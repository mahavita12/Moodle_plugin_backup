<?php
define('CLI_SCRIPT', true);
require_once(dirname(dirname(dirname(__FILE__))) . '/config.php');
require_once($CFG->libdir.'/ddllib.php');

$dbman = $DB->get_manager();
$table = new xmldb_table('local_homework_reports');
$field = new xmldb_field('lang', XMLDB_TYPE_CHAR, '10', null, XMLDB_NOTNULL, null, 'en', 'timecreated');

if (!$dbman->field_exists($table, $field)) {
    $dbman->add_field($table, $field);
    echo "Field 'lang' added successfully.\n";
} else {
    echo "Field 'lang' already exists.\n";
}
