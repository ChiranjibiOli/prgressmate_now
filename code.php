<?php
require_once 'includes/db_connection.php';

$updates = [
    ['email' => 'admin@progressmate.com',   'plain' => 'admin123'],
    ['email' => 'student@progressmate.com', 'plain' => 'student123'],
    ['email' => 'john@student.com',         'plain' => 'password'],
    ['email' => 'jane@student.com',         'plain' => 'password'],
    ['email' => 'michael@student.com',      'plain' => 'password'],
    ['email' => 'sarah@student.com',        'plain' => 'password'],
];

$stmt = $pdo->prepare("UPDATE users SET password = ? WHERE email = ?");

foreach ($updates as $u) {
    $hash = password_hash($u['plain'], PASSWORD_BCRYPT);
    $stmt->execute([$hash, $u['email']]);
    echo "✓ {$u['email']} → <b>{$u['plain']}</b><br>";
}

echo "<br><b>All done!</b>";
?>