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
    0, [], 0, [], [$userid], '', '', '', '', null, 'timeclose', 'DESC', false, [$timeclose], false
);

// Sort Rows: New > Revision > Category 1 > Others
usort($rows, function($a, $b) {
    // 1. Classification Priority
    $classA = strtolower($a->classification ?? '');
    $classB = strtolower($b->classification ?? '');
    
    $scoreA = ($classA === 'new') ? 2 : (($classA === 'revision') ? 1 : 0);
    $scoreB = ($classB === 'new') ? 2 : (($classB === 'revision') ? 1 : 0);
    
    if ($scoreA !== $scoreB) {
        return $scoreB - $scoreA; // Higher score first
    }
    
    // 2. Category Priority (Category 1 first)
    $catA = strtolower($a->categoryname ?? '');
    $catB = strtolower($b->categoryname ?? '');
    $isCat1A = ($catA === 'category 1');
    $isCat1B = ($catB === 'category 1');
    
    if ($isCat1A !== $isCat1B) {
        return $isCat1A ? -1 : 1;
    }
    
    return strcasecmp($a->quizname, $b->quizname);
});

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

        // Sort Activities 2: New > Revision > Category 1 > Others
        usort($activities2, function($a, $b) {
            // 1. Classification Priority
            $classA = strtolower($a->classification ?? '');
            $classB = strtolower($b->classification ?? '');
            
            $scoreA = ($classA === 'new') ? 2 : (($classA === 'revision') ? 1 : 0);
            $scoreB = ($classB === 'new') ? 2 : (($classB === 'revision') ? 1 : 0);
            
            if ($scoreA !== $scoreB) {
                return $scoreB - $scoreA; // Higher score first
            }
            
            // 2. Category Priority (Category 1 first)
            $catA = strtolower($a->categoryname ?? '');
            $catB = strtolower($b->categoryname ?? '');
            $isCat1A = ($catA === 'category 1');
            $isCat1B = ($catB === 'category 1');
            
            if ($isCat1A !== $isCat1B) {
                return $isCat1A ? -1 : 1;
            }
            
            return strcasecmp($a->name, $b->name);
        });
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

// Find Classroom (Category 1 Course)
$classroom = '';
foreach ($rows as $r) {
    if (strcasecmp($r->categoryname ?? '', 'Category 1') === 0) {
        $classroom = $r->coursename;
        break;
    }
}
// Fallback if no Category 1 found, use the first course name
if (empty($classroom) && !empty($rows)) {
    $classroom = $rows[0]->coursename;
}

