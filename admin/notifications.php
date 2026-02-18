<?php
session_start();
require_once '../includes/db_connection.php';
require_once '../includes/functions.php';

checkAuth('admin');

// Flash Messages
$success = $_SESSION['success'] ?? '';
$error   = $_SESSION['error'] ?? '';
unset($_SESSION['success'], $_SESSION['error']);

// Sidebar Stats
$total_students = (int)($pdo->query("SELECT COUNT(*) FROM users WHERE role='student'")->fetchColumn() ?? 0);
$total_goals    = (int)($pdo->query("SELECT COUNT(*) FROM admin_goals")->fetchColumn() ?? 0);
$total_points   = (int)($pdo->query("SELECT COALESCE(SUM(points),0) FROM users WHERE role='student'")->fetchColumn() ?? 0);

$sidebar_stats = [
    'students' => $total_students,
    'goals'    => $total_goals,
    'assigned' => 0,
    'points'   => $total_points
];

// Nav badges
$students_count = (int)($sidebar_stats['students'] ?? 0);
$goals_count    = (int)($sidebar_stats['goals'] ?? 0);
$assigned_count = (int)($sidebar_stats['assigned'] ?? 0);
$points_count   = (int)($sidebar_stats['points'] ?? 0);

// Pending progress requests
$pending_requests = 0;
try {
    $pending_requests = (int)($pdo->query("SELECT COUNT(*) FROM progress_requests WHERE status='pending'")->fetchColumn() ?? 0);
} catch (Exception $e) {
    $pending_requests = 0;
}

