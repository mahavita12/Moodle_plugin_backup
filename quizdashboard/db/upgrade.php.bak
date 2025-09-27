<?php
defined('MOODLE_INTERNAL') || die();

function xmldb_local_quizdashboard_upgrade($oldversion) {
    global $DB;
    
    $dbman = $DB->get_manager();

    // Add homework_html column for homework exercises (should be first/older version)
    if ($oldversion < 2025082908) {
        $table = new xmldb_table('local_quizdashboard_gradings');
        $field = new xmldb_field('homework_html', XMLDB_TYPE_TEXT, null, null, null, null, null, 'ai_likelihood');

        // Conditionally launch add field homework_html
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        upgrade_plugin_savepoint(true, 2025082908, 'local', 'quizdashboard');
    }

    // Add the essay grading table for auto-grading functionality (should be second/newer version)
    if ($oldversion < 2025082912) {
        $table = new xmldb_table('local_quizdashboard_resubmissions');

        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('current_attempt_id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('previous_attempt_id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('submission_number', XMLDB_TYPE_INTEGER, '3', null, XMLDB_NOTNULL, null, null);
        $table->add_field('is_copy_detected', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('similarity_percentage', XMLDB_TYPE_NUMBER, '5,2', null, null, null, null);
        $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);

        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_key('current_attempt_unique', XMLDB_KEY_UNIQUE, ['current_attempt_id']);
        $table->add_index('previous_attempt_idx', XMLDB_INDEX_NOTUNIQUE, ['previous_attempt_id']);

        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        upgrade_plugin_savepoint(true, 2025082912, 'local', 'quizdashboard');
    }

    // Any future upgrades for version 2025091101+
    if ($oldversion < 2025091101) {
        // No database changes needed for this version - just forcing reload of lib.php functions
        upgrade_plugin_savepoint(true, 2025091101, 'local', 'quizdashboard');
    }

    return true;
}