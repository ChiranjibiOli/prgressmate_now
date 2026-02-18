<?php
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

$admin_id = $_SESSION['user_id'] ?? 0;

$total_students = 0;
$total_goals = 0;
$total_assigned = 0;
$total_points = 0;
$pending_count = $in_progress_count = $completed_count = $overdue_count = 0;
$department_stats = [];
$student_progress = [];
$sidebar_stats = ['students' => 0, 'goals' => 0, 'assigned' => 0, 'points' => 0];

$current_date = date('F d, Y');

try {
    $total_students = $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'student' AND status = 'active' AND deleted_at IS NULL")->fetchColumn();
    $total_goals = $pdo->query("SELECT COUNT(*) FROM admin_goals WHERE status = 'active' AND deleted_at IS NULL")->fetchColumn();
    $total_assigned = $pdo->query("SELECT COUNT(*) FROM student_goals WHERE deleted_at IS NULL")->fetchColumn();
    $total_points = $pdo->query("SELECT COALESCE(SUM(a.points), 0) FROM user_achievements ua JOIN achievements a ON ua.achievement_id = a.id JOIN users u ON ua.user_id = u.id WHERE u.role = 'student' AND u.deleted_at IS NULL")->fetchColumn();

    $pending_count = $pdo->query("SELECT COUNT(*) FROM student_goals WHERE progress_percentage = 0 AND deleted_at IS NULL")->fetchColumn();
    $in_progress_count = $pdo->query("SELECT COUNT(*) FROM student_goals WHERE progress_percentage > 0 AND progress_percentage < 100 AND deleted_at IS NULL")->fetchColumn();
    $completed_count = $pdo->query("SELECT COUNT(*) FROM student_goals WHERE progress_percentage >= 100 AND deleted_at IS NULL")->fetchColumn();
    $overdue_count = $pdo->query("SELECT COUNT(*) FROM student_goals WHERE due_date IS NOT NULL AND due_date < CURDATE() AND progress_percentage < 100 AND deleted_at IS NULL")->fetchColumn();

    $dept_stmt = $pdo->prepare("
        SELECT 
            u.department,
            COUNT(sg.id) as total_goals,
            SUM(CASE WHEN sg.progress_percentage >= 100 THEN 1 ELSE 0 END) as completed_goals,
            COALESCE(ROUND(AVG(sg.progress_percentage), 1), 0) as avg_progress
        FROM users u
        LEFT JOIN student_goals sg ON u.id = sg.student_id AND sg.deleted_at IS NULL
        WHERE u.role = 'student' AND u.status = 'active' AND u.deleted_at IS NULL AND u.department IS NOT NULL
        GROUP BY u.department
        HAVING total_goals > 0
        ORDER BY avg_progress DESC
        LIMIT 8
    ");
    $dept_stmt->execute();
    $department_stats = $dept_stmt->fetchAll();

    $student_stmt = $pdo->prepare("
        SELECT 
            u.id,
            u.name,
            u.department,
            u.profile_picture,
            COUNT(sg.id) as total_goals,
            SUM(CASE WHEN sg.progress_percentage >= 100 THEN 1 ELSE 0 END) as completed_goals,
            COALESCE(ROUND(AVG(sg.progress_percentage), 1), 0) as avg_progress,
            COALESCE(SUM(a.points), 0) as total_points
        FROM users u
        LEFT JOIN student_goals sg ON u.id = sg.student_id AND sg.deleted_at IS NULL
        LEFT JOIN user_achievements ua ON u.id = ua.user_id
        LEFT JOIN achievements a ON ua.achievement_id = a.id AND a.deleted_at IS NULL
        WHERE u.role = 'student' AND u.status = 'active' AND u.deleted_at IS NULL
        GROUP BY u.id
        ORDER BY avg_progress DESC, completed_goals DESC, total_points DESC, u.name ASC
        LIMIT 10
    ");
    $student_stmt->execute();
    $student_progress = $student_stmt->fetchAll();
} catch (Exception $e) {
    error_log('Admin dashboard error: ' . $e->getMessage());
}

$sidebar_stats = [
    'students' => $total_students,
    'goals' => $total_goals,
    'assigned' => $total_assigned,
    'points' => $total_points
];

$current = basename($_SERVER['PHP_SELF']);

$students_count = (int)($stats['students'] ?? 0);
$goals_count    = (int)(($stats['system_goals'] ?? 0) + ($stats['personal_goals'] ?? 0));
$assigned_count = (int)($stats['assigned'] ?? 0);
$points_count   = (int)($stats['points'] ?? 0);

$pending_requests = 0;
try {
    $pending_requests = (int)$pdo->query("SELECT COUNT(*) FROM progress_requests WHERE status='pending'")->fetchColumn();
} catch (Exception $e) {
    $pending_requests = 0;
}

$profile_picture = $_SESSION['profile_picture'] ?? '';
$name = $_SESSION['name'] ?? 'Admin';
$email = $_SESSION['email'] ?? 'admin@progressmate.com';
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard - ProgressMate</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
:root{
  --bg0:#070A18;
  --bg1:#0B1030;

  --text:#EAF0FF;
  --muted: rgba(234,240,255,.65);
  --muted2: rgba(234,240,255,.50);

  --primary:#7C3AED;
  --cyan:#22D3EE;
  --pink:#FB7185;

  --success:#34D399;
  --warning:#FBBF24;
  --danger:#FB7185;
  --info:#22D3EE;

  --gray-500: rgba(234,240,255,.60);

  --success-light: rgba(52,211,153,.12);
  --info-light: rgba(34,211,238,.12);
  --purple-light: rgba(124,58,237,.12);
  --purple: var(--primary);

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
a{ color: inherit; }

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

.dashboard-wrapper{
  display: grid;
  grid-template-columns: 320px 1fr;
  min-height: 100vh;
}

.sidebar{
  position: sticky;
  top: 0;
  height: 100vh;
  overflow: hidden;
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
  background:
    linear-gradient(120deg,
      rgba(124,58,237,.22),
      rgba(34,211,238,.16),
      rgba(251,113,133,.14));
  opacity:.22;
  filter: blur(26px);
  pointer-events:none;
  z-index:0;
}

.sidebar-header,
.user-profile,
.nav-menu,
.sidebar-quick-stats,
.sidebar-footer{
  position: relative;
  z-index: 2;
}

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

.user-info h4{
  margin: 2px 0 2px;
  font-size: 15px;
  font-weight: 900;
}
.user-info p{
  margin: 0;
  font-size: 12.5px;
  color: var(--muted);
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
  max-width: 210px;
}

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
.nav-menu::-webkit-scrollbar-track{ background: transparent; }
.nav-menu::-webkit-scrollbar-thumb{
  background: rgba(255,255,255,.16);
  border-radius: 99px;
}
.nav-menu::-webkit-scrollbar-thumb:hover{
  background: rgba(255,255,255,.22);
}

.nav-link{
  position: relative;
  display:flex;
  align-items:center;
  gap: 12px;
  padding: 12px 12px;
  border-radius: 14px;
  color: rgba(234,240,255,.90);
  text-decoration:none;
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
  font-weight: 900;
  padding: 4px 10px;
  border-radius: 999px;
  color: var(--text);
  background:
    radial-gradient(120% 180% at 20% 20%, rgba(255,255,255,.20), transparent 55%),
    linear-gradient(135deg, rgba(251,113,133,.70), rgba(124,58,237,.45));
  border: 1px solid rgba(255,255,255,.18);
  flex: 0 0 auto;
}

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
.sidebar-stat-number{ font-size: 18px; font-weight: 950; letter-spacing: .2px; }

.sidebar-footer{
  flex: 0 0 auto;
  margin-top: 12px;
}
.logout-btn{
  display:flex;
  align-items:center;
  justify-content:center;
  gap: 10px;
  padding: 12px 12px;
  border-radius: 14px;
  text-decoration:none;
  color: var(--text);
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

.main-content{
  padding: 26px 26px 40px;
}

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
.header-content h1{
  margin: 0 0 6px;
  font-size: 26px;
  font-weight: 950;
  letter-spacing: .2px;
}
.header-content p{
  margin: 0;
  color: var(--muted);
  font-size: 14px;
}

.btn{
  display:inline-flex;
  align-items:center;
  gap: 10px;
  padding: 12px 14px;
  border-radius: 14px;
  text-decoration:none;
  font-weight: 900;
  border: 1px solid rgba(255,255,255,.14);
  color: var(--text);
  background: rgba(255,255,255,.05);
  transition: transform .18s ease, box-shadow .18s ease, background .18s ease;
  white-space: nowrap;
}
.btn:hover{
  transform: translateY(-1px);
  box-shadow: 0 0 0 1px rgba(255,255,255,.08), 0 12px 30px rgba(124,58,237,.18);
  background: rgba(255,255,255,.07);
}
.btn-outline{
  background:
    radial-gradient(120% 180% at 20% 10%, rgba(255,255,255,.16), transparent 55%),
    linear-gradient(135deg, rgba(124,58,237,.45), rgba(34,211,238,.18));
}

.stats-grid{
  margin-top: 18px;
  display:grid;
  grid-template-columns: repeat(4, minmax(0, 1fr));
  gap: 14px;
}
.stat-card{
  position: relative;
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
.stat-number{
  font-size: 26px;
  font-weight: 950;
  letter-spacing: .4px;
  line-height: 1.1;
}
.stat-label{
  margin-top: 2px;
  font-size: 13px;
  color: var(--muted);
}

.content-grid{
  margin-top: 16px;
  display:grid;
  grid-template-columns: 1.25fr .9fr;
  gap: 14px;
}

.card{
  border-radius: var(--r20);
  border: 1px solid rgba(255,255,255,.10);
  background:
    radial-gradient(140% 220% at 10% 0%, rgba(255,255,255,.10), transparent 60%),
    linear-gradient(180deg, rgba(255,255,255,.05), rgba(255,255,255,.03));
  box-shadow: var(--shadow);
  overflow:hidden;
}
.card-header{
  display:flex;
  align-items:center;
  justify-content: space-between;
  gap: 12px;
  padding: 14px 16px;
  border-bottom: 1px solid rgba(255,255,255,.08);
  background: rgba(255,255,255,.03);
}
.card-header h3{
  margin: 0;
  font-size: 15px;
  font-weight: 950;
  letter-spacing: .2px;
  display:flex;
  align-items:center;
  gap: 10px;
}
.card-header h3 i{
  width: 34px;
  height: 34px;
  border-radius: 14px;
  display:grid;
  place-items:center;
  border: 1px solid rgba(255,255,255,.12);
  background: rgba(255,255,255,.05);
}
.card-body{ padding: 14px 16px 16px; }

.student-item{
  display:flex;
  gap: 12px;
  align-items:flex-start;
  padding: 12px 12px;
  border-radius: 16px;
  border: 1px solid rgba(255,255,255,.08);
  background: rgba(255,255,255,.03);
  margin-bottom: 10px;
  transition: transform .18s ease, border-color .18s ease, background .18s ease;
}
.student-item:hover{
  transform: translateY(-1px);
  background: rgba(255,255,255,.045);
  border-color: rgba(255,255,255,.12);
}
.student-avatar{
  width: 46px;
  height: 46px;
  border-radius: 16px;
  object-fit: cover;
  display:grid;
  place-items:center;
  font-weight: 950;
  color: var(--text);
  border: 1px solid rgba(255,255,255,.14);
  background:
    radial-gradient(120% 160% at 20% 10%, rgba(255,255,255,.16), transparent 55%),
    linear-gradient(135deg, rgba(124,58,237,.45), rgba(34,211,238,.20));
}

.dept-item{
  display:flex;
  align-items:center;
  justify-content: space-between;
  gap: 14px;
  padding: 12px 12px;
  border-radius: 16px;
  border: 1px solid rgba(255,255,255,.08);
  background: rgba(255,255,255,.03);
  margin-bottom: 10px;
}

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
  box-shadow: 0 10px 25px rgba(34,211,238,.16);
  transition: width 1s cubic-bezier(.22,.75,.12,1);
}
.progress-fill.completed{
  background: linear-gradient(90deg, rgba(52,211,153,.95), rgba(34,211,238,.75));
}

.status-item{
  padding: 12px 12px;
  border-radius: 16px;
  border: 1px solid rgba(255,255,255,.08);
  background: rgba(255,255,255,.03);
  margin-bottom: 10px;
}
.status-count{
  font-size: 20px;
  font-weight: 950;
  line-height: 1.1;
}
.status-label{
  font-size: 12px;
  color: var(--muted);
  margin-top: 3px;
}
.status-bar{
  height: 10px;
  width: 100%;
  border-radius: 999px;
  background: rgba(255,255,255,.07);
  border: 1px solid rgba(255,255,255,.08);
  overflow:hidden;
  margin-top: 10px;
}
.status-fill{
  height: 100%;
  width: 0%;
  border-radius: 999px;
  transition: width 1s cubic-bezier(.22,.75,.12,1);
}
.status-percentage{
  margin-top: 6px;
  font-size: 12px;
  color: var(--muted);
  text-align: right;
  font-weight: 900;
}

.quick-actions-grid{
  display:grid;
  grid-template-columns: repeat(3, minmax(0, 1fr));
  gap: 12px;
}
.quick-action{
  display:flex;
  align-items:center;
  justify-content:center;
  gap: 10px;
  padding: 14px 14px;
  border-radius: 16px;
  text-decoration:none;
  font-weight: 950;
  color: var(--text);
  border: 1px solid rgba(255,255,255,.12);
  background:
    radial-gradient(140% 220% at 10% 0%, rgba(255,255,255,.12), transparent 60%),
    linear-gradient(135deg, rgba(124,58,237,.22), rgba(34,211,238,.10));
  box-shadow: var(--shadow2);
  transition: transform .18s ease, box-shadow .18s ease, border-color .18s ease;
}
.quick-action:hover{
  transform: translateY(-2px);
  box-shadow: 0 0 0 1px rgba(255,255,255,.08), 0 14px 38px rgba(34,211,238,.16);
  border-color: rgba(255,255,255,.18);
}

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

@media (max-width: 1100px){
  .stats-grid{ grid-template-columns: repeat(2, minmax(0, 1fr)); }
  .content-grid{ grid-template-columns: 1fr; }
  .quick-actions-grid{ grid-template-columns: repeat(2, minmax(0, 1fr)); }
}

@media (max-width: 860px){
  .dashboard-wrapper{ grid-template-columns: 1fr; }
  .mobile-toggle{ display:grid; }

  .sidebar{
    position: fixed;
    left: 0;
    top: 0;
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

@media (max-width: 520px){
  .stats-grid{ grid-template-columns: 1fr; }
  .quick-actions-grid{ grid-template-columns: 1fr; }
  .user-info p{ max-width: 160px; }
}

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
                <?php if (!empty($profile_picture)): ?>
                    <img src="../<?php echo htmlspecialchars($profile_picture); ?>" alt="Profile" class="profile-pic">
                <?php else: ?>
                    <div class="profile-pic default"><?php echo htmlspecialchars(strtoupper(substr($name, 0, 1))); ?></div>
                <?php endif; ?>
                <div class="user-info">
                    <h4><?php echo htmlspecialchars($name); ?></h4>
                    <p><?php echo htmlspecialchars($email); ?></p>
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
                    <div>
                        <div class="sidebar-stat-label">Active Students</div>
                        <div class="sidebar-stat-number"><?php echo $sidebar_stats['students']; ?></div>
                    </div>
                </div>
                <div class="sidebar-stat">
                    <div class="sidebar-stat-icon"><i class="fas fa-bullseye"></i></div>
                    <div>
                        <div class="sidebar-stat-label">System Goals</div>
                        <div class="sidebar-stat-number"><?php echo $sidebar_stats['goals']; ?></div>
                    </div>
                </div>
                <div class="sidebar-stat">
                    <div class="sidebar-stat-icon"><i class="fas fa-star"></i></div>
                    <div>
                        <div class="sidebar-stat-label">Total Points</div>
                        <div class="sidebar-stat-number"><?php echo $sidebar_stats['points']; ?></div>
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
                    <h1>Admin Dashboard</h1>
                    <p>Welcome back! Here's your system overview as of <?php echo $current_date; ?></p>
                </div>
                <a href="reports.php" class="btn btn-outline"><i class="fas fa-chart-bar"></i> Detailed Reports</a>
            </header>

            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-content">
                        <div class="stat-icon"><i class="fas fa-users"></i></div>
                        <div>
                            <div class="stat-number"><?php echo $total_students; ?></div>
                            <div class="stat-label">Active Students</div>
                        </div>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-content">
                        <div class="stat-icon"><i class="fas fa-bullseye"></i></div>
                        <div>
                            <div class="stat-number"><?php echo $total_goals; ?></div>
                            <div class="stat-label">System Goals</div>
                        </div>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-content">
                        <div class="stat-icon"><i class="fas fa-tasks"></i></div>
                        <div>
                            <div class="stat-number"><?php echo $total_assigned; ?></div>
                            <div class="stat-label">Assigned Goals</div>
                        </div>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-content">
                        <div class="stat-icon"><i class="fas fa-star"></i></div>
                        <div>
                            <div class="stat-number"><?php echo $total_points; ?></div>
                            <div class="stat-label">Total Points Earned</div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="content-grid">
                <div class="card">
                    <div class="card-header">
                        <h3><i class="fas fa-trophy"></i> Top Performing Students</h3>
                        <a href="students.php" style="color: var(--primary);">View All →</a>
                    </div>
                    <div class="card-body">
                        <?php if (!empty($student_progress)): ?>
                            <?php foreach ($student_progress as $index => $student):
                                $medal = $index == 0 ? '🥇' : ($index == 1 ? '🥈' : ($index == 2 ? '🥉' : ($index + 1)));
                                $progress = $student['avg_progress'] ?? 0;
                            ?>
                                <div class="student-item">
                                    <div style="font-size: 32px;"><?php echo $medal; ?></div>
                                    <?php if (!empty($student['profile_picture'])): ?>
                                        <img src="../<?php echo htmlspecialchars($student['profile_picture']); ?>" alt="" class="student-avatar">
                                    <?php else: ?>
                                        <div class="student-avatar"><?php echo htmlspecialchars(strtoupper(substr($student['name'], 0, 1))); ?></div>
                                    <?php endif; ?>
                                    <div style="flex: 1;">
                                        <div style="font-weight: 600;"><?php echo htmlspecialchars($student['name']); ?></div>
                                        <div style="color: var(--gray-500); font-size: 14px;"><?php echo htmlspecialchars($student['department'] ?? 'No Department'); ?></div>
                                        <div style="margin-top: 8px; display: flex; gap: 20px; font-size: 14px;">
                                            <span><strong><?php echo $student['completed_goals']; ?></strong> completed</span>
                                            <span><strong><?php echo $student['total_points']; ?></strong> pts</span>
                                        </div>
                                        <div style="margin-top: 10px;">
                                            <div class="progress-bar">
                                                <div class="progress-fill <?php echo $progress >= 100 ? 'completed' : ''; ?>" style="width: <?php echo $progress; ?>%;"></div>
                                            </div>
                                            <div style="text-align: right; margin-top: 4px; font-size: 13px; color: var(--primary); font-weight: 600;"><?php echo $progress; ?>% avg progress</div>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <div class="empty-state"><i class="fas fa-users"></i>
                                <p>No student data available</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="card">
                    <div class="card-header">
                        <h3><i class="fas fa-building"></i> Department Performance</h3>
                    </div>
                    <div class="card-body">
                        <?php if (!empty($department_stats)): ?>
                            <?php foreach ($department_stats as $dept): ?>
                                <div class="dept-item">
                                    <div style="flex: 1; font-weight: 600;"><?php echo htmlspecialchars($dept['department']); ?></div>
                                    <div style="width: 180px;">
                                        <div class="progress-bar">
                                            <div class="progress-fill" style="width: <?php echo $dept['avg_progress']; ?>%; background: var(--primary);"></div>
                                        </div>
                                        <div style="text-align: right; margin-top: 4px; font-size: 13px;"><?php echo $dept['avg_progress']; ?>% avg • <?php echo $dept['completed_goals']; ?>/<?php echo $dept['total_goals']; ?> completed</div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <div class="empty-state"><i class="fas fa-building"></i>
                                <p>No department data</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="card">
                    <div class="card-header">
                        <h3><i class="fas fa-chart-pie"></i> Goal Status Distribution</h3>
                    </div>
                    <div class="card-body">
                        <?php
                        $total = $total_assigned;
                        $statuses = ['pending' => $pending_count, 'in_progress' => $in_progress_count, 'completed' => $completed_count, 'overdue' => $overdue_count];
                        $labels = ['Pending', 'In Progress', 'Completed', 'Overdue'];
                        $colors = ['var(--warning)', 'var(--info)', 'var(--success)', 'var(--danger)'];
                        foreach ($statuses as $key => $count):
                            $perc = $total > 0 ? round(($count / $total) * 100) : 0;
                        ?>
                            <div class="status-item status-<?php echo $key; ?>">
                                <div class="status-count"><?php echo $count; ?></div>
                                <div class="status-label"><?php echo $labels[array_search($key, array_keys($statuses))]; ?></div>
                                <div class="status-bar">
                                    <div class="status-fill" style="width: <?php echo $perc; ?>%; background: <?php echo $colors[array_search($key, array_keys($statuses))]; ?>;"></div>
                                </div>
                                <div class="status-percentage"><?php echo $perc; ?>%</div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>

            <h3 style="margin-bottom: 20px;">Quick Actions</h3>
            <div class="quick-actions-grid">
                <a href="students.php" class="quick-action"><i class="fas fa-users"></i> Manage Students</a>
                <a href="goals.php" class="quick-action"><i class="fas fa-bullseye"></i> System Goals</a>
                <a href="assign_goals.php" class="quick-action"><i class="fas fa-tasks"></i> Assign Goals</a>
                <a href="achievements.php" class="quick-action"><i class="fas fa-trophy"></i> Achievements</a>
                <a href="categories.php" class="quick-action"><i class="fas fa-tags"></i> Categories</a>
                <a href="reports.php" class="quick-action"><i class="fas fa-chart-bar"></i> Reports</a>
            </div>

            <div class="card" style="margin-top: 40px;">
                <div class="card-header">
                    <h3><i class="fas fa-server"></i> System Status</h3>
                </div>
                <div class="card-body">
                    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 20px;">
                        <div style="text-align: center; padding: 20px; background: var(--success-light); border-radius: 10px;">
                            <div style="font-size: 28px; font-weight: 700; color: var(--success);">100%</div>
                            <div style="font-size: 14px; color: var(--gray-500);">System Uptime</div>
                        </div>
                        <div style="text-align: center; padding: 20px; background: var(--info-light); border-radius: 10px;">
                            <div style="font-size: 28px; font-weight: 700; color: var(--info);"><?php echo $current_date; ?></div>
                            <div style="font-size: 14px; color: var(--gray-500);">Current Date</div>
                        </div>
                        <div style="text-align: center; padding: 20px; background: var(--purple-light); border-radius: 10px;">
                            <div style="font-size: 28px; font-weight: 700; color: var(--purple);">v1.0.0</div>
                            <div style="font-size: 14px; color: var(--gray-500);">Version</div>
                        </div>
                    </div>
                </div>
            </div>
        </main>
    </div>

    <script>
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

        const observer = new IntersectionObserver((entries) => {
            entries.forEach(entry => {
                if (entry.isIntersecting) {
                    entry.target.querySelectorAll('.progress-fill, .status-fill').forEach(bar => {
                        const width = bar.style.width || bar.dataset.width + '%';
                        bar.style.width = '0%';
                        setTimeout(() => bar.style.width = width, 100);
                    });
                }
            });
        }, { threshold: 0.3 });

        document.querySelectorAll('.card').forEach(card => observer.observe(card));
    </script>
</body>
</html>
