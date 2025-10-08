<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Library functions for Essays Master plugin.
 *
 * @package    local_essaysmaster
 * @copyright  2024 Essays Master Development Team
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

/**
 * Get essay questions from a quiz attempt
 *
 * @param int $attemptid Quiz attempt ID
 * @return array Array of essay question attempts
 */
function local_essaysmaster_get_essay_questions($attemptid) {
    global $DB;

    $sql = "SELECT qa.*, q.name, q.questiontext
            FROM {question_attempts} qa
            JOIN {question} q ON qa.questionid = q.id
            JOIN {quiz_attempts} quiza ON qa.questionusageid = quiza.uniqueid
            WHERE quiza.id = :attemptid
            AND q.qtype = 'essay'";

    return $DB->get_records_sql($sql, ['attemptid' => $attemptid]);
}

/**
 * Check if user can access a quiz attempt
 *
 * @param int $attemptid Quiz attempt ID
 * @param int $userid User ID
 * @return bool True if access allowed
 */
function local_essaysmaster_can_access_attempt($attemptid, $userid = null) {
    global $DB, $USER;

    if ($userid === null) {
        $userid = $USER->id;
    }

    $attempt = $DB->get_record('quiz_attempts', ['id' => $attemptid]);
    if (!$attempt) {
        return false;
    }

    // Check if user owns the attempt
    if ($attempt->userid == $userid) {
        return true;
    }

    // Check if user has grading capability
    $context = context_module::instance($attempt->quiz);
    return has_capability('mod/quiz:grade', $context);
}

/**
 * Get or create Essays Master session
 *
 * @param int $attemptid Quiz attempt ID
 * @param int $questionattemptid Question attempt ID
 * @param int $userid User ID
 * @return object Session record
 */
function local_essaysmaster_get_or_create_session($attemptid, $questionattemptid, $userid) {
    global $DB;

    $session = $DB->get_record('local_essaysmaster_sessions', [
        'attempt_id' => $attemptid,
        'question_attempt_id' => $questionattemptid,
        'user_id' => $userid
    ]);

    if (!$session) {
        $session = new stdClass();
        $session->attempt_id = $attemptid;
        $session->question_attempt_id = $questionattemptid;
        $session->user_id = $userid;
        $session->current_level = 1;
        $session->max_level = 3;
        $session->threshold_percentage = 80.0;
        $session->status = 'active';
        $session->session_start_time = time();
        $session->session_end_time = null;
        $session->final_submission_allowed = 0;
        $session->timecreated = time();
        $session->timemodified = time();

        $session->id = $DB->insert_record('local_essaysmaster_sessions', $session);
    }

    return $session;
}

/**
 * Get configuration for a quiz
 *
 * @param int $quizid Quiz ID
 * @return object|false Configuration record or false if not found
 */
function local_essaysmaster_get_quiz_config($quizid) {
    global $DB;

    return $DB->get_record('local_essaysmaster_config', ['quiz_id' => $quizid]);
}

/**
 * Add Essays Master feedback system to quiz attempt pages
 * TEMPORARY: Re-enabled for compatibility while debugging hook system
 */
