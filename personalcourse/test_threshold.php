<?php
define('CLI_SCRIPT', true);
require('/home/master/applications/srfshmcmyg/public_html/config.php');
require_once('/home/master/applications/srfshmcmyg/public_html/local/personalcourse/classes/threshold_policy.php');

// Force reload of class if needed (though require_once handles it)
// We need to make sure we are testing the UPDATED code. 
// Since this is a CLI script running fresh, it will load the new file content.


$userid = 9;
$quizid = 229; // Correct ID for Attempt 1508 (verified from DB)

echo "Checking Threshold for User $userid, Quiz $quizid\n";
$result = \local_personalcourse\threshold_policy::allow_initial_creation($userid, $quizid);
echo "Result: " . ($result ? 'TRUE' : 'FALSE') . "\n";

// Debug the grade calculation specifically
global $DB;
$attempts = $DB->get_records('quiz_attempts', ['quiz' => $quizid, 'userid' => $userid], 'attempt ASC', 'id,attempt,sumgrades');
$quizrow = $DB->get_record('quiz', ['id' => $quizid], 'id,sumgrades');
$totalsum = $quizrow ? (float)$quizrow->sumgrades : 0.0;

echo "Found " . count($attempts) . " attempts.\n";
foreach ($attempts as $a) {
    $raw_grade = ($totalsum > 0.0) ? (((float)$a->sumgrades / $totalsum) * 100.0) : 0.0;
    $rounded = round($raw_grade, 4);
    echo "Attempt " . $a->attempt . " (ID: " . $a->id . "): Sumgrades=" . $a->sumgrades . " Raw=" . $raw_grade . " Rounded=" . $rounded . "\n";
}
