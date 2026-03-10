<?php
// api/student/goal_delete.php
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

$student_id = (int) $_SESSION['user_id'];
$goal_id    = (int) ($_POST['goal_id'] ?? 0);

if ($goal_id <= 0) {
    echo json_encode(['success' => false, 'error' => 'Invalid goal ID']);
    exit;
}

try {
    // Verify the goal belongs to this student
    $check = $pdo->prepare("
        SELECT id, title, is_self_created
        FROM student_goals
        WHERE id = ? AND student_id = ? AND deleted_at IS NULL
    ");
    $check->execute([$goal_id, $student_id]);
    $goal = $check->fetch(PDO::FETCH_ASSOC);

    if (!$goal) {
        echo json_encode(['success' => false, 'error' => 'Goal not found']);
        exit;
    }

    // Soft delete — sets deleted_at instead of removing the row
    // This preserves progress history integrity
    $stmt = $pdo->prepare("
        UPDATE student_goals
        SET deleted_at = NOW(),
            updated_at = NOW()
        WHERE id = ? AND student_id = ?
    ");
    $stmt->execute([$goal_id, $student_id]);

    echo json_encode([
        'success' => true,
        'message' => "Goal \"{$goal['title']}\" deleted successfully"
    ]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}