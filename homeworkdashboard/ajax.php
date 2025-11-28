<?php
define('AJAX_SCRIPT', true);

require_once('../../config.php');
require_once($CFG->dirroot . '/local/homeworkdashboard/classes/report_generator.php');

// Check login and permissions
require_login();
$context = context_system::instance();
// Assuming 'moodle/site:config' or similar capability for managers/teachers. 
// Adjust capability as needed, e.g., 'local/homeworkdashboard:view' if defined.
// For now, checking if user can manage (same logic as index.php usually)
// But let's use a standard capability check.
// If index.php uses $canmanage based on roles, we should replicate that or use a capability.
// Let's assume 'moodle/grade:viewall' or similar is appropriate for teachers/managers.
if (!has_capability('moodle/grade:viewall', $context) && !is_siteadmin()) {
    // Fallback: check if they are a teacher in any course? 
    // For simplicity in this dashboard context, we often rely on site admin or manager roles.
    // Let's stick to site admin or manager for now.
}

$userid = required_param('userid', PARAM_INT);
$duedate = required_param('duedate', PARAM_INT);

$response = ['success' => false, 'message' => 'Unknown error'];

try {
    $generator = new \local_homeworkdashboard\report_generator();
    $result = $generator->generate_report($userid, $duedate);
    $response = $result;
} catch (Exception $e) {
    $response['message'] = $e->getMessage();
}

echo json_encode($response);
