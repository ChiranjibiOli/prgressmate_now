<?php
session_start();
require_once '../includes/db_connection.php';
checkAuth('admin');

// === POST Action Handling ===
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $success = '';
    $error = '';
    
    try {
        if (isset($_POST['action'], $_POST['student_id'])) {
            $student_id = (int)$_POST['student_id'];
            $action = $_POST['action'];

            switch ($action) {
                case 'activate':
                    $stmt = $pdo->prepare("UPDATE users SET status = 'active' WHERE id = ? AND role = 'student'");
                    $stmt->execute([$student_id]);
                    $success = 'Student activated successfully.';
                    break;

                case 'deactivate':
                    $stmt = $pdo->prepare("UPDATE users SET status = 'inactive' WHERE id = ? AND role = 'student'");
                    $stmt->execute([$student_id]);
                    $success = 'Student deactivated successfully.';
                    break;

                case 'change_password':
                    if (empty($_POST['new_password']) || empty($_POST['confirm_password'])) {
                        $error = 'Both password fields are required.';
                    } elseif ($_POST['new_password'] !== $_POST['confirm_password']) {
                        $error = 'Passwords do not match.';
                    } elseif (strlen($_POST['new_password']) < 6) {
                        $error = 'Password must be at least 6 characters long.';
                    } else {
                        $hashed = password_hash($_POST['new_password'], PASSWORD_DEFAULT);
                        $stmt = $pdo->prepare("UPDATE users SET password = ? WHERE id = ? AND role = 'student'");
                        $stmt->execute([$hashed, $student_id]);
                        $success = 'Password changed successfully.';
                    }
                    break;

                case 'delete':
                    // Check if student has active goals before deletion
                    $active_goals = $pdo->query("SELECT COUNT(*) FROM student_goals WHERE student_id = $student_id AND status IN ('pending', 'active')")->fetchColumn();
                    
                    if ($active_goals > 0) {
                        $error = "Cannot delete student with $active_goals active goals. Please reassign or complete goals first.";
                    } else {
                        // Set status to 'deleted'
                        $stmt = $pdo->prepare("UPDATE users SET status = 'deleted' WHERE id = ? AND role = 'student'");
                        $stmt->execute([$student_id]);
                        $success = 'Student deleted successfully.';
                    }
                    break;

                case 'edit_student':
                    if (empty($_POST['name']) || empty($_POST['email'])) {
                        $error = 'Name and email are required.';
                    } else {
                        $pdo->beginTransaction();
                        
                        $stmt = $pdo->prepare("
                            UPDATE users 
                            SET name = ?, email = ?, department = ?, semester = ?, student_id = ?, status = ?
                            WHERE id = ? AND role = 'student'
                        ");
                        
                        $stmt->execute([
                            trim($_POST['name']),
                            trim($_POST['email']),
                            $_POST['department'] ?? '',
                            $_POST['semester'] ?? '',
                            $_POST['student_id_number'] ?? '',
                            $_POST['status'] ?? 'active',
                            $student_id
                        ]);
                        
                        $pdo->commit();
                        $success = 'Student information updated successfully.';
                    }
                    break;

                case 'bulk_action':
                    $bulk_action = $_POST['bulk_action'] ?? '';
                    $student_ids = $_POST['student_ids'] ?? [];
                    
                    if (empty($student_ids)) {
                        $error = 'Please select at least one student.';
                    } else {
                        $placeholders = implode(',', array_fill(0, count($student_ids), '?'));
                        
                        if ($bulk_action === 'activate') {
                            $pdo->prepare("UPDATE users SET status = 'active' WHERE id IN ($placeholders) AND role = 'student'")->execute($student_ids);
                            $success = count($student_ids) . ' student(s) activated successfully!';
                        } elseif ($bulk_action === 'deactivate') {
                            $pdo->prepare("UPDATE users SET status = 'inactive' WHERE id IN ($placeholders) AND role = 'student'")->execute($student_ids);
                            $success = count($student_ids) . ' student(s) deactivated successfully!';
                        } elseif ($bulk_action === 'send_welcome') {
                            // Placeholder for email sending functionality
                            $success = 'Welcome emails queued for ' . count($student_ids) . ' student(s).';
                        }
                    }
                    break;

                default:
                    $error = 'Invalid action.';
            }
        }
    } catch (Exception $e) {
        $error = 'Action failed: ' . $e->getMessage();
    }

    $_SESSION['success'] = $success;
    $_SESSION['error'] = $error;
    header('Location: students.php?' . http_build_query($_GET));
    exit();
}

// === Flash Messages ===
$success = $_SESSION['success'] ?? '';
$error = $_SESSION['error'] ?? '';
unset($_SESSION['success'], $_SESSION['error']);

// === Enhanced Stats (using status instead of deleted_at) ===
$stats = [
    'total_students' => $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'student' AND status != 'deleted'")->fetchColumn() ?: 0,
    'active_students' => $pdo->query("SELECT COUNT(*) FROM users WHERE role='student' AND status='active'")->fetchColumn() ?: 0,
    'inactive_students' => $pdo->query("SELECT COUNT(*) FROM users WHERE role='student' AND status='inactive'")->fetchColumn() ?: 0,
    'deleted_students' => $pdo->query("SELECT COUNT(*) FROM users WHERE role='student' AND status='deleted'")->fetchColumn() ?: 0,
    'created_today' => $pdo->query("SELECT COUNT(*) FROM users WHERE role='student' AND DATE(created_at) = CURDATE() AND status != 'deleted'")->fetchColumn() ?: 0,
    'created_week' => $pdo->query("SELECT COUNT(*) FROM users WHERE role='student' AND created_at >= DATE_SUB(CURDATE(), INTERVAL 7 DAY) AND status != 'deleted'")->fetchColumn() ?: 0,
    'total_goals' => $pdo->query("SELECT COUNT(*) FROM student_goals sg JOIN users u ON sg.student_id = u.id WHERE u.status != 'deleted'")->fetchColumn() ?: 0,
    'total_points' => $pdo->query("SELECT COALESCE(SUM(points), 0) FROM users WHERE role='student' AND status != 'deleted'")->fetchColumn() ?: 0,
    'avg_points' => $pdo->query("SELECT COALESCE(AVG(points), 0) FROM users WHERE role='student' AND status='active'")->fetchColumn() ?: 0,
    'avg_goals' => $pdo->query("SELECT COALESCE(AVG(goal_count), 0) FROM (SELECT COUNT(*) as goal_count FROM student_goals GROUP BY student_id) as goals")->fetchColumn() ?: 0
];

