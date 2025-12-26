<?php
defined('MOODLE_INTERNAL') || die();

function xmldb_local_personalcourse_upgrade($oldversion) {
    global $DB;

    $dbman = $DB->get_manager();

    if ($oldversion < 2025102600) {
        // Table: local_personalcourse_courses.
        $table = new xmldb_table('local_personalcourse_courses');
        if (!$dbman->table_exists($table)) {
            $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
            $table->add_field('userid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
            $table->add_field('courseid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
            $table->add_field('status', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, 'active');
            $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
            $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
            $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $table->add_index('userid_unique', XMLDB_INDEX_UNIQUE, ['userid']);
            $table->add_index('courseid', XMLDB_INDEX_NOTUNIQUE, ['courseid']);
            $dbman->create_table($table);
        }

        // Table: local_personalcourse_quizzes.
        $table = new xmldb_table('local_personalcourse_quizzes');
        if (!$dbman->table_exists($table)) {
            $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
            $table->add_field('personalcourseid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
            $table->add_field('quizid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
            $table->add_field('sourcequizid', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
            $table->add_field('sectionname', XMLDB_TYPE_CHAR, '255', null, null, null, null);
            $table->add_field('quiztype', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, 'non_essay');
            $table->add_field('questionsperpage', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '1');
            $table->add_field('navmethod', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, 'free');
            $table->add_field('threshold_grade', XMLDB_TYPE_NUMBER, '10, 2', null, XMLDB_NOTNULL, null, null);
            $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
            $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $table->add_index('pc_time', XMLDB_INDEX_NOTUNIQUE, ['personalcourseid', 'timecreated']);
            $table->add_index('user_quiz', XMLDB_INDEX_NOTUNIQUE, ['userid', 'sourcequizid']);
            $dbman->create_table($table);
        }

        // Table: local_personalcourse_archives.
        $table = new xmldb_table('local_personalcourse_archives');
        if (!$dbman->table_exists($table)) {
            $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
            $table->add_field('ownerid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
            $table->add_field('personalcourseid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
            $table->add_field('sourcequizid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
            $table->add_field('archivedquizid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
            $table->add_field('archivedcmid', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
            $table->add_field('archivedname', XMLDB_TYPE_CHAR, '255', null, XMLDB_NOTNULL, null, null);
            $table->add_field('reason', XMLDB_TYPE_CHAR, '32', null, XMLDB_NOTNULL, null, 'flags_empty');
            $table->add_field('archivedat', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
            $table->add_field('notes', XMLDB_TYPE_TEXT, null, null, null, null, null);
            $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $table->add_index('owner_source', XMLDB_INDEX_NOTUNIQUE, ['ownerid', 'sourcequizid']);
            $table->add_index('pc_source', XMLDB_INDEX_NOTUNIQUE, ['personalcourseid', 'sourcequizid']);
            $table->add_index('archivedquiz_unique', XMLDB_INDEX_UNIQUE, ['archivedquizid']);
            $dbman->create_table($table);
        }

        upgrade_plugin_savepoint(true, 2025102604, 'local', 'personalcourse');
    }

    if ($oldversion < 2025102605) {
        $table = new xmldb_table('local_personalcourse_quizzes');
        $field = new xmldb_field('sourcequizid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null, 'quizid');
        $index = new xmldb_index('sourcequiz_unique', XMLDB_INDEX_UNIQUE, ['personalcourseid', 'sourcequizid']);
        if ($dbman->table_exists($table)) {
            $hasnulls = 0;
            try { $hasnulls = (int)$DB->count_records_select('local_personalcourse_quizzes', 'sourcequizid IS NULL'); } catch (\Throwable $e) { $hasnulls = 0; }
            if ($hasnulls === 0) {
                if ($dbman->index_exists($table, $index)) {
                    $dbman->drop_index($table, $index);
                }
                if ($dbman->field_exists($table, $field)) {
                    $dbman->change_field_notnull($table, $field);
                }
                if (!$dbman->index_exists($table, $index)) {
                    $dbman->add_index($table, $index);
                }
            }
        }
        upgrade_plugin_savepoint(true, 2025102605, 'local', 'personalcourse');
    }

    // Mark upgrade for deferred-on-review behavior.
    if ($oldversion < 2025110600) {
        upgrade_plugin_savepoint(true, 2025110600, 'local', 'personalcourse');
    }

    if ($oldversion < 2025110700) {
        upgrade_plugin_savepoint(true, 2025110700, 'local', 'personalcourse');
    }

    // New Step: Add sourcecategory column
    if ($oldversion < 2025122600) {
        $table = new xmldb_table('local_personalcourse_quizzes');
        $field = new xmldb_field('sourcecategory', XMLDB_TYPE_CHAR, '255', null, null, null, null, 'sourcequizid');
        if ($dbman->table_exists($table) && !$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
        upgrade_plugin_savepoint(true, 2025122600, 'local', 'personalcourse');
    }

    // New Step: Add sourcecourseid column
    if ($oldversion < 2025122601) {
        $table = new xmldb_table('local_personalcourse_quizzes');
        $field = new xmldb_field('sourcecourseid', XMLDB_TYPE_INTEGER, '10', null, null, null, null, 'sourcequizid');
        if ($dbman->table_exists($table) && !$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
            // Add index for performance on course lookups
            $index = new xmldb_index('sourcecourse_idx', XMLDB_INDEX_NOTUNIQUE, ['sourcecourseid']);
            if (!$dbman->index_exists($table, $index)) {
                $dbman->add_index($table, $index);
            }
        }
        upgrade_plugin_savepoint(true, 2025122601, 'local', 'personalcourse');
    }

    return true;
}
