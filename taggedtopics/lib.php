<?php
// This file is part of Moodle - http://moodle.org/

defined('MOODLE_INTERNAL') || die();

/**
 * Inject sitewide JS/CSS to append activity tags in non-course-format pages (e.g., Dashboard Timeline).
 */
function local_taggedtopics_extend_navigation(global_navigation $nav) {
    global $PAGE;
    // Load our injector everywhere navigation is built (plain JS to avoid AMD build step).
    $PAGE->requires->js(new moodle_url('/local/taggedtopics/injector.js'), true);
    $PAGE->requires->css('/local/taggedtopics/styles.css');
}
