<?php
require_once(__DIR__.'/../../config.php');
require_once($CFG->libdir.'/formslib.php');

class check_comment_form extends moodleform {
    public function definition() {
        $mform = $this->_form;
        $mform->addElement('text', 'attemptid', 'Enter Quiz Attempt ID');
        $mform->setType('attemptid', PARAM_INT);
        $mform->addRule('attemptid', null, 'required', null, 'client');
        $this->add_action_buttons(true, 'Check Comment');
    }
}

require_login();
$context = context_system::instance();
require_capability('local/quizdashboard:view', $context);

$PAGE->set_url(new moodle_url('/local/quizdashboard/check_comment.php'));
$PAGE->set_context($context);
$PAGE->set_title('Database Comment Checker');
$PAGE->set_heading('Database Comment Checker');
$PAGE->set_pagelayout('admin');

echo $OUTPUT->header();

$mform = new check_comment_form();

if ($mform->is_cancelled()) {
    redirect(new moodle_url('/local/quizdashboard/essays.php'));
} else if ($fromform = $mform->get_data()) {
    global $DB;
    $attemptid = $fromform->attemptid;

    echo $OUTPUT->heading('Checking comment for Attempt ID: ' . $attemptid);

    $sql = "
        SELECT cmt.id, cmt.value
        FROM {quiz_attempts} quiza
        JOIN {question_usages} qu           ON qu.id = quiza.uniqueid
        JOIN {question_attempts} qa         ON qa.questionusageid = qu.id
        JOIN {question} q                   ON q.id = qa.questionid AND q.qtype = 'essay'
        JOIN {question_attempt_steps} qas   ON qas.questionattemptid = qa.id
          AND qas.sequencenumber = (
            SELECT MAX(x.sequencenumber)
            FROM {question_attempt_steps} x
            WHERE x.questionattemptid = qa.id
          )
        JOIN {question_attempt_step_data} cmt
               ON cmt.attemptstepid = qas.id AND cmt.name = 'comment'
        WHERE quiza.id = :attemptid
        ORDER BY qa.slot
        LIMIT 10
    ";
    
    try {
        $record = $DB->get_record_sql($sql, ['attemptid' => $attemptid]);
        if ($record) {
            echo $OUTPUT->box_start('generalbox boxwidthwide boxaligncenter');
            echo '<strong>Found Record ID:</strong> ' . $record->id . '<br>';
            echo '<strong>Raw Value in Database:</strong>';
            echo '<pre>' . htmlspecialchars($record->value) . '</pre>';
            echo $OUTPUT->box_end();
        } else {
            echo $OUTPUT->notification('No comment record found in the database for that attempt ID.');
        }
    } catch (\Exception $e) {
        echo $OUTPUT->notification('An error occurred while querying the database: ' . $e->getMessage());
    }

}

$mform->display();

echo $OUTPUT->footer();
