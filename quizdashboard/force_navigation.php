<?php
/**
 * SIMPLE DIRECT SOLUTION - No hooks needed
 * This will force navigation on every page by being included in the navigation_fallback.php
 */

// Only run for logged-in users with capability  
if (!CLI_SCRIPT && function_exists('has_capability')) {
    if (isloggedin() && !isguestuser() && has_capability('local/quizdashboard:view', context_system::instance())) {
        
        // Direct JavaScript injection - no dependencies
        ?>
        <script>
        console.log('DIRECT NAVIGATION: Starting injection...');
        
        function forceInjectNavigation() {
            // Remove any existing navigation
            var existing = document.querySelectorAll('.quiz-dashboard-global-nav');
            existing.forEach(function(el) { el.remove(); });
            
            console.log('DIRECT NAVIGATION: Creating navigation element');
            
            var nav = document.createElement('div');
            nav.className = 'quiz-dashboard-global-nav';
            nav.style.cssText = 'position:fixed;top:60px;right:60px;z-index:10001;font-family:-apple-system,BlinkMacSystemFont,Segoe UI,Roboto,sans-serif';
            
            // Check current page for active state
            var currentPath = window.location.pathname;
            var quizActive = currentPath.includes('/local/quizdashboard/index.php');
            var essayActive = currentPath.includes('/local/quizdashboard/essays.php');
            var questionsActive = currentPath.includes('/local/quizdashboard/questions.php');
            var essaysmasterActive = currentPath.includes('/local/essaysmaster/dashboard.php');
            
            nav.innerHTML = '<div class="nav-dropdown" style="position:relative;display:inline-block">' +
                '<button class="nav-button" style="background:#007cba;color:#fff;padding:8px 12px;border:1px solid #005a87;cursor:pointer;border-radius:4px;font-size:13px;font-weight:400;min-width:100px;text-align:center;box-shadow:0 2px 5px rgba(0,0,0,0.2)">Dashboards</button>' +
                '<div class="nav-menu" style="display:none;position:absolute;right:0;top:calc(100% + 2px);background:#fff;min-width:160px;box-shadow:0 2px 5px rgba(0,0,0,0.2);border:1px solid #dee2e6;border-radius:4px">' +
                    '<a href="<?php echo $CFG->wwwroot; ?>/local/quizdashboard/index.php" style="display:block;padding:8px 12px;color:#007cba;text-decoration:none;border-bottom:1px solid #dee2e6;font-size:13px' + (quizActive ? ';background:#e9ecef;font-weight:500' : '') + '">Quiz Dashboard</a>' +
                    '<a href="<?php echo $CFG->wwwroot; ?>/local/quizdashboard/essays.php" style="display:block;padding:8px 12px;color:#007cba;text-decoration:none;border-bottom:1px solid #dee2e6;font-size:13px' + (essayActive ? ';background:#e9ecef;font-weight:500' : '') + '">Essay Dashboard</a>' +
                    '<a href="<?php echo $CFG->wwwroot; ?>/local/quizdashboard/questions.php" style="display:block;padding:8px 12px;color:#007cba;text-decoration:none;border-bottom:1px solid #dee2e6;font-size:13px' + (questionsActive ? ';background:#e9ecef;font-weight:500' : '') + '">Questions Dashboard</a>' +
                    '<a href="<?php echo $CFG->wwwroot; ?>/local/essaysmaster/dashboard.php" style="display:block;padding:8px 12px;color:#007cba;text-decoration:none;font-size:13px' + (essaysmasterActive ? ';background:#e9ecef;font-weight:500' : '') + '">EssaysMaster Dashboard</a>' +
                '</div>' +
            '</div>';
            
            document.body.appendChild(nav);
            
            // Add hover functionality
            var dropdown = nav.querySelector('.nav-dropdown');
            var menu = nav.querySelector('.nav-menu');
            var button = nav.querySelector('.nav-button');
            
            if (dropdown && menu && button) {
                dropdown.addEventListener('mouseenter', function() {
                    menu.style.display = 'block';
                    button.style.background = '#005a87';
                });
                
                dropdown.addEventListener('mouseleave', function() {
                    menu.style.display = 'none';
                    button.style.background = '#007cba';
                });
                
                // Remove border from last menu item
                var menuItems = menu.querySelectorAll('a');
                if (menuItems.length > 0) {
                    menuItems[menuItems.length - 1].style.borderBottom = 'none';
                }
                
                console.log('DIRECT NAVIGATION: Successfully injected and functional!');
                return true;
            } else {
                console.error('DIRECT NAVIGATION: Failed to find navigation elements');
                return false;
            }
        }
        
        // Try multiple times to ensure it loads
        var attempts = 0;
        var maxAttempts = 10;
        
        function tryInject() {
            attempts++;
            console.log('DIRECT NAVIGATION: Attempt #' + attempts);
            
            if (forceInjectNavigation()) {
                console.log('DIRECT NAVIGATION: Success on attempt #' + attempts);
                return;
            }
            
            if (attempts < maxAttempts) {
                setTimeout(tryInject, 500);
            } else {
                console.error('DIRECT NAVIGATION: Failed after ' + maxAttempts + ' attempts');
            }
        }
        
        // Start immediately
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', tryInject);
        } else {
            tryInject();
        }
        
        // Also try after delays
        setTimeout(tryInject, 100);
        setTimeout(tryInject, 1000);
        </script>
        <?php
    }
}