function local_essaysmaster_before_footer() {
    global $PAGE, $DB;
    
    error_log("Essays Master LEGACY: Function called on page: " . $PAGE->url->out(false));

    // Only on mod/quiz attempt pages
    if (strpos($PAGE->url->out(false), '/mod/quiz/attempt.php') === false) {
        error_log("Essays Master LEGACY: Not a quiz attempt page, exiting");
        return;
    }
    
    error_log("Essays Master LEGACY: On quiz attempt page, proceeding...");

    // Get attempt ID from URL
    $attemptid = optional_param('attempt', 0, PARAM_INT);
    if (!$attemptid) {
        return;
    }

    // Check if this quiz has Essays Master enabled
    $attempt = $DB->get_record('quiz_attempts', ['id' => $attemptid]);
    if (!$attempt) {
        return;
    }

    $config = $DB->get_record('local_essaysmaster_config', [
        'quiz_id' => $attempt->quiz, 
        'is_enabled' => 1
    ]);

    if (!$config) {
        return;
    }

    // Check if quiz has essay questions
    $sql = "SELECT COUNT(*)
            FROM {quiz_slots} qs
            JOIN {question_references} qr ON qr.itemid = qs.id 
                AND qr.component = 'mod_quiz' 
                AND qr.questionarea = 'slot'
            JOIN {question_bank_entries} qbe ON qr.questionbankentryid = qbe.id
            JOIN {question_versions} qv ON qbe.id = qv.questionbankentryid
            JOIN {question} q ON qv.questionid = q.id
            WHERE qs.quizid = ? AND q.qtype = 'essay'";

    $essay_count = $DB->count_records_sql($sql, [$attempt->quiz]);
    if ($essay_count == 0) {
        return;
    }

    // Load the AMD module with diagnostic logging
    error_log("Essays Master: About to load AMD module for attempt {$attemptid}");
    
    $PAGE->requires->js_call_amd('local_essaysmaster/feedback', 'init', [
        [
            'maxRounds' => 3, 
            'attemptId' => $attemptid,
            'quizId' => $attempt->quiz
        ]
    ]);

    // Add inline JS to confirm loading and enforce spellcheck off + feedback hardening
    $js = <<<JS
        try {
            console.log("Essays Master: Legacy function executed - AMD module should load");
            console.log("Essays Master: Attempt ID = {$attemptid}");
            console.log("Essays Master: Quiz ID = {$attempt->quiz}");

            // Disable spellcheck/autocorrect sitewide on attempt page inputs/editors
            (function disableSpellcheckEverywhere(){
                var sels = ["textarea", "textarea[name*='answer']", "#essay-text"]; 
                sels.forEach(function(sel){
                    document.querySelectorAll(sel).forEach(function(el){
                        try {
                            el.setAttribute("spellcheck", "false");
                            el.setAttribute("data-spellcheck", "false");
                            el.setAttribute("autocomplete", "off");
                            el.setAttribute("autocorrect", "off");
                            el.setAttribute("data-autocorrect", "off");
                            el.setAttribute("data-autocomplete", "off");
                            el.setAttribute("data-gramm", "false");
                        } catch(e){}
                    });
                });

                // TinyMCE/Atto bodies
                if (window.tinyMCE || window.tinymce) {
                    var tmce = window.tinyMCE || window.tinymce;
                    try {
                        (tmce.editors || []).forEach(function(ed){
                            if (ed && typeof ed.getBody === 'function') {
                                var b = ed.getBody();
                                if (b) { b.setAttribute('spellcheck','false'); b.setAttribute('data-gramm','false'); }
                            }
                        });
                    } catch(e){}
                }
            })();

            // Harden feedback container if present
            (function hardenFeedback(){
                var c = document.getElementById('feedback-panel-container');
                if (c) {
                    ['copy','cut','paste','contextmenu','dragstart','selectstart'].forEach(function(evt){
                        c.addEventListener(evt, function(e){ e.preventDefault(); e.stopPropagation(); }, {passive:false});
                    });
                    c.setAttribute('oncontextmenu','return false');
                    c.setAttribute('oncopy','return false');
                    c.setAttribute('oncut','return false');
                    c.setAttribute('onpaste','return false');
                }
            })();
        } catch (e) { console.warn("Essays Master inline hardening failed", e); }
    JS;
    $PAGE->requires->js_init_code($js);

    error_log("Essays Master: Successfully called AMD module loader for attempt {$attemptid}");
}

/**
 * Add Essays Master Dashboard to global navigation
 * Called by Moodle's navigation system
 *
 * @param global_navigation $navigation The global navigation object
 */
function local_essaysmaster_extend_navigation(global_navigation $navigation) {
    global $USER, $PAGE;
    
    // Only add for logged in users
    if (!isloggedin() || isguestuser()) {
        return;
    }
    
    // Check if user has dashboard access capability
    $context = context_system::instance();
    if (!has_capability('local/essaysmaster:viewdashboard', $context)) {
        return;
    }
    
    // Initialize global navigation panel
    local_essaysmaster_after_config();
    
    // Create the dashboard navigation node
    $dashboardnode = $navigation->add(
        get_string('dashboard', 'local_essaysmaster'),
        new moodle_url('/local/essaysmaster/dashboard.php'),
        navigation_node::TYPE_CUSTOM,
        null,
        'essaysmasterdashboard',
        new pix_icon('t/edit', get_string('dashboard', 'local_essaysmaster'))
    );
    
    // Set as active if we're on the dashboard page
    if ($PAGE->url->compare(new moodle_url('/local/essaysmaster/dashboard.php'), URL_MATCH_BASE)) {
        $dashboardnode->make_active();
    }
}

/**
 * Global page initialization hook - Add EssaysMaster Dashboard navigation to every page
 * This ensures it loads on every page regardless of theme or navigation hooks
 */
