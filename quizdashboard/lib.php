<?php
/**
 * AMD-based nav loader to avoid interfering with page standards mode.
 */

defined('MOODLE_INTERNAL') || die();

function local_quizdashboard_before_footer() {
    global $PAGE, $CFG;
    if (!empty($CFG->quizdashboard_disable_global_nav)) { return; }
    // Do not inject on print or clean views
    $clean = isset($_GET['clean']) ? (int)$_GET['clean'] : 0;
    $printp = isset($_GET['print']) ? (int)$_GET['print'] : 0;
    if ($PAGE->pagelayout === 'print' || $clean === 1 || $printp === 1) { return; }
    $PAGE->requires->js_call_amd('local_quizdashboard/global_nav', 'init');
}
