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
 * Hook implementation for before footer HTML generation.
 *
 * @package    local_essaysmaster
 * @copyright  2024 Essays Master Development Team
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_essaysmaster\hook\output;

defined('MOODLE_INTERNAL') || die();

/**
 * Hook: runs just before the footer HTML is generated (Moodle 4.4+).
 */
class before_footer_html_generation {
    /**
     * Hook callback for before footer HTML generation.
     *
     * @param \core\hook\output\before_footer_html_generation $hook
     */
    public static function callback(\core\hook\output\before_footer_html_generation $hook): void {
        global $PAGE, $DB;
        
        error_log("🪝 Essays Master HOOK: Called on page: " . $PAGE->url->out(false));

        // Only on mod/quiz attempt pages
        if (strpos($PAGE->url->out(false), '/mod/quiz/attempt.php') === false) {
            error_log("🪝 Essays Master HOOK: Not a quiz attempt page, exiting");
            return;
        }
        
        error_log("🪝 Essays Master HOOK: On quiz attempt page, proceeding...");

        // Get attempt ID from URL
        $attemptid = optional_param('attempt', 0, PARAM_INT);
        if (!$attemptid) {
            return;
        }

        // Check if this quiz has Essays Master enabled
        $attempt = $DB->get_record('quiz_attempts', ['id' => $attemptid]);
        if (!$attempt) {
            return;
        }

        // Respect unified enablement logic using dashboard config table
        if (!\local_essaysmaster_is_quiz_enabled($attempt->quiz)) {
            return;
        }

        // Check if quiz has essay questions
        $sql = "SELECT COUNT(*)
                FROM {quiz_slots} qs
                JOIN {question_references} qr ON qr.itemid = qs.id 
                    AND qr.component = 'mod_quiz' 
                    AND qr.questionarea = 'slot'
                JOIN {question_bank_entries} qbe ON qr.questionbankentryid = qbe.id
                JOIN {question_versions} qv ON qbe.id = qv.questionbankentryid
                JOIN {question} q ON qv.questionid = q.id
                WHERE qs.quizid = ? AND q.qtype = 'essay'";

        $essay_count = $DB->count_records_sql($sql, [$attempt->quiz]);
        if ($essay_count == 0) {
            return;
        }

        // Load the AMD module
        $PAGE->requires->js_call_amd('local_essaysmaster/feedback', 'init', [
            [
                'maxRounds' => 3, 
                'attemptId' => $attemptid,
                'quizId' => $attempt->quiz
            ]
        ]);

        error_log("Essays Master: Loaded feedback system for attempt {$attemptid}");
    }
}
