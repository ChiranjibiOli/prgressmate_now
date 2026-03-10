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
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<link href="https://fonts.googleapis.com/css2?family=Sora:wght@400;500;600;700;800&family=DM+Sans:ital,wght@0,400;0,500;0,600;1,400&display=swap" rel="stylesheet">
<style>
:root{
  --bg0:#070A18;--bg1:#0B1030;
  --text:#EAF0FF;--muted:rgba(234,240,255,.65);--muted2:rgba(234,240,255,.45);
  --primary:#4F46E5;--success:#34D399;--warning:#FBBF24;--danger:#FB7185;--info:#22D3EE;
  --border:rgba(255,255,255,.10);--border2:rgba(255,255,255,.08);
  --shadow:0 18px 45px rgba(0,0,0,.35);--shadow2:0 10px 30px rgba(0,0,0,.22);
  --r12:12px;--r14:14px;--r16:16px;--r20:20px;--t:.18s ease;
  --input-bg:rgba(255,255,255,.05);--input-border:rgba(255,255,255,.12);--input-focus:rgba(79,70,229,.40);
  --card:rgba(255,255,255,.04);
}
[data-theme="light"]{
  --bg0:#f0f4ff;--bg1:#e8eeff;
  --text:#1a1f3c;--muted:rgba(26,31,60,.60);--muted2:rgba(26,31,60,.40);
  --border:rgba(0,0,0,.10);--border2:rgba(0,0,0,.07);
  --shadow:0 18px 45px rgba(79,70,229,.12);--shadow2:0 10px 30px rgba(79,70,229,.08);
  --input-bg:rgba(255,255,255,.80);--input-border:rgba(79,70,229,.22);--input-focus:rgba(79,70,229,.25);
  --card:rgba(255,255,255,.65);
}
*{margin:0;padding:0;box-sizing:border-box;}
html,body{height:100%;}
body{font-family:'DM Sans',system-ui,sans-serif;color:var(--text);background:radial-gradient(900px 520px at 18% 10%,rgba(79,70,229,.22),transparent 60%),radial-gradient(900px 520px at 88% 15%,rgba(34,211,238,.18),transparent 58%),radial-gradient(700px 500px at 60% 90%,rgba(96,165,250,.14),transparent 62%),linear-gradient(180deg,var(--bg0),var(--bg1));overflow-x:hidden;line-height:1.55;transition:background .3s,color .3s;}
a{color:inherit;text-decoration:none;}
button{font-family:inherit;cursor:pointer;border:none;background:none;}
input,select,textarea{font-family:inherit;font-size:inherit;outline:none;}

.mobile-toggle{position:fixed;top:16px;left:16px;z-index:2000;width:44px;height:44px;display:none;place-items:center;border-radius:14px;border:1px solid var(--border);background:rgba(10,14,35,.60);color:var(--text);box-shadow:var(--shadow2);backdrop-filter:blur(12px);}
.sidebar-overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,.45);backdrop-filter:blur(2px);opacity:0;pointer-events:none;transition:opacity .2s;z-index:1600;}
.sidebar-overlay.active{opacity:1;pointer-events:auto;}
.dashboard-wrapper{display:grid;grid-template-columns:280px 1fr;min-height:100vh;}

