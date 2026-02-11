<?php
// includes/functions.php

/**
 * Get a single statistic value from database
 */
function getStat($pdo, $query, $params = []) {
    try {
        $stmt = $pdo->prepare($query);
        $stmt->execute($params);
        $result = $stmt->fetchColumn();
        return $result !== false ? (int)$result : 0;
    } catch (Exception $e) {
        error_log("getStat error: " . $e->getMessage());
        return 0;
    }
}

/**
 * Award achievements to a student based on their progress
 * @param PDO $pdo Database connection
 * @param int $student_id Student user ID
 * @return int Number of new achievements awarded
 */
function awardAchievements($pdo, $student_id) {
    try {
        $pdo->beginTransaction();
        
        // Get student's current stats in one efficient query
        $studentStats = $pdo->prepare("
            SELECT 
                u.id,
                u.name,
                u.points,
                u.current_streak,
                (SELECT COUNT(*) FROM student_goals WHERE student_id = u.id AND status = 'completed' AND deleted_at IS NULL) as completed_goals,
                (SELECT COUNT(*) FROM student_goals WHERE student_id = u.id AND is_self_created = 1 AND deleted_at IS NULL) as self_created_goals,
(SELECT COUNT(*) FROM student_goals 
 WHERE student_id = u.id 
 AND status IN ('pending','in_progress') 
 AND deleted_at IS NULL) as active_goals,

(SELECT COUNT(*) FROM student_goals 
 WHERE student_id = u.id 
 AND status != 'completed' 
 AND due_date IS NOT NULL 
 AND due_date < CURDATE()
 AND deleted_at IS NULL) as overdue_goals


            FROM users u 
            WHERE u.id = ? AND u.role = 'student' AND u.status IN ('pending','in_progress') AND u.deleted_at IS NULL
        ");
        $studentStats->execute([$student_id]);
        $stats = $studentStats->fetch(PDO::FETCH_ASSOC);
        
        if (!$stats) {
            $pdo->rollBack();
            return 0;
        }
        
        // Get all active achievements
        $achievements = $pdo->query("
            SELECT a.*, c.name as category_name 
            FROM achievements a 
            LEFT JOIN categories c ON a.category_id = c.id 
            WHERE a.is_active = 1 AND a.deleted_at IS NULL
            ORDER BY a.points DESC
        ")->fetchAll(PDO::FETCH_ASSOC);
        
        $new_achievements = [];
        $total_points_added = 0;
        
        foreach ($achievements as $achievement) {
            // Check if already earned
            $checkStmt = $pdo->prepare("
                SELECT id FROM user_achievements 
                WHERE user_id = ? AND achievement_id = ? AND deleted_at IS NULL
            ");
            $checkStmt->execute([$student_id, $achievement['id']]);
            if ($checkStmt->fetch()) continue;
            
            $criteria_met = false;
            
            switch ($achievement['criteria_type']) {
                case 'total_completed_goals':
                    if ($stats['completed_goals'] >= (int)$achievement['criteria_value']) {
                        $criteria_met = true;
                    }
                    break;
                    
                case 'completed_goals_in_category':
                    if ($achievement['category_id']) {
                        // Get category name from category_id
                        $catStmt = $pdo->prepare("SELECT name FROM categories WHERE id = ?");
                        $catStmt->execute([$achievement['category_id']]);
                        $category = $catStmt->fetchColumn();
                        
                        if ($category) {
                            $countStmt = $pdo->prepare("
                                SELECT COUNT(*) FROM student_goals 
                                WHERE student_id = ? 
                                AND category = ? 
                                AND status = 'completed'
                                AND deleted_at IS NULL
                            ");
                            $countStmt->execute([$student_id, $category]);
                            $category_count = (int)$countStmt->fetchColumn();
                            
                            if ($category_count >= (int)$achievement['criteria_value']) {
                                $criteria_met = true;
                            }
                        }
                    }
                    break;
                    
                case 'total_points':
                    if ($stats['points'] >= (int)$achievement['criteria_value']) {
                        $criteria_met = true;
                    }
                    break;
                    
                case 'login_streak':
                    if ($stats['current_streak'] >= (int)$achievement['criteria_value']) {
                        $criteria_met = true;
                    }
                    break;
                    
                case 'total_goals_created':
                    if ($stats['self_created_goals'] >= (int)$achievement['criteria_value']) {
                        $criteria_met = true;
                    }
                    break;
                    
                case 'perfect_week':
                    // Check for 7 consecutive days with at least one goal completed
                    $weekStmt = $pdo->prepare("
                        WITH dates AS (
                            SELECT CURDATE() - INTERVAL n DAY as date
                            FROM (SELECT 0 as n UNION SELECT 1 UNION SELECT 2 UNION SELECT 3 
                                  UNION SELECT 4 UNION SELECT 5 UNION SELECT 6) nums
                        )
                        SELECT COUNT(DISTINCT DATE(sg.completed_at)) as days_with_completions
                        FROM dates d
                        LEFT JOIN student_goals sg ON DATE(sg.completed_at) = d.date 
                            AND sg.student_id = ? 
                            AND sg.status = 'completed'
                            AND sg.deleted_at IS NULL
                        WHERE sg.id IS NOT NULL
                    ");
                    $weekStmt->execute([$student_id]);
                    $days_with_completions = (int)$weekStmt->fetchColumn();
                    
                    if ($days_with_completions >= 7) {
                        $criteria_met = true;
                    }
                    break;
                    
                case 'early_completion':
                    // Check for goals completed 3+ days before deadline
                    $earlyStmt = $pdo->prepare("
                        SELECT COUNT(*) 
                        FROM student_goals 
                        WHERE student_id = ? 
                        AND status = 'completed' 
                        AND deleted_at IS NULL
                        AND due_date IS NOT NULL
                        AND DATEDIFF(due_date, completed_at) >= 3
                    ");
                    $earlyStmt->execute([$student_id]);
                    $early_completions = (int)$earlyStmt->fetchColumn();
                    
                    if ($early_completions >= (int)$achievement['criteria_value']) {
                        $criteria_met = true;
                    }
                    break;
                    
                case 'zero_overdue':
                    if ($stats['overdue_goals'] == 0 && $stats['completed_goals'] >= 5) {
                        $criteria_met = true;
                    }
                    break;
            }
            
            if ($criteria_met) {
                // Award the achievement
                $awardStmt = $pdo->prepare("
                    INSERT INTO user_achievements (user_id, achievement_id, earned_at) 
                    VALUES (?, ?, NOW())
                ");
                $awardStmt->execute([$student_id, $achievement['id']]);
                
                // Add points to student
                $points = (int)$achievement['points'];
                $updateStmt = $pdo->prepare("
                    UPDATE users 
                    SET points = points + ? 
                    WHERE id = ?
                ");
                $updateStmt->execute([$points, $student_id]);
                
                $total_points_added += $points;
                
                $new_achievements[] = [
                    'id' => $achievement['id'],
                    'title' => $achievement['title'],
                    'description' => $achievement['description'],
                    'points' => $points,
                    'icon' => $achievement['icon'],
                    'color' => $achievement['color']
                ];
                
                // Create notification
                createAchievementNotification($pdo, $student_id, $achievement);
            }
        }
        
        $pdo->commit();
        
        // Log achievement awards
        if (!empty($new_achievements)) {
            error_log("Awarded " . count($new_achievements) . " achievements to student $student_id (+$total_points_added points)");
        }
        
        return count($new_achievements);
        
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log("Achievement award error for student $student_id: " . $e->getMessage());
        return 0;
    }
}

/**
 * Create notification for achievement unlock
 */
function createAchievementNotification($pdo, $student_id, $achievement) {
    try {
        $message = "🎉 Congratulations! You've earned the '{$achievement['title']}' achievement (+{$achievement['points']} points)! {$achievement['description']}";
        
        $stmt = $pdo->prepare("
            INSERT INTO notifications 
            (user_id, title, message, type, related_id, related_type, created_at) 
            VALUES (?, 'Achievement Unlocked!', ?, 'achievement', ?, 'achievement', NOW())
        ");
        
        return $stmt->execute([
            $student_id, 
            $message, 
            $achievement['id']
        ]);
    } catch (Exception $e) {
        error_log("Notification creation error: " . $e->getMessage());
        return false;
    }
}

/**
 * Update student's login streak and award achievements
 */
function updateLoginStreak($pdo, $user_id) {
    try {
        $pdo->beginTransaction();
        
        // Get user's last login date
        $userStmt = $pdo->prepare("
            SELECT last_login_date, current_streak 
            FROM users 
            WHERE id = ? AND deleted_at IS NULL
        ");
        $userStmt->execute([$user_id]);
        $user = $userStmt->fetch(PDO::FETCH_ASSOC);
        
        $today = date('Y-m-d');
        $new_streak = 1;
        
        if ($user) {
            if ($user['last_login_date'] == $today) {
                // Already logged in today
                $pdo->rollBack();
                return $user['current_streak'];
            }
            
            $last_login = new DateTime($user['last_login_date']);
            $yesterday = new DateTime('yesterday');
            
            if ($last_login->format('Y-m-d') == $yesterday->format('Y-m-d')) {
                // Consecutive login
                $new_streak = $user['current_streak'] + 1;
            } elseif ($last_login->format('Y-m-d') < $yesterday->format('Y-m-d')) {
                // Streak broken
                $new_streak = 1;
            }
        }
        
        // Update streak and last login
        $updateStmt = $pdo->prepare("
            UPDATE users 
            SET current_streak = ?, last_login_date = ?, last_login = NOW() 
            WHERE id = ?
        ");
        $updateStmt->execute([$new_streak, $today, $user_id]);
        
        $pdo->commit();
        
        // Check for streak-based achievements
        awardAchievements($pdo, $user_id);
        
        return $new_streak;
        
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log("Update login streak error: " . $e->getMessage());
        return 0;
    }
}

/**
 * Check and award achievements when goal is completed
 */
function checkGoalCompletionAchievements($pdo, $goal_id, $student_id) {
    try {
        // Get goal details
        $goalStmt = $pdo->prepare("
            SELECT * FROM student_goals 
            WHERE id = ? AND student_id = ? AND deleted_at IS NULL
        ");
        $goalStmt->execute([$goal_id, $student_id]);
        $goal = $goalStmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$goal || $goal['status'] !== 'completed') {
            return false;
        }
        
        // Award achievements based on this completion
        $awarded = awardAchievements($pdo, $student_id);
        
        return $awarded > 0;
        
    } catch (Exception $e) {
        error_log("Goal completion achievement check error: " . $e->getMessage());
        return false;
    }
}

/**
 * Recalculate achievements for all students
 */
function recalculateAllAchievements($pdo) {
    try {
        $students = $pdo->query("
            SELECT id FROM users 
            WHERE role = 'student' AND status = 'active' AND deleted_at IS NULL
        ")->fetchAll(PDO::FETCH_COLUMN);
        
        $total_awarded = 0;
        
        foreach ($students as $student_id) {
            $awarded = awardAchievements($pdo, $student_id);
            $total_awarded += $awarded;
        }
        
        return $total_awarded;
        
    } catch (Exception $e) {
        error_log("Recalculate all achievements error: " . $e->getMessage());
        return 0;
    }
}

/**
 * Get student's earned achievements with details
 */
function getStudentAchievements($pdo, $student_id, $limit = null) {
    try {
        $query = "
            SELECT 
                ua.*,
                a.title,
                a.description,
                a.points,
                a.icon,
                a.color,
                a.criteria_type,
                a.criteria_value,
                c.name as category_name,
                DATE_FORMAT(ua.earned_at, '%Y-%m-%d %H:%i') as earned_date
            FROM user_achievements ua
            JOIN achievements a ON ua.achievement_id = a.id
            LEFT JOIN categories c ON a.category_id = c.id
            WHERE ua.user_id = ? AND ua.deleted_at IS NULL AND a.deleted_at IS NULL
            ORDER BY ua.earned_at DESC
        ";
        
        if ($limit) {
            $query .= " LIMIT " . (int)$limit;
        }
        
        $stmt = $pdo->prepare($query);
        $stmt->execute([$student_id]);
        
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        error_log("Get student achievements error: " . $e->getMessage());
        return [];
    }
}

/**
 * Get student's achievement progress (which ones they're close to earning)
 */
function getAchievementProgress($pdo, $student_id) {
    try {
        // Get student stats
        $statsStmt = $pdo->prepare("
            SELECT 
                u.points,
                u.current_streak,
                (SELECT COUNT(*) FROM student_goals 
 WHERE student_id = u.id 
 AND status IN ('pending','in_progress') 
 AND deleted_at IS NULL) as active_goals,

                (SELECT COUNT(*) FROM student_goals WHERE student_id = u.id AND is_self_created = 1) as self_created_goals
            FROM users u 
            WHERE u.id = ?
        ");
        $statsStmt->execute([$student_id]);
        $stats = $statsStmt->fetch(PDO::FETCH_ASSOC);
        
        // Get achievements not yet earned
        $achievementsStmt = $pdo->prepare("
            SELECT 
                a.*,
                c.name as category_name,
                (SELECT 1 FROM user_achievements ua WHERE ua.user_id = ? AND ua.achievement_id = a.id) as is_earned
            FROM achievements a
            LEFT JOIN categories c ON a.category_id = c.id
            WHERE a.is_active = 1 AND a.deleted_at IS NULL
            HAVING is_earned IS NULL
            ORDER BY a.points ASC
        ");
        $achievementsStmt->execute([$student_id]);
        $achievements = $achievementsStmt->fetchAll(PDO::FETCH_ASSOC);
        
        $progress = [];
        
        foreach ($achievements as $achievement) {
            $current_value = 0;
            $target_value = (int)$achievement['criteria_value'];
            $percentage = 0;
            
            switch ($achievement['criteria_type']) {
                case 'total_completed_goals':
                    $current_value = $stats['completed_goals'] ?? 0;
                    break;
                case 'total_points':
                    $current_value = $stats['points'] ?? 0;
                    break;
                case 'login_streak':
                    $current_value = $stats['current_streak'] ?? 0;
                    break;
                case 'total_goals_created':
                    $current_value = $stats['self_created_goals'] ?? 0;
                    break;
                // Add more cases as needed
            }
            
            if ($target_value > 0) {
                $percentage = min(100, round(($current_value / $target_value) * 100));
            }
            
            $progress[] = [
                'achievement' => $achievement,
                'current_value' => $current_value,
                'target_value' => $target_value,
                'percentage' => $percentage,
                'remaining' => max(0, $target_value - $current_value)
            ];
        }
        
        return $progress;
    } catch (Exception $e) {
        error_log("Get achievement progress error: " . $e->getMessage());
        return [];
    }
}
?>