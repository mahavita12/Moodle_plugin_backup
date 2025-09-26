<?php
/**
 * Hook callbacks for Quiz Dashboard plugin
 * File: local/quizdashboard/classes/hook_callbacks.php
 */

namespace local_quizdashboard;

defined('MOODLE_INTERNAL') || die();

/**
 * Hook callbacks for the Quiz Dashboard plugin.
 */
class hook_callbacks {

    /**
     * Add dashboard navigation to the top of the page
     * 
     * @param \core\hook\output\before_standard_top_of_body_html $hook
     */
    public static function before_standard_top_of_body_html(\core\hook\output\before_standard_top_of_body_html $hook): void {
        global $PAGE, $CFG;
        
        // Check capability
        if (!has_capability('local/quizdashboard:view', \context_system::instance())) {
            return;
        }
        
        // Determine current page for active state
        $current_url = $PAGE->url ? $PAGE->url->out(false) : '';
        $is_main_dashboard = strpos($current_url, '/local/quizdashboard/index.php') !== false;
        $is_essay_dashboard = strpos($current_url, '/local/quizdashboard/essays.php') !== false;
        $is_questions_dashboard = strpos($current_url, '/local/quizdashboard/questions.php') !== false;
        
        $html = '
        <style>
        .quiz-dashboard-global-nav {
            position: fixed;
            top: 60px;
            right: 20px;
            z-index: 10001;
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
        }
        
        .quiz-dashboard-global-nav .nav-dropdown {
            position: relative;
            display: inline-block;
        }
        
        .quiz-dashboard-global-nav .nav-button {
            background: #007cba;
            color: #ffffff;
            padding: 8px 12px;
            border: 1px solid #005a87;
            cursor: pointer;
            border-radius: 4px;
            font-size: 13px;
            font-weight: 400;
            transition: background-color 0.15s ease-in-out;
            display: inline-block;
            min-width: 100px;
            text-align: center;
            box-shadow: 0 2px 5px rgba(0,0,0,0.2);
        }
        
        .quiz-dashboard-global-nav .nav-button:hover {
            background: #005a87;
            color: #ffffff;
        }
        
        .quiz-dashboard-global-nav .nav-menu {
            display: none;
            position: absolute;
            right: 0;
            top: calc(100% + 2px);
            background: #ffffff;
            min-width: 180px;
            box-shadow: 0 4px 8px rgba(0,0,0,0.25);
            border: 1px solid #dee2e6;
            border-radius: 4px;
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
            padding: 10px 12px;
            color: #007cba;
            text-decoration: none;
            border-bottom: 1px solid #dee2e6;
            font-size: 13px;
            transition: background-color 0.15s ease-in-out;
        }
        
        .quiz-dashboard-global-nav .nav-menu a:hover {
            background: #f8f9fa;
            color: #007cba;
            text-decoration: none;
        }
        
        .quiz-dashboard-global-nav .nav-menu a.active {
            background: #e9ecef;
            color: #007cba;
            font-weight: 500;
        }
        
        .quiz-dashboard-global-nav .nav-menu a:last-child {
            border-bottom: none;
            border-bottom-left-radius: 4px;
            border-bottom-right-radius: 4px;
        }
        
        .quiz-dashboard-global-nav .nav-menu a:first-child {
            border-top-left-radius: 4px;
            border-top-right-radius: 4px;
        }
        
        @media (max-width: 768px) {
            .quiz-dashboard-global-nav { 
                top: 10px; 
                right: 10px; 
            }
            .quiz-dashboard-global-nav .nav-button { 
                padding: 6px 10px; 
                font-size: 12px; 
                min-width: 90px; 
            }
            .quiz-dashboard-global-nav .nav-menu {