<?php
// students/profile.php - Student Profile Management
session_start();
require_once '../includes/db_connection.php';
require_once '../includes/functions.php';
checkAuth('student');

$student_id = $_SESSION['user_id'] ?? 0;
$error = '';
$success = '';

// Initialize student array
$student = [
    'name' => '',
    'email' => '',
    'profile_picture' => null,
    'student_id' => null,
    'department' => null,
    'semester' => null,
    'bio' => null,
    'created_at' => date('Y-m-d H:i:s'),
    'last_login' => null,
    'total_goals' => 0,
    'completed_goals' => 0,
    'total_achievements' => 0,
    'total_points' => 0
];

// Get student details with stats
try {
    $stmt = $pdo->prepare("
        SELECT u.*, 
               COUNT(sg.id) as total_goals,
               SUM(CASE WHEN sg.status = 'completed' THEN 1 ELSE 0 END) as completed_goals,
               SUM(CASE WHEN sg.status = 'in_progress' THEN 1 ELSE 0 END) as in_progress_goals,
               SUM(CASE WHEN sg.status = 'overdue' THEN 1 ELSE 0 END) as overdue_goals,
               COALESCE(SUM(a.points), 0) as total_points,
               COUNT(DISTINCT ua.achievement_id) as total_achievements
        FROM users u
      LEFT JOIN student_goals sg 
ON u.id = sg.student_id AND sg.deleted_at IS NULL

        LEFT JOIN user_achievements ua ON u.id = ua.user_id
        LEFT JOIN achievements a ON ua.achievement_id = a.id
        WHERE u.id = ?
        GROUP BY u.id
    ");
    $stmt->execute([$student_id]);
    $result = $stmt->fetch();

    if ($result) {
        $student = array_merge($student, $result);
    } else {
        header("Location: ../login.php");
        exit;
    }
} catch (Exception $e) {
    $error = "Error loading profile: " . $e->getMessage();
}

// Handle profile update
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (isset($_POST['update_profile'])) {
        $name = trim($_POST['name'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $student_id_input = trim($_POST['student_id'] ?? '');
        $department = trim($_POST['department'] ?? '');
        $semester = trim($_POST['semester'] ?? '');
        $bio = trim($_POST['bio'] ?? '');

        // Validation
        if (empty($name) || empty($email)) {
            $error = "Name and email are required.";
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = "Please enter a valid email address.";
        } else {
            try {
                // Check if email is already taken by another user
                $check_email_stmt = $pdo->prepare("SELECT id FROM users WHERE email = ? AND id != ?");
                $check_email_stmt->execute([$email, $student_id]);

                if ($check_email_stmt->fetch()) {
                    $error = "Email already in use by another account.";
                } else {
                    // Check if student ID is already taken
                    if (!empty($student_id_input)) {
                        $check_student_id_stmt = $pdo->prepare("SELECT id FROM users WHERE student_id = ? AND id != ?");
                        $check_student_id_stmt->execute([$student_id_input, $student_id]);

                        if ($check_student_id_stmt->fetch()) {
                            $error = "Student ID already in use by another account.";
                        }
                    }

                    if (empty($error)) {
                        $pdo->beginTransaction();

                        $update_stmt = $pdo->prepare("
                            UPDATE users 
                            SET name = ?, email = ?, student_id = ?, department = ?, semester = ?, bio = ?, updated_at = NOW()
                            WHERE id = ?
                        ");
                        $update_stmt->execute([$name, $email, $student_id_input, $department, $semester, $bio, $student_id]);

                        // Update session
                        $_SESSION['name'] = $name;
                        $_SESSION['email'] = $email;

                        $pdo->commit();

                        $success = "Profile updated successfully!";
                        // Refresh student data
                        $stmt->execute([$student_id]);
                        $result = $stmt->fetch();
                        if ($result) {
                            $student = array_merge($student, $result);
                        }
                    }
                }
            } catch (Exception $e) {
                $pdo->rollBack();
                $error = "Error updating profile: " . $e->getMessage();
            }
        }
    }

    // Handle password change
    elseif (isset($_POST['change_password'])) {
        $current_password = $_POST['current_password'] ?? '';
        $new_password = $_POST['new_password'] ?? '';
        $confirm_password = $_POST['confirm_password'] ?? '';

        // Validation
        if (empty($current_password) || empty($new_password) || empty($confirm_password)) {
            $error = "All password fields are required.";
        } elseif ($new_password !== $confirm_password) {
            $error = "New passwords do not match.";
        } elseif (strlen($new_password) < 6) {
            $error = "New password must be at least 6 characters.";
        } else {
            try {
                // Verify current password
                $check_password_stmt = $pdo->prepare("SELECT password FROM users WHERE id = ?");
                $check_password_stmt->execute([$student_id]);
                $user = $check_password_stmt->fetch();

                if ($user && password_verify($current_password, $user['password'])) {
                    // Hash new password
                    $hashed_password = password_hash($new_password, PASSWORD_BCRYPT);

                    $update_password_stmt = $pdo->prepare("UPDATE users SET password = ?, updated_at = NOW() WHERE id = ?");
                    $update_password_stmt->execute([$hashed_password, $student_id]);

                    $success = "Password changed successfully!";
                } else {
                    $error = "Current password is incorrect.";
                }
            } catch (Exception $e) {
                $error = "Error changing password: " . $e->getMessage();
            }
        }
    }

    // Handle profile picture upload
    elseif (isset($_POST['upload_photo']) && isset($_FILES['profile_picture'])) {
        $file = $_FILES['profile_picture'];

     
        if ($file['error'] !== UPLOAD_ERR_OK) {
            $error = "File upload failed with error code: " . $file['error'];
        } else {
       
            $allowed_types = ['image/jpeg', 'image/jpg', 'image/png', 'image/gif'];
            $file_type = mime_content_type($file['tmp_name']);

            if (!in_array($file_type, $allowed_types)) {
                $error = "Only JPG, PNG, and GIF files are allowed.";
            } elseif ($file['size'] > 2 * 1024 * 1024) {
                $error = "File size must be less than 2MB.";
            } else {
                try {
                    $upload_dir = '../uploads/profiles/';
                    if (!file_exists($upload_dir)) {
                        mkdir($upload_dir, 0777, true);
                    }

                    $file_extension = pathinfo($file['name'], PATHINFO_EXTENSION);
                    $filename = 'profile_' . $student_id . '_' . time() . '.' . $file_extension;
                    $file_path = $upload_dir . $filename;

                    // Move uploaded file
                    if (move_uploaded_file($file['tmp_name'], $file_path)) {
     
                        if (!empty($student['profile_picture']) && file_exists('../' . $student['profile_picture'])) {
                            unlink('../' . $student['profile_picture']);
                        }
                        $relative_path = 'uploads/profiles/' . $filename;
                        $update_pic_stmt = $pdo->prepare("UPDATE users SET profile_picture = ?, updated_at = NOW() WHERE id = ?");
                        $update_pic_stmt->execute([$relative_path, $student_id]);

                        $_SESSION['profile_picture'] = $relative_path;
                        $student['profile_picture'] = $relative_path;

                        $success = "Profile picture updated successfully!";
                    } else {
                        $error = "Failed to save uploaded file.";
                    }
                } catch (Exception $e) {
                    $error = "Error uploading profile picture: " . $e->getMessage();
                }
            }
        }
    }

    elseif (isset($_POST['delete_photo'])) {
        try {
            if (!empty($student['profile_picture']) && file_exists('../' . $student['profile_picture'])) {
                unlink('../' . $student['profile_picture']);
            }

            $delete_pic_stmt = $pdo->prepare("UPDATE users SET profile_picture = NULL, updated_at = NOW() WHERE id = ?");
            $delete_pic_stmt->execute([$student_id]);

            $_SESSION['profile_picture'] = null;
            $student['profile_picture'] = null;

            $success = "Profile picture removed successfully!";
        } catch (Exception $e) {
            $error = "Error removing profile picture: " . $e->getMessage();
        }
    }
}


$recent_activities = [];
try {
    $activities_stmt = $pdo->prepare("
        (SELECT 
            'goal_created' as type,
            title as title,
            'Created goal: ' as action,
            created_at as timestamp
        FROM student_goals 
        WHERE student_id = ?
        ORDER BY created_at DESC
        LIMIT 5)
        
        UNION ALL
        
        (SELECT 
            'goal_completed' as type,
            title as title,
            'Completed goal: ' as action,
            completed_at as timestamp
        FROM student_goals 
        WHERE student_id = ? AND status = 'completed' AND completed_at IS NOT NULL
        ORDER BY completed_at DESC
        LIMIT 5)
        
        UNION ALL
        
        (SELECT 
            'achievement_earned' as type,
            a.title as title,
            'Earned achievement: ' as action,
            ua.earned_at as timestamp
        FROM user_achievements ua
        JOIN achievements a ON ua.achievement_id = a.id
        WHERE ua.user_id = ?
        ORDER BY ua.earned_at DESC
        LIMIT 5)
        
        ORDER BY timestamp DESC
        LIMIT 10
    ");
    $activities_stmt->execute([$student_id, $student_id, $student_id]);
    $recent_activities = $activities_stmt->fetchAll();
} catch (Exception $e) {
   
}


$sidebar_stats = [
    'total_goals' => 0,
    'completed_goals' => 0,
    'total_points' => 0
];

try {
    $stats_stmt = $pdo->prepare("
        SELECT 
            COUNT(*) as total_goals,
            SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed_goals
        FROM student_goals 
        WHERE student_id = ?
    ");
    $stats_stmt->execute([$student_id]);
    $goal_stats = $stats_stmt->fetch();

    if ($goal_stats) {
        $sidebar_stats['total_goals'] = $goal_stats['total_goals'] ?? 0;
        $sidebar_stats['completed_goals'] = $goal_stats['completed_goals'] ?? 0;
    }

    $points_stmt = $pdo->prepare("
        SELECT COALESCE(SUM(a.points), 0) as total_points
        FROM user_achievements ua
        JOIN achievements a ON ua.achievement_id = a.id
        WHERE ua.user_id = ?
    ");
    $points_stmt->execute([$student_id]);
    $points_result = $points_stmt->fetch();
    $sidebar_stats['total_points'] = $points_result['total_points'] ?? 0;
} catch (Exception $e) {

}


$unread_count = 0;
try {
    $notif_stmt = $pdo->prepare("SELECT COUNT(*) as count FROM notifications WHERE user_id = ? AND is_read = 0");
    $notif_stmt->execute([$student_id]);
    $notif_result = $notif_stmt->fetch();
    $unread_count = $notif_result['count'] ?? 0;
} catch (Exception $e) {
 
}

$current = basename($_SERVER['PHP_SELF']);
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width
    =device-width, initial-scale=1.0">
    <title>My Profile - ProgressMate</title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=Roboto:wght@400;500;700&display=swap" rel="stylesheet">

    <style>


:root{
  --bg0:#070A18;
  --bg1:#0B1030;

  --text:#EAF0FF;
  --muted: rgba(234,240,255,.65);
  --muted2: rgba(234,240,255,.50);

  --primary:#4F46E5;
  --cyan:#22D3EE;
  --pink:#FB7185;

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
    radial-gradient(900px 520px at 70% 95%, rgba(251,113,133,.10), transparent 62%),
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
  background: linear-gradient(120deg, rgba(79,70,229,.20), rgba(34,211,238,.14), rgba(251,113,133,.10));
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
/* your inline STUDENT badge */
.user-info span[style*="STUDENT"]{
  background: rgba(224,231,255,.92) !important;
  color: rgba(79,70,229,.98) !important;
  border: 1px solid rgba(255,255,255,.40);
}

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
    linear-gradient(135deg, rgba(251,113,133,.18), rgba(255,255,255,.03));
  box-shadow: 0 14px 26px rgba(0,0,0,.16);
}

/* ===== Main ===== */
.main-content{
  padding: 22px 22px 32px;
}
.profile-container{
  width: 100%;
  max-width: 1080px;
}

/* header inside profile container */
.page-header{
  width: 100%;
  padding: 16px 16px;
  border-radius: var(--r20);
  border: 1px solid var(--border);
  background:
    radial-gradient(120% 220% at 15% 10%, rgba(255,255,255,.10), transparent 55%),
    linear-gradient(180deg, rgba(255,255,255,.05), rgba(255,255,255,.03));
  box-shadow: var(--shadow2);
  margin-bottom: 14px;
}
.page-header h1{ margin:0 0 6px; font-size: 24px; font-weight: 950; }
.page-header p{ margin:0; color: var(--muted); font-size: 14px; }

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
.btn-danger{
  background:
    radial-gradient(120% 160% at 10% 20%, rgba(255,255,255,.12), transparent 55%),
    linear-gradient(135deg, rgba(251,113,133,.70), rgba(79,70,229,.18));
  border: 1px solid rgba(255,255,255,.16);
}

/* ===== Tabs ===== */
.profile-tabs{
  width: 100%;
  margin-top: 14px;
  display:flex;
  gap: 10px;
  padding: 10px;
  border-radius: var(--r20);
  border: 1px solid rgba(255,255,255,.10);
  background: rgba(255,255,255,.03);
  box-shadow: var(--shadow2);
}
.tab-btn{
  flex: 0 0 auto;
  display:inline-flex;
  align-items:center;
  gap: 10px;
  padding: 10px 14px;
  border-radius: 14px;
  border: 1px solid transparent;
  background: rgba(255,255,255,.04);
  color: rgba(234,240,255,.86);
  font-weight: 950;
  font-size: 13px;
  transition: transform .18s ease, background .18s ease, border-color .18s ease, box-shadow .18s ease;
}
.tab-btn:hover{
  transform: translateY(-1px);
  background: rgba(255,255,255,.06);
  border-color: rgba(255,255,255,.10);
}
.tab-btn.active{
  color: rgba(234,240,255,.98);
  background:
    radial-gradient(120% 160% at 10% 20%, rgba(255,255,255,.14), transparent 55%),
    linear-gradient(135deg, rgba(79,70,229,.80), rgba(34,211,238,.18));
  border-color: rgba(255,255,255,.18);
  box-shadow: 0 16px 40px rgba(79,70,229,.18);
}

/* ===== Profile Layout ===== */
.profile-layout{
  width: 100%;
  display:grid;
  grid-template-columns: 360px 1fr;
  gap: 14px;
  margin-top: 14px;
}

.profile-sidebar,
.profile-content{
  border-radius: var(--r20);
  border: 1px solid rgba(255,255,255,.10);
  background: rgba(255,255,255,.03);
  box-shadow: var(--shadow2);
}

/* ===== Profile Sidebar Card ===== */
.profile-sidebar{ padding: 14px; }
.profile-header{
  padding: 14px;
  border-radius: 18px;
  border: 1px solid rgba(255,255,255,.10);
  background:
    radial-gradient(120% 200% at 10% 0%, rgba(79,70,229,.18), transparent 60%),
    rgba(255,255,255,.03);
}

.profile-avatar{
  position: relative;
  width: 96px;
  height: 96px;
  margin: 0 auto 12px;
}
.profile-picture{
  width: 96px;
  height: 96px;
  border-radius: 26px;
  object-fit: cover;
  border: 1px solid rgba(255,255,255,.16);
  box-shadow: 0 18px 34px rgba(0,0,0,.22);
}
.profile-picture.default{
  display:grid;
  place-items:center;
  font-weight: 950;
  font-size: 30px;
  color: var(--text);
  background:
    radial-gradient(120% 140% at 30% 25%, rgba(255,255,255,.18), transparent 55%),
    linear-gradient(135deg, rgba(34,211,238,.55), rgba(79,70,229,.55));
}
.avatar-upload{
  position:absolute;
  right: -4px;
  bottom: -4px;
}
.avatar-upload-btn{
  width: 38px;
  height: 38px;
  border-radius: 14px;
  border: 1px solid rgba(255,255,255,.16);
  background:
    radial-gradient(120% 140% at 30% 25%, rgba(255,255,255,.16), transparent 55%),
    rgba(255,255,255,.05);
  color: rgba(234,240,255,.95);
  box-shadow: 0 16px 28px rgba(0,0,0,.22);
}
.avatar-upload-btn:hover{
  background:
    radial-gradient(120% 140% at 30% 25%, rgba(255,255,255,.16), transparent 55%),
    rgba(255,255,255,.07);
}

.profile-name{
  text-align:center;
  font-size: 18px;
  font-weight: 950;
  margin-top: 6px;
}
.profile-email{
  text-align:center;
  color: var(--muted);
  font-size: 13px;
  margin-top: 4px;
}
.profile-badge{
  display:inline-block;
  margin: 10px auto 0;
  padding: 6px 12px;
  border-radius: 999px;
  border: 1px solid rgba(255,255,255,.16);
  background: rgba(79,70,229,.18);
  color: rgba(234,240,255,.95);
  font-size: 12px;
  font-weight: 950;
}

/* stats mini grid */
.profile-stats{
  margin-top: 12px;
  display:grid;
  grid-template-columns: 1fr 1fr;
  gap: 10px;
}
.stat-item{
  padding: 12px;
  border-radius: 16px;
  border: 1px solid rgba(255,255,255,.10);
  background: rgba(255,255,255,.03);
}
.stat-number{
  font-size: 20px;
  font-weight: 950;
}
.stat-label{
  margin-top: 2px;
  font-size: 12px;
  color: var(--muted);
  font-weight: 800;
}

/* info list */
.profile-info{
  margin-top: 12px;
  display:flex;
  flex-direction: column;
  gap: 10px;
}
.info-item{
  display:flex;
  justify-content: space-between;
  gap: 10px;
  padding: 12px;
  border-radius: 16px;
  border: 1px solid rgba(255,255,255,.10);
  background: rgba(255,255,255,.03);
}
.info-label{
  font-size: 12px;
  color: var(--muted);
  font-weight: 900;
}
.info-value{
  font-size: 12.5px;
  color: rgba(234,240,255,.92);
  font-weight: 900;
  text-align: right;
}
.info-value.empty{ color: rgba(234,240,255,.45); }

/* ===== Profile Content ===== */
.profile-content{ padding: 14px; }

.tab-content{ display:none; }
.tab-content.active{ display:block; }

.profile-section{
  padding: 14px;
  border-radius: 18px;
  border: 1px solid rgba(255,255,255,.10);
  background: rgba(255,255,255,.03);
}
.profile-section + .profile-section{ margin-top: 12px; }

.section-header{
  display:flex;
  align-items:center;
  justify-content: space-between;
  gap: 10px;
  margin-bottom: 10px;
}
.section-header h3{
  display:flex;
  align-items:center;
  gap: 10px;
  font-size: 15px;
  font-weight: 950;
}
.section-header h3 i{ color: rgba(34,211,238,.95); }

/* recent activity list */
.activity-list{
  display:flex;
  flex-direction: column;
  gap: 10px;
}
.activity-item{
  display:flex;
  align-items:flex-start;
  gap: 12px;
  padding: 12px;
  border-radius: 16px;
  border: 1px solid rgba(255,255,255,.10);
  background: rgba(255,255,255,.03);
}
.activity-icon{
  width: 42px;
  height: 42px;
  border-radius: 16px;
  display:grid;
  place-items:center;
  border: 1px solid rgba(255,255,255,.14);
  background: rgba(255,255,255,.05);
  color: rgba(234,240,255,.95);
  flex-shrink: 0;
}
.activity-icon.goal{ color: rgba(34,211,153,.95); }
.activity-icon.completed{ color: rgba(52,211,153,.95); }
.activity-icon.achievement{ color: rgba(251,191,36,.95); }

.activity-title{
  font-weight: 950;
  font-size: 13.5px;
  margin-bottom: 4px;
}
.activity-time{
  font-size: 11.5px;
  color: var(--muted2);
  font-weight: 800;
}

.empty-state{
  text-align:center;
  padding: 16px;
  border-radius: 16px;
  border: 1px solid rgba(255,255,255,.10);
  background: rgba(255,255,255,.03);
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

/* ===== Forms ===== */
.form-row{
  display:grid;
  grid-template-columns: 1fr 1fr;
  gap: 12px;
}
.form-group{ margin-top: 10px; }
.form-group label{
  display:block;
  font-size: 12px;
  color: var(--muted);
  font-weight: 900;
  margin-bottom: 6px;
}
.form-group label.required::after{
  content:" *";
  color: rgba(251,113,133,.95);
}
input, select, textarea{
  width: 100%;
  padding: 10px 12px;
  border-radius: 14px;
  border: 1px solid rgba(255,255,255,.12);
  background: rgba(255,255,255,.04);
  color: rgba(234,240,255,.95);
  outline: none;
  transition: border-color var(--transition-fast), background var(--transition-fast), box-shadow var(--transition-fast);
}
textarea{ min-height: 120px; resize: vertical; }
input:focus, select:focus, textarea:focus{
  border-color: rgba(34,211,238,.45);
  box-shadow: 0 0 0 3px rgba(34,211,238,.12);
  background: rgba(255,255,255,.05);
}

/* ===== Modal ===== */
.modal-overlay{
  display:none;
  position: fixed;
  inset: 0;
  background: rgba(0,0,0,.55);
  backdrop-filter: blur(8px);
  z-index: 3000;
  align-items: center;
  justify-content: center;
  padding: 18px;
}
.modal{
  width: 100%;
  max-width: 520px;
  border-radius: 18px;
  border: 1px solid rgba(255,255,255,.14);
  background:
    radial-gradient(120% 200% at 10% 0%, rgba(79,70,229,.18), transparent 60%),
    rgba(10,14,35,.85);
  box-shadow: var(--shadow);
  overflow: hidden;
}
.modal-header{
  display:flex;
  align-items:center;
  justify-content: space-between;
  padding: 14px 14px;
  border-bottom: 1px solid rgba(255,255,255,.10);
}
.modal-header h3{ font-size: 15px; font-weight: 950; }
.modal-close{
  width: 40px;
  height: 40px;
  border-radius: 14px;
  border: 1px solid rgba(255,255,255,.14);
  background: rgba(255,255,255,.06);
  color: rgba(234,240,255,.92);
}
.modal-body{ padding: 14px; }

.file-upload{
  padding: 16px;
  border-radius: 16px;
  border: 1px dashed rgba(255,255,255,.18);
  background: rgba(255,255,255,.03);
  text-align:center;
  cursor:pointer;
}
.file-upload i{ font-size: 26px; color: rgba(34,211,238,.95); margin-bottom: 8px; }
.file-upload p{ color: var(--muted); font-size: 13px; margin-top: 4px; }
.file-input{ display:none; }

.preview-image{
  width: 160px;
  height: 160px;
  border-radius: 26px;
  object-fit: cover;
  border: 1px solid rgba(255,255,255,.16);
  box-shadow: 0 18px 34px rgba(0,0,0,.22);
}

/* ===== Responsive ===== */
@media (max-width: 980px){
  .profile-layout{ grid-template-columns: 1fr; }
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
    z-index: 2600;
  }
  .sidebar.active{ transform: translateX(0); }
  .sidebar-close{ display:grid; }

  .main-content{ padding: 18px 14px 28px; }
}

@media (max-width: 640px){
  .form-row{ grid-template-columns: 1fr; }
  .profile-tabs{ flex-wrap: wrap; }
  .tab-btn{ width: 100%; justify-content: center; }
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
                <?php if (!empty($student['profile_picture'])): ?>
                    <img src="../<?php echo htmlspecialchars($student['profile_picture']); ?>" alt="Profile" class="profile-pic">
                <?php else: ?>
                    <div class="profile-pic default">
                        <?php echo strtoupper(substr($student['name'], 0, 1)); ?>
                    </div>
                <?php endif; ?>
                <div class="user-info">
                    <h4><?php echo htmlspecialchars($student['name']); ?></h4>
                    <p><?php echo htmlspecialchars($student['email']); ?></p>
                    <span style="font-size: 11px; background: #e0e7ff; color: #4f46e5; padding: 2px 8px; border-radius: 12px;">
                        STUDENT
                    </span>
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
                    <div class="sidebar-stat-icon">
                        <i class="fas fa-bullseye"></i>
                    </div>
                    <div class="sidebar-stat-info">
                        <div class="sidebar-stat-label">Goals</div>
                        <div class="sidebar-stat-number">
                            <?php echo $sidebar_stats['completed_goals']; ?>/<?php echo $sidebar_stats['total_goals']; ?>
                        </div>
                    </div>
                </div>
                <div class="sidebar-stat">
                    <div class="sidebar-stat-icon">
                        <i class="fas fa-star"></i>
                    </div>
                    <div class="sidebar-stat-info">
                        <div class="sidebar-stat-label">Points</div>
                        <div class="sidebar-stat-number"><?php echo $sidebar_stats['total_points']; ?></div>
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
            <div class="profile-container">
                <header class="page-header">
                    <h1>My Profile</h1>
                    <p>Manage your personal information and settings</p>
                </header>

                <!-- Alerts -->
                <?php if (!empty($success)): ?>
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

                <!-- Profile Tabs -->
                <div class="profile-tabs">
                <button class="tab-btn active" onclick="showTab('overview', event)">

                        <i class="fas fa-user"></i> Overview
                    </button>
                    <button class="tab-btn" onclick="showTab('edit', event)">
                        <i class="fas fa-edit"></i> Edit Profile
                    </button>
                    <button class="tab-btn" onclick="showTab('security', event)">
                        <i class="fas fa-lock"></i> Security
                    </button>
                </div>

                <div class="profile-layout">
                    <!-- Profile Sidebar -->
                    <div class="profile-sidebar">
                        <div class="profile-header">
                            <div class="profile-avatar">
                                <?php if (!empty($student['profile_picture'])): ?>
                                    <img src="../<?php echo htmlspecialchars($student['profile_picture']); ?>"
                                        alt="Profile Picture" class="profile-picture" id="currentProfilePic">
                                <?php else: ?>
                                    <div class="profile-picture default" id="currentProfilePic">
                                        <?php echo strtoupper(substr($student['name'], 0, 1)); ?>
                                    </div>
                                <?php endif; ?>

                                <div class="avatar-upload">
                                    <button class="avatar-upload-btn" onclick="openPhotoModal()" title="Change Photo">
                                        <i class="fas fa-camera"></i>
                                    </button>
                                </div>
                            </div>

                            <h2 class="profile-name"><?php echo htmlspecialchars($student['name']); ?></h2>
                            <p class="profile-email"><?php echo htmlspecialchars($student['email']); ?></p>
                            <span class="profile-badge">Student</span>
                        </div>

                        <!-- Profile Stats -->
                        <div class="profile-stats">
                            <div class="stat-item">
                                <div class="stat-number"><?php echo $student['total_goals']; ?></div>
                                <div class="stat-label">Total Goals</div>
                            </div>
                            <div class="stat-item">
                                <div class="stat-number"><?php echo $student['completed_goals']; ?></div>
                                <div class="stat-label">Completed</div>
                            </div>
                            <div class="stat-item">
                                <div class="stat-number"><?php echo $student['total_achievements']; ?></div>
                                <div class="stat-label">Achievements</div>
                            </div>
                            <div class="stat-item">
                                <div class="stat-number"><?php echo $student['total_points']; ?></div>
                                <div class="stat-label">Points</div>
                            </div>
                        </div>

                        <!-- Profile Info -->
                        <div class="profile-info">
                            <div class="info-item">
                                <span class="info-label">Student ID</span>
                                <span class="info-value <?php echo empty($student['student_id']) ? 'empty' : ''; ?>">
                                    <?php echo !empty($student['student_id']) ? htmlspecialchars($student['student_id']) : 'Not set'; ?>
                                </span>
                            </div>
                            <div class="info-item">
                                <span class="info-label">Department</span>
                                <span class="info-value <?php echo empty($student['department']) ? 'empty' : ''; ?>">
                                    <?php echo !empty($student['department']) ? htmlspecialchars($student['department']) : 'Not set'; ?>
                                </span>
                            </div>
                            <div class="info-item">
                                <span class="info-label">Semester</span>
                                <span class="info-value <?php echo empty($student['semester']) ? 'empty' : ''; ?>">
                                    <?php echo !empty($student['semester']) ? htmlspecialchars($student['semester']) : 'Not set'; ?>
                                </span>
                            </div>
                            <div class="info-item">
                                <span class="info-label">Member Since</span>
                                <span class="info-value">
                                    <?php echo date('F d, Y', strtotime($student['created_at'])); ?>
                                </span>
                            </div>
                            <?php if (!empty($student['last_login'])): ?>
                                <div class="info-item">
                                    <span class="info-label">Last Login</span>
                                    <span class="info-value">
                                        <?php echo date('M d, Y h:i A', strtotime($student['last_login'])); ?>
                                    </span>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Profile Content -->
                    <div class="profile-content">
                        <!-- Overview Tab -->
                        <div id="overview-tab" class="tab-content active">
                            <!-- Recent Activity -->
                            <div class="profile-section">
                                <div class="section-header">
                                    <h3><i class="fas fa-chart-line"></i> Recent Activity</h3>
                                </div>

                                <div class="activity-list">
                                    <?php if (!empty($recent_activities)): ?>
                                        <?php foreach ($recent_activities as $activity):
                                            $icon_class = '';
                                            $icon = '';
                                            if ($activity['type'] === 'goal_created') {
                                                $icon_class = 'goal';
                                                $icon = 'fas fa-plus-circle';
                                            } elseif ($activity['type'] === 'goal_completed') {
                                                $icon_class = 'completed';
                                                $icon = 'fas fa-check-circle';
                                            } elseif ($activity['type'] === 'achievement_earned') {
                                                $icon_class = 'achievement';
                                                $icon = 'fas fa-trophy';
                                            }
                                        ?>
                                            <div class="activity-item">
                                                <div class="activity-icon <?php echo $icon_class; ?>">
                                                    <i class="<?php echo $icon; ?>"></i>
                                                </div>
                                                <div class="activity-content">
                                                    <div class="activity-title">
                                                        <?php echo htmlspecialchars($activity['action']) . htmlspecialchars($activity['title']); ?>
                                                    </div>
                                                    <div class="activity-time">
                                                        <?php echo date('M d, Y h:i A', strtotime($activity['timestamp'])); ?>
                                                    </div>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <div class="empty-state">
                                            <i class="fas fa-history"></i>
                                            <p>No recent activity</p>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <!-- Bio Section -->
                            <?php if (!empty($student['bio'])): ?>
                                <div id="bio-section" class="profile-section">
                                    <div class="section-header">
                                        <h3><i class="fas fa-user-edit"></i> About Me</h3>
                                    </div>
                                    <div style="color: #4b5563; line-height: 1.6;">
                                        <?php echo nl2br(htmlspecialchars($student['bio'])); ?>
                                    </div>
                                </div>
                            <?php endif; ?>
                        </div>

                        <!-- Edit Profile Tab -->
                        <div id="edit-tab" class="tab-content">
                            <div class="profile-section">
                                <div class="section-header">
                                    <h3><i class="fas fa-user-edit"></i> Edit Profile</h3>
                                </div>

                                <form method="POST" id="editProfileForm">
                                    <input type="hidden" name="update_profile" value="1">

                                    <div class="form-row">
                                        <div class="form-group">
                                            <label for="name" class="required">Full Name</label>
                                            <input type="text" id="name" name="name"
                                                value="<?php echo htmlspecialchars($student['name']); ?>" required>
                                        </div>

                                        <div class="form-group">
                                            <label for="email" class="required">Email Address</label>
                                            <input type="email" id="email" name="email"
                                                value="<?php echo htmlspecialchars($student['email']); ?>" required>
                                        </div>
                                    </div>

                                    <div class="form-row">
                                        <div class="form-group">
                                            <label for="student_id">Student ID</label>
                                            <input type="text" id="student_id" name="student_id"
                                                value="<?php echo htmlspecialchars($student['student_id'] ?? ''); ?>">
                                        </div>

                                        <div class="form-group">
                                            <label for="department">Department</label>
                                            <input type="text" id="department" name="department"
                                                value="<?php echo htmlspecialchars($student['department'] ?? ''); ?>">
                                        </div>
                                    </div>

                                    <div class="form-row">
                                        <div class="form-group">
                                            <label for="semester">Semester</label>
                                            <select id="semester" name="semester">
                                                <option value="">Select Semester</option>
                                                <?php for ($i = 1; $i <= 8; $i++): ?>
                                                    <option value="<?php echo $i; ?>" <?php echo ($student['semester'] == $i) ? 'selected' : ''; ?>>
                                                        Semester <?php echo $i; ?>
                                                    </option>
                                                <?php endfor; ?>
                                            </select>
                                        </div>
                                    </div>

                                    <div class="form-group">
                                        <label for="bio">Bio / About Me</label>
                                        <textarea id="bio" name="bio" placeholder="Tell us about yourself..."><?php echo htmlspecialchars($student['bio'] ?? ''); ?></textarea>
                                    </div>

                                    <div style="display: flex; gap: 15px; margin-top: 30px;">
                                        <button type="submit" class="btn btn-primary">
                                            <i class="fas fa-save"></i> Save Changes
                                        </button>
                                        <button type="button" class="btn btn-outline" onclick="showTab('overview')">
                                            <i class="fas fa-times"></i> Cancel
                                        </button>
                                    </div>
                                </form>
                            </div>
                        </div>

                        <!-- Security Tab -->
                        <div id="security-tab" class="tab-content">
                            <div class="profile-section">
                                <div class="section-header">
                                    <h3><i class="fas fa-lock"></i> Change Password</h3>
                                </div>

                                <form method="POST" id="passwordForm">
                                    <input type="hidden" name="change_password" value="1">

                                    <div class="form-group">
                                        <label for="current_password" class="required">Current Password</label>
                                        <input type="password" id="current_password" name="current_password" required>
                                    </div>

                                    <div class="form-row">
                                        <div class="form-group">
                                            <label for="new_password" class="required">New Password</label>
                                            <input type="password" id="new_password" name="new_password" required>
                                        </div>

                                        <div class="form-group">
                                            <label for="confirm_password" class="required">Confirm New Password</label>
                                            <input type="password" id="confirm_password" name="confirm_password" required>
                                        </div>
                                    </div>

                                    <div style="display: flex; gap: 15px; margin-top: 30px;">
                                        <button type="submit" class="btn btn-primary">
                                            <i class="fas fa-lock"></i> Change Password
                                        </button>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </main>
    </div>

    <!-- Photo Upload Modal -->
    <div class="modal-overlay" id="photoModal">
        <div class="modal">
            <div class="modal-header">
                <h3>Update Profile Picture</h3>
                <button class="modal-close" onclick="closePhotoModal()">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <div class="modal-body">
                <form method="POST" enctype="multipart/form-data" id="photoForm">
                    <input type="hidden" name="upload_photo" value="1">

                    <div class="file-upload" id="uploadArea" onclick="document.getElementById('profilePicture').click()">
                        <i class="fas fa-cloud-upload-alt"></i>
                        <p>Click to browse or drag and drop</p>
                        <p style="font-size: 12px;">JPG, PNG or GIF (Max 2MB)</p>
                        <input type="file" id="profilePicture" name="profile_picture" class="file-input" accept="image/*" onchange="previewImage(this)">
                    </div>

                    <div id="previewContainer" style="text-align: center; margin: 20px 0; display: none;">
                        <img id="imagePreview" class="preview-image" src="" alt="Preview">
                    </div>

                    <div style="display: flex; gap: 15px; margin-top: 25px;">
                        <button type="submit" class="btn btn-primary" id="uploadBtn" style="display: none;">
                            <i class="fas fa-upload"></i> Upload Photo
                        </button>
                        <?php if (!empty($student['profile_picture'])): ?>
                            <button type="button" class="btn btn-danger" onclick="deletePhoto()">
                                <i class="fas fa-trash"></i> Remove Photo
                            </button>
                        <?php endif; ?>
                        <button type="button" class="btn btn-outline" onclick="closePhotoModal()">
                            <i class="fas fa-times"></i> Cancel
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Delete Photo Form (Hidden) -->
    <form method="POST" id="deletePhotoForm" style="display: none;">
        <input type="hidden" name="delete_photo" value="1">
    </form>

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

function showTab(tabName, event){
  if (event) event.preventDefault();

  document.querySelectorAll('.tab-content').forEach(t => t.classList.remove('active'));
  document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));

  document.getElementById(tabName + '-tab').classList.add('active');

  if (event && event.currentTarget) {
    event.currentTarget.classList.add('active');
  } else {
    document.querySelector(`.tab-btn[onclick*="${tabName}"]`)?.classList.add('active');
  }
}


        // Photo Modal Functions
        function openPhotoModal() {
            document.getElementById('photoModal').style.display = 'flex';
        }

        function closePhotoModal() {
            document.getElementById('photoModal').style.display = 'none';
            resetPhotoForm();
        }

        function resetPhotoForm() {
            const previewContainer = document.getElementById('previewContainer');
            const uploadBtn = document.getElementById('uploadBtn');
            const fileInput = document.getElementById('profilePicture');

            previewContainer.style.display = 'none';
            uploadBtn.style.display = 'none';
            fileInput.value = '';
        }

        function previewImage(input) {
            const previewContainer = document.getElementById('previewContainer');
            const preview = document.getElementById('imagePreview');
            const uploadBtn = document.getElementById('uploadBtn');

            if (input.files && input.files[0]) {
                const reader = new FileReader();

                reader.onload = function(e) {
                    preview.src = e.target.result;
                    previewContainer.style.display = 'block';
                    uploadBtn.style.display = 'flex';
                }

                reader.readAsDataURL(input.files[0]);
            }
        }

        function deletePhoto() {
            if (confirm('Are you sure you want to remove your profile picture?')) {
                document.getElementById('deletePhotoForm').submit();
            }
        }

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

        // Form validation
        document.getElementById('editProfileForm')?.addEventListener('submit', function(e) {
            const name = document.getElementById('name').value.trim();
            const email = document.getElementById('email').value.trim();

            if (!name || !email) {
                e.preventDefault();
                alert('Please fill in all required fields.');
                return false;
            }
        });

        document.getElementById('passwordForm')?.addEventListener('submit', function(e) {
            const currentPassword = document.getElementById('current_password').value;
            const newPassword = document.getElementById('new_password').value;
            const confirmPassword = document.getElementById('confirm_password').value;

            if (!currentPassword || !newPassword || !confirmPassword) {
                e.preventDefault();
                alert('Please fill in all password fields.');
                return false;
            }

            if (newPassword.length < 6) {
                e.preventDefault();
                alert('New password must be at least 6 characters long.');
                return false;
            }

            if (newPassword !== confirmPassword) {
                e.preventDefault();
                alert('New passwords do not match.');
                return false;
            }
        });

        // Close modal when clicking outside
        document.getElementById('photoModal').addEventListener('click', function(e) {
            if (e.target === this) {
                closePhotoModal();
            }
        });
    </script>
</body>

</html>