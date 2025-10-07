<?php
include('connect.php');
session_start();

require './PHPMailer/PHPMailer/src/Exception.php';
require './PHPMailer/PHPMailer/src/PHPMailer.php';
require './PHPMailer/PHPMailer/src/SMTP.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

// Fetch available departments and sections from academic_adviser table
$departments_query = "SELECT DISTINCT department FROM academic_adviser WHERE department IS NOT NULL AND approval_status = 'approved' ORDER BY department";
$departments_result = mysqli_query($conn, $departments_query);
$departments = [];
while ($row = mysqli_fetch_assoc($departments_result)) {
    if (!empty($row['department'])) {
        $departments[] = $row['department'];
    }
}

// Fetch sections with their assigned groups
$sections_query = "SELECT DISTINCT section, assigned_groups FROM academic_adviser 
                   WHERE section IS NOT NULL 
                   AND assigned_groups IS NOT NULL 
                   AND approval_status = 'approved' 
                   ORDER BY section";
$sections_result = mysqli_query($conn, $sections_query);
$section_groups = [];

while ($row = mysqli_fetch_assoc($sections_result)) {
    $section = trim($row['section']);
    $groups = trim($row['assigned_groups']);
    
    if (!empty($section) && !empty($groups)) {
        $group_array = array_map('trim', explode(',', $groups));
        
        foreach ($group_array as $group) {
            if (!in_array($group, $section_groups)) {
                $section_groups[] = $group;
            }
        }
    }
}

natsort($section_groups);

// Fetch approved academic advisers with their details - UPDATED WITH assigned_groups
$advisers_query = "SELECT id, name, email, department, year_level, section, assigned_groups 
                   FROM academic_adviser 
                   WHERE approval_status = 'approved' 
                   ORDER BY name";
$advisers_result = mysqli_query($conn, $advisers_query);
$advisers = [];
while ($row = mysqli_fetch_assoc($advisers_result)) {
    $advisers[] = $row;
}

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
    'company_name' => '',
    'company_email' => '',
    'academic_adviser_id' => '',
    'academic_adviser_email' => '',
    'password' => '',
    'confirm_password' => ''
];

