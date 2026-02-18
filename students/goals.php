<?php
session_start();
require_once '../includes/db_connection.php';
require_once '../includes/functions.php';

require_once '../api/_helpers.php';
$csrf = csrfToken();

ini_set('display_errors', 0);
error_reporting(E_ALL);


// Check if user is logged in as student
checkAuth('student');

$student_id = $_SESSION['user_id'];

// Auto-mark overdue goals (past due date and not completed)
$pdo->prepare("
    UPDATE student_goals
    SET status = 'overdue'
    WHERE student_id = ?
      AND deleted_at IS NULL
      AND status != 'completed'
      AND due_date IS NOT NULL
      AND due_date < CURDATE()
")->execute([$student_id]);


// Initialize variables
$success = '';
$error = '';

$total_goals = getStat($pdo, "SELECT COUNT(*) FROM student_goals WHERE student_id=? AND deleted_at IS NULL", [$student_id]);

$completed_goals = getStat($pdo, "SELECT COUNT(*) FROM student_goals WHERE student_id=? AND status='completed' AND deleted_at IS NULL", [$student_id]);

$in_progress_goals = getStat($pdo, "SELECT COUNT(*) FROM student_goals WHERE student_id=? AND status='in_progress' AND deleted_at IS NULL", [$student_id]);

$total_points = getStat($pdo, "SELECT points FROM users WHERE id=? AND deleted_at IS NULL", [$student_id]);

$streak = getStat($pdo, "SELECT current_streak FROM users WHERE id=? AND deleted_at IS NULL", [$student_id]);

$unread = getStat($pdo, "SELECT COUNT(*) FROM notifications WHERE user_id=? AND is_read=0 AND deleted_at IS NULL", [$student_id]);




// Check for session messages
if (isset($_SESSION['success'])) {
    $success = $_SESSION['success'];
    unset($_SESSION['success']);
}
if (isset($_SESSION['error'])) {
    $error = $_SESSION['error'];
    unset($_SESSION['error']);
}

// Fetch all goals for this student
$goals_query = $pdo->prepare("
    SELECT * FROM student_goals 
    WHERE student_id = ? 
      AND deleted_at IS NULL
    ORDER BY 
        CASE priority 
            WHEN 'high' THEN 1 
            WHEN 'medium' THEN 2 
            WHEN 'low' THEN 3 
            ELSE 4 
        END,
        due_date ASC,
        created_at DESC
");

$goals_query->execute([$student_id]);
$goals = $goals_query->fetchAll(PDO::FETCH_ASSOC);

$categories_stmt = $pdo->prepare("
    SELECT DISTINCT category 
    FROM student_goals 
    WHERE student_id=? 
      AND deleted_at IS NULL
      AND category IS NOT NULL
");

$categories_stmt->execute([$student_id]);
$categories = $categories_stmt->fetchAll(PDO::FETCH_COLUMN);
$current = basename($_SERVER['PHP_SELF']);
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Goals - ProgressMate</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

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
.main-content > *{
  max-width: 1180px;
}
@media (min-width: 1200px){
  .main-content{ display:flex; flex-direction: column; align-items:flex-start; }
}

/* Header */
.page-header{
  width: 100%;
  display:flex;
  align-items:flex-start;
  justify-content: space-between;
  gap: 14px;
  padding: 16px 16px;
  border-radius: var(--r20);
  border: 1px solid var(--border);
  background:
    radial-gradient(120% 220% at 15% 10%, rgba(255,255,255,.10), transparent 55%),
    linear-gradient(180deg, rgba(255,255,255,.05), rgba(255,255,255,.03));
  box-shadow: var(--shadow2);
}
.header-content h1{ margin:0 0 6px; font-size: 24px; font-weight: 950; }
.header-content p{ margin:0; color: var(--muted); font-size: 14px; }

/* Buttons */
.btn{
  display:inline-flex;
  align-items:center;
  justify-content:center;
  gap: 10px;
  padding: 12px 14px;
  border-radius: 14px;
  font-weight: 900;
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
  box-shadow: 0 0 0 1px rgba(255,255,255,.08), 0 12px 30px rgba(79,70,229,.18);
}
.btn-primary{
  border-color: rgba(79,70,229,.35);
  background:
    radial-gradient(120% 180% at 20% 10%, rgba(255,255,255,.16), transparent 55%),
    linear-gradient(135deg, rgba(79,70,229,.62), rgba(34,211,238,.18));
}
.btn-secondary{
  background: rgba(255,255,255,.04);
}
.btn-danger{
  border-color: rgba(251,113,133,.35);
  background:
    radial-gradient(120% 180% at 20% 10%, rgba(255,255,255,.16), transparent 55%),
    linear-gradient(135deg, rgba(251,113,133,.55), rgba(79,70,229,.10));
}
.btn-sm{
  padding: 10px 12px;
  border-radius: 12px;
  font-size: 12.5px;
  font-weight: 900;
}

/* Alerts */
.alert{
  width: 100%;
  margin-top: 12px;
  padding: 12px 14px;
  border-radius: 16px;
  border: 1px solid rgba(255,255,255,.10);
  background: rgba(255,255,255,.03);
  display:flex;
  align-items:center;
  gap: 10px;
}
.alert i{ width: 34px; height:34px; display:grid; place-items:center; border-radius: 12px; background: rgba(255,255,255,.05); border: 1px solid rgba(255,255,255,.10); }
.alert-success{ border-color: rgba(52,211,153,.25); }
.alert-success i{ color: var(--success); }
.alert-error{ border-color: rgba(251,113,133,.25); }
.alert-error i{ color: var(--danger); }

/* Stats */
.stats-grid{
  width: 100%;
  margin-top: 14px;
  display:grid;
  grid-template-columns: repeat(4, minmax(0, 1fr));
  gap: 12px;
}
.stat-card{
  border-radius: var(--r20);
  border: 1px solid rgba(255,255,255,.12);
  background:
    radial-gradient(120% 180% at 10% 0%, rgba(255,255,255,.12), transparent 60%),
    linear-gradient(180deg, rgba(255,255,255,.05), rgba(255,255,255,.03));
  box-shadow: var(--shadow2);
}
.stat-content{
  display:flex;
  align-items:center;
  gap: 12px;
  padding: 14px 14px;
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
    linear-gradient(135deg, rgba(34,211,238,.40), rgba(79,70,229,.40));
  box-shadow: 0 16px 30px rgba(0,0,0,.22);
}
.stat-number{ font-size: 24px; font-weight: 950; line-height: 1.1; }
.stat-label{ margin-top: 2px; font-size: 13px; color: var(--muted); }

/* ===== Goals Grid + Cards ===== */
.goals-grid{
  width: 100%;
  margin-top: 14px;
  display:grid;
  grid-template-columns: repeat(auto-fill, minmax(340px, 1fr));
  gap: 12px;
}

.goal-card{
  border-radius: var(--r20);
  border: 1px solid rgba(255,255,255,.10);
  background:
    radial-gradient(140% 220% at 10% 0%, rgba(255,255,255,.10), transparent 60%),
    linear-gradient(180deg, rgba(255,255,255,.05), rgba(255,255,255,.03));
  box-shadow: var(--shadow);
  overflow:hidden;
  display:flex;
  flex-direction: column;
  transition: transform .18s ease, box-shadow .18s ease, border-color .18s ease;
}
.goal-card:hover{
  transform: translateY(-2px);
  box-shadow: 0 0 0 1px rgba(255,255,255,.08), 0 18px 50px rgba(0,0,0,.35);
  border-color: rgba(255,255,255,.14);
}

/* priority edge */
.goal-card.high-priority{ border-left: 4px solid rgba(251,113,133,.85); }
.goal-card.medium-priority{ border-left: 4px solid rgba(251,191,36,.85); }
.goal-card.low-priority{ border-left: 4px solid rgba(52,211,153,.85); }

.goal-card.completed{
  border-color: rgba(52,211,153,.20);
  background:
    radial-gradient(140% 220% at 10% 0%, rgba(52,211,153,.12), transparent 65%),
    linear-gradient(180deg, rgba(255,255,255,.05), rgba(255,255,255,.03));
}

/* goal header */
.goal-header{
  padding: 12px 14px;
  border-bottom: 1px solid rgba(255,255,255,.08);
  background: rgba(255,255,255,.03);
  display:flex;
  justify-content: space-between;
  align-items: flex-start;
  gap: 12px;
}
.goal-title{
  font-weight: 950;
  font-size: 15px;
  line-height: 1.2;
  margin-bottom: 6px;
}
.goal-category{
  display:inline-flex;
  align-items:center;
  gap: 8px;
  padding: 4px 10px;
  font-size: 12px;
  font-weight: 900;
  border-radius: 999px;
  color: var(--text);
  border: 1px solid rgba(255,255,255,.12);
  background:
    radial-gradient(120% 180% at 20% 20%, rgba(255,255,255,.16), transparent 55%),
    rgba(255,255,255,.03);
}

/* status badges (your classes: status-completed, status-in_progress, status-pending) */
.status-completed,
.status-in_progress,
.status-pending,
.status-overdue{
  font-size: 12px;
  font-weight: 950;
  padding: 4px 10px;
  border-radius: 999px;
  border: 1px solid rgba(255,255,255,.12);
  background: rgba(255,255,255,.03);
  white-space: nowrap;
}
.status-completed{ border-color: rgba(52,211,153,.25); color: rgba(52,211,153,.95); background: rgba(52,211,153,.10); }
.status-in_progress{ border-color: rgba(34,211,238,.25); color: rgba(34,211,238,.95); background: rgba(34,211,238,.10); }
.status-pending{ border-color: rgba(234,240,255,.18); color: rgba(234,240,255,.85); background: rgba(255,255,255,.04); }
.status-overdue{ border-color: rgba(251,113,133,.25); color: rgba(251,113,133,.95); background: rgba(251,113,133,.10); }

.goal-header-actions{
  display:flex;
  align-items:center;
  gap: 10px;
}

/* dropdown */
.dropdown{ position: relative; }
.dropdown-toggle{
  width: 36px;
  height: 36px;
  display:grid;
  place-items:center;
  border-radius: 12px;
  border: 1px solid rgba(255,255,255,.12);
  background: rgba(255,255,255,.04);
  color: rgba(234,240,255,.85);
  cursor:pointer;
}
.dropdown-toggle:hover{ background: rgba(255,255,255,.06); }

.dropdown-menu{
  display:none;
  position:absolute;
  right:0;
  top: calc(100% + 8px);
  min-width: 180px;
  border-radius: 14px;
  border: 1px solid rgba(255,255,255,.14);
  background: rgba(10,14,35,.92);
  box-shadow: 0 18px 40px rgba(0,0,0,.45);
  overflow:hidden;
  z-index: 30;
  backdrop-filter: blur(12px);
}
.dropdown-item{
  display:flex;
  align-items:center;
  gap: 10px;
  padding: 10px 12px;
  font-size: 13px;
  font-weight: 800;
  color: rgba(234,240,255,.92);
  border-bottom: 1px solid rgba(255,255,255,.08);
}
.dropdown-item:last-child{ border-bottom: none; }
.dropdown-item:hover{
  background:
    radial-gradient(120% 180% at 10% 0%, rgba(79,70,229,.18), transparent 60%),
    rgba(255,255,255,.03);
}
.dropdown-item.text-danger{ color: rgba(251,113,133,.95); }

/* goal body */
.goal-body{ padding: 12px 14px 14px; }
.goal-description{
  color: rgba(234,240,255,.84);
  font-size: 13px;
  line-height: 1.55;
  margin-bottom: 12px;
}

.goal-meta{
  display:flex;
  flex-wrap: wrap;
  gap: 10px 14px;
  margin-bottom: 12px;
}
.goal-meta-item{
  display:flex;
  align-items:center;
  gap: 8px;
  color: var(--muted);
  font-size: 12.5px;
  font-weight: 700;
}
.goal-meta-item i{ color: rgba(96,165,250,.95); }

/* progress */
.progress-section{ margin-top: 6px; }
.progress-header{
  display:flex;
  justify-content: space-between;
  align-items:center;
  margin-bottom: 8px;
}
.progress-label{ color: var(--muted); font-size: 12.5px; font-weight: 800; }
.progress-percentage{ color: rgba(96,165,250,.95); font-weight: 950; font-size: 13px; }

.progress-bar{
  height: 10px;
  width: 100%;
  border-radius: 999px;
  background: rgba(255,255,255,.07);
  border: 1px solid rgba(255,255,255,.08);
  overflow:hidden;
  margin: 8px 0 6px;
}
.progress-fill{
  height: 100%;
  width: 0%;
  border-radius: 999px;
  background: linear-gradient(90deg, rgba(34,211,238,.95), rgba(79,70,229,.95));
  box-shadow: 0 10px 25px rgba(34,211,238,.16);
  transition: width 1s cubic-bezier(.22,.75,.12,1);
}
.progress-stats{
  display:flex;
  justify-content: space-between;
  font-size: 12px;
  color: var(--muted2);
  font-weight: 800;
}

.progress-chart{
  height: 70px;
  margin-top: 10px;
}
.progress-chart canvas{
  width:100% !important;
  height:70px !important;
}

/* footer */
.goal-footer{
  padding: 12px 14px;
  border-top: 1px solid rgba(255,255,255,.08);
  background: rgba(255,255,255,.03);
  display:flex;
  gap: 10px;
}
.goal-footer .btn{ flex: 1; }

/* empty state */
.empty-state{
  grid-column: 1 / -1;
  text-align:center;
  padding: 22px 14px;
  color: var(--muted);
  border-radius: var(--r20);
  border: 1px solid rgba(255,255,255,.10);
  background: rgba(255,255,255,.03);
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

/* ===== Modals ===== */
.modal-overlay{
  display:none;
  position: fixed;
  inset: 0;
  background: rgba(0,0,0,.55);
  z-index: 2200;
  align-items: center;
  justify-content: center;
  padding: 18px;
  backdrop-filter: blur(4px);
}
.modal{
  width: 100%;
  max-width: 520px;
  border-radius: 20px;
  border: 1px solid rgba(255,255,255,.14);
  background:
    radial-gradient(120% 200% at 15% 0%, rgba(79,70,229,.18), transparent 60%),
    linear-gradient(180deg, rgba(10,14,35,.92), rgba(10,14,35,.78));
  box-shadow: 0 26px 70px rgba(0,0,0,.55);
  overflow:hidden;
}
.modal-header{
  padding: 12px 14px;
  border-bottom: 1px solid rgba(255,255,255,.10);
  display:flex;
  align-items:center;
  justify-content: space-between;
  gap: 10px;
  background: rgba(255,255,255,.03);
}
.modal-header h3{
  margin:0;
  font-size: 15px;
  font-weight: 950;
}
.modal-close{
  width: 40px;
  height: 40px;
  border-radius: 14px;
  border: 1px solid rgba(255,255,255,.14);
  background: rgba(255,255,255,.04);
  color: var(--text);
  cursor:pointer;
}
.modal-body{ padding: 12px 14px 14px; }

.form-group{ margin-bottom: 12px; }
.form-group label{
  display:block;
  margin-bottom: 6px;
  font-size: 12.5px;
  font-weight: 900;
  color: rgba(234,240,255,.90);
}
.form-group input,
.form-group select,
.form-group textarea{
  width: 100%;
  padding: 12px 12px;
  border-radius: 14px;
  border: 1px solid rgba(255,255,255,.12);
  background: rgba(255,255,255,.04);
  color: var(--text);
  outline: none;
}
.form-group textarea{ resize: vertical; min-height: 110px; }
.form-row{
  display:grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 12px;
}
.modal-actions{
  display:flex;
  gap: 10px;
  justify-content:flex-end;
  margin-top: 12px;
}

/* focus */
a:focus, button:focus, input:focus, textarea:focus, select:focus{ outline: none; }
a:focus-visible,
button:focus-visible,
input:focus-visible,
textarea:focus-visible,
select:focus-visible{
  box-shadow: 0 0 0 3px rgba(34,211,238,.25), 0 0 0 1px rgba(255,255,255,.10);
  border-radius: 14px;
}

/* ===== Responsive ===== */
@media (max-width: 1100px){
  .stats-grid{ grid-template-columns: repeat(2, minmax(0, 1fr)); }
  .goals-grid{ grid-template-columns: repeat(auto-fill, minmax(320px, 1fr)); }
  .form-row{ grid-template-columns: 1fr; }
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

  .main-content{ padding: 18px 14px 28px; }
  .page-header{ flex-direction: column; align-items: flex-start; }
}

@media (max-width: 520px){
  .stats-grid{ grid-template-columns: 1fr; }
  .goals-grid{ grid-template-columns: 1fr; }
  .user-info p{ max-width: 160px; }
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

        <!-- MAIN CONTENT -->
        <main class="main-content">
            <header class="page-header">
                <div class="header-content">
                    <h1>My Goals</h1>
                    <p>Track and manage all your goals in one place</p>
                </div>
                <div class="header-actions">
                    <a href="create_goal.php" class="btn btn-primary"><i class="fas fa-plus"></i> Create Goal</a>
                </div>
            </header>

            <!-- Success/Error Alerts -->
            <?php if ($success): ?>
                <div class="alert alert-success"><i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($success); ?></div>
            <?php endif; ?>
            <?php if ($error): ?>
                <div class="alert alert-error"><i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($error); ?></div>
            <?php endif; ?>

            <!-- Stats Overview -->
            <div class="stats-grid">
                <div class="stat-card goals">
                    <div class="stat-content">
                        <div class="stat-icon">
                            <i class="fas fa-bullseye"></i>
                        </div>
                        <div>
                            <div class="stat-number"><?php echo $total_goals; ?></div>
                            <div class="stat-label">Total Goals</div>
                        </div>
                    </div>
                </div>

                <div class="stat-card completed">
                    <div class="stat-content">
                        <div class="stat-icon">
                            <i class="fas fa-check-circle"></i>
                        </div>
                        <div>
                            <div class="stat-number"><?php echo $completed_goals; ?></div>
                            <div class="stat-label">Completed</div>
                        </div>
                    </div>
                </div>

                <div class="stat-card progress">
                    <div class="stat-content">
                        <div class="stat-icon">
                            <i class="fas fa-spinner"></i>
                        </div>
                        <div>
                            <div class="stat-number"><?php echo $in_progress_goals; ?></div>
                            <div class="stat-label">In Progress</div>
                        </div>
                    </div>
                </div>

                <div class="stat-card points">
                    <div class="stat-content">
                        <div class="stat-icon">
                            <i class="fas fa-star"></i>
                        </div>
                        <div>
                            <div class="stat-number"><?php echo $total_points; ?></div>
                            <div class="stat-label">Total Points</div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Goals Grid -->
            <div class="goals-grid">
                <?php if (empty($goals)): ?>
                    <div class="empty-state">
                        <i class="fas fa-bullseye"></i>
                        <p>No goals yet</p>
                        <a href="create_goal.php" class="btn btn-primary"><i class="fas fa-plus"></i> Create Your First Goal</a>
                    </div>
                <?php else: ?>
                    <?php foreach ($goals as $goal):
                        $percentage = round($goal['progress_percentage'], 1);

                        $current_value = (float)$goal['current_value'];
                        $target_value  = (float)$goal['target_value'];

                        // remaining never negative
                       $remaining = round(max(0, $target_value - $current_value), 2);
                        $isPending = (($goal['status'] ?? '') === 'pending');
                        $isAdminGoal = ((int)$goal['is_admin_created'] === 1);
                        $isSelfCreated = ((int)($goal['is_self_created'] ?? 0) === 1);
                        $status = $goal['status'] ?? 'pending';

                        // Only allow CRUD if student-created AND completed AND not admin goal
                     $canCrud = (!$isAdminGoal && $isSelfCreated && $status !== 'completed');




                        $days_left = null;
                        if (!empty($goal['due_date'])) {
                            $diff = (new DateTime())->diff(new DateTime($goal['due_date']));
                            $days_left = (int)$diff->format('%r%a');
                        }


                        $is_overdue = ($goal['status'] === 'overdue');

                    ?>
                        <div class="goal-card <?php echo $goal['priority']; ?>-priority <?php echo $goal['status']; ?>">
                            <div class="goal-header">
                                <div>
                                    <div class="goal-title"><?php echo htmlspecialchars($goal['title']); ?></div>
                                    <?php if ($goal['category']): ?>
                                        <span class="goal-category"><?php echo htmlspecialchars($goal['category']); ?></span>
                                    <?php endif; ?>
                                </div>
                                <div class="goal-header-actions">
                                    <span class="status-<?php echo $goal['status']; ?>">
                                        <?php echo ucfirst(str_replace('_', ' ', $goal['status'])); ?>
                                    </span>
                                    <div class="dropdown">
                                        <button class="dropdown-toggle" type="button">
                                            <i class="fas fa-ellipsis-v"></i>
                                        </button>
                                        <div class="dropdown-menu">
                                            <?php if ($canCrud): ?>
                                                <a href="#" onclick="openEditGoalModal(<?php echo $goal['id']; ?>)" class="dropdown-item">
                                                    <i class="fas fa-edit"></i> Edit
                                                </a>
                                                <a href="#" onclick="openDeleteModal(<?php echo $goal['id']; ?>, '<?php echo addslashes($goal['title']); ?>')" class="dropdown-item text-danger">
                                                    <i class="fas fa-trash"></i> Delete
                                                </a>
                                            <?php else: ?>
                                                <span class="dropdown-item" style="color:#64748b; cursor:not-allowed;">
                                                    <i class="fas fa-lock"></i> Locked
                                                </span>
                                            <?php endif; ?>
                                        </div>


                                    </div>
                                </div>
                            </div>

                            <div class="goal-body">
                                <?php if ($goal['description']): ?>
                                    <div class="goal-description"><?php echo nl2br(htmlspecialchars($goal['description'])); ?></div>
                                <?php endif; ?>

                                <div class="goal-meta">
                                    <div class="goal-meta-item"><i class="fas fa-flag"></i> <?php echo ucfirst($goal['priority']); ?></div>
                                    <?php if ($goal['due_date']): ?>
                                        <div class="goal-meta-item">
                                            <i class="fas fa-calendar"></i>
                                            <?php if ($is_overdue): ?>
                                                <span style="color: #dc2626;">Overdue</span>
                                            <?php elseif ($days_left == 0): ?>
                                                Due today
                                            <?php else: ?>
                                                <?php echo $days_left; ?> day<?php echo $days_left != 1 ? 's' : ''; ?> left
                                            <?php endif; ?>
                                        </div>
                                    <?php endif; ?>
                                    <?php if ($goal['unit']): ?>
                                        <div class="goal-meta-item"><i class="fas fa-ruler"></i> <?php echo htmlspecialchars($goal['unit']); ?></div>
                                    <?php endif; ?>
                                </div>

                                <div class="progress-section">
                                    <div class="progress-header">
                                        <span class="progress-label">Progress</span>
                                        <span class="progress-percentage"><?php echo $percentage; ?>%</span>
                                    </div>
                                    <div class="progress-bar">
                                        <div class="progress-fill" style="width: <?php echo $percentage; ?>%"></div>
                                    </div>
                                    <div class="progress-stats">
                                        <span><?php echo $goal['current_value']; ?> / <?php echo $goal['target_value']; ?> <?php echo $goal['unit']; ?></span>
                                    </div>
                                </div>

                                <!-- Small Progress Chart -->
                                <div class="progress-chart">
                                    <canvas id="chart_<?php echo $goal['id']; ?>"></canvas>
                                </div>
                            </div>

                            <div class="goal-footer">
                                <?php if ($goal['status'] !== 'completed'): ?>
                                    <button class="btn btn-sm btn-primary"
                                        onclick="openUpdateProgressModal(<?php echo $goal['id']; ?>, '<?php echo addslashes($goal['title']); ?>', <?php echo $remaining; ?>)">
                                        <i class="fas fa-arrow-up"></i> Update
                                    </button>
                                <?php endif; ?>

                                <?php if ($canCrud): ?>
                                    <button class="btn btn-sm btn-secondary" onclick="openEditGoalModal(<?php echo $goal['id']; ?>)">
                                        <i class="fas fa-edit"></i> Edit
                                    </button>
                                <?php else: ?>
                                    <button class="btn btn-sm btn-secondary" disabled style="opacity:.6; cursor:not-allowed;">
                                        <i class="fas fa-lock"></i> Locked
                                    </button>
                                <?php endif; ?>


                            </div>

                        </div>

                        <script>
                            // Individual chart for this goal
                            const ctx<?php echo $goal['id']; ?> = document.getElementById('chart_<?php echo $goal['id']; ?>').getContext('2d');
                            new Chart(ctx<?php echo $goal['id']; ?>, {
                                type: 'line',
                                data: {
                                    labels: ['Start', 'Now', 'Target'],
                                    datasets: [{
                                        data: [0, <?php echo $goal['current_value']; ?>, <?php echo $goal['target_value']; ?>],
                                        borderColor: '#4f46e5',
                                        backgroundColor: 'rgba(79, 70, 229, 0.1)',
                                        fill: true,
                                        tension: 0.4
                                    }]
                                },
                                options: {
                                    responsive: true,
                                    maintainAspectRatio: false,
                                    plugins: {
                                        legend: {
                                            display: false
                                        }
                                    },
                                    scales: {
                                        x: {
                                            display: false
                                        },
                                        y: {
                                            display: false
                                        }
                                    }
                                }
                            });
                        </script>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </main>
    </div>

    <!-- Update Progress Modal -->
    <div class="modal-overlay" id="updateProgressModal">
        <div class="modal">
            <div class="modal-header">
                <h3>Update Progress</h3>
                <button class="modal-close" onclick="closeUpdateProgressModal()">×</button>
            </div>
            <div class="modal-body">
                <form id="progressForm">

                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf); ?>">
                    <input type="hidden" name="goal_id" id="modal_goal_id">


                    <p><strong>Goal:</strong> <span id="modal_goal_title"></span></p>
                    <p><strong>Remaining:</strong> <span id="modal_remaining"></span></p>

                    <div class="form-group">
                        <label>Progress Added</label>
                        <input type="number" name="progress_value" step="0.01" min="0.01" required placeholder="e.g. 5">
                    </div>
                    <div class="form-group">
                        <label>Notes (optional)</label>
                        <textarea name="notes" rows="3" placeholder="What did you do today?"></textarea>
                    </div>
                    <div class="modal-actions">
                        <button type="button" class="btn btn-secondary" onclick="closeUpdateProgressModal()">Cancel</button>
                        <button type="submit" class="btn btn-primary">Save Progress</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Edit Goal Modal -->
    <div class="modal-overlay" id="editGoalModal">
        <div class="modal">
            <div class="modal-header">
                <h3>Edit Goal</h3>
                <button class="modal-close" onclick="closeEditGoalModal()">×</button>
            </div>
            <div class="modal-body">
                <form method="POST" id="editGoalForm">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf); ?>">

                    <input type="hidden" name="goal_id" id="edit_goal_id">

                    <div class="form-group">
                        <label>Goal Title *</label>
                        <input type="text" name="title" id="edit_title" required placeholder="e.g., Complete React Course">
                    </div>

                    <div class="form-group">
                        <label>Description</label>
                        <textarea name="description" id="edit_description" rows="3" placeholder="Describe your goal..."></textarea>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label>Category</label>
                            <select name="category" id="edit_category">
                                <option value="">Select Category</option>
                                <?php foreach ($categories as $cat): ?>
                                    <option value="<?php echo htmlspecialchars($cat); ?>"><?php echo htmlspecialchars($cat); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Priority</label>
                            <select name="priority" id="edit_priority">
                                <option value="low">Low</option>
                                <option value="medium" selected>Medium</option>
                                <option value="high">High</option>
                            </select>
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label>Target Value *</label>
                            <input type="number" name="target_value" id="edit_target_value" step="0.01" min="0.01" required placeholder="e.g., 100">
                        </div>
                        <div class="form-group">
                            <label>Unit</label>
                            <input type="text" name="unit" id="edit_unit" placeholder="e.g., pages, hours, kg">
                        </div>
                    </div>

                    <div class="form-group">
                        <label>Due Date</label>
                        <input type="date" name="due_date" id="edit_due_date">
                    </div>

                    <div class="modal-actions">
                        <button type="button" class="btn btn-secondary" onclick="closeEditGoalModal()">Cancel</button>
                        <button type="submit" class="btn btn-primary">Update Goal</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Delete Confirmation Modal -->
    <div class="modal-overlay" id="deleteModal">
        <div class="modal">
            <div class="modal-header">
                <h3>Delete Goal</h3>
                <button class="modal-close" onclick="closeDeleteModal()">×</button>
            </div>
            <div class="modal-body">
                <p>Are you sure you want to delete "<span id="delete_goal_title"></span>"?</p>
                <p class="text-danger"><i class="fas fa-exclamation-triangle"></i> This action cannot be undone. All progress and history will be deleted.</p>
                <form id="deleteForm">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf); ?>">
                    <input type="hidden" name="goal_id" id="delete_goal_id">

                    <div class="modal-actions">
                        <button type="button" class="btn btn-secondary" onclick="closeDeleteModal()">Cancel</button>
                        <button type="submit" class="btn btn-danger">Delete Goal</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script>
        // Function to fetch goal data and populate edit form

        function closeEditGoalModal() {
            document.getElementById('editGoalModal').style.display = 'none';
            document.getElementById('editGoalForm').reset();
        }

        // Delete modal functions
        function openDeleteModal(goalId, goalTitle) {
            document.getElementById('delete_goal_id').value = goalId;
            document.getElementById('delete_goal_title').textContent = goalTitle;
            document.getElementById('deleteModal').style.display = 'flex';
        }

        function closeDeleteModal() {
            document.getElementById('deleteModal').style.display = 'none';
        }

        // Update progress modal functions
        function openUpdateProgressModal(id, title, remaining) {
            document.getElementById('modal_goal_id').value = id;
            document.getElementById('modal_goal_title').textContent = title;
            document.getElementById('modal_remaining').textContent = remaining;
            document.getElementById('updateProgressModal').style.display = 'flex';
        }

        function closeUpdateProgressModal() {
            document.getElementById('updateProgressModal').style.display = 'none';
        }

        // Mobile sidebar
        document.getElementById('sidebarToggle')?.addEventListener('click', () => {
            document.getElementById('sidebar').classList.add('active');
        });

        document.getElementById('sidebarClose')?.addEventListener('click', () => {
            document.getElementById('sidebar').classList.remove('active');
        });

        // Close modals when clicking outside
        document.querySelectorAll('.modal-overlay').forEach(modal => {
            modal.addEventListener('click', function(e) {
                if (e.target === this) {
                    this.style.display = 'none';
                }
            });
        });

        // Dropdown menu functionality
        document.addEventListener('click', function(e) {
            if (!e.target.closest('.dropdown')) {
                document.querySelectorAll('.dropdown-menu').forEach(menu => {
                    menu.style.display = 'none';
                });
            }
        });

        document.querySelectorAll('.dropdown-toggle').forEach(toggle => {
            toggle.addEventListener('click', function(e) {
                e.stopPropagation();
                const menu = this.nextElementSibling;
                menu.style.display = menu.style.display === 'block' ? 'none' : 'block';
            });
        });

        // Animate progress bars on load
        document.addEventListener('DOMContentLoaded', function() {
            document.querySelectorAll('.progress-fill').forEach(fill => {
                const width = fill.style.width;
                fill.style.width = '0';
                setTimeout(() => {
                    fill.style.width = width;
                }, 300);
            });
        });

        // Handle window resize
        window.addEventListener('resize', function() {
            if (window.innerWidth > 992) {
                document.getElementById('sidebar').classList.remove('active');
            }
        });

    async function postForm(url, formEl) {
  const formData = new FormData(formEl);

  const res = await fetch(url, { method: "POST", body: formData });
  const text = await res.text();

  let data;
  try { data = JSON.parse(text); }
  catch {
    console.error("Server returned NOT JSON:", text);
    alert("Server returned HTML / error. Open Console (F12) to see it.");
    throw new Error("Invalid JSON response");
  }

  if (!res.ok || !data.success) throw new Error(data.error || "Failed");
  return data;
}


        async function openEditGoalModal(goalId) {
            // fetch goal first
            const res = await fetch(`../api/student/goal_get.php?id=${goalId}`);
            const data = await res.json();
            if (!data.success) {
                alert(data.error);
                return;
            }

            const g = data.goal;

            if (!g.can_edit) {
    alert("Locked: You can only edit your own self-created goals after they are completed.");
    return;
  }


            // open modal if allowed
            document.getElementById('editGoalModal').style.display = 'flex';
            document.getElementById('edit_goal_id').value = goalId;

            document.getElementById('edit_title').value = g.title || '';
            document.getElementById('edit_description').value = g.description || '';
            document.getElementById('edit_category').value = g.category || '';
            document.getElementById('edit_priority').value = g.priority || 'medium';
            document.getElementById('edit_target_value').value = g.target_value || 0;
            document.getElementById('edit_unit').value = g.unit || '';
            document.getElementById('edit_due_date').value = g.due_date || '';
        }


        document.getElementById('editGoalForm')?.addEventListener('submit', async (e) => {
            e.preventDefault();
            try {
                await postForm('../api/student/goal_edit.php', e.target);
                location.reload();
            } catch (err) {
                alert(err.message);
            }
        });

 document.getElementById('progressForm')?.addEventListener('submit', async (e) => {
    e.preventDefault();

    try {
        const data = await postForm('../api/student/goal_progress.php', e.target);

        if (!data.success) {
            console.error(data.error);
            return;
        }

        // Close modal
        closeUpdateProgressModal();

        // Get goal id from hidden input
        const goalId = document.getElementById('modal_goal_id').value;

        // Find correct goal card
        const card = document.querySelector(`canvas#chart_${goalId}`)?.closest('.goal-card');

        if (!card) return;

        // Update percentage text
        card.querySelector('.progress-percentage').innerText = data.after.progress_percentage
 + '%';

        // Update progress bar width
        card.querySelector('.progress-fill').style.width = data.after.progress_percentage
 + '%';

        // Update numbers
        const stats = card.querySelector('.progress-stats span');
        stats.innerText = data.new_value + " / " + stats.innerText.split('/')[1];

        // If completed → update status badge
        if (data.status === 'completed') {
            const badge = card.querySelector('[class^="status-"]');
            badge.className = 'status-completed';
            badge.innerText = 'Completed';

            // Hide update button
            const updateBtn = card.querySelector('.btn-primary');
            if (updateBtn) updateBtn.remove();
        }

    } catch (err) {
        console.error(err.message);
    }
});




        document.getElementById('deleteForm')?.addEventListener('submit', async (e) => {
            e.preventDefault();
            try {
                await postForm('../api/student/goal_delete.php', e.target);
                location.reload();
            } catch (err) {
                alert(err.message);
            }
        });
        
    </script>
</body>

</html>