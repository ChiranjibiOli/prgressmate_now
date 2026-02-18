<?php
session_start();
require_once '../includes/db_connection.php';
require_once '../includes/functions.php'; // ✅ REQUIRED for checkAuth()
checkAuth('student');


$student_id = $_SESSION['user_id'];

// === Sidebar Stats ===
$total_goals = $pdo->prepare("SELECT COUNT(*) FROM student_goals WHERE student_id = ?");
$total_goals->execute([$student_id]);
$total_goals = $total_goals->fetchColumn();

$completed_goals = $pdo->prepare("SELECT COUNT(*) FROM student_goals WHERE student_id = ? AND status = 'completed'");
$completed_goals->execute([$student_id]);
$completed_goals = $completed_goals->fetchColumn();

$total_points = $pdo->prepare("SELECT points FROM users WHERE id = ?");
$total_points->execute([$student_id]);
$total_points = $total_points->fetchColumn() ?: 0;

$streak = $pdo->prepare("SELECT current_streak FROM users WHERE id = ?");
$streak->execute([$student_id]);
$streak = $streak->fetchColumn() ?: 0;

$unread = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0 AND deleted_at IS NULL");

$unread->execute([$student_id]);
$unread = $unread->fetchColumn();

// === Handle Actions ===
$success = $error = '';
if (isset($_GET['read'])) {
    $id = (int)$_GET['read'];
    $stmt = $pdo->prepare("UPDATE notifications SET is_read = 1 WHERE id = ? AND user_id = ?");
    $stmt->execute([$id, $student_id]);
    $success = "Notification marked as read.";
    header("Location: notifications.php");
    exit;
}
if (isset($_GET['delete'])) {
    $id = (int)$_GET['delete'];
    $stmt = $pdo->prepare("
        UPDATE notifications 
        SET deleted_at = NOW() 
        WHERE id = ? AND user_id = ? AND deleted_at IS NULL
    ");
    $stmt->execute([$id, $student_id]);
    header("Location: notifications.php");
    exit;
}

// === Pagination ===
$per_page = 20;
$page = max(1, $_GET['page'] ?? 1);
$offset = ($page - 1) * $per_page;

$total_stmt = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND deleted_at IS NULL");

$total_stmt->execute([$student_id]);
$total_notifications = $total_stmt->fetchColumn();
$total_pages = ceil($total_notifications / $per_page);

// === Fetch Notifications ===
$per_page = 20;
$page = max(1, $_GET['page'] ?? 1);
$offset = ($page - 1) * $per_page;

// === Fetch Notifications safely ===
$stmt = $pdo->prepare("
    SELECT * FROM notifications 
    WHERE user_id = ? AND deleted_at IS NULL
    ORDER BY created_at DESC 
    LIMIT $per_page OFFSET $offset
");

$stmt->execute([$student_id]);
$notifications = $stmt->fetchAll();
$current = basename($_SERVER['PHP_SELF']);

?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Notifications - ProgressMate</title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
:root{
  --bg0:#070A18;
  --bg1:#0B1030;

  --text:#EAF0FF;
  --muted: rgba(234,240,255,.65);
  --muted2: rgba(234,240,255,.50);

  --primary:#4F46E5;
  --primary-dark:#4338ca;
  --primary-light: rgba(79,70,229,.14);

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

  --transition-fast: 150ms ease;
  --transition-base: 300ms ease;
}

/* ===== Reset ===== */
*{ margin:0; padding:0; box-sizing:border-box; }
html,body{ height:100%; }
body{
  font-family:'Inter', system-ui, -apple-system, Segoe UI, Roboto, Arial, sans-serif;
  color: var(--text);
  background:
    radial-gradient(900px 520px at 18% 10%, rgba(79,70,229,.22), transparent 60%),
    radial-gradient(900px 520px at 88% 15%, rgba(34,211,238,.18), transparent 58%),
    radial-gradient(900px 520px at 70% 95%, rgba(96,165,250,.14), transparent 62%),
    linear-gradient(180deg, var(--bg0), var(--bg1));
  overflow-x:hidden;
  line-height: 1.5;
}
a{ color: inherit; text-decoration:none; }
img{ max-width:100%; display:block; }
button{ font-family: inherit; cursor:pointer; border:none; background:none; }

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
}

/* profile */
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
  color: var(--text);
  background:
    radial-gradient(120% 140% at 30% 25%, rgba(255,255,255,.18), transparent 55%),
    linear-gradient(135deg, rgba(34,211,238,.55), rgba(79,70,229,.55));
}
.user-info h4{ margin: 2px 0 2px; font-size: 15px; font-weight: 900; }
.user-info p{
  margin: 0;
  font-size: 12.5px;
  color: var(--muted);
  overflow:hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
  max-width: 210px;
}

