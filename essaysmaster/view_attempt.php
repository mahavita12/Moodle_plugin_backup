<?php
require_once('../../config.php');
require_once($CFG->dirroot . '/local/essaysmaster/classes/dashboard_manager.php');
require_once($CFG->libdir . '/adminlib.php');

$attemptid = required_param('id', PARAM_INT);

require_login();

$context = context_system::instance();
require_capability('local/essaysmaster:viewdashboard', $context);

$PAGE->set_context($context);
$PAGE->set_url('/local/essaysmaster/view_attempt.php', array('id' => $attemptid));
$PAGE->set_title(get_string('attempt_details', 'local_essaysmaster'));
$PAGE->set_heading(get_string('attempt_details', 'local_essaysmaster'));
$PAGE->set_pagelayout('admin');

// Add CSS styles
$PAGE->requires->css('/local/essaysmaster/styles.css');

// Get attempt information
$dashboard_manager = new \local_essaysmaster\dashboard_manager();
$attempt_details = $dashboard_manager->get_attempt_details($attemptid);

if (!$attempt_details) {
    print_error('attemptnotfound', 'local_essaysmaster');
}

echo $OUTPUT->header();

echo html_writer::start_div('essaysmaster-attempt-detail');

// Attempt header
echo html_writer::start_div('attempt-header card mb-4');
echo html_writer::start_div('card-body');
echo html_writer::tag('h3', get_string('attempt_details', 'local_essaysmaster'), array('class' => 'mb-2'));
echo html_writer::tag('p', 
    get_string('student', 'local_essaysmaster') . ': ' . fullname($attempt_details->user), 
    array('class' => 'mb-1'));
echo html_writer::tag('p', 
    get_string('quiz_name', 'local_essaysmaster') . ': ' . $attempt_details->quiz_name, 
    array('class' => 'mb-1'));
echo html_writer::tag('p', 
    get_string('round', 'local_essaysmaster') . ': ' . $attempt_details->round, 
    array('class' => 'mb-1'));
echo html_writer::tag('p', 
    get_string('attempt_date', 'local_essaysmaster') . ': ' . userdate($attempt_details->timemodified), 
    array('class' => 'mb-3'));

// Status badge
$status_class = ($attempt_details->status === 'Pass') ? 'badge-success' : 'badge-warning';
echo html_writer::tag('span', $attempt_details->status, array('class' => 'badge ' . $status_class . ' mb-2'));

echo html_writer::end_div(); // End card-body
echo html_writer::end_div(); // End attempt-header

// Essay content
if (!empty($attempt_details->essay_text) && in_array($attempt_details->round, [2, 3, 4])) {
    echo html_writer::start_div('essay-content card mb-4');
    echo html_writer::start_div('card-body');
    echo html_writer::tag('h5', get_string('essay_text', 'local_essaysmaster'), array('class' => 'card-title'));
    echo html_writer::div($attempt_details->essay_text, 'essay-text-display');
    echo html_writer::end_div(); // End card-body
    echo html_writer::end_div(); // End essay-content
}

// AI Feedback
if (!empty($attempt_details->ai_feedback)) {
    echo html_writer::start_div('ai-feedback card mb-4');
    echo html_writer::start_div('card-body');
    echo html_writer::tag('h5', get_string('ai_feedback', 'local_essaysmaster'), array('class' => 'card-title'));
    echo html_writer::div($attempt_details->ai_feedback, 'ai-feedback-display');
    echo html_writer::end_div(); // End card-body
    echo html_writer::end_div(); // End ai-feedback
}

// Original => Improved examples (for validation rounds)
if (in_array($attempt_details->round, [2, 4, 6]) && !empty($attempt_details->examples)) {
    echo html_writer::start_div('original-improved card mb-4');
    echo html_writer::start_div('card-body');
    echo html_writer::tag('h5', get_string('original_improved', 'local_essaysmaster'), array('class' => 'card-title'));
    echo html_writer::div($attempt_details->examples, 'examples-display');
    echo html_writer::end_div(); // End card-body
    echo html_writer::end_div(); // End original-improved
}

// Performance metrics
if (!empty($attempt_details->improvement_score)) {
    echo html_writer::start_div('performance-metrics card mb-4');
    echo html_writer::start_div('card-body');
    echo html_writer::tag('h5', get_string('performance_metrics', 'local_essaysmaster'), array('class' => 'card-title'));
    
    echo html_writer::start_div('row');
    echo html_writer::start_div('col-md-6');
    echo html_writer::div(
        html_writer::tag('strong', number_format($attempt_details->improvement_score, 1) . '%') . '<br>' .
        get_string('improvement_score', 'local_essaysmaster'),
        'metric-card text-center p-3'
    );
    echo html_writer::end_div();
    
    if (!empty($attempt_details->time_spent)) {
        echo html_writer::start_div('col-md-6');
        echo html_writer::div(
            html_writer::tag('strong', gmdate("H:i:s", $attempt_details->time_spent)) . '<br>' .
            get_string('time_spent', 'local_essaysmaster'),
            'metric-card text-center p-3'
        );
        echo html_writer::end_div();
    }
    echo html_writer::end_div(); // End row
    
    echo html_writer::end_div(); // End card-body
    echo html_writer::end_div(); // End performance-metrics
}

// Navigation buttons
echo html_writer::start_div('navigation-buttons mt-4');
echo html_writer::link(
    new moodle_url('/local/essaysmaster/student_detail.php', array('id' => $attempt_details->userid)),
    get_string('back_to_student', 'local_essaysmaster'),
    array('class' => 'btn btn-secondary mr-2')
);
echo html_writer::link(
    new moodle_url('/local/essaysmaster/dashboard.php'),
    get_string('back_to_dashboard', 'local_essaysmaster'),
    array('class' => 'btn btn-primary')
);
echo html_writer::end_div(); // End navigation-buttons

echo html_writer::end_div(); // End essaysmaster-attempt-detail

echo $OUTPUT->footer();
?>