<?php
include('connect.php');
session_start();

// Set timezone consistency
date_default_timezone_set('Asia/Manila');
mysqli_query($conn, "SET time_zone = '+08:00'");

require './PHPMailer/PHPMailer/src/Exception.php';
require './PHPMailer/PHPMailer/src/PHPMailer.php';
require './PHPMailer/PHPMailer/src/SMTP.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

$step = isset($_GET['step']) ? $_GET['step'] : 'request';
$message = '';
$error = '';

// Function to generate secure 6-digit code
function generateResetCode() {
    $code = '';
    for ($i = 0; $i < 6; $i++) {
        $code .= mt_rand(0, 9);
    }
    return $code;
}

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        
        // Step 1: Request password reset
        if ($_POST['action'] === 'request_reset') {
            $email = trim($_POST['email']);
            
            if (empty($email)) {
                $error = "Please enter your email address.";
            } else {
                // Check if email exists in database
                $query = "SELECT * FROM students WHERE email = ?";
                $stmt = mysqli_prepare($conn, $query);
                mysqli_stmt_bind_param($stmt, "s", $email);
                mysqli_stmt_execute($stmt);
                $result = mysqli_stmt_get_result($stmt);
                
                if (mysqli_num_rows($result) > 0) {
                    $user = mysqli_fetch_assoc($result);
                    
                    // Check if account is blocked
                    if ($user['status'] === 'Blocked') {
                        $error = "Your account has been blocked. Please contact support.";
                    } else {
                        // Clear any existing reset codes first
                        $clear_query = "UPDATE students SET reset_code = NULL, reset_code_expiry = NULL WHERE email = ?";
                        $clear_stmt = mysqli_prepare($conn, $clear_query);
                        mysqli_stmt_bind_param($clear_stmt, "s", $email);
                        mysqli_stmt_execute($clear_stmt);
                        
                        // Generate reset code ONCE and use the same code for both database and email
                        $reset_code = generateResetCode();
                        $reset_expiry = date('Y-m-d H:i:s', time() + 900); // 15 minutes from now
                        
                        // Debug: Log the generated code
                        error_log("Generated reset code for $email: '$reset_code' (length: " . strlen($reset_code) . ")");
                        
                        // Update database with reset code (store as VARCHAR/TEXT)
                        $update_query = "UPDATE students SET reset_code = ?, reset_code_expiry = ? WHERE email = ?";
                        $update_stmt = mysqli_prepare($conn, $update_query);
                        mysqli_stmt_bind_param($update_stmt, "sss", $reset_code, $reset_expiry, $email);
                        
                        if (mysqli_stmt_execute($update_stmt)) {
                            // Verify what was actually saved to database
                            $verify_query = "SELECT reset_code FROM students WHERE email = ?";
                            $verify_stmt = mysqli_prepare($conn, $verify_query);
                            mysqli_stmt_bind_param($verify_stmt, "s", $email);
                            mysqli_stmt_execute($verify_stmt);
                            $verify_result = mysqli_stmt_get_result($verify_stmt);
                            $verify_data = mysqli_fetch_assoc($verify_result);
                            error_log("Saved to DB: '" . $verify_data['reset_code'] . "' (length: " . strlen($verify_data['reset_code']) . ")");
                            
                            // Send reset email using the SAME code
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
                                $mail->Subject = 'Password Reset Request - OnTheJob Tracker';
                                
                                // Debug: Log the code being sent in email
                                error_log("Sending in email: '$reset_code'");
                                
                                $mail->Body = '
                                <div style="font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto; padding: 20px; border: 1px solid #ddd; border-radius: 10px;">
                                    <div style="text-align: center; margin-bottom: 30px;">
                                        <h1 style="color: #800000; margin: 0;">OnTheJob Tracker</h1>
                                        <p style="color: #666; margin: 5px 0;">BULSU Student OJT Performance Monitoring System</p>
                                    </div>
                                    
                                    <h2 style="color: #333;">Password Reset Request</h2>
                                    <p style="color: #555; line-height: 1.6;">
                                        Hello ' . htmlspecialchars($user['first_name']) . ',<br><br>
                                        We received a request to reset your password for your OnTheJob Tracker account. If you made this request, please use the verification code below to reset your password.
                                    </p>
                                    
                                    <div style="background-color: #f8f9fa; padding: 20px; border-radius: 8px; margin: 20px 0; text-align: center;">
                                        <h3 style="color: #800000; margin: 0 0 10px 0;">Your Password Reset Code:</h3>
                                        <div style="font-size: 32px; font-weight: bold; color: #DAA520; letter-spacing: 5px; font-family: monospace;">
                                            ' . $reset_code . '
                                        </div>
                                    </div>
                                    
                                    <div style="background-color: #fef3cd; padding: 15px; border-radius: 5px; border-left: 4px solid #DAA520; margin: 20px 0;">
                                        <p style="margin: 0; color: #92400e;">
                                            <strong>Important:</strong> This code is valid for 15 minutes only. Do not share this code with anyone.
                                        </p>
                                    </div>
                                    
                                    <div style="margin: 30px 0;">
                                        <h4 style="color: #333;">Security Information:</h4>
                                        <ul style="color: #555; line-height: 1.8;">
                                            <li><strong>Request Time:</strong> ' . date('F j, Y g:i A') . '</li>
                                            <li><strong>Student ID:</strong> ' . htmlspecialchars($user['student_id']) . '</li>
                                            <li><strong>Account Email:</strong> ' . htmlspecialchars($email) . '</li>
                                        </ul>
                                    </div>
                                    
                                    <p style="color: #555; line-height: 1.6;">
                                        If you did not request a password reset, please ignore this email or contact our support team immediately. Your account security is important to us.
                                    </p>
                                    
                                    <div style="text-align: center; margin-top: 30px; padding-top: 20px; border-top: 1px solid #eee;">
                                        <p style="color: #666; margin: 0;">
                                            <strong>OnTheJob Tracker Team</strong><br>
                                            <small>BULSU AI-Powered OJT Performance Monitoring</small>
                                        </p>
                                    </div>
                                </div>';
                                
                                $mail->send();
                                
                                // Store email in session for verification step
                                $_SESSION['reset_email'] = $email;
                                $_SESSION['reset_requested'] = true;
                                
                                header("Location: forgot.php?step=verify");
                                exit;
                                
                            } catch (Exception $e) {
                                $error = "Failed to send reset email. Please try again later.";
                                error_log("Password reset email failed: " . $e->getMessage());
                            }
                        } else {
                            $error = "An error occurred. Please try again.";
                            error_log("Database update failed: " . mysqli_error($conn));
                        }
                    }
                } else {
                    $error = "No account found with this email address.";
                }
            }
        }
        
        // Step 2: Verify reset code - FIXED VERSION
        elseif ($_POST['action'] === 'verify_code') {
            $reset_code = trim($_POST['reset_code']);
            $email = $_SESSION['reset_email'] ?? '';
            
            if (empty($reset_code)) {
                $error = "Please enter the verification code.";
            } elseif (empty($email)) {
                $error = "Session expired. Please start over.";
                header("Location: forgot.php?step=request");
                exit;
            } else {
                // Debug: Log what we're comparing
                error_log("Verifying code for $email. Input: '$reset_code' (length: " . strlen($reset_code) . ")");
                
                // Get current timestamp in same format as database
                $current_time = date('Y-m-d H:i:s');
                
                // First, get the user's reset data
                $query = "SELECT reset_code, reset_code_expiry FROM students WHERE email = ?";
                $stmt = mysqli_prepare($conn, $query);
                mysqli_stmt_bind_param($stmt, "s", $email);
                mysqli_stmt_execute($stmt);
                $result = mysqli_stmt_get_result($stmt);
                
                if (mysqli_num_rows($result) > 0) {
                    $user_data = mysqli_fetch_assoc($result);
                    $db_code = trim($user_data['reset_code']); // Trim whitespace
                    $expiry_time = $user_data['reset_code_expiry'];
                    
                    // Debug logging
                    error_log("DB Code: '$db_code' (length: " . strlen($db_code) . ")");
                    error_log("Expiry: $expiry_time, Current: $current_time");
                    error_log("Code match: " . ($reset_code === $db_code ? 'YES' : 'NO'));
                    error_log("Time valid: " . ($current_time <= $expiry_time ? 'YES' : 'NO'));
                    
                    // Check if codes match exactly (string comparison)
                    if ($reset_code === $db_code) {
                        // Check if code is still valid (not expired)
                        if ($current_time <= $expiry_time) {
                            // Code is valid and not expired
                            $_SESSION['code_verified'] = true;
                            error_log("Code verification successful for $email");
                            header("Location: forgot.php?step=reset");
                            exit;
                        } else {
                            // Code is correct but expired
                            $error = "Verification code has expired. Please request a new one.";
                            error_log("Code expired for $email. Expiry: $expiry_time, Current: $current_time");
                        }
                    } else {
                        // Code doesn't match
                        $error = "Invalid verification code. Please check and try again.";
                        error_log("Code mismatch for $email. Expected: '$db_code', Got: '$reset_code'");
                    }
                } else {
                    $error = "No reset request found. Please start over.";
                    error_log("No reset data found for $email");
                }
            }
        }
        
        // Step 3: Reset password
        elseif ($_POST['action'] === 'reset_password') {
            $new_password = $_POST['new_password'] ?? '';
            $confirm_password = $_POST['confirm_password'] ?? '';
            $email = $_SESSION['reset_email'] ?? '';
            
            if (empty($new_password) || empty($confirm_password)) {
                $error = "Please fill in all fields.";
            } elseif ($new_password !== $confirm_password) {
                $error = "Passwords do not match.";
            } elseif (strlen($new_password) < 8) {
                $error = "Password must be at least 8 characters long.";
            } elseif (empty($email)) {
                $error = "Session expired. Please start over.";
                header("Location: forgot.php?step=request");
                exit;
            } else {
                // Hash new password
                $hashed_password = password_hash($new_password, PASSWORD_BCRYPT);
                
                // Update password and clear reset code
                $update_query = "UPDATE students SET password = ?, reset_code = NULL, reset_code_expiry = NULL WHERE email = ?";
                $update_stmt = mysqli_prepare($conn, $update_query);
                mysqli_stmt_bind_param($update_stmt, "ss", $hashed_password, $email);
                
                if (mysqli_stmt_execute($update_stmt)) {
                    // Clear session data
                    unset($_SESSION['reset_email']);
                    unset($_SESSION['reset_requested']);
                    unset($_SESSION['code_verified']);
                    
                    // Set success flag instead of using alert
                    $_SESSION['password_reset_success'] = true;
                    header("Location: forgot.php?step=success");
                    exit;
                } else {
                    $error = "Failed to reset password. Please try again.";
                    error_log("Password reset failed: " . mysqli_error($conn));
                }
            }
        }
        
        if ($step === 'success' && isset($_SESSION['password_reset_success'])) {
            unset($_SESSION['password_reset_success']);
            // This will show the success modal
        }
        
        // Resend code
        elseif ($_POST['action'] === 'resend_code') {
            $email = $_SESSION['reset_email'] ?? '';
            
            if (empty($email)) {
                $error = "Session expired. Please start over.";
                header("Location: forgot.php?step=request");
                exit;
            }
            
            // Clear any existing reset codes first
            $clear_query = "UPDATE students SET reset_code = NULL, reset_code_expiry = NULL WHERE email = ?";
            $clear_stmt = mysqli_prepare($conn, $clear_query);
            mysqli_stmt_bind_param($clear_stmt, "s", $email);
            mysqli_stmt_execute($clear_stmt);
            
            // Generate new reset code ONCE
            $reset_code = generateResetCode();
            $reset_expiry = date('Y-m-d H:i:s', time() + 900); // 15 minutes from now
            
            // Debug: Log the new code
            error_log("Generated new reset code for $email: '$reset_code' (length: " . strlen($reset_code) . ")");
            
            // Update database with new reset code
            $update_query = "UPDATE students SET reset_code = ?, reset_code_expiry = ? WHERE email = ?";
            $update_stmt = mysqli_prepare($conn, $update_query);
            mysqli_stmt_bind_param($update_stmt, "sss", $reset_code, $reset_expiry, $email);
            
            if (mysqli_stmt_execute($update_stmt)) {
                // Verify what was actually saved
                $verify_query = "SELECT reset_code FROM students WHERE email = ?";
                $verify_stmt = mysqli_prepare($conn, $verify_query);
                mysqli_stmt_bind_param($verify_stmt, "s", $email);
                mysqli_stmt_execute($verify_stmt);
                $verify_result = mysqli_stmt_get_result($verify_stmt);
                $verify_data = mysqli_fetch_assoc($verify_result);
                error_log("New code saved to DB: '" . $verify_data['reset_code'] . "'");
                
                // Get user data for email
                $user_query = "SELECT * FROM students WHERE email = ?";
                $user_stmt = mysqli_prepare($conn, $user_query);
                mysqli_stmt_bind_param($user_stmt, "s", $email);
                mysqli_stmt_execute($user_stmt);
                $user_result = mysqli_stmt_get_result($user_stmt);
                $user = mysqli_fetch_assoc($user_result);
                
                // Send new reset email using the SAME code
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
                    $mail->Subject = 'New Password Reset Code - OnTheJob Tracker';
                    
                    // Debug: Log the code being sent
                    error_log("Sending new code in email: '$reset_code'");
                    
                    $mail->Body = '
                    <div style="font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto; padding: 20px; border: 1px solid #ddd; border-radius: 10px;">
                        <div style="text-align: center; margin-bottom: 30px;">
                            <h1 style="color: #800000; margin: 0;">OnTheJob Tracker</h1>
                            <p style="color: #666; margin: 5px 0;">BULSU Student OJT Performance Monitoring System</p>
                        </div>
                        
                        <h2 style="color: #333;">New Password Reset Code</h2>
                        <p style="color: #555; line-height: 1.6;">
                            Hello ' . htmlspecialchars($user['first_name']) . ',<br><br>
                            You requested a new password reset code. Here is your new verification code:
                        </p>
                        
                        <div style="background-color: #f8f9fa; padding: 20px; border-radius: 8px; margin: 20px 0; text-align: center;">
                            <h3 style="color: #800000; margin: 0 0 10px 0;">Your New Password Reset Code:</h3>
                            <div style="font-size: 32px; font-weight: bold; color: #DAA520; letter-spacing: 5px; font-family: monospace;">
                                ' . $reset_code . '
                            </div>
                        </div>
                        
                        <div style="background-color: #fef3cd; padding: 15px; border-radius: 5px; border-left: 4px solid #DAA520; margin: 20px 0;">
                            <p style="margin: 0; color: #92400e;">
                                <strong>Important:</strong> This new code is valid for 15 minutes only.
                            </p>
                        </div>
                    </div>';
                    
                    $mail->send();
                    $message = "A new verification code has been sent to your email.";
                    
                } catch (Exception $e) {
                    $error = "Failed to send new code. Please try again.";
                    error_log("Resend email failed: " . $e->getMessage());
                }
            } else {
                $error = "Failed to generate new code. Please try again.";
                error_log("Database update failed for resend: " . mysqli_error($conn));
            }
        }
    }
}

