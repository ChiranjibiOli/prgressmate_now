<?php
// api/student/goal_progress.php
if (session_status() === PHP_SESSION_NONE) session_start();
require_once '../../includes/db_connection.php';
require_once '../../includes/functions.php';
require_once '../../api/_helpers.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'student') {
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

// CSRF check
if (!verifyCsrf($_POST['csrf_token'] ?? '')) {
    echo json_encode(['success' => false, 'error' => 'Invalid CSRF token']);
    exit;
}

$student_id     = (int) $_SESSION['user_id'];
$goal_id        = (int) ($_POST['goal_id'] ?? 0);
$progress_value = (float) ($_POST['progress_value'] ?? 0);
$notes          = trim($_POST['notes'] ?? '');

if ($goal_id <= 0 || $progress_value <= 0) {
    echo json_encode(['success' => false, 'error' => 'Invalid goal ID or progress value']);
    exit;
}

try {
    // Fetch goal — must belong to this student
    $stmt = $pdo->prepare("
        SELECT * FROM student_goals
        WHERE id = ? AND student_id = ? AND deleted_at IS NULL
    ");
    $stmt->execute([$goal_id, $student_id]);
    $goal = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$goal) {
        echo json_encode(['success' => false, 'error' => 'Goal not found']);
        exit;
    }

    if ($goal['status'] === 'completed') {
        echo json_encode(['success' => false, 'error' => 'Goal is already completed']);
        exit;
    }

    // Calculate new value (cap at target)
    $new_value  = min(
        (float) $goal['target_value'],
        (float) $goal['current_value'] + $progress_value
    );
    $target     = (float) $goal['target_value'];
    $percentage = $target > 0 ? round(($new_value / $target) * 100, 2) : 0;
    $percentage = min(100, $percentage);

    // Determine new status
    $new_status = $goal['status'];
    $completed_at = $goal['completed_at'];

    if ($percentage >= 100) {
        $new_status   = 'completed';
        $completed_at = date('Y-m-d H:i:s');
    } elseif ($new_value > 0) {
        $new_status = 'in_progress';
    }

    $pdo->beginTransaction();

    // Update student_goals
    $update = $pdo->prepare("
        UPDATE student_goals
        SET current_value       = ?,
            progress_percentage = ?,
            status              = ?,
            completed_at        = ?,
            updated_at          = NOW()
        WHERE id = ? AND student_id = ?
    ");
    $update->execute([
        $new_value,
        $percentage,
        $new_status,
        $completed_at,
        $goal_id,
        $student_id
    ]);

    // Log to progress_history
    $log = $pdo->prepare("
        INSERT INTO progress_history (student_id, goal_id, log_date, progress_added, notes)
        VALUES (?, ?, CURDATE(), ?, ?)
    ");
    $log->execute([$student_id, $goal_id, $progress_value, $notes]);

    // Also insert into goal_progress table
    $gp = $pdo->prepare("
        INSERT INTO goal_progress (goal_id, student_id, progress_value, notes, status)
        VALUES (?, ?, ?, ?, 'approved')
    ");
    $gp->execute([$goal_id, $student_id, $progress_value, $notes]);

    // Award points if completed (10 pts per completion)
    if ($new_status === 'completed' && $goal['status'] !== 'completed') {
        $pdo->prepare("UPDATE users SET points = points + 10 WHERE id = ?")
            ->execute([$student_id]);

        // Create completion notification
        $pdo->prepare("
            INSERT INTO notifications (user_id, title, message, type, related_id, related_type)
            VALUES (?, 'Goal Completed! 🎉', ?, 'goal', ?, 'goal')
        ")->execute([
            $student_id,
            "You completed your goal: \"{$goal['title']}\" (+10 points)",
            $goal_id
        ]);
    }

    $pdo->commit();

    // Check for new achievements
    awardAchievements($pdo, $student_id);

    echo json_encode([
        'success'   => true,
        'new_value' => $new_value,
        'status'    => $new_status,
        'after'     => [
            'progress_percentage' => $percentage,
            'current_value'       => $new_value,
            'status'              => $new_status,
        ]
    ]);

} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}