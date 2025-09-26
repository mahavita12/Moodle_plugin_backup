<?php
require_once("../../config.php");

require_login();

$PAGE->set_url('/local/essaysmaster/simple_manage.php');
$PAGE->set_context(context_system::instance());
$PAGE->set_title('Essays Master - Quiz Settings');
$PAGE->set_heading('Essays Master Quiz Configuration');

$action = optional_param('action', '', PARAM_ALPHA);
$quizid = optional_param('quizid', 0, PARAM_INT);

// Handle toggle action
if ($action === 'toggle' && $quizid && confirm_sesskey()) {
    $config = $DB->get_record('local_essaysmaster_config', ['quiz_id' => $quizid]);

    if ($config) {
        // Toggle existing config
        $config->is_enabled = $config->is_enabled ? 0 : 1;
        $config->modified = time();
        $DB->update_record('local_essaysmaster_config', $config);
        $status = $config->is_enabled ? 'enabled' : 'disabled';
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
        $status = 'enabled';
    }

    redirect($PAGE->url, "Essays Master {$status} for this quiz.", 2);
}

echo $OUTPUT->header();

echo '<div class="container-fluid">';
echo '<h2>Essays Master Quiz Configuration</h2>';

// Get all quizzes with essay questions
$sql = "SELECT DISTINCT q.id, q.name, c.fullname as course_name, cm.id as cmid,
               emc.is_enabled,
               COUNT(DISTINCT qu.id) as essay_count
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
        GROUP BY q.id, q.name, c.fullname, cm.id, emc.is_enabled
        ORDER BY c.fullname, q.name";

$quizzes = $DB->get_records_sql($sql);

if ($quizzes) {
    echo '<div class="table-responsive">';
    echo '<table class="table table-striped table-bordered">';
    echo '<thead class="thead-dark">';
    echo '<tr>';
    echo '<th>Course</th>';
    echo '<th>Quiz Name</th>';
    echo '<th>CM ID</th>';
    echo '<th>Essay Questions</th>';
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
        $badge_class = $enabled ? 'badge-success' : 'badge-secondary';

        echo '<tr>';
        echo '<td>' . format_string($quiz->course_name) . '</td>';
        echo '<td><strong>' . format_string($quiz->name) . '</strong></td>';
        echo '<td><code>' . $quiz->cmid . '</code></td>';
        echo '<td><span class="badge badge-info">' . $quiz->essay_count . '</span></td>';
        echo '<td><span class="badge ' . $badge_class . '">' . $status . '</span></td>';
        echo '<td>';

        $url = new moodle_url($PAGE->url, [
            'action' => 'toggle',
            'quizid' => $quiz->id,
            'sesskey' => sesskey()
        ]);

        echo '<a href="' . $url . '" class="btn ' . $btn_class . ' btn-sm" ';
        echo 'onclick="return confirm(\'Are you sure you want to ' . strtolower($action_text) . ' Essays Master for this quiz?\');">';
        echo $action_text . '</a>';
        echo '</td>';
        echo '</tr>';
    }

    echo '</tbody>';
    echo '</table>';
    echo '</div>';

    $enabled_count = array_filter($quizzes, function($q) { return $q->is_enabled; });
    echo '<div class="alert alert-info">';
    echo '<strong>Summary:</strong> ' . count($enabled_count) . ' out of ' . count($quizzes) . ' quizzes have Essays Master enabled.';
    echo '</div>';

} else {
    echo '<div class="alert alert-warning">';
    echo '<strong>No essay quizzes found.</strong> Create quizzes with essay questions first.';
    echo '</div>';
}

echo '<div class="mt-4">';
echo '<h3>How Essays Master Works:</h3>';
echo '<div class="card">';
echo '<div class="card-body">';
echo '<ol>';
echo '<li><strong>Enable</strong> Essays Master for quizzes containing essay questions</li>';
echo '<li>When students attempt to <strong>submit</strong> these quizzes, they are redirected to Essays Master</li>';
echo '<li>Students receive <strong>AI feedback</strong> and can improve their essays through multiple levels</li>';
echo '<li>Only after meeting quality standards can they <strong>submit their final essay</strong></li>';
echo '</ol>';
echo '</div>';
echo '</div>';
echo '</div>';

echo '</div>';

echo $OUTPUT->footer();
?>