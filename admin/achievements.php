<?php
// admin/achievements.php - Complete Achievement Management System

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once '../includes/db_connection.php';
require_once '../includes/functions.php';

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    header('Location: ../login.php');
    exit();
}

// CSRF Token
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];

// === POST Action Handling ===
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($csrf_token, $_POST['csrf_token'] ?? '')) {
        die('CSRF validation failed.');
    }

    $success = $error = '';
    
    try {
        $action = $_POST['action'] ?? '';
        
        // ADD/EDIT ACHIEVEMENT
        if ($action === 'add_achievement' || $action === 'edit_achievement') {
            // Validate and sanitize inputs
            $title = trim($_POST['title'] ?? '');
            $description = trim($_POST['description'] ?? '');
            $points = max(1, (int)($_POST['points'] ?? 10));
            $criteria_type = trim($_POST['criteria_type'] ?? '');
            $criteria_value = (int)($_POST['criteria_value'] ?? 0);
            $category_id = !empty($_POST['category_id']) ? (int)$_POST['category_id'] : null;
            
            // Icon validation
            $allowed_icons = ['trophy', 'medal', 'star', 'award', 'certificate', 'gem', 'crown', 'fire', 'bullseye', 'rocket', 'lightning', 'shield', 'book', 'graduation-cap', 'brain', 'heart', 'flag', 'moon', 'sun', 'cloud'];
            $icon = in_array($_POST['icon'] ?? '', $allowed_icons) ? $_POST['icon'] : 'trophy';
            
            // Color validation
            $color = preg_match('/^#[a-f0-9]{6}$/i', $_POST['color'] ?? '') ? strtolower($_POST['color']) : '#f59e0b';
            $is_active = isset($_POST['is_active']) ? 1 : 0;
            $image_path = trim($_POST['image_path'] ?? '');
            
            // Validation
            if (empty($title)) {
                throw new Exception("Achievement title is required.");
            }
            
            if (strlen($title) < 3) {
                throw new Exception("Title must be at least 3 characters.");
            }
            
            if ($points < 1) {
                throw new Exception("Points must be at least 1.");
            }
            
            if ($criteria_type && $criteria_value <= 0) {
                throw new Exception("Criteria value must be greater than 0.");
            }
            
            $pdo->beginTransaction();
            
            if ($action === 'add_achievement') {
                $stmt = $pdo->prepare("
                    INSERT INTO achievements 
                    (title, description, points, criteria_type, criteria_value, category_id, 
                     icon, color, is_active, image_path, created_at)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
                ");
                
                $stmt->execute([
                    $title,
                    $description,
                    $points,
                    $criteria_type,
                    $criteria_value,
                    $category_id,
                    $icon,
                    $color,
                    $is_active,
                    $image_path
                ]);
                
                $new_id = $pdo->lastInsertId();
                $success = "Achievement created successfully! ID: $new_id";
                
            } else {
                $id = (int)($_POST['achievement_id'] ?? 0);
                if ($id <= 0) {
                    throw new Exception("Invalid achievement ID.");
                }
                
                // Check if achievement exists
                $check = $pdo->prepare("SELECT id FROM achievements WHERE id = ? AND deleted_at IS NULL");
                $check->execute([$id]);
                if (!$check->fetch()) {
                    throw new Exception("Achievement not found.");
                }
                
                $stmt = $pdo->prepare("
                    UPDATE achievements
                    SET title = ?, description = ?, points = ?, criteria_type = ?, criteria_value = ?,
                        category_id = ?, icon = ?, color = ?, is_active = ?, image_path = ?, updated_at = NOW()
                    WHERE id = ?
                ");
                
                $stmt->execute([
                    $title,
                    $description,
                    $points,
                    $criteria_type,
                    $criteria_value,
                    $category_id,
                    $icon,
                    $color,
                    $is_active,
                    $image_path,
                    $id
                ]);
                
                $success = "Achievement updated successfully!";
            }
            
            $pdo->commit();
            
        // DELETE ACHIEVEMENT
        } elseif ($action === 'delete_achievement') {
            $id = (int)($_POST['achievement_id'] ?? 0);
            if ($id <= 0) {
                throw new Exception("Invalid achievement ID.");
            }
            
            $pdo->beginTransaction();
            
            // Soft delete from user_achievements
            $pdo->prepare("UPDATE user_achievements SET deleted_at = NOW() WHERE achievement_id = ?")->execute([$id]);
            
            // Soft delete from achievements
            $pdo->prepare("UPDATE achievements SET deleted_at = NOW() WHERE id = ?")->execute([$id]);
            
            $pdo->commit();
            $success = "Achievement deleted successfully!";
            
        // BULK ACTIONS
        } elseif ($action === 'bulk_action') {
            $bulk_action = $_POST['bulk_action'] ?? '';
            $achievement_ids = array_filter(array_map('intval', $_POST['achievement_ids'] ?? []));
            
            if (empty($achievement_ids)) {
                throw new Exception('Please select at least one achievement.');
            }
            
            $placeholders = implode(',', array_fill(0, count($achievement_ids), '?'));
            
            if ($bulk_action === 'activate') {
                $stmt = $pdo->prepare("UPDATE achievements SET is_active = 1 WHERE id IN ($placeholders)");
                $stmt->execute($achievement_ids);
                $success = count($achievement_ids) . ' achievement(s) activated.';
                
            } elseif ($bulk_action === 'deactivate') {
                $stmt = $pdo->prepare("UPDATE achievements SET is_active = 0 WHERE id IN ($placeholders)");
                $stmt->execute($achievement_ids);
                $success = count($achievement_ids) . ' achievement(s) deactivated.';
                
            } elseif ($bulk_action === 'delete') {
                $pdo->beginTransaction();
                $pdo->prepare("UPDATE user_achievements SET deleted_at = NOW() WHERE achievement_id IN ($placeholders)")->execute($achievement_ids);
                $pdo->prepare("UPDATE achievements SET deleted_at = NOW() WHERE id IN ($placeholders)")->execute($achievement_ids);
                $pdo->commit();
                $success = count($achievement_ids) . ' achievement(s) deleted.';
                
            } else {
                throw new Exception('Invalid bulk action.');
            }
            
        // AWARD TO SPECIFIC STUDENT
        } elseif ($action === 'award_to_student') {
            $achievement_id = (int)($_POST['achievement_id'] ?? 0);
            $student_id = (int)($_POST['student_id'] ?? 0);
            
            if ($achievement_id <= 0 || $student_id <= 0) {
                throw new Exception("Invalid achievement or student ID.");
            }
            
            // Check if already awarded
            $check = $pdo->prepare("
                SELECT id FROM user_achievements 
                WHERE user_id = ? AND achievement_id = ? AND deleted_at IS NULL
            ");
            $check->execute([$student_id, $achievement_id]);
            
            if ($check->fetch()) {
                throw new Exception("This student has already earned this achievement.");
            }
            
            // Get achievement details for points
            $achievement = $pdo->prepare("SELECT title, points FROM achievements WHERE id = ?");
            $achievement->execute([$achievement_id]);
            $ach = $achievement->fetch();
            
            if (!$ach) {
                throw new Exception("Achievement not found.");
            }
            
            $pdo->beginTransaction();
            
            // Award achievement
            $pdo->prepare("
                INSERT INTO user_achievements (user_id, achievement_id, earned_at) 
                VALUES (?, ?, NOW())
            ")->execute([$student_id, $achievement_id]);
            
            // Add points
            $pdo->prepare("
                UPDATE users SET points = points + ? WHERE id = ?
            ")->execute([$ach['points'], $student_id]);
            
            // Create notification
            $message = "🎉 Congratulations! You've been awarded the '{$ach['title']}' achievement by an admin (+{$ach['points']} points)!";
            $pdo->prepare("
                INSERT INTO notifications (user_id, title, message, type, related_id, created_at) 
                VALUES (?, 'Achievement Awarded!', ?, 'achievement', ?, NOW())
            ")->execute([$student_id, $message, $achievement_id]);
            
            $pdo->commit();
            $success = "Achievement '{$ach['title']}' awarded to student!";
            
        // RECALCULATE ALL ACHIEVEMENTS
        } elseif ($action === 'recalculate_all') {
            $total_awarded = recalculateAllAchievements($pdo);
            
            if ($total_awarded === 0) {
                $success = 'Recalculation completed. No new achievements awarded.';
            } else {
                $success = "Recalculation completed. $total_awarded new achievement(s) awarded to students.";
            }
            
        // AWARD TO ALL ELIGIBLE STUDENTS
        } elseif ($action === 'award_to_all_eligible') {
            $achievement_id = (int)($_POST['achievement_id'] ?? 0);
            
            if ($achievement_id <= 0) {
                throw new Exception("Invalid achievement ID.");
            }
            
            // Get achievement criteria
            $achievement = $pdo->prepare("
                SELECT * FROM achievements 
                WHERE id = ? AND is_active = 1 AND deleted_at IS NULL
            ");
            $achievement->execute([$achievement_id]);
            $ach = $achievement->fetch();
            
            if (!$ach) {
                throw new Exception("Achievement not found or inactive.");
            }
            
            // Find eligible students
            $eligible_students = [];
            $students = $pdo->query("
                SELECT id, name FROM users 
                WHERE role = 'student' AND status = 'active' AND deleted_at IS NULL
            ")->fetchAll(PDO::FETCH_ASSOC);
            
            $pdo->beginTransaction();
            $awarded_count = 0;
            
            foreach ($students as $student) {
                // Check if already has achievement
                $check = $pdo->prepare("
                    SELECT id FROM user_achievements 
                    WHERE user_id = ? AND achievement_id = ? AND deleted_at IS NULL
                ");
                $check->execute([$student['id'], $achievement_id]);
                
                if ($check->fetch()) {
                    continue; // Already has it
                }
                
                // Check if student meets criteria
                $meets_criteria = false;
                
                switch ($ach['criteria_type']) {
                    case 'total_completed_goals':
                        $count = getStat($pdo, 
                            "SELECT COUNT(*) FROM student_goals WHERE student_id = ? AND status = 'completed'",
                            [$student['id']]
                        );
                        $meets_criteria = ($count >= $ach['criteria_value']);
                        break;
                        
                    case 'completed_goals_in_category':
                        if ($ach['category_id']) {
                            $category = $pdo->prepare("SELECT name FROM categories WHERE id = ?")->execute([$ach['category_id']]);
                            $cat_name = $category->fetchColumn();
                            
                            if ($cat_name) {
                                $count = getStat($pdo,
                                    "SELECT COUNT(*) FROM student_goals WHERE student_id = ? AND category = ? AND status = 'completed'",
                                    [$student['id'], $cat_name]
                                );
                                $meets_criteria = ($count >= $ach['criteria_value']);
                            }
                        }
                        break;
                        
                    case 'total_points':
                        $points = getStat($pdo, "SELECT points FROM users WHERE id = ?", [$student['id']]);
                        $meets_criteria = ($points >= $ach['criteria_value']);
                        break;
                        
                    case 'login_streak':
                        $streak = getStat($pdo, "SELECT current_streak FROM users WHERE id = ?", [$student['id']]);
                        $meets_criteria = ($streak >= $ach['criteria_value']);
                        break;
                        
                    case 'total_goals_created':
                        $count = getStat($pdo,
                            "SELECT COUNT(*) FROM student_goals WHERE student_id = ? AND is_self_created = 1",
                            [$student['id']]
                        );
                        $meets_criteria = ($count >= $ach['criteria_value']);
                        break;
                }
                
                if ($meets_criteria) {
                    // Award achievement
                    $pdo->prepare("
                        INSERT INTO user_achievements (user_id, achievement_id, earned_at) 
                        VALUES (?, ?, NOW())
                    ")->execute([$student['id'], $achievement_id]);
                    
                    // Add points
                    $pdo->prepare("
                        UPDATE users SET points = points + ? WHERE id = ?
                    ")->execute([$ach['points'], $student['id']]);
                    
                    $awarded_count++;
                }
            }
            
            $pdo->commit();
            $success = "Awarded achievement to $awarded_count eligible student(s).";
        }
        
    } catch (Exception $e) {
        if (isset($pdo) && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $error = $e->getMessage();
    }
    
    $_SESSION['success'] = $success;
    $_SESSION['error'] = $error;
    header("Location: achievements.php?" . http_build_query($_GET));
    exit();
}

// === Flash Messages ===
$success = $_SESSION['success'] ?? '';
$error = $_SESSION['error'] ?? '';
unset($_SESSION['success'], $_SESSION['error']);

// === Sidebar Stats ===
$total_students = getStat($pdo, "SELECT COUNT(*) FROM users WHERE role = 'student' AND status = 'active'");
$total_goals = getStat($pdo, "SELECT COUNT(*) FROM admin_goals WHERE status = 'active'");
$total_achievements = getStat($pdo, "SELECT COUNT(*) FROM achievements WHERE deleted_at IS NULL");
$total_unlocked = getStat($pdo, "SELECT COUNT(*) FROM user_achievements WHERE deleted_at IS NULL");

$total_points = getStat($pdo, "
    SELECT COALESCE(SUM(a.points), 0) 
    FROM user_achievements ua 
    JOIN achievements a ON ua.achievement_id = a.id 
    WHERE ua.deleted_at IS NULL
");

$sidebar_stats = [
    'students' => $total_students,
    'goals' => $total_goals,
    'points' => $total_points,
    'achievements' => $total_achievements,
    'unlocked' => $total_unlocked
];

// === Edit Mode ===
$edit_achievement = null;
$edit_mode = false;

if (isset($_GET['edit'])) {
    $edit_mode = true;
    
    if ($_GET['edit'] === 'new') {
        $edit_achievement = [
            'id' => 'new',
            'title' => '',
            'description' => '',
            'points' => 10,
            'criteria_type' => 'total_completed_goals',
            'criteria_value' => 1,
            'category_id' => null,
            'icon' => 'trophy',
            'color' => '#f59e0b',
            'image_path' => '',
            'is_active' => 1
        ];
    } else {
        $id = (int)$_GET['edit'];
        $stmt = $pdo->prepare("
            SELECT a.*, c.name as category_name 
            FROM achievements a 
            LEFT JOIN categories c ON a.category_id = c.id 
            WHERE a.id = ? AND a.deleted_at IS NULL
        ");
        $stmt->execute([$id]);
        $edit_achievement = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$edit_achievement) {
            $_SESSION['error'] = 'Achievement not found.';
            header('Location: achievements.php');
            exit();
        }
        $edit_achievement['id'] = $id; // Ensure ID is set
    }
}

// === Fetch Data for Filters ===
$categories = $pdo->query("
    SELECT id, name, color FROM categories 
    WHERE deleted_at IS NULL 
    ORDER BY name ASC
")->fetchAll(PDO::FETCH_ASSOC);

$students = $pdo->query("
    SELECT id, name, email, profile_picture 
    FROM users 
    WHERE role = 'student' AND status = 'active' 
    ORDER BY name ASC
")->fetchAll(PDO::FETCH_ASSOC);

// === Filters ===
$search = trim($_GET['search'] ?? '');
$status_filter = $_GET['status'] ?? 'all';
$criteria_filter = $_GET['criteria'] ?? 'all';
$category_filter = $_GET['category'] ?? 'all';

$where = ['a.deleted_at IS NULL'];
$params = [];

if ($search) {
    $where[] = "(a.title LIKE ? OR a.description LIKE ?)";
    $like = "%$search%";
    $params[] = $like;
    $params[] = $like;
}

if ($status_filter === 'active') {
    $where[] = "a.is_active = 1";
} elseif ($status_filter === 'inactive') {
    $where[] = "a.is_active = 0";
}

if ($criteria_filter !== 'all' && $criteria_filter) {
    $where[] = "a.criteria_type = ?";
    $params[] = $criteria_filter;
}

if ($category_filter !== 'all' && is_numeric($category_filter)) {
    $where[] = "a.category_id = ?";
    $params[] = (int)$category_filter;
}

$where_clause = $where ? 'WHERE ' . implode(' AND ', $where) : '';

// === Fetch Achievements ===
$achievements_stmt = $pdo->prepare("
    SELECT 
        a.*, 
        c.name as category_name,
        c.color as category_color,
        COUNT(DISTINCT ua.user_id) as unlocked_count,
        GROUP_CONCAT(DISTINCT u.name ORDER BY u.name SEPARATOR ', ') as unlocked_by_names
    FROM achievements a
    LEFT JOIN categories c ON a.category_id = c.id
    LEFT JOIN user_achievements ua ON a.id = ua.achievement_id AND ua.deleted_at IS NULL
    LEFT JOIN users u ON ua.user_id = u.id
    $where_clause
    GROUP BY a.id
    ORDER BY a.created_at DESC
");

$achievements_stmt->execute($params);
$achievements = $achievements_stmt->fetchAll(PDO::FETCH_ASSOC);

// === Top Students Leaderboard ===
$top_students = $pdo->query("
    SELECT 
        u.id,
        u.name, 
        u.profile_picture, 
        u.email,
        COUNT(DISTINCT ua.id) as achievements_count, 
        COALESCE(SUM(a.points), 0) as total_points,
        u.current_streak
    FROM users u
    LEFT JOIN user_achievements ua ON u.id = ua.user_id AND ua.deleted_at IS NULL
    LEFT JOIN achievements a ON ua.achievement_id = a.id AND a.deleted_at IS NULL
    WHERE u.role = 'student' AND u.status = 'active' AND u.deleted_at IS NULL
    GROUP BY u.id
    ORDER BY achievements_count DESC, total_points DESC
    LIMIT 10
")->fetchAll(PDO::FETCH_ASSOC);

// === Criteria Types for Dropdown ===
$criteria_types = [
    '' => 'Manual Award Only',
    'total_completed_goals' => 'Total Completed Goals',
    'completed_goals_in_category' => 'Goals in Specific Category',
    'total_points' => 'Total Points Earned',
    'login_streak' => 'Login Streak Days',
    'total_goals_created' => 'Self-Created Goals',
    'perfect_week' => 'Perfect Week (7-day completion streak)',
    'early_completion' => 'Early Goal Completions',
    'zero_overdue' => 'No Overdue Goals'
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Achievements Management - ProgressMate</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/select2/4.0.13/css/select2.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary: #4f46e5;
            --primary-dark: #4338ca;
            --primary-light: #eef2ff;
            --success: #10b981;
            --success-light: #d1fae5;
            --warning: #f59e0b;
            --warning-light: #fef3c7;
            --danger: #ef4444;
            --danger-light: #fee2e2;
            --info: #3b82f6;
            --info-light: #dbeafe;
            --purple: #8b5cf6;
            --gold: #fbbf24;
            --silver: #9ca3af;
            --bronze: #f97316;
            --gray-100: #f9fafb;
            --gray-200: #f3f4f6;
            --gray-300: #e5e7eb;
            --gray-400: #d1d5db;
            --gray-500: #6b7280;
            --gray-700: #374151;
            --gray-900: #111827;
            --shadow-sm: 0 1px 3px rgba(0,0,0,0.1);
            --shadow: 0 4px 12px rgba(0,0,0,0.08);
            --shadow-lg: 0 10px 25px rgba(0,0,0,0.1);
            --radius: 12px;
            --radius-sm: 8px;
            --transition: all 0.3s ease;
        }

        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Inter', sans-serif; background: var(--gray-100); color: var(--gray-900); line-height: 1.6; }
        a { text-decoration: none; color: inherit; }

        .dashboard-wrapper { display: flex; min-height: 100vh; position: relative; }
        
        .sidebar {
            width: 280px;
            background: white;
            border-right: 1px solid var(--gray-300);
            position: fixed;
            height: 100vh;
            z-index: 1000;
            display: flex;
            flex-direction: column;
            transition: var(--transition);
            box-shadow: var(--shadow);
        }

        .sidebar-header { padding: 24px 20px; border-bottom: 1px solid var(--gray-300); display: flex; align-items: center; justify-content: space-between; }
        .logo { font-size: 20px; font-weight: 700; color: var(--primary); display: flex; align-items: center; gap: 10px; }
        .sidebar-close { display: none; background: none; border: none; font-size: 20px; color: var(--gray-500); cursor: pointer; }

        .user-profile { padding: 24px 20px; border-bottom: 1px solid var(--gray-300); display: flex; align-items: center; gap: 15px; }
        .profile-pic { width: 56px; height: 56px; border-radius: 50%; object-fit: cover; border: 3px solid var(--gray-300); }
        .profile-pic.default { background: linear-gradient(135deg, var(--primary), var(--purple)); color: white; font-size: 24px; font-weight: bold; display: flex; align-items: center; justify-content: center; }

        .nav-menu { flex: 1; padding: 16px 0; overflow-y: auto; }
        .nav-link { display: flex; align-items: center; gap: 12px; padding: 14px 20px; color: var(--gray-700); transition: var(--transition); }
        .nav-link:hover { background: var(--gray-200); color: var(--primary); }
        .nav-link.active { background: var(--primary-light); color: var(--primary); border-left: 4px solid var(--primary); font-weight: 600; }
        .badge { margin-left: auto; background: var(--primary); color: white; padding: 4px 10px; border-radius: 20px; font-size: 12px; font-weight: 600; }

        .sidebar-quick-stats { padding: 20px; border-top: 1px solid var(--gray-300); }
        .sidebar-stat { display: flex; align-items: center; gap: 15px; margin-bottom: 16px; }
        .sidebar-stat-icon { width: 44px; height: 44px; border-radius: 10px; background: var(--primary-light); color: var(--primary); display: flex; align-items: center; justify-content: center; font-size: 20px; }
        .sidebar-stat-label { font-size: 13px; color: var(--gray-500); }
        .sidebar-stat-number { font-size: 18px; font-weight: 700; }

        .sidebar-footer { padding: 20px; }
        .logout-btn { display: flex; align-items: center; gap: 12px; padding: 14px 20px; background: var(--danger-light); color: var(--danger); border-radius: 10px; width: 100%; font-weight: 500; transition: var(--transition); }
        .logout-btn:hover { background: var(--danger); color: white; }

        .main-content { flex: 1; margin-left: 280px; padding: 32px; transition: var(--transition); }

        .page-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 32px; flex-wrap: wrap; gap: 16px; }
        .header-content h1 { font-size: 30px; font-weight: 700; }
        .header-content p { color: var(--gray-500); margin-top: 8px; }

        .btn { padding: 12px 24px; border-radius: 10px; font-weight: 500; cursor: pointer; border: none; transition: var(--transition); display: inline-flex; align-items: center; gap: 8px; font-size: 15px; }
        .btn-primary { background: var(--primary); color: white; }
        .btn-primary:hover { background: var(--primary-dark); transform: translateY(-2px); }
        .btn-success { background: var(--success); color: white; }
        .btn-warning { background: var(--warning); color: white; }
        .btn-danger { background: var(--danger); color: white; }
        .btn-outline { background: transparent; border: 2px solid var(--primary); color: var(--primary); }
        .btn-outline:hover { background: var(--primary); color: white; }
        .btn-sm { padding: 8px 16px; font-size: 14px; }
        .btn-icon { padding: 8px; border-radius: 8px; }

        .alert { padding: 16px 24px; border-radius: var(--radius); margin-bottom: 24px; display: flex; align-items: center; gap: 12px; font-weight: 500; box-shadow: var(--shadow-sm); }
        .alert-success { background: var(--success-light); color: #065f46; border-left: 5px solid var(--success); }
        .alert-error { background: var(--danger-light); color: #991b1b; border-left: 5px solid var(--danger); }

        .stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap: 24px; margin-bottom: 40px; }
        .stat-card { background: white; border-radius: var(--radius); padding: 24px; box-shadow: var(--shadow); position: relative; overflow: hidden; }
        .stat-card::before { content: ''; position: absolute; top: 0; left: 0; width: 5px; height: 100%; }
        .stat-card:nth-child(1)::before { background: var(--gold); }
        .stat-card:nth-child(2)::before { background: var(--success); }
        .stat-card:nth-child(3)::before { background: var(--purple); }
        .stat-card:nth-child(4)::before { background: var(--info); }

        .stat-icon { width: 60px; height: 60px; border-radius: 12px; display: flex; align-items: center; justify-content: center; font-size: 28px; margin-bottom: 16px; }
        .stat-card:nth-child(1) .stat-icon { background: #fffbeb; color: var(--gold); }
        .stat-card:nth-child(2) .stat-icon { background: var(--success-light); color: var(--success); }
        .stat-card:nth-child(3) .stat-icon { background: var(--info-light); color: var(--info); }
        .stat-card:nth-child(4) .stat-icon { background: #e0e7ff; color: var(--purple); }

        .stat-number { font-size: 32px; font-weight: 800; line-height: 1; margin-bottom: 8px; }
        .stat-label { font-size: 14px; color: var(--gray-500); }

        .content-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(350px, 1fr)); gap: 24px; margin-bottom: 40px; }

        .card { background: white; border-radius: var(--radius); box-shadow: var(--shadow); overflow: hidden; }
        .card-header { padding: 24px; border-bottom: 1px solid var(--gray-300); display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 16px; }
        .card-header h3 { font-size: 19px; font-weight: 600; display: flex; align-items: center; gap: 10px; }
        .card-body { padding: 24px; }
        .card-footer { padding: 16px 24px; border-top: 1px solid var(--gray-300); background: var(--gray-100); }

        .achievement-item {
            display: flex;
            align-items: center;
            gap: 20px;
            padding: 20px;
            background: var(--gray-100);
            border-radius: 10px;
            margin-bottom: 12px;
            transition: var(--transition);
            border: 1px solid transparent;
        }
        .achievement-item:hover { 
            background: var(--gray-200); 
            transform: translateY(-2px);
            box-shadow: var(--shadow-sm);
            border-color: var(--gray-300);
        }
        .achievement-item.selected { border-color: var(--primary); background: var(--primary-light); }

        .achievement-checkbox { align-self: flex-start; margin-top: 10px; }

        .badge-preview {
            width: 80px;
            height: 80px;
            border-radius: var(--radius);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 36px;
            color: white;
            box-shadow: var(--shadow);
            flex-shrink: 0;
        }

        .achievement-info { flex: 1; min-width: 0; }
        .achievement-title { font-size: 18px; font-weight: 600; margin-bottom: 6px; display: flex; align-items: center; gap: 8px; }
        .achievement-description { color: var(--gray-600); font-size: 14px; margin-bottom: 12px; }
        .achievement-stats { display: flex; align-items: center; gap: 16px; margin-top: 12px; font-size: 14px; }
        .achievement-points { color: var(--warning); font-weight: 700; }
        .achievement-unlocked { color: var(--primary); font-weight: 700; }

        .status-badge {
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
        }
        .status-active { background: var(--success-light); color: var(--success); }
        .status-inactive { background: var(--gray-300); color: var(--gray-700); }

        .action-buttons { display: flex; gap: 8px; flex-wrap: wrap; }

        /* Form Styles */
        .form-row { display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 20px; margin-bottom: 20px; }
        .form-group { display: flex; flex-direction: column; }
        .form-group label { margin-bottom: 8px; font-weight: 500; color: var(--gray-700); }
        .form-group label.required::after { content: " *"; color: var(--danger); }
        .form-group input, .form-group select, .form-group textarea {
            padding: 12px;
            border: 1px solid var(--gray-300);
            border-radius: 8px;
            font-size: 15px;
            transition: var(--transition);
        }
        .form-group input:focus, .form-group select:focus, .form-group textarea:focus {
            border-color: var(--primary);
            box-shadow: 0 0 0 3px var(--primary-light);
            outline: none;
        }
        .form-group textarea { min-height: 100px; resize: vertical; }
        .form-help { font-size: 13px; color: var(--gray-500); margin-top: 6px; }

        .filters { display: flex; gap: 12px; flex-wrap: wrap; margin-bottom: 24px; align-items: flex-end; }
        .filter-group { display: flex; flex-direction: column; min-width: 180px; }
        .filter-group label { font-size: 13px; font-weight: 600; margin-bottom: 6px; color: var(--gray-600); }

        .bulk-actions { display: flex; gap: 12px; align-items: center; flex-wrap: wrap; padding: 16px; background: var(--gray-100); border-radius: var(--radius); margin-bottom: 20px; }

        .empty-state { text-align: center; padding: 60px 20px; color: var(--gray-500); }
        .empty-state i { font-size: 48px; margin-bottom: 16px; opacity: 0.5; }

        /* Tabs */
        .tabs { display: flex; gap: 4px; border-bottom: 1px solid var(--gray-300); margin-bottom: 32px; }
        .tab { padding: 16px 24px; background: none; border: none; color: var(--gray-500); font-weight: 600; cursor: pointer; border-bottom: 3px solid transparent; transition: var(--transition); position: relative; }
        .tab:hover { color: var(--primary); }
        .tab.active { color: var(--primary); border-bottom-color: var(--primary); }
        .tab-badge { position: absolute; top: 8px; right: 8px; background: var(--primary); color: white; font-size: 10px; padding: 2px 6px; border-radius: 10px; }

        /* Modal */
        .modal-overlay {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0,0,0,0.5);
            z-index: 2000;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }
        .modal {
            background: white;
            border-radius: var(--radius);
            width: 100%;
            max-width: 500px;
            max-height: 90vh;
            overflow-y: auto;
            box-shadow: var(--shadow-lg);
            animation: modalSlideIn 0.3s ease;
        }
        @keyframes modalSlideIn {
            from { opacity: 0; transform: translateY(-20px); }
            to { opacity: 1; transform: translateY(0); }
        }
        .modal-header { padding: 20px; border-bottom: 1px solid var(--gray-300); display: flex; justify-content: space-between; align-items: center; }
        .modal-header h3 { margin: 0; font-size: 18px; font-weight: 600; }
        .modal-close { background: none; border: none; color: var(--gray-500); cursor: pointer; font-size: 24px; padding: 4px; }
        .modal-body { padding: 20px; }

        /* Student list */
        .student-list { max-height: 300px; overflow-y: auto; border: 1px solid var(--gray-300); border-radius: var(--radius); }
        .student-item { display: flex; align-items: center; gap: 12px; padding: 12px; border-bottom: 1px solid var(--gray-200); }
        .student-item:last-child { border-bottom: none; }
        .student-item:hover { background: var(--gray-100); }
        .student-avatar { width: 40px; height: 40px; border-radius: 50%; object-fit: cover; }
        .student-avatar.default { background: var(--primary); color: white; display: flex; align-items: center; justify-content: center; font-weight: bold; }

        /* Mobile responsive */
        .mobile-toggle { display: none; position: fixed; top: 20px; left: 20px; z-index: 1100; background: var(--primary); color: white; border: none; width: 48px; height: 48px; border-radius: 12px; font-size: 20px; cursor: pointer; box-shadow: var(--shadow); }
        .sidebar-overlay { display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.5); z-index: 999; }

        @media (max-width: 1024px) { 
            .content-grid { grid-template-columns: 1fr; }
            .stats-grid { grid-template-columns: repeat(2, 1fr); }
        }
        @media (max-width: 768px) {
            .sidebar { transform: translateX(-100%); width: 300px; }
            .sidebar.active { transform: translateX(0); }
            .sidebar-overlay.active { display: block; }
            .sidebar-close { display: block; }
            .mobile-toggle { display: flex; align-items: center; justify-content: center; }
            .main-content { margin-left: 0; padding: 24px 16px; padding-top: 80px; }
            .page-header { flex-direction: column; align-items: flex-start; }
            .stats-grid { grid-template-columns: 1fr; }
            .achievement-item { flex-direction: column; align-items: flex-start; gap: 16px; }
            .form-row { grid-template-columns: 1fr; }
            .filters { flex-direction: column; align-items: stretch; }
            .filter-group { min-width: auto; }
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
                    <div class="profile-pic default"><?php echo htmlspecialchars(strtoupper(substr($_SESSION['name'] ?? 'A', 0, 1))); ?></div>
                <?php endif; ?>
                <div class="user-info">
                    <h4><?php echo htmlspecialchars($_SESSION['name'] ?? 'Admin'); ?></h4>
                    <p><?php echo htmlspecialchars($_SESSION['email'] ?? ''); ?></p>
                    <span style="font-size: 12px; background: var(--primary-light); color: var(--primary); padding: 4px 10px; border-radius: 20px; font-weight: 600;">ADMIN</span>
                </div>
            </div>

            <nav class="nav-menu">
                <a href="admin.php" class="nav-link"><i class="fas fa-tachometer-alt"></i> Dashboard</a>
                <a href="students.php" class="nav-link"><i class="fas fa-users"></i> Students <span class="badge"><?php echo $sidebar_stats['students']; ?></span></a>
                <a href="goals.php" class="nav-link"><i class="fas fa-bullseye"></i> System Goals <span class="badge"><?php echo $sidebar_stats['goals']; ?></span></a>
                <a href="assign_goals.php" class="nav-link"><i class="fas fa-tasks"></i> Assign Goals</a>
                <a href="achievements.php" class="nav-link active"><i class="fas fa-trophy"></i> Achievements <span class="badge"><?php echo $sidebar_stats['unlocked']; ?></span></a>
                <a href="reports.php" class="nav-link"><i class="fas fa-chart-bar"></i> Reports</a>
                <a href="categories.php" class="nav-link"><i class="fas fa-tags"></i> Categories</a>
                <a href="settings.php" class="nav-link"><i class="fas fa-cog"></i> Settings</a>
            </nav>

            <div class="sidebar-quick-stats">
                <div class="sidebar-stat">
                    <div class="sidebar-stat-icon"><i class="fas fa-trophy"></i></div>
                    <div><div class="sidebar-stat-label">Total Achievements</div><div class="sidebar-stat-number"><?php echo $sidebar_stats['achievements']; ?></div></div>
                </div>
                <div class="sidebar-stat">
                    <div class="sidebar-stat-icon"><i class="fas fa-unlock"></i></div>
                    <div><div class="sidebar-stat-label">Unlocked</div><div class="sidebar-stat-number"><?php echo $sidebar_stats['unlocked']; ?></div></div>
                </div>
                <div class="sidebar-stat">
                    <div class="sidebar-stat-icon"><i class="fas fa-star"></i></div>
                    <div><div class="sidebar-stat-label">Points Distributed</div><div class="sidebar-stat-number"><?php echo $sidebar_stats['points']; ?></div></div>
                </div>
            </div>

            <div class="sidebar-footer">
                <a href="../logout.php" class="logout-btn"><i class="fas fa-sign-out-alt"></i> Logout</a>
            </div>
        </aside>

        <main class="main-content">
            <header class="page-header">
                <div class="header-content">
                    <h1>Achievements Management</h1>
                    <p>Define and award achievements to students based on their goal achievements</p>
                </div>
                <div style="display: flex; gap: 12px; flex-wrap: wrap;">
                    <a href="?edit=new" class="btn btn-primary"><i class="fas fa-plus"></i> Add Achievement</a>
                    <form method="POST" style="display:inline;">
                        <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                        <input type="hidden" name="action" value="recalculate_all">
                        <button type="submit" class="btn btn-outline" onclick="return confirm('Recalculate achievements for all students? This may take time.')">
                            <i class="fas fa-sync-alt"></i> Recalculate All
                        </button>
                    </form>
                </div>
            </header>

            <?php if ($success): ?>
                <div class="alert alert-success"><i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($success); ?></div>
            <?php endif; ?>
            <?php if ($error): ?>
                <div class="alert alert-error"><i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($error); ?></div>
            <?php endif; ?>

            <!-- Quick Stats -->
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-icon"><i class="fas fa-trophy"></i></div>
                    <div class="stat-number"><?php echo $total_achievements; ?></div>
                    <div class="stat-label">Total Achievements</div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon"><i class="fas fa-unlock"></i></div>
                    <div class="stat-number"><?php echo $total_unlocked; ?></div>
                    <div class="stat-label">Total Unlocks</div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon"><i class="fas fa-star"></i></div>
                    <div class="stat-number"><?php echo $total_points; ?></div>
                    <div class="stat-label">Points Distributed</div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon"><i class="fas fa-users"></i></div>
                    <div class="stat-number"><?php echo $total_students; ?></div>
                    <div class="stat-label">Active Students</div>
                </div>
            </div>

            <!-- Tabs -->
            <div class="tabs">
                <button class="tab active" data-tab="list"><i class="fas fa-list"></i> All Achievements</button>
                <button class="tab" data-tab="top"><i class="fas fa-medal"></i> Top Students</button>
                <?php if ($edit_mode): ?>
                    <button class="tab" data-tab="edit"><i class="fas fa-edit"></i> 
                        <?php echo $edit_achievement['id'] === 'new' ? 'Add Achievement' : 'Edit Achievement'; ?>
                    </button>
                <?php endif; ?>
            </div>

            <!-- Tab 1: All Achievements -->
            <div class="tab-content active" id="tab-list">
                <!-- Filters -->
                <form method="GET" class="filters">
                    <div class="filter-group">
                        <label>Search</label>
                        <input type="text" name="search" value="<?php echo htmlspecialchars($search); ?>" placeholder="Title, description...">
                    </div>
                    <div class="filter-group">
                        <label>Status</label>
                        <select name="status">
                            <option value="all" <?php echo $status_filter === 'all' ? 'selected' : ''; ?>>All Status</option>
                            <option value="active" <?php echo $status_filter === 'active' ? 'selected' : ''; ?>>Active Only</option>
                            <option value="inactive" <?php echo $status_filter === 'inactive' ? 'selected' : ''; ?>>Inactive Only</option>
                        </select>
                    </div>
                    <div class="filter-group">
                        <label>Criteria Type</label>
                        <select name="criteria">
                            <option value="all" <?php echo $criteria_filter === 'all' ? 'selected' : ''; ?>>All Criteria</option>
                            <?php foreach ($criteria_types as $key => $label): ?>
                                <?php if ($key): ?>
                                    <option value="<?php echo $key; ?>" <?php echo $criteria_filter === $key ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($label); ?>
                                    </option>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="filter-group">
                        <label>Category</label>
                        <select name="category">
                            <option value="all" <?php echo $category_filter === 'all' ? 'selected' : ''; ?>>All Categories</option>
                            <?php foreach ($categories as $cat): ?>
                                <option value="<?php echo $cat['id']; ?>" <?php echo $category_filter == $cat['id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($cat['name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <button type="submit" class="btn btn-primary"><i class="fas fa-search"></i> Filter</button>
                    <a href="achievements.php" class="btn btn-outline">Clear</a>
                </form>

                <!-- Bulk Actions -->
                <form method="POST" id="bulkForm">
                    <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                    <div class="bulk-actions">
                        <select name="bulk_action" required style="min-width: 150px;">
                            <option value="">Bulk Actions</option>
                            <option value="activate">Activate Selected</option>
                            <option value="deactivate">Deactivate Selected</option>
                            <option value="delete">Delete Selected</option>
                        </select>
                        <button type="submit" name="action" value="bulk_action" class="btn btn-primary" onclick="return confirm('Apply to selected achievements?')">
                            <i class="fas fa-check"></i> Apply
                        </button>
                        <span style="font-size: 14px; color: var(--gray-500); margin-left: auto;">
                            <?php echo count($achievements); ?> achievement(s) found
                        </span>
                    </div>

                    <?php if (empty($achievements)): ?>
                        <div class="empty-state">
                            <i class="fas fa-trophy"></i>
                            <p>No achievements found</p>
                            <a href="?edit=new" class="btn btn-primary">Create First Achievement</a>
                        </div>
                    <?php else: ?>
                        <?php foreach ($achievements as $achievement): ?>
                            <div class="achievement-item">
                                <input type="checkbox" name="achievement_ids[]" value="<?php echo $achievement['id']; ?>" 
                                       class="achievement-checkbox" onchange="toggleAchievementSelection(this)">
                                
                                <div class="badge-preview" style="background: <?php echo htmlspecialchars($achievement['color']); ?>;">
                                    <i class="fas fa-<?php echo htmlspecialchars($achievement['icon']); ?>"></i>
                                </div>
                                
                                <div class="achievement-info">
                                    <div class="achievement-title">
                                        <?php echo htmlspecialchars($achievement['title']); ?>
                                        <?php if ($achievement['category_name']): ?>
                                            <span style="font-size: 12px; background: <?php echo htmlspecialchars($achievement['category_color'] ?? '#e5e7eb'); ?>; color: white; padding: 2px 8px; border-radius: 12px;">
                                                <?php echo htmlspecialchars($achievement['category_name']); ?>
                                            </span>
                                        <?php endif; ?>
                                    </div>
                                    
                                    <div class="achievement-description">
                                        <?php echo htmlspecialchars($achievement['description']); ?>
                                    </div>
                                    
                                    <div class="achievement-stats">
                                        <span class="achievement-points">
                                            <i class="fas fa-star"></i> <?php echo $achievement['points']; ?> points
                                        </span>
                                        <span class="achievement-unlocked">
                                            <i class="fas fa-users"></i> <?php echo $achievement['unlocked_count']; ?> unlocked
                                        </span>
                                        <span class="status-badge status-<?php echo $achievement['is_active'] ? 'active' : 'inactive'; ?>">
                                            <?php echo $achievement['is_active'] ? 'Active' : 'Inactive'; ?>
                                        </span>
                                        <?php if ($achievement['criteria_type']): ?>
                                            <span style="font-size: 12px; color: var(--gray-500);">
                                                <i class="fas fa-bullseye"></i> 
                                                <?php echo htmlspecialchars($criteria_types[$achievement['criteria_type']] ?? $achievement['criteria_type']); ?>
                                                <?php if ($achievement['criteria_value']): ?>
                                                    (<?php echo $achievement['criteria_value']; ?>)
                                                <?php endif; ?>
                                            </span>
                                        <?php endif; ?>
                                    </div>
                                    
                                    <?php if ($achievement['unlocked_by_names']): ?>
                                        <div style="font-size: 12px; color: var(--gray-500); margin-top: 8px;">
                                            <i class="fas fa-user-check"></i> 
                                            Unlocked by: <?php echo htmlspecialchars(substr($achievement['unlocked_by_names'], 0, 100)); ?>
                                            <?php if (strlen($achievement['unlocked_by_names']) > 100): ?>...<?php endif; ?>
                                        </div>
                                    <?php endif; ?>
                                </div>
                                
                                <div class="action-buttons">
                                    <a href="?edit=<?php echo $achievement['id']; ?>" class="btn btn-sm btn-outline" title="Edit">
                                        <i class="fas fa-edit"></i>
                                    </a>
                                    
                                    <!-- Award Modal Trigger -->
                                    <button type="button" class="btn btn-sm btn-success" onclick="openAwardModal(<?php echo $achievement['id']; ?>, '<?php echo addslashes($achievement['title']); ?>')" title="Award to Student">
                                        <i class="fas fa-award"></i>
                                    </button>
                                    
                                    <button type="button" class="btn btn-sm btn-warning" onclick="openAwardAllModal(<?php echo $achievement['id']; ?>, '<?php echo addslashes($achievement['title']); ?>')" title="Award to All Eligible">
                                        <i class="fas fa-users"></i>
                                    </button>
                                    
                                    <form method="POST" style="display:inline;">
                                        <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                                        <input type="hidden" name="action" value="delete_achievement">
                                        <input type="hidden" name="achievement_id" value="<?php echo $achievement['id']; ?>">
                                        <button type="submit" class="btn btn-sm btn-danger" onclick="return confirm('Delete this achievement? All student unlocks will be removed.')" title="Delete">
                                            <i class="fas fa-trash"></i>
                                        </button>
                                    </form>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </form>
            </div>

            <!-- Tab 2: Top Students -->
            <div class="tab-content" id="tab-top">
                <div class="card">
                    <div class="card-header">
                        <h3><i class="fas fa-medal"></i> Top Achievement Earners</h3>
                    </div>
                    <div class="card-body">
                        <?php if (empty($top_students)): ?>
                            <div class="empty-state">
                                <i class="fas fa-users"></i>
                                <p>No students have earned achievements yet</p>
                            </div>
                        <?php else: ?>
                            <?php foreach ($top_students as $index => $student): 
                                $medal_class = '';
                                if ($index === 0) {
                                    $medal_class = 'gold';
                                    $medal_icon = '🥇';
                                } elseif ($index === 1) {
                                    $medal_class = 'silver';
                                    $medal_icon = '🥈';
                                } elseif ($index === 2) {
                                    $medal_class = 'bronze';
                                    $medal_icon = '🥉';
                                } else {
                                    $medal_icon = ($index + 1) . '.';
                                }
                            ?>
                                <div class="achievement-item">
                                    <div style="font-size: 24px; width: 40px; text-align: center;">
                                        <?php echo $medal_icon; ?>
                                    </div>
                                    
                                    <?php if (!empty($student['profile_picture'])): ?>
                                        <img src="../<?php echo htmlspecialchars($student['profile_picture']); ?>" alt="" class="profile-pic" style="width: 56px; height: 56px;">
                                    <?php else: ?>
                                        <div class="profile-pic default" style="width: 56px; height: 56px;">
                                            <?php echo htmlspecialchars(strtoupper(substr($student['name'], 0, 1))); ?>
                                        </div>
                                    <?php endif; ?>
                                    
                                    <div class="achievement-info">
                                        <div style="font-weight: 600; font-size: 16px;">
                                            <?php echo htmlspecialchars($student['name']); ?>
                                        </div>
                                        <div style="color: var(--gray-500); font-size: 14px;">
                                            <?php echo htmlspecialchars($student['email']); ?>
                                        </div>
                                        <div class="achievement-stats">
                                            <span><strong><?php echo $student['achievements_count']; ?></strong> achievements</span>
                                            <span><strong><?php echo $student['total_points']; ?></strong> points</span>
                                            <span><strong><?php echo $student['current_streak']; ?></strong> day streak</span>
                                        </div>
                                    </div>
                                    
                                    <div class="action-buttons">
                                        <a href="students.php?view=<?php echo $student['id']; ?>" class="btn btn-sm btn-outline">
                                            <i class="fas fa-eye"></i> View
                                        </a>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Tab 3: Edit/Add Achievement -->
            <?php if ($edit_mode): ?>
                <div class="tab-content" id="tab-edit">
                    <div class="card">
                        <div class="card-header">
                            <h3>
                                <i class="fas fa-<?php echo $edit_achievement['id'] === 'new' ? 'plus' : 'edit'; ?>"></i>
                                <?php echo $edit_achievement['id'] === 'new' ? 'Add New Achievement' : 'Edit Achievement'; ?>
                            </h3>
                        </div>
                        <div class="card-body">
                            <form method="POST" id="achievementForm">
                                <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                                <input type="hidden" name="action" value="<?php echo $edit_achievement['id'] === 'new' ? 'add_achievement' : 'edit_achievement'; ?>">
                                
                                <?php if ($edit_achievement['id'] !== 'new'): ?>
                                    <input type="hidden" name="achievement_id" value="<?php echo $edit_achievement['id']; ?>">
                                <?php endif; ?>

                                <div class="form-row">
                                    <div class="form-group">
                                        <label class="required">Title</label>
                                        <input type="text" name="title" value="<?php echo htmlspecialchars($edit_achievement['title']); ?>" required 
                                               placeholder="e.g., Goal Master" maxlength="100">
                                    </div>
                                    <div class="form-group">
                                        <label class="required">Points</label>
                                        <input type="number" name="points" min="1" max="1000" value="<?php echo $edit_achievement['points']; ?>" required>
                                    </div>
                                </div>

                                <div class="form-group">
                                    <label>Description</label>
                                    <textarea name="description" rows="3" placeholder="Describe what this achievement represents..."><?php echo htmlspecialchars($edit_achievement['description']); ?></textarea>
                                </div>

                                <div class="form-row">
                                    <div class="form-group">
                                        <label>Criteria Type</label>
                                        <select name="criteria_type" id="criteria_type" onchange="toggleCriteriaFields()">
                                            <?php foreach ($criteria_types as $key => $label): ?>
                                                <option value="<?php echo $key; ?>" <?php echo $edit_achievement['criteria_type'] === $key ? 'selected' : ''; ?>>
                                                    <?php echo htmlspecialchars($label); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                        <div class="form-help">How students earn this achievement</div>
                                    </div>
                                    <div class="form-group" id="criteria_value_group">
                                        <label>Criteria Value</label>
                                        <input type="number" name="criteria_value" min="1" value="<?php echo $edit_achievement['criteria_value']; ?>">
                                        <div class="form-help" id="criteria_help">e.g., number of goals, points required, etc.</div>
                                    </div>
                                </div>

                                <div class="form-row">
                                    <div class="form-group" id="category_group">
                                        <label>Category</label>
                                        <select name="category_id">
                                            <option value="">No Category</option>
                                            <?php foreach ($categories as $cat): ?>
                                                <option value="<?php echo $cat['id']; ?>" 
                                                        <?php echo $edit_achievement['category_id'] == $cat['id'] ? 'selected' : ''; ?>
                                                        data-color="<?php echo htmlspecialchars($cat['color']); ?>">
                                                    <?php echo htmlspecialchars($cat['name']); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                        <div class="form-help">Required for "Goals in Category" criteria</div>
                                    </div>
                                    <div class="form-group">
                                        <label>Icon</label>
                                        <select name="icon" id="icon_select" onchange="updateIconPreview()">
                                            <option value="trophy" <?php echo $edit_achievement['icon'] === 'trophy' ? 'selected' : ''; ?>>🏆 Trophy</option>
                                            <option value="medal" <?php echo $edit_achievement['icon'] === 'medal' ? 'selected' : ''; ?>>🥇 Medal</option>
                                            <option value="star" <?php echo $edit_achievement['icon'] === 'star' ? 'selected' : ''; ?>>⭐ Star</option>
                                            <option value="award" <?php echo $edit_achievement['icon'] === 'award' ? 'selected' : ''; ?>>🏅 Award</option>
                                            <option value="certificate" <?php echo $edit_achievement['icon'] === 'certificate' ? 'selected' : ''; ?>>📜 Certificate</option>
                                            <option value="gem" <?php echo $edit_achievement['icon'] === 'gem' ? 'selected' : ''; ?>>💎 Gem</option>
                                            <option value="crown" <?php echo $edit_achievement['icon'] === 'crown' ? 'selected' : ''; ?>>👑 Crown</option>
                                            <option value="fire" <?php echo $edit_achievement['icon'] === 'fire' ? 'selected' : ''; ?>>🔥 Fire</option>
                                            <option value="rocket" <?php echo $edit_achievement['icon'] === 'rocket' ? 'selected' : ''; ?>>🚀 Rocket</option>
                                        </select>
                                    </div>
                                </div>

                                <div class="form-row">
                                    <div class="form-group">
                                        <label>Color</label>
                                        <div style="display: flex; gap: 10px; align-items: center;">
                                            <input type="color" name="color" id="color_picker" value="<?php echo htmlspecialchars($edit_achievement['color']); ?>" style="width: 60px; height: 40px;">
                                            <input type="text" id="color_text" value="<?php echo htmlspecialchars($edit_achievement['color']); ?>" style="flex: 1;" onchange="document.getElementById('color_picker').value = this.value">
                                        </div>
                                    </div>
                                    <div class="form-group">
                                        <label>Image Path (Optional)</label>
                                        <input type="text" name="image_path" value="<?php echo htmlspecialchars($edit_achievement['image_path']); ?>" 
                                               placeholder="/path/to/image.png">
                                    </div>
                                </div>

                                <div class="form-group" style="margin-top: 20px;">
                                    <label style="display: flex; align-items: center; gap: 10px;">
                                        <input type="checkbox" name="is_active" <?php echo $edit_achievement['is_active'] ? 'checked' : ''; ?>>
                                        <span>Active (students can earn this achievement)</span>
                                    </label>
                                </div>

                                <!-- Preview -->
                                <div style="background: var(--gray-100); padding: 20px; border-radius: var(--radius); margin: 20px 0; text-align: center;">
                                    <h4 style="margin-bottom: 15px;"><i class="fas fa-eye"></i> Preview</h4>
                                    <div id="achievement_preview" style="display: inline-block; text-align: center;">
                                        <div id="preview_badge" class="badge-preview" style="margin: 0 auto 15px;">
                                            <i id="preview_icon" class="fas fa-<?php echo $edit_achievement['icon']; ?>"></i>
                                        </div>
                                        <div id="preview_title" style="font-weight: 600; font-size: 18px;"><?php echo htmlspecialchars($edit_achievement['title']); ?></div>
                                        <div id="preview_points" style="color: var(--warning); font-weight: 600; margin-top: 5px;">
                                            <?php echo $edit_achievement['points']; ?> points
                                        </div>
                                    </div>
                                </div>

                                <div style="display: flex; gap: 12px; margin-top: 24px;">
                                    <button type="submit" class="btn btn-primary">
                                        <i class="fas fa-save"></i> 
                                        <?php echo $edit_achievement['id'] === 'new' ? 'Create Achievement' : 'Update Achievement'; ?>
                                    </button>
                                    <a href="achievements.php" class="btn btn-outline">Cancel</a>
                                    <?php if ($edit_achievement['id'] !== 'new'): ?>
                                        <button type="button" class="btn btn-warning" onclick="testAchievement(<?php echo $edit_achievement['id']; ?>)">
                                            <i class="fas fa-vial"></i> Test Criteria
                                        </button>
                                    <?php endif; ?>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
            <?php endif; ?>
        </main>
    </div>

    <!-- Award to Student Modal -->
    <div class="modal-overlay" id="awardModal">
        <div class="modal">
            <div class="modal-header">
                <h3>Award Achievement to Student</h3>
                <button class="modal-close" onclick="closeAwardModal()">×</button>
            </div>
            <div class="modal-body">
                <form method="POST" id="awardForm">
                    <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                    <input type="hidden" name="action" value="award_to_student">
                    <input type="hidden" name="achievement_id" id="award_achievement_id">
                    
                    <div class="form-group">
                        <label>Achievement: <span id="award_achievement_title" style="font-weight: 600;"></span></label>
                    </div>
                    
                    <div class="form-group">
                        <label>Select Student</label>
                        <select name="student_id" required style="width: 100%;">
                            <option value="">-- Select Student --</option>
                            <?php foreach ($students as $student): ?>
                                <option value="<?php echo $student['id']; ?>">
                                    <?php echo htmlspecialchars($student['name']); ?> 
                                    (<?php echo htmlspecialchars($student['email']); ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label>Notes (Optional)</label>
                        <textarea name="notes" rows="3" placeholder="Reason for awarding..."></textarea>
                    </div>
                    
                    <button type="submit" class="btn btn-primary">Award Achievement</button>
                    <button type="button" class="btn btn-outline" onclick="closeAwardModal()">Cancel</button>
                </form>
            </div>
        </div>
    </div>

    <!-- Award to All Eligible Modal -->
    <div class="modal-overlay" id="awardAllModal">
        <div class="modal">
            <div class="modal-header">
                <h3>Award Achievement to All Eligible Students</h3>
                <button class="modal-close" onclick="closeAwardAllModal()">×</button>
            </div>
            <div class="modal-body">
                <form method="POST" id="awardAllForm">
                    <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                    <input type="hidden" name="action" value="award_to_all_eligible">
                    <input type="hidden" name="achievement_id" id="awardAll_achievement_id">
                    
                    <div class="form-group">
                        <label>Achievement: <span id="awardAll_achievement_title" style="font-weight: 600;"></span></label>
                    </div>
                    
                    <div class="alert alert-warning" style="margin-bottom: 20px;">
                        <i class="fas fa-exclamation-triangle"></i>
                        This will check all active students and award this achievement to those who meet the criteria.
                        Students who already have this achievement will be skipped.
                    </div>
                    
                    <div class="form-group">
                        <label>Force Award (Ignore Criteria)</label>
                        <label style="display: flex; align-items: center; gap: 10px; margin-top: 10px;">
                            <input type="checkbox" name="force_award" id="force_award">
                            <span>Award to all active students regardless of criteria</span>
                        </label>
                        <div class="form-help" style="color: var(--danger);">
                            Warning: This will award the achievement to ALL active students, even if they haven't met the criteria.
                        </div>
                    </div>
                    
                    <button type="submit" class="btn btn-primary" onclick="return confirm('Are you sure? This may take a moment.')">
                        <i class="fas fa-users"></i> Award to Eligible Students
                    </button>
                    <button type="button" class="btn btn-outline" onclick="closeAwardAllModal()">Cancel</button>
                </form>
            </div>
        </div>
    </div>

    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/select2/4.0.13/js/select2.min.js"></script>
    <script>
        // Initialize Select2
        $(document).ready(function() {
            $('select[name="student_id"]').select2({
                placeholder: "Select a student...",
                width: '100%'
            });
        });

        // Tab functionality
        document.querySelectorAll('.tab').forEach(tab => {
            tab.addEventListener('click', () => {
                // Remove active class from all tabs
                document.querySelectorAll('.tab').forEach(t => t.classList.remove('active'));
                document.querySelectorAll('.tab-content').forEach(c => c.classList.remove('active'));
                
                // Add active class to clicked tab
                tab.classList.add('active');
                
                // Show corresponding content
                const tabId = tab.dataset.tab;
                if (tabId === 'edit') {
                    document.getElementById('tab-edit').classList.add('active');
                } else {
                    document.getElementById('tab-' + tabId).classList.add('active');
                }
            });
        });

        // Achievement preview update
        function updateIconPreview() {
            const icon = document.getElementById('icon_select').value;
            const color = document.getElementById('color_picker').value;
            
            document.getElementById('preview_icon').className = 'fas fa-' + icon;
            document.getElementById('preview_badge').style.background = color;
        }

        // Color picker sync
        document.getElementById('color_picker').addEventListener('input', function() {
            document.getElementById('color_text').value = this.value;
            updateIconPreview();
        });

        document.getElementById('color_text').addEventListener('input', function() {
            document.getElementById('color_picker').value = this.value;
            updateIconPreview();
        });

        // Update preview when title/points change
        document.querySelectorAll('input[name="title"], input[name="points"]').forEach(input => {
            input.addEventListener('input', function() {
                if (this.name === 'title') {
                    document.getElementById('preview_title').textContent = this.value || 'Achievement Title';
                } else if (this.name === 'points') {
                    document.getElementById('preview_points').textContent = (this.value || '0') + ' points';
                }
            });
        });

        // Toggle criteria fields based on type
        function toggleCriteriaFields() {
            const criteriaType = document.getElementById('criteria_type').value;
            const criteriaValueGroup = document.getElementById('criteria_value_group');
            const categoryGroup = document.getElementById('category_group');
            const criteriaHelp = document.getElementById('criteria_help');
            
            // Show/hide criteria value
            if (!criteriaType) {
                criteriaValueGroup.style.display = 'none';
                categoryGroup.style.display = 'none';
                criteriaHelp.textContent = 'No criteria required for manual awards';
            } else {
                criteriaValueGroup.style.display = 'flex';
                criteriaHelp.textContent = 'Number required to earn achievement';
                
                // Show/hide category field for category-specific achievements
                if (criteriaType === 'completed_goals_in_category') {
                    categoryGroup.style.display = 'flex';
                } else {
                    categoryGroup.style.display = 'none';
                }
            }
        }

        // Initialize criteria fields
        toggleCriteriaFields();

        // Test achievement criteria
        function testAchievement(achievementId) {
            window.open(`test_achievement.php?id=${achievementId}`, '_blank', 'width=800,height=600');
        }

        // Award modal functions
        function openAwardModal(achievementId, achievementTitle) {
            document.getElementById('award_achievement_id').value = achievementId;
            document.getElementById('award_achievement_title').textContent = achievementTitle;
            document.getElementById('awardModal').style.display = 'flex';
        }

        function closeAwardModal() {
            document.getElementById('awardModal').style.display = 'none';
        }

        // Award to all modal functions
        function openAwardAllModal(achievementId, achievementTitle) {
            document.getElementById('awardAll_achievement_id').value = achievementId;
            document.getElementById('awardAll_achievement_title').textContent = achievementTitle;
            document.getElementById('awardAllModal').style.display = 'flex';
        }

        function closeAwardAllModal() {
            document.getElementById('awardAllModal').style.display = 'none';
        }

        // Toggle achievement selection
        function toggleAchievementSelection(checkbox) {
            const item = checkbox.closest('.achievement-item');
            if (checkbox.checked) {
                item.classList.add('selected');
            } else {
                item.classList.remove('selected');
            }
        }

        // Select all checkboxes
        function selectAllAchievements(selectAll) {
            document.querySelectorAll('.achievement-checkbox').forEach(checkbox => {
                checkbox.checked = selectAll.checked;
                toggleAchievementSelection(checkbox);
            });
        }

        // Mobile sidebar
        const sidebar = document.getElementById('sidebar');
        const sidebarToggle = document.getElementById('sidebarToggle');
        const sidebarClose = document.getElementById('sidebarClose');
        const overlay = document.getElementById('sidebarOverlay');

        function openSidebar() { 
            sidebar.classList.add('active'); 
            overlay.classList.add('active'); 
        }
        function closeSidebar() { 
            sidebar.classList.remove('active'); 
            overlay.classList.remove('active'); 
        }

        sidebarToggle?.addEventListener('click', openSidebar);
        sidebarClose?.addEventListener('click', closeSidebar);
        overlay?.addEventListener('click', closeSidebar);

        // Initialize icon preview
        updateIconPreview();
    </script>
</body>
</html>