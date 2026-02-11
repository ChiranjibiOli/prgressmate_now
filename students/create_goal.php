<?php
// students/create_goal.php

session_start();
require_once '../includes/db_connection.php';
checkAuth('student');

$student_id = $_SESSION['user_id'];
$success = $_SESSION['success'] ?? '';
$error = $_SESSION['error'] ?? '';
unset($_SESSION['success'], $_SESSION['error']);

// === Fetch Sidebar Stats ===
$stmt = $pdo->prepare("SELECT COUNT(*) FROM student_goals WHERE student_id = ? AND deleted_at IS NULL");
$stmt->execute([$student_id]);
$total_goals = $stmt->fetchColumn() ?: 0;

$stmt = $pdo->prepare("SELECT COUNT(*) FROM student_goals WHERE student_id = ? AND status = 'completed' AND deleted_at IS NULL");
$stmt->execute([$student_id]);
$completed_goals = $stmt->fetchColumn() ?: 0;

$stmt = $pdo->prepare("SELECT COUNT(*) FROM student_goals WHERE student_id = ? AND status = 'in_progress' AND deleted_at IS NULL");
$stmt->execute([$student_id]);
$in_progress_goals = $stmt->fetchColumn() ?: 0;

$stmt = $pdo->prepare("SELECT points FROM users WHERE id = ?");
$stmt->execute([$student_id]);
$total_points = $stmt->fetchColumn() ?: 0;

$stmt = $pdo->prepare("SELECT current_streak FROM users WHERE id = ?");
$stmt->execute([$student_id]);
$streak = $stmt->fetchColumn() ?: 0;

$stmt = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0");
$stmt->execute([$student_id]);
$unread = $stmt->fetchColumn() ?: 0;

// === Fetch Previous Categories for Suggestions ===
$cat_stmt = $pdo->prepare("SELECT DISTINCT category FROM student_goals WHERE student_id = ? AND category IS NOT NULL AND category != '' AND deleted_at IS NULL ORDER BY category");
$cat_stmt->execute([$student_id]);
$categories = $cat_stmt->fetchAll(PDO::FETCH_COLUMN);

