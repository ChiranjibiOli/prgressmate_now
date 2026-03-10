<?php
// students/achievements.php

session_start();
require_once '../includes/db_connection.php';
require_once '../includes/functions.php'; // For getStudentAchievements() function

checkAuth('student');

$student_id = $_SESSION['user_id'];
awardAchievements($pdo, $student_id);
$success = $_SESSION['success'] ?? '';
$error = $_SESSION['error'] ?? '';
unset($_SESSION['success'], $_SESSION['error']);

// === Fetch Student Statistics ===
$stmt = $pdo->prepare("SELECT COUNT(*) FROM student_goals WHERE student_id = ? AND deleted_at IS NULL");
$stmt->execute([$student_id]);
$total_goals = $stmt->fetchColumn() ?: 0;

$stmt = $pdo->prepare("SELECT COUNT(*) FROM student_goals WHERE student_id = ? AND status='completed' AND deleted_at IS NULL");
$stmt->execute([$student_id]);
$completed_goals = $stmt->fetchColumn() ?: 0;

$stmt = $pdo->prepare("SELECT points FROM users WHERE id = ?");
$stmt->execute([$student_id]);
$total_points = $stmt->fetchColumn() ?: 0;

$stmt = $pdo->prepare("SELECT current_streak FROM users WHERE id = ?");
$stmt->execute([$student_id]);
$streak = $stmt->fetchColumn() ?: 0;

$stmt = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0");
$stmt->execute([$student_id]);
$unread = $stmt->fetchColumn() ?: 0;

// === Fetch Earned Achievements ===
$earned_achievements = getStudentAchievements($pdo, $student_id);

// === Fetch Achievement Progress (unearned achievements) ===
$progress_achievements = getAchievementProgress($pdo, $student_id);

// === Fetch Recent Activity ===
$recent_activity = $pdo->prepare("
    SELECT 
        n.*,
        DATE_FORMAT(n.created_at, '%b %d, %Y %h:%i %p') as formatted_date
    FROM notifications n
 WHERE n.user_id = ? AND n.type = 'achievement' AND n.deleted_at IS NULL

    ORDER BY n.created_at DESC
    LIMIT 10
");
$recent_activity->execute([$student_id]);
$recent_activity = $recent_activity->fetchAll(PDO::FETCH_ASSOC);
$current = basename($_SERVER['PHP_SELF']);
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Achievements - ProgressMate</title>
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
  --primary-light: rgba(79,70,229,.14);

  --cyan:#22D3EE;
  --pink:#60A5FA;

  --success:#34D399;
  --warning:#FBBF24;
  --danger:#FB7185;
  --info:#22D3EE;

  --purple:#8b5cf6;
  --gold:#fbbf24;
  --silver:#9ca3af;
  --bronze:#f97316;

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
  font-family: 'Inter', system-ui, -apple-system, Segoe UI, Roboto, Arial, sans-serif;
  background:
    radial-gradient(900px 520px at 18% 10%, rgba(79,70,229,.22), transparent 60%),
    radial-gradient(900px 520px at 88% 15%, rgba(34,211,238,.18), transparent 58%),
    radial-gradient(900px 520px at 70% 95%, rgba(96,165,250,.14), transparent 62%),
    linear-gradient(180deg, var(--bg0), var(--bg1));
  overflow-x:hidden;
}

a{ color: inherit; text-decoration:none; }
img{ max-width:100%; display:block; }
button{ font-family: inherit; cursor:pointer; border:none; background:none; }

