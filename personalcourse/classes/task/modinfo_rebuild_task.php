<?php
namespace local_personalcourse\task;

defined('MOODLE_INTERNAL') || die();

/**
 * Adhoc task: rebuild modinfo for a single course.
 */
class modinfo_rebuild_task extends \core\task\adhoc_task {
	public function get_component() {
		return 'local_personalcourse';
	}

	public function execute() {
		$data = (object)($this->get_custom_data() ?? []);
		$courseid = isset($data->courseid) ? (int)$data->courseid : 0;
		$reason = isset($data->reason) ? (string)$data->reason : '';
		if ($courseid <= 0) { return; }
		\local_personalcourse\modinfo_rebuilder::rebuild_now((int)$courseid, $reason);
	}
}


