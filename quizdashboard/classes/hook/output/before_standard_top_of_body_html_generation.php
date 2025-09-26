<?php
namespace local_quizdashboard\hook\output;
defined('MOODLE_INTERNAL') || die();

class before_standard_top_of_body_html_generation {
    public function __invoke(\core\hook\output\before_standard_top_of_body_html_generation $hook): void {
        // $hook->add_html('<!-- quizdashboard top-of-body -->');
    }
}
