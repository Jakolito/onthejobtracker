<?php
include('connect.php');
session_start();

// Prevent caching
header("Cache-Control: no-cache, no-store, must-revalidate");
header("Pragma: no-cache");
header("Expires: 0");

// Check if user is logged in and is an adviser
if (!isset($_SESSION['adviser_id']) || $_SESSION['user_type'] !== 'adviser') {
    header("Location: login.php");
    exit;
}

// Get adviser information
$adviser_id = $_SESSION['adviser_id'];
$adviser_name = $_SESSION['name'];
$adviser_email = $_SESSION['email'];

// Handle form submission
$success_message = '';
$error_message = '';

// Fetch current adviser data including profile picture
try {
$stmt = $conn->prepare("SELECT * FROM academic_adviser WHERE id = ?");
    $stmt->bind_param("i", $adviser_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows > 0) {
        $adviser = $result->fetch_assoc();
        $profile_picture = $adviser['profile_picture'] ?? '';
    } else {
        header("Location: login.php");
        exit();
    }
} catch (Exception $e) {
    $error_message = "Error fetching user data: " . $e->getMessage();
}

if ($_POST) {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'update_profile_picture') {
        // Handle profile picture upload
        if (isset($_FILES['profile_picture']) && $_FILES['profile_picture']['error'] === UPLOAD_ERR_OK) {
            $upload_dir = 'uploads/adviser_profile_pictures/';
            
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
                    $new_filename = 'adviser_profile_' . $adviser_id . '_' . time() . '.' . $file_extension;
                    $upload_path = $upload_dir . $new_filename;
                    
                    if (move_uploaded_file($_FILES['profile_picture']['tmp_name'], $upload_path)) {
                        // Delete old profile picture if exists
                        if (!empty($profile_picture) && file_exists($profile_picture)) {
                            unlink($profile_picture);
                        }
                        
                        // Update database
                        try {
$stmt = $conn->prepare("UPDATE academic_adviser SET profile_picture = ? WHERE id = ?");
                            $stmt->bind_param("si", $upload_path, $adviser_id);
                            
                            if ($stmt->execute()) {
                                $success_message = "Profile picture updated successfully!";
                                $profile_picture = $upload_path;
                                $adviser['profile_picture'] = $upload_path;
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
$stmt = $conn->prepare("UPDATE academic_adviser SET profile_picture = NULL WHERE id = ?");
            $stmt->bind_param("i", $adviser_id);
            
            if ($stmt->execute()) {
                $success_message = "Profile picture removed successfully!";
                $profile_picture = '';
                $adviser['profile_picture'] = '';
            } else {
                $error_message = "Error removing profile picture.";
            }
            $stmt->close();
        } catch (Exception $e) {
            $error_message = "Database error: " . $e->getMessage();
        }
    }
    
    elseif ($action === 'update_account') {
        $new_name = trim($_POST['name']);
        $new_email = trim($_POST['email']);
        $current_password = $_POST['current_password'];
        $new_password = $_POST['new_password'];
        $confirm_password = $_POST['confirm_password'];
        
        // Validate inputs
        if (empty($new_name)) {
            $error_message = "Name cannot be empty.";
        } elseif (empty($new_email)) {
            $error_message = "Email cannot be empty.";
        } elseif (!filter_var($new_email, FILTER_VALIDATE_EMAIL)) {
            $error_message = "Please enter a valid email address.";
        } else {
            // Check if email already exists (for other advisers)
$email_check_query = "SELECT id FROM academic_adviser WHERE email = ? AND id != ?";
            $email_check_stmt = mysqli_prepare($conn, $email_check_query);
            mysqli_stmt_bind_param($email_check_stmt, "si", $new_email, $adviser_id);
            mysqli_stmt_execute($email_check_stmt);
            $email_check_result = mysqli_stmt_get_result($email_check_stmt);
            
            if (mysqli_num_rows($email_check_result) > 0) {
                $error_message = "Email address is already in use by another adviser.";
            } else {
                // Get current password from database for verification
$current_info_query = "SELECT password FROM academic_adviser WHERE id = ?";
                $current_info_stmt = mysqli_prepare($conn, $current_info_query);
                mysqli_stmt_bind_param($current_info_stmt, "i", $adviser_id);
                mysqli_stmt_execute($current_info_stmt);
                $current_info_result = mysqli_stmt_get_result($current_info_stmt);
                $current_info = mysqli_fetch_assoc($current_info_result);
                
                // If user wants to change password
                if (!empty($new_password)) {
                    if (empty($current_password)) {
                        $error_message = "Current password is required to change password.";
                    } elseif (!password_verify($current_password, $current_info['password'])) {
                        $error_message = "Current password is incorrect.";
                    } elseif (strlen($new_password) < 6) {
                        $error_message = "New password must be at least 6 characters long.";
                    } elseif ($new_password !== $confirm_password) {
                        $error_message = "New password and confirm password do not match.";
                    } else {
                        // Update name, email, and password
                        $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
$update_query = "UPDATE academic_adviser SET name = ?, email = ?, password = ? WHERE id = ?";
                        $update_stmt = mysqli_prepare($conn, $update_query);
                        mysqli_stmt_bind_param($update_stmt, "sssi", $new_name, $new_email, $hashed_password, $adviser_id);
                        
                        if (mysqli_stmt_execute($update_stmt)) {
                            $_SESSION['name'] = $new_name;
                            $_SESSION['email'] = $new_email;
                            $adviser_name = $new_name;
                            $adviser_email = $new_email;
                            $success_message = "Account information and password updated successfully!";
                        } else {
                            $error_message = "Error updating account information.";
                        }
                    }
                } else {
                    // Update only name and email
$update_query = "UPDATE academic_adviser SET name = ?, email = ? WHERE id = ?";
                    $update_stmt = mysqli_prepare($conn, $update_query);
                    mysqli_stmt_bind_param($update_stmt, "ssi", $new_name, $new_email, $adviser_id);
                    
                    if (mysqli_stmt_execute($update_stmt)) {
                        $_SESSION['name'] = $new_name;
                        $_SESSION['email'] = $new_email;
                        $adviser_name = $new_name;
                        $adviser_email = $new_email;
                        $success_message = "Account information updated successfully!";
                    } else {
                        $error_message = "Error updating account information.";
                    }
                }
            }
        }
    }
}

// Create adviser initials
$adviser_initials = strtoupper(substr($adviser_name, 0, 2));

// Get notification counts for sidebar
try {
    $unread_messages_query = "SELECT COUNT(*) as count FROM messages WHERE recipient_type = 'adviser' AND sender_type = 'student' AND is_read = 0 AND is_deleted_by_recipient = 0";
    $unread_messages_result = mysqli_query($conn, $unread_messages_query);
    $unread_messages_count = mysqli_fetch_assoc($unread_messages_result)['count'];
} catch (Exception $e) {
    $unread_messages_count = 0;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Account Settings - OnTheJob Tracker</title>
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
        .sidebar {
            transition: transform 0.3s ease-in-out;
        }

        .sidebar-overlay {
            transition: opacity 0.3s ease-in-out;
        }

        .password-toggle {
            position: absolute;
            right: 12px;
            top: 50%;
            transform: translateY(-50%);
            background: none;
            border: none;
            color: #DAA520;
            cursor: pointer;
            font-size: 1rem;
            z-index: 10;
        }

        .password-toggle:hover {
            color: #B8860B;
        }

        .form-input:focus {
            border-color: #DAA520;
            box-shadow: 0 0 0 3px rgba(218, 165, 32, 0.1);
        }

        .btn-primary {
            background: linear-gradient(135deg, #800000 0%, #6B1028 100%);
        }

        .btn-primary:hover {
            background: linear-gradient(135deg, #6B1028 0%, #800000 100%);
        }

        .notification-badge {
            position: absolute;
            top: -8px;
            right: -8px;
            background: linear-gradient(135deg, #ef4444, #dc2626);
            color: white;
            font-size: 10px;
            font-weight: 600;
            padding: 2px 6px;
            border-radius: 10px;
            min-width: 18px;
            height: 18px;
            display: flex;
            align-items: center;
            justify-content: center;
            border: 2px solid white;
            animation: pulse 2s infinite;
        }

        @keyframes pulse {
            0%, 100% { transform: scale(1); }
            50% { transform: scale(1.1); }
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
<body class="bg-gray-50">
    <!-- Mobile Sidebar Overlay -->
    <div id="sidebarOverlay" class="fixed inset-0 bg-black bg-opacity-50 z-40 lg:hidden hidden sidebar-overlay"></div>

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
            <a href="AdviserDashboard.php" class="nav-item flex items-center px-3 py-2 text-sm font-medium text-bulsu-light-gold hover:text-white hover:bg-bulsu-gold hover:bg-opacity-20 rounded-md transition-all duration-200">
                <i class="fas fa-th-large mr-3"></i>
                Dashboard
            </a>
            <a href="ViewOJTCoordinators.php" class="nav-item flex items-center px-3 py-2 text-sm font-medium text-bulsu-light-gold hover:text-white hover:bg-bulsu-gold hover:bg-opacity-20 rounded-md transition-all duration-200">
                <i class="fas fa-users-cog mr-3"></i>
                View OJT Company Supervisor
            </a>
            <a href="StudentAccounts.php" class="nav-item flex items-center px-3 py-2 text-sm font-medium text-bulsu-light-gold hover:text-white hover:bg-bulsu-gold hover:bg-opacity-20 rounded-md transition-all duration-200">
                <i class="fas fa-user-graduate mr-3"></i>
                Student Accounts
            </a>
            <a href="StudentDeployment.php" class="nav-item flex items-center px-3 py-2 text-sm font-medium text-bulsu-light-gold hover:text-white hover:bg-bulsu-gold hover:bg-opacity-20 rounded-md transition-all duration-200">
                <i class="fas fa-paper-plane mr-3"></i>
                Student Deployment
            </a>
            <a href="StudentPerformance.php" class="nav-item flex items-center px-3 py-2 text-sm font-medium text-bulsu-light-gold hover:text-white hover:bg-bulsu-gold hover:bg-opacity-20 rounded-md transition-all duration-200">
                <i class="fas fa-chart-line mr-3"></i>
                Student Performance
            </a>
            <a href="StudentRecords.php" class="nav-item flex items-center px-3 py-2 text-sm font-medium text-bulsu-light-gold hover:text-white hover:bg-bulsu-gold hover:bg-opacity-20 rounded-md transition-all duration-200">
                <i class="fas fa-folder-open mr-3"></i>
                Student Records
            </a>
            <a href="GenerateReports.php" class="nav-item flex items-center px-3 py-2 text-sm font-medium text-bulsu-light-gold hover:text-white hover:bg-bulsu-gold hover:bg-opacity-20 rounded-md transition-all duration-200">
                <i class="fas fa-file-alt mr-3"></i>
                Generate Reports
            </a>
            <a href="AdminAlerts.php" class="nav-item flex items-center px-3 py-2 text-sm font-medium text-bulsu-light-gold hover:text-white hover:bg-bulsu-gold hover:bg-opacity-20 rounded-md transition-all duration-200">
                <i class="fas fa-bell mr-3"></i>
                Administrative Alerts
            </a>
            <a href="academicAdviserMessage.php" class="nav-item flex items-center px-3 py-2 text-sm font-medium text-bulsu-light-gold hover:text-white hover:bg-bulsu-gold hover:bg-opacity-20 rounded-md transition-all duration-200">
                <i class="fas fa-envelope mr-3"></i>
                Messages
                <?php if ($unread_messages_count > 0): ?>
                    <span class="notification-badge" id="sidebar-notification-badge">
                        <?php echo $unread_messages_count; ?>
                    </span>
                <?php endif; ?>
            </a>
            <a href="academicAdviserEdit.php" class="nav-item flex items-center px-3 py-2 text-sm font-medium text-bulsu-light-gold hover:text-white hover:bg-bulsu-gold hover:bg-opacity-20 rounded-md transition-all duration-200">
                <i class="fas fa-edit mr-3"></i>
                Edit Document
            </a>
        </nav>
    </div>
    
    <!-- User Profile -->
    <div class="absolute bottom-0 left-0 right-0 p-4 border-t border-bulsu-gold border-opacity-30 bg-gradient-to-t from-black to-transparent">
        <div class="flex items-center space-x-3">
            <div class="flex-shrink-0 w-10 h-10 bg-gradient-to-r from-bulsu-gold to-yellow-400 rounded-full flex items-center justify-center text-bulsu-maroon font-semibold text-sm overflow-hidden">
                <?php if (!empty($profile_picture) && file_exists($profile_picture)): ?>
                    <img src="<?php echo htmlspecialchars($profile_picture); ?>" alt="Profile Picture" class="w-full h-full object-cover">
                <?php else: ?>
                    <?php echo $adviser_initials; ?>
                <?php endif; ?>
            </div>
            <div class="flex-1 min-w-0">
                <p class="text-sm font-medium text-white truncate"><?php echo htmlspecialchars($adviser_name); ?></p>
                <p class="text-xs text-bulsu-light-gold">Academic Adviser</p>
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
                        <div class="w-8 h-8 sm:w-10 sm:h-10 bg-gradient-to-r from-blue-500 to-purple-600 rounded-full flex items-center justify-center text-white font-semibold text-xs sm:text-sm overflow-hidden">
    <?php if (!empty($profile_picture) && file_exists($profile_picture)): ?>
        <img src="<?php echo htmlspecialchars($profile_picture); ?>" alt="Profile Picture" class="w-full h-full object-cover">
    <?php else: ?>
        <?php echo $adviser_initials; ?>
    <?php endif; ?>
</div>
                    </button>
                    <div id="profileDropdown" class="hidden absolute right-0 mt-2 w-48 sm:w-64 bg-white rounded-md shadow-lg border border-gray-200 z-50">
                        <div class="p-4 border-b border-gray-200">
                            <div class="flex items-center space-x-3">
                                <div class="w-12 h-12 bg-gradient-to-r from-blue-500 to-purple-600 rounded-full flex items-center justify-center text-white font-semibold overflow-hidden">
    <?php if (!empty($profile_picture) && file_exists($profile_picture)): ?>
        <img src="<?php echo htmlspecialchars($profile_picture); ?>" alt="Profile Picture" class="w-full h-full object-cover">
    <?php else: ?>
        <?php echo $adviser_initials; ?>
    <?php endif; ?>
</div>
                                <div>
                                    <p class="font-medium text-gray-900"><?php echo htmlspecialchars($adviser_name); ?></p>
                                    <p class="text-sm text-gray-500">Academic Adviser</p>
                                </div>
                            </div>
                        </div>
                        <a href="AdviserAccountSettings.php" class="flex items-center px-4 py-2 text-sm text-gray-700 hover:bg-gray-50">
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
            <div class="bg-gradient-to-r from-bulsu-maroon to-bulsu-dark-maroon text-white rounded-lg p-6 mb-6 sm:mb-8">
                <div class="flex items-center space-x-4">
                    <div class="w-16 h-16 bg-white bg-opacity-20 rounded-full flex items-center justify-center text-2xl font-bold overflow-hidden">
                        <?php if (!empty($profile_picture) && file_exists($profile_picture)): ?>
                            <img src="<?php echo htmlspecialchars($profile_picture); ?>" alt="Profile Picture" class="w-full h-full object-cover">
                        <?php else: ?>
                            <?php echo $adviser_initials; ?>
                        <?php endif; ?>
                    </div>
                    <div>
                        <h2 class="text-xl font-bold"><?php echo htmlspecialchars($adviser_name); ?></h2>
                        <p class="text-bulsu-light-gold">Academic Adviser</p>
                        <p class="text-bulsu-light-gold text-sm"><?php echo htmlspecialchars($adviser_email); ?></p>
                    </div>
                </div>
            </div>

            <!-- Alert Messages -->
            <?php if ($success_message): ?>
                <div class="mb-6 p-4 bg-green-50 border border-green-200 rounded-lg">
                    <div class="flex items-start">
                        <i class="fas fa-check-circle text-green-600 mt-1 mr-3"></i>
                        <p class="text-green-700"><?php echo htmlspecialchars($success_message); ?></p>
                    </div>
                </div>
            <?php endif; ?>
            
            <?php if ($error_message): ?>
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
                                        <?php echo $adviser_initials; ?>
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

                <!-- Account Information Section -->
                <div class="bg-white rounded-lg shadow-sm border border-gray-200">
                    <div class="p-6 border-b border-gray-200">
                        <div class="flex items-center">
                            <div class="w-12 h-12 bg-gradient-to-r from-blue-500 to-purple-600 rounded-lg flex items-center justify-center text-white mr-4">
                                <i class="fas fa-user text-xl"></i>
                            </div>
                            <div>
                                <h3 class="text-lg font-medium text-bulsu-maroon">Account Information</h3>
                                <p class="text-sm text-gray-600">Update your personal details and change password</p>
                            </div>
                        </div>
                    </div>

                    <div class="p-6">
                        <!-- Current Account Information Display -->
                        <div class="bg-bulsu-light-gold bg-opacity-30 border border-bulsu-gold border-opacity-50 rounded-lg p-4 mb-6">
                            <h4 class="text-lg font-medium text-bulsu-dark-maroon mb-4 flex items-center">
                                <i class="fas fa-info-circle mr-2"></i>
                                Current Account Information
                            </h4>
                            <div class="grid grid-cols-1 md:grid-cols-3 gap-4 text-sm">
                                <div>
                                    <span class="font-medium text-bulsu-maroon">Name:</span>
                                    <span class="text-bulsu-dark-maroon ml-2"><?php echo htmlspecialchars($adviser_name); ?></span>
                                </div>
                                <div>
                                    <span class="font-medium text-bulsu-maroon">Email:</span>
                                    <span class="text-bulsu-dark-maroon ml-2"><?php echo htmlspecialchars($adviser_email); ?></span>
                                </div>
                                <div>
                                    <span class="font-medium text-bulsu-maroon">Role:</span>
                                    <span class="text-bulsu-dark-maroon ml-2">Academic Adviser</span>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Settings Form -->
                        <form method="POST" action="" id="accountForm">
                            <input type="hidden" name="action" value="update_account">
                            
                            <!-- Personal Information Section -->
                            <div class="mb-8">
                                <h4 class="text-lg font-medium text-gray-900 mb-4 pb-2 border-b border-gray-200 flex items-center">
                                    <i class="fas fa-user mr-2 text-bulsu-gold"></i>
                                    Personal Information
                                </h4>
                                
                                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                                    <div>
                                        <label for="name" class="block text-sm font-medium text-gray-700 mb-2">Full Name <span class="text-red-500">*</span></label>
                                        <input type="text" id="name" name="name" value="<?php echo htmlspecialchars($adviser_name); ?>" required
                                               class="form-input w-full px-4 py-3 border-2 border-gray-300 rounded-lg text-sm transition-all duration-200 focus:outline-none focus:ring-2 focus:ring-bulsu-gold focus:border-transparent">
                                    </div>
                                    
                                    <div>
                                        <label for="email" class="block text-sm font-medium text-gray-700 mb-2">Email Address <span class="text-red-500">*</span></label>
                                        <input type="email" id="email" name="email" value="<?php echo htmlspecialchars($adviser_email); ?>" required
                                               class="form-input w-full px-4 py-3 border-2 border-gray-300 rounded-lg text-sm transition-all duration-200 focus:outline-none focus:ring-2 focus:ring-bulsu-gold focus:border-transparent">
                                    </div>
                                </div>
                            </div>
                            
                            <!-- Password Change Section -->
                            <div class="mb-8">
                                <div class="bg-bulsu-light-gold bg-opacity-30 border border-bulsu-gold border-opacity-50 rounded-lg p-6">
                                    <h4 class="text-lg font-medium text-gray-900 mb-4 flex items-center">
                                        <i class="fas fa-lock mr-2 text-bulsu-gold"></i>
                                        Change Password (Optional)
                                    </h4>
                                    
                                    <div class="mb-4 p-3 bg-yellow-50 border border-yellow-200 rounded-md">
                                        <div class="flex items-start">
                                            <i class="fas fa-info-circle text-yellow-600 mt-1 mr-2"></i>
                                            <p class="text-sm text-yellow-700">
                                                Leave password fields empty if you don't want to change your password.
                                            </p>
                                        </div>
                                    </div>
                                    
                                    <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                                        <div>
                                            <label for="current_password" class="block text-sm font-medium text-gray-700 mb-2">Current Password</label>
                                            <div class="relative">
                                                <input type="password" id="current_password" name="current_password" 
                                                       placeholder="Enter your current password"
                                                       class="form-input w-full px-4 py-3 pr-12 border-2 border-gray-300 rounded-lg text-sm transition-all duration-200 focus:outline-none focus:ring-2 focus:ring-bulsu-gold focus:border-transparent">
                                                <button type="button" class="password-toggle" onclick="togglePassword('current_password')">
                                                    <i class="fas fa-eye"></i>
                                                </button>
                                            </div>
                                        </div>
                                        
                                        <div>
                                            <label for="new_password" class="block text-sm font-medium text-gray-700 mb-2">New Password</label>
                                            <div class="relative">
                                                <input type="password" id="new_password" name="new_password" 
                                                       placeholder="Enter new password (min. 6 characters)"
                                                       class="form-input w-full px-4 py-3 pr-12 border-2 border-gray-300 rounded-lg text-sm transition-all duration-200 focus:outline-none focus:ring-2 focus:ring-bulsu-gold focus:border-transparent"
                                                       oninput="checkPasswordStrength(this.value)">
                                                <button type="button" class="password-toggle" onclick="togglePassword('new_password')">
                                                    <i class="fas fa-eye"></i>
                                                </button>
                                            </div>
                                            <div class="mt-2" id="passwordStrength"></div>
                                        </div>
                                        
                                        <div>
                                            <label for="confirm_password" class="block text-sm font-medium text-gray-700 mb-2">Confirm New Password</label>
                                            <div class="relative">
                                                <input type="password" id="confirm_password" name="confirm_password" 
                                                       placeholder="Confirm your new password"
                                                       class="form-input w-full px-4 py-3 pr-12 border-2 border-gray-300 rounded-lg text-sm transition-all duration-200 focus:outline-none focus:ring-2 focus:ring-bulsu-gold focus:border-transparent"
                                                       oninput="checkPasswordMatch()">
                                                <button type="button" class="password-toggle" onclick="togglePassword('confirm_password')">
                                                    <i class="fas fa-eye"></i>
                                                </button>
                                            </div>
                                            <div class="mt-2" id="passwordMatch"></div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            
                            <!-- Action Buttons -->
                            <div class="flex flex-col sm:flex-row gap-4 pt-6 border-t border-gray-200">
                                <a href="AdviserDashboard.php" 
                                   class="flex items-center justify-center px-6 py-3 bg-gray-600 hover:bg-gray-700 text-white font-medium rounded-lg transition-all duration-200 transform hover:-translate-y-0.5">
                                    <i class="fas fa-arrow-left mr-2"></i>
                                    Back to Dashboard
                                </a>
                                <button type="submit" 
                                        class="flex items-center justify-center px-6 py-3 btn-primary text-white font-medium rounded-lg transition-all duration-200 transform hover:-translate-y-0.5 flex-1 sm:flex-none">
                                    <i class="fas fa-save mr-2"></i>
                                    Save Changes
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

        // Password visibility toggle function
        function togglePassword(fieldId) {
            const field = document.getElementById(fieldId);
            const toggleBtn = field.nextElementSibling.querySelector('i');
            
            if (field.type === 'password') {
                field.type = 'text';
                toggleBtn.classList.remove('fa-eye');
                toggleBtn.classList.add('fa-eye-slash');
            } else {
                field.type = 'password';
                toggleBtn.classList.remove('fa-eye-slash');
                toggleBtn.classList.add('fa-eye');
            }
        }

        // Check password strength
        function checkPasswordStrength(password) {
            const strengthDiv = document.getElementById('passwordStrength');
            let strength = 0;
            let feedback = '';

            if (password.length >= 6) strength++;
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
        }

        // Check if passwords match
        function checkPasswordMatch() {
            const newPassword = document.getElementById('new_password').value;
            const confirmPassword = document.getElementById('confirm_password').value;
            const matchDiv = document.getElementById('passwordMatch');

            if (confirmPassword.length > 0) {
                if (newPassword === confirmPassword) {
                    matchDiv.innerHTML = '<span class="text-xs text-green-600"><i class="fas fa-check mr-1"></i>Passwords match</span>';
                } else {
                    matchDiv.innerHTML = '<span class="text-xs text-red-600"><i class="fas fa-times mr-1"></i>Passwords do not match</span>';
                }
            } else {
                matchDiv.innerHTML = '';
            }
        }

        // Form validation
        document.querySelector('#accountForm').addEventListener('submit', function(e) {
            const newPassword = document.getElementById('new_password').value;
            const confirmPassword = document.getElementById('confirm_password').value;
            const currentPassword = document.getElementById('current_password').value;
            
            // If user wants to change password
            if (newPassword || confirmPassword || currentPassword) {
                if (!currentPassword) {
                    e.preventDefault();
                    alert('Current password is required to change password.');
                    return;
                }
                
                if (newPassword.length < 6) {
                    e.preventDefault();
                    alert('New password must be at least 6 characters long.');
                    return;
                }
                
                if (newPassword !== confirmPassword) {
                    e.preventDefault();
                    alert('New password and confirm password do not match.');
                    return;
                }
            }
        });

        // Real-time password match validation
        document.getElementById('confirm_password').addEventListener('input', function() {
            const newPassword = document.getElementById('new_password').value;
            const confirmPassword = this.value;
            
            if (confirmPassword && newPassword !== confirmPassword) {
                this.style.borderColor = '#ef4444';
                this.style.backgroundColor = '#fef2f2';
            } else if (confirmPassword && newPassword === confirmPassword) {
                this.style.borderColor = '#22c55e';
                this.style.backgroundColor = '#f0fdf4';
            } else {
                this.style.borderColor = '';
                this.style.backgroundColor = '';
            }
        });

        // Password strength indicator
        document.getElementById('new_password').addEventListener('input', function() {
            const password = this.value;
            const field = this;
            
            if (password.length === 0) {
                field.style.borderColor = '';
                field.style.backgroundColor = '';
            } else if (password.length < 6) {
                field.style.borderColor = '#ef4444';
                field.style.backgroundColor = '#fef2f2';
            } else {
                field.style.borderColor = '#22c55e';
                field.style.backgroundColor = '#f0fdf4';
            }
        });

        // Confirm logout function
        function confirmLogout() {
            return confirm('Are you sure you want to logout?');
        }

        // Auto-hide alerts after 5 seconds
        setTimeout(function() {
            const alerts = document.querySelectorAll('.bg-green-50, .bg-red-50');
            alerts.forEach(function(alert) {
                alert.style.transition = 'opacity 0.5s ease-out';
                alert.style.opacity = '0';
                setTimeout(function() {
                    alert.remove();
                }, 500);
            });
        }, 5000);

        // Add loading state to submit button
        document.querySelectorAll('form').forEach(form => {
            form.addEventListener('submit', function() {
                const submitBtn = this.querySelector('button[type="submit"]');
                if (submitBtn) {
                    const originalText = submitBtn.innerHTML;
                    submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin mr-2"></i>Saving Changes...';
                    submitBtn.disabled = true;
                    
                    // Re-enable button after 3 seconds in case of error
                    setTimeout(function() {
                        submitBtn.innerHTML = originalText;
                        submitBtn.disabled = false;
                    }, 3000);
                }
            });
        });

        // Escape key to close dropdown and sidebar
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                // Close profile dropdown
                const profileDropdown = document.getElementById('profileDropdown');
                if (!profileDropdown.classList.contains('hidden')) {
                    profileDropdown.classList.add('hidden');
                }
                
                // Close sidebar on mobile
                const sidebar = document.getElementById('sidebar');
                if (!sidebar.classList.contains('-translate-x-full')) {
                    closeSidebar();
                }
            }
        });

        // Close sidebar on larger screens
        window.addEventListener('resize', function() {
            if (window.innerWidth >= 1024) {
                closeSidebar();
            }
        });
    </script>
</body>
</html>