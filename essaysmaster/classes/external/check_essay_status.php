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
 * External API for checking essay status.
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
use invalid_parameter_exception;
use context_module;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/externallib.php');

/**
 * External API for checking Essays Master status.
 */
class check_essay_status extends external_api {

    /**
     * Returns description of method parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters() {
        return new external_function_parameters([
            'attemptid' => new external_value(PARAM_INT, 'Quiz attempt ID'),
            'userid' => new external_value(PARAM_INT, 'User ID'),
        ]);
    }

    /**
     * Check Essays Master status for quiz attempt.
     *
     * @param int $attemptid Quiz attempt ID
     * @param int $userid User ID
     * @return array Status data
     */
    public static function execute($attemptid, $userid) {
        global $DB, $USER;

        // Validate parameters
        $params = self::validate_parameters(self::execute_parameters(), [
            'attemptid' => $attemptid,
            'userid' => $userid,
        ]);

        // Verify user access
        if ($params['userid'] != $USER->id) {
            throw new invalid_parameter_exception('Access denied');
        }

        // Check access permissions
        if (!\local_essaysmaster_can_access_attempt($params['attemptid'], $params['userid'])) {
            throw new invalid_parameter_exception('Access denied to attempt');
        }

        try {
            // Initialize submission interceptor
            $interceptor = new \local_essaysmaster\submission_interceptor($params['attemptid']);

            // Check if interception is needed
            $result = $interceptor->intercept();

            return [
                'needsInterception' => $result['status'] === 'redirect',
                'status' => $result['status'],
                'message' => $result['message'],
                'redirectUrl' => $result['redirect_url'] ?? '',
                'incompleteCount' => $result['incomplete_count'] ?? 0,
                'jsConfig' => $result['js_config'] ?? new \stdClass(),
            ];

        } catch (\Exception $e) {
            return [
                'needsInterception' => false,
                'status' => 'error',
                'message' => 'Error checking essay status: ' . $e->getMessage(),
                'redirectUrl' => '',
                'incompleteCount' => 0,
                'jsConfig' => new \stdClass(),
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
            'needsInterception' => new external_value(PARAM_BOOL, 'Whether interception is needed'),
            'status' => new external_value(PARAM_TEXT, 'Status message'),
            'message' => new external_value(PARAM_TEXT, 'Detailed message'),
            'redirectUrl' => new external_value(PARAM_URL, 'Redirect URL if needed'),
            'incompleteCount' => new external_value(PARAM_INT, 'Number of incomplete questions'),
            'jsConfig' => new external_value(PARAM_RAW, 'JavaScript configuration'),
        ]);
    }
}