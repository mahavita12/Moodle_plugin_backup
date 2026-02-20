<?php
/**
 * Hook definitions for Quiz Dashboard plugin
 */

defined('MOODLE_INTERNAL') || die();

$callbacks = [
	[
		'hook'     => \core\hook\output\before_footer_html_generation::class,
		'callback' => [\local_quizdashboard\hook\output\before_footer_html_generation::class, 'callback'],
	],
];
