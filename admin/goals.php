<?php
session_start();
require_once '../includes/db_connection.php';
checkAuth('admin');

/* ==============================
   Flash Messages
================================ */
$success = $_SESSION['success'] ?? '';
$error = $_SESSION['error'] ?? '';
unset($_SESSION['success'], $_SESSION['error']);

/* ==============================
   Sidebar Stats (Safe Initialization)
================================ */
// Total students
$total_students = $pdo->query("SELECT COUNT(*) FROM users WHERE role='student'")->fetchColumn() ?: 0;

// Total goals
$total_goals = $pdo->query("SELECT COUNT(*) FROM admin_goals")->fetchColumn() ?: 0;

// Total points
$total_points = $pdo->query("SELECT SUM(points) FROM users WHERE role='student'")->fetchColumn() ?: 0;

// Total assigned goals
$total_assigned = $pdo->query("SELECT COUNT(*) FROM student_goals")->fetchColumn() ?: 0;

// Wrap into sidebar stats
$sidebar_stats = [
    'students' => $total_students,
    'goals' => $total_goals,
    'assigned' => $total_assigned,
    'points' => $total_points
];

/* ==============================
   Filters & Search
================================ */
$search = trim($_GET['search'] ?? '');
$status = $_GET['status'] ?? 'all';
$category = trim($_GET['category'] ?? '');

$where = [];
$params = [];

if ($search) {
    $where[] = "(title LIKE ? OR description LIKE ?)";
    $like = "%$search%";
    $params[] = $like;
    $params[] = $like;
}

if ($status !== 'all') {
    $where[] = "status = ?";
    $params[] = $status;
}

if ($category) {
    $where[] = "category = ?";
    $params[] = $category;
}

$where_clause = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

/* ==============================
   Pagination
================================ */
$per_page = 15;
$page = max(1, (int)($_GET['page'] ?? 1));
$offset = ($page - 1) * $per_page;

// Count total records
$count_stmt = $pdo->prepare("SELECT COUNT(*) FROM admin_goals $where_clause");
$count_stmt->execute($params);
$total_records = $count_stmt->fetchColumn();
$total_pages = ceil($total_records / $per_page);

