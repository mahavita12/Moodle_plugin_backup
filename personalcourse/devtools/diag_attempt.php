<?php
define('CLI_SCRIPT', true);

$root = realpath(__DIR__ . '/../../../');
require_once($root . '/config.php');

global $DB;

$attemptid = isset($argv[1]) ? (int)$argv[1] : 0;
$cmid = isset($argv[2]) ? (int)$argv[2] : 0;
if (!$attemptid || !$cmid) {
    fwrite(STDERR, "Usage: php diag_attempt.php <attemptid> <cmid>\n");
    exit(1);
}

$report = [
    'attempt' => null,
    'cm' => null,
    'quiz' => null,
    'slots' => [],
    'mapping' => null,
    'pcourse' => null,
    'pc_questions' => [],
    'user_flags' => [],
    'flag_removed_logs' => [],
];

// Attempt row.
$report['attempt'] = $DB->get_record('quiz_attempts', ['id' => $attemptid]);
if (!$report['attempt']) {
    echo json_encode(['error' => 'attempt_not_found', 'attemptid' => $attemptid], JSON_PRETTY_PRINT) . "\n";
    exit(2);
}

// CM and quiz.
$report['cm'] = $DB->get_record('course_modules', ['id' => $cmid], 'id,course,instance');
if ($report['cm']) {
    $report['quiz'] = $DB->get_record('quiz', ['id' => $report['cm']->instance], 'id,course,name,sumgrades,grade,questionsperpage');
}

$quizid = $report['cm'] ? (int)$report['cm']->instance : 0;
$userid = (int)$report['attempt']->userid;

// Slots in this quiz (schema tolerant: prefer question_references join).
if ($quizid) {
    try {
        $sql = "SELECT qs.slot, qs.id, qv.questionid, qs.page, qs.maxmark\n                  FROM {quiz_slots} qs\n                  JOIN {question_references} qr\n                    ON qr.itemid = qs.id AND qr.component = 'mod_quiz' AND qr.questionarea = 'slot'\n                  JOIN {question_versions} qv\n                    ON qv.questionbankentryid = qr.questionbankentryid\n                 WHERE qs.quizid = ?\n              ORDER BY qs.slot";
        $report['slots'] = array_values($DB->get_records_sql($sql, [$quizid]));
    } catch (\Throwable $e) {
        // Fallback to legacy column if available.
        try {
            $report['slots'] = array_values($DB->get_records('quiz_slots', ['quizid' => $quizid], 'slot', 'slot,id,questionid,page,maxmark'));
        } catch (\Throwable $e2) {
            $report['slots_error'] = $e2->getMessage();
            $report['slots'] = [];
        }
    }
}

// Mapping row for this personal quiz.
$report['mapping'] = $DB->get_record('local_personalcourse_quizzes', ['quizid' => $quizid]);

// Personal course row for this user.
$report['pcourse'] = $DB->get_record('local_personalcourse_courses', ['userid' => $userid], '*');

// personalcourse_questions for this personal quiz.
if ($report['mapping']) {
    $report['pc_questions'] = array_values($DB->get_records('local_personalcourse_questions', ['personalquizid' => (int)$report['mapping']->id], 'id DESC'));
}

// User flags.
$report['user_flags'] = array_values($DB->get_records('local_questionflags', ['userid' => $userid], 'questionid', 'questionid,flagcolor,cmid,quizid'));

// Recent flag_removed logs for this cmid.
$params = ['%local_questionflags\\event\\flag_removed%', $cmid];
$sql = "SELECT timecreated, eventname, contextinstanceid, relateduserid\n          FROM {logstore_standard_log}\n         WHERE eventname LIKE ? AND contextinstanceid = ?\n      ORDER BY timecreated DESC";
$report['flag_removed_logs'] = array_values($DB->get_records_sql($sql, $params));

// Diff expected vs actual: what questions should be present (flags ∩ source quiz), if mapping has sourcequizid.
$report['expected_qids'] = [];
$report['current_qids'] = [];
$report['to_add'] = [];
$report['to_remove'] = [];

try {
    if (!empty($report['mapping']->sourcequizid)) {
        $srcquizid = (int)$report['mapping']->sourcequizid;
        $srcqids = [];
        try {
            $srcqids = $DB->get_fieldset_sql("SELECT DISTINCT qv.questionid\n                                              FROM {quiz_slots} qs\n                                              JOIN {question_references} qr ON qr.itemid = qs.id AND qr.component = 'mod_quiz' AND qr.questionarea = 'slot'\n                                              JOIN {question_versions} qv ON qv.questionbankentryid = qr.questionbankentryid\n                                             WHERE qs.quizid = ?", [$srcquizid]);
        } catch (\Throwable $j) {
            $srcqids = $DB->get_fieldset_sql("SELECT DISTINCT questionid FROM {quiz_slots} WHERE quizid = ? AND questionid IS NOT NULL", [$srcquizid]);
        }
        if ($srcqids) {
            $flags = $DB->get_fieldset_sql("SELECT DISTINCT questionid FROM {local_questionflags} WHERE userid = ?", [$userid]);
            $expect = array_values(array_intersect(array_map('intval',$srcqids), array_map('intval',$flags)));
            $report['expected_qids'] = $expect;
        }
    }
} catch (\Throwable $e) {
    $report['expected_error'] = $e->getMessage();
}

if ($quizid) {
    $curr = [];
    try {
        $curr = $DB->get_fieldset_sql("SELECT DISTINCT qv.questionid\n                                       FROM {quiz_slots} qs\n                                       JOIN {question_references} qr ON qr.itemid = qs.id AND qr.component = 'mod_quiz' AND qr.questionarea = 'slot'\n                                       JOIN {question_versions} qv ON qv.questionbankentryid = qr.questionbankentryid\n                                      WHERE qs.quizid = ?", [$quizid]);
    } catch (\Throwable $j) {
        $curr = $DB->get_fieldset_sql("SELECT DISTINCT questionid FROM {quiz_slots} WHERE quizid = ? AND questionid IS NOT NULL", [$quizid]);
    }
    $report['current_qids'] = array_map('intval', $curr ?: []);
}

if (!empty($report['expected_qids'])) {
    $report['to_add'] = array_values(array_diff($report['expected_qids'], $report['current_qids']));
    $report['to_remove'] = array_values(array_diff($report['current_qids'], $report['expected_qids']));
}

echo json_encode($report, JSON_PRETTY_PRINT) . "\n";
