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
            'reason' => new external_value(PARAM_TEXT, 'Reason for flag', VALUE_DEFAULT, null),
        ]);
    }

    public static function execute($questionid, $flagcolor, $isflagged, $cmid, $reason = null) {
        global $DB, $USER;

        $params = self::validate_parameters(self::execute_parameters(), [
            'questionid' => $questionid,
            'flagcolor' => $flagcolor,
            'isflagged' => $isflagged,
            'cmid' => $cmid,
            'reason' => $reason,
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

        // Resolve quiz from cmid (for attribution)
        $cm = get_coursemodule_from_id('quiz', $params['cmid']);
        $quizid = $cm ? (int)$cm->instance : null;

        $qbeid = $DB->get_field('question_versions', 'questionbankentryid', ['questionid' => (int)$params['questionid']], IGNORE_MISSING);
        $siblings = [];
        if (!empty($qbeid)) {
            $siblings = $DB->get_fieldset_select('question_versions', 'questionid', 'questionbankentryid = ?', [(int)$qbeid]);
        }

        if ($params['isflagged']) {
            if (!empty($siblings)) {
                list($in, $inparams) = $DB->get_in_or_equal($siblings, SQL_PARAMS_QM);
                $DB->delete_records_select('local_questionflags', 'userid = ? AND questionid ' . $in, array_merge([(int)$USER->id], $inparams));
            } else {
                $DB->delete_records('local_questionflags', [
                    'userid' => $USER->id,
                    'questionid' => $params['questionid']
                ]);
            }

            $record = new \stdClass();
            $record->userid = $USER->id;
            $record->questionid = $params['questionid'];
            $record->flagcolor = $params['flagcolor'];
            $record->cmid = $params['cmid'];
            $record->quizid = $quizid;
            $record->timecreated = $time;
            $record->timemodified = $time;
            $record->reason = $params['reason'];

            $insertid = $DB->insert_record('local_questionflags', $record);

            // History Log for Flagging
            $history = new \stdClass();
            $history->userid = $USER->id;
            $history->questionid = $params['questionid'];
            $history->quizid = $quizid;
            $history->cmid = $params['cmid'];
            $history->flagcolor = $params['flagcolor'];
            $history->action = 'flagged';
            $history->timecreated = $time;
            $history->reason = $params['reason'];
            try { $DB->insert_record('local_questionflags_history', $history); } catch (\Throwable $e) {}

            if (!empty($siblings)) {
                foreach ($siblings as $sid) {
                    $sid = (int)$sid;
                    if ($sid === (int)$params['questionid']) { continue; }
                    if (!$DB->record_exists('local_questionflags', ['userid' => (int)$USER->id, 'questionid' => $sid])) {
                        $r2 = clone $record;
                        $r2->questionid = $sid;
                        try { $DB->insert_record('local_questionflags', $r2, false); } catch (\Throwable $e) {}
                    }
                }
            }

            $event = \local_questionflags\event\flag_added::create([
                'context' => $context,
                'objectid' => $insertid,
                'relateduserid' => $USER->id,
                'other' => [
                    'questionid' => $params['questionid'],
                    'flagcolor' => $params['flagcolor'],
                    'cmid' => $params['cmid'],
                    'quizid' => $quizid,
                ],
            ]);
            $event->trigger();
        } else {
            if (!empty($siblings)) {
                list($in, $inparams) = $DB->get_in_or_equal($siblings, SQL_PARAMS_QM);
                $DB->delete_records_select('local_questionflags', 'userid = ? AND questionid ' . $in, array_merge([(int)$USER->id], $inparams));
            } else {
                $DB->delete_records('local_questionflags', [
                    'userid' => $USER->id,
                    'questionid' => $params['questionid']
                ]);
            }
            
            // History Log for Unflagging
            $history = new \stdClass();
            $history->userid = $USER->id;
            $history->questionid = $params['questionid'];
            // We need to fetch quizid for history if possible, or leave null. 
            // In unflagging, we might not have quizid unless passed or fetched. 
            // We have $quizid calculated earlier in valid_context check if cmid was passed.
            $history->quizid = $quizid;
            $history->cmid = $params['cmid'];
            $history->flagcolor = $params['flagcolor'];
            $history->action = 'unflagged';
            $history->timecreated = time();
            $history->reason = $params['reason'];
            try { $DB->insert_record('local_questionflags_history', $history); } catch (\Throwable $e) {}

            $event = \local_questionflags\event\flag_removed::create([
                'context' => $context,
                'objectid' => 0,
                'relateduserid' => $USER->id,
                'other' => [
                    'questionid' => $params['questionid'],
                    'flagcolor' => $params['flagcolor'],
                    'cmid' => $params['cmid'],
                    'quizid' => $quizid,
                ],
            ]);
            $event->trigger();
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