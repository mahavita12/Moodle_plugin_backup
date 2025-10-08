# Essays Master Plugin Development Plan

## Executive Summary

The **Essays Master** plugin will intercept the essay submission flow in Moodle quiz attempts to provide AI-powered feedback and enforce iterative revision cycles before allowing final submission. This plugin will leverage your existing OpenAI API integration and extend your current quiz dashboard functionality.

## 1. Plugin Architecture Overview

### Plugin Type & Location
- **Type**: `local` plugin (most flexible for intercepting submission flow)
- **Directory**: `/local/essaysmaster/`
- **Component**: `local_essaysmaster`

### Core Components
1. **Submission Interceptor** - Hooks into quiz submission process
2. **AI Feedback Engine** - Leverages existing OpenAI integration
3. **Text Highlighting System** - Visual feedback interface
4. **Copy/Paste Prevention** - Client-side enforcement
5. **Progress Tracking** - Completion rate monitoring
6. **Version Control** - Essay revision history
7. **Level Management** - Multi-tier feedback system

## 2. Database Schema Design

### New Tables Required

```sql
-- Main tracking table for essay master sessions
CREATE TABLE mdl_local_essaysmaster_sessions (
    id BIGINT(10) NOT NULL AUTO_INCREMENT,
    attempt_id BIGINT(10) NOT NULL,
    question_attempt_id BIGINT(10) NOT NULL,
    user_id BIGINT(10) NOT NULL,
    current_level TINYINT(2) DEFAULT 1,
    max_level TINYINT(2) DEFAULT 3,
    threshold_percentage DECIMAL(5,2) DEFAULT 80.00,
    status VARCHAR(20) DEFAULT 'active',
    session_start_time BIGINT(10) NOT NULL,
    session_end_time BIGINT(10) NULL,
    final_submission_allowed TINYINT(1) DEFAULT 0,
    timecreated BIGINT(10) NOT NULL,
    timemodified BIGINT(10) NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY attempt_question (attempt_id, question_attempt_id),
    KEY idx_user_status (user_id, status)
);

-- Essay versions and revisions
CREATE TABLE mdl_local_essaysmaster_versions (
    id BIGINT(10) NOT NULL AUTO_INCREMENT,
    session_id BIGINT(10) NOT NULL,
    version_number INT(5) NOT NULL,
    level_number TINYINT(2) NOT NULL,
    original_text LONGTEXT NOT NULL,
    revised_text LONGTEXT NULL,
    word_count INT(8) NOT NULL,
    character_count INT(10) NOT NULL,
    submission_time BIGINT(10) NOT NULL,
    is_initial TINYINT(1) DEFAULT 0,
    timecreated BIGINT(10) NOT NULL,
    PRIMARY KEY (id),
    KEY idx_session_version (session_id, version_number),
    FOREIGN KEY (session_id) REFERENCES mdl_local_essaysmaster_sessions(id)
);

-- AI feedback for each version
CREATE TABLE mdl_local_essaysmaster_feedback (
    id BIGINT(10) NOT NULL AUTO_INCREMENT,
    version_id BIGINT(10) NOT NULL,
    level_type VARCHAR(50) NOT NULL,
    feedback_html LONGTEXT NOT NULL,
    highlighted_areas LONGTEXT NULL,
    completion_score DECIMAL(5,2) NOT NULL,
    feedback_generated_time BIGINT(10) NOT NULL,
    api_response_time DECIMAL(6,3) NULL,
    timecreated BIGINT(10) NOT NULL,
    PRIMARY KEY (id),
    KEY idx_version_level (version_id, level_type),
    FOREIGN KEY (version_id) REFERENCES mdl_local_essaysmaster_versions(id)
);

-- Progress tracking for completion requirements
CREATE TABLE mdl_local_essaysmaster_progress (
    id BIGINT(10) NOT NULL AUTO_INCREMENT,
    session_id BIGINT(10) NOT NULL,
    level_number TINYINT(2) NOT NULL,
    requirement_type VARCHAR(50) NOT NULL,
    requirement_description TEXT NOT NULL,
    is_completed TINYINT(1) DEFAULT 0,
    completion_time BIGINT(10) NULL,
    completion_data LONGTEXT NULL,
    timecreated BIGINT(10) NOT NULL,
    timemodified BIGINT(10) NOT NULL,
    PRIMARY KEY (id),
    KEY idx_session_level (session_id, level_number),
    FOREIGN KEY (session_id) REFERENCES mdl_local_essaysmaster_sessions(id)
);

-- Configuration per quiz/question
CREATE TABLE mdl_local_essaysmaster_config (
    id BIGINT(10) NOT NULL AUTO_INCREMENT,
    quiz_id BIGINT(10) NULL,
    question_id BIGINT(10) NULL,
    course_id BIGINT(10) NULL,
    is_enabled TINYINT(1) DEFAULT 1,
    levels_config LONGTEXT NOT NULL,
    threshold_percentage DECIMAL(5,2) DEFAULT 80.00,
    max_revisions_per_level INT(3) DEFAULT 5,
    time_limit_per_level INT(8) NULL,
    copy_paste_prevention TINYINT(1) DEFAULT 1,
    timecreated BIGINT(10) NOT NULL,
    timemodified BIGINT(10) NOT NULL,
    PRIMARY KEY (id),
    KEY idx_quiz_question (quiz_id, question_id)
);
```

