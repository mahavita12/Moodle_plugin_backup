<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

defined('MOODLE_INTERNAL') || die();

$string['pluginname'] = 'Quiz Uploader';
$string['quiz_uploader:uploadquiz'] = 'Upload quiz from XML';
$string['privacy:metadata'] = 'The Quiz Uploader plugin does not store any personal data.';

// Page titles
$string['uploadquiz'] = 'Upload Quiz';
$string['uploadquizpage'] = 'Upload Quiz from XML File';

// Form labels
$string['course'] = 'Course';
$string['course_help'] = 'Select the course where the quiz will be created';
$string['section'] = 'Section';
$string['section_help'] = 'Select the section (topic) where the quiz will appear';
$string['categorypath'] = 'Question Bank Category';
$string['categorypath_help'] = 'Select the category where questions will be stored';
$string['categoryname'] = 'Category Class Name';
$string['categoryname_help'] = 'Enter the class/subcategory name (e.g., GMSR11). Will be created under the selected category above. Leave empty to use quiz name.';
$string['quizname'] = 'Quiz Name';
$string['quizname_help'] = 'Enter a name for the quiz';
$string['xmlfile'] = 'XML File';
$string['xmlfile_help'] = 'Upload a Moodle XML file containing questions';
$string['checkduplicates'] = 'Check for duplicates';
$string['checkduplicates_help'] = 'Check if quiz or questions already exist before importing';

// Quiz settings
$string['quizsettings'] = 'Quiz Settings (Optional)';
$string['quizsettings1'] = 'Quiz Settings 1';
$string['quizsettings2'] = 'Quiz Settings 2';
$string['quizsettings3'] = 'Quiz Settings 3';
$string['quizsettings_help'] = 'Select quiz behavior mode: Default (Interactive with multiple tries) or Test (Deferred Feedback)';
$string['timelimit1'] = 'Time Limit (minutes)';
$string['timelimit1_help'] = 'Time limit in minutes for Test mode. Default is 45 minutes. This field only appears when Test mode is selected.';
$string['timelimit2'] = 'Time Limit (minutes)';
$string['timelimit2_help'] = 'Time limit in minutes for Test mode. Default is 45 minutes. This field only appears when Test mode is selected.';
$string['timelimit3'] = 'Time Limit (minutes)';
$string['timelimit3_help'] = 'Time limit in minutes for Test mode. Default is 45 minutes. This field only appears when Test mode is selected.';
$string['timeclose'] = 'Close the quiz';
$string['timeclose_help'] = 'Date and time when the quiz will close';
$string['timelimit'] = 'Time limit (minutes)';
$string['timelimit_help'] = 'Maximum time allowed to complete the quiz (in minutes)';
$string['completionminattempts'] = 'Minimum attempts required';
$string['completionminattempts_help'] = 'Number of attempts required for completion';

// Buttons
$string['upload'] = 'Upload and Create Quiz';
$string['uploadanother'] = 'Upload Another Quiz';

// Success messages
$string['uploadsuccess'] = 'Quiz uploaded successfully';
$string['quizcreated'] = 'Quiz "{$a->quizname}" created successfully with {$a->questioncount} questions';
$string['viewquiz'] = 'View Quiz';
$string['viewquestionbank'] = 'View Question Bank';

// Error messages
$string['error_nofile'] = 'No file found in draft area';
$string['error_invalidxml'] = 'Invalid XML file format';
$string['error_duplicatequiz'] = 'Quiz with this name already exists in the course';
$string['error_duplicatequestions'] = 'Some questions already exist in the category';
$string['error_nocategory'] = 'Could not create or find question category';
$string['error_importfailed'] = 'Question import failed';
$string['error_quizcreatefailed'] = 'Quiz creation failed';
$string['error_invalidcourse'] = 'Invalid course selected';
$string['error_invalidsection'] = 'Invalid section selected';
$string['error_noxmlfile'] = 'Please select an XML file to upload';
$string['error_nocourse'] = 'Please select a course';
$string['error_nosection'] = 'Please select a section';
$string['error_noquizname'] = 'Please enter a quiz name';
