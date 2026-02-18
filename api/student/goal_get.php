<?php
session_start();
require_once '../../includes/db_connection.php';
require_once '../../includes/functions.php';
require_once '../_helpers.php';

requireGet();
checkAuth('student');

$student_id = (int)$_SESSION['user_id'];
$goal_id = (int)($_GET['id'] ?? 0);

if ($goal_id <= 0) jsonResponse(['success' => false, 'error' => 'Invalid id'], 400);

$stmt = $pdo->prepare("
  SELECT 
    id,title,description,category,priority,target_value,unit,due_date,
    current_value,progress_percentage,status,
    is_admin_created, is_self_created, goal_id
  FROM student_goals
  WHERE id=? AND student_id=? AND deleted_at IS NULL
  LIMIT 1
");
$stmt->execute([$goal_id, $student_id]);
$goal = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$goal) jsonResponse(['success' => false, 'error' => 'Not found'], 404);

$goal['can_edit']   = ((int)$goal['is_admin_created'] !== 1)
                   && ((int)($goal['is_self_created'] ?? 0) === 1)
                   && ($goal['status'] === 'completed');

$goal['can_delete'] = $goal['can_edit'];

jsonResponse(['success' => true, 'goal' => $goal]);
