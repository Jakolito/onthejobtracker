<?php
include('connect.php');
session_start();

require './PHPMailer/PHPMailer/src/Exception.php';
require './PHPMailer/PHPMailer/src/PHPMailer.php';
require './PHPMailer/PHPMailer/src/SMTP.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

// Check if user has valid session and email
if (!isset($_SESSION['email'])) {
    // If no email in session, redirect to signup
    header("Location: signup.php");
    exit;
}

$email = $_SESSION['email'];
$verification_message = '';
$verification_error = '';

// Get student details from database for email
$student_query = "SELECT student_id, first_name, last_name, program, department, year_level FROM students WHERE email = ?";
$stmt = $conn->prepare($student_query);
$stmt->bind_param("s", $email);
$stmt->execute();
$student_result = $stmt->get_result();
$student_data = $student_result->fetch_assoc();

// Check if OTP exists and is valid, if not generate new one
if (!isset($_SESSION['otp']) || !isset($_SESSION['otp_expiry']) || time() > $_SESSION['otp_expiry']) {
    // Generate new OTP
    $new_otp = rand(100000, 999999);
    $new_expiry = time() + 300; // 5 minutes
    
    // Update session
    $_SESSION['otp'] = $new_otp;
    $_SESSION['otp_expiry'] = $new_expiry;
    
    // Update database
    $update_otp_query = "UPDATE students SET verification_code = ? WHERE email = ?";
    $stmt = $conn->prepare($update_otp_query);
    $stmt->bind_param("is", $new_otp, $email);
    $stmt->execute();
    
    // Send OTP email
    try {
        $mail = new PHPMailer(true);
        $mail->isSMTP();
        $mail->Host = 'smtp.gmail.com';
        $mail->SMTPAuth = true;
        $mail->Username = 'ojttracker2@gmail.com';
        $mail->Password = 'rxtj qlze uomg xzqj';
        $mail->SMTPSecure = 'ssl';
        $mail->Port = 465;
        
        $mail->setFrom('ojttracker2@gmail.com', 'OnTheJob Tracker');
        $mail->addAddress($email);
        
        $mail->isHTML(true);
        $mail->Subject = 'Email Verification Code - OnTheJob Tracker';
        $mail->Body = '
        <div style="font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto; padding: 20px; border: 1px solid #ddd; border-radius: 10px;">
            <div style="text-align: center; margin-bottom: 30px;">
                <h1 style="color: #800000; margin: 0;">OnTheJob Tracker</h1>
                <p style="color: #666; margin: 5px 0;">BULSU Student OJT Performance Monitoring System</p>
            </div>
            
            <h2 style="color: #333;">Welcome, ' . htmlspecialchars($student_data['first_name']) . '!</h2>
            <p style="color: #555; line-height: 1.6;">
                Thank you for registering with OnTheJob Tracker. To complete your registration and start monitoring your OJT performance with AI, please verify your email address.
            </p>
            
            <div style="text-align: center; margin: 30px 0;">
                <h3 style="color: #800000; margin: 0 0 15px 0;">Your Verification Code:</h3>
                <div style="background-color: #f8f9fa; padding: 20px; border-radius: 8px; margin: 20px 0;">
                    <div style="font-size: 36px; font-weight: bold; color: #800000; letter-spacing: 8px; font-family: monospace;">
                        ' . $new_otp . '
                    </div>
                </div>
            </div>
            
            <div style="background-color: #fff3cd; padding: 15px; border-radius: 5px; border-left: 4px solid #DAA520; margin: 20px 0;">
                <p style="margin: 0; color: #856404;">
                    <strong>Important:</strong> This OTP is valid for 5 minutes only. Do not share this code with anyone.
                </p>
            </div>
            
            <div style="background-color: #f8f9fa; padding: 20px; border-radius: 8px; margin: 20px 0; border-left: 4px solid #800000;">
                <h3 style="color: #800000; margin: 0 0 15px 0;">Your Registration Details:</h3>
                <div style="color: #555;">
                    <p style="margin: 5px 0;"><strong>Student ID:</strong> ' . htmlspecialchars($student_data['student_id']) . '</p>
                    <p style="margin: 5px 0;"><strong>Program:</strong> ' . htmlspecialchars($student_data['program']) . '</p>
                    <p style="margin: 5px 0;"><strong>Department:</strong> ' . htmlspecialchars($student_data['department']) . '</p>
                    <p style="margin: 5px 0;"><strong>Year Level:</strong> ' . htmlspecialchars($student_data['year_level']) . '</p>
                </div>
            </div>
            
            <p style="color: #666; font-size: 14px; margin-top: 30px;">
                If you did not create this account, please ignore this email or contact our support team immediately.
            </p>
            
            <div style="text-align: center; margin-top: 30px; padding-top: 20px; border-top: 1px solid #eee;">
                <p style="color: #666; font-size: 14px; margin: 0;">
                    <strong>OnTheJob Tracker Team</strong><br>
                    AI-Powered OJT Performance Monitoring
                </p>
            </div>
        </div>';
        
        $mail->send();
        $verification_message = "Verification code has been sent to your email address.";
        
    } catch (Exception $e) {
        $verification_error = "Failed to send verification code. Please try again.";
    }
}

