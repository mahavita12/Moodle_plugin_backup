<?php
require_once('../../config.php');
require_once($CFG->dirroot . '/local/homeworkdashboard/classes/google_drive_helper.php');

// Disable buffering to see output immediately if possible (though Moodle buffers)
@apache_setenv('no-gzip', 1);
@ini_set('zlib.output_compression', 0);

echo "<pre>\n";
echo "--- Google Drive Web Debug ---\n";
echo "User: " . exec('whoami') . "\n";

$helper = new \local_homeworkdashboard\google_drive_helper();

// Reflection to access private properties
$ref = new ReflectionClass($helper);
$prop_sa = $ref->getProperty('service_account_path');
$prop_sa->setAccessible(true);
$sa_path = $prop_sa->getValue($helper);

$prop_fid = $ref->getProperty('google_folder_id');
$prop_fid->setAccessible(true);
$folder_id = $prop_fid->getValue($helper);

echo "Service Account Path: " . $sa_path . "\n";
if (file_exists($sa_path)) {
    echo "  [OK] File exists.\n";
    echo "  Readable: " . (is_readable($sa_path) ? "YES" : "NO") . "\n";
} else {
    echo "  [FAIL] File NOT found.\n";
}

echo "Folder ID: " . $folder_id . "\n";
if (!empty($folder_id)) {
    echo "  [OK] Folder ID is set.\n";
} else {
    echo "  [FAIL] Folder ID is empty.\n";
}

echo "Is Configured: " . ($helper->is_configured() ? 'YES' : 'NO') . "\n";

if ($helper->is_configured()) {
    echo "Attempting upload...\n";
    $content = "Web upload test " . date('Y-m-d H:i:s');
    $filename = "Web_Debug_" . time();
    
    // Capture error log output? Hard to do in web.
    // We'll rely on the return value.
    
    $link = $helper->upload_html_content($content, $filename);
    
    if ($link) {
        echo "  [SUCCESS] Upload successful!\n";
        echo "  Link: " . $link . "\n";
    } else {
        echo "  [FAIL] Upload failed.\n";
        echo "  Check server error logs.\n";
        
        // Try to peek at the temp dir creation
        $temp_dir = make_temp_directory('local_homeworkdashboard_reports');
        echo "  Temp Dir: " . $temp_dir . "\n";
        echo "  Temp Dir Writable: " . (is_writable($temp_dir) ? "YES" : "NO") . "\n";
    }
}

echo "</pre>";
