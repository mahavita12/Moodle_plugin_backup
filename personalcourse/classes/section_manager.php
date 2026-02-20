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

    /**
     * Sort all quiz activities in a given section by their name (ascending) and
     * rewrite the section sequence accordingly. Non-quiz activities are kept
     * in their original relative order and appended after the sorted quizzes.
     *
     * @param int $courseid Course id the section belongs to
     * @param int $sectionid ID of the course_sections row to sort
     */
    public function sort_quizzes_in_section_by_name(int $courseid, int $sectionid): void {
        global $DB, $CFG;
        require_once($CFG->dirroot . '/course/lib.php');

        $section = $DB->get_record('course_sections', ['id' => $sectionid, 'course' => $courseid], 'id,course,section,sequence', IGNORE_MISSING);
        if (!$section) {
            return;
        }
        $seq = trim((string)$section->sequence);
        if ($seq === '') { return; }
        $cmids = array_values(array_filter(array_map('intval', explode(',', $seq))));
        if (empty($cmids)) { return; }

        $moduleidquiz = (int)$DB->get_field('modules', 'id', ['name' => 'quiz']);
        if ($moduleidquiz <= 0) { return; }

        // Fetch module/instance info for all CMs in the section.
        list($insql, $inparams) = $DB->get_in_or_equal($cmids, SQL_PARAMS_QM);
        $cms = $DB->get_records_sql("SELECT id, module, instance FROM {course_modules} WHERE id {$insql}", $inparams);
        if (empty($cms)) { return; }

        $quizitems = [];
        $othercms = [];
        foreach ($cmids as $cmid) {
            if (!isset($cms[$cmid])) { continue; }
            $cm = $cms[$cmid];
            if ((int)$cm->module === $moduleidquiz) {
                $name = (string)$DB->get_field('quiz', 'name', ['id' => (int)$cm->instance], IGNORE_MISSING);
                $quizitems[] = ['cmid' => (int)$cmid, 'name' => $name];
            } else {
                $othercms[] = (int)$cmid;
            }
        }
        if (empty($quizitems)) { return; }

        usort($quizitems, function($a, $b) {
            return strcasecmp($a['name'] ?? '', $b['name'] ?? '');
        });
        $sortedquizids = array_map(function($r){ return (int)$r['cmid']; }, $quizitems);

        $newsequence = implode(',', array_merge($sortedquizids, $othercms));
        if ($newsequence !== $seq) {
            $DB->set_field('course_sections', 'sequence', $newsequence, ['id' => (int)$section->id]);
        }
    }
}
