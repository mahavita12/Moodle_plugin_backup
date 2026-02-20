<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

namespace local_quiz_uploader;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/course/modlib.php');
require_once($CFG->dirroot . '/mod/quiz/lib.php');
require_once($CFG->dirroot . '/mod/quiz/locallib.php');

/**
 * Create quizzes and add questions.
 */
class quiz_creator {

    /**
     * Build quiz settings object based on mode (default or test).
     *
     * @param string $mode Quiz settings mode ('default' or 'test')
     * @param int $questioncount Number of questions in the quiz
     * @param int $timelimit Time limit in minutes (only for test mode)
     * @return object Quiz settings object
     */
    public static function build_quiz_settings($mode = 'default', $questioncount = 0, $timelimit = 45) {
        $settings = new \stdClass();

        // Set maximum grade equal to number of questions
        $settings->grade = $questioncount > 0 ? $questioncount : 10;

        // All 8 review option fields
        $allreviewoptions = ['attempt', 'correctness', 'marks', 'maxmarks', 'specificfeedback', 'generalfeedback', 'rightanswer', 'overallfeedback'];
        
        if ($mode === 'test') {
            // Test mode settings
            $settings->preferredbehaviour = 'deferredfeedback';
            $settings->shuffleanswers = 0; // No shuffle within questions
            
            // Time limit in seconds (convert minutes to seconds)
            $settings->timelimit = $timelimit * 60;
            
            // Review options - tick all 8 options for all time periods
            // Note: For deferred feedback, "during" will be greyed out by Moodle, but we'll tick them anyway
            $settings->reviewoptions = [
                'during' => ['correctness', 'marks', 'maxmarks', 'specificfeedback'], // These 4 will be ticked but greyed out
                'immediately' => $allreviewoptions, // All 8 options
                'open' => $allreviewoptions, // All 8 options
                'closed' => $allreviewoptions // All 8 options
            ];
            
        } else {
            // Default mode settings
            $settings->preferredbehaviour = 'interactive';
            $settings->shuffleanswers = 0; // No shuffle within questions
            
            // Review options - tick all 8 options for all time periods
            $settings->reviewoptions = [
                'during' => ['correctness', 'marks', 'maxmarks', 'specificfeedback'], // The 4 options specified
                'immediately' => $allreviewoptions, // All 8 options
                'open' => $allreviewoptions, // All 8 options
                'closed' => $allreviewoptions // All 8 options
            ];
        }

        return $settings;
    }

