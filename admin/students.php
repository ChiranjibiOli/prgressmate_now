<?php
// admin/students.php - Manage Students with Full CRUD

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

// Initialize variables
$success = '';
$error = '';

// === ADD NEW STUDENT ===
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_student'])) {
    if (!hash_equals($csrf_token, $_POST['csrf_token'] ?? '')) {
        $error = 'Invalid CSRF token.';
    } else {
        $name = trim($_POST['name'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';
        $student_id = trim($_POST['student_id'] ?? '');
        $department = trim($_POST['department'] ?? '');
        $semester = trim($_POST['semester'] ?? '');
        
        // Validate required fields
        if (empty($name) || empty($email) || empty($password)) {
            $error = 'Name, email, and password are required.';
        } elseif (strlen($password) < 8) {
            $error = 'Password must be at least 8 characters.';
        } else {
            try {
                // Check if email already exists
                $check_email = $pdo->prepare("SELECT id FROM users WHERE email = ? AND deleted_at IS NULL");
                $check_email->execute([$email]);
                if ($check_email->fetch()) {
                    $error = 'Email already exists.';
                } elseif ($student_id) {
                    // Check if student ID already exists
                    $check_student_id = $pdo->prepare("SELECT id FROM users WHERE student_id = ? AND deleted_at IS NULL");
                    $check_student_id->execute([$student_id]);
                    if ($check_student_id->fetch()) {
                        $error = 'Student ID already exists.';
                    }
                }
                
                if (!$error) {
                    $hashed_password = password_hash($password, PASSWORD_ARGON2ID);
                    
                    // Insert new student
                    $stmt = $pdo->prepare("
                        INSERT INTO users (name, email, password, student_id, department, semester, role, status, created_at) 
                        VALUES (?, ?, ?, ?, ?, ?, 'student', 'active', NOW())
                    ");
                    
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $error = 'Invalid email format.';
}
                    
                    if ($stmt->execute([$name, $email, $hashed_password, $student_id ?: null, $department ?: null, $semester ?: null])) {
                        $student_id_inserted = $pdo->lastInsertId();
                        
                        // Assign default achievements to new student
                        $default_achievements = $pdo->query("
                            SELECT id FROM achievements 
                            WHERE is_active = 1 AND criteria_type = 'on_registration'
                        ")->fetchAll(PDO::FETCH_COLUMN);
                        
                        foreach ($default_achievements as $achievement_id) {
                            $pdo->prepare("
                                INSERT INTO user_achievements (user_id, achievement_id, earned_at)
                                VALUES (?, ?, NOW())
                                ON DUPLICATE KEY UPDATE earned_at = VALUES(earned_at)
                            ")->execute([$student_id_inserted, $achievement_id]);
                        }
                        
                        $success = 'Student added successfully!';
                        $_SESSION['success'] = $success;
                        header('Location: students.php');
                        exit;
                    } else {
                        $error = 'Failed to add student.';
                    }
                }
            } catch (Exception $e) {
                error_log('Add student error: ' . $e->getMessage());
                $error = 'Failed to add student. Please try again.';
            }
        }
    }
}

// === EDIT STUDENT ===
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_student'])) {
    if (!hash_equals($csrf_token, $_POST['csrf_token'] ?? '')) {
        $error = 'Invalid CSRF token.';
    } else {
        $student_id = (int)($_POST['student_id'] ?? 0);
        $name = trim($_POST['name'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $student_id_number = trim($_POST['student_id_number'] ?? '');
        $department = trim($_POST['department'] ?? '');
        $semester = trim($_POST['semester'] ?? '');
        $status = $_POST['status'] ?? 'active';
        
        if (empty($name) || empty($email)) {
            $error = 'Name and email are required.';
        } else {
            try {
                // Check if email belongs to another student
                $check_email = $pdo->prepare("
                    SELECT id FROM users 
                    WHERE email = ? AND id != ? AND deleted_at IS NULL
                ");
                $check_email->execute([$email, $student_id]);
                if ($check_email->fetch()) {
                    $error = 'Email already in use by another student.';
                } elseif ($student_id_number) {
                    // Check if student ID belongs to another student
                    $check_student_id = $pdo->prepare("
                        SELECT id FROM users 
                        WHERE student_id = ? AND id != ? AND deleted_at IS NULL
                    ");
                    $check_student_id->execute([$student_id_number, $student_id]);
                    if ($check_student_id->fetch()) {
                        $error = 'Student ID already in use by another student.';
                    }
                }
                
                if (!$error) {
                    $stmt = $pdo->prepare("
                        UPDATE users 
                        SET name = ?, email = ?, student_id = ?, department = ?, semester = ?, status = ?, updated_at = NOW()
                        WHERE id = ? AND role = 'student' AND deleted_at IS NULL
                    ");
                    
                    if ($stmt->execute([$name, $email, $student_id_number ?: null, $department ?: null, $semester ?: null, $status, $student_id])) {
                        $success = 'Student updated successfully!';
                        $_SESSION['success'] = $success;
                        header('Location: students.php');
                        exit;
                    } else {
                        $error = 'Failed to update student.';
                    }
                }
            } catch (Exception $e) {
                error_log('Edit student error: ' . $e->getMessage());
                $error = 'Failed to update student. Please try again.';
            }
        }
    }
}

// === CHANGE PASSWORD ===
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['change_password'])) {
    if (!hash_equals($csrf_token, $_POST['csrf_token'] ?? '')) {
        $error = 'Invalid CSRF token.';
    } else {
        $student_id = (int)($_POST['student_id'] ?? 0);
        $new_password = $_POST['new_password'] ?? '';
        $confirm_password = $_POST['confirm_password'] ?? '';
        
        if (empty($new_password)) {
            $error = 'New password is required.';
        } elseif (strlen($new_password) < 8) {
            $error = 'Password must be at least 8 characters.';
        } elseif ($new_password !== $confirm_password) {
            $error = 'Passwords do not match.';
        } else {
            try {
                $hashed_password = password_hash($new_password, PASSWORD_ARGON2ID);
                
                $stmt = $pdo->prepare("
                    UPDATE users 
                    SET password = ?, updated_at = NOW() 
                    WHERE id = ? AND role = 'student' AND deleted_at IS NULL
                ");
                
                if ($stmt->execute([$hashed_password, $student_id])) {
                    $success = 'Password changed successfully!';
                    $_SESSION['success'] = $success;
                    header('Location: students.php');
                    exit;
                } else {
                    $error = 'Failed to change password.';
                }
            } catch (Exception $e) {
                error_log('Change password error: ' . $e->getMessage());
                $error = 'Failed to change password. Please try again.';
            }
        }
    }
}

// === TOGGLE STATUS ===
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['toggle_status'])) {
    if (!hash_equals($csrf_token, $_POST['csrf_token'] ?? '')) {
        $error = 'Invalid CSRF token.';
    } else {
        $student_id = (int)($_POST['student_id'] ?? 0);
        $action = $_POST['action'] ?? '';
        
        if (!in_array($action, ['activate', 'deactivate'])) {
            $error = 'Invalid action.';
        } else {
            try {
                $new_status = $action === 'activate' ? 'active' : 'inactive';
                
                $stmt = $pdo->prepare("
                    UPDATE users 
                    SET status = ?, updated_at = NOW() 
                    WHERE id = ? AND role = 'student' AND deleted_at IS NULL
                ");
                
                if ($stmt->execute([$new_status, $student_id])) {
                    $success = $action === 'activate' ? 'Student activated successfully!' : 'Student deactivated successfully!';
                    $_SESSION['success'] = $success;
                    header('Location: students.php');
                    exit;
                } else {
                    $error = 'Failed to update student status.';
                }
            } catch (Exception $e) {
                error_log('Toggle status error: ' . $e->getMessage());
                $error = 'Failed to update student status. Please try again.';
            }
        }
    }
}

// === BULK ACTIONS ===
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['bulk_action'])) {
    if (!hash_equals($csrf_token, $_POST['csrf_token'] ?? '')) {
        $error = 'Invalid CSRF token.';
    } else {
        $bulk_action = $_POST['bulk_action'] ?? '';
        $student_ids = $_POST['student_ids'] ?? [];
        
        if (empty($student_ids)) {
            $error = 'No students selected.';
        } elseif (!in_array($bulk_action, ['activate', 'deactivate', 'delete'])) {
            $error = 'Invalid bulk action.';
        } else {
            try {
                $placeholders = implode(',', array_fill(0, count($student_ids), '?'));
                
                // Instead of array_reverse, do this:
if ($bulk_action === 'delete') {
    // Soft delete
    $stmt = $pdo->prepare("
        UPDATE users 
        SET deleted_at = NOW(), status = 'inactive', updated_at = NOW()
        WHERE id IN ($placeholders) AND role = 'student' AND deleted_at IS NULL
    ");
    $stmt->execute($student_ids);
} else {
    // Activate/Deactivate
    $new_status = $bulk_action === 'activate' ? 'active' : 'inactive';
    $stmt = $pdo->prepare("
        UPDATE users 
        SET status = ?, updated_at = NOW() 
        WHERE id IN ($placeholders) AND role = 'student' AND deleted_at IS NULL
    ");
    // Prepend status to the array
    $params = array_merge([$new_status], $student_ids);
    $stmt->execute($params);
}
                
                if ($stmt->execute($student_ids)) {
                    $success = 'Bulk action completed successfully!';
                    $_SESSION['success'] = $success;
                    header('Location: students.php');
                    exit;
                } else {
                    $error = 'Failed to perform bulk action.';
                }
            } catch (Exception $e) {
                error_log('Bulk action error: ' . $e->getMessage());
                $error = 'Failed to perform bulk action. Please try again.';
            }
        }
    }
}

// === DELETE STUDENT ===
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_student'])) {
    if (!hash_equals($csrf_token, $_POST['csrf_token'] ?? '')) {
        $error = 'Invalid CSRF token.';
    } else {
        $student_id = (int)($_POST['student_id'] ?? 0);
        
        try {
            // Check if student has active goals
            $check_goals = $pdo->prepare("
                SELECT COUNT(*) FROM student_goals 
                WHERE student_id = ? AND status IN ('pending', 'in_progress') AND deleted_at IS NULL
            ");
            $check_goals->execute([$student_id]);
            
            if ($check_goals->fetchColumn() > 0) {
                $error = 'Cannot delete student with active/in-progress goals.';
                $_SESSION['error'] = $error;
                header('Location: students.php');
                exit;
            }
            
            // Soft delete the student
            $stmt = $pdo->prepare("
                UPDATE users 
                SET deleted_at = NOW(), status = 'inactive', updated_at = NOW()
                WHERE id = ? AND role = 'student' AND deleted_at IS NULL
            ");
            
            if ($stmt->execute([$student_id])) {
                $success = 'Student deleted successfully!';
                $_SESSION['success'] = $success;
                header('Location: students.php');
                exit;
            } else {
                $error = 'Failed to delete student.';
            }
        } catch (Exception $e) {
            error_log('Delete student error: ' . $e->getMessage());
            $error = 'Failed to delete student. Please try again.';
        }
    }
}

// Check for session messages
if (isset($_SESSION['success'])) {
    $success = $_SESSION['success'];
    unset($_SESSION['success']);
}
if (isset($_SESSION['error'])) {
    $error = $_SESSION['error'];
    unset($_SESSION['error']);
}

// Stats - OPTIMIZED
$stats = [
    'total_students' => $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'student' AND deleted_at IS NULL")->fetchColumn(),
    'active_students' => $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'student' AND status = 'active' AND deleted_at IS NULL")->fetchColumn(),
    'inactive_students' => $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'student' AND status = 'inactive' AND deleted_at IS NULL")->fetchColumn(),
    'total_goals' => $pdo->query("SELECT COUNT(*) FROM student_goals WHERE deleted_at IS NULL")->fetchColumn(), // Simplified
    'total_points' => $pdo->query("SELECT COALESCE(SUM(points), 0) FROM users WHERE role = 'student' AND deleted_at IS NULL")->fetchColumn(),
];

$sidebar_stats = [
    'students' => $stats['total_students'],
    'goals' => $stats['total_goals'],
    'points' => $stats['total_points']
];

// Get unique departments
$departments = $pdo->query("SELECT DISTINCT department FROM users WHERE department IS NOT NULL AND department != '' ORDER BY department")
    ->fetchAll(PDO::FETCH_COLUMN);

// Filters & Pagination
$search = trim($_GET['search'] ?? '');
$status_filter = $_GET['status'] ?? 'all';
$department_filter = $_GET['department'] ?? 'all';
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
if ($department_filter !== 'all') {
    $where[] = "u.department = ?";
    $params[] = $department_filter;
}

$where_clause = $where ? 'WHERE ' . implode(' AND ', $where) : '';

// Valid sort columns
$sort_columns = ['name', 'email', 'points', 'created_at', 'status'];
$order_by = in_array($sort_by, $sort_columns) ? "u.$sort_by" : 'u.name';
$order_dir = strtoupper($sort_order) === 'DESC' ? 'DESC' : 'ASC';

$per_page = 15;
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
            --info-light: #eff6ff;
            --warning-light: #fef3c7;
            --danger-light: #fee2e2;
            --primary-light: #e0e7ff;
        }

        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Inter', sans-serif; background: var(--gray-100); color: var(--gray-900); line-height: 1.6; }
        a { text-decoration: none; color: inherit; transition: var(--transition); }
        
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
        
        .sidebar-close {
            display: none;
            background: none;
            border: none;
            font-size: 20px;
            color: var(--gray-500);
            cursor: pointer;
        }
        
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
        
        .nav-menu {
            flex: 1;
            padding: 16px 0;
            overflow-y: auto;
        }
        
        .nav-link {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 14px 20px;
            color: var(--gray-700);
            transition: var(--transition);
        }
        
        .nav-link:hover {
            background: var(--gray-200);
            color: var(--primary);
            transform: translateX(4px);
        }
        
        .nav-link.active {
            background: #eef2ff;
            color: var(--primary);
            border-left: 4px solid var(--primary);
            font-weight: 600;
        }
        
        .nav-link i {
            width: 20px;
            text-align: center;
        }
        
        .badge {
            margin-left: auto;
            background: var(--primary);
            color: white;
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            min-width: 28px;
            text-align: center;
        }
        
        .sidebar-quick-stats {
            padding: 20px;
            border-top: 1px solid var(--gray-300);
        }
        
        .sidebar-stat {
            display: flex;
            align-items: center;
            gap: 15px;
            margin-bottom: 16px;
        }
        
        .sidebar-stat:last-child {
            margin-bottom: 0;
        }
        
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
        
        .sidebar-stat-label {
            font-size: 13px;
            color: var(--gray-500);
        }
        
        .sidebar-stat-number {
            font-size: 18px;
            font-weight: 700;
        }
        
        .sidebar-footer {
            padding: 20px;
        }
        
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
            text-align: center;
            justify-content: center;
        }
        
        .logout-btn:hover {
            background: #fecaca;
            transform: translateY(-2px);
        }
        
        /* Main Content */
        .main-content {
            flex: 1;
            margin-left: 280px;
            padding: 32px;
            transition: var(--transition);
        }
        
        .page-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 32px;
            flex-wrap: wrap;
            gap: 16px;
        }
        
        .page-header h1 {
            font-size: 30px;
            font-weight: 700;
            color: var(--gray-900);
            margin-bottom: 8px;
        }
        
        .page-header p {
            color: var(--gray-500);
            font-size: 16px;
        }
        
        /* Buttons */
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
            text-decoration: none;
        }
        
        .btn-primary {
            background: linear-gradient(135deg, var(--primary), var(--primary-dark));
            color: white;
            box-shadow: var(--shadow);
        }
        
        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(79, 70, 229, 0.3);
        }
        
        .btn-success {
            background: linear-gradient(135deg, var(--success), #059669);
            color: white;
            box-shadow: var(--shadow);
        }
        
        .btn-warning {
            background: linear-gradient(135deg, var(--warning), #d97706);
            color: white;
            box-shadow: var(--shadow);
        }
        
        .btn-danger {
            background: linear-gradient(135deg, var(--danger), #dc2626);
            color: white;
            box-shadow: var(--shadow);
        }
        
        .btn-outline {
            background: white;
            color: var(--primary);
            border: 1px solid var(--primary);
        }
        
        .btn-outline:hover {
            background: var(--primary);
            color: white;
        }
        
        .btn-sm {
            padding: 8px 16px;
            font-size: 14px;
        }
        
        /* Stats Grid */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(260px, 1fr));
            gap: 24px;
            margin-bottom: 40px;
        }
        
        .stat-card {
            background: white;
            border-radius: var(--radius);
            padding: 24px;
            box-shadow: var(--shadow);
            position: relative;
            overflow: hidden;
            transition: var(--transition);
        }
        
        .stat-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.1);
        }
        
        .stat-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 5px;
            height: 100%;
        }
        
        .stat-card:nth-child(1)::before { background: var(--info); }
        .stat-card:nth-child(2)::before { background: var(--success); }
        .stat-card:nth-child(3)::before { background: var(--warning); }
        .stat-card:nth-child(4)::before { background: var(--purple); }
        .stat-card:nth-child(5)::before { background: var(--primary); }
        
        .stat-content {
            display: flex;
            align-items: center;
            gap: 20px;
        }
        
        .stat-icon {
            width: 60px;
            height: 60px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 28px;
        }
        
        .stat-card:nth-child(1) .stat-icon { background: #dbeafe; color: var(--info); }
        .stat-card:nth-child(2) .stat-icon { background: #d1fae5; color: var(--success); }
        .stat-card:nth-child(3) .stat-icon { background: #fef3c7; color: var(--warning); }
        .stat-card:nth-child(4) .stat-icon { background: #e0e7ff; color: var(--purple); }
        .stat-card:nth-child(5) .stat-icon { background: #e0e7ff; color: var(--primary); }
        
        .stat-number {
            font-size: 32px;
            font-weight: 800;
            color: var(--gray-900);
            line-height: 1;
        }
        
        .stat-label {
            font-size: 15px;
            color: var(--gray-500);
            margin-top: 8px;
        }
        
        /* Filters */
        .filters-section {
            background: white;
            border-radius: var(--radius);
            padding: 24px;
            margin-bottom: 32px;
            box-shadow: var(--shadow);
        }
        
        .filter-row {
            display: flex;
            gap: 16px;
            flex-wrap: wrap;
            align-items: flex-end;
        }
        
        .filter-group {
            flex: 1;
            min-width: 200px;
        }
        
        .filter-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: 500;
            color: var(--gray-700);
            font-size: 14px;
        }
        
        .filter-group input,
        .filter-group select {
            width: 100%;
            padding: 12px 16px;
            border: 1px solid var(--gray-300);
            border-radius: 8px;
            font-size: 15px;
            transition: var(--transition);
            background: white;
            color: var(--gray-900);
        }
        
        .filter-group input:focus,
        .filter-group select:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(79, 70, 229, 0.1);
        }
        
        /* Actions Bar */
        .actions-bar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            padding: 16px;
            background: white;
            border-radius: var(--radius);
            box-shadow: var(--shadow);
        }
        
        .bulk-actions {
            display: flex;
            gap: 10px;
            align-items: center;
        }
        
        /* Table */
        .table-container {
            background: white;
            border-radius: var(--radius);
            overflow: hidden;
            box-shadow: var(--shadow);
            overflow-x: auto;
            margin-bottom: 32px;
        }
        
        table {
            width: 100%;
            border-collapse: collapse;
            min-width: 1000px;
        }
        
        thead {
            background: var(--gray-100);
        }
        
        th {
            padding: 18px 20px;
            text-align: left;
            font-weight: 600;
            color: var(--gray-700);
            font-size: 14px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            border-bottom: 2px solid var(--gray-300);
        }
        
        td {
            padding: 16px 20px;
            border-bottom: 1px solid var(--gray-300);
            color: var(--gray-700);
            font-size: 15px;
        }
        
        tbody tr:hover {
            background: var(--gray-100);
        }
        
        tbody tr:last-child td {
            border-bottom: none;
        }
        
        /* Checkbox */
        .bulk-checkbox {
            width: 20px;
            height: 20px;
            cursor: pointer;
        }
        
        /* Student Avatar */
        .student-avatar {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            object-fit: cover;
            border: 2px solid var(--gray-300);
        }
        
        .student-avatar.default {
            background: linear-gradient(135deg, var(--primary), var(--purple));
            color: white;
            font-size: 16px;
            font-weight: bold;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        
        /* Status Badge */
        .status-badge {
            display: inline-block;
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 13px;
            font-weight: 600;
        }
        
        .status-active {
            background: var(--success-light);
            color: #065f46;
        }
        
        .status-inactive {
            background: var(--danger-light);
            color: #991b1b;
        }
        
        /* Progress Bar */
        .progress-bar {
            height: 8px;
            background: var(--gray-300);
            border-radius: 4px;
            overflow: hidden;
            margin: 8px 0;
        }
        
        .progress-fill {
            height: 100%;
            background: var(--primary);
            border-radius: 4px;
            width: 0;
            transition: width 1.8s ease-out;
        }
        
        /* Action Buttons */
        .action-buttons {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
        }
        
        /* Pagination */
        .pagination {
            display: flex;
            justify-content: center;
            gap: 8px;
            margin-top: 32px;
        }
        
        .pagination a,
        .pagination span {
            padding: 10px 16px;
            border-radius: 8px;
            background: white;
            color: var(--gray-700);
            border: 1px solid var(--gray-300);
            text-decoration: none;
            transition: var(--transition);
        }
        
        .pagination a:hover {
            background: var(--primary);
            color: white;
            border-color: var(--primary);
        }
        
        .pagination .active {
            background: var(--primary);
            color: white;
            border-color: var(--primary);
        }
        
        /* Alerts */
        .alert {
            padding: 16px 24px;
            border-radius: var(--radius);
            margin-bottom: 24px;
            display: flex;
            align-items: center;
            gap: 12px;
            animation: slideIn 0.3s ease;
        }
        
        @keyframes slideIn {
            from {
                opacity: 0;
                transform: translateX(-10px);
            }
            to {
                opacity: 1;
                transform: translateX(0);
            }
        }
        
        .alert-success {
            background: var(--success-light);
            color: #065f46;
            border: 1px solid #a7f3d0;
        }
        
        .alert-error {
            background: var(--danger-light);
            color: #991b1b;
            border: 1px solid #fca5a5;
        }
        
        .alert i {
            font-size: 18px;
        }
        
        /* Modal */
        .modal-overlay {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0, 0, 0, 0.5);
            z-index: 2000;
            align-items: center;
            justify-content: center;
            padding: 20px;
            backdrop-filter: blur(3px);
        }
        
        .modal {
            background: white;
            border-radius: var(--radius);
            width: 100%;
            max-width: 500px;
            max-height: 90vh;
            overflow-y: auto;
            box-shadow: var(--shadow);
            animation: modalSlideIn 0.3s ease;
        }
        
        @keyframes modalSlideIn {
            from {
                opacity: 0;
                transform: translateY(-20px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        .modal-header {
            padding: 20px;
            border-bottom: 1px solid var(--gray-300);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .modal-header h3 {
            margin: 0;
            font-size: 18px;
            font-weight: 600;
        }
        
        .modal-close {
            background: none;
            border: none;
            color: var(--gray-500);
            cursor: pointer;
            font-size: 20px;
            padding: 4px;
        }
        
        .modal-body {
            padding: 20px;
        }
        
        .form-group {
            margin-bottom: 20px;
        }
        
        .form-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: 500;
            color: var(--gray-700);
            font-size: 14px;
        }
        
        .form-group input,
        .form-group select,
        .form-group textarea {
            width: 100%;
            padding: 12px 16px;
            border: 1px solid var(--gray-300);
            border-radius: 8px;
            font-size: 15px;
            transition: var(--transition);
        }
        
        .form-group input:focus,
        .form-group select:focus,
        .form-group textarea:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(79, 70, 229, 0.1);
        }
        
        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 16px;
        }
        
        .modal-actions {
            display: flex;
            gap: 12px;
            justify-content: flex-end;
            margin-top: 24px;
        }
        
        /* Mobile Toggle */
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
            align-items: center;
            justify-content: center;
        }
        
        .mobile-toggle:hover {
            transform: scale(1.1);
        }
        
        .sidebar-overlay {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0, 0, 0, 0.5);
            z-index: 999;
            backdrop-filter: blur(3px);
        }
        
        /* Responsive */
        @media (max-width: 1024px) {
            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
            }
            
            .form-row {
                grid-template-columns: 1fr;
            }
        }
        
        @media (max-width: 768px) {
            .mobile-toggle {
                display: flex;
            }
            
            .sidebar {
                transform: translateX(-100%);
                width: 300px;
                box-shadow: 4px 0 20px rgba(0, 0, 0, 0.15);
            }
            
            .sidebar.active {
                transform: translateX(0);
            }
            
            .sidebar-overlay.active {
                display: block;
            }
            
            .sidebar-close {
                display: block;
            }
            
            .main-content {
                margin-left: 0;
                width: 100%;
                padding: 24px 16px;
                padding-top: 80px;
            }
            
            .page-header {
                flex-direction: column;
                align-items: flex-start;
            }
            
            .stats-grid {
                grid-template-columns: 1fr;
            }
            
            .filter-row {
                flex-direction: column;
            }
            
            .filter-group {
                width: 100%;
            }
            
            .actions-bar {
                flex-direction: column;
                gap: 16px;
                align-items: stretch;
            }
            
            .bulk-actions {
                flex-direction: column;
                width: 100%;
            }
            
            .action-buttons {
                flex-direction: column;
            }
        }
        
        @media (max-width: 480px) {
            .sidebar {
                width: 85%;
                max-width: 320px;
            }
            
            html {
                font-size: 14px;
            }
        }
    </style>
