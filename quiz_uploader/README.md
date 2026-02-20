# Quiz Uploader Plugin

Upload Moodle XML question files and automatically create quiz activities via REST API.

## Features

- ✅ Import questions from Moodle XML format
- ✅ Auto-create question category hierarchy from XML
- ✅ Create quiz activities in any course section
- ✅ Duplicate detection (quiz names + question names)
- ✅ Customizable quiz settings
- ✅ Python script for automation
- ✅ Testing tools included

## Installation

### 1. Install Plugin

```bash
# Plugin is already in: local/quiz_uploader/
# Visit Site Administration → Notifications
```

### 2. Enable Web Services

1. Go to **Site Administration → Server → Web Services**
2. Enable web services
3. Enable REST protocol

### 3. Create Web Service

1. Go to **External Services**
2. Add new service: "Quiz Uploader"
3. Add these functions:
   - `local_quiz_uploader_import_quiz_from_xml`
   - `local_quiz_uploader_create_quiz_from_questions`

### 4. Create Token

1. Go to **Manage tokens**
2. Create token for your user
3. Select "Quiz Uploader" service
4. Copy the token

### 5. Configure Python Scripts

Edit `C:\MCP_servers\moodle_mcp_server\Moodle_Quiz_Uploader.py`:

```python
MOODLE_URL = "http://localhost"  # Your Moodle URL
MOODLE_TOKEN = "your_token_here"  # Your token
```

## Usage

### Python Script

```bash
# Basic usage
python Moodle_Quiz_Uploader.py <xml_file> <course_id> <section_id> <quiz_name>

# Example
python Moodle_Quiz_Uploader.py GMSR12.xml 2 16 "GMSR12 Quiz"

# Skip duplicate check
python Moodle_Quiz_Uploader.py GMSR12.xml 2 16 "GMSR12 Quiz" --no-check
```

### Testing Tools

```bash
# 1. Show course information and section IDs
python test_course_info.py
python test_course_info.py 2  # Specific course

# 2. Show question categories
python test_categories.py
python test_categories.py --course 2
python test_categories.py --system

# 3. Check for duplicates before importing
python test_duplicate_check.py GMSR12.xml 2 "GMSR12 Quiz"
```

## How It Works

### Workflow

1. **Generate XML** (using `Moodle_XML_Generator.py`)
   - Converts LearnDash XML to Moodle XML
   - Embeds images as base64
   - Creates category hierarchy

2. **Upload & Import** (using `Moodle_Quiz_Uploader.py`)
   - Uploads XML to draft area
   - Calls plugin to:
     - Extract category path from XML
     - Create category hierarchy if needed
     - Check for duplicates
     - Import questions to question bank
     - Create quiz activity
     - Add questions to quiz

3. **Result**
   - Quiz appears in course section
   - Questions in question bank
   - Direct URL to quiz

### Category Handling

The plugin auto-creates category hierarchies from XML:

```xml
<question type="category">
    <category>
        <text>$system$/top/System Category/English/GMSR12</text>
    </category>
</question>
```

Creates:
```
System (context)
└── top
    └── System Category
        └── English
            └── GMSR12  ← Questions imported here
```

## Web Service API

### Function: `local_quiz_uploader_import_quiz_from_xml`

Import quiz from XML in draft area.

**Parameters:**
- `courseid` (int): Course ID
- `sectionid` (int): Section database ID
- `draftitemid` (int): Draft area item ID
- `quizname` (string): Quiz name
- `checkduplicates` (int): 1=check, 0=skip (default: 1)
- `quizsettings` (JSON): Optional quiz settings

**Returns:**
```json
{
  "success": true,
  "quizid": 42,
  "cmid": 123,
  "quizurl": "http://moodle/mod/quiz/view.php?id=123",
  "questionsimported": 15,
  "questionids": "[101,102,103,...]",
  "categoryid": 5,
  "categoryname": "GMSR12"
}
```

**On Duplicate:**
```json
{
  "success": false,
  "error": "duplicate_detected",
  "message": "Duplicates found...",
  "duplicates": {
    "quiz_exists": true,
    "quiz_name": "GMSR12 Quiz",
    "questions_exist": ["Question 1", "Question 2"],
    "question_count": 2
  }
}
```

### Function: `local_quiz_uploader_create_quiz_from_questions`

Create quiz from existing question bank questions.

**Parameters:**
- `courseid` (int): Course ID
- `sectionid` (int): Section database ID
- `quizname` (string): Quiz name
- `questionids` (array): Question IDs to add
- `checkduplicates` (int): Check quiz name (default: 1)
- `quizsettings` (JSON): Optional settings

## Finding Section IDs

Section ID is the **database ID**, not the section number!

```bash
# Method 1: Use test tool
python test_course_info.py 2

# Output shows:
#   Section 1: Week 1
#     Section DB ID: 15

# Method 2: SQL query (via MCP)
SELECT id, course, section, name
FROM mdl_course_sections
WHERE course = 2
ORDER BY section;
```

## Quiz Settings

Customize quiz behavior via `quizsettings` parameter:

```python
quiz_settings = {
    "timeopen": 0,                    # Open time (timestamp)
    "timeclose": 0,                   # Close time (timestamp)
    "timelimit": 3600,                # Time limit (seconds)
    "attempts": 3,                    # Max attempts (0=unlimited)
    "grademethod": 1,                 # Grading method (1=highest)
    "questionsperpage": 1,            # Questions per page
    "shuffleanswers": 1,              # Shuffle answers
    "grade": 10                       # Maximum grade
}

uploader.import_quiz_from_xml(
    xml_file="quiz.xml",
    course_id=2,
    section_id=16,
    quiz_name="My Quiz",
    quiz_settings=quiz_settings
)
```

## Troubleshooting

### "No file found in draft area"
- Check draft item ID is valid
- Ensure file was uploaded successfully

### "Invalid XML file format"
- Validate XML with `Moodle_XML_Generator.py`
- Check XML has `<quiz>` root element

### "Could not create category"
- Check user has `moodle/question:add` capability
- Verify context exists (course/system)

### "Duplicate detected"
- Use different quiz name, OR
- Delete existing quiz, OR
- Run with `--no-check` flag

### Section ID not working
- Use **database ID** from `test_course_info.py`
- NOT the section number (0, 1, 2...)

## File Structure

```
local/quiz_uploader/
├── version.php                          # Plugin metadata
├── db/
│   ├── services.php                     # Web service definitions
│   └── access.php                       # Capabilities
├── classes/
│   ├── xml_parser.php                   # Parse XML files
│   ├── category_manager.php             # Manage categories
│   ├── duplicate_checker.php            # Check duplicates
│   ├── question_importer.php            # Import questions
│   ├── quiz_creator.php                 # Create quizzes
│   └── external/
│       ├── import_quiz_from_xml.php     # Main API function
│       └── create_quiz_from_questions.php
└── lang/en/
    └── local_quiz_uploader.php          # Language strings
```

## License

GPL v3 or later

## Support

For issues or questions, contact your system administrator.
