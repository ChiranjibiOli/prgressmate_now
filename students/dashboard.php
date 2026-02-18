<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once '../includes/db_connection.php';
require_once '../includes/functions.php';
checkAuth('student');

$student_id = $_SESSION['user_id'];

// ====== Sidebar Stats ======
$total_goals = getStat($pdo, "SELECT COUNT(*) FROM student_goals WHERE student_id=?", [$student_id]);
$completed_goals = getStat($pdo, "SELECT COUNT(*) FROM student_goals WHERE student_id=? AND status='completed'", [$student_id]);
$in_progress_goals = getStat($pdo, "SELECT COUNT(*) FROM student_goals WHERE student_id=? AND status='in_progress'", [$student_id]);
$total_points = getStat($pdo, "SELECT points FROM users WHERE id=?", [$student_id], 0);
$streak = getStat($pdo, "SELECT current_streak FROM users WHERE id=?", [$student_id], 0);
$unread = getStat($pdo, "SELECT COUNT(*) FROM notifications WHERE user_id=? AND is_read=0", [$student_id]);

// ====== Recent Goals (last 5) ======
$recent_stmt = $pdo->prepare("
    SELECT * FROM student_goals 
    WHERE student_id=?
    ORDER BY created_at DESC
    LIMIT 5
");
$recent_stmt->execute([$student_id]);
$recent_goals = $recent_stmt->fetchAll();

// ====== Upcoming Deadlines (next 7 days) ======
$upcoming_stmt = $pdo->prepare("
    SELECT * FROM student_goals
    WHERE student_id=? AND due_date >= CURDATE()
    ORDER BY due_date ASC
    LIMIT 5
");
$upcoming_stmt->execute([$student_id]);
$upcoming_deadlines = $upcoming_stmt->fetchAll();

// ====== Recent Achievements (last 5) ======
$achievements_stmt = $pdo->prepare("
    SELECT a.*, ua.earned_at
    FROM achievements a
    LEFT JOIN user_achievements ua 
    ON a.id=ua.achievement_id AND ua.user_id=?
    ORDER BY a.created_at DESC
    LIMIT 5
");
$achievements_stmt->execute([$student_id]);
$recent_achievements = $achievements_stmt->fetchAll();

// ====== Recent Notifications (last 5) ======
$notif_stmt = $pdo->prepare("
    SELECT * FROM notifications 
    WHERE user_id=? 
    ORDER BY created_at DESC
    LIMIT 5
");
$notif_stmt->execute([$student_id]);
$notifications = $notif_stmt->fetchAll();

// ====== Weekly Progress for Chart ======
$progress_stmt = $pdo->prepare("
    SELECT log_date, SUM(progress_added) AS total
    FROM progress_history
    WHERE student_id=?
    GROUP BY log_date
    ORDER BY log_date ASC
");
$progress_stmt->execute([$student_id]);
$progress_data = $progress_stmt->fetchAll(PDO::FETCH_ASSOC);
$current = basename($_SERVER['PHP_SELF']);
?>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

<script>
    document.addEventListener('DOMContentLoaded', function() {
        <?php
        // Prepare clean, readable dates and data
        $chart_labels = [];
        $chart_data = [];

        if (!empty($progress_data)) {
            foreach ($progress_data as $row) {
                $chart_labels[] = date('M j', strtotime($row['log_date'])); // e.g., "Dec 26"
                $chart_data[] = (float)$row['total'];
            }
        } else {
            $chart_labels = ['No data yet'];
            $chart_data = [0];
        }
        ?>

        const ctx = document.getElementById('progressChart').getContext('2d');

        new Chart(ctx, {
            type: 'line',
            data: {
                labels: <?php echo json_encode($chart_labels); ?>,
                datasets: [{
                    label: 'Daily Progress',
                    data: <?php echo json_encode($chart_data); ?>,
                    borderColor: '#4f46e5',
                    backgroundColor: 'rgba(79, 70, 229, 0.15)',
                    fill: true,
                    tension: 0.4,
                    pointBackgroundColor: '#4f46e5',
                    pointBorderColor: '#ffffff',
                    pointBorderWidth: 3,
                    pointRadius: 6,
                    pointHoverRadius: 9
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false, // Crucial for fixed height
                plugins: {
                    legend: {
                        display: false
                    },
                    tooltip: {
                        backgroundColor: 'rgba(0, 0, 0, 0.8)',
                        cornerRadius: 8,
                        titleFont: {
                            size: 14
                        },
                        bodyFont: {
                            size: 13
                        }
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        grid: {
                            color: 'rgba(0, 0, 0, 0.05)'
                        },
                        ticks: {
                            padding: 10
                        }
                    },
                    x: {
                        grid: {
                            display: false
                        },
                        ticks: {
                            padding: 10
                        }
                    }
                },
                animation: {
                    duration: 1800,
                    easing: 'easeOutQuart'
                }
            }
        });
    });
</script>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Student Dashboard - ProgressMate</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
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
            font-family: 'Inter', system-ui, -apple-system, sans-serif;
            background:
                radial-gradient(900px 520px at 18% 10%, rgba(79,70,229,.22), transparent 60%),
                radial-gradient(900px 520px at 88% 15%, rgba(34,211,238,.18), transparent 58%),
                radial-gradient(900px 520px at 70% 95%, rgba(96,165,250,.14), transparent 62%),
                linear-gradient(180deg, var(--bg0), var(--bg1));
            overflow-x:hidden;
        }

        a{ color: inherit; text-decoration:none; }
        img{ max-width:100%; display:block; }

        /* ===== MOBILE TOGGLE ===== */
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

        /* ===== LAYOUT ===== */
        .dashboard-wrapper{
            display: grid;
            grid-template-columns: 320px 1fr;
            min-height: 100vh;
        }

        /* ===== SIDEBAR (identical aesthetic) ===== */
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
        .sidebar *{ position:relative; z-index:2; }

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
            cursor:pointer;
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
            background:
                radial-gradient(120% 140% at 30% 25%, rgba(255,255,255,.18), transparent 55%),
                linear-gradient(135deg, rgba(34,211,238,.55), rgba(79,70,229,.55));
        }
        .user-info h4{ margin: 2px 0 2px; font-size: 15px; font-weight: 900; }
        .user-info p{
            margin: 0;
            font-size: 12.5px;
            color: var(--muted);
            white-space: nowrap;
        }

        .nav-menu{
            flex: 1 1 auto;
            overflow-y: auto;
            padding: 12px 6px 8px;
            margin-top: 8px;
            display:flex;
            flex-direction: column;
            gap: 6px;
        }
        .nav-menu::-webkit-scrollbar{ width: 8px; }
        .nav-menu::-webkit-scrollbar-thumb{
            background: rgba(255,255,255,.16);
            border-radius: 99px;
        }
        .nav-link{
            display:flex;
            align-items:center;
            gap: 12px;
            padding: 12px 12px;
            border-radius: 14px;
            color: rgba(234,240,255,.92);
            border: 1px solid transparent;
            transition: transform .18s ease, background .18s ease;
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
                linear-gradient(135deg, rgba(96,165,250,.70), rgba(79,70,229,.45));
            border: 1px solid rgba(255,255,255,.18);
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
            padding: 8px 10px;
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
        }

        /* ===== MAIN CONTENT ===== */
        .main-content{
            padding: 22px 22px 32px;
        }
        .main-content > *{
            max-width: 1180px;
        }

        .page-header{
            width: 100%;
            display:flex;
            align-items:center;
            justify-content: space-between;
            gap: 14px;
            padding: 16px 16px;
            border-radius: var(--r20);
            border: 1px solid var(--border);
            background:
                radial-gradient(120% 220% at 15% 10%, rgba(255,255,255,.10), transparent 55%),
                linear-gradient(180deg, rgba(255,255,255,.05), rgba(255,255,255,.03));
            box-shadow: var(--shadow2);
        }
        .header-content h1{ margin:0 0 6px; font-size: 24px; font-weight: 950; }
        .header-content p{ margin:0; color: var(--muted); font-size: 14px; }

        .btn{
            display:inline-flex;
            align-items:center;
            justify-content:center;
            gap: 10px;
            padding: 12px 14px;
            border-radius: 14px;
            font-weight: 900;
            border: 1px solid rgba(255,255,255,.14);
            color: var(--text);
            background: rgba(255,255,255,.05);
            cursor:pointer;
            transition: transform .18s ease, box-shadow .18s ease;
        }
        .btn:hover{
            transform: translateY(-1px);
            background: rgba(255,255,255,.07);
            box-shadow: 0 0 0 1px rgba(255,255,255,.08), 0 12px 30px rgba(79,70,229,.18);
        }
        .btn-primary{
            border-color: rgba(79,70,229,.35);
            background:
                radial-gradient(120% 180% at 20% 10%, rgba(255,255,255,.16), transparent 55%),
                linear-gradient(135deg, rgba(79,70,229,.62), rgba(34,211,238,.18));
        }

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
        }
        .stat-content{
            display:flex;
            align-items:center;
            gap: 12px;
            padding: 14px 14px;
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
        }
        .stat-number{ font-size: 24px; font-weight: 950; line-height: 1.1; }
        .stat-label{ margin-top: 2px; font-size: 13px; color: var(--muted); }

        .content-grid{
            width: 100%;
            margin-top: 14px;
            display:grid;
            grid-template-columns: 1fr 1fr;
            gap: 12px;
        }

        .card{
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
            margin: 0;
            font-size: 15px;
            font-weight: 950;
            display:flex;
            align-items:center;
            gap: 10px;
        }
        .card-header h3 i{
            width: 34px;
            height: 34px;
            border-radius: 14px;
            display:grid;
            place-items:center;
            border: 1px solid rgba(255,255,255,.12);
            background: rgba(255,255,255,.05);
        }
        .card-body{ padding: 12px 14px 14px; }

        .goal-item, .notification-item{
            border-radius: 16px;
            border: 1px solid rgba(255,255,255,.08);
            background: rgba(255,255,255,.03);
            padding: 12px 12px;
            margin-bottom: 10px;
        }
        .goal-header{
            display:flex;
            justify-content:space-between;
            align-items:center;
            margin-bottom:8px;
        }
        .goal-title{ font-weight:700; }
        .goal-date{ font-size:12px; color:var(--muted2); }
        .goal-status{
            font-size:12px;
            padding:4px 10px;
            border-radius:20px;
            background:rgba(255,255,255,.06);
            border:1px solid rgba(255,255,255,.08);
        }
        .progress-bar{
            height: 10px;
            width: 100%;
            border-radius: 999px;
            background: rgba(255,255,255,.07);
            border: 1px solid rgba(255,255,255,.08);
            overflow:hidden;
            margin: 8px 0 4px;
        }
        .progress-fill{
            height: 100%;
            width: 0%;
            border-radius: 999px;
            background: linear-gradient(90deg, rgba(34,211,238,.95), rgba(79,70,229,.95));
            transition: width 1s cubic-bezier(.22,.75,.12,1);
        }

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

        .achievements-grid{
            display:grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 10px;
        }
        .achievement-item{
            border-radius: 18px;
            border: 1px solid rgba(255,255,255,.10);
            background: rgba(255,255,255,.03);
            padding: 12px;
            text-align:center;
        }
        .achievement-icon{
            width: 46px;
            height: 46px;
            border-radius: 16px;
            display:grid;
            place-items:center;
            margin: 0 auto 8px;
            border: 1px solid rgba(255,255,255,.18);
        }
        .achievement-title{ font-weight: 950; font-size: 13px; }
        .achievement-points{ margin-top: 4px; font-size: 12px; color: var(--muted); }

        .quick-actions{
            display:grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 12px;
            margin-top: 20px;
        }
        .quick-action{
            display:flex;
            align-items:center;
            justify-content:center;
            gap: 10px;
            padding: 14px 14px;
            border-radius: 16px;
            font-weight: 950;
            border: 1px solid rgba(255,255,255,.12);
            background:
                radial-gradient(140% 220% at 10% 0%, rgba(255,255,255,.12), transparent 60%),
                linear-gradient(135deg, rgba(79,70,229,.22), rgba(34,211,238,.10));
            transition: transform .18s;
        }
        .quick-action:hover{ transform: translateY(-2px); }

        .notifications-list .notification-item{
            display:flex;
            gap:12px;
            align-items:flex-start;
        }
        .notification-icon i{
            width:34px; height:34px; display:grid; place-items:center;
            background:rgba(255,255,255,.05); border-radius:12px;
        }
        .notification-content{ flex:1; }
        .notification-time{ font-size:11px; color:var(--muted2); margin-top:4px; }

        canvas{ max-height:200px; width:100%; }

        /* responsive */
        @media (max-width: 1000px){
            .stats-grid{ grid-template-columns: repeat(2,1fr); }
            .content-grid{ grid-template-columns:1fr; }
            .achievements-grid{ grid-template-columns:repeat(2,1fr); }
            .quick-actions{ grid-template-columns:repeat(2,1fr); }
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
        }
        @media (max-width: 520px){
            .stats-grid, .achievements-grid, .quick-actions{ grid-template-columns:1fr; }
        }
        .mobile-toggle i{ font-size: 18px; }

/* ✅ ADD OVERLAY CSS HERE */
.sidebar-overlay{
    position: fixed;
    inset: 0;
    background: rgba(0,0,0,.45);
    backdrop-filter: blur(2px);
    opacity: 0;
    pointer-events: none;
    transition: opacity .2s ease;
    z-index: 1600;
}
.sidebar-overlay.active{
    opacity: 1;
    pointer-events: auto;
}

    </style>
</head>
<body>
    <!-- MOBILE TOGGLE -->
    <button class="mobile-toggle" id="sidebarToggle"><i class="fas fa-bars"></i></button>
    <div class="sidebar-overlay" id="sidebarOverlay"></div>

    <div class="dashboard-wrapper">
        <!-- SIDEBAR (student version) -->
        <aside class="sidebar" id="sidebar">
            <div class="sidebar-header">
                <div class="logo"><i class="fas fa-star"></i> ProgressMate</div>
                <button class="sidebar-close" id="sidebarClose"><i class="fas fa-times"></i></button>
            </div>

            <div class="user-profile">
                <!-- simulate session data -->
                <?php
                $_SESSION['profile_picture'] = ''; // empty for default
                $current = basename($_SERVER['PHP_SELF']); // but we'll set manually via dummy var
                $current = 'dashboard.php'; // mock
                ?>
                <?php if (!empty($_SESSION['profile_picture'])): ?>
                    <img src="../<?php echo htmlspecialchars($_SESSION['profile_picture']); ?>" alt="Profile" class="profile-pic">
                <?php else: ?>
                    <div class="profile-pic default"><?php echo strtoupper(substr($_SESSION['name'], 0, 1)); ?></div>
                <?php endif; ?>
                <div class="user-info">
                    <h4><?php echo htmlspecialchars($_SESSION['name']); ?></h4>
                    <p><?php echo htmlspecialchars($_SESSION['email']); ?></p>
                    <span style="font-size: 11px; background: #e0e7ff; color: #4f46e5; padding: 2px 8px; border-radius: 12px;">STUDENT</span>
                </div>
            </div>

            <nav class="nav-menu">
                <a href="dashboard.php" class="nav-link <?php echo ($current=='dashboard.php')?'active':''; ?>"><i class="fas fa-tachometer-alt"></i> Dashboard</a>
                <a href="goals.php" class="nav-link <?php echo ($current=='goals.php')?'active':''; ?>"><i class="fas fa-bullseye"></i> Goals</a>
                <a href="achievements.php" class="nav-link <?php echo ($current=='achievements.php')?'active':''; ?>"><i class="fas fa-trophy"></i> Achievements</a>
                <a href="notifications.php" class="nav-link <?php echo ($current=='notifications.php')?'active':''; ?>"><i class="fas fa-bell"></i> Notifications</a>
                <a href="profile.php" class="nav-link <?php echo ($current=='profile.php')?'active':''; ?>"><i class="fas fa-user"></i> Profile</a>
            </nav>

            <!-- quick stats with dummy numbers -->
            <div class="sidebar-quick-stats">
                <div class="sidebar-stat">
                    <div class="sidebar-stat-icon"><i class="fas fa-bullseye"></i></div>
                    <div class="sidebar-stat-info">
                        <div class="sidebar-stat-label">Goals Score</div>
                        <div class="sidebar-stat-number">8/12</div>
                    </div>
                </div>
                <div class="sidebar-stat">
                    <div class="sidebar-stat-icon"><i class="fas fa-star"></i></div>
                    <div class="sidebar-stat-info">
                        <div class="sidebar-stat-label">Points</div>
                        <div class="sidebar-stat-number">1450</div>
                    </div>
                </div>
                <div class="sidebar-stat">
                    <div class="sidebar-stat-icon"><i class="fas fa-fire"></i></div>
                    <div class="sidebar-stat-info">
                        <div class="sidebar-stat-label">Streak</div>
                        <div class="sidebar-stat-number">12 days</div>
                    </div>
                </div>
            </div>

            <div class="sidebar-footer">
                <a href="../logout.php" class="logout-btn"><i class="fas fa-sign-out-alt"></i> <span>Logout</span></a>
            </div>
        </aside>

        <!-- MAIN CONTENT -->
        <main class="main-content">
            <!-- HEADER -->
            <header class="page-header">
                <div class="header-content">
                    <h1>Welcome back, Alex!</h1>
                    <p>Track your progress and achieve your goals</p>
                </div>
                <div class="header-actions">
                    <a href="create_goal.php" class="btn btn-primary"><i class="fas fa-plus"></i> Create Goal</a>
                </div>
            </header>

            <!-- STATS (dummy) -->
            <div class="stats-grid">
                <div class="stat-card"><div class="stat-content"><div class="stat-icon"><i class="fas fa-bullseye"></i></div><div><div class="stat-number">12</div><div class="stat-label">Total Goals</div></div></div></div>
                <div class="stat-card"><div class="stat-content"><div class="stat-icon"><i class="fas fa-check-circle"></i></div><div><div class="stat-number">8</div><div class="stat-label">Completed</div></div></div></div>
                <div class="stat-card"><div class="stat-content"><div class="stat-icon"><i class="fas fa-spinner"></i></div><div><div class="stat-number">3</div><div class="stat-label">In Progress</div></div></div></div>
                <div class="stat-card"><div class="stat-content"><div class="stat-icon"><i class="fas fa-star"></i></div><div><div class="stat-number">1450</div><div class="stat-label">Total Points</div></div></div></div>
            </div>

            <!-- CONTENT GRID (two columns) -->
            <div class="content-grid">
                <!-- RECENT GOALS CARD -->
                <div class="card">
                    <div class="card-header"><h3><i class="fas fa-bullseye"></i> Recent Goals</h3><a href="goals.php" style="color:#4f46e5;">View All</a></div>
                    <div class="card-body">
                        <div class="goals-list">
                            <!-- dummy goal 1 -->
                            <div class="goal-item">
                                <div class="goal-header"><div><div class="goal-title">Complete Math Assignment</div><div class="goal-date">Due: Jun 25, 2025</div></div><span class="goal-status">in progress</span></div>
                                <div class="progress-bar"><div class="progress-fill" style="width:65%"></div></div><div style="font-size:12px; color:var(--muted); text-align:right;">65% Complete</div>
                            </div>
                            <div class="goal-item">
                                <div class="goal-header"><div><div class="goal-title">Read 5 research papers</div><div class="goal-date">Due: Jun 28, 2025</div></div><span class="goal-status">pending</span></div>
                                <div class="progress-bar"><div class="progress-fill" style="width:20%"></div></div><div style="font-size:12px; color:var(--muted); text-align:right;">20% Complete</div>
                            </div>
                            <div class="goal-item">
                                <div class="goal-header"><div><div class="goal-title">Team project prototype</div><div class="goal-date">Due: Jul 02, 2025</div></div><span class="goal-status">completed</span></div>
                                <div class="progress-bar"><div class="progress-fill" style="width:100%"></div></div><div style="font-size:12px; color:var(--muted); text-align:right;">100% Complete</div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- PROGRESS GRAPH CARD (with Chart.js dummy) -->
                <div class="card">
                    <div class="card-header"><h3><i class="fas fa-chart-line"></i> Weekly Progress</h3></div>
                    <div class="card-body"><canvas id="progressChart" style="height:200px; width:100%;"></canvas></div>
                </div>

                <!-- UPCOMING DEADLINES (spans both columns via grid, but we can adjust) -->
                <div class="card" style="grid-column: span 1;">
                    <div class="card-header"><h3><i class="fas fa-calendar"></i> Upcoming Deadlines</h3></div>
                    <div class="card-body">
                        <div class="goal-item warning"><div class="goal-header"><div><div class="goal-title">Physics Lab Report</div><div class="goal-date">1 day remaining</div></div></div><div class="progress-bar"><div class="progress-fill" style="width:80%"></div></div></div>
                        <div class="goal-item"><div class="goal-header"><div><div class="goal-title">Literature essay</div><div class="goal-date">3 days remaining</div></div></div><div class="progress-bar"><div class="progress-fill" style="width:35%"></div></div></div>
                    </div>
                </div>

                <!-- RECENT ACHIEVEMENTS -->
                <div class="card">
                    <div class="card-header"><h3><i class="fas fa-trophy"></i> Recent Achievements</h3><a href="achievements.php" style="color:#4f46e5;">View All</a></div>
                    <div class="card-body">
                        <div class="achievements-grid">
                            <div class="achievement-item"><div class="achievement-icon" style="background:#f59e0b;"><i class="fas fa-rocket"></i></div><div class="achievement-title">Quick Starter</div><div class="achievement-points">100 pts</div></div>
                            <div class="achievement-item"><div class="achievement-icon" style="background:#10b981;"><i class="fas fa-check-double"></i></div><div class="achievement-title">Goal Crusher</div><div class="achievement-points">250 pts</div></div>
                            <div class="achievement-item"><div class="achievement-icon" style="background:#8b5cf6;"><i class="fas fa-fire"></i></div><div class="achievement-title">7‑day streak</div><div class="achievement-points">70 pts</div></div>
                        </div>
                    </div>
                </div>

                <!-- RECENT NOTIFICATIONS -->
                <div class="card">
                    <div class="card-header"><h3><i class="fas fa-bell"></i> Recent Notifications</h3><a href="notifications.php" style="color:#4f46e5;">View All</a></div>
                    <div class="card-body">
                        <div class="notifications-list">
                            <div class="notification-item unread"><div class="notification-icon"><i class="fas fa-bullseye"></i></div><div class="notification-content"><div class="notification-title">Goal almost due</div><div class="notification-message">Math assignment deadline tomorrow</div><div class="notification-time">Today, 2:30 PM</div></div></div>
                            <div class="notification-item"><div class="notification-icon"><i class="fas fa-trophy"></i></div><div class="notification-content"><div class="notification-title">Achievement unlocked</div><div class="notification-message">You earned 'Quick Starter'</div><div class="notification-time">Yesterday</div></div></div>
                            <div class="notification-item"><div class="notification-icon"><i class="fas fa-info-circle"></i></div><div class="notification-content"><div class="notification-title">New goal available</div><div class="notification-message">Check optional coding challenge</div><div class="notification-time">Jun 20</div></div></div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- QUICK ACTIONS -->
            <div style="margin-top:30px;">
                <h3 style="margin-bottom:20px; color:var(--text); font-size:18px;">Quick Actions</h3>
                <div class="quick-actions">
                    <a href="create_goal.php" class="quick-action"><i class="fas fa-plus-circle"></i> <span>Create Goal</span></a>
                    <a href="goals.php" class="quick-action"><i class="fas fa-list-check"></i> <span>View Goals</span></a>
                    <a href="profile.php" class="quick-action"><i class="fas fa-user-edit"></i> <span>Edit Profile</span></a>
                </div>
            </div>
        </main>
    </div>

    <!-- SIDEBAR SCRIPT -->
    <script>
    const sidebar = document.getElementById('sidebar');
const toggle = document.getElementById('sidebarToggle');
const closeBtn = document.getElementById('sidebarClose');
const overlay = document.getElementById('sidebarOverlay');

if (toggle) {
    toggle.addEventListener('click', () => {
        sidebar.classList.add('active');
        overlay.classList.add('active');
    });
}

if (closeBtn) {
    closeBtn.addEventListener('click', () => {
        sidebar.classList.remove('active');
        overlay.classList.remove('active');
    });
}

if (overlay) {
    overlay.addEventListener('click', () => {
        sidebar.classList.remove('active');
        overlay.classList.remove('active');
    });
}

        document.addEventListener('click', (e) => {
            if (window.innerWidth <= 860 && sidebar.classList.contains('active') && !sidebar.contains(e.target) && !toggle.contains(e.target))
                sidebar.classList.remove('active');
        });
        window.addEventListener('resize', () => {
            if (window.innerWidth > 860) sidebar.classList.remove('active');
        });

        // dummy chart
        const ctx = document.getElementById('progressChart')?.getContext('2d');
        if(ctx) {
            new Chart(ctx, {
                type: 'line',
                data: {
                    labels: ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'],
                    datasets: [{
                        label: 'Progress %',
                        data: [20, 35, 50, 48, 68, 82, 75],
                        borderColor: '#4f46e5',
                        backgroundColor: 'rgba(79,70,229,0.2)',
                        fill: true,
                        tension: 0.4
                    }]
                },
                options: {
                    responsive: true,
                    animation: { duration: 1500 },
                    scales: { y: { beginAtZero: true, max:100 } },
                    plugins: { legend: { display: false } }
                }
            });
        }

        // progress bars animation
        document.querySelectorAll('.progress-fill').forEach(bar => {
            const w = bar.style.width;
            bar.style.width = '0%';
            setTimeout(() => bar.style.width = w, 200);
        });
    </script>
</body>
</html>