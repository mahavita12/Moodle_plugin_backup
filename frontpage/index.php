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

$approach_title = get_string('approach_title', 'local_frontpage');
$approach_subtitle = get_string('approach_subtitle', 'local_frontpage');
$approach_desc = get_string('approach_desc', 'local_frontpage');

$programs_title = get_string('programs_title', 'local_frontpage');
$reading_club = get_string('reading_club', 'local_frontpage');
$reading_club_desc = get_string('reading_club_desc', 'local_frontpage');
$writing_club = get_string('writing_club', 'local_frontpage');
$writing_club_desc = get_string('writing_club_desc', 'local_frontpage');
$tutoring = get_string('tutoring', 'local_frontpage');
$tutoring_desc = get_string('tutoring_desc', 'local_frontpage');

$team_title = get_string('team_title', 'local_frontpage');
$testimonials_title = get_string('testimonials_title', 'local_frontpage');
$programs_desc = get_string('programs_desc', 'local_frontpage');
$team_desc = get_string('team_desc', 'local_frontpage');
$testimonials_desc = get_string('testimonials_desc', 'local_frontpage');

$cta_title = get_string('cta_title', 'local_frontpage');
$cta_subtitle = get_string('cta_subtitle', 'local_frontpage');
$cta_button = get_string('cta_button', 'local_frontpage');

$footer_copyright = get_string('footer_copyright', 'local_frontpage');

// URLs
$login_url = new moodle_url('/login/index.php');
$register_url = new moodle_url('/login/signup.php');

