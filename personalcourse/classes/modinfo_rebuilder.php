<?php
namespace local_personalcourse;

defined('MOODLE_INTERNAL') || die();

/**
 * Helper to defer and deduplicate course modinfo rebuilds.
 *
 * - Request-time code should call queue() instead of rebuilding directly.
 * - Actual rebuilds happen in an adhoc task to avoid blocking users.
 */
final class modinfo_rebuilder {
	/**
	 * Queue a rebuild for the given course (deduped).
	 *
	 * @param int $courseid
	 * @param string $reason
	 * @return void
	 */
	public static function queue(int $courseid, string $reason = ''): void {
		global $DB;
		$courseid = (int)$courseid;
		if ($courseid <= 0) { return; }

		// Allow immediate rebuild if deferral is disabled via settings.
		$defer = (int)get_config('local_personalcourse', 'defer_modinfo_rebuilds');
		if (!$defer) {
			self::rebuild_now($courseid, $reason !== '' ? $reason : 'immediate');
		 return;
		}

		// Time-based dedupe to avoid bursty queueing.
		$interval = (int)(get_config('local_personalcourse', 'modinfo_rebuild_min_interval') ?: 120);
		$lastkey = 'last_modinfo_queue_' . $courseid;
		$last = (int)(get_config('local_personalcourse', $lastkey) ?: 0);
		if ($last > 0 && (time() - $last) < $interval) {
			if (PHP_SAPI === 'cli') { @mtrace('[local_personalcourse] skip queue (interval) course '.$courseid); }
			return;
		}

		// Deduplicate by checking for an existing queued task for this course.
		$classname = '\\local_personalcourse\\task\\modinfo_rebuild_task';
		$needle = '"courseid":' . $courseid;
		$exists = $DB->record_exists_select('task_adhoc', 'classname = ? AND customdata LIKE ?', [$classname, "%$needle%"]);
		if ($exists) {
			if (PHP_SAPI === 'cli') { @mtrace('[local_personalcourse] skip queue (already queued) course '.$courseid); }
			return;
		}

		$task = new \local_personalcourse\task\modinfo_rebuild_task();
		$task->set_component('local_personalcourse');
		$task->set_custom_data([
			'courseid' => $courseid,
			'reason' => $reason,
			'queuedat' => time(),
		]);
		\core\task\manager::queue_adhoc_task($task, true);
		set_config($lastkey, (string)time(), 'local_personalcourse');
		if (PHP_SAPI === 'cli') { @mtrace('[local_personalcourse] queued modinfo rebuild for course '.$courseid . ($reason ? " ($reason)" : '')); }
	}

	/**
	 * Perform the rebuild immediately (intended for task contexts).
	 *
	 * @param int $courseid
	 * @param string $reason
	 * @return void
	 */
	public static function rebuild_now(int $courseid, string $reason = ''): void {
		global $CFG;
		$courseid = (int)$courseid;
		if ($courseid <= 0) { return; }
		require_once($CFG->dirroot . '/course/lib.php');
		try {
			// clearonly=false warms caches for the next request.
			\rebuild_course_cache($courseid, false);
			if (PHP_SAPI === 'cli') {
				@mtrace('[local_personalcourse] rebuilt modinfo for course ' . $courseid . ($reason ? " ($reason)" : ''));
			}
		} catch (\Throwable $e) {
			if (PHP_SAPI === 'cli') {
				@mtrace('[local_personalcourse] rebuild failed for course ' . $courseid . ': ' . $e->getMessage());
			}
		}
	}
}


