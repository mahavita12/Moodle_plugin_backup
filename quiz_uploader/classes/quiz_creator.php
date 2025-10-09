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

            // Apply default settings
            $moduleinfo->quizpassword = $settings->quizpassword ?? '';
            $moduleinfo->subnet = $settings->subnet ?? '';
            $moduleinfo->browsersecurity = $settings->browsersecurity ?? '-';
            $moduleinfo->delay1 = $settings->delay1 ?? 0;
            $moduleinfo->delay2 = $settings->delay2 ?? 0;
            $moduleinfo->timeopen = $settings->timeopen ?? 0;
            $moduleinfo->timeclose = $settings->timeclose ?? 0;
            $moduleinfo->timelimit = $settings->timelimit ?? 0;
            $moduleinfo->overduehandling = $settings->overduehandling ?? 'autosubmit';
            $moduleinfo->graceperiod = $settings->graceperiod ?? 0;
            $moduleinfo->preferredbehaviour = $settings->preferredbehaviour ?? 'deferredfeedback';
            $moduleinfo->canredoquestions = $settings->canredoquestions ?? 0;
            $moduleinfo->attempts = $settings->attempts ?? 0;
            $moduleinfo->attemptonlast = $settings->attemptonlast ?? 0;
            $moduleinfo->grademethod = $settings->grademethod ?? 1;
            $moduleinfo->decimalpoints = $settings->decimalpoints ?? 2;
            $moduleinfo->questiondecimalpoints = $settings->questiondecimalpoints ?? -1;
            $moduleinfo->reviewattempt = $settings->reviewattempt ?? 69904;
            $moduleinfo->reviewcorrectness = $settings->reviewcorrectness ?? 4368;
            $moduleinfo->reviewmarks = $settings->reviewmarks ?? 4368;
            $moduleinfo->reviewspecificfeedback = $settings->reviewspecificfeedback ?? 4368;
            $moduleinfo->reviewgeneralfeedback = $settings->reviewgeneralfeedback ?? 4368;
            $moduleinfo->reviewrightanswer = $settings->reviewrightanswer ?? 4368;
            $moduleinfo->reviewoverallfeedback = $settings->reviewoverallfeedback ?? 4368;
            $moduleinfo->questionsperpage = $settings->questionsperpage ?? 1;
            $moduleinfo->navmethod = $settings->navmethod ?? 'free';
            $moduleinfo->shuffleanswers = $settings->shuffleanswers ?? 1;
            $moduleinfo->sumgrades = 0;
            $moduleinfo->grade = $settings->grade ?? 10;
            $moduleinfo->showuserpicture = $settings->showuserpicture ?? 0;
            $moduleinfo->showblocks = $settings->showblocks ?? 0;
            $moduleinfo->completionattemptsexhausted = $settings->completionattemptsexhausted ?? 0;
            $moduleinfo->completionpass = $settings->completionpass ?? 0;

            // Create the module
            $moduleinfo = add_moduleinfo($moduleinfo, $course);

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
