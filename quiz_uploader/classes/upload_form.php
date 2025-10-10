<?php
/**
 * Quiz upload form
 */

namespace local_quiz_uploader;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->libdir . '/formslib.php');

class upload_form extends \moodleform {

    public function definition() {
        global $DB, $CFG;

        $mform = $this->_form;

        // Set form to accept file uploads
        $mform->updateAttributes(['enctype' => 'multipart/form-data']);

        // Course selector
        $courses = ['' => 'Select a course...'];
        $sql = "SELECT id, fullname FROM {course} WHERE id > 1 ORDER BY fullname ASC";
        $courserecords = $DB->get_records_sql($sql);
        foreach ($courserecords as $course) {
            $courses[$course->id] = $course->fullname;
        }

        // Hidden field to store draft_itemid (will be set by JavaScript)
        $mform->addElement('hidden', 'draftitemid', '');
        $mform->setType('draftitemid', PARAM_INT);

        // File upload - JavaScript will handle uploading via Moodle API (MOVED TO TOP)
        $mform->addElement('html', '
        <div class="form-group row fitem" id="fitem_id_xmlfile">
            <div class="col-md-3 col-form-label d-flex pb-0 pr-md-0">
                <label class="d-inline word-break" for="xmlfile_upload">
                    XML File
                    <span class="text-danger" title="Required field">*</span>
                </label>
            </div>
            <div class="col-md-9 form-inline align-items-start felement">
                <input type="file" id="xmlfile_upload" accept=".xml" class="form-control">
                <div id="file_status" style="margin-top: 8px; padding: 8px; border-radius: 4px;"></div>
            </div>
        </div>
        ');

        // Check duplicates - enabled by default (checks if topic category has questions)
        $mform->addElement('advcheckbox', 'checkduplicates', 'Check for duplicates', 'Check if topic name exists with questions in question bank');
        $mform->setDefault('checkduplicates', 1);  // Enabled by default

        // Header for quiz destinations
        $mform->addElement('header', 'quizdestinationsheader', 'Quiz Destinations (Create up to 3 quiz copies)');
        $mform->setExpanded('quizdestinationsheader', true);

        // Course 1: Central Question Bank (Required)
        $mform->addElement('static', 'course1_label', '', '<strong>Quiz Copy 1: Central Question Bank</strong>');

        // Find "Central Question Bank" course ID
        $centralcourse = $DB->get_record('course', ['fullname' => 'Central Question Bank']);
        if (!$centralcourse) {
            $centralcourse = $DB->get_record('course', ['shortname' => 'CQB']);
        }

        if ($centralcourse) {
            $mform->addElement('static', 'course1_static', 'Course 1', $centralcourse->fullname);
            $mform->addElement('hidden', 'course1', $centralcourse->id);
            $mform->setType('course1', PARAM_INT);
        } else {
            $mform->addElement('static', 'course1_error', 'Course 1', '<span style="color:red;">ERROR: "Central Question Bank" course not found</span>');
        }

        $mform->addElement('select', 'section1', 'Section 1', ['' => 'Please wait...']);
        $mform->addElement('hidden', 'section1_hidden', '');
        $mform->setType('section1_hidden', PARAM_INT);
        $mform->addRule('section1', 'Required', 'required', null, 'client');

        // Quiz Name 1
        $mform->addElement('text', 'quizname1', 'Quiz Name 1', ['size' => '50']);
        $mform->setType('quizname1', PARAM_TEXT);
        $mform->addRule('quizname1', 'Required', 'required', null, 'client');

        // Course 2: Optional
        $mform->addElement('static', 'course2_label', '', '<strong>Quiz Copy 2 (Optional)</strong>');
        $mform->addElement('select', 'course2', 'Course 2', ['' => '-- Leave blank to skip --'] + $courses);
        $mform->addElement('select', 'section2', 'Section 2', ['' => 'Please select a course first...']);
        $mform->addElement('hidden', 'section2_hidden', '');
        $mform->setType('section2_hidden', PARAM_INT);

        // Quiz Name 2
        $mform->addElement('text', 'quizname2', 'Quiz Name 2', ['size' => '50']);
        $mform->setType('quizname2', PARAM_TEXT);

        // Course 3: Optional
        $mform->addElement('static', 'course3_label', '', '<strong>Quiz Copy 3 (Optional)</strong>');
        $mform->addElement('select', 'course3', 'Course 3', ['' => '-- Leave blank to skip --'] + $courses);
        $mform->addElement('select', 'section3', 'Section 3', ['' => 'Please select a course first...']);
        $mform->addElement('hidden', 'section3_hidden', '');
        $mform->setType('section3_hidden', PARAM_INT);

        // Quiz Name 3
        $mform->addElement('text', 'quizname3', 'Quiz Name 3', ['size' => '50']);
        $mform->setType('quizname3', PARAM_TEXT);

        // 5-Layer Category Structure (System > Subject > Type > ClassCode > TopicName)
        $mform->addElement('header', 'categoryheader', 'Question Bank Category (5-Layer Structure)');

        // Layer 1: System (fixed as "System" - no selection needed)
        $mform->addElement('static', 'cat_layer1_static', 'Layer 1: System', 'System Category');
        $mform->addElement('hidden', 'cat_layer1_fixed', 'System Category');
        $mform->setType('cat_layer1_fixed', PARAM_TEXT);

        // Layer 2: Subject (select from dropdown only - e.g., English, Math)
        $mform->addElement('select', 'cat_layer2', 'Layer 2: Subject', ['' => '-- Select Subject --']);
        $mform->addElement('hidden', 'cat_layer2_hidden', '');
        $mform->setType('cat_layer2_hidden', PARAM_INT);

        // Layer 3: Type (select from dropdown only - e.g., Selective, Standard)
        $mform->addElement('select', 'cat_layer3', 'Layer 3: Type', ['' => '-- Select Type --']);
        $mform->addElement('hidden', 'cat_layer3_hidden', '');
        $mform->setType('cat_layer3_hidden', PARAM_INT);

        // Layer 4: Class Code (e.g., GMSR)
        $mform->addElement('select', 'cat_layer4', 'Layer 4: Class Code', ['' => '-- Select or create new --']);
        $mform->addElement('hidden', 'cat_layer4_hidden', '');
        $mform->setType('cat_layer4_hidden', PARAM_INT);
        $mform->addElement('text', 'cat_layer4_new', 'Or create new:', ['size' => '30', 'placeholder' => 'e.g., GMSR']);
        $mform->setType('cat_layer4_new', PARAM_TEXT);

        // Layer 5: Topic Name (auto-filled from XML filename, editable)
        $mform->addElement('text', 'cat_layer5', 'Layer 5: Topic Name', ['size' => '30', 'placeholder' => 'Auto-filled from XML filename']);
        $mform->setType('cat_layer5', PARAM_TEXT);
        $mform->addHelpButton('cat_layer5', 'cat_layer5', 'local_quiz_uploader');

        // Submit button
        $this->add_action_buttons(false, 'Upload and Create Quiz');
    }

    public function validation($data, $files) {
        global $DB;
        $errors = parent::validation($data, $files);

        // Validate Course 1 (Central Question Bank - Required)
        if (empty($data['course1'])) {
            $errors['course1'] = 'Course 1 is required';
        }

        // Validate Section 1 (use hidden field if available, otherwise dropdown)
        $section1 = !empty($data['section1_hidden']) ? $data['section1_hidden'] : $data['section1'] ?? '';
        if (empty($section1)) {
            $errors['section1'] = 'Please select a section for Course 1 (Central Question Bank)';
        }

        // Validate Quiz Name 1 (required)
        if (empty(trim($data['quizname1']))) {
            $errors['quizname1'] = 'Quiz name 1 is required';
        }

        // Course 2 is optional, but if selected, section and quiz name must be provided
        if (!empty($data['course2'])) {
            $section2 = !empty($data['section2_hidden']) ? $data['section2_hidden'] : $data['section2'] ?? '';
            if (empty($section2)) {
                $errors['section2'] = 'Please select a section for Course 2';
            }
            if (empty(trim($data['quizname2']))) {
                $errors['quizname2'] = 'Quiz name 2 is required when Course 2 is selected';
            }
        }

        // Course 3 is optional, but if selected, section and quiz name must be provided
        if (!empty($data['course3'])) {
            $section3 = !empty($data['section3_hidden']) ? $data['section3_hidden'] : $data['section3'] ?? '';
            if (empty($section3)) {
                $errors['section3'] = 'Please select a section for Course 3';
            }
            if (empty(trim($data['quizname3']))) {
                $errors['quizname3'] = 'Quiz name 3 is required when Course 3 is selected';
            }
        }

        return $errors;
    }
}
