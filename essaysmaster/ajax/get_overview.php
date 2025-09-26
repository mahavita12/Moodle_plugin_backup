<?php
require_once('../../../config.php');
require_once($CFG->dirroot . '/local/essaysmaster/classes/dashboard_manager.php');

require_login();
require_sesskey();

$context = context_system::instance();
require_capability('local/essaysmaster:viewdashboard', $context);

header('Content-Type: application/json');

try {
    $dashboard_manager = new \local_essaysmaster\dashboard_manager();
    $stats = $dashboard_manager->get_overview_stats();
    
    echo json_encode([
        'success' => true,
        'data' => $stats
    ]);
    
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
?>