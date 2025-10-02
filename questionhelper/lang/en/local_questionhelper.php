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
 * Language strings for the question helper plugin
 *
 * @package    local_questionhelper
 * @copyright  2025
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

$string['pluginname'] = 'Question Helper';
$string['openai_apikey'] = 'OpenAI API Key';
$string['openai_apikey_desc'] = 'Enter your OpenAI API key to enable AI-powered question help';
$string['max_attempts'] = 'Maximum attempts per question';
$string['max_attempts_desc'] = 'Number of times a student can request help for each question (default: 3)';
$string['enabled'] = 'Enable Question Helper';
$string['enabled_desc'] = 'Enable or disable the question helper functionality';
$string['provider'] = 'AI Provider';
$string['provider_desc'] = 'Choose which AI provider to use for generation';
$string['provider_openai'] = 'OpenAI';
$string['provider_anthropic'] = 'Anthropic';
$string['openai_model'] = 'OpenAI model';
$string['openai_model_desc'] = 'Override OpenAI chat model (default: gpt-3.5-turbo)';
$string['anthropic_apikey'] = 'Anthropic API Key';
$string['anthropic_apikey_desc'] = 'Enter your Anthropic API key';
$string['anthropic_model'] = 'Anthropic model';
$string['anthropic_model_desc'] = 'Model to use (e.g., sonnet-4)';
$string['resetglobals'] = 'Reset all global AI questions';
$string['resetglobals_desc'] = 'Deletes all globally shared AI-generated questions so new content is generated next time. Personal copies are not removed.';
$string['resetglobals_button'] = 'Reset all globals';
$string['resetglobals_confirm_title'] = 'Confirm reset of all globals';
$string['resetglobals_confirm_body'] = 'This will delete all global Question Helper records (is_global = 1) across the site. Students will regenerate new content next time. Personal (per-user) records will remain. This cannot be undone. Continue?';
$string['resetglobals_done'] = 'Global Question Helper records have been reset.';
$string['gethelp'] = 'Get Help';
$string['helpexhausted'] = 'Help exhausted';
$string['gettinghelp'] = 'Getting help...';
$string['practicequestion'] = 'Practice Question';
$string['keyconcept'] = 'Key Concept';
$string['gotit'] = 'Got it!';
$string['view'] = 'View';
$string['saveerror'] = 'Could not save help content.';
$string['loaderror'] = 'Could not load saved help content.';
$string['helpnotavailable'] = 'Help is not available for this question';
$string['erroroccurred'] = 'An error occurred while getting help. Please try again.';
$string['retry'] = 'Retry';
$string['privacy:metadata'] = 'The Question Helper plugin does not store any personal data.';

// Tag gating settings
$string['allowed_tags'] = 'Allowed quiz tags for Question Helper';
$string['allowed_tags_desc'] = 'Comma-separated list of tags that enable the Question Helper (case-insensitive). Example: math, thinking';
$string['allowed_tags_mode'] = 'Allowed tag matching mode';
$string['allowed_tags_mode_desc'] = 'any = at least one tag must match; all = all listed tags must be present.';
$string['allowed_tags_mode_any'] = 'any';
$string['allowed_tags_mode_all'] = 'all';