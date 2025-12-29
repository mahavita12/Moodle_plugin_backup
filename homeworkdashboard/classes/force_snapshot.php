<?php
define('CLI_SCRIPT', true);
require(__DIR__ . '/../../../config.php');
require_once($CFG->dirroot . '/local/homeworkdashboard/classes/homework_manager.php');

mtrace("Forcing Snapshot Computation...");

$manager = new \local_homeworkdashboard\homework_manager();
$manager->compute_due_snapshots();

mtrace("Snapshot compuation complete.");
