<?php
session_start();

// Check if user came from verification process
if (!isset($_SESSION['verification_success']) || !isset($_SESSION['verified_email'])) {
    header("Location: signup.php");
    exit;
}

$verified_email = $_SESSION['verified_email'];

// Clear the verification success session data
unset($_SESSION['verification_success']);
unset($_SESSION['verified_email']);
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Verification Success - BULSU OnTheJob Tracker</title>
    <link rel="icon" type="image/png" href="reqsample/bulsu12.png">
    <link rel="shortcut icon" type="image/png" href="reqsample/bulsu12.png">
    <!-- Tailwind CSS CDN -->
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
    tailwind.config = {
        theme: {
            extend: {
                colors: {
                    'bulsu-maroon': '#800000',     // Primary Maroon
                    'bulsu-dark-maroon': '#6B1028',// Dark shade ng maroon
                    'bulsu-gold': '#DAA520',       // Official Gold
                    'bulsu-light-gold': '#F4E4BC', // Accent light gold
                    'bulsu-white': '#FFFFFF'       // Supporting White
                }
            }
        }
    }
    </script>
    <style>
        @keyframes pulse {
            0%, 100% {
                transform: scale(1);
            }
            50% {
                transform: scale(1.05);
            }
        }

        @keyframes ripple {
            0% {
                transform: scale(0.8);
                opacity: 1;
            }
            100% {
                transform: scale(1.2);
                opacity: 0;
            }
        }

        @keyframes fadeInUp {
            from {
                opacity: 0;
                transform: translateY(30px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .animate-pulse-slow {
            animation: pulse 2s ease-in-out infinite;
        }

        .animate-ripple {
            animation: ripple 2s ease-in-out infinite;
        }

        .animate-fadeInUp {
            animation: fadeInUp 0.6s ease-out forwards;
        }

        .animate-delay-100 { animation-delay: 0.1s; }
        .animate-delay-200 { animation-delay: 0.2s; }
        .animate-delay-300 { animation-delay: 0.3s; }
        .animate-delay-400 { animation-delay: 0.4s; }
        .animate-delay-500 { animation-delay: 0.5s; }

        @keyframes fall {
            to {
                transform: translateY(100vh) rotate(360deg);
            }
        }
    </style>
</head>

<body class="bg-gray-100 text-gray-800 min-h-screen flex flex-col">
    <header class="bg-gradient-to-r from-bulsu-maroon to-bulsu-dark-maroon text-white shadow-lg">
        <nav class="max-w-6xl mx-auto flex items-center justify-between px-4 py-4 relative">
            <div class="flex items-center">
                <!-- BULSU Logos -->
                             <img src="reqsample/bulsu12.png" alt="BULSU Logo 2" class="w-20 h-20">

                <!-- Brand Name -->
                <div class="flex items-center font-bold text-xl">
                    <span>OnTheJob</span>
                    <span class="ml-2">Tracker</span>
                    <span class="mx-4 font-bold text-bulsu-gold">|||</span>
                </div>
            </div>
            <!-- Hamburger Button (Mobile) -->
            <button id="menu-btn" class="md:hidden block focus:outline-none z-50">
                <svg class="w-7 h-7" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M4 6h16M4 12h16M4 18h16" />
                </svg>
            </button>
            <!-- Navigation Links (Desktop only) -->
            <ul id="nav-links" class="hidden md:flex space-x-8 font-medium">
                <li><a href="index.php#features" class="hover:text-bulsu-gold transition">Features</a></li>  
                <li><a href="index.php#stakeholders" class="hover:text-bulsu-gold transition">Stakeholders</a></li>
                <li><a href="index.php#contact" class="hover:text-bulsu-gold transition">Contact</a></li>
            </ul>
            <div class="hidden md:flex space-x-4">
                <a href="login.php" class="bg-bulsu-gold bg-opacity-20 border border-bulsu-gold border-opacity-50 rounded px-4 py-2 font-medium hover:bg-opacity-30 transition text-bulsu-gold">Login</a>
                <a href="signup.php" class="bg-bulsu-gold text-bulsu-maroon rounded px-4 py-2 font-medium hover:bg-yellow-400 transition">Sign Up</a>
            </div>
            <!-- Mobile Menu -->
            <div id="mobile-menu" class="md:hidden hidden absolute top-full left-0 w-full bg-bulsu-maroon z-50 px-4 pb-4 shadow-lg">
                <ul class="flex flex-col space-y-2 font-medium pt-4">
                    <li><a href="index.php#features" class="hover:text-bulsu-gold block py-2 text-white transition">Features</a></li>
                    <li><a href="index.php#stakeholders" class="hover:text-bulsu-gold block py-2 text-white transition">Stakeholders</a></li>
                    <li><a href="index.php#contact" class="hover:text-bulsu-gold block py-2 text-white transition">Contact</a></li>
                </ul>
                <div class="flex flex-col space-y-2 mt-4">
                    <a href="login.php" class="bg-bulsu-gold bg-opacity-20 border border-bulsu-gold border-opacity-50 rounded px-4 py-2 font-medium text-bulsu-gold hover:bg-opacity-30 transition">Login</a>
                    <a href="signup.php" class="bg-bulsu-gold text-bulsu-maroon rounded px-4 py-2 font-medium hover:bg-yellow-400 transition text-center">Sign Up</a>
                </div>
            </div>
        </nav>
    </header>

    <main class="flex-1 flex items-center justify-center px-4 py-8">
        <div class="bg-white bg-opacity-95 rounded-2xl shadow-2xl p-8 md:p-16 max-w-2xl w-full text-center relative overflow-hidden animate-fadeInUp">
            
            <!-- BULSU Header -->
            <div class="mb-8">
                <div class="mb-6">
                    <h3 class="text-bulsu-gold font-semibold text-lg mb-2">Bulacan State University</h3>
                    <div class="w-24 h-1 bg-bulsu-gold mx-auto rounded"></div>
                </div>
            </div>

            <!-- Success Icon -->
            <div class="relative mx-auto mb-8">
                <div class="w-32 h-32 bg-gradient-to-br from-green-500 to-green-600 rounded-full flex items-center justify-center animate-pulse-slow relative">
                    <div class="absolute w-36 h-36 border-4 border-green-300 border-opacity-30 rounded-full animate-ripple"></div>
                    <svg class="w-16 h-16 fill-white" viewBox="0 0 24 24">
                        <path d="M9 16.17L4.83 12l-1.42 1.41L9 19 21 7l-1.41-1.41L9 16.17z"/>
                    </svg>
                </div>
            </div>

            <!-- Success Header -->
            <div class="mb-8 animate-fadeInUp animate-delay-100">
                <h1 class="text-bulsu-maroon text-3xl md:text-4xl font-bold mb-4">🎉 Verification Complete!</h1>
                <p class="text-gray-600 text-lg md:text-xl leading-relaxed">Congratulations! Your email has been successfully verified. You can now access all features of the BULSU OJT Performance Monitoring System.</p>
            </div>

            <!-- Verified Email -->
            <div class="bg-gradient-to-r from-bulsu-light-gold to-yellow-50 border-l-4 border-bulsu-gold rounded-xl p-6 mb-10 relative animate-fadeInUp animate-delay-200">
                <div class="absolute -top-2 right-5 text-3xl">📧</div>
                <p class="text-gray-700 mb-2">Successfully verified email:</p>
                <strong class="text-bulsu-maroon text-xl font-semibold break-all"><?php echo htmlspecialchars($verified_email); ?></strong>
            </div>

            <!-- Features Section -->
            <div class="bg-gray-50 rounded-xl p-8 mb-10 animate-fadeInUp animate-delay-300">
                <h3 class="text-bulsu-maroon text-2xl font-semibold mb-6 flex items-center justify-center gap-2">
                    🚀 What's Next?
                </h3>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div class="flex items-center gap-3 p-3 bg-white rounded-lg border-l-4 border-bulsu-gold">
                        <div class="w-6 h-6 bg-bulsu-gold rounded-full flex items-center justify-center flex-shrink-0">
                            <svg class="w-3 h-3 fill-white" viewBox="0 0 24 24">
                                <path d="M9 16.17L4.83 12l-1.42 1.41L9 19 21 7l-1.41-1.41L9 16.17z"/>
                            </svg>
                        </div>
                        <span class="text-gray-700 font-medium">Access your student dashboard</span>
                    </div>
                    <div class="flex items-center gap-3 p-3 bg-white rounded-lg border-l-4 border-bulsu-gold">
                        <div class="w-6 h-6 bg-bulsu-gold rounded-full flex items-center justify-center flex-shrink-0">
                            <svg class="w-3 h-3 fill-white" viewBox="0 0 24 24">
                                <path d="M9 16.17L4.83 12l-1.42 1.41L9 19 21 7l-1.41-1.41L9 16.17z"/>
                            </svg>
                        </div>
                        <span class="text-gray-700 font-medium">Track your OJT progress</span>
                    </div>
                    <div class="flex items-center gap-3 p-3 bg-white rounded-lg border-l-4 border-bulsu-gold">
                        <div class="w-6 h-6 bg-bulsu-gold rounded-full flex items-center justify-center flex-shrink-0">
                            <svg class="w-3 h-3 fill-white" viewBox="0 0 24 24">
                                <path d="M9 16.17L4.83 12l-1.42 1.41L9 19 21 7l-1.41-1.41L9 16.17z"/>
                            </svg>
                        </div>
                        <span class="text-gray-700 font-medium">Submit daily time records</span>
                    </div>
                    <div class="flex items-center gap-3 p-3 bg-white rounded-lg border-l-4 border-bulsu-gold">
                        <div class="w-6 h-6 bg-bulsu-gold rounded-full flex items-center justify-center flex-shrink-0">
                            <svg class="w-3 h-3 fill-white" viewBox="0 0 24 24">
                                <path d="M9 16.17L4.83 12l-1.42 1.41L9 19 21 7l-1.41-1.41L9 16.17z"/>
                            </svg>
                        </div>
                        <span class="text-gray-700 font-medium">Communicate with supervisors</span>
                    </div>
                    <div class="flex items-center gap-3 p-3 bg-white rounded-lg border-l-4 border-bulsu-gold">
                        <div class="w-6 h-6 bg-bulsu-gold rounded-full flex items-center justify-center flex-shrink-0">
                            <svg class="w-3 h-3 fill-white" viewBox="0 0 24 24">
                                <path d="M9 16.17L4.83 12l-1.42 1.41L9 19 21 7l-1.41-1.41L9 16.17z"/>
                            </svg>
                        </div>
                        <span class="text-gray-700 font-medium">View performance evaluations</span>
                    </div>
                    <div class="flex items-center gap-3 p-3 bg-white rounded-lg border-l-4 border-bulsu-gold">
                        <div class="w-6 h-6 bg-bulsu-gold rounded-full flex items-center justify-center flex-shrink-0">
                            <svg class="w-3 h-3 fill-white" viewBox="0 0 24 24">
                                <path d="M9 16.17L4.83 12l-1.42 1.41L9 19 21 7l-1.41-1.41L9 16.17z"/>
                            </svg>
                        </div>
                        <span class="text-gray-700 font-medium">Generate OJT reports</span>
                    </div>
                </div>
            </div>

            <!-- Action Buttons -->
            <div class="flex flex-col md:flex-row gap-4 justify-center mb-10 animate-fadeInUp animate-delay-400">
                <a href="login.php" class="bg-gradient-to-r from-bulsu-maroon to-bulsu-dark-maroon text-white px-10 py-4 rounded-xl font-bold text-lg shadow-lg hover:from-bulsu-dark-maroon hover:to-black transition transform hover:-translate-y-1 hover:shadow-xl tracking-wide">
                    LOGIN TO YOUR ACCOUNT
                </a>
                <a href="index.php" class="bg-transparent border-2 border-bulsu-maroon text-bulsu-maroon px-8 py-4 rounded-xl font-medium hover:bg-bulsu-maroon hover:text-white transition transform hover:-translate-y-0.5">
                    ← Back to Home
                </a>
            </div>

            <!-- Additional Info -->
            <div class="bg-gradient-to-r from-bulsu-light-gold to-yellow-50 border-l-4 border-bulsu-gold rounded-xl p-6 text-left animate-fadeInUp animate-delay-500">
                <h4 class="text-bulsu-maroon text-lg font-semibold mb-4 flex items-center gap-2">
                    📋 Important Reminders:
                </h4>
                <ul class="text-gray-700 space-y-2 leading-relaxed pl-4">
                    <li class="flex items-start gap-2">
                        <span class="text-bulsu-gold font-bold mt-1">•</span>
                        <span>Keep your login credentials secure and don't share them with others</span>
                    </li>
                    <li class="flex items-start gap-2">
                        <span class="text-bulsu-gold font-bold mt-1">•</span>
                        <span>Update your profile information after your first login</span>
                    </li>
                    <li class="flex items-start gap-2">
                        <span class="text-bulsu-gold font-bold mt-1">•</span>
                        <span>Check your email regularly for important notifications from BULSU</span>
                    </li>
                    <li class="flex items-start gap-2">
                        <span class="text-bulsu-gold font-bold mt-1">•</span>
                        <span>Contact your academic adviser if you encounter any issues accessing your account</span>
                    </li>
                </ul>
            </div>
        </div>
    </main>

    <!-- Footer (simplified version) -->
    <footer class="bg-gradient-to-r from-bulsu-dark-maroon to-black text-white py-8 px-4 mt-auto">
        <div class="max-w-6xl mx-auto text-center">
            <div class="flex flex-col md:flex-row items-center justify-center space-y-2 md:space-y-0 md:space-x-4 text-gray-300 text-sm">
                <p>&copy; 2025 Bulacan State University - OnTheJob Tracker System</p>
                <span class="hidden md:inline">•</span>
                <p>AI-Powered OJT Performance Monitoring Platform</p>
            </div>
            <p class="text-xs text-gray-400 mt-2">Developed in partnership with BULSU College of Information Technology</p>
        </div>
    </footer>

    <script>
        // Mobile menu toggle
        const menuBtn = document.getElementById('menu-btn');
        const mobileMenu = document.getElementById('mobile-menu');
        menuBtn.addEventListener('click', () => {
            mobileMenu.classList.toggle('hidden');
        });

        // Close menu when clicking outside
        document.addEventListener('click', function(e) {
            if (!mobileMenu.classList.contains('hidden') && !menuBtn.contains(e.target) && !mobileMenu.contains(e.target)) {
                mobileMenu.classList.add('hidden');
            }
        });

        // Add confetti effect
        function createConfetti() {
            const colors = ['#DAA520', '#800000', '#F4E4BC', '#6B1028'];
            const confettiCount = 50;
            
            for (let i = 0; i < confettiCount; i++) {
                const confetti = document.createElement('div');
                confetti.style.position = 'fixed';
                confetti.style.width = '10px';
                confetti.style.height = '10px';
                confetti.style.backgroundColor = colors[Math.floor(Math.random() * colors.length)];
                confetti.style.left = Math.random() * 100 + 'vw';
                confetti.style.animationDuration = Math.random() * 3 + 2 + 's';
                confetti.style.animationName = 'fall';
                confetti.style.zIndex = '1000';
                confetti.style.borderRadius = '50%';
                
                document.body.appendChild(confetti);
                
                setTimeout(() => {
                    confetti.remove();
                }, 5000);
            }
        }

        // Trigger confetti on page load
        window.addEventListener('load', () => {
            setTimeout(createConfetti, 500);
        });
    </script>
</body>

</html>