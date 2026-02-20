
file_path = 'classes/homework_manager.php'
with open(file_path, 'r') as f:
    content = f.read()

search_text = """        $fields = [
            'parent1name' => 'p1_name',
            'parent1email' => 'p1_email',
            'parent1phone' => 'p1_phone',
            'parent1lang' => 'p1_lang',
            'parent2name' => 'p2_name',
            'parent2email' => 'p2_email',
            'parent2phone' => 'p2_phone',
            'parent2lang' => 'p2_lang'
        ];"""

replace_text = """        $fields = [
            'parent1name' => 'p1_name',
            'parent1pmail' => 'p1_email',
            'parent1phone' => 'p1_phone',
            'P1_language' => 'p1_lang',
            'parent2name' => 'p2_name',
            'parent2email' => 'p2_email',
            'parent2phone' => 'p2_phone',
            'P2_language' => 'p2_lang'
        ];"""

if search_text in content:
    new_content = content.replace(search_text, replace_text)
    with open(file_path, 'w') as f:
        f.write(new_content)
    print("Successfully updated homework_manager.php")
else:
    print("Search text not found")
