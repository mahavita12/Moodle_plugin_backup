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
 * Hook implementation for before footer HTML generation.
 *
 * @package    local_questionflags
 * @copyright  2024 Question Flags Development Team
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_questionflags\hook\output;

defined('MOODLE_INTERNAL') || die();

/**
 * Hook: runs just before the footer HTML is generated (Moodle 4.4+).
 */
class before_footer_html_generation {
    /**
     * Hook callback for before footer HTML generation.
     *
     * @param \core\hook\output\before_footer_html_generation $hook
     */
    public static function callback(\core\hook\output\before_footer_html_generation $hook): void {
        global $PAGE, $DB, $USER;

        // Only load on quiz pages
        if ($PAGE->pagetype !== 'mod-quiz-attempt' && $PAGE->pagetype !== 'mod-quiz-review') {
            return;
        }

        $cmid = $PAGE->cm->id ?? 0;
        if (!$cmid) {
            return;
        }

        $quizid = (int)$PAGE->cm->instance;

        // Include the lib.php file for helper functions
        require_once($GLOBALS['CFG']->dirroot . '/local/questionflags/lib.php');

        // Handle on-demand generation of structure guide (teacher only)
        if ($_POST && isset($_POST['generate_guide']) && confirm_sesskey()) {
            require_once($GLOBALS['CFG']->dirroot . '/local/questionflags/classes/ai_helper.php');
            require_capability('moodle/course:manageactivities', \context_course::instance($PAGE->course->id));

            $questionid = required_param('questionid', PARAM_INT);
            $prompt = local_questionflags_get_question_prompt_plain($questionid);
            $content = '';
            if (!empty($prompt)) {
                try {
                    $helper = new \local_questionflags\ai_helper();
                    $result = $helper->generate_structure_guide($prompt, 'secondary', 'en-AU');
                    if (!empty($result['success'])) {
                        $content = $result['guide'];
                    } else {
                        error_log('QUESTIONFLAGS: Guide generation failed - ' . ($result['message'] ?? 'unknown'));
                        $content = 'Generation temporarily unavailable. Please try again later.';
                    }
                } catch (\Throwable $e) {
                    error_log('QUESTIONFLAGS: Guide generation exception - ' . $e->getMessage());
                    $content = 'Generation error. Please try again later.';
                }
                if ($content !== '') {
                    local_questionflags_save_question_guide($questionid, $content);
                }
            } else {
                error_log('QUESTIONFLAGS: Empty prompt for question ' . $questionid);
            }
            redirect($PAGE->url);
        }

        // Handle save_reason AJAX (auto-save from textarea blur)
        if ($_POST && isset($_POST['save_reason']) && confirm_sesskey()) {
            $questionid = required_param('questionid', PARAM_INT);
            $reason = optional_param('reason', '', PARAM_RAW);
            
            // Get Question Bank Entry ID to find siblings
            $qbeid = $DB->get_field('question_versions', 'questionbankentryid', ['questionid' => $questionid]);
            
            $target_questionids = [$questionid];
            
            // If we found a QBE ID, get all sibling question IDs (versions of the same question)
            if ($qbeid) {
                // Get all question IDs that belong to this entry
                $siblings = $DB->get_fieldset_select('question_versions', 'questionid', 'questionbankentryid = ?', [$qbeid]);
                if ($siblings) {
                    $target_questionids = array_unique(array_merge($target_questionids, $siblings));
                }
            }
            
            // Update all existing flag records for these questions
            if (!empty($target_questionids)) {
                list($insql, $inparams) = $DB->get_in_or_equal($target_questionids);
                
                // We only update EXISTING records. The note box only shows if a flag exists.
                // If a flag exists, a record exists.
                $sql = "UPDATE {local_questionflags} 
                        SET reason = ?, timemodified = ? 
                        WHERE userid = ? AND questionid $insql";
                        
                $params = array_merge([$reason, time(), $USER->id], $inparams);
                $DB->execute($sql, $params);
            }
            
            // Return JSON response (no redirect for AJAX)
            header('Content-Type: application/json');
            echo json_encode(['success' => true]);
            exit;
        }

        // Handle structure guide updates - NOW STORES IN QUESTION METADATA
        if ($_POST && isset($_POST['update_guide']) && confirm_sesskey()) {
            error_log("Structure guide update request received");
            error_log("POST data: " . print_r($_POST, true));
            
            $questionid = required_param('questionid', PARAM_INT);
            $guide_content = optional_param('guide_content', '', PARAM_RAW);
            
            error_log("Question ID: $questionid");
            error_log("Guide content length: " . strlen($guide_content));
            
            // Do not overwrite existing guide with empty content
            if (trim($guide_content) === '') {
                redirect($PAGE->url);
            }
            
            // Store in question metadata table (works across all quizzes)
            $success = local_questionflags_save_question_guide($questionid, $guide_content);
            
            if ($success) {
                error_log("Successfully saved structure guide for question $questionid");
            } else {
                error_log("Failed to save structure guide for question $questionid");
            }
            
            redirect($PAGE->url);
        }

        // Handle flag submissions
        if ($_POST && isset($_POST['flag_action']) && confirm_sesskey()) {
            $questionid = required_param('questionid', PARAM_INT);
            $flagcolor = required_param('flagcolor', PARAM_ALPHA);
            $current_state = optional_param('current_state', '', PARAM_ALPHA);

            if (in_array($flagcolor, ['blue', 'red'])) {
                $time = time();
                $cmid = $PAGE->cm->id ?? 0;
                $quizid = null;
                if ($cmid) {
                    $cm = get_coursemodule_from_id('quiz', $cmid);
                    if ($cm) { $quizid = (int)$cm->instance; }
                }

                $qbeid = $DB->get_field('question_versions', 'questionbankentryid', ['questionid' => (int)$questionid], IGNORE_MISSING);
                $siblings = [];
                if (!empty($qbeid)) {
                    $siblings = $DB->get_fieldset_select('question_versions', 'questionid', 'questionbankentryid = ?', [(int)$qbeid]);
                }
                if (!empty($siblings)) {
                    list($in, $inparams) = $DB->get_in_or_equal($siblings, SQL_PARAMS_QM);
                    $DB->delete_records_select('local_questionflags', 'userid = ? AND questionid ' . $in, array_merge([(int)$USER->id], $inparams));
                } else {
                    $DB->delete_records('local_questionflags', [
                        'userid' => $USER->id,
                        'questionid' => $questionid
                    ]);
                }

                if ($current_state !== $flagcolor) {
                    $record = new \stdClass();
                    $record->userid = $USER->id;
                    $record->questionid = $questionid;
                    $record->flagcolor = $flagcolor;
                    $record->cmid = $cmid ?: null;
                    $record->quizid = $quizid;
                    $record->timecreated = $time;
                    $record->timemodified = $time;
                    $insertid = $DB->insert_record('local_questionflags', $record);
                    if (!empty($siblings)) {
                        foreach ($siblings as $sid) {
                            $sid = (int)$sid;
                            if ($sid === (int)$questionid) { continue; }
                            if (!$DB->record_exists('local_questionflags', ['userid' => (int)$USER->id, 'questionid' => $sid])) {
                                $r2 = new \stdClass();
                                $r2->userid = (int)$USER->id;
                                $r2->questionid = $sid;
                                $r2->flagcolor = $flagcolor;
                                $r2->cmid = $cmid ?: null;
                                $r2->quizid = $quizid;
                                $r2->timecreated = $time;
                                $r2->timemodified = $time;
                                try { $DB->insert_record('local_questionflags', $r2, false); } catch (\Throwable $e) {}
                            }
                        }
                    }

                    $origin = ($PAGE->pagetype === 'mod-quiz-review') ? 'review' : (($PAGE->pagetype === 'mod-quiz-attempt') ? 'attempt' : '');
                    $event = \local_questionflags\event\flag_added::create([
                        'context' => \context_module::instance($cmid),
                        'objectid' => $insertid,
                        'relateduserid' => $USER->id,
                        'other' => [
                            'questionid' => $questionid,
                            'flagcolor' => $flagcolor,
                            'cmid' => $cmid,
                            'quizid' => $quizid,
                            'origin' => $origin,
                        ],
                    ]);
                    error_log("[questionflags] Triggering flag_added event for question $questionid");
                    $event->trigger();
                    error_log("[questionflags] flag_added event triggered successfully");
                } else {
                    $origin = ($PAGE->pagetype === 'mod-quiz-review') ? 'review' : (($PAGE->pagetype === 'mod-quiz-attempt') ? 'attempt' : '');
                    $event = \local_questionflags\event\flag_removed::create([
                        'context' => \context_module::instance($cmid),
                        'objectid' => 0,
                        'relateduserid' => $USER->id,
                        'other' => [
                            'questionid' => $questionid,
                            'flagcolor' => $flagcolor,
                            'cmid' => $cmid,
                            'quizid' => $quizid,
                            'origin' => $origin,
                        ],
                    ]);
                    error_log("[questionflags] Triggering flag_removed event for question $questionid");
                    $event->trigger();
                    error_log("[questionflags] flag_removed event triggered successfully");
                }
            }

            redirect($PAGE->url);
        }

        // Get existing flags
        $existing_flags = $DB->get_records('local_questionflags', ['userid' => $USER->id]);
        $user_flags = [];
        $user_reasons = [];
        
        foreach ($existing_flags as $flag) {
            $user_flags[$flag->questionid] = $flag->flagcolor;
            if (!empty($flag->reason)) {
                $user_reasons[$flag->questionid] = $flag->reason;
            }
        }

        // Get structure guides from question metadata
        // OPTIMIZATION: Only load heavy guide data if the quiz actually contains essay questions.
        $quizid = $PAGE->activityrecord->id ?? 0;
        $hasessay = false;
        if ($quizid) {
             $hasessay = $DB->record_exists_sql(
                "SELECT 1
                   FROM {quiz_slots} qs
                   JOIN {question_references} qr ON qr.itemid = qs.id
                        AND qr.component = 'mod_quiz' AND qr.questionarea = 'slot'
                   JOIN {question_versions} qv ON qv.questionbankentryid = qr.questionbankentryid
                   JOIN {question} q ON q.id = qv.questionid
                  WHERE qs.quizid = ? AND q.qtype = 'essay'",
                [$quizid]
            );
        }

        $guides_data = [];
        if ($hasessay) {
            $guides_data = local_questionflags_get_quiz_guides($PAGE);
        }

        // ROBUST QUESTION ID MAPPING - try multiple methods
        $question_mapping = [];
        $attemptid = null;
        
        // Get attempt ID from multiple sources
        if (isset($PAGE->url) && $PAGE->url->get_param('attempt')) {
            $attemptid = $PAGE->url->get_param('attempt');
        } elseif (optional_param('attempt', 0, PARAM_INT)) {
            $attemptid = optional_param('attempt', 0, PARAM_INT);
        } elseif (isset($_GET['attempt'])) {
            $attemptid = (int)$_GET['attempt'];
        }
        
        if ($attemptid) {
            try {
                // Method 1: Get from current attempt's question usage
                $sql = "SELECT qatt.slot, qatt.questionid 
                        FROM {quiz_attempts} qa
                        JOIN {question_attempts} qatt ON qatt.questionusageid = qa.uniqueid
                        WHERE qa.id = ?
                        ORDER BY qatt.slot";
                $mapping_records = $DB->get_records_sql($sql, [$attemptid]);
                
                foreach ($mapping_records as $record) {
                    $question_mapping[$record->slot] = $record->questionid;
                }
                
                error_log("QUESTIONFLAGS: Method 1 - Loaded " . count($question_mapping) . " question mappings from attempt $attemptid");
                error_log("QUESTIONFLAGS: Question mapping: " . json_encode($question_mapping));
            } catch (\Exception $e) {
                error_log("QUESTIONFLAGS: Failed to load question mapping: " . $e->getMessage());
            }
        } else {
            error_log("QUESTIONFLAGS: No attempt ID found - mapping will be limited");
        }

        $sesskey = sesskey();
        $user_flags_json = json_encode($user_flags);
        $user_reasons_json = json_encode($user_reasons);
        $guides_json = json_encode($guides_data);
        $question_mapping_json = json_encode($question_mapping);
        $page_type = $PAGE->pagetype;
        $is_teacher = has_capability('moodle/course:manageactivities', \context_course::instance($PAGE->course->id));
        
        // Output CSS and JavaScript via hook API (avoid direct echo)
        ob_start();
        self::output_question_flags_assets($user_flags_json, $user_reasons_json, $guides_json, $question_mapping_json, $page_type, $is_teacher, $sesskey);
        
        // Load AMD module for flag box textarea functionality
        $PAGE->requires->js_call_amd('local_questionflags/flagbox', 'init');
        
        // Add feedback toggle button HTML directly for review pages  
        if ($page_type === 'mod-quiz-review') {
            echo '<script>
            (function(){
                document.addEventListener("DOMContentLoaded", function() {
                    // Find all question containers
                    var questions = document.querySelectorAll(".que");
                    if (questions.length === 0) return;
                    
                    // Detect quiz display mode: check if we\'re viewing one page at a time
                    // In "one page at a time" mode, there\'s typically only 1-2 questions visible
                    // In "show all" mode, there are many questions
                    var isOnePageMode = questions.length <= 2;
                    
                    console.log("FEEDBACK TOGGLE: Questions count:", questions.length, "Mode:", isOnePageMode ? "one-page-at-a-time" : "show-all");
                    
                    // Unified Logic: Support both One-Page and Multi-Page via Server-side rendering
                    // OPTIMIZATION: Button is rendered server-side. JS only handles the toggle event.
                        document.body.addEventListener("click", function(e) {
                            // use closest in case of inner elements
                            var btn = e.target.closest(".question-feedback-btn");
                            if (btn) {
                                var qContainer = btn.closest(".que");
                                
                                if (qContainer) {
                                    var isHidden = qContainer.classList.contains("hide-question-feedback");
                                    if (isHidden) {
                                        qContainer.classList.remove("hide-question-feedback");
                                        btn.textContent = "Hide Feedback";
                                        btn.className = "btn btn-info question-feedback-btn";
                                    } else {
                                        qContainer.classList.add("hide-question-feedback");
                                        btn.textContent = "Feedback";
                                        btn.className = "btn btn-primary question-feedback-btn";
                                    }
                                    // Reset styles
                                    btn.style.padding = "8px 16px";
                                    btn.style.fontSize = "13px";
                                    btn.style.fontWeight = "bold";
                                }
                            }
                        });
                });
            })();
            </script>';
        }
        
        $hook->add_html(ob_get_clean());
    }

