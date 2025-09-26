<?php
require_once('../../config.php');
require_once($CFG->dirroot . '/local/essaysmaster/classes/dashboard_manager.php');
require_once($CFG->libdir . '/adminlib.php');

$userid = required_param('id', PARAM_INT);

require_login();

$context = context_system::instance();
require_capability('local/essaysmaster:viewdashboard', $context);

$PAGE->set_context($context);
$PAGE->set_url('/local/essaysmaster/student_detail.php', array('id' => $userid));
$PAGE->set_title(get_string('student_detail', 'local_essaysmaster'));
$PAGE->set_heading(get_string('student_detail', 'local_essaysmaster'));
$PAGE->set_pagelayout('admin');

$PAGE->requires->js_call_amd('local_essaysmaster/student_detail', 'init');

// Get student information
$user = $DB->get_record('user', array('id' => $userid, 'deleted' => 0), '*', MUST_EXIST);

$dashboard_manager = new \local_essaysmaster\dashboard_manager();
$student_data = $dashboard_manager->get_student_detail($userid);
$student_attempts = $dashboard_manager->get_student_attempts($userid);

echo $OUTPUT->header();

echo html_writer::start_div('essaysmaster-student-detail');

// Student info header
echo html_writer::start_div('student-header card mb-4');
echo html_writer::start_div('card-body');
echo html_writer::tag('h3', fullname($user), array('class' => 'mb-2'));
echo html_writer::tag('p', $user->email, array('class' => 'text-muted mb-3'));

// Student stats grid
echo html_writer::start_div('row');

echo html_writer::start_div('col-md-3');
echo html_writer::start_div('stat-card text-center');
echo html_writer::tag('h4', $student_data['total_attempts'], array('class' => 'stat-number text-primary'));
echo html_writer::tag('p', get_string('total_attempts', 'local_essaysmaster'), array('class' => 'stat-label'));
echo html_writer::end_div();
echo html_writer::end_div();

echo html_writer::start_div('col-md-3');
echo html_writer::start_div('stat-card text-center');
echo html_writer::tag('h4', $student_data['completed_rounds'], array('class' => 'stat-number text-success'));
echo html_writer::tag('p', get_string('completed_rounds', 'local_essaysmaster'), array('class' => 'stat-label'));
echo html_writer::end_div();
echo html_writer::end_div();

echo html_writer::start_div('col-md-3');
echo html_writer::start_div('stat-card text-center');
echo html_writer::tag('h4', number_format($student_data['avg_improvement'], 1) . '%', array('class' => 'stat-number text-info'));
echo html_writer::tag('p', get_string('avg_improvement', 'local_essaysmaster'), array('class' => 'stat-label'));
echo html_writer::end_div();
echo html_writer::end_div();

echo html_writer::start_div('col-md-3');
echo html_writer::start_div('stat-card text-center');
echo html_writer::tag('h4', $student_data['last_activity'], array('class' => 'stat-number text-secondary'));
echo html_writer::tag('p', get_string('last_activity', 'local_essaysmaster'), array('class' => 'stat-label'));
echo html_writer::end_div();
echo html_writer::end_div();

echo html_writer::end_div(); // End stats row
echo html_writer::end_div(); // End card-body
echo html_writer::end_div(); // End student-header

// Filter controls
echo html_writer::start_div('filter-controls card mb-4');
echo html_writer::start_div('card-body');
echo html_writer::tag('h5', get_string('filter_attempts', 'local_essaysmaster'), array('class' => 'card-title'));

echo html_writer::start_div('row');
echo html_writer::start_div('col-md-4');
echo html_writer::label(get_string('quiz_filter', 'local_essaysmaster'), 'quiz-filter');
echo html_writer::select(
    array('' => get_string('all_quizzes', 'local_essaysmaster')) + $dashboard_manager->get_quiz_options(),
    'quiz_filter',
    '',
    array(),
    array('id' => 'quiz-filter', 'class' => 'form-control')
);
echo html_writer::end_div();