## 3. Technical Implementation Strategy

### Phase 1: Core Infrastructure (Week 1-2)

#### 1.1 Plugin Structure Setup
```
/local/essaysmaster/
├── version.php                 # Plugin version info
├── lib.php                     # Core hooks and functions
├── settings.php                # Admin settings
├── db/
│   ├── access.php             # Capability definitions
│   ├── install.xml            # Database schema
│   ├── upgrade.php            # Database upgrades
│   └── hooks.php              # Hook definitions
├── classes/
│   ├── submission_interceptor.php    # Main interception logic
│   ├── feedback_engine.php          # AI feedback wrapper
│   ├── progress_tracker.php         # Completion monitoring
│   ├── text_highlighter.php         # Highlighting utilities
│   ├── version_manager.php          # Essay version control
│   └── external/
│       ├── get_feedback.php         # Web service for AJAX
│       ├── save_revision.php        # Save essay revision
│       └── check_completion.php     # Check submission eligibility
├── amd/
│   ├── src/
│   │   ├── essay_interceptor.js     # Main JS controller
│   │   ├── text_highlighter.js     # Text highlighting
│   │   ├── copy_paste_blocker.js   # Copy/paste prevention
│   │   └── progress_monitor.js     # Progress tracking
│   └── build/                      # Compiled JS files
├── templates/
│   ├── feedback_panel.mustache     # Feedback display
│   ├── progress_indicator.mustache # Progress tracker
│   └── essay_interface.mustache    # Main interface
├── lang/en/
│   └── local_essaysmaster.php      # Language strings
└── tests/
    ├── submission_test.php         # Unit tests
    └── behat/                      # Acceptance tests
```

#### 1.2 Hook Integration Points
- **Before quiz submission**: `\core\hook\output\before_http_headers`
- **Quiz attempt processing**: `\mod_quiz\hook\attempt_submitted`
- **Question rendering**: `\core_question\hook\before_question_render`

### Phase 2: Submission Interception (Week 2-3)

#### 2.1 JavaScript Injection Strategy
```javascript
// Inject into quiz attempt page
require(['local_essaysmaster/essay_interceptor'], function(EssayInterceptor) {
    EssayInterceptor.init({
        attemptId: ATTEMPT_ID,
        questionIds: ESSAY_QUESTION_IDS,
        userId: USER_ID,
        sessionToken: SESSION_TOKEN
    });
});
```

#### 2.2 Form Submission Override
```javascript
// Override form submission for essay questions
document.addEventListener('DOMContentLoaded', function() {
    const quizForm = document.getElementById('responseform');
    if (quizForm) {
        quizForm.addEventListener('submit', function(e) {
            if (hasEssayQuestions() && !isSubmissionAllowed()) {
                e.preventDefault();
                showEssayMasterInterface();
            }
        });
    }
});
```

