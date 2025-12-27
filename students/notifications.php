<?php
session_start();
require_once '../includes/db_connection.php';
checkAuth('student');

$student_id = $_SESSION['user_id'];

// === Sidebar Stats ===
$total_goals = $pdo->prepare("SELECT COUNT(*) FROM student_goals WHERE student_id = ?");
$total_goals->execute([$student_id]);
$total_goals = $total_goals->fetchColumn();

$completed_goals = $pdo->prepare("SELECT COUNT(*) FROM student_goals WHERE student_id = ? AND status = 'completed'");
$completed_goals->execute([$student_id]);
$completed_goals = $completed_goals->fetchColumn();

$total_points = $pdo->prepare("SELECT points FROM users WHERE id = ?");
$total_points->execute([$student_id]);
$total_points = $total_points->fetchColumn() ?: 0;

$streak = $pdo->prepare("SELECT current_streak FROM users WHERE id = ?");
$streak->execute([$student_id]);
$streak = $streak->fetchColumn() ?: 0;

$unread = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0");
$unread->execute([$student_id]);
$unread = $unread->fetchColumn();

// === Handle Actions ===
$success = $error = '';
if (isset($_GET['read'])) {
    $id = (int)$_GET['read'];
    $stmt = $pdo->prepare("UPDATE notifications SET is_read = 1 WHERE id = ? AND user_id = ?");
    $stmt->execute([$id, $student_id]);
    $success = "Notification marked as read.";
    header("Location: notifications.php");
    exit;
}

if (isset($_GET['delete'])) {
    $id = (int)$_GET['delete'];
    $stmt = $pdo->prepare("DELETE FROM notifications WHERE id = ? AND user_id = ?");
    $stmt->execute([$id, $student_id]);
    $success = "Notification deleted.";
    header("Location: notifications.php");
    exit;
}

// === Pagination ===
$per_page = 20;
$page = max(1, $_GET['page'] ?? 1);
$offset = ($page - 1) * $per_page;

$total_stmt = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ?");
$total_stmt->execute([$student_id]);
$total_notifications = $total_stmt->fetchColumn();
$total_pages = ceil($total_notifications / $per_page);

// === Fetch Notifications ===
$per_page = 20;
$page = max(1, $_GET['page'] ?? 1);
$offset = ($page - 1) * $per_page;

