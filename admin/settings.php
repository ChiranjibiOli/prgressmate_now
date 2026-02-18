<?php
session_start();
require_once '../includes/db_connection.php';
require_once '../includes/functions.php';
checkAuth('admin');

$success = $_SESSION['success'] ?? '';
$error = $_SESSION['error'] ?? '';
unset($_SESSION['success'], $_SESSION['error']);

// Get current admin data
$admin_id = $_SESSION['user_id'];
try {
    $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ? AND role = 'admin'");
    $stmt->execute([$admin_id]);
    $admin = $stmt->fetch();
} catch (Exception $e) {
    $error = "Error fetching profile: " . $e->getMessage();
    $admin = [];
}

// Handle profile update
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'update_profile') {
        $name = trim($_POST['name'] ?? '');
        $email = trim($_POST['email'] ?? '');

        if (empty($name) || empty($email)) {
            $error = "Name and email are required.";
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = "Invalid email format.";
        } else {
            try {
                // Handle profile picture upload
                $profile_picture = $admin['profile_picture'] ?? '';
                if (isset($_FILES['profile_picture']) && $_FILES['profile_picture']['error'] === 0) {
                    $upload_dir = '../uploads/profiles/';
                    if (!is_dir($upload_dir)) {
                        mkdir($upload_dir, 0755, true);
                    }
                    $file_name = 'admin_' . $admin_id . '_' . time() . '.' . pathinfo($_FILES['profile_picture']['name'], PATHINFO_EXTENSION);
                    $target_path = $upload_dir . $file_name;

                    if (move_uploaded_file($_FILES['profile_picture']['tmp_name'], $target_path)) {
                        $profile_picture = 'uploads/profiles/' . $file_name;
                        // Delete old picture if exists
                        if ($admin['profile_picture'] && file_exists('../' . $admin['profile_picture'])) {
                            unlink('../' . $admin['profile_picture']);
                        }
                    } else {
                        $error = "Error uploading profile picture.";
                    }
                }

                if (!$error) {
                    $stmt = $pdo->prepare("UPDATE users SET name = ?, email = ?, profile_picture = ? WHERE id = ?");
                    $stmt->execute([$name, $email, $profile_picture, $admin_id]);

                    $_SESSION['name'] = $name;
                    $_SESSION['email'] = $email;
                    $_SESSION['profile_picture'] = $profile_picture;

                    $success = "Profile updated successfully!";
                }
            } catch (Exception $e) {
                $error = "Error updating profile: " . $e->getMessage();
            }
        }
    } elseif ($action === 'change_password') {
        $current_password = $_POST['current_password'] ?? '';
        $new_password = $_POST['new_password'] ?? '';
        $confirm_password = $_POST['confirm_password'] ?? '';

        if (empty($current_password) || empty($new_password) || empty($confirm_password)) {
            $error = "All password fields are required.";
        } elseif ($new_password !== $confirm_password) {
            $error = "New passwords do not match.";
        } elseif (strlen($new_password) < 8) {
            $error = "New password must be at least 8 characters.";
        } else {
            try {
                if (password_verify($current_password, $admin['password'])) {
                    $hashed_password = password_hash($new_password, PASSWORD_BCRYPT);
                    $stmt = $pdo->prepare("UPDATE users SET password = ? WHERE id = ?");
                    $stmt->execute([$hashed_password, $admin_id]);
                    $success = "Password changed successfully!";
                } else {
                    $error = "Current password is incorrect.";
                }
            } catch (Exception $e) {
                $error = "Error changing password: " . $e->getMessage();
            }
        }
    }
}

// Get admin stats for sidebar
$sidebar_stats = [
    'students' => getStat($pdo, "SELECT COUNT(*) FROM users WHERE role = 'student' AND status = 'active'"),
    'goals' => getStat($pdo, "SELECT COUNT(*) FROM admin_goals WHERE status = 'active'"),
    'assigned' => getStat($pdo, "SELECT COUNT(*) FROM student_goals"),
    'points' => getStat($pdo, "SELECT COALESCE(SUM(a.points), 0) FROM user_achievements ua JOIN achievements a ON ua.achievement_id = a.id")
];

$current = basename($_SERVER['PHP_SELF']);

// Sidebar stats (badges)
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
    <title>Settings - ProgressMate</title>
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
.sidebar-stat-info{ display:flex; flex-direction:column; gap:2px; }
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
  border: 1px solid rgba(255,255,255,.10);
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

