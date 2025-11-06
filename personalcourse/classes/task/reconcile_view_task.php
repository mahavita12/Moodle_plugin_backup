<?php
namespace local_personalcourse\task;

defined('MOODLE_INTERNAL') || die();

class reconcile_view_task extends \core\task\adhoc_task {
    public function get_component() {
        return 'local_personalcourse';
    }

    public function execute() {
        global $DB;
        $data = (object)($this->get_custom_data() ?? []);
        $userid = isset($data->userid) ? (int)$data->userid : 0;
        $sourcequizid = isset($data->sourcequizid) ? (int)$data->sourcequizid : 0;
        if ($userid <= 0 || $sourcequizid <= 0) {
            return;
        }

        // Resolve personal course context and current personal quiz mapping if any.
        $pc = $DB->get_record('local_personalcourse_courses', ['userid' => $userid], 'id,courseid', IGNORE_MISSING);
        $pccourseid = $pc ? (int)$pc->courseid : 0;
        $pq = null;
        if ($pc) {
            $pq = $DB->get_record('local_personalcourse_quizzes', [
                'personalcourseid' => (int)$pc->id,
                'sourcequizid' => $sourcequizid,
            ], 'id, quizid');
        }

        // If there is an in-progress/overdue attempt on the personal quiz, reschedule.
        $hasinprogress = false;
        if ($pq && !empty($pq->quizid)) {
            $hasinprogress = $DB->record_exists_select('quiz_attempts',
                "quiz = ? AND userid = ? AND state IN ('inprogress','overdue')",
                [(int)$pq->quizid, (int)$userid]
            );
        }
        // Also reschedule if CM is currently being deleted.
        $deleting = false;
        if ($pq && !empty($pq->quizid) && $pccourseid) {
            $moduleidquiz = (int)$DB->get_field('modules', 'id', ['name' => 'quiz']);
            $cmrow = $DB->get_record('course_modules', ['module' => $moduleidquiz, 'instance' => (int)$pq->quizid, 'course' => $pccourseid], 'id,deletioninprogress');
            $deleting = ($cmrow && !empty($cmrow->deletioninprogress));
        }

        if ($hasinprogress || $deleting) {
            // Reschedule shortly.
            $next = new self();
            $next->set_component('local_personalcourse');
            $next->set_custom_data([
                'userid' => $userid,
                'sourcequizid' => $sourcequizid,
            ]);
            $next->set_next_run_time(time() + 120);
            \core\task\manager::queue_adhoc_task($next, true);
            return;
        }

        // Perform flags-only reconcile (non-deferred) outside request.
        try {
            $svc = new \local_personalcourse\generator_service();
            $svc->generate_from_source((int)$userid, (int)$sourcequizid, null, 'flags_only', false);
        } catch (\Throwable $e) {
            // If anything fails, reschedule once to retry.
            $retry = new self();
            $retry->set_component('local_personalcourse');
            $retry->set_custom_data([
                'userid' => $userid,
                'sourcequizid' => $sourcequizid,
            ]);
            $retry->set_next_run_time(time() + 300);
            \core\task\manager::queue_adhoc_task($retry, true);
        }
    }
}
