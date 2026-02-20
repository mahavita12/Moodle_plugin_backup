<?php
define('AJAX_SCRIPT', true);
require(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/moodlelib.php');

// Require login for security
require_login();
require_capability('moodle/site:config', context_system::instance());

echo "<pre>";
echo "Starting email test (WEB MODE)...\n";
echo "SMTP Host: " . $CFG->smtphosts . "\n";
echo "SMTP User: " . $CFG->smtpuser . "\n";
echo "SMTP Secure: " . $CFG->smtpsecure . "\n";

// Enable SMTP debugging for this request
$CFG->debugsmtp = true;

$user = new stdClass();
$user->id = 99999;
$user->email = 'mahavita@gmail.com'; 
$user->firstname = 'Test';
$user->lastname = 'User';
$user->maildisplay = 1;
$user->mailformat = 1;

$from = get_admin();

echo "Attempting to send email to {$user->email}...\n";

try {
    // Capture output buffer to show SMTP debug info
    ob_start();
    $result = email_to_user($user, $from, 'SMTP Test Subject (Web)', 'This is a test email body from the web interface.');
    $debug_output = ob_get_clean();
    
    echo "SMTP DEBUG OUTPUT:\n" . htmlspecialchars($debug_output) . "\n";

    if ($result) {
        echo "SUCCESS: Email accepted by SMTP server.\n";
    } else {
        echo "FAILURE: email_to_user returned false.\n";
    }
} catch (Exception $e) {
    echo "EXCEPTION: " . $e->getMessage() . "\n";
    echo $e->getTraceAsString() . "\n";
}
echo "</pre>";
