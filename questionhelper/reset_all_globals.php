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

require_once(__DIR__ . '/../../config.php');

require_login();

// Site-level admin capability is required; we show this from site admin settings.
if (!is_siteadmin()) {
    print_error('accessdenied', 'admin');
}

require_sesskey();

$confirm = optional_param('confirm', 0, PARAM_BOOL);

$context = context_system::instance();
$PAGE->set_context($context);
$PAGE->set_url(new moodle_url('/local/questionhelper/reset_all_globals.php'));
$PAGE->set_pagelayout('admin');
$PAGE->set_title(get_string('resetglobals', 'local_questionhelper'));
$PAGE->set_heading(get_string('resetglobals', 'local_questionhelper'));

if ($confirm) {
    global $DB;
    // Delete all global records. Using delete_records_select for visibility of affected rows.
    $deleted = $DB->delete_records_select('local_qh_saved_help', 'is_global = ?', [1]);

    // Redirect back to settings with a notification.
    redirect(
        new moodle_url('/admin/settings.php', ['section' => 'local_questionhelper']),
        get_string('resetglobals_done', 'local_questionhelper') . ' (deleted: ' . (int)$deleted . ')',
        3,
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
    );
}

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('resetglobals_confirm_title', 'local_questionhelper'));
echo html_writer::div(get_string('resetglobals_confirm_body', 'local_questionhelper'));

$confirmurl = new moodle_url('/local/questionhelper/reset_all_globals.php', ['sesskey' => sesskey(), 'confirm' => 1]);
$cancelurl = new moodle_url('/admin/settings.php', ['section' => 'local_questionhelper']);

echo $OUTPUT->confirm(
    get_string('resetglobals_confirm_body', 'local_questionhelper'),
    $confirmurl,
    $cancelurl
);

echo $OUTPUT->footer();

