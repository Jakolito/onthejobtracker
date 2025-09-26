<?php
include('connect.php');
session_start();

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
        // Check if user is a student
        $student_query = "SELECT * FROM students WHERE email = ?";
        $student_stmt = mysqli_prepare($conn, $student_query);
        mysqli_stmt_bind_param($student_stmt, "s", $email);
        mysqli_stmt_execute($student_stmt);
        $student_result = mysqli_stmt_get_result($student_stmt);
        
        if (mysqli_num_rows($student_result) > 0) {
            $user = mysqli_fetch_assoc($student_result);
            
            // Check if account is blocked
            if ($user['status'] === 'Blocked') {
                $error_message = 'Your account has been blocked due to multiple failed login attempts. Please contact support.';
            } else {
                // Verify password
                if (password_verify($password, $user['password'])) {
                    // Login successful - reset login attempts
                    $reset_attempts_query = "UPDATE students SET login_attempts = 0, last_login_attempt = NULL WHERE email = ?";
                    $reset_stmt = mysqli_prepare($conn, $reset_attempts_query);
                    mysqli_stmt_bind_param($reset_stmt, "s", $email);
                    mysqli_stmt_execute($reset_stmt);
                    
                    // Set session variables for student
                    $_SESSION['user_id'] = $user['id'];
                    $_SESSION['student_id'] = $user['student_id'];
                    $_SESSION['email'] = $user['email'];
                    $_SESSION['first_name'] = $user['first_name'];
                    $_SESSION['last_name'] = $user['last_name'];
                    $_SESSION['profile_picture'] = $user['profile_picture'];
                    $_SESSION['user_type'] = 'student';
                    $_SESSION['logged_in'] = true;
                    $_SESSION['verified'] = $user['verified'];
                    
                    // Update last login
                    $update_login_query = "UPDATE students SET last_login = NOW() WHERE email = ?";
                    $update_stmt = mysqli_prepare($conn, $update_login_query);
                    mysqli_stmt_bind_param($update_stmt, "s", $email);
                    mysqli_stmt_execute($update_stmt);
                    
                    // Redirect to student dashboard
                    header("Location: studentdashboard.php");
                    exit;
                } else {
                    // Password incorrect - increment login attempts
                    $current_attempts = $user['login_attempts'] + 1;
                    
                    if ($current_attempts >= 3) {
                        // Block the account after 3 failed attempts
                        $block_query = "UPDATE students SET login_attempts = ?, last_login_attempt = NOW(), status = 'Blocked' WHERE email = ?";
                        $block_stmt = mysqli_prepare($conn, $block_query);
                        mysqli_stmt_bind_param($block_stmt, "is", $current_attempts, $email);
                        mysqli_stmt_execute($block_stmt);
                        
                        $error_message = 'Account blocked! Too many failed login attempts. Your account has been blocked. Please contact support.';
                    } else {
                        // Update login attempts
                        $update_attempts_query = "UPDATE students SET login_attempts = ?, last_login_attempt = NOW() WHERE email = ?";
                        $update_stmt = mysqli_prepare($conn, $update_attempts_query);
                        mysqli_stmt_bind_param($update_stmt, "is", $current_attempts, $email);
                        mysqli_stmt_execute($update_stmt);
                        
                        $remaining_attempts = 3 - $current_attempts;
                        $error_message = "Invalid password. $remaining_attempts attempt(s) remaining before account is blocked.";
                    }
                }
            }
        } else {
            $error_message = 'No student account found with this email address.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Student Login - BULSU OnTheJob Tracker</title>
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
        <div class="bg-white bg-opacity-95 rounded-2xl shadow-2xl p-8 md:p-12 max-w-md w-full text-center animate-fadeInUp">
            <div class="mb-8">
                <div class="mb-6">
                    <h3 class="text-bulsu-gold font-semibold text-lg mb-2">Bulacan State University</h3>
                    <div class="w-24 h-1 bg-bulsu-gold mx-auto rounded"></div>
                </div>
                <div class="text-5xl mb-4">🎓</div>
                <h1 class="text-bulsu-maroon text-2xl md:text-3xl font-bold mb-2">Student Portal</h1>
                <p class="text-gray-600">Welcome back! Access your OJT dashboard and track your internship progress</p>
            </div>
            
            <?php if (!empty($error_message)): ?>
                <div class="bg-red-50 border border-red-200 text-red-700 px-4 py-3 rounded mb-4 text-center text-sm">
                    <?php echo htmlspecialchars($error_message); ?>
                </div>
            <?php endif; ?>
            
            <form action="student-login.php" method="post" class="space-y-5 text-left">
                <div>
                    <label for="email" class="block text-gray-700 font-medium mb-2">Student Email Address</label>
                    <input type="email" id="email" name="email" placeholder="Enter your BULSU student email"
                        value="<?php echo $email_value; ?>" required
                        class="w-full px-4 py-3 border-2 border-gray-200 rounded-lg focus:outline-none focus:border-bulsu-gold transition duration-300">
                </div>
                
                <div>
                    <label for="password" class="block text-gray-700 font-medium mb-2">Password</label>
                    <div class="relative">
                        <input type="password" id="password" name="password" placeholder="Enter your password" required
                            class="w-full px-4 py-3 pr-12 border-2 border-gray-200 rounded-lg focus:outline-none focus:border-bulsu-gold transition duration-300">
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
                
                <div class="flex flex-col md:flex-row justify-between items-center text-sm gap-2">
                    <label class="flex items-center cursor-pointer">
                        <input type="checkbox" id="remember" name="remember" class="mr-2 accent-bulsu-gold">
                        <span class="text-gray-600">Remember me</span>
                    </label>
                    <a href="forgot.php" class="text-bulsu-gold hover:text-bulsu-dark-maroon transition font-medium">Forgot Password?</a>
                </div>
                
                <button type="submit"
                    class="w-full bg-gradient-to-r from-bulsu-maroon to-bulsu-dark-maroon text-white rounded-lg py-3 font-semibold shadow-lg hover:from-bulsu-dark-maroon hover:to-black transition transform hover:scale-105">
                    Login to Dashboard
                </button>
            </form>
            
            <div class="text-center mt-6 text-gray-600">
                Don't have a student account?
                <a href="signup.php" class="text-bulsu-gold font-semibold hover:text-bulsu-dark-maroon transition">Register here</a>
            </div>
            
            <div class="mt-6 text-center">
                <a href="login.php" class="inline-block bg-bulsu-gold bg-opacity-20 border border-bulsu-gold border-opacity-50 rounded px-6 py-2 font-medium hover:bg-opacity-30 transition text-bulsu-gold">
                    ← Back to Login Selection
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