<?php
namespace local_personalcourse\task;

defined('MOODLE_INTERNAL') || die();

class orchestrate_deletion_task extends \core\task\adhoc_task {
    public function get_component() { return 'local_personalcourse'; }

    public function execute() {
        global $DB, $CFG;
        require_once($CFG->dirroot . '/course/lib.php');
        $data = (object)($this->get_custom_data() ?? []);
        $courseid = isset($data->courseid) ? (int)$data->courseid : 0;
        $cmids = isset($data->cmids) && is_array($data->cmids) ? array_values(array_map('intval', $data->cmids)) : [];
        if ($courseid <= 0 || empty($cmids)) { return; }

        // 1) Pre-clean: remove target cmids from course_sections.sequence.
        $sections = $DB->get_records('course_sections', ['course' => $courseid], 'section', 'id,sequence');
        $changed = false;
        foreach ($sections as $sec) {
            $seq = trim((string)$sec->sequence);
            if ($seq === '') { continue; }
            $ids = array_filter(array_map('intval', explode(',', $seq)));
            $filtered = [];
            foreach ($ids as $id) { if (!in_array((int)$id, $cmids, true)) { $filtered[] = (int)$id; } }
            $newseq = implode(',', $filtered);
            if ($newseq !== $seq) {
                $DB->update_record('course_sections', (object)['id' => (int)$sec->id, 'sequence' => $newseq]);
                $changed = true;
            }
        }
        if ($changed) { rebuild_course_cache($courseid, true); }

        // 2) Queue core deletions by calling course_delete_module (which will mark deletioninprogress and enqueue core task).
        foreach ($cmids as $cmid) {
            try { course_delete_module((int)$cmid); } catch (\Throwable $e) { /* ignore */ }
        }
    }
}
