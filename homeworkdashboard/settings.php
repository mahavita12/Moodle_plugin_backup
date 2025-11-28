<?php
defined('MOODLE_INTERNAL') || die;

if ($hassiteconfig) {
    $settings = new admin_settingpage('local_homeworkdashboard', get_string('pluginname', 'local_homeworkdashboard'));

    $settings->add(new admin_setting_configtext(
        'local_homeworkdashboard/defaultwindowdays',
        get_string('defaultwindowdays', 'local_homeworkdashboard'),
        get_string('defaultwindowdays_desc', 'local_homeworkdashboard'),
        7,
        PARAM_INT
    ));

    $settings->add(new admin_setting_configtext(
        'local_homeworkdashboard/google_drive_folder_id',
        'Google Drive Folder ID',
        'The ID of the Google Drive folder where reports will be saved. Requires service-account.json in local_quizdashboard.',
        '',
        PARAM_TEXT
    ));

    $ADMIN->add('localplugins', $settings);
}
