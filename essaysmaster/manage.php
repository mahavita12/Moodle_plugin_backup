<?php
require_once("../../config.php");
require_once($CFG->libdir . '/adminlib.php');

admin_externalpage_setup('local_essaysmaster_manage');

$action = optional_param('action', '', PARAM_ALPHA);
$quizid = optional_param('quizid', 0, PARAM_INT);

if ($action === 'toggle' && $quizid) {
    require_sesskey();

    $config = $DB->get_record('local_essaysmaster_config', ['quiz_id' => $quizid]);

    if ($config) {
        // Toggle existing config
        $config->is_enabled = $config->is_enabled ? 0 : 1;
        $config->modified = time();
        $DB->update_record('local_essaysmaster_config', $config);
    } else {
        // Create new config
        $config = new stdClass();
        $config->quiz_id = $quizid;
        $config->is_enabled = 1;
        $config->max_levels = 3;
        $config->pass_score = 80;
        $config->created = time();
        $config->modified = time();
        $DB->insert_record('local_essaysmaster_config', $config);
    }

    redirect($PAGE->url);
}

$PAGE->set_title('Essays Master Management');
$PAGE->set_heading('Essays Master Management');

echo $OUTPUT->header();
echo $OUTPUT->heading('Essays Master Quiz Configuration');

// Get all quizzes with essay questions
$sql = "SELECT DISTINCT q.id, q.name, c.fullname as course_name, cm.id as cmid,
               emc.is_enabled
        FROM {quiz} q
        JOIN {course_modules} cm ON cm.instance = q.id AND cm.module = (
            SELECT id FROM {modules} WHERE name = 'quiz'
        )
        JOIN {course} c ON c.id = q.course
        JOIN {quiz_slots} qs ON qs.quizid = q.id
        JOIN {question_references} qr ON qr.itemid = qs.id
            AND qr.component = 'mod_quiz'
            AND qr.questionarea = 'slot'
        JOIN {question_bank_entries} qbe ON qr.questionbankentryid = qbe.id
        JOIN {question_versions} qv ON qbe.id = qv.questionbankentryid
        JOIN {question} qu ON qv.questionid = qu.id
        LEFT JOIN {local_essaysmaster_config} emc ON emc.quiz_id = q.id
        WHERE qu.qtype = 'essay'
        ORDER BY c.fullname, q.name";

$quizzes = $DB->get_records_sql($sql);

if ($quizzes) {
    echo '<table class="table table-striped">';
    echo '<thead>';
    echo '<tr>';
    echo '<th>Course</th>';
    echo '<th>Quiz</th>';
    echo '<th>Course Module ID</th>';
    echo '<th>Essays Master Status</th>';
    echo '<th>Action</th>';
    echo '</tr>';
    echo '</thead>';
    echo '<tbody>';

    foreach ($quizzes as $quiz) {
        $enabled = $quiz->is_enabled ? true : false;
        $status = $enabled ? 'ENABLED' : 'DISABLED';
        $action_text = $enabled ? 'Disable' : 'Enable';
        $btn_class = $enabled ? 'btn-danger' : 'btn-success';

        echo '<tr>';
        echo '<td>' . format_string($quiz->course_name) . '</td>';
        echo '<td>' . format_string($quiz->name) . '</td>';
        echo '<td>' . $quiz->cmid . '</td>';
        echo '<td><span class="badge badge-' . ($enabled ? 'success' : 'secondary') . '">' . $status . '</span></td>';
        echo '<td>';
        echo '<a href="' . $PAGE->url . '?action=toggle&quizid=' . $quiz->id . '&sesskey=' . sesskey() . '" ';
        echo 'class="btn ' . $btn_class . ' btn-sm">' . $action_text . '</a>';
        echo '</td>';
        echo '</tr>';
    }

    echo '</tbody>';
    echo '</table>';
} else {
    echo '<div class="alert alert-info">No quizzes with essay questions found.</div>';
}

echo '<div class="mt-4">';
echo '<h3>How to use Essays Master:</h3>';
echo '<ol>';
echo '<li>Enable Essays Master for quizzes that contain essay questions</li>';
echo '<li>When students submit these quizzes, they will be redirected to the Essays Master interface</li>';
echo '<li>Students will receive feedback and can improve their essays through multiple levels</li>';
echo '<li>Only after reaching the required standard can they submit their final essay</li>';
echo '</ol>';
echo '</div>';

echo $OUTPUT->footer();
?>