    /**
     * Output the CSS and JavaScript for the question flags functionality.
     *
     * @param string $user_flags_json JSON encoded user flags
     * @param string $guides_json JSON encoded guides data
     * @param string $question_mapping_json JSON encoded question mapping
     * @param string $page_type Moodle page type
     * @param bool $is_teacher Whether user is a teacher
     * @param string $sesskey Session key
     */
    private static function output_question_flags_assets($user_flags_json, $user_reasons_json, $guides_json, $question_mapping_json, $page_type, $is_teacher, $sesskey) {
        // Output CSS styles
        echo '<style>
/* QUESTION FLAGS PLUGIN STYLES */
.question-flag-container { 
    margin: 5px 0; 
    display: flex;
    flex-direction: column;
    align-items: flex-start;
    gap: 3px;
}

.flag-btn { 
    background: rgba(0, 0, 0, 0.02);
    border: 1px solid;
    padding: 4px 8px;
    margin: 0;
    font-size: 12px;
    line-height: 1.4;
    text-decoration: none;
    cursor: pointer;
    border-radius: 3px;
    display: inline-block;
    transition: all 0.2s ease;
    width: 80px;
    white-space: nowrap;
}

.flag-btn:hover { 
    background-color: rgba(0, 0, 0, 0.05);
}

.flag-btn.blue { 
    border-color: #007bff;
    background-color: rgba(0, 123, 255, 0.05);
}

.flag-btn.blue .text { 
    color: #007bff;
}

.flag-btn.blue.active {
    background-color: #007bff;
    color: white;
}

.flag-btn.blue.active .text {
    color: white;
}

.flag-btn.red { 
    border-color: #dc3545;
    background-color: rgba(220, 53, 69, 0.05);
}

.flag-btn.red .text { 
    color: #dc3545;
}

.flag-btn.red.active {
    background-color: #dc3545;
    color: white;
}

.flag-btn.red.active .text {
    color: white;
}

.question-flagged-blue .formulation { 
    border-left: 6px solid #007bff !important; 
    background: rgba(0, 123, 255, 0.15) !important;
    padding: 10px !important;
    border-radius: 4px !important;
}

.question-flagged-red .formulation { 
    border-left: 6px solid #dc3545 !important; 
    background: rgba(220, 53, 69, 0.15) !important;
    padding: 10px !important;
    border-radius: 4px !important;
}

.que.blue-flagged-review {
    border: 3px solid #007bff !important;
    border-radius: 6px !important;
    background: rgba(0, 123, 255, 0.05) !important;
}

.que.red-flagged-review {
    border: 3px solid #dc3545 !important;
    border-radius: 6px !important;
    background: rgba(220, 53, 69, 0.05) !important;
}

.qnbutton { 
    position: relative; 
}

.qnbutton.blue-flagged::after { 
    content: "";
    position: absolute;
    top: 2px;
    right: 2px;
    width: 0;
    height: 0;
    border-left: 8px solid transparent;
    border-top: 8px solid #007bff;
    z-index: 10;
}

.qnbutton.red-flagged::after { 
    content: "";
    position: absolute;
    top: 2px;
    right: 2px;
    width: 0;
    height: 0;
    border-left: 8px solid transparent;
    border-top: 8px solid #dc3545;
    z-index: 10;
}

.info .flag,
.info a.qabtn,
a.qabtn[title*="Flag"],
a[aria-label*="Flag"],
*[title*="Flag this question"] { 
    display: none !important; 
}

/* STRUCTURE GUIDE STYLES */
.structure-guide-box {
    margin: 15px 0;
    border: 2px solid #1f8ce6;
    border-radius: 8px;
    background: #f8f9fa;
    max-width: 600px;
}

.guide-header {
    background: transparent;
    color: #1f8ce6;
    padding: 12px 15px;
    font-weight: bold;
    font-size: 14px;
    cursor: pointer;
    user-select: none;
    width: 100%;
    margin: 0;
    border-bottom: 1px solid #e0e0e0;
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.guide-header:hover {
    background: rgba(31, 140, 230, 0.1);
    color: #1976d2;
}

.toggle-arrow {
    font-size: 12px;
    transition: transform 0.3s ease;
    color: #1f8ce6;
}

.toggle-arrow.expanded {
    transform: rotate(180deg);
}

.guide-content {
    padding: 15px;
    background: white;
    border-top: 1px solid #1f8ce6;
    line-height: 1.6;
    font-family: Arial, Helvetica, sans-serif;
    font-size: 14px;
}

.guide-content h4, .guide-content h5 {
    margin: 10px 0 5px 0;
    font-weight: bold;
}

.guide-content strong {
    font-weight: bold;
    color: #333;
}

.guide-content em {
    font-style: italic;
    color: #555;
}

.guide-edit {
    padding: 15px;
    background: white;
    border-top: 1px solid #1f8ce6;
    display: none;
}

.guide-edit textarea {
    width: 100%;
    height: 100px;
    border: 1px solid #ccc;
    border-radius: 4px;
    padding: 10px;
    margin-bottom: 10px;
}

.guide-edit button {
    margin-right: 10px;
    padding: 8px 15px;
    border: none;
    border-radius: 4px;
    cursor: pointer;
    font-size: 12px;
}

.save-btn {
    background: #28a745;
    color: white;
}

.cancel-btn {
    background: #6c757d;
    color: white;
}

/* FEEDBACK TOGGLE STYLES */
.feedback-toggle-container {
    position: fixed;
    top: 80px;
    right: 20px;
    z-index: 1000;
    background: white;
    padding: 10px 15px;
    border-radius: 8px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.15);
}

.feedback-toggle-btn {
    padding: 8px 16px;
    background-color: #f39c12;
    color: white;
    border: none;
    border-radius: 4px;
    cursor: pointer;
    font-size: 14px;
    font-weight: bold;
}

.feedback-toggle-btn:hover {
    background-color: #e67e22;
}

.feedback-toggle-btn.active {
    background-color: #27ae60;
}

/* Hide all feedback-related elements - GLOBAL MODE (one page at a time) */
body.hide-feedback .specificfeedback,
body.hide-feedback .generalfeedback,
body.hide-feedback .rightanswer,
body.hide-feedback .outcome,
body.hide-feedback .im-feedback,
body.hide-feedback .feedback,
body.hide-feedback .state,
body.hide-feedback .grade,
body.hide-feedback .gradingdetails,
body.hide-feedback .comment {
    display: none !important;
}

/* Remove background colors and borders indicating correctness - GLOBAL MODE */
body.hide-feedback .que.correct,
body.hide-feedback .que.incorrect,
body.hide-feedback .que.partiallycorrect,
body.hide-feedback .que.notanswered {
    background-color: transparent !important;
    border-left: none !important;
}

body.hide-feedback .formulation {
    background-color: transparent !important;
}

/* Hide feedback for individual questions - PER-QUESTION MODE (show all questions) */
.que.hide-question-feedback .specificfeedback,
.que.hide-question-feedback .generalfeedback,
.que.hide-question-feedback .rightanswer,
.que.hide-question-feedback .outcome,
.que.hide-question-feedback .im-feedback,
.que.hide-question-feedback .feedback,
.que.hide-question-feedback .state,
.que.hide-question-feedback .grade,
.que.hide-question-feedback .gradingdetails,
.que.hide-question-feedback .comment {
    display: none !important;
}

/* Remove background colors and borders indicating correctness - PER-QUESTION MODE */
.que.hide-question-feedback.correct,
.que.hide-question-feedback.incorrect,
.que.hide-question-feedback.partiallycorrect,
.que.hide-question-feedback.notanswered {
    background-color: transparent !important;
    border-left: none !important;
}

.que.hide-question-feedback .formulation {
    background-color: transparent !important;
}
</style>';
        
        // Output JavaScript with proper formatting function
        echo '<script>
    window.questionFlagsData = ' . $user_flags_json . ';
    window.questionFlagReasons = ' . $user_reasons_json . ';
    window.structureGuidesData = ' . $guides_json . ';
    window.questionMapping = ' . $question_mapping_json . ';
    window.moodlePageType = "' . $page_type . '";
    window.isTeacher = ' . ($is_teacher ? 'true' : 'false') . ';
    window.qfSesskey = "' . $sesskey . '";

    // Submit flag via AJAX to avoid form submission conflict
    window.submitFlag = function(questionId, flagColor, currentState) {
        var formData = new FormData();
        formData.append("flag_action", "1");
        formData.append("questionid", questionId);
        formData.append("flagcolor", flagColor);
        formData.append("current_state", currentState);
        formData.append("sesskey", window.qfSesskey);
        
        fetch(window.location.href, {
            method: "POST",
            body: formData
        }).then(function() {
            window.location.reload();
        });
    };

    document.addEventListener("DOMContentLoaded", function() {
        console.log("Question flags loaded:", window.questionFlagsData);
        // console.log("Structure guides loaded:", window.structureGuidesData);
        console.log("Question mapping loaded:", window.questionMapping);
        console.log("FEEDBACK TOGGLE: Page type is:", window.moodlePageType);
        
        // Format guide content: convert plain text to HTML with proper formatting
        function formatGuideContent(content) {
            if (!content || content.trim() === "") {
                return "No structure guide set yet.";
            }
            
            // Escape HTML entities for security
            var escaped = content
                .replace(/&/g, "&amp;")
                .replace(/</g, "&lt;")
                .replace(/>/g, "&gt;")
                .replace(/"/g, "&quot;")
                .replace(/\'/g, "&#039;");
            
            // Convert line breaks to HTML
            var formatted = escaped.replace(/\\n/g, "<br>");
            // Strip any stray markdown tokens
            formatted = formatted
                .replace(/\*\*(.*?)\*\*/g, "$1")
                .replace(/__(.*?)__/g, "$1")
                .replace(/^#\s+/gm, "")
                .replace(/^##\s+/gm, "")
                .replace(/`/g, "");
            // Enhance Label: content lines (blue bold label)
            formatted = formatted.replace(/(^|<br>)\s*([A-Z][A-Za-z0-9 ]{2,20}):\s+/g, function(m, br, label){
                return (br || "") + "<span style=\"color:#1f8ce6;font-weight:bold;\">" + label + ":</span> ";
            });
            
            // Add some basic formatting for common patterns
            formatted = formatted
                // Bold text patterns: **text** or __text__
                .replace(/\\*\\*(.*?)\\*\\*/g, "<strong>$1</strong>")
                .replace(/__(.*?)__/g, "<strong>$1</strong>")
                // Headers: lines starting with #
                .replace(/^# (.*?)(<br>|$)/gm, "<h4 style=\\"color: #1f8ce6; margin: 10px 0 5px 0;\\">$1</h4>")
                .replace(/^## (.*?)(<br>|$)/gm, "<h5 style=\\"color: #1f8ce6; margin: 8px 0 4px 0;\\">$1</h5>")
                // Bullet points: lines starting with - or *
                .replace(/^[\\-\\*] (.*?)(<br>|$)/gm, "<div style=\\"margin-left: 15px;\\">• $1</div>")
                // Numbered lists: lines starting with numbers
                .replace(/^(\\d+)\\. (.*?)(<br>|$)/gm, "<div style=\\"margin-left: 15px;\\">$1. $2</div>");
            
            return formatted;
        }
        
        function getQuestionId(questionElement) {
            var id = questionElement.id;
            if (id && id.includes("question-")) {
                var parts = id.split("-");
                var slotNumber = parts[parts.length - 1];
                
                if (window.questionMapping && window.questionMapping[slotNumber]) {
                    var realQuestionId = window.questionMapping[slotNumber];
                    console.log("QUESTIONFLAGS: Converted slot " + slotNumber + " to question ID " + realQuestionId);
                    return realQuestionId;
                }
                
                console.warn("QUESTIONFLAGS: No mapping found for slot " + slotNumber + ", using slot number as question ID");
                return slotNumber;
            }
            return null;
        }

        function createStructureGuide(questionId) {
            var box = document.createElement("div");
            box.className = "structure-guide-box";
            box.id = "guide-box-" + questionId;
            
            var existingContent = window.structureGuidesData && window.structureGuidesData[questionId];
            var isExpanded = false;
            
            // Header
            var header = document.createElement("div");
            header.className = "guide-header";
            header.innerHTML = "<span>Guide to Structure your Response</span><span class=\\"toggle-arrow\\">▼</span>";
            box.appendChild(header);
            
            // Content Area
            var content = document.createElement("div");
            content.className = "guide-content";
            content.style.display = "none";
            content.innerHTML = formatGuideContent(existingContent);
            box.appendChild(content);
            
            // Edit Area (Teachers only)
            var editArea = null;
            if (window.isTeacher) {
                editArea = document.createElement("div");
                editArea.className = "guide-edit";
                
                var textarea = document.createElement("textarea");
                textarea.value = existingContent || "";
                editArea.appendChild(textarea);
                
                var saveBtn = document.createElement("button");
                saveBtn.className = "save-btn";
                saveBtn.textContent = "Save Guide";
                editArea.appendChild(saveBtn);
                
                // Add Generate Button
                var generateBtn = document.createElement("button");
                generateBtn.className = "btn btn-info";
                generateBtn.style.cssText = "margin-right: 10px; padding: 8px 15px; border: none; border-radius: 4px; cursor: pointer; font-size: 12px; color: white; background-color: #17a2b8;";
                generateBtn.textContent = "Generate with AI";
                editArea.appendChild(generateBtn);
                
                var cancelBtn = document.createElement("button");
                cancelBtn.className = "cancel-btn";
                cancelBtn.textContent = "Cancel";
                editArea.appendChild(cancelBtn);
                
                box.appendChild(editArea);
                
                // Add Edit Button to header if content exists
                var editBtn = document.createElement("span");
                editBtn.style.fontSize = "12px";
                editBtn.style.color = "#666";
                editBtn.style.marginLeft = "10px";
                editBtn.style.cursor = "pointer";
                editBtn.textContent = "[Edit]";
                editBtn.onclick = function(e) {
                    e.stopPropagation();
                    content.style.display = "none";
                    editArea.style.display = "block";
                };
                header.firstChild.appendChild(editBtn);
                
                // Save Handler
                saveBtn.onclick = function() {
                    // Create hidden form to submit
                    var form = document.createElement("form");
                    form.method = "POST";
                    form.action = window.location.href;
                    
                    var inputId = document.createElement("input");
                    inputId.type = "hidden";
                    inputId.name = "questionid";
                    inputId.value = questionId;
                    form.appendChild(inputId);
                    
                    var inputContent = document.createElement("input");
                    inputContent.type = "hidden";
                    inputContent.name = "guide_content";
                    inputContent.value = textarea.value;
                    form.appendChild(inputContent);
                    
                    var sesskey = document.createElement("input");
                    sesskey.type = "hidden";
                    sesskey.name = "update_guide";
                    sesskey.value = "1";
                    form.appendChild(sesskey);
                    
                    var token = document.createElement("input");
                    token.type = "hidden";
                    token.name = "sesskey";
                    token.value = window.qfSesskey;
                    form.appendChild(token);
                    
                    document.body.appendChild(form);
                    form.submit();
                };

                // Generate Handler
                generateBtn.onclick = function() {
                    if (!confirm("This will use AI to generate a structure guide based on the question prompt. Continue?")) {
                        return;
                    }
                    
                    generateBtn.textContent = "Generating...";
                    generateBtn.disabled = true;

                    // Create hidden form to submit
                    var form = document.createElement("form");
                    form.method = "POST";
                    form.action = window.location.href;
                    
                    var inputId = document.createElement("input");
                    inputId.type = "hidden";
                    inputId.name = "questionid";
                    inputId.value = questionId;
                    form.appendChild(inputId);
                    
                    var actionInput = document.createElement("input");
                    actionInput.type = "hidden";
                    actionInput.name = "generate_guide";
                    actionInput.value = "1";
                    form.appendChild(actionInput);
                    
                    var token = document.createElement("input");
                    token.type = "hidden";
                    token.name = "sesskey";
                    token.value = window.qfSesskey;
                    form.appendChild(token);
                    
                    document.body.appendChild(form);
                    form.submit();
                };
                
                cancelBtn.onclick = function() {
                    editArea.style.display = "none";
                    if (isExpanded) {
                        content.style.display = "block";
                    }
                };
            }
            
            // Toggle Handler
            header.onclick = function(e) {
                // Don\'t toggle if clicking buttons
                if (e.target.tagName === "BUTTON" || e.target.tagName === "INPUT" || e.target.tagName === "TEXTAREA") return;
                
                isExpanded = !isExpanded;
                var arrow = header.querySelector(".toggle-arrow");
                
                if (isExpanded) {
                    content.style.display = "block";
                    if (editArea) editArea.style.display = "none";
                    arrow.classList.add("expanded");
                } else {
                    content.style.display = "none";
                    if (editArea) editArea.style.display = "none";
                    arrow.classList.remove("expanded");
                }
            };
            
            return box;
        }

        // Initialize Structure Guides (only on Essay questions)
        var questions = document.querySelectorAll(".que.essay");
        questions.forEach(function(q) {
            var qId = getQuestionId(q);
            if (qId) {
                // Only show if we have data or user is teacher
                if ((window.structureGuidesData && window.structureGuidesData[qId]) || window.isTeacher) {
                    var formulation = q.querySelector(".formulation");
                    if (formulation) {
                        var guide = createStructureGuide(qId);
                        formulation.parentNode.insertBefore(guide, formulation.nextSibling);
                    }
                }
            }
        });
    });
</script>';
    }
}
