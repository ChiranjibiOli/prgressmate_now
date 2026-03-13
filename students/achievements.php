<?php
session_start();
require_once '../includes/db_connection.php';
require_once '../includes/functions.php';
checkAuth('student');

$student_id = $_SESSION['user_id'];
awardAchievements($pdo, $student_id);

$success = $_SESSION['success'] ?? ''; $error = $_SESSION['error'] ?? '';
unset($_SESSION['success'],$_SESSION['error']);

$total_goals     = getStat($pdo,"SELECT COUNT(*) FROM student_goals WHERE student_id=? AND deleted_at IS NULL",[$student_id]);
$completed_goals = getStat($pdo,"SELECT COUNT(*) FROM student_goals WHERE student_id=? AND status='completed' AND deleted_at IS NULL",[$student_id]);
$total_points    = getStat($pdo,"SELECT COALESCE(points,0) FROM users WHERE id=?",[$student_id]);
$streak          = getStat($pdo,"SELECT COALESCE(current_streak,0) FROM users WHERE id=?",[$student_id]);

$earned  = getStudentAchievements($pdo, $student_id);
$inprog  = getAchievementProgress($pdo, $student_id);

$act_stmt = $pdo->prepare("SELECT n.*, DATE_FORMAT(n.created_at,'%b %d, %Y %h:%i %p') AS fmt FROM notifications n WHERE n.user_id=? AND n.type='achievement' AND n.deleted_at IS NULL ORDER BY n.created_at DESC LIMIT 10");
$act_stmt->execute([$student_id]); $activity = $act_stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>Achievements — ProgressMate</title>
<?php require_once '../includes/student_nav.php'; nav_head(); ?>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
<style>
.page-header{
  width:100%;display:flex;align-items:flex-start;justify-content:space-between;
  gap:14px;flex-wrap:wrap;padding:18px 20px;border-radius:var(--r20);
  border:1px solid var(--border);
  background:radial-gradient(120% 220% at 15% 10%,rgba(255,255,255,.10),transparent 55%),
             linear-gradient(180deg,rgba(255,255,255,.05),rgba(255,255,255,.03));
  box-shadow:var(--shadow2);margin-bottom:18px;
}
.page-header h1{margin:0 0 4px;font-size:22px;font-weight:900;}
.page-header p{margin:0;color:var(--muted);font-size:13px;}
.hdr-actions{display:flex;gap:10px;align-items:center;flex-wrap:wrap;}

.btn{display:inline-flex;align-items:center;gap:8px;padding:10px 16px;border-radius:14px;
  border:1px solid var(--border);background:rgba(255,255,255,.05);color:var(--text);
  font-weight:700;font-size:13px;cursor:pointer;transition:.18s;font-family:inherit;}
.btn:hover{transform:translateY(-1px);background:rgba(255,255,255,.08);}
.btn-primary{
  background:radial-gradient(120% 160% at 10% 20%,rgba(255,255,255,.14),transparent 55%),
             linear-gradient(135deg,rgba(79,70,229,.85),rgba(34,211,238,.25));
  border-color:rgba(255,255,255,.18);box-shadow:0 10px 30px rgba(79,70,229,.22);}

.alert{display:flex;align-items:center;gap:12px;padding:13px 16px;border-radius:16px;
  border:1px solid var(--border);background:rgba(255,255,255,.04);margin-bottom:14px;font-size:14px;}
.alert i{width:32px;height:32px;display:grid;place-items:center;border-radius:10px;
  background:rgba(255,255,255,.06);border:1px solid rgba(255,255,255,.12);flex-shrink:0;}
.alert-success{border-color:rgba(52,211,153,.30);} .alert-success i{color:var(--success);}
.alert-error{border-color:rgba(251,113,133,.30);}   .alert-error i{color:var(--danger);}

/* Stats */
.stats-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px;margin-bottom:18px;}
.stat-card{
  border-radius:var(--r20);border:1px solid rgba(255,255,255,.12);padding:14px;
  background:radial-gradient(120% 180% at 10% 0%,rgba(255,255,255,.12),transparent 60%),
             linear-gradient(180deg,rgba(255,255,255,.05),rgba(255,255,255,.03));
  box-shadow:var(--shadow2);
}
.stat-icon{width:44px;height:44px;border-radius:16px;display:grid;place-items:center;
  border:1px solid rgba(255,255,255,.16);
  background:radial-gradient(120% 180% at 20% 15%,rgba(255,255,255,.20),transparent 55%),
             linear-gradient(135deg,rgba(34,211,238,.40),rgba(79,70,229,.40));
  box-shadow:0 16px 30px rgba(0,0,0,.22);margin-bottom:10px;}
