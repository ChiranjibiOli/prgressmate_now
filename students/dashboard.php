<?php
session_start();
require_once '../includes/db_connection.php';
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
?>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

<script>
document.addEventListener('DOMContentLoaded', function () {
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
                legend: { display: false },
                tooltip: {
                    backgroundColor: 'rgba(0, 0, 0, 0.8)',
                    cornerRadius: 8,
                    titleFont: { size: 14 },
                    bodyFont: { size: 13 }
                }
            },
            scales: {
                y: {
                    beginAtZero: true,
                    grid: { color: 'rgba(0, 0, 0, 0.05)' },
                    ticks: { padding: 10 }
                },
                x: {
                    grid: { display: false },
                    ticks: { padding: 10 }
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
<>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard - ProgressMate</title>
     <link rel="stylesheet" href="../assets/css/style.css">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

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
            padding: 2.5rem 2rem;
            min-height: 100vh;
            animation: fadeIn 0.8s ease-out;
        }

        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(30px); }
            to { opacity: 1; transform: translateY(0); }
        }

        /* Hero Welcome */
        .welcome-hero {
            text-align: center;
            margin-bottom: 3rem;
            padding: 2rem;
        }

        .welcome-hero h1 {
            font-size: 3.2rem;
            font-weight: 800;
            background: linear-gradient(135deg, var(--primary), var(--primary-dark));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
            margin-bottom: 0.75rem;
        }

        .welcome-hero p {
            font-size: 1.3rem;
            color: var(--secondary);
            max-width: 700px;
            margin: 0 auto;
        }

        /* Stats Grid */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
            gap: 2rem;
            margin-bottom: 3rem;
        }

        .stat-card {
            background: var(--white);
            border-radius: var(--radius-lg);
            padding: 2rem;
            box-shadow: var(--shadow-md);
            transition: var(--transition);
            border: 1px solid var(--gray);
            position: relative;
            overflow: hidden;
        }

        .stat-card:hover {
            transform: translateY(-10px);
            box-shadow: var(--shadow-lg);
        }

        .stat-card::before {
            content: '';
            position: absolute;
            top: 0; left: 0;
            width: 6px;
            height: 100%;
            background: linear-gradient(180deg, var(--primary), var(--primary-dark));
            opacity: 0;
            transition: opacity 0.4s;
        }

        .stat-card:hover::before { opacity: 1; }

        .stat-icon {
            width: 70px;
            height: 70px;
            border-radius: var(--radius-lg);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 2rem;
            color: white;
            margin-bottom: 1rem;
            box-shadow: var(--shadow-md);
        }

        .stat-card.goals .stat-icon { background: linear-gradient(135deg, #6366f1, #8b5cf6); }
        .stat-card.completed .stat-icon { background: linear-gradient(135deg, var(--success), #059669); }
        .stat-card.progress .stat-icon { background: linear-gradient(135deg, var(--warning), #d97706); }
        .stat-card.points .stat-icon { background: linear-gradient(135deg, #f97316, #ea580c); }

        .stat-number {
            font-size: 2.8rem;
            font-weight: 800;
            color: var(--dark);
        }

        .stat-label {
            font-size: 1.1rem;
            color: var(--secondary);
            margin-top: 0.5rem;
        }

        /* Content Grid */
        .content-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(380px, 1fr));
            gap: 2rem;
            margin-bottom: 3rem;
        }

        .card {
            background: var(--white);
            border-radius: var(--radius-lg);
            box-shadow: var(--shadow-md);
            overflow: hidden;
            border: 1px solid var(--gray);
            transition: var(--transition);
        }

        .card:hover {
            box-shadow: var(--shadow-lg);
            transform: translateY(-5px);
        }

        .card-header {
            padding: 1.5rem 2rem;
            background: linear-gradient(135deg, var(--primary-light), transparent);
            border-bottom: 1px solid var(--gray);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .card-header h3 {
            font-size: 1.4rem;
            font-weight: 700;
            color: var(--dark);
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }

        .card-header a {
            font-size: 0.95rem;
            color: var(--primary);
            font-weight: 600;
        }

        .card-body {
            padding: 2rem;
        }

        /* Progress Chart Card */
        .chart-container {
            position: relative;
            height: 300px;
            margin: 1rem 0;
        }

        /* Goal Item */
        .goal-item {
            padding: 1.25rem;
            border-radius: var(--radius-md);
            background: var(--light);
            margin-bottom: 1rem;
            transition: var(--transition);
        }

        .goal-item:hover {
            background: var(--primary-light);
            transform: translateX(8px);
        }

        .goal-title {
            font-weight: 600;
            color: var(--dark);
            margin-bottom: 0.5rem;
        }

        .goal-meta {
            font-size: 0.9rem;
            color: var(--secondary);
            margin-bottom: 1rem;
        }

        .progress-bar {
            height: 10px;
            background: var(--gray);
            border-radius: 5px;
            overflow: hidden;
            margin: 0.75rem 0;
        }

        .progress-fill {
            height: 100%;
            background: linear-gradient(90deg, var(--primary), #7c3aed);
            border-radius: 5px;
            width: 0;
            transition: width 1.5s ease-out;
        }

        /* Achievement Grid */
        .achievements-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(120px, 1fr));
            gap: 1rem;
        }

        .achievement-item {
            text-align: center;
            padding: 1.25rem;
            background: var(--light);
            border-radius: var(--radius-lg);
            transition: var(--transition);
        }

        .achievement-item:hover {
            transform: translateY(-8px);
            box-shadow: var(--shadow-md);
        }

        .achievement-icon {
            width: 60px;
            height: 60px;
            border-radius: 50%;
            background: var(--primary);
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.6rem;
            margin: 0 auto 1rem;
            box-shadow: var(--shadow-md);
        }

        /* Notifications */
        .notification-item {
            display: flex;
            gap: 1rem;
            padding: 1rem;
            border-radius: var(--radius-md);
            background: var(--light);
            margin-bottom: 0.75rem;
            transition: var(--transition);
        }

        .notification-item.unread {
            background: var(--primary-light);
            border-left: 4px solid var(--primary);
        }

        .notification-item:hover {
            transform: translateX(6px);
        }

        .notification-icon {
            width: 45px;
            height: 45px;
            border-radius: var(--radius-md);
            background: var(--primary-light);
            color: var(--primary);
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
        }

        /* Empty States */
        .empty-state {
            text-align: center;
            padding: 3rem 1rem;
            color: var(--secondary);
        }

        .empty-state i {
            font-size: 4rem;
            margin-bottom: 1rem;
            opacity: 0.4;
        }

        /* Quick Actions */
        .quick-actions {
            display: flex;
            gap: 1.5rem;
            justify-content: center;
            flex-wrap: wrap;
        }

        .quick-action {
            background: var(--white);
            padding: 1.5rem 2rem;
            border-radius: var(--radius-lg);
            box-shadow: var(--shadow-md);
            text-align: center;
            min-width: 200px;
            transition: var(--transition);
            color: var(--dark);
            text-decoration: none;
        }

        .quick-action:hover {
            transform: translateY(-8px);
            box-shadow: var(--shadow-lg);
        }

        .quick-action i {
            font-size: 2rem;
            color: var(--primary);
            margin-bottom: 0.75rem;
            display: block;
        }

        /* Responsive */
        @media (max-width: 992px) {
            .main-content { margin-left: 0; padding-top: 100px; }
            .content-grid { grid-template-columns: 1fr; }
        }

        @media (max-width: 768px) {
            .welcome-hero h1 { font-size: 2.5rem; }
            .stats-grid { grid-template-columns: repeat(2, 1fr); }
        }

        @media (max-width: 480px) {
            .stats-grid { grid-template-columns: 1fr; }

        }
        /* MAIN CONTENT - CLEANER & MORE BALANCED */
        .main-content {
            flex: 1;
            margin-left: 280px;
            padding: 2rem 1.5rem;
            min-height: 100vh;
        }

        /* Hero Welcome - Subtler */
        .welcome-hero {
            text-align: center;
            margin-bottom: 2.5rem;
        }

        .welcome-hero h1 {
            font-size: 2.8rem;
            font-weight: 800;
            color: var(--dark);
            margin-bottom: 0.5rem;
        }

        .welcome-hero p {
            font-size: 1.2rem;
            color: var(--secondary);
            max-width: 600px;
            margin: 0 auto;
        }

        /* Stats Grid - Cleaner cards */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            gap: 1.5rem;
            margin-bottom: 3rem;
        }

        .stat-card {
            background: var(--white);
            border-radius: var(--radius-lg);
            padding: 1.75rem;
            box-shadow: var(--shadow-sm);
            transition: var(--transition);
            border: 1px solid var(--gray);
        }

        .stat-card:hover {
            box-shadow: var(--shadow-md);
            transform: translateY(-6px);
        }

        .stat-icon {
            width: 60px;
            height: 60px;
            border-radius: var(--radius-lg);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.8rem;
            color: white;
            margin-bottom: 1rem;
        }

        .stat-number {
            font-size: 2.4rem;
            font-weight: 800;
            color: var(--dark);
        }

        .stat-label {
            font-size: 1rem;
            color: var(--secondary);
        }

        /* Content Grid - More breathing room */
        .content-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(350px, 1fr));
            gap: 2rem;
        }

        .card {
            background: var(--white);
            border-radius: var(--radius-lg);
            box-shadow: var(--shadow-sm);
            overflow: hidden;
            border: 1px solid var(--gray);
            transition: var(--transition);
        }

        .card:hover {
            box-shadow: var(--shadow-md);
        }

        .card-header {
            padding: 1.25rem 1.75rem;
            background: var(--gray-light);
            border-bottom: 1px solid var(--gray);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .card-header h3 {
            font-size: 1.3rem;
            font-weight: 600;
            color: var(--dark);
            display: flex;
            align-items: center;
            gap: 0.6rem;
        }

        .card-body {
            padding: 1.5rem;
        }

        /* Chart */
        .chart-container {
            height: 280px;
            padding: 1rem 0;
        }

        /* Goal Items - Cleaner */
        .goal-item {
            padding: 1rem;
            background: var(--gray-light);
            border-radius: var(--radius-md);
            margin-bottom: 1rem;
        }

        .goal-title {
            font-weight: 600;
            font-size: 1.05rem;
            margin-bottom: 0.4rem;
        }

        .goal-meta {
            font-size: 0.9rem;
            color: var(--secondary);
            margin-bottom: 0.8rem;
        }

        .progress-bar {
            height: 8px;
            background: var(--gray);
            border-radius: 4px;
            overflow: hidden;
        }

        .progress-fill {
            height: 100%;
            background: linear-gradient(90deg, var(--primary), var(--primary-dark));
            width: 0;
            transition: width 1.8s ease;
        }

        /* Achievements - Smaller icons */
        .achievement-icon {
            width: 50px;
            height: 50px;
            border-radius: 50%;
            font-size: 1.4rem;
            margin: 0 auto 0.75rem;
        }

        /* Quick Actions - At bottom */
        .quick-actions {
            display: flex;
            gap: 1.5rem;
            justify-content: center;
            margin-top: 3rem;
            flex-wrap: wrap;
        }

        .quick-action {
            background: var(--white);
            padding: 1.5rem 2.5rem;
            border-radius: var(--radius-lg);
            box-shadow: var(--shadow-sm);
            text-align: center;
            min-width: 180px;
            transition: var(--transition);
        }

        .quick-action:hover {
            box-shadow: var(--shadow-md);
            transform: translateY(-5px);
        }

        .quick-action i {
            font-size: 1.8rem;
            color: var(--primary);
            margin-bottom: 0.75rem;
        }

        /* Responsive adjustments */
        @media (max-width: 992px) {
            .main-content { margin-left: 0; padding-top: 90px; }
            .content-grid { grid-template-columns: 1fr; }
        }
        .main-content {
            flex: 1;
            margin-left: 280px;
            padding: 2rem 1.5rem;
            min-height: 100vh;
        }

        .welcome-hero {
            text-align: center;
            margin-bottom: 2.5rem;
        }

        .welcome-hero h1 {
            font-size: 2.6rem;
            font-weight: 800;
            color: var(--dark);
            margin-bottom: 0.5rem;
        }

        .welcome-hero p {
            font-size: 1.15rem;
            color: var(--secondary);
            max-width: 600px;
            margin: 0 auto;
        }

        /* Stats Grid - Perfectly sized cards */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2.5rem;
        }

        .stat-card {
            background: var(--white);
            border-radius: var(--radius-lg);
            padding: 1.6rem;
            box-shadow: var(--shadow-sm);
            border: 1px solid var(--gray);
            transition: var(--transition);
            display: flex;
            align-items: center;
            gap: 1.2rem;
        }

        .stat-card:hover {
            box-shadow: var(--shadow-md);
            transform: translateY(-4px);
        }

        .stat-icon {
            width: 56px;
            height: 56px;
            border-radius: var(--radius-lg);
            flex-shrink: 0;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.7rem;
            color: white;
        }

        .stat-info { flex: 1; }
        .stat-number { font-size: 2.2rem; font-weight: 800; line-height: 1; }
        .stat-label { font-size: 0.95rem; color: var(--secondary); margin-top: 0.3rem; }

        /* Content Grid - Tight, content-fitting cards */
        .content-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(360px, 1fr));
            gap: 1.75rem;
            margin-bottom: 2rem;
        }

        .card {
            background: var(--white);
            border-radius: var(--radius-lg);
            box-shadow: var(--shadow-sm);
            border: 1px solid var(--gray);
            overflow: hidden;
            display: flex;
            flex-direction: column;
        }

        .card-header {
            padding: 1.1rem 1.5rem;
            background: var(--gray-light);
            border-bottom: 1px solid var(--gray);
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-shrink: 0;
        }

        .card-header h3 {
            font-size: 1.25rem;
            font-weight: 600;
            color: var(--dark);
            display: flex;
            align-items: center;
            gap: 0.6rem;
        }

        .card-header a {
            font-size: 0.9rem;
            color: var(--primary);
            font-weight: 600;
        }

        .card-body {
            padding: 1.4rem 1.5rem;
            flex: 1;
            display: flex;
            flex-direction: column;
        }

        /* Chart - Exact fit */
        .chart-container {
            height: 260px;
            margin-top: 0.5rem;
        }

        /* Goal Items - Compact & snug */
        .goal-item {
            padding: 1rem;
            background: var(--gray-light);
            border-radius: var(--radius-md);
            margin-bottom: 1rem;
        }

        .goal-title {
            font-weight: 600;
            font-size: 1.05rem;
            margin-bottom: 0.4rem;
            line-height: 1.3;
        }

        .goal-meta {
            font-size: 0.88rem;
            color: var(--secondary);
            margin-bottom: 0.8rem;
        }

        .progress-bar {
            height: 8px;
            background: var(--gray);
            border-radius: 4px;
            overflow: hidden;
            margin: 0.6rem 0;
        }

        .progress-fill {
            height: 100%;
            background: linear-gradient(90deg, var(--primary), var(--primary-dark));
            width: 0;
            transition: width 1.6s ease-out;
        }

        /* Achievements - Tight grid */
        .achievements-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(110px, 1fr));
            gap: 0.9rem;
        }

        .achievement-item {
            text-align: center;
            padding: 1rem;
            background: var(--light);
            border-radius: var(--radius-lg);
        }

        .achievement-icon {
            width: 48px;
            height: 48px;
            border-radius: 50%;
            background: var(--primary);
            color: white;
            font-size: 1.3rem;
            margin: 0 auto 0.7rem;
        }

        .achievement-title {
            font-size: 0.88rem;
            font-weight: 600;
            line-height: 1.3;
        }

        .achievement-points {
            font-size: 0.85rem;
            color: var(--warning);
            font-weight: 700;
        }

        /* Notifications - Compact */
        .notification-item {
            display: flex;
            gap: 0.9rem;
            padding: 0.9rem;
            background: var(--light);
            border-radius: var(--radius-md);
            margin-bottom: 0.7rem;
        }

        .notification-item.unread {
            background: var(--primary-light);
            border-left: 4px solid var(--primary);
        }

        .notification-icon {
            width: 40px;
            height: 40px;
            border-radius: var(--radius-md);
            background: var(--primary-light);
            color: var(--primary);
            flex-shrink: 0;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.1rem;
        }

        .notification-content {
            flex: 1;
            min-width: 0;
        }

        .notification-title {
            font-weight: 600;
            font-size: 0.95rem;
            margin-bottom: 0.2rem;
        }

        .notification-message {
            font-size: 0.85rem;
            color: var(--secondary);
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .notification-time {
            font-size: 0.8rem;
            color: var(--gray-dark);
            margin-top: 0.3rem;
        }

        /* Empty States - Compact */
        .empty-state {
            text-align: center;
            padding: 2rem 1rem;
            color: var(--secondary);
        }

        .empty-state i {
            font-size: 3rem;
            margin-bottom: 0.8rem;
            opacity: 0.4;
        }

        /* Quick Actions - Perfectly sized */
        .quick-actions {
            display: flex;
            gap: 1.2rem;
            justify-content: center;
            flex-wrap: wrap;
            margin-top: 2.5rem;
        }

        .quick-action {
            background: var(--white);
            padding: 1.3rem 2rem;
            border-radius: var(--radius-lg);
            box-shadow: var(--shadow-sm);
            text-align: center;
            min-width: 170px;
            transition: var(--transition);
            text-decoration: none;
            color: var(--dark);
        }

        .quick-action:hover {
            box-shadow: var(--shadow-md);
            transform: translateY(-4px);
        }

        .quick-action i {
            font-size: 1.7rem;
            color: var(--primary);
            margin-bottom: 0.6rem;
        }

        .quick-action span {
            font-weight: 600;
            font-size: 0.95rem;
        }

        /* Responsive */
        @media (max-width: 992px) {
            .main-content { margin-left: 0; padding-top: 90px; }
            .content-grid { grid-template-columns: 1fr; }
        }

        @media (max-width: 768px) {
            .stats-grid { grid-template-columns: repeat(2, 1fr); }
            .welcome-hero h1 { font-size: 2.3rem; }
        }

        @media (max-width: 480px) {
            .stats-grid { grid-template-columns: 1fr; }
        }
    </style>

   
</head>
<body>
    <!-- ===== MOBILE MENU TOGGLE ===== -->
    <button class="mobile-toggle" id="sidebarToggle">
        <i class="fas fa-bars"></i>
    </button>
    
    <!-- ===== DASHBOARD WRAPPER ===== -->
    <div class="dashboard-wrapper">
        
        <!-- ===== SIDEBAR ===== -->
        <aside class="sidebar" id="sidebar">
            <!-- Sidebar Header -->
            <div class="sidebar-header">
                <div class="logo">
                    <i class="fas fa-star"></i>
                    <span>ProgressMate</span>
                </div>
                <button class="sidebar-close" id="sidebarClose">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <!-- User Profile -->
            <div class="user-profile">
                <?php if (!empty($_SESSION['profile_picture'])): ?>
                    <img src="<?php echo '../' . htmlspecialchars($_SESSION['profile_picture']); ?>" alt="Profile" class="profile-pic">
                <?php else: ?>
                    <div class="profile-pic default">
                        <?php echo strtoupper(substr($_SESSION['name'], 0, 1)); ?>
                    </div>
                <?php endif; ?>
                <div class="user-info">
                    <h4><?php echo htmlspecialchars($_SESSION['name']); ?></h4>
                    <p><?php echo htmlspecialchars($_SESSION['email']); ?></p>
                    <span style="font-size: 11px; background: #e0e7ff; color: #4f46e5; padding: 2px 8px; border-radius: 12px;">
                        STUDENT
                    </span>
                </div>
            </div>
            <!-- Navigation Menu -->
            <nav class="nav-menu">
                <a href="dashboard.php" class="nav-link active">
                    <i class="fas fa-tachometer-alt"></i>
                    <span>Dashboard</span>
                </a>
                <a href="goals.php" class="nav-link">
                    <i class="fas fa-bullseye"></i>
                    <span>My Goals</span>
                    <?php if ($total_goals > 0): ?>
                        <span class="badge"><?php echo $total_goals; ?></span>
                    <?php endif; ?>
                </a>
                <a href="create_goal.php" class="nav-link">
                    <i class="fas fa-plus-circle"></i>
                    <span>Create Goal</span>
                </a>
                <a href="achievements.php" class="nav-link">
                    <i class="fas fa-trophy"></i>
                    <span>Achievements</span>
                    <?php if ($total_points > 0): ?>
                        <span class="badge"><?php echo $total_points; ?> pts</span>
                    <?php endif; ?>
                </a>
                <a href="notifications.php" class="nav-link">
                    <i class="fas fa-inbox"></i>
                    <span>Notifications</span>
                    <?php if ($unread > 0): ?>
                        <span class="badge"><?php echo $unread; ?></span>
                    <?php endif; ?>
                </a>
                <a href="profile.php" class="nav-link">
                    <i class="fas fa-user"></i>
                    <span>Profile</span>
                </a>
            </nav>
            <!-- Quick Stats -->
            <div class="sidebar-quick-stats">
                <div class="sidebar-stat">
                    <div class="sidebar-stat-icon">
                        <i class="fas fa-bullseye"></i>
                    </div>
                    <div class="sidebar-stat-info">
                        <div class="sidebar-stat-label">Goals Score</div>
                        <div class="sidebar-stat-number"><?php echo $completed_goals; ?>/<?php echo $total_goals; ?></div>
                    </div>
                </div>
                <div class="sidebar-stat">
                    <div class="sidebar-stat-icon">
                        <i class="fas fa-star"></i>
                    </div>
                    <div class="sidebar-stat-info">
                        <div class="sidebar-stat-label">Points</div>
                        <div class="sidebar-stat-number"><?php echo $total_points; ?></div>
                    </div>
                </div>
                <div class="sidebar-stat">
                    <div class="sidebar-stat-icon">
                        <i class="fas fa-fire"></i>
                    </div>
                    <div class="sidebar-stat-info">
                        <div class="sidebar-stat-label">Streak</div>
                        <div class="sidebar-stat-number"><?php echo $streak; ?> days</div>
                    </div>
                </div>
            </div>
            <!-- Logout -->
            <div class="sidebar-footer">
                <a href="../logout.php" class="logout-btn">
                    <i class="fas fa-sign-out-alt"></i>
                    <span>Logout</span>
                </a>
            </div>
        </aside>
        
        <!-- ===== MAIN CONTENT ===== -->
        <main class="main-content">
            <!-- Page Header -->
            <header class="page-header">
                <div class="header-content">
                    <h1>Welcome back, <?php echo htmlspecialchars(explode(' ', $_SESSION['name'])[0]); ?>!</h1>
                    <p>Track your progress and achieve your goals</p>
                </div>
                <div class="header-actions">
                    <a href="create_goal.php" class="btn btn-primary">
                        <i class="fas fa-plus"></i> Create Goal
                    </a>
                </div>
            </header>
            
            <!-- Stats Overview -->
            <div class="stats-grid">
                <div class="stat-card goals">
                    <div class="stat-content">
                        <div class="stat-icon">
                            <i class="fas fa-bullseye"></i>
                        </div>
                        <div>
                            <div class="stat-number"><?php echo $total_goals; ?></div>
                            <div class="stat-label">Total Goals</div>
                        </div>
                    </div>
                </div>
                
                <div class="stat-card completed">
                    <div class="stat-content">
                        <div class="stat-icon">
                            <i class="fas fa-check-circle"></i>
                        </div>
                        <div>
                            <div class="stat-number"><?php echo $completed_goals; ?></div>
                            <div class="stat-label">Completed</div>
                        </div>
                    </div>
                </div>
                
                <div class="stat-card progress">
                    <div class="stat-content">
                        <div class="stat-icon">
                            <i class="fas fa-spinner"></i>
                        </div>
                        <div>
                            <div class="stat-number"><?php echo $in_progress_goals; ?></div>
                            <div class="stat-label">In Progress</div>
                        </div>
                    </div>
                </div>
                
                <div class="stat-card points">
                    <div class="stat-content">
                        <div class="stat-icon">
                            <i class="fas fa-star"></i>
                        </div>
                        <div>
                            <div class="stat-number"><?php echo $total_points; ?></div>
                            <div class="stat-label">Total Points</div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Content Grid -->
            <div class="content-grid">
                <!-- Recent Goals -->
                <div class="card">
                    <div class="card-header">
                        <h3><i class="fas fa-bullseye"></i> Recent Goals</h3>
                        <a href="goals.php" style="font-size: 14px; color: #4f46e5; text-decoration: none;">View All</a>
                    </div>
                    <div class="card-body">
                        <div class="goals-list">
                            <?php foreach ($recent_goals as $goal): ?>
                                <div class="goal-item">
                                    <div class="goal-header">
                                        <div>
                                            <div class="goal-title"><?php echo htmlspecialchars($goal['title']); ?></div>
                                            <div class="goal-date">
                                                Due: <?php echo $goal['due_date'] ? date('M d, Y', strtotime($goal['due_date'])) : 'No deadline'; ?>
                                            </div>
                                        </div>
                                        <span class="goal-status status-<?php echo $goal['status']; ?>">
                                            <?php echo ucfirst(str_replace('_', ' ', $goal['status'])); ?>
                                        </span>
                                    </div>
                                    <div class="progress-bar">
                                        <div class="progress-fill" style="width: <?php echo $goal['progress_percentage']; ?>%"></div>
                                    </div>
                                    <div style="font-size: 12px; color: #6b7280; text-align: right;">
                                        <?php echo $goal['progress_percentage']; ?>% Complete
                                    </div>
                                </div>
                            <?php endforeach; ?>
                            
                            <?php if (empty($recent_goals)): ?>
                                <div class="empty-state">
                                    <i class="fas fa-bullseye"></i>
                                    <p>No goals yet</p>
                                    <a href="create_goal.php" class="btn btn-outline">Create First Goal</a>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- Progress Graph -->
                <div class="card">
                    <div class="card-header">
                        <h3><i class="fas fa-chart-line"></i> Weekly Progress</h3>
                    </div>
                    <div class="card-body">
                        <canvas id="progressChart" style="height: 200px;"></canvas>
                    </div>
                </div>

                <!-- Upcoming Deadlines / Reminders -->
                <div class="card reminder-box">
                    <div class="card-header">
                        <h3><i class="fas fa-calendar"></i> Upcoming Deadlines</h3>
                    </div>
                    <div class="card-body">
                        <div class="goals-list">
                            <?php foreach ($upcoming_deadlines as $goal): ?>
                                <?php 
                                $days_left = date_diff(new DateTime(), new DateTime($goal['due_date']))->days;
                                $warning_class = ($days_left <= 1) ? 'warning' : '';
                                ?>
                                <div class="goal-item <?php echo $warning_class; ?>">
                                    <div class="goal-header">
                                        <div>
                                            <div class="goal-title"><?php echo htmlspecialchars($goal['title']); ?></div>
                                            <div class="goal-date">
                                                <?php echo $days_left . ' day' . ($days_left != 1 ? 's' : '') . ' remaining'; ?>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="progress-bar">
                                        <div class="progress-fill" style="width: <?php echo $goal['progress_percentage']; ?>%"></div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                            
                            <?php if (empty($upcoming_deadlines)): ?>
                                <div class="empty-state">
                                    <i class="fas fa-calendar-check"></i>
                                    <p>No upcoming deadlines</p>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- Recent Achievements -->
                <div class="card">
                    <div class="card-header">
                        <h3><i class="fas fa-trophy"></i> Recent Achievements</h3>
                        <a href="achievements.php" style="font-size: 14px; color: #4f46e5; text-decoration: none;">View All</a>
                    </div>
                    <div class="card-body">
                        <div class="achievements-grid">
                            <?php foreach ($recent_achievements as $achievement): ?>
                                <div class="achievement-item">
                                    <div class="achievement-icon" style="background: <?php echo $achievement['color']; ?>;">
                                        <i class="fas fa-<?php echo $achievement['icon']; ?>"></i>
                                    </div>
                                    <div class="achievement-title"><?php echo htmlspecialchars($achievement['title']); ?></div>
                                    <div class="achievement-points"><?php echo $achievement['points']; ?> pts</div>
                                </div>
                            <?php endforeach; ?>
                            
                            <?php if (empty($recent_achievements)): ?>
                                <div class="empty-state">
                                    <i class="fas fa-trophy"></i>
                                    <p>No achievements yet</p>
                                    <p style="font-size: 13px;">Complete goals to earn achievements!</p>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- Recent Notifications -->
                <div class="card">
                    <div class="card-header">
                        <h3><i class="fas fa-bell"></i> Recent Notifications</h3>
                        <a href="notifications.php" style="font-size: 14px; color: #4f46e5; text-decoration: none;">View All</a>
                    </div>
                    <div class="card-body">
                        <div class="notifications-list">
                            <?php foreach ($notifications as $notification): ?>
                                <div class="notification-item <?php echo $notification['is_read'] ? '' : 'unread'; ?>">
                                    <div class="notification-icon">
                                        <i class="fas fa-<?php echo $notification['type'] == 'goal' ? 'bullseye' : ($notification['type'] == 'achievement' ? 'trophy' : 'info-circle'); ?>"></i>
                                    </div>
                                    <div class="notification-content">
                                        <div class="notification-title"><?php echo htmlspecialchars($notification['title']); ?></div>
                                        <div class="notification-message" style="font-size: 13px; color: #6b7280; margin-bottom: 2px;">
                                            <?php echo htmlspecialchars(substr($notification['message'], 0, 60)); ?>
                                            <?php echo strlen($notification['message']) > 60 ? '...' : ''; ?>
                                        </div>
                                        <div class="notification-time">
                                            <?php echo date('M d, h:i A', strtotime($notification['created_at'])); ?>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                            
                            <?php if (empty($notifications)): ?>
                                <div class="empty-state">
                                    <i class="fas fa-bell"></i>
                                    <p>No notifications</p>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Quick Actions -->
            <div style="margin-top: 30px;">
                <h3 style="margin-bottom: 20px; color: #111827; font-size: 18px;">Quick Actions</h3>
                <div class="quick-actions">
                    <a href="create_goal.php" class="quick-action">
                        <i class="fas fa-plus-circle"></i>
                        <span>Create Goal</span>
                    </a>
                    <a href="goals.php" class="quick-action">
                        <i class="fas fa-list-check"></i>
                        <span>View Goals</span>
                    </a>
                    <a href="profile.php" class="quick-action">
                        <i class="fas fa-user-edit"></i>
                        <span>Edit Profile</span>
                    </a>
                </div>
            </div>
        </main>
    </div>
    
    <!-- ===== JAVASCRIPT ===== -->
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
        
        // Auto-refresh notifications every 30 seconds
        setInterval(() => {
            fetch('check_notifications.php')
                .then(response => response.json())
                .then(data => {
                    if (data.unread > 0) {
                        const badge = document.querySelector('.nav-link[href*="notifications"] .badge');
                        if (badge) {
                            badge.textContent = data.unread;
                        }
                    }
                });
        }, 30000);
        
        // Update progress bars animation
        document.querySelectorAll('.progress-fill').forEach(fill => {
            const currentWidth = fill.style.width;
            fill.style.width = '0';
            setTimeout(() => {
                fill.style.width = currentWidth;
            }, 100);
        });

        // Progress Chart
        const ctx = document.getElementById('progressChart').getContext('2d');
        new Chart(ctx, {
            type: 'line',
            data: {
                labels: <?php echo $chart_labels_json; ?>,
                datasets: [{
                    label: 'Weekly Progress',
                    data: <?php echo $chart_data_json; ?>,
                    borderColor: '#4f46e5',
                    backgroundColor: 'rgba(79, 70, 229, 0.2)',
                    fill: true,
                    tension: 0.4
                }]
            },
            options: {
                responsive: true,
                animation: {
                    duration: 2000,
                    easing: 'easeOutQuart'
                },
                scales: {
                    y: {
                        beginAtZero: true
                    }
                },
                plugins: {
                    legend: {
                        display: true
                    }
                }
            }
        });
    </script>
</body>
</html>