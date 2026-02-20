<?php
require_once(__DIR__ . '/../../../config.php');

require_login();
$userid = (int)$USER->id;
$courseid = required_param('courseid', PARAM_INT);
$sesskeyparam = optional_param('sesskey', '', PARAM_RAW);
if (!confirm_sesskey($sesskeyparam)) {
    header('Content-Type: application/json');
    echo json_encode(['items' => []]);
    exit;
}

$context = context_course::instance($courseid, IGNORE_MISSING);
if (!$context) {
    header('Content-Type: application/json');
    echo json_encode(['items' => []]);
    exit;
}
require_capability('moodle/course:view', $context);

// Do not restrict to the personal course owner; allow any user with course:view to see counts.

$moduleidquiz = (int)$DB->get_field('modules', 'id', ['name' => 'quiz']);
if ($moduleidquiz <= 0) {
    header('Content-Type: application/json');
    echo json_encode(['items' => []]);
    exit;
}

// Get all quiz CMs in this course (skip ones being deleted).
$cmrows = $DB->get_records_sql(
    'SELECT cm.id AS cmid, q.id AS quizid
       FROM {course_modules} cm
       JOIN {quiz} q ON q.id = cm.instance
      WHERE cm.course = ? AND cm.module = ? AND COALESCE(cm.deletioninprogress, 0) = 0',
    [$courseid, $moduleidquiz]
);
if (empty($cmrows)) {
    header('Content-Type: application/json');
    echo json_encode(['items' => []]);
    exit;
}

$quizids = array_map(function($r){ return (int)$r->quizid; }, array_values($cmrows));
list($insql, $inparams) = $DB->get_in_or_equal($quizids, SQL_PARAMS_QM);
$cntrows = $DB->get_records_sql(
    "SELECT qs.quizid, COUNT(1) AS cnt
       FROM {quiz_slots} qs
      WHERE qs.quizid {$insql}
   GROUP BY qs.quizid",
    $inparams
);

$counts = [];
foreach ($cntrows as $r) { $counts[(int)$r->quizid] = (int)$r->cnt; }

$items = [];
foreach ($cmrows as $r) {
    $qid = (int)$r->quizid;
    $items[] = [
        'cmid' => (int)$r->cmid,
        'quizid' => $qid,
        'count' => isset($counts[$qid]) ? (int)$counts[$qid] : 0,
    ];
}

header('Content-Type: application/json');
echo json_encode(['items' => $items]);