.user-info span[style*="STUDENT"]{
  background: rgba(224,231,255,.92) !important;
  color: rgba(79,70,229,.98) !important;
  border: 1px solid rgba(255,255,255,.40);
}

/* nav */
.nav-menu{
  flex: 1 1 auto;
  overflow-y: auto;
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
    linear-gradient(135deg, rgba(251,113,133,.70), rgba(79,70,229,.35));
  border: 1px solid rgba(255,255,255,.18);
}

/* quick stats + logout */
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
  padding: 10px 14px;
  border-radius: 14px;
  border: 1px solid rgba(255,255,255,.14);
  background: rgba(255,255,255,.04);
  color: rgba(234,240,255,.92);
  font-weight: 900;
  font-size: 13px;
  transition: transform .18s ease, background .18s ease, border-color .18s ease, box-shadow .18s ease;
}
.btn:hover{
  transform: translateY(-1px);
  background: rgba(255,255,255,.06);
  border-color: rgba(255,255,255,.18);
  box-shadow: 0 16px 36px rgba(0,0,0,.24);
}
.btn-sm{ padding: 8px 12px; font-size: 12px; border-radius: 12px; }

.btn-primary{
  border-color: rgba(255,255,255,.18);
  background:
    radial-gradient(120% 160% at 10% 20%, rgba(255,255,255,.14), transparent 55%),
    linear-gradient(135deg, rgba(79,70,229,.85), rgba(34,211,238,.25));
  box-shadow: 0 18px 40px rgba(79,70,229,.18);
}

