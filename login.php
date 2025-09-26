<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Choose Login Type - BULSU OnTheJob Tracker</title>
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
        <div class="bg-white bg-opacity-95 rounded-2xl shadow-2xl p-8 md:p-12 max-w-4xl w-full text-center animate-fadeInUp">
            <div class="mb-8">
                <div class="mb-6">
                    <h3 class="text-bulsu-gold font-semibold text-lg mb-2">Bulacan State University</h3>
                    <div class="w-24 h-1 bg-bulsu-gold mx-auto rounded"></div>
                </div>
                <h1 class="text-bulsu-maroon text-3xl md:text-4xl font-bold mb-4">Choose Your Login</h1>
                <p class="text-gray-600 text-lg md:text-xl">Select your role to access the appropriate dashboard and tools for the BULSU OJT Performance Monitoring System</p>
            </div>
            
            <div class="grid grid-cols-1 md:grid-cols-3 gap-6 md:gap-8 mt-8">
                <!-- Student Login -->
                <a href="student-login.php" class="bg-white border-4 border-bulsu-gold border-opacity-50 rounded-2xl p-6 md:p-8 shadow-lg transition hover:-translate-y-2 hover:scale-105 hover:border-bulsu-gold hover:bg-bulsu-light-gold hover:bg-opacity-30 flex flex-col items-center group">
                    <div class="text-5xl md:text-6xl mb-4 bg-gradient-to-br from-bulsu-light-gold to-bulsu-gold text-bulsu-maroon rounded-full w-20 h-20 flex items-center justify-center group-hover:scale-110 transition duration-300">🎓</div>
                    <h3 class="text-xl md:text-2xl font-bold mb-3 text-bulsu-maroon">BULSU Student</h3>
                    <p class="text-gray-600 text-center">Access your OJT progress, tasks, evaluations, and performance analytics dashboard</p>
                    <div class="mt-4 text-sm text-bulsu-gold font-medium">Click to Login →</div>
                </a>

                <!-- Academic Adviser Login -->
                <a href="adviser-login.php" class="bg-white border-4 border-bulsu-gold border-opacity-50 rounded-2xl p-6 md:p-8 shadow-lg transition hover:-translate-y-2 hover:scale-105 hover:border-bulsu-gold hover:bg-bulsu-light-gold hover:bg-opacity-30 flex flex-col items-center group">
                    <div class="text-5xl md:text-6xl mb-4 bg-gradient-to-br from-bulsu-light-gold to-bulsu-gold text-bulsu-maroon rounded-full w-20 h-20 flex items-center justify-center group-hover:scale-110 transition duration-300">👨‍🏫</div>
                    <h3 class="text-xl md:text-2xl font-bold mb-3 text-bulsu-maroon">Academic Adviser</h3>
                    <p class="text-gray-600 text-center">Monitor BULSU students, review progress, manage academic requirements, and access AI insights</p>
                    <div class="mt-4 text-sm text-bulsu-gold font-medium">Click to Login →</div>
                </a>

                <!-- Company Supervisor Login -->
                <a href="supervisor-login.php" class="bg-white border-4 border-bulsu-gold border-opacity-50 rounded-2xl p-6 md:p-8 shadow-lg transition hover:-translate-y-2 hover:scale-105 hover:border-bulsu-gold hover:bg-bulsu-light-gold hover:bg-opacity-30 flex flex-col items-center group">
                    <div class="text-5xl md:text-6xl mb-4 bg-gradient-to-br from-bulsu-light-gold to-bulsu-gold text-bulsu-maroon rounded-full w-20 h-20 flex items-center justify-center group-hover:scale-110 transition duration-300">👔</div>
                    <h3 class="text-xl md:text-2xl font-bold mb-3 text-bulsu-maroon">Company Supervisor</h3>
                    <p class="text-gray-600 text-center">Supervise BULSU interns, assign tasks, evaluate performance, and provide industry feedback</p>
                    <div class="mt-4 text-sm text-bulsu-gold font-medium">Click to Login →</div>
                </a>
            </div>

        

            <!-- Back to Landing Page -->
            <div class="mt-8 text-center">
                <a href="index.php" class="inline-block bg-gradient-to-r from-bulsu-maroon to-bulsu-dark-maroon text-white px-8 py-3 rounded-lg font-semibold shadow-lg hover:from-bulsu-dark-maroon hover:to-black transition transform hover:scale-105">
                    ← Back to Landing Page
                </a>
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

    <style>
        @keyframes fadeInUp {
            from { 
                opacity: 0; 
                transform: translateY(40px);
            }
            to { 
                opacity: 1; 
                transform: translateY(0);
            }
        }
        .animate-fadeInUp { 
            animation: fadeInUp 0.8s ease-out;
        }
    </style>

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

        // Add hover effects for login cards
        const loginCards = document.querySelectorAll('a[href*="login.php"]');
        loginCards.forEach(card => {
            card.addEventListener('mouseenter', function() {
                this.style.transform = 'translateY(-8px) scale(1.05)';
            });
            
            card.addEventListener('mouseleave', function() {
                this.style.transform = 'translateY(0) scale(1)';
            });
        });
    </script>
</body>
</html>