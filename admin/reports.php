<?php
session_start();
require_once '../includes/db_connection.php';
require_once '../includes/functions.php';
require_once '../tcpdf/tcpdf.php';
checkAuth('admin');

$success = $_SESSION['success'] ?? '';
$error   = $_SESSION['error']   ?? '';
unset($_SESSION['success'], $_SESSION['error']);

// Filter parameters
$search     = trim($_GET['search']     ?? '');
$department = trim($_GET['department'] ?? '');
$status     = $_GET['status']          ?? 'all';
$student_id = (int)($_GET['student_id'] ?? 0);

// Unique departments
try {
    $dept_stmt = $pdo->prepare("
        SELECT DISTINCT department
        FROM users
        WHERE role = 'student' AND department IS NOT NULL AND department != ''
        ORDER BY department
    ");
    $dept_stmt->execute();
    $departments = $dept_stmt->fetchAll(PDO::FETCH_COLUMN);
} catch (Exception $e) {
    $departments = [];
}

// Overall stats
$total_students  = getStat($pdo, "SELECT COUNT(*) FROM users WHERE role = 'student'");
$active_students = getStat($pdo, "SELECT COUNT(*) FROM users WHERE role = 'student' AND status = 'active'");
$total_goals     = getStat($pdo, "SELECT COUNT(*) FROM admin_goals WHERE status = 'active'");
$assigned_goals  = getStat($pdo, "SELECT COUNT(*) FROM student_goals");
$completed_goals = getStat($pdo, "SELECT COUNT(*) FROM student_goals WHERE status = 'completed'");
$total_points    = getStat($pdo, "
    SELECT COALESCE(SUM(a.points), 0)
    FROM user_achievements ua
    JOIN achievements a ON ua.achievement_id = a.id
    WHERE ua.deleted_at IS NULL
");

// Department stats
$dept_query  = "
    SELECT
        u.department,
        COUNT(DISTINCT u.id)                                          AS total_students,
        COUNT(sg.id)                                                  AS total_assigned,
        SUM(CASE WHEN sg.status = 'completed' THEN 1 ELSE 0 END)    AS completed_goals,
        COALESCE(AVG(sg.progress_percentage), 0)                     AS avg_progress,
        COALESCE(SUM(a.points), 0)                                   AS total_points
    FROM users u
    LEFT JOIN student_goals sg      ON u.id = sg.student_id
    LEFT JOIN user_achievements ua  ON u.id = ua.user_id
    LEFT JOIN achievements a        ON ua.achievement_id = a.id
    WHERE u.role = 'student'
";
$dept_params = [];
if ($department) { $dept_query .= " AND u.department = ?"; $dept_params[] = $department; }
if ($status !== 'all') { $dept_query .= " AND u.status = ?"; $dept_params[] = $status; }
$dept_query .= " GROUP BY u.department ORDER BY avg_progress DESC";

$dept_stmt       = $pdo->prepare($dept_query);
$dept_stmt->execute($dept_params);
$department_stats = $dept_stmt->fetchAll(PDO::FETCH_ASSOC);

// Top students
$top_query  = "
    SELECT
        u.id,
        u.name,
        u.department,
        COUNT(sg.id)                                                  AS total_goals,
        SUM(CASE WHEN sg.status = 'completed' THEN 1 ELSE 0 END)    AS completed_goals,
        COALESCE(AVG(sg.progress_percentage), 0)                     AS avg_progress,
        COALESCE(SUM(a.points), 0)                                   AS total_points
    FROM users u
    LEFT JOIN student_goals sg      ON u.id = sg.student_id
    LEFT JOIN user_achievements ua  ON u.id = ua.user_id
    LEFT JOIN achievements a        ON ua.achievement_id = a.id
    WHERE u.role = 'student'
";
$top_params = [];
if ($search)     { $top_query .= " AND (u.name LIKE ? OR u.email LIKE ?)"; $top_params[] = "%$search%"; $top_params[] = "%$search%"; }
if ($department) { $top_query .= " AND u.department = ?";  $top_params[] = $department; }
if ($status !== 'all') { $top_query .= " AND u.status = ?"; $top_params[] = $status; }
$top_query .= " GROUP BY u.id ORDER BY completed_goals DESC, total_points DESC LIMIT 10";

$top_stmt    = $pdo->prepare($top_query);
$top_stmt->execute($top_params);
$top_students = $top_stmt->fetchAll(PDO::FETCH_ASSOC);

// Individual student report
$student_report = null;
if ($student_id > 0) {
    $student_stmt = $pdo->prepare("
        SELECT
            u.*,
            COUNT(sg.id)                                                  AS total_goals,
            SUM(CASE WHEN sg.status = 'completed' THEN 1 ELSE 0 END)    AS completed_goals,
            COALESCE(AVG(sg.progress_percentage), 0)                     AS avg_progress,
            COALESCE(SUM(a.points), 0)                                   AS total_points
        FROM users u
        LEFT JOIN student_goals sg      ON u.id = sg.student_id
        LEFT JOIN user_achievements ua  ON u.id = ua.user_id
        LEFT JOIN achievements a        ON ua.achievement_id = a.id
        WHERE u.id = ? AND u.role = 'student'
        GROUP BY u.id
    ");
    $student_stmt->execute([$student_id]);
    $student_report = $student_stmt->fetch(PDO::FETCH_ASSOC);

    if ($student_report) {
        $goals_stmt = $pdo->prepare("
            SELECT sg.*, ag.title AS goal_title, ag.unit
            FROM student_goals sg
            JOIN admin_goals ag ON sg.goal_id = ag.id
            WHERE sg.student_id = ?
            ORDER BY sg.due_date ASC
        ");
        $goals_stmt->execute([$student_id]);
        $student_report['goals'] = $goals_stmt->fetchAll(PDO::FETCH_ASSOC);

        $ach_stmt = $pdo->prepare("
            SELECT a.title, a.description, a.points, a.icon, a.color, ua.earned_at
            FROM user_achievements ua
            JOIN achievements a ON ua.achievement_id = a.id
            WHERE ua.user_id = ? AND ua.deleted_at IS NULL
            ORDER BY ua.earned_at DESC
        ");
        $ach_stmt->execute([$student_id]);
        $student_report['achievements'] = $ach_stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}

// Sidebar helpers
$students_count   = $total_students;
$goals_count      = $total_goals;
$assigned_count   = $assigned_goals;
$points_count     = $total_points;
$pending_requests = 0;
try {
    $pending_requests = (int)$pdo->query("SELECT COUNT(*) FROM progress_requests WHERE status='pending'")->fetchColumn();
} catch (Exception $e) { $pending_requests = 0; }

$current = basename($_SERVER['PHP_SELF']);

// ── Overall PDF Export ──────────────────────────────────────────────────────
if (isset($_GET['export']) && $_GET['export'] === 'pdf') {
    $pdf = new TCPDF(PDF_PAGE_ORIENTATION, PDF_UNIT, PDF_PAGE_FORMAT, true, 'UTF-8', false);
    $pdf->SetCreator('ProgressMate'); $pdf->SetAuthor('Admin'); $pdf->SetTitle('ProgressMate System Report');
    $pdf->setPrintHeader(false); $pdf->setPrintFooter(false);
    $pdf->SetMargins(15, 20, 15); $pdf->SetAutoPageBreak(true, 20);
    $pdf->AddPage();
    $pdf->SetFont('helvetica','B',20); $pdf->Cell(0,15,'ProgressMate System Report',0,1,'C'); $pdf->Ln(10);
    $pdf->SetFont('helvetica','B',14); $pdf->Cell(0,10,'Overall Statistics',0,1);
    $pdf->SetFont('helvetica','',12);
    foreach ([["Total Students",$total_students],["Active Students",$active_students],["System Goals",$total_goals],
              ["Assigned Goals",$assigned_goals],["Completed Goals",$completed_goals],["Total Points Awarded",$total_points]] as $r) {
        $pdf->Cell(0,8,$r[0].': '.$r[1],0,1);
    }
    $pdf->Ln(10);
    $pdf->SetFont('helvetica','B',14); $pdf->Cell(0,10,'Department Statistics',0,1);
    $pdf->SetFont('helvetica','B',11); $pdf->SetFillColor(240,240,240);
    foreach ([['Department',50],['Students',25],['Assigned',30],['Completed',30],['Avg Progress',30],['Points',25]] as $h) {
        $pdf->Cell($h[1],10,$h[0],1,0,'C',true);
    } $pdf->Ln();
    $pdf->SetFont('helvetica','',11);
    foreach ($department_stats as $dept) {
        $pdf->Cell(50,10,$dept['department']??'Unassigned',1);
        $pdf->Cell(25,10,$dept['total_students'],1,0,'C');
        $pdf->Cell(30,10,$dept['total_assigned'],1,0,'C');
        $pdf->Cell(30,10,$dept['completed_goals'],1,0,'C');
        $pdf->Cell(30,10,round($dept['avg_progress'],1).'%',1,0,'C');
        $pdf->Cell(25,10,$dept['total_points']??0,1,1,'C');
    }
    $pdf->Ln(10);
    $pdf->SetFont('helvetica','B',14); $pdf->Cell(0,10,'Top 10 Students',0,1);
    $pdf->SetFont('helvetica','B',11); $pdf->SetFillColor(240,240,240);
    foreach ([['Name',60],['Department',40],['Completed/Total',35],['Avg Progress',30],['Points',25]] as $h) {
        $pdf->Cell($h[1],10,$h[0],1,0,'C',true);
    } $pdf->Ln();
    $pdf->SetFont('helvetica','',11);
    foreach ($top_students as $s) {
        $pdf->Cell(60,10,$s['name'],1);
        $pdf->Cell(40,10,$s['department']??'N/A',1);
        $pdf->Cell(35,10,$s['completed_goals'].'/'.$s['total_goals'],1,0,'C');
        $pdf->Cell(30,10,round($s['avg_progress'],1).'%',1,0,'C');
        $pdf->Cell(25,10,$s['total_points'],1,1,'C');
    }
    $pdf->Output('progressmate_report_'.date('Y-m-d').'.pdf','D'); exit;
}

// ── Individual Student PDF Export ───────────────────────────────────────────
if (isset($_GET['export_student']) && $_GET['export_student'] === 'pdf' && $student_report) {
    $pdf = new TCPDF(PDF_PAGE_ORIENTATION, PDF_UNIT, PDF_PAGE_FORMAT, true, 'UTF-8', false);
    $pdf->SetCreator('ProgressMate'); $pdf->SetAuthor('Admin'); $pdf->SetTitle('Student Report - '.$student_report['name']);
    $pdf->setPrintHeader(false); $pdf->setPrintFooter(false);
    $pdf->SetMargins(15, 20, 15); $pdf->SetAutoPageBreak(true, 20);
    $pdf->AddPage();
    $pdf->SetFont('helvetica','B',20); $pdf->Cell(0,15,'Student Report: '.$student_report['name'],0,1,'C'); $pdf->Ln(10);
    $pdf->SetFont('helvetica','B',14); $pdf->Cell(0,10,'Student Information',0,1);
    $pdf->SetFont('helvetica','',12);
    $pdf->Cell(0,8,'Email: '.$student_report['email'],0,1);
    $pdf->Cell(0,8,'Department: '.($student_report['department']??'N/A'),0,1);
    $pdf->Cell(0,8,'Status: '.ucfirst($student_report['status']),0,1); $pdf->Ln(10);
    $pdf->SetFont('helvetica','B',14); $pdf->Cell(0,10,'Performance Statistics',0,1);
    $pdf->SetFont('helvetica','',12);
    $pdf->Cell(0,8,'Total Goals: '.$student_report['total_goals'],0,1);
    $pdf->Cell(0,8,'Completed Goals: '.$student_report['completed_goals'],0,1);
    $pdf->Cell(0,8,'Average Progress: '.round($student_report['avg_progress'],1).'%',0,1);
    $pdf->Cell(0,8,'Total Points: '.$student_report['total_points'],0,1);
    $pdf->Cell(0,8,'Achievements Unlocked: '.count($student_report['achievements']),0,1); $pdf->Ln(10);
    $pdf->SetFont('helvetica','B',14); $pdf->Cell(0,10,'Assigned Goals',0,1);
    $pdf->SetFont('helvetica','B',11); $pdf->SetFillColor(240,240,240);
    foreach ([['Goal Title',80],['Status',30],['Progress',30],['Due Date',40]] as $h) {
        $pdf->Cell($h[1],10,$h[0],1,0,'C',true);
    } $pdf->Ln();
    $pdf->SetFont('helvetica','',11);
    foreach ($student_report['goals'] as $goal) {
        $pdf->Cell(80,10,$goal['goal_title'],1);
        $pdf->Cell(30,10,ucfirst($goal['status']),1,0,'C');
        $pdf->Cell(30,10,$goal['progress_percentage'].'%',1,0,'C');
        $pdf->Cell(40,10,$goal['due_date']?date('M d, Y',strtotime($goal['due_date'])):'No due date',1,1);
    } $pdf->Ln(10);
    $pdf->SetFont('helvetica','B',14); $pdf->Cell(0,10,'Unlocked Achievements',0,1);
    $pdf->SetFont('helvetica','B',11); $pdf->SetFillColor(240,240,240);
    foreach ([['Title',80],['Description',60],['Points',20],['Earned On',40]] as $h) {
        $pdf->Cell($h[1],10,$h[0],1,0,'C',true);
    } $pdf->Ln();
    $pdf->SetFont('helvetica','',11);
    foreach ($student_report['achievements'] as $ach) {
        $pdf->Cell(80,10,$ach['title'],1);
        $pdf->Cell(60,10,substr($ach['description'],0,50).(strlen($ach['description'])>50?'...':''),1);
        $pdf->Cell(20,10,$ach['points'],1,0,'C');
        $pdf->Cell(40,10,date('M d, Y',strtotime($ach['earned_at'])),1,1);
    }
    $pdf->Output('student_report_'.$student_report['id'].'_'.date('Y-m-d').'.pdf','D'); exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Reports — ProgressMate</title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<style>
/* ============================================================
   DESIGN TOKENS
============================================================ */
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
a{ color:inherit; text-decoration:none; }
img{ max-width:100%; display:block; }

/* ---------- Mobile Toggle + Overlay ---------- */
.mobile-toggle{
  position:fixed; top:16px; left:16px; z-index:2000;
  width:44px; height:44px;
  display:none; place-items:center;
  border-radius:14px; border:1px solid var(--border);
  background:rgba(10,14,35,.60); color:var(--text);
  box-shadow:var(--shadow2); backdrop-filter:blur(12px); cursor:pointer;
}
.mobile-toggle i{ font-size:18px; }
.sidebar-overlay{
  position:fixed; inset:0;
  background:rgba(2,6,23,.55);
  opacity:0; pointer-events:none;
  transition:opacity .25s ease; z-index:1500;
}
.sidebar-overlay.active{ opacity:1; pointer-events:auto; }

/* ---------- Layout ---------- */
.dashboard-wrapper{
  display:grid;
  grid-template-columns:320px 1fr;
  min-height:100vh;
}

/* ======================================================
   SIDEBAR
====================================================== */
.sidebar{
  position:sticky; top:0; height:100vh; overflow:hidden;
  display:flex; flex-direction:column;
  padding:18px 16px 16px;
  background:
    radial-gradient(700px 320px at 20% 0%, rgba(124,58,237,.22), transparent 60%),
    radial-gradient(520px 300px at 100% 20%, rgba(34,211,238,.15), transparent 60%),
    linear-gradient(180deg, rgba(10,14,35,.85), rgba(10,14,35,.62));
  border-right:1px solid rgba(255,255,255,.10);
  backdrop-filter:blur(16px);
  box-shadow:0 10px 50px rgba(0,0,0,.25);
}
.sidebar::before{
  content:""; position:absolute; inset:-2px;
  background:linear-gradient(120deg, rgba(124,58,237,.22), rgba(34,211,238,.16), rgba(251,113,133,.14));
  opacity:.22; filter:blur(26px); pointer-events:none; z-index:0;
}
.sidebar-header,.user-profile,.nav-menu,.sidebar-quick-stats,.sidebar-footer{
  position:relative; z-index:2;
}

.sidebar-header{
  display:flex; align-items:center; justify-content:space-between;
  padding:10px 10px 14px; flex:0 0 auto;
}
.logo{
  display:flex; align-items:center; gap:10px;
  font-weight:950; letter-spacing:.2px; font-size:18px;
}
.logo i{
  width:34px; height:34px; display:grid; place-items:center;
  border-radius:12px;
  background:
    radial-gradient(120% 140% at 30% 25%, rgba(255,255,255,.18), transparent 55%),
    linear-gradient(135deg, rgba(124,58,237,.70), rgba(34,211,238,.35));
  border:1px solid rgba(255,255,255,.18);
  box-shadow:0 14px 30px rgba(124,58,237,.18);
}
.sidebar-close{
  display:none; width:40px; height:40px; border-radius:14px;
  border:1px solid rgba(255,255,255,.14); background:rgba(255,255,255,.06);
  color:var(--text); cursor:pointer;
}

.user-profile{
  display:flex; gap:12px; padding:14px 12px;
  border-radius:var(--r16); border:1px solid var(--border2);
  background:
    radial-gradient(140% 180% at 10% 0%, rgba(255,255,255,.10), transparent 60%),
    linear-gradient(180deg, rgba(255,255,255,.05), rgba(255,255,255,.03));
  box-shadow:0 12px 26px rgba(0,0,0,.18); flex:0 0 auto;
}
.profile-pic{
  width:52px; height:52px; border-radius:16px; object-fit:cover;
  border:1px solid rgba(255,255,255,.16); box-shadow:0 10px 20px rgba(0,0,0,.22);
}
.profile-pic.default{
  display:grid; place-items:center; font-weight:950; font-size:18px;
  background:
    radial-gradient(120% 140% at 30% 25%, rgba(255,255,255,.18), transparent 55%),
    linear-gradient(135deg, rgba(34,211,238,.55), rgba(124,58,237,.55));
}
.user-info h4{ margin:2px 0 2px; font-size:15px; font-weight:950; }
.user-info p{
  margin:0; font-size:12.5px; color:var(--muted);
  overflow:hidden; text-overflow:ellipsis; white-space:nowrap; max-width:210px;
}

.nav-menu{
  flex:1 1 auto; overflow-y:auto; overflow-x:hidden;
  padding:14px 6px 10px; margin-top:10px;
  display:flex; flex-direction:column; gap:6px;
  scrollbar-width:thin; scrollbar-color:rgba(255,255,255,.18) transparent;
}
.nav-menu::-webkit-scrollbar{ width:8px; }
.nav-menu::-webkit-scrollbar-thumb{ background:rgba(255,255,255,.16); border-radius:99px; }
.nav-menu::-webkit-scrollbar-thumb:hover{ background:rgba(255,255,255,.22); }

.nav-link{
  display:flex; align-items:center; gap:12px;
  padding:12px 12px; border-radius:14px;
  color:rgba(234,240,255,.92); border:1px solid transparent;
  transition:transform .18s ease, background .18s ease, border-color .18s ease, box-shadow .18s ease;
  min-height:46px;
}
.nav-link i{
  width:34px; height:34px; display:grid; place-items:center;
  border-radius:12px; background:rgba(255,255,255,.05);
  border:1px solid rgba(255,255,255,.10);
}
.nav-link:hover{
  background:rgba(255,255,255,.06); border-color:rgba(255,255,255,.12);
  transform:translateX(2px);
}
.nav-link.active{
  background:
    radial-gradient(120% 160% at 10% 20%, rgba(255,255,255,.14), transparent 55%),
    linear-gradient(135deg, rgba(124,58,237,.55), rgba(34,211,238,.20));
  border-color:rgba(255,255,255,.18);
  box-shadow:0 18px 40px rgba(124,58,237,.20);
}
.badge{
  margin-left:auto; font-size:12px; font-weight:950;
  padding:4px 10px; border-radius:999px; color:var(--text);
  background:
    radial-gradient(120% 180% at 20% 20%, rgba(255,255,255,.20), transparent 55%),
    linear-gradient(135deg, rgba(251,113,133,.70), rgba(124,58,237,.45));
  border:1px solid rgba(255,255,255,.18);
}

.sidebar-quick-stats{
  flex:0 0 auto; margin-top:10px; padding:10px;
  border-radius:var(--r16); border:1px solid rgba(255,255,255,.10);
  background:rgba(255,255,255,.03);
}
.sidebar-stat{
  display:flex; gap:12px; align-items:center;
  padding:10px; border-radius:14px; transition:background .18s ease;
}
.sidebar-stat:hover{ background:rgba(255,255,255,.04); }
.sidebar-stat-icon{
  width:38px; height:38px; border-radius:14px;
  display:grid; place-items:center;
  border:1px solid rgba(255,255,255,.12);
  background:
    radial-gradient(120% 180% at 20% 10%, rgba(255,255,255,.18), transparent 55%),
    linear-gradient(135deg, rgba(34,211,238,.35), rgba(124,58,237,.35));
  box-shadow:0 14px 26px rgba(0,0,0,.18);
}
.sidebar-stat-label{ font-size:12px; color:var(--muted); }
.sidebar-stat-number{ font-size:18px; font-weight:950; }

.sidebar-footer{ flex:0 0 auto; margin-top:12px; }
.logout-btn{
  display:flex; align-items:center; justify-content:center;
  gap:10px; padding:12px 12px; border-radius:14px;
  border:1px solid rgba(255,255,255,.14);
  background:
    radial-gradient(140% 180% at 20% 0%, rgba(255,255,255,.10), transparent 60%),
    linear-gradient(135deg, rgba(251,113,133,.20), rgba(255,255,255,.03));
  box-shadow:0 14px 26px rgba(0,0,0,.16);
  transition:transform .18s ease, box-shadow .18s ease;
}
.logout-btn:hover{
  transform:translateY(-1px);
  box-shadow:0 18px 34px rgba(251,113,133,.18);
}

/* ======================================================
   MAIN CONTENT
====================================================== */
.main-content{ padding:26px 26px 44px; }

.page-header{
  display:flex; align-items:flex-start; justify-content:space-between;
  gap:16px; padding:18px;
  border-radius:var(--r20); border:1px solid var(--border);
  background:
    radial-gradient(120% 220% at 15% 10%, rgba(255,255,255,.10), transparent 55%),
    linear-gradient(180deg, rgba(255,255,255,.05), rgba(255,255,255,.03));
  box-shadow:var(--shadow2);
}
.header-content h1{ margin:0 0 6px; font-size:26px; font-weight:950; }
.header-content p{ margin:0; color:var(--muted); font-size:14px; }

/* ======================================================
   BUTTONS
====================================================== */
.btn{
  display:inline-flex; align-items:center; justify-content:center;
  gap:10px; padding:12px 14px; border-radius:14px;
  font-weight:950; border:1px solid rgba(255,255,255,.14);
  color:var(--text); background:rgba(255,255,255,.05);
  cursor:pointer; white-space:nowrap;
  transition:transform .18s ease, box-shadow .18s ease, background .18s ease, border-color .18s ease;
}
.btn:hover{
  transform:translateY(-1px); background:rgba(255,255,255,.07);
  box-shadow:0 0 0 1px rgba(255,255,255,.08), 0 12px 30px rgba(124,58,237,.18);
}
.btn-primary{
  border-color:rgba(124,58,237,.35);
  background:
    radial-gradient(120% 180% at 20% 10%, rgba(255,255,255,.16), transparent 55%),
    linear-gradient(135deg, rgba(124,58,237,.62), rgba(34,211,238,.18));
}
.btn-outline{ background:rgba(255,255,255,.03); }
.btn-sm{ padding:8px 10px; border-radius:12px; font-size:12px; font-weight:950; }

/* ======================================================
   ALERTS
====================================================== */
.alert{
  margin-top:14px; padding:12px 14px; border-radius:14px;
  border:1px solid rgba(255,255,255,.12); background:rgba(255,255,255,.04);
  display:flex; align-items:center; gap:10px;
}
.alert-success{ border-color:rgba(52,211,153,.25); background:rgba(52,211,153,.10); color:var(--text); }
.alert-error  { border-color:rgba(251,113,133,.25); background:rgba(251,113,133,.10); color:var(--text); }

/* ======================================================
   STATS GRID
====================================================== */
.stats-grid{
  margin-top:18px;
  display:grid;
  grid-template-columns:repeat(6, minmax(0,1fr));
  gap:14px;
}
.stat-card{
  border-radius:var(--r20); border:1px solid rgba(255,255,255,.12);
  background:
    radial-gradient(120% 180% at 10% 0%, rgba(255,255,255,.12), transparent 60%),
    linear-gradient(180deg, rgba(255,255,255,.05), rgba(255,255,255,.03));
  box-shadow:var(--shadow2); padding:18px 16px;
  transition:transform .18s ease, box-shadow .18s ease;
}
.stat-card:hover{
  transform:translateY(-2px);
  box-shadow:0 0 0 1px rgba(255,255,255,.08), 0 18px 45px rgba(124,58,237,.18);
}
.stat-card .stat-icon{
  width:44px; height:44px; border-radius:14px;
  display:grid; place-items:center; margin-bottom:12px;
  border:1px solid rgba(255,255,255,.12); font-size:20px;
}
.stat-card:nth-child(1) .stat-icon{ background:rgba(34,211,238,.12);  color:var(--cyan);    border-color:rgba(34,211,238,.22); }
.stat-card:nth-child(2) .stat-icon{ background:rgba(52,211,153,.12);  color:var(--success); border-color:rgba(52,211,153,.22); }
.stat-card:nth-child(3) .stat-icon{ background:rgba(251,191,36,.12);  color:var(--warning); border-color:rgba(251,191,36,.22); }
.stat-card:nth-child(4) .stat-icon{ background:rgba(124,58,237,.12);  color:#a78bfa;        border-color:rgba(124,58,237,.22); }
.stat-card:nth-child(5) .stat-icon{ background:rgba(52,211,153,.12);  color:var(--success); border-color:rgba(52,211,153,.22); }
.stat-card:nth-child(6) .stat-icon{ background:rgba(251,191,36,.12);  color:var(--warning); border-color:rgba(251,191,36,.22); }
.stat-card .stat-number{
  font-size:26px; font-weight:950; letter-spacing:.2px;
}
.stat-card .stat-label{
  margin-top:4px; font-size:13px; color:var(--muted); font-weight:800;
}

/* ======================================================
   FILTERS SECTION
====================================================== */
.filters-section{
  margin-top:20px; border-radius:var(--r20);
  border:1px solid rgba(255,255,255,.10); background:rgba(255,255,255,.04);
  box-shadow:var(--shadow2); padding:18px;
}
.filters-section h3{
  font-size:15px; font-weight:950; color:var(--text);
  margin:0 0 14px; display:flex; align-items:center; gap:10px;
}
.filters-section h3 i{ color:var(--cyan); }
.filter-row{
  display:grid;
  grid-template-columns:1.4fr 1fr 1fr 1.6fr auto;
  gap:12px; align-items:end;
}
.filter-group label{
  display:block; font-size:12px; color:var(--muted);
  margin-bottom:6px; font-weight:900;
}
.filter-group input,
.filter-group select{
  width:100%; padding:11px 12px; border-radius:12px;
  border:1px solid rgba(255,255,255,.12);
  background:rgba(10,14,35,.45); color:var(--text); outline:none;
  font-size:14px;
}
.filter-group input::placeholder{ color:rgba(234,240,255,.45); }
.filter-group input:focus,
.filter-group select:focus{
  border-color:rgba(34,211,238,.30);
  box-shadow:0 0 0 3px rgba(34,211,238,.16);
}

/* ======================================================
   REPORT CARDS
====================================================== */
.report-card{
  margin-top:24px; border-radius:var(--r20);
  border:1px solid rgba(255,255,255,.10); background:rgba(255,255,255,.035);
  box-shadow:var(--shadow); overflow:hidden;
}
.report-card-header{
  display:flex; align-items:center; justify-content:space-between;
  padding:16px 20px; border-bottom:1px solid rgba(255,255,255,.08);
  background:rgba(255,255,255,.04);
}
.report-card-header h3{
  margin:0; font-size:16px; font-weight:950;
  display:flex; align-items:center; gap:10px;
}
.report-card-header h3 i{ color:var(--cyan); }
.report-card-body{ padding:20px; }

/* section subheadings inside student report */
.report-card-body h4{
  font-size:14px; font-weight:950; color:var(--muted);
  text-transform:uppercase; letter-spacing:.4px;
  margin:24px 0 12px; padding-bottom:8px;
  border-bottom:1px solid rgba(255,255,255,.08);
}
.report-card-body h4:first-child{ margin-top:0; }

/* ======================================================
   TABLES
====================================================== */
.table-wrap{
  border-radius:var(--r16); overflow:hidden;
  border:1px solid rgba(255,255,255,.08);
}
table{ width:100%; border-collapse:collapse; }
thead th{
  text-align:left; font-size:12px; letter-spacing:.25px;
  text-transform:uppercase; color:rgba(234,240,255,.75);
  background:rgba(255,255,255,.04);
  border-bottom:1px solid rgba(255,255,255,.08);
  padding:14px 16px;
}
tbody td{
  padding:14px 16px; border-bottom:1px solid rgba(255,255,255,.06);
  vertical-align:middle; color:var(--text); font-size:14px;
}
tbody tr:hover{ background:rgba(255,255,255,.03); }
tbody tr:last-child td{ border-bottom:none; }

/* ======================================================
   PROGRESS BAR (inline table cell)
====================================================== */
.progress-cell{ display:flex; align-items:center; gap:10px; }
.progress-bar{
  flex:1; height:8px; border-radius:999px;
  background:rgba(255,255,255,.07); border:1px solid rgba(255,255,255,.08); overflow:hidden;
}
.progress-fill{
  height:100%; width:0; border-radius:999px;
  background:linear-gradient(90deg, rgba(34,211,238,.95), rgba(124,58,237,.95));
  box-shadow:0 10px 25px rgba(34,211,238,.14);
  transition:width 1s cubic-bezier(.22,.75,.12,1);
}
.progress-pct{ font-size:13px; font-weight:950; white-space:nowrap; color:var(--muted); min-width:36px; text-align:right; }

/* status badges */
.status-badge{
  display:inline-flex; align-items:center; justify-content:center;
  padding:5px 10px; border-radius:999px;
  font-size:12px; font-weight:950; border:1px solid rgba(255,255,255,.12);
}
.status-completed{ color:rgba(52,211,153,1); border-color:rgba(52,211,153,.25); background:rgba(52,211,153,.10); }
.status-active   { color:rgba(52,211,153,1); border-color:rgba(52,211,153,.25); background:rgba(52,211,153,.10); }
.status-inactive { color:rgba(251,191,36,1); border-color:rgba(251,191,36,.25); background:rgba(251,191,36,.10); }
.status-pending  { color:rgba(34,211,238,1); border-color:rgba(34,211,238,.25); background:rgba(34,211,238,.10); }
.status-overdue  { color:rgba(251,113,133,1);border-color:rgba(251,113,133,.25);background:rgba(251,113,133,.10); }

/* rank badge (top students) */
.rank-badge{
  display:inline-grid; place-items:center;
  width:28px; height:28px; border-radius:10px;
  font-size:13px; font-weight:950;
  border:1px solid rgba(255,255,255,.14);
}
.rank-1{ background:rgba(251,191,36,.18); color:#fbbf24; border-color:rgba(251,191,36,.28); }
.rank-2{ background:rgba(148,163,184,.14); color:#94a3b8; border-color:rgba(148,163,184,.24); }
.rank-3{ background:rgba(249,115,22,.14);  color:#f97316; border-color:rgba(249,115,22,.22); }

/* ======================================================
   EMPTY STATE
====================================================== */
.empty-state{
  text-align:center; padding:40px 16px; color:var(--muted);
}
.empty-state i{
  display:inline-grid; place-items:center;
  width:56px; height:56px; border-radius:18px;
  margin-bottom:10px; border:1px solid rgba(255,255,255,.12);
  background:rgba(255,255,255,.05); font-size:22px;
}

/* ======================================================
   RESPONSIVE
====================================================== */
@media (max-width:1280px){
  .stats-grid{ grid-template-columns:repeat(3, minmax(0,1fr)); }
  .filter-row{ grid-template-columns:1fr 1fr 1fr; }
  .filter-row .btn{ grid-column:span 3; justify-self:start; }
}
@media (max-width:980px){
  .stats-grid{ grid-template-columns:repeat(2, minmax(0,1fr)); }
  .filter-row{ grid-template-columns:1fr 1fr; }
  .filter-row .btn{ grid-column:span 2; }
}
@media (max-width:860px){
  .dashboard-wrapper{ grid-template-columns:1fr; }
  .mobile-toggle{ display:grid; }
  .sidebar{
    position:fixed; left:0; top:0; width:320px;
    transform:translateX(-105%); transition:transform .25s ease; z-index:1601;
  }
  .sidebar.active{ transform:translateX(0); }
  .sidebar-close{ display:grid; }
  .sidebar-overlay{ z-index:1600; }
  .main-content{ padding:70px 16px 36px; }
  .page-header{ flex-direction:column; align-items:flex-start; }
}
@media (max-width:620px){
  .stats-grid{ grid-template-columns:1fr; }
  .filter-row{ grid-template-columns:1fr; }
  .filter-row .btn{ grid-column:span 1; }
  table{ display:block; overflow-x:auto; }
}

a:focus,button:focus{ outline:none; }
a:focus-visible,button:focus-visible{
  box-shadow:0 0 0 3px rgba(34,211,238,.25), 0 0 0 1px rgba(255,255,255,.10);
  border-radius:14px;
}
</style>
</head>
<body>

<div class="dashboard-wrapper">

  <!-- ───── SIDEBAR ───── -->
  <aside class="sidebar" id="sidebar">
    <div class="sidebar-header">
      <div class="logo"><i class="fas fa-star"></i> ProgressMate</div>
      <button class="sidebar-close" id="sidebarClose" aria-label="Close menu"><i class="fas fa-times"></i></button>
    </div>

    <div class="user-profile">
      <div class="profile-pic default">A</div>
      <div class="user-info">
        <h4>Admin</h4>
        <p>Administrator</p>
      </div>
    </div>

    <nav class="nav-menu">
      <a href="admin.php"            class="nav-link <?php echo $current==='admin.php'            ?'active':''; ?>"><i class="fas fa-tachometer-alt"></i> Dashboard</a>
      <a href="students.php"         class="nav-link <?php echo $current==='students.php'         ?'active':''; ?>">
        <i class="fas fa-users"></i> Students
        <?php if($students_count>0):?><span class="badge"><?php echo $students_count;?></span><?php endif;?>
      </a>
      <a href="goals.php"            class="nav-link <?php echo $current==='goals.php'            ?'active':''; ?>">
        <i class="fas fa-bullseye"></i> All Goals
        <?php if($goals_count>0):?><span class="badge"><?php echo $goals_count;?></span><?php endif;?>
      </a>
      <a href="assign_goals.php"     class="nav-link <?php echo $current==='assign_goals.php'     ?'active':''; ?>">
        <i class="fas fa-tasks"></i> Assign Goals
        <?php if($assigned_count>0):?><span class="badge"><?php echo $assigned_count;?></span><?php endif;?>
      </a>
   
      <a href="achievements.php"     class="nav-link <?php echo $current==='achievements.php'     ?'active':''; ?>">
        <i class="fas fa-trophy"></i> Achievements
        <?php if($points_count>0):?><span class="badge"><?php echo $points_count;?> pts</span><?php endif;?>
      </a>
      <a href="reports.php"          class="nav-link <?php echo $current==='reports.php'          ?'active':''; ?>"><i class="fas fa-chart-bar"></i> Reports</a>
      <a href="notifications.php"    class="nav-link <?php echo $current==='notifications.php'    ?'active':''; ?>"><i class="fas fa-bell"></i> Notifications</a>
      <a href="settings.php"         class="nav-link <?php echo $current==='settings.php'         ?'active':''; ?>"><i class="fas fa-cog"></i> Settings</a>
    </nav>

    <div class="sidebar-quick-stats">
      <div class="sidebar-stat">
        <div class="sidebar-stat-icon"><i class="fas fa-users"></i></div>
        <div><div class="sidebar-stat-label">Students</div><div class="sidebar-stat-number"><?php echo $total_students;?></div></div>
      </div>
      <div class="sidebar-stat">
        <div class="sidebar-stat-icon"><i class="fas fa-bullseye"></i></div>
        <div><div class="sidebar-stat-label">Active Goals</div><div class="sidebar-stat-number"><?php echo $total_goals;?></div></div>
      </div>
      <div class="sidebar-stat">
        <div class="sidebar-stat-icon"><i class="fas fa-star"></i></div>
        <div><div class="sidebar-stat-label">Points Awarded</div><div class="sidebar-stat-number"><?php echo $total_points;?></div></div>
      </div>
    </div>

    <div class="sidebar-footer">
      <a href="../logout.php" class="logout-btn"><i class="fas fa-sign-out-alt"></i> Logout</a>
    </div>
  </aside>

  <!-- ───── MAIN ───── -->
  <main class="main-content">

    <!-- Page Header -->
    <div class="page-header">
      <div class="header-content">
        <h1><i class="fas fa-chart-bar" style="color:var(--cyan);margin-right:10px;"></i>Reports &amp; Analytics</h1>
        <p>System-wide and individual student performance insights</p>
      </div>
      <a href="?export=pdf" class="btn btn-primary"><i class="fas fa-file-pdf"></i> Export Overall PDF</a>
    </div>

    <!-- Alerts -->
    <?php if($success):?><div class="alert alert-success"><i class="fas fa-check-circle"></i><?php echo htmlspecialchars($success);?></div><?php endif;?>
    <?php if($error):?><div class="alert alert-error"><i class="fas fa-exclamation-circle"></i><?php echo htmlspecialchars($error);?></div><?php endif;?>

    <!-- Filters -->
    <div class="filters-section">
      <h3><i class="fas fa-filter"></i> Filters</h3>
      <form method="GET">
        <div class="filter-row">
          <div class="filter-group">
            <label>Search Student</label>
            <input type="text" name="search" placeholder="Name or email…" value="<?php echo htmlspecialchars($search);?>">
          </div>
          <div class="filter-group">
            <label>Department</label>
            <select name="department">
              <option value="">All Departments</option>
              <?php foreach($departments as $dept):?>
                <option value="<?php echo htmlspecialchars($dept);?>" <?php echo $department===$dept?'selected':'';?>><?php echo htmlspecialchars($dept);?></option>
              <?php endforeach;?>
            </select>
          </div>
          <div class="filter-group">
            <label>Status</label>
            <select name="status">
              <option value="all"      <?php echo $status==='all'     ?'selected':'';?>>All</option>
              <option value="active"   <?php echo $status==='active'  ?'selected':'';?>>Active</option>
              <option value="inactive" <?php echo $status==='inactive'?'selected':'';?>>Inactive</option>
            </select>
          </div>
          <div class="filter-group">
            <label>Individual Student Report</label>
            <select name="student_id">
              <option value="">— None —</option>
              <?php foreach($pdo->query("SELECT id, name FROM users WHERE role='student' ORDER BY name")->fetchAll() as $s):?>
                <option value="<?php echo $s['id'];?>" <?php echo $student_id==$s['id']?'selected':'';?>><?php echo htmlspecialchars($s['name']);?></option>
              <?php endforeach;?>
            </select>
          </div>
          <button type="submit" class="btn btn-primary"><i class="fas fa-search"></i> Apply</button>
        </div>
      </form>
    </div>

    <!-- Stats Overview -->
    <div class="stats-grid">
      <div class="stat-card">
        <div class="stat-icon"><i class="fas fa-users"></i></div>
        <div class="stat-number"><?php echo $total_students;?></div>
        <div class="stat-label">Total Students</div>
      </div>
      <div class="stat-card">
        <div class="stat-icon"><i class="fas fa-user-check"></i></div>
        <div class="stat-number"><?php echo $active_students;?></div>
        <div class="stat-label">Active Students</div>
      </div>
      <div class="stat-card">
        <div class="stat-icon"><i class="fas fa-bullseye"></i></div>
        <div class="stat-number"><?php echo $total_goals;?></div>
        <div class="stat-label">System Goals</div>
      </div>
      <div class="stat-card">
        <div class="stat-icon"><i class="fas fa-tasks"></i></div>
        <div class="stat-number"><?php echo $assigned_goals;?></div>
        <div class="stat-label">Assigned Goals</div>
      </div>
      <div class="stat-card">
        <div class="stat-icon"><i class="fas fa-check-circle"></i></div>
        <div class="stat-number"><?php echo $completed_goals;?></div>
        <div class="stat-label">Completed Goals</div>
      </div>
      <div class="stat-card">
        <div class="stat-icon"><i class="fas fa-star"></i></div>
        <div class="stat-number"><?php echo $total_points;?></div>
        <div class="stat-label">Points Awarded</div>
      </div>
    </div>

    <!-- Department Statistics -->
    <div class="report-card">
      <div class="report-card-header">
        <h3><i class="fas fa-building"></i> Department Statistics</h3>
      </div>
      <div class="report-card-body">
        <?php if(empty($department_stats)):?>
          <div class="empty-state"><i class="fas fa-inbox"></i><p>No department data found.</p></div>
        <?php else:?>
        <div class="table-wrap">
          <table>
            <thead>
              <tr>
                <th>Department</th>
                <th>Students</th>
                <th>Assigned</th>
                <th>Completed</th>
                <th>Avg Progress</th>
                <th>Points</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach($department_stats as $d):?>
              <tr>
                <td><strong><?php echo htmlspecialchars($d['department']??'Unassigned');?></strong></td>
                <td><?php echo $d['total_students'];?></td>
                <td><?php echo $d['total_assigned'];?></td>
                <td><?php echo $d['completed_goals'];?></td>
                <td>
                  <div class="progress-cell">
                    <div class="progress-bar"><div class="progress-fill" style="width:<?php echo min(100,round($d['avg_progress'],1));?>%"></div></div>
                    <span class="progress-pct"><?php echo round($d['avg_progress'],1);?>%</span>
                  </div>
                </td>
                <td><?php echo $d['total_points']??0;?></td>
              </tr>
              <?php endforeach;?>
            </tbody>
          </table>
        </div>
        <?php endif;?>
      </div>
    </div>

    <!-- Top 10 Students -->
    <div class="report-card">
      <div class="report-card-header">
        <h3><i class="fas fa-trophy"></i> Top 10 Students</h3>
      </div>
      <div class="report-card-body">
        <?php if(empty($top_students)):?>
          <div class="empty-state"><i class="fas fa-user-graduate"></i><p>No student data found.</p></div>
        <?php else:?>
        <div class="table-wrap">
          <table>
            <thead>
              <tr>
                <th>#</th>
                <th>Name</th>
                <th>Department</th>
                <th>Goals (Done/Total)</th>
                <th>Avg Progress</th>
                <th>Points</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach($top_students as $i=>$s):
                $rank = $i+1;
                $rankClass = $rank===1?'rank-1':($rank===2?'rank-2':($rank===3?'rank-3':''));
              ?>
              <tr>
                <td><span class="rank-badge <?php echo $rankClass;?>"><?php echo $rank;?></span></td>
                <td><strong><?php echo htmlspecialchars($s['name']);?></strong></td>
                <td><?php echo htmlspecialchars($s['department']??'N/A');?></td>
                <td>
                  <span class="status-badge status-completed"><?php echo $s['completed_goals'];?></span>
                  <span style="color:var(--muted);margin:0 4px;">/</span>
                  <?php echo $s['total_goals'];?>
                </td>
                <td>
                  <div class="progress-cell">
                    <div class="progress-bar"><div class="progress-fill" style="width:<?php echo min(100,round($s['avg_progress'],1));?>%"></div></div>
                    <span class="progress-pct"><?php echo round($s['avg_progress'],1);?>%</span>
                  </div>
                </td>
                <td><strong><?php echo $s['total_points'];?></strong></td>
              </tr>
              <?php endforeach;?>
            </tbody>
          </table>
        </div>
        <?php endif;?>
      </div>
    </div>

    <!-- Individual Student Report -->
    <?php if($student_report):?>
    <div class="report-card">
      <div class="report-card-header">
        <h3><i class="fas fa-user-graduate"></i> <?php echo htmlspecialchars($student_report['name']);?> — Individual Report</h3>
        <a href="?student_id=<?php echo $student_id;?>&export_student=pdf" class="btn btn-primary btn-sm"><i class="fas fa-file-pdf"></i> Export PDF</a>
      </div>
      <div class="report-card-body">

        <!-- Student info pills -->
        <div style="display:flex;flex-wrap:wrap;gap:10px;margin-bottom:20px;">
          <span class="status-badge status-<?php echo htmlspecialchars($student_report['status']);?>">
            <i class="fas fa-circle" style="font-size:8px;margin-right:4px;"></i><?php echo ucfirst($student_report['status']);?>
          </span>
          <span style="color:var(--muted);font-size:13px;"><i class="fas fa-envelope" style="margin-right:6px;"></i><?php echo htmlspecialchars($student_report['email']);?></span>
          <?php if($student_report['department']):?>
          <span style="color:var(--muted);font-size:13px;"><i class="fas fa-building" style="margin-right:6px;"></i><?php echo htmlspecialchars($student_report['department']);?></span>
          <?php endif;?>
        </div>

        <!-- Mini stats -->
        <div class="stats-grid" style="grid-template-columns:repeat(4,minmax(0,1fr));margin-top:0;">
          <div class="stat-card">
            <div class="stat-icon"><i class="fas fa-tasks"></i></div>
            <div class="stat-number"><?php echo $student_report['total_goals'];?></div>
            <div class="stat-label">Total Goals</div>
          </div>
          <div class="stat-card">
            <div class="stat-icon"><i class="fas fa-check-circle"></i></div>
            <div class="stat-number"><?php echo $student_report['completed_goals'];?></div>
            <div class="stat-label">Completed</div>
          </div>
          <div class="stat-card">
            <div class="stat-icon"><i class="fas fa-chart-line"></i></div>
            <div class="stat-number"><?php echo round($student_report['avg_progress'],1);?>%</div>
            <div class="stat-label">Avg Progress</div>
          </div>
          <div class="stat-card">
            <div class="stat-icon"><i class="fas fa-star"></i></div>
            <div class="stat-number"><?php echo $student_report['total_points'];?></div>
            <div class="stat-label">Points Earned</div>
          </div>
        </div>

        <!-- Goals table -->
        <h4><i class="fas fa-bullseye" style="margin-right:8px;color:var(--cyan);"></i>Assigned Goals</h4>
        <?php if(empty($student_report['goals'])):?>
          <div class="empty-state"><i class="fas fa-clipboard-list"></i><p>No goals assigned.</p></div>
        <?php else:?>
        <div class="table-wrap">
          <table>
            <thead><tr><th>Goal Title</th><th>Status</th><th>Progress</th><th>Due Date</th></tr></thead>
            <tbody>
              <?php foreach($student_report['goals'] as $g):?>
              <tr>
                <td><?php echo htmlspecialchars($g['goal_title']);?></td>
                <td><span class="status-badge status-<?php echo htmlspecialchars($g['status']);?>"><?php echo ucfirst($g['status']);?></span></td>
                <td>
                  <div class="progress-cell">
                    <div class="progress-bar"><div class="progress-fill" style="width:<?php echo min(100,(int)$g['progress_percentage']);?>%"></div></div>
                    <span class="progress-pct"><?php echo $g['progress_percentage'];?>%</span>
                  </div>
                </td>
                <td style="color:var(--muted);"><?php echo $g['due_date']?date('M d, Y',strtotime($g['due_date'])):'—';?></td>
              </tr>
              <?php endforeach;?>
            </tbody>
          </table>
        </div>
        <?php endif;?>

        <!-- Achievements table -->
        <h4><i class="fas fa-trophy" style="margin-right:8px;color:var(--warning);"></i>Achievements Unlocked <span class="badge" style="margin-left:8px;"><?php echo count($student_report['achievements']);?></span></h4>
        <?php if(empty($student_report['achievements'])):?>
          <div class="empty-state"><i class="fas fa-medal"></i><p>No achievements earned yet.</p></div>
        <?php else:?>
        <div class="table-wrap">
          <table>
            <thead><tr><th>Achievement</th><th>Description</th><th>Points</th><th>Earned On</th></tr></thead>
            <tbody>
              <?php foreach($student_report['achievements'] as $a):?>
              <tr>
                <td><strong><?php echo htmlspecialchars($a['title']);?></strong></td>
                <td style="color:var(--muted);"><?php echo htmlspecialchars($a['description']);?></td>
                <td><span class="status-badge status-completed">+<?php echo $a['points'];?></span></td>
                <td style="color:var(--muted);"><?php echo date('M d, Y',strtotime($a['earned_at']));?></td>
              </tr>
              <?php endforeach;?>
            </tbody>
          </table>
        </div>
        <?php endif;?>

      </div>
    </div>
    <?php endif;?>

  </main>
</div>

<!-- Mobile overlay -->
<div class="sidebar-overlay" id="sidebarOverlay"></div>
<!-- Mobile toggle -->
<button class="mobile-toggle" id="mobileToggle" aria-label="Open menu"><i class="fas fa-bars"></i></button>

<script>
(function(){
  const sidebar  = document.getElementById('sidebar');
  const overlay  = document.getElementById('sidebarOverlay');
  const openBtn  = document.getElementById('mobileToggle');
  const closeBtn = document.getElementById('sidebarClose');

  function open(){  sidebar.classList.add('active'); overlay.classList.add('active'); }
  function close(){ sidebar.classList.remove('active'); overlay.classList.remove('active'); }

  openBtn.addEventListener('click', open);
  closeBtn.addEventListener('click', close);
  overlay.addEventListener('click', close);

  // Animate progress bars on load
  requestAnimationFrame(()=>{
    document.querySelectorAll('.progress-fill').forEach(el=>{
      const w = el.style.width;
      el.style.width = '0';
      setTimeout(()=>{ el.style.width = w; }, 120);
    });
  });
})();
</script>

</body>
</html>
