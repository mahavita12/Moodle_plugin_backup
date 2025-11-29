<?php
defined('MOODLE_INTERNAL') || die();

function xmldb_local_homeworkdashboard_upgrade($oldversion) {
    global $DB;

    $dbman = $DB->get_manager();

    if ($oldversion < 2025112900) {

        // Define table local_homework_reports to be modified.
        $table = new xmldb_table('local_homework_reports');

        // Adding field ai_commentary.
        $field = new xmldb_field('ai_commentary', XMLDB_TYPE_TEXT, null, null, null, null, null, 'content');

        // Launch add field ai_commentary.
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        // Adding field ai_raw_response.
        $field2 = new xmldb_field('ai_raw_response', XMLDB_TYPE_TEXT, null, null, null, null, null, 'ai_commentary');

        // Launch add field ai_raw_response.
        if (!$dbman->field_exists($table, $field2)) {
            $dbman->add_field($table, $field2);
        }

        // Homeworkdashboard savepoint reached.
        upgrade_plugin_savepoint(true, 2025112900, 'local', 'homeworkdashboard');
    }

    return true;
}
