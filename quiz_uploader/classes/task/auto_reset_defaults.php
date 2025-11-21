<?php
namespace local_quiz_uploader\task;

defined('MOODLE_INTERNAL') || die();

use core\task\scheduled_task;
use local_quiz_uploader\preset_helper;
use local_quiz_uploader\settings_service;

class auto_reset_defaults extends scheduled_task {

    public function get_name() {
        return 'Quiz Uploader auto-reset defaults';
    }

    public function execute() {
        global $DB;

        $now = time();

        $jobs = $DB->get_records_select(
            'local_quiz_uploader_autoreset',
            'status = :pending AND resetat <= :now',
            ['pending' => 'pending', 'now' => $now],
            'resetat ASC'
        );

        if (empty($jobs)) {
            return;
        }

        foreach ($jobs as $job) {
            $quiz = $DB->get_record('quiz', ['id' => $job->quizid], 'id, course, timeclose', \core\invalid_record_exception::IGNORE_MISSING);
            if (!$quiz) {
                $job->status = 'cancelled';
                $job->timemodified = $now;
                $DB->update_record('local_quiz_uploader_autoreset', $job);
                continue;
            }

            $currentclose = (int)$quiz->timeclose;
            $originalclose = (int)$job->originaltimeclose;

            // If the quiz close time has changed since scheduling, cancel this job.
            if ($currentclose !== $originalclose) {
                $job->status = 'cancelled';
                $job->timemodified = $now;
                $DB->update_record('local_quiz_uploader_autoreset', $job);
                continue;
            }

            // Sanity check on resetat in case cron is misaligned.
            if ($now < (int)$job->resetat) {
                continue;
            }

            try {
                // Apply the Quiz Uploader "Default" preset and clear timeclose (0).
                preset_helper::apply_to_quiz(
                    (int)$quiz->id,
                    'default',
                    0,
                    null,
                    'full',
                    true
                );

                // Set activity classification to "None" for this quiz's course module.
                settings_service::maybe_set_activity_classification((int)$job->cmid, 'None');

                $job->status = 'done';
                $job->timemodified = $now;
                $DB->update_record('local_quiz_uploader_autoreset', $job);
            } catch (\Throwable $e) {
                // On error, cancel this job to avoid repeated failures.
                $job->status = 'cancelled';
                $job->timemodified = $now;
                $DB->update_record('local_quiz_uploader_autoreset', $job);
            }
        }
    }
}
