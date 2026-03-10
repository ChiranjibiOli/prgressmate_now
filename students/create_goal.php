<?php
// students/create_goal.php
session_start();
require_once '../includes/db_connection.php';
require_once '../includes/functions.php';
checkAuth('student');

$student_id = $_SESSION['user_id'];
$success = $_SESSION['success'] ?? '';
$error   = $_SESSION['error']   ?? '';
unset($_SESSION['success'], $_SESSION['error']);

// === Sidebar Stats ===
$stmt = $pdo->prepare("SELECT COUNT(*) FROM student_goals WHERE student_id=? AND deleted_at IS NULL");
$stmt->execute([$student_id]); $total_goals = $stmt->fetchColumn() ?: 0;
$stmt = $pdo->prepare("SELECT COUNT(*) FROM student_goals WHERE student_id=? AND status='completed' AND deleted_at IS NULL");
$stmt->execute([$student_id]); $completed_goals = $stmt->fetchColumn() ?: 0;
$stmt = $pdo->prepare("SELECT COALESCE(points,0) FROM users WHERE id=?");
$stmt->execute([$student_id]); $total_points = $stmt->fetchColumn() ?: 0;
$stmt = $pdo->prepare("SELECT COALESCE(current_streak,0) FROM users WHERE id=?");
$stmt->execute([$student_id]); $streak = $stmt->fetchColumn() ?: 0;
$stmt = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE user_id=? AND is_read=0 AND deleted_at IS NULL");
$stmt->execute([$student_id]); $unread = $stmt->fetchColumn() ?: 0;

// === Categories ===
$sys_stmt = $pdo->prepare("SELECT name FROM categories WHERE (is_global=1 OR created_by=?) AND deleted_at IS NULL ORDER BY name ASC");
$sys_stmt->execute([$student_id]);
$system_categories = $sys_stmt->fetchAll(PDO::FETCH_COLUMN);
$cat_stmt = $pdo->prepare("SELECT DISTINCT category FROM student_goals WHERE student_id=? AND category IS NOT NULL AND category!='' AND deleted_at IS NULL ORDER BY category");
$cat_stmt->execute([$student_id]);
$all_categories = array_unique(array_merge($cat_stmt->fetchAll(PDO::FETCH_COLUMN), $system_categories));

// === Form Defaults ===
$form_data = [
    'title'           => $_POST['title']           ?? '',
    'description'     => $_POST['description']     ?? '',
    'category'        => $_POST['category']        ?? '',
    'target_value'    => $_POST['target_value']    ?? '',
    'unit'            => $_POST['unit']            ?? '',
    'due_date'        => $_POST['due_date']        ?? '',
    'priority'        => $_POST['priority']        ?? 'medium',
    'estimated_hours' => $_POST['estimated_hours'] ?? '',
    'start_date'      => $_POST['start_date']      ?? date('Y-m-d'),
];

// === Handle Submission ===
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $title           = trim($_POST['title'] ?? '');
    $description     = trim($_POST['description'] ?? '');
    $category        = trim($_POST['category'] ?? '');
    $target_value    = floatval($_POST['target_value'] ?? 0);
    $unit            = trim($_POST['unit'] ?? '');
    $due_date        = $_POST['due_date'] ?: null;
    $priority        = in_array($_POST['priority'] ?? '', ['low','medium','high','critical']) ? $_POST['priority'] : 'medium';
    $estimated_hours = !empty($_POST['estimated_hours']) ? floatval($_POST['estimated_hours']) : null;
    $start_date      = $_POST['start_date'] ?: date('Y-m-d');

    $errors = [];
    if (empty($title) || strlen($title) < 3) $errors[] = "Title must be at least 3 characters.";
    if ($target_value <= 0)  $errors[] = "Target value must be greater than 0.";
    if (empty($unit))        $errors[] = "Unit is required (e.g., hours, pages, chapters).";
    if ($due_date && $due_date < date('Y-m-d')) $errors[] = "Due date cannot be in the past.";
    if ($start_date && $due_date && $start_date > $due_date) $errors[] = "Start date cannot be after due date.";
    if ($estimated_hours !== null && $estimated_hours <= 0) $errors[] = "Estimated hours must be greater than 0.";

    if (empty($errors)) {
        try {
            $pdo->beginTransaction();
            $stmt = $pdo->prepare("
INSERT INTO student_goals (student_id, title, description, category, unit, target_value, current_value, progress_percentage, priority, status, is_admin_created, is_self_created, created_at)
VALUES (?, ?, ?, ?, ?, ?, 0, 0, ?, 'pending', 0, 1, NOW())");
            $stmt->execute([$student_id, $title, $description, $category, $unit, $target_value, $priority]);
            $new_goal_id = $pdo->lastInsertId();

            $notif_stmt = $pdo->prepare("INSERT INTO notifications (user_id, title, message, type, related_id, related_type, created_at) VALUES (?, 'Goal Created', ?, 'goal', ?, 'student_goal', NOW())");
            $notif_stmt->execute([$student_id, "New goal created: $title", $new_goal_id]);
            $pdo->commit();

            $_SESSION['success'] = "Goal created successfully!";
            header("Location: goals.php"); exit;
        } catch (Exception $e) {
            $pdo->rollBack();
            $error = "Error creating goal: " . $e->getMessage();
        }
    } else {
        $error = implode(" ", $errors);
    }
}

