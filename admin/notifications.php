<?php
session_start();
require_once '../includes/db_connection.php';
checkAuth('admin'); // Only admin can see

// === Flash Messages ===
$success = $_SESSION['success'] ?? '';
$error = $_SESSION['error'] ?? '';
unset($_SESSION['success'], $_SESSION['error']);

// === Sidebar Stats ===
$total_students = $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'student'")->fetchColumn() ?: 0;
$total_goals = $pdo->query("SELECT COUNT(*) FROM admin_goals")->fetchColumn() ?: 0;
$total_points = $pdo->query("SELECT SUM(points) FROM users WHERE role = 'student'")->fetchColumn() ?: 0;

$sidebar_stats = [
    'students' => $total_students,
    'goals' => $total_goals,
    'assigned' => 0, // optional placeholder
    'points' => $total_points
];

// === Fetch Notifications ===
$user_id = $_SESSION['user_id'];
$role = $_SESSION['role'];

if ($role === 'admin') {
    $stmt = $pdo->query("
        SELECT n.*, u.name AS student_name
        FROM notifications n
        LEFT JOIN users u ON n.user_id = u.id
        ORDER BY n.created_at DESC
    ");
} else {
    $stmt = $pdo->prepare("
        SELECT *
        FROM notifications
        WHERE user_id = ?
        ORDER BY created_at DESC
    ");
    $stmt->execute([$user_id]);
}

$notifications = $stmt->fetchAll(PDO::FETCH_ASSOC);

// === Safe Defaults for Display ===
foreach ($notifications as &$n) {
    $n['target_type'] = $n['target_type'] ?? 'N/A';
    $n['recipients']  = $n['recipients'] ?? 0;
    $n['read_count']  = $n['read_count'] ?? 0;
}
unset($n);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Notifications - ProgressMate</title>
    <!-- <link rel="stylesheet" href="../assets/css/style.css"> -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=Roboto:wght@400;500;700&display=swap" rel="stylesheet">
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
                <a href="notifications.php" class="nav-link active">
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
                    <h1>Notifications</h1>
                    <p>Send and manage notifications to students</p>
                </div>
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
            
            <!-- Send Notification Form -->
            <div class="form-card">
                <h3 style="margin-bottom: 20px; font-size: 18px;">Send New Notification</h3>
                <form method="POST">
                    <input type="hidden" name="action" value="send_notification">
                    
                    <div class="form-group">
                        <label for="title">Title</label>
                        <input type="text" id="title" name="title" required placeholder="Enter notification title">
                    </div>
                    
                    <div class="form-group">
                        <label for="message">Message</label>
                        <textarea id="message" name="message" required placeholder="Enter your message here"></textarea>
                    </div>
                    
                    <div class="form-group">
                        <label for="target_type">Send To</label>
                        <select id="target_type" name="target_type" onchange="toggleStudentSelect(this)">
                            <option value="all">All Active Students</option>
                            <option value="student">Specific Student</option>
                        </select>
                    </div>
                    
                    <div class="form-group" id="student_select_group" style="display: none;">
                        <label for="target_id">Select Student</label>
                        <select id="target_id" name="target_id">
                            <option value="">Choose a student</option>
                            <?php foreach ($students as $student): ?>
                                <option value="<?php echo $student['id']; ?>"><?php echo htmlspecialchars($student['name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-paper-plane"></i> Send Notification
                    </button>
                </form>
            </div>
            
            <!-- Sent Notifications -->
            <div class="notifications-table-container">
                <h3 style="padding: 20px; border-bottom: 1px solid #e5e7eb; font-size: 18px;">Sent Notifications</h3>
                <?php if (empty($notifications)): ?>
                    <div class="empty-state">
                        <i class="fas fa-bell-slash"></i>
                        <p>No notifications sent yet</p>
                        <p>Use the form above to send your first notification</p>
                    </div>
                <?php else: ?>
                    <table>
                        <thead>
                            <tr>
                                <th>Title</th>
                                <th>Message</th>
                                <th>Target</th>
                                <th>Sent At</th>
                                <th>Recipients</th>
                                <th>Read</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($notifications as $notif): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($notif['title']); ?></td>
                                    <td style="max-width: 300px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;" title="<?php echo htmlspecialchars($notif['message']); ?>">
                                        <?php echo htmlspecialchars($notif['message']); ?>
                                    </td>
                                    <td class="target-type">
                                        <?php 
                                        if ($notif['target_type'] === 'all') {
                                            echo 'All Students';
                                        } elseif ($notif['target_type'] === 'student') {
                                            $stud_stmt = $pdo->prepare("SELECT name FROM users WHERE id = ?");
                                            $stud_stmt->execute([$notif['target_id']]);
                                            $stud_name = $stud_stmt->fetchColumn() ?: 'Unknown';
                                            echo 'Student: ' . htmlspecialchars($stud_name);
                                        }
                                        ?>
                                    </td>
                                    <td><?php echo date('M d, Y h:i A', strtotime($notif['created_at'])); ?></td>
                                    <td><?php echo $notif['recipients']; ?></td>
                                    <td><?php echo $notif['read_count']; ?> (<?php echo $notif['recipients'] > 0 ? round(($notif['read_count'] / $notif['recipients']) * 100) : 0; ?>%)</td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
        </main>
    </div>
    
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
            if (window.innerWidth <= 768 && sidebar.classList.contains('active') && !sidebar.contains(event.target) && !sidebarToggle.contains(event.target)) {
                sidebar.classList.remove('active');
            }
        });
        
        // Handle window resize
        window.addEventListener('resize', function() {
            if (window.innerWidth > 768 && sidebar.classList.contains('active')) {
                sidebar.classList.remove('active');
            }
        });
        
        // Toggle student select
        function toggleStudentSelect(select) {
            const group = document.getElementById('student_select_group');
            group.style.display = select.value === 'student' ? 'block' : 'none';
        }
    </script>
</body>
</html>