.settings-section{
  margin-top: 16px;
  border-radius: var(--r20);
  border: 1px solid rgba(255,255,255,.10);
  background:
    radial-gradient(140% 220% at 10% 0%, rgba(255,255,255,.10), transparent 60%),
    linear-gradient(180deg, rgba(255,255,255,.05), rgba(255,255,255,.03));
  box-shadow: var(--shadow);
  padding: 16px;
}
.settings-section h3{
  margin: 0 0 12px;
  font-size: 16px;
  font-weight: 950;
  letter-spacing: .2px;
  display:flex;
  align-items:center;
  gap: 10px;
}
.settings-section h3 i{
  width: 34px;
  height: 34px;
  display:grid;
  place-items:center;
  border-radius: 14px;
  border: 1px solid rgba(255,255,255,.12);
  background: rgba(255,255,255,.05);
}

.profile-preview{
  display:flex;
  gap: 14px;
  align-items:center;
  padding: 12px 12px;
  border-radius: 16px;
  border: 1px solid rgba(255,255,255,.08);
  background: rgba(255,255,255,.03);
  margin-bottom: 14px;
}
.profile-pic-large{
  width: 84px;
  height: 84px;
  border-radius: 22px;
  object-fit: cover;
  border: 1px solid rgba(255,255,255,.16);
  box-shadow: 0 16px 30px rgba(0,0,0,.22);
}
.profile-pic-large.default{
  display:grid;
  place-items:center;
  font-weight: 950;
  font-size: 26px;
  color: var(--text);
  background:
    radial-gradient(120% 140% at 30% 25%, rgba(255,255,255,.18), transparent 55%),
    linear-gradient(135deg, rgba(34,211,238,.45), rgba(124,58,237,.55));
}