</head>
<body>
    <!-- Mobile Toggle -->
    <button class="mobile-toggle" id="sidebarToggle"><i class="fas fa-bars"></i></button>
    <div class="sidebar-overlay" id="sidebarOverlay"></div>

    <div class="dashboard-wrapper">
        <!-- Sidebar -->
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
                <a href="categories.php" class="nav-link"><i class="fas fa-tags"></i> Categories</a>
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

        <!-- Main Content -->
        <main class="main-content">
            <header class="page-header">
                <div class="header-content">
                    <h1>Manage Students</h1>
                    <p>View, add, edit, and manage student accounts</p>
                </div>
                <button class="btn btn-primary" onclick="openAddModal()">
                    <i class="fas fa-plus"></i> Add New Student
                </button>
            </header>

            <?php if ($success): ?>
                <div class="alert alert-success">
                    <i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($success); ?>
                </div>
            <?php endif; ?>
            
            <?php if ($error): ?>
                <div class="alert alert-error">
                    <i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($error); ?>
                </div>
            <?php endif; ?>

            <!-- Stats -->
            <div class="stats-grid">
                <div class="stat-card">
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
                        <div class="stat-icon"><i class="fas fa-user-times"></i></div>
                        <div>
                            <div class="stat-number"><?php echo $stats['inactive_students']; ?></div>
                            <div class="stat-label">Inactive</div>
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
                <div class="stat-card">
                    <div class="stat-content">
                        <div class="stat-icon"><i class="fas fa-star"></i></div>
                        <div>
                            <div class="stat-number"><?php echo $stats['total_points']; ?></div>
                            <div class="stat-label">Total Points</div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Filters -->
            <div class="filters-section">
                <form method="GET" id="filterForm">
                    <div class="filter-row">
                        <div class="filter-group">
                            <label>Search</label>
                            <input type="text" name="search" value="<?php echo htmlspecialchars($search); ?>" placeholder="Name, email, or student ID">
                        </div>
                        <div class="filter-group">
                            <label>Status</label>
                            <select name="status">
                                <option value="all" <?php echo $status_filter === 'all' ? 'selected' : ''; ?>>All Status</option>
                                <option value="active" <?php echo $status_filter === 'active' ? 'selected' : ''; ?>>Active</option>
                                <option value="inactive" <?php echo $status_filter === 'inactive' ? 'selected' : ''; ?>>Inactive</option>
                            </select>
                        </div>
                        <div class="filter-group">
                            <label>Department</label>
                            <select name="department">
                                <option value="all" <?php echo $department_filter === 'all' ? 'selected' : ''; ?>>All Departments</option>
                                <?php foreach ($departments as $dept): ?>
                                    <option value="<?php echo htmlspecialchars($dept); ?>" <?php echo $department_filter === $dept ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($dept); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="filter-group">
                            <label>Sort By</label>
                            <select name="sort_by">
                                <option value="name" <?php echo $sort_by === 'name' ? 'selected' : ''; ?>>Name</option>
                                <option value="email" <?php echo $sort_by === 'email' ? 'selected' : ''; ?>>Email</option>
                                <option value="points" <?php echo $sort_by === 'points' ? 'selected' : ''; ?>>Points</option>
                                <option value="created_at" <?php echo $sort_by === 'created_at' ? 'selected' : ''; ?>>Joined Date</option>
                            </select>
                        </div>
                        <div class="filter-group">
                            <label>Order</label>
                            <select name="sort_order">
                                <option value="asc" <?php echo $sort_order === 'asc' ? 'selected' : ''; ?>>Ascending</option>
                                <option value="desc" <?php echo $sort_order === 'desc' ? 'selected' : ''; ?>>Descending</option>
                            </select>
                        </div>
                        <div class="filter-group">
                            <button type="submit" class="btn btn-primary" style="width: 100%;">
                                <i class="fas fa-filter"></i> Apply Filters
                            </button>
                        </div>
                    </div>
                </form>
            </div>

            <!-- Bulk Actions -->
            <form method="POST" id="bulkForm">
                <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                <input type="hidden" name="bulk_action" id="bulkAction" value="">
                
                <div class="actions-bar">
                    <div class="bulk-actions">
                        <select id="bulkActionSelect" style="padding: 10px; border-radius: 8px; border: 1px solid var(--gray-300);">
                            <option value="">Bulk Actions</option>
                            <option value="activate">Activate Selected</option>
                            <option value="deactivate">Deactivate Selected</option>
                            <option value="delete">Delete Selected</option>
                        </select>
                        <button type="button" class="btn btn-outline" onclick="applyBulkAction()">
                            <i class="fas fa-play"></i> Apply
                        </button>
                        <label style="display: flex; align-items: center; gap: 8px; margin-left: 16px;">
                            <input type="checkbox" id="selectAll" onchange="toggleSelectAll()">
                            Select All
                        </label>
                    </div>
                    <div>
                        <span><?php echo $total_records; ?> student<?php echo $total_records !== 1 ? 's' : ''; ?> found</span>
                    </div>
                </div>

                <!-- Students Table -->
                <div class="table-container">
                    <table>
                        <thead>
                            <tr>
                                <th width="50">
                                    <input type="checkbox" id="selectAllTable" onchange="toggleSelectAllTable()">
                                </th>
                                <th>Student</th>
                                <th>Contact</th>
                                <th>Department</th>
                                <th>Progress</th>
                                <th>Goals</th>
                                <th>Points</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($students)): ?>
                                <tr>
                                    <td colspan="9" style="text-align: center; padding: 40px;">
                                        <div class="empty-state">
                                            <i class="fas fa-users"></i>
                                            <p>No students found</p>
                                        </div>
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($students as $student): ?>
                                    <tr>
                                        <td>
                                            <input type="checkbox" class="student-checkbox" name="student_ids[]" value="<?php echo $student['id']; ?>">
                                        </td>
                                        <td>
                                            <div style="display: flex; align-items: center; gap: 12px;">
                                                <?php if ($student['profile_picture']): ?>
                                                    <img src="../<?php echo htmlspecialchars($student['profile_picture']); ?>" alt="" class="student-avatar">
                                                <?php else: ?>
                                                    <div class="student-avatar default"><?php echo strtoupper(substr($student['name'], 0, 1)); ?></div>
                                                <?php endif; ?>
                                                <div>
                                                    <div style="font-weight: 600;"><?php echo htmlspecialchars($student['name']); ?></div>
                                                    <div style="font-size: 13px; color: var(--gray-500);">
                                                        <?php echo $student['student_id'] ? 'ID: ' . htmlspecialchars($student['student_id']) : 'No ID'; ?>
                                                    </div>
                                                </div>
                                            </div>
                                        </td>
                                        <td>
                                            <div><?php echo htmlspecialchars($student['email']); ?></div>
                                            <div style="font-size: 13px; color: var(--gray-500);">
                                                Joined: <?php echo date('M d, Y', strtotime($student['created_at'])); ?>
                                            </div>
                                        </td>
                                        <td>
                                            <?php echo htmlspecialchars($student['department'] ?? '—'); ?><br>
                                            <span style="font-size: 13px; color: var(--gray-500);">
                                                Sem <?php echo htmlspecialchars($student['semester'] ?? '—'); ?>
                                            </span>
                                        </td>
                                        <td width="120">
                                            <div class="progress-bar">
                                                <div class="progress-fill" style="width: <?php echo $student['avg_progress'] ?? 0; ?>%"></div>
                                            </div>
                                            <div style="text-align: center; font-size: 13px; margin-top: 4px;">
                                                <?php echo $student['avg_progress'] ?? 0; ?>%
                                            </div>
                                        </td>
                                        <td width="100">
                                            <div style="text-align: center;">
                                                <div style="font-weight: 600;"><?php echo $student['total_goals']; ?></div>
                                                <div style="font-size: 13px; color: var(--gray-500);">
                                                    <?php echo $student['completed_goals']; ?> completed
                                                </div>
                                            </div>
                                        </td>
                                        <td width="80">
                                            <div style="font-weight: 700; color: var(--success); text-align: center;">
                                                <?php echo $student['points'] ?? 0; ?>
                                            </div>
                                        </td>
                                        <td width="100">
                                            <span class="status-badge status-<?php echo $student['status']; ?>">
                                                <?php echo ucfirst($student['status']); ?>
                                            </span>
                                        </td>
                                        <td width="180">
                                            <div class="action-buttons">
                                                <button type="button" class="btn btn-sm btn-outline" onclick="openEditModal(<?php echo htmlspecialchars(json_encode($student)); ?>)">
                                                    <i class="fas fa-edit"></i> Edit
                                                </button>
                                                <button type="button" class="btn btn-sm btn-success" onclick="openPasswordModal(<?php echo $student['id']; ?>, '<?php echo htmlspecialchars($student['name']); ?>')">
                                                    <i class="fas fa-key"></i> Pass
                                                </button>
                                                <form method="POST" style="display: inline;">
                                                    <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                                                    <input type="hidden" name="student_id" value="<?php echo $student['id']; ?>">
                                                    <?php if ($student['status'] === 'active'): ?>
                                                        <input type="hidden" name="action" value="deactivate">
                                                        <button type="submit" name="toggle_status" class="btn btn-sm btn-warning">
                                                            <i class="fas fa-ban"></i> Deact
                                                        </button>
                                                    <?php else: ?>
                                                        <input type="hidden" name="action" value="activate">
                                                        <button type="submit" name="toggle_status" class="btn btn-sm btn-success">
                                                            <i class="fas fa-check"></i> Activ
                                                        </button>
                                                    <?php endif; ?>
                                                </form>
                                                <button type="button" class="btn btn-sm btn-danger" onclick="confirmDelete(<?php echo $student['id']; ?>, '<?php echo htmlspecialchars($student['name']); ?>')">
                                                    <i class="fas fa-trash"></i> Del
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </form>

            <!-- Pagination -->
            <?php if ($total_pages > 1): ?>
                <div class="pagination">
                    <?php if ($page > 1): ?>
                        <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page - 1])); ?>">
                            <i class="fas fa-chevron-left"></i> Prev
                        </a>
                    <?php endif; ?>
                    
                    <?php for ($i = max(1, $page - 2); $i <= min($total_pages, $page + 2); $i++): ?>
                        <?php if ($i == $page): ?>
                            <span class="active"><?php echo $i; ?></span>
                        <?php else: ?>
                            <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $i])); ?>"><?php echo $i; ?></a>
                        <?php endif; ?>
                    <?php endfor; ?>
                    
                    <?php if ($page < $total_pages): ?>
                        <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page + 1])); ?>">
                            Next <i class="fas fa-chevron-right"></i>
                        </a>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </main>
    </div>

    <!-- Add Student Modal -->
    <div class="modal-overlay" id="addModal">
        <div class="modal">
            <div class="modal-header">
                <h3><i class="fas fa-user-plus"></i> Add New Student</h3>
                <button class="modal-close" onclick="closeAddModal()">×</button>
            </div>
            <div class="modal-body">
                <form method="POST" id="addStudentForm">
                    <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                    <input type="hidden" name="add_student" value="1">
                    
                    <div class="form-group">
                        <label>Full Name *</label>
                        <input type="text" name="name" required placeholder="John Doe">
                    </div>
                    
                    <div class="form-group">
                        <label>Email Address *</label>
                        <input type="email" name="email" required placeholder="john@example.com">
                    </div>
                    
                    <div class="form-group">
                        <label>Password *</label>
                        <input type="password" name="password" required minlength="8" placeholder="Minimum 8 characters">
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label>Student ID</label>
                            <input type="text" name="student_id" placeholder="Optional">
                        </div>
                        <div class="form-group">
                            <label>Semester</label>
                            <input type="text" name="semester" placeholder="e.g., 5th">
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label>Department</label>
                        <input type="text" name="department" placeholder="e.g., Computer Science">
                    </div>
                    
                    <div class="modal-actions">
                        <button type="button" class="btn btn-outline" onclick="closeAddModal()">Cancel</button>
                        <button type="submit" class="btn btn-primary">Add Student</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Edit Student Modal -->
    <div class="modal-overlay" id="editModal">
        <div class="modal">
            <div class="modal-header">
                <h3><i class="fas fa-edit"></i> Edit Student</h3>
                <button class="modal-close" onclick="closeEditModal()">×</button>
            </div>
            <div class="modal-body">
                <form method="POST" id="editStudentForm">
                    <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                    <input type="hidden" name="edit_student" value="1">
                    <input type="hidden" name="student_id" id="edit_student_id">
                    
                    <div class="form-group">
                        <label>Full Name *</label>
                        <input type="text" name="name" id="edit_name" required>
                    </div>
                    
                    <div class="form-group">
                        <label>Email Address *</label>
                        <input type="email" name="email" id="edit_email" required>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label>Student ID</label>
                            <input type="text" name="student_id_number" id="edit_student_id_number">
                        </div>
                        <div class="form-group">
                            <label>Semester</label>
                            <input type="text" name="semester" id="edit_semester">
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label>Department</label>
                        <input type="text" name="department" id="edit_department">
                    </div>
                    
                    <div class="form-group">
                        <label>Status</label>
                        <select name="status" id="edit_status">
                            <option value="active">Active</option>
                            <option value="inactive">Inactive</option>
                        </select>
                    </div>
                    
                    <div class="modal-actions">
                        <button type="button" class="btn btn-outline" onclick="closeEditModal()">Cancel</button>
                        <button type="submit" class="btn btn-primary">Update Student</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Change Password Modal -->
    <div class="modal-overlay" id="passwordModal">
        <div class="modal">
            <div class="modal-header">
                <h3><i class="fas fa-key"></i> Change Password</h3>
                <button class="modal-close" onclick="closePasswordModal()">×</button>
            </div>
            <div class="modal-body">
                <form method="POST" id="passwordForm">
                    <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                    <input type="hidden" name="change_password" value="1">
                    <input type="hidden" name="student_id" id="password_student_id">
                    
                    <div class="form-group">
                        <label>Student Name</label>
                        <input type="text" id="password_student_name" readonly style="background: var(--gray-100);">
                    </div>
                    
                    <div class="form-group">
                        <label>New Password *</label>
                        <input type="password" name="new_password" required minlength="8" placeholder="Minimum 8 characters">
                    </div>
                    
                    <div class="form-group">
                        <label>Confirm Password *</label>
                        <input type="password" name="confirm_password" required minlength="8" placeholder="Re-enter password">
                    </div>
                    
                    <div class="modal-actions">
                        <button type="button" class="btn btn-outline" onclick="closePasswordModal()">Cancel</button>
                        <button type="submit" class="btn btn-primary">Change Password</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Delete Confirmation Modal -->
    <div class="modal-overlay" id="deleteModal">
        <div class="modal">
            <div class="modal-header">
                <h3><i class="fas fa-trash"></i> Delete Student</h3>
                <button class="modal-close" onclick="closeDeleteModal()">×</button>
            </div>
            <div class="modal-body">
                <form method="POST" id="deleteForm">
                    <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                    <input type="hidden" name="delete_student" value="1">
                    <input type="hidden" name="student_id" id="delete_student_id">
                    
                    <p>Are you sure you want to delete "<span id="delete_student_name"></span>"?</p>
                    <p class="text-danger"><i class="fas fa-exclamation-triangle"></i> This action cannot be undone. The student will be soft-deleted and marked as inactive.</p>
                    
                    <div class="modal-actions">
                        <button type="button" class="btn btn-outline" onclick="closeDeleteModal()">Cancel</button>
                        <button type="submit" class="btn btn-danger">Delete Student</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script>
        // Sidebar toggle
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

        // Modal functions
        function openAddModal() {
            document.getElementById('addModal').style.display = 'flex';
        }

        function closeAddModal() {
            document.getElementById('addModal').style.display = 'none';
            document.getElementById('addStudentForm').reset();
        }

        function openEditModal(student) {
            document.getElementById('edit_student_id').value = student.id;
            document.getElementById('edit_name').value = student.name;
            document.getElementById('edit_email').value = student.email;
            document.getElementById('edit_student_id_number').value = student.student_id || '';
            document.getElementById('edit_semester').value = student.semester || '';
            document.getElementById('edit_department').value = student.department || '';
            document.getElementById('edit_status').value = student.status;
            
            document.getElementById('editModal').style.display = 'flex';
        }

        function closeEditModal() {
            document.getElementById('editModal').style.display = 'none';
            document.getElementById('editStudentForm').reset();
        }

        function openPasswordModal(studentId, studentName) {
            document.getElementById('password_student_id').value = studentId;
            document.getElementById('password_student_name').value = studentName;
            document.getElementById('passwordModal').style.display = 'flex';
        }

        function closePasswordModal() {
            document.getElementById('passwordModal').style.display = 'none';
            document.getElementById('passwordForm').reset();
        }

        function confirmDelete(studentId, studentName) {
            document.getElementById('delete_student_id').value = studentId;
            document.getElementById('delete_student_name').textContent = studentName;
            document.getElementById('deleteModal').style.display = 'flex';
        }

        function closeDeleteModal() {
            document.getElementById('deleteModal').style.display = 'none';
        }

        // Close modals when clicking outside
        document.querySelectorAll('.modal-overlay').forEach(modal => {
            modal.addEventListener('click', function(e) {
                if (e.target === this) {
                    this.style.display = 'none';
                }
            });
        });

        // Bulk actions
        function toggleSelectAll() {
            const selectAll = document.getElementById('selectAll');
            const checkboxes = document.querySelectorAll('.student-checkbox');
            checkboxes.forEach(cb => cb.checked = selectAll.checked);
            document.getElementById('selectAllTable').checked = selectAll.checked;
        }

        function toggleSelectAllTable() {
            const selectAllTable = document.getElementById('selectAllTable');
            const checkboxes = document.querySelectorAll('.student-checkbox');
            checkboxes.forEach(cb => cb.checked = selectAllTable.checked);
            document.getElementById('selectAll').checked = selectAllTable.checked;
        }

        function applyBulkAction() {
            const action = document.getElementById('bulkActionSelect').value;
            if (!action) {
                alert('Please select a bulk action.');
                return;
            }
            
            const selected = document.querySelectorAll('.student-checkbox:checked');
            if (selected.length === 0) {
                alert('Please select at least one student.');
                return;
            }
            
            if (action === 'delete' && !confirm(`Are you sure you want to delete ${selected.length} student(s)?`)) {
                return;
            }
            
            document.getElementById('bulkAction').value = action;
            document.getElementById('bulkForm').submit();
        }

        // Animate progress bars
        const observer = new IntersectionObserver((entries) => {
            entries.forEach(entry => {
                if (entry.isIntersecting) {
                    entry.target.querySelectorAll('.progress-fill').forEach(bar => {
                        const width = bar.style.width;
                        bar.style.width = '0%';
                        setTimeout(() => {
                            bar.style.width = width;
                        }, 100);
                    });
                }
            });
        }, { threshold: 0.2 });

        document.querySelectorAll('tbody tr').forEach(row => observer.observe(row));
    </script>
</body>
</html>