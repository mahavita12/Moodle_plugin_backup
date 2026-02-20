<?php
define('CLI_SCRIPT', true);
$root = realpath(__DIR__ . '/../../../');
require_once($root . '/config.php');

use local_personalcourse\quiz_builder;

global $DB, $CFG;

$cmid = isset($argv[1]) ? (int)$argv[1] : 0;
$userid = isset($argv[2]) ? (int)$argv[2] : 0;
$apply = in_array('--apply', $argv, true);

if (!$cmid) {
    fwrite(STDERR, "Usage: php reconcile_pq.php <cmid> <userid> [--apply]\n");
    exit(1);
}

$cm = $DB->get_record('course_modules', ['id' => $cmid], 'id,course,instance', IGNORE_MISSING);
if (!$cm) {
    echo json_encode(['error' => 'cm_not_found', 'cmid' => $cmid], JSON_PRETTY_PRINT) . "\n";
    exit(2);
}
$quizid = (int)$cm->instance;

if (!$userid) {
    // Infer from personal course owner.
    $pc = $DB->get_record('local_personalcourse_courses', ['courseid' => (int)$cm->course], 'id,userid');
    $userid = $pc ? (int)$pc->userid : 0;
}

$report = [
    'cmid' => $cmid,
    'quizid' => $quizid,
    'userid' => $userid,
    'mapping' => null,
    'expected_qids' => [],
    'current_qids' => [],
    'to_add' => [],
    'to_remove' => [],
    'applied' => false,
];

$report['mapping'] = $DB->get_record('local_personalcourse_quizzes', ['quizid' => $quizid]);
if (!$report['mapping']) {
    echo json_encode(['error' => 'mapping_not_found', 'cmid' => $cmid, 'quizid' => $quizid], JSON_PRETTY_PRINT) . "\n";
    exit(3);
}

// Build expected = flags ∩ source quiz questions.
$srcquizid = (int)$report['mapping']->sourcequizid;
$srcqids = [];
try {
    $srcqids = $DB->get_fieldset_sql("SELECT DISTINCT qv.questionid\n                                      FROM {quiz_slots} qs\n                                      JOIN {question_references} qr ON qr.itemid = qs.id AND qr.component = 'mod_quiz' AND qr.questionarea = 'slot'\n                                      JOIN {question_versions} qv ON qv.questionbankentryid = qr.questionbankentryid\n                                     WHERE qs.quizid = ?", [$srcquizid]);
} catch (\Throwable $e) {
    $srcqids = $DB->get_fieldset_sql("SELECT DISTINCT questionid FROM {quiz_slots} WHERE quizid = ? AND questionid IS NOT NULL", [$srcquizid]);
}
$flags = $DB->get_fieldset_sql("SELECT DISTINCT questionid FROM {local_questionflags} WHERE userid = ?", [$userid]);
$expected = array_values(array_intersect(array_map('intval',$srcqids ?: []), array_map('intval',$flags ?: [])));
$report['expected_qids'] = $expected;

// Current in PQ.
$curr = [];
try {
    $curr = $DB->get_fieldset_sql("SELECT DISTINCT qv.questionid\n                                   FROM {quiz_slots} qs\n                                   JOIN {question_references} qr ON qr.itemid = qs.id AND qr.component = 'mod_quiz' AND qr.questionarea = 'slot'\n                                   JOIN {question_versions} qv ON qv.questionbankentryid = qr.questionbankentryid\n                                  WHERE qs.quizid = ?", [$quizid]);
} catch (\Throwable $e) {
    $curr = $DB->get_fieldset_sql("SELECT DISTINCT questionid FROM {quiz_slots} WHERE quizid = ? AND questionid IS NOT NULL", [$quizid]);
}
$curr = array_map('intval', $curr ?: []);
$report['current_qids'] = $curr;

$report['to_add'] = array_values(array_diff($expected, $curr));
$report['to_remove'] = array_values(array_diff($curr, $expected));

if ($apply && (!empty($report['to_add']) || !empty($report['to_remove']))) {
    require_once($CFG->dirroot . '/local/personalcourse/classes/quiz_builder.php');
    $qb = new quiz_builder();
    // Remove first, then add.
    foreach ($report['to_remove'] as $qid) {
        $qb->remove_question($quizid, (int)$qid);
        // Clean mapping rows for this question in this PQ.
        $DB->delete_records('local_personalcourse_questions', [
            'personalquizid' => (int)$report['mapping']->id,
            'questionid' => (int)$qid,
        ]);
    }
    if (!empty($report['to_add'])) {
        $qb->add_questions($quizid, array_map('intval', $report['to_add']));
        $now = time();
        foreach ($report['to_add'] as $qid) {
            $DB->insert_record('local_personalcourse_questions', (object)[
                'personalcourseid' => (int)$report['mapping']->personalcourseid,
                'personalquizid' => (int)$report['mapping']->id,
                'questionid' => (int)$qid,
                'slotid' => null,
                'flagcolor' => 'blue',
                'source' => 'manual_flag',
                'originalposition' => null,
                'currentposition' => null,
                'timecreated' => $now,
                'timemodified' => $now,
            ]);
        }
    }
    $report['applied'] = true;
}

echo json_encode($report, JSON_PRETTY_PRINT) . "\n";