// Also fetch system categories
$sys_stmt = $pdo->prepare("
    SELECT name
    FROM categories
    WHERE (is_global = 1 OR created_by = ?)
      AND deleted_at IS NULL
    ORDER BY name ASC
");
$sys_stmt->execute([$student_id]);
$system_categories = $sys_stmt->fetchAll(PDO::FETCH_COLUMN);

$all_categories = array_unique(array_merge($categories, $system_categories));

// === Preserve Form Data on Error ===
$form_data = [
    'title' => $_POST['title'] ?? '',
    'description' => $_POST['description'] ?? '',
    'category' => $_POST['category'] ?? '',
    'target_value' => $_POST['target_value'] ?? '',
    'unit' => $_POST['unit'] ?? '',
    'due_date' => $_POST['due_date'] ?? '',
    'priority' => $_POST['priority'] ?? 'medium',
    'estimated_hours' => $_POST['estimated_hours'] ?? ''
];

// === Handle Form Submission ===
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $title = trim($_POST['title'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $category = trim($_POST['category'] ?? '');
    $target_value = floatval($_POST['target_value'] ?? 0);
    $unit = trim($_POST['unit'] ?? '');
    $due_date = $_POST['due_date'] ?: null;
    $allowedPriorities = ['low','medium','high','critical'];
$priority = $_POST['priority'] ?? 'medium';
if (!in_array($priority, $allowedPriorities, true)) $priority = 'medium';

    $estimated_hours = !empty($_POST['estimated_hours']) ? floatval($_POST['estimated_hours']) : null;
    $start_date = $_POST['start_date'] ?: date('Y-m-d');

    // Validation
    $errors = [];
    
    if (empty($title)) {
        $errors[] = "Goal title is required.";
    } elseif (strlen($title) < 3) {
        $errors[] = "Title must be at least 3 characters.";
    }
    
    if ($target_value <= 0) {
        $errors[] = "Target value must be greater than 0.";
    }
    
    if (empty($unit)) {
        $errors[] = "Unit is required (e.g., hours, pages, chapters).";
    }
    
    if ($due_date && $due_date < date('Y-m-d')) {
        $errors[] = "Due date cannot be in the past.";
    }
    
    if ($start_date && $due_date && $start_date > $due_date) {
        $errors[] = "Start date cannot be after due date.";
    }
    
    if ($estimated_hours !== null && $estimated_hours <= 0) {
        $errors[] = "Estimated hours must be greater than 0 if provided.";
    }

    if (empty($errors)) {
        try {
            $pdo->beginTransaction();

            // Insert into student_goals
            $stmt = $pdo->prepare("INSERT INTO student_goals 
                (student_id, title, description, category, target_value, current_value, unit, 
                 start_date, due_date, priority, status, is_self_created, estimated_hours,
                 progress_percentage, created_at, updated_at)
                VALUES (?, ?, ?, ?, ?, 0, ?, ?, ?, ?, 'pending', 1, ?, 0, NOW(), NOW())");
            
            $stmt->execute([
                $student_id, 
                $title, 
                $description, 
                $category, 
                $target_value, 
                $unit,
                $start_date,
                $due_date, 
                $priority,
                $estimated_hours
            ]);

            $new_goal_id = $pdo->lastInsertId();

            // Optional: Also insert into admin_goals for admin visibility
            // (Only if you want student-created goals to be visible to admins)
            /*
            $stmt2 = $pdo->prepare("INSERT INTO admin_goals 
                (title, description, category, target_value, unit, due_date, priority, 
                 status, created_by, created_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, 'active', ?, NOW())");
            $stmt2->execute([
                $title, $description, $category, $target_value, $unit, 
                $due_date, $priority, $student_id
            ]);
            */

            // Create a notification for the student
            $notification_msg = "New goal created: " . htmlspecialchars($title);
            $notif_stmt = $pdo->prepare("
                INSERT INTO notifications 
                (user_id, title, message, type, related_id, related_type, created_at)
                VALUES (?, 'Goal Created', ?, 'goal', ?, 'student_goal', NOW())
            ");
            $notif_stmt->execute([$student_id, $notification_msg, $new_goal_id]);

            $pdo->commit();

            $_SESSION['success'] = "Goal created successfully!";
            header("Location: goals.php");
            exit;
        } catch (Exception $e) {
            $pdo->rollBack();
            $error = "Error creating goal: " . $e->getMessage();
        }
    } else {
        $error = implode(" ", $errors);
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Create Goal - ProgressMate</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        :root {
            /* Primary Colors */
            --primary: #4f46e5;
            --primary-light: #eef2ff;
            --primary-dark: #4338ca;
            
            /* Secondary Colors */
            --secondary: #64748b;
            --secondary-light: #f8fafc;
            --secondary-dark: #475569;
            
            /* Status Colors */
            --success: #10b981;
            --success-light: #d1fae5;
            --warning: #f59e0b;
            --warning-light: #fef3c7;
            --danger: #ef4444;
            --danger-light: #fee2e2;
            --info: #3b82f6;
            --info-light: #dbeafe;
            --purple: #8b5cf6;
            
            /* Neutral Colors */
            --dark: #1e293b;
            --light: #f1f5f9;
            --white: #ffffff;
            --gray: #e2e8f0;
            --gray-light: #f9fafb;
            --gray-dark: #6b7280;
            
            /* Typography */
            --font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            --font-size-xs: 0.75rem;
            --font-size-sm: 0.875rem;
            --font-size-base: 1rem;
            --font-size-lg: 1.125rem;
            --font-size-xl: 1.25rem;
            --font-size-2xl: 1.5rem;
            --font-size-3xl: 1.875rem;
            --font-size-4xl: 2.25rem;
            
            /* Spacing */
            --spacing-xs: 0.25rem;
            --spacing-sm: 0.5rem;
            --spacing-md: 1rem;
            --spacing-lg: 1.5rem;
            --spacing-xl: 2rem;
            --spacing-2xl: 3rem;
            
            /* Borders & Shadows */
            --radius-sm: 0.375rem;
            --radius-md: 0.5rem;
            --radius-lg: 0.75rem;
            --radius-xl: 1rem;
            --radius-2xl: 1.5rem;
            --shadow-sm: 0 1px 2px 0 rgba(0, 0, 0, 0.05);
            --shadow-md: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
            --shadow-lg: 0 10px 15px -3px rgba(0, 0, 0, 0.1);
            --shadow-xl: 0 20px 25px -5px rgba(0, 0, 0, 0.1);
            
            /* Transitions */
            --transition-fast: 150ms ease;
            --transition-base: 300ms ease;
            --transition-slow: 500ms ease;
        }

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

        .user-tag {
            display: inline-block;
            background: var(--primary-light);
            color: var(--primary);
            padding: 0.25rem 0.75rem;
            border-radius: 20px;
            font-size: var(--font-size-xs);
            font-weight: 600;
        }

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
            min-height: 100vh;
            animation: fadeIn 0.7s ease-out;
        }

        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
        }

        /* Page Header */
        .page-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: var(--spacing-2xl);
            flex-wrap: wrap;
            gap: var(--spacing-md);
        }

        .header-content h1 {
            font-size: 2.5rem;
            font-weight: 800;
            color: var(--dark);
            margin-bottom: var(--spacing-xs);
            background: linear-gradient(135deg, var(--primary), var(--primary-dark));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }

        .header-content p {
            color: var(--secondary);
            font-size: var(--font-size-lg);
            max-width: 600px;
        }

        /* Back Button */
        .back-btn {
            display: inline-flex;
            align-items: center;
            gap: var(--spacing-sm);
            padding: 0.75rem 1.25rem;
            background: var(--white);
            color: var(--primary);
            border: 2px solid var(--primary);
            border-radius: var(--radius-md);
            font-weight: 600;
            transition: var(--transition);
            text-decoration: none;
        }

        .back-btn:hover {
            background: var(--primary);
            color: var(--white);
            transform: translateY(-2px);
            box-shadow: var(--shadow-md);
        }

        /* Alerts */
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
            from { opacity: 0; transform: translateX(-10px); }
            to { opacity: 1; transform: translateX(0); }
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

        /* Form Container */
        .form-container {
            max-width: 900px;
            margin: 0 auto var(--spacing-2xl);
            background: var(--white);
            border-radius: var(--radius-lg);
            padding: 3rem;
            box-shadow: var(--shadow-lg);
            position: relative;
            overflow: hidden;
        }

        .form-container::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 6px;
            background: linear-gradient(90deg, var(--primary), #8b5cf6, var(--success));
        }

        .form-section {
            margin-bottom: 3rem;
        }

        .form-section h3 {
            font-size: 1.75rem;
            font-weight: 700;
            color: var(--dark);
            margin-bottom: 2rem;
            display: flex;
            align-items: center;
            gap: 0.75rem;
            position: relative;
            padding-bottom: 0.75rem;
        }

        .form-section h3::after {
            content: '';
            position: absolute;
            bottom: 0;
            left: 0;
            width: 80px;
            height: 4px;
            background: linear-gradient(90deg, var(--primary), transparent);
            border-radius: 2px;
        }

        .form-group {
            margin-bottom: 1.75rem;
        }

        .form-group label {
            display: block;
            margin-bottom: 0.75rem;
            font-weight: 600;
            color: var(--dark);
            font-size: 1.05rem;
        }

        .form-group label.required::after {
            content: " *";
            color: var(--danger);
        }

        .form-group input,
        .form-group textarea,
        .form-group select {
            width: 100%;
            padding: 1rem 1.25rem;
            border: 2px solid var(--gray);
            border-radius: var(--radius-md);
            font-size: 1rem;
            transition: var(--transition);
            background: var(--white);
        }

        .form-group input:focus,
        .form-group textarea:focus,
        .form-group select:focus {
            border-color: var(--primary);
            box-shadow: 0 0 0 4px var(--primary-light);
            transform: translateY(-2px);
        }

        .form-group textarea {
            min-height: 140px;
            resize: vertical;
        }

        .form-row {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 2rem;
        }

        .form-help {
            font-size: 0.9rem;
            color: var(--secondary);
            margin-top: 0.5rem;
        }

        .unit-examples {
            font-size: 0.85rem;
            color: var(--gray-dark);
            margin-top: 0.5rem;
            font-style: italic;
        }

        /* Category Suggestions */
        .category-suggestions {
            display: flex;
            flex-wrap: wrap;
            gap: 0.75rem;
            margin-top: 0.75rem;
        }

        .category-tag {
            background: var(--primary-light);
            color: var(--primary);
            padding: 0.5rem 1rem;
            border-radius: 30px;
            font-size: 0.9rem;
            cursor: pointer;
            transition: var(--transition);
            font-weight: 500;
            border: 2px solid transparent;
        }

        .category-tag:hover {
            background: var(--primary);
            color: white;
            transform: translateY(-2px);
            border-color: var(--primary);
        }

        /* Priority Selector */
        .priority-options {
            display: flex;
            gap: 1rem;
            margin-top: 0.5rem;
        }

        .priority-option {
            flex: 1;
            padding: 1.25rem;
            border-radius: var(--radius-md);
            text-align: center;
            cursor: pointer;
            transition: var(--transition);
            border: 2px solid var(--gray);
            background: var(--light);
        }

        .priority-option:hover {
            transform: translateY(-5px);
            box-shadow: var(--shadow-md);
        }

        .priority-option.selected {
            border-color: var(--primary);
            background: var(--primary-light);
            box-shadow: 0 0 0 4px var(--primary-light);
        }

        .priority-option .priority-icon {
            font-size: 2rem;
            margin-bottom: 0.5rem;
        }

        .priority-option.low .priority-icon { color: #94a3b8; }
        .priority-option.medium .priority-icon { color: var(--primary); }
        .priority-option.high .priority-icon { color: var(--danger); }

        .priority-option.selected .priority-icon {
            color: inherit;
        }

        /* Form Actions */
        .form-actions {
            display: flex;
            justify-content: flex-end;
            gap: 1.5rem;
            padding-top: 2rem;
            border-top: 1px solid var(--gray);
            margin-top: 2rem;
        }

        .btn {
            padding: 1rem 2rem;
            border-radius: var(--radius-md);
            font-weight: 600;
            font-size: 1.05rem;
            transition: var(--transition);
            display: inline-flex;
            align-items: center;
            gap: 0.75rem;
            cursor: pointer;
        }

        .btn-primary {
            background: linear-gradient(135deg, var(--primary), var(--primary-dark));
            color: white;
            box-shadow: var(--shadow-md);
            border: none;
        }

        .btn-primary:hover {
            transform: translateY(-4px);
            box-shadow: var(--shadow-lg);
        }

        .btn-outline {
            background: transparent;
            color: var(--primary);
            border: 2px solid var(--primary);
        }

        .btn-outline:hover {
            background: var(--primary);
            color: white;
        }

        /* Tips Section */
        .tips-section {
            max-width: 1000px;
            margin: 4rem auto 2rem;
            background: var(--white);
            border-radius: var(--radius-lg);
            padding: 2.5rem;
            box-shadow: var(--shadow-md);
        }

        .tips-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
            gap: 1.5rem;
            margin-top: 2rem;
        }

        .tip-card {
            background: linear-gradient(135deg, #f8fafc, #f1f5f9);
            padding: 1.75rem;
            border-radius: var(--radius-lg);
            text-align: center;
            transition: var(--transition);
        }

        .tip-card:hover {
            transform: translateY(-8px);
            box-shadow: var(--shadow-lg);
        }

        .tip-card i {
            font-size: 2.5rem;
            color: var(--warning);
            margin-bottom: 1rem;
        }

        .tip-card h4 {
            font-size: 1.2rem;
            font-weight: 700;
            margin-bottom: 0.75rem;
            color: var(--dark);
        }

        .tip-card p {
            color: var(--secondary);
            font-size: 0.95rem;
            line-height: 1.6;
        }

        /* Mobile Toggle */
        .mobile-toggle {
            display: none;
            position: fixed;
            top: 20px;
            left: 20px;
            z-index: 1100;
            background: var(--primary);
            color: white;
            border: none;
            width: 48px;
            height: 48px;
            border-radius: 12px;
            font-size: 20px;
            cursor: pointer;
            box-shadow: var(--shadow);
        }

        .sidebar-overlay {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(0,0,0,0.5);
            z-index: 999;
        }

        /* Responsive */
        @media (max-width: 768px) {
            .sidebar {
                transform: translateX(-100%);
                width: 300px;
            }
            .sidebar.active { transform: translateX(0); }
            .sidebar-overlay.active { display: block; }
            .sidebar-close { display: block; }
            .mobile-toggle { display: flex; align-items: center; justify-content: center; }
            .main-content { margin-left: 0; padding: 24px 16px; padding-top: 80px; }
            .page-header { flex-direction: column; align-items: flex-start; }
            .form-container { padding: 2rem; }
            .form-row { grid-template-columns: 1fr; }
            .form-actions { flex-direction: column; }
            .btn { width: 100%; justify-content: center; }
            .priority-options { flex-direction: column; }
        }
    </style>
</head>
<body>
    <button class="mobile-toggle" id="sidebarToggle"><i class="fas fa-bars"></i></button>
    <div class="sidebar-overlay" id="sidebarOverlay"></div>

    <div class="dashboard-wrapper">
        <!-- SIDEBAR -->
        <aside class="sidebar" id="sidebar">
            <div class="sidebar-header">
                <div class="logo"><i class="fas fa-star"></i> ProgressMate</div>
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
                    <span class="user-tag">STUDENT</span>
                </div>
            </div>

            <nav class="nav-menu">
                <a href="dashboard.php" class="nav-link"><i class="fas fa-tachometer-alt"></i> Dashboard</a>
                <a href="goals.php" class="nav-link"><i class="fas fa-bullseye"></i> My Goals <?php if ($total_goals > 0): ?><span class="badge"><?php echo $total_goals; ?></span><?php endif; ?></a>
                <a href="create_goal.php" class="nav-link active"><i class="fas fa-plus-circle"></i> Create Goal</a>
                <a href="achievements.php" class="nav-link"><i class="fas fa-trophy"></i> Achievements <?php if ($total_points > 0): ?><span class="badge"><?php echo $total_points; ?> pts</span><?php endif; ?></a>
                <a href="notifications.php" class="nav-link"><i class="fas fa-inbox"></i> Notifications <?php if ($unread > 0): ?><span class="badge"><?php echo $unread; ?></span><?php endif; ?></a>
                <a href="profile.php" class="nav-link"><i class="fas fa-user"></i> Profile</a>
            </nav>

            <div class="sidebar-quick-stats">
                <div class="sidebar-stat">
                    <div class="sidebar-stat-icon"><i class="fas fa-bullseye"></i></div>
                    <div><div class="sidebar-stat-label">Goals</div><div class="sidebar-stat-number"><?php echo $completed_goals; ?>/<?php echo $total_goals; ?></div></div>
                </div>
                <div class="sidebar-stat">
                    <div class="sidebar-stat-icon"><i class="fas fa-star"></i></div>
                    <div><div class="sidebar-stat-label">Points</div><div class="sidebar-stat-number"><?php echo $total_points; ?></div></div>
                </div>
                <div class="sidebar-stat">
                    <div class="sidebar-stat-icon"><i class="fas fa-fire"></i></div>
                    <div><div class="sidebar-stat-label">Streak</div><div class="sidebar-stat-number"><?php echo $streak; ?> days</div></div>
                </div>
            </div>

            <div class="sidebar-footer">
                <a href="../logout.php" class="logout-btn"><i class="fas fa-sign-out-alt"></i> Logout</a>
            </div>
        </aside>

        <!-- MAIN CONTENT -->
        <main class="main-content">
            <header class="page-header">
                <div class="header-content">
                    <h1>Create New Goal</h1>
                    <p>Set a new goal and start tracking your progress</p>
                </div>
                <a href="goals.php" class="back-btn">
                    <i class="fas fa-arrow-left"></i> Back to Goals
                </a>
            </header>

            <?php if ($success): ?>
                <div class="alert alert-success">
                    <i class="fas fa-check-circle"></i>
                    <span><?php echo htmlspecialchars($success); ?></span>
                </div>
            <?php endif; ?>
            
            <?php if ($error): ?>
                <div class="alert alert-error">
                    <i class="fas fa-exclamation-circle"></i>
                    <span><?php echo htmlspecialchars($error); ?></span>
                </div>
            <?php endif; ?>

            <!-- Goal Creation Form -->
            <div class="form-container">
                <form method="POST" id="createGoalForm">
                    <!-- Basic Information Section -->
                    <div class="form-section">
                        <h3><i class="fas fa-info-circle"></i> Basic Information</h3>
                        
                        <div class="form-group">
                            <label for="title" class="required">Goal Title</label>
                            <input type="text" id="title" name="title" 
                                   placeholder="What do you want to achieve?" 
                                   value="<?php echo htmlspecialchars($form_data['title']); ?>" 
                                   required maxlength="200">
                            <div class="form-help">Be specific about what you want to accomplish</div>
                        </div>
                        
                        <div class="form-group">
                            <label for="description">Description</label>
                            <textarea id="description" name="description" 
                                      placeholder="Describe your goal in detail... What steps will you take? Why is this important to you?"><?php echo htmlspecialchars($form_data['description']); ?></textarea>
                            <div class="form-help">Optional: Add details, reasons, or steps to achieve this goal</div>
                        </div>
                        
                        <div class="form-group">
                            <label for="category">Category</label>
                            <input type="text" id="category" name="category" 
                                   placeholder="e.g., Health, Education, Career, Personal, Fitness"
                                   value="<?php echo htmlspecialchars($form_data['category']); ?>"
                                   list="categorySuggestions">
                            <datalist id="categorySuggestions">
                                <?php foreach ($all_categories as $cat): ?>
                                    <option value="<?php echo htmlspecialchars($cat); ?>">
                                <?php endforeach; ?>
                            </datalist>
                            <?php if (!empty($all_categories)): ?>
                                <div class="form-help">Suggestions: Click to select</div>
                                <div class="category-suggestions">
                                    <?php foreach (array_slice($all_categories, 0, 8) as $cat): ?>
                                        <span class="category-tag" onclick="document.getElementById('category').value = '<?php echo htmlspecialchars($cat); ?>'">
                                            <?php echo htmlspecialchars($cat); ?>
                                        </span>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                    
                    <!-- Goal Details Section -->
                    <div class="form-section">
                        <h3><i class="fas fa-cog"></i> Goal Details</h3>
                        
                        <div class="form-row">
                            <div class="form-group">
                                <label for="target_value" class="required">Target Value</label>
                                <input type="number" id="target_value" name="target_value" 
                                       min="0.01" step="0.01" 
                                       placeholder="e.g., 100, 5.5, 30"
                                       value="<?php echo htmlspecialchars($form_data['target_value']); ?>"
                                       required>
                                <div class="form-help">The numerical target you want to achieve</div>
                            </div>
                            
                            <div class="form-group">
                                <label for="unit" class="required">Unit</label>
                                <input type="text" id="unit" name="unit" 
                                       placeholder="e.g., pages, kilometers, hours, kg, chapters"
                                       value="<?php echo htmlspecialchars($form_data['unit']); ?>"
                                       required>
                                <div class="unit-examples">Examples: pages (books), km (distance), hours (study), kg (weight), chapters (learning)</div>
                            </div>
                        </div>
                        
                        <div class="form-row">
                            <div class="form-group">
                                <label for="start_date">Start Date</label>
                                <input type="date" id="start_date" name="start_date" 
                                       value="<?php echo htmlspecialchars($form_data['start_date'] ?? date('Y-m-d')); ?>">
                                <div class="form-help">When you plan to start working on this goal</div>
                            </div>
                            
                            <div class="form-group">
                                <label for="due_date">Due Date</label>
                                <input type="date" id="due_date" name="due_date" 
                                       value="<?php echo htmlspecialchars($form_data['due_date']); ?>">
                                <div class="form-help">Optional: Set a deadline for your goal</div>
                            </div>
                        </div>
                        
                        <div class="form-row">
                            <div class="form-group">
                                <label for="estimated_hours">Estimated Hours</label>
                                <input type="number" id="estimated_hours" name="estimated_hours" 
                                       min="0.5" step="0.5"
                                       placeholder="e.g., 10.5, 20, 5"
                                       value="<?php echo htmlspecialchars($form_data['estimated_hours']); ?>">
                                <div class="form-help">Optional: Estimated time needed to complete</div>
                            </div>
                            
                            <div class="form-group">
                                <label for="priority">Priority</label>
                                <input type="hidden" id="priority" name="priority" value="<?php echo htmlspecialchars($form_data['priority']); ?>">
                                <div class="priority-options">
                                    <div class="priority-option low <?php echo $form_data['priority'] == 'low' ? 'selected' : ''; ?>" 
                                         data-value="low">
                                        <div class="priority-icon">
                                            <i class="fas fa-arrow-down"></i>
                                        </div>
                                        <div>Low Priority</div>
                                    </div>
                                    
                                    <div class="priority-option medium <?php echo $form_data['priority'] == 'medium' ? 'selected' : ''; ?>" 
                                         data-value="medium">
                                        <div class="priority-icon">
                                            <i class="fas fa-equals"></i>
                                        </div>
                                        <div>Medium Priority</div>
                                    </div>
                                    
                                    <div class="priority-option high <?php echo $form_data['priority'] == 'high' ? 'selected' : ''; ?>" 
                                         data-value="high">
                                        <div class="priority-icon">
                                            <i class="fas fa-arrow-up"></i>
                                        </div>
                                        <div>High Priority</div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Form Actions -->
                    <div class="form-actions">
                        <button type="reset" class="btn btn-outline">
                            <i class="fas fa-redo"></i> Clear Form
                        </button>
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-plus"></i> Create Goal
                        </button>
                    </div>
                </form>
            </div>
            
            <!-- Tips Section -->
            <div class="tips-section">
                <h3 style="margin-bottom: 1.5rem; color: var(--dark); display: flex; align-items: center; gap: 10px;">
                    <i class="fas fa-lightbulb" style="color: var(--warning);"></i>
                    Tips for Setting Effective Goals
                </h3>
                <div class="tips-grid">
                    <div class="tip-card">
                        <i class="fas fa-bullseye"></i>
                        <h4>Be Specific</h4>
                        <p>Clearly define what you want to achieve. Instead of "Exercise more," try "Exercise 30 minutes daily."</p>
                    </div>
                    <div class="tip-card">
                        <i class="fas fa-ruler"></i>
                        <h4>Make it Measurable</h4>
                        <p>Use numbers to track progress. "Read 10 books" is better than "Read more books."</p>
                    </div>
                    <div class="tip-card">
                        <i class="fas fa-calendar-check"></i>
                        <h4>Set a Deadline</h4>
                        <p>Deadlines create urgency and help you stay focused on completion.</p>
                    </div>
                    <div class="tip-card">
                        <i class="fas fa-chart-line"></i>
                        <h4>Track Regularly</h4>
                        <p>Update your progress frequently to stay motivated and make adjustments.</p>
                    </div>
                </div>
            </div>
        </main>
    </div>

    <script>
        // Mobile sidebar
        const sidebar = document.getElementById('sidebar');
        const sidebarToggle = document.getElementById('sidebarToggle');
        const sidebarClose = document.getElementById('sidebarClose');
        const overlay = document.getElementById('sidebarOverlay');

        function openSidebar() { 
            sidebar.classList.add('active'); 
            overlay.classList.add('active'); 
        }
        function closeSidebar() { 
            sidebar.classList.remove('active'); 
            overlay.classList.remove('active'); 
        }

        sidebarToggle?.addEventListener('click', openSidebar);
        sidebarClose?.addEventListener('click', closeSidebar);
        overlay?.addEventListener('click', closeSidebar);

        // Close sidebar when clicking outside on mobile
        document.addEventListener('click', function(event) {
            if (window.innerWidth <= 768 && 
                sidebar && sidebar.classList.contains('active') &&
                !sidebar.contains(event.target) && 
                !sidebarToggle.contains(event.target)) {
                closeSidebar();
            }
        });

        // Handle window resize
        window.addEventListener('resize', function() {
            if (window.innerWidth > 768 && sidebar && sidebar.classList.contains('active')) {
                closeSidebar();
            }
        });

        // Priority selection
        document.querySelectorAll('.priority-option').forEach(option => {
            option.addEventListener('click', function() {
                // Remove selected class from all options
                document.querySelectorAll('.priority-option').forEach(opt => {
                    opt.classList.remove('selected');
                });
                
                // Add selected class to clicked option
                this.classList.add('selected');
                
                // Update hidden input value
                document.getElementById('priority').value = this.dataset.value;
            });
        });

        // Form validation
        document.getElementById('createGoalForm').addEventListener('submit', function(e) {
            const title = document.getElementById('title').value.trim();
            const targetValue = document.getElementById('target_value').value;
            const unit = document.getElementById('unit').value.trim();
            const startDate = document.getElementById('start_date').value;
            const dueDate = document.getElementById('due_date').value;
            const estimatedHours = document.getElementById('estimated_hours').value;
            
            // Clear previous errors
            document.querySelectorAll('.error-message').forEach(el => el.remove());
            
            let hasError = false;
            
            if (!title) {
                showError('title', 'Please enter a goal title.');
                hasError = true;
            } else if (title.length < 3) {
                showError('title', 'Title must be at least 3 characters.');
                hasError = true;
            }
            
            if (!targetValue || parseFloat(targetValue) <= 0) {
                showError('target_value', 'Target value must be greater than 0.');
                hasError = true;
            }
            
            if (!unit) {
                showError('unit', 'Please specify the unit (e.g., pages, kilometers, hours).');
                hasError = true;
            }
            
            if (dueDate) {
                const today = new Date().toISOString().split('T')[0];
                if (dueDate < today) {
                    showError('due_date', 'Due date cannot be in the past.');
                    hasError = true;
                }
            }
            
            if (startDate && dueDate && startDate > dueDate) {
                showError('start_date', 'Start date cannot be after due date.');
                hasError = true;
            }
            
            if (estimatedHours && parseFloat(estimatedHours) <= 0) {
                showError('estimated_hours', 'Estimated hours must be greater than 0.');
                hasError = true;
            }
            
            if (hasError) {
                e.preventDefault();
                return false;
            }
            
            return true;
        });

        function showError(fieldId, message) {
            const field = document.getElementById(fieldId);
            const error = document.createElement('div');
            error.className = 'error-message';
            error.style.color = 'var(--danger)';
            error.style.fontSize = '0.85rem';
            error.style.marginTop = '0.5rem';
            error.innerHTML = `<i class="fas fa-exclamation-circle"></i> ${message}`;
            
            if (field.parentNode.querySelector('.error-message')) {
                field.parentNode.querySelector('.error-message').remove();
            }
            
            field.parentNode.appendChild(error);
            field.style.borderColor = 'var(--danger)';
            
            // Auto-remove error when user starts typing
            field.addEventListener('input', function() {
                if (this.parentNode.querySelector('.error-message')) {
                    this.parentNode.querySelector('.error-message').remove();
                    this.style.borderColor = '';
                }
            }, { once: true });
        }

        // Auto-focus on title input
        document.getElementById('title').focus();
        
        // Set minimum dates
        const today = new Date().toISOString().split('T')[0];
        document.getElementById('start_date').min = today;
        document.getElementById('due_date').min = today;
        
        // Set default start date to today
        if (!document.getElementById('start_date').value) {
            document.getElementById('start_date').value = today;
        }

        // Character counter for description
        const descriptionTextarea = document.getElementById('description');
        if (descriptionTextarea) {
            const charCount = document.createElement('div');
            charCount.className = 'form-help';
            charCount.style.textAlign = 'right';
            charCount.style.marginTop = '5px';
            descriptionTextarea.parentNode.appendChild(charCount);
            
            function updateCharCount() {
                const length = descriptionTextarea.value.length;
                charCount.textContent = `${length}/1000 characters`;
                
                if (length > 1000) {
                    charCount.style.color = 'var(--danger)';
                } else if (length > 800) {
                    charCount.style.color = 'var(--warning)';
                } else {
                    charCount.style.color = 'var(--gray-dark)';
                }
            }
            
            descriptionTextarea.addEventListener('input', updateCharCount);
            updateCharCount();
        }

        // Clear form confirmation
        document.querySelector('button[type="reset"]').addEventListener('click', function(e) {
            if (!confirm('Are you sure you want to clear all form fields?')) {
                e.preventDefault();
            } else {
                // Reset priority selection
                document.querySelectorAll('.priority-option').forEach(opt => {
                    opt.classList.remove('selected');
                });
                document.querySelector('.priority-option.medium').classList.add('selected');
                document.getElementById('priority').value = 'medium';
                
                // Reset dates to today
                document.getElementById('start_date').value = today;
                document.getElementById('due_date').value = '';
            }
        });

        // Auto-suggest categories when typing
        const categoryInput = document.getElementById('category');
        if (categoryInput) {
            categoryInput.addEventListener('input', function() {
                const value = this.value.toLowerCase();
                const suggestions = document.querySelectorAll('.category-tag');
                suggestions.forEach(tag => {
                    if (tag.textContent.toLowerCase().includes(value) || value === '') {
                        tag.style.display = 'inline-block';
                    } else {
                        tag.style.display = 'none';
                    }
                });
            });
        }
    </script>
</body>
</html>