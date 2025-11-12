<?php
namespace local_quizdashboard\hook\output;

defined('MOODLE_INTERNAL') || die();

class before_footer_html_generation {
    /**
     * Hook callback for before footer HTML generation.
     * Handles two responsibilities:
     * 1. Load global navigation panel (all pages, admins only)
     * 2. Inject previous feedback summary (quiz attempt pages, resubmissions only)
     */
    public static function callback(\core\hook\output\before_footer_html_generation $hook): void {
        global $PAGE, $DB, $USER, $CFG;

        // FIRST: Load global navigation for site administrators (runs on ALL pages)
        if (empty($CFG->quizdashboard_disable_global_nav)) {
            $clean = isset($_GET['clean']) ? (int)$_GET['clean'] : 0;
            $printp = isset($_GET['print']) ? (int)$_GET['print'] : 0;
            if ($PAGE->pagelayout !== 'print' && $clean !== 1 && $printp !== 1) {
                if (is_siteadmin()) {
                    $PAGE->requires->js_call_amd('local_quizdashboard/global_nav', 'init');
                }
            }
        }

        // SECOND: Inject feedback summaries (only on quiz attempt pages)
        if ($PAGE->pagetype !== 'mod-quiz-attempt') {
            return;
        }

        // Find attempt id
        $attemptid = 0;
        if (isset($PAGE->url) && $PAGE->url->get_param('attempt')) {
            $attemptid = (int)$PAGE->url->get_param('attempt');
        } else if (optional_param('attempt', 0, PARAM_INT)) {
            $attemptid = optional_param('attempt', 0, PARAM_INT);
        } else if (isset($_GET['attempt'])) {
            $attemptid = (int)$_GET['attempt'];
        }
        if (!$attemptid) { return; }

        $attempt = $DB->get_record('quiz_attempts', ['id' => $attemptid]);
        if (!$attempt) { return; }

        // HOMEWORK CARD: allow for teachers/admins too (no owner check required)
        $quiz = $DB->get_record('quiz', ['id' => $attempt->quiz], 'id,name,course', \IGNORE_MISSING);
        if ($quiz) {
            $qname = (string)$quiz->name;
            $ishomework = (stripos($qname, 'homework') !== false) || preg_match('/^[A-Z]{1,3}\s*[-–—]\s*/u', $qname);
            if ($ishomework) {
                self::render_homework_examples_card_for_attempt($hook, $attempt);
                return;
            }
        }

        // ESSAY RESUBMISSION CARD: only for the attempt owner
        if ((int)$attempt->userid !== (int)$USER->id) { return; }

        // Determine submission number for this user+quiz (essay resubmission card)
        $count = $DB->count_records_select('quiz_attempts',
            'userid = ? AND quiz = ? AND timestart <= ? AND state IN (?, ?)',
            [$attempt->userid, $attempt->quiz, $attempt->timestart, 'finished', 'inprogress']
        );
        $submissionnum = max(1, (int)$count);
        if ($submissionnum < 2) { return; } // only resubmissions

        // Find immediate previous attempt
        $prev = $DB->get_record_sql(
            "SELECT id, timestart FROM {quiz_attempts}
             WHERE userid = ? AND quiz = ? AND timestart < ? AND state IN ('finished','inprogress')
             ORDER BY timestart DESC", [ $attempt->userid, $attempt->quiz, $attempt->timestart ]);
        if (!$prev) { return; }

        // Load previous grading (fallback to FIRST submission if previous is not graded)
        $fallbackfirst = false;
        $grading = $DB->get_record('local_quizdashboard_gradings', ['attempt_id' => $prev->id]);
        if (!$grading || empty($grading->feedback_html)) {
            // Find first submission for this user+quiz
            $first = $DB->get_record_sql(
                "SELECT id, timestart FROM {quiz_attempts}
                 WHERE userid = ? AND quiz = ?
                 ORDER BY timestart ASC LIMIT 1",
                [$attempt->userid, $attempt->quiz]
            );
            if ($first) {
                $firstgrading = $DB->get_record('local_quizdashboard_gradings', ['attempt_id' => $first->id]);
                if ($firstgrading && !empty($firstgrading->feedback_html)) {
                    $prev = $first; // use first submission as the source
                    $grading = $firstgrading;
                    $fallbackfirst = true;
                } else {
                    return; // nothing to render
                }
            } else {
                return; // nothing to render
            }
        }

        $html = (string)$grading->feedback_html;

        // Extract summaries using lightweight regex fallbacks
        $meta = [
            'score' => self::extract_final_score($grading, $html),
            'submitted' => userdate($prev->timestart)
        ];

        $items = [
            'Content and Ideas (25%)' => self::extract_improvement_items($html, 'Content\s+and\s+Ideas', 3),
            'Structure and Organization (25%)' => self::extract_improvement_items($html, 'Structure\s+and\s+Organi[sz]ation', 3),
            'Language Use (20%)' => self::extract_improvement_items($html, 'Language\s+Use', 3),
            'Creativity and Originality (20%)' => self::extract_improvement_items($html, 'Creativity\s+and\s+Originality', 3),
            'Mechanics (10%)' => self::extract_mechanics_items($html, 3)
        ];
        $relevance = self::extract_relevance_from_content($html);
        $overall = self::extract_overall_html($html);

        $ordinal = $fallbackfirst ? 'First' : self::ordinal_label($submissionnum - 1); // previous or first
        $card = self::render_card($prev->id, $ordinal, $meta, $items, $relevance, $overall);
        $hook->add_html($card);
    }

    private static function extract_between($html, $startRegex, $endRegex) {
        if (preg_match($startRegex, $html, $m, PREG_OFFSET_CAPTURE)) {
            $start = $m[0][1] + strlen($m[0][0]);
            if (preg_match($endRegex, $html, $n, PREG_OFFSET_CAPTURE, $start)) {
                return substr($html, $start, $n[0][1] - $start);
            }
        }
        return '';
    }

    private static function summarize_section($html, $titlePattern) : string {
        // Try strategic markers first
        $map = [
            'Content\s+and\s+Ideas' => 'CONTENT_IDEAS',
            'Structure\s+and\s+Organi[sz]ation' => 'STRUCTURE_ORG',
            'Language\s+Use' => 'LANGUAGE_USE',
            'Creativity\s+and\s+Originality' => 'CREATIVITY_ORIG',
            'Mechanics' => 'MECHANICS'
        ];
        foreach ($map as $title => $marker) {
            if (preg_match('/' . $title . '/i', $titlePattern)) {
                if (preg_match('/<!--\s*EXTRACT_' . $marker . '_START\s*-->(.*?)<!--\s*EXTRACT_' . $marker . '_END\s*-->/si', $html, $mm)) {
                    $segment = $mm[1];
                    if (preg_match('/<li><strong>Analysis of Changes:\\/*strong><\\/?[^>]*>(.*?)<\\/li>/si', $segment, $an)) {
                        return trim(strip_tags($an[1]));
                    }
                    return self::first_sentences(strip_tags($segment), 2);
                }
            }
        }
        // Fallback by heading
        if (preg_match('/<h2[^>]*>.*?' . $titlePattern . '.*?<\\/h2>(.*?)(?=<h2|$)/si', $html, $m)) {
            $segment = $m[1];
            if (preg_match('/<li><strong>Analysis of Changes[^<]*<\\/strong>:\s*<[^>]*>(.*?)<\\/li>/si', $segment, $an)) {
                return trim(strip_tags($an[1]));
            }
            return self::first_sentences(strip_tags($segment), 2);
        }
        return '';
    }

    private static function summarize_relevance($html) : string {
        // Look inside Content & Ideas first
        $sec = self::summarize_section($html, 'Content\s+and\s+Ideas');
        if (!empty($sec)) { return $sec; }
        // Fallback to overall
        return self::summarize_overall($html);
    }

    private static function extract_overall_html($html) : string {
        if (preg_match('/<div[^>]+id=["\']overall-comments["\'][^>]*>(.*?)<\\/div>/si', $html, $m)) {
            return trim($m[1]);
        }
        return '';
    }

    /**
     * Extract up to $limit items from Areas for Improvement in a section.
     */
    private static function extract_improvement_items($html, $titlePattern, $limit = 3) : array {
        $segment = '';
        // Strictly slice between this section header and the next h2
        if (preg_match('/<h2[^>]*>[^<]*' . $titlePattern . '[^<]*<\\/h2>/si', $html, $mh, PREG_OFFSET_CAPTURE)) {
            $start = $mh[0][1] + strlen($mh[0][0]);
            // Find next h2 after this start
            if (preg_match('/<h2[^>]*>/si', $html, $nx, PREG_OFFSET_CAPTURE, $start)) {
                $segment = substr($html, $start, $nx[0][1] - $start);
            } else {
                $segment = substr($html, $start);
            }
        }
        if ($segment === '') {
            // try markers
            $map = [
                'Content\\s+and\\s+Ideas' => 'CONTENT_IDEAS',
                'Structure\\s+and\\s+Organi[sz]ation' => 'STRUCTURE_ORG',
                'Language\\s+Use' => 'LANGUAGE_USE',
                'Creativity\\s+and\\s+Originality' => 'CREATIVITY_ORIG',
                'Mechanics' => 'MECHANICS'
            ];
            foreach ($map as $title => $marker) {
                if (preg_match('/' . $title . '/i', $titlePattern)) {
                    if (preg_match('/<!--\s*EXTRACT_' . $marker . '_START\s*-->(.*?)<!--\s*EXTRACT_' . $marker . '_END\s*-->/si', $html, $mm)) {
                        $segment = $mm[1];
                    }
                }
            }
        }
        $items = [];
        if ($segment) {
            // Find list after Areas for Improvement label
            if (preg_match('/Areas\s*for\s*Improvement[^:]*:/i', $segment, $ai, PREG_OFFSET_CAPTURE)) {
                $start = $ai[0][1] + strlen($ai[0][0]);
                if (preg_match('/<ul[^>]*>(.*?)<\\/ul>/si', $segment, $ul, 0, $start)) {
                    if (preg_match_all('/<li[^>]*>(.*?)<\\/li>/si', $ul[1], $lis)) {
                        foreach ($lis[1] as $li) {
                            $text = trim(preg_replace('/\s+/', ' ', strip_tags($li)));
                            if ($text !== '') { $items[] = $text; }
                            if (count($items) >= $limit) break;
                        }
                    }
                }
            }
        }
        return $items;
    }

    private static function extract_mechanics_items($html, $limit = 3) : array {
        $segment = '';

        // Prefer mechanics section by heading.
        if (preg_match('/<h2[^>]*>.*?Mechanics.*?<\/h2>(.*?)(?=<h2|$)/si', $html, $m)) {
            $segment = $m[1];
        }

        // Fallback to markers – these may wrap a larger chunk that still contains
        // the Mechanics heading, so trim down to the portion following that heading.
        if ($segment === '' && preg_match('/<!--\s*EXTRACT_MECHANICS_START\s*-->(.*?)<!--\s*EXTRACT_MECHANICS_END\s*-->/si', $html, $mm)) {
            $rawsegment = $mm[1];
            if (preg_match('/<h2[^>]*>.*?Mechanics.*?<\/h2>(.*)/si', $rawsegment, $inner)) {
                $segment = $inner[1];
            }
        }

        $items = [];
        if ($segment) {
            // Primary: list immediately after "Areas for Improvement" label in Mechanics.
            if (preg_match('/Areas\s*for\s*Improvement[^:]*:/i', $segment, $ai, PREG_OFFSET_CAPTURE)) {
                $start = $ai[0][1] + strlen($ai[0][0]);
                if (preg_match('/<ul[^>]*>(.*?)<\/ul>/si', $segment, $ul, 0, $start)) {
                    if (preg_match_all('/<li[^>]*>(.*?)<\/li>/si', $ul[1], $lis)) {
                        foreach ($lis[1] as $li) {
                            $text = trim(preg_replace('/\s+/', ' ', strip_tags($li)));
                            if ($text !== '') {
                                $items[] = $text;
                                if (count($items) >= $limit) {
                                    break;
                                }
                            }
                        }
                    }
                }
            }

            // Secondary: harvest mechanics-like bullets by keyword from any UL inside section.
            if (count($items) < $limit) {
                if (preg_match_all('/<li[^>]*>(.*?)<\/li>/si', $segment, $lis2)) {
                    foreach ($lis2[1] as $li) {
                        $plain = strtolower(trim(strip_tags($li)));
                        if (preg_match('/spelling|capital|punctuation|comma|proofread|tense|grammar/i', $plain)) {
                            $text = trim(preg_replace('/\s+/', ' ', strip_tags($li)));
                            if ($text !== '') {
                                $items[] = $text;
                                if (count($items) >= $limit) {
                                    break;
                                }
                            }
                        }
                    }
                }
            }
        }

        if (!empty($items)) {
            // Remove duplicates while preserving order.
            $items = array_values(array_unique($items));

            // Avoid repeating the same bullets as the Content section when markers misalign.
            $contentitems = self::extract_improvement_items($html, 'Content\\s+and\\s+Ideas', $limit);
            if (!empty($contentitems)) {
                $items = array_values(array_diff($items, $contentitems));
            }

            // Enforce limit after filtering.
            if (count($items) > $limit) {
                $items = array_slice($items, 0, $limit);
            }
        }

        // Final fallback: standard mechanics reminders.
        if (empty($items)) {
            $items = [
                'Check spelling carefully, especially common words',
                'Use capital letters for the pronoun "I" throughout',
                'Review punctuation, particularly commas in compound sentences'
            ];
        }

        return $items;
    }

    private static function extract_relevance_from_content($html) : string {
        // Look inside Content & Ideas block for a line beginning with Relevance to Question
        if (preg_match('/<h2[^>]*>.*?Content\s+and\s+Ideas.*?<\\/h2>(.*?)(?=<h2|$)/si', $html, $m)) {
            $segment = $m[1];
            if (preg_match('/Relevance\s*to\s*Question:\s*(.*?)<\/li>|Relevance\s*to\s*Question:\s*(.*?)(?:<br|<p|<ul)/si', $segment, $rm)) {
                $val = $rm[1] ?: $rm[2];
                return trim(strip_tags($val));
            }
        }
        return '';
    }

    /**
     * Extract full HTML lists for a criterion: Areas for Improvement and Examples.
     */
    private static function extract_section_lists($html, $titlePattern) : array {
        $segment = '';
        // Try strategic markers first based on title mapping
        $map = [
            'Content\\s+and\\s+Ideas' => 'CONTENT_IDEAS',
            'Structure\\s+and\\s+Organi[sz]ation' => 'STRUCTURE_ORG',
            'Language\\s+Use' => 'LANGUAGE_USE',
            'Creativity\\s+and\\s+Originality' => 'CREATIVITY_ORIG',
            'Mechanics' => 'MECHANICS'
        ];
        foreach ($map as $title => $marker) {
            if (preg_match('/' . $title . '/i', $titlePattern)) {
                if (preg_match('/<!--\s*EXTRACT_' . $marker . '_START\s*-->(.*?)<!--\s*EXTRACT_' . $marker . '_END\s*-->/si', $html, $mm)) {
                    $segment = $mm[1];
                }
            }
        }
        // Fallback: capture section content by heading
        if ($segment === '') {
            if (preg_match('/<h2[^>]*>.*?' . $titlePattern . '.*?<\\/h2>(.*?)(?=<h2|$)/si', $html, $m)) {
                $segment = $m[1];
            }
        }

        $improvements = '';
        $examples = '';
        if ($segment) {
            // Prefer labeled lists
            if (preg_match('/Areas\s*for\s*Improvement:?\s*<ul[^>]*>(.*?)<\\/ul>/si', $segment, $ai)) {
                $improvements = '<ul>' . $ai[1] . '</ul>';
            }
            if (preg_match('/Examples:?\s*<ul[^>]*>(.*?)<\\/ul>/si', $segment, $ex)) {
                $examples = '<ul>' . $ex[1] . '</ul>';
            }
            // Fallback: take first and second ULs in the section if labels missing
            if ($improvements === '' || $examples === '') {
                if (preg_match_all('/<ul[^>]*>(.*?)<\\/ul>/si', $segment, $alls)) {
                    if ($improvements === '' && !empty($alls[1][0])) {
                        $improvements = '<ul>' . $alls[1][0] . '</ul>';
                    }
                    if ($examples === '' && !empty($alls[1][1])) {
                        $examples = '<ul>' . $alls[1][1] . '</ul>';
                    }
                }
            }
        }
        return ['improvements' => $improvements, 'examples' => $examples];
    }

    private static function extract_improvement_bullets($html, $limit = 5) : array {
        $bullets = [];
        if (preg_match_all('/<li[^>]*>(.*?)<\\/li>/si', $html, $m)) {
            foreach ($m[1] as $item) {
                $line = trim(preg_replace('/\s+/', ' ', strip_tags($item)));
                if ($line !== '' && !preg_match('/Score|Final\s+Score/i', $line)) {
                    $bullets[] = $line;
                }
                if (count($bullets) >= $limit) break;
            }
        }
        return $bullets;
    }

    private static function first_sentences($text, $max = 2) : string {
        $text = trim(preg_replace('/\s+/', ' ', $text));
        $parts = preg_split('/(?<=[.!?])\s+/', $text);
        return trim(implode(' ', array_slice($parts, 0, $max)));
    }

    private static function extract_final_score($grading, $html) : string {
        if (!empty($grading->score_content_ideas)) {
            $total = (int)$grading->score_content_ideas + (int)$grading->score_structure_organization
                   + (int)$grading->score_language_use + (int)$grading->score_creativity_originality
                   + (int)$grading->score_mechanics;
            return $total . ' / 100';
        }
        if (preg_match('/Final\s+Score[^:]*:\s*(\d+)\s*\/\s*100/i', $html, $m)) {
            return ((int)$m[1]) . ' / 100';
        }
        return '';
    }

    private static function ordinal_label($n) : string {
        $map = [1=>'First',2=>'Second',3=>'Third',4=>'Fourth',5=>'Fifth'];
        return $map[$n] ?? ($n . 'th');
    }

    private static function render_card($previd, $ordinal, $meta, $items, $relevance, $overall) : string {
        $esc = function($s){ return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8'); };
        $sec = '';
        $mkBullets = function($title, $arr, $color, $relevanceText = '') use ($esc) {
            if (empty($arr) && $relevanceText === '') return '';
            $html = '<div class="qd-criteria__item" style="border-left-color:' . $color . ';">'
                 . '<h4 class="qd-criteria__title">' . $esc($title) . '</h4>';
            if ($relevanceText !== '') {
                $html .= '<div class="qd-criteria__body"><strong>Relevance to Question:</strong> ' . '<span style="color:#0b69c7;font-weight:700">' . $esc($relevanceText) . '</span>' . '</div>';
            }
            if (!empty($arr)) {
                $html .= '<div class="qd-criteria__body"><ul>';
                foreach ($arr as $b) { $html .= '<li>' . $esc($b) . '</li>'; }
                $html .= '</ul></div>';
            }
            $html .= '</div>';
            return $html;
        };
        $colors = ['#0b69c7','#0b69c7','#0b69c7','#0b69c7','#0b69c7'];
        $i = 0;
        foreach ($items as $title => $arr) {
            $rel = ($i === 0) ? ($relevance ?? '') : '';
            $sec .= $mkBullets($title, $arr, $colors[min($i,4)], $rel);
            $i++;
        }
        $overallhtml = '';
        if (!empty($overall)) {
            $overallhtml = '<div class="qd-criteria__item" style="border-left-color:#6f42c1;"><h4 class="qd-criteria__title">Overall Comments</h4>' . $overall . '</div>';
        }

        $score = $esc($meta['score'] ?? '');
        $submitted = $esc($meta['submitted'] ?? '');

        $html = '<div id="qd-prev-summary" class="qd-prev-summary" data-prev-attempt="' . (int)$previd . '">'
            . '<style>'
            . '.qd-prev-summary{margin:16px 0 20px;border:1px solid #d0d7de;border-left:4px solid #6f42c1;border-radius:6px;background:#fbfbfe;font-family:system-ui,-apple-system,Segoe UI,Roboto,Helvetica,Arial,"Apple Color Emoji","Segoe UI Emoji"}'
            . '.qd-prev-summary__header{display:flex;align-items:center;justify-content:space-between;gap:12px;padding:10px 12px;cursor:pointer}'
            . '.qd-prev-summary__title{display:flex;align-items:center;gap:8px;font-weight:600;color:#0b69c7;margin:0;font-size:16px}'
            . '.qd-prev-summary__chip{display:none}'
            . '.qd-prev-summary__toggle{background:transparent;border:1px solid #c8c8d0;border-radius:6px;color:#6f42c1;font-weight:700;font-size:14px;width:30px;height:30px;display:inline-flex;align-items:center;justify-content:center;padding:0;text-align:center}'
            . '.qd-prev-summary__body{display:none;padding:8px 12px 12px;border-top:1px dashed #e5e5e5}'
            . '.qd-prev-summary__meta{display:grid;grid-template-columns:1fr 1fr;gap:8px;margin:6px 0 10px}'
            . '.qd-prev-summary__meta-item{background:#f6f8fa;border:1px solid #e5e7eb;border-radius:6px;padding:8px 10px;font-size:12px;color:#444}'
            . '.qd-criteria{display:grid;grid-template-columns:1fr;gap:10px}'
            . '.qd-criteria__item{background:#fff;border:1px solid #e5e7eb;border-left:3px solid #0b69c7;border-radius:6px;padding:10px 12px}'
            . '.qd-criteria__title{margin:0 0 6px 0;font-weight:700;color:#0b69c7;font-size:14px}'
            . '.qd-criteria__body{margin:0;color:#2f2f2f;font-size:13px;line-height:1.4}'
            . '.qd-prev-summary__bullets{margin:10px 0 12px 18px;padding:0}'
            . '.qd-prev-summary__bullets li{margin:6px 0;line-height:1.35;color:#2f2f2f;font-size:14px}'
            . '.qd-prev-summary__footer{display:flex;align-items:center;justify-content:space-between;gap:10px;margin-top:8px}'
            . '.qd-prev-summary__link{font-size:13px;font-weight:700;text-decoration:none;display:inline-block;background:#0b69c7;color:#ffffff;padding:8px 12px;border-radius:6px;border:1px solid #0a5fb0}'
            . '.qd-prev-summary__link:hover{filter:brightness(0.95)}'
            . '@media print{.qd-prev-summary{page-break-inside:avoid}.qd-prev-summary__body{display:block!important}.qd-prev-summary__toggle{display:none}}'
            . '</style>'
            . '<div class="qd-prev-summary__header" role="button" aria-expanded="false" aria-controls="qd-prev-summary-body">'
            . '<h3 class="qd-prev-summary__title">Previous Feedback Summary - ' . $esc($ordinal) . ' Submission</h3>'
            . '<button class="qd-prev-summary__toggle" type="button" aria-label="Toggle summary">▾</button>'
            . '</div>'
            . '<div id="qd-prev-summary-body" class="qd-prev-summary__body" aria-hidden="true">'
            .   '<div class="qd-prev-summary__meta">'
            .     '<div class="qd-prev-summary__meta-item"><strong>Final Score:</strong> ' . $score . '</div>'
            .     '<div class="qd-prev-summary__meta-item"><strong>Submitted:</strong> ' . $submitted . '</div>'
            .   '</div>'
            .   $sec
            .   $overallhtml
            .   '<div class="qd-prev-summary__footer">'
            .     '<a class="qd-prev-summary__link" href="' . new \moodle_url('/local/quizdashboard/viewfeedback.php', ['clean'=>1,'id'=>$previd]) . '" target="_blank" rel="noopener">View full feedback from ' . $esc($ordinal) . ' Submission</a>'
            .   '</div>'
            . '</div>'
            . '<script>(function(){var h=document.querySelector("#qd-prev-summary .qd-prev-summary__header");if(!h)return;var b=document.getElementById("qd-prev-summary-body"),t=h.querySelector(".qd-prev-summary__toggle");function s(o){b.style.display=o?"block":"none";h.setAttribute("aria-expanded",String(o));b.setAttribute("aria-hidden",String(!o));if(t)t.textContent=o?"▴":"▾";}s(false);h.addEventListener("click",function(e){if(e.target&&(e.target===h||e.target===t||h.contains(e.target))){var x=h.getAttribute("aria-expanded")==="true";s(!x);}});})();</script>'
            . '</div>';
        return $html;
    }

    /**
     * HOMEWORK: Render examples card (Language Use / Mechanics) using the most recent essay feedback for this user.
     */
    private static function render_homework_examples_card_for_attempt($hook, $attempt): void {
        global $DB;
        try {
            // Find most recent graded essay attempt for this user
            $src = $DB->get_record_sql("
                SELECT qa.id AS attemptid, qa.timestart, q.name AS quizname, g.feedback_html
                FROM {quiz_attempts} qa
                JOIN {local_quizdashboard_gradings} g ON g.attempt_id = qa.id
                JOIN {quiz} q ON q.id = qa.quiz
                WHERE qa.userid = ? AND (g.feedback_html IS NOT NULL AND g.feedback_html <> '')
                ORDER BY qa.timestart DESC
                ", [$attempt->userid], \IGNORE_MISSING);
            if (!$src || empty($src->feedback_html)) { return; }

            $feedback = (string)$src->feedback_html;
            $essayname = (string)$src->quizname;
            $submitted = userdate((int)$src->timestart);

            // Extract Language Use Examples
            $lang = self::extract_section_lists($feedback, 'Language\s+Use');
            $langPairs = self::extract_original_improved_pairs($lang['examples'], 5);

            // Extract Mechanics Examples
            $mech = self::extract_section_lists($feedback, 'Mechanics');
            $mechPairs = self::extract_original_improved_pairs($mech['examples'], 5);

            // If nothing to show, do not render
            if (empty($langPairs) && empty($mechPairs)) { return; }

            $card = self::render_homework_examples_card((int)$src->attemptid, $essayname, $submitted, $langPairs, $mechPairs);
            if ($card !== '') {
                $hook->add_html($card);
            }
        } catch (\Throwable $e) {
            // Silent failure – card is optional
        }
    }

    private static function extract_original_improved_pairs(string $examplesHtml, int $limit = 5): array {
        $pairs = [];
        if (trim($examplesHtml) === '') { return $pairs; }

        // Prefer list items, but be resilient to nesting and extra spans.
        if (preg_match_all('/<li[^>]*>(.*?)<\/li>/si', $examplesHtml, $lis)) {
            foreach ($lis[1] as $liHtml) {
                // Convert to plain text to avoid brittle tag ordering assumptions.
                $plain = html_entity_decode(trim(preg_replace('/\s+/u', ' ', strip_tags($liHtml))), ENT_QUOTES, 'UTF-8');

                $orig = '';
                $impr = '';
                if (preg_match('/Original:\s*(.+?)(?:\s*(Improved:|$))/i', $plain, $m1)) {
                    $orig = trim($m1[1]);
                }
                if (preg_match('/Improved:\s*(.+)$/i', $plain, $m2)) {
                    $impr = trim($m2[1]);
                }

                // Clean quotes and trailing punctuation commonly present in examples.
                $clean = function ($s) {
                    $s = trim($s);
                    $s = trim($s, " \t\n\r\0\x0B\"'“”‘’");
                    return $s;
                };

                $orig = $clean($orig);
                $impr = $clean($impr);

                if ($orig !== '' && $impr !== '') {
                    $pairs[] = ['original' => $orig, 'improved' => $impr];
                    if (count($pairs) >= $limit) break;
                }
            }
        }

        // Fallback: try to extract sequentially from the whole block if no <li> found.
        if (empty($pairs)) {
            $plainBlock = html_entity_decode(trim(preg_replace('/\s+/u', ' ', strip_tags($examplesHtml))), ENT_QUOTES, 'UTF-8');
            if (preg_match_all('/Original:\s*(.+?)\s*Improved:\s*(.+?)(?=\s*Original:|$)/i', $plainBlock, $mm, PREG_SET_ORDER)) {
                foreach ($mm as $m) {
                    $o = trim($m[1], " \t\n\r\0\x0B\"'“”‘’");
                    $i = trim($m[2], " \t\n\r\0\x0B\"'“”‘’");
                    if ($o !== '' && $i !== '') {
                        $pairs[] = ['original' => $o, 'improved' => $i];
                        if (count($pairs) >= $limit) break;
                    }
                }
            }
        }

        return $pairs;
    }

    private static function render_homework_examples_card(int $essayattemptid, string $essayname, string $submitted, array $langPairs, array $mechPairs): string {
        $esc = function($s){ return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8'); };
        $mkPairs = function($title, $pairs, $color) use ($esc) {
            if (empty($pairs)) return '';
            $html = '<div class="qd-hwex__section"><h4 class="qd-hwex__title" style="color:'.$color.';">'.$esc($title).'</h4>';
            foreach ($pairs as $i => $p) {
                $html .= '<div class="qd-hwex__row">'
                       . '<div class="qd-hwex__label">Example '.($i+1).'</div>'
                       . '<div class="qd-hwex__orig"><strong>Original:</strong> '.$esc($p['original']).'</div>'
                       . '<div class="qd-hwex__impr"><strong>Improved:</strong> '.$esc($p['improved']).'</div>'
                       . '</div>';
            }
            $html .= '</div>';
            return $html;
        };

        $body = $mkPairs('Language Use – Examples from Your Essay', $langPairs, '#0b69c7')
              . $mkPairs('Mechanics – Examples from Your Essay', $mechPairs, '#dc2626');
        if ($body === '') return '';

        $html = '<div id="qd-hw-examples" class="qd-hwex">'
              . '<style>'
              . '.qd-hwex{margin:16px 0 20px;border:1px solid #d0d7de;border-left:4px solid #6f42c1;border-radius:6px;background:#fbfbfe;font-family:system-ui,-apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif}'
              . '.qd-hwex__header{display:flex;align-items:center;justify-content:space-between;gap:12px;padding:10px 12px;cursor:pointer}'
              . '.qd-hwex__titlebar{font-weight:600;color:#6f42c1;margin:0;font-size:16px}'
              . '.qd-hwex__toggle{background:transparent;border:1px solid #c8c8d0;border-radius:6px;color:#6f42c1;font-weight:700;font-size:14px;width:30px;height:30px;display:inline-flex;align-items:center;justify-content:center;padding:0;text-align:center}'
              . '.qd-hwex__body{display:none;padding:8px 12px 12px;border-top:1px dashed #e5e5e5}'
              . '.qd-hwex__meta{background:#f6f8fa;border:1px solid #e5e7eb;border-radius:6px;padding:8px 10px;font-size:12px;color:#444;margin-bottom:10px}'
              . '.qd-hwex__section{margin-bottom:14px}'
              . '.qd-hwex__title{margin:0 0 8px 0;font-weight:700;font-size:15px;border-bottom:2px solid #e5e7eb;padding-bottom:6px}'
              . '.qd-hwex__row{background:#fff;border:1px solid #e5e7eb;border-left:3px solid #0b69c7;border-radius:6px;padding:8px 10px;margin-bottom:8px}'
              . '.qd-hwex__label{font-weight:700;color:#555;font-size:12px;margin-bottom:4px}'
              . '.qd-hwex__orig{font-size:13px;color:#6b7280;margin-bottom:2px;line-height:1.5}'
              . '.qd-hwex__impr{font-size:13px;color:#059669;line-height:1.5}'
              . '@media print{.qd-hwex{page-break-inside:avoid}.qd-hwex__body{display:block!important}.qd-hwex__toggle{display:none}}'
              . '</style>'
              . '<div class="qd-hwex__header" role="button" aria-expanded="false" aria-controls="qd-hwex-body">'
              . '<h3 class="qd-hwex__titlebar">Previous Submission Feedback - '.$esc($essayname).'</h3>'
              . '<button class="qd-hwex__toggle" type="button" aria-label="Toggle">▾</button>'
              . '</div>'
              . '<div id="qd-hwex-body" class="qd-hwex__body" aria-hidden="true">'
              .   '<div class="qd-hwex__meta"><strong>Essay Submitted:</strong> '.$esc($submitted).' <span style="color:#6f42c1;font-weight:600;margin-left:8px;">Use these examples to answer the homework questions</span></div>'
              .    $body
              . '</div>'
              . '<script>(function(){var h=document.querySelector("#qd-hw-examples .qd-hwex__header");if(!h)return;var b=document.getElementById("qd-hwex-body"),t=h.querySelector(".qd-hwex__toggle");function s(o){b.style.display=o?"block":"none";h.setAttribute("aria-expanded",String(o));b.setAttribute("aria-hidden",String(!o));if(t)t.textContent=o?"▴":"▾";}s(false);h.addEventListener("click",function(e){if(e.target&&(e.target===h||e.target===t||h.contains(e.target))){var x=h.getAttribute("aria-expanded")==="true";s(!x);}});})();</script>'
              . '</div>';
        return $html;
    }
}


