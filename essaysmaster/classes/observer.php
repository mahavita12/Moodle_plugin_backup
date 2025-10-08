<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Event observer for Essays Master plugin.
 *
 * @package    local_essaysmaster
 * @copyright  2024 Essays Master Development Team
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_essaysmaster;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/local/essaysmaster/lib.php');

/**
 * Class observer
 *
 * Handles Moodle events related to quiz attempts
 */
class observer {

    /**
     * Handle quiz attempt submission
     *
     * @param \mod_quiz\event\attempt_submitted $event
     */
    public static function quiz_attempt_submitted(\mod_quiz\event\attempt_submitted $event) {
        // DISABLED - using JavaScript interception instead to avoid conflicts
        // The new AMD feedback module handles all interception logic
        error_log("Essays Master: PHP submission observer disabled - JavaScript handles interception");
        return;
    }

    /**
     * Handle quiz attempt preview started
     *
     * @param \mod_quiz\event\attempt_preview_started $event
     */
    public static function quiz_attempt_preview_started(\mod_quiz\event\attempt_preview_started $event) {
        // Preview attempts should not be intercepted
        // This is mainly for logging/analytics
        error_log("Essays Master: Quiz preview started - not intercepting");
    }

    /**
     * Handle quiz attempt started - Load Essays Master feedback system
     *
     * @param \mod_quiz\event\attempt_started $event
     */
    public static function quiz_attempt_started(\mod_quiz\event\attempt_started $event) {
        global $DB, $PAGE;

        $attemptid = $event->objectid;

        try {
            // Check if this quiz has Essays Master configuration
            $attempt = $DB->get_record('quiz_attempts', ['id' => $attemptid]);
            if (!$attempt) {
                return;
            }

            $config = $DB->get_record('local_essaysmaster_config', [
                'quiz_id' => $attempt->quiz, 
                'is_enabled' => 1
            ]);

            if (!$config) {
                return;
            }

            // Check if quiz has essay questions using existing helper method
            if (!self::quiz_has_essay_questions($attempt->quiz)) {
                return;
            }

            // Load the AMD module for Essays Master feedback system
            $PAGE->requires->js_call_amd('local_essaysmaster/feedback', 'init', [
                [
                    'maxRounds' => 3, 
                    'attemptId' => $attemptid,
                    'quizId' => $attempt->quiz
                ]
            ]);

            error_log("Essays Master: Loaded feedback system for attempt {$attemptid}");

        } catch (\Exception $e) {
            error_log("Essays Master: Error during attempt start handling: " . $e->getMessage());
        }
    }

    /**
     * Check if a quiz has essay questions
     *
     * @param int $quizid Quiz ID
     * @return bool True if quiz has essay questions
     */
    private static function quiz_has_essay_questions($quizid) {
        global $DB;

        $sql = "SELECT COUNT(*)
                FROM {quiz_slots} qs
                JOIN {question_references} qr ON qr.itemid = qs.id AND qr.component = 'mod_quiz' AND qr.questionarea = 'slot'
                JOIN {question_bank_entries} qbe ON qr.questionbankentryid = qbe.id
                JOIN {question_versions} qv ON qbe.id = qv.questionbankentryid
                JOIN {question} q ON qv.questionid = q.id
                WHERE qs.quizid = ? AND q.qtype = 'essay'";

        $count = $DB->count_records_sql($sql, [$quizid]);
        return $count > 0;
    }
}
