<?php
namespace local_personalcourse\hook\output;

defined('MOODLE_INTERNAL') || die();

/**
 * Early redirect for hidden archived Personal Quiz links.
 *
 * If a student hits /mod/quiz/view.php?id={cmid} where that CM is a hidden
 * archived personal-quiz copy, redirect them to the latest visible
 * "Previous Attempt" CM for the same source quiz. If not found, fall back to
 * the course page. Staff/admins are not redirected.
 */
class before_http_headers_redirect {
	/**
	 * @param \core\hook\output\before_http_headers $hook
	 * @return void
	 */
	public static function callback(\core\hook\output\before_http_headers $hook): void {
		global $DB;
		// Only for mod/quiz/view.php?id={cmid}.
		$script = (string)($_SERVER['SCRIPT_NAME'] ?? '');
		if ($script === '' || substr($script, -18) !== '/mod/quiz/view.php') {
			return;
		}
		$cmid = optional_param('id', 0, PARAM_INT);
		if ($cmid <= 0) { return; }

		// Resolve CM and course; ensure it's a quiz.
		$sql = "SELECT cm.id, cm.course, cm.visible, cm.visibleoncoursepage, cm.deletioninprogress, q.id AS quizid
		          FROM {course_modules} cm
		          JOIN {modules} m ON m.id = cm.module AND m.name = 'quiz'
		          JOIN {quiz} q ON q.id = cm.instance
		         WHERE cm.id = ?";
		$cm = $DB->get_record_sql($sql, [$cmid]);
		if (!$cm) { return; }

		// Skip redirect for staff/admins (allow managing hidden items).
		$coursectx = \context_course::instance((int)$cm->course);
		if (is_siteadmin() || has_capability('moodle/course:manageactivities', $coursectx)) {
			return;
		}

		// If CM is visible already, nothing to do.
		if ((int)$cm->visible === 1 && (int)$cm->visibleoncoursepage === 1 && empty($cm->deletioninprogress)) {
			return;
		}

		// Check if this CM is an archived PQ in a personal course via archives mapping.
		$arch = $DB->get_record('local_personalcourse_archives', ['archivedcmid' => (int)$cm->id], 'id, personalcourseid, sourcequizid');
		if (!$arch) { return; } // Not a managed archived PQ; let Moodle handle it.

		// Find latest archived row for this personal course + source quiz.
		$latest = $DB->get_record_sql(
			"SELECT archivedcmid
			   FROM {local_personalcourse_archives}
			  WHERE personalcourseid = ? AND sourcequizid = ?
		   ORDER BY archivedat DESC, id DESC",
			[(int)$arch->personalcourseid, (int)$arch->sourcequizid]
		);
		if (!$latest) { return; }
		$targetcmid = (int)$latest->archivedcmid;
		if ($targetcmid <= 0 || $targetcmid === (int)$cm->id) {
			// Nothing better to redirect to.
			return;
		}

		// Ensure the target is visible to students.
		$target = $DB->get_record('course_modules', ['id' => $targetcmid], 'id, course, visible, visibleoncoursepage, deletioninprogress', IGNORE_MISSING);
		if ($target && (int)$target->visible === 1 && (int)$target->visibleoncoursepage === 1 && empty($target->deletioninprogress)) {
			redirect(new \moodle_url('/mod/quiz/view.php', ['id' => $targetcmid]));
			return;
		}

		// Fallback: redirect to course page instead of showing the hidden banner.
		redirect(new \moodle_url('/course/view.php', ['id' => (int)$cm->course]));
	}
}


