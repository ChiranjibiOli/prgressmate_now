<?php
session_start();
require_once '../../includes/db_connection.php';
require_once '../../includes/functions.php';
require_once '../_helpers.php';

requirePost();
checkAuth('student');
verifyCsrf();

$student_id = (int)$_SESSION['user_id'];
$goal_id    = (int)($_POST['goal_id'] ?? 0);

if ($goal_id <= 0) {
    jsonResponse(['success' => false, 'error' => 'Invalid id'], 400);
}

$pdo->beginTransaction();
try {
   $q = $pdo->prepare("
    SELECT status, is_admin_created, is_self_created
    FROM student_goals
    WHERE id=? AND student_id=? AND deleted_at IS NULL
    FOR UPDATE
");

    $q->execute([$goal_id, $student_id]);
    $g = $q->fetch(PDO::FETCH_ASSOC);

    if (!$g) throw new Exception("Not found");

  if ((int)($g['is_admin_created'] ?? 0) === 1) {

        throw new Exception("You cannot delete admin-created goals.");
    }

// ✅ Rule 2: Must be self-created
if ((int)($g['is_self_created'] ?? 0) !== 1) {
    throw new Exception("You can only delete your own created goals.");
}

if (($g['status'] ?? '') !== 'completed') {
    throw new Exception("You can only delete a goal after it is completed.");
}

    $pdo->prepare("
        UPDATE student_goals
        SET deleted_at = NOW()
        WHERE id=? AND student_id=? AND deleted_at IS NULL
    ")->execute([$goal_id, $student_id]);

    $pdo->commit();
    jsonResponse(['success' => true, 'message' => 'Goal deleted']);
} catch (Throwable $e) {
    $pdo->rollBack();
    jsonResponse(['success' => false, 'error' => $e->getMessage()], 400);
}
