<?php
session_start();
require_once '../includes/db_connection.php';
checkAuth('admin');

// === Flash Messages ===
$success = $_SESSION['success'] ?? '';
$error = $_SESSION['error'] ?? '';
unset($_SESSION['success'], $_SESSION['error']);

// === Sidebar Stats ===
$total_students = $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'student'")->fetchColumn() ?: 0;
$active_students = $pdo->query("SELECT COUNT(*) FROM users WHERE role='student' AND status='active'")->fetchColumn() ?: 0;
$inactive_students = $pdo->query("SELECT COUNT(*) FROM users WHERE role='student' AND status='inactive'")->fetchColumn() ?: 0;
$created_today = $pdo->query("SELECT COUNT(*) FROM users WHERE role='student' AND DATE(created_at) = CURDATE()")->fetchColumn() ?: 0;
$total_goals = $pdo->query("SELECT COUNT(*) FROM student_goals")->fetchColumn() ?: 0;
$total_points = $pdo->query("SELECT SUM(points) FROM users WHERE role='student'")->fetchColumn() ?: 0;

// Wrap into sidebar stats matching template
$sidebar_stats = [
    'students' => $total_students,
    'active_students' => $active_students,
    'inactive_students' => $inactive_students,
    'created_today' => $created_today,
    'goals' => $total_goals,
    'assigned' => 0, // optional: calculate actual assigned goals if needed
    'points' => $total_points
];

// === Filters & Search ===
$search = trim($_GET['search'] ?? '');
$status = $_GET['status'] ?? 'all';
$department = trim($_GET['department'] ?? '');

// Build WHERE clause
$where = ["role = 'student'"];
$params = [];

if ($search) {
    $where[] = "(name LIKE ? OR email LIKE ? OR student_id LIKE ?)";
    $like = "%$search%";
    $params[] = $like; $params[] = $like; $params[] = $like;
}
if ($status !== 'all') {
    $where[] = "status = ?";
    $params[] = $status;
}
if ($department) {
    $where[] = "department = ?";
    $params[] = $department;
}

$where_clause = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

// === Pagination ===
$per_page = 15;
$page = max(1, (int)($_GET['page'] ?? 1));
$offset = ($page - 1) * $per_page;

// Count total
$count_stmt = $pdo->prepare("SELECT COUNT(*) FROM users $where_clause");
$count_stmt->execute($params);
$total_records = $count_stmt->fetchColumn();
$total_pages = ceil($total_records / $per_page);

