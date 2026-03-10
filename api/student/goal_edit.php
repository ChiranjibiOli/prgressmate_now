<?php
// api/student/goal_edit.php
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

$student_id  = (int) $_SESSION['user_id'];
$goal_id     = (int) ($_POST['goal_id'] ?? 0);
$title       = trim($_POST['title']       ?? '');
$description = trim($_POST['description'] ?? '');
$category    = trim($_POST['category']    ?? '');
$priority    = trim($_POST['priority']    ?? 'medium');
$unit        = trim($_POST['unit']        ?? '');
$target_raw  = $_POST['target_value']     ?? '';
$due_date    = trim($_POST['due_date']    ?? '');

// Validate
if ($goal_id <= 0) {
    echo json_encode(['success' => false, 'error' => 'Invalid goal ID']);
    exit;
}
if (empty($title)) {
    echo json_encode(['success' => false, 'error' => 'Title is required']);
    exit;
}
$target_value = (float) $target_raw;
if ($target_value <= 0) {
    echo json_encode(['success' => false, 'error' => 'Target value must be greater than 0']);
    exit;
}
if (!in_array($priority, ['low', 'medium', 'high'])) {
    $priority = 'medium';
}
$due_date = !empty($due_date) ? $due_date : null;

try {
    // Only allow editing self-created goals belonging to this student
    $check = $pdo->prepare("
        SELECT id, is_self_created, current_value
        FROM student_goals
        WHERE id = ? AND student_id = ? AND deleted_at IS NULL
    ");
    $check->execute([$goal_id, $student_id]);
    $goal = $check->fetch(PDO::FETCH_ASSOC);

    if (!$goal) {
        echo json_encode(['success' => false, 'error' => 'Goal not found']);
        exit;
    }

    if (!$goal['is_self_created']) {
        echo json_encode(['success' => false, 'error' => 'You can only edit your own self-created goals']);
        exit;
    }

    // Recalculate percentage based on new target
    $current_value = (float) $goal['current_value'];
    $percentage    = $target_value > 0
        ? min(100, round(($current_value / $target_value) * 100, 2))
        : 0;

    $new_status = 'pending';
    if ($percentage >= 100)      $new_status = 'completed';
    elseif ($current_value > 0)  $new_status = 'in_progress';

    $stmt = $pdo->prepare("
        UPDATE student_goals
        SET title               = ?,
            description         = ?,
            category            = ?,
            priority            = ?,
            unit                = ?,
            target_value        = ?,
            due_date            = ?,
            progress_percentage = ?,
            status              = ?,
            updated_at          = NOW()
        WHERE id = ? AND student_id = ?
    ");
    $stmt->execute([
        $title,
        $description,
        $category,
        $priority,
        $unit,
        $target_value,
        $due_date,
        $percentage,
        $new_status,
        $goal_id,
        $student_id
    ]);

    echo json_encode(['success' => true, 'message' => 'Goal updated successfully']);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}