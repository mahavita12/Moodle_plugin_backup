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

namespace local_personalcourse\hook\output;

defined('MOODLE_INTERNAL') || die();

/**
 * Hook: runs just before the footer HTML is generated (Moodle 4.4+).
 */
class before_footer_html_generation {
    /**
     * Hook callback for before footer HTML generation.
     * Adds prominent styling to Personal Course notifications only.
     *
     * @param \core\hook\output\before_footer_html_generation $hook
     */
    public static function callback(\core\hook\output\before_footer_html_generation $hook): void {
        global $PAGE, $COURSE, $USER, $DB;

        // Prepare the four localized messages so we only style our own notifications.
        $created = get_string('notify_pq_created_short', 'local_personalcourse');
        $exists  = get_string('notify_pq_exists_short', 'local_personalcourse');
        $first   = get_string('notify_pq_not_created_first_short', 'local_personalcourse');
        $next    = get_string('notify_pq_not_created_next_short', 'local_personalcourse');

        $messages = json_encode([$created, $exists, $first, $next], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT);

        // Determine if we are on the student's personal course page to enable quiz count badges.
        $enablecounts = false;
        $pcourseid = 0;
        try {
            $pagetype = (string)($PAGE->pagetype ?? '');
            if (strpos($pagetype, 'course-view') === 0 && !empty($COURSE) && !empty($COURSE->id) && !empty($USER) && !empty($USER->id)) {
                $pcmap = $DB->get_record('local_personalcourse_courses', ['userid' => (int)$USER->id], 'id,courseid');
                if ($pcmap && (int)$pcmap->courseid === (int)$COURSE->id) {
                    $enablecounts = true;
                    $pcourseid = (int)$COURSE->id;
                }
            }
        } catch (\Throwable $e) { $enablecounts = false; }

        ob_start();
        ?>
        <style>
            /* Personal Course: Highlight our specific notifications */
            .pcq-highlight {
                font-size: 18px !important;
                font-weight: 700 !important;
                color: #1f8ce6 !important;
                border: 2px solid #1f8ce6 !important;
                background: rgba(31, 140, 230, 0.08) !important;
            }
            .pcq-highlight .alert-heading { color: #1f8ce6 !important; }
            /* Personal Course: Quiz count badge */
            .pcq-qcount-badge {
                display: inline-block;
                margin-left: .5rem;
                padding: 0 .5rem;
                font-size: 0.8rem;
                line-height: 1.4;
                border-radius: 999px;
                color: #3c4043;
                background: #eef3f8;
                border: 1px solid #d6e0ea;
                vertical-align: baseline;
                white-space: nowrap;
            }
        </style>
        <script>
            (function() {
                document.addEventListener('DOMContentLoaded', function() {
                    try {
                        var targetMessages = <?php echo $messages; ?>;
                        // Select standard Moodle alert containers.
                        var nodes = document.querySelectorAll('.alert, [role="alert"], .notifications .alert');
                        if (!nodes || !nodes.length) return;
                        nodes.forEach(function(el){
                            try {
                                var text = (el.innerText || el.textContent || '').trim();
                                if (!text) return;
                                for (var i = 0; i < targetMessages.length; i++) {
                                    var msg = String(targetMessages[i]).trim();
                                    if (msg && text.indexOf(msg) !== -1) {
                                        el.classList.add('pcq-highlight');
                                        break;
                                    }
                                }
                            } catch (e) {}
                        });
                    } catch (e) {}
                });
            })();
        </script>
        <?php if ($enablecounts) { $sess = sesskey(); $cid = (int)$pcourseid; ?>
        <script>
            (function() {
                document.addEventListener('DOMContentLoaded', function() {
                    try {
                        var courseid = <?php echo (int)$cid; ?>;
                        var sesskey = <?php echo json_encode($sess, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_AMP|JSON_HEX_QUOT); ?>;
                        var url = <?php echo json_encode((new \moodle_url('/local/personalcourse/ajax/get_question_counts.php'))->out(false), JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_AMP|JSON_HEX_QUOT); ?>;
                        var href = url + '?courseid=' + encodeURIComponent(courseid) + '&sesskey=' + encodeURIComponent(sesskey);
                        fetch(href, {credentials: 'same-origin'})
                            .then(function(r){ return r.ok ? r.json() : {items: []}; })
                            .then(function(data){
                                try {
                                    if (!data || !data.items || !data.items.length) return;
                                    var bycm = {};
                                    data.items.forEach(function(it){ bycm[Number(it.cmid)] = Number(it.count)||0; });
                                    var anchors = document.querySelectorAll('a[href*="/mod/quiz/view.php?id="]');
                                    if (!anchors || !anchors.length) return;
                                    anchors.forEach(function(a){
                                        try {
                                            var m = a.getAttribute('href').match(/[?&]id=(\d+)/);
                                            if (!m) return;
                                            var cmid = Number(m[1]);
                                            if (!cmid || !(cmid in bycm)) return;
                                            var count = bycm[cmid];
                                            // Avoid duplicates.
                                            if (a.parentNode && a.parentNode.querySelector('.pcq-qcount-badge')) return;
                                            var badge = document.createElement('span');
                                            badge.className = 'pcq-qcount-badge';
                                            badge.textContent = (count === 1 ? '1 question' : (count + ' questions'));
                                            // Prefer to append inside the link, after the instance name.
                                            var inst = a.querySelector('.instancename');
                                            if (inst && inst.parentNode === a) { a.appendChild(badge); }
                                            else if (a.parentNode) { a.parentNode.appendChild(badge); }
                                        } catch (e) {}
                                    });
                                } catch (e) {}
                            })
                            .catch(function(){ /* ignore */ });
                    } catch (e) { }
                });
            })();
        </script>
        <?php } ?>
        <?php
        $hook->add_html(ob_get_clean());
    }
}
