<?php
session_start();
require_once '../includes/db_connection.php';
require_once '../includes/functions.php';
checkAuth('student');
$student_id = $_SESSION['user_id'];

$total_goals     = getStat($pdo,"SELECT COUNT(*) FROM student_goals WHERE student_id=? AND deleted_at IS NULL",[$student_id]);
$completed_goals = getStat($pdo,"SELECT COUNT(*) FROM student_goals WHERE student_id=? AND status='completed' AND deleted_at IS NULL",[$student_id]);
$total_points    = getStat($pdo,"SELECT COALESCE(points,0) FROM users WHERE id=?",[$student_id]);
$streak          = getStat($pdo,"SELECT COALESCE(current_streak,0) FROM users WHERE id=?",[$student_id]);
$unread          = getStat($pdo,"SELECT COUNT(*) FROM notifications WHERE user_id=? AND is_read=0 AND deleted_at IS NULL",[$student_id]);

// Actions
if(isset($_GET['read_all'])){ $pdo->prepare("UPDATE notifications SET is_read=1 WHERE user_id=? AND is_read=0 AND deleted_at IS NULL")->execute([$student_id]); header("Location: notifications.php"); exit; }
if(isset($_GET['read'])){ $pdo->prepare("UPDATE notifications SET is_read=1 WHERE id=? AND user_id=?")->execute([(int)$_GET['read'],$student_id]); header("Location: notifications.php?filter=".($_GET['f']??'all')); exit; }
if(isset($_GET['delete'])){ $pdo->prepare("UPDATE notifications SET deleted_at=NOW() WHERE id=? AND user_id=? AND deleted_at IS NULL")->execute([(int)$_GET['delete'],$student_id]); header("Location: notifications.php?filter=".($_GET['f']??'all')); exit; }

// Filter & pagination
$filter = $_GET['filter'] ?? 'all';
if(!in_array($filter,['all','unread','goal','achievement','reminder'])) $filter='all';
$per_page=15; $page=max(1,(int)($_GET['page']??1)); $offset=($page-1)*$per_page;
$where="WHERE user_id=? AND deleted_at IS NULL"; $params=[$student_id];
if($filter==='unread'){ $where.=" AND is_read=0"; }
elseif(in_array($filter,['goal','achievement','reminder'])){ $where.=" AND type=?"; $params[]=$filter; }

$total_stmt=$pdo->prepare("SELECT COUNT(*) FROM notifications $where"); $total_stmt->execute($params); $total_n=$total_stmt->fetchColumn();
$total_pages=max(1,ceil($total_n/$per_page));
$stmt=$pdo->prepare("SELECT * FROM notifications $where ORDER BY created_at DESC LIMIT $per_page OFFSET $offset"); $stmt->execute($params); $notifications=$stmt->fetchAll();

// Counts for stats row
$cnt_total = getStat($pdo,"SELECT COUNT(*) FROM notifications WHERE user_id=? AND deleted_at IS NULL",[$student_id]);
$cnt_unread= getStat($pdo,"SELECT COUNT(*) FROM notifications WHERE user_id=? AND is_read=0 AND deleted_at IS NULL",[$student_id]);
$cnt_goal  = getStat($pdo,"SELECT COUNT(*) FROM notifications WHERE user_id=? AND type='goal' AND deleted_at IS NULL",[$student_id]);
$cnt_ach   = getStat($pdo,"SELECT COUNT(*) FROM notifications WHERE user_id=? AND type='achievement' AND deleted_at IS NULL",[$student_id]);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>Notifications — ProgressMate</title>
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
  font-weight:700;font-size:13px;cursor:pointer;transition:.18s;font-family:inherit;text-decoration:none;}
.btn:hover{transform:translateY(-1px);background:rgba(255,255,255,.08);}
.btn-sm{padding:7px 12px;font-size:12px;border-radius:11px;}
.btn-primary{
  background:radial-gradient(120% 160% at 10% 20%,rgba(255,255,255,.14),transparent 55%),
             linear-gradient(135deg,rgba(79,70,229,.85),rgba(34,211,238,.25));
  border-color:rgba(255,255,255,.18);box-shadow:0 10px 30px rgba(79,70,229,.22);}
.btn-del{background:rgba(251,113,133,.10);border-color:rgba(251,113,133,.28);color:var(--danger);}
.btn-del:hover{background:rgba(251,113,133,.20);}

/* Stats row */
.stats-row{display:grid;grid-template-columns:repeat(4,1fr);gap:12px;margin-bottom:18px;}
.stat-mini{display:flex;align-items:center;gap:12px;padding:14px 16px;
  border-radius:16px;border:1px solid var(--border2);background:rgba(255,255,255,.03);}
