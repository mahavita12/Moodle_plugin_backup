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
 * Essays Master external functions and service definitions.
 *
 * @package    local_essaysmaster
 * @copyright  2024 Essays Master Development Team
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$functions = [
    'local_essaysmaster_get_feedback' => [
        'classname' => 'local_essaysmaster\external\get_feedback',
        'methodname' => 'execute',
        'description' => 'Generate AI feedback for essay',
        'type' => 'write',
        'ajax' => true,
        'capabilities' => 'local/essaysmaster:use',
        'loginrequired' => true,
    ],

    'local_essaysmaster_check_essay_status' => [
        'classname' => 'local_essaysmaster\external\check_essay_status',
        'methodname' => 'execute',
        'description' => 'Check Essays Master status for quiz attempt',
        'type' => 'read',
        'ajax' => true,
        'capabilities' => 'local/essaysmaster:use',
        'loginrequired' => true,
    ],
];

$services = [
    'Essays Master Service' => [
        'functions' => [
            'local_essaysmaster_get_feedback',
            'local_essaysmaster_check_essay_status',
        ],
        'restrictedusers' => 0,
        'enabled' => 1,
        'shortname' => 'essaysmaster',
        'downloadfiles' => 0,
        'uploadfiles' => 0,
    ],
];