<?php
if (session_status() === PHP_SESSION_NONE) session_start();
require_once '../includes/db_connection.php';
require_once '../includes/functions.php';
require_once '../includes/student_nav.php';
checkAuth('student');

$student_id = $_SESSION['user_id'];

// Update login streak
updateLoginStreak($pdo, $student_id);
awardAchievements($pdo, $student_id);

// ── Core stats ────────────────────────────────────────────────
$total_goals     = getStat($pdo, "SELECT COUNT(*) FROM student_goals WHERE student_id=? AND deleted_at IS NULL", [$student_id]);
$completed_goals = getStat($pdo, "SELECT COUNT(*) FROM student_goals WHERE student_id=? AND status='completed' AND deleted_at IS NULL", [$student_id]);
$in_progress     = getStat($pdo, "SELECT COUNT(*) FROM student_goals WHERE student_id=? AND status='in_progress' AND deleted_at IS NULL", [$student_id]);
$pending_goals   = getStat($pdo, "SELECT COUNT(*) FROM student_goals WHERE student_id=? AND status='pending' AND deleted_at IS NULL", [$student_id]);
$overdue_goals   = getStat($pdo, "SELECT COUNT(*) FROM student_goals WHERE student_id=? AND status='overdue' AND deleted_at IS NULL", [$student_id]);
$total_points    = getStat($pdo, "SELECT COALESCE(points,0) FROM users WHERE id=?", [$student_id]);
$streak          = getStat($pdo, "SELECT COALESCE(current_streak,0) FROM users WHERE id=?", [$student_id]);
$unread          = getStat($pdo, "SELECT COUNT(*) FROM notifications WHERE user_id=? AND is_read=0 AND deleted_at IS NULL", [$student_id]);
$total_badges    = getStat($pdo, "SELECT COUNT(*) FROM user_achievements WHERE user_id=? AND earned_at IS NOT NULL AND deleted_at IS NULL", [$student_id]);

// ── Recent goals ──────────────────────────────────────────────
$stmt = $pdo->prepare("SELECT * FROM student_goals WHERE student_id=? AND deleted_at IS NULL ORDER BY created_at DESC LIMIT 5");
$stmt->execute([$student_id]);
$recent_goals = $stmt->fetchAll();

// ── Upcoming deadlines ────────────────────────────────────────
$stmt = $pdo->prepare("SELECT * FROM student_goals WHERE student_id=? AND due_date >= CURDATE() AND status != 'completed' AND deleted_at IS NULL ORDER BY due_date ASC LIMIT 4");
$stmt->execute([$student_id]);
$upcoming = $stmt->fetchAll();

