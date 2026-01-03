<?php
// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once '../includes/db_connection.php';

// Enforce admin authentication
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    header('Location: ../login.php');
    exit();
}

checkAuth('admin');

$admin_id = $_SESSION['user_id'] ?? 0;

// Safe defaults
$total_students = 0;
$total_goals = 0;
$total_assigned = 0;
$total_points = 0;
$recent_activity = [];
$department_stats = [];
$top_students = [];
$sidebar_stats = ['students' => 0, 'goals' => 0, 'assigned' => 0, 'points' => 0];

if (isset($pdo)) {
    try {
        $total_students = getStat($pdo, "SELECT COUNT(*) FROM users WHERE role = 'student' AND status = 'active'");
        $total_goals = getStat($pdo, "SELECT COUNT(*) FROM admin_goals WHERE status = 'active'");
        $total_assigned = getStat($pdo, "SELECT COUNT(*) FROM student_goals");
        $total_points = getStat($pdo, "SELECT COALESCE(SUM(a.points), 0) FROM user_achievements ua JOIN achievements a ON ua.achievement_id = a.id");

        // Progress-based goal status counts
        $pending_count = getStat($pdo, "SELECT COUNT(*) FROM student_goals WHERE progress_percentage = 0");
        $in_progress_count = getStat($pdo, "SELECT COUNT(*) FROM student_goals WHERE progress_percentage > 0 AND progress_percentage < 100");
        $completed_count = getStat($pdo, "SELECT COUNT(*) FROM student_goals WHERE progress_percentage >= 100");
        $overdue_count = getStat($pdo, "SELECT COUNT(*) FROM student_goals WHERE due_date IS NOT NULL AND due_date < CURDATE() AND progress_percentage < 100");

    } catch (Exception $e) {}

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
        $recent_activity = $activity_stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {}

    try {
        $dept_stmt = $pdo->prepare("
            SELECT 
                u.department,
                COUNT(sg.id) as total_goals,
                SUM(CASE WHEN sg.progress_percentage >= 100 THEN 1 ELSE 0 END) as completed_goals,
                ROUND(AVG(sg.progress_percentage), 1) as avg_progress
            FROM users u
            LEFT JOIN student_goals sg ON u.id = sg.student_id
            WHERE u.role = 'student' AND u.department IS NOT NULL
            GROUP BY u.department
            ORDER BY avg_progress DESC
            LIMIT 5
        ");
        $dept_stmt->execute();
        $department_stats = $dept_stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {}

    try {
        $top_students_stmt = $pdo->prepare("
            SELECT 
                u.id,
                u.name,
                u.email,
                u.department,
                COUNT(sg.id) as total_goals,
                SUM(CASE WHEN sg.progress_percentage >= 100 THEN 1 ELSE 0 END) as completed_goals,
                ROUND(AVG(sg.progress_percentage), 1) as avg_progress,
                COALESCE(SUM(a.points), 0) as total_points
            FROM users u
            LEFT JOIN student_goals sg ON u.id = sg.student_id
            LEFT JOIN user_achievements ua ON u.id = ua.user_id
            LEFT JOIN achievements a ON ua.achievement_id = a.id
            WHERE u.role = 'student' AND u.status = 'active'
            GROUP BY u.id
            ORDER BY avg_progress DESC, completed_goals DESC, total_points DESC
            LIMIT 5
        ");
        $top_students_stmt->execute();
        $top_students = $top_students_stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {}
}

$sidebar_stats = [
    'students' => $total_students ?? 0,
    'goals' => $total_goals ?? 0,
    'assigned' => $total_assigned ?? 0,
    'points' => $total_points ?? 0
];

$profile_picture = $_SESSION['profile_picture'] ?? '';
$name = $_SESSION['name'] ?? 'Admin User';
$email = $_SESSION['email'] ?? 'admin@progressmate.com';

function relativeTime($datetime) {
    try {
        $now = new DateTime();
        $past = new DateTime($datetime);
        $interval = $now->diff($past);
        if ($interval->days == 0) {
            if ($interval->h == 0) return $interval->i . ' min ago';
            return $interval->h . ' hr ago';
        } elseif ($interval->days == 1) return 'Yesterday';
        elseif ($interval->days < 7) return $interval->days . ' days ago';
        return $past->format('M d');
    } catch (Exception $e) {
        return 'Unknown';
    }
}
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
            --gray-100: #f9fafb;
            --gray-200: #f3f4f6;
            --gray-300: #e5e7eb;
            --gray-500: #6b7280;
            --gray-700: #374151;
            --gray-900: #111827;
            --shadow-sm: 0 1px 3px rgba(0,0,0,0.1);
            --shadow: 0 4px 12px rgba(0,0,0,0.08);
            --radius: 12px;
            --transition: all 0.3s ease;
        }

        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Inter', sans-serif; background: var(--gray-100); color: var(--gray-900); line-height: 1.6; }
        a { text-decoration: none; }

        /* Layout */
        .dashboard-wrapper { display: flex; min-height: 100vh; position: relative; }
        
        /* Sidebar */
        .sidebar {
            width: 280px;
            background: white;
            border-right: 1px solid var(--gray-300);
            position: fixed;
            height: 100vh;
            left: 0;
            top: 0;
            z-index: 1000;
            display: flex;
            flex-direction: column;
            transition: var(--transition);
            box-shadow: var(--shadow);
        }

        .sidebar-header {
            padding: 24px 20px;
            border-bottom: 1px solid var(--gray-300);
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        .logo {
            font-size: 20px;
            font-weight: 700;
            color: var(--primary);
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .sidebar-close { display: none; background: none; border: none; font-size: 20px; color: var(--gray-500); cursor: pointer; }

        .user-profile {
            padding: 24px 20px;
            border-bottom: 1px solid var(--gray-300);
            display: flex;
            align-items: center;
            gap: 15px;
        }

        .profile-pic {
            width: 56px;
            height: 56px;
            border-radius: 50%;
            object-fit: cover;
            border: 3px solid var(--gray-300);
        }

        .profile-pic.default {
            background: linear-gradient(135deg, var(--primary), var(--purple));
            color: white;
            font-size: 24px;
            font-weight: bold;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .nav-menu { flex: 1; padding: 16px 0; overflow-y: auto; }
        .nav-link {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 14px 20px;
            color: var(--gray-700);
            transition: var(--transition);
        }

        .nav-link:hover { background: var(--gray-200); color: var(--primary); }
        .nav-link.active { background: #eef2ff; color: var(--primary); border-left: 4px solid var(--primary); font-weight: 600; }

        .badge {
            margin-left: auto;
            background: var(--primary);
            color: white;
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
        }

        .sidebar-quick-stats { padding: 20px; border-top: 1px solid var(--gray-300); }
        .sidebar-stat { display: flex; align-items: center; gap: 15px; margin-bottom: 16px; }
        .sidebar-stat:last-child { margin-bottom: 0; }
        .sidebar-stat-icon {
            width: 44px;
            height: 44px;
            border-radius: 10px;
            background: #eef2ff;
            color: var(--primary);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 20px;
        }

        .sidebar-stat-label { font-size: 13px; color: var(--gray-500); }
        .sidebar-stat-number { font-size: 18px; font-weight: 700; }

        .sidebar-footer { padding: 20px; }
        .logout-btn {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 14px 20px;
            background: #fee2e2;
            color: #dc2626;
            border-radius: 10px;
            width: 100%;
            font-weight: 500;
            transition: var(--transition);
        }

        .logout-btn:hover { background: #fecaca; }

        /* Main Content */
        .main-content {
            flex: 1;
            margin-left: 280px;
            padding: 32px;
            transition: var(--transition);
        }

        .page-header {
            display: flex;
            flex-direction: row;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 32px;
            flex-wrap: wrap;
            gap: 16px;
        }

        .header-content h1 { font-size: 30px; font-weight: 700; }
        .header-content p { color: var(--gray-500); margin-top: 8px; }

        .btn {
            padding: 12px 24px;
            border-radius: 10px;
            font-weight: 500;
            cursor: pointer;
            border: none;
            transition: var(--transition);
            display: inline-flex;
            align-items: center;
            gap: 8px;
            font-size: 15px;
        }

        .btn-outline {
            background: white;
            color: var(--primary);
            border: 1px solid var(--primary);
        }

        .btn-outline:hover { background: var(--primary); color: white; }

        /* Grids */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(260px, 1fr));
            gap: 24px;
            margin-bottom: 40px;
        }

        .content-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(360px, 1fr));
            gap: 24px;
            margin-bottom: 40px;
        }

        .quick-actions-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(160px, 1fr));
            gap: 20px;
        }

        /* Cards */
        .card, .stat-card {
            background: white;
            border-radius: var(--radius);
            box-shadow: var(--shadow);
            overflow: hidden;
        }

        .stat-card {
            padding: 24px;
            border-left: 5px solid var(--primary);
        }

        .stat-card.students { border-left-color: var(--info); }
        .stat-card.goals { border-left-color: var(--success); }
        .stat-card.assigned { border-left-color: var(--warning); }
        .stat-card.points { border-left-color: var(--purple); }

        .stat-content { display: flex; align-items: center; gap: 20px; }
        .stat-icon {
            width: 60px;
            height: 60px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 28px;
        }

        .stat-card.students .stat-icon { background: #dbeafe; color: var(--info); }
        .stat-card.goals .stat-icon { background: #d1fae5; color: var(--success); }
        .stat-card.assigned .stat-icon { background: #fef3c7; color: var(--warning); }
        .stat-card.points .stat-icon { background: #e0e7ff; color: var(--purple); }

        .stat-number { font-size: 32px; font-weight: 800; }
        .stat-label { font-size: 15px; color: var(--gray-500); }

        .card-header {
            padding: 24px;
            border-bottom: 1px solid var(--gray-300);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .card-header h3 {
            font-size: 19px;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .card-body { padding: 24px; }

        /* Activity & Lists */
        .activity-item, .dept-item, .student-item, .status-item {
            display: flex;
            align-items: center;
            gap: 16px;
            padding: 16px;
            background: var(--gray-100);
            border-radius: 10px;
            margin-bottom: 12px;
            transition: var(--transition);
        }

        .activity-item:hover, .dept-item:hover, .student-item:hover { background: var(--gray-200); }

        .activity-icon, .student-avatar {
            width: 48px;
            height: 48px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
        }

        .activity-icon { background: #e0e7ff; color: var(--primary); font-size: 20px; }
        .student-avatar {
            background: linear-gradient(135deg, var(--primary), var(--purple));
            color: white;
            font-weight: bold;
            font-size: 18px;
        }

        .activity-time, .progress-text, .status-percentage { font-size: 13px; color: var(--gray-500); white-space: nowrap; }

        /* Progress Bars */
        .progress-bar, .status-bar {
            flex: 1;
            height: 10px;
            background: var(--gray-300);
            border-radius: 6px;
            overflow: hidden;
        }

        .progress-fill, .status-fill {
            height: 100%;
            background: var(--primary);
            border-radius: 6px;
            width: 0;
            transition: width 1.6s ease-out;
        }

        .status-pending .status-fill { background: var(--warning); }
        .status-in-progress .status-fill { background: var(--info); }
        .status-completed .status-fill { background: var(--success); }
        .status-overdue .status-fill { background: var(--danger); }

        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 60px 20px;
            color: var(--gray-500);
        }

        .empty-state i { font-size: 48px; margin-bottom: 16px; opacity: 0.5; }

        /* Quick Actions */
        .quick-action {
            padding: 24px;
            background: white;
            border-radius: var(--radius);
            text-align: center;
            color: var(--gray-700);
            box-shadow: var(--shadow-sm);
            transition: var(--transition);
        }

        .quick-action:hover {
            transform: translateY(-6px);
            box-shadow: var(--shadow);
        }

        .quick-action i { font-size: 32px; color: var(--primary); margin-bottom: 12px; }

        /* Mobile Toggle & Responsiveness */
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

        /* Media Queries */
        @media (max-width: 1024px) {
            .content-grid { grid-template-columns: 1fr 1fr; }
            .stats-grid { grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); }
        }

        @media (max-width: 768px) {
            .sidebar {
                transform: translateX(-100%);
                width: 300px;
                box-shadow: var(--shadow);
            }

            .sidebar.active { transform: translateX(0); }
            .sidebar-overlay.active { display: block; }

            .sidebar-close { display: block; }
            .mobile-toggle { display: flex; align-items: center; justify-content: center; }

            .main-content { margin-left: 0; padding: 24px 16px; padding-top: 80px; }

            .page-header { flex-direction: column; align-items: flex-start; }
            .stats-grid, .content-grid { grid-template-columns: 1fr; }

            .quick-actions-grid { grid-template-columns: repeat(auto-fit, minmax(140px, 1fr)); }

            .activity-item, .dept-item, .student-item, .status-item { padding: 16px; flex-direction: column; align-items: flex-start; gap: 12px; }
            .activity-time { align-self: flex-end; }
        }

        @media (max-width: 480px) {
            .header-content h1 { font-size: 26px; }
            .stat-content { flex-direction: column; text-align: center; gap: 12px; }
            .stat-icon { width: 50px; height: 50px; font-size: 24px; }
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
                    <div class="profile-pic default"><?php echo strtoupper(substr($name, 0, 1)); ?></div>
                <?php endif; ?>
                <div class="user-info">
                    <h4><?php echo htmlspecialchars($name); ?></h4>
                    <p><?php echo htmlspecialchars($email); ?></p>
                    <span style="font-size: 12px; background: #e0e7ff; color: var(--primary); padding: 4px 10px; border-radius: 20px; font-weight: 600;">ADMIN</span>
                </div>
            </div>

            <nav class="nav-menu">
                <a href="admin.php" class="nav-link active"><i class="fas fa-tachometer-alt"></i> Dashboard</a>
                <a href="students.php" class="nav-link"><i class="fas fa-users"></i> Students <?php if ($sidebar_stats['students'] > 0): ?><span class="badge"><?php echo $sidebar_stats['students']; ?></span><?php endif; ?></a>
                <a href="goals.php" class="nav-link"><i class="fas fa-bullseye"></i> System Goals <?php if ($sidebar_stats['goals'] > 0): ?><span class="badge"><?php echo $sidebar_stats['goals']; ?></span><?php endif; ?></a>
                <a href="assign_goals.php" class="nav-link"><i class="fas fa-tasks"></i> Assign Goals <?php if ($sidebar_stats['assigned'] > 0): ?><span class="badge"><?php echo $sidebar_stats['assigned']; ?></span><?php endif; ?></a>
                <a href="achievements.php" class="nav-link"><i class="fas fa-trophy"></i> Achievements <?php if ($sidebar_stats['points'] > 0): ?><span class="badge"><?php echo $sidebar_stats['points']; ?> pts</span><?php endif; ?></a>
                <a href="reports.php" class="nav-link"><i class="fas fa-chart-bar"></i> Reports</a>
                <a href="notifications.php" class="nav-link"><i class="fas fa-bell"></i> Notifications</a>
                <a href="settings.php" class="nav-link"><i class="fas fa-cog"></i> Settings</a>
            </nav>

            <div class="sidebar-quick-stats">
                <div class="sidebar-stat">
                    <div class="sidebar-stat-icon"><i class="fas fa-users"></i></div>
                    <div class="sidebar-stat-info">
                        <div class="sidebar-stat-label">Students</div>
                        <div class="sidebar-stat-number"><?php echo $sidebar_stats['students']; ?></div>
                    </div>
                </div>
                <div class="sidebar-stat">
                    <div class="sidebar-stat-icon"><i class="fas fa-bullseye"></i></div>
                    <div class="sidebar-stat-info">
                        <div class="sidebar-stat-label">Goals</div>
                        <div class="sidebar-stat-number"><?php echo $sidebar_stats['goals']; ?></div>
                    </div>
                </div>
                <div class="sidebar-stat">
                    <div class="sidebar-stat-icon"><i class="fas fa-star"></i></div>
                    <div class="sidebar-stat-info">
                        <div class="sidebar-stat-label">Points</div>
                        <div class="sidebar-stat-number"><?php echo $sidebar_stats['points']; ?></div>
                    </div>
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
                    <p>System overview and analytics</p>
                </div>
                <div class="header-actions">
                    <a href="reports.php" class="btn btn-outline"><i class="fas fa-chart-bar"></i> View Reports</a>
                </div>
            </header>

            <div class="stats-grid">
                <div class="stat-card students">
                    <div class="stat-content">
                        <div class="stat-icon"><i class="fas fa-users"></i></div>
                        <div>
                            <div class="stat-number"><?php echo $total_students ?? 0; ?></div>
                            <div class="stat-label">Active Students</div>
                        </div>
                    </div>
                </div>
                <div class="stat-card goals">
                    <div class="stat-content">
                        <div class="stat-icon"><i class="fas fa-bullseye"></i></div>
                        <div>
                            <div class="stat-number"><?php echo $total_goals ?? 0; ?></div>
                            <div class="stat-label">System Goals</div>
                        </div>
                    </div>
                </div>
                <div class="stat-card assigned">
                    <div class="stat-content">
                        <div class="stat-icon"><i class="fas fa-tasks"></i></div>
                        <div>
                            <div class="stat-number"><?php echo $total_assigned ?? 0; ?></div>
                            <div class="stat-label">Assigned Goals</div>
                        </div>
                    </div>
                </div>
                <div class="stat-card points">
                    <div class="stat-content">
                        <div class="stat-icon"><i class="fas fa-star"></i></div>
                        <div>
                            <div class="stat-number"><?php echo $total_points ?? 0; ?></div>
                            <div class="stat-label">Total Points Earned</div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="content-grid">
                <!-- Recent Activity (task-specific with student name and exact progress) -->
                <div class="card">
                    <div class="card-header"><h3><i class="fas fa-history"></i> Recent Goal Updates</h3></div>
                    <div class="card-body">
                        <?php if (!empty($recent_activity)): ?>
                            <?php foreach ($recent_activity as $activity): 
                                $status_color = match($activity['goal_status'] ?? 'pending') {
                                    'completed' => 'var(--success)',
                                    'overdue' => 'var(--danger)',
                                    default => 'var(--warning)'
                                };
                            ?>
                                <div class="activity-item">
                                    <div class="activity-icon"><i class="fas fa-bullseye"></i></div>
                                    <div style="flex: 1;">
                                        <div style="font-weight: 600; margin-bottom: 4px;">
                                            <?php echo htmlspecialchars($activity['student_name'] ?? 'Unknown'); ?> 
                                        </div>
                                        <div style="font-size: 14px; color: var(--gray-700);">
                                            Goal: "<?php echo htmlspecialchars($activity['goal_title'] ?? 'Untitled'); ?>"
                                            <span style="color: <?php echo $status_color; ?>; font-weight: 600;">
                                                • <?php echo ucfirst(str_replace('_', ' ', $activity['goal_status'] ?? 'pending')); ?>
                                            </span>
                                        </div>
                                        <div style="margin-top: 6px;">
                                            <div class="progress-bar">
                                                <div class="progress-fill" style="width: <?php echo $activity['progress_percentage'] ?? 0; ?>%; background: <?php echo ($activity['progress_percentage'] ?? 0) >= 100 ? 'var(--success)' : 'var(--info)'; ?>;"></div>
                                            </div>
                                            <span style="font-size: 13px; color: var(--gray-700);"><?php echo number_format($activity['progress_percentage'] ?? 0, 1); ?>% complete</span>
                                        </div>
                                    </div>
                                    <div class="activity-time"><?php echo relativeTime($activity['updated_at'] ?? 'now'); ?></div>
                                </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <div class="empty-state"><i class="fas fa-history"></i> <p>No recent activity yet</p></div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Top Departments (with avg progress) -->
                <div class="card">
                    <div class="card-header"><h3><i class="fas fa-chart-line"></i> Top Departments by Progress</h3></div>
                    <div class="card-body">
                        <?php if (!empty($department_stats)): ?>
                            <div style="display: flex; flex-direction: column; gap: 12px;">
                                <?php foreach ($department_stats as $dept): ?>
                                    <div class="dept-item">
                                        <div style="font-weight: 600; flex: 1;"><?php echo htmlspecialchars($dept['department'] ?? 'Unknown'); ?></div>
                                        <div style="display: flex; align-items: center; gap: 16px; width: 200px;">
                                            <div class="progress-bar">
                                                <div class="progress-fill" data-width="<?php echo $dept['avg_progress'] ?? 0; ?>"></div>
                                            </div>
                                            <span class="progress-text"><?php echo $dept['avg_progress'] ?? 0; ?>% avg</span>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <div class="empty-state"><i class="fas fa-building"></i> <p>No department data</p></div>
                            <?php endif; ?>
                    </div>
                </div>

                <!-- Top Students (with avg progress bar per student) -->
                <div class="card">
                    <div class="card-header"><h3><i class="fas fa-crown"></i> Top Students by Progress</h3></div>
                    <div class="card-body">
                        <?php if (!empty($top_students)): ?>
                            <div style="display: flex; flex-direction: column; gap: 12px;">
                                <?php foreach ($top_students as $index => $student): ?>
                                    <div class="student-item">
                                        <div class="student-avatar"><?php echo strtoupper(substr($student['name'] ?? 'U', 0, 1)); ?></div>
                                        <div style="flex: 1;">
                                            <div style="font-weight: 600;">
                                                <?php echo htmlspecialchars($student['name'] ?? 'Unknown'); ?>
                                                <?php if ($index < 3): ?>
                                                    <span style="font-size: 12px; background: var(--warning); color: white; padding: 4px 8px; border-radius: 20px; margin-left: 8px;">Top <?php echo $index + 1; ?></span>
                                                <?php endif; ?>
                                            </div>
                                            <div style="font-size: 14px; color: var(--gray-500);"><?php echo htmlspecialchars($student['department'] ?? 'N/A'); ?></div>
                                            <div style="margin-top: 8px;">
                                                <div class="progress-bar">
                                                    <div class="progress-fill" data-width="<?php echo $student['avg_progress'] ?? 0; ?>"></div>
                                                </div>
                                                <span style="font-size: 13px; color: var(--gray-700);"><?php echo $student['avg_progress'] ?? 0; ?>% average progress</span>
                                            </div>
                                        </div>
                                        <div style="text-align: right;">
                                            <div style="font-weight: 700; color: var(--success);"><?php echo $student['completed_goals'] ?? 0; ?>/<?php echo $student['total_goals'] ?? 0; ?> completed</div>
                                            <div style="font-size: 13px; color: var(--gray-500);"><?php echo $student['total_points'] ?? 0; ?> pts</div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <div class="empty-state"><i class="fas fa-user-graduate"></i> <p>No student data</p></div>
                            <?php endif; ?>
                    </div>
                </div>

                <!-- Goal Status Distribution (progress-based categories) -->
                <div class="card">
                    <div class="card-header"><h3><i class="fas fa-chart-pie"></i> Goal Progress Overview</h3></div>
                    <div class="card-body">
                        <div style="display: flex; flex-direction: column; gap: 16px;">
                            <?php 
                            $total = $total_assigned ?? 0;
                            $statuses = [
                                'pending' => $pending_count ?? 0,
                                'in_progress' => $in_progress_count ?? 0,
                                'completed' => $completed_count ?? 0,
                                'overdue' => $overdue_count ?? 0
                            ];
                            $labels = [
                                'pending' => 'Pending (0%)',
                                'in_progress' => 'In Progress (1%-99%)',
                                'completed' => 'Completed (100%)',
                                'overdue' => 'Overdue (<100% & past due)'
                            ];
                            $colors = [
                                'pending' => 'var(--warning)',
                                'in_progress' => 'var(--info)',
                                'completed' => 'var(--success)',
                                'overdue' => 'var(--danger)'
                            ];
                            foreach ($statuses as $key => $count):
                                $percentage = $total > 0 ? round(($count / $total) * 100) : 0;
                            ?>
                                <div class="status-item status-<?php echo $key; ?>">
                                    <span class="status-count"><?php echo $count; ?></span>
                                    <span class="status-label"><?php echo $labels[$key]; ?></span>
                                    <div class="status-bar">
                                        <div class="status-fill" data-width="<?php echo $percentage; ?>" style="background: <?php echo $colors[$key]; ?>;"></div>
                                    </div>
                                    <span class="status-percentage"><?php echo $percentage; ?>%</span>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            </div>

            <h3 style="font-size: 20px; margin-bottom: 20px;">Quick Actions</h3>
            <div class="quick-actions-grid">
                <a href="students.php" class="quick-action"><i class="fas fa-users"></i> <span>Manage Students</span></a>
                <a href="goals.php" class="quick-action"><i class="fas fa-bullseye"></i> <span>System Goals</span></a>
                <a href="assign_goals.php" class="quick-action"><i class="fas fa-tasks"></i> <span>Assign Goals</span></a>
                <a href="achievements.php" class="quick-action"><i class="fas fa-trophy"></i> <span>Achievements</span></a>
                <a href="reports.php" class="quick-action"><i class="fas fa-chart-bar"></i> <span>Reports</span></a>
                <a href="settings.php" class="quick-action"><i class="fas fa-cog"></i> <span>Settings</span></a>
            </div>

            <div class="card" style="margin-top: 40px;">
                <div class="card-header"><h3><i class="fas fa-server"></i> System Status</h3></div>
                <div class="card-body">
                    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 20px;">
                        <div style="text-align: center; padding: 20px; background: var(--gray-100); border-radius: 10px;">
                            <div style="font-size: 28px; font-weight: 700; color: var(--success);">100%</div>
                            <div style="font-size: 14px; color: var(--gray-500);">Uptime</div>
                        </div>
                        <div style="text-align: center; padding: 20px; background: var(--gray-100); border-radius: 10px;">
                            <div style="font-size: 28px; font-weight: 700; color: var(--info);"><?php echo date('H:i'); ?></div>
                            <div style="font-size: 14px; color: var(--gray-500);">Current Time</div>
                        </div>
                        <div style="text-align: center; padding: 20px; background: var(--gray-100); border-radius: 10px;">
                            <div style="font-size: 28px; font-weight: 700; color: var(--warning);"><?php echo date('M d, Y'); ?></div>
                            <div style="font-size: 14px; color: var(--gray-500);">Today's Date</div>
                        </div>
                        <div style="text-align: center; padding: 20px; background: var(--gray-100); border-radius: 10px;">
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

        // Progress animation on scroll
        const observer = new IntersectionObserver((entries) => {
            entries.forEach(entry => {
                if (entry.isIntersecting) {
                    entry.target.querySelectorAll('.progress-fill, .status-fill').forEach(bar => {
                        bar.style.width = bar.dataset.width + '%';
                    });
                }
            });
        }, { threshold: 0.2 });

        document.querySelectorAll('.card').forEach(card => observer.observe(card));
    </script>
</body>
</html>