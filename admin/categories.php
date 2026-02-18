<?php
session_start();
require_once '../includes/db_connection.php';
require_once '../includes/functions.php';
checkAuth('admin');

// CSRF Token
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];

// POST handling
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($csrf_token, $_POST['csrf_token'] ?? '')) {
        $_SESSION['error'] = 'Invalid request.';
    } else {
        $action = $_POST['action'] ?? '';
        try {
            if ($action === 'create' || $action === 'update') {
                $name = trim($_POST['name'] ?? '');
                $description = trim($_POST['description'] ?? '');
                $color = preg_match('/^#[a-f0-9]{6}$/i', $_POST['color'] ?? '') ? $_POST['color'] : '#6366f1';
                $icon = trim($_POST['icon'] ?? 'fas fa-book');

                if (empty($name)) {
                    throw new Exception('Category name is required.');
                }

                if ($action === 'create') {
                    $stmt = $pdo->prepare("
                        INSERT INTO categories 
                        (name, description, color, icon, is_global, created_by, created_at) 
                        VALUES (?, ?, ?, ?, 1, ?, NOW())
                    ");
                    $stmt->execute([$name, $description, $color, $icon, $_SESSION['user_id']]);
                    $_SESSION['success'] = 'Category created successfully.';
                } else {
                    $id = (int)($_POST['id'] ?? 0);
                    if ($id <= 0) {
                        throw new Exception('Invalid category ID.');
                    }
                    $stmt = $pdo->prepare("
                        UPDATE categories 
                        SET name = ?, description = ?, color = ?, icon = ? 
                        WHERE id = ? AND deleted_at IS NULL
                    ");
                    $stmt->execute([$name, $description, $color, $icon, $id]);
                    $_SESSION['success'] = 'Category updated successfully.';
                }
            } elseif ($action === 'delete') {
                $id = (int)($_POST['id'] ?? 0);
                if ($id <= 0) {
                    throw new Exception('Invalid category ID.');
                }
                $pdo->prepare("UPDATE categories SET deleted_at = NOW() WHERE id = ?")->execute([$id]);
                $_SESSION['success'] = 'Category deleted successfully.';
            }
        } catch (Exception $e) {
            $_SESSION['error'] = $e->getMessage();
        }
    }
    header('Location: categories.php');
    exit();
}

// Flash messages
$success = $_SESSION['success'] ?? '';
$error = $_SESSION['error'] ?? '';
unset($_SESSION['success'], $_SESSION['error']);

// Fetch categories
$categories = $pdo->query("
    SELECT * FROM categories 
    WHERE deleted_at IS NULL 
    ORDER BY name ASC
")->fetchAll(PDO::FETCH_ASSOC);

$current = basename($_SERVER['PHP_SELF']);

// === Sidebar Stats (REAL) ===
$total_students = (int)($pdo->query("SELECT COUNT(*) FROM users WHERE role='student'")->fetchColumn() ?? 0);
$total_goals    = (int)($pdo->query("SELECT COUNT(*) FROM admin_goals")->fetchColumn() ?? 0);
$total_points   = (int)($pdo->query("SELECT COALESCE(SUM(points),0) FROM users WHERE role='student'")->fetchColumn() ?? 0);

$sidebar_stats = [
    'students' => $total_students,
    'goals'    => $total_goals,
    'assigned' => 0,
    'points'   => $total_points
];
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
    <title>Manage Categories - ProgressMate</title>
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
  --primary-light: rgba(124,58,237,.14);

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
.page-header h1{
  margin: 0 0 6px;
  font-size: 26px;
  font-weight: 950;
  letter-spacing: .2px;
}
.page-header p{ margin:0; color: var(--muted); font-size: 14px; }

.alert-success,
.alert-error{
  margin-top: 16px;
  padding: 12px 14px;
  border-radius: 16px;
  border: 1px solid rgba(255,255,255,.12);
  box-shadow: var(--shadow2);
  font-weight: 650;
}
.alert-success{
  border-color: rgba(52,211,153,.22);
  background: linear-gradient(180deg, rgba(52,211,153,.10), rgba(255,255,255,.03));
  color: rgba(234,240,255,.92);
}
.alert-error{
  border-color: rgba(251,113,133,.22);
  background: linear-gradient(180deg, rgba(251,113,133,.10), rgba(255,255,255,.03));
  color: rgba(234,240,255,.92);
}

.grid{
  margin-top: 16px;
  display:grid;
  grid-template-columns: repeat(3, minmax(0, 1fr));
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
  padding: 16px;
}
.card p{
  margin: 12px 0 0;
  color: rgba(234,240,255,.75);
  line-height: 1.45;
  font-size: 13.5px;
}

.category-tag{
  display:inline-flex;
  align-items:center;
  gap: 10px;
  padding: 10px 12px;
  border-radius: 16px;
  font-weight: 900;
  color: rgba(255,255,255,.95);
  border: 1px solid rgba(255,255,255,.18);
  box-shadow: 0 12px 26px rgba(0,0,0,.18);
}
.category-tag i{
  width: 34px;
  height: 34px;
  border-radius: 12px;
  display:grid;
  place-items:center;
  background: rgba(0,0,0,.18);
  border: 1px solid rgba(255,255,255,.18);
}

.form-group label{
  display:block;
  font-size: 13px;
  font-weight: 800;
  color: rgba(234,240,255,.86);
  margin-bottom: 6px;
}
.form-group input[type="text"],
.form-group textarea,
.form-group input[type="color"]{
  width:100%;
  padding: 12px 12px;
  border-radius: 14px;
  border: 1px solid rgba(255,255,255,.12);
  background: rgba(0,0,0,.18);
  color: var(--text);
  outline:none;
}
.form-group textarea{ min-height: 110px; resize: vertical; }
.form-row{
  display:grid;
  grid-template-columns: 160px 1fr;
  gap: 12px;
}
.form-group input[type="color"]{
  height: 44px;
  padding: 6px;
}

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
  transition: transform .18s ease, box-shadow .18s ease, background .18s ease, border-color .18s ease;
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
.btn-outline{
  background: rgba(255,255,255,.04);
}
.btn-danger{
  border-color: rgba(251,113,133,.30);
  background: linear-gradient(135deg, rgba(251,113,133,.18), rgba(255,255,255,.03));
}
.btn-danger:hover{
  box-shadow: 0 0 0 1px rgba(255,255,255,.08), 0 12px 30px rgba(251,113,133,.16);
}

.modal{
  position: fixed;
  inset: 0;
  display:none;
  place-items:center;
  padding: 16px;
  background: rgba(2,6,23,.62);
  z-index: 2500;
}
.modal.active{ display:grid; }
.modal-content{
  width: min(560px, 100%);
  border-radius: 20px;
  border: 1px solid rgba(255,255,255,.14);
  background:
    radial-gradient(140% 220% at 10% 0%, rgba(255,255,255,.10), transparent 60%),
    linear-gradient(180deg, rgba(10,14,35,.92), rgba(10,14,35,.74));
  box-shadow: 0 30px 90px rgba(0,0,0,.55);
  padding: 18px 18px 16px;
}
.modal-content h2{
  margin: 0 0 12px;
  font-size: 18px;
  font-weight: 950;
  letter-spacing: .2px;
}

@media (max-width: 1100px){
  .grid{ grid-template-columns: repeat(2, minmax(0, 1fr)); }
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
  .grid{ grid-template-columns: 1fr; }
}
@media (max-width: 520px){
  .user-info p{ max-width: 160px; }
  .form-row{ grid-template-columns: 1fr; }
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
                <a href="../logout.php" class="logout-btn"><i class="fas fa-sign-out-alt"></i> Logout</a>
            </div>
        </aside>

        <main class="main-content">
            <header class="page-header">
                <div>
                    <h1>Manage Categories</h1>
                    <p>Create and organize goal categories</p>
                </div>
                <button class="btn btn-primary" onclick="openModal()">Add New Category</button>
            </header>

            <?php if ($success): ?>
                <div class="alert-success"><?php echo htmlspecialchars($success); ?></div>
            <?php endif; ?>
            <?php if ($error): ?>
                <div class="alert-error"><?php echo htmlspecialchars($error); ?></div>
            <?php endif; ?>

            <div class="grid">
                <?php if (empty($categories)): ?>
                    <div class="card" style="text-align:center; grid-column:1/-1; padding:60px;">
                        <i class="fas fa-tags" style="font-size:48px; color:var(--gray-500); margin-bottom:16px;"></i>
                        <p>No categories yet. Create your first one!</p>
                    </div>
                <?php else: ?>
                    <?php foreach ($categories as $cat): ?>
                        <div class="card">
                            <div class="category-tag" style="background:<?php echo htmlspecialchars($cat['color']); ?>;">
                                <i class="<?php echo htmlspecialchars($cat['icon']); ?>"></i>
                                <?php echo htmlspecialchars($cat['name']); ?>
                            </div>
                            <p><?php echo htmlspecialchars($cat['description'] ?: 'No description'); ?></p>
                            <div style="display:flex; gap:12px; margin-top:20px;">
                                <button class="btn btn-outline" onclick='editCategory(<?php echo json_encode($cat, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>)'>Edit</button>
                                <form method="POST" style="display:inline;">
                                    <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="id" value="<?php echo $cat['id']; ?>">
                                    <button type="submit" class="btn btn-danger" onclick="return confirm('Delete this category? Goals will retain the category name.')">Delete</button>
                                </form>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </main>
    </div>

    <!-- Modal -->
    <div class="modal" id="categoryModal">
        <div class="modal-content">
            <h2 id="modalTitle">Add Category</h2>
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                <input type="hidden" name="action" id="modalAction" value="create">
                <input type="hidden" name="id" id="modalId">

                <div class="form-group">
                    <label>Name <span style="color:var(--danger);">*</span></label>
                    <input type="text" name="name" id="modalName" required placeholder="e.g., Mathematics">
                </div>

                <div class="form-group">
                    <label>Description</label>
                    <textarea name="description" id="modalDesc" placeholder="Brief description of this category"></textarea>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label>Color</label>
                        <input type="color" name="color" id="modalColor" value="#6366f1">
                    </div>
                    <div class="form-group">
                        <label>Icon (Font Awesome class)</label>
                        <input type="text" name="icon" id="modalIcon" value="fas fa-book" placeholder="e.g., fas fa-calculator">
                    </div>
                </div>

                <div style="display:flex; gap:12px; margin-top:24px;">
                    <button type="submit" class="btn btn-primary">Save Category</button>
                    <button type="button" class="btn btn-outline" onclick="closeModal()">Cancel</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        const modal = document.getElementById('categoryModal');
        const sidebar = document.getElementById('sidebar');
        const sidebarToggle = document.getElementById('sidebarToggle');
        const sidebarClose = document.getElementById('sidebarClose');
        const overlay = document.getElementById('sidebarOverlay');

        function openModal() {
            document.getElementById('modalTitle').textContent = 'Add Category';
            document.getElementById('modalAction').value = 'create';
            document.getElementById('modalId').value = '';
            document.getElementById('modalName').value = '';
            document.getElementById('modalDesc').value = '';
            document.getElementById('modalColor').value = '#6366f1';
            document.getElementById('modalIcon').value = 'fas fa-book';
            modal.classList.add('active');
        }

        function editCategory(cat) {
            document.getElementById('modalTitle').textContent = 'Edit Category';
            document.getElementById('modalAction').value = 'update';
            document.getElementById('modalId').value = cat.id;
            document.getElementById('modalName').value = cat.name;
            document.getElementById('modalDesc').value = cat.description || '';
            document.getElementById('modalColor').value = cat.color;
            document.getElementById('modalIcon').value = cat.icon;
            modal.classList.add('active');
        }

        function closeModal() {
            modal.classList.remove('active');
        }

        modal.addEventListener('click', e => {
            if (e.target === modal) closeModal();
        });

        // Mobile sidebar
        sidebarToggle.addEventListener('click', () => {
            sidebar.classList.add('active');
            overlay.classList.add('active');
        });
        sidebarClose.addEventListener('click', () => {
            sidebar.classList.remove('active');
            overlay.classList.remove('active');
        });
        overlay.addEventListener('click', () => {
            sidebar.classList.remove('active');
            overlay.classList.remove('active');
        });
    </script>
</body>

</html>