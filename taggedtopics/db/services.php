<?php
// This file is part of Moodle - http://moodle.org/

defined('MOODLE_INTERNAL') || die();

$functions = [
    'local_taggedtopics_get_tags' => [
        'classname'   => 'local_taggedtopics\\external\\get_tags',
        'methodname'  => 'execute',
        'classpath'   => '',
        'description' => 'Get activity tag HTML for a list of course module IDs.',
        'type'        => 'read',
        'ajax'        => true,
        'capabilities'=> '',
        'loginrequired' => true,
    ],
    'local_taggedtopics_get_tags_by_events' => [
        'classname'   => 'local_taggedtopics\\external\\get_tags_by_events',
        'methodname'  => 'execute',
        'classpath'   => '',
        'description' => 'Get activity tag HTML for a list of calendar event IDs (module events).',
        'type'        => 'read',
        'ajax'        => true,
        'capabilities'=> '',
        'loginrequired' => true,
    ],
];

$services = [];
