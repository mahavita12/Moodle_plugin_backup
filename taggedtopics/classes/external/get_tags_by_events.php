<?php
namespace local_taggedtopics\external;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->libdir . '/externallib.php');
require_once($CFG->dirroot . '/course/lib.php');

use external_api;
use external_function_parameters;
use external_multiple_structure;
use external_single_structure;
use external_value;

class get_tags_by_events extends external_api {
    public static function execute_parameters() {
        return new external_function_parameters([
            'eventids' => new external_multiple_structure(
                new external_value(PARAM_INT, 'Calendar event id'),
                'List of calendar event ids to fetch tags for', VALUE_DEFAULT, []
            ),
        ]);
    }

    public static function execute($eventids = []) {
        global $DB, $USER;
        self::validate_parameters(self::execute_parameters(), ['eventids' => $eventids]);
        $eventids = array_values(array_unique(array_map('intval', $eventids)));
        if (empty($eventids)) {
            return ['items' => []];
        }

        list($insql, $inparams) = $DB->get_in_or_equal($eventids, SQL_PARAMS_NAMED);
        $recs = $DB->get_records_select('event', "id $insql AND modulename = :modname", $inparams + ['modname' => 'quiz'], '', 'id, courseid, instance');
        if (!$recs) {
            return ['items' => []];
        }

        $items = [];
        $bycourse = [];
        foreach ($recs as $e) {
            if (empty($e->courseid) || empty($e->instance)) { continue; }
            $bycourse[$e->courseid][] = $e;
        }

        foreach ($bycourse as $courseid => $events) {
            $modinfo = get_fast_modinfo($courseid, $USER->id);
            $instances = $modinfo->get_instances_of('quiz');
            foreach ($events as $e) {
                $instanceid = (int)$e->instance;
                if (!isset($instances[$instanceid])) { continue; }
                $cminfo = $instances[$instanceid];
                if (!$cminfo->uservisible) { continue; }

                $tag = \format_taggedtopics\activity_tags::get_tag($cminfo->id);
                if (empty($tag)) { continue; }
                $html = \format_taggedtopics\activity_tags::render_tag($tag, false);
                if (empty($html)) { continue; }

                $items[] = [
                    'eventid' => (int)$e->id,
                    'html' => $html,
                ];
            }
        }

        return ['items' => $items];
    }

    public static function execute_returns() {
        return new external_single_structure([
            'items' => new external_multiple_structure(
                new external_single_structure([
                    'eventid' => new external_value(PARAM_INT, 'Calendar event id'),
                    'html' => new external_value(PARAM_RAW, 'Rendered tag HTML'),
                ])
            )
        ]);
    }
}
