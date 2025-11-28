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
$html = '
<style>
    .report-table { width: 100%; border-collapse: collapse; font-family: Arial, sans-serif; font-size: 12px; }
    .report-table th, .report-table td { border: 1px solid #ddd; padding: 8px; text-align: left; }
    .report-table th { background-color: #f2f2f2; font-weight: bold; }
    .status-badge { padding: 2px 6px; border-radius: 4px; color: white; font-size: 10px; }
    .status-done { background-color: #28a745; }
    .status-todo { background-color: #dc3545; }
    .status-submitted { background-color: #ffc107; color: black; }
    .classification-new { background-color: #17a2b8; color: white; padding: 2px 4px; border-radius: 2px; }
    .classification-revision { background-color: #ffc107; color: black; padding: 2px 4px; border-radius: 2px; }
</style>

<h2>Homework Report</h2>
<p><strong>Student:</strong> ' . fullname($user) . '</p>
<p><strong>Due Date:</strong> ' . userdate($timeclose, get_string('strftimedate', 'langconfig')) . '</p>

<h3>Activities Due ' . userdate($timeclose, get_string('strftimedate', 'langconfig')) . '</h3>
<table class="report-table">
    <thead>
        <tr>
            <th>Full Name</th>
            <th>Course</th>
            <th>Quiz</th>
            <th>Status</th>
            <th>Attempt #</th>
            <th>Classification</th>
            <th>Quiz Type</th>
            <th>Due Date</th>
            <th>Finished</th>
            <th>Duration</th>
            <th>Score</th>
            <th>%</th>
        </tr>
    </thead>
    <tbody>';

// We need full details for Activities 1.
// $rows contains the snapshot data for this user and date.
foreach ($rows as $idx => $r) {
    $statusClass = 'status-todo';
    $statusLabel = 'To do';
    if ($r->status == 'completed') {
        $statusClass = 'status-done';
        $statusLabel = 'Done';
    } elseif ($r->status == 'submitted') {
        $statusClass = 'status-submitted';
        $statusLabel = 'Submitted';
    }

    $classLabel = $r->classification ?? '';
    $classStyle = '';
    if (strtolower($classLabel) === 'new') $classStyle = 'classification-new';
    if (strtolower($classLabel) === 'revision') $classStyle = 'classification-revision';

    $finished = $r->timefinish > 0 ? userdate($r->timefinish, get_string('strftimedatetime', 'langconfig')) : '-';
    $duration = '-'; // Not available in snapshot currently
    $score = $r->score !== '' ? $r->score . ' / ' . $r->maxscore : '-';
    $percent = $r->percentage !== '' ? $r->percentage . '%' : '-';
    $attemptno = isset($r->attemptno) ? $r->attemptno : (is_numeric($r->attempts) ? $r->attempts : 0);

    $html .= '
        <tr>
            <td>' . s($r->studentname) . '</td>
            <td>' . s($r->coursename) . '</td>
            <td>' . s($r->quizname) . '</td>
            <td><span class="status-badge ' . $statusClass . '">' . $statusLabel . '</span></td>
            <td>' . $attemptno . '</td>
            <td><span class="' . $classStyle . '">' . s($classLabel) . '</span></td>
            <td>' . s($r->quiz_type) . '</td>
            <td>' . userdate($r->timeclose, get_string('strftimedatetime', 'langconfig')) . '</td>
            <td>' . $finished . '</td>
            <td>' . $duration . '</td>
            <td>' . $score . '</td>
            <td>' . $percent . '</td>
        </tr>';
}

$html .= '</tbody></table>';

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

if ($existing) {
    $record->id = $existing->id;
    $DB->update_record('local_homework_reports', $record);
} else {
    $DB->insert_record('local_homework_reports', $record);
}

// Send Email
$noreply = core_user::get_noreply_user();
$subject = $record->subject;
$messagehtml = $html;
$messagetext = strip_tags($html);

// Recipients: Parent 1 and Parent 2
$recipients = [];
if (!empty($pinfo->p1_email)) {
    $recipients[] = (object)['email' => $pinfo->p1_email, 'firstname' => $pinfo->p1_name, 'lastname' => '', 'id' => -1, 'maildisplay' => 1];
}
if (!empty($pinfo->p2_email)) {
    $recipients[] = (object)['email' => $pinfo->p2_email, 'firstname' => $pinfo->p2_name, 'lastname' => '', 'id' => -1, 'maildisplay' => 1];
}

foreach ($recipients as $recipient) {
    email_to_user($recipient, $noreply, $subject, $messagetext, $messagehtml);
}

echo json_encode(['status' => 'success']);
