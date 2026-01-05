<?php
// Database configuration
define('DB_HOST', '127.0.0.1');
define('DB_PORT', '3307');
define('DB_NAME', 'progressmate_now');
define('DB_USER', 'root');
define('DB_PASS', '');

// Create connection
try {
    $pdo = new PDO(
        "mysql:host=" . DB_HOST . ";port=" . DB_PORT . ";dbname=" . DB_NAME . ";charset=utf8mb4",
        DB_USER,
        DB_PASS,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false
        ]
    );
} catch (PDOException $e) {
    die("Database connection failed: " . $e->getMessage());
}

// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}



function checkAuth($required_role = null) {
    if (!isset($_SESSION['user_id'])) {
        header("Location: ../login.php");
        exit;
    }
    
    if ($required_role && $_SESSION['role'] !== $required_role) {
        // Redirect to appropriate dashboard based on role
        if ($_SESSION['role'] == 'admin') {
            header("Location: ../admin/admin.php");
        } else {
            header("Location: ../students/dashboard.php");
        }
        exit;
    }
}

// Compute achievement progress
function computeAchievementProgress($pdo, $student_id, $achievement) {
    try {
        $criteria_type = $achievement['criteria_type'] ?? '';
        $criteria_value = intval($achievement['criteria_value'] ?? 0);
        
        if ($criteria_value <= 0) {
            error_log("Invalid criteria_value: 0 for achievement ID " . ($achievement['id'] ?? 'unknown'));
            return 0;
        }
        
        $current = 0;
        
        switch ($criteria_type) {
            case 'goals_completed':
                $current = getStat($pdo, "SELECT COUNT(*) FROM student_goals WHERE student_id = ? AND status = 'completed'", [$student_id]);
                break;
            
            case 'perfect_scores':
                $current = getStat($pdo, "
                    SELECT COUNT(*) FROM student_goals 
                    WHERE student_id = ? AND status = 'completed' AND progress_percentage = 100
                ", [$student_id]);
                break;
            
            case 'early_completions':
                $current = getStat($pdo, "
                    SELECT COUNT(*) 
                    FROM student_goals sg
                    WHERE sg.student_id = ? 
                    AND sg.status = 'completed' 
                    AND sg.completed_at IS NOT NULL
                    AND sg.due_date IS NOT NULL
                    AND DATE(sg.completed_at) < sg.due_date
                ", [$student_id]);
                break;
            
            case 'consecutive_days':
                // Calculate from progress_history (distinct days with progress)
                $stmt = $pdo->prepare("
                    SELECT log_date FROM progress_history 
                    WHERE student_id = ? 
                    GROUP BY log_date 
                    ORDER BY log_date DESC
                ");
                $stmt->execute([$student_id]);
                $dates = $stmt->fetchAll(PDO::FETCH_COLUMN);
                
                $streak = 0;
                if (!empty($dates)) {
                    $streak = 1;
                    $prev_date = new DateTime($dates[0]);
                    for ($i = 1; $i < count($dates); $i++) {
                        $curr_date = new DateTime($dates[$i]);
                        $diff = $prev_date->diff($curr_date)->days;
                        if ($diff == 1) {
                            $streak++;
                            $prev_date = $curr_date;
                        } else {
                            break;
                        }
                    }
                }
                // Update users.current_streak
                $update_stmt = $pdo->prepare("UPDATE users SET current_streak = ? WHERE id = ?");
                $update_stmt->execute([$streak, $student_id]);
                
                $current = $streak;
                break;
                
            case 'total_points':
                $current = getStat($pdo, "SELECT COALESCE(SUM(a.points), 0) FROM user_achievements ua JOIN achievements a ON ua.achievement_id = a.id WHERE ua.user_id = ? AND ua.earned_at IS NOT NULL", [$student_id]);
                break;
                
            case 'progress_logs':
                // Fixed to match schema (progress_history)
                $current = getStat($pdo, "SELECT COUNT(*) FROM progress_history WHERE student_id = ?", [$student_id]);
                break;
                
            default:
                error_log("Unknown criteria_type: $criteria_type for achievement ID " . ($achievement['id'] ?? 'unknown'));
                $current = 0;
                break;
        }
        
        $progress = min(100, ($current / $criteria_value) * 100);
        return round($progress, 2);
    } catch (Exception $e) {
        error_log("Progress computation error for student $student_id, achievement " . ($achievement['id'] ?? 'unknown') . ": " . $e->getMessage());
        return 0;
    }
}
// Function to distribute new achievement to all students
function distributeAchievementToAllStudents($pdo, $achievement_id) {
    try {
        // Get all active students
        $stmt = $pdo->prepare("SELECT id FROM users WHERE role = 'student' AND status = 'active' AND is_deleted = 0");
        $stmt->execute();
        $students = $stmt->fetchAll();
        
        // Get achievement details
        $ach_stmt = $pdo->prepare("SELECT * FROM achievements WHERE id = ?");
        $ach_stmt->execute([$achievement_id]);
        $achievement = $ach_stmt->fetch();
        
        foreach ($students as $student) {
            $progress = computeAchievementProgress($pdo, $student['id'], $achievement);
            
            // Check if student already has this achievement
            $check_stmt = $pdo->prepare("SELECT id FROM user_achievements WHERE user_id = ? AND achievement_id = ?");
            $check_stmt->execute([$student['id'], $achievement_id]);
            
            if (!$check_stmt->fetch()) {
                // Insert achievement record for student if they don't have it
                $insert_stmt = $pdo->prepare("
                    INSERT INTO user_achievements (user_id, achievement_id, earned_at) 
                    VALUES (?, ?, ?)
                    ON DUPLICATE KEY UPDATE earned_at = VALUES(earned_at)
                ");
                
                // Only set earned_at if progress is 100%
                $earned_at = $progress >= 100 ? date('Y-m-d H:i:s') : null;
                $insert_stmt->execute([$student['id'], $achievement_id, $earned_at]);
                
                // Create notification if unlocked
                if ($progress >= 100) {
                    createAchievementNotification($pdo, $student['id'], $achievement);
                }
            }
        }
        
        return true;
    } catch (Exception $e) {
        error_log("Distribute achievement error: " . $e->getMessage());
        return false;
    }
}

// Function to award achievements for a specific student
function awardAchievementsForStudent($pdo, $student_id) {
    try {
        // Get all active achievements
        $stmt = $pdo->prepare("SELECT * FROM achievements WHERE is_active = 1");
        $stmt->execute();
        $achievements = $stmt->fetchAll();
        
        foreach ($achievements as $achievement) {
            $achievement_id = $achievement['id'];
            
            // Check if already earned
            $check_stmt = $pdo->prepare("SELECT id, earned_at FROM user_achievements WHERE user_id = ? AND achievement_id = ?");
            $check_stmt->execute([$student_id, $achievement_id]);
            $user_achievement = $check_stmt->fetch();
            
            $progress = computeAchievementProgress($pdo, $student_id, $achievement);
            
            if ($user_achievement) {
                // Update earned_at if not yet earned and progress is 100%
                if ($progress >= 100 && !$user_achievement['earned_at']) {
                    $update_stmt = $pdo->prepare("
                        UPDATE user_achievements 
                        SET earned_at = NOW()
                        WHERE user_id = ? AND achievement_id = ?
                    ");
                    $update_stmt->execute([$student_id, $achievement_id]);
                    
                    // Create notification
                    createAchievementNotification($pdo, $student_id, $achievement);
                }
            } else {
                // Create new achievement record
                $earned_at = $progress >= 100 ? date('Y-m-d H:i:s') : null;
                $insert_stmt = $pdo->prepare("
                    INSERT INTO user_achievements (user_id, achievement_id, earned_at) 
                    VALUES (?, ?, ?)
                ");
                $insert_stmt->execute([$student_id, $achievement_id, $earned_at]);
                
                // Create notification if unlocked
                if ($progress >= 100) {
                    createAchievementNotification($pdo, $student_id, $achievement);
                }
            }
        }
    } catch (Exception $e) {
        error_log("Award achievements for student error: " . $e->getMessage());
    }
}


//new fields if not exist
try {
    $pdo->exec("ALTER TABLE users ADD COLUMN current_streak INT DEFAULT 0");
} catch (PDOException $e) {}
try {
    $pdo->exec("ALTER TABLE users ADD COLUMN last_login_date DATE");
} catch (PDOException $e) {}
try {
    $pdo->exec("ALTER TABLE users ADD COLUMN verification_code VARCHAR(6)");
} catch (PDOException $e) {}
try {
    $pdo->exec("ALTER TABLE users ADD COLUMN verification_expire DATETIME");
} catch (PDOException $e) {}
try {
    $pdo->exec("ALTER TABLE achievements ADD COLUMN image_path VARCHAR(255)");
} catch (PDOException $e) {}
try {
    $pdo->exec("ALTER TABLE admin_goals ADD COLUMN achievement_id INT");
} catch (PDOException $e) {}

// New tables
$pdo->exec("CREATE TABLE IF NOT EXISTS progress_history (
    id INT AUTO_INCREMENT PRIMARY KEY,
    student_id INT,
    goal_id INT,
    log_date DATE,
    progress_added FLOAT,
    notes TEXT,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
)");

$pdo->exec("CREATE TABLE IF NOT EXISTS user_sessions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT,
    login_time DATETIME,
    logout_time DATETIME,
    duration FLOAT DEFAULT 0
)");


// Existing other functions if any
?>