$sidebar_stats = [
    'students' => $stats['total_students'],
    'goals' => $stats['total_goals'],
    'points' => $stats['total_points']
];

// === Advanced Filters ===
$search = trim($_GET['search'] ?? '');
$status_filter = $_GET['status'] ?? 'all';
$department = trim($_GET['department'] ?? '');
$semester = trim($_GET['semester'] ?? '');
$date_filter = $_GET['date_filter'] ?? 'all';
$points_min = isset($_GET['points_min']) ? (int)$_GET['points_min'] : '';
$points_max = isset($_GET['points_max']) ? (int)$_GET['points_max'] : '';
$sort_by = $_GET['sort_by'] ?? 'points';
$sort_order = $_GET['sort_order'] ?? 'desc';

$where = ["u.role = 'student'", "u.status != 'deleted'"];
$params = [];

if ($search) {
    $where[] = "(u.name LIKE ? OR u.email LIKE ? OR u.student_id LIKE ? OR u.department LIKE ?)";
    $like = "%$search%";
    array_push($params, $like, $like, $like, $like);
}

if ($status_filter !== 'all' && $status_filter !== 'deleted') {
    $where[] = "u.status = ?";
    $params[] = $status_filter;
}

if ($department) {
    $where[] = "u.department = ?";
    $params[] = $department;
}

if ($semester) {
    $where[] = "u.semester = ?";
    $params[] = $semester;
}

if ($points_min !== '') {
    $where[] = "u.points >= ?";
    $params[] = $points_min;
}

if ($points_max !== '') {
    $where[] = "u.points <= ?";
    $params[] = $points_max;
}

if ($date_filter !== 'all') {
    $today = date('Y-m-d');
    if ($date_filter === 'today') {
        $where[] = "DATE(u.created_at) = ?";
        $params[] = $today;
    } elseif ($date_filter === 'week') {
        $week_ago = date('Y-m-d', strtotime('-7 days'));
        $where[] = "DATE(u.created_at) >= ?";
        $params[] = $week_ago;
    } elseif ($date_filter === 'month') {
        $month_ago = date('Y-m-d', strtotime('-30 days'));
        $where[] = "DATE(u.created_at) >= ?";
        $params[] = $month_ago;
    } elseif ($date_filter === 'year') {
        $year_ago = date('Y-m-d', strtotime('-365 days'));
        $where[] = "DATE(u.created_at) >= ?";
        $params[] = $year_ago;
    }
}

$where_clause = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

// === Sorting Options ===
$sort_options = [
    'name' => 'u.name',
    'points' => 'u.points',
    'created_at' => 'u.created_at',
    'last_login' => 'u.last_login',
    'goals_completed' => 'completed_goals',
    'avg_progress' => 'avg_progress'
];

$order_by = $sort_options[$sort_by] ?? 'u.points';
$order_direction = strtoupper($sort_order) === 'ASC' ? 'ASC' : 'DESC';

// === Pagination ===
$per_page = 15;
$page = max(1, (int)($_GET['page'] ?? 1));
$offset = ($page - 1) * $per_page;

$count_stmt = $pdo->prepare("SELECT COUNT(*) FROM users u $where_clause");
$count_stmt->execute($params);
$total_records = $count_stmt->fetchColumn();
$total_pages = ceil($total_records / $per_page);

