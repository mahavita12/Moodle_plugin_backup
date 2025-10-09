<?php
/**
 * Library functions for Quiz Uploader plugin
 * File: local/quiz_uploader/lib.php
 */

defined('MOODLE_INTERNAL') || die();

/**
 * GLOBAL navigation injection - appears on ALL Moodle pages
 * Adds "Quiz Uploader" to the global Dashboards dropdown menu
 */
function local_quiz_uploader_before_standard_top_of_body_html() {
    global $PAGE, $CFG;

    // Navigation is handled by Quiz Dashboard plugin
    // The Quiz Dashboard dropdown already includes "Quiz Uploader" link
    return '';

    // Only run for logged-in users with capability
    if (!CLI_SCRIPT && function_exists('has_capability')) {
        if (isloggedin() && !isguestuser() &&
            (has_capability('local/quiz_uploader:uploadquiz', context_system::instance()) || is_siteadmin()) &&
            !preg_match('/login|logout/', $_SERVER['REQUEST_URI'])) {

            // Direct JavaScript injection - adds Quiz Uploader to existing dropdown
            ?>
            <script>
            console.log('QUIZ UPLOADER NAVIGATION: Starting injection...');

            function injectQuizUploaderNav() {
                // Look for existing quiz-dashboard-global-nav
                var existingNav = document.querySelector('.quiz-dashboard-global-nav');

                if (existingNav) {
                    console.log('QUIZ UPLOADER NAVIGATION: Found existing navigation dropdown');

                    // Find the menu
                    var menu = existingNav.querySelector('.nav-menu');

                    if (menu) {
                        // Check if Quiz Uploader already exists
                        var existing = menu.querySelector('a[href*="/local/quiz_uploader/upload.php"]');
                        if (existing) {
                            console.log('QUIZ UPLOADER NAVIGATION: Link already exists');
                            return true;
                        }

                        // Check current page for active state
                        var currentPath = window.location.pathname;
                        var uploaderActive = currentPath.includes('/local/quiz_uploader/upload.php');

                        // Create Quiz Uploader link (positioned at the bottom before EssaysMaster)
                        var uploaderLink = document.createElement('a');
                        uploaderLink.href = '<?php echo $CFG->wwwroot; ?>/local/quiz_uploader/upload.php';
                        uploaderLink.style.cssText = 'display:block;padding:8px 12px;color:#007cba;text-decoration:none;font-size:13px' +
                            (uploaderActive ? ';background:#e9ecef;font-weight:500' : '');
                        uploaderLink.textContent = 'Quiz Uploader';

                        // Add hover effect
                        uploaderLink.addEventListener('mouseenter', function() {
                            if (!uploaderActive) {
                                this.style.background = '#f8f9fa';
                            }
                        });
                        uploaderLink.addEventListener('mouseleave', function() {
                            if (!uploaderActive) {
                                this.style.background = '';
                            }
                        });

                        // Find EssaysMaster link (last item) and insert before it
                        var essaysmasterLink = menu.querySelector('a[href*="/local/essaysmaster/"]');
                        if (essaysmasterLink) {
                            // Add border to uploader link
                            uploaderLink.style.borderBottom = '1px solid #dee2e6';
                            menu.insertBefore(uploaderLink, essaysmasterLink);
                            console.log('QUIZ UPLOADER NAVIGATION: Inserted before EssaysMaster');
                        } else {
                            // If no EssaysMaster, add at the end
                            // Remove border from previous last item
                            var links = menu.querySelectorAll('a');
                            if (links.length > 0) {
                                links[links.length - 1].style.borderBottom = '1px solid #dee2e6';
                            }
                            menu.appendChild(uploaderLink);
                            uploaderLink.style.borderBottom = 'none';
                            console.log('QUIZ UPLOADER NAVIGATION: Added at the end');
                        }

                        console.log('QUIZ UPLOADER NAVIGATION: Successfully injected!');
                        return true;
                    } else {
                        console.error('QUIZ UPLOADER NAVIGATION: Menu not found');
                        return false;
                    }
                } else {
                    // If no existing navigation, create our own standalone button
                    console.log('QUIZ UPLOADER NAVIGATION: No existing dropdown, creating standalone...');

                    var nav = document.createElement('div');
                    nav.className = 'quiz-uploader-standalone-nav';
                    nav.style.cssText = 'position:fixed;top:60px;right:60px;z-index:10001;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif';

                    var currentPath = window.location.pathname;
                    var uploaderActive = currentPath.includes('/local/quiz_uploader/upload.php');

                    nav.innerHTML = '<a href="<?php echo $CFG->wwwroot; ?>/local/quiz_uploader/upload.php" ' +
                        'style="background:' + (uploaderActive ? '#005a87' : '#007cba') + ';color:#fff;padding:8px 12px;border:1px solid #005a87;' +
                        'text-decoration:none;border-radius:4px;font-size:13px;font-weight:400;display:inline-block;' +
                        'box-shadow:0 2px 5px rgba(0,0,0,0.2);transition:background 0.15s ease">Quiz Uploader</a>';

                    document.body.appendChild(nav);

                    // Add hover effect
                    var link = nav.querySelector('a');
                    if (!uploaderActive) {
                        link.addEventListener('mouseenter', function() {
                            this.style.background = '#005a87';
                        });
                        link.addEventListener('mouseleave', function() {
                            this.style.background = '#007cba';
                        });
                    }

                    console.log('QUIZ UPLOADER NAVIGATION: Standalone button created!');
                    return true;
                }
            }

            // Try multiple times to ensure it loads (wait for quizdashboard nav to load first)
            var attempts = 0;
            var maxAttempts = 15;

            function tryInject() {
                attempts++;
                console.log('QUIZ UPLOADER NAVIGATION: Attempt #' + attempts);

                if (injectQuizUploaderNav()) {
                    console.log('QUIZ UPLOADER NAVIGATION: Success on attempt #' + attempts);
                    return;
                }

                if (attempts < maxAttempts) {
                    setTimeout(tryInject, 500);
                } else {
                    console.error('QUIZ UPLOADER NAVIGATION: Failed after ' + maxAttempts + ' attempts');
                }
            }

            // Start after a delay to ensure quizdashboard nav loads first
            setTimeout(tryInject, 1000);
            setTimeout(tryInject, 2000);
            setTimeout(tryInject, 3000);
            </script>
            <?php
        }
    }

    return '';
}
