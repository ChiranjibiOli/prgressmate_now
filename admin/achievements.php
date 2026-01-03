<?php
session_start();
require_once '../includes/db_connection.php';
checkAuth('admin');

// === POST Action Handling ===
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $success = '';
    $error = '';
    
    try {
        $action = $_POST['action'] ?? '';
        
        if ($action === 'add_achievement' || $action === 'edit_achievement') {
            $title = trim($_POST['title'] ?? '');
            $description = trim($_POST['description'] ?? '');
            $points = (int)($_POST['points'] ?? 0);
            $criteria_type = trim($_POST['criteria_type'] ?? '');
            $criteria_value = trim($_POST['criteria_value'] ?? '');
            $icon = trim($_POST['icon'] ?? 'trophy');
            $color = $_POST['color'] ?? '#f59e0b';
            $is_active = isset($_POST['is_active']) ? 1 : 0;
            
            if (!$title || $points <= 0) {
                throw new Exception("Title and points (greater than 0) are required.");
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
                        WHERE id=?
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
                
                // Delete from user_achievements first
                $pdo->prepare("DELETE FROM user_achievements WHERE achievement_id=?")->execute([$id]);
                
                // Then delete achievement
                $pdo->prepare("DELETE FROM achievements WHERE id=?")->execute([$id]);
                
                $pdo->commit();
                $success = "Achievement and all associated records deleted successfully!";
            } else {
                throw new Exception("Invalid achievement ID.");
            }
            
        } elseif ($action === 'bulk_action') {
            $bulk_action = $_POST['bulk_action'] ?? '';
            $achievement_ids = $_POST['achievement_ids'] ?? [];
            
            if (empty($achievement_ids)) {
                throw new Exception('Please select at least one achievement.');
            }
            
            $placeholders = implode(',', array_fill(0, count($achievement_ids), '?'));
            
            if ($bulk_action === 'activate') {
                $pdo->prepare("UPDATE achievements SET is_active = 1 WHERE id IN ($placeholders)")->execute($achievement_ids);
                $success = count($achievement_ids) . ' achievement(s) activated!';
            } elseif ($bulk_action === 'deactivate') {
                $pdo->prepare("UPDATE achievements SET is_active = 0 WHERE id IN ($placeholders)")->execute($achievement_ids);
                $success = count($achievement_ids) . ' achievement(s) deactivated!';
            } elseif ($bulk_action === 'delete') {
                $pdo->beginTransaction();
                $pdo->prepare("DELETE FROM user_achievements WHERE achievement_id IN ($placeholders)")->execute($achievement_ids);
                $pdo->prepare("DELETE FROM achievements WHERE id IN ($placeholders)")->execute($achievement_ids);
                $pdo->commit();
                $success = count($achievement_ids) . ' achievement(s) deleted!';
            }
        }
        
    } catch (Exception $e) {
        $error = 'Error: ' . $e->getMessage();
    }
    
    $_SESSION['success'] = $success;
    $_SESSION['error'] = $error;
    header("Location: achievements.php?" . http_build_query($_GET));
    exit;
}

// === Flash Messages ===
$success = $_SESSION['success'] ?? '';
$error = $_SESSION['error'] ?? '';
unset($_SESSION['success'], $_SESSION['error']);

// === Sidebar Stats ===
$total_students = $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'student' AND status != 'deleted'")->fetchColumn() ?: 0;
$total_goals = $pdo->query("SELECT COUNT(*) FROM admin_goals WHERE status = 'active'")->fetchColumn() ?: 0;
$total_points = $pdo->query("SELECT COALESCE(SUM(points), 0) FROM users WHERE role = 'student' AND status != 'deleted'")->fetchColumn() ?: 0;
$total_achievements = $pdo->query("SELECT COUNT(*) FROM achievements")->fetchColumn() ?: 0;
$total_unlocked = $pdo->query("SELECT COUNT(*) FROM user_achievements")->fetchColumn() ?: 0;

$sidebar_stats = [
    'students' => $total_students,
    'goals' => $total_goals,
    'points' => $total_points,
    'achievements' => $total_achievements,
    'unlocked' => $total_unlocked
];

// === Edit Mode ===
$edit_achievement_id = null;
$edit_achievement = null;

if (isset($_GET['edit'])) {
    if ($_GET['edit'] === 'new') {
        $edit_achievement_id = 'new';
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
        $edit_achievement_id = (int)$_GET['edit'];
        $stmt = $pdo->prepare("SELECT * FROM achievements WHERE id=?");
        $stmt->execute([$edit_achievement_id]);
        $edit_achievement = $stmt->fetch(PDO::FETCH_ASSOC);
    }
}

// === Filters ===
$search = trim($_GET['search'] ?? '');
$status_filter = $_GET['status'] ?? 'all';
$criteria_filter = $_GET['criteria'] ?? 'all';
$sort_by = $_GET['sort_by'] ?? 'created_at';
$sort_order = $_GET['sort_order'] ?? 'desc';

$where = [];
$params = [];

