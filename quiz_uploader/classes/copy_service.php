<?php
namespace local_quiz_uploader;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/mod/quiz/lib.php');
require_once(__DIR__ . '/quiz_creator.php');

class copy_service {

    /**
     * Copy specific quizzes to a target course/section WITHOUT duplicating questions.
     * Uses local_quiz_uploader\quiz_creator to link existing questions.
     */
    public static function copy_quizzes(array $cmids, int $targetcourseid, int $targetsectionid, string $preset = 'default', ?string $timeclose = null, string $activityclass = 'New'): array {
        global $DB;

        $results = [];

        foreach ($cmids as $cmid) {
            $res = new \stdClass();
            $res->cmid = $cmid;
            $res->success = false;
            $res->error = '';

            try {
                $cm = get_coursemodule_from_id('quiz', $cmid, 0, false, MUST_EXIST);
                $srcquiz = $DB->get_record('quiz', ['id' => $cm->instance], '*', MUST_EXIST);

                // 1. Get Settings
                $settings = \local_quiz_uploader\quiz_creator::build_quiz_settings($preset, 0); // Count updated later if needed
                if ($timeclose) {
                    $settings->timeclose = strtotime($timeclose);
                }

                // 2. Get Questions from source
                // Check if 'questionid' column exists (Moodle < 4.0)
                $columns = $DB->get_columns('quiz_slots');
                
                if (array_key_exists('questionid', $columns)) {
                    // Legacy: Direct questionid
                    $questions = $DB->get_records_sql("
                        SELECT questionid
                        FROM {quiz_slots}
                        WHERE quizid = ? AND questionid IS NOT NULL
                        ORDER BY slot ASC",
                        [$srcquiz->id]
                    );
                    $qids = array_keys($questions);
                } else {
                    // Modern: Via question_references
                    // We need the question ID from the latest version referenced
                    $sql = "SELECT qv.questionid
                              FROM {quiz_slots} qs
                              JOIN {question_references} qr ON qr.itemid = qs.id AND qr.component = 'mod_quiz' AND qr.questionarea = 'slot'
                              JOIN {question_bank_entries} qbe ON qbe.id = qr.questionbankentryid
                              JOIN {question_versions} qv ON qv.questionbankentryid = qbe.id
                             WHERE qs.quizid = ?
                               AND (qr.version IS NULL OR qr.version = qv.version)
                               AND (qr.version IS NOT NULL OR qv.version = (
                                   SELECT MAX(v.version)
                                   FROM {question_versions} v
                                   WHERE v.questionbankentryid = qbe.id
                               ))
                          ORDER BY qs.slot ASC";
                    $questions = $DB->get_records_sql($sql, [$srcquiz->id]);
                    $qids = array_map(function($q){ return $q->questionid; }, array_values($questions));
                }

                $qcount = count($qids);
                $settings->grade = $qcount > 0 ? $qcount : 10; // Update grade based on actual count

                // 3. Create Quiz in target
                $creator_res = \local_quiz_uploader\quiz_creator::create_quiz(
                    $targetcourseid,
                    $targetsectionid,
                    $srcquiz->name,
                    $srcquiz->intro,
                    $settings
                );

                if (!$creator_res->success) {
                    throw new \Exception($creator_res->error);
                }

                $newquizid = $creator_res->quizid;

                // 4. Add Questions to new quiz
                if ($qcount > 0) {
                    $add_res = \local_quiz_uploader\quiz_creator::add_questions_to_quiz($newquizid, $qids);
                    if (!$add_res->success && !empty($add_res->errors)) {
                        $res->warnings = $add_res->errors;
                    }
                }

                $res->success = true;
                $res->newcmid = $creator_res->cmid;
                $res->name = $srcquiz->name;

            } catch (\Exception $e) {
                $res->error = $e->getMessage();
            }

            $results[] = $res;
        }

        return $results;
    }

    public static function find_target_quiz_by_name($courseid, $sectionid, $name) {
        global $DB;

        $module = $DB->get_field('modules', 'id', ['name' => 'quiz'], IGNORE_MISSING);
        if (!$module) return null;

        $sql = "SELECT cm.id
                  FROM {course_modules} cm
                  JOIN {quiz} q ON q.id = cm.instance
                 WHERE cm.course = :course
                   AND cm.section = :section
                   AND cm.module = :module
                   AND q.name = :name";

        return $DB->get_record_sql($sql, [
            'course' => $courseid,
            'section' => $sectionid,
            'module' => $module,
            'name' => $name
        ]);
    }
}
