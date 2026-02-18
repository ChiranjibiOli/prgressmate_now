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

$sidebar_stats = [
    'students' => 0,
    'goals'    => 0,
    'points'   => 0
];

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

                    // ✅ validate email BEFORE hashing and insert
                    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                        $error = 'Invalid email format.';
                    } else {

                        $hashed_password = password_hash($password, PASSWORD_ARGON2ID);

                        // Insert new student
                        $stmt = $pdo->prepare("
            INSERT INTO users (name, email, password, student_id, department, semester, role, status, created_at) 
            VALUES (?, ?, ?, ?, ?, ?, 'student', 'active', NOW())
        ");

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
// === BULK ACTIONS ===
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['bulk_action'])) {
    if (!hash_equals($csrf_token, $_POST['csrf_token'] ?? '')) {
        $error = 'Invalid CSRF token.';
    } else {
        $bulk_action = $_POST['bulk_action'] ?? '';
        $student_ids = $_POST['student_ids'] ?? [];

        if (empty($student_ids)) {
            $error = 'No students selected.';
        } elseif (!in_array($bulk_action, ['activate', 'deactivate', 'delete'], true)) {
            $error = 'Invalid bulk action.';
        } else {
            try {
                $placeholders = implode(',', array_fill(0, count($student_ids), '?'));

                if ($bulk_action === 'delete') {
                    $stmt = $pdo->prepare("
                        UPDATE users
                        SET deleted_at = NOW(), status='inactive', updated_at = NOW()
                        WHERE id IN ($placeholders) AND role='student' AND deleted_at IS NULL
                    ");
                    $ok = $stmt->execute($student_ids);
                } else {
                    $new_status = $bulk_action === 'activate' ? 'active' : 'inactive';
                    $stmt = $pdo->prepare("
                        UPDATE users
                        SET status = ?, updated_at = NOW()
                        WHERE id IN ($placeholders) AND role='student' AND deleted_at IS NULL
                    ");
                    $ok = $stmt->execute(array_merge([$new_status], $student_ids));
                }

                if ($ok) {
                    $_SESSION['success'] = 'Bulk action completed successfully!';
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

// ✅ Stats (All Time + Current)
$stats = [
    // All students ever created (includes soft-deleted)
    'total_students' => (int)$pdo->query("
        SELECT COUNT(*) 
        FROM users 
        WHERE role='student'
    ")->fetchColumn(),

    // Current students (not deleted)
    'current_students' => (int)$pdo->query("
        SELECT COUNT(*) 
        FROM users 
        WHERE role='student' AND deleted_at IS NULL
    ")->fetchColumn(),

    // Active students (only current)
    'active_students' => (int)$pdo->query("
    SELECT COUNT(*)
    FROM users
    WHERE role='student'
      AND TRIM(LOWER(status))='active'
")->fetchColumn(),


    // Inactive students (only current)
    'inactive_students' => (int)$pdo->query("
        SELECT COUNT(*) 
        FROM users 
        WHERE role='student' 
          AND deleted_at IS NULL
          AND TRIM(LOWER(status))='inactive'
    ")->fetchColumn(),

    // Deleted students count
    'deleted_students' => (int)$pdo->query("
        SELECT COUNT(*) 
        FROM users 
        WHERE role='student' AND deleted_at IS NOT NULL
    ")->fetchColumn(),

    'total_goals' => (int)$pdo->query("
        SELECT COUNT(*) 
        FROM student_goals 
        WHERE deleted_at IS NULL
    ")->fetchColumn(),

    'total_points' => (int)$pdo->query("
        SELECT COALESCE(SUM(points),0) 
        FROM users 
        WHERE role='student' AND deleted_at IS NULL
    ")->fetchColumn(),


];


$sidebar_stats = [
    'students' => (int)($stats['current_students'] ?? 0),
    'goals'    => (int)($stats['total_goals'] ?? 0),
    'points'   => (int)($stats['total_points'] ?? 0),
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

$current = basename($_SERVER['PHP_SELF']);

// Sidebar stats (optional badges)
$students_count = (int)($stats['current_students'] ?? 0);
$goals_count = (int)($stats['total_goals'] ?? 0);
$points_count = (int)($stats['total_points'] ?? 0);
$assigned_count = 0;


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
    <title>Manage Students - ProgressMate</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>


:root{
  --bg0:#070A18;
  --bg1:#0B1030;

  --text:#EAF0FF;
  --muted: rgba(234,240,255,.68);
  --muted2: rgba(234,240,255,.52);

  --primary:#7C3AED;     /* purple */
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

  --border: rgba(255,255,255,.10);
  --border2: rgba(255,255,255,.08);

  --card: rgba(255,255,255,.05);
  --card2: rgba(255,255,255,.035);

  --r12: 12px;
  --r14: 14px;
  --r16: 16px;
  --r20: 20px;

  --shadow: 0 18px 45px rgba(0,0,0,.35);
  --shadow2: 0 10px 30px rgba(0,0,0,.22);
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

  overflow: hidden;              /* sidebar itself NOT scrolling */
  display: flex;
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

/* close button (mobile) */
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

/* ONLY nav scrolls */
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
.sidebar-stat-info{ display:flex; flex-direction:column; line-height:1.1; }
.sidebar-stat-label{ font-size: 12px; color: var(--muted); }
.sidebar-stat-number{ font-size: 18px; font-weight: 950; }

/* footer */
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
.main-content{ padding: 26px 26px 40px; }

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
.btn-outline{
  background: rgba(255,255,255,.03);
}
.btn-outline:hover{
  box-shadow: 0 0 0 1px rgba(255,255,255,.08), 0 14px 38px rgba(34,211,238,.16);
}
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
.alert-success{
  border-color: rgba(52,211,153,.25);
  background: rgba(52,211,153,.10);
}
.alert-error{
  border-color: rgba(251,113,133,.25);
  background: rgba(251,113,133,.10);
}

/* ======================================================
   STATS GRID (5 cards)
====================================================== */
.stats-grid{
  margin-top: 18px;
  display:grid;
  grid-template-columns: repeat(5, minmax(0, 1fr));
  gap: 14px;
}
.stat-card{
  border-radius: var(--r20);
  border: 1px solid rgba(255,255,255,.12);
  background:
    radial-gradient(120% 180% at 10% 0%, rgba(255,255,255,.12), transparent 60%),
    linear-gradient(180deg, rgba(255,255,255,.05), rgba(255,255,255,.03));
  box-shadow: var(--shadow2);
  overflow:hidden;
  transition: transform .18s ease, box-shadow .18s ease;
}
.stat-card:hover{
  transform: translateY(-2px);
  box-shadow: 0 0 0 1px rgba(255,255,255,.08), 0 18px 45px rgba(124,58,237,.18);
}
.stat-content{
  display:flex;
  align-items:center;
  gap: 14px;
  padding: 16px 16px;
}
.stat-icon{
  width: 44px;
  height: 44px;
  border-radius: 16px;
  display:grid;
  place-items:center;
  border: 1px solid rgba(255,255,255,.16);
  background:
    radial-gradient(120% 180% at 20% 15%, rgba(255,255,255,.20), transparent 55%),
    linear-gradient(135deg, rgba(34,211,238,.40), rgba(124,58,237,.40));
  box-shadow: 0 16px 30px rgba(0,0,0,.22);
}
.stat-number{ font-size: 24px; font-weight: 950; line-height: 1.1; }
.stat-label{ margin-top: 2px; font-size: 13px; color: var(--muted); }

/* ======================================================
   FILTERS SECTION
====================================================== */
.filters-section{
  margin-top: 16px;
  border-radius: var(--r20);
  border: 1px solid rgba(255,255,255,.10);
  background: rgba(255,255,255,.04);
  box-shadow: var(--shadow2);
  padding: 14px 14px;
}
.filter-row{
  display:grid;
  grid-template-columns: 1.3fr .8fr .9fr .7fr .7fr .7fr;
  gap: 12px;
  align-items:end;
}
.filter-group label{
  display:block;
  font-size: 12px;
  color: var(--muted);
  margin-bottom: 6px;
  font-weight: 800;
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
   ACTIONS BAR (bulk)
====================================================== */
.actions-bar{
  margin-top: 14px;
  padding: 12px 14px;
  border-radius: var(--r16);
  border: 1px solid rgba(255,255,255,.10);
  background: rgba(255,255,255,.04);
  display:flex;
  align-items:center;
  justify-content: space-between;
  gap: 12px;
}
.bulk-actions{
  display:flex;
  align-items:center;
  flex-wrap: wrap;
  gap: 10px;
}
.actions-bar select{
  padding: 10px 12px;
  border-radius: 12px;
  border: 1px solid rgba(255,255,255,.12);
  background: rgba(10,14,35,.45);
  color: var(--text);
}

/* ======================================================
   TABLE
====================================================== */
.table-container{
  margin-top: 12px;
  border-radius: var(--r20);
  border: 1px solid rgba(255,255,255,.10);
  background: rgba(255,255,255,.035);
  box-shadow: var(--shadow);
  overflow: hidden;
}

table{
  width: 100%;
  border-collapse: collapse;
}
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
tbody tr:hover{
  background: rgba(255,255,255,.03);
}
tbody tr:last-child td{ border-bottom: none; }

input[type="checkbox"]{
  width: 16px;
  height: 16px;
  accent-color: var(--cyan);
}

/* avatars (table) */
.student-avatar{
  width: 40px;
  height: 40px;
  border-radius: 14px;
  object-fit: cover;
  border: 1px solid rgba(255,255,255,.14);
  background:
    radial-gradient(120% 160% at 20% 10%, rgba(255,255,255,.14), transparent 55%),
    linear-gradient(135deg, rgba(124,58,237,.45), rgba(34,211,238,.18));
  display:grid;
  place-items:center;
  font-weight: 950;
}
.student-avatar.default{
  background: linear-gradient(135deg, rgba(34,211,238,.45), rgba(124,58,237,.50));
}

/* progress */
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

/* status badge */
.status-badge{
  display:inline-flex;
  align-items:center;
  justify-content:center;
  padding: 7px 10px;
  border-radius: 999px;
  font-size: 12px;
  font-weight: 950;
  border: 1px solid rgba(255,255,255,.12);
  background: rgba(255,255,255,.04);
}
.status-active{
  color: rgba(52,211,153,1);
  border-color: rgba(52,211,153,.25);
  background: rgba(52,211,153,.10);
}
.status-inactive{
  color: rgba(251,191,36,1);
  border-color: rgba(251,191,36,.25);
  background: rgba(251,191,36,.10);
}

/* action buttons */
.action-buttons{
  display:flex;
  flex-wrap: wrap;
  gap: 8px;
}

/* empty state */
.empty-state{
  text-align:center;
  padding: 26px 14px;
  color: var(--muted);
}
.empty-state i{
  display:inline-grid;
  place-items:center;
  width: 52px;
  height: 52px;
  border-radius: 18px;
  margin-bottom: 10px;
  border: 1px solid rgba(255,255,255,.12);
  background: rgba(255,255,255,.05);
}

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
.pagination a, .pagination span{
  padding: 10px 12px;
  border-radius: 12px;
  border: 1px solid rgba(255,255,255,.12);
  background: rgba(255,255,255,.04);
  color: var(--text);
  font-weight: 900;
}
.pagination a:hover{
  box-shadow: 0 0 0 1px rgba(255,255,255,.08), 0 12px 30px rgba(124,58,237,.16);
  transform: translateY(-1px);
}
.pagination span.active{
  background: linear-gradient(135deg, rgba(124,58,237,.55), rgba(34,211,238,.18));
  border-color: rgba(255,255,255,.18);
}

/* ======================================================
   MODALS
====================================================== */
.modal-overlay{
  position: fixed;
  inset: 0;
  display: none;              /* you toggle with JS */
  align-items: center;
  justify-content: center;
  background: rgba(2,6,23,.65);
  z-index: 3000;
  padding: 18px;
}
.modal{
  width: min(560px, 100%);
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
  display:flex;
  align-items:center;
  gap:10px;
}
.modal-close{
  width: 40px;
  height: 40px;
  border-radius: 14px;
  border: 1px solid rgba(255,255,255,.14);
  background: rgba(255,255,255,.06);
  color: var(--text);
  font-size: 22px;
  cursor:pointer;
}
.modal-body{ padding: 16px; }
.modal-actions{
  display:flex;
  justify-content:flex-end;
  gap: 10px;
  margin-top: 14px;
}

/* forms */
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
.form-group select{
  width: 100%;
  padding: 12px 12px;
  border-radius: 12px;
  border: 1px solid rgba(255,255,255,.12);
  background: rgba(255,255,255,.04);
  color: var(--text);
  outline: none;
}
.form-group input:focus,
.form-group select:focus{
  border-color: rgba(34,211,238,.30);
  box-shadow: 0 0 0 3px rgba(34,211,238,.16);
}
.text-danger{ color: rgba(251,113,133,.95); }

/* ======================================================
   RESPONSIVE
====================================================== */
@media (max-width: 1200px){
  .stats-grid{ grid-template-columns: repeat(3, minmax(0, 1fr)); }
  .filter-row{ grid-template-columns: 1fr 1fr 1fr; }
}
@media (max-width: 980px){
  .stats-grid{ grid-template-columns: repeat(2, minmax(0, 1fr)); }
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
  .filter-row{ grid-template-columns: 1fr; }
  .form-row{ grid-template-columns: 1fr; }
  .stats-grid{ grid-template-columns: 1fr; }
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
                    <div class="sidebar-stat-icon"><i class="fas fa-users"></i></div>
                    <div class="sidebar-stat-info">
                        <div class="sidebar-stat-label">Students</div>
                        <div class="sidebar-stat-number"><?php echo (int)($sidebar_stats['students'] ?? 0); ?></div>
                    </div>
                </div>
                <div class="sidebar-stat">
                    <div class="sidebar-stat-icon"><i class="fas fa-bullseye"></i></div>
                    <div class="sidebar-stat-info">
                        <div class="sidebar-stat-label">Goals</div>
                        <div class="sidebar-stat-number"><?php echo (int)($sidebar_stats['goals'] ?? 0); ?></div>
                    </div>
                </div>
                <div class="sidebar-stat">
                    <div class="sidebar-stat-icon"><i class="fas fa-star"></i></div>
                    <div class="sidebar-stat-info">
                        <div class="sidebar-stat-label">Points</div>
                        <div class="sidebar-stat-number"><?php echo (int)($sidebar_stats['points'] ?? 0); ?></div>
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
                                <th>Email / Joined</th>
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
                                                <a class="btn btn-sm btn-primary" href="student_view.php?id=<?php echo (int)$student['id']; ?>">
                                                    <i class="fas fa-eye"></i> View
                                                </a>

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
        }, {
            threshold: 0.2
        });

        document.querySelectorAll('tbody tr').forEach(row => observer.observe(row));
    </script>
</body>

</html>