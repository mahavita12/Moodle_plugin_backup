<?php
// This file provides a small, admin-only helper to force-delete a problematic course
// while printing clear diagnostics about what is blocking the deletion.
//
// Usage (as admin):
//   /local/quizdashboard/force_delete_course.php?id=COURSEID
//   Optional: &confirm=1 to actually attempt the deletion (otherwise it only reports diagnostics)

require_once(__DIR__ . '/../../config.php');

require_login();
require_capability('moodle/site:config', context_system::instance());

require_once($CFG->libdir . '/adminlib.php');
require_once($CFG->dirroot . '/course/lib.php');

$courseid = required_param('id', PARAM_INT);
$confirm  = optional_param('confirm', 0, PARAM_BOOL);
$autofix  = optional_param('autofix', 0, PARAM_BOOL);

$url = new moodle_url('/local/quizdashboard/force_delete_course.php', ['id' => $courseid, 'confirm' => $confirm]);
$PAGE->set_url($url);
$PAGE->set_pagelayout('admin');
$PAGE->set_context(context_system::instance());

echo $OUTPUT->header();
echo $OUTPUT->heading('Force delete course helper');

// Basic course fetch and sanity checks.
try {
    $course = get_course($courseid);
} catch (Throwable $e) {
    echo html_writer::div('Cannot load course with id=' . (int)$courseid . ': ' . s($e->getMessage()), 'alert alert-danger');
    echo $OUTPUT->footer();
    exit;
}

// Diagnostics block.
$diagnostics = [];

// 1) Count course modules and list a few by module type.
$cmscount = $DB->count_records('course_modules', ['course' => $courseid]);
$diagnostics[] = 'course_modules count: ' . $cmscount;

if ($cmscount) {
    $sql = "SELECT m.name, COUNT(*) AS cnt
              FROM {course_modules} cm
              JOIN {modules} m ON m.id = cm.module
             WHERE cm.course = :cid
          GROUP BY m.name
          ORDER BY m.name";
    $types = $DB->get_records_sql($sql, ['cid' => $courseid]);
    $pairs = array_map(function($r){return $r->name . ':' . $r->cnt;}, array_values($types));
    $diagnostics[] = 'module usage: ' . implode(', ', $pairs);
}

// 2) Any sections still referencing non-existent CMs?
$sql = "SELECT cs.id, cs.sequence
          FROM {course_sections} cs
         WHERE cs.course = :cid AND cs.sequence IS NOT NULL AND cs.sequence <> ''";
$sectionswithseq = $DB->get_records_sql($sql, ['cid' => $courseid]);
if ($sectionswithseq) {
    $diagnostics[] = 'sections with non-empty sequence: ' . count($sectionswithseq);
} else {
    $diagnostics[] = 'sections with non-empty sequence: 0';
}

// 3) Check for course-level contexts and block instances tied to the course context.
$coursectx = context_course::instance($courseid, IGNORE_MISSING);
if ($coursectx) {
    $diagnostics[] = 'course context id: ' . $coursectx->id;
    $blockcount = $DB->count_records('block_instances', ['parentcontextid' => $coursectx->id]);
    $diagnostics[] = 'block_instances in course context: ' . $blockcount;
} else {
    $diagnostics[] = 'course context: MISSING';
}

// 4) Which module id 18 is mapped to (if present), to help with the common error message.
$mod18 = $DB->get_record('modules', ['id' => 18]);
if ($mod18) {
    $diagnostics[] = 'modules.id=18 name: ' . $mod18->name;
}

echo html_writer::tag('pre', s(implode("\n", $diagnostics)));

