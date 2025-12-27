<?php
require_once '../includes/db_connection.php';

// Update streaks
updateAllStreaks($pdo);

// Run achievement checks
awardAchievements($pdo);

// Log
file_put_contents('cron.log', date('Y-m-d H:i:s') . " - Streaks and achievements updated\n", FILE_APPEND);
?>