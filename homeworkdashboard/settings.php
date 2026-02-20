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

    // Gemini API Settings
    $settings->add(new admin_setting_configtext(
        'local_homeworkdashboard/gemini_api_key',
        'Gemini API Key',
        'API Key for Google Gemini (AI Commentary).',
        '',
        PARAM_TEXT
    ));

    $settings->add(new admin_setting_configselect(
        'local_homeworkdashboard/gemini_model',
        'Gemini Model',
        'Select the Gemini model to use.',
        'gemini-3.1-pro-preview',
        [
            'gemini-3.1-pro-preview' => 'Gemini 3.1 Pro Preview (Latest)',
            'gemini-2.0-flash' => 'Gemini 2.0 Flash (Fast & Smart)',
            'gemini-1.5-pro' => 'Gemini 1.5 Pro (Stable)',
            'gemini-1.5-flash' => 'Gemini 1.5 Flash (Fast)'
        ]
    ));

    $ADMIN->add('localplugins', $settings);
}
