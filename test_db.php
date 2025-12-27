<?php
require_once 'includes/db_connection.php';
echo "Database connected successfully!";
$stmt = $pdo->query("SELECT COUNT(*) as count FROM users");
$result = $stmt->fetch();
echo "<br>Total users: " . $result['count'];
?>