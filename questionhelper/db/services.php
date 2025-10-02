<?php
defined('MOODLE_INTERNAL') || die();

$functions = array(
    'local_questionhelper_save_help' => array(
        'classname'   => 'local_questionhelper\\external\\save_help',
        'methodname'  => 'execute',
        'classpath'   => '',
        'description' => 'Save generated help content per user and question',
        'type'        => 'write',
        'ajax'        => true,
        'loginrequired' => true,
    ),
    'local_questionhelper_get_help_saved' => array(
        'classname'   => 'local_questionhelper\\external\\get_help_saved',
        'methodname'  => 'execute',
        'classpath'   => '',
        'description' => 'Get saved help content per user and question',
        'type'        => 'read',
        'ajax'        => true,
        'loginrequired' => true,
    ),
    'local_questionhelper_is_enabled_for_quiz' => array(
        'classname'   => 'local_questionhelper\\external\\is_enabled_for_quiz',
        'methodname'  => 'execute',
        'classpath'   => '',
        'description' => 'Check whether Question Helper is enabled for a quiz by tags',
        'type'        => 'read',
        'ajax'        => true,
        'loginrequired' => true,
    ),
);