$current = basename($_SERVER['PHP_SELF']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Create Goal — ProgressMate</title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<script>
(function(){
  var t = localStorage.getItem('pm_theme') || 'dark';
  if (t === 'light') document.documentElement.setAttribute('data-theme','light');
})();
</script>
<style>
:root{
  --bg0:#070A18;
  --bg1:#0B1030;

  --text:#EAF0FF;
  --muted: rgba(234,240,255,.65);
  --muted2: rgba(234,240,255,.50);

  --primary:#4F46E5;
  --primary-light: rgba(79,70,229,.14);

  --cyan:#22D3EE;
  --pink:#60A5FA;

  --success:#34D399;
  --warning:#FBBF24;
  --danger:#FB7185;
  --info:#22D3EE;

  --border: rgba(255,255,255,.10);
  --border2: rgba(255,255,255,.08);

  --shadow: 0 18px 45px rgba(0,0,0,.35);
  --shadow2: 0 10px 30px rgba(0,0,0,.22);

  --r12: 12px;
  --r14: 14px;
  --r16: 16px;
  --r20: 20px;

  --field: rgba(255,255,255,.05);
  --field2: rgba(255,255,255,.03);
}

*{ box-sizing:border-box; }
html,body{ height:100%; }

body{
  margin:0;
  color: var(--text);
  font-family: 'Inter', system-ui, -apple-system, Segoe UI, Roboto, Arial, sans-serif;
  background:
    radial-gradient(900px 520px at 18% 10%, rgba(79,70,229,.22), transparent 60%),
    radial-gradient(900px 520px at 88% 15%, rgba(34,211,238,.18), transparent 58%),
    radial-gradient(900px 520px at 70% 95%, rgba(96,165,250,.14), transparent 62%),
    linear-gradient(180deg, var(--bg0), var(--bg1));
  overflow-x:hidden;
}

a{ color: inherit; text-decoration:none; }
img{ max-width:100%; display:block; }
button,input,select,textarea{ font-family: inherit; }

/* ===== Mobile Toggle ===== */
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

/* (Optional) overlay – if you add <div class="sidebar-overlay" id="sidebarOverlay"></div> */
.sidebar-overlay{
  position: fixed;
  inset: 0;
  background: rgba(0,0,0,.45);
  opacity: 0;
  pointer-events: none;
  transition: opacity .2s ease;
  z-index: 1600;
}
.sidebar-overlay.active{
  opacity: 1;
  pointer-events: auto;
}

/* ===== Layout ===== */
.dashboard-wrapper{
  display: grid;
  grid-template-columns: 320px 1fr;
  min-height: 100vh;
}

/* ===== Sidebar ===== */
.sidebar{
  position: sticky;
  top: 0;
  height: 100vh;
  overflow: hidden;
  display:flex;
  flex-direction: column;
  padding: 18px 16px 16px;
  background:
    radial-gradient(700px 320px at 20% 0%, rgba(79,70,229,.18), transparent 60%),
    radial-gradient(520px 300px at 100% 20%, rgba(34,211,238,.14), transparent 60%),
    linear-gradient(180deg, rgba(10,14,35,.85), rgba(10,14,35,.62));
  border-right: 1px solid rgba(255,255,255,.10);
  backdrop-filter: blur(16px);
  box-shadow: 0 10px 50px rgba(0,0,0,.25);
}
.sidebar::before{
  content:"";
  position:absolute;
  inset:-2px;
  background: linear-gradient(120deg, rgba(79,70,229,.20), rgba(34,211,238,.14), rgba(96,165,250,.10));
  opacity:.22;
  filter: blur(26px);
  pointer-events:none;
  z-index:0;
}
.sidebar-header,.user-profile,.nav-menu,.sidebar-quick-stats,.sidebar-footer{ position:relative; z-index:2; }

.sidebar-header{
  display:flex;
  align-items:center;
  justify-content: space-between;
  padding: 10px 10px 12px;
}
.logo{
  display:flex;
  align-items:center;
  gap:10px;
  font-weight: 900;
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
    linear-gradient(135deg, rgba(79,70,229,.70), rgba(34,211,238,.35));
  border: 1px solid rgba(255,255,255,.18);
  box-shadow: 0 14px 30px rgba(79,70,229,.18);
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

/* Profile */
.user-profile{
  display:flex;
  gap: 12px;
  padding: 12px 12px;
  border-radius: var(--r16);
  border: 1px solid var(--border2);
  background:
    radial-gradient(140% 180% at 10% 0%, rgba(255,255,255,.10), transparent 60%),
    linear-gradient(180deg, rgba(255,255,255,.05), rgba(255,255,255,.03));
  box-shadow: 0 12px 26px rgba(0,0,0,.18);
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
    linear-gradient(135deg, rgba(34,211,238,.55), rgba(79,70,229,.55));
}
.user-info h4{ margin: 2px 0 2px; font-size: 15px; font-weight: 900; }
.user-info p{
  margin: 0;
  font-size: 12.5px;
  color: var(--muted);
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
  max-width: 210px;
}

/* Nav */
.nav-menu{
  flex: 1 1 auto;
  overflow-y: auto;
  overflow-x: hidden;
  padding: 12px 6px 8px;
  margin-top: 8px;
  display:flex;
  flex-direction: column;
  gap: 6px;
  scrollbar-width: thin;
  scrollbar-color: rgba(255,255,255,.18) transparent;
}
.nav-menu::-webkit-scrollbar{ width: 8px; }
.nav-menu::-webkit-scrollbar-thumb{ background: rgba(255,255,255,.16); border-radius: 99px; }

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
  font-size: 14.5px;
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
    linear-gradient(135deg, rgba(79,70,229,.55), rgba(34,211,238,.20));
  border-color: rgba(255,255,255,.18);
  box-shadow: 0 18px 40px rgba(79,70,229,.18);
}
.badge{
  margin-left:auto;
  font-size: 12px;
  font-weight: 900;
  padding: 4px 10px;
  border-radius: 999px;
  color: var(--text);
  background:
    radial-gradient(120% 180% at 20% 20%, rgba(255,255,255,.20), transparent 55%),
    linear-gradient(135deg, rgba(96,165,250,.70), rgba(79,70,229,.45));
  border: 1px solid rgba(255,255,255,.18);
}

/* Quick stats + logout */
.sidebar-quick-stats{
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
    linear-gradient(135deg, rgba(34,211,238,.35), rgba(79,70,229,.35));
  box-shadow: 0 14px 26px rgba(0,0,0,.18);
}
.sidebar-stat-label{ font-size: 12px; color: var(--muted); }
.sidebar-stat-number{ font-size: 18px; font-weight: 950; }

.sidebar-footer{ margin-top: 12px; }
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
    linear-gradient(135deg, rgba(96,165,250,.16), rgba(255,255,255,.03));
  box-shadow: 0 14px 26px rgba(0,0,0,.16);
}

