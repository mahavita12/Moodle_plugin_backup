<?php
namespace local_quizdashboard\output;

defined('MOODLE_INTERNAL') || die();

use renderable;
use templatable;
use renderer_base;

class essay_dashboard_page implements renderable, templatable {
    /** @var array */
    private $data;

    public function __construct(array $data) {
        $this->data = $data;
    }

    public function export_for_template(renderer_base $output): array {
        // Map sources for selects
        $users = [];
        foreach ($this->data['sources']['users'] as $u) {
            $users[] = ['id' => $u->id, 'name' => $u->fullname];
        }
        $courses = [];
        foreach ($this->data['sources']['courses'] as $c) {
            $courses[] = ['id' => $c->id, 'name' => $c->fullname];
        }
        $quizzes = [];
        foreach ($this->data['sources']['quizzes'] as $q) {
            $quizzes[] = ['id' => $q->id, 'name' => $q->name];
        }

        // Rows
        $rows = [];
        foreach ($this->data['rows'] as $r) {
            $statuslc = strtolower($r->status);
            $rows[] = [
                'attemptid'   => $r->attemptid,
                'userid'      => $r->userid,
                'studentname' => format_string($r->studentname),
                'coursename'  => format_string($r->coursename),
                'quizname'    => format_string($r->quizname),
                'attemptno'   => $r->attemptno,
                'status'      => $r->status,
                'statusclass' => ($statuslc === 'finished' || $statuslc === 'completed') ? 'success'
                                 : ($statuslc === 'in progress' ? 'warning' : 'secondary'),
                'timestart'   => $r->timestart ? userdate($r->timestart, '%Y-%m-%d %H:%M') : '',
                'timefinish'  => $r->timefinish ? userdate($r->timefinish, '%Y-%m-%d %H:%M') : '',
                'score'       => $r->score,
                'maxscore'    => $r->maxscore,
                'percentage'  => $r->percentage,
                'quiz_type'   => $r->quiz_type,
                'reviewurl'   => $r->reviewurl,
                'gradeurl'    => $r->gradeurl,
                'nextstep'    => $r->nextstep,
                'nexturl'     => $r->nexturl,
            ];
        }

        $filters = $this->data['filters'] ?? [];
        $filters['quiztypeEssay']    = ($filters['quiztype'] ?? '') === 'Essay';
        $filters['quiztypeNonEssay'] = ($filters['quiztype'] ?? '') === 'Non-Essay';

        global $CFG;
        return [
            'wwwroot' => $CFG->wwwroot,
            'filters' => $filters,
            'users'   => $users,
            'courses' => $courses,
            'quizzes' => $quizzes,
            'rows'    => $rows,
        ];
    }
}
