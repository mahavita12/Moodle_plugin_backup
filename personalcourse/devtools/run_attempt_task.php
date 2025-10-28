<?php
define('CLI_SCRIPT', true);
$root = realpath(__DIR__ . '/../../../');
require_once($root . '/config.php');

global $DB;

$attemptid = isset($argv[1]) ? (int)$argv[1] : 0;
$cmid = isset($argv[2]) ? (int)$argv[2] : 0;
if (!$attemptid || !$cmid) {
    fwrite(STDERR, "Usage: php run_attempt_task.php <attemptid> <cmid>\n");
    exit(1);
}

$attempt = $DB->get_record('quiz_attempts', ['id' => $attemptid], 'id,quiz,userid', MUST_EXIST);
$userid = (int)$attempt->userid;
$quizid = (int)$attempt->quiz;

$task = new \local_personalcourse\task\attempt_generation_task();
$task->set_custom_data([
    'userid' => $userid,
    'quizid' => $quizid,
    'attemptid' => $attemptid,
    'cmid' => $cmid,
]);
$task->set_component('local_personalcourse');

try {
    $task->execute();
    echo json_encode(['status' => 'ok', 'attemptid' => $attemptid, 'cmid' => $cmid, 'userid' => $userid, 'quizid' => $quizid]) . "\n";
} catch (\Throwable $e) {
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]) . "\n";
    exit(2);
}
