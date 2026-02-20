<?php
defined('MOODLE_INTERNAL') || die();

// Register an admin external page so the Quiz Dashboard config appears under Local plugins.
if ($hassiteconfig) {
    $ADMIN->add('localplugins', new admin_externalpage(
        'local_quizdashboard_config',
        get_string('pluginname', 'local_quizdashboard'),
        new moodle_url('/local/quizdashboard/config.php'),
        'moodle/site:config'
    ));
}