<?php
// Read-only DB probe for attempts, slots, and flags.
// Usage: php db_probe.php <attemptid> <cmid> <userid> [focus_qid]

define('CLI_SCRIPT', true);
$root = realpath(__DIR__ . '/../../../');
require_once($root . '/config.php');

global $DB, $CFG;

$attemptid = isset($argv[1]) ? (int)$argv[1] : 0;
$cmid      = isset($argv[2]) ? (int)$argv[2] : 0;
$userid    = isset($argv[3]) ? (int)$argv[3] : 0;
$focusqid  = isset($argv[4]) ? (int)$argv[4] : 0;

$report = [
    'input' => ['attemptid'=>$attemptid,'cmid'=>$cmid,'userid'=>$userid,'focusqid'=>$focusqid],
    'attempt' => null,
    'cm' => null,
    'quizid' => null,
    'attempt_slots' => [],
    'student_flags' => [],
    'incorrect_qids' => [],
    'focusqid_flag' => null,
    'alt_attempts_for_quiz_user' => [],
    'errors' => [],
];

try {
    if ($cmid > 0) {
        $report['cm'] = $DB->get_record('course_modules', ['id'=>$cmid], 'id,course,module,instance');
        if ($report['cm']) { $report['quizid'] = (int)$report['cm']->instance; }
    }
} catch (Throwable $e) { $report['errors'][] = 'cm_fetch: '.$e->getMessage(); }

try {
    if ($attemptid > 0) {
        $report['attempt'] = $DB->get_record('quiz_attempts', ['id'=>$attemptid]);
    }
} catch (Throwable $e) { $report['errors'][] = 'attempt_fetch: '.$e->getMessage(); }

// If attempt not found, show last few attempts for this quiz+user to help pick a valid id.
if (!$report['attempt'] && $report['quizid'] && $userid) {
    try {
        $alts = $DB->get_records_sql(
            "SELECT id, quiz, userid, attempt, state, uniqueid, COALESCE(timefinish,timemodified,timecreated) AS t
               FROM {quiz_attempts}
              WHERE quiz = ? AND userid = ?
           ORDER BY id DESC LIMIT 10",
           [$report['quizid'], $userid]
        );
        $report['alt_attempts_for_quiz_user'] = array_values($alts ?: []);
    } catch (Throwable $e) { $report['errors'][] = 'alt_attempts: '.$e->getMessage(); }
}

// If attempt exists, fetch slot->questionid mapping from question_attempts.
if ($report['attempt']) {
    try {
        $qidrows = $DB->get_records_sql(
            "SELECT qatt.slot, qatt.questionid
               FROM {question_attempts} qatt
              WHERE qatt.questionusageid = ?
           ORDER BY qatt.slot",
           [(int)$report['attempt']->uniqueid]
        );
        $report['attempt_slots'] = array_values($qidrows ?: []);
    } catch (Throwable $e) { $report['errors'][] = 'slot_map: '.$e->getMessage(); }
    // Compute incorrect via attempt_analyzer for this attempt.
    try {
        require_once($root . '/local/personalcourse/classes/attempt_analyzer.php');
        $an = new \local_personalcourse\attempt_analyzer();
        $incorrect = $an->get_incorrect_questionids_from_attempt((int)$attemptid);
        $report['incorrect_qids'] = array_values(array_map('intval', $incorrect ?: []));
    } catch (Throwable $e) { $report['errors'][] = 'incorrect: '.$e->getMessage(); }
}

// Load student flags for those questionids (or all, if no attempt mapping yet).
try {
    if (!empty($report['attempt_slots'])) {
        $qids = array_map(function($r){ return (int)$r->questionid; }, $report['attempt_slots']);
        list($insql, $params) = $DB->get_in_or_equal($qids, SQL_PARAMS_QM, '', false);
        $rows = $DB->get_records_sql(
            "SELECT questionid, flagcolor, cmid, quizid, timecreated, timemodified
               FROM {local_questionflags}
              WHERE userid = ? AND questionid $insql",
            array_merge([(int)$userid], $params)
        );
        $report['student_flags'] = array_values($rows ?: []);
    } else if ($userid) {
        $rows = $DB->get_records('local_questionflags', ['userid'=>(int)$userid], 'questionid', 'questionid,flagcolor,cmid,quizid,timecreated,timemodified');
        $report['student_flags'] = array_values($rows ?: []);
    }
} catch (Throwable $e) { $report['errors'][] = 'flags_fetch: '.$e->getMessage(); }

// Focus qid summary.
if ($focusqid) {
    try {
        $row = $DB->get_record('local_questionflags', ['userid'=>(int)$userid, 'questionid'=>(int)$focusqid], 'questionid,flagcolor,cmid,quizid,timecreated,timemodified');
        $report['focusqid_flag'] = $row ?: null;
    } catch (Throwable $e) { $report['errors'][] = 'focusqid: '.$e->getMessage(); }
}

// Output JSON
echo json_encode($report, JSON_PRETTY_PRINT), "\n";
