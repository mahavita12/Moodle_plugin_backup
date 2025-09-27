<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Settings for the question helper plugin
 *
 * @package    local_questionhelper
 * @copyright  2025
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

if ($hassiteconfig) {
    $settings = new admin_settingpage('local_questionhelper', get_string('pluginname', 'local_questionhelper'));

    // Provider select
    $settings->add(new admin_setting_configselect(
        'local_questionhelper/provider',
        get_string('provider', 'local_questionhelper'),
        get_string('provider_desc', 'local_questionhelper'),
        'openai',
        [
            'openai' => get_string('provider_openai', 'local_questionhelper'),
            'anthropic' => get_string('provider_anthropic', 'local_questionhelper'),
        ]
    ));

    // OpenAI API Key setting
    $settings->add(new admin_setting_configtext(
        'local_questionhelper/openai_apikey',
        get_string('openai_apikey', 'local_questionhelper'),
        get_string('openai_apikey_desc', 'local_questionhelper'),
        '',
        PARAM_TEXT
    ));

    // OpenAI model (optional)
    $settings->add(new admin_setting_configtext(
        'local_questionhelper/openai_model',
        get_string('openai_model', 'local_questionhelper'),
        get_string('openai_model_desc', 'local_questionhelper'),
        'gpt-3.5-turbo',
        PARAM_TEXT
    ));

    // Anthropic API Key setting
    $settings->add(new admin_setting_configtext(
        'local_questionhelper/anthropic_apikey',
        get_string('anthropic_apikey', 'local_questionhelper'),
        get_string('anthropic_apikey_desc', 'local_questionhelper'),
        '',
        PARAM_TEXT
    ));

    // Anthropic model setting (default to user request: sonnet-4)
    $settings->add(new admin_setting_configtext(
        'local_questionhelper/anthropic_model',
        get_string('anthropic_model', 'local_questionhelper'),
        get_string('anthropic_model_desc', 'local_questionhelper'),
        'sonnet-4',
        PARAM_TEXT
    ));

    // Maximum attempts per question setting
    $settings->add(new admin_setting_configtext(
        'local_questionhelper/max_attempts',
        get_string('max_attempts', 'local_questionhelper'),
        get_string('max_attempts_desc', 'local_questionhelper'),
        '3',
        PARAM_INT
    ));

    // Enable/disable plugin setting
    $settings->add(new admin_setting_configcheckbox(
        'local_questionhelper/enabled',
        get_string('enabled', 'local_questionhelper'),
        get_string('enabled_desc', 'local_questionhelper'),
        1
    ));

    $ADMIN->add('localplugins', $settings);
}