<?php
/**
 * AJAX endpoint to get course chart data for injection
 */
define('AJAX_SCRIPT', true);

require_once(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/classes/homework_manager.php');

$courseid = required_param('courseid', PARAM_INT);

require_login();

$course = $DB->get_record('course', ['id' => $courseid], '*', MUST_EXIST);
$categoryid = $course->category;

// Only allow for Category 1, 2, or 3 courses
if ($categoryid > 3) {
    echo json_encode(['success' => false, 'error' => 'Course not eligible']);
    exit;
}

// Get ALL students enrolled in this course
$context = context_course::instance($courseid);
$enrolled_users = get_enrolled_users($context, 'mod/quiz:attempt', 0, 'u.id', null, 0, 0, true);
$student_ids = array_keys($enrolled_users);

if (empty($student_ids)) {
    echo json_encode(['success' => false, 'error' => 'No students enrolled']);
    exit;
}

// Get homework data using the manager for ALL enrolled students
$manager = new \local_homeworkdashboard\homework_manager();
// Pass $courseid as 5th arg for Term Point calculation context
$rows = $manager->get_leaderboard_data($categoryid, [], true, $student_ids, $courseid);

// Filter to students who have data for this course
$chartData = [];
foreach ($rows as $row) {
    if (isset($row->courses[$courseid])) {
        $chartData[] = [
            'name' => $row->fullname,
            'live' => round(($row->points_live ?? 0) / 10, 1),
            'goal_live' => round(($row->max_live ?? 0) / 10, 1),
            'w2' => round(($row->points_2w ?? 0) / 10, 1),
            'goal_2w' => round(($row->max_2w ?? 0) / 10, 1),
            'w4' => round(($row->points_4w ?? 0) / 10, 1),
            'goal_4w' => round(($row->max_4w ?? 0) / 10, 1),
            'alltime' => round(($row->points_all ?? 0) / 10, 1),
            'goal_all' => round(($row->max_all ?? 0) / 10, 1),
            'term'     => round(($row->points_term ?? 0) / 10, 1),
            'goal_term'=> round(($row->max_term ?? 0) / 10, 1),
            'level' => max(1, ceil(round(($row->points_all ?? 0) / 10, 1) / 100)),
        ];
    }
}

// Sort by live points descending
usort($chartData, function($a, $b) {
    return $b['alltime'] <=> $a['alltime'];
});

// Fetch restart date for display
$restart_date_display = 'From Restart';
$handler = \core_customfield\handler::get_handler('core_course', 'course');
$course_custom_data = $handler->get_instance_data($courseid);
foreach ($course_custom_data as $d) {
    if ($d->get_field()->get('shortname') === 'homework_leaderboard_restart_date') {
        $ts = (int)$d->get_value();
        if ($ts > 0) {
            $restart_date_display = 'Since ' . userdate($ts, '%d %b %Y');
        }
        break;
    }
}

echo json_encode([
    'success' => true,
    'coursename' => $course->fullname,
    'restart_date_display' => $restart_date_display,
    'data' => $chartData
]);
