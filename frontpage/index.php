<?php
require_once(__DIR__ . '/../../config.php');

// Logged-in users now see the frontpage (no redirect to dashboard)

$PAGE->set_url(new moodle_url('/local/frontpage/index.php'));
$PAGE->set_context(context_system::instance());
$PAGE->set_pagelayout('embedded');
$PAGE->set_title(get_string('pluginname', 'local_frontpage'));

// Get language strings
$hero_title = get_string('hero_title', 'local_frontpage');
$hero_subtitle = get_string('hero_subtitle', 'local_frontpage');
$hero_cta = get_string('hero_cta', 'local_frontpage');

$benefit1_title = get_string('benefit1_title', 'local_frontpage');
$benefit1_desc = get_string('benefit1_desc', 'local_frontpage');
$benefit2_title = get_string('benefit2_title', 'local_frontpage');
$benefit2_desc = get_string('benefit2_desc', 'local_frontpage');
$benefit3_title = get_string('benefit3_title', 'local_frontpage');
$benefit3_desc = get_string('benefit3_desc', 'local_frontpage');
$benefit4_title = get_string('benefit4_title', 'local_frontpage');
$benefit4_desc = get_string('benefit4_desc', 'local_frontpage');

$programs_title = get_string('programs_title', 'local_frontpage');
$reading_club = get_string('reading_club', 'local_frontpage');
$reading_club_desc = get_string('reading_club_desc', 'local_frontpage');
$writing_club = get_string('writing_club', 'local_frontpage');
$writing_club_desc = get_string('writing_club_desc', 'local_frontpage');
$tutoring = get_string('tutoring', 'local_frontpage');
$tutoring_desc = get_string('tutoring_desc', 'local_frontpage');

$team_title = get_string('team_title', 'local_frontpage');
$testimonials_title = get_string('testimonials_title', 'local_frontpage');

$cta_title = get_string('cta_title', 'local_frontpage');
$cta_subtitle = get_string('cta_subtitle', 'local_frontpage');
$cta_button = get_string('cta_button', 'local_frontpage');

$footer_copyright = get_string('footer_copyright', 'local_frontpage');

// URLs
$login_url = new moodle_url('/login/index.php');
$register_url = new moodle_url('/login/signup.php');

// Team members data
$team_members = [
    ['name' => 'Sarah Johnson', 'role' => 'Education Director'],
    ['name' => 'Michael Chen', 'role' => 'Reading Specialist'],
    ['name' => 'Emily Williams', 'role' => 'Writing Coach'],
    ['name' => 'David Park', 'role' => 'Learning Advisor'],
];

