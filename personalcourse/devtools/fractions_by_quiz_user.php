<?php
// Usage: php fractions_by_quiz_user.php <quizid> <userid> [filter_questionid]
// Read-only diagnostic that prints per-attempt per-question fractions using the latest step per questionattempt.

define('CLI_SCRIPT', true);
$root = realpath(__DIR__ . '/../../../');
require_once($root . '/config.php');

global $DB;

$quizid = isset($argv[1]) ? (int)$argv[1] : 0;
$userid = isset($argv[2]) ? (int)$argv[2] : 0;
$fqid   = isset($argv[3]) ? (int)$argv[3] : 0;

if (!$quizid || !$userid) {
    fwrite(STDERR, "Usage: php fractions_by_quiz_user.php <quizid> <userid> [filter_questionid]\n");
    exit(1);
}

$out = [
    'input' => ['quizid'=>$quizid, 'userid'=>$userid, 'filter_questionid'=>$fqid ?: null],
    'attempts' => [],
    'rows' => [],
    'errors' => [],
];

try {
    $attempts = $DB->get_records_sql(
        "SELECT qa.id AS attemptid, qa.attempt AS attemptno, qa.uniqueid, qa.timefinish\n         FROM {quiz_attempts} qa\n         WHERE qa.quiz = ? AND qa.userid = ?\n         ORDER BY qa.attempt ASC",
        [$quizid, $userid]
    );
    $out['attempts'] = array_values($attempts ?: []);
} catch (Throwable $e) { $out['errors'][] = 'attempts: '.$e->getMessage(); }

try {
    $filter = '';
    $params = ['quizid'=>$quizid, 'userid'=>$userid];
    if ($fqid) { $filter = ' AND qatt.questionid = :fqid'; $params['fqid'] = $fqid; }

    $sql = "
        SELECT qa.id AS attemptid, qa.attempt AS attemptno, qatt.slot, qatt.questionid, qas.fraction
        FROM {quiz_attempts} qa
        JOIN {question_attempts} qatt ON qatt.questionusageid = qa.uniqueid
        JOIN (
          SELECT questionattemptid, MAX(sequencenumber) AS maxseq
          FROM {question_attempt_steps}
          GROUP BY questionattemptid
        ) laststep ON laststep.questionattemptid = qatt.id
        JOIN {question_attempt_steps} qas ON qas.questionattemptid = laststep.questionattemptid
                                         AND qas.sequencenumber = laststep.maxseq
        WHERE qa.quiz = :quizid AND qa.userid = :userid" . $filter . "
        ORDER BY qa.attempt, qatt.slot
    ";
    $rows = $DB->get_records_sql($sql, $params);
    $out['rows'] = array_values($rows ?: []);
} catch (Throwable $e) { $out['errors'][] = 'rows: '.$e->getMessage(); }

echo json_encode($out, JSON_PRETTY_PRINT), "\n";
