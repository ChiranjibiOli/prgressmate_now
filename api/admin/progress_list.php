<?php
session_start();
require_once '../../includes/db_connection.php';
require_once '../../includes/functions.php';
require_once '../_helpers.php';

requireGet();
checkAuth('admin');

$stmt = $pdo->query("
  SELECT gp.id,
         gp.progress_value,
         gp.notes,
         gp.created_at,
         u.name AS student_name,
         u.email AS student_email,
         sg.title AS goal_title
  FROM goal_progress gp
  JOIN users u ON gp.student_id = u.id
  JOIN student_goals sg ON gp.goal_id = sg.id
  WHERE gp.status = 'pending'
  ORDER BY gp.created_at DESC
");

$items = $stmt->fetchAll(PDO::FETCH_ASSOC);

jsonResponse(['success' => true, 'items' => $items]);
