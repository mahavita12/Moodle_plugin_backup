<?php
// Cleanup local/personalcourse/lib.php specifically
$file = '/home/master/applications/srfshmcmyg/public_html/local/personalcourse/lib.php';
$content = file_get_contents($file);

$targetFunc = "function local_personalcourse_before_footer() {";
$parts = explode($targetFunc, $content);

if (count($parts) > 1) {
    // We assume this is the last function. 
    // We keep $parts[0] and append our clean stub.
    $newContent = $parts[0] . "function local_personalcourse_before_footer() {\n    return; // Disabled: Navigation moved to User Menu\n}\n";
    file_put_contents($file, $newContent);
    echo "Force cleaned local/personalcourse/lib.php\n";
} else {
    echo "Could not find target function in personalcourse/lib.php\n";
}

// Delete redundant AMD file
$amdFile = '/home/master/applications/srfshmcmyg/public_html/local/personalcourse/amd/src/nav_patch.js';
if (file_exists($amdFile)) {
    unlink($amdFile);
    echo "Deleted amd/src/nav_patch.js\n";
}
// Delete minified if exists
$minFile = '/home/master/applications/srfshmcmyg/public_html/local/personalcourse/amd/build/nav_patch.min.js';
if (file_exists($minFile)) {
    unlink($minFile);
    echo "Deleted amd/build/nav_patch.min.js\n";
}
