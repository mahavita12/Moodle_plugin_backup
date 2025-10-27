<?php
namespace local_questionflags\event;

defined('MOODLE_INTERNAL') || die();

class flag_removed extends \core\event\base {
    protected function init() {
        $this->data['crud'] = 'd'; // deleted
        $this->data['edulevel'] = self::LEVEL_PARTICIPATING;
        $this->data['objecttable'] = 'local_questionflags';
    }

    public static function get_name() {
        return get_string('event_flag_removed', 'local_questionflags');
    }

    public function get_description() {
        $color = isset($this->other['flagcolor']) ? $this->other['flagcolor'] : 'unknown';
        $qid = isset($this->other['questionid']) ? $this->other['questionid'] : 'unknown';
        return "The user with id '{$this->relateduserid}' removed a '{$color}' flag from question id '{$qid}'.";
    }

    protected function validate_data() {
        parent::validate_data();
        if (!isset($this->relateduserid)) {
            throw new \coding_exception('The relateduserid must be set.');
        }
        if (!isset($this->other['questionid'])) {
            throw new \coding_exception('The questionid must be set in other.');
        }
        if (!isset($this->other['flagcolor'])) {
            throw new \coding_exception('The flagcolor must be set in other.');
        }
    }
}
