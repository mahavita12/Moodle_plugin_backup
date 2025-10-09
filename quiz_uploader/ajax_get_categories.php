<?php
/**
 * AJAX endpoint to get question bank categories for cascading dropdowns
 * Returns direct children of a specified parent category
 * File: local/quiz_uploader/ajax_get_categories.php
 */

define('AJAX_SCRIPT', true);

require_once(__DIR__ . '/../../config.php');

require_login();
require_sesskey();

$parentid = optional_param('parentid', 0, PARAM_INT);
$level = optional_param('level', 1, PARAM_INT); // 1=System, 2=Subject, 3=Type, 4=ClassCode

header('Content-Type: application/json');

try {
    // Always work with system context for question bank categories
    $systemcontext = context_system::instance();

    // If parentid is 0, we need to find the 'top' category first
    if ($parentid == 0) {
        $top = $DB->get_record('question_categories', [
            'contextid' => $systemcontext->id,
            'parent' => 0,
            'name' => 'top'
        ]);
        if ($top) {
            $parentid = $top->id;
        }
    }

    // Get direct children of the specified parent
    $categories = $DB->get_records('question_categories', [
        'contextid' => $systemcontext->id,
        'parent' => $parentid
    ], 'sortorder, name ASC');

    $result = [];

    foreach ($categories as $cat) {
        // Skip 'top' category
        if ($cat->name === 'top') {
            continue;
        }

        // Check if this category has children
        $haschildren = $DB->record_exists('question_categories', [
            'contextid' => $systemcontext->id,
            'parent' => $cat->id
        ]);

        $result[] = [
            'id' => $cat->id,
            'name' => $cat->name,
            'parent' => $cat->parent,
            'haschildren' => $haschildren,
        ];
    }

    echo json_encode($result);

} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'error' => $e->getMessage()
    ]);
}
