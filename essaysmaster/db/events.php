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
 * Event observers for Essays Master plugin.
 *
 * @package    local_essaysmaster
 * @copyright  2024 Essays Master Development Team
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$observers = [
    // DISABLED - conflicts with JavaScript interceptor
    /*
    [
        'eventname' => '\mod_quiz\event\attempt_submitted',
        'callback' => '\local_essaysmaster\observer::quiz_attempt_submitted',
        'priority' => 100, // High priority to intercept before processing
    ],
    */
    [
        'eventname' => '\mod_quiz\event\attempt_preview_started',
        'callback' => '\local_essaysmaster\observer::quiz_attempt_preview_started',
    ],
    [
        'eventname' => '\mod_quiz\event\attempt_started',
        'callback' => '\local_essaysmaster\observer::quiz_attempt_started',
    ],
];