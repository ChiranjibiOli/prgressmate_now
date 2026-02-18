<?php
session_start();
require_once '../includes/db_connection.php';
require_once '../includes/functions.php';
checkAuth('admin');

$student_id = (int)($_GET['id'] ?? 0);
if ($student_id <= 0) {
  die("Invalid student.");
}

$stmt = $pdo->prepare("
  SELECT id, name, email, student_id, department, semester, status, points, profile_picture, created_at
  FROM users
  WHERE id=? AND role='student' AND deleted_at IS NULL
");
$stmt->execute([$student_id]);
$student = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$student) {
  die("Student not found.");
}

$goals = $pdo->prepare("
  SELECT id, title, status, progress_percentage, due_date, created_at
  FROM student_goals
  WHERE student_id=? AND deleted_at IS NULL
  ORDER BY created_at DESC
");
$goals->execute([$student_id]);
$goals = $goals->fetchAll(PDO::FETCH_ASSOC);

$ach = $pdo->prepare("
  SELECT a.title, a.points, ua.earned_at
  FROM user_achievements ua
  JOIN achievements a ON a.id=ua.achievement_id
  WHERE ua.user_id=? AND ua.deleted_at IS NULL AND a.deleted_at IS NULL
  ORDER BY ua.earned_at DESC
");
$ach->execute([$student_id]);
$achievements = $ach->fetchAll(PDO::FETCH_ASSOC);
?>
<!doctype html>
<html>

<head>
  <meta charset="utf-8">
  <title>Student Details</title>
  <style>
    body {
      font-family: Inter, Arial;
      background: #f9fafb;
      padding: 24px;
    }

    .card {
      background: #fff;
      border: 1px solid #e5e7eb;
      border-radius: 12px;
      padding: 16px;
      margin-bottom: 16px;
    }

    a {
      color: #4f46e5;
      text-decoration: none;
    }

    table {
      width: 100%;
      border-collapse: collapse;
    }

    th,
    td {
      padding: 10px;
      border-bottom: 1px solid #eee;
      text-align: left;
    }
  </style>
</head>

<body>
  <a href="students.php">← Back to Students</a>

  <div class="card">
    <h2 style="margin:0;"><?php echo htmlspecialchars($student['name']); ?></h2>
    <p style="margin:6px 0;">
      <?php echo htmlspecialchars($student['email']); ?> |
      ID: <?php echo htmlspecialchars($student['student_id'] ?? '—'); ?> |
      Dept: <?php echo htmlspecialchars($student['department'] ?? '—'); ?> |
      Sem: <?php echo htmlspecialchars($student['semester'] ?? '—'); ?>
    </p>
    <p style="margin:6px 0;"><b>Points:</b> <?php echo (int)$student['points']; ?> | <b>Status:</b> <?php echo htmlspecialchars($student['status']); ?></p>
  </div>

  <div class="card">
    <h3>Goals</h3>
    <?php if (empty($goals)): ?>
      <p>No goals.</p>
    <?php else: ?>
      <table>
        <thead>
          <tr>
            <th>Title</th>
            <th>Status</th>
            <th>Progress</th>
            <th>Due</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($goals as $g): ?>
            <tr>
              <td><?php echo htmlspecialchars($g['title']); ?></td>
              <td><?php echo htmlspecialchars($g['status']); ?></td>
              <td><?php echo (float)($g['progress_percentage'] ?? 0); ?>%</td>
              <td><?php echo $g['due_date'] ? date('M d, Y', strtotime($g['due_date'])) : '—'; ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  </div>

  <div class="card">
    <h3>Achievements</h3>
    <?php if (empty($achievements)): ?>
      <p>No achievements.</p>
    <?php else: ?>
      <table>
        <thead>
          <tr>
            <th>Title</th>
            <th>Points</th>
            <th>Earned</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($achievements as $a): ?>
            <tr>
              <td><?php echo htmlspecialchars($a['title']); ?></td>
              <td><?php echo (int)$a['points']; ?></td>
              <td><?php echo date('M d, Y', strtotime($a['earned_at'])); ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  </div>
</body>

</html>