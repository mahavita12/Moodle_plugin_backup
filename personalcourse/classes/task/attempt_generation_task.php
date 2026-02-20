<?php
namespace local_personalcourse\task;

defined('MOODLE_INTERNAL') || die();

class attempt_generation_task extends \core\task\adhoc_task {
    public function get_component() {
        return 'local_personalcourse';
    }

    public function execute() {
        $data = (object)($this->get_custom_data() ?? []);
        $userid = isset($data->userid) ? (int)$data->userid : 0;
        $quizid = isset($data->quizid) ? (int)$data->quizid : 0;
        $attemptid = isset($data->attemptid) ? (int)$data->attemptid : 0;
        if ($userid <= 0 || $quizid <= 0) { return; }
        try {
            $svc = new \local_personalcourse\generator_service();
            // Unified generation path; already running in adhoc context (no defer).
            $svc->generate_from_source((int)$userid, (int)$quizid, $attemptid > 0 ? (int)$attemptid : null, 'union', false);
        } catch (\Throwable $e) {
            // Retry once after a short delay; then give up silently.
            try {
                $retry = new self();
                $retry->set_component('local_personalcourse');
                $retry->set_custom_data([
                    'userid' => (int)$userid,
                    'quizid' => (int)$quizid,
                    'attemptid' => ($attemptid > 0 ? (int)$attemptid : null),
                    'cmid' => isset($data->cmid) ? (int)$data->cmid : 0,
                ]);
                $retry->set_next_run_time(time() + 120);
                \core\task\manager::queue_adhoc_task($retry, true);
            } catch (\Throwable $ee) { }
        }
    }
}
