<?php
include('connect.php');
session_start();

require './PHPMailer/PHPMailer/src/Exception.php';
require './PHPMailer/PHPMailer/src/PHPMailer.php';
require './PHPMailer/PHPMailer/src/SMTP.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

// Initialize variables to retain form data
$form_data = [
    'first_name' => '',
    'middle_name' => '',
    'last_name' => '',
    'gender' => '',
    'dob' => '',
    'student_id' => '',
    'contact_number' => '',
    'email' => '',
    'address' => '',
    'year_level' => '',
    'department' => '',
    'program' => '',
    'section' => '',
    'password' => '',
    'confirm_password' => ''
];

$errors = [];
$registration_success = false;
$email_sent = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Debug: Check if form is being submitted
    error_log("Form submitted with POST method");
    
    // Get form data and store in array for retention
    $form_data['first_name'] = trim($_POST['first_name'] ?? '');
    $form_data['middle_name'] = trim($_POST['middle_name'] ?? '');
    $form_data['last_name'] = trim($_POST['last_name'] ?? '');
    $form_data['gender'] = $_POST['gender'] ?? '';
    $form_data['dob'] = $_POST['dob'] ?? '';
    $form_data['student_id'] = trim($_POST['student_id'] ?? '');
    $form_data['contact_number'] = trim($_POST['contact_number'] ?? '');
    $form_data['email'] = trim($_POST['email'] ?? '');
    $form_data['address'] = trim($_POST['address'] ?? '');
    $form_data['year_level'] = $_POST['year_level'] ?? '';
    $form_data['department'] = trim($_POST['department'] ?? '');
    $form_data['program'] = trim($_POST['program'] ?? '');
    $form_data['section'] = trim($_POST['section'] ?? '');
    $form_data['password'] = $_POST['password'] ?? '';
    $form_data['confirm_password'] = $_POST['confirm_password'] ?? '';
    
    // Debug: Log received data
    error_log("Received data: " . json_encode($form_data));
    
    // Check required fields
    $required_fields = ['first_name', 'last_name', 'gender', 'dob', 'student_id', 'contact_number', 'email', 'address', 'year_level', 'department', 'program', 'section', 'password'];
    
    foreach ($required_fields as $field) {
        if (empty($form_data[$field])) {
            $errors[] = ucwords(str_replace('_', ' ', $field)) . " is required.";
        }
    }
    
    // Server-side validation
    
    // Check if passwords match
    if ($form_data['password'] !== $form_data['confirm_password']) {
        $errors[] = "Passwords do not match.";
    }
    
    // Check password strength
    if (strlen($form_data['password']) < 8) {
        $errors[] = "Password must be at least 8 characters long.";
    }
    
    // Validate email format
    if (!filter_var($form_data['email'], FILTER_VALIDATE_EMAIL)) {
        $errors[] = "Please enter a valid email address.";
    }
    
    // Validate contact number - must be exactly 11 digits
    if (!empty($form_data['contact_number'])) {
        // Remove any non-numeric characters
        $clean_number = preg_replace('/[^0-9]/', '', $form_data['contact_number']);
        
        if (strlen($clean_number) !== 11) {
            $errors[] = "Contact number must be exactly 11 digits.";
        } elseif (!preg_match('/^09\d{9}$/', $clean_number)) {
            $errors[] = "Contact number must start with 09 and be exactly 11 digits.";
        } else {
            // Store the cleaned number
            $form_data['contact_number'] = $clean_number;
        }
    }
    
    // Check if terms are agreed
    if (!isset($_POST['agree_terms'])) {
        $errors[] = "You must agree to the Terms and Conditions.";
    }
    
    // Only check database if basic validation passes
    if (empty($errors)) {
        // Check if email already exists
        $check_email_query = "SELECT * FROM students WHERE email = ?";
        $check_email_stmt = mysqli_prepare($conn, $check_email_query);
        
        if ($check_email_stmt) {
            mysqli_stmt_bind_param($check_email_stmt, "s", $form_data['email']);
            mysqli_stmt_execute($check_email_stmt);
            $check_email_result = mysqli_stmt_get_result($check_email_stmt);
            
            if (mysqli_num_rows($check_email_result) > 0) {
                $errors[] = "This email is already registered. Please use a different email.";
            }
            mysqli_stmt_close($check_email_stmt);
        } else {
            $errors[] = "Database error: " . mysqli_error($conn);
        }
        
        // Check if student ID already exists
        $check_student_id_query = "SELECT * FROM students WHERE student_id = ?";
        $check_student_id_stmt = mysqli_prepare($conn, $check_student_id_query);
        
        if ($check_student_id_stmt) {
            mysqli_stmt_bind_param($check_student_id_stmt, "s", $form_data['student_id']);
            mysqli_stmt_execute($check_student_id_stmt);
            $check_student_id_result = mysqli_stmt_get_result($check_student_id_stmt);
            
            if (mysqli_num_rows($check_student_id_result) > 0) {
                $errors[] = "This Student ID is already registered. Please check your Student ID.";
            }
            mysqli_stmt_close($check_student_id_stmt);
        } else {
            $errors[] = "Database error: " . mysqli_error($conn);
        }
        
        // Check if contact number already exists
        $check_contact_query = "SELECT * FROM students WHERE contact_number = ?";
        $check_contact_stmt = mysqli_prepare($conn, $check_contact_query);
        
        if ($check_contact_stmt) {
            mysqli_stmt_bind_param($check_contact_stmt, "s", $form_data['contact_number']);
            mysqli_stmt_execute($check_contact_stmt);
            $check_contact_result = mysqli_stmt_get_result($check_contact_stmt);
            
            if (mysqli_num_rows($check_contact_result) > 0) {
                $errors[] = "This contact number is already registered. Please use a different number.";
            }
            mysqli_stmt_close($check_contact_stmt);
        } else {
            $errors[] = "Database error: " . mysqli_error($conn);
        }
    }
    
    // If there are no errors, proceed with registration
    if (empty($errors)) {
        // Hash password
        $hashed_password = password_hash($form_data['password'], PASSWORD_BCRYPT);
        
        // Generate OTP for email verification
        $otp = rand(100000, 999999);
        
        // Insert student data into database without profile_picture
        $insert_query = "INSERT INTO students (
            first_name, middle_name, last_name, gender, date_of_birth, student_id, 
            contact_number, email, address, year_level, department, program, section, 
            password, verification_code, verified, status, 
            login_attempts, ready_for_deployment
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, 'Active', 0, 0)";
        
        $stmt = mysqli_prepare($conn, $insert_query);
        
        if (!$stmt) {
            $errors[] = 'Database preparation failed: ' . mysqli_error($conn);
            error_log('Statement preparation failed: ' . mysqli_error($conn));
        } else {
            // Bind parameters - 15 parameters total (removed profile_picture)
            mysqli_stmt_bind_param(
                $stmt,
                "ssssssssssssssi", 
                $form_data['first_name'], 
                $form_data['middle_name'], 
                $form_data['last_name'], 
                $form_data['gender'], 
                $form_data['dob'], 
                $form_data['student_id'],
                $form_data['contact_number'], 
                $form_data['email'], 
                $form_data['address'], 
                $form_data['year_level'], 
                $form_data['department'], 
                $form_data['program'], 
                $form_data['section'], 
                $hashed_password, 
                $otp
            );
            
            if (mysqli_stmt_execute($stmt)) {
                $registration_success = true;
                
                // Store email and OTP in session for verification
                $_SESSION['email'] = $form_data['email'];
                $_SESSION['otp'] = $otp;
                $_SESSION['otp_expiry'] = time() + 300; // 5 minutes expiry
                $_SESSION['student_name'] = $form_data['first_name'] . ' ' . $form_data['last_name'];
                $_SESSION['student_id'] = $form_data['student_id'];
                
                // Try to send verification email
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
                    $mail->addAddress($form_data['email']);
                    
                    $mail->isHTML(true);
                    $mail->Subject = 'Welcome to OnTheJob Tracker - Verify Your Email';
                    $mail->Body = '
                    <div style="font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto; padding: 20px; border: 1px solid #ddd; border-radius: 10px;">
                        <div style="text-align: center; margin-bottom: 30px;">
                            <h1 style="color: #800000; margin: 0;">OnTheJob Tracker</h1>
                            <p style="color: #666; margin: 5px 0;">Student OJT Performance Monitoring System</p>
                        </div>
                        
                        <h2 style="color: #333;">Welcome, ' . htmlspecialchars($form_data['first_name']) . '!</h2>
                        <p style="color: #555; line-height: 1.6;">
                            Thank you for registering with OnTheJob Tracker. To complete your registration and start monitoring your OJT performance with AI, please verify your email address.
                        </p>
                        
                        <div style="background-color: #f8f9fa; padding: 20px; border-radius: 8px; margin: 20px 0; text-align: center;">
                            <h3 style="color: #800000; margin: 0 0 10px 0;">Your Verification Code:</h3>
                            <div style="font-size: 32px; font-weight: bold; color: #800000; letter-spacing: 5px; font-family: monospace;">
                                ' . $otp . '
                            </div>
                        </div>
                        
                        <div style="background-color: #fef3cd; padding: 15px; border-radius: 5px; border-left: 4px solid #DAA520; margin: 20px 0;">
                            <p style="margin: 0; color: #92400e;">
                                <strong>Important:</strong> This OTP is valid for 5 minutes only. Do not share this code with anyone.
                            </p>
                        </div>
                        
                        <div style="margin: 30px 0;">
                            <h4 style="color: #333;">Your Registration Details:</h4>
                            <ul style="color: #555; line-height: 1.8;">
                                <li><strong>Student ID:</strong> ' . htmlspecialchars($form_data['student_id']) . '</li>
                                <li><strong>Program:</strong> ' . htmlspecialchars($form_data['program']) . '</li>
                                <li><strong>Department:</strong> ' . htmlspecialchars($form_data['department']) . '</li>
                                <li><strong>Year Level:</strong> ' . htmlspecialchars($form_data['year_level']) . '</li>
                            </ul>
                        </div>
                        
                        <p style="color: #555; line-height: 1.6;">
                            If you did not create this account, please ignore this email or contact our support team immediately.
                        </p>
                        
                        <div style="text-align: center; margin-top: 30px; padding-top: 20px; border-top: 1px solid #eee;">
                            <p style="color: #666; margin: 0;">
                                <strong>OnTheJob Tracker Team</strong><br>
                                <small>AI-Powered OJT Performance Monitoring</small>
                            </p>
                        </div>
                    </div>';
                    
                    $mail->send();
                    $email_sent = true;
                    
                } catch (Exception $e) {
                    // If email sending fails, log the error but still show success
                    error_log("Email sending failed: " . $e->getMessage());
                    $email_sent = false;
                }
            } else {
                $errors[] = "Registration failed: " . mysqli_stmt_error($stmt);
                error_log("Registration failed: " . mysqli_stmt_error($stmt));
            }
            
            mysqli_stmt_close($stmt);
        }
    }
    
    // Debug: Log any errors
    if (!empty($errors)) {
        error_log("Registration errors: " . json_encode($errors));
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sign Up - BULSU OnTheJob Tracker</title>
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

    <!-- Success Modal -->
    <?php if ($registration_success): ?>
    <div class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50">
        <div class="bg-white rounded-xl shadow-xl p-8 max-w-md w-full text-center animate-fadeInUp">
            <div class="text-5xl text-bulsu-gold mb-4">✅</div>
            <h2 class="text-bulsu-maroon text-2xl font-bold mb-2">Registration Successful!</h2>
            <div class="text-gray-700 mb-4">
                <p><strong>Welcome, <?php echo htmlspecialchars($form_data['first_name']); ?>!</strong></p>
                <p>Your account has been created successfully.</p>
                <?php if ($email_sent): ?>
                    <p>We've sent a verification code to <strong><?php echo htmlspecialchars($form_data['email']); ?></strong></p>
                    <p>You can verify your email now or skip verification and do it later.</p>
                    <p><strong>Note:</strong> You can upload your profile picture after logging into the system.</p>
                <?php else: ?>
                    <p>There was an issue sending the verification email, but your account is ready to use.</p>
                    <p>You can verify your email later from your dashboard.</p>
                <?php endif; ?>
            </div>
            <div class="flex flex-col md:flex-row gap-4 justify-center mt-4">
                <?php if ($email_sent): ?>
                    <a href="verification.php" class="bg-bulsu-maroon hover:bg-bulsu-dark-maroon text-white px-6 py-2 rounded font-semibold transition">Verify Email Now</a>
                <?php endif; ?>
                <a href="login.php" class="bg-gray-500 hover:bg-gray-700 text-white px-6 py-2 rounded font-semibold transition">Skip Verification</a>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <main class="flex-1 flex items-center justify-center px-2 py-8">
        <section class="w-full flex items-center justify-center">
            <div class="bg-white bg-opacity-95 rounded-2xl shadow-2xl p-8 md:p-12 max-w-2xl w-full animate-fadeInUp">
                <div class="text-center mb-8">
                    <div class="mb-6">
                        <h3 class="text-bulsu-gold font-semibold text-lg mb-2">Bulacan State University</h3>
                        <div class="w-24 h-1 bg-bulsu-gold mx-auto rounded"></div>
                    </div>
                    <h1 class="text-bulsu-maroon text-2xl md:text-3xl font-bold mb-2">Create Your Student Account</h1>
                    <p class="text-gray-600">Join OnTheJob Tracker and start monitoring OJT performance with AI</p>
                </div>

                <?php if (!empty($errors)): ?>
                <div class="bg-red-50 border border-red-200 text-red-700 px-4 py-3 rounded mb-4 text-left text-sm">
                    <strong>Please fix the following errors:</strong>
                    <ul class="list-disc ml-6">
                        <?php foreach ($errors as $error): ?>
                            <li><?php echo htmlspecialchars($error); ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
                <?php endif; ?>

                <form method="POST" action="signup.php" id="signupForm" class="space-y-6">
                    <!-- Personal Information Section -->
                    <div>
                        <h3 class="text-bulsu-maroon font-semibold mb-4">Personal Information</h3>
                        <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-4">
                            <div>
                                <label for="first_name" class="block text-gray-700 font-medium mb-1">First Name <span class="text-red-500">*</span></label>
                                <input type="text" id="first_name" name="first_name" placeholder="Enter your first name"
                                    value="<?php echo htmlspecialchars($form_data['first_name']); ?>" required
                                    class="w-full px-4 py-2 border-2 border-gray-200 rounded-lg focus:outline-none focus:border-bulsu-gold transition">
                            </div>
                            <div>
                                <label for="middle_name" class="block text-gray-700 font-medium mb-1">Middle Name <span class="text-gray-400 text-xs">(Optional)</span></label>
                                <input type="text" id="middle_name" name="middle_name" placeholder="Enter your middle name"
                                    value="<?php echo htmlspecialchars($form_data['middle_name']); ?>"
                                    class="w-full px-4 py-2 border-2 border-gray-200 rounded-lg focus:outline-none focus:border-bulsu-gold transition">
                            </div>
                            <div>
                                <label for="last_name" class="block text-gray-700 font-medium mb-1">Last Name <span class="text-red-500">*</span></label>
                                <input type="text" id="last_name" name="last_name" placeholder="Enter your last name"
                                    value="<?php echo htmlspecialchars($form_data['last_name']); ?>" required
                                    class="w-full px-4 py-2 border-2 border-gray-200 rounded-lg focus:outline-none focus:border-bulsu-gold transition">
                            </div>
                        </div>
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
                            <div>
                                <label for="gender" class="block text-gray-700 font-medium mb-1">Gender <span class="text-red-500">*</span></label>
                                <select id="gender" name="gender" required
                                    class="w-full px-4 py-2 border-2 border-gray-200 rounded-lg focus:outline-none focus:border-bulsu-gold transition">
                                    <option value="" <?php echo ($form_data['gender'] == '') ? 'selected' : ''; ?>>Select gender</option>
                                    <option value="male" <?php echo ($form_data['gender'] == 'male') ? 'selected' : ''; ?>>Male</option>
                                    <option value="female" <?php echo ($form_data['gender'] == 'female') ? 'selected' : ''; ?>>Female</option>
                                </select>
                            </div>
                            <div>
                                <label for="dob" class="block text-gray-700 font-medium mb-1">Date of Birth <span class="text-red-500">*</span></label>
                                <input type="date" id="dob" name="dob"
                                    value="<?php echo htmlspecialchars($form_data['dob']); ?>" required
                                    class="w-full px-4 py-2 border-2 border-gray-200 rounded-lg focus:outline-none focus:border-bulsu-gold transition">
                            </div>
                        </div>
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
                            <div>
                                <label for="contact_number" class="block text-gray-700 font-medium mb-1">Contact Number <span class="text-red-500">*</span></label>
                                <input type="tel" id="contact_number" name="contact_number" placeholder="09XXXXXXXXX"
                                    value="<?php echo htmlspecialchars($form_data['contact_number']); ?>"
                                    pattern="09[0-9]{9}" maxlength="11" required
                                    class="w-full px-4 py-2 border-2 border-gray-200 rounded-lg focus:outline-none focus:border-bulsu-gold transition">
                                <small class="text-gray-400 text-xs">Must be exactly 11 digits starting with 09 (e.g., 09123456789)</small>
                            </div>
                            <div>
                                <label for="email" class="block text-gray-700 font-medium mb-1">Email Address <span class="text-red-500">*</span></label>
                                <input type="email" id="email" name="email" placeholder="Enter your email address"
                                    value="<?php echo htmlspecialchars($form_data['email']); ?>" required
                                    class="w-full px-4 py-2 border-2 border-gray-200 rounded-lg focus:outline-none focus:border-bulsu-gold transition">
                            </div>
                        </div>
                        <div>
                            <label for="address" class="block text-gray-700 font-medium mb-1">Address <span class="text-red-500">*</span></label>
                            <input type="text" id="address" name="address" placeholder="Enter your complete address"
                                value="<?php echo htmlspecialchars($form_data['address']); ?>" required
                                class="w-full px-4 py-2 border-2 border-gray-200 rounded-lg focus:outline-none focus:border-bulsu-gold transition">
                        </div>
                    </div>

                    <!-- Academic Information Section -->
                    <div>
                        <h3 class="text-bulsu-maroon font-semibold mb-4 mt-8">Academic Information</h3>
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
                            <div>
                                <label for="student_id" class="block text-gray-700 font-medium mb-1">Student ID <span class="text-red-500">*</span></label>
                                <input type="text" id="student_id" name="student_id" placeholder="Enter your student ID"
                                    value="<?php echo htmlspecialchars($form_data['student_id']); ?>" required
                                    class="w-full px-4 py-2 border-2 border-gray-200 rounded-lg focus:outline-none focus:border-bulsu-gold transition">
                            </div>
                            <div>
                                <label for="year_level" class="block text-gray-700 font-medium mb-1">Year Level <span class="text-red-500">*</span></label>
                                <select id="year_level" name="year_level" required
                                    class="w-full px-4 py-2 border-2 border-gray-200 rounded-lg focus:outline-none focus:border-bulsu-gold transition">
                                    <option value="" <?php echo ($form_data['year_level'] == '') ? 'selected' : ''; ?>>Select year level</option>
                                    <option value="1st Year" <?php echo ($form_data['year_level'] == '1st Year') ? 'selected' : ''; ?>>1st Year</option>
                                    <option value="2nd Year" <?php echo ($form_data['year_level'] == '2nd Year') ? 'selected' : ''; ?>>2nd Year</option>
                                    <option value="3rd Year" <?php echo ($form_data['year_level'] == '3rd Year') ? 'selected' : ''; ?>>3rd Year</option>
                                    <option value="4th Year" <?php echo ($form_data['year_level'] == '4th Year') ? 'selected' : ''; ?>>4th Year</option>
                                </select>
                            </div>
                        </div>
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
                            <div>
                                <label for="department" class="block text-gray-700 font-medium mb-1">Department <span class="text-red-500">*</span></label>
                                <input type="text" id="department" name="department" placeholder="Enter your department"
                                    value="<?php echo htmlspecialchars($form_data['department']); ?>" required
                                    class="w-full px-4 py-2 border-2 border-gray-200 rounded-lg focus:outline-none focus:border-bulsu-gold transition">
                            </div>
                            <div>
                                <label for="program" class="block text-gray-700 font-medium mb-1">Program <span class="text-red-500">*</span></label>
                                <input type="text" id="program" name="program" placeholder="Enter your program"
                                    value="<?php echo htmlspecialchars($form_data['program']); ?>" required
                                    class="w-full px-4 py-2 border-2 border-gray-200 rounded-lg focus:outline-none focus:border-bulsu-gold transition">
                            </div>
                        </div>
                        <div>
                            <label for="section" class="block text-gray-700 font-medium mb-1">Section <span class="text-red-500">*</span></label>
                            <input type="text" id="section" name="section" placeholder="Enter your section"
                                value="<?php echo htmlspecialchars($form_data['section']); ?>" required
                                class="w-full px-4 py-2 border-2 border-gray-200 rounded-lg focus:outline-none focus:border-bulsu-gold transition">
                        </div>
                    </div>

                    <!-- Account Security Section -->
                    <div>
                        <h3 class="text-bulsu-maroon font-semibold mb-4 mt-8">Account Security</h3>
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
                            <div>
                                <label for="password" class="block text-gray-700 font-medium mb-1">Password <span class="text-red-500">*</span></label>
                                <input type="password" id="password" name="password" placeholder="Enter your password"
                                    minlength="8" required
                                    class="w-full px-4 py-2 border-2 border-gray-200 rounded-lg focus:outline-none focus:border-bulsu-gold transition">
                                <small class="text-gray-400 text-xs">Password must be at least 8 characters long</small>
                            </div>
                            <div>
                                <label for="confirm_password" class="block text-gray-700 font-medium mb-1">Confirm Password <span class="text-red-500">*</span></label>
                                <input type="password" id="confirm_password" name="confirm_password" placeholder="Confirm your password"
                                    minlength="8" required
                                    class="w-full px-4 py-2 border-2 border-gray-200 rounded-lg focus:outline-none focus:border-bulsu-gold transition">
                            </div>
                        </div>
                    </div>

                    <!-- Terms and Conditions -->
                    <div class="flex items-center mb-4">
                        <input type="checkbox" id="agree_terms" name="agree_terms" required class="mr-2">
                        <label for="agree_terms" class="text-gray-700">I agree to the <a href="#" class="text-bulsu-gold hover:underline">Terms and Conditions</a> and <a href="#" class="text-bulsu-gold hover:underline">Privacy Policy</a> <span class="text-red-500">*</span></label>
                    </div>

                    <button type="submit"
                        class="w-full bg-gradient-to-r from-bulsu-maroon to-bulsu-dark-maroon text-white rounded-lg py-3 font-semibold shadow-lg hover:from-bulsu-dark-maroon hover:to-black transition transform hover:scale-105">
                        Create Account
                    </button>
                </form>

                <div class="text-center mt-6 text-gray-600">
                    Already have an account?
                    <a href="login.php" class="text-bulsu-gold font-semibold hover:text-bulsu-dark-maroon transition">Sign In</a>
                    <p class="mt-2 text-xs text-gray-400">
                        <strong>Note:</strong> Profile picture can be uploaded after creating your account
                    </p>
                </div>

                <!-- Back to Landing Page -->
                <div class="mt-6 text-center">
                    <a href="index.php" class="inline-block bg-gradient-to-r from-gray-500 to-gray-700 text-white px-6 py-2 rounded-lg font-semibold shadow-lg hover:from-gray-600 hover:to-gray-800 transition transform hover:scale-105">
                        ← Back to Landing Page
                    </a>
                </div>
            </div>
        </section>
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

        // Enhanced phone number validation
        document.getElementById('contact_number').addEventListener('input', function(e) {
            let value = e.target.value.replace(/[^0-9]/g, '');
            if (value.length > 11) value = value.substring(0, 11);
            e.target.value = value;
        });

        // Prevent non-numeric input on keypress
        document.getElementById('contact_number').addEventListener('keypress', function(e) {
            if ((e.shiftKey || (e.keyCode < 48 || e.keyCode > 57)) && (e.keyCode < 96 || e.keyCode > 105)) {
                e.preventDefault();
            }
            if (this.value.length >= 11) e.preventDefault();
        });

        // Form validation
        document.getElementById('signupForm').addEventListener('submit', function(event) {
            const password = document.getElementById('password').value;
            const confirmPassword = document.getElementById('confirm_password').value;
            const agreeTerms = document.getElementById('agree_terms').checked;
            const contactNumber = document.getElementById('contact_number').value;
            
            if (!agreeTerms) {
                alert('You must agree to the Terms and Conditions!');
                event.preventDefault();
                return false;
            }
            
            if (contactNumber.length !== 11 || !contactNumber.startsWith('09')) {
                alert('Contact number must be exactly 11 digits and start with 09!');
                document.getElementById('contact_number').focus();
                event.preventDefault();
                return false;
            }
            
            if (password !== confirmPassword) {
                alert('Passwords do not match!');
                event.preventDefault();
                return false;
            }
            
            if (password.length < 8) {
                alert('Password must be at least 8 characters long!');
                event.preventDefault();
                return false;
            }
        });
    </script>
</body>
</html>