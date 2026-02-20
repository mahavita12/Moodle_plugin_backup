# Personal Course Extension - FINAL Planning Document

**Date**: 2025-10-26
**Status**: ✅ FINALIZED - Ready for Implementation
**Author**: Planning Session with Claude Code
**Purpose**: Extend Question Flags plugin to create personalized review courses for students

---

## 🎯 Executive Summary

This plugin creates **one personalized review course per student** containing all flagged questions (blue/red) and incorrect answers from public courses. The system uses **dynamic linking** to question bank, **flag-based synchronization**, and **smart generation triggers** to minimize confusion and maximize learning value.

**Key Innovation**: Questions update based on **flag status only** (not correct/incorrect), with generation triggered at specific grade thresholds to ensure worthwhile review content.

---

## Table of Contents

1. [Final Architectural Decisions](#final-architectural-decisions)
2. [System Overview](#system-overview)
3. [Technical Architecture](#technical-architecture)
4. [Database Schema](#database-schema)
5. [Core Workflows](#core-workflows)
6. [Implementation Phases](#implementation-phases)
7. [Class Structure](#class-structure)
8. [User Interface](#user-interface)
9. [Testing Strategy](#testing-strategy)
10. [Appendix](#appendix)

---

## Final Architectural Decisions

### ✅ **CONFIRMED DECISIONS** (All Questions Answered)

#### **1. Course Structure: Meta-Course (Flat Sections)**

**Decision**: ONE personal course per student, flat section structure

**Structure**:
```
[PERSONAL] Review - John Smith (12345)
├── Section: 5A-Writing (Year 5A Classroom)
├── Section: 5A-English (Year 5A Classroom)
├── Section: 5A-Math (Year 5A Classroom)
├── Section: ST-Reading (Selective Trial Test)
├── Section: ST-Math (Selective Trial Test)
├── Section: ST-Thinking (Selective Trial Test)
├── Section: OT-Reading (OC Trial Test)
├── Section: OT-Math (OC Trial Test)
└── Section: OT-Thinking (OC Trial Test)
```

**Rationale**:
- Each section already contains course name in prefix (5A-, ST-, OT-)
- Simpler, flatter structure
- Easier navigation
- Matches existing public course structure

---

#### **2. Generation & Update Triggers: Smart Threshold System**

**Decision**: Dynamic generation based on attempt number and grade thresholds

**Rules**:
```
Attempt 1 → NO generation (regardless of grade)
Attempt 2 → IF grade ≥30% THEN generate personal course
Attempt 2 → IF grade <30% THEN no generation
Attempt 3+ → IF grade <70% THEN no update
Attempt 3+ → IF grade ≥70% THEN refresh personal course
```

**Philosophy**:
- Don't generate from first attempt (student learning)
- Only generate if student shows minimum understanding (≥30%)
- Minimize regeneration unless significant improvement (≥70%)
- Focus on worthwhile review content

**Example Timeline**:
```
Student John - Quiz "5A-Math-01"

Attempt 1 → Grade 25% → ❌ No generation (first attempt)
Attempt 2 → Grade 35% → ✅ Generate (≥30%, worthwhile to review)
Attempt 3 → Grade 45% → ❌ No update (<70%, not enough improvement)
Attempt 4 → Grade 75% → ✅ Refresh (≥70%, significant improvement)
Attempt 5 → Grade 80% → ✅ Refresh (≥70%, continued improvement)
```

---

#### **3. Question Synchronization: Flag-Based Only**

**Decision**: Questions update based on **flag status ONLY**, not correct/incorrect

**Critical Rule**:
> **Even if student answers correctly, if flag not manually removed, question remains in personal course**

**Sync Triggers**:
1. **Public Course**: When student clicks "Finish review" after attempt
2. **Personal Course**: After each personal course attempt (flags change dynamically)

**Flag Management**:
- **Blue flags**: Manual OR auto-assigned to incorrect answers
- **Red flags**: Manual only (higher priority)
- **Flag colors**: Retained from public course
- **Flag removal**: Manual only (student removes flag → question drops)

**Unification Rule**:
- On initial generation/regeneration, any incorrect (auto) answers without an existing manual flag will be persisted as a blue flag in `local_questionflags` for that `userid+questionid`. From that point forward, manual and auto-inferred items are treated identically (pure flag-based sync; removal is manual by the student).

**Example**:
```
Personal Course Quiz: 5A-Math-01
Questions: Q2 (blue), Q5 (red), Q7 (blue)

Student attempts personal course:
├── Q2: Answered correctly ✓
│   └── Flag status: STILL blue (not removed)
│   └── Result: Q2 REMAINS in quiz
├── Q5: Answered correctly ✓
│   └── Flag status: STILL red (not removed)
│   └── Result: Q5 REMAINS in quiz
└── Q7: Answered correctly ✓ AND student removes blue flag
    └── Flag status: No flag
    └── Result: Q7 DROPPED from quiz after attempt

Next time student accesses personal quiz: Q2 and Q5 still there, Q7 gone
```

---

#### **4. Question Numbering: Sequential Renumbering**

**Decision**: Option B - Renumber questions sequentially for simplicity

**Example**:
```
Public Quiz: 5A-Math-01 (Original)
├── Q1: Algebra (correct) ✓
├── Q2: Geometry (wrong, blue flag) 🔵
├── Q3: Fractions (correct) ✓
├── Q4: Decimals (wrong, blue flag) 🔵
└── Q5: Word problems (red flag) 🔴

Personal Quiz: 5A-Math-01 (Generated)
├── Q1: Geometry (originally Q2) 🔵
├── Q2: Decimals (originally Q4) 🔵
└── Q3: Word problems (originally Q5) 🔴
```

**Rationale**:
- Simpler for students
- Avoid confusion with gaps
- Cleaner presentation

---

#### **5. Dashboard Organization: Public First, Personal Alphabetical**

**Decision**: Sort courses with public first, then personal by student name/ID

**My Courses Display**:
```
My Courses (Teacher View)
├── Central Question Bank (public)
├── OC Trial Test (public)
├── Selective Trial Test (public)
├── Year 3-4 Classroom (public)
├── Year 5A Classroom (public)
├── Year 5B Classroom (public)
├── ─────────────────────────────
├── [PERSONAL] Review - Amy Chen (10001)
├── [PERSONAL] Review - Ben Lee (10003)
├── [PERSONAL] Review - John Smith (10025)
└── [PERSONAL] Review - Sarah Wong (10048)
```

**Filters Available**:
- By public course source
- By student name/ID
- By flag count
- By last updated

---

#### **6. Course Name Extraction: Prefix-Based**

**Decision**: Use the course short name as the prefix (e.g., `5A`, `ST`, `OT`)

**Pattern**: `{Prefix}-{Subject}-{Title} ({Code})`

**Examples**:
```
5A-Writing-Should all students learn coding? (WRIT01)
├── Prefix: 5A
├── Subject: Writing
├── Course: Year 5A Classroom
└── Section in personal course: "5A-Writing (Year 5A Classroom)"

ST-Math-33 (NSSM00)
├── Prefix: ST
├── Subject: Math
├── Course: Selective Trial Test
└── Section in personal course: "ST-Math (Selective Trial Test)"
```

**Extraction Logic**:
```php
// Use course shortname directly as prefix
$prefix = $course->shortname; // e.g., "5A", "ST", "OT"
$course_name = $course->fullname; // e.g., "Year 5A Classroom"
// Section name in personal course
$section_name = "{$prefix}-{$subject} ({$course_name})";
```

---

#### **7. Lifecycle Management: Manual Deletion Only**

**Decision**: No auto-archive, manual deletion only

**Rationale**:
- Teacher controls when to delete
- Students may need extended review time
- Safer to keep than accidentally remove

**Admin Actions Available**:
- Manual deletion
- Manual archiving
- Bulk selection for deletion

---

#### **8. Student Permissions: View & Attempt Only**

**Decision**: Students can view and attempt, but not generate/regenerate

**Student Capabilities**:
- ✅ **View** personal course
- ✅ **Attempt** quizzes in personal course
- ✅ **Remove flags** (triggers question removal)
- ❌ **Generate** personal course (admin/teacher only)
- ❌ **Regenerate** personal course (admin/teacher only)
- ❌ **Delete** personal course (admin/teacher only)
- ❌ **Request regeneration** (not needed - automatic at thresholds)

---

#### **9. Bulk Operations: Full Admin Control**

**Decision**: Comprehensive bulk operations for efficiency

**Available Bulk Actions**:
```
Select: [☑ John Smith] [☑ Sarah Wong] [☑ Tom Lee]

Actions:
├── Apply Settings Template (essay/non-essay)
├── Force Regenerate All Quizzes
├── Force Sync Flags Now
├── Archive Selected Courses
├── Delete Selected Courses
├── Enroll Additional Teacher
└── Export Progress Reports
```

---

#### **10. Notifications: None Required**

**Decision**: No automated notifications

**Rationale**:
- Teacher manages generation manually
- Students see course in dashboard when created
- Flag changes are student-initiated (they know)

---

#### **11. Scale: 50 Students (Expandable)**

**Current Scope**:
- 50 students
- ~3-5 courses per student
- ~10-30 flagged questions per student

**Architecture**: Designed for growth
- Can scale to 500+ students
- Performance optimizations built-in
- Efficient database indexing

---

#### **12. Essay Homework: Deferred to Phase 5**

**Decision**: Focus on core functionality first, add homework later

**Phase 5 will add**:
- Homework extraction from essay grader feedback
- Injection into essay-type quizzes
- Section structure for homework

---

## System Overview

### **What This Plugin Does**

1. **Aggregates Learning Gaps**
   - Collects all flagged questions (blue/red)
   - Identifies incorrect answers (auto-blue flags)
   - Groups by source course and quiz

2. **Creates Personal Course**
   - One course per student
   - Flat section structure mirroring public courses
   - Dynamic question linking (not duplication)

3. **Maintains Sync**
   - Flag removal → question drops
   - Public course attempts → regenerate at thresholds
   - Personal course attempts → update based on flags

4. **Provides Management Dashboard**
   - Teacher view: All personal courses
   - Student view: Own course only
   - Bulk operations for efficiency

---

### **User Roles & Workflows**

#### **Student Workflow**

```
1. Student attempts public quiz "5A-Math-01" (Attempt 2)
   ├── Gets Q2, Q5, Q7 wrong
   ├── Manually flags Q3 as blue
   └── Manually flags Q8 as red

2. Finishes quiz with 35% grade (≥30%)
   └── ✅ Personal course generates automatically

3. Personal course appears in "My Courses"
   └── Section: 5A-Math
       └── Quiz: 5A-Math-01 (5 questions: Q2, Q3, Q5, Q7, Q8)

4. Student practices in personal course
   ├── Attempts Q2, gets it right
   ├── Removes blue flag from Q2
   └── Still struggles with Q5 (keeps flag)

5. Next access to personal course
   └── Quiz now has 4 questions (Q2 removed, Q3, Q5, Q7, Q8 remain)

6. Student retakes public quiz (Attempt 4)
   └── Gets 75% (≥70%)
   └── ✅ Personal course refreshes with latest flags
```

---

#### **Teacher Workflow**

```
1. Teacher accesses Personal Course Dashboard

2. Sees list of all students:
   ├── John Smith: 25 flagged questions
   ├── Sarah Wong: 18 flagged questions
   └── Tom Lee: 32 flagged questions

3. Bulk actions:
   ├── Selects multiple students
   ├── Applies essay/non-essay settings templates
   └── Forces regeneration for selected students

4. Views individual progress:
   ├── Question breakdown per student
   ├── Flag colors (blue/red)
   ├── Last updated timestamp
   └── Export report

5. Manual overrides:
   ├── Force regenerate for specific student
   ├── Delete personal course if needed
   └── Adjust settings per student
```

---

## Technical Architecture

### **Core Components**

```
┌─────────────────────────────────────────────┐
│         Personal Course Plugin              │
├─────────────────────────────────────────────┤
│                                             │
│  ┌─────────────────────────────────────┐  │
│  │   Course Generator                   │  │
│  │   - Creates Moodle course            │  │
│  │   - Sets up sections                 │  │
│  │   - Enrolls users                    │  │
│  └─────────────────────────────────────┘  │
│                                             │
│  ┌─────────────────────────────────────┐  │
│  │   Flag Aggregator                    │  │
│  │   - Queries question flags           │  │
│  │   - Identifies incorrect answers     │  │
│  │   - Groups by quiz                   │  │
│  └─────────────────────────────────────┘  │
│                                             │
│  ┌─────────────────────────────────────┐  │
│  │   Quiz Builder                       │  │
│  │   - Delegates to `local_quiz_uploader\quiz_creator` to create quizzes  │  │
│  │   - Adds/removes questions via `quiz_add_quiz_question()` and mod_quiz  │  │
│  │     structure APIs (System Question Bank only)                          │  │
│  │   - Applies settings templates (non-essay uses quiz_uploader defaults)  │  │
│  └─────────────────────────────────────┘  │
│                                             │
│  ┌─────────────────────────────────────┐  │
│  │   Sync Manager                       │  │
│  │   - Listens to flag events           │  │
│  │   - Updates quiz questions           │  │
│  │   - Checks grade thresholds          │  │
│  └─────────────────────────────────────┘  │
│                                             │
│  ┌─────────────────────────────────────┐  │
│  │   Dashboard Controller               │  │
│  │   - Admin interface                  │  │
│  │   - Bulk operations                  │  │
│  │   - Progress reports                 │  │
│  └─────────────────────────────────────┘  │
│                                             │
└─────────────────────────────────────────────┘
           │                    │
           ▼                    ▼
  ┌─────────────────┐  ┌─────────────────┐
  │ Question Flags  │  │ Quiz Dashboard  │
  │ Plugin          │  │ Plugin          │
  └─────────────────┘  └─────────────────┘
```

---

## Database Schema

### **Tables Overview**

```sql
-- 1. Personal course tracking
mdl_local_personalcourse_courses

-- 2. Quiz mapping (personal ← source)
mdl_local_personalcourse_quizzes

-- 3. Question tracking within quizzes
mdl_local_personalcourse_questions

-- 4. Generation history/log
mdl_local_personalcourse_generations

-- 5. Settings templates
mdl_local_personalcourse_templates

-- 6. Attempt tracking for thresholds
mdl_local_personalcourse_attempts
```

---

### **Detailed Schema**

```sql
-- ===================================================================
-- Personal Course Tracking
-- ===================================================================
CREATE TABLE mdl_local_personalcourse_courses (
    id BIGINT(10) NOT NULL AUTO_INCREMENT,
    userid BIGINT(10) NOT NULL,              -- Student ID (FK to mdl_user)
    courseid BIGINT(10) NOT NULL,             -- Moodle course ID (FK to mdl_course)
    status VARCHAR(20) DEFAULT 'active',      -- 'active', 'archived'
    timecreated BIGINT(10) NOT NULL,
    timemodified BIGINT(10) NOT NULL,
    timeaccessed BIGINT(10) DEFAULT NULL,     -- Last student access
    PRIMARY KEY (id),
    UNIQUE KEY userid_unique (userid),        -- One course per student
    KEY userid (userid),
    KEY courseid (courseid),
    KEY status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='Personal review courses';

-- ===================================================================
-- Quiz Mapping (Personal Quiz ← Source Quiz)
-- ===================================================================
CREATE TABLE mdl_local_personalcourse_quizzes (
    id BIGINT(10) NOT NULL AUTO_INCREMENT,
    personalcourseid BIGINT(10) NOT NULL,     -- FK to personalcourse_courses
    quizid BIGINT(10) NOT NULL,               -- Personal quiz ID (FK to mdl_quiz)
    sourcequizid BIGINT(10) NOT NULL,         -- Source public quiz ID (FK to mdl_quiz)
    sectionname VARCHAR(255) NOT NULL,        -- Section name (e.g., "5A-Math")
    quiztype VARCHAR(20) NOT NULL,            -- 'essay' or 'non_essay'
    questioncount INT DEFAULT 0,              -- Current question count
    timecreated BIGINT(10) NOT NULL,
    timemodified BIGINT(10) NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY personalcourse_sourcequiz (personalcourseid, sourcequizid),
    KEY personalcourseid (personalcourseid),
    KEY quizid (quizid),
    KEY sourcequizid (sourcequizid)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='Quiz mapping between personal and source';

-- ===================================================================
-- Question Tracking (Which Questions in Which Quiz)
-- ===================================================================
CREATE TABLE mdl_local_personalcourse_questions (
    id BIGINT(10) NOT NULL AUTO_INCREMENT,
    personalcourseid BIGINT(10) NOT NULL,       -- FK to personalcourse_courses
    personalquizid BIGINT(10) NOT NULL,       -- FK to personalcourse_quizzes
    questionid BIGINT(10) NOT NULL,           -- Question ID (FK to mdl_question)
    slotid BIGINT(10) DEFAULT NULL,           -- Quiz slot ID (FK to mdl_quiz_slots)
    flagcolor VARCHAR(10) DEFAULT NULL,       -- 'blue', 'red'
    source VARCHAR(20) NOT NULL,              -- 'manual_flag' or 'auto_incorrect'
    originalposition INT DEFAULT NULL,        -- Original question number in source quiz
    currentposition INT DEFAULT NULL,         -- Current position in personal quiz
    timecreated BIGINT(10) NOT NULL,
    timemodified BIGINT(10) NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY personalcourse_question (personalcourseid, questionid),
    KEY personalquizid (personalquizid),
    KEY personalcourseid (personalcourseid),
    KEY questionid (questionid),
    KEY flagcolor (flagcolor)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='Questions in personal quizzes';

-- ===================================================================
-- Generation Log (Track Generation/Regeneration Events)
-- ===================================================================
CREATE TABLE mdl_local_personalcourse_generations (
    id BIGINT(10) NOT NULL AUTO_INCREMENT,
    personalcourseid BIGINT(10) NOT NULL,     -- FK to personalcourse_courses
    triggertype VARCHAR(50) NOT NULL,         -- 'initial', 'threshold_30', 'threshold_70', 'manual', 'flag_sync'
    triggerdetails TEXT DEFAULT NULL,         -- JSON: attempt number, grade, quiz IDs
    questionsadded INT DEFAULT 0,
    questionsremoved INT DEFAULT 0,
    quizzesadded INT DEFAULT 0,
    quizzesremoved INT DEFAULT 0,
    timecreated BIGINT(10) NOT NULL,
    PRIMARY KEY (id),
    KEY personalcourseid (personalcourseid),
    KEY triggertype (triggertype),
    KEY timecreated (timecreated)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='Generation history log';

-- ===================================================================
-- Settings Templates
-- ===================================================================
CREATE TABLE mdl_local_personalcourse_templates (
    id BIGINT(10) NOT NULL AUTO_INCREMENT,
    name VARCHAR(255) NOT NULL,
    quiztype VARCHAR(20) NOT NULL,            -- 'essay' or 'non_essay'
    settings TEXT NOT NULL,                   -- JSON encoded quiz settings
    isdefault TINYINT(1) DEFAULT 0,
    version INT DEFAULT 1,
    timecreated BIGINT(10) NOT NULL,
    timemodified BIGINT(10) NOT NULL,
    PRIMARY KEY (id),
    KEY quiztype (quiztype),
    KEY isdefault (isdefault)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='Quiz settings templates';

-- ===================================================================
-- Attempt Tracking (For Threshold Logic)
-- ===================================================================
CREATE TABLE mdl_local_personalcourse_attempts (
    id BIGINT(10) NOT NULL AUTO_INCREMENT,
    userid BIGINT(10) NOT NULL,               -- Student ID
    quizid BIGINT(10) NOT NULL,               -- Source quiz ID (public course)
    attemptid BIGINT(10) NOT NULL,            -- Quiz attempt ID (FK to mdl_quiz_attempts)
    attemptnumber INT NOT NULL,               -- 1, 2, 3, ...
    grade DECIMAL(10, 5) DEFAULT NULL,        -- Percentage grade (0-100)
    generationtriggered TINYINT(1) DEFAULT 0, -- 1 if triggered generation/regeneration
    timecreated BIGINT(10) NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY attemptid_unique (attemptid),
    KEY userid_quizid (userid, quizid),
    KEY userid (userid),
    KEY quizid (quizid)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='Attempt tracking for generation thresholds';
```

---

### **Database Indexes**

```sql
-- Performance optimization indexes

-- Personal courses lookup by user
CREATE INDEX idx_pc_userid ON mdl_local_personalcourse_courses(userid, status);

-- Quiz mapping lookups
CREATE INDEX idx_pq_personalcourse ON mdl_local_personalcourse_quizzes(personalcourseid, quiztype);
CREATE INDEX idx_pq_sourcequiz ON mdl_local_personalcourse_quizzes(sourcequizid);

-- Question lookups
CREATE INDEX idx_pqst_quiz_flag ON mdl_local_personalcourse_questions(personalquizid, flagcolor);
CREATE INDEX idx_pqst_question ON mdl_local_personalcourse_questions(questionid);
-- Enforce per-personal-course dedupe across all quizzes
CREATE UNIQUE INDEX idx_pcq_course_question ON mdl_local_personalcourse_questions(personalcourseid, questionid);

-- Generation log queries
CREATE INDEX idx_gen_course_time ON mdl_local_personalcourse_generations(personalcourseid, timecreated DESC);
CREATE INDEX idx_gen_trigger ON mdl_local_personalcourse_generations(triggertype, timecreated DESC);

-- Attempt tracking
CREATE INDEX idx_att_user_quiz_num ON mdl_local_personalcourse_attempts(userid, quizid, attemptnumber);
CREATE INDEX idx_att_generated ON mdl_local_personalcourse_attempts(generationtriggered, timecreated DESC);
```

---

## Core Workflows

### **Workflow 1: Initial Personal Course Generation**

**Trigger**: Student completes 2nd attempt of public quiz with grade ≥30%

```
┌─────────────────────────────────────────────────────────────┐
│ WORKFLOW 1: Initial Personal Course Generation              │
└─────────────────────────────────────────────────────────────┘

Student: John Smith (ID: 12345)
Quiz: "5A-Math-01 (APSMQ101)"
Attempt: 2nd attempt
Grade: 35% (≥30% ✓)

Step 1: Trigger Detection
├── Observer: quiz_attempt_submitted
├── Check: Is this 2nd attempt? → YES
├── Check: Grade ≥30%? → YES (35%)
└── Action: Trigger generation

Step 2: Check Existing Personal Course
├── Query: mdl_local_personalcourse_courses WHERE userid=12345
├── Result: NOT FOUND
└── Action: Create new personal course

Step 3: Create Moodle Course
├── course_generator::create_course()
│   ├── Course name: "[PERSONAL] Review - John Smith (12345)"
│   ├── Course category: "Personal Review Courses"
│   ├── Visibility: Visible (access restricted via enrolment)
│   └── Course ID: 999
├── Insert: mdl_local_personalcourse_courses
│   ├── userid: 12345
│   ├── courseid: 999
│   └── status: 'active'
└── Result: Personal course created

Step 4: Aggregate Flagged Questions
├── flag_aggregator::get_all_flags(userid=12345)
│   ├── Query: mdl_local_questionflags WHERE userid=12345
│   │   └── Results: Q2 (blue), Q8 (red) [manual flags]
│   ├── Query: mdl_quiz_attempts for incorrect answers
│   │   └── Results: Q5 (wrong), Q7 (wrong) [auto-blue]
│   └── Combined: Q2🔵, Q5🔵, Q7🔵, Q8🔴
└── Group by quiz: "5A-Math-01" → 4 questions

Step 5: Extract Course/Section Info
├── course_name_extractor::parse_quiz_name("5A-Math-01")
│   ├── Prefix: "5A"
│   ├── Subject: "Math"
│   ├── Get course: get_course_by_shortname("5A") → "Year 5A Classroom"
│   └── Section name: "5A-Math (Year 5A Classroom)"
└── Result: Section name determined

Step 6: Create Section in Personal Course
├── Create Moodle section in course 999
│   ├── Section name: "5A-Math (Year 5A Classroom)"
│   └── Section ID: 10
└── Result: Section created

Step 7: Create Quiz in Section
├── quiz_builder::create_quiz()
│   ├── Quiz name: "5A-Math-01 (APSMQ101)"
│   ├── Section: 10
│   ├── Quiz type: Detect → non_essay
│   ├── Settings template: Apply "non_essay_quiz"
│   └── Quiz ID: 1001
├── Insert: mdl_local_personalcourse_quizzes
│   ├── personalcourseid: (from course 999)
│   ├── quizid: 1001
│   ├── sourcequizid: (original quiz ID)
│   └── sectionname: "5A-Math"
└── Result: Quiz created

Step 8: Add Questions to Quiz
├── Dedupe across personal course
│   ├── If any of Q2/Q5/Q7/Q8 already exist in another personal quiz
│   │   within this personal course, remove them there first
│   │   (enforced by unique constraint and logic)
│   └── Result: Each question appears only once per personal course
├── Inject using quiz_uploader
│   ├── `local_quiz_uploader\quiz_creator::add_questions_to_quiz($quizid, $questionids)`
│   ├── Questions are sourced from the System Question Bank only
│   └── Positions assigned sequentially (1..N)
├── Persist auto-incorrect as blue flags
│   └── For any incorrect answer without an existing manual flag,
│       insert `local_questionflags` row (userid+questionid, flagcolor=blue)
└── Result: 4 questions added (and flags unified)

Step 9: Enroll Users
├── enrollment_manager::enroll_users()
│   ├── Enroll: John Smith (student role)
│   ├── Enroll: All teachers from John's public courses
│   ├── Enroll: Managers
│   └── Enroll: Site admins
└── Result: Users enrolled

Step 10: Log Generation
├── Insert: mdl_local_personalcourse_generations
│   ├── personalcourseid: (course 999)
│   ├── triggertype: 'threshold_30'
│   ├── triggerdetails: JSON {attempt: 2, grade: 35, quiz: "5A-Math-01"}
│   ├── questionsadded: 4
│   └── quizzesadded: 1
└── Result: Generation logged

Step 11: Update Attempt Tracking
├── Insert: mdl_local_personalcourse_attempts
│   ├── userid: 12345
│   ├── quizid: (source quiz)
│   ├── attemptid: (attempt ID)
│   ├── attemptnumber: 2
│   ├── grade: 35.00
│   └── generationtriggered: 1
└── Result: Attempt tracked

✅ COMPLETE
John's personal course now contains:
└── Section: 5A-Math (Year 5A Classroom)
    └── Quiz: 5A-Math-01 (4 questions)
```

---

### **Workflow 2: Flag-Based Question Sync (Personal Course Attempt)**

**Trigger**: Student attempts personal course quiz and removes flag

```
┌─────────────────────────────────────────────────────────────┐
│ WORKFLOW 2: Flag Removal → Question Sync                    │
└─────────────────────────────────────────────────────────────┘

Student: John Smith (ID: 12345)
Personal Quiz: "5A-Math-01"
Current Questions: Q1(originally Q2)🔵, Q2(originally Q5)🔵,
                   Q3(originally Q7)🔵, Q4(originally Q8)🔴

Action: Student attempts quiz, answers Q1 correctly, removes blue flag

Step 1: Quiz Attempt Completion
├── Student finishes personal quiz attempt
├── Q1: Correct ✓
├── Q2: Incorrect ✗
├── Q3: Correct ✓
└── Q4: Skipped

Step 2: Student Removes Flag
├── Student clicks "Finish review"
├── Student clicks "Remove flag" on Q1 (original Q2)
├── Update: mdl_local_questionflags
│   └── DELETE WHERE userid=12345 AND questionid=Q2
└── Event triggered: \local_questionflags\event\flag_removed

Step 3: Observer Catches Event
├── Observer: sync_manager::flag_removed()
├── Event data:
│   ├── userid: 12345
│   └── questionid: Q2
└── Action: Process flag removal

Step 4: Find Personal Course & Quiz
├── Query: mdl_local_personalcourse_courses WHERE userid=12345
│   └── Result: personalcourseid = (course 999)
├── Query: mdl_local_personalcourse_questions
│   WHERE questionid=Q2
│   └── Result: personalquizid = 1001
└── Action: Remove question from quiz

Step 5: Remove Question from Quiz
├── quiz_builder::remove_question()
│   ├── Delete from mdl_quiz_slots WHERE questionid=Q2
│   ├── Renumber remaining slots: Q2→Q1, Q3→Q2, Q4→Q3
│   └── Update quiz question count
├── Delete: mdl_local_personalcourse_questions
│   WHERE personalquizid=1001 AND questionid=Q2
└── Result: Question removed

Step 6: Update Quiz Metadata
├── Update: mdl_local_personalcourse_quizzes
│   ├── questioncount: 3 (was 4)
│   └── timemodified: NOW
└── Result: Metadata updated

Step 7: Log Sync Event
├── Insert: mdl_local_personalcourse_generations
│   ├── triggertype: 'flag_sync'
│   ├── triggerdetails: JSON {action: 'remove', questionid: Q2}
│   ├── questionsremoved: 1
│   └── timecreated: NOW
└── Result: Sync logged

✅ COMPLETE
Personal quiz now contains:
├── Q1 (originally Q5) 🔵
├── Q2 (originally Q7) 🔵
└── Q3 (originally Q8) 🔴

Note: Q2 was removed even though student answered correctly,
because that's when they removed the flag.
```

---

### **Workflow 3: Regeneration on 70% Threshold**

**Trigger**: Student completes 4th attempt with grade ≥70%

```
┌─────────────────────────────────────────────────────────────┐
│ WORKFLOW 3: Regeneration on 70% Threshold                   │
└─────────────────────────────────────────────────────────────┘

Student: John Smith (ID: 12345)
Quiz: "5A-Math-01"
Attempt: 4th attempt
Previous Grade: 45% (Attempt 3)
Current Grade: 75% (≥70% ✓)

Step 1: Trigger Detection
├── Observer: quiz_attempt_submitted
├── Check: Attempt number? → 4
├── Check: Grade ≥70%? → YES (75%)
├── Query: Previous attempts
│   ├── Attempt 2: 35% (generated)
│   └── Attempt 3: 45% (no update)
└── Action: Trigger regeneration

Step 2: Get Current Flags (Latest State)
├── flag_aggregator::get_all_flags(userid=12345, quizid=source)
│   ├── Manual flags: Q3🔵, Q8🔴, Q10🔵 (NEW!)
│   ├── Incorrect this attempt: Q5🔵 (auto)
│   └── Total: Q3🔵, Q5🔵, Q8🔴, Q10🔵
└── Compare with existing personal quiz

Step 3: Diff Analysis
├── Current personal quiz has: Q5🔵, Q7🔵, Q8🔴
├── New flags show: Q3🔵, Q5🔵, Q8🔴, Q10🔵
├── Changes:
│   ├── Add: Q3🔵, Q10🔵 (newly flagged)
│   ├── Keep: Q5🔵, Q8🔴 (still flagged)
│   └── Remove: Q7🔵 (flag was removed)
└── Actions needed: +2, -1

Step 4: Remove Unflagged Questions
├── quiz_builder::remove_question(Q7)
│   ├── Delete from quiz_slots
│   └── Delete from personalcourse_questions
└── Result: Q7 removed

Step 5: Add New Flagged Questions
├── For Q3:
│   ├── quiz_builder::add_question_to_quiz()
│   ├── Insert quiz_slot
│   └── Insert personalcourse_questions
├── For Q10:
│   ├── quiz_builder::add_question_to_quiz()
│   ├── Insert quiz_slot
│   └── Insert personalcourse_questions
└── Result: Q3, Q10 added

Step 6: Renumber Questions
├── Reorder quiz slots sequentially
│   ├── Q3 → Position 1
│   ├── Q5 → Position 2
│   ├── Q8 → Position 3
│   └── Q10 → Position 4
└── Update currentposition in personalcourse_questions

Step 7: Update Metadata
├── Update: mdl_local_personalcourse_quizzes
│   ├── questioncount: 4
│   └── timemodified: NOW
└── Update: mdl_local_personalcourse_courses
    └── timemodified: NOW

Step 8: Log Regeneration
├── Insert: mdl_local_personalcourse_generations
│   ├── triggertype: 'threshold_70'
│   ├── triggerdetails: JSON {attempt: 4, grade: 75}
│   ├── questionsadded: 2
│   └── questionsremoved: 1
└── Result: Regeneration logged

Step 9: Update Attempt Tracking
├── Insert: mdl_local_personalcourse_attempts
│   ├── attemptnumber: 4
│   ├── grade: 75.00
│   └── generationtriggered: 1
└── Result: Attempt tracked

✅ COMPLETE
Personal quiz refreshed:
├── Q1 (originally Q3) 🔵 [NEW]
├── Q2 (originally Q5) 🔵 [KEPT]
├── Q3 (originally Q8) 🔴 [KEPT]
└── Q4 (originally Q10) 🔵 [NEW]

Removed: Q7 (no longer flagged)
```

---

### **Workflow 4: Multi-Quiz Personal Course**

**Scenario**: Student has flagged questions across multiple quizzes

```
┌─────────────────────────────────────────────────────────────┐
│ WORKFLOW 4: Multi-Quiz Personal Course Structure            │
└─────────────────────────────────────────────────────────────┘

Student: Sarah Wong (ID: 10048)
Enrolled in:
├── Year 5A Classroom (5A-)
├── Selective Trial Test (ST-)
└── OC Trial Test (OT-)

Flagged Questions Across Quizzes:
├── 5A-Math-01: Q2🔵, Q5🔵, Q8🔴 (3 questions)
├── 5A-Writing-01: Q1🔵 (1 question, essay type)
├── ST-Reading-33: Q3🔵, Q7🔴 (2 questions)
└── OT-Math-01: Q4🔵, Q6🔵, Q9🔵 (3 questions)

Generated Personal Course Structure:

[PERSONAL] Review - Sarah Wong (10048)
│
├── Section 1: 5A-Math (Year 5A Classroom)
│   └── Quiz: 5A-Math-01 (APSMQ101)
│       ├── Type: non_essay
│       ├── Settings: non_essay_quiz template
│       └── Questions: 3
│           ├── Q1 (originally Q2) 🔵
│           ├── Q2 (originally Q5) 🔵
│           └── Q3 (originally Q8) 🔴
│
├── Section 2: 5A-Writing (Year 5A Classroom)
│   └── Quiz: 5A-Writing-01 (WRIT01)
│       ├── Type: essay
│       ├── Settings: essay_quiz template
│       └── Questions: 1
│           └── Q1 (originally Q1) 🔵
│
├── Section 3: ST-Reading (Selective Trial Test)
│   └── Quiz: ST-Reading-33 (GMSR13)
│       ├── Type: non_essay
│       ├── Settings: non_essay_quiz template
│       └── Questions: 2
│           ├── Q1 (originally Q3) 🔵
│           └── Q2 (originally Q7) 🔴
│
└── Section 4: OT-Math (OC Trial Test)
    └── Quiz: OT-Math-01 (OCSOM01)
        ├── Type: non_essay
        ├── Settings: non_essay_quiz template
        └── Questions: 3
            ├── Q1 (originally Q4) 🔵
            ├── Q2 (originally Q6) 🔵
            └── Q3 (originally Q9) 🔵

Total: 4 sections, 4 quizzes, 9 questions
```

---

## Implementation Phases

### **Phase 1: Core Infrastructure** (Week 1-2)

**Goal**: Set up database, basic course generation for ONE student

**Tasks**:
```
[ ] 3.0 Questionflags Extension
    ├── [ ] Add nullable `cmid` and `quizid` columns to `local_questionflags`
    ├── [ ] Populate these in `\local_questionflags\external\flag_question::execute()`
    └── [ ] Backward compatibility: handle existing rows with NULL values
[ ] 1.1 Database Schema
    ├── [ ] Create install.xml with all tables
    ├── [ ] Create upgrade.php script
    ├── [ ] Add database indexes
    └── [ ] Test database installation

[ ] 1.2 Basic Course Generator
    ├── [ ] Build course_generator class
    │   ├── [ ] create_course() method
    │   ├── [ ] create_section() method
    │   └── [ ] delete_course() method
    ├── [ ] Test course creation manually
    └── [ ] Verify course visibility settings

[ ] 1.3 Flag Aggregator
    ├── [ ] Build flag_aggregator class
    │   ├── [ ] get_manual_flags() method
    │   ├── [ ] get_incorrect_answers() method
    │   └── [ ] combine_flags() method
    ├── [ ] Test flag collection from sample data
    └── [ ] Verify blue/red flag priorities

[ ] 1.4 Enrollment Manager
    ├── [ ] Build enrollment_manager class
    │   ├── [ ] enroll_student() method
    │   ├── [ ] enroll_teachers() method
    │   └── [ ] enroll_admins() method
    ├── [ ] Test enrollment
    └── [ ] Verify role assignments

[ ] 1.5 CLI Test Script
    ├── [ ] Create cli/generate_personal_course.php
    ├── [ ] Test with one student
    └── [ ] Document results
```

**Deliverables**:
- ✅ Database tables created and installed
- ✅ Basic course generation working for 1 student
- ✅ Flag aggregation functional
- ✅ CLI script for testing

**Testing Checklist**:
```
[ ] Create test student account
[ ] Add manual flags (blue/red) to questions
[ ] Run CLI generation script
[ ] Verify personal course created
[ ] Verify correct sections
[ ] Verify student enrolled
[ ] Verify teachers enrolled
```

---

### **Phase 2: Quiz Generation** (Week 3-4)

**Goal**: Generate quizzes with questions from flags

**Tasks**:
```
[ ] 2.1 Quiz Builder
    ├── [ ] Build quiz_builder adapter class (delegates to quiz_uploader)
    │   ├── [ ] create_quiz() -> `local_quiz_uploader\quiz_creator::create_quiz()`
    │   ├── [ ] add_questions() -> `quiz_creator::add_questions_to_quiz()`
    │   ├── [ ] remove_question() -> mod_quiz structure API
    │   └── [ ] renumber_questions() -> mod_quiz structure API
    ├── [ ] Test quiz creation
    └── [ ] Test question linking (NOT duplication)

[ ] 2.2 Course Name Extractor
    ├── [ ] Build course_name_extractor class
    │   ├── [ ] parse_quiz_name() method
    │   ├── [ ] get_course_by_shortname() method
    │   └── [ ] build_section_name() method
    ├── [ ] Test with sample quiz names
    └── [ ] Handle missing prefixes gracefully

[ ] 2.3 Settings Manager
    ├── [ ] Build settings_manager class
    │   ├── [ ] create_template() method
    │   ├── [ ] apply_template() method
    │   └── [ ] detect_quiz_type() method (essay/non-essay)
    ├── [ ] Create default templates
    │   ├── [ ] essay_quiz template = Moodle defaults
    │   └── [ ] non_essay_quiz template = quiz_uploader default (interactive)
    └── [ ] Test template application

[ ] 2.4 Question Positioning
    ├── [ ] Implement sequential renumbering
    ├── [ ] Store original positions
    ├── [ ] Test position updates
    └── [ ] Verify quiz_slots ordering

[ ] 2.5 Integration Testing
    ├── [ ] Generate course with multiple quizzes
    ├── [ ] Verify flat section structure
    ├── [ ] Verify question renumbering
    └── [ ] Verify settings applied correctly
```

**Deliverables**:
- ✅ Quiz generation functional
- ✅ Settings templates working
- ✅ Multi-quiz support
- ✅ Question linking (not duplication)

**Testing Checklist**:
```
[ ] Create student with flags in 3 quizzes
[ ] Generate personal course
[ ] Verify 3 quizzes created
[ ] Verify questions renumbered (Q1, Q2, Q3...)
[ ] Verify essay quiz has essay template
[ ] Verify non-essay quiz has non-essay template
[ ] Attempt quiz in personal course
[ ] Verify questions display correctly
```

---

### **Phase 3: Sync & Updates** (Week 5)

**Goal**: Auto-sync with flag changes and threshold-based regeneration (multiple generations per day allowed)

**Tasks**:
```
[ ] 3.1 Event Observers
    ├── [ ] Extend local_questionflags to emit events from `classes/external/flag_question::execute()`
    │   ├── [ ] Define events: `\local_questionflags\event\flag_added`, `flag_removed`
    │   └── [ ] Trigger after DB writes
    ├── [ ] Create observers.php in personalcourse
    │   ├── [ ] on_flag_removed()
    │   ├── [ ] on_flag_added()
    │   └── [ ] on_quiz_attempt_submitted()
    ├── [ ] Register in db/events.php
    └── [ ] Test event triggering end-to-end

[ ] 3.2 Sync Manager
    ├── [ ] Build sync_manager class
    │   ├── [ ] handle_flag_removal() method
    │   ├── [ ] handle_flag_addition() method
    │   └── [ ] check_and_trigger_generation() method
    ├── [ ] Implement flag-based sync logic
    └── [ ] Test question removal on flag clear

[ ] 3.3 Threshold Logic
    ├── [ ] Build threshold_checker class
    │   ├── [ ] get_attempt_number() method
    │   ├── [ ] should_generate() method (2nd attempt, ≥30%)
    │   └── [ ] should_regenerate() method (3rd+ attempt, ≥70%)
    ├── [ ] Track attempts in personalcourse_attempts table
    └── [ ] Test threshold triggers (no daily rate limit; normalized 0–100)

[ ] 3.4 Regeneration Logic
    ├── [ ] Build regenerator class
    │   ├── [ ] diff_flags() method (compare old vs new)
    │   ├── [ ] apply_diff() method (add/remove questions)
    │   └── [ ] log_regeneration() method
    ├── [ ] Test regeneration on 70% threshold
    └── [ ] Verify only changed questions updated

[ ] 3.5 Integration Testing
    ├── [ ] Student removes flag → question drops
    ├── [ ] Student adds flag → question appears
    ├── [ ] 2nd attempt at 35% → generate
    ├── [ ] 3rd attempt at 50% → no update
    └── [ ] 4th attempt at 75% → regenerate

[ ] 3.6 Enrolment Sync
    ├── [ ] Ensure student is enrolled via manual enrol plugin
    ├── [ ] Enrol all teachers from source public courses; managers; admins
    └── [ ] Periodically sync/cleanup teacher enrolments as source course staff change
```

**Deliverables**:
- ✅ Real-time flag sync working
- ✅ Threshold-based generation working
- ✅ Regeneration functional
- ✅ Event observers installed

**Testing Checklist**:
```
[ ] Student flags question blue → appears in personal course
[ ] Student removes blue flag → drops from personal course
[ ] Student attempts quiz (attempt 1, 50%) → no generation
[ ] Student attempts quiz (attempt 2, 35%) → generates
[ ] Student attempts quiz (attempt 3, 55%) → no update
[ ] Student attempts quiz (attempt 4, 75%) → regenerates
[ ] Verify generation log entries
[ ] Verify attempt tracking table
```

---

### **Phase 4: Dashboard & UI** (Week 6-7)

**Goal**: Admin dashboard and student view

**Tasks**:
```
[ ] 4.1 Admin Dashboard Page
    ├── [ ] Create dashboard.php
    ├── [ ] Build dashboard controller class
    │   ├── [ ] get_all_personal_courses() method
    │   ├── [ ] filter_courses() method
    │   └── [ ] sort_courses() method
    ├── [ ] Design dashboard UI
    │   ├── [ ] Course list with stats
    │   ├── [ ] Filter controls
    │   └── [ ] Search box
    └── [ ] Test dashboard rendering

[ ] 4.2 Bulk Operations
    ├── [ ] Build bulk_operations class
    │   ├── [ ] regenerate_selected() method
    │   ├── [ ] apply_template_to_selected() method
    │   ├── [ ] delete_selected() method
    │   └── [ ] export_report() method
    ├── [ ] Add bulk action UI controls
    └── [ ] Test bulk regeneration

[ ] 4.3 Student Dashboard Widget
    ├── [ ] Create student_view.php
    ├── [ ] Build widget renderer
    │   ├── [ ] Display flag counts
    │   ├── [ ] Display progress
    │   └── [ ] Link to personal course
    └── [ ] Test widget display

[ ] 4.4 Progress Reports
    ├── [ ] Build report_generator class
    │   ├── [ ] generate_student_report() method
    │   ├── [ ] generate_class_report() method
    │   └── [ ] export_csv() method
    ├── [ ] Design report layouts
    └── [ ] Test report generation

[ ] 4.5 Course List Organization
    ├── [ ] Implement custom sorting
    │   ├── [ ] Public courses first
    │   └── [ ] Personal courses alphabetically
    ├── [ ] Add filters
    │   ├── [ ] By student name/ID
    │   ├── [ ] By flag count
    │   └── [ ] By last updated
    └── [ ] Test sorting/filtering
```

**Deliverables**:
- ✅ Fully functional admin dashboard
- ✅ Student dashboard widget
- ✅ Bulk operations working
- ✅ Progress reports

**Testing Checklist**:
```
[ ] Admin accesses dashboard
[ ] Dashboard shows all personal courses
[ ] Filter by student name works
[ ] Sort by flag count works
[ ] Bulk select 3 students
[ ] Apply template to selected
[ ] Force regenerate selected
[ ] Export progress report
[ ] Student sees widget in dashboard
[ ] Student clicks to access personal course
```

---

### **Phase 5: Homework Integration** (Week 8)

**Goal**: Integrate essay homework from quizdashboard (DEFERRED)

**Note**: Focus on core functionality in Phases 1-4 first. Phase 5 to be implemented later.

**Future Tasks**:
```
[ ] 5.1 Homework Injector
    ├── [ ] Build homework_injector class
    ├── [ ] Extract homework from essay grader feedback
    ├── [ ] Create homework sections in quizzes
    └── [ ] Test homework injection

[ ] 5.2 Essay Observer
    ├── [ ] Listen to essay_graded event
    ├── [ ] Trigger homework injection
    └── [ ] Test event handling

[ ] 5.3 Homework Sync
    ├── [ ] Update homework when feedback changes
    ├── [ ] Remove homework when feedback deleted
    └── [ ] Test homework updates
```

---

### **Phase 6: Polish & Testing** (Week 9-10)

**Goal**: Error handling, logging, documentation

**Tasks**:
```
[ ] 6.1 Error Handling
    ├── [ ] Add try-catch blocks everywhere
    ├── [ ] Implement error logging
    ├── [ ] Create error message strings
    └── [ ] Test error scenarios

[ ] 6.2 Logging System
    ├── [ ] Build logger class
    │   ├── [ ] log_generation() method
    │   ├── [ ] log_sync() method
    │   └── [ ] log_error() method
    ├── [ ] Add logging throughout codebase
    └── [ ] Test log output

[ ] 6.3 Validation & Security
    ├── [ ] Validate all user inputs
    ├── [ ] Check capabilities everywhere
    ├── [ ] Sanitize HTML outputs
    └── [ ] Security audit

[ ] 6.4 Performance Testing
    ├── [ ] Test with 50 students
    ├── [ ] Profile database queries
    ├── [ ] Optimize slow queries
    └── [ ] Implement caching where needed

[ ] 6.5 Documentation
    ├── [ ] Write admin guide
    ├── [ ] Write user guide
    ├── [ ] Document all classes/methods (PHPDoc)
    ├── [ ] Create troubleshooting guide
    └── [ ] Record demo video

[ ] 6.6 User Acceptance Testing
    ├── [ ] Test with real teachers
    ├── [ ] Test with real students
    ├── [ ] Collect feedback
    └── [ ] Fix bugs

[ ] 6.7 Final Checklist
    ├── [ ] All features working
    ├── [ ] No critical bugs
    ├── [ ] Documentation complete
    ├── [ ] Performance acceptable
    └── [ ] Ready for production
```

**Deliverables**:
- ✅ Production-ready plugin
- ✅ Complete documentation
- ✅ Test reports
- ✅ User guides

---

## Class Structure

### **Directory Layout**

```
local/personalcourse/
├── classes/
│   ├── course_generator.php          -- Creates Moodle courses
│   ├── quiz_builder.php              -- Creates/manages quizzes
│   │   (adapter to `local_quiz_uploader\quiz_creator` and mod_quiz APIs)
│   ├── flag_aggregator.php           -- Collects flags + incorrect answers
│   ├── sync_manager.php              -- Handles flag-based syncing
│   ├── threshold_checker.php         -- Checks attempt/grade thresholds
│   ├── regenerator.php               -- Handles course regeneration
│   ├── course_name_extractor.php     -- Parses quiz names for sections
│   ├── settings_manager.php          -- Manages quiz settings templates
│   ├── enrollment_manager.php        -- Handles course enrollments
│   ├── access_checker.php            -- Checks user permissions
│   ├── dashboard_controller.php      -- Admin dashboard logic
│   ├── bulk_operations.php           -- Bulk actions on courses
│   ├── report_generator.php          -- Progress reports
│   ├── logger.php                    -- Logging system
│   ├── observers.php                 -- Event observers
│   ├── task/
│   │   └── cleanup_old_logs.php      -- Scheduled task
│   └── external/
│       ├── regenerate_course.php     -- Web service
│       └── get_course_stats.php      -- Web service
├── db/
│   ├── install.xml                   -- Database schema
│   ├── upgrade.php                   -- Upgrade scripts
│   ├── access.php                    -- Capabilities
│   ├── events.php                    -- Event observers
│   └── tasks.php                     -- Scheduled tasks
├── lang/
│   └── en/
│       └── local_personalcourse.php  -- Language strings
├── templates/
│   ├── dashboard.mustache            -- Admin dashboard
│   ├── student_widget.mustache       -- Student widget
│   └── progress_report.mustache      -- Report template
├── cli/
│   ├── generate_personal_course.php  -- CLI generation script
│   └── cleanup.php                   -- CLI cleanup script
├── dashboard.php                     -- Admin dashboard page
├── student_view.php                  -- Student dashboard widget
├── settings.php                      -- Plugin settings
├── lib.php                           -- Required Moodle functions
├── version.php                       -- Version info
└── README.md                         -- Plugin documentation
```

---

### **Core Classes**

#### **1. course_generator.php**

```php
<?php
namespace local_personalcourse;

class course_generator {

    /**
     * Generate personal course for student
     *
     * @param int $userid Student ID
     * @return int|false Course ID or false on error
     */
    public function generate_for_student($userid) {
        // Check if already exists
        if ($this->has_personal_course($userid)) {
            return false;
        }

        // Create Moodle course
        $courseid = $this->create_moodle_course($userid);

        // Store in tracking table
        $this->save_personal_course_record($userid, $courseid);

        // Enroll users
        $this->enroll_users($courseid, $userid);

        // Generate content
        $this->generate_course_content($userid, $courseid);

        return $courseid;
    }

    /**
     * Create Moodle course
     */
    private function create_moodle_course($userid) {
        global $DB;

        $user = $DB->get_record('user', ['id' => $userid]);

        $course = new \stdClass();
        $course->fullname = "[PERSONAL] Review - {$user->firstname} {$user->lastname} ({$userid})";
        $course->shortname = "PERSONAL_{$userid}";
        $course->category = $this->get_personal_course_category();
        $course->visible = 0;  // Hidden
        $course->startdate = time();

        return create_course($course)->id;
    }

    /**
     * Generate course content (sections, quizzes, questions)
     */
    private function generate_course_content($userid, $courseid) {
        // Get all flagged questions
        $aggregator = new flag_aggregator();
        $flags = $aggregator->get_all_flags($userid);

        // Group by quiz
        $quizzes = $this->group_by_quiz($flags);

        // Create sections and quizzes
        foreach ($quizzes as $source_quiz_id => $questions) {
            $this->create_quiz_in_course($courseid, $source_quiz_id, $questions);
        }
    }
}
```

---

#### **2. flag_aggregator.php**

```php
<?php
namespace local_personalcourse;

class flag_aggregator {

    /**
     * Get all flagged questions for user
     * Combines manual flags + incorrect answers
     *
     * @param int $userid Student ID
     * @return array Flagged questions grouped by quiz
     */
    public function get_all_flags($userid) {
        // Get manual flags
        $manual_flags = $this->get_manual_flags($userid);

        // Get incorrect answers (auto-blue)
        $incorrect = $this->get_incorrect_answers($userid);

        // Combine and deduplicate
        return $this->combine_flags($manual_flags, $incorrect);
    }

    /**
     * Get manual blue/red flags
     */
    private function get_manual_flags($userid) {
        global $DB;

        $sql = "SELECT qf.*, q.id as quizid
                FROM {local_questionflags} qf
                JOIN {quiz_slots} qs ON qs.questionid = qf.questionid
                JOIN {quiz} q ON q.id = qs.quizid
                WHERE qf.userid = ?";

        $flags = $DB->get_records_sql($sql, [$userid]);

        return array_map(function($flag) {
            return [
                'questionid' => $flag->questionid,
                'quizid' => $flag->quizid,
                'color' => $flag->flagcolor,  // blue or red
                'source' => 'manual_flag'
            ];
        }, $flags);
    }

    /**
     * Get incorrect answers (auto-blue flags)
     */
    private function get_incorrect_answers($userid) {
        global $DB;

        // Get latest attempt for each quiz
        $sql = "SELECT qa.quiz, MAX(qa.id) as attemptid
                FROM {quiz_attempts} qa
                WHERE qa.userid = ?
                AND qa.state = 'finished'
                GROUP BY qa.quiz";

        $attempts = $DB->get_records_sql($sql, [$userid]);

        $incorrect = [];
        foreach ($attempts as $attempt) {
            // Get wrong answers from this attempt
            $wrong = $this->get_wrong_answers_from_attempt($attempt->attemptid);
            $incorrect = array_merge($incorrect, $wrong);
        }

        return $incorrect;
    }

    /**
     * Combine manual flags + incorrect answers
     * Priority: Red > Blue > Auto-blue
     */
    private function combine_flags($manual, $incorrect) {
        $combined = [];

        // Add all manual flags first
        foreach ($manual as $flag) {
            $key = $flag['questionid'];
            $combined[$key] = $flag;
        }

        // Add incorrect answers as auto-blue (only if not already flagged)
        foreach ($incorrect as $flag) {
            $key = $flag['questionid'];
            if (!isset($combined[$key])) {
                $combined[$key] = $flag;
            }
        }

        return array_values($combined);
    }
}
```

---

#### **3. sync_manager.php**

```php
<?php
namespace local_personalcourse;

class sync_manager {

    /**
     * Handle flag removal event
     * Triggered when student removes blue/red flag
     */
    public function handle_flag_removal($userid, $questionid) {
        global $DB;

        // Get student's personal course
        $pc = $DB->get_record('local_personalcourse_courses', ['userid' => $userid]);
        if (!$pc) {
            return;  // No personal course yet
        }

        // Find which quiz contains this question
        $pq = $DB->get_record_sql(
            "SELECT pq.*
             FROM {local_personalcourse_questions} pcq
             JOIN {local_personalcourse_quizzes} pq ON pq.id = pcq.personalquizid
             WHERE pcq.questionid = ?
             AND pq.personalcourseid = ?",
            [$questionid, $pc->id]
        );

        if (!$pq) {
            return;  // Question not in personal course
        }

        // Remove question from quiz
        $quiz_builder = new quiz_builder();
        $quiz_builder->remove_question($pq->quizid, $questionid);

        // Log the sync
        $this->log_sync_event($pc->id, 'flag_removed', $questionid);
    }

    /**
     * Handle flag addition event
     */
    public function handle_flag_addition($userid, $questionid, $color, $quizid) {
        global $DB;

        // Get student's personal course
        $pc = $DB->get_record('local_personalcourse_courses', ['userid' => $userid]);
        if (!$pc) {
            return;  // No personal course yet
        }

        // Check if quiz exists in personal course
        $pq = $DB->get_record('local_personalcourse_quizzes', [
            'personalcourseid' => $pc->id,
            'sourcequizid' => $quizid
        ]);

        if (!$pq) {
            // Quiz doesn't exist, create it
            $quiz_builder = new quiz_builder();
            $pq_id = $quiz_builder->create_quiz_from_source($pc->courseid, $quizid);
        } else {
            $pq_id = $pq->id;
        }

        // Add question to quiz
        $quiz_builder = new quiz_builder();
        $quiz_builder->add_question($pq_id, $questionid, $color);

        // Log the sync
        $this->log_sync_event($pc->id, 'flag_added', $questionid);
    }
}
```

---

#### **4. threshold_checker.php**

```php
<?php
namespace local_personalcourse;

class threshold_checker {

    /**
     * Check if attempt should trigger generation/regeneration
     *
     * @param int $userid Student ID
     * @param int $quizid Source quiz ID
     * @param int $attemptid Attempt ID
     * @param float $grade Grade percentage (0-100)
     * @return array ['action' => 'generate'|'regenerate'|'none', 'reason' => string]
     */
    public function check_thresholds($userid, $quizid, $attemptid, $grade) {
        // Get attempt number
        $attempt_number = $this->get_attempt_number($userid, $quizid, $attemptid);

        // Rule 1: No generation on first attempt
        if ($attempt_number == 1) {
            return ['action' => 'none', 'reason' => 'First attempt, no generation'];
        }

        // Rule 2: Generate on 2nd attempt if grade ≥30%
        if ($attempt_number == 2) {
            if ($grade >= 30) {
                return ['action' => 'generate', 'reason' => '2nd attempt, grade ≥30%'];
            } else {
                return ['action' => 'none', 'reason' => '2nd attempt, grade <30%'];
            }
        }

        // Rule 3: Regenerate on 3rd+ attempt if grade ≥70%
        if ($attempt_number >= 3) {
            if ($grade >= 70) {
                return ['action' => 'regenerate', 'reason' => 'Grade ≥70%, worthwhile to refresh'];
            } else {
                return ['action' => 'none', 'reason' => 'Grade <70%, minimize regeneration'];
            }
        }

        return ['action' => 'none', 'reason' => 'Unknown state'];
    }

    /**
     * Get attempt number for user+quiz
     */
    private function get_attempt_number($userid, $quizid, $attemptid) {
        global $DB;

        $sql = "SELECT COUNT(*) + 1
                FROM {quiz_attempts}
                WHERE userid = ?
                AND quiz = ?
                AND id < ?
                AND state = 'finished'";

        return (int)$DB->count_records_sql($sql, [$userid, $quizid, $attemptid]);
    }
}
```

---

## User Interface

### **Admin Dashboard**

```
┌──────────────────────────────────────────────────────────────────┐
│  Personal Review Courses - Dashboard                             │
│  Manage student personal courses                                 │
└──────────────────────────────────────────────────────────────────┘

[Filters]
┌────────────────────────────────────────────────────────────────┐
│ Public Course: [All ▼]  Status: [Active ▼]                     │
│ Search Student: [__________________] 🔍                         │
│ Sort by: [Name ▼]  [Last Updated] [Flag Count]                 │
└────────────────────────────────────────────────────────────────┘

[Bulk Actions]
☐ Select All (50 students)  [Regenerate Selected] [Apply Template ▼]

┌──────────────────────────────────────────────────────────────────┐
│ ☐ Student: Amy Chen (ID: 10001)                                 │
│    Course: [PERSONAL] Review - Amy Chen (10001)                  │
│    ─────────────────────────────────────────────────────────────│
│    Sections: 3  |  Quizzes: 5  |  Questions: 23                 │
│    🔵 Blue: 18  |  🔴 Red: 5                                    │
│    Last Updated: 2 hours ago  |  Last Access: Today 10:30 AM    │
│    ─────────────────────────────────────────────────────────────│
│    [View Course] [Regenerate] [Settings] [Delete] [📊 Report]   │
└──────────────────────────────────────────────────────────────────┘

┌──────────────────────────────────────────────────────────────────┐
│ ☐ Student: Ben Lee (ID: 10003)                                  │
│    Course: [PERSONAL] Review - Ben Lee (10003)                   │
│    ─────────────────────────────────────────────────────────────│
│    Sections: 4  |  Quizzes: 7  |  Questions: 31                 │
│    🔵 Blue: 25  |  🔴 Red: 6                                    │
│    Last Updated: 1 day ago  |  Last Access: Yesterday 3:15 PM   │
│    ─────────────────────────────────────────────────────────────│
│    [View Course] [Regenerate] [Settings] [Delete] [📊 Report]   │
└──────────────────────────────────────────────────────────────────┘

... (48 more students)

[Statistics]
Total Courses: 50  |  Active: 48  |  Archived: 2
Total Flagged Questions: 1,247  |  Avg per Student: 24.9

[Pagination]
◄ Previous  1  2  3  4  5  Next ►
```

---

### **Student Dashboard Widget**

```
┌────────────────────────────────────┐
│  📚 My Review Workspace            │
├────────────────────────────────────┤
│                                    │
│  Practice questions you've         │
│  flagged or answered incorrectly.  │
│                                    │
│  ┌──────────────────────────────┐ │
│  │ 🔵 Blue Flags: 18            │ │
│  │ 🔴 Red Flags: 5              │ │
│  │ 📝 Quizzes: 5                │ │
│  │                              │ │
│  │ Last Updated: 2 hours ago    │ │
│  └──────────────────────────────┘ │
│                                    │
│  [Access My Review Course]         │
│                                    │
│  Progress: ██████░░░░ 60%          │
│  Questions Mastered: 14/23         │
└────────────────────────────────────┘
```

---

## Testing Strategy

### **Unit Tests**

```php
// tests/flag_aggregator_test.php
class flag_aggregator_test extends \advanced_testcase {

    public function test_get_manual_flags() {
        $this->resetAfterTest();

        // Create test data
        $user = $this->getDataGenerator()->create_user();
        $course = $this->getDataGenerator()->create_course();
        $quiz = $this->getDataGenerator()->create_module('quiz', ['course' => $course->id]);

        // Add manual flag
        // ... test setup ...

        $aggregator = new \local_personalcourse\flag_aggregator();
        $flags = $aggregator->get_manual_flags($user->id);

        $this->assertCount(1, $flags);
        $this->assertEquals('blue', $flags[0]['color']);
    }

    public function test_combine_flags_priority() {
        // Test that red flags take priority over blue
        // ... test implementation ...
    }
}
```

---

### **Integration Tests**

```php
// tests/generation_workflow_test.php
class generation_workflow_test extends \advanced_testcase {

    public function test_full_generation_workflow() {
        $this->resetAfterTest();

        // 1. Create test student
        $student = $this->getDataGenerator()->create_user();

        // 2. Create quiz and add flags
        // ... test setup ...

        // 3. Simulate 2nd attempt with 35% grade
        // ... trigger generation ...

        // 4. Verify personal course created
        $pc = $DB->get_record('local_personalcourse_courses', ['userid' => $student->id]);
        $this->assertNotEmpty($pc);

        // 5. Verify quiz created
        // 6. Verify questions added (deduped across personal course)
        // ... assertions ...
    }
}
```

---

### **Manual Testing Checklist**

```
Phase 1 Testing:
[ ] Install plugin on dev Moodle
[ ] Verify database tables created
[ ] Create test student account
[ ] Add manual blue/red flags
[ ] Run CLI generation script
[ ] Verify personal course appears in course list
[ ] Verify course is visible to the enrolled student and not visible to others
[ ] Verify student enrolled
[ ] Verify sections created
[ ] Access personal course as student

Phase 2 Testing:
[ ] Create flags in 3 different quizzes
[ ] Generate personal course
[ ] Verify 3 quizzes created
[ ] Verify questions renumbered sequentially
[ ] Verify non-essay quizzes use preferredbehaviour = interactive
[ ] Verify essay quizzes use Moodle defaults
[ ] Attempt quiz in personal course
[ ] Verify questions display correctly

Phase 3 Testing:
[ ] Student flags question blue
[ ] Verify question appears in personal course
[ ] Student removes blue flag
[ ] Verify question drops from personal course
[ ] Student attempts quiz (1st, 50%)
[ ] Verify no generation
[ ] Student attempts quiz (2nd, 35%)
[ ] Verify generation triggered
[ ] Student attempts quiz (3rd, 55%)
[ ] Verify no update
[ ] Student attempts quiz (4th, 75%)
[ ] Verify regeneration triggered

Phase 4 Testing:
[ ] Teacher accesses dashboard
[ ] Verify all students listed
[ ] Filter by student name
[ ] Sort by flag count
[ ] Select 3 students for bulk action
[ ] Apply settings template
[ ] Verify settings applied to all 3
[ ] Force regenerate selected
[ ] Verify regeneration completed
[ ] Export progress report
[ ] Verify CSV download

Phase 6 Testing:
[ ] Test with 50 students
[ ] Monitor database query performance
[ ] Check page load times
[ ] Test concurrent access
[ ] Review error logs
[ ] Test on mobile devices
[ ] Verify log purge after retention period
```

---

## Appendix

### **A. Naming Conventions**

**Course Names**:
- Format: `[PERSONAL] Review - {FirstName} {LastName} ({UserID})`
- Example: `[PERSONAL] Review - John Smith (12345)`

**Section Names**:
- Format: `{Prefix}-{Subject} ({FullCourseName})`
- Example: `5A-Math (Year 5A Classroom)`

**Quiz Names**:
- Format: `{Prefix}-{Subject}-{Title} ({Code})`
- Example: `5A-Math-01 (APSMQ101)`

**Short Names**:
- Personal Course: `PERSONAL_{userid}`
- Example: `PERSONAL_12345`

---

### **B. Database Queries**

**Get all flagged questions for user (preferred, with extended schema)**:
```sql
SELECT
    qf.questionid,
    qf.flagcolor,
    qf.quizid,
    q.name AS quizname
FROM mdl_local_questionflags qf
JOIN mdl_quiz q ON q.id = qf.quizid
WHERE qf.userid = ? AND qf.quizid IS NOT NULL
```

**Fallback (legacy rows without `quizid`)**:
```sql
SELECT
    qf.questionid,
    qf.flagcolor,
    q.id AS quizid,
    q.name AS quizname
FROM mdl_local_questionflags qf
JOIN mdl_quiz_slots qs ON qs.questionid = qf.questionid
JOIN mdl_quiz q ON q.id = qs.quizid
WHERE qf.userid = ? AND qf.quizid IS NULL
```

**Get personal course for student**:
```sql
SELECT
    pc.*,
    c.fullname AS coursename
FROM mdl_local_personalcourse_courses pc
JOIN mdl_course c ON c.id = pc.courseid
WHERE pc.userid = ?
```

**Get question count per quiz**:
```sql
SELECT
    pq.quizid,
    q.name AS quizname,
    COUNT(pcq.id) AS questioncount
FROM mdl_local_personalcourse_quizzes pq
LEFT JOIN mdl_local_personalcourse_questions pcq ON pcq.personalquizid = pq.id
JOIN mdl_quiz q ON q.id = pq.quizid
WHERE pq.personalcourseid = ?
GROUP BY pq.quizid, q.name
```

---

### **C. Moodle APIs Used**

**Course Management**:
- `create_course()` - Create new course
- `delete_course()` - Delete course
- `course_get_format()` - Get course format

**Quiz API**:
- `add_moduleinfo()` - Create quiz activity (module creation)
- `quiz_delete_instance()` - Delete quiz
- `quiz_add_quiz_question()` - Add question to quiz slots
- `quiz_update_sumgrades()` - Recalculate total grades
- `\mod_quiz\structure` - Remove/renumber slots

**Question Bank**:
- System Question Bank only; categories managed via
  `local_quiz_uploader\category_manager` and `question_categories` tables

**Enrollment**:
- `enrol_get_plugin('manual')` and `$plugin->enrol_user()` - Enrol student/teachers/managers/admins
- `enrol_instance()` - Ensure manual enrol instance exists for the course
- Avoid direct `role_assign()`; use enrolments for course access

**Events**:
- `\core\event\base::trigger()` - Trigger event
- Event observers registered in `db/events.php`

---

### **D. Related Plugins**

**Dependencies**:
- `local_questionflags` - Question flagging system
- `mod_quiz` - Core quiz module

**Integrations (Future)**:
- `local_quizdashboard` - Essay grading and homework
- `local_essaysmaster` - Essay management
 - `local_quiz_uploader` - Used for quiz creation and question injection

---

### **E. Configuration Settings**

**Admin Settings** (`settings.php`):

```php
// Personal course category
$settings->add(new admin_setting_configtext(
    'local_personalcourse/coursecategory',
    get_string('coursecategory', 'local_personalcourse'),
    get_string('coursecategory_desc', 'local_personalcourse'),
    'Personal Review Courses',
    PARAM_TEXT
));

// Generation threshold - 2nd attempt minimum grade
$settings->add(new admin_setting_configtext(
    'local_personalcourse/threshold_generate',
    get_string('threshold_generate', 'local_personalcourse'),
    get_string('threshold_generate_desc', 'local_personalcourse'),
    30,
    PARAM_INT
));

// Regeneration threshold - 3rd+ attempt minimum grade
$settings->add(new admin_setting_configtext(
    'local_personalcourse/threshold_regenerate',
    get_string('threshold_regenerate', 'local_personalcourse'),
    get_string('threshold_regenerate_desc', 'local_personalcourse'),
    70,
    PARAM_INT
));

// Auto-sync enabled
$settings->add(new admin_setting_configcheckbox(
    'local_personalcourse/autosync',
    get_string('autosync', 'local_personalcourse'),
    get_string('autosync_desc', 'local_personalcourse'),
    1
));

// Log retention (days) for generation/sync logs
$settings->add(new admin_setting_configtext(
    'local_personalcourse/logretentiondays',
    get_string('logretentiondays', 'local_personalcourse'),
    get_string('logretentiondays_desc', 'local_personalcourse'),
    14,
    PARAM_INT
));
```

---

### **F. Security Considerations**

**Capabilities**:
```php
$capabilities = [
    'local/personalcourse:viewown' => [
        'riskbitmask' => 0,
        'captype' => 'read',
        'contextlevel' => CONTEXT_COURSE,
        'archetypes' => ['student' => CAP_ALLOW]
    ],
    'local/personalcourse:viewall' => [
        'riskbitmask' => RISK_PERSONAL,
        'captype' => 'read',
        'contextlevel' => CONTEXT_SYSTEM,
        'archetypes' => [
            'teacher' => CAP_ALLOW,
            'editingteacher' => CAP_ALLOW,
            'manager' => CAP_ALLOW
        ]
    ],
    'local/personalcourse:manage' => [
        'riskbitmask' => RISK_CONFIG | RISK_DATALOSS,
        'captype' => 'write',
        'contextlevel' => CONTEXT_SYSTEM,
        'archetypes' => [
            'editingteacher' => CAP_ALLOW,
            'manager' => CAP_ALLOW
        ]
    ]
];
```

**Input Validation**:
- All user IDs validated with `clean_param($userid, PARAM_INT)`
- All text inputs sanitized with `clean_param($text, PARAM_TEXT)`
- SQL queries use parameterized placeholders
- Capability checks before all operations

---

### **G. Performance Optimization**

**Database Indexes**:
- See "Database Schema" section for full index list

**Caching**:
```php
// Cache flag counts per user
$cache = \cache::make('local_personalcourse', 'flagcounts');
$cachekey = "flagcount_{$userid}";

if (!$count = $cache->get($cachekey)) {
    $count = $this->count_flags($userid);
    $cache->set($cachekey, $count);
}
```

**Bulk Operations**:
- Use bulk inserts/updates where possible
- Process in batches of 50

---

## Final Notes

### **✅ Ready for Implementation**

All architectural decisions finalized. No remaining questions.

### **📝 Implementation Order**

1. Phase 1: Core Infrastructure (Weeks 1-2)
2. Phase 2: Quiz Generation (Weeks 3-4)
3. Phase 3: Sync & Updates (Week 5)
4. Phase 4: Dashboard & UI (Weeks 6-7)
5. Phase 6: Polish & Testing (Weeks 9-10)
6. Phase 5: Homework Integration (Deferred)

### **🎯 Success Criteria**

- Personal course auto-generates on 2nd attempt (≥30%)
- Personal course auto-regenerates on 70% threshold
- Flag removal immediately drops question
- Dashboard shows all 50 students efficiently
- No performance issues with 50+ students

---

**END OF PLAN**

This document is finalized and ready for implementation. DO NOT START yet - awaiting your approval to proceed.
