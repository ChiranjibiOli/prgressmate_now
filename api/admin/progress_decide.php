<?php
session_start();
require_once '../../includes/db_connection.php';
require_once '../../includes/functions.php';
require_once '../_helpers.php';

requirePost();
checkAuth('admin');
verifyCsrf();

$admin_id = (int)$_SESSION['user_id'];
$progress_id = (int)($_POST['progress_id'] ?? 0);
$action = $_POST['action'] ?? '';
$admin_note = trim($_POST['admin_note'] ?? '');

if ($progress_id <= 0 || !in_array($action, ['approve', 'reject'])) {
  jsonResponse(['success' => false, 'error' => 'Invalid input'], 400);
}

$pdo->beginTransaction();

try {

  $stmt = $pdo->prepare("
    SELECT gp.*, sg.target_value, sg.current_value, sg.status AS goal_status,
           sg.student_id, sg.goal_id
    FROM goal_progress gp
    JOIN student_goals sg ON gp.goal_id = sg.id
    WHERE gp.id=? AND gp.status='pending'
    FOR UPDATE
  ");
  $stmt->execute([$progress_id]);
  $row = $stmt->fetch(PDO::FETCH_ASSOC);

  if (!$row) throw new Exception("Request not found");

  if ($action === 'reject') {

    $pdo->prepare("
      UPDATE goal_progress
      SET status='rejected', approved_at=NOW(), approved_by=?
      WHERE id=?
    ")->execute([$admin_id, $progress_id]);

    $pdo->commit();
    jsonResponse(['success' => true, 'message' => 'Progress rejected']);
  }

  // APPROVE LOGIC

  $new_val = min(
    $row['current_value'] + $row['progress_value'],
    $row['target_value']
  );

  $percentage = ($row['target_value'] > 0)
    ? min(($new_val / $row['target_value']) * 100, 100)
    : 0;

  $new_status = ($percentage >= 100) ? 'completed' : 'in_progress';

  $pdo->prepare("
    UPDATE student_goals
    SET current_value=?,
        progress_percentage=?,
        status=?,
        completed_at = CASE
            WHEN ?='completed' THEN COALESCE(completed_at,NOW())
            ELSE completed_at
        END
    WHERE id=?
  ")->execute([
    $new_val,
    $percentage,
    $new_status,
    $new_status,
    $row['goal_id']
  ]);

  // mark progress approved
  $pdo->prepare("
    UPDATE goal_progress
    SET status='approved',
        approved_at=NOW(),
        approved_by=?
    WHERE id=?
  ")->execute([$admin_id, $progress_id]);

  // If completed → reward points
  if ($row['goal_status'] !== 'completed' && $new_status === 'completed') {

    $adminGoal = null;
    if (!empty($row['goal_id'])) {
      $a = $pdo->prepare("SELECT reward_points FROM admin_goals WHERE id=?");
      $a->execute([$row['goal_id']]);
      $adminGoal = $a->fetch(PDO::FETCH_ASSOC);
    }

    $points = (int)($adminGoal['reward_points'] ?? 0);

    if ($points > 0) {
      $pdo->prepare("
            UPDATE users SET points = COALESCE(points,0) + ?
            WHERE id=?
          ")->execute([$points, $row['student_id']]);
    }

    awardAchievements($pdo, $row['student_id']);
  }

  $pdo->commit();

  jsonResponse(['success' => true, 'message' => 'Progress approved']);
} catch (Throwable $e) {
  if ($pdo->inTransaction()) $pdo->rollBack();
  jsonResponse(['success' => false, 'error' => $e->getMessage()], 400);
}
