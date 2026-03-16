<?php

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once '../includes/db_connection.php';
require_once '../includes/functions.php';

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    header('Location: ../login.php');
    exit();
}


if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];
$current = basename($_SERVER['PHP_SELF']); 

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($csrf_token, $_POST['csrf_token'] ?? '')) {
        die('CSRF validation failed.');
    }
    $success = $error = '';

    try {
        $action = $_POST['action'] ?? '';


        if ($action === 'add_achievement' || $action === 'edit_achievement') {
            $title = trim($_POST['title'] ?? '');
            $description = trim($_POST['description'] ?? '');
            $points = max(1, (int)($_POST['points'] ?? 10));
            $criteria_type = trim($_POST['criteria_type'] ?? '');
            $criteria_value = (int)($_POST['criteria_value'] ?? 0);
            $category_id = !empty($_POST['category_id']) ? (int)$_POST['category_id'] : null;

            $allowed_icons = ['trophy', 'medal', 'star', 'award', 'certificate', 'gem', 'crown', 'fire', 'bullseye', 'rocket', 'lightning', 'shield', 'book', 'graduation-cap', 'brain', 'heart', 'flag', 'moon', 'sun', 'cloud'];
            $icon = in_array($_POST['icon'] ?? '', $allowed_icons) ? $_POST['icon'] : 'trophy';

            $color = preg_match('/^#[a-f0-9]{6}$/i', $_POST['color'] ?? '') ? strtolower($_POST['color']) : '#f59e0b';
            $is_active = isset($_POST['is_active']) ? 1 : 0;
            $image_path = trim($_POST['image_path'] ?? '');

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
                $success = "Achievement created successfully!";
            } else {
                $id = (int)($_POST['achievement_id'] ?? 0);
                if ($id <= 0) {
                    throw new Exception("Invalid achievement ID.");
                }

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

        } elseif ($action === 'delete_achievement') {
            $id = (int)($_POST['achievement_id'] ?? 0);
            if ($id <= 0) {
                throw new Exception("Invalid achievement ID.");
            }

            $pdo->beginTransaction();
            try {
  
                $pointsStmt = $pdo->prepare("SELECT points FROM achievements WHERE id = ? AND deleted_at IS NULL");
                $pointsStmt->execute([$id]);
                $points = (int)($pointsStmt->fetchColumn() ?: 0);

                if ($points > 0) {
                   
                    $usersStmt = $pdo->prepare("
                SELECT user_id, COUNT(*) AS cnt
                FROM user_achievements
                WHERE achievement_id = ? AND deleted_at IS NULL
                GROUP BY user_id
            ");
                    $usersStmt->execute([$id]);
                    $rows = $usersStmt->fetchAll(PDO::FETCH_ASSOC);

                    $upd = $pdo->prepare("UPDATE users SET points = GREATEST(points - ?, 0) WHERE id = ?");
                    foreach ($rows as $r) {
                        $deduct = $points * (int)$r['cnt'];
                        $upd->execute([$deduct, (int)$r['user_id']]);
                    }
                }

              
                $pdo->prepare("UPDATE user_achievements SET deleted_at = NOW() WHERE achievement_id = ? AND deleted_at IS NULL")->execute([$id]);
                $pdo->prepare("UPDATE achievements SET deleted_at = NOW() WHERE id = ?")->execute([$id]);

                $pdo->commit();
                $success = "Achievement deleted successfully!";
            } catch (Exception $e) {
                $pdo->rollBack();
                throw $e;
            }


     
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
                try {
            
                    $pointsMapStmt = $pdo->prepare("SELECT id, points FROM achievements WHERE id IN ($placeholders) AND deleted_at IS NULL");
                    $pointsMapStmt->execute($achievement_ids);
                    $pointsMap = $pointsMapStmt->fetchAll(PDO::FETCH_KEY_PAIR); 

                    $agg = $pdo->prepare("
            SELECT achievement_id, user_id, COUNT(*) AS cnt
            FROM user_achievements
            WHERE achievement_id IN ($placeholders) AND deleted_at IS NULL
            GROUP BY achievement_id, user_id
        ");
                    $agg->execute($achievement_ids);
                    $rows = $agg->fetchAll(PDO::FETCH_ASSOC);

                    $upd = $pdo->prepare("UPDATE users SET points = GREATEST(points - ?, 0) WHERE id = ?");
                    foreach ($rows as $r) {
                        $aid = (int)$r['achievement_id'];
                        $p = (int)($pointsMap[$aid] ?? 0);
                        if ($p <= 0) continue;
                        $deduct = $p * (int)$r['cnt'];
                        $upd->execute([$deduct, (int)$r['user_id']]);
                    }

      
                    $pdo->prepare("UPDATE user_achievements SET deleted_at = NOW() WHERE achievement_id IN ($placeholders) AND deleted_at IS NULL")->execute($achievement_ids);
                    $pdo->prepare("UPDATE achievements SET deleted_at = NOW() WHERE id IN ($placeholders)")->execute($achievement_ids);

                    $pdo->commit();
                    $success = count($achievement_ids) . ' achievement(s) deleted.';
                } catch (Exception $e) {
                    $pdo->rollBack();
                    throw $e;
                }
            } else {
                throw new Exception('Invalid bulk action.');
            }

        } elseif ($action === 'award_to_student') {
            $achievement_id = (int)($_POST['achievement_id'] ?? 0);
            $student_id = (int)($_POST['student_id'] ?? 0);

            if ($achievement_id <= 0 || $student_id <= 0) {
                throw new Exception("Invalid achievement or student ID.");
            }

            $check = $pdo->prepare("
                SELECT id FROM user_achievements
                WHERE user_id = ? AND achievement_id = ? AND deleted_at IS NULL
            ");
            $check->execute([$student_id, $achievement_id]);
            if ($check->fetch()) {
                throw new Exception("This student has already earned this achievement.");
            }

            $achievement = $pdo->prepare("SELECT title, points FROM achievements WHERE id = ?");
            $achievement->execute([$achievement_id]);
            $ach = $achievement->fetch();

            if (!$ach) {
                throw new Exception("Achievement not found.");
            }

            $pdo->beginTransaction();
            $pdo->prepare("
                INSERT INTO user_achievements (user_id, achievement_id, earned_at)
                VALUES (?, ?, NOW())
            ")->execute([$student_id, $achievement_id]);

            $pdo->prepare("
                UPDATE users SET points = points + ? WHERE id = ?
            ")->execute([$ach['points'], $student_id]);

            $message = "🎉 Congratulations! You've been awarded the '{$ach['title']}' achievement by an admin (+{$ach['points']} points)!";
            $pdo->prepare("
                INSERT INTO notifications (user_id, title, message, type, related_id, created_at)
                VALUES (?, 'Achievement Awarded!', ?, 'achievement', ?, NOW())
            ")->execute([$student_id, $message, $achievement_id]);

            $pdo->commit();
            $success = "Achievement '{$ach['title']}' awarded to student!";

      
        } elseif ($action === 'recalculate_all') {
            $total_awarded = recalculateAllAchievements($pdo);
            $success = $total_awarded === 0
                ? 'Recalculation completed. No new achievements awarded.'
                : "Recalculation completed. $total_awarded new achievement(s) awarded.";

         
        } elseif ($action === 'award_to_all_eligible') {
            $achievement_id = (int)($_POST['achievement_id'] ?? 0);
            if ($achievement_id <= 0) {
                throw new Exception("Invalid achievement ID.");
            }

            $achievement = $pdo->prepare("
                SELECT * FROM achievements WHERE id = ? AND is_active = 1 AND deleted_at IS NULL
            ");
            $achievement->execute([$achievement_id]);
            $ach = $achievement->fetch();

            if (!$ach) {
                throw new Exception("Achievement not found or inactive.");
            }
            $students = $pdo->query("
    SELECT id, name, email, profile_picture
    FROM users 
    WHERE role='student' AND status='active' AND deleted_at IS NULL
    ORDER BY name ASC
")->fetchAll(PDO::FETCH_ASSOC);

            $force_award = isset($_POST['force_award']);

            $pdo->beginTransaction();
            $awarded_count = 0;

            foreach ($students as $student) {
                $student_id = (int)$student['id'];

                $check = $pdo->prepare("
        SELECT id FROM user_achievements
        WHERE user_id = ? AND achievement_id = ? AND deleted_at IS NULL
    ");
                $check->execute([$student_id, $achievement_id]);
                if ($check->fetch()) continue;

                $meets_criteria = false;

                if ($force_award) {
                    $meets_criteria = true;
                } else {
                    switch ($ach['criteria_type']) {
                        case 'total_completed_goals':
                            $count = getStat($pdo, "
        SELECT COUNT(*) FROM student_goals 
        WHERE student_id = ? AND status='completed' AND deleted_at IS NULL
    ", [$student_id]);
                            $meets_criteria = ($count >= $ach['criteria_value']);
                            break;
                        case 'completed_goals_in_category':
                            if ($ach['category_id']) {
                                $cat_stmt = $pdo->prepare("SELECT name FROM categories WHERE id = ?");
                                $cat_stmt->execute([$ach['category_id']]);
                                $cat_name = $cat_stmt->fetchColumn();
                                if ($cat_name) {
                                    $count = getStat($pdo, "
    SELECT COUNT(*) FROM student_goals
    WHERE student_id = ? AND category = ? AND status='completed' AND deleted_at IS NULL
", [$student_id, $cat_name]);
                                    $meets_criteria = ($count >= $ach['criteria_value']);
                                }
                            }
                            break;
                        case 'total_points':
                            $points = getStat($pdo, "SELECT points FROM users WHERE id = ?", [$student_id]);
                            $meets_criteria = ($points >= $ach['criteria_value']);
                            break;
                        case 'login_streak':
                            $streak = getStat($pdo, "SELECT current_streak FROM users WHERE id = ?", [$student_id]);
                            $meets_criteria = ($streak >= $ach['criteria_value']);
                            break;
                        case 'total_goals_created':
                            $count = getStat($pdo, "
    SELECT COUNT(*) FROM student_goals
    WHERE student_id = ? AND is_self_created = 1 AND deleted_at IS NULL
", [$student_id]);
                            $meets_criteria = ($count >= $ach['criteria_value']);
                            break;
                    }
                }

                if ($meets_criteria) {
                    $pdo->prepare("
                        INSERT INTO user_achievements (user_id, achievement_id, earned_at)
                        VALUES (?, ?, NOW())
                    ")->execute([$student_id, $achievement_id]);

                    $pdo->prepare("
                        UPDATE users SET points = points + ? WHERE id = ?
                    ")->execute([$ach['points'], $student_id]);

                    $awarded_count++;
                }
            }

            $pdo->commit();
            $success = "Awarded achievement to $awarded_count eligible student(s).";
        }
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $error = $e->getMessage();
    }

    $_SESSION['success'] = $success;
    $_SESSION['error'] = $error;
    header("Location: achievements.php?" . http_build_query($_GET));
    exit();
}

// Flash messages
$success = $_SESSION['success'] ?? '';
$error = $_SESSION['error'] ?? '';
unset($_SESSION['success'], $_SESSION['error']);

// Sidebar stats
$total_students = getStat($pdo, "SELECT COUNT(*) FROM users WHERE role='student' AND status='active' AND deleted_at IS NULL");
$total_goals = getStat($pdo, "SELECT COUNT(*) FROM admin_goals WHERE status='active' AND deleted_at IS NULL");
$total_achievements = getStat($pdo, "SELECT COUNT(*) FROM achievements WHERE deleted_at IS NULL");
$total_unlocked = getStat($pdo, "SELECT COUNT(*) FROM user_achievements WHERE deleted_at IS NULL");

$total_points = getStat($pdo, "
    SELECT COALESCE(SUM(a.points), 0)
    FROM user_achievements ua
    JOIN achievements a ON ua.achievement_id = a.id
    JOIN users u ON ua.user_id = u.id
    WHERE ua.deleted_at IS NULL
      AND a.deleted_at IS NULL
      AND u.deleted_at IS NULL
");


$sidebar_stats = [
    'students' => $total_students,
    'goals' => $total_goals,
    'points' => $total_points,
    'achievements' => $total_achievements,
    'unlocked' => $total_unlocked
];

// Edit mode
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
            'criteria_type' => '',
            'criteria_value' => 0,
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
    }
}

// Fetch data for filters
$categories = $pdo->query("
    SELECT id, name, color FROM categories WHERE deleted_at IS NULL ORDER BY name ASC
")->fetchAll(PDO::FETCH_ASSOC);

$students = $pdo->query("
    SELECT id, name, email, profile_picture
    FROM users WHERE role = 'student' AND status = 'active' ORDER BY name ASC
")->fetchAll(PDO::FETCH_ASSOC);

// Filters
$search = trim($_GET['search'] ?? '');
$status_filter = $_GET['status'] ?? 'all';
$criteria_filter = $_GET['criteria'] ?? 'all';
$category_filter = $_GET['category'] ?? 'all';

$where = ['a.deleted_at IS NULL'];
$params = [];

if ($search) {
    $where[] = "(a.title LIKE ? OR a.description LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
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

// Fetch achievements
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

// Active page highlight
$current = basename($_SERVER['PHP_SELF']);

$students_count = (int)($sidebar_stats['students'] ?? 0);
$goals_count    = (int)($sidebar_stats['goals'] ?? 0);
$assigned_count = (int)($sidebar_stats['unlocked'] ?? 0);
$points_count   = (int)($sidebar_stats['points'] ?? 0);



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
    <title>Achievements Management - ProgressMate</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/select2/4.0.13/css/select2.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>

:root{
  --bg0:#070A18;
  --bg1:#0B1030;

  --text:#EAF0FF;
  --muted: rgba(234,240,255,.68);

  --primary:#7C3AED;
  --primary-light: rgba(124,58,237,.14);

  --cyan:#22D3EE;
  --pink:#FB7185;

  --success:#34D399;
  --warning:#FBBF24;
  --danger:#FB7185;

  --gray-50: rgba(255,255,255,.02);
  --gray-100: rgba(255,255,255,.04);
  --gray-200: rgba(255,255,255,.06);
  --gray-300: rgba(255,255,255,.10);
  --gray-500: rgba(234,240,255,.60);
  --gray-600: rgba(234,240,255,.52);
  --gray-900: rgba(234,240,255,.95);

  --border: rgba(255,255,255,.10);
  --border2: rgba(255,255,255,.08);

  --shadow: 0 18px 45px rgba(0,0,0,.35);
  --shadow2: 0 10px 30px rgba(0,0,0,.22);

  --r12: 12px;
  --r14: 14px;
  --r16: 16px;
  --r20: 20px;
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
a{ color: inherit; text-decoration:none; }
img{ max-width:100%; display:block; }


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
  display: grid;
  grid-template-columns: 320px 1fr;
  min-height: 100vh;
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
.sidebar-header,.user-profile,.nav-menu,.sidebar-quick-stats,.sidebar-footer{
  position:relative;
  z-index:2;
}
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
  font-weight: 950;
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
.user-info h4{ margin: 2px 0 2px; font-size: 15px; font-weight: 950; }
.user-info p{
  margin: 0;
  font-size: 12.5px;
  color: var(--muted);
  overflow: hidden;
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
.nav-menu::-webkit-scrollbar-thumb{
  background: rgba(255,255,255,.16);
  border-radius: 99px;
}
.nav-menu::-webkit-scrollbar-thumb:hover{ background: rgba(255,255,255,.22); }

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
  font-weight: 950;
  padding: 4px 10px;
  border-radius: 999px;
  color: var(--text);
  background:
    radial-gradient(120% 180% at 20% 20%, rgba(255,255,255,.20), transparent 55%),
    linear-gradient(135deg, rgba(251,113,133,.70), rgba(124,58,237,.45));
  border: 1px solid rgba(255,255,255,.18);
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
.sidebar-stat-number{ font-size: 18px; font-weight: 950; }

.sidebar-footer{ flex: 0 0 auto; margin-top: 12px; }
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
    linear-gradient(135deg, rgba(251,113,133,.20), rgba(255,255,255,.03));
  box-shadow: 0 14px 26px rgba(0,0,0,.16);
  transition: transform .18s ease, box-shadow .18s ease;
}
.logout-btn:hover{
  transform: translateY(-1px);
  box-shadow: 0 18px 34px rgba(251,113,133,.18);
}

.main-content{ padding: 26px 26px 54px; }

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
.header-content h1{ margin: 0 0 6px; font-size: 26px; font-weight: 950; }
.header-content p{ margin: 0; color: var(--muted); font-size: 14px; max-width: 900px; }

.btn{
  display:inline-flex;
  align-items:center;
  justify-content:center;
  gap: 10px;
  padding: 12px 14px;
  border-radius: 14px;
  font-weight: 950;
  border: 1px solid rgba(255,255,255,.14);
  color: var(--text);
  background: rgba(255,255,255,.05);
  cursor:pointer;
  transition: transform .18s ease, box-shadow .18s ease, background .18s ease, border-color .18s ease;
  white-space: nowrap;
}
.btn:hover{
  transform: translateY(-1px);
  background: rgba(255,255,255,.07);
  box-shadow: 0 0 0 1px rgba(255,255,255,.08), 0 12px 30px rgba(124,58,237,.18);
}
.btn-primary{
  border-color: rgba(124,58,237,.35);
  background:
    radial-gradient(120% 180% at 20% 10%, rgba(255,255,255,.16), transparent 55%),
    linear-gradient(135deg, rgba(124,58,237,.62), rgba(34,211,238,.18));
}
.btn-outline{ background: rgba(255,255,255,.04); }
.btn-danger{ background: rgba(251,113,133,.14); border-color: rgba(251,113,133,.22); }
.btn-success{ background: rgba(52,211,153,.14); border-color: rgba(52,211,153,.22); }

.btn-sm{
  padding: 9px 10px;
  border-radius: 12px;
  font-size: 13px;
}


.alert{
  margin-top: 14px;
  padding: 12px 14px;
  border-radius: 14px;
  border: 1px solid rgba(255,255,255,.12);
  background: rgba(255,255,255,.04);
  display:flex;
  align-items:center;
  gap:10px;
}
.alert-success{ border-color: rgba(52,211,153,.25); background: rgba(52,211,153,.10); }
.alert-error{ border-color: rgba(251,113,133,.25); background: rgba(251,113,133,.10); }
.alert-warning{ border-color: rgba(251,191,36,.25); background: rgba(251,191,36,.10); }

.stats-grid{
  margin-top: 18px;
  display:grid;
  grid-template-columns: repeat(4, minmax(0, 1fr));
  gap: 14px;
}
.stat-card{
  border-radius: var(--r20);
  border: 1px solid rgba(255,255,255,.12);
  background:
    radial-gradient(120% 180% at 10% 0%, rgba(255,255,255,.12), transparent 60%),
    linear-gradient(180deg, rgba(255,255,255,.05), rgba(255,255,255,.03));
  box-shadow: var(--shadow2);
  padding: 14px 14px;
  position: relative;
  overflow:hidden;
}
.stat-card::after{
  content:"";
  position:absolute;
  inset:-2px;
  background: linear-gradient(120deg, rgba(124,58,237,.22), rgba(34,211,238,.16), rgba(251,113,133,.14));
  opacity:.16;
  filter: blur(22px);
  pointer-events:none;
}
.stat-icon{
  width: 44px;
  height: 44px;
  border-radius: 16px;
  display:grid;
  place-items:center;
  border: 1px solid rgba(255,255,255,.14);
  background: rgba(255,255,255,.06);
  margin-bottom: 10px;
  position: relative;
  z-index: 1;
}
.stat-number{
  font-size: 26px;
  font-weight: 950;
  position: relative;
  z-index: 1;
}
.stat-label{
  margin-top: 4px;
  font-size: 13px;
  color: var(--muted);
  font-weight: 800;
  position: relative;
  z-index: 1;
}


.tabs{
  margin-top: 16px;
  display:flex;
  gap: 10px;
  flex-wrap: wrap;
}
.tab{
  display:inline-flex;
  align-items:center;
  gap: 10px;
  padding: 12px 14px;
  border-radius: 14px;
  border: 1px solid rgba(255,255,255,.12);
  background: rgba(255,255,255,.04);
  color: rgba(234,240,255,.90);
  cursor:pointer;
  font-weight: 950;
  transition: transform .18s ease, box-shadow .18s ease, background .18s ease;
}
.tab:hover{
  transform: translateY(-1px);
  box-shadow: 0 12px 30px rgba(124,58,237,.12);
}
.tab.active{
  background: linear-gradient(135deg, rgba(124,58,237,.58), rgba(34,211,238,.18));
  border-color: rgba(255,255,255,.18);
  box-shadow: 0 18px 40px rgba(124,58,237,.18);
}
.tab-content{ display:none; margin-top: 14px; }
.tab-content.active{ display:block; }

.filters{
  margin-top: 12px;
  display:grid;
  grid-template-columns: 1.4fr 1fr 1fr 1fr auto auto;
  gap: 12px;
  align-items:end;
  padding: 14px;
  border-radius: var(--r20);
  border: 1px solid rgba(255,255,255,.10);
  background: rgba(255,255,255,.035);
  box-shadow: var(--shadow2);
}
.filter-group label{
  display:block;
  font-size: 12px;
  color: var(--muted);
  margin-bottom: 8px;
  font-weight: 900;
}
.filters input,
.filters select{
  width: 100%;
  padding: 12px 12px;
  border-radius: 12px;
  border: 1px solid rgba(255,255,255,.12);
  background: rgba(10,14,35,.45);
  color: var(--text);
  outline:none;
}
.filters input:focus,
.filters select:focus{
  border-color: rgba(34,211,238,.30);
  box-shadow: 0 0 0 3px rgba(34,211,238,.16);
}

.bulk-actions{
  margin-top: 12px;
  display:flex;
  gap: 12px;
  align-items:center;
  flex-wrap: wrap;
  padding: 12px 14px;
  border-radius: 16px;
  border: 1px solid rgba(255,255,255,.10);
  background: rgba(255,255,255,.03);
}
.bulk-actions select{
  padding: 12px 12px;
  border-radius: 12px;
  border: 1px solid rgba(255,255,255,.12);
  background: rgba(10,14,35,.45);
  color: var(--text);
}

.achievement-item{
  margin-top: 12px;
  display:grid;
  grid-template-columns: 26px 56px 1fr auto;
  gap: 14px;
  align-items: center;
  padding: 14px;
  border-radius: var(--r20);
  border: 1px solid rgba(255,255,255,.10);
  background:
    radial-gradient(120% 180% at 10% 0%, rgba(255,255,255,.10), transparent 60%),
    linear-gradient(180deg, rgba(255,255,255,.05), rgba(255,255,255,.03));
  box-shadow: var(--shadow2);
  transition: transform .18s ease, border-color .18s ease, background .18s ease;
}
.achievement-item:hover{
  transform: translateY(-1px);
  border-color: rgba(255,255,255,.14);
  background: rgba(255,255,255,.045);
}
.achievement-item.selected{
  border-color: rgba(34,211,238,.28);
  box-shadow: 0 0 0 2px rgba(34,211,238,.12), var(--shadow2);
}

.achievement-checkbox{
  width: 16px;
  height: 16px;
  accent-color: var(--cyan);
}

.badge-preview{
  width: 54px;
  height: 54px;
  border-radius: 18px;
  display:grid;
  place-items:center;
  color: #fff;
  border: 1px solid rgba(255,255,255,.20);
  box-shadow: 0 18px 40px rgba(0,0,0,.20);
}
.badge-preview i{ font-size: 18px; }


.achievement-title{
  font-weight: 950;
  font-size: 16px;
}
.achievement-description{
  margin-top: 6px;
  color: var(--muted);
  font-size: 13px;
}
.achievement-stats{
  margin-top: 10px;
  display:flex;
  flex-wrap: wrap;
  gap: 10px 14px;
  align-items:center;
}
.achievement-points,
.achievement-unlocked{
  display:inline-flex;
  gap: 8px;
  align-items:center;
  font-weight: 900;
  font-size: 13px;
  padding: 6px 10px;
  border-radius: 999px;
  border: 1px solid rgba(255,255,255,.12);
  background: rgba(255,255,255,.04);
}


.status-badge{
  margin-left: auto;
  font-size: 12px;
  font-weight: 950;
  padding: 6px 10px;
  border-radius: 999px;
  border: 1px solid rgba(255,255,255,.12);
  background: rgba(255,255,255,.04);
}
.status-active{ border-color: rgba(52,211,153,.22); background: rgba(52,211,153,.10); }
.status-inactive{ border-color: rgba(251,113,133,.22); background: rgba(251,113,133,.10); }


.action-buttons{
  display:flex;
  gap: 10px;
  align-items:center;
}
.action-buttons form{ margin:0; }


.empty-state{
  margin-top: 16px;
  padding: 30px 18px;
  border-radius: var(--r20);
  border: 1px dashed rgba(255,255,255,.18);
  background: rgba(255,255,255,.03);
  text-align:center;
  color: var(--muted);
}
.empty-state i{
  font-size: 32px;
  margin-bottom: 10px;
  opacity: .9;
}


.modal-overlay{
  position: fixed;
  inset: 0;
  display: none;
  align-items:center;
  justify-content:center;
  background: rgba(2,6,23,.62);
  z-index: 2200;
  padding: 18px;
}
.modal{
  width: min(620px, 100%);
  border-radius: 20px;
  border: 1px solid rgba(255,255,255,.12);
  background:
    radial-gradient(120% 180% at 10% 0%, rgba(255,255,255,.12), transparent 60%),
    linear-gradient(180deg, rgba(10,14,35,.95), rgba(10,14,35,.82));
  box-shadow: 0 30px 90px rgba(0,0,0,.65);
  overflow:hidden;
}
.modal-header{
  padding: 14px 16px;
  display:flex;
  align-items:center;
  justify-content: space-between;
  border-bottom: 1px solid rgba(255,255,255,.08);
}
.modal-header h3{ margin:0; font-size: 16px; font-weight: 950; }
.modal-close{
  width: 40px;
  height: 40px;
  border-radius: 14px;
  border: 1px solid rgba(255,255,255,.12);
  background: rgba(255,255,255,.05);
  color: var(--text);
  cursor:pointer;
}
.modal-body{ padding: 16px; }

.form-group{ margin-bottom: 14px; }
.modal-body label{
  display:block;
  font-size: 12px;
  color: var(--muted);
  margin-bottom: 8px;
  font-weight: 900;
}
.modal-body input,
.modal-body select,
.modal-body textarea{
  width: 100%;
  padding: 12px 12px;
  border-radius: 12px;
  border: 1px solid rgba(255,255,255,.12);
  background: rgba(255,255,255,.04);
  color: var(--text);
  outline:none;
}
.modal-body textarea{ resize: vertical; }

.select2-container{ width: 100% !important; }
.select2-container--default .select2-selection--single{
  min-height: 46px;
  border-radius: 12px;
  border: 1px solid rgba(255,255,255,.12);
  background: rgba(10,14,35,.45);
  color: var(--text);
  padding: 6px 10px;
}
.select2-container--default .select2-selection--single .select2-selection__rendered{
  color: var(--text);
  line-height: 32px;
}
.select2-container--default.select2-container--focus .select2-selection{
  border-color: rgba(34,211,238,.30);
  box-shadow: 0 0 0 3px rgba(34,211,238,.16);
}
.select2-dropdown{
  border-radius: 14px;
  border: 1px solid rgba(255,255,255,.12);
  background: rgba(10,14,35,.92);
  box-shadow: 0 22px 55px rgba(0,0,0,.55);
  overflow: hidden;
}
.select2-container--default .select2-results__option{
  padding: 10px 12px;
  color: rgba(234,240,255,.90);
}
.select2-container--default .select2-results__option--highlighted{
  background: rgba(124,58,237,.35);
  color: #fff;
}
.select2-search--dropdown .select2-search__field{
  border-radius: 12px;
  border: 1px solid rgba(255,255,255,.12);
  background: rgba(255,255,255,.04);
  color: var(--text);
  padding: 10px 12px;
}

@media (max-width: 1100px){
  .filters{ grid-template-columns: 1fr 1fr 1fr 1fr; }
  .filters .btn{ width: 100%; }
}
@media (max-width: 980px){
  .stats-grid{ grid-template-columns: repeat(2, minmax(0, 1fr)); }
  .achievement-item{ grid-template-columns: 26px 56px 1fr; }
  .action-buttons{ grid-column: 1 / -1; justify-content:flex-end; }
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
    z-index: 1601;
  }
  .sidebar.active{ transform: translateX(0); }
  .sidebar-close{ display:grid; }
  .sidebar-overlay{ z-index: 1600; }

  .main-content{ padding: 22px 16px 44px; }
  .page-header{ flex-direction: column; align-items: flex-start; }
}
@media (max-width: 620px){
  .stats-grid{ grid-template-columns: 1fr; }
  .filters{ grid-template-columns: 1fr; }
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
                    <div class="profile-pic default"><?php echo htmlspecialchars(strtoupper(substr($_SESSION['name'] ?? 'A', 0, 1))); ?></div>
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

              
            
                <a href="settings.php" class="nav-link <?php echo $current === 'settings.php' ? 'active' : ''; ?>">
                    <i class="fas fa-cog"></i> Settings
                </a>
            </nav>
            <div class="sidebar-quick-stats">
                <div class="sidebar-stat">
                    <div class="sidebar-stat-icon"><i class="fas fa-trophy"></i></div>
                    <div>
                        <div class="sidebar-stat-label">Total Achievements</div>
                        <div class="sidebar-stat-number"><?php echo $sidebar_stats['achievements']; ?></div>
                    </div>
                </div>
                <div class="sidebar-stat">
                    <div class="sidebar-stat-icon"><i class="fas fa-unlock"></i></div>
                    <div>
                        <div class="sidebar-stat-label">Unlocked</div>
                        <div class="sidebar-stat-number"><?php echo $sidebar_stats['unlocked']; ?></div>
                    </div>
                </div>
                <div class="sidebar-stat">
                    <div class="sidebar-stat-icon"><i class="fas fa-star"></i></div>
                    <div>
                        <div class="sidebar-stat-label">Points Distributed</div>
                        <div class="sidebar-stat-number"><?php echo $sidebar_stats['points']; ?></div>
                    </div>
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
                    <p>Define and award achievements to students based on their progress</p>
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
            <div class="tabs">
                <button class="tab active" data-tab="list"><i class="fas fa-list"></i> All Achievements</button>
                <button class="tab" data-tab="top"><i class="fas fa-medal"></i> Top Students</button>
                <?php if ($edit_mode): ?>
                    <button class="tab" data-tab="edit"><i class="fas fa-edit"></i>
                        <?php echo $edit_achievement['id'] === 'new' ? 'Add Achievement' : 'Edit Achievement'; ?>
                    </button>
                <?php endif; ?>
            </div>
            <div class="tab-content active" id="tab-list">
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
                <form method="POST" id="bulkForm">
                    <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                    <div class="bulk-actions">
                        <select name="bulk_action" required>
                            <option value="">Bulk Actions</option>
                            <option value="activate">Activate Selected</option>
                            <option value="deactivate">Deactivate Selected</option>
                            <option value="delete">Delete Selected</option>
                        </select>
                        <button type="submit" name="action" value="bulk_action" class="btn btn-primary">
                            <i class="fas fa-check"></i> Apply
                        </button>
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
                                <input class="achievement-checkbox" type="checkbox"
                                    name="achievement_ids[]" value="<?php echo $achievement['id']; ?>"
                                    onchange="toggleAchievementSelection(this)">

                                <div class="badge-preview" style="background: <?php echo htmlspecialchars($achievement['color']); ?>;">
                                    <i class="fas fa-<?php echo htmlspecialchars($achievement['icon']); ?>"></i>
                                </div>
                                <div class="achievement-info">
                                    <div class="achievement-title"><?php echo htmlspecialchars($achievement['title']); ?></div>
                                    <div class="achievement-description"><?php echo htmlspecialchars($achievement['description']); ?></div>
                                    <div class="achievement-stats">
                                        <span class="achievement-points"><i class="fas fa-star"></i> <?php echo $achievement['points']; ?> points</span>
                                        <span class="achievement-unlocked"><i class="fas fa-users"></i> <?php echo $achievement['unlocked_count']; ?> unlocked</span>
                                        <?php if (!empty($achievement['unlocked_by_names'])): ?>
                                            <div style="margin-top:8px; font-size:13px; color:#6b7280;">
                                                <strong>Unlocked by:</strong> <?php echo htmlspecialchars($achievement['unlocked_by_names']); ?>
                                            </div>
                                        <?php endif; ?>

                                        <span class="status-badge status-<?php echo $achievement['is_active'] ? 'active' : 'inactive'; ?>">
                                            <?php echo $achievement['is_active'] ? 'Active' : 'Inactive'; ?>
                                        </span>
                                    </div>
                                </div>
                                <div class="action-buttons">
                                    <a href="?edit=<?php echo $achievement['id']; ?>" class="btn btn-sm btn-outline"><i class="fas fa-edit"></i></a>
                                    <button type="button" class="btn btn-sm btn-success" onclick="openAwardModal(<?php echo $achievement['id']; ?>, '<?php echo addslashes($achievement['title']); ?>')"><i class="fas fa-award"></i></button>
                                    <form method="POST" style="display:inline;">
                                        <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                                        <input type="hidden" name="action" value="delete_achievement">
                                        <input type="hidden" name="achievement_id" value="<?php echo $achievement['id']; ?>">
                                        <button type="submit" class="btn btn-sm btn-danger"><i class="fas fa-trash"></i></button>
                                    </form>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </form>
            </div>
<?php if ($edit_mode && $edit_achievement): ?>
<div class="tab-content" id="tab-edit">
  <div class="modal" style="max-width: 900px; margin-top: 14px;">
    <div class="modal-header">
      <h3><?php echo $edit_achievement['id'] === 'new' ? 'Add Achievement' : 'Edit Achievement'; ?></h3>
    </div>

    <div class="modal-body">
      <form method="POST">
        <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">

        <input type="hidden" name="action" value="<?php echo $edit_achievement['id'] === 'new' ? 'add_achievement' : 'edit_achievement'; ?>">
        <input type="hidden" name="achievement_id" value="<?php echo $edit_achievement['id'] === 'new' ? '' : (int)$edit_achievement['id']; ?>">

        <div class="form-group">
          <label>Title</label>
          <input type="text" name="title" required minlength="3"
                 value="<?php echo htmlspecialchars($edit_achievement['title'] ?? ''); ?>"
                 placeholder="e.g., Top Performer">
        </div>

        <div class="form-group">
          <label>Description</label>
          <textarea name="description" rows="3"
                    placeholder="Short description"><?php echo htmlspecialchars($edit_achievement['description'] ?? ''); ?></textarea>
        </div>

        <div class="form-group">
          <label>Points</label>
          <input type="number" name="points" min="1"
                 value="<?php echo (int)($edit_achievement['points'] ?? 10); ?>">
        </div>

        <div class="form-row" style="display:flex; gap:12px; flex-wrap:wrap;">
          <div class="form-group" style="flex:1; min-width:220px;">
            <label>Criteria Type</label>
            <select name="criteria_type" id="criteria_type" onchange="toggleCriteriaFields()">
              <option value="">Manual Award Only</option>
              <?php foreach ($criteria_types as $key => $label): ?>
                <?php if ($key): ?>
                  <option value="<?php echo htmlspecialchars($key); ?>"
                    <?php echo (($edit_achievement['criteria_type'] ?? '') === $key) ? 'selected' : ''; ?>>
                    <?php echo htmlspecialchars($label); ?>
                  </option>
                <?php endif; ?>
              <?php endforeach; ?>
            </select>
            <div id="criteria_help" style="margin-top:8px; font-size:12px; color:var(--muted); font-weight:700;"></div>
          </div>

          <div class="form-group" id="criteria_value_group" style="flex:1; min-width:220px; display:none;">
            <label>Criteria Value</label>
            <input type="number" name="criteria_value" min="1"
                   value="<?php echo (int)($edit_achievement['criteria_value'] ?? 0); ?>">
          </div>

          <div class="form-group" id="category_group" style="flex:1; min-width:220px; display:none;">
            <label>Category</label>
            <select name="category_id">
              <option value="">None</option>
              <?php foreach ($categories as $cat): ?>
                <option value="<?php echo (int)$cat['id']; ?>"
                  <?php echo ((int)($edit_achievement['category_id'] ?? 0) === (int)$cat['id']) ? 'selected' : ''; ?>>
                  <?php echo htmlspecialchars($cat['name']); ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>

        <div class="form-row" style="display:flex; gap:12px; flex-wrap:wrap;">
          <div class="form-group" style="flex:1; min-width:220px;">
            <label>Icon</label>
            <select name="icon" id="icon_select" onchange="updateIconPreview()">
              <?php
              $allowed_icons = ['trophy','medal','star','award','certificate','gem','crown','fire','bullseye','rocket','lightning','shield','book','graduation-cap','brain','heart','flag','moon','sun','cloud'];
              $current_icon = $edit_achievement['icon'] ?? 'trophy';
              foreach ($allowed_icons as $ic):
              ?>
                <option value="<?php echo $ic; ?>" <?php echo $current_icon === $ic ? 'selected' : ''; ?>>
                  <?php echo $ic; ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="form-group" style="flex:1; min-width:220px;">
            <label>Color</label>
            <input type="color" name="color" id="color_picker"
                   value="<?php echo htmlspecialchars($edit_achievement['color'] ?? '#f59e0b'); ?>">
            <input type="text" id="color_text"
                   value="<?php echo htmlspecialchars($edit_achievement['color'] ?? '#f59e0b'); ?>"
                   style="margin-top:10px;">
          </div>

          <div class="form-group" style="flex:1; min-width:220px;">
            <label>Image Path (optional)</label>
            <input type="text" name="image_path"
                   value="<?php echo htmlspecialchars($edit_achievement['image_path'] ?? ''); ?>"
                   placeholder="uploads/badges/badge1.png">
          </div>
        </div>

        <div class="form-group" style="display:flex; gap:10px; align-items:center;">
          <input type="checkbox" name="is_active" id="is_active"
                 <?php echo !empty($edit_achievement['is_active']) ? 'checked' : ''; ?>>
          <label for="is_active" style="margin:0;">Active</label>
        </div>

        <div style="display:flex; gap:12px; margin-top:18px; flex-wrap:wrap;">
          <button type="submit" class="btn btn-primary">
            <i class="fas fa-save"></i> Save Achievement
          </button>
          <a href="achievements.php" class="btn btn-outline">Cancel</a>
        </div>

        <div style="margin-top:16px; display:flex; gap:12px; align-items:center;">
          <div id="preview_badge" class="badge-preview" style="background: <?php echo htmlspecialchars($edit_achievement['color'] ?? '#f59e0b'); ?>;">
            <i id="preview_icon" class="fas fa-<?php echo htmlspecialchars($edit_achievement['icon'] ?? 'trophy'); ?>"></i>
          </div>
          <div>
            <div id="preview_title" style="font-weight:950;">Achievement Title</div>
            <div id="preview_points" style="margin-top:6px; color:var(--muted); font-weight:800;"><?php echo (int)($edit_achievement['points'] ?? 10); ?> points</div>
          </div>
        </div>

      </form>
    </div>
  </div>
</div>
<?php endif; ?>

   
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
               
                $(document).ready(function() {
                    $('select[name="student_id"]').select2({
                        placeholder: "Select a student...",
                        width: '100%'
                    });
                });

           
                document.querySelectorAll('.tab').forEach(tab => {
                    tab.addEventListener('click', () => {
                    
                        document.querySelectorAll('.tab').forEach(t => t.classList.remove('active'));
                        document.querySelectorAll('.tab-content').forEach(c => c.classList.remove('active'));

                    
                        tab.classList.add('active');

            
                        const tabId = tab.dataset.tab;
                        if (tabId === 'edit') {
                            document.getElementById('tab-edit').classList.add('active');
                        } else {
                            document.getElementById('tab-' + tabId).classList.add('active');
                        }
                    });
                });
function byId(id){ return document.getElementById(id); }

function updateIconPreview(){
  const iconSel = byId('icon_select');
  const colorPick = byId('color_picker');
  const prevIcon = byId('preview_icon');
  const prevBadge = byId('preview_badge');

  if(!iconSel || !colorPick || !prevIcon || !prevBadge) return;

  prevIcon.className = 'fas fa-' + iconSel.value;
  prevBadge.style.background = colorPick.value;
}

const colorPicker = byId('color_picker');
const colorText = byId('color_text');

if(colorPicker){
  colorPicker.addEventListener('input', function(){
    if(colorText) colorText.value = this.value;
    updateIconPreview();
  });
}

if(colorText){
  colorText.addEventListener('input', function(){
    if(colorPicker) colorPicker.value = this.value;
    updateIconPreview();
  });
}


                document.querySelectorAll('input[name="title"], input[name="points"]').forEach(input => {
                    input.addEventListener('input', function() {
                        if (this.name === 'title') {
                            document.getElementById('preview_title').textContent = this.value || 'Achievement Title';
                        } else if (this.name === 'points') {
                            document.getElementById('preview_points').textContent = (this.value || '0') + ' points';
                        }
                    });
                });

            function toggleCriteriaFields(){
  const criteriaTypeEl = document.getElementById('criteria_type');
  const criteriaValueGroup = document.getElementById('criteria_value_group');
  const categoryGroup = document.getElementById('category_group');
  const criteriaHelp = document.getElementById('criteria_help');

  if(!criteriaTypeEl) return;

  const criteriaType = criteriaTypeEl.value;

  if(!criteriaType){
    if(criteriaValueGroup) criteriaValueGroup.style.display = 'none';
    if(categoryGroup) categoryGroup.style.display = 'none';
    if(criteriaHelp) criteriaHelp.textContent = 'No criteria required for manual awards';
  } else {
    if(criteriaValueGroup) criteriaValueGroup.style.display = 'flex';
    if(criteriaHelp) criteriaHelp.textContent = 'Number required to earn achievement';
    if(categoryGroup) categoryGroup.style.display = (criteriaType === 'completed_goals_in_category') ? 'flex' : 'none';
  }
}


             
      document.addEventListener('DOMContentLoaded', () => {
  toggleCriteriaFields();
  updateIconPreview();
});


            
                function testAchievement(achievementId) {
                    window.open(`test_achievement.php?id=${achievementId}`, '_blank', 'width=800,height=600');
                }

      
                function openAwardModal(achievementId, achievementTitle) {
                    document.getElementById('award_achievement_id').value = achievementId;
                    document.getElementById('award_achievement_title').textContent = achievementTitle;
                    document.getElementById('awardModal').style.display = 'flex';
                }

                function closeAwardModal() {
                    document.getElementById('awardModal').style.display = 'none';
                }

            
                function openAwardAllModal(achievementId, achievementTitle) {
                    document.getElementById('awardAll_achievement_id').value = achievementId;
                    document.getElementById('awardAll_achievement_title').textContent = achievementTitle;
                    document.getElementById('awardAllModal').style.display = 'flex';
                }

                function closeAwardAllModal() {
                    document.getElementById('awardAllModal').style.display = 'none';
                }

            
                function toggleAchievementSelection(checkbox) {
                    const item = checkbox.closest('.achievement-item');
                    if (checkbox.checked) {
                        item.classList.add('selected');
                    } else {
                        item.classList.remove('selected');
                    }
                }

              
                function selectAllAchievements(selectAll) {
                    document.querySelectorAll('.achievement-checkbox').forEach(checkbox => {
                        checkbox.checked = selectAll.checked;
                        toggleAchievementSelection(checkbox);
                    });
                }

               
                const sidebar = document.getElementById('sidebar');
                const sidebarToggle = document.getElementById('sidebarToggle');
                const sidebarClose = document.getElementById('sidebarClose');
                const overlay = document.getElementById('sidebarOverlay');
                document.querySelectorAll('.nav-menu .nav-link').forEach(link => {
  link.addEventListener('click', () => {
    closeSidebar()
  });
});


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

   
                updateIconPreview();
                 document.addEventListener('DOMContentLoaded', () => {
  const nav = document.querySelector('.nav-menu');
const active = document.querySelector('.nav-menu .nav-link.active');
if (nav && active) active.scrollIntoView({ block: 'center' });

  });
  
</script>
</body>

</html>