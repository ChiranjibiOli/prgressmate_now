<?php
session_start();
require_once '../includes/db_connection.php';
require_once '../includes/functions.php';
require_once '../api/_helpers.php';
$csrf = csrfToken();
ini_set('display_errors',0); error_reporting(E_ALL);
checkAuth('student');

$student_id = $_SESSION['user_id'];

// Auto-mark overdue
$pdo->prepare("UPDATE student_goals SET status='overdue'
    WHERE student_id=? AND deleted_at IS NULL AND status!='completed'
    AND due_date IS NOT NULL AND due_date < CURDATE()")->execute([$student_id]);

$success=''; $error='';
if(isset($_SESSION['success'])){ $success=$_SESSION['success']; unset($_SESSION['success']); }
if(isset($_SESSION['error'])){ $error=$_SESSION['error']; unset($_SESSION['error']); }

$total_goals     = getStat($pdo,"SELECT COUNT(*) FROM student_goals WHERE student_id=? AND deleted_at IS NULL",[$student_id]);
$completed_goals = getStat($pdo,"SELECT COUNT(*) FROM student_goals WHERE student_id=? AND status='completed' AND deleted_at IS NULL",[$student_id]);
$in_progress_goals=getStat($pdo,"SELECT COUNT(*) FROM student_goals WHERE student_id=? AND status='in_progress' AND deleted_at IS NULL",[$student_id]);
$total_points    = getStat($pdo,"SELECT COALESCE(points,0) FROM users WHERE id=?",[$student_id]);
$streak          = getStat($pdo,"SELECT COALESCE(current_streak,0) FROM users WHERE id=?",[$student_id]);
$unread          = getStat($pdo,"SELECT COUNT(*) FROM notifications WHERE user_id=? AND is_read=0 AND deleted_at IS NULL",[$student_id]);

// Fetch ACTIVE goals (not completed)
$stmt = $pdo->prepare("SELECT * FROM student_goals WHERE student_id=? AND deleted_at IS NULL
    AND status != 'completed'
    ORDER BY CASE priority WHEN 'high' THEN 1 WHEN 'medium' THEN 2 WHEN 'low' THEN 3 ELSE 4 END,
             due_date ASC, created_at DESC");
$stmt->execute([$student_id]);
$active_goals = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch COMPLETED goals
$stmt2 = $pdo->prepare("SELECT * FROM student_goals WHERE student_id=? AND deleted_at IS NULL
    AND status='completed'
    ORDER BY updated_at DESC");
$stmt2->execute([$student_id]);
$completed_list = $stmt2->fetchAll(PDO::FETCH_ASSOC);

$cats_stmt = $pdo->prepare("SELECT DISTINCT category FROM student_goals
    WHERE student_id=? AND deleted_at IS NULL AND category IS NOT NULL");
$cats_stmt->execute([$student_id]);
$categories = $cats_stmt->fetchAll(PDO::FETCH_COLUMN);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>My Goals — ProgressMate</title>
<?php require_once '../includes/student_nav.php'; nav_head(); ?>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<style>
/* Page header */
.page-header{
  width:100%;display:flex;align-items:flex-start;justify-content:space-between;
  gap:14px;flex-wrap:wrap;padding:16px;border-radius:var(--r20);
  border:1px solid var(--border);
  background:radial-gradient(120% 220% at 15% 10%,rgba(255,255,255,.10),transparent 55%),
             linear-gradient(180deg,rgba(255,255,255,.05),rgba(255,255,255,.03));
  box-shadow:var(--shadow2);margin-bottom:14px;
}
.header-content h1{margin:0 0 4px;font-size:24px;font-weight:950;}
.header-content p{margin:0;color:var(--muted);font-size:14px;}
.header-actions{display:flex;align-items:center;gap:10px;flex-wrap:wrap;}

/* Buttons */
.btn{display:inline-flex;align-items:center;justify-content:center;gap:10px;
  padding:12px 14px;border-radius:14px;font-weight:900;border:1px solid rgba(255,255,255,.14);
  color:var(--text);background:rgba(255,255,255,.05);cursor:pointer;
  transition:transform .18s,box-shadow .18s,background .18s,border-color .18s;white-space:nowrap;}
.btn:hover{transform:translateY(-1px);background:rgba(255,255,255,.07);box-shadow:0 12px 30px rgba(79,70,229,.18);}
.btn-primary{border-color:rgba(79,70,229,.35);
  background:radial-gradient(120% 180% at 20% 10%,rgba(255,255,255,.16),transparent 55%),
             linear-gradient(135deg,rgba(79,70,229,.62),rgba(34,211,238,.18));}
.btn-secondary{background:rgba(255,255,255,.04);}
.btn-danger{border-color:rgba(251,113,133,.35);
  background:radial-gradient(120% 180% at 20% 10%,rgba(255,255,255,.16),transparent 55%),
             linear-gradient(135deg,rgba(251,113,133,.55),rgba(79,70,229,.10));}
.btn-sm{padding:8px 12px;border-radius:12px;font-size:12.5px;font-weight:900;}
.btn-view{border-color:rgba(34,211,238,.30);
  background:radial-gradient(120% 180% at 20% 10%,rgba(255,255,255,.12),transparent 55%),
             linear-gradient(135deg,rgba(34,211,238,.25),rgba(79,70,229,.15));}

/* Alerts */
.alert{width:100%;margin-top:12px;padding:12px 14px;border-radius:16px;
  border:1px solid rgba(255,255,255,.10);background:rgba(255,255,255,.03);
  display:flex;align-items:center;gap:10px;}
.alert i{width:34px;height:34px;display:grid;place-items:center;border-radius:12px;
  background:rgba(255,255,255,.05);border:1px solid rgba(255,255,255,.10);}
.alert-success{border-color:rgba(52,211,153,.25);}
.alert-success i{color:var(--success);}
.alert-error{border-color:rgba(251,113,133,.25);}
.alert-error i{color:var(--danger);}

/* Stats */
.stats-grid{width:100%;margin-bottom:14px;display:grid;
  grid-template-columns:repeat(4,minmax(0,1fr));gap:12px;}
.stat-card{border-radius:var(--r20);border:1px solid rgba(255,255,255,.12);
  background:radial-gradient(120% 180% at 10% 0%,rgba(255,255,255,.12),transparent 60%),
             linear-gradient(180deg,rgba(255,255,255,.05),rgba(255,255,255,.03));
  box-shadow:var(--shadow2);}
.stat-content{display:flex;align-items:center;gap:12px;padding:14px;}
.stat-icon{width:44px;height:44px;border-radius:16px;display:grid;place-items:center;
  border:1px solid rgba(255,255,255,.16);
  background:radial-gradient(120% 180% at 20% 15%,rgba(255,255,255,.20),transparent 55%),
             linear-gradient(135deg,rgba(34,211,238,.40),rgba(79,70,229,.40));
  box-shadow:0 16px 30px rgba(0,0,0,.22);}
.stat-number{font-size:24px;font-weight:950;line-height:1.1;}
.stat-label{margin-top:2px;font-size:13px;color:var(--muted);}

/* Section divider */
.section-divider{
  width:100%;display:flex;align-items:center;gap:14px;
  font-size:17px;font-weight:900;color:var(--text);
  padding-bottom:10px;margin:6px 0 10px;
  border-bottom:1px solid var(--border2);
}
.section-divider i{color:var(--warning);}
.section-divider .section-count{
  font-size:12px;font-weight:700;padding:3px 10px;border-radius:999px;
  background:rgba(255,255,255,.08);border:1px solid var(--border);color:var(--muted);margin-left:4px;
}

/* Goals grid */
.goals-grid{width:100%;display:grid;
  grid-template-columns:repeat(auto-fill,minmax(340px,1fr));gap:12px;margin-bottom:24px;}

/* Goal card */
.goal-card{border-radius:var(--r20);border:1px solid rgba(255,255,255,.10);
  background:radial-gradient(140% 220% at 10% 0%,rgba(255,255,255,.10),transparent 60%),
             linear-gradient(180deg,rgba(255,255,255,.05),rgba(255,255,255,.03));
  box-shadow:var(--shadow);overflow:hidden;display:flex;flex-direction:column;
  transition:transform .18s,box-shadow .18s,border-color .18s;}
.goal-card:hover{transform:translateY(-2px);
  box-shadow:0 0 0 1px rgba(255,255,255,.08),0 18px 50px rgba(0,0,0,.35);
  border-color:rgba(255,255,255,.14);}
.goal-card.high-priority{border-left:4px solid rgba(251,113,133,.85);}
.goal-card.medium-priority{border-left:4px solid rgba(251,191,36,.85);}
.goal-card.low-priority{border-left:4px solid rgba(52,211,153,.85);}

/* Completed card style */
.goal-card.completed-card{
  border-color:rgba(52,211,153,.22);opacity:.90;
  background:radial-gradient(140% 220% at 10% 0%,rgba(52,211,153,.10),transparent 65%),
             linear-gradient(180deg,rgba(255,255,255,.04),rgba(255,255,255,.02));
}
.goal-card.completed-card:hover{opacity:1;}

/* Compact completed row */
.completed-row{
  display:flex;align-items:center;justify-content:space-between;
  gap:12px;padding:14px 16px;flex-wrap:wrap;
}
.completed-left{display:flex;align-items:center;gap:12px;min-width:0;}
.completed-check{
  width:36px;height:36px;border-radius:12px;display:grid;place-items:center;flex-shrink:0;
  background:rgba(52,211,153,.15);border:1px solid rgba(52,211,153,.30);
  color:rgba(52,211,153,.95);font-size:14px;
}
.completed-right{display:flex;align-items:center;gap:10px;flex-shrink:0;flex-wrap:wrap;}

/* Locked overlay on completed cards */
.completed-lock{
  display:inline-flex;align-items:center;gap:6px;
  font-size:11px;font-weight:800;color:rgba(52,211,153,.90);
  background:rgba(52,211,153,.12);border:1px solid rgba(52,211,153,.25);
  padding:4px 12px;border-radius:999px;white-space:nowrap;
}

/* goal header */
.goal-header{padding:12px 14px;border-bottom:1px solid rgba(255,255,255,.08);
  background:rgba(255,255,255,.03);display:flex;justify-content:space-between;
  align-items:flex-start;gap:12px;}
.goal-title{font-weight:950;font-size:15px;line-height:1.2;margin-bottom:6px;}
.goal-category{display:inline-flex;align-items:center;gap:8px;padding:4px 10px;
  font-size:12px;font-weight:900;border-radius:999px;color:var(--text);
  border:1px solid rgba(255,255,255,.12);
  background:radial-gradient(120% 180% at 20% 20%,rgba(255,255,255,.16),transparent 55%),
             rgba(255,255,255,.03);}
.status-completed{font-size:12px;font-weight:950;padding:4px 10px;border-radius:999px;
  border:1px solid rgba(52,211,153,.25);color:rgba(52,211,153,.95);background:rgba(52,211,153,.10);white-space:nowrap;}
.status-in_progress{font-size:12px;font-weight:950;padding:4px 10px;border-radius:999px;
  border:1px solid rgba(34,211,238,.25);color:rgba(34,211,238,.95);background:rgba(34,211,238,.10);white-space:nowrap;}
.status-pending{font-size:12px;font-weight:950;padding:4px 10px;border-radius:999px;
  border:1px solid rgba(234,240,255,.18);color:rgba(234,240,255,.85);background:rgba(255,255,255,.04);white-space:nowrap;}
.status-overdue{font-size:12px;font-weight:950;padding:4px 10px;border-radius:999px;
  border:1px solid rgba(251,113,133,.25);color:rgba(251,113,133,.95);background:rgba(251,113,133,.10);white-space:nowrap;}
.goal-header-actions{display:flex;align-items:center;gap:10px;}

/* dropdown (3-dot) */
.dropdown{position:relative;}
.dropdown-toggle{width:36px;height:36px;display:grid;place-items:center;
  border-radius:12px;border:1px solid rgba(255,255,255,.12);
  background:rgba(255,255,255,.04);color:rgba(234,240,255,.85);cursor:pointer;}
.dropdown-toggle:hover{background:rgba(255,255,255,.07);}
.dropdown-menu{display:none;position:absolute;right:0;top:calc(100% + 8px);
  min-width:170px;border-radius:14px;border:1px solid rgba(255,255,255,.14);
  background:rgba(10,14,35,.95);box-shadow:0 18px 40px rgba(0,0,0,.50);
  overflow:hidden;z-index:30;backdrop-filter:blur(12px);}
.dropdown-item{display:flex;align-items:center;gap:10px;padding:10px 14px;
  font-size:13px;font-weight:800;color:rgba(234,240,255,.92);
  border-bottom:1px solid rgba(255,255,255,.07);cursor:pointer;}
.dropdown-item:last-child{border-bottom:none;}
.dropdown-item:hover{background:rgba(79,70,229,.18);}
.dropdown-item.locked-item{color:#64748b;cursor:default;}
.dropdown-item.locked-item:hover{background:none;}
.dropdown-item.text-danger{color:rgba(251,113,133,.95);}

/* goal body */
.goal-body{padding:12px 14px 14px;}
.goal-description{color:rgba(234,240,255,.84);font-size:13px;line-height:1.55;margin-bottom:12px;}
.goal-meta{display:flex;flex-wrap:wrap;gap:10px 14px;margin-bottom:12px;}
.goal-meta-item{display:flex;align-items:center;gap:8px;color:var(--muted);font-size:12.5px;font-weight:700;}
.goal-meta-item i{color:rgba(96,165,250,.95);}

/* progress */
.progress-section{margin-top:6px;}
.progress-header{display:flex;justify-content:space-between;align-items:center;margin-bottom:8px;}
.progress-label{color:var(--muted);font-size:12.5px;font-weight:800;}
.progress-percentage{color:rgba(96,165,250,.95);font-weight:950;font-size:13px;}
.progress-bar{height:10px;width:100%;border-radius:999px;background:rgba(255,255,255,.07);
  border:1px solid rgba(255,255,255,.08);overflow:hidden;margin:8px 0 6px;}
.progress-fill{height:100%;width:0%;border-radius:999px;
  background:linear-gradient(90deg,rgba(34,211,238,.95),rgba(79,70,229,.95));
  box-shadow:0 10px 25px rgba(34,211,238,.16);transition:width 1s cubic-bezier(.22,.75,.12,1);}
.progress-fill.full{background:linear-gradient(90deg,rgba(52,211,153,.90),rgba(34,211,238,.80));}
.progress-stats{display:flex;justify-content:space-between;font-size:12px;color:var(--muted2);font-weight:800;}
.progress-chart{height:70px;margin-top:10px;}
.progress-chart canvas{width:100%!important;height:70px!important;}

/* footer */
.goal-footer{padding:12px 14px;border-top:1px solid rgba(255,255,255,.08);
  background:rgba(255,255,255,.03);display:flex;gap:10px;}
.goal-footer .btn{flex:1;}

/* View modal */
.modal-overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,.60);
  z-index:2200;align-items:center;justify-content:center;padding:18px;backdrop-filter:blur(4px);}
.modal{width:100%;max-width:540px;border-radius:20px;
  border:1px solid rgba(255,255,255,.14);
  background:radial-gradient(120% 200% at 15% 0%,rgba(79,70,229,.18),transparent 60%),
             linear-gradient(180deg,rgba(10,14,35,.96),rgba(10,14,35,.88));
  box-shadow:0 26px 70px rgba(0,0,0,.60);overflow:hidden;}
.modal-header{padding:14px 16px;border-bottom:1px solid rgba(255,255,255,.10);
  display:flex;align-items:center;justify-content:space-between;
  background:rgba(255,255,255,.03);}
.modal-header h3{margin:0;font-size:16px;font-weight:950;}
.modal-close{width:38px;height:38px;border-radius:12px;border:1px solid rgba(255,255,255,.14);
  background:rgba(255,255,255,.04);color:var(--text);cursor:pointer;
  display:grid;place-items:center;font-size:18px;}
.modal-body{padding:16px;}
.modal-section{margin-bottom:14px;}
.modal-label{font-size:11px;font-weight:800;color:var(--muted2);text-transform:uppercase;
  letter-spacing:.4px;margin-bottom:4px;}
.modal-value{font-size:14px;font-weight:700;}
.modal-grid{display:grid;grid-template-columns:1fr 1fr;gap:12px;}

/* Update progress modal */
.form-group{margin-bottom:12px;}
.form-group label{display:block;margin-bottom:6px;font-size:12.5px;font-weight:900;color:rgba(234,240,255,.90);}
.form-group input,.form-group select,.form-group textarea{
  width:100%;padding:12px;border-radius:14px;border:1px solid rgba(255,255,255,.12);
  background:rgba(255,255,255,.04);color:var(--text);outline:none;font-family:inherit;}
.form-group textarea{resize:vertical;min-height:90px;}
.form-row{display:grid;grid-template-columns:1fr 1fr;gap:12px;}
.modal-actions{display:flex;gap:10px;justify-content:flex-end;margin-top:14px;}

/* Delete modal */
.text-danger{color:rgba(251,113,133,.95);}

/* Empty state */
.empty-state{grid-column:1/-1;text-align:center;padding:22px 14px;color:var(--muted);
  border-radius:var(--r20);border:1px solid rgba(255,255,255,.10);background:rgba(255,255,255,.03);}
.empty-state i{display:inline-grid;place-items:center;width:52px;height:52px;
  border-radius:18px;margin-bottom:10px;border:1px solid rgba(255,255,255,.12);
  background:rgba(255,255,255,.05);}

/* focus */
a:focus-visible,button:focus-visible,input:focus-visible,textarea:focus-visible,select:focus-visible{
  box-shadow:0 0 0 3px rgba(34,211,238,.25),0 0 0 1px rgba(255,255,255,.10);
  border-radius:14px;outline:none;}

@media(max-width:1100px){
  .stats-grid{grid-template-columns:repeat(2,minmax(0,1fr));}
  .goals-grid{grid-template-columns:repeat(auto-fill,minmax(300px,1fr));}
  .form-row{grid-template-columns:1fr;}
  .modal-grid{grid-template-columns:1fr;}
}
@media(max-width:520px){
  .stats-grid{grid-template-columns:1fr;}
  .goals-grid{grid-template-columns:1fr;}
}
</style>
</head>
<body>
<?php nav_body(); ?>

  <!-- PAGE HEADER -->
  <header class="page-header">
    <div class="header-content">
      <h1>My Goals</h1>
      <p>Track and manage all your goals in one place</p>
    </div>
    <div class="header-actions">
      <button class="theme-btn" id="themeBtn">
        <div class="tgl-track" id="themeTrack"><div class="tgl-thumb"></div></div>
        <span id="themeLabel">Dark</span>
      </button>
      <a href="create_goal.php" class="btn btn-primary"><i class="fas fa-plus"></i> Create Goal</a>
    </div>
  </header>

  <?php if($success): ?><div class="alert alert-success"><i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($success); ?></div><?php endif; ?>
  <?php if($error): ?><div class="alert alert-error"><i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($error); ?></div><?php endif; ?>

  <!-- STATS -->
  <div class="stats-grid">
    <div class="stat-card"><div class="stat-content"><div class="stat-icon"><i class="fas fa-bullseye"></i></div><div><div class="stat-number"><?php echo $total_goals; ?></div><div class="stat-label">Total Goals</div></div></div></div>
    <div class="stat-card"><div class="stat-content"><div class="stat-icon"><i class="fas fa-check-circle"></i></div><div><div class="stat-number"><?php echo $completed_goals; ?></div><div class="stat-label">Completed</div></div></div></div>
    <div class="stat-card"><div class="stat-content"><div class="stat-icon"><i class="fas fa-spinner"></i></div><div><div class="stat-number"><?php echo $in_progress_goals; ?></div><div class="stat-label">In Progress</div></div></div></div>
    <div class="stat-card"><div class="stat-content"><div class="stat-icon"><i class="fas fa-star"></i></div><div><div class="stat-number"><?php echo $total_points; ?></div><div class="stat-label">Points</div></div></div></div>
  </div>

  <!-- ════ ACTIVE GOALS ════ -->
  <div class="section-divider">
    <i class="fas fa-fire"></i> Active Goals
    <span class="section-count"><?php echo count($active_goals); ?></span>
  </div>

  <div class="goals-grid">
    <?php if(empty($active_goals)): ?>
      <div class="empty-state">
        <i class="fas fa-bullseye" style="font-size:22px;"></i>
        <p>No active goals. <a href="create_goal.php" style="color:var(--info);">Create one!</a></p>
      </div>
    <?php else: ?>
      <?php foreach($active_goals as $goal):
        $pct      = round($goal['progress_percentage'], 1);
        $cur_val  = (float)$goal['current_value'];
        $tgt_val  = (float)$goal['target_value'];
        $remaining= round(max(0,$tgt_val-$cur_val),2);
        $isAdmin  = (int)$goal['is_admin_created']===1;
        $isSelf   = (int)($goal['is_self_created']??0)===1;
        $status   = $goal['status']??'pending';
        $canCrud  = (!$isAdmin && $isSelf);
        $days_left= null;
        if(!empty($goal['due_date'])){
          $diff     = (new DateTime())->diff(new DateTime($goal['due_date']));
          $days_left= (int)$diff->format('%r%a');
        }
        $is_overdue= ($status==='overdue');
      ?>
      <div class="goal-card <?php echo $goal['priority']; ?>-priority">
        <div class="goal-header">
          <div>
            <div class="goal-title"><?php echo htmlspecialchars($goal['title']); ?></div>
            <?php if($goal['category']): ?><span class="goal-category"><?php echo htmlspecialchars($goal['category']); ?></span><?php endif; ?>
          </div>
          <div class="goal-header-actions">
            <span class="status-<?php echo $status; ?>"><?php echo ucfirst(str_replace('_',' ',$status)); ?></span>
            <div class="dropdown">
              <button class="dropdown-toggle" type="button"><i class="fas fa-ellipsis-v"></i></button>
              <div class="dropdown-menu">
                <!-- View always available -->
                <a href="#" onclick="openViewModal(<?php echo $goal['id']; ?>);return false;" class="dropdown-item">
                  <i class="fas fa-eye"></i> View Details
                </a>
                <?php if($canCrud): ?>
                  <a href="#" onclick="openEditGoalModal(<?php echo $goal['id']; ?>);return false;" class="dropdown-item">
                    <i class="fas fa-edit"></i> Edit
                  </a>
                  <a href="#" onclick="openDeleteModal(<?php echo $goal['id']; ?>,'<?php echo addslashes($goal['title']); ?>');return false;" class="dropdown-item text-danger">
                    <i class="fas fa-trash"></i> Delete
                  </a>
                <?php else: ?>
                  <span class="dropdown-item locked-item"><i class="fas fa-lock"></i> Locked (Admin Goal)</span>
                <?php endif; ?>
              </div>
            </div>
          </div>
        </div>
        <div class="goal-body">
          <?php if($goal['description']): ?><div class="goal-description"><?php echo nl2br(htmlspecialchars($goal['description'])); ?></div><?php endif; ?>
          <div class="goal-meta">
            <div class="goal-meta-item"><i class="fas fa-flag"></i> <?php echo ucfirst($goal['priority']); ?></div>
            <?php if($goal['due_date']): ?>
              <div class="goal-meta-item"><i class="fas fa-calendar"></i>
                <?php if($is_overdue): ?><span style="color:#fb7185;">Overdue</span>
                <?php elseif($days_left==0): ?>Due today
                <?php else: ?><?php echo $days_left; ?> day<?php echo $days_left!=1?'s':''; ?> left<?php endif; ?>
              </div>
            <?php endif; ?>
            <?php if($goal['unit']): ?><div class="goal-meta-item"><i class="fas fa-ruler"></i> <?php echo htmlspecialchars($goal['unit']); ?></div><?php endif; ?>
          </div>
          <div class="progress-section">
            <div class="progress-header">
              <span class="progress-label">Progress</span>
              <span class="progress-percentage"><?php echo $pct; ?>%</span>
            </div>
            <div class="progress-bar"><div class="progress-fill" style="width:<?php echo $pct; ?>%"></div></div>
            <div class="progress-stats"><span><?php echo $cur_val; ?> / <?php echo $tgt_val; ?> <?php echo htmlspecialchars($goal['unit']); ?></span></div>
          </div>
          <div class="progress-chart"><canvas id="chart_<?php echo $goal['id']; ?>"></canvas></div>
        </div>
        <div class="goal-footer">
          <button class="btn btn-sm btn-primary"
            onclick="openUpdateProgressModal(<?php echo $goal['id']; ?>,'<?php echo addslashes($goal['title']); ?>',<?php echo $remaining; ?>)">
            <i class="fas fa-arrow-up"></i> Update Progress
          </button>
          <?php if($canCrud): ?>
          <button class="btn btn-sm btn-danger"
            onclick="openDeleteModal(<?php echo $goal['id']; ?>,'<?php echo addslashes($goal['title']); ?>')">
            <i class="fas fa-trash"></i> Delete
          </button>
          <?php else: ?>
          <button class="btn btn-sm btn-view" onclick="openViewModal(<?php echo $goal['id']; ?>)">
            <i class="fas fa-eye"></i> View
          </button>
          <?php endif; ?>
        </div>
      </div>
      <script>
        (function(){
          var ctx=document.getElementById('chart_<?php echo $goal['id']; ?>').getContext('2d');
          new Chart(ctx,{type:'line',data:{labels:['Start','Now','Target'],
            datasets:[{data:[0,<?php echo $cur_val; ?>,<?php echo $tgt_val; ?>],
              borderColor:'#4f46e5',backgroundColor:'rgba(79,70,229,.10)',fill:true,tension:.4}]},
            options:{responsive:true,maintainAspectRatio:false,
              plugins:{legend:{display:false}},
              scales:{x:{display:false},y:{display:false}}}});
        })();
      </script>
      <?php endforeach; ?>
    <?php endif; ?>
  </div>

  <!-- ════ COMPLETED GOALS ════ -->
  <?php if(!empty($completed_list)): ?>
  <div class="section-divider" style="margin-top:8px;">
    <i class="fas fa-check-circle" style="color:var(--success);"></i> Completed Goals
    <span class="section-count"><?php echo count($completed_list); ?></span>
  </div>

  <div class="goals-grid">
    <?php foreach($completed_list as $goal):
      $pct     = 100;
      $cur_val = (float)$goal['current_value'];
      $tgt_val = (float)$goal['target_value'];
    ?>
    <div class="goal-card completed-card">
      <div class="completed-row">
        <div class="completed-left">
          <div class="completed-check"><i class="fas fa-check"></i></div>
          <div>
            <div class="goal-title"><?php echo htmlspecialchars($goal['title']); ?></div>
            <?php if($goal['category']): ?><span class="goal-category"><?php echo htmlspecialchars($goal['category']); ?></span><?php endif; ?>
          </div>
        </div>
        <div class="completed-right">
          <span class="completed-lock"><i class="fas fa-lock"></i> Completed &amp; Locked</span>
          <button class="btn btn-sm btn-view" onclick="openViewModal(<?php echo $goal['id']; ?>)">
            <i class="fas fa-eye"></i> View Details
          </button>
        </div>
      </div>
    </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>

</main></div>

<!-- VIEW GOAL MODAL -->
<div class="modal-overlay" id="viewModal">
  <div class="modal">
    <div class="modal-header">
      <h3><i class="fas fa-eye" style="color:var(--info);margin-right:8px;"></i>Goal Details</h3>
      <button class="modal-close" onclick="document.getElementById('viewModal').style.display='none'">×</button>
    </div>
    <div class="modal-body" id="viewModalBody">Loading...</div>
  </div>
</div>

<!-- UPDATE PROGRESS MODAL -->
<div class="modal-overlay" id="updateProgressModal">
  <div class="modal">
    <div class="modal-header">
      <h3>Update Progress</h3>
      <button class="modal-close" onclick="document.getElementById('updateProgressModal').style.display='none'">×</button>
    </div>
    <div class="modal-body">
      <form id="progressForm">
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf); ?>">
        <input type="hidden" name="goal_id" id="modal_goal_id">
        <p><strong>Goal:</strong> <span id="modal_goal_title"></span></p>
        <p><strong>Remaining:</strong> <span id="modal_remaining"></span></p>
        <div class="form-group">
          <label>Progress Added</label>
          <input type="number" name="progress_value" step="0.01" min="0.01" required placeholder="e.g. 5">
        </div>
        <div class="form-group">
          <label>Notes (optional)</label>
          <textarea name="notes" rows="3" placeholder="What did you do?"></textarea>
        </div>
        <div class="modal-actions">
          <button type="button" class="btn btn-secondary" onclick="document.getElementById('updateProgressModal').style.display='none'">Cancel</button>
          <button type="submit" class="btn btn-primary">Save Progress</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- EDIT GOAL MODAL -->
<div class="modal-overlay" id="editGoalModal">
  <div class="modal">
    <div class="modal-header">
      <h3>Edit Goal</h3>
      <button class="modal-close" onclick="document.getElementById('editGoalModal').style.display='none'">×</button>
    </div>
    <div class="modal-body">
      <form method="POST" id="editGoalForm">
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf); ?>">
        <input type="hidden" name="goal_id" id="edit_goal_id">
        <div class="form-group"><label>Title *</label><input type="text" name="title" id="edit_title" required></div>
        <div class="form-group"><label>Description</label><textarea name="description" id="edit_description" rows="3"></textarea></div>
        <div class="form-row">
          <div class="form-group"><label>Category</label>
            <select name="category" id="edit_category">
              <option value="">Select</option>
              <?php foreach($categories as $cat): ?><option value="<?php echo htmlspecialchars($cat); ?>"><?php echo htmlspecialchars($cat); ?></option><?php endforeach; ?>
            </select></div>
          <div class="form-group"><label>Priority</label>
            <select name="priority" id="edit_priority">
              <option value="low">Low</option><option value="medium" selected>Medium</option><option value="high">High</option>
            </select></div>
        </div>
        <div class="form-row">
          <div class="form-group"><label>Target Value *</label><input type="number" name="target_value" id="edit_target_value" step="0.01" min="0.01" required></div>
          <div class="form-group"><label>Unit</label><input type="text" name="unit" id="edit_unit"></div>
        </div>
        <div class="form-group"><label>Due Date</label><input type="date" name="due_date" id="edit_due_date"></div>
        <div class="modal-actions">
          <button type="button" class="btn btn-secondary" onclick="document.getElementById('editGoalModal').style.display='none'">Cancel</button>
          <button type="submit" class="btn btn-primary">Update Goal</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- DELETE MODAL -->
<div class="modal-overlay" id="deleteModal">
  <div class="modal">
    <div class="modal-header">
      <h3>Delete Goal</h3>
      <button class="modal-close" onclick="document.getElementById('deleteModal').style.display='none'">×</button>
    </div>
    <div class="modal-body">
      <p>Delete "<span id="delete_goal_title"></span>"?</p>
      <p class="text-danger"><i class="fas fa-exclamation-triangle"></i> This cannot be undone.</p>
      <form id="deleteForm">
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf); ?>">
        <input type="hidden" name="goal_id" id="delete_goal_id">
        <div class="modal-actions">
          <button type="button" class="btn btn-secondary" onclick="document.getElementById('deleteModal').style.display='none'">Cancel</button>
          <button type="submit" class="btn btn-danger">Delete</button>
        </div>
      </form>
    </div>
  </div>
