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
                
                $DB->delete_records('local_questionflags', [
                    'userid' => $USER->id,
                    'questionid' => $questionid
                ]);
                
                if ($current_state !== $flagcolor) {
                    $record = new \stdClass();
                    $record->userid = $USER->id;
                    $record->questionid = $questionid;
                    $record->flagcolor = $flagcolor;
                    $record->timecreated = $time;
                    $record->timemodified = $time;
                    $DB->insert_record('local_questionflags', $record);
                }
            }
            
            redirect($PAGE->url);
        }

        // Get existing flags
        $existing_flags = $DB->get_records('local_questionflags', ['userid' => $USER->id]);
        $user_flags = [];
        
        foreach ($existing_flags as $flag) {
            $user_flags[$flag->questionid] = $flag->flagcolor;
        }

        // Get structure guides from question metadata
        $guides_data = local_questionflags_get_quiz_guides($PAGE);

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
        $guides_json = json_encode($guides_data);
        $question_mapping_json = json_encode($question_mapping);
        $page_type = $PAGE->pagetype;
        $is_teacher = has_capability('moodle/course:manageactivities', \context_course::instance($PAGE->course->id));
        
        // Output CSS and JavaScript via hook API (avoid direct echo)
        ob_start();
        self::output_question_flags_assets($user_flags_json, $guides_json, $question_mapping_json, $page_type, $is_teacher, $sesskey);
        
        // Add feedback toggle button HTML directly for review pages  
        if ($page_type === 'mod-quiz-review') {
            echo '<script>
            (function(){
                document.addEventListener("DOMContentLoaded", function() {
                    // Find all question containers
                    var questions = document.querySelectorAll(".que");
                    if (questions.length === 0) return;
                    
                    // Create the toggle button with Moodle navigation styling
                    var btnContainer = document.createElement("div");
                    btnContainer.style.cssText = "margin: 15px 0 15px 145px; text-align: left;";
                    
                    var btn = document.createElement("button");
                    btn.id = "feedbackToggleBtn";
                    btn.className = "btn btn-primary";
                    btn.style.cssText = "padding: 10px 20px; font-size: 14px; font-weight: bold;";
                    btn.textContent = "Hide Feedback";
                    
                    btnContainer.appendChild(btn);
                    
                    // Insert button after the first question (below answer choices)
                    var firstQuestion = questions[0];
                    firstQuestion.parentNode.insertBefore(btnContainer, firstQuestion.nextSibling);
                    
                    // Load saved preference
                    var hidden = localStorage.getItem("quiz_hide_feedback") === "true";
                    if (hidden) {
                        document.body.classList.add("hide-feedback");
                        btn.textContent = "Show Feedback";
                    }
                    
                    // Toggle on click
                    btn.addEventListener("click", function() {
                        var isHidden = document.body.classList.contains("hide-feedback");
                        if (isHidden) {
                            document.body.classList.remove("hide-feedback");
                            btn.textContent = "Hide Feedback";
                            localStorage.setItem("quiz_hide_feedback", "false");
                        } else {
                            document.body.classList.add("hide-feedback");
                            btn.textContent = "Show Feedback";
                            localStorage.setItem("quiz_hide_feedback", "true");
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
    private static function output_question_flags_assets($user_flags_json, $guides_json, $question_mapping_json, $page_type, $is_teacher, $sesskey) {
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

/* Hide all feedback-related elements - based on actual Moodle structure */
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

/* Remove background colors and borders indicating correctness */
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
</style>';
        
        // Output JavaScript with proper formatting function
        echo '<script>
    window.questionFlagsData = ' . $user_flags_json . ';
    window.structureGuidesData = ' . $guides_json . ';
    window.questionMapping = ' . $question_mapping_json . ';
    window.moodlePageType = "' . $page_type . '";
    window.isTeacher = ' . ($is_teacher ? 'true' : 'false') . ';
    window.qfSesskey = "' . $sesskey . '";

    document.addEventListener("DOMContentLoaded", function() {
        console.log("Question flags loaded:", window.questionFlagsData);
        console.log("Structure guides loaded:", window.structureGuidesData);
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
        
        function isEssayQuestion(questionElement) {
            var textareas = questionElement.querySelectorAll("textarea[name*=\\"answer\\"]");
            var fileUpload = questionElement.querySelector("input[type=\\"file\\"]");
            var richTextEditor = questionElement.querySelector(".editor_atto");
            
            return textareas.length > 0 || fileUpload || richTextEditor;
        }
        
        function addStructureGuideBox(question, questionId) {
            if (question.querySelector(".structure-guide-box")) {
                return; // Already added
            }
            
            var guideContent = window.structureGuidesData[questionId] || "";
            
            // Always show the guide box for teachers, only show for students if there is content
            if (!guideContent && !window.isTeacher) {
                return; // No content for students
            }
            
            // Convert plain text to HTML with proper formatting
            var formattedContent = guideContent ? formatGuideContent(guideContent) : "No structure guide set yet.";
            
            var guideBox = document.createElement("div");
            guideBox.className = "structure-guide-box";
            guideBox.innerHTML = 
                "<div class=\\"guide-header\\" onclick=\\"toggleGuide(" + questionId + ")\\" id=\\"guide-header-" + questionId + "\\">Structure Guide <span class=\\"toggle-arrow\\" id=\\"arrow-" + questionId + "\\">▼</span></div>" +
                "<div class=\\"guide-content\\" id=\\"guide-content-" + questionId + "\\" style=\\"display:none;\\">" +
                    formattedContent +
                "</div>" +
                (window.isTeacher ? 
                    "<div class=\\"guide-edit\\" id=\\"guide-edit-" + questionId + "\\">"+
                        "<div style=\\"display:flex; gap:8px; margin-bottom:8px;\\">"+
                            "<form method=\\"post\\" style=\\"display:inline;\\">"+
                                "<input type=\\"hidden\\" name=\\"sesskey\\" value=\\"" + window.qfSesskey + "\\">"+
                                "<input type=\\"hidden\\" name=\\"generate_guide\\" value=\\"1\\">"+
                                "<input type=\\"hidden\\" name=\\"questionid\\" value=\\""+questionId+"\\">"+
                                "<button type=\\"submit\\" class=\\"save-btn\\" style=\\"background:#1f8ce6;\\">Generate Structure Guide</button>"+
                            "</form>"+
                            "<button type=\\"button\\" class=\\"cancel-btn\\" onclick=\\"cancelEdit(" + questionId + ")\\">Close</button>"+
                        "</div>"+
                        "<textarea id=\\"guide-textarea-" + questionId + "\\" placeholder=\\"Enter structure guide...\\">"+guideContent+"</textarea>"+
                        "<button type=\\"button\\" class=\\"save-btn\\" onclick=\\"saveGuide(" + questionId + ")\\">Save</button>"+
                        "<button type=\\"button\\" class=\\"cancel-btn\\" onclick=\\"cancelEdit(" + questionId + ")\\">Cancel</button>"+
                    "</div>"
                : "");
            
            var qtext = question.querySelector(".qtext");
            if (qtext && qtext.nextSibling) {
                qtext.parentNode.insertBefore(guideBox, qtext.nextSibling);
            }
        }
        
        window.toggleGuide = function(questionId) {
            var content = document.getElementById("guide-content-" + questionId);
            var arrow = document.getElementById("arrow-" + questionId);
            
            if (!window.isTeacher) {
                // For students: just toggle content visibility
                if (content.style.display === "none") {
                    content.style.display = "block";
                    arrow.classList.add("expanded");
                } else {
                    content.style.display = "none";
                    arrow.classList.remove("expanded");
                }
                return;
            }
            
            // For teachers: handle edit mode
            var edit = document.getElementById("guide-edit-" + questionId);
            
            if (edit.style.display === "none" || !edit.style.display) {
                // Show edit mode
                content.style.display = "none";
                edit.style.display = "block";
                arrow.classList.add("expanded");
                document.getElementById("guide-textarea-" + questionId).focus();
            } else {
                // Hide edit mode, show content
                edit.style.display = "none";
                content.style.display = "block";
                arrow.classList.add("expanded");
            }
        };
        
        window.saveGuide = function(questionId) {
            var textarea = document.getElementById("guide-textarea-" + questionId);
            var content = textarea.value;
            
            var form = document.createElement("form");
            form.method = "POST";
            form.innerHTML = 
                "<input type=\\"hidden\\" name=\\"sesskey\\" value=\\"' . $sesskey . '\\">"+
                "<input type=\\"hidden\\" name=\\"update_guide\\" value=\\"1\\">"+
                "<input type=\\"hidden\\" name=\\"questionid\\" value=\\""+questionId+"\\">"+
                "<input type=\\"hidden\\" name=\\"guide_content\\" value=\\""+content.replace(/"/g, "&quot;")+"\\>";
            document.body.appendChild(form);
            form.submit();
        };
        
        window.cancelEdit = function(questionId) {
            var content = document.getElementById("guide-content-" + questionId);
            var edit = document.getElementById("guide-edit-" + questionId);
            var textarea = document.getElementById("guide-textarea-" + questionId);
            var arrow = document.getElementById("arrow-" + questionId);
            
            edit.style.display = "none";
            content.style.display = "none";
            arrow.classList.remove("expanded");
            textarea.value = window.structureGuidesData[questionId] || "";
        };

        function applyFlagState(question, questionId, currentFlag) {
            var normalized = currentFlag === "blue" || currentFlag === "red" ? currentFlag : "";

            question.classList.remove("question-flagged-blue", "question-flagged-red");
            question.querySelectorAll(".flag-btn").forEach(function(btn) {
                btn.classList.remove("active");
            });

            if (normalized) {
                question.classList.add("question-flagged-" + normalized);
                var activeBtn = question.querySelector(".flag-btn." + normalized);
                if (activeBtn) {
                    activeBtn.classList.add("active");
                }
            }

            question.querySelectorAll("input[name=\'current_state\']").forEach(function(inputEl) {
                inputEl.value = normalized;
            });
        }
        
        // Add flag buttons and structure guides to questions
        var questions = document.querySelectorAll(".que");
        questions.forEach(function(question) {
            var questionId = getQuestionId(question);
            if (!questionId) return;

            var currentFlag = window.questionFlagsData[questionId] || "";
            
            // Add flag buttons
            if (!question.querySelector(".question-flag-container")) {
                
                var flagDiv = document.createElement("div");
                flagDiv.className = "question-flag-container";
                flagDiv.innerHTML = 
                    "<form class=\"flag-form\" method=\"post\" style=\"display: inline;\">"+
                        "<input type=\\"hidden\\" name=\\"flag_action\\" value=\\"1\\">"+
                        "<input type=\\"hidden\\" name=\\"questionid\\" value=\\""+questionId+"\\">"+
                        "<input type=\\"hidden\\" name=\\"flagcolor\\" value=\\"blue\\">"+
                        "<input type=\\"hidden\\" name=\\"current_state\\" value=\\""+currentFlag+"\\">"+
                        "<input type=\\"hidden\\" name=\\"sesskey\\" value=\\"' . $sesskey . '\\">"+
                        "<button type=\\"submit\\" class=\\"flag-btn blue\\"><span class=\\"emoji\\">🏳️</span> <span class=\\"text\\">Blue flag</span></button>"+
                    "</form>"+
                    "<form class=\\"flag-form\\" method=\\"post\\" style=\\"display: inline;\\">"+
                        "<input type=\\"hidden\\" name=\\"flag_action\\" value=\\"1\\">"+
                        "<input type=\\"hidden\\" name=\\"questionid\\" value=\\""+questionId+"\\">"+
                        "<input type=\\"hidden\\" name=\\"flagcolor\\" value=\\"red\\">"+
                        "<input type=\\"hidden\\" name=\\"current_state\\" value=\\""+currentFlag+"\\">"+
                        "<input type=\\"hidden\\" name=\\"sesskey\\" value=\\"' . $sesskey . '\\">"+
                        "<button type=\\"submit\\" class=\\"flag-btn red\\"><span class=\\"emoji\\">🚩</span> <span class=\\"text\\">Red flag</span></button>"+
                    "</form>";
                
                var infoDiv = question.querySelector(".info");
                if (infoDiv) {
                    infoDiv.appendChild(flagDiv);
                }
            }
            
            // Add structure guide for essay questions
            if (isEssayQuestion(question)) {
                addStructureGuideBox(question, questionId);
            }

            applyFlagState(question, questionId, currentFlag);
        });
        
        // Setup feedback toggle for quiz review pages
        if (window.moodlePageType === "mod-quiz-review") {
            setupFeedbackToggle();
        }
        
        function setupFeedbackToggle() {
            // Create toggle button container
            var toggleContainer = document.createElement("div");
            toggleContainer.className = "feedback-toggle-container";
            
            var toggleBtn = document.createElement("button");
            toggleBtn.className = "feedback-toggle-btn";
            toggleBtn.textContent = "Hide Feedback";
            
            toggleContainer.appendChild(toggleBtn);
            document.body.appendChild(toggleContainer);
            
            // Load saved preference from localStorage
            var hideFeedback = localStorage.getItem("quiz_hide_feedback") === "true";
            if (hideFeedback) {
                document.body.classList.add("hide-feedback");
                toggleBtn.textContent = "Show Feedback";
                toggleBtn.classList.add("active");
            }
            
            // Toggle feedback visibility
            toggleBtn.addEventListener("click", function() {
                var isHidden = document.body.classList.contains("hide-feedback");
                
                if (isHidden) {
                    document.body.classList.remove("hide-feedback");
                    toggleBtn.textContent = "Hide Feedback";
                    toggleBtn.classList.remove("active");
                    localStorage.setItem("quiz_hide_feedback", "false");
                } else {
                    document.body.classList.add("hide-feedback");
                    toggleBtn.textContent = "Show Feedback";
                    toggleBtn.classList.add("active");
                    localStorage.setItem("quiz_hide_feedback", "true");
                }
            });
        }
    });
    </script>';
        // Append safe, isolated script to exclude essay flags and update navigation panel
        echo <<<'QFJS'
<script>
    document.addEventListener("DOMContentLoaded", function() {
        function isEssay(question) {
            var hasTextarea = question.querySelectorAll("textarea[name*='answer']").length > 0;
            var hasFile = !!question.querySelector("input[type='file']");
            var hasRTE = !!question.querySelector(".editor_atto");
            return hasTextarea || hasFile || hasRTE;
        }

        function getSlotFromQuestion(question) {
            var id = question && question.id ? question.id : '';
            if (id.indexOf('question-') !== -1) {
                var parts = id.split('-');
                return parts[parts.length - 1];
            }
            return null;
        }

        function collectEssaySlots() {
            var map = {};
            var nodes = document.querySelectorAll('.que');
            nodes.forEach(function(q){
                var slot = getSlotFromQuestion(q);
                if (!slot) { return; }
                if (isEssay(q)) {
                    map[slot] = true;
                    // Remove any flag UI if it exists
                    var flag = q.querySelector('.question-flag-container');
                    if (flag && flag.parentNode) { flag.parentNode.removeChild(flag); }
                    q.classList.remove('question-flagged-blue','question-flagged-red');
                }
            });
            return map;
        }

        function getSlotFromNav(btn) {
            var slot = (btn.dataset && btn.dataset.slot) || btn.getAttribute('data-slot');
            if (!slot && btn.id) {
                var m = btn.id.match(/quiznavbutton(\d+)/);
                if (m) { slot = m[1]; }
            }
            if (!slot) {
                var a = btn.querySelector('a');
                var href = a ? a.getAttribute('href') : btn.getAttribute('href');
                if (href) {
                    var mh = href.match(/[?&]slot=(\d+)/);
                    if (mh) { slot = mh[1]; }
                }
            }
            return slot;
        }

        function updateNav(essaySlots) {
            var buttons = document.querySelectorAll('.qnbutton');
            buttons.forEach(function(b){
                b.classList.remove('blue-flagged','red-flagged');
                var slot = getSlotFromNav(b);
                if (!slot) { return; }
                if (essaySlots && essaySlots[slot]) { return; }
                if (!window.questionMapping) { return; }
                var qid = window.questionMapping[slot];
                if (!qid) { return; }
                var color = (window.questionFlagsData || {})[qid];
                if (color === 'blue') { b.classList.add('blue-flagged'); }
                else if (color === 'red') { b.classList.add('red-flagged'); }
            });
        }

        var essaySlots = collectEssaySlots();
        updateNav(essaySlots);

        // In case navigation is re-rendered dynamically, observe and refresh
        var nav = document.querySelector('#quiznavblock, .qnbuttons');
        if (nav && window.MutationObserver) {
            var obs = new MutationObserver(function(){ updateNav(essaySlots); });
            obs.observe(nav, { childList: true, subtree: true });
        }
    });
</script>
QFJS;
    }
}