### Phase 3: AI Feedback Integration (Week 3-4)

#### 3.1 Feedback Engine Wrapper
```php
class feedback_engine {
    private $essay_grader;
    
    public function __construct() {
        $this->essay_grader = new \local_quizdashboard\essay_grader();
    }
    
    public function generate_level_feedback($essay_text, $level, $context) {
        $level_prompts = [
            1 => 'basic_grammar_spelling',
            2 => 'language_sophistication', 
            3 => 'advanced_structure_content'
        ];
        
        return $this->essay_grader->generate_essay_feedback($context, $level_prompts[$level]);
    }
}
```

#### 3.2 Level-Specific Prompts
```php
private function get_level_prompt($level) {
    switch($level) {
        case 1:
            return "Focus on basic grammar, spelling, and punctuation errors. Highlight specific mistakes and provide simple corrections.";
        case 2:
            return "Analyze language sophistication, word choice, and sentence variety. Suggest more advanced vocabulary and sentence structures.";
        case 3:
            return "Evaluate overall structure, argument development, and content depth. Provide high-level organizational and analytical feedback.";
    }
}
```

### Phase 4: Text Highlighting System (Week 4-5)

#### 4.1 Highlighting Implementation
```javascript
class TextHighlighter {
    constructor(textareaId, feedbackData) {
        this.textarea = document.getElementById(textareaId);
        this.feedbackData = feedbackData;
        this.initializeHighlighting();
    }
    
    initializeHighlighting() {
        // Create overlay div for highlighting
        this.overlay = this.createOverlayDiv();
        this.textarea.parentNode.insertBefore(this.overlay, this.textarea.nextSibling);
        
        // Sync scroll and content
        this.textarea.addEventListener('scroll', () => this.syncScroll());
        this.textarea.addEventListener('input', () => this.updateHighlights());
    }
    
    highlightText(ranges) {
        ranges.forEach(range => {
            this.addHighlight(range.start, range.end, range.type, range.feedback);
        });
    }
}
```

#### 4.2 Feedback-to-Highlight Mapping
```php
public function extract_highlight_ranges($feedback_html, $original_text) {
    $ranges = [];
    
    // Parse feedback for specific text references
    preg_match_all('/<span class="highlight-target">(.*?)<\/span>/s', $feedback_html, $matches);
    
    foreach($matches[1] as $target_text) {
        $position = strpos($original_text, $target_text);
        if ($position !== false) {
            $ranges[] = [
                'start' => $position,
                'end' => $position + strlen($target_text),
                'type' => 'error',
                'feedback' => $this->get_specific_feedback($target_text, $feedback_html)
            ];
        }
    }
    
    return $ranges;
}
```

### Phase 5: Copy/Paste Prevention (Week 5)

#### 5.1 Client-Side Enforcement
```javascript
class CopyPasteBlocker {
    constructor(textareaId, feedbackPanelId) {
        this.textarea = document.getElementById(textareaId);
        this.feedbackPanel = document.getElementById(feedbackPanelId);
        this.initializeBlocking();
    }
    
    initializeBlocking() {
        // Block paste events
        this.textarea.addEventListener('paste', (e) => {
            e.preventDefault();
            this.showPasteWarning();
        });
        
        // Block drag and drop from feedback panel
        this.feedbackPanel.addEventListener('dragstart', (e) => {
            e.preventDefault();
        });
        
        // Monitor for suspicious rapid text insertion
        this.textarea.addEventListener('input', (e) => {
            this.detectRapidInsertion(e);
        });
    }
    
    detectRapidInsertion(event) {
        const currentTime = Date.now();
        const textLength = event.target.value.length;
        
        if (this.lastInputTime && this.lastTextLength) {
            const timeDiff = currentTime - this.lastInputTime;
            const lengthDiff = textLength - this.lastTextLength;
            
            // Flag if more than 50 characters added in less than 100ms
            if (timeDiff < 100 && lengthDiff > 50) {
                this.flagSuspiciousActivity();
            }
        }
        
        this.lastInputTime = currentTime;
        this.lastTextLength = textLength;
    }
}
```

