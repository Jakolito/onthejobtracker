<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>BULSU AI-Powered OJT Performance Monitoring System</title>
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

<body class="bg-gray-100 text-gray-800">
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
                <li><a href="#features" class="hover:text-bulsu-gold transition">Features</a></li>  
                <li><a href="#stakeholders" class="hover:text-bulsu-gold transition">Stakeholders</a></li>
                <li><a href="#contact" class="hover:text-bulsu-gold transition">Contact</a></li>
            </ul>
            <div class="hidden md:flex space-x-4">
                <a href="login.php" class="bg-bulsu-gold bg-opacity-20 border border-bulsu-gold border-opacity-50 rounded px-4 py-2 font-medium hover:bg-opacity-30 transition text-bulsu-gold">Login</a>
                <a href="signup.php" class="bg-bulsu-gold text-bulsu-maroon rounded px-4 py-2 font-medium hover:bg-yellow-400 transition">Sign Up</a>
            </div>
            <!-- Mobile Menu -->
            <div id="mobile-menu" class="md:hidden hidden absolute top-full left-0 w-full bg-bulsu-maroon z-50 px-4 pb-4 shadow-lg">
                <ul class="flex flex-col space-y-2 font-medium pt-4">
                    <li><a href="#features" class="hover:text-bulsu-gold block py-2 text-white transition">Features</a></li>
                    <li><a href="#algorithms" class="hover:text-bulsu-gold block py-2 text-white transition">AI Algorithms</a></li>
                    <li><a href="#stakeholders" class="hover:text-bulsu-gold block py-2 text-white transition">Stakeholders</a></li>
                    <li><a href="#contact" class="hover:text-bulsu-gold block py-2 text-white transition">Contact</a></li>
                </ul>
                <div class="flex flex-col space-y-2 mt-4">
                    <a href="login.php" class="bg-bulsu-gold bg-opacity-20 border border-bulsu-gold border-opacity-50 rounded px-4 py-2 font-medium text-bulsu-gold hover:bg-opacity-30 transition">Login</a>
                    <a href="signup.php" class="bg-bulsu-gold text-bulsu-maroon rounded px-4 py-2 font-medium hover:bg-yellow-400 transition text-center">Sign Up</a>
                </div>
            </div>
        </nav>
    </header>

    <main>
        <section class="bg-gradient-to-r from-bulsu-maroon via-bulsu-dark-maroon to-bulsu-maroon text-white py-16 px-4 text-center relative overflow-hidden">
            <div class="absolute inset-0 bg-gradient-to-b from-transparent to-black opacity-20"></div>
            <div class="max-w-3xl mx-auto relative z-10">
                <div class="mb-6">
                    <h3 class="text-bulsu-gold font-semibold text-lg mb-2">Bulacan State University</h3>
                    <div class="w-24 h-1 bg-bulsu-gold mx-auto rounded"></div>
                </div>
                <h1 class="text-3xl md:text-5xl font-bold mb-4 leading-tight">An OJT Performance Monitoring System</h1>
                <p class="text-lg md:text-xl mb-8 text-gray-100">Enhancing Student Progress Tracking through Predictive Analytics</p>
                <div class="flex flex-col sm:flex-row gap-4 justify-center">
                    <a href="signup.php" class="inline-block bg-bulsu-gold text-bulsu-maroon font-bold px-8 py-3 rounded-lg shadow-lg hover:bg-yellow-400 transition transform hover:scale-105">Get Started</a>
                    <a href="#features" class="inline-block border-2 border-bulsu-gold text-bulsu-gold font-bold px-8 py-3 rounded-lg hover:bg-bulsu-gold hover:text-bulsu-maroon transition">Learn More</a>
                </div>
            </div>
        </section>

        <section id="features" class="bg-white py-16 px-4">
            <div class="max-w-6xl mx-auto">
                <div class="text-center mb-12">
                    <h2 class="text-bulsu-maroon text-3xl font-bold mb-2">Advanced System Features</h2>
                    <div class="w-16 h-1 bg-bulsu-gold mx-auto mb-4 rounded"></div>
                    <p class="text-gray-600 text-lg max-w-3xl mx-auto">Our AI-powered monitoring system uses cutting-edge machine learning and predictive analytics to transform OJT performance tracking and evaluation for BULSU students and industry partners</p>
                </div>
                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-8">
                    <div class="bg-white border-t-4 border-bulsu-gold rounded-lg p-8 shadow-lg hover:-translate-y-2 hover:shadow-xl transition duration-300">
                        <div class="text-4xl mb-4">🤖</div>
                        <h3 class="text-xl font-bold text-bulsu-maroon mb-2">AI-Based Performance Analysis</h3>
                        <p class="text-gray-600">Machine Learning algorithms analyze student feedback and performance data to predict trends and identify key success factors for BULSU OJT programs.</p>
                    </div>
                    <div class="bg-white border-t-4 border-bulsu-gold rounded-lg p-8 shadow-lg hover:-translate-y-2 hover:shadow-xl transition duration-300">
                        <div class="text-4xl mb-4">📊</div>
                        <h3 class="text-xl font-bold text-bulsu-maroon mb-2">Real-Time Progress Dashboard</h3>
                        <p class="text-gray-600">Interactive dashboards displaying OJT completion rates, skill development metrics, and supervisor ratings with AI-generated insights tailored for BULSU programs.</p>
                    </div>
                    <div class="bg-white border-t-4 border-bulsu-gold rounded-lg p-8 shadow-lg hover:-translate-y-2 hover:shadow-xl transition duration-300">
                        <div class="text-4xl mb-4">🔔</div>
                        <h3 class="text-xl font-bold text-bulsu-maroon mb-2">Predictive Alerts for At-Risk Students</h3>
                        <p class="text-gray-600">ML-powered early warning system identifies BULSU students likely to struggle or fail, enabling timely intervention strategies by faculty and supervisors.</p>
                    </div>
                    <div class="bg-white border-t-4 border-bulsu-gold rounded-lg p-8 shadow-lg hover:-translate-y-2 hover:shadow-xl transition duration-300">
                        <div class="text-4xl mb-4">📄</div>
                        <h3 class="text-xl font-bold text-bulsu-maroon mb-2">Automated Report Generation</h3>
                        <p class="text-gray-600">AI-generated custom reports for BULSU students, industry supervisors, and academic departments with actionable insights and recommendations.</p>
                    </div>
                </div>
            </div>
        </section>

        <section id="how-it-works" class="bg-gradient-to-br from-bulsu-light-gold to-white py-16 px-4">
            <div class="max-w-6xl mx-auto">
                <div class="text-center mb-12">
                    <h2 class="text-bulsu-maroon text-3xl font-bold mb-2">How It Works</h2>
                    <div class="w-16 h-1 bg-bulsu-gold mx-auto mb-4 rounded"></div>
                    <p class="text-gray-700 text-lg">Our system empowers data-driven decision making throughout the entire BULSU OJT process</p>
                </div>
                <div class="flex flex-col md:flex-row md:justify-center gap-8">
                    <div class="bg-white border border-bulsu-gold border-opacity-30 rounded-lg p-8 shadow-lg relative flex-1 min-w-[220px] max-w-xs mx-auto hover:shadow-xl transition duration-300">
                        <div class="absolute -top-6 -left-6 bg-gradient-to-r from-bulsu-maroon to-bulsu-dark-maroon text-white w-12 h-12 rounded-full flex items-center justify-center font-bold text-xl shadow-lg">1</div>
                        <h3 class="text-xl font-bold text-bulsu-maroon mb-2">Data Collection</h3>
                        <p class="text-gray-600">Automated collection of BULSU student performance data, industry supervisor evaluations, and self-assessments through intuitive mobile and web interfaces.</p>
                    </div>
                    <div class="bg-white border border-bulsu-gold border-opacity-30 rounded-lg p-8 shadow-lg relative flex-1 min-w-[220px] max-w-xs mx-auto hover:shadow-xl transition duration-300">
                        <div class="absolute -top-6 -left-6 bg-gradient-to-r from-bulsu-maroon to-bulsu-dark-maroon text-white w-12 h-12 rounded-full flex items-center justify-center font-bold text-xl shadow-lg">2</div>
                        <h3 class="text-xl font-bold text-bulsu-maroon mb-2">AI Analysis</h3>
                        <p class="text-gray-600">Advanced machine learning algorithms process the collected data to identify patterns, predict outcomes, and generate actionable insights for BULSU faculty and students.</p>
                    </div>
                    <div class="bg-white border border-bulsu-gold border-opacity-30 rounded-lg p-8 shadow-lg relative flex-1 min-w-[220px] max-w-xs mx-auto hover:shadow-xl transition duration-300">
                        <div class="absolute -top-6 -left-6 bg-gradient-to-r from-bulsu-maroon to-bulsu-dark-maroon text-white w-12 h-12 rounded-full flex items-center justify-center font-bold text-xl shadow-lg">3</div>
                        <h3 class="text-xl font-bold text-bulsu-maroon mb-2">Early Intervention</h3>
                        <p class="text-gray-600">Predictive analytics identify at-risk BULSU students, triggering automatic alerts to faculty advisors and suggested intervention strategies.</p>
                    </div>
                    <div class="bg-white border border-bulsu-gold border-opacity-30 rounded-lg p-8 shadow-lg relative flex-1 min-w-[220px] max-w-xs mx-auto hover:shadow-xl transition duration-300">
                        <div class="absolute -top-6 -left-6 bg-gradient-to-r from-bulsu-maroon to-bulsu-dark-maroon text-white w-12 h-12 rounded-full flex items-center justify-center font-bold text-xl shadow-lg">4</div>
                        <h3 class="text-xl font-bold text-bulsu-maroon mb-2">Continuous Improvement</h3>
                        <p class="text-gray-600">System learns and improves over time, refining its predictions and recommendations based on BULSU program outcomes and faculty feedback.</p>
                    </div>
                </div>
            </div>
        </section>

        <section id="stakeholders" class="bg-white py-16 px-4">
            <div class="max-w-6xl mx-auto">
                <div class="text-center mb-12">
                    <h2 class="text-bulsu-maroon text-3xl font-bold mb-2">Benefits for All BULSU Stakeholders</h2>
                    <div class="w-16 h-1 bg-bulsu-gold mx-auto mb-4 rounded"></div>
                    <p class="text-gray-600 text-lg">Our AI-powered system creates value for everyone involved in the BULSU OJT process</p>
                </div>
                <div class="grid grid-cols-1 md:grid-cols-3 gap-8">
                    <div class="bg-white border-b-4 border-bulsu-gold rounded-lg p-8 shadow-lg flex flex-col items-center hover:shadow-xl transition duration-300 group">
                        <div class="text-5xl mb-4 bg-gradient-to-br from-bulsu-light-gold to-bulsu-gold text-bulsu-maroon rounded-full w-20 h-20 flex items-center justify-center group-hover:scale-110 transition duration-300">👨‍🎓</div>
                        <h3 class="text-xl font-bold text-bulsu-maroon mb-4">For BULSU Students</h3>
                        <ul class="text-left text-gray-600 space-y-2">
                            <li>• Real-time feedback on OJT performance</li>
                            <li>• Personalized career development recommendations</li>
                            <li>• Enhanced skill tracking and validation</li>
                            <li>• Easy submission of reports and reflections</li>
                            <li>• Better preparation for post-graduation careers</li>
                            <li>• Direct connection with BULSU faculty support</li>
                        </ul>
                    </div>
                    <div class="bg-white border-b-4 border-bulsu-gold rounded-lg p-8 shadow-lg flex flex-col items-center hover:shadow-xl transition duration-300 group">
                        <div class="text-5xl mb-4 bg-gradient-to-br from-bulsu-light-gold to-bulsu-gold text-bulsu-maroon rounded-full w-20 h-20 flex items-center justify-center group-hover:scale-110 transition duration-300">👨‍💼</div>
                        <h3 class="text-xl font-bold text-bulsu-maroon mb-4">For Industry Supervisors</h3>
                        <ul class="text-left text-gray-600 space-y-2">
                            <li>• Streamlined evaluation process for BULSU trainees</li>
                            <li>• Data-driven insights into student performance</li>
                            <li>• Reduced administrative burden</li>
                            <li>• Enhanced communication with BULSU faculty</li>
                            <li>• Tools to provide better guidance and mentorship</li>
                            <li>• Easy tracking of multiple BULSU trainees</li>
                        </ul>
                    </div>
                    <div class="bg-white border-b-4 border-bulsu-gold rounded-lg p-8 shadow-lg flex flex-col items-center hover:shadow-xl transition duration-300 group">
                        <div class="text-5xl mb-4 bg-gradient-to-br from-bulsu-light-gold to-bulsu-gold text-bulsu-maroon rounded-full w-20 h-20 flex items-center justify-center group-hover:scale-110 transition duration-300">🏫</div>
                        <h3 class="text-xl font-bold text-bulsu-maroon mb-4">For BULSU Faculty & Admin</h3>
                        <ul class="text-left text-gray-600 space-y-2">
                            <li>• Comprehensive program effectiveness analytics</li>
                            <li>• Early identification of at-risk students</li>
                            <li>• Evidence-based curriculum improvement</li>
                            <li>• Enhanced industry partnership management</li>
                            <li>• Improved student outcomes and satisfaction</li>
                            <li>• Compliance with BULSU academic standards</li>
                        </ul>
                    </div>
                </div>
            </div>
        </section>
    </main>

    <footer id="contact" class="bg-gradient-to-r from-bulsu-dark-maroon to-black text-white py-12 px-4">
        <div class="max-w-6xl mx-auto grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-8">
            <div>
                <h3 class="text-bulsu-gold font-bold mb-4 text-lg">OnTheJob Tracker</h3>
                <p class="text-gray-300 text-sm mb-4">Empowering BULSU students with AI-driven OJT monitoring and career development.</p>
                <ul class="space-y-2 text-sm">
                    <li><a href="#features" class="hover:text-bulsu-gold transition">Features</a></li>
                    <li><a href="#algorithms" class="hover:text-bulsu-gold transition">AI Algorithms</a></li>
                    <li><a href="#stakeholders" class="hover:text-bulsu-gold transition">Stakeholders</a></li>
                    <li><a href="#faq" class="hover:text-bulsu-gold transition">FAQ</a></li>
                </ul>
            </div>
            <div>
                <h3 class="text-bulsu-gold font-bold mb-4 text-lg">Resources</h3>
                <ul class="space-y-2 text-sm">
                    <li><a href="#" class="hover:text-bulsu-gold transition">Help Center</a></li>
                    <li><a href="#" class="hover:text-bulsu-gold transition">Research Papers</a></li>
                    <li><a href="#" class="hover:text-bulsu-gold transition">API Documentation</a></li>
                    <li><a href="#" class="hover:text-bulsu-gold transition">Tutorial Videos</a></li>
                    <li><a href="#" class="hover:text-bulsu-gold transition">BULSU Integration Guide</a></li>
                </ul>
            </div>
            <div>
                <h3 class="text-bulsu-gold font-bold mb-4 text-lg">University Partnership</h3>
                <ul class="space-y-2 text-sm">
                    <li><a href="#" class="hover:text-bulsu-gold transition">About BULSU</a></li>
                    <li><a href="#" class="hover:text-bulsu-gold transition">Our Development Team</a></li>
                    <li><a href="#" class="hover:text-bulsu-gold transition">Privacy Policy</a></li>
                    <li><a href="#" class="hover:text-bulsu-gold transition">Terms of Service</a></li>
                    <li><a href="#" class="hover:text-bulsu-gold transition">Academic Compliance</a></li>
                </ul>
            </div>
            <div>
                <h3 class="text-bulsu-gold font-bold mb-4 text-lg">Contact Us</h3>
                <ul class="space-y-2 text-sm text-gray-300">
                    <li>📧 support@bulsu-ojtracker.edu.ph</li>
                    <li>📱 (044) 123-4567</li>
                    <li>🏫 Bulacan State University<br>City of Malolos, Bulacan 3000</li>
                    <li>🕒 Mon-Fri: 8:00 AM - 5:00 PM</li>
                </ul>
                <div class="mt-4 flex space-x-4">
                    <a href="#" class="text-bulsu-gold hover:text-yellow-400 transition text-xl">📘</a>
                    <a href="#" class="text-bulsu-gold hover:text-yellow-400 transition text-xl">🐦</a>
                    <a href="#" class="text-bulsu-gold hover:text-yellow-400 transition text-xl">📧</a>
                </div>
            </div>
        </div>
        <div class="text-center pt-8 mt-8 border-t border-gray-700">
            <div class="flex flex-col md:flex-row items-center justify-center space-y-2 md:space-y-0 md:space-x-4 text-gray-300 text-sm">
                <p>&copy; 2025 Bulacan State University - OnTheJob Tracker System</p>
                <span class="hidden md:inline">•</span>
                <p>AI-Powered OJT Performance Monitoring Platform</p>
            </div>
            <p class="text-xs text-gray-400 mt-2">Developed in partnership with BULSU College of Engineering</p>
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

        // Smooth scrolling for anchor links
        document.querySelectorAll('a[href^="#"]').forEach(anchor => {
            anchor.addEventListener('click', function (e) {
                e.preventDefault();
                const target = document.querySelector(this.getAttribute('href'));
                if (target) {
                    target.scrollIntoView({
                        behavior: 'smooth',
                        block: 'start'
                    });
                }
            });
        });

        // Add scroll effect for hero section
        window.addEventListener('scroll', () => {
            const heroSection = document.querySelector('main section:first-child');
            const scrolled = window.pageYOffset;
            const parallax = scrolled * 0.5;
            
            if (heroSection) {
                heroSection.style.transform = `translateY(${parallax}px)`;
            }
        });
    </script>
</body>

</html>