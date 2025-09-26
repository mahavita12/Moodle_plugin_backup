<?php
/**
 * Navigation callbacks for Quiz Dashboard plugin
 * File: local/quizdashboard/lib.php
 */

defined('MOODLE_INTERNAL') || die();

/**
 * Add items to the admin tree (keeping existing functionality)
 */
function local_quizdashboard_extend_settings_navigation($settingsnav, $context) {
    if (!has_capability('local/quizdashboard:view', context_system::instance())) {
        return;
    }
    

    
    if ($settingnode = $settingsnav->find('siteadministration', navigation_node::TYPE_SITE_ADMIN)) {
        $reportsnode = $settingnode->find('reports', navigation_node::TYPE_SETTING);
        if (!$reportsnode) {
            $reportsnode = navigation_node::create(
                get_string('reports'),
                null,
                navigation_node::TYPE_SETTING,
                null,
                'reports',
                new pix_icon('i/report', get_string('reports'))
            );
            $settingnode->add_node($reportsnode);
        }
        
        $quizurl = new moodle_url('/local/quizdashboard/index.php');
        $quiznode = navigation_node::create(
            'Quiz Dashboard',
            $quizurl,
            navigation_node::TYPE_SETTING,
            null,
            'quizdashboard',
            new pix_icon('i/report', 'Quiz Dashboard')
        );
        $reportsnode->add_node($quiznode);
        
        $essayurl = new moodle_url('/local/quizdashboard/essays.php');
        $essaynode = navigation_node::create(
            'Essay Dashboard', 
            $essayurl,
            navigation_node::TYPE_SETTING,
            null,
            'essaydashboard',
            new pix_icon('i/edit', 'Essay Dashboard')
        );
        $reportsnode->add_node($essaynode);
        
        // Add Questions Dashboard
        $questionsurl = new moodle_url('/local/quizdashboard/questions.php');
        $questionsnode = navigation_node::create(
            'Questions Dashboard', 
            $questionsurl,
            navigation_node::TYPE_SETTING,
            null,
            'questionsdashboard',
            new pix_icon('i/report', 'Questions Dashboard')
        );
        $reportsnode->add_node($questionsnode);
    }
}

/**
 * Hook into the flat navigation for newer Moodle themes
 */
function local_quizdashboard_extend_flat_navigation(\flat_navigation $flatnav) {
    global $PAGE;
    
    if (!has_capability('local/quizdashboard:view', context_system::instance())) {
        return;
    }
    

    
    // Add main dashboard
    $quiznode = navigation_node::create(
        'Quiz Dashboard',
        new moodle_url('/local/quizdashboard/index.php'),
        navigation_node::TYPE_CUSTOM,
        null,
        'quizdashboard_flat'
    );
    $quiznode->set_force_into_more_menu(false);
    $quiznode->key = 'quizdashboard_main';
    $flatnav->add($quiznode);
    
    // Add essay dashboard  
    $essaynode = navigation_node::create(
        'Essay Dashboard',
        new moodle_url('/local/quizdashboard/essays.php'), 
        navigation_node::TYPE_CUSTOM,
        null,
        'essaydashboard_flat'
    );
    $essaynode->set_force_into_more_menu(false);
    $essaynode->key = 'essaydashboard_main';
    $flatnav->add($essaynode);
    
    // Add questions dashboard
    $questionsnode = navigation_node::create(
        'Questions Dashboard',
        new moodle_url('/local/quizdashboard/questions.php'), 
        navigation_node::TYPE_CUSTOM,
        null,
        'questionsdashboard_flat'
    );
    $questionsnode->set_force_into_more_menu(false);
    $questionsnode->key = 'questionsdashboard_main';
    $flatnav->add($questionsnode);
}



/**
 * Hook into the global navigation structure
 */
function local_quizdashboard_extend_navigation(global_navigation $navigation) {
    if (!has_capability('local/quizdashboard:view', context_system::instance())) {
        return;
    }
    

    
    // Create main dashboard node
    $mainnode = navigation_node::create(
        'Quiz Dashboards',
        null,
        navigation_node::TYPE_CUSTOM,
        null,
        'quiz_dashboards_main'
    );
    
    // Add child nodes
    $quizurl = new moodle_url('/local/quizdashboard/index.php');
    $quiznode = $mainnode->add(
        'Quiz Dashboard',
        $quizurl,
        navigation_node::TYPE_CUSTOM
    );
    
    $essayurl = new moodle_url('/local/quizdashboard/essays.php');
    $essaynode = $mainnode->add(
        'Essay Dashboard',
        $essayurl,
        navigation_node::TYPE_CUSTOM
    );
    
    $questionsurl = new moodle_url('/local/quizdashboard/questions.php');
    $questionsnode = $mainnode->add(
        'Questions Dashboard',
        $questionsurl,
        navigation_node::TYPE_CUSTOM
    );
    
    // Force into primary navigation
    $mainnode->showinflatnavigation = true;
    $navigation->add_node($mainnode);
}