### Phase 6: Progress Tracking & Completion (Week 6)

#### 6.1 Completion Requirements Engine
```php
class progress_tracker {
    private $requirements = [
        1 => [
            'grammar_fixes' => ['target' => 5, 'weight' => 0.4],
            'spelling_fixes' => ['target' => 3, 'weight' => 0.3],
            'punctuation_fixes' => ['target' => 2, 'weight' => 0.3]
        ],
        2 => [
            'vocabulary_improvements' => ['target' => 4, 'weight' => 0.5],
            'sentence_variety' => ['target' => 3, 'weight' => 0.5]
        ],
        3 => [
            'structure_improvements' => ['target' => 2, 'weight' => 0.6],
            'content_depth' => ['target' => 1, 'weight' => 0.4]
        ]
    ];
    
    public function calculate_completion_score($session_id, $level) {
        $progress = $this->get_level_progress($session_id, $level);
        $total_score = 0;
        
        foreach($this->requirements[$level] as $req_type => $config) {
            $completion_rate = min(1.0, $progress[$req_type] / $config['target']);
            $total_score += $completion_rate * $config['weight'];
        }
        
        return $total_score * 100; // Return as percentage
    }
}
```

#### 6.2 Real-time Progress Updates
```javascript
class ProgressMonitor {
    constructor(sessionId, level) {
        this.sessionId = sessionId;
        this.level = level;
        this.updateInterval = setInterval(() => this.checkProgress(), 5000);
    }
    
    async checkProgress() {
        const response = await fetch(`/local/essaysmaster/ajax.php?action=check_progress`, {
            method: 'POST',
            body: JSON.stringify({
                session_id: this.sessionId,
                level: this.level
            })
        });
        
        const data = await response.json();
        this.updateProgressDisplay(data.completion_score);
        
        if (data.completion_score >= data.threshold) {
            this.enableNextLevel();
        }
    }
}
```

## 4. User Interface Design

### 4.1 Main Interface Layout
```html
<div id="essays-master-interface" class="essays-master-container">
    <!-- Progress Indicator -->
    <div class="progress-header">
        <div class="level-indicator">Level {currentLevel} of {maxLevels}</div>
        <div class="progress-bar">
            <div class="progress-fill" style="width: {completionPercentage}%"></div>
        </div>
        <div class="completion-text">{completionPercentage}% Complete</div>
    </div>
    
    <!-- Main Content Area -->
    <div class="main-content">
        <!-- Essay Editor -->
        <div class="essay-editor-panel">
            <h3>Your Essay</h3>
            <div class="editor-container">
                <textarea id="essay-text" class="essay-textarea">{currentEssayText}</textarea>
                <div id="highlight-overlay" class="highlight-overlay"></div>
            </div>
            <div class="essay-stats">
                Words: {wordCount} | Characters: {charCount}
            </div>
        </div>
        
        <!-- AI Feedback Panel -->
        <div class="feedback-panel">
            <h3>AI Feedback - Level {currentLevel}</h3>
            <div id="feedback-content" class="feedback-content">
                {feedbackHTML}
            </div>
            <button id="get-feedback-btn" class="btn btn-primary">
                Get AI Feedback
            </button>
        </div>
    </div>
    
    <!-- Action Buttons -->
    <div class="action-footer">
        <button id="save-draft-btn" class="btn btn-secondary">Save Draft</button>
        <button id="next-level-btn" class="btn btn-success" disabled>
            Proceed to Level {nextLevel}
        </button>
        <button id="submit-final-btn" class="btn btn-danger" disabled>
            Submit Final Essay
        </button>
    </div>
</div>
```

### 4.2 Level-Specific UI Adaptations
- **Level 1**: Focus indicators on grammar/spelling errors
- **Level 2**: Vocabulary and language sophistication highlights  
- **Level 3**: Structural and content organization feedback

## 5. Configuration & Admin Interface

