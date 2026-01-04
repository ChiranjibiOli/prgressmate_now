<?php
// admin/students.php - Manage Students (CSS exactly matching admin.php)

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

// CSRF Token
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];

// === POST Action Handling ===
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $success = $error = '';
    if (!hash_equals($csrf_token, $_POST['csrf_token'] ?? '')) {
        $error = 'Invalid CSRF token.';
    } else {
        try {
            if (isset($_POST['action'], $_POST['student_id'])) {
                $student_id = (int)$_POST['student_id'];
                $action = $_POST['action'];

                $verify = $pdo->prepare("SELECT id FROM users WHERE id = ? AND role = 'student' AND deleted_at IS NULL");
                $verify->execute([$student_id]);
                if (!$verify->fetch()) {
                    $error = 'Invalid student.';
                } else {
                    switch ($action) {
                        case 'activate':
                            $pdo->prepare("UPDATE users SET status = 'active' WHERE id = ?")->execute([$student_id]);
                            $success = 'Student activated.';
                            break;
                        case 'deactivate':
                            $pdo->prepare("UPDATE users SET status = 'inactive' WHERE id = ?")->execute([$student_id]);
                            $success = 'Student deactivated.';
                            break;
                        case 'change_password':
                            if (empty($_POST['new_password']) || $_POST['new_password'] !== ($_POST['confirm_password'] ?? '')) {
                                $error = 'Passwords do not match or are empty.';
                            } elseif (strlen($_POST['new_password']) < 8) {
                                $error = 'Password must be at least 8 characters.';
                            } else {
                                $hashed = password_hash($_POST['new_password'], PASSWORD_ARGON2ID);
                                $pdo->prepare("UPDATE users SET password = ? WHERE id = ?")->execute([$hashed, $student_id]);
                                $success = 'Password changed.';
                            }
                            break;
                        case 'delete':
                            $check = $pdo->prepare("SELECT COUNT(*) FROM student_goals WHERE student_id = ? AND status IN ('pending', 'in_progress') AND deleted_at IS NULL");
                            $check->execute([$student_id]);
                            if ($check->fetchColumn() > 0) {
                                $error = 'Cannot delete: student has active/in-progress goals.';
                            } else {
                                $pdo->prepare("UPDATE users SET deleted_at = NOW(), status = 'inactive' WHERE id = ?")->execute([$student_id]);
                                $success = 'Student soft-deleted.';
                            }
                            break;
                        case 'edit_student':
                            $name = trim($_POST['name'] ?? '');
                            $email = trim($_POST['email'] ?? '');
                            if (empty($name) || empty($email)) {
                                $error = 'Name and email required.';
                            } else {
                                $email_check = $pdo->prepare("SELECT id FROM users WHERE email = ? AND id != ?");
                                $email_check->execute([$email, $student_id]);
                                if ($email_check->fetch()) {
                                    $error = 'Email already in use.';
                                } else {
                                    $pdo->prepare("UPDATE users SET name=?, email=?, department=?, semester=?, student_id=?, status=? WHERE id=?")
                                        ->execute([
                                            $name, $email,
                                            $_POST['department'] ?? null,
                                            $_POST['semester'] ?? null,
                                            $_POST['student_id_number'] ?? null,
                                            $_POST['status'] ?? 'active',
                                            $student_id
                                        ]);
                                    $success = 'Student updated.';
                                }
                            }
                            break;
                    }
                }
            } elseif ($_POST['action'] === 'bulk_action') {
                $bulk = $_POST['bulk_action'] ?? '';
                $ids = array_filter(array_map('intval', $_POST['student_ids'] ?? []));
                if (empty($ids) || !in_array($bulk, ['activate', 'deactivate'])) {
                    $error = 'Invalid bulk action.';
                } else {
                    $placeholders = implode(',', array_fill(0, count($ids), '?'));
                    $status = $bulk === 'activate' ? 'active' : 'inactive';
                    $pdo->prepare("UPDATE users SET status = ? WHERE id IN ($placeholders) AND role = 'student' AND deleted_at IS NULL")
                        ->execute(array_merge([$status], $ids));
                    $success = count($ids) . ' students updated.';
                }
            }
        } catch (Exception $e) {
            error_log('Students error: ' . $e->getMessage());
            $error = 'Operation failed.';
        }
    }
    $_SESSION['success'] = $success;
    $_SESSION['error'] = $error;
    header('Location: students.php?' . http_build_query($_GET));
    exit();
}

