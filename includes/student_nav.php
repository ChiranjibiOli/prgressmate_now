<?php
/**
 * includes/student_nav.php
 * ONE file — include ONCE per page, outputs everything needed.
 *
 * Usage in every student page:
 * ────────────────────────────
 *   STEP 1 — Top of <head> (anti-flash):
 *     <?php require_once '../includes/student_nav.php'; nav_head(); ?>
 *
 *   STEP 2 — Inside <body>, where sidebar goes:
 *     <?php nav_body(); ?>
 *     ... your page content here ...
 *     </main></div>
 *
 *   STEP 3 — Before </body>:
 *     <?php nav_js(); ?>
 *
 *   Theme toggle button (paste anywhere in your page header):
 *     <button class="theme-btn" id="themeBtn">
 *       <div class="tgl-track" id="themeTrack"><div class="tgl-thumb"></div></div>
 *       <span id="themeLabel">Dark</span>
 *     </button>
 *
 * Requires: $pdo, $student_id (set before calling nav_body())
 */

/* ─────────────────────────────────────────────
   nav_head()  →  call inside <head>
   Outputs anti-flash theme script
   ───────────────────────────────────────────── */
function nav_head() {
    echo '<script>
(function(){
  var t=localStorage.getItem("pm_theme");
  if(t==="light")document.documentElement.setAttribute("data-theme","light");
})();
</script>';
}

/* ─────────────────────────────────────────────
   nav_body()  →  call at start of <body>
   Outputs: CSS vars, sidebar CSS, light theme,
            mobile toggle, sidebar HTML, opens
            .dashboard-wrapper and main.main-content
   ───────────────────────────────────────────── */
