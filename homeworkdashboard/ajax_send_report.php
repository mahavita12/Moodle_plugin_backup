<?php
define('AJAX_SCRIPT', true);

require_once('../../config.php');
error_log('GEMINI_DEBUG: ajax_send_report.php called!'); // Added top-level log
require_once($CFG->dirroot . '/local/homeworkdashboard/classes/homework_manager.php');
require_once($CFG->dirroot . '/local/homeworkdashboard/classes/google_drive_helper.php');
require_once($CFG->dirroot . '/local/homeworkdashboard/classes/gemini_helper_v2.php');

$userid = required_param('userid', PARAM_INT);
$timeclose = required_param('timeclose', PARAM_INT); // Due Date 1 timestamp
$lang = optional_param('lang', 'en', PARAM_ALPHA);

require_login();
$context = context_system::instance();
require_capability('local/homeworkdashboard:view', $context);

$manager = new \local_homeworkdashboard\homework_manager();

// Check if report already exists
$existing = $DB->get_record('local_homework_reports', ['userid' => $userid, 'timeclose' => $timeclose, 'lang' => $lang]);

// Fetch Data
// 1. User Info & Parents
$user = $DB->get_record('user', ['id' => $userid], '*', MUST_EXIST);
$parents = $manager->get_users_parent_info([$userid]);
$pinfo = $parents[$userid] ?? null;

// 2. Activities 1 (Due Date 1)
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
$classroom_short = '';
foreach ($rows as $r) {
    if (strcasecmp($r->categoryname ?? '', 'Category 1') === 0) {
        $classroom = $r->coursename;
        $classroom_short = $r->courseshortname ?? $r->coursename; // Fallback to fullname if shortname missing
        break;
    }
}
// Fallback if no Category 1 found, use the first course name
if (empty($classroom) && !empty($rows)) {
    $classroom = $rows[0]->coursename;
    $classroom_short = $rows[0]->courseshortname ?? $rows[0]->coursename;
}

// --- GEMINI AI INTEGRATION START ---
$new_acts_data = [];
$rev_acts_data = [];

foreach ($rows as $r) {
    // Fetch detailed attempts
    // Note: $r->quizid comes from the snapshot query. Ensure it exists.
    // The snapshot query selects q.id AS quizid.
    if (!empty($r->quizid)) {
        $attempts_raw = $manager->get_user_quiz_attempts($userid, $r->quizid);
        $attempts_clean = [];
        foreach ($attempts_raw as $att) {
            $duration = $att->timefinish - $att->timestart;
            $attempts_clean[] = [
                'attempt' => $att->attempt,
                'score' => $att->sumgrades, // Raw score
                'duration' => $duration
            ];
        }
        
        // Get question count for AI analysis
        $question_count = $DB->count_records('quiz_slots', ['quizid' => $r->quizid]);

        // Pass status directly (it is already 'Completed', 'Low grade', or 'No attempt')
        $status_label = $r->status ?? 'No attempt';

        $act_data = [
            'name' => $r->quizname,
            'coursename' => $r->coursename,
            'maxscore' => $r->maxscore,
            'question_count' => $question_count,
            'attempts' => $attempts_clean,
            'status' => $status_label
        ];

        if (strtolower($r->classification ?? '') === 'new') {
            $new_acts_data[] = $act_data;
        } else {
            $rev_acts_data[] = $act_data;
        }
    }
}

$gemini = new \local_homeworkdashboard\gemini_helper();
error_log('GEMINI_DEBUG: Starting generation for ' . fullname($user));
// Generate AI Commentary
$ai_commentary = $gemini->generate_commentary($user->firstname, $new_acts_data, $rev_acts_data, $lang);

