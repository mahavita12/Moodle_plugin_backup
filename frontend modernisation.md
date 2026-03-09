# Frontend Modernisation Plan — Modern Quiz UI

## Overview

Build a **modern, standalone quiz frontend** at `growminds.net/quiz/` that replaces the quiz attempt and review experience while keeping Moodle as the backend engine. The original Moodle quiz pages remain untouched as a permanent fallback.

## Architecture

```
┌────────────────────────────────┐          ┌─────────────────────────┐
│  Modern Frontend (Next.js)     │  REST    │  Moodle Backend         │
│  growminds.net/quiz/            │ ◄──────► │  growminds.net           │
│                                │  API     │                         │
│  Quiz Attempt Page             │          │  Quiz Engine            │
│  Quiz Review Page              │          │  Question Bank          │
│  Question Flags UI             │          │  Grading & Gradebook    │
│  Essay Feedback Cards          │          │  User Auth & Enrollment │
│  Structure Guides Panel        │          │  Custom Plugin APIs     │
└────────────────────────────────┘          └─────────────────────────┘
```

**Key Principle:** Both the old and new UIs coexist. They share the same Moodle backend — same quizzes, same grades, same users. No data migration needed.

---

## Supported Question Types

| Type | Moodle qtype | Rendering Complexity |
|------|-------------|---------------------|
| Multiple Choice | `multichoice` | Low — radio/checkbox inputs |
| Essay | `essay` | Low — textarea + file upload |
| Select Missing Words | `gapselect` | Medium — inline `<select>` dropdowns within text |

---

## Phase 1: Foundation (Week 1-2)

### 1.1 Project Setup
- Next.js 14+ with App Router, TypeScript, Tailwind CSS
- Host as a subdirectory on `growminds.net/quiz/` via nginx reverse proxy
- Environment config for Moodle API base URL and token management

### 1.2 Authentication Layer
- Moodle Web Services token-based auth (`core_auth_request_login_token` or manual token)
- Session management: store Moodle token in httpOnly cookie
- Login flow: redirect to Moodle login → callback with token → store → redirect to quiz
- Alternative: share Moodle session cookie (same domain, simpler)

### 1.3 Moodle API Integration Layer
- Create a TypeScript API client wrapping Moodle Web Service calls
- Key endpoints to integrate:

| Moodle Function | Purpose |
|----------------|---------|
| `mod_quiz_get_quizzes_by_courses` | List quizzes |
| `mod_quiz_get_attempt_data` | Get questions for current page |
| `mod_quiz_start_attempt` | Start a new attempt |
| `mod_quiz_process_attempt` | Submit answers / navigate pages |
| `mod_quiz_save_attempt` | Auto-save answers |
| `mod_quiz_get_attempt_review` | Get review data after submission |
| `mod_quiz_get_attempt_summary` | Get attempt summary |
| `core_question_get_random_question_summaries` | Question metadata |

### 1.4 Custom Web Service Plugin (`local_quizapi`)
- Expose additional data not available in core Moodle APIs:
  - Question flags (from `local_questionflags`)
  - Structure guides
  - Essay grading/feedback data (from `local_quizdashboard`)
  - Homework examples card data

---

## Phase 2: Quiz Attempt Page (Week 2-4)

### 2.1 Question Renderer Components

```
src/components/questions/
├── MultiChoiceQuestion.tsx    # Radio buttons (single) / Checkboxes (multi)
├── EssayQuestion.tsx          # Rich textarea + optional file upload
├── GapSelectQuestion.tsx      # Inline dropdowns within paragraph text
└── QuestionWrapper.tsx        # Shared card layout, flag button, number badge
```

### 2.2 Quiz Layout
- **Header**: Quiz title, timer (countdown), progress bar
- **Main Area**: Question card with clean typography, ample white space
- **Side Panel** (desktop): Question navigation grid with flag color indicators
- **Bottom Bar** (mobile): Previous / Next buttons, navigation drawer