.stat-number{font-size:24px;font-weight:950;line-height:1.1;}
.stat-label{margin-top:2px;font-size:13px;color:var(--muted);}

/* Tabs */
.tabs{display:flex;gap:8px;padding:8px;border-radius:var(--r20);
  border:1px solid var(--border);background:rgba(255,255,255,.03);
  box-shadow:var(--shadow2);margin-bottom:16px;flex-wrap:wrap;}
.tab{display:flex;align-items:center;gap:10px;padding:11px 16px;border-radius:16px;
  border:1px solid transparent;background:transparent;color:rgba(234,240,255,.82);
  font-weight:800;cursor:pointer;transition:.18s;font-family:inherit;font-size:13px;}
.tab:hover{background:rgba(255,255,255,.05);border-color:rgba(255,255,255,.10);}
.tab.active{
  background:radial-gradient(120% 160% at 10% 20%,rgba(255,255,255,.14),transparent 55%),
             linear-gradient(135deg,rgba(79,70,229,.55),rgba(34,211,238,.20));
  border-color:rgba(255,255,255,.18);box-shadow:0 18px 40px rgba(79,70,229,.18);color:rgba(234,240,255,.95);}
.tab-count{display:inline-flex;align-items:center;justify-content:center;min-width:24px;height:20px;
  padding:0 6px;border-radius:999px;font-size:11px;font-weight:950;
  background:linear-gradient(135deg,rgba(79,70,229,.70),rgba(34,211,238,.35));
  border:1px solid rgba(255,255,255,.18);color:#fff;margin-left:4px;}
.tab-content{display:none;width:100%;}
.tab-content.active{display:block;}

/* Cards */
.card{width:100%;border-radius:var(--r20);border:1px solid var(--border);
  background:radial-gradient(140% 220% at 10% 0%,rgba(255,255,255,.08),transparent 60%),
             linear-gradient(180deg,rgba(255,255,255,.04),rgba(255,255,255,.02));
  box-shadow:var(--shadow);overflow:hidden;margin-bottom:12px;}
.card-header{display:flex;align-items:center;justify-content:space-between;gap:10px;
  padding:12px 16px;border-bottom:1px solid var(--border2);background:rgba(255,255,255,.02);}
.card-header h3{margin:0;font-size:14px;font-weight:900;display:flex;align-items:center;gap:8px;}
.card-body{padding:14px 16px;}

/* Achievements grid */
.achievements-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(150px,1fr));gap:10px;}
.achievement-card{
  border-radius:18px;border:1px solid rgba(255,255,255,.10);background:rgba(255,255,255,.03);
  padding:14px;text-align:center;transition:.18s;overflow:hidden;
}
.achievement-card:hover{transform:translateY(-3px);box-shadow:0 16px 40px rgba(0,0,0,.35);border-color:rgba(255,255,255,.14);}
.achievement-card.earned{border-color:rgba(52,211,153,.22);
  background:radial-gradient(120% 180% at 20% 0%,rgba(52,211,153,.12),transparent 65%),rgba(255,255,255,.03);}
.achievement-card.locked{opacity:.88;}
.achievement-icon{width:52px;height:52px;border-radius:16px;display:grid;place-items:center;
  margin:0 auto 8px;border:1px solid rgba(255,255,255,.18);box-shadow:0 14px 26px rgba(0,0,0,.20);}
.achievement-name{font-weight:950;font-size:13px;line-height:1.2;}
.achievement-pts{margin-top:4px;font-size:12px;color:rgba(251,191,36,.95);font-weight:950;}
.achievement-date{margin-top:6px;font-size:11px;color:var(--muted2);}
.achievement-desc{margin-top:4px;font-size:11px;color:var(--muted2);line-height:1.4;}
.ach-progress{height:7px;width:100%;border-radius:999px;background:rgba(255,255,255,.07);
  border:1px solid rgba(255,255,255,.08);overflow:hidden;margin:8px 0 4px;}
