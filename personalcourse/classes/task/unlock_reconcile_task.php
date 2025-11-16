<?php
namespace local_personalcourse\task;

defined('MOODLE_INTERNAL') || die();

class unlock_reconcile_task extends \core\task\adhoc_task {
    public function get_component() {
        return 'local_personalcourse';
    }

    public function execute() {
        global $DB, $CFG;
        $data = (object)($this->get_custom_data() ?? []);
        $userid = isset($data->userid) ? (int)$data->userid : 0;
        $sourcequizid = isset($data->sourcequizid) ? (int)$data->sourcequizid : 0;
        if ($userid <= 0 || $sourcequizid <= 0) { return; }

        // Resolve personal course and mapped personal quiz.
        $pc = $DB->get_record('local_personalcourse_courses', ['userid' => (int)$userid], 'id,courseid');
        if (!$pc) { return; }
        $pq = $DB->get_record('local_personalcourse_quizzes', [
            'personalcourseid' => (int)$pc->id,
            'sourcequizid' => (int)$sourcequizid,
        ], 'id, quizid');
        if (!$pq || empty($pq->quizid)) { return; }

        // Delete in-progress/overdue attempts to unlock structure edits.
        try { require_once($CFG->dirroot . '/mod/quiz/locallib.php'); } catch (\Throwable $e) { /* noop */ }
        try {
            $attempts = $DB->get_records_select('quiz_attempts',
                "quiz = ? AND userid = ? AND state IN ('inprogress','overdue')",
                [(int)$pq->quizid, (int)$userid], 'id ASC');
            if (!empty($attempts)) {
                $quiz = $DB->get_record('quiz', ['id' => (int)$pq->quizid], '*', IGNORE_MISSING);
                if ($quiz) {
                    try { $cm = get_coursemodule_from_instance('quiz', (int)$pq->quizid, (int)$quiz->course, false, MUST_EXIST); if ($cm && !isset($quiz->cmid)) { $quiz->cmid = (int)$cm->id; } } catch (\Throwable $e) {}
                    foreach ($attempts as $a) { try { quiz_delete_attempt($a, $quiz); } catch (\Throwable $e) {} }
                }
            }
        } catch (\Throwable $e) { /* best effort */ }

        // Reconcile with the latest flags from the source quiz.
        try {
            $svc = new \local_personalcourse\generator_service();
            $svc->generate_from_source((int)$userid, (int)$sourcequizid, null, 'flags_only', false);
        } catch (\Throwable $e) { /* best effort */ }
    }
}
?>

