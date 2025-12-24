<?php
namespace local_personalcourse;

defined('MOODLE_INTERNAL') || die();

class attempt_analyzer {
    /**
     * Return incorrect question IDs from a quiz attempt.
     * Uses QE2 to load usage and checks per-question fraction.
     * A question is considered incorrect if fraction is not null and < 1.0.
     */
    public function get_incorrect_questionids_from_attempt(int $attemptid): array {
        global $DB, $CFG;
        require_once($CFG->libdir . '/questionlib.php');
        require_once($CFG->dirroot . '/question/engine/lib.php');

        $attempt = $DB->get_record('quiz_attempts', ['id' => $attemptid], 'id,uniqueid', MUST_EXIST);
        $qubaid = (int)$attempt->uniqueid;
        $quba = \question_engine::load_questions_usage_by_activity($qubaid);

        $incorrect = [];
        foreach ($quba->get_attempt_iterator() as $slot => $qa) {
            $qid = (int)$qa->get_question()->id;
            $fraction = $qa->get_fraction();
            $stateobj = $qa->get_state();
            $state = method_exists($stateobj, '__toString') ? (string)$stateobj : '';
            // Consider incorrect if:
            // - fraction exists and is < 1.0 (gradedwrong/gradedpartial), OR
            // - fraction is null but the question state is finished and not gradedright (e.g., gaveup, finished without checking).
            if ($fraction !== null) {
                // Only consider completely wrong (fraction = 0) as incorrect
                if ((float)$fraction === 0.0) { $incorrect[] = $qid; }
            } else {
                if (method_exists($stateobj, 'is_finished') && $stateobj->is_finished()) {
                    if ($state !== 'gradedright') { $incorrect[] = $qid; }
                }
            }
        }
        return array_values(array_unique($incorrect));
    }
}