    /**
     * Create a quiz activity in a course section.
     *
     * @param int $courseid Course ID
     * @param int $sectionid Section database ID
     * @param string $name Quiz name
     * @param string $intro Quiz introduction
     * @param object|null $settings Optional quiz settings
     * @return object Result with quiz ID and course module ID
     */
    public static function create_quiz($courseid, $sectionid, $name, $intro = '', $settings = null) {
        global $DB;

        $result = new \stdClass();
        $result->success = false;
        $result->quizid = null;
        $result->cmid = null;
        $result->error = null;

        try {
            // Get course
            $course = $DB->get_record('course', ['id' => $courseid], '*', MUST_EXIST);

            // Get section
            $section = $DB->get_record('course_sections', ['id' => $sectionid], '*', MUST_EXIST);

            // Get quiz module ID
            $module = $DB->get_record('modules', ['name' => 'quiz'], '*', MUST_EXIST);

            // Get Moodle's admin defaults for quiz settings
            $quizconfig = get_config('quiz');

            // Prepare module info
            $moduleinfo = new \stdClass();
            $moduleinfo->modulename = 'quiz';
            $moduleinfo->module = $module->id;  // Module ID from modules table
            $moduleinfo->course = $courseid;
            $moduleinfo->section = $section->section; // Section number, not ID
            $moduleinfo->visible = 1;
            $moduleinfo->visibleoncoursepage = 1;
            $moduleinfo->name = $name;
            $moduleinfo->intro = $intro;
            $moduleinfo->introformat = FORMAT_HTML;
            $moduleinfo->cmidnumber = '';
            $moduleinfo->groupmode = 0;
            $moduleinfo->groupingid = 0;

            // Apply settings - use provided settings or fall back to Moodle's admin defaults
            $moduleinfo->quizpassword = $settings->quizpassword ?? $quizconfig->quizpassword ?? '';
            $moduleinfo->subnet = $settings->subnet ?? $quizconfig->subnet ?? '';
            $moduleinfo->browsersecurity = $settings->browsersecurity ?? $quizconfig->browsersecurity ?? '-';
            $moduleinfo->delay1 = $settings->delay1 ?? $quizconfig->delay1 ?? 0;
            $moduleinfo->delay2 = $settings->delay2 ?? $quizconfig->delay2 ?? 0;
            $moduleinfo->timeopen = $settings->timeopen ?? 0;
            $moduleinfo->timeclose = $settings->timeclose ?? 0;
            $moduleinfo->timelimit = $settings->timelimit ?? $quizconfig->timelimit ?? 0;
            $moduleinfo->overduehandling = $settings->overduehandling ?? $quizconfig->overduehandling ?? 'autosubmit';
            $moduleinfo->graceperiod = $settings->graceperiod ?? $quizconfig->graceperiod ?? 0;
            $moduleinfo->preferredbehaviour = $settings->preferredbehaviour ?? $quizconfig->preferredbehaviour ?? 'deferredfeedback';
            $moduleinfo->canredoquestions = $settings->canredoquestions ?? $quizconfig->canredoquestions ?? 0;
            $moduleinfo->attempts = $settings->attempts ?? $quizconfig->attempts ?? 0;
            $moduleinfo->attemptonlast = $settings->attemptonlast ?? $quizconfig->attemptonlast ?? 0;
            $moduleinfo->grademethod = $settings->grademethod ?? $quizconfig->grademethod ?? 1;
            $moduleinfo->decimalpoints = $settings->decimalpoints ?? $quizconfig->decimalpoints ?? 2;
            $moduleinfo->questiondecimalpoints = $settings->questiondecimalpoints ?? $quizconfig->questiondecimalpoints ?? -1;
            
            // Set review options as individual checkboxes (form-style) rather than combined values
            // quiz_process_options() will combine these into the review* fields using bitwise OR
            // Each review field needs checkboxes for: during, immediately, open, closed
            $reviewfields = ['attempt', 'correctness', 'marks', 'maxmarks', 'specificfeedback', 'generalfeedback', 'rightanswer', 'overallfeedback'];
            $reviewtimes = ['during', 'immediately', 'open', 'closed'];
            $timebits = [
                'during' => 0x10000,      // 65536
                'immediately' => 0x01000,  // 4096
                'open' => 0x00100,         // 256
                'closed' => 0x00010        // 16
            ];
            
            // Check if custom review options are provided in settings
            if (isset($settings->reviewoptions) && is_array($settings->reviewoptions)) {
                // Use custom review options from settings
                foreach ($reviewfields as $field) {
                    foreach ($reviewtimes as $time) {
                        $checkboxfield = $field . $time;
                        // Check if this field is enabled for this time period
                        if (isset($settings->reviewoptions[$time]) && in_array($field, $settings->reviewoptions[$time])) {
                            $moduleinfo->$checkboxfield = 1;
                        } else {
                            $moduleinfo->$checkboxfield = 0;
                        }
                    }
                }
            } else {
                // Use default Moodle config values
                foreach ($reviewfields as $field) {
                    $configfield = 'review' . $field;
                    $defaultvalue = $quizconfig->$configfield ?? 69904; // Default to all times enabled
                    
                    // Set individual checkbox fields based on bit flags
                    foreach ($reviewtimes as $time) {
                        $checkboxfield = $field . $time;
                        // Check if this time bit is set in the config
                        if ($defaultvalue & $timebits[$time]) {
                            $moduleinfo->$checkboxfield = 1;
                        } else {
                            $moduleinfo->$checkboxfield = 0;
                        }
                    }
                }
            }
            
            $moduleinfo->questionsperpage = $settings->questionsperpage ?? $quizconfig->questionsperpage ?? 1;
            $moduleinfo->navmethod = $settings->navmethod ?? $quizconfig->navmethod ?? 'free';
            $moduleinfo->shuffleanswers = $settings->shuffleanswers ?? $quizconfig->shuffleanswers ?? 0;
            $moduleinfo->sumgrades = 0;
            $moduleinfo->grade = $settings->grade ?? $quizconfig->maximumgrade ?? 10;
            $moduleinfo->showuserpicture = $settings->showuserpicture ?? $quizconfig->showuserpicture ?? 0;
            $moduleinfo->showblocks = $settings->showblocks ?? $quizconfig->showblocks ?? 0;
            

            // Create the module
            $moduleinfo = add_moduleinfo($moduleinfo, $course);
            
            // Safety: Ensure deletioninprogress is 0
            // Sometimes during rapid creation/deletion cycles this flag can stick
            global $DB;
            $DB->set_field('course_modules', 'deletioninprogress', 0, ['id' => $moduleinfo->coursemodule]);

            $result->success = true;
            $result->quizid = $moduleinfo->instance;
            $result->cmid = $moduleinfo->coursemodule;

        } catch (\Exception $e) {
            $result->error = $e->getMessage();
        }

        return $result;
    }

    /**
     * Add questions to a quiz.
     *
     * @param int $quizid Quiz ID
     * @param array $questionids Array of question IDs to add
     * @return object Result with count of questions added
     */
    public static function add_questions_to_quiz($quizid, $questionids) {
        global $DB;

        $result = new \stdClass();
        $result->success = false;
        $result->count = 0;
        $result->errors = [];

        try {
            // Get quiz
            $quiz = $DB->get_record('quiz', ['id' => $quizid], '*', MUST_EXIST);

            // Ensure cmid is set
            if (!isset($quiz->cmid)) {
                $cm = get_coursemodule_from_instance('quiz', $quiz->id, $quiz->course);
                $quiz->cmid = $cm->id;
            }

            // Add each question
            foreach ($questionids as $questionid) {
                try {
                    quiz_add_quiz_question($questionid, $quiz);
                    $result->count++;
                } catch (\Exception $e) {
                    $result->errors[] = "Question ID $questionid: " . $e->getMessage();
                }
            }

            // Update the sumgrades
            quiz_update_sumgrades($quiz);

            $result->success = $result->count > 0;

        } catch (\Exception $e) {
            $result->errors[] = $e->getMessage();
        }

        return $result;
    }

    /**
     * Get quiz URL.
     *
     * @param int $cmid Course module ID
     * @return string Quiz URL
     */
    public static function get_quiz_url($cmid) {
        global $CFG;
        return $CFG->wwwroot . '/mod/quiz/view.php?id=' . $cmid;
    }
}
