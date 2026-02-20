<?php
defined('MOODLE_INTERNAL') || die();

$capabilities = [
    'local/personalcourse:viewdashboard' => [
        'captype' => 'read',
        'contextlevel' => CONTEXT_SYSTEM,
        'archetypes' => [
            'manager' => CAP_ALLOW,
        ],
    ],
];
