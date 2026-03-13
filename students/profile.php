<?php
// students/profile.php
session_start();
require_once '../includes/db_connection.php';
require_once '../includes/functions.php';
checkAuth('student');

$student_id = $_SESSION['user_id'] ?? 0;
$error = ''; $success = '';

$student = ['name'=>'','email'=>'','profile_picture'=>null,'student_id'=>null,'department'=>null,'semester'=>null,'bio'=>null,'created_at'=>date('Y-m-d H:i:s'),'last_login'=>null,'total_goals'=>0,'completed_goals'=>0,'total_achievements'=>0,'total_points'=>0];

try {
    $stmt = $pdo->prepare("
        SELECT u.*,
               COUNT(DISTINCT sg.id) as total_goals,
               SUM(CASE WHEN sg.status='completed' THEN 1 ELSE 0 END) as completed_goals,
               SUM(CASE WHEN sg.status='in_progress' THEN 1 ELSE 0 END) as in_progress_goals,
               COALESCE(u.points,0) as total_points,
               COUNT(DISTINCT ua.achievement_id) as total_achievements
        FROM users u
        LEFT JOIN student_goals sg ON u.id=sg.student_id AND sg.deleted_at IS NULL
        LEFT JOIN user_achievements ua ON u.id=ua.user_id
        WHERE u.id=? GROUP BY u.id");
    $stmt->execute([$student_id]);
    $result = $stmt->fetch();
    if ($result) $student = array_merge($student, $result);
    else { header("Location: ../login.php"); exit; }
} catch (Exception $e) { $error = "Error loading profile: ".$e->getMessage(); }

// Handle POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['update_profile'])) {
        $name = trim($_POST['name'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $sid = trim($_POST['student_id'] ?? '');
        $dept = trim($_POST['department'] ?? '');
        $sem = trim($_POST['semester'] ?? '');
        $bio = trim($_POST['bio'] ?? '');
        if (empty($name)||empty($email)) { $error = "Name and email are required."; }
        elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) { $error = "Invalid email address."; }
        else {
            try {
                $chk = $pdo->prepare("SELECT id FROM users WHERE email=? AND id!=?"); $chk->execute([$email,$student_id]);
                if ($chk->fetch()) { $error = "Email already in use."; }
                else {
                    if (!empty($sid)) { $chk2=$pdo->prepare("SELECT id FROM users WHERE student_id=? AND id!=?"); $chk2->execute([$sid,$student_id]); if ($chk2->fetch()) { $error="Student ID already taken."; } }
                    if (empty($error)) {
                        $pdo->beginTransaction();
                        $pdo->prepare("UPDATE users SET name=?,email=?,student_id=?,department=?,semester=?,bio=?,updated_at=NOW() WHERE id=?")->execute([$name,$email,$sid,$dept,$sem,$bio,$student_id]);
                        $_SESSION['name']=$name; $_SESSION['email']=$email;
                        $pdo->commit(); $success="Profile updated successfully!";
                        $stmt->execute([$student_id]); $r=$stmt->fetch(); if($r) $student=array_merge($student,$r);
                    }
                }
            } catch(Exception $e){ $pdo->rollBack(); $error="Error: ".$e->getMessage(); }
        }
    } elseif (isset($_POST['change_password'])) {
        $cur=$_POST['current_password']??''; $new=$_POST['new_password']??''; $con=$_POST['confirm_password']??'';
        if (empty($cur)||empty($new)||empty($con)) { $error="All password fields required."; }
        elseif ($new!==$con) { $error="New passwords do not match."; }
        elseif (strlen($new)<6) { $error="Password must be at least 6 characters."; }
        else {
            try {
                $pw=$pdo->prepare("SELECT password FROM users WHERE id=?"); $pw->execute([$student_id]); $u=$pw->fetch();
                if ($u && password_verify($cur,$u['password'])) {
                    $pdo->prepare("UPDATE users SET password=?,updated_at=NOW() WHERE id=?")->execute([password_hash($new,PASSWORD_BCRYPT),$student_id]);
                    $success="Password changed successfully!";
                } else { $error="Current password is incorrect."; }
            } catch(Exception $e){ $error="Error: ".$e->getMessage(); }
        }
    } elseif (isset($_POST['upload_photo']) && isset($_FILES['profile_picture'])) {
        $file=$_FILES['profile_picture'];
        if ($file['error']!==UPLOAD_ERR_OK) { $error="Upload failed (error ".$file['error'].")."; }
        else {
            $ft=mime_content_type($file['tmp_name']);
            if (!in_array($ft,['image/jpeg','image/jpg','image/png','image/gif'])) { $error="Only JPG, PNG, GIF allowed."; }
            elseif ($file['size']>2*1024*1024) { $error="Max file size 2MB."; }
            else {
                try {
                    $dir='../uploads/profiles/'; if(!file_exists($dir)) mkdir($dir,0777,true);
                    $ext=pathinfo($file['name'],PATHINFO_EXTENSION);
                    $fn='profile_'.$student_id.'_'.time().'.'.$ext;
                    if (move_uploaded_file($file['tmp_name'],$dir.$fn)) {
                        if (!empty($student['profile_picture'])&&file_exists('../'.$student['profile_picture'])) unlink('../'.$student['profile_picture']);
                        $rp='uploads/profiles/'.$fn;
                        $pdo->prepare("UPDATE users SET profile_picture=?,updated_at=NOW() WHERE id=?")->execute([$rp,$student_id]);
                        $_SESSION['profile_picture']=$rp; $student['profile_picture']=$rp;
                        $success="Profile picture updated!";
                    } else { $error="Failed to save file."; }
                } catch(Exception $e){ $error="Error: ".$e->getMessage(); }
            }
        }
    } elseif (isset($_POST['delete_photo'])) {
        try {
            if (!empty($student['profile_picture'])&&file_exists('../'.$student['profile_picture'])) unlink('../'.$student['profile_picture']);
            $pdo->prepare("UPDATE users SET profile_picture=NULL,updated_at=NOW() WHERE id=?")->execute([$student_id]);
            $_SESSION['profile_picture']=null; $student['profile_picture']=null;
            $success="Profile picture removed.";
        } catch(Exception $e){ $error="Error: ".$e->getMessage(); }
    }
}

