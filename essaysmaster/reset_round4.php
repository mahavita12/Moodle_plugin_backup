<?php
define('CLI_SCRIPT', true);
require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/clilib.php');

$attemptid = 1121;
$round = 4;

echo "Resetting Round $round for Attempt $attemptid...\n";

// 1. Get the session
$session = $DB->get_record('local_essaysmaster_sessions', ['attempt_id' => $attemptid]);
if (!$session) {
    die("Session not found for attempt $attemptid\n");
}

echo "Current Session State: Level {$session->current_level}, Completed {$session->feedback_rounds_completed}\n";

// 2. Delete the feedback for this round
$DB->delete_records('local_essaysmaster_feedback', [
    'attempt_id' => $attemptid,
    'round_number' => $round
]);
// Also delete by version_id/level_type just in case
$DB->delete_records('local_essaysmaster_feedback', [
    'version_id' => $attemptid,
    'level_type' => "round_$round"
]);

echo "Deleted feedback records for Round $round.\n";

// 3. Update session status to "rewind"
// If we are resetting Round 4, it means we have completed Round 3.
$new_completed = $round - 1;

$update = new stdClass();
$update->id = $session->id;
$update->feedback_rounds_completed = $new_completed;
// Ensure current level is set to the round we want to redo
$update->current_level = $round; 

$DB->update_record('local_essaysmaster_sessions', $update);

echo "Updated Session State: Level $round, Completed $new_completed\n";
echo "Done. Please reload the page and try the validation again.\n";
