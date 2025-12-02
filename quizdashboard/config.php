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
    $provider = optional_param('provider', 'anthropic', PARAM_TEXT);
    $openai_key = optional_param('openai_api_key', '', PARAM_TEXT);
    $openai_model = optional_param('openai_model', 'gpt-5', PARAM_TEXT);
    $anthropic_key = optional_param('anthropic_apikey', '', PARAM_TEXT);
    $anthropic_model = optional_param('anthropic_model', 'sonnet-4', PARAM_TEXT);
    $google_folder_id = optional_param('google_folder_id', '', PARAM_TEXT);
    // Similarity settings
    $similarity_threshold = optional_param('similarity_threshold', '', PARAM_INT);
    $similarity_autozero = optional_param('similarity_autozero', 0, PARAM_INT);
    $similarity_warning_text = optional_param('similarity_warning_text', '', PARAM_RAW);
    $service_account_json = optional_param('service_account_json', '', PARAM_RAW);
    
    // Save provider
    set_config('provider', $provider, 'local_quizdashboard');
    
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
    
    // Save Anthropic API key
    if (!empty($anthropic_key)) {
        set_config('anthropic_apikey', trim($anthropic_key), 'local_quizdashboard');
        \core\notification::success('Anthropic API key saved successfully.');
    }
    
    // Save Anthropic model
    if (!empty($anthropic_model)) {
        set_config('anthropic_model', trim($anthropic_model), 'local_quizdashboard');
        \core\notification::success('Anthropic model saved successfully.');
    }
    
    // Save Google Drive folder ID
    if (!empty($google_folder_id)) {
        set_config('google_drive_folder_id', trim($google_folder_id), 'local_quizdashboard');
        \core\notification::success('Google Drive folder ID saved successfully.');
    }
    
    // Save service account JSON
    // Save similarity settings (empty values are allowed to fall back to defaults)
    if ($similarity_threshold !== '') {
        $st = max(0, min(100, (int)$similarity_threshold));
        set_config('similarity_threshold', $st, 'local_quizdashboard');
        \core\notification::success('Similarity threshold saved: ' . $st . '%');
    }
    set_config('similarity_autozero', $similarity_autozero ? 1 : 0, 'local_quizdashboard');
    if ($similarity_warning_text !== '') {
        set_config('similarity_warning_text', trim($similarity_warning_text), 'local_quizdashboard');
        \core\notification::success('Similarity warning text saved.');
    }
    // Save OpenAI model
    if (!empty($openai_model)) {
        set_config('openai_model', trim($openai_model), 'local_quizdashboard');
        \core\notification::success('OpenAI model saved successfully.');
    }
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
$current_provider = get_config('local_quizdashboard', 'provider') ?: 'anthropic';
$current_openai_key = get_config('local_quizdashboard', 'openai_api_key');
$current_anthropic_key = get_config('local_quizdashboard', 'anthropic_apikey');
$current_anthropic_model = get_config('local_quizdashboard', 'anthropic_model') ?: 'sonnet-4';
$current_folder_id = get_config('local_quizdashboard', 'google_drive_folder_id');
$current_openai_model = get_config('local_quizdashboard', 'openai_model') ?: 'gpt-5';
// Similarity current values with defaults
$cfg_similarity_threshold = (int)(get_config('local_quizdashboard', 'similarity_threshold') ?: 90);
$cfg_similarity_autozero = (int)(get_config('local_quizdashboard', 'similarity_autozero') ?? 1);
$cfg_similarity_warning_text = get_config('local_quizdashboard', 'similarity_warning_text');
if ($cfg_similarity_warning_text === false || $cfg_similarity_warning_text === null || $cfg_similarity_warning_text === '') {
    $cfg_similarity_warning_text = 'Similarity violation detected (copying previous revision). All category scores and the final score have been set to 0. Resubmissions must reflect your own work and improvements.';
}

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
                <label for="provider" class="col-sm-3 col-form-label">AI Provider:</label>
                <div class="col-sm-9">
                    <select class="form-control" id="provider" name="provider">
                        <option value="anthropic" <?php echo ($current_provider === 'anthropic') ? 'selected' : ''; ?>>Anthropic (Claude)</option>
                        <option value="openai" <?php echo ($current_provider === 'openai') ? 'selected' : ''; ?>>OpenAI (GPT)</option>
                    </select>
                    <small class="form-text text-muted">Choose AI provider for essay grading</small>
                </div>
            </div>

            <div class="form-group row">
                <label for="anthropic_apikey" class="col-sm-3 col-form-label">Anthropic API Key:</label>
                <div class="col-sm-9">
                    <input type="password" class="form-control" id="anthropic_apikey" name="anthropic_apikey" 
                        placeholder="<?php echo $current_anthropic_key ? 'Enter new key to replace existing one' : 'Enter your Anthropic API key'; ?>"
                        value="">
                    <small class="form-text text-muted">
                        <?php if ($current_anthropic_key): ?>
                            Status: ✅ Anthropic API key is configured. Leave blank to keep current key.
                        <?php else: ?>
                            Your Anthropic API key for Claude access
                        <?php endif; ?>
                    </small>
                </div>
            </div>

            <div class="form-group row">
                <label for="anthropic_model" class="col-sm-3 col-form-label">Anthropic Model:</label>
                <div class="col-sm-9">
                    <input type="text" class="form-control" id="anthropic_model" name="anthropic_model" 
                           value="<?php echo htmlspecialchars($current_anthropic_model); ?>">
                    <small class="form-text text-muted">Default: sonnet-4 (maps to Claude 4 Sonnet)</small>
                </div>
            </div>
            
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
                <label for="openai_model" class="col-sm-3 col-form-label">OpenAI Model:</label>
                <div class="col-sm-9">
                    <input type="text" class="form-control" id="openai_model" name="openai_model" 
                           value="<?php echo htmlspecialchars($current_openai_model); ?>">
                    <small class="form-text text-muted">Default: gpt-5. Override if needed (e.g., gpt-4o).</small>
                </div>
            </div>

            <hr>
            <h4>Similarity Policy</h4>
            <div class="form-group row">
                <label for="similarity_threshold" class="col-sm-3 col-form-label">Similarity Threshold (%)</label>
                <div class="col-sm-3">
                    <input type="number" min="0" max="100" class="form-control" id="similarity_threshold" name="similarity_threshold" value="<?php echo (int)$cfg_similarity_threshold; ?>">
                    <small class="form-text text-muted">Default 70. Resubmission ≥ threshold is penalized.</small>
                </div>
            </div>
            <div class="form-group row">
                <label for="similarity_autozero" class="col-sm-3 col-form-label">Auto-zero on Violation</label>
                <div class="col-sm-9">
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" id="similarity_autozero" name="similarity_autozero" value="1" <?php echo $cfg_similarity_autozero ? 'checked' : ''; ?>>
                        <label class="form-check-label" for="similarity_autozero">Set all category scores and final score to 0 when threshold is met</label>
                    </div>
                </div>
            </div>
            <div class="form-group row">
                <label for="similarity_warning_text" class="col-sm-3 col-form-label">Warning Banner Text</label>
                <div class="col-sm-9">
                    <textarea class="form-control" id="similarity_warning_text" name="similarity_warning_text" rows="3"><?php echo htmlspecialchars($cfg_similarity_warning_text); ?></textarea>
                    <small class="form-text text-muted">Shown at the top of resubmission feedback when penalized.</small>
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