<?php
// admin/achievements.php - Fully Responsive Achievement Management (All errors fixed + Secure)

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
    if (!hash_equals($csrf_token, $_POST['csrf_token'] ?? '')) {
        die('CSRF validation failed.');
    }

    $success = $error = '';
    
    try {
        $action = $_POST['action'] ?? '';
        
        if ($action === 'add_achievement' || $action === 'edit_achievement') {
            $title = trim($_POST['title'] ?? '');
            $description = trim($_POST['description'] ?? '');
            $points = max(1, (int)($_POST['points'] ?? 0));
            $criteria_type = trim($_POST['criteria_type'] ?? '');
            $criteria_value = trim($_POST['criteria_value'] ?? '');
            $allowed_icons = ['trophy', 'medal', 'star', 'award', 'certificate', 'gem'];
            $icon = in_array($_POST['icon'] ?? '', $allowed_icons) ? $_POST['icon'] : 'trophy';
            $color = preg_match('/^#[a-f0-9]{6}$/i', $_POST['color'] ?? '') ? $_POST['color'] : '#f59e0b';
            $is_active = isset($_POST['is_active']) ? 1 : 0;
            
            if (!$title) {
                throw new Exception("Title is required.");
            }
            
            if ($action === 'add_achievement') {
                $stmt = $pdo->prepare("
                    INSERT INTO achievements
                    (title, description, points, criteria_type, criteria_value, icon, color, is_active, created_at)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())
                ");
                $stmt->execute([$title, $description, $points, $criteria_type, $criteria_value, $icon, $color, $is_active]);
                $success = "Achievement added successfully!";
            } else {
                $id = (int)($_POST['achievement_id'] ?? 0);
                if ($id > 0) {
                    $stmt = $pdo->prepare("
                        UPDATE achievements
                        SET title=?, description=?, points=?, criteria_type=?, criteria_value=?,
                            icon=?, color=?, is_active=?
                        WHERE id=? AND deleted_at IS NULL
                    ");
                    $stmt->execute([$title, $description, $points, $criteria_type, $criteria_value, $icon, $color, $is_active, $id]);
                    $success = "Achievement updated successfully!";
                } else {
                    throw new Exception("Invalid achievement ID.");
                }
            }
            
        } elseif ($action === 'delete_achievement') {
            $id = (int)($_POST['achievement_id'] ?? 0);
            if ($id > 0) {
                $pdo->beginTransaction();
                $pdo->prepare("UPDATE user_achievements SET deleted_at = NOW() WHERE achievement_id=?")->execute([$id]);
                $pdo->prepare("UPDATE achievements SET deleted_at = NOW() WHERE id=?")->execute([$id]);
                $pdo->commit();
                $success = "Achievement deleted successfully!";
            } else {
                throw new Exception("Invalid achievement ID.");
            }
            
        } elseif ($action === 'bulk_action') {
            $bulk_action = $_POST['bulk_action'] ?? '';
            $achievement_ids = array_filter(array_map('intval', $_POST['achievement_ids'] ?? []));
            
            if (empty($achievement_ids)) {
                throw new Exception('Please select at least one achievement.');
            }
            
            $placeholders = implode(',', array_fill(0, count($achievement_ids), '?'));
            
            if ($bulk_action === 'activate') {
                $pdo->prepare("UPDATE achievements SET is_active = 1 WHERE id IN ($placeholders) AND deleted_at IS NULL")->execute($achievement_ids);
                $success = count($achievement_ids) . ' achievement(s) activated.';
            } elseif ($bulk_action === 'deactivate') {
                $pdo->prepare("UPDATE achievements SET is_active = 0 WHERE id IN ($placeholders) AND deleted_at IS NULL")->execute($achievement_ids);
                $success = count($achievement_ids) . ' achievement(s) deactivated.';
            } elseif ($bulk_action === 'delete') {
                $pdo->beginTransaction();
                $pdo->prepare("UPDATE user_achievements SET deleted_at = NOW() WHERE achievement_id IN ($placeholders)")->execute($achievement_ids);
                $pdo->prepare("UPDATE achievements SET deleted_at = NOW() WHERE id IN ($placeholders)")->execute($achievement_ids);
                $pdo->commit();
                $success = count($achievement_ids) . ' achievement(s) deleted.';
            }
            
        } elseif ($action === 'recalculate_all') {
            $students = $pdo->query("SELECT id FROM users WHERE role = 'student' AND status = 'active' AND deleted_at IS NULL")->fetchAll(PDO::FETCH_COLUMN);
            foreach ($students as $student_id) {
                if (function_exists('awardAchievements')) {
                    awardAchievements($pdo, $student_id);
                }
            }
            $success = 'Achievements recalculated for all students.';
        }
    } catch (Exception $e) {
        $error = $e->getMessage();
    }
    
    $_SESSION['success'] = $success;
    $_SESSION['error'] = $error;
    header("Location: achievements.php?" . http_build_query($_GET));
    exit();
}