// Post-process AI commentary
if (!empty($ai_commentary)) {
    $ai_commentary = str_replace('새 진도 활동', '새로운 과제', $ai_commentary);
    $ai_commentary = str_replace(['복습활동', '복습 활동'], '복습과제', $ai_commentary);
    $ai_commentary = str_ireplace('Stern Warning', 'Warning', $ai_commentary);
    $ai_commentary = str_ireplace('Warning', '<span style="color: red; font-weight: bold;">Warning</span>', $ai_commentary);
}
error_log('GEMINI_DEBUG: Generation complete. Result length: ' . strlen($ai_commentary ?? ''));
// --- GEMINI AI INTEGRATION END ---

// Generate HTML with INLINE STYLES
// Define styles as PHP variables for cleaner concatenation
// FIX: Use single quotes for font names inside double-quoted style attribute
$style_container = "font-family: 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;";
$style_header = 'background-color: #004494; padding: 20px; border-radius: 8px; margin-bottom: 20px;';
$style_h2 = 'margin-top: 0; color: #ffffff;';
$style_subtitle = 'margin-bottom: 0; color: #e3f2fd; font-size: 18px; font-weight: bold;';
$style_info = 'margin-bottom: 20px;';
$style_p = 'margin: 5px 0;';
$style_section = 'color: #0056b3; font-size: 1.1rem; margin-top: 20px; margin-bottom: 10px; font-weight: bold;';
$style_table = 'width: 100%; border-collapse: collapse; font-family: Arial, sans-serif; font-size: 12px; margin-bottom: 20px;';
$style_th = 'border: 1px solid #ddd; padding: 8px; text-align: left; background-color: #0056b3 !important; color: white !important; font-weight: bold; border-color: #0056b3;';
$style_td = 'border: 1px solid #ddd; padding: 8px; text-align: left;';
$style_badge = 'padding: 4px 8px; border-radius: 12px; font-size: 11px; font-weight: bold; display: inline-block; text-align: center; min-width: 60px;';
$style_class = 'padding: 4px 8px; border-radius: 4px; font-size: 11px; font-weight: bold; color: white; display: inline-block;';

// Greeting
$html = '<div style="font-family: \'Segoe UI\', sans-serif; color: #333; margin-bottom: 20px;">';
// Greeting
if ($lang === 'ko') {
    $date_str = userdate($timeclose, get_string('strftimedate', 'langconfig'));
    $html .= '<p>안녕하세요</p>';
    $html .= '<p>이번주 (' . $date_str . ') 의 ' . s($user->firstname) . ' 의 Progress Report를 보내드립니다.</p>';
    $html .= '<p>아래 리포트는 ' . s($user->firstname) . '(이)가 얼마나 숙제를 성실하게 했는지, 잘했거나 부족한 부분이 무엇인지를 보여드리기 위해 작성했습니다.</p>';
    $html .= '<p>질문이 있으시거나 좀 더 필요한 부분이 있으시면 말씀주세요.</p>';
    $html .= '<p>GrowMinds Academy Team</p>';
} else {
    $html .= '<p>To ' . s($user->firstname) . '\'s parents,</p>';
    $html .= '<p>Please find ' . s($user->firstname) . '\'s progress report for the week ending ' . userdate($timeclose, get_string('strftimedate', 'langconfig')) . ' below.</p>';
    $html .= '<p>This report details ' . s($user->firstname) . '\'s progress for homework activities and outlines areas for improvement.</p>';
    $html .= '<p>Should you have any questions or require further support, please reach out to us.</p>';
    $html .= '<p>Warm regards,<br>GrowMinds Academy Team</p>';
}
$html .= '</div>';