### 2.3 Core Features
- **Auto-save**: Debounced save on every answer change via `mod_quiz_save_attempt`
- **Page Navigation**: Smooth transitions between question pages (no full reload)
- **Timer**: Real-time countdown synced with Moodle's server-side timer
- **Question Flagging**: Blue/Red flag buttons with reflection notes (calls `local_questionflags` API)
- **Structure Guides**: Slide-out panel for essay questions (if guide exists)

### 2.4 Design Direction
- Clean, modern card-based layout
- Soft shadows, rounded corners, subtle gradients
- Modern typography (Inter or similar)
- Micro-animations on transitions and interactions
- Dark mode support (optional, future)

---

## Phase 3: Quiz Review Page (Week 4-5)

### 3.1 Review Layout
- Question-by-question review with correct/incorrect indicators
- Color-coded feedback: green (correct), red (incorrect), amber (partial)
- Collapsible general feedback and specific feedback sections

### 3.2 Essay Feedback Integration
- Port the existing feedback summary card (from `quizdashboard` hook)
- Display: initial scores, criteria breakdown, improvement items, overall comments
- "Your Writing Journey" section with score progression
- "View Full Feedback" link to existing `viewfeedback.php`

### 3.3 Homework Examples Card
- Port the homework examples card for Language Use and Mechanics
- Original → Improved example pairs
- Essay revision display

---

## Phase 4: Polish & Deployment (Week 5-6)

### 4.1 Integration
- Nginx config: proxy `/quiz/` to the Next.js app
- "Try New Quiz UI ✨" button injected on original Moodle attempt page
- Deep linking: `growminds.net/quiz/attempt/{attemptid}` maps to Moodle attempt

### 4.2 Mobile Responsiveness
- Responsive breakpoints for tablet and phone
- Touch-friendly question interactions
- Bottom sheet navigation on mobile

### 4.3 Testing
- Test all three question types across multiple quizzes
- Verify grade submission matches Moodle's native grading
- Timer accuracy testing
- Auto-save reliability testing
- Edge cases: session timeout, network interruption, concurrent tabs

---

## Rollout Strategy

| Stage | Action | Risk |
|-------|--------|------|
| **1. Build** | Develop at `/quiz/` while original pages are untouched | Zero |
| **2. Internal Test** | Admin/teacher testing on real quizzes | Zero |
| **3. Soft Launch** | Add "Try new UI" button to original pages | Zero (opt-in) |
| **4. Gradual Switch** | Default new UI for select courses/students | Low (fallback available) |
| **5. Full Switch** | Redirect all quiz attempts to new frontend | Low (one-line rollback) |

**Rollback:** Remove the redirect — original Moodle pages are never modified or deleted.

---

## Tech Stack Summary

| Layer | Technology |
|-------|-----------|
| Framework | Next.js 14+ (App Router) |
| Language | TypeScript |
| Styling | Tailwind CSS |
| State | React Context + SWR for API caching |
| API | Moodle Web Services REST API |
| Auth | Moodle token (httpOnly cookie) |
| Hosting | Same server, nginx reverse proxy |
| Build | Vercel-compatible or self-hosted Node.js |

---

## Estimated Timeline

| Phase | Duration | Deliverable |
|-------|----------|-------------|
| Foundation | 1-2 weeks | Auth, API client, project scaffold |
| Quiz Attempt | 2-3 weeks | Functional modern quiz-taking experience |
| Quiz Review | 1-2 weeks | Modern review with feedback cards |
| Polish & Deploy | 1 week | Mobile, testing, soft launch |
| **Total** | **5-8 weeks** | **Full modern quiz frontend** |

---

## Open Questions

1. **Hosting**: Self-hosted Node.js on the same Cloudways server, or separate (e.g., Vercel)?
2. **Design**: Any reference UIs to draw from (Duolingo, Google Forms, Typeform, etc.)?
3. **Priority**: Start immediately, or after current plugin stabilisation?
