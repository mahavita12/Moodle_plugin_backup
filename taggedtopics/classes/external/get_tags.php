<?php
namespace local_taggedtopics\external;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->libdir . '/externallib.php');

use external_api;
use external_function_parameters;
use external_multiple_structure;
use external_single_structure;
use external_value;
use context_module;
use context_course;

class get_tags extends external_api {
    public static function execute_parameters() {
        return new external_function_parameters([
            'cmids' => new external_multiple_structure(
                new external_value(PARAM_INT, 'Course module id'),
                'List of course module ids to fetch tags for', VALUE_DEFAULT, []
            ),
        ]);
    }

    public static function execute($cmids = []) {
        global $DB, $USER;
        self::validate_parameters(self::execute_parameters(), ['cmids' => $cmids]);
        $cmids = array_values(array_unique(array_map('intval', $cmids)));
        if (empty($cmids)) {
            return ['items' => []];
        }

        $results = [];
        list($insql, $inparams) = $DB->get_in_or_equal($cmids, SQL_PARAMS_NAMED);
        $cms = $DB->get_records_select('course_modules', "id $insql", $inparams, '', 'id, course, module, instance');
        if (!$cms) {
            return ['items' => []];
        }

        // Map module id => name to filter quiz modules only.
        $moduleids = array_unique(array_map(function($cm){return $cm->module;}, $cms));
        if (!empty($moduleids)) {
            list($minsql, $minparams) = $DB->get_in_or_equal($moduleids, SQL_PARAMS_NAMED);
            $mods = $DB->get_records_select('modules', "id $minsql", $minparams, '', 'id, name');
        } else {
            $mods = [];
        }

        foreach ($cms as $cm) {
            if (empty($mods[$cm->module]) || $mods[$cm->module]->name !== 'quiz') {
                continue; // Only tag quizzes for now.
            }

            // Visibility/capability: Only return tags if user can view this cm.
            try {
                $modinfo = get_fast_modinfo($cm->course, $USER->id);
                if (!isset($modinfo->cms[$cm->id])) {
                    continue;
                }
                $cminfo = $modinfo->cms[$cm->id];
                if (!$cminfo->uservisible) {
                    continue;
                }
            } catch (\Exception $e) {
                continue;
            }

            $tag = \format_taggedtopics\activity_tags::get_tag($cm->id);
            if (!$tag) {
                continue;
            }
            $html = \format_taggedtopics\activity_tags::render_tag($tag, true);
            if (empty($html)) {
                continue;
            }
            $results[] = [
                'cmid' => (int)$cm->id,
                'html' => $html,
            ];
        }

        return ['items' => $results];
    }

    public static function execute_returns() {
        return new external_single_structure([
            'items' => new external_multiple_structure(
                new external_single_structure([
                    'cmid' => new external_value(PARAM_INT, 'Course module id'),
                    'html' => new external_value(PARAM_RAW, 'Rendered tag HTML'),
                ])
            )
        ]);
    }
}
