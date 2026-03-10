<?php
session_start();
require_once '../includes/db_connection.php';
require_once '../includes/functions.php';
checkAuth('student');

$student_id = $_SESSION['user_id'];

// === Sidebar Stats ===
$stmt = $pdo->prepare("SELECT COUNT(*) FROM student_goals WHERE student_id=? AND deleted_at IS NULL"); $stmt->execute([$student_id]); $total_goals = $stmt->fetchColumn() ?: 0;
$stmt = $pdo->prepare("SELECT COUNT(*) FROM student_goals WHERE student_id=? AND status='completed' AND deleted_at IS NULL"); $stmt->execute([$student_id]); $completed_goals = $stmt->fetchColumn() ?: 0;
$stmt = $pdo->prepare("SELECT COALESCE(points,0) FROM users WHERE id=?"); $stmt->execute([$student_id]); $total_points = $stmt->fetchColumn() ?: 0;
$stmt = $pdo->prepare("SELECT COALESCE(current_streak,0) FROM users WHERE id=?"); $stmt->execute([$student_id]); $streak = $stmt->fetchColumn() ?: 0;
$stmt = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE user_id=? AND is_read=0 AND deleted_at IS NULL"); $stmt->execute([$student_id]); $unread = $stmt->fetchColumn() ?: 0;

// === Actions ===
$success = $error = '';
if (isset($_GET['read_all'])) {
    $pdo->prepare("UPDATE notifications SET is_read=1 WHERE user_id=? AND is_read=0 AND deleted_at IS NULL")->execute([$student_id]);
    header("Location: notifications.php"); exit;
}
if (isset($_GET['read'])) {
    $id = (int)$_GET['read'];
    $pdo->prepare("UPDATE notifications SET is_read=1 WHERE id=? AND user_id=?")->execute([$id,$student_id]);
    header("Location: notifications.php"); exit;
}
if (isset($_GET['delete'])) {
    $id = (int)$_GET['delete'];
    $pdo->prepare("UPDATE notifications SET deleted_at=NOW() WHERE id=? AND user_id=? AND deleted_at IS NULL")->execute([$id,$student_id]);
    header("Location: notifications.php"); exit;
}

// === Filter ===
$filter = $_GET['filter'] ?? 'all';
$allowed_filters = ['all','unread','goal','achievement','reminder'];
if (!in_array($filter, $allowed_filters)) $filter = 'all';

$per_page = 15;
$page = max(1, (int)($_GET['page'] ?? 1));
$offset = ($page-1)*$per_page;

$where = "WHERE user_id=? AND deleted_at IS NULL";
$params = [$student_id];
if ($filter === 'unread') { $where .= " AND is_read=0"; }
elseif (in_array($filter, ['goal','achievement','reminder'])) { $where .= " AND type=?"; $params[] = $filter; }

$total_stmt = $pdo->prepare("SELECT COUNT(*) FROM notifications $where");
$total_stmt->execute($params);
$total_n = $total_stmt->fetchColumn();
$total_pages = max(1, ceil($total_n / $per_page));

$stmt = $pdo->prepare("SELECT * FROM notifications $where ORDER BY created_at DESC LIMIT $per_page OFFSET $offset");
$stmt->execute($params);
$notifications = $stmt->fetchAll();