// Optional: Attempt to recreate missing course_modules for any module instances
// that still exist in module tables for this course but do not have a CM row.
if ($autofix) {
    echo $OUTPUT->heading('Autofix: recreate missing course_modules', 3);
    $dbman = $DB->get_manager();

    // Find a target section (by id and section number) to attach recreated or unattached CMs.
    $firstsection = $DB->get_record_sql(
        "SELECT id, section FROM {course_sections} WHERE course = :cid ORDER BY section ASC, id ASC LIMIT 1",
        ['cid' => $courseid]
    );
    if (!$firstsection) {
        // Create section 1 if none exist.
        require_once($CFG->dirroot . '/course/lib.php');
        course_create_sections_if_missing($courseid, [1]);
        $firstsection = $DB->get_record_sql(
            "SELECT id, section FROM {course_sections} WHERE course = :cid ORDER BY section ASC, id ASC LIMIT 1",
            ['cid' => $courseid]
        );
    }
    $firstsectionid = $firstsection->id ?? null;
    $firstsectionnum = $firstsection->section ?? 1; // Fallback to section number 1.

    $inserted = [];
    $attached = [];
    $modules = $DB->get_records('modules', null, 'id');
    foreach ($modules as $m) {
        $tablename = $m->name; // Module table matches module name (e.g., quiz -> quiz).
        // Skip if module table does not exist or does not have a 'course' field.
        $table = new xmldb_table($tablename);
        $field = new xmldb_field('course');
        if (!$dbman->table_exists($table) || !$dbman->field_exists($table, $field)) {
            continue;
        }

        // Get instances of this module in the course that are missing a CM row.
          $sql = "SELECT t.id
                        FROM {" . $tablename . "} t
                 LEFT JOIN {course_modules} cm
                          ON cm.course = :cid1 AND cm.module = :mid1 AND cm.instance = t.id
                      WHERE t.course = :cid2 AND cm.id IS NULL";
          $missing = $DB->get_records_sql($sql, ['cid1' => $courseid, 'mid1' => $m->id, 'cid2' => $courseid]);
        foreach ($missing as $inst) {
            $rec = (object) [
                'course'   => $courseid,
                'module'   => $m->id,
                'instance' => $inst->id,
                'section'  => $firstsectionid, // Temporary; we will also ensure sequence via course_add_cm_to_section below.
                'added'    => time(),
                'visible'  => 0,
            ];
            try {
                $newid = $DB->insert_record('course_modules', $rec);
                $inserted[] = $tablename . ':' . $inst->id . ' -> cmid ' . $newid;
            } catch (\Throwable $e) {
                $inserted[] = $tablename . ':' . $inst->id . ' -> insert failed: ' . $e->getMessage();
            }
        }
    }

    // Ensure every CM in this course appears in a section sequence; if not, attach it to the first section.
    // Build a set of cmids referenced in sequences for this course.
    $seqrecs = $DB->get_records('course_sections', ['course' => $courseid], 'id ASC', 'id,sequence');
    $seqcmids = [];
    foreach ($seqrecs as $sr) {
        if (!empty($sr->sequence)) {
            foreach (explode(',', $sr->sequence) as $cmidstr) {
                $cmid = (int)trim($cmidstr);
                if ($cmid > 0) { $seqcmids[$cmid] = true; }
            }
        }
    }
    // All cmids in course_modules for this course.
    $allcmids = $DB->get_fieldset_select('course_modules', 'id', 'course = :cid', ['cid' => $courseid]);
    foreach ($allcmids as $cmid) {
        if (!isset($seqcmids[$cmid])) {
            try {
                // This API updates both course_sections.sequence and cm->section.
                course_add_cm_to_section($courseid, (int)$cmid, (int)$firstsectionnum);
                $attached[] = 'Attached cmid ' . $cmid . ' to section #' . $firstsectionnum;
            } catch (\Throwable $e) {
                $attached[] = 'Failed to attach cmid ' . $cmid . ': ' . $e->getMessage();
            }
        }
    }

    rebuild_course_cache($courseid, true);
    if ($inserted) {
        echo html_writer::div('Recreated CMs: ' . count($inserted));
        echo html_writer::tag('pre', s(implode("\n", $inserted)));
    } else {
        echo html_writer::div('No missing course_modules found to recreate.', 'alert alert-info');
    }
    if ($attached) {
        echo html_writer::div('Attached CMs to section sequences: ' . count($attached));
        echo html_writer::tag('pre', s(implode("\n", $attached)));
    } else {
        echo html_writer::div('All course_modules already present in section sequences.', 'alert alert-info');
    }
}

// Offer an action link to attempt deletion.
if (!$confirm) {
    $confirmurl = new moodle_url($url, ['confirm' => 1]);
    echo $OUTPUT->single_button($confirmurl, 'Attempt delete_course() now');
    $autofixurl = new moodle_url($url, ['autofix' => 1]);
    echo html_writer::empty_tag('br');
    echo $OUTPUT->single_button($autofixurl, 'Autofix missing course_modules');
    echo $OUTPUT->footer();
    exit;
}

// Attempt the delete with clear messaging and error capture.
try {
    // Rebuild modinfo to avoid stale caches during deletion.
    rebuild_course_cache($courseid, true);

    $ok = delete_course($course, false); // false => no internal echoing of progress
    if ($ok) {
        echo html_writer::div('delete_course() reported success.', 'alert alert-success');
    } else {
        echo html_writer::div('delete_course() returned false.', 'alert alert-warning');
    }
} catch (Throwable $e) {
    echo html_writer::div('Exception during delete_course: ' . s($e->getMessage()), 'alert alert-danger');
    // Print a compact trace to help pinpoint the source (e.g., which module).
    $trace = $e->getTraceAsString();
    echo html_writer::tag('pre', s($trace));
}

echo $OUTPUT->footer();
