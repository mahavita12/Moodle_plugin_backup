<?php
namespace local_personalcourse;

defined('MOODLE_INTERNAL') || die();

class sync_manager {
    public function queue_flag_change(int $userid, int $questionid, string $flagcolor, bool $added, int $cmid, ?int $quizid, string $origin = 'manual'): void {
        $task = new \local_personalcourse\task\flag_sync_task();
        $task->set_custom_data([
            'userid' => $userid,
            'questionid' => $questionid,
            'flagcolor' => $flagcolor,
            'added' => $added,
            'cmid' => $cmid,
            'quizid' => $quizid,
            'origin' => $origin,
        ]);
        $task->set_component('local_personalcourse');
        \core\task\manager::queue_adhoc_task($task, true);
    }
}
