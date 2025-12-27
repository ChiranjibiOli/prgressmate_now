<?php
require_once '../includes/db_connection.php';
checkAuth('admin');

$admin_id = $_SESSION['user_id'];

// Get system stats
$total_students = getStat($pdo, "SELECT COUNT(*) FROM users WHERE role = 'student' AND status = 'active'");
$total_goals = getStat($pdo, "SELECT COUNT(*) FROM admin_goals WHERE status = 'active'");
$total_assigned = getStat($pdo, "SELECT COUNT(*) FROM student_goals");
$completed_goals = getStat($pdo, "SELECT COUNT(*) FROM student_goals WHERE status = 'completed'");
$in_progress_goals = getStat($pdo, "SELECT COUNT(*) FROM student_goals WHERE status = 'in_progress'");
$overdue_goals = getStat($pdo, "SELECT COUNT(*) FROM student_goals WHERE status = 'overdue'");
$total_points = getStat($pdo, "SELECT COALESCE(SUM(a.points), 0) FROM user_achievements ua JOIN achievements a ON ua.achievement_id = a.id");

// Get recent activity
try {
    $activity_stmt = $pdo->prepare("
        SELECT 
            u.name as student_name,
            sg.title as goal_title,
            sg.status as goal_status,
            sg.progress_percentage,
            sg.updated_at
        FROM student_goals sg
        JOIN users u ON sg.student_id = u.id
        WHERE u.role = 'student'
        ORDER BY sg.updated_at DESC
        LIMIT 10
    ");
    $activity_stmt->execute();
    $recent_activity = $activity_stmt->fetchAll();
} catch (Exception $e) {
    $recent_activity = [];
}


// Get department statistics
try {
    $dept_stmt = $pdo->prepare("
        SELECT 
            u.department,
            COUNT(sg.id) as total_goals,
            SUM(CASE WHEN sg.status = 'completed' THEN 1 ELSE 0 END) as completed_goals,
            ROUND(AVG(sg.progress_percentage), 1) as avg_progress
        FROM users u
        LEFT JOIN student_goals sg ON u.id = sg.student_id
        WHERE u.role = 'student' AND u.department IS NOT NULL
        GROUP BY u.department
        ORDER BY avg_progress DESC
        LIMIT 5
    ");
    $dept_stmt->execute();
    $department_stats = $dept_stmt->fetchAll();
} catch (Exception $e) {
    $department_stats = [];
}

// Get top students
try {
    $top_students_stmt = $pdo->prepare("
        SELECT 
            u.id,
            u.name,
            u.email,
            u.department,
            COUNT(sg.id) as total_goals,
            SUM(CASE WHEN sg.status = 'completed' THEN 1 ELSE 0 END) as completed_goals,
            COALESCE(SUM(a.points), 0) as total_points
        FROM users u
        LEFT JOIN student_goals sg ON u.id = sg.student_id
        LEFT JOIN user_achievements ua ON u.id = ua.user_id
        LEFT JOIN achievements a ON ua.achievement_id = a.id
        WHERE u.role = 'student' AND u.status = 'active'
        GROUP BY u.id
        ORDER BY completed_goals DESC, total_points DESC
        LIMIT 5
    ");
    $top_students_stmt->execute();
    $top_students = $top_students_stmt->fetchAll();
} catch (Exception $e) {
    $top_students = [];
}

// Get admin stats for sidebar
$sidebar_stats = [
    'students' => $total_students,
    'goals' => $total_goals,
    'assigned' => $total_assigned,
    'points' => $total_points
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard - ProgressMate</title>
    <!-- <link rel="stylesheet" href="../assets/css/style.css"> -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=Roboto:wght@400;500;700&display=swap" rel="stylesheet">
    <style>
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
                <a href="admin.php" class="nav-link active">
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
                <a href="achievements.php" class="nav-link">
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
            <!-- Header -->
            <header class="page-header">
                <div class="header-content">
                    <h1>Admin Dashboard</h1>
                    <p>System overview and analytics</p>
                </div>
                <div class="header-actions">
                    <a href="reports.php" class="btn btn-outline">
                        <i class="fas fa-chart-bar"></i> View Reports
                    </a>
                </div>
            </header>
            
            <!-- Stats Overview -->
            <div class="stats-grid">
                <div class="stat-card students">
                    <div class="stat-content">
                        <div class="stat-icon">
                            <i class="fas fa-users"></i>
                        </div>
                        <div>
                            <div class="stat-number"><?php echo $total_students; ?></div>
                            <div class="stat-label">Active Students</div>
                        </div>
                    </div>
                </div>
                
                <div class="stat-card goals">
                    <div class="stat-content">
                        <div class="stat-icon">
                            <i class="fas fa-bullseye"></i>
                        </div>
                        <div>
                            <div class="stat-number"><?php echo $total_goals; ?></div>
                            <div class="stat-label">System Goals</div>
                        </div>
                    </div>
                </div>
                
                <div class="stat-card assigned">
                    <div class="stat-content">
                        <div class="stat-icon">
                            <i class="fas fa-tasks"></i>
                        </div>
                        <div>
                            <div class="stat-number"><?php echo $total_assigned; ?></div>
                            <div class="stat-label">Assigned Goals</div>
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
            
            <!-- Content Grid -->
            <div class="content-grid">
                <!-- Recent Activity -->
                <div class="card">
                    <div class="card-header">
                        <h3><i class="fas fa-history"></i> Recent Activity</h3>
                    </div>
                    <div class="card-body">
                        <div class="activity-list">
                            <?php if (!empty($recent_activity)): ?>
                                <?php foreach ($recent_activity as $activity): ?>
                                    <div class="activity-item">
                                        <div class="activity-icon">
                                            <i class="fas fa-bullseye"></i>
                                        </div>
                                        <div class="activity-content">
                                            <div class="activity-title">
                                                <?php echo htmlspecialchars($activity['student_name']); ?>
                                            </div>
                                            <div class="activity-details">
                                                <?php echo htmlspecialchars($activity['goal_title']); ?> • 
                                                <?php echo ucfirst(str_replace('_', ' ', $activity['goal_status'])); ?>
                                            </div>
                                        </div>
                                        <div class="activity-time">
                                            <?php echo date('h:i A', strtotime($activity['updated_at'])); ?>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <div class="empty-state">
                                    <i class="fas fa-history"></i>
                                    <p>No recent activity</p>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                
                <!-- Department Performance -->
                <div class="card">
                    <div class="card-header">
                        <h3><i class="fas fa-chart-line"></i> Top Departments</h3>
                    </div>
                    <div class="card-body">
                        <div class="dept-stats">
                            <?php if (!empty($department_stats)): ?>
                                <?php foreach ($department_stats as $dept): ?>
                                    <div class="dept-item">
                                        <span class="dept-name"><?php echo htmlspecialchars($dept['department']); ?></span>
                                        <div class="dept-progress">
                                            <div class="progress-bar">
                                                <div class="progress-fill" style="width: <?php echo $dept['avg_progress']; ?>%"></div>
                                            </div>
                                            <span class="progress-text"><?php echo $dept['avg_progress']; ?>%</span>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <div class="empty-state">
                                    <i class="fas fa-building"></i>
                                    <p>No department data</p>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                
                <!-- Top Students -->
                <div class="card">
                    <div class="card-header">
                        <h3><i class="fas fa-crown"></i> Top Students</h3>
                    </div>
                    <div class="card-body">
                        <div class="top-students-list">
                            <?php if (!empty($top_students)): ?>
                                <?php foreach ($top_students as $index => $student): ?>
                                    <div class="student-item">
                                        <div class="student-avatar">
                                            <?php echo strtoupper(substr($student['name'], 0, 1)); ?>
                                        </div>
                                        <div class="student-info">
                                            <div class="student-name">
                                                <?php echo htmlspecialchars($student['name']); ?>
                                                <?php if ($index < 3): ?>
                                                    <span style="font-size: 11px; background: #f59e0b; color: white; padding: 2px 6px; border-radius: 10px; margin-left: 5px;">
                                                        Top <?php echo $index + 1; ?>
                                                    </span>
                                                <?php endif; ?>
                                            </div>
                                            <div class="student-details">
                                                <?php echo htmlspecialchars($student['department']); ?>
                                            </div>
                                        </div>
                                        <div class="student-score">
                                            <?php echo $student['completed_goals']; ?>/<?php echo $student['total_goals']; ?>
                                            <div style="font-size: 11px; color: #6b7280; text-align: right;">
                                                <?php echo $student['total_points']; ?> pts
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <div class="empty-state">
                                    <i class="fas fa-user-graduate"></i>
                                    <p>No student data</p>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                
                <!-- Goal Status Distribution -->
                <div class="card">
                    <div class="card-header">
                        <h3><i class="fas fa-chart-pie"></i> Goal Status</h3>
                    </div>
                    <div class="card-body">
                        <div class="status-distribution">
                            <?php 
                            $total = $total_assigned;
                            $statuses = [
                                'completed' => $completed_goals,
                                'in_progress' => $in_progress_goals,
                                'pending' => $total_assigned - $completed_goals - $in_progress_goals - $overdue_goals,
                                'overdue' => $overdue_goals
                            ];
                            
                            foreach ($statuses as $status => $count):
                                $percentage = $total > 0 ? round(($count / $total) * 100) : 0;
                            ?>
                                <div class="status-item status-<?php echo str_replace('_', '-', $status); ?>">
                                    <span class="status-count"><?php echo $count; ?></span>
                                    <span class="status-label"><?php echo ucfirst(str_replace('_', ' ', $status)); ?></span>
                                    <div class="status-bar">
                                        <div class="status-fill" style="width: <?php echo $percentage; ?>%"></div>
                                    </div>
                                    <span class="status-percentage"><?php echo $percentage; ?>%</span>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Quick Actions -->
            <div style="margin-top: 30px;">
                <h3 style="margin-bottom: 20px; color: #111827; font-size: 18px;">Quick Actions</h3>
                <div class="quick-actions-grid">
                    <a href="students.php" class="quick-action">
                        <i class="fas fa-users"></i>
                        <span>Manage Students</span>
                    </a>
                    <a href="goals.php" class="quick-action">
                        <i class="fas fa-bullseye"></i>
                        <span>System Goals</span>
                    </a>
                    <a href="assign_goals.php" class="quick-action">
                        <i class="fas fa-tasks"></i>
                        <span>Assign Goals</span>
                    </a>
                    <a href="achievements.php" class="quick-action">
                        <i class="fas fa-trophy"></i>
                        <span>Achievements</span>
                    </a>
                    <a href="reports.php" class="quick-action">
                        <i class="fas fa-chart-bar"></i>
                        <span>Reports</span>
                    </a>
                    <a href="settings.php" class="quick-action">
                        <i class="fas fa-cog"></i>
                        <span>Settings</span>
                    </a>
                </div>
            </div>
            
            <!-- System Status -->
            <div style="background: white; border-radius: 12px; padding: 20px; box-shadow: 0 2px 10px rgba(0,0,0,0.05); margin-top: 30px;">
                <h3 style="margin: 0 0 20px 0; color: #111827; font-size: 18px;">
                    <i class="fas fa-server"></i> System Status
                </h3>
                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px;">
                    <div style="text-align: center; padding: 15px; background: #f9fafb; border-radius: 8px;">
                        <div style="font-size: 24px; font-weight: 700; color: #10b981;">100%</div>
                        <div style="font-size: 12px; color: #6b7280;">Uptime</div>
                    </div>
                    <div style="text-align: center; padding: 15px; background: #f9fafb; border-radius: 8px;">
                        <div style="font-size: 24px; font-weight: 700; color: #3b82f6;"><?php echo date('H:i'); ?></div>
                        <div style="font-size: 12px; color: #6b7280;">Current Time</div>
                    </div>
                    <div style="text-align: center; padding: 15px; background: #f9fafb; border-radius: 8px;">
                        <div style="font-size: 24px; font-weight: 700; color: #f59e0b;"><?php echo date('M d, Y'); ?></div>
                        <div style="font-size: 12px; color: #6b7280;">Today's Date</div>
                    </div>
                    <div style="text-align: center; padding: 15px; background: #f9fafb; border-radius: 8px;">
                        <div style="font-size: 24px; font-weight: 700; color: #8b5cf6;">v1.0.0</div>
                        <div style="font-size: 12px; color: #6b7280;">System Version</div>
                    </div>
                </div>
            </div>
        </main>
    </div>
    
    <!-- JavaScript -->
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
            if (window.innerWidth <= 768 && sidebar && sidebar.classList.contains('active') && !sidebar.contains(event.target) && !sidebarToggle.contains(event.target)) {
                sidebar.classList.remove('active');
            }
        });
        
        // Handle window resize
        window.addEventListener('resize', function() {
            if (window.innerWidth > 768 && sidebar && sidebar.classList.contains('active')) {
                sidebar.classList.remove('active');
            }
        });
        
        // Auto-refresh data every 60 seconds
        setInterval(() => {
            // In a real implementation, this would fetch updated stats via AJAX
            console.log('Auto-refreshing dashboard data...');
        }, 60000);

const observer = new IntersectionObserver((entries) => {
            entries.forEach(entry => {
                if (entry.isIntersecting) {
                    entry.target.querySelectorAll('.progress-fill').forEach(bar => {
                        bar.style.width = bar.dataset.width + '%';
                    });
                }
            });
        });

        document.querySelectorAll('.card').forEach(card => {
            observer.observe(card);
            card.querySelectorAll('.progress-fill').forEach(bar => {
                bar.dataset.width = bar.style.width.replace('%', '');
                bar.style.width = '0';
            });
        });
    </script>
</body>
</html>