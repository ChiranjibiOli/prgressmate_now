<?php
// api/student/goal_get.php
if (session_status() === PHP_SESSION_NONE) session_start();
require_once '../../includes/db_connection.php';
require_once '../../includes/functions.php';

header('Content-Type: application/json');

// Must be logged in as student
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'student') {
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

$student_id = (int) $_SESSION['user_id'];
$goal_id    = (int) ($_GET['id'] ?? 0);

if ($goal_id <= 0) {
    echo json_encode(['success' => false, 'error' => 'Invalid goal ID']);
    exit;
}

try {
    $stmt = $pdo->prepare("
        SELECT * FROM student_goals
        WHERE id = ? AND student_id = ? AND deleted_at IS NULL
    ");
    $stmt->execute([$goal_id, $student_id]);
    $goal = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$goal) {
        echo json_encode(['success' => false, 'error' => 'Goal not found']);
        exit;
    }

    // Only self-created goals can be edited
    $goal['can_edit'] = (bool) $goal['is_self_created'];

    echo json_encode(['success' => true, 'goal' => $goal]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}