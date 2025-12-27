<?php
session_start();
require_once '../includes/db_connection.php';
checkAuth('admin');

// === Flash Messages ===
$success = $_SESSION['success'] ?? '';
$error = $_SESSION['error'] ?? '';
unset($_SESSION['success'], $_SESSION['error']);

// === Sidebar Stats ===
$total_students = $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'student'")->fetchColumn() ?: 0;
$total_goals = $pdo->query("SELECT COUNT(*) FROM admin_goals")->fetchColumn() ?: 0;
$total_points = $pdo->query("SELECT SUM(points) FROM users WHERE role = 'student'")->fetchColumn() ?: 0;

// Wrap into sidebar_stats
$sidebar_stats = [
    'students' => $total_students,
    'goals' => $total_goals,
    'assigned' => 0, // optional, calculate if needed
    'points' => $total_points
];

// === Handle Actions ===
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    try {
        if ($action === 'add_achievement' || $action === 'edit_achievement') {
            $title = trim($_POST['title'] ?? '');
            $description = trim($_POST['description'] ?? '');
            $points = (int)($_POST['points'] ?? 0);
            $criteria_type = trim($_POST['criteria_type'] ?? '');
            $criteria_value = trim($_POST['criteria_value'] ?? '');
            $icon = trim($_POST['icon'] ?? 'trophy');
            $color = $_POST['color'] ?? '#f59e0b';

            if (!$title || $points <= 0) {
                throw new Exception("Title and points are required.");
            }

            if ($action === 'add_achievement') {
                $stmt = $pdo->prepare("INSERT INTO achievements (title, description, points, criteria_type, criteria_value, icon, color, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, NOW())");
                $stmt->execute([$title, $description, $points, $criteria_type, $criteria_value, $icon, $color]);
                $success = "Achievement added.";
            } else {
                $id = (int)($_POST['achievement_id'] ?? 0);
                if ($id > 0) {
                    $stmt = $pdo->prepare("UPDATE achievements SET title=?, description=?, points=?, criteria_type=?, criteria_value=?, icon=?, color=? WHERE id=?");
                    $stmt->execute([$title, $description, $points, $criteria_type, $criteria_value, $icon, $color, $id]);
                    $success = "Achievement updated.";
                }
            }
        } elseif ($action === 'delete_achievement') {
            $id = (int)($_POST['achievement_id'] ?? 0);
            if ($id > 0) {
                $pdo->prepare("DELETE FROM achievements WHERE id=?")->execute([$id]);
                $pdo->prepare("DELETE FROM user_achievements WHERE achievement_id=?")->execute([$id]);
                $success = "Achievement deleted.";
            }
        } elseif ($action === 'recalculate_all') {
            // placeholder
            $success = "Recalculation queued (implement logic if needed).";
        }
    } catch (Exception $e) {
        $error = $e->getMessage();
    }
    header("Location: achievements.php");
    exit;
}

// === Edit Mode ===
$edit_achievement_id = null;
$edit_achievement = null;

if (isset($_GET['edit'])) {
    if ($_GET['edit'] === 'new') {
        $edit_achievement_id = 'new';
        $edit_achievement = ['id' => 'new'];
    } else {
        $edit_achievement_id = (int)$_GET['edit'];
        $stmt = $pdo->prepare("SELECT * FROM achievements WHERE id=?");
        $stmt->execute([$edit_achievement_id]);
        $edit_achievement = $stmt->fetch(PDO::FETCH_ASSOC);
    }
}

