<?php
session_start();
require_once '../includes/db_connection.php';
require_once '../includes/functions.php';
require_once '../tcpdf/tcpdf.php';
checkAuth('admin');

$success = $_SESSION['success'] ?? '';
$error = $_SESSION['error'] ?? '';
unset($_SESSION['success'], $_SESSION['error']);

// Filter parameters
$search = trim($_GET['search'] ?? '');
$department = trim($_GET['department'] ?? '');
$status = $_GET['status'] ?? 'all';
$student_id = (int)($_GET['student_id'] ?? 0);

// Unique departments
try {
    $dept_stmt = $pdo->prepare("
        SELECT DISTINCT department 
        FROM users 
        WHERE role = 'student' AND department IS NOT NULL AND department != ''
        ORDER BY department
    ");
    $dept_stmt->execute();
    $departments = $dept_stmt->fetchAll(PDO::FETCH_COLUMN);
} catch (Exception $e) {
    $departments = [];
}

// Overall stats
$total_students = getStat($pdo, "SELECT COUNT(*) FROM users WHERE role = 'student'");
$active_students = getStat($pdo, "SELECT COUNT(*) FROM users WHERE role = 'student' AND status = 'active'");
$total_goals = getStat($pdo, "SELECT COUNT(*) FROM admin_goals WHERE status = 'active'");
$assigned_goals = getStat($pdo, "SELECT COUNT(*) FROM student_goals");
$completed_goals = getStat($pdo, "SELECT COUNT(*) FROM student_goals WHERE status = 'completed'");
$total_points = getStat($pdo, "
    SELECT COALESCE(SUM(a.points), 0)
    FROM user_achievements ua
    JOIN achievements a ON ua.achievement_id = a.id
    WHERE ua.deleted_at IS NULL
");

// Department stats
$dept_query = "
    SELECT 
        u.department,
        COUNT(DISTINCT u.id) as total_students,
        COUNT(sg.id) as total_assigned,
        SUM(CASE WHEN sg.status = 'completed' THEN 1 ELSE 0 END) as completed_goals,
        COALESCE(AVG(sg.progress_percentage), 0) as avg_progress,
        COALESCE(SUM(a.points), 0) as total_points
    FROM users u
    LEFT JOIN student_goals sg ON u.id = sg.student_id
    LEFT JOIN user_achievements ua ON u.id = ua.user_id
    LEFT JOIN achievements a ON ua.achievement_id = a.id
    WHERE u.role = 'student'
";
$dept_params = [];
if ($department) {
    $dept_query .= " AND u.department = ?";
    $dept_params[] = $department;
}
if ($status !== 'all') {
    $dept_query .= " AND u.status = ?";
    $dept_params[] = $status;
}
$dept_query .= " GROUP BY u.department ORDER BY avg_progress DESC";

$dept_stmt = $pdo->prepare($dept_query);
$dept_stmt->execute($dept_params);
$department_stats = $dept_stmt->fetchAll(PDO::FETCH_ASSOC);

// Top students
$top_query = "
    SELECT 
        u.id,
        u.name,
        u.department,
        COUNT(sg.id) as total_goals,
        SUM(CASE WHEN sg.status = 'completed' THEN 1 ELSE 0 END) as completed_goals,
        COALESCE(AVG(sg.progress_percentage), 0) as avg_progress,
        COALESCE(SUM(a.points), 0) as total_points
    FROM users u
    LEFT JOIN student_goals sg ON u.id = sg.student_id
    LEFT JOIN user_achievements ua ON u.id = ua.user_id
    LEFT JOIN achievements a ON ua.achievement_id = a.id
    WHERE u.role = 'student'
";
$top_params = [];
if ($search) {
    $top_query .= " AND (u.name LIKE ? OR u.email LIKE ?)";
    $top_params[] = "%$search%";
    $top_params[] = "%$search%";
}
if ($department) {
    $top_query .= " AND u.department = ?";
    $top_params[] = $department;
}
if ($status !== 'all') {
    $top_query .= " AND u.status = ?";
    $top_params[] = $status;
}
$top_query .= " GROUP BY u.id ORDER BY completed_goals DESC, total_points DESC LIMIT 10";

$top_stmt = $pdo->prepare($top_query);
$top_stmt->execute($top_params);
$top_students = $top_stmt->fetchAll(PDO::FETCH_ASSOC);

// Individual student report
$student_report = null;
if ($student_id > 0) {
    $student_stmt = $pdo->prepare("
        SELECT 
            u.*,
            COUNT(sg.id) as total_goals,
            SUM(CASE WHEN sg.status = 'completed' THEN 1 ELSE 0 END) as completed_goals,
            COALESCE(AVG(sg.progress_percentage), 0) as avg_progress,
            COALESCE(SUM(a.points), 0) as total_points
        FROM users u
        LEFT JOIN student_goals sg ON u.id = sg.student_id
        LEFT JOIN user_achievements ua ON u.id = ua.user_id
        LEFT JOIN achievements a ON ua.achievement_id = a.id
        WHERE u.id = ? AND u.role = 'student'
        GROUP BY u.id
    ");
    $student_stmt->execute([$student_id]);
    $student_report = $student_stmt->fetch(PDO::FETCH_ASSOC);

    if ($student_report) {
        // Goals
        $goals_stmt = $pdo->prepare("
            SELECT sg.*, ag.title as goal_title, ag.unit
            FROM student_goals sg
            JOIN admin_goals ag ON sg.goal_id = ag.id
            WHERE sg.student_id = ?
            ORDER BY sg.due_date ASC
        ");
        $goals_stmt->execute([$student_id]);
        $student_report['goals'] = $goals_stmt->fetchAll(PDO::FETCH_ASSOC);

        // Achievements
        $ach_stmt = $pdo->prepare("
            SELECT a.title, a.description, a.points, a.icon, a.color, ua.earned_at
            FROM user_achievements ua
            JOIN achievements a ON ua.achievement_id = a.id
            WHERE ua.user_id = ? AND ua.deleted_at IS NULL
            ORDER BY ua.earned_at DESC
        ");
        $ach_stmt->execute([$student_id]);
        $student_report['achievements'] = $ach_stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}

// Sidebar stats
$sidebar_stats = [
    'students' => $total_students,
    'goals' => $total_goals,
    'assigned' => $assigned_goals,
    'points' => $total_points
];

// Overall PDF Export
if (isset($_GET['export']) && $_GET['export'] === 'pdf') {
    $pdf = new TCPDF(PDF_PAGE_ORIENTATION, PDF_UNIT, PDF_PAGE_FORMAT, true, 'UTF-8', false);

    $pdf->SetCreator('ProgressMate');
    $pdf->SetAuthor('Admin');
    $pdf->SetTitle('ProgressMate System Report');
    $pdf->setPrintHeader(false);
    $pdf->setPrintFooter(false);
    $pdf->SetMargins(15, 20, 15);
    $pdf->SetAutoPageBreak(true, 20);

    $pdf->AddPage();

    $pdf->SetFont('helvetica', 'B', 20);
    $pdf->Cell(0, 15, 'ProgressMate System Report', 0, 1, 'C');
    $pdf->Ln(10);

    $pdf->SetFont('helvetica', 'B', 14);
    $pdf->Cell(0, 10, 'Overall Statistics', 0, 1);
    $pdf->SetFont('helvetica', '', 12);
    $pdf->Cell(0, 8, "Total Students: $total_students", 0, 1);
    $pdf->Cell(0, 8, "Active Students: $active_students", 0, 1);
    $pdf->Cell(0, 8, "System Goals: $total_goals", 0, 1);
    $pdf->Cell(0, 8, "Assigned Goals: $assigned_goals", 0, 1);
    $pdf->Cell(0, 8, "Completed Goals: $completed_goals", 0, 1);
    $pdf->Cell(0, 8, "Total Points Awarded: $total_points", 0, 1);
    $pdf->Ln(10);

    $pdf->SetFont('helvetica', 'B', 14);
    $pdf->Cell(0, 10, 'Department Statistics', 0, 1);

    $pdf->SetFont('helvetica', 'B', 11);
    $pdf->SetFillColor(240, 240, 240);
    $pdf->Cell(50, 10, 'Department', 1, 0, 'C', true);
    $pdf->Cell(25, 10, 'Students', 1, 0, 'C', true);
    $pdf->Cell(30, 10, 'Assigned', 1, 0, 'C', true);
    $pdf->Cell(30, 10, 'Completed', 1, 0, 'C', true);
    $pdf->Cell(30, 10, 'Avg Progress', 1, 0, 'C', true);
    $pdf->Cell(25, 10, 'Points', 1, 1, 'C', true);

    $pdf->SetFont('helvetica', '', 11);
    foreach ($department_stats as $dept) {
        $pdf->Cell(50, 10, $dept['department'] ?? 'Unassigned', 1);
        $pdf->Cell(25, 10, $dept['total_students'], 1, 0, 'C');
        $pdf->Cell(30, 10, $dept['total_assigned'], 1, 0, 'C');
        $pdf->Cell(30, 10, $dept['completed_goals'], 1, 0, 'C');
        $pdf->Cell(30, 10, round($dept['avg_progress'], 1) . '%', 1, 0, 'C');
        $pdf->Cell(25, 10, $dept['total_points'] ?? 0, 1, 1, 'C');
    }
    $pdf->Ln(10);

    $pdf->SetFont('helvetica', 'B', 14);
    $pdf->Cell(0, 10, 'Top 10 Students', 0, 1);

    $pdf->SetFont('helvetica', 'B', 11);
    $pdf->SetFillColor(240, 240, 240);
    $pdf->Cell(60, 10, 'Name', 1, 0, 'C', true);
    $pdf->Cell(40, 10, 'Department', 1, 0, 'C', true);
    $pdf->Cell(35, 10, 'Completed/Total', 1, 0, 'C', true);
    $pdf->Cell(30, 10, 'Avg Progress', 1, 0, 'C', true);
    $pdf->Cell(25, 10, 'Points', 1, 1, 'C', true);

    $pdf->SetFont('helvetica', '', 11);
    foreach ($top_students as $student) {
        $pdf->Cell(60, 10, $student['name'], 1);
        $pdf->Cell(40, 10, $student['department'] ?? 'N/A', 1);
        $pdf->Cell(35, 10, $student['completed_goals'] . '/' . $student['total_goals'], 1, 0, 'C');
        $pdf->Cell(30, 10, round($student['avg_progress'], 1) . '%', 1, 0, 'C');
        $pdf->Cell(25, 10, $student['total_points'], 1, 1, 'C');
    }

    $pdf->Output('progressmate_report_' . date('Y-m-d') . '.pdf', 'D');
    exit;
}

// Individual Student PDF Export
if (isset($_GET['export_student']) && $_GET['export_student'] === 'pdf' && $student_report) {
    $pdf = new TCPDF(PDF_PAGE_ORIENTATION, PDF_UNIT, PDF_PAGE_FORMAT, true, 'UTF-8', false);

    $pdf->SetCreator('ProgressMate');
    $pdf->SetAuthor('Admin');
    $pdf->SetTitle('Student Report - ' . $student_report['name']);
    $pdf->setPrintHeader(false);
    $pdf->setPrintFooter(false);
    $pdf->SetMargins(15, 20, 15);
    $pdf->SetAutoPageBreak(true, 20);

    $pdf->AddPage();

    $pdf->SetFont('helvetica', 'B', 20);
    $pdf->Cell(0, 15, 'Student Report: ' . $student_report['name'], 0, 1, 'C');
    $pdf->Ln(10);

    $pdf->SetFont('helvetica', 'B', 14);
    $pdf->Cell(0, 10, 'Student Information', 0, 1);
    $pdf->SetFont('helvetica', '', 12);
    $pdf->Cell(0, 8, 'Email: ' . $student_report['email'], 0, 1);
    $pdf->Cell(0, 8, 'Department: ' . ($student_report['department'] ?? 'N/A'), 0, 1);
    $pdf->Cell(0, 8, 'Status: ' . ucfirst($student_report['status']), 0, 1);
    $pdf->Ln(10);

    $pdf->SetFont('helvetica', 'B', 14);
    $pdf->Cell(0, 10, 'Performance Statistics', 0, 1);
    $pdf->SetFont('helvetica', '', 12);
    $pdf->Cell(0, 8, 'Total Goals: ' . $student_report['total_goals'], 0, 1);
    $pdf->Cell(0, 8, 'Completed Goals: ' . $student_report['completed_goals'], 0, 1);
    $pdf->Cell(0, 8, 'Average Progress: ' . round($student_report['avg_progress'], 1) . '%', 0, 1);
    $pdf->Cell(0, 8, 'Total Points: ' . $student_report['total_points'], 0, 1);
    $pdf->Cell(0, 8, 'Achievements Unlocked: ' . count($student_report['achievements']), 0, 1);
    $pdf->Ln(10);

    $pdf->SetFont('helvetica', 'B', 14);
    $pdf->Cell(0, 10, 'Assigned Goals', 0, 1);

    $pdf->SetFont('helvetica', 'B', 11);
    $pdf->SetFillColor(240, 240, 240);
    $pdf->Cell(80, 10, 'Goal Title', 1, 0, 'C', true);
    $pdf->Cell(30, 10, 'Status', 1, 0, 'C', true);
    $pdf->Cell(30, 10, 'Progress', 1, 0, 'C', true);
    $pdf->Cell(40, 10, 'Due Date', 1, 1, 'C', true);

    $pdf->SetFont('helvetica', '', 11);
    foreach ($student_report['goals'] as $goal) {
        $pdf->Cell(80, 10, $goal['goal_title'], 1);
        $pdf->Cell(30, 10, ucfirst($goal['status']), 1, 0, 'C');
        $pdf->Cell(30, 10, $goal['progress_percentage'] . '%', 1, 0, 'C');
        $pdf->Cell(40, 10, $goal['due_date'] ? date('M d, Y', strtotime($goal['due_date'])) : 'No due date', 1, 1);
    }
    $pdf->Ln(10);

    $pdf->SetFont('helvetica', 'B', 14);
    $pdf->Cell(0, 10, 'Unlocked Achievements', 0, 1);

    $pdf->SetFont('helvetica', 'B', 11);
    $pdf->SetFillColor(240, 240, 240);
    $pdf->Cell(80, 10, 'Title', 1, 0, 'C', true);
    $pdf->Cell(60, 10, 'Description', 1, 0, 'C', true);
    $pdf->Cell(20, 10, 'Points', 1, 0, 'C', true);
    $pdf->Cell(40, 10, 'Earned On', 1, 1, 'C', true);

    $pdf->SetFont('helvetica', '', 11);
    foreach ($student_report['achievements'] as $ach) {
        $pdf->Cell(80, 10, $ach['title'], 1);
        $pdf->Cell(60, 10, substr($ach['description'], 0, 50) . (strlen($ach['description']) > 50 ? '...' : ''), 1);
        $pdf->Cell(20, 10, $ach['points'], 1, 0, 'C');
        $pdf->Cell(40, 10, date('M d, Y', strtotime($ach['earned_at'])), 1, 1);
    }

    $pdf->Output('student_report_' . $student_report['id'] . '_' . date('Y-m-d') . '.pdf', 'D');
    exit;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reports - ProgressMate</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<style>
    :root {
        --primary: #4f46e5;
        --primary-dark: #4338ca;
        --success: #10b981;
        --warning: #f59e0b;
        --danger: #ef4444;
        --info: #3b82f6;
        --purple: #8b5cf6;
        --gold: #fbbf24;
        --silver: #9ca3af;
        --bronze: #f97316;
        --gray-100: #f9fafb;
        --gray-200: #f3f4f6;
        --gray-300: #e5e7eb;
        --gray-400: #9ca3af;
        --gray-500: #6b7280;
        --gray-700: #374151;
        --gray-900: #111827;
        --shadow-sm: 0 1px 3px rgba(0,0,0,0.1);
        --shadow: 0 4px 12px rgba(0,0,0,0.08);
        --shadow-lg: 0 10px 25px rgba(0,0,0,0.1);
        --radius: 12px;
        --transition: all 0.3s ease;
        
        --success-light: #ecfdf5;
        --info-light: #eff6ff;
        --warning-light: #fef3c7;
        --danger-light: #fee2e2;
        --primary-light: #e0e7ff;
    }

    * { margin: 0; padding: 0; box-sizing: border-box; }
    body { font-family: 'Inter', sans-serif; background: var(--gray-100); color: var(--gray-900); line-height: 1.6; }
    a { text-decoration: none; color: inherit; transition: var(--transition); }
    
    .dashboard-wrapper { display: flex; min-height: 100vh; position: relative; }
    
    /* Sidebar - EXACTLY like admin.php */
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
    
    .sidebar-header {
        padding: 24px 20px;
        border-bottom: 1px solid var(--gray-300);
        display: flex;
        align-items: center;
        justify-content: space-between;
    }
    
    .logo {
        font-size: 20px;
        font-weight: 700;
        color: var(--primary);
        display: flex;
        align-items: center;
        gap: 10px;
    }
    
    .sidebar-close {
        display: none;
        background: none;
        border: none;
        font-size: 20px;
        color: var(--gray-500);
        cursor: pointer;
    }
    
    .user-profile {
        padding: 24px 20px;
        border-bottom: 1px solid var(--gray-300);
        display: flex;
        align-items: center;
        gap: 15px;
    }
    
    .profile-pic {
        width: 56px;
        height: 56px;
        border-radius: 50%;
        object-fit: cover;
        border: 3px solid var(--gray-300);
    }
    
    .profile-pic.default {
        background: linear-gradient(135deg, var(--primary), var(--purple));
        color: white;
        font-size: 24px;
        font-weight: bold;
        display: flex;
        align-items: center;
        justify-content: center;
    }
    
    .nav-menu {
        flex: 1;
        padding: 16px 0;
        overflow-y: auto;
    }
    
    .nav-link {
        display: flex;
        align-items: center;
        gap: 12px;
        padding: 14px 20px;
        color: var(--gray-700);
        transition: var(--transition);
    }
    
    .nav-link:hover {
        background: var(--gray-200);
        color: var(--primary);
        transform: translateX(4px);
    }
    
    .nav-link.active {
        background: #eef2ff;
        color: var(--primary);
        border-left: 4px solid var(--primary);
        font-weight: 600;
    }
    
    .nav-link i {
        width: 20px;
        text-align: center;
    }
    
    .badge {
        margin-left: auto;
        background: var(--primary);
        color: white;
        padding: 4px 10px;
        border-radius: 20px;
        font-size: 12px;
        font-weight: 600;
        min-width: 28px;
        text-align: center;
    }
    
    .sidebar-quick-stats {
        padding: 20px;
        border-top: 1px solid var(--gray-300);
    }
    
    .sidebar-stat {
        display: flex;
        align-items: center;
        gap: 15px;
        margin-bottom: 16px;
    }
    
    .sidebar-stat:last-child {
        margin-bottom: 0;
    }
    
    .sidebar-stat-icon {
        width: 44px;
        height: 44px;
        border-radius: 10px;
        background: #eef2ff;
        color: var(--primary);
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 20px;
    }
    
    .sidebar-stat-label {
        font-size: 13px;
        color: var(--gray-500);
    }
    
    .sidebar-stat-number {
        font-size: 18px;
        font-weight: 700;
    }
    
    .sidebar-footer {
        padding: 20px;
    }
    
    .logout-btn {
        display: flex;
        align-items: center;
        gap: 12px;
        padding: 14px 20px;
        background: #fee2e2;
        color: #dc2626;
        border-radius: 10px;
        width: 100%;
        font-weight: 500;
        transition: var(--transition);
        text-align: center;
        justify-content: center;
    }
    
    .logout-btn:hover {
        background: #fecaca;
        transform: translateY(-2px);
    }
    
    /* Main Content - EXACTLY like admin.php */
    .main-content {
        flex: 1;
        margin-left: 280px;
        padding: 32px;
        transition: var(--transition);
    }
    
    .page-header {
        display: flex;
        justify-content: space-between;
        align-items: flex-start;
        margin-bottom: 32px;
        flex-wrap: wrap;
        gap: 16px;
    }
    
    .page-header h1 {
        font-size: 30px;
        font-weight: 700;
        color: var(--gray-900);
        margin-bottom: 8px;
    }
    
    .page-header p {
        color: var(--gray-500);
        font-size: 16px;
    }
    
    /* Buttons - EXACTLY like admin.php */
    .btn {
        padding: 12px 24px;
        border-radius: 10px;
        font-weight: 500;
        cursor: pointer;
        border: none;
        transition: var(--transition);
        display: inline-flex;
        align-items: center;
        gap: 8px;
        font-size: 15px;
        text-decoration: none;
    }
    
    .btn-primary {
        background: linear-gradient(135deg, var(--primary), var(--primary-dark));
        color: white;
        box-shadow: var(--shadow);
    }
    
    .btn-primary:hover {
        transform: translateY(-2px);
        box-shadow: var(--shadow-lg);
    }
    
    .btn-outline {
        background: white;
        color: var(--primary);
        border: 1px solid var(--primary);
    }
    
    .btn-outline:hover {
        background: var(--primary);
        color: white;
    }
    
    /* Stats Grid - EXACTLY like admin.php */
    .stats-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(260px, 1fr));
        gap: 24px;
        margin-bottom: 40px;
    }
    
    .stat-card {
        background: white;
        border-radius: var(--radius);
        padding: 24px;
        box-shadow: var(--shadow);
        position: relative;
        overflow: hidden;
        transition: var(--transition);
    }
    
    .stat-card:hover {
        transform: translateY(-4px);
        box-shadow: var(--shadow-lg);
    }
    
    .stat-card::before {
        content: '';
        position: absolute;
        top: 0;
        left: 0;
        width: 5px;
        height: 100%;
    }
    
    .stat-card:nth-child(1)::before { background: var(--info); }
    .stat-card:nth-child(2)::before { background: var(--success); }
    .stat-card:nth-child(3)::before { background: var(--warning); }
    .stat-card:nth-child(4)::before { background: var(--purple); }
    .stat-card:nth-child(5)::before { background: var(--primary); }
    .stat-card:nth-child(6)::before { background: var(--danger); }
    
    .stat-content {
        display: flex;
        align-items: center;
        gap: 20px;
    }
    
    .stat-icon {
        width: 60px;
        height: 60px;
        border-radius: 12px;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 28px;
    }
    
    .stat-card:nth-child(1) .stat-icon { background: #dbeafe; color: var(--info); }
    .stat-card:nth-child(2) .stat-icon { background: #d1fae5; color: var(--success); }
    .stat-card:nth-child(3) .stat-icon { background: #fef3c7; color: var(--warning); }
    .stat-card:nth-child(4) .stat-icon { background: #e0e7ff; color: var(--purple); }
    .stat-card:nth-child(5) .stat-icon { background: #e0e7ff; color: var(--primary); }
    .stat-card:nth-child(6) .stat-icon { background: #fde2e2; color: var(--danger); }
    
    .stat-number {
        font-size: 32px;
        font-weight: 800;
        color: var(--gray-900);
        line-height: 1;
    }
    
    .stat-label {
        font-size: 15px;
        color: var(--gray-500);
        margin-top: 8px;
    }
    
    /* Filters Section - NEW but matching style */
    .filters-section {
        background: white;
        border-radius: var(--radius);
        padding: 24px;
        margin-bottom: 32px;
        box-shadow: var(--shadow);
    }
    
    .filters-section h3 {
        font-size: 18px;
        font-weight: 600;
        color: var(--gray-900);
        margin-bottom: 20px;
        display: flex;
        align-items: center;
        gap: 10px;
    }
    
    .filters-section h3 i {
        color: var(--primary);
    }
    
    .filter-row {
        display: flex;
        gap: 16px;
        flex-wrap: wrap;
        align-items: flex-end;
    }
    
    .filter-group {
        flex: 1;
        min-width: 200px;
    }
    
    .filter-group label {
        display: block;
        margin-bottom: 8px;
        font-weight: 500;
        color: var(--gray-700);
        font-size: 14px;
    }
    
    .filter-group input,
    .filter-group select {
        width: 100%;
        padding: 12px 16px;
        border: 1px solid var(--gray-300);
        border-radius: 8px;
        font-size: 15px;
        transition: var(--transition);
        background: white;
        color: var(--gray-900);
    }
    
    .filter-group input:focus,
    .filter-group select:focus {
        outline: none;
        border-color: var(--primary);
        box-shadow: 0 0 0 3px rgba(79, 70, 229, 0.1);
    }
    
    /* Report Cards - NEW but matching style */
    .report-card {
        background: white;
        border-radius: var(--radius);
        box-shadow: var(--shadow);
        overflow: hidden;
        margin-bottom: 32px;
        transition: var(--transition);
    }
    
    .report-card:hover {
        box-shadow: var(--shadow-lg);
        transform: translateY(-2px);
    }
    
    .card-header {
        padding: 24px;
        border-bottom: 1px solid var(--gray-300);
        display: flex;
        justify-content: space-between;
        align-items: center;
        background: var(--gray-100);
    }
    
    .card-header h3 {
        font-size: 19px;
        font-weight: 600;
        color: var(--gray-900);
        display: flex;
        align-items: center;
        gap: 10px;
    }
    
    .card-header h3 i {
        color: var(--primary);
    }
    
    .card-body {
        padding: 24px;
    }
    
    /* Report Tables - NEW but matching style */
    .report-table {
        width: 100%;
        border-collapse: separate;
        border-spacing: 0;
        border-radius: 10px;
        overflow: hidden;
        border: 1px solid var(--gray-300);
    }
    
    .report-table th {
        background: var(--gray-100);
        padding: 16px 20px;
        text-align: left;
        font-weight: 600;
        color: var(--gray-700);
        font-size: 14px;
        text-transform: uppercase;
        letter-spacing: 0.5px;
        border-bottom: 2px solid var(--gray-300);
    }
    
    .report-table td {
        padding: 16px 20px;
        border-bottom: 1px solid var(--gray-300);
        color: var(--gray-700);
        font-size: 15px;
    }
    
    .report-table tbody tr:last-child td {
        border-bottom: none;
    }
    
    .report-table tbody tr:hover {
        background: var(--gray-100);
    }
    
    .progress-cell {
        display: flex;
        align-items: center;
        gap: 12px;
    }
    
    .progress-bar {
        flex: 1;
        height: 8px;
        background: var(--gray-300);
        border-radius: 4px;
        overflow: hidden;
    }
    
    .progress-fill {
        height: 100%;
        background: var(--primary);
        border-radius: 4px;
        transition: width 1.8s ease-out;
    }
    
    /* Student Report - Special styling */
    .student-report-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 24px;
        padding-bottom: 20px;
        border-bottom: 2px solid var(--gray-200);
    }
    
    .student-report-header h3 {
        margin: 0;
        font-size: 22px;
        color: var(--gray-900);
    }
    
    .student-stats-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
        gap: 20px;
        margin: 24px 0;
    }
    
    /* Alerts */
    .alert {
        padding: 16px 24px;
        border-radius: var(--radius);
        margin-bottom: 24px;
        display: flex;
        align-items: center;
        gap: 12px;
        animation: slideIn 0.3s ease;
    }
    
    @keyframes slideIn {
        from {
            opacity: 0;
            transform: translateX(-10px);
        }
        to {
            opacity: 1;
            transform: translateX(0);
        }
    }
    
    .alert-success {
        background: var(--success-light);
        color: #065f46;
        border: 1px solid #a7f3d0;
    }
    
    .alert-error {
        background: var(--danger-light);
        color: #991b1b;
        border: 1px solid #fca5a5;
    }
    
    .alert i {
        font-size: 18px;
    }
    
    /* Empty State */
    .empty-state {
        text-align: center;
        padding: 60px 20px;
        color: var(--gray-500);
    }
    
    .empty-state i {
        font-size: 48px;
        margin-bottom: 16px;
        opacity: 0.5;
    }
    
    /* Mobile Toggle - EXACTLY like admin.php */
    .mobile-toggle {
        display: none;
        position: fixed;
        top: 20px;
        left: 20px;
        z-index: 1100;
        background: var(--primary);
        color: white;
        border: none;
        width: 48px;
        height: 48px;
        border-radius: 12px;
        font-size: 20px;
        cursor: pointer;
        box-shadow: var(--shadow);
        align-items: center;
        justify-content: center;
    }
    
    .mobile-toggle:hover {
        transform: scale(1.1);
    }
    
    .sidebar-overlay {
        display: none;
        position: fixed;
        top: 0;
        left: 0;
        right: 0;
        bottom: 0;
        background: rgba(0,0,0,0.5);
        z-index: 999;
        backdrop-filter: blur(3px);
    }
    
    /* Responsive - EXACTLY like admin.php */
    @media (max-width: 1024px) {
        .stats-grid {
            grid-template-columns: repeat(2, 1fr);
        }
        
        .student-stats-grid {
            grid-template-columns: repeat(2, 1fr);
        }
    }
    
    @media (max-width: 768px) {
        .mobile-toggle {
            display: flex;
        }
        
        .sidebar {
            transform: translateX(-100%);
            width: 300px;
            box-shadow: 4px 0 20px rgba(0,0,0,0.15);
        }
        
        .sidebar.active {
            transform: translateX(0);
        }
        
        .sidebar-overlay.active {
            display: block;
        }
        
        .sidebar-close {
            display: block;
        }
        
        .main-content {
            margin-left: 0;
            width: 100%;
            padding: 24px 16px;
            padding-top: 80px;
        }
        
        .page-header {
            flex-direction: column;
            align-items: flex-start;
        }
        
        .stats-grid {
            grid-template-columns: 1fr;
        }
        
        .student-stats-grid {
            grid-template-columns: 1fr;
        }
        
        .filter-row {
            flex-direction: column;
        }
        
        .filter-group {
            width: 100%;
        }
        
        .report-table {
            display: block;
            overflow-x: auto;
        }
        
        .student-report-header {
            flex-direction: column;
            align-items: flex-start;
            gap: 16px;
        }
        
        .btn {
            width: 100%;
            justify-content: center;
        }
    }
    
    @media (max-width: 480px) {
        .sidebar {
            width: 85%;
            max-width: 320px;
        }
        
        html {
            font-size: 14px;
        }
    }
</style>
</head>
<body>
    <div class="dashboard-wrapper">
        <aside class="sidebar">
            <div class="logo"><i class="fas fa-star"></i> ProgressMate</div>
            <nav>
                <a href="admin.php" class="nav-link"><i class="fas fa-tachometer-alt"></i> Dashboard</a>
                <a href="students.php" class="nav-link"><i class="fas fa-users"></i> Students</a>
                <a href="goals.php" class="nav-link"><i class="fas fa-bullseye"></i> System Goals</a>
                <a href="assign_goals.php" class="nav-link"><i class="fas fa-tasks"></i> Assign Goals</a>
                <a href="achievements.php" class="nav-link"><i class="fas fa-trophy"></i> Achievements</a>
                <a href="reports.php" class="nav-link active"><i class="fas fa-chart-bar"></i> Reports</a>
                <a href="notifications.php" class="nav-link"><i class="fas fa-bell"></i> Notifications</a>
                <a href="categories.php" class="nav-link active"><i class="fas fa-tags"></i> Categories</a>
                <a href="settings.php" class="nav-link"><i class="fas fa-cog"></i> Settings</a>
            </nav>
        </aside>

        <main class="main-content">
            <header class="page-header">
                <div>
                    <h1>Reports & Analytics</h1>
                    <p>System-wide and individual student performance</p>
                </div>
                <a href="?export=pdf" class="btn">Export Overall Report (PDF)</a>
            </header>

            <?php if ($success): ?><div class="alert-success"><?php echo htmlspecialchars($success); ?></div><?php endif; ?>
            <?php if ($error): ?><div class="alert-error"><?php echo htmlspecialchars($error); ?></div><?php endif; ?>

            <div class="filters-section">
                <form method="GET">
                    <div class="filter-row">
                        <div class="filter-group">
                            <label>Search</label>
                            <input type="text" name="search" value="<?php echo htmlspecialchars($search); ?>">
                        </div>
                        <div class="filter-group">
                            <label>Department</label>
                            <select name="department">
                                <option value="">All</option>
                                <?php foreach ($departments as $dept): ?>
                                    <option value="<?php echo htmlspecialchars($dept); ?>" <?php echo $department === $dept ? 'selected' : ''; ?>><?php echo htmlspecialchars($dept); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="filter-group">
                            <label>Status</label>
                            <select name="status">
                                <option value="all" <?php echo $status === 'all' ? 'selected' : ''; ?>>All</option>
                                <option value="active" <?php echo $status === 'active' ? 'selected' : ''; ?>>Active</option>
                                <option value="inactive" <?php echo $status === 'inactive' ? 'selected' : ''; ?>>Inactive</option>
                            </select>
                        </div>
                        <div class="filter-group">
                            <label>Student Report</label>
                            <select name="student_id">
                                <option value="">None</option>
                                <?php foreach ($pdo->query("SELECT id, name FROM users WHERE role = 'student' ORDER BY name")->fetchAll() as $s): ?>
                                    <option value="<?php echo $s['id']; ?>" <?php echo $student_id == $s['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($s['name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <button type="submit" class="btn">Apply</button>
                    </div>
                </form>
            </div>

            <div class="stats-grid">
                <div class="stat-card"><div class="stat-number"><?php echo $total_students; ?></div><div>Total Students</div></div>
                <div class="stat-card"><div class="stat-number"><?php echo $active_students; ?></div><div>Active</div></div>
                <div class="stat-card"><div class="stat-number"><?php echo $total_goals; ?></div><div>System Goals</div></div>
                <div class="stat-card"><div class="stat-number"><?php echo $assigned_goals; ?></div><div>Assigned</div></div>
                <div class="stat-card"><div class="stat-number"><?php echo $completed_goals; ?></div><div>Completed</div></div>
                <div class="stat-card"><div class="stat-number"><?php echo $total_points; ?></div><div>Points Awarded</div></div>
            </div>

            <div class="report-card">
                <h3>Department Statistics</h3>
                <table class="report-table">
                    <thead><tr><th>Department</th><th>Students</th><th>Assigned</th><th>Completed</th><th>Avg Progress</th><th>Points</th></tr></thead>
                    <tbody>
                        <?php foreach ($department_stats as $d): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($d['department'] ?? 'Unassigned'); ?></td>
                                <td><?php echo $d['total_students']; ?></td>
                                <td><?php echo $d['total_assigned']; ?></td>
                                <td><?php echo $d['completed_goals']; ?></td>
                                <td><?php echo round($d['avg_progress'], 1); ?>%</td>
                                <td><?php echo $d['total_points']; ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <div class="report-card">
                <h3>Top 10 Students</h3>
                <table class="report-table">
                    <thead><tr><th>Name</th><th>Department</th><th>Completed/Total</th><th>Avg Progress</th><th>Points</th></tr></thead>
                    <tbody>
                        <?php foreach ($top_students as $s): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($s['name']); ?></td>
                                <td><?php echo htmlspecialchars($s['department'] ?? 'N/A'); ?></td>
                                <td><?php echo $s['completed_goals'] . '/' . $s['total_goals']; ?></td>
                                <td><?php echo round($s['avg_progress'], 1); ?>%</td>
                                <td><?php echo $s['total_points']; ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <?php if ($student_report): ?>
                <div class="report-card">
                    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:20px;">
                        <h3><?php echo htmlspecialchars($student_report['name']); ?> Report</h3>
                        <a href="?student_id=<?php echo $student_id; ?>&export_student=pdf" class="btn">Export PDF</a>
                    </div>
                    <div class="stats-grid">
                        <div class="stat-card"><div class="stat-number"><?php echo $student_report['total_goals']; ?></div><div>Total Goals</div></div>
                        <div class="stat-card"><div class="stat-number"><?php echo $student_report['completed_goals']; ?></div><div>Completed</div></div>
                        <div class="stat-card"><div class="stat-number"><?php echo round($student_report['avg_progress'], 1); ?>%</div><div>Avg Progress</div></div>
                        <div class="stat-card"><div class="stat-number"><?php echo $student_report['total_points']; ?></div><div>Points</div></div>
                    </div>
                    <h4>Goals</h4>
                    <table class="report-table">
                        <thead><tr><th>Title</th><th>Status</th><th>Progress</th><th>Due Date</th></tr></thead>
                        <tbody>
                            <?php foreach ($student_report['goals'] as $g): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($g['goal_title']); ?></td>
                                    <td><?php echo ucfirst($g['status']); ?></td>
                                    <td><?php echo $g['progress_percentage']; ?>%</td>
                                    <td><?php echo $g['due_date'] ? date('M d, Y', strtotime($g['due_date'])) : 'None'; ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    <h4>Achievements</h4>
                    <table class="report-table">
                        <thead><tr><th>Title</th><th>Description</th><th>Points</th><th>Earned</th></tr></thead>
                        <tbody>
                            <?php foreach ($student_report['achievements'] as $a): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($a['title']); ?></td>
                                    <td><?php echo htmlspecialchars($a['description']); ?></td>
                                    <td><?php echo $a['points']; ?></td>
                                    <td><?php echo date('M d, Y', strtotime($a['earned_at'])); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </main>
    </div>

    <script>
    // Mobile sidebar toggle
    const mobileToggle = document.createElement('button');
    mobileToggle.className = 'mobile-toggle';
    mobileToggle.innerHTML = '<i class="fas fa-bars"></i>';
    mobileToggle.id = 'sidebarToggle';
    document.body.prepend(mobileToggle);

    const sidebar = document.querySelector('.sidebar');
    const sidebarToggle = document.getElementById('sidebarToggle');

    sidebarToggle.addEventListener('click', () => {
        sidebar.classList.toggle('active');
    });

    // Close sidebar when clicking outside on mobile
    document.addEventListener('click', (e) => {
        if (window.innerWidth <= 768 && sidebar.classList.contains('active')) {
            if (!sidebar.contains(e.target) && !sidebarToggle.contains(e.target)) {
                sidebar.classList.remove('active');
            }
        }
    });
</script>
</body>
</html>