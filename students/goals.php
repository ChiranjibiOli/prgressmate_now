<?php
session_start();
require_once '../includes/db_connection.php';
require_once '../includes/functions.php';

// Check if user is logged in as student
checkAuth('student');

$student_id = $_SESSION['user_id'];

// Auto-mark overdue goals (past due date and not completed)
$pdo->prepare("
    UPDATE student_goals
    SET status = 'overdue'
    WHERE student_id = ?
      AND deleted_at IS NULL
      AND status != 'completed'
      AND due_date IS NOT NULL
      AND due_date < CURDATE()
")->execute([$student_id]);


// Initialize variables
$success = '';
$error = '';

// Get sidebar stats
$total_goals = getStat($pdo, "SELECT COUNT(*) FROM student_goals WHERE student_id=?", [$student_id]);
$completed_goals = getStat($pdo, "SELECT COUNT(*) FROM student_goals WHERE student_id=? AND status='completed'", [$student_id]);
$in_progress_goals = getStat($pdo, "SELECT COUNT(*) FROM student_goals WHERE student_id=? AND status='in_progress'", [$student_id]);
$total_points = getStat($pdo, "SELECT points FROM users WHERE id=?", [$student_id]);
$streak = getStat($pdo, "SELECT current_streak FROM users WHERE id=?", [$student_id]);

$unread = getStat($pdo, "SELECT COUNT(*) FROM notifications WHERE user_id=? AND is_read=0", [$student_id]);

session_start();
require_once '../includes/db_connection.php';
checkAuth('student');

$student_id = $_SESSION['user_id'];

// === UPDATE PROGRESS ===
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_progress'])) {
    $goal_id = (int)($_POST['goal_id'] ?? 0);
    $progress = floatval($_POST['progress_value'] ?? 0);

    if ($goal_id <= 0 || $progress <= 0) {
        $_SESSION['error'] = 'Please enter valid progress for a valid goal.';
        header("Location: goals.php");
        exit;
    }

    $goal = $pdo->prepare("SELECT * FROM student_goals WHERE id=? AND student_id=?");
    $goal->execute([$goal_id, $student_id]);
    $goal = $goal->fetch();

    if ($goal) {
        try {
        $new_val = min($goal['current_value'] + $progress, $goal['target_value']);
        
        if ((float)$goal['target_value'] > 0) {
            $percentage = ($new_val / $goal['target_value']) * 100;
        } else {
            $percentage = 0;
        }
        
        $old_status = $goal['status'];
        $new_status = $percentage >= 100 ? 'completed' : 'in_progress';

        $pdo->beginTransaction();

        $stmt = $pdo->prepare("
            UPDATE student_goals 
            SET current_value=?, progress_percentage=?, status=?, updated_at=NOW() 
            WHERE id=? AND student_id=?
        ");
        $stmt->execute([$new_val, $percentage, $new_status, $goal_id, $student_id]);

        // Add progress history
        $hist = $pdo->prepare("
            INSERT INTO progress_history (student_id, goal_id, log_date, progress_added, notes) 
            VALUES (?, ?, CURDATE(), ?, ?)
        ");
        $hist->execute([$student_id, $goal_id, $progress, trim($_POST['notes'] ?? '')]);

        // Check if goal was just completed
        if ($old_status !== 'completed' && $new_status === 'completed') {
            // Set completion date
            $pdo->prepare("
                UPDATE student_goals 
                SET completed_at = NOW() 
                WHERE id = ? AND student_id = ?
            ")->execute([$goal_id, $student_id]);
            
            // Award achievements
            $achievements_awarded = awardAchievements($pdo, $student_id);
            
            if ($achievements_awarded > 0) {
                $_SESSION['success'] = "Progress updated! 🎉 You earned $achievements_awarded new achievement(s)!";
            } else {
                $_SESSION['success'] = "Progress updated! Goal completed!";
            }
        } else {
            $_SESSION['success'] = "Progress updated!";
        }

        $pdo->commit();
        header("Location: goals.php");
        exit;
        } catch (Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $_SESSION['error'] = 'Unable to update progress right now.';
            header("Location: goals.php");
            exit;
        }
    }
}

// === EDIT GOAL ===
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_goal'])) {
    $goal_id = $_POST['goal_id'];
    
    // Check if goal belongs to student
    $check = $pdo->prepare("SELECT id FROM student_goals WHERE id=? AND student_id=?");
    $check->execute([$goal_id, $student_id]);
    
    if ($check->fetch()) {
        $update_data = [
            'title' => $_POST['title'],
            'description' => $_POST['description'] ?? NULL,
            'category' => $_POST['category'] ?? NULL,
            'priority' => $_POST['priority'] ?? 'medium',
            'target_value' => floatval($_POST['target_value']),
            'unit' => $_POST['unit'] ?? NULL,
            'due_date' => $_POST['due_date'] ?: NULL,
            'updated_at' => date('Y-m-d H:i:s')
        ];
        
        $update_sql = "UPDATE student_goals SET ";
        $params = [];
        foreach ($update_data as $key => $value) {
            $update_sql .= "$key = ?, ";
            $params[] = $value;
        }
        $update_sql = rtrim($update_sql, ', ') . " WHERE id = ? AND student_id = ?";
        $params[] = $goal_id;
        $params[] = $student_id;
        
        $update_stmt = $pdo->prepare($update_sql);
        if ($update_stmt->execute($params)) {
            $_SESSION['success'] = "Goal updated successfully!";
            header("Location: goals.php");
            exit;
        } else {
            $_SESSION['error'] = "Failed to update goal.";
        }
    } else {
        $_SESSION['error'] = "Goal not found or access denied.";
    }
}

// === DELETE GOAL ===
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_goal'])) {
    $goal_id = $_POST['goal_id'];
    
    // Check if goal belongs to student
    $check = $pdo->prepare("SELECT id FROM student_goals WHERE id=? AND student_id=?");
    $check->execute([$goal_id, $student_id]);
    
    if ($check->fetch()) {
        // Delete progress history first
        $del_history = $pdo->prepare("DELETE FROM progress_history WHERE goal_id = ?");
        $del_history->execute([$goal_id]);
        
        // Delete goal
        $del_goal = $pdo->prepare("DELETE FROM student_goals WHERE id = ? AND student_id = ?");
        if ($del_goal->execute([$goal_id, $student_id])) {
            $_SESSION['success'] = "Goal deleted successfully!";
            header("Location: goals.php");
            exit;
        } else {
            $_SESSION['error'] = "Failed to delete goal.";
        }
    } else {
        $_SESSION['error'] = "Goal not found or access denied.";
    }
}

// Check for session messages
if (isset($_SESSION['success'])) {
    $success = $_SESSION['success'];
    unset($_SESSION['success']);
}
if (isset($_SESSION['error'])) {
    $error = $_SESSION['error'];
    unset($_SESSION['error']);
}

// Fetch all goals for this student
$goals_query = $pdo->prepare("
    SELECT * FROM student_goals 
    WHERE student_id = ? 
    ORDER BY 
        CASE priority 
            WHEN 'high' THEN 1 
            WHEN 'medium' THEN 2 
            WHEN 'low' THEN 3 
            ELSE 4 
        END,
        due_date ASC,
        created_at DESC
");
$goals_query->execute([$student_id]);
$goals = $goals_query->fetchAll(PDO::FETCH_ASSOC);

// Get unique categories for filter/dropdown
$categories_stmt = $pdo->prepare("SELECT DISTINCT category FROM student_goals WHERE student_id=? AND category IS NOT NULL");
$categories_stmt->execute([$student_id]);
$categories = $categories_stmt->fetchAll(PDO::FETCH_COLUMN);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Goals - ProgressMate</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    
    <style>
        /* ===== CSS VARIABLES & THEME ===== */
        :root {
            --primary: #4f46e5;
            --primary-light: #eef2ff;
            --primary-dark: #4338ca;
            --secondary: #64748b;
            --secondary-light: #f8fafc;
            --secondary-dark: #475569;
            --success: #10b981;
            --success-light: #d1fae5;
            --warning: #f59e0b;
            --warning-light: #fef3c7;
            --danger: #ef4444;
            --danger-light: #fee2e2;
            --info: #3b82f6;
            --info-light: #dbeafe;
            --dark: #1e293b;
            --light: #f1f5f9;
            --white: #ffffff;
            --gray: #e2e8f0;
            --gray-light: #f9fafb;
            --gray-dark: #6b7280;
            --font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            --font-size-xs: 0.75rem;
            --font-size-sm: 0.875rem;
            --font-size-base: 1rem;
            --font-size-lg: 1.125rem;
            --font-size-xl: 1.25rem;
            --font-size-2xl: 1.5rem;
            --font-size-3xl: 1.875rem;
            --font-size-4xl: 2.25rem;
            --spacing-xs: 0.25rem;
            --spacing-sm: 0.5rem;
            --spacing-md: 1rem;
            --spacing-lg: 1.5rem;
            --spacing-xl: 2rem;
            --spacing-2xl: 3rem;
            --radius-sm: 0.375rem;
            --radius-md: 0.5rem;
            --radius-lg: 0.75rem;
            --radius-xl: 1rem;
            --radius-2xl: 1.5rem;
            --shadow-sm: 0 1px 2px 0 rgba(0, 0, 0, 0.05);
            --shadow-md: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
            --shadow-lg: 0 10px 15px -3px rgba(0, 0, 0, 0.1);
            --shadow-xl: 0 20px 25px -5px rgba(0, 0, 0, 0.1);
            --transition-fast: 150ms ease;
            --transition-base: 300ms ease;
            --transition-slow: 500ms ease;
        }

        /* ===== BASE RESET ===== */
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        html {
            font-size: 16px;
            scroll-behavior: smooth;
        }

        body {
            font-family: var(--font-family);
            background: linear-gradient(135deg, #f5f7fa 0%, #e4edf5 100%);
            color: var(--dark);
            min-height: 100vh;
            line-height: 1.5;
            overflow-x: hidden;
        }

        a {
            text-decoration: none;
            color: inherit;
            transition: color var(--transition-fast);
        }

        button {
            font-family: inherit;
            cursor: pointer;
            border: none;
            background: none;
            outline: none;
        }

        input, select, textarea {
            font-family: inherit;
            font-size: inherit;
            outline: none;
        }

        img {
            max-width: 100%;
            height: auto;
        }

        /* ===== DASHBOARD LAYOUT ===== */
        .dashboard-wrapper {
            display: flex;
            min-height: 100vh;
            position: relative;
        }

        /* ===== SIDEBAR ===== */
        .sidebar {
            width: 280px;
            background: var(--white);
            border-right: 1px solid var(--gray);
            position: fixed;
            height: 100vh;
            left: 0;
            top: 0;
            z-index: 1000;
            display: flex;
            flex-direction: column;
            transition: transform var(--transition-base);
            overflow: hidden;
            box-shadow: 4px 0 20px rgba(0, 0, 0, 0.08);
        }

        .sidebar-header {
            padding: var(--spacing-lg);
            border-bottom: 1px solid var(--gray);
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-shrink: 0;
        }

        .logo {
            font-size: var(--font-size-xl);
            font-weight: 800;
            color: var(--primary);
            display: flex;
            align-items: center;
            gap: var(--spacing-sm);
        }

        .sidebar-close {
            display: none;
            color: var(--secondary);
            font-size: var(--font-size-xl);
            background: none;
            border: none;
            cursor: pointer;
            padding: var(--spacing-xs);
        }

        .user-profile {
            padding: var(--spacing-lg);
            border-bottom: 1px solid var(--gray);
            text-align: center;
            flex-shrink: 0;
        }

        .profile-pic {
            width: 80px;
            height: 80px;
            border-radius: 50%;
            object-fit: cover;
            border: 4px solid var(--primary-light);
            margin: 0 auto var(--spacing-md);
        }

        .profile-pic.default {
            background: linear-gradient(135deg, var(--primary), #8b5cf6);
            color: var(--white);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: var(--font-size-2xl);
            font-weight: bold;
        }

        .user-info h4 {
            font-size: var(--font-size-lg);
            font-weight: 600;
            margin-bottom: var(--spacing-xs);
        }

        .user-info p {
            color: var(--secondary);
            font-size: var(--font-size-sm);
            margin-bottom: var(--spacing-sm);
        }

        /* Nav Menu */
        .nav-menu {
            flex: 1;
            padding: var(--spacing-md) 0;
            overflow-y: auto;
            min-height: 0;
        }

        .nav-menu::-webkit-scrollbar {
            width: 6px;
        }

        .nav-menu::-webkit-scrollbar-track {
            background: var(--light);
        }

        .nav-menu::-webkit-scrollbar-thumb {
            background: var(--gray);
            border-radius: 3px;
        }

        .nav-link {
            display: flex;
            align-items: center;
            gap: var(--spacing-sm);
            padding: 0.875rem var(--spacing-lg);
            margin: 0 var(--spacing-sm);
            color: var(--secondary-dark);
            font-weight: 500;
            border-radius: var(--radius-md);
            transition: all var(--transition-base);
        }

        .nav-link:hover {
            background: var(--secondary-light);
            color: var(--primary);
            transform: translateX(4px);
        }

        .nav-link.active {
            background: linear-gradient(90deg, var(--primary-light), transparent);
            color: var(--primary);
            font-weight: 600;
            border-left: 4px solid var(--primary);
        }

        .nav-link i {
            width: 24px;
            text-align: center;
            font-size: 1.125rem;
        }

        .badge {
            background: linear-gradient(135deg, var(--danger), #dc2626);
            color: var(--white);
            font-size: var(--font-size-xs);
            padding: 0.25rem 0.5rem;
            border-radius: 20px;
            margin-left: auto;
            font-weight: 700;
            min-width: 1.5rem;
            text-align: center;
        }

        .sidebar-quick-stats {
            padding: var(--spacing-lg);
            background: var(--secondary-light);
            border-top: 1px solid var(--gray);
            flex-shrink: 0;
        }

        .sidebar-stat {
            display: flex;
            align-items: center;
            gap: var(--spacing-sm);
            margin-bottom: var(--spacing-md);
        }

        .sidebar-stat:last-child {
            margin-bottom: 0;
        }

        .sidebar-stat-icon {
            width: 48px;
            height: 48px;
            border-radius: var(--radius-md);
            background: var(--primary-light);
            color: var(--primary);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.25rem;
        }

        .sidebar-stat-number {
            font-size: var(--font-size-lg);
            font-weight: 700;
            color: var(--dark);
        }

        .sidebar-stat-label {
            font-size: var(--font-size-xs);
            color: var(--secondary);
        }

        .logout-btn {
            margin: var(--spacing-lg);
            background: linear-gradient(135deg, var(--danger-light), #fecaca);
            color: #dc2626;
            padding: 0.875rem;
            border-radius: var(--radius-lg);
            font-weight: 600;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: var(--spacing-sm);
            transition: all var(--transition-base);
            text-align: center;
            text-decoration: none;
            flex-shrink: 0;
        }

        .logout-btn:hover {
            background: linear-gradient(135deg, #fecaca, #fca5a5);
            transform: translateY(-2px);
        }

        /* ===== MAIN CONTENT ===== */
        .main-content {
            flex: 1;
            margin-left: 280px;
            padding: var(--spacing-xl);
            width: calc(100% - 280px);
            min-height: 100vh;
            overflow-y: auto;
            animation: fadeIn 0.5s ease;
        }

        @keyframes fadeIn {
            from {
                opacity: 0;
                transform: translateY(10px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .main-content::-webkit-scrollbar {
            width: 8px;
        }

        .main-content::-webkit-scrollbar-track {
            background: var(--light);
        }

        .main-content::-webkit-scrollbar-thumb {
            background: var(--gray);
            border-radius: 4px;
        }

        .main-content::-webkit-scrollbar-thumb:hover {
            background: var(--secondary);
        }

        /* ===== PAGE HEADER ===== */
        .page-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: var(--spacing-2xl);
            flex-wrap: wrap;
            gap: var(--spacing-md);
        }

        .page-header h1 {
            font-size: var(--font-size-3xl);
            font-weight: 800;
            color: var(--dark);
            margin-bottom: var(--spacing-xs);
        }

        .page-header p {
            color: var(--secondary);
            font-size: var(--font-size-base);
        }

        /* ===== BUTTONS ===== */
        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: var(--spacing-sm);
            padding: 0.625rem 1.25rem;
            border-radius: var(--radius-md);
            font-weight: 500;
            font-size: var(--font-size-sm);
            transition: all var(--transition-base);
            border: 1px solid transparent;
            cursor: pointer;
        }

        .btn-primary {
            background: linear-gradient(135deg, var(--primary), var(--primary-dark));
            color: var(--white);
            box-shadow: var(--shadow-md);
        }

        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow-lg);
        }

        .btn-secondary {
            background: var(--secondary-light);
            color: var(--secondary-dark);
            border: 1px solid var(--gray);
        }

        .btn-secondary:hover {
            background: var(--gray);
            transform: translateY(-2px);
        }

        .btn-danger {
            background: linear-gradient(135deg, var(--danger), #dc2626);
            color: var(--white);
            box-shadow: var(--shadow-md);
        }

        .btn-danger:hover {
            background: linear-gradient(135deg, #dc2626, #b91c1c);
            transform: translateY(-2px);
            box-shadow: var(--shadow-lg);
        }

        .btn-sm {
            padding: 0.375rem 0.75rem;
            font-size: var(--font-size-xs);
        }

        /* ===== ALERTS ===== */
        .alert {
            padding: var(--spacing-md);
            border-radius: var(--radius-md);
            margin-bottom: var(--spacing-lg);
            display: flex;
            align-items: center;
            gap: var(--spacing-sm);
            animation: slideIn 0.3s ease;
        }

        @keyframes slideIn {
            from {
                opacity: 0;
                transform: translateX(-10px);
            }
            to {
                opacity: 1;
                transform: translateX(0);
            }
        }

        .alert-success {
            background: var(--success-light);
            color: #065f46;
            border: 1px solid #a7f3d0;
        }

        .alert-error {
            background: var(--danger-light);
            color: #991b1b;
            border: 1px solid #fca5a5;
        }

        .alert-warning {
            background: var(--warning-light);
            color: #92400e;
            border: 1px solid #fde68a;
        }

        .alert-info {
            background: var(--info-light);
            color: #1e40af;
            border: 1px solid #93c5fd;
        }

        /* ===== STATS GRID ===== */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: var(--spacing-lg);
            margin-bottom: var(--spacing-2xl);
        }

        .stat-card {
            background: var(--white);
            border-radius: var(--radius-lg);
            padding: var(--spacing-lg);
            box-shadow: var(--shadow-md);
            transition: all var(--transition-base);
            border: 1px solid var(--gray);
        }

        .stat-card:hover {
            transform: translateY(-4px);
            box-shadow: var(--shadow-lg);
        }

        .stat-content {
            display: flex;
            align-items: center;
            gap: var(--spacing-md);
        }

        .stat-icon {
            width: 60px;
            height: 60px;
            border-radius: var(--radius-lg);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
            color: var(--white);
        }

        .stat-card.goals .stat-icon {
            background: linear-gradient(135deg, #6366f1, #8b5cf6);
        }

        .stat-card.completed .stat-icon {
            background: linear-gradient(135deg, var(--success), #059669);
        }

        .stat-card.progress .stat-icon {
            background: linear-gradient(135deg, var(--warning), #d97706);
        }

        .stat-card.points .stat-icon {
            background: linear-gradient(135deg, #f97316, #ea580c);
        }

        .stat-number {
            font-size: var(--font-size-3xl);
            font-weight: 800;
            color: var(--dark);
            line-height: 1;
        }

        .stat-label {
            font-size: var(--font-size-sm);
            color: var(--secondary);
            margin-top: var(--spacing-xs);
        }

        /* ===== GOALS GRID ===== */
        .goals-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(350px, 1fr));
            gap: 1.5rem;
            margin-top: 2rem;
        }

        .goal-card {
            background: var(--white);
            border-radius: var(--radius-lg);
            box-shadow: var(--shadow-md);
            border: 1px solid var(--gray);
            overflow: hidden;
            transition: var(--transition-base);
            display: flex;
            flex-direction: column;
        }

        .goal-card:hover {
            transform: translateY(-5px);
            box-shadow: var(--shadow-lg);
        }

        .goal-card.high-priority {
            border-left: 4px solid #dc2626;
        }

        .goal-card.medium-priority {
            border-left: 4px solid #f59e0b;
        }

        .goal-card.low-priority {
            border-left: 4px solid #10b981;
        }

        .goal-card.completed {
            opacity: 0.9;
            background: linear-gradient(to bottom, var(--success-light), var(--white));
        }

        .goal-header {
            padding: 1.25rem 1.5rem;
            border-bottom: 1px solid var(--gray);
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            background: var(--gray-light);
        }

        .goal-title {
            font-weight: 700;
            font-size: 1.1rem;
            color: var(--dark);
            margin-bottom: 0.5rem;
            line-height: 1.3;
        }

        .goal-category {
            display: inline-block;
            background: var(--primary-light);
            color: var(--primary);
            padding: 0.25rem 0.75rem;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
        }

        .goal-header-actions {
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }

        /* Status badges */
        .status-completed {
            background: var(--success-light);
            color: #065f46;
            padding: 0.25rem 0.75rem;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
        }

        .status-in_progress {
            background: var(--info-light);
            color: #1e40af;
            padding: 0.25rem 0.75rem;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
        }

        .status-pending {
            background: var(--light);
            color: var(--gray-dark);
            padding: 0.25rem 0.75rem;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
        }

        /* Dropdown */
        .dropdown {
            position: relative;
        }

        .dropdown-toggle {
            background: none;
            border: none;
            color: var(--secondary);
            padding: 0.5rem;
            cursor: pointer;
            border-radius: var(--radius-sm);
            transition: var(--transition-fast);
        }

        .dropdown-toggle:hover {
            background: var(--gray);
        }

        .dropdown-menu {
            display: none;
            position: absolute;
            right: 0;
            top: 100%;
            background: var(--white);
            border-radius: var(--radius-md);
            box-shadow: var(--shadow-lg);
            min-width: 160px;
            z-index: 100;
            border: 1px solid var(--gray);
        }

        .dropdown-item {
            display: block;
            padding: 0.75rem 1rem;
            color: var(--dark);
            text-decoration: none;
            transition: var(--transition-fast);
            border-bottom: 1px solid var(--gray);
        }

        .dropdown-item:last-child {
            border-bottom: none;
        }

        .dropdown-item:hover {
            background: var(--primary-light);
            color: var(--primary);
        }

        .dropdown-item.text-danger {
            color: var(--danger);
        }

        .dropdown-item.text-danger:hover {
            background: var(--danger-light);
            color: #991b1b;
        }

        /* Goal Body */
        .goal-body {
            padding: 1.5rem;
            flex: 1;
        }

        .goal-description {
            font-size: 0.95rem;
            color: var(--secondary-dark);
            margin-bottom: 1.25rem;
            line-height: 1.5;
        }

        .goal-meta {
            display: flex;
            flex-wrap: wrap;
            gap: 1rem;
            margin-bottom: 1.5rem;
            font-size: 0.9rem;
        }

        .goal-meta-item {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            color: var(--secondary);
        }

        .goal-meta-item i {
            color: var(--primary);
        }

        /* Progress Section */
        .progress-section {
            margin-bottom: 1.5rem;
        }

        .progress-header {
            display: flex;
            justify-content: space-between;
            margin-bottom: 0.5rem;
            font-size: 0.9rem;
        }

        .progress-label {
            color: var(--secondary);
        }

        .progress-percentage {
            font-weight: 700;
            color: var(--primary);
        }

        .progress-bar {
            height: 10px;
            background: var(--gray);
            border-radius: 5px;
            overflow: hidden;
            margin: var(--spacing-sm) 0;
        }

        .progress-fill {
            height: 100%;
            background: linear-gradient(90deg, var(--primary), #7c3aed);
            border-radius: 5px;
            transition: width var(--transition-slow);
        }

        .progress-stats {
            display: flex;
            justify-content: space-between;
            font-size: 0.85rem;
            color: var(--secondary);
            margin-top: 0.5rem;
        }

        .progress-chart {
            height: 60px;
            margin: 1rem 0;
        }

        /* Goal Footer */
        .goal-footer {
            padding: 1.25rem 1.5rem;
            border-top: 1px solid var(--gray);
            background: var(--gray-light);
            display: flex;
            gap: 0.75rem;
        }

        .goal-footer .btn {
            flex: 1;
        }

        /* ===== EMPTY STATES ===== */
        .empty-state {
            text-align: center;
            padding: var(--spacing-2xl);
            color: var(--secondary);
            grid-column: 1 / -1;
        }

        .empty-state i {
            font-size: 4rem;
            margin-bottom: var(--spacing-lg);
            opacity: 0.5;
        }

        .empty-state p {
            margin-bottom: var(--spacing-lg);
            font-size: var(--font-size-lg);
        }

        /* ===== MODAL STYLES ===== */
        .modal-overlay {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0, 0, 0, 0.5);
            z-index: 2000;
            align-items: center;
            justify-content: center;
            padding: var(--spacing-md);
            backdrop-filter: blur(3px);
        }

        .modal {
            background: var(--white);
            border-radius: var(--radius-lg);
            width: 100%;
            max-width: 500px;
            max-height: 90vh;
            overflow-y: auto;
            box-shadow: var(--shadow-xl);
            animation: modalSlideIn 0.3s ease;
        }

        @keyframes modalSlideIn {
            from {
                opacity: 0;
                transform: translateY(-20px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .modal-header {
            padding: var(--spacing-lg);
            border-bottom: 1px solid var(--gray);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .modal-header h3 {
            margin: 0;
            font-size: var(--font-size-lg);
            font-weight: 600;
        }

        .modal-close {
            background: none;
            border: none;
            color: var(--secondary);
            cursor: pointer;
            font-size: 1.5rem;
            padding: var(--spacing-xs);
        }

        .modal-body {
            padding: var(--spacing-lg);
        }

        /* Form Styles */
        .form-group {
            margin-bottom: var(--spacing-lg);
        }

        .form-group label {
            display: block;
            margin-bottom: var(--spacing-sm);
            font-weight: 500;
            color: var(--dark);
            font-size: var(--font-size-sm);
        }

        .form-group input,
        .form-group select,
        .form-group textarea {
            width: 100%;
            padding: 0.75rem 1rem;
            border: 1px solid var(--gray);
            border-radius: var(--radius-md);
            font-size: var(--font-size-sm);
            transition: all var(--transition-fast);
        }

        .form-group input:focus,
        .form-group select:focus,
        .form-group textarea:focus {
            border-color: var(--primary);
            box-shadow: 0 0 0 3px var(--primary-light);
        }

        .form-group textarea {
            min-height: 120px;
            resize: vertical;
        }

        .form-row {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: var(--spacing-lg);
        }

        .modal-actions {
            display: flex;
            gap: 1rem;
            justify-content: flex-end;
            margin-top: 1.5rem;
        }

        /* ===== MOBILE RESPONSIVE ===== */
        .mobile-toggle {
            display: none;
            position: fixed;
            top: var(--spacing-md);
            left: var(--spacing-md);
            z-index: 999;
            background: var(--primary);
            color: var(--white);
            width: 48px;
            height: 48px;
            border-radius: var(--radius-md);
            font-size: 1.25rem;
            box-shadow: var(--shadow-lg);
            transition: all var(--transition-base);
        }

        .mobile-toggle:hover {
            transform: scale(1.1);
        }

        /* Mobile responsive adjustments */
        @media (max-width: 992px) {
            .mobile-toggle {
                display: flex;
                align-items: center;
                justify-content: center;
            }
            
            .sidebar {
                transform: translateX(-100%);
                width: 300px;
                box-shadow: 4px 0 20px rgba(0, 0, 0, 0.15);
            }
            
            .sidebar.active {
                transform: translateX(0);
            }
            
            .sidebar-close {
                display: block;
            }
            
            .main-content {
                margin-left: 0;
                width: 100%;
                padding: var(--spacing-lg);
                padding-top: calc(var(--spacing-xl) + 60px);
            }
            
            .page-header {
                flex-direction: column;
                align-items: flex-start;
            }
            
            .goals-grid {
                grid-template-columns: 1fr;
            }
            
            .form-row {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 768px) {
            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
            }
            
            .main-content {
                padding: var(--spacing-md);
                padding-top: calc(var(--spacing-lg) + 60px);
            }
        }

        @media (max-width: 480px) {
            .stats-grid {
                grid-template-columns: 1fr;
            }
            
            html {
                font-size: 14px;
            }
            
            .sidebar {
                width: 85%;
                max-width: 320px;
            }
            
            .goal-footer {
                flex-direction: column;
            }
            
            .goal-meta {
                flex-direction: column;
                gap: 0.5rem;
            }
        }
    </style>
</head>
<body>
    <!-- MOBILE TOGGLE -->
    <button class="mobile-toggle" id="sidebarToggle"><i class="fas fa-bars"></i></button>

    <div class="dashboard-wrapper">
        <!-- SIDEBAR -->
        <aside class="sidebar" id="sidebar">
            <div class="sidebar-header">
                <div class="logo"><i class="fas fa-star"></i> <span>ProgressMate</span></div>
                <button class="sidebar-close" id="sidebarClose"><i class="fas fa-times"></i></button>
            </div>
            <div class="user-profile">
                <?php if (!empty($_SESSION['profile_picture'])): ?>
                    <img src="<?php echo '../' . htmlspecialchars($_SESSION['profile_picture']); ?>" alt="Profile" class="profile-pic">
                <?php else: ?>
                    <div class="profile-pic default"><?php echo strtoupper(substr($_SESSION['name'], 0, 1)); ?></div>
                <?php endif; ?>
                <div class="user-info">
                    <h4><?php echo htmlspecialchars($_SESSION['name']); ?></h4>
                    <p><?php echo htmlspecialchars($_SESSION['email']); ?></p>
                    <span style="font-size: 11px; background: #e0e7ff; color: #4f46e5; padding: 2px 8px; border-radius: 12px;">STUDENT</span>
                </div>
            </div>
            <nav class="nav-menu">
                <a href="dashboard.php" class="nav-link<?php echo basename($_SERVER['PHP_SELF']) == 'dashboard.php' ? ' active' : ''; ?>">
                    <i class="fas fa-tachometer-alt"></i> <span>Dashboard</span>
                </a>
                <a href="goals.php" class="nav-link active">
                    <i class="fas fa-bullseye"></i> <span>My Goals</span>
                    <?php if ($total_goals > 0): ?><span class="badge"><?php echo $total_goals; ?></span><?php endif; ?>
                </a>
                <a href="create_goal.php" class="nav-link<?php echo basename($_SERVER['PHP_SELF']) == 'create_goal.php' ? ' active' : ''; ?>">
                    <i class="fas fa-plus-circle"></i> <span>Create Goal</span>
                </a>
                <a href="achievements.php" class="nav-link<?php echo basename($_SERVER['PHP_SELF']) == 'achievements.php' ? ' active' : ''; ?>">
                    <i class="fas fa-trophy"></i> <span>Achievements</span>
                    <?php if ($total_points > 0): ?><span class="badge"><?php echo $total_points; ?> pts</span><?php endif; ?>
                </a>
                <a href="notifications.php" class="nav-link<?php echo basename($_SERVER['PHP_SELF']) == 'notifications.php' ? ' active' : ''; ?>">
                    <i class="fas fa-inbox"></i> <span>Notifications</span>
                    <?php if ($unread > 0): ?><span class="badge"><?php echo $unread; ?></span><?php endif; ?>
                </a>
                <a href="profile.php" class="nav-link<?php echo basename($_SERVER['PHP_SELF']) == 'profile.php' ? ' active' : ''; ?>">
                    <i class="fas fa-user"></i> <span>Profile</span>
                </a>
            </nav>
            <div class="sidebar-quick-stats">
                <div class="sidebar-stat">
                    <div class="sidebar-stat-icon"><i class="fas fa-bullseye"></i></div>
                    <div class="sidebar-stat-info">
                        <div class="sidebar-stat-label">Goals</div>
                        <div class="sidebar-stat-number"><?php echo $completed_goals; ?>/<?php echo $total_goals; ?></div>
                    </div>
                </div>
                <div class="sidebar-stat">
                    <div class="sidebar-stat-icon"><i class="fas fa-star"></i></div>
                    <div class="sidebar-stat-info">
                        <div class="sidebar-stat-label">Points</div>
                        <div class="sidebar-stat-number"><?php echo $total_points; ?></div>
                    </div>
                </div>
                <div class="sidebar-stat">
                    <div class="sidebar-stat-icon"><i class="fas fa-fire"></i></div>
                    <div class="sidebar-stat-info">
                        <div class="sidebar-stat-label">Streak</div>
                        <div class="sidebar-stat-number"><?php echo $streak; ?> days</div>
                    </div>
                </div>
            </div>
            <div class="sidebar-footer">
                <a href="../logout.php" class="logout-btn"><i class="fas fa-sign-out-alt"></i> <span>Logout</span></a>
            </div>
        </aside>

        <!-- MAIN CONTENT -->
        <main class="main-content">
            <header class="page-header">
                <div class="header-content">
                    <h1>My Goals</h1>
                    <p>Track and manage all your goals in one place</p>
                </div>
                <div class="header-actions">
                    <a href="create_goal.php" class="btn btn-primary"><i class="fas fa-plus"></i> Create Goal</a>
                </div>
            </header>

            <!-- Success/Error Alerts -->
            <?php if ($success): ?>
                <div class="alert alert-success"><i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($success); ?></div>
            <?php endif; ?>
            <?php if ($error): ?>
                <div class="alert alert-error"><i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($error); ?></div>
            <?php endif; ?>

            <!-- Stats Overview -->
            <div class="stats-grid">
                <div class="stat-card goals">
                    <div class="stat-content">
                        <div class="stat-icon">
                            <i class="fas fa-bullseye"></i>
                        </div>
                        <div>
                            <div class="stat-number"><?php echo $total_goals; ?></div>
                            <div class="stat-label">Total Goals</div>
                        </div>
                    </div>
                </div>
                
                <div class="stat-card completed">
                    <div class="stat-content">
                        <div class="stat-icon">
                            <i class="fas fa-check-circle"></i>
                        </div>
                        <div>
                            <div class="stat-number"><?php echo $completed_goals; ?></div>
                            <div class="stat-label">Completed</div>
                        </div>
                    </div>
                </div>
                
                <div class="stat-card progress">
                    <div class="stat-content">
                        <div class="stat-icon">
                            <i class="fas fa-spinner"></i>
                        </div>
                        <div>
                            <div class="stat-number"><?php echo $in_progress_goals; ?></div>
                            <div class="stat-label">In Progress</div>
                        </div>
                    </div>
                </div>
                
                <div class="stat-card points">
                    <div class="stat-content">
                        <div class="stat-icon">
                            <i class="fas fa-star"></i>
                        </div>
                        <div>
                            <div class="stat-number"><?php echo $total_points; ?></div>
                            <div class="stat-label">Total Points</div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Goals Grid -->
            <div class="goals-grid">
                <?php if (empty($goals)): ?>
                    <div class="empty-state">
                        <i class="fas fa-bullseye"></i>
                        <p>No goals yet</p>
                        <a href="create_goal.php" class="btn btn-primary"><i class="fas fa-plus"></i> Create Your First Goal</a>
                    </div>
                <?php else: ?>
                    <?php foreach ($goals as $goal): 
                        $percentage = round($goal['progress_percentage'], 1);
                        $days_left = $goal['due_date'] ? date_diff(new DateTime(), new DateTime($goal['due_date']))->days : null;
                        $is_overdue = ($goal['status'] === 'overdue');

                    ?>
                        <div class="goal-card <?php echo $goal['priority']; ?>-priority <?php echo $goal['status']; ?>">
                            <div class="goal-header">
                                <div>
                                    <div class="goal-title"><?php echo htmlspecialchars($goal['title']); ?></div>
                                    <?php if ($goal['category']): ?>
                                        <span class="goal-category"><?php echo htmlspecialchars($goal['category']); ?></span>
                                    <?php endif; ?>
                                </div>
                                <div class="goal-header-actions">
                                    <span class="status-<?php echo $goal['status']; ?>">
                                        <?php echo ucfirst(str_replace('_', ' ', $goal['status'])); ?>
                                    </span>
                                    <div class="dropdown">
                                        <button class="dropdown-toggle" type="button">
                                            <i class="fas fa-ellipsis-v"></i>
                                        </button>
                                        <div class="dropdown-menu">
                                            <a href="#" onclick="openEditGoalModal(<?php echo $goal['id']; ?>)" class="dropdown-item">
                                                <i class="fas fa-edit"></i> Edit
                                            </a>
                                            <a href="#" onclick="openDeleteModal(<?php echo $goal['id']; ?>, '<?php echo addslashes($goal['title']); ?>')" class="dropdown-item text-danger">
                                                <i class="fas fa-trash"></i> Delete
                                            </a>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div class="goal-body">
                                <?php if ($goal['description']): ?>
                                    <div class="goal-description"><?php echo nl2br(htmlspecialchars($goal['description'])); ?></div>
                                <?php endif; ?>

                                <div class="goal-meta">
                                    <div class="goal-meta-item"><i class="fas fa-flag"></i> <?php echo ucfirst($goal['priority']); ?></div>
                                    <?php if ($goal['due_date']): ?>
                                        <div class="goal-meta-item">
                                            <i class="fas fa-calendar"></i>
                                            <?php if ($is_overdue): ?>
                                                <span style="color: #dc2626;">Overdue</span>
                                            <?php elseif ($days_left == 0): ?>
                                                Due today
                                            <?php else: ?>
                                                <?php echo $days_left; ?> day<?php echo $days_left != 1 ? 's' : ''; ?> left
                                            <?php endif; ?>
                                        </div>
                                    <?php endif; ?>
                                    <?php if ($goal['unit']): ?>
                                        <div class="goal-meta-item"><i class="fas fa-ruler"></i> <?php echo htmlspecialchars($goal['unit']); ?></div>
                                    <?php endif; ?>
                                </div>

                                <div class="progress-section">
                                    <div class="progress-header">
                                        <span class="progress-label">Progress</span>
                                        <span class="progress-percentage"><?php echo $percentage; ?>%</span>
                                    </div>
                                    <div class="progress-bar">
                                        <div class="progress-fill" style="width: <?php echo $percentage; ?>%"></div>
                                    </div>
                                    <div class="progress-stats">
                                        <span><?php echo $goal['current_value']; ?> / <?php echo $goal['target_value']; ?> <?php echo $goal['unit']; ?></span>
                                    </div>
                                </div>

                                <!-- Small Progress Chart -->
                                <div class="progress-chart">
                                    <canvas id="chart_<?php echo $goal['id']; ?>"></canvas>
                                </div>
                            </div>

                            <div class="goal-footer">
                                <?php if ($goal['status'] !== 'completed'): ?>
                                    <button class="btn btn-sm btn-primary" onclick="openUpdateProgressModal(<?php echo $goal['id']; ?>, '<?php echo addslashes($goal['title']); ?>', <?php echo $goal['target_value'] - $goal['current_value']; ?>)">
                                        <i class="fas fa-arrow-up"></i> Update
                                    </button>
                                <?php endif; ?>
                                <button class="btn btn-sm btn-secondary" onclick="openEditGoalModal(<?php echo $goal['id']; ?>)">
                                    <i class="fas fa-edit"></i> Edit
                                </button>
                            </div>
                        </div>

                        <script>
                            // Individual chart for this goal
                            const ctx<?php echo $goal['id']; ?> = document.getElementById('chart_<?php echo $goal['id']; ?>').getContext('2d');
                            new Chart(ctx<?php echo $goal['id']; ?>, {
                                type: 'line',
                                data: {
                                    labels: ['Start', 'Now', 'Target'],
                                    datasets: [{
                                        data: [0, <?php echo $goal['current_value']; ?>, <?php echo $goal['target_value']; ?>],
                                        borderColor: '#4f46e5',
                                        backgroundColor: 'rgba(79, 70, 229, 0.1)',
                                        fill: true,
                                        tension: 0.4
                                    }]
                                },
                                options: {
                                    responsive: true,
                                    maintainAspectRatio: false,
                                    plugins: { legend: { display: false } },
                                    scales: { x: { display: false }, y: { display: false } }
                                }
                            });
                        </script>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </main>
    </div>

    <!-- Update Progress Modal -->
    <div class="modal-overlay" id="updateProgressModal">
        <div class="modal">
            <div class="modal-header">
                <h3>Update Progress</h3>
                <button class="modal-close" onclick="closeUpdateProgressModal()">×</button>
            </div>
            <div class="modal-body">
                <form method="POST">
                    <input type="hidden" name="update_progress" value="1">
                    <input type="hidden" name="goal_id" id="modal_goal_id">

                    <p><strong>Goal:</strong> <span id="modal_goal_title"></span></p>
                    <p><strong>Remaining:</strong> <span id="modal_remaining"></span></p>

                    <div class="form-group">
                        <label>Progress Added</label>
                        <input type="number" name="progress_value" step="0.01" min="0.01" required placeholder="e.g. 5">
                    </div>
                    <div class="form-group">
                        <label>Notes (optional)</label>
                        <textarea name="notes" rows="3" placeholder="What did you do today?"></textarea>
                    </div>
                    <div class="modal-actions">
                        <button type="button" class="btn btn-secondary" onclick="closeUpdateProgressModal()">Cancel</button>
                        <button type="submit" class="btn btn-primary">Save Progress</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Edit Goal Modal -->
    <div class="modal-overlay" id="editGoalModal">
        <div class="modal">
            <div class="modal-header">
                <h3>Edit Goal</h3>
                <button class="modal-close" onclick="closeEditGoalModal()">×</button>
            </div>
            <div class="modal-body">
                <form method="POST" id="editGoalForm">
                    <input type="hidden" name="edit_goal" value="1">
                    <input type="hidden" name="goal_id" id="edit_goal_id">

                    <div class="form-group">
                        <label>Goal Title *</label>
                        <input type="text" name="title" id="edit_title" required placeholder="e.g., Complete React Course">
                    </div>

                    <div class="form-group">
                        <label>Description</label>
                        <textarea name="description" id="edit_description" rows="3" placeholder="Describe your goal..."></textarea>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label>Category</label>
                            <select name="category" id="edit_category">
                                <option value="">Select Category</option>
                                <?php foreach ($categories as $cat): ?>
                                    <option value="<?php echo htmlspecialchars($cat); ?>"><?php echo htmlspecialchars($cat); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Priority</label>
                            <select name="priority" id="edit_priority">
                                <option value="low">Low</option>
                                <option value="medium" selected>Medium</option>
                                <option value="high">High</option>
                            </select>
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label>Target Value *</label>
                            <input type="number" name="target_value" id="edit_target_value" step="0.01" min="0.01" required placeholder="e.g., 100">
                        </div>
                        <div class="form-group">
                            <label>Unit</label>
                            <input type="text" name="unit" id="edit_unit" placeholder="e.g., pages, hours, kg">
                        </div>
                    </div>

                    <div class="form-group">
                        <label>Due Date</label>
                        <input type="date" name="due_date" id="edit_due_date">
                    </div>

                    <div class="modal-actions">
                        <button type="button" class="btn btn-secondary" onclick="closeEditGoalModal()">Cancel</button>
                        <button type="submit" class="btn btn-primary">Update Goal</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Delete Confirmation Modal -->
    <div class="modal-overlay" id="deleteModal">
        <div class="modal">
            <div class="modal-header">
                <h3>Delete Goal</h3>
                <button class="modal-close" onclick="closeDeleteModal()">×</button>
            </div>
            <div class="modal-body">
                <p>Are you sure you want to delete "<span id="delete_goal_title"></span>"?</p>
                <p class="text-danger"><i class="fas fa-exclamation-triangle"></i> This action cannot be undone. All progress and history will be deleted.</p>
                <form method="POST" id="deleteForm">
                    <input type="hidden" name="delete_goal" value="1">
                    <input type="hidden" name="goal_id" id="delete_goal_id">
                    <div class="modal-actions">
                        <button type="button" class="btn btn-secondary" onclick="closeDeleteModal()">Cancel</button>
                        <button type="submit" class="btn btn-danger">Delete Goal</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script>
        // Function to fetch goal data and populate edit form
        function openEditGoalModal(goalId) {
            // For now, we'll use the existing form. In production, you'd want to fetch via AJAX
            document.getElementById('editGoalModal').style.display = 'flex';
            document.getElementById('edit_goal_id').value = goalId;
            
            // Simple approach - in a real app, fetch data via AJAX
            // For now, we'll just show the modal and rely on the user to fill the form
        }

        function closeEditGoalModal() {
            document.getElementById('editGoalModal').style.display = 'none';
            document.getElementById('editGoalForm').reset();
        }

        // Delete modal functions
        function openDeleteModal(goalId, goalTitle) {
            document.getElementById('delete_goal_id').value = goalId;
            document.getElementById('delete_goal_title').textContent = goalTitle;
            document.getElementById('deleteModal').style.display = 'flex';
        }

        function closeDeleteModal() {
            document.getElementById('deleteModal').style.display = 'none';
        }

        // Update progress modal functions
        function openUpdateProgressModal(id, title, remaining) {
            document.getElementById('modal_goal_id').value = id;
            document.getElementById('modal_goal_title').textContent = title;
            document.getElementById('modal_remaining').textContent = remaining;
            document.getElementById('updateProgressModal').style.display = 'flex';
        }

        function closeUpdateProgressModal() {
            document.getElementById('updateProgressModal').style.display = 'none';
        }

        // Mobile sidebar
        document.getElementById('sidebarToggle')?.addEventListener('click', () => {
            document.getElementById('sidebar').classList.add('active');
        });
        
        document.getElementById('sidebarClose')?.addEventListener('click', () => {
            document.getElementById('sidebar').classList.remove('active');
        });

        // Close modals when clicking outside
        document.querySelectorAll('.modal-overlay').forEach(modal => {
            modal.addEventListener('click', function(e) {
                if (e.target === this) {
                    this.style.display = 'none';
                }
            });
        });

        // Dropdown menu functionality
        document.addEventListener('click', function(e) {
            if (!e.target.closest('.dropdown')) {
                document.querySelectorAll('.dropdown-menu').forEach(menu => {
                    menu.style.display = 'none';
                });
            }
        });

        document.querySelectorAll('.dropdown-toggle').forEach(toggle => {
            toggle.addEventListener('click', function(e) {
                e.stopPropagation();
                const menu = this.nextElementSibling;
                menu.style.display = menu.style.display === 'block' ? 'none' : 'block';
            });
        });

        // Animate progress bars on load
        document.addEventListener('DOMContentLoaded', function() {
            document.querySelectorAll('.progress-fill').forEach(fill => {
                const width = fill.style.width;
                fill.style.width = '0';
                setTimeout(() => {
                    fill.style.width = width;
                }, 300);
            });
        });
        
        // Handle window resize
        window.addEventListener('resize', function() {
            if (window.innerWidth > 992) {
                document.getElementById('sidebar').classList.remove('active');
            }
        });
    </script>
</body>
</html>