<?php
namespace local_homeworkdashboard;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/local/homeworkdashboard/classes/homework_manager.php');
require_once($CFG->dirroot . '/local/homeworkdashboard/classes/google_drive_helper.php');

class report_generator {

    /**
     * Generate and send a report for a student and due date.
     * 
     * @param int $userid
     * @param int $duedate
     * @return array ['success' => bool, 'message' => string]
     */
    public function generate_report(int $userid, int $duedate) {
        global $DB, $CFG;

        // 1. Get Student Work
        $work = $this->get_student_work($userid, $duedate);
        if (empty($work)) {
            return ['success' => false, 'message' => 'No work found for this student and date.'];
        }

        // 2. Get Parent Contacts
        $parents = $this->get_parent_contacts($userid);
        if (empty($parents) || empty($parents['parent1pmail'])) {
            return ['success' => false, 'message' => 'No parent email found for this student.'];
        }

        // 3. Generate Content (Dummy for Phase 1)
        $html_content = $this->generate_dummy_content($userid, $duedate, $work, $parents);

        // 4. Save to Google Drive
        $drive_helper = new google_drive_helper();
        $drive_link = null;
        if ($drive_helper->is_configured()) {
            // Filename: Report_StudentName_Date
            $student = $DB->get_record('user', ['id' => $userid]);
            $studentname = fullname($student);
            $datestr = date('Y-m-d', $duedate);
            $filename = "Homework_Report_{$studentname}_{$datestr}";
            
            $drive_link = $drive_helper->upload_html_content($html_content, $filename);
        }

        // 5. Send Email
        $sent = $this->send_email($parents, $html_content, $drive_link);

        if ($sent) {
            $msg = 'Report sent successfully.';
            if ($drive_link) {
                $msg .= ' Saved to Drive.';
            } else {
                $msg .= ' (Drive upload skipped/failed).';
            }
            return ['success' => true, 'message' => $msg];
        } else {
            return ['success' => false, 'message' => 'Failed to send email.'];
        }
    }

    /**
     * Get all quizzes for the student due on the given date.
     */
    private function get_student_work(int $userid, int $duedate) {
        global $DB;
        
        // Find quizzes with timeclose = duedate where user is enrolled in the course
        $sql = "SELECT q.id, q.course, q.name, c.fullname as coursename, cm.id as cmid
                FROM {quiz} q
                JOIN {course} c ON q.course = c.id
                JOIN {course_modules} cm ON cm.instance = q.id AND cm.module = (SELECT id FROM {modules} WHERE name = 'quiz')
                JOIN {enrol} e ON e.courseid = c.id
                JOIN {user_enrolments} ue ON ue.enrolid = e.id
                WHERE q.timeclose = :duedate
                AND ue.userid = :userid
                AND ue.status = 0 
                AND e.status = 0"; // Active enrolments
        
        return $DB->get_records_sql($sql, ['duedate' => $duedate, 'userid' => $userid]);
    }

    /**
     * Get parent contact info from custom profile fields.
     */
    private function get_parent_contacts(int $userid) {
        global $DB;
        
        // We need to find the field IDs first
        $fields = $DB->get_records_list('user_info_field', 'shortname', 
            ['parent1name', 'parent1pmail', 'parent1phone', 'P1_language']);
            
        if (empty($fields)) {
            return [];
        }
        
        $contacts = [];
        foreach ($fields as $field) {
            $data = $DB->get_record('user_info_data', ['userid' => $userid, 'fieldid' => $field->id]);
            $contacts[$field->shortname] = $data ? $data->data : '';
        }
        
        return $contacts;
    }

    /**
     * Generate dummy HTML content for testing.
     */
    private function generate_dummy_content($userid, $duedate, $work, $parents) {
        global $DB;
        $student = $DB->get_record('user', ['id' => $userid]);
        $studentname = fullname($student);
        $date_str = userdate($duedate, get_string('strftimedate', 'langconfig'));
        $parentname = $parents['parent1name'] ?? 'Parent';
        
        $html = "<!DOCTYPE html><html><head><style>
                    body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
                    .header { background: #f4f4f4; padding: 20px; border-bottom: 2px solid #ddd; }
                    .content { padding: 20px; }
                    .activity { margin-bottom: 15px; padding: 10px; border: 1px solid #eee; border-radius: 5px; }
                    .footer { margin-top: 30px; font-size: 0.9em; color: #777; }
                 </style></head><body>";
                 
        $html .= "<div class='header'>";
        $html .= "<h1>Weekly Homework Report</h1>";
        $html .= "<p><strong>Student:</strong> {$studentname}</p>";
        $html .= "<p><strong>Due Date:</strong> {$date_str}</p>";
        $html .= "</div>";
        
        $html .= "<div class='content'>";
        $html .= "<p>Dear {$parentname},</p>";
        $html .= "<p>Here is the summary of homework activities due on {$date_str}:</p>";
        
        foreach ($work as $w) {
            $html .= "<div class='activity'>";
            $html .= "<h3>{$w->coursename}</h3>";
            $html .= "<p><strong>Activity:</strong> {$w->name}</p>";
            $html .= "<p><em>Status: (Dummy Status)</em></p>";
            $html .= "</div>";
        }
        
        $html .= "</div>";
        
        $html .= "<div class='footer'>";
        $html .= "<p>Generated by GrowMinds Homework System.</p>";
        $html .= "</div>";
        $html .= "</body></html>";
        
        return $html;
    }

    /**
     * Send email to parent.
     */
    private function send_email($parents, $html, $link) {
        global $CFG;
        
        // Construct a dummy user object for the parent recipient
        $parent_user = new \stdClass();
        $parent_user->id = -99; // Dummy ID
        $parent_user->email = $parents['parent1pmail'];
        $parent_user->firstname = $parents['parent1name'] ?? 'Parent';
        $parent_user->lastname = '';
        $parent_user->maildisplay = 1;
        $parent_user->mailformat = 1; // HTML

        // Sender: support@growminds.net
        $from_user = new \stdClass();
        $from_user->id = -98; // Dummy ID
        $from_user->email = 'support@growminds.net';
        $from_user->firstname = 'GrowMinds';
        $from_user->lastname = 'Support';
        $from_user->maildisplay = 1;
        $from_user->mailformat = 1;
        
        $subject = "Homework Report";
        $text = strip_tags($html);
        
        if ($link) {
            $html_link = "<p style='margin-top:20px; border-top:1px solid #eee; padding-top:10px;'>";
            $html_link .= "<strong>View this report online:</strong> <a href='{$link}'>Google Drive Link</a>";
            $html_link .= "</p>";
            
            // Inject link before closing body
            $html = str_replace('</body>', $html_link . '</body>', $html);
            $text .= "\n\nView online: {$link}";
        }

        return email_to_user($parent_user, $from_user, $subject, $text, $html);
    }
}
