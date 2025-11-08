<?php
defined('MOODLE_INTERNAL') || die();

if ($hassiteconfig) {
    $settingspage = new admin_settingpage('local_personalcourse_settings', get_string('settings', 'local_personalcourse'));
    $defaultmap = "/\\bthinking\\b/i => Thinking\n/\\bmath(?:ematics)?\\b/i => Math\n/\\bread(?:ing)?\\b/i => Reading\n/\\bwriting\\b/i => Writing";
    $settingspage->add(new admin_setting_configtextarea(
        'local_personalcourse/subjectmap',
        get_string('setting_subjectmap', 'local_personalcourse'),
        get_string('setting_subjectmap_desc', 'local_personalcourse'),
        $defaultmap
    ));
    $settingspage->add(new admin_setting_configtext(
        'local_personalcourse/coderegex',
        get_string('setting_coderegex', 'local_personalcourse'),
        get_string('setting_coderegex_desc', 'local_personalcourse'),
        '/\(([A-Za-z0-9-]{2,})\)\s*$'
    ));
    $ADMIN->add('localplugins', $settingspage);

    $ADMIN->add('localplugins', new admin_externalpage(
        'local_personalcourse_dashboard',
        get_string('dashboard', 'local_personalcourse'),
        new moodle_url('/local/personalcourse/index.php'),
        'local/personalcourse:viewdashboard'
    ));
}