.stat-ico{width:36px;height:36px;border-radius:11px;display:grid;place-items:center;
  font-size:14px;flex-shrink:0;border:1px solid rgba(255,255,255,.14);}
.stat-num{font-size:20px;font-weight:900;line-height:1;}
.stat-lbl{font-size:11px;color:var(--muted);margin-top:2px;}

/* Filter tabs */
.filter-tabs{display:flex;gap:8px;margin-bottom:16px;flex-wrap:wrap;}
.ftab{padding:8px 16px;border-radius:999px;border:1px solid var(--border);
  background:rgba(255,255,255,.04);color:var(--muted);font-size:13px;font-weight:600;
  cursor:pointer;transition:.18s;text-decoration:none;font-family:inherit;}
.ftab:hover{background:rgba(255,255,255,.08);color:var(--text);}
.ftab.on{
  background:radial-gradient(120% 160% at 20% 20%,rgba(255,255,255,.12),transparent 55%),
            linear-gradient(135deg,rgba(79,70,229,.55),rgba(34,211,238,.20));
  border-color:rgba(255,255,255,.18);color:var(--text);}

/* Notifications */
.notif-list{display:flex;flex-direction:column;gap:10px;}
.notif-item{
  display:flex;align-items:flex-start;gap:14px;padding:16px;
  border-radius:18px;border:1px solid var(--border);background:rgba(255,255,255,.03);
  transition:.18s;position:relative;overflow:hidden;
}
.notif-item:hover{transform:translateY(-2px);border-color:rgba(255,255,255,.15);background:rgba(255,255,255,.05);box-shadow:0 12px 36px rgba(0,0,0,.22);}
.notif-item.unread{border-color:rgba(79,70,229,.28);
  background:radial-gradient(120% 200% at 5% 0%,rgba(79,70,229,.10),transparent 60%),rgba(255,255,255,.03);}
