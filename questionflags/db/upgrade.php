<?php
defined('MOODLE_INTERNAL') || die();

function xmldb_local_questionflags_upgrade($oldversion) {
    global $DB;
    $dbman = $DB->get_manager();

    if ($oldversion < 2025081600) {
        // Create table for storing question flags
        $table = new xmldb_table('local_questionflags');
        
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('userid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('questionid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('flagcolor', XMLDB_TYPE_CHAR, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);

        $table->add_key('primary', XMLDB_KEY_PRIMARY, array('id'));
        $table->add_key('userid', XMLDB_KEY_FOREIGN, array('userid'), 'user', array('id'));
        $table->add_index('userid_questionid', XMLDB_INDEX_UNIQUE, array('userid', 'questionid'));

        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        upgrade_plugin_savepoint(true, 2025081600, 'local', 'questionflags');
    }

    if ($oldversion < 2025081601) {
        // Create table for storing structure guides per question
        $table = new xmldb_table('local_questionflags_guides');
        
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('questionid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('guide_content', XMLDB_TYPE_TEXT, null, null, null, null, null);
        $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);

        $table->add_key('primary', XMLDB_KEY_PRIMARY, array('id'));
        $table->add_index('questionid', XMLDB_INDEX_UNIQUE, array('questionid'));

        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        upgrade_plugin_savepoint(true, 2025081601, 'local', 'questionflags');
    }

    if ($oldversion < 2025102600) {
        $table = new xmldb_table('local_questionflags');

        $quizid = new xmldb_field('quizid', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
        if (!$dbman->field_exists($table, $quizid)) {
            $dbman->add_field($table, $quizid);
        }

        $cmid = new xmldb_field('cmid', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
        if (!$dbman->field_exists($table, $cmid)) {
            $dbman->add_field($table, $cmid);
        }

        $indexquiz = new xmldb_index('idx_qf_quizid', XMLDB_INDEX_NOTUNIQUE, ['quizid']);
        if (!$dbman->index_exists($table, $indexquiz)) {
            $dbman->add_index($table, $indexquiz);
        }

        $indexcm = new xmldb_index('idx_qf_cmid', XMLDB_INDEX_NOTUNIQUE, ['cmid']);
        if (!$dbman->index_exists($table, $indexcm)) {
            $dbman->add_index($table, $indexcm);
        }

        upgrade_plugin_savepoint(true, 2025102600, 'local', 'questionflags');
    }

    if ($oldversion < 2025122900) {
        $table = new xmldb_table('local_questionflags');
        
        $field = new xmldb_field('points_earned', XMLDB_TYPE_NUMBER, '10, 2', null, XMLDB_NOTNULL, null, '0.00', 'cmid');

        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        upgrade_plugin_savepoint(true, 2025122900, 'local', 'questionflags');
    }

    return true;
}