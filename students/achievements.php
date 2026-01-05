<?php
// students/achievements.php

session_start();
require_once '../includes/db_connection.php';
require_once '../includes/functions.php'; // For getStudentAchievements() function

checkAuth('student');

$student_id = $_SESSION['user_id'];
$success = $_SESSION['success'] ?? '';
$error = $_SESSION['error'] ?? '';
unset($_SESSION['success'], $_SESSION['error']);

// === Fetch Student Statistics ===
$stmt = $pdo->prepare("SELECT COUNT(*) FROM student_goals WHERE student_id = ?");
$stmt->execute([$student_id]);
$total_goals = $stmt->fetchColumn() ?: 0;

$stmt = $pdo->prepare("SELECT COUNT(*) FROM student_goals WHERE student_id = ? AND status = 'completed'");
$stmt->execute([$student_id]);
$completed_goals = $stmt->fetchColumn() ?: 0;

$stmt = $pdo->prepare("SELECT points FROM users WHERE id = ?");
$stmt->execute([$student_id]);
$total_points = $stmt->fetchColumn() ?: 0;

$stmt = $pdo->prepare("SELECT current_streak FROM users WHERE id = ?");
$stmt->execute([$student_id]);
$streak = $stmt->fetchColumn() ?: 0;

$stmt = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0");
$stmt->execute([$student_id]);
$unread = $stmt->fetchColumn() ?: 0;

// === Fetch Earned Achievements ===
$earned_achievements = getStudentAchievements($pdo, $student_id);

// === Fetch Achievement Progress (unearned achievements) ===
$progress_achievements = getAchievementProgress($pdo, $student_id);