/* SIDEBAR */
.sidebar{position:sticky;top:0;height:100vh;overflow:hidden;display:flex;flex-direction:column;padding:18px 16px 16px;background:radial-gradient(700px 320px at 20% 0%,rgba(79,70,229,.18),transparent 60%),radial-gradient(520px 300px at 100% 20%,rgba(34,211,238,.14),transparent 60%),linear-gradient(180deg,rgba(10,14,35,.85),rgba(10,14,35,.62));border-right:1px solid rgba(255,255,255,.10);backdrop-filter:blur(16px);box-shadow:0 10px 50px rgba(0,0,0,.25);position:relative;}
[data-theme="light"] .sidebar{background:radial-gradient(700px 320px at 20% 0%,rgba(79,70,229,.10),transparent 60%),rgba(240,244,255,.92);border-right:1px solid rgba(79,70,229,.15);}
.sidebar::before{content:"";position:absolute;inset:-2px;background:linear-gradient(120deg,rgba(79,70,229,.20),rgba(34,211,238,.14),rgba(96,165,250,.10));opacity:.22;filter:blur(26px);pointer-events:none;z-index:0;}
.sidebar-header,.user-profile,.nav-menu,.sidebar-quick-stats,.sidebar-footer{position:relative;z-index:2;}
.sidebar-header{display:flex;align-items:center;justify-content:space-between;padding:10px 10px 12px;}
.logo{display:flex;align-items:center;gap:10px;font-weight:900;font-size:18px;font-family:'Sora',sans-serif;}
.logo i{width:34px;height:34px;display:grid;place-items:center;border-radius:12px;background:radial-gradient(120% 140% at 30% 25%,rgba(255,255,255,.18),transparent 55%),linear-gradient(135deg,rgba(79,70,229,.70),rgba(34,211,238,.35));border:1px solid rgba(255,255,255,.18);box-shadow:0 14px 30px rgba(79,70,229,.18);}
.sidebar-close{display:none;width:40px;height:40px;border-radius:14px;border:1px solid rgba(255,255,255,.14);background:rgba(255,255,255,.06);color:var(--text);}
.user-profile{display:flex;gap:12px;padding:12px;border-radius:var(--r16);border:1px solid var(--border2);background:radial-gradient(140% 180% at 10% 0%,rgba(255,255,255,.10),transparent 60%),linear-gradient(180deg,rgba(255,255,255,.05),rgba(255,255,255,.03));box-shadow:0 12px 26px rgba(0,0,0,.18);}
.profile-pic{width:52px;height:52px;border-radius:16px;object-fit:cover;border:1px solid rgba(255,255,255,.16);}
.profile-pic.default{display:grid;place-items:center;font-weight:950;font-size:18px;color:var(--text);background:radial-gradient(120% 140% at 30% 25%,rgba(255,255,255,.18),transparent 55%),linear-gradient(135deg,rgba(34,211,238,.55),rgba(79,70,229,.55));}
.user-info h4{font-size:15px;font-weight:900;font-family:'Sora',sans-serif;margin:2px 0;}
.user-info p{font-size:12px;color:var(--muted);overflow:hidden;text-overflow:ellipsis;white-space:nowrap;max-width:170px;}
.user-tag{font-size:10px;background:rgba(224,231,255,.92);color:rgba(79,70,229,.98);padding:2px 8px;border-radius:12px;font-weight:700;}
.nav-menu{flex:1 1 auto;overflow-y:auto;padding:12px 4px 8px;margin-top:8px;display:flex;flex-direction:column;gap:4px;scrollbar-width:thin;scrollbar-color:rgba(255,255,255,.12) transparent;}
.nav-link{display:flex;align-items:center;gap:12px;padding:11px 12px;border-radius:14px;color:rgba(234,240,255,.88);border:1px solid transparent;transition:all var(--t);font-size:14px;}
.nav-link i{width:32px;height:32px;display:grid;place-items:center;border-radius:10px;background:rgba(255,255,255,.05);border:1px solid rgba(255,255,255,.10);font-size:13px;}
.nav-link:hover{background:rgba(255,255,255,.06);border-color:rgba(255,255,255,.12);transform:translateX(2px);}
.nav-link.active{background:radial-gradient(120% 160% at 10% 20%,rgba(255,255,255,.14),transparent 55%),linear-gradient(135deg,rgba(79,70,229,.55),rgba(34,211,238,.20));border-color:rgba(255,255,255,.18);box-shadow:0 10px 30px rgba(79,70,229,.18);}
.badge{margin-left:auto;font-size:11px;font-weight:900;padding:3px 8px;border-radius:999px;color:var(--text);background:radial-gradient(120% 180% at 20% 20%,rgba(255,255,255,.20),transparent 55%),linear-gradient(135deg,rgba(251,113,133,.70),rgba(79,70,229,.35));border:1px solid rgba(255,255,255,.18);}
.sidebar-quick-stats{margin-top:10px;padding:10px;border-radius:var(--r16);border:1px solid rgba(255,255,255,.10);background:rgba(255,255,255,.03);}
.sidebar-stat{display:flex;gap:12px;align-items:center;padding:9px 10px;border-radius:14px;}
.sidebar-stat:hover{background:rgba(255,255,255,.04);}
.sidebar-stat-icon{width:36px;height:36px;border-radius:12px;display:grid;place-items:center;border:1px solid rgba(255,255,255,.12);background:radial-gradient(120% 180% at 20% 10%,rgba(255,255,255,.18),transparent 55%),linear-gradient(135deg,rgba(34,211,238,.35),rgba(79,70,229,.35));font-size:13px;}
.sidebar-stat-label{font-size:11px;color:var(--muted);}
.sidebar-stat-number{font-size:16px;font-weight:950;font-family:'Sora',sans-serif;}
.sidebar-footer{margin-top:12px;}
.logout-btn{display:flex;align-items:center;justify-content:center;gap:10px;padding:12px;border-radius:14px;border:1px solid rgba(255,255,255,.14);background:radial-gradient(140% 180% at 20% 0%,rgba(255,255,255,.10),transparent 60%),linear-gradient(135deg,rgba(251,113,133,.16),rgba(255,255,255,.03));transition:all var(--t);font-size:14px;}
.logout-btn:hover{transform:translateY(-1px);background:rgba(251,113,133,.12);}

