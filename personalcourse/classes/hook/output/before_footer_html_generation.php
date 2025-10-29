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
        global $PAGE;

        // Prepare the four localized messages so we only style our own notifications.
        $created = get_string('notify_pq_created_short', 'local_personalcourse');
        $exists  = get_string('notify_pq_exists_short', 'local_personalcourse');
        $first   = get_string('notify_pq_not_created_first_short', 'local_personalcourse');
        $next    = get_string('notify_pq_not_created_next_short', 'local_personalcourse');

        $messages = json_encode([$created, $exists, $first, $next], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT);

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
        <?php
        $hook->add_html(ob_get_clean());
    }
}