.notif-item.unread::before{content:'';position:absolute;left:0;top:50%;transform:translateY(-50%);
  width:3px;height:55%;background:linear-gradient(180deg,#4f46e5,#22d3ee);border-radius:0 3px 3px 0;}
.notif-ico{width:44px;height:44px;border-radius:14px;display:grid;place-items:center;
  border:1px solid rgba(255,255,255,.14);background:rgba(255,255,255,.05);flex-shrink:0;font-size:16px;}
.notif-ico.type-goal{background:rgba(52,211,153,.12);border-color:rgba(52,211,153,.25);color:var(--success);}
.notif-ico.type-achievement{background:rgba(251,191,36,.12);border-color:rgba(251,191,36,.25);color:var(--warning);}
.notif-ico.type-reminder{background:rgba(34,211,238,.12);border-color:rgba(34,211,238,.25);color:var(--info);}
.notif-item.unread .notif-ico{
  background:radial-gradient(120% 180% at 20% 10%,rgba(255,255,255,.18),transparent 55%),
             linear-gradient(135deg,rgba(79,70,229,.70),rgba(34,211,238,.25));
  border-color:rgba(255,255,255,.20);}
.notif-body{flex:1;min-width:0;}
.notif-title{font-weight:800;font-size:14px;margin-bottom:4px;}
.notif-msg{font-size:13px;color:var(--muted);line-height:1.5;margin-bottom:8px;}
.notif-meta{display:flex;align-items:center;gap:8px;flex-wrap:wrap;}
.notif-time{font-size:11.5px;color:var(--muted2);font-weight:600;}
.notif-badge{font-size:10.5px;font-weight:700;padding:2px 8px;border-radius:999px;
  border:1px solid rgba(255,255,255,.12);background:rgba(255,255,255,.06);text-transform:uppercase;letter-spacing:.3px;}
.notif-badge.new{color:var(--info);border-color:rgba(34,211,238,.22);background:rgba(34,211,238,.08);}
.notif-actions{display:flex;flex-direction:column;gap:7px;flex-shrink:0;}
/* New notif animation */
.notif-item.new-in{animation:slideIn .3s ease;}
@keyframes slideIn{from{opacity:0;transform:translateY(-8px);}to{opacity:1;transform:translateY(0);}}

/* Empty */
.empty-state{text-align:center;padding:50px 20px;border-radius:20px;border:1px solid var(--border2);background:rgba(255,255,255,.02);}
.empty-ico{width:60px;height:60px;display:grid;place-items:center;border-radius:18px;
  border:1px solid rgba(255,255,255,.12);background:rgba(255,255,255,.04);font-size:24px;margin:0 auto 16px;}
.empty-state h3{font-size:18px;font-weight:800;margin-bottom:8px;}
.empty-state p{color:var(--muted);font-size:14px;margin-bottom:18px;}

/* Pagination */
.pagination{display:flex;justify-content:center;gap:8px;margin-top:20px;flex-wrap:wrap;}

/* Sync */
.sync-dot{width:8px;height:8px;border-radius:50%;background:var(--success);display:inline-block;margin-right:4px;animation:pulse 2s infinite;}
@keyframes pulse{0%,100%{opacity:1;}50%{opacity:.4;}}

@media(max-width:600px){
  .stats-row{grid-template-columns:1fr 1fr;}
  .notif-item{flex-direction:column;}
  .notif-actions{flex-direction:row;width:100%;}
  .notif-actions .btn-sm{flex:1;justify-content:center;}
}
</style>
</head>
<body>
<?php nav_body(); ?>

  <!-- HEADER -->
  <header class="page-header">
    <div>
      <h1>Notifications</h1>
      <p>Stay updated with your progress, achievements and reminders</p>
    </div>
    <div class="hdr-actions">
      <button class="theme-btn" id="themeBtn">
        <div class="tgl-track" id="themeTrack"><div class="tgl-thumb"></div></div>
        <span id="themeLabel">Dark</span>
      </button>
      <span style="font-size:12px;color:var(--muted);"><span class="sync-dot"></span>Live</span>
      <?php if($unread>0): ?>
        <a href="?read_all=1" class="btn btn-primary"><i class="fas fa-check-double"></i> Mark All Read</a>
      <?php endif; ?>
    </div>
  </header>

  <!-- STATS ROW (live) -->
  <div class="stats-row">
    <div class="stat-mini">
      <div class="stat-ico" style="background:rgba(79,70,229,.14);border-color:rgba(79,70,229,.28);color:#818cf8;"><i class="fas fa-bell"></i></div>
      <div><div class="stat-num" id="cntTotal"><?php echo $cnt_total; ?></div><div class="stat-lbl">Total</div></div>
    </div>
    <div class="stat-mini">
      <div class="stat-ico" style="background:rgba(251,113,133,.12);border-color:rgba(251,113,133,.26);color:var(--danger);"><i class="fas fa-circle-dot"></i></div>
      <div><div class="stat-num" id="cntUnread"><?php echo $cnt_unread; ?></div><div class="stat-lbl">Unread</div></div>
    </div>
    <div class="stat-mini">
      <div class="stat-ico" style="background:rgba(52,211,153,.12);border-color:rgba(52,211,153,.26);color:var(--success);"><i class="fas fa-bullseye"></i></div>
      <div><div class="stat-num" id="cntGoal"><?php echo $cnt_goal; ?></div><div class="stat-lbl">Goals</div></div>
    </div>
    <div class="stat-mini">
      <div class="stat-ico" style="background:rgba(251,191,36,.12);border-color:rgba(251,191,36,.26);color:var(--warning);"><i class="fas fa-trophy"></i></div>
      <div><div class="stat-num" id="cntAch"><?php echo $cnt_ach; ?></div><div class="stat-lbl">Achievements</div></div>
    </div>
  </div>

  <!-- FILTER TABS -->
  <div class="filter-tabs">
    <?php foreach([['all','All'],['unread','Unread'],['goal','Goals'],['achievement','Achievements'],['reminder','Reminders']] as [$v,$l]): ?>
      <a href="?filter=<?php echo $v; ?>" class="ftab <?php echo $filter===$v?'on':''; ?>"><?php echo $l; ?></a>
    <?php endforeach; ?>
  </div>

  <!-- NOTIFICATION LIST (live) -->
  <div class="notif-list" id="notifList">
    <?php if(empty($notifications)): ?>
      <div class="empty-state">
        <div class="empty-ico"><i class="fas fa-bell-slash"></i></div>
        <h3>No notifications</h3>
        <p><?php echo $filter==='all'?"You're all caught up!":'No notifications in this category.'; ?></p>
        <a href="goals.php" class="btn btn-primary"><i class="fas fa-bullseye"></i> View Goals</a>
      </div>
    <?php else: ?>
      <?php
      $icons=['goal'=>'fas fa-bullseye','achievement'=>'fas fa-trophy','reminder'=>'fas fa-clock','deadline'=>'fas fa-calendar','system'=>'fas fa-circle-info'];
      foreach($notifications as $n):
        $ico=$icons[$n['type']]??'fas fa-bell';
        $unrd=!$n['is_read'];
        $td=time()-strtotime($n['created_at']);
        $tstr=$td<60?'Just now':($td<3600?floor($td/60).'m ago':($td<86400?floor($td/3600).'h ago':($td<604800?floor($td/86400).'d ago':date('M j, Y',strtotime($n['created_at'])))));
      ?>
        <div class="notif-item <?php echo $unrd?'unread':''; ?>" data-id="<?php echo $n['id']; ?>">
          <div class="notif-ico type-<?php echo htmlspecialchars($n['type']); ?>"><i class="<?php echo $ico; ?>"></i></div>
          <div class="notif-body">
            <div class="notif-title"><?php echo htmlspecialchars($n['title']); ?></div>
            <div class="notif-msg"><?php echo htmlspecialchars($n['message']); ?></div>
            <div class="notif-meta">
              <span class="notif-time"><i class="fas fa-clock" style="font-size:10px;opacity:.7;"></i> <?php echo $tstr; ?></span>
              <span class="notif-badge"><?php echo htmlspecialchars($n['type']); ?></span>
              <?php if($unrd): ?><span class="notif-badge new">New</span><?php endif; ?>
            </div>
          </div>
          <div class="notif-actions">
            <?php if($unrd): ?><a href="?read=<?php echo $n['id']; ?>&f=<?php echo $filter; ?>" class="btn btn-sm"><i class="fas fa-check"></i> Read</a><?php endif; ?>
            <a href="?delete=<?php echo $n['id']; ?>&f=<?php echo $filter; ?>" class="btn btn-sm btn-del" onclick="return confirm('Delete?')"><i class="fas fa-trash"></i>Delete</a>
          </div>
        </div>
      <?php endforeach; ?>
    <?php endif; ?>
  </div>

  <!-- PAGINATION -->
  <?php if($total_pages>1): ?>
    <div class="pagination">
      <?php for($i=1;$i<=$total_pages;$i++): ?>
        <a href="?page=<?php echo $i; ?>&filter=<?php echo $filter; ?>" class="btn btn-sm <?php echo $page==$i?'btn-primary':''; ?>"><?php echo $i; ?></a>
      <?php endfor; ?>
    </div>
  <?php endif; ?>

</main></div>

<script>
// Live poll every 15 seconds
let knownIds = new Set([<?php echo implode(',',array_column($notifications,'id')); ?>]);

(function pollNotifs(){
  setInterval(async()=>{
    try{
      const r=await fetch('../api/student/notifications_live.php');
      if(!r.ok) return;
      const d=await r.json();
      if(!d.success) return;

      // Update stat counters
      if(d.total!==undefined) document.getElementById('cntTotal').textContent=d.total;
      if(d.unread!==undefined){
        document.getElementById('cntUnread').textContent=d.unread;
        // Update sidebar badge
        const sb=document.querySelector('.sb-badge');
        if(sb){ if(d.unread>0){sb.textContent=d.unread;sb.style.display='';}else sb.style.display='none'; }
        // Update mark all read btn
        const markBtn=document.querySelector('a[href="?read_all=1"]');
        if(markBtn&&d.unread===0) markBtn.style.display='none';
      }
      if(d.cnt_goal!==undefined) document.getElementById('cntGoal').textContent=d.cnt_goal;
      if(d.cnt_ach!==undefined) document.getElementById('cntAch').textContent=d.cnt_ach;

      // Prepend new notifications
      if(d.latest && Array.isArray(d.latest)){
        const list=document.getElementById('notifList');
        d.latest.forEach(n=>{
          if(knownIds.has(n.id)) return;
          knownIds.add(n.id);
          const icons={goal:'fas fa-bullseye',achievement:'fas fa-trophy',reminder:'fas fa-clock'};
          const ico=icons[n.type]||'fas fa-bell';
          const el=document.createElement('div');
          el.className='notif-item unread new-in';
          el.dataset.id=n.id;
          el.innerHTML=`
            <div class="notif-ico type-${n.type}"><i class="${ico}"></i></div>
            <div class="notif-body">
              <div class="notif-title">${n.title}</div>
              <div class="notif-msg">${n.message}</div>
              <div class="notif-meta"><span class="notif-time">Just now</span><span class="notif-badge">${n.type}</span><span class="notif-badge new">New</span></div>
            </div>
            <div class="notif-actions">
              <a href="?read=${n.id}" class="btn btn-sm"><i class="fas fa-check"></i> Read</a>
              <a href="?delete=${n.id}" class="btn btn-sm btn-del" onclick="return confirm('Delete?')"><i class="fas fa-trash"></i></a>
            </div>`;
          if(list.firstChild && list.firstChild.classList?.contains('empty-state')) list.innerHTML='';
          list.prepend(el);
        });
      }
    }catch(e){}
  },15000);
})();
</script>
<?php nav_js(); ?>
</body>
</html>