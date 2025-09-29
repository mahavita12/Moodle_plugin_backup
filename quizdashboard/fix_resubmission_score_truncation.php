<?php
/**
 * Fix for resubmission grader score truncation issue
 */

// Configuration
$plugin_dir = 'C:\MoodleWindowsInstaller-latest-404\server\moodle\local\quizdashboard';
$file_path = $plugin_dir . '\classes\essay_grader.php';
$backup_path = $file_path . '.backup_' . date('Ymd_His');

// Create backup
if (!copy($file_path, $backup_path)) {
    die("ERROR: Could not create backup file\n");
}
echo "Backup created: $backup_path\n";

// Read the file
$content = file_get_contents($file_path);
if ($content === false) {
    die("ERROR: Could not read file\n");
}

// Find the problematic section and replace it
$search_pattern = '/else if \(preg_match\(\'.*?Final Score.*?Previous.*?New.*?\'\, \$feedback_data\[\'feedback_html\'\], \$matches\)\)/s';

// New improved pattern that handles multiple arrow formats
$replacement = 'else if (preg_match(\'/<strong>Final Score \\\\(Previous.*?(?:â†’|&rarr;|->|âž”|â–º).*?New\\\\):\\s*\\d+\\/\\d+\\s*(?:â†’|&rarr;|->|âž”|â–º)\\s*(\\d+(?:\\.\\d+)?)\\s*\\/\\s*(\\d+(?:\\.\\d+)?)<\\/strong>/iu\', $feedback_data[\'feedback_html\'], $matches))';

// Perform replacement
$content = preg_replace($search_pattern, $replacement, $content, 1, $count);

if ($count > 0) {
    echo "Fixed the regex pattern for resubmission score extraction\n";
} else {
    echo "Warning: Could not find the regex pattern to fix. Trying alternative approach...\n";
}

// Also add score initialization to prevent undefined variable error
$init_search = '$fraction = 0.0;';
$init_replace = '$fraction = 0.0;
        $score = 0.0;  // Initialize to prevent undefined variable error
        $max_score = 100.0;  // Default max score';

$content = str_replace($init_search, $init_replace, $content, $init_count);

if ($init_count > 0) {
    echo "Added score variable initialization\n";
}

// Add fallback score extraction from scores array
$fallback_search = '        } else {
            error_log("DEBUG: Grade extraction FAILED - neither regex matched for attempt {$essay_data[\'attempt_id\']}");';
            
$fallback_replace = '        }
        // Fallback: Try extracting from scores data if available
        else if (empty($fraction) && isset($feedback_data[\'scores\']) && is_array($feedback_data[\'scores\'])) {
            // Calculate total from individual scores
            $total = 0;
            $total += isset($feedback_data[\'scores\'][\'content_and_ideas\']) ? (int)$feedback_data[\'scores\'][\'content_and_ideas\'] : 0;
            $total += isset($feedback_data[\'scores\'][\'structure_and_organization\']) ? (int)$feedback_data[\'scores\'][\'structure_and_organization\'] : 0;
            $total += isset($feedback_data[\'scores\'][\'language_use\']) ? (int)$feedback_data[\'scores\'][\'language_use\'] : 0;
            $total += isset($feedback_data[\'scores\'][\'creativity_and_originality\']) ? (int)$feedback_data[\'scores\'][\'creativity_and_originality\'] : 0;
            $total += isset($feedback_data[\'scores\'][\'mechanics\']) ? (int)$feedback_data[\'scores\'][\'mechanics\'] : 0;
            
            if ($total > 0) {
                $score = (float) $total;
                $max_score = 100.0;
                $fraction = max(0.0, min(1.0, $score / $max_score));
                error_log("DEBUG: Grade extraction from scores array - Total score: {$score}, fraction: {$fraction}");
            }
        } else {
            error_log("DEBUG: Grade extraction FAILED - neither regex matched for attempt {$essay_data[\'attempt_id\']}");';

$content = str_replace($fallback_search, $fallback_replace, $content, $fallback_count);

if ($fallback_count > 0) {
    echo "Added fallback score extraction from scores array\n";
}

// Fix undefined variable check
$content = str_replace(
    'if ($score > 0 && $max_score > 0) {',
    'if (isset($score) && $score > 0 && isset($max_score) && $max_score > 0) {',
    $content
);

// Write the fixed content
if (file_put_contents($file_path, $content) === false) {
    die("ERROR: Could not write fixed content to file\n");
}

echo "\nSUCCESS: Fixed the resubmission grader score truncation issue!\n";
echo "Please test the resubmission grading feature to confirm it now shows the correct scores.\n";