.btn-outline{
  background: rgba(255,255,255,.04);
  color: rgba(234,240,255,.92);
  border: 1px solid rgba(255,255,255,.14);
}
.btn-outline.active{
  background:
    radial-gradient(120% 160% at 10% 20%, rgba(255,255,255,.14), transparent 55%),
    linear-gradient(135deg, rgba(79,70,229,.70), rgba(34,211,238,.18));
  border-color: rgba(255,255,255,.18);
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
.alert i{
  width: 34px; height:34px;
  display:grid; place-items:center;
  border-radius: 12px;
  background: rgba(255,255,255,.05);
  border: 1px solid rgba(255,255,255,.10);
}
.alert-success{ border-color: rgba(52,211,153,.25); }
.alert-success i{ color: var(--success); }
.alert-error{ border-color: rgba(251,113,133,.25); }
.alert-error i{ color: var(--danger); }

/* ===== Notifications List ===== */
.notifications-container{ max-width: 980px; margin: 0 auto; }

.notifications-list{
  width: 100%;
  display:flex;
  flex-direction: column;
  gap: 10px;
  margin-top: 14px;
}

.notification-item{
  width: 100%;
  display:flex;
  align-items:flex-start;
  gap: 12px;
  padding: 12px 12px;
  border-radius: 18px;
  border: 1px solid rgba(255,255,255,.10);
  background: rgba(255,255,255,.03);
  box-shadow: 0 18px 40px rgba(0,0,0,.20);
  transition: transform .18s ease, border-color .18s ease, background .18s ease, box-shadow .18s ease;
  position: relative;
}
.notification-item:hover{
  transform: translateY(-2px);
  border-color: rgba(255,255,255,.14);
  background: rgba(255,255,255,.04);
  box-shadow: 0 22px 55px rgba(0,0,0,.28);
}

/* unread glow */
.notification-item.unread{
  border-color: rgba(79,70,229,.30);
  background:
    radial-gradient(120% 200% at 10% 0%, rgba(79,70,229,.18), transparent 60%),
    rgba(255,255,255,.03);
}

/* icon */
.notification-icon{
  width: 44px;
  height: 44px;
  border-radius: 16px;
  display:grid;
  place-items:center;
  border: 1px solid rgba(255,255,255,.14);
  background: rgba(255,255,255,.05);
  color: rgba(234,240,255,.92);
  flex-shrink: 0;
  box-shadow: 0 14px 26px rgba(0,0,0,.18);
}

/* type coloring */
.notification-icon.goal{ color: rgba(34,211,153,.95); }
.notification-icon.achievement{ color: rgba(251,191,36,.95); }
.notification-icon.bell{ color: rgba(34,211,238,.95); }

.notification-item.unread .notification-icon{
  background:
    radial-gradient(120% 180% at 20% 10%, rgba(255,255,255,.20), transparent 55%),
    linear-gradient(135deg, rgba(79,70,229,.85), rgba(34,211,238,.25));
  border-color: rgba(255,255,255,.18);
  color: rgba(234,240,255,.96);
}

.notification-content{
  flex: 1 1 auto;
  min-width: 0;
}
.notification-title{
  font-weight: 950;
  font-size: 14px;
  color: rgba(234,240,255,.95);
  margin-bottom: 4px;
}
.notification-message{
  font-size: 13px;
  color: var(--muted);
  line-height: 1.5;
  margin-bottom: 8px;
  word-break: break-word;
}
.notification-time{
  font-size: 11.5px;
  color: var(--muted2);
  font-weight: 800;
}

/* actions */
.notification-actions{
  display:flex;
  flex-direction: column;
  gap: 8px;
  margin-left: auto;
  flex-shrink: 0;
}
.notification-actions .btn-sm{
  width: 140px;
  justify-content:center;
}

/* Empty state */
.empty-state{
  width: 100%;
  text-align:center;
  padding: 22px 14px;
  color: var(--muted);
  border-radius: var(--r20);
  border: 1px solid rgba(255,255,255,.10);
  background: rgba(255,255,255,.03);
  box-shadow: var(--shadow2);
  margin-top: 14px;
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

/* Pagination */
.pagination{
  width: 100%;
  display:flex;
  justify-content:center;
  gap: 8px;
  margin-top: 16px;
  flex-wrap: wrap;
}
.pagination a.btn{ padding: 8px 12px; border-radius: 12px; }

/* ===== Responsive ===== */
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
}

@media (max-width: 760px){
  .page-header{ flex-direction: column; align-items: flex-start; }
  .notification-item{ flex-direction: column; }
  .notification-actions{
    flex-direction: row;
    width: 100%;
    margin-left: 0;
  }
  .notification-actions .btn-sm{ width: auto; flex: 1; }
}

    </style>
</head>

<body>
    <!-- ===== MOBILE MENU TOGGLE ===== -->
    <button class="mobile-toggle" id="sidebarToggle">
        <i class="fas fa-bars"></i>
    </button>

    <!-- ===== DASHBOARD WRAPPER ===== -->
    <div class="dashboard-wrapper">

        <!-- ===== SIDEBAR ===== -->
        <aside class="sidebar" id="sidebar">
            <!-- Sidebar Header -->
            <div class="sidebar-header">
                <div class="logo">
                    <i class="fas fa-star"></i>
                    <span>ProgressMate</span>
                </div>
                <button class="sidebar-close" id="sidebarClose">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <!-- User Profile -->
            <div class="user-profile">
                <?php if (!empty($_SESSION['profile_picture'])): ?>
                    <img src="<?php echo '../' . htmlspecialchars($_SESSION['profile_picture']); ?>" alt="Profile" class="profile-pic">
                <?php else: ?>
                    <div class="profile-pic default">
                        <?php echo strtoupper(substr($_SESSION['name'], 0, 1)); ?>
                    </div>
                <?php endif; ?>
                <div class="user-info">
                    <h4><?php echo htmlspecialchars($_SESSION['name']); ?></h4>
                    <p><?php echo htmlspecialchars($_SESSION['email']); ?></p>
                    <span style="font-size: 11px; background: #e0e7ff; color: #4f46e5; padding: 2px 8px; border-radius: 12px;">
                        STUDENT
                    </span>
                </div>
            </div>
            <!-- Navigation Menu -->
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
            <!-- Quick Stats -->
            <div class="sidebar-quick-stats">
                <div class="sidebar-stat">
                    <div class="sidebar-stat-icon">
                        <i class="fas fa-bullseye"></i>
                    </div>
                    <div class="sidebar-stat-info">
                        <div class="sidebar-stat-label">Goals</div>
                        <div class="sidebar-stat-number"><?php echo $completed_goals; ?>/<?php echo $total_goals; ?></div>
                    </div>
                </div>
                <div class="sidebar-stat">
                    <div class="sidebar-stat-icon">
                        <i class="fas fa-star"></i>
                    </div>
                    <div class="sidebar-stat-info">
                        <div class="sidebar-stat-label">Points</div>
                        <div class="sidebar-stat-number"><?php echo $total_points; ?></div>
                    </div>
                </div>
            </div>

            <!-- Logout -->
            <div class="sidebar-footer">
                <a href="../logout.php" class="logout-btn">
                    <i class="fas fa-sign-out-alt"></i>
                    <span>Logout</span>
                </a>
            </div>
        </aside>

        <!-- ===== MAIN CONTENT ===== -->
        <main class="main-content">
            <!-- Page Header -->
            <header class="page-header">
                <div class="header-content">
                    <h1>Notifications</h1>
                    <p>Stay updated with your progress and reminders</p>
                </div>
                <div class="header-actions">
                    <a href="dashboard.php" class="btn btn-outline">
                        <i class="fas fa-arrow-left"></i> Back to Dashboard
                    </a>
                </div>
            </header>

            <!-- Alerts -->
            <?php if ($success): ?>
                <div class="alert alert-success">
                    <i class="fas fa-check-circle"></i>
                    <span><?php echo htmlspecialchars($success); ?></span>
                </div>
            <?php endif; ?>

            <?php if (!empty($error)): ?>
                <div class="alert alert-error">
                    <i class="fas fa-exclamation-circle"></i>
                    <span><?php echo htmlspecialchars($error); ?></span>
                </div>
            <?php endif; ?>

            <!-- Notifications List -->
            <div class="notifications-list">
                <?php if (empty($notifications)): ?>
                    <div class="empty-state">
                        <i class="fas fa-bell"></i>
                        <p>No notifications yet</p>
                        <a href="goals.php" class="btn btn-primary">
                            <i class="fas fa-bullseye"></i> View Goals
                        </a>
                    </div>
                <?php else: ?>
                    <?php foreach ($notifications as $notification): ?>
                        <div class="notification-item <?php echo $notification['is_read'] ? 'read' : 'unread'; ?>">
                            <div class="notification-icon <?php echo $notification['type']; ?>">
                                <i class="fas fa-<?php echo $notification['type'] == 'goal' ? 'bullseye' : ($notification['type'] == 'achievement' ? 'trophy' : 'bell'); ?>"></i>
                            </div>
                            <div class="notification-content">
                                <div class="notification-title"><?php echo htmlspecialchars($notification['title']); ?></div>
                                <div class="notification-message"><?php echo htmlspecialchars($notification['message']); ?></div>
                                <div class="notification-time"><?php echo date('M d, Y h:i A', strtotime($notification['created_at'])); ?></div>
                            </div>
                            <div class="notification-actions">
                                <?php if (!$notification['is_read']): ?>
                                    <a href="?read=<?php echo $notification['id']; ?>" class="btn btn-sm btn-outline">Mark as Read</a>
                                <?php endif; ?>
                                <a href="?delete=<?php echo $notification['id']; ?>" class="btn btn-sm btn-outline" onclick="return confirm('Delete this notification?');">Delete</a>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>

            <!-- Pagination -->
            <div class="pagination">
                <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                    <a href="?page=<?php echo $i; ?>" class="btn btn-outline <?php echo $page == $i ? 'active' : ''; ?>"><?php echo $i; ?></a>
                <?php endfor; ?>
            </div>
        </main>
    </div>

    <!-- ===== JAVASCRIPT ===== -->
    <script>
        // Mobile sidebar toggle
        const sidebarToggle = document.getElementById('sidebarToggle');
        const sidebar = document.getElementById('sidebar');
        const sidebarClose = document.getElementById('sidebarClose');

        if (sidebarToggle) {
            sidebarToggle.addEventListener('click', function() {
                sidebar.classList.add('active');
            });
        }

        if (sidebarClose) {
            sidebarClose.addEventListener('click', function() {
                sidebar.classList.remove('active');
            });
        }

        // Close sidebar when clicking outside on mobile
        document.addEventListener('click', function(event) {
            if (window.innerWidth <= 768 &&
                sidebar && sidebar.classList.contains('active') &&
                !sidebar.contains(event.target) &&
                !sidebarToggle.contains(event.target)) {
                sidebar.classList.remove('active');
            }
        });

        // Handle window resize
        window.addEventListener('resize', function() {
            if (window.innerWidth > 768 && sidebar && sidebar.classList.contains('active')) {
                sidebar.classList.remove('active');
            }
        });

        // Auto-hide alerts after 5 seconds
        setTimeout(() => {
            const alerts = document.querySelectorAll('.alert');
            alerts.forEach(alert => {
                alert.style.opacity = '0';
                setTimeout(() => {
                    alert.style.display = 'none';
                }, 300);
            });
        }, 5000);
    </script>
  
</body>

</html>