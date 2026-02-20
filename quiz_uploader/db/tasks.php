<?php
// Scheduled tasks for local_quiz_uploader.

defined('MOODLE_INTERNAL') || die();

$tasks = [
    [
        'classname' => '\\local_quiz_uploader\\task\\auto_reset_defaults',
        'blocking' => 0,
        'minute' => '*/5',
        'hour' => '*',
        'day' => '*',
        'month' => '*',
        'dayofweek' => '*',
    ],
];