$current = basename($_SERVER['PHP_SELF']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Notifications — ProgressMate</title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<link href="https://fonts.googleapis.com/css2?family=Sora:wght@400;500;600;700;800&family=DM+Sans:ital,wght@0,400;0,500;0,600;1,400&display=swap" rel="stylesheet">
<style>
:root{
  --bg0:#070A18; --bg1:#0B1030;
  --text:#EAF0FF; --muted:rgba(234,240,255,.65); --muted2:rgba(234,240,255,.45);
  --primary:#4F46E5; --success:#34D399; --warning:#FBBF24; --danger:#FB7185; --info:#22D3EE;
  --border:rgba(255,255,255,.10); --border2:rgba(255,255,255,.08);
  --shadow:0 18px 45px rgba(0,0,0,.35); --shadow2:0 10px 30px rgba(0,0,0,.22);
  --r12:12px; --r14:14px; --r16:16px; --r20:20px; --t:.18s ease;
}
[data-theme="light"]{
  --bg0:#f0f4ff; --bg1:#e8eeff;
  --text:#1a1f3c; --muted:rgba(26,31,60,.60); --muted2:rgba(26,31,60,.40);
  --border:rgba(0,0,0,.10); --border2:rgba(0,0,0,.07);
  --shadow:0 18px 45px rgba(79,70,229,.12); --shadow2:0 10px 30px rgba(79,70,229,.08);
}
*{margin:0;padding:0;box-sizing:border-box;}
html,body{height:100%;}
body{
  font-family:'DM Sans',system-ui,sans-serif; color:var(--text);
  background:
    radial-gradient(900px 520px at 18% 10%, rgba(79,70,229,.22), transparent 60%),
    radial-gradient(900px 520px at 88% 15%, rgba(34,211,238,.18), transparent 58%),
    radial-gradient(700px 500px at 60% 90%, rgba(96,165,250,.14), transparent 62%),
    linear-gradient(180deg, var(--bg0), var(--bg1));
  overflow-x:hidden; line-height:1.55;
  transition:background .3s, color .3s;
}
a{color:inherit;text-decoration:none;}
button{font-family:inherit;cursor:pointer;border:none;background:none;}

.mobile-toggle{position:fixed;top:16px;left:16px;z-index:2000;width:44px;height:44px;display:none;place-items:center;border-radius:14px;border:1px solid var(--border);background:rgba(10,14,35,.60);color:var(--text);box-shadow:var(--shadow2);backdrop-filter:blur(12px);}
.sidebar-overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,.45);backdrop-filter:blur(2px);opacity:0;pointer-events:none;transition:opacity .2s;z-index:1600;}
.sidebar-overlay.active{opacity:1;pointer-events:auto;}
.dashboard-wrapper{display:grid;grid-template-columns:280px 1fr;min-height:100vh;}

