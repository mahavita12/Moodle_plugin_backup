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

    // AI Provider Selection
    $settings->add(new admin_setting_configselect(
        'local_homeworkdashboard/ai_provider',
        'AI Provider',
        'Select the AI provider for generating homework report commentary. If the primary provider fails, it will NOT automatically fall back.',
        'gemini',
        [
            'gemini' => 'Gemini (Google)',
            'anthropic' => 'Anthropic (Claude)'
        ]
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

    // Anthropic API Settings
    $settings->add(new admin_setting_configpasswordunmask(
        'local_homeworkdashboard/anthropic_api_key',
        'Anthropic API Key',
        'API Key for Anthropic Claude. If left blank, will attempt to use the key from Quiz Dashboard settings.',
        ''
    ));

    $settings->add(new admin_setting_configselect(
        'local_homeworkdashboard/anthropic_model',
        'Anthropic Model',
        'Select the Anthropic Claude model to use.',
        'claude-sonnet-4-6',
        [
            'claude-sonnet-4-6' => 'Claude Sonnet 4.6 (Recommended)',
            'claude-sonnet-4-0' => 'Claude Sonnet 4.0',
            'claude-3-5-haiku-latest' => 'Claude 3.5 Haiku (Fast & Cheap)'
        ]
    ));

    // Book Reading Tracker Points
    $settings->add(new admin_setting_configtext(
        'local_homeworkdashboard/book_points_finished',
        'Book Points (Finished)',
        'Intellect Points awarded when a student finishes a book.',
        '200',
        PARAM_FLOAT
    ));

    $settings->add(new admin_setting_configtext(
        'local_homeworkdashboard/book_points_inprogress',
        'Book Points (In Progress)',
        'Intellect Points awarded for a book that is in progress.',
        '100',
        PARAM_FLOAT
    ));

    $ADMIN->add('localplugins', $settings);
}
