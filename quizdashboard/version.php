<?php
defined('MOODLE_INTERNAL') || die();

$plugin->component = 'local_quizdashboard';
$plugin->version = 2026030801; // Dual-score: initial draft scores + final submission scores

$plugin->requires = 2022112800; // Moodle 4.0+
$plugin->maturity = MATURITY_BETA;
$plugin->release = 'v.1.0 - Auto-Grading Integration';

// Dependencies
$plugin->dependencies = array(
    'mod_quiz' => 2022112800, // Requires quiz module
);