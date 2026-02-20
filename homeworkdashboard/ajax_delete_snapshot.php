<?php
define('AJAX_SCRIPT', true);

require_once('../../config.php');
require_once(__DIR__ . '/classes/homework_manager.php');

require_login();
require_sesskey();
$context = context_system::instance();

// Check capability
if (!has_capability('local/homeworkdashboard:manage', $context) && !is_siteadmin()) {
    echo json_encode(['success' => false, 'message' => 'Permission denied']);
    die();
}

$snapshotids_str = required_param('snapshotids', PARAM_SEQUENCE); // Comma separated IDs
$snapshotids = explode(',', $snapshotids_str);

$response = ['success' => false, 'message' => 'Unknown error'];

try {
    $manager = new \local_homeworkdashboard\homework_manager();
    $deleted_count = 0;
    $failed_count = 0;

    foreach ($snapshotids as $id) {
        if ($manager->delete_snapshot((int)$id)) {
            $deleted_count++;
        } else {
            $failed_count++;
        }
    }

    if ($deleted_count > 0) {
        $response = [
            'success' => true, 
            'message' => "Deleted $deleted_count snapshots." . ($failed_count > 0 ? " Failed to delete $failed_count." : "")
        ];
    } else {
        $response = ['success' => false, 'message' => 'No snapshots were deleted.'];
    }

} catch (Exception $e) {
    $response = ['success' => false, 'message' => $e->getMessage()];
}

echo json_encode($response);
