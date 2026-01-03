<?php
session_start();
require_once '../includes/db_connection.php';
checkAuth('admin');

// === POST Action Handling ===
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $success = '';
    $error = '';

    try {
        if ($action === 'add_goal') {
            if (empty(trim($_POST['title'])) || empty($_POST['target_value']) || empty($_POST['unit'])) {
                $error = 'Title, target value, and unit are required.';
            } else {
                $pdo->beginTransaction();
                
                $stmt = $pdo->prepare("
                    INSERT INTO admin_goals 
                    (title, description, category, target_value, unit, due_date, priority, status, created_by, created_at)
                    VALUES (?, ?, ?, ?, ?, ?, ?, 'active', ?, NOW())
                ");
                
                $stmt->execute([
                    trim($_POST['title']),
                    $_POST['description'] ?? '',
                    $_POST['category'] ?? '',
                    $_POST['target_value'],
                    $_POST['unit'],
                    $_POST['due_date'] ? date('Y-m-d', strtotime($_POST['due_date'])) : null,
                    $_POST['priority'] ?? 'medium',
                    $_SESSION['user_id']
                ]);
                
                $new_goal_id = $pdo->lastInsertId();
                
                // Add achievement if this is the first goal created
                $goal_count = $pdo->query("SELECT COUNT(*) FROM admin_goals WHERE created_by = " . $_SESSION['user_id'])->fetchColumn();
                if ($goal_count === 1) {
                    $pdo->prepare("
                        INSERT INTO user_achievements (user_id, achievement_id, earned_at)
                        VALUES (?, (SELECT id FROM achievements WHERE criteria_type = 'first_goal'), NOW())
                    ")->execute([$_SESSION['user_id']]);
                }
                
                $pdo->commit();
                $success = 'System goal created successfully!';
            }
        } elseif ($action === 'edit_goal') {
            if (empty($_POST['goal_id']) || empty(trim($_POST['title'])) || empty($_POST['target_value']) || empty($_POST['unit'])) {
                $error = 'Invalid data.';
            } else {
                $pdo->beginTransaction();
                
                $stmt = $pdo->prepare("
                    UPDATE admin_goals 
                    SET title = ?, description = ?, category = ?, target_value = ?, unit = ?, 
                        due_date = ?, priority = ?, status = ?, updated_at = NOW()
                    WHERE id = ?
                ");
                
                $stmt->execute([
                    trim($_POST['title']),
                    $_POST['description'] ?? '',
                    $_POST['category'] ?? '',
                    $_POST['target_value'],
                    $_POST['unit'],
                    $_POST['due_date'] ? date('Y-m-d', strtotime($_POST['due_date'])) : null,
                    $_POST['priority'] ?? 'medium',
                    $_POST['status'] ?? 'active',
                    $_POST['goal_id']
                ]);
                
                // Update all student goals that reference this system goal
                if ($_POST['status'] === 'inactive') {
                    $pdo->prepare("
                        UPDATE student_goals 
                        SET status = 'inactive' 
                        WHERE goal_id = ? AND status != 'completed'
                    ")->execute([$_POST['goal_id']]);
                }
                
                $pdo->commit();
                $success = 'System goal updated successfully!';
            }
        } elseif ($action === 'delete_personal_goal') {
            if (empty($_POST['goal_id'])) {
                throw new Exception('Goal ID missing.');
            }
            $goal_id = (int)$_POST['goal_id'];
            
            $pdo->beginTransaction();
            
            // Delete progress history first
            $pdo->prepare("DELETE FROM progress_history WHERE goal_id = ?")->execute([$goal_id]);
            
            // Delete goal progress
            $pdo->prepare("DELETE FROM goal_progress WHERE goal_id = ?")->execute([$goal_id]);
            
            // Delete the goal
            $pdo->prepare("DELETE FROM student_goals WHERE id = ?")->execute([$goal_id]);
            
            $pdo->commit();
            $success = 'Personal goal deleted successfully!';
            
        } elseif (in_array($action, ['activate', 'deactivate', 'delete'])) {
            if (empty($_POST['goal_id'])) {
                throw new Exception('Goal ID missing.');
            }
            $goal_id = (int)$_POST['goal_id'];

            $pdo->beginTransaction();
            
            if ($action === 'delete') {
                // First, check if this goal is assigned to any student
                $assigned_count = $pdo->query("SELECT COUNT(*) FROM student_goals WHERE goal_id = $goal_id")->fetchColumn();
                
                if ($assigned_count > 0) {
                    // Delete all associated student goals
                    $pdo->prepare("DELETE FROM progress_history WHERE goal_id IN (SELECT id FROM student_goals WHERE goal_id = ?)")->execute([$goal_id]);
                    $pdo->prepare("DELETE FROM goal_progress WHERE goal_id IN (SELECT id FROM student_goals WHERE goal_id = ?)")->execute([$goal_id]);
                    $pdo->prepare("DELETE FROM student_goals WHERE goal_id = ?")->execute([$goal_id]);
                }
                
                // Delete the system goal
                $pdo->prepare("DELETE FROM admin_goals WHERE id = ?")->execute([$goal_id]);
                
                $success = 'System goal and all assignments deleted successfully!';
            } else {
                $status = $action === 'activate' ? 'active' : 'inactive';
                $pdo->prepare("UPDATE admin_goals SET status = ? WHERE id = ?")->execute([$status, $goal_id]);
                
                // Update all student goals if deactivating
                if ($status === 'inactive') {
                    $pdo->prepare("
                        UPDATE student_goals 
                        SET status = 'inactive' 
                        WHERE goal_id = ? AND status != 'completed'
                    ")->execute([$goal_id]);
                }
                
                $success = "System goal {$status}d successfully!";
            }
            
            $pdo->commit();
        } elseif ($action === 'bulk_action') {
            $bulk_action = $_POST['bulk_action'] ?? '';
            $goal_ids = $_POST['goal_ids'] ?? [];
            
            if (empty($goal_ids)) {
                throw new Exception('Please select at least one goal.');
            }
            
            $placeholders = implode(',', array_fill(0, count($goal_ids), '?'));
            
            if ($bulk_action === 'activate') {
                $pdo->prepare("UPDATE admin_goals SET status = 'active' WHERE id IN ($placeholders)")->execute($goal_ids);
                $success = count($goal_ids) . ' goal(s) activated successfully!';
            } elseif ($bulk_action === 'deactivate') {
                $pdo->prepare("UPDATE admin_goals SET status = 'inactive' WHERE id IN ($placeholders)")->execute($goal_ids);
                $success = count($goal_ids) . ' goal(s) deactivated successfully!';
            } elseif ($bulk_action === 'delete') {
                // Delete associated student goals first
                $pdo->prepare("DELETE FROM student_goals WHERE goal_id IN ($placeholders)")->execute($goal_ids);
                // Delete system goals
                $pdo->prepare("DELETE FROM admin_goals WHERE id IN ($placeholders)")->execute($goal_ids);
                $success = count($goal_ids) . ' goal(s) deleted successfully!';
            }
        }
    } catch (Exception $e) {
        if (isset($pdo) && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $error = 'Error: ' . $e->getMessage();
    }

    $_SESSION['success'] = $success;
    $_SESSION['error'] = $error;
    header('Location: goals.php?' . http_build_query($_GET));
    exit();
}

// === Flash Messages ===
$success = $_SESSION['success'] ?? '';
$error = $_SESSION['error'] ?? '';
unset($_SESSION['success'], $_SESSION['error']);

// === Stats ===
$total_system_goals = $pdo->query("SELECT COUNT(*) FROM admin_goals")->fetchColumn() ?: 0;
$total_personal_goals = $pdo->query("SELECT COUNT(*) FROM student_goals WHERE is_self_created = 1")->fetchColumn() ?: 0;
$total_goals = $total_system_goals + $total_personal_goals;

// More detailed stats
$stats = [
    'students' => $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'student' AND status = 'active'")->fetchColumn() ?: 0,
    'system_goals' => $total_system_goals,
    'personal_goals' => $total_personal_goals,
    'assigned' => $pdo->query("SELECT COUNT(*) FROM student_goals WHERE is_self_created = 0")->fetchColumn() ?: 0,
    'points' => $pdo->query("SELECT COALESCE(SUM(points), 0) FROM users WHERE role = 'student'")->fetchColumn() ?: 0,
    'active_system_goals' => $pdo->query("SELECT COUNT(*) FROM admin_goals WHERE status = 'active'")->fetchColumn() ?: 0,
    'completed_goals' => $pdo->query("SELECT COUNT(*) FROM student_goals WHERE status = 'completed'")->fetchColumn() ?: 0,
    'pending_goals' => $pdo->query("SELECT COUNT(*) FROM student_goals WHERE status = 'pending'")->fetchColumn() ?: 0
];

// === Filters (apply to both tabs) ===
$search = trim($_GET['search'] ?? '');
$status_filter = $_GET['status'] ?? 'all';
$category_filter = trim($_GET['category'] ?? '');
$priority_filter = $_GET['priority'] ?? 'all';
$date_filter = $_GET['date_filter'] ?? 'all';
$sort_by = $_GET['sort_by'] ?? 'created_at';
$sort_order = $_GET['sort_order'] ?? 'desc';

$where_system = [];
$where_personal = [];
$params_system = [];
$params_personal = [];

// Common search
if ($search) {
    $where_system[] = "(a.title LIKE ? OR a.description LIKE ?)";
    $where_personal[] = "(sg.title LIKE ? OR sg.description LIKE ?)";
    $params_system[] = "%$search%";
    $params_system[] = "%$search%";
    $params_personal[] = "%$search%";
    $params_personal[] = "%$search%";
}

// Status filter
if ($status_filter !== 'all') {
    $where_system[] = "a.status = ?";
    $where_personal[] = "sg.status = ?";
    $params_system[] = $status_filter;
    $params_personal[] = $status_filter;
}

// Category filter
if ($category_filter) {
    $where_system[] = "a.category = ?";
    $where_personal[] = "sg.category = ?";
    $params_system[] = $category_filter;
    $params_personal[] = $category_filter;
}

// Priority filter
if ($priority_filter !== 'all') {
    $where_system[] = "a.priority = ?";
    $where_personal[] = "sg.priority = ?";
    $params_system[] = $priority_filter;
    $params_personal[] = $priority_filter;
}

// Date filter
if ($date_filter !== 'all') {
    $today = date('Y-m-d');
    if ($date_filter === 'today') {
        $where_system[] = "DATE(a.created_at) = ?";
        $where_personal[] = "DATE(sg.created_at) = ?";
        $params_system[] = $today;
        $params_personal[] = $today;
    } elseif ($date_filter === 'week') {
        $week_ago = date('Y-m-d', strtotime('-7 days'));
        $where_system[] = "DATE(a.created_at) >= ?";
        $where_personal[] = "DATE(sg.created_at) >= ?";
        $params_system[] = $week_ago;
        $params_personal[] = $week_ago;
    } elseif ($date_filter === 'month') {
        $month_ago = date('Y-m-d', strtotime('-30 days'));
        $where_system[] = "DATE(a.created_at) >= ?";
        $where_personal[] = "DATE(sg.created_at) >= ?";
        $params_system[] = $month_ago;
        $params_personal[] = $month_ago;
    } elseif ($date_filter === 'overdue') {
        $where_system[] = "a.due_date < ? AND a.due_date IS NOT NULL";
        $where_personal[] = "sg.due_date < ? AND sg.due_date IS NOT NULL";
        $params_system[] = $today;
        $params_personal[] = $today;
    }
}

$where_system_clause = $where_system ? 'WHERE ' . implode(' AND ', $where_system) : '';
$where_personal_clause = $where_personal ? 'WHERE ' . implode(' AND ', $where_personal) : '';

// === Sorting ===
$sort_options = [
    'created_at' => 'a.created_at',
    'title' => 'a.title',
    'priority' => 'a.priority',
    'due_date' => 'a.due_date',
    'assigned_count' => 'assigned_count'
];

$personal_sort_options = [
    'created_at' => 'sg.created_at',
    'title' => 'sg.title',
    'priority' => 'sg.priority',
    'due_date' => 'sg.due_date'
];

$order_by = $sort_options[$sort_by] ?? 'a.created_at';
$personal_order_by = $personal_sort_options[$sort_by] ?? 'sg.created_at';
$order_direction = strtoupper($sort_order) === 'ASC' ? 'ASC' : 'DESC';

// === Pagination ===
$per_page = 15;
$page = max(1, (int)($_GET['page'] ?? 1));
$offset = ($page - 1) * $per_page;

// === Fetch System Goals with advanced stats ===
$system_goals_sql = "
    SELECT a.*, 
           (SELECT COUNT(*) FROM student_goals sg WHERE sg.goal_id = a.id) AS assigned_count,
           (SELECT COUNT(*) FROM student_goals sg WHERE sg.goal_id = a.id AND sg.status = 'completed') AS completed_count,
           (SELECT COUNT(*) FROM student_goals sg WHERE sg.goal_id = a.id AND sg.status = 'pending') AS pending_count,
           u.name as created_by_name,
           DATEDIFF(a.due_date, CURDATE()) as days_left
    FROM admin_goals a
    LEFT JOIN users u ON a.created_by = u.id
    $where_system_clause
    ORDER BY $order_by $order_direction
    LIMIT ? OFFSET ?
";

$system_goals_stmt = $pdo->prepare($system_goals_sql);
$system_goals_stmt->execute(array_merge($params_system, [$per_page, $offset]));
$system_goals = $system_goals_stmt->fetchAll(PDO::FETCH_ASSOC);

// === Fetch Personal Goals with advanced stats ===
$personal_goals_sql = "
    SELECT sg.*, 
           u.name AS student_name,
           u.email as student_email,
           u.profile_picture as student_pic,
           DATEDIFF(sg.due_date, CURDATE()) as days_left,
           (SELECT COUNT(*) FROM goal_progress gp WHERE gp.goal_id = sg.id) as progress_count,
           sg.current_value / sg.target_value * 100 as progress_percent
    FROM student_goals sg
    JOIN users u ON sg.student_id = u.id
    WHERE sg.is_self_created = 1
    $where_personal_clause
    ORDER BY $personal_order_by $order_direction
    LIMIT ? OFFSET ?
";

$personal_goals_stmt = $pdo->prepare($personal_goals_sql);
$personal_goals_stmt->execute(array_merge($params_personal, [$per_page, $offset]));
$personal_goals = $personal_goals_stmt->fetchAll(PDO::FETCH_ASSOC);

// === Categories (from both tables) ===
$categories = $pdo->query("
    SELECT DISTINCT category, COUNT(*) as count FROM (
        SELECT category FROM admin_goals
        UNION ALL
        SELECT category FROM student_goals WHERE is_self_created = 1
    ) AS combined
    WHERE category IS NOT NULL AND category != ''
    GROUP BY category
    ORDER BY category
")->fetchAll(PDO::FETCH_ASSOC);

// === Priorities ===
$priorities = [
    'low' => ['color' => '#3b82f6', 'icon' => 'arrow-down'],
    'medium' => ['color' => '#f59e0b', 'icon' => 'equals'],
    'high' => ['color' => '#ef4444', 'icon' => 'arrow-up'],
    'critical' => ['color' => '#dc2626', 'icon' => 'exclamation-triangle']
];

// === Get total counts for pagination ===
$total_system_sql = "SELECT COUNT(*) FROM admin_goals a $where_system_clause";
$total_system_stmt = $pdo->prepare($total_system_sql);
$total_system_stmt->execute($params_system);
$total_system_count = $total_system_stmt->fetchColumn();

$total_personal_sql = "SELECT COUNT(*) FROM student_goals sg WHERE sg.is_self_created = 1 $where_personal_clause";
$total_personal_stmt = $pdo->prepare($total_personal_sql);
$total_personal_stmt->execute($params_personal);
$total_personal_count = $total_personal_stmt->fetchColumn();

$total_pages_system = ceil($total_system_count / $per_page);
$total_pages_personal = ceil($total_personal_count / $per_page);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Goals - ProgressMate</title>
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
        .btn-outline { background: white; color: var(--primary); border: 1px solid var(--primary); }
        .btn-outline:hover { background: var(--primary); color: white; }
        .btn-sm { padding: 8px 16px; font-size: 13px; }

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

        .filters-section { background: white; border-radius: var(--radius); padding: 24px; margin-bottom: 32px; box-shadow: var(--shadow); }
        .filter-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 16px; align-items: end; }
        .filter-group { margin-bottom: 0; }
        .filter-group label { display: block; margin-bottom: 8px; font-weight: 500; color: var(--gray-700); }
        .filter-group input, .filter-group select { width: 100%; padding: 12px; border: 1px solid var(--gray-300); border-radius: var(--radius-sm); font-size: 15px; }

        .goals-table-container { background: white; border-radius: var(--radius); overflow: hidden; box-shadow: var(--shadow); overflow-x: auto; position: relative; }
        table { width: 100%; min-width: 1100px; border-collapse: collapse; }
        th { background: var(--gray-200); padding: 18px; text-align: left; font-weight: 600; color: var(--gray-700); position: sticky; top: 0; }
        td { padding: 18px; border-bottom: 1px solid var(--gray-300); vertical-align: middle; }
        tr:hover { background: var(--gray-100); }

        .priority-badge {
            padding: 6px 14px;
            border-radius: 20px;
            font-size: 13px;
            font-weight: 600;
            text-transform: capitalize;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }
        .priority-critical { background: #fef2f2; color: #991b1b; border: 1px solid #fecaca; }
        .priority-high { background: #fef3c7; color: #92400e; border: 1px solid #fde68a; }
        .priority-medium { background: #e0e7ff; color: var(--primary); border: 1px solid #c7d2fe; }
        .priority-low { background: #f3f4f6; color: var(--gray-700); border: 1px solid #e5e7eb; }

        .status-badge { padding: 6px 14px; border-radius: 20px; font-size: 13px; font-weight: 600; text-transform: capitalize; }
        .status-active { background: var(--success-light); color: #065f46; }
        .status-inactive { background: var(--gray-300); color: var(--gray-700); }
        .status-pending { background: var(--warning-light); color: #92400e; }
        .status-completed { background: var(--info-light); color: #1e40af; }

        .type-badge {
            padding: 6px 14px;
            border-radius: 20px;
            font-size: 13px;
            font-weight: 600;
            background: var(--primary);
            color: white;
        }
        .type-system { background: var(--primary); }
        .type-personal { background: var(--info); }

        .progress-bar {
            height: 8px;
            background: var(--gray-300);
            border-radius: 4px;
            overflow: hidden;
            width: 100px;
        }
        .progress-fill {
            height: 100%;
            background: var(--success);
            border-radius: 4px;
        }

        .action-buttons { display: flex; flex-wrap: wrap; gap: 10px; }

        .bulk-actions { background: var(--gray-100); padding: 16px; border-bottom: 1px solid var(--gray-300); display: flex; align-items: center; gap: 16px; }
        .bulk-select { display: flex; align-items: center; gap: 8px; }
        .select-all-checkbox { width: 16px; height: 16px; }

        .pagination { display: flex; justify-content: center; gap: 8px; padding: 24px; flex-wrap: wrap; }
        .pagination-link { padding: 10px 16px; border-radius: var(--radius-sm); background: white; border: 1px solid var(--gray-300); color: var(--gray-700); transition: var(--transition); }
        .pagination-link:hover { background: var(--gray-200); }
        .pagination-link.active { background: var(--primary); color: white; border-color: var(--primary); }

        .empty-state { text-align: center; padding: 80px 20px; color: var(--gray-500); }
        .empty-state i { font-size: 64px; margin-bottom: 20px; opacity: 0.5; }

        .timer-display {
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
        }

        .countdown {
            font-size: 14px;
            font-weight: 600;
            padding: 6px 12px;
            border-radius: var(--radius-sm);
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }
        .countdown.expired { background: var(--danger-light); color: var(--danger); }
        .countdown.soon { background: var(--warning-light); color: var(--warning); }
        .countdown.safe { background: var(--success-light); color: var(--success); }

        .mobile-toggle { display: none; position: fixed; top: 20px; left: 20px; z-index: 1100; background: var(--primary); color: white; border: none; width: 48px; height: 48px; border-radius: 12px; font-size: 20px; cursor: pointer; box-shadow: var(--shadow); }
        .sidebar-overlay { display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.5); z-index: 999; }

        /* Modal */
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

        /* Form Styles */
        .form-group { margin-bottom: 24px; }
        .form-group label { display: block; margin-bottom: 8px; font-weight: 500; color: var(--gray-700); }
        .required::after { content: " *"; color: var(--danger); }
        .form-group input, .form-group select, .form-group textarea { width: 100%; padding: 14px; border: 1px solid var(--gray-300); border-radius: var(--radius-sm); font-size: 15px; }
        .form-group textarea { min-height: 120px; resize: vertical; }
        .form-help { font-size: 13px; color: var(--gray-500); margin-top: 6px; }
        .form-row { display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 24px; }

        @media (max-width: 768px) {
            .sidebar { transform: translateX(-100%); width: 300px; }
            .sidebar.active { transform: translateX(0); }
            .sidebar-overlay.active { display: block; }
            .sidebar-close { display: block; }
            .mobile-toggle { display: flex; align-items: center; justify-content: center; }
            .main-content { margin-left: 0; padding: 24px 16px; padding-top: 80px; }
            .filter-grid { grid-template-columns: 1fr; }
            .form-row { grid-template-columns: 1fr; }
            .action-buttons { flex-direction: column; }
            .bulk-actions { flex-direction: column; align-items: flex-start; }
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
                <a href="students.php" class="nav-link"><i class="fas fa-users"></i> Students <?php if ($stats['students'] > 0): ?><span class="badge"><?php echo $stats['students']; ?></span><?php endif; ?></a>
                <a href="goals.php" class="nav-link active"><i class="fas fa-bullseye"></i> All Goals <?php if ($stats['system_goals'] + $stats['personal_goals'] > 0): ?><span class="badge"><?php echo $stats['system_goals'] + $stats['personal_goals']; ?></span><?php endif; ?></a>
                <a href="assign_goals.php" class="nav-link"><i class="fas fa-tasks"></i> Assign Goals <?php if ($stats['assigned'] > 0): ?><span class="badge"><?php echo $stats['assigned']; ?></span><?php endif; ?></a>
                <a href="achievements.php" class="nav-link"><i class="fas fa-trophy"></i> Achievements <?php if ($stats['points'] > 0): ?><span class="badge"><?php echo $stats['points']; ?> pts</span><?php endif; ?></a>
                <a href="reports.php" class="nav-link"><i class="fas fa-chart-bar"></i> Reports</a>
                <a href="notifications.php" class="nav-link"><i class="fas fa-bell"></i> Notifications</a>
                <a href="settings.php" class="nav-link"><i class="fas fa-cog"></i> Settings</a>
            </nav>

            <div class="sidebar-quick-stats">
                <div class="sidebar-stat">
                    <div class="sidebar-stat-icon"><i class="fas fa-bullseye"></i></div>
                    <div><div class="sidebar-stat-label">System Goals</div><div class="sidebar-stat-number"><?php echo $stats['system_goals']; ?></div></div>
                </div>
                <div class="sidebar-stat">
                    <div class="sidebar-stat-icon"><i class="fas fa-user"></i></div>
                    <div><div class="sidebar-stat-label">Personal Goals</div><div class="sidebar-stat-number"><?php echo $stats['personal_goals']; ?></div></div>
                </div>
                <div class="sidebar-stat">
                    <div class="sidebar-stat-icon"><i class="fas fa-check-circle"></i></div>
                    <div><div class="sidebar-stat-label">Completed</div><div class="sidebar-stat-number"><?php echo $stats['completed_goals']; ?></div></div>
                </div>
            </div>

            <div class="sidebar-footer">
                <a href="../logout.php" class="logout-btn"><i class="fas fa-sign-out-alt"></i> Logout</a>
            </div>
        </aside>

        <main class="main-content">
            <header class="page-header">
                <div class="header-content">
                    <h1>Manage All Goals</h1>
                    <p>View and manage system goals (admin-created) and personal goals (student self-created)</p>
                </div>
                <div class="timer-display">
                    <i class="fas fa-clock"></i>
                    <span id="currentTime">Loading...</span>
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
                    <div class="stat-number"><?php echo $stats['system_goals']; ?></div>
                    <div class="stat-label">System Goals</div>
                </div>
                <div class="stat-card">
                    <div class="stat-number"><?php echo $stats['personal_goals']; ?></div>
                    <div class="stat-label">Personal Goals</div>
                </div>
                <div class="stat-card">
                    <div class="stat-number"><?php echo $stats['completed_goals']; ?></div>
                    <div class="stat-label">Completed Goals</div>
                </div>
                <div class="stat-card">
                    <div class="stat-number"><?php echo count($categories); ?></div>
                    <div class="stat-label">Categories</div>
                </div>
            </div>

            <div class="tabs">
                <button class="tab active" data-tab="system">
                    <i class="fas fa-bullseye"></i> System Goals
                    <span class="tab-badge"><?php echo $total_system_count; ?></span>
                </button>
                <button class="tab" data-tab="personal">
                    <i class="fas fa-user"></i> Personal Goals
                    <span class="tab-badge"><?php echo $total_personal_count; ?></span>
                </button>
            </div>

            <div class="filters-section">
                <form method="GET" id="filterForm">
                    <div class="filter-grid">
                        <div class="filter-group">
                            <label><i class="fas fa-search"></i> Search</label>
                            <input type="text" name="search" placeholder="Search by title or description..." value="<?php echo htmlspecialchars($search); ?>">
                        </div>
                        <div class="filter-group">
                            <label><i class="fas fa-filter"></i> Status</label>
                            <select name="status">
                                <option value="all" <?php echo $status_filter === 'all' ? 'selected' : ''; ?>>All Status</option>
                                <option value="active" <?php echo $status_filter === 'active' ? 'selected' : ''; ?>>Active</option>
                                <option value="inactive" <?php echo $status_filter === 'inactive' ? 'selected' : ''; ?>>Inactive</option>
                                <option value="pending" <?php echo $status_filter === 'pending' ? 'selected' : ''; ?>>Pending</option>
                                <option value="completed" <?php echo $status_filter === 'completed' ? 'selected' : ''; ?>>Completed</option>
                            </select>
                        </div>
                        <div class="filter-group">
                            <label><i class="fas fa-tags"></i> Category</label>
                            <select name="category">
                                <option value="">All Categories</option>
                                <?php foreach ($categories as $cat): ?>
                                    <option value="<?php echo htmlspecialchars($cat['category']); ?>" <?php echo $category_filter === $cat['category'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($cat['category']); ?> (<?php echo $cat['count']; ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="filter-group">
                            <label><i class="fas fa-flag"></i> Priority</label>
                            <select name="priority">
                                <option value="all" <?php echo $priority_filter === 'all' ? 'selected' : ''; ?>>All Priorities</option>
                                <option value="low" <?php echo $priority_filter === 'low' ? 'selected' : ''; ?>>Low</option>
                                <option value="medium" <?php echo $priority_filter === 'medium' ? 'selected' : ''; ?>>Medium</option>
                                <option value="high" <?php echo $priority_filter === 'high' ? 'selected' : ''; ?>>High</option>
                                <option value="critical" <?php echo $priority_filter === 'critical' ? 'selected' : ''; ?>>Critical</option>
                            </select>
                        </div>
                        <div class="filter-group">
                            <label><i class="fas fa-calendar"></i> Date Filter</label>
                            <select name="date_filter">
                                <option value="all" <?php echo $date_filter === 'all' ? 'selected' : ''; ?>>All Time</option>
                                <option value="today" <?php echo $date_filter === 'today' ? 'selected' : ''; ?>>Today</option>
                                <option value="week" <?php echo $date_filter === 'week' ? 'selected' : ''; ?>>Last 7 Days</option>
                                <option value="month" <?php echo $date_filter === 'month' ? 'selected' : ''; ?>>Last 30 Days</option>
                                <option value="overdue" <?php echo $date_filter === 'overdue' ? 'selected' : ''; ?>>Overdue</option>
                            </select>
                        </div>
                        <div class="filter-group">
                            <label><i class="fas fa-sort"></i> Sort By</label>
                            <select name="sort_by">
                                <option value="created_at" <?php echo $sort_by === 'created_at' ? 'selected' : ''; ?>>Created Date</option>
                                <option value="title" <?php echo $sort_by === 'title' ? 'selected' : ''; ?>>Title</option>
                                <option value="priority" <?php echo $sort_by === 'priority' ? 'selected' : ''; ?>>Priority</option>
                                <option value="due_date" <?php echo $sort_by === 'due_date' ? 'selected' : ''; ?>>Due Date</option>
                                <option value="assigned_count" <?php echo $sort_by === 'assigned_count' ? 'selected' : ''; ?>>Assigned Count</option>
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
                        <?php if ($search || $status_filter !== 'all' || $category_filter || $priority_filter !== 'all' || $date_filter !== 'all'): ?>
                            <div class="filter-group">
                                <a href="goals.php" class="btn btn-outline" style="width: 100%;"><i class="fas fa-times"></i> Clear Filters</a>
                            </div>
                        <?php endif; ?>
                    </div>
                </form>
            </div>

            <!-- System Goals Tab -->
            <div class="tab-content active" id="system">
                <div class="bulk-actions" id="bulkActionsSystem" style="display: none;">
                    <div class="bulk-select">
                        <input type="checkbox" id="selectAllSystem" class="select-all-checkbox">
                        <span id="selectedCountSystem">0 goals selected</span>
                    </div>
                    <select id="bulkActionSelectSystem" class="btn btn-outline">
                        <option value="">Bulk Actions</option>
                        <option value="activate">Activate Selected</option>
                        <option value="deactivate">Deactivate Selected</option>
                        <option value="delete">Delete Selected</option>
                    </select>
                    <button id="applyBulkActionSystem" class="btn btn-primary">Apply</button>
                    <button id="clearSelectionSystem" class="btn btn-outline">Clear Selection</button>
                </div>

                <div class="goals-table-container">
                    <?php if (empty($system_goals)): ?>
                        <div class="empty-state">
                            <i class="fas fa-bullseye"></i>
                            <p>No system goals found</p>
                            <button class="btn btn-primary" id="addGoalBtnSystem"><i class="fas fa-plus"></i> Add System Goal</button>
                        </div>
                    <?php else: ?>
                        <table id="systemGoalsTable">
                            <thead>
                                <tr>
                                    <th width="50"><input type="checkbox" id="selectAllHeaderSystem"></th>
                                    <th>Type</th>
                                    <th>Title</th>
                                    <th>Category</th>
                                    <th>Target</th>
                                    <th>Progress</th>
                                    <th>Due Date</th>
                                    <th>Priority</th>
                                    <th>Status</th>
                                    <th>Created By</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($system_goals as $goal): 
                                    $priority_class = 'priority-' . ($goal['priority'] ?? 'medium');
                                    $days_left = $goal['days_left'] ?? null;
                                ?>
                                    <tr>
                                        <td><input type="checkbox" class="goal-checkbox" data-goal-id="<?php echo $goal['id']; ?>" data-type="system"></td>
                                        <td><span class="type-badge type-system">System</span></td>
                                        <td>
                                            <strong><?php echo htmlspecialchars($goal['title']); ?></strong>
                                            <?php if ($goal['description']): ?>
                                                <div style="font-size: 13px; color: var(--gray-500); margin-top: 4px;">
                                                    <?php echo htmlspecialchars(substr($goal['description'], 0, 100)); ?>
                                                    <?php if (strlen($goal['description']) > 100): ?>...<?php endif; ?>
                                                </div>
                                            <?php endif; ?>
                                        </td>
                                        <td><?php echo htmlspecialchars($goal['category'] ?? 'Uncategorized'); ?></td>
                                        <td>
                                            <strong><?php echo htmlspecialchars($goal['target_value'] . ' ' . ($goal['unit'] ?? '')); ?></strong>
                                            <div style="font-size: 12px; color: var(--gray-500);">
                                                <?php echo $goal['assigned_count']; ?> assigned
                                            </div>
                                        </td>
                                        <td>
                                            <?php if ($goal['assigned_count'] > 0): ?>
                                                <div class="progress-bar">
                                                    <div class="progress-fill" style="width: <?php echo min(100, ($goal['completed_count'] / $goal['assigned_count'] * 100)); ?>%"></div>
                                                </div>
                                                <div style="font-size: 12px; color: var(--gray-500);">
                                                    <?php echo $goal['completed_count']; ?> of <?php echo $goal['assigned_count']; ?> completed
                                                </div>
                                            <?php else: ?>
                                                <span style="color: var(--gray-500); font-size: 13px;">Not assigned</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if ($goal['due_date']): ?>
                                                <?php echo date('M d, Y', strtotime($goal['due_date'])); ?>
                                                <?php if ($days_left !== null): ?>
                                                    <div class="countdown <?php echo $days_left < 0 ? 'expired' : ($days_left < 7 ? 'soon' : 'safe'); ?>">
                                                        <i class="fas fa-clock"></i>
                                                        <?php echo $days_left < 0 ? abs($days_left) . ' days overdue' : $days_left . ' days left'; ?>
                                                    </div>
                                                <?php endif; ?>
                                            <?php else: ?>
                                                <span style="color: var(--gray-500);">No due date</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <span class="priority-badge <?php echo $priority_class; ?>">
                                                <i class="fas fa-<?php echo $priorities[$goal['priority'] ?? 'medium']['icon'] ?? 'circle'; ?>"></i>
                                                <?php echo ucfirst($goal['priority'] ?? 'medium'); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <span class="status-badge status-<?php echo $goal['status']; ?>">
                                                <?php echo ucfirst($goal['status']); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <div style="font-size: 13px;">
                                                <?php echo htmlspecialchars($goal['created_by_name'] ?? 'Unknown'); ?>
                                                <div style="color: var(--gray-500); font-size: 12px;">
                                                    <?php echo date('M d, Y', strtotime($goal['created_at'])); ?>
                                                </div>
                                            </div>
                                        </td>
                                        <td>
                                            <div class="action-buttons">
                                                <button class="btn btn-sm btn-outline edit-goal" data-goal='<?php echo json_encode($goal, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>'>
                                                    <i class="fas fa-edit"></i> Edit
                                                </button>
                                                <a href="assign_goals.php?goal_id=<?php echo $goal['id']; ?>" class="btn btn-sm btn-primary">
                                                    <i class="fas fa-paper-plane"></i> Assign
                                                </a>
                                                <?php if ($goal['status'] === 'inactive'): ?>
                                                    <form method="POST" style="display:inline;">
                                                        <input type="hidden" name="goal_id" value="<?php echo $goal['id']; ?>">
                                                        <input type="hidden" name="action" value="activate">
                                                        <button type="submit" class="btn btn-sm btn-success" onclick="return confirm('Activate this goal?')">
                                                            <i class="fas fa-check"></i>
                                                        </button>
                                                    </form>
                                                <?php else: ?>
                                                    <form method="POST" style="display:inline;">
                                                        <input type="hidden" name="goal_id" value="<?php echo $goal['id']; ?>">
                                                        <input type="hidden" name="action" value="deactivate">
                                                        <button type="submit" class="btn btn-sm btn-warning" onclick="return confirm('Deactivate this goal?')">
                                                            <i class="fas fa-ban"></i>
                                                        </button>
                                                    </form>
                                                <?php endif; ?>
                                                <form method="POST" style="display:inline;">
                                                    <input type="hidden" name="goal_id" value="<?php echo $goal['id']; ?>">
                                                    <input type="hidden" name="action" value="delete">
                                                    <button type="submit" class="btn btn-sm btn-danger" onclick="return confirm('Delete this system goal and all assignments? This action cannot be undone.')">
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

                <?php if ($total_pages_system > 1): ?>
                    <div class="pagination">
                        <?php for ($i = 1; $i <= $total_pages_system; $i++): ?>
                            <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $i])); ?>" 
                               class="pagination-link <?php echo $page == $i ? 'active' : ''; ?>">
                                <?php echo $i; ?>
                            </a>
                        <?php endfor; ?>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Personal Goals Tab -->
            <div class="tab-content" id="personal">
                <div class="goals-table-container">
                    <?php if (empty($personal_goals)): ?>
                        <div class="empty-state">
                            <i class="fas fa-user"></i>
                            <p>No personal (student self-created) goals found</p>
                        </div>
                    <?php else: ?>
                        <table>
                            <thead>
                                <tr>
                                    <th>Type</th>
                                    <th>Student</th>
                                    <th>Title</th>
                                    <th>Category</th>
                                    <th>Target</th>
                                    <th>Progress</th>
                                    <th>Due Date</th>
                                    <th>Priority</th>
                                    <th>Status</th>
                                    <th>Created</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($personal_goals as $goal): 
                                    $priority_class = 'priority-' . ($goal['priority'] ?? 'medium');
                                    $days_left = $goal['days_left'] ?? null;
                                ?>
                                    <tr>
                                        <td><span class="type-badge type-personal">Personal</span></td>
                                        <td>
                                            <div style="display: flex; align-items: center; gap: 10px;">
                                                <?php if ($goal['student_pic']): ?>
                                                    <img src="../<?php echo htmlspecialchars($goal['student_pic']); ?>" alt="Student" style="width: 32px; height: 32px; border-radius: 50%; object-fit: cover;">
                                                <?php else: ?>
                                                    <div style="width: 32px; height: 32px; border-radius: 50%; background: var(--primary); color: white; display: flex; align-items: center; justify-content: center; font-weight: bold; font-size: 14px;">
                                                        <?php echo strtoupper(substr($goal['student_name'], 0, 1)); ?>
                                                    </div>
                                                <?php endif; ?>
                                                <div>
                                                    <div style="font-weight: 600;"><?php echo htmlspecialchars($goal['student_name']); ?></div>
                                                    <div style="font-size: 12px; color: var(--gray-500);"><?php echo htmlspecialchars($goal['student_email']); ?></div>
                                                </div>
                                            </div>
                                        </td>
                                        <td>
                                            <strong><?php echo htmlspecialchars($goal['title']); ?></strong>
                                            <?php if ($goal['description']): ?>
                                                <div style="font-size: 13px; color: var(--gray-500); margin-top: 4px;">
                                                    <?php echo htmlspecialchars(substr($goal['description'], 0, 100)); ?>
                                                    <?php if (strlen($goal['description']) > 100): ?>...<?php endif; ?>
                                                </div>
                                            <?php endif; ?>
                                        </td>
                                        <td><?php echo htmlspecialchars($goal['category'] ?? 'Uncategorized'); ?></td>
                                        <td><strong><?php echo htmlspecialchars($goal['target_value'] . ' ' . ($goal['unit'] ?? '')); ?></strong></td>
                                        <td>
                                            <div class="progress-bar">
                                                <div class="progress-fill" style="width: <?php echo min(100, $goal['progress_percent'] ?? 0); ?>%"></div>
                                            </div>
                                            <div style="font-size: 12px; color: var(--gray-500);">
                                                <?php echo $goal['progress_count']; ?> updates
                                            </div>
                                        </td>
                                        <td>
                                            <?php if ($goal['due_date']): ?>
                                                <?php echo date('M d, Y', strtotime($goal['due_date'])); ?>
                                                <?php if ($days_left !== null): ?>
                                                    <div class="countdown <?php echo $days_left < 0 ? 'expired' : ($days_left < 7 ? 'soon' : 'safe'); ?>">
                                                        <i class="fas fa-clock"></i>
                                                        <?php echo $days_left < 0 ? abs($days_left) . ' days overdue' : $days_left . ' days left'; ?>
                                                    </div>
                                                <?php endif; ?>
                                            <?php else: ?>
                                                <span style="color: var(--gray-500);">No due date</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <span class="priority-badge <?php echo $priority_class; ?>">
                                                <i class="fas fa-<?php echo $priorities[$goal['priority'] ?? 'medium']['icon'] ?? 'circle'; ?>"></i>
                                                <?php echo ucfirst($goal['priority'] ?? 'medium'); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <span class="status-badge status-<?php echo $goal['status']; ?>">
                                                <?php echo ucfirst($goal['status']); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <div style="font-size: 13px;">
                                                <?php echo date('M d, Y', strtotime($goal['created_at'])); ?>
                                                <div style="color: var(--gray-500); font-size: 12px;">
                                                    <?php echo date('h:i A', strtotime($goal['created_at'])); ?>
                                                </div>
                                            </div>
                                        </td>
                                        <td>
                                            <div class="action-buttons">
                                                <form method="POST" style="display:inline;">
                                                    <input type="hidden" name="goal_id" value="<?php echo $goal['id']; ?>">
                                                    <input type="hidden" name="action" value="delete_personal_goal">
                                                    <button type="submit" class="btn btn-sm btn-danger" onclick="return confirm('Delete this personal goal? This action cannot be undone.')">
                                                        <i class="fas fa-trash"></i> Delete
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

                <?php if ($total_pages_personal > 1): ?>
                    <div class="pagination">
                        <?php for ($i = 1; $i <= $total_pages_personal; $i++): ?>
                            <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $i])); ?>" 
                               class="pagination-link <?php echo $page == $i ? 'active' : ''; ?>">
                                <?php echo $i; ?>
                            </a>
                        <?php endfor; ?>
                    </div>
                <?php endif; ?>
            </div>

            <div style="margin-top: 40px; display: flex; gap: 16px; flex-wrap: wrap;">
                <button class="btn btn-primary" id="addGoalBtn"><i class="fas fa-plus"></i> Add System Goal</button>
                <a href="assign_goals.php" class="btn btn-outline"><i class="fas fa-tasks"></i> Assign Goals</a>
                <a href="reports.php" class="btn btn-outline"><i class="fas fa-chart-bar"></i> View Reports</a>
                <button class="btn btn-outline" onclick="window.print()"><i class="fas fa-print"></i> Print Report</button>
            </div>
        </main>
    </div>

    <!-- Modal for System Goals -->
    <div class="modal-overlay" id="goalModalOverlay">
        <div class="modal">
            <div class="modal-header">
                <h3 id="modalTitle">Add System Goal</h3>
                <button class="modal-close" id="closeModal"><i class="fas fa-times"></i></button>
            </div>
            <div class="modal-body">
                <form method="POST" id="goalForm">
                    <input type="hidden" name="action" id="formAction" value="add_goal">
                    <input type="hidden" name="goal_id" id="goalId">

                    <div class="form-group">
                        <label><i class="fas fa-heading"></i> Title <span class="required"></span></label>
                        <input type="text" name="title" id="title" required placeholder="Enter goal title">
                        <div class="form-help">A clear, descriptive title for the goal</div>
                    </div>

                    <div class="form-group">
                        <label><i class="fas fa-align-left"></i> Description</label>
                        <textarea name="description" id="description" placeholder="Describe the goal in detail..."></textarea>
                        <div class="form-help">Provide clear instructions and expectations</div>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label><i class="fas fa-tags"></i> Category</label>
                            <input type="text" name="category" id="category" placeholder="e.g., Academic, Skill, Project">
                            <div class="form-help">Optional category for organization</div>
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-ruler"></i> Unit <span class="required"></span></label>
                            <input type="text" name="unit" id="unit" required placeholder="e.g., hours, pages, chapters">
                            <div class="form-help">Measurement unit for the target</div>
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label><i class="fas fa-bullseye"></i> Target Value <span class="required"></span></label>
                            <input type="number" name="target_value" id="target_value" min="0.01" step="0.01" required placeholder="e.g., 100">
                            <div class="form-help">Numerical target to achieve</div>
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-calendar-day"></i> Due Date</label>
                            <input type="date" name="due_date" id="due_date">
                            <div class="form-help">Optional deadline for the goal</div>
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label><i class="fas fa-flag"></i> Priority</label>
                            <select name="priority" id="priority">
                                <option value="low">Low</option>
                                <option value="medium" selected>Medium</option>
                                <option value="high">High</option>
                                <option value="critical">Critical</option>
                            </select>
                            <div class="form-help">Set priority level</div>
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-toggle-on"></i> Status</label>
                            <select name="status" id="status">
                                <option value="active" selected>Active</option>
                                <option value="inactive">Inactive</option>
                            </select>
                            <div class="form-help">Active goals can be assigned to students</div>
                        </div>
                    </div>

                    <div class="form-group">
                        <label><i class="fas fa-hourglass-half"></i> Estimated Time (Optional)</label>
                        <input type="number" name="estimated_hours" min="0.5" step="0.5" placeholder="e.g., 10.5 hours">
                        <div class="form-help">Estimated time needed to complete this goal</div>
                    </div>

                    <button type="submit" class="btn btn-primary" style="width:100%; margin-top:20px;" id="saveGoalBtn">
                        <i class="fas fa-save"></i> Save System Goal
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

        // Tabs
        document.querySelectorAll('.tab').forEach(tab => {
            tab.addEventListener('click', () => {
                document.querySelectorAll('.tab').forEach(t => t.classList.remove('active'));
                document.querySelectorAll('.tab-content').forEach(c => c.classList.remove('active'));
                tab.classList.add('active');
                document.getElementById(tab.dataset.tab).classList.add('active');
                localStorage.setItem('selectedTab', tab.dataset.tab);
            });
        });

        // Restore selected tab
        const savedTab = localStorage.getItem('selectedTab');
        if (savedTab) {
            const tab = document.querySelector(`.tab[data-tab="${savedTab}"]`);
            if (tab) {
                tab.click();
            }
        }

        // Modal functions
        const modalOverlay = document.getElementById('goalModalOverlay');
        const closeModal = document.getElementById('closeModal');
        const addBtn = document.getElementById('addGoalBtn');
        const addBtnSystem = document.getElementById('addGoalBtnSystem');

        function openModal(goal = null) {
            const title = document.getElementById('modalTitle');
            const form = document.getElementById('goalForm');
            
            if (goal) {
                title.textContent = 'Edit System Goal';
                document.getElementById('formAction').value = 'edit_goal';
                document.getElementById('goalId').value = goal.id;
                document.getElementById('title').value = goal.title;
                document.getElementById('description').value = goal.description || '';
                document.getElementById('category').value = goal.category || '';
                document.getElementById('target_value').value = goal.target_value;
                document.getElementById('unit').value = goal.unit || '';
                document.getElementById('due_date').value = goal.due_date || '';
                document.getElementById('priority').value = goal.priority || 'medium';
                document.getElementById('status').value = goal.status || 'active';
            } else {
                title.textContent = 'Add System Goal';
                document.getElementById('formAction').value = 'add_goal';
                document.getElementById('goalId').value = '';
                form.reset();
                document.getElementById('priority').value = 'medium';
                document.getElementById('status').value = 'active';
                
                // Set default due date to 30 days from now
                const defaultDue = new Date();
                defaultDue.setDate(defaultDue.getDate() + 30);
                document.getElementById('due_date').value = defaultDue.toISOString().split('T')[0];
                document.getElementById('due_date').min = new Date().toISOString().split('T')[0];
            }
            
            modalOverlay.classList.add('active');
        }

        addBtn?.addEventListener('click', () => openModal());
        addBtnSystem?.addEventListener('click', () => openModal());
        closeModal?.addEventListener('click', () => modalOverlay.classList.remove('active'));
        modalOverlay?.addEventListener('click', (e) => { 
            if (e.target === modalOverlay) modalOverlay.classList.remove('active'); 
        });

        // Edit system goals
        document.querySelectorAll('.edit-goal').forEach(btn => {
            btn.addEventListener('click', function() {
                try {
                    const goal = JSON.parse(this.dataset.goal);
                    openModal(goal);
                } catch (e) {
                    console.error('Error parsing goal data:', e);
                    alert('Error loading goal data. Please try again.');
                }
            });
        });

        // Form validation
        document.getElementById('goalForm')?.addEventListener('submit', function(e) {
            const title = document.getElementById('title').value.trim();
            const targetValue = document.getElementById('target_value').value;
            const unit = document.getElementById('unit').value.trim();
            
            if (!title) {
                e.preventDefault();
                alert('Please enter a goal title.');
                return false;
            }
            
            if (!targetValue || parseFloat(targetValue) <= 0) {
                e.preventDefault();
                alert('Please enter a valid target value greater than 0.');
                return false;
            }
            
            if (!unit) {
                e.preventDefault();
                alert('Please enter a unit.');
                return false;
            }
            
            // Show loading
            const submitBtn = document.getElementById('saveGoalBtn');
            const originalText = submitBtn.innerHTML;
            submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving...';
            submitBtn.disabled = true;
            
            setTimeout(() => {
                submitBtn.disabled = false;
                submitBtn.innerHTML = originalText;
            }, 3000);
        });

        // Bulk actions for system goals
        const bulkActionsSystem = document.getElementById('bulkActionsSystem');
        const selectAllSystem = document.getElementById('selectAllHeaderSystem');
        const selectAllCheckboxSystem = document.getElementById('selectAllSystem');
        const selectedCountSystem = document.getElementById('selectedCountSystem');
        const clearSelectionSystem = document.getElementById('clearSelectionSystem');
        const applyBulkActionSystem = document.getElementById('applyBulkActionSystem');
        const bulkActionSelectSystem = document.getElementById('bulkActionSelectSystem');

        let selectedGoals = [];

        function updateBulkActions() {
            const checkboxes = document.querySelectorAll('#systemGoalsTable .goal-checkbox:checked');
            selectedGoals = Array.from(checkboxes).map(cb => cb.dataset.goalId);
            
            if (selectedGoals.length > 0) {
                bulkActionsSystem.style.display = 'flex';
                selectedCountSystem.textContent = `${selectedGoals.length} goal${selectedGoals.length !== 1 ? 's' : ''} selected`;
                selectAllSystem.checked = checkboxes.length === document.querySelectorAll('#systemGoalsTable .goal-checkbox').length;
                selectAllCheckboxSystem.checked = selectAllSystem.checked;
            } else {
                bulkActionsSystem.style.display = 'none';
            }
        }

        // Select all checkboxes
        selectAllSystem?.addEventListener('change', function() {
            const checkboxes = document.querySelectorAll('#systemGoalsTable .goal-checkbox');
            checkboxes.forEach(cb => cb.checked = this.checked);
            selectAllCheckboxSystem.checked = this.checked;
            updateBulkActions();
        });

        selectAllCheckboxSystem?.addEventListener('change', function() {
            const checkboxes = document.querySelectorAll('#systemGoalsTable .goal-checkbox');
            checkboxes.forEach(cb => cb.checked = this.checked);
            selectAllSystem.checked = this.checked;
            updateBulkActions();
        });

        // Individual checkbox changes
        document.addEventListener('change', function(e) {
            if (e.target.classList.contains('goal-checkbox')) {
                updateBulkActions();
            }
        });

        // Clear selection
        clearSelectionSystem?.addEventListener('click', function() {
            const checkboxes = document.querySelectorAll('#systemGoalsTable .goal-checkbox');
            checkboxes.forEach(cb => cb.checked = false);
            selectAllSystem.checked = false;
            selectAllCheckboxSystem.checked = false;
            updateBulkActions();
        });

        // Apply bulk action
        applyBulkActionSystem?.addEventListener('click', function() {
            const action = bulkActionSelectSystem.value;
            if (!action) {
                alert('Please select a bulk action.');
                return;
            }
            
            if (!selectedGoals.length) {
                alert('Please select at least one goal.');
                return;
            }
            
            if (!confirm(`Are you sure you want to ${action} ${selectedGoals.length} goal(s)?`)) {
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
            
            selectedGoals.forEach(goalId => {
                const input = document.createElement('input');
                input.type = 'hidden';
                input.name = 'goal_ids[]';
                input.value = goalId;
                form.appendChild(input);
            });
            
            document.body.appendChild(form);
            form.submit();
        });

        // Real-time clock
        function updateClock() {
            const now = new Date();
            const timeString = now.toLocaleTimeString('en-US', {
                hour12: true,
                hour: '2-digit',
                minute: '2-digit',
                second: '2-digit'
            });
            const dateString = now.toLocaleDateString('en-US', {
                weekday: 'short',
                month: 'short',
                day: 'numeric'
            });
            document.getElementById('currentTime').textContent = `${dateString} ${timeString}`;
        }
        setInterval(updateClock, 1000);
        updateClock();

        // Auto-save form data
        function autoSaveForm() {
            const form = document.getElementById('goalForm');
            if (!form) return;
            
            const formData = new FormData(form);
            const data = {};
            formData.forEach((value, key) => {
                data[key] = value;
            });
            
            localStorage.setItem('goalFormDraft', JSON.stringify(data));
        }

        // Load auto-saved data
        function loadAutoSaved() {
            const saved = localStorage.getItem('goalFormDraft');
            if (saved && !document.getElementById('goalId').value) {
                const data = JSON.parse(saved);
                const form = document.getElementById('goalForm');
                
                Object.keys(data).forEach(key => {
                    const input = form.querySelector(`[name="${key}"]`);
                    if (input && input.type !== 'hidden' && !input.value) {
                        input.value = data[key];
                    }
                });
            }
        }

        // Auto-save every 3 seconds
        document.getElementById('goalForm')?.addEventListener('input', autoSaveForm);
        window.addEventListener('beforeunload', () => {
            if (!document.getElementById('goalId').value) {
                autoSaveForm();
            } else {
                localStorage.removeItem('goalFormDraft');
            }
        });

        // Clear saved form on successful submit
        document.getElementById('goalForm')?.addEventListener('submit', function() {
            localStorage.removeItem('goalFormDraft');
        });

        // Initialize
        loadAutoSaved();
        updateBulkActions();

        // Export functionality
        function exportGoals(type) {
            const currentTab = document.querySelector('.tab.active').dataset.tab;
            let url = `export_goals.php?type=${type}&tab=${currentTab}`;
            
            // Add filters to export
            const params = new URLSearchParams(window.location.search);
            url += '&' + params.toString();
            
            window.open(url, '_blank');
        }

        // Add export buttons dynamically
        const actionButtons = document.querySelector('.page-header');
        if (actionButtons) {
            const exportDiv = document.createElement('div');
            exportDiv.style.display = 'flex';
            exportDiv.style.gap = '10px';
            exportDiv.innerHTML = `
                <button class="btn btn-outline" onclick="exportGoals('csv')"><i class="fas fa-file-csv"></i> CSV</button>
                <button class="btn btn-outline" onclick="exportGoals('excel')"><i class="fas fa-file-excel"></i> Excel</button>
                <button class="btn btn-outline" onclick="exportGoals('pdf')"><i class="fas fa-file-pdf"></i> PDF</button>
            `;
            actionButtons.appendChild(exportDiv);
        }
    </script>
</body>
</html>