<?php
include('connect.php');
session_start();

// Initialize variables to retain form data
$form_data = [
    'company_name' => '',
    'company_address' => '',
    'industry_field' => '',
    'company_contact' => '',
    'company_email' => '',
    'full_name' => '',
    'position' => '',
    'email' => '',
    'contact_number' => '',
    'students_needed' => '',
    'role_position' => '',
    'required_skills' => '',
    'internship_duration' => '',
    'work_schedule_start' => '',
    'work_schedule_end' => '',
    'work_days' => [],
    'internship_start_date' => '',
    'internship_end_date' => '',
    'password' => '',
    'confirm_password' => ''
];

$errors = [];
$registration_success = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Debug: Check if form is being submitted
    error_log("Supervisor form submitted with POST method");
    
    // Get form data and store in array for retention
    $form_data['company_name'] = trim($_POST['company_name'] ?? '');
    $form_data['company_address'] = trim($_POST['company_address'] ?? '');
    $form_data['industry_field'] = trim($_POST['industry_field'] ?? '');
    $form_data['company_contact'] = trim($_POST['company_contact'] ?? '');
    $form_data['company_email'] = trim($_POST['company_email'] ?? '');
    $form_data['full_name'] = trim($_POST['full_name'] ?? '');
    $form_data['position'] = trim($_POST['position'] ?? '');
    $form_data['email'] = trim($_POST['email'] ?? '');
    $form_data['contact_number'] = trim($_POST['contact_number'] ?? '');
    $form_data['students_needed'] = trim($_POST['students_needed'] ?? '');
    $form_data['role_position'] = trim($_POST['role_position'] ?? '');
    $form_data['required_skills'] = trim($_POST['required_skills'] ?? '');
    $form_data['internship_duration'] = trim($_POST['internship_duration'] ?? '');
    $form_data['work_schedule_start'] = trim($_POST['work_schedule_start'] ?? '');
    $form_data['work_schedule_end'] = trim($_POST['work_schedule_end'] ?? '');
    $form_data['work_days'] = $_POST['work_days'] ?? [];
    $form_data['internship_start_date'] = trim($_POST['internship_start_date'] ?? '');
    $form_data['internship_end_date'] = trim($_POST['internship_end_date'] ?? '');
    $form_data['password'] = $_POST['password'] ?? '';
    $form_data['confirm_password'] = $_POST['confirm_password'] ?? '';
    
    // Debug: Log received data
    error_log("Received supervisor data: " . json_encode($form_data));
    
    // Check required fields
    $required_fields = [
        'company_name', 'company_address', 'industry_field', 'company_contact',
        'full_name', 'position', 'email', 'contact_number', 'students_needed',
        'role_position', 'internship_duration', 'work_schedule_start', 'work_schedule_end',
        'internship_start_date', 'internship_end_date', 'password'
    ];
    
    foreach ($required_fields as $field) {
        if (empty($form_data[$field])) {
            $errors[] = ucwords(str_replace('_', ' ', $field)) . " is required.";
        }
    }
    
    // Check if work days are selected
    if (empty($form_data['work_days'])) {
        $errors[] = "Please select at least one work day.";
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
    
    // Validate company email format if provided
    if (!empty($form_data['company_email']) && !filter_var($form_data['company_email'], FILTER_VALIDATE_EMAIL)) {
        $errors[] = "Please enter a valid company email address.";
    }
    
    // Validate contact numbers - must be exactly 11 digits
    $contact_fields = ['contact_number', 'company_contact'];
    foreach ($contact_fields as $field) {
        if (!empty($form_data[$field])) {
            // Remove any non-numeric characters
            $clean_number = preg_replace('/[^0-9]/', '', $form_data[$field]);
            
            if (strlen($clean_number) !== 11) {
                $field_name = ($field === 'contact_number') ? 'Personal contact number' : 'Company contact number';
                $errors[] = $field_name . " must be exactly 11 digits.";
            } elseif (!preg_match('/^09\d{9}$/', $clean_number)) {
                $field_name = ($field === 'contact_number') ? 'Personal contact number' : 'Company contact number';
                $errors[] = $field_name . " must start with 09 and be exactly 11 digits.";
            } else {
                // Store the cleaned number
                $form_data[$field] = $clean_number;
            }
        }
    }
    
    // Validate students needed (must be a positive number)
    if (!empty($form_data['students_needed']) && (!is_numeric($form_data['students_needed']) || intval($form_data['students_needed']) <= 0)) {
        $errors[] = "Number of students needed must be a positive number.";
    }
    
    // Validate time schedule
    if (!empty($form_data['work_schedule_start']) && !empty($form_data['work_schedule_end'])) {
        $start_time = strtotime($form_data['work_schedule_start']);
        $end_time = strtotime($form_data['work_schedule_end']);
        
        if ($start_time >= $end_time) {
            $errors[] = "Work schedule end time must be after start time.";
        }
    }
    
    // Validate internship dates
    if (!empty($form_data['internship_start_date']) && !empty($form_data['internship_end_date'])) {
        $start_date = strtotime($form_data['internship_start_date']);
        $end_date = strtotime($form_data['internship_end_date']);
        $today = strtotime(date('Y-m-d'));
        
        if ($start_date < $today) {
            $errors[] = "Internship start date cannot be in the past.";
        }
        
        if ($start_date >= $end_date) {
            $errors[] = "Internship end date must be after start date.";
        }
    }
    
    // Check if terms are agreed
    if (!isset($_POST['agree_terms'])) {
        $errors[] = "You must agree to the Terms and Conditions.";
    }
    
    // Only check database if basic validation passes
    if (empty($errors)) {
        // Check if email already exists
        $check_email_query = "SELECT * FROM company_supervisors WHERE email = ?";
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
        
        // Check if phone number already exists
        $check_contact_query = "SELECT * FROM company_supervisors WHERE phone_number = ?";
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
        
        // Use email as username since username field is removed
        $username = $form_data['email'];
        
        // Convert work days array to comma-separated string
        $work_days_string = implode(',', $form_data['work_days']);
        
        // Insert supervisor data into database with schedule fields
        $insert_query = "INSERT INTO company_supervisors (
            company_name, company_address, industry_field, company_contact_number, 
            full_name, position, email, phone_number, students_needed, role_position, 
            required_skills, internship_duration, work_schedule_start, work_schedule_end,
            work_days, internship_start_date, internship_end_date, username, password, 
            account_status, work_mode
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'Pending', 'On-site')";
        
        $stmt = mysqli_prepare($conn, $insert_query);
        
        if (!$stmt) {
            $errors[] = 'Database preparation failed: ' . mysqli_error($conn);
            error_log('Statement preparation failed: ' . mysqli_error($conn));
        } else {
            // Bind parameters - 19 parameters total
            mysqli_stmt_bind_param(
                $stmt,
                "ssssssssissssssssss", 
                $form_data['company_name'],
                $form_data['company_address'],
                $form_data['industry_field'],
                $form_data['company_contact'],
                $form_data['full_name'],
                $form_data['position'],
                $form_data['email'],
                $form_data['contact_number'],
                $form_data['students_needed'],
                $form_data['role_position'],
                $form_data['required_skills'],
                $form_data['internship_duration'],
                $form_data['work_schedule_start'],
                $form_data['work_schedule_end'],
                $work_days_string,
                $form_data['internship_start_date'],
                $form_data['internship_end_date'],
                $username,
                $hashed_password
            );
            
            if (mysqli_stmt_execute($stmt)) {
                $registration_success = true;
                
                // Store supervisor info in session for success message
                $_SESSION['supervisor_name'] = $form_data['full_name'];
                $_SESSION['company_name'] = $form_data['company_name'];
                $_SESSION['supervisor_email'] = $form_data['email'];
                
            } else {
                $errors[] = "Registration failed: " . mysqli_stmt_error($stmt);
                error_log("Supervisor registration failed: " . mysqli_stmt_error($stmt));
            }
            
            mysqli_stmt_close($stmt);
        }
    }
    
    // Debug: Log any errors
    if (!empty($errors)) {
        error_log("Supervisor registration errors: " . json_encode($errors));
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Company Supervisor Registration - BULSU OnTheJob Tracker</title>
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
            <div class="text-5xl text-bulsu-gold mb-4">⏳</div>
            <h2 class="text-bulsu-maroon text-2xl font-bold mb-2">Registration Submitted Successfully!</h2>
            <div class="text-gray-700 mb-4">
                <p><strong>Welcome, <?php echo htmlspecialchars($_SESSION['supervisor_name'] ?? ''); ?>!</strong></p>
                <p><strong><?php echo htmlspecialchars($_SESSION['company_name'] ?? ''); ?></strong></p>
                <hr class="my-4 border-gray-200">
                <p><strong>Your account is now pending admin approval.</strong></p>
                <p>You will receive an email notification at <strong><?php echo htmlspecialchars($_SESSION['supervisor_email'] ?? ''); ?></strong> once your account has been reviewed and approved by our academic advisers.</p>
                <div class="mt-4 p-3 bg-bulsu-light-gold bg-opacity-50 border-l-4 border-bulsu-gold rounded">
                    <p class="text-bulsu-maroon text-sm">
                        <strong>Note:</strong> This process typically takes a few days. Thank you for your patience!
                    </p>
                </div>
            </div>
            <div class="flex justify-center">
                <a href="index.php" class="bg-gradient-to-r from-bulsu-maroon to-bulsu-dark-maroon text-white px-6 py-2 rounded font-semibold transition hover:from-bulsu-dark-maroon hover:to-black">Back to Home</a>
            </div>
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
                    <div class="bg-gradient-to-r from-bulsu-maroon to-bulsu-dark-maroon text-white px-4 py-2 rounded-full inline-block font-medium text-sm mb-4">
                        👔 Company Supervisor Registration
                    </div>
                    <h1 class="text-bulsu-maroon text-2xl md:text-3xl font-bold mb-2">Register Your Company</h1>
                    <p class="text-gray-600">Partner with us to provide OJT opportunities for BULSU students</p>
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

                <form method="POST" action="supervisor-signup.php" id="supervisorSignupForm" class="space-y-6">

                    <!-- Company Information Section -->
                    <div class="border-b border-gray-200 pb-6">
                        <h3 class="text-bulsu-maroon font-semibold mb-4 flex items-center gap-2">
                            <span class="text-lg">🏢</span> Company Information
                        </h3>
                        <div class="grid grid-cols-1 gap-4 mb-4">
                            <div>
                                <label for="company_name" class="block text-gray-700 font-medium mb-1">Company Name <span class="text-red-500">*</span></label>
                                <input type="text" id="company_name" name="company_name" placeholder="Enter your company name" 
                                       value="<?php echo htmlspecialchars($form_data['company_name']); ?>" required
                                       class="w-full px-4 py-2 border-2 border-gray-200 rounded-lg focus:outline-none focus:border-bulsu-gold transition">
                            </div>
                            <div>
                                <label for="company_address" class="block text-gray-700 font-medium mb-1">Company Address <span class="text-red-500">*</span></label>
                                <textarea id="company_address" name="company_address" placeholder="Enter complete company address" required
                                          class="w-full px-4 py-2 border-2 border-gray-200 rounded-lg focus:outline-none focus:border-bulsu-gold transition min-h-20 resize-y"><?php echo htmlspecialchars($form_data['company_address']); ?></textarea>
                            </div>
                        </div>
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
                            <div>
                                <label for="industry_field" class="block text-gray-700 font-medium mb-1">Industry / Field <span class="text-red-500">*</span></label>
                                <input type="text" id="industry_field" name="industry_field" placeholder="e.g., IT, Marketing, Engineering" 
                                       value="<?php echo htmlspecialchars($form_data['industry_field']); ?>" required
                                       class="w-full px-4 py-2 border-2 border-gray-200 rounded-lg focus:outline-none focus:border-bulsu-gold transition">
                            </div>
                            <div>
                                <label for="company_contact" class="block text-gray-700 font-medium mb-1">Company Contact Number <span class="text-red-500">*</span></label>
                                <input type="tel" id="company_contact" name="company_contact" placeholder="09XXXXXXXXX" 
                                       value="<?php echo htmlspecialchars($form_data['company_contact']); ?>" 
                                       pattern="09[0-9]{9}" maxlength="11" required
                                       class="w-full px-4 py-2 border-2 border-gray-200 rounded-lg focus:outline-none focus:border-bulsu-gold transition">
                                <small class="text-gray-400 text-xs">Must be exactly 11 digits starting with 09</small>
                            </div>
                        </div>
                        <div>
                            <label for="company_email" class="block text-gray-700 font-medium mb-1">Company Email <span class="text-gray-400 text-xs">(Optional)</span></label>
                            <input type="email" id="company_email" name="company_email" placeholder="company@example.com" 
                                   value="<?php echo htmlspecialchars($form_data['company_email']); ?>"
                                   class="w-full px-4 py-2 border-2 border-gray-200 rounded-lg focus:outline-none focus:border-bulsu-gold transition">
                        </div>
                    </div>

                    <!-- Personal Information Section -->
                    <div class="border-b border-gray-200 pb-6">
                        <h3 class="text-bulsu-maroon font-semibold mb-4 flex items-center gap-2">
                            <span class="text-lg">👤</span> Supervisor or Manager Information
                        </h3>
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
                            <div>
                                <label for="full_name" class="block text-gray-700 font-medium mb-1">Full Name <span class="text-red-500">*</span></label>
                                <input type="text" id="full_name" name="full_name" placeholder="Enter your full name" 
                                       value="<?php echo htmlspecialchars($form_data['full_name']); ?>" required
                                       class="w-full px-4 py-2 border-2 border-gray-200 rounded-lg focus:outline-none focus:border-bulsu-gold transition">
                            </div>
                            <div>
                                <label for="position" class="block text-gray-700 font-medium mb-1">Position in Company <span class="text-red-500">*</span></label>
                                <input type="text" id="position" name="position" placeholder="e.g., HR Manager, Supervisor" 
                                       value="<?php echo htmlspecialchars($form_data['position']); ?>" required
                                       class="w-full px-4 py-2 border-2 border-gray-200 rounded-lg focus:outline-none focus:border-bulsu-gold transition">
                            </div>
                        </div>
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
                            <div>
                                <label for="email" class="block text-gray-700 font-medium mb-1">Email Address <span class="text-red-500">*</span></label>
                                <input type="email" id="email" name="email" placeholder="your.email@example.com" 
                                       value="<?php echo htmlspecialchars($form_data['email']); ?>" required
                                       class="w-full px-4 py-2 border-2 border-gray-200 rounded-lg focus:outline-none focus:border-bulsu-gold transition">
                                <small class="text-gray-400 text-xs">This will be your login email</small>
                            </div>
                            <div>
                                <label for="contact_number" class="block text-gray-700 font-medium mb-1">Personal Contact Number <span class="text-red-500">*</span></label>
                                <input type="tel" id="contact_number" name="contact_number" placeholder="09XXXXXXXXX" 
                                       value="<?php echo htmlspecialchars($form_data['contact_number']); ?>" 
                                       pattern="09[0-9]{9}" maxlength="11" required
                                       class="w-full px-4 py-2 border-2 border-gray-200 rounded-lg focus:outline-none focus:border-bulsu-gold transition">
                                <small class="text-gray-400 text-xs">Must be exactly 11 digits starting with 09</small>
                            </div>
                        </div>
                    </div>

                    <!-- Internship Details Section -->
                    <div class="border-b border-gray-200 pb-6">
                        <h3 class="text-bulsu-maroon font-semibold mb-4 flex items-center gap-2">
                            <span class="text-lg">📝</span> Internship Details
                        </h3>
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
                            <div>
                                <label for="students_needed" class="block text-gray-700 font-medium mb-1">Number of Students Needed <span class="text-red-500">*</span></label>
                                <input type="number" id="students_needed" name="students_needed" placeholder="e.g., 5" min="1" 
                                       value="<?php echo htmlspecialchars($form_data['students_needed']); ?>" required
                                       class="w-full px-4 py-2 border-2 border-gray-200 rounded-lg focus:outline-none focus:border-bulsu-gold transition">
                            </div>
                            <div>
                                <label for="internship_duration" class="block text-gray-700 font-medium mb-1">Internship Duration <span class="text-red-500">*</span></label>
                                <select id="internship_duration" name="internship_duration" required
                                        class="w-full px-4 py-2 border-2 border-gray-200 rounded-lg focus:outline-none focus:border-bulsu-gold transition">
                                    <option value="">Select duration</option>
                                    <option value="3 months" <?php echo ($form_data['internship_duration'] === '3 months') ? 'selected' : ''; ?>>3 months</option>
                                    <option value="6 months" <?php echo ($form_data['internship_duration'] === '6 months') ? 'selected' : ''; ?>>6 months</option>
                                    <option value="1 year" <?php echo ($form_data['internship_duration'] === '1 year') ? 'selected' : ''; ?>>1 year</option>
                                    <option value="Other" <?php echo ($form_data['internship_duration'] === 'Other') ? 'selected' : ''; ?>>Other</option>
                                </select>
                            </div>
                        </div>
                        <div class="mb-4">
                            <label for="role_position" class="block text-gray-700 font-medium mb-1">Role/Position for Interns <span class="text-red-500">*</span></label>
                            <input type="text" id="role_position" name="role_position" placeholder="e.g., IT Support Intern, Marketing Assistant" 
                                   value="<?php echo htmlspecialchars($form_data['role_position']); ?>" required
                                   class="w-full px-4 py-2 border-2 border-gray-200 rounded-lg focus:outline-none focus:border-bulsu-gold transition">
                        </div>
                        <div>
                            <label for="required_skills" class="block text-gray-700 font-medium mb-1">Required Skills <span class="text-red-500">*</span></label>
                            <textarea id="required_skills" name="required_skills" placeholder="List the skills and qualifications required for this internship" required
                                      class="w-full px-4 py-2 border-2 border-gray-200 rounded-lg focus:outline-none focus:border-bulsu-gold transition min-h-20 resize-y"><?php echo htmlspecialchars($form_data['required_skills']); ?></textarea>
                        </div>
                    </div>

                    <!-- Work Schedule Section -->
                    <div class="border-b border-gray-200 pb-6">
                        <h3 class="text-bulsu-maroon font-semibold mb-4 flex items-center gap-2">
                            <span class="text-lg">⏰</span> Work Schedule
                        </h3>
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
                            <div>
                                <label for="work_schedule_start" class="block text-gray-700 font-medium mb-1">Work Start Time <span class="text-red-500">*</span></label>
                                <input type="time" id="work_schedule_start" name="work_schedule_start" 
                                       value="<?php echo htmlspecialchars($form_data['work_schedule_start']); ?>" required
                                       class="w-full px-4 py-2 border-2 border-gray-200 rounded-lg focus:outline-none focus:border-bulsu-gold transition">
                            </div>
                            <div>
                                <label for="work_schedule_end" class="block text-gray-700 font-medium mb-1">Work End Time <span class="text-red-500">*</span></label>
                                <input type="time" id="work_schedule_end" name="work_schedule_end" 
                                       value="<?php echo htmlspecialchars($form_data['work_schedule_end']); ?>" required
                                       class="w-full px-4 py-2 border-2 border-gray-200 rounded-lg focus:outline-none focus:border-bulsu-gold transition">
                            </div>
                        </div>
                        <div class="mb-4">
                            <label class="block text-gray-700 font-medium mb-2">Work Days <span class="text-red-500">*</span></label>
                            <div class="grid grid-cols-2 md:grid-cols-4 lg:grid-cols-7 gap-2">
                                <?php
                                $days = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];
                                foreach ($days as $day): ?>
                                <div class="flex items-center">
                                    <input type="checkbox" id="<?php echo strtolower($day); ?>" name="work_days[]" value="<?php echo $day; ?>" 
                                           <?php echo in_array($day, $form_data['work_days']) ? 'checked' : ''; ?>
                                           class="mr-2 rounded border-gray-300 text-bulsu-maroon focus:ring-bulsu-gold focus:ring-2">
                                    <label for="<?php echo strtolower($day); ?>" class="text-sm font-medium text-gray-700 cursor-pointer"><?php echo substr($day, 0, 3); ?></label>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <div>
                                <label for="internship_start_date" class="block text-gray-700 font-medium mb-1">Internship Start Date <span class="text-red-500">*</span></label>
                                <input type="date" id="internship_start_date" name="internship_start_date" 
                                       value="<?php echo htmlspecialchars($form_data['internship_start_date']); ?>" 
                                       min="<?php echo date('Y-m-d'); ?>" required
                                       class="w-full px-4 py-2 border-2 border-gray-200 rounded-lg focus:outline-none focus:border-bulsu-gold transition">
                            </div>
                            <div>
                                <label for="internship_end_date" class="block text-gray-700 font-medium mb-1">Internship End Date <span class="text-red-500">*</span></label>
                                <input type="date" id="internship_end_date" name="internship_end_date" 
                                       value="<?php echo htmlspecialchars($form_data['internship_end_date']); ?>" 
                                       min="<?php echo date('Y-m-d'); ?>" required
                                       class="w-full px-4 py-2 border-2 border-gray-200 rounded-lg focus:outline-none focus:border-bulsu-gold transition">
                            </div>
                        </div>
                    </div>

                    <!-- Account Security Section -->
                    <div class="border-b border-gray-200 pb-6">
                        <h3 class="text-bulsu-maroon font-semibold mb-4 flex items-center gap-2">
                            <span class="text-lg">🔐</span> Account Security
                        </h3>
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <div>
                                <label for="password" class="block text-gray-700 font-medium mb-1">Password <span class="text-red-500">*</span></label>
                                <input type="password" id="password" name="password" placeholder="Create a strong password" required
                                       minlength="8" 
                                       class="w-full px-4 py-2 border-2 border-gray-200 rounded-lg focus:outline-none focus:border-bulsu-gold transition">
                                <small class="text-gray-400 text-xs">Must be at least 8 characters long</small>
                            </div>
                            <div>
                                <label for="confirm_password" class="block text-gray-700 font-medium mb-1">Confirm Password <span class="text-red-500">*</span></label>
                                <input type="password" id="confirm_password" name="confirm_password" placeholder="Confirm your password" required
                                       minlength="8" 
                                       class="w-full px-4 py-2 border-2 border-gray-200 rounded-lg focus:outline-none focus:border-bulsu-gold transition">
                            </div>
                        </div>
                    </div>

                    <!-- Terms and Conditions -->
                    <div class="flex items-center mb-6">
                        <input type="checkbox" id="agree_terms" name="agree_terms" required 
                               class="mr-2 rounded border-gray-300 text-bulsu-maroon focus:ring-bulsu-gold focus:ring-2">
                        <label for="agree_terms" class="text-gray-700">I agree to the <a href="#" class="text-bulsu-gold hover:underline">Terms and Conditions</a> and <a href="#" class="text-bulsu-gold hover:underline">Privacy Policy</a> <span class="text-red-500">*</span></label>
                    </div>

                    <button type="submit"
                        class="w-full bg-gradient-to-r from-bulsu-maroon to-bulsu-dark-maroon text-white rounded-lg py-3 font-semibold shadow hover:from-bulsu-dark-maroon hover:to-black transition transform hover:scale-105">
                        Register as Company Supervisor
                    </button>

                    <div class="text-center mt-6 text-gray-600 pt-4 border-t border-gray-200">
                        Already have an account?
                        <a href="login.php" class="text-bulsu-gold font-semibold hover:text-bulsu-maroon transition">Login here</a>
                    </div>
                </form>
            </div>
        </section>
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

        // Phone number validation
        function validatePhoneNumber(input) {
            const phoneRegex = /^09\d{9}$/;
            const value = input.value.replace(/[^0-9]/g, '');
            
            if (value.length === 11 && phoneRegex.test(value)) {
                input.classList.remove('border-red-300');
                input.classList.add('border-bulsu-gold');
                return true;
            } else if (value.length > 0) {
                input.classList.remove('border-bulsu-gold');
                input.classList.add('border-red-300');
                return false;
            } else {
                input.classList.remove('border-red-300', 'border-bulsu-gold');
                input.classList.add('border-gray-200');
                return null;
            }
        }

        // Enhanced phone number validation
        document.getElementById('contact_number').addEventListener('input', function(e) {
            let value = e.target.value.replace(/[^0-9]/g, '');
            if (value.length > 11) value = value.substring(0, 11);
            e.target.value = value;
            validatePhoneNumber(e.target);
        });

        document.getElementById('company_contact').addEventListener('input', function(e) {
            let value = e.target.value.replace(/[^0-9]/g, '');
            if (value.length > 11) value = value.substring(0, 11);
            e.target.value = value;
            validatePhoneNumber(e.target);
        });

        // Prevent non-numeric input on keypress
        ['contact_number', 'company_contact'].forEach(id => {
            document.getElementById(id).addEventListener('keypress', function(e) {
                if ((e.shiftKey || (e.keyCode < 48 || e.keyCode > 57)) && (e.keyCode < 96 || e.keyCode > 105)) {
                    e.preventDefault();
                }
                if (this.value.length >= 11) e.preventDefault();
            });
        });

        // Password confirmation validation
        document.getElementById('confirm_password').addEventListener('input', function() {
            const password = document.getElementById('password').value;
            const confirmPassword = this.value;
            
            if (confirmPassword && password !== confirmPassword) {
                this.setCustomValidity('Passwords do not match');
                this.classList.remove('border-bulsu-gold');
                this.classList.add('border-red-300');
            } else {
                this.setCustomValidity('');
                this.classList.remove('border-red-300');
                if (confirmPassword) {
                    this.classList.add('border-bulsu-gold');
                } else {
                    this.classList.remove('border-bulsu-gold');
                    this.classList.add('border-gray-200');
                }
            }
        });

        document.getElementById('password').addEventListener('input', function() {
            const confirmPassword = document.getElementById('confirm_password');
            if (confirmPassword.value) {
                confirmPassword.dispatchEvent(new Event('input'));
            }
        });

        // Date validation
        document.getElementById('internship_start_date').addEventListener('change', function() {
            const startDate = new Date(this.value);
            const endDateInput = document.getElementById('internship_end_date');
            const endDate = new Date(endDateInput.value);
            
            if (endDateInput.value && startDate >= endDate) {
                endDateInput.value = '';
                alert('End date must be after start date');
            }
            
            // Set minimum end date to start date
            endDateInput.min = this.value;
        });

        document.getElementById('internship_end_date').addEventListener('change', function() {
            const startDate = new Date(document.getElementById('internship_start_date').value);
            const endDate = new Date(this.value);
            
            if (startDate && endDate <= startDate) {
                this.value = '';
                alert('End date must be after start date');
            }
        });

        // Time validation
        document.getElementById('work_schedule_start').addEventListener('change', function() {
            const endTimeInput = document.getElementById('work_schedule_end');
            if (endTimeInput.value && this.value >= endTimeInput.value) {
                endTimeInput.value = '';
                alert('End time must be after start time');
            }
        });

        document.getElementById('work_schedule_end').addEventListener('change', function() {
            const startTime = document.getElementById('work_schedule_start').value;
            if (startTime && this.value <= startTime) {
                this.value = '';
                alert('End time must be after start time');
            }
        });

        // Form submission validation
        document.getElementById('supervisorSignupForm').addEventListener('submit', function(e) {
            const workDays = document.querySelectorAll('input[name="work_days[]"]:checked');
            if (workDays.length === 0) {
                e.preventDefault();
                alert('Please select at least one work day');
                return false;
            }

            const password = document.getElementById('password').value;
            const confirmPassword = document.getElementById('confirm_password').value;
            
            if (password !== confirmPassword) {
                e.preventDefault();
                alert('Passwords do not match');
                return false;
            }

            if (password.length < 8) {
                e.preventDefault();
                alert('Password must be at least 8 characters long');
                return false;
            }

            // Validate phone numbers
            const contactNumber = document.getElementById('contact_number').value;
            const companyContact = document.getElementById('company_contact').value;
            const phoneRegex = /^09\d{9}$/;
            
            if (!phoneRegex.test(contactNumber)) {
                e.preventDefault();
                alert('Personal contact number must be exactly 11 digits starting with 09');
                return false;
            }
            
            if (!phoneRegex.test(companyContact)) {
                e.preventDefault();
                alert('Company contact number must be exactly 11 digits starting with 09');
                return false;
            }

            if (!document.getElementById('agree_terms').checked) {
                e.preventDefault();
                alert('You must agree to the Terms and Conditions!');
                return false;
            }
        });
    </script>
</body>
</html>