/* MAIN */
.main-content{padding:24px 28px 40px;}
.btn{display:inline-flex;align-items:center;justify-content:center;gap:9px;padding:10px 16px;border-radius:14px;border:1px solid rgba(255,255,255,.14);background:rgba(255,255,255,.04);color:rgba(234,240,255,.92);font-weight:700;font-size:13px;transition:all var(--t);cursor:pointer;}
.btn:hover{transform:translateY(-1px);background:rgba(255,255,255,.07);border-color:rgba(255,255,255,.18);box-shadow:0 10px 28px rgba(0,0,0,.22);}
.btn-primary{background:radial-gradient(120% 160% at 10% 20%,rgba(255,255,255,.14),transparent 55%),linear-gradient(135deg,rgba(79,70,229,.85),rgba(34,211,238,.25));border-color:rgba(255,255,255,.18);box-shadow:0 10px 30px rgba(79,70,229,.22);}
.btn-sm{padding:7px 12px;font-size:12px;border-radius:11px;}
.btn-danger{background:rgba(251,113,133,.14);border-color:rgba(251,113,133,.30);color:var(--danger);}

/* PAGE HEADER */
.page-header{display:flex;align-items:center;justify-content:space-between;gap:14px;padding:18px 20px;border-radius:var(--r20);border:1px solid var(--border);background:radial-gradient(120% 220% at 15% 10%,rgba(255,255,255,.10),transparent 55%),linear-gradient(180deg,rgba(255,255,255,.05),rgba(255,255,255,.03));box-shadow:var(--shadow2);margin-bottom:22px;flex-wrap:wrap;}
.page-header h1{font-size:22px;font-weight:800;font-family:'Sora',sans-serif;margin:0 0 4px;}
.page-header p{margin:0;color:var(--muted);font-size:13px;}

