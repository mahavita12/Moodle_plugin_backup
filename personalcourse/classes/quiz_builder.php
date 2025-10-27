<?php
namespace local_personalcourse;

defined('MOODLE_INTERNAL') || die();

use context_module;

class quiz_builder {
    public function create_quiz(int $courseid, int $sectionnumber, string $name, string $intro, ?string $settingsmode = 'default'): object {
        global $CFG;
        require_once($CFG->dirroot . '/local/quiz_uploader/classes/quiz_creator.php');

        $questioncount = 0; // Unknown at creation.
        $settings = null;
        if ($settingsmode !== null) {
            $settings = \local_quiz_uploader\quiz_creator::build_quiz_settings($settingsmode, $questioncount);
        }
        return \local_quiz_uploader\quiz_creator::create_quiz($courseid, $this->get_section_id_by_number($courseid, $sectionnumber), $name, $intro, $settings);
    }

    public function add_questions(int $quizid, array $questionids): object {
        global $CFG;
        require_once($CFG->dirroot . '/local/quiz_uploader/classes/quiz_creator.php');
        return \local_quiz_uploader\quiz_creator::add_questions_to_quiz($quizid, $questionids);
    }

    public function remove_question(int $quizid, int $questionid): void {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/mod/quiz/locallib.php');
        require_once($CFG->dirroot . '/mod/quiz/lib.php');

        // Be tolerant to stale mappings: if the quiz (or CM) no longer exists, just return.
        $quiz = $DB->get_record('quiz', ['id' => $quizid], '*', IGNORE_MISSING);
        if (!$quiz) { return; }
        $cm = get_coursemodule_from_instance('quiz', $quiz->id, $quiz->course, IGNORE_MISSING);
        if (!$cm) { return; }
        // Build quiz structure using Moodle's API for this version.
        $quizobj = \mod_quiz\quiz_settings::create_for_cmid($cm->id);
        $structure = $quizobj->get_structure();

        $removed = false;
        foreach ($structure->get_slots() as $slot) {
            // Remove by slot number when we find the matching question.
            if (isset($slot->questionid) && (int)$slot->questionid === (int)$questionid) {
                try {
                    $structure->remove_slot((int)$slot->slot);
                    $removed = true;
                } catch (\Throwable $t) {
                    // Fallback: force-remove even if attempts exist.
                    $this->force_remove_slot($quiz->id, (int)$slot->slot, (int)$slot->id);
                    $removed = true;
                }
                break;
            }
        }

        if (!$removed) {
            // Try direct quiz_slots match (legacy schema) using slot number.
            $slotnumber = $DB->get_field('quiz_slots', 'slot', [
                'quizid' => (int)$quizid,
                'questionid' => (int)$questionid,
            ], IGNORE_MISSING);
            if ($slotnumber) {
                try {
                    $structure->remove_slot((int)$slotnumber);
                } catch (\Throwable $t) {
                    // Fetch slot id to support reference cleanup.
                    $slotid = $DB->get_field('quiz_slots', 'id', ['quizid' => (int)$quizid, 'slot' => (int)$slotnumber], IGNORE_MISSING);
                    $this->force_remove_slot((int)$quizid, (int)$slotnumber, (int)($slotid ?: 0));
                }
                $removed = true;
            }
        }

        if (!$removed) {
            // Robust path for question_references-based quizzes.
            $slotnumbers = $DB->get_fieldset_sql(
                "SELECT qs.slot
                   FROM {quiz_slots} qs
                   JOIN {question_references} qr
                     ON qr.itemid = qs.id AND qr.component = 'mod_quiz' AND qr.questionarea = 'slot'
                   JOIN {question_versions} qv
                     ON qv.questionbankentryid = qr.questionbankentryid
                  WHERE qs.quizid = ? AND qv.questionid = ?",
                [(int)$quizid, (int)$questionid]
            );
            foreach ($slotnumbers as $snum) {
                try {
                    $structure->remove_slot((int)$snum);
                } catch (\Throwable $t) {
                    $slotid = $DB->get_field('quiz_slots', 'id', ['quizid' => (int)$quizid, 'slot' => (int)$snum], IGNORE_MISSING);
                    $this->force_remove_slot((int)$quizid, (int)$snum, (int)($slotid ?: 0));
                }
                $removed = true; // Remove all matches just in case of duplicates.
            }
        }

        if ($removed) {
            quiz_update_sumgrades($quiz);
        }
    }

    private function force_remove_slot(int $quizid, int $slotnumber, int $slotid = 0): void {
        global $DB, $CFG;
        // Direct DB removal mirroring key parts of mod_quiz\structure::remove_slot but without edit gating.
        $slot = $DB->get_record('quiz_slots', ['quizid' => $quizid, 'slot' => $slotnumber]);
        if (!$slot) { return; }
        if (empty($slotid)) { $slotid = (int)$slot->id; }

        $trans = $DB->start_delegated_transaction();
        $questionreference = $DB->get_record('question_references', ['component' => 'mod_quiz', 'questionarea' => 'slot', 'itemid' => $slotid]);
        if ($questionreference) {
            $DB->delete_records('question_references', ['id' => $questionreference->id]);
        }
        $questionsetreference = $DB->get_record('question_set_references', ['component' => 'mod_quiz', 'questionarea' => 'slot', 'itemid' => $slotid]);
        if ($questionsetreference) {
            $DB->delete_records('question_set_references', ['id' => $questionsetreference->id, 'component' => 'mod_quiz', 'questionarea' => 'slot']);
        }
        $DB->delete_records('quiz_slots', ['id' => $slotid]);
        $maxslot = (int)$DB->get_field_sql('SELECT MAX(slot) FROM {quiz_slots} WHERE quizid = ?', [$quizid]);
        for ($i = $slotnumber + 1; $i <= $maxslot; $i++) {
            $DB->set_field('quiz_slots', 'slot', $i - 1, ['quizid' => $quizid, 'slot' => $i]);
        }
        // Reset sections to a single section starting at slot 1 to guarantee consistency.
        $DB->delete_records('quiz_sections', ['quizid' => $quizid]);
        $remain = (int)$DB->count_records('quiz_slots', ['quizid' => $quizid]);
        if ($remain > 0) {
            $DB->insert_record('quiz_sections', (object)[
                'quizid' => $quizid,
                'firstslot' => 1,
                'heading' => null,
                'shufflequestions' => 0,
            ]);
        }
        // Repaginate to normalise pages after removal (1 question per page preserves existing look).
        require_once($CFG->dirroot . '/mod/quiz/locallib.php');
        quiz_repaginate_questions($quizid, 1);
        $trans->allow_commit();
    }

    private function get_section_id_by_number(int $courseid, int $sectionnumber): int {
        global $DB;
        $section = $DB->get_record('course_sections', ['course' => $courseid, 'section' => $sectionnumber], 'id');
        if ($section) { return (int)$section->id; }
        // Default to section 0 if not found.
        $section = $DB->get_record('course_sections', ['course' => $courseid, 'section' => 0], 'id', MUST_EXIST);
        return (int)$section->id;
    }
}