/* SIDEBAR */
.sidebar{position:sticky;top:0;height:100vh;overflow:hidden;display:flex;flex-direction:column;padding:18px 16px 16px;background:radial-gradient(700px 320px at 20% 0%, rgba(79,70,229,.18), transparent 60%),radial-gradient(520px 300px at 100% 20%, rgba(34,211,238,.14), transparent 60%),linear-gradient(180deg,rgba(10,14,35,.85),rgba(10,14,35,.62));border-right:1px solid rgba(255,255,255,.10);backdrop-filter:blur(16px);box-shadow:0 10px 50px rgba(0,0,0,.25);position:relative;}
[data-theme="light"] .sidebar{background:radial-gradient(700px 320px at 20% 0%, rgba(79,70,229,.10), transparent 60%),rgba(240,244,255,.92);border-right:1px solid rgba(79,70,229,.15);}
.sidebar::before{content:"";position:absolute;inset:-2px;background:linear-gradient(120deg,rgba(79,70,229,.20),rgba(34,211,238,.14),rgba(96,165,250,.10));opacity:.22;filter:blur(26px);pointer-events:none;z-index:0;}
.sidebar-header,.user-profile,.nav-menu,.sidebar-quick-stats,.sidebar-footer{position:relative;z-index:2;}
.sidebar-header{display:flex;align-items:center;justify-content:space-between;padding:10px 10px 12px;}
.logo{display:flex;align-items:center;gap:10px;font-weight:900;font-size:18px;font-family:'Sora',sans-serif;}
.logo i{width:34px;height:34px;display:grid;place-items:center;border-radius:12px;background:radial-gradient(120% 140% at 30% 25%, rgba(255,255,255,.18), transparent 55%),linear-gradient(135deg,rgba(79,70,229,.70),rgba(34,211,238,.35));border:1px solid rgba(255,255,255,.18);box-shadow:0 14px 30px rgba(79,70,229,.18);}
.sidebar-close{display:none;width:40px;height:40px;border-radius:14px;border:1px solid rgba(255,255,255,.14);background:rgba(255,255,255,.06);color:var(--text);}
.user-profile{display:flex;gap:12px;padding:12px;border-radius:var(--r16);border:1px solid var(--border2);background:radial-gradient(140% 180% at 10% 0%, rgba(255,255,255,.10), transparent 60%),linear-gradient(180deg,rgba(255,255,255,.05),rgba(255,255,255,.03));box-shadow:0 12px 26px rgba(0,0,0,.18);}
.profile-pic{width:52px;height:52px;border-radius:16px;object-fit:cover;border:1px solid rgba(255,255,255,.16);}
.profile-pic.default{display:grid;place-items:center;font-weight:950;font-size:18px;color:var(--text);background:radial-gradient(120% 140% at 30% 25%, rgba(255,255,255,.18), transparent 55%),linear-gradient(135deg,rgba(34,211,238,.55),rgba(79,70,229,.55));}
.user-info h4{font-size:15px;font-weight:900;font-family:'Sora',sans-serif;margin:2px 0;}
.user-info p{font-size:12px;color:var(--muted);overflow:hidden;text-overflow:ellipsis;white-space:nowrap;max-width:170px;}
.user-tag{font-size:10px;background:rgba(224,231,255,.92);color:rgba(79,70,229,.98);padding:2px 8px;border-radius:12px;font-weight:700;}
.nav-menu{flex:1 1 auto;overflow-y:auto;padding:12px 4px 8px;margin-top:8px;display:flex;flex-direction:column;gap:4px;scrollbar-width:thin;scrollbar-color:rgba(255,255,255,.12) transparent;}
.nav-link{display:flex;align-items:center;gap:12px;padding:11px 12px;border-radius:14px;color:rgba(234,240,255,.88);border:1px solid transparent;transition:all var(--t);font-size:14px;}
.nav-link i{width:32px;height:32px;display:grid;place-items:center;border-radius:10px;background:rgba(255,255,255,.05);border:1px solid rgba(255,255,255,.10);font-size:13px;}
.nav-link:hover{background:rgba(255,255,255,.06);border-color:rgba(255,255,255,.12);transform:translateX(2px);}
.nav-link.active{background:radial-gradient(120% 160% at 10% 20%, rgba(255,255,255,.14), transparent 55%),linear-gradient(135deg,rgba(79,70,229,.55),rgba(34,211,238,.20));border-color:rgba(255,255,255,.18);box-shadow:0 10px 30px rgba(79,70,229,.18);}
.badge{margin-left:auto;font-size:11px;font-weight:900;padding:3px 8px;border-radius:999px;color:var(--text);background:radial-gradient(120% 180% at 20% 20%, rgba(255,255,255,.20), transparent 55%),linear-gradient(135deg,rgba(251,113,133,.70),rgba(79,70,229,.35));border:1px solid rgba(255,255,255,.18);}
.sidebar-quick-stats{margin-top:10px;padding:10px;border-radius:var(--r16);border:1px solid rgba(255,255,255,.10);background:rgba(255,255,255,.03);}
.sidebar-stat{display:flex;gap:12px;align-items:center;padding:9px 10px;border-radius:14px;}
.sidebar-stat:hover{background:rgba(255,255,255,.04);}
.sidebar-stat-icon{width:36px;height:36px;border-radius:12px;display:grid;place-items:center;border:1px solid rgba(255,255,255,.12);background:radial-gradient(120% 180% at 20% 10%, rgba(255,255,255,.18), transparent 55%),linear-gradient(135deg,rgba(34,211,238,.35),rgba(79,70,229,.35));font-size:13px;}
.sidebar-stat-label{font-size:11px;color:var(--muted);}
.sidebar-stat-number{font-size:16px;font-weight:950;font-family:'Sora',sans-serif;}
.sidebar-footer{margin-top:12px;}
.logout-btn{display:flex;align-items:center;justify-content:center;gap:10px;padding:12px;border-radius:14px;border:1px solid rgba(255,255,255,.14);background:radial-gradient(140% 180% at 20% 0%, rgba(255,255,255,.10), transparent 60%),linear-gradient(135deg,rgba(251,113,133,.16),rgba(255,255,255,.03));transition:all var(--t);font-size:14px;}
.logout-btn:hover{transform:translateY(-1px);background:rgba(251,113,133,.12);}

