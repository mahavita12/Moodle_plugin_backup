<?php
$files = [
    '/home/master/applications/srfshmcmyg/public_html/local/quizdashboard/classes/essay_grader.php',
    '/home/master/applications/srfshmcmyg/public_html/local/quizdashboard/classes/resubmission_grader.php'
];

foreach ($files as $file) {
    if (!file_exists($file)) continue;

    $content = file_get_contents($file);

    // To be safe, we know the exact string has:
    // <br><span style=\"color:#3399cc;\">Improved: [corrected version in blue]</span>\n        </li>\n        <li><strong>[Name of Priority 2]
    
    // We will do a generic regex replace for the end of each list item in the priorities block
    // Specifically looking for the closing span of 'Improved:' followed by </li>
    
    $search = '/(<span style=\\\\"color:#3399cc;\\\\">Improved: \\[corrected version in blue\\]<\\\\/span>\\\\n\\s*)(<\\\\/li>)/';
    $replace = "$1<br><strong>IMPORTANT:</strong> You must supply EXACTLY TWO examples per priority. Format them exactly as shown above, on separate lines. If you cannot find two examples, provide the one you found or create a hypothetical relevant example. Do NOT supply more or less. Each example MUST be wrapped in an `<li>` tag inside a `<ul>` list.$2";

    $new_content_1 = preg_replace($search, $replace, $content);

    // Now we also need to enforce the `<ul>` structure in the prompt itself, since right now the prompt examples don't show `<ul>`
    $search2 = '/(<strong>\\[Name of Priority \\d\\]:<\\\\/strong> Provide a 1-2 sentence explanation of why this is a priority\\. Then, provide exactly TWO distinct examples of this issue from the student\\'s essay, formatted like this:\\\\n\\s*<br><span style=\\\\"color:#808080;\\\\">Original:)/si';
    $replace2 = "$1";
    // Actually, modifying the prompt to include `<ul>` and `<li>` explicitly is safer.
    
    $full_block_search = '/(<li><strong>\\[Name of Priority \\d\\]:<\\\\/strong> Provide a 1-2 sentence explanation of why this is a priority\\. Then, provide exactly TWO distinct examples of this issue from the student\\'s essay, formatted like this:\\\\n\\s*)(<br><span style=\\\\"color:#808080;\\\\">Original:.*?)(<\\\\/li>)/si';
    
    $full_block_replace = "$1<ul>\\n        <li>$2</li>\\n        <li>$2</li>\\n        </ul>\\n        <br><strong>IMPORTANT:</strong> You must supply EXACTLY TWO examples per priority. If you cannot find two, provide one or a hypothetical one. Do NOT supply more or less. Each example MUST be inside an `<li>` tag as shown.\\n        $3";

    $new_content_2 = preg_replace($full_block_search, $full_block_replace, $content);
    
    if ($new_content_2 !== $content) {
        file_put_contents($file, $new_content_2);
        echo "SUCCESS $file\\n";
    } else {
        echo "FAILED_TO_REPLACE $file\\n";
    }
}
