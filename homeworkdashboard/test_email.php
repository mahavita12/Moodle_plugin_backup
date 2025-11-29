<?php
define('CLI_SCRIPT', true);
require(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/moodlelib.php');

// Enable SMTP debugging
$CFG->debug = E_ALL;
$CFG->debugdisplay = 1;
$CFG->debugsmtp = true; // Force SMTP debug output

echo "Starting email test...\n";
echo "SMTP Host: " . $CFG->smtphosts . "\n";
echo "SMTP User: " . $CFG->smtpuser . "\n";
echo "SMTP Secure: " . $CFG->smtpsecure . "\n";

$user = new stdClass();
$user->id = 99999;
$user->email = 'mahavita@gmail.com'; // User's email from screenshot
$user->firstname = 'Test';
$user->lastname = 'User';
$user->maildisplay = 1;
$user->mailformat = 1;

$from = get_admin();

echo "Attempting to send email to {$user->email}...\n";

try {
    $result = email_to_user($user, $from, 'SMTP Test Subject', 'This is a test email body.');
    if ($result) {
        echo "SUCCESS: Email accepted by SMTP server.\n";
    } else {
        echo "FAILURE: email_to_user returned false.\n";
    }
} catch (Exception $e) {
    echo "EXCEPTION: " . $e->getMessage() . "\n";
    echo $e->getTraceAsString() . "\n";
}
