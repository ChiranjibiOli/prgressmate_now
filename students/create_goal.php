<?php
session_start();
require_once '../includes/db_connection.php';
checkAuth('student');

$student_id = $_SESSION['user_id'];
$success = $_SESSION['success'] ?? '';
$error = $_SESSION['error'] ?? '';
unset($_SESSION['success'], $_SESSION['error']);

// === Fetch Sidebar Stats ===
$stmt = $pdo->prepare("SELECT COUNT(*) FROM student_goals WHERE student_id = ?");
$stmt->execute([$student_id]);
$total_goals = $stmt->fetchColumn() ?: 0;

$stmt = $pdo->prepare("SELECT COUNT(*) FROM student_goals WHERE student_id = ? AND status = 'completed'");
$stmt->execute([$student_id]);
$completed_goals = $stmt->fetchColumn() ?: 0;

$stmt = $pdo->prepare("SELECT COUNT(*) FROM student_goals WHERE student_id = ? AND status = 'in_progress'");
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
$cat_stmt = $pdo->prepare("SELECT DISTINCT category FROM student_goals WHERE student_id = ? AND category IS NOT NULL AND category != '' ORDER BY category");
$cat_stmt->execute([$student_id]);
$categories = $cat_stmt->fetchAll(PDO::FETCH_COLUMN);

// === Preserve Form Data on Error ===
$form_data = [
    'title' => $_POST['title'] ?? '',
    'description' => $_POST['description'] ?? '',
    'category' => $_POST['category'] ?? '',
    'target_value' => $_POST['target_value'] ?? '',
    'unit' => $_POST['unit'] ?? '',
    'due_date' => $_POST['due_date'] ?? '',
    'priority' => $_POST['priority'] ?? 'medium'
];

