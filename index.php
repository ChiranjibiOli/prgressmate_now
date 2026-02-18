<?php
// index.php - Landing page with session redirect
session_start();
if (isset($_SESSION['user_id'])) {
    if ($_SESSION['role'] === 'admin') {
        header("Location: admin/admin.php");
    } else {
        header("Location: dashboard.php");
    }
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ProgressMate - Track Your Academic Success</title>
    <link rel="stylesheet" href="assets/css/style.css">
    <link rel="stylesheet" href="assets/css/landing.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=Roboto:wght@400;500;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>

<body>
    <!-- Navigation -->
    <nav class="navbar">
        <div class="container">
            <div class="navbar-brand">
                <a href="index.php" class="logo">
                    <i class="fas fa-star logo-icon"></i>
                    <span class="logo-text">ProgressMate</span>
                </a>
            </div>

            <div class="navbar-menu" id="navbarMenu">
                <a href="index.php" class="nav-link active">Home</a>
                <a href="#about" class="nav-link">About</a>
                <a href="#contact" class="nav-link">Contact</a>
            </div>

            <div class="navbar-actions">
                <a href="login.php" class="nav-link">Login</a>
                <a href="register.php" class="btn btn-primary">Register</a>
            </div>

            <button class="navbar-toggle" id="navbarToggle">
                <i class="fas fa-bars"></i>
            </button>
        </div>
    </nav>

    <!-- Hero Section -->
    <section class="hero">
        <div class="container">
            <div class="hero-content">
                <h1 class="hero-title">Track. <span class="highlight">Improve.</span> Succeed.</h1>
                <p class="hero-subtitle">
                    Your ultimate partner for goal setting, progress tracking, and academic success.
                    Stay motivated, organized, and ahead in your academic journey with customizable goals tailored to your needs.
                </p>
                <div class="hero-actions">
                    <a href="Register.php" class="btn btn-primary btn-lg">
                        <i class="fas fa-rocket"></i> Start Your Journey
                    </a>
                    <a href="#features" class="btn btn-outline btn-lg">
                        <i class="fas fa-play-circle"></i> See How It Works
                    </a>
                </div>
                <div class="hero-stats">
                    <div class="stat">
                        <span class="stat-number">10,000+</span>
                        <span class="stat-label">Students</span>
                    </div>
                    <div class="stat">
                        <span class="stat-number">95%</span>
                        <span class="stat-label">Success Rate</span>
                    </div>
                    <div class="stat">
                        <span class="stat-number">4.8</span>
                        <span class="stat-label">Rating</span>
                    </div>
                </div>
            </div>
            <div class="hero-image">
                <img src="assets/images/hero-dashboard.png" alt="ProgressMate Dashboard" class="dashboard-preview">
            </div>
        </div>
    </section>

    <!-- Features Section -->
    <section id="features" class="section features">
        <div class="container">
            <div class="section-header">
                <h2 class="section-title">Powerful Features to Boost Your Learning</h2>
                <p class="section-subtitle">
                    ProgressMate offers customizable goal templates across key categories to help you track academic performance, build study habits, manage assignments, develop skills, and foster personal growth—all with real-time insights and achievement badges.
                </p>
            </div>

            <div class="features-grid">
                <div class="feature-card">
                    <div class="feature-icon">
                        <i class="fas fa-graduation-cap"></i>
                    </div>
                    <h3 class="feature-title">Academic Performance Tracking</h3>
                    <p class="feature-description">
                        Set goals to improve subjects, prepare for exams, or track attendance with weekly milestones and progress logs.
                    </p>
                </div>

                <div class="feature-card">
                    <div class="feature-icon">
                        <i class="fas fa-book-open"></i>
                    </div>
                    <h3 class="feature-title">Study Habits Building</h3>
                    <p class="feature-description">
                        Create routines like daily study time or Pomodoro sessions, with reminders and balance for rest to prevent burnout.
                    </p>
                </div>

                <div class="feature-card">
                    <div class="feature-icon">
                        <i class="fas fa-tasks"></i>
                    </div>
                    <h3 class="feature-title">Assignment Management</h3>
                    <p class="feature-description">
                        Break down tasks, prioritize deadlines, and incorporate feedback with step-by-step milestones for timely completion.
                    </p>
                </div>

                <div class="feature-card">
                    <div class="feature-icon">
                        <i class="fas fa-lightbulb"></i>
                    </div>
                    <h3 class="feature-title">Skill Development</h3>
                    <p class="feature-description">
                        Learn tools, improve writing, or practice public speaking with practice-based milestones and progress graphs.
                    </p>
                </div>

                <div class="feature-card">
                    <div class="feature-icon">
                        <i class="fas fa-heart"></i>
                    </div>
                    <h3 class="feature-title">Personal Growth</h3>
                    <p class="feature-description">
                        Incorporate wellness like exercise, meditation, or journaling, correlating with academic progress for holistic support.
                    </p>
                </div>

                <div class="feature-card">
                    <div class="feature-icon">
                        <i class="fas fa-trophy"></i>
                    </div>
                    <h3 class="feature-title">Achievement Badges & Insights</h3>
                    <p class="feature-description">
                        Earn badges for milestones and view performance trends with charts, predictions, and actionable reports.
                    </p>
                </div>
            </div>
        </div>
    </section>

    <!-- How It Works Section -->
    <section class="section how-it-works">
        <div class="container">
            <div class="section-header">
                <h2 class="section-title">How ProgressMate Works</h2>
                <p class="section-subtitle">Three simple steps to transform your academic journey with customizable goals</p>
            </div>

            <div class="steps">
                <div class="step">
                    <div class="step-number">1</div>
                    <h3 class="step-title">Choose & Customize Goals</h3>
                    <p class="step-description">Select from templates in categories like academic or wellness, and tailor to your needs with personal data.</p>
                </div>

                <div class="step">
                    <div class="step-number">2</div>
                    <h3 class="step-title">Track & Update Progress</h3>
                    <p class="step-description">Log daily actions, hit milestones, and see real-time updates with graphs and reminders.</p>
                </div>

                <div class="step">
                    <div class="step-number">3</div>
                    <h3 class="step-title">Earn Achievements</h3>
                    <p class="step-description">Complete goals to unlock badges, celebrate success, and gain insights for continued improvement.</p>
                </div>
            </div>
        </div>
    </section>

    <!-- Testimonials Section -->
    <section id="testimonials" class="section testimonials">
        <div class="container">
            <div class="section-header">
                <h2 class="section-title">What Students Say</h2>
                <p class="section-subtitle">Join thousands of students who transformed their academic journey</p>
            </div>

            <div class="testimonials-grid">
                <div class="testimonial-card">
                    <div class="testimonial-content">
                        <p>"ProgressMate's customizable goals helped me boost my math understanding with weekly quizzes—my grades improved without the stress!"</p>
                    </div>
                    <div class="testimonial-author">
                        <img src="https://i.pravatar.cc/50?img=1" alt="Sarah Johnson" class="author-avatar">
                        <div class="author-info">
                            <h4>Sarah Johnson</h4>
                            <p>Computer Science, Stanford University</p>
                        </div>
                    </div>
                </div>

                <div class="testimonial-card">
                    <div class="testimonial-content">
                        <p>"The study habits templates like Pomodoro kept me focused, and the badges made it fun to track my progress daily."</p>
                    </div>
                    <div class="testimonial-author">
                        <img src="https://i.pravatar.cc/50?img=2" alt="Michael Chen" class="author-avatar">
                        <div class="author-info">
                            <h4>Michael Chen</h4>
                            <p>Medicine, Johns Hopkins University</p>
                        </div>
                    </div>
                </div>

                <div class="testimonial-card">
                    <div class="testimonial-content">
                        <p>"Assignment management goals helped me break down projects and meet deadlines—earning badges felt rewarding!"</p>
                    </div>
                    <div class="testimonial-author">
                        <img src="https://i.pravatar.cc/50?img=3" alt="Emily Rodriguez" class="author-avatar">
                        <div class="author-info">
                            <h4>Emily Rodriguez</h4>
                            <p>Business Administration, NYU</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- About Section -->
    <section id="about" class="section about">
        <div class="container">
            <div class="section-header">
                <h2 class="section-title">About ProgressMate</h2>
                <p class="section-subtitle">Learn more about our mission and how we're helping students succeed</p>
            </div>
            <div class="about-content">
                <p>ProgressMate is a comprehensive academic tracking platform designed to help students, educators, and administrators manage tasks, track progress, and achieve academic excellence. Built with role-based access control, our system ensures secure and efficient management of student data and assignments.</p>
                <p>Our features include real-time progress tracking, deadline management, performance analytics, and collaborative tools, all powered by a robust PHP and MySQL backend. With customizable goal templates across categories like academic performance and personal growth, students can tailor their journey for maximum productivity.</p>
            </div>
        </div>
    </section>

    <!-- Contact Section -->
    <section id="contact" class="section contact">
        <div class="container">
            <div class="section-header">
                <h2 class="section-title">Contact Us</h2>
                <p class="section-subtitle">Get in touch for support, feedback, or inquiries</p>
            </div>

            <?php
            if (isset($_GET['success'])) {
                echo '<p class="success-message">' . htmlspecialchars($_GET['success']) . '</p>';
            }
            if (isset($_GET['error'])) {
                echo '<p class="error-message">' . htmlspecialchars($_GET['error']) . '</p>';
            }
            ?>
            <form class="contact-form" action="contact.php" method="POST">
                <div class="form-group">
                    <label for="name">Name</label>
                    <input type="text" id="name" name="name" required>
                </div>
                <div class="form-group">
                    <label for="email">Email</label>
                    <input type="email" id="email" name="email" required>
                </div>
                <div class="form-group">
                    <label for="subject">Subject</label>
                    <input type="text" id="subject" name="subject">
                </div>
                <div class="form-group">
                    <label for="message">Message</label>
                    <textarea id="message" name="message" rows="5" required></textarea>
                </div>
                <button type="submit" class="btn btn-primary">Send Message</button>
            </form>
        </div>
    </section>

    <!-- CTA Section -->
    <section class="section cta">
        <div class="container">
            <div class="cta-content">
                <h2 class="cta-title">Ready to Transform Your Academic Journey?</h2>
                <p class="cta-subtitle">Join 10,000+ students who are achieving their academic dreams with ProgressMate's customizable goals and insights</p>
                <div class="cta-actions">
                    <a href="register.php" class="btn btn-primary btn-lg">
                        <i class="fas fa-user-plus"></i> Start Free Trial
                    </a>
                    <a href="login.php" class="btn btn-outline btn-lg">
                        <i class="fas fa-sign-in-alt"></i> Existing User? Login
                    </a>
                </div>
                <p class="cta-note">No credit card required. Free 14-day trial.</p>
            </div>
        </div>
    </section>

    <!-- Footer -->
    <footer class="footer">
        <div class="container">
            <div class="footer-content">
                <div class="footer-section">
                    <a href="index.php" class="footer-logo">
                        <i class="fas fa-star"></i>
                        <span>ProgressMate</span>
                    </a>
                    <p class="footer-description">
                        Empowering students to achieve their academic goals through smart tracking and actionable insights.
                    </p>
                    <div class="social-links">
                        <a href="#" class="social-link"><i class="fab fa-twitter"></i></a>
                        <a href="#" class="social-link"><i class="fab fa-facebook"></i></a>
                        <a href="#" class="social-link"><i class="fab fa-instagram"></i></a>
                        <a href="#" class="social-link"><i class="fab fa-linkedin"></i></a>
                        <a href="#" class="social-link"><i class="fab fa-github"></i></a>
                    </div>
                </div>

                <div class="footer-section">
                    <h4 class="footer-heading">Product</h4>
                    <a href="dashboard.php" class="footer-link">Dashboard</a>
                    <a href="goals.php" class="footer-link">Goals</a>
                    <a href="achievements.php" class="footer-link">Achievements</a>
                    <a href="profile.php" class="footer-link">Profile</a>
                </div>

                <div class="footer-section">
                    <h4 class="footer-heading">Company</h4>
                    <a href="#about" class="footer-link">About Us</a>
                    <a href="#" class="footer-link">Careers</a>
                    <a href="#" class="footer-link">Blog</a>
                    <a href="#contact" class="footer-link">Contact Us</a>
                </div>

                <div class="footer-section">
                    <h4 class="footer-heading">Resources</h4>
                    <a href="#" class="footer-link">Help Center</a>
                    <a href="#" class="footer-link">Documentation</a>
                    <a href="#" class="footer-link">Community</a>
                    <a href="#" class="footer-link">Privacy Policy</a>
                    <a href="#" class="footer-link">Terms of Service</a>
                </div>
            </div>

            <div class="footer-bottom">
                <p>&copy; 2024 ProgressMate. All rights reserved.</p>
                <div class="footer-links-bottom">
                    <a href="#" class="footer-link">Privacy</a>
                    <a href="#" class="footer-link">Terms</a>
                    <a href="#" class="footer-link">Cookies</a>
                    <a href="#" class="footer-link">Sitemap</a>
                </div>
            </div>
        </div>
    </footer>
    <script src="assets/js/main.js"></script>
</body>

</html>