<?php
defined('MOODLE_INTERNAL') || die();

$tasks = [
    [
        'classname' => '\\local_homeworkdashboard\\task\\compute_homework_snapshots',
        'blocking' => 0,
        'minute' => '*/15',
        'hour' => '*',
        'day' => '*',
        'dayofweek' => '*',
        'month' => '*',
    ],
];
