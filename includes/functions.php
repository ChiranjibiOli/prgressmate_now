<?php
// includes/functions.php

function awardAchievements($pdo, $student_id) {
    try {
        // Fetch all active achievements
        $stmt = $pdo->prepare("
            SELECT a.*, c.name AS category_name 
            FROM achievements a 
            LEFT JOIN categories c ON a.category_id = c.id 
            WHERE a.is_active = 1 AND a.deleted_at IS NULL
        ");
        $stmt->execute();
        $achievements = $stmt->fetchAll();

        foreach ($achievements as $achievement) {
            $already_earned = $pdo->prepare("SELECT 1 FROM user_achievements WHERE user_id = ? AND achievement_id = ?");
            $already_earned->execute([$student_id, $achievement['id']]);
            if ($already_earned->fetch()) continue; // already has it

            $award = false;

            if ($achievement['criteria_type'] === 'total_completed_goals') {
                $count = getStat($pdo, "SELECT COUNT(*) FROM student_goals WHERE student_id = ? AND status = 'completed' AND deleted_at IS NULL", [$student_id]);
                if ($count >= $achievement['criteria_value']) $award = true;

            } elseif ($achievement['criteria_type'] === 'completed_goals_in_category' && $achievement['category_id']) {
                $count = getStat($pdo, "SELECT COUNT(*) FROM student_goals WHERE student_id = ? AND category = (SELECT name FROM categories WHERE id = ?) AND status = 'completed' AND deleted_at IS NULL", [$student_id, $achievement['category_id']]);
                if ($count >= $achievement['criteria_value']) $award = true;
            }
            // Add more criteria types here if needed (streak, points, etc.)

            if ($award) {
                // Award achievement
                $pdo->prepare("INSERT INTO user_achievements (user_id, achievement_id, earned_at) VALUES (?, ?, NOW())")
                    ->execute([$student_id, $achievement['id']]);

                // Add points
                $pdo->prepare("UPDATE users SET points = points + ? WHERE id = ?")
                    ->execute([$achievement['points'], $student_id]);

                // Notification
                createAchievementNotification($pdo, $student_id, $achievement);
            }
        }
    } catch (Exception $e) {
        error_log("Achievement award error: " . $e->getMessage());
    }
}

function createAchievementNotification($pdo, $student_id, $achievement) {
    $message = "Congratulations! You've earned the '{$achievement['title']}' achievement (+{$achievement['points']} points)!";
    $pdo->prepare("INSERT INTO notifications (user_id, title, message, type) VALUES (?, 'Achievement Unlocked', ?, 'achievement')")
        ->execute([$student_id, $message]);
}
?>