<?php
$files = [
    __DIR__ . '/classes/essay_grader.php',
    __DIR__ . '/classes/resubmission_grader.php'
];

$find1 = <<<'EOD'
        <h2 style=\"font-size:18px;\">5. Mechanics (10%)</h2> 
        <p><strong>Score:</strong> X/10</p> 
        <ul> 
        <li><strong>Strengths:</strong><ul><li>Provide exactly one concise bullet summarising the main strength.</li></ul></li> 
        <li><strong>Areas for Improvement:</strong><ul><li>...</li></ul></li> 
        <li><strong>Examples:</strong><ul style=\"list-style-type:disc; padding-left:20px; margin-top:10px;\">
        <li><span style=\"color:#3399cc;\">- List specific grammar, punctuation, and spelling mistakes found in the essay with corrections. ALWAYS format corrections as:
        <br><span style=\"color:#808080;\">Original: [student's mistake in grey]</span>
        <br><span style=\"color:#3399cc;\">Improved: [corrected version in blue]</span>
        NON-NEGOTIABLE REQUIREMENT: The word 'Original:' and all student text following it MUST be in grey color (#808080). Each original and improved pair must be on separate lines.</span></li>
        <li><span style=\"color:#3399cc;\">- Include up to 5 examples (maximum 5) showing the original and improved version separately on different lines. Do not mention the limit in the output.</span></li></ul></li> 
        </ul> 

        <h2 style=\"font-size:18px;\">Overall Comments</h2> 
        <div id=\"overall-comments\"><p>Provide up to three short paragraphs (1–2 sentences each), concise and encouraging with concrete next steps.</p></div> 
EOD;

$replace1 = <<<'EOD'
        <h2 style=\"font-size:18px;\">5. Mechanics (10%)</h2> 
        <p><strong>Score:</strong> X/10</p> 
        <ul> 
        <li><strong>Strengths:</strong><ul><li>Provide exactly one concise bullet summarising the main strength.</li></ul></li> 
        <li><strong>Areas for Improvement:</strong><ul><li>...</li></ul></li> 
        <li><strong>Examples:</strong><ul style=\"list-style-type:disc; padding-left:20px; margin-top:10px;\">
        <li><span style=\"color:#3399cc;\">- List specific grammar, punctuation, and spelling mistakes found in the essay with corrections. ALWAYS format corrections as:
        <br><span style=\"color:#808080;\">Original: [student's mistake in grey]</span>
        <br><span style=\"color:#3399cc;\">Improved: [corrected version in blue]</span>
        NON-NEGOTIABLE REQUIREMENT: The word 'Original:' and all student text following it MUST be in grey color (#808080). Each original and improved pair must be on separate lines.</span></li>
        <li><span style=\"color:#3399cc;\">- Include up to 5 examples (maximum 5) showing the original and improved version separately on different lines. Do not mention the limit in the output.</span></li></ul></li> 
        </ul> 

        <h2 style=\"font-size:18px;\">Top 4 Priorities for Improvement</h2>
        <ul>
        <li><strong>[Name of Priority 1]:</strong> Provide a 1-2 sentence explanation of why this is a priority. Then, provide exactly TWO distinct examples of this issue from the student's essay, formatted like this:
        <ul>
        <li><span style=\"color:#808080;\">Original: [student's mistake in grey]</span></li>
        <li><span style=\"color:#3399cc;\">Improved: [corrected version in blue]</span></li>
        </ul>
        <br><strong>IMPORTANT:</strong> You must supply EXACTLY TWO examples per priority. If you cannot find two, provide one or a hypothetical one. Do NOT supply more or less. Each example MUST be inside an `<li>` tag as shown.
        </li>
        <li><strong>[Name of Priority 2]:</strong> (same format)</li>
        <li><strong>[Name of Priority 3]:</strong> (same format)</li>
        <li><strong>[Name of Priority 4]:</strong> (same format)</li>
        </ul>

        <h2 style=\"font-size:18px;\">Overall Comments</h2> 
        <div id=\"overall-comments\"><p>Provide up to three short paragraphs (1–2 sentences each), concise and encouraging with concrete next steps.</p></div> 
EOD;

$find2 = <<<'EOD'
        $json = [
            'scores' => $scores,
            'sections' => [
                'content_and_ideas' => [
EOD;

$replace2 = <<<'EOD'
        $priorities = [];
        $priorities_html = $section('Top\s+4\s+Priorities\s+for\s+Improvement');
        if (preg_match_all('/<li[^>]*>\s*<strong>(?:[\*\[])?(.*?)(?:\][\*])?:<\/strong>\s*(.*?)(?=<ul|<\/li>)/is', $priorities_html, $pm, PREG_SET_ORDER)) {
            foreach ($pm as $m) {
                $p_name = trim(strip_tags($m[1]));
                $p_exp = trim(strip_tags($m[2]));
                
                $examples = [];
                $offset = strpos($priorities_html, $m[0]) + strlen($m[0]);
                if (preg_match('/<ul[^>]*>(.*?)<\/ul>/si', $priorities_html, $ulmatch, 0, $offset)) {
                    if (preg_match_all('/<li[^>]*>(?:.*?)Original:\s*(.*?)(?=<\/li>|\n)/is', $ulmatch[1], $mo, PREG_SET_ORDER) && 
                        preg_match_all('/<li[^>]*>(?:.*?)Improved:\s*(.*?)(?=<\/li>|\n)/is', $ulmatch[1], $mi, PREG_SET_ORDER)) {
                        for ($i = 0; $i < count($mo); $i++) {
                            if (isset($mi[$i])) {
                                $examples[] = ['original' => trim(strip_tags($mo[$i][1])), 'improved' => trim(strip_tags($mi[$i][1]))];
                            }
                        }
                    } else if (preg_match_all('/Original:\s*(.+?)\s*Improved:\s*(.+?)(?=<li|$)/is', strip_tags($ulmatch[1]), $mm, PREG_SET_ORDER)) {
                        foreach ($mm as $ex) {
                            $examples[] = ['original' => trim($ex[1]), 'improved' => trim($ex[2])];
                        }
                    }
                }
                
                $priorities[] = [
                    'name' => $p_name,
                    'explanation' => $p_exp,
                    'examples' => $examples
                ];
            }
        }

        preg_match('/<div id=["\']overall-comments["\'].*?>(.*?)<\/div>/si', $feedbackHtml, $matches);
        $overall_comments = strip_tags(trim($matches[1] ?? ''));

        $json = [
            'top_priorities' => $priorities,
            'overall_comments' => $overall_comments,
            'scores' => $scores,
            'sections' => [
                'content_and_ideas' => [
EOD;

foreach ($files as $file) {
    if (!file_exists($file)) continue;
    $content = file_get_contents($file);
    
    // Direct string replacement for prompt
    $new_content = str_replace($find1, $replace1, $content);
    if ($new_content === $content) {
        echo "FAILED_FIND1_IN $file\n";
    }
    
    // Direct string replacement for json parsing
    $new_content2 = str_replace($find2, $replace2, $new_content);
    if ($new_content2 === $new_content) {
        echo "FAILED_FIND2_IN $file\n";
    }
    
    if ($new_content2 !== $content) {
        copy($file, $file . '.bak.' . time());
        file_put_contents($file, $new_content2);
        echo "SUCCESS $file\n";
    } else {
        echo "UNCHANGED $file\n";
    }
}