/* THEME TOGGLE */
.theme-toggle{display:flex;align-items:center;gap:8px;padding:8px 14px;border-radius:999px;border:1px solid var(--border);background:rgba(255,255,255,.06);cursor:pointer;font-size:13px;font-weight:700;transition:all var(--t);}
.theme-toggle:hover{background:rgba(255,255,255,.10);border-color:rgba(255,255,255,.18);}
.toggle-track{width:40px;height:22px;border-radius:999px;border:1px solid rgba(255,255,255,.20);background:rgba(255,255,255,.10);position:relative;transition:background .2s;}
.toggle-track.on{background:linear-gradient(135deg,rgba(79,70,229,.70),rgba(34,211,238,.40));}
.toggle-thumb{position:absolute;top:2px;left:2px;width:16px;height:16px;border-radius:50%;background:#fff;transition:transform .2s;box-shadow:0 2px 6px rgba(0,0,0,.25);}
.toggle-track.on .toggle-thumb{transform:translateX(18px);}

/* ALERT */
.alert{display:flex;align-items:center;gap:12px;padding:14px 16px;border-radius:var(--r16);border:1px solid var(--border);background:rgba(255,255,255,.04);margin-bottom:16px;font-size:14px;}
.alert i{width:34px;height:34px;display:grid;place-items:center;border-radius:12px;background:rgba(255,255,255,.06);border:1px solid rgba(255,255,255,.12);flex-shrink:0;}
.alert-success{border-color:rgba(52,211,153,.25);}.alert-success i{color:var(--success);}
.alert-error{border-color:rgba(251,113,133,.25);}.alert-error i{color:var(--danger);}

/* PROFILE HERO */
.profile-hero{
  display:grid;grid-template-columns:auto 1fr;gap:24px;
  padding:24px;border-radius:var(--r20);border:1px solid var(--border);
  background:radial-gradient(120% 220% at 5% 5%,rgba(79,70,229,.18),transparent 55%),linear-gradient(180deg,rgba(255,255,255,.06),rgba(255,255,255,.03));
  box-shadow:var(--shadow2);margin-bottom:22px;align-items:center;flex-wrap:wrap;
}
.avatar-wrap{position:relative;width:96px;height:96px;}
.avatar-img{width:96px;height:96px;border-radius:24px;object-fit:cover;border:2px solid rgba(255,255,255,.20);box-shadow:0 14px 36px rgba(0,0,0,.30);}
.avatar-default{width:96px;height:96px;border-radius:24px;display:grid;place-items:center;font-size:36px;font-weight:950;color:var(--text);background:radial-gradient(120% 140% at 30% 25%,rgba(255,255,255,.20),transparent 55%),linear-gradient(135deg,rgba(34,211,238,.60),rgba(79,70,229,.60));border:2px solid rgba(255,255,255,.20);box-shadow:0 14px 36px rgba(0,0,0,.25);font-family:'Sora',sans-serif;}
.avatar-edit{position:absolute;bottom:-6px;right:-6px;width:28px;height:28px;border-radius:10px;display:grid;place-items:center;background:linear-gradient(135deg,rgba(79,70,229,.85),rgba(34,211,238,.40));border:2px solid rgba(255,255,255,.30);font-size:11px;cursor:pointer;box-shadow:0 6px 16px rgba(79,70,229,.35);transition:all var(--t);}
.avatar-edit:hover{transform:scale(1.12);}
.profile-hero-info{}
.hero-name{font-size:22px;font-weight:900;font-family:'Sora',sans-serif;margin-bottom:4px;}
.hero-email{font-size:13px;color:var(--muted);margin-bottom:12px;}
.hero-stats{display:flex;gap:18px;flex-wrap:wrap;}
.hero-stat{text-align:center;}
.hero-stat-num{font-size:20px;font-weight:900;font-family:'Sora',sans-serif;line-height:1;}
.hero-stat-label{font-size:11px;color:var(--muted);margin-top:2px;}
.hero-badges{display:flex;gap:8px;margin-top:10px;flex-wrap:wrap;}
.hero-badge{font-size:11px;font-weight:700;padding:4px 10px;border-radius:999px;border:1px solid rgba(255,255,255,.14);background:rgba(255,255,255,.06);}

/* PHOTO UPLOAD FORM */
.photo-form{display:none;}
.photo-actions{display:flex;gap:8px;flex-wrap:wrap;margin-top:8px;}

/* TABS */
.profile-tabs{display:flex;gap:6px;margin-bottom:18px;border-bottom:1px solid var(--border2);padding-bottom:2px;}
.profile-tab{padding:10px 18px;border-radius:12px 12px 0 0;font-size:13.5px;font-weight:700;cursor:pointer;border:1px solid transparent;border-bottom:none;color:var(--muted);transition:all var(--t);}
.profile-tab:hover{color:var(--text);background:rgba(255,255,255,.04);}
.profile-tab.active{background:radial-gradient(120% 160% at 10% 20%,rgba(255,255,255,.12),transparent 55%),rgba(79,70,229,.18);border-color:rgba(255,255,255,.12);color:var(--text);}
.tab-content{display:none;}
.tab-content.active{display:block;}

/* SECTION CARD */
.section-card{background:var(--card);border:1px solid var(--border);border-radius:var(--r20);padding:22px;margin-bottom:18px;backdrop-filter:blur(12px);}
.section-card h3{font-size:16px;font-weight:800;font-family:'Sora',sans-serif;margin-bottom:18px;display:flex;align-items:center;gap:10px;}
.section-card h3 i{color:var(--info);}

/* FORM GRID */
.form-grid{display:grid;grid-template-columns:1fr 1fr;gap:16px;}
.form-full{grid-column:1/-1;}
.form-group{display:flex;flex-direction:column;gap:6px;}
.form-group label{font-size:12px;font-weight:700;color:var(--muted);letter-spacing:.3px;text-transform:uppercase;}
.form-group label .req{color:var(--danger);}
.form-group input,.form-group select,.form-group textarea{padding:11px 14px;border-radius:12px;border:1px solid var(--input-border);background:var(--input-bg);color:var(--text);font-size:14px;transition:border-color var(--t),box-shadow var(--t),background var(--t);}
.form-group input:focus,.form-group select:focus,.form-group textarea:focus{border-color:rgba(79,70,229,.60);box-shadow:0 0 0 3px var(--input-focus);background:rgba(255,255,255,.08);}
.form-group select option{background:#0B1030;color:#EAF0FF;}
.form-group textarea{resize:vertical;min-height:100px;line-height:1.6;}
.form-actions{display:flex;gap:10px;margin-top:20px;flex-wrap:wrap;}

/* INFO GRID */
.info-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:14px;}
.info-item{padding:20px 16px;border-radius:var(--r14);border:1px solid var(--border2);background:rgba(255,255,255,.03);}
.info-label{font-size:11px;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:.3px;margin-bottom:5px;}
.info-value{font-size:14px;font-weight:700;font-family:'Sora',sans-serif;}

/* RESPONSIVE */
@media(max-width:860px){
  .dashboard-wrapper{grid-template-columns:1fr;}
  .mobile-toggle{display:grid;}
  .sidebar{position:fixed;left:0;top:0;width:300px;height:100vh;transform:translateX(-105%);transition:transform .25s;z-index:1601;}
  .sidebar.active{transform:translateX(0);}
  .sidebar-overlay{display:block;}
  .sidebar-close{display:grid;}
  .main-content{padding:80px 16px 32px;}
  .profile-hero{grid-template-columns:1fr;text-align:center;}
  .hero-stats{justify-content:center;}
  .hero-badges{justify-content:center;}
}
@media(max-width:520px){
  .form-grid{grid-template-columns:1fr;}
  .form-full{grid-column:1;}
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
      <?php if (!empty($student['profile_picture'])): ?>
        <img src="../<?php echo htmlspecialchars($student['profile_picture']); ?>" class="profile-pic" alt="">
      <?php else: ?>
        <div class="profile-pic default"><?php echo strtoupper(substr($student['name'],0,1)); ?></div>
      <?php endif; ?>
      <div class="user-info">
        <h4><?php echo htmlspecialchars($student['name']); ?></h4>
        <p><?php echo htmlspecialchars($student['email']); ?></p>
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
      <div class="sidebar-stat"><div class="sidebar-stat-icon"><i class="fas fa-bullseye"></i></div><div><div class="sidebar-stat-label">Goals</div><div class="sidebar-stat-number"><?php echo $student['completed_goals']?:0; ?>/<?php echo $student['total_goals']?:0; ?></div></div></div>
      <div class="sidebar-stat"><div class="sidebar-stat-icon"><i class="fas fa-star"></i></div><div><div class="sidebar-stat-label">Points</div><div class="sidebar-stat-number"><?php echo $student['total_points']?:0; ?></div></div></div>
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
        <h1><i class="fas fa-user" style="color:var(--info);margin-right:8px;"></i>My Profile</h1>
        <p>Manage your personal information and account settings</p>
      </div>
      <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;">
        <!-- THEME TOGGLE -->
        <button class="theme-toggle" id="themeToggle" title="Toggle dark/light mode">
          <div class="toggle-track" id="toggleTrack">
            <div class="toggle-thumb"></div>
          </div>
          <span id="themeLabel">Dark</span>
        </button>
        <a href="dashboard.php" class="btn"><i class="fas fa-arrow-left"></i> Dashboard</a>
      </div>
    </header>

    <?php if ($success): ?><div class="alert alert-success"><i class="fas fa-check-circle"></i><span><?php echo htmlspecialchars($success); ?></span></div><?php endif; ?>
    <?php if ($error): ?><div class="alert alert-error"><i class="fas fa-exclamation-circle"></i><span><?php echo htmlspecialchars($error); ?></span></div><?php endif; ?>

    <!-- PROFILE HERO -->
    <div class="profile-hero">
      <div class="avatar-wrap">
        <?php if (!empty($student['profile_picture'])): ?>
          <img src="../<?php echo htmlspecialchars($student['profile_picture']); ?>" class="avatar-img" alt="Profile">
        <?php else: ?>
          <div class="avatar-default"><?php echo strtoupper(substr($student['name'],0,1)); ?></div>
        <?php endif; ?>
        <div class="avatar-edit" onclick="document.getElementById('photoInput').click()" title="Change photo"><i class="fas fa-camera"></i></div>
      </div>
      <div class="profile-hero-info">
        <div class="hero-name"><?php echo htmlspecialchars($student['name']); ?></div>
        <div class="hero-email"><?php echo htmlspecialchars($student['email']); ?>
          <?php if (!empty($student['student_id'])): ?> &nbsp;·&nbsp; ID: <?php echo htmlspecialchars($student['student_id']); ?><?php endif; ?>
        </div>
        <div class="hero-stats">
          <div class="hero-stat"><div class="hero-stat-num"><?php echo $student['total_goals']?:0; ?></div><div class="hero-stat-label">Goals</div></div>
          <div class="hero-stat"><div class="hero-stat-num"><?php echo $student['completed_goals']?:0; ?></div><div class="hero-stat-label">Completed</div></div>
          <div class="hero-stat"><div class="hero-stat-num"><?php echo $student['total_achievements']?:0; ?></div><div class="hero-stat-label">Achievements</div></div>
          <div class="hero-stat"><div class="hero-stat-num"><?php echo $student['total_points']?:0; ?></div><div class="hero-stat-label">Points</div></div>
          <div class="hero-stat"><div class="hero-stat-num"><?php echo $streak; ?></div><div class="hero-stat-label">Streak days</div></div>
        </div>
        <div class="hero-badges">
          <span class="hero-badge">STUDENT</span>
          <?php if (!empty($student['department'])): ?><span class="hero-badge"><?php echo htmlspecialchars($student['department']); ?></span><?php endif; ?>
          <?php if (!empty($student['semester'])): ?><span class="hero-badge">Semester <?php echo $student['semester']; ?></span><?php endif; ?>
          <span class="hero-badge">Member since <?php echo date('M Y', strtotime($student['created_at'])); ?></span>
        </div>
        <!-- Hidden photo upload form -->
        <form method="POST" enctype="multipart/form-data" class="photo-form" id="photoForm">
          <input type="hidden" name="upload_photo" value="1">
          <input type="file" id="photoInput" name="profile_picture" accept="image/*" style="display:none" onchange="document.getElementById('photoForm').submit();">
        </form>
        <?php if (!empty($student['profile_picture'])): ?>
          <form method="POST" style="display:inline;margin-top:8px;">
            <input type="hidden" name="delete_photo" value="1">
            <button type="submit" class="btn btn-sm btn-danger" onclick="return confirm('Remove profile picture?')" style="margin-top:8px;"><i class="fas fa-trash"></i> Remove Photo</button>
          </form>
        <?php endif; ?>
      </div>
    </div>

    <!-- TABS -->
    <div class="profile-tabs">
      <button class="profile-tab active" onclick="showTab('info')"><i class="fas fa-user"></i> Account Info</button>
      <button class="profile-tab" onclick="showTab('edit')"><i class="fas fa-edit"></i> Edit Profile</button>
      <button class="profile-tab" onclick="showTab('security')"><i class="fas fa-lock"></i> Security</button>
    </div>

    <!-- TAB: Account Info -->
    <div id="tab-info" class="tab-content active">
      <div class="section-card">
        <h3><i class="fas fa-id-card"></i> Account Details</h3>
        <div class="info-grid">
          <div class="info-item"><div class="info-label">Full Name</div><div class="info-value"><?php echo htmlspecialchars($student['name']); ?></div></div>
          <div class="info-item"><div class="info-label">Email</div><div class="info-value" style="font-size:13px;"><?php echo htmlspecialchars($student['email']); ?></div></div>
          <?php if (!empty($student['student_id'])): ?>
          <div class="info-item"><div class="info-label">Student ID</div><div class="info-value"><?php echo htmlspecialchars($student['student_id']); ?></div></div>
          <?php endif; ?>
          <?php if (!empty($student['department'])): ?>
          <div class="info-item"><div class="info-label">Department</div><div class="info-value"><?php echo htmlspecialchars($student['department']); ?></div></div>
          <?php endif; ?>
          <?php if (!empty($student['semester'])): ?>
          <div class="info-item"><div class="info-label">Semester</div><div class="info-value">Semester <?php echo $student['semester']; ?></div></div>
          <?php endif; ?>
          <div class="info-item"><div class="info-label">Member Since</div><div class="info-value"><?php echo date('M d, Y', strtotime($student['created_at'])); ?></div></div>
          <?php if (!empty($student['last_login'])): ?>
          <div class="info-item"><div class="info-label">Last Login</div><div class="info-value" style="font-size:13px;"><?php echo date('M d, Y g:i A', strtotime($student['last_login'])); ?></div></div>
          <?php endif; ?>
        </div>
        <?php if (!empty($student['bio'])): ?>
          <div style="margin-top:18px;padding-top:18px;border-top:1px solid var(--border2);">
            <div style="font-size:12px;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:.3px;margin-bottom:8px;">About Me</div>
            <div style="font-size:14px;line-height:1.65;color:var(--text);"><?php echo nl2br(htmlspecialchars($student['bio'])); ?></div>
          </div>
        <?php endif; ?>
      </div>
    </div>

    <!-- TAB: Edit Profile -->
    <div id="tab-edit" class="tab-content">
      <div class="section-card">
        <h3><i class="fas fa-user-edit"></i> Edit Profile</h3>
        <form method="POST" id="editForm">
          <input type="hidden" name="update_profile" value="1">
          <div class="form-grid">
            <div class="form-group">
              <label for="name">Full Name <span class="req">*</span></label>
              <input type="text" id="name" name="name" value="<?php echo htmlspecialchars($student['name']); ?>" required>
            </div>
            <div class="form-group">
              <label for="email">Email Address <span class="req">*</span></label>
              <input type="email" id="email" name="email" value="<?php echo htmlspecialchars($student['email']); ?>" required>
            </div>
            <div class="form-group">
              <label for="student_id">Student ID</label>
              <input type="text" id="student_id" name="student_id" value="<?php echo htmlspecialchars($student['student_id']??''); ?>">
            </div>
            <div class="form-group">
              <label for="department">Department</label>
              <input type="text" id="department" name="department" value="<?php echo htmlspecialchars($student['department']??''); ?>">
            </div>
            <div class="form-group">
              <label for="semester">Semester</label>
              <select id="semester" name="semester">
                <option value="">Select Semester</option>
                <?php for($i=1;$i<=8;$i++): ?><option value="<?php echo $i; ?>" <?php echo ($student['semester']==$i)?'selected':''; ?>>Semester <?php echo $i; ?></option><?php endfor; ?>
              </select>
            </div>
            <div class="form-group form-full">
              <label for="bio">Bio / About Me</label>
              <textarea id="bio" name="bio" placeholder="Tell us a little about yourself..."><?php echo htmlspecialchars($student['bio']??''); ?></textarea>
            </div>
          </div>
          <div class="form-actions">
            <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Save Changes</button>
            <button type="button" class="btn" onclick="showTab('info')"><i class="fas fa-times"></i> Cancel</button>
          </div>
        </form>
      </div>
    </div>

    <!-- TAB: Security -->
    <div id="tab-security" class="tab-content">
      <div class="section-card">
        <h3><i class="fas fa-lock"></i> Change Password</h3>
        <form method="POST" id="pwForm">
          <input type="hidden" name="change_password" value="1">
          <div class="form-grid">
            <div class="form-group form-full">
              <label for="current_password">Current Password <span class="req">*</span></label>
              <input type="password" id="current_password" name="current_password" required autocomplete="current-password">
            </div>
            <div class="form-group">
              <label for="new_password">New Password <span class="req">*</span></label>
              <input type="password" id="new_password" name="new_password" required autocomplete="new-password" minlength="6">
            </div>
            <div class="form-group">
              <label for="confirm_password">Confirm New Password <span class="req">*</span></label>
              <input type="password" id="confirm_password" name="confirm_password" required autocomplete="new-password">
            </div>
          </div>
          <div class="form-actions">
            <button type="submit" class="btn btn-primary"><i class="fas fa-lock"></i> Change Password</button>
          </div>
        </form>
      </div>
      <div class="section-card">
        <h3><i class="fas fa-shield-halved"></i> Account Security</h3>
        <div class="info-grid">
          <div class="info-item"><div class="info-label">Role</div><div class="info-value">Student</div></div>
          <div class="info-item"><div class="info-label">Status</div><div class="info-value" style="color:var(--success);"><i class="fas fa-circle" style="font-size:9px;"></i> Active</div></div>
          <div class="info-item"><div class="info-label">Login Streak</div><div class="info-value"><?php echo $streak; ?> days</div></div>
          <div class="info-item"><div class="info-label">Total Points</div><div class="info-value"><?php echo $student['total_points']?:0; ?></div></div>
        </div>
      </div>
    </div>

  </main>
</div>

<script>
// Sidebar
const sidebar=document.getElementById('sidebar');
const overlay=document.getElementById('sidebarOverlay');
document.getElementById('sidebarToggle')?.addEventListener('click',()=>{sidebar.classList.add('active');overlay.classList.add('active');});
document.getElementById('sidebarClose')?.addEventListener('click',()=>{sidebar.classList.remove('active');overlay.classList.remove('active');});
overlay?.addEventListener('click',()=>{sidebar.classList.remove('active');overlay.classList.remove('active');});

// Tabs
function showTab(name){
  document.querySelectorAll('.tab-content').forEach(t=>t.classList.remove('active'));
  document.querySelectorAll('.profile-tab').forEach(t=>t.classList.remove('active'));
  document.getElementById('tab-'+name).classList.add('active');
  // match button
  const btns=document.querySelectorAll('.profile-tab');
  const names=['info','edit','security'];
  btns[names.indexOf(name)]?.classList.add('active');
}

// Open edit tab if error and form was for profile
<?php if ($error && isset($_POST['update_profile'])): ?>showTab('edit');<?php endif; ?>
<?php if ($error && isset($_POST['change_password'])): ?>showTab('security');<?php endif; ?>

// Theme toggle
const themeToggle=document.getElementById('themeToggle');
const toggleTrack=document.getElementById('toggleTrack');
const themeLabel=document.getElementById('themeLabel');
const saved=localStorage.getItem('pm_theme')||'dark';
function applyTheme(t){
  document.documentElement.setAttribute('data-theme', t==='light'?'light':'');
  toggleTrack.classList.toggle('on', t==='light');
  themeLabel.textContent=t==='light'?'Light':'Dark';
}
applyTheme(saved);
themeToggle.addEventListener('click',()=>{
  const cur=document.documentElement.getAttribute('data-theme')==='light'?'dark':'light';
  localStorage.setItem('pm_theme',cur);
  applyTheme(cur);
});

// Auto dismiss alerts
setTimeout(()=>{document.querySelectorAll('.alert').forEach(a=>{a.style.transition='opacity .3s';a.style.opacity='0';setTimeout(()=>a.remove(),300);});},5000);
</script>
</body>
</html>