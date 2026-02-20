<?php
require_once('../../config.php');

require_login();

// Check capability
$context = context_system::instance();
if (!has_capability('local/quizdashboard:view', $context)) {
    print_error('noaccess', 'local_quizdashboard');
}

// Validate sesskey
require_sesskey();

// Get filename parameter
$filename = required_param('file', PARAM_FILE);

// Validate filename format (security check)
if (!preg_match('/^quiz_attempts_export_\d{4}-\d{2}-\d{2}_\d{2}-\d{2}-\d{2}\.csv$/', $filename)) {
    print_error('Invalid filename format');
}

// Construct filepath
$filepath = $CFG->tempdir . '/' . $filename;

// Check if file exists
if (!file_exists($filepath)) {
    print_error('Export file not found. Please regenerate the export.');
}

// Check file age (delete files older than 1 hour for security)
if (filemtime($filepath) < (time() - 3600)) {
    unlink($filepath);
    print_error('Export file has expired. Please regenerate the export.');
}

// Send file to browser
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Content-Length: ' . filesize($filepath));
header('Cache-Control: no-cache, must-revalidate');
header('Expires: Sat, 26 Jul 1997 05:00:00 GMT');

// Output file contents
readfile($filepath);

// Clean up - delete the temporary file
unlink($filepath);

exit;
?>
