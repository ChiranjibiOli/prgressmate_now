<?php
session_start();
require_once '../../includes/db_connection.php';
require_once '../../includes/functions.php';
require_once '../_helpers.php';

requireGet();
checkAuth('admin');

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) jsonResponse(['success' => false, 'error' => 'Invalid id'], 400);

$stmt = $pdo->prepare("
  SELECT gp.*, sg.title AS goal_title, u.name AS student_name, u.email AS student_email
  FROM goal_progress gp
  JOIN student_goals sg ON sg.id = gp.goal_id
  JOIN users u ON u.id = gp.student_id
  WHERE gp.id=?
  LIMIT 1
");
$stmt->execute([$id]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$row) jsonResponse(['success' => false, 'error' => 'Not found'], 404);

jsonResponse(['success' => true, 'item' => $row]);
