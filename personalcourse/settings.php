<?php
defined('MOODLE_INTERNAL') || die();

if ($hassiteconfig) {
    $ADMIN->add('localplugins', new admin_externalpage(
        'local_personalcourse_dashboard',
        get_string('dashboard', 'local_personalcourse'),
        new moodle_url('/local/personalcourse/index.php'),
        'local/personalcourse:viewdashboard'
    ));
}