echo html_writer::start_div('col-md-4');
echo html_writer::label(get_string('round_filter', 'local_essaysmaster'), 'round-filter');
echo html_writer::select(
    array(
        '' => get_string('all_rounds', 'local_essaysmaster'),
        '1' => get_string('round_1', 'local_essaysmaster'),
        '2' => get_string('round_2', 'local_essaysmaster'),
        '3' => get_string('round_3', 'local_essaysmaster'),
        '4' => get_string('round_4', 'local_essaysmaster'),
        '5' => get_string('round_5', 'local_essaysmaster'),
        '6' => get_string('round_6', 'local_essaysmaster')
    ),
    'round_filter',
    '',
    array(),
    array('id' => 'round-filter', 'class' => 'form-control')
);
echo html_writer::end_div();

echo html_writer::start_div('col-md-4');
echo html_writer::label(get_string('date_range', 'local_essaysmaster'), 'date-range');
echo html_writer::select(
    array(
        '' => get_string('all_time', 'local_essaysmaster'),
        '7' => get_string('last_7_days', 'local_essaysmaster'),
        '30' => get_string('last_30_days', 'local_essaysmaster'),
        '90' => get_string('last_90_days', 'local_essaysmaster')
    ),
    'date_range',
    '',
    array(),
    array('id' => 'date-range', 'class' => 'form-control')
);
echo html_writer::end_div();
echo html_writer::end_div(); // End filter row

echo html_writer::end_div(); // End card-body
echo html_writer::end_div(); // End filter-controls

// Attempts table
echo html_writer::start_div('attempts-table card');
echo html_writer::start_div('card-body');
echo html_writer::tag('h5', get_string('attempt_history', 'local_essaysmaster'), array('class' => 'card-title'));

if (!empty($student_attempts)) {
    $table = new html_table();
    $table->head = array(
        get_string('quiz_name', 'local_essaysmaster'),
        get_string('attempt_date', 'local_essaysmaster'),
        get_string('round', 'local_essaysmaster'),
        get_string('status', 'local_essaysmaster'),
        get_string('improvement_score', 'local_essaysmaster'),
        get_string('actions', 'local_essaysmaster')
    );
    $table->attributes['class'] = 'table table-striped';
    $table->attributes['id'] = 'attempts-table';
    
    foreach ($student_attempts as $attempt) {
        $quiz_link = html_writer::link(
            new moodle_url('/mod/quiz/view.php', array('id' => $attempt->quiz_cmid)),
            $attempt->quiz_name,
            array('target' => '_blank')
        );
        
        $date_formatted = userdate($attempt->timemodified, get_string('strftimedate', 'core_langconfig'));
        
        $round_badge = html_writer::tag('span', 
            get_string('round_num', 'local_essaysmaster', $attempt->round),
            array('class' => 'badge badge-primary')
        );
        
        $status_class = ($attempt->status === 'Pass') ? 'badge-success' : 'badge-warning';
        $status_badge = html_writer::tag('span', $attempt->status, array('class' => 'badge ' . $status_class));
        
        $improvement = $attempt->improvement_score ? number_format($attempt->improvement_score, 1) . '%' : '-';
        
        $view_link = html_writer::link(
            new moodle_url('/local/essaysmaster/view_attempt.php', array('id' => $attempt->id)),
            get_string('view_details', 'local_essaysmaster'),
            array('class' => 'btn btn-sm btn-outline-primary')
        );
        
        $table->data[] = array(
            $quiz_link,
            $date_formatted,
            $round_badge,
            $status_badge,
            $improvement,
            $view_link
        );
    }
    
    echo html_writer::table($table);
} else {
    echo html_writer::div(
        get_string('no_attempts_found', 'local_essaysmaster'),
        'alert alert-info'
    );
}

echo html_writer::end_div(); // End card-body
echo html_writer::end_div(); // End attempts-table

// Back button
echo html_writer::start_div('mt-3');
echo html_writer::link(
    new moodle_url('/local/essaysmaster/dashboard.php'),
    get_string('back_to_dashboard', 'local_essaysmaster'),
    array('class' => 'btn btn-secondary')
);
echo html_writer::end_div();

echo html_writer::end_div(); // End essaysmaster-student-detail

echo $OUTPUT->footer();
?>