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
            $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
            $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
            $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $table->add_index('personalcourseid', XMLDB_INDEX_NOTUNIQUE, ['personalcourseid']);
            $table->add_index('sourcequiz_unique', XMLDB_INDEX_UNIQUE, ['personalcourseid', 'sourcequizid']);
            $dbman->create_table($table);
        }

        // Table: local_personalcourse_questions.
        $table = new xmldb_table('local_personalcourse_questions');
        if (!$dbman->table_exists($table)) {
            $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
            $table->add_field('personalcourseid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
            $table->add_field('personalquizid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
            $table->add_field('questionid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
            $table->add_field('slotid', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
            $table->add_field('flagcolor', XMLDB_TYPE_CHAR, '10', null, null, null, null);
            $table->add_field('source', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, null);
            $table->add_field('originalposition', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
            $table->add_field('currentposition', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
            $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
            $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
            $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $table->add_index('personalquizid', XMLDB_INDEX_NOTUNIQUE, ['personalquizid']);
            $table->add_index('questionid', XMLDB_INDEX_NOTUNIQUE, ['questionid']);
            $table->add_index('pcq_unique', XMLDB_INDEX_UNIQUE, ['personalcourseid', 'questionid']);
            $dbman->create_table($table);
        }

        // Table: local_personalcourse_generations.
        $table = new xmldb_table('local_personalcourse_generations');
        if (!$dbman->table_exists($table)) {
            $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
            $table->add_field('personalcourseid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
            $table->add_field('userid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
            $table->add_field('sourcequizid', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
            $table->add_field('action', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, null);
            $table->add_field('attemptnumber', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
            $table->add_field('gradepercent', XMLDB_TYPE_NUMBER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
            $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $table->add_index('pc_time', XMLDB_INDEX_NOTUNIQUE, ['personalcourseid', 'timecreated']);
            $table->add_index('user_quiz', XMLDB_INDEX_NOTUNIQUE, ['userid', 'sourcequizid']);
            $dbman->create_table($table);
        }

        upgrade_plugin_savepoint(true, 2025102600, 'local', 'personalcourse');
    }

    if ($oldversion < 2025102602) {
        upgrade_plugin_savepoint(true, 2025102602, 'local', 'personalcourse');
    }

    if ($oldversion < 2025102603) {
        // Safeguard: ensure all tables exist even if plugin was installed before install.xml.
        $tables = [
            'local_personalcourse_courses' => function() use ($dbman) {
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
            },
            'local_personalcourse_quizzes' => function() use ($dbman) {
                $table = new xmldb_table('local_personalcourse_quizzes');
                if (!$dbman->table_exists($table)) {
                    $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
                    $table->add_field('personalcourseid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
                    $table->add_field('quizid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
                    $table->add_field('sourcequizid', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
                    $table->add_field('sectionname', XMLDB_TYPE_CHAR, '255', null, null, null, null);
                    $table->add_field('quiztype', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, 'non_essay');
                    $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
                    $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
                    $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
                    $table->add_index('personalcourseid', XMLDB_INDEX_NOTUNIQUE, ['personalcourseid']);
                    $table->add_index('sourcequiz_unique', XMLDB_INDEX_UNIQUE, ['personalcourseid', 'sourcequizid']);
                    $dbman->create_table($table);
                }
            },
            'local_personalcourse_questions' => function() use ($dbman) {
                $table = new xmldb_table('local_personalcourse_questions');
                if (!$dbman->table_exists($table)) {
                    $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
                    $table->add_field('personalcourseid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
                    $table->add_field('personalquizid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
                    $table->add_field('questionid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
                    $table->add_field('slotid', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
                    $table->add_field('flagcolor', XMLDB_TYPE_CHAR, '10', null, null, null, null);
                    $table->add_field('source', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, null);
                    $table->add_field('originalposition', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
                    $table->add_field('currentposition', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
                    $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
                    $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
                    $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
                    $table->add_index('personalquizid', XMLDB_INDEX_NOTUNIQUE, ['personalquizid']);
                    $table->add_index('questionid', XMLDB_INDEX_NOTUNIQUE, ['questionid']);
                    $table->add_index('pcq_unique', XMLDB_INDEX_UNIQUE, ['personalcourseid', 'questionid']);
                    $dbman->create_table($table);
                }
            },
            'local_personalcourse_generations' => function() use ($dbman) {
                $table = new xmldb_table('local_personalcourse_generations');
                if (!$dbman->table_exists($table)) {
                    $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
                    $table->add_field('personalcourseid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
                    $table->add_field('userid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
                    $table->add_field('sourcequizid', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
                    $table->add_field('action', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, null);
                    $table->add_field('attemptnumber', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
                    $table->add_field('gradepercent', XMLDB_TYPE_NUMBER, '10', null, XMLDB_NOTNULL, null, '0');
                    $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
                    $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
                    $table->add_index('pc_time', XMLDB_INDEX_NOTUNIQUE, ['personalcourseid', 'timecreated']);
                    $table->add_index('user_quiz', XMLDB_INDEX_NOTUNIQUE, ['userid', 'sourcequizid']);
                    $dbman->create_table($table);
                }
            },
        ];

        foreach ($tables as $callback) {
            $callback();
        }

        upgrade_plugin_savepoint(true, 2025102603, 'local', 'personalcourse');
    }

    return true;
}
