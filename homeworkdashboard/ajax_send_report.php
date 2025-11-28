<?php
define('AJAX_SCRIPT', true);

require_once('../../config.php');
require_once($CFG->dirroot . '/local/homeworkdashboard/classes/homework_manager.php');

$userid = required_param('userid', PARAM_INT);
$timeclose = required_param('timeclose', PARAM_INT); // Due Date 1 timestamp

require_login();
$context = context_system::instance();
require_capability('local/homeworkdashboard:view', $context);

$manager = new \local_homeworkdashboard\homework_manager();

// Check if report already exists
$existing = $DB->get_record('local_homework_reports', ['userid' => $userid, 'timeclose' => $timeclose]);
if ($existing) {
    echo json_encode(['status' => 'success', 'message' => 'Report already exists']);
    die;
}

// Fetch Data
// 1. User Info & Parents
$user = $DB->get_record('user', ['id' => $userid], '*', MUST_EXIST);
$parents = $manager->get_users_parent_info([$userid]);
$pinfo = $parents[$userid] ?? null;

// 2. Activities 1 (Due Date 1)
// We need to fetch the activities for this specific user and deadline.
// We can reuse get_snapshot_homework_rows but filter for this user and date?
// Or just use get_quizzes_for_deadline if we know the courses?
// Let's use get_snapshot_homework_rows to get the exact status/score info if needed, 
// but for the report we just need the list of activities and their status.
// Actually, the report should probably show the *snapshot* data (score, status) for Activities 1.
// For Activities 2, it's just a list of upcoming tasks.

// Let's get the snapshot row for this user and date.
$rows = $manager->get_snapshot_homework_rows(
    0, [], 0, [], [$userid], '', '', '', '', null, 'timeclose', 'DESC', false, [$timeclose]
);

if (empty($rows)) {
    echo json_encode(['status' => 'error', 'message' => 'No data found']);
    die;
}

$row = $rows[0]; // Should be one row per user per date

// 3. Activities 2 (Due Date 2)
$activities2 = [];
if (!empty($row->next_due_date)) {
    // We need course IDs. The row has courseid, but if it's grouped...
    // get_snapshot_homework_rows returns individual rows per quiz/course.
    // We need to group them like index.php does.
    // Actually, let's just fetch all rows for this user and date, then group.
    // But $rows above is already a list of all quiz attempts for this user on this date.
    
    // Grouping logic similar to index.php
    $courses = [];
    $activities1 = [];
    $next_due_date = 0;
    $next_due_date_courseid = 0;

    foreach ($rows as $r) {
        $courses[$r->courseid] = $r->coursename;
        $activities1[] = (object)[
            'name' => $r->quizname,
            'classification' => $r->classification ?? '',
            'category' => $r->categoryname
        ];
        
        // Capture Next Due Date (Category 1 priority)
        if (strcasecmp($r->categoryname, 'Category 1') === 0 && !empty($r->next_due_date)) {
            if ($next_due_date == 0 || $r->next_due_date < $next_due_date) {
                $next_due_date = $r->next_due_date;
                $next_due_date_courseid = $r->courseid;
            }
        }
    }
    
    if ($next_due_date > 0) {
        $courseids = array_keys($courses);
        $activities2 = $manager->get_quizzes_for_deadline($courseids, $next_due_date);
    }
} else {
    $activities1 = [];
    foreach ($rows as $r) {
         $activities1[] = (object)[
            'name' => $r->quizname,
            'classification' => $r->classification ?? '',
            'category' => $r->categoryname
        ];
    }
}

// Generate HTML
$html = '<h2>Homework Report</h2>';
$html .= '<p><strong>Student:</strong> ' . fullname($user) . '</p>';
$html .= '<p><strong>Due Date:</strong> ' . userdate($timeclose, get_string('strftimedate', 'langconfig')) . '</p>';

$html .= '<h3>Activities Due ' . userdate($timeclose, get_string('strftimedate', 'langconfig')) . '</h3>';
$html .= '<ul>';
foreach ($activities1 as $act) {
    $html .= '<li>' . s($act->name) . ' (' . s($act->category) . ')</li>';
}
$html .= '</ul>';

if (!empty($activities2)) {
    $html .= '<h3>Upcoming Activities Due ' . userdate($next_due_date, get_string('strftimedate', 'langconfig')) . '</h3>';
    $html .= '<ul>';
    foreach ($activities2 as $act) {
        $html .= '<li>' . s($act->name) . '</li>';
    }
    $html .= '</ul>';
}

// Save Report
$record = new stdClass();
$record->userid = $userid;
$record->timeclose = $timeclose;
$record->subject = 'Homework Report - ' . userdate($timeclose, get_string('strftimedate', 'langconfig'));
$record->content = $html;
$record->timecreated = time();

$DB->insert_record('local_homework_reports', $record);

// Send Email (Mockup)
// In a real scenario, use email_to_user
// $email_user = new stdClass();
// $email_user->email = $pinfo->p1_email; ...
// email_to_user($email_user, $noreply, $record->subject, strip_tags($html), $html);

echo json_encode(['status' => 'success']);
