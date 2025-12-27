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
        LEFT JOIN student_goals sg ON u.id = sg.student_id
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
        
        // Check for upload errors
        if ($file['error'] !== UPLOAD_ERR_OK) {
            $error = "File upload failed with error code: " . $file['error'];
        } else {
            // Validate file type
            $allowed_types = ['image/jpeg', 'image/jpg', 'image/png', 'image/gif'];
            $file_type = mime_content_type($file['tmp_name']);
            
            if (!in_array($file_type, $allowed_types)) {
                $error = "Only JPG, PNG, and GIF files are allowed.";
            } elseif ($file['size'] > 2 * 1024 * 1024) { // 2MB limit
                $error = "File size must be less than 2MB.";
            } else {
                try {
                    // Create uploads directory if it doesn't exist
                    $upload_dir = '../uploads/profiles/';
                    if (!file_exists($upload_dir)) {
                        mkdir($upload_dir, 0777, true);
                    }
                    
                    // Generate unique filename
                    $file_extension = pathinfo($file['name'], PATHINFO_EXTENSION);
                    $filename = 'profile_' . $student_id . '_' . time() . '.' . $file_extension;
                    $file_path = $upload_dir . $filename;
                    
                    // Move uploaded file
                    if (move_uploaded_file($file['tmp_name'], $file_path)) {
                        // Delete old profile picture if exists
                        if (!empty($student['profile_picture']) && file_exists('../' . $student['profile_picture'])) {
                            unlink('../' . $student['profile_picture']);
                        }
                        
                        // Update database with relative path
                        $relative_path = 'uploads/profiles/' . $filename;
                        $update_pic_stmt = $pdo->prepare("UPDATE users SET profile_picture = ?, updated_at = NOW() WHERE id = ?");
                        $update_pic_stmt->execute([$relative_path, $student_id]);
                        
                        // Update session
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
    
    // Handle delete profile picture
    elseif (isset($_POST['delete_photo'])) {
        try {
            // Delete file if exists
            if (!empty($student['profile_picture']) && file_exists('../' . $student['profile_picture'])) {
                unlink('../' . $student['profile_picture']);
            }
            
            // Update database
            $delete_pic_stmt = $pdo->prepare("UPDATE users SET profile_picture = NULL, updated_at = NOW() WHERE id = ?");
            $delete_pic_stmt->execute([$student_id]);
            
            // Update session
            $_SESSION['profile_picture'] = null;
            $student['profile_picture'] = null;
            
            $success = "Profile picture removed successfully!";
        } catch (Exception $e) {
            $error = "Error removing profile picture: " . $e->getMessage();
        }
    }
}

// Get recent activities
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
    // Silently fail for activities - not critical
}

// Get sidebar stats separately for navigation
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
    // Silently fail for stats
}

// Get unread notifications count
$unread_count = 0;
try {
    $notif_stmt = $pdo->prepare("SELECT COUNT(*) as count FROM notifications WHERE user_id = ? AND is_read = 0");
    $notif_stmt->execute([$student_id]);
    $notif_result = $notif_stmt->fetch();
    $unread_count = $notif_result['count'] ?? 0;
} catch (Exception $e) {
    // Silently fail for notifications
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Profile - ProgressMate</title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=Roboto:wght@400;500;700&display=swap" rel="stylesheet">
    
   <style>
/* ===== CSS VARIABLES & THEME ===== */
:root {
    /* Primary Colors */
    --primary: #4f46e5;
    --primary-light: #eef2ff;
    --primary-dark: #4338ca;
    
    /* Secondary Colors */
    --secondary: #64748b;
    --secondary-light: #f8fafc;
    --secondary-dark: #475569;
    
    /* Status Colors */
    --success: #10b981;
    --success-light: #d1fae5;
    --warning: #f59e0b;
    --warning-light: #fef3c7;
    --danger: #ef4444;
    --danger-light: #fee2e2;
    --info: #3b82f6;
    --info-light: #dbeafe;
    
    /* Neutral Colors */
    --dark: #1e293b;
    --light: #f1f5f9;
    --white: #ffffff;
    --gray: #e2e8f0;
    --gray-light: #f9fafb;
    --gray-dark: #6b7280;
    
    /* Typography */
    --font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
    --font-size-xs: 0.75rem;
    --font-size-sm: 0.875rem;
    --font-size-base: 1rem;
    --font-size-lg: 1.125rem;
    --font-size-xl: 1.25rem;
    --font-size-2xl: 1.5rem;
    --font-size-3xl: 1.875rem;
    --font-size-4xl: 2.25rem;
    
    /* Spacing */
    --spacing-xs: 0.25rem;
    --spacing-sm: 0.5rem;
    --spacing-md: 1rem;
    --spacing-lg: 1.5rem;
    --spacing-xl: 2rem;
    --spacing-2xl: 3rem;
    
    /* Borders & Shadows */
    --radius-sm: 0.375rem;
    --radius-md: 0.5rem;
    --radius-lg: 0.75rem;
    --radius-xl: 1rem;
    --radius-2xl: 1.5rem;
    --shadow-sm: 0 1px 2px 0 rgba(0, 0, 0, 0.05);
    --shadow-md: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
    --shadow-lg: 0 10px 15px -3px rgba(0, 0, 0, 0.1);
    --shadow-xl: 0 20px 25px -5px rgba(0, 0, 0, 0.1);
    
    /* Transitions */
    --transition-fast: 150ms ease;
    --transition-base: 300ms ease;
    --transition-slow: 500ms ease;
}

/* ===== BASE RESET ===== */
* {
    margin: 0;
    padding: 0;
    box-sizing: border-box;
}

html {
    font-size: 16px;
    scroll-behavior: smooth;
}

body {
    font-family: var(--font-family);
    background: linear-gradient(135deg, #f5f7fa 0%, #e4edf5 100%);
    color: var(--dark);
    min-height: 100vh;
    line-height: 1.5;
    overflow-x: hidden;
}

a {
    text-decoration: none;
    color: inherit;
    transition: color var(--transition-fast);
}

button {
    font-family: inherit;
    cursor: pointer;
    border: none;
    background: none;
    outline: none;
}

input, select, textarea {
    font-family: inherit;
    font-size: inherit;
    outline: none;
}

img {
    max-width: 100%;
    height: auto;
}

/* ===== UTILITY CLASSES ===== */
.container {
    width: 100%;
    max-width: 1200px;
    margin: 0 auto;
    padding: 0 var(--spacing-md);
}

.flex {
    display: flex;
}

.flex-col {
    flex-direction: column;
}

.items-center {
    align-items: center;
}

.justify-between {
    justify-content: space-between;
}

.gap-sm { gap: var(--spacing-sm); }
.gap-md { gap: var(--spacing-md); }
.gap-lg { gap: var(--spacing-lg); }

.mt-sm { margin-top: var(--spacing-sm); }
.mt-md { margin-top: var(--spacing-md); }
.mt-lg { margin-top: var(--spacing-lg); }
.mb-sm { margin-bottom: var(--spacing-sm); }
.mb-md { margin-bottom: var(--spacing-md); }
.mb-lg { margin-bottom: var(--spacing-lg); }

.text-center { text-align: center; }
.text-right { text-align: right; }
.text-primary { color: var(--primary); }
.text-success { color: var(--success); }
.text-danger { color: var(--danger); }
.text-warning { color: var(--warning); }
.text-muted { color: var(--gray-dark); }

.bg-white { background: var(--white); }
.bg-light { background: var(--light); }
.bg-primary { background: var(--primary); }
.bg-success { background: var(--success); }
.bg-danger { background: var(--danger); }

.rounded-sm { border-radius: var(--radius-sm); }
.rounded-md { border-radius: var(--radius-md); }
.rounded-lg { border-radius: var(--radius-lg); }

.shadow-sm { box-shadow: var(--shadow-sm); }
.shadow-md { box-shadow: var(--shadow-md); }
.shadow-lg { box-shadow: var(--shadow-lg); }

/* ===== BUTTONS ===== */
.btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: var(--spacing-sm);
    padding: 0.625rem 1.25rem;
    border-radius: var(--radius-md);
    font-weight: 500;
    font-size: var(--font-size-sm);
    transition: all var(--transition-base);
    border: 1px solid transparent;
    cursor: pointer;
}

.btn-primary {
    background: linear-gradient(135deg, var(--primary), var(--primary-dark));
    color: var(--white);
    box-shadow: var(--shadow-md);
}

.btn-primary:hover {
    transform: translateY(-2px);
    box-shadow: var(--shadow-lg);
}

.btn-outline {
    background: transparent;
    color: var(--primary);
    border-color: var(--primary);
}

.btn-outline:hover {
    background: var(--primary);
    color: var(--white);
}

.btn-sm {
    padding: 0.375rem 0.75rem;
    font-size: var(--font-size-xs);
}

.btn-lg {
    padding: 0.75rem 1.5rem;
    font-size: var(--font-size-base);
}

/* ===== DASHBOARD LAYOUT - FIXED SIDEBAR WITH SCROLLABLE MAIN ===== */
.dashboard-wrapper {
    display: flex;
    min-height: 100vh;
    position: relative;
}

/* ===== SIDEBAR - FIXED, NON-SCROLLABLE ===== */
.sidebar {
    width: 280px;
    background: var(--white);
    border-right: 1px solid var(--gray);
    position: fixed;
    height: 100vh;
    left: 0;
    top: 0;
    z-index: 1000;
    display: flex;
    flex-direction: column;
    transition: transform var(--transition-base);
    overflow: hidden; /* Changed from auto to hidden */
    box-shadow: 4px 0 20px rgba(0, 0, 0, 0.08);
}

/* Remove scrollbar styles since sidebar is non-scrollable */
.sidebar::-webkit-scrollbar {
    display: none;
}

.sidebar-header {
    padding: var(--spacing-lg);
    border-bottom: 1px solid var(--gray);
    display: flex;
    align-items: center;
    justify-content: space-between;
    flex-shrink: 0; /* Prevent shrinking */
}

.logo {
    font-size: var(--font-size-xl);
    font-weight: 800;
    color: var(--primary);
    display: flex;
    align-items: center;
    gap: var(--spacing-sm);
}

.sidebar-close {
    display: none;
    color: var(--secondary);
    font-size: var(--font-size-xl);
    background: none;
    border: none;
    cursor: pointer;
    padding: var(--spacing-xs);
}

.user-profile {
    padding: var(--spacing-lg);
    border-bottom: 1px solid var(--gray);
    text-align: center;
    flex-shrink: 0; /* Prevent shrinking */
}

.profile-pic {
    width: 80px;
    height: 80px;
    border-radius: 50%;
    object-fit: cover;
    border: 4px solid var(--primary-light);
    margin: 0 auto var(--spacing-md);
}

.profile-pic.default {
    background: linear-gradient(135deg, var(--primary), #8b5cf6);
    color: var(--white);
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: var(--font-size-2xl);
    font-weight: bold;
}

.user-info h4 {
    font-size: var(--font-size-lg);
    font-weight: 600;
    margin-bottom: var(--spacing-xs);
}

.user-info p {
    color: var(--secondary);
    font-size: var(--font-size-sm);
    margin-bottom: var(--spacing-sm);
}

.user-tag {
    display: inline-block;
    background: var(--primary-light);
    color: var(--primary);
    padding: 0.25rem 0.75rem;
    border-radius: 20px;
    font-size: var(--font-size-xs);
    font-weight: 600;
}

/* Nav Menu - Now scrollable within fixed sidebar */
.nav-menu {
    flex: 1;
    padding: var(--spacing-md) 0;
    overflow-y: auto; /* Only nav menu scrolls */
    min-height: 0; /* Important for flex scrolling */
}

.nav-menu::-webkit-scrollbar {
    width: 6px;
}

.nav-menu::-webkit-scrollbar-track {
    background: var(--light);
}

.nav-menu::-webkit-scrollbar-thumb {
    background: var(--gray);
    border-radius: 3px;
}

.nav-link {
    display: flex;
    align-items: center;
    gap: var(--spacing-sm);
    padding: 0.875rem var(--spacing-lg);
    margin: 0 var(--spacing-sm);
    color: var(--secondary-dark);
    font-weight: 500;
    border-radius: var(--radius-md);
    transition: all var(--transition-base);
}

.nav-link:hover {
    background: var(--secondary-light);
    color: var(--primary);
    transform: translateX(4px);
}

.nav-link.active {
    background: linear-gradient(90deg, var(--primary-light), transparent);
    color: var(--primary);
    font-weight: 600;
    border-left: 4px solid var(--primary);
}

.nav-link i {
    width: 24px;
    text-align: center;
    font-size: 1.125rem;
}

.badge {
    background: linear-gradient(135deg, var(--danger), #dc2626);
    color: var(--white);
    font-size: var(--font-size-xs);
    padding: 0.25rem 0.5rem;
    border-radius: 20px;
    margin-left: auto;
    font-weight: 700;
    min-width: 1.5rem;
    text-align: center;
}

.sidebar-quick-stats {
    padding: var(--spacing-lg);
    background: var(--secondary-light);
    border-top: 1px solid var(--gray);
    flex-shrink: 0; /* Prevent shrinking */
}

.sidebar-stat {
    display: flex;
    align-items: center;
    gap: var(--spacing-sm);
    margin-bottom: var(--spacing-md);
}

.sidebar-stat:last-child {
    margin-bottom: 0;
}

.sidebar-stat-icon {
    width: 48px;
    height: 48px;
    border-radius: var(--radius-md);
    background: var(--primary-light);
    color: var(--primary);
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.25rem;
}

.sidebar-stat-number {
    font-size: var(--font-size-lg);
    font-weight: 700;
    color: var(--dark);
}

.sidebar-stat-label {
    font-size: var(--font-size-xs);
    color: var(--secondary);
}

.logout-btn {
    margin: var(--spacing-lg);
    background: linear-gradient(135deg, var(--danger-light), #fecaca);
    color: #dc2626;
    padding: 0.875rem;
    border-radius: var(--radius-lg);
    font-weight: 600;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: var(--spacing-sm);
    transition: all var(--transition-base);
    text-align: center;
    text-decoration: none;
    flex-shrink: 0; /* Prevent shrinking */
}

.logout-btn:hover {
    background: linear-gradient(135deg, #fecaca, #fca5a5);
    transform: translateY(-2px);
}
.main-content {
            flex: 1;
            margin-left: 280px;
            padding: 2.5rem 1.5rem;
            min-height: 100vh;
        }

        /* Page Header */
        .page-header {
            text-align: center;
            margin-bottom: 3rem;
        }

        .page-header h1 {
            font-size: 2.8rem;
            font-weight: 800;
            color: var(--dark);
            margin-bottom: 0.75rem;
        }

        .page-header p {
            font-size: 1.2rem;
            color: var(--secondary);
            max-width: 700px;
            margin: 0 auto;
        }

        /* Alerts */
        .alert {
            padding: 1.2rem 1.6rem;
            border-radius: var(--radius-md);
            margin-bottom: 1.8rem;
            display: flex;
            align-items: center;
            gap: 0.8rem;
            font-weight: 500;
            box-shadow: var(--shadow-sm);
        }

        .alert-success { background: #d1fae5; color: #065f46; border-left: 5px solid var(--success); }
        .alert-error { background: #fee2e2; color: #991b1b; border-left: 5px solid var(--danger); }

        /* Profile Layout - Two Column */
        .profile-container {
            max-width: 1200px;
            margin: 0 auto;
        }

        .profile-layout {
            display: grid;
            grid-template-columns: 380px 1fr;
            gap: 2.5rem;
        }

        /* Profile Sidebar */
        .profile-sidebar {
            background: var(--white);
            border-radius: var(--radius-lg);
            box-shadow: var(--shadow-sm);
            border: 1px solid var(--gray);
            overflow: hidden;
            padding: 2rem;
            display: flex;
            flex-direction: column;
            height: fit-content;
        }

        .profile-header {
            text-align: center;
            margin-bottom: 2rem;
        }

        .profile-avatar {
            position: relative;
            display: inline-block;
            margin-bottom: 1.2rem;
        }

        .profile-picture {
            width: 140px;
            height: 140px;
            border-radius: 50%;
            object-fit: cover;
            border: 5px solid var(--primary-light);
            box-shadow: var(--shadow-md);
        }

        .profile-picture.default {
            background: linear-gradient(135deg, var(--primary), #8b5cf6);
            color: white;
            font-size: 4rem;
            font-weight: bold;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .avatar-upload-btn {
            position: absolute;
            bottom: 8px;
            right: 8px;
            width: 40px;
            height: 40px;
            background: var(--primary);
            color: white;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            box-shadow: var(--shadow-md);
            transition: var(--transition);
        }

        .avatar-upload-btn:hover {
            background: var(--primary-dark);
            transform: scale(1.1);
        }

        .profile-name {
            font-size: 1.8rem;
            font-weight: 700;
            margin-bottom: 0.5rem;
        }

        .profile-email {
            color: var(--secondary);
            margin-bottom: 1rem;
        }

        .profile-badge {
            display: inline-block;
            background: var(--primary-light);
            color: var(--primary);
            padding: 0.5rem 1rem;
            border-radius: 30px;
            font-weight: 600;
            font-size: 0.9rem;
        }

        /* Profile Stats */
        .profile-stats {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 1.2rem;
            margin: 2rem 0;
        }

        .stat-item {
            text-align: center;
            padding: 1.2rem;
            background: var(--gray-light);
            border-radius: var(--radius-lg);
        }

        .stat-number {
            font-size: 2rem;
            font-weight: 800;
            color: var(--dark);
        }

        .stat-label {
            font-size: 0.9rem;
            color: var(--secondary);
            margin-top: 0.4rem;
        }

        /* Profile Info */
        .profile-info {
            margin-top: 1rem;
        }

        .info-item {
            display: flex;
            justify-content: space-between;
            padding: 0.9rem 0;
            border-bottom: 1px solid var(--gray);
        }

        .info-item:last-child {
            border-bottom: none;
        }

        .info-label {
            color: var(--secondary);
            font-weight: 500;
        }

        .info-value {
            font-weight: 600;
            color: var(--dark);
        }

        .info-value.empty {
            color: var(--gray-dark);
            font-style: italic;
        }

        /* Tabs */
        .profile-tabs {
            display: flex;
            background: var(--white);
            border-radius: var(--radius-lg);
            box-shadow: var(--shadow-sm);
            margin-bottom: 2rem;
            overflow: hidden;
        }

        .tab-btn {
            flex: 1;
            padding: 1.2rem;
            background: none;
            border: none;
            font-weight: 600;
            color: var(--secondary);
            cursor: pointer;
            transition: var(--transition);
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.6rem;
        }

        .tab-btn:hover {
            background: var(--gray-light);
        }

        .tab-btn.active {
            background: var(--primary);
            color: white;
        }

        /* Tab Content */
        .tab-content {
            display: none;
        }

        .tab-content.active {
            display: block;
        }

        .profile-section {
            background: var(--white);
            border-radius: var(--radius-lg);
            box-shadow: var(--shadow-sm);
            border: 1px solid var(--gray);
            margin-bottom: 2rem;
            overflow: hidden;
        }

        .section-header {
            padding: 1.4rem 1.8rem;
            background: var(--gray-light);
            border-bottom: 1px solid var(--gray);
        }

        .section-header h3 {
            font-size: 1.4rem;
            font-weight: 700;
            display: flex;
            align-items: center;
            gap: 0.7rem;
        }

        /* Activity List */
        .activity-list {
            padding: 1.5rem 1.8rem;
        }

        .activity-item {
            display: flex;
            gap: 1rem;
            padding: 1rem 0;
            border-bottom: 1px dashed var(--gray);
        }

        .activity-item:last-child {
            border-bottom: none;
        }

        .activity-icon {
            width: 44px;
            height: 44px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            flex-shrink: 0;
        }

        .activity-icon.goal { background: var(--primary); }
        .activity-icon.completed { background: var(--success); }
        .activity-icon.achievement { background: #f59e0b; }

        .activity-title {
            font-weight: 600;
            margin-bottom: 0.3rem;
        }

        .activity-time {
            font-size: 0.9rem;
            color: var(--secondary);
        }

        /* Forms */
        .form-container {
            padding: 2rem 1.8rem;
        }

        .form-row {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 1.5rem;
            margin-bottom: 1.5rem;
        }

        .form-group label {
            display: block;
            margin-bottom: 0.6rem;
            font-weight: 600;
            color: var(--dark);
        }

        .form-group input,
        .form-group select,
        .form-group textarea {
            width: 100%;
            padding: 0.9rem 1.2rem;
            border: 2px solid var(--gray);
            border-radius: var(--radius-md);
            font-size: 1rem;
            transition: var(--transition);
        }

        .form-group input:focus,
        .form-group select:focus,
        .form-group textarea:focus {
            border-color: var(--primary);
            box-shadow: 0 0 0 4px var(--primary-light);
        }

        .form-group textarea {
            min-height: 130px;
            resize: vertical;
        }

        /* Modal */
        .modal-overlay {
            display: none;
            position: fixed;
            top: 0; left: 0; right: 0; bottom: 0;
            background: rgba(0,0,0,0.5);
            backdrop-filter: blur(6px);
            z-index: 2000;
            align-items: center;
            justify-content: center;
        }

        .modal {
            background: var(--white);
            border-radius: var(--radius-lg);
            width: 90%;
            max-width: 500px;
            box-shadow: var(--shadow-xl);
            overflow: hidden;
        }

        .modal-header {
            padding: 1.5rem;
            background: var(--primary-light);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .modal-header h3 {
            font-weight: 700;
        }

        .file-upload {
            border: 3px dashed var(--gray);
            border-radius: var(--radius-lg);
            padding: 3rem;
            text-align: center;
            cursor: pointer;
            transition: var(--transition);
        }

        .file-upload:hover {
            border-color: var(--primary);
            background: var(--primary-light);
        }

        .preview-image {
            max-width: 200px;
            max-height: 200px;
            border-radius: var(--radius-lg);
            box-shadow: var(--shadow-md);
        }

        /* Responsive */
        @media (max-width: 992px) {
            .main-content { margin-left: 0; padding-top: 90px; }
            .profile-layout { grid-template-columns: 1fr; }
        }

        @media (max-width: 768px) {
            .profile-tabs {
                flex-direction: column;
            }
            .tab-btn {
                justify-content: flex-start;
                padding-left: 2rem;
            }
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
                <a href="dashboard.php" class="nav-link">
                    <i class="fas fa-tachometer-alt"></i>
                    <span>Dashboard</span>
                </a>
                <a href="goals.php" class="nav-link">
                    <i class="fas fa-bullseye"></i>
                    <span>My Goals</span>
                    <?php if ($sidebar_stats['total_goals'] > 0): ?>
                        <span class="badge"><?php echo $sidebar_stats['total_goals']; ?></span>
                    <?php endif; ?>
                </a>
                <a href="create_goal.php" class="nav-link">
                    <i class="fas fa-plus-circle"></i>
                    <span>Create Goal</span>
                </a>
                <a href="achievements.php" class="nav-link">
                    <i class="fas fa-trophy"></i>
                    <span>Achievements</span>
                    <?php if ($sidebar_stats['total_points'] > 0): ?>
                        <span class="badge"><?php echo $sidebar_stats['total_points']; ?> pts</span>
                    <?php endif; ?>
                </a>
                <a href="notifications.php" class="nav-link">
                    <i class="fas fa-inbox"></i>
                    <span>Notifications</span>
                    <?php if ($unread_count > 0): ?>
                        <span class="badge"><?php echo $unread_count; ?></span>
                    <?php endif; ?>
                </a>
                <a href="profile.php" class="nav-link active">
                    <i class="fas fa-user"></i>
                    <span>Profile</span>
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
                    <button class="tab-btn active" onclick="showTab('overview')">
                        <i class="fas fa-user"></i> Overview
                    </button>
                    <button class="tab-btn" onclick="showTab('edit')">
                        <i class="fas fa-edit"></i> Edit Profile
                    </button>
                    <button class="tab-btn" onclick="showTab('security')">
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
        
        // Tab functionality
        function showTab(tabName) {
            // Hide all tabs
            document.querySelectorAll('.tab-content').forEach(tab => {
                tab.classList.remove('active');
            });
            
            // Remove active class from all tab buttons
            document.querySelectorAll('.tab-btn').forEach(btn => {
                btn.classList.remove('active');
            });
            
            // Show selected tab
            document.getElementById(tabName + '-tab').classList.add('active');
            
            // Activate selected tab button
            event.target.classList.add('active');
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