</div>

<script>
// Close modals on overlay click
document.querySelectorAll('.modal-overlay').forEach(m=>{
  m.addEventListener('click',function(e){ if(e.target===this) this.style.display='none'; });
});

// Dropdown
document.addEventListener('click',function(e){
  if(!e.target.closest('.dropdown')) document.querySelectorAll('.dropdown-menu').forEach(m=>m.style.display='none');
});
document.querySelectorAll('.dropdown-toggle').forEach(t=>{
  t.addEventListener('click',function(e){
    e.stopPropagation();
    const m=this.nextElementSibling;
    document.querySelectorAll('.dropdown-menu').forEach(x=>x!==m&&(x.style.display='none'));
    m.style.display=m.style.display==='block'?'none':'block';
  });
});

// Animate progress bars
document.querySelectorAll('.progress-fill').forEach(f=>{
  const w=f.style.width; f.style.width='0';
  setTimeout(()=>f.style.width=w,300);
});

// VIEW MODAL
async function openViewModal(id) {
  document.getElementById('viewModal').style.display='flex';
  document.getElementById('viewModalBody').innerHTML='<p style="color:var(--muted);text-align:center;padding:20px;">Loading...</p>';
  try {
    const r = await fetch(`../api/student/goal_get.php?id=${id}`);
    const d = await r.json();
    if(!d.success){ document.getElementById('viewModalBody').innerHTML='<p class="text-danger">'+d.error+'</p>'; return; }
    const g = d.goal;
    const pct = Math.round(g.progress_percentage||0);
    const statusColors = {completed:'#34d399',in_progress:'#22d3ee',pending:'#94a3b8',overdue:'#fb7185'};
    const sc = statusColors[g.status]||'#94a3b8';
    document.getElementById('viewModalBody').innerHTML = `
      <div class="modal-section">
        <div class="modal-label">Title</div>
        <div class="modal-value" style="font-size:16px;">${g.title}</div>
      </div>
      ${g.description?`<div class="modal-section"><div class="modal-label">Description</div><div class="modal-value" style="font-weight:500;line-height:1.6;">${g.description}</div></div>`:''}
      <div class="modal-grid">
        <div class="modal-section"><div class="modal-label">Status</div><div class="modal-value" style="color:${sc};">${g.status.replace('_',' ')}</div></div>
        <div class="modal-section"><div class="modal-label">Priority</div><div class="modal-value">${g.priority}</div></div>
        <div class="modal-section"><div class="modal-label">Category</div><div class="modal-value">${g.category||'—'}</div></div>
        <div class="modal-section"><div class="modal-label">Due Date</div><div class="modal-value">${g.due_date||'—'}</div></div>
        <div class="modal-section"><div class="modal-label">Progress</div><div class="modal-value">${g.current_value} / ${g.target_value} ${g.unit||''}</div></div>
        <div class="modal-section"><div class="modal-label">Points Earned</div><div class="modal-value">${g.status==='completed'?'10 pts':'—'}</div></div>
      </div>
      <div class="modal-section">
        <div class="modal-label">Completion</div>
        <div style="background:rgba(255,255,255,.07);border-radius:999px;height:10px;margin-top:6px;overflow:hidden;">
          <div style="height:100%;width:${pct}%;background:linear-gradient(90deg,#22d3ee,#4f46e5);border-radius:999px;"></div>
        </div>
        <div style="text-align:right;font-size:13px;font-weight:700;margin-top:4px;">${pct}%</div>
      </div>`;
  } catch(e){ document.getElementById('viewModalBody').innerHTML='<p class="text-danger">Error loading details.</p>'; }
}