// Sidebar stats
$stmt2=$pdo->prepare("SELECT COUNT(*) FROM notifications WHERE user_id=? AND is_read=0 AND deleted_at IS NULL"); $stmt2->execute([$student_id]); $unread=$stmt2->fetchColumn()?:0;
$stmt2=$pdo->prepare("SELECT COALESCE(current_streak,0) FROM users WHERE id=?"); $stmt2->execute([$student_id]); $streak=$stmt2->fetchColumn()?:0;
$current=basename($_SERVER['PHP_SELF']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Profile — ProgressMate</title>
<?php require_once '../includes/student_nav.php'; nav_head(); ?>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
<style>
/* ── Page Header (matches all other student pages) ── */
.page-header{
  width:100%;display:flex;align-items:flex-start;justify-content:space-between;
  gap:14px;flex-wrap:wrap;padding:18px 20px;border-radius:var(--r20);
  border:1px solid var(--border);
  background:radial-gradient(120% 220% at 15% 10%,rgba(255,255,255,.10),transparent 55%),
             linear-gradient(180deg,rgba(255,255,255,.05),rgba(255,255,255,.03));
  box-shadow:var(--shadow2);margin-bottom:22px;
}
.page-header h1{margin:0 0 4px;font-size:22px;font-weight:900;}
.page-header p{margin:0;color:var(--muted);font-size:13px;}
.hdr-actions{display:flex;gap:10px;align-items:center;flex-wrap:wrap;}
.btn{display:inline-flex;align-items:center;justify-content:center;gap:8px;
  padding:10px 16px;border-radius:14px;border:1px solid var(--border);
  background:rgba(255,255,255,.05);color:var(--text);font-weight:700;font-size:13px;
  transition:.18s;cursor:pointer;font-family:inherit;text-decoration:none;}
.btn:hover{transform:translateY(-1px);background:rgba(255,255,255,.08);}
.btn-primary{
  background:radial-gradient(120% 160% at 10% 20%,rgba(255,255,255,.14),transparent 55%),
             linear-gradient(135deg,rgba(79,70,229,.85),rgba(34,211,238,.25));
  border-color:rgba(255,255,255,.18);box-shadow:0 10px 30px rgba(79,70,229,.22);}
.btn-danger{background:rgba(251,113,133,.10);border-color:rgba(251,113,133,.28);color:var(--danger);}
.btn-danger:hover{background:rgba(251,113,133,.20);}
.btn-sm{padding:7px 12px;font-size:12px;border-radius:11px;}
.alert{display:flex;align-items:center;gap:12px;padding:13px 16px;border-radius:16px;
  border:1px solid var(--border);background:rgba(255,255,255,.04);margin-bottom:16px;font-size:14px;}
.alert i{width:32px;height:32px;display:grid;place-items:center;border-radius:10px;
  background:rgba(255,255,255,.06);border:1px solid rgba(255,255,255,.12);flex-shrink:0;}
.alert-success{border-color:rgba(52,211,153,.30);} .alert-success i{color:var(--success);}
.alert-error{border-color:rgba(251,113,133,.30);}   .alert-error i{color:var(--danger);}

/* ── Profile Hero ── */
.profile-hero {
  display:grid; grid-template-columns:auto 1fr; gap:24px;
  padding:24px; border-radius:20px; border:1px solid var(--border);
  background: radial-gradient(120% 220% at 5% 5%, rgba(79,70,229,.16), transparent 55%),
              linear-gradient(180deg,rgba(255,255,255,.06),rgba(255,255,255,.02));
  box-shadow:var(--shadow2); margin-bottom:22px; align-items:start;
}
[data-theme="light"] .profile-hero { background:rgba(255,255,255,.70); border-color:rgba(79,70,229,.12); }
.avatar-wrap { position:relative; width:90px; height:90px; }
.avatar-img { width:90px; height:90px; border-radius:22px; object-fit:cover; border:2px solid rgba(255,255,255,.20); box-shadow:0 14px 36px rgba(0,0,0,.28); }
.avatar-dflt {
  width:90px; height:90px; border-radius:22px; display:grid; place-items:center;
  font-size:34px; font-weight:900; color:#fff;
  background: radial-gradient(120% 140% at 30% 25%, rgba(255,255,255,.20), transparent 55%),
              linear-gradient(135deg,rgba(34,211,238,.70),rgba(79,70,229,.70));
  border:2px solid rgba(255,255,255,.20); box-shadow:0 14px 36px rgba(0,0,0,.25);
}
.avatar-edit {
  position:absolute; bottom:-5px; right:-5px;
  width:26px; height:26px; border-radius:9px; display:grid; place-items:center;
  background:linear-gradient(135deg,rgba(79,70,229,.90),rgba(34,211,238,.50));
  border:2px solid rgba(255,255,255,.35); font-size:10px; cursor:pointer;
  box-shadow:0 6px 16px rgba(79,70,229,.35); transition:.18s;
}
.avatar-edit:hover { transform:scale(1.12); }

/* Hero info */
.hero-info { min-width:0; }
.hero-name { font-size:22px; font-weight:800; margin-bottom:10px; }

/* Identity block — name, email, student ID side by side cleanly */
.identity-row {
  display:flex; align-items:flex-start; gap:20px; margin-bottom:14px; flex-wrap:wrap;
}
.id-field { display:flex; flex-direction:column; gap:3px; }
.id-label { font-size:10.5px; font-weight:700; color:var(--muted2); text-transform:uppercase; letter-spacing:.4px; }
.id-val { font-size:13.5px; font-weight:600; color:var(--text); }
.id-divider { width:1px; background:var(--border); align-self:stretch; margin:4px 0; flex-shrink:0; }

/* Stats strip */
.hero-stats { display:flex; gap:0; border-radius:14px; border:1px solid var(--border2); background:rgba(255,255,255,.03); overflow:hidden; margin-bottom:12px; }
[data-theme="light"] .hero-stats { background:rgba(255,255,255,.50); border-color:rgba(79,70,229,.10); }
.hstat { flex:1; padding:12px 10px; text-align:center; border-right:1px solid var(--border2); }
.hstat:last-child { border-right:none; }
[data-theme="light"] .hstat { border-right-color:rgba(79,70,229,.09); }
.hstat-num { font-size:20px; font-weight:900; line-height:1; }
.hstat-lbl { font-size:11px; color:var(--muted); margin-top:3px; }

/* Tags row */
.hero-tags { display:flex; gap:7px; flex-wrap:wrap; }
.htag { font-size:11px; font-weight:700; padding:4px 10px; border-radius:999px; border:1px solid var(--border); background:rgba(255,255,255,.06); }
[data-theme="light"] .htag { background:rgba(79,70,229,.07); border-color:rgba(79,70,229,.16); color:#4f46e5; }

/* Photo actions */
.photo-form { display:none; }

/* Tabs */
.profile-tabs { display:flex; gap:4px; margin-bottom:16px; border-bottom:1px solid var(--border2); padding-bottom:2px; }
[data-theme="light"] .profile-tabs { border-bottom-color:rgba(79,70,229,.10); }
.ptab {
  display:inline-flex; align-items:center; gap:7px;
  padding:10px 16px; border-radius:12px 12px 0 0; font-size:13.5px; font-weight:700;
  cursor:pointer; border:1px solid transparent; border-bottom:none; color:var(--muted);
  transition:.18s; font-family:inherit; background:none;
}
.ptab:hover { color:var(--text); background:rgba(255,255,255,.04); }
.ptab.on { background:rgba(255,255,255,.06); border-color:var(--border); color:var(--text); }
[data-theme="light"] .ptab.on { background:rgba(255,255,255,.80); border-color:rgba(79,70,229,.14); color:#1a1f3c; }
.tab-pane { display:none; }
.tab-pane.on { display:block; }

/* Section card */
.section-card {
  background:rgba(255,255,255,.04); border:1px solid var(--border); border-radius:20px;
  padding:22px; margin-bottom:16px;
}
[data-theme="light"] .section-card { background:rgba(255,255,255,.70); border-color:rgba(79,70,229,.12); }
.section-card h3 { font-size:15px; font-weight:800; margin:0 0 18px; display:flex; align-items:center; gap:10px; }
.section-card h3 i { color:var(--info); }

/* Info grid */
.info-grid { display:grid; grid-template-columns:repeat(auto-fit,minmax(160px,1fr)); gap:12px; }
.info-item { padding:12px 14px; border-radius:14px; border:1px solid var(--border2); background:rgba(255,255,255,.03); }
[data-theme="light"] .info-item { background:rgba(255,255,255,.60); border-color:rgba(79,70,229,.09); }
.info-lbl { font-size:10.5px; font-weight:700; color:var(--muted); text-transform:uppercase; letter-spacing:.3px; margin-bottom:5px; }
.info-val { font-size:14px; font-weight:700; }

/* Form grid */
.form-grid { display:grid; grid-template-columns:1fr 1fr; gap:14px; }
.form-full { grid-column:1/-1; }
.form-group { display:flex; flex-direction:column; gap:5px; }
.form-group label { font-size:11.5px; font-weight:700; color:var(--muted); letter-spacing:.3px; text-transform:uppercase; }
.req { color:var(--danger); }
.form-group input, .form-group select, .form-group textarea {
  padding:11px 14px; border-radius:12px; border:1px solid rgba(255,255,255,.12);
  background:var(--field); color:var(--text); font-size:14px; outline:none;
  transition:.18s; font-family:inherit;
}
.form-group input:focus, .form-group select:focus, .form-group textarea:focus {
  border-color:rgba(79,70,229,.55); box-shadow:0 0 0 3px rgba(79,70,229,.14);
}
[data-theme="light"] .form-group input,
[data-theme="light"] .form-group select,
[data-theme="light"] .form-group textarea { background:rgba(255,255,255,.90); border-color:rgba(79,70,229,.22); color:#1a1f3c; }
[data-theme="light"] .form-group input:focus,
[data-theme="light"] .form-group select:focus,
[data-theme="light"] .form-group textarea:focus { box-shadow:0 0 0 3px rgba(79,70,229,.12); border-color:rgba(79,70,229,.55); }
.form-group textarea { resize:vertical; min-height:90px; line-height:1.6; }
.form-group select option { background:#0B1030; color:#EAF0FF; }
[data-theme="light"] .form-group select option { background:#fff; color:#1a1f3c; }
.form-actions { display:flex; gap:10px; margin-top:18px; flex-wrap:wrap; }

/* Divider */
.divider { height:1px; background:var(--border2); margin:18px 0; }
[data-theme="light"] .divider { background:rgba(79,70,229,.09); }

@media(max-width:600px) {
  .profile-hero { grid-template-columns:1fr; }
  .hero-stats { flex-wrap:wrap; }
  .form-grid { grid-template-columns:1fr; }
  .form-full { grid-column:1; }
  .identity-row { gap:12px; }
}
</style>
</head>
<body>
<?php nav_body(); ?>

            <header class="page-header">
                <div class="header-content">
                    <h1>My Profile</h1>
                    <p>Manage your personal information and account settings</p>
                </div>
                <div class="hdr-actions">
                    <button class="theme-btn" id="themeBtn">
                        <div class="tgl-track" id="themeTrack"><div class="tgl-thumb"></div></div>
                        <span id="themeLabel">Dark</span>
                    </button>
                    <a href="dashboard.php" class="btn"><i class="fas fa-arrow-left"></i> Dashboard</a>
                </div>
            </header>

            <?php if ($success): ?><div class="alert alert-success"><i class="fas fa-check-circle"></i><span><?php echo htmlspecialchars($success); ?></span></div><?php endif; ?>
            <?php if ($error): ?><div class="alert alert-error"><i class="fas fa-exclamation-circle"></i><span><?php echo htmlspecialchars($error); ?></span></div><?php endif; ?>

            <!-- Profile Hero -->
            <div class="profile-hero">
                <div class="avatar-wrap">
                    <?php if (!empty($student['profile_picture'])): ?>
                        <img src="../<?php echo htmlspecialchars($student['profile_picture']); ?>" class="avatar-img" alt="Profile">
                    <?php else: ?>
                        <div class="avatar-dflt"><?php echo strtoupper(substr($student['name'],0,1)); ?></div>
                    <?php endif; ?>
                    <div class="avatar-edit" onclick="document.getElementById('photoInput').click()" title="Change photo">
                        <i class="fas fa-camera"></i>
                    </div>
                </div>
                <div class="hero-info">
                    <div class="hero-name"><?php echo htmlspecialchars($student['name']); ?></div>

                    <!-- Clean identity row: email | student ID | department separated by dividers -->
                    <div class="identity-row">
                        <div class="id-field">
                            <span class="id-label">Email</span>
                            <span class="id-val"><?php echo htmlspecialchars($student['email']); ?></span>
                        </div>
                        <?php if (!empty($student['student_id'])): ?>
                        <div class="id-divider"></div>
                        <div class="id-field">
                            <span class="id-label">Student ID</span>
                            <span class="id-val"><?php echo htmlspecialchars($student['student_id']); ?></span>
                        </div>
                        <?php endif; ?>
                        <?php if (!empty($student['department'])): ?>
                        <div class="id-divider"></div>
                        <div class="id-field">
                            <span class="id-label">Department</span>
                            <span class="id-val"><?php echo htmlspecialchars($student['department']); ?></span>
                        </div>
                        <?php endif; ?>
                        <?php if (!empty($student['semester'])): ?>
                        <div class="id-divider"></div>
                        <div class="id-field">
                            <span class="id-label">Semester</span>
                            <span class="id-val"><?php echo $student['semester']; ?></span>
                        </div>
                        <?php endif; ?>
                    </div>

                    <!-- Stats strip -->
                    <div class="hero-stats">
                        <div class="hstat"><div class="hstat-num"><?php echo $student['total_goals']?:0; ?></div><div class="hstat-lbl">Goals</div></div>
                        <div class="hstat"><div class="hstat-num"><?php echo $student['completed_goals']?:0; ?></div><div class="hstat-lbl">Completed</div></div>
                        <div class="hstat"><div class="hstat-num"><?php echo $student['total_achievements']?:0; ?></div><div class="hstat-lbl">Achievements</div></div>
                        <div class="hstat"><div class="hstat-num"><?php echo $student['total_points']?:0; ?></div><div class="hstat-lbl">Points</div></div>
                        <div class="hstat"><div class="hstat-num"><?php echo $streak; ?></div><div class="hstat-lbl">Streak days</div></div>
                    </div>

                    <!-- Tags -->
                    <div class="hero-tags">
                        <span class="htag">STUDENT</span>
                        <span class="htag">Member since <?php echo date('M Y',strtotime($student['created_at'])); ?></span>
                        <?php if (!empty($student['last_login'])): ?><span class="htag">Last seen <?php echo date('M j',strtotime($student['last_login'])); ?></span><?php endif; ?>
                    </div>

                    <!-- Hidden photo form -->
                    <form method="POST" enctype="multipart/form-data" class="photo-form" id="photoForm">
                        <input type="hidden" name="upload_photo" value="1">
                        <input type="file" id="photoInput" name="profile_picture" accept="image/*" style="display:none" onchange="document.getElementById('photoForm').submit()">
                    </form>
                    <?php if (!empty($student['profile_picture'])): ?>
                    <form method="POST" style="margin-top:10px;">
                        <input type="hidden" name="delete_photo" value="1">
                        <button type="submit" class="btn btn-sm btn-danger" onclick="return confirm('Remove profile picture?')"><i class="fas fa-trash"></i> Remove Photo</button>
                    </form>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Tabs -->
            <div class="profile-tabs">
                <button class="ptab on" onclick="showTab('info')"><i class="fas fa-id-card"></i> Account Info</button>
                <button class="ptab" onclick="showTab('edit')"><i class="fas fa-pen"></i> Edit Profile</button>
                <button class="ptab" onclick="showTab('security')"><i class="fas fa-lock"></i> Security</button>
            </div>

            <!-- Tab: Account Info -->
            <div id="tab-info" class="tab-pane on">
                <div class="section-card">
                    <h3><i class="fas fa-circle-info"></i> Account Details</h3>
                    <div class="info-grid">
                        <div class="info-item"><div class="info-lbl">Full Name</div><div class="info-val"><?php echo htmlspecialchars($student['name']); ?></div></div>
                        <div class="info-item"><div class="info-lbl">Email</div><div class="info-val" style="font-size:13px;font-weight:600;"><?php echo htmlspecialchars($student['email']); ?></div></div>
                        <?php if (!empty($student['student_id'])): ?><div class="info-item"><div class="info-lbl">Student ID</div><div class="info-val"><?php echo htmlspecialchars($student['student_id']); ?></div></div><?php endif; ?>
                        <?php if (!empty($student['department'])): ?><div class="info-item"><div class="info-lbl">Department</div><div class="info-val"><?php echo htmlspecialchars($student['department']); ?></div></div><?php endif; ?>
                        <?php if (!empty($student['semester'])): ?><div class="info-item"><div class="info-lbl">Semester</div><div class="info-val">Semester <?php echo $student['semester']; ?></div></div><?php endif; ?>
                        <div class="info-item"><div class="info-lbl">Member Since</div><div class="info-val"><?php echo date('M d, Y',strtotime($student['created_at'])); ?></div></div>
                        <?php if (!empty($student['last_login'])): ?><div class="info-item"><div class="info-lbl">Last Login</div><div class="info-val" style="font-size:13px;"><?php echo date('M d, Y g:i A',strtotime($student['last_login'])); ?></div></div><?php endif; ?>
                    </div>
                    <?php if (!empty($student['bio'])): ?>
                    <div class="divider"></div>
                    <div style="font-size:11px;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:.3px;margin-bottom:8px;">About Me</div>
                    <div style="font-size:14px;line-height:1.65;"><?php echo nl2br(htmlspecialchars($student['bio'])); ?></div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Tab: Edit Profile -->
            <div id="tab-edit" class="tab-pane">
                <div class="section-card">
                    <h3><i class="fas fa-pen"></i> Edit Profile</h3>
                    <form method="POST">
                        <input type="hidden" name="update_profile" value="1">
                        <div class="form-grid">
                            <div class="form-group">
                                <label>Full Name <span class="req">*</span></label>
                                <input type="text" name="name" value="<?php echo htmlspecialchars($student['name']); ?>" required>
                            </div>
                            <div class="form-group">
                                <label>Email Address <span class="req">*</span></label>
                                <input type="email" name="email" value="<?php echo htmlspecialchars($student['email']); ?>" required>
                            </div>
                            <div class="form-group">
                                <label>Student ID</label>
                                <input type="text" name="student_id" value="<?php echo htmlspecialchars($student['student_id']??''); ?>">
                            </div>
                            <div class="form-group">
                                <label>Department</label>
                                <input type="text" name="department" value="<?php echo htmlspecialchars($student['department']??''); ?>">
                            </div>
                            <div class="form-group">
                                <label>Semester</label>
                                <select name="semester">
                                    <option value="">Select Semester</option>
                                    <?php for($i=1;$i<=8;$i++): ?><option value="<?php echo $i; ?>" <?php echo $student['semester']==$i?'selected':''; ?>>Semester <?php echo $i; ?></option><?php endfor; ?>
                                </select>
                            </div>
                            <div class="form-group form-full">
                                <label>Bio / About Me</label>
                                <textarea name="bio" placeholder="Tell us a little about yourself..."><?php echo htmlspecialchars($student['bio']??''); ?></textarea>
                            </div>
                        </div>
                        <div class="form-actions">
                            <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Save Changes</button>
                            <button type="button" class="btn" onclick="showTab('info')"><i class="fas fa-times"></i> Cancel</button>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Tab: Security -->
            <div id="tab-security" class="tab-pane">
                <div class="section-card">
                    <h3><i class="fas fa-lock"></i> Change Password</h3>
                    <form method="POST">
                        <input type="hidden" name="change_password" value="1">
                        <div class="form-grid">
                            <div class="form-group form-full">
                                <label>Current Password <span class="req">*</span></label>
                                <input type="password" name="current_password" required autocomplete="current-password">
                            </div>
                            <div class="form-group">
                                <label>New Password <span class="req">*</span></label>
                                <input type="password" name="new_password" required autocomplete="new-password" minlength="6">
                            </div>
                            <div class="form-group">
                                <label>Confirm New Password <span class="req">*</span></label>
                                <input type="password" name="confirm_password" required autocomplete="new-password">
                            </div>
                        </div>
                        <div class="form-actions">
                            <button type="submit" class="btn btn-primary"><i class="fas fa-lock"></i> Change Password</button>
                        </div>
                    </form>
                </div>
                <div class="section-card">
                    <h3><i class="fas fa-shield-halved"></i> Account Status</h3>
                    <div class="info-grid">
                        <div class="info-item"><div class="info-lbl">Role</div><div class="info-val">Student</div></div>
                        <div class="info-item"><div class="info-lbl">Status</div><div class="info-val" style="color:var(--success);"><i class="fas fa-circle" style="font-size:8px;"></i> Active</div></div>
                        <div class="info-item"><div class="info-lbl">Streak</div><div class="info-val"><?php echo $streak; ?> days</div></div>
                        <div class="info-item"><div class="info-lbl">Points</div><div class="info-val"><?php echo $student['total_points']?:0; ?></div></div>
                    </div>
                </div>
            </div>

        </main>
    </div>

<script>

        // Sidebar toggle
        document.addEventListener('click', function(e) {
            const sb = document.getElementById('sidebar');
            const tog = document.getElementById('sidebarToggle');
            if (window.innerWidth <= 860 && sb && sb.classList.contains('active')
                && !sb.contains(e.target) && !tog?.contains(e.target)) {
                sb.classList.remove('active');
            }
        });


        // Tabs
        function showTab(name) {
            document.querySelectorAll('.tab-pane').forEach(t=>t.classList.remove('on'));
            document.querySelectorAll('.ptab').forEach(t=>t.classList.remove('on'));
            document.getElementById('tab-'+name).classList.add('on');
            const map={info:0,edit:1,security:2};
            document.querySelectorAll('.ptab')[map[name]]?.classList.add('on');
        }
        <?php if ($error && isset($_POST['update_profile'])): ?>showTab('edit');<?php endif; ?>
        <?php if ($error && isset($_POST['change_password'])): ?>showTab('security');<?php endif; ?>

        setTimeout(()=>{document.querySelectorAll('.alert').forEach(a=>{a.style.transition='opacity .3s';a.style.opacity='0';setTimeout(()=>a.remove(),300);});},5000);
</script>
<?php nav_js(); ?>
</body>
</html>