.form-row{
  display:grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 12px;
}
.form-group{ margin-bottom: 14px; }
.form-group label{
  display:block;
  font-size: 13px;
  font-weight: 800;
  color: rgba(234,240,255,.86);
  margin-bottom: 6px;
}
.form-group input{
  width:100%;
  padding: 12px 12px;
  border-radius: 14px;
  border: 1px solid rgba(255,255,255,.12);
  background: rgba(0,0,0,.18);
  color: var(--text);
  outline: none;
  transition: box-shadow .18s ease, border-color .18s ease, background .18s ease;
}
.form-group input::placeholder{ color: rgba(234,240,255,.45); }
.form-group input:focus{
  border-color: rgba(34,211,238,.35);
  box-shadow: 0 0 0 3px rgba(34,211,238,.18);
  background: rgba(0,0,0,.22);
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

.upload-label{ cursor:pointer; }

.password-form .form-group{ max-width: 560px; }

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
  .form-row{ grid-template-columns: 1fr; }
  .profile-preview{ flex-direction: column; align-items: flex-start; }
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
    <!-- Mobile Menu Toggle -->
    <button class="mobile-toggle" id="sidebarToggle">
        <i class="fas fa-bars"></i>
    </button>

    <div class="dashboard-wrapper">
        <!-- Sidebar -->
        <aside class="sidebar" id="sidebar">
            <div class="sidebar-header">
                <div class="logo">
                    <i class="fas fa-star"></i>
                    <span>ProgressMate</span>
                </div>
                <button class="sidebar-close" id="sidebarClose">
                    <i class="fas fa-times"></i>
                </button>
            </div>

            <div class="user-profile">
                <?php if (!empty($_SESSION['profile_picture'])): ?>
                    <img src="../<?php echo htmlspecialchars($_SESSION['profile_picture']); ?>" alt="Profile" class="profile-pic">
                <?php else: ?>
                    <div class="profile-pic default">
                        <?php echo strtoupper(substr($_SESSION['name'], 0, 1)); ?>
                    </div>
                <?php endif; ?>
                <div class="user-info">
                    <h4><?php echo htmlspecialchars($_SESSION['name']); ?></h4>
                    <p><?php echo htmlspecialchars($_SESSION['email']); ?></p>
                    <span style="font-size: 11px; background: #e0e7ff; color: #4f46e5; padding: 2px 8px; border-radius: 12px;">
                        ADMIN
                    </span>
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
                    <div class="sidebar-stat-icon">
                        <i class="fas fa-users"></i>
                    </div>
                    <div class="sidebar-stat-info">
                        <div class="sidebar-stat-label">Students</div>
                        <div class="sidebar-stat-number"><?php echo $sidebar_stats['students']; ?></div>
                    </div>
                </div>
                <div class="sidebar-stat">
                    <div class="sidebar-stat-icon">
                        <i class="fas fa-bullseye"></i>
                    </div>
                    <div class="sidebar-stat-info">
                        <div class="sidebar-stat-label">Goals</div>
                        <div class="sidebar-stat-number"><?php echo $sidebar_stats['goals']; ?></div>
                    </div>
                </div>
                <div class="sidebar-stat">
                    <div class="sidebar-stat-icon">
                        <i class="fas fa-star"></i>
                    </div>
                    <div class="sidebar-stat-info">
                        <div class="sidebar-stat-label">Points</div>
                        <div class="sidebar-stat-number"><?php echo $sidebar_stats['points']; ?></div>
                    </div>
                </div>
            </div>

            <div class="sidebar-footer">
                <a href="../logout.php" class="logout-btn">
                    <i class="fas fa-sign-out-alt"></i>
                    <span>Logout</span>
                </a>
            </div>
        </aside>

        <!-- Main Content -->
        <main class="main-content">
            <header class="page-header">
                <div class="header-content">
                    <h1>Settings</h1>
                    <p>Manage your account settings</p>
                </div>
            </header>

            <?php if ($success): ?>
                <div class="alert alert-success">
                    <i class="fas fa-check-circle"></i>
                    <span><?php echo htmlspecialchars($success); ?></span>
                </div>
            <?php endif; ?>

            <?php if ($error): ?>
                <div class="alert alert-error">
                    <i class="fas fa-exclamation-circle"></i>
                    <span><?php echo htmlspecialchars($error); ?></span>
                </div>
            <?php endif; ?>

            <!-- Profile Settings -->
            <div class="settings-section">
                <h3><i class="fas fa-user-edit"></i> Update Profile</h3>
                <form method="POST" enctype="multipart/form-data">
                    <input type="hidden" name="action" value="update_profile">

                    <div class="profile-preview">
                        <?php if (!empty($admin['profile_picture'])): ?>
                            <img src="../<?php echo htmlspecialchars($admin['profile_picture']); ?>" alt="Profile" class="profile-pic-large">
                        <?php else: ?>
                            <div class="profile-pic-large default">
                                <?php echo strtoupper(substr($admin['name'], 0, 1)); ?>
                            </div>
                        <?php endif; ?>
                        <div>
                            <label for="profile_picture" class="btn btn-outline upload-label">
                                <i class="fas fa-upload"></i> Upload New Picture
                            </label>
                            <input type="file" id="profile_picture" name="profile_picture" accept="image/*" style="display: none;" onchange="previewImage(this)">
                            <p style="font-size: 12px; color: #6b7280; margin-top: 5px;">Recommended size: 200x200px. Max 2MB.</p>
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label for="name">Full Name</label>
                            <input type="text" id="name" name="name" required value="<?php echo htmlspecialchars($admin['name']); ?>">
                        </div>

                        <div class="form-group">
                            <label for="email">Email Address</label>
                            <input type="email" id="email" name="email" required value="<?php echo htmlspecialchars($admin['email']); ?>">
                        </div>
                    </div>

                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-save"></i> Update Profile
                    </button>
                </form>
            </div>

            <!-- Password Change -->
            <div class="settings-section password-form">
                <h3><i class="fas fa-lock"></i> Change Password</h3>
                <form method="POST">
                    <input type="hidden" name="action" value="change_password">

                    <div class="form-group">
                        <label for="current_password">Current Password</label>
                        <input type="password" id="current_password" name="current_password" required placeholder="Enter current password">
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label for="new_password">New Password</label>
                            <input type="password" id="new_password" name="new_password" required minlength="8" placeholder="Enter new password">
                        </div>

                        <div class="form-group">
                            <label for="confirm_password">Confirm New Password</label>
                            <input type="password" id="confirm_password" name="confirm_password" required minlength="8" placeholder="Confirm new password">
                        </div>
                    </div>

                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-key"></i> Change Password
                    </button>
                </form>
            </div>

            <!-- System Settings -->
            <div class="settings-section">
                <h3><i class="fas fa-cogs"></i> System Settings</h3>
                <p>System-wide settings are managed by the development team. For custom configurations or feature requests, please contact support.</p>
                <a href="mailto:support@progressmate.com" class="btn btn-outline">
                    <i class="fas fa-envelope"></i> Contact Support
                </a>
            </div>
        </main>
    </div>

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
            if (window.innerWidth <= 768 && sidebar.classList.contains('active') && !sidebar.contains(event.target) && !sidebarToggle.contains(event.target)) {
                sidebar.classList.remove('active');
            }
        });

        // Handle window resize
        window.addEventListener('resize', function() {
            if (window.innerWidth > 768 && sidebar.classList.contains('active')) {
                sidebar.classList.remove('active');
            }
        });

      function previewImage(input) {
  if (input.files && input.files[0]) {
    const reader = new FileReader();

    reader.onload = function (e) {
      let preview = document.querySelector('.profile-pic-large');

      // if it's not an image, replace the div with an img
      if (preview.tagName.toLowerCase() !== 'img') {
        const img = document.createElement('img');
        img.className = 'profile-pic-large';
        preview.replaceWith(img);
        preview = img;
      }

      preview.src = e.target.result;
    };

    reader.readAsDataURL(input.files[0]);
  }
}

    </script>
</body>

</html>