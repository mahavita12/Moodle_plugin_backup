<?php
namespace local_personalcourse;

defined('MOODLE_INTERNAL') || die();

class threshold_policy {
    /**
     * Decide whether a student's initial Personal Quiz may be created for a source quiz.
     * Policy: Attempt 1 must be >30% (TESTING); from Attempt 2 onward require >=40%.
     * This mirrors existing behaviour and should be used only when no mapping exists yet.
     */
    public static function allow_initial_creation(int $userid, int $quizid): bool {
        global $DB;
        if ($userid <= 0 || $quizid <= 0) { return false; }

        // Fetch attempts in order; keep semantics consistent with prior gating (no state filter).
        $attempts = $DB->get_records('quiz_attempts', [
            'quiz' => (int)$quizid,
            'userid' => (int)$userid,
        ], 'attempt ASC', 'id,attempt,sumgrades');
        if (empty($attempts)) { return false; }

        $quizrow = $DB->get_record('quiz', ['id' => (int)$quizid], 'id,sumgrades', IGNORE_MISSING);
        $totalsum = $quizrow ? (float)($quizrow->sumgrades ?? 0.0) : 0.0;

        foreach ($attempts as $a) {
            $n = (int)$a->attempt;
            $grade = ($totalsum > 0.0)
                ? (((float)($a->sumgrades ?? 0.0) / $totalsum) * 100.0)
                : 0.0;
            
            // Attempt 1: require >30% (TESTING - normally 80%)
            if ($n === 1 && $grade > 30.0) {
                return true;
            }
            // Attempt 2+: require >=40%
            if ($n >= 2 && $grade >= 40.0) {
                return true;
            }
        }
        return false;
    }
}
