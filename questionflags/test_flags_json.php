<?php
define('CLI_SCRIPT', true);
require('/home/master/applications/srfshmcmyg/public_html/config.php');

$userid = 9;
$attemptid = 1513;

echo "--- Debugging Flags for User $userid, Attempt $attemptid ---\n";

// 1. Fetch Flags
$existing_flags = $DB->get_records('local_questionflags', ['userid' => $userid]);
$user_flags = [];
foreach ($existing_flags as $flag) {
    // Mimic the overwrite logic
    $user_flags[$flag->questionid] = $flag->flagcolor;
}
echo "Total Flags Found: " . count($user_flags) . "\n";
echo "Flag for Q3034: " . ($user_flags[3034] ?? 'NULL') . "\n";
echo "Flag for Q3035: " . ($user_flags[3035] ?? 'NULL') . "\n";

// 2. Fetch Mapping
$sql = "SELECT qatt.slot, qatt.questionid
        FROM {quiz_attempts} qa
        JOIN {question_attempts} qatt ON qatt.questionusageid = qa.uniqueid
        WHERE qa.id = ?
        ORDER BY qatt.slot";
$mapping_records = $DB->get_records_sql($sql, [$attemptid]);
$question_mapping = [];
foreach ($mapping_records as $record) {
    $question_mapping[$record->slot] = $record->questionid;
}
echo "Question Mapping:\n";
print_r($question_mapping);

// 3. Simulate Client Lookup
echo "\n--- Simulation ---\n";
foreach ($question_mapping as $slot => $qid) {
    $color = $user_flags[$qid] ?? 'NONE';
    echo "Slot $slot (QID $qid) -> Flag: $color\n";
}
