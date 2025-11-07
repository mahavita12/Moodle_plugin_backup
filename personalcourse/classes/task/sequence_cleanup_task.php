<?php
namespace local_personalcourse\task;

defined('MOODLE_INTERNAL') || die();

class sequence_cleanup_task extends \core\task\adhoc_task {
    public function get_component() {
        return 'local_personalcourse';
    }

    public function execute() {
        global $DB;
        $data = (object)($this->get_custom_data() ?? []);
        $courseid = isset($data->courseid) ? (int)$data->courseid : 0;
        if ($courseid <= 0) {
            return;
        }

        // Build a map of valid CMs for this course (exclude deletioninprogress).
        $validcmids = [];
        $rs = $DB->get_recordset_select('course_modules', 'course = ? AND (deletioninprogress = 0 OR deletioninprogress IS NULL)', [$courseid], '', 'id');
        foreach ($rs as $r) { $validcmids[(int)$r->id] = true; }
        $rs->close();

        // Clean each section's sequence from invalid or deleting CMs.
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
                $rec = (object)['id' => (int)$sec->id, 'sequence' => $newseq];
                $DB->update_record('course_sections', $rec);
                $changed = true;
            }
        }

        // Rebuild course cache if anything changed.
        if ($changed) {
            rebuild_course_cache($courseid, true);
        }
    }
}
