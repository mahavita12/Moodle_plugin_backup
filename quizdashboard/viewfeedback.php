<?php
require_once(__DIR__.'/../../config.php');
require_once(__DIR__.'/classes/essay_grader.php');

$attemptid = required_param('id', PARAM_INT);
$print_view = optional_param('print', 0, PARAM_INT);

require_login();

global $DB, $PAGE, $OUTPUT, $USER;

try {
    $attempt = $DB->get_record('quiz_attempts', ['id' => $attemptid], '*', MUST_EXIST);
    $quiz = $DB->get_record('quiz', ['id' => $attempt->quiz], '*', MUST_EXIST);
    $course = $DB->get_record('course', ['id' => $quiz->course], '*', MUST_EXIST);
    $context = context_course::instance($course->id);

    if ($USER->id != $attempt->userid && !has_capability('mod/quiz:viewreports', $context)) {
        print_error('noaccess', 'local_quizdashboard');
    }

    $is_admin_view = has_capability('mod/quiz:viewreports', $context) && ($USER->id != $attempt->userid);

    $PAGE->set_url('/local/quizdashboard/viewfeedback.php', ['id' => $attemptid]);
    $PAGE->set_context($context);
    $PAGE->set_title('Essay Feedback');
    $PAGE->set_heading('Essay Feedback');
    
    // FIXED: Always use standard layout for admins, popup only for students
    if ($print_view) {
        $PAGE->set_pagelayout('print');
    } else if ($is_admin_view) {
        $PAGE->set_pagelayout('standard'); // Changed from 'admin' to 'standard'
    } else {
        $PAGE->set_pagelayout('popup');
    }

    // Add JavaScript for print functionality
    if ($print_view) {
        $PAGE->requires->js_init_code('window.onload = function() { window.print(); };');
    } else {
        $PAGE->requires->js_init_code('
            function printFeedback() { 
                window.print(); 
            }
            function printInNewWindow() { 
                var url = window.location.href + (window.location.href.includes("?") ? "&" : "?") + "print=1";
                var printWindow = window.open(url, "print_feedback", "width=900,height=700,scrollbars=yes,resizable=yes");
                if (printWindow) {
                    printWindow.focus();
                } else {
                    alert("Please allow popups for this site to use the print in new window feature.");
                }
            }
        ');
    }

    $grader = new \local_quizdashboard\essay_grader();
    $grading_result = $grader->get_grading_result($attemptid);

    echo $OUTPUT->header();

    // IMPROVED: Better print button layout
    if (!$print_view && $grading_result && !empty($grading_result->feedback_html)) {
        echo '<div class="print-buttons screen-only">';
        echo '<div class="btn-group float-right" role="group">';
        echo '<button type="button" class="btn btn-secondary" onclick="printFeedback()" title="Print this page">';
        echo '<i class="fa fa-print"></i> Print Page';
        echo '</button>';
        echo '<button type="button" class="btn btn-outline-secondary" onclick="printInNewWindow()" title="Open in new window for printing">';
        echo '<i class="fa fa-external-link"></i> Print Window';
        echo '</button>';
        echo '</div>';
        echo '<div class="clearfix"></div>';
        echo '</div>';
    }

    // Add wrapper for admin view
    if ($is_admin_view) {
        echo '<div class="admin-feedback-view">';
    } else {
        echo '<div class="popup-feedback-view">';
    }

    if ($grading_result && !empty($grading_result->feedback_html)) {
        echo $grading_result->feedback_html;
    } else {
        echo '<div class="alert alert-info text-center">';
        echo '<h4><i class="fa fa-info-circle"></i> No Feedback Available</h4>';
        echo '<p>Automated feedback has not been generated for this essay yet.</p>';
        echo '<p><a href="' . new moodle_url('/local/quizdashboard/essays.php') . '" class="btn btn-primary">Return to Essay Dashboard</a></p>';
        echo '</div>';
    }

    echo '</div>'; // Close wrapper div

} catch (Exception $e) {
    echo $OUTPUT->header();
    echo '<div class="alert alert-danger">';
    echo '<h4>Error Loading Feedback</h4>';
    echo '<p>There was an error loading the feedback for this essay attempt.</p>';
    echo '</div>';
    error_log('Error in viewfeedback.php: ' . $e->getMessage());
}

echo $OUTPUT->footer();
?>