/* MAIN */
.main-content{padding:24px 28px 40px;}
.btn{display:inline-flex;align-items:center;justify-content:center;gap:9px;padding:10px 16px;border-radius:14px;border:1px solid rgba(255,255,255,.14);background:rgba(255,255,255,.04);color:rgba(234,240,255,.92);font-weight:700;font-size:13px;transition:all var(--t);cursor:pointer;}
.btn:hover{transform:translateY(-1px);background:rgba(255,255,255,.07);border-color:rgba(255,255,255,.18);box-shadow:0 10px 28px rgba(0,0,0,.22);}
.btn-primary{background:radial-gradient(120% 160% at 10% 20%, rgba(255,255,255,.14), transparent 55%),linear-gradient(135deg,rgba(79,70,229,.85),rgba(34,211,238,.25));border-color:rgba(255,255,255,.18);box-shadow:0 10px 30px rgba(79,70,229,.22);}
.btn-sm{padding:7px 12px;font-size:12px;border-radius:11px;}
.btn-danger-sm{background:rgba(251,113,133,.12);border-color:rgba(251,113,133,.30);color:var(--danger);}
.btn-danger-sm:hover{background:rgba(251,113,133,.22);}

/* PAGE HEADER */
.page-header{display:flex;align-items:center;justify-content:space-between;gap:14px;padding:18px 20px;border-radius:var(--r20);border:1px solid var(--border);background:radial-gradient(120% 220% at 15% 10%, rgba(255,255,255,.10), transparent 55%),linear-gradient(180deg,rgba(255,255,255,.05),rgba(255,255,255,.03));box-shadow:var(--shadow2);margin-bottom:20px;}
.page-header h1{font-size:22px;font-weight:800;font-family:'Sora',sans-serif;margin:0 0 4px;display:flex;align-items:center;gap:10px;}
.page-header p{margin:0;color:var(--muted);font-size:13px;}
.header-actions{display:flex;gap:10px;align-items:center;flex-wrap:wrap;}

/* STATS ROW */
.stats-row{display:grid;grid-template-columns:repeat(4,1fr);gap:12px;margin-bottom:20px;}
.stat-mini{padding:14px 16px;border-radius:var(--r16);border:1px solid var(--border2);background:rgba(255,255,255,.03);display:flex;align-items:center;gap:12px;}
.stat-mini-icon{width:38px;height:38px;border-radius:12px;display:grid;place-items:center;border:1px solid rgba(255,255,255,.14);font-size:15px;flex-shrink:0;}
.stat-mini-num{font-size:20px;font-weight:900;font-family:'Sora',sans-serif;line-height:1;}
.stat-mini-label{font-size:11.5px;color:var(--muted);}

