<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>TeamSphere | Orbital Workspace</title>
    <link rel="stylesheet" href="assets/css/main.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --deep-space: #0F0F1A;
            --nebula-purple: #6C4DF6;
            --stellar-blue: #4A90E2;
            --cosmic-pink: #FF6B9D;
            --neon-white: #E0E0FF;
            --galaxy-gold: #FFD700;
        }

        body {
            margin: 0;
            padding: 0;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background-color: var(--deep-space);
            color: var(--neon-white);
            overflow-x: hidden;
            background-image:
                radial-gradient(circle at 20% 30%, rgba(108, 77, 246, 0.15) 0%, transparent 25%),
                radial-gradient(circle at 80% 70%, rgba(74, 144, 226, 0.15) 0%, transparent 25%);
            background-size: cover;
            background-attachment: fixed;
            min-height: 100vh;
        }

        .stars {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            z-index: -1;
            background-image:
                radial-gradient(1px 1px at 20px 30px, white, rgba(0, 0, 0, 0)),
                radial-gradient(1px 1px at 40px 70px, white, rgba(0, 0, 0, 0)),
                radial-gradient(1px 1px at 90px 40px, white, rgba(0, 0, 0, 0));
            background-size: 100px 100px;
            animation: twinkle 5s infinite alternate;
        }

        @keyframes twinkle {
            0% {
                opacity: 0.3;
            }

            100% {
                opacity: 1;
            }
        }

        .container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 2rem;
            position: relative;
            z-index: 1;
        }

        header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 1.5rem 0;
            border-bottom: 1px solid rgba(224, 224, 255, 0.1);
            margin-bottom: 3rem;
        }

        .logo {
            display: flex;
            align-items: center;
            gap: 1rem;
        }

        .logo-icon {
            font-size: 2.5rem;
            color: var(--nebula-purple);
            text-shadow: 0 0 15px rgba(108, 77, 246, 0.5);
            animation: pulse 4s infinite alternate;
        }

        @keyframes pulse {
            0% {
                transform: scale(1);
            }

            50% {
                transform: scale(1.1);
            }

            100% {
                transform: scale(1);
            }
        }

        .logo-text {
            font-size: 2rem;
            font-weight: 700;
            background: linear-gradient(90deg, var(--nebula-purple), var(--stellar-blue));
            -webkit-background-clip: text;
            background-clip: text;
            color: transparent;
            text-shadow: 0 0 15px rgba(108, 77, 246, 0.3);
        }

        nav ul {
            display: flex;
            gap: 2rem;
            list-style: none;
        }

        nav a {
            color: var(--neon-white);
            text-decoration: none;
            font-weight: 500;
            position: relative;
            padding: 0.5rem 0;
            transition: all 0.3s ease;
        }

        nav a:hover {
            color: var(--cosmic-pink);
        }

        nav a::after {
            content: '';
            position: absolute;
            bottom: 0;
            left: 0;
            width: 0;
            height: 2px;
            background: linear-gradient(90deg, var(--nebula-purple), var(--cosmic-pink));
            transition: width 0.3s ease;
        }

        nav a:hover::after {
            width: 100%;
        }

        /* Hero Section */
        .hero {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            text-align: center;
            padding: 5rem 0;
            position: relative;
        }

        .hero h1 {
            font-size: 4rem;
            margin-bottom: 1.5rem;
            background: linear-gradient(90deg, var(--nebula-purple), var(--stellar-blue), var(--cosmic-pink));
            -webkit-background-clip: text;
            background-clip: text;
            color: transparent;
            text-shadow: 0 0 20px rgba(108, 77, 246, 0.3);
        }

        .hero p {
            font-size: 1.25rem;
            max-width: 700px;
            margin-bottom: 2.5rem;
            line-height: 1.6;
            opacity: 0.9;
        }

        .cta-buttons {
            display: flex;
            gap: 1.5rem;
            margin-bottom: 3rem;
        }

        .btn {
            padding: 0.8rem 2rem;
            border-radius: 50px;
            font-weight: 600;
            text-decoration: none;
            transition: all 0.3s ease;
            position: relative;
            overflow: hidden;
            z-index: 1;
            border: none;
            cursor: pointer;
        }

        .btn-primary {
            background: linear-gradient(135deg, var(--nebula-purple), var(--stellar-blue));
            color: white;
            box-shadow: 0 5px 15px rgba(108, 77, 246, 0.4);
        }

        .btn-primary:hover {
            transform: translateY(-3px);
            color: white;
            box-shadow: 0 8px 25px rgba(108, 77, 246, 0.6);
        }

        .btn-secondary {
            background: transparent;
            color: var(--neon-white);
            border: 2px solid rgba(224, 224, 255, 0.3);
        }

        .btn-secondary:hover {
            background: rgba(224, 224, 255, 0.1);
            border-color: var(--cosmic-pink);
            color: var(--cosmic-pink);
        }

        /* Solar System Animation */
        .solar-system {
            position: relative;
            width: 600px;
            height: 600px;
            margin: 3rem auto;
        }

        .sun {
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            width: 80px;
            height: 80px;
            background: radial-gradient(circle, var(--galaxy-gold), #FFA500);
            border-radius: 50%;
            box-shadow: 0 0 50px var(--galaxy-gold), 0 0 100px rgba(255, 215, 0, 0.5);
            z-index: 1;
            animation: sun-glow 3s infinite alternate;
        }

        @keyframes sun-glow {
            0% {
                box-shadow: 0 0 50px var(--galaxy-gold), 0 0 100px rgba(255, 215, 0, 0.5);
            }

            100% {
                box-shadow: 0 0 70px var(--galaxy-gold), 0 0 140px rgba(255, 215, 0, 0.7);
            }
        }

        .orbit {
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            border: 1px dashed rgba(224, 224, 255, 0.2);
            border-radius: 50%;
        }

        .planet {
            position: absolute;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: bold;
            color: var(--deep-space);
            cursor: pointer;
            transition: all 0.3s ease;
            z-index: 2;
        }

        .planet:hover {
            transform: scale(1.1);
            box-shadow: 0 0 20px currentColor;
        }

        .planet-1 {
            width: 40px;
            height: 40px;
            background: linear-gradient(135deg, var(--nebula-purple), #8A2BE2);
        }

        .planet-2 {
            width: 50px;
            height: 50px;
            background: linear-gradient(135deg, var(--stellar-blue), #00BFFF);
        }

        .planet-3 {
            width: 45px;
            height: 45px;
            background: linear-gradient(135deg, var(--cosmic-pink), #FF1493);
        }

        .planet-4 {
            width: 60px;
            height: 60px;
            background: linear-gradient(135deg, #00FA9A, #00CED1);
        }

        /* Features Section */
        .section-title {
            text-align: center;
            margin-bottom: 3rem;
            position: relative;
        }

        .section-title h2 {
            font-size: 2.5rem;
            display: inline-block;
            background: linear-gradient(90deg, var(--nebula-purple), var(--stellar-blue));
            -webkit-background-clip: text;
            background-clip: text;
            color: transparent;
            margin-bottom: 1rem;
        }

        .section-title::after {
            content: '';
            display: block;
            width: 100px;
            height: 3px;
            background: linear-gradient(90deg, var(--nebula-purple), var(--cosmic-pink));
            margin: 0 auto;
        }

        .features {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 2rem;
            margin: 5rem 0;
        }

        .feature-card {
            background: rgba(15, 15, 26, 0.7);
            border-radius: 15px;
            padding: 2rem;
            backdrop-filter: blur(10px);
            border: 1px solid rgba(224, 224, 255, 0.1);
            transition: all 0.3s ease;
            position: relative;
            overflow: hidden;
        }

        .feature-card:hover {
            transform: translateY(-10px);
            box-shadow: 0 15px 30px rgba(0, 0, 0, 0.3);
            border-color: rgba(108, 77, 246, 0.3);
        }

        .feature-card::before {
            content: '';
            position: absolute;
            top: -50%;
            left: -50%;
            width: 200%;
            height: 200%;
            background: radial-gradient(circle, rgba(108, 77, 246, 0.1) 0%, transparent 70%);
            transform: rotate(45deg);
            z-index: -1;
        }

        .feature-icon {
            font-size: 2.5rem;
            margin-bottom: 1.5rem;
            background: linear-gradient(135deg, var(--nebula-purple), var(--stellar-blue));
            -webkit-background-clip: text;
            background-clip: text;
            color: transparent;
        }

        .feature-card h3 {
            font-size: 1.5rem;
            margin-bottom: 1rem;
            color: var(--neon-white);
        }

        .feature-card p {
            opacity: 0.8;
            line-height: 1.6;
        }

        /* Testimonials Section */
        .testimonials {
            margin: 6rem 0;
        }

        .testimonial-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 2rem;
        }

        .testimonial-card {
            background: rgba(15, 15, 26, 0.7);
            border-radius: 15px;
            padding: 2rem;
            backdrop-filter: blur(10px);
            border: 1px solid rgba(224, 224, 255, 0.1);
            position: relative;
        }

        .testimonial-card::before {
            content: '\201C';
            font-size: 5rem;
            position: absolute;
            top: -1rem;
            left: 0.5rem;
            color: rgba(224, 224, 255, 0.1);
            font-family: serif;
            line-height: 1;
        }

        .testimonial-content {
            margin-bottom: 1.5rem;
            font-style: italic;
            position: relative;
            z-index: 1;
        }

        .testimonial-author {
            display: flex;
            align-items: center;
            gap: 1rem;
        }

        /* Testimonial Animations */
        .testimonial-card {
            transition: all 0.5s ease;
            transform: translateY(20px);
            opacity: 0;
        }

        .testimonial-card.animate {
            transform: translateY(0);
            opacity: 1;
        }

        /* Hover effects */
        .testimonial-card:hover {
            transform: translateY(-5px) !important;
            box-shadow: 0 10px 30px rgba(108, 77, 246, 0.2);
        }

        .author-avatar {
            width: 50px;
            height: 50px;
            border-radius: 50%;
            background: linear-gradient(135deg, var(--nebula-purple), var(--stellar-blue));
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: bold;
        }

        .author-info h4 {
            margin: 0;
            color: var(--neon-white);
        }

        .author-info p {
            margin: 0;
            opacity: 0.7;
            font-size: 0.9rem;
        }

        /* Pricing Section */
        .pricing {
            margin: 6rem 0;
        }

        .pricing-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 2rem;
        }

        .pricing-card {
            background: rgba(15, 15, 26, 0.7);
            border-radius: 15px;
            padding: 2rem;
            backdrop-filter: blur(10px);
            border: 1px solid rgba(224, 224, 255, 0.1);
            transition: all 0.3s ease;
            position: relative;
            overflow: hidden;
        }

        .pricing-card.popular {
            border: 1px solid var(--cosmic-pink);
            transform: translateY(-20px);
        }

        .pricing-card.popular::before {
            content: 'Popular';
            position: absolute;
            top: 15px;
            right: -30px;
            background: var(--cosmic-pink);
            color: var(--deep-space);
            padding: 0.25rem 2rem;
            transform: rotate(45deg);
            font-weight: bold;
            font-size: 0.8rem;
        }

        .pricing-header {
            margin-bottom: 2rem;
        }

        .pricing-title {
            font-size: 1.5rem;
            margin-bottom: 0.5rem;
            color: var(--neon-white);
        }

        .pricing-price {
            font-size: 2.5rem;
            margin-bottom: 0.5rem;
            background: linear-gradient(135deg, var(--nebula-purple), var(--stellar-blue));
            -webkit-background-clip: text;
            background-clip: text;
            color: transparent;
        }

        .pricing-period {
            opacity: 0.7;
        }

        .pricing-features {
            margin-bottom: 2rem;
        }

        .pricing-features li {
            margin-bottom: 0.75rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .pricing-features i {
            color: var(--stellar-blue);
        }

        .pricing-card:hover {
            transform: scale(1.03) !important;
            box-shadow: 0 15px 35px rgba(108, 77, 246, 0.2);
        }

        /* Pricing Animations */
        .pricing-card {
            transition: all 0.5s cubic-bezier(0.175, 0.885, 0.32, 1.275);
            transform: scale(0.95);
            opacity: 0;
        }

        .pricing-card.animate {
            transform: scale(1);
            opacity: 1;
        }

        .pricing-card.popular {
            transition-delay: 0.2s;
        }

        /* Footer */
        footer {
            text-align: center;
            padding: 3rem 0;
            margin-top: 5rem;
            border-top: 1px solid rgba(224, 224, 255, 0.1);
        }

        .footer-links {
            display: flex;
            justify-content: center;
            gap: 2rem;
            margin-bottom: 1.5rem;
        }

        .footer-links a {
            color: var(--neon-white);
            text-decoration: none;
            transition: color 0.3s ease;
        }

        .footer-links a:hover {
            color: var(--cosmic-pink);
        }

        .social-links {
            display: flex;
            justify-content: center;
            gap: 1.5rem;
            margin-bottom: 1.5rem;
        }

        .social-links a {
            color: var(--neon-white);
            font-size: 1.5rem;
            transition: color 0.3s ease;
        }

        .social-links a:hover {
            color: var(--cosmic-pink);
        }

        .copyright {
            opacity: 0.6;
            font-size: 0.9rem;
        }

        /* Responsive adjustments */
        @media (max-width: 768px) {
            .hero h1 {
                font-size: 2.5rem;
            }

            .solar-system {
                width: 300px;
                height: 300px;
            }

            nav ul {
                gap: 1rem;
            }

            .cta-buttons {
                flex-direction: column;
                gap: 1rem;
            }
        }
    </style>
</head>

<body>
    <div class="stars"></div>

    <div class="container">
        <header>
            <div class="logo">
                <div class="logo-icon">
                    <i class="fas fa-user-astronaut"></i>
                </div>
                <span class="logo-text">TeamSphere</span>
            </div>
            <nav>
                <ul>
                    <li><a href="#">Home</a></li>
                    <li><a href="#features">Features</a></li>
                    <li><a href="#testimonials">Testimonials</a></li>
                    <li><a href="#pricing">Pricing</a></li>
                    <li><a href="src/auth/auth.php">Login</a></li>
                </ul>
            </nav>
        </header>

        <!-- Hero Section -->
        <section class="hero">
            <h1>Orbital Workspace</h1>
            <p>Experience the future of employee management with TeamSphere's celestial-inspired platform. Navigate your
                professional universe with elegance and efficiency.</p>

            <div class="cta-buttons">
                <a href="src/auth/auth.php" class="btn btn-primary">Get Started</a>
                <a href="#features" class="btn btn-secondary">Explore Features</a>
            </div>

            <div class="solar-system">
                <div class="sun"></div>
                <div class="orbit" style="width: 300px; height: 300px;"></div>
                <div class="orbit" style="width: 400px; height: 400px;"></div>
                <div class="orbit" style="width: 500px; height: 500px;"></div>
                <div class="orbit" style="width: 600px; height: 600px;"></div>

                <div class="planet planet-1"
                    style="top: 50%; left: 50%; transform: translate(-50%, -50%) translateX(150px);">HR</div>
                <div class="planet planet-2"
                    style="top: 50%; left: 50%; transform: translate(-50%, -50%) translateX(200px);">Dev</div>
                <div class="planet planet-3"
                    style="top: 50%; left: 50%; transform: translate(-50%, -50%) translateX(250px);">Sales</div>
                <div class="planet planet-4"
                    style="top: 50%; left: 50%; transform: translate(-50%, -50%) translateX(300px);">Admin</div>
            </div>
        </section>

        <!-- Features Section -->
        <section id="features">
            <div class="section-title">
                <h2>Galactic Features</h2>
                <p>Discover the powerful tools that will revolutionize your workflow</p>
            </div>

            <div class="features">
                <div class="feature-card">
                    <div class="feature-icon">
                        <i class="fas fa-user-astronaut"></i>
                    </div>
                    <h3>User Authentication</h3>
                    <p>Secure login system with role-based access control. Your digital identity in our galaxy.</p>
                </div>

                <div class="feature-card">
                    <div class="feature-icon">
                        <i class="fas fa-rocket"></i>
                    </div>
                    <h3>Task Management</h3>
                    <p>Track tasks like comets streaking across your dashboard. Never miss a deadline again.</p>
                </div>

                <div class="feature-card">
                    <div class="feature-icon">
                        <i class="fas fa-satellite"></i>
                    </div>
                    <h3>Real-time Communication</h3>
                    <p>Instant messaging that connects your team across the digital cosmos.</p>
                </div>

                <div class="feature-card">
                    <div class="feature-icon">
                        <i class="	fas fa-chart-pie"></i>
                    </div>
                    <h3>Advanced Analytics</h3>
                    <p>Navigate through your performance metrics with our stellar reporting tools.</p>
                </div>

                <div class="feature-card">
                    <div class="feature-icon">
                        <i class="	fas fa-calendar-alt"></i>
                    </div>
                    <h3>Event Calendar</h3>
                    <p>Keep track of important dates with our celestial calendar system.</p>
                </div>

                <div class="feature-card">
                    <div class="feature-icon">
                        <i class="fas fa-file-shield"></i>
                    </div>
                    <h3>Document Management</h3>
                    <p>Store and share files securely in our cloud-based nebula storage.</p>
                </div>
            </div>
        </section>

        <!-- Testimonials Section -->
        <section id="testimonials" class="testimonials">
            <div class="section-title">
                <h2>Stellar Reviews</h2>
                <p>What our cosmic customers are saying</p>
            </div>

            <div class="testimonial-grid">
                <div class="testimonial-card">
                    <div class="testimonial-content">
                        <p>TeamSphere has completely transformed how we manage our intergalactic operations. The task
                            management system is out of this world!</p>
                    </div>
                    <div class="testimonial-author">
                        <div class="author-avatar">JD</div>
                        <div class="author-info">
                            <h4>Jane Doe</h4>
                            <p>CEO, Stellar Solutions</p>
                        </div>
                    </div>
                </div>

                <div class="testimonial-card">
                    <div class="testimonial-content">
                        <p>The employee directory makes finding team members as easy as spotting constellations. Our
                            productivity has skyrocketed!</p>
                    </div>
                    <div class="testimonial-author">
                        <div class="author-avatar">JS</div>
                        <div class="author-info">
                            <h4>John Smith</h4>
                            <p>HR Director, Nebula Corp</p>
                        </div>
                    </div>
                </div>

                <div class="testimonial-card">
                    <div class="testimonial-content">
                        <p>From the beautiful interface to the powerful features, TeamSphere is the perfect orbit for
                            our growing company's needs.</p>
                    </div>
                    <div class="testimonial-author">
                        <div class="author-avatar">AM</div>
                        <div class="author-info">
                            <h4>Alex Morgan</h4>
                            <p>CTO, Galaxy Tech</p>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <!-- Pricing Section -->
        <section id="pricing" class="pricing">
            <div class="section-title">
                <h2>Cosmic Pricing</h2>
                <p>Choose the perfect plan for your stellar team</p>
            </div>

            <div class="pricing-grid">
                <div class="pricing-card">
                    <div class="pricing-header">
                        <h3 class="pricing-title">Starter</h3>
                        <div class="pricing-price">$19</div>
                        <div class="pricing-period">per month</div>
                    </div>
                    <ul class="pricing-features">
                        <li><i class="fas fa-check"></i> Up to 10 users</li>
                        <li><i class="fas fa-check"></i> Basic task management</li>
                        <li><i class="fas fa-check"></i> Employee directory</li>
                        <li><i class="fas fa-check"></i> 5GB document storage</li>
                    </ul>
                    <a href="#" class="btn btn-secondary">Get Started</a>
                </div>

                <div class="pricing-card popular">
                    <div class="pricing-header">
                        <h3 class="pricing-title">Professional</h3>
                        <div class="pricing-price">$49</div>
                        <div class="pricing-period">per month</div>
                    </div>
                    <ul class="pricing-features">
                        <li><i class="fas fa-check"></i> Up to 50 users</li>
                        <li><i class="fas fa-check"></i> Advanced analytics</li>
                        <li><i class="fas fa-check"></i> Department management</li>
                        <li><i class="fas fa-check"></i> 50GB document storage</li>
                        <li><i class="fas fa-check"></i> Priority support</li>
                    </ul>
                    <a href="#" class="btn btn-primary">Get Started</a>
                </div>

                <div class="pricing-card">
                    <div class="pricing-header">
                        <h3 class="pricing-title">Enterprise</h3>
                        <div class="pricing-price">$99</div>
                        <div class="pricing-period">per month</div>
                    </div>
                    <ul class="pricing-features">
                        <li><i class="fas fa-check"></i> Unlimited users</li>
                        <li><i class="fas fa-check"></i> All features included</li>
                        <li><i class="fas fa-check"></i> Custom integrations</li>
                        <li><i class="fas fa-check"></i> 500GB document storage</li>
                        <li><i class="fas fa-check"></i> Dedicated account manager</li>
                    </ul>
                    <a href="#" class="btn btn-secondary">Get Started</a>
                </div>
            </div>
        </section>
    </div>

    <footer>
        <div class="footer-links">
            <a href="#">About</a>
            <a href="#">Contact</a>
            <a href="#">Privacy</a>
            <a href="#">Terms</a>
            <a href="#">Careers</a>
        </div>

        <div class="social-links">
            <a href="#"><i class="fab fa-twitter"></i></a>
            <a href="#"><i class="fab fa-linkedin"></i></a>
            <a href="#"><i class="fab fa-github"></i></a>
            <a href="#"><i class="fab fa-instagram"></i></a>
            <a href="#"><i class="fab fa-youtube"></i></a>
        </div>

        <p class="copyright">© 2023 TeamSphere. All rights reserved. Made with <i class="fas fa-heart"
                style="color: var(--cosmic-pink);"></i> in the cosmos.</p>
    </footer>

    <script>
        // Create dynamic stars
        document.addEventListener('DOMContentLoaded', function () {
            const starsContainer = document.querySelector('.stars');
            const starsCount = 200;

            for (let i = 0; i < starsCount; i++) {
                const star = document.createElement('div');
                star.style.position = 'absolute';
                star.style.width = `${Math.random() * 3}px`;
                star.style.height = star.style.width;
                star.style.backgroundColor = 'white';
                star.style.borderRadius = '50%';
                star.style.top = `${Math.random() * 100}%`;
                star.style.left = `${Math.random() * 100}%`;
                star.style.opacity = Math.random();
                star.style.animation = `twinkle ${2 + Math.random() * 3}s infinite alternate`;
                starsContainer.appendChild(star);
            }

            // Animate planets in orbits
            const planets = document.querySelectorAll('.planet');
            let angle1 = 0, angle2 = 90, angle3 = 180, angle4 = 270;

            function animatePlanets() {
                angle1 += 0.5;
                angle2 += 0.4;
                angle3 += 0.3;
                angle4 += 0.2;

                planets[0].style.transform = `translate(-50%, -50%) rotate(${angle1}deg) translateX(150px) rotate(-${angle1}deg)`;
                planets[1].style.transform = `translate(-50%, -50%) rotate(${angle2}deg) translateX(200px) rotate(-${angle2}deg)`;
                planets[2].style.transform = `translate(-50%, -50%) rotate(${angle3}deg) translateX(250px) rotate(-${angle3}deg)`;
                planets[3].style.transform = `translate(-50%, -50%) rotate(${angle4}deg) translateX(300px) rotate(-${angle4}deg)`;

                requestAnimationFrame(animatePlanets);
            }

            animatePlanets();

            // Add hover effect to planets
            planets.forEach(planet => {
                planet.addEventListener('mouseenter', function () {
                    const color = this.classList.contains('planet-1') ? 'var(--nebula-purple)' :
                        this.classList.contains('planet-2') ? 'var(--stellar-blue)' :
                            this.classList.contains('planet-3') ? 'var(--cosmic-pink)' :
                                '#00CED1';
                    this.style.boxShadow = `0 0 30px ${color}`;
                });

                planet.addEventListener('mouseleave', function () {
                    this.style.boxShadow = 'none';
                });
            });

            // Animate testimonials on scroll
            const testimonialObserver = new IntersectionObserver((entries) => {
                entries.forEach(entry => {
                    if (entry.isIntersecting) {
                        entry.target.classList.add('animate');
                    }
                });
            }, { threshold: 0.1 });

            document.querySelectorAll('.testimonial-card').forEach(card => {
                testimonialObserver.observe(card);
            });

            // Animate pricing cards on scroll with staggered delay
            const pricingObserver = new IntersectionObserver((entries) => {
                entries.forEach((entry, index) => {
                    if (entry.isIntersecting) {
                        setTimeout(() => {
                            entry.target.classList.add('animate');
                        }, index * 150);
                    }
                });
            }, { threshold: 0.1 });

            document.querySelectorAll('.pricing-card').forEach((card, index) => {
                pricingObserver.observe(card);
            });
        });
    </script>
</body>

</html>