// Students list (dropdown)
$students = $pdo->query("
    SELECT id, name
    FROM users
    WHERE role='student'
    ORDER BY name ASC
")->fetchAll(PDO::FETCH_ASSOC);

// =====================
// SEND NOTIFICATION (POST)
// =====================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'send_notification') {

    $title       = trim($_POST['title'] ?? '');
    $message     = trim($_POST['message'] ?? '');
    $target_type = $_POST['target_type'] ?? 'all';
    $target_id   = $_POST['target_id'] ?? null;

    // Optional: your table has enum type: system, goal, achievement, reminder
    // We'll send admin announcements as "system"
    $type = 'system';

    if ($title === '' || $message === '') {
        $_SESSION['error'] = "Title and message are required.";
        header("Location: notifications.php");
        exit;
    }

    if ($target_type === 'student' && empty($target_id)) {
        $_SESSION['error'] = "Please select a student.";
        header("Location: notifications.php");
        exit;
    }

    // Insert query matches YOUR table columns
    $insert = $pdo->prepare("
        INSERT INTO notifications (user_id, title, message, type, related_type, related_id, is_read, read_at, created_at)
        VALUES (?, ?, ?, ?, NULL, NULL, 0, NULL, NOW())
    ");

    if ($target_type === 'all') {
        // ✅ One notification per student
        foreach ($students as $s) {
            $insert->execute([(int)$s['id'], $title, $message, $type]);
        }
        $_SESSION['success'] = "Notification sent to all students (" . count($students) . ").";
    } else {
        // ✅ One notification for selected student
        $insert->execute([(int)$target_id, $title, $message, $type]);
        $_SESSION['success'] = "Notification sent successfully.";
    }

    header("Location: notifications.php");
    exit;
}

$stmt = $pdo->query("
    SELECT
        n.title,
        n.message,
        n.type,
        MIN(n.created_at) AS created_at,
        CASE
            WHEN COUNT(*) > 1 THEN 'All Students'
            ELSE MAX(u.name)
        END AS receiver_name,
        COUNT(*) AS recipients,
        SUM(CASE WHEN n.is_read = 1 THEN 1 ELSE 0 END) AS read_count
    FROM notifications n
    LEFT JOIN users u ON u.id = n.user_id
    GROUP BY n.title, n.message, n.type, DATE_FORMAT(n.created_at, '%Y-%m-%d %H:%i')
    ORDER BY created_at DESC
");

$notifications = $stmt->fetchAll(PDO::FETCH_ASSOC);

$current = basename($_SERVER['PHP_SELF']);
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Notifications - ProgressMate</title>

    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=Roboto:wght@400;500;700&display=swap" rel="stylesheet">

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
  --danger-light: rgba(251,113,133,.12);
  --purple-light: rgba(124,58,237,.12);

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
a{ color:inherit; text-decoration:none; }

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

.dashboard-wrapper{
  display:grid;
  grid-template-columns: 320px 1fr;
  min-height:100vh;
}

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
.sidebar-header,
.user-profile,
.nav-menu,
.sidebar-quick-stats,
.sidebar-footer{ position:relative; z-index:2; }

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
.nav-menu::-webkit-scrollbar-thumb{ background: rgba(255,255,255,.16); border-radius: 99px; }
.nav-menu::-webkit-scrollbar-thumb:hover{ background: rgba(255,255,255,.22); }

.nav-link{
  position: relative;
  display:flex;
  align-items:center;
  gap: 12px;
  padding: 12px 12px;
  border-radius: 14px;
  color: rgba(234,240,255,.90);
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

.sidebar-footer{ flex: 0 0 auto; margin-top: 12px; }
.logout-btn{
  display:flex;
  align-items:center;
  justify-content:center;
  gap: 10px;
  padding: 12px 12px;
  border-radius: 14px;
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
.header-content h1{
  margin: 0 0 6px;
  font-size: 26px;
  font-weight: 950;
  letter-spacing: .2px;
}
.header-content p{ margin:0; color: var(--muted); font-size: 14px; }

.alert{
  margin-top: 16px;
  display:flex;
  align-items:flex-start;
  gap: 10px;
  padding: 12px 14px;
  border-radius: 16px;
  border: 1px solid rgba(255,255,255,.12);
  background: rgba(255,255,255,.04);
  box-shadow: var(--shadow2);
}
.alert i{ margin-top:2px; }
.alert span{ color: rgba(234,240,255,.92); font-weight: 650; }
.alert-success{
  border-color: rgba(52,211,153,.22);
  background: linear-gradient(180deg, rgba(52,211,153,.10), rgba(255,255,255,.03));
}
.alert-success i{ color: var(--success); }
.alert-error{
  border-color: rgba(251,113,133,.22);
  background: linear-gradient(180deg, rgba(251,113,133,.10), rgba(255,255,255,.03));
}
.alert-error i{ color: var(--danger); }

.form-card{
  margin-top: 16px;
  border-radius: var(--r20);
  border: 1px solid rgba(255,255,255,.10);
  background:
    radial-gradient(140% 220% at 10% 0%, rgba(255,255,255,.10), transparent 60%),
    linear-gradient(180deg, rgba(255,255,255,.05), rgba(255,255,255,.03));
  box-shadow: var(--shadow);
  padding: 16px;
}
.form-group{ margin-bottom: 14px; }
.form-group label{
  display:block;
  font-size: 13px;
  font-weight: 800;
  color: rgba(234,240,255,.86);
  margin-bottom: 6px;
}
.form-group input,
.form-group textarea,
.form-group select{
  width:100%;
  padding: 12px 12px;
  border-radius: 14px;
  border: 1px solid rgba(255,255,255,.12);
  background: rgba(0,0,0,.18);
  color: var(--text);
  outline: none;
  transition: box-shadow .18s ease, border-color .18s ease, background .18s ease;
}
.form-group textarea{ min-height: 120px; resize: vertical; }
.form-group input::placeholder,
.form-group textarea::placeholder{ color: rgba(234,240,255,.45); }
.form-group input:focus,
.form-group textarea:focus,
.form-group select:focus{
  border-color: rgba(34,211,238,.35);
  box-shadow: 0 0 0 3px rgba(34,211,238,.18);
  background: rgba(0,0,0,.22);
}

.btn{
  display:inline-flex;
  align-items:center;
  gap: 10px;
  padding: 12px 14px;
  border-radius: 14px;
  font-weight: 900;
  border: 1px solid rgba(255,255,255,.14);
  color: var(--text);
  background: rgba(255,255,255,.05);
  transition: transform .18s ease, box-shadow .18s ease, background .18s ease;
  cursor:pointer;
}
.btn:hover{
  transform: translateY(-1px);
  box-shadow: 0 0 0 1px rgba(255,255,255,.08), 0 12px 30px rgba(124,58,237,.18);
  background: rgba(255,255,255,.07);
}
.btn-primary{
  background:
    radial-gradient(120% 180% at 20% 10%, rgba(255,255,255,.16), transparent 55%),
    linear-gradient(135deg, rgba(124,58,237,.55), rgba(34,211,238,.18));
}

.notifications-table-container{
  margin-top: 16px;
  border-radius: var(--r20);
  border: 1px solid rgba(255,255,255,.10);
  background:
    radial-gradient(140% 220% at 10% 0%, rgba(255,255,255,.10), transparent 60%),
    linear-gradient(180deg, rgba(255,255,255,.05), rgba(255,255,255,.03));
  box-shadow: var(--shadow);
  overflow:hidden;
}
.notifications-table-container h3{
  margin: 0;
  padding: 14px 16px;
  font-size: 15px;
  font-weight: 950;
  letter-spacing: .2px;
  border-bottom: 1px solid rgba(255,255,255,.08);
  background: rgba(255,255,255,.03);
}

table{
  width:100%;
  border-collapse: collapse;
}
thead th{
  text-align:left;
  font-size: 12.5px;
  letter-spacing: .2px;
  color: rgba(234,240,255,.75);
  padding: 12px 16px;
  border-bottom: 1px solid rgba(255,255,255,.08);
  background: rgba(255,255,255,.02);
}
tbody td{
  padding: 12px 16px;
  border-bottom: 1px solid rgba(255,255,255,.06);
  color: rgba(234,240,255,.90);
  font-size: 13.5px;
}
tbody tr:hover{
  background: rgba(255,255,255,.04);
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

  .main-content{ padding: 22px 16px 36px; }
  .page-header{ flex-direction: column; align-items: flex-start; }
  .notifications-table-container{ overflow-x:auto; }
  table{ min-width: 820px; }
}

@media (max-width: 520px){
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

    <div class="dashboard-wrapper">

        <aside class="sidebar" id="sidebar">
            <div class="sidebar-header">
                <div class="logo"><i class="fas fa-star"></i><span>ProgressMate</span></div>
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
                    <span style="font-size:11px;background:#e0e7ff;color:#4f46e5;padding:2px 8px;border-radius:12px;">ADMIN</span>
                </div>
            </div>

            <nav class="nav-menu">
                <a href="admin.php" class="nav-link <?php echo $current === 'admin.php' ? 'active' : ''; ?>"><i class="fas fa-tachometer-alt"></i> Dashboard</a>

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

                <a href="reports.php" class="nav-link <?php echo $current === 'reports.php' ? 'active' : ''; ?>"><i class="fas fa-chart-bar"></i> Reports</a>
                <a href="notifications.php" class="nav-link <?php echo $current === 'notifications.php' ? 'active' : ''; ?>"><i class="fas fa-bell"></i> Notifications</a>
                <a href="categories.php" class="nav-link <?php echo $current === 'categories.php' ? 'active' : ''; ?>"><i class="fas fa-tags"></i> Categories</a>
                <a href="settings.php" class="nav-link <?php echo $current === 'settings.php' ? 'active' : ''; ?>"><i class="fas fa-cog"></i> Settings</a>
            </nav>

            <div class="sidebar-quick-stats">
                <div class="sidebar-stat">
                    <div class="sidebar-stat-icon"><i class="fas fa-users"></i></div>
                    <div>
                        <div class="sidebar-stat-label">Students</div>
                        <div class="sidebar-stat-number"><?php echo (int)($sidebar_stats['students'] ?? 0); ?></div>
                    </div>
                </div>
                <div class="sidebar-stat">
                    <div class="sidebar-stat-icon"><i class="fas fa-bullseye"></i></div>
                    <div>
                        <div class="sidebar-stat-label">Goals</div>
                        <div class="sidebar-stat-number"><?php echo (int)($sidebar_stats['goals'] ?? 0); ?></div>
                    </div>
                </div>
                <div class="sidebar-stat">
                    <div class="sidebar-stat-icon"><i class="fas fa-star"></i></div>
                    <div>
                        <div class="sidebar-stat-label">Points</div>
                        <div class="sidebar-stat-number"><?php echo (int)($sidebar_stats['points'] ?? 0); ?></div>
                    </div>
                </div>
            </div>

            <div class="sidebar-footer">
                <a href="../logout.php" class="logout-btn"><i class="fas fa-sign-out-alt"></i><span>Logout</span></a>
            </div>
        </aside>

        <main class="main-content">
            <header class="page-header">
                <div class="header-content">
                    <h1>Notifications</h1>
                    <p>Send and manage notifications to students</p>
                </div>
            </header>

            <?php if ($success): ?>
                <div class="alert alert-success"><i class="fas fa-check-circle"></i><span><?php echo htmlspecialchars($success); ?></span></div>
            <?php endif; ?>

            <?php if ($error): ?>
                <div class="alert alert-error"><i class="fas fa-exclamation-circle"></i><span><?php echo htmlspecialchars($error); ?></span></div>
            <?php endif; ?>

            <div class="form-card">
                <h3 style="margin-bottom:16px;font-size:18px;">Send New Notification</h3>

                <form method="POST">
                    <input type="hidden" name="action" value="send_notification">

                    <div class="form-group">
                        <label for="title">Title</label>
                        <input type="text" id="title" name="title" required placeholder="Enter notification title">
                    </div>

                    <div class="form-group">
                        <label for="message">Message</label>
                        <textarea id="message" name="message" required placeholder="Enter your message here"></textarea>
                    </div>

                    <div class="form-group">
                        <label for="target_type">Send To</label>
                        <select id="target_type" name="target_type" onchange="toggleStudentSelect(this)">
                            <option value="all">All Students</option>
                            <option value="student">Specific Student</option>
                        </select>
                    </div>

                    <div class="form-group" id="student_select_group" style="display:none;">
                        <label for="target_id">Select Student</label>
                        <select id="target_id" name="target_id">
                            <option value="">Choose a student</option>
                            <?php foreach ($students as $student): ?>
                                <option value="<?php echo (int)$student['id']; ?>"><?php echo htmlspecialchars($student['name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <button type="submit" class="btn btn-primary"><i class="fas fa-paper-plane"></i> Send Notification</button>
                </form>
            </div>

            <div class="notifications-table-container">
                <h3>Sent Notifications</h3>

                <?php if (empty($notifications)): ?>
                    <div class="empty-state">
                        <i class="fas fa-bell-slash"></i>
                        <p>No notifications yet</p>
                    </div>
                <?php else: ?>
                    <table>
                        <thead>
                            <tr>
                                <th>Target</th>
                                <th>Title</th>
                                <th>Message</th>
                                <th>Type</th>
                                <th>Sent At</th>
                                <th>Recipients</th>
                                <th>Read</th>

                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($notifications as $notif): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($notif['receiver_name'] ?? ''); ?></td>
                                    <td><?php echo htmlspecialchars($notif['title'] ?? ''); ?></td>
                                    <td style="max-width:320px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;"
                                        title="<?php echo htmlspecialchars($notif['message'] ?? ''); ?>">
                                        <?php echo htmlspecialchars($notif['message'] ?? ''); ?>
                                    </td>
                                    <td><?php echo htmlspecialchars($notif['type'] ?? 'system'); ?></td>
                                    <td><?php echo date('M d, Y h:i A', strtotime($notif['created_at'])); ?></td>
                                    <td><?php echo (int)($notif['recipients'] ?? 0); ?></td>
                                    <td>
                                        <?php
                                        $r = (int)($notif['recipients'] ?? 0);
                                        $c = (int)($notif['read_count'] ?? 0);
                                        $pct = $r > 0 ? round(($c / $r) * 100) : 0;
                                        echo $c . " ({$pct}%)";
                                        ?>
                                    </td>

                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>

        </main>
    </div>

    <script>
        const sidebarToggle = document.getElementById('sidebarToggle');
        const sidebar = document.getElementById('sidebar');
        const sidebarClose = document.getElementById('sidebarClose');

        if (sidebarToggle) sidebarToggle.addEventListener('click', () => sidebar.classList.add('active'));
        if (sidebarClose) sidebarClose.addEventListener('click', () => sidebar.classList.remove('active'));

        document.addEventListener('click', function(event) {
            if (window.innerWidth <= 768 &&
                sidebar.classList.contains('active') &&
                !sidebar.contains(event.target) &&
                !sidebarToggle.contains(event.target)
            ) {
                sidebar.classList.remove('active');
            }
        });

        window.addEventListener('resize', function() {
            if (window.innerWidth > 768 && sidebar.classList.contains('active')) {
                sidebar.classList.remove('active');
            }
        });

        function toggleStudentSelect(select) {
            const group = document.getElementById('student_select_group');
            group.style.display = select.value === 'student' ? 'block' : 'none';
        }
    </script>
</body>

</html>