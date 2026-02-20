<?php
namespace local_homeworkdashboard\task;

defined('MOODLE_INTERNAL') || die();

class compute_homework_snapshots extends \core\task\scheduled_task {

    public function get_name() {
        return get_string('task_computehomeworksnapshots', 'local_homeworkdashboard');
    }

    public function execute() {
        $manager = new \local_homeworkdashboard\homework_manager();
        $manager->compute_due_snapshots();
    }
}