/* FILTER TABS */
.filter-tabs{display:flex;gap:8px;margin-bottom:16px;flex-wrap:wrap;}
.filter-tab{padding:8px 16px;border-radius:999px;border:1px solid var(--border);background:rgba(255,255,255,.04);color:var(--muted);font-size:13px;font-weight:600;cursor:pointer;transition:all var(--t);text-decoration:none;}
.filter-tab:hover{background:rgba(255,255,255,.07);color:var(--text);}
.filter-tab.active{background:radial-gradient(120% 160% at 20% 20%, rgba(255,255,255,.12), transparent 55%),linear-gradient(135deg,rgba(79,70,229,.60),rgba(34,211,238,.20));border-color:rgba(255,255,255,.18);color:#fff;}

/* NOTIFICATION ITEMS */
.notif-list{display:flex;flex-direction:column;gap:10px;}
.notif-item{
  display:flex;align-items:flex-start;gap:14px;padding:16px;
  border-radius:18px;border:1px solid var(--border);background:rgba(255,255,255,.03);
  box-shadow:0 4px 20px rgba(0,0,0,.15);
  transition:transform var(--t),border-color var(--t),background var(--t),box-shadow var(--t);
  position:relative;
}
.notif-item:hover{transform:translateY(-2px);border-color:rgba(255,255,255,.15);background:rgba(255,255,255,.05);box-shadow:0 12px 36px rgba(0,0,0,.25);}
.notif-item.unread{border-color:rgba(79,70,229,.28);background:radial-gradient(120% 200% at 5% 0%, rgba(79,70,229,.12), transparent 60%),rgba(255,255,255,.03);}
.notif-item.unread::before{content:'';position:absolute;left:0;top:50%;transform:translateY(-50%);width:3px;height:60%;background:linear-gradient(180deg,rgba(79,70,229,.90),rgba(34,211,238,.60));border-radius:0 3px 3px 0;}
.notif-icon{width:46px;height:46px;border-radius:16px;display:grid;place-items:center;border:1px solid rgba(255,255,255,.14);background:rgba(255,255,255,.05);color:var(--text);flex-shrink:0;font-size:17px;}
.notif-icon.type-goal{background:rgba(52,211,153,.12);border-color:rgba(52,211,153,.25);color:var(--success);}
.notif-icon.type-achievement{background:rgba(251,191,36,.12);border-color:rgba(251,191,36,.25);color:var(--warning);}
.notif-icon.type-reminder{background:rgba(34,211,238,.12);border-color:rgba(34,211,238,.25);color:var(--info);}
.notif-item.unread .notif-icon{background:radial-gradient(120% 180% at 20% 10%, rgba(255,255,255,.18), transparent 55%),linear-gradient(135deg,rgba(79,70,229,.70),rgba(34,211,238,.25));border-color:rgba(255,255,255,.20);}
.notif-body{flex:1;min-width:0;}
.notif-title{font-weight:800;font-size:14px;margin-bottom:4px;font-family:'Sora',sans-serif;}
.notif-msg{font-size:13px;color:var(--muted);line-height:1.5;margin-bottom:8px;word-break:break-word;}
.notif-meta{display:flex;align-items:center;gap:10px;flex-wrap:wrap;}
.notif-time{font-size:11.5px;color:var(--muted2);font-weight:600;}
.notif-type-badge{font-size:10.5px;font-weight:700;padding:2px 8px;border-radius:999px;border:1px solid rgba(255,255,255,.14);background:rgba(255,255,255,.06);text-transform:uppercase;letter-spacing:.4px;}
.notif-actions{display:flex;flex-direction:column;gap:7px;flex-shrink:0;}

/* EMPTY STATE */
.empty-state{text-align:center;padding:50px 20px;border-radius:var(--r20);border:1px solid var(--border2);background:rgba(255,255,255,.02);}
.empty-state-icon{width:64px;height:64px;display:grid;place-items:center;border-radius:20px;border:1px solid rgba(255,255,255,.12);background:rgba(255,255,255,.04);font-size:26px;margin:0 auto 16px;}
.empty-state h3{font-size:18px;font-weight:800;font-family:'Sora',sans-serif;margin-bottom:8px;}
.empty-state p{color:var(--muted);font-size:14px;margin-bottom:18px;}

/* PAGINATION */
.pagination{display:flex;justify-content:center;gap:8px;margin-top:20px;flex-wrap:wrap;}

/* ALERT */
.alert{display:flex;align-items:center;gap:12px;padding:14px 16px;border-radius:var(--r16);border:1px solid var(--border);background:rgba(255,255,255,.04);margin-bottom:16px;font-size:14px;}
.alert i{width:34px;height:34px;display:grid;place-items:center;border-radius:12px;background:rgba(255,255,255,.06);border:1px solid rgba(255,255,255,.12);flex-shrink:0;}
.alert-success{border-color:rgba(52,211,153,.25);}.alert-success i{color:var(--success);}
.alert-error{border-color:rgba(251,113,133,.25);}.alert-error i{color:var(--danger);}

/* RESPONSIVE */
@media(max-width:860px){
  .dashboard-wrapper{grid-template-columns:1fr;}
  .mobile-toggle{display:grid;}
  .sidebar{position:fixed;left:0;top:0;width:300px;height:100vh;transform:translateX(-105%);transition:transform .25s;z-index:1601;}
  .sidebar.active{transform:translateX(0);}
  .sidebar-overlay{display:block;}
  .sidebar-close{display:grid;}
  .main-content{padding:80px 16px 32px;}
  .stats-row{grid-template-columns:1fr 1fr;}
}
@media(max-width:520px){
  .notif-item{flex-direction:column;}
  .notif-actions{flex-direction:row;width:100%;}
  .notif-actions .btn-sm{flex:1;justify-content:center;}
  .stats-row{grid-template-columns:1fr 1fr;}
  .header-actions{width:100%;}
}
</style>
</head>
<body>
<button class="mobile-toggle" id="sidebarToggle"><i class="fas fa-bars"></i></button>
<div class="sidebar-overlay" id="sidebarOverlay"></div>

<div class="dashboard-wrapper">
  <!-- SIDEBAR -->
  <aside class="sidebar" id="sidebar">
    <div class="sidebar-header">
      <div class="logo"><i class="fas fa-star"></i><span>ProgressMate</span></div>
      <button class="sidebar-close" id="sidebarClose"><i class="fas fa-times"></i></button>
    </div>
    <div class="user-profile">
      <?php if (!empty($_SESSION['profile_picture'])): ?>
        <img src="../<?php echo htmlspecialchars($_SESSION['profile_picture']); ?>" class="profile-pic" alt="">
      <?php else: ?>
        <div class="profile-pic default"><?php echo strtoupper(substr($_SESSION['name'],0,1)); ?></div>
      <?php endif; ?>
      <div class="user-info">
        <h4><?php echo htmlspecialchars($_SESSION['name']); ?></h4>
        <p><?php echo htmlspecialchars($_SESSION['email']); ?></p>
        <span class="user-tag">STUDENT</span>
      </div>
    </div>
    <nav class="nav-menu">
      <a href="dashboard.php" class="nav-link <?php echo $current==='dashboard.php'?'active':''; ?>"><i class="fas fa-tachometer-alt"></i> Dashboard</a>
      <a href="goals.php" class="nav-link <?php echo $current==='goals.php'?'active':''; ?>"><i class="fas fa-bullseye"></i> Goals</a>
      <a href="achievements.php" class="nav-link <?php echo $current==='achievements.php'?'active':''; ?>"><i class="fas fa-trophy"></i> Achievements</a>
      <a href="notifications.php" class="nav-link <?php echo $current==='notifications.php'?'active':''; ?>">
        <i class="fas fa-bell"></i> Notifications
        <?php if ($unread > 0): ?><span class="badge"><?php echo $unread; ?></span><?php endif; ?>
      </a>
      <a href="profile.php" class="nav-link <?php echo $current==='profile.php'?'active':''; ?>"><i class="fas fa-user"></i> Profile</a>
    </nav>
    <div class="sidebar-quick-stats">
      <div class="sidebar-stat"><div class="sidebar-stat-icon"><i class="fas fa-bullseye"></i></div><div><div class="sidebar-stat-label">Goals</div><div class="sidebar-stat-number"><?php echo $completed_goals; ?>/<?php echo $total_goals; ?></div></div></div>
      <div class="sidebar-stat"><div class="sidebar-stat-icon"><i class="fas fa-star"></i></div><div><div class="sidebar-stat-label">Points</div><div class="sidebar-stat-number"><?php echo $total_points; ?></div></div></div>
      <div class="sidebar-stat"><div class="sidebar-stat-icon"><i class="fas fa-fire"></i></div><div><div class="sidebar-stat-label">Streak</div><div class="sidebar-stat-number"><?php echo $streak; ?>d</div></div></div>
    </div>
    <div class="sidebar-footer">
      <a href="../logout.php" class="logout-btn"><i class="fas fa-sign-out-alt"></i> Logout</a>
    </div>
  </aside>

  <!-- MAIN -->
  <main class="main-content">
    <header class="page-header">
      <div>
        <h1><i class="fas fa-bell" style="color:var(--warning);"></i> Notifications</h1>
        <p>Stay updated with your progress, achievements and reminders</p>
      </div>
      <div class="header-actions">
        <?php if ($unread > 0): ?>
          <a href="?read_all=1" class="btn btn-primary"><i class="fas fa-check-double"></i> Mark All Read</a>
        <?php endif; ?>
        <a href="dashboard.php" class="btn"><i class="fas fa-arrow-left"></i> Dashboard</a>
      </div>
    </header>

    <?php if ($success): ?><div class="alert alert-success"><i class="fas fa-check-circle"></i><span><?php echo htmlspecialchars($success); ?></span></div><?php endif; ?>
    <?php if ($error): ?><div class="alert alert-error"><i class="fas fa-exclamation-circle"></i><span><?php echo htmlspecialchars($error); ?></span></div><?php endif; ?>

    <!-- Stats row -->
    <?php
    $s_total = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE user_id=? AND deleted_at IS NULL"); $s_total->execute([$student_id]); $cnt_total=$s_total->fetchColumn();
    $s_unread2 = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE user_id=? AND is_read=0 AND deleted_at IS NULL"); $s_unread2->execute([$student_id]); $cnt_unread=$s_unread2->fetchColumn();
    $s_goal = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE user_id=? AND type='goal' AND deleted_at IS NULL"); $s_goal->execute([$student_id]); $cnt_goal=$s_goal->fetchColumn();
    $s_ach = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE user_id=? AND type='achievement' AND deleted_at IS NULL"); $s_ach->execute([$student_id]); $cnt_ach=$s_ach->fetchColumn();
    ?>
    <div class="stats-row">
      <div class="stat-mini">
        <div class="stat-mini-icon" style="background:rgba(79,70,229,.15);border-color:rgba(79,70,229,.30);color:var(--primary);"><i class="fas fa-bell"></i></div>
        <div><div class="stat-mini-num"><?php echo $cnt_total; ?></div><div class="stat-mini-label">Total</div></div>
      </div>
      <div class="stat-mini">
        <div class="stat-mini-icon" style="background:rgba(251,113,133,.12);border-color:rgba(251,113,133,.28);color:var(--danger);"><i class="fas fa-circle"></i></div>
        <div><div class="stat-mini-num"><?php echo $cnt_unread; ?></div><div class="stat-mini-label">Unread</div></div>
      </div>
      <div class="stat-mini">
        <div class="stat-mini-icon" style="background:rgba(52,211,153,.12);border-color:rgba(52,211,153,.28);color:var(--success);"><i class="fas fa-bullseye"></i></div>
        <div><div class="stat-mini-num"><?php echo $cnt_goal; ?></div><div class="stat-mini-label">Goal</div></div>
      </div>
      <div class="stat-mini">
        <div class="stat-mini-icon" style="background:rgba(251,191,36,.12);border-color:rgba(251,191,36,.28);color:var(--warning);"><i class="fas fa-trophy"></i></div>
        <div><div class="stat-mini-num"><?php echo $cnt_ach; ?></div><div class="stat-mini-label">Achievement</div></div>
      </div>
    </div>

    <!-- Filter tabs -->
    <div class="filter-tabs">
      <?php $filters=[['all','All'],['unread','Unread'],['goal','Goals'],['achievement','Achievements'],['reminder','Reminders']]; ?>
      <?php foreach($filters as [$val,$label]): ?>
        <a href="?filter=<?php echo $val; ?>" class="filter-tab <?php echo $filter===$val?'active':''; ?>"><?php echo $label; ?></a>
      <?php endforeach; ?>
    </div>

    <!-- Notifications list -->
    <div class="notif-list">
      <?php if (empty($notifications)): ?>
        <div class="empty-state">
          <div class="empty-state-icon"><i class="fas fa-bell-slash"></i></div>
          <h3>No notifications</h3>
          <p><?php echo $filter==='all'?'You\'re all caught up! Complete goals to get updates.':'No notifications in this category.'; ?></p>
          <a href="goals.php" class="btn btn-primary"><i class="fas fa-bullseye"></i> View Goals</a>
        </div>
      <?php else: ?>
        <?php
        $type_icons = ['goal'=>'fas fa-bullseye','achievement'=>'fas fa-trophy','reminder'=>'fas fa-clock','deadline'=>'fas fa-calendar-xmark','system'=>'fas fa-info-circle'];
        foreach ($notifications as $n):
          $icon = $type_icons[$n['type']] ?? 'fas fa-bell';
          $is_unread = !$n['is_read'];
          $time_diff = time() - strtotime($n['created_at']);
          if ($time_diff < 60) $time_str = 'Just now';
          elseif ($time_diff < 3600) $time_str = floor($time_diff/60).'m ago';
          elseif ($time_diff < 86400) $time_str = floor($time_diff/3600).'h ago';
          elseif ($time_diff < 604800) $time_str = floor($time_diff/86400).'d ago';
          else $time_str = date('M j, Y', strtotime($n['created_at']));
        ?>
          <div class="notif-item <?php echo $is_unread?'unread':''; ?>">
            <div class="notif-icon type-<?php echo htmlspecialchars($n['type']); ?>">
              <i class="<?php echo $icon; ?>"></i>
            </div>
            <div class="notif-body">
              <div class="notif-title"><?php echo htmlspecialchars($n['title']); ?></div>
              <div class="notif-msg"><?php echo htmlspecialchars($n['message']); ?></div>
              <div class="notif-meta">
                <span class="notif-time"><i class="fas fa-clock" style="font-size:10px;"></i> <?php echo $time_str; ?></span>
                <span class="notif-type-badge"><?php echo htmlspecialchars($n['type']); ?></span>
                <?php if ($is_unread): ?><span class="notif-type-badge" style="color:var(--info);border-color:rgba(34,211,238,.25);background:rgba(34,211,238,.08);">New</span><?php endif; ?>
              </div>
            </div>
            <div class="notif-actions">
              <?php if ($is_unread): ?>
                <a href="?read=<?php echo $n['id']; ?>&filter=<?php echo $filter; ?>" class="btn btn-sm"><i class="fas fa-check"></i> Read</a>
              <?php endif; ?>
              <a href="?delete=<?php echo $n['id']; ?>&filter=<?php echo $filter; ?>" class="btn btn-sm btn-danger-sm" onclick="return confirm('Delete this notification?')"><i class="fas fa-trash"></i> Delete</a>
            </div>
          </div>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>

    <!-- Pagination -->
    <?php if ($total_pages > 1): ?>
      <div class="pagination">
        <?php for ($i=1; $i<=$total_pages; $i++): ?>
          <a href="?page=<?php echo $i; ?>&filter=<?php echo $filter; ?>" class="btn btn-sm <?php echo $page==$i?'btn-primary':''; ?>"><?php echo $i; ?></a>
        <?php endfor; ?>
      </div>
    <?php endif; ?>

  </main>
</div>

<script>
const sidebar=document.getElementById('sidebar');
const overlay=document.getElementById('sidebarOverlay');
document.getElementById('sidebarToggle')?.addEventListener('click',()=>{sidebar.classList.add('active');overlay.classList.add('active');});
document.getElementById('sidebarClose')?.addEventListener('click',()=>{sidebar.classList.remove('active');overlay.classList.remove('active');});
overlay?.addEventListener('click',()=>{sidebar.classList.remove('active');overlay.classList.remove('active');});
setTimeout(()=>{document.querySelectorAll('.alert').forEach(a=>{a.style.transition='opacity .3s';a.style.opacity='0';setTimeout(()=>a.remove(),300);});},5000);
</script>
</body>
</html>