// UPDATE PROGRESS
function openUpdateProgressModal(id,title,remaining){
  document.getElementById('modal_goal_id').value=id;
  document.getElementById('modal_goal_title').textContent=title;
  document.getElementById('modal_remaining').textContent=remaining;
  document.getElementById('updateProgressModal').style.display='flex';
}

async function postForm(url,formEl){
  const fd=new FormData(formEl);
  const r=await fetch(url,{method:'POST',body:fd});
  const t=await r.text();
  let d; try{d=JSON.parse(t);}catch{alert('Server error. Check console.');throw new Error('Bad JSON');}
  if(!r.ok||!d.success) throw new Error(d.error||'Failed');
  return d;
}

document.getElementById('progressForm')?.addEventListener('submit',async(e)=>{
  e.preventDefault();
  try{
    const d=await postForm('../api/student/goal_progress.php',e.target);
    document.getElementById('updateProgressModal').style.display='none';
    const gid=document.getElementById('modal_goal_id').value;
    const card=document.querySelector(`canvas#chart_${gid}`)?.closest('.goal-card');
    if(card){
      card.querySelector('.progress-percentage').textContent=d.after.progress_percentage+'%';
      card.querySelector('.progress-fill').style.width=d.after.progress_percentage+'%';
      const s=card.querySelector('.progress-stats span');
      if(s) s.textContent=d.new_value+' / '+s.textContent.split('/')[1];
      if(d.status==='completed') location.reload();
    }
  }catch(err){alert(err.message);}
});

