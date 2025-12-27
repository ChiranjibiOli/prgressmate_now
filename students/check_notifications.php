<?php
require_once '../includes/db_connection.php';
checkAuth('student');

header('Content-Type: application/json');

$student_id = $_SESSION['user_id'];
$unread = getStat($pdo, "SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0", [$student_id]);

echo json_encode(['unread' => $unread]);
?>