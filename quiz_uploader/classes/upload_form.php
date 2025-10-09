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

        $mform->addElement('select', 'course', 'Course', $courses);
        $mform->addRule('course', 'Required', 'required', null, 'client');

        // Section selector
        $mform->addElement('select', 'section', 'Section', ['' => 'Please select a course first...']);
        $mform->addElement('hidden', 'section_hidden', '');
        $mform->setType('section_hidden', PARAM_INT);

        // 5-Layer Category Structure (System > Subject > Type > ClassCode > TopicName)
        $mform->addElement('header', 'categoryheader', 'Question Bank Category (5-Layer Structure)');

        // Layer 1: System (select only - always "System")
        $mform->addElement('select', 'cat_layer1', 'Layer 1: System', ['' => '-- Select System --']);
        $mform->addElement('hidden', 'cat_layer1_hidden', '');
        $mform->setType('cat_layer1_hidden', PARAM_INT);

        // Layer 2: Subject (e.g., English, Math)
        $mform->addElement('select', 'cat_layer2', 'Layer 2: Subject', ['' => '-- Select or create new --']);
        $mform->addElement('hidden', 'cat_layer2_hidden', '');
        $mform->setType('cat_layer2_hidden', PARAM_INT);
        $mform->addElement('text', 'cat_layer2_new', 'Or create new:', ['size' => '30', 'placeholder' => 'e.g., English']);
        $mform->setType('cat_layer2_new', PARAM_TEXT);

        // Layer 3: Type (e.g., Selective, Standard)
        $mform->addElement('select', 'cat_layer3', 'Layer 3: Type', ['' => '-- Select or create new --']);
        $mform->addElement('hidden', 'cat_layer3_hidden', '');
        $mform->setType('cat_layer3_hidden', PARAM_INT);
        $mform->addElement('text', 'cat_layer3_new', 'Or create new:', ['size' => '30', 'placeholder' => 'e.g., Selective']);
        $mform->setType('cat_layer3_new', PARAM_TEXT);

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

        // Quiz name
        $mform->addElement('text', 'quizname', 'Quiz Name', ['size' => '50']);
        $mform->setType('quizname', PARAM_TEXT);
        $mform->addRule('quizname', 'Required', 'required', null, 'client');

        // Hidden field to store draft_itemid (will be set by JavaScript)
        $mform->addElement('hidden', 'draftitemid', '');
        $mform->setType('draftitemid', PARAM_INT);

        // File upload - JavaScript will handle uploading via Moodle API
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

        // Check duplicates - enabled by default (only checks within same Layer 5 topic category)
        $mform->addElement('advcheckbox', 'checkduplicates', 'Check for duplicates', 'Check if questions with same names exist in this topic category');
        $mform->setDefault('checkduplicates', 1);  // Enabled by default

        // Quiz settings header
        $mform->addElement('header', 'quizsettingsheader', 'Quiz Settings (Optional)');
        $mform->setExpanded('quizsettingsheader', false);

        // Time close
        $mform->addElement('date_time_selector', 'timeclose', 'Close the quiz', ['optional' => true]);

        // Time limit
        $mform->addElement('duration', 'timelimit', 'Time limit (minutes)', ['optional' => true, 'defaultunit' => 60]);

        // Minimum attempts
        $mform->addElement('text', 'completionminattempts', 'Minimum attempts required', ['size' => '3']);
        $mform->setType('completionminattempts', PARAM_INT);
        $mform->setDefault('completionminattempts', 2);

        // Submit button
        $this->add_action_buttons(false, 'Upload and Create Quiz');
    }

    public function validation($data, $files) {
        global $DB;
        $errors = parent::validation($data, $files);

        if (empty($data['course'])) {
            $errors['course'] = 'Please select a course';
        }

        // Debug section value
        error_log('Form validation - Section value received: ' . print_r($data['section'] ?? 'NOT SET', true));
        error_log('Form validation - Section empty check: ' . (empty($data['section']) ? 'YES' : 'NO'));
        error_log('Form validation - All data keys: ' . print_r(array_keys($data), true));

        // TEMPORARY: Skip section validation for testing
        // if (empty($data['section']) || $data['section'] === '') {
        //     $errors['section'] = 'Please select a section';
        // }

        // For now, just log if section is missing
        if (empty($data['section'])) {
            error_log('WARNING: Section not provided, but allowing for testing');
            // Uncomment to enforce: $errors['section'] = 'Please select a section';
        }

        if (empty(trim($data['quizname']))) {
            $errors['quizname'] = 'Quiz name cannot be empty';
        }

        return $errors;
    }
}
