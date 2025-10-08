<?php
namespace local_questionhelper\external;

use external_api;
use external_function_parameters;
use external_value;
use external_single_structure;
use context_module;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->libdir . '/externallib.php');

class is_enabled_for_quiz extends external_api {
    public static function execute_parameters() {
        return new external_function_parameters([
            'cmid' => new external_value(PARAM_INT, 'Course module ID (quiz)'),
        ]);
    }

    public static function execute($cmid) {
        $params = self::validate_parameters(self::execute_parameters(), compact('cmid'));

        $context = context_module::instance($params['cmid']);
        self::validate_context($context);

        require_login();

        $allowed = self::quiz_has_allowed_tags($params['cmid']);

        return [ 'allowed' => $allowed ? 1 : 0 ];
    }

    public static function execute_returns() {
        return new external_single_structure([
            'allowed' => new external_value(PARAM_BOOL, 'Whether helper is enabled for this quiz'),
        ]);
    }

    private static function quiz_has_allowed_tags(int $cmid): bool {
        global $DB;

        // Fetch tags for the course module (quiz).
        $tags = [];
        try {
            if (class_exists('core_tag_tag')) {
                $tags = \core_tag_tag::get_item_tags('core', 'course_modules', $cmid);
            }
        } catch (\Throwable $e) {
            return false;
        }

        $configured = (string) get_config('local_questionhelper', 'allowed_tags');
        if ($configured === '') { return false; }
        $mode = (string) get_config('local_questionhelper', 'allowed_tags_mode');
        $mode = $mode === 'all' ? 'all' : 'any';

        $allowedTags = array_filter(array_map(function($t){ return strtolower(trim($t)); }, explode(',', $configured)));
        if (empty($allowedTags)) { return false; }

        $quizTags = array_map(function($t){ return strtolower(trim($t->rawname ?? $t->name ?? '')); }, $tags);
        $quizTags = array_filter($quizTags, function($s){ return $s !== ''; });
        if (empty($quizTags)) { return false; }

        $matches = array_intersect($allowedTags, $quizTags);
        if ($mode === 'all') {
            return count($matches) === count($allowedTags);
        }
        return count($matches) > 0;
    }
}


