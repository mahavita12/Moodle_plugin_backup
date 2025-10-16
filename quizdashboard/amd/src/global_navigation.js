/**
 * Global navigation module for Quiz Dashboard
 * Injects navigation panel on all Moodle pages
 */

define(['jquery'], function($) {
    
    var GlobalNavigation = {
        
        init: function() {
            console.log('Initializing Quiz Dashboard global navigation');
            try {
                var params = new URLSearchParams(window.location.search);
                if (params.get('clean') === '1' || params.get('print') === '1') {
                    return; // don't inject on clean/print views
                }
            } catch (e) {}
            this.injectStyles();
            this.injectNavigation();
        },
        
        injectStyles: function() {
            // Check if styles already injected
            if ($('#quiz-dashboard-global-styles').length > 0) {
                return;
            }
            
            var css = `
            <style id="quiz-dashboard-global-styles">
            .quiz-dashboard-global-nav {
                position: fixed;
                top: 60px;
                right: 20px;
                z-index: 9999;
                font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
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
                text-decoration: none;
                min-width: 130px;
                text-align: center;
                box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            }
            
            .quiz-dashboard-global-nav .nav-button:hover {
                background: #005a87;
                color: #ffffff;
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
                from {
                    opacity: 0;
                    transform: translateY(-5px);
                }
                to {
                    opacity: 1;
                    transform: translateY(0);
                }
            }
            
            .quiz-dashboard-global-nav .nav-menu a {
                display: block;
                padding: 12px 16px;
                color: #333;
                text-decoration: none;
                border-bottom: 1px solid #f1f3f4;
                font-size: 14px;
                font-weight: 400;
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
            
            /* Mobile responsiveness */
            @media (max-width: 768px) {
                .quiz-dashboard-global-nav {
                    top: 10px;
                    right: 10px;
                }
                .quiz-dashboard-global-nav .nav-button {
                    padding: 8px 12px;
                    font-size: 13px;
                    min-width: 110px;
                }
                .quiz-dashboard-global-nav .nav-menu {
                    min-width: 180px;
                }
                .quiz-dashboard-global-nav .nav-menu a {
                    padding: 10px 14px;
                    font-size: 13px;
                }
            }
            
            /* Adjust for different Moodle themes */
            .theme-boost .quiz-dashboard-global-nav {
                top: 70px;
            }
            
            .theme-classic .quiz-dashboard-global-nav {
                top: 55px;
            }
            
            /* Hide on very small screens */
            @media (max-width: 480px) {
                .quiz-dashboard-global-nav {
                    display: none;
                }
            }
            </style>`;
            
            $('head').append(css);
        },
        
        injectNavigation: function() {
            // Check if navigation already exists
            if $('.quiz-dashboard-global-nav').length > 0) {
                return;
            }
            
            // Get current page URL to determine active state
            var currentPath = window.location.pathname;
            var quizActive = currentPath.indexOf('/local/quizdashboard/index.php') !== -1 ? 'active' : '';
            var essayActive = currentPath.indexOf('/local/quizdashboard/essays.php') !== -1 ? 'active' : '';
            var essaysmasterActive = currentPath.indexOf('/local/essaysmaster/dashboard.php') !== -1 ? 'active' : '';
            
            // Get Moodle's wwwroot from the page
            var wwwroot = M.cfg.wwwroot || '';
            
            var navHTML = `
            <div class="quiz-dashboard-global-nav">
                <div class="nav-dropdown">
                    <button class="nav-button">📊 Dashboards</button>
                    <div class="nav-menu">
                        <a href="${wwwroot}/local/quizdashboard/index.php" class="${quizActive}">📝 Quiz Dashboard</a>
                        <a href="${wwwroot}/local/quizdashboard/essays.php" class="${essayActive}">✍️ Essay Dashboard</a>
                        <a href="${wwwroot}/local/essaysmaster/dashboard.php" class="${essaysmasterActive}">🧠 EssaysMaster</a>
                    </div>
                </div>
            </div>`;
            
            // Inject navigation into page
            $('body').append(navHTML);
            
            console.log('Quiz Dashboard global navigation injected successfully');
        }
    };
    
    return GlobalNavigation;
});
