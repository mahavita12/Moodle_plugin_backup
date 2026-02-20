<?php
namespace local_personalcourse\task;

defined('MOODLE_INTERNAL') || die();

class create_generation_task extends \core\task\adhoc_task {
    public function get_component() {
        return 'local_personalcourse';
    }

    public function execute() {
        $data = (object)($this->get_custom_data() ?? []);
        $userid = isset($data->userid) ? (int)$data->userid : 0;
        $sourcequizid = isset($data->sourcequizid) ? (int)$data->sourcequizid : 0;
        $attemptid = isset($data->attemptid) ? (int)$data->attemptid : null;
        if ($userid <= 0 || $sourcequizid <= 0) { return; }

        try {
            $svc = new \local_personalcourse\generator_service();
            // Run union mode generation non-deferred (we are already async in adhoc task).
            $svc->generate_from_source((int)$userid, (int)$sourcequizid, $attemptid ?: null, 'union', false);
        } catch (\Throwable $e) {
            // Retry once after a short delay.
            $retry = new self();
            $retry->set_component('local_personalcourse');
            $retry->set_custom_data([
                'userid' => (int)$userid,
                'sourcequizid' => (int)$sourcequizid,
                'attemptid' => $attemptid ?: null,
            ]);
            $retry->set_next_run_time(time() + 120);
            \core\task\manager::queue_adhoc_task($retry, true);
        }
    }
}