/**
 * GLOBAL navigation injection - appears on ALL Moodle pages
 * Uses the same approach as force_navigation.php but globally
 */
function local_quizdashboard_before_standard_top_of_body_html() {
    global $PAGE, $CFG;
    
    // Only run for logged-in users with capability  
    if (!CLI_SCRIPT && function_exists('has_capability')) {
        if (isloggedin() && !isguestuser() && has_capability('local/quizdashboard:view', context_system::instance()) && 
            !preg_match('/login|logout/', $_SERVER['REQUEST_URI'])) {
            
            // Direct JavaScript injection - same as force_navigation.php
            ?>
            <script>
            console.log('GLOBAL NAVIGATION: Starting injection...');
            
            function forceInjectNavigation() {
                // Remove any existing navigation
                var existing = document.querySelectorAll('.quiz-dashboard-global-nav');
                existing.forEach(function(el) { el.remove(); });
                
                console.log('GLOBAL NAVIGATION: Creating navigation element');
                
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
                    
                    console.log('GLOBAL NAVIGATION: Successfully injected and functional!');
                    return true;
                } else {
                    console.error('GLOBAL NAVIGATION: Failed to find navigation elements');
                    return false;
                }
            }
            
            // Try multiple times to ensure it loads
            var attempts = 0;
            var maxAttempts = 10;
            
            function tryInject() {
                attempts++;
                console.log('GLOBAL NAVIGATION: Attempt #' + attempts);
                
                if (forceInjectNavigation()) {
                    console.log('GLOBAL NAVIGATION: Success on attempt #' + attempts);
                    return;
                }
                
                if (attempts < maxAttempts) {
                    setTimeout(tryInject, 500);
                } else {
                    console.error('GLOBAL NAVIGATION: Failed after ' + maxAttempts + ' attempts');
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
    
    return '';
    
    // Determine current page for active state
    $current_url = $PAGE->url ? $PAGE->url->out(false) : '';
    $is_main_dashboard = strpos($current_url, '/local/quizdashboard/index.php') !== false;
    $is_essay_dashboard = strpos($current_url, '/local/quizdashboard/essays.php') !== false;
    $is_questions_dashboard = strpos($current_url, '/local/quizdashboard/questions.php') !== false;
    $is_essaysmaster_dashboard = strpos($current_url, '/local/essaysmaster/dashboard.php') !== false;
    
    return '
    <style>
    /* Global Dashboard Navigation Styles */
    .quiz-dashboard-global-nav {
        position: fixed;
        top: 60px;
        right: 60px;
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
        min-width: 100px;
        text-align: center;
        box-shadow: 0 2px 5px rgba(0,0,0,0.2);
        transition: background-color 0.15s ease-in-out;
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
        min-width: 160px;
        box-shadow: 0 2px 5px rgba(0,0,0,0.2);
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
        padding: 8px 12px;
        color: #007cba;
        text-decoration: none;
        border-bottom: 1px solid #dee2e6;
        font-size: 13px;
        transition: background-color 0.15s ease-in-out;
    }
    
    .quiz-dashboard-global-nav .nav-menu a:hover {
        background: #f8f9fa;
        color: #007cba;
    }
    
    .quiz-dashboard-global-nav .nav-menu a.active {
        background: #e9ecef;
        color: #007cba;
        font-weight: 500;
    }
    
    .quiz-dashboard-global-nav .nav-menu a:last-child {
        border-bottom: none;
    }
    
    /* Blocks Toggle Styles */
    .global-blocks-toggle {
        position: fixed;
        top: 130px;
        right: 15px;
        z-index: 9998;
        font-family: system-ui;
    }
    
    .blocks-toggle-btn {
        background: #f8f9fa;
        border: 1px solid #dee2e6;
        color: #495057;
        padding: 8px 12px;
        cursor: pointer;
        font-size: 12px;
        border-radius: 20px;
        box-shadow: 0 2px 8px rgba(0,0,0,0.1);
        display: flex;
        align-items: center;
        gap: 6px;
        min-width: 110px;
        justify-content: center;
        font-weight: 500;
    }
    
    .blocks-hidden-mode [data-region="blocks-column"],
    .blocks-hidden-mode .region_post,
    .blocks-hidden-mode .region-post,
    .blocks-hidden-mode #region-post,
    .blocks-hidden-mode [data-region="post"],
    .blocks-hidden-mode .block-region-post,
    .blocks-hidden-mode .block-region-side-post {
        display: none !important;
    }
    
    @media (max-width: 768px) {
        .quiz-dashboard-global-nav { top: 10px; right: 10px; }
        .quiz-dashboard-global-nav .nav-button { padding: 6px 10px; font-size: 12px; min-width: 90px; }
        .quiz-dashboard-global-nav .nav-menu { min-width: 140px; }
        .global-blocks-toggle { right: 10px; }
    }
    </style>
    
    <script>
    document.addEventListener("DOMContentLoaded", function() {
        console.log("Global navigation and blocks toggle initializing...");
        
        // Add blocks toggle functionality
        var blockSelectors = [
            "[data-region=\"blocks-column\"]",
            ".region_post", 
            ".region-post", 
            "#region-post",
            "[data-region=\"post\"]",
            ".block-region-post",
            ".block-region-side-post"
        ];
        
        var hasBlocks = blockSelectors.some(function(sel) {
            return document.querySelectorAll(sel).length > 0;
        });
        
        if (hasBlocks && !document.querySelector(".global-blocks-toggle")) {
            var isHidden = false;
            try {
                isHidden = localStorage.getItem("moodle_blocks_hidden") === "true";
            } catch(e) {}
            
            var toggleHtml = "<div class=\"global-blocks-toggle\">" +
                "<button type=\"button\" class=\"blocks-toggle-btn\" title=\"Toggle blocks panel visibility\">" +
                    "<span class=\"toggle-icon\">" + (isHidden ? "Show" : "Hide") + "</span>" +
                    "<span class=\"toggle-text\"> Blocks</span>" +
                "</button>" +
            "</div>";
            
            document.body.insertAdjacentHTML("beforeend", toggleHtml);
            
            var button = document.querySelector(".blocks-toggle-btn");
            var toggleClass = "blocks-hidden-mode";
            
            if (isHidden) {
                document.body.classList.add(toggleClass);
                button.style.background = "#007cba";
                button.style.color = "#fff";
                button.style.borderColor = "#005a87";
            }
            
            button.addEventListener("click", function() {
                var currentlyHidden = document.body.classList.contains(toggleClass);
                var newState = !currentlyHidden;
                
                if (newState) {
                    document.body.classList.add(toggleClass);
                    button.style.background = "#007cba";
                    button.style.color = "#fff";
                    button.style.borderColor = "#005a87";
                    button.querySelector(".toggle-icon").textContent = "Show";
                } else {
                    document.body.classList.remove(toggleClass);
                    button.style.background = "#f8f9fa";
                    button.style.color = "#495057";
                    button.style.borderColor = "#dee2e6";
                    button.querySelector(".toggle-icon").textContent = "Hide";
                }
                
                try {
                    localStorage.setItem("moodle_blocks_hidden", newState.toString());
                } catch(e) {}
            });
        }
        
        console.log("Global navigation and blocks toggle loaded successfully!");
    });
    </script>
    
    <div class="quiz-dashboard-global-nav" id="quiz-dashboard-global-nav">
        <div class="nav-dropdown">
            <button class="nav-button">Dashboards</button>
            <div class="nav-menu">
                <a href="' . $CFG->wwwroot . '/local/quizdashboard/index.php"' . 
                    ($is_main_dashboard ? ' class="active"' : '') . '>Quiz Dashboard</a>
                <a href="' . $CFG->wwwroot . '/local/quizdashboard/essays.php"' .
                    ($is_essay_dashboard ? ' class="active"' : '') . '>Essay Dashboard</a>
                <a href="' . $CFG->wwwroot . '/local/quizdashboard/questions.php"' .
                    ($is_questions_dashboard ? ' class="active"' : '') . '>Questions Dashboard</a>
                <a href="' . $CFG->wwwroot . '/local/essaysmaster/dashboard.php"' .
                    ($is_essaysmaster_dashboard ? ' class="active"' : '') . '>EssaysMaster Dashboard</a>
            </div>
        </div>
    </div>';
}