// ── Recent earned achievements ────────────────────────────────
$stmt = $pdo->prepare("
    SELECT a.title, a.icon, a.color, a.points, ua.earned_at
    FROM user_achievements ua
    JOIN achievements a ON a.id = ua.achievement_id
    WHERE ua.user_id=? AND ua.earned_at IS NOT NULL AND ua.deleted_at IS NULL AND a.deleted_at IS NULL
    ORDER BY ua.earned_at DESC LIMIT 4
");
$stmt->execute([$student_id]);
$recent_badges = $stmt->fetchAll();

// ── Recent notifications ──────────────────────────────────────
$stmt = $pdo->prepare("SELECT * FROM notifications WHERE user_id=? AND deleted_at IS NULL ORDER BY created_at DESC LIMIT 4");
$stmt->execute([$student_id]);
$notifications = $stmt->fetchAll();

// ── Weekly goal completions (bar chart) — last 7 days ─────────
$weekly = [];
for ($i = 6; $i >= 0; $i--) {
    $date  = date('Y-m-d', strtotime("-$i days"));
    $label = date('D', strtotime($date));
    $count = getStat($pdo,
        "SELECT COUNT(*) FROM student_goals WHERE student_id=? AND DATE(completed_at)=? AND status='completed' AND deleted_at IS NULL",
        [$student_id, $date]
    );
    $weekly[] = ['label' => $label, 'count' => $count];
}
$bar_labels = json_encode(array_column($weekly, 'label'));
$bar_data   = json_encode(array_column($weekly, 'count'));

// ── Donut data ────────────────────────────────────────────────
$donut_data   = json_encode([$completed_goals, $in_progress, $pending_goals, $overdue_goals]);
$donut_labels = json_encode(['Completed', 'In Progress', 'Pending', 'Overdue']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Dashboard - ProgressMate</title>
<?php nav_head(); ?>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<style>
/* ── Dashboard-specific styles — layout/sidebar/theme from nav_body() ── */

.page-header{
  width:100%;display:flex;align-items:flex-start;justify-content:space-between;
  gap:14px;flex-wrap:wrap;padding:16px 18px;border-radius:var(--r20);
  border:1px solid var(--border);margin-bottom:16px;
  background:radial-gradient(120% 220% at 15% 10%,rgba(255,255,255,.10),transparent 55%),
             linear-gradient(180deg,rgba(255,255,255,.05),rgba(255,255,255,.03));
  box-shadow:var(--shadow2);
}
.page-header h1{font-size:22px;font-weight:900;margin-bottom:3px;}
.page-header p{font-size:13px;color:var(--muted);}

.stats-row{display:grid;grid-template-columns:repeat(4,1fr);gap:10px;margin-bottom:16px;}
.stat-card{
  padding:14px;border-radius:var(--r20);
  border:1px solid rgba(255,255,255,.10);
  background:linear-gradient(180deg,rgba(255,255,255,.07),rgba(255,255,255,.03));
  box-shadow:var(--shadow2);
}
.stat-icon{width:40px;height:40px;border-radius:14px;display:grid;place-items:center;margin-bottom:10px;border:1px solid rgba(255,255,255,.14);font-size:16px;}
.stat-num{font-size:26px;font-weight:900;line-height:1.1;}
.stat-lbl{font-size:12px;color:var(--muted);margin-top:2px;}

.charts-row{display:grid;grid-template-columns:1fr 1.8fr;gap:12px;margin-bottom:16px;}
.card{border-radius:var(--r20);border:1px solid rgba(255,255,255,.09);background:linear-gradient(180deg,rgba(255,255,255,.06),rgba(255,255,255,.03));box-shadow:var(--shadow2);overflow:hidden;}
.card-head{display:flex;align-items:center;justify-content:space-between;padding:12px 14px;border-bottom:1px solid rgba(255,255,255,.07);}
.card-head h3{display:flex;align-items:center;gap:9px;font-size:14px;font-weight:800;}
.card-head h3 i{font-size:13px;color:var(--muted);}
.card-head a{font-size:12.5px;color:var(--primary);}
.card-body{padding:14px;}

.donut-wrap{display:flex;align-items:center;gap:18px;}
.donut-canvas{width:140px!important;height:140px!important;flex-shrink:0;}
.donut-legend{display:flex;flex-direction:column;gap:8px;flex:1;}
.legend-item{display:flex;align-items:center;gap:8px;font-size:12.5px;}
.legend-dot{width:10px;height:10px;border-radius:999px;flex-shrink:0;}
.legend-val{margin-left:auto;font-weight:800;font-size:13px;}
.bar-canvas{width:100%!important;height:160px!important;}

.bottom-grid{display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:16px;}

.goal-item{display:flex;flex-direction:column;gap:6px;padding:10px 0;border-bottom:1px solid rgba(255,255,255,.06);}
.goal-item:last-child{border-bottom:none;padding-bottom:0;}
.goal-row{display:flex;align-items:center;justify-content:space-between;gap:8px;}
.goal-title{font-size:13px;font-weight:700;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:200px;}
.goal-due{font-size:11px;color:var(--muted);}
.status-pill{font-size:10.5px;font-weight:800;padding:2px 8px;border-radius:999px;white-space:nowrap;flex-shrink:0;}
.s-completed{background:rgba(52,211,153,.15);color:#34D399;}
.s-in_progress{background:rgba(79,70,229,.20);color:#818CF8;}
.s-pending{background:rgba(251,191,36,.12);color:#FBBF24;}
.s-overdue{background:rgba(251,113,133,.15);color:#FB7185;}
.pbar{height:5px;border-radius:999px;background:rgba(255,255,255,.07);overflow:hidden;margin-top:2px;}
.pfill{height:100%;border-radius:999px;background:linear-gradient(90deg,#4F46E5,#22D3EE);transition:width 1s ease;}
.pfill.done{background:linear-gradient(90deg,#34D399,#10b981);}

.due-item{display:flex;align-items:center;gap:10px;padding:9px 0;border-bottom:1px solid rgba(255,255,255,.06);}
.due-item:last-child{border-bottom:none;}
.due-dot{width:8px;height:8px;border-radius:999px;flex-shrink:0;}
.due-name{font-size:13px;font-weight:700;flex:1;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
.due-date{font-size:11.5px;color:var(--muted);white-space:nowrap;}
.due-urgent{color:var(--danger)!important;}

.badges-grid{display:grid;grid-template-columns:repeat(2,1fr);gap:8px;}
.badge-item{display:flex;align-items:center;gap:9px;padding:9px;border-radius:14px;border:1px solid rgba(255,255,255,.08);background:rgba(255,255,255,.03);}
.badge-icon{width:34px;height:34px;border-radius:11px;display:grid;place-items:center;flex-shrink:0;font-size:13px;}
.badge-name{font-size:12px;font-weight:700;line-height:1.2;}
.badge-pts{font-size:11px;color:rgba(251,191,36,.90);font-weight:700;}

.notif-item{display:flex;gap:10px;align-items:flex-start;padding:9px 0;border-bottom:1px solid rgba(255,255,255,.06);}
.notif-item:last-child{border-bottom:none;}
.notif-icon{width:32px;height:32px;border-radius:11px;display:grid;place-items:center;flex-shrink:0;background:rgba(255,255,255,.05);border:1px solid rgba(255,255,255,.09);font-size:12px;}
.notif-title{font-size:12.5px;font-weight:700;}
.notif-msg{font-size:11.5px;color:var(--muted);margin-top:1px;display:-webkit-box;-webkit-line-clamp:1;-webkit-box-orient:vertical;overflow:hidden;}
.notif-time{font-size:10.5px;color:var(--muted2);margin-top:3px;}
.unread-dot{width:6px;height:6px;border-radius:999px;background:var(--primary);flex-shrink:0;margin-top:5px;}

.section-label{font-size:16px;font-weight:900;margin-bottom:10px;}
.actions-row{display:flex;gap:10px;flex-wrap:wrap;}
.action-btn{display:flex;align-items:center;gap:9px;padding:11px 18px;border-radius:14px;border:1px solid rgba(255,255,255,.11);background:rgba(255,255,255,.05);font-size:13.5px;font-weight:700;transition:all .17s;}
.action-btn:hover{background:rgba(79,70,229,.20);border-color:rgba(79,70,229,.35);transform:translateY(-1px);}
.action-btn i{font-size:14px;color:var(--primary);}

.empty{text-align:center;padding:20px 10px;color:var(--muted);font-size:13px;}
.empty i{font-size:22px;display:block;margin-bottom:8px;opacity:.5;}

/* Light theme — dashboard-specific overrides */
[data-theme="light"] .page-header,
[data-theme="light"] .stat-card,
[data-theme="light"] .card{background:rgba(255,255,255,.65)!important;border-color:rgba(79,70,229,.12)!important;}
[data-theme="light"] .card-head{border-bottom-color:rgba(79,70,229,.10)!important;}
[data-theme="light"] .goal-item,
[data-theme="light"] .due-item,
[data-theme="light"] .notif-item{border-bottom-color:rgba(79,70,229,.08);}
[data-theme="light"] .badge-item{background:rgba(79,70,229,.04);border-color:rgba(79,70,229,.10);}
[data-theme="light"] .notif-icon{background:rgba(79,70,229,.07);border-color:rgba(79,70,229,.12);}
[data-theme="light"] .action-btn{background:rgba(255,255,255,.80);border-color:rgba(79,70,229,.16);}
[data-theme="light"] .action-btn:hover{background:rgba(79,70,229,.10);border-color:rgba(79,70,229,.30);}
[data-theme="light"] .pbar{background:rgba(79,70,229,.10);}
[data-theme="light"] .goal-title,
[data-theme="light"] .stat-num,
[data-theme="light"] .card-head h3,
[data-theme="light"] .section-label{color:#1a1f3c;}

@media(max-width:1000px){.stats-row{grid-template-columns:repeat(2,1fr);}.charts-row{grid-template-columns:1fr;}.donut-canvas{width:120px!important;height:120px!important;}}
@media(max-width:640px){.stats-row{grid-template-columns:repeat(2,1fr);}.bottom-grid{grid-template-columns:1fr;}.charts-row{grid-template-columns:1fr;}}
</style>
</head>
<body>
<?php nav_body(); ?>

  <!-- Page header -->
  <div class="page-header">
    <div>
      <h1>👋 Welcome back, <?= htmlspecialchars(explode(' ', $_SESSION['name'])[0]) ?>!</h1>
      <p><?= date('l, F j, Y') ?> · <?= $streak ?> day streak · <?= $total_badges ?> badges earned</p>
    </div>
    <button class="theme-btn" id="themeBtn">
      <div class="tgl-track" id="themeTrack"><div class="tgl-thumb"></div></div>
      <span id="themeLabel">Dark</span>
    </button>
  </div>

  <!-- Stat cards -->
  <div class="stats-row">
    <div class="stat-card">
      <div class="stat-icon" style="background:linear-gradient(135deg,rgba(79,70,229,.35),rgba(79,70,229,.15));color:#818CF8;"><i class="fas fa-bullseye"></i></div>
      <div class="stat-num"><?= $total_goals ?></div>
      <div class="stat-lbl">Total Goals</div>
    </div>
    <div class="stat-card">
      <div class="stat-icon" style="background:linear-gradient(135deg,rgba(52,211,153,.35),rgba(52,211,153,.15));color:#34D399;"><i class="fas fa-check-circle"></i></div>
      <div class="stat-num"><?= $completed_goals ?></div>
      <div class="stat-lbl">Completed</div>
    </div>
    <div class="stat-card">
      <div class="stat-icon" style="background:linear-gradient(135deg,rgba(251,191,36,.30),rgba(251,191,36,.12));color:#FBBF24;"><i class="fas fa-star"></i></div>
      <div class="stat-num"><?= $total_points ?></div>
      <div class="stat-lbl">Total Points</div>
    </div>
    <div class="stat-card">
      <div class="stat-icon" style="background:linear-gradient(135deg,rgba(239,68,68,.30),rgba(239,68,68,.12));color:#FB7185;"><i class="fas fa-fire"></i></div>
      <div class="stat-num"><?= $streak ?></div>
      <div class="stat-lbl">Day Streak</div>
    </div>
  </div>

  <!-- Charts row -->
  <div class="charts-row">
    <div class="card">
      <div class="card-head"><h3><i class="fas fa-chart-pie"></i> Goal Status</h3></div>
      <div class="card-body">
        <?php if ($total_goals === 0): ?>
          <div class="empty"><i class="fas fa-bullseye"></i>No goals yet — <a href="create_goal.php" style="color:var(--primary);">create one!</a></div>
        <?php else: ?>
        <div class="donut-wrap">
          <canvas id="donutChart" class="donut-canvas"></canvas>
          <div class="donut-legend">
            <div class="legend-item"><span class="legend-dot" style="background:#34D399;"></span> Completed <span class="legend-val"><?= $completed_goals ?></span></div>
            <div class="legend-item"><span class="legend-dot" style="background:#818CF8;"></span> In Progress <span class="legend-val"><?= $in_progress ?></span></div>
            <div class="legend-item"><span class="legend-dot" style="background:#FBBF24;"></span> Pending <span class="legend-val"><?= $pending_goals ?></span></div>
            <div class="legend-item"><span class="legend-dot" style="background:#FB7185;"></span> Overdue <span class="legend-val"><?= $overdue_goals ?></span></div>
          </div>
        </div>
        <?php endif; ?>
      </div>
    </div>
    <div class="card">
      <div class="card-head"><h3><i class="fas fa-chart-bar"></i> Goals Completed This Week</h3></div>
      <div class="card-body"><canvas id="barChart" class="bar-canvas"></canvas></div>
    </div>
  </div>

  <!-- Bottom grid -->
  <div class="bottom-grid">

    <div class="card">
      <div class="card-head"><h3><i class="fas fa-list-check"></i> Recent Goals</h3><a href="goals.php">View all</a></div>
      <div class="card-body">
        <?php if (empty($recent_goals)): ?>
          <div class="empty"><i class="fas fa-bullseye"></i>No goals yet</div>
        <?php else: ?>
          <?php foreach ($recent_goals as $g):
            $pct = (float)$g['progress_percentage'];
            $s   = $g['status'];
          ?>
          <div class="goal-item">
            <div class="goal-row">
              <div class="goal-title"><?= htmlspecialchars($g['title']) ?></div>
              <span class="status-pill s-<?= $s ?>"><?= ucfirst(str_replace('_',' ',$s)) ?></span>
            </div>
            <?php if ($g['due_date']): ?><div class="goal-due">Due <?= date('M j', strtotime($g['due_date'])) ?></div><?php endif; ?>
            <div class="pbar"><div class="pfill <?= $s==='completed'?'done':'' ?>" style="width:<?= $pct ?>%"></div></div>
          </div>
          <?php endforeach; ?>
        <?php endif; ?>
      </div>
    </div>

    <div class="card">
      <div class="card-head"><h3><i class="fas fa-calendar-alt"></i> Upcoming Deadlines</h3><a href="goals.php">View all</a></div>
      <div class="card-body">
        <?php if (empty($upcoming)): ?>
          <div class="empty"><i class="fas fa-calendar-check"></i>No upcoming deadlines</div>
        <?php else: ?>
          <?php foreach ($upcoming as $u):
            $days = (int)ceil((strtotime($u['due_date']) - time()) / 86400);
            $urgent = $days <= 2;
          ?>
          <div class="due-item">
            <div class="due-dot" style="background:<?= $urgent?'#FB7185':'#34D399' ?>;"></div>
            <div class="due-name"><?= htmlspecialchars($u['title']) ?></div>
            <div class="due-date <?= $urgent?'due-urgent':'' ?>"><?= $days === 0 ? 'Today' : ($days === 1 ? 'Tomorrow' : "in $days days") ?></div>
          </div>
          <?php endforeach; ?>
        <?php endif; ?>
      </div>
    </div>

    <div class="card">
      <div class="card-head"><h3><i class="fas fa-trophy"></i> Recent Badges</h3><a href="achievements.php">View all</a></div>
      <div class="card-body">
        <?php if (empty($recent_badges)): ?>
          <div class="empty"><i class="fas fa-trophy"></i>Complete goals to earn badges!</div>
        <?php else: ?>
          <div class="badges-grid">
            <?php foreach ($recent_badges as $b): ?>
            <div class="badge-item">
              <div class="badge-icon" style="background:<?= htmlspecialchars($b['color']) ?>;"><i class="fas fa-<?= htmlspecialchars($b['icon']) ?>"></i></div>
              <div>
                <div class="badge-name"><?= htmlspecialchars($b['title']) ?></div>
                <div class="badge-pts">+<?= $b['points'] ?> pts</div>
              </div>
            </div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>
    </div>

    <div class="card">
      <div class="card-head"><h3><i class="fas fa-bell"></i> Notifications</h3><a href="notifications.php">View all</a></div>
      <div class="card-body">
        <?php if (empty($notifications)): ?>
          <div class="empty"><i class="fas fa-bell-slash"></i>No notifications yet</div>
        <?php else: ?>
          <?php foreach ($notifications as $n):
            $icon = match($n['type']) {
              'achievement' => 'trophy',
              'goal'        => 'bullseye',
              'reminder'    => 'clock',
              default       => 'info-circle'
            };
          ?>
          <div class="notif-item">
            <?php if (!$n['is_read']): ?><div class="unread-dot"></div><?php endif; ?>
            <div class="notif-icon"><i class="fas fa-<?= $icon ?>"></i></div>
            <div>
              <div class="notif-title"><?= htmlspecialchars($n['title']) ?></div>
              <div class="notif-msg"><?= htmlspecialchars($n['message']) ?></div>
              <div class="notif-time"><?= date('M j, g:i a', strtotime($n['created_at'])) ?></div>
            </div>
          </div>
          <?php endforeach; ?>
        <?php endif; ?>
      </div>
    </div>

  </div>

  <!-- Quick actions -->
  <div class="section-label">Quick Actions</div>
  <div class="actions-row">
    <a href="create_goal.php" class="action-btn"><i class="fas fa-plus-circle"></i> Create Goal</a>
    <a href="goals.php"       class="action-btn"><i class="fas fa-list-check"></i> My Goals</a>
    <a href="achievements.php"class="action-btn"><i class="fas fa-trophy"></i> Achievements</a>
    <a href="profile.php"     class="action-btn"><i class="fas fa-user-edit"></i> Edit Profile</a>
  </div>

</main></div>


<script>
// Progress bars animate in
document.querySelectorAll('.pfill').forEach(b => {
  const w = b.style.width; b.style.width = '0';
  setTimeout(() => b.style.width = w, 200);
});

Chart.defaults.font.family = 'Inter';

function isLight(){ return document.documentElement.getAttribute('data-theme')==='light'; }
function tcol(){ return isLight() ? 'rgba(26,31,60,0.65)'  : 'rgba(234,240,255,0.65)'; }
function gcol(){ return isLight() ? 'rgba(79,70,229,0.10)' : 'rgba(255,255,255,0.05)'; }

// Donut chart (build once)
const donutEl = document.getElementById('donutChart');
if (donutEl) {
  new Chart(donutEl, {
    type: 'doughnut',
    data: {
      labels: <?= $donut_labels ?>,
      datasets: [{ data: <?= $donut_data ?>, backgroundColor: ['#34D399','#818CF8','#FBBF24','#FB7185'], borderWidth: 0, hoverOffset: 6 }]
    },
    options: {
      responsive: false, cutout: '72%',
      animation: { animateScale: true, duration: 900 },
      plugins: { legend: { display: false }, tooltip: { callbacks: { label: ctx => ` ${ctx.label}: ${ctx.parsed}` } } }
    }
  });
}

// Bar chart - rebuild on theme change
const barEl     = document.getElementById('barChart');
const barLabels = <?= $bar_labels ?>;
const barData   = <?= $bar_data ?>;
let   barInst   = null;

function buildBar() {
  if (barInst) { barInst.destroy(); barInst = null; }
  if (!barEl) return;
  barInst = new Chart(barEl, {
    type: 'bar',
    data: {
      labels: barLabels,
      datasets: [{ label: 'Goals completed', data: barData,
        backgroundColor: 'rgba(79,70,229,0.55)',
        hoverBackgroundColor: 'rgba(79,70,229,0.85)',
        borderRadius: 8, borderSkipped: false }]
    },
    options: {
      responsive: true, maintainAspectRatio: false,
      animation: { duration: 500 },
      scales: {
        x: { grid: { color: gcol() }, border: { display: false },
             ticks: { color: tcol(), font: { size: 12, weight: '700' } } },
        y: { beginAtZero: true, grid: { color: gcol() }, border: { display: false },
             ticks: { color: tcol(), stepSize: 1, precision: 0, font: { size: 11 } } }
      },
      plugins: { legend: { display: false } }
    }
  });
}
buildBar();

// nav_js() calls this hook every time the theme toggles
window.onThemeChange = function() { buildBar(); };
</script>
<?php nav_js(); ?>
</body>
</html>