.ach-fill{height:100%;border-radius:999px;
  background:linear-gradient(90deg,rgba(34,211,238,.95),rgba(79,70,229,.95));
  transition:width 1s cubic-bezier(.22,.75,.12,1);}
.ach-fill.done{background:rgba(52,211,153,.90);}
.ach-pct{font-size:11px;color:var(--muted2);font-weight:800;}

/* Activity */
.activity-list{display:flex;flex-direction:column;gap:10px;}
.activity-item{display:flex;gap:12px;align-items:flex-start;padding:12px;
  border-radius:16px;border:1px solid rgba(255,255,255,.10);border-left:4px solid rgba(251,191,36,.80);
  background:rgba(255,255,255,.03);transition:.18s;}
.activity-item:hover{transform:translateX(2px);border-color:rgba(255,255,255,.14);}
.activity-ico{width:40px;height:40px;border-radius:12px;display:grid;place-items:center;
  border:1px solid rgba(255,255,255,.12);background:rgba(255,255,255,.05);
  color:rgba(251,191,36,.95);flex-shrink:0;}
.activity-title{font-weight:950;font-size:13px;}
.activity-msg{font-size:12.5px;color:var(--muted);margin-top:2px;}
.activity-time{font-size:11px;color:var(--muted2);margin-top:6px;}

/* Tips */
.tips-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:10px;margin-top:16px;}
.tip-card{display:flex;align-items:flex-start;gap:10px;padding:12px 14px;
  border-radius:14px;border:1px solid var(--border2);background:rgba(255,255,255,.03);}
.tip-ico{width:34px;height:34px;border-radius:10px;display:grid;place-items:center;flex-shrink:0;
  background:rgba(255,255,255,.05);border:1px solid rgba(255,255,255,.10);}
.tip-title{font-size:13px;font-weight:800;margin-bottom:2px;}
.tip-text{font-size:12px;color:var(--muted);}

/* Empty */
.empty-state{text-align:center;padding:28px 14px;color:var(--muted);}
.empty-state i{display:inline-grid;place-items:center;width:48px;height:48px;border-radius:16px;
  margin-bottom:8px;border:1px solid rgba(255,255,255,.12);background:rgba(255,255,255,.04);font-size:20px;}

/* Sync indicator */
.sync-dot{width:8px;height:8px;border-radius:50%;background:var(--success);display:inline-block;margin-right:4px;animation:pulse 2s infinite;}
@keyframes pulse{0%,100%{opacity:1;}50%{opacity:.4;}}

