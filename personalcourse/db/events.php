<?php
defined('MOODLE_INTERNAL') || die();

$observers = [
    [
        'eventname'   => '\local_questionflags\event\flag_added',
        'callback'    => '\local_personalcourse\observers::on_flag_added',
        'includefile' => '/local/personalcourse/classes/observers.php',
        'internal'    => false,
        'priority'    => 9999,
    ],
    [
        'eventname'   => '\local_questionflags\event\flag_removed',
        'callback'    => '\local_personalcourse\observers::on_flag_removed',
        'includefile' => '/local/personalcourse/classes/observers.php',
        'internal'    => false,
        'priority'    => 9999,
    ],
    [
        'eventname'   => '\mod_quiz\event\attempt_submitted',
        'callback'    => '\local_personalcourse\observers::on_quiz_attempt_submitted',
        'includefile' => '/local/personalcourse/classes/observers.php',
        'internal'    => false,
        'priority'    => 9999,
    ],
    [
        'eventname'   => '\mod_quiz\event\attempt_started',
        'callback'    => '\local_personalcourse\observers::on_personal_quiz_attempt_started',
        'includefile' => '/local/personalcourse/classes/observers.php',
        'internal'    => false,
        'priority'    => 9999,
    ],
    [
        'eventname'   => '\mod_quiz\event\course_module_viewed',
        'callback'    => '\local_personalcourse\observers::on_quiz_viewed',
        'includefile' => '/local/personalcourse/classes/observers.php',
        'internal'    => false,
        'priority'    => 9999,
    ],
];
