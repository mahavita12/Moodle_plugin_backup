<?php
// Scheduled tasks for local_personalcourse.

defined('MOODLE_INTERNAL') || die();

$tasks = [
    [
        'classname' => '\\local_personalcourse\\task\\sequence_cleanup_scheduled_task',
        'blocking' => 0,
        'minute' => '*/10',
        'hour' => '*',
        'day' => '*',
        'month' => '*',
        'dayofweek' => '*',
    ],
];