### 5.1 Global Settings (Site Administration)
```php
// settings.php
$settings->add(new admin_setting_configcheckbox(
    'local_essaysmaster/enabled',
    get_string('enabled', 'local_essaysmaster'),
    get_string('enabled_desc', 'local_essaysmaster'),
    1
));

$settings->add(new admin_setting_configtext(
    'local_essaysmaster/default_threshold',
    get_string('default_threshold', 'local_essaysmaster'),
    get_string('default_threshold_desc', 'local_essaysmaster'),
    80,
    PARAM_INT
));
```

### 5.2 Per-Quiz Configuration
- Enable/disable Essays Master for specific quizzes
- Set completion thresholds per level
- Configure maximum revisions allowed
- Set time limits per level

## 6. Integration with Existing System

### 6.1 Leverage Current Infrastructure
- **Reuse OpenAI Integration**: Extend `local_quizdashboard\essay_grader` class
- **Database Synergy**: Reference existing `quiz_attempts` and `question_attempts` tables
- **User Management**: Integrate with Moodle's user system and capabilities
- **Styling**: Follow existing quiz dashboard UI patterns

### 6.2 Data Flow Integration
```
Quiz Attempt Start 
    ↓
Essays Master Detection
    ↓
Essay Interception
    ↓
Level 1 Feedback Loop
    ↓
Level 2 Feedback Loop  
    ↓
Level 3 Feedback Loop
    ↓
Final Submission Allowed
    ↓
Standard Moodle Processing
```

## 7. Enhanced Feature Suggestions

### 7.1 Advanced Analytics
- **Writing Pattern Analysis**: Track improvement over time
- **Comparison Reports**: Before/after essay quality metrics
- **Teacher Dashboard**: Overview of student progress across levels

### 7.2 Personalization Features
- **Adaptive Thresholds**: Adjust completion requirements based on student ability
- **Custom Level Configuration**: Allow teachers to modify level requirements
- **Student Profiles**: Remember previous weaknesses for targeted feedback

### 7.3 Collaboration Features
- **Peer Review Integration**: Optional peer feedback step
- **Teacher Intervention**: Alerts when students struggle with specific levels
- **Parent Notifications**: Progress reports for guardian involvement

### 7.4 Gamification Elements
- **Achievement Badges**: Unlock achievements for improvement milestones
- **Writing Streaks**: Consecutive successful submissions
- **Leaderboards**: Class-wide improvement metrics (anonymous)

## 8. Technical Considerations

### 8.1 Performance Optimization
- **Async Processing**: Use Moodle's task API for AI calls
- **Caching Strategy**: Cache AI responses for similar text patterns
- **Database Optimization**: Proper indexing and query optimization

### 8.2 Security Measures
- **CSRF Protection**: All AJAX calls use sesskey validation
- **Input Sanitization**: Proper cleaning of essay text and feedback
- **Capability Checks**: Ensure users can only access their own essays

### 8.3 Scalability Planning
- **API Rate Limiting**: Implement queuing for high-volume usage
- **Load Balancing**: Consider multiple OpenAI API keys for load distribution
- **Data Archival**: Plan for long-term storage of essay versions

## 9. Testing Strategy

### 9.1 Unit Testing
- Test submission interception logic
- Validate completion calculation algorithms
- Verify text highlighting accuracy

### 9.2 Integration Testing  
- Test with existing quiz dashboard functionality
- Verify database consistency across components
- Test API integration reliability

### 9.3 User Acceptance Testing
- Teacher workflow validation
- Student experience testing
- Performance testing under load

## 10. Deployment Plan

### Phase 1: Core Plugin (Weeks 1-3)
- Basic interception and database structure
- Simple AI feedback integration
- Admin configuration interface

### Phase 2: Enhanced Features (Weeks 4-6)  
- Text highlighting system
- Copy/paste prevention
- Progress tracking and completion

### Phase 3: Polish & Optimization (Weeks 7-8)
- UI/UX refinements
- Performance optimization
- Comprehensive testing

### Phase 4: Advanced Features (Future)
- Analytics dashboard
- Personalization features
- Gamification elements

This comprehensive plan provides a solid foundation for developing the Essays Master plugin while leveraging your existing sophisticated infrastructure. The modular approach allows for iterative development and testing, ensuring each component works seamlessly with your current system.