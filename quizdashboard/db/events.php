<?php
// Event observers for local_quizdashboard.

defined('MOODLE_INTERNAL') || die();

$observers = [
    // Ensure newly-created quiz activities are attached to a course section
    // to prevent orphaned CMs that break /mod/quiz/view.php?id=cmid.
    [
        'eventname'   => '\\core\\event\\course_module_created',
        'callback'    => 'local_quizdashboard\\observer::quiz_cm_autofix',
        'includefile' => '/local/quizdashboard/classes/observer.php',
        'internal'    => false,
        'priority'    => 9999,
    ],
    // Also run on updates (e.g., programmatic inserts followed by late moves).
    [
        'eventname'   => '\\core\\event\\course_module_updated',
        'callback'    => 'local_quizdashboard\\observer::quiz_cm_autofix',
        'includefile' => '/local/quizdashboard/classes/observer.php',
        'internal'    => false,
        'priority'    => 9999,
    ],
];

return $observers;