// Flash messages
$success = $_SESSION['success'] ?? '';
$error = $_SESSION['error'] ?? '';
unset($_SESSION['success'], $_SESSION['error']);

// Stats (same as dashboard)
$stats = [
    'total_students' => $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'student' AND deleted_at IS NULL")->fetchColumn(),
    'active_students' => $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'student' AND status = 'active' AND deleted_at IS NULL")->fetchColumn(),
    'inactive_students' => $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'student' AND status = 'inactive' AND deleted_at IS NULL")->fetchColumn(),
    'total_goals' => $pdo->query("SELECT COUNT(*) FROM student_goals sg JOIN users u ON sg.student_id = u.id WHERE u.deleted_at IS NULL AND sg.deleted_at IS NULL")->fetchColumn(),
    'total_points' => $pdo->query("SELECT COALESCE(SUM(points), 0) FROM users WHERE role = 'student' AND deleted_at IS NULL")->fetchColumn(),
];

$sidebar_stats = [
    'students' => $stats['total_students'],
    'goals' => $stats['total_goals'],
    'points' => $stats['total_points']
];

// Filters & Data
$search = trim($_GET['search'] ?? '');
$status_filter = $_GET['status'] ?? 'all';
$sort_by = $_GET['sort_by'] ?? 'name';
$sort_order = $_GET['sort_order'] ?? 'asc';

$where = ["u.role = 'student'", "u.deleted_at IS NULL"];
$params = [];

if ($search) {
    $where[] = "(u.name LIKE ? OR u.email LIKE ? OR u.student_id LIKE ?)";
    $like = "%$search%";
    $params = array_merge($params, [$like, $like, $like]);
}
if ($status_filter !== 'all') {
    $where[] = "u.status = ?";
    $params[] = $status_filter;
}

$where_clause = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$order_by = in_array($sort_by, ['name', 'points', 'created_at']) ? "u.$sort_by" : 'u.name';
$order_dir = strtoupper($sort_order) === 'DESC' ? 'DESC' : 'ASC';

$per_page = 20;
$page = max(1, (int)($_GET['page'] ?? 1));
$offset = ($page - 1) * $per_page;

$total = $pdo->prepare("SELECT COUNT(*) FROM users u $where_clause");
$total->execute($params);
$total_records = $total->fetchColumn();
$total_pages = ceil($total_records / $per_page);

$students_stmt = $pdo->prepare("
    SELECT u.*, 
           COUNT(sg.id) as total_goals,
           SUM(CASE WHEN sg.progress_percentage >= 100 THEN 1 ELSE 0 END) as completed_goals,
           ROUND(AVG(sg.progress_percentage), 1) as avg_progress
    FROM users u
    LEFT JOIN student_goals sg ON u.id = sg.student_id AND sg.deleted_at IS NULL
    $where_clause
    GROUP BY u.id
    ORDER BY $order_by $order_dir
    LIMIT ? OFFSET ?
");
$students_stmt->execute(array_merge($params, [$per_page, $offset]));
$students = $students_stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Students - ProgressMate</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        /* Exact CSS from your admin/admin.php - copied verbatim for perfect match */
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

        /* Student Progress Items */
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
            background: linear-gradient(135deg, var(--primary), var(--purple));
            color: white;
            font-weight: bold;
            font-size: 20px;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
        }

        /* Progress Bars */
        .progress-bar {
            height: 12px;
            background: var(--gray-300);
            border-radius: 6px;
            overflow: hidden;
            flex: 1;
        }
        .progress-fill {
            height: 100%;
            background: var(--primary);
            border-radius: 6px;
            width: 0;
            transition: width 1.8s ease-out;
        }
        .progress-fill.completed { background: var(--success); }

        /* Department & Status Items */
        .dept-item, .status-item {
            display: flex;
            align-items: center;
            gap: 16px;
            padding: 16px;
            background: var(--gray-100);
            border-radius: 10px;
            margin-bottom: 12px;
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

        /* Mobile */
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

        @media (max-width: 1024px) {
            .content-grid { grid-template-columns: 1fr 1fr; }
            .stats-grid { grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); }
        }

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
            .stats-grid, .content-grid { grid-template-columns: 1fr; }
            .student-item { flex-direction: column; align-items: flex-start; gap: 12px; }
            .student-item > i.fa-chevron-right { align-self: flex-end; margin-top: -8px; }
        }

        @media (max-width: 480px) {
            .header-content h1 { font-size: 26px; }
        }

        /* Additional for students page */
        .table-container { background: white; border-radius: var(--radius); overflow: hidden; box-shadow: var(--shadow); overflow-x: auto; }
        table { width: 100%; border-collapse: collapse; }
        th { background: var(--gray-200); padding: 18px; text-align: left; font-weight: 600; }
        td { padding: 18px; border-bottom: 1px solid var(--gray-300); }
        tr:hover { background: var(--gray-100); }
        .action-buttons { display: flex; gap: 8px; flex-wrap: wrap; }
        .btn-sm { padding: 8px 12px; font-size: 13px; }
    </style>
