<?php
include('connect.php');
session_start();

// Add these session configurations for hosting environments
ini_set('session.cookie_httponly', 1);
ini_set('session.use_only_cookies', 1);
ini_set('session.cookie_secure', 0); // Set to 1 if using HTTPS

// Initialize variables for form persistence
$email_value = '';
$error_message = '';

// Handle login form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email']);
    $password = $_POST['password'];
    
    // Store email for potential redisplay
    $email_value = htmlspecialchars($email);
    
    // Validate input
    if (empty($email) || empty($password)) {
        $error_message = 'Please fill in all fields.';
    } else {
        // Check if user is a Company Supervisor
        $supervisor_query = "SELECT * FROM company_supervisors WHERE email = ?";
        $supervisor_stmt = mysqli_prepare($conn, $supervisor_query);
        
        if ($supervisor_stmt) {
            mysqli_stmt_bind_param($supervisor_stmt, "s", $email);
            mysqli_stmt_execute($supervisor_stmt);
            $supervisor_result = mysqli_stmt_get_result($supervisor_stmt);
            
            if (mysqli_num_rows($supervisor_result) > 0) {
                $supervisor = mysqli_fetch_assoc($supervisor_result);
                
                // Check if account is active
                if ($supervisor['account_status'] !== 'Active') {
                    if ($supervisor['account_status'] === 'Pending') {
                        $error_message = 'Your account is still pending approval. Please wait for administrator approval.';
                    } else {
                        $error_message = 'Your account is inactive. Please contact support.';
                    }
                } else {
                    // Verify password for supervisor
                    if (password_verify($password, $supervisor['password'])) {
                        // Regenerate session ID for security
                        session_regenerate_id(true);
                        
                        // Login successful for supervisor
                        $_SESSION['supervisor_id'] = $supervisor['supervisor_id'];
                        $_SESSION['email'] = $supervisor['email'];
                        $_SESSION['full_name'] = $supervisor['full_name'];
                        $_SESSION['username'] = $supervisor['username'];
                        $_SESSION['position'] = $supervisor['position'];
                        $_SESSION['company_name'] = $supervisor['company_name'];
                        $_SESSION['profile_picture'] = $supervisor['profile_picture'];
                        $_SESSION['user_type'] = 'supervisor';
                        $_SESSION['logged_in'] = true;
                        
                        // Make sure session is written before redirect
                        session_write_close();
                        
                        // Use absolute URL for redirect (adjust domain as needed)
                        $redirect_url = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'] . dirname($_SERVER['REQUEST_URI']) . '/CompanyDashboard.php';
                        
                        // Redirect to company dashboard - FIXED FILENAME
                        header("Location: CompanyDashboard.php");
                        exit();
                    } else {
                        $error_message = 'Invalid password. Please check your credentials.';
                    }
                }
            } else {
                $error_message = 'No company supervisor account found with this email address.';
            }
            
            mysqli_stmt_close($supervisor_stmt);
        } else {
            $error_message = 'Database error: ' . mysqli_error($conn);
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Company Supervisor Login - BULSU OnTheJob Tracker</title>
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
                <a href="supervisor-signup.php" class="bg-bulsu-gold text-bulsu-maroon rounded px-4 py-2 font-medium hover:bg-yellow-400 transition">Sign Up</a>
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
        <div class="bg-white bg-opacity-95 rounded-2xl shadow-2xl p-8 md:p-12 max-w-md w-full animate-fadeInUp">
            <div class="text-center mb-8">
                <div class="mb-6">
                    <h3 class="text-bulsu-gold font-semibold text-lg mb-2">Bulacan State University</h3>
                    <div class="w-24 h-1 bg-bulsu-gold mx-auto rounded"></div>
                </div>
                <div class="inline-block bg-gradient-to-r from-bulsu-maroon to-bulsu-dark-maroon text-white px-4 py-2 rounded-full font-semibold mb-4">👔 Company Supervisor Portal</div>
                <h1 class="text-bulsu-maroon text-2xl md:text-3xl font-bold mb-2">Welcome Back!</h1>
                <p class="text-gray-600">Access your dashboard to manage BULSU interns and assignments</p>
            </div>

            <?php if (!empty($error_message)): ?>
                <div class="bg-red-50 border border-red-200 text-red-700 px-4 py-3 rounded-lg mb-6 text-center text-sm">
                    <div class="flex items-center justify-center">
                        <svg class="w-4 h-4 mr-2" fill="currentColor" viewBox="0 0 20 20">
                            <path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7 4a1 1 0 11-2 0 1 1 0 012 0zm-1-9a1 1 0 00-1 1v4a1 1 0 102 0V6a1 1 0 00-1-1z" clip-rule="evenodd"></path>
                        </svg>
                        <?php echo htmlspecialchars($error_message); ?>
                    </div>
                </div>
            <?php endif; ?>

            <form action="supervisor-login.php" method="post" class="space-y-6">
                <div>
                    <label for="email" class="block text-bulsu-maroon font-semibold mb-2">Company Email Address</label>
                    <input type="email" id="email" name="email" placeholder="Enter your company email"
                        value="<?php echo $email_value; ?>" required
                        class="w-full px-4 py-3 border-2 border-gray-200 rounded-lg focus:outline-none focus:border-bulsu-gold focus:ring-2 focus:ring-bulsu-gold focus:ring-opacity-20 transition duration-200">
                </div>

                <div>
                    <label for="password" class="block text-bulsu-maroon font-semibold mb-2">Password</label>
                    <div class="relative">
                        <input type="password" id="password" name="password" placeholder="Enter your password" required
                            class="w-full px-4 py-3 pr-12 border-2 border-gray-200 rounded-lg focus:outline-none focus:border-bulsu-gold focus:ring-2 focus:ring-bulsu-gold focus:ring-opacity-20 transition duration-200">
                        <button type="button" id="togglePassword" class="absolute inset-y-0 right-0 flex items-center px-3 text-gray-600 hover:text-bulsu-gold focus:outline-none transition-colors">
                            <!-- Eye Open Icon (visible by default) -->
                            <svg id="eyeOpen" class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path>
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"></path>
                            </svg>
                            <!-- Eye Closed Icon (hidden by default) -->
                            <svg id="eyeClosed" class="w-5 h-5 hidden" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.875 18.825A10.05 10.05 0 0112 19c-4.478 0-8.268-2.943-9.543-7a9.97 9.97 0 011.563-3.029m5.858.908a3 3 0 114.243 4.243M9.878 9.878l4.242 4.242M9.878 9.878L3 3m6.878 6.878L21 21"></path>
                            </svg>
                        </button>
                    </div>
                </div>

                <div class="flex flex-col md:flex-row justify-between items-start md:items-center text-sm gap-3">
                    <label class="flex items-center cursor-pointer text-gray-700">
                        <input type="checkbox" id="remember" name="remember" class="mr-2 text-bulsu-gold focus:ring-bulsu-gold">
                        <span class="text-sm">Remember me</span>
                    </label>
                    <a href="companyforgot.php" class="text-bulsu-gold hover:text-bulsu-dark-maroon transition font-medium">Forgot Password?</a>
                </div>

                <button type="submit"
                    class="w-full bg-gradient-to-r from-bulsu-maroon to-bulsu-dark-maroon text-white rounded-lg py-3 font-semibold shadow-lg hover:from-bulsu-dark-maroon hover:to-black transition duration-200 transform hover:scale-105">
                    Sign In to Dashboard
                </button>
            </form>

            <div class="text-center mt-8 text-gray-600">
                Need a supervisor account?
                <a href="supervisor-signup.php" class="text-bulsu-gold font-semibold hover:text-bulsu-dark-maroon transition">Register here</a>
            </div>

            <div class="mt-6 text-center">
                <a href="login.php" class="inline-block bg-bulsu-gold bg-opacity-20 border border-bulsu-gold border-opacity-50 rounded px-6 py-2 font-medium hover:bg-opacity-30 transition text-bulsu-gold">
                    ← Back to Login Selection
                </a>
            </div>
        </div>
    </main>

    <!-- Footer -->
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

        // Password toggle functionality
        const togglePassword = document.getElementById('togglePassword');
        const passwordInput = document.getElementById('password');
        const eyeOpen = document.getElementById('eyeOpen');
        const eyeClosed = document.getElementById('eyeClosed');

        togglePassword.addEventListener('click', function() {
            // Toggle password visibility
            const type = passwordInput.getAttribute('type') === 'password' ? 'text' : 'password';
            passwordInput.setAttribute('type', type);
            
            // Toggle eye icons
            eyeOpen.classList.toggle('hidden');
            eyeClosed.classList.toggle('hidden');
        });
    </script>
</body>
</html>