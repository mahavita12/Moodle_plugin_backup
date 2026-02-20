<?php
/**
 * Local File Download Handler
 * Allows students to download their feedback files stored locally
 */

require_once(__DIR__ . '/../../config.php');

// Get the requested file
$filename = required_param('file', PARAM_FILE);

// Security: Only allow files from the feedback directory
$upload_dir = $CFG->dataroot . '/quiz_feedback_files/';
$file_path = $upload_dir . $filename;

// Verify file exists and is in the correct directory
if (!file_exists($file_path) || !is_file($file_path)) {
    print_error('File not found');
}

// Verify the file is actually in our upload directory (security check)
$real_upload_dir = realpath($upload_dir);
$real_file_path = realpath($file_path);
if (strpos($real_file_path, $real_upload_dir) !== 0) {
    print_error('Access denied');
}

// Check if user has permission to download this file
// Extract user ID from filename (assumes format: userid_username_...)
$filename_parts = explode('_', $filename);
if (count($filename_parts) >= 2) {
    $file_user_id = intval($filename_parts[0]);
    
    // Only allow the file owner or admin to download
    if (!current_user_can('manage_options') && $USER->id != $file_user_id) {
        require_login();
        if ($USER->id != $file_user_id) {
            print_error('Access denied - you can only download your own feedback files');
        }
    }
} else {
    // If we can't determine ownership, require admin access
    require_capability('local/quizdashboard:view', context_system::instance());
}

// Set headers for file download
$mime_type = 'text/html';
if (pathinfo($filename, PATHINFO_EXTENSION) === 'json') {
    $mime_type = 'application/json';
}

header('Content-Type: ' . $mime_type);
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Content-Length: ' . filesize($file_path));
header('Cache-Control: no-cache, must-revalidate');
header('Expires: 0');

// Output the file
readfile($file_path);
exit;
