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

    if ($oldversion < 2025092902) {
        // ROLLING GLOBAL STRATEGY: Redesign for rolling global question updates
        $table = new xmldb_table('local_qh_saved_help');
        
        // Drop all existing indexes first to avoid dependency issues
        // Use exact index names from the database
        $existing_indexes = [
            ['name' => 'userid_idx', 'fields' => ['userid']],
            ['name' => 'questionid_idx', 'fields' => ['questionid']],
            ['name' => 'user_q_variant_idx', 'fields' => ['userid', 'questionid', 'variant']],
            ['name' => 'personal_user_q_variant_idx', 'fields' => ['userid', 'questionid', 'variant']],
            ['name' => 'global_q_variant_idx', 'fields' => ['questionid', 'variant', 'is_global']]
        ];
        
        foreach ($existing_indexes as $idx_info) {
            $index = new xmldb_index($idx_info['name'], XMLDB_INDEX_NOTUNIQUE, $idx_info['fields']);
            if ($dbman->index_exists($table, $index)) {
                $dbman->drop_index($table, $index);
            }
        }
        
        // Also drop the specific indexes we found in the database
        try {
            $DB->execute("DROP INDEX mdl_locaqhsavehelp_use_ix ON {local_qh_saved_help}");
        } catch (Exception $e) {
            // Index might not exist, continue
        }
        
        try {
            $DB->execute("DROP INDEX mdl_locaqhsavehelp_usequev3_ix ON {local_qh_saved_help}");
        } catch (Exception $e) {
            // Index might not exist, continue
        }
        
        // Drop all existing keys
        $existing_keys = ['user_q_variant_unique', 'user_q_variant_attempt_unique'];
        foreach ($existing_keys as $key_name) {
            $key = new xmldb_key($key_name, XMLDB_KEY_UNIQUE, []);
            if ($dbman->find_key_name($table, $key)) {
                $dbman->drop_key($table, $key);
            }
        }
        
        // Remove attempt_number field if it exists (from previous strategy)
        $attempt_field = new xmldb_field('attempt_number');
        if ($dbman->field_exists($table, $attempt_field)) {
            $dbman->drop_field($table, $attempt_field);
        }
        
        // Add is_global field to mark global records
        $global_field = new xmldb_field('is_global', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, 0, 'variant');
        if (!$dbman->field_exists($table, $global_field)) {
            $dbman->add_field($table, $global_field);
        }
        
        // Modify userid to allow NULL for global records (after dropping indexes)
        $userid_field = new xmldb_field('userid', XMLDB_TYPE_INTEGER, '10', null, false, null, null, 'id');
        if ($dbman->field_exists($table, $userid_field)) {
            $dbman->change_field_notnull($table, $userid_field);
        }
        
        // Recreate basic indexes
        $userid_index = new xmldb_index('userid_idx', XMLDB_INDEX_NOTUNIQUE, ['userid']);
        if (!$dbman->index_exists($table, $userid_index)) {
            $dbman->add_index($table, $userid_index);
        }
        
        $questionid_index = new xmldb_index('questionid_idx', XMLDB_INDEX_NOTUNIQUE, ['questionid']);
        if (!$dbman->index_exists($table, $questionid_index)) {
            $dbman->add_index($table, $questionid_index);
        }
        
        upgrade_plugin_savepoint(true, 2025092902, 'local', 'questionhelper');
    }

    return true;
}

