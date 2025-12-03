<?php
define('CLI_SCRIPT', true);
require_once(__DIR__ . '/../../config.php');

$attemptid = 1121;
echo "Debugging Attempt $attemptid\n";

$session = $DB->get_record('local_essaysmaster_sessions', ['attempt_id' => $attemptid]);
if (!$session) {
    die("Session not found\n");
}

echo "Session found: ID {$session->id}, Current Level {$session->current_level}, Rounds Completed {$session->feedback_rounds_completed}\n";

$current_round = $session->current_level;

if ($session->feedback_rounds_completed >= $current_round) {
    echo "Condition met: rounds_completed >= current_round\n";
    
    $sql = "SELECT * FROM {local_essaysmaster_feedback}
            WHERE (attempt_id = ? AND round_number = ?)
            OR (version_id = ? AND level_type = ?)
            ORDER BY id DESC";
    $params = [$attemptid, $current_round, $attemptid, "round_$current_round"];
    
    echo "Executing SQL: $sql\n";
    echo "Params: " . json_encode($params) . "\n";
    
    $feedback_record = $DB->get_record_sql($sql, $params);
    
    if ($feedback_record) {
        echo "Feedback record found: ID {$feedback_record->id}\n";
        echo "Feedback HTML length: " . strlen($feedback_record->feedback_html) . "\n";
        echo "--- CONTENT START ---\n";
        echo substr($feedback_record->feedback_html, 0, 1000) . "\n";
        echo "--- CONTENT END ---\n";
    } else {
        echo "NO feedback record found.\n";
    }
} else {
    echo "Condition FAILED: rounds_completed < current_round\n";
}