// === Handle Form Submission ===
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $title = trim($_POST['title'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $category = trim($_POST['category'] ?? '');
    $target_value = floatval($_POST['target_value'] ?? 0);
    $unit = trim($_POST['unit'] ?? '');
    $due_date = $_POST['due_date'] ?: null;
    $priority = in_array($_POST['priority'] ?? 'medium', ['low','medium','high']) ? $_POST['priority'] : 'medium';

    if (!$title || $target_value <= 0 || !$unit) {
        $error = "Title, target value (greater than 0), and unit are required.";
    } elseif ($due_date && $due_date < date('Y-m-d')) {
        $error = "Due date cannot be in the past.";
    } else {
        try {
            $pdo->beginTransaction();

            // Insert into student_goals
            $stmt = $pdo->prepare("INSERT INTO student_goals 
                (student_id, title, description, category, target_value, current_value, unit, due_date, priority, status, is_self_created, created_at, updated_at)
                VALUES (?, ?, ?, ?, ?, 0, ?, ?, ?, 'pending', 1, NOW(), NOW())");
            $stmt->execute([$student_id, $title, $description, $category, $target_value, $unit, $due_date, $priority]);

            // Optional: Sync to admin_goals for admin visibility
            $stmt2 = $pdo->prepare("INSERT INTO admin_goals 
                (title, description, category, target_value, unit, due_date, priority, status, created_by, created_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, 'active', ?, NOW())");
            $stmt2->execute([$title, $description, $category, $target_value, $unit, $due_date, $priority, $student_id]);

            $pdo->commit();

            $_SESSION['success'] = "Goal created successfully!";
            header("Location: goals.php");
            exit;
        } catch (Exception $e) {
            $pdo->rollBack();
            $error = "Error creating goal. Please try again.";
        }
    }
}
?>



<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Create Goal - ProgressMate</title>
    <link rel="stylesheet" href="../assets/css/style.css">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
   <style>
/* ===== CSS VARIABLES & THEME ===== */
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

/* ===== UTILITY CLASSES ===== */
.container {
    width: 100%;
    max-width: 1200px;
    margin: 0 auto;
    padding: 0 var(--spacing-md);
}

.flex {
    display: flex;
}

.flex-col {
    flex-direction: column;
}

.items-center {
    align-items: center;
}

.justify-between {
    justify-content: space-between;
}

.gap-sm { gap: var(--spacing-sm); }
.gap-md { gap: var(--spacing-md); }
.gap-lg { gap: var(--spacing-lg); }

.mt-sm { margin-top: var(--spacing-sm); }
.mt-md { margin-top: var(--spacing-md); }
.mt-lg { margin-top: var(--spacing-lg); }
.mb-sm { margin-bottom: var(--spacing-sm); }
.mb-md { margin-bottom: var(--spacing-md); }
.mb-lg { margin-bottom: var(--spacing-lg); }

.text-center { text-align: center; }
.text-right { text-align: right; }
.text-primary { color: var(--primary); }
.text-success { color: var(--success); }
.text-danger { color: var(--danger); }
.text-warning { color: var(--warning); }
.text-muted { color: var(--gray-dark); }

.bg-white { background: var(--white); }
.bg-light { background: var(--light); }
.bg-primary { background: var(--primary); }
.bg-success { background: var(--success); }
.bg-danger { background: var(--danger); }

.rounded-sm { border-radius: var(--radius-sm); }
.rounded-md { border-radius: var(--radius-md); }
.rounded-lg { border-radius: var(--radius-lg); }

.shadow-sm { box-shadow: var(--shadow-sm); }
.shadow-md { box-shadow: var(--shadow-md); }
.shadow-lg { box-shadow: var(--shadow-lg); }

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

.btn-outline {
    background: transparent;
    color: var(--primary);
    border-color: var(--primary);
}

.btn-outline:hover {
    background: var(--primary);
    color: var(--white);
}

.btn-sm {
    padding: 0.375rem 0.75rem;
    font-size: var(--font-size-xs);
}

.btn-lg {
    padding: 0.75rem 1.5rem;
    font-size: var(--font-size-base);
}

/* ===== DASHBOARD LAYOUT - FIXED SIDEBAR WITH SCROLLABLE MAIN ===== */
.dashboard-wrapper {
    display: flex;
    min-height: 100vh;
    position: relative;
}

/* ===== SIDEBAR - FIXED, NON-SCROLLABLE ===== */
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
    overflow: hidden; /* Changed from auto to hidden */
    box-shadow: 4px 0 20px rgba(0, 0, 0, 0.08);
}

/* Remove scrollbar styles since sidebar is non-scrollable */
.sidebar::-webkit-scrollbar {
    display: none;
}

.sidebar-header {
    padding: var(--spacing-lg);
    border-bottom: 1px solid var(--gray);
    display: flex;
    align-items: center;
    justify-content: space-between;
    flex-shrink: 0; /* Prevent shrinking */
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
    flex-shrink: 0; /* Prevent shrinking */
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

/* Nav Menu - Now scrollable within fixed sidebar */
.nav-menu {
    flex: 1;
    padding: var(--spacing-md) 0;
    overflow-y: auto; /* Only nav menu scrolls */
    min-height: 0; /* Important for flex scrolling */
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
    flex-shrink: 0; /* Prevent shrinking */
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
    flex-shrink: 0; /* Prevent shrinking */
}

.logout-btn:hover {
    background: linear-gradient(135deg, #fecaca, #fca5a5);
    transform: translateY(-2px);
}
.main-content {
            flex: 1;
            margin-left: 280px;
            padding: 2.5rem 2rem;
            min-height: 100vh;
            animation: fadeIn 0.7s ease-out;
        }

        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .page-header {
            margin-bottom: 3rem;
            text-align: center;
        }

        .page-header h1 {
            font-size: 3rem;
            font-weight: 800;
            color: var(--dark);
            margin-bottom: 0.75rem;
            background: linear-gradient(135deg, var(--primary), var(--primary-dark));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }

        .page-header p {
            font-size: 1.25rem;
            color: var(--secondary);
            max-width: 600px;
            margin: 0 auto;
        }

        .back-btn {
            position: absolute;
            top: 2.5rem;
            left: 2rem;
            background: rgba(255,255,255,0.9);
            backdrop-filter: blur(10px);
            padding: 0.75rem 1.25rem;
            border-radius: var(--radius-md);
            box-shadow: var(--shadow-md);
            transition: var(--transition);
        }

        .back-btn:hover {
            transform: translateY(-3px);
            box-shadow: var(--shadow-lg);
        }

        /* Alerts */
        .alert {
            padding: 1.25rem 1.75rem;
            border-radius: var(--radius-md);
            margin-bottom: 2rem;
            display: flex;
            align-items: center;
            gap: 1rem;
            font-weight: 600;
            box-shadow: var(--shadow-md);
            animation: slideDown 0.5s ease;
        }

        @keyframes slideDown {
            from { opacity: 0; transform: translateY(-20px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .alert-success { background: var(--success-light); color: #065f46; border-left: 6px solid var(--success); }
        .alert-error { background: var(--danger-light); color: #991b1b; border-left: 6px solid var(--danger); }

        /* Form Container - Modern Card */
        .form-container {
            max-width: 900px;
            margin: 0 auto 3rem;
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
            top: 0; left: 0; right: 0;
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
        .form-group textarea:focus {
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

        .unit-examples, .form-help {
            font-size: 0.9rem;
            color: var(--secondary);
            margin-top: 0.5rem;
        }

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
        }

        .category-tag:hover {
            background: var(--primary);
            color: white;
            transform: translateY(-2px);
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
            color: var(--secondary);
        }

        .priority-option.selected .priority-icon {
            color: var(--primary);
        }

        .priority-option.low .priority-icon { color: #94a3b8; }
        .priority-option.high .priority-icon { color: var(--danger); }

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
        }

        .btn-primary {
            background: linear-gradient(135deg, var(--primary), var(--primary-dark));
            color: white;
            box-shadow: var(--shadow-md);
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

        /* Responsive */
        @media (max-width: 992px) {
            .main-content { margin-left: 0; padding-top: 100px; }
            .back-btn { position: static; margin-bottom: 2rem; display: inline-flex; }
            .page-header { text-align: left; }
        }

        @media (max-width: 768px) {
            .form-container { padding: 2rem; }
            .form-row { grid-template-columns: 1fr; }
            .form-actions { flex-direction: column; }
            .btn { width: 100%; justify-content: center; }
            .page-header h1 { font-size: 2.5rem; }
        }
    </style>
</head>
<body>
    <!-- ===== MOBILE MENU TOGGLE ===== -->
    <button class="mobile-toggle" id="sidebarToggle">
        <i class="fas fa-bars"></i>
    </button>
    
    <!-- ===== DASHBOARD WRAPPER ===== -->
    <div class="dashboard-wrapper">
        
        <!-- ===== SIDEBAR ===== -->
        <aside class="sidebar" id="sidebar">
            <!-- Sidebar Header -->
            <div class="sidebar-header">
                <div class="logo">
                    <i class="fas fa-star"></i>
                    <span>ProgressMate</span>
                </div>
                <button class="sidebar-close" id="sidebarClose">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <!-- User Profile -->
            <div class="user-profile">
                <?php if (!empty($_SESSION['profile_picture'])): ?>
                    <img src="<?php echo '../' . htmlspecialchars($_SESSION['profile_picture']); ?>" alt="Profile" class="profile-pic">
                <?php else: ?>
                    <div class="profile-pic default">
                        <?php echo strtoupper(substr($_SESSION['name'], 0, 1)); ?>
                    </div>
                <?php endif; ?>
                <div class="user-info">
                    <h4><?php echo htmlspecialchars($_SESSION['name']); ?></h4>
                    <p><?php echo htmlspecialchars($_SESSION['email']); ?></p>
                    <span style="font-size: 11px; background: #e0e7ff; color: #4f46e5; padding: 2px 8px; border-radius: 12px;">
                        STUDENT
                    </span>
                </div>
            </div>
            <!-- Navigation Menu -->
            <nav class="nav-menu">
                <a href="dashboard.php" class="nav-link<?php echo basename($_SERVER['PHP_SELF']) == 'dashboard.php' ? ' active' : ''; ?>">
                    <i class="fas fa-tachometer-alt"></i>
                    <span>Dashboard</span>
                </a>
                <a href="goals.php" class="nav-link">
                    <i class="fas fa-bullseye"></i>
                    <span>My Goals</span>
                    <?php if ($total_goals > 0): ?>
                        <span class="badge"><?php echo $total_goals; ?></span>
                    <?php endif; ?>
                </a>
                <a href="create_goal.php" class="nav-link active">
                    <i class="fas fa-plus-circle"></i>
                    <span>Create Goal</span>
                </a>
                <a href="achievements.php" class="nav-link">
                    <i class="fas fa-trophy"></i>
                    <span>Achievements</span>
                    <?php if ($total_points > 0): ?>
                        <span class="badge"><?php echo $total_points; ?> pts</span>
                    <?php endif; ?>
                </a>
                <a href="notifications.php" class="nav-link">
                    <i class="fas fa-inbox"></i>
                    <span>Notifications</span>
                    <?php if ($unread > 0): ?>
                        <span class="badge"><?php echo $unread; ?></span>
                    <?php endif; ?>
                </a>
                <a href="profile.php" class="nav-link">
                    <i class="fas fa-user"></i>
                    <span>Profile</span>
                </a>
            </nav>
            <!-- Quick Stats -->
            <div class="sidebar-quick-stats">
                <div class="sidebar-stat">
                    <div class="sidebar-stat-icon">
                        <i class="fas fa-bullseye"></i>
                    </div>
                    <div class="sidebar-stat-info">
                        <div class="sidebar-stat-label">Goals</div>
                        <div class="sidebar-stat-number"><?php echo $completed_goals; ?>/<?php echo $total_goals; ?></div>
                    </div>
                </div>
                <div class="sidebar-stat">
                    <div class="sidebar-stat-icon">
                        <i class="fas fa-star"></i>
                    </div>
                    <div class="sidebar-stat-info">
                        <div class="sidebar-stat-label">Points</div>
                        <div class="sidebar-stat-number"><?php echo $total_points; ?></div>
                    </div>
                </div>
                <div class="sidebar-stat">
                    <div class="sidebar-stat-icon">
                        <i class="fas fa-fire"></i>
                    </div>
                    <div class="sidebar-stat-info">
                        <div class="sidebar-stat-label">Streak</div>
                        <div class="sidebar-stat-number"><?php echo $streak; ?> days</div>
                    </div>
                </div>
            </div>
            <!-- Logout -->
            <div class="sidebar-footer">
                <a href="../logout.php" class="logout-btn">
                    <i class="fas fa-sign-out-alt"></i>
                    <span>Logout</span>
                </a>
            </div>
        </aside>
        
        <!-- ===== MAIN CONTENT ===== -->
        <main class="main-content">
            <!-- Page Header -->
            <header class="page-header">
                <div class="header-content">
                    <h1>Create New Goal</h1>
                    <p>Set a new goal and start tracking your progress</p>
                </div>
                <a href="goals.php" class="btn btn-outline">
                    <i class="fas fa-arrow-left"></i> Back to Goals
                </a>
            </header>
            
            <!-- Alerts -->
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
                            <div class="input-with-icon">
                                <i class="fas fa-bullseye input-icon"></i>
                                <input type="text" id="title" name="title" 
                                       placeholder="What do you want to achieve?" 
                                       value="<?php echo htmlspecialchars($form_data['title']); ?>" 
                                       required>
                            </div>
                            <div class="form-help">Be specific about what you want to accomplish</div>
                        </div>
                        
                        <div class="form-group">
                            <label for="description">Description</label>
                            <textarea id="description" name="description" 
                                      placeholder="Describe your goal in detail..."><?php echo htmlspecialchars($form_data['description']); ?></textarea>
                            <div class="form-help">Optional: Add details, reasons, or steps to achieve this goal</div>
                        </div>
                        
                        <div class="form-group">
                            <label for="category">Category</label>
                            <input type="text" id="category" name="category" 
                                   placeholder="e.g., Health, Education, Career, Personal"
                                   value="<?php echo htmlspecialchars($form_data['category']); ?>">
                            <?php if (!empty($categories)): ?>
                                <div class="form-help">Your previous categories:</div>
                                <div class="category-suggestions">
                                    <?php foreach ($categories as $cat): ?>
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
                                       placeholder="e.g., 100"
                                       value="<?php echo htmlspecialchars($form_data['target_value']); ?>"
                                       required>
                            </div>
                            
                            <div class="form-group">
                                <label for="unit" class="required">Unit</label>
                                <input type="text" id="unit" name="unit" 
                                       placeholder="e.g., pages, kilometers, hours"
                                       value="<?php echo htmlspecialchars($form_data['unit']); ?>"
                                       required>
                                <div class="unit-examples">Examples: pages (for books), kg (for weight), hours (for study)</div>
                            </div>
                        </div>
                        
                        <div class="form-row">
                            <div class="form-group">
                                <label for="due_date">Due Date</label>
                                <input type="date" id="due_date" name="due_date" 
                                       value="<?php echo htmlspecialchars($form_data['due_date']); ?>">
                                <div class="form-help">Optional: Set a deadline for your goal</div>
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
            <div style="background: white; border-radius: 12px; padding: 20px; margin-top: 30px; box-shadow: 0 2px 10px rgba(0,0,0,0.05);">
                <h3 style="margin-bottom: 15px; color: #111827; display: flex; align-items: center; gap: 10px;">
                    <i class="fas fa-lightbulb" style="color: #f59e0b;"></i>
                    Tips for Setting Effective Goals
                </h3>
                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 15px;">
                    <div style="background: #f9fafb; padding: 15px; border-radius: 8px;">
                        <div style="font-weight: 500; color: #111827; margin-bottom: 5px;">Be Specific</div>
                        <div style="font-size: 13px; color: #6b7280;">Clearly define what you want to achieve</div>
                    </div>
                    <div style="background: #f9fafb; padding: 15px; border-radius: 8px;">
                        <div style="font-weight: 500; color: #111827; margin-bottom: 5px;">Make it Measurable</div>
                        <div style="font-size: 13px; color: #6b7280;">Use numbers to track your progress</div>
                    </div>
                    <div style="background: #f9fafb; padding: 15px; border-radius: 8px;">
                        <div style="font-weight: 500; color: #111827; margin-bottom: 5px;">Set a Deadline</div>
                        <div style="font-size: 13px; color: #6b7280;">Deadlines create urgency and focus</div>
                    </div>
                    <div style="background: #f9fafb; padding: 15px; border-radius: 8px;">
                        <div style="font-weight: 500; color: #111827; margin-bottom: 5px;">Track Regularly</div>
                        <div style="font-size: 13px; color: #6b7280;">Update your progress frequently</div>
                    </div>
                </div>
            </div>
        </main>
    </div>
    
    <!-- ===== JAVASCRIPT ===== -->
    <script>
        // Mobile sidebar toggle
        const sidebarToggle = document.getElementById('sidebarToggle');
        const sidebar = document.getElementById('sidebar');
        const sidebarClose = document.getElementById('sidebarClose');
        
        if (sidebarToggle) {
            sidebarToggle.addEventListener('click', function() {
                sidebar.classList.add('active');
            });
        }
        
        if (sidebarClose) {
            sidebarClose.addEventListener('click', function() {
                sidebar.classList.remove('active');
            });
        }
        
        // Close sidebar when clicking outside on mobile
        document.addEventListener('click', function(event) {
            if (window.innerWidth <= 768 && 
                sidebar && sidebar.classList.contains('active') &&
                !sidebar.contains(event.target) && 
                !sidebarToggle.contains(event.target)) {
                sidebar.classList.remove('active');
            }
        });
        
        // Handle window resize
        window.addEventListener('resize', function() {
            if (window.innerWidth > 768 && sidebar && sidebar.classList.contains('active')) {
                sidebar.classList.remove('active');
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
            
            if (!title) {
                e.preventDefault();
                alert('Please enter a goal title.');
                document.getElementById('title').focus();
                return false;
            }
            
            if (!targetValue || parseFloat(targetValue) <= 0) {
                e.preventDefault();
                alert('Target value must be greater than 0.');
                document.getElementById('target_value').focus();
                return false;
            }
            
            if (!unit) {
                e.preventDefault();
                alert('Please specify the unit (e.g., pages, kilometers, hours).');
                document.getElementById('unit').focus();
                return false;
            }
            
            // Set minimum due date to today
            const dueDate = document.getElementById('due_date').value;
            if (dueDate) {
                const today = new Date().toISOString().split('T')[0];
                if (dueDate < today) {
                    e.preventDefault();
                    alert('Due date cannot be in the past.');
                    document.getElementById('due_date').focus();
                    return false;
                }
            }
            
            return true;
        });
        
        // Auto-focus on title input
        document.getElementById('title').focus();
        
        // Set minimum date for due date input
        const today = new Date().toISOString().split('T')[0];
        document.getElementById('due_date').min = today;
        
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
                charCount.textContent = `${length}/500 characters`;
                
                if (length > 500) {
                    charCount.style.color = '#ef4444';
                } else if (length > 400) {
                    charCount.style.color = '#f59e0b';
                } else {
                    charCount.style.color = '#6b7280';
                }
            }
            
            descriptionTextarea.addEventListener('input', updateCharCount);
            updateCharCount(); // Initial count
        }
        
        // Clear form confirmation
        document.querySelector('button[type="reset"]').addEventListener('click', function(e) {
            if (!confirm('Are you sure you want to clear all form fields?')) {
                e.preventDefault();
            }
        });
    </script>
</body>
</html>