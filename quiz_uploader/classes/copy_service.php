<?php
namespace local_quiz_uploader;

defined('MOODLE_INTERNAL') || die();

class copy_service {
    public static function copy_quizzes(array $sourcecmids, int $targetcourseid, int $targetsectionid, string $preset = 'default', ?int $timeclose = null, string $activityclass = 'New'): array {
        global $DB, $CFG, $USER;
        require_once($CFG->dirroot . '/backup/util/includes/backup_includes.php');
        require_once($CFG->dirroot . '/backup/util/includes/restore_includes.php');
        require_once($CFG->dirroot . '/course/lib.php');

        $results = [];

        // Resolve section number from id.
        $sectionnumber = (int)$DB->get_field('course_sections', 'section', ['id' => $targetsectionid], \IGNORE_MISSING);
        if ($sectionnumber === null) {
            return [['success' => false, 'message' => 'Invalid target section']];
        }

        foreach ($sourcecmids as $sourcecmid) {
            $res = (object)['success' => false, 'sourcecmid' => $sourcecmid, 'message' => ''];
            try {
                // Get source quiz name.
                $src = \get_coursemodule_from_id('quiz', $sourcecmid, 0, false, \MUST_EXIST);
                $srcquiz = $DB->get_record('quiz', ['id' => $src->instance], 'id,course,name', \MUST_EXIST);

                // Collision check in target section by name.
                $collision = self::find_target_quiz_by_name($targetcourseid, $targetsectionid, $srcquiz->name);
                if ($collision) {
                    // Overwrite: delete existing module.
                    \course_delete_module($collision->cmid);
                }

                // Backup the source cm.
                $bc = new \backup_controller(\backup::TYPE_1ACTIVITY, $sourcecmid, \backup::FORMAT_MOODLE, \backup::INTERACTIVE_NO, \backup::MODE_GENERAL, $USER->id);
                $bc->execute_plan();
                $backupid = $bc->get_backupid();
                $bc->destroy();

                // Restore into target course/section.
                $rc = new \restore_controller($backupid, $targetcourseid, \backup::INTERACTIVE_NO, \backup::MODE_GENERAL, $USER->id, \backup::TARGET_EXISTING_ADDING);
                $plan = $rc->get_plan();
                if ($plan->setting_exists('section')) {
                    $plan->get_setting('section')->set_value($sectionnumber);
                }
                $rc->execute_precheck();
                $rc->execute_plan();

                // Find the restored quiz by name in target section.
                $new = self::find_target_quiz_by_name($targetcourseid, $targetsectionid, $srcquiz->name);
                if ($new) {
                    // Apply preset defaults (except timeclose left null unless provided).
                    preset_helper::apply_to_quiz($new->quizid, $preset, $timeclose, null);
                    // Reveal explicitly.
                    \set_coursemodule_visible($new->cmid, 1);
                    \rebuild_course_cache($targetcourseid, true);
                }

                $res->success = true;
                $res->message = 'Copied';
                $res->quizname = $srcquiz->name;
                $res->targetcmid = $new->cmid ?? null;
                $res->targetquizid = $new->quizid ?? null;
            } catch (\Throwable $e) {
                $res->success = false;
                $res->message = $e->getMessage();
            }
            $results[] = $res;
        }

        return $results;
    }

    public static function find_target_quiz_by_name(int $courseid, int $sectionid, string $name): ?object {
        global $DB;
        $moduleid = (int)$DB->get_field('modules', 'id', ['name' => 'quiz'], \IGNORE_MISSING);
        if (!$moduleid) { return null; }
        $sql = "SELECT cm.id as cmid, q.id as quizid
                  FROM {course_modules} cm
                  JOIN {quiz} q ON q.id = cm.instance
                 WHERE cm.course = :course AND cm.section = :section AND cm.module = :module AND q.name = :name
                 ORDER BY cm.id DESC";
        $params = ['course' => $courseid, 'section' => $sectionid, 'module' => $moduleid, 'name' => $name];
        $rec = $DB->get_record_sql($sql, $params);
        return $rec ?: null;
    }
}