// === Fetch Achievements with unlocked count ===
$achievements = $pdo->query("
    SELECT a.*, COUNT(ua.user_id) AS unlocked_count
    FROM achievements a
    LEFT JOIN user_achievements ua ON a.id = ua.achievement_id AND ua.earned_at IS NOT NULL
    GROUP BY a.id
    ORDER BY a.created_at DESC
")->fetchAll(PDO::FETCH_ASSOC);
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
       /* ===== DASHBOARD BASE STYLES ===== */
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Inter', sans-serif;
            background: #f9fafb;
            color: #333;
            line-height: 1.5;
        }

        .dashboard-wrapper {
            display: flex;
            min-height: 100vh;
        }

        /* ===== SIDEBAR STYLES ===== */
        .sidebar {
            width: 280px;
            background: white;
            border-right: 1px solid #e5e7eb;
            display: flex;
            flex-direction: column;
            position: fixed;
            height: 100vh;
            left: 0;
            top: 0;
            z-index: 1000;
            transition: transform 0.3s ease;
        }

        @media (max-width: 768px) {
            .sidebar {
                transform: translateX(-100%);
            }
            .sidebar.active {
                transform: translateX(0);
            }
        }

        .sidebar-header {
            padding: 20px;
            border-bottom: 1px solid #e5e7eb;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .logo {
            color: #4f46e5;
            font-weight: 700;
            font-size: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .sidebar-close {
            display: none;
            background: none;
            border: none;
            color: #6b7280;
            cursor: pointer;
            font-size: 20px;
            margin-left: auto;
        }

        @media (max-width: 768px) {
            .sidebar-close {
                display: block;
            }
        }

        .user-profile {
            padding: 20px;
            border-bottom: 1px solid #e5e7eb;
            display: flex;
            align-items: center;
            gap: 15px;
        }

        .profile-pic {
            width: 50px;
            height: 50px;
            border-radius: 50%;
            object-fit: cover;
            border: 3px solid #e5e7eb;
        }

        .profile-pic.default {
            background: linear-gradient(135deg, #4f46e5, #8b5cf6);
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 20px;
            font-weight: bold;
        }

        .user-info h4 {
            margin: 0 0 5px 0;
            font-size: 16px;
            font-weight: 600;
        }

        .user-info p {
            margin: 0;
            color: #6b7280;
            font-size: 14px;
        }

        .nav-menu {
            flex: 1;
            padding: 20px 0;
            overflow-y: auto;
        }

        .nav-link {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 12px 20px;
            color: #374151;
            text-decoration: none;
            transition: all 0.2s;
            position: relative;
        }

        .nav-link:hover {
            background: #f3f4f6;
            color: #4f46e5;
        }

        .nav-link.active {
            background: #eef2ff;
            color: #4f46e5;
            border-left: 3px solid #4f46e5;
        }

        .nav-link i {
            width: 20px;
            text-align: center;
        }

        .badge {
            background: #4f46e5;
            color: white;
            padding: 2px 8px;
            border-radius: 12px;
            font-size: 12px;
            font-weight: 600;
            margin-left: auto;
        }

        .sidebar-footer {
            padding: 20px;
            border-top: 1px solid #e5e7eb;
        }

        .logout-btn {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 12px 20px;
            background: #fee2e2;
            color: #dc2626;
            border: none;
            border-radius: 8px;
            width: 100%;
            text-align: left;
            cursor: pointer;
            transition: all 0.2s;
            font-size: 14px;
            font-weight: 500;
        }

        .logout-btn:hover {
            background: #fecaca;
        }

        .sidebar-quick-stats {
            padding: 20px;
            border-top: 1px solid #e5e7eb;
        }

        .sidebar-stat {
            display: flex;
            align-items: center;
            gap: 15px;
            margin-bottom: 15px;
        }

        .sidebar-stat:last-child {
            margin-bottom: 0;
        }

        .sidebar-stat-icon {
            width: 40px;
            height: 40px;
            border-radius: 10px;
            background: #eef2ff;
            color: #4f46e5;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .sidebar-stat-info {
            flex: 1;
        }

        .sidebar-stat-label {
            font-size: 12px;
            color: #6b7280;
        }

        .sidebar-stat-number {
            font-size: 16px;
            font-weight: 600;
            color: #111827;
        }

        /* ===== MAIN CONTENT STYLES ===== */
        .main-content {
            flex: 1;
            margin-left: 280px;
            padding: 20px;
            overflow-y: auto;
        }

        @media (max-width: 768px) {
            .main-content {
                margin-left: 0;
                padding: 15px;
            }
        }

        .mobile-toggle {
            display: none;
            position: fixed;
            top: 15px;
            left: 15px;
            z-index: 999;
            background: #4f46e5;
            color: white;
            border: none;
            width: 40px;
            height: 40px;
            border-radius: 8px;
            cursor: pointer;
            align-items: center;
            justify-content: center;
        }

        @media (max-width: 768px) {
            .mobile-toggle {
                display: flex;
            }
        }

        /* ===== ADMIN DASHBOARD STYLES ===== */
        .page-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 30px;
        }

        .header-content h1 {
            margin: 0 0 10px 0;
            font-size: 28px;
            color: #111827;
        }

        .header-content p {
            margin: 0;
            color: #6b7280;
        }

        .btn {
            padding: 10px 20px;
            border-radius: 8px;
            font-weight: 500;
            cursor: pointer;
            border: none;
            transition: all 0.2s;
            font-size: 14px;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }

        .btn-primary {
            background: #4f46e5;
            color: white;
        }

        .btn-primary:hover {
            background: #4338ca;
        }

        .btn-outline {
            background: white;
            color: #4f46e5;
            border: 1px solid #4f46e5;
        }

        .btn-outline:hover {
            background: #4f46e5;
            color: white;
        }

        .btn-sm {
            padding: 6px 12px;
            font-size: 13px;
        }

        /* Stats Grid */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }

        .stat-card {
            background: white;
            border-radius: 12px;
            padding: 20px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
            border-left: 4px solid #4f46e5;
        }

        .stat-card.students { border-left-color: #3b82f6; }
        .stat-card.goals { border-left-color: #10b981; }
        .stat-card.assigned { border-left-color: #f59e0b; }
        .stat-card.points { border-left-color: #8b5cf6; }

        .stat-content {
            display: flex;
            align-items: center;
            gap: 15px;
        }

        .stat-icon {
            width: 50px;
            height: 50px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 24px;
        }

        .stat-card.students .stat-icon { background: #dbeafe; color: #3b82f6; }
        .stat-card.goals .stat-icon { background: #d1fae5; color: #10b981; }
        .stat-card.assigned .stat-icon { background: #fef3c7; color: #f59e0b; }
        .stat-card.points .stat-icon { background: #e0e7ff; color: #8b5cf6; }

        .stat-number {
            font-size: 28px;
            font-weight: 700;
            margin: 5px 0;
            color: #111827;
        }

        .stat-label {
            font-size: 14px;
            color: #6b7280;
        }

        /* Content Grid */
        .content-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(350px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }

        @media (max-width: 768px) {
            .content-grid {
                grid-template-columns: 1fr;
            }
        }

        .card {
            background: white;
            border-radius: 12px;
            overflow: hidden;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
        }

        .card-header {
            padding: 20px;
            border-bottom: 1px solid #e5e7eb;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .card-header h3 {
            margin: 0;
            font-size: 18px;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .card-body {
            padding: 20px;
        }

        /* Activity List */
        .activity-list {
            display: flex;
            flex-direction: column;
            gap: 15px;
        }

        .activity-item {
            display: flex;
            align-items: center;
            gap: 15px;
            padding: 10px;
            border-radius: 8px;
            background: #f9fafb;
        }

        .activity-icon {
            width: 40px;
            height: 40px;
            border-radius: 8px;
            background: #e0e7ff;
            color: #4f46e5;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .activity-content {
            flex: 1;
        }

        .activity-title {
            font-weight: 500;
            color: #111827;
            margin-bottom: 2px;
        }

        .activity-details {
            font-size: 12px;
            color: #6b7280;
        }

        .activity-time {
            font-size: 12px;
            color: #9ca3af;
        }

        /* Department Stats */
        .dept-stats {
            display: flex;
            flex-direction: column;
            gap: 10px;
        }

        .dept-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 10px;
            border-radius: 8px;
            background: #f9fafb;
        }

        .dept-name {
            font-weight: 500;
            color: #374151;
        }

        .dept-progress {
            display: flex;
            align-items: center;
            gap: 15px;
        }

        .progress-bar {
            width: 100px;
            height: 8px;
            background: #e5e7eb;
            border-radius: 4px;
            overflow: hidden;
        }

        .progress-fill {
            height: 100%;
            background: #4f46e5;
            border-radius: 4px;
        }

        .progress-text {
            font-size: 12px;
            color: #6b7280;
            min-width: 40px;
            text-align: right;
        }

        /* Top Students */
        .top-students-list {
            display: flex;
            flex-direction: column;
            gap: 10px;
        }

        .student-item {
            display: flex;
            align-items: center;
            gap: 15px;
            padding: 10px;
            border-radius: 8px;
            background: #f9fafb;
        }

        .student-avatar {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: linear-gradient(135deg, #4f46e5, #8b5cf6);
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: bold;
            font-size: 16px;
        }

        .student-info {
            flex: 1;
        }

        .student-name {
            font-weight: 500;
            color: #111827;
            margin-bottom: 2px;
        }

        .student-details {
            font-size: 12px;
            color: #6b7280;
        }

        .student-score {
            font-weight: 600;
            color: #10b981;
        }

        /* Quick Actions */
        .quick-actions-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 15px;
            margin-top: 20px;
        }

        .quick-action {
            padding: 20px;
            background: #f9fafb;
            border-radius: 10px;
            text-align: center;
            text-decoration: none;
            color: #374151;
            transition: all 0.2s;
            display: block;
        }

        .quick-action:hover {
            background: #e5e7eb;
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0,0,0,0.1);
        }

        .quick-action i {
            font-size: 24px;
            margin-bottom: 10px;
            display: block;
            color: #4f46e5;
        }

        .quick-action span {
            font-size: 14px;
            font-weight: 500;
        }

        /* Goal Status Distribution */
        .status-distribution {
            display: flex;
            flex-direction: column;
            gap: 10px;
        }

        .status-item {
            display: flex;
            align-items: center;
            gap: 15px;
            padding: 10px;
            border-radius: 8px;
        }

        .status-count {
            font-weight: 600;
            color: #111827;
            min-width: 30px;
        }

        .status-label {
            flex: 1;
            font-size: 14px;
        }

        .status-bar {
            width: 100px;
            height: 8px;
            background: #e5e7eb;
            border-radius: 4px;
            overflow: hidden;
        }

        .status-fill {
            height: 100%;
            border-radius: 4px;
        }

        .status-completed .status-fill { background: #10b981; }
        .status-in-progress .status-fill { background: #3b82f6; }
        .status-pending .status-fill { background: #f59e0b; }
        .status-overdue .status-fill { background: #ef4444; }

        .status-percentage {
            font-size: 12px;
            color: #6b7280;
            min-width: 40px;
            text-align: right;
        }

        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 40px 20px;
            color: #6b7280;
        }

        .empty-state i {
            font-size: 24px;
            margin-bottom: 10px;
        }

        /* Reuse styles from previous pages */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }
        
        .stat-card {
            background: white;
            border-radius: 12px;
            padding: 20px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
            text-align: center;
        }
        
        .stat-number {
            font-size: 28px;
            font-weight: 700;
            color: #111827;
        }
        
        .stat-label {
            font-size: 14px;
            color: #6b7280;
        }
        
        .filters-section {
            background: white;
            border-radius: 12px;
            padding: 20px;
            margin-bottom: 20px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
        }
        
        .filter-row {
            display: flex;
            gap: 15px;
            flex-wrap: wrap;
            align-items: flex-end;
        }
        
        .filter-group {
            flex: 1;
            min-width: 200px;
        }
        
        .report-card {
            background: white;
            border-radius: 12px;
            padding: 20px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
            margin-bottom: 30px;
        }
        
        .report-table {
            width: 100%;
            border-collapse: collapse;
        }
        
        .report-table th {
            background: #f9fafb;
            padding: 15px;
            text-align: left;
            border-bottom: 1px solid #e5e7eb;
        }
        
        .report-table td {
            padding: 15px;
            border-bottom: 1px solid #f3f4f6;
        }
        
        .student-select {
            width: 100%;
            padding: 10px;
            border: 1px solid #d1d5db;
            border-radius: 8px;
        }
        
        .student-report {
            background: white;
            border-radius: 12px;
            padding: 20px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
        }
        
        .student-info {
            margin-bottom: 20px;
        }
        
        .student-stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 15px;
            margin-bottom: 20px;
        }
        
        .student-stat {
            background: #f9fafb;
            padding: 15px;
            border-radius: 8px;
            text-align: center;
        }
        
        .student-stat .stat-number {
            font-size: 24px;
            font-weight: 700;
        }
        
        .student-stat .stat-label {
            font-size: 12px;
            color: #6b7280;
        }
         </style>
</head>
<body>
    <!-- Mobile Menu Toggle -->
    <button class="mobile-toggle" id="sidebarToggle">
        <i class="fas fa-bars"></i>
    </button>
    
    <div class="dashboard-wrapper">
        <!-- Sidebar -->
        <aside class="sidebar" id="sidebar">
            <div class="sidebar-header">
                <div class="logo">
                    <i class="fas fa-star"></i>
                    <span>ProgressMate</span>
                </div>
                <button class="sidebar-close" id="sidebarClose">
                    <i class="fas fa-times"></i>
                </button>
            </div>

            <div class="user-profile">
                <?php if (!empty($_SESSION['profile_picture'])): ?>
                    <img src="../<?php echo htmlspecialchars($_SESSION['profile_picture']); ?>" alt="Profile" class="profile-pic">
                <?php else: ?>
                    <div class="profile-pic default">
                        <?php echo strtoupper(substr($_SESSION['name'], 0, 1)); ?>
                    </div>
                <?php endif; ?>
                <div class="user-info">
                    <h4><?php echo htmlspecialchars($_SESSION['name']); ?></h4>
                    <p><?php echo htmlspecialchars($_SESSION['email']); ?></p>
                    <span style="font-size: 11px; background: #e0e7ff; color: #4f46e5; padding: 2px 8px; border-radius: 12px;">
                        ADMIN
                    </span>
                </div>
            </div>

            <nav class="nav-menu">
                <a href="admin.php" class="nav-link">
                    <i class="fas fa-tachometer-alt"></i>
                    <span>Dashboard</span>
                </a>
                <a href="students.php" class="nav-link">
                    <i class="fas fa-users"></i>
                    <span>Students</span>
                    <?php if ($sidebar_stats['students'] > 0): ?>
                        <span class="badge"><?php echo $sidebar_stats['students']; ?></span>
                    <?php endif; ?>
                </a>
                <a href="goals.php" class="nav-link">
                    <i class="fas fa-bullseye"></i>
                    <span>System Goals</span>
                    <?php if ($sidebar_stats['goals'] > 0): ?>
                        <span class="badge"><?php echo $sidebar_stats['goals']; ?></span>
                    <?php endif; ?>
                </a>
                <a href="assign_goals.php" class="nav-link">
                    <i class="fas fa-tasks"></i>
                    <span>Assign Goals</span>
                    <?php if ($sidebar_stats['assigned'] > 0): ?>
                        <span class="badge"><?php echo $sidebar_stats['assigned']; ?></span>
                    <?php endif; ?>
                </a>
                <a href="achievements.php" class="nav-link active">
                    <i class="fas fa-trophy"></i>
                    <span>Achievements</span>
                    <?php if ($sidebar_stats['points'] > 0): ?>
                        <span class="badge"><?php echo $sidebar_stats['points']; ?> pts</span>
                    <?php endif; ?>
                </a>
                <a href="reports.php" class="nav-link">
                    <i class="fas fa-chart-bar"></i>
                    <span>Reports</span>
                </a>
                <a href="notifications.php" class="nav-link">
                    <i class="fas fa-bell"></i>
                    <span>Notifications</span>
                </a>
                <a href="settings.php" class="nav-link">
                    <i class="fas fa-cog"></i>
                    <span>Settings</span>
                </a>
            </nav>

            <div class="sidebar-quick-stats">
                <div class="sidebar-stat">
                    <div class="sidebar-stat-icon">
                        <i class="fas fa-users"></i>
                    </div>
                    <div class="sidebar-stat-info">
                        <div class="sidebar-stat-label">Students</div>
                        <div class="sidebar-stat-number"><?php echo $sidebar_stats['students']; ?></div>
                    </div>
                </div>
                <div class="sidebar-stat">
                    <div class="sidebar-stat-icon">
                        <i class="fas fa-bullseye"></i>
                    </div>
                    <div class="sidebar-stat-info">
                        <div class="sidebar-stat-label">Goals</div>
                        <div class="sidebar-stat-number"><?php echo $sidebar_stats['goals']; ?></div>
                    </div>
                </div>
                <div class="sidebar-stat">
                    <div class="sidebar-stat-icon">
                        <i class="fas fa-star"></i>
                    </div>
                    <div class="sidebar-stat-info">
                        <div class="sidebar-stat-label">Points</div>
                        <div class="sidebar-stat-number"><?php echo $sidebar_stats['points']; ?></div>
                    </div>
                </div>
            </div>

            <div class="sidebar-footer">
                <a href="../logout.php" class="logout-btn">
                    <i class="fas fa-sign-out-alt"></i>
                    <span>Logout</span>
                </a>
            </div>
        </aside>
        
        <!-- Main Content -->
        <main class="main-content">
            <header class="page-header">
                <div class="header-content">
                    <h1>Achievements</h1>
                    <p>Manage badges and achievements for goals</p>
                </div>
                <a href="?edit=new" class="btn btn-primary">
                    <i class="fas fa-plus"></i> Add New Achievement
                </a>
            </header>
            
            <?php if ($success): ?>
                <div class="alert alert-success">
                    <i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($success); ?>
                </div>
            <?php endif; ?>
            
            <?php if ($error): ?>
                <div class="alert alert-error">
                    <i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($error); ?>
                </div>
            <?php endif; ?>
             <!-- Add this in your page header or as a separate card -->
<div style="margin-bottom: 20px;">
    <form method="POST" style="display: inline;">
        <input type="hidden" name="action" value="recalculate_all">
        <button type="submit" class="btn btn-outline" onclick="return confirm('This will recalculate achievements for ALL students. Continue?')">
            <i class="fas fa-sync-alt"></i> Recalculate All Achievements
        </button>
    </form>
</div>
            <!-- Achievement Form -->
             
            <div class="form-card">
                <h2><?php echo $edit_achievement_id === 'new' || $edit_achievement ? 'Add/Edit Achievement' : 'Add New Achievement'; ?></h2>
                <form method="POST">
                    <?php if ($edit_achievement_id === 'new' || !$edit_achievement): ?>
                        <input type="hidden" name="action" value="add_achievement">
                    <?php else: ?>
                        <input type="hidden" name="action" value="edit_achievement">
                        <input type="hidden" name="achievement_id" value="<?php echo $edit_achievement['id']; ?>">
                    <?php endif; ?>
                    
                    <div class="form-group">
                        <label for="title">Title</label>
                        <input type="text" id="title" name="title" required value="<?php echo htmlspecialchars($edit_achievement['title'] ?? ''); ?>">
                    </div>
                    
                    <div class="form-group">
                        <label for="description">Description</label>
                        <textarea id="description" name="description"><?php echo htmlspecialchars($edit_achievement['description'] ?? ''); ?></textarea>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label for="points">Points</label>
                            <input type="number" id="points" name="points" required value="<?php echo $edit_achievement['points'] ?? ''; ?>">
                        </div>
                        
                        <div class="form-group">
                            <label for="criteria_type">Criteria Type (e.g., goals_completed)</label>
                            <input type="text" id="criteria_type" name="criteria_type" value="<?php echo htmlspecialchars($edit_achievement['criteria_type'] ?? ''); ?>">
                        </div>
                        
                        <div class="form-group">
                            <label for="criteria_value">Criteria Value (e.g., 5)</label>
                            <input type="text" id="criteria_value" name="criteria_value" value="<?php echo htmlspecialchars($edit_achievement['criteria_value'] ?? ''); ?>">
                        </div>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label for="icon">Icon (Font Awesome name, e.g., trophy, medal, star)</label>
                            <input type="text" id="icon" name="icon" value="<?php echo htmlspecialchars($edit_achievement['icon'] ?? 'trophy'); ?>">
                        </div>
                        
                        <div class="form-group">
                            <label for="color">Color</label>
                            <input type="color" id="color" name="color" value="<?php echo htmlspecialchars($edit_achievement['color'] ?? '#f59e0b'); ?>">
                        </div>
                    </div>
                    
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-save"></i> <?php echo $edit_achievement ? 'Update Achievement' : 'Add Achievement'; ?>
                    </button>
                    <?php if ($edit_achievement_id): ?>
                        <a href="achievements.php" class="btn btn-outline">Cancel</a>
                    <?php endif; ?>
                </form>
            </div>
            
            <!-- Achievements Table -->
            <div class="achievements-table-container">
                <?php if (empty($achievements)): ?>
                    <div class="empty-state">
                        <i class="fas fa-trophy"></i>
                        <p>No achievements found</p>
                        <p>Add a new achievement to get started</p>
                    </div>
                <?php else: ?>
                    <table>
                        <thead>
                            <tr>
                                <th>Achievement</th>
                                <th>Points</th>
                                <th>Criteria</th>
                                <th>Unlocked By</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($achievements as $achievement): ?>
                                <tr>
                                    <td>
                                        <div class="achievement-badge">
                                            <div class="badge-icon" style="background: <?php echo htmlspecialchars($achievement['color'] ?? '#f59e0b'); ?>;">
                                                <i class="fas fa-<?php echo htmlspecialchars($achievement['icon'] ?? 'trophy'); ?>"></i>
                                            </div>
                                            <div>
                                                <div style="font-weight: 500;"><?php echo htmlspecialchars($achievement['title'] ?? 'Untitled'); ?></div>
                                                <div style="font-size: 12px; color: #6b7280;"><?php echo htmlspecialchars($achievement['description'] ?? ''); ?></div>
                                            </div>
                                        </div>
                                    </td>
                                    <td><?php echo $achievement['points'] ?? 0; ?></td>
                                    <td><?php echo htmlspecialchars(($achievement['criteria_type'] ?? '') . ' ' . ($achievement['criteria_value'] ?? 'N/A')); ?></td>
                                    <td><?php echo $achievement['unlocked_count']; ?></td>
                                    <td>
                                        <div class="action-buttons">
                                            <a href="?edit=<?php echo $achievement['id']; ?>" class="btn btn-sm btn-outline">
                                                <i class="fas fa-edit"></i> Edit
                                            </a>
                                            <form method="POST" style="display: inline;">
                                                <input type="hidden" name="action" value="delete_achievement">
                                                <input type="hidden" name="achievement_id" value="<?php echo $achievement['id']; ?>">
                                                <button type="submit" class="btn btn-sm btn-danger" onclick="return confirm('Are you sure you want to delete this achievement?')">
                                                    <i class="fas fa-trash"></i> Delete
                                                </button>
                                            </form>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
        </main>
    </div>
    
    <script>
        // Sidebar toggle code as previous
        const sidebarToggle = document.getElementById('sidebarToggle');
        const sidebar = document.getElementById('sidebar');
        const sidebarClose = document.getElementById('sidebarClose');
        
        if (sidebarToggle) {
            sidebarToggle.addEventListener('click', () => sidebar.classList.add('active'));
        }
        if (sidebarClose) {
            sidebarClose.addEventListener('click', () => sidebar.classList.remove('active'));
        }
        document.addEventListener('click', (event) => {
            if (window.innerWidth <= 768 && sidebar.classList.contains('active') && !sidebar.contains(event.target) && !sidebarToggle.contains(event.target)) {
                sidebar.classList.remove('active');
            }
        });
        window.addEventListener('resize', () => {
            if (window.innerWidth > 768 && sidebar.classList.contains('active')) {
                sidebar.classList.remove('active');
            }
        });
    </script>
</body>
</html>