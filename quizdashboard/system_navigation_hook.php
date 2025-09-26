<?php
/**
 * System-wide navigation injection via config.php modification
 * This file can be included from Moodle's config.php to ensure global coverage
 */

// Only proceed if we're not in CLI mode and user is logged in
if (!CLI_SCRIPT && !during_initial_install()) {
    
    // Use output buffering to inject navigation at the end of page rendering
    if (!defined('QUIZDASHBOARD_BUFFER_STARTED')) {
        define('QUIZDASHBOARD_BUFFER_STARTED', true);
        
        function quizdashboard_output_handler($buffer) {
            // Only process HTML content
            if (strpos($buffer, '</html>') === false) {
                return $buffer;
            }
            
            // Skip if user doesn't have capability
            if (!function_exists('has_capability') || !has_capability('local/quizdashboard:view', context_system::instance())) {
                return $buffer;
            }
            
            // Skip if navigation already present
            if (strpos($buffer, 'quiz-dashboard-global-nav') !== false) {
                return $buffer;
            }
            
            global $CFG, $PAGE;
            
            $current_url = $PAGE->url ? $PAGE->url->out(false) : '';
            $is_main_dashboard = strpos($current_url, '/local/quizdashboard/index.php') !== false;
            $is_essay_dashboard = strpos($current_url, '/local/quizdashboard/essays.php') !== false;
            
            $navigation_html = "
            <style>
            .quiz-dashboard-global-nav {
                position: fixed;
                top: 60px;
                right: 20px;
                z-index: 10000;
                font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            }
            
            .quiz-dashboard-global-nav .nav-dropdown {
                position: relative;
                display: inline-block;
            }
            
            .quiz-dashboard-global-nav .nav-button {
                background: #007cba;
                color: #ffffff;
                padding: 10px 15px;
                border: 1px solid #005a87;
                cursor: pointer;
                border-radius: 6px;
                font-size: 14px;
                font-weight: 500;
                transition: all 0.2s ease-in-out;
                display: inline-block;
                min-width: 130px;
                text-align: center;
                box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            }
            
            .quiz-dashboard-global-nav .nav-button:hover {
                background: #005a87;
                transform: translateY(-1px);
                box-shadow: 0 4px 8px rgba(0,0,0,0.15);
            }
            
            .quiz-dashboard-global-nav .nav-menu {
                display: none;
                position: absolute;
                right: 0;
                top: calc(100% + 5px);
                background: #ffffff;
                min-width: 200px;
                box-shadow: 0 4px 12px rgba(0,0,0,0.15);
                border: 1px solid #dee2e6;
                border-radius: 6px;
                overflow: hidden;
            }
            
            .quiz-dashboard-global-nav .nav-dropdown:hover .nav-menu {
                display: block;
                animation: fadeInDown 0.2s ease-out;
            }
            
            @keyframes fadeInDown {
                from { opacity: 0; transform: translateY(-5px); }
                to { opacity: 1; transform: translateY(0); }
            }
            
            .quiz-dashboard-global-nav .nav-menu a {
                display: block;
                padding: 12px 16px;
                color: #333;
                text-decoration: none;
                border-bottom: 1px solid #f1f3f4;
                font-size: 14px;
                transition: background-color 0.15s ease-in-out;
            }
            
            .quiz-dashboard-global-nav .nav-menu a:hover {
                background: #f8f9fa;
                color: #007cba;
            }
            
            .quiz-dashboard-global-nav .nav-menu a.active {
                background: #e7f3ff;
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
                    <button class='nav-button'>📊 Dashboards</button>
                    <div class='nav-menu'>
                        <a href='{$CFG->wwwroot}/local/quizdashboard/index.php'" . 
                            ($is_main_dashboard ? " class='active'" : "") . ">📝 Quiz Dashboard</a>
                        <a href='{$CFG->wwwroot}/local/quizdashboard/essays.php'" .
                            ($is_essay_dashboard ? " class='active'" : "") . ">✍️ Essay Dashboard</a>
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
