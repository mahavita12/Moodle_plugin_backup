<?php
define('CLI_SCRIPT', true);
require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/clilib.php');

// Get a user to test with (e.g., a student)
// You might need to adjust the username or id
$user = $DB->get_record('user', ['username' => 'jamie.mun.01'], '*', IGNORE_MISSING);

if (!$user) {
    mtrace("User 'jamie.mun.01' not found. Listing first 5 users:");
    $users = $DB->get_records('user', null, '', '*', 0, 5);
    foreach ($users as $u) {
        mtrace(" - " . $u->username . " (ID: " . $u->id . ")");
    }
    exit(1);
}

mtrace("Found user: " . $user->username . " (ID: " . $user->id . ")");

// Load profile fields
require_once($CFG->dirroot . '/user/profile/lib.php');
profile_load_data($user);

mtrace("Profile fields:");
foreach ($user as $key => $value) {
    if (strpos($key, 'profile_field_') === 0) {
        mtrace(" - " . $key . ": " . $value);
    }
}
