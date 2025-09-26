<?php
/**
 * Moodle Debug Monitor Script - Place in Moodle root directory
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('log_errors', 1);
ini_set('error_log', 'C:/MoodleWindowsInstaller-latest-404/server/apache/logs/php_debug.log');

echo "<h1>Moodle Debug Monitor</h1><pre>";

echo "=== PHP Configuration Check ===\n";
echo "PHP Version: " . phpversion() . "\n";
echo "Error Reporting: " . error_reporting() . "\n";
echo "Display Errors: " . ini_get('display_errors') . "\n";
echo "Log Errors: " . ini_get('log_errors') . "\n";
echo "Error Log: " . ini_get('error_log') . "\n";
echo "Max Execution Time: " . ini_get('max_execution_time') . "\n";
echo "Memory Limit: " . ini_get('memory_limit') . "\n\n";

echo "=== Server Environment ===\n";
echo "Server Software: " . $_SERVER['SERVER_SOFTWARE'] . "\n";
echo "Document Root: " . $_SERVER['DOCUMENT_ROOT'] . "\n\n";

echo "=== Extensions Check ===\n";
$extensions = ['mysqli', 'pdo_mysql', 'curl', 'zip', 'xml', 'gd', 'mbstring'];
foreach ($extensions as $ext) {
    echo ($extension_loaded($ext) ? "✅" : "❌") . " {$ext}\n";
}

error_log("DEBUG: Monitor executed at " . date('Y-m-d H:i:s'));
echo "\n✅ Debug logs generated\n";
echo "</pre>";
?>