@media(max-width:900px){.stats-grid{grid-template-columns:repeat(2,1fr);}}
@media(max-width:520px){.stats-grid{grid-template-columns:1fr;}.achievements-grid{grid-template-columns:repeat(2,1fr);}}
</style>
</head>
<body>
<?php nav_body(); ?>

  <!-- HEADER -->
  <header class="page-header">
    <div>
      <h1>My Achievements</h1>
      <p>Track your progress and celebrate your accomplishments</p>
    </div>
    <div class="hdr-actions">
      <button class="theme-btn" id="themeBtn">
        <div class="tgl-track" id="themeTrack"><div class="tgl-thumb"></div></div>
        <span id="themeLabel">Dark</span>
      </button>
      <span style="font-size:12px;color:var(--muted);"><span class="sync-dot"></span>Live sync</span>
    </div>
  </header>

  <?php if($success): ?><div class="alert alert-success"><i class="fas fa-check-circle"></i><span><?php echo htmlspecialchars($success); ?></span></div><?php endif; ?>
  <?php if($error): ?><div class="alert alert-error"><i class="fas fa-exclamation-circle"></i><span><?php echo htmlspecialchars($error); ?></span></div><?php endif; ?>

  <!-- STATS -->
  <div class="stats-grid">
    <div class="stat-card"><div class="stat-icon"><i class="fas fa-trophy"></i></div><div class="stat-number" id="statEarned"><?php echo count($earned); ?></div><div class="stat-label">Earned</div></div>
    <div class="stat-card"><div class="stat-icon"><i class="fas fa-star"></i></div><div class="stat-number" id="statPoints"><?php echo $total_points; ?></div><div class="stat-label">Total Points</div></div>
    <div class="stat-card"><div class="stat-icon"><i class="fas fa-bullseye"></i></div><div class="stat-number" id="statCompleted"><?php echo $completed_goals; ?></div><div class="stat-label">Goals Done</div></div>
    <div class="stat-card"><div class="stat-icon"><i class="fas fa-fire"></i></div><div class="stat-number" id="statStreak"><?php echo $streak; ?></div><div class="stat-label">Day Streak</div></div>
  </div>

  <!-- TABS -->
  <div class="tabs">
    <button class="tab active" data-tab="earned"><i class="fas fa-unlock"></i> Earned <span class="tab-count" id="tcEarned"><?php echo count($earned); ?></span></button>
    <button class="tab" data-tab="progress"><i class="fas fa-bullseye"></i> In Progress <span class="tab-count" id="tcProgress"><?php echo count($inprog); ?></span></button>
    <button class="tab" data-tab="activity"><i class="fas fa-history"></i> Recent Activity</button>
  </div>

  <!-- TAB: EARNED -->
  <div class="tab-content active" id="tab-earned">
    <div class="card">
      <div class="card-header">
        <h3><i class="fas fa-unlock" style="color:var(--success);"></i> Earned Achievements</h3>
        <span style="font-size:12px;color:var(--muted);" id="earnedSub"><?php echo count($earned); ?> earned &bull; <?php echo $total_points; ?> pts</span>
      </div>
      <div class="card-body">
        <?php if(empty($earned)): ?>
          <div class="empty-state"><i class="fas fa-trophy"></i><p>No achievements yet. Complete goals!</p><a href="goals.php" class="btn btn-primary" style="margin-top:10px;"><i class="fas fa-bullseye"></i> View Goals</a></div>
        <?php else: ?>
          <div class="achievements-grid" id="earnedGrid">
            <?php foreach($earned as $a): ?>
              <div class="achievement-card earned">
                <div class="achievement-icon" style="background:<?php echo htmlspecialchars($a['color']); ?>;"><i class="fas fa-<?php echo htmlspecialchars($a['icon']); ?>"></i></div>
                <div class="achievement-name"><?php echo htmlspecialchars($a['title']); ?></div>
                <div class="achievement-pts">+<?php echo $a['points']; ?> pts</div>
                <div class="achievement-date"><?php echo date('M d, Y',strtotime($a['earned_at'])); ?></div>
                <?php if(!empty($a['description'])): ?><div class="achievement-desc"><?php echo htmlspecialchars($a['description']); ?></div><?php endif; ?>
              </div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <!-- TAB: IN PROGRESS -->
  <div class="tab-content" id="tab-progress">
    <div class="card">
      <div class="card-header">
        <h3><i class="fas fa-bullseye" style="color:var(--info);"></i> Achievements In Progress</h3>
        <span style="font-size:12px;color:var(--muted);" id="progressSub"><?php echo count($inprog); ?> to unlock</span>
      </div>
      <div class="card-body">
        <?php if(empty($inprog)): ?>
          <div class="empty-state"><i class="fas fa-flag-checkered"></i><p>All achievements earned! Check back later.</p></div>
        <?php else: ?>
          <div class="achievements-grid" id="progressGrid">
            <?php foreach($inprog as $p): $a=$p['achievement']; ?>
              <div class="achievement-card locked" data-id="<?php echo $a['id']; ?>">
                <div class="achievement-icon" style="background:<?php echo htmlspecialchars($a['color']); ?>;opacity:.75;"><i class="fas fa-<?php echo htmlspecialchars($a['icon']); ?>"></i></div>
                <div class="achievement-name"><?php echo htmlspecialchars($a['title']); ?></div>
                <div class="achievement-pts">+<?php echo $a['points']; ?> pts</div>
                <div class="ach-progress"><div class="ach-fill" style="width:<?php echo $p['percentage']; ?>%;"></div></div>
                <div class="ach-pct ach-pct-<?php echo $a['id']; ?>"><?php echo $p['current_value']; ?>/<?php echo $p['target_value']; ?> (<?php echo $p['percentage']; ?>%)</div>
                <?php if(!empty($a['description'])): ?><div class="achievement-desc"><?php echo htmlspecialchars($a['description']); ?></div><?php endif; ?>
              </div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <!-- TAB: ACTIVITY -->
  <div class="tab-content" id="tab-activity">
    <div class="card">
      <div class="card-header">
        <h3><i class="fas fa-history"></i> Recent Achievement Activity</h3>
        <span style="font-size:12px;color:var(--muted);" id="activitySub"><?php echo count($activity); ?> events</span>
      </div>
      <div class="card-body" id="activityList">
        <?php if(empty($activity)): ?>
          <div class="empty-state"><i class="fas fa-bell-slash"></i><p>No achievement activity yet.</p></div>
        <?php else: ?>
          <div class="activity-list">
            <?php foreach($activity as $act): ?>
              <div class="activity-item">
                <div class="activity-ico"><i class="fas fa-trophy"></i></div>
                <div>
                  <div class="activity-title"><?php echo htmlspecialchars($act['title']); ?></div>
                  <div class="activity-msg"><?php echo htmlspecialchars($act['message']); ?></div>
                  <div class="activity-time"><?php echo $act['fmt']; ?></div>
                </div>
              </div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <!-- TIPS -->
  <div class="card" style="margin-top:6px;">
    <div class="card-header"><h3><i class="fas fa-lightbulb" style="color:var(--warning);"></i> How to Earn More</h3></div>
    <div class="card-body">
      <div class="tips-grid">
        <div class="tip-card"><div class="tip-ico"><i class="fas fa-bullseye" style="color:var(--primary);"></i></div><div><div class="tip-title">Complete Goals</div><div class="tip-text">Finish assigned and personal goals</div></div></div>
        <div class="tip-card"><div class="tip-ico"><i class="fas fa-fire" style="color:var(--warning);"></i></div><div><div class="tip-title">Maintain Streak</div><div class="tip-text">Log in daily to keep your streak</div></div></div>
        <div class="tip-card"><div class="tip-ico"><i class="fas fa-calendar-check" style="color:var(--success);"></i></div><div><div class="tip-title">Be Consistent</div><div class="tip-text">Work on goals regularly</div></div></div>
        <div class="tip-card"><div class="tip-ico"><i class="fas fa-star" style="color:var(--info);"></i></div><div><div class="tip-title">Earn Points</div><div class="tip-text">Accumulate points through goals</div></div></div>
      </div>
    </div>
  </div>

