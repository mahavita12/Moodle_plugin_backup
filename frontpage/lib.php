<?php
defined('MOODLE_INTERNAL') || die();

/**
 * Hook called before HTTP headers are sent.
 * Redirects users from the Moodle front page:
 * - Logged-in users go to their dashboard (/my/)
 * - Guests go to the custom frontpage (/local/frontpage/)
 */
function local_frontpage_before_http_headers() {
    global $CFG, $PAGE;
    
    // NEVER redirect during AJAX requests - this causes "redirecterrordetected" errors
    if (defined('AJAX_SCRIPT') && AJAX_SCRIPT) {
        return;
    }
    
    // Don't redirect during CLI scripts
    if (CLI_SCRIPT) {
        return;
    }
    
    // Don't redirect during web service calls
    if (defined('WS_SERVER') && WS_SERVER) {
        return;
    }
    
    // Only act on the site front page (index.php at site root)
    if ($PAGE->pagetype !== 'site-index') {
        return;
    }
    
    // Logged-in users (not guests) go to dashboard
    if (isloggedin() && !isguestuser()) {
        redirect(new moodle_url('/my/'));
    }
    
    // Guests and non-logged-in users go to custom frontpage
    redirect(new moodle_url('/local/frontpage/'));
}

/**
 * Extend navigation - adds custom frontpage link
 */
function local_frontpage_extend_navigation(global_navigation $navigation) {
    // Navigation extensions if needed
}
