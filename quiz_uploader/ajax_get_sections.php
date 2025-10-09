<?php
/**
 * AJAX endpoint to get sections for a course
 * File: local/quiz_uploader/ajax_get_sections.php
 */

define('AJAX_SCRIPT', true);

require_once(__DIR__ . '/../../config.php');

require_login();
require_sesskey();

$courseid = required_param('courseid', PARAM_INT);

// Validate course exists
$course = $DB->get_record('course', ['id' => $courseid], '*', MUST_EXIST);
$context = context_course::instance($courseid);

// Check if user has any course access (viewing or editing)
if (!has_capability('moodle/course:view', $context) && !has_capability('moodle/course:update', $context)) {
    header('Content-Type: application/json');
    echo json_encode(['error' => 'No access to course']);
    exit;
}

// Get sections
$sections = $DB->get_records('course_sections', ['course' => $courseid], 'section ASC');

$result = [];
foreach ($sections as $section) {
    // Skip section 0 (general section) unless it has a name
    if ($section->section == 0 && empty($section->name)) {
        continue;
    }

    $sectionname = $section->name;
    if (empty($sectionname)) {
        $sectionname = get_string('section') . ' ' . $section->section;
    }

    $result[] = [
        'id' => $section->id,
        'section' => $section->section,
        'name' => $sectionname,
    ];
}

header('Content-Type: application/json');
echo json_encode($result);
