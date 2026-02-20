<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * API for Question Flags plugin.
 *
 * @package    local_questionflags
 * @copyright  2024 Question Flags Development Team
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_questionflags;

defined('MOODLE_INTERNAL') || die();

/**
 * Static API for efficient flag retrieval.
 */
class api {

    /** @var array Cache of flags for the current request. [quizid => [questionid => color]] */
    private static $flagcache = [];

    /** @var array Set of quiz IDs that have been fully preloaded. */
    private static $preloadedquizzes = [];

    /**
     * Preload all flags for a specific quiz and user into static cache.
     * This allows us to fetch 50+ flags in a single DB query instead of 50.
     *
     * @param int $quizid The quiz ID (instance ID, not cmid).
     * @param int $userid The user ID.
     * @return void
     */
    public static function preload_flags(int $quizid, int $userid): void {
        global $DB;

        if ($quizid <= 0 || $userid <= 0) {
            return;
        }

        // caching optimization: if already loaded, don't hit DB again.
        $cachekey = $quizid . '_' . $userid;
        if (isset(self::$preloadedquizzes[$cachekey])) {
            return;
        }

        // Initialize cache bucket
        if (!isset(self::$flagcache[$cachekey])) {
            self::$flagcache[$cachekey] = [];
        }

        // Fetch all flags for this user/quiz tuple.
        // We only care about questionid and flagcolor.
        $records = $DB->get_records('local_questionflags', [
            'userid' => $userid,
            'quizid' => $quizid
        ], '', 'questionid, flagcolor');

        foreach ($records as $rec) {
            self::$flagcache[$cachekey][(int)$rec->questionid] = $rec->flagcolor;
        }

        // Mark as loaded so we don't query again
        self::$preloadedquizzes[$cachekey] = true;
    }

    /**
     * Get the CSS class for a flag on a specific question.
     * returns 'blue-flagged', 'red-flagged', or empty string.
     *
     * @param int $questionid The question ID.
     * @return string CSS class or empty string.
     */
    public static function get_flag_class(int $questionid): string {
        global $PAGE, $USER;

        // Try to guess context if not readily available, but primarily rely on preloaded cache.
        // We iterate over all loaded quizzes in cache to find this question.
        // This is fast because typically only 1 quiz is loaded per page request.
        
        $flagcolor = null;

        // 1. Search in the caches (most likely path)
        foreach (self::$flagcache as $cachekey => $map) {
            if (isset($map[$questionid])) {
                $flagcolor = $map[$questionid];
                break;
            }
        }

        // 2. Fallback: If not found in cache, check DB directly (slow path, single item).
        // This handles edge cases where preload wasn't called.
        if ($flagcolor === null && isloggedin() && !isguestuser()) {
            // We need a lightweight check.
            // Note: We don't know the quizid here easily without context, 
            // but the table has (userid, questionid) which is usually sufficient 
            // if we assume a question flag is global for that user.
            global $DB;
            $rec = $DB->get_record('local_questionflags', [
                'userid' => $USER->id,
                'questionid' => $questionid
            ], 'flagcolor', IGNORE_MISSING);
            
            if ($rec) {
                $flagcolor = $rec->flagcolor;
            }
        }

        if ($flagcolor === 'blue') {
            return 'blue-flagged';
        }
        if ($flagcolor === 'red') {
            return 'red-flagged';
        }

        return '';
    }
}
