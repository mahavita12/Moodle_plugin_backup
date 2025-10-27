<?php
namespace local_personalcourse;

defined('MOODLE_INTERNAL') || die();

class enrollment_manager {
    private function get_or_create_manual_instance(int $courseid): ?\stdClass {
        global $DB;
        $plugin = enrol_get_plugin('manual');
        if (!$plugin) { return null; }
        $instance = $DB->get_record('enrol', ['enrol' => 'manual', 'courseid' => $courseid]);
        if (!$instance) {
            $course = $DB->get_record('course', ['id' => $courseid], 'id');
            $fields = ['status' => 0];
            $instanceid = $plugin->add_instance($course, $fields);
            $instance = $DB->get_record('enrol', ['id' => $instanceid]);
        }
        return $instance;
    }

    public function ensure_manual_instance_and_enrol_student(int $courseid, int $userid): void {
        $plugin = enrol_get_plugin('manual');
        if (!$plugin) { return; }
        $instance = $this->get_or_create_manual_instance($courseid);
        if (!$instance) { return; }

        // Find student role id.
        global $DB;
        $studentrole = $DB->get_record('role', ['shortname' => 'student'], 'id', MUST_EXIST);

        // Enrol user.
        $plugin->enrol_user($instance, $userid, (int)$studentrole->id);
    }

    /**
     * Enrol staff from the source public course into the student's personal course
     * only if they are enrolled (role assignments) in the source course.
     * Consider roles: teacher, editingteacher, manager, admin (if present as a role and assigned).
     */
    public function sync_staff_from_source_course(int $personalcourseid, int $sourcecourseid): void {
        global $DB;
        $plugin = enrol_get_plugin('manual');
        if (!$plugin) { return; }

        $instance = $this->get_or_create_manual_instance($personalcourseid);
        if (!$instance) { return; }

        $coursectx = \context_course::instance($sourcecourseid);

        // Resolve role ids for target staff roles (skip missing roles).
        $staffshortnames = ['teacher', 'editingteacher', 'manager', 'admin'];
        list($insql, $inparams) = $DB->get_in_or_equal($staffshortnames, SQL_PARAMS_QM, 'r');
        $roles = $DB->get_records_select('role', 'shortname '.$insql, $inparams, '', 'id,shortname');
        if (!$roles) { return; }
        $roleids = array_map(function($r){ return (int)$r->id; }, array_values($roles));

        // Fetch role assignments in source course context for those roles.
        list($insql2, $inparams2) = $DB->get_in_or_equal($roleids, SQL_PARAMS_QM, 'ra');
        $ras = $DB->get_records_select('role_assignments', 'contextid = ? AND roleid '.$insql2, array_merge([$coursectx->id], $inparams2), '', 'userid, roleid');

        foreach ($ras as $ra) {
            $plugin->enrol_user($instance, (int)$ra->userid, (int)$ra->roleid);
        }
    }
}
