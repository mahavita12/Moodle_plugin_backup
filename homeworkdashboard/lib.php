<?php
defined('MOODLE_INTERNAL') || die();

use core_calendar\local\event\entities\event_interface;

function local_homeworkdashboard_calendar_get_event_homework_status(event_interface $event): ?string {
    global $USER, $DB;

    if (empty($USER) || empty($USER->id)) {
        return null;
    }

    if ($event->get_component() !== 'mod_quiz') {
        return null;
    }

    if ($event->get_type() !== 'close') {
        return null;
    }

    $eventid = $event->get_id();
    if ($eventid <= 0) {
        return null;
    }

    $ev = $DB->get_record('event', ['id' => $eventid], 'id, courseid, instance, modulename, eventtype, timestart', IGNORE_MISSING);
    if (!$ev) {
        return null;
    }

    if ($ev->modulename !== 'quiz' || $ev->eventtype !== 'close') {
        return null;
    }

    $courseid = (int)$ev->courseid;
    $quizid = (int)$ev->instance;
    $timeclose = (int)$ev->timestart;

    if ($courseid <= 0 || $quizid <= 0 || $timeclose <= 0) {
        return null;
    }

    $manager = new \local_homeworkdashboard\homework_manager();

    return $manager->get_homework_status_for_user_quiz_event(
        (int)$USER->id,
        $quizid,
        $courseid,
        $timeclose
    );
}
