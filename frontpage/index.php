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
$reading_club_detail = get_string('reading_club_detail', 'local_frontpage');
$writing_club_detail = get_string('writing_club_detail', 'local_frontpage');
$tutoring_detail = get_string('tutoring_detail', 'local_frontpage');

// Programs data for modal
$programs = [
    ['title' => $reading_club, 'desc' => $reading_club_desc, 'detail' => $reading_club_detail, 'image' => 'programs1.jpg'],
    ['title' => $writing_club, 'desc' => $writing_club_desc, 'detail' => $writing_club_detail, 'image' => 'programs2.jpg'],
    ['title' => $tutoring, 'desc' => $tutoring_desc, 'detail' => $tutoring_detail, 'image' => 'programs3.jpg'],
];

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
    ['name' => 'Mia K.', 'grade' => 'Grade 11', 'text' => 'GrowMinds Academy helped me prepare for my exams with confidence. My grades have improved significantly since I joined.'],
    ['name' => 'Ethan R.', 'grade' => 'Grade 6', 'text' => 'The teachers make learning fun! I look forward to every session and have made so much progress in reading.'],
    ['name' => 'Ava C.', 'grade' => 'Grade 8', 'text' => 'The AI-powered feedback helps me understand my mistakes instantly. I have become a much more confident writer.'],
    ['name' => 'Lucas W.', 'grade' => 'Grade 12', 'text' => 'GrowMinds Academy prepared me perfectly for my final exams. The structured approach and dedicated support made all the difference.'],
];

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>GrowMinds Academy - We Achieve While Enjoying Studying</title>
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
                <img src="<?php echo $CFG->wwwroot; ?>/local/frontpage/public/logo_main.png" alt="GrowMinds Academy" class="gm-logo-img">
                <span class="gm-logo-text">GrowMinds Academy</span>
            </a>
            <div class="gm-nav-links">
                <a href="#" class="gm-nav-link active">Home</a>
                <a href="<?php echo $CFG->wwwroot; ?>/my" class="gm-nav-link">Classroom</a>
            </div>
            <a href="<?php echo $login_url; ?>" class="gm-btn gm-btn-primary">Log In</a>
        </div>
    </nav>

    <!-- Hero Section -->
    <section class="gm-hero" style="background: url('<?php echo $CFG->wwwroot; ?>/local/frontpage/public/hero_background.jpg') center center / cover no-repeat;">
        <div class="gm-hero-overlay"></div>
        <div class="gm-hero-content">
            <h1 class="gm-hero-title"><?php echo $hero_title; ?></h1>
            <p class="gm-hero-subtitle"><?php echo $hero_subtitle; ?></p>
            <a href="<?php echo $register_url; ?>" class="gm-btn gm-btn-primary gm-btn-large"><?php echo $hero_cta; ?></a>
        </div>
    </section>

    <!-- SVG Gradient Definition -->
    <svg width="0" height="0" style="position: absolute;">
        <defs>
            <linearGradient id="icon-gradient" x1="0%" y1="0%" x2="100%" y2="100%">
                <stop offset="0%" style="stop-color:#667eea" />
                <stop offset="50%" style="stop-color:#764ba2" />
                <stop offset="100%" style="stop-color:#43a047" />
            </linearGradient>
        </defs>
    </svg>

    <!-- Our Approach Section -->
    <section class="gm-approach">
        <div class="gm-container">
            <div class="gm-approach-grid">
                <div class="gm-approach-image" style="background: url('<?php echo $CFG->wwwroot; ?>/local/frontpage/public/approaches.png') center center / cover no-repeat;"></div>
                <div class="gm-approach-content">
                    <h2 class="gm-approach-title"><?php echo $approach_title; ?></h2>
                    <h3 class="gm-approach-subtitle"><?php echo $approach_subtitle; ?></h3>
                    <p class="gm-approach-desc"><?php echo $approach_desc; ?></p>
                </div>
            </div>
        </div>
    </section>

    <!-- Benefits Section -->
    <section class="gm-benefits" id="about">
        <div class="gm-container">
            <div class="gm-benefits-grid">
                <div class="gm-benefit-card">
                    <div class="gm-benefit-icon">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <circle cx="12" cy="12" r="10"></circle>
                            <circle cx="12" cy="12" r="6"></circle>
                            <circle cx="12" cy="12" r="2"></circle>
                        </svg>
                    </div>
                    <h3 class="gm-benefit-title"><?php echo $benefit1_title; ?></h3>
                    <p class="gm-benefit-desc"><?php echo $benefit1_desc; ?></p>
                </div>
                <div class="gm-benefit-card">
                    <div class="gm-benefit-icon">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M12 2L15.09 8.26L22 9.27L17 14.14L18.18 21.02L12 17.77L5.82 21.02L7 14.14L2 9.27L8.91 8.26L12 2Z"></path>
                        </svg>
                    </div>
                    <h3 class="gm-benefit-title"><?php echo $benefit2_title; ?></h3>
                    <p class="gm-benefit-desc"><?php echo $benefit2_desc; ?></p>
                </div>
                <div class="gm-benefit-card">
                    <div class="gm-benefit-icon">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M6 9H4.5a2.5 2.5 0 0 1 0-5H6"></path>
                            <path d="M18 9h1.5a2.5 2.5 0 0 0 0-5H18"></path>
                            <path d="M4 22h16"></path>
                            <path d="M10 14.66V17c0 .55-.47.98-.97 1.21C7.85 18.75 7 20.24 7 22"></path>
                            <path d="M14 14.66V17c0 .55.47.98.97 1.21C16.15 18.75 17 20.24 17 22"></path>
                            <path d="M18 2H6v7a6 6 0 0 0 12 0V2Z"></path>
                        </svg>
                    </div>
                    <h3 class="gm-benefit-title"><?php echo $benefit3_title; ?></h3>
                    <p class="gm-benefit-desc"><?php echo $benefit3_desc; ?></p>
                </div>
                <div class="gm-benefit-card">
                    <div class="gm-benefit-icon">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path>
                            <circle cx="9" cy="7" r="4"></circle>
                            <path d="M23 21v-2a4 4 0 0 0-3-3.87"></path>
                            <path d="M16 3.13a4 4 0 0 1 0 7.75"></path>
                        </svg>
                    </div>
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
            <p class="gm-section-desc"><?php echo $programs_desc; ?></p>
            <div class="gm-programs-grid">
                <?php foreach ($programs as $index => $program): ?>
                <div class="gm-program-card" onclick="openProgramModal(<?php echo $index; ?>)">
                    <div class="gm-program-image" style="background: url('<?php echo $CFG->wwwroot; ?>/local/frontpage/public/<?php echo $program['image']; ?>') center center / cover no-repeat;">
                    </div>
                    <div class="gm-program-content">
                        <h3 class="gm-program-title"><?php echo $program['title']; ?></h3>
                        <div class="gm-program-desc"><?php echo format_text($program['desc'], FORMAT_HTML); ?></div>
                    </div>
                </div>
                <?php endforeach; ?>
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

    <!-- Program Modal -->
    <div id="programModal" class="gm-modal">
        <div class="gm-modal-content gm-modal-program">
            <span class="gm-modal-close" onclick="closeProgramModal()">&times;</span>
            <div class="gm-modal-program-image" id="programModalImage"></div>
            <h3 class="gm-modal-name" id="programModalTitle"></h3>
            <div class="gm-modal-program-summary" id="programModalSummary"></div>
            <p class="gm-modal-bio" id="programModalDetail"></p>
        </div>
    </div>

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
                        <img src="<?php echo $CFG->wwwroot; ?>/local/frontpage/public/logo_main.png" alt="GrowMinds Academy" class="gm-logo-img">
                        <span class="gm-logo-text">GrowMinds Academy</span>
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
                    <p class="gm-footer-info">📧 support@growminds.net</p>
                    <p class="gm-footer-info">📞 0400 421 991</p>
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

    // Program modal functionality
    const programs = <?php echo json_encode($programs); ?>;
    const wwwroot = '<?php echo $CFG->wwwroot; ?>';

    function openProgramModal(index) {
        const program = programs[index];
        document.getElementById('programModalImage').style.background = `url('${wwwroot}/local/frontpage/public/${program.image}') center center / cover no-repeat`;
        document.getElementById('programModalTitle').textContent = program.title;
        document.getElementById('programModalSummary').innerHTML = program.desc;
        document.getElementById('programModalDetail').textContent = program.detail;
        document.getElementById('programModal').classList.add('active');
        document.body.style.overflow = 'hidden';
    }

    function closeProgramModal() {
        document.getElementById('programModal').classList.remove('active');
        document.body.style.overflow = '';
    }

    // Close program modal on outside click
    document.getElementById('programModal').addEventListener('click', function(e) {
        if (e.target === this) {
            closeProgramModal();
        }
    });

    // Team modal functionality
    const teamMembers = <?php echo json_encode($team_members); ?>;

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
            closeProgramModal();
        }
    });
    </script>
</body>
</html>
