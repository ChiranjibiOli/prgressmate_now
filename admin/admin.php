<?php
// admin/admin.php - Admin Dashboard

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once '../includes/db_connection.php';

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    header('Location: ../login.php');
    exit();
}

if (function_exists('checkAuth')) {
    checkAuth('admin');
}

$admin_id = $_SESSION['user_id'] ?? 0;

// Safe defaults
$total_students = 0;
$total_goals = 0;
$total_assigned = 0;
$total_points = 0;
$pending_count = $in_progress_count = $completed_count = $overdue_count = 0;
$department_stats = [];
$student_progress = [];
$sidebar_stats = ['students' => 0, 'goals' => 0, 'assigned' => 0, 'points' => 0];

$current_date = date('F d, Y');

try {
    $total_students = $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'student' AND status = 'active' AND deleted_at IS NULL")->fetchColumn();
    $total_goals = $pdo->query("SELECT COUNT(*) FROM admin_goals WHERE status = 'active' AND deleted_at IS NULL")->fetchColumn();
    $total_assigned = $pdo->query("SELECT COUNT(*) FROM student_goals WHERE deleted_at IS NULL")->fetchColumn();
    $total_points = $pdo->query("SELECT COALESCE(SUM(a.points), 0) FROM user_achievements ua JOIN achievements a ON ua.achievement_id = a.id JOIN users u ON ua.user_id = u.id WHERE u.role = 'student' AND u.deleted_at IS NULL")->fetchColumn();

    // Goal status counts (excluding deleted)
    $pending_count = $pdo->query("SELECT COUNT(*) FROM student_goals WHERE progress_percentage = 0 AND deleted_at IS NULL")->fetchColumn();
    $in_progress_count = $pdo->query("SELECT COUNT(*) FROM student_goals WHERE progress_percentage > 0 AND progress_percentage < 100 AND deleted_at IS NULL")->fetchColumn();
    $completed_count = $pdo->query("SELECT COUNT(*) FROM student_goals WHERE progress_percentage >= 100 AND deleted_at IS NULL")->fetchColumn();
    $overdue_count = $pdo->query("SELECT COUNT(*) FROM student_goals WHERE due_date IS NOT NULL AND due_date < CURDATE() AND progress_percentage < 100 AND deleted_at IS NULL")->fetchColumn();

    // Top departments by average progress
    $dept_stmt = $pdo->prepare("
        SELECT 
            u.department,
            COUNT(sg.id) as total_goals,
            SUM(CASE WHEN sg.progress_percentage >= 100 THEN 1 ELSE 0 END) as completed_goals,
            COALESCE(ROUND(AVG(sg.progress_percentage), 1), 0) as avg_progress
        FROM users u
        LEFT JOIN student_goals sg ON u.id = sg.student_id AND sg.deleted_at IS NULL
        WHERE u.role = 'student' AND u.status = 'active' AND u.deleted_at IS NULL AND u.department IS NOT NULL
        GROUP BY u.department
        HAVING total_goals > 0
        ORDER BY avg_progress DESC
        LIMIT 8
    ");
    $dept_stmt->execute();
    $department_stats = $dept_stmt->fetchAll();

    // Top 10 students leaderboard (removed unused u.email)
    $student_stmt = $pdo->prepare("
        SELECT 
            u.id,
            u.name,
            u.department,
            u.profile_picture,
            COUNT(sg.id) as total_goals,
            SUM(CASE WHEN sg.progress_percentage >= 100 THEN 1 ELSE 0 END) as completed_goals,
            COALESCE(ROUND(AVG(sg.progress_percentage), 1), 0) as avg_progress,
            COALESCE(SUM(a.points), 0) as total_points
        FROM users u
        LEFT JOIN student_goals sg ON u.id = sg.student_id AND sg.deleted_at IS NULL
        LEFT JOIN user_achievements ua ON u.id = ua.user_id
        LEFT JOIN achievements a ON ua.achievement_id = a.id AND a.deleted_at IS NULL
        WHERE u.role = 'student' AND u.status = 'active' AND u.deleted_at IS NULL
        GROUP BY u.id
        ORDER BY avg_progress DESC, completed_goals DESC, total_points DESC, u.name ASC
        LIMIT 10
    ");
    $student_stmt->execute();
    $student_progress = $student_stmt->fetchAll();

} catch (Exception $e) {
    error_log('Admin dashboard error: ' . $e->getMessage());
}

$sidebar_stats = [
    'students' => $total_students,
    'goals' => $total_goals,
    'assigned' => $total_assigned,
    'points' => $total_points
];

$profile_picture = $_SESSION['profile_picture'] ?? '';
$name = $_SESSION['name'] ?? 'Admin';
$email = $_SESSION['email'] ?? 'admin@progressmate.com';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard - ProgressMate</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary: #4f46e5;
            --primary-dark: #4338ca;
            --success: #10b981;
            --warning: #f59e0b;
            --danger: #ef4444;
            --info: #3b82f6;
            --purple: #8b5cf6;
            --gold: #fbbf24;
            --silver: #9ca3af;
            --bronze: #f97316;
            --gray-100: #f9fafb;
            --gray-200: #f3f4f6;
            --gray-300: #e5e7eb;
            --gray-400: #9ca3af;
            --gray-500: #6b7280;
            --gray-700: #374151;
            --gray-900: #111827;
            --shadow-sm: 0 1px 3px rgba(0,0,0,0.1);
            --shadow: 0 4px 12px rgba(0,0,0,0.08);
            --radius: 12px;
            --transition: all 0.3s ease;

            /* Light background shades for system status cards */
            --success-light: #ecfdf5;
            --info-light: #eff6ff;
            --purple-light: #f3e8ff;
        }

        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Inter', sans-serif; background: var(--gray-100); color: var(--gray-900); line-height: 1.6; }
        a { text-decoration: none; }

        .dashboard-wrapper { display: flex; min-height: 100vh; position: relative; }
        
        .sidebar {
            width: 280px;
            background: white;
            border-right: 1px solid var(--gray-300);
            position: fixed;
            height: 100vh;
            z-index: 1000;
            display: flex;
            flex-direction: column;
            transition: var(--transition);
            box-shadow: var(--shadow);
        }

        .sidebar-header { padding: 24px 20px; border-bottom: 1px solid var(--gray-300); display: flex; align-items: center; justify-content: space-between; }
        .logo { font-size: 20px; font-weight: 700; color: var(--primary); display: flex; align-items: center; gap: 10px; }
        .sidebar-close { display: none; background: none; border: none; font-size: 20px; color: var(--gray-500); cursor: pointer; }

        .user-profile { padding: 24px 20px; border-bottom: 1px solid var(--gray-300); display: flex; align-items: center; gap: 15px; }
        .profile-pic { width: 56px; height: 56px; border-radius: 50%; object-fit: cover; border: 3px solid var(--gray-300); }
        .profile-pic.default { background: linear-gradient(135deg, var(--primary), var(--purple)); color: white; font-size: 24px; font-weight: bold; display: flex; align-items: center; justify-content: center; }

        .nav-menu { flex: 1; padding: 16px 0; overflow-y: auto; }
        .nav-link { display: flex; align-items: center; gap: 12px; padding: 14px 20px; color: var(--gray-700); transition: var(--transition); }
        .nav-link:hover { background: var(--gray-200); color: var(--primary); }
        .nav-link.active { background: #eef2ff; color: var(--primary); border-left: 4px solid var(--primary); font-weight: 600; }
        .badge { margin-left: auto; background: var(--primary); color: white; padding: 4px 10px; border-radius: 20px; font-size: 12px; font-weight: 600; }

        .sidebar-quick-stats { padding: 20px; border-top: 1px solid var(--gray-300); }
        .sidebar-stat { display: flex; align-items: center; gap: 15px; margin-bottom: 16px; }
        .sidebar-stat-icon { width: 44px; height: 44px; border-radius: 10px; background: #eef2ff; color: var(--primary); display: flex; align-items: center; justify-content: center; font-size: 20px; }
        .sidebar-stat-label { font-size: 13px; color: var(--gray-500); }
        .sidebar-stat-number { font-size: 18px; font-weight: 700; }

        .sidebar-footer { padding: 20px; }
        .logout-btn { display: flex; align-items: center; gap: 12px; padding: 14px 20px; background: #fee2e2; color: #dc2626; border-radius: 10px; width: 100%; font-weight: 500; transition: var(--transition); }

        .main-content { flex: 1; margin-left: 280px; padding: 32px; transition: var(--transition); }

        .page-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 32px; flex-wrap: wrap; gap: 16px; }
        .header-content h1 { font-size: 30px; font-weight: 700; }
        .header-content p { color: var(--gray-500); margin-top: 8px; }

        .btn { padding: 12px 24px; border-radius: 10px; font-weight: 500; cursor: pointer; border: none; transition: var(--transition); display: inline-flex; align-items: center; gap: 8px; font-size: 15px; }
        .btn-outline { background: white; color: var(--primary); border: 1px solid var(--primary); }
        .btn-outline:hover { background: var(--primary); color: white; }

        .stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(260px, 1fr)); gap: 24px; margin-bottom: 40px; }
        .stat-card { background: white; border-radius: var(--radius); padding: 24px; box-shadow: var(--shadow); position: relative; overflow: hidden; }
        .stat-card::before { content: ''; position: absolute; top: 0; left: 0; width: 5px; height: 100%; }
        .stat-card:nth-child(1)::before { background: var(--info); }
        .stat-card:nth-child(2)::before { background: var(--success); }
        .stat-card:nth-child(3)::before { background: var(--warning); }
        .stat-card:nth-child(4)::before { background: var(--purple); }

        .stat-content { display: flex; align-items: center; gap: 20px; }
        .stat-icon { width: 60px; height: 60px; border-radius: 12px; display: flex; align-items: center; justify-content: center; font-size: 28px; }
        .stat-card:nth-child(1) .stat-icon { background: #dbeafe; color: var(--info); }
        .stat-card:nth-child(2) .stat-icon { background: #d1fae5; color: var(--success); }
        .stat-card:nth-child(3) .stat-icon { background: #fef3c7; color: var(--warning); }
        .stat-card:nth-child(4) .stat-icon { background: #e0e7ff; color: var(--purple); }

        .stat-number { font-size: 32px; font-weight: 800; }
        .stat-label { font-size: 15px; color: var(--gray-500); }

        .content-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(360px, 1fr)); gap: 24px; margin-bottom: 40px; }

        .card { background: white; border-radius: var(--radius); box-shadow: var(--shadow); overflow: hidden; }
        .card-header { padding: 24px; border-bottom: 1px solid var(--gray-300); display: flex; justify-content: space-between; align-items: center; }
        .card-header h3 { font-size: 19px; font-weight: 600; display: flex; align-items: center; gap: 10px; }
        .card-body { padding: 24px; }

        .student-item {
            display: flex;
            align-items: center;
            gap: 16px;
            padding: 20px;
            background: var(--gray-100);
            border-radius: 10px;
            margin-bottom: 12px;
            transition: var(--transition);
        }
        .student-item:hover { background: var(--gray-200); transform: translateY(-2px); }
        .student-avatar {
            width: 56px;
            height: 56px;
            border-radius: 50%;
            object-fit: cover;
            background: linear-gradient(135deg, var(--primary), var(--purple));
            color: white;
            font-weight: bold;
            font-size: 20px;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
        }

        .progress-bar { height: 12px; background: var(--gray-300); border-radius: 6px; overflow: hidden; flex: 1; }
        .progress-fill { height: 100%; background: var(--primary); border-radius: 6px; width: 0; transition: width 1.8s ease-out; }
        .progress-fill.completed { background: var(--success); }

        .dept-item {
            display: flex;
            align-items: center;
            gap: 16px;
            padding: 16px;
            background: var(--gray-100);
            border-radius: 10px;
            margin-bottom: 12px;
        }

        .status-item {
            display: flex;
            align-items: center;
            gap: 16px;
            padding: 16px;
            background: var(--gray-100);
            border-radius: 10px;
            margin-bottom: 12px;
        }
        .status-count { font-size: 24px; font-weight: 800; min-width: 60px; text-align: center; }
        .status-label { flex: 1; font-weight: 500; }
        .status-bar { height: 12px; background: var(--gray-300); border-radius: 6px; overflow: hidden; width: 150px; }
        .status-fill { height: 100%; border-radius: 6px; width: 0; transition: width 1.8s ease-out; }
        .status-pending .status-fill { background: var(--warning); }
        .status-in-progress .status-fill { background: var(--info); }
        .status-completed .status-fill { background: var(--success); }
        .status-overdue .status-fill { background: var(--danger); }

        .empty-state { text-align: center; padding: 60px 20px; color: var(--gray-500); }
        .empty-state i { font-size: 48px; margin-bottom: 16px; opacity: 0.5; }

        .quick-actions-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 20px; }
        .quick-action {
            padding: 24px;
            background: white;
            border-radius: var(--radius);
            text-align: center;
            color: var(--gray-700);
            box-shadow: var(--shadow-sm);
            transition: var(--transition);
        }
        .quick-action:hover { transform: translateY(-6px); box-shadow: var(--shadow); }
        .quick-action i { font-size: 32px; color: var(--primary); margin-bottom: 12px; }

        .mobile-toggle { display: none; position: fixed; top: 20px; left: 20px; z-index: 1100; background: var(--primary); color: white; border: none; width: 48px; height: 48px; border-radius: 12px; font-size: 20px; cursor: pointer; box-shadow: var(--shadow); }
        .sidebar-overlay { display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.5); z-index: 999; }

        @media (max-width: 1024px) { .content-grid { grid-template-columns: 1fr 1fr; } }
        @media (max-width: 768px) {
            .sidebar { transform: translateX(-100%); width: 300px; }
            .sidebar.active { transform: translateX(0); }
            .sidebar-overlay.active { display: block; }
            .sidebar-close { display: block; }
            .mobile-toggle { display: flex; align-items: center; justify-content: center; }
            .main-content { margin-left: 0; padding: 24px 16px; padding-top: 80px; }
            .page-header { flex-direction: column; align-items: flex-start; }
            .stats-grid, .content-grid { grid-template-columns: 1fr; }
            .student-item { flex-direction: column; align-items: flex-start; gap: 12px; }
        }
    </style>
</head>
<body>
    <button class="mobile-toggle" id="sidebarToggle"><i class="fas fa-bars"></i></button>
    <div class="sidebar-overlay" id="sidebarOverlay"></div>

    <div class="dashboard-wrapper">
        <aside class="sidebar" id="sidebar">
            <div class="sidebar-header">
                <div class="logo"><i class="fas fa-star"></i> ProgressMate</div>
                <button class="sidebar-close" id="sidebarClose"><i class="fas fa-times"></i></button>
            </div>

            <div class="user-profile">
                <?php if (!empty($profile_picture)): ?>
                    <img src="../<?php echo htmlspecialchars($profile_picture); ?>" alt="Profile" class="profile-pic">
                <?php else: ?>
                    <div class="profile-pic default"><?php echo htmlspecialchars(strtoupper(substr($name, 0, 1))); ?></div>
                <?php endif; ?>
                <div class="user-info">
                    <h4><?php echo htmlspecialchars($name); ?></h4>
                    <p><?php echo htmlspecialchars($email); ?></p>
                    <span style="font-size: 12px; background: #e0e7ff; color: var(--primary); padding: 4px 10px; border-radius: 20px; font-weight: 600;">ADMIN</span>
                </div>
            </div>

            <nav class="nav-menu">
                <a href="admin.php" class="nav-link active"><i class="fas fa-tachometer-alt"></i> Dashboard</a>
                <a href="students.php" class="nav-link"><i class="fas fa-users"></i> Students <span class="badge"><?php echo $sidebar_stats['students']; ?></span></a>
                <a href="goals.php" class="nav-link"><i class="fas fa-bullseye"></i> System Goals <span class="badge"><?php echo $sidebar_stats['goals']; ?></span></a>
                <a href="assign_goals.php" class="nav-link"><i class="fas fa-tasks"></i> Assign Goals <span class="badge"><?php echo $sidebar_stats['assigned']; ?></span></a>
                <a href="achievements.php" class="nav-link"><i class="fas fa-trophy"></i> Achievements <span class="badge"><?php echo $sidebar_stats['points']; ?> pts</span></a>
                <a href="reports.php" class="nav-link"><i class="fas fa-chart-bar"></i> Reports</a>
                <a href="categories.php" class="nav-link"><i class="fas fa-tags"></i> Categories</a>
                <a href="settings.php" class="nav-link"><i class="fas fa-cog"></i> Settings</a>
            </nav>

            <div class="sidebar-quick-stats">
                <div class="sidebar-stat">
                    <div class="sidebar-stat-icon"><i class="fas fa-users"></i></div>
                    <div><div class="sidebar-stat-label">Active Students</div><div class="sidebar-stat-number"><?php echo $sidebar_stats['students']; ?></div></div>
                </div>
                <div class="sidebar-stat">
                    <div class="sidebar-stat-icon"><i class="fas fa-bullseye"></i></div>
                    <div><div class="sidebar-stat-label">System Goals</div><div class="sidebar-stat-number"><?php echo $sidebar_stats['goals']; ?></div></div>
                </div>
                <div class="sidebar-stat">
                    <div class="sidebar-stat-icon"><i class="fas fa-star"></i></div>
                    <div><div class="sidebar-stat-label">Total Points</div><div class="sidebar-stat-number"><?php echo $sidebar_stats['points']; ?></div></div>
                </div>
            </div>

            <div class="sidebar-footer">
                <a href="../logout.php" class="logout-btn"><i class="fas fa-sign-out-alt"></i> Logout</a>
            </div>
        </aside>

        <main class="main-content">
            <header class="page-header">
                <div class="header-content">
                    <h1>Admin Dashboard</h1>
                    <p>Welcome back! Here's your system overview as of <?php echo $current_date; ?></p>
                </div>
                <a href="reports.php" class="btn btn-outline"><i class="fas fa-chart-bar"></i> Detailed Reports</a>
            </header>

            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-content">
                        <div class="stat-icon"><i class="fas fa-users"></i></div>
                        <div>
                            <div class="stat-number"><?php echo $total_students; ?></div>
                            <div class="stat-label">Active Students</div>
                        </div>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-content">
                        <div class="stat-icon"><i class="fas fa-bullseye"></i></div>
                        <div>
                            <div class="stat-number"><?php echo $total_goals; ?></div>
                            <div class="stat-label">System Goals</div>
                        </div>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-content">
                        <div class="stat-icon"><i class="fas fa-tasks"></i></div>
                        <div>
                            <div class="stat-number"><?php echo $total_assigned; ?></div>
                            <div class="stat-label">Assigned Goals</div>
                        </div>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-content">
                        <div class="stat-icon"><i class="fas fa-star"></i></div>
                        <div>
                            <div class="stat-number"><?php echo $total_points; ?></div>
                            <div class="stat-label">Total Points Earned</div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="content-grid">
                <!-- Top Students Leaderboard -->
                <div class="card">
                    <div class="card-header">
                        <h3><i class="fas fa-trophy"></i> Top Performing Students</h3>
                        <a href="students.php" style="color: var(--primary);">View All →</a>
                    </div>
                    <div class="card-body">
                        <?php if (!empty($student_progress)): ?>
                            <?php foreach ($student_progress as $index => $student): 
                                $medal = $index == 0 ? '🥇' : ($index == 1 ? '🥈' : ($index == 2 ? '🥉' : ($index + 1)));
                                $progress = $student['avg_progress'] ?? 0;
                            ?>
                                <div class="student-item">
                                    <div style="font-size: 32px;"><?php echo $medal; ?></div>
                                    <?php if (!empty($student['profile_picture'])): ?>
                                        <img src="../<?php echo htmlspecialchars($student['profile_picture']); ?>" alt="" class="student-avatar">
                                    <?php else: ?>
                                        <div class="student-avatar"><?php echo htmlspecialchars(strtoupper(substr($student['name'], 0, 1))); ?></div>
                                    <?php endif; ?>
                                    <div style="flex: 1;">
                                        <div style="font-weight: 600;"><?php echo htmlspecialchars($student['name']); ?></div>
                                        <div style="color: var(--gray-500); font-size: 14px;"><?php echo htmlspecialchars($student['department'] ?? 'No Department'); ?></div>
                                        <div style="margin-top: 8px; display: flex; gap: 20px; font-size: 14px;">
                                            <span><strong><?php echo $student['completed_goals']; ?></strong> completed</span>
                                            <span><strong><?php echo $student['total_points']; ?></strong> pts</span>
                                        </div>
                                        <div style="margin-top: 10px;">
                                            <div class="progress-bar">
                                                <div class="progress-fill <?php echo $progress >= 100 ? 'completed' : ''; ?>" style="width: <?php echo $progress; ?>%;"></div>
                                            </div>
                                            <div style="text-align: right; margin-top: 4px; font-size: 13px; color: var(--primary); font-weight: 600;"><?php echo $progress; ?>% avg progress</div>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <div class="empty-state"><i class="fas fa-users"></i><p>No student data available</p></div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Department Performance -->
                <div class="card">
                    <div class="card-header">
                        <h3><i class="fas fa-building"></i> Department Performance</h3>
                    </div>
                    <div class="card-body">
                        <?php if (!empty($department_stats)): ?>
                            <?php foreach ($department_stats as $dept): ?>
                                <div class="dept-item">
                                    <div style="flex: 1; font-weight: 600;"><?php echo htmlspecialchars($dept['department']); ?></div>
                                    <div style="width: 180px;">
                                        <div class="progress-bar">
                                            <div class="progress-fill" style="width: <?php echo $dept['avg_progress']; ?>%; background: var(--primary);"></div>
                                        </div>
                                        <div style="text-align: right; margin-top: 4px; font-size: 13px;"><?php echo $dept['avg_progress']; ?>% avg • <?php echo $dept['completed_goals']; ?>/<?php echo $dept['total_goals']; ?> completed</div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <div class="empty-state"><i class="fas fa-building"></i><p>No department data</p></div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Goal Status Overview -->
                <div class="card">
                    <div class="card-header">
                        <h3><i class="fas fa-chart-pie"></i> Goal Status Distribution</h3>
                    </div>
                    <div class="card-body">
                        <?php 
                        $total = $total_assigned;
                        $statuses = ['pending' => $pending_count, 'in_progress' => $in_progress_count, 'completed' => $completed_count, 'overdue' => $overdue_count];
                        $labels = ['Pending', 'In Progress', 'Completed', 'Overdue'];
                        $colors = ['var(--warning)', 'var(--info)', 'var(--success)', 'var(--danger)'];
                        foreach ($statuses as $key => $count):
                            $perc = $total > 0 ? round(($count / $total) * 100) : 0;
                        ?>
                            <div class="status-item status-<?php echo $key; ?>">
                                <div class="status-count"><?php echo $count; ?></div>
                                <div class="status-label"><?php echo $labels[array_search($key, array_keys($statuses))]; ?></div>
                                <div class="status-bar">
                                    <div class="status-fill" style="width: <?php echo $perc; ?>%; background: <?php echo $colors[array_search($key, array_keys($statuses))]; ?>;"></div>
                                </div>
                                <div class="status-percentage"><?php echo $perc; ?>%</div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>

            <h3 style="margin-bottom: 20px;">Quick Actions</h3>
            <div class="quick-actions-grid">
                <a href="students.php" class="quick-action"><i class="fas fa-users"></i> Manage Students</a>
                <a href="goals.php" class="quick-action"><i class="fas fa-bullseye"></i> System Goals</a>
                <a href="assign_goals.php" class="quick-action"><i class="fas fa-tasks"></i> Assign Goals</a>
                <a href="achievements.php" class="quick-action"><i class="fas fa-trophy"></i> Achievements</a>
                <a href="categories.php" class="quick-action"><i class="fas fa-tags"></i> Categories</a>
                <a href="reports.php" class="quick-action"><i class="fas fa-chart-bar"></i> Reports</a>
            </div>

            <div class="card" style="margin-top: 40px;">
                <div class="card-header"><h3><i class="fas fa-server"></i> System Status</h3></div>
                <div class="card-body">
                    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 20px;">
                        <div style="text-align: center; padding: 20px; background: var(--success-light); border-radius: 10px;">
                            <div style="font-size: 28px; font-weight: 700; color: var(--success);">100%</div>
                            <div style="font-size: 14px; color: var(--gray-500);">System Uptime</div>
                        </div>
                        <div style="text-align: center; padding: 20px; background: var(--info-light); border-radius: 10px;">
                            <div style="font-size: 28px; font-weight: 700; color: var(--info);"><?php echo $current_date; ?></div>
                            <div style="font-size: 14px; color: var(--gray-500);">Current Date</div>
                        </div>
                        <div style="text-align: center; padding: 20px; background: var(--purple-light); border-radius: 10px;">
                            <div style="font-size: 28px; font-weight: 700; color: var(--purple);">v1.0.0</div>
                            <div style="font-size: 14px; color: var(--gray-500);">Version</div>
                        </div>
                    </div>
                </div>
            </div>
        </main>
    </div>

    <script>
        const sidebar = document.getElementById('sidebar');
        const sidebarToggle = document.getElementById('sidebarToggle');
        const sidebarClose = document.getElementById('sidebarClose');
        const overlay = document.getElementById('sidebarOverlay');

        function openSidebar() { sidebar.classList.add('active'); overlay.classList.add('active'); }
        function closeSidebar() { sidebar.classList.remove('active'); overlay.classList.remove('active'); }

        sidebarToggle?.addEventListener('click', openSidebar);
        sidebarClose?.addEventListener('click', closeSidebar);
        overlay?.addEventListener('click', closeSidebar);

        // Animate progress bars when in view
        const observer = new IntersectionObserver((entries) => {
            entries.forEach(entry => {
                if (entry.isIntersecting) {
                    entry.target.querySelectorAll('.progress-fill, .status-fill').forEach(bar => {
                        const width = bar.style.width || bar.dataset.width + '%';
                        bar.style.width = '0%';
                        setTimeout(() => bar.style.width = width, 100);
                    });
                }
            });
        }, { threshold: 0.3 });

        document.querySelectorAll('.card').forEach(card => observer.observe(card));
    </script>
</body>
</html>