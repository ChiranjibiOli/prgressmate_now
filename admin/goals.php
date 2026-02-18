<?php
session_start();
require_once '../includes/db_connection.php';
require_once '../includes/functions.php';

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
    (title, description, category, target_value, unit, reward_points, requires_approval, due_date, priority, status, created_by, created_at)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'active', ?, NOW())
");

                $stmt->execute([
                    trim($_POST['title']),
                    $_POST['description'] ?? '',
                    $_POST['category'] ?? '',
                    $_POST['target_value'],
                    $_POST['unit'],
                    (int)($_POST['reward_points'] ?? 10),
                    (int)($_POST['requires_approval'] ?? 0),
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
                $started = getStat($pdo, "
    SELECT COUNT(*)
    FROM student_goals
    WHERE goal_id = ? 
      AND deleted_at IS NULL
      AND (
            COALESCE(progress_percentage,0) > 0
         OR COALESCE(current_value,0) > 0
         OR status IN ('in_progress','completed','overdue')
      )
", [$_POST['goal_id']]);

                if ($started > 0) {
                    throw new Exception("This goal cannot be edited because students have already started it. You can only activate/deactivate it.");
                }


                $stmt = $pdo->prepare("
    UPDATE admin_goals 
    SET title = ?, description = ?, category = ?, target_value = ?, unit = ?,
        reward_points = ?, requires_approval = ?,
        due_date = ?, priority = ?, status = ?, updated_at = NOW()
    WHERE id = ?
");


                $stmt->execute([
                    trim($_POST['title']),
                    $_POST['description'] ?? '',
                    $_POST['category'] ?? '',
                    $_POST['target_value'],
                    $_POST['unit'],
                    (int)($_POST['reward_points'] ?? 10),
                    (int)($_POST['requires_approval'] ?? 0),
                    $_POST['due_date'] ? date('Y-m-d', strtotime($_POST['due_date'])) : null,
                    $_POST['priority'] ?? 'medium',
                    $_POST['status'] ?? 'active',
                    $_POST['goal_id']
                ]);


                // Update all student goals that reference this system goal
                if ($_POST['status'] === 'inactive') {
                    $pdo->prepare("
                        UPDATE student_goals
SET status='inactive'
WHERE goal_id=? AND status='pending' AND progress_percentage=0

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
$where_personal_clause = $where_personal ? ' AND ' . implode(' AND ', $where_personal) : '';


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
                      (SELECT COUNT(*) 
            FROM student_goals sg 
            WHERE sg.goal_id = a.id 
              AND sg.deleted_at IS NULL
              AND (
                    COALESCE(sg.progress_percentage,0) > 0
                 OR COALESCE(sg.current_value,0) > 0
                 OR sg.status IN ('in_progress','completed','overdue')
              )
           ) AS started_count,

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
           CASE 
  WHEN sg.target_value IS NULL OR sg.target_value = 0 THEN 0
  ELSE (sg.current_value / sg.target_value) * 100
END as progress_percent

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

$current = basename($_SERVER['PHP_SELF']);

// Sidebar stats (optional badges)
$students_count = (int)($stats['students'] ?? 0);
$goals_count    = (int)(($stats['system_goals'] ?? 0) + ($stats['personal_goals'] ?? 0));
$assigned_count = (int)($stats['assigned'] ?? 0);
$points_count   = (int)($stats['points'] ?? 0);

// If you have progress requests table, show pending badge (safe if table exists)
$pending_requests = 0;
try {
    $pending_requests = (int)$pdo->query("SELECT COUNT(*) FROM progress_requests WHERE status='pending'")->fetchColumn();
} catch (Exception $e) {
    $pending_requests = 0;
}
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


:root{
  --bg0:#070A18;
  --bg1:#0B1030;

  --text:#EAF0FF;
  --muted: rgba(234,240,255,.68);
  --muted2: rgba(234,240,255,.52);

  --primary:#7C3AED;
  --primary-light: rgba(124,58,237,.14);

  --cyan:#22D3EE;
  --pink:#FB7185;

  --success:#34D399;
  --warning:#FBBF24;
  --danger:#FB7185;
  --info:#22D3EE;

  --gray-50: rgba(255,255,255,.02);
  --gray-100: rgba(255,255,255,.04);
  --gray-200: rgba(255,255,255,.06);
  --gray-300: rgba(255,255,255,.10);
  --gray-500: rgba(234,240,255,.60);
  --gray-900: rgba(234,240,255,.95);

  --border: rgba(255,255,255,.10);
  --border2: rgba(255,255,255,.08);

  --shadow: 0 18px 45px rgba(0,0,0,.35);
  --shadow2: 0 10px 30px rgba(0,0,0,.22);

  --r12: 12px;
  --r14: 14px;
  --r16: 16px;
  --r20: 20px;
}

/* ---------- Base ---------- */
*{ box-sizing:border-box; }
html,body{ height:100%; }
body{
  margin:0;
  color: var(--text);
  font-family: system-ui, -apple-system, Segoe UI, Roboto, Arial, sans-serif;
  background:
    radial-gradient(900px 520px at 18% 10%, rgba(124,58,237,.24), transparent 60%),
    radial-gradient(900px 520px at 88% 15%, rgba(34,211,238,.18), transparent 58%),
    radial-gradient(900px 520px at 70% 95%, rgba(251,113,133,.14), transparent 62%),
    linear-gradient(180deg, var(--bg0), var(--bg1));
  overflow-x:hidden;
}
a{ color: inherit; text-decoration:none; }
img{ max-width:100%; display:block; }

/* ---------- Mobile Toggle + Overlay ---------- */
.mobile-toggle{
  position: fixed;
  top: 16px;
  left: 16px;
  z-index: 2000;
  width: 44px;
  height: 44px;
  display: none;
  place-items: center;
  border-radius: 14px;
  border: 1px solid var(--border);
  background: rgba(10,14,35,.60);
  color: var(--text);
  box-shadow: var(--shadow2);
  backdrop-filter: blur(12px);
  cursor:pointer;
}
.mobile-toggle i{ font-size: 18px; }

.sidebar-overlay{
  position: fixed;
  inset: 0;
  background: rgba(2,6,23,.55);
  opacity: 0;
  pointer-events: none;
  transition: opacity .25s ease;
  z-index: 1500;
}
.sidebar-overlay.active{
  opacity: 1;
  pointer-events: auto;
}

/* ---------- Layout ---------- */
.dashboard-wrapper{
  display: grid;
  grid-template-columns: 320px 1fr;
  min-height: 100vh;
}

/* ======================================================
   SIDEBAR — fixed (NO full scroll), only nav scroll
====================================================== */
.sidebar{
  position: sticky;
  top: 0;
  height: 100vh;
  overflow: hidden;
  display:flex;
  flex-direction: column;

  padding: 18px 16px 16px;
  background:
    radial-gradient(700px 320px at 20% 0%, rgba(124,58,237,.22), transparent 60%),
    radial-gradient(520px 300px at 100% 20%, rgba(34,211,238,.15), transparent 60%),
    linear-gradient(180deg, rgba(10,14,35,.85), rgba(10,14,35,.62));
  border-right: 1px solid rgba(255,255,255,.10);
  backdrop-filter: blur(16px);
  box-shadow: 0 10px 50px rgba(0,0,0,.25);
}
.sidebar::before{
  content:"";
  position:absolute;
  inset:-2px;
  background: linear-gradient(120deg, rgba(124,58,237,.22), rgba(34,211,238,.16), rgba(251,113,133,.14));
  opacity:.22;
  filter: blur(26px);
  pointer-events:none;
  z-index:0;
}
.sidebar-header,.user-profile,.nav-menu,.sidebar-quick-stats,.sidebar-footer{
  position:relative;
  z-index:2;
}

/* sidebar header */
.sidebar-header{
  display:flex;
  align-items:center;
  justify-content: space-between;
  padding: 10px 10px 14px;
  flex: 0 0 auto;
}
.logo{
  display:flex;
  align-items:center;
  gap:10px;
  font-weight: 950;
  letter-spacing: .2px;
  font-size: 18px;
}
.logo i{
  width: 34px;
  height: 34px;
  display:grid;
  place-items:center;
  border-radius: 12px;
  background:
    radial-gradient(120% 140% at 30% 25%, rgba(255,255,255,.18), transparent 55%),
    linear-gradient(135deg, rgba(124,58,237,.70), rgba(34,211,238,.35));
  border: 1px solid rgba(255,255,255,.18);
  box-shadow: 0 14px 30px rgba(124,58,237,.18);
}
.sidebar-close{
  display:none;
  width: 40px;
  height: 40px;
  border-radius: 14px;
  border: 1px solid rgba(255,255,255,.14);
  background: rgba(255,255,255,.06);
  color: var(--text);
  cursor:pointer;
}

/* user profile */
.user-profile{
  display:flex;
  gap: 12px;
  padding: 14px 12px;
  border-radius: var(--r16);
  border: 1px solid var(--border2);
  background:
    radial-gradient(140% 180% at 10% 0%, rgba(255,255,255,.10), transparent 60%),
    linear-gradient(180deg, rgba(255,255,255,.05), rgba(255,255,255,.03));
  box-shadow: 0 12px 26px rgba(0,0,0,.18);
  flex: 0 0 auto;
}
.profile-pic{
  width: 52px;
  height: 52px;
  border-radius: 16px;
  object-fit: cover;
  border: 1px solid rgba(255,255,255,.16);
  box-shadow: 0 10px 20px rgba(0,0,0,.22);
}
.profile-pic.default{
  display:grid;
  place-items:center;
  font-weight: 950;
  font-size: 18px;
  background:
    radial-gradient(120% 140% at 30% 25%, rgba(255,255,255,.18), transparent 55%),
    linear-gradient(135deg, rgba(34,211,238,.55), rgba(124,58,237,.55));
}
.user-info h4{ margin: 2px 0 2px; font-size: 15px; font-weight: 950; }
.user-info p{
  margin: 0;
  font-size: 12.5px;
  color: var(--muted);
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
  max-width: 210px;
}

/* only nav scroll */
.nav-menu{
  flex: 1 1 auto;
  overflow-y: auto;
  overflow-x: hidden;
  padding: 14px 6px 10px;
  margin-top: 10px;
  display:flex;
  flex-direction: column;
  gap: 6px;

  scrollbar-width: thin;
  scrollbar-color: rgba(255,255,255,.18) transparent;
}
.nav-menu::-webkit-scrollbar{ width: 8px; }
.nav-menu::-webkit-scrollbar-thumb{
  background: rgba(255,255,255,.16);
  border-radius: 99px;
}
.nav-menu::-webkit-scrollbar-thumb:hover{ background: rgba(255,255,255,.22); }

.nav-link{
  display:flex;
  align-items:center;
  gap: 12px;
  padding: 12px 12px;
  border-radius: 14px;
  color: rgba(234,240,255,.92);
  border: 1px solid transparent;
  transition: transform .18s ease, background .18s ease, border-color .18s ease, box-shadow .18s ease;
  min-height: 46px;
}
.nav-link i{
  width: 34px;
  height: 34px;
  display:grid;
  place-items:center;
  border-radius: 12px;
  background: rgba(255,255,255,.05);
  border: 1px solid rgba(255,255,255,.10);
}
.nav-link:hover{
  background: rgba(255,255,255,.06);
  border-color: rgba(255,255,255,.12);
  transform: translateX(2px);
}
.nav-link.active{
  background:
    radial-gradient(120% 160% at 10% 20%, rgba(255,255,255,.14), transparent 55%),
    linear-gradient(135deg, rgba(124,58,237,.55), rgba(34,211,238,.20));
  border-color: rgba(255,255,255,.18);
  box-shadow: 0 18px 40px rgba(124,58,237,.20);
}
.badge{
  margin-left:auto;
  font-size: 12px;
  font-weight: 950;
  padding: 4px 10px;
  border-radius: 999px;
  color: var(--text);
  background:
    radial-gradient(120% 180% at 20% 20%, rgba(255,255,255,.20), transparent 55%),
    linear-gradient(135deg, rgba(251,113,133,.70), rgba(124,58,237,.45));
  border: 1px solid rgba(255,255,255,.18);
}

/* quick stats */
.sidebar-quick-stats{
  flex: 0 0 auto;
  margin-top: 10px;
  padding: 10px;
  border-radius: var(--r16);
  border: 1px solid rgba(255,255,255,.10);
  background: rgba(255,255,255,.03);
}
.sidebar-stat{
  display:flex;
  gap: 12px;
  align-items:center;
  padding: 10px;
  border-radius: 14px;
  transition: background .18s ease;
}
.sidebar-stat:hover{ background: rgba(255,255,255,.04); }
.sidebar-stat-icon{
  width: 38px;
  height: 38px;
  border-radius: 14px;
  display:grid;
  place-items:center;
  border: 1px solid rgba(255,255,255,.12);
  background:
    radial-gradient(120% 180% at 20% 10%, rgba(255,255,255,.18), transparent 55%),
    linear-gradient(135deg, rgba(34,211,238,.35), rgba(124,58,237,.35));
  box-shadow: 0 14px 26px rgba(0,0,0,.18);
}
.sidebar-stat-label{ font-size: 12px; color: var(--muted); }
.sidebar-stat-number{ font-size: 18px; font-weight: 950; }

.sidebar-footer{ flex: 0 0 auto; margin-top: 12px; }
.logout-btn{
  display:flex;
  align-items:center;
  justify-content:center;
  gap: 10px;
  padding: 12px 12px;
  border-radius: 14px;
  border: 1px solid rgba(255,255,255,.14);
  background:
    radial-gradient(140% 180% at 20% 0%, rgba(255,255,255,.10), transparent 60%),
    linear-gradient(135deg, rgba(251,113,133,.20), rgba(255,255,255,.03));
  box-shadow: 0 14px 26px rgba(0,0,0,.16);
  transition: transform .18s ease, box-shadow .18s ease;
}
.logout-btn:hover{
  transform: translateY(-1px);
  box-shadow: 0 18px 34px rgba(251,113,133,.18);
}

/* ======================================================
   MAIN CONTENT
====================================================== */
.main-content{ padding: 26px 26px 44px; }

.page-header{
  display:flex;
  align-items:flex-start;
  justify-content: space-between;
  gap: 16px;
  padding: 18px 18px;
  border-radius: var(--r20);
  border: 1px solid var(--border);
  background:
    radial-gradient(120% 220% at 15% 10%, rgba(255,255,255,.10), transparent 55%),
    linear-gradient(180deg, rgba(255,255,255,.05), rgba(255,255,255,.03));
  box-shadow: var(--shadow2);
}
.header-content h1{ margin: 0 0 6px; font-size: 26px; font-weight: 950; }
.header-content p{ margin: 0; color: var(--muted); font-size: 14px; }

/* timer */
.timer-display{
  display:flex;
  align-items:center;
  gap: 10px;
  padding: 10px 12px;
  border-radius: 14px;
  border: 1px solid rgba(255,255,255,.12);
  background: rgba(255,255,255,.04);
  box-shadow: 0 12px 26px rgba(0,0,0,.12);
  white-space: nowrap;
}
.timer-display i{
  width: 32px;
  height: 32px;
  border-radius: 12px;
  display:grid;
  place-items:center;
  border: 1px solid rgba(255,255,255,.12);
  background: rgba(255,255,255,.05);
}
#currentTime{ font-weight: 900; color: rgba(234,240,255,.92); }

/* ======================================================
   BUTTONS
====================================================== */
.btn{
  display:inline-flex;
  align-items:center;
  justify-content:center;
  gap: 10px;
  padding: 12px 14px;
  border-radius: 14px;
  font-weight: 950;
  border: 1px solid rgba(255,255,255,.14);
  color: var(--text);
  background: rgba(255,255,255,.05);
  cursor:pointer;
  transition: transform .18s ease, box-shadow .18s ease, background .18s ease, border-color .18s ease;
  white-space: nowrap;
}
.btn:hover{
  transform: translateY(-1px);
  background: rgba(255,255,255,.07);
  box-shadow: 0 0 0 1px rgba(255,255,255,.08), 0 12px 30px rgba(124,58,237,.18);
}
.btn-outline{ background: rgba(255,255,255,.03); }
.btn-outline:hover{ box-shadow: 0 0 0 1px rgba(255,255,255,.08), 0 14px 38px rgba(34,211,238,.14); }

.btn-primary{
  border-color: rgba(124,58,237,.35);
  background:
    radial-gradient(120% 180% at 20% 10%, rgba(255,255,255,.16), transparent 55%),
    linear-gradient(135deg, rgba(124,58,237,.62), rgba(34,211,238,.18));
}

.btn-success{ background: rgba(52,211,153,.15); border-color: rgba(52,211,153,.25); }
.btn-warning{ background: rgba(251,191,36,.15); border-color: rgba(251,191,36,.25); }
.btn-danger{  background: rgba(251,113,133,.15); border-color: rgba(251,113,133,.25); }

.btn-sm{
  padding: 8px 10px;
  border-radius: 12px;
  font-size: 12px;
  font-weight: 950;
}
.btn[disabled]{ opacity:.55; cursor:not-allowed; transform:none !important; box-shadow:none !important; }

/* ======================================================
   ALERTS
====================================================== */
.alert{
  margin-top: 14px;
  padding: 12px 14px;
  border-radius: 14px;
  border: 1px solid rgba(255,255,255,.12);
  background: rgba(255,255,255,.04);
  display:flex;
  align-items:center;
  gap:10px;
}
.alert-success{ border-color: rgba(52,211,153,.25); background: rgba(52,211,153,.10); }
.alert-error{ border-color: rgba(251,113,133,.25); background: rgba(251,113,133,.10); }
.alert button i{ color: rgba(234,240,255,.85); }

/* ======================================================
   STATS GRID (4 cards)
====================================================== */
.stats-grid{
  margin-top: 18px;
  display:grid;
  grid-template-columns: repeat(4, minmax(0, 1fr));
  gap: 14px;
}
.stat-card{
  border-radius: var(--r20);
  border: 1px solid rgba(255,255,255,.12);
  background:
    radial-gradient(120% 180% at 10% 0%, rgba(255,255,255,.12), transparent 60%),
    linear-gradient(180deg, rgba(255,255,255,.05), rgba(255,255,255,.03));
  box-shadow: var(--shadow2);
  padding: 14px 14px;
  transition: transform .18s ease, box-shadow .18s ease;
}
.stat-card:hover{
  transform: translateY(-2px);
  box-shadow: 0 0 0 1px rgba(255,255,255,.08), 0 18px 45px rgba(124,58,237,.18);
}
.stat-card .stat-number{
  font-size: 26px;
  font-weight: 950;
  letter-spacing: .2px;
}
.stat-card .stat-label{
  margin-top: 4px;
  font-size: 13px;
  color: var(--muted);
  font-weight: 800;
}

/* ======================================================
   TABS
====================================================== */
.tabs{
  margin-top: 16px;
  display:flex;
  gap: 10px;
  flex-wrap: wrap;
}
.tab{
  display:inline-flex;
  align-items:center;
  gap: 10px;
  padding: 12px 14px;
  border-radius: 14px;
  border: 1px solid rgba(255,255,255,.12);
  background: rgba(255,255,255,.04);
  color: rgba(234,240,255,.90);
  cursor:pointer;
  font-weight: 950;
  transition: transform .18s ease, box-shadow .18s ease, background .18s ease;
}
.tab:hover{
  transform: translateY(-1px);
  box-shadow: 0 12px 30px rgba(124,58,237,.12);
}
.tab.active{
  background: linear-gradient(135deg, rgba(124,58,237,.58), rgba(34,211,238,.18));
  border-color: rgba(255,255,255,.18);
  box-shadow: 0 18px 40px rgba(124,58,237,.18);
}
.tab-badge{
  margin-left: 6px;
  font-size: 12px;
  font-weight: 950;
  padding: 3px 9px;
  border-radius: 999px;
  border: 1px solid rgba(255,255,255,.16);
  background: rgba(255,255,255,.06);
}

/* tab contents */
.tab-content{ display:none; }
.tab-content.active{ display:block; }

/* ======================================================
   FILTERS (toggle box)
====================================================== */
.filters-section{
  margin-top: 12px;
  border-radius: var(--r20);
  border: 1px solid rgba(255,255,255,.10);
  background: rgba(255,255,255,.04);
  box-shadow: var(--shadow2);
  padding: 14px 14px;
}
.filter-grid{
  display:grid;
  grid-template-columns: 1.4fr .9fr .9fr .9fr .9fr .9fr .8fr .8fr;
  gap: 12px;
  align-items:end;
}
.filter-group label{
  display:block;
  font-size: 12px;
  color: var(--muted);
  margin-bottom: 6px;
  font-weight: 900;
}
.filter-group input,
.filter-group select{
  width: 100%;
  padding: 11px 12px;
  border-radius: 12px;
  border: 1px solid rgba(255,255,255,.12);
  background: rgba(10,14,35,.45);
  color: var(--text);
  outline: none;
}
.filter-group input::placeholder{ color: rgba(234,240,255,.45); }
.filter-group input:focus,
.filter-group select:focus{
  border-color: rgba(34,211,238,.30);
  box-shadow: 0 0 0 3px rgba(34,211,238,.16);
}

/* ======================================================
   BULK ACTION BAR (system)
====================================================== */
.bulk-actions{
  margin-top: 14px;
  padding: 12px 14px;
  border-radius: var(--r16);
  border: 1px solid rgba(255,255,255,.10);
  background: rgba(255,255,255,.04);
  display:flex;
  align-items:center;
  gap: 10px;
  flex-wrap: wrap;
}
.bulk-select{
  display:flex;
  align-items:center;
  gap: 10px;
  margin-right: 6px;
}
.select-all-checkbox{
  width: 16px;
  height: 16px;
  accent-color: var(--cyan);
}
.bulk-actions select.btn{
  padding: 10px 12px;
}

/* ======================================================
   TABLE CONTAINERS
====================================================== */
.goals-table-container{
  margin-top: 12px;
  border-radius: var(--r20);
  border: 1px solid rgba(255,255,255,.10);
  background: rgba(255,255,255,.035);
  box-shadow: var(--shadow);
  overflow: hidden;
}

/* tables */
table{ width: 100%; border-collapse: collapse; }
thead th{
  text-align:left;
  font-size: 12px;
  letter-spacing: .25px;
  text-transform: uppercase;
  color: rgba(234,240,255,.75);
  background: rgba(255,255,255,.04);
  border-bottom: 1px solid rgba(255,255,255,.08);
  padding: 14px 12px;
}
tbody td{
  padding: 14px 12px;
  border-bottom: 1px solid rgba(255,255,255,.06);
  vertical-align: middle;
}
tbody tr:hover{ background: rgba(255,255,255,.03); }
tbody tr:last-child td{ border-bottom: none; }

input[type="checkbox"]{
  width: 16px;
  height: 16px;
  accent-color: var(--cyan);
}

/* title column prevent ugly wrap */
.col-title{ min-width: 320px; }
.col-title *{ max-width: 100%; }

/* action buttons wrap nicely */
.action-buttons{
  display:flex;
  flex-wrap: wrap;
  gap: 8px;
}

/* ======================================================
   BADGES: type / priority / status
====================================================== */
.type-badge{
  display:inline-flex;
  align-items:center;
  gap:6px;
  padding: 4px 10px;
  border-radius: 999px;
  font-size: 12px;
  font-weight: 950;
  border: 1px solid rgba(255,255,255,.12);
  background: rgba(255,255,255,.05);
}
.type-system{
  border-color: rgba(34,211,238,.25);
  background: rgba(34,211,238,.10);
  color: rgba(34,211,238,.95);
}
.type-personal{
  border-color: rgba(124,58,237,.25);
  background: rgba(124,58,237,.10);
  color: rgba(180,150,255,.95);
}

.priority-badge{
  display:inline-flex;
  align-items:center;
  gap: 6px;
  padding: 6px 10px;
  border-radius: 999px;
  font-size: 12px;
  font-weight: 950;
  border: 1px solid rgba(255,255,255,.12);
  background: rgba(255,255,255,.04);
}
.priority-low{ color: rgba(34,211,238,.95); border-color: rgba(34,211,238,.22); background: rgba(34,211,238,.10); }
.priority-medium{ color: rgba(251,191,36,.95); border-color: rgba(251,191,36,.20); background: rgba(251,191,36,.10); }
.priority-high{ color: rgba(251,113,133,.95); border-color: rgba(251,113,133,.20); background: rgba(251,113,133,.10); }
.priority-critical{ color: rgba(255,120,255,.95); border-color: rgba(255,120,255,.20); background: rgba(255,120,255,.10); }

/* status */
.status-badge{
  display:inline-flex;
  align-items:center;
  justify-content:center;
  padding: 6px 10px;
  border-radius: 999px;
  font-size: 12px;
  font-weight: 950;
  border: 1px solid rgba(255,255,255,.12);
  background: rgba(255,255,255,.04);
}
.status-active{ color: rgba(52,211,153,1); border-color: rgba(52,211,153,.25); background: rgba(52,211,153,.10); }
.status-inactive{ color: rgba(251,191,36,1); border-color: rgba(251,191,36,.25); background: rgba(251,191,36,.10); }
.status-pending{ color: rgba(34,211,238,1); border-color: rgba(34,211,238,.25); background: rgba(34,211,238,.10); }
.status-completed{ color: rgba(52,211,153,1); border-color: rgba(52,211,153,.25); background: rgba(52,211,153,.10); }
.status-overdue{ color: rgba(251,113,133,1); border-color: rgba(251,113,133,.25); background: rgba(251,113,133,.10); }

/* ======================================================
   PROGRESS BAR
====================================================== */
.progress-bar{
  height: 10px;
  width: 100%;
  border-radius: 999px;
  background: rgba(255,255,255,.07);
  border: 1px solid rgba(255,255,255,.08);
  overflow:hidden;
}
.progress-fill{
  height: 100%;
  width: 0%;
  border-radius: 999px;
  background: linear-gradient(90deg, rgba(34,211,238,.95), rgba(124,58,237,.95));
  box-shadow: 0 10px 25px rgba(34,211,238,.14);
  transition: width 1s cubic-bezier(.22,.75,.12,1);
}

/* ======================================================
   COUNTDOWN
====================================================== */
.countdown{
  display:inline-flex;
  align-items:center;
  gap: 8px;
  padding: 6px 10px;
  border-radius: 999px;
  font-size: 12px;
  font-weight: 950;
  border: 1px solid rgba(255,255,255,.12);
  background: rgba(255,255,255,.04);
}
.countdown.safe{ color: rgba(52,211,153,.95); border-color: rgba(52,211,153,.22); background: rgba(52,211,153,.10); }
.countdown.soon{ color: rgba(251,191,36,.95); border-color: rgba(251,191,36,.22); background: rgba(251,191,36,.10); }
.countdown.expired{ color: rgba(251,113,133,.95); border-color: rgba(251,113,133,.22); background: rgba(251,113,133,.10); }

/* ======================================================
   PAGINATION
====================================================== */
.pagination{
  margin-top: 14px;
  display:flex;
  align-items:center;
  gap: 8px;
  flex-wrap: wrap;
}
.pagination-link, .pagination a{
  padding: 10px 12px;
  border-radius: 12px;
  border: 1px solid rgba(255,255,255,.12);
  background: rgba(255,255,255,.04);
  color: var(--text);
  font-weight: 900;
}
.pagination-link:hover, .pagination a:hover{
  box-shadow: 0 0 0 1px rgba(255,255,255,.08), 0 12px 30px rgba(124,58,237,.16);
  transform: translateY(-1px);
}
.pagination-link.active{
  background: linear-gradient(135deg, rgba(124,58,237,.55), rgba(34,211,238,.18));
  border-color: rgba(255,255,255,.18);
}

/* ======================================================
   EMPTY STATE
====================================================== */
.empty-state{
  text-align:center;
  padding: 34px 16px;
  color: var(--muted);
}
.empty-state i{
  display:inline-grid;
  place-items:center;
  width: 56px;
  height: 56px;
  border-radius: 18px;
  margin-bottom: 10px;
  border: 1px solid rgba(255,255,255,.12);
  background: rgba(255,255,255,.05);
}

/* ======================================================
   MODAL (goalModalOverlay uses .active)
====================================================== */
.modal-overlay{
  position: fixed;
  inset: 0;
  display: none;
  align-items: center;
  justify-content: center;
  background: rgba(2,6,23,.65);
  z-index: 3000;
  padding: 18px;
}
.modal-overlay.active{ display:flex; }

.modal{
  width: min(650px, 100%);
  border-radius: 18px;
  border: 1px solid rgba(255,255,255,.14);
  background:
    radial-gradient(140% 220% at 10% 0%, rgba(255,255,255,.10), transparent 60%),
    linear-gradient(180deg, rgba(10,14,35,.92), rgba(10,14,35,.75));
  box-shadow: 0 30px 80px rgba(0,0,0,.55);
  overflow: hidden;
}
.modal-header{
  display:flex;
  align-items:center;
  justify-content: space-between;
  padding: 14px 16px;
  border-bottom: 1px solid rgba(255,255,255,.10);
  background: rgba(255,255,255,.03);
}
.modal-header h3{
  margin:0;
  font-size: 16px;
  font-weight: 950;
}
.modal-close{
  width: 42px;
  height: 42px;
  border-radius: 14px;
  border: 1px solid rgba(255,255,255,.14);
  background: rgba(255,255,255,.06);
  color: var(--text);
  cursor:pointer;
}
.modal-body{
  padding: 16px;
  max-height: calc(100vh - 160px);
  overflow: auto;
}

/* form styles */
.form-group{ margin-bottom: 12px; }
.form-row{
  display:grid;
  grid-template-columns: 1fr 1fr;
  gap: 12px;
}
.form-group label{
  display:block;
  font-size: 12px;
  color: var(--muted);
  margin-bottom: 6px;
  font-weight: 900;
}
.form-group input,
.form-group select,
.form-group textarea{
  width: 100%;
  padding: 12px 12px;
  border-radius: 12px;
  border: 1px solid rgba(255,255,255,.12);
  background: rgba(255,255,255,.04);
  color: var(--text);
  outline: none;
}
.form-group textarea{
  min-height: 110px;
  resize: vertical;
}
.form-group input:focus,
.form-group select:focus,
.form-group textarea:focus{
  border-color: rgba(34,211,238,.30);
  box-shadow: 0 0 0 3px rgba(34,211,238,.16);
}
.form-help{
  margin-top: 6px;
  font-size: 12px;
  color: rgba(234,240,255,.55);
}

/* required mark (you used empty span .required) */
.required::after{
  content:" *";
  color: rgba(251,113,133,.95);
  font-weight: 950;
}

/* ======================================================
   RESPONSIVE
====================================================== */
@media (max-width: 1250px){
  .filter-grid{ grid-template-columns: 1fr 1fr 1fr 1fr; }
}
@media (max-width: 980px){
  .stats-grid{ grid-template-columns: repeat(2, minmax(0, 1fr)); }
  .filter-grid{ grid-template-columns: 1fr 1fr; }
}
@media (max-width: 860px){
  .dashboard-wrapper{ grid-template-columns: 1fr; }
  .mobile-toggle{ display:grid; }

  .sidebar{
    position: fixed;
    left: 0; top: 0;
    width: 320px;
    transform: translateX(-105%);
    transition: transform .25s ease;
    z-index: 1601;
  }
  .sidebar.active{ transform: translateX(0); }
  .sidebar-close{ display:grid; }
  .sidebar-overlay{ z-index: 1600; }

  .main-content{ padding: 22px 16px 36px; }
  .page-header{ flex-direction: column; align-items: flex-start; }
}
@media (max-width: 620px){
  .filter-grid{ grid-template-columns: 1fr; }
  .form-row{ grid-template-columns: 1fr; }
  .stats-grid{ grid-template-columns: 1fr; }
  .col-title{ min-width: unset; }
}

/* focus */
a:focus, button:focus{ outline: none; }
a:focus-visible, button:focus-visible{
  box-shadow: 0 0 0 3px rgba(34,211,238,.25), 0 0 0 1px rgba(255,255,255,.10);
  border-radius: 14px;
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
                <a href="admin.php" class="nav-link <?php echo $current === 'admin.php' ? 'active' : ''; ?>">
                    <i class="fas fa-tachometer-alt"></i> Dashboard
                </a>

                <a href="students.php" class="nav-link <?php echo $current === 'students.php' ? 'active' : ''; ?>">
                    <i class="fas fa-users"></i> Students
                    <?php if ($students_count > 0): ?><span class="badge"><?php echo $students_count; ?></span><?php endif; ?>
                </a>

                <a href="goals.php" class="nav-link <?php echo $current === 'goals.php' ? 'active' : ''; ?>">
                    <i class="fas fa-bullseye"></i> All Goals
                    <?php if ($goals_count > 0): ?><span class="badge"><?php echo $goals_count; ?></span><?php endif; ?>
                </a>

                <a href="assign_goals.php" class="nav-link <?php echo $current === 'assign_goals.php' ? 'active' : ''; ?>">
                    <i class="fas fa-tasks"></i> Assign Goals
                    <?php if ($assigned_count > 0): ?><span class="badge"><?php echo $assigned_count; ?></span><?php endif; ?>
                </a>

                <!-- ✅ Progress Requests nav link -->
                <a href="progress_requests.php" class="nav-link <?php echo $current === 'progress_requests.php' ? 'active' : ''; ?>">
                    <i class="fas fa-check-double"></i> Progress Requests
                    <?php if ($pending_requests > 0): ?><span class="badge"><?php echo $pending_requests; ?></span><?php endif; ?>
                </a>

                <a href="achievements.php" class="nav-link <?php echo $current === 'achievements.php' ? 'active' : ''; ?>">
                    <i class="fas fa-trophy"></i> Achievements
                    <?php if ($points_count > 0): ?><span class="badge"><?php echo $points_count; ?> pts</span><?php endif; ?>
                </a>

                <a href="reports.php" class="nav-link <?php echo $current === 'reports.php' ? 'active' : ''; ?>">
                    <i class="fas fa-chart-bar"></i> Reports
                </a>

                <a href="notifications.php" class="nav-link <?php echo $current === 'notifications.php' ? 'active' : ''; ?>">
                    <i class="fas fa-bell"></i> Notifications
                </a>

                <!-- IMPORTANT: Only ONE active class at a time (you had Categories active also in goals.php) -->
                <a href="categories.php" class="nav-link <?php echo $current === 'categories.php' ? 'active' : ''; ?>">
                    <i class="fas fa-tags"></i> Categories
                </a>

                <a href="settings.php" class="nav-link <?php echo $current === 'settings.php' ? 'active' : ''; ?>">
                    <i class="fas fa-cog"></i> Settings
                </a>
            </nav>

            <div class="sidebar-quick-stats">
                <div class="sidebar-stat">
                    <div class="sidebar-stat-icon"><i class="fas fa-bullseye"></i></div>
                    <div>
                        <div class="sidebar-stat-label">System Goals</div>
                        <div class="sidebar-stat-number"><?php echo $stats['system_goals']; ?></div>
                    </div>
                </div>
                <div class="sidebar-stat">
                    <div class="sidebar-stat-icon"><i class="fas fa-user"></i></div>
                    <div>
                        <div class="sidebar-stat-label">Personal Goals</div>
                        <div class="sidebar-stat-number"><?php echo $stats['personal_goals']; ?></div>
                    </div>
                </div>
                <div class="sidebar-stat">
                    <div class="sidebar-stat-icon"><i class="fas fa-check-circle"></i></div>
                    <div>
                        <div class="sidebar-stat-label">Completed</div>
                        <div class="sidebar-stat-number"><?php echo $stats['completed_goals']; ?></div>
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
            <button class="btn btn-outline" type="button" id="toggleFiltersBtn" style="margin-bottom:14px;">
                <i class="fas fa-sliders-h"></i> Filters
            </button>

            <div class="filters-section" id="filtersBox">

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
                                    <th>Title</th>
                                    <th width="150">Target</th>
                                    <th width="130">Assigned</th>
                                    <th width="170">Due</th>
                                    <th width="220">Actions</th>
                                </tr>
                            </thead>

                            <tbody>
                                <?php foreach ($system_goals as $goal):
                                    $priority_class = 'priority-' . ($goal['priority'] ?? 'medium');
                                    $days_left = $goal['days_left'] ?? null;
                                ?>
                                    <tr>
                                        <td>
                                            <input type="checkbox" class="goal-checkbox"
                                                data-goal-id="<?php echo $goal['id']; ?>" data-type="system">
                                        </td>

                                        <td class="col-title">
                                            <div style="font-weight:700; color: var(--gray-900);">
                                                <?php echo htmlspecialchars($goal['title']); ?>
                                                <span style="margin-left:8px;" class="type-badge type-system">System</span>
                                            </div>

                                            <div style="margin-top:6px; display:flex; gap:10px; flex-wrap:wrap; align-items:center;">
                                                <span class="priority-badge <?php echo 'priority-' . ($goal['priority'] ?? 'medium'); ?>">
                                                    <i class="fas fa-<?php echo $priorities[$goal['priority'] ?? 'medium']['icon'] ?? 'circle'; ?>"></i>
                                                    <?php echo ucfirst($goal['priority'] ?? 'medium'); ?>
                                                </span>

                                                <span class="status-badge status-<?php echo $goal['status']; ?>">
                                                    <?php echo ucfirst($goal['status']); ?>
                                                </span>

                                                <span style="font-size:12px; color: var(--gray-500);">
                                                    By <?php echo htmlspecialchars($goal['created_by_name'] ?? 'Unknown'); ?>
                                                </span>
                                            </div>
                                        </td>

                                        <td>
                                            <div style="font-weight:700; color: var(--gray-900);">
                                                <?php echo htmlspecialchars($goal['target_value'] . ' ' . ($goal['unit'] ?? '')); ?>
                                            </div>
                                            <div style="font-size:12px; color: var(--gray-500);">
                                                <?php echo htmlspecialchars($goal['category'] ?? 'Uncategorized'); ?>
                                            </div>
                                        </td>

                                        <td>
                                            <div style="font-weight:700;"><?php echo (int)$goal['assigned_count']; ?></div>
                                            <div style="font-size:12px; color: var(--gray-500);">
                                                <?php echo (int)$goal['completed_count']; ?> done
                                            </div>

                                            <?php if ($goal['assigned_count'] > 0): ?>
                                                <div class="progress-bar" style="margin-top:6px;">
                                                    <div class="progress-fill"
                                                        style="width: <?php echo min(100, ($goal['completed_count'] / max(1, $goal['assigned_count']) * 100)); ?>%"></div>
                                                </div>
                                            <?php endif; ?>
                                        </td>

                                        <td>
                                            <?php if ($goal['due_date']): ?>
                                                <div style="font-weight:600;"><?php echo date('M d, Y', strtotime($goal['due_date'])); ?></div>
                                                <?php if (($goal['days_left'] ?? null) !== null): ?>
                                                    <div class="countdown <?php echo $goal['days_left'] < 0 ? 'expired' : ($goal['days_left'] < 7 ? 'soon' : 'safe'); ?>" style="margin-top:6px;">
                                                        <i class="fas fa-clock"></i>
                                                        <?php echo $goal['days_left'] < 0 ? abs($goal['days_left']) . ' overdue' : $goal['days_left'] . ' left'; ?>
                                                    </div>
                                                <?php endif; ?>
                                            <?php else: ?>
                                                <span style="color: var(--gray-500);">No due date</span>
                                            <?php endif; ?>
                                        </td>

                                        <td>
                                            <div class="action-buttons">
                                                <?php if (($goal['started_count'] ?? 0) > 0): ?>
                                                    <button class="btn btn-sm btn-outline" disabled title="Locked: students already started">
                                                        <i class="fas fa-lock"></i> Locked
                                                    </button>
                                                <?php else: ?>
                                                    <button class="btn btn-sm btn-outline edit-goal"
                                                        data-goal='<?php echo json_encode($goal, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>'>
                                                        <i class="fas fa-edit"></i> Edit
                                                    </button>
                                                <?php endif; ?>

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
                                                    <button type="submit" class="btn btn-sm btn-danger"
                                                        onclick="return confirm('Delete this system goal and all assignments?')">
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
                                    <th width="240">Student</th>
                                    <th>Title</th>
                                    <th width="140">Progress</th>
                                    <th width="170">Due</th>
                                    <th width="120">Status</th>
                                    <th width="140">Actions</th>
                                </tr>
                            </thead>

                            <tbody>
                                <?php foreach ($personal_goals as $goal):
                                    $priority_class = 'priority-' . ($goal['priority'] ?? 'medium');
                                    $days_left = $goal['days_left'] ?? null;
                                ?>
                                    <tr>
                                        <td>
                                            <div style="display:flex; align-items:center; gap:10px;">
                                                <?php if ($goal['student_pic']): ?>
                                                    <img src="../<?php echo htmlspecialchars($goal['student_pic']); ?>"
                                                        style="width:32px;height:32px;border-radius:50%;object-fit:cover;">
                                                <?php else: ?>
                                                    <div style="width:32px;height:32px;border-radius:50%;background:var(--primary);color:#fff;display:flex;align-items:center;justify-content:center;font-weight:700;">
                                                        <?php echo strtoupper(substr($goal['student_name'], 0, 1)); ?>
                                                    </div>
                                                <?php endif; ?>
                                                <div style="min-width:0;">
                                                    <div style="font-weight:700; color:var(--gray-900); overflow:hidden; text-overflow:ellipsis; white-space:nowrap;">
                                                        <?php echo htmlspecialchars($goal['student_name']); ?>
                                                    </div>
                                                    <div style="font-size:12px; color:var(--gray-500); overflow:hidden; text-overflow:ellipsis; white-space:nowrap;">
                                                        <?php echo htmlspecialchars($goal['student_email']); ?>
                                                    </div>
                                                </div>
                                            </div>
                                        </td>

                                        <td class="col-title">
                                            <div style="font-weight:700; color:var(--gray-900);">
                                                <?php echo htmlspecialchars($goal['title']); ?>
                                                <span style="margin-left:8px;" class="type-badge type-personal">Personal</span>
                                            </div>
                                            <div style="margin-top:6px; display:flex; gap:10px; flex-wrap:wrap;">
                                                <span class="priority-badge <?php echo 'priority-' . ($goal['priority'] ?? 'medium'); ?>">
                                                    <?php echo ucfirst($goal['priority'] ?? 'medium'); ?>
                                                </span>
                                                <span style="font-size:12px; color:var(--gray-500);">
                                                    <?php echo htmlspecialchars($goal['category'] ?? 'Uncategorized'); ?>
                                                </span>
                                            </div>
                                        </td>

                                        <td>
                                            <div style="font-weight:800;">
                                                <?php echo (int)min(100, $goal['progress_percent'] ?? 0); ?>%
                                            </div>
                                            <div class="progress-bar" style="margin-top:6px;">
                                                <div class="progress-fill" style="width: <?php echo (int)min(100, $goal['progress_percent'] ?? 0); ?>%"></div>
                                            </div>
                                            <div style="font-size:12px; color:var(--gray-500); margin-top:6px;">
                                                <?php echo (int)$goal['progress_count']; ?> updates
                                            </div>
                                        </td>

                                        <td>
                                            <?php if ($goal['due_date']): ?>
                                                <div style="font-weight:600;"><?php echo date('M d, Y', strtotime($goal['due_date'])); ?></div>
                                                <?php if (($goal['days_left'] ?? null) !== null): ?>
                                                    <div class="countdown <?php echo $goal['days_left'] < 0 ? 'expired' : ($goal['days_left'] < 7 ? 'soon' : 'safe'); ?>" style="margin-top:6px;">
                                                        <i class="fas fa-clock"></i>
                                                        <?php echo $goal['days_left'] < 0 ? abs($goal['days_left']) . ' overdue' : $goal['days_left'] . ' left'; ?>
                                                    </div>
                                                <?php endif; ?>
                                            <?php else: ?>
                                                <span style="color:var(--gray-500);">No due date</span>
                                            <?php endif; ?>
                                        </td>

                                        <td>
                                            <span class="status-badge status-<?php echo $goal['status']; ?>">
                                                <?php echo ucfirst($goal['status']); ?>
                                            </span>
                                        </td>

                                        <td>
                                            <form method="POST" style="display:inline;">
                                                <input type="hidden" name="goal_id" value="<?php echo $goal['id']; ?>">
                                                <input type="hidden" name="action" value="delete_personal_goal">
                                                <button type="submit" class="btn btn-sm btn-danger"
                                                    onclick="return confirm('Delete this personal goal?')">
                                                    <i class="fas fa-trash"></i>
                                                </button>
                                            </form>
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
                <div id="goalLockWarning" style="display:none; 
    background:#fee2e2; 
    color:#991b1b; 
    padding:12px; 
    border-radius:8px; 
    margin-bottom:16px;
    font-weight:600;">
                    <i class="fas fa-lock"></i>
                    This goal is locked because students have already started it.
                    You can only change the status (activate/deactivate).
                </div>

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
                            <label><i class="fas fa-star"></i> Reward Points <span class="required"></span></label>
                            <input type="number" name="reward_points" id="reward_points" min="1" value="10" required>
                            <div class="form-help">Points student earns after completing this system goal</div>
                        </div>

                        <div class="form-group">
                            <label><i class="fas fa-user-check"></i> Requires Approval?</label>
                            <select name="requires_approval" id="requires_approval">
                                <option value="0">No (Auto Complete)</option>
                                <option value="1">Yes (Admin Approves)</option>
                            </select>
                            <div class="form-help">If yes, progress goes to Progress Requests first</div>
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

        sidebarToggle?.addEventListener('click', () => {
            sidebar.classList.add('active');
            overlay.classList.add('active');
        });
        sidebarClose?.addEventListener('click', () => {
            sidebar.classList.remove('active');
            overlay.classList.remove('active');
        });
        overlay?.addEventListener('click', () => {
            sidebar.classList.remove('active');
            overlay.classList.remove('active');
        });

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
            const lockWarning = document.getElementById('goalLockWarning');

            const fieldsToLock = [
                'title',
                'description',
                'category',
                'target_value',
                'unit',
                'due_date',
                'priority'
            ];

            if (goal) {
                title.textContent = 'Edit System Goal';
                document.getElementById('formAction').value = 'edit_goal';
                document.getElementById('goalId').value = goal.id;

                document.getElementById('title').value = goal.title;
                document.getElementById('description').value = goal.description || '';
                document.getElementById('category').value = goal.category || '';
                document.getElementById('reward_points').value = goal.reward_points || 10;
                document.getElementById('requires_approval').value = goal.requires_approval || 0;

                document.getElementById('due_date').value = goal.due_date || '';
                document.getElementById('priority').value = goal.priority || 'medium';
                document.getElementById('status').value = goal.status || 'active';

                // 🔒 LOCK LOGIC
                if (goal.started_count && parseInt(goal.started_count) > 0) {
                    lockWarning.style.display = 'block';

                    fieldsToLock.forEach(id => {
                        const el = document.getElementById(id);
                        if (el) el.disabled = true;
                    });

                } else {
                    lockWarning.style.display = 'none';

                    fieldsToLock.forEach(id => {
                        const el = document.getElementById(id);
                        if (el) el.disabled = false;
                    });
                }

            } else {
                title.textContent = 'Add System Goal';
                document.getElementById('formAction').value = 'add_goal';
                document.getElementById('goalId').value = '';
                form.reset();

                lockWarning.style.display = 'none';

                fieldsToLock.forEach(id => {
                    const el = document.getElementById(id);
                    if (el) el.disabled = false;
                });

                document.getElementById('priority').value = 'medium';
                document.getElementById('status').value = 'active';
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

        const toggleBtn = document.getElementById('toggleFiltersBtn');
        const filtersBox = document.getElementById('filtersBox');

        if (toggleBtn && filtersBox) {
            const saved = localStorage.getItem('goalsFiltersOpen');
            if (saved === '0') filtersBox.style.display = 'none';

            toggleBtn.addEventListener('click', () => {
                const hidden = filtersBox.style.display === 'none';
                filtersBox.style.display = hidden ? 'block' : 'none';
                localStorage.setItem('goalsFiltersOpen', hidden ? '1' : '0');
            });
        }
    </script>
</body>

</html>