// Handle OTP verification
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['verify_otp'])) {
        $entered_otp = trim($_POST['otp']);
        
        // Check if OTP has expired
        if (time() > $_SESSION['otp_expiry']) {
            $verification_error = "OTP has expired. Please request a new one.";
        } elseif ($entered_otp == $_SESSION['otp']) {
            // OTP is correct, update student record to verified
            $update_query = "UPDATE students SET verified = 1, verification_code = NULL WHERE email = ?";
            $stmt = $conn->prepare($update_query);
            $stmt->bind_param("s", $email);
            
            if ($stmt->execute()) {
                // Clear OTP session data
                unset($_SESSION['otp']);
                unset($_SESSION['otp_expiry']);
                
                // Set success message
                $_SESSION['verification_success'] = true;
                $_SESSION['verified_email'] = $email;
                
                // Determine where to redirect based on source
                if (isset($_SESSION['redirect_after_verification'])) {
                    $redirect_url = $_SESSION['redirect_after_verification'];
                    unset($_SESSION['redirect_after_verification']);
                    header("Location: " . $redirect_url);
                } else {
                    // Default redirect to success page
                    header("Location: verification_success.php");
                }
                exit;
            } else {
                $verification_error = "Database error. Please try again.";
            }
        } else {
            $verification_error = "Invalid OTP. Please check and try again.";
        }
    }
    
    // Handle resend OTP
    if (isset($_POST['resend_otp'])) {
        // Generate new OTP
        $new_otp = rand(100000, 999999);
        $new_expiry = time() + 300; // 5 minutes
        
        // Update session
        $_SESSION['otp'] = $new_otp;
        $_SESSION['otp_expiry'] = $new_expiry;
        
        // Update database
        $update_otp_query = "UPDATE students SET verification_code = ? WHERE email = ?";
        $stmt = $conn->prepare($update_otp_query);
        $stmt->bind_param("is", $new_otp, $email);
        $stmt->execute();
        
        // Send new OTP email
        try {
            $mail = new PHPMailer(true);
            $mail->isSMTP();
            $mail->Host = 'smtp.gmail.com';
            $mail->SMTPAuth = true;
            $mail->Username = 'ojttracker2@gmail.com';
            $mail->Password = 'rxtj qlze uomg xzqj';
            $mail->SMTPSecure = 'ssl';
            $mail->Port = 465;
            
            $mail->setFrom('ojttracker2@gmail.com', 'OnTheJob Tracker');
            $mail->addAddress($email);
            
            $mail->isHTML(true);
            $mail->Subject = 'New Verification Code - OnTheJob Tracker';
            $mail->Body = '
            <div style="font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto; padding: 20px; border: 1px solid #ddd; border-radius: 10px;">
                <div style="text-align: center; margin-bottom: 30px;">
                    <h1 style="color: #800000; margin: 0;">OnTheJob Tracker</h1>
                    <p style="color: #666; margin: 5px 0;">BULSU Student OJT Performance Monitoring System</p>
                </div>
                
                <h2 style="color: #333;">New Verification Code</h2>
                <p style="color: #555; line-height: 1.6;">
                    You requested a new verification code. Here is your new OTP:
                </p>
                
                <div style="background-color: #f8f9fa; padding: 20px; border-radius: 8px; margin: 20px 0; text-align: center;">
                    <h3 style="color: #800000; margin: 0 0 10px 0;">Your New Verification Code:</h3>
                    <div style="font-size: 32px; font-weight: bold; color: #800000; letter-spacing: 5px; font-family: monospace;">
                        ' . $new_otp . '
                    </div>
                </div>
                
                <div style="background-color: #fff3cd; padding: 15px; border-radius: 5px; border-left: 4px solid #DAA520; margin: 20px 0;">
                    <p style="margin: 0; color: #856404;">
                        <strong>Important:</strong> This OTP is valid for 5 minutes only. Do not share this code with anyone.
                    </p>
                </div>
                
                <div style="background-color: #f8f9fa; padding: 20px; border-radius: 8px; margin: 20px 0; border-left: 4px solid #800000;">
                    <h3 style="color: #800000; margin: 0 0 15px 0;">Your Registration Details:</h3>
                    <div style="color: #555;">
                        <p style="margin: 5px 0;"><strong>Student ID:</strong> ' . htmlspecialchars($student_data['student_id']) . '</p>
                        <p style="margin: 5px 0;"><strong>Program:</strong> ' . htmlspecialchars($student_data['program']) . '</p>
                        <p style="margin: 5px 0;"><strong>Department:</strong> ' . htmlspecialchars($student_data['department']) . '</p>
                        <p style="margin: 5px 0;"><strong>Year Level:</strong> ' . htmlspecialchars($student_data['year_level']) . '</p>
                    </div>
                </div>
            </div>';
            
            $mail->send();
            $verification_message = "New OTP has been sent to your email address.";
            
        } catch (Exception $e) {
            $verification_error = "Failed to send new OTP. Please try again.";
        }
    }
}

