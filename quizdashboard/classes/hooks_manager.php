<?php
/**
 * Global hooks manager for Quiz Dashboard
 * Injects navigation on all Moodle pages when user has admin capabilities
 */

namespace local_quizdashboard;

class hooks_manager {
    
    /**
     * Inject dashboard navigation globally
     * Called from after_config hook in db/hooks.php
     */
    public static function inject_global_navigation() {
        global $PAGE, $CFG, $USER;
        
        // Don't inject during CLI, AJAX, or installation
        if (CLI_SCRIPT || defined('AJAX_SCRIPT') || during_initial_install() || !isset($CFG->version)) {
            return;
        }
        
        // Only inject for logged-in users
        if (!isloggedin() || isguestuser()) {
            return;
        }
        
        // Check if user has dashboard viewing capability
        $context = \context_system::instance();
        if (!has_capability('local/quizdashboard:view', $context)) {
            return;
        }
        
        // Don't inject on the dashboard pages themselves (already have navigation)
        $current_url = $PAGE->url ? $PAGE->url->get_path() : '';
        if (strpos($current_url, '/local/quizdashboard/') !== false) {
            return;
        }
        
        // Add CSS and JavaScript to inject navigation
        $PAGE->requires->js_call_amd('local_quizdashboard/global_navigation', 'init');
        
        // Inject styles and navigation HTML via footer callback
        $PAGE->add_body_class('quiz-dashboard-nav-enabled');
    }
    
    /**
     * Get navigation HTML
     */
    public static function get_navigation_html() {
        global $CFG, $PAGE;
        
        $current_url = $PAGE->url ? $PAGE->url->get_path() : '';
        $quiz_active = strpos($current_url, '/local/quizdashboard/index.php') !== false ? 'active' : '';
        $essay_active = strpos($current_url, '/local/quizdashboard/essays.php') !== false ? 'active' : '';
        
        return '
        <div class="quiz-dashboard-global-nav">
            <div class="nav-dropdown">
                <button class="nav-button">📊 Dashboards</button>
                <div class="nav-menu">
                    <a href="' . $CFG->wwwroot . '/local/quizdashboard/index.php" class="' . $quiz_active . '">📝 Quiz Dashboard</a>
                    <a href="' . $CFG->wwwroot . '/local/quizdashboard/essays.php" class="' . $essay_active . '">✍️ Essay Dashboard</a>
                </div>
            </div>
        </div>';
    }
}
