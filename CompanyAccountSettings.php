<?php
include('connect.php');
session_start();

// Prevent caching - add these headers at the top
header("Cache-Control: no-cache, no-store, must-revalidate");
header("Pragma: no-cache");
header("Expires: 0");

// Check if user is logged in and is a company supervisor
if (!isset($_SESSION['supervisor_id']) || $_SESSION['user_type'] !== 'supervisor') {
    header("Location: login.php");
    exit();
}

$supervisor_id = $_SESSION['supervisor_id'];
$success_message = '';
$error_message = '';

// Fetch current supervisor data
try {
    $stmt = $conn->prepare("SELECT * FROM company_supervisors WHERE supervisor_id = ?");
    $stmt->bind_param("i", $supervisor_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows > 0) {
        $supervisor = $result->fetch_assoc();
        
        // Build full name for display
        $full_name = $supervisor['full_name'];
        
        // Create initials for avatar
        $name_parts = explode(' ', trim($supervisor['full_name']));
        if (count($name_parts) >= 2) {
            $initials = strtoupper(substr($name_parts[0], 0, 1) . substr(end($name_parts), 0, 1));
        } else {
            $initials = strtoupper(substr($supervisor['full_name'], 0, 2));
        }
        
        // Profile picture path
        $profile_picture = $supervisor['profile_picture'] ?? '';
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
            $upload_dir = 'uploads/supervisor_profiles/';
            
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
                    $new_filename = 'supervisor_' . $supervisor_id . '_' . time() . '.' . $file_extension;
                    $upload_path = $upload_dir . $new_filename;
                    
                    if (move_uploaded_file($_FILES['profile_picture']['tmp_name'], $upload_path)) {
                        // Delete old profile picture if exists
                        if (!empty($profile_picture) && file_exists($profile_picture)) {
                            unlink($profile_picture);
                        }
                        
                        // Update database
                        try {
                            $stmt = $conn->prepare("UPDATE company_supervisors SET profile_picture = ?, updated_at = CURRENT_TIMESTAMP WHERE supervisor_id = ?");
                            $stmt->bind_param("si", $upload_path, $supervisor_id);
                            
                            if ($stmt->execute()) {
                                $success_message = "Profile picture updated successfully!";
                                $profile_picture = $upload_path;
                                $supervisor['profile_picture'] = $upload_path;
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
            $stmt = $conn->prepare("UPDATE company_supervisors SET profile_picture = NULL, updated_at = CURRENT_TIMESTAMP WHERE supervisor_id = ?");
            $stmt->bind_param("i", $supervisor_id);
            
            if ($stmt->execute()) {
                $success_message = "Profile picture removed successfully!";
                $profile_picture = '';
                $supervisor['profile_picture'] = '';
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
        $full_name = trim($_POST['full_name']);
        $phone_number = trim($_POST['phone_number']);
        $position = trim($_POST['position']);
        
        // Validation
        if (empty($full_name) || empty($phone_number) || empty($position)) {
            $error_message = "Please fill in all required fields.";
        } else {
            try {
                $stmt = $conn->prepare("UPDATE company_supervisors SET full_name = ?, phone_number = ?, position = ?, updated_at = CURRENT_TIMESTAMP WHERE supervisor_id = ?");
                $stmt->bind_param("sssi", $full_name, $phone_number, $position, $supervisor_id);
                
                if ($stmt->execute()) {
                    $success_message = "Personal information updated successfully!";
                    // Refresh supervisor data
                    $supervisor['full_name'] = $full_name;
                    $supervisor['phone_number'] = $phone_number;
                    $supervisor['position'] = $position;
                    
                    // Update full name and initials
                    $full_name = $supervisor['full_name'];
                    $name_parts = explode(' ', trim($full_name));
                    if (count($name_parts) >= 2) {
                        $initials = strtoupper(substr($name_parts[0], 0, 1) . substr(end($name_parts), 0, 1));
                    } else {
                        $initials = strtoupper(substr($full_name, 0, 2));
                    }
                    
                    // Update session
                    $_SESSION['full_name'] = $full_name;
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
        } elseif ($new_email === $supervisor['email']) {
            $error_message = "New email must be different from current email.";
        } else {
            // Verify current password
            if (password_verify($current_password, $supervisor['password'])) {
                // Check if email already exists
                $check_stmt = $conn->prepare("SELECT supervisor_id FROM company_supervisors WHERE email = ? AND supervisor_id != ?");
                $check_stmt->bind_param("si", $new_email, $supervisor_id);
                $check_stmt->execute();
                $check_result = $check_stmt->get_result();
                
                if ($check_result->num_rows > 0) {
                    $error_message = "This email address is already in use.";
                } else {
                    try {
                        $stmt = $conn->prepare("UPDATE company_supervisors SET email = ?, updated_at = CURRENT_TIMESTAMP WHERE supervisor_id = ?");
                        $stmt->bind_param("si", $new_email, $supervisor_id);
                        
                        if ($stmt->execute()) {
                            $success_message = "Email address updated successfully!";
                            $supervisor['email'] = $new_email;
                            $_SESSION['email'] = $new_email;
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
            if (password_verify($current_password, $supervisor['password'])) {
                try {
                    $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
                    $stmt = $conn->prepare("UPDATE company_supervisors SET password = ?, updated_at = CURRENT_TIMESTAMP WHERE supervisor_id = ?");
                    $stmt->bind_param("si", $hashed_password, $supervisor_id);
                    
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
        .sidebar {
            transition: transform 0.3s ease-in-out;
        }
        .sidebar-overlay {
            transition: opacity 0.3s ease-in-out;
        }
        .strength-bar {
            height: 4px;
            background: #e9ecef;
            border-radius: 2px;
            overflow: hidden;
        }
        .strength-fill {
            height: 100%;
            border-radius: 2px;
            transition: all 0.3s ease;
        }
        .strength-weak { background: #dc3545; width: 25%; }
        .strength-fair { background: #ffc107; width: 50%; }
        .strength-good { background: #fd7e14; width: 75%; }
        .strength-strong { background: #28a745; width: 100%; }
    </style>
</head>
<body class="bg-gray-50">
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
            <a href="CompanyDashboard.php" class="nav-item flex items-center px-3 py-2 text-sm font-medium text-bulsu-light-gold hover:text-white hover:bg-bulsu-gold hover:bg-opacity-20 rounded-md transition-all duration-200">
                <i class="fas fa-th-large mr-3"></i>
                Dashboard
            </a>
            <a href="CompanyTasks.php" class="nav-item flex items-center px-3 py-2 text-sm font-medium text-bulsu-light-gold hover:text-white hover:bg-bulsu-gold hover:bg-opacity-20 rounded-md transition-all duration-200">
                <i class="fas fa-tasks mr-3"></i>
                Tasks
            </a>
            <a href="CompanyTimeRecord.php" class="nav-item flex items-center px-3 py-2 text-sm font-medium text-bulsu-light-gold hover:text-white hover:bg-bulsu-gold hover:bg-opacity-20 rounded-md transition-all duration-200">
                <i class="fas fa-clock mr-3"></i>
                Student Time Record
            </a>
            <a href="ApproveTasks.php" class="nav-item flex items-center px-3 py-2 text-sm font-medium text-bulsu-light-gold hover:text-white hover:bg-bulsu-gold hover:bg-opacity-20 rounded-md transition-all duration-200">
                <i class="fas fa-comment-dots mr-3"></i>
                Task Approval Management
            </a>
            <a href="CompanyProgressReport.php" class="nav-item flex items-center px-3 py-2 text-sm font-medium text-bulsu-light-gold hover:text-white hover:bg-bulsu-gold hover:bg-opacity-20 rounded-md transition-all duration-200">
                <i class="fas fa-chart-line mr-3"></i>
                Student Progress Report
            </a>
            <a href="StudentEvaluate.php" class="nav-item flex items-center px-3 py-2 text-sm font-medium text-bulsu-light-gold hover:text-white hover:bg-bulsu-gold hover:bg-opacity-20 rounded-md transition-all duration-200">
                <i class="fas fa-star mr-3"></i>
                Student Evaluation
            </a>
            <a href="CompanyMessage.php" class="nav-item flex items-center px-3 py-2 text-sm font-medium text-bulsu-light-gold hover:text-white hover:bg-bulsu-gold hover:bg-opacity-20 rounded-md transition-all duration-200">
                <i class="fas fa-envelope mr-3"></i>
                Messages
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
                <p class="text-xs text-bulsu-light-gold">Company Supervisor</p>
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

                <!-- Header Title -->
                <div class="flex-1 lg:ml-0 ml-4">
                    <h1 class="text-xl sm:text-2xl font-bold text-gray-900">Account Settings</h1>
                    <p class="text-sm sm:text-base text-gray-500 hidden sm:block">Manage your personal information and account security</p>
                </div>
                
                <!-- Profile Dropdown -->
                <div class="relative">
                    <button id="profileBtn" class="flex items-center p-1 rounded-full hover:bg-gray-100">
                        <div class="w-8 h-8 sm:w-10 sm:h-10 bg-gradient-to-r from-blue-500 to-purple-600 rounded-full flex items-center justify-center text-white font-semibold text-xs sm:text-sm">
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
                                <div class="w-12 h-12 bg-gradient-to-r from-blue-500 to-purple-600 rounded-full flex items-center justify-center text-white font-semibold">
                                    <?php if (!empty($profile_picture) && file_exists($profile_picture)): ?>
                                        <img src="<?php echo htmlspecialchars($profile_picture); ?>" alt="Profile Picture" class="w-full h-full rounded-full object-cover">
                                    <?php else: ?>
                                        <?php echo $initials; ?>
                                    <?php endif; ?>
                                </div>
                                <div>
                                    <p class="font-medium text-gray-900"><?php echo htmlspecialchars($full_name); ?></p>
                                    <p class="text-sm text-gray-500">Company Supervisor</p>
                                    <p class="text-xs text-gray-400"><?php echo htmlspecialchars($supervisor['company_name']); ?></p>
                                </div>
                            </div>
                        </div>
                        <a href="CompanyAccountSettings.php" class="flex items-center px-4 py-2 text-sm text-blue-600 bg-blue-50 font-medium">
                            <i class="fas fa-cog mr-3"></i>
                            Account Settings
                        </a>
                        <div class="border-t border-gray-200"></div>
                        <a href="logout.php" class="flex items-center px-4 py-2 text-sm text-gray-700 hover:bg-gray-50" onclick="return confirmLogout()">
                            <i class="fas fa-sign-out-alt mr-3"></i>
                            Logout
                        </a>
                    </div>
                </div>
            </div>
        </div>

        <!-- Main Container -->
        <div class="p-4 sm:p-6 lg:p-8">
            <!-- Profile Summary -->
            <div class="bg-gradient-to-r from-bulsu-maroon to-bulsu-dark-maroon text-white rounded-lg p-6 mb-6">
                <div class="flex items-center space-x-4">
                    <div class="w-20 h-20 bg-bulsu-gold bg-opacity-20 rounded-full flex items-center justify-center text-2xl font-bold border-4 border-bulsu-gold border-opacity-30 overflow-hidden">
                        <?php if (!empty($profile_picture) && file_exists($profile_picture)): ?>
                            <img src="<?php echo htmlspecialchars($profile_picture); ?>" alt="Profile Picture" class="w-full h-full object-cover">
                        <?php else: ?>
                            <?php echo $initials; ?>
                        <?php endif; ?>
                    </div>
                    <div>
                        <h2 class="text-2xl font-bold mb-1"><?php echo htmlspecialchars($full_name); ?></h2>
                        <p class="opacity-90 text-sm"><?php echo htmlspecialchars($supervisor['position']); ?> • <?php echo htmlspecialchars($supervisor['company_name']); ?></p>
                        <p class="opacity-80 text-sm"><?php echo htmlspecialchars($supervisor['email']); ?></p>
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
                        <i class="fas fa-exclamation-triangle text-red-600 mt-1 mr-3"></i>
                        <p class="text-red-700"><?php echo htmlspecialchars($error_message); ?></p>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Settings Cards -->
            <div class="grid gap-6 lg:gap-8">
                <!-- Profile Picture Section -->
                <div class="bg-white rounded-lg shadow-sm border border-gray-200">
                    <div class="p-6 border-b border-gray-200">
                        <div class="flex items-center">
                            <div class="w-12 h-12 bg-gradient-to-r from-bulsu-gold to-yellow-400 rounded-lg flex items-center justify-center text-bulsu-maroon mr-4">
                                <i class="fas fa-camera text-xl"></i>
                            </div>
                            <div>
                                <h3 class="text-lg font-medium text-gray-900">Profile Picture</h3>
                                <p class="text-sm text-gray-500">Update your profile picture</p>
                            </div>
                        </div>
                    </div>
                    
                    <div class="p-6">
                        <form method="POST" enctype="multipart/form-data">
                            <input type="hidden" name="action" value="update_profile_picture">
                            
                            <div class="flex flex-col sm:flex-row items-center gap-6 mb-6">
                                <div class="w-24 h-24 bg-gradient-to-r from-bulsu-gold to-yellow-400 rounded-full flex items-center justify-center text-bulsu-maroon font-bold text-2xl border-4 border-gray-100 overflow-hidden">
                                    <?php if (!empty($profile_picture) && file_exists($profile_picture)): ?>
                                        <img src="<?php echo htmlspecialchars($profile_picture); ?>" alt="Profile Picture" class="w-full h-full object-cover">
                                    <?php else: ?>
                                        <?php echo $initials; ?>
                                    <?php endif; ?>
                                </div>
                                
                                <div class="flex flex-col gap-3">
                                    <div class="relative">
                                        <input type="file" id="profile_picture" name="profile_picture" accept="image/*" class="absolute inset-0 w-full h-full opacity-0 cursor-pointer" required>
                                        <label for="profile_picture" class="inline-flex items-center px-4 py-2 bg-gradient-to-r from-bulsu-gold to-yellow-400 text-bulsu-maroon rounded-md cursor-pointer hover:from-yellow-400 hover:to-bulsu-gold transition-all duration-200">
                                            <i class="fas fa-upload mr-2"></i>
                                            Choose New Picture
                                        </label>
                                    </div>
                                    <p class="text-xs text-gray-500">Max file size: 5MB. Supported formats: JPG, PNG, GIF</p>
                                </div>
                            </div>

                            <div class="flex justify-end">
                                <button type="submit" class="inline-flex items-center px-6 py-2 bg-bulsu-maroon text-white rounded-md hover:bg-bulsu-dark-maroon transition-colors duration-200">
                                    <i class="fas fa-save mr-2"></i>
                                    Update Picture
                                </button>
                            </div>
                        </form>

                        <?php if (!empty($profile_picture)): ?>
                            <form method="POST" class="mt-4">
                                <input type="hidden" name="action" value="remove_profile_picture">
                                <button type="submit" class="inline-flex items-center px-4 py-2 bg-red-600 text-white rounded-md hover:bg-red-700 transition-colors duration-200 text-sm" onclick="return confirm('Are you sure you want to remove your profile picture?')">
                                    <i class="fas fa-trash mr-2"></i>
                                    Remove Picture
                                </button>
                            </form>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Personal Information Section -->
                <div class="bg-white rounded-lg shadow-sm border border-gray-200">
                    <div class="p-6 border-b border-gray-200">
                        <div class="flex items-center">
                            <div class="w-12 h-12 bg-gradient-to-r from-green-500 to-emerald-600 rounded-lg flex items-center justify-center text-white mr-4">
                                <i class="fas fa-user text-xl"></i>
                            </div>
                            <div>
                                <h3 class="text-lg font-medium text-gray-900">Personal Information</h3>
                                <p class="text-sm text-gray-500">Update your basic information</p>
                            </div>
                        </div>
                    </div>

                    <div class="p-6">
                        <!-- Read-only Company Information -->
                        <div class="bg-gray-50 border border-gray-200 rounded-lg p-4 mb-6">
                            <h4 class="flex items-center text-base font-medium text-gray-900 mb-3">
                                <i class="fas fa-building mr-2"></i>
                                Company Information
                            </h4>
                            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                                <div>
                                    <span class="text-xs text-gray-500 font-medium block mb-1">Company Name</span>
                                    <span class="text-sm text-gray-900 font-semibold"><?php echo htmlspecialchars($supervisor['company_name']); ?></span>
                                </div>
                                <div>
                                    <span class="text-xs text-gray-500 font-medium block mb-1">Company Address</span>
                                    <span class="text-sm text-gray-900 font-semibold"><?php echo htmlspecialchars($supervisor['company_address']); ?></span>
                                </div>
                                <div>
                                    <span class="text-xs text-gray-500 font-medium block mb-1">Account Created</span>
                                    <span class="text-sm text-gray-900 font-semibold"><?php echo date('F j, Y', strtotime($supervisor['created_at'])); ?></span>
                                </div>
                            </div>
                        </div>

                        <form method="POST">
                            <input type="hidden" name="action" value="update_personal">
                            
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-6">
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-2" for="full_name">
                                        Full Name <span class="text-red-500">*</span>
                                    </label>
                                    <input type="text" id="full_name" name="full_name" 
                                           class="w-full px-4 py-2 border border-gray-300 rounded-md focus:ring-2 focus:ring-bulsu-gold focus:border-bulsu-gold transition-colors duration-200" 
                                           value="<?php echo htmlspecialchars($supervisor['full_name']); ?>" 
                                           required maxlength="100">
                                </div>
                                
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-2" for="phone_number">
                                        Phone Number <span class="text-red-500">*</span>
                                    </label>
                                    <input type="tel" id="phone_number" name="phone_number" 
                                           class="w-full px-4 py-2 border border-gray-300 rounded-md focus:ring-2 focus:ring-bulsu-gold focus:border-bulsu-gold transition-colors duration-200" 
                                           value="<?php echo htmlspecialchars($supervisor['phone_number']); ?>" 
                                           required maxlength="20">
                                </div>
                            </div>

                            <div class="mb-6">
                                <label class="block text-sm font-medium text-gray-700 mb-2" for="position">
                                    Position/Title <span class="text-red-500">*</span>
                                </label>
                                <input type="text" id="position" name="position" 
                                       class="w-full px-4 py-2 border border-gray-300 rounded-md focus:ring-2 focus:ring-bulsu-gold focus:border-bulsu-gold transition-colors duration-200" 
                                       value="<?php echo htmlspecialchars($supervisor['position']); ?>" 
                                       required maxlength="100">
                            </div>

                            <div class="flex justify-end">
                                <button type="submit" class="inline-flex items-center px-6 py-2 bg-bulsu-maroon text-white rounded-md hover:bg-bulsu-dark-maroon transition-colors duration-200">
                                    <i class="fas fa-save mr-2"></i>
                                    Update Information
                                </button>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- Email Update Section -->
                <div class="bg-white rounded-lg shadow-sm border border-gray-200">
                    <div class="p-6 border-b border-gray-200">
                        <div class="flex items-center">
                            <div class="w-12 h-12 bg-gradient-to-r from-pink-500 to-rose-600 rounded-lg flex items-center justify-center text-white mr-4">
                                <i class="fas fa-envelope text-xl"></i>
                            </div>
                            <div>
                                <h3 class="text-lg font-medium text-gray-900">Email Address</h3>
                                <p class="text-sm text-gray-500">Change your login email address</p>
                            </div>
                        </div>
                    </div>

                    <div class="p-6">
                        <div class="bg-blue-50 border border-blue-200 rounded-lg p-4 mb-6">
                            <div class="flex items-start">
                                <i class="fas fa-info-circle text-blue-600 mt-1 mr-3"></i>
                                <p class="text-blue-700 text-sm">Your current email address is: <strong><?php echo htmlspecialchars($supervisor['email']); ?></strong></p>
                            </div>
                        </div>

                        <form method="POST">
                            <input type="hidden" name="action" value="update_email">
                            
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-6">
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-2" for="new_email">
                                        New Email Address <span class="text-red-500">*</span>
                                    </label>
                                    <input type="email" id="new_email" name="new_email" 
                                           class="w-full px-4 py-2 border border-gray-300 rounded-md focus:ring-2 focus:ring-bulsu-gold focus:border-bulsu-gold transition-colors duration-200" 
                                           required maxlength="255" placeholder="Enter new email address">
                                </div>
                                
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-2" for="current_password_email">
                                        Current Password <span class="text-red-500">*</span>
                                    </label>
                                    <div class="relative">
                                        <input type="password" id="current_password_email" name="current_password_email" 
                                               class="w-full px-4 py-2 pr-10 border border-gray-300 rounded-md focus:ring-2 focus:ring-bulsu-gold focus:border-bulsu-gold transition-colors duration-200" 
                                               required placeholder="Enter current password">
                                        <button type="button" class="absolute inset-y-0 right-0 px-3 py-2 text-gray-500 hover:text-gray-700" onclick="togglePassword('current_password_email')">
                                            <i class="fas fa-eye" id="current_password_email_icon"></i>
                                        </button>
                                    </div>
                                </div>
                            </div>

                            <div class="flex justify-end">
                                <button type="submit" class="inline-flex items-center px-6 py-2 bg-bulsu-maroon text-white rounded-md hover:bg-bulsu-dark-maroon transition-colors duration-200">
                                    <i class="fas fa-save mr-2"></i>
                                    Update Email
                                </button>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- Password Update Section -->
                <div class="bg-white rounded-lg shadow-sm border border-gray-200">
                    <div class="p-6 border-b border-gray-200">
                        <div class="flex items-center">
                            <div class="w-12 h-12 bg-gradient-to-r from-blue-500 to-cyan-600 rounded-lg flex items-center justify-center text-white mr-4">
                                <i class="fas fa-lock text-xl"></i>
                            </div>
                            <div>
                                <h3 class="text-lg font-medium text-gray-900">Password Security</h3>
                                <p class="text-sm text-gray-500">Change your account password</p>
                            </div>
                        </div>
                    </div>

                    <div class="p-6">
                        <div class="bg-yellow-50 border border-yellow-200 rounded-lg p-4 mb-6">
                            <h4 class="flex items-center text-yellow-800 font-medium mb-2">
                                <i class="fas fa-shield-alt mr-2"></i>
                                Password Security Tips
                            </h4>
                            <ul class="text-yellow-700 text-sm space-y-1 ml-6 list-disc">
                                <li>Use at least 8 characters with a mix of letters, numbers, and symbols</li>
                                <li>Avoid using personal information or common words</li>
                                <li>Don't reuse passwords from other accounts</li>
                                <li>Consider using a password manager</li>
                            </ul>
                        </div>

                        <form method="POST">
                            <input type="hidden" name="action" value="update_password">
                            
                            <div class="space-y-6 mb-6">
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-2" for="current_password">
                                        Current Password <span class="text-red-500">*</span>
                                    </label>
                                    <div class="relative">
                                        <input type="password" id="current_password" name="current_password" 
                                               class="w-full px-4 py-2 pr-10 border border-gray-300 rounded-md focus:ring-2 focus:ring-bulsu-gold focus:border-bulsu-gold transition-colors duration-200" 
                                               required placeholder="Enter current password">
                                        <button type="button" class="absolute inset-y-0 right-0 px-3 py-2 text-gray-500 hover:text-gray-700" onclick="togglePassword('current_password')">
                                            <i class="fas fa-eye" id="current_password_icon"></i>
                                        </button>
                                    </div>
                                </div>
                                
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-2" for="new_password">
                                        New Password <span class="text-red-500">*</span>
                                    </label>
                                    <div class="relative">
                                        <input type="password" id="new_password" name="new_password" 
                                               class="w-full px-4 py-2 pr-10 border border-gray-300 rounded-md focus:ring-2 focus:ring-bulsu-gold focus:border-bulsu-gold transition-colors duration-200" 
                                               required placeholder="Enter new password" 
                                               minlength="8" oninput="checkPasswordStrength()">
                                        <button type="button" class="absolute inset-y-0 right-0 px-3 py-2 text-gray-500 hover:text-gray-700" onclick="togglePassword('new_password')">
                                            <i class="fas fa-eye" id="new_password_icon"></i>
                                        </button>
                                    </div>
                                    <div class="mt-2" id="password-strength">
                                        <div class="strength-bar mb-1">
                                            <div class="strength-fill" id="strength-fill"></div>
                                        </div>
                                        <span class="text-sm text-gray-600" id="strength-text">Enter a password</span>
                                    </div>
                                </div>
                                
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-2" for="confirm_password">
                                        Confirm New Password <span class="text-red-500">*</span>
                                    </label>
                                    <div class="relative">
                                        <input type="password" id="confirm_password" name="confirm_password" 
                                               class="w-full px-4 py-2 pr-10 border border-gray-300 rounded-md focus:ring-2 focus:ring-bulsu-gold focus:border-bulsu-gold transition-colors duration-200" 
                                               required placeholder="Confirm new password" 
                                               minlength="8" oninput="checkPasswordMatch()">
                                        <button type="button" class="absolute inset-y-0 right-0 px-3 py-2 text-gray-500 hover:text-gray-700" onclick="togglePassword('confirm_password')">
                                            <i class="fas fa-eye" id="confirm_password_icon"></i>
                                        </button>
                                    </div>
                                    <div id="password-match" class="text-sm mt-2"></div>
                                </div>
                            </div>

                            <div class="flex justify-end">
                                <button type="submit" class="inline-flex items-center px-6 py-2 bg-bulsu-maroon text-white rounded-md hover:bg-bulsu-dark-maroon transition-colors duration-200 disabled:opacity-50 disabled:cursor-not-allowed" id="update-password-btn" disabled>
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
        // Mobile menu functionality
        document.getElementById('mobileMenuBtn').addEventListener('click', function() {
            const sidebar = document.getElementById('sidebar');
            const overlay = document.getElementById('sidebarOverlay');
            sidebar.classList.remove('-translate-x-full');
            overlay.classList.remove('hidden');
            document.body.style.overflow = 'hidden';
        });

        document.getElementById('closeSidebar').addEventListener('click', closeSidebar);
        document.getElementById('sidebarOverlay').addEventListener('click', closeSidebar);

        function closeSidebar() {
            const sidebar = document.getElementById('sidebar');
            const overlay = document.getElementById('sidebarOverlay');
            sidebar.classList.add('-translate-x-full');
            overlay.classList.add('hidden');
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

        // Toggle password visibility
        function togglePassword(inputId) {
            const input = document.getElementById(inputId);
            const icon = document.getElementById(inputId + '_icon');
            
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

        // Password strength checker
        function checkPasswordStrength() {
            const password = document.getElementById('new_password').value;
            const strengthFill = document.getElementById('strength-fill');
            const strengthText = document.getElementById('strength-text');
            
            if (password.length === 0) {
                strengthFill.className = 'strength-fill';
                strengthText.textContent = 'Enter a password';
                return;
            }
            
            let score = 0;
            let feedback = [];
            
            // Length check
            if (password.length >= 8) score++;
            else feedback.push('at least 8 characters');
            
            // Lowercase check
            if (/[a-z]/.test(password)) score++;
            else feedback.push('lowercase letters');
            
            // Uppercase check
            if (/[A-Z]/.test(password)) score++;
            else feedback.push('uppercase letters');
            
            // Number check
            if (/[0-9]/.test(password)) score++;
            else feedback.push('numbers');
            
            // Special character check
            if (/[^a-zA-Z0-9]/.test(password)) score++;
            else feedback.push('special characters');
            
            // Update UI based on score
            strengthFill.className = 'strength-fill';
            if (score <= 2) {
                strengthFill.classList.add('strength-weak');
                strengthText.innerHTML = '<span class="text-red-600">Weak</span> - Add ' + feedback.slice(0, 2).join(', ');
            } else if (score === 3) {
                strengthFill.classList.add('strength-fair');
                strengthText.innerHTML = '<span class="text-yellow-600">Fair</span> - Add ' + feedback.slice(0, 1).join(', ');
            } else if (score === 4) {
                strengthFill.classList.add('strength-good');
                strengthText.innerHTML = '<span class="text-orange-600">Good</span> - Almost there!';
            } else {
                strengthFill.classList.add('strength-strong');
                strengthText.innerHTML = '<span class="text-green-600">Strong</span> - Great password!';
            }
            
            checkPasswordMatch();
        }

        // Password match checker
        function checkPasswordMatch() {
            const password = document.getElementById('new_password').value;
            const confirmPassword = document.getElementById('confirm_password').value;
            const matchDiv = document.getElementById('password-match');
            const updateBtn = document.getElementById('update-password-btn');
            
            if (confirmPassword.length === 0) {
                matchDiv.innerHTML = '';
                updateBtn.disabled = true;
                return;
            }
            
            if (password === confirmPassword) {
                matchDiv.innerHTML = '<span class="text-green-600"><i class="fas fa-check mr-1"></i> Passwords match</span>';
                updateBtn.disabled = false;
            } else {
                matchDiv.innerHTML = '<span class="text-red-600"><i class="fas fa-times mr-1"></i> Passwords do not match</span>';
                updateBtn.disabled = true;
            }
        }

        // File input change handler
        document.getElementById('profile_picture').addEventListener('change', function(e) {
            const file = e.target.files[0];
            const label = document.querySelector('label[for="profile_picture"]');
            
            if (file) {
                // Validate file size (5MB)
                if (file.size > 5 * 1024 * 1024) {
                    alert('File size must be less than 5MB');
                    e.target.value = '';
                    label.innerHTML = '<i class="fas fa-upload mr-2"></i> Choose New Picture';
                    return;
                }
                
                // Validate file type
                const allowedTypes = ['image/jpeg', 'image/jpg', 'image/png', 'image/gif'];
                if (!allowedTypes.includes(file.type)) {
                    alert('Only JPG, PNG, and GIF files are allowed');
                    e.target.value = '';
                    label.innerHTML = '<i class="fas fa-upload mr-2"></i> Choose New Picture';
                    return;
                }
                
                label.innerHTML = '<i class="fas fa-check mr-2"></i> ' + file.name;
            } else {
                label.innerHTML = '<i class="fas fa-upload mr-2"></i> Choose New Picture';
            }
        });

        // Logout confirmation
        function confirmLogout() {
            return confirm('Are you sure you want to logout?');
        }

        // Auto-hide alerts after 5 seconds
        setTimeout(() => {
            const alerts = document.querySelectorAll('.bg-green-50, .bg-red-50');
            alerts.forEach(alert => {
                alert.style.transition = 'opacity 0.5s ease';
                alert.style.opacity = '0';
                setTimeout(() => {
                    alert.remove();
                }, 500);
            });
        }, 5000);

        // Handle escape key to close modals/dropdowns
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                // Close sidebar on mobile
                if (window.innerWidth < 1024) {
                    closeSidebar();
                }
                
                // Close profile dropdown
                const profileDropdown = document.getElementById('profileDropdown');
                if (!profileDropdown.classList.contains('hidden')) {
                    profileDropdown.classList.add('hidden');
                }
            }
        });

        // Back button prevention
        if (performance.navigation.type === 2) {
            location.replace('login.php');
        }

        // Disable back button functionality
        history.pushState(null, null, location.href);
        window.onpopstate = function () {
            history.go(1);
        };
    </script>

</body>
</html>