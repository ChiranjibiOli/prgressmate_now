<?php
session_start();
require_once '../includes/db_connection.php';
checkAuth('student');

$student_id = $_SESSION['user_id'];

// === Flash Messages ===
$success = $_SESSION['success'] ?? '';
$error = $_SESSION['error'] ?? '';
unset($_SESSION['success'], $_SESSION['error']);

// === Sidebar Stats ===
$total_goals_stmt = $pdo->prepare("SELECT COUNT(*) FROM student_goals WHERE student_id = ?");
$total_goals_stmt->execute([$student_id]);
$total_goals = $total_goals_stmt->fetchColumn() ?: 0;

$completed_goals_stmt = $pdo->prepare("SELECT COUNT(*) FROM student_goals WHERE student_id = ? AND status = 'completed'");
$completed_goals_stmt->execute([$student_id]);
$completed_goals = $completed_goals_stmt->fetchColumn() ?: 0;

$total_points_stmt = $pdo->prepare("SELECT points FROM users WHERE id = ?");
$total_points_stmt->execute([$student_id]);
$total_points = $total_points_stmt->fetchColumn() ?: 0;

$streak_stmt = $pdo->prepare("SELECT current_streak FROM users WHERE id = ?");
$streak_stmt->execute([$student_id]);
$streak = $streak_stmt->fetchColumn() ?: 0;

$unread_stmt = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0");
$unread_stmt->execute([$student_id]);
$unread = $unread_stmt->fetchColumn() ?: 0;

