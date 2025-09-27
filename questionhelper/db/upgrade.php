<?php
defined('MOODLE_INTERNAL') || die();

function xmldb_local_questionhelper_upgrade($oldversion) {
    global $DB;

    $dbman = $DB->get_manager();

    if ($oldversion < 2025092701) {
        // Define table local_qh_saved_help.
        $table = new xmldb_table('local_qh_saved_help');

        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('userid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('questionid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('practice_question', XMLDB_TYPE_TEXT, null, null, null, null, null);
        $table->add_field('optionsjson', XMLDB_TYPE_TEXT, null, null, null, null, null);
        $table->add_field('correct_answer', XMLDB_TYPE_CHAR, '1', null, null, null, null);
        $table->add_field('explanation', XMLDB_TYPE_TEXT, null, null, null, null, null);
        $table->add_field('concept_explanation', XMLDB_TYPE_TEXT, null, null, null, null, null);
        $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, 0);
        $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, 0);

        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_key('user_q_unique', XMLDB_KEY_UNIQUE, ['userid', 'questionid']);
        $table->add_index('userid_idx', XMLDB_INDEX_NOTUNIQUE, ['userid']);
        $table->add_index('questionid_idx', XMLDB_INDEX_NOTUNIQUE, ['questionid']);

        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        upgrade_plugin_savepoint(true, 2025092701, 'local', 'questionhelper');
    }

    if ($oldversion < 2025092801) {
        // Add variant column and update unique key.
        $table = new xmldb_table('local_qh_saved_help');

        $field = new xmldb_field('variant', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, 'help', 'userid');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        // Drop old unique key if exists.
        $key = new xmldb_key('user_q_unique', XMLDB_KEY_UNIQUE, ['userid', 'questionid']);
        if ($dbman->find_key_name($table, $key)) {
            $dbman->drop_key($table, $key);
        }
        // Add new unique key including variant.
        $key2 = new xmldb_key('user_q_variant_unique', XMLDB_KEY_UNIQUE, ['userid', 'questionid', 'variant']);
        if (!$dbman->find_key_name($table, $key2)) {
            $dbman->add_key($table, $key2);
        }

        upgrade_plugin_savepoint(true, 2025092801, 'local', 'questionhelper');
    }

    return true;
}

