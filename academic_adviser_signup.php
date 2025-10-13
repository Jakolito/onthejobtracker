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
    'name' => '',
    'email' => '',
    'contact_number' => '',
    'department' => '',
    'year_level' => '',
    'section' => '',
    'password' => '',
    'confirm_password' => ''
];

$errors = [];
$registration_success = false;
$email_sent = false;
$assigned_groups_display = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    error_log("Form submitted with POST method");
    
    // Get form data
    $form_data['name'] = trim($_POST['name'] ?? '');
    $form_data['email'] = trim($_POST['email'] ?? '');
    $form_data['contact_number'] = trim($_POST['contact_number'] ?? '');
    $form_data['department'] = trim($_POST['department'] ?? '');
    $form_data['year_level'] = $_POST['year_level'] ?? '';
    $form_data['section'] = trim($_POST['section'] ?? '');
    $form_data['password'] = $_POST['password'] ?? '';
    $form_data['confirm_password'] = $_POST['confirm_password'] ?? '';
    
    error_log("Received data: " . json_encode($form_data));
    
    // Check required fields
    $required_fields = ['name', 'email', 'contact_number', 'department', 'year_level', 'section', 'password'];
    
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
    
    // Validate contact number
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
        $check_email_query = "SELECT * FROM academic_adviser WHERE email = ?";
        $check_email_stmt = mysqli_prepare($conn, $check_email_query);
        
        if ($check_email_stmt) {
            mysqli_stmt_bind_param($check_email_stmt, "s", $form_data['email']);
            mysqli_stmt_execute($check_email_stmt);
            $check_email_result = mysqli_stmt_get_result($check_email_stmt);
            
            if (mysqli_num_rows($check_email_result) > 0) {
                $errors[] = "This email is already registered. Please use a different email.";
            }
            mysqli_stmt_close($check_email_stmt);
        }
        
        // Check if contact number already exists
        $check_contact_query = "SELECT * FROM academic_adviser WHERE contact_number = ?";
        $check_contact_stmt = mysqli_prepare($conn, $check_contact_query);
        
        if ($check_contact_stmt) {
            mysqli_stmt_bind_param($check_contact_stmt, "s", $form_data['contact_number']);
            mysqli_stmt_execute($check_contact_stmt);
            $check_contact_result = mysqli_stmt_get_result($check_contact_stmt);
            
            if (mysqli_num_rows($check_contact_result) > 0) {
                $errors[] = "This contact number is already registered. Please use a different number.";
            }
            mysqli_stmt_close($check_contact_stmt);
        }
    }
    
    // If there are no errors, proceed with registration
    if (empty($errors)) {
        // Hash password
        $hashed_password = password_hash($form_data['password'], PASSWORD_BCRYPT);
        
        // Combine year_level + section + grupos G1 y G2
        // Extract just the number from year_level (e.g., "4th Year" -> "4")
preg_match('/(\d+)/', $form_data['year_level'], $matches);
$year_number = $matches[1] ?? '';

// Combine year_number + section + grupos G1 y G2
$year_section = $year_number . strtoupper($form_data['section']);
$assigned_groups = $year_section . '-G1,' . $year_section . '-G2';
        $assigned_groups_display = $assigned_groups;
        
        // Insert adviser data into database with pending approval status
        $insert_query = "INSERT INTO academic_adviser (
            email, password, name, contact_number, department, year_level, section, 
            assigned_groups, role, approval_status, status, created_at
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'adviser', 'pending', 'inactive', NOW())";
        
        $stmt = mysqli_prepare($conn, $insert_query);
        
        if (!$stmt) {
            $errors[] = 'Database preparation failed: ' . mysqli_error($conn);
            error_log('Statement preparation failed: ' . mysqli_error($conn));
        } else {
            mysqli_stmt_bind_param(
                $stmt,
                "ssssssss", 
                $form_data['email'],
                $hashed_password,
                $form_data['name'],
                $form_data['contact_number'],
                $form_data['department'],
                $form_data['year_level'],
                $form_data['section'],
                $assigned_groups
            );
            
           if (mysqli_stmt_execute($stmt)) {
                $registration_success = true;
                
                // Store info in session
                $_SESSION['adviser_email'] = $form_data['email'];
                $_SESSION['adviser_name'] = $form_data['name'];
                
                // Try to send notification email
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
                    $mail->Subject = 'Academic Adviser Registration - Pending Approval';
                    
                    $mail->Body = '
                    <div style="font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto; padding: 20px; border: 1px solid #ddd; border-radius: 10px;">
                        <div style="text-align: center; margin-bottom: 30px;">
                            <h1 style="color: #800000; margin: 0;">OnTheJob Tracker</h1>
                            <p style="color: #666; margin: 5px 0;">Student OJT Performance Monitoring System</p>
                        </div>
                        
                        <h2 style="color: #333;">Welcome, ' . htmlspecialchars($form_data['name']) . '!</h2>
                        <p style="color: #555; line-height: 1.6;">
                            Thank you for registering as an Academic Adviser with OnTheJob Tracker.
                        </p>
                        
                        <div style="background-color: #fef3cd; padding: 15px; border-radius: 5px; border-left: 4px solid #DAA520; margin: 20px 0;">
                            <p style="margin: 0; color: #92400e;">
                                <strong>Important:</strong> Your account is pending approval. The coordinator will review your registration and approve your access to the system.
                            </p>
                        </div>
                        
                        <div style="margin: 30px 0;">
                            <h4 style="color: #333;">Your Registration Details:</h4>
                            <ul style="color: #555; line-height: 1.8;">
                                <li><strong>Name:</strong> ' . htmlspecialchars($form_data['name']) . '</li>
                                <li><strong>Department:</strong> ' . htmlspecialchars($form_data['department']) . '</li>
                                <li><strong>Year Level:</strong> ' . htmlspecialchars($form_data['year_level']) . '</li>
                                <li><strong>Section:</strong> ' . htmlspecialchars($form_data['section']) . '</li>
                                <li><strong>Assigned Groups:</strong> ' . htmlspecialchars($assigned_groups) . '</li>
                            </ul>
                        </div>
                        
                        <p style="color: #555; line-height: 1.6;">
                            You will receive another email once your account has been approved by the coordinator.
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
    <title>Academic Adviser Sign Up - BULSU OnTheJob Tracker</title>
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
    <div id="termsModal" class="hidden fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50 p-4">
        <div class="bg-white rounded-xl shadow-2xl max-w-3xl w-full max-h-[90vh] overflow-y-auto">
            <div class="sticky top-0 bg-bulsu-maroon text-white px-6 py-4 flex justify-between items-center">
                <h2 class="text-2xl font-bold">Terms and Conditions</h2>
                <button onclick="closeModal('termsModal')" class="text-white hover:text-bulsu-gold text-3xl">&times;</button>
            </div>
            <div class="p-6 text-gray-700 space-y-4">
                <p class="text-sm text-gray-500">Last Updated: January 2025</p>
                
                <h3 class="text-lg font-bold text-bulsu-maroon">1. Acceptance of Terms</h3>
                <p>By creating an account as an Academic Adviser on the OnTheJob Tracker system, you agree to comply with these Terms and Conditions. If you do not agree, please discontinue use of the platform immediately.</p>
                
                <h3 class="text-lg font-bold text-bulsu-maroon">2. Academic Adviser Registration</h3>
                <p>You must provide accurate, complete, and current information during registration. Your account is subject to approval by the OJT Coordinator. You are responsible for maintaining the confidentiality of your account credentials and for all activities under your account.</p>
                
                <h3 class="text-lg font-bold text-bulsu-maroon">3. Role and Responsibilities</h3>
                <p>As an Academic Adviser, you agree to:</p>
                <ul class="list-disc ml-6 space-y-1">
                    <li>Monitor and supervise assigned students during their OJT period</li>
                    <li>Review and provide feedback on student reports and activities</li>
                    <li>Maintain regular communication with students and coordinators</li>
                    <li>Ensure accurate and timely evaluation of student performance</li>
                    <li>Uphold academic integrity and professional standards</li>
                    <li>Protect student privacy and confidential information</li>
                </ul>
                
                <h3 class="text-lg font-bold text-bulsu-maroon">4. Use of Platform</h3>
                <p>The OnTheJob Tracker is designed exclusively for monitoring On-the-Job Training (OJT) performance of Bulacan State University students. Advisers agree to:</p>
                <ul class="list-disc ml-6 space-y-1">
                    <li>Use the platform solely for educational and monitoring purposes</li>
                    <li>Provide fair and objective evaluations based on actual student performance</li>
                    <li>Respect the intellectual property rights of the platform</li>
                    <li>Not attempt to disrupt, hack, or compromise system security</li>
                    <li>Not share login credentials with unauthorized persons</li>
                </ul>
                
                <h3 class="text-lg font-bold text-bulsu-maroon">5. Data Collection and AI Monitoring</h3>
                <p>The platform utilizes AI technology to monitor and analyze OJT performance. By using this system, you consent to:</p>
                <ul class="list-disc ml-6 space-y-1">
                    <li>Collection of professional and account activity data</li>
                    <li>AI-powered analysis of evaluation patterns and feedback</li>
                    <li>Sharing of performance metrics with coordinators and administrators</li>
                </ul>
                
                <h3 class="text-lg font-bold text-bulsu-maroon">6. Account Approval and Status</h3>
                <p>All Academic Adviser accounts require coordinator approval before activation. BULSU reserves the right to suspend or terminate accounts that violate these terms, including but not limited to: fraudulent information, system abuse, academic dishonesty, or misuse of platform features.</p>
                
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
                <p>Bulacan State University ("BULSU," "we," "us," or "our") respects your privacy and is committed to protecting your personal data. This Privacy Policy explains how we collect, use, disclose, and safeguard information collected through the OnTheJob Tracker platform for Academic Advisers.</p>
                
                <h3 class="text-lg font-bold text-bulsu-maroon">2. Information We Collect</h3>
                <p>We collect the following types of information from Academic Advisers:</p>
                <ul class="list-disc ml-6 space-y-1">
                    <li><strong>Personal Information:</strong> Name, email address, contact number</li>
                    <li><strong>Professional Information:</strong> Department, year level, section assignments, assigned student groups</li>
                    <li><strong>Activity Data:</strong> Evaluations, feedback provided, reports reviewed, communication records</li>
                    <li><strong>Technical Data:</strong> IP address, browser type, device information, login timestamps</li>
                </ul>
                
                <h3 class="text-lg font-bold text-bulsu-maroon">3. How We Use Your Information</h3>
                <p>Your information is used to:</p>
                <ul class="list-disc ml-6 space-y-1">
                    <li>Facilitate OJT student monitoring and evaluation</li>
                    <li>Enable communication between advisers, students, and coordinators</li>
                    <li>Generate performance reports and analytics</li>
                    <li>Improve platform functionality and user experience</li>
                    <li>Comply with university policies and legal requirements</li>
                    <li>Provide AI-powered insights and recommendations</li>
                    <li>Verify identity and prevent unauthorized access</li>
                </ul>
                
                <h3 class="text-lg font-bold text-bulsu-maroon">4. Information Sharing</h3>
                <p>We may share your information with:</p>
                <ul class="list-disc ml-6 space-y-1">
                    <li>OJT Coordinators and university administrators for oversight purposes</li>
                    <li>Assigned students within your advisory capacity</li>
                    <li>Other authorized university personnel as required for academic operations</li>
                    <li>Third-party service providers who assist in platform operations (subject to confidentiality agreements)</li>
                </ul>
                <p class="mt-2">We do not sell or rent your personal information to third parties.</p>
                
                <h3 class="text-lg font-bold text-bulsu-maroon">5. Data Security</h3>
                <p>We implement appropriate technical and organizational measures to protect your personal data against unauthorized access, alteration, disclosure, or destruction. However, no method of transmission over the internet is 100% secure.</p>
                
                <h3 class="text-lg font-bold text-bulsu-maroon">6. Your Rights</h3>
                <p>You have the right to:</p>
                <ul class="list-disc ml-6 space-y-1">
                    <li>Access your personal information</li>
                    <li>Request correction of inaccurate data</li>
                    <li>Request deletion of your account (subject to university record-keeping requirements)</li>
                    <li>Object to certain data processing activities</li>
                </ul>
                
                <h3 class="text-lg font-bold text-bulsu-maroon">7. Data Retention</h3>
                <p>We retain your information for as long as necessary to fulfill the purposes outlined in this policy, or as required by university policies and applicable laws.</p>
                
                <h3 class="text-lg font-bold text-bulsu-maroon">8. Contact Us</h3>
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
            <div class="text-5xl text-bulsu-gold mb-4">⏳</div>
            <h2 class="text-bulsu-maroon text-2xl font-bold mb-2">Registration Submitted!</h2>
            <div class="text-gray-700 mb-4">
                <p><strong>Thank you, <?php echo htmlspecialchars($form_data['name']); ?>!</strong></p>
                <p>Your application as an Academic Adviser has been submitted successfully.</p>
                <?php if ($email_sent): ?>
                    <p class="mt-2">A confirmation email has been sent to <strong><?php echo htmlspecialchars($form_data['email']); ?></strong></p>
                <?php endif; ?>
                <div class="bg-blue-50 border border-blue-200 rounded p-3 mt-4 text-sm text-left">
                    <p class="font-semibold text-blue-800">📋 Auto-Assigned Groups</p>
                    <p class="text-blue-700">Groups <strong><?php echo htmlspecialchars($assigned_groups_display); ?></strong> have been automatically assigned to your section.</p>
                </div>
                <div class="bg-yellow-50 border border-yellow-200 rounded p-3 mt-4 text-sm text-left">
                    <p class="font-semibold text-yellow-800">⚠️ Pending Approval</p>
                    <p class="text-yellow-700">Your account is currently pending approval from the coordinator. You will receive an email notification once your account has been approved.</p>
                </div>
            </div>
            <a href="login.php" class="inline-block bg-bulsu-maroon hover:bg-bulsu-dark-maroon text-white px-6 py-2 rounded font-semibold transition mt-4">Go to Login</a>
        </div>
    </div>
    <?php endif; ?>

    <main class="flex-1 flex items-center justify-center px-2 py-8">
        <section class="w-full flex items-center justify-center">
            <div class="bg-white bg-opacity-95 rounded-2xl shadow-2xl p-8 md:p-12 max-w-4xl w-full animate-fadeInUp">
                <div class="text-center mb-8">
                    <div class="mb-6">
                        <h3 class="text-bulsu-gold font-semibold text-lg mb-2">Bulacan State University</h3>
                        <div class="w-24 h-1 bg-bulsu-gold mx-auto rounded"></div>
                    </div>
                    <h1 class="text-bulsu-maroon text-2xl md:text-3xl font-bold mb-2">Academic Adviser Registration</h1>
                    <p class="text-gray-600">Create your adviser account to monitor student OJT performance</p>
                    
                </div>

                <?php if (!empty($errors)): ?>
                <div class="bg-red-50 border border-red-200 text-red-700 px-4 py-3 rounded mb-6 text-left text-sm">
                    <strong>Please fix the following errors:</strong>
                    <ul class="list-disc ml-6 mt-2">
                        <?php foreach ($errors as $error): ?>
                            <li><?php echo htmlspecialchars($error); ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
                <?php endif; ?>

                <form method="POST" action="academic_adviser_signup.php" id="adviserSignupForm" class="space-y-8">
                    <!-- Personal Information -->
                    <div>
                        <h3 class="text-bulsu-maroon font-semibold text-lg mb-4 pb-2 border-b-2 border-bulsu-gold">Personal Information</h3>
                        <div class="space-y-4">
                            <div>
                                <label for="name" class="block text-gray-700 font-medium mb-2">Full Name <span class="text-red-500">*</span></label>
                                <input type="text" id="name" name="name" placeholder="Enter your full name"
                                    value="<?php echo htmlspecialchars($form_data['name']); ?>" required
                                    class="w-full px-4 py-3 border-2 border-gray-200 rounded-lg focus:outline-none focus:border-bulsu-gold transition">
                            </div>
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                <div>
                                    <label for="email" class="block text-gray-700 font-medium mb-2">Email Address <span class="text-red-500">*</span></label>
                                    <input type="email" id="email" name="email" placeholder="Enter your email address"
                                        value="<?php echo htmlspecialchars($form_data['email']); ?>" required
                                        class="w-full px-4 py-3 border-2 border-gray-200 rounded-lg focus:outline-none focus:border-bulsu-gold transition">
                                </div>
                                <div>
                                    <label for="contact_number" class="block text-gray-700 font-medium mb-2">Contact Number <span class="text-red-500">*</span></label>
                                    <input type="tel" id="contact_number" name="contact_number" placeholder="09XXXXXXXXX"
                                        value="<?php echo htmlspecialchars($form_data['contact_number']); ?>"
                                        pattern="09[0-9]{9}" maxlength="11" required
                                        class="w-full px-4 py-3 border-2 border-gray-200 rounded-lg focus:outline-none focus:border-bulsu-gold transition">
                                    <small class="text-gray-500 text-xs mt-1 block">Must be exactly 11 digits starting with 09</small>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Assignment Information -->
                    <div>
                        <h3 class="text-bulsu-maroon font-semibold text-lg mb-4 pb-2 border-b-2 border-bulsu-gold">Assignment Information</h3>
                        <div class="space-y-4">
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                <div>
                                    <label for="department" class="block text-gray-700 font-medium mb-2">Department <span class="text-red-500">*</span></label>
                                    <input type="text" id="department" name="department" 
                                        placeholder="e.g., BSIT, BSCS, BSIS"
                                        value="<?php echo htmlspecialchars($form_data['department']); ?>" required
                                        class="w-full px-4 py-3 border-2 border-gray-200 rounded-lg focus:outline-none focus:border-bulsu-gold transition">
                                    <small class="text-gray-500 text-xs mt-1 block">Enter your assigned department</small>
                                </div>
                                <div>
                                    <label for="year_level" class="block text-gray-700 font-medium mb-2">Year Level <span class="text-red-500">*</span></label>
                                    <select id="year_level" name="year_level" required
    class="w-full px-4 py-3 border-2 border-gray-200 rounded-lg focus:outline-none focus:border-bulsu-gold transition">
    <option value="">Select Year Level</option>
    <option value="1st Year" <?php echo ($form_data['year_level'] == '1st Year') ? 'selected' : ''; ?>>1st Year</option>
    <option value="2nd Year" <?php echo ($form_data['year_level'] == '2nd Year') ? 'selected' : ''; ?>>2nd Year</option>
    <option value="3rd Year" <?php echo ($form_data['year_level'] == '3rd Year') ? 'selected' : ''; ?>>3rd Year</option>
    <option value="4th Year" <?php echo ($form_data['year_level'] == '4th Year') ? 'selected' : ''; ?>>4th Year</option>
</select>
                                </div>
                            </div>
                            <div>
                                <label for="section" class="block text-gray-700 font-medium mb-2">Section <span class="text-red-500">*</span></label>
                                <input type="text" id="section" name="section" 
                                    placeholder="e.g., A, B, C"
                                    value="<?php echo htmlspecialchars($form_data['section']); ?>" required
                                    class="w-full px-4 py-3 border-2 border-gray-200 rounded-lg focus:outline-none focus:border-bulsu-gold transition">
                                <small class="text-gray-500 text-xs mt-1 block">Enter your section (e.g., A, B, C). Groups will be auto-generated with your year level.</small>
                            </div>
                            
                            <div class="bg-green-50 border border-green-300 rounded-lg p-4">
                                <div class="flex items-start">
                                    <svg class="w-5 h-5 text-green-600 mt-0.5 mr-3 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20">
                                        <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"/>
                                    </svg>
                                    <div>
                                        <p class="text-green-800 font-semibold">Auto-Assigned Groups</p>
                                        <p class="text-green-700 text-sm mt-1">When you register, two groups will automatically be created combining your year level and section with G1 and G2.</p>
                                        <p class="text-green-700 text-sm mt-1"><strong>Example:</strong> Year Level 4 + Section B = Groups <strong>4B-G1</strong> and <strong>4B-G2</strong></p>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Account Security -->
                    <div>
                        <h3 class="text-bulsu-maroon font-semibold text-lg mb-4 pb-2 border-b-2 border-bulsu-gold">Account Security</h3>
                        <div class="space-y-4">
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                <div>
                                    <label for="password" class="block text-gray-700 font-medium mb-2">Password <span class="text-red-500">*</span></label>
                                    <div class="relative">
                                        <input type="password" id="password" name="password" placeholder="Enter your password"
                                            minlength="8" required
                                            class="w-full px-4 py-3 pr-12 border-2 border-gray-200 rounded-lg focus:outline-none focus:border-bulsu-gold transition">
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
                                    <label for="confirm_password" class="block text-gray-700 font-medium mb-2">Confirm Password <span class="text-red-500">*</span></label>
                                    <div class="relative">
                                        <input type="password" id="confirm_password" name="confirm_password" placeholder="Confirm your password"
                                            minlength="8" required
                                            class="w-full px-4 py-3 pr-12 border-2 border-gray-200 rounded-lg focus:outline-none focus:border-bulsu-gold transition">
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
                    </div>

                    <!-- Terms and Conditions -->
                    <div class="border-t-2 border-gray-200 pt-6">
                        <div class="flex items-start space-x-3">
                            <input type="checkbox" id="agree_terms" name="agree_terms" required class="mt-1 w-4 h-4 text-bulsu-maroon focus:ring-bulsu-gold border-gray-300 rounded">
                            <label for="agree_terms" class="text-gray-700 text-sm">
                                I agree to the <a href="#" onclick="event.preventDefault(); openModal('termsModal');" class="text-bulsu-gold hover:underline font-medium">Terms and Conditions</a> and <a href="#" onclick="event.preventDefault(); openModal('privacyModal');" class="text-bulsu-gold hover:underline font-medium">Privacy Policy</a> <span class="text-red-500">*</span>
                            </label>
                        </div>
                    </div>

                    <button type="submit"
                        class="w-full bg-gradient-to-r from-bulsu-maroon to-bulsu-dark-maroon text-white rounded-lg py-4 font-semibold text-lg shadow-lg hover:from-bulsu-dark-maroon hover:to-black transition transform hover:scale-105">
                        Create Account
                    </button>
                </form>

                <div class="text-center mt-6 text-gray-600 border-t pt-6">
                    Already have an account?
                    <a href="login.php" class="text-bulsu-gold font-semibold hover:text-bulsu-dark-maroon transition ml-1">Sign In</a>
                </div>

                <div class="mt-4 text-center">
                    <a href="index.php" class="inline-block bg-gradient-to-r from-gray-500 to-gray-700 text-white px-6 py-3 rounded-lg font-semibold shadow-lg hover:from-gray-600 hover:to-gray-800 transition transform hover:scale-105">
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
         function openModal(modalId) {
        document.getElementById(modalId).classList.remove('hidden');
        document.body.style.overflow = 'hidden';
    }

    function closeModal(modalId) {
        document.getElementById(modalId).classList.add('hidden');
        document.body.style.overflow = 'auto';
    }
        function togglePassword(inputId, iconId) {
            const input = document.getElementById(inputId);
            const icon = document.getElementById(iconId);
            
            if (input.type === 'password') {
                input.type = 'text';
                icon.innerHTML = `
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.875 18.825A10.05 10.05 0 0112 19c-4.478 0-8.268-2.943-9.543-7a9.97 9.97 0 011.563-3.029m5.858.908a3 3 0 114.243 4.243M9.878 9.878l4.242 4.242M9.88 9.88l-3.29-3.29m7.532 7.532l3.29 3.29M3 3l3.59 3.59m0 0A9.953 9.953 0 0112 5c4.478 0 8.268 2.943 9.543 7a10.025 10.025 0 01-4.132 5.411m0 0L21 21" />
                `;
            } else {
                input.type = 'password';
                icon.innerHTML = `
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z" />
                `;
            }
        }

        const menuBtn = document.getElementById('menu-btn');
        const mobileMenu = document.getElementById('mobile-menu');
        menuBtn.addEventListener('click', () => {
            mobileMenu.classList.toggle('hidden');
        });

        document.addEventListener('click', function(e) {
            if (!mobileMenu.classList.contains('hidden') && !menuBtn.contains(e.target) && !mobileMenu.contains(e.target)) {
                mobileMenu.classList.add('hidden');
            }
        });

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

        document.getElementById('adviserSignupForm').addEventListener('submit', function(event) {
            const password = document.getElementById('password').value;
            const confirmPassword = document.getElementById('confirm_password').value;
            const agreeTerms = document.getElementById('agree_terms').checked;
            const contactNumber = document.getElementById('contact_number').value;
            
            let isValid = true;
            
            if (!agreeTerms) isValid = false;
            if (contactNumber.length !== 11 || !contactNumber.startsWith('09')) isValid = false;
            if (password.length < 8 || 
                !/[a-zA-Z]/.test(password) || 
                !/[0-9]/.test(password) || 
                !/[!@#$%^&*(),.?":{}|<>]/.test(password)) isValid = false;
            if (password !== confirmPassword) isValid = false;
            
            if (!isValid) {
                event.preventDefault();
                return false;
            }
        });
    </script>
</body>
</html>