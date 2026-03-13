<?php
/**
 * fix_passwords.php — run ONCE, then DELETE this file.
 * Sets correct bcrypt hashes for all demo accounts.
 */
require_once 'includes/db_connection.php';

$accounts = [
    'admin@progressmate.com'   => 'admin123',
    'student@progressmate.com' => 'student123',
    'john@student.com'         => 'password',
    'jane@student.com'         => 'password',
    'michael@student.com'      => 'password',
    'sarah@student.com'        => 'password',
    'chira@progressmate.com'   => 'password',
];

$stmt = $pdo->prepare("UPDATE users SET password = ? WHERE email = ?");
$rows = [];
foreach ($accounts as $email => $plain) {
    $stmt->execute([password_hash($plain, PASSWORD_BCRYPT), $email]);
    $rows[] = ($stmt->rowCount() ? '✅' : '⚠️ not found') . " <b>$email</b> → $plain";
}
?><!DOCTYPE html><html><head><meta charset="UTF-8">
<title>Fix Passwords</title>
<style>
  body { font-family: monospace; padding: 30px; background: #0d1117; color: #e6edf3; }
  h2   { color: #58a6ff; }
  ul   { list-style: none; padding: 0; }
  li   { padding: 5px 0; font-size: 15px; }
  .box { background: #161b22; border: 1px solid #30363d; border-radius: 8px; padding: 18px 22px; margin-top: 14px; }
  .warn { color: #f0883e; margin-top: 20px; font-size: 14px; }
</style></head><body>
<h2>ProgressMate — Password Fix</h2>
<div class="box">
  <ul><?php foreach ($rows as $r) echo "<li>$r</li>"; ?></ul>
</div>
<p class="warn">⚠️ <strong>Delete this file from your server immediately after running it.</strong></p>
</body></html>