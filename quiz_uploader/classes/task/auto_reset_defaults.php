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

        $courseidstorebuild = [];

        foreach ($jobs as $job) {
            $quiz = $DB->get_record('quiz', ['id' => $job->quizid], 'id, course, timeclose', IGNORE_MISSING);
            if (!$quiz) {
                $job->status = 'cancelled';
                $job->timemodified = $now;
                $DB->update_record('local_quiz_uploader_autoreset', $job);
                continue;
            }

            $currentclose = (int)$quiz->timeclose;
            $originalclose = (int)$job->originaltimeclose;

            // If the quiz is already open (0), we assume it was reset (possibly by a previous crashed run)
            // or manually opened. In either case, we proceed to ensure cleanup (badges, job status).
            // Only cancel if it was changed to a DIFFERENT non-zero time.
            if ($currentclose !== $originalclose && $currentclose !== 0) {
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
                // Pass false to defer cache rebuilding.
                preset_helper::apply_to_quiz(
                    (int)$quiz->id,
                    'default',
                    0,
                    null,
                    'full',
                    true,
                    false
                );

                // Set activity classification to "None" for this quiz's course module.
                settings_service::maybe_set_activity_classification((int)$job->cmid, 'None');

                $job->status = 'done';
                $job->timemodified = $now;
                $DB->update_record('local_quiz_uploader_autoreset', $job);

                $courseidstorebuild[$job->courseid] = true;

            } catch (\Throwable $e) {
                // On error, increment retries and decide whether to retry or fail.
                $job->retries = (int)$job->retries + 1;
                $job->lasterror = substr($e->getMessage(), 0, 1000); // Truncate to fit text field if needed
                $job->timemodified = $now;

                if ($job->retries < 3) {
                    // Keep status 'pending' to retry next time.
                    $job->status = 'pending';
                } else {
                    // Too many retries, mark as failed.
                    $job->status = 'failed';
                }
                $DB->update_record('local_quiz_uploader_autoreset', $job);
            }
        }

        // Rebuild course caches in bulk at the end.
        if (!empty($courseidstorebuild)) {
            foreach (array_keys($courseidstorebuild) as $cid) {
                \rebuild_course_cache($cid, true);
            }
        }
    }
}
