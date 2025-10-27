<?php
namespace local_personalcourse;

defined('MOODLE_INTERNAL') || die();

class section_manager {
    /**
     * Ensure a section exists in the given course with the provided name (prefix) and return its section number.
     * If not present, creates a new section at the end and sets its name to the prefix.
     */
    public function ensure_section_by_prefix(int $courseid, string $prefix): int {
        global $DB, $CFG;
        require_once($CFG->dirroot . '/course/lib.php');

        $prefix = trim($prefix);
        if ($prefix === '') { $prefix = 'General'; }

        $existing = $DB->get_record('course_sections', ['course' => $courseid, 'name' => $prefix], 'id, section');
        if ($existing) {
            return (int)$existing->section;
        }

        // Find next section number (section numbers start at 0).
        $maxsection = $DB->get_field_sql('SELECT MAX(section) FROM {course_sections} WHERE course = ?', [$courseid]);
        $next = is_null($maxsection) ? 1 : ((int)$maxsection + 1);

        $section = course_create_section($courseid, $next, false);
        if (!empty($section->id)) {
            $DB->set_field('course_sections', 'name', $prefix, ['id' => $section->id]);
        }
        return $next;
    }
}
