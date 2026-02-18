<?php
session_start();
require_once '../../includes/db_connection.php';
require_once '../../includes/functions.php';
require_once '../_helpers.php';

requirePost();
checkAuth('student');
verifyCsrf();

$student_id = (int)($_SESSION['user_id'] ?? 0);
$goal_id    = (int)($_POST['goal_id'] ?? 0);

$title        = trim($_POST['title'] ?? '');
$description  = trim($_POST['description'] ?? '');
$category     = trim($_POST['category'] ?? '');
$priority     = $_POST['priority'] ?? 'medium';
$target_value = (float)($_POST['target_value'] ?? 0);
$unit         = trim($_POST['unit'] ?? '');
$due_date     = trim($_POST['due_date'] ?? '');

if ($student_id <= 0) jsonResponse(['success' => false, 'error' => 'Unauthorized'], 401);
if ($goal_id <= 0) jsonResponse(['success' => false, 'error' => 'Invalid id'], 400);
if ($title === '') jsonResponse(['success' => false, 'error' => 'Title required'], 400);
if (!in_array($priority, ['low', 'medium', 'high'], true)) $priority = 'medium';
if ($target_value <= 0) jsonResponse(['success' => false, 'error' => 'Target value must be > 0'], 400);

// Normalize optional fields to NULL
$description = ($description === '') ? null : $description;
$category    = ($category === '') ? null : $category;
$unit        = ($unit === '') ? null : $unit;
$due_date    = ($due_date === '') ? null : $due_date;

$pdo->beginTransaction();
try {
    // Lock row + verify ownership + read flags/status
    $q = $pdo->prepare("
        SELECT current_value, status, is_admin_created, is_self_created
        FROM student_goals
        WHERE id=? AND student_id=? AND deleted_at IS NULL
        FOR UPDATE
    ");
    $q->execute([$goal_id, $student_id]);
    $goal = $q->fetch(PDO::FETCH_ASSOC);

    if (!$goal) throw new Exception("Not found");

    // Rule 1: admin-created goals cannot be edited
    if ((int)($goal['is_admin_created'] ?? 0) === 1) {
        throw new Exception("You cannot edit admin-created goals.");
    }

    // Rule 2: must be self-created
    if ((int)($goal['is_self_created'] ?? 0) !== 1) {
        throw new Exception("You can only edit your own created goals.");
    }

    // Rule 3: only completed goals can be edited
    if (($goal['status'] ?? '') !== 'completed') {
        throw new Exception("You can only edit a goal after it is completed.");
    }

    $current_value = (float)($goal['current_value'] ?? 0);

    // Recalculate percentage based on new target (keep completed status)
    $percentage = ($target_value > 0) ? (($current_value / $target_value) * 100) : 0;
    if ($percentage > 100) $percentage = 100;
    if ($percentage < 0) $percentage = 0;

    // Keep status as completed (because you only allow editing when completed)
    $status = 'completed';

    $pdo->prepare("
        UPDATE student_goals
        SET title=?, description=?, category=?, priority=?,
            target_value=?, unit=?, due_date=?,
            progress_percentage=?, status=?, updated_at=NOW()
        WHERE id=? AND student_id=? AND deleted_at IS NULL
    ")->execute([
        $title,
        $description,
        $category,
        $priority,
        $target_value,
        $unit,
        $due_date,
        $percentage,
        $status,
        $goal_id,
        $student_id
    ]);

    $pdo->commit();
    jsonResponse(['success' => true, 'message' => 'Goal updated']);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    jsonResponse(['success' => false, 'error' => $e->getMessage()], 400);
}
