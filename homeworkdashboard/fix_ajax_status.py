
file_path = 'ajax_send_report.php'
with open(file_path, 'r') as f:
    content = f.read()

search_text = """        // Determine status based on snapshot status
        $status_label = 'No attempt';
        if (isset($r->status)) {
            if ($r->status === 'completed') {
                $status_label = 'Completed';
            } elseif ($r->status === 'lowgrade') {
                $status_label = 'Low grade';
            }
        }"""

replace_text = """        // Pass status directly (it is already 'Completed', 'Low grade', or 'No attempt')
        $status_label = $r->status ?? 'No attempt';"""

if search_text in content:
    new_content = content.replace(search_text, replace_text)
    with open(file_path, 'w') as f:
        f.write(new_content)
    print("Successfully updated ajax_send_report.php")
else:
    print("Search text not found")
