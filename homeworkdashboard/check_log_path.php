<?php
define('CLI_SCRIPT', true);
require(__DIR__ . '/../../config.php');
echo "Log file: " . ini_get('error_log') . "\n";
