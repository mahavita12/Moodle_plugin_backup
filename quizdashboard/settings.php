<?php
/**
 * Admin settings for Quiz Dashboard plugin
 * File: local/quizdashboard/settings.php
 */

defined('MOODLE_INTERNAL') || die();

// Debug logging - remove after testing
error_log('Quiz Dashboard settings.php loaded');

if ($hassiteconfig || has_capability('local/quizdashboard:view', context_system::instance())) {
    
    // Add to the Reports category in Site Administration
    $ADMIN->add('reports', new admin_externalpage(
        'local_quizdashboard_main',
        get_string('quizdashboard', 'local_quizdashboard'),
        new moodle_url('/local/quizdashboard/index.php'),
        'local/quizdashboard:view'
    ));
    
    $ADMIN->add('reports', new admin_externalpage(
        'local_quizdashboard_essays', 
        get_string('essaydashboard', 'local_quizdashboard'),
        new moodle_url('/local/quizdashboard/essays.php'),
        'local/quizdashboard:view'
    ));
}