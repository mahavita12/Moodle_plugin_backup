<?php
defined('MOODLE_INTERNAL') || die();

$functions = array(
    'local_questionflags_flag_question' => array(
        'classname'   => 'local_questionflags\external\flag_question',
        'methodname'  => 'execute',
        'classpath'   => '',
        'description' => 'Flag/unflag a question with blue or red flag',
        'type'        => 'write',
        'ajax'        => true,
        'capabilities' => 'local/questionflags:flag',
        'loginrequired' => true,
    ),
);