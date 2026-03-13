<?php
// api/student/goal_edit.php
if (session_status() === PHP_SESSION_NONE) session_start();
require_once '../../includes/db_connection.php';
require_once '../../includes/functions.php';
require_once '../../api/_helpers.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'student') {
    echo json_encode(['success' => false, 'error' => 'Unauthorized']); exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'error' => 'Method not allowed']); exit;
}
if (!verifyCsrf($_POST['csrf_token'] ?? '')) {
    echo json_encode(['success' => false, 'error' => 'Invalid CSRF token']); exit;
}

$student_id   = (int)$_SESSION['user_id'];
$goal_id      = (int)($_POST['goal_id']      ?? 0);
$title        = trim($_POST['title']        ?? '');
$description  = trim($_POST['description']  ?? '');
$category     = trim($_POST['category']     ?? '');
$priority     = trim($_POST['priority']     ?? 'medium');
$unit         = trim($_POST['unit']         ?? '');
$target_value = (float)($_POST['target_value'] ?? 0);
$due_date     = trim($_POST['due_date']     ?? '') ?: null;

if ($goal_id <= 0)        { echo json_encode(['success'=>false,'error'=>'Invalid goal ID']);         exit; }
if (empty($title))        { echo json_encode(['success'=>false,'error'=>'Title is required']);       exit; }
if ($target_value <= 0)   { echo json_encode(['success'=>false,'error'=>'Target must be > 0']);      exit; }
if (!in_array($priority, ['low','medium','high'])) $priority = 'medium';

try {
    // Fetch goal — must belong to this student and be self-created
    $chk = $pdo->prepare("
        SELECT id, status, is_self_created, current_value, completed_at
        FROM student_goals
        WHERE id = ? AND student_id = ? AND deleted_at IS NULL
    ");
    $chk->execute([$goal_id, $student_id]);
    $goal = $chk->fetch(PDO::FETCH_ASSOC);

    if (!$goal)                    { echo json_encode(['success'=>false,'error'=>'Goal not found']);                    exit; }
    if (!$goal['is_self_created']) { echo json_encode(['success'=>false,'error'=>'You can only edit your own goals']); exit; }

    // Recalculate status from new target
    $current    = (float)$goal['current_value'];
    $pct        = $target_value > 0 ? min(100, round(($current / $target_value) * 100, 2)) : 0;
    $new_status = $pct >= 100 ? 'completed' : ($current > 0 ? 'in_progress' : 'pending');

    $was_completed = ($goal['status'] === 'completed');
    $now_completed = ($new_status === 'completed');
    // Preserve original completed_at; only set it if newly becoming completed
    $completed_at_val = $now_completed ? ($goal['completed_at'] ?? date('Y-m-d H:i:s')) : null;

    $pdo->beginTransaction();

    $pdo->prepare("
        UPDATE student_goals
        SET title = ?, description = ?, category = ?, priority = ?, unit = ?,
            target_value = ?, due_date = ?, progress_percentage = ?, status = ?,
            completed_at = CASE WHEN ? = 1 THEN COALESCE(completed_at, NOW()) ELSE completed_at END,
            updated_at = NOW()
        WHERE id = ? AND student_id = ?
    ")->execute([
        $title, $description, $category, $priority, $unit,
        $target_value, $due_date, $pct, $new_status,
        $now_completed ? 1 : 0,
        $goal_id, $student_id
    ]);

    // If goal just became completed: award +10 pts + notification
    if ($now_completed && !$was_completed) {
        $pdo->prepare("UPDATE users SET points = points + 10 WHERE id = ?")->execute([$student_id]);
        $pdo->prepare("
            INSERT INTO notifications (user_id, title, message, type, related_id, related_type)
            VALUES (?, 'Goal Completed! 🎉', ?, 'goal', ?, 'goal')
        ")->execute([$student_id, "You completed \"{$title}\" (+10 pts)", $goal_id]);
    }

    $pdo->commit();

    // Check for newly unlocked achievements (non-fatal)
    awardAchievements($pdo, $student_id);

    echo json_encode(['success' => true, 'message' => 'Goal updated successfully',
        'status' => $new_status, 'percentage' => $pct]);

} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}