$html .= '
<div class="homework-report-container" style="' . $style_container . '">
    <div class="report-header" style="' . $style_header . '">
        <h2 style="' . $style_h2 . '">Progress Report - ' . userdate($timeclose, get_string('strftimedate', 'langconfig')) . '</h2>
        <div class="report-subtitle" style="' . $style_subtitle . '">GrowMinds Academy</div>
    </div>

    <div class="report-info" style="' . $style_info . ' color: #004494;">
        <p style="' . $style_p . '"><strong>Student:</strong> ' . $user->firstname . '</p>
        <p style="' . $style_p . '"><strong>Classroom:</strong> ' . s($classroom) . '</p>
        <p style="' . $style_p . '"><strong>Date:</strong> ' . userdate($timeclose, get_string('strftimedate', 'langconfig')) . '</p>
    </div>

    <!-- AI Commentary Section -->
    <div class="ai-commentary" style="background-color: #f8f9fa; border-left: 4px solid #0056b3; padding: 15px; margin-bottom: 20px; border-radius: 4px;">
        <h4 style="margin-top: 0; color: #0056b3;">GrowMinds Academy Summary</h4>
        <div style="font-family: \'Segoe UI\', Roboto, Arial, sans-serif; color: #333; line-height: 1.6;">
            ' . $ai_commentary . '
        </div>
    </div>
    <!-- End AI Commentary -->

    <h4 class="section-heading" style="' . $style_section . '">Activities Due ' . userdate($timeclose, get_string('strftimedate', 'langconfig')) . '</h4>
    <table class="report-table" style="' . $style_table . '">
        <thead>
            <tr>
                <th style="' . $style_th . '">Name</th>
                <th style="' . $style_th . '">Course</th>
                <th style="' . $style_th . '">Quiz</th>
                <th style="' . $style_th . '">Attempt</th>
                <th style="' . $style_th . '">Classification</th>
                <th style="' . $style_th . '">Finished</th>
                <th style="' . $style_th . '">Duration</th>
                <th style="' . $style_th . '">Score</th>
                <th style="' . $style_th . '">%</th>
                <th style="' . $style_th . '">Status</th>
            </tr>
        </thead>
        <tbody>';

// We need full details for Activities 1.
// $rows contains the snapshot data for this user and date.
foreach ($rows as $idx => $r) {
    $statusStyle = $style_badge;
    $statusLabel = 'Not done';
    $st = strtolower((string)$r->status);

    if ($st === 'completed') {
        $statusStyle .= ' background-color: #d4edda; color: #155724;';
        $statusLabel = 'Done';
    } elseif ($st === 'low grade' || $st === 'submitted') {
        $statusStyle .= ' background-color: #fff3cd; color: #856404;';
        $statusLabel = 'Low score';
    } else {
        $statusStyle .= ' background-color: #dc3545; color: white;'; // Todo
    }

    $classLabel = $r->classification ?? '';
    $classStyle = $style_class;
    if (strtolower($classLabel) === 'new') {
        $classStyle .= ' background-color: #17a2b8;';
    } elseif (strtolower($classLabel) === 'revision') {
        $classStyle .= ' background-color: #ffc107; color: white;';
    } else {
        $classStyle .= ' background-color: #6c757d;'; // Default gray
    }

    $finished = $r->timefinish > 0 ? userdate($r->timefinish, get_string('strftimedatetime', 'langconfig')) : '-';
    $duration = isset($r->time_taken) && $r->time_taken !== '' ? $r->time_taken : '-';
    $score = $r->score !== '' ? $r->score . ' / ' . $r->maxscore : '-';
    $percent = $r->percentage !== '' ? $r->percentage . '%' : '-';
    $attemptno = isset($r->attemptno) ? $r->attemptno : (is_numeric($r->attempts) ? $r->attempts : 0);

    $html .= '
        <tr>
            <td style="' . $style_td . '">' . s($user->firstname) . '</td>
            <td style="' . $style_td . '">' . s($r->coursename) . '</td>
            <td style="' . $style_td . '">' . s($r->quizname) . '</td>
            <td style="' . $style_td . '">' . $attemptno . '</td>
            <td style="' . $style_td . '"><span style="' . $classStyle . '">' . s($classLabel) . '</span></td>
            <td style="' . $style_td . '">' . $finished . '</td>
            <td style="' . $style_td . '">' . $duration . '</td>
            <td style="' . $style_td . '">' . $score . '</td>
            <td style="' . $style_td . '">' . $percent . '</td>
            <td style="' . $style_td . '"><span style="' . $statusStyle . '">' . $statusLabel . '</span></td>
        </tr>';
}

