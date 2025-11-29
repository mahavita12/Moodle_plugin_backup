
file_path = 'ajax_email_report.php'
with open(file_path, 'r') as f:
    content = f.read()

search_text = """    // Refined logic:
    if ($lang === 'ko' && $report_ko) {
        $report_to_send = $report_ko;
    } else {
        $report_to_send = $report_en;
    }"""

replace_text = """    // Refined logic:
    if (($lang === 'ko' || $lang === 'Korean') && $report_ko) {
        $report_to_send = $report_ko;
    } else {
        $report_to_send = $report_en;
    }"""

if search_text in content:
    new_content = content.replace(search_text, replace_text)
    with open(file_path, 'w') as f:
        f.write(new_content)
    print("Successfully updated ajax_email_report.php")
else:
    print("Search text not found")