// Team members data
$team_members = [
    ['name' => 'Sarah Johnson', 'role' => 'Education Director', 'image' => 'profile1.jpg', 'bio' => 'Sarah brings over 15 years of experience in educational leadership. She holds a Master\'s degree in Education from Sydney University and is passionate about creating innovative learning environments that inspire students to reach their full potential.'],
    ['name' => 'Michael Chen', 'role' => 'Reading Specialist', 'image' => 'profile2.jpg', 'bio' => 'Michael is a certified literacy specialist with expertise in developing reading comprehension strategies. He has helped hundreds of students discover the joy of reading and improve their analytical skills through engaging literature programs.'],
    ['name' => 'Emily Williams', 'role' => 'Writing Coach', 'image' => 'profile3.jpg', 'bio' => 'Emily is an award-winning writing instructor who specializes in essay writing and creative expression. Her unique approach combines structured techniques with creative freedom, helping students find their authentic voice.'],
    ['name' => 'David Park', 'role' => 'Learning Advisor', 'image' => 'profile4.jpg', 'bio' => 'David is dedicated to helping students develop effective study habits and learning strategies. With a background in educational psychology, he creates personalized learning plans that address each student\'s unique needs and goals.'],
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
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">
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

    <!-- Our Approach Section -->
    <section class="gm-approach">
        <div class="gm-container">
            <div class="gm-approach-grid">
                <div class="gm-approach-image" style="background: url('<?php echo $CFG->wwwroot; ?>/local/frontpage/public/approach.jpg') center center / cover no-repeat;"></div>
                <div class="gm-approach-content">
                    <h2 class="gm-approach-title"><?php echo $approach_title; ?></h2>
                    <h3 class="gm-approach-subtitle"><?php echo $approach_subtitle; ?></h3>
                    <p class="gm-approach-desc"><?php echo $approach_desc; ?></p>
                </div>
            </div>
        </div>
    </section>

    <!-- Programs Section -->
    <section class="gm-programs" id="programs">
        <div class="gm-container">
            <h2 class="gm-section-title"><?php echo $programs_title; ?></h2>
            <p class="gm-section-desc"><?php echo $programs_desc; ?></p>
            <div class="gm-programs-grid">
                <div class="gm-program-card">
                    <div class="gm-program-image" style="background: url('<?php echo $CFG->wwwroot; ?>/local/frontpage/public/program1.jpg') center center / cover no-repeat;">
                    </div>
                    <div class="gm-program-content">
                        <h3 class="gm-program-title"><?php echo $reading_club; ?></h3>
                        <div class="gm-program-desc"><?php echo format_text($reading_club_desc, FORMAT_HTML); ?></div>
                        <a href="<?php echo $register_url; ?>" class="gm-btn gm-btn-secondary">Learn More</a>
                    </div>
                </div>
                <div class="gm-program-card">
                    <div class="gm-program-image" style="background: url('<?php echo $CFG->wwwroot; ?>/local/frontpage/public/program2.jpg') center center / cover no-repeat;">
                    </div>
                    <div class="gm-program-content">
                        <h3 class="gm-program-title"><?php echo $writing_club; ?></h3>
                        <div class="gm-program-desc"><?php echo format_text($writing_club_desc, FORMAT_HTML); ?></div>
                        <a href="<?php echo $register_url; ?>" class="gm-btn gm-btn-secondary">Learn More</a>
                    </div>
                </div>
                <div class="gm-program-card">
                    <div class="gm-program-image" style="background: url('<?php echo $CFG->wwwroot; ?>/local/frontpage/public/program3.jpg') center center / cover no-repeat;">
                    </div>
                    <div class="gm-program-content">
                        <h3 class="gm-program-title"><?php echo $tutoring; ?></h3>
                        <div class="gm-program-desc"><?php echo format_text($tutoring_desc, FORMAT_HTML); ?></div>
                        <a href="<?php echo $register_url; ?>" class="gm-btn gm-btn-secondary">Learn More</a>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <?php /* Team Section - Commented Out
    <section class="gm-team" id="team">
        <div class="gm-container">
            <h2 class="gm-section-title"><?php echo $team_title; ?></h2>
            <p class="gm-section-desc"><?php echo $team_desc; ?></p>
            <div class="gm-team-carousel">
                <div class="gm-team-track">
                    <?php foreach ($team_members as $index => $member): ?>
                    <div class="gm-team-card" onclick="openTeamModal(<?php echo $index; ?>)">
                        <div class="gm-team-avatar" style="background: url('<?php echo $CFG->wwwroot; ?>/local/frontpage/public/<?php echo $member['image']; ?>') center center / cover no-repeat;">
                        </div>
                        <h4 class="gm-team-name"><?php echo $member['name']; ?></h4>
                        <p class="gm-team-role"><?php echo $member['role']; ?></p>

                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </section>
    */ ?>

    <?php /* Testimonials Section - Commented Out
    <section class="gm-testimonials">
        <div class="gm-container">
            <h2 class="gm-section-title"><?php echo $testimonials_title; ?></h2>
            <p class="gm-section-desc"><?php echo $testimonials_desc; ?></p>
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
    */ ?>

    <!-- CTA Section -->
    <section class="gm-cta">
        <div class="gm-container">
            <h2 class="gm-cta-title"><?php echo $cta_title; ?></h2>
            <p class="gm-cta-subtitle"><?php echo $cta_subtitle; ?></p>
            <a href="<?php echo $register_url; ?>" class="gm-btn gm-btn-primary gm-btn-large"><?php echo $cta_button; ?></a>
        </div>
    </section>

    <!-- Team Modal -->
    <div id="teamModal" class="gm-modal">
        <div class="gm-modal-content">
            <span class="gm-modal-close" onclick="closeTeamModal()">&times;</span>
            <div class="gm-modal-avatar" id="modalAvatar"></div>
            <h3 class="gm-modal-name" id="modalName"></h3>
            <p class="gm-modal-role" id="modalRole"></p>
            <p class="gm-modal-bio" id="modalBio"></p>
        </div>
    </div>

    <!-- Footer -->
    <footer class="gm-footer">
        <div class="gm-container">
            <div class="gm-footer-grid">
                <div class="gm-footer-brand-section">
                    <div class="gm-footer-brand">
                        <span class="gm-logo-icon">🌱</span>
                        <span class="gm-logo-text">Grow Minds Study</span>
                    </div>
                    <p class="gm-footer-tagline">Empowering students to achieve academic excellence through personalized learning.</p>
                </div>
                <div class="gm-footer-links">
                    <h4 class="gm-footer-heading">Quick Links</h4>
                    <a href="#about" class="gm-footer-link">About Us</a>
                    <a href="#programs" class="gm-footer-link">Our Programs</a>
                    <a href="<?php echo $login_url; ?>" class="gm-footer-link">Student Login</a>
                </div>
                <div class="gm-footer-contact">
                    <h4 class="gm-footer-heading">Contact Us</h4>
                    <p class="gm-footer-info">📧 info@growmindsstudy.com.au</p>
                    <p class="gm-footer-info">📞 (02) 1234 5678</p>
                    <p class="gm-footer-info">📍 Sydney, Australia</p>
                </div>
            </div>
            <div class="gm-footer-bottom">
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

    // Team modal functionality
    const teamMembers = <?php echo json_encode($team_members); ?>;
    const wwwroot = '<?php echo $CFG->wwwroot; ?>';

    function openTeamModal(index) {
        const member = teamMembers[index];
        document.getElementById('modalAvatar').style.background = `url('${wwwroot}/local/frontpage/public/${member.image}') center center / cover no-repeat`;
        document.getElementById('modalName').textContent = member.name;
        document.getElementById('modalRole').textContent = member.role;
        document.getElementById('modalBio').textContent = member.bio;
        document.getElementById('teamModal').classList.add('active');
        document.body.style.overflow = 'hidden';
    }

    function closeTeamModal() {
        document.getElementById('teamModal').classList.remove('active');
        document.body.style.overflow = '';
    }

    // Close modal on outside click
    document.getElementById('teamModal').addEventListener('click', function(e) {
        if (e.target === this) {
            closeTeamModal();
        }
    });

    // Close modal on Escape key
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
            closeTeamModal();
        }
    });
    </script>
</body>
</html>
