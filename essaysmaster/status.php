<?php
require_once("../../config.php");

require_login();
require_capability('moodle/site:config', context_system::instance());

$PAGE->set_url('/local/essaysmaster/status.php');
$PAGE->set_context(context_system::instance());
$PAGE->set_title('Essays Master Status');
$PAGE->set_heading('Essays Master Status');

echo $OUTPUT->header();

echo '<h2>Essays Master Configuration Status</h2>';

// Get all quizzes with Essays Master enabled
$sql = "SELECT q.id, q.name, c.fullname as course_name, cm.id as cmid,
               emc.is_enabled, emc.max_levels, emc.pass_score
        FROM {quiz} q
        JOIN {course_modules} cm ON cm.instance = q.id AND cm.module = (
            SELECT id FROM {modules} WHERE name = 'quiz'
        )
        JOIN {course} c ON c.id = q.course
        JOIN {local_essaysmaster_config} emc ON emc.quiz_id = q.id
        WHERE emc.is_enabled = 1
        ORDER BY c.fullname, q.name";

$enabled_quizzes = $DB->get_records_sql($sql);

if ($enabled_quizzes) {
    echo '<h3>Quizzes with Essays Master Enabled:</h3>';
    echo '<table class="table table-striped">';
    echo '<tr><th>Course</th><th>Quiz Name</th><th>Course Module ID</th><th>Max Levels</th><th>Pass Score</th></tr>';

    foreach ($enabled_quizzes as $quiz) {
        echo '<tr>';
        echo '<td>' . format_string($quiz->course_name) . '</td>';
        echo '<td>' . format_string($quiz->name) . '</td>';
        echo '<td>' . $quiz->cmid . '</td>';
        echo '<td>' . ($quiz->max_levels ?: 'Default') . '</td>';
        echo '<td>' . ($quiz->pass_score ?: 'Default') . '%</td>';
        echo '</tr>';
    }
    echo '</table>';

    echo '<div class="alert alert-success">Essays Master is enabled for ' . count($enabled_quizzes) . ' quiz(es).</div>';
} else {
    echo '<div class="alert alert-warning">No quizzes have Essays Master enabled.</div>';
}

// Plugin status
$plugin_enabled = get_config('local_essaysmaster', 'enabled');
echo '<h3>Plugin Status:</h3>';
echo '<p>Global Enable Status: ' . ($plugin_enabled ? 'ENABLED' : 'DISABLED') . '</p>';

echo '<h3>Quick Actions:</h3>';
echo '<p><a href="manage.php" class="btn btn-primary">Manage Quiz Settings</a></p>';

echo $OUTPUT->footer();
?>