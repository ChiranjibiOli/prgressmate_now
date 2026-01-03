<?php
session_start();
require_once '../includes/db_connection.php';
checkAuth('admin');

// === POST Action Handling ===
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    try {
        if ($action === 'assign_system_goal') {
            // Validate inputs
            if (empty($_POST['goal_id']) || empty($_POST['student_ids'])) {
                throw new Exception('Please select a goal and at least one student.');
            }
            
            $goal_id = (int)$_POST['goal_id'];
            $due_date = $_POST['due_date'] ? date('Y-m-d', strtotime($_POST['due_date'])) : null;
            $priority = $_POST['priority'] ?? 'medium';
            
            // Validate due date
            if ($due_date && strtotime($due_date) < strtotime('today')) {
                throw new Exception('Due date cannot be in the past.');
            }
            
            $stmt = $pdo->prepare("
                INSERT INTO student_goals 
                (student_id, goal_id, due_date, priority, assigned_by, status, created_at)
                VALUES (?, ?, ?, ?, ?, 'pending', NOW())
                ON DUPLICATE KEY UPDATE updated_at = NOW()
            ");
            
            $assigned_count = 0;
            foreach ($_POST['student_ids'] as $student_id) {
                $student_id = (int)$student_id;
                if ($student_id > 0) {
                    $stmt->execute([$student_id, $goal_id, $due_date, $priority, $_SESSION['user_id']]);
                    $assigned_count++;
                }
            }
            
            if ($assigned_count === 0) {
                throw new Exception('No valid students selected.');
            }
            
            $_SESSION['success'] = "Goal assigned to {$assigned_count} student(s).";
            
        } elseif ($action === 'create_and_assign') {
            // Validate all required fields
            $required = ['title', 'target_value', 'unit', 'student_ids'];
            foreach ($required as $field) {
                if (empty($_POST[$field])) {
                    throw new Exception("Please fill all required fields.");
                }
            }
            
            // Sanitize inputs
            $title = trim($_POST['title']);
            $target_value = (float)$_POST['target_value'];
            $unit = trim($_POST['unit']);
            $description = trim($_POST['description'] ?? '');
            $category = trim($_POST['category'] ?? '');
            $due_date = $_POST['due_date'] ? date('Y-m-d', strtotime($_POST['due_date'])) : null;
            $priority = $_POST['priority'] ?? 'medium';
            
            if ($target_value <= 0) {
                throw new Exception('Target value must be greater than 0.');
            }
            
            if (strlen($title) < 3) {
                throw new Exception('Goal title must be at least 3 characters.');
            }
            
            $pdo->beginTransaction();
            
            // Insert into admin_goals
            $stmt = $pdo->prepare("
                INSERT INTO admin_goals 
                (title, description, category, target_value, unit, due_date, priority, status, created_by, created_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, 'active', ?, NOW())
            ");
            $stmt->execute([
                $title,
                $description,
                $category,
                $target_value,
                $unit,
                $due_date,
                $priority,
                $_SESSION['user_id']
            ]);
            
            $new_goal_id = $pdo->lastInsertId();
            
            // Assign to students
            $assign_stmt = $pdo->prepare("
                INSERT INTO student_goals 
                (student_id, goal_id, due_date, priority, assigned_by, status, created_at)
                VALUES (?, ?, ?, ?, ?, 'pending', NOW())
            ");
            
            $assigned_count = 0;
            foreach ($_POST['student_ids'] as $student_id) {
                $student_id = (int)$student_id;
                if ($student_id > 0) {
                    $assign_stmt->execute([
                        $student_id,
                        $new_goal_id,
                        $due_date,
                        $priority,
                        $_SESSION['user_id']
                    ]);
                    $assigned_count++;
                }
            }
            
            if ($assigned_count === 0) {
                throw new Exception('No valid students selected.');
            }
            
            $pdo->commit();
            $_SESSION['success'] = "New goal created and assigned to {$assigned_count} student(s).";
        }
    } catch (Exception $e) {
        if (isset($pdo) && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $_SESSION['error'] = $e->getMessage();
    }
    
    header('Location: assign_goals.php');
    exit();
}

// === Flash Messages ===
$success = $_SESSION['success'] ?? '';
$error = $_SESSION['error'] ?? '';
unset($_SESSION['success'], $_SESSION['error']);

// Fetch active system goals with categories
$system_goals = $pdo->query("
    SELECT ag.*, c.name as category_name, c.color as category_color 
    FROM admin_goals ag
    LEFT JOIN categories c ON ag.category = c.name
    WHERE ag.status = 'active' 
    ORDER BY ag.title ASC
")->fetchAll(PDO::FETCH_ASSOC);

// Fetch students with additional info
$students = $pdo->query("
    SELECT id, name, email, department, semester, profile_picture,
           (SELECT COUNT(*) FROM student_goals WHERE student_id = users.id) as active_goals,
           (SELECT COUNT(*) FROM student_goals WHERE student_id = users.id AND status = 'completed') as completed_goals
    FROM users 
    WHERE role = 'student' AND status = 'active'
    ORDER BY name ASC
")->fetchAll(PDO::FETCH_ASSOC);

// Fetch categories for dropdown
$categories = $pdo->query("SELECT name, color FROM categories WHERE is_global = 1 ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);

// Goal preview (from GET)
$selected_goal = null;
if (!empty($_GET['goal_id'])) {
    $goal_id = (int)$_GET['goal_id'];
    $stmt = $pdo->prepare("
        SELECT ag.*, c.name as category_name, c.color as category_color 
        FROM admin_goals ag
        LEFT JOIN categories c ON ag.category = c.name
        WHERE ag.id = ? AND ag.status = 'active'
    ");
    $stmt->execute([$goal_id]);
    $selected_goal = $stmt->fetch(PDO::FETCH_ASSOC);
}

// Recent assignments with more details
$recent_assignments = $pdo->query("
    SELECT sg.*, u.name AS student_name, u.email AS student_email, u.profile_picture,
           ag.title AS goal_title, ag.target_value, ag.unit,
           admin.name AS assigned_by_name, admin.profile_picture as admin_pic,
           sg.created_at AS assigned_at,
           DATEDIFF(sg.due_date, CURDATE()) as days_left,
           (SELECT COUNT(*) FROM goal_progress WHERE goal_id = sg.id) as progress_updates
    FROM student_goals sg
    JOIN users u ON sg.student_id = u.id
    JOIN admin_goals ag ON sg.goal_id = ag.id
    LEFT JOIN users admin ON sg.assigned_by = admin.id
    ORDER BY sg.created_at DESC
    LIMIT 15
")->fetchAll(PDO::FETCH_ASSOC);

// Stats with additional metrics
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

        .stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 24px; margin-bottom: 40px; }
        .stat-card { background: white; border-radius: var(--radius); padding: 24px; box-shadow: var(--shadow); text-align: center; position: relative; overflow: hidden; }
        .stat-card::before { content: ''; position: absolute; top: 0; left: 0; width: 5px; height: 100%; }
        .stat-card:nth-child(1)::before { background: var(--primary); }
        .stat-card:nth-child(2)::before { background: var(--success); }
        .stat-card:nth-child(3)::before { background: var(--warning); }
        .stat-card:nth-child(4)::before { background: var(--purple); }
        .stat-number { font-size: 32px; font-weight: 800; }
        .stat-label { font-size: 15px; color: var(--gray-500); }

        .alert { padding: 16px; border-radius: var(--radius); margin-bottom: 24px; display: flex; align-items: center; gap: 12px; font-weight: 500; box-shadow: var(--shadow-sm); }
        .alert-success { background: var(--success-light); color: #065f46; border-left: 5px solid var(--success); }
        .alert-error { background: var(--danger-light); color: #991b1b; border-left: 5px solid var(--danger); }

        .tabs { display: flex; gap: 8px; margin-bottom: 32px; border-bottom: 1px solid var(--gray-300); }
        .tab { padding: 16px 24px; background: transparent; border: none; font-weight: 600; color: var(--gray-500); cursor: pointer; border-bottom: 3px solid transparent; transition: var(--transition); position: relative; }
        .tab.active { color: var(--primary); border-bottom-color: var(--primary); }
        .tab-badge { position: absolute; top: 8px; right: 8px; background: var(--primary); color: white; font-size: 10px; padding: 2px 6px; border-radius: 10px; }

        .tab-content { display: none; animation: fadeIn 0.5s ease; }
        .tab-content.active { display: block; }

        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(10px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .form-card { background: white; border-radius: var(--radius); padding: 32px; box-shadow: var(--shadow); }

        .form-group { margin-bottom: 24px; }
        .form-group label { display: block; margin-bottom: 8px; font-weight: 500; color: var(--gray-700); }
        .required::after { content: " *"; color: var(--danger); }
        .form-group input, .form-group select, .form-group textarea { width: 100%; padding: 14px; border: 1px solid var(--gray-300); border-radius: 8px; font-size: 15px; }
        .form-group textarea { min-height: 120px; resize: vertical; }
        .form-help { font-size: 13px; color: var(--gray-500); margin-top: 6px; }

        .form-row { display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 24px; }

        .goal-preview { background: var(--gray-100); padding: 20px; border-radius: var(--radius); margin: 20px 0; border-left: 4px solid var(--primary); }
        .goal-preview-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px; }
        .goal-category { display: inline-block; padding: 4px 12px; border-radius: 20px; font-size: 12px; font-weight: 600; }

        .progress-ring { width: 80px; height: 80px; }
        .progress-ring-circle { transition: stroke-dashoffset 0.35s; transform: rotate(-90deg); transform-origin: 50% 50%; }

        .timer-display { 
            font-family: 'Courier New', monospace;
            font-size: 18px;
            font-weight: 600;
            padding: 10px 20px;
            background: var(--gray-900);
            color: white;
            border-radius: var(--radius-sm);
            display: inline-flex;
            align-items: center;
            gap: 10px;
            margin: 10px 0;
        }
        .timer-label { font-size: 14px; color: var(--gray-400); }

        .countdown {
            font-size: 16px;
            font-weight: 600;
            padding: 8px 16px;
            border-radius: var(--radius-sm);
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }
        .countdown.expired { background: var(--danger-light); color: var(--danger); }
        .countdown.soon { background: var(--warning-light); color: var(--warning); }
        .countdown.safe { background: var(--success-light); color: var(--success); }

        .student-card { 
            background: white; 
            border-radius: var(--radius-sm); 
            padding: 16px; 
            border: 1px solid var(--gray-300);
            display: flex;
            align-items: center;
            gap: 15px;
            transition: var(--transition);
        }
        .student-card:hover { border-color: var(--primary); box-shadow: var(--shadow); }
        .student-avatar { width: 40px; height: 40px; border-radius: 50%; object-fit: cover; }
        .student-info { flex: 1; }
        .student-goals { font-size: 12px; color: var(--gray-500); }

        .recent-assignments table { width: 100%; border-collapse: collapse; }
        .recent-assignments th { background: var(--gray-200); padding: 16px; text-align: left; font-weight: 600; }
        .recent-assignments td { padding: 16px; border-bottom: 1px solid var(--gray-300); vertical-align: middle; }
        .status-badge { padding: 6px 12px; border-radius: 20px; font-size: 12px; font-weight: 600; }
        .status-pending { background: var(--warning-light); color: var(--warning); }
        .status-active { background: var(--success-light); color: var(--success); }
        .status-completed { background: var(--info-light); color: var(--info); }

        .mobile-toggle { display: none; position: fixed; top: 20px; left: 20px; z-index: 1100; background: var(--primary); color: white; border: none; width: 48px; height: 48px; border-radius: 12px; font-size: 20px; cursor: pointer; box-shadow: var(--shadow); }
        .sidebar-overlay { display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.5); z-index: 999; }

        /* New Timer Widget */
        .timer-widget {
            position: fixed;
            bottom: 30px;
            right: 30px;
            background: white;
            border-radius: var(--radius);
            padding: 20px;
            box-shadow: var(--shadow-lg);
            z-index: 100;
            width: 300px;
            border: 1px solid var(--gray-300);
        }
        .timer-widget-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 15px;
            padding-bottom: 10px;
            border-bottom: 1px solid var(--gray-300);
        }
        .timer-controls {
            display: flex;
            gap: 10px;
        }
        .timer-btn {
            padding: 6px 12px;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            font-size: 12px;
            font-weight: 600;
        }

        /* Category color preview */
        .category-color-preview {
            width: 20px;
            height: 20px;
            border-radius: 4px;
            display: inline-block;
            margin-right: 8px;
            vertical-align: middle;
        }

        @media (max-width: 768px) {
            .sidebar { transform: translateX(-100%); width: 300px; }
            .sidebar.active { transform: translateX(0); }
            .sidebar-overlay.active { display: block; }
            .sidebar-close { display: block; }
            .mobile-toggle { display: flex; align-items: center; justify-content: center; }
            .main-content { margin-left: 0; padding: 24px 16px; padding-top: 80px; }
            .form-row { grid-template-columns: 1fr; }
            .timer-widget { width: calc(100% - 40px); right: 20px; bottom: 20px; }
        }
    </style>
</head>
<body>
    <button class="mobile-toggle" id="sidebarToggle"><i class="fas fa-bars"></i></button>
    <div class="sidebar-overlay" id="sidebarOverlay"></div>

    <!-- Timer Widget -->
    <div class="timer-widget" id="timerWidget">
        <div class="timer-widget-header">
            <h4><i class="fas fa-clock"></i> Session Timer</h4>
            <div class="timer-controls">
                <button class="timer-btn" id="startTimer" style="background: var(--success); color: white;">Start</button>
                <button class="timer-btn" id="pauseTimer" style="background: var(--warning); color: white;">Pause</button>
                <button class="timer-btn" id="resetTimer" style="background: var(--danger); color: white;">Reset</button>
            </div>
        </div>
        <div class="timer-display" id="timerDisplay">
            <span id="timerHours">00</span>:<span id="timerMinutes">00</span>:<span id="timerSeconds">00</span>
        </div>
        <div class="timer-label" id="timerStatus">Ready to start</div>
    </div>

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
                <a href="assign_goals.php" class="nav-link active"><i class="fas fa-tasks"></i> Assign Goals 
                    <?php if ($sidebar_stats['pending'] > 0): ?><span class="badge" style="background: var(--warning);"><?php echo $sidebar_stats['pending']; ?> pending</span><?php endif; ?>
                </a>
                <a href="achievements.php" class="nav-link"><i class="fas fa-trophy"></i> Achievements <?php if ($sidebar_stats['points'] > 0): ?><span class="badge"><?php echo $sidebar_stats['points']; ?> pts</span><?php endif; ?></a>
                <a href="reports.php" class="nav-link"><i class="fas fa-chart-bar"></i> Reports</a>
                <a href="notifications.php" class="nav-link"><i class="fas fa-bell"></i> Notifications</a>
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
                    <p>Assign existing system goals or create new ones for students</p>
                </div>
                <div>
                    <div class="timer-display" id="pageTimer">
                        <i class="fas fa-hourglass-half"></i>
                        <span id="currentTime">Loading...</span>
                    </div>
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
                <button class="tab active" data-tab="1">
                    <i class="fas fa-paper-plane"></i> Assign Existing Goal
                    <span class="tab-badge">Quick</span>
                </button>
                <button class="tab" data-tab="2">
                    <i class="fas fa-plus-circle"></i> Create New Goal & Assign
                    <span class="tab-badge">Custom</span>
                </button>
            </div>

            <!-- Tab 1: Assign Existing Goal -->
            <div class="tab-content active" id="tab1">
                <h2 style="margin-bottom: 20px; color: var(--primary);"><i class="fas fa-paper-plane"></i> Assign an Existing System Goal</h2>
                <div class="form-card">
                    <form method="POST" id="assignForm">
                        <input type="hidden" name="action" value="assign_system_goal">

                        <div class="form-group">
                            <label><i class="fas fa-bullseye"></i> Select Goal <span class="required"></span></label>
                            <select name="goal_id" id="goal_id" required class="goal-select">
                                <option value="">-- Choose a goal --</option>
                                <?php foreach ($system_goals as $goal): ?>
                                    <option value="<?php echo $goal['id']; ?>" 
                                            data-description="<?php echo htmlspecialchars($goal['description']); ?>"
                                            data-target="<?php echo htmlspecialchars($goal['target_value'] . ' ' . $goal['unit']); ?>"
                                            data-category="<?php echo htmlspecialchars($goal['category_name'] ?? ''); ?>"
                                            data-category-color="<?php echo htmlspecialchars($goal['category_color'] ?? '#4f46e5'); ?>"
                                            <?php echo $selected_goal && $selected_goal['id'] == $goal['id'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($goal['title']); ?>
                                        <?php if ($goal['category_name']): ?>
                                            (<?php echo htmlspecialchars($goal['category_name']); ?>)
                                        <?php endif; ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <div class="form-help">Select from existing system goals</div>
                        </div>

                        <div id="goalPreview" style="display: <?php echo $selected_goal ? 'block' : 'none'; ?>;">
                            <div class="goal-preview">
                                <div class="goal-preview-header">
                                    <div>
                                        <strong style="font-size: 18px;" id="previewTitle">
                                            <?php echo $selected_goal ? htmlspecialchars($selected_goal['title']) : ''; ?>
                                        </strong>
                                        <?php if ($selected_goal && $selected_goal['category_name']): ?>
                                            <span class="goal-category" id="previewCategory" style="background: <?php echo $selected_goal['category_color'] ?? '#e0e7ff'; ?>; color: var(--primary);">
                                                <?php echo htmlspecialchars($selected_goal['category_name']); ?>
                                            </span>
                                        <?php endif; ?>
                                    </div>
                                    <div id="previewCountdown" class="countdown" data-due="<?php echo $selected_goal['due_date'] ?? ''; ?>"></div>
                                </div>
                                <div id="previewDescription">
                                    <?php if ($selected_goal && $selected_goal['description']): ?>
                                        <?php echo nl2br(htmlspecialchars($selected_goal['description'])); ?><br><br>
                                    <?php endif; ?>
                                </div>
                                <div>
                                    <strong><i class="fas fa-target"></i> Target:</strong> 
                                    <span id="previewTarget">
                                        <?php echo $selected_goal ? htmlspecialchars($selected_goal['target_value'] . ' ' . $selected_goal['unit']) : ''; ?>
                                    </span><br>
                                    <?php if ($selected_goal && $selected_goal['due_date']): ?>
                                        <strong><i class="fas fa-calendar-alt"></i> Default Due Date:</strong> 
                                        <span id="previewDueDate"><?php echo date('M d, Y', strtotime($selected_goal['due_date'])); ?></span><br>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>

                        <div class="form-group">
                            <label><i class="fas fa-user-graduate"></i> Select Students <span class="required"></span></label>
                            <select name="student_ids[]" class="student-select" multiple required>
                                <?php foreach ($students as $student): ?>
                                    <option value="<?php echo $student['id']; ?>">
                                        <?php echo htmlspecialchars($student['name']); ?> 
                                        (<?php echo htmlspecialchars($student['email']); ?>)
                                        <?php if ($student['department']): ?> - <?php echo htmlspecialchars($student['department']); ?><?php endif; ?>
                                        | Goals: <?php echo $student['active_goals']; ?> active, <?php echo $student['completed_goals']; ?> completed
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <div class="form-help">Select multiple students using Ctrl+Click or drag</div>
                        </div>

                        <div class="form-row">
                            <div class="form-group">
                                <label><i class="fas fa-calendar-day"></i> Due Date <span class="required"></span></label>
                                <input type="date" name="due_date" id="due_date" required>
                                <div class="form-help">When should students complete this goal?</div>
                                <div class="countdown" id="assignCountdown"></div>
                            </div>
                            <div class="form-group">
                                <label><i class="fas fa-flag"></i> Priority</label>
                                <select name="priority">
                                    <option value="low">Low</option>
                                    <option value="medium" selected>Medium</option>
                                    <option value="high">High</option>
                                </select>
                                <div class="form-help">Set priority for this assignment</div>
                            </div>
                        </div>

                        <button type="submit" class="btn btn-primary" style="width:100%; margin-top:20px;" id="assignBtn">
                            <i class="fas fa-paper-plane"></i> Assign Goal to Selected Students
                        </button>
                    </form>
                </div>
            </div>

            <!-- Tab 2: Create New Goal & Assign -->
            <div class="tab-content" id="tab2">
                <h2 style="margin-bottom: 20px; color: var(--primary);"><i class="fas fa-plus-circle"></i> Create a Completely New Goal and Assign to Students</h2>
                <div class="form-card">
                    <form method="POST" id="createAndAssignForm">
                        <input type="hidden" name="action" value="create_and_assign">

                        <div class="form-group">
                            <label><i class="fas fa-heading"></i> Goal Title <span class="required"></span></label>
                            <input type="text" name="title" required placeholder="e.g., Complete Web Development Project" id="newGoalTitle">
                            <div class="form-help">Clear, descriptive title for the goal</div>
                        </div>

                        <div class="form-group">
                            <label><i class="fas fa-align-left"></i> Description</label>
                            <textarea name="description" placeholder="Detailed description of the goal..." id="newGoalDescription"></textarea>
                            <div class="form-help">Provide clear instructions and expectations</div>
                        </div>

                        <div class="form-row">
                            <div class="form-group">
                                <label><i class="fas fa-bullseye"></i> Target Value <span class="required"></span></label>
                                <input type="number" name="target_value" min="0.01" step="0.01" required placeholder="100" id="newTargetValue">
                                <div class="form-help">Numerical target to achieve</div>
                            </div>
                            <div class="form-group">
                                <label><i class="fas fa-ruler"></i> Unit <span class="required"></span></label>
                                <input type="text" name="unit" required placeholder="e.g., hours, pages, chapters" id="newUnit">
                                <div class="form-help">Measurement unit (hours, pages, etc.)</div>
                            </div>
                        </div>

                        <div class="form-row">
                            <div class="form-group">
                                <label><i class="fas fa-tags"></i> Category</label>
                                <select name="category" id="categorySelect">
                                    <option value="">-- Select Category --</option>
                                    <?php foreach ($categories as $cat): ?>
                                        <option value="<?php echo htmlspecialchars($cat['name']); ?>" data-color="<?php echo htmlspecialchars($cat['color']); ?>">
                                            <span class="category-color-preview" style="background: <?php echo htmlspecialchars($cat['color']); ?>"></span>
                                            <?php echo htmlspecialchars($cat['name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                    <option value="_custom">+ Create Custom Category</option>
                                </select>
                                <input type="text" name="custom_category" id="customCategory" placeholder="Enter custom category" style="display: none; margin-top: 10px;">
                                <div class="form-help">Organize goals by category</div>
                            </div>
                            <div class="form-group">
                                <label><i class="fas fa-palette"></i> Category Color</label>
                                <input type="color" name="category_color" id="categoryColor" value="#4f46e5" style="width: 100%; height: 45px; border-radius: 8px; padding: 5px;">
                            </div>
                        </div>

                        <div class="form-group">
                            <label><i class="fas fa-user-graduate"></i> Select Students <span class="required"></span></label>
                            <select name="student_ids[]" class="student-select" multiple required id="newStudentSelect">
                                <?php foreach ($students as $student): ?>
                                    <option value="<?php echo $student['id']; ?>">
                                        <?php echo htmlspecialchars($student['name']); ?> 
                                        (<?php echo htmlspecialchars($student['email']); ?>)
                                        <?php if ($student['department']): ?> - <?php echo htmlspecialchars($student['department']); ?><?php endif; ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <div class="form-help">Hold Ctrl/Cmd to select multiple students</div>
                        </div>

                        <div class="form-row">
                            <div class="form-group">
                                <label><i class="fas fa-calendar-day"></i> Due Date <span class="required"></span></label>
                                <input type="date" name="due_date" id="new_due_date" required>
                                <div class="form-help">Set a realistic deadline</div>
                                <div class="countdown" id="newCountdown"></div>
                            </div>
                            <div class="form-group">
                                <label><i class="fas fa-flag"></i> Priority</label>
                                <select name="priority">
                                    <option value="low">Low</option>
                                    <option value="medium" selected>Medium</option>
                                    <option value="high">High</option>
                                </select>
                                <div class="form-help">How urgent is this goal?</div>
                            </div>
                        </div>

                        <div class="form-group">
                            <label><i class="fas fa-hourglass-half"></i> Estimated Time (Optional)</label>
                            <input type="number" name="estimated_hours" min="0.5" step="0.5" placeholder="e.g., 10.5 hours">
                            <div class="form-help">Estimated time needed to complete this goal</div>
                        </div>

                        <div style="background: var(--gray-100); padding: 20px; border-radius: var(--radius); margin: 20px 0;">
                            <h4><i class="fas fa-eye"></i> Goal Preview</h4>
                            <div id="livePreview">
                                <p><strong>Title:</strong> <span id="previewLiveTitle">[Enter title above]</span></p>
                                <p><strong>Target:</strong> <span id="previewLiveTarget">[Enter target above]</span></p>
                                <p><strong>Category:</strong> <span id="previewLiveCategory">[Select category]</span></p>
                            </div>
                        </div>

                        <button type="submit" class="btn btn-success" style="width:100%; margin-top:20px;" id="createBtn">
                            <i class="fas fa-plus-circle"></i> Create New Goal & Assign to Selected Students
                        </button>
                    </form>
                </div>
            </div>

            <!-- Recent Assignments -->
            <div style="margin-top: 60px;">
                <h3 style="margin-bottom: 24px; font-size: 20px; display: flex; align-items: center; gap: 10px;">
                    <i class="fas fa-history"></i> Recent Assignments
                    <span style="font-size: 14px; background: var(--gray-300); color: var(--gray-700); padding: 4px 12px; border-radius: 20px;">
                        Last 15
                    </span>
                </h3>
                <div class="form-card">
                    <?php if (empty($recent_assignments)): ?>
                        <div style="text-align: center; padding: 60px; color: var(--gray-500);">
                            <i class="fas fa-tasks" style="font-size: 64px; margin-bottom: 20px; opacity: 0.5;"></i>
                            <p>No recent assignments</p>
                        </div>
                    <?php else: ?>
                        <div style="overflow-x: auto;">
                            <table class="recent-assignments">
                                <thead>
                                    <tr>
                                        <th>Student</th>
                                        <th>Goal</th>
                                        <th>Due Date</th>
                                        <th>Countdown</th>
                                        <th>Priority</th>
                                        <th>Status</th>
                                        <th>Progress</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($recent_assignments as $assignment): ?>
                                        <tr>
                                            <td>
                                                <div style="display: flex; align-items: center; gap: 10px;">
                                                    <?php if ($assignment['profile_picture']): ?>
                                                        <img src="../<?php echo htmlspecialchars($assignment['profile_picture']); ?>" alt="Student" class="student-avatar">
                                                    <?php else: ?>
                                                        <div style="width: 40px; height: 40px; border-radius: 50%; background: var(--primary); color: white; display: flex; align-items: center; justify-content: center; font-weight: bold;">
                                                            <?php echo strtoupper(substr($assignment['student_name'], 0, 1)); ?>
                                                        </div>
                                                    <?php endif; ?>
                                                    <div>
                                                        <div style="font-weight: 600;"><?php echo htmlspecialchars($assignment['student_name']); ?></div>
                                                        <small style="color: var(--gray-500);"><?php echo htmlspecialchars($assignment['student_email']); ?></small>
                                                    </div>
                                                </div>
                                            </td>
                                            <td>
                                                <strong><?php echo htmlspecialchars($assignment['goal_title']); ?></strong><br>
                                                <small style="color: var(--gray-500);"><?php echo $assignment['target_value'] . ' ' . $assignment['unit']; ?></small>
                                            </td>
                                            <td>
                                                <?php echo $assignment['due_date'] ? date('M d, Y', strtotime($assignment['due_date'])) : 'No due'; ?>
                                            </td>
                                            <td>
                                                <?php if ($assignment['due_date']): ?>
                                                    <div class="countdown" data-due="<?php echo $assignment['due_date']; ?>"></div>
                                                <?php else: ?>
                                                    <span style="color: var(--gray-500);">No deadline</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?php 
                                                $priority = $assignment['priority'] ?? 'medium';
                                                $priority_colors = [
                                                    'low' => 'var(--gray-500)',
                                                    'medium' => 'var(--warning)',
                                                    'high' => 'var(--danger)'
                                                ];
                                                ?>
                                                <span style="color: <?php echo $priority_colors[$priority] ?? 'var(--gray-700)'; ?>; font-weight: 600;">
                                                    <?php echo ucfirst($priority); ?>
                                                </span>
                                            </td>
                                            <td>
                                                <?php 
                                                $status = $assignment['status'] ?? 'pending';
                                                $status_classes = [
                                                    'pending' => 'status-pending',
                                                    'active' => 'status-active',
                                                    'completed' => 'status-completed'
                                                ];
                                                ?>
                                                <span class="status-badge <?php echo $status_classes[$status] ?? 'status-pending'; ?>">
                                                    <?php echo ucfirst(str_replace('_', ' ', $status)); ?>
                                                </span>
                                            </td>
                                            <td>
                                                <?php if ($assignment['progress_updates'] > 0): ?>
                                                    <div style="background: var(--gray-200); height: 8px; width: 100px; border-radius: 4px; overflow: hidden;">
                                                        <div style="background: var(--success); height: 100%; width: <?php echo min(100, ($assignment['progress_updates'] * 10)); ?>%;"></div>
                                                    </div>
                                                    <small><?php echo $assignment['progress_updates']; ?> updates</small>
                                                <?php else: ?>
                                                    <span style="color: var(--gray-500);">No progress yet</span>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </main>
    </div>

    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/select2/4.0.13/js/select2.min.js"></script>
    <script>
        $(document).ready(function() {
            // Initialize Select2
            $('.student-select, .goal-select').select2({
                placeholder: "Select...",
                width: '100%',
                closeOnSelect: false
            });

            // Live preview for new goal creation
            function updateLivePreview() {
                $('#previewLiveTitle').text($('#newGoalTitle').val() || '[Enter title above]');
                $('#previewLiveTarget').text(
                    ($('#newTargetValue').val() || '0') + ' ' + ($('#newUnit').val() || '[unit]')
                );
                
                const category = $('#categorySelect option:selected').text();
                $('#previewLiveCategory').text(category || '[Select category]');
            }

            $('#newGoalTitle, #newTargetValue, #newUnit, #categorySelect').on('input change', updateLivePreview);
            updateLivePreview();

            // Category selector logic
            $('#categorySelect').on('change', function() {
                const selected = $(this).val();
                if (selected === '_custom') {
                    $('#customCategory').show().focus();
                } else {
                    $('#customCategory').hide();
                    const color = $(this).find('option:selected').data('color');
                    if (color) {
                        $('#categoryColor').val(color);
                    }
                }
            });

            // Custom category handling
            $('#customCategory').on('input', function() {
                $('#categorySelect option[value="_custom"]').text('+ ' + $(this).val());
            });

            // Default due date 30 days from now
            const today = new Date().toISOString().split('T')[0];
            const defaultDue = new Date();
            defaultDue.setDate(defaultDue.getDate() + 30);
            const formatted = defaultDue.toISOString().split('T')[0];

            $('#due_date, #new_due_date').attr('min', today);
            if (!$('#due_date').val()) $('#due_date').val(formatted);
            $('#new_due_date').val(formatted);

            // Tabs
            document.querySelectorAll('.tab').forEach(tab => {
                tab.addEventListener('click', () => {
                    document.querySelectorAll('.tab').forEach(t => t.classList.remove('active'));
                    document.querySelectorAll('.tab-content').forEach(c => c.classList.remove('active'));
                    tab.classList.add('active');
                    document.getElementById('tab' + tab.dataset.tab).classList.add('active');
                    
                    // Reset forms when switching tabs
                    if (tab.dataset.tab === '1') {
                        $('#createAndAssignForm')[0]?.reset();
                        $('.student-select').val(null).trigger('change');
                        $('#due_date').val(formatted);
                        updateLivePreview();
                    } else if (tab.dataset.tab === '2') {
                        $('#assignForm')[0]?.reset();
                        $('.student-select').val(null).trigger('change');
                        $('#goal_id').val(null).trigger('change');
                        $('#new_due_date').val(formatted);
                        updateCountdowns();
                    }
                });
            });

            // Goal preview on change
            $('#goal_id').on('change', function() {
                const value = this.value;
                const selectedOption = $(this).find('option:selected');
                
                if (value) {
                    // Update preview
                    $('#goalPreview').show();
                    $('#previewTitle').text(selectedOption.text().split('(')[0].trim());
                    $('#previewDescription').html(
                        selectedOption.data('description') ? 
                        nl2br(selectedOption.data('description')) + '<br><br>' : 
                        ''
                    );
                    $('#previewTarget').text(selectedOption.data('target'));
                    
                    // Update category
                    const category = selectedOption.data('category');
                    const categoryColor = selectedOption.data('category-color');
                    if (category) {
                        $('#previewCategory').show().text(category)
                            .css('background', categoryColor + '20')
                            .css('color', categoryColor);
                    } else {
                        $('#previewCategory').hide();
                    }
                    
                    // Update URL without page reload for preview
                    const url = new URL(window.location);
                    url.searchParams.set('goal_id', value);
                    window.history.replaceState({}, '', url);
                    
                    // Update countdown
                    updateCountdowns();
                } else {
                    $('#goalPreview').hide();
                    const url = new URL(window.location);
                    url.searchParams.delete('goal_id');
                    window.history.replaceState({}, '', url);
                }
            });

            // Live countdown timer
            function updateCountdowns() {
                document.querySelectorAll('.countdown').forEach(el => {
                    const dueDate = el.dataset.due || el.previousElementSibling?.value;
                    if (!dueDate) {
                        el.textContent = 'No deadline';
                        el.className = 'countdown';
                        return;
                    }

                    const due = new Date(dueDate + 'T23:59:59');
                    const now = new Date();
                    const diff = due - now;

                    if (diff < 0) {
                        el.innerHTML = '<i class="fas fa-exclamation-triangle"></i> Overdue!';
                        el.className = 'countdown expired';
                    } else {
                        const days = Math.floor(diff / (1000 * 60 * 60 * 24));
                        const hours = Math.floor((diff % (1000 * 60 * 60 * 24)) / (1000 * 60 * 60));
                        const minutes = Math.floor((diff % (1000 * 60 * 60)) / (1000 * 60));

                        let icon = 'fas fa-clock';
                        let text = '';
                        
                        if (days > 30) {
                            icon = 'far fa-calendar-check';
                            text = days + ' days left';
                        } else if (days > 0) {
                            text = days + ' days ' + hours + 'h left';
                        } else if (hours > 0) {
                            icon = 'fas fa-hourglass-half';
                            text = hours + 'h ' + minutes + 'm left';
                        } else {
                            icon = 'fas fa-hourglass-end';
                            text = minutes + 'm left';
                        }

                        el.innerHTML = `<i class="${icon}"></i> ${text}`;

                        if (days === 0) {
                            el.className = 'countdown soon';
                        } else if (days <= 7) {
                            el.className = 'countdown soon';
                        } else {
                            el.className = 'countdown safe';
                        }
                    }
                });
            }

            // Real-time clock
            function updateClock() {
                const now = new Date();
                const timeString = now.toLocaleTimeString('en-US', {
                    hour12: true,
                    hour: '2-digit',
                    minute: '2-digit',
                    second: '2-digit'
                });
                $('#currentTime').text(timeString);
            }
            setInterval(updateClock, 1000);
            updateClock();

            // Session Timer
            let timerInterval = null;
            let timerSeconds = 0;
            let timerRunning = false;

            function updateTimerDisplay() {
                const hours = Math.floor(timerSeconds / 3600);
                const minutes = Math.floor((timerSeconds % 3600) / 60);
                const seconds = timerSeconds % 60;
                
                $('#timerHours').text(hours.toString().padStart(2, '0'));
                $('#timerMinutes').text(minutes.toString().padStart(2, '0'));
                $('#timerSeconds').text(seconds.toString().padStart(2, '0'));
                
                // Update page title with timer when running
                if (timerRunning) {
                    document.title = `[${hours.toString().padStart(2, '0')}:${minutes.toString().padStart(2, '0')}:${seconds.toString().padStart(2, '0')}] Assign Goals - ProgressMate`;
                } else {
                    document.title = 'Assign Goals - ProgressMate';
                }
            }

            $('#startTimer').click(function() {
                if (!timerRunning) {
                    timerRunning = true;
                    $('#timerStatus').text('Timer running...');
                    timerInterval = setInterval(() => {
                        timerSeconds++;
                        updateTimerDisplay();
                    }, 1000);
                }
            });

            $('#pauseTimer').click(function() {
                if (timerRunning) {
                    timerRunning = false;
                    $('#timerStatus').text('Timer paused');
                    clearInterval(timerInterval);
                }
            });

            $('#resetTimer').click(function() {
                timerRunning = false;
                timerSeconds = 0;
                $('#timerStatus').text('Timer reset');
                clearInterval(timerInterval);
                updateTimerDisplay();
            });

            // Form validation
            function nl2br(str) {
                return (str + '').replace(/([^>\r\n]?)(\r\n|\n\r|\r|\n)/g, '$1<br>$2');
            }

            $('#assignForm').on('submit', function(e) {
                const selectedStudents = $(this).find('.student-select').val();
                if (!selectedStudents || selectedStudents.length === 0) {
                    e.preventDefault();
                    alert('Please select at least one student.');
                    return false;
                }
                
                const dueDate = $('#due_date').val();
                if (!dueDate) {
                    e.preventDefault();
                    alert('Please select a due date.');
                    return false;
                }
                
                // Show loading
                $('#assignBtn').prop('disabled', true).html('<i class="fas fa-spinner fa-spin"></i> Assigning...');
            });

            $('#createAndAssignForm').on('submit', function(e) {
                const title = $('#newGoalTitle').val();
                if (!title.trim()) {
                    e.preventDefault();
                    alert('Please enter a goal title.');
                    return false;
                }
                
                const targetValue = $('#newTargetValue').val();
                if (!targetValue || parseFloat(targetValue) <= 0) {
                    e.preventDefault();
                    alert('Please enter a valid target value greater than 0.');
                    return false;
                }
                
                const unit = $('#newUnit').val();
                if (!unit.trim()) {
                    e.preventDefault();
                    alert('Please enter a unit.');
                    return false;
                }
                
                const selectedStudents = $(this).find('.student-select').val();
                if (!selectedStudents || selectedStudents.length === 0) {
                    e.preventDefault();
                    alert('Please select at least one student.');
                    return false;
                }
                
                // Show loading
                $('#createBtn').prop('disabled', true).html('<i class="fas fa-spinner fa-spin"></i> Creating and Assigning...');
            });

            // Initialize countdowns
            updateCountdowns();
            setInterval(updateCountdowns, 60000);

            // Auto-update countdowns when dates change
            $('#due_date, #new_due_date').on('change', updateCountdowns);

            // Mobile sidebar
            const sidebarToggle = document.getElementById('sidebarToggle');
            const sidebar = document.getElementById('sidebar');
            const sidebarClose = document.getElementById('sidebarClose');
            const overlay = document.getElementById('sidebarOverlay');

            sidebarToggle?.addEventListener('click', () => { sidebar.classList.add('active'); overlay.classList.add('active'); });
            sidebarClose?.addEventListener('click', () => { sidebar.classList.remove('active'); overlay.classList.remove('active'); });
            overlay?.addEventListener('click', () => { sidebar.classList.remove('active'); overlay.classList.remove('active'); });

            // Auto-save form data (optional)
            function autoSaveForm(formId) {
                const form = document.getElementById(formId);
                if (!form) return;
                
                const formData = new FormData(form);
                const data = {};
                formData.forEach((value, key) => {
                    data[key] = value;
                });
                
                localStorage.setItem(`form_${formId}`, JSON.stringify(data));
            }

            // Load auto-saved data
            function loadAutoSaved(formId) {
                const saved = localStorage.getItem(`form_${formId}`);
                if (saved) {
                    const data = JSON.parse(saved);
                    const form = document.getElementById(formId);
                    
                    // Populate form fields
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

            // Auto-save every 5 seconds
            setInterval(() => {
                autoSaveForm('assignForm');
                autoSaveForm('createAndAssignForm');
            }, 5000);

            // Load saved data on page load
            loadAutoSaved('assignForm');
            loadAutoSaved('createAndAssignForm');
        });
    </script>
</body>
</html>