// EDIT
async function openEditGoalModal(id){
  const r=await fetch(`../api/student/goal_get.php?id=${id}`);
  const d=await r.json();
  if(!d.success){alert(d.error);return;}
  const g=d.goal;
  if(!g.can_edit){alert('Locked: only self-created goals can be edited.');return;}
  document.getElementById('editGoalModal').style.display='flex';
  document.getElementById('edit_goal_id').value=id;
  document.getElementById('edit_title').value=g.title||'';
  document.getElementById('edit_description').value=g.description||'';
  document.getElementById('edit_category').value=g.category||'';
  document.getElementById('edit_priority').value=g.priority||'medium';
  document.getElementById('edit_target_value').value=g.target_value||0;
  document.getElementById('edit_unit').value=g.unit||'';
  document.getElementById('edit_due_date').value=g.due_date||'';
}
document.getElementById('editGoalForm')?.addEventListener('submit',async(e)=>{
  e.preventDefault();
  try{await postForm('../api/student/goal_edit.php',e.target);location.reload();}
  catch(err){alert(err.message);}
});

// DELETE
function openDeleteModal(id,title){
  document.getElementById('delete_goal_id').value=id;
  document.getElementById('delete_goal_title').textContent=title;
  document.getElementById('deleteModal').style.display='flex';
}
document.getElementById('deleteForm')?.addEventListener('submit',async(e)=>{
  e.preventDefault();
  try{await postForm('../api/student/goal_delete.php',e.target);location.reload();}
  catch(err){alert(err.message);}
});
</script>
<?php nav_js(); ?>
</body>
</html>