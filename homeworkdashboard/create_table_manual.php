<?php
define('CLI_SCRIPT', true);
require_once(dirname(dirname(dirname(__FILE__))) . '/config.php');
require_once($CFG->libdir.'/ddllib.php');

$dbman = $DB->get_manager();
$table = new xmldb_table('local_homework_reports');

$table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
$table->add_field('userid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
$table->add_field('timeclose', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
$table->add_field('subject', XMLDB_TYPE_TEXT, null, null, XMLDB_NOTNULL, null, null);
$table->add_field('content', XMLDB_TYPE_TEXT, 'big', null, XMLDB_NOTNULL, null, null);
$table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);

$table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
$table->add_index('userid_timeclose_idx', XMLDB_INDEX_NOTUNIQUE, ['userid', 'timeclose']);

if (!$dbman->table_exists($table)) {
    $dbman->create_table($table);
    echo "Table local_homework_reports created successfully.\n";
} else {
    echo "Table local_homework_reports already exists.\n";
}