function nav_body() {
    global $pdo, $student_id;

    $cur      = basename($_SERVER['PHP_SELF']);
    $sb_total     = getStat($pdo,"SELECT COUNT(*) FROM student_goals WHERE student_id=? AND deleted_at IS NULL",[$student_id]);
    $sb_done      = getStat($pdo,"SELECT COUNT(*) FROM student_goals WHERE student_id=? AND status='completed' AND deleted_at IS NULL",[$student_id]);
    $sb_pts       = getStat($pdo,"SELECT COALESCE(points,0) FROM users WHERE id=?",[$student_id]);
    $sb_streak    = getStat($pdo,"SELECT COALESCE(current_streak,0) FROM users WHERE id=?",[$student_id]);
    $sb_unread    = getStat($pdo,"SELECT COUNT(*) FROM notifications WHERE user_id=? AND is_read=0 AND deleted_at IS NULL",[$student_id]);

    $name     = htmlspecialchars($_SESSION['name'] ?? '');
    $email    = htmlspecialchars($_SESSION['email'] ?? '');
    $initial  = strtoupper(substr($_SESSION['name'] ?? 'S', 0, 1));
    $pic      = !empty($_SESSION['profile_picture'])
        ? '<img src="../'.htmlspecialchars($_SESSION['profile_picture']).'" alt="Profile" class="sb-pic">'
        : '<div class="sb-pic sb-pic-default">'.$initial.'</div>';

    $badge = $sb_unread > 0 ? '<span class="sb-badge">'.$sb_unread.'</span>' : '';

    // nav link helper
    $nl = function($href,$icon,$label,$extra='') use ($cur) {
        $a = ($cur===$href)?' active':'';
        return '<a href="'.$href.'" class="nav-link'.$a.'"><i class="fas '.$icon.'"></i><span>'.$label.'</span>'.$extra.'</a>';
    };

    echo <<<HTML
<style>
/* ══════════════════════════════════════════
   CSS VARIABLES — dark default (goals.php exact)
   ══════════════════════════════════════════ */
:root{
  --bg0:#070A18;--bg1:#0B1030;
  --text:#EAF0FF;
  --muted:rgba(234,240,255,.65);
  --muted2:rgba(234,240,255,.50);
  --primary:#4F46E5;--primary-light:rgba(79,70,229,.14);
  --cyan:#22D3EE;--pink:#60A5FA;
  --success:#34D399;--warning:#FBBF24;--danger:#FB7185;--info:#22D3EE;
  --border:rgba(255,255,255,.10);--border2:rgba(255,255,255,.08);
  --shadow:0 18px 45px rgba(0,0,0,.35);--shadow2:0 10px 30px rgba(0,0,0,.22);
  --r12:12px;--r14:14px;--r16:16px;--r20:20px;
  --field:rgba(255,255,255,.05);--field2:rgba(255,255,255,.03);
}
/* ══ LIGHT THEME ══ */
[data-theme="light"]{
  --text:#1a1f3c;--muted:rgba(26,31,60,.58);--muted2:rgba(26,31,60,.38);
  --border:rgba(79,70,229,.14);--border2:rgba(79,70,229,.09);
  --shadow:0 18px 45px rgba(79,70,229,.12);--shadow2:0 10px 30px rgba(79,70,229,.08);
  --field:rgba(255,255,255,.85);--field2:rgba(255,255,255,.65);
}
[data-theme="light"] body{
  background:
    radial-gradient(900px 520px at 18% 10%,rgba(79,70,229,.10),transparent 60%),
    radial-gradient(900px 520px at 88% 15%,rgba(34,211,238,.07),transparent 58%),
    radial-gradient(900px 520px at 70% 95%,rgba(96,165,250,.07),transparent 62%),
    linear-gradient(180deg,#f0f4ff,#e8eeff);
  color:#1a1f3c;
}
[data-theme="light"] .sidebar{
  background:
    radial-gradient(700px 320px at 20% 0%,rgba(79,70,229,.10),transparent 60%),
    radial-gradient(520px 300px at 100% 20%,rgba(34,211,238,.07),transparent 60%),
    rgba(240,244,255,.97);
  border-right:1px solid rgba(79,70,229,.15);
}
[data-theme="light"] .logo{color:#1a1f3c;}
[data-theme="light"] .nav-link{color:rgba(26,31,60,.75);}
[data-theme="light"] .nav-link i{background:rgba(79,70,229,.07);border-color:rgba(79,70,229,.14);}
[data-theme="light"] .nav-link:hover{background:rgba(79,70,229,.08);border-color:rgba(79,70,229,.18);color:#1a1f3c;}
[data-theme="light"] .nav-link.active{color:#1a1f3c;}
[data-theme="light"] .sb-profile{background:rgba(255,255,255,.60);border-color:rgba(79,70,229,.12);}
[data-theme="light"] .sb-name{color:#1a1f3c;}
[data-theme="light"] .sidebar-quick-stats{border-color:rgba(79,70,229,.10);background:rgba(79,70,229,.04);}
[data-theme="light"] .sidebar-stat-number{color:#1a1f3c;}
[data-theme="light"] .logout-btn{color:rgba(26,31,60,.75);}
[data-theme="light"] .mobile-toggle{background:rgba(240,244,255,.92);color:#1a1f3c;}
/* light theme form/card overrides for pages */
[data-theme="light"] .stat-card,[data-theme="light"] .goal-card,
[data-theme="light"] .card,[data-theme="light"] .page-header{
  background:rgba(255,255,255,.65)!important;border-color:rgba(79,70,229,.12)!important;
  color:#1a1f3c;
}
[data-theme="light"] .goal-description,[data-theme="light"] .goal-meta-item,
[data-theme="light"] .progress-label,[data-theme="light"] .stat-label,
[data-theme="light"] .notification-message,[data-theme="light"] .notification-time{
  color:rgba(26,31,60,.60);
}
[data-theme="light"] .progress-bar{background:rgba(79,70,229,.12);}
[data-theme="light"] .goal-header{background:rgba(255,255,255,.30)!important;border-bottom-color:rgba(79,70,229,.10)!important;}
[data-theme="light"] .goal-footer{background:rgba(255,255,255,.30)!important;border-top-color:rgba(79,70,229,.10)!important;}
[data-theme="light"] .dropdown-menu{background:rgba(240,244,255,.97);border-color:rgba(79,70,229,.18);}
[data-theme="light"] .dropdown-item{color:rgba(26,31,60,.80);}
[data-theme="light"] .modal{background:rgba(240,244,255,.98);border-color:rgba(79,70,229,.18);}
[data-theme="light"] .modal-header{background:rgba(255,255,255,.50);border-bottom-color:rgba(79,70,229,.10);}
[data-theme="light"] .form-group input,[data-theme="light"] .form-group select,[data-theme="light"] .form-group textarea{background:rgba(255,255,255,.90);border-color:rgba(79,70,229,.22);color:#1a1f3c;}
[data-theme="light"] .header-content h1,[data-theme="light"] .goal-title,
[data-theme="light"] .card h3,[data-theme="light"] .stat-number{color:#1a1f3c;}
[data-theme="light"] .notification-item{background:rgba(255,255,255,.60)!important;border-color:rgba(79,70,229,.10)!important;}
[data-theme="light"] .notification-item.unread{background:rgba(79,70,229,.06)!important;border-color:rgba(79,70,229,.22)!important;}
[data-theme="light"] .achievement-card{background:rgba(255,255,255,.65)!important;border-color:rgba(79,70,229,.12)!important;}
[data-theme="light"] .achievement-name,[data-theme="light"] .achievement-desc{color:rgba(26,31,60,.80);}
[data-theme="light"] .section-divider{color:#1a1f3c;border-bottom-color:rgba(79,70,229,.14);}

/* ══ RESET + BASE ══ */
*{box-sizing:border-box;}
html,body{height:100%;}
body{
  margin:0;color:var(--text);
  font-family:'Inter',system-ui,-apple-system,Segoe UI,Roboto,Arial,sans-serif;
  background:
    radial-gradient(900px 520px at 18% 10%,rgba(79,70,229,.22),transparent 60%),
    radial-gradient(900px 520px at 88% 15%,rgba(34,211,238,.18),transparent 58%),
    radial-gradient(900px 520px at 70% 95%,rgba(96,165,250,.14),transparent 62%),
    linear-gradient(180deg,var(--bg0),var(--bg1));
  overflow-x:hidden;
}
a{color:inherit;text-decoration:none;}
img{max-width:100%;display:block;}
button,input,select,textarea{font-family:inherit;}

/* ══ MOBILE TOGGLE ══ */
.mobile-toggle{
  position:fixed;top:16px;left:16px;z-index:2000;
  width:44px;height:44px;display:none;place-items:center;
  border-radius:14px;border:1px solid var(--border);
  background:rgba(10,14,35,.60);color:var(--text);
  box-shadow:var(--shadow2);backdrop-filter:blur(12px);cursor:pointer;
}
.mobile-toggle i{font-size:18px;}
.sidebar-overlay{
  position:fixed;inset:0;background:rgba(0,0,0,.45);
  opacity:0;pointer-events:none;transition:opacity .2s ease;z-index:1600;
}
.sidebar-overlay.active{opacity:1;pointer-events:auto;}

/* ══ LAYOUT ══ */
.dashboard-wrapper{display:grid;grid-template-columns:320px 1fr;min-height:100vh;}

/* ══ SIDEBAR (exact goals.php) ══ */
.sidebar{
  position:sticky;top:0;height:100vh;overflow:hidden;
  display:flex;flex-direction:column;padding:18px 16px 16px;
  background:
    radial-gradient(700px 320px at 20% 0%,rgba(79,70,229,.18),transparent 60%),
    radial-gradient(520px 300px at 100% 20%,rgba(34,211,238,.14),transparent 60%),
    linear-gradient(180deg,rgba(10,14,35,.85),rgba(10,14,35,.62));
  border-right:1px solid rgba(255,255,255,.10);
  backdrop-filter:blur(16px);box-shadow:0 10px 50px rgba(0,0,0,.25);
}
.sidebar::before{
  content:"";position:absolute;inset:-2px;
  background:linear-gradient(120deg,rgba(79,70,229,.20),rgba(34,211,238,.14),rgba(96,165,250,.10));
  opacity:.22;filter:blur(26px);pointer-events:none;z-index:0;
}
.sidebar-header,.sb-profile,.nav-menu,.sidebar-quick-stats,.sidebar-footer{position:relative;z-index:2;}

.sidebar-header{
  display:flex;align-items:center;justify-content:space-between;padding:10px 10px 12px;
}
.logo{display:flex;align-items:center;gap:10px;font-weight:900;letter-spacing:.2px;font-size:18px;}
.logo i{
  width:34px;height:34px;display:grid;place-items:center;border-radius:12px;
  background:
    radial-gradient(120% 140% at 30% 25%,rgba(255,255,255,.18),transparent 55%),
    linear-gradient(135deg,rgba(79,70,229,.70),rgba(34,211,238,.35));
  border:1px solid rgba(255,255,255,.18);box-shadow:0 14px 30px rgba(79,70,229,.18);
}
.sidebar-close{
  display:none;width:40px;height:40px;border-radius:14px;
  border:1px solid rgba(255,255,255,.14);background:rgba(255,255,255,.06);
  color:var(--text);cursor:pointer;place-items:center;
}

.sb-profile{
  display:flex;gap:12px;padding:12px;border-radius:var(--r16);
  border:1px solid var(--border2);
  background:
    radial-gradient(140% 180% at 10% 0%,rgba(255,255,255,.10),transparent 60%),
    linear-gradient(180deg,rgba(255,255,255,.05),rgba(255,255,255,.03));
  box-shadow:0 12px 26px rgba(0,0,0,.18);
}
.sb-pic{
  width:52px;height:52px;border-radius:16px;object-fit:cover;
  border:1px solid rgba(255,255,255,.16);box-shadow:0 10px 20px rgba(0,0,0,.22);flex-shrink:0;
}
.sb-pic-default{
  display:grid;place-items:center;font-weight:950;font-size:18px;
  background:
    radial-gradient(120% 140% at 30% 25%,rgba(255,255,255,.18),transparent 55%),
    linear-gradient(135deg,rgba(34,211,238,.55),rgba(79,70,229,.55));
}
.sb-name{margin:2px 0;font-size:15px;font-weight:900;}
.sb-email{
  margin:0;font-size:12.5px;color:var(--muted);
  overflow:hidden;text-overflow:ellipsis;white-space:nowrap;max-width:210px;
}

.nav-menu{
  flex:1 1 auto;overflow-y:auto;overflow-x:hidden;
  padding:12px 6px 8px;margin-top:8px;
  display:flex;flex-direction:column;gap:6px;
  scrollbar-width:thin;scrollbar-color:rgba(255,255,255,.18) transparent;
}
.nav-menu::-webkit-scrollbar{width:8px;}
.nav-menu::-webkit-scrollbar-thumb{background:rgba(255,255,255,.16);border-radius:99px;}
.nav-link{
  display:flex;align-items:center;gap:12px;padding:12px;
  border-radius:14px;color:rgba(234,240,255,.92);border:1px solid transparent;
  transition:transform .18s,background .18s,border-color .18s,box-shadow .18s;
  min-height:46px;font-size:14.5px;
}
.nav-link i{
  width:34px;height:34px;display:grid;place-items:center;border-radius:12px;
  background:rgba(255,255,255,.05);border:1px solid rgba(255,255,255,.10);flex-shrink:0;
}
.nav-link:hover{background:rgba(255,255,255,.06);border-color:rgba(255,255,255,.12);transform:translateX(2px);}
.nav-link.active{
  background:
    radial-gradient(120% 160% at 10% 20%,rgba(255,255,255,.14),transparent 55%),
    linear-gradient(135deg,rgba(79,70,229,.55),rgba(34,211,238,.20));
  border-color:rgba(255,255,255,.18);box-shadow:0 18px 40px rgba(79,70,229,.18);
}
.sb-badge{
  margin-left:auto;font-size:12px;font-weight:900;padding:4px 10px;
  border-radius:999px;color:var(--text);
  background:
    radial-gradient(120% 180% at 20% 20%,rgba(255,255,255,.20),transparent 55%),
    linear-gradient(135deg,rgba(96,165,250,.70),rgba(79,70,229,.45));
  border:1px solid rgba(255,255,255,.18);
}

.sidebar-quick-stats{
  margin-top:10px;padding:10px;border-radius:var(--r16);
  border:1px solid rgba(255,255,255,.10);background:rgba(255,255,255,.03);
}
.sidebar-stat{display:flex;gap:12px;align-items:center;padding:10px;border-radius:14px;}
.sidebar-stat:hover{background:rgba(255,255,255,.04);}
.sidebar-stat-icon{
  width:38px;height:38px;border-radius:14px;display:grid;place-items:center;
  border:1px solid rgba(255,255,255,.12);
  background:
    radial-gradient(120% 180% at 20% 10%,rgba(255,255,255,.18),transparent 55%),
    linear-gradient(135deg,rgba(34,211,238,.35),rgba(79,70,229,.35));
  box-shadow:0 14px 26px rgba(0,0,0,.18);
}
.sidebar-stat-label{font-size:12px;color:var(--muted);}
.sidebar-stat-number{font-size:18px;font-weight:950;}

.sidebar-footer{margin-top:12px;}
.logout-btn{
  display:flex;align-items:center;justify-content:center;gap:10px;padding:12px;
  border-radius:14px;border:1px solid rgba(255,255,255,.14);
  background:
    radial-gradient(140% 180% at 20% 0%,rgba(255,255,255,.10),transparent 60%),
    linear-gradient(135deg,rgba(96,165,250,.16),rgba(255,255,255,.03));
  box-shadow:0 14px 26px rgba(0,0,0,.16);
}

/* ══ MAIN ══ */
.main-content{padding:22px 22px 32px;}
.main-content>*{max-width:1180px;}

/* ══ THEME TOGGLE BUTTON (shared) ══ */
.theme-btn{
  display:inline-flex;align-items:center;gap:8px;padding:8px 14px;border-radius:999px;
  border:1px solid var(--border);background:rgba(255,255,255,.06);
  color:var(--text);font-size:13px;font-weight:700;cursor:pointer;
  transition:background .18s,border-color .18s;font-family:inherit;
}
.theme-btn:hover{background:rgba(255,255,255,.10);}
[data-theme="light"] .theme-btn{background:rgba(79,70,229,.07);border-color:rgba(79,70,229,.20);}
.tgl-track{
  width:36px;height:20px;border-radius:999px;
  border:1px solid rgba(255,255,255,.22);background:rgba(255,255,255,.14);
  position:relative;transition:background .2s;flex-shrink:0;
}
.tgl-track.on{background:linear-gradient(135deg,rgba(79,70,229,.85),rgba(34,211,238,.55));}
.tgl-thumb{
  position:absolute;top:2px;left:2px;width:14px;height:14px;border-radius:50%;
  background:#fff;transition:transform .2s;box-shadow:0 2px 6px rgba(0,0,0,.28);
}
.tgl-track.on .tgl-thumb{transform:translateX(16px);}

/* ══ RESPONSIVE ══ */
@media(max-width:860px){
  .dashboard-wrapper{grid-template-columns:1fr;}
  .mobile-toggle{display:grid;}
  .sidebar{
    position:fixed;left:0;top:0;width:320px;
    transform:translateX(-105%);transition:transform .25s ease;z-index:1601;
  }
  .sidebar.active{transform:translateX(0);}
  .sidebar-close{display:grid;}
  .main-content{padding:18px 14px 28px;}
  .page-header{flex-direction:column;align-items:flex-start;}
}
</style>

    <button class="mobile-toggle" id="sidebarToggle"><i class="fas fa-bars"></i></button>
    <div class="sidebar-overlay" id="sidebarOverlay"></div>

    <div class="dashboard-wrapper">
      <aside class="sidebar" id="sidebar">
        <div class="sidebar-header">
          <div class="logo"><i class="fas fa-star"></i><span>ProgressMate</span></div>
          <button class="sidebar-close" id="sidebarClose"><i class="fas fa-times"></i></button>
        </div>
        <div class="sb-profile">
          {$pic}
          <div>
            <div class="sb-name">{$name}</div>
            <div class="sb-email">{$email}</div>
            <span style="font-size:11px;background:#e0e7ff;color:#4f46e5;padding:2px 8px;border-radius:12px;display:inline-block;margin-top:4px;">STUDENT</span>
          </div>
        </div>
        <nav class="nav-menu">
          {$nl('dashboard.php',   'fa-tachometer-alt','Dashboard')}
          {$nl('goals.php',       'fa-bullseye',      'Goals')}
          {$nl('achievements.php','fa-trophy',        'Achievements')}
          {$nl('notifications.php','fa-bell',         'Notifications',$badge)}
          {$nl('profile.php',     'fa-user',          'Profile')}
        </nav>
        <div class="sidebar-quick-stats">
          <div class="sidebar-stat">
            <div class="sidebar-stat-icon"><i class="fas fa-bullseye"></i></div>
            <div><div class="sidebar-stat-label">Goals</div><div class="sidebar-stat-number">{$sb_done}/{$sb_total}</div></div>
          </div>
          <div class="sidebar-stat">
            <div class="sidebar-stat-icon"><i class="fas fa-star"></i></div>
            <div><div class="sidebar-stat-label">Points</div><div class="sidebar-stat-number">{$sb_pts}</div></div>
          </div>
          <div class="sidebar-stat">
            <div class="sidebar-stat-icon"><i class="fas fa-fire"></i></div>
            <div><div class="sidebar-stat-label">Streak</div><div class="sidebar-stat-number">{$sb_streak} days</div></div>
          </div>
        </div>
        <div class="sidebar-footer">
          <a href="../logout.php" class="logout-btn"><i class="fas fa-sign-out-alt"></i><span>Logout</span></a>
        </div>
      </aside>
      <main class="main-content">
HTML;
}

/* ─────────────────────────────────────────────
   nav_js()  →  call before </body>
   Outputs sidebar toggle + global theme toggle JS
   ───────────────────────────────────────────── */
function nav_js() {
    echo '<script>
// Sidebar
document.getElementById("sidebarToggle")?.addEventListener("click",function(){
  document.getElementById("sidebar").classList.add("active");
  document.getElementById("sidebarOverlay").classList.add("active");
});
document.getElementById("sidebarClose")?.addEventListener("click",function(){
  document.getElementById("sidebar").classList.remove("active");
  document.getElementById("sidebarOverlay").classList.remove("active");
});
document.getElementById("sidebarOverlay")?.addEventListener("click",function(){
  document.getElementById("sidebar").classList.remove("active");
  this.classList.remove("active");
});
// Theme
(function(){
  function applyTheme(t){
    if(t==="light") document.documentElement.setAttribute("data-theme","light");
    else document.documentElement.removeAttribute("data-theme");
    var tr=document.getElementById("themeTrack"),lb=document.getElementById("themeLabel");
    if(tr) tr.classList.toggle("on",t==="light");
    if(lb) lb.textContent=t==="light"?"Light":"Dark";
    if(typeof window.onThemeChange==="function") window.onThemeChange(t);
  }
  applyTheme(localStorage.getItem("pm_theme")||"dark");
  document.getElementById("themeBtn")?.addEventListener("click",function(){
    var c=document.documentElement.getAttribute("data-theme")==="light"?"dark":"light";
    localStorage.setItem("pm_theme",c);applyTheme(c);
  });
})();
</script>';
}