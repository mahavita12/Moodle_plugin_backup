<?php
define('AJAX_SCRIPT', true);

// Start output buffering immediately to capture any stray output/warnings
ob_start();

require_once('../../config.php');
require_once($CFG->dirroot . '/local/homeworkdashboard/classes/homework_manager.php');

try {
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
        throw new Exception('No parent info found');
    }

    // 2. Fetch Existing Reports
    $reports = $DB->get_records('local_homework_reports', ['userid' => $userid, 'timeclose' => $timeclose], '', 'lang, id, subject, content, drive_link');

    $report_en = $reports['en'] ?? null;
    $report_ko = $reports['ko'] ?? null;

    // Helper to send email
    if (!function_exists('send_report_email')) {
        function send_report_email($recipient, $report, $sender) {
            global $CFG;

            if (empty($recipient->email)) {
                return false; // Skip if no email
            }

            $subject = $report->subject;
            $html = $report->content;

            // Append Drive Link if available
            if (!empty($report->drive_link)) {
                $html .= '<br><br><p><a href="' . $report->drive_link . '" style="display: inline-block; padding: 10px 20px; background-color: #007bff; color: white; text-decoration: none; border-radius: 5px;">View PDF/Printable Report</a></p>';
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
    }

    // Sender
    $sender = new stdClass();
    $sender->id = -99;
    $sender->email = 'support@growminds.net';
    $sender->firstname = 'GrowMinds';
    $sender->lastname = 'Support';
    $sender->maildisplay = true;
    $sender->mailformat = 1;

    // Support Recipient for BCC
    $support_recipient = new stdClass();
    $support_recipient->id = -99; 
    $support_recipient->email = 'support@growminds.net';
    $support_recipient->firstname = 'GrowMinds';
    $support_recipient->lastname = 'Support';
    $support_recipient->maildisplay = true;
    $support_recipient->mailformat = 1;

    $sent_count = 0;
    $debug_logs = [];

    // Process Parent 1
    if (!empty($pinfo->p1_email)) {
        $lang = $pinfo->p1_lang;

        // Logic: Default EN. If KO pref AND KO report exists -> KO.
        if (($lang === 'ko' || $lang === 'Korean') && $report_ko) {
            $report_to_send = $report_ko;
        } else {
            $report_to_send = $report_en;
        }

        if ($report_to_send) {
            $recipient = (object)['email' => $pinfo->p1_email, 'firstname' => $pinfo->p1_name, 'lastname' => '', 'id' => -1, 'maildisplay' => 1, 'mailformat' => 1];
            if (send_report_email($recipient, $report_to_send, $sender)) {
                $sent_count++;
                
                // Update timeemailsent
                $report_to_send->timeemailsent = time();
                $DB->update_record('local_homework_reports', $report_to_send);

                // Send BCC to Support
                send_report_email($support_recipient, $report_to_send, $sender);
            } else {
                $debug_logs[] = "Failed to send to P1: " . $pinfo->p1_email;
            }
        } else {
             $debug_logs[] = "No report found for P1 (Lang: $lang)";
        }
    }

    // Process Parent 2
    if (!empty($pinfo->p2_email)) {
        $lang = $pinfo->p2_lang;

        if (($lang === 'ko' || $lang === 'Korean') && $report_ko) {
            $report_to_send = $report_ko;
        } else {
            $report_to_send = $report_en;
        }

        if ($report_to_send) {
            $recipient = (object)['email' => $pinfo->p2_email, 'firstname' => $pinfo->p2_name, 'lastname' => '', 'id' => -1, 'maildisplay' => 1, 'mailformat' => 1];
            if (send_report_email($recipient, $report_to_send, $sender)) {
                $sent_count++;
                
                // Update timeemailsent
                $report_to_send->timeemailsent = time();
                $DB->update_record('local_homework_reports', $report_to_send);

                // Send BCC to Support
                send_report_email($support_recipient, $report_to_send, $sender);
            } else {
                $debug_logs[] = "Failed to send to P2: " . $pinfo->p2_email;
            }
        }
    }

    // Capture any output (e.g. SMTP debug)
    $output = ob_get_clean();

    if ($sent_count > 0) {
        echo json_encode(['status' => 'success', 'message' => 'Email sent to ' . $sent_count . ' parent(s)', 'debug' => $output, 'logs' => $debug_logs]);
    } else {
        echo json_encode(['status' => 'error', 'message' => 'No emails sent. Check logs.', 'debug' => $output, 'logs' => $debug_logs]);
    }

} catch (Exception $e) {
    $output = ob_get_clean();
    echo json_encode(['status' => 'error', 'message' => $e->getMessage(), 'debug' => $output]);
}
