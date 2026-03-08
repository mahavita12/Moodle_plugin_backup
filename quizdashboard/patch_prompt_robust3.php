<?php
$files = [
    '/home/master/applications/srfshmcmyg/public_html/local/quizdashboard/classes/essay_grader.php',
    '/home/master/applications/srfshmcmyg/public_html/local/quizdashboard/classes/resubmission_grader.php'
];

foreach ($files as $file) {
    if (!file_exists($file)) continue;

    $content = file_get_contents($file);

    // To be safe, we know the exact string has:
    // <br><span style=\"color:#3399cc;\">Improved: [corrected version in blue]</span>\n        </li>\n
    
    // We will do a generic regex replace for the end of each list item in the priorities block
    // Specifically looking for the closing span of 'Improved:' followed by </li>
    
    $search = '/(<span style=\\\\"color:#3399cc;\\\\">Improved: \\[corrected version in blue\\]<\\\\/span>\\\\n\\s*)(<\\\\/li>)/';
    $replace = "\$1<br><strong>IMPORTANT:</strong> You must supply EXACTLY TWO examples per priority. Format them exactly as shown above, on separate lines. If you cannot find two examples, provide the one you found or create a hypothetical relevant example. Do NOT supply more or less.\\n        \$2";

    $new_content_1 = preg_replace($search, $replace, $content);

    // And modify the original/improved lines to be wrapped in <ul><li>
    // Original prompt: <br><span style=\"color:#808080;\">Original: [student's mistake in grey]</span>\n<br><span style=\"color:#3399cc;\">Improved: [corrected version in blue]</span>
    
    $search2 = '/(<br><span style=\\\\"color:#808080;\\\\">Original: \\[' . preg_quote("student's mistake in grey", '/') . '\\]<\\\\/span>\\\\n\\s*<br><span style=\\\\"color:#3399cc;\\\\">Improved: \\[corrected version in blue\\]<\\\\/span>)/';
    $replace2 = "<ul>\\n        <li>\$1</li>\\n        <li>\$1</li>\\n        </ul>";

    $new_content_2 = preg_replace($search2, $replace2, $new_content_1);
    
    if ($new_content_2 !== $content) {
        file_put_contents($file, $new_content_2);
        echo "SUCCESS $file\\n";
    } else {
        echo "FAILED_TO_REPLACE $file\\n";
        echo "Regex match failed on search2 in $file\\n";
    }
}
