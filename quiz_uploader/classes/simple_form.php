<?php
namespace local_quiz_uploader;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/formslib.php');

class simple_form extends \moodleform {

    public function definition() {
        $mform = $this->_form;

        $mform->addElement('text', 'testfield', 'Test Field');
        $mform->setType('testfield', PARAM_TEXT);

        $this->add_action_buttons(false, 'Submit');
    }
}