// Generate HTML
$html = '
<style>
    .report-header { background-color: #004494; padding: 20px; border-radius: 8px; margin-bottom: 20px; }
    .report-header h2 { margin-top: 0; color: #ffffff; }
    .report-subtitle { margin-bottom: 0; color: #e3f2fd; font-size: 18px; font-weight: bold; }
    .report-info { margin-bottom: 20px; }
    .report-info p { margin: 5px 0; }
    .section-heading { color: #0056b3; font-size: 1.1rem; margin-top: 20px; margin-bottom: 10px; font-weight: bold; }
    .report-table { width: 100%; border-collapse: collapse; font-family: Arial, sans-serif; font-size: 12px; margin-bottom: 20px; }
    .report-table th, .report-table td { border: 1px solid #ddd; padding: 8px; text-align: left; }
    .report-table th { background-color: #0056b3; color: white; font-weight: bold; border-color: #0056b3; }
    .status-badge { padding: 4px 8px; border-radius: 12px; font-size: 11px; font-weight: bold; display: inline-block; text-align: center; min-width: 60px; }
    .status-done { background-color: #d4edda; color: #155724; }
    .status-todo { background-color: #dc3545; color: white; }
    .status-submitted { background-color: #fff3cd; color: #856404; }
    .classification-badge { padding: 4px 8px; border-radius: 4px; font-size: 11px; font-weight: bold; color: white; display: inline-block; }
    .classification-new { background-color: #17a2b8; }
    .classification-revision { background-color: #ffc107; color: white; }
</style>

<div class="report-header">
    <h2>Progress Report - ' . userdate($timeclose, get_string('strftimedate', 'langconfig')) . '</h2>
    <div class="report-subtitle">GrowMinds Academy</div>
</div>

<div class="report-info">
    <p><strong>Student:</strong> ' . fullname($user) . '</p>
    <p><strong>Classroom:</strong> ' . s($classroom) . '</p>
    <p><strong>Date:</strong> ' . userdate($timeclose, get_string('strftimedate', 'langconfig')) . '</p>
</div>

<h4 class="section-heading">Activities Due ' . userdate($timeclose, get_string('strftimedate', 'langconfig')) . '</h4>
<table class="report-table">
    <thead>
        <tr>
            <th>Name</th>
            <th>Course</th>
            <th>Quiz</th>
            <th>Attempt</th>
            <th>Classification</th>
            <th>Finished</th>
            <th>Duration</th>
            <th>Score</th>
            <th>%</th>
            <th>Status</th>
        </tr>
    </thead>
    <tbody>';

// We need full details for Activities 1.
// $rows contains the snapshot data for this user and date.
foreach ($rows as $idx => $r) {
    $statusClass = 'status-todo';
    $statusLabel = 'Not done';
    $st = strtolower((string)$r->status);

    if ($st === 'completed') {
        $statusClass = 'status-done';
        $statusLabel = 'Done';
    } elseif ($st === 'low grade' || $st === 'submitted') {
        $statusClass = 'status-submitted';
        $statusLabel = 'Low score';
    }

    $classLabel = $r->classification ?? '';
    $classStyle = 'classification-badge';
    if (strtolower($classLabel) === 'new') $classStyle .= ' classification-new';
    if (strtolower($classLabel) === 'revision') $classStyle .= ' classification-revision';

    $finished = $r->timefinish > 0 ? userdate($r->timefinish, get_string('strftimedatetime', 'langconfig')) : '-';
    $duration = isset($r->time_taken) && $r->time_taken !== '' ? $r->time_taken : '-';
    $score = $r->score !== '' ? $r->score . ' / ' . $r->maxscore : '-';
    $percent = $r->percentage !== '' ? $r->percentage . '%' : '-';
    $attemptno = isset($r->attemptno) ? $r->attemptno : (is_numeric($r->attempts) ? $r->attempts : 0);

    $html .= '
        <tr>
            <td>' . s(fullname($user)) . '</td>
            <td>' . s($r->coursename) . '</td>
            <td>' . s($r->quizname) . '</td>
            <td>' . $attemptno . '</td>
            <td><span class="' . $classStyle . '">' . s($classLabel) . '</span></td>
            <td>' . $finished . '</td>
            <td>' . $duration . '</td>
            <td>' . $score . '</td>
            <td>' . $percent . '</td>
            <td><span class="status-badge ' . $statusClass . '">' . $statusLabel . '</span></td>
        </tr>';
}

$html .= '</tbody></table>';

if (!empty($activities2)) {
    $html .= '<h4 class="section-heading">Upcoming Activities Due ' . userdate($next_due_date, get_string('strftimedate', 'langconfig')) . '</h4>';
    $html .= '<table class="report-table">
        <thead>
            <tr>
                <th>Name</th>
                <th>Course</th>
                <th>Quiz</th>
                <th>Classification</th>
                <th>Due Date</th>
            </tr>
        </thead>
        <tbody>';
    
    foreach ($activities2 as $act) {
        $clLabel = $act->classification ?? '';
        $clStyle = 'classification-badge';
        if (strtolower($clLabel) === 'new') $clStyle .= ' classification-new';
        if (strtolower($clLabel) === 'revision') $clStyle .= ' classification-revision';
        
        $html .= '<tr>
            <td>' . s(fullname($user)) . '</td>
            <td>' . s($act->coursename ?? '') . '</td>
            <td>' . s($act->name) . '</td>
            <td><span class="' . $clStyle . '">' . s($clLabel) . '</span></td>
            <td>' . userdate($next_due_date, get_string('strftimedate', 'langconfig')) . '</td>
        </tr>';
    }
    $html .= '</tbody></table>';
}

$html .= '</div>'; 

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
