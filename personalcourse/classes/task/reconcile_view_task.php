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

        // If there is an in-progress/overdue attempt on the personal quiz, unlock instead of rescheduling.
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

        if ($deleting) {
            // Reschedule shortly.
            $next = new self();
            $next->set_component('local_personalcourse');
            $next->set_custom_data([
                'userid' => $userid,
                'sourcequizid' => $sourcequizid,
            ]);
            $next->set_next_run_time(time() + 120);
            \core\task\manager::queue_adhoc_task($next, true);
            // Also queue a sequence cleanup for the personal course to heal stale sequences.
            if ($pccourseid) {
                $cleanup = new \local_personalcourse\task\sequence_cleanup_task();
                $cleanup->set_component('local_personalcourse');
                $cleanup->set_custom_data(['courseid' => (int)$pccourseid]);
                \core\task\manager::queue_adhoc_task($cleanup, true);
            }
            return;
        }

        // If an attempt is in progress, proactively unlock here (idempotent).
        if ($hasinprogress && $pq && !empty($pq->quizid)) {
            try {
                require_once($CFG->dirroot . '/mod/quiz/locallib.php');
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
            } catch (\Throwable $e) { /* best-effort unlock */ }
        }

        // Perform flags-only reconcile outside request. Apply changes (defer=false).
        try {
            $svc = new \local_personalcourse\generator_service();
            $svc->generate_from_source((int)$userid, (int)$sourcequizid, null, 'flags_only', false);
            // After reconcile, queue a sequence cleanup to ensure section sequences do not reference deleted CMs.
            if ($pccourseid) {
                $cleanup = new \local_personalcourse\task\sequence_cleanup_task();
                $cleanup->set_component('local_personalcourse');
                $cleanup->set_custom_data(['courseid' => (int)$pccourseid]);
                \core\task\manager::queue_adhoc_task($cleanup, true);
            }
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
