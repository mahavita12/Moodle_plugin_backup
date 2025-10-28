<?php
define('CLI_SCRIPT', true);
$root = realpath(__DIR__ . '/../../../');
require_once($root . '/config.php');
require_once($CFG->libdir . '/questionlib.php');
require_once($CFG->dirroot . '/question/engine/lib.php');

global $DB;

$attemptid = isset($argv[1]) ? (int)$argv[1] : 0;
if (!$attemptid) {
    fwrite(STDERR, "Usage: php inspect_attempt.php <attemptid>\n");
    exit(1);
}

$attempt = $DB->get_record('quiz_attempts', ['id' => $attemptid], '*', MUST_EXIST);
$qubaid = (int)$attempt->uniqueid;
$quba = \question_engine::load_questions_usage_by_activity($qubaid);

$report = [
    'attempt' => [
        'id' => (int)$attempt->id,
        'quiz' => (int)$attempt->quiz,
        'userid' => (int)$attempt->userid,
        'sumgrades' => (float)$attempt->sumgrades,
        'state' => $attempt->state,
    ],
    'slots' => []
];

foreach ($quba->get_attempt_iterator() as $slot => $qa) {
    $qid = (int)$qa->get_question()->id;
    $fraction = $qa->get_fraction();
    $state = (string)$qa->get_state();
    $mark = $qa->get_mark();
    $report['slots'][] = [
        'slot' => (int)$slot,
        'questionid' => $qid,
        'state' => $state,
        'fraction' => is_null($fraction) ? null : (float)$fraction,
        'mark' => is_null($mark) ? null : (float)$mark,
    ];
}

echo json_encode($report, JSON_PRETTY_PRINT) . "\n";