/* ===== Mobile Toggle + Overlay ===== */
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
.sidebar-overlay{
  position: fixed;
  inset: 0;
  background: rgba(0,0,0,.45);
  opacity: 0;
  pointer-events: none;
  transition: opacity .2s ease;
  z-index: 1600;
}
.sidebar-overlay.active{
  opacity: 1;
  pointer-events: auto;
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
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
  max-width: 210px;
}
.user-tag{
  display:inline-flex;
  align-items:center;
  margin-top: 6px;
  font-size: 11px;
  font-weight: 950;
  padding: 3px 10px;
  border-radius: 999px;
  color: rgba(79,70,229,.95);
  background: rgba(224,231,255,.95);
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

/* header */
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
.header-content h1{ margin:0 0 6px; font-size: 24px; font-weight: 950; }
.header-content p{ margin:0; color: var(--muted); font-size: 14px; }

/* alerts */
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

/* stats */
.stats-grid{
  width: 100%;
  margin-top: 14px;
  display:grid;
  grid-template-columns: repeat(4, minmax(0, 1fr));
  gap: 12px;
}
.stat-card{
  border-radius: var(--r20);
  border: 1px solid rgba(255,255,255,.12);
  background:
    radial-gradient(120% 180% at 10% 0%, rgba(255,255,255,.12), transparent 60%),
    linear-gradient(180deg, rgba(255,255,255,.05), rgba(255,255,255,.03));
  box-shadow: var(--shadow2);
  text-align:left;
}
.stat-icon{
  width: 44px;
  height: 44px;
  border-radius: 16px;
  display:grid;
  place-items:center;
  border: 1px solid rgba(255,255,255,.16);
  background:
    radial-gradient(120% 180% at 20% 15%, rgba(255,255,255,.20), transparent 55%),
    linear-gradient(135deg, rgba(34,211,238,.40), rgba(79,70,229,.40));
  box-shadow: 0 16px 30px rgba(0,0,0,.22);
  margin-bottom: 10px;
}
.stat-card{ padding: 14px; }
.stat-number{ font-size: 24px; font-weight: 950; line-height: 1.1; }
.stat-label{ margin-top: 2px; font-size: 13px; color: var(--muted); }

/* ===== Tabs ===== */
.tabs{
  width:100%;
  margin-top: 14px;
  display:flex;
  gap: 8px;
  padding: 8px;
  border-radius: var(--r20);
  border: 1px solid rgba(255,255,255,.10);
  background: rgba(255,255,255,.03);
  box-shadow: var(--shadow2);
}
.tab{
  position: relative;
  display:flex;
  align-items:center;
  gap: 10px;
  padding: 12px 14px;
  border-radius: 16px;
  border: 1px solid transparent;
  background: transparent;
  color: rgba(234,240,255,.82);
  font-weight: 950;
  cursor:pointer;
  transition: transform .18s ease, background .18s ease, border-color .18s ease, box-shadow .18s ease;
  white-space: nowrap;
}
.tab:hover{
  background: rgba(255,255,255,.05);
  border-color: rgba(255,255,255,.10);
  transform: translateY(-1px);
}
.tab.active{
  background:
    radial-gradient(120% 160% at 10% 20%, rgba(255,255,255,.14), transparent 55%),
    linear-gradient(135deg, rgba(79,70,229,.55), rgba(34,211,238,.20));
  border-color: rgba(255,255,255,.18);
  box-shadow: 0 18px 40px rgba(79,70,229,.18);
  color: rgba(234,240,255,.95);
}
.tab-badge{
  position:absolute;
  top: 6px;
  right: 8px;
  font-size: 10px;
  font-weight: 950;
  padding: 2px 8px;
  border-radius: 999px;
  color: var(--text);
  background: rgba(0,0,0,.25);
  border: 1px solid rgba(255,255,255,.14);
}

/* Tab content show/hide (YOUR JS uses .tab-content.active) */
.tab-content{ display:none; width:100%; margin-top: 12px; }
.tab-content.active{ display:block; }

/* Inline count inside tab label */
.tab-count{
  display:inline-flex;
  align-items:center;
  justify-content:center;
  min-width: 26px;
  height: 22px;
  padding: 0 7px;
  border-radius: 999px;
  font-size: 12px;
  font-weight: 950;
  background:
    radial-gradient(120% 180% at 20% 20%, rgba(255,255,255,.20), transparent 55%),
    linear-gradient(135deg, rgba(79,70,229,.70), rgba(34,211,238,.35));
  border: 1px solid rgba(255,255,255,.18);
  color: #fff;
  margin-left: 4px;
}

/* Inline count inside tab label */
.tab-count{
  display:inline-flex;
  align-items:center;
  justify-content:center;
  min-width: 26px;
  height: 22px;
  padding: 0 7px;
  border-radius: 999px;
  font-size: 12px;
  font-weight: 950;
  background:
    radial-gradient(120% 180% at 20% 20%, rgba(255,255,255,.20), transparent 55%),
    linear-gradient(135deg, rgba(79,70,229,.70), rgba(34,211,238,.35));
  border: 1px solid rgba(255,255,255,.18);
  color: #fff;
  margin-left: 4px;
}

/* ===== Cards ===== */
.card{
  width: 100%;
  border-radius: var(--r20);
  border: 1px solid rgba(255,255,255,.10);
  background:
    radial-gradient(140% 220% at 10% 0%, rgba(255,255,255,.10), transparent 60%),
    linear-gradient(180deg, rgba(255,255,255,.05), rgba(255,255,255,.03));
  box-shadow: var(--shadow);
  overflow:hidden;
}
.card-header{
  display:flex;
  align-items:center;
  justify-content: space-between;
  gap: 12px;
  padding: 12px 14px;
  border-bottom: 1px solid rgba(255,255,255,.08);
  background: rgba(255,255,255,.03);
}
.card-header h3{
  margin:0;
  font-size: 15px;
  font-weight: 950;
  display:flex;
  align-items:center;
  gap: 10px;
}
.card-body{ padding: 12px 14px 14px; }

/* content grid (activity tab) */
.content-grid{
  width: 100%;
  display:grid;
  grid-template-columns: 1fr 1fr;
  gap: 12px;
}

/* ===== Achievements Grid ===== */
.achievements-grid{
  display:grid;
  grid-template-columns: repeat(auto-fill, minmax(150px, 1fr));
  gap: 10px;
}

.achievement-item{
  border-radius: 18px;
  border: 1px solid rgba(255,255,255,.10);
  background: rgba(255,255,255,.03);
  padding: 12px;
  text-align:center;
  transition: transform .18s ease, box-shadow .18s ease, border-color .18s ease;
  overflow:hidden;
}
.achievement-item:hover{
  transform: translateY(-2px);
  box-shadow: 0 16px 40px rgba(0,0,0,.35);
  border-color: rgba(255,255,255,.14);
}

.achievement-item.earned{
  border-color: rgba(52,211,153,.22);
  background:
    radial-gradient(120% 180% at 20% 0%, rgba(52,211,153,.12), transparent 65%),
    rgba(255,255,255,.03);
}
.achievement-item.locked{
  opacity: .92;
}

.achievement-icon{
  width: 54px;
  height: 54px;
  border-radius: 16px;
  display:grid;
  place-items:center;
  margin: 0 auto 8px;
  border: 1px solid rgba(255,255,255,.18);
  box-shadow: 0 14px 26px rgba(0,0,0,.20);
}
.achievement-title{ font-weight: 950; font-size: 13px; line-height: 1.2; }
.achievement-points{ margin-top: 4px; font-size: 12px; color: rgba(251,191,36,.95); font-weight: 950; }
.achievement-date{ margin-top: 6px; font-size: 11px; color: var(--muted2); }

/* progress bar inside achievement */
.achievement-progress{
  height: 8px;
  width: 100%;
  border-radius: 999px;
  background: rgba(255,255,255,.07);
  border: 1px solid rgba(255,255,255,.08);
  overflow:hidden;
  margin: 10px 0 6px;
}
.progress-fill{
  height:100%;
  width:0%;
  border-radius: 999px;
  background: linear-gradient(90deg, rgba(34,211,238,.95), rgba(79,70,229,.95));
  transition: width 1s cubic-bezier(.22,.75,.12,1);
}
.progress-fill.earned,
.progress-fill.completed{
  background: rgba(52,211,153,.95);
}
.progress-text{ font-size: 11px; color: var(--muted2); font-weight: 800; }

/* ===== Activity ===== */
.activity-list{
  display:flex;
  flex-direction: column;
  gap: 10px;
}
.activity-item{
  display:flex;
  gap: 12px;
  align-items:flex-start;
  padding: 12px;
  border-radius: 16px;
  border: 1px solid rgba(255,255,255,.10);
  background: rgba(255,255,255,.03);
  transition: transform .18s ease, background .18s ease, border-color .18s ease;
}
.activity-item:hover{
  transform: translateX(2px);
  border-color: rgba(255,255,255,.14);
  background: rgba(255,255,255,.04);
}
.activity-item.achievement{ border-left: 4px solid rgba(251,191,36,.85); }

.activity-icon{
  width: 42px;
  height: 42px;
  border-radius: 14px;
  display:grid;
  place-items:center;
  border: 1px solid rgba(255,255,255,.12);
  background: rgba(255,255,255,.05);
  color: rgba(251,191,36,.95);
  flex-shrink:0;
}
.activity-title{ font-weight: 950; font-size: 13px; }
.activity-message{ font-size: 12.5px; color: var(--muted); margin-top: 2px; }
.activity-time{ font-size: 11px; color: var(--muted2); margin-top: 6px; }

/* ===== Leaderboard ===== */
.leaderboard-list{
  display:flex;
  flex-direction: column;
  gap: 10px;
}
.leaderboard-item{
  display:flex;
  align-items:center;
  gap: 12px;
  padding: 12px;
  border-radius: 16px;
  border: 1px solid rgba(255,255,255,.10);
  background: rgba(255,255,255,.03);
  transition: transform .18s ease, background .18s ease, border-color .18s ease;
}
.leaderboard-item:hover{
  transform: translateX(2px);
  border-color: rgba(255,255,255,.14);
  background: rgba(255,255,255,.04);
}
.leaderboard-rank{
  width: 34px;
  height: 34px;
  border-radius: 14px;
  display:grid;
  place-items:center;
  font-weight: 950;
  border: 1px solid rgba(255,255,255,.12);
  background: rgba(255,255,255,.05);
  color: rgba(234,240,255,.92);
}
.leaderboard-rank.gold{ color: rgba(251,191,36,.95); }
.leaderboard-rank.silver{ color: rgba(156,163,175,.95); }
.leaderboard-rank.bronze{ color: rgba(249,115,22,.95); }

.leaderboard-avatar{
  width: 42px;
  height: 42px;
  border-radius: 16px;
  object-fit: cover;
  border: 1px solid rgba(255,255,255,.14);
  box-shadow: 0 12px 26px rgba(0,0,0,.18);
}
.leaderboard-avatar.default{
  display:grid;
  place-items:center;
  font-weight: 950;
  color: var(--text);
  background:
    radial-gradient(120% 140% at 30% 25%, rgba(255,255,255,.18), transparent 55%),
    linear-gradient(135deg, rgba(34,211,238,.55), rgba(79,70,229,.55));
}
.leaderboard-name{ font-weight: 950; font-size: 13px; }
.leaderboard-stats{
  margin-top: 3px;
  display:flex;
  gap: 10px;
  font-size: 11.5px;
  color: var(--muted);
  font-weight: 800;
}

/* empty */
.empty-state{
  text-align:center;
  padding: 22px 14px;
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

/* ===== Responsive ===== */
@media (max-width: 1000px){
  .stats-grid{ grid-template-columns: repeat(2, minmax(0, 1fr)); }
  .content-grid{ grid-template-columns: 1fr; }
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

  .main-content{ padding: 18px 14px 28px; }
  .tabs{ overflow-x:auto; }
  .tabs::-webkit-scrollbar{ height: 8px; }
  .tabs::-webkit-scrollbar-thumb{ background: rgba(255,255,255,.14); border-radius: 99px; }
}

@media (max-width: 520px){
  .stats-grid{ grid-template-columns: 1fr; }
  .achievements-grid{ grid-template-columns: repeat(2, minmax(0,1fr)); }
  .user-info p{ max-width: 160px; }
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
                <div class="logo"><i class="fas fa-star"></i> ProgressMate</div>
                <button class="sidebar-close" id="sidebarClose"><i class="fas fa-times"></i></button>
            </div>

            <div class="user-profile">
                <?php if (!empty($_SESSION['profile_picture'])): ?>
                    <img src="<?php echo '../' . htmlspecialchars($_SESSION['profile_picture']); ?>" alt="Profile" class="profile-pic">
                <?php else: ?>
                    <div class="profile-pic default"><?php echo strtoupper(substr($_SESSION['name'], 0, 1)); ?></div>
                <?php endif; ?>
                <div class="user-info">
                    <h4><?php echo htmlspecialchars($_SESSION['name']); ?></h4>
                    <p><?php echo htmlspecialchars($_SESSION['email']); ?></p>
                    <span class="user-tag">STUDENT</span>
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
                    <div class="sidebar-stat-icon"><i class="fas fa-bullseye"></i></div>
                    <div>
                        <div class="sidebar-stat-label">Goals</div>
                        <div class="sidebar-stat-number"><?php echo $completed_goals; ?>/<?php echo $total_goals; ?></div>
                    </div>
                </div>
                <div class="sidebar-stat">
                    <div class="sidebar-stat-icon"><i class="fas fa-star"></i></div>
                    <div>
                        <div class="sidebar-stat-label">Points</div>
                        <div class="sidebar-stat-number"><?php echo $total_points; ?></div>
                    </div>
                </div>
                <div class="sidebar-stat">
                    <div class="sidebar-stat-icon"><i class="fas fa-fire"></i></div>
                    <div>
                        <div class="sidebar-stat-label">Streak</div>
                        <div class="sidebar-stat-number"><?php echo $streak; ?> days</div>
                    </div>
                </div>
            </div>

            <div class="sidebar-footer">
                <a href="../logout.php" class="logout-btn"><i class="fas fa-sign-out-alt"></i> Logout</a>
            </div>
        </aside>

        <!-- MAIN CONTENT -->
        <main class="main-content">
            <header class="page-header">
                <div class="header-content">
                    <h1>My Achievements</h1>
                    <p>Track your progress and celebrate your accomplishments</p>
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
                    <div class="stat-number"><?php echo count($earned_achievements); ?></div>
                    <div class="stat-label">Achievements Earned</div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon"><i class="fas fa-star"></i></div>
                    <div class="stat-number"><?php echo $total_points; ?></div>
                    <div class="stat-label">Total Points</div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon"><i class="fas fa-bullseye"></i></div>
                    <div class="stat-number"><?php echo $completed_goals; ?></div>
                    <div class="stat-label">Goals Completed</div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon"><i class="fas fa-fire"></i></div>
                    <div class="stat-number"><?php echo $streak; ?> days</div>
                    <div class="stat-label">Current Streak</div>
                </div>
            </div>

            <!-- Tabs -->
            <div class="tabs">
                <button class="tab active" data-tab="earned">
                    <i class="fas fa-unlock"></i>
                    Earned Achievements = <span class="tab-count"><?php echo count($earned_achievements); ?></span>
                </button>
                <button class="tab" data-tab="progress">
                    <i class="fas fa-bullseye"></i>
                    In Progress = <span class="tab-count"><?php echo count($progress_achievements); ?></span>
                </button>
                <button class="tab" data-tab="activity">
                    <i class="fas fa-history"></i> Recent Activity
                </button>
            </div>

            <!-- Tab 1: Earned Achievements -->
            <div class="tab-content active" id="tab-earned">
                <div class="card">
                    <div class="card-header">
                        <h3><i class="fas fa-unlock"></i> Your Earned Achievements</h3>
                        <span style="color: var(--gray-dark); font-size: 14px;">
                            <?php echo count($earned_achievements); ?> achievements earned • <?php echo $total_points; ?> total points
                        </span>
                    </div>
                    <div class="card-body">
                        <?php if (empty($earned_achievements)): ?>
                            <div class="empty-state">
                                <i class="fas fa-trophy"></i>
                                <p>No achievements earned yet</p>
                                <p style="font-size: 14px; margin-top: 10px;">Complete goals to earn achievements!</p>
                                <a href="goals.php" class="btn" style="background: var(--primary); color: white; padding: 10px 20px; border-radius: 8px; margin-top: 15px; display: inline-block;">
                                    <i class="fas fa-bullseye"></i> View Goals
                                </a>
                            </div>
                        <?php else: ?>
                            <div class="achievements-grid">
                                <?php foreach ($earned_achievements as $achievement): ?>
                                    <div class="achievement-item earned">
                                        <div class="achievement-icon" style="background: <?php echo htmlspecialchars($achievement['color']); ?>;">
                                            <i class="fas fa-<?php echo htmlspecialchars($achievement['icon']); ?>"></i>
                                        </div>
                                        <div class="achievement-title"><?php echo htmlspecialchars($achievement['title']); ?></div>
                                        <div class="achievement-points">+<?php echo $achievement['points']; ?> points</div>
                                        <div class="achievement-date">Earned: <?php echo date('M d, Y', strtotime($achievement['earned_at'])); ?></div>
                                        <?php if ($achievement['description']): ?>
                                            <div style="font-size: 0.75rem; color: var(--gray-dark); margin-top: 5px;">
                                                <?php echo htmlspecialchars($achievement['description']); ?>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Tab 2: Achievement Progress -->
            <div class="tab-content" id="tab-progress">
                <div class="card">
                    <div class="card-header">
                        <h3><i class="fas fa-bullseye"></i> Achievements in Progress</h3>
                        <span style="color: var(--gray-dark); font-size: 14px;">
                            <?php echo count($progress_achievements); ?> achievements to unlock
                        </span>
                    </div>
                    <div class="card-body">
                        <?php if (empty($progress_achievements)): ?>
                            <div class="empty-state">
                                <i class="fas fa-flag-checkered"></i>
                                <p>All available achievements earned!</p>
                                <p style="font-size: 14px; margin-top: 10px;">Check back later for new achievements</p>
                            </div>
                        <?php else: ?>
                            <div class="achievements-grid">
                                <?php foreach ($progress_achievements as $progress):
                                    $achievement = $progress['achievement'];
                                ?>
                                    <div class="achievement-item locked">
                                        <div class="achievement-icon" style="background: <?php echo htmlspecialchars($achievement['color']); ?>; opacity: 0.7;">
                                            <i class="fas fa-<?php echo htmlspecialchars($achievement['icon']); ?>"></i>
                                        </div>
                                        <div class="achievement-title"><?php echo htmlspecialchars($achievement['title']); ?></div>
                                        <div class="achievement-points">+<?php echo $achievement['points']; ?> points</div>

                                        <!-- Progress Bar -->
                                        <div class="achievement-progress">
                                            <div class="progress-fill" style="width: <?php echo $progress['percentage']; ?>%"></div>
                                        </div>
                                        <div class="progress-text">
                                            <?php echo $progress['current_value']; ?>/<?php echo $progress['target_value']; ?>
                                            (<?php echo $progress['percentage']; ?>%)
                                        </div>

                                        <?php if ($achievement['criteria_type'] == 'total_completed_goals'): ?>
                                            <div style="font-size: 0.7rem; color: var(--gray-dark); margin-top: 5px;">
                                                Complete <?php echo $progress['remaining']; ?> more goal(s)
                                            </div>
                                        <?php elseif ($achievement['criteria_type'] == 'total_points'): ?>
                                            <div style="font-size: 0.7rem; color: var(--gray-dark); margin-top: 5px;">
                                                Earn <?php echo $progress['remaining']; ?> more points
                                            </div>
                                        <?php elseif ($achievement['criteria_type'] == 'login_streak'): ?>
                                            <div style="font-size: 0.7rem; color: var(--gray-dark); margin-top: 5px;">
                                                Login for <?php echo $progress['remaining']; ?> more day(s)
                                            </div>
                                        <?php elseif ($achievement['description']): ?>
                                            <div style="font-size: 0.7rem; color: var(--gray-dark); margin-top: 5px;">
                                                <?php echo htmlspecialchars($achievement['description']); ?>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Tab 3: Recent Activity -->
            <div class="tab-content" id="tab-activity">
                    <!-- Recent Achievement Unlocks -->
                    <div class="card">
                        <div class="card-header">
                            <h3><i class="fas fa-history"></i> Recent Achievement Activity</h3>
                            <span style="color:var(--muted); font-size:13px;"><?php echo count($recent_activity); ?> recent events</span>
                        </div>
                        <div class="card-body">
                            <?php if (empty($recent_activity)): ?>
                                <div class="empty-state">
                                    <i class="fas fa-bell-slash"></i>
                                    <p>No recent achievement activity yet. Complete goals to unlock achievements!</p>
                                </div>
                            <?php else: ?>
                                <div class="activity-list">
                                    <?php foreach ($recent_activity as $activity): ?>
                                        <div class="activity-item achievement">
                                            <div class="activity-icon">
                                                <i class="fas fa-trophy"></i>
                                            </div>
                                            <div class="activity-content">
                                                <div class="activity-title"><?php echo htmlspecialchars($activity['title']); ?></div>
                                                <div class="activity-message"><?php echo htmlspecialchars($activity['message']); ?></div>
                                                <div class="activity-time"><?php echo $activity['formatted_date']; ?></div>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
            </div>

            <!-- Tips Section -->
            <div style="background: white; border-radius: 12px; padding: 20px; margin-top: 30px; box-shadow: 0 2px 10px rgba(0,0,0,0.05);">
                <h3 style="margin-bottom: 15px; color: #111827; display: flex; align-items: center; gap: 10px;">
                    <i class="fas fa-lightbulb" style="color: #f59e0b;"></i>
                    How to Earn More Achievements
                </h3>
                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 15px;">
                    <div style="background: #f9fafb; padding: 15px; border-radius: 8px;">
                        <div style="font-weight: 500; color: #111827; margin-bottom: 5px; display: flex; align-items: center; gap: 8px;">
                            <i class="fas fa-bullseye" style="color: var(--primary);"></i> Complete Goals
                        </div>
                        <div style="font-size: 13px; color: #6b7280;">Finish your assigned and personal goals</div>
                    </div>
                    <div style="background: #f9fafb; padding: 15px; border-radius: 8px;">
                        <div style="font-weight: 500; color: #111827; margin-bottom: 5px; display: flex; align-items: center; gap: 8px;">
                            <i class="fas fa-fire" style="color: var(--warning);"></i> Maintain Streak
                        </div>
                        <div style="font-size: 13px; color: #6b7280;">Log in daily to keep your streak alive</div>
                    </div>
                    <div style="background: #f9fafb; padding: 15px; border-radius: 8px;">
                        <div style="font-weight: 500; color: #111827; margin-bottom: 5px; display: flex; align-items: center; gap: 8px;">
                            <i class="fas fa-calendar-check" style="color: var(--success);"></i> Be Consistent
                        </div>
                        <div style="font-size: 13px; color: #6b7280;">Work on goals regularly to earn more points</div>
                    </div>
                    <div style="background: #f9fafb; padding: 15px; border-radius: 8px;">
                        <div style="font-weight: 500; color: #111827; margin-bottom: 5px; display: flex; align-items: center; gap: 8px;">
                            <i class="fas fa-star" style="color: var(--purple);"></i> Earn Points
                        </div>
                        <div style="font-size: 13px; color: #6b7280;">Accumulate points through achievements</div>
            </div>
        </main>
    </div>

    <script>
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
                document.getElementById('tab-' + tabId).classList.add('active');
            });
        });

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

        // Auto-hide sidebar on mobile when clicking outside
        document.addEventListener('click', function(event) {
            if (window.innerWidth <= 768 &&
                sidebar && sidebar.classList.contains('active') &&
                !sidebar.contains(event.target) &&
                !sidebarToggle.contains(event.target)) {
                closeSidebar();
            }
        });

        // Achievement hover effect
        document.querySelectorAll('.achievement-item').forEach(item => {
            item.addEventListener('mouseenter', function() {
                this.style.transform = 'translateY(-6px) scale(1.02)';
            });
            item.addEventListener('mouseleave', function() {
                this.style.transform = 'translateY(0) scale(1)';
            });
        });

        // Add subtle animation to stats cards
        document.querySelectorAll('.stat-card').forEach((card, index) => {
            card.style.animationDelay = (index * 100) + 'ms';
        });
    </script>
</body>

</html>