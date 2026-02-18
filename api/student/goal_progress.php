<?php
session_start();
header('Content-Type: application/json; charset=utf-8');
ini_set('display_errors', 0);
error_reporting(E_ALL);

require_once '../../includes/db_connection.php';
require_once '../../includes/functions.php';
require_once '../_helpers.php';

requirePost();
checkAuth('student');
verifyCsrf();
$student_id = (int)($_SESSION['user_id'] ?? 0);
$goal_id    = (int)($_POST['goal_id'] ?? 0);
$progress   = round((float)($_POST['progress_value'] ?? 0), 2);
$notes      = trim($_POST['notes'] ?? '');

if ($student_id <= 0 || $goal_id <= 0 || $progress <= 0) {
  jsonResponse(['success' => false, 'error' => 'Invalid input'], 400);
}

$pdo->beginTransaction();

try {
  $q = $pdo->prepare("
    SELECT *
    FROM student_goals
    WHERE id=? AND student_id=? AND deleted_at IS NULL
    FOR UPDATE
  ");
  $q->execute([$goal_id, $student_id]);
  $goal = $q->fetch(PDO::FETCH_ASSOC);

  if (!$goal) throw new Exception("Goal not found");

  if (($goal['status'] ?? '') === 'completed') {
    $pdo->rollBack();
    jsonResponse(['success' => true, 'message' => 'Already completed']);
  }

  // Admin goal check
  $adminGoal = null;
  if (!empty($goal['goal_id'])) {
    $a = $pdo->prepare("SELECT reward_points, requires_approval FROM admin_goals WHERE id=?");
    $a->execute([(int)$goal['goal_id']]);
    $adminGoal = $a->fetch(PDO::FETCH_ASSOC);
  }

  // Approval flow
  if ($adminGoal && (int)$adminGoal['requires_approval'] === 1) {
    $pendingCheck = $pdo->prepare("
      SELECT id FROM goal_progress
      WHERE goal_id=? AND student_id=? AND status='pending'
      LIMIT 1
    ");
    $pendingCheck->execute([$goal_id, $student_id]);
    if ($pendingCheck->fetch()) throw new Exception("You already have a pending request for this goal.");

    $pdo->prepare("
      INSERT INTO goal_progress (goal_id, student_id, progress_value, notes, status, created_at)
      VALUES (?, ?, ?, ?, 'pending', NOW())
    ")->execute([$goal_id, $student_id, $progress, $notes]);

    $pdo->commit();
    jsonResponse(['success' => true, 'message' => 'Progress submitted for admin approval.', 'approval_required' => true]);
  }

  // Normal progress update
  $target  = max(0, (float)$goal['target_value']);
  $current = max(0, (float)$goal['current_value']);

  $new_val = ($target > 0) ? min($current + $progress, $target) : ($current + $progress);
  $percentage = ($target > 0) ? min((($new_val / $target) * 100), 100) : 0;

  $new_val = round($new_val, 2);
  $percentage = round($percentage, 2);

  $old_status = $goal['status'] ?? 'pending';
  $new_status = ($percentage >= 100) ? 'completed' : 'in_progress';

  $st = $pdo->prepare("
    UPDATE student_goals
    SET current_value=?,
        progress_percentage=?,
        status=?,
        updated_at=NOW(),
        completed_at = CASE
          WHEN ?='completed' THEN COALESCE(completed_at, NOW())
          ELSE completed_at
        END
    WHERE id=? AND student_id=? AND deleted_at IS NULL
  ");
  $st->execute([$new_val, $percentage, $new_status, $new_status, $goal_id, $student_id]);

  if ($st->rowCount() === 0) {
    throw new Exception("Update failed: row not updated.");
  }

  $pdo->prepare("
    INSERT INTO progress_history (student_id, goal_id, progress_added, notes, created_at)
    VALUES (?, ?, ?, ?, NOW())
  ")->execute([$student_id, $goal_id, $progress, $notes]);

  $pdo->commit();

  // Verify from DB (debug once)
  $check = $pdo->prepare("SELECT current_value, progress_percentage, status FROM student_goals WHERE id=? AND student_id=?");
  $check->execute([$goal_id, $student_id]);
  $after = $check->fetch(PDO::FETCH_ASSOC);

jsonResponse([
  'success' => true,
  'message' => ($new_status === 'completed' ? 'Goal completed!' : 'Progress updated!'),
  'new_value' => $new_val,
  'percentage' => $percentage,
  'status' => $new_status
]);


} catch (Throwable $e) {
  if ($pdo->inTransaction()) $pdo->rollBack();
  jsonResponse(['success' => false, 'error' => $e->getMessage()], 400);
}
