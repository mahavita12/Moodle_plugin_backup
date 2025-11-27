<?php
defined('MOODLE_INTERNAL') || die();

$plugin->component = 'local_homeworkdashboard';
$plugin->version   = 2025112703;
$plugin->requires  = 2022112800; // Moodle 4.0+
$plugin->maturity  = MATURITY_ALPHA;
$plugin->release   = '0.1 Homework Dashboard MVP';

$plugin->dependencies = [
    'mod_quiz' => 2022112800,
];
