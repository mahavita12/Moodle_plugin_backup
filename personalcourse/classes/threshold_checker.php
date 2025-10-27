<?php
namespace local_personalcourse;

defined('MOODLE_INTERNAL') || die();

class threshold_checker {
    public function decide(int $userid, int $quizid, int $attemptid): array {
        global $DB;

        $attempt = $DB->get_record('quiz_attempts', ['id' => $attemptid], '*', MUST_EXIST);
        if ((int)$attempt->userid !== $userid || (int)$attempt->quiz !== $quizid) {
            return ['action' => 'none', 'reason' => 'mismatch'];
        }

        $quiz = $DB->get_record('quiz', ['id' => $quizid], 'id,sumgrades', MUST_EXIST);
        $sumgrades = (float)($attempt->sumgrades ?? 0.0);
        $totalsum = (float)($quiz->sumgrades ?? 0.0);
        $grade = ($totalsum > 0.0) ? round(($sumgrades / $totalsum) * 100.0, 2) : 0.0;
        $n = (int)$attempt->attempt; // Attempt sequence number.

        // Did we already generate for this quiz? If yes, what attempt number?
        $gen = $DB->get_record_sql(
            "SELECT g.* FROM {local_personalcourse_generations} g
             WHERE g.userid = ? AND g.sourcequizid = ? AND g.action = ?
             ORDER BY g.timecreated ASC",
            [$userid, $quizid, 'generate']
        );

        if (!$gen) {
            // No generation yet.
            if ($n === 1 && $grade > 70.0) {
                return ['action' => 'generate', 'reason' => 'first_attempt_gt70', 'grade' => $grade, 'attempt' => $n];
            }
            if ($n === 2 && $grade >= 30.0) {
                return ['action' => 'generate', 'reason' => 'second_attempt_ge30', 'grade' => $grade, 'attempt' => $n];
            }
            return ['action' => 'none', 'reason' => 'no_initial_threshold_met', 'grade' => $grade, 'attempt' => $n];
        }

        // Already generated.
        $firstgenattempt = (int)$gen->attemptnumber;
        if ($firstgenattempt === 1) {
            // If first generation was on attempt 1 due to >70, future regenerations require >70 again.
            if ($grade > 70.0) {
                return ['action' => 'regenerate', 'reason' => 'regen_gt70_after_first_attempt_gen', 'grade' => $grade, 'attempt' => $n];
            }
            return ['action' => 'none', 'reason' => 'regen_requires_gt70', 'grade' => $grade, 'attempt' => $n];
        }

        // Otherwise, standard rule: attempt >= 3 and grade >= 70.
        if ($n >= 3 && $grade >= 70.0) {
            return ['action' => 'regenerate', 'reason' => 'regen_attempt3p_ge70', 'grade' => $grade, 'attempt' => $n];
        }
        return ['action' => 'none', 'reason' => 'regen_threshold_not_met', 'grade' => $grade, 'attempt' => $n];
    }
}