/* ===== Main ===== */
.main-content{
  padding: 22px 22px 32px;
}

/* ===== Light Theme ===== */
[data-theme="light"] body {
  background:
    radial-gradient(900px 520px at 18% 10%, rgba(79,70,229,.10), transparent 60%),
    radial-gradient(900px 520px at 88% 15%, rgba(34,211,238,.07), transparent 58%),
    radial-gradient(900px 520px at 70% 95%, rgba(96,165,250,.07), transparent 62%),
    linear-gradient(180deg, #f0f4ff, #e8eeff);
  color: #1a1f3c;
}
[data-theme="light"] {
  --text: #1a1f3c;
  --muted: rgba(26,31,60,.58);
  --muted2: rgba(26,31,60,.38);
  --border: rgba(79,70,229,.14);
  --border2: rgba(79,70,229,.09);
  --shadow: 0 18px 45px rgba(79,70,229,.12);
  --shadow2: 0 10px 30px rgba(79,70,229,.08);
  --field: rgba(255,255,255,.85);
  --field2: rgba(255,255,255,.65);
  --bg0: #f0f4ff;
  --bg1: #e8eeff;
}
[data-theme="light"] .sidebar {
  background:
    radial-gradient(700px 320px at 20% 0%, rgba(79,70,229,.10), transparent 60%),
    radial-gradient(520px 300px at 100% 20%, rgba(34,211,238,.07), transparent 60%),
    rgba(240,244,255,.96);
  border-right: 1px solid rgba(79,70,229,.15);
}
[data-theme="light"] .nav-link { color: rgba(26,31,60,.75); }
[data-theme="light"] .nav-link i { background: rgba(79,70,229,.07); border-color: rgba(79,70,229,.14); }
[data-theme="light"] .nav-link:hover { background: rgba(79,70,229,.08); border-color: rgba(79,70,229,.18); color: #1a1f3c; }
[data-theme="light"] .nav-link.active { color: #1a1f3c; }
[data-theme="light"] .logo { color: #1a1f3c; }
[data-theme="light"] .user-info h4 { color: #1a1f3c; }
[data-theme="light"] .sidebar-stat-number { color: #1a1f3c; }
[data-theme="light"] .logout-btn { color: rgba(26,31,60,.80); }
[data-theme="light"] .sidebar-quick-stats { border-color: rgba(79,70,229,.10); background: rgba(79,70,229,.04); }
[data-theme="light"] .user-profile { background: rgba(255,255,255,.60); border-color: rgba(79,70,229,.12); }
[data-theme="light"] .mobile-toggle { background: rgba(240,244,255,.90); color: #1a1f3c; }


/* ===== Theme Toggle ===== */
.theme-btn {
  display: inline-flex; align-items: center; gap: 8px;
  padding: 8px 14px; border-radius: 999px;
  border: 1px solid var(--border); background: rgba(255,255,255,.06);
  color: var(--text); font-size: 13px; font-weight: 700; cursor: pointer;
  transition: background .18s, border-color .18s; font-family: inherit;
}
.theme-btn:hover { background: rgba(255,255,255,.10); border-color: rgba(255,255,255,.18); }
[data-theme="light"] .theme-btn { background: rgba(79,70,229,.07); border-color: rgba(79,70,229,.20); }
.tgl-track {
  width: 36px; height: 20px; border-radius: 999px;
  border: 1px solid rgba(255,255,255,.20); background: rgba(255,255,255,.12);
  position: relative; transition: background .2s; flex-shrink: 0;
}
.tgl-track.on { background: linear-gradient(135deg, rgba(79,70,229,.80), rgba(34,211,238,.50)); }
[data-theme="light"] .tgl-track { border-color: rgba(79,70,229,.25); background: rgba(79,70,229,.12); }
[data-theme="light"] .tgl-track.on { background: linear-gradient(135deg, #4f46e5, #22d3ee); }
.tgl-thumb {
  position: absolute; top: 2px; left: 2px;
  width: 14px; height: 14px; border-radius: 50%;
  background: #fff; transition: transform .2s; box-shadow: 0 2px 6px rgba(0,0,0,.25);
}
.tgl-track.on .tgl-thumb { transform: translateX(16px); }

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

  .main-content{ padding: 18px 14px 28px; }
  .page-header{ flex-direction: column; align-items: flex-start; }
}

/* === Page specific === */
body { overflow-x: hidden; line-height: 1.55; }
.btn {
  display:inline-flex; align-items:center; justify-content:center; gap:8px;
  padding:10px 16px; border-radius:14px; border:1px solid var(--border);
  background:rgba(255,255,255,.05); color:var(--text); font-weight:700; font-size:13px;
  transition:.18s; cursor:pointer; font-family:inherit;
}
.btn:hover { transform:translateY(-1px); background:rgba(255,255,255,.08); border-color:rgba(255,255,255,.18); box-shadow:0 10px 28px rgba(0,0,0,.22); }
.btn-primary {
  background: radial-gradient(120% 160% at 10% 20%, rgba(255,255,255,.14), transparent 55%),
              linear-gradient(135deg,rgba(79,70,229,.85),rgba(34,211,238,.25));
  border-color:rgba(255,255,255,.18); box-shadow:0 10px 30px rgba(79,70,229,.22);
}
[data-theme="light"] .btn { background:rgba(255,255,255,.80); color:#1a1f3c; border-color:rgba(79,70,229,.20); }
[data-theme="light"] .btn:hover { background:rgba(255,255,255,.95); }
[data-theme="light"] .btn-primary { background:linear-gradient(135deg,#4f46e5,#22d3ee); color:#fff; border-color:transparent; }

.page-header {
  display:flex; align-items:center; justify-content:space-between; gap:14px; flex-wrap:wrap;
  padding:18px 20px; border-radius:20px; border:1px solid var(--border);
  background: radial-gradient(120% 220% at 15% 10%, rgba(255,255,255,.08), transparent 55%),
              linear-gradient(180deg,rgba(255,255,255,.05),rgba(255,255,255,.02));
  box-shadow:var(--shadow2); margin-bottom:22px;
}
[data-theme="light"] .page-header { background:rgba(255,255,255,.65); border-color:rgba(79,70,229,.12); }
.page-header h1 { font-size:22px; font-weight:800; margin:0 0 4px; }
.page-header p { margin:0; color:var(--muted); font-size:13px; }
.header-right { display:flex; gap:10px; align-items:center; flex-wrap:wrap; }

.alert { display:flex; align-items:center; gap:12px; padding:13px 16px; border-radius:16px; border:1px solid var(--border); background:rgba(255,255,255,.04); margin-bottom:16px; font-size:14px; }
.alert i { width:32px; height:32px; display:grid; place-items:center; border-radius:10px; background:rgba(255,255,255,.06); border:1px solid rgba(255,255,255,.12); flex-shrink:0; }
.alert-success { border-color:rgba(52,211,153,.30); } .alert-success i { color:var(--success); }
.alert-error   { border-color:rgba(251,113,133,.30); } .alert-error i   { color:var(--danger); }
[data-theme="light"] .alert { background:rgba(255,255,255,.75); }

/* Form cards */
.form-card {
  background:rgba(255,255,255,.04); border:1px solid var(--border); border-radius:20px;
  overflow:hidden; margin-bottom:16px; box-shadow:var(--shadow2);
}
[data-theme="light"] .form-card { background:rgba(255,255,255,.70); border-color:rgba(79,70,229,.12); }
.fcard-head {
  display:flex; align-items:center; gap:12px;
  padding:16px 20px; border-bottom:1px solid var(--border2);
}
[data-theme="light"] .fcard-head { border-bottom-color:rgba(79,70,229,.09); }
.fcard-ico {
  width:38px; height:38px; border-radius:12px; display:grid; place-items:center; flex-shrink:0;
  background: radial-gradient(120% 160% at 20% 20%, rgba(255,255,255,.14), transparent 55%),
              linear-gradient(135deg,rgba(79,70,229,.55),rgba(34,211,238,.20));
  border:1px solid rgba(255,255,255,.16); font-size:15px;
}
.fcard-head h3 { font-size:15px; font-weight:800; margin:0 0 2px; }
.fcard-head p  { font-size:12px; color:var(--muted); margin:0; }
.fcard-body { padding:20px; }

.form-grid { display:grid; grid-template-columns:1fr 1fr; gap:16px; }
.form-full { grid-column:1/-1; }
.form-group { display:flex; flex-direction:column; gap:6px; }
.form-group label { font-size:11.5px; font-weight:700; color:var(--muted); letter-spacing:.3px; text-transform:uppercase; }
.req { color:var(--danger); }
.form-group input, .form-group select, .form-group textarea {
  padding:11px 14px; border-radius:12px; border:1px solid rgba(255,255,255,.12);
  background:var(--field); color:var(--text); font-size:14px; outline:none;
  transition:border-color .18s, box-shadow .18s; font-family:inherit;
}
.form-group input:focus, .form-group select:focus, .form-group textarea:focus {
  border-color:rgba(79,70,229,.55); box-shadow:0 0 0 3px rgba(79,70,229,.15);
}
[data-theme="light"] .form-group input,
[data-theme="light"] .form-group select,
[data-theme="light"] .form-group textarea { background:rgba(255,255,255,.90); border-color:rgba(79,70,229,.22); color:#1a1f3c; }
[data-theme="light"] .form-group input:focus,
[data-theme="light"] .form-group select:focus,
[data-theme="light"] .form-group textarea:focus { box-shadow:0 0 0 3px rgba(79,70,229,.12); border-color:rgba(79,70,229,.55); }
.form-group textarea { resize:vertical; min-height:110px; line-height:1.6; }
.form-hint { font-size:11.5px; color:var(--muted2); margin-top:2px; }
.field-err { font-size:11.5px; color:var(--danger); }

.cat-pills { display:flex; flex-wrap:wrap; gap:7px; margin-top:8px; }
.cat-pill {
  padding:5px 12px; border-radius:999px; font-size:12px; font-weight:600; cursor:pointer;
  border:1px solid rgba(79,70,229,.30); background:rgba(79,70,229,.10); color:var(--info);
  transition:.18s;
}
.cat-pill:hover { background:rgba(79,70,229,.22); transform:translateY(-1px); }
.cat-pill.on { background:linear-gradient(135deg,rgba(79,70,229,.65),rgba(34,211,238,.25)); border-color:rgba(255,255,255,.20); color:#fff; }
[data-theme="light"] .cat-pill { background:rgba(79,70,229,.08); color:#4f46e5; border-color:rgba(79,70,229,.22); }
[data-theme="light"] .cat-pill.on { background:linear-gradient(135deg,#4f46e5,#22d3ee); color:#fff; }

.priority-grid { display:grid; grid-template-columns:repeat(3,1fr); gap:10px; }
.prio-btn {
  padding:14px 8px; border-radius:14px; border:1px solid var(--border);
  background:rgba(255,255,255,.03); text-align:center; cursor:pointer;
  transition:.18s;
}
.prio-btn:hover { transform:translateY(-2px); border-color:rgba(255,255,255,.18); }
.prio-btn.on { border-color:rgba(79,70,229,.55); background:rgba(79,70,229,.14); box-shadow:0 8px 24px rgba(79,70,229,.20); }
.prio-btn.on.high { border-color:rgba(251,113,133,.55); background:rgba(251,113,133,.12); box-shadow:0 8px 24px rgba(251,113,133,.18); }
.prio-btn.on.low  { border-color:rgba(148,163,184,.45); background:rgba(148,163,184,.10); }
.prio-ico { font-size:20px; margin-bottom:6px; }
.prio-btn.low  .prio-ico { color:#94a3b8; }
.prio-btn.medium .prio-ico { color:var(--info); }
.prio-btn.high .prio-ico { color:var(--danger); }
.prio-lbl { font-size:12px; font-weight:700; color:var(--muted); }
[data-theme="light"] .prio-btn { background:rgba(255,255,255,.65); border-color:rgba(79,70,229,.14); }
[data-theme="light"] .prio-btn.on { background:rgba(79,70,229,.10); }

.fcard-actions {
  display:flex; justify-content:flex-end; gap:10px; flex-wrap:wrap;
  padding:14px 20px; border-top:1px solid var(--border2);
}
[data-theme="light"] .fcard-actions { border-top-color:rgba(79,70,229,.09); }

.sec-label { font-size:16px; font-weight:800; margin:24px 0 14px; display:flex; align-items:center; gap:10px; }
.sec-label i { color:var(--warning); }
.tips-grid { display:grid; grid-template-columns:repeat(auto-fit,minmax(190px,1fr)); gap:12px; margin-bottom:32px; }
.tip-card {
  padding:18px; border-radius:16px; border:1px solid var(--border2);
  background:rgba(255,255,255,.03); text-align:center; transition:.18s;
}
.tip-card:hover { transform:translateY(-3px); border-color:rgba(79,70,229,.22); box-shadow:0 12px 32px rgba(0,0,0,.18); }
.tip-card i { font-size:22px; color:var(--warning); margin-bottom:10px; display:block; }
.tip-card h4 { font-size:14px; font-weight:800; margin-bottom:6px; }
.tip-card p  { font-size:12.5px; color:var(--muted); line-height:1.55; }
[data-theme="light"] .tip-card { background:rgba(255,255,255,.65); border-color:rgba(79,70,229,.10); }

@media(max-width:600px){
  .form-grid { grid-template-columns:1fr; }
  .form-full { grid-column:1; }
  .fcard-actions { flex-direction:column; }
  .fcard-actions .btn { width:100%; justify-content:center; }
}
</style>
</head>
<body>
    <!-- MOBILE TOGGLE -->
    <button class="mobile-toggle" id="sidebarToggle"><i class="fas fa-bars"></i></button>

    <div class="dashboard-wrapper">
        <!-- SIDEBAR -->
        <aside class="sidebar" id="sidebar">
            <div class="sidebar-header">
                <div class="logo"><i class="fas fa-star"></i> <span>ProgressMate</span></div>
                <button class="sidebar-close" id="sidebarClose"><i class="fas fa-times"></i></button>
            </div>
            <div class="user-profile">
                <?php if (!empty($_SESSION['profile_picture'])): ?>
                    <img src="<?php echo '../' . htmlspecialchars($_SESSION['profile_picture']); ?>" alt="Profile" class="profile-pic">
                <?php else: ?>
                    <div class="profile-pic default"><?php echo strtoupper(substr($_SESSION['name'], 0, 1)); ?></div>
                <?php endif; ?>
                <div class="user-info">
                    <h4><?php echo htmlspecialchars($_SESSION['name']); ?></h4>
                    <p><?php echo htmlspecialchars($_SESSION['email']); ?></p>
                    <span style="font-size: 11px; background: #e0e7ff; color: #4f46e5; padding: 2px 8px; border-radius: 12px;">STUDENT</span>
                </div>
            </div>
            <nav class="nav-menu">
                <a href="dashboard.php" class="nav-link <?php echo $current === 'dashboard.php' ? 'active' : ''; ?>">
                    <i class="fas fa-tachometer-alt"></i> Dashboard
                </a>

                <a href="goals.php" class="nav-link <?php echo $current === 'goals.php' ? 'active' : ''; ?>">
                    <i class="fas fa-bullseye"></i> Goals
                </a>

                <a href="achievements.php" class="nav-link <?php echo $current === 'achievements.php' ? 'active' : ''; ?>">
                    <i class="fas fa-trophy"></i> Achievements
                </a>

                <a href="notifications.php" class="nav-link <?php echo $current === 'notifications.php' ? 'active' : ''; ?>">
                    <i class="fas fa-bell"></i> Notifications
                    <?php if ($unread > 0): ?><span class="badge"><?php echo $unread; ?></span><?php endif; ?>
                </a>

                <a href="profile.php" class="nav-link <?php echo $current === 'profile.php' ? 'active' : ''; ?>">
                    <i class="fas fa-user"></i> Profile
                </a>
            </nav>
            <div class="sidebar-quick-stats">
                <div class="sidebar-stat">
                    <div class="sidebar-stat-icon"><i class="fas fa-bullseye"></i></div>
                    <div class="sidebar-stat-info">
                        <div class="sidebar-stat-label">Goals</div>
                        <div class="sidebar-stat-number"><?php echo $completed_goals; ?>/<?php echo $total_goals; ?></div>
                    </div>
                </div>
                <div class="sidebar-stat">
                    <div class="sidebar-stat-icon"><i class="fas fa-star"></i></div>
                    <div class="sidebar-stat-info">
                        <div class="sidebar-stat-label">Points</div>
                        <div class="sidebar-stat-number"><?php echo $total_points; ?></div>
                    </div>
                </div>
                <div class="sidebar-stat">
                    <div class="sidebar-stat-icon"><i class="fas fa-fire"></i></div>
                    <div class="sidebar-stat-info">
                        <div class="sidebar-stat-label">Streak</div>
                        <div class="sidebar-stat-number"><?php echo $streak; ?> days</div>
                    </div>
                </div>
            </div>
            <div class="sidebar-footer">
                <a href="../logout.php" class="logout-btn"><i class="fas fa-sign-out-alt"></i> <span>Logout</span></a>
            </div>
        </aside>

        <main class="main-content">
            <header class="page-header">
                <div>
                    <h1>Create New Goal</h1>
                    <p>Define what you want to achieve and start tracking your progress</p>
                </div>
                <div class="header-right">
                    <button class="theme-btn" id="themeBtn">
                    <div class="tgl-track" id="themeTrack"><div class="tgl-thumb"></div></div>
                    <span id="themeLabel">Dark</span>
                </button>
                    <a href="goals.php" class="btn"><i class="fas fa-arrow-left"></i> Back to Goals</a>
                </div>
            </header>

            <?php if ($success): ?><div class="alert alert-success"><i class="fas fa-check-circle"></i><span><?php echo htmlspecialchars($success); ?></span></div><?php endif; ?>
            <?php if ($error): ?><div class="alert alert-error"><i class="fas fa-exclamation-circle"></i><span><?php echo htmlspecialchars($error); ?></span></div><?php endif; ?>

            <form method="POST" id="goalForm">

              <div class="form-card">
                <div class="fcard-head">
                  <div class="fcard-ico"><i class="fas fa-info-circle"></i></div>
                  <div><h3>Basic Information</h3><p>Give your goal a clear name and context</p></div>
                </div>
                <div class="fcard-body">
                  <div class="form-grid">
                    <div class="form-group form-full">
                      <label>Goal Title <span class="req">*</span></label>
                      <input type="text" id="title" name="title" placeholder="What do you want to achieve?" value="<?php echo htmlspecialchars($form_data['title']); ?>" required maxlength="200" autocomplete="off">
                      <span class="form-hint">Be specific — e.g. "Read 5 books this semester"</span>
                    </div>
                    <div class="form-group form-full">
                      <label>Description</label>
                      <textarea id="description" name="description" placeholder="Why is this goal important? What steps will you take..."><?php echo htmlspecialchars($form_data['description']); ?></textarea>
                      <span class="form-hint" id="charCount">0 / 1000 characters</span>
                    </div>
                    <div class="form-group form-full">
                      <label>Category</label>
                      <input type="text" id="category" name="category" placeholder="e.g. Health, Study, Career, Fitness…" value="<?php echo htmlspecialchars($form_data['category']); ?>" list="catList" autocomplete="off">
                      <datalist id="catList"><?php foreach($all_categories as $c): ?><option value="<?php echo htmlspecialchars($c); ?>"><?php endforeach; ?></datalist>
                      <?php if (!empty($all_categories)): ?>
                        <div class="cat-pills"><?php foreach(array_slice($all_categories,0,10) as $c): ?><span class="cat-pill" onclick="selectCat('<?php echo htmlspecialchars($c,ENT_QUOTES); ?>')"><?php echo htmlspecialchars($c); ?></span><?php endforeach; ?></div>
                      <?php endif; ?>
                    </div>
                  </div>
                </div>
              </div>

              <div class="form-card">
                <div class="fcard-head">
                  <div class="fcard-ico"><i class="fas fa-sliders-h"></i></div>
                  <div><h3>Target & Timeline</h3><p>Set measurable targets and a deadline</p></div>
                </div>
                <div class="fcard-body">
                  <div class="form-grid">
                    <div class="form-group">
                      <label>Target Value <span class="req">*</span></label>
                      <input type="number" id="target_value" name="target_value" min="0.01" step="0.01" placeholder="e.g. 100, 5.5, 30" value="<?php echo htmlspecialchars($form_data['target_value']); ?>" required>
                      <span class="form-hint">The number you want to reach</span>
                    </div>
                    <div class="form-group">
                      <label>Unit <span class="req">*</span></label>
                      <input type="text" id="unit" name="unit" placeholder="pages, km, hours, kg…" value="<?php echo htmlspecialchars($form_data['unit']); ?>" required autocomplete="off">
                      <span class="form-hint">How you will measure progress</span>
                    </div>
                    <div class="form-group">
                      <label>Start Date</label>
                      <input type="date" id="start_date" name="start_date" value="<?php echo htmlspecialchars($form_data['start_date']); ?>">
                    </div>
                    <div class="form-group">
                      <label>Due Date</label>
                      <input type="date" id="due_date" name="due_date" value="<?php echo htmlspecialchars($form_data['due_date']); ?>">
                    </div>
                    <div class="form-group">
                      <label>Estimated Hours</label>
                      <input type="number" id="estimated_hours" name="estimated_hours" min="0.5" step="0.5" placeholder="e.g. 20" value="<?php echo htmlspecialchars($form_data['estimated_hours']); ?>">
                    </div>
                  </div>
                </div>
              </div>

              <div class="form-card">
                <div class="fcard-head">
                  <div class="fcard-ico"><i class="fas fa-flag"></i></div>
                  <div><h3>Priority Level</h3><p>How important is this goal?</p></div>
                </div>
                <div class="fcard-body">
                  <input type="hidden" id="priority" name="priority" value="<?php echo htmlspecialchars($form_data['priority']); ?>">
                  <div class="priority-grid">
                    <div class="prio-btn low <?php echo $form_data['priority']==='low'?'on':''; ?>" data-v="low">
                      <div class="prio-ico"><i class="fas fa-arrow-down"></i></div><div class="prio-lbl">Low</div>
                    </div>
                    <div class="prio-btn medium <?php echo ($form_data['priority']==='medium'||empty($form_data['priority']))?'on':''; ?>" data-v="medium">
                      <div class="prio-ico"><i class="fas fa-equals"></i></div><div class="prio-lbl">Medium</div>
                    </div>
                    <div class="prio-btn high <?php echo $form_data['priority']==='high'?'on':''; ?>" data-v="high">
                      <div class="prio-ico"><i class="fas fa-arrow-up"></i></div><div class="prio-lbl">High</div>
                    </div>
                  </div>
                </div>
                <div class="fcard-actions">
                  <button type="button" class="btn" onclick="if(confirm('Clear all fields?')){document.getElementById('goalForm').reset();resetPrio();}"><i class="fas fa-redo"></i> Clear</button>
                  <button type="submit" class="btn btn-primary"><i class="fas fa-plus"></i> Create Goal</button>
                </div>
              </div>

            </form>

            <div class="sec-label"><i class="fas fa-lightbulb"></i> Tips for Effective Goals</div>
            <div class="tips-grid">
              <div class="tip-card"><i class="fas fa-bullseye"></i><h4>Be Specific</h4><p>"Exercise 30 min daily" beats "Exercise more".</p></div>
              <div class="tip-card"><i class="fas fa-ruler"></i><h4>Make it Measurable</h4><p>"Read 10 books" beats "Read more".</p></div>
              <div class="tip-card"><i class="fas fa-calendar-check"></i><h4>Set a Deadline</h4><p>Deadlines create focus and urgency.</p></div>
              <div class="tip-card"><i class="fas fa-chart-line"></i><h4>Track Regularly</h4><p>Update progress often to stay motivated.</p></div>
            </div>
        </main>
    </div>

<script>

        // Sidebar toggle
        document.getElementById('sidebarToggle')?.addEventListener('click', () => {
            document.getElementById('sidebar').classList.add('active');
        });
        document.getElementById('sidebarClose')?.addEventListener('click', () => {
            document.getElementById('sidebar').classList.remove('active');
        });
        document.addEventListener('click', function(e) {
            const sb = document.getElementById('sidebar');
            const tog = document.getElementById('sidebarToggle');
            if (window.innerWidth <= 860 && sb && sb.classList.contains('active')
                && !sb.contains(e.target) && !tog?.contains(e.target)) {
                sb.classList.remove('active');
            }
        });


        // Theme toggle
        (function(){
          var saved = localStorage.getItem('pm_theme') || 'dark';
          function apply(t) {
            if (t==='light') document.documentElement.setAttribute('data-theme','light');
            else document.documentElement.removeAttribute('data-theme');
            var track = document.getElementById('themeTrack');
            var label = document.getElementById('themeLabel');
            if (track) track.classList.toggle('on', t==='light');
            if (label) label.textContent = t==='light' ? 'Light' : 'Dark';
          }
          apply(saved);
          var btn = document.getElementById('themeBtn');
          if (btn) btn.addEventListener('click', function(){
            var cur = document.documentElement.getAttribute('data-theme')==='light' ? 'dark' : 'light';
            localStorage.setItem('pm_theme', cur);
            apply(cur);
          });
        })();


        // Priority
        document.querySelectorAll('.prio-btn').forEach(b => {
          b.addEventListener('click', () => {
            document.querySelectorAll('.prio-btn').forEach(x=>x.classList.remove('on'));
            b.classList.add('on');
            document.getElementById('priority').value = b.dataset.v;
          });
        });
        function resetPrio(){
          document.querySelectorAll('.prio-btn').forEach(x=>x.classList.remove('on'));
          document.querySelector('.prio-btn.medium').classList.add('on');
          document.getElementById('priority').value='medium';
        }

        // Category pills
        function selectCat(n){
          document.getElementById('category').value=n;
          document.querySelectorAll('.cat-pill').forEach(p=>p.classList.toggle('on',p.textContent.trim()===n));
        }
        document.getElementById('category')?.addEventListener('input',function(){
          const v=this.value.toLowerCase();
          document.querySelectorAll('.cat-pill').forEach(p=>p.style.display=p.textContent.toLowerCase().includes(v)||!v?'':'none');
        });

        // Char counter
        const _d=document.getElementById('description'),_cc=document.getElementById('charCount');
        function updCC(){const l=_d.value.length;_cc.textContent=l+'/1000 characters';_cc.style.color=l>1000?'var(--danger)':l>800?'var(--warning)':'';};
        _d?.addEventListener('input',updCC);updCC();

        // Dates
        const _td=new Date().toISOString().split('T')[0];
        document.getElementById('start_date').min=_td;
        document.getElementById('due_date').min=_td;
        if(!document.getElementById('start_date').value)document.getElementById('start_date').value=_td;

        // Validation
        document.getElementById('goalForm').addEventListener('submit',function(e){
          document.querySelectorAll('.field-err').forEach(el=>el.remove());
          let ok=true;
          const err=(id,msg)=>{
            const f=document.getElementById(id);
            f.style.borderColor='var(--danger)';
            const d=document.createElement('span');d.className='field-err';d.textContent=msg;
            f.parentNode.appendChild(d);
            f.addEventListener('input',()=>{f.style.borderColor='';d.remove();},{once:true});
            ok=false;
          };
          const t=document.getElementById('title').value.trim();
          if(!t||t.length<3)err('title','Please enter at least 3 characters.');
          if(!document.getElementById('target_value').value||parseFloat(document.getElementById('target_value').value)<=0)err('target_value','Must be greater than 0.');
          if(!document.getElementById('unit').value.trim())err('unit','Unit is required.');
          const sd=document.getElementById('start_date').value,dd=document.getElementById('due_date').value;
          if(dd&&dd<_td)err('due_date','Cannot be in the past.');
          if(sd&&dd&&sd>dd)err('start_date','Start date cannot be after due date.');
          if(!ok)e.preventDefault();
        });
        document.getElementById('title').focus();
</script>
</body>
</html>