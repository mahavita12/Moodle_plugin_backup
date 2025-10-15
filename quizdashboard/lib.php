<?php
/**
 * AMD-based nav loader to avoid interfering with page standards mode.
 */

defined('MOODLE_INTERNAL') || die();

function local_quizdashboard_before_footer() {
    global $PAGE, $CFG;
    if (!empty($CFG->quizdashboard_disable_global_nav)) { return; }
    $PAGE->requires->js_call_amd('local_quizdashboard/global_nav', 'init');
}
