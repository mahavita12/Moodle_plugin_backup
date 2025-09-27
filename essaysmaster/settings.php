<?php
defined('MOODLE_INTERNAL') || die();

// Register an admin external page so the Essays Master config appears under Local plugins.
if ($hassiteconfig) {
    $ADMIN->add('localplugins', new admin_externalpage(
        'local_essaysmaster_config',
        get_string('pluginname', 'local_essaysmaster'),
        new moodle_url('/local/essaysmaster/config.php'),
        'moodle/site:config'
    ));
}