// Check session validity for steps
if ($step === 'verify' && !isset($_SESSION['reset_requested'])) {
    header("Location: forgot.php?step=request");
    exit;
}

if ($step === 'reset' && !isset($_SESSION['code_verified'])) {
    header("Location: forgot.php?step=request");
    exit;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>
        <?php 
        if ($step === 'request') echo 'Forgot Password';
        elseif ($step === 'verify') echo 'Verify Reset Code';
        elseif ($step === 'reset') echo 'Reset Password';
        ?> - BULSU OnTheJob Tracker
    </title>
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
        @keyframes fadeInUp {
            from { opacity: 0; transform: translateY(40px);}
            to { opacity: 1; transform: translateY(0);}
        }
        .animate-fadeInUp { animation: fadeInUp 0.8s ease-out;}
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
                    <a href="index.php">
                        <span>OnTheJob</span>
                        <span class="ml-2">Tracker</span>
                        <span class="mx-4 font-bold text-bulsu-gold">|||</span>
                    </a>
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
        <div class="bg-white bg-opacity-95 rounded-2xl shadow-2xl p-8 md:p-12 max-w-lg w-full animate-fadeInUp">
            <!-- BULSU Header -->
            <div class="mb-8">
                <div class="mb-6 text-center">
                    <h3 class="text-bulsu-gold font-semibold text-lg mb-2">Bulacan State University</h3>
                    <div class="w-24 h-1 bg-bulsu-gold mx-auto rounded"></div>
                </div>

                <!-- Progress Steps -->
                <div class="flex justify-center items-center mb-8">
                    <div class="flex items-center">
                        <div class="flex items-center">
                            <div class="w-8 h-8 rounded-full <?php echo ($step === 'request') ? 'bg-bulsu-maroon text-white' : 'bg-gray-300 text-gray-600'; ?> flex items-center justify-center text-sm font-bold">1</div>
                            <span class="ml-2 text-sm <?php echo ($step === 'request') ? 'text-bulsu-maroon font-semibold' : 'text-gray-500'; ?>">Request</span>
                        </div>
                        <div class="w-12 h-0.5 bg-gray-300 mx-4"></div>
                        <div class="flex items-center">
                            <div class="w-8 h-8 rounded-full <?php echo ($step === 'verify') ? 'bg-bulsu-maroon text-white' : 'bg-gray-300 text-gray-600'; ?> flex items-center justify-center text-sm font-bold">2</div>
                            <span class="ml-2 text-sm <?php echo ($step === 'verify') ? 'text-bulsu-maroon font-semibold' : 'text-gray-500'; ?>">Verify</span>
                        </div>
                        <div class="w-12 h-0.5 bg-gray-300 mx-4"></div>
                        <div class="flex items-center">
                            <div class="w-8 h-8 rounded-full <?php echo ($step === 'reset') ? 'bg-bulsu-maroon text-white' : 'bg-gray-300 text-gray-600'; ?> flex items-center justify-center text-sm font-bold">3</div>
                            <span class="ml-2 text-sm <?php echo ($step === 'reset') ? 'text-bulsu-maroon font-semibold' : 'text-gray-500'; ?>">Reset</span>
                        </div>
                    </div>
                </div>
            </div>

            <?php if (!empty($error)): ?>
                <div class="bg-red-50 border border-red-200 text-red-700 px-4 py-3 rounded-lg mb-6">
                    <?php echo $error; ?>
                </div>
            <?php endif; ?>

            <?php if (!empty($message)): ?>
                <div class="bg-green-50 border border-green-200 text-green-700 px-4 py-3 rounded-lg mb-6">
                    <?php echo $message; ?>
                </div>
            <?php endif; ?>

            <?php if ($step === 'request'): ?>
                <!-- Step 1: Request Password Reset -->
                <div class="text-center mb-8">
                    <h1 class="text-bulsu-maroon text-3xl font-bold mb-2">Forgot Password?</h1>
                    <p class="text-gray-600">Enter your email address and we'll send you a verification code to reset your password.</p>
                </div>
                <form method="post" class="space-y-6">
                    <input type="hidden" name="action" value="request_reset">
                    <div>
                        <label for="email" class="block text-sm font-medium text-gray-700 mb-2">Email Address</label>
                        <input type="email" id="email" name="email" class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-bulsu-gold focus:border-bulsu-gold" placeholder="Enter your registered email address" required>
                    </div>
                    <button type="submit" class="w-full bg-gradient-to-r from-bulsu-maroon to-bulsu-dark-maroon text-white py-3 rounded-lg font-semibold hover:from-bulsu-dark-maroon hover:to-bulsu-maroon transition duration-300 transform hover:scale-105">
                        Send Reset Code
                    </button>
                </form>
                <div class="text-center mt-6">
                    <a href="login.php" class="text-bulsu-maroon hover:text-bulsu-dark-maroon font-medium">← Back to Login</a>
                </div>

            <?php elseif ($step === 'verify'): ?>
                <!-- Step 2: Verify Reset Code -->
                <div class="text-center mb-8">
                    <h1 class="text-bulsu-maroon text-3xl font-bold mb-2">Verify Reset Code</h1>
                    <p class="text-gray-600">We've sent a 6-digit verification code to your email address. Please enter it below.</p>
                </div>
                <form method="post" class="space-y-6">
                    <input type="hidden" name="action" value="verify_code">
                    <div>
                        <label for="reset_code" class="block text-sm font-medium text-gray-700 mb-2">Verification Code</label>
                        <input type="text" id="reset_code" name="reset_code" maxlength="6" class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-bulsu-gold focus:border-bulsu-gold text-center text-2xl font-mono tracking-widest" placeholder="000000" required>
                        <p class="text-sm text-gray-500 mt-2">Enter the 6-digit code sent to your email</p>
                    </div>
                    <button type="submit" class="w-full bg-gradient-to-r from-bulsu-maroon to-bulsu-dark-maroon text-white py-3 rounded-lg font-semibold hover:from-bulsu-dark-maroon hover:to-bulsu-maroon transition duration-300 transform hover:scale-105">
                        Verify Code
                    </button>
                </form>
                
                <!-- Resend Code Option -->
                <div class="text-center mt-6">
                    <p class="text-gray-600 mb-2">Didn't receive the code?</p>
                    <form method="post" class="inline">
                        <input type="hidden" name="action" value="resend_code">
                        <button type="submit" class="text-bulsu-maroon hover:text-bulsu-dark-maroon font-medium underline">Resend Code</button>
                    </form>
                </div>
                <div class="text-center mt-4">
                    <a href="forgot.php?step=request" class="text-gray-500 hover:text-gray-700">← Start Over</a>
                </div>

            <?php elseif ($step === 'reset'): ?>
                <!-- Step 3: Reset Password -->
                <div class="text-center mb-8">
                    <h1 class="text-bulsu-maroon text-3xl font-bold mb-2">Reset Password</h1>
                    <p class="text-gray-600">Enter your new password below. Make sure it's strong and secure.</p>
                </div>
                <form method="post" class="space-y-6">
                    <input type="hidden" name="action" value="reset_password">
                    <div>
                        <label for="new_password" class="block text-sm font-medium text-gray-700 mb-2">New Password</label>
                        <input type="password" id="new_password" name="new_password" class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-bulsu-gold focus:border-bulsu-gold" placeholder="Enter new password" required>
                        <p class="text-sm text-gray-500 mt-1">Must be at least 8 characters long</p>
                    </div>
                    <div>
                        <label for="confirm_password" class="block text-sm font-medium text-gray-700 mb-2">Confirm Password</label>
                        <input type="password" id="confirm_password" name="confirm_password" class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-bulsu-gold focus:border-bulsu-gold" placeholder="Confirm new password" required>
                    </div>
                    <button type="submit" class="w-full bg-gradient-to-r from-bulsu-maroon to-bulsu-dark-maroon text-white py-3 rounded-lg font-semibold hover:from-bulsu-dark-maroon hover:to-bulsu-maroon transition duration-300 transform hover:scale-105">
                        Reset Password
                    </button>
                </form>
                <div class="text-center mt-6">
                    <a href="forgot.php?step=request" class="text-gray-500 hover:text-gray-700">← Start Over</a>
                </div>

            <?php elseif ($step === 'success'): ?>
                <!-- Success Step -->
                <div class="text-center">
                    <div class="mb-6">
                        <div class="w-16 h-16 bg-green-100 rounded-full flex items-center justify-center mx-auto mb-4">
                            <svg class="w-8 h-8 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
                            </svg>
                        </div>
                        <h1 class="text-bulsu-maroon text-3xl font-bold mb-2">Password Reset Successful!</h1>
                        <p class="text-gray-600">Your password has been successfully reset. You can now login with your new password.</p>
                    </div>
                    <a href="login.php" class="inline-block bg-gradient-to-r from-bulsu-maroon to-bulsu-dark-maroon text-white px-8 py-3 rounded-lg font-semibold hover:from-bulsu-dark-maroon hover:to-bulsu-maroon transition duration-300 transform hover:scale-105">
                        Go to Login
                    </a>
                </div>
            <?php endif; ?>
        </div>
    </main>

    <!-- Footer -->
    <footer class="bg-gradient-to-r from-bulsu-maroon to-bulsu-dark-maroon text-white py-8">
        <div class="max-w-6xl mx-auto px-4">
            <div class="grid md:grid-cols-3 gap-8">
                <div>
                    <div class="flex items-center mb-4">
                        <img src="reqsample/bulsu12.png" alt="BULSU Logo" class="w-8 h-8 mr-2">
                        <img src="reqsample/bulsu1.png" alt="BULSU Logo" class="w-8 h-8 mr-2">
                        <h3 class="text-lg font-bold">OnTheJob Tracker</h3>
                    </div>
                    <p class="text-gray-300">BULSU AI-Powered Student OJT Performance Monitoring System</p>
                </div>
                <div>
                    <h4 class="font-semibold mb-3">Quick Links</h4>
                    <ul class="space-y-2">
                        <li><a href="index.php" class="text-gray-300 hover:text-bulsu-gold transition">Home</a></li>
                        <li><a href="login.php" class="text-gray-300 hover:text-bulsu-gold transition">Login</a></li>
                        <li><a href="signup.php" class="text-gray-300 hover:text-bulsu-gold transition">Sign Up</a></li>
                    </ul>
                </div>
                <div>
                    <h4 class="font-semibold mb-3">Contact Info</h4>
                    <div class="text-gray-300 space-y-2">
                        <p>📧 support@bulsu-ojt.edu.ph</p>
                        <p>📞 (044) 760-0000</p>
                        <p>📍 City of Malolos, Bulacan</p>
                    </div>
                </div>
            </div>
            <div class="border-t border-gray-600 mt-8 pt-6 text-center">
                <p class="text-gray-400">&copy; <?php echo date('Y'); ?> Bulacan State University. All rights reserved.</p>
            </div>
        </div>
    </footer>

    <script>
        // Mobile menu toggle
        document.addEventListener('DOMContentLoaded', function() {
            const menuBtn = document.getElementById('menu-btn');
            const mobileMenu = document.getElementById('mobile-menu');
            
            if (menuBtn && mobileMenu) {
                menuBtn.addEventListener('click', function() {
                    mobileMenu.classList.toggle('hidden');
                });
            }

            // Auto-format verification code input
            const resetCodeInput = document.getElementById('reset_code');
            if (resetCodeInput) {
                resetCodeInput.addEventListener('input', function(e) {
                    // Remove any non-numeric characters
                    this.value = this.value.replace(/\D/g, '');
                    
                    // Limit to 6 digits
                    if (this.value.length > 6) {
                        this.value = this.value.slice(0, 6);
                    }
                });

                // Auto-submit when 6 digits are entered
                resetCodeInput.addEventListener('keyup', function(e) {
                    if (this.value.length === 6) {
                        // Optional: Auto-submit after a short delay
                        setTimeout(() => {
                            if (this.value.length === 6) {
                                this.form.submit();
                            }
                        }, 500);
                    }
                });
            }

            // Password confirmation validation
            const newPassword = document.getElementById('new_password');
            const confirmPassword = document.getElementById('confirm_password');
            
            if (newPassword && confirmPassword) {
                function validatePasswords() {
                    if (confirmPassword.value && newPassword.value !== confirmPassword.value) {
                        confirmPassword.setCustomValidity('Passwords do not match');
                        confirmPassword.style.borderColor = '#ef4444';
                    } else {
                        confirmPassword.setCustomValidity('');
                        confirmPassword.style.borderColor = '';
                    }
                }

                newPassword.addEventListener('input', validatePasswords);
                confirmPassword.addEventListener('input', validatePasswords);
            }

            // Show/hide password toggle functionality
            function addPasswordToggle(inputId) {
                const input = document.getElementById(inputId);
                if (input) {
                    const toggleBtn = document.createElement('button');
                    toggleBtn.type = 'button';
                    toggleBtn.className = 'absolute right-3 top-1/2 transform -translate-y-1/2 text-gray-500 hover:text-gray-700';
                    toggleBtn.innerHTML = '👁️';
                    
                    input.parentElement.style.position = 'relative';
                    input.parentElement.appendChild(toggleBtn);
                    
                    toggleBtn.addEventListener('click', function() {
                        if (input.type === 'password') {
                            input.type = 'text';
                            this.innerHTML = '🙈';
                        } else {
                            input.type = 'password';
                            this.innerHTML = '👁️';
                        }
                    });
                }
            }

            // Add password toggles
            addPasswordToggle('new_password');
            addPasswordToggle('confirm_password');
        });

        // Show success modal if password was reset successfully
        <?php if ($step === 'success' && isset($_SESSION['password_reset_success'])): ?>
        // You can add a success animation or modal here if needed
        console.log('Password reset successful!');
        <?php endif; ?>
    </script>
</body>
</html>