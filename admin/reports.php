<?php
require_once '../includes/db_connection.php';
// require_once '../tcpdf/tcpdf.php'; // Assume TCPDF is installed in this path
checkAuth('admin');

$success = $_SESSION['success'] ?? '';
$error = $_SESSION['error'] ?? '';
unset($_SESSION['success'], $_SESSION['error']);

// Get filter parameters
$search = $_GET['search'] ?? '';
$department = $_GET['department'] ?? '';
$status = $_GET['status'] ?? 'all';
$student_id = $_GET['student_id'] ?? '';

// Get unique departments
try {
    $dept_stmt = $pdo->prepare("SELECT DISTINCT department FROM users WHERE role = 'student' AND department IS NOT NULL ORDER BY department");
    $dept_stmt->execute();
    $departments = $dept_stmt->fetchAll(PDO::FETCH_COLUMN);
} catch (Exception $e) {
    $departments = [];
}

// Get report data
try {
    // Overall stats
    $total_students = getStat($pdo, "SELECT COUNT(*) FROM users WHERE role = 'student'");
    $active_students = getStat($pdo, "SELECT COUNT(*) FROM users WHERE role = 'student' AND status = 'active'");
    $total_goals = getStat($pdo, "SELECT COUNT(*) FROM admin_goals");
    $assigned_goals = getStat($pdo, "SELECT COUNT(*) FROM student_goals");
    $completed_goals = getStat($pdo, "SELECT COUNT(*) FROM student_goals WHERE status = 'completed'");
    $total_points = getStat($pdo, "SELECT SUM(points) FROM user_achievements ua JOIN achievements a ON ua.achievement_id = a.id");

    // Department stats
    $dept_stats_query = "
        SELECT 
            u.department,
            COUNT(DISTINCT u.id) as total_students,
            COUNT(sg.id) as total_assigned,
            SUM(CASE WHEN sg.status = 'completed' THEN 1 ELSE 0 END) as completed_goals,
            AVG(sg.progress_percentage) as avg_progress,
            SUM(a.points) as total_points
        FROM users u
        LEFT JOIN student_goals sg ON u.id = sg.student_id
        LEFT JOIN user_achievements ua ON u.id = ua.user_id
        LEFT JOIN achievements a ON ua.achievement_id = a.id
        WHERE u.role = 'student'
    ";
    $dept_params = [];
    if ($department) {
        $dept_stats_query .= " AND u.department = ?";
        $dept_params[] = $department;
    }
    if ($status !== 'all') {
        $dept_stats_query .= " AND u.status = ?";
        $dept_params[] = $status;
    }
    $dept_stats_query .= " GROUP BY u.department ORDER BY avg_progress DESC";
    
    $dept_stmt = $pdo->prepare($dept_stats_query);
    $dept_stmt->execute($dept_params);
    $department_stats = $dept_stmt->fetchAll();

    // Top students
    $top_students_query = "
        SELECT 
            u.id,
            u.name,
            u.department,
            COUNT(sg.id) as total_goals,
            SUM(CASE WHEN sg.status = 'completed' THEN 1 ELSE 0 END) as completed_goals,
            AVG(sg.progress_percentage) as avg_progress,
            COALESCE(SUM(a.points), 0) as total_points
        FROM users u
        LEFT JOIN student_goals sg ON u.id = sg.student_id
        LEFT JOIN user_achievements ua ON u.id = ua.user_id
        LEFT JOIN achievements a ON ua.achievement_id = a.id
        WHERE u.role = 'student'
    ";
    $top_params = [];
    if ($search) {
        $top_students_query .= " AND (u.name LIKE ? OR u.email LIKE ?)";
        $search_term = "%$search%";
        $top_params[] = $search_term;
        $top_params[] = $search_term;
    }
    if ($department) {
        $top_students_query .= " AND u.department = ?";
        $top_params[] = $department;
    }
    if ($status !== 'all') {
        $top_students_query .= " AND u.status = ?";
        $top_params[] = $status;
    }
    $top_students_query .= " GROUP BY u.id ORDER BY completed_goals DESC, total_points DESC LIMIT 10";
    
    $top_stmt = $pdo->prepare($top_students_query);
    $top_stmt->execute($top_params);
    $top_students = $top_stmt->fetchAll();

    // Single student report if requested
    $student_report = null;
    if ($student_id) {
        $student_stmt = $pdo->prepare("
            SELECT 
                u.*,
                COUNT(sg.id) as total_goals,
                SUM(CASE WHEN sg.status = 'completed' THEN 1 ELSE 0 END) as completed_goals,
                AVG(sg.progress_percentage) as avg_progress,
                COALESCE(SUM(a.points), 0) as total_points
            FROM users u
            LEFT JOIN student_goals sg ON u.id = sg.student_id
            LEFT JOIN user_achievements ua ON u.id = ua.user_id
            LEFT JOIN achievements a ON ua.achievement_id = a.id
            WHERE u.id = ? AND u.role = 'student'
            GROUP BY u.id
        ");
        $student_stmt->execute([$student_id]);
        $student_report = $student_stmt->fetch();

        if ($student_report) {
            // Get student's goals
            $goals_stmt = $pdo->prepare("
                SELECT sg.*, ag.title as goal_title
                FROM student_goals sg
                JOIN admin_goals ag ON sg.goal_id = ag.id
                WHERE sg.student_id = ?
                ORDER BY sg.due_date
            ");
            $goals_stmt->execute([$student_id]);
            $student_report['goals'] = $goals_stmt->fetchAll();

            // Get achievements
            $ach_stmt = $pdo->prepare("
                SELECT a.*, ua.unlocked_at
                FROM user_achievements ua
                JOIN achievements a ON ua.achievement_id = a.id
                WHERE ua.user_id = ?
                ORDER BY ua.unlocked_at DESC
            ");
            $ach_stmt->execute([$student_id]);
            $student_report['achievements'] = $ach_stmt->fetchAll();
        }
    }

} catch (Exception $e) {
    $error = "Error fetching reports: " . $e->getMessage();
}

// Handle PDF export
if (isset($_GET['export']) && $_GET['export'] === 'pdf') {
    $pdf = new TCPDF();
    $pdf->SetCreator('ProgressMate');
    $pdf->SetAuthor('Admin');
    $pdf->SetTitle('ProgressMate Report');
    $pdf->SetSubject('System Reports');

    $pdf->AddPage();
    $pdf->SetFont('helvetica', 'B', 16);
    $pdf->Cell(0, 10, 'ProgressMate Reports', 0, 1, 'C');
    $pdf->Ln(10);

    // Overall Stats
    $pdf->SetFont('helvetica', 'B', 12);
    $pdf->Cell(0, 10, 'Overall Statistics', 0, 1);
    $pdf->SetFont('helvetica', '', 10);
    $pdf->Cell(0, 10, "Total Students: $total_students", 0, 1);
    $pdf->Cell(0, 10, "Active Students: $active_students", 0, 1);
    $pdf->Cell(0, 10, "Total Goals: $total_goals", 0, 1);
    $pdf->Cell(0, 10, "Assigned Goals: $assigned_goals", 0, 1);
    $pdf->Cell(0, 10, "Completed Goals: $completed_goals", 0, 1);
    $pdf->Cell(0, 10, "Total Points: $total_points", 0, 1);
    $pdf->Ln(10);

    // Department Stats
    $pdf->SetFont('helvetica', 'B', 12);
    $pdf->Cell(0, 10, 'Department Statistics', 0, 1);
    $pdf->SetFont('helvetica', 'B', 10);
    $pdf->Cell(40, 10, 'Department', 1);
    $pdf->Cell(30, 10, 'Students', 1);
    $pdf->Cell(30, 10, 'Assigned', 1);
    $pdf->Cell(30, 10, 'Completed', 1);
    $pdf->Cell(30, 10, 'Avg Progress', 1);
    $pdf->Cell(30, 10, 'Points', 1);
    $pdf->Ln();

    $pdf->SetFont('helvetica', '', 10);
    foreach ($department_stats as $dept) {
        $pdf->Cell(40, 10, $dept['department'] ?? 'N/A', 1);
        $pdf->Cell(30, 10, $dept['total_students'], 1);
        $pdf->Cell(30, 10, $dept['total_assigned'], 1);
        $pdf->Cell(30, 10, $dept['completed_goals'], 1);
        $pdf->Cell(30, 10, round($dept['avg_progress'], 1) . '%', 1);
        $pdf->Cell(30, 10, $dept['total_points'] ?? 0, 1);
        $pdf->Ln();
    }
    $pdf->Ln(10);

    // Top Students
    $pdf->SetFont('helvetica', 'B', 12);
    $pdf->Cell(0, 10, 'Top Students', 0, 1);
    $pdf->SetFont('helvetica', 'B', 10);
    $pdf->Cell(50, 10, 'Name', 1);
    $pdf->Cell(40, 10, 'Department', 1);
    $pdf->Cell(30, 10, 'Completed', 1);
    $pdf->Cell(30, 10, 'Progress', 1);
    $pdf->Cell(30, 10, 'Points', 1);
    $pdf->Ln();

    $pdf->SetFont('helvetica', '', 10);
    foreach ($top_students as $student) {
        $pdf->Cell(50, 10, $student['name'], 1);
        $pdf->Cell(40, 10, $student['department'] ?? 'N/A', 1);
        $pdf->Cell(30, 10, $student['completed_goals'] . '/' . $student['total_goals'], 1);
        $pdf->Cell(30, 10, round($student['avg_progress'], 1) . '%', 1);
        $pdf->Cell(30, 10, $student['total_points'], 1);
        $pdf->Ln();
    }

    $pdf->Output('progressmate_report.pdf', 'D');
    exit;
}

// Handle single student PDF export
if (isset($_GET['export_student']) && $_GET['export_student'] === 'pdf' && $student_id && $student_report) {
    $pdf = new TCPDF();
    $pdf->SetCreator('ProgressMate');
    $pdf->SetAuthor('Admin');
    $pdf->SetTitle('Student Report - ' . $student_report['name']);
    $pdf->SetSubject('Individual Student Report');

    $pdf->AddPage();
    $pdf->SetFont('helvetica', 'B', 16);
    $pdf->Cell(0, 10, 'Student Report: ' . $student_report['name'], 0, 1, 'C');
    $pdf->Ln(10);

    // Student Info
    $pdf->SetFont('helvetica', 'B', 12);
    $pdf->Cell(0, 10, 'Student Information', 0, 1);
    $pdf->SetFont('helvetica', '', 10);
    $pdf->Cell(0, 10, "Email: " . $student_report['email'], 0, 1);
    $pdf->Cell(0, 10, "Department: " . ($student_report['department'] ?? 'N/A'), 0, 1);
    $pdf->Cell(0, 10, "Status: " . ucfirst($student_report['status']), 0, 1);
    $pdf->Ln(10);

    // Stats
    $pdf->SetFont('helvetica', 'B', 12);
    $pdf->Cell(0, 10, 'Performance Statistics', 0, 1);
    $pdf->SetFont('helvetica', '', 10);
    $pdf->Cell(0, 10, "Total Goals: " . $student_report['total_goals'], 0, 1);
    $pdf->Cell(0, 10, "Completed Goals: " . $student_report['completed_goals'], 0, 1);
    $pdf->Cell(0, 10, "Average Progress: " . round($student_report['avg_progress'], 1) . '%', 0, 1);
    $pdf->Cell(0, 10, "Total Points: " . $student_report['total_points'], 0, 1);
    $pdf->Ln(10);

    // Goals
    $pdf->SetFont('helvetica', 'B', 12);
    $pdf->Cell(0, 10, 'Goals', 0, 1);
    $pdf->SetFont('helvetica', 'B', 10);
    $pdf->Cell(60, 10, 'Title', 1);
    $pdf->Cell(30, 10, 'Status', 1);
    $pdf->Cell(30, 10, 'Progress', 1);
    $pdf->Cell(40, 10, 'Due Date', 1);
    $pdf->Ln();

    $pdf->SetFont('helvetica', '', 10);
    foreach ($student_report['goals'] as $goal) {
        $pdf->Cell(60, 10, $goal['goal_title'], 1);
        $pdf->Cell(30, 10, ucfirst($goal['status']), 1);
        $pdf->Cell(30, 10, $goal['progress_percentage'] . '%', 1);
        $pdf->Cell(40, 10, $goal['due_date'] ? date('Y-m-d', strtotime($goal['due_date'])) : 'N/A', 1);
        $pdf->Ln();
    }
    $pdf->Ln(10);

    // Achievements
    $pdf->SetFont('helvetica', 'B', 12);
    $pdf->Cell(0, 10, 'Achievements', 0, 1);
    $pdf->SetFont('helvetica', 'B', 10);
    $pdf->Cell(60, 10, 'Name', 1);
    $pdf->Cell(30, 10, 'Points', 1);
    $pdf->Cell(60, 10, 'Unlocked At', 1);
    $pdf->Ln();

    $pdf->SetFont('helvetica', '', 10);
    foreach ($student_report['achievements'] as $ach) {
        $pdf->Cell(60, 10, $ach['name'], 1);
        $pdf->Cell(30, 10, $ach['points'], 1);
        $pdf->Cell(60, 10, date('Y-m-d H:i:s', strtotime($ach['unlocked_at'])), 1);
        $pdf->Ln();
    }

    $pdf->Output('student_report_' . $student_report['id'] . '.pdf', 'D');
    exit;
}

// Get admin stats for sidebar
$sidebar_stats = [
    'students' => $total_students,
    'goals' => $total_goals,
    'assigned' => $assigned_goals,
    'points' => $total_points
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reports - ProgressMate</title>
    <!-- <link rel="stylesheet" href="../assets/css/style.css"> -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=Roboto:wght@400;500;700&display=swap" rel="stylesheet">
    <style>
      
        /* ===== DASHBOARD BASE STYLES ===== */
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Inter', sans-serif;
            background: #f9fafb;
            color: #333;
            line-height: 1.5;
        }

        .dashboard-wrapper {
            display: flex;
            min-height: 100vh;
        }

        /* ===== SIDEBAR STYLES ===== */
        .sidebar {
            width: 280px;
            background: white;
            border-right: 1px solid #e5e7eb;
            display: flex;
            flex-direction: column;
            position: fixed;
            height: 100vh;
            left: 0;
            top: 0;
            z-index: 1000;
            transition: transform 0.3s ease;
        }

        @media (max-width: 768px) {
            .sidebar {
                transform: translateX(-100%);
            }
            .sidebar.active {
                transform: translateX(0);
            }
        }

        .sidebar-header {
            padding: 20px;
            border-bottom: 1px solid #e5e7eb;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .logo {
            color: #4f46e5;
            font-weight: 700;
            font-size: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .sidebar-close {
            display: none;
            background: none;
            border: none;
            color: #6b7280;
            cursor: pointer;
            font-size: 20px;
            margin-left: auto;
        }

        @media (max-width: 768px) {
            .sidebar-close {
                display: block;
            }
        }

        .user-profile {
            padding: 20px;
            border-bottom: 1px solid #e5e7eb;
            display: flex;
            align-items: center;
            gap: 15px;
        }

        .profile-pic {
            width: 50px;
            height: 50px;
            border-radius: 50%;
            object-fit: cover;
            border: 3px solid #e5e7eb;
        }

        .profile-pic.default {
            background: linear-gradient(135deg, #4f46e5, #8b5cf6);
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 20px;
            font-weight: bold;
        }

        .user-info h4 {
            margin: 0 0 5px 0;
            font-size: 16px;
            font-weight: 600;
        }

        .user-info p {
            margin: 0;
            color: #6b7280;
            font-size: 14px;
        }

        .nav-menu {
            flex: 1;
            padding: 20px 0;
            overflow-y: auto;
        }

        .nav-link {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 12px 20px;
            color: #374151;
            text-decoration: none;
            transition: all 0.2s;
            position: relative;
        }

        .nav-link:hover {
            background: #f3f4f6;
            color: #4f46e5;
        }

        .nav-link.active {
            background: #eef2ff;
            color: #4f46e5;
            border-left: 3px solid #4f46e5;
        }

        .nav-link i {
            width: 20px;
            text-align: center;
        }

        .badge {
            background: #4f46e5;
            color: white;
            padding: 2px 8px;
            border-radius: 12px;
            font-size: 12px;
            font-weight: 600;
            margin-left: auto;
        }

        .sidebar-footer {
            padding: 20px;
            border-top: 1px solid #e5e7eb;
        }

        .logout-btn {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 12px 20px;
            background: #fee2e2;
            color: #dc2626;
            border: none;
            border-radius: 8px;
            width: 100%;
            text-align: left;
            cursor: pointer;
            transition: all 0.2s;
            font-size: 14px;
            font-weight: 500;
        }

        .logout-btn:hover {
            background: #fecaca;
        }

        .sidebar-quick-stats {
            padding: 20px;
            border-top: 1px solid #e5e7eb;
        }

        .sidebar-stat {
            display: flex;
            align-items: center;
            gap: 15px;
            margin-bottom: 15px;
        }

        .sidebar-stat:last-child {
            margin-bottom: 0;
        }

        .sidebar-stat-icon {
            width: 40px;
            height: 40px;
            border-radius: 10px;
            background: #eef2ff;
            color: #4f46e5;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .sidebar-stat-info {
            flex: 1;
        }

        .sidebar-stat-label {
            font-size: 12px;
            color: #6b7280;
        }

        .sidebar-stat-number {
            font-size: 16px;
            font-weight: 600;
            color: #111827;
        }

        /* ===== MAIN CONTENT STYLES ===== */
        .main-content {
            flex: 1;
            margin-left: 280px;
            padding: 20px;
            overflow-y: auto;
        }

        @media (max-width: 768px) {
            .main-content {
                margin-left: 0;
                padding: 15px;
            }
        }

        .mobile-toggle {
            display: none;
            position: fixed;
            top: 15px;
            left: 15px;
            z-index: 999;
            background: #4f46e5;
            color: white;
            border: none;
            width: 40px;
            height: 40px;
            border-radius: 8px;
            cursor: pointer;
            align-items: center;
            justify-content: center;
        }

        @media (max-width: 768px) {
            .mobile-toggle {
                display: flex;
            }
        }

        /* ===== ADMIN DASHBOARD STYLES ===== */
        .page-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 30px;
        }

        .header-content h1 {
            margin: 0 0 10px 0;
            font-size: 28px;
            color: #111827;
        }

        .header-content p {
            margin: 0;
            color: #6b7280;
        }

        .btn {
            padding: 10px 20px;
            border-radius: 8px;
            font-weight: 500;
            cursor: pointer;
            border: none;
            transition: all 0.2s;
            font-size: 14px;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }

        .btn-primary {
            background: #4f46e5;
            color: white;
        }

        .btn-primary:hover {
            background: #4338ca;
        }

        .btn-outline {
            background: white;
            color: #4f46e5;
            border: 1px solid #4f46e5;
        }

        .btn-outline:hover {
            background: #4f46e5;
            color: white;
        }

        .btn-sm {
            padding: 6px 12px;
            font-size: 13px;
        }

        /* Stats Grid */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }

        .stat-card {
            background: white;
            border-radius: 12px;
            padding: 20px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
            border-left: 4px solid #4f46e5;
        }

        .stat-card.students { border-left-color: #3b82f6; }
        .stat-card.goals { border-left-color: #10b981; }
        .stat-card.assigned { border-left-color: #f59e0b; }
        .stat-card.points { border-left-color: #8b5cf6; }

        .stat-content {
            display: flex;
            align-items: center;
            gap: 15px;
        }

        .stat-icon {
            width: 50px;
            height: 50px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 24px;
        }

        .stat-card.students .stat-icon { background: #dbeafe; color: #3b82f6; }
        .stat-card.goals .stat-icon { background: #d1fae5; color: #10b981; }
        .stat-card.assigned .stat-icon { background: #fef3c7; color: #f59e0b; }
        .stat-card.points .stat-icon { background: #e0e7ff; color: #8b5cf6; }

        .stat-number {
            font-size: 28px;
            font-weight: 700;
            margin: 5px 0;
            color: #111827;
        }

        .stat-label {
            font-size: 14px;
            color: #6b7280;
        }

        /* Content Grid */
        .content-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(350px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }

        @media (max-width: 768px) {
            .content-grid {
                grid-template-columns: 1fr;
            }
        }

        .card {
            background: white;
            border-radius: 12px;
            overflow: hidden;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
        }

        .card-header {
            padding: 20px;
            border-bottom: 1px solid #e5e7eb;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .card-header h3 {
            margin: 0;
            font-size: 18px;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .card-body {
            padding: 20px;
        }

        /* Activity List */
        .activity-list {
            display: flex;
            flex-direction: column;
            gap: 15px;
        }

        .activity-item {
            display: flex;
            align-items: center;
            gap: 15px;
            padding: 10px;
            border-radius: 8px;
            background: #f9fafb;
        }

        .activity-icon {
            width: 40px;
            height: 40px;
            border-radius: 8px;
            background: #e0e7ff;
            color: #4f46e5;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .activity-content {
            flex: 1;
        }

        .activity-title {
            font-weight: 500;
            color: #111827;
            margin-bottom: 2px;
        }

        .activity-details {
            font-size: 12px;
            color: #6b7280;
        }

        .activity-time {
            font-size: 12px;
            color: #9ca3af;
        }

        /* Department Stats */
        .dept-stats {
            display: flex;
            flex-direction: column;
            gap: 10px;
        }

        .dept-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 10px;
            border-radius: 8px;
            background: #f9fafb;
        }

        .dept-name {
            font-weight: 500;
            color: #374151;
        }

        .dept-progress {
            display: flex;
            align-items: center;
            gap: 15px;
        }

        .progress-bar {
            width: 100px;
            height: 8px;
            background: #e5e7eb;
            border-radius: 4px;
            overflow: hidden;
        }

        .progress-fill {
            height: 100%;
            background: #4f46e5;
            border-radius: 4px;
        }

        .progress-text {
            font-size: 12px;
            color: #6b7280;
            min-width: 40px;
            text-align: right;
        }

        /* Top Students */
        .top-students-list {
            display: flex;
            flex-direction: column;
            gap: 10px;
        }

        .student-item {
            display: flex;
            align-items: center;
            gap: 15px;
            padding: 10px;
            border-radius: 8px;
            background: #f9fafb;
        }

        .student-avatar {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: linear-gradient(135deg, #4f46e5, #8b5cf6);
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: bold;
            font-size: 16px;
        }

        .student-info {
            flex: 1;
        }

        .student-name {
            font-weight: 500;
            color: #111827;
            margin-bottom: 2px;
        }

        .student-details {
            font-size: 12px;
            color: #6b7280;
        }

        .student-score {
            font-weight: 600;
            color: #10b981;
        }

        /* Quick Actions */
        .quick-actions-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 15px;
            margin-top: 20px;
        }

        .quick-action {
            padding: 20px;
            background: #f9fafb;
            border-radius: 10px;
            text-align: center;
            text-decoration: none;
            color: #374151;
            transition: all 0.2s;
            display: block;
        }

        .quick-action:hover {
            background: #e5e7eb;
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0,0,0,0.1);
        }

        .quick-action i {
            font-size: 24px;
            margin-bottom: 10px;
            display: block;
            color: #4f46e5;
        }

        .quick-action span {
            font-size: 14px;
            font-weight: 500;
        }

        /* Goal Status Distribution */
        .status-distribution {
            display: flex;
            flex-direction: column;
            gap: 10px;
        }

        .status-item {
            display: flex;
            align-items: center;
            gap: 15px;
            padding: 10px;
            border-radius: 8px;
        }

        .status-count {
            font-weight: 600;
            color: #111827;
            min-width: 30px;
        }

        .status-label {
            flex: 1;
            font-size: 14px;
        }

        .status-bar {
            width: 100px;
            height: 8px;
            background: #e5e7eb;
            border-radius: 4px;
            overflow: hidden;
        }

        .status-fill {
            height: 100%;
            border-radius: 4px;
        }

        .status-completed .status-fill { background: #10b981; }
        .status-in-progress .status-fill { background: #3b82f6; }
        .status-pending .status-fill { background: #f59e0b; }
        .status-overdue .status-fill { background: #ef4444; }

        .status-percentage {
            font-size: 12px;
            color: #6b7280;
            min-width: 40px;
            text-align: right;
        }

        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 40px 20px;
            color: #6b7280;
        }

        .empty-state i {
            font-size: 24px;
            margin-bottom: 10px;
        }

        /* Reuse styles from previous pages */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }
        
        .stat-card {
            background: white;
            border-radius: 12px;
            padding: 20px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
            text-align: center;
        }
        
        .stat-number {
            font-size: 28px;
            font-weight: 700;
            color: #111827;
        }
        
        .stat-label {
            font-size: 14px;
            color: #6b7280;
        }
        
        .filters-section {
            background: white;
            border-radius: 12px;
            padding: 20px;
            margin-bottom: 20px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
        }
        
        .filter-row {
            display: flex;
            gap: 15px;
            flex-wrap: wrap;
            align-items: flex-end;
        }
        
        .filter-group {
            flex: 1;
            min-width: 200px;
        }
        
        .report-card {
            background: white;
            border-radius: 12px;
            padding: 20px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
            margin-bottom: 30px;
        }
        
        .report-table {
            width: 100%;
            border-collapse: collapse;
        }
        
        .report-table th {
            background: #f9fafb;
            padding: 15px;
            text-align: left;
            border-bottom: 1px solid #e5e7eb;
        }
        
        .report-table td {
            padding: 15px;
            border-bottom: 1px solid #f3f4f6;
        }
        
        .student-select {
            width: 100%;
            padding: 10px;
            border: 1px solid #d1d5db;
            border-radius: 8px;
        }
        
        .student-report {
            background: white;
            border-radius: 12px;
            padding: 20px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
        }
        
        .student-info {
            margin-bottom: 20px;
        }
        
        .student-stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 15px;
            margin-bottom: 20px;
        }
        
        .student-stat {
            background: #f9fafb;
            padding: 15px;
            border-radius: 8px;
            text-align: center;
        }
        
        .student-stat .stat-number {
            font-size: 24px;
            font-weight: 700;
        }
        
        .student-stat .stat-label {
            font-size: 12px;
            color: #6b7280;
        }
    </style>
</head>
<body>
    <!-- Mobile Menu Toggle -->
    <button class="mobile-toggle" id="sidebarToggle">
        <i class="fas fa-bars"></i>
    </button>
    
    <div class="dashboard-wrapper">
        <!-- Sidebar -->
        <aside class="sidebar" id="sidebar">
            <div class="sidebar-header">
                <div class="logo">
                    <i class="fas fa-star"></i>
                    <span>ProgressMate</span>
                </div>
                <button class="sidebar-close" id="sidebarClose">
                    <i class="fas fa-times"></i>
                </button>
            </div>

            <div class="user-profile">
                <?php if (!empty($_SESSION['profile_picture'])): ?>
                    <img src="../<?php echo htmlspecialchars($_SESSION['profile_picture']); ?>" alt="Profile" class="profile-pic">
                <?php else: ?>
                    <div class="profile-pic default">
                        <?php echo strtoupper(substr($_SESSION['name'], 0, 1)); ?>
                    </div>
                <?php endif; ?>
                <div class="user-info">
                    <h4><?php echo htmlspecialchars($_SESSION['name']); ?></h4>
                    <p><?php echo htmlspecialchars($_SESSION['email']); ?></p>
                    <span style="font-size: 11px; background: #e0e7ff; color: #4f46e5; padding: 2px 8px; border-radius: 12px;">
                        ADMIN
                    </span>
                </div>
            </div>

            <nav class="nav-menu">
                <a href="admin.php" class="nav-link">
                    <i class="fas fa-tachometer-alt"></i>
                    <span>Dashboard</span>
                </a>
                <a href="students.php" class="nav-link">
                    <i class="fas fa-users"></i>
                    <span>Students</span>
                    <?php if ($sidebar_stats['students'] > 0): ?>
                        <span class="badge"><?php echo $sidebar_stats['students']; ?></span>
                    <?php endif; ?>
                </a>
                <a href="goals.php" class="nav-link">
                    <i class="fas fa-bullseye"></i>
                    <span>System Goals</span>
                    <?php if ($sidebar_stats['goals'] > 0): ?>
                        <span class="badge"><?php echo $sidebar_stats['goals']; ?></span>
                    <?php endif; ?>
                </a>
                <a href="assign_goals.php" class="nav-link">
                    <i class="fas fa-tasks"></i>
                    <span>Assign Goals</span>
                    <?php if ($sidebar_stats['assigned'] > 0): ?>
                        <span class="badge"><?php echo $sidebar_stats['assigned']; ?></span>
                    <?php endif; ?>
                </a>
                <a href="achievements.php" class="nav-link">
                    <i class="fas fa-trophy"></i>
                    <span>Achievements</span>
                    <?php if ($sidebar_stats['points'] > 0): ?>
                        <span class="badge"><?php echo $sidebar_stats['points']; ?> pts</span>
                    <?php endif; ?>
                </a>
                <a href="reports.php" class="nav-link active">
                    <i class="fas fa-chart-bar"></i>
                    <span>Reports</span>
                </a>
                <a href="notifications.php" class="nav-link">
                    <i class="fas fa-bell"></i>
                    <span>Notifications</span>
                </a>
                <a href="settings.php" class="nav-link">
                    <i class="fas fa-cog"></i>
                    <span>Settings</span>
                </a>
            </nav>

            <div class="sidebar-quick-stats">
                <div class="sidebar-stat">
                    <div class="sidebar-stat-icon">
                        <i class="fas fa-users"></i>
                    </div>
                    <div class="sidebar-stat-info">
                        <div class="sidebar-stat-label">Students</div>
                        <div class="sidebar-stat-number"><?php echo $sidebar_stats['students']; ?></div>
                    </div>
                </div>
                <div class="sidebar-stat">
                    <div class="sidebar-stat-icon">
                        <i class="fas fa-bullseye"></i>
                    </div>
                    <div class="sidebar-stat-info">
                        <div class="sidebar-stat-label">Goals</div>
                        <div class="sidebar-stat-number"><?php echo $sidebar_stats['goals']; ?></div>
                    </div>
                </div>
                <div class="sidebar-stat">
                    <div class="sidebar-stat-icon">
                        <i class="fas fa-star"></i>
                    </div>
                    <div class="sidebar-stat-info">
                        <div class="sidebar-stat-label">Points</div>
                        <div class="sidebar-stat-number"><?php echo $sidebar_stats['points']; ?></div>
                    </div>
                </div>
            </div>

            <div class="sidebar-footer">
                <a href="../logout.php" class="logout-btn">
                    <i class="fas fa-sign-out-alt"></i>
                    <span>Logout</span>
                </a>
            </div>
        </aside>
        
        <main class="main-content">
            <header class="page-header">
                <div class="header-content">
                    <h1>Reports & Analytics</h1>
                    <p>View system and student performance reports</p>
                </div>
                <a href="?export=pdf<?php echo $department ? '&department=' . urlencode($department) : ''; ?><?php echo $status !== 'all' ? '&status=' . urlencode($status) : ''; ?>" class="btn btn-primary">
                    <i class="fas fa-file-pdf"></i> Export Overall Report
                </a>
            </header>
            
            <?php if ($success): ?>
                <div class="alert alert-success">
                    <i class="fas fa-check-circle"></i>
                    <?php echo htmlspecialchars($success); ?>
                </div>
            <?php endif; ?>
            
            <?php if ($error): ?>
                <div class="alert alert-error">
                    <i class="fas fa-exclamation-circle"></i>
                    <?php echo htmlspecialchars($error); ?>
                </div>
            <?php endif; ?>
            
            <!-- Filters -->
            <div class="filters-section">
                <form method="GET">
                    <div class="filter-row">
                        <div class="filter-group">
                            <label for="search">Search Students</label>
                            <input type="text" id="search" name="search" value="<?php echo htmlspecialchars($search); ?>" placeholder="Search by name or email...">
                        </div>
                        <div class="filter-group">
                            <label for="department">Department</label>
                            <select id="department" name="department">
                                <option value="">All Departments</option>
                                <?php foreach ($departments as $dept): ?>
                                    <option value="<?php echo htmlspecialchars($dept); ?>" <?php echo $department === $dept ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($dept); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="filter-group">
                            <label for="status">Student Status</label>
                            <select id="status" name="status">
                                <option value="all" <?php echo $status === 'all' ? 'selected' : ''; ?>>All</option>
                                <option value="active" <?php echo $status === 'active' ? 'selected' : ''; ?>>Active</option>
                                <option value="inactive" <?php echo $status === 'inactive' ? 'selected' : ''; ?>>Inactive</option>
                            </select>
                        </div>
                        
                        <div class="filter-group">
                            <label for="student_id">Individual Student Report</label>
                            <select id="student_id" name="student_id" class="student-select">
                                <option value="">Select a student</option>
                                <?php 
                                $students_stmt = $pdo->prepare("SELECT id, name FROM users WHERE role = 'student' ORDER BY name");
                                $students_stmt->execute();
                                $all_students = $students_stmt->fetchAll();
                                foreach ($all_students as $stud): ?>
                                    <option value="<?php echo $stud['id']; ?>" <?php echo $student_id == $stud['id'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($stud['name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-filter"></i> Apply Filters
                        </button>
                    </div>
                </form>
            </div>
            
            <!-- Overall Stats -->
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-number"><?php echo $total_students; ?></div>
                    <div class="stat-label">Total Students</div>
                </div>
                <div class="stat-card">
                    <div class="stat-number"><?php echo $active_students; ?></div>
                    <div class="stat-label">Active Students</div>
                </div>
                <div class="stat-card">
                    <div class="stat-number"><?php echo $total_goals; ?></div>
                    <div class="stat-label">System Goals</div>
                </div>
                <div class="stat-card">
                    <div class="stat-number"><?php echo $assigned_goals; ?></div>
                    <div class="stat-label">Assigned Goals</div>
                </div>
                <div class="stat-card">
                    <div class="stat-number"><?php echo $completed_goals; ?></div>
                    <div class="stat-label">Completed Goals</div>
                </div>
                <div class="stat-card">
                    <div class="stat-number"><?php echo $total_points; ?></div>
                    <div class="stat-label">Total Points Awarded</div>
                </div>
            </div>
            
            <!-- Department Stats -->
            <div class="report-card">
                <h3 style="margin-bottom: 20px;">Department Statistics</h3>
                <table class="report-table">
                    <thead>
                        <tr>
                            <th>Department</th>
                            <th>Students</th>
                            <th>Assigned Goals</th>
                            <th>Completed Goals</th>
                            <th>Avg Progress</th>
                            <th>Total Points</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($department_stats)): ?>
                            <tr>
                                <td colspan="6" style="text-align: center; padding: 20px;">No data available</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($department_stats as $dept): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($dept['department'] ?? 'Unassigned'); ?></td>
                                    <td><?php echo $dept['total_students']; ?></td>
                                    <td><?php echo $dept['total_assigned']; ?></td>
                                    <td><?php echo $dept['completed_goals']; ?></td>
                                    <td><?php echo round($dept['avg_progress'] ?? 0, 1); ?>%</td>
                                    <td><?php echo $dept['total_points'] ?? 0; ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
            
            <!-- Top Students -->
            <div class="report-card">
                <h3 style="margin-bottom: 20px;">Top Performing Students</h3>
                <table class="report-table">
                    <thead>
                        <tr>
                            <th>Name</th>
                            <th>Department</th>
                            <th>Completed / Total</th>
                            <th>Avg Progress</th>
                            <th>Points</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($top_students)): ?>
                            <tr>
                                <td colspan="5" style="text-align: center; padding: 20px;">No data available</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($top_students as $student): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($student['name']); ?></td>
                                    <td><?php echo htmlspecialchars($student['department'] ?? 'N/A'); ?></td>
                                    <td><?php echo $student['completed_goals'] . ' / ' . $student['total_goals']; ?></td>
                                    <td><?php echo round($student['avg_progress'] ?? 0, 1); ?>%</td>
                                    <td><?php echo $student['total_points']; ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
            
            <!-- Single Student Report -->
            <?php if ($student_id && $student_report): ?>
                <div class="student-report">
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
                        <h3><?php echo htmlspecialchars($student_report['name']); ?> - Detailed Report</h3>
                        <a href="?search=<?php echo urlencode($search); ?>&department=<?php echo urlencode($department); ?>&status=<?php echo urlencode($status); ?>&student_id=<?php echo $student_id; ?>&export_student=pdf" class="btn btn-primary">
                            <i class="fas fa-file-pdf"></i> Export to PDF
                        </a>
                    </div>
                    
                    <div class="student-info">
                        <p><strong>Email:</strong> <?php echo htmlspecialchars($student_report['email']); ?></p>
                        <p><strong>Department:</strong> <?php echo htmlspecialchars($student_report['department'] ?? 'N/A'); ?></p>
                        <p><strong>Status:</strong> <?php echo ucfirst($student_report['status']); ?></p>
                        <p><strong>Joined:</strong> <?php echo date('Y-m-d', strtotime($student_report['created_at'])); ?></p>
                    </div>
                    
                    <div class="student-stats">
                        <div class="student-stat">
                            <div class="stat-number"><?php echo $student_report['total_goals']; ?></div>
                            <div class="stat-label">Total Goals</div>
                        </div>
                        <div class="student-stat">
                            <div class="stat-number"><?php echo $student_report['completed_goals']; ?></div>
                            <div class="stat-label">Completed Goals</div>
                        </div>
                        <div class="student-stat">
                            <div class="stat-number"><?php echo round($student_report['avg_progress'] ?? 0, 1); ?>%</div>
                            <div class="stat-label">Avg Progress</div>
                        </div>
                        <div class="student-stat">
                            <div class="stat-number"><?php echo $student_report['total_points']; ?></div>
                            <div class="stat-label">Total Points</div>
                        </div>
                        <div class="student-stat">
                            <div class="stat-number"><?php echo count($student_report['achievements']); ?></div>
                            <div class="stat-label">Achievements</div>
                        </div>
                    </div>
                    
                    <h4 style="margin-bottom: 10px;">Assigned Goals</h4>
                    <table class="report-table">
                        <thead>
                            <tr>
                                <th>Title</th>
                                <th>Status</th>
                                <th>Progress</th>
                                <th>Due Date</th>
                                <th>Updated At</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($student_report['goals'])): ?>
                                <tr>
                                    <td colspan="5" style="text-align: center; padding: 20px;">No goals assigned</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($student_report['goals'] as $goal): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($goal['goal_title']); ?></td>
                                        <td><?php echo ucfirst(str_replace('_', ' ', $goal['status'])); ?></td>
                                        <td><?php echo $goal['progress_percentage']; ?>%</td>
                                        <td><?php echo $goal['due_date'] ? date('Y-m-d', strtotime($goal['due_date'])) : 'N/A'; ?></td>
                                        <td><?php echo date('Y-m-d H:i', strtotime($goal['updated_at'])); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                    
                    <h4 style="margin: 20px 0 10px 0;">Unlocked Achievements</h4>
                    <table class="report-table">
                        <thead>
                            <tr>
                                <th>Name</th>
                                <th>Description</th>
                                <th>Points</th>
                                <th>Unlocked At</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($student_report['achievements'])): ?>
                                <tr>
                                    <td colspan="4" style="text-align: center; padding: 20px;">No achievements unlocked</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($student_report['achievements'] as $ach): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($ach['name']); ?></td>
                                        <td><?php echo htmlspecialchars($ach['description']); ?></td>
                                        <td><?php echo $ach['points']; ?></td>
                                        <td><?php echo date('Y-m-d H:i', strtotime($ach['unlocked_at'])); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            <?php elseif ($student_id): ?>
                <div class="alert alert-error">
                    <i class="fas fa-exclamation-circle"></i> Student not found
                </div>
            <?php endif; ?>
        </main>
    </div>
    
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
            if (window.innerWidth <= 768 && sidebar.classList.contains('active') && !sidebar.contains(event.target) && !sidebarToggle.contains(event.target)) {
                sidebar.classList.remove('active');
            }
        });
        
        // Handle window resize
        window.addEventListener('resize', function() {
            if (window.innerWidth > 768 && sidebar.classList.contains('active')) {
                sidebar.classList.remove('active');
            }
        });
    </script>
</body>
</html>