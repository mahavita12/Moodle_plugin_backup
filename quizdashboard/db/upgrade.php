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

    // Add subcategory score columns for stored grading data
    if ($oldversion < 2025092601) {
        $table = new xmldb_table('local_quizdashboard_gradings');

        $fields = [
            new xmldb_field('score_content_ideas', XMLDB_TYPE_INTEGER, '3', null, null, null, null, 'grading_level'),
            new xmldb_field('score_structure_organization', XMLDB_TYPE_INTEGER, '3', null, null, null, null, 'score_content_ideas'),
            new xmldb_field('score_language_use', XMLDB_TYPE_INTEGER, '3', null, null, null, null, 'score_structure_organization'),
            new xmldb_field('score_creativity_originality', XMLDB_TYPE_INTEGER, '3', null, null, null, null, 'score_language_use'),
            new xmldb_field('score_mechanics', XMLDB_TYPE_INTEGER, '3', null, null, null, null, 'score_creativity_originality'),
        ];

        foreach ($fields as $field) {
            if (!$dbman->field_exists($table, $field)) {
                $dbman->add_field($table, $field);
            }
        }

        upgrade_plugin_savepoint(true, 2025092601, 'local', 'quizdashboard');
    }

    // Add similarity fields to gradings table
    if ($oldversion < 2025092901) {
        $table = new xmldb_table('local_quizdashboard_gradings');

        $fields = [
            new xmldb_field('similarity_percent', XMLDB_TYPE_INTEGER, '3', null, null, null, null, 'score_mechanics'),
            new xmldb_field('similarity_flag', XMLDB_TYPE_INTEGER, '1', null, null, null, '0', 'similarity_percent'),
            new xmldb_field('similarity_checkedat', XMLDB_TYPE_INTEGER, '10', null, null, null, null, 'similarity_flag'),
        ];

        foreach ($fields as $field) {
            if (!$dbman->field_exists($table, $field)) {
                $dbman->add_field($table, $field);
            }
        }

        upgrade_plugin_savepoint(true, 2025092901, 'local', 'quizdashboard');
    }

    // Ensure large text capacity for feedback/homework/comments (maps to LONGTEXT on MySQL)
    if ($oldversion < 2025101301) {
        $table = new xmldb_table('local_quizdashboard_gradings');

        // feedback_html -> BIG TEXT
        $feedback = new xmldb_field('feedback_html', XMLDB_TYPE_TEXT, 'big', null, null, null, null, 'attempt_id');
        if ($dbman->field_exists($table, $feedback)) {
            $dbman->change_field_precision($table, $feedback);
        }

        // homework_html -> BIG TEXT (if present)
        $homework = new xmldb_field('homework_html', XMLDB_TYPE_TEXT, 'big', null, null, null, null, 'ai_likelihood');
        if ($dbman->field_exists($table, $homework)) {
            $dbman->change_field_precision($table, $homework);
        }

        // overall_comments -> BIG TEXT
        $overall = new xmldb_field('overall_comments', XMLDB_TYPE_TEXT, 'big', null, null, null, null, 'feedback_html');
        if ($dbman->field_exists($table, $overall)) {
            $dbman->change_field_precision($table, $overall);
        }

        upgrade_plugin_savepoint(true, 2025101301, 'local', 'quizdashboard');
    }

    // Add homework_json field to store structured JSON for injection
    if ($oldversion < 2025111001) {
        $table = new xmldb_table('local_quizdashboard_gradings');
        $field = new xmldb_field('homework_json', XMLDB_TYPE_TEXT, 'big', null, null, null, null, 'homework_html');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        upgrade_plugin_savepoint(true, 2025111001, 'local', 'quizdashboard');
    }

    // Ensure JSON columns exist for structured data (homework_json + feedback_json).
    if ($oldversion < 2025111401) {
        $table = new xmldb_table('local_quizdashboard_gradings');

        // homework_json (BIG TEXT)
        $homeworkjson = new xmldb_field('homework_json', XMLDB_TYPE_TEXT, 'big', null, null, null, null, 'homework_html');
        if (!$dbman->field_exists($table, $homeworkjson)) {
            $dbman->add_field($table, $homeworkjson);
        }

        // feedback_json (BIG TEXT) – for future structured feedback storage
        $feedbackjson = new xmldb_field('feedback_json', XMLDB_TYPE_TEXT, 'big', null, null, null, null, 'feedback_html');
        if (!$dbman->field_exists($table, $feedbackjson)) {
            $dbman->add_field($table, $feedbackjson);
        }

        upgrade_plugin_savepoint(true, 2025111401, 'local', 'quizdashboard');
    }

    // Add revision_json column for structured revision saving.
    if ($oldversion < 2025111402) {
        $table = new xmldb_table('local_quizdashboard_gradings');
        $revisionjson = new xmldb_field('revision_json', XMLDB_TYPE_TEXT, 'big', null, null, null, null, 'feedback_json');
        if (!$dbman->field_exists($table, $revisionjson)) {
            $dbman->add_field($table, $revisionjson);
        }
        upgrade_plugin_savepoint(true, 2025111402, 'local', 'quizdashboard');
    }

    return true;
}