/* ==============================
   Fetch Goals with Assigned Count
================================ */
$goals_stmt = $pdo->prepare("
    SELECT a.*, COUNT(sg.id) AS assigned_count
    FROM admin_goals a
    LEFT JOIN student_goals sg ON sg.goal_id = a.id
    $where_clause
    GROUP BY a.id
    ORDER BY a.priority DESC, a.due_date ASC, a.created_at DESC
    LIMIT ? OFFSET ?
");
$params_with_limit = array_merge($params, [$per_page, $offset]);
$goals_stmt->execute($params_with_limit);
$goals = $goals_stmt->fetchAll(PDO::FETCH_ASSOC);

/* ==============================
   Categories for Filters
================================ */
$categories = $pdo->query("SELECT DISTINCT category FROM admin_goals WHERE category IS NOT NULL AND category != '' ORDER BY category")->fetchAll(PDO::FETCH_COLUMN);

/* ==============================
   Fetch Students for Assign Dropdown
================================ */
$students = $pdo->query("SELECT id, name, email FROM users WHERE role='student' ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);

/* ==============================
   Handle Assign & Create Goal POST
================================ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {

    /* ---- Assign Existing Goal ---- */
    if ($_POST['action'] === 'assign_system_goal') {
        if (empty($_POST['goal_id']) || empty($_POST['student_ids'])) {
            $error = "Please select a goal and at least one student.";
        } else {
            $goal_id = (int)$_POST['goal_id'];
            $student_ids = $_POST['student_ids'];
            $due_date = $_POST['due_date'];
            $priority = $_POST['priority'];

            $stmt = $pdo->prepare("
                INSERT INTO student_goals
                (student_id, goal_id, due_date, priority, assigned_by, status)
                VALUES (?, ?, ?, ?, ?, 'pending')
            ");

            foreach ($student_ids as $student_id) {
                $stmt->execute([
                    $student_id,
                    $goal_id,
                    $due_date,
                    $priority,
                    $_SESSION['user_id']
                ]);
            }

            $_SESSION['success'] = "Goal successfully assigned to selected students.";
            header("Location: assign_goals.php");
            exit;
        }
    }

    /* ---- Create & Assign New Goal ---- */
    if ($_POST['action'] === 'create_and_assign') {
        if (empty($_POST['title']) || empty($_POST['target_value']) || empty($_POST['unit']) || empty($_POST['student_ids'])) {
            $error = "Please fill all required fields.";
        } else {
            $pdo->beginTransaction();
            $stmt = $pdo->prepare("
                INSERT INTO admin_goals
                (title, description, target_value, unit, priority, due_date, created_by)
                VALUES (?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $_POST['title'],
                $_POST['description'] ?? '',
                $_POST['target_value'],
                $_POST['unit'],
                $_POST['priority'],
                $_POST['due_date'] ?? null,
                $_SESSION['user_id']
            ]);

            $new_goal_id = $pdo->lastInsertId();

            $assignStmt = $pdo->prepare("
                INSERT INTO student_goals
                (student_id, goal_id, due_date, priority, assigned_by, status)
                VALUES (?, ?, ?, ?, ?, 'pending')
            ");

            foreach ($_POST['student_ids'] as $student_id) {
                $assignStmt->execute([
                    $student_id,
                    $new_goal_id,
                    $_POST['due_date'] ?? null,
                    $_POST['priority'],
                    $_SESSION['user_id']
                ]);
            }

            $pdo->commit();
            $_SESSION['success'] = "New goal created and assigned successfully.";
            header("Location: assign_goals.php");
            exit;
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage System Goals - ProgressMate</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">

    <style>
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

    /* ===== MAIN CONTENT ===== */
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

    /* ===== PAGE HEADER & BUTTONS ===== */
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
        text-decoration: none;
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

    .btn-success { background: #10b981; color: white; }
    .btn-success:hover { background: #059669; }

    .btn-warning { background: #f59e0b; color: white; }
    .btn-warning:hover { background: #d97706; }

    .btn-danger { background: #ef4444; color: white; }
    .btn-danger:hover { background: #dc2626; }

    /* ===== STATS GRID ===== */
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

    /* ===== ALERTS ===== */
    .alert {
        padding: 15px;
        border-radius: 8px;
        margin-bottom: 20px;
        display: flex;
        align-items: center;
        gap: 10px;
        font-weight: 500;
    }

    .alert-success {
        background: #d1fae5;
        color: #065f46;
        border-left: 4px solid #10b981;
    }

    .alert-error {
        background: #fee2e2;
        color: #991b1b;
        border-left: 4px solid #ef4444;
    }

    /* ===== FILTERS SECTION ===== */
    .filters-section {
        background: white;
        border-radius: 12px;
        padding: 20px;
        margin-bottom: 20px;
        box-shadow: 0 2px 10px rgba(0,0,0,0.05);
    }

    .filter-form label {
        display: block;
        margin-bottom: 8px;
        font-size: 14px;
        color: #374151;
        font-weight: 500;
    }

    .filter-form input[type="text"],
    .filter-form input[type="date"],
    .filter-form select {
        width: 100%;
        padding: 10px 12px;
        border: 1px solid #d1d5db;
        border-radius: 8px;
        font-size: 14px;
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

    /* ===== GOALS TABLE ===== */
    .goals-table-container {
        background: white;
        border-radius: 12px;
        overflow: hidden;
        box-shadow: 0 2px 10px rgba(0,0,0,0.05);
        overflow-x: auto;
    }

    table {
        width: 100%;
        min-width: 1000px;
        border-collapse: collapse;
    }

    th {
        background: #f9fafb;
        padding: 15px;
        text-align: left;
        font-weight: 600;
        color: #374151;
        border-bottom: 1px solid #e5e7eb;
    }

    td {
        padding: 15px;
        border-bottom: 1px solid #f3f4f6;
        vertical-align: top;
    }

    tr:hover {
        background: #f9fafb;
    }

    .status-badge {
        padding: 4px 10px;
        border-radius: 12px;
        font-size: 12px;
        font-weight: 600;
        text-transform: capitalize;
    }

    .status-active { background: #d1fae5; color: #065f46; }
    .status-inactive { background: #fef3c7; color: #92400e; }

    .action-buttons {
        display: flex;
        flex-wrap: wrap;
        gap: 8px;
    }

    .action-buttons .btn-sm {
        font-size: 12px;
    }

    /* ===== PAGINATION ===== */
    .pagination {
        display: flex;
        justify-content: center;
        align-items: center;
        gap: 8px;
        flex-wrap: wrap;
        padding: 20px 0;
    }

    .pagination-link {
        padding: 8px 14px;
        border-radius: 8px;
        background: white;
        border: 1px solid #d1d5db;
        color: #374151;
        text-decoration: none;
        font-size: 14px;
        transition: all 0.2s;
    }

    .pagination-link:hover {
        background: #f3f4f6;
        border-color: #4f46e5;
    }

    .pagination-link.active {
        background: #4f46e5;
        color: white;
        border-color: #4f46e5;
    }

    .pagination-link.disabled {
        background: transparent;
        border: none;
        color: #9ca3af;
        cursor: default;
    }

    /* ===== EMPTY STATE ===== */
    .empty-state {
        text-align: center;
        padding: 60px 20px;
        color: #6b7280;
    }

    .empty-state i {
        font-size: 48px;
        margin-bottom: 15px;
        color: #9ca3af;
    }

    .empty-state p {
        margin: 10px 0;
    }

    .empty-state .small {
        font-size: 13px;
        color: #9ca3af;
    }

    /* ===== MODAL ===== */
    .modal-overlay {
        display: none;
        position: fixed;
        top: 0;
        left: 0;
        width: 100%;
        height: 100%;
        background: rgba(0, 0, 0, 0.5);
        align-items: center;
        justify-content: center;
        z-index: 2000;
    }

    .modal-overlay[style*="flex"] {
        display: flex !important;
    }

    .modal {
        background: white;
        border-radius: 12px;
        width: 90%;
        max-width: 700px;
        max-height: 90vh;
        overflow-y: auto;
        box-shadow: 0 20px 40px rgba(0,0,0,0.15);
    }

    .modal-header {
        padding: 20px;
        border-bottom: 1px solid #e5e7eb;
        display: flex;
        justify-content: space-between;
        align-items: center;
    }

    .modal-header h3 {
        margin: 0;
        font-size: 20px;
        font-weight: 600;
    }

    .modal-close {
        background: none;
        border: none;
        font-size: 20px;
        color: #6b7280;
        cursor: pointer;
    }

    .modal-body {
        padding: 20px;
    }

    .form-row {
        display: flex;
        gap: 15px;
        margin-bottom: 15px;
        flex-wrap: wrap;
    }

    .form-row .filter-group {
        flex: 1;
        min-width: 250px;
    }

    .filter-group label {
        display: block;
        margin-bottom: 8px;
        font-size: 14px;
        color: #374151;
        font-weight: 500;
    }

    .filter-group input,
    .filter-group select,
    .filter-group textarea {
        width: 100%;
        padding: 10px 12px;
        border: 1px solid #d1d5db;
        border-radius: 8px;
        font-size: 14px;
    }

    .filter-group textarea {
        min-height: 100px;
        resize: vertical;
    }

    /* Responsive adjustments */
    @media (max-width: 768px) {
        .form-row {
            flex-direction: column;
        }
        .filter-group {
            min-width: auto;
        }
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
            <div class="profile-pic default"><?php echo strtoupper(substr($_SESSION['name'], 0, 1)); ?></div>
        <?php endif; ?>
        <div class="user-info">
            <h4><?php echo htmlspecialchars($_SESSION['name']); ?></h4>
            <p><?php echo htmlspecialchars($_SESSION['email']); ?></p>
            <span style="font-size:11px; background:#e0e7ff; color:#4f46e5; padding:2px 8px; border-radius:12px;">ADMIN</span>
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
            <?php if (!empty($sidebar_stats['students'])): ?>
                <span class="badge"><?php echo $sidebar_stats['students']; ?></span>
            <?php endif; ?>
        </a>
        <a href="goals.php" class="nav-link active">
            <i class="fas fa-bullseye"></i>
            <span>System Goals</span>
            <?php if (!empty($sidebar_stats['goals'])): ?>
                <span class="badge"><?php echo $sidebar_stats['goals']; ?></span>
            <?php endif; ?>
        </a>
        <a href="assign_goals.php" class="nav-link">
            <i class="fas fa-tasks"></i>
            <span>Assign Goals</span>
            <?php if (!empty($sidebar_stats['assigned'])): ?>
                <span class="badge"><?php echo $sidebar_stats['assigned']; ?></span>
            <?php endif; ?>
        </a>
        <a href="achievements.php" class="nav-link">
            <i class="fas fa-trophy"></i>
            <span>Achievements</span>
            <?php if (!empty($sidebar_stats['points'])): ?>
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
            <div class="sidebar-stat-icon"><i class="fas fa-users"></i></div>
            <div class="sidebar-stat-info">
                <div class="sidebar-stat-label">Students</div>
                <div class="sidebar-stat-number"><?php echo $sidebar_stats['students'] ?? 0; ?></div>
            </div>
        </div>
        <div class="sidebar-stat">
            <div class="sidebar-stat-icon"><i class="fas fa-bullseye"></i></div>
            <div class="sidebar-stat-info">
                <div class="sidebar-stat-label">Goals</div>
                <div class="sidebar-stat-number"><?php echo $sidebar_stats['goals'] ?? 0; ?></div>
            </div>
        </div>
        <div class="sidebar-stat">
            <div class="sidebar-stat-icon"><i class="fas fa-star"></i></div>
            <div class="sidebar-stat-info">
                <div class="sidebar-stat-label">Points</div>
                <div class="sidebar-stat-number"><?php echo $sidebar_stats['points'] ?? 0; ?></div>
            </div>
        </div>
    </div>
</aside>

        
        <!-- Main Content -->
        <main class="main-content">
            <header class="page-header">
                <div class="header-content">
                    <h1>Manage System Goals</h1>
                    <p>Create and manage goals for students</p>
                </div>
                <button class="btn btn-primary" id="addGoalBtn">
                    <i class="fas fa-plus"></i> Add New Goal
                </button>
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
            
            <!-- Goal Stats -->
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-number"><?php echo $total_goals; ?></div>
                    <div class="stat-label">Total Goals</div>
                </div>
                <div class="stat-card">
                    <div class="stat-number">
                        <?php
                        $active_count = getStat($pdo, "SELECT COUNT(*) FROM admin_goals WHERE status = 'active'");
                        echo $active_count;
                        ?>
                    </div>
                    <div class="stat-label">Active Goals</div>
                </div>
                <div class="stat-card">
                    <div class="stat-number">
                        <?php
                        $today_count = getStat($pdo, "SELECT COUNT(*) FROM admin_goals WHERE DATE(created_at) = CURDATE()");
                        echo $today_count;
                        ?>
                    </div>
                    <div class="stat-label">Created Today</div>
                </div>
                <div class="stat-card">
                    <div class="stat-number"><?php echo count($categories); ?></div>
                    <div class="stat-label">Categories</div>
                </div>
            </div>
            
            <!-- Filters -->
            <div class="filters-section">
                <form method="GET" class="filter-form">
                    <div class="filter-row">
                        <div class="filter-group">
                            <label for="search">Search Goals</label>
                            <input type="text" id="search" name="search" placeholder="Search by title or description..."
                                   value="<?php echo htmlspecialchars($search); ?>">
                        </div>
                        
                        <div class="filter-group">
                            <label for="status">Status</label>
                            <select id="status" name="status">
                                <option value="all" <?php echo $status === 'all' ? 'selected' : ''; ?>>All Status</option>
                                <option value="active" <?php echo $status === 'active' ? 'selected' : ''; ?>>Active</option>
                                <option value="inactive" <?php echo $status === 'inactive' ? 'selected' : ''; ?>>Inactive</option>
                            </select>
                        </div>
                        
                        <div class="filter-group">
                            <label for="category">Category</label>
                            <select id="category" name="category">
                                <option value="">All Categories</option>
                                <?php foreach ($categories as $cat): ?>
                                    <option value="<?php echo htmlspecialchars($cat); ?>"
                                            <?php echo $category === $cat ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($cat); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="filter-group">
                            <button type="submit" class="btn btn-primary" style="width: 100%;">
                                <i class="fas fa-filter"></i> Apply Filters
                            </button>
                        </div>
                        
                        <?php if ($search || $status !== 'all' || $category): ?>
                            <div class="filter-group">
                                <a href="goals.php" class="btn btn-outline" style="width: 100%;">
                                    <i class="fas fa-times"></i> Clear Filters
                                </a>
                            </div>
                        <?php endif; ?>
                    </div>
                </form>
            </div>
            
            <!-- Goals Table -->
            <div class="goals-table-container">
                <?php if (empty($goals)): ?>
                    <div class="empty-state">
                        <i class="fas fa-bullseye"></i>
                        <p>No goals found</p>
                        <?php if ($search || $status !== 'all' || $category): ?>
                            <p class="small">Try changing your search filters</p>
                            <a href="goals.php" class="btn btn-outline">Clear Filters</a>
                        <?php else: ?>
                            <button class="btn btn-outline" id="addGoalBtnEmpty">Add Your First Goal</button>
                        <?php endif; ?>
                    </div>
                <?php else: ?>
                    <table>
                        <thead>
                            <tr>
                                <th>Title</th>
                                <th>Description</th>
                                <th>Category</th>
                                <th>Target</th>
                                <th>Due Date</th>
                                <th>Priority</th>
                                <th>Points</th>
                                <th>Status</th>
                                <th>Created</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($goals as $goal): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($goal['title']); ?></td>
                                    <td><?php echo htmlspecialchars(substr($goal['description'] ?? '', 0, 100)) . (strlen($goal['description'] ?? '') > 100 ? '...' : ''); ?></td>
                                    <td><?php echo htmlspecialchars($goal['category'] ?? 'N/A'); ?></td>
                                    <td><?php echo htmlspecialchars($goal['target_value'] . ' ' . $goal['unit']); ?></td>
                                    <td><?php echo $goal['due_date'] ? date('M d, Y', strtotime($goal['due_date'])) : 'No due date'; ?></td>
                                    <td><?php echo ucfirst(htmlspecialchars($goal['priority'])); ?></td>
                                    <td><?php echo htmlspecialchars($goal['points'] ?? 0); ?></td>
                                    <td>
                                        <span class="status-badge status-<?php echo $goal['status'] == 'active' ? 'active' : 'inactive'; ?>">
                                            <?php echo ucfirst($goal['status']); ?>
                                        </span>
                                    </td>
                                    <td><?php echo date('M d, Y', strtotime($goal['created_at'])); ?></td>
                                    <td>
                                        <div class="action-buttons">
                                            <button class="btn btn-sm btn-outline edit-goal"
                                                    data-goal='<?php echo json_encode([
                                                        "id" => $goal["id"],
                                                        "title" => $goal["title"],
                                                        "description" => $goal["description"] ?? "",
                                                        "category" => $goal["category"] ?? "General",
                                                        "target_value" => $goal["target_value"] ?? 100,
                                                        "unit" => $goal["unit"] ?? "points",
                                                        "due_date" => $goal["due_date"] ?? "",
                                                        "priority" => $goal["priority"] ?? "medium",
                                                        "points" => $goal["points"] ?? 10,
                                                        "icon" => $goal["icon"] ?? "fas fa-bullseye",
                                                        "color" => $goal["color"] ?? "#4f46e5",
                                                        "status" => $goal["status"] ?? "active"
                                                    ]); ?>'>
                                                <i class="fas fa-edit"></i> Edit
                                            </button>
                                            
                                            <?php if ($goal['status'] != 'active'): ?>
                                                <form method="POST" style="display: inline;">
                                                    <input type="hidden" name="goal_id" value="<?php echo $goal['id']; ?>">
                                                    <input type="hidden" name="action" value="activate">
                                                    <button type="submit" class="btn btn-sm btn-success" onclick="return confirm('Activate this goal?')">
                                                        <i class="fas fa-check"></i> Activate
                                                    </button>
                                                </form>
                                            <?php else: ?>
                                                <form method="POST" style="display: inline;">
                                                    <input type="hidden" name="goal_id" value="<?php echo $goal['id']; ?>">
                                                    <input type="hidden" name="action" value="deactivate">
                                                    <button type="submit" class="btn btn-sm btn-warning" onclick="return confirm('Deactivate this goal?')">
                                                        <i class="fas fa-ban"></i> Deactivate
                                                    </button>
                                                </form>
                                            <?php endif; ?>
                                            
                                            <form method="POST" style="display: inline;">
                                                <input type="hidden" name="goal_id" value="<?php echo $goal['id']; ?>">
                                                <input type="hidden" name="action" value="delete">
                                                <button type="submit" class="btn btn-sm btn-danger" onclick="return confirm('Are you sure? This will delete the goal permanently.')">
                                                    <i class="fas fa-trash"></i> Delete
                                                </button>
                                            </form>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    
                    <!-- Pagination -->
                    <?php if ($total_pages > 1): ?>
                        <div style="padding: 20px; border-top: 1px solid #e5e7eb;">
                            <div class="pagination">
                                <?php if ($page > 1): ?>
                                    <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page - 1])); ?>" class="pagination-link">
                                        <i class="fas fa-chevron-left"></i> Previous
                                    </a>
                                <?php endif; ?>
                                
                                <?php for ($i = 1; $i <= min(5, $total_pages); $i++): ?>
                                    <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $i])); ?>"
                                       class="pagination-link <?php echo $i == $page ? 'active' : ''; ?>">
                                        <?php echo $i; ?>
                                    </a>
                                <?php endfor; ?>
                                
                                <?php if ($total_pages > 5): ?>
                                    <span class="pagination-link disabled">...</span>
                                    <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $total_pages])); ?>"
                                       class="pagination-link <?php echo $total_pages == $page ? 'active' : ''; ?>">
                                        <?php echo $total_pages; ?>
                                    </a>
                                <?php endif; ?>
                                
                                <?php if ($page < $total_pages): ?>
                                    <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page + 1])); ?>" class="pagination-link">
                                        Next <i class="fas fa-chevron-right"></i>
                                    </a>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
            
            <!-- Quick Actions -->
            <div style="display: flex; gap: 15px; margin-top: 30px;">
                <a href="assign_goals.php" class="btn btn-primary">
                    <i class="fas fa-tasks"></i> Assign Goals
                </a>
                <a href="reports.php" class="btn btn-outline">
                    <i class="fas fa-chart-bar"></i> View Goal Reports
                </a>
            </div>
        </main>
    </div>
    
    <!-- Goal Modal -->
    <div class="modal-overlay" id="goalModalOverlay">
        <div class="modal">
            <div class="modal-header">
                <h3 id="modalTitle">Add New Goal</h3>
                <button class="modal-close" id="closeModal">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <div class="modal-body">
                <form method="POST" id="goalForm">
                    <input type="hidden" name="action" id="formAction" value="add">
                    <input type="hidden" name="goal_id" id="goalId">
                    
                    <div class="form-row">
                        <div class="filter-group">
                            <label for="title">Title *</label>
                            <input type="text" id="title" name="title" required>
                        </div>
                        
                        <div class="filter-group">
                            <label for="category">Category</label>
                            <input type="text" id="category" name="category">
                        </div>
                    </div>
                    
                    <div class="form-row">
                        <div class="filter-group">
                            <label for="target_value">Target Value</label>
                            <input type="number" id="target_value" name="target_value" min="1" value="100">
                        </div>
                        
                        <div class="filter-group">
                            <label for="unit">Unit</label>
                            <input type="text" id="unit" name="unit" value="points">
                        </div>
                    </div>
                    
                    <div class="form-row">
                        <div class="filter-group">
                            <label for="due_date">Due Date</label>
                            <input type="date" id="due_date" name="due_date">
                        </div>
                        
                        <div class="filter-group">
                            <label for="priority">Priority</label>
                            <select id="priority" name="priority">
                                <option value="low">Low</option>
                                <option value="medium" selected>Medium</option>
                                <option value="high">High</option>
                                <option value="critical">Critical</option>
                            </select>
                        </div>
                    </div>
                    
                    <div class="form-row">
                        <div class="filter-group">
                            <label for="points">Points</label>
                            <input type="number" id="points" name="points" min="0" value="10">
                        </div>
                        
                        <div class="filter-group">
                            <label for="icon">Icon (Font Awesome class)</label>
                            <input type="text" id="icon" name="icon" value="fas fa-bullseye">
                        </div>
                    </div>
                    
                    <div class="form-row">
                        <div class="filter-group">
                            <label for="color">Color</label>
                            <input type="color" id="color" name="color" value="#4f46e5">
                        </div>
                        
                        <div class="filter-group">
                            <label for="status">Status</label>
                            <select id="status" name="status">
                                <option value="active">Active</option>
                                <option value="inactive">Inactive</option>
                            </select>
                        </div>
                    </div>
                    
                    <div class="filter-group">
                        <label for="description">Description</label>
                        <textarea id="description" name="description"></textarea>
                    </div>
                    
                    <button type="submit" class="btn btn-primary" style="width: 100%;">
                        <i class="fas fa-save"></i> Save Goal
                    </button>
                </form>
            </div>
        </div>
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
            if (window.innerWidth <= 768 &&
                sidebar && sidebar.classList.contains('active') &&
                !sidebar.contains(event.target) &&
                !sidebarToggle.contains(event.target)) {
                sidebar.classList.remove('active');
            }
        });
        
        // Handle window resize
        window.addEventListener('resize', function() {
            if (window.innerWidth > 768 && sidebar && sidebar.classList.contains('active')) {
                sidebar.classList.remove('active');
            }
        });
        
        // Goal Modal
        const addGoalBtn = document.getElementById('addGoalBtn');
        const addGoalBtnEmpty = document.getElementById('addGoalBtnEmpty');
        const goalModalOverlay = document.getElementById('goalModalOverlay');
        const closeModal = document.getElementById('closeModal');
        const modalTitle = document.getElementById('modalTitle');
        const formAction = document.getElementById('formAction');
        const goalId = document.getElementById('goalId');
        const titleInput = document.getElementById('title');
        const descriptionInput = document.getElementById('description');
        const categoryInput = document.getElementById('category');
        const targetValueInput = document.getElementById('target_value');
        const unitInput = document.getElementById('unit');
        const dueDateInput = document.getElementById('due_date');
        const priorityInput = document.getElementById('priority');
        const pointsInput = document.getElementById('points');
        const iconInput = document.getElementById('icon');
        const colorInput = document.getElementById('color');
        const statusInput = document.getElementById('status');
        
        function openAddModal() {
            modalTitle.textContent = 'Add New Goal';
            formAction.value = 'add';
            goalId.value = '';
            titleInput.value = '';
            descriptionInput.value = '';
            categoryInput.value = 'General';
            targetValueInput.value = '100';
            unitInput.value = 'points';
            dueDateInput.value = '';
            priorityInput.value = 'medium';
            pointsInput.value = '10';
            iconInput.value = 'fas fa-bullseye';
            colorInput.value = '#4f46e5';
            statusInput.value = 'active';
            goalModalOverlay.style.display = 'flex';
        }
        
        if (addGoalBtn) {
            addGoalBtn.addEventListener('click', openAddModal);
        }
        
        if (addGoalBtnEmpty) {
            addGoalBtnEmpty.addEventListener('click', openAddModal);
        }
        
        if (closeModal) {
            closeModal.addEventListener('click', function() {
                goalModalOverlay.style.display = 'none';
            });
        }
        
        goalModalOverlay.addEventListener('click', function(event) {
            if (event.target === goalModalOverlay) {
                goalModalOverlay.style.display = 'none';
            }
        });
        
        // Edit buttons
        document.querySelectorAll('.edit-goal').forEach(btn => {
            btn.addEventListener('click', function() {
                const goal = JSON.parse(this.dataset.goal);
                modalTitle.textContent = 'Edit Goal';
                formAction.value = 'edit';
                goalId.value = goal.id;
                titleInput.value = goal.title;
                descriptionInput.value = goal.description;
                categoryInput.value = goal.category;
                targetValueInput.value = goal.target_value;
                unitInput.value = goal.unit;
                dueDateInput.value = goal.due_date;
                priorityInput.value = goal.priority;
                pointsInput.value = goal.points;
                iconInput.value = goal.icon;
                colorInput.value = goal.color;
                statusInput.value = goal.status;
                goalModalOverlay.style.display = 'flex';
            });
        });
    </script>
</body>
</html>