// === Fetch Notifications safely ===
$stmt = $pdo->prepare("
    SELECT * FROM notifications 
    WHERE user_id = ? 
    ORDER BY created_at DESC 
    LIMIT $per_page OFFSET $offset
");
$stmt->execute([$student_id]);
$notifications = $stmt->fetchAll();


?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Notifications - ProgressMate</title>
       <link rel="stylesheet" href="../assets/css/style.css">
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
            padding: 1rem 1.5rem;
            border-radius: var(--radius-md);
            margin-bottom: 1.5rem;
            display: flex;
            align-items: center;
            gap: 0.75rem;
            font-weight: 500;
            box-shadow: var(--shadow-sm);
        }

        .alert-success { background: #d1fae5; color: #065f46; border-left: 5px solid var(--success); }
        .alert-error { background: #fee2e2; color: #991b1b; border-left: 5px solid var(--danger); }

        /* Notifications List - Clean & Modern */
        .notifications-container {
            max-width: 900px;
            margin: 0 auto;
        }

        .notifications-list {
            display: flex;
            flex-direction: column;
            gap: 1rem;
        }

        .notification-item {
            background: var(--white);
            border-radius: var(--radius-lg);
            padding: 1.4rem 1.6rem;
            box-shadow: var(--shadow-sm);
            border: 1px solid var(--gray);
            display: flex;
            align-items: flex-start;
            gap: 1.2rem;
            transition: var(--transition);
            position: relative;
        }

        .notification-item:hover {
            box-shadow: var(--shadow-md);
            transform: translateY(-4px);
        }

        .notification-item.unread {
            background: var(--primary-light);
            border-left: 5px solid var(--primary);
        }

        .notification-icon {
            width: 48px;
            height: 48px;
            border-radius: var(--radius-md);
            background: var(--primary-light);
            color: var(--primary);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.3rem;
            flex-shrink: 0;
        }

        .notification-item.unread .notification-icon {
            background: var(--primary);
            color: white;
        }

        .notification-content {
            flex: 1;
            min-width: 0;
        }

        .notification-title {
            font-weight: 700;
            font-size: 1.1rem;
            color: var(--dark);
            margin-bottom: 0.4rem;
        }

        .notification-message {
            font-size: 0.98rem;
            color: var(--secondary);
            line-height: 1.5;
            margin-bottom: 0.6rem;
        }

        .notification-time {
            font-size: 0.85rem;
            color: var(--gray-dark);
        }

        .notification-actions {
            display: flex;
            flex-direction: column;
            gap: 0.5rem;
            margin-left: auto;
        }

        .notification-actions .btn-sm {
            padding: 0.4rem 0.8rem;
            font-size: 0.85rem;
            white-space: nowrap;
        }

        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 4rem 2rem;
            background: var(--white);
            border-radius: var(--radius-lg);
            box-shadow: var(--shadow-sm);
            margin: 2rem auto;
            max-width: 600px;
        }

        .empty-state i {
            font-size: 4.5rem;
            color: var(--gray-dark);
            opacity: 0.3;
            margin-bottom: 1.5rem;
        }

        .empty-state p {
            font-size: 1.3rem;
            color: var(--secondary);
            margin-bottom: 1.5rem;
        }

        /* Pagination */
        .pagination {
            display: flex;
            justify-content: center;
            gap: 0.75rem;
            margin-top: 2.5rem;
            flex-wrap: wrap;
        }

        .pagination a {
            padding: 0.6rem 1rem;
            border-radius: var(--radius-md);
            background: var(--white);
            color: var(--primary);
            border: 1px solid var(--gray);
            font-weight: 500;
            transition: var(--transition);
        }

        .pagination a:hover,
        .pagination a.active {
            background: var(--primary);
            color: white;
            border-color: var(--primary);
        }

        /* Responsive */
        @media (max-width: 992px) {
            .main-content { margin-left: 0; padding-top: 90px; }
            .back-btn { position: static; margin-bottom: 1.5rem; display: inline-flex; }
            .page-header { text-align: left; }
        }

        @media (max-width: 768px) {
            .notification-item {
                flex-direction: column;
            }
            .notification-actions {
                flex-direction: row;
                width: 100%;
                margin-left: 0;
                margin-top: 1rem;
            }
            .page-header h1 { font-size: 2.4rem; }
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
                <a href="dashboard.php" class="nav-link">
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
                <a href="create_goal.php" class="nav-link">
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
                <a href="notifications.php" class="nav-link active">
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
                    <h1>Notifications</h1>
                    <p>Stay updated with your progress and reminders</p>
                </div>
                <div class="header-actions">
                    <a href="dashboard.php" class="btn btn-outline">
                        <i class="fas fa-arrow-left"></i> Back to Dashboard
                    </a>
                </div>
            </header>
            
            <!-- Alerts -->
            <?php if ($success): ?>
                <div class="alert alert-success">
                    <i class="fas fa-check-circle"></i>
                    <span><?php echo htmlspecialchars($success); ?></span>
                </div>
            <?php endif; ?>
            
            <?php if (!empty($error)): ?>
                <div class="alert alert-error">
                    <i class="fas fa-exclamation-circle"></i>
                    <span><?php echo htmlspecialchars($error); ?></span>
                </div>
            <?php endif; ?>
            
            <!-- Notifications List -->
            <div class="notifications-list">
                <?php if (empty($notifications)): ?>
                    <div class="empty-state">
                        <i class="fas fa-bell"></i>
                        <p>No notifications yet</p>
                        <a href="goals.php" class="btn btn-primary">
                            <i class="fas fa-bullseye"></i> View Goals
                        </a>
                    </div>
                <?php else: ?>
                    <?php foreach ($notifications as $notification): ?>
                        <div class="notification-item <?php echo $notification['is_read'] ? 'read' : 'unread'; ?>">
                            <div class="notification-icon <?php echo $notification['type']; ?>">
                                <i class="fas fa-<?php echo $notification['type'] == 'goal' ? 'bullseye' : ($notification['type'] == 'achievement' ? 'trophy' : 'bell'); ?>"></i>
                            </div>
                            <div class="notification-content">
                                <div class="notification-title"><?php echo htmlspecialchars($notification['title']); ?></div>
                                <div class="notification-message"><?php echo htmlspecialchars($notification['message']); ?></div>
                                <div class="notification-time"><?php echo date('M d, Y h:i A', strtotime($notification['created_at'])); ?></div>
                            </div>
                            <div class="notification-actions">
                                <?php if (!$notification['is_read']): ?>
                                    <a href="?read=<?php echo $notification['id']; ?>" class="btn btn-sm btn-outline">Mark as Read</a>
                                <?php endif; ?>
                                <a href="?delete=<?php echo $notification['id']; ?>" class="btn btn-sm btn-outline" onclick="return confirm('Delete this notification?');">Delete</a>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
            
            <!-- Pagination -->
            <div class="pagination">
                <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                    <a href="?page=<?php echo $i; ?>" class="btn btn-outline <?php echo $page == $i ? 'active' : ''; ?>"><?php echo $i; ?></a>
                <?php endfor; ?>
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
        
        // Auto-hide alerts after 5 seconds
        setTimeout(() => {
            const alerts = document.querySelectorAll('.alert');
            alerts.forEach(alert => {
                alert.style.opacity = '0';
                setTimeout(() => {
                    alert.style.display = 'none';
                }, 300);
            });
        }, 5000);
    </script>
    <script>setInterval(() => {
    fetch('notifications_fetch.php')
        .then(res => res.json())
        .then(data => {
            const notifBox = document.getElementById('notifBox');
            notifBox.innerHTML = '';
            data.forEach(n => {
                const div = document.createElement('div');
                div.className = 'notif-item';
                div.textContent = `${n.title}: ${n.message}`;
                notifBox.appendChild(div);
            });
        });
}, 10000); // every 10 seconds
</script>
</body>
</html>