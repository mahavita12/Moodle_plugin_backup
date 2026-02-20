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
 * External API for getting AI feedback.
 *
 * @package    local_essaysmaster
 * @copyright  2024 Essays Master Development Team
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_essaysmaster\external;

use external_api;
use external_function_parameters;
use external_value;
use external_single_structure;
use external_multiple_structure;
use invalid_parameter_exception;
use context_module;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/externallib.php');

/**
 * External API for Essays Master feedback generation.
 */
class get_feedback extends external_api {

    /**
     * Returns description of method parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters() {
        return new external_function_parameters([
            'sessionid' => new external_value(PARAM_INT, 'Session ID'),
            'essaytext' => new external_value(PARAM_RAW, 'Essay text'),
            'level' => new external_value(PARAM_INT, 'Feedback level'),
            'refresh' => new external_value(PARAM_BOOL, 'Force refresh', VALUE_DEFAULT, false),
        ]);
    }

    /**
     * Generate AI feedback for essay.
     *
     * @param int $sessionid Session ID
     * @param string $essaytext Essay text
     * @param int $level Feedback level
     * @param bool $refresh Force refresh
     * @return array Feedback data
     */
    public static function execute($sessionid, $essaytext, $level, $refresh = false) {
        global $DB, $USER;

        // Validate parameters
        $params = self::validate_parameters(self::execute_parameters(), [
            'sessionid' => $sessionid,
            'essaytext' => $essaytext,
            'level' => $level,
            'refresh' => $refresh,
        ]);

        // Get session and validate access
        $session = $DB->get_record('local_essaysmaster_sessions', ['id' => $params['sessionid']], '*', MUST_EXIST);

        if ($session->user_id != $USER->id) {
            throw new invalid_parameter_exception('Access denied to this session');
        }

        // Check capabilities
        $attempt = $DB->get_record('quiz_attempts', ['id' => $session->attempt_id], '*', MUST_EXIST);
        $quiz = $DB->get_record('quiz', ['id' => $attempt->quiz], '*', MUST_EXIST);
        $cm = get_coursemodule_from_instance('quiz', $quiz->id);
        $context = context_module::instance($cm->id);

        require_capability('local/essaysmaster:use', $context);

        try {
            // Save essay version
            $versionid = \local_essaysmaster_save_version($params['sessionid'], $params['essaytext'], $params['level']);

            // Initialize feedback engine
            $feedback_engine = new \local_essaysmaster\feedback_engine($session);

            // Check for cached feedback if not refreshing
            if (!$params['refresh']) {
                $cached = $feedback_engine->get_cached_feedback($versionid, $params['level']);
                if ($cached) {
                    return [
                        'success' => true,
                        'cached' => true,
                        'feedback_html' => $cached->feedback_html,
                        'highlighted_areas' => $cached->highlighted_areas,
                        'completion_score' => $cached->completion_score,
                        'level' => $params['level'],
                    ];
                }
            }

            // Generate new feedback
            $feedback_data = $feedback_engine->generate_level_feedback(
                $params['essaytext'],
                $params['level']
            );

            if ($feedback_data['success']) {
                // Save feedback to database
                $feedback_engine->save_feedback($versionid, $feedback_data);

                // Update progress tracking
                $progress_tracker = new \local_essaysmaster\progress_tracker($session);
                $progress_tracker->update_progress($params['level'], $feedback_data, $params['essaytext']);

                return [
                    'success' => true,
                    'cached' => false,
                    'feedback_html' => $feedback_data['feedback_html'],
                    'highlighted_areas' => $feedback_data['highlighted_areas'],
                    'completion_score' => $feedback_data['completion_score'],
                    'level' => $params['level'],
                    'response_time' => $feedback_data['response_time'],
                ];
            } else {
                return [
                    'success' => false,
                    'error' => $feedback_data['error'],
                    'level' => $params['level'],
                ];
            }

        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => 'Failed to generate feedback: ' . $e->getMessage(),
                'level' => $params['level'],
            ];
        }
    }

    /**
     * Returns description of method result value.
     *
     * @return external_single_structure
     */
    public static function execute_returns() {
        return new external_single_structure([
            'success' => new external_value(PARAM_BOOL, 'Success status'),
            'cached' => new external_value(PARAM_BOOL, 'Whether feedback was cached', VALUE_OPTIONAL),
            'feedback_html' => new external_value(PARAM_RAW, 'Feedback HTML', VALUE_OPTIONAL),
            'highlighted_areas' => new external_value(PARAM_RAW, 'Highlighted areas JSON', VALUE_OPTIONAL),
            'completion_score' => new external_value(PARAM_FLOAT, 'Completion score', VALUE_OPTIONAL),
            'level' => new external_value(PARAM_INT, 'Feedback level'),
            'response_time' => new external_value(PARAM_FLOAT, 'Response time', VALUE_OPTIONAL),
            'error' => new external_value(PARAM_TEXT, 'Error message', VALUE_OPTIONAL),
        ]);
    }
}