function local_essaysmaster_after_config() {
    global $PAGE, $CFG;
    
    // Only add if user has permission and not on login pages
    if (has_capability('local/essaysmaster:viewdashboard', context_system::instance()) && 
        !preg_match('/login|logout/', $_SERVER['REQUEST_URI'])) {
        
        // Add inline JavaScript that creates global navigation
        $PAGE->requires->js_init_code('
            // Wait for DOM and add EssaysMaster Dashboard navigation
            document.addEventListener("DOMContentLoaded", function() {
                console.log("EssaysMaster: Adding global dashboard navigation...");
                
                // Remove any existing navigation first
                var existingNav = document.querySelector(".essaysmaster-global-nav");
                if (existingNav) {
                    existingNav.remove();
                }
                
                // Determine current page for active state
                var currentPath = window.location.pathname;
                var isDashboard = currentPath.includes("/local/essaysmaster/dashboard.php");
                
                // Create navigation
                var nav = document.createElement("div");
                nav.className = "essaysmaster-global-nav";
                nav.style.cssText = "position:fixed;top:60px;right:20px;z-index:10000;font-family:-apple-system,BlinkMacSystemFont,\\\"Segoe UI\\\",Roboto,sans-serif";
                
                nav.innerHTML = `
                    <div class="nav-link" style="position:relative;display:inline-block">
                        <a href="' . $CFG->wwwroot . '/local/essaysmaster/dashboard.php" 
                           class="nav-button" 
                           style="background:${isDashboard ? \'#28a745\' : \'#007cba\'};color:#fff;padding:8px 16px;border:1px solid:${isDashboard ? \'#1e7e34\' : \'#005a87\'};text-decoration:none;border-radius:4px;font-size:13px;font-weight:500;min-width:140px;text-align:center;box-shadow:0 2px 5px rgba(0,0,0,0.2);display:inline-block;transition:all 0.15s ease-in-out"
                           onmouseover="this.style.background=\'${isDashboard ? \'#218838\' : \'#005a87\'}\'"
                           onmouseout="this.style.background=\'${isDashboard ? \'#28a745\' : \'#007cba\'}\'"
                           title="Access EssaysMaster Dashboard">
                           ðŸ“Š EssaysMaster Dashboard
                        </a>
                    </div>
                `;
                
                document.body.appendChild(nav);
                console.log("EssaysMaster: Global dashboard navigation created successfully!");
            });
        ');
    }
}

/**
 * Check if Essays Master is enabled for a specific quiz
 * Default behavior: enabled for all essay quizzes unless explicitly disabled
 *
 * @param int $quizid Quiz ID
 * @return bool True if enabled (default), false if explicitly disabled
 */
function local_essaysmaster_is_quiz_enabled($quizid) {
    global $DB;
    
    // Check if there's a configuration record
    $config = $DB->get_record('local_essaysmaster_quiz_config', ['quiz_id' => $quizid]);
    
    if ($config) {
        // Explicit configuration exists - use that setting
        return (bool)$config->is_enabled;
    }
    
    // No configuration record - check if quiz has essay questions
    $sql = "SELECT COUNT(*) 
            FROM {quiz_slots} qs
            JOIN {question_references} qr ON qr.itemid = qs.id 
                AND qr.component = 'mod_quiz' 
                AND qr.questionarea = 'slot'
            JOIN {question_bank_entries} qbe ON qr.questionbankentryid = qbe.id
            JOIN {question_versions} qv ON qbe.id = qv.questionbankentryid
            JOIN {question} q ON qv.questionid = q.id
            WHERE qs.quizid = ? AND q.qtype = 'essay'";
    
    $essay_count = $DB->count_records_sql($sql, [$quizid]);
    
    // DEFAULT BEHAVIOR: Auto-enable if quiz has essay questions
    if ($essay_count > 0) {
        // Auto-create configuration record with enabled=1 (default)
        $quiz = $DB->get_record('quiz', ['id' => $quizid], 'id, course');
        if ($quiz) {
            $config = new stdClass();
            $config->quiz_id = $quizid;
            $config->course_id = $quiz->course;
            $config->is_enabled = 1; // DEFAULT: ENABLED
            $config->validation_thresholds = json_encode([
                'round_2' => 50,
                'round_4' => 50, 
                'round_6' => 50
            ]);
            $config->max_attempts_per_round = 3;
            $config->created_by = get_admin()->id;
            $config->modified_by = null;
            $config->timecreated = time();
            $config->timemodified = time();
            
            try {
                $DB->insert_record('local_essaysmaster_quiz_config', $config);
                error_log("Essays Master: Auto-enabled for quiz {$quizid} (default behavior)");
                return true;
            } catch (Exception $e) {
                error_log("Essays Master: Could not auto-enable quiz {$quizid}: " . $e->getMessage());
                return false;
            }
        }
    }
    
    // No essay questions found
    return false;
}