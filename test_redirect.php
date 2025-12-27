<?php
echo "<h1>Test Redirects</h1>";

$tests = [
    'dashboard.php',
    'students/dashboard.php',
    './students/dashboard.php',
    '/1_progressmate/students/dashboard.php',
    'http://' . $_SERVER['HTTP_HOST'] . '/1_progressmate/students/dashboard.php'
];

foreach ($tests as $test_url) {
    echo "<p><a href='$test_url'>Test: $test_url</a></p>";
}

echo "<h2>File Check:</h2>";
$files_to_check = [
    'students/dashboard.php' => file_exists('students/dashboard.php'),
    'admin/admin.php' => file_exists('admin/admin.php'),
    'login.php' => file_exists('login.php'),
    'register.php' => file_exists('register.php'),
];

foreach ($files_to_check as $file => $exists) {
    echo "$file: " . ($exists ? "✅ EXISTS" : "❌ MISSING") . "<br>";
}

echo "<h2>Folder Structure:</h2>";
echo "<pre>";
system("find . -type f -name '*.php' | head -20");
echo "</pre>";
?>