// === Flash Messages ===
$success = $_SESSION['success'] ?? '';
$error = $_SESSION['error'] ?? '';
unset($_SESSION['success'], $_SESSION['error']);

// === Sidebar Stats ===
$total_students = $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'student' AND status = 'active' AND deleted_at IS NULL")->fetchColumn();
$total_goals = $pdo->query("SELECT COUNT(*) FROM admin_goals WHERE status = 'active' AND deleted_at IS NULL")->fetchColumn();
$total_achievements = $pdo->query("SELECT COUNT(*) FROM achievements WHERE deleted_at IS NULL")->fetchColumn();
$total_unlocked = $pdo->query("SELECT COUNT(*) FROM user_achievements WHERE deleted_at IS NULL")->fetchColumn();

// Total points distributed (sum points for each valid unlock)
$total_points = $pdo->query("
    SELECT COALESCE(SUM(a.points), 0) 
    FROM user_achievements ua 
    JOIN achievements a ON ua.achievement_id = a.id 
    WHERE ua.deleted_at IS NULL AND a.deleted_at IS NULL
")->fetchColumn();

$sidebar_stats = [
    'students' => $total_students,
    'goals' => $total_goals,
    'points' => $total_points,
    'achievements' => $total_achievements,
    'unlocked' => $total_unlocked
];

// === Edit Mode ===
$edit_achievement = null;
if (isset($_GET['edit'])) {
    if ($_GET['edit'] === 'new') {
        $edit_achievement = [
            'id' => 'new',
            'title' => '',
            'description' => '',
            'points' => 10,
            'criteria_type' => '',
            'criteria_value' => '',
            'icon' => 'trophy',
            'color' => '#f59e0b',
            'is_active' => 1
        ];
    } else {
        $id = (int)$_GET['edit'];
        $stmt = $pdo->prepare("SELECT * FROM achievements WHERE id = ? AND deleted_at IS NULL");
        $stmt->execute([$id]);
        $edit_achievement = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$edit_achievement) {
            $_SESSION['error'] = 'Achievement not found.';
            header('Location: achievements.php');
            exit();
        }
    }
}

// === Filters (ALL columns qualified with 'a.' to prevent ambiguity) ===
$search = trim($_GET['search'] ?? '');
$status_filter = $_GET['status'] ?? 'all';
$criteria_filter = $_GET['criteria'] ?? 'all';

$where = ['a.deleted_at IS NULL'];
$params = [];

if ($search) {
    $where[] = "(a.title LIKE ? OR a.description LIKE ?)";
    $like = "%$search%";
    $params[] = $like;
    $params[] = $like;
}
if ($status_filter === 'active') {
    $where[] = "a.is_active = 1";
} elseif ($status_filter === 'inactive') {
    $where[] = "a.is_active = 0";
}
if ($criteria_filter !== 'all') {
    $where[] = "a.criteria_type = ?";
    $params[] = $criteria_filter;
}

$where_clause = $where ? 'WHERE ' . implode(' AND ', $where) : '';