// === Fetch Achievements ===
// === Fetch Achievements ===
$achievements_stmt = $pdo->prepare("
    SELECT a.*, ua.earned_at
    FROM achievements a
    LEFT JOIN user_achievements ua ON a.id = ua.achievement_id AND ua.user_id = ?
    ORDER BY (ua.earned_at IS NULL), ua.earned_at DESC, a.created_at DESC
");
$achievements_stmt->execute([$student_id]);
$achievements = $achievements_stmt->fetchAll();

// === Progress Stats ===
$total_count = count($achievements);
$unlocked_count = 0;
$unlocked_points = 0;
$total_possible_points = 0;

foreach ($achievements as $ach) {
    $total_possible_points += $ach['points'] ?? 0;
    if (!empty($ach['earned_at'])) {
        $unlocked_count++;
        $unlocked_points += $ach['points'] ?? 0;
    }
}

$unlocked_percentage = $total_count > 0 ? round(($unlocked_count / $total_count) * 100) : 0;
$points_percentage = $total_possible_points > 0 ? round(($unlocked_points / $total_possible_points) * 100) : 0;
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Achievements - ProgressMate</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">

    <style>
    
     
        /* Sidebar styles - UNCHANGED (kept exactly as provided) */
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
*{
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

/* ===== MAIN CONTENT - SCROLLABLE ===== */
.main-content {
    flex: 1;
    margin-left: 280px;
    padding: var(--spacing-xl);
    width: calc(100% - 280px);
    min-height: 100vh;
    overflow-y: auto; /* Main content scrolls */
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

        
        /* ... [all original sidebar CSS remains here] ... */

        /* ===== MAIN CONTENT - ENHANCED & MODERNIZED ===== */
        .main-content {
            flex: 1;
            margin-left: 280px;
            padding: 2rem 1.5rem;
            min-height: 100vh;
            animation: fadeIn 0.6s ease-out;
        }

        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .page-header {
            margin-bottom: 2.5rem;
        }

        .page-header h1 {
            font-size: 2.5rem;
            font-weight: 800;
            color: var(--dark);
            margin-bottom: 0.5rem;
        }

        .page-header p {
            color: var(--secondary);
            font-size: 1.1rem;
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
            box-shadow: var(--shadow-md);
        }

        .alert-success { background: var(--success-light); color: #065f46; border-left: 5px solid var(--success); }
        .alert-error { background: #fee2e2; color: #991b1b; border-left: 5px solid var(--danger); }

        /* Total Points Hero Card */
        .total-points-hero {
            background: linear-gradient(135deg, var(--primary), var(--primary-dark));
            color: white;
            padding: 2.5rem;
            border-radius: var(--radius-lg);
            text-align: center;
            margin-bottom: 2.5rem;
            box-shadow: var(--shadow-lg);
            position: relative;
            overflow: hidden;
        }

        .total-points-hero::before {
            content: '';
            position: absolute;
            top: -50%; left: -50%;
            width: 200%; height: 200%;
            background: radial-gradient(circle, rgba(255,255,255,0.1) 0%, transparent 70%);
            animation: shine 8s infinite linear;
        }

        @keyframes shine {
            0% { transform: translate(0, 0) rotate(0deg); }
            100% { transform: translate(50%, 50%) rotate(360deg); }
        }

        .total-points-number {
            font-size: 4rem;
            font-weight: 800;
            line-height: 1;
            margin-bottom: 0.5rem;
        }

        .total-points-label {
            font-size: 1.25rem;
            opacity: 0.9;
        }

        /* Progress Overview */
        .progress-overview-card {
            background: var(--white);
            border-radius: var(--radius-lg);
            padding: 2rem;
            box-shadow: var(--shadow-md);
            margin-bottom: 2.5rem;
            display: flex;
            align-items: center;
            gap: 3rem;
            flex-wrap: wrap;
        }

        .progress-circle {
            position: relative;
            width: 160px;
            height: 160px;
        }

        .progress-circle svg {
            width: 160px;
            height: 160px;
            transform: rotate(-90deg);
        }

        .circle-bg {
            fill: none;
            stroke: #e0e7ff;
            stroke-width: 12;
        }

        .circle-progress {
            fill: none;
            stroke: var(--primary);
            stroke-width: 12;
            stroke-linecap: round;
            stroke-dasharray: 440;
            stroke-dashoffset: 440;
            transition: stroke-dashoffset 1.5s ease-out;
        }

        .circle-text {
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            text-align: center;
        }

        .circle-percentage {
            font-size: 2.5rem;
            font-weight: 800;
            color: var(--primary);
        }

        .circle-label {
            font-size: 1rem;
            color: var(--secondary);
            margin-top: 0.25rem;
        }

        .progress-stats {
            flex: 1;
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1.5rem;
        }

        .progress-stat-label {
            font-size: 0.95rem;
            color: var(--secondary);
            margin-bottom: 0.5rem;
        }

        .progress-stat-value {
            font-size: 1.75rem;
            font-weight: 700;
            color: var(--dark);
        }

        /* Achievements Grid */
        .achievements-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
            gap: 1.75rem;
            margin-bottom: 3rem;
        }

        .achievement-card {
            background: var(--white);
            border-radius: var(--radius-lg);
            padding: 1.75rem;
            box-shadow: var(--shadow-md);
            border: 1px solid var(--gray);
            transition: var(--transition);
            position: relative;
            overflow: hidden;
        }

        .achievement-card:hover {
            transform: translateY(-10px);
            box-shadow: var(--shadow-lg);
        }

        .achievement-card.unlocked {
            border: 2px solid var(--success);
        }

        .achievement-card.locked {
            opacity: 0.85;
        }

        .achievement-badge {
            position: absolute;
            top: 10px;
            right: 10px;
            background: rgba(0,0,0,0.1);
            color: var(--gray-dark);
            font-size: 0.8rem;
            padding: 0.25rem 0.75rem;
            border-radius: 20px;
            font-weight: 600;
        }

        .achievement-icon {
            width: 80px;
            height: 80px;
            border-radius: var(--radius-lg);
            background: var(--primary);
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 2rem;
            margin: 0 auto 1rem;
            box-shadow: var(--shadow-md);
        }

        .achievement-card.unlocked .achievement-icon {
            background: linear-gradient(135deg, var(--success), #059669);
            animation: pulse 2s infinite;
        }

        @keyframes pulse {
            0%, 100% { box-shadow: 0 0 0 0 rgba(16, 185, 129, 0.4); }
            70% { box-shadow: 0 0 0 15px rgba(16, 185, 129, 0); }
        }

        .achievement-title {
            font-size: 1.25rem;
            font-weight: 700;
            text-align: center;
            margin-bottom: 0.75rem;
            color: var(--dark);
        }

        .achievement-description {
            text-align: center;
            color: var(--secondary);
            font-size: 0.95rem;
            margin-bottom: 1rem;
            line-height: 1.5;
        }

        .achievement-points {
            text-align: center;
            font-weight: 700;
            color: var(--warning);
            font-size: 1.1rem;
            margin: 1rem 0;
        }

        .achievement-progress {
            margin-top: 1rem;
        }

        .progress-text {
            display: flex;
            justify-content: space-between;
            font-size: 0.9rem;
            margin-bottom: 0.5rem;
            color: var(--secondary);
        }

        .progress-bar {
            height: 10px;
            background: var(--gray);
            border-radius: 5px;
            overflow: hidden;
        }

        .progress-fill {
            height: 100%;
            background: linear-gradient(90deg, var(--primary), #7c3aed);
            border-radius: 5px;
            width: 0;
            transition: width 1.2s ease-out;
        }

        .achievement-date, .achievement-locked {
            text-align: center;
            margin-top: 1rem;
            font-size: 0.9rem;
            font-weight: 500;
            padding: 0.75rem;
            border-radius: var(--radius-md);
        }

        .achievement-date {
            background: var(--success-light);
            color: #065f46;
        }

        .achievement-locked {
            background: #fef3c7;
            color: #92400e;
        }

        /* Empty State */
        .empty-state {
            grid-column: 1 / -1;
            text-align: center;
            padding: 4rem 2rem;
            color: var(--secondary);
        }

        .empty-state i {
            font-size: 5rem;
            margin-bottom: 1.5rem;
            opacity: 0.4;
        }

        .empty-state p {
            font-size: 1.5rem;
            margin-bottom: 2rem;
        }

        /* Responsive */
        @media (max-width: 992px) {
            .main-content { margin-left: 0; padding-top: 80px; }
            .progress-overview-card { flex-direction: column; text-align: center; }
            .achievements-grid { grid-template-columns: 1fr; }
        }

        @media (max-width: 768px) {
            .page-header h1 { font-size: 2rem; }
            .total-points-number { font-size: 3.5rem; }
        }
    </style>
</head>
<body>
    <!-- Mobile Toggle & Sidebar (UNCHANGED) -->
    <button class="mobile-toggle" id="sidebarToggle"><i class="fas fa-bars"></i></button>

    <div class="dashboard-wrapper">
        <!-- SIDEBAR (EXACTLY AS ORIGINAL - NO CHANGES) -->
        <aside class="sidebar" id="sidebar">
            <!-- ... [entire original sidebar HTML unchanged] ... -->
            <div class="sidebar-header">
                <div class="logo"><i class="fas fa-star"></i> <span>ProgressMate</span></div>
                <button class="sidebar-close" id="sidebarClose"><i class="fas fa-times"></i></button>
            </div>
            <div class="user-profile">
                <?php if (!empty($_SESSION['profile_picture'])): ?>
                    <img src="<?php echo '../' . htmlspecialchars($_SESSION['profile_picture']); ?>" alt="Profile" class="profile-pic">
                <?php else: ?>
                    <div class="profile-pic default"><?php echo strtoupper(substr($_SESSION['name'] ?? 'U', 0, 1)); ?></div>
                <?php endif; ?>
                <div class="user-info">
                    <h4><?php echo htmlspecialchars($_SESSION['name'] ?? 'User'); ?></h4>
                    <p><?php echo htmlspecialchars($_SESSION['email'] ?? 'No email'); ?></p>
                    <span style="font-size: 11px; background: #e0e7ff; color: #4f46e5; padding: 2px 8px; border-radius: 12px;">STUDENT</span>
                </div>
            </div>
            <nav class="nav-menu">
                <a href="dashboard.php" class="nav-link"><i class="fas fa-tachometer-alt"></i> <span>Dashboard</span></a>
                <a href="goals.php" class="nav-link"><i class="fas fa-bullseye"></i> <span>My Goals</span><?php if ($total_goals > 0): ?><span class="badge"><?php echo $total_goals; ?></span><?php endif; ?></a>
                <a href="create_goal.php" class="nav-link"><i class="fas fa-plus-circle"></i> <span>Create Goal</span></a>
                <a href="achievements.php" class="nav-link active"><i class="fas fa-trophy"></i> <span>Achievements</span><?php if ($total_points > 0): ?><span class="badge"><?php echo $total_points; ?> pts</span><?php endif; ?></a>
                <a href="notifications.php" class="nav-link"><i class="fas fa-bell"></i> <span>Notifications</span><?php if ($unread > 0): ?><span class="badge"><?php echo $unread; ?></span><?php endif; ?></a>
                <a href="profile.php" class="nav-link"><i class="fas fa-user"></i> <span>Profile</span></a>
            </nav>
            <div class="sidebar-quick-stats">
                <div class="sidebar-stat"><div class="sidebar-stat-icon"><i class="fas fa-bullseye"></i></div><div class="sidebar-stat-info"><div class="sidebar-stat-label">Goals</div><div class="sidebar-stat-number"><?php echo $completed_goals; ?>/<?php echo $total_goals; ?></div></div></div>
                <div class="sidebar-stat"><div class="sidebar-stat-icon"><i class="fas fa-star"></i></div><div class="sidebar-stat-info"><div class="sidebar-stat-label">Points</div><div class="sidebar-stat-number"><?php echo $total_points; ?></div></div></div>
                <div class="sidebar-stat"><div class="sidebar-stat-icon"><i class="fas fa-fire"></i></div><div class="sidebar-stat-info"><div class="sidebar-stat-label">Streak</div><div class="sidebar-stat-number"><?php echo $streak; ?> days</div></div></div>
            </div>
            <div class="sidebar-footer">
                <a href="../logout.php" class="logout-btn"><i class="fas fa-sign-out-alt"></i> <span>Logout</span></a>
            </div>
        </aside>

        <!-- MAIN CONTENT - BEAUTIFULLY REDESIGNED -->
        <main class="main-content">
            <header class="page-header">
                <div>
                    <h1>My Achievements</h1>
                    <p>Track your progress, unlock badges, and celebrate your success!</p>
                </div>
                <a href="goals.php" class="btn btn-outline"><i class="fas fa-arrow-left"></i> Back to Goals</a>
            </header>

            <?php if ($success): ?>
                <div class="alert alert-success"><i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($success); ?></div>
            <?php endif; ?>
            <?php if ($error): ?>
                <div class="alert alert-error"><i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($error); ?></div>
            <?php endif; ?>

            <!-- Total Points Hero -->
            <div class="total-points-hero">
                <div class="total-points-number"><?php echo number_format($total_points); ?></div>
                <div class="total-points-label">Total Points Earned</div>
            </div>

            <!-- Progress Overview -->
            <?php if (!empty($achievements)): ?>
            <div class="progress-overview-card">
                <div class="progress-circle">
                    <svg><circle class="circle-bg" cx="80" cy="80" r="70"></circle><circle class="circle-progress" cx="80" cy="80" r="70" style="stroke-dashoffset: <?php echo 440 - (440 * $unlocked_percentage / 100); ?>;"></circle></svg>
                    <div class="circle-text">
                        <div class="circle-percentage"><?php echo $unlocked_percentage; ?>%</div>
                        <div class="circle-label">Unlocked</div>
                    </div>
                </div>
                <div class="progress-stats">
                    <div>
                        <div class="progress-stat-label">Achievements Unlocked</div>
                        <div class="progress-stat-value"><?php echo $unlocked_count; ?> / <?php echo $total_count; ?></div>
                    </div>
                    <div>
                        <div class="progress-stat-label">Points Earned</div>
                        <div class="progress-stat-value"><?php echo number_format($unlocked_points); ?> / <?php echo number_format($total_possible_points); ?></div>
                    </div>
                    <div>
                        <div class="progress-stat-label">Completion Rate</div>
                        <div class="progress-stat-value"><?php echo $points_percentage; ?>%</div>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <!-- Achievements Grid -->
            <div class="achievements-grid">
                <?php if (empty($achievements)): ?>
                    <div class="empty-state">
                        <i class="fas fa-trophy"></i>
                        <p>No achievements available yet</p>
                        <p>Start completing goals to unlock badges and earn points!</p>
                        <div style="margin-top: 1.5rem;">
                            <a href="goals.php" class="btn btn-primary"><i class="fas fa-bullseye"></i> View Goals</a>
                            <a href="create_goal.php" class="btn btn-outline" style="margin-left: 1rem;"><i class="fas fa-plus-circle"></i> Create Goal</a>
                        </div>
                    </div>
                <?php else: ?>
                    <?php $index = 1; foreach ($achievements as $achievement): 
                        $is_unlocked = !empty($achievement['earned_at']);
                        $progress = $achievement['progress'] ?? 0;
                        $color = $achievement['color'] ?? '#6366f1';
                    ?>
                        <div class="achievement-card <?php echo $is_unlocked ? 'unlocked' : 'locked'; ?>">
                            <div class="achievement-badge">#<?php echo $index++; ?></div>
                            <div class="achievement-icon" style="background: <?php echo $color; ?>;">
                                <i class="fas fa-<?php echo htmlspecialchars($achievement['icon'] ?? 'trophy'); ?>"></i>
                            </div>
                            <div class="achievement-title"><?php echo htmlspecialchars($achievement['title']); ?></div>
                            <div class="achievement-description"><?php echo htmlspecialchars($achievement['description']); ?></div>
                            <div class="achievement-points"><i class="fas fa-star"></i> +<?php echo $achievement['points']; ?> points</div>

                            <?php if (!$is_unlocked): ?>
                                <div class="achievement-progress">
                                    <div class="progress-text">
                                        <span>Progress: <?php echo $progress; ?>%</span>
                                        <span><?php echo htmlspecialchars($achievement['criteria_type'] . ' ' . $achievement['criteria_value']); ?></span>
                                    </div>
                                    <div class="progress-bar">
                                        <div class="progress-fill" style="width: <?php echo $progress; ?>%;"></div>
                                    </div>
                                </div>
                                <div class="achievement-locked">
                                    <i class="fas fa-lock"></i> Keep going!
                                </div>
                            <?php else: ?>
                                <div class="achievement-date">
                                    <i class="fas fa-unlock"></i> Earned on <?php echo date('M d, Y', strtotime($achievement['earned_at'])); ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </main>
    </div>

    <script>
        // Mobile sidebar (unchanged)
        const sidebarToggle = document.getElementById('sidebarToggle');
        const sidebar = document.getElementById('sidebar');
        const sidebarClose = document.getElementById('sidebarClose');

        sidebarToggle?.addEventListener('click', () => sidebar.classList.add('active'));
        sidebarClose?.addEventListener('click', () => sidebar.classList.remove('active'));
        document.addEventListener('click', (e) => {
            if (window.innerWidth <= 992 && sidebar.classList.contains('active') && !sidebar.contains(e.target) && !sidebarToggle.contains(e.target)) {
                sidebar.classList.remove('active');
            }
        });

        // Animate progress fills and circle
        document.addEventListener('DOMContentLoaded', () => {
            document.querySelectorAll('.progress-fill').forEach(bar => {
                setTimeout(() => bar.style.width = bar.style.width, 300);
            });

            document.querySelectorAll('.circle-progress').forEach(circle => {
                const offset = circle.style.strokeDashoffset;
                circle.style.strokeDashoffset = 440;
                setTimeout(() => circle.style.strokeDashoffset = offset, 300);
            });
        });

        // Auto-hide alerts
        setTimeout(() => {
            document.querySelectorAll('.alert').forEach(alert => {
                alert.style.opacity = '0';
                setTimeout(() => alert.remove(), 400);
            });
        }, 6000);
    </script>
</body>
</html>