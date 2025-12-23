<?php
/**
 * System-wide navigation injection via config.php modification
 * This file can be included from Moodle's config.php to ensure global coverage
 */

// Only proceed if we're not in CLI mode and user is logged in
if (!CLI_SCRIPT && !during_initial_install()) {
    return; // Disabled: Navigation moved to User Menu
    
    // Skip for print or clean views (e.g., feedback windows)
    $isclean = isset($_GET['clean']) && (int)$_GET['clean'] === 1;
    $isprint = isset($_GET['print']) && (int)$_GET['print'] === 1;
    if ($isclean || $isprint) {
        return;
    }
    
    // Use output buffering to inject navigation at the end of page rendering
    if (!defined('QUIZDASHBOARD_BUFFER_STARTED')) {
        define('QUIZDASHBOARD_BUFFER_STARTED', true);
        
        function quizdashboard_output_handler($buffer) {
            // Only process HTML content
            if (strpos($buffer, '</html>') === false) {
                return $buffer;
            }
            
            // Skip if user doesn't have capability (Check for either Quiz OR Homework dashboard view)
            $can_view_quiz = function_exists('has_capability') && has_capability('local/quizdashboard:view', context_system::instance());
            $can_view_homework = function_exists('has_capability') && has_capability('local/homeworkdashboard:view', context_system::instance());
            
            // Only show this dropdown if the user is STAFF (has quiz dashboard view)
            // Students (who only have homework view) should NOT see this dropdown, as per requirements.
            if (!$can_view_quiz) {
                return $buffer;
            }
            
            // Skip if navigation already present
            if (strpos($buffer, 'quiz-dashboard-global-nav') !== false) {
                return $buffer;
            }
            
            global $CFG, $PAGE;
            // Extra safety: if the final page layout is print, do not inject
            if ($PAGE->pagelayout === 'print') {
                return $buffer;
            }

            // Determine active state
            $current_url = $PAGE->url->out_as_string();
            $is_main_dashboard = strpos($current_url, '/local/quizdashboard/index.php') !== false;
            $is_essay_dashboard = strpos($current_url, '/local/quizdashboard/essays.php') !== false;
            
            // Build navigation HTML
            $navigation_html = "
            <style>
            .quiz-dashboard-global-nav {
                position: fixed;
                top: 60px;
                right: 60px;
                z-index: 10001;
                font-family: -apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;
            }
            
            .quiz-dashboard-global-nav .nav-dropdown {
                position: relative;
                display: inline-block;
            }
            
            .quiz-dashboard-global-nav .nav-button {
                background: #007cba;
                color: #fff;
                padding: 8px 12px;
                border: 1px solid #005a87;
                cursor: pointer;
                border-radius: 4px;
                font-size: 13px;
                min-width: 100px;
                text-align: center;
                box-shadow: 0 2px 5px rgba(0,0,0,0.2);
                transition: background 0.2s;
            }
            
            .quiz-dashboard-global-nav .nav-button:hover {
                background: #005a87;
            }
            
            .quiz-dashboard-global-nav .nav-menu {
                display: none;
                position: absolute;
                right: 0;
                top: 100%;
                background: #fff;
                min-width: 160px;
                box-shadow: 0 2px 5px rgba(0,0,0,0.2);
                border-radius: 4px;
                margin-top: 2px;
                border: 1px solid #dee2e6;
                overflow: hidden;
            }
            
            .quiz-dashboard-global-nav .nav-dropdown:hover .nav-menu {
                display: block;
            }
            
            .quiz-dashboard-global-nav .nav-menu a {
                display: block;
                padding: 8px 12px;
                color: #007cba;
                text-decoration: none;
                font-size: 13px;
                border-bottom: 1px solid #dee2e6;
                transition: background 0.2s;
            }
            
            .quiz-dashboard-global-nav .nav-menu a:hover {
                background: #f8f9fa;
                color: #0056b3;
            }
            
            .quiz-dashboard-global-nav .nav-menu a.active {
                background: #e9ecef;
                color: #007cba;
                font-weight: 600;
                border-left: 3px solid #007cba;
            }
            
            .quiz-dashboard-global-nav .nav-menu a:last-child {
                border-bottom: none;
            }
            
            @media (max-width: 768px) {
                .quiz-dashboard-global-nav { top: 10px; right: 10px; }
                .quiz-dashboard-global-nav .nav-button { padding: 8px 12px; font-size: 13px; min-width: 110px; }
                .quiz-dashboard-global-nav .nav-menu { min-width: 180px; }
            }
            
            @media (max-width: 480px) {
                .quiz-dashboard-global-nav { display: none; }
            }
            
            .theme-boost .quiz-dashboard-global-nav { top: 70px; }
            .theme-classic .quiz-dashboard-global-nav { top: 55px; }
            </style>
            
            <div class='quiz-dashboard-global-nav'>
                <div class='nav-dropdown'>
                    <button class='nav-button'>\ud83d\udcca Dashboards</button>
                    <div class='nav-menu'>
                        <a href='{$CFG->wwwroot}/local/quizdashboard/index.php'" . 
                            ($is_main_dashboard ? " class='active'" : "") . ">\ud83d\udcdd Quiz Dashboard</a>
                        <a href='{$CFG->wwwroot}/local/quizdashboard/essays.php'" .
                            ($is_essay_dashboard ? " class='active'" : "") . ">\u270d\ufe0f Essay Dashboard</a>
                    </div>
                </div>
            </div>";
            
            // Insert navigation before closing body tag
            $buffer = str_replace('</body>', $navigation_html . '</body>', $buffer);
            
            return $buffer;
        }
        
        ob_start('quizdashboard_output_handler');
    }
}
