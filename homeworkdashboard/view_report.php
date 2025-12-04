<?php
require_once('../../config.php');

$id = required_param('id', PARAM_INT);

require_login();
$context = context_system::instance();
require_capability('local/homeworkdashboard:view', $context);

$report = $DB->get_record('local_homework_reports', ['id' => $id], '*', MUST_EXIST);

// Prevent caching
header("Cache-Control: no-cache, no-store, must-revalidate"); // HTTP 1.1.
header("Pragma: no-cache"); // HTTP 1.0.
header("Expires: 0"); // Proxies.

$PAGE->set_context($context);
$PAGE->set_url(new moodle_url('/local/homeworkdashboard/view_report.php', ['id' => $id]));
$PAGE->set_title($report->subject);
$PAGE->set_heading($report->subject);

echo $OUTPUT->header();

echo '<div class="container mt-4">';
echo '<div class="card">';
// Header removed as requested
echo '<div class="card-body">';

$content = $report->content;
$canmanage = has_capability('local/homeworkdashboard:manage', $context) || is_siteadmin();

if (!$canmanage) {
    // Remove greeting for students
    // If the report has the modern container, strip everything before it (which contains the greeting)
    if (strpos($content, '<div class="homework-report-container"') !== false) {
        $content = strstr($content, '<div class="homework-report-container"');
    } else {
        // Fallback for older reports: try to strip the greeting block using regex
        $content = preg_replace('/<p>To .*?\'s parents,<\/p>.*?GrowMinds Academy Team<\/p>/s', '', $content);
    }
}

echo $content;
echo '</div>';
echo '</div>';
echo '<div class="mt-3">';
echo '<a href="index.php?tab=reports" class="btn btn-secondary">Back to Reports</a>';
echo '</div>';
echo '</div>';

echo $OUTPUT->footer();