$html .= '</tbody></table>';

if (!empty($activities2)) {
    $html .= '<h4 class="section-heading" style="' . $style_section . '">Upcoming Activities Due ' . userdate($next_due_date, get_string('strftimedate', 'langconfig')) . '</h4>';
    $html .= '<table class="report-table" style="' . $style_table . '">
        <thead>
            <tr>
                <th style="' . $style_th . '">Name</th>
                <th style="' . $style_th . '">Course</th>
                <th style="' . $style_th . '">Quiz</th>
                <th style="' . $style_th . '">Classification</th>
                <th style="' . $style_th . '">Due Date</th>
            </tr>
        </thead>
        <tbody>';
    
    foreach ($activities2 as $act) {
        $clLabel = $act->classification ?? '';
        $clStyle = $style_class;
        if (strtolower($clLabel) === 'new') {
            $clStyle .= ' background-color: #17a2b8;';
        } elseif (strtolower($clLabel) === 'revision') {
            $clStyle .= ' background-color: #ffc107; color: white;';
        } else {
            $clStyle .= ' background-color: #6c757d;';
        }
        
        $html .= '<tr>
            <td style="' . $style_td . '">' . s($user->firstname) . '</td>
            <td style="' . $style_td . '">' . s($act->coursename ?? '') . '</td>
            <td style="' . $style_td . '">' . s($act->name) . '</td>
            <td style="' . $style_td . '"><span style="' . $clStyle . '">' . s($clLabel) . '</span></td>
            <td style="' . $style_td . '">' . userdate($next_due_date, get_string('strftimedate', 'langconfig')) . '</td>
        </tr>';
    }
    $html .= '</tbody></table>';
}

$html .= '</div>'; // Close container

// Link to GrowMinds Site
$site_link = 'https://growminds.net';
$html .= '<div style="margin-top: 20px; text-align: center;">';
$html .= '<a href="' . $site_link . '" target="_blank" style="display: inline-block; background-color: #0056b3; color: white; padding: 10px 20px; text-decoration: none; border-radius: 5px; font-weight: bold; font-family: Arial, sans-serif;">Login to GrowMinds</a>';
$html .= '</div>';

$record = new stdClass();
$record->userid = $userid;
$record->timeclose = $timeclose;
// Subject: Progress Report - Student Name (Classroom) - Date
$record->subject = 'Progress Report - ' . $user->firstname . ' (' . $classroom_short . ') - ' . userdate($timeclose, get_string('strftimedate', 'langconfig'));
if ($lang === 'ko') {
    $record->subject .= ' (Korean)';
}
$record->content = $html;
$record->timecreated = time();
$record->lang = $lang;

// Save to Google Drive
$drive_helper = new \local_homeworkdashboard\google_drive_helper();
$filename = $userid . '_' . fullname($user) . '_' . $classroom_short . '_' . date('Y-m-d', $timeclose) . '_' . $lang;
$drive_link = $drive_helper->upload_html_content($html, $filename);
if ($drive_link) {
    $record->drive_link = $drive_link;
}

// Save AI Data
if (!empty($ai_commentary)) {
    $record->ai_commentary = $ai_commentary;
    $record->ai_raw_response = $gemini->get_last_response();
}

if ($existing) {
    $record->id = $existing->id;
    $DB->update_record('local_homework_reports', $record);
    $reportid = $existing->id;
} else {
    $reportid = $DB->insert_record('local_homework_reports', $record);
}

// NOTE: Email sending is handled by ajax_email_report.php. 
// This script ONLY generates the report.

echo json_encode([
    'status' => 'success',
    'reportid' => $reportid,
    'timeemailsent' => $existing ? $existing->timeemailsent : 0,
    'ai_status' => !empty($ai_commentary) ? 'success' : 'failed',
    'ai_error' => $gemini->get_last_error()
]);