</head>
<body>
    <!-- Mobile toggle and overlay -->
    <button class="mobile-toggle" id="sidebarToggle"><i class="fas fa-bars"></i></button>
    <div class="sidebar-overlay" id="sidebarOverlay"></div>

    <div class="dashboard-wrapper">
        <!-- Sidebar - exact copy from admin.php -->
        <aside class="sidebar" id="sidebar">
            <div class="sidebar-header">
                <div class="logo"><i class="fas fa-star"></i> ProgressMate</div>
                <button class="sidebar-close" id="sidebarClose"><i class="fas fa-times"></i></button>
            </div>

            <div class="user-profile">
                <?php if (!empty($_SESSION['profile_picture'])): ?>
                    <img src="../<?php echo htmlspecialchars($_SESSION['profile_picture']); ?>" alt="Profile" class="profile-pic">
                <?php else: ?>
                    <div class="profile-pic default"><?php echo strtoupper(substr($_SESSION['name'] ?? 'A', 0, 1)); ?></div>
                <?php endif; ?>
                <div class="user-info">
                    <h4><?php echo htmlspecialchars($_SESSION['name'] ?? 'Admin'); ?></h4>
                    <p><?php echo htmlspecialchars($_SESSION['email'] ?? ''); ?></p>
                    <span style="font-size: 12px; background: #e0e7ff; color: var(--primary); padding: 4px 10px; border-radius: 20px; font-weight: 600;">ADMIN</span>
                </div>
            </div>

            <nav class="nav-menu">
                <a href="admin.php" class="nav-link"><i class="fas fa-tachometer-alt"></i> Dashboard</a>
                <a href="students.php" class="nav-link active"><i class="fas fa-users"></i> Students <?php if ($sidebar_stats['students'] > 0): ?><span class="badge"><?php echo $sidebar_stats['students']; ?></span><?php endif; ?></a>
                <a href="goals.php" class="nav-link"><i class="fas fa-bullseye"></i> System Goals <?php if ($sidebar_stats['goals'] > 0): ?><span class="badge"><?php echo $sidebar_stats['goals']; ?></span><?php endif; ?></a>
                <a href="assign_goals.php" class="nav-link"><i class="fas fa-tasks"></i> Assign Goals</a>
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
                    <h1>Manage Students</h1>
                    <p>Full student management with identical design to dashboard</p>
                </div>
            </header>

            <?php if ($success): ?>
                <div class="alert-success"><i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($success); ?></div>
            <?php endif; ?>
            <?php if ($error): ?>
                <div class="alert-error"><i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($error); ?></div>
            <?php endif; ?>

            <div class="stats-grid">
                <div class="stat-card students">
                    <div class="stat-content">
                        <div class="stat-icon"><i class="fas fa-users"></i></div>
                        <div>
                            <div class="stat-number"><?php echo $stats['total_students']; ?></div>
                            <div class="stat-label">Total Students</div>
                        </div>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-content">
                        <div class="stat-icon"><i class="fas fa-user-check"></i></div>
                        <div>
                            <div class="stat-number"><?php echo $stats['active_students']; ?></div>
                            <div class="stat-label">Active</div>
                        </div>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-content">
                        <div class="stat-icon"><i class="fas fa-bullseye"></i></div>
                        <div>
                            <div class="stat-number"><?php echo $stats['total_goals']; ?></div>
                            <div class="stat-label">Total Goals</div>
                        </div>
                    </div>
                </div>
                <div class="stat-card points">
                    <div class="stat-content">
                        <div class="stat-icon"><i class="fas fa-star"></i></div>
                        <div>
                            <div class="stat-number"><?php echo $stats['total_points']; ?></div>
                            <div class="stat-label">Total Points</div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="table-container">
                <table>
                    <thead>
                        <tr>
                            <th>Student</th>
                            <th>Contact</th>
                            <th>Progress</th>
                            <th>Goals</th>
                            <th>Points</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($students as $student): ?>
                            <tr>
                                <td>
                                    <div style="display: flex; align-items: center; gap: 12px;">
                                        <?php if ($student['profile_picture']): ?>
                                            <img src="../<?php echo htmlspecialchars($student['profile_picture']); ?>" alt="" class="student-avatar">
                                        <?php else: ?>
                                            <div class="student-avatar default"><?php echo strtoupper(substr($student['name'], 0, 1)); ?></div>
                                        <?php endif; ?>
                                        <div>
                                            <div style="font-weight: 600;"><?php echo htmlspecialchars($student['name']); ?></div>
                                            <div style="font-size: 13px; color: var(--gray-500);"><?php echo htmlspecialchars($student['email']); ?></div>
                                        </div>
                                    </div>
                                </td>
                                <td><?php echo htmlspecialchars($student['department'] ?? '—'); ?> / Sem <?php echo htmlspecialchars($student['semester'] ?? '—'); ?></td>
                                <td>
                                    <div class="progress-bar">
                                        <div class="progress-fill" data-width="<?php echo $student['avg_progress'] ?? 0; ?>"></div>
                                    </div>
                                    <?php echo $student['avg_progress'] ?? 0; ?>%
                                </td>
                                <td><?php echo $student['total_goals']; ?> (<?php echo $student['completed_goals']; ?> completed)</td>
                                <td style="font-weight: 700; color: var(--success);"><?php echo $student['points'] ?? 0; ?></td>
                                <td>
                                    <span class="status-badge status-<?php echo $student['status']; ?>">
                                        <?php echo ucfirst($student['status']); ?>
                                    </span>
                                </td>
                                <td class="action-buttons">
                                    <button class="btn btn-sm btn-outline" onclick="openEditModal(<?php echo htmlspecialchars(json_encode($student)); ?>)">Edit</button>
                                    <button class="btn btn-sm btn-info" onclick="openPasswordModal(<?php echo $student['id']; ?>, '<?php echo htmlspecialchars($student['name']); ?>')">Password</button>
                                    <?php if ($student['status'] === 'active'): ?>
                                        <form method="POST" style="display:inline;">
                                            <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                                            <input type="hidden" name="student_id" value="<?php echo $student['id']; ?>">
                                            <input type="hidden" name="action" value="deactivate">
                                            <button type="submit" class="btn btn-sm btn-warning">Deactivate</button>
                                        </form>
                                    <?php else: ?>
                                        <form method="POST" style="display:inline;">
                                            <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                                            <input type="hidden" name="student_id" value="<?php echo $student['id']; ?>">
                                            <input type="hidden" name="action" value="activate">
                                            <button type="submit" class="btn btn-sm btn-success">Activate</button>
                                        </form>
                                    <?php endif; ?>
                                    <form method="POST" style="display:inline;">
                                        <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                                        <input type="hidden" name="student_id" value="<?php echo $student['id']; ?>">
                                        <input type="hidden" name="action" value="delete">
                                        <button type="submit" class="btn btn-sm btn-danger" onclick="return confirm('Soft-delete this student?')">Delete</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </main>
    </div>

    <script>
        // Sidebar toggle - exact same as admin.php
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

        // Progress bar animation - exact same as admin.php
        const observer = new IntersectionObserver((entries) => {
            entries.forEach(entry => {
                if (entry.isIntersecting) {
                    entry.target.querySelectorAll('.progress-fill').forEach(bar => {
                        bar.style.width = bar.dataset.width + '%';
                    });
                }
            });
        }, { threshold: 0.2 });

        document.querySelectorAll('.card, tr').forEach(el => observer.observe(el));
    </script>
</body>
</html>