// Testimonials data
$testimonials = [
    ['name' => 'Emma S.', 'grade' => 'Grade 8', 'text' => 'The Reading Club helped me discover my love for literature. My comprehension skills have improved dramatically!'],
    ['name' => 'James L.', 'grade' => 'Grade 10', 'text' => 'The Writing Club transformed my essay writing. I went from struggling to achieving top marks consistently.'],
    ['name' => 'Sophie M.', 'grade' => 'Grade 7', 'text' => 'The personalized approach really works. My tutor understands exactly how I learn best.'],
    ['name' => 'Oliver T.', 'grade' => 'Grade 9', 'text' => 'I used to dread writing assignments, but now I actually enjoy them. The feedback I get is always so helpful and encouraging.'],
    ['name' => 'Mia K.', 'grade' => 'Grade 11', 'text' => 'Grow Minds helped me prepare for my exams with confidence. My grades have improved significantly since I joined.'],
    ['name' => 'Ethan R.', 'grade' => 'Grade 6', 'text' => 'The teachers make learning fun! I look forward to every session and have made so much progress in reading.'],
    ['name' => 'Ava C.', 'grade' => 'Grade 8', 'text' => 'The AI-powered feedback helps me understand my mistakes instantly. I have become a much more confident writer.'],
    ['name' => 'Lucas W.', 'grade' => 'Grade 12', 'text' => 'Grow Minds prepared me perfectly for my final exams. The structured approach and dedicated support made all the difference.'],
];

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Grow Minds Study - Trusted Educational Support</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?php echo $CFG->wwwroot; ?>/local/frontpage/styles.css?v=<?php echo time(); ?>">
</head>
<body>
    <!-- Navigation -->
    <nav class="gm-nav">
        <div class="gm-nav-container">
            <a href="<?php echo $CFG->wwwroot; ?>" class="gm-logo">
                <span class="gm-logo-icon">🌱</span>
                <span class="gm-logo-text">Grow Minds Study</span>
            </a>
            <div class="gm-nav-links">
                <a href="#" class="gm-nav-link active">Home</a>
                <a href="<?php echo $CFG->wwwroot; ?>/my" class="gm-nav-link">Classroom</a>
            </div>
            <a href="<?php echo $login_url; ?>" class="gm-btn gm-btn-primary">Log In</a>
        </div>
    </nav>

    <!-- Hero Section -->
    <section class="gm-hero" style="background: url('<?php echo $CFG->wwwroot; ?>/local/frontpage/public/growminds_hero.jpg') center center / cover no-repeat;">
        <div class="gm-hero-overlay"></div>
        <div class="gm-hero-content">
            <h1 class="gm-hero-title"><?php echo $hero_title; ?></h1>
            <p class="gm-hero-subtitle"><?php echo $hero_subtitle; ?></p>
            <a href="<?php echo $register_url; ?>" class="gm-btn gm-btn-primary gm-btn-large"><?php echo $hero_cta; ?></a>
        </div>
    </section>

    <!-- Benefits Section -->
    <section class="gm-benefits" id="about">
        <div class="gm-container">
            <div class="gm-benefits-grid">
                <div class="gm-benefit-card">
                    <div class="gm-benefit-icon">🎯</div>
                    <h3 class="gm-benefit-title"><?php echo $benefit1_title; ?></h3>
                    <p class="gm-benefit-desc"><?php echo $benefit1_desc; ?></p>
                </div>
                <div class="gm-benefit-card">
                    <div class="gm-benefit-icon">🌟</div>
                    <h3 class="gm-benefit-title"><?php echo $benefit2_title; ?></h3>
                    <p class="gm-benefit-desc"><?php echo $benefit2_desc; ?></p>
                </div>
                <div class="gm-benefit-card">
                    <div class="gm-benefit-icon">🏆</div>
                    <h3 class="gm-benefit-title"><?php echo $benefit3_title; ?></h3>
                    <p class="gm-benefit-desc"><?php echo $benefit3_desc; ?></p>
                </div>
                <div class="gm-benefit-card">
                    <div class="gm-benefit-icon">👨‍🏫</div>
                    <h3 class="gm-benefit-title"><?php echo $benefit4_title; ?></h3>
                    <p class="gm-benefit-desc"><?php echo $benefit4_desc; ?></p>
                </div>
            </div>
        </div>
    </section>

    <!-- Programs Section -->
    <section class="gm-programs" id="programs">
        <div class="gm-container">
            <h2 class="gm-section-title"><?php echo $programs_title; ?></h2>
            <div class="gm-programs-grid">
                <div class="gm-program-card">
                    <div class="gm-program-image gm-program-reading">
                        <div class="gm-program-icon">📚</div>
                    </div>
                    <div class="gm-program-content">
                        <h3 class="gm-program-title"><?php echo $reading_club; ?></h3>
                        <p class="gm-program-desc"><?php echo $reading_club_desc; ?></p>
                        <a href="<?php echo $register_url; ?>" class="gm-btn gm-btn-secondary">Learn More</a>
                    </div>
                </div>
                <div class="gm-program-card">
                    <div class="gm-program-image gm-program-writing">
                        <div class="gm-program-icon">✍️</div>
                    </div>
                    <div class="gm-program-content">
                        <h3 class="gm-program-title"><?php echo $writing_club; ?></h3>
                        <p class="gm-program-desc"><?php echo $writing_club_desc; ?></p>
                        <a href="<?php echo $register_url; ?>" class="gm-btn gm-btn-secondary">Learn More</a>
                    </div>
                </div>
                <div class="gm-program-card">
                    <div class="gm-program-image gm-program-tutoring">
                        <div class="gm-program-icon">🎓</div>
                    </div>
                    <div class="gm-program-content">
                        <h3 class="gm-program-title"><?php echo $tutoring; ?></h3>
                        <p class="gm-program-desc"><?php echo $tutoring_desc; ?></p>
                        <a href="<?php echo $register_url; ?>" class="gm-btn gm-btn-secondary">Learn More</a>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- Team Section -->
    <section class="gm-team" id="team">
        <div class="gm-container">
            <h2 class="gm-section-title"><?php echo $team_title; ?></h2>
            <div class="gm-team-carousel">
                <div class="gm-team-track">
                    <?php foreach ($team_members as $member): ?>
                    <div class="gm-team-card">
                        <div class="gm-team-avatar">
                            <span class="gm-team-avatar-placeholder">👤</span>
                        </div>
                        <h4 class="gm-team-name"><?php echo $member['name']; ?></h4>
                        <p class="gm-team-role"><?php echo $member['role']; ?></p>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </section>

    <!-- Testimonials Section -->
    <section class="gm-testimonials">
        <div class="gm-container">
            <h2 class="gm-section-title"><?php echo $testimonials_title; ?></h2>
            <div class="gm-testimonials-carousel">
                <div class="gm-testimonials-track">
                    <?php foreach ($testimonials as $testimonial): ?>
                    <div class="gm-testimonial-card">
                        <div class="gm-testimonial-quote">"</div>
                        <p class="gm-testimonial-text"><?php echo $testimonial['text']; ?></p>
                        <div class="gm-testimonial-author">
                            <span class="gm-testimonial-name"><?php echo $testimonial['name']; ?></span>
                            <span class="gm-testimonial-grade"><?php echo $testimonial['grade']; ?></span>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </section>

    <!-- CTA Section -->
    <section class="gm-cta">
        <div class="gm-container">
            <h2 class="gm-cta-title"><?php echo $cta_title; ?></h2>
            <p class="gm-cta-subtitle"><?php echo $cta_subtitle; ?></p>
            <a href="<?php echo $register_url; ?>" class="gm-btn gm-btn-primary gm-btn-large"><?php echo $cta_button; ?></a>
        </div>
    </section>

    <!-- Footer -->
    <footer class="gm-footer">
        <div class="gm-container">
            <div class="gm-footer-content">
                <div class="gm-footer-brand">
                    <span class="gm-logo-icon">🌱</span>
                    <span class="gm-logo-text">Grow Minds Study</span>
                </div>
                <a href="<?php echo $register_url; ?>" class="gm-btn gm-btn-outline"><?php echo $cta_button; ?></a>
                <p class="gm-footer-copyright"><?php echo $footer_copyright; ?></p>
            </div>
        </div>
    </footer>

    <script>
    document.addEventListener('DOMContentLoaded', function() {
        // Auto-scroll carousels
        const carousels = document.querySelectorAll('.gm-team-track, .gm-testimonials-track');
        carousels.forEach(track => {
            let scrollAmount = 0;
            const cardWidth = track.querySelector('div')?.offsetWidth || 300;
            const maxScroll = track.scrollWidth - track.parentElement.offsetWidth;
            
            setInterval(() => {
                scrollAmount += cardWidth + 24;
                if (scrollAmount > maxScroll) {
                    scrollAmount = 0;
                }
                track.style.transform = `translateX(-${scrollAmount}px)`;
            }, 4000);
        });

        // Smooth scroll for nav links
        document.querySelectorAll('.gm-nav-link[href^="#"]').forEach(link => {
            link.addEventListener('click', function(e) {
                e.preventDefault();
                const target = document.querySelector(this.getAttribute('href'));
                if (target) {
                    target.scrollIntoView({ behavior: 'smooth' });
                }
            });
        });
    });
    </script>
</body>
</html>
