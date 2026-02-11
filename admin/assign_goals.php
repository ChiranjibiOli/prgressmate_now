<?php
session_start();
require_once '../includes/db_connection.php';
checkAuth('admin');

// Award function (keep in shared include or here)
function award_goal_completion_achievement(int $student_goal_id, PDO $pdo): void
{
    $stmt = $pdo->prepare("
        SELECT sg.student_id, ag.achievement_id, ach.points
        FROM student_goals sg
        JOIN admin_goals ag ON sg.goal_id = ag.id
        LEFT JOIN achievements ach ON ag.achievement_id = ach.id
        WHERE sg.id = ? AND sg.status = 'completed' AND ag.achievement_id IS NOT NULL
    ");
    $stmt->execute([$student_goal_id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) return;

    $student_id = $row['student_id'];
    $achievement_id = $row['achievement_id'];
    $points = (int)$row['points'];

    $check = $pdo->prepare("SELECT 1 FROM user_achievements WHERE user_id = ? AND achievement_id = ? LIMIT 1");
    $check->execute([$student_id, $achievement_id]);
    if ($check->fetch()) return;

    $pdo->beginTransaction();
    try {
        $pdo->prepare("
            INSERT INTO user_achievements (user_id, achievement_id, earned_at, awarded_at)
            VALUES (?, ?, NOW(), NOW())
        ")->execute([$student_id, $achievement_id]);

        if ($points > 0) {
            $pdo->prepare("UPDATE users SET points = points + ? WHERE id = ?")->execute([$points, $student_id]);
        }

        $pdo->prepare("
            INSERT INTO notifications (user_id, title, message, type, related_id, related_type, created_at)
            VALUES (?, 'Achievement Unlocked!', 'You completed a goal and earned a badge!', 'achievement', ?, 'goal_completion', NOW())
        ")->execute([$student_id, $student_goal_id]);

        $pdo->commit();
    } catch (Exception $e) {
        $pdo->rollBack();
        error_log('Achievement award failed: ' . $e->getMessage());
    }
}

// POST handling
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $success = $error = '';
    try {
        if ($action === 'assign_system_goal') {
            if (empty($_POST['goal_id']) || empty($_POST['student_ids'])) {
                throw new Exception('Please select a goal and at least one student.');
            }

            $goal_id = (int)$_POST['goal_id'];
            $due_date = $_POST['due_date'] ? date('Y-m-d', strtotime($_POST['due_date'])) : null;
            $priority = $_POST['priority'] ?? 'medium';

            if ($due_date && strtotime($due_date) < strtotime('today')) {
                throw new Exception('Due date cannot be in the past.');
            }

         $goalQ = $pdo->prepare("SELECT title, description, category, target_value, unit
                        FROM admin_goals WHERE id=? AND status='active'");
$goalQ->execute([$goal_id]);
$g = $goalQ->fetch(PDO::FETCH_ASSOC);
if (!$g) throw new Exception("Goal not found.");

$stmt = $pdo->prepare("
    INSERT INTO student_goals
    (student_id, goal_id, title, description, category, target_value, unit,
     due_date, priority, assigned_by, assigned_at, is_self_created, status, created_at)
    VALUES
    (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), 0, 'pending', NOW())
");


            $assigned_count = 0;
            foreach ($_POST['student_ids'] as $student_id) {
                $student_id = (int)$student_id;
                if ($student_id > 0) {
                   $stmt->execute([
    $student_id, $goal_id,
    $g['title'], $g['description'], $g['category'], $g['target_value'], $g['unit'],
    $due_date, $priority, $_SESSION['user_id']
]);

                    $assigned_count++;
                }
            }

            if ($assigned_count === 0) {
                throw new Exception('No valid students selected.');
            }

            $success = "Goal assigned to {$assigned_count} student(s).";

        } elseif ($action === 'create_and_assign') {
            $required = ['title', 'target_value', 'unit', 'student_ids'];
            foreach ($required as $field) {
                if (empty($_POST[$field])) {
                    throw new Exception("Please fill all required fields.");
                }
            }

            $title = trim($_POST['title']);
            $target_value = (float)$_POST['target_value'];
            $unit = trim($_POST['unit']);
            $description = trim($_POST['description'] ?? '');
            $category = trim($_POST['category'] ?? '');
            $due_date = $_POST['due_date'] ? date('Y-m-d', strtotime($_POST['due_date'])) : null;
            $priority = $_POST['priority'] ?? 'medium';
            $achievement_id = !empty($_POST['achievement_id']) ? (int)$_POST['achievement_id'] : null;

            if ($target_value <= 0) {
                throw new Exception('Target value must be greater than 0.');
            }

            $pdo->beginTransaction();

            $stmt = $pdo->prepare("
                INSERT INTO admin_goals
                (title, description, category, target_value, unit, due_date, priority, status, created_by, created_at, achievement_id)
                VALUES (?, ?, ?, ?, ?, ?, ?, 'active', ?, NOW(), ?)
            ");
            $stmt->execute([
                $title, $description, $category, $target_value, $unit, $due_date, $priority,
                $_SESSION['user_id'], $achievement_id
            ]);

            $new_goal_id = $pdo->lastInsertId();

          $assign_stmt = $pdo->prepare("
    INSERT INTO student_goals
    (student_id, goal_id, title, description, category, target_value, unit,
     due_date, priority, assigned_by, assigned_at, is_self_created, status, created_at)
    VALUES
    (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), 0, 'pending', NOW())
");


            $assigned_count = 0;
            foreach ($_POST['student_ids'] as $student_id) {
                $student_id = (int)$student_id;
                if ($student_id > 0) {
                    $assign_stmt->execute([
    $student_id, $new_goal_id,
    $title, $description, $category, $target_value, $unit,
    $due_date, $priority, $_SESSION['user_id']
]);

                    $assigned_count++;
                }
            }

            if ($assigned_count === 0) {
                throw new Exception('No valid students selected.');
            }

            $pdo->commit();
            $success = "New goal created and assigned to {$assigned_count} student(s).";
        }
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $error = $e->getMessage();
    }

    $_SESSION['success'] = $success;
    $_SESSION['error'] = $error;
    header('Location: assign_goals.php');
    exit();
}

// Flash messages
$success = $_SESSION['success'] ?? '';
$error = $_SESSION['error'] ?? '';
unset($_SESSION['success'], $_SESSION['error']);

// Data fetching
$system_goals = $pdo->query("
    SELECT ag.*, c.name as category_name, c.color as category_color,
           ach.title as achievement_title, ach.points as achievement_points,
           ach.icon as achievement_icon, ach.color as achievement_color
    FROM admin_goals ag
    LEFT JOIN categories c ON ag.category = c.name
    LEFT JOIN achievements ach ON ag.achievement_id = ach.id
    WHERE ag.status = 'active'
    ORDER BY ag.title ASC
")->fetchAll(PDO::FETCH_ASSOC);

$achievements_list = $pdo->query("
    SELECT id, title, points, icon, color
    FROM achievements
    WHERE is_active = 1 AND deleted_at IS NULL
    ORDER BY title
")->fetchAll(PDO::FETCH_ASSOC);

$students = $pdo->query("
    SELECT id, name, email, department, semester, profile_picture,
           (SELECT COUNT(*) FROM student_goals WHERE student_id = users.id) as active_goals,
           (SELECT COUNT(*) FROM student_goals WHERE student_id = users.id AND status = 'completed') as completed_goals
    FROM users
    WHERE role = 'student' AND status = 'active'
    ORDER BY name ASC
")->fetchAll(PDO::FETCH_ASSOC);

$categories = $pdo->query("SELECT name, color FROM categories WHERE is_global = 1 ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);

$selected_goal = null;
if (!empty($_GET['goal_id'])) {
    $goal_id = (int)$_GET['goal_id'];
    $stmt = $pdo->prepare("
        SELECT ag.*, c.name as category_name, c.color as category_color,
               ach.title as achievement_title, ach.points as achievement_points,
               ach.icon as achievement_icon, ach.color as achievement_color
        FROM admin_goals ag
        LEFT JOIN categories c ON ag.category = c.name
        LEFT JOIN achievements ach ON ag.achievement_id = ach.id
        WHERE ag.id = ? AND ag.status = 'active'
    ");
    $stmt->execute([$goal_id]);
    $selected_goal = $stmt->fetch(PDO::FETCH_ASSOC);
}

$recent_assignments = $pdo->query("
    SELECT sg.*, u.name AS student_name, u.email AS student_email, u.profile_picture,
           ag.title AS goal_title, ag.target_value, ag.unit,
           ach.title AS achievement_title, ach.points AS achievement_points,
           ach.icon AS achievement_icon, ach.color AS achievement_color,
           admin.name AS assigned_by_name,
           sg.created_at AS assigned_at,
           DATEDIFF(sg.due_date, CURDATE()) as days_left
    FROM student_goals sg
    JOIN users u ON sg.student_id = u.id
    JOIN admin_goals ag ON sg.goal_id = ag.id
    LEFT JOIN achievements ach ON ag.achievement_id = ach.id
    LEFT JOIN users admin ON sg.assigned_by = admin.id
    ORDER BY sg.created_at DESC
    LIMIT 15
")->fetchAll(PDO::FETCH_ASSOC);

$total_assignments = $pdo->query("SELECT COUNT(*) FROM student_goals")->fetchColumn() ?: 0;
$pending_assignments = $pdo->query("SELECT COUNT(*) FROM student_goals WHERE status = 'pending'")->fetchColumn() ?: 0;
$completed_assignments = $pdo->query("SELECT COUNT(*) FROM student_goals WHERE status = 'completed'")->fetchColumn() ?: 0;

$sidebar_stats = [
    'students' => $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'student' AND status = 'active'")->fetchColumn() ?: 0,
    'goals' => $pdo->query("SELECT COUNT(*) FROM admin_goals WHERE status = 'active'")->fetchColumn() ?: 0,
    'assigned' => $total_assignments,
    'points' => $pdo->query("SELECT COALESCE(SUM(points), 0) FROM users WHERE role = 'student'")->fetchColumn() ?: 0,
    'pending' => $pending_assignments,
    'completed' => $completed_assignments
];
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Assign Goals - ProgressMate</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/select2/4.0.13/css/select2.min.css">
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
            --gray-100: #f9fafb;
            --gray-200: #f3f4f6;
            --gray-300: #e5e7eb;
            --gray-400: #d1d5db;
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
        .dashboard-wrapper { display: flex; min-height: 100vh; }
        .sidebar { width: 280px; background: white; border-right: 1px solid var(--gray-300); position: fixed; height: 100vh; z-index: 1000; display: flex; flex-direction: column; box-shadow: var(--shadow); }
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
        .stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 24px; margin-bottom: 40px; }
        .stat-card { background: white; border-radius: var(--radius); padding: 24px; box-shadow: var(--shadow); text-align: center; }
        .stat-number { font-size: 32px; font-weight: 800; }
        .stat-label { font-size: 15px; color: var(--gray-500); }
        .alert { padding: 16px; border-radius: var(--radius); margin-bottom: 24px; display: flex; align-items: center; gap: 12px; font-weight: 500; box-shadow: var(--shadow-sm); }
        .alert-success { background: var(--success-light); color: #065f46; border-left: 5px solid var(--success); }
        .alert-error { background: var(--danger-light); color: #991b1b; border-left: 5px solid var(--danger); }
        .tabs { display: flex; gap: 8px; margin-bottom: 32px; border-bottom: 1px solid var(--gray-300); }
        .tab { padding: 16px 24px; background: transparent; border: none; font-weight: 600; color: var(--gray-500); cursor: pointer; border-bottom: 3px solid transparent; transition: var(--transition); }
        .tab.active { color: var(--primary); border-bottom-color: var(--primary); }
        .tab-badge { position: absolute; top: 8px; right: 8px; background: var(--primary); color: white; font-size: 10px; padding: 2px 6px; border-radius: 10px; }
        .tab-content { display: none; }
        .tab-content.active { display: block; }
        .form-card { background: white; border-radius: var(--radius); padding: 32px; box-shadow: var(--shadow); }
        .form-group { margin-bottom: 24px; }
        .form-group label { display: block; margin-bottom: 8px; font-weight: 500; color: var(--gray-700); }
        .required::after { content: " *"; color: var(--danger); }
        .form-group input, .form-group select, .form-group textarea { width: 100%; padding: 14px; border: 1px solid var(--gray-300); border-radius: 8px; font-size: 15px; }
        .form-group textarea { min-height: 120px; resize: vertical; }
        .form-row { display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 24px; }
        .goal-preview { background: var(--gray-100); padding: 24px; border-radius: var(--radius); margin: 20px 0; border-left: 5px solid var(--primary); }
        .achievement-preview { text-align: center; padding: 20px; background: var(--primary-light); border-radius: var(--radius); margin-top: 20px; }
        .badge-large { width: 100px; height: 100px; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 48px; color: white; margin: 0 auto 15px; box-shadow: var(--shadow); }
        .countdown { font-size: 16px; font-weight: 600; padding: 8px 16px; border-radius: var(--radius-sm); display: inline-flex; align-items: center; gap: 8px; }
        .countdown.expired { background: var(--danger-light); color: var(--danger); }
        .countdown.soon { background: var(--warning-light); color: var(--warning); }
        .countdown.safe { background: var(--success-light); color: var(--success); }
        .mobile-toggle { display: none; position: fixed; top: 20px; left: 20px; z-index: 1100; background: var(--primary); color: white; border: none; width: 48px; height: 48px; border-radius: 12px; font-size: 20px; cursor: pointer; box-shadow: var(--shadow); }
        .sidebar-overlay { display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.5); z-index: 999; }
        @media (max-width: 768px) {
            .sidebar { transform: translateX(-100%); width: 300px; }
            .sidebar.active { transform: translateX(0); }
            .sidebar-overlay.active { display: block; }
            .sidebar-close { display: block; }
            .mobile-toggle { display: flex; align-items: center; justify-content: center; }
            .main-content { margin-left: 0; padding: 24px 16px; padding-top: 80px; }
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
                    <span style="font-size: 12px; background: var(--primary-light); color: var(--primary); padding: 4px 10px; border-radius: 20px; font-weight: 600;">ADMIN</span>
                </div>
            </div>
            <nav class="nav-menu">
                <a href="admin.php" class="nav-link"><i class="fas fa-tachometer-alt"></i> Dashboard</a>
                <a href="students.php" class="nav-link"><i class="fas fa-users"></i> Students <span class="badge"><?php echo $sidebar_stats['students']; ?></span></a>
                <a href="goals.php" class="nav-link"><i class="fas fa-bullseye"></i> System Goals <span class="badge"><?php echo $sidebar_stats['goals']; ?></span></a>
                <a href="assign_goals.php" class="nav-link active"><i class="fas fa-tasks"></i> Assign Goals</a>
                <a href="achievements.php" class="nav-link"><i class="fas fa-trophy"></i> Achievements</a>
                <a href="reports.php" class="nav-link"><i class="fas fa-chart-bar"></i> Reports</a>
                <a href="notifications.php" class="nav-link"><i class="fas fa-bell"></i> Notifications</a>
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
                    <div><div class="sidebar-stat-label">Active Goals</div><div class="sidebar-stat-number"><?php echo $sidebar_stats['goals']; ?></div></div>
                </div>
                <div class="sidebar-stat">
                    <div class="sidebar-stat-icon"><i class="fas fa-check-circle"></i></div>
                    <div><div class="sidebar-stat-label">Completed</div><div class="sidebar-stat-number"><?php echo $sidebar_stats['completed']; ?></div></div>
                </div>
            </div>
            <div class="sidebar-footer">
                <a href="../logout.php" class="logout-btn"><i class="fas fa-sign-out-alt"></i> Logout</a>
            </div>
        </aside>

        <main class="main-content">
            <header class="page-header">
                <div class="header-content">
                    <h1>Assign Goals</h1>
                    <p>Assign system goals to students. Goals with linked achievements automatically award badges + points on completion.</p>
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
                    <div class="stat-number"><?php echo count($system_goals); ?></div>
                    <div class="stat-label">Available Goals</div>
                </div>
                <div class="stat-card">
                    <div class="stat-number"><?php echo count($students); ?></div>
                    <div class="stat-label">Active Students</div>
                </div>
                <div class="stat-card">
                    <div class="stat-number"><?php echo $total_assignments; ?></div>
                    <div class="stat-label">Total Assignments</div>
                </div>
                <div class="stat-card">
                    <div class="stat-number"><?php echo $pending_assignments; ?></div>
                    <div class="stat-label">Pending Assignments</div>
                </div>
            </div>

            <div class="tabs">
                <button class="tab active" data-tab="1">Assign Existing Goal</button>
                <button class="tab" data-tab="2">Create New Goal & Assign</button>
            </div>

            <!-- Tab 1: Assign Existing Goal -->
            <div class="tab-content active" id="tab1">
                <div class="form-card">
                    <form method="POST" id="assignForm">
                        <input type="hidden" name="action" value="assign_system_goal">
                        <div class="form-group">
                            <label>Select Goal <span class="required"></span></label>
                            <select name="goal_id" id="goal_id" required class="goal-select">
                                <option value="">-- Choose a goal --</option>
                                <?php foreach ($system_goals as $goal): ?>
                                    <option value="<?php echo $goal['id']; ?>"
                                            data-target="<?php echo htmlspecialchars($goal['target_value'] . ' ' . $goal['unit']); ?>"
                                            data-description="<?php echo htmlspecialchars($goal['description'] ?? ''); ?>"
                                            data-achievement-title="<?php echo htmlspecialchars($goal['achievement_title'] ?? ''); ?>"
                                            data-achievement-points="<?php echo $goal['achievement_points'] ?? ''; ?>"
                                            data-achievement-icon="<?php echo htmlspecialchars($goal['achievement_icon'] ?? 'trophy'); ?>"
                                            data-achievement-color="<?php echo htmlspecialchars($goal['achievement_color'] ?? '#f59e0b'); ?>"
                                            <?php echo $selected_goal && $selected_goal['id'] == $goal['id'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($goal['title']); ?>
                                        <?php if ($goal['achievement_title']): ?>
                                            (Awards: <?php echo htmlspecialchars($goal['achievement_title']); ?> +<?php echo $goal['achievement_points']; ?> pts)
                                        <?php endif; ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div id="goalPreview" style="display: <?php echo $selected_goal ? 'block' : 'none'; ?>;">
                            <div class="goal-preview">
                                <strong style="font-size: 18px;"><?php echo $selected_goal ? htmlspecialchars($selected_goal['title']) : ''; ?></strong>
                                <?php if ($selected_goal && $selected_goal['description']): ?>
                                    <div style="margin: 12px 0; color: var(--gray-600);"><?php echo nl2br(htmlspecialchars($selected_goal['description'])); ?></div>
                                <?php endif; ?>
                                <div><strong>Target:</strong> <?php echo $selected_goal ? htmlspecialchars($selected_goal['target_value'] . ' ' . $selected_goal['unit']) : ''; ?></div>

                                <?php if ($selected_goal && $selected_goal['achievement_title']): ?>
                                    <div class="achievement-preview">
                                        <div class="badge-large" style="background: <?php echo htmlspecialchars($selected_goal['achievement_color']); ?>;">
                                            <i class="fas fa-<?php echo htmlspecialchars($selected_goal['achievement_icon']); ?>"></i>
                                        </div>
                                        <strong>Automatic Award on Completion:</strong><br>
                                        <?php echo htmlspecialchars($selected_goal['achievement_title']); ?><br>
                                        +<?php echo $selected_goal['achievement_points']; ?> points
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>

                        <div class="form-group">
                            <label>Select Students <span class="required"></span></label>
                            <select name="student_ids[]" class="student-select" multiple required>
                                <?php foreach ($students as $student): ?>
                                    <option value="<?php echo $student['id']; ?>">
                                        <?php echo htmlspecialchars($student['name']); ?> (<?php echo htmlspecialchars($student['email']); ?>)
                                        | Active: <?php echo $student['active_goals']; ?>, Completed: <?php echo $student['completed_goals']; ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="form-row">
                            <div class="form-group">
                                <label>Due Date <span class="required"></span></label>
                                <input type="date" name="due_date" required>
                            </div>
                            <div class="form-group">
                                <label>Priority</label>
                                <select name="priority">
                                    <option value="low">Low</option>
                                    <option value="medium" selected>Medium</option>
                                    <option value="high">High</option>
                                    <option value="critical">Critical</option>
                                </select>
                            </div>
                        </div>

                        <button type="submit" class="btn btn-primary" style="width:100%;">Assign Goal</button>
                    </form>
                </div>
            </div>

            <!-- Tab 2: Create New Goal & Assign -->
            <div class="tab-content" id="tab2">
                <div class="form-card">
                    <form method="POST" id="createAndAssignForm">
                        <input type="hidden" name="action" value="create_and_assign">
                        <div class="form-group">
                            <label>Goal Title <span class="required"></span></label>
                            <input type="text" name="title" required placeholder="e.g., Read 100 pages">
                        </div>
                        <div class="form-group">
                            <label>Description</label>
                            <textarea name="description" placeholder="Detailed instructions..."></textarea>
                        </div>
                        <div class="form-row">
                            <div class="form-group">
                                <label>Target Value <span class="required"></span></label>
                                <input type="number" name="target_value" min="0.01" step="0.01" required>
                            </div>
                            <div class="form-group">
                                <label>Unit <span class="required"></span></label>
                                <input type="text" name="unit" required placeholder="e.g., pages, hours">
                            </div>
                        </div>
                        <div class="form-group">
                            <label>Linked Achievement (auto-awarded on completion)</label>
                            <select name="achievement_id">
                                <option value="">None</option>
                                <?php foreach ($achievements_list as $ach): ?>
                                    <option value="<?php echo $ach['id']; ?>">
                                        <?php echo htmlspecialchars($ach['title']); ?> (+<?php echo $ach['points']; ?> points)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Select Students <span class="required"></span></label>
                            <select name="student_ids[]" class="student-select" multiple required>
                                <?php foreach ($students as $student): ?>
                                    <option value="<?php echo $student['id']; ?>">
                                        <?php echo htmlspecialchars($student['name']); ?> (<?php echo htmlspecialchars($student['email']); ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-row">
                            <div class="form-group">
                                <label>Due Date <span class="required"></span></label>
                                <input type="date" name="due_date" required>
                            </div>
                            <div class="form-group">
                                <label>Priority</label>
                                <select name="priority">
                                    <option value="low">Low</option>
                                    <option value="medium" selected>Medium</option>
                                    <option value="high">High</option>
                                    <option value="critical">Critical</option>
                                </select>
                            </div>
                        </div>
                        <button type="submit" class="btn btn-success" style="width:100%;">Create Goal & Assign</button>
                    </form>
                </div>
            </div>

            <!-- Recent Assignments -->
            <div style="margin-top: 60px;">
                <h3 style="margin-bottom: 24px;">Recent Assignments (Last 15)</h3>
                <div class="form-card">
                    <?php if (empty($recent_assignments)): ?>
                        <p style="text-align: center; padding: 40px; color: var(--gray-500);">No recent assignments</p>
                    <?php else: ?>
                        <table style="width: 100%; border-collapse: collapse;">
                            <thead>
                                <tr style="background: var(--gray-200);">
                                    <th style="padding: 16px; text-align: left;">Student</th>
                                    <th style="padding: 16px; text-align: left;">Goal</th>
                                    <th style="padding: 16px; text-align: left;">Due Date</th>
                                    <th style="padding: 16px; text-align: left;">Award on Completion</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($recent_assignments as $assignment): ?>
                                    <tr style="border-bottom: 1px solid var(--gray-300);">
                                        <td style="padding: 16px;"><?php echo htmlspecialchars($assignment['student_name']); ?></td>
                                        <td style="padding: 16px;">
                                            <strong><?php echo htmlspecialchars($assignment['goal_title']); ?></strong><br>
                                            <small><?php echo $assignment['target_value'] . ' ' . $assignment['unit']; ?></small>
                                        </td>
                                        <td style="padding: 16px;">
                                            <?php echo $assignment['due_date'] ? date('M d, Y', strtotime($assignment['due_date'])) : 'No due date'; ?>
                                        </td>
                                        <td style="padding: 16px;">
                                            <?php if ($assignment['achievement_title']): ?>
                                                <div style="display: inline-flex; align-items: center; gap: 8px; background: <?php echo htmlspecialchars($assignment['achievement_color'] ?? '#f59e0b'); ?>20; padding: 8px 12px; border-radius: 20px;">
                                                    <i class="fas fa-<?php echo htmlspecialchars($assignment['achievement_icon'] ?? 'trophy'); ?>" style="color: <?php echo htmlspecialchars($assignment['achievement_color'] ?? '#f59e0b'); ?>;"></i>
                                                    <span><?php echo htmlspecialchars($assignment['achievement_title']); ?> (+<?php echo $assignment['achievement_points']; ?> pts)</span>
                                                </div>
                                            <?php else: ?>
                                                <span style="color: var(--gray-500);">None</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                </div>
            </div>
        </main>
    </div>

    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/select2/4.0.13/js/select2.min.js"></script>
    <script>
        $(document).ready(function() {
            $('.student-select, .goal-select').select2({
                width: '100%'
            });

            $('#goal_id').on('change', function() {
                const selected = $(this).find('option:selected');
                if (selected.val()) {
                    $('#goalPreview').show();
                    const achTitle = selected.data('achievement-title');
                    if (achTitle) {
                        $('.achievement-preview').show();
                        $('.badge-large').css('background', selected.data('achievement-color'));
                        $('.badge-large i').attr('class', 'fas fa-' + selected.data('achievement-icon'));
                    } else {
                        $('.achievement-preview').hide();
                    }
                } else {
                    $('#goalPreview').hide();
                }
            });

            $('.tab').on('click', function() {
                $('.tab').removeClass('active');
                $('.tab-content').removeClass('active');
                $(this).addClass('active');
                $('#tab' + $(this).data('tab')).addClass('active');
            });
        });
    </script>
</body>
</html>