if ($search) {
    $where[] = "(a.title LIKE ? OR a.description LIKE ?)";
    $like = "%$search%";
    array_push($params, $like, $like);
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

// === Sorting ===
$sort_options = [
    'created_at' => 'a.created_at',
    'title' => 'a.title',
    'points' => 'a.points',
    'unlocked_count' => 'unlocked_count',
    'criteria_type' => 'a.criteria_type'
];

$order_by = $sort_options[$sort_by] ?? 'a.created_at';
$order_direction = strtoupper($sort_order) === 'ASC' ? 'ASC' : 'DESC';

// === Fetch Achievements with unlocked count ===
$achievements_stmt = $pdo->prepare("
    SELECT a.*, 
           COUNT(ua.user_id) AS unlocked_count,
           (SELECT GROUP_CONCAT(DISTINCT u.name ORDER BY u.name SEPARATOR ', ') 
            FROM user_achievements ua2 
            JOIN users u ON ua2.user_id = u.id 
            WHERE ua2.achievement_id = a.id 
            LIMIT 5) as recent_unlockers
    FROM achievements a
    LEFT JOIN user_achievements ua ON a.id = ua.achievement_id
    $where_clause
    GROUP BY a.id
    ORDER BY $order_by $order_direction
");
$achievements_stmt->execute($params);
$achievements = $achievements_stmt->fetchAll(PDO::FETCH_ASSOC);

// === Get distinct criteria types for filter ===
$criteria_types = $pdo->query("
    SELECT DISTINCT criteria_type 
    FROM achievements 
    WHERE criteria_type IS NOT NULL AND criteria_type != '' 
    ORDER BY criteria_type
")->fetchAll(PDO::FETCH_COLUMN);

// === Get top students by achievements ===
$top_students = $pdo->query("
    SELECT u.id, u.name, u.email, u.profile_picture, u.points,
           COUNT(ua.id) as achievements_count,
           SUM(a.points) as total_achievement_points
    FROM users u
    JOIN user_achievements ua ON u.id = ua.user_id
    JOIN achievements a ON ua.achievement_id = a.id
    WHERE u.role = 'student' AND u.status = 'active'
    GROUP BY u.id
    ORDER BY achievements_count DESC, total_achievement_points DESC
    LIMIT 5
")->fetchAll(PDO::FETCH_ASSOC);

// === Achievement distribution ===
$achievement_stats = [
    'total' => $total_achievements,
    'active' => $pdo->query("SELECT COUNT(*) FROM achievements WHERE is_active = 1")->fetchColumn() ?: 0,
    'inactive' => $pdo->query("SELECT COUNT(*) FROM achievements WHERE is_active = 0")->fetchColumn() ?: 0,
    'with_criteria' => $pdo->query("SELECT COUNT(*) FROM achievements WHERE criteria_type IS NOT NULL AND criteria_type != ''")->fetchColumn() ?: 0,
    'without_criteria' => $pdo->query("SELECT COUNT(*) FROM achievements WHERE criteria_type IS NULL OR criteria_type = ''")->fetchColumn() ?: 0
];

// === Common criteria examples ===
$common_criteria = [
    'goals_completed' => 'Number of goals completed',
    'streak_days' => 'Daily login streak',
    'total_points' => 'Total points earned',
    'perfect_goals' => 'Goals completed with 100%',
    'early_completion' => 'Goals completed before deadline',
    'community_help' => 'Helping other students'
];
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
            --primary-light: #e0e7ff;
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
            --silver: #d1d5db;
            --bronze: #92400e;
            --gray-100: #f9fafb;
            --gray-200: #f3f4f6;
            --gray-300: #e5e7eb;
            --gray-500: #6b7280;
            --gray-700: #374151;
            --gray-900: #111827;
            --shadow-sm: 0 1px 3px rgba(0,0,0,0.1);
            --shadow: 0 4px 12px rgba(0,0,0,0.08);
            --shadow-lg: 0 10px 25px rgba(0,0,0,0.1);
            --radius: 12px;
            --radius-sm: 8px;
            --transition: all 0.3s ease;
        }

        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Inter', sans-serif; background: var(--gray-100); color: var(--gray-900); line-height: 1.6; }

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
        .nav-link.active { background: var(--primary-light); color: var(--primary); border-left: 4px solid var(--primary); font-weight: 600; }
        .badge { margin-left: auto; background: var(--primary); color: white; padding: 4px 10px; border-radius: 20px; font-size: 12px; font-weight: 600; }

        .sidebar-quick-stats { padding: 20px; border-top: 1px solid var(--gray-300); }
        .sidebar-stat { display: flex; align-items: center; gap: 15px; margin-bottom: 16px; }
        .sidebar-stat-icon { width: 44px; height: 44px; border-radius: 10px; background: var(--primary-light); color: var(--primary); display: flex; align-items: center; justify-content: center; font-size: 20px; }

        .sidebar-footer { padding: 20px; }
        .logout-btn { display: flex; align-items: center; gap: 12px; padding: 14px 20px; background: var(--danger-light); color: var(--danger); border-radius: 10px; width: 100%; font-weight: 500; transition: var(--transition); }

        .main-content { flex: 1; margin-left: 280px; padding: 32px; transition: var(--transition); }

        .page-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 32px; flex-wrap: wrap; gap: 16px; }
        .header-content h1 { font-size: 30px; font-weight: 700; }
        .header-content p { color: var(--gray-500); margin-top: 8px; }

        .btn { padding: 12px 24px; border-radius: 10px; font-weight: 500; cursor: pointer; border: none; transition: var(--transition); display: inline-flex; align-items: center; gap: 8px; font-size: 15px; }
        .btn-primary { background: var(--primary); color: white; }
        .btn-primary:hover { background: var(--primary-dark); }
        .btn-success { background: var(--success); color: white; }
        .btn-warning { background: var(--warning); color: white; }
        .btn-danger { background: var(--danger); color: white; }
        .btn-outline { background: white; color: var(--primary); border: 1px solid var(--primary); }
        .btn-outline:hover { background: var(--primary); color: white; }
        .btn-sm { padding: 8px 16px; font-size: 13px; }
        .btn-gold { background: var(--gold); color: var(--gray-900); }
        .btn-silver { background: var(--silver); color: var(--gray-900); }
        .btn-bronze { background: var(--bronze); color: white; }

        .stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 24px; margin-bottom: 40px; }
        .stat-card { background: white; border-radius: var(--radius); padding: 24px; box-shadow: var(--shadow); text-align: center; position: relative; overflow: hidden; }
        .stat-card::before { content: ''; position: absolute; top: 0; left: 0; width: 5px; height: 100%; }
        .stat-card:nth-child(1)::before { background: var(--gold); }
        .stat-card:nth-child(2)::before { background: var(--silver); }
        .stat-card:nth-child(3)::before { background: var(--bronze); }
        .stat-card:nth-child(4)::before { background: var(--purple); }
        .stat-number { font-size: 32px; font-weight: 800; }
        .stat-label { font-size: 15px; color: var(--gray-500); }

        .alert { padding: 16px; border-radius: var(--radius); margin-bottom: 24px; display: flex; align-items: center; gap: 12px; font-weight: 500; box-shadow: var(--shadow-sm); }
        .alert-success { background: var(--success-light); color: #065f46; border-left: 5px solid var(--success); }
        .alert-error { background: var(--danger-light); color: #991b1b; border-left: 5px solid var(--danger); }

        .filters-section { background: white; border-radius: var(--radius); padding: 24px; margin-bottom: 32px; box-shadow: var(--shadow); }
        .filter-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 16px; align-items: end; }
        .filter-group { margin-bottom: 0; }
        .filter-group label { display: block; margin-bottom: 8px; font-weight: 500; color: var(--gray-700); }
        .filter-group input, .filter-group select { width: 100%; padding: 12px; border: 1px solid var(--gray-300); border-radius: var(--radius-sm); font-size: 15px; }

        .bulk-actions { 
            background: var(--gray-100); 
            padding: 16px 24px; 
            border-bottom: 1px solid var(--gray-300); 
            display: flex; 
            align-items: center; 
            gap: 16px; 
            flex-wrap: wrap;
            display: none;
        }
        .bulk-select { display: flex; align-items: center; gap: 8px; }
        .select-all-checkbox { width: 16px; height: 16px; cursor: pointer; }

        .table-container { 
            background: white; 
            border-radius: var(--radius); 
            overflow: hidden; 
            box-shadow: var(--shadow); 
            overflow-x: auto;
            position: relative;
        }
        table { width: 100%; min-width: 1200px; border-collapse: collapse; }
        th { background: var(--gray-200); padding: 18px; text-align: left; font-weight: 600; color: var(--gray-700); position: sticky; top: 0; }
        td { padding: 18px; border-bottom: 1px solid var(--gray-300); vertical-align: middle; }
        tr:hover { background: var(--gray-100); }

        .achievement-badge {
            display: flex;
            align-items: center;
            gap: 15px;
        }
        .badge-icon {
            width: 56px;
            height: 56px;
            border-radius: var(--radius);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 24px;
            color: white;
            box-shadow: var(--shadow);
            flex-shrink: 0;
        }

        .status-badge {
            padding: 6px 14px;
            border-radius: 20px;
            font-size: 13px;
            font-weight: 600;
            text-transform: capitalize;
        }
        .status-active { background: var(--success-light); color: #065f46; }
        .status-inactive { background: var(--gray-300); color: var(--gray-700); }

        .action-buttons { display: flex; flex-wrap: wrap; gap: 8px; }

        .pagination { display: flex; justify-content: center; gap: 8px; padding: 24px; flex-wrap: wrap; }
        .pagination-link { padding: 10px 16px; border-radius: var(--radius-sm); background: white; border: 1px solid var(--gray-300); color: var(--gray-700); transition: var(--transition); }
        .pagination-link:hover { background: var(--gray-200); }
        .pagination-link.active { background: var(--primary); color: white; border-color: var(--primary); }

        .empty-state { text-align: center; padding: 80px 20px; color: var(--gray-500); }
        .empty-state i { font-size: 64px; margin-bottom: 20px; opacity: 0.5; }

        /* Modal Styles */
        .modal-overlay {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(0,0,0,0.5);
            z-index: 2000;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }
        .modal-overlay.active { display: flex; }
        .modal {
            background: white;
            border-radius: var(--radius);
            width: 100%;
            max-width: 700px;
            max-height: 90vh;
            overflow-y: auto;
            box-shadow: var(--shadow-lg);
        }
        .modal-header {
            padding: 24px;
            border-bottom: 1px solid var(--gray-300);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .modal-close {
            background: none;
            border: none;
            font-size: 20px;
            color: var(--gray-500);
            cursor: pointer;
            padding: 8px;
            border-radius: 8px;
        }
        .modal-close:hover { background: var(--gray-200); }
        .modal-body { padding: 24px; }

        .form-group { margin-bottom: 20px; }
        .form-group label { display: block; margin-bottom: 8px; font-weight: 500; color: var(--gray-700); }
        .required::after { content: " *"; color: var(--danger); }
        .form-group input, .form-group select, .form-group textarea { 
            width: 100%; 
            padding: 12px; 
            border: 1px solid var(--gray-300); 
            border-radius: var(--radius-sm); 
            font-size: 15px; 
        }
        .form-group textarea { min-height: 100px; resize: vertical; }
        .form-help { font-size: 13px; color: var(--gray-500); margin-top: 6px; }
        .form-row { display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 16px; }

        .icon-preview {
            width: 60px;
            height: 60px;
            border-radius: var(--radius);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 28px;
            margin-bottom: 10px;
            box-shadow: var(--shadow);
        }

        .top-students {
            background: white;
            border-radius: var(--radius);
            padding: 24px;
            margin-bottom: 32px;
            box-shadow: var(--shadow);
        }
        .top-students h3 { margin-bottom: 20px; display: flex; align-items: center; gap: 10px; }
        .student-rank-card {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 16px;
            border-bottom: 1px solid var(--gray-200);
            transition: var(--transition);
        }
        .student-rank-card:last-child { border-bottom: none; }
        .student-rank-card:hover { background: var(--gray-100); }
        .student-rank { font-size: 20px; font-weight: 800; color: var(--primary); width: 40px; }
        .student-info { flex: 1; display: flex; align-items: center; gap: 12px; }
        .student-avatar {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: linear-gradient(135deg, var(--primary), var(--purple));
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: bold;
            object-fit: cover;
        }
        .student-achievements { font-weight: 700; color: var(--success); }

        .mobile-toggle { display: none; position: fixed; top: 20px; left: 20px; z-index: 1100; background: var(--primary); color: white; border: none; width: 48px; height: 48px; border-radius: 12px; font-size: 20px; cursor: pointer; box-shadow: var(--shadow); }
        .sidebar-overlay { display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.5); z-index: 999; }

        @media (max-width: 768px) {
            .sidebar { transform: translateX(-100%); width: 300px; }
            .sidebar.active { transform: translateX(0); }
            .sidebar-overlay.active { display: block; }
            .sidebar-close { display: block; }
            .mobile-toggle { display: flex; align-items: center; justify-content: center; }
            .main-content { margin-left: 0; padding: 24px 16px; padding-top: 80px; }
            .filter-grid { grid-template-columns: 1fr; }
            .action-buttons { flex-direction: column; }
            .bulk-actions { flex-direction: column; align-items: flex-start; }
            table { min-width: 800px; }
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
                    <div class="profile-pic default"><?php echo strtoupper(substr($_SESSION['name'] ?? 'A', 0, 1)); ?></div>
                <?php endif; ?>
                <div class="user-info">
                    <h4><?php echo htmlspecialchars($_SESSION['name'] ?? 'Admin'); ?></h4>
                    <p><?php echo htmlspecialchars($_SESSION['email'] ?? ''); ?></p>
                    <span style="font-size: 12px; background: var(--primary-light); color: var(--primary); padding: 4px 10px; border-radius: 20px; font-weight: 600;">ADMIN</span>
                </div>
            </div>

            <nav class="nav-menu">
                <a href="admin.php" class="nav-link"><i class="fas fa-tachometer-alt"></i> Dashboard</a>
                <a href="students.php" class="nav-link"><i class="fas fa-users"></i> Students <?php if ($sidebar_stats['students'] > 0): ?><span class="badge"><?php echo $sidebar_stats['students']; ?></span><?php endif; ?></a>
                <a href="goals.php" class="nav-link"><i class="fas fa-bullseye"></i> System Goals <?php if ($sidebar_stats['goals'] > 0): ?><span class="badge"><?php echo $sidebar_stats['goals']; ?></span><?php endif; ?></a>
                <a href="assign_goals.php" class="nav-link"><i class="fas fa-tasks"></i> Assign Goals</a>
                <a href="achievements.php" class="nav-link active"><i class="fas fa-trophy"></i> Achievements 
                    <?php if ($sidebar_stats['unlocked'] > 0): ?>
                        <span class="badge"><?php echo $sidebar_stats['unlocked']; ?> unlocked</span>
                    <?php endif; ?>
                </a>
                <a href="reports.php" class="nav-link"><i class="fas fa-chart-bar"></i> Reports</a>
                <a href="notifications.php" class="nav-link"><i class="fas fa-bell"></i> Notifications</a>
                <a href="settings.php" class="nav-link"><i class="fas fa-cog"></i> Settings</a>
            </nav>

            <div class="sidebar-quick-stats">
                <div class="sidebar-stat">
                    <div class="sidebar-stat-icon"><i class="fas fa-users"></i></div>
                    <div>
                        <div class="sidebar-stat-label">Students</div>
                        <div class="sidebar-stat-number"><?php echo $sidebar_stats['students']; ?></div>
                    </div>
                </div>
                <div class="sidebar-stat">
                    <div class="sidebar-stat-icon"><i class="fas fa-trophy"></i></div>
                    <div>
                        <div class="sidebar-stat-label">Achievements</div>
                        <div class="sidebar-stat-number"><?php echo $sidebar_stats['achievements']; ?></div>
                    </div>
                </div>
                <div class="sidebar-stat">
                    <div class="sidebar-stat-icon"><i class="fas fa-unlock"></i></div>
                    <div>
                        <div class="sidebar-stat-label">Unlocked</div>
                        <div class="sidebar-stat-number"><?php echo $sidebar_stats['unlocked']; ?></div>
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
                    <h1>Achievements</h1>
                    <p>Manage badges, trophies, and achievements for student accomplishments</p>
                </div>
                <div>
                    <?php if ($edit_achievement_id): ?>
                        <a href="achievements.php" class="btn btn-outline"><i class="fas fa-times"></i> Cancel Edit</a>
                    <?php else: ?>
                        <a href="?edit=new" class="btn btn-primary"><i class="fas fa-plus"></i> Add New Achievement</a>
                    <?php endif; ?>
                </div>
            </header>

            <?php if ($success): ?>
                <div class="alert alert-success">
                    <i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($success); ?>
                    <button style="margin-left: auto; background: none; border: none; cursor: pointer;" onclick="this.parentElement.remove()">
                        <i class="fas fa-times"></i>
                    </button>
                </div>
            <?php endif; ?>
            
            <?php if ($error): ?>
                <div class="alert alert-error">
                    <i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($error); ?>
                    <button style="margin-left: auto; background: none; border: none; cursor: pointer;" onclick="this.parentElement.remove()">
                        <i class="fas fa-times"></i>
                    </button>
                </div>
            <?php endif; ?>

            <?php if (!empty($top_students)): ?>
                <div class="top-students">
                    <h3><i class="fas fa-crown"></i> Top 5 Students by Achievements</h3>
                    <?php foreach ($top_students as $index => $student): 
                        $rank_colors = ['var(--gold)', 'var(--silver)', 'var(--bronze)', 'var(--info)', 'var(--purple)'];
                        $rank_color = $rank_colors[$index] ?? 'var(--gray-500)';
                    ?>
                        <div class="student-rank-card">
                            <div class="student-rank" style="color: <?php echo $rank_color; ?>">#<?php echo $index + 1; ?></div>
                            <div class="student-info">
                                <?php if ($student['profile_picture']): ?>
                                    <img src="../<?php echo htmlspecialchars($student['profile_picture']); ?>" alt="Profile" class="student-avatar">
                                <?php else: ?>
                                    <div class="student-avatar"><?php echo strtoupper(substr($student['name'], 0, 1)); ?></div>
                                <?php endif; ?>
                                <div>
                                    <div style="font-weight: 600;"><?php echo htmlspecialchars($student['name']); ?></div>
                                    <div style="font-size: 13px; color: var(--gray-500);">
                                        <?php echo $student['achievements_count']; ?> achievements
                                    </div>
                                </div>
                            </div>
                            <div class="student-achievements"><?php echo $student['total_achievement_points']; ?> pts</div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-number"><?php echo $achievement_stats['total']; ?></div>
                    <div class="stat-label">Total Achievements</div>
                </div>
                <div class="stat-card">
                    <div class="stat-number"><?php echo $achievement_stats['active']; ?></div>
                    <div class="stat-label">Active Achievements</div>
                </div>
                <div class="stat-card">
                    <div class="stat-number"><?php echo $achievement_stats['with_criteria']; ?></div>
                    <div class="stat-label">With Criteria</div>
                </div>
                <div class="stat-card">
                    <div class="stat-number"><?php echo $total_unlocked; ?></div>
                    <div class="stat-label">Total Unlocks</div>
                </div>
            </div>

            <!-- Achievement Form (only shown in edit mode) -->
            <?php if ($edit_achievement_id): ?>
                <div class="modal-overlay active">
                    <div class="modal">
                        <div class="modal-header">
                            <h3><?php echo $edit_achievement_id === 'new' ? 'Add New Achievement' : 'Edit Achievement'; ?></h3>
                            <a href="achievements.php" class="modal-close"><i class="fas fa-times"></i></a>
                        </div>
                        <div class="modal-body">
                            <form method="POST" id="achievementForm">
                                <?php if ($edit_achievement_id === 'new'): ?>
                                    <input type="hidden" name="action" value="add_achievement">
                                <?php else: ?>
                                    <input type="hidden" name="action" value="edit_achievement">
                                    <input type="hidden" name="achievement_id" value="<?php echo $edit_achievement['id']; ?>">
                                <?php endif; ?>
                                
                                <div class="form-group">
                                    <label><i class="fas fa-heading"></i> Title <span class="required"></span></label>
                                    <input type="text" name="title" required value="<?php echo htmlspecialchars($edit_achievement['title'] ?? ''); ?>" placeholder="e.g., Perfect Streak">
                                    <div class="form-help">Name of the achievement badge</div>
                                </div>
                                
                                <div class="form-group">
                                    <label><i class="fas fa-align-left"></i> Description</label>
                                    <textarea name="description" placeholder="Description of how to earn this achievement..."><?php echo htmlspecialchars($edit_achievement['description'] ?? ''); ?></textarea>
                                    <div class="form-help">Explain what students need to do to earn this</div>
                                </div>
                                
                                <div class="form-row">
                                    <div class="form-group">
                                        <label><i class="fas fa-star"></i> Points <span class="required"></span></label>
                                        <input type="number" name="points" min="1" max="1000" required value="<?php echo $edit_achievement['points'] ?? 10; ?>" placeholder="10">
                                        <div class="form-help">Points awarded when unlocked (1-1000)</div>
                                    </div>
                                    
                                    <div class="form-group">
                                        <label><i class="fas fa-toggle-on"></i> Status</label>
                                        <select name="is_active" style="height: 46px;">
                                            <option value="1" <?php echo ($edit_achievement['is_active'] ?? 1) ? 'selected' : ''; ?>>Active</option>
                                            <option value="0" <?php echo !($edit_achievement['is_active'] ?? 1) ? 'selected' : ''; ?>>Inactive</option>
                                        </select>
                                        <div class="form-help">Inactive achievements won't be awarded</div>
                                    </div>
                                </div>
                                
                                <div class="form-row">
                                    <div class="form-group">
                                        <label><i class="fas fa-bullseye"></i> Criteria Type</label>
                                        <select name="criteria_type" id="criteriaType" style="height: 46px;">
                                            <option value="">No Criteria (Manual Award)</option>
                                            <?php foreach ($common_criteria as $value => $label): ?>
                                                <option value="<?php echo htmlspecialchars($value); ?>" 
                                                    <?php echo ($edit_achievement['criteria_type'] ?? '') === $value ? 'selected' : ''; ?>>
                                                    <?php echo htmlspecialchars($label); ?>
                                                </option>
                                            <?php endforeach; ?>
                                            <option value="custom">Custom Criteria</option>
                                        </select>
                                        <div class="form-help">How students earn this achievement</div>
                                    </div>
                                    
                                    <div class="form-group">
                                        <label><i class="fas fa-bullseye"></i> Criteria Value</label>
                                        <input type="text" name="criteria_value" id="criteriaValue" 
                                            value="<?php echo htmlspecialchars($edit_achievement['criteria_value'] ?? ''); ?>" 
                                            placeholder="e.g., 10">
                                        <div class="form-help">Target value (e.g., complete 10 goals)</div>
                                    </div>
                                </div>
                                
                                <div class="form-row">
                                    <div class="form-group">
                                        <label><i class="fas fa-icons"></i> Icon</label>
                                        <select name="icon" id="iconSelect" style="height: 46px;">
                                            <option value="trophy" <?php echo ($edit_achievement['icon'] ?? 'trophy') === 'trophy' ? 'selected' : ''; ?>>🏆 Trophy</option>
                                            <option value="medal" <?php echo ($edit_achievement['icon'] ?? '') === 'medal' ? 'selected' : ''; ?>>🥇 Medal</option>
                                            <option value="star" <?php echo ($edit_achievement['icon'] ?? '') === 'star' ? 'selected' : ''; ?>>⭐ Star</option>
                                            <option value="crown" <?php echo ($edit_achievement['icon'] ?? '') === 'crown' ? 'selected' : ''; ?>>👑 Crown</option>
                                            <option value="award" <?php echo ($edit_achievement['icon'] ?? '') === 'award' ? 'selected' : ''; ?>>🏅 Award</option>
                                            <option value="fire" <?php echo ($edit_achievement['icon'] ?? '') === 'fire' ? 'selected' : ''; ?>>🔥 Fire</option>
                                            <option value="rocket" <?php echo ($edit_achievement['icon'] ?? '') === 'rocket' ? 'selected' : ''; ?>>🚀 Rocket</option>
                                            <option value="gem" <?php echo ($edit_achievement['icon'] ?? '') === 'gem' ? 'selected' : ''; ?>>💎 Gem</option>
                                        </select>
                                        <div class="form-help">Choose an icon for the badge</div>
                                    </div>
                                    
                                    <div class="form-group">
                                        <label><i class="fas fa-palette"></i> Color</label>
                                        <input type="color" name="color" id="colorPicker" 
                                            value="<?php echo htmlspecialchars($edit_achievement['color'] ?? '#f59e0b'); ?>" 
                                            style="width: 100%; height: 46px; border-radius: var(--radius-sm); padding: 5px;">
                                        <div class="form-help">Choose badge color</div>
                                    </div>
                                </div>
                                
                                <div class="form-group">
                                    <label><i class="fas fa-eye"></i> Preview</label>
                                    <div id="iconPreview" class="icon-preview" 
                                        style="background: <?php echo htmlspecialchars($edit_achievement['color'] ?? '#f59e0b'); ?>; color: white;">
                                        <i class="fas fa-<?php echo htmlspecialchars($edit_achievement['icon'] ?? 'trophy'); ?>"></i>
                                    </div>
                                    <div class="form-help">This is how the badge will look to students</div>
                                </div>
                                
                                <button type="submit" class="btn btn-primary" style="width:100%;">
                                    <i class="fas fa-save"></i> <?php echo $edit_achievement_id === 'new' ? 'Add Achievement' : 'Update Achievement'; ?>
                                </button>
                            </form>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

            <div class="filters-section">
                <form method="GET" id="filterForm">
                    <div class="filter-grid">
                        <div class="filter-group">
                            <label><i class="fas fa-search"></i> Search</label>
                            <input type="text" name="search" placeholder="Search achievements..." value="<?php echo htmlspecialchars($search); ?>">
                        </div>
                        <div class="filter-group">
                            <label><i class="fas fa-toggle-on"></i> Status</label>
                            <select name="status">
                                <option value="all" <?php echo $status_filter === 'all' ? 'selected' : ''; ?>>All Status</option>
                                <option value="active" <?php echo $status_filter === 'active' ? 'selected' : ''; ?>>Active</option>
                                <option value="inactive" <?php echo $status_filter === 'inactive' ? 'selected' : ''; ?>>Inactive</option>
                            </select>
                        </div>
                        <div class="filter-group">
                            <label><i class="fas fa-bullseye"></i> Criteria Type</label>
                            <select name="criteria">
                                <option value="all" <?php echo $criteria_filter === 'all' ? 'selected' : ''; ?>>All Criteria</option>
                                <?php foreach ($criteria_types as $type): ?>
                                    <option value="<?php echo htmlspecialchars($type); ?>" <?php echo $criteria_filter === $type ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($type); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="filter-group">
                            <label><i class="fas fa-sort"></i> Sort By</label>
                            <select name="sort_by">
                                <option value="created_at" <?php echo $sort_by === 'created_at' ? 'selected' : ''; ?>>Created Date</option>
                                <option value="title" <?php echo $sort_by === 'title' ? 'selected' : ''; ?>>Title</option>
                                <option value="points" <?php echo $sort_by === 'points' ? 'selected' : ''; ?>>Points</option>
                                <option value="unlocked_count" <?php echo $sort_by === 'unlocked_count' ? 'selected' : ''; ?>>Unlocked Count</option>
                                <option value="criteria_type" <?php echo $sort_by === 'criteria_type' ? 'selected' : ''; ?>>Criteria Type</option>
                            </select>
                        </div>
                        <div class="filter-group">
                            <label><i class="fas fa-sort-amount-down"></i> Sort Order</label>
                            <select name="sort_order">
                                <option value="desc" <?php echo $sort_order === 'desc' ? 'selected' : ''; ?>>Descending</option>
                                <option value="asc" <?php echo $sort_order === 'asc' ? 'selected' : ''; ?>>Ascending</option>
                            </select>
                        </div>
                        <div class="filter-group">
                            <button type="submit" class="btn btn-primary" style="width: 100%;"><i class="fas fa-search"></i> Apply Filters</button>
                        </div>
                        <?php if ($search || $status_filter !== 'all' || $criteria_filter !== 'all'): ?>
                            <div class="filter-group">
                                <a href="achievements.php" class="btn btn-outline" style="width: 100%;"><i class="fas fa-times"></i> Clear Filters</a>
                            </div>
                        <?php endif; ?>
                    </div>
                </form>
            </div>

            <!-- Bulk Actions -->
            <div class="bulk-actions" id="bulkActions">
                <div class="bulk-select">
                    <input type="checkbox" id="selectAll" class="select-all-checkbox">
                    <span id="selectedCount">0 achievements selected</span>
                </div>
                <select id="bulkActionSelect" class="btn btn-outline">
                    <option value="">Bulk Actions</option>
                    <option value="activate">Activate Selected</option>
                    <option value="deactivate">Deactivate Selected</option>
                    <option value="delete">Delete Selected</option>
                </select>
                <button id="applyBulkAction" class="btn btn-primary">Apply</button>
                <button id="clearSelection" class="btn btn-outline">Clear Selection</button>
            </div>

            <div class="table-container">
                <?php if (empty($achievements)): ?>
                    <div class="empty-state">
                        <i class="fas fa-trophy"></i>
                        <p>No achievements found</p>
                        <p style="font-size: 14px; margin-top: 10px;">
                            <?php if ($search || $status_filter !== 'all' || $criteria_filter !== 'all'): ?>
                                Try adjusting your filters or <a href="achievements.php">clear all filters</a>
                            <?php else: ?>
                                <a href="?edit=new" class="btn btn-primary" style="margin-top: 15px;">Create Your First Achievement</a>
                            <?php endif; ?>
                        </p>
                    </div>
                <?php else: ?>
                    <table id="achievementsTable">
                        <thead>
                            <tr>
                                <th width="50"><input type="checkbox" id="selectAllHeader"></th>
                                <th>Achievement</th>
                                <th>Points</th>
                                <th>Criteria</th>
                                <th>Unlocked By</th>
                                <th>Status</th>
                                <th>Created</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($achievements as $achievement): 
                                $is_active = $achievement['is_active'] ?? 1;
                                $recent_unlockers = $achievement['recent_unlockers'] ?? '';
                            ?>
                                <tr>
                                    <td><input type="checkbox" class="achievement-checkbox" data-achievement-id="<?php echo $achievement['id']; ?>"></td>
                                    <td>
                                        <div class="achievement-badge">
                                            <div class="badge-icon" style="background: <?php echo htmlspecialchars($achievement['color'] ?? '#f59e0b'); ?>;">
                                                <i class="fas fa-<?php echo htmlspecialchars($achievement['icon'] ?? 'trophy'); ?>"></i>
                                            </div>
                                            <div>
                                                <div style="font-weight: 600;"><?php echo htmlspecialchars($achievement['title']); ?></div>
                                                <div style="font-size: 13px; color: var(--gray-500); margin-top: 4px;">
                                                    <?php echo htmlspecialchars($achievement['description']); ?>
                                                </div>
                                                <?php if ($recent_unlockers): ?>
                                                    <div style="font-size: 12px; color: var(--success); margin-top: 4px;">
                                                        <i class="fas fa-user-check"></i> Recently unlocked by: <?php echo htmlspecialchars($recent_unlockers); ?>
                                                    </div>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </td>
                                    <td>
                                        <div style="text-align: center;">
                                            <div style="font-weight: 700; font-size: 20px; color: var(--success);">
                                                <?php echo $achievement['points']; ?>
                                            </div>
                                            <div style="font-size: 11px; color: var(--gray-500);">points</div>
                                        </div>
                                    </td>
                                    <td>
                                        <div style="font-size: 14px;">
                                            <?php if ($achievement['criteria_type']): ?>
                                                <div><strong>Type:</strong> <?php echo htmlspecialchars($achievement['criteria_type']); ?></div>
                                                <div><strong>Value:</strong> <?php echo htmlspecialchars($achievement['criteria_value']); ?></div>
                                            <?php else: ?>
                                                <span style="color: var(--gray-500);">Manual award</span>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                    <td>
                                        <div style="text-align: center;">
                                            <div style="font-weight: 700; font-size: 24px; color: var(--primary);">
                                                <?php echo $achievement['unlocked_count']; ?>
                                            </div>
                                            <div style="font-size: 12px; color: var(--gray-500);">
                                                students
                                            </div>
                                        </div>
                                    </td>
                                    <td>
                                        <span class="status-badge status-<?php echo $is_active ? 'active' : 'inactive'; ?>">
                                            <?php echo $is_active ? 'Active' : 'Inactive'; ?>
                                        </span>
                                    </td>
                                    <td>
                                        <div style="font-size: 14px;">
                                            <?php echo date('M d, Y', strtotime($achievement['created_at'])); ?>
                                            <div style="color: var(--gray-500); font-size: 12px;">
                                                <?php echo date('h:i A', strtotime($achievement['created_at'])); ?>
                                            </div>
                                        </div>
                                    </td>
                                    <td>
                                        <div class="action-buttons">
                                            <a href="?edit=<?php echo $achievement['id']; ?>" class="btn btn-sm btn-outline" title="Edit">
                                                <i class="fas fa-edit"></i>
                                            </a>
                                            <?php if ($is_active): ?>
                                                <form method="POST" style="display:inline;">
                                                    <input type="hidden" name="action" value="edit_achievement">
                                                    <input type="hidden" name="achievement_id" value="<?php echo $achievement['id']; ?>">
                                                    <input type="hidden" name="is_active" value="0">
                                                    <button type="submit" class="btn btn-sm btn-warning" title="Deactivate" onclick="return confirm('Deactivate this achievement?')">
                                                        <i class="fas fa-ban"></i>
                                                    </button>
                                                </form>
                                            <?php else: ?>
                                                <form method="POST" style="display:inline;">
                                                    <input type="hidden" name="action" value="edit_achievement">
                                                    <input type="hidden" name="achievement_id" value="<?php echo $achievement['id']; ?>">
                                                    <input type="hidden" name="is_active" value="1">
                                                    <button type="submit" class="btn btn-sm btn-success" title="Activate" onclick="return confirm('Activate this achievement?')">
                                                        <i class="fas fa-check"></i>
                                                    </button>
                                                </form>
                                            <?php endif; ?>
                                            <form method="POST" style="display:inline;">
                                                <input type="hidden" name="action" value="delete_achievement">
                                                <input type="hidden" name="achievement_id" value="<?php echo $achievement['id']; ?>">
                                                <button type="submit" class="btn btn-sm btn-danger" onclick="return confirm('Delete this achievement and all unlock records? This cannot be undone.')" title="Delete">
                                                    <i class="fas fa-trash"></i>
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

            <div style="margin-top: 40px; display: flex; gap: 16px; flex-wrap: wrap;">
                <a href="?edit=new" class="btn btn-primary"><i class="fas fa-plus"></i> Add Achievement</a>
                <form method="POST" style="display:inline;">
                    <input type="hidden" name="action" value="recalculate_all">
                    <button type="submit" class="btn btn-outline" onclick="return confirm('Recalculate achievements for all students?')">
                        <i class="fas fa-sync-alt"></i> Recalculate All
                    </button>
                </form>
                <a href="reports.php?type=achievements" class="btn btn-outline"><i class="fas fa-chart-bar"></i> Achievement Reports</a>
                <button class="btn btn-outline" onclick="window.print()"><i class="fas fa-print"></i> Print</button>
            </div>
        </main>
    </div>

    <script>
        // Sidebar toggle
        const sidebarToggle = document.getElementById('sidebarToggle');
        const sidebar = document.getElementById('sidebar');
        const sidebarClose = document.getElementById('sidebarClose');
        const overlay = document.getElementById('sidebarOverlay');

        sidebarToggle?.addEventListener('click', () => { sidebar.classList.add('active'); overlay.classList.add('active'); });
        sidebarClose?.addEventListener('click', () => { sidebar.classList.remove('active'); overlay.classList.remove('active'); });
        overlay?.addEventListener('click', () => { sidebar.classList.remove('active'); overlay.classList.remove('active'); });

        // Icon and color preview
        const iconSelect = document.getElementById('iconSelect');
        const colorPicker = document.getElementById('colorPicker');
        const iconPreview = document.getElementById('iconPreview');

        function updatePreview() {
            if (iconSelect && colorPicker && iconPreview) {
                const icon = iconSelect.value;
                const color = colorPicker.value;
                iconPreview.style.background = color;
                iconPreview.innerHTML = `<i class="fas fa-${icon}"></i>`;
            }
        }

        if (iconSelect) iconSelect.addEventListener('change', updatePreview);
        if (colorPicker) colorPicker.addEventListener('input', updatePreview);
        
        // Update preview on page load
        updatePreview();

        // Criteria type and value handling
        const criteriaType = document.getElementById('criteriaType');
        const criteriaValue = document.getElementById('criteriaValue');
        const commonCriteria = <?php echo json_encode($common_criteria); ?>;

        if (criteriaType) {
            criteriaType.addEventListener('change', function() {
                if (this.value === 'custom') {
                    criteriaValue.value = '';
                    criteriaValue.placeholder = 'Enter custom criteria...';
                } else if (this.value && commonCriteria[this.value]) {
                    // Set default value based on criteria type
                    switch(this.value) {
                        case 'goals_completed':
                            criteriaValue.value = '5';
                            break;
                        case 'streak_days':
                            criteriaValue.value = '7';
                            break;
                        case 'total_points':
                            criteriaValue.value = '100';
                            break;
                        case 'perfect_goals':
                            criteriaValue.value = '3';
                            break;
                        case 'early_completion':
                            criteriaValue.value = '5';
                            break;
                        case 'community_help':
                            criteriaValue.value = '10';
                            break;
                        default:
                            criteriaValue.value = '';
                    }
                } else if (!this.value) {
                    criteriaValue.value = '';
                    criteriaValue.placeholder = 'No criteria needed';
                }
            });
        }

        // Form validation
        const achievementForm = document.getElementById('achievementForm');
        if (achievementForm) {
            achievementForm.addEventListener('submit', function(e) {
                const points = this.querySelector('input[name="points"]');
                const title = this.querySelector('input[name="title"]');
                
                if (points && (parseInt(points.value) < 1 || parseInt(points.value) > 1000)) {
                    e.preventDefault();
                    alert('Points must be between 1 and 1000.');
                    return false;
                }
                
                if (title && !title.value.trim()) {
                    e.preventDefault();
                    alert('Title is required.');
                    return false;
                }
                
                // Show loading
                const submitBtn = this.querySelector('button[type="submit"]');
                const originalText = submitBtn.innerHTML;
                submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving...';
                submitBtn.disabled = true;
                
                setTimeout(() => {
                    submitBtn.disabled = false;
                    submitBtn.innerHTML = originalText;
                }, 3000);
            });
        }

        // Bulk actions
        const bulkActions = document.getElementById('bulkActions');
        const selectAll = document.getElementById('selectAllHeader');
        const selectAllCheckbox = document.getElementById('selectAll');
        const selectedCount = document.getElementById('selectedCount');
        const clearSelectionBtn = document.getElementById('clearSelection');
        const applyBulkActionBtn = document.getElementById('applyBulkAction');
        const bulkActionSelect = document.getElementById('bulkActionSelect');

        let selectedAchievements = [];

        function updateBulkActions() {
            const checkboxes = document.querySelectorAll('#achievementsTable .achievement-checkbox:checked');
            selectedAchievements = Array.from(checkboxes).map(cb => cb.dataset.achievementId);
            
            if (selectedAchievements.length > 0) {
                bulkActions.style.display = 'flex';
                selectedCount.textContent = `${selectedAchievements.length} achievement${selectedAchievements.length !== 1 ? 's' : ''} selected`;
                selectAll.checked = checkboxes.length === document.querySelectorAll('#achievementsTable .achievement-checkbox').length;
                selectAllCheckbox.checked = selectAll.checked;
            } else {
                bulkActions.style.display = 'none';
            }
        }

        // Select all checkboxes
        selectAll?.addEventListener('change', function() {
            const checkboxes = document.querySelectorAll('#achievementsTable .achievement-checkbox');
            checkboxes.forEach(cb => cb.checked = this.checked);
            selectAllCheckbox.checked = this.checked;
            updateBulkActions();
        });

        selectAllCheckbox?.addEventListener('change', function() {
            const checkboxes = document.querySelectorAll('#achievementsTable .achievement-checkbox');
            checkboxes.forEach(cb => cb.checked = this.checked);
            selectAll.checked = this.checked;
            updateBulkActions();
        });

        // Individual checkbox changes
        document.addEventListener('change', function(e) {
            if (e.target.classList.contains('achievement-checkbox')) {
                updateBulkActions();
            }
        });

        // Clear selection
        clearSelectionBtn?.addEventListener('click', function() {
            const checkboxes = document.querySelectorAll('#achievementsTable .achievement-checkbox');
            checkboxes.forEach(cb => cb.checked = false);
            selectAll.checked = false;
            selectAllCheckbox.checked = false;
            updateBulkActions();
        });

        // Apply bulk action
        applyBulkActionBtn?.addEventListener('click', function() {
            const action = bulkActionSelect.value;
            if (!action) {
                alert('Please select a bulk action.');
                return;
            }
            
            if (!selectedAchievements.length) {
                alert('Please select at least one achievement.');
                return;
            }
            
            if (!confirm(`Are you sure you want to ${action} ${selectedAchievements.length} achievement(s)?`)) {
                return;
            }
            
            // Create form and submit
            const form = document.createElement('form');
            form.method = 'POST';
            form.style.display = 'none';
            
            const actionInput = document.createElement('input');
            actionInput.type = 'hidden';
            actionInput.name = 'action';
            actionInput.value = 'bulk_action';
            form.appendChild(actionInput);
            
            const bulkActionInput = document.createElement('input');
            bulkActionInput.type = 'hidden';
            bulkActionInput.name = 'bulk_action';
            bulkActionInput.value = action;
            form.appendChild(bulkActionInput);
            
            selectedAchievements.forEach(achievementId => {
                const input = document.createElement('input');
                input.type = 'hidden';
                input.name = 'achievement_ids[]';
                input.value = achievementId;
                form.appendChild(input);
            });
            
            document.body.appendChild(form);
            form.submit();
        });

        // Filter form auto-save
        function saveFilters() {
            const form = document.getElementById('filterForm');
            if (!form) return;
            
            const formData = new FormData(form);
            const data = {};
            formData.forEach((value, key) => {
                data[key] = value;
            });
            
            localStorage.setItem('achievementFilters', JSON.stringify(data));
        }

        function loadFilters() {
            const saved = localStorage.getItem('achievementFilters');
            if (saved) {
                const data = JSON.parse(saved);
                const form = document.getElementById('filterForm');
                
                Object.keys(data).forEach(key => {
                    const input = form.querySelector(`[name="${key}"]`);
                    if (input) {
                        if (input.type === 'checkbox' || input.type === 'radio') {
                            input.checked = data[key];
                        } else {
                            input.value = data[key];
                        }
                    }
                });
            }
        }

        // Auto-save filters
        document.getElementById('filterForm')?.addEventListener('input', saveFilters);
        window.addEventListener('beforeunload', saveFilters);

        // Initialize
        loadFilters();
        updateBulkActions();

        // Real-time clock in header
        function updateClock() {
            const now = new Date();
            const timeString = now.toLocaleTimeString('en-US', {
                hour12: true,
                hour: '2-digit',
                minute: '2-digit'
            });
            const dateString = now.toLocaleDateString('en-US', {
                weekday: 'short',
                month: 'short',
                day: 'numeric'
            });
            
            const clockElement = document.createElement('div');
            clockElement.style.cssText = `
                font-family: 'Courier New', monospace;
                font-size: 14px;
                font-weight: 600;
                padding: 8px 16px;
                background: var(--gray-900);
                color: white;
                border-radius: var(--radius-sm);
                display: inline-flex;
                align-items: center;
                gap: 10px;
                margin-left: auto;
            `;
            clockElement.innerHTML = `<i class="fas fa-clock"></i> ${dateString} ${timeString}`;
            
            const header = document.querySelector('.page-header');
            if (header) {
                const existingClock = document.querySelector('.page-header .timer-display');
                if (existingClock) {
                    existingClock.remove();
                }
                header.appendChild(clockElement);
            }
        }
        setInterval(updateClock, 60000);
        updateClock();
    </script>
</body>
</html>