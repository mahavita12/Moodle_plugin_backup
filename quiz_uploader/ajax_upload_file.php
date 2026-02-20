<?php
/**
 * AJAX file upload endpoint
 * Uploads XML file to draft area and returns draft_itemid
 */

define('AJAX_SCRIPT', true);

require_once(__DIR__ . '/../../config.php');

require_login();
require_sesskey();

$context = context_system::instance();
require_capability('local/quiz_uploader:uploadquiz', $context);

header('Content-Type: application/json');

try {
    // Check if file was uploaded
    if (empty($_FILES['xmlfile'])) {
        throw new Exception('No file uploaded');
    }

    $file = $_FILES['xmlfile'];

    // Check for upload errors
    if ($file['error'] !== UPLOAD_ERR_OK) {
        throw new Exception('File upload error: ' . $file['error']);
    }

    // Validate file type
    if (!preg_match('/\.xml$/i', $file['name'])) {
        throw new Exception('Only XML files are allowed');
    }

    // Generate a unique draft itemid (similar to what Moodle does internally)
    // Draft itemids are just unique integers
    $draftitemid = (int) (time() . rand(1000, 9999));

    // Get user context
    $usercontext = context_user::instance($USER->id);

    // Store file in draft area
    $fs = get_file_storage();

    $filerecord = [
        'contextid' => $usercontext->id,
        'component' => 'user',
        'filearea' => 'draft',
        'itemid' => $draftitemid,
        'filepath' => '/',
        'filename' => $file['name'],
        'userid' => $USER->id
    ];

    // Create file from uploaded file
    $storedfile = $fs->create_file_from_pathname($filerecord, $file['tmp_name']);

    if (!$storedfile) {
        throw new Exception('Failed to store file');
    }

    echo json_encode([
        'success' => true,
        'draftitemid' => $draftitemid,
        'filename' => $file['name'],
        'filesize' => $file['size']
    ]);

} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