// === Fetch Achievements ===
$achievements_stmt = $pdo->prepare("
    SELECT a.*, COALESCE(COUNT(ua.user_id), 0) AS unlocked_count
    FROM achievements a
    LEFT JOIN user_achievements ua ON a.id = ua.achievement_id AND ua.deleted_at IS NULL
    $where_clause
    GROUP BY a.id
    ORDER BY a.created_at DESC
");
$achievements_stmt->execute($params);
$achievements = $achievements_stmt->fetchAll(PDO::FETCH_ASSOC);

// === Top Students Leaderboard ===
$top_students_stmt = $pdo->prepare("
    SELECT 
        u.name, 
        u.profile_picture, 
        COUNT(ua.id) as achievements_count, 
        COALESCE(SUM(a.points), 0) as points_earned
    FROM users u
    JOIN user_achievements ua ON u.id = ua.user_id AND ua.deleted_at IS NULL
    JOIN achievements a ON ua.achievement_id = a.id AND a.deleted_at IS NULL
    WHERE u.role = 'student' AND u.status = 'active' AND u.deleted_at IS NULL
    GROUP BY u.id
    ORDER BY achievements_count DESC, points_earned DESC, u.name ASC
    LIMIT 5
");
$top_students_stmt->execute();
$top_students = $top_students_stmt->fetchAll();
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

            --success-light: #ecfdf5;
            --danger-light: #fee2e2;
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

        .btn { padding: 12px 24px; border-radius: 10px; font-weight: 500; cursor: pointer; border: none; transition: var(--transition); display: inline-flex; align-items: center; gap: 8px; font-size: 15px; background: var(--primary); color: white; }
        .btn:hover { background: var(--primary-dark); }
        .btn-outline { background: white; color: var(--primary); border: 1px solid var(--primary); }
        .btn-outline:hover { background: var(--primary); color: white; }
        .btn-danger { background: var(--danger); color: white; }
        .btn-sm { padding: 8px 16px; font-size: 14px; }

        .alert { padding: 16px 24px; border-radius: var(--radius); margin-bottom: 24px; display: flex; align-items: center; gap: 12px; font-weight: 500; }
        .alert-success { background: var(--success-light); color: var(--success); border: 1px solid var(--success); }
        .alert-error { background: var(--danger-light); color: var(--danger); border: 1px solid var(--danger); }

        .stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(260px, 1fr)); gap: 24px; margin-bottom: 40px; }
        .stat-card { background: white; border-radius: var(--radius); padding: 24px; box-shadow: var(--shadow); position: relative; overflow: hidden; }
        .stat-card::before { content: ''; position: absolute; top: 0; left: 0; width: 5px; height: 100%; }
        .stat-card:nth-child(1)::before { background: var(--gold); }
        .stat-card:nth-child(2)::before { background: var(--success); }
        .stat-card:nth-child(3)::before { background: var(--purple); }

        .stat-icon { width: 60px; height: 60px; border-radius: 12px; display: flex; align-items: center; justify-content: center; font-size: 28px; }
        .stat-card:nth-child(1) .stat-icon { background: #fffbeb; color: var(--gold); }
        .stat-card:nth-child(2) .stat-icon { background: var(--success-light); color: var(--success); }
        .stat-card:nth-child(3) .stat-icon { background: #e0e7ff; color: var(--purple); }

        .stat-number { font-size: 32px; font-weight: 800; }
        .stat-label { font-size: 15px; color: var(--gray-500); }

        .content-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(360px, 1fr)); gap: 24px; margin-bottom: 40px; }

        .card { background: white; border-radius: var(--radius); box-shadow: var(--shadow); overflow: hidden; }
        .card-header { padding: 24px; border-bottom: 1px solid var(--gray-300); display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 16px; }
        .card-header h3 { font-size: 19px; font-weight: 600; display: flex; align-items: center; gap: 10px; }
        .card-body { padding: 24px; }

        .achievement-item {
            display: flex;
            align-items: center;
            gap: 20px;
            padding: 20px;
            background: var(--gray-100);
            border-radius: 10px;
            margin-bottom: 12px;
            transition: var(--transition);
        }
        .achievement-item:hover { background: var(--gray-200); transform: translateY(-2px); }

        .badge-preview {
            width: 80px;
            height: 80px;
            border-radius: var(--radius);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 36px;
            color: white;
            box-shadow: var(--shadow);
            flex-shrink: 0;
        }

        .achievement-info { flex: 1; }
        .achievement-stats { display: flex; align-items: center; gap: 20px; margin-top: 12px; font-size: 14px; }
        .achievement-unlocked { font-size: 20px; font-weight: 800; color: var(--primary); }

        .status-badge {
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 13px;
            font-weight: 600;
        }
        .status-active { background: var(--success-light); color: var(--success); }
        .status-inactive { background: var(--gray-300); color: var(--gray-700); }

        .action-buttons { display: flex; gap: 8px; }

        /* Form Styles */
        .form-row { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-bottom: 20px; }
        .form-group { display: flex; flex-direction: column; }
        .form-group label { margin-bottom: 8px; font-weight: 500; }
        .form-group input, .form-group select, .form-group textarea {
            padding: 12px;
            border: 1px solid var(--gray-300);
            border-radius: 8px;
            font-size: 15px;
        }
        .form-group textarea { min-height: 100px; resize: vertical; }

        .filters { display: flex; gap: 12px; flex-wrap: wrap; margin-bottom: 24px; align-items: end; }
        .filter-group { display: flex; flex-direction: column; min-width: 180px; }

        .bulk-actions { display: flex; gap: 12px; align-items: center; flex-wrap: wrap; }

        .empty-state { text-align: center; padding: 60px 20px; color: var(--gray-500); }
        .empty-state i { font-size: 48px; margin-bottom: 16px; opacity: 0.5; }

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
            .achievement-item { flex-direction: column; align-items: flex-start; gap: 16px; }
            .form-row { grid-template-columns: 1fr; }
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
                <?php if (!empty($_SESSION['profile_picture'])): ?>
                    <img src="../<?php echo htmlspecialchars($_SESSION['profile_picture']); ?>" alt="Profile" class="profile-pic">
                <?php else: ?>
                    <div class="profile-pic default"><?php echo htmlspecialchars(strtoupper(substr($_SESSION['name'] ?? 'A', 0, 1))); ?></div>
                <?php endif; ?>
                <div class="user-info">
                    <h4><?php echo htmlspecialchars($_SESSION['name'] ?? 'Admin'); ?></h4>
                    <p><?php echo htmlspecialchars($_SESSION['email'] ?? ''); ?></p>
                    <span style="font-size: 12px; background: #e0e7ff; color: var(--primary); padding: 4px 10px; border-radius: 20px; font-weight: 600;">ADMIN</span>
                </div>
            </div>

            <nav class="nav-menu">
                <a href="admin.php" class="nav-link"><i class="fas fa-tachometer-alt"></i> Dashboard</a>
                <a href="students.php" class="nav-link"><i class="fas fa-users"></i> Students <span class="badge"><?php echo $sidebar_stats['students']; ?></span></a>
                <a href="goals.php" class="nav-link"><i class="fas fa-bullseye"></i> System Goals <span class="badge"><?php echo $sidebar_stats['goals']; ?></span></a>
                <a href="assign_goals.php" class="nav-link"><i class="fas fa-tasks"></i> Assign Goals</a>
                <a href="achievements.php" class="nav-link active"><i class="fas fa-trophy"></i> Achievements <span class="badge"><?php echo $sidebar_stats['unlocked']; ?> unlocked</span></a>
                <a href="reports.php" class="nav-link"><i class="fas fa-chart-bar"></i> Reports</a>
                <a href="categories.php" class="nav-link"><i class="fas fa-tags"></i> Categories</a>
                <a href="settings.php" class="nav-link"><i class="fas fa-cog"></i> Settings</a>
            </nav>

            <div class="sidebar-quick-stats">
                <div class="sidebar-stat">
                    <div class="sidebar-stat-icon"><i class="fas fa-trophy"></i></div>
                    <div><div class="sidebar-stat-label">Total Achievements</div><div class="sidebar-stat-number"><?php echo $sidebar_stats['achievements']; ?></div></div>
                </div>
                <div class="sidebar-stat">
                    <div class="sidebar-stat-icon"><i class="fas fa-unlock"></i></div>
                    <div><div class="sidebar-stat-label">Unlocked</div><div class="sidebar-stat-number"><?php echo $sidebar_stats['unlocked']; ?></div></div>
                </div>
                <div class="sidebar-stat">
                    <div class="sidebar-stat-icon"><i class="fas fa-star"></i></div>
                    <div><div class="sidebar-stat-label">Points Distributed</div><div class="sidebar-stat-number"><?php echo $sidebar_stats['points']; ?></div></div>
                </div>
            </div>

            <div class="sidebar-footer">
                <a href="../logout.php" class="logout-btn"><i class="fas fa-sign-out-alt"></i> Logout</a>
            </div>
        </aside>

        <main class="main-content">
            <header class="page-header">
                <div class="header-content">
                    <h1>Achievements & Badges</h1>
                    <p>Define badges students earn automatically based on goal completion criteria</p>
                </div>
                <div style="display: flex; gap: 12px; flex-wrap: wrap;">
                    <a href="?edit=new" class="btn"><i class="fas fa-plus"></i> Add Achievement</a>
                    <form method="POST" style="display:inline;">
                        <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                        <input type="hidden" name="action" value="recalculate_all">
                        <button type="submit" class="btn btn-outline" onclick="return confirm('Recalculate achievements for all students? This may take time.')">
                            <i class="fas fa-sync-alt"></i> Recalculate All
                        </button>
                    </form>
                </div>
            </header>

            <?php if ($success): ?>
                <div class="alert alert-success"><i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($success); ?></div>
            <?php endif; ?>
            <?php if ($error): ?>
                <div class="alert alert-error"><i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($error); ?></div>
            <?php endif; ?>

            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-icon"><i class="fas fa-trophy"></i></div>
                    <div>
                        <div class="stat-number"><?php echo $total_achievements; ?></div>
                        <div class="stat-label">Total Achievements</div>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon"><i class="fas fa-unlock"></i></div>
                    <div>
                        <div class="stat-number"><?php echo $total_unlocked; ?></div>
                        <div class="stat-label">Total Unlocks</div>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon"><i class="fas fa-star"></i></div>
                    <div>
                        <div class="stat-number"><?php echo $total_points; ?></div>
                        <div class="stat-label">Points Distributed</div>
                    </div>
                </div>
            </div>

            <div class="content-grid">
                <!-- Top Students with Most Achievements -->
                <div class="card">
                    <div class="card-header">
                        <h3><i class="fas fa-medal"></i> Top Achievement Hunters</h3>
                    </div>
                    <div class="card-body">
                        <?php if (!empty($top_students)): ?>
                            <?php foreach ($top_students as $index => $student): 
                                $medal = $index == 0 ? '🥇' : ($index == 1 ? '🥈' : ($index == 2 ? '🥉' : ($index + 1)));
                            ?>
                                <div class="achievement-item">
                                    <div style="font-size: 32px;"><?php echo $medal; ?></div>
                                    <?php if (!empty($student['profile_picture'])): ?>
                                        <img src="../<?php echo htmlspecialchars($student['profile_picture']); ?>" alt="" class="profile-pic" style="width:56px;height:56px;">
                                    <?php else: ?>
                                        <div class="profile-pic default" style="width:56px;height:56px;font-size:20px;"><?php echo htmlspecialchars(strtoupper(substr($student['name'], 0, 1))); ?></div>
                                    <?php endif; ?>
                                    <div class="achievement-info">
                                        <div style="font-weight: 600;"><?php echo htmlspecialchars($student['name']); ?></div>
                                        <div class="achievement-stats">
                                            <span><strong><?php echo $student['achievements_count']; ?></strong> achievements</span>
                                            <span><strong><?php echo $student['points_earned']; ?></strong> points</span>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <div class="empty-state"><i class="fas fa-medal"></i><p>No achievements unlocked yet</p></div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- All Achievements List -->
                <div class="card">
                    <div class="card-header">
                        <h3><i class="fas fa-trophy"></i> All Achievements</h3>
                        <div class="filters">
                            <form method="GET" style="display:flex; gap:8px; align-items:end;">
                                <div class="filter-group">
                                    <label>Search</label>
                                    <input type="text" name="search" value="<?php echo htmlspecialchars($search); ?>" placeholder="Title/description">
                                </div>
                                <div class="filter-group">
                                    <label>Status</label>
                                    <select name="status">
                                        <option value="all" <?php echo $status_filter==='all'?'selected':''; ?>>All</option>
                                        <option value="active" <?php echo $status_filter==='active'?'selected':''; ?>>Active</option>
                                        <option value="inactive" <?php echo $status_filter==='inactive'?'selected':''; ?>>Inactive</option>
                                    </select>
                                </div>
                                <button type="submit" class="btn btn-outline"><i class="fas fa-search"></i></button>
                            </form>
                        </div>
                    </div>
                    <div class="card-body">
                        <?php if (empty($achievements)): ?>
                            <div class="empty-state">
                                <i class="fas fa-trophy"></i>
                                <p>No achievements found</p>
                                <a href="?edit=new" class="btn">Create First Achievement</a>
                            </div>
                        <?php else: ?>
                            <form method="POST">
                                <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                                <div class="bulk-actions" style="margin-bottom:16px;">
                                    <select name="bulk_action" required>
                                        <option value="">Bulk Action</option>
                                        <option value="activate">Activate</option>
                                        <option value="deactivate">Deactivate</option>
                                        <option value="delete">Delete</option>
                                    </select>
                                    <button type="submit" name="action" value="bulk_action" class="btn btn-outline" onclick="return confirm('Apply to selected?')">Apply</button>
                                </div>
                                <?php foreach ($achievements as $achievement): ?>
                                    <div class="achievement-item">
                                        <input type="checkbox" name="achievement_ids[]" value="<?php echo $achievement['id']; ?>" style="align-self:start;margin-top:28px;">
                                        <div class="badge-preview" style="background: <?php echo htmlspecialchars($achievement['color']); ?>;">
                                            <i class="fas fa-<?php echo htmlspecialchars($achievement['icon']); ?>"></i>
                                        </div>
                                        <div class="achievement-info">
                                            <div style="font-weight: 600; font-size: 18px;"><?php echo htmlspecialchars($achievement['title']); ?></div>
                                            <div style="color: var(--gray-500); margin-top: 4px;"><?php echo htmlspecialchars($achievement['description']); ?></div>
                                            <div class="achievement-stats">
                                                <div><strong><?php echo $achievement['points']; ?> points</strong></div>
                                                <div class="achievement-unlocked"><?php echo $achievement['unlocked_count']; ?> unlocked</div>
                                                <span class="status-badge status-<?php echo $achievement['is_active'] ? 'active' : 'inactive'; ?>">
                                                    <?php echo $achievement['is_active'] ? 'Active' : 'Inactive'; ?>
                                                </span>
                                            </div>
                                        </div>
                                        <div class="action-buttons">
                                            <a href="?edit=<?php echo $achievement['id']; ?>" class="btn btn-sm btn-outline"><i class="fas fa-edit"></i></a>
                                            <form method="POST" style="display:inline;">
                                                <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                                                <input type="hidden" name="action" value="delete_achievement">
                                                <input type="hidden" name="achievement_id" value="<?php echo $achievement['id']; ?>">
                                                <button type="submit" class="btn btn-sm btn-danger" onclick="return confirm('Delete this achievement?')"><i class="fas fa-trash"></i></button>
                                            </form>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </form>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <?php if ($edit_achievement): ?>
                <div class="card">
                    <div class="card-header">
                        <h3><?php echo $edit_achievement['id'] === 'new' ? 'Add New Achievement' : 'Edit Achievement'; ?></h3>
                    </div>
                    <div class="card-body">
                        <form method="POST">
                            <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                            <input type="hidden" name="action" value="<?php echo $edit_achievement['id'] === 'new' ? 'add_achievement' : 'edit_achievement'; ?>">
                            <?php if ($edit_achievement['id'] !== 'new'): ?>
                                <input type="hidden" name="achievement_id" value="<?php echo $edit_achievement['id']; ?>">
                            <?php endif; ?>

                            <div class="form-row">
                                <div class="form-group">
                                    <label>Title *</label>
                                    <input type="text" name="title" value="<?php echo htmlspecialchars($edit_achievement['title'] ?? ''); ?>" required>
                                </div>
                                <div class="form-group">
                                    <label>Points *</label>
                                    <input type="number" name="points" min="1" value="<?php echo (int)($edit_achievement['points'] ?? 10); ?>" required>
                                </div>
                            </div>

                            <div class="form-group">
                                <label>Description</label>
                                <textarea name="description"><?php echo htmlspecialchars($edit_achievement['description'] ?? ''); ?></textarea>
                            </div>

                            <div class="form-row">
                                <div class="form-group">
                                    <label>Criteria Type</label>
                                    <select name="criteria_type">
                                        <option value="">Manual Award</option>
                                        <option value="goals_completed" <?php echo ($edit_achievement['criteria_type'] ?? '') === 'goals_completed' ? 'selected' : ''; ?>>Total Goals Completed</option>
                                        <option value="category_goals" <?php echo ($edit_achievement['criteria_type'] ?? '') === 'category_goals' ? 'selected' : ''; ?>>Goals in Specific Category</option>
                                        <option value="streak" <?php echo ($edit_achievement['criteria_type'] ?? '') === 'streak' ? 'selected' : ''; ?>>Consecutive Days</option>
                                    </select>
                                </div>
                                <div class="form-group">
                                    <label>Criteria Value <small>(e.g., 10 or category name)</small></label>
                                    <input type="text" name="criteria_value" value="<?php echo htmlspecialchars($edit_achievement['criteria_value'] ?? ''); ?>">
                                </div>
                            </div>

                            <div class="form-row">
                                <div class="form-group">
                                    <label>Icon</label>
                                    <select name="icon">
                                        <option value="trophy" <?php echo ($edit_achievement['icon'] ?? 'trophy') === 'trophy' ? 'selected' : ''; ?>>Trophy</option>
                                        <option value="medal" <?php echo ($edit_achievement['icon'] ?? '') === 'medal' ? 'selected' : ''; ?>>Medal</option>
                                        <option value="star" <?php echo ($edit_achievement['icon'] ?? '') === 'star' ? 'selected' : ''; ?>>Star</option>
                                        <option value="award" <?php echo ($edit_achievement['icon'] ?? '') === 'award' ? 'selected' : ''; ?>>Award</option>
                                        <option value="certificate" <?php echo ($edit_achievement['icon'] ?? '') === 'certificate' ? 'selected' : ''; ?>>Certificate</option>
                                        <option value="gem" <?php echo ($edit_achievement['icon'] ?? '') === 'gem' ? 'selected' : ''; ?>>Gem</option>
                                    </select>
                                </div>
                                <div class="form-group">
                                    <label>Banner Color</label>
                                    <input type="color" name="color" value="<?php echo htmlspecialchars($edit_achievement['color'] ?? '#f59e0b'); ?>">
                                </div>
                                <div class="form-group" style="align-self: end;">
                                    <label><input type="checkbox" name="is_active" <?php echo ($edit_achievement['is_active'] ?? 1) ? 'checked' : ''; ?>> Active (eligible for award)</label>
                                </div>
                            </div>

                            <div style="display: flex; gap: 12px; margin-top: 24px;">
                                <button type="submit" class="btn"><?php echo $edit_achievement['id'] === 'new' ? 'Create Achievement' : 'Update Achievement'; ?></button>
                                <a href="achievements.php" class="btn btn-outline">Cancel</a>
                            </div>
                        </form>
                    </div>
                </div>
            <?php endif; ?>
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
    </script>
</body>
</html>