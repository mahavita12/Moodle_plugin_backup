<?php
/**
 * Library functions for Quiz Uploader plugin
 * File: local/quiz_uploader/lib.php
 */

defined('MOODLE_INTERNAL') || die();

/**
 * GLOBAL navigation injection - DISABLED
 */
function local_quiz_uploader_before_standard_top_of_body_html() {
    return ''; // Disabled: Navigation moved to User Menu
}
