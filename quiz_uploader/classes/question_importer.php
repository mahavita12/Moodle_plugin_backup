<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

namespace local_quiz_uploader;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/question/format.php');
require_once($CFG->dirroot . '/question/format/xml/format.php');
require_once($CFG->dirroot . '/question/engine/bank.php');

/**
 * Import questions from XML using Moodle's qformat_xml.
 */
class question_importer {

    /**
     * Import questions from XML content.
     *
     * @param string $xmlcontent XML file content
     * @param object $category Target question category object
     * @param int $courseid Course ID
     * @return object Result with question IDs and success status
     */
    public static function import_from_xml($xmlcontent, $category, $courseid) {
        global $DB, $USER, $CFG;

        $result = new \stdClass();
        $result->success = false;
        $result->questionids = [];
        $result->count = 0;
        $result->error = null;

        $tempfile = null;

        try {
            // Get course
            $course = $DB->get_record('course', ['id' => $courseid], '*', MUST_EXIST);

            // Get context
            $context = \context::instance_by_id($category->contextid);

            // Write XML content to temporary file
            $tempfile = tempnam($CFG->tempdir, 'quiz_import_');
            if (!$tempfile || !file_put_contents($tempfile, $xmlcontent)) {
                throw new \moodle_exception('Failed to create temporary file');
            }
            
            // Log XML content preview for debugging
            // error_log('Quiz Uploader - XML content first 500 chars: ' . substr($xmlcontent, 0, 500));
            // error_log('Quiz Uploader - XML content length: ' . strlen($xmlcontent));

            // Create qformat_xml importer
            $qformat = new \qformat_xml();

            // Set up the importer
            $qformat->setCategory($category);
            $qformat->setContexts([$context]);
            $qformat->setCourse($course);
            $qformat->setFilename($tempfile);  // Use temp file
            $qformat->setRealfilename('import.xml');
            $qformat->setMatchgrades('error');
            $qformat->setCatfromfile(true);  // Allow categories from file
            $qformat->setContextfromfile(false);  // Don't change context
            $qformat->setStoponerror(false);  // Continue on errors
            $qformat->set_display_progress(false);  // Disable progress output

            // Log before import attempt
            // error_log('Quiz Uploader - Starting importprocess...');
            // error_log('Quiz Uploader - Temp file exists: ' . (file_exists($tempfile) ? 'YES' : 'NO'));
            // error_log('Quiz Uploader - Temp file size: ' . filesize($tempfile) . ' bytes');
            // error_log('Quiz Uploader - Category ID: ' . $category->id . ', Context ID: ' . $category->contextid);
            
            // Import questions using importprocess (handles read + parse + save internally)
            if ($qformat->importprocess()) {
                $result->success = true;
                $result->questionids = $qformat->questionids;
                $result->count = count($qformat->questionids);
                // error_log('Quiz Uploader - Import SUCCESS! Question count: ' . $result->count);
            } else {
                // Get detailed error info
                $errors = [];
                if (property_exists($qformat, 'importerrors') && $qformat->importerrors) {
                    $errors[] = 'Import errors count: ' . $qformat->importerrors;
                }
                // Check for question IDs even on failure
                $questionCount = property_exists($qformat, 'questionids') ? count($qformat->questionids) : 0;
                $errors[] = 'Questions imported before failure: ' . $questionCount;
                
                $result->error = 'Import process failed: ' . implode('; ', $errors);
                $result->count = $qformat->importerrors ?? 0;
                // error_log('Quiz Uploader - Import FAILED: ' . $result->error);
            }

        } catch (\Exception $e) {
            $result->error = $e->getMessage();
            error_log('Quiz Uploader - Exception: ' . $e->getMessage() . ' - Trace: ' . $e->getTraceAsString());
        } finally {
            // Clean up temp file
            if ($tempfile && file_exists($tempfile)) {
                @unlink($tempfile);
            }
        }

        return $result;
    }

    /**
     * Get file content from draft area.
     *
     * @param int $draftitemid Draft item ID
     * @param int $userid User ID
     * @return string|null File content or null if not found
     */
    public static function get_file_from_draft($draftitemid, $userid) {
        $fs = get_file_storage();
        $usercontext = \context_user::instance($userid);

        // Get files in draft area
        $files = $fs->get_area_files(
            $usercontext->id,
            'user',
            'draft',
            $draftitemid,
            'id DESC',
            false  // Exclude directories
        );

        if (empty($files)) {
            debugging("No files found in draft area. Context: {$usercontext->id}, DraftID: {$draftitemid}", DEBUG_DEVELOPER);
            return null;
        }

        // Get the first file
        $file = reset($files);
        $content = $file->get_content();
        debugging("File retrieved: {$file->get_filename()}, Size: {$file->get_filesize()} bytes, Content length: " . strlen($content), DEBUG_DEVELOPER);
        return $content;
    }

    /**
     * Validate that XML file exists in draft area.
     *
     * @param int $draftitemid Draft item ID
     * @param int $userid User ID
     * @return bool True if file exists
     */
    public static function validate_draft_file($draftitemid, $userid) {
        $fs = get_file_storage();
        $usercontext = \context_user::instance($userid);

        $files = $fs->get_area_files(
            $usercontext->id,
            'user',
            'draft',
            $draftitemid,
            'id DESC',
            false
        );

        return !empty($files);
    }
}
