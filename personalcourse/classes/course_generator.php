<?php
namespace local_personalcourse;

defined('MOODLE_INTERNAL') || die();

use context_system;

class course_generator {
    public function ensure_personal_course(int $userid): object {
        global $DB, $CFG;
        require_once($CFG->dirroot . '/course/lib.php');

        // Compute target names based on user.
        $user = $DB->get_record('user', ['id' => $userid], 'id,firstname,lastname');
        $displayname = trim(($user->firstname ?? '') . ' ' . ($user->lastname ?? ''));
        $targetfullname = $displayname . "'s Classroom";
        $lastinitial = '';
        if (!empty($user->lastname)) {
            $lastinitial = strtoupper(substr((string)$user->lastname, 0, 1));
        }
        $shortname = trim((string)($user->firstname ?? '')) . ($lastinitial !== '' ? ('_' . $lastinitial) : '');

        // Check existing mapping and ensure naming is up to date.
        $pc = $DB->get_record('local_personalcourse_courses', ['userid' => $userid]);
        if ($pc) {
            $course = $DB->get_record('course', ['id' => $pc->courseid], '*', MUST_EXIST);
            $needsrefresh = false;
            if ((string)$course->fullname !== $targetfullname) {
                $DB->set_field('course', 'fullname', $targetfullname, ['id' => (int)$course->id]);
                $needsrefresh = true;
            }
            if ($shortname !== '' && (string)$course->shortname !== $shortname) {
                // If another course already uses this shortname, append _<userid> to avoid collision.
                $exists = $DB->get_record('course', ['shortname' => $shortname], 'id');
                $newshort = $shortname;
                if ($exists && (int)$exists->id !== (int)$course->id) {
                    $newshort = $shortname . '_' . (int)$userid;
                }
                $DB->set_field('course', 'shortname', $newshort, ['id' => (int)$course->id]);
                $needsrefresh = true;
            }
            if ($needsrefresh) {
                $course = $DB->get_record('course', ['id' => $pc->courseid], '*', MUST_EXIST);
            }
            return (object)['pc' => $pc, 'course' => $course];
        }

        // Build course fullname and shortname.
        $fullname = $targetfullname;

        // Find or create category.
        $categoryname = 'Personal Review Courses';
        $category = $DB->get_record('course_categories', ['name' => $categoryname], 'id, name');
        if (!$category) {
            // Create category at top level.
            $category = new \stdClass();
            $category->name = $categoryname;
            $category->parent = 0;
            $category->idnumber = '';
            $category->visible = 1;
            $category->id = \core_course_category::create($category)->id;
        }

        // Prepare course creation data.
        $data = new \stdClass();
        $data->fullname = $fullname;
        // Ensure shortname uniqueness across site.
        $targetshort = $shortname;
        if ($targetshort === '' || $DB->record_exists('course', ['shortname' => $targetshort])) {
            $targetshort = ($shortname !== '' ? $shortname : 'PC') . '_' . (int)$userid;
        }
        $data->shortname = $targetshort;
        $data->category = $category->id;
        $data->visible = 1; // Visible; access enforced by enrolments.

        $course = create_course($data);

        // Map in local table.
        $rec = new \stdClass();
        $rec->userid = $userid;
        $rec->courseid = $course->id;
        $rec->status = 'active';
        $rec->timecreated = time();
        $rec->timemodified = $rec->timecreated;
        $rec->id = $DB->insert_record('local_personalcourse_courses', $rec);

        return (object)['pc' => $rec, 'course' => $course];
    }
}