// Fetch students with goal stats
$students_stmt = $pdo->prepare("
    SELECT u.*, 
           COUNT(sg.id) AS total_goals,
           SUM(CASE WHEN sg.status = 'completed' THEN 1 ELSE 0 END) AS completed_goals
    FROM users u
    LEFT JOIN student_goals sg ON u.id = sg.student_id
    $where_clause
    GROUP BY u.id
    ORDER BY u.points DESC, u.name ASC
    LIMIT ? OFFSET ?
");
$params_with_limit = array_merge($params, [$per_page, $offset]);
$students_stmt->execute($params_with_limit);
$students = $students_stmt->fetchAll(PDO::FETCH_ASSOC);

// === Departments for filter dropdown ===
$departments = $pdo->query("SELECT DISTINCT department FROM users WHERE role='student' AND department IS NOT NULL AND department != '' ORDER BY department")->fetchAll(PDO::FETCH_COLUMN);
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

    .btn-info { background: #3b82f6; color: white; }
    .btn-info:hover { background: #2563eb; }

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

    /* ===== STUDENTS TABLE ===== */
    .students-table-container {
        background: white;
        border-radius: 12px;
        overflow: hidden;
        box-shadow: 0 2px 10px rgba(0,0,0,0.05);
    }

    table {
        width: 100%;
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
        vertical-align: middle;
    }

    tr:hover {
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

    .status-badge {
        padding: 4px 10px;
        border-radius: 12px;
        font-size: 12px;
        font-weight: 600;
        text-transform: capitalize;
    }

    .status-active { background: #d1fae5; color: #065f46; }
    .status-inactive { background: #fef3c7; color: #92400e; }
    .status-pending { background: #dbeafe; color: #1e40af; }
    .status-deleted { background: #fee2e2; color: #991b1b; }

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
                <a href="students.php" class="nav-link active">
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
            <header class="page-header">
                <div class="header-content">
                    <h1>Manage Students</h1>
                    <p>View and manage all registered students</p>
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
            
            <!-- Student Stats -->
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-number"><?php echo $total_students; ?></div>
                    <div class="stat-label">Total Students</div>
                </div>
                <div class="stat-card">
                    <div class="stat-number">
                        <?php 
                        $active_count = getStat($pdo, "SELECT COUNT(*) FROM users WHERE role = 'student' AND status = 'active'");
                        echo $active_count;
                        ?>
                    </div>
                    <div class="stat-label">Active Students</div>
                </div>
                <div class="stat-card">
                    <div class="stat-number">
                        <?php 
                        $today_count = getStat($pdo, "SELECT COUNT(*) FROM users WHERE role = 'student' AND DATE(created_at) = CURDATE()");
                        echo $today_count;
                        ?>
                    </div>
                    <div class="stat-label">Registered Today</div>
                </div>
                <div class="stat-card">
                    <div class="stat-number">
                        <?php 
                        $inactive_count = getStat($pdo, "SELECT COUNT(*) FROM users WHERE role = 'student' AND status = 'inactive'");
                        echo $inactive_count;
                        ?>
                    </div>
                    <div class="stat-label">Inactive Students</div>
                </div>
            </div>
            
            <!-- Filters -->
            <div class="filters-section">
                <form method="GET" class="filter-form">
                    <div class="filter-row">
                        <div class="filter-group">
                            <label for="search">Search Students</label>
                            <input type="text" id="search" name="search" placeholder="Search by name, email, or ID..." 
                                   value="<?php echo htmlspecialchars($search); ?>">
                        </div>
                        
                        <div class="filter-group">
                            <label for="status">Status</label>
                            <select id="status" name="status">
                                <option value="all" <?php echo $status === 'all' ? 'selected' : ''; ?>>All Status</option>
                                <option value="active" <?php echo $status === 'active' ? 'selected' : ''; ?>>Active</option>
                                <option value="inactive" <?php echo $status === 'inactive' ? 'selected' : ''; ?>>Inactive</option>
                                <option value="pending" <?php echo $status === 'pending' ? 'selected' : ''; ?>>Pending</option>
                                <option value="deleted" <?php echo $status === 'deleted' ? 'selected' : ''; ?>>Deleted</option>
                            </select>
                        </div>
                        
                        <div class="filter-group">
                            <label for="department">Department</label>
                            <select id="department" name="department">
                                <option value="">All Departments</option>
                                <?php foreach ($departments as $dept): ?>
                                    <option value="<?php echo htmlspecialchars($dept); ?>" 
                                            <?php echo $department === $dept ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($dept); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="filter-group">
                            <button type="submit" class="btn btn-primary" style="width: 100%;">
                                <i class="fas fa-filter"></i> Apply Filters
                            </button>
                        </div>
                        
                        <?php if ($search || $status !== 'all' || $department): ?>
                            <div class="filter-group">
                                <a href="students.php" class="btn btn-outline" style="width: 100%;">
                                    <i class="fas fa-times"></i> Clear Filters
                                </a>
                            </div>
                        <?php endif; ?>
                    </div>
                </form>
            </div>
            
            <!-- Students Table -->
            <div class="students-table-container">
                <?php if (empty($students)): ?>
                    <div class="empty-state">
                        <i class="fas fa-users"></i>
                        <p>No students found</p>
                        <?php if ($search || $status !== 'all' || $department): ?>
                            <p class="small">Try changing your search filters</p>
                            <a href="students.php" class="btn btn-outline">Clear Filters</a>
                        <?php endif; ?>
                    </div>
                <?php else: ?>
                    <table>
                        <thead>
                            <tr>
                                <th>Student</th>
                                <th>Contact Info</th>
                                <th>Department</th>
                                <th>Status</th>
                                <th>Joined</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($students as $student): ?>
                                <tr>
                                    <td>
                                        <div style="display: flex; align-items: center; gap: 10px;">
                                            <div class="student-avatar">
                                                <?php echo strtoupper(substr($student['name'], 0, 1)); ?>
                                            </div>
                                            <div>
                                                <div style="font-weight: 500;"><?php echo htmlspecialchars($student['name']); ?></div>
                                                <?php if ($student['student_id']): ?>
                                                    <div style="font-size: 12px; color: #6b7280;">ID: <?php echo htmlspecialchars($student['student_id']); ?></div>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </td>
                                    <td>
                                        <div><?php echo htmlspecialchars($student['email']); ?></div>
                                        <div style="font-size: 12px; color: #6b7280;">
                                            Last Login: <?php echo $student['last_login'] ? date('M d, Y', strtotime($student['last_login'])) : 'Never'; ?>
                                        </div>
                                    </td>
                                    <td>
                                        <?php echo htmlspecialchars($student['department'] ?? 'N/A'); ?>
                                        <?php if ($student['semester']): ?>
                                            <div style="font-size: 12px; color: #6b7280;">Semester <?php echo $student['semester']; ?></div>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <span class="status-badge status-<?php echo $student['status']; ?>">
                                            <?php echo ucfirst($student['status']); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?php echo date('M d, Y', strtotime($student['created_at'])); ?>
                                    </td>
                                    <td>
                                        <div class="action-buttons">
                                            <?php if ($student['status'] == 'inactive'): ?>
                                                <form method="POST" style="display: inline;">
                                                    <input type="hidden" name="student_id" value="<?php echo $student['id']; ?>">
                                                    <input type="hidden" name="action" value="activate">
                                                    <button type="submit" class="btn btn-sm btn-success" onclick="return confirm('Activate this student?')">
                                                        <i class="fas fa-check"></i> Activate
                                                    </button>
                                                </form>
                                            <?php elseif ($student['status'] == 'active'): ?>
                                                <form method="POST" style="display: inline;">
                                                    <input type="hidden" name="student_id" value="<?php echo $student['id']; ?>">
                                                    <input type="hidden" name="action" value="deactivate">
                                                    <button type="submit" class="btn btn-sm btn-warning" onclick="return confirm('Deactivate this student?')">
                                                        <i class="fas fa-ban"></i> Deactivate
                                                    </button>
                                                </form>
                                            <?php endif; ?>
                                            
                                            <form method="POST" style="display: inline;">
                                                <input type="hidden" name="student_id" value="<?php echo $student['id']; ?>">
                                                <input type="hidden" name="action" value="reset_password">
                                                <button type="submit" class="btn btn-sm btn-info" onclick="return confirm('Reset password to \"student123\"?')">
                                                    <i class="fas fa-key"></i> Reset Password
                                                </button>
                                            </form>
                                            
                                            <?php if ($student['status'] != 'deleted'): ?>
                                                <form method="POST" style="display: inline;">
                                                    <input type="hidden" name="student_id" value="<?php echo $student['id']; ?>">
                                                    <input type="hidden" name="action" value="delete">
                                                    <button type="submit" class="btn btn-sm btn-danger" onclick="return confirm('Are you sure? This will delete the student permanently.')">
                                                        <i class="fas fa-trash"></i> Delete
                                                    </button>
                                                </form>
                                            <?php endif; ?>
                                            
                                            <a href="student_stats.php?id=<?php echo $student['id']; ?>" class="btn btn-sm btn-outline">
                                                <i class="fas fa-chart-bar"></i> Stats
                                            </a>
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
                    <i class="fas fa-tasks"></i> Assign Goals to Students
                </a>
                <a href="reports.php" class="btn btn-outline">
                    <i class="fas fa-chart-bar"></i> View Student Reports
                </a>
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
        
        // Confirm actions
        document.querySelectorAll('form').forEach(form => {
            if (form.querySelector('button[type="submit"][class*="btn-danger"]')) {
                form.addEventListener('submit', function(e) {
                    if (!confirm('Are you sure you want to perform this action?')) {
                        e.preventDefault();
                    }
                });
            }
        });
    </script>
</body>
</html>