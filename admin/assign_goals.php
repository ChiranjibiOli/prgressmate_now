<?php
session_start();
require_once '../includes/db_connection.php';
require_once '../includes/functions.php';
checkAuth('admin');



/**
 * Award achievement when a student goal (assigned from admin_goals) is completed.
 * NOTE: This function is only useful if you CALL it when status becomes completed.
 * (Example: call it inside the code that updates a student_goal to 'completed'.)
 */
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

    $student_id     = (int)$row['student_id'];
    $achievement_id = (int)$row['achievement_id'];
    $points         = (int)$row['points'];

    $check = $pdo->prepare("SELECT 1 FROM user_achievements WHERE user_id=? AND achievement_id=? LIMIT 1");
    $check->execute([$student_id, $achievement_id]);
    if ($check->fetch()) return;

    $pdo->beginTransaction();
    try {
        $pdo->prepare("
            INSERT INTO user_achievements (user_id, achievement_id, earned_at, awarded_at)
            VALUES (?, ?, NOW(), NOW())
        ")->execute([$student_id, $achievement_id]);

        if ($points > 0) {
            $pdo->prepare("UPDATE users SET points = points + ? WHERE id = ?")
                ->execute([$points, $student_id]);
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


// ============================
// POST handling (FIXED)
// ============================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $success = $error = '';

    try {

        // ============================
        // 1) ASSIGN EXISTING GOAL
        // ============================
        if ($action === 'assign_system_goal') {

            if (empty($_POST['goal_id']) || empty($_POST['student_ids'])) {
                throw new Exception('Please select a goal and at least one student.');
            }

            $goal_id  = (int)$_POST['goal_id'];
            $due_date = !empty($_POST['due_date']) ? date('Y-m-d', strtotime($_POST['due_date'])) : null;
            $priority = $_POST['priority'] ?? 'medium';

            if ($due_date && strtotime($due_date) < strtotime('today')) {
                throw new Exception('Due date cannot be in the past.');
            }

            // Fetch goal details
            $goalQ = $pdo->prepare("
                SELECT title, description, category, target_value, unit
                FROM admin_goals
                WHERE id=? AND status='active'
            ");
            $goalQ->execute([$goal_id]);
            $g = $goalQ->fetch(PDO::FETCH_ASSOC);
            if (!$g) throw new Exception("Goal not found.");

            // Prepare INSERT once
            $insertStmt = $pdo->prepare("
                INSERT INTO student_goals
                (student_id, goal_id, title, description, category, target_value, unit,
                 due_date, priority, assigned_by, assigned_at,
                 is_admin_created, is_self_created, status, created_at)
                VALUES
                (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(),
                 1, 0, 'pending', NOW())
            ");

            // Duplicate prevention
            $exists = $pdo->prepare("
                SELECT 1 FROM student_goals
                WHERE student_id=? AND goal_id=? AND deleted_at IS NULL
                LIMIT 1
            ");

            $assigned_count = 0;

            foreach ($_POST['student_ids'] as $student_id) {
                $student_id = (int)$student_id;
                if ($student_id <= 0) continue;

                $exists->execute([$student_id, $goal_id]);
                if ($exists->fetch()) {
                    continue; // already assigned
                }

                $insertStmt->execute([
                    $student_id,
                    $goal_id,
                    $g['title'],
                    $g['description'],
                    $g['category'],
                    $g['target_value'],
                    $g['unit'],
                    $due_date,
                    $priority,
                    $_SESSION['user_id']
                ]);

                $assigned_count++;
            }

            if ($assigned_count === 0) {
                throw new Exception('No valid students selected (or goal already assigned).');
            }

            $success = "Goal assigned to {$assigned_count} student(s).";
        }

        // ============================
        // 2) CREATE NEW GOAL & ASSIGN
        // ============================
        elseif ($action === 'create_and_assign') {

            $required = ['title', 'target_value', 'unit', 'student_ids'];
            foreach ($required as $field) {
                if (empty($_POST[$field])) {
                    throw new Exception("Please fill all required fields.");
                }
            }

            $title        = trim($_POST['title']);
            $target_value = (float)$_POST['target_value'];
            $unit         = trim($_POST['unit']);
            $description  = trim($_POST['description'] ?? '');
            $category     = trim($_POST['category'] ?? '');
            $due_date     = !empty($_POST['due_date']) ? date('Y-m-d', strtotime($_POST['due_date'])) : null;
            $priority     = $_POST['priority'] ?? 'medium';
            $achievement_id = !empty($_POST['achievement_id']) ? (int)$_POST['achievement_id'] : null;

            if ($target_value <= 0) {
                throw new Exception('Target value must be greater than 0.');
            }

            if ($due_date && strtotime($due_date) < strtotime('today')) {
                throw new Exception('Due date cannot be in the past.');
            }

            $pdo->beginTransaction();

            // Create admin goal
            $stmt = $pdo->prepare("
                INSERT INTO admin_goals
                (title, description, category, target_value, unit, due_date, priority,
                 status, created_by, created_at, achievement_id)
                VALUES (?, ?, ?, ?, ?, ?, ?, 'active', ?, NOW(), ?)
            ");
            $stmt->execute([
                $title,
                $description,
                $category,
                $target_value,
                $unit,
                $due_date,
                $priority,
                $_SESSION['user_id'],
                $achievement_id
            ]);

            $new_goal_id = (int)$pdo->lastInsertId();

            // Assign to students (no duplicates needed for a new goal, but safe check can be added if you want)
            $assign_stmt = $pdo->prepare("
                INSERT INTO student_goals
                (student_id, goal_id, title, description, category, target_value, unit,
                 due_date, priority, assigned_by, assigned_at,
                 is_admin_created, is_self_created, status, created_at)
                VALUES
                (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(),
                 1, 0, 'pending', NOW())
            ");

            $assigned_count = 0;
            foreach ($_POST['student_ids'] as $student_id) {
                $student_id = (int)$student_id;
                if ($student_id <= 0) continue;

                $assign_stmt->execute([
                    $student_id,
                    $new_goal_id,
                    $title,
                    $description,
                    $category,
                    $target_value,
                    $unit,
                    $due_date,
                    $priority,
                    $_SESSION['user_id']
                ]);

                $assigned_count++;
            }

            if ($assigned_count === 0) {
                $pdo->rollBack();
                throw new Exception('No valid students selected.');
            }

            $pdo->commit();
            $success = "New goal created and assigned to {$assigned_count} student(s).";
        }
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $error = $e->getMessage();
    }

    $_SESSION['success'] = $success;
    $_SESSION['error'] = $error;
    header('Location: assign_goals.php');
    exit();
}


// ============================
// Flash messages
// ============================
$success = $_SESSION['success'] ?? '';
$error   = $_SESSION['error'] ?? '';
unset($_SESSION['success'], $_SESSION['error']);


// ============================
// Data fetching
// ============================
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
           (SELECT COUNT(*) FROM student_goals sg 
             WHERE sg.student_id = users.id
               AND sg.deleted_at IS NULL
               AND sg.is_admin_created = 1) as active_goals,
           (SELECT COUNT(*) FROM student_goals sg 
             WHERE sg.student_id = users.id
               AND sg.deleted_at IS NULL
               AND sg.is_admin_created = 1
               AND sg.status = 'completed') as completed_goals
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
 WHERE sg.deleted_at IS NULL
  AND sg.is_admin_created = 1
ORDER BY sg.created_at DESC
LIMIT 15

")->fetchAll(PDO::FETCH_ASSOC);

$notDeleted = "(deleted_at IS NULL OR deleted_at = '0000-00-00 00:00:00' OR deleted_at = '')";

$total_assignments = (int)($pdo->query("
    SELECT COUNT(*) 
    FROM student_goals 
    WHERE $notDeleted
")->fetchColumn() ?: 0);

$pending_assignments = (int)($pdo->query("
    SELECT COUNT(*) 
    FROM student_goals 
    WHERE status='pending' AND $notDeleted
")->fetchColumn() ?: 0);

$completed_assignments = (int)($pdo->query("
    SELECT COUNT(*) 
    FROM student_goals 
    WHERE status='completed' AND $notDeleted
")->fetchColumn() ?: 0);




$sidebar_stats = [
    'students' => $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'student' AND status = 'active'")->fetchColumn() ?: 0,
    'goals' => $pdo->query("SELECT COUNT(*) FROM admin_goals WHERE status = 'active'")->fetchColumn() ?: 0,
    'assigned' => $total_assignments,
    'points' => $pdo->query("SELECT COALESCE(SUM(points), 0) FROM users WHERE role = 'student'")->fetchColumn() ?: 0,
    'pending' => $pending_assignments,
    'completed' => $completed_assignments
];

$current = basename($_SERVER['PHP_SELF']);

$students_count = (int)($sidebar_stats['students'] ?? 0);
$goals_count    = (int)($sidebar_stats['goals'] ?? 0);
$assigned_count = (int)($sidebar_stats['assigned'] ?? 0);
$points_count   = (int)($sidebar_stats['points'] ?? 0);

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
    <title>Assign Goals - ProgressMate</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/select2/4.0.13/css/select2.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">

    <style>


:root{
  --bg0:#070A18;
  --bg1:#0B1030;

  --text:#EAF0FF;
  --muted: rgba(234,240,255,.68);

  --primary:#7C3AED;
  --primary-light: rgba(124,58,237,.14);

  --cyan:#22D3EE;
  --pink:#FB7185;

  --success:#34D399;
  --warning:#FBBF24;
  --danger:#FB7185;

  --gray-50: rgba(255,255,255,.02);
  --gray-100: rgba(255,255,255,.04);
  --gray-200: rgba(255,255,255,.06);
  --gray-300: rgba(255,255,255,.10);
  --gray-500: rgba(234,240,255,.60);
  --gray-600: rgba(234,240,255,.52);
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
.main-content{ padding: 26px 26px 54px; }

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
.header-content p{ margin: 0; color: var(--muted); font-size: 14px; max-width: 900px; }

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
.btn-primary{
  border-color: rgba(124,58,237,.35);
  background:
    radial-gradient(120% 180% at 20% 10%, rgba(255,255,255,.16), transparent 55%),
    linear-gradient(135deg, rgba(124,58,237,.62), rgba(34,211,238,.18));
}
.btn-success{ background: rgba(52,211,153,.16); border-color: rgba(52,211,153,.25); }

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

/* ======================================================
   STATS GRID
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
}
.stat-number{
  font-size: 26px;
  font-weight: 950;
}
.stat-label{
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
.tab-content{ display:none; margin-top: 14px; }
.tab-content.active{ display:block; }

/* ======================================================
   FORM CARD + FORM ELEMENTS
====================================================== */
.form-card{
  border-radius: var(--r20);
  border: 1px solid rgba(255,255,255,.10);
  background: rgba(255,255,255,.035);
  box-shadow: var(--shadow);
  padding: 16px;
}

.form-group{ margin-bottom: 14px; }
.form-row{
  display:grid;
  grid-template-columns: 1fr 1fr;
  gap: 12px;
}
label{
  display:block;
  font-size: 12px;
  color: var(--muted);
  margin-bottom: 8px;
  font-weight: 900;
}
input[type="text"],
input[type="number"],
input[type="date"],
select,
textarea{
  width: 100%;
  padding: 12px 12px;
  border-radius: 12px;
  border: 1px solid rgba(255,255,255,.12);
  background: rgba(10,14,35,.45);
  color: var(--text);
  outline: none;
}
textarea{ min-height: 120px; resize: vertical; }
input:focus, select:focus, textarea:focus{
  border-color: rgba(34,211,238,.30);
  box-shadow: 0 0 0 3px rgba(34,211,238,.16);
}

/* required mark (you used empty span.required) */
.required::after{
  content:" *";
  color: rgba(251,113,133,.95);
  font-weight: 950;
}

/* ======================================================
   GOAL PREVIEW + ACHIEVEMENT PREVIEW
====================================================== */
.goal-preview{
  margin-top: 12px;
  border-radius: 18px;
  border: 1px solid rgba(255,255,255,.12);
  background:
    radial-gradient(120% 180% at 10% 0%, rgba(255,255,255,.10), transparent 60%),
    linear-gradient(180deg, rgba(255,255,255,.05), rgba(255,255,255,.03));
  padding: 14px;
}
.achievement-preview{
  margin-top: 14px;
  display:flex;
  gap: 12px;
  align-items:center;
  padding: 12px;
  border-radius: 16px;
  border: 1px solid rgba(255,255,255,.12);
  background: rgba(255,255,255,.04);
}
.badge-large{
  width: 52px;
  height: 52px;
  border-radius: 18px;
  display:grid;
  place-items:center;
  color: #fff;
  border: 1px solid rgba(255,255,255,.18);
  box-shadow: 0 18px 40px rgba(0,0,0,.18);
}
.badge-large i{ font-size: 18px; }

/* ======================================================
   SELECT2 (student-select + goal-select)
====================================================== */
.select2-container{
  width: 100% !important;
  font-size: 14px;
}
.select2-container--default .select2-selection--single,
.select2-container--default .select2-selection--multiple{
  min-height: 46px;
  border-radius: 12px;
  border: 1px solid rgba(255,255,255,.12);
  background: rgba(10,14,35,.45);
  color: var(--text);
  padding: 6px 10px;
  box-shadow: none;
}
.select2-container--default .select2-selection--single .select2-selection__rendered{
  color: var(--text);
  line-height: 32px;
}
.select2-container--default .select2-selection--single .select2-selection__arrow{
  height: 44px;
}
.select2-container--default.select2-container--focus .select2-selection{
  border-color: rgba(34,211,238,.30);
  box-shadow: 0 0 0 3px rgba(34,211,238,.16);
}

/* multiple chips */
.select2-container--default .select2-selection--multiple .select2-selection__choice{
  background: rgba(255,255,255,.06);
  border: 1px solid rgba(255,255,255,.12);
  color: rgba(234,240,255,.92);
  border-radius: 999px;
  padding: 4px 10px;
  margin-top: 6px;
}
.select2-container--default .select2-selection--multiple .select2-selection__choice__remove{
  color: rgba(234,240,255,.75);
  margin-right: 6px;
}
.select2-container--default .select2-search--inline .select2-search__field{
  color: var(--text);
  margin-top: 10px;
}

/* dropdown */
.select2-dropdown{
  border-radius: 14px;
  border: 1px solid rgba(255,255,255,.12);
  background: rgba(10,14,35,.92);
  box-shadow: 0 22px 55px rgba(0,0,0,.55);
  overflow: hidden;
}
.select2-container--default .select2-results__option{
  padding: 10px 12px;
  color: rgba(234,240,255,.90);
}
.select2-container--default .select2-results__option--highlighted{
  background: rgba(124,58,237,.35);
  color: #fff;
}
.select2-search--dropdown .select2-search__field{
  border-radius: 12px;
  border: 1px solid rgba(255,255,255,.12);
  background: rgba(255,255,255,.04);
  color: var(--text);
  padding: 10px 12px;
}

/* compact multi mode: show "X students selected" */
.select2-container.compact-multi .select2-selection__rendered{
  position: relative;
  padding-right: 8px;
}
.select2-container.compact-multi .select2-selection__choice{
  display: none !important;
}
.select2-container.compact-multi .select2-selection__rendered::after{
  content: attr(data-count) " students selected";
  display: inline-block;
  margin-top: 10px;
  padding: 6px 10px;
  border-radius: 999px;
  border: 1px solid rgba(255,255,255,.12);
  background: rgba(255,255,255,.05);
  color: rgba(234,240,255,.92);
  font-weight: 900;
}

/* ======================================================
   RECENT ASSIGNMENTS TABLE (inside .form-card)
====================================================== */
.form-card table{
  width: 100%;
  border-collapse: collapse;
}
.form-card thead th{
  text-align:left;
  font-size: 12px;
  letter-spacing: .2px;
  text-transform: uppercase;
  color: rgba(234,240,255,.75);
  background: rgba(255,255,255,.04) !important;
  border-bottom: 1px solid rgba(255,255,255,.08);
  padding: 14px 12px;
}
.form-card tbody td{
  padding: 14px 12px;
  border-bottom: 1px solid rgba(255,255,255,.06);
}
.form-card tbody tr:hover{ background: rgba(255,255,255,.03); }
.form-card tbody tr:last-child td{ border-bottom: none; }

/* ======================================================
   RESPONSIVE
====================================================== */
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

  .main-content{ padding: 22px 16px 44px; }
  .page-header{ flex-direction: column; align-items: flex-start; }
}
@media (max-width: 620px){
  .stats-grid{ grid-template-columns: 1fr; }
  .form-row{ grid-template-columns: 1fr; }
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
                    <div class="profile-pic default"><?php echo htmlspecialchars(strtoupper(substr($_SESSION['name'] ?? 'A', 0, 1))); ?></div>
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

            

                <a href="settings.php" class="nav-link <?php echo $current === 'settings.php' ? 'active' : ''; ?>">
                    <i class="fas fa-cog"></i> Settings
                </a>
            </nav>

            <div class="sidebar-quick-stats">
                <div class="sidebar-stat">
                    <div class="sidebar-stat-icon"><i class="fas fa-users"></i></div>
                    <div>
                        <div class="sidebar-stat-label">Active Students</div>
                        <div class="sidebar-stat-number"><?php echo $sidebar_stats['students']; ?></div>
                    </div>
                </div>
                <div class="sidebar-stat">
                    <div class="sidebar-stat-icon"><i class="fas fa-bullseye"></i></div>
                    <div>
                        <div class="sidebar-stat-label">Active Goals</div>
                        <div class="sidebar-stat-number"><?php echo $sidebar_stats['goals']; ?></div>
                    </div>
                </div>
                <div class="sidebar-stat">
                    <div class="sidebar-stat-icon"><i class="fas fa-check-circle"></i></div>
                    <div>
                        <div class="sidebar-stat-label">Completed</div>
                        <div class="sidebar-stat-number"><?php echo $sidebar_stats['completed']; ?></div>
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

            <!-- Tab 1 -->
            <div class="tab-content active" id="tab1">
                <div class="form-card">
                    <form method="POST" id="assignForm">
                        <input type="hidden" name="action" value="assign_system_goal">

                        <div class="form-group">
                            <label>Select Goal <span class="required"></span></label>
                            <select name="goal_id" id="goal_id" required class="goal-select">
                                <option value="">-- Choose a goal --</option>
                                <?php foreach ($system_goals as $goal): ?>
                                    <option value="<?php echo (int)$goal['id']; ?>"
                                        data-target="<?php echo htmlspecialchars(($goal['target_value'] ?? '') . ' ' . ($goal['unit'] ?? '')); ?>"
                                        data-description="<?php echo htmlspecialchars($goal['description'] ?? ''); ?>"
                                        data-achievement-title="<?php echo htmlspecialchars($goal['achievement_title'] ?? ''); ?>"
                                        data-achievement-points="<?php echo htmlspecialchars($goal['achievement_points'] ?? ''); ?>"
                                        data-achievement-icon="<?php echo htmlspecialchars($goal['achievement_icon'] ?? 'trophy'); ?>"
                                        data-achievement-color="<?php echo htmlspecialchars($goal['achievement_color'] ?? '#f59e0b'); ?>"
                                        <?php echo $selected_goal && (int)$selected_goal['id'] === (int)$goal['id'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($goal['title']); ?>
                                        <?php if (!empty($goal['achievement_title'])): ?>
                                            (Awards: <?php echo htmlspecialchars($goal['achievement_title']); ?> +<?php echo (int)$goal['achievement_points']; ?> pts)
                                        <?php endif; ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div id="goalPreview" style="display: <?php echo $selected_goal ? 'block' : 'none'; ?>;">
                            <div class="goal-preview">
                                <strong style="font-size: 18px;"><?php echo $selected_goal ? htmlspecialchars($selected_goal['title']) : ''; ?></strong>
                                <?php if ($selected_goal && !empty($selected_goal['description'])): ?>
                                    <div style="margin: 12px 0; color: var(--gray-600);"><?php echo nl2br(htmlspecialchars($selected_goal['description'])); ?></div>
                                <?php endif; ?>
                                <div><strong>Target:</strong> <?php echo $selected_goal ? htmlspecialchars($selected_goal['target_value'] . ' ' . $selected_goal['unit']) : ''; ?></div>

                                <?php if ($selected_goal && !empty($selected_goal['achievement_title'])): ?>
                                    <div class="achievement-preview">
                                        <div class="badge-large" style="background: <?php echo htmlspecialchars($selected_goal['achievement_color']); ?>;">
                                            <i class="fas fa-<?php echo htmlspecialchars($selected_goal['achievement_icon']); ?>"></i>
                                        </div>
                                        <strong>Automatic Award on Completion:</strong><br>
                                        <?php echo htmlspecialchars($selected_goal['achievement_title']); ?><br>
                                        +<?php echo (int)$selected_goal['achievement_points']; ?> points
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>

                        <div class="form-group">
                            <label>Select Students <span class="required"></span></label>

                            <!-- ✅ Added buttons for Tab 1 (your JS uses these IDs) -->
                            <div style="display:flex; gap:10px; margin-bottom:12px; flex-wrap:wrap;">
                                <button type="button" class="btn btn-primary" id="selectAllStudentsTab1">
                                    <i class="fas fa-check"></i> Select All
                                </button>
                                <button type="button" class="btn" id="clearAllStudentsTab1" style="background: var(--gray-200);">
                                    <i class="fas fa-times"></i> Clear
                                </button>
                            </div>

                            <select name="student_ids[]" class="student-select" multiple required>
                                <?php foreach ($students as $student): ?>
                                    <option value="<?php echo (int)$student['id']; ?>">
                                        <?php echo htmlspecialchars($student['name']); ?> (<?php echo htmlspecialchars($student['email']); ?>)
                                        | Active: <?php echo (int)$student['active_goals']; ?>, Completed: <?php echo (int)$student['completed_goals']; ?>
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

            <!-- Tab 2 -->
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
                                    <option value="<?php echo (int)$ach['id']; ?>">
                                        <?php echo htmlspecialchars($ach['title']); ?> (+<?php echo (int)$ach['points']; ?> points)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="form-group">
                            <label>Select Students <span class="required"></span></label>

                            <!-- Select All / Clear (Tab 2) -->
                            <div style="display:flex; gap:10px; margin-bottom:12px; flex-wrap:wrap;">
                                <button type="button" class="btn btn-primary" id="selectAllStudentsTab2">
                                    <i class="fas fa-check"></i> Select All
                                </button>
                                <button type="button" class="btn" id="clearAllStudentsTab2" style="background: var(--gray-200);">
                                    <i class="fas fa-times"></i> Clear
                                </button>
                            </div>

                            <!-- ✅ IMPORTANT: options must contain ONLY text (no div/buttons inside option) -->
                            <select name="student_ids[]" class="student-select" multiple required>
                                <?php foreach ($students as $student): ?>
                                    <option value="<?php echo (int)$student['id']; ?>">
                                        <?php echo htmlspecialchars($student['name']); ?> (<?php echo htmlspecialchars($student['email']); ?>)
                                        | Active: <?php echo (int)$student['active_goals']; ?>, Completed: <?php echo (int)$student['completed_goals']; ?>
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
                                            <small><?php echo htmlspecialchars($assignment['target_value'] . ' ' . $assignment['unit']); ?></small>
                                        </td>
                                        <td style="padding: 16px;">
                                            <?php echo $assignment['due_date'] ? date('M d, Y', strtotime($assignment['due_date'])) : 'No due date'; ?>
                                        </td>
                                        <td style="padding: 16px;">
                                            <?php if (!empty($assignment['achievement_title'])): ?>
                                                <div style="display: inline-flex; align-items: center; gap: 8px; background: <?php echo htmlspecialchars($assignment['achievement_color'] ?? '#f59e0b'); ?>20; padding: 8px 12px; border-radius: 20px;">
                                                    <i class="fas fa-<?php echo htmlspecialchars($assignment['achievement_icon'] ?? 'trophy'); ?>" style="color: <?php echo htmlspecialchars($assignment['achievement_color'] ?? '#f59e0b'); ?>;"></i>
                                                    <span><?php echo htmlspecialchars($assignment['achievement_title']); ?> (+<?php echo (int)$assignment['achievement_points']; ?> pts)</span>
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

            // Init Select2
            $('.student-select, .goal-select').select2({
                width: '100%',
                closeOnSelect: false
            });

            // Helpers
            function selectAllStudents($select) {
                const allVals = $select.find('option').map(function() {
                    return this.value;
                }).get();
                $select.val(allVals).trigger('change');
            }

            function clearStudents($select) {
                $select.val(null).trigger('change');
            }

            // Compact mode: show "X students selected" when many selected
            function updateStudentSelectCompact($select) {
                const count = ($select.val() || []).length;
                const $container = $select.next('.select2-container');
                const $rendered = $container.find('.select2-selection__rendered');

                if (count > 5) {
                    $container.addClass('compact-multi');
                    $rendered.attr('data-count', count);
                } else {
                    $container.removeClass('compact-multi');
                    $rendered.removeAttr('data-count');
                }
            }

            // Tab 1 Select All / Clear
            $('#selectAllStudentsTab1').on('click', function() {
                const $s = $('#tab1 .student-select');
                selectAllStudents($s);
                updateStudentSelectCompact($s);
                $s.select2('close');
            });
            $('#clearAllStudentsTab1').on('click', function() {
                const $s = $('#tab1 .student-select');
                clearStudents($s);
                updateStudentSelectCompact($s);
            });

            // Tab 2 Select All / Clear
            $('#selectAllStudentsTab2').on('click', function() {
                const $s = $('#tab2 .student-select');
                selectAllStudents($s);
                updateStudentSelectCompact($s);
                $s.select2('close');
            });
            $('#clearAllStudentsTab2').on('click', function() {
                const $s = $('#tab2 .student-select');
                clearStudents($s);
                updateStudentSelectCompact($s);
            });

            // Run once on load + on every change
            $('.student-select').each(function() {
                updateStudentSelectCompact($(this));
            }).on('change', function() {
                updateStudentSelectCompact($(this));
            });

            // Goal preview change
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

            // Tabs switch
            $('.tab').on('click', function() {
                $('.tab').removeClass('active');
                $('.tab-content').removeClass('active');
                $(this).addClass('active');
                $('#tab' + $(this).data('tab')).addClass('active');
            });

            // Mobile sidebar toggle (optional - you had UI but not JS earlier)
            $('#sidebarToggle').on('click', function() {
                $('#sidebar').addClass('active');
                $('#sidebarOverlay').addClass('active');
            });
            $('#sidebarClose, #sidebarOverlay').on('click', function() {
                $('#sidebar').removeClass('active');
                $('#sidebarOverlay').removeClass('active');
            });

        });
    </script>
</body>

</html>