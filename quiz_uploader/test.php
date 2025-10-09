<?php
require_once(__DIR__ . '/../../config.php');
require_login();
$PAGE->set_context(context_system::instance());
$PAGE->set_url('/local/quiz_uploader/test.php');
$PAGE->set_title('Test Page');
echo $OUTPUT->header();
echo '<h1>Test Page Works</h1>';
echo '<p>If you see this, basic page rendering is working.</p>';
echo $OUTPUT->footer();
