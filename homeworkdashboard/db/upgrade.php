<?php
defined('MOODLE_INTERNAL') || die();

function xmldb_local_homeworkdashboard_upgrade($oldversion) {
    global $DB;

    $dbman = $DB->get_manager();

    // Bump version to trigger this block
    if ($oldversion < 2025113002) {

        // Define table local_homework_reports
        $table = new xmldb_table('local_homework_reports');

        // Check if table exists
        if (!$dbman->table_exists($table)) {
            // Define fields for creation
            $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
            $table->add_field('userid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
            $table->add_field('timeclose', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
            $table->add_field('subject', XMLDB_TYPE_TEXT, null, null, XMLDB_NOTNULL, null, null);
            $table->add_field('content', XMLDB_TYPE_TEXT, 'big', null, XMLDB_NOTNULL, null, null);
            $table->add_field('ai_commentary', XMLDB_TYPE_TEXT, null, null, null, null, null);
            $table->add_field('ai_raw_response', XMLDB_TYPE_TEXT, null, null, null, null, null);
            $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
            // Add lang field for new tables
            $table->add_field('lang', XMLDB_TYPE_CHAR, '10', null, XMLDB_NOTNULL, null, 'en');
            // Add drive_link field for new tables
            $table->add_field('drive_link', XMLDB_TYPE_TEXT, null, null, null, null, null);

            // Define keys and indexes
            $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $table->add_index('userid_timeclose_idx', XMLDB_INDEX_NOTUNIQUE, ['userid', 'timeclose']);

            // Create the table
            $dbman->create_table($table);
        } else {
            // Table exists, check for missing fields (for existing installs)
            
            // Add ai_commentary if missing
            $field = new xmldb_field('ai_commentary', XMLDB_TYPE_TEXT, null, null, null, null, null, 'content');
            if (!$dbman->field_exists($table, $field)) {
                $dbman->add_field($table, $field);
            }

            // Add ai_raw_response if missing
            $field2 = new xmldb_field('ai_raw_response', XMLDB_TYPE_TEXT, null, null, null, null, null, 'ai_commentary');
            if (!$dbman->field_exists($table, $field2)) {
                $dbman->add_field($table, $field2);
            }

            // Add lang if missing
            $field3 = new xmldb_field('lang', XMLDB_TYPE_CHAR, '10', null, XMLDB_NOTNULL, null, 'en', 'timecreated');
            if (!$dbman->field_exists($table, $field3)) {
                $dbman->add_field($table, $field3);
            }

            // Add drive_link if missing
            $field4 = new xmldb_field('drive_link', XMLDB_TYPE_TEXT, null, null, null, null, null, 'ai_raw_response');
            if (!$dbman->field_exists($table, $field4)) {
                $dbman->add_field($table, $field4);
            }
        }

        // Savepoint
        upgrade_plugin_savepoint(true, 2025113002, 'local', 'homeworkdashboard');
    }

    return true;
}
