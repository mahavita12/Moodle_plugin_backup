<?php
namespace local_quizdashboard;

defined('MOODLE_INTERNAL') || die();

/**
 * Lightweight observers to prevent orphaned quiz CMs.
 * This does not change creation logic; it only self-heals when a quiz CM
 * is created/updated without being referenced in any course section sequence.
 */
class observer {

    /**
     * Attach orphan quiz CM to a valid section and rebuild cache if needed.
     * Runs for course_module_created and course_module_updated.
     *
     * Safe by default and can be disabled by setting $CFG->quizdashboard_disable_cm_autofix = true;
     *
     * @param \core\event\course_module_created|\core\event\course_module_updated $event
     * @return void
     */
    public static function quiz_cm_autofix($event): void {
        global $DB, $CFG;

        try {
            if (!empty($CFG->quizdashboard_disable_cm_autofix)) { return; }

            $cmid = (int)$event->objectid;
            if ($cmid <= 0) { return; }

            // Load course module.
            $cm = $DB->get_record('course_modules', ['id' => $cmid], '*', IGNORE_MISSING);
            if (!$cm) { return; }

            // Only target quiz modules.
            $modname = $DB->get_field('modules', 'name', ['id' => $cm->module], IGNORE_MISSING);
            if ($modname !== 'quiz') { return; }

            $courseid = (int)$cm->course;
            if ($courseid <= 0) { return; }

            // Build set of cmids referenced in sequences for this course.
            $sections = $DB->get_records('course_sections', ['course' => $courseid], 'section ASC', 'id,section,sequence');
            $isreferenced = false;
            $firstsectionnum = null;
            foreach ($sections as $s) {
                if ($firstsectionnum === null) { $firstsectionnum = (int)$s->section; }
                if (!empty($s->sequence)) {
                    foreach (explode(',', $s->sequence) as $idstr) {
                        if ((int)trim($idstr) === $cmid) { $isreferenced = true; break 2; }
                    }
                }
            }

            if ($isreferenced) { return; }

            // Prefer the section already stored on the CM row (cm.section -> course_sections.id)
            // and map it to its section NUMBER; this preserves the creator's intended target section.
            $targetsection = null;
            if (!empty($cm->section)) {
                $secnum = $DB->get_field('course_sections', 'section', ['id' => $cm->section]);
                if ($secnum !== false && $secnum !== null) {
                    $targetsection = (int)$secnum; // section NUMBER
                }
            }
            // If unknown, choose a sensible default: 0 if it exists, else first existing, else create 1.
            if ($targetsection === null) {
                $targetsection = 0;
                $hassection0 = $DB->record_exists('course_sections', ['course' => $courseid, 'section' => 0]);
                if (!$hassection0) {
                    if ($firstsectionnum !== null) {
                        $targetsection = $firstsectionnum;
                    } else {
                        require_once($CFG->dirroot . '/course/lib.php');
                        \course_create_sections_if_missing($courseid, [1]);
                        $targetsection = 1;
                    }
                }
            }

            // Attach and rebuild cache using core APIs.
            require_once($CFG->dirroot . '/course/lib.php');
            \course_add_cm_to_section($courseid, $cmid, $targetsection);
            \rebuild_course_cache($courseid, true);
        } catch (\Throwable $e) {
            // Fail-safe: never break the page due to an observer; just log.
            debugging('quizdashboard observer error: '.$e->getMessage(), DEBUG_DEVELOPER);
        }
    }
}