// Calculate remaining time
$remaining_time = $_SESSION['otp_expiry'] - time();
$remaining_minutes = max(0, floor($remaining_time / 60));
$remaining_seconds = max(0, $remaining_time % 60);

// Determine the back link based on referrer or session
$back_link = 'signup.php';
$back_text = 'Back to Sign Up';

if (isset($_SESSION['student_id'])) {
    // User is logged in, show back to dashboard
    $back_link = 'studentdashboard.php';
    $back_text = 'Back to Dashboard';
} elseif (isset($_SESSION['verification_source']) && $_SESSION['verification_source'] == 'dashboard') {
    $back_link = 'studentdashboard.php';
    $back_text = 'Back to Dashboard';
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Email Verification - BULSU OnTheJob Tracker</title>
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
        @keyframes fadeInUp {
            from { opacity: 0; transform: translateY(30px);}
            to { opacity: 1; transform: translateY(0);}
        }
        .animate-fadeInUp { animation: fadeInUp 0.8s ease-out;}
        
        @keyframes pulse {
            0%, 100% { opacity: 1; }
            50% { opacity: 0.5; }
        }
        .animate-pulse-slow { animation: pulse 2s infinite; }
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
        <div class="bg-white bg-opacity-95 rounded-2xl shadow-2xl p-8 md:p-12 max-w-lg w-full animate-fadeInUp border-t-4 border-bulsu-maroon">
            <div class="text-center mb-8">
                <div class="mb-6">
                    <h3 class="text-bulsu-gold font-semibold text-lg mb-2">Bulacan State University</h3>
                    <div class="w-24 h-1 bg-bulsu-gold mx-auto rounded"></div>
                </div>
                <div class="text-5xl mb-4 bg-gradient-to-br from-bulsu-light-gold to-bulsu-gold text-bulsu-maroon rounded-full w-20 h-20 flex items-center justify-center mx-auto">📧</div>
                <h1 class="text-bulsu-maroon text-2xl md:text-3xl font-bold mb-2">Verify Your Email</h1>
                <p class="text-gray-600">We've sent a verification code to your email address</p>
            </div>

            <div class="bg-gradient-to-r from-bulsu-light-gold from-opacity-30 to-bulsu-gold to-opacity-20 border border-bulsu-gold border-opacity-50 rounded-lg p-4 mb-6 text-center">
                <p class="text-gray-700 mb-1">Verification code sent to:</p>
                <p class="font-bold text-bulsu-maroon text-lg break-all"><?php echo htmlspecialchars($email); ?></p>
            </div>

            <div class="bg-blue-50 border border-blue-200 rounded-lg p-4 mb-6 border-l-4 border-l-blue-400">
                <h3 class="text-blue-800 font-semibold mb-3 flex items-center">
                    <span class="mr-2">📋</span>Check Your Email
                </h3>
                <ul class="text-blue-700 text-sm space-y-1 list-disc list-inside">
                    <li>Look for an email from OnTheJob Tracker</li>
                    <li>Check your spam/junk folder if you don't see it</li>
                    <li>Enter the 6-digit code below</li>
                    <li>Code expires in 5 minutes</li>
                </ul>
            </div>

            <?php if ($verification_error): ?>
                <div class="bg-red-50 border border-red-200 text-red-700 px-4 py-3 rounded-lg mb-4 border-l-4 border-l-red-400">
                    <div class="flex items-center">
                        <span class="mr-2">⚠️</span>
                        <?php echo htmlspecialchars($verification_error); ?>
                    </div>
                </div>
            <?php endif; ?>

            <?php if ($verification_message): ?>
                <div class="bg-green-50 border border-green-200 text-green-700 px-4 py-3 rounded-lg mb-4 border-l-4 border-l-green-400">
                    <div class="flex items-center">
                        <span class="mr-2">✅</span>
                        <?php echo htmlspecialchars($verification_message); ?>
                    </div>
                </div>
            <?php endif; ?>

            <form method="POST" class="mb-6">
                <div class="mb-4">
                    <label for="otp" class="block text-gray-700 font-medium mb-2">Enter Verification Code</label>
                    <input 
                        type="text" 
                        id="otp"
                        name="otp" 
                        class="w-full px-4 py-3 text-2xl text-center border-2 border-gray-200 rounded-lg focus:outline-none focus:border-bulsu-maroon transition font-mono tracking-widest" 
                        placeholder="000000" 
                        maxlength="6" 
                        pattern="[0-9]{6}" 
                        required
                        autocomplete="off"
                        inputmode="numeric"
                    >
                </div>
                
                <div class="mb-6 p-4 rounded-lg border-l-4 <?php echo ($remaining_time <= 0) ? 'bg-red-50 border-l-red-400' : 'bg-yellow-50 border-l-yellow-400'; ?>">
                    <div class="flex items-center text-sm font-medium <?php echo ($remaining_time <= 0) ? 'text-red-700' : 'text-yellow-700'; ?>">
                        <span class="mr-2"><?php echo ($remaining_time <= 0) ? '⚠️' : '⏰'; ?></span>
                        <?php if ($remaining_time > 0): ?>
                            Code expires in: <span id="timer" class="ml-1 font-mono font-bold"><?php echo sprintf('%02d:%02d', $remaining_minutes, $remaining_seconds); ?></span>
                        <?php else: ?>
                            Verification code has expired
                        <?php endif; ?>
                    </div>
                </div>

                <button 
                    type="submit" 
                    name="verify_otp" 
                    class="w-full bg-gradient-to-r from-bulsu-maroon to-bulsu-dark-maroon text-white rounded-lg py-3 font-semibold shadow-lg hover:from-bulsu-dark-maroon hover:to-black transition transform hover:scale-105 disabled:bg-gray-400 disabled:cursor-not-allowed disabled:transform-none disabled:hover:from-gray-400 disabled:hover:to-gray-400"
                    <?php echo ($remaining_time <= 0) ? 'disabled' : ''; ?>
                >
                    <?php echo ($remaining_time <= 0) ? 'Code Expired' : 'Verify Account'; ?>
                </button>
            </form>

            <form method="POST" class="mb-6">
                <button 
                    type="submit" 
                    name="resend_otp" 
                    class="w-full bg-transparent border-2 border-bulsu-gold text-bulsu-maroon rounded-lg py-3 font-semibold hover:bg-bulsu-gold hover:text-white transition transform hover:scale-105"
                >
                    📨 Resend Verification Code
                </button>
            </form>

            <div class="text-center pt-6 mt-6 border-t border-gray-200">
                <a href="<?php echo $back_link; ?>" class="text-gray-500 hover:text-bulsu-maroon transition font-medium">
                    ← <?php echo $back_text; ?>
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

    <script>
        // Mobile menu toggle
        const menuBtn = document.getElementById('menu-btn');
        const mobileMenu = document.getElementById('mobile-menu');
        if (menuBtn && mobileMenu) {
            menuBtn.addEventListener('click', () => {
                mobileMenu.classList.toggle('hidden');
            });
        }

        // Close menu when clicking outside
        document.addEventListener('click', function(e) {
            if (!mobileMenu.classList.contains('hidden') && !menuBtn.contains(e.target) && !mobileMenu.contains(e.target)) {
                mobileMenu.classList.add('hidden');
            }
        });

        // Timer countdown
        let remainingTime = <?php echo max(0, $remaining_time); ?>;
        const timerElement = document.getElementById('timer');
        const verifyBtn = document.querySelector('button[name="verify_otp"]');
        const timerDisplay = document.querySelector('.mb-6.p-4');
        const timerText = timerDisplay?.querySelector('div');

        function updateTimer() {
            if (remainingTime <= 0) {
                if (timerElement) {
                    timerElement.textContent = '00:00';
                }
                if (verifyBtn) {
                    verifyBtn.disabled = true;
                    verifyBtn.textContent = 'Code Expired';
                }
                if (timerDisplay) {
                    timerDisplay.className = timerDisplay.className.replace('bg-yellow-50 border-l-yellow-400', 'bg-red-50 border-l-red-400');
                }
                if (timerText) {
                    timerText.className = timerText.className.replace('text-yellow-700', 'text-red-700');
                    timerText.innerHTML = '<span class="mr-2">⚠️</span>Verification code has expired';
                }
                return;
            }

            const minutes = Math.floor(remainingTime / 60);
            const seconds = remainingTime % 60;
            
            if (timerElement) {
                timerElement.textContent = `${minutes.toString().padStart(2, '0')}:${seconds.toString().padStart(2, '0')}`;
            }
            
            remainingTime--;
        }

        // Update timer every second
        if (remainingTime > 0) {
            const timerInterval = setInterval(updateTimer, 1000);
            
            // Clear interval when timer reaches 0
            setTimeout(() => {
                clearInterval(timerInterval);
            }, remainingTime * 1000);
        }

        // Enhanced OTP input handling
        const otpInput = document.getElementById('otp');
        if (otpInput) {
            // Store cursor position
            let cursorPosition = 0;
            
            otpInput.addEventListener('beforeinput', function(e) {
                cursorPosition = this.selectionStart;
            });

            otpInput.addEventListener('input', function(e) {
                const currentPos = this.selectionStart;
                const originalValue = this.value;
                
                // Only allow numbers and limit to 6 digits
                let cleanValue = originalValue.replace(/[^0-9]/g, '');
                if (cleanValue.length > 6) {
                    cleanValue = cleanValue.slice(0, 6);
                }
                
                // Only update if value actually changed
                if (this.value !== cleanValue) {
                    this.value = cleanValue;
                    
                    // Restore cursor position
                    const newPos = Math.min(currentPos, cleanValue.length);
                    this.setSelectionRange(newPos, newPos);
                }
            });

            // Prevent paste of non-numeric content
            // Complete the paste event handler for OTP input
otpInput.addEventListener('paste', function(e) {
    e.preventDefault();
    const paste = (e.clipboardData || window.clipboardData).getData('text');
    
    // Only allow numeric characters and limit to 6 digits
    const cleanPaste = paste.replace(/[^0-9]/g, '').slice(0, 6);
    
    if (cleanPaste) {
        this.value = cleanPaste;
        // Move cursor to end
        this.setSelectionRange(cleanPaste.length, cleanPaste.length);
        
        // Auto-submit if 6 digits are pasted
        if (cleanPaste.length === 6) {
            // Optional: Auto-submit the form after a short delay
            setTimeout(() => {
                if (verifyBtn && !verifyBtn.disabled) {
                    verifyBtn.click();
                }
            }, 500);
        }
    }
});

// Auto-focus OTP input when page loads
otpInput.focus();

// Auto-submit when 6 digits are entered
otpInput.addEventListener('input', function(e) {
    if (this.value.length === 6 && remainingTime > 0) {
        // Optional: Auto-submit after a short delay
        setTimeout(() => {
            if (verifyBtn && !verifyBtn.disabled) {
                verifyBtn.click();
            }
        }, 800);
    }
});
        }
</script>
</body>
</html>
