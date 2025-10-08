<?php
namespace local_questionflags\external;

use external_api;
use external_function_parameters;
use external_value;
use external_single_structure;
use context_module;
use invalid_parameter_exception;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->libdir . '/externallib.php');

class flag_question extends external_api {

    public static function execute_parameters() {
        return new external_function_parameters([
            'questionid' => new external_value(PARAM_INT, 'Question ID'),
            'flagcolor' => new external_value(PARAM_ALPHA, 'Flag color (blue or red)'),
            'isflagged' => new external_value(PARAM_BOOL, 'Is question flagged'),
            'cmid' => new external_value(PARAM_INT, 'Course module ID'),
        ]);
    }

    public static function execute($questionid, $flagcolor, $isflagged, $cmid) {
        global $DB, $USER;

        $params = self::validate_parameters(self::execute_parameters(), [
            'questionid' => $questionid,
            'flagcolor' => $flagcolor,
            'isflagged' => $isflagged,
            'cmid' => $cmid,
        ]);

        // Validate context
        $context = context_module::instance($params['cmid']);
        self::validate_context($context);

        // Check capability
        require_capability('local/questionflags:flag', $context);

        // Validate flag color
        if (!in_array($params['flagcolor'], ['blue', 'red'])) {
            throw new invalid_parameter_exception('Invalid flag color');
        }

        $time = time();

        if ($params['isflagged']) {
            // Remove any existing flag for this question first
            $DB->delete_records('local_questionflags', [
                'userid' => $USER->id,
                'questionid' => $params['questionid']
            ]);

            // Add new flag
            $record = new \stdClass();
            $record->userid = $USER->id;
            $record->questionid = $params['questionid'];
            $record->flagcolor = $params['flagcolor'];
            $record->timecreated = $time;
            $record->timemodified = $time;

            $DB->insert_record('local_questionflags', $record);
        } else {
            // Remove flag
            $DB->delete_records('local_questionflags', [
                'userid' => $USER->id,
                'questionid' => $params['questionid'],
                'flagcolor' => $params['flagcolor']
            ]);
        }

        return [
            'success' => true,
            'message' => 'Flag updated successfully'
        ];
    }

    public static function execute_returns() {
        return new external_single_structure([
            'success' => new external_value(PARAM_BOOL, 'Success status'),
            'message' => new external_value(PARAM_TEXT, 'Response message'),
        ]);
    }
}