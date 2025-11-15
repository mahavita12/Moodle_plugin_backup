<?php
namespace local_personalcourse\task;

defined('MOODLE_INTERNAL') || die();

class sequence_cleanup_scheduled_task extends \core\task\scheduled_task {
    public function get_name() {
        return get_string('pluginname', 'local_personalcourse') . ' sequence cleanup';
    }

    public function execute() {
        global $DB;
        $limitpcourses = (int)get_config('local_personalcourse', 'limit_cleanup_to_personalcourses');
        if ($limitpcourses) {
            // Scan only personal-course courses to reduce load.
            $candidates = $DB->get_records_sql(
                "SELECT DISTINCT cs.course
                   FROM {course_sections} cs
                   JOIN {local_personalcourse_courses} pc ON pc.courseid = cs.course
                  WHERE cs.sequence IS NOT NULL AND cs.sequence <> ''"
            );
        } else {
            // Legacy broad scan (kept for safety if admin disables the scope limit).
            $candidates = $DB->get_records_sql(
                "SELECT DISTINCT cs.course
                   FROM {course_sections} cs
                   JOIN {course_modules} cm ON cm.course = cs.course
                  WHERE cs.sequence IS NOT NULL AND cs.sequence <> ''"
            );
        }
        foreach ($candidates as $row) {
            $courseid = (int)$row->course;
            $this->cleanup_course($courseid);
        }
    }

    private function cleanup_course(int $courseid): void {
        global $DB;
        if ($courseid <= 0) { return; }
        // Map valid CMs (exclude deletioninprogress and ensure existence).
        $validcmids = [];
        $rs = $DB->get_recordset_select('course_modules', 'course = ? AND (deletioninprogress = 0 OR deletioninprogress IS NULL)', [$courseid], '', 'id');
        foreach ($rs as $r) { $validcmids[(int)$r->id] = true; }
        $rs->close();
        $sections = $DB->get_records('course_sections', ['course' => $courseid], 'section', 'id,sequence');
        $changed = false;
        foreach ($sections as $sec) {
            $seq = trim((string)$sec->sequence);
            if ($seq === '') { continue; }
            $ids = array_filter(array_map('intval', explode(',', $seq)));
            $filtered = [];
            foreach ($ids as $cmid) {
                if (isset($validcmids[$cmid])) { $filtered[] = $cmid; }
            }
            $newseq = implode(',', $filtered);
            if ($newseq !== $seq) {
                $DB->update_record('course_sections', (object)['id' => (int)$sec->id, 'sequence' => $newseq]);
                $changed = true;
            }
        }
        if ($changed) {
            rebuild_course_cache($courseid, true);
        }
    }
}
