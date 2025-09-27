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
 * Library functions for the question helper plugin
 *
 * @package    local_questionhelper
 * @copyright  2025
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

/**
 * Hook to inject JavaScript into quiz attempt pages
 */
function local_questionhelper_before_footer() {
    global $PAGE;

    // Only load on quiz attempt pages
    if (strpos($PAGE->pagetype, 'mod-quiz-attempt') !== false) {
        $PAGE->requires->js_call_amd('local_questionhelper/quiz_helper', 'init');
    }
}

/**
 * Get OpenAI API key from plugin settings
 *
 * @return string|null The API key or null if not set
 */
function local_questionhelper_get_api_key() {
    return get_config('local_questionhelper', 'openai_apikey');
}

/**
 * Check if the plugin is properly configured
 *
 * @return bool True if configured, false otherwise
 */
function local_questionhelper_is_configured() {
    $provider = get_config('local_questionhelper', 'provider');
    if ($provider === 'anthropic') {
        return !empty(get_config('local_questionhelper', 'anthropic_apikey'));
    }
    return !empty(get_config('local_questionhelper', 'openai_apikey'));
}

/**
 * Declare external services
 */
function local_questionhelper_extend_navigation() {
    // No-op, placeholder to keep file structured for plugin callbacks.
}