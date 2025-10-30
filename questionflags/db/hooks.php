<?php
/**
 * Hook definitions for Question Flags plugin
 * File: local/questionflags/db/hooks.php
 */

defined('MOODLE_INTERNAL') || die();

$callbacks = [
    [
        'hook'     => \core\hook\output\before_footer_html_generation::class,
        'callback' => [\local_questionflags\hook\output\before_footer_html_generation::class, 'callback'],
    ],
];
