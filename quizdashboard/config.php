<?php
require_once(__DIR__.'/../../config.php');

require_login();
$context = context_system::instance();
require_capability('local/quizdashboard:manage', $context);


$PAGE->set_url(new moodle_url('/local/quizdashboard/config.php'));
$PAGE->set_context($context);
$PAGE->set_title('Essay Auto-Grading Configuration');
$PAGE->set_heading('Essay Auto-Grading Configuration');
$PAGE->set_pagelayout('admin');

// Handle form submission
if (data_submitted() && confirm_sesskey()) {
    $openai_key = optional_param('openai_api_key', '', PARAM_TEXT);
    $google_folder_id = optional_param('google_folder_id', '', PARAM_TEXT);
    $service_account_json = optional_param('service_account_json', '', PARAM_RAW);
    
// Save OpenAI API key with debugging
    if (!empty($openai_key)) {
        // Don't save if it's just the placeholder text
        if ($openai_key === '***HIDDEN***') {
            // User didn't change the key, skip saving
            \core\notification::info('OpenAI API key unchanged.');
        } else {
            // DEBUG: Log what we received
            error_log('DEBUG CONFIG: Received key length: ' . strlen($openai_key));
            error_log('DEBUG CONFIG: Received key starts with: ' . substr($openai_key, 0, 6));
            error_log('DEBUG CONFIG: Raw key (first 20 chars): ' . substr($openai_key, 0, 20));
            
            // Clean the key: remove all whitespace and validate format
            $cleaned_key = preg_replace('/\s+/', '', trim($openai_key));
            
            // DEBUG: Log cleaned version
            error_log('DEBUG CONFIG: Cleaned key length: ' . strlen($cleaned_key));
            error_log('DEBUG CONFIG: Cleaned key starts with: ' . substr($cleaned_key, 0, 6));
            
            // More flexible validation - OpenAI keys can vary in format (old sk- and new sk-proj-)
            if (preg_match('/^sk-[a-zA-Z0-9_-]{20,}$/', $cleaned_key)) {
                set_config('openai_api_key', $cleaned_key, 'local_quizdashboard');
                \core\notification::success('OpenAI API key saved successfully. Length: ' . strlen($cleaned_key));
            } else {
                error_log('DEBUG CONFIG: Validation failed for key: ' . substr($cleaned_key, 0, 10) . '...');
                \core\notification::error('Invalid OpenAI API key format. Received length: ' . strlen($cleaned_key) . ', starts with: ' . substr($cleaned_key, 0, 10));
            }
        }
    }
    
    // Save Google Drive folder ID
    if (!empty($google_folder_id)) {
        set_config('google_drive_folder_id', trim($google_folder_id), 'local_quizdashboard');
        \core\notification::success('Google Drive folder ID saved successfully.');
    }
    
    // Save service account JSON
    if (!empty($service_account_json)) {
        $dataroot = $CFG->dataroot;
        $plugin_dir = $dataroot . '/local_quizdashboard';
        if (!is_dir($plugin_dir)) {
            mkdir($plugin_dir, 0755, true);
        }
        
        $json_path = $plugin_dir . '/service-account.json';
        if (file_put_contents($json_path, $service_account_json)) {
            \core\notification::success('Service account JSON saved successfully.');
        } else {
            \core\notification::error('Failed to save service account JSON.');
        }
    }
    
    redirect($PAGE->url);
}

// Get current settings
$current_openai_key = get_config('local_quizdashboard', 'openai_api_key');
$current_folder_id = get_config('local_quizdashboard', 'google_drive_folder_id');

echo $OUTPUT->header();
?>

<div class="card">
    <div class="card-header">
        <h3>Essay Auto-Grading Configuration</h3>
    </div>
    <div class="card-body">
        <form method="post" action="">
            <input type="hidden" name="sesskey" value="<?php echo sesskey(); ?>">
            
            <div class="form-group row">
                <label for="openai_api_key" class="col-sm-3 col-form-label">OpenAI API Key:</label>
                <div class="col-sm-9">
                    <input type="password" class="form-control" id="openai_api_key" name="openai_api_key" 
                        placeholder="<?php echo $current_openai_key ? 'Enter new key to replace existing one' : 'Enter your OpenAI API key'; ?>"
                        value="">
                    <small class="form-text text-muted">
                        <?php if ($current_openai_key): ?>
                            Status: ✅ API key is configured. Leave blank to keep current key, or enter new key to replace.
                        <?php else: ?>
                            Your OpenAI API key for GPT-4 access (should start with "sk-")
                        <?php endif; ?>
                    </small>
                </div>
            </div>
            
            <div class="form-group row">
                <label for="google_folder_id" class="col-sm-3 col-form-label">Google Drive Folder ID:</label>
                <div class="col-sm-9">
                    <input type="text" class="form-control" id="google_folder_id" name="google_folder_id" 
                           value="<?php echo htmlspecialchars($current_folder_id ?: ''); ?>" 
                           placeholder="Google Drive folder ID for storing feedback files">
                    <small class="form-text text-muted">The folder ID from Google Drive URL where feedback files will be stored</small>
                </div>
            </div>
            
            <div class="form-group row">
                <label for="service_account_json" class="col-sm-3 col-form-label">Service Account JSON:</label>
                <div class="col-sm-9">
                    <textarea class="form-control" id="service_account_json" name="service_account_json" 
                              rows="10" placeholder="Paste your Google service account JSON content here"></textarea>
                    <small class="form-text text-muted">
                        <?php 
                        $json_exists = file_exists($CFG->dataroot . '/local_quizdashboard/service-account.json');
                        echo $json_exists ? 'Status: ✓ Service account JSON file exists' : 'Status: ✗ No service account JSON configured';
                        ?>
                    </small>
                </div>
            </div>
            
            <div class="form-group row">
                <div class="col-sm-9 offset-sm-3">
                    <button type="submit" class="btn btn-primary">Save Settings</button>
                    <a href="<?php echo new moodle_url('/local/quizdashboard/essays.php'); ?>" class="btn btn-secondary">Back to Essays</a>
                </div>
            </div>
        </form>
    </div>
</div>

<div class="card mt-4">
    <div class="card-header">
        <h4>Setup Instructions</h4>
    </div>
    <div class="card-body">
        <h5>1. OpenAI API Key</h5>
        <p>Get your API key from <a href="https://platform.openai.com/api-keys" target="_blank">OpenAI Platform</a></p>
        
        <h5>2. Google Drive Setup</h5>
        <ol>
            <li>Create a Google Cloud Project</li>
            <li>Enable Google Drive API</li>
            <li>Create a Service Account</li>
            <li>Download the JSON key file</li>
            <li>Create a folder in Google Drive and get its ID from the URL</li>
            <li>Share the folder with the service account email</li>
        </ol>
        
        <h5>3. Test Configuration</h5>
        <p>After saving settings, go to the Essay Dashboard and try auto-grading a test essay.</p>
    </div>
</div>

<?php
echo $OUTPUT->footer();
?>