<?php
include('connect.php');
session_start();

// ADD THESE 3 LINES:
header("Cache-Control: no-cache, no-store, must-revalidate"); // HTTP 1.1.
header("Pragma: no-cache"); // HTTP 1.0.
header("Expires: 0"); // Proxies.

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

$user_id = $_SESSION['user_id'];
include_once('notification_functions.php');
$unread_count = getUnreadNotificationCount($conn, $user_id);

$success_message = '';
$error_message = '';

// Fetch current student data
try {
    $stmt = $conn->prepare("SELECT * FROM students WHERE id = ?");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows > 0) {
        $student = $result->fetch_assoc();
        
        // Build full name for display
        $full_name = $student['first_name'];
        if (!empty($student['middle_name'])) {
            $full_name .= ' ' . $student['middle_name'];
        }
        $full_name .= ' ' . $student['last_name'];
        
        $first_name = $student['first_name'];
        $middle_name = $student['middle_name'];
        $last_name = $student['last_name'];
        $email = $student['email'];
        $student_id = $student['student_id'];
        $department = $student['department'];
        $program = $student['program'];
        $year_level = $student['year_level'];
        $profile_picture = $student['profile_picture'];
        $is_verified = $student['verified']; // Get verification status
        
        // Create initials for avatar
        $initials = strtoupper(substr($student['first_name'], 0, 1) . substr($student['last_name'], 0, 1));
    } else {
        header("Location: login.php");
        exit();
    }
} catch (Exception $e) {
    $error_message = "Error fetching user data: " . $e->getMessage();
}

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'update_profile_picture') {
        // Handle profile picture upload
        if (isset($_FILES['profile_picture']) && $_FILES['profile_picture']['error'] === UPLOAD_ERR_OK) {
            $upload_dir = 'uploads/profile_pictures/';
            
            // Create directory if it doesn't exist
            if (!is_dir($upload_dir)) {
                mkdir($upload_dir, 0777, true);
            }
            
            $file_info = pathinfo($_FILES['profile_picture']['name']);
            $allowed_extensions = ['jpg', 'jpeg', 'png', 'gif'];
            $file_extension = strtolower($file_info['extension']);
            
            // Validate file type
            if (!in_array($file_extension, $allowed_extensions)) {
                $error_message = "Only JPG, JPEG, PNG, and GIF files are allowed.";
            } else {
                // Validate file size (max 5MB)
                if ($_FILES['profile_picture']['size'] > 5 * 1024 * 1024) {
                    $error_message = "File size must be less than 5MB.";
                } else {
                    $new_filename = 'profile_' . $user_id . '_' . time() . '.' . $file_extension;
                    $upload_path = $upload_dir . $new_filename;
                    
                    if (move_uploaded_file($_FILES['profile_picture']['tmp_name'], $upload_path)) {
                        // Delete old profile picture if exists
                        if (!empty($profile_picture) && file_exists($profile_picture)) {
                            unlink($profile_picture);
                        }
                        
                        // Update database
                        try {
                            $stmt = $conn->prepare("UPDATE students SET profile_picture = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?");
                            $stmt->bind_param("si", $upload_path, $user_id);
                            
                            if ($stmt->execute()) {
                                $success_message = "Profile picture updated successfully!";
                                $profile_picture = $upload_path;
                                $student['profile_picture'] = $upload_path;
                            } else {
                                $error_message = "Error updating profile picture in database.";
                            }
                            $stmt->close();
                        } catch (Exception $e) {
                            $error_message = "Database error: " . $e->getMessage();
                        }
                    } else {
                        $error_message = "Error uploading file.";
                    }
                }
            }
        } else {
            $error_message = "Please select a valid image file.";
        }
    }
    
    elseif ($action === 'remove_profile_picture') {
        // Remove profile picture
        if (!empty($profile_picture) && file_exists($profile_picture)) {
            unlink($profile_picture);
        }
        
        try {
            $stmt = $conn->prepare("UPDATE students SET profile_picture = NULL, updated_at = CURRENT_TIMESTAMP WHERE id = ?");
            $stmt->bind_param("i", $user_id);
            
            if ($stmt->execute()) {
                $success_message = "Profile picture removed successfully!";
                $profile_picture = '';
                $student['profile_picture'] = '';
            } else {
                $error_message = "Error removing profile picture.";
            }
            $stmt->close();
        } catch (Exception $e) {
            $error_message = "Database error: " . $e->getMessage();
        }
    }
    
    elseif ($action === 'update_personal') {
        // Update personal information
        $first_name = trim($_POST['first_name']);
        $middle_name = trim($_POST['middle_name']);
        $last_name = trim($_POST['last_name']);
        $gender = $_POST['gender'];
        $date_of_birth = $_POST['date_of_birth'];
        $contact_number = trim($_POST['contact_number']);
        $address = trim($_POST['address']);
        
        // Validation
        if (empty($first_name) || empty($last_name) || empty($gender) || empty($date_of_birth) || empty($contact_number) || empty($address)) {
            $error_message = "Please fill in all required fields.";
        } else {
            try {
                $stmt = $conn->prepare("UPDATE students SET first_name = ?, middle_name = ?, last_name = ?, gender = ?, date_of_birth = ?, contact_number = ?, address = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?");
                $stmt->bind_param("sssssssi", $first_name, $middle_name, $last_name, $gender, $date_of_birth, $contact_number, $address, $user_id);
                
                if ($stmt->execute()) {
                    $success_message = "Personal information updated successfully!";
                    // Refresh student data
                    $student['first_name'] = $first_name;
                    $student['middle_name'] = $middle_name;
                    $student['last_name'] = $last_name;
                    $student['gender'] = $gender;
                    $student['date_of_birth'] = $date_of_birth;
                    $student['contact_number'] = $contact_number;
                    $student['address'] = $address;
                    
                    // Update full name and initials
                    $full_name = $first_name;
                    if (!empty($middle_name)) {
                        $full_name .= ' ' . $middle_name;
                    }
                    $full_name .= ' ' . $last_name;
                    $initials = strtoupper(substr($first_name, 0, 1) . substr($last_name, 0, 1));
                } else {
                    $error_message = "Error updating personal information.";
                }
                $stmt->close();
            } catch (Exception $e) {
                $error_message = "Database error: " . $e->getMessage();
            }
        }
    }
    
    elseif ($action === 'update_email') {
        // Update email
        $new_email = trim($_POST['new_email']);
        $current_password = $_POST['current_password_email'];
        
        // Validation
        if (empty($new_email) || empty($current_password)) {
            $error_message = "Please fill in all fields.";
        } elseif (!filter_var($new_email, FILTER_VALIDATE_EMAIL)) {
            $error_message = "Please enter a valid email address.";
        } elseif ($new_email === $student['email']) {
            $error_message = "New email must be different from current email.";
        } else {
            // Verify current password
            if (password_verify($current_password, $student['password'])) {
                // Check if email already exists
                $check_stmt = $conn->prepare("SELECT id FROM students WHERE email = ? AND id != ?");
                $check_stmt->bind_param("si", $new_email, $user_id);
                $check_stmt->execute();
                $check_result = $check_stmt->get_result();
                
                if ($check_result->num_rows > 0) {
                    $error_message = "This email address is already in use.";
                } else {
                    try {
                        $stmt = $conn->prepare("UPDATE students SET email = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?");
                        $stmt->bind_param("si", $new_email, $user_id);
                        
                        if ($stmt->execute()) {
                            $success_message = "Email address updated successfully!";
                            $student['email'] = $new_email;
                        } else {
                            $error_message = "Error updating email address.";
                        }
                        $stmt->close();
                    } catch (Exception $e) {
                        $error_message = "Database error: " . $e->getMessage();
                    }
                }
                $check_stmt->close();
            } else {
                $error_message = "Current password is incorrect.";
            }
        }
    }
    
    elseif ($action === 'update_password') {
        // Update password
        $current_password = $_POST['current_password'];
        $new_password = $_POST['new_password'];
        $confirm_password = $_POST['confirm_password'];
        
        // Validation
        if (empty($current_password) || empty($new_password) || empty($confirm_password)) {
            $error_message = "Please fill in all password fields.";
        } elseif (strlen($new_password) < 8) {
            $error_message = "New password must be at least 8 characters long.";
        } elseif ($new_password !== $confirm_password) {
            $error_message = "New password and confirm password do not match.";
        } elseif ($current_password === $new_password) {
            $error_message = "New password must be different from current password.";
        } else {
            // Verify current password
            if (password_verify($current_password, $student['password'])) {
                try {
                    $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
                    $stmt = $conn->prepare("UPDATE students SET password = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?");
                    $stmt->bind_param("si", $hashed_password, $user_id);
                    
                    if ($stmt->execute()) {
                        $success_message = "Password updated successfully!";
                    } else {
                        $error_message = "Error updating password.";
                    }
                    $stmt->close();
                } catch (Exception $e) {
                    $error_message = "Database error: " . $e->getMessage();
                }
            } else {
                $error_message = "Current password is incorrect.";
            }
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>OnTheJob Tracker - Account Settings</title>
    <link rel="icon" type="image/png" href="reqsample/bulsu12.png">
    <link rel="shortcut icon" type="image/png" href="reqsample/bulsu12.png">
    <meta http-equiv="Cache-Control" content="no-cache, no-store, must-revalidate">
    <meta http-equiv="Pragma" content="no-cache">
    <meta http-equiv="Expires" content="0">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
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
        /* Custom CSS for features not easily achievable with Tailwind */
        .nav-item {
            position: relative;
        }

        .notification-badge {
            position: absolute;
            top: -5px;
            right: -5px;
            background: linear-gradient(135deg, #ff4757, #ff3742);
            color: white;
            border-radius: 50%;
            font-size: 0.7rem;
            font-weight: 700;
            padding: 2px 6px;
            min-width: 18px;
            height: 18px;
            display: flex;
            align-items: center;
            justify-content: center;
            box-shadow: 0 2px 8px rgba(255, 71, 87, 0.4);
            animation: pulse-badge 2s infinite;
            z-index: 10;
        }

        @keyframes pulse-badge {
            0% { transform: scale(1); }
            50% { transform: scale(1.1); }
            100% { transform: scale(1); }
        }

        .notification-badge.zero {
            display: none;
        }

        .sidebar {
            transition: transform 0.3s ease-in-out;
            width: 280px;
        }

        .sidebar-overlay {
            transition: opacity 0.3s ease-in-out;
        }

        .loading i {
            animation: spin 1s linear infinite;
        }

        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }

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

        /* Custom styles for form elements */
        .password-toggle {
            background: none;
            border: none;
            color: #6b7280;
            cursor: pointer;
            padding: 8px;
            position: absolute;
            right: 8px;
            top: 50%;
            transform: translateY(-50%);
        }

        .password-toggle:hover {
            color: #374151;
        }

        .strength-bar {
            height: 4px;
            background: #e5e7eb;
            border-radius: 2px;
            margin-top: 4px;
            overflow: hidden;
        }

        .strength-fill {
            height: 100%;
            border-radius: 2px;
            transition: all 0.3s ease;
        }

        .strength-weak { background: #ef4444; width: 25%; }
        .strength-fair { background: #f59e0b; width: 50%; }
        .strength-good { background: #f97316; width: 75%; }
        .strength-strong { background: #10b981; width: 100%; }
    </style>
</head>
<body class="bg-gray-100 text-gray-800">
    <!-- Mobile Sidebar Overlay -->
    <div id="sidebarOverlay" class="fixed inset-0 bg-black bg-opacity-50 z-40 lg:hidden hidden sidebar-overlay"></div>

    <!-- Sidebar -->
    <div id="sidebar" class="fixed left-0 top-0 h-full w-64 bg-gradient-to-b from-bulsu-maroon to-bulsu-dark-maroon shadow-lg z-50 transform -translate-x-full lg:translate-x-0 transition-transform duration-300 ease-in-out sidebar">
        <!-- Close button for mobile -->
        <div class="flex justify-end p-4 lg:hidden">
            <button id="closeSidebar" class="text-bulsu-light-gold hover:text-bulsu-gold">
                <i class="fas fa-times text-xl"></i>
            </button>
        </div>

        <!-- Logo Section with BULSU Branding -->
        <div class="px-6 py-4 border-b border-bulsu-gold border-opacity-30">
            <div class="flex items-center">
                <!-- BULSU Logos -->
                 <img src="reqsample/bulsu12.png" alt="BULSU Logo 2" class="w-14 h-14 mr-2">
                <!-- Brand Name -->
                <div class="flex items-center font-bold text-lg text-white">
                    <span>OnTheJob</span>
                    <span class="ml-1">Tracker</span>
                </div>
            </div>
        </div>
        
        <!-- Navigation -->
        <div class="px-4 py-6">
            <h2 class="text-xs font-semibold text-bulsu-light-gold uppercase tracking-wide mb-4">Navigation</h2>
            <nav class="space-y-2">
                <a href="studentdashboard.php" class="nav-item flex items-center px-3 py-2 text-sm font-medium text-bulsu-light-gold hover:text-white hover:bg-bulsu-gold hover:bg-opacity-20 rounded-md transition-all duration-200">
                    <i class="fas fa-th-large mr-3"></i>
                    Dashboard
                </a>
                <a href="studentAttendance.php" class="nav-item flex items-center px-3 py-2 text-sm font-medium text-bulsu-light-gold hover:text-white hover:bg-bulsu-gold hover:bg-opacity-20 rounded-md transition-all duration-200">
                    <i class="fas fa-calendar-check mr-3"></i>
                    Attendance
                </a>
                <a href="studentTask.php" class="nav-item flex items-center px-3 py-2 text-sm font-medium text-bulsu-light-gold hover:text-white hover:bg-bulsu-gold hover:bg-opacity-20 rounded-md transition-all duration-200">
                    <i class="fas fa-tasks mr-3"></i>
                    Tasks
                </a>
                <a href="studentReport.php" class="nav-item flex items-center px-3 py-2 text-sm font-medium text-bulsu-light-gold hover:text-white hover:bg-bulsu-gold hover:bg-opacity-20 rounded-md transition-all duration-200">
                    <i class="fas fa-book mr-3"></i>
                    Report
                </a>
                <a href="studentEvaluation.php" class="nav-item flex items-center px-3 py-2 text-sm font-medium text-bulsu-light-gold hover:text-white hover:bg-bulsu-gold hover:bg-opacity-20 rounded-md transition-all duration-200">
                    <i class="fas fa-star mr-3"></i>
                    Evaluation
                </a>
                <a href="studentSelf-Assessment.php" class="nav-item flex items-center px-3 py-2 text-sm font-medium text-bulsu-light-gold hover:text-white hover:bg-bulsu-gold hover:bg-opacity-20 rounded-md transition-all duration-200">
                    <i class="fas fa-star mr-3"></i>
                    Self-Assessment
                </a>
                <a href="studentMessage.php" class="nav-item flex items-center px-3 py-2 text-sm font-medium text-bulsu-light-gold hover:text-white hover:bg-bulsu-gold hover:bg-opacity-20 rounded-md transition-all duration-200">
                    <i class="fas fa-envelope mr-3"></i>
                    Message
                </a>
                <a href="notifications.php" class="nav-item flex items-center px-3 py-2 text-sm font-medium text-bulsu-light-gold hover:text-white hover:bg-bulsu-gold hover:bg-opacity-20 rounded-md transition-all duration-200">
                    <i class="fas fa-bell mr-3"></i>
                    Notifications
                    <?php if ($unread_count > 0): ?>
                        <span class="notification-badge" id="sidebar-notification-badge">
                            <?php echo $unread_count; ?>
                        </span>
                    <?php endif; ?>
                </a>
            </nav>
        </div>
        
        <!-- User Profile -->
        <div class="absolute bottom-0 left-0 right-0 p-4 border-t border-bulsu-gold border-opacity-30 bg-gradient-to-t from-black to-transparent">
            <div class="flex items-center space-x-3">
                <div class="flex-shrink-0 w-10 h-10 bg-gradient-to-r from-bulsu-gold to-yellow-400 rounded-full flex items-center justify-center text-bulsu-maroon font-semibold text-sm">
                    <?php if (!empty($profile_picture) && file_exists($profile_picture)): ?>
                        <img src="<?php echo htmlspecialchars($profile_picture); ?>" alt="Profile Picture" class="w-full h-full rounded-full object-cover">
                    <?php else: ?>
                        <?php echo $initials; ?>
                    <?php endif; ?>
                </div>
                <div class="flex-1 min-w-0">
                    <p class="text-sm font-medium text-white truncate"><?php echo htmlspecialchars($full_name); ?></p>
                    <p class="text-xs text-bulsu-light-gold">BULSU Trainee</p>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Main Content -->
    <div class="lg:ml-64 min-h-screen">
        <!-- Header -->
        <div class="bg-white shadow-sm border-b border-gray-200">
            <div class="flex items-center justify-between px-4 sm:px-6 py-4">
                <!-- Mobile Menu Button -->
                <button id="mobileMenuBtn" class="lg:hidden p-2 rounded-md text-gray-500 hover:text-gray-900 hover:bg-gray-100">
                    <i class="fas fa-bars text-xl"></i>
                </button>

                <div class="flex-1 lg:ml-0 ml-4">
                    <h1 class="text-xl sm:text-2xl font-bold text-gray-900">Account Settings</h1>
                    <p class="text-sm sm:text-base text-gray-500 hidden sm:block">Manage your personal information and account security</p>
                </div>
                
                <!-- Profile Dropdown -->
                <div class="relative">
                    <button id="profileBtn" class="flex items-center p-1 rounded-full hover:bg-bulsu-gold hover:bg-opacity-20">
                        <div class="w-8 h-8 sm:w-10 sm:h-10 bg-gradient-to-r from-bulsu-gold to-yellow-400 rounded-full flex items-center justify-center text-bulsu-maroon font-semibold text-xs sm:text-sm">
                            <?php if (!empty($profile_picture) && file_exists($profile_picture)): ?>
                                <img src="<?php echo htmlspecialchars($profile_picture); ?>" alt="Profile Picture" class="w-full h-full rounded-full object-cover">
                            <?php else: ?>
                                <?php echo $initials; ?>
                            <?php endif; ?>
                        </div>
                    </button>
                    <div id="profileDropdown" class="hidden absolute right-0 mt-2 w-48 sm:w-64 bg-white rounded-md shadow-lg border border-gray-200 z-50">
                        <div class="p-4 border-b border-gray-200">
                            <div class="flex items-center space-x-3">
                                <div class="w-12 h-12 bg-gradient-to-r from-bulsu-gold to-yellow-400 rounded-full flex items-center justify-center text-bulsu-maroon font-semibold">
                                    <?php if (!empty($profile_picture) && file_exists($profile_picture)): ?>
                                        <img src="<?php echo htmlspecialchars($profile_picture); ?>" alt="Profile Picture" class="w-full h-full rounded-full object-cover">
                                    <?php else: ?>
                                        <?php echo $initials; ?>
                                    <?php endif; ?>
                                </div>
                                <div>
                                    <p class="font-medium text-gray-900"><?php echo htmlspecialchars($full_name); ?></p>
                                    <p class="text-sm text-gray-500"><?php echo htmlspecialchars($student_id); ?> • <?php echo htmlspecialchars($program); ?></p>
                                </div>
                            </div>
                        </div>
                        <a href="studentAccount-settings.php" class="flex items-center px-4 py-2 text-sm text-gray-700 hover:bg-gray-50 bg-bulsu-light-gold bg-opacity-20">
                            <i class="fas fa-cog mr-3"></i>
                            Account Settings
                        </a>
                        <div class="border-t border-gray-200"></div>
                        <a href="logout.php" class="flex items-center px-4 py-2 text-sm text-gray-700 hover:bg-gray-50">
                            <i class="fas fa-sign-out-alt mr-3"></i>
                            Logout
                        </a>
                    </div>
                </div>
            </div>
        </div>

        <!-- Main Container -->
        <div class="p-4 sm:p-6 lg:p-8">
            <!-- Welcome Section -->
            <div class="mb-6 sm:mb-8">
                
            </div>

            <!-- Profile Summary -->
            <div class="bg-gradient-to-r from-bulsu-maroon to-bulsu-dark-maroon text-white rounded-lg p-6 mb-6 sm:mb-8">
                <div class="flex items-center space-x-4">
                    <div class="w-16 h-16 bg-white bg-opacity-20 rounded-full flex items-center justify-center text-2xl font-bold overflow-hidden">
                        <?php if (!empty($profile_picture) && file_exists($profile_picture)): ?>
                            <img src="<?php echo htmlspecialchars($profile_picture); ?>" alt="Profile Picture" class="w-full h-full object-cover">
                        <?php else: ?>
                            <?php echo $initials; ?>
                        <?php endif; ?>
                    </div>
                    <div>
                        <h2 class="text-xl font-bold"><?php echo htmlspecialchars($full_name); ?></h2>
                        <p class="text-bulsu-light-gold"><?php echo htmlspecialchars($student_id); ?> • <?php echo htmlspecialchars($program); ?></p>
                        <p class="text-bulsu-light-gold text-sm"><?php echo htmlspecialchars($email); ?></p>
                    </div>
                </div>
            </div>

            <!-- Success/Error Messages -->
            <?php if (!empty($success_message)): ?>
                <div class="mb-6 p-4 bg-green-50 border border-green-200 rounded-lg">
                    <div class="flex items-start">
                        <i class="fas fa-check-circle text-green-600 mt-1 mr-3"></i>
                        <p class="text-green-700"><?php echo htmlspecialchars($success_message); ?></p>
                    </div>
                </div>
            <?php endif; ?>

            <?php if (!empty($error_message)): ?>
                <div class="mb-6 p-4 bg-red-50 border border-red-200 rounded-lg">
                    <div class="flex items-start">
                        <i class="fas fa-exclamation-circle text-red-600 mt-1 mr-3"></i>
                        <p class="text-red-700"><?php echo htmlspecialchars($error_message); ?></p>
                    </div>
                </div>
            <?php endif; ?>

            <div class="grid gap-6 lg:gap-8">
                <!-- Profile Picture Section -->
                <div class="bg-white rounded-lg shadow-sm border border-gray-200">
                    <div class="p-6 border-b border-gray-200">
                        <div class="flex items-center">
                            <div class="w-12 h-12 bg-gradient-to-r from-green-500 to-green-400 rounded-lg flex items-center justify-center text-white mr-4">
                                <i class="fas fa-camera text-xl"></i>
                            </div>
                            <div>
                                <h3 class="text-lg font-medium text-bulsu-maroon">Profile Picture</h3>
                                <p class="text-sm text-gray-600">Upload or change your profile picture</p>
                            </div>
                        </div>
                    </div>

                    <div class="p-6">
                        <form method="POST" enctype="multipart/form-data" id="profilePictureForm">
                            <input type="hidden" name="action" value="update_profile_picture">
                            
                            <div class="flex items-center space-x-6 mb-6">
                                <div class="w-24 h-24 bg-gradient-to-r from-bulsu-gold to-yellow-400 rounded-full flex items-center justify-center text-bulsu-maroon font-bold text-2xl overflow-hidden">
                                    <?php if (!empty($profile_picture) && file_exists($profile_picture)): ?>
                                        <img src="<?php echo htmlspecialchars($profile_picture); ?>" alt="Current Profile Picture" class="w-full h-full object-cover">
                                    <?php else: ?>
                                        <?php echo $initials; ?>
                                    <?php endif; ?>
                                </div>
                                
                                <div class="flex flex-col space-y-2">
                                    <div class="relative">
                                        <input type="file" id="profile_picture" name="profile_picture" accept="image/*" class="hidden" onchange="previewImage(this)">
                                        <label for="profile_picture" class="inline-flex items-center px-4 py-2 bg-gradient-to-r from-green-500 to-green-400 text-white font-medium rounded-md cursor-pointer hover:from-green-600 hover:to-green-500 transition-colors">
                                            <i class="fas fa-upload mr-2"></i>
                                            Choose Photo
                                        </label>
                                    </div>
                                    <p class="text-xs text-gray-500">Max size: 5MB • Formats: JPG, PNG, GIF</p>
                                    
                                    <?php if (!empty($profile_picture)): ?>
                                        <button type="submit" form="removePictureForm" class="inline-flex items-center px-4 py-2 bg-red-500 text-white font-medium rounded-md hover:bg-red-600 transition-colors">
                                            <i class="fas fa-trash mr-2"></i>
                                            Remove Picture
                                        </button>
                                    <?php endif; ?>
                                </div>
                            </div>
                            
                            <div class="flex justify-end">
                                <button type="submit" class="inline-flex items-center px-4 py-2 bg-bulsu-maroon text-white font-medium rounded-md hover:bg-bulsu-dark-maroon transition-colors">
                                    <i class="fas fa-save mr-2"></i>
                                    Update Picture
                                </button>
                            </div>
                        </form>

                        <!-- Remove picture form -->
                        <?php if (!empty($profile_picture)): ?>
                            <form method="POST" id="removePictureForm" style="display: none;">
                                <input type="hidden" name="action" value="remove_profile_picture">
                            </form>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Personal Information Section -->
                <div class="bg-white rounded-lg shadow-sm border border-gray-200">
                    <div class="p-6 border-b border-gray-200">
                        <div class="flex items-center">
                            <div class="w-12 h-12 bg-gradient-to-r from-blue-500 to-purple-600 rounded-lg flex items-center justify-center text-white mr-4">
                                <i class="fas fa-user text-xl"></i>
                            </div>
                            <div>
                                <h3 class="text-lg font-medium text-bulsu-maroon">Personal Information</h3>
                                <p class="text-sm text-gray-600">Update your personal details</p>
                            </div>
                        </div>
                    </div>

                    <div class="p-6">
                        <!-- Read-only Academic Info -->
                        <div class="bg-gray-50 border border-gray-200 rounded-lg p-4 mb-6">
                            <h4 class="flex items-center text-base font-medium text-bulsu-maroon mb-3">
                                <i class="fas fa-graduation-cap mr-2"></i>
                                Academic Information
                            </h4>
                            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
                                <div>
                                    <div class="text-xs text-gray-600 mb-1">Student ID</div>
                                    <div class="text-sm font-medium text-gray-900"><?php echo htmlspecialchars($student['student_id']); ?></div>
                                </div>
                                <div>
                                    <div class="text-xs text-gray-600 mb-1">Program</div>
                                    <div class="text-sm font-medium text-gray-900"><?php echo htmlspecialchars($student['program']); ?></div>
                                </div>
                                <div>
                                    <div class="text-xs text-gray-600 mb-1">Year Level</div>
                                    <div class="text-sm font-medium text-gray-900"><?php echo htmlspecialchars($student['year_level']); ?></div>
                                </div>
                                <div>
                                    <div class="text-xs text-gray-600 mb-1">Section</div>
                                    <div class="text-sm font-medium text-gray-900"><?php echo htmlspecialchars($student['section']); ?></div>
                                </div>
                            </div>
                        </div>

                        <form method="POST" id="personalInfoForm">
                            <input type="hidden" name="action" value="update_personal">
                            
                            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4 mb-6">
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-2" for="first_name">
                                        First Name <span class="text-red-500">*</span>
                                    </label>
                                    <input type="text" id="first_name" name="first_name" 
                                           class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-bulsu-gold focus:border-transparent" 
                                           value="<?php echo htmlspecialchars($student['first_name']); ?>" required>
                                </div>
                                
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-2" for="middle_name">
                                        Middle Name
                                    </label>
                                    <input type="text" id="middle_name" name="middle_name" 
                                           class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-bulsu-gold focus:border-transparent" 
                                           value="<?php echo htmlspecialchars($student['middle_name']); ?>">
                                </div>
                                
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-2" for="last_name">
                                        Last Name <span class="text-red-500">*</span>
                                    </label>
                                    <input type="text" id="last_name" name="last_name" 
                                           class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-bulsu-gold focus:border-transparent" 
                                           value="<?php echo htmlspecialchars($student['last_name']); ?>" required>
                                </div>
                                
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-2" for="gender">
                                        Gender <span class="text-red-500">*</span>
                                    </label>
                                    <select id="gender" name="gender" 
                                            class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-bulsu-gold focus:border-transparent" required>
                                        <option value="">Select Gender</option>
                                        <option value="Male" <?php echo ($student['gender'] === 'Male') ? 'selected' : ''; ?>>Male</option>
                                        <option value="Female" <?php echo ($student['gender'] === 'Female') ? 'selected' : ''; ?>>Female</option>
                                        <option value="Other" <?php echo ($student['gender'] === 'Other') ? 'selected' : ''; ?>>Other</option>
                                    </select>
                                </div>
                                
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-2" for="date_of_birth">
                                        Date of Birth <span class="text-red-500">*</span>
                                    </label>
                                    <input type="date" id="date_of_birth" name="date_of_birth" 
                                           class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-bulsu-gold focus:border-transparent" 
                                           value="<?php echo htmlspecialchars($student['date_of_birth']); ?>" required>
                                </div>
                                
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-2" for="contact_number">
                                        Contact Number <span class="text-red-500">*</span>
                                    </label>
                                    <input type="tel" id="contact_number" name="contact_number" 
                                           class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-bulsu-gold focus:border-transparent" 
                                           value="<?php echo htmlspecialchars($student['contact_number']); ?>" required>
                                </div>
                            </div>
                            
                            <div class="mb-6">
                                <label class="block text-sm font-medium text-gray-700 mb-2" for="address">
                                    Address <span class="text-red-500">*</span>
                                </label>
                                <textarea id="address" name="address" rows="3"
                                          class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-bulsu-gold focus:border-transparent" 
                                          required><?php echo htmlspecialchars($student['address']); ?></textarea>
                            </div>
                            
                            <div class="flex justify-end space-x-3">
                                <button type="button" onclick="resetPersonalForm()" 
                                        class="inline-flex items-center px-4 py-2 bg-gray-300 text-gray-700 font-medium rounded-md hover:bg-gray-400 transition-colors">
                                    <i class="fas fa-undo mr-2"></i>
                                    Reset
                                </button>
                                <button type="submit" 
                                        class="inline-flex items-center px-4 py-2 bg-bulsu-maroon text-white font-medium rounded-md hover:bg-bulsu-dark-maroon transition-colors">
                                    <i class="fas fa-save mr-2"></i>
                                    Save Changes
                                </button>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- Email Address Section -->
                <div class="bg-white rounded-lg shadow-sm border border-gray-200">
                    <div class="p-6 border-b border-gray-200">
                        <div class="flex items-center">
                            <div class="w-12 h-12 bg-gradient-to-r from-pink-500 to-red-500 rounded-lg flex items-center justify-center text-white mr-4">
                                <i class="fas fa-envelope text-xl"></i>
                            </div>
                            <div>
                                <h3 class="text-lg font-medium text-bulsu-maroon">Email Address</h3>
                                <p class="text-sm text-gray-600">Change your login email address</p>
                            </div>
                        </div>
                    </div>

                    <div class="p-6">
                        <div class="bg-blue-50 border border-blue-200 rounded-lg p-4 mb-6">
                            <div class="flex items-center">
                                <i class="fas fa-info-circle text-blue-600 mr-2"></i>
                                <span class="text-blue-700">Your current email address is: <strong><?php echo htmlspecialchars($student['email']); ?></strong></span>
                            </div>
                        </div>

                        <form method="POST" id="emailForm">
                            <input type="hidden" name="action" value="update_email">
                            
                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 mb-6">
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-2" for="new_email">
                                        New Email Address <span class="text-red-500">*</span>
                                    </label>
                                    <input type="email" id="new_email" name="new_email" 
                                           class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-bulsu-gold focus:border-transparent" 
                                           placeholder="Enter new email address" required>
                                </div>
                                
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-2" for="current_password_email">
                                        Current Password <span class="text-red-500">*</span>
                                    </label>
                                    <div class="relative">
                                        <input type="password" id="current_password_email" name="current_password_email" 
                                               class="w-full px-3 py-2 pr-10 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-bulsu-gold focus:border-transparent" 
                                               placeholder="Enter current password" required>
                                        <button type="button" class="password-toggle" onclick="togglePassword('current_password_email')">
                                            <i class="fas fa-eye"></i>
                                        </button>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="flex justify-end">
                                <button type="submit" 
                                        class="inline-flex items-center px-4 py-2 bg-bulsu-maroon text-white font-medium rounded-md hover:bg-bulsu-dark-maroon transition-colors">
                                    <i class="fas fa-save mr-2"></i>
                                    Update Email
                                </button>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- Password Security Section -->
                <div class="bg-white rounded-lg shadow-sm border border-gray-200">
                    <div class="p-6 border-b border-gray-200">
                        <div class="flex items-center">
                            <div class="w-12 h-12 bg-gradient-to-r from-cyan-500 to-blue-500 rounded-lg flex items-center justify-center text-white mr-4">
                                <i class="fas fa-shield-alt text-xl"></i>
                            </div>
                            <div>
                                <h3 class="text-lg font-medium text-bulsu-maroon">Password & Security</h3>
                                <p class="text-sm text-gray-600">Update your password to keep your account secure</p>
                            </div>
                        </div>
                    </div>

                    <div class="p-6">
                        <div class="bg-yellow-50 border border-yellow-200 rounded-lg p-4 mb-6">
                            <h4 class="flex items-center text-sm font-medium text-yellow-800 mb-2">
                                <i class="fas fa-lightbulb mr-2"></i>
                                Password Security Tips
                            </h4>
                            <ul class="text-sm text-yellow-700 space-y-1 ml-4">
                                <li>• Use at least 8 characters with a mix of letters, numbers, and symbols</li>
                                <li>• Don't use personal information like your name or birthday</li>
                                <li>• Don't reuse passwords from other accounts</li>
                                <li>• Consider using a password manager</li>
                            </ul>
                        </div>

                        <form method="POST" id="passwordForm">
                            <input type="hidden" name="action" value="update_password">
                            
                            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 mb-6">
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-2" for="current_password">
                                        Current Password <span class="text-red-500">*</span>
                                    </label>
                                    <div class="relative">
                                        <input type="password" id="current_password" name="current_password" 
                                               class="w-full px-3 py-2 pr-10 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-bulsu-gold focus:border-transparent" 
                                               placeholder="Enter current password" required>
                                        <button type="button" class="password-toggle" onclick="togglePassword('current_password')">
                                            <i class="fas fa-eye"></i>
                                        </button>
                                    </div>
                                </div>
                                
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-2" for="new_password">
                                        New Password <span class="text-red-500">*</span>
                                    </label>
                                    <div class="relative">
                                        <input type="password" id="new_password" name="new_password" 
                                               class="w-full px-3 py-2 pr-10 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-bulsu-gold focus:border-transparent" 
                                               placeholder="Enter new password" required
                                               oninput="checkPasswordStrength(this.value)">
                                        <button type="button" class="password-toggle" onclick="togglePassword('new_password')">
                                            <i class="fas fa-eye"></i>
                                        </button>
                                    </div>
                                    <div class="mt-2" id="passwordStrength"></div>
                                </div>
                                
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-2" for="confirm_password">
                                        Confirm New Password <span class="text-red-500">*</span>
                                    </label>
                                    <div class="relative">
                                        <input type="password" id="confirm_password" name="confirm_password" 
                                               class="w-full px-3 py-2 pr-10 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-bulsu-gold focus:border-transparent" 
                                               placeholder="Confirm new password" required
                                               oninput="checkPasswordMatch()">
                                        <button type="button" class="password-toggle" onclick="togglePassword('confirm_password')">
                                            <i class="fas fa-eye"></i>
                                        </button>
                                    </div>
                                    <div class="mt-2" id="passwordMatch"></div>
                                </div>
                            </div>
                            
                            <div class="flex justify-end">
                                <button type="submit" id="updatePasswordBtn" disabled
                                        class="inline-flex items-center px-4 py-2 bg-bulsu-maroon text-white font-medium rounded-md hover:bg-bulsu-dark-maroon transition-colors disabled:bg-gray-400 disabled:cursor-not-allowed">
                                    <i class="fas fa-save mr-2"></i>
                                    Update Password
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
        // Global variables
        let sidebar = document.getElementById('sidebar');
        let sidebarOverlay = document.getElementById('sidebarOverlay');

        // Mobile menu functionality
        document.getElementById('mobileMenuBtn').addEventListener('click', function() {
            sidebar.classList.remove('-translate-x-full');
            sidebarOverlay.classList.remove('hidden');
            document.body.style.overflow = 'hidden';
        });

        document.getElementById('closeSidebar').addEventListener('click', closeSidebar);
        sidebarOverlay.addEventListener('click', closeSidebar);

        function closeSidebar() {
            sidebar.classList.add('-translate-x-full');
            sidebarOverlay.classList.add('hidden');
            document.body.style.overflow = 'auto';
        }

        // Profile dropdown functionality
        document.getElementById('profileBtn').addEventListener('click', function(e) {
            e.stopPropagation();
            const dropdown = document.getElementById('profileDropdown');
            dropdown.classList.toggle('hidden');
        });

        // Close dropdown when clicking outside
        document.addEventListener('click', function(e) {
            const profileDropdown = document.getElementById('profileDropdown');
            if (!e.target.closest('#profileBtn') && !profileDropdown.classList.contains('hidden')) {
                profileDropdown.classList.add('hidden');
            }
        });

        // Preview image before upload
        function previewImage(input) {
            if (input.files && input.files[0]) {
                const reader = new FileReader();
                reader.onload = function(e) {
                    const profilePic = input.closest('form').querySelector('.w-24.h-24');
                    profilePic.innerHTML = `<img src="${e.target.result}" alt="Preview" class="w-full h-full object-cover">`;
                };
                reader.readAsDataURL(input.files[0]);
            }
        }

        // Toggle password visibility
        function togglePassword(inputId) {
            const input = document.getElementById(inputId);
            const toggle = input.parentElement.querySelector('.password-toggle');
            const icon = toggle.querySelector('i');
            
            if (input.type === 'password') {
                input.type = 'text';
                icon.classList.remove('fa-eye');
                icon.classList.add('fa-eye-slash');
            } else {
                input.type = 'password';
                icon.classList.remove('fa-eye-slash');
                icon.classList.add('fa-eye');
            }
        }

        // Check password strength
        function checkPasswordStrength(password) {
            const strengthDiv = document.getElementById('passwordStrength');
            let strength = 0;
            let feedback = '';

            if (password.length >= 8) strength++;
            if (/[a-z]/.test(password)) strength++;
            if (/[A-Z]/.test(password)) strength++;
            if (/[0-9]/.test(password)) strength++;
            if (/[^A-Za-z0-9]/.test(password)) strength++;

            switch (strength) {
                case 0:
                case 1:
                    feedback = '<div class="strength-bar"><div class="strength-fill strength-weak"></div></div><span class="text-xs text-red-600">Weak password</span>';
                    break;
                case 2:
                    feedback = '<div class="strength-bar"><div class="strength-fill strength-fair"></div></div><span class="text-xs text-yellow-600">Fair password</span>';
                    break;
                case 3:
                    feedback = '<div class="strength-bar"><div class="strength-fill strength-good"></div></div><span class="text-xs text-orange-600">Good password</span>';
                    break;
                case 4:
                case 5:
                    feedback = '<div class="strength-bar"><div class="strength-fill strength-strong"></div></div><span class="text-xs text-green-600">Strong password</span>';
                    break;
            }

            strengthDiv.innerHTML = feedback;
            checkPasswordMatch();
        }

        // Check if passwords match
        function checkPasswordMatch() {
            const newPassword = document.getElementById('new_password').value;
            const confirmPassword = document.getElementById('confirm_password').value;
            const matchDiv = document.getElementById('passwordMatch');
            const updateBtn = document.getElementById('updatePasswordBtn');

            if (confirmPassword.length > 0) {
                if (newPassword === confirmPassword) {
                    matchDiv.innerHTML = '<span class="text-xs text-green-600"><i class="fas fa-check mr-1"></i>Passwords match</span>';
                    updateBtn.disabled = false;
                } else {
                    matchDiv.innerHTML = '<span class="text-xs text-red-600"><i class="fas fa-times mr-1"></i>Passwords do not match</span>';
                    updateBtn.disabled = true;
                }
            } else {
                matchDiv.innerHTML = '';
                updateBtn.disabled = true;
            }
        }

        // Reset personal information form
        function resetPersonalForm() {
            document.getElementById('personalInfoForm').reset();
        }

        // Form validation
        document.addEventListener('DOMContentLoaded', function() {
            // Email validation
            const emailInput = document.getElementById('new_email');
            if (emailInput) {
                emailInput.addEventListener('input', function() {
                    const email = this.value;
                    const currentEmail = '<?php echo htmlspecialchars($student['email']); ?>';
                    
                    if (email === currentEmail) {
                        this.setCustomValidity('New email must be different from current email');
                    } else {
                        this.setCustomValidity('');
                    }
                });
            }

            // Contact number validation
            const contactInput = document.getElementById('contact_number');
            if (contactInput) {
                contactInput.addEventListener('input', function() {
                    this.value = this.value.replace(/[^0-9+\-\s()]/g, '');
                });
            }
        });

        // Show loading state on form submission
        document.querySelectorAll('form').forEach(form => {
            form.addEventListener('submit', function() {
                const submitBtn = this.querySelector('button[type="submit"]');
                if (submitBtn) {
                    submitBtn.disabled = true;
                    submitBtn.innerHTML = '<i class="fas fa-spinner animate-spin mr-2"></i> Saving...';
                }
            });
        });

        // Auto-hide alerts after 5 seconds
        setTimeout(function() {
            const alerts = document.querySelectorAll('.bg-green-50, .bg-red-50');
            alerts.forEach(alert => {
                if (alert.closest('.p-4')) {
                    alert.parentElement.style.transition = 'opacity 0.5s ease';
                    alert.parentElement.style.opacity = '0';
                    setTimeout(() => alert.parentElement.remove(), 500);
                }
            });
        }, 5000);

        // Close sidebar on larger screens
        window.addEventListener('resize', function() {
            if (window.innerWidth >= 1024) {
                closeSidebar();
            }
        });

        // Keyboard shortcuts
        document.addEventListener('keydown', function(e) {
            // Close sidebar with Escape key
            if (e.key === 'Escape') {
                if (!sidebar.classList.contains('-translate-x-full')) {
                    closeSidebar();
                }
            }
        });
    </script>
</body>
</html>