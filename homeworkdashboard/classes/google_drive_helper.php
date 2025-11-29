<?php
namespace local_homeworkdashboard;

defined('MOODLE_INTERNAL') || die();

/**
 * Helper class for Google Drive interactions.
 */
class google_drive_helper {

    private $service_account_path;
    private $google_folder_id;

    public function __construct() {
        global $CFG;
        // Reuse the service account from quizdashboard if available
        $this->service_account_path = $CFG->dataroot . '/local_quizdashboard/service-account.json';
        
        // Get folder ID from settings
        $config = get_config('local_homeworkdashboard');
        // Use provided ID if config is empty, or override if needed. 
        // User explicitly requested this ID: 1Kx4eQ2NDQ2rriVDP0VIiTdP-NgcHDrRL
        $this->google_folder_id = '1Kx4eQ2NDQ2rriVDP0VIiTdP-NgcHDrRL';
    }

    /**
     * Check if Google Drive is configured.
     */
    public function is_configured(): bool {
        return !empty($this->google_folder_id) && file_exists($this->service_account_path);
    }

    /**
     * Upload content to Google Drive.
     * 
     * @param string $content HTML content to upload
     * @param string $filename Desired filename (without extension)
     * @return string|null Web view link or null on failure
     */
    public function upload_html_content(string $content, string $filename): ?string {
        global $CFG;

        if (!$this->is_configured()) {
            error_log("local_homeworkdashboard: Google Drive not configured.");
            return null;
        }

        // Create a temporary file
        // Use make_temp_directory to ensure correct permissions and path
        $temp_dir = make_temp_directory('local_homeworkdashboard_reports');

        $clean_filename = $this->sanitize_filename($filename);
        $file_path = $temp_dir . '/' . $clean_filename . '.html';

        if (file_put_contents($file_path, $content) === false) {
            error_log("local_homeworkdashboard: Failed to write temp file: {$file_path}");
            return null;
        }

        $link = $this->upload_file($file_path, 'text/html');

        // Cleanup
        if (file_exists($file_path)) {
            unlink($file_path);
        }

        return $link;
    }

    /**
     * Upload a file to Google Drive.
     */
    private function upload_file(string $file_path, string $mime_type): ?string {
        global $CFG;

        $vendor_path = $CFG->dirroot . '/vendor/autoload.php';
        if (!file_exists($vendor_path)) {
            error_log("local_homeworkdashboard: Google API client not found at {$vendor_path}");
            return null;
        }

        require_once($vendor_path);

        try {
            $client = new \Google\Client();
            $client->setAuthConfig($this->service_account_path);
            $client->addScope(\Google\Service\Drive::DRIVE_FILE);

            $service = new \Google\Service\Drive($client);

            $file_metadata = new \Google\Service\Drive\DriveFile([
                'name' => basename($file_path),
                'parents' => [$this->google_folder_id]
            ]);

            $uploaded_file = $service->files->create($file_metadata, [
                'data' => file_get_contents($file_path),
                'mimeType' => $mime_type,
                'uploadType' => 'multipart',
                'fields' => 'id, webViewLink'
            ]);

            if (!$uploaded_file || !$uploaded_file->id) {
                error_log("local_homeworkdashboard: Drive upload failed - no ID returned.");
                return null;
            }

            // Set public permissions (reader) - mimicking quizdashboard behavior
            // Note: You might want to restrict this in production if sensitive
            $service->permissions->create($uploaded_file->id, new \Google\Service\Drive\Permission([
                'type' => 'anyone',
                'role' => 'reader'
            ]));

            $link = "https://drive.google.com/uc?export=download&id=" . $uploaded_file->id;
            // Or use webViewLink if preferred: $uploaded_file->webViewLink;
            
            return $link;

        } catch (\Exception $e) {
            error_log("local_homeworkdashboard: Drive API Error: " . $e->getMessage());
            return null;
        }
    }

    private function sanitize_filename($filename) {
        $clean = preg_replace('/[^a-zA-Z0-9_-]/', '_', $filename);
        $clean = preg_replace('/_+/', '_', $clean);
        return substr(trim($clean, '_-'), 0, 200);
    }
}
