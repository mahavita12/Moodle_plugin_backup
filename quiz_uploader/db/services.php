<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

defined('MOODLE_INTERNAL') || die();

$functions = [
    'local_quiz_uploader_import_quiz_from_xml' => [
        'classname'   => 'local_quiz_uploader\external\import_quiz_from_xml',
        'methodname'  => 'execute',
        'classpath'   => '',
        'description' => 'Import quiz from XML file in draft area',
        'type'        => 'write',
        'ajax'        => true,
        'services'    => [MOODLE_OFFICIAL_MOBILE_SERVICE],
        'capabilities'=> 'moodle/question:add,mod/quiz:addinstance',
    ],
    'local_quiz_uploader_create_quiz_from_questions' => [
        'classname'   => 'local_quiz_uploader\external\create_quiz_from_questions',
        'methodname'  => 'execute',
        'classpath'   => '',
        'description' => 'Create quiz from existing question bank questions',
        'type'        => 'write',
        'ajax'        => true,
        'services'    => [MOODLE_OFFICIAL_MOBILE_SERVICE],
        'capabilities'=> 'mod/quiz:addinstance,mod/quiz:manage',
    ],
];