</main></div>

<script>
// Tab switch
document.querySelectorAll('.tab').forEach(t=>{
  t.addEventListener('click',()=>{
    document.querySelectorAll('.tab').forEach(x=>x.classList.remove('active'));
    document.querySelectorAll('.tab-content').forEach(x=>x.classList.remove('active'));
    t.classList.add('active');
    document.getElementById('tab-'+t.dataset.tab).classList.add('active');
  });
});

// Animate progress bars
document.querySelectorAll('.ach-fill').forEach(f=>{const w=f.style.width;f.style.width='0';setTimeout(()=>f.style.width=w,400);});

// Live sync — poll every 20 seconds
(function pollAchievements(){
  setInterval(async()=>{
    try{
      const r=await fetch('../api/student/achievements_live.php');
      if(!r.ok) return;
      const d=await r.json();
      if(!d.success) return;

      // Update stats
      if(d.earned_count!==undefined){ document.getElementById('statEarned').textContent=d.earned_count; document.getElementById('tcEarned').textContent=d.earned_count; document.getElementById('earnedSub').textContent=d.earned_count+' earned \u2022 '+d.total_points+' pts'; }
      if(d.total_points!==undefined) document.getElementById('statPoints').textContent=d.total_points;
      if(d.completed_goals!==undefined) document.getElementById('statCompleted').textContent=d.completed_goals;
      if(d.streak!==undefined) document.getElementById('statStreak').textContent=d.streak;

      // Update progress bars for unlocked achievements
      if(d.progress && Array.isArray(d.progress)){
        d.progress.forEach(p=>{
          const fill=document.querySelector('.achievement-card[data-id="'+p.id+'"] .ach-fill');
          const pct=document.querySelector('.ach-pct-'+p.id);
          if(fill) fill.style.width=p.percentage+'%';
          if(pct) pct.textContent=p.current+'/'+p.target+' ('+p.percentage+'%)';
        });
      }
    }catch(e){}
  },20000);
})();
</script>
<?php nav_js(); ?>
</body>
</html>