<?php
define('AJAX_SCRIPT', true);

require_once('../../config.php');
require_once($CFG->dirroot . '/local/homeworkdashboard/classes/homework_manager.php');

$userid = required_param('userid', PARAM_INT);
$timeclose = required_param('timeclose', PARAM_INT);

require_login();
$context = context_system::instance();
require_capability('local/homeworkdashboard:view', $context);

$manager = new \local_homeworkdashboard\homework_manager();

// 1. Fetch Parent Info
$parents = $manager->get_users_parent_info([$userid]);
$pinfo = $parents[$userid] ?? null;

if (!$pinfo) {
    echo json_encode(['status' => 'error', 'message' => 'No parent info found']);
    die();
}

// 2. Fetch Existing Reports
$reports = $DB->get_records('local_homework_reports', ['userid' => $userid, 'timeclose' => $timeclose], '', 'lang, id, subject, content, drive_link');

    if (is_array($reports)) {
    } else {
    }


$report_en = $reports['en'] ?? null;
$report_ko = $reports['ko'] ?? null;

// Helper to send email
function send_report_email($recipient, $report, $sender) {
    global $CFG;
    
    if (empty($recipient->email)) {
        return false; // Skip if no email
    }

    $subject = $report->subject;
    $html = $report->content;
    
    // Append Drive Link if available
    if (!empty($report->drive_link)) {
        $html .= '<br><br><p><a href="' . $report->drive_link . '" style="background-color: #4285F4; color: white; padding: 10px 20px; text-decoration: none; border-radius: 5px;">View PDF/Printable Report</a></p>';
    }

    $messagehtml = $html;
    $messagetext = "This is the plain text version of the report. If you see this, your email client is not rendering HTML.\n\n" . strip_tags($html);
    if (!empty($report->drive_link)) {
        $messagetext .= "\n\nView Report: " . $report->drive_link;
    }

    // Send
    return email_to_user(
        $recipient, 
        $sender, 
        $subject, 
        $messagetext, 
        $messagehtml, 
        '', 
        '', 
        true, 
        'support@growminds.net', 
        'GrowMinds Support'
    );
}

// Sender
$sender = new stdClass();
$sender->id = -99;
$sender->email = 'support@growminds.net';
$sender->firstname = 'GrowMinds';
$sender->lastname = 'Support';
$sender->maildisplay = true;
$sender->mailformat = 1;

$sent_count = 0;

// Process Parent 1

    if (!empty($pinfo->p1_email)) {
        $lang = $pinfo->p1_lang;

    $report_to_send = ($lang === 'ko' && $report_ko) ? $report_ko : ($report_en ?? $report_ko); // Default to EN, fallback to KO if EN missing? Or strict default EN?
    // Plan said: "Default: English Report. If Parent Language is 'ko' AND Korean Report exists -> Send Korean Report. Fallback: If Parent Language is 'ko' but Korean Report MISSING -> Send English Report."
    
    // Refined logic:
    if (($lang === 'ko' || $lang === 'Korean') && $report_ko) {
        $report_to_send = $report_ko;
    } else {
        $report_to_send = $report_en;
    }

    if ($report_to_send) {
        $recipient = (object)['email' => $pinfo->p1_email, 'firstname' => $pinfo->p1_name, 'lastname' => '', 'id' => -1, 'maildisplay' => 1, 'mailformat' => 1];
        if (send_report_email($recipient, $report_to_send, $sender)) {
            $sent_count++;
        }
    }
}

// Process Parent 2
if (!empty($pinfo->p2_email)) {
    $lang = $pinfo->p2_lang;
    // Same logic
    if (($lang === 'ko' || $lang === 'Korean') && $report_ko) {
        $report_to_send = $report_ko;
    } else {
        $report_to_send = $report_en;
    }

    if ($report_to_send) {
        $recipient = (object)['email' => $pinfo->p2_email, 'firstname' => $pinfo->p2_name, 'lastname' => '', 'id' => -1, 'maildisplay' => 1, 'mailformat' => 1];
        if (send_report_email($recipient, $report_to_send, $sender)) {
            $sent_count++;
        }
    }
}

if ($sent_count > 0) {
    echo json_encode(['status' => 'success', 'message' => "Sent to $sent_count recipients"]);
} else {
    // If no emails sent (e.g. missing emails or missing reports)
    if (empty($report_en) && empty($report_ko)) {
        echo json_encode(['status' => 'error', 'message' => 'No reports generated yet']);
    } else {
        echo json_encode(['status' => 'success', 'message' => 'No emails sent (missing parent emails?)']);
    }
}
