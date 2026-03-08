<?php
// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Basic security headers
header("X-Frame-Options: DENY");
header("X-Content-Type-Options: nosniff");
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>TeamSphere | Dashboard</title>
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
            min-height: 100vh;
            position: relative;
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
            0% { opacity: 0.3; }
            100% { opacity: 1; }
        }

        /* Decorative planets */
        .planet-decoration {
            position: fixed;
            border-radius: 50%;
            filter: blur(40px);
            opacity: 0.2;
            z-index: -1;
            animation: float 15s infinite ease-in-out;
        }

        .planet-1 {
            background: var(--nebula-purple);
            width: 200px;
            height: 200px;
            top: 20%;
            left: 10%;
            animation-delay: 0s;
        }

        .planet-2 {
            background: var(--stellar-blue);
            width: 300px;
            height: 300px;
            bottom: 15%;
            right: 10%;
            animation-delay: 3s;
        }

        .planet-3 {
            background: var(--cosmic-pink);
            width: 250px;
            height: 250px;
            top: 40%;
            right: 15%;
            animation-delay: 6s;
        }

        @keyframes float {
            0%, 100% { transform: translateY(0) rotate(0deg); }
            50% { transform: translateY(-20px) rotate(5deg); }
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
            0% { transform: scale(1); }
            50% { transform: scale(1.1); }
            100% { transform: scale(1); }
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

        /* Logout button styling */
        .logout-btn {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.6rem 1.2rem;
            border-radius: 50px;
            background: rgba(224, 224, 255, 0.1);
            color: var(--neon-white);
            text-decoration: none;
            font-weight: 500;
            transition: all 0.3s ease;
            border: 1px solid rgba(224, 224, 255, 0.2);
        }

        .logout-btn:hover {
            background: rgba(255, 107, 157, 0.2);
            color: var(--cosmic-pink);
            border-color: var(--cosmic-pink);
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(255, 107, 157, 0.2);
        }

        .logout-btn i {
            transition: transform 0.3s ease;
        }

        .logout-btn:hover i {
            transform: rotate(180deg);
        }

        /* Dashboard content */
        .dashboard-content {
            background: rgba(15, 15, 26, 0.7);
            border-radius: 15px;
            padding: 3rem 2rem;
            backdrop-filter: blur(10px);
            border: 1px solid rgba(224, 224, 255, 0.1);
            text-align: center;
            transition: all 0.5s cubic-bezier(0.175, 0.885, 0.32, 1.275);
            position: relative;
            overflow: hidden;
        }

        .dashboard-content:hover {
            transform: translateY(-5px);
            box-shadow: 0 15px 30px rgba(108, 77, 246, 0.3);
            border-color: rgba(108, 77, 246, 0.3);
        }

        .dashboard-content::before {
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

        .dashboard-content h2 {
            font-size: 2rem;
            margin-bottom: 1.5rem;
            background: linear-gradient(90deg, var(--cosmic-pink), var(--nebula-purple));
            -webkit-background-clip: text;
            background-clip: text;
            color: transparent;
        }

        .dashboard-content p {
            line-height: 1.6;
            margin-bottom: 2rem;
            font-size: 1.1rem;
            opacity: 0.9;
            max-width: 700px;
            margin-left: auto;
            margin-right: auto;
        }

        /* Contact admin button */
        .contact-admin {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.8rem 2rem;
            background: linear-gradient(135deg, var(--nebula-purple), var(--stellar-blue));
            color: white;
            border-radius: 50px;
            text-decoration: none;
            font-weight: 600;
            transition: all 0.3s ease;
            position: relative;
            overflow: hidden;
            z-index: 1;
            border: none;
            cursor: pointer;
            box-shadow: 0 5px 15px rgba(108, 77, 246, 0.4);
        }

        .contact-admin:hover {
            transform: translateY(-3px);
            box-shadow: 0 8px 25px rgba(108, 77, 246, 0.6);
        }

        .contact-admin::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: linear-gradient(135deg, var(--stellar-blue), var(--cosmic-pink));
            opacity: 0;
            transition: opacity 0.3s ease;
            z-index: -1;
        }

        .contact-admin:hover::before {
            opacity: 1;
        }

        @media (max-width: 768px) {
            .logo-text {
                font-size: 1.5rem;
            }
            
            .dashboard-content {
                padding: 2rem 1rem;
            }
            
            .dashboard-content h2 {
                font-size: 1.5rem;
            }
        }
    </style>
</head>

<body>
    <div class="stars"></div>
    
    <!-- Decorative planets -->
    <div class="planet-decoration planet-1"></div>
    <div class="planet-decoration planet-2"></div>
    <div class="planet-decoration planet-3"></div>

    <div class="container">
        <header>
            <div class="logo">
                <div class="logo-icon">
                    <i class="fas fa-user-astronaut"></i>
                </div>
                <span class="logo-text">TeamSphere</span>
            </div>
            <a href="../src/auth/auth.php?action=logout" class="logout-btn">
                <i class="fas fa-sign-out-alt"></i> Logout
            </a>
        </header>

        <main class="dashboard-content">
            <h2><i class="fas fa-exclamation-triangle"></i> Access Notification</h2>
            <p>Welcome to TeamSphere. It appears your account permissions need to be configured.</p>
            <p>Please contact your system administrator to get proper access to the features you need.</p>
            <p>You've been directed to this page because either your role isn't properly set or you tried to access a restricted area.</p>
            <a href="mailto:admin@teamsphere.com" class="contact-admin">
                <i class="fas fa-envelope"></i> Contact Admin
            </a>
        </main>
    </div>

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
        });
    </script>
</body>
</html>