// === Fetch Recent Activity ===
$recent_activity = $pdo->prepare("
    SELECT 
        n.*,
        DATE_FORMAT(n.created_at, '%b %d, %Y %h:%i %p') as formatted_date
    FROM notifications n
    WHERE n.user_id = ? AND n.type = 'achievement'
    ORDER BY n.created_at DESC
    LIMIT 10
");
$recent_activity->execute([$student_id]);
$recent_activity = $recent_activity->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Achievements - ProgressMate</title>
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
            --gold: #fbbf24;
            --silver: #9ca3af;
            --bronze: #f97316;
            
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
        }

        .header-content p {
            color: var(--secondary);
            font-size: var(--font-size-lg);
        }

        /* Stats Grid */
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
            text-align: center;
        }

        .stat-card:hover {
            transform: translateY(-4px);
            box-shadow: var(--shadow-lg);
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
            margin: 0 auto var(--spacing-md);
        }

        .stat-card:nth-child(1) .stat-icon { background: linear-gradient(135deg, var(--primary), #8b5cf6); }
        .stat-card:nth-child(2) .stat-icon { background: linear-gradient(135deg, var(--success), #059669); }
        .stat-card:nth-child(3) .stat-icon { background: linear-gradient(135deg, var(--warning), #d97706); }
        .stat-card:nth-child(4) .stat-icon { background: linear-gradient(135deg, var(--purple), #a855f7); }

        .stat-number {
            font-size: 2rem;
            font-weight: 800;
            color: var(--dark);
            margin-bottom: var(--spacing-xs);
        }

        .stat-label {
            font-size: var(--font-size-sm);
            color: var(--gray-dark);
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

        /* Tabs */
        .tabs {
            display: flex;
            gap: 4px;
            border-bottom: 1px solid var(--gray-300);
            margin-bottom: 32px;
        }

        .tab {
            padding: 16px 24px;
            background: none;
            border: none;
            color: var(--gray-500);
            font-weight: 600;
            cursor: pointer;
            border-bottom: 3px solid transparent;
            transition: var(--transition);
            position: relative;
        }

        .tab:hover { color: var(--primary); }
        .tab.active { color: var(--primary); border-bottom-color: var(--primary); }
        .tab-badge { position: absolute; top: 8px; right: 8px; background: var(--primary); color: white; font-size: 10px; padding: 2px 6px; border-radius: 10px; }

        /* Content Grid */
        .content-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: var(--spacing-lg);
            margin-bottom: var(--spacing-2xl);
        }

        .card {
            background: var(--white);
            border-radius: var(--radius-lg);
            box-shadow: var(--shadow-md);
            overflow: hidden;
            border: 1px solid var(--gray);
        }

        .card-header {
            padding: var(--spacing-lg);
            border-bottom: 1px solid var(--gray);
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 16px;
        }

        .card-header h3 {
            font-size: 19px;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .card-body {
            padding: var(--spacing-lg);
        }

        /* Achievements Grid */
        .achievements-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(140px, 1fr));
            gap: var(--spacing-md);
        }

        .achievement-item {
            text-align: center;
            padding: var(--spacing-md);
            border-radius: var(--radius-lg);
            background: var(--secondary-light);
            border: 2px solid var(--gray);
            transition: all var(--transition-base);
            position: relative;
            overflow: hidden;
        }

        .achievement-item:hover {
            transform: translateY(-4px);
            box-shadow: var(--shadow-md);
        }

        .achievement-item.earned {
            border-color: var(--success);
            background: linear-gradient(to bottom, var(--success-light), var(--white));
        }

        .achievement-item.locked {
            opacity: 0.8;
            filter: grayscale(30%);
        }

        .achievement-icon {
            width: 64px;
            height: 64px;
            border-radius: var(--radius-lg);
            margin: 0 auto var(--spacing-sm);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
            color: var(--white);
            box-shadow: var(--shadow-md);
        }

        .achievement-title {
            font-size: var(--font-size-sm);
            font-weight: 600;
            color: var(--dark);
            margin-bottom: var(--spacing-xs);
            line-height: 1.3;
        }

        .achievement-points {
            font-size: var(--font-size-xs);
            color: var(--warning);
            font-weight: 700;
        }

        .achievement-date {
            font-size: 0.7rem;
            color: var(--gray-dark);
            margin-top: 5px;
        }

        .achievement-progress {
            width: 100%;
            height: 6px;
            background: var(--gray);
            border-radius: 3px;
            margin-top: 10px;
            overflow: hidden;
        }

        .progress-fill {
            height: 100%;
            background: linear-gradient(90deg, var(--primary), #7c3aed);
            border-radius: 3px;
            transition: width var(--transition-slow);
        }

        .progress-fill.earned {
            background: var(--success);
        }

        .progress-text {
            font-size: 0.7rem;
            color: var(--gray-dark);
            margin-top: 4px;
        }

        /* Progress Bar */
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

        .progress-fill.completed {
            background: var(--success);
        }

        /* Leaderboard */
        .leaderboard-list {
            display: flex;
            flex-direction: column;
            gap: var(--spacing-sm);
        }

        .leaderboard-item {
            display: flex;
            align-items: center;
            gap: var(--spacing-md);
            padding: var(--spacing-md);
            border-radius: var(--radius-md);
            background: var(--secondary-light);
            border: 1px solid var(--gray);
            transition: all var(--transition-base);
        }

        .leaderboard-item:hover {
            background: var(--primary-light);
            transform: translateX(4px);
        }

        .leaderboard-rank {
            font-size: 1.25rem;
            font-weight: 800;
            color: var(--primary);
            width: 40px;
            text-align: center;
        }

        .leaderboard-rank.gold { color: var(--gold); }
        .leaderboard-rank.silver { color: var(--silver); }
        .leaderboard-rank.bronze { color: var(--bronze); }

        .leaderboard-avatar {
            width: 48px;
            height: 48px;
            border-radius: 50%;
            object-fit: cover;
            border: 3px solid var(--primary-light);
        }

        .leaderboard-avatar.default {
            background: linear-gradient(135deg, var(--primary), #8b5cf6);
            color: var(--white);
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: bold;
        }

        .leaderboard-info {
            flex: 1;
        }

        .leaderboard-name {
            font-weight: 600;
            color: var(--dark);
            margin-bottom: var(--spacing-xs);
        }

        .leaderboard-stats {
            display: flex;
            gap: var(--spacing-md);
            font-size: var(--font-size-sm);
            color: var(--gray-dark);
        }

        .leaderboard-score {
            margin-left: auto;
            font-weight: 700;
            color: var(--primary);
        }

        /* Empty States */
        .empty-state {
            text-align: center;
            padding: var(--spacing-2xl);
            color: var(--secondary);
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

        /* Activity List */
        .activity-list {
            display: flex;
            flex-direction: column;
            gap: var(--spacing-sm);
        }

        .activity-item {
            display: flex;
            align-items: flex-start;
            gap: var(--spacing-md);
            padding: var(--spacing-md);
            border-radius: var(--radius-md);
            background: var(--secondary-light);
            border-left: 4px solid var(--gray);
            transition: all var(--transition-base);
        }

        .activity-item:hover {
            transform: translateX(4px);
        }

        .activity-item.achievement {
            border-left-color: var(--warning);
        }

        .activity-icon {
            width: 48px;
            height: 48px;
            border-radius: var(--radius-md);
            background: var(--primary-light);
            color: var(--primary);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.25rem;
            flex-shrink: 0;
        }

        .activity-content {
            flex: 1;
        }

        .activity-title {
            font-weight: 600;
            color: var(--dark);
            margin-bottom: var(--spacing-xs);
        }

        .activity-message {
            font-size: var(--font-size-sm);
            color: var(--secondary);
            margin-bottom: var(--spacing-xs);
            line-height: 1.4;
        }

        .activity-time {
            font-size: var(--font-size-xs);
            color: var(--gray-dark);
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
            .stats-grid, .achievements-grid { grid-template-columns: 1fr; }
            .content-grid { grid-template-columns: 1fr; }
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
                <a href="goals.php" class="nav-link"><i class="fas fa-bullseye"></i> My Goals</a>
                <a href="create_goal.php" class="nav-link"><i class="fas fa-plus-circle"></i> Create Goal</a>
                <a href="achievements.php" class="nav-link active"><i class="fas fa-trophy"></i> Achievements <?php if (count($earned_achievements) > 0): ?><span class="badge"><?php echo count($earned_achievements); ?></span><?php endif; ?></a>
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
                    <h1>My Achievements</h1>
                    <p>Track your progress and celebrate your accomplishments</p>
                </div>
            </header>

            <?php if ($success): ?>
                <div class="alert alert-success"><i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($success); ?></div>
            <?php endif; ?>
            <?php if ($error): ?>
                <div class="alert alert-error"><i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($error); ?></div>
            <?php endif; ?>

            <!-- Quick Stats -->
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-icon"><i class="fas fa-trophy"></i></div>
                    <div class="stat-number"><?php echo count($earned_achievements); ?></div>
                    <div class="stat-label">Achievements Earned</div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon"><i class="fas fa-star"></i></div>
                    <div class="stat-number"><?php echo $total_points; ?></div>
                    <div class="stat-label">Total Points</div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon"><i class="fas fa-bullseye"></i></div>
                    <div class="stat-number"><?php echo $completed_goals; ?></div>
                    <div class="stat-label">Goals Completed</div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon"><i class="fas fa-fire"></i></div>
                    <div class="stat-number"><?php echo $streak; ?> days</div>
                    <div class="stat-label">Current Streak</div>
                </div>
            </div>

            <!-- Tabs -->
            <div class="tabs">
                <button class="tab active" data-tab="earned">
                    <i class="fas fa-unlock"></i> Earned Achievements
                    <span class="tab-badge"><?php echo count($earned_achievements); ?></span>
                </button>
                <button class="tab" data-tab="progress">
                    <i class="fas fa-bullseye"></i> In Progress
                    <span class="tab-badge"><?php echo count($progress_achievements); ?></span>
                </button>
                <button class="tab" data-tab="activity">
                    <i class="fas fa-history"></i> Recent Activity
                </button>
            </div>

            <!-- Tab 1: Earned Achievements -->
            <div class="tab-content active" id="tab-earned">
                <div class="card">
                    <div class="card-header">
                        <h3><i class="fas fa-unlock"></i> Your Earned Achievements</h3>
                        <span style="color: var(--gray-dark); font-size: 14px;">
                            <?php echo count($earned_achievements); ?> achievements earned • <?php echo $total_points; ?> total points
                        </span>
                    </div>
                    <div class="card-body">
                        <?php if (empty($earned_achievements)): ?>
                            <div class="empty-state">
                                <i class="fas fa-trophy"></i>
                                <p>No achievements earned yet</p>
                                <p style="font-size: 14px; margin-top: 10px;">Complete goals to earn achievements!</p>
                                <a href="goals.php" class="btn" style="background: var(--primary); color: white; padding: 10px 20px; border-radius: 8px; margin-top: 15px; display: inline-block;">
                                    <i class="fas fa-bullseye"></i> View Goals
                                </a>
                            </div>
                        <?php else: ?>
                            <div class="achievements-grid">
                                <?php foreach ($earned_achievements as $achievement): ?>
                                    <div class="achievement-item earned">
                                        <div class="achievement-icon" style="background: <?php echo htmlspecialchars($achievement['color']); ?>;">
                                            <i class="fas fa-<?php echo htmlspecialchars($achievement['icon']); ?>"></i>
                                        </div>
                                        <div class="achievement-title"><?php echo htmlspecialchars($achievement['title']); ?></div>
                                        <div class="achievement-points">+<?php echo $achievement['points']; ?> points</div>
                                        <div class="achievement-date">Earned: <?php echo date('M d, Y', strtotime($achievement['earned_date'])); ?></div>
                                        <?php if ($achievement['description']): ?>
                                            <div style="font-size: 0.75rem; color: var(--gray-dark); margin-top: 5px;">
                                                <?php echo htmlspecialchars($achievement['description']); ?>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Tab 2: Achievement Progress -->
            <div class="tab-content" id="tab-progress">
                <div class="card">
                    <div class="card-header">
                        <h3><i class="fas fa-bullseye"></i> Achievements in Progress</h3>
                        <span style="color: var(--gray-dark); font-size: 14px;">
                            <?php echo count($progress_achievements); ?> achievements to unlock
                        </span>
                    </div>
                    <div class="card-body">
                        <?php if (empty($progress_achievements)): ?>
                            <div class="empty-state">
                                <i class="fas fa-flag-checkered"></i>
                                <p>All available achievements earned!</p>
                                <p style="font-size: 14px; margin-top: 10px;">Check back later for new achievements</p>
                            </div>
                        <?php else: ?>
                            <div class="achievements-grid">
                                <?php foreach ($progress_achievements as $progress): 
                                    $achievement = $progress['achievement'];
                                ?>
                                    <div class="achievement-item locked">
                                        <div class="achievement-icon" style="background: <?php echo htmlspecialchars($achievement['color']); ?>; opacity: 0.7;">
                                            <i class="fas fa-<?php echo htmlspecialchars($achievement['icon']); ?>"></i>
                                        </div>
                                        <div class="achievement-title"><?php echo htmlspecialchars($achievement['title']); ?></div>
                                        <div class="achievement-points">+<?php echo $achievement['points']; ?> points</div>
                                        
                                        <!-- Progress Bar -->
                                        <div class="achievement-progress">
                                            <div class="progress-fill" style="width: <?php echo $progress['percentage']; ?>%"></div>
                                        </div>
                                        <div class="progress-text">
                                            <?php echo $progress['current_value']; ?>/<?php echo $progress['target_value']; ?>
                                            (<?php echo $progress['percentage']; ?>%)
                                        </div>
                                        
                                        <?php if ($achievement['criteria_type'] == 'total_completed_goals'): ?>
                                            <div style="font-size: 0.7rem; color: var(--gray-dark); margin-top: 5px;">
                                                Complete <?php echo $progress['remaining']; ?> more goal(s)
                                            </div>
                                        <?php elseif ($achievement['criteria_type'] == 'total_points'): ?>
                                            <div style="font-size: 0.7rem; color: var(--gray-dark); margin-top: 5px;">
                                                Earn <?php echo $progress['remaining']; ?> more points
                                            </div>
                                        <?php elseif ($achievement['criteria_type'] == 'login_streak'): ?>
                                            <div style="font-size: 0.7rem; color: var(--gray-dark); margin-top: 5px;">
                                                Login for <?php echo $progress['remaining']; ?> more day(s)
                                            </div>
                                        <?php elseif ($achievement['description']): ?>
                                            <div style="font-size: 0.7rem; color: var(--gray-dark); margin-top: 5px;">
                                                <?php echo htmlspecialchars($achievement['description']); ?>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Tab 3: Recent Activity -->
            <div class="tab-content" id="tab-activity">
                <div class="content-grid">
                    <!-- Recent Achievement Unlocks -->
                    <div class="card">
                        <div class="card-header">
                            <h3><i class="fas fa-history"></i> Recent Achievement Activity</h3>
                        </div>
                        <div class="card-body">
                            <?php if (empty($recent_activity)): ?>
                                <div class="empty-state">
                                    <i class="fas fa-bell-slash"></i>
                                    <p>No recent achievement activity</p>
                                </div>
                            <?php else: ?>
                                <div class="activity-list">
                                    <?php foreach ($recent_activity as $activity): ?>
                                        <div class="activity-item achievement">
                                            <div class="activity-icon">
                                                <i class="fas fa-trophy"></i>
                                            </div>
                                            <div class="activity-content">
                                                <div class="activity-title"><?php echo htmlspecialchars($activity['title']); ?></div>
                                                <div class="activity-message"><?php echo htmlspecialchars($activity['message']); ?></div>
                                                <div class="activity-time"><?php echo $activity['formatted_date']; ?></div>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Top Students Leaderboard -->
                    <div class="card">
                        <div class="card-header">
                            <h3><i class="fas fa-medal"></i> Top Achievers</h3>
                        </div>
                        <div class="card-body">
                            <?php
                            // Fetch top students in the system
                            $top_students = $pdo->query("
                                SELECT 
                                    u.id, u.name, u.profile_picture,
                                    COUNT(ua.id) as achievements_count,
                                    COALESCE(SUM(a.points), 0) as total_points
                                FROM users u
                                LEFT JOIN user_achievements ua ON u.id = ua.user_id AND ua.deleted_at IS NULL
                                LEFT JOIN achievements a ON ua.achievement_id = a.id AND a.deleted_at IS NULL
                                WHERE u.role = 'student' AND u.status = 'active'
                                GROUP BY u.id
                                ORDER BY achievements_count DESC, total_points DESC
                                LIMIT 5
                            ")->fetchAll(PDO::FETCH_ASSOC);
                            ?>
                            
                            <?php if (empty($top_students)): ?>
                                <div class="empty-state">
                                    <i class="fas fa-users"></i>
                                    <p>No leaderboard data yet</p>
                                </div>
                            <?php else: ?>
                                <div class="leaderboard-list">
                                    <?php foreach ($top_students as $index => $student): 
                                        $rank_class = '';
                                        if ($index === 0) $rank_class = 'gold';
                                        elseif ($index === 1) $rank_class = 'silver';
                                        elseif ($index === 2) $rank_class = 'bronze';
                                    ?>
                                        <div class="leaderboard-item <?php echo $student['id'] == $student_id ? 'highlight' : ''; ?>">
                                            <div class="leaderboard-rank <?php echo $rank_class; ?>">
                                                <?php echo $index + 1; ?>
                                            </div>
                                            <?php if (!empty($student['profile_picture'])): ?>
                                                <img src="../<?php echo htmlspecialchars($student['profile_picture']); ?>" alt="Profile" class="leaderboard-avatar">
                                            <?php else: ?>
                                                <div class="leaderboard-avatar default">
                                                    <?php echo strtoupper(substr($student['name'], 0, 1)); ?>
                                                </div>
                                            <?php endif; ?>
                                            <div class="leaderboard-info">
                                                <div class="leaderboard-name">
                                                    <?php echo htmlspecialchars($student['name']); ?>
                                                    <?php if ($student['id'] == $student_id): ?>
                                                        <span style="font-size: 0.75rem; color: var(--primary);">(You)</span>
                                                    <?php endif; ?>
                                                </div>
                                                <div class="leaderboard-stats">
                                                    <span><?php echo $student['achievements_count']; ?> achievements</span>
                                                    <span><?php echo $student['total_points']; ?> points</span>
                                                </div>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Tips Section -->
            <div style="background: white; border-radius: 12px; padding: 20px; margin-top: 30px; box-shadow: 0 2px 10px rgba(0,0,0,0.05);">
                <h3 style="margin-bottom: 15px; color: #111827; display: flex; align-items: center; gap: 10px;">
                    <i class="fas fa-lightbulb" style="color: #f59e0b;"></i>
                    How to Earn More Achievements
                </h3>
                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 15px;">
                    <div style="background: #f9fafb; padding: 15px; border-radius: 8px;">
                        <div style="font-weight: 500; color: #111827; margin-bottom: 5px; display: flex; align-items: center; gap: 8px;">
                            <i class="fas fa-bullseye" style="color: var(--primary);"></i> Complete Goals
                        </div>
                        <div style="font-size: 13px; color: #6b7280;">Finish your assigned and personal goals</div>
                    </div>
                    <div style="background: #f9fafb; padding: 15px; border-radius: 8px;">
                        <div style="font-weight: 500; color: #111827; margin-bottom: 5px; display: flex; align-items: center; gap: 8px;">
                            <i class="fas fa-fire" style="color: var(--warning);"></i> Maintain Streak
                        </div>
                        <div style="font-size: 13px; color: #6b7280;">Log in daily to keep your streak alive</div>
                    </div>
                    <div style="background: #f9fafb; padding: 15px; border-radius: 8px;">
                        <div style="font-weight: 500; color: #111827; margin-bottom: 5px; display: flex; align-items: center; gap: 8px;">
                            <i class="fas fa-calendar-check" style="color: var(--success);"></i> Be Consistent
                        </div>
                        <div style="font-size: 13px; color: #6b7280;">Work on goals regularly to earn more points</div>
                    </div>
                    <div style="background: #f9fafb; padding: 15px; border-radius: 8px;">
                        <div style="font-weight: 500; color: #111827; margin-bottom: 5px; display: flex; align-items: center; gap: 8px;">
                            <i class="fas fa-star" style="color: var(--purple);"></i> Earn Points
                        </div>
                        <div style="font-size: 13px; color: #6b7280;">Accumulate points through achievements</div>
                    </div>
                </div>
            </div>
        </main>
    </div>

    <script>
        // Tab functionality
        document.querySelectorAll('.tab').forEach(tab => {
            tab.addEventListener('click', () => {
                // Remove active class from all tabs
                document.querySelectorAll('.tab').forEach(t => t.classList.remove('active'));
                document.querySelectorAll('.tab-content').forEach(c => c.classList.remove('active'));
                
                // Add active class to clicked tab
                tab.classList.add('active');
                
                // Show corresponding content
                const tabId = tab.dataset.tab;
                document.getElementById('tab-' + tabId).classList.add('active');
            });
        });

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

        // Auto-hide sidebar on mobile when clicking outside
        document.addEventListener('click', function(event) {
            if (window.innerWidth <= 768 && 
                sidebar && sidebar.classList.contains('active') &&
                !sidebar.contains(event.target) && 
                !sidebarToggle.contains(event.target)) {
                closeSidebar();
            }
        });

        // Achievement hover effect
        document.querySelectorAll('.achievement-item').forEach(item => {
            item.addEventListener('mouseenter', function() {
                this.style.transform = 'translateY(-6px) scale(1.02)';
            });
            item.addEventListener('mouseleave', function() {
                this.style.transform = 'translateY(0) scale(1)';
            });
        });

        // Add subtle animation to stats cards
        document.querySelectorAll('.stat-card').forEach((card, index) => {
            card.style.animationDelay = (index * 100) + 'ms';
        });
    </script>
</body>
</html>