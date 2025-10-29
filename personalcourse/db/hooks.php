<?php
/**
 * Hook definitions for Personal Course plugin
 * File: local/personalcourse/db/hooks.php
 */

defined('MOODLE_INTERNAL') || die();

$callbacks = [
    [
        'hook'     => \core\hook\output\before_footer_html_generation::class,
        'callback' => [\local_personalcourse\hook\output\before_footer_html_generation::class, 'callback'],
    ],
];
