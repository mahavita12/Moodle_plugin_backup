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
    // Performance/UX toggles for safe rollout of deferred rebuilds.
    $settingspage->add(new admin_setting_configcheckbox(
        'local_personalcourse/defer_modinfo_rebuilds',
        get_string('setting_defer_modinfo_rebuilds', 'local_personalcourse'),
        get_string('setting_defer_modinfo_rebuilds_desc', 'local_personalcourse'),
        1
    ));
    $settingspage->add(new admin_setting_configtext(
        'local_personalcourse/modinfo_rebuild_min_interval',
        get_string('setting_modinfo_rebuild_min_interval', 'local_personalcourse'),
        get_string('setting_modinfo_rebuild_min_interval_desc', 'local_personalcourse'),
        '120'
    ));
    $settingspage->add(new admin_setting_configcheckbox(
        'local_personalcourse/show_async_notice_on_submit',
        get_string('setting_show_async_notice_on_submit', 'local_personalcourse'),
        get_string('setting_show_async_notice_on_submit_desc', 'local_personalcourse'),
        1
    ));
    // Optional deferrals and scope limits for extra safety.
    $settingspage->add(new admin_setting_configcheckbox(
        'local_personalcourse/defer_view_enforcement',
        get_string('setting_defer_view_enforcement', 'local_personalcourse'),
        get_string('setting_defer_view_enforcement_desc', 'local_personalcourse'),
        0
    ));
    $settingspage->add(new admin_setting_configcheckbox(
        'local_personalcourse/limit_cleanup_to_personalcourses',
        get_string('setting_limit_cleanup_to_pcourses', 'local_personalcourse'),
        get_string('setting_limit_cleanup_to_pcourses_desc', 'local_personalcourse'),
        1
    ));
    $ADMIN->add('localplugins', $settingspage);

    $ADMIN->add('localplugins', new admin_externalpage(
        'local_personalcourse_dashboard',
        get_string('dashboard', 'local_personalcourse'),
        new moodle_url('/local/personalcourse/index.php'),
        'local/personalcourse:viewdashboard'
    ));
}