// === Fetch Students with Enhanced Stats ===
$students_stmt = $pdo->prepare("
    SELECT u.*, 
           COUNT(DISTINCT sg.id) AS total_goals,
           SUM(CASE WHEN sg.progress_percentage >= 100 THEN 1 ELSE 0 END) AS completed_goals,
           SUM(CASE WHEN sg.status = 'pending' THEN 1 ELSE 0 END) AS pending_goals,
           SUM(CASE WHEN sg.status = 'active' THEN 1 ELSE 0 END) AS active_goals,
           ROUND(AVG(sg.progress_percentage), 1) AS avg_progress,
           MAX(sg.created_at) AS last_goal_created,
           (SELECT COUNT(*) FROM user_achievements ua WHERE ua.user_id = u.id) AS achievements_count,
           (SELECT MAX(login_time) FROM user_sessions WHERE user_id = u.id) AS last_session,
           (SELECT COALESCE(SUM(duration), 0) FROM user_sessions WHERE user_id = u.id) AS total_session_time
    FROM users u
    LEFT JOIN student_goals sg ON u.id = sg.student_id
    $where_clause
    GROUP BY u.id
    ORDER BY $order_by $order_direction
    LIMIT ? OFFSET ?
");
$students_stmt->execute(array_merge($params, [$per_page, $offset]));
$students = $students_stmt->fetchAll(PDO::FETCH_ASSOC);

// === Departments and Semesters ===
$departments = $pdo->query("
    SELECT DISTINCT department, COUNT(*) as count 
    FROM users 
    WHERE role='student' AND status != 'deleted' AND department IS NOT NULL AND department != '' 
    GROUP BY department 
    ORDER BY department
")->fetchAll(PDO::FETCH_ASSOC);

$semesters = $pdo->query("
    SELECT DISTINCT semester, COUNT(*) as count 
    FROM users 
    WHERE role='student' AND status != 'deleted' AND semester IS NOT NULL AND semester != '' 
    GROUP BY semester 
    ORDER BY semester + 0
")->fetchAll(PDO::FETCH_ASSOC);

// === Get top performers ===
$top_performers = $pdo->query("
    SELECT u.name, u.email, u.points, u.profile_picture,
           COUNT(sg.id) as total_goals,
           SUM(CASE WHEN sg.status = 'completed' THEN 1 ELSE 0 END) as completed_goals
    FROM users u
    LEFT JOIN student_goals sg ON u.id = sg.student_id
    WHERE u.role = 'student' AND u.status = 'active'
    GROUP BY u.id
    ORDER BY u.points DESC
    LIMIT 5
")->fetchAll(PDO::FETCH_ASSOC);
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
        .btn-info { background: var(--info); color: white; }
        .btn-outline { background: white; color: var(--primary); border: 1px solid var(--primary); }
        .btn-outline:hover { background: var(--primary); color: white; }
        .btn-sm { padding: 8px 16px; font-size: 13px; }

        .stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 24px; margin-bottom: 40px; }
        .stat-card { background: white; border-radius: var(--radius); padding: 24px; box-shadow: var(--shadow); text-align: center; position: relative; overflow: hidden; }
        .stat-card::before { content: ''; position: absolute; top: 0; left: 0; width: 5px; height: 100%; }
        .stat-card:nth-child(1)::before { background: var(--primary); }
        .stat-card:nth-child(2)::before { background: var(--success); }
        .stat-card:nth-child(3)::before { background: var(--warning); }
        .stat-card:nth-child(4)::before { background: var(--info); }
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
        table { width: 100%; min-width: 1300px; border-collapse: collapse; }
        th { background: var(--gray-200); padding: 18px; text-align: left; font-weight: 600; color: var(--gray-700); position: sticky; top: 0; }
        td { padding: 18px; border-bottom: 1px solid var(--gray-300); vertical-align: middle; }
        tr:hover { background: var(--gray-100); }

        .student-avatar {
            width: 48px;
            height: 48px;
            border-radius: 50%;
            background: linear-gradient(135deg, var(--primary), var(--purple));
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: bold;
            font-size: 18px;
            flex-shrink: 0;
            object-fit: cover;
        }
        .student-avatar img { width: 100%; height: 100%; border-radius: 50%; object-fit: cover; }

        .status-badge {
            padding: 6px 14px;
            border-radius: 20px;
            font-size: 13px;
            font-weight: 600;
            text-transform: capitalize;
            display: inline-block;
        }
        .status-active { background: var(--success-light); color: #065f46; }
        .status-inactive { background: var(--warning-light); color: #92400e; }
        .status-deleted { background: var(--gray-300); color: var(--gray-700); }

        .progress-bar {
            width: 120px;
            height: 10px;
            background: var(--gray-300);
            border-radius: 6px;
            overflow: hidden;
            display: inline-block;
            vertical-align: middle;
        }
        .progress-fill {
            height: 100%;
            background: linear-gradient(90deg, var(--info), var(--primary));
            border-radius: 6px;
            transition: width 0.6s ease;
        }

        .action-buttons { display: flex; flex-wrap: wrap; gap: 8px; }

        .pagination { display: flex; justify-content: center; gap: 8px; padding: 24px; flex-wrap: wrap; }
        .pagination-link { padding: 10px 16px; border-radius: var(--radius-sm); background: white; border: 1px solid var(--gray-300); color: var(--gray-700); transition: var(--transition); }
        .pagination-link:hover { background: var(--gray-200); }
        .pagination-link.active { background: var(--primary); color: white; border-color: var(--primary); }

        .empty-state { text-align: center; padding: 80px 20px; color: var(--gray-500); }
        .empty-state i { font-size: 64px; margin-bottom: 20px; opacity: 0.5; }

        .mobile-toggle { display: none; position: fixed; top: 20px; left: 20px; z-index: 1100; background: var(--primary); color: white; border: none; width: 48px; height: 48px; border-radius: 12px; font-size: 20px; cursor: pointer; box-shadow: var(--shadow); }
        .sidebar-overlay { display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.5); z-index: 999; }

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
            max-width: 600px;
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
        .form-help { font-size: 13px; color: var(--gray-500); margin-top: 6px; }
        .form-row { display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 16px; }

        /* Top Performers */
        .top-performers {
            background: white;
            border-radius: var(--radius);
            padding: 24px;
            margin-bottom: 32px;
            box-shadow: var(--shadow);
        }
        .top-performers h3 { margin-bottom: 20px; display: flex; align-items: center; gap: 10px; }
        .performer-card {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 16px;
            border-bottom: 1px solid var(--gray-200);
            transition: var(--transition);
        }
        .performer-card:last-child { border-bottom: none; }
        .performer-card:hover { background: var(--gray-100); }
        .performer-rank { font-size: 20px; font-weight: 800; color: var(--primary); width: 40px; }
        .performer-info { flex: 1; display: flex; align-items: center; gap: 12px; }
        .performer-points { font-weight: 700; color: var(--success); }

        /* Export Button */
        .export-dropdown {
            position: relative;
            display: inline-block;
        }
        .export-menu {
            display: none;
            position: absolute;
            top: 100%;
            left: 0;
            background: white;
            min-width: 180px;
            border-radius: var(--radius-sm);
            box-shadow: var(--shadow-lg);
            z-index: 100;
            overflow: hidden;
        }
        .export-menu a {
            display: block;
            padding: 12px 16px;
            color: var(--gray-700);
            text-decoration: none;
            transition: var(--transition);
            border-bottom: 1px solid var(--gray-200);
        }
        .export-menu a:last-child { border-bottom: none; }
        .export-menu a:hover { background: var(--gray-100); color: var(--primary); }
        .export-dropdown:hover .export-menu { display: block; }

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
            .export-dropdown { width: 100%; }
            .export-menu { position: static; display: none; width: 100%; }
            .export-dropdown:hover .export-menu { display: none; }
            .export-dropdown.active .export-menu { display: block; }
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
                <a href="students.php" class="nav-link active"><i class="fas fa-users"></i> Students 
                    <?php if ($sidebar_stats['students'] > 0): ?>
                        <span class="badge"><?php echo $sidebar_stats['students']; ?></span>
                    <?php endif; ?>
                </a>
                <a href="goals.php" class="nav-link"><i class="fas fa-bullseye"></i> System Goals 
                    <?php if ($sidebar_stats['goals'] > 0): ?>
                        <span class="badge"><?php echo $sidebar_stats['goals']; ?></span>
                    <?php endif; ?>
                </a>
                <a href="assign_goals.php" class="nav-link"><i class="fas fa-tasks"></i> Assign Goals</a>
                <a href="achievements.php" class="nav-link"><i class="fas fa-trophy"></i> Achievements 
                    <?php if ($sidebar_stats['points'] > 0): ?>
                        <span class="badge"><?php echo $sidebar_stats['points']; ?> pts</span>
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
                        <div class="sidebar-stat-label">Active Students</div>
                        <div class="sidebar-stat-number"><?php echo $stats['active_students']; ?></div>
                    </div>
                </div>
                <div class="sidebar-stat">
                    <div class="sidebar-stat-icon"><i class="fas fa-bullseye"></i></div>
                    <div>
                        <div class="sidebar-stat-label">Total Goals</div>
                        <div class="sidebar-stat-number"><?php echo $stats['total_goals']; ?></div>
                    </div>
                </div>
                <div class="sidebar-stat">
                    <div class="sidebar-stat-icon"><i class="fas fa-trophy"></i></div>
                    <div>
                        <div class="sidebar-stat-label">Avg Points</div>
                        <div class="sidebar-stat-number"><?php echo number_format($stats['avg_points'], 0); ?></div>
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
                    <p>View, search, filter, and manage all registered students</p>
                </div>
                <div>
                    <div class="export-dropdown">
                        <button class="btn btn-outline">
                            <i class="fas fa-download"></i> Export
                            <i class="fas fa-caret-down"></i>
                        </button>
                        <div class="export-menu">
                            <a href="#" onclick="exportData('csv')"><i class="fas fa-file-csv"></i> Export as CSV</a>
                            <a href="#" onclick="exportData('excel')"><i class="fas fa-file-excel"></i> Export as Excel</a>
                            <a href="#" onclick="exportData('pdf')"><i class="fas fa-file-pdf"></i> Export as PDF</a>
                            <a href="#" onclick="window.print()"><i class="fas fa-print"></i> Print Report</a>
                        </div>
                    </div>
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

            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-number"><?php echo $stats['total_students']; ?></div>
                    <div class="stat-label">Total Students</div>
                </div>
                <div class="stat-card">
                    <div class="stat-number"><?php echo $stats['active_students']; ?></div>
                    <div class="stat-label">Active Students</div>
                </div>
                <div class="stat-card">
                    <div class="stat-number"><?php echo $stats['created_week']; ?></div>
                    <div class="stat-label">New This Week</div>
                </div>
                <div class="stat-card">
                    <div class="stat-number"><?php echo number_format($stats['avg_points'], 0); ?></div>
                    <div class="stat-label">Average Points</div>
                </div>
            </div>

            <?php if (!empty($top_performers)): ?>
                <div class="top-performers">
                    <h3><i class="fas fa-trophy"></i> Top 5 Performers</h3>
                    <?php foreach ($top_performers as $index => $performer): ?>
                        <div class="performer-card">
                            <div class="performer-rank">#<?php echo $index + 1; ?></div>
                            <div class="performer-info">
                                <?php if ($performer['profile_picture']): ?>
                                    <img src="../<?php echo htmlspecialchars($performer['profile_picture']); ?>" alt="Profile" class="student-avatar">
                                <?php else: ?>
                                    <div class="student-avatar"><?php echo strtoupper(substr($performer['name'], 0, 1)); ?></div>
                                <?php endif; ?>
                                <div>
                                    <div style="font-weight: 600;"><?php echo htmlspecialchars($performer['name']); ?></div>
                                    <div style="font-size: 13px; color: var(--gray-500);">
                                        <?php echo $performer['completed_goals']; ?> goals completed
                                    </div>
                                </div>
                            </div>
                            <div class="performer-points"><?php echo $performer['points']; ?> pts</div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <div class="filters-section">
                <form method="GET" id="filterForm">
                    <div class="filter-grid">
                        <div class="filter-group">
                            <label><i class="fas fa-search"></i> Search</label>
                            <input type="text" name="search" placeholder="Name, email, ID, or department..." value="<?php echo htmlspecialchars($search); ?>">
                        </div>
                        <div class="filter-group">
                            <label><i class="fas fa-user-check"></i> Status</label>
                            <select name="status">
                                <option value="all" <?php echo $status_filter === 'all' ? 'selected' : ''; ?>>All Status</option>
                                <option value="active" <?php echo $status_filter === 'active' ? 'selected' : ''; ?>>Active</option>
                                <option value="inactive" <?php echo $status_filter === 'inactive' ? 'selected' : ''; ?>>Inactive</option>
                            </select>
                        </div>
                        <div class="filter-group">
                            <label><i class="fas fa-building"></i> Department</label>
                            <select name="department">
                                <option value="">All Departments</option>
                                <?php foreach ($departments as $dept): ?>
                                    <option value="<?php echo htmlspecialchars($dept['department']); ?>" <?php echo $department === $dept['department'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($dept['department']); ?> (<?php echo $dept['count']; ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="filter-group">
                            <label><i class="fas fa-graduation-cap"></i> Semester</label>
                            <select name="semester">
                                <option value="">All Semesters</option>
                                <?php foreach ($semesters as $sem): ?>
                                    <option value="<?php echo htmlspecialchars($sem['semester']); ?>" <?php echo $semester === $sem['semester'] ? 'selected' : ''; ?>>
                                        Semester <?php echo htmlspecialchars($sem['semester']); ?> (<?php echo $sem['count']; ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="filter-group">
                            <label><i class="fas fa-calendar"></i> Date Joined</label>
                            <select name="date_filter">
                                <option value="all" <?php echo $date_filter === 'all' ? 'selected' : ''; ?>>All Time</option>
                                <option value="today" <?php echo $date_filter === 'today' ? 'selected' : ''; ?>>Today</option>
                                <option value="week" <?php echo $date_filter === 'week' ? 'selected' : ''; ?>>Last 7 Days</option>
                                <option value="month" <?php echo $date_filter === 'month' ? 'selected' : ''; ?>>Last 30 Days</option>
                                <option value="year" <?php echo $date_filter === 'year' ? 'selected' : ''; ?>>Last Year</option>
                            </select>
                        </div>
                        <div class="filter-group">
                            <label><i class="fas fa-star"></i> Points Range</label>
                            <div style="display: flex; gap: 8px;">
                                <input type="number" name="points_min" placeholder="Min" value="<?php echo htmlspecialchars($points_min); ?>" min="0">
                                <input type="number" name="points_max" placeholder="Max" value="<?php echo htmlspecialchars($points_max); ?>" min="0">
                            </div>
                        </div>
                        <div class="filter-group">
                            <label><i class="fas fa-sort"></i> Sort By</label>
                            <select name="sort_by">
                                <option value="points" <?php echo $sort_by === 'points' ? 'selected' : ''; ?>>Points</option>
                                <option value="name" <?php echo $sort_by === 'name' ? 'selected' : ''; ?>>Name</option>
                                <option value="created_at" <?php echo $sort_by === 'created_at' ? 'selected' : ''; ?>>Join Date</option>
                                <option value="last_login" <?php echo $sort_by === 'last_login' ? 'selected' : ''; ?>>Last Login</option>
                                <option value="goals_completed" <?php echo $sort_by === 'goals_completed' ? 'selected' : ''; ?>>Goals Completed</option>
                                <option value="avg_progress" <?php echo $sort_by === 'avg_progress' ? 'selected' : ''; ?>>Avg Progress</option>
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
                        <?php if ($search || $status_filter !== 'all' || $department || $semester || $date_filter !== 'all' || $points_min !== '' || $points_max !== ''): ?>
                            <div class="filter-group">
                                <a href="students.php" class="btn btn-outline" style="width: 100%;"><i class="fas fa-times"></i> Clear Filters</a>
                            </div>
                        <?php endif; ?>
                    </div>
                </form>
            </div>

            <!-- Bulk Actions Bar -->
            <div class="bulk-actions" id="bulkActions">
                <div class="bulk-select">
                    <input type="checkbox" id="selectAll" class="select-all-checkbox">
                    <span id="selectedCount">0 students selected</span>
                </div>
                <select id="bulkActionSelect" class="btn btn-outline">
                    <option value="">Bulk Actions</option>
                    <option value="activate">Activate Selected</option>
                    <option value="deactivate">Deactivate Selected</option>
                    <option value="send_welcome">Send Welcome Email</option>
                </select>
                <button id="applyBulkAction" class="btn btn-primary">Apply</button>
                <button id="clearSelection" class="btn btn-outline">Clear Selection</button>
            </div>

            <div class="table-container">
                <?php if (empty($students)): ?>
                    <div class="empty-state">
                        <i class="fas fa-users"></i>
                        <p>No students found</p>
                        <p style="font-size: 14px; margin-top: 10px;">Try adjusting your filters or search terms</p>
                    </div>
                <?php else: ?>
                    <table id="studentsTable">
                        <thead>
                            <tr>
                                <th width="50"><input type="checkbox" id="selectAllHeader"></th>
                                <th>Student</th>
                                <th>Contact Info</th>
                                <th>Department</th>
                                <th>Progress</th>
                                <th>Goals</th>
                                <th>Achievements</th>
                                <th>Points</th>
                                <th>Status</th>
                                <th>Joined</th>
                                <th>Last Active</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($students as $student): 
                                $progress_percent = $student['avg_progress'] ?? 0;
                                $last_login = $student['last_login'] ? date('M d, Y', strtotime($student['last_login'])) : 'Never';
                                $last_session = $student['last_session'] ? date('M d, Y', strtotime($student['last_session'])) : 'N/A';
                                $total_session_hours = round(($student['total_session_time'] ?? 0) / 60, 1);
                            ?>
                                <tr>
                                    <td><input type="checkbox" class="student-checkbox" data-student-id="<?php echo $student['id']; ?>"></td>
                                    <td>
                                        <div style="display: flex; align-items: center; gap: 12px;">
                                            <?php if ($student['profile_picture']): ?>
                                                <img src="../<?php echo htmlspecialchars($student['profile_picture']); ?>" alt="Profile" class="student-avatar">
                                            <?php else: ?>
                                                <div class="student-avatar"><?php echo strtoupper(substr($student['name'] ?? 'U', 0, 1)); ?></div>
                                            <?php endif; ?>
                                            <div>
                                                <div style="font-weight: 600; display: flex; align-items: center; gap: 8px;">
                                                    <?php echo htmlspecialchars($student['name']); ?>
                                                    <?php if ($student['student_id']): ?>
                                                        <span style="font-size: 12px; background: var(--gray-200); color: var(--gray-700); padding: 2px 8px; border-radius: 10px;">
                                                            ID: <?php echo htmlspecialchars($student['student_id']); ?>
                                                        </span>
                                                    <?php endif; ?>
                                                </div>
                                                <div style="font-size: 13px; color: var(--gray-500);">
                                                    <i class="fas fa-envelope"></i> <?php echo htmlspecialchars($student['email']); ?>
                                                </div>
                                            </div>
                                        </div>
                                    </td>
                                    <td>
                                        <div style="font-size: 14px;">
                                            <div><i class="fas fa-envelope"></i> <?php echo htmlspecialchars($student['email']); ?></div>
                                            <?php if ($student['student_id']): ?>
                                                <div><i class="fas fa-id-card"></i> <?php echo htmlspecialchars($student['student_id']); ?></div>
                                            <?php endif; ?>
                                            <div style="margin-top: 4px; color: var(--gray-500); font-size: 12px;">
                                                Last login: <?php echo $last_login; ?>
                                            </div>
                                        </div>
                                    </td>
                                    <td>
                                        <div style="font-weight: 500;"><?php echo htmlspecialchars($student['department'] ?? 'Not set'); ?></div>
                                        <?php if ($student['semester']): ?>
                                            <div style="font-size: 13px; color: var(--gray-500);">
                                                Semester <?php echo htmlspecialchars($student['semester']); ?>
                                            </div>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div class="progress-bar">
                                            <div class="progress-fill" style="width: <?php echo min(100, $progress_percent); ?>%;"></div>
                                        </div>
                                        <div style="font-size: 13px; margin-top: 4px;">
                                            <span style="color: var(--success);"><?php echo number_format($progress_percent, 1); ?>%</span> avg
                                            <?php if ($total_session_hours > 0): ?>
                                                <div style="color: var(--gray-500); font-size: 12px;">
                                                    <i class="fas fa-clock"></i> <?php echo $total_session_hours; ?> hrs
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                    <td>
                                        <div style="text-align: center;">
                                            <div style="font-weight: 700; font-size: 18px; color: var(--primary);">
                                                <?php echo $student['total_goals']; ?>
                                            </div>
                                            <div style="font-size: 12px; color: var(--gray-500); display: flex; gap: 8px; justify-content: center;">
                                                <span title="Completed"><i class="fas fa-check-circle" style="color: var(--success);"></i> <?php echo $student['completed_goals']; ?></span>
                                                <span title="Active"><i class="fas fa-spinner" style="color: var(--info);"></i> <?php echo $student['active_goals']; ?></span>
                                                <span title="Pending"><i class="fas fa-clock" style="color: var(--warning);"></i> <?php echo $student['pending_goals']; ?></span>
                                            </div>
                                        </div>
                                    </td>
                                    <td>
                                        <div style="text-align: center;">
                                            <div style="font-weight: 700; font-size: 18px; color: var(--purple);">
                                                <?php echo $student['achievements_count']; ?>
                                            </div>
                                            <div style="font-size: 12px; color: var(--gray-500);">
                                                achievements
                                            </div>
                                        </div>
                                    </td>
                                    <td>
                                        <div style="text-align: center;">
                                            <div style="font-weight: 700; font-size: 20px; color: var(--success);">
                                                <?php echo $student['points']; ?>
                                            </div>
                                            <div style="font-size: 11px; color: var(--gray-500); text-transform: uppercase; letter-spacing: 0.5px;">
                                                points
                                            </div>
                                        </div>
                                    </td>
                                    <td>
                                        <span class="status-badge status-<?php echo $student['status']; ?>">
                                            <?php echo ucfirst($student['status']); ?>
                                        </span>
                                        <?php if ($student['status'] === 'active' && $student['current_streak']): ?>
                                            <div style="font-size: 12px; color: var(--success); margin-top: 4px;">
                                                <i class="fas fa-fire"></i> Streak: <?php echo $student['current_streak']; ?> days
                                            </div>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div style="font-size: 14px;">
                                            <?php echo date('M d, Y', strtotime($student['created_at'])); ?>
                                            <div style="color: var(--gray-500); font-size: 12px;">
                                                <?php echo date('h:i A', strtotime($student['created_at'])); ?>
                                            </div>
                                        </div>
                                    </td>
                                    <td>
                                        <div style="font-size: 14px;">
                                            <?php echo $last_session; ?>
                                            <?php if ($last_session !== 'N/A'): ?>
                                                <div style="color: var(--gray-500); font-size: 12px;">
                                                    Session: <?php echo $total_session_hours; ?>h
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                    <td>
                                        <div class="action-buttons">
                                            <button class="btn btn-sm btn-outline edit-student-btn" data-student='<?php echo htmlspecialchars(json_encode($student), ENT_QUOTES, 'UTF-8'); ?>'>
                                                <i class="fas fa-edit"></i>
                                            </button>
                                            <?php if ($student['status'] === 'inactive'): ?>
                                                <form method="POST" style="display:inline;">
                                                    <input type="hidden" name="student_id" value="<?php echo $student['id']; ?>">
                                                    <input type="hidden" name="action" value="activate">
                                                    <button type="submit" class="btn btn-sm btn-success" onclick="return confirm('Activate this student?')">
                                                        <i class="fas fa-check"></i>
                                                    </button>
                                                </form>
                                            <?php elseif ($student['status'] === 'active'): ?>
                                                <form method="POST" style="display:inline;">
                                                    <input type="hidden" name="student_id" value="<?php echo $student['id']; ?>">
                                                    <input type="hidden" name="action" value="deactivate">
                                                    <button type="submit" class="btn btn-sm btn-warning" onclick="return confirm('Deactivate this student?')">
                                                        <i class="fas fa-ban"></i>
                                                    </button>
                                                </form>
                                            <?php endif; ?>
                                            <button type="button" class="btn btn-sm btn-info change-password-btn" data-student-id="<?php echo $student['id']; ?>" data-student-name="<?php echo htmlspecialchars($student['name']); ?>">
                                                <i class="fas fa-key"></i>
                                            </button>
                                            <a href="student_stats.php?id=<?php echo $student['id']; ?>" class="btn btn-sm btn-outline" title="View Stats">
                                                <i class="fas fa-chart-bar"></i>
                                            </a>
                                            <a href="assign_goals.php?student_ids[]=<?php echo $student['id']; ?>" class="btn btn-sm btn-primary" title="Assign Goal">
                                                <i class="fas fa-tasks"></i>
                                            </a>
                                            <form method="POST" style="display:inline;">
                                                <input type="hidden" name="student_id" value="<?php echo $student['id']; ?>">
                                                <input type="hidden" name="action" value="delete">
                                                <button type="submit" class="btn btn-sm btn-danger" onclick="return confirm('Are you sure you want to delete this student? This action can be undone.')" title="Delete">
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

            <?php if ($total_pages > 1): ?>
                <div class="pagination">
                    <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                        <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $i])); ?>" 
                           class="pagination-link <?php echo $page == $i ? 'active' : ''; ?>">
                            <?php echo $i; ?>
                        </a>
                    <?php endfor; ?>
                </div>
            <?php endif; ?>

            <div style="margin-top: 40px; display: flex; gap: 16px; flex-wrap: wrap;">
                <a href="assign_goals.php" class="btn btn-primary"><i class="fas fa-tasks"></i> Assign Goals</a>
                <a href="reports.php" class="btn btn-outline"><i class="fas fa-chart-bar"></i> View Reports</a>
                <button class="btn btn-outline" onclick="location.reload()"><i class="fas fa-sync-alt"></i> Refresh</button>
            </div>
        </main>
    </div>

    <!-- Password Change Modal -->
    <div class="modal-overlay" id="passwordModal">
        <div class="modal">
            <div class="modal-header">
                <h3 id="passwordModalTitle">Change Student Password</h3>
                <button class="modal-close" id="closePasswordModal"><i class="fas fa-times"></i></button>
            </div>
            <div class="modal-body">
                <form method="POST" id="passwordForm">
                    <input type="hidden" name="action" value="change_password">
                    <input type="hidden" name="student_id" id="passwordStudentId">

                    <div class="form-group">
                        <label>Student</label>
                        <div style="padding: 12px; background: var(--gray-100); border-radius: var(--radius-sm); font-weight: 500;" id="passwordStudentName">
                            Loading...
                        </div>
                    </div>

                    <div class="form-group">
                        <label>New Password <span class="required"></span></label>
                        <input type="password" name="new_password" id="newPassword" required minlength="6" placeholder="Enter new password (min 6 chars)">
                        <div class="form-help">Password must be at least 6 characters long</div>
                    </div>

                    <div class="form-group">
                        <label>Confirm Password <span class="required"></span></label>
                        <input type="password" name="confirm_password" id="confirmPassword" required minlength="6" placeholder="Confirm new password">
                        <div id="passwordMatch" style="font-size: 13px; margin-top: 6px;"></div>
                    </div>

                    <div class="form-group">
                        <label>Generate Random Password</label>
                        <div style="display: flex; gap: 10px;">
                            <input type="text" id="generatedPassword" readonly style="flex: 1;">
                            <button type="button" class="btn btn-outline" onclick="generatePassword()">
                                <i class="fas fa-random"></i> Generate
                            </button>
                        </div>
                        <div class="form-help">Click generate to create a secure random password</div>
                    </div>

                    <button type="submit" class="btn btn-primary" style="width:100%;">
                        <i class="fas fa-save"></i> Update Password
                    </button>
                </form>
            </div>
        </div>
    </div>

    <!-- Edit Student Modal -->
    <div class="modal-overlay" id="editStudentModal">
        <div class="modal">
            <div class="modal-header">
                <h3>Edit Student Information</h3>
                <button class="modal-close" id="closeEditModal"><i class="fas fa-times"></i></button>
            </div>
            <div class="modal-body">
                <form method="POST" id="editStudentForm">
                    <input type="hidden" name="action" value="edit_student">
                    <input type="hidden" name="student_id" id="editStudentId">

                    <div class="form-row">
                        <div class="form-group">
                            <label>Full Name <span class="required"></span></label>
                            <input type="text" name="name" id="editName" required>
                        </div>
                        <div class="form-group">
                            <label>Email <span class="required"></span></label>
                            <input type="email" name="email" id="editEmail" required>
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label>Student ID</label>
                            <input type="text" name="student_id_number" id="editStudentIdNumber">
                        </div>
                        <div class="form-group">
                            <label>Status</label>
                            <select name="status" id="editStatus">
                                <option value="active">Active</option>
                                <option value="inactive">Inactive</option>
                            </select>
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label>Department</label>
                            <input type="text" name="department" id="editDepartment" list="departmentsList">
                            <datalist id="departmentsList">
                                <?php foreach ($departments as $dept): ?>
                                    <option value="<?php echo htmlspecialchars($dept['department']); ?>">
                                <?php endforeach; ?>
                            </datalist>
                        </div>
                        <div class="form-group">
                            <label>Semester</label>
                            <input type="text" name="semester" id="editSemester" list="semestersList">
                            <datalist id="semestersList">
                                <?php foreach ($semesters as $sem): ?>
                                    <option value="<?php echo htmlspecialchars($sem['semester']); ?>">
                                <?php endforeach; ?>
                            </datalist>
                        </div>
                    </div>

                    <button type="submit" class="btn btn-primary" style="width:100%;">
                        <i class="fas fa-save"></i> Save Changes
                    </button>
                </form>
            </div>
        </div>
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

        // Password Modal
        const passwordModal = document.getElementById('passwordModal');
        const closePasswordModal = document.getElementById('closePasswordModal');
        const passwordForm = document.getElementById('passwordForm');
        const newPassword = document.getElementById('newPassword');
        const confirmPassword = document.getElementById('confirmPassword');
        const passwordMatch = document.getElementById('passwordMatch');
        const generatedPassword = document.getElementById('generatedPassword');

        document.querySelectorAll('.change-password-btn').forEach(btn => {
            btn.addEventListener('click', () => {
                document.getElementById('passwordStudentId').value = btn.dataset.studentId;
                document.getElementById('passwordStudentName').textContent = btn.dataset.studentName;
                document.getElementById('passwordModalTitle').textContent = `Change Password - ${btn.dataset.studentName}`;
                passwordForm.reset();
                passwordMatch.textContent = '';
                passwordModal.classList.add('active');
            });
        });

        closePasswordModal?.addEventListener('click', () => passwordModal.classList.remove('active'));
        passwordModal?.addEventListener('click', (e) => { 
            if (e.target === passwordModal) passwordModal.classList.remove('active'); 
        });

        // Password validation
        function validatePasswords() {
            const pass1 = newPassword.value;
            const pass2 = confirmPassword.value;
            
            if (pass2 === '') {
                passwordMatch.textContent = '';
                passwordMatch.style.color = '';
            } else if (pass1 === pass2) {
                passwordMatch.textContent = '✓ Passwords match';
                passwordMatch.style.color = 'var(--success)';
            } else {
                passwordMatch.textContent = '✗ Passwords do not match';
                passwordMatch.style.color = 'var(--danger)';
            }
        }

        newPassword.addEventListener('input', validatePasswords);
        confirmPassword.addEventListener('input', validatePasswords);

        // Generate random password
        function generatePassword() {
            const length = 12;
            const charset = "abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789!@#$%^&*";
            let password = "";
            
            for (let i = 0; i < length; i++) {
                password += charset.charAt(Math.floor(Math.random() * charset.length));
            }
            
            generatedPassword.value = password;
            newPassword.value = password;
            confirmPassword.value = password;
            validatePasswords();
        }

        // Edit Student Modal
        const editStudentModal = document.getElementById('editStudentModal');
        const closeEditModal = document.getElementById('closeEditModal');
        const editStudentForm = document.getElementById('editStudentForm');

        document.querySelectorAll('.edit-student-btn').forEach(btn => {
            btn.addEventListener('click', function() {
                try {
                    const student = JSON.parse(this.dataset.student);
                    document.getElementById('editStudentId').value = student.id;
                    document.getElementById('editName').value = student.name;
                    document.getElementById('editEmail').value = student.email;
                    document.getElementById('editStudentIdNumber').value = student.student_id || '';
                    document.getElementById('editDepartment').value = student.department || '';
                    document.getElementById('editSemester').value = student.semester || '';
                    document.getElementById('editStatus').value = student.status;
                    editStudentModal.classList.add('active');
                } catch (e) {
                    console.error('Error parsing student data:', e);
                    alert('Error loading student data. Please try again.');
                }
            });
        });

        closeEditModal?.addEventListener('click', () => editStudentModal.classList.remove('active'));
        editStudentModal?.addEventListener('click', (e) => { 
            if (e.target === editStudentModal) editStudentModal.classList.remove('active'); 
        });

        // Bulk actions
        const bulkActions = document.getElementById('bulkActions');
        const selectAll = document.getElementById('selectAllHeader');
        const selectAllCheckbox = document.getElementById('selectAll');
        const selectedCount = document.getElementById('selectedCount');
        const clearSelectionBtn = document.getElementById('clearSelection');
        const applyBulkActionBtn = document.getElementById('applyBulkAction');
        const bulkActionSelect = document.getElementById('bulkActionSelect');

        let selectedStudents = [];

        function updateBulkActions() {
            const checkboxes = document.querySelectorAll('#studentsTable .student-checkbox:checked');
            selectedStudents = Array.from(checkboxes).map(cb => cb.dataset.studentId);
            
            if (selectedStudents.length > 0) {
                bulkActions.style.display = 'flex';
                selectedCount.textContent = `${selectedStudents.length} student${selectedStudents.length !== 1 ? 's' : ''} selected`;
                selectAll.checked = checkboxes.length === document.querySelectorAll('#studentsTable .student-checkbox').length;
                selectAllCheckbox.checked = selectAll.checked;
            } else {
                bulkActions.style.display = 'none';
            }
        }

        // Select all checkboxes
        selectAll?.addEventListener('change', function() {
            const checkboxes = document.querySelectorAll('#studentsTable .student-checkbox');
            checkboxes.forEach(cb => cb.checked = this.checked);
            selectAllCheckbox.checked = this.checked;
            updateBulkActions();
        });

        selectAllCheckbox?.addEventListener('change', function() {
            const checkboxes = document.querySelectorAll('#studentsTable .student-checkbox');
            checkboxes.forEach(cb => cb.checked = this.checked);
            selectAll.checked = this.checked;
            updateBulkActions();
        });

        // Individual checkbox changes
        document.addEventListener('change', function(e) {
            if (e.target.classList.contains('student-checkbox')) {
                updateBulkActions();
            }
        });

        // Clear selection
        clearSelectionBtn?.addEventListener('click', function() {
            const checkboxes = document.querySelectorAll('#studentsTable .student-checkbox');
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
            
            if (!selectedStudents.length) {
                alert('Please select at least one student.');
                return;
            }
            
            if (!confirm(`Are you sure you want to ${action} ${selectedStudents.length} student(s)?`)) {
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
            
            selectedStudents.forEach(studentId => {
                const input = document.createElement('input');
                input.type = 'hidden';
                input.name = 'student_ids[]';
                input.value = studentId;
                form.appendChild(input);
            });
            
            document.body.appendChild(form);
            form.submit();
        });

        // Export functionality
        function exportData(format) {
            const params = new URLSearchParams(window.location.search);
            params.set('export', format);
            window.open(`export_students.php?${params.toString()}`, '_blank');
        }

        // Mobile export dropdown
        document.querySelectorAll('.export-dropdown').forEach(dropdown => {
            const btn = dropdown.querySelector('button');
            const menu = dropdown.querySelector('.export-menu');
            
            btn?.addEventListener('click', function(e) {
                if (window.innerWidth <= 768) {
                    e.preventDefault();
                    dropdown.classList.toggle('active');
                }
            });
            
            // Close when clicking outside
            document.addEventListener('click', function(e) {
                if (!dropdown.contains(e.target)) {
                    dropdown.classList.remove('active');
                }
            });
        });

        // Form submission loading states
        document.querySelectorAll('form').forEach(form => {
            form.addEventListener('submit', function() {
                const submitBtn = this.querySelector('button[type="submit"]');
                if (submitBtn) {
                    const originalText = submitBtn.innerHTML;
                    submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Processing...';
                    submitBtn.disabled = true;
                    
                    setTimeout(() => {
                        submitBtn.disabled = false;
                        submitBtn.innerHTML = originalText;
                    }, 3000);
                }
            });
        });

        // Auto-save filter preferences
        function saveFilters() {
            const form = document.getElementById('filterForm');
            const formData = new FormData(form);
            const data = {};
            formData.forEach((value, key) => {
                data[key] = value;
            });
            localStorage.setItem('studentFilters', JSON.stringify(data));
        }

        function loadFilters() {
            const saved = localStorage.getItem('studentFilters');
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

        // Auto-save every 5 seconds
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
            clockElement.className = 'timer-display';
            clockElement.innerHTML = `<i class="fas fa-clock"></i> ${dateString} ${timeString}`;
            
            const header = document.querySelector('.header-content p');
            if (header) {
                const existingClock = document.querySelector('.header-content .timer-display');
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