$errors = [];
$registration_success = false;
$email_sent = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
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
    $form_data['company_name'] = trim($_POST['company_name'] ?? '');
    $form_data['company_email'] = trim($_POST['company_email'] ?? '');
    $form_data['academic_adviser_id'] = trim($_POST['academic_adviser_id'] ?? '');
    $form_data['academic_adviser_email'] = trim($_POST['academic_adviser_email'] ?? '');
    $form_data['password'] = $_POST['password'] ?? '';
    $form_data['confirm_password'] = $_POST['confirm_password'] ?? '';
    
    error_log("Received data: " . json_encode($form_data));
    
    // Check required fields
    $required_fields = ['first_name', 'last_name', 'gender', 'dob', 'student_id', 'contact_number', 'email', 'address', 'year_level', 'department', 'program', 'section', 'academic_adviser_id', 'password'];
    
    foreach ($required_fields as $field) {
        if (empty($form_data[$field])) {
            $errors[] = ucwords(str_replace('_', ' ', $field)) . " is required.";
        }
    }
    
    if (!empty($form_data['company_email']) && !filter_var($form_data['company_email'], FILTER_VALIDATE_EMAIL)) {
        $errors[] = "Please enter a valid company email address.";
    }

    // Validate Student ID - must be exactly 10 digits
    if (!empty($form_data['student_id'])) {
        $clean_student_id = preg_replace('/[^0-9]/', '', $form_data['student_id']);
        
        if (strlen($clean_student_id) !== 10) {
            $errors[] = "Student ID must be exactly 10 digits.";
        } else {
            $form_data['student_id'] = $clean_student_id;
        }
    }
    
    // Validate date format (MM/DD/YYYY)
    if (!empty($form_data['dob'])) {
        $date_parts = explode('/', $form_data['dob']);
        if (count($date_parts) === 3) {
            list($month, $day, $year) = $date_parts;
            if (!checkdate($month, $day, $year)) {
                $errors[] = "Invalid date of birth. Please use MM/DD/YYYY format.";
            }
        } else {
            $errors[] = "Date of birth must be in MM/DD/YYYY format.";
        }
    }
    
    // Check if passwords match
    if ($form_data['password'] !== $form_data['confirm_password']) {
        $errors[] = "Passwords do not match.";
    }
    
    // Check password strength
    if (strlen($form_data['password']) < 8) {
        $errors[] = "Password must be at least 8 characters long.";
    }
    
    if (!preg_match('/[a-zA-Z]/', $form_data['password'])) {
        $errors[] = "Password must contain at least one letter.";
    }
    
    if (!preg_match('/[0-9]/', $form_data['password'])) {
        $errors[] = "Password must contain at least one number.";
    }
    
    if (!preg_match('/[!@#$%^&*(),.?":{}|<>]/', $form_data['password'])) {
        $errors[] = "Password must contain at least one special character (!@#$%^&*(),.?\":{}|<>).";
    }
    
    // Validate email format
    if (!filter_var($form_data['email'], FILTER_VALIDATE_EMAIL)) {
        $errors[] = "Please enter a valid email address.";
    }
    
    // Validate contact number - must be exactly 11 digits
    if (!empty($form_data['contact_number'])) {
        $clean_number = preg_replace('/[^0-9]/', '', $form_data['contact_number']);
        
        if (strlen($clean_number) !== 11) {
            $errors[] = "Contact number must be exactly 11 digits.";
        } elseif (!preg_match('/^09\d{9}$/', $clean_number)) {
            $errors[] = "Contact number must start with 09 and be exactly 11 digits.";
        } else {
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
    
    // VALIDATION: Check if academic adviser matches student's department, year, and section
    if (empty($errors) && !empty($form_data['academic_adviser_id'])) {
        $adviser_check_query = "SELECT id, name, department, year_level, section, assigned_groups 
                                FROM academic_adviser 
                                WHERE id = ? AND approval_status = 'approved'";
        $adviser_check_stmt = mysqli_prepare($conn, $adviser_check_query);
        
        if ($adviser_check_stmt) {
            mysqli_stmt_bind_param($adviser_check_stmt, "i", $form_data['academic_adviser_id']);
            mysqli_stmt_execute($adviser_check_stmt);
            $adviser_result = mysqli_stmt_get_result($adviser_check_stmt);
            
            if ($adviser = mysqli_fetch_assoc($adviser_result)) {
                $mismatch_errors = [];
                
                // Check if department matches
                if (!empty($adviser['department']) && !empty($form_data['department'])) {
                    if ($adviser['department'] !== $form_data['department']) {
                        $mismatch_errors[] = "Department mismatch: You selected '{$form_data['department']}' but your adviser '{$adviser['name']}' handles '{$adviser['department']}'";
                    }
                }
                
                // Check if year level matches
                // Check if year level matches
if (!empty($adviser['year_level']) && !empty($form_data['year_level'])) {
    // Extract numeric part from student's year level
    preg_match('/(\d+)/', $form_data['year_level'], $student_matches);
    $student_year_numeric = $student_matches[1] ?? '';
    
    // Extract numeric part from adviser's year level (in case it's also in text format)
    preg_match('/(\d+)/', $adviser['year_level'], $adviser_matches);
    $adviser_year_numeric = $adviser_matches[1] ?? $adviser['year_level'];
    
    // Compare numeric values
    if ($student_year_numeric !== $adviser_year_numeric) {
        $mismatch_errors[] = "Year level mismatch: You selected '{$form_data['year_level']}' but your adviser '{$adviser['name']}' handles year {$adviser['year_level']}";
    }
}
                
                // Check if section matches (considering assigned groups)
                if (!empty($adviser['assigned_groups']) && !empty($form_data['section'])) {
                    $assigned_groups = array_map('trim', explode(',', $adviser['assigned_groups']));
                    $student_section = trim($form_data['section']);
                    
                    // Check if student's section is in adviser's assigned groups
                    if (!in_array($student_section, $assigned_groups)) {
                        // Find the correct adviser for this section
                        $correct_adviser_query = "SELECT name, assigned_groups 
                                                 FROM academic_adviser 
                                                 WHERE approval_status = 'approved' 
                                                 AND FIND_IN_SET(?, assigned_groups) > 0
                                                 LIMIT 1";
                        $correct_stmt = mysqli_prepare($conn, $correct_adviser_query);
                        mysqli_stmt_bind_param($correct_stmt, "s", $student_section);
                        mysqli_stmt_execute($correct_stmt);
                        $correct_result = mysqli_stmt_get_result($correct_stmt);
                        
                        if ($correct_adviser = mysqli_fetch_assoc($correct_result)) {
                            $mismatch_errors[] = "Section mismatch: Your section '{$student_section}' is handled by '{$correct_adviser['name']}', not '{$adviser['name']}'";
                        } else {
                            $mismatch_errors[] = "Section mismatch: No adviser found for section '{$student_section}'. Please contact the coordinator.";
                        }
                        mysqli_stmt_close($correct_stmt);
                    }
                }
                
                // Add all mismatch errors to the main errors array
                if (!empty($mismatch_errors)) {
                    $errors = array_merge($errors, $mismatch_errors);
                }
            } else {
                $errors[] = "Selected academic adviser not found or not approved.";
            }
            mysqli_stmt_close($adviser_check_stmt);
        }
    }
    
    // If there are no errors, proceed with registration
    if (empty($errors)) {
        // Convert date format from MM/DD/YYYY to YYYY-MM-DD for database
        $date_parts = explode('/', $form_data['dob']);
        $db_date = $date_parts[2] . '-' . $date_parts[0] . '-' . $date_parts[1];
        
        // Hash password
        $hashed_password = password_hash($form_data['password'], PASSWORD_BCRYPT);
        
        // Generate OTP for email verification
        $otp = rand(100000, 999999);
        
        // Insert student data into database
        $insert_query = "INSERT INTO students (
            first_name, middle_name, last_name, gender, date_of_birth, student_id, 
            contact_number, email, address, year_level, department, program, section, 
            company_name, company_email, academic_adviser_id, academic_adviser_email,
            password, verification_code, verified, status, 
            login_attempts, ready_for_deployment
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, 'Active', 0, 0)";

        $stmt = mysqli_prepare($conn, $insert_query);
       
        if (!$stmt) {
            $errors[] = 'Database preparation failed: ' . mysqli_error($conn);
            error_log('Statement preparation failed: ' . mysqli_error($conn));
        } else {
            mysqli_stmt_bind_param(
                $stmt,
                "ssssssssssssssssssi",
                $form_data['first_name'], 
                $form_data['middle_name'], 
                $form_data['last_name'], 
                $form_data['gender'], 
                $db_date, 
                $form_data['student_id'],
                $form_data['contact_number'], 
                $form_data['email'], 
                $form_data['address'], 
                $form_data['year_level'], 
                $form_data['department'], 
                $form_data['program'], 
                $form_data['section'],
                $form_data['company_name'],
                $form_data['company_email'],
                $form_data['academic_adviser_id'],
                $form_data['academic_adviser_email'],
                $hashed_password, 
                $otp
            );
            
            if (mysqli_stmt_execute($stmt)) {
                $registration_success = true;
                
                // Store email and OTP in session for verification
                $_SESSION['email'] = $form_data['email'];
                $_SESSION['otp'] = $otp;
                $_SESSION['otp_expiry'] = time() + 300;
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
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
    tailwind.config = {
        theme: {
            extend: {
                colors: {
                    'bulsu-maroon': '#800000',
                    'bulsu-dark-maroon': '#6B1028',
                    'bulsu-gold': '#DAA520',
                    'bulsu-light-gold': '#F4E4BC',
                    'bulsu-white': '#FFFFFF'
                }
            }
        }
    }
    
    // Store advisers data in JavaScript
    const advisersData = <?php echo json_encode($advisers); ?>;
    </script>
</head>
<body class="bg-gray-100 text-gray-800 min-h-screen flex flex-col">
    <header class="bg-gradient-to-r from-bulsu-maroon to-bulsu-dark-maroon text-white shadow-lg">
        <nav class="max-w-6xl mx-auto flex items-center justify-between px-4 py-4 relative">
            <div class="flex items-center">
                <a href="index.php" class="cursor-pointer hover:opacity-80 transition-opacity">
                    <img src="reqsample/bulsu12.png" alt="BULSU Logo 2" class="w-20 h-20">
                </a>
                <div class="flex items-center font-bold text-xl">
                    <span>OnTheJob</span>
                    <span class="ml-2">Tracker</span>
                </div>
            </div>
            <button id="menu-btn" class="md:hidden block focus:outline-none z-50">
                <svg class="w-7 h-7" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M4 6h16M4 12h16M4 18h16" />
                </svg>
            </button>
            <ul id="nav-links" class="hidden md:flex space-x-8 font-medium">
                <li><a href="index.php#features" class="hover:text-bulsu-gold transition">Features</a></li>  
                <li><a href="index.php#stakeholders" class="hover:text-bulsu-gold transition">Stakeholders</a></li>
                <li><a href="index.php#contact" class="hover:text-bulsu-gold transition">Contact</a></li>
            </ul>
            <div class="hidden md:flex space-x-4">
                <a href="login.php" class="bg-bulsu-gold bg-opacity-20 border border-bulsu-gold border-opacity-50 rounded px-4 py-2 font-medium hover:bg-opacity-30 transition text-bulsu-gold">Login</a>
                <a href="signuplanding.php" class="bg-bulsu-gold text-bulsu-maroon rounded px-4 py-2 font-medium hover:bg-yellow-400 transition">Sign Up</a>
            </div>
            <div id="mobile-menu" class="md:hidden hidden absolute top-full left-0 w-full bg-bulsu-maroon z-50 px-4 pb-4 shadow-lg">
                <ul class="flex flex-col space-y-2 font-medium pt-4">
                    <li><a href="index.php#features" class="hover:text-bulsu-gold block py-2 text-white transition">Features</a></li>
                    <li><a href="index.php#stakeholders" class="hover:text-bulsu-gold block py-2 text-white transition">Stakeholders</a></li>
                    <li><a href="index.php#contact" class="hover:text-bulsu-gold block py-2 text-white transition">Contact</a></li>
                </ul>
                <div class="flex flex-col space-y-2 mt-4">
                    <a href="login.php" class="bg-bulsu-gold bg-opacity-20 border border-bulsu-gold border-opacity-50 rounded px-4 py-2 font-medium text-bulsu-gold hover:bg-opacity-30 transition">Login</a>
                    <a href="signuplanding.php" class="bg-bulsu-gold text-bulsu-maroon rounded px-4 py-2 font-medium hover:bg-yellow-400 transition text-center">Sign Up</a>
                </div>
            </div>
        </nav>
    </header>

    <!-- Terms and Conditions Modal -->
    <div id="termsModal" class="hidden fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50 p-4">
        <div class="bg-white rounded-xl shadow-2xl max-w-3xl w-full max-h-[90vh] overflow-y-auto">
            <div class="sticky top-0 bg-bulsu-maroon text-white px-6 py-4 flex justify-between items-center">
                <h2 class="text-2xl font-bold">Terms and Conditions</h2>
                <button onclick="closeModal('termsModal')" class="text-white hover:text-bulsu-gold text-3xl">&times;</button>
            </div>
            <div class="p-6 text-gray-700 space-y-4">
                <p class="text-sm text-gray-500">Last Updated: January 2025</p>
                
                <h3 class="text-lg font-bold text-bulsu-maroon">1. Acceptance of Terms</h3>
                <p>By creating an account on the OnTheJob Tracker system, you agree to comply with these Terms and Conditions. If you do not agree, please discontinue use of the platform immediately.</p>
                
                <h3 class="text-lg font-bold text-bulsu-maroon">2. User Registration</h3>
                <p>You must provide accurate, complete, and current information during registration. You are responsible for maintaining the confidentiality of your account credentials and for all activities under your account.</p>
                
                <h3 class="text-lg font-bold text-bulsu-maroon">3. Use of Platform</h3>
                <p>The OnTheJob Tracker is designed exclusively for monitoring On-the-Job Training (OJT) performance of Bulacan State University students. Users agree to:</p>
                <ul class="list-disc ml-6 space-y-1">
                    <li>Use the platform solely for educational and monitoring purposes</li>
                    <li>Submit accurate information regarding OJT activities and progress</li>
                    <li>Respect the intellectual property rights of the platform</li>
                    <li>Not attempt to disrupt, hack, or compromise system security</li>
                </ul>
                
                <h3 class="text-lg font-bold text-bulsu-maroon">4. Data Collection and AI Monitoring</h3>
                <p>The platform utilizes AI technology to monitor and analyze OJT performance. By using this system, you consent to:</p>
                <ul class="list-disc ml-6 space-y-1">
                    <li>Collection of academic and performance data</li>
                    <li>AI-powered analysis of submitted reports and activities</li>
                    <li>Sharing of performance metrics with academic advisers and coordinators</li>
                </ul>
                
                <h3 class="text-lg font-bold text-bulsu-maroon">5. User Responsibilities</h3>
                <p>Students must:</p>
                <ul class="list-disc ml-6 space-y-1">
                    <li>Regularly log activities and submit required reports</li>
                    <li>Respond to notifications and feedback from advisers</li>
                    <li>Maintain professional conduct in all communications</li>
                    <li>Report any technical issues or concerns promptly</li>
                </ul>
                
                <h3 class="text-lg font-bold text-bulsu-maroon">6. Account Suspension</h3>
                <p>BULSU reserves the right to suspend or terminate accounts that violate these terms, including but not limited to: fraudulent information, system abuse, academic dishonesty, or misuse of platform features.</p>
                
                <h3 class="text-lg font-bold text-bulsu-maroon">7. Intellectual Property</h3>
                <p>All content, features, and functionality of OnTheJob Tracker are owned by Bulacan State University and protected by copyright, trademark, and other intellectual property laws.</p>
                
                <h3 class="text-lg font-bold text-bulsu-maroon">8. Limitation of Liability</h3>
                <p>BULSU is not liable for any direct, indirect, incidental, or consequential damages arising from use of the platform, including but not limited to system downtime, data loss, or technical errors.</p>
                
                <h3 class="text-lg font-bold text-bulsu-maroon">9. Modifications</h3>
                <p>BULSU reserves the right to modify these Terms and Conditions at any time. Users will be notified of significant changes, and continued use constitutes acceptance of modified terms.</p>
                
                <h3 class="text-lg font-bold text-bulsu-maroon">10. Contact Information</h3>
                <p>For questions regarding these terms, contact the OJT Coordinator at Bulacan State University or email ojttracker2@gmail.com.</p>
            </div>
            <div class="sticky bottom-0 bg-gray-100 px-6 py-4 flex justify-end">
                <button onclick="closeModal('termsModal')" class="bg-bulsu-maroon hover:bg-bulsu-dark-maroon text-white px-6 py-2 rounded-lg font-semibold transition">Close</button>
            </div>
        </div>
    </div>

    <!-- Privacy Policy Modal -->
    <div id="privacyModal" class="hidden fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50 p-4">
        <div class="bg-white rounded-xl shadow-2xl max-w-3xl w-full max-h-[90vh] overflow-y-auto">
            <div class="sticky top-0 bg-bulsu-maroon text-white px-6 py-4 flex justify-between items-center">
                <h2 class="text-2xl font-bold">Privacy Policy</h2>
                <button onclick="closeModal('privacyModal')" class="text-white hover:text-bulsu-gold text-3xl">&times;</button>
            </div>
            <div class="p-6 text-gray-700 space-y-4">
                <p class="text-sm text-gray-500">Last Updated: January 2025</p>
                
                <h3 class="text-lg font-bold text-bulsu-maroon">1. Introduction</h3>
                <p>Bulacan State University ("BULSU," "we," "us," or "our") respects your privacy and is committed to protecting your personal data. This Privacy Policy explains how we collect, use, disclose, and safeguard information collected through the OnTheJob Tracker platform.</p>
                
                <h3 class="text-lg font-bold text-bulsu-maroon">2. Information We Collect</h3>
                <p>We collect the following types of information:</p>
                <ul class="list-disc ml-6 space-y-1">
                    <li><strong>Personal Information:</strong> Name, student ID, email address, contact number, date of birth, address</li>
                    <li><strong>Academic Information:</strong> Department, program, year level, section, OJT activities</li>
                    <li><strong>Performance Data:</strong> Reports, evaluations, attendance records, skill assessments</li>
                    <li><strong>Technical Data:</strong> IP address, browser type, device information, login timestamps</li>
                </ul>
                
                <h3 class="text-lg font-bold text-bulsu-maroon">3. How We Use Your Information</h3>
                <p>Your information is used to:</p>
                <ul class="list-disc ml-6 space-y-1">
                    <li>Monitor and evaluate OJT performance</li>
                    <li>Facilitate communication between students, advisers, and coordinators</li>
                    <li>Generate performance reports and analytics</li>
                    <li>Improve platform functionality and user experience</li>
                    <li>Comply with university policies and legal requirements</li>
                    <li>Provide AI-powered insights and recommendations</li>
                </ul>
                
                <h3 class="text-lg font-bold text-bulsu-maroon">4. Contact Us</h3>
                <p>For privacy concerns or data protection inquiries, contact:</p>
                <ul class="list-none ml-6 space-y-1">
                    <li><strong>Email:</strong> ojttracker2@gmail.com</li>
                    <li><strong>Office:</strong> OJT Coordinator, Bulacan State University</li>
                </ul>
            </div>
            <div class="sticky bottom-0 bg-gray-100 px-6 py-4 flex justify-end">
                <button onclick="closeModal('privacyModal')" class="bg-bulsu-maroon hover:bg-bulsu-dark-maroon text-white px-6 py-2 rounded-lg font-semibold transition">Close</button>
            </div>
        </div>
    </div>

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
                                <input type="text" id="dob" name="dob" placeholder="MM/DD/YYYY"
                                    value="<?php echo htmlspecialchars($form_data['dob']); ?>" required maxlength="10"
                                    class="w-full px-4 py-2 border-2 border-gray-200 rounded-lg focus:outline-none focus:border-bulsu-gold transition">
                                <small class="text-gray-400 text-xs">Format: MM/DD/YYYY (e.g., 07/06/2004)</small>
                            </div>
                        </div>
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
                            <div>
                                <label for="contact_number" class="block text-gray-700 font-medium mb-1">Contact Number <span class="text-red-500">*</span></label>
                                <input type="tel" id="contact_number" name="contact_number" placeholder="09XXXXXXXXX"
                                    value="<?php echo htmlspecialchars($form_data['contact_number']); ?>"
                                    pattern="09[0-9]{9}" maxlength="11" required
                                    class="w-full px-4 py-2 border-2 border-gray-200 rounded-lg focus:outline-none focus:border-bulsu-gold transition">
                                <small class="text-gray-400 text-xs">Must be exactly 11 digits starting with 09</small>
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
                                <input type="text" id="student_id" name="student_id" placeholder="Enter 10-digit Student ID"
                                    value="<?php echo htmlspecialchars($form_data['student_id']); ?>" required maxlength="10"
                                    class="w-full px-4 py-2 border-2 border-gray-200 rounded-lg focus:outline-none focus:border-bulsu-gold transition">
                                <small class="text-gray-400 text-xs">Must be exactly 10 digits (e.g., 2022200291)</small>
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
                                <select id="department" name="department" required
                                    class="w-full px-4 py-2 border-2 border-gray-200 rounded-lg focus:outline-none focus:border-bulsu-gold transition">
                                    <option value="">Select department</option>
                                    <?php foreach ($departments as $dept): ?>
                                        <option value="<?php echo htmlspecialchars($dept); ?>" 
                                            <?php echo ($form_data['department'] == $dept) ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($dept); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
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
                            <select id="section" name="section" required
                                class="w-full px-4 py-2 border-2 border-gray-200 rounded-lg focus:outline-none focus:border-bulsu-gold transition">
                                <option value="">Select section</option>
                                <?php 
                                $grouped = [];
                                foreach ($section_groups as $group) {
                                    if (preg_match('/^(\d+[A-Z])-G\d+$/', $group, $matches)) {
                                        $base = $matches[1];
                                        if (!isset($grouped[$base])) {
                                            $grouped[$base] = [];
                                        }
                                        $grouped[$base][] = $group;
                                    } else {
                                        $grouped[$group] = [$group];
                                    }
                                }
                                
                                foreach ($grouped as $base => $groups) {
                                    if (count($groups) > 1) {
                                        echo '<optgroup label="Section ' . htmlspecialchars($base) . '">';
                                        foreach ($groups as $group) {
                                            $selected = ($form_data['section'] == $group) ? 'selected' : '';
                                            $display = preg_match('/-G(\d+)$/', $group, $m) ? "Group " . $m[1] : $group;
                                            echo '<option value="' . htmlspecialchars($group) . '" ' . $selected . '>' . 
                                                 htmlspecialchars($group) . ' (' . $display . ')</option>';
                                        }
                                        echo '</optgroup>';
                                    } else {
                                        $selected = ($form_data['section'] == $groups[0]) ? 'selected' : '';
                                        echo '<option value="' . htmlspecialchars($groups[0]) . '" ' . $selected . '>' . 
                                             htmlspecialchars($groups[0]) . '</option>';
                                    }
                                }
                                ?>
                            </select>
                            <small class="text-gray-400 text-xs mt-1 block">Select your assigned section and group</small>
                        </div>
                    </div>

                    <!-- Academic Adviser Information Section -->
                    <div class="mt-6">
                        <h3 class="text-bulsu-maroon font-semibold mb-4">Academic Adviser Information <span class="text-red-500">*</span></h3>
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <div>
                                <label for="academic_adviser_id" class="block text-gray-700 font-medium mb-1">Academic Adviser Name <span class="text-red-500">*</span></label>
                                <select id="academic_adviser_id" name="academic_adviser_id" required
                                    class="w-full px-4 py-2 border-2 border-gray-200 rounded-lg focus:outline-none focus:border-bulsu-gold transition">
                                    <option value="">Select your academic adviser</option>
                                    <?php foreach ($advisers as $adviser): ?>
                                        <option value="<?php echo htmlspecialchars($adviser['id']); ?>" 
                                            data-email="<?php echo htmlspecialchars($adviser['email']); ?>"
                                            data-department="<?php echo htmlspecialchars($adviser['department'] ?? ''); ?>"
                                            data-year="<?php echo htmlspecialchars($adviser['year_level'] ?? ''); ?>"
                                            data-section="<?php echo htmlspecialchars($adviser['section'] ?? ''); ?>"
                                            <?php echo ($form_data['academic_adviser_id'] == $adviser['id']) ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($adviser['name']); ?>
                                            <?php 
                                            $details = [];
                                            if (!empty($adviser['department'])) $details[] = $adviser['department'];
                                            if (!empty($adviser['section'])) $details[] = $adviser['section'];
                                            if (!empty($details)) echo ' - ' . implode(', ', $details);
                                            ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <small class="text-gray-400 text-xs">Select your assigned academic adviser</small>
                            </div>
                            <div>
                                <label for="academic_adviser_email" class="block text-gray-700 font-medium mb-1">Academic Adviser Email</label>
                                <input type="email" id="academic_adviser_email" name="academic_adviser_email" 
                                    placeholder="Auto-filled when adviser selected"
                                    value="<?php echo htmlspecialchars($form_data['academic_adviser_email']); ?>"
                                    readonly
                                    class="w-full px-4 py-2 border-2 border-gray-200 rounded-lg bg-gray-50 text-gray-600 cursor-not-allowed">
                                <small class="text-gray-400 text-xs">Automatically filled based on adviser selection</small>
                            </div>
                        </div>
                    </div>

                    <!-- Company Information Section -->
                    <div class="mt-6">
                        <h3 class="text-bulsu-maroon font-semibold mb-4">Company Information <span class="text-gray-400 text-sm font-normal">(Required)</span></h3>
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <div>
                                <label for="company_name" class="block text-gray-700 font-medium mb-1">Company Name</label>
                                <input type="text" id="company_name" name="company_name" 
                                    placeholder="Enter company name (if already assigned)"
                                    value="<?php echo htmlspecialchars($form_data['company_name']); ?>"
                                    class="w-full px-4 py-2 border-2 border-gray-200 rounded-lg focus:outline-none focus:border-bulsu-gold transition">
                                <small class="text-gray-400 text-xs">You can update this later if not assigned yet</small>
                            </div>
                            <div>
                                <label for="company_email" class="block text-gray-700 font-medium mb-1">Supervisor Email</label>
                                <input type="email" id="company_email" name="company_email" 
                                    placeholder="company@example.com"
                                    value="<?php echo htmlspecialchars($form_data['company_email']); ?>"
                                    class="w-full px-4 py-2 border-2 border-gray-200 rounded-lg focus:outline-none focus:border-bulsu-gold transition">
                                <small class="text-gray-400 text-xs">Contact email for your OJT company</small>
                            </div>
                        </div>
                    </div>

                    <!-- Account Security Section -->
                    <div>
                        <h3 class="text-bulsu-maroon font-semibold mb-4 mt-8">Account Security</h3>
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
                            <div>
                                <label for="password" class="block text-gray-700 font-medium mb-1">Password <span class="text-red-500">*</span></label>
                                <div class="relative">
                                    <input type="password" id="password" name="password" placeholder="Enter your password"
                                        minlength="8" required
                                        class="w-full px-4 py-2 pr-10 border-2 border-gray-200 rounded-lg focus:outline-none focus:border-bulsu-gold transition">
                                    <button type="button" onclick="togglePassword('password', 'eyeIcon1')" class="absolute right-3 top-1/2 transform -translate-y-1/2 text-gray-500 hover:text-gray-700 focus:outline-none">
                                        <svg id="eyeIcon1" xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z" />
                                        </svg>
                                    </button>
                                </div>
                                <div id="password-requirements" class="mt-2 text-xs space-y-1">
                                    <div id="req-length" class="flex items-center text-gray-400">
                                        <span class="mr-2">✗</span>
                                        <span>At least 8 characters</span>
                                    </div>
                                    <div id="req-letter" class="flex items-center text-gray-400">
                                        <span class="mr-2">✗</span>
                                        <span>Contains a letter</span>
                                    </div>
                                    <div id="req-number" class="flex items-center text-gray-400">
                                        <span class="mr-2">✗</span>
                                        <span>Contains a number</span>
                                    </div>
                                    <div id="req-special" class="flex items-center text-gray-400">
                                        <span class="mr-2">✗</span>
                                        <span>Contains a special character (!@#$%^&*)</span>
                                    </div>
                                </div>
                            </div>
                            <div>
                                <label for="confirm_password" class="block text-gray-700 font-medium mb-1">Confirm Password <span class="text-red-500">*</span></label>
                                <div class="relative">
                                    <input type="password" id="confirm_password" name="confirm_password" placeholder="Confirm your password"
                                        minlength="8" required
                                        class="w-full px-4 py-2 pr-10 border-2 border-gray-200 rounded-lg focus:outline-none focus:border-bulsu-gold transition">
                                    <button type="button" onclick="togglePassword('confirm_password', 'eyeIcon2')" class="absolute right-3 top-1/2 transform -translate-y-1/2 text-gray-500 hover:text-gray-700 focus:outline-none">
                                        <svg id="eyeIcon2" xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z" />
                                        </svg>
                                    </button>
                                </div>
                                <div id="password-match" class="mt-2 text-xs hidden">
                                    <div class="flex items-center">
                                        <span id="match-icon" class="mr-2">✗</span>
                                        <span id="match-text">Passwords match</span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Terms and Conditions -->
                    <div class="flex items-center mb-4">
                        <input type="checkbox" id="agree_terms" name="agree_terms" required class="mr-2">
                        <label for="agree_terms" class="text-gray-700">I agree to the 
                            <a href="#" onclick="openModal('termsModal'); return false;" class="text-bulsu-gold hover:underline">Terms and Conditions</a> 
                            and 
                            <a href="#" onclick="openModal('privacyModal'); return false;" class="text-bulsu-gold hover:underline">Privacy Policy</a> 
                            <span class="text-red-500">*</span>
                        </label>
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

                <div class="mt-6 text-center">
                    <a href="index.php" class="inline-block bg-gradient-to-r from-gray-500 to-gray-700 text-white px-6 py-2 rounded-lg font-semibold shadow-lg hover:from-gray-600 hover:to-gray-800 transition transform hover:scale-105">
                        ← Back to Landing Page
                    </a>
                </div>
            </div>
        </section>
    </main>

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
            from { opacity: 0; transform: translateY(40px); }
            to { opacity: 1; transform: translateY(0); }
        }
        .animate-fadeInUp { animation: fadeInUp 0.8s ease-out; }
    </style>

    <script>
        // Modal functions
        function openModal(modalId) {
            document.getElementById(modalId).classList.remove('hidden');
            document.body.style.overflow = 'hidden';
        }

        function closeModal(modalId) {
            document.getElementById(modalId).classList.add('hidden');
            document.body.style.overflow = 'auto';
        }

        // Toggle password visibility
        function togglePassword(inputId, iconId) {
            const input = document.getElementById(inputId);
            const icon = document.getElementById(iconId);
            
            if (input.type === 'password') {
                input.type = 'text';
                icon.innerHTML = `<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.875 18.825A10.05 10.05 0 0112 19c-4.478 0-8.268-2.943-9.543-7a9.97 9.97 0 011.563-3.029m5.858.908a3 3 0 114.243 4.243M9.878 9.878l4.242 4.242M9.88 9.88l-3.29-3.29m7.532 7.532l3.29 3.29M3 3l3.59 3.59m0 0A9.953 9.953 0 0112 5c4.478 0 8.268 2.943 9.543 7a10.025 10.025 0 01-4.132 5.411m0 0L21 21" />`;
            } else {
                input.type = 'password';
                icon.innerHTML = `<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" /><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z" />`;
            }
        }

        // Filter advisers based on student's selections
document.addEventListener('DOMContentLoaded', function() {
    const departmentSelect = document.getElementById('department');
    const yearLevelSelect = document.getElementById('year_level');
    const sectionSelect = document.getElementById('section');
    const adviserSelect = document.getElementById('academic_adviser_id');
    
    function filterAdvisers() {
    const selectedDept = departmentSelect.value;
    const selectedYear = yearLevelSelect.value;
    const selectedSection = sectionSelect.value;
    
    if (!selectedDept || !selectedYear || !selectedSection) {
        return; // Don't filter if not all fields are filled
    }
    
    // Reset adviser dropdown
    adviserSelect.innerHTML = '<option value="">Select your academic adviser</option>';
    
    // Filter advisers
    let matchingAdvisers = advisersData.filter(adviser => {
        let deptMatch = !adviser.department || adviser.department === selectedDept;
        
        // Extract year number from both formats
        let yearMatch = false;
        if (adviser.year_level) {
            const adviserYearNum = adviser.year_level.match(/\d+/)?.[0];
            const studentYearNum = selectedYear.match(/\d+/)?.[0];
            yearMatch = adviserYearNum === studentYearNum;
        }
        
        // Check assigned_groups instead of section
        let sectionMatch = false;
        if (adviser.assigned_groups) {
            const assignedGroups = adviser.assigned_groups.split(',').map(s => s.trim());
            sectionMatch = assignedGroups.includes(selectedSection);
        }
        
        return deptMatch && yearMatch && sectionMatch;
    });
    
    if (matchingAdvisers.length === 0) {
        adviserSelect.innerHTML = '<option value="">No matching adviser found for your selections</option>';
    } else {
        matchingAdvisers.forEach(adviser => {
            const option = document.createElement('option');
            option.value = adviser.id;
            option.setAttribute('data-email', adviser.email);
            option.setAttribute('data-department', adviser.department || '');
            option.setAttribute('data-year', adviser.year_level || '');
            option.setAttribute('data-section', adviser.section || '');
            
            let details = [];
            if (adviser.department) details.push(adviser.department);
            if (adviser.assigned_groups) details.push(adviser.assigned_groups);
            
            option.textContent = adviser.name + (details.length ? ' - ' + details.join(', ') : '');
            adviserSelect.appendChild(option);
        });
        
        // Auto-select if only one match
        if (matchingAdvisers.length === 1) {
            adviserSelect.value = matchingAdvisers[0].id;
            document.getElementById('academic_adviser_email').value = matchingAdvisers[0].email;
        }
    }
}
    
    departmentSelect.addEventListener('change', filterAdvisers);
    yearLevelSelect.addEventListener('change', filterAdvisers);
    sectionSelect.addEventListener('change', filterAdvisers);
});

        // Mobile menu toggle
        const menuBtn = document.getElementById('menu-btn');
        const mobileMenu = document.getElementById('mobile-menu');
        menuBtn.addEventListener('click', () => mobileMenu.classList.toggle('hidden'));
        document.addEventListener('click', function(e) {
            if (!mobileMenu.classList.contains('hidden') && !menuBtn.contains(e.target) && !mobileMenu.contains(e.target)) {
                mobileMenu.classList.add('hidden');
            }
        });

        // Auto-populate academic adviser email when adviser is selected
        document.getElementById('academic_adviser_id').addEventListener('change', function() {
            const selectedOption = this.options[this.selectedIndex];
            const adviserEmail = selectedOption.getAttribute('data-email');
            document.getElementById('academic_adviser_email').value = adviserEmail || '';
        });

        // Date of birth formatting (MM/DD/YYYY)
        document.getElementById('dob').addEventListener('input', function(e) {
            let value = e.target.value.replace(/\D/g, '');
            if (value.length >= 2) value = value.slice(0, 2) + '/' + value.slice(2);
            if (value.length >= 5) value = value.slice(0, 5) + '/' + value.slice(5, 9);
            e.target.value = value;
        });

        // Student ID validation (exactly 10 digits)
        document.getElementById('student_id').addEventListener('input', function(e) {
            let value = e.target.value.replace(/[^0-9]/g, '');
            if (value.length > 10) value = value.substring(0, 10);
            e.target.value = value;
        });

        document.getElementById('student_id').addEventListener('keypress', function(e) {
            if ((e.shiftKey || (e.keyCode < 48 || e.keyCode > 57)) && (e.keyCode < 96 || e.keyCode > 105)) {
                e.preventDefault();
            }
            if (this.value.length >= 10) e.preventDefault();
        });

        // Contact number validation
        document.getElementById('contact_number').addEventListener('input', function(e) {
            let value = e.target.value.replace(/[^0-9]/g, '');
            if (value.length > 11) value = value.substring(0, 11);
            e.target.value = value;
        });

        document.getElementById('contact_number').addEventListener('keypress', function(e) {
            if ((e.shiftKey || (e.keyCode < 48 || e.keyCode > 57)) && (e.keyCode < 96 || e.keyCode > 105)) {
                e.preventDefault();
            }
            if (this.value.length >= 11) e.preventDefault();
        });

        // Real-time password validation
        document.getElementById('password').addEventListener('input', function() {
            const password = this.value;
            
            const reqLength = document.getElementById('req-length');
            if (password.length >= 8) {
                reqLength.classList.remove('text-gray-400', 'text-red-500');
                reqLength.classList.add('text-green-500');
                reqLength.querySelector('span:first-child').textContent = '✓';
            } else {
                reqLength.classList.remove('text-gray-400', 'text-green-500');
                reqLength.classList.add('text-red-500');
                reqLength.querySelector('span:first-child').textContent = '✗';
            }
            
            const reqLetter = document.getElementById('req-letter');
            if (/[a-zA-Z]/.test(password)) {
                reqLetter.classList.remove('text-gray-400', 'text-red-500');
                reqLetter.classList.add('text-green-500');
                reqLetter.querySelector('span:first-child').textContent = '✓';
            } else {
                reqLetter.classList.remove('text-gray-400', 'text-green-500');
                reqLetter.classList.add('text-red-500');
                reqLetter.querySelector('span:first-child').textContent = '✗';
            }
            
            const reqNumber = document.getElementById('req-number');
            if (/[0-9]/.test(password)) {
                reqNumber.classList.remove('text-gray-400', 'text-red-500');
                reqNumber.classList.add('text-green-500');
                reqNumber.querySelector('span:first-child').textContent = '✓';
            } else {
                reqNumber.classList.remove('text-gray-400', 'text-green-500');
                reqNumber.classList.add('text-red-500');
                reqNumber.querySelector('span:first-child').textContent = '✗';
            }
            
            const reqSpecial = document.getElementById('req-special');
            if (/[!@#$%^&*(),.?":{}|<>]/.test(password)) {
                reqSpecial.classList.remove('text-gray-400', 'text-red-500');
                reqSpecial.classList.add('text-green-500');
                reqSpecial.querySelector('span:first-child').textContent = '✓';
            } else {
                reqSpecial.classList.remove('text-gray-400', 'text-green-500');
                reqSpecial.classList.add('text-red-500');
                reqSpecial.querySelector('span:first-child').textContent = '✗';
            }
            
            checkPasswordMatch();
        });

        document.getElementById('confirm_password').addEventListener('input', checkPasswordMatch);

        function checkPasswordMatch() {
            const password = document.getElementById('password').value;
            const confirmPassword = document.getElementById('confirm_password').value;
            const matchIndicator = document.getElementById('password-match');
            const matchIcon = document.getElementById('match-icon');
            
            if (confirmPassword.length > 0) {
                matchIndicator.classList.remove('hidden');
                
                if (password === confirmPassword) {
                    matchIndicator.classList.remove('text-red-500');
                    matchIndicator.classList.add('text-green-500');
                    matchIcon.textContent = '✓';
                } else {
                    matchIndicator.classList.remove('text-green-500');
                    matchIndicator.classList.add('text-red-500');
                    matchIcon.textContent = '✗';
                }
            } else {
                matchIndicator.classList.add('hidden');
            }
        }

        // Form validation
        document.getElementById('signupForm').addEventListener('submit', function(event) {
            const password = document.getElementById('password').value;
            const confirmPassword = document.getElementById('confirm_password').value;
            const agreeTerms = document.getElementById('agree_terms').checked;
            const contactNumber = document.getElementById('contact_number').value;
            const studentId = document.getElementById('student_id').value;
            const dob = document.getElementById('dob').value;
            const academicAdviserId = document.getElementById('academic_adviser_id').value;
            
            let isValid = true;
            
            if (!agreeTerms) isValid = false;
            
            if (!academicAdviserId) {
                alert('Please select your academic adviser');
                isValid = false;
            }
            
            // Validate contact number
            if (contactNumber.length !== 11 || !contactNumber.startsWith('09')) isValid = false;
            
            // Validate student ID
            if (studentId.length !== 10) isValid = false;
            
            // Validate date format
            const datePattern = /^(0[1-9]|1[0-2])\/(0[1-9]|[12][0-9]|3[01])\/\d{4}$/;
            if (!datePattern.test(dob)) isValid = false;
            
            // Validate password requirements
            if (password.length < 8 || 
                !/[a-zA-Z]/.test(password) || 
                !/[0-9]/.test(password) || 
                !/[!@#$%^&*(),.?":{}|<>]/.test(password)) {
                isValid = false;
            }
            
            if (password !== confirmPassword) isValid = false;
            
            if (!isValid) {
                event.preventDefault();
                return false;
            }
        });
    </script>
</body>
</html>