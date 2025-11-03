<?php
include('connect.php');
session_start();

// Prevent caching
header("Cache-Control: no-cache, no-store, must-revalidate");
header("Pragma: no-cache");
header("Expires: 0");

// Check if user is logged in and is a coordinator
if (!isset($_SESSION['adviser_id']) || $_SESSION['user_type'] !== 'adviser') {
    header("Location: login.php");
    exit;
}

// Get adviser role from database
$adviser_id = $_SESSION['adviser_id'];
$role_query = "SELECT role FROM academic_adviser WHERE id = ?";
$role_stmt = mysqli_prepare($conn, $role_query);
mysqli_stmt_bind_param($role_stmt, "i", $adviser_id);
mysqli_stmt_execute($role_stmt);
$role_result = mysqli_stmt_get_result($role_stmt);
$adviser_data = mysqli_fetch_assoc($role_result);
$adviser_role = $adviser_data['role'] ?? 'adviser';
mysqli_stmt_close($role_stmt);

// Only coordinators can access this page
if ($adviser_role !== 'coordinator') {
    header("Location: AdviserDashboard.php");
    exit;
}

$adviser_name = $_SESSION['name'];
$adviser_email = $_SESSION['email'];
$adviser_initials = strtoupper(substr($adviser_name, 0, 2));

require './PHPMailer/PHPMailer/src/Exception.php';
require './PHPMailer/PHPMailer/src/PHPMailer.php';
require './PHPMailer/PHPMailer/src/SMTP.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

$message = '';
$message_type = '';

// Handle actions (Approve, Reject, Block, Unblock)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $account_id = $_POST['account_id'] ?? 0;
    
    if ($account_id > 0) {
        // Get account details
        $account_query = "SELECT * FROM academic_adviser WHERE id = ?";
        $account_stmt = mysqli_prepare($conn, $account_query);
        mysqli_stmt_bind_param($account_stmt, "i", $account_id);
        mysqli_stmt_execute($account_stmt);
        $account_result = mysqli_stmt_get_result($account_stmt);
        $account = mysqli_fetch_assoc($account_result);
        mysqli_stmt_close($account_stmt);
        
        if ($account) {
            if ($action === 'approve') {
                // Update account to approved and active
                $update_query = "UPDATE academic_adviser SET approval_status = 'approved', status = 'active', approved_at = NOW() WHERE id = ?";
                $update_stmt = mysqli_prepare($conn, $update_query);
                mysqli_stmt_bind_param($update_stmt, "i", $account_id);
                
                if (mysqli_stmt_execute($update_stmt)) {
                    // Send approval email
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
                        $mail->addAddress($account['email']);
                        
                        $mail->isHTML(true);
                        $mail->Subject = 'Account Approved - OnTheJob Tracker';
                        $mail->Body = '
                        <div style="font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto; padding: 20px; border: 1px solid #ddd; border-radius: 10px;">
                            <div style="text-align: center; margin-bottom: 30px;">
                                <h1 style="color: #800000; margin: 0;">OnTheJob Tracker</h1>
                                <p style="color: #666; margin: 5px 0;">Student OJT Performance Monitoring System</p>
                            </div>
                            
                            <h2 style="color: #333;">Congratulations, ' . htmlspecialchars($account['name']) . '!</h2>
                            <p style="color: #555; line-height: 1.6;">
                                Your Academic Adviser account has been approved by the coordinator.
                            </p>
                            
                            <div style="background-color: #d1fae5; padding: 15px; border-radius: 5px; border-left: 4px solid #10b981; margin: 20px 0;">
                                <p style="margin: 0; color: #065f46;">
                                    <strong>Your account is now active!</strong> You can now log in and start monitoring student OJT performance.
                                </p>
                            </div>
                            
                            <div style="margin: 30px 0;">
                                <h4 style="color: #333;">Your Account Details:</h4>
                                <ul style="color: #555; line-height: 1.8;">
                                    <li><strong>Email:</strong> ' . htmlspecialchars($account['email']) . '</li>
                                    <li><strong>Department:</strong> ' . htmlspecialchars($account['department']) . '</li>
                                    <li><strong>Year Level:</strong> ' . htmlspecialchars($account['year_level']) . '</li>
                                    <li><strong>Section:</strong> ' . htmlspecialchars($account['section']) . '</li>
                                </ul>
                            </div>
                            
                            <div style="text-align: center; margin: 30px 0;">
                                <a href="http://localhost/ojttracker/login.php" style="display: inline-block; background-color: #800000; color: white; padding: 12px 30px; text-decoration: none; border-radius: 5px; font-weight: bold;">
                                    Login to Your Account
                                </a>
                            </div>
                            
                            <div style="text-align: center; margin-top: 30px; padding-top: 20px; border-top: 1px solid #eee;">
                                <p style="color: #666; margin: 0;">
                                    <strong>OnTheJob Tracker Team</strong><br>
                                    <small>AI-Powered OJT Performance Monitoring</small>
                                </p>
                            </div>
                        </div>';
                        
                        $mail->send();
                        $message = "Account approved successfully and notification email sent to " . htmlspecialchars($account['name']);
                        $message_type = "success";
                    } catch (Exception $e) {
                        $message = "Account approved successfully but email could not be sent.";
                        $message_type = "warning";
                    }
                } else {
                    $message = "Failed to approve account.";
                    $message_type = "error";
                }
                mysqli_stmt_close($update_stmt);
                
            } elseif ($action === 'reject') {
                // Update account to rejected
                $update_query = "UPDATE academic_adviser SET approval_status = 'rejected', status = 'inactive' WHERE id = ?";
                $update_stmt = mysqli_prepare($conn, $update_query);
                mysqli_stmt_bind_param($update_stmt, "i", $account_id);
                
                if (mysqli_stmt_execute($update_stmt)) {
                    // Send rejection email
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
                        $mail->addAddress($account['email']);
                        
                        $mail->isHTML(true);
                        $mail->Subject = 'Account Registration Update - OnTheJob Tracker';
                        $mail->Body = '
                        <div style="font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto; padding: 20px; border: 1px solid #ddd; border-radius: 10px;">
                            <div style="text-align: center; margin-bottom: 30px;">
                                <h1 style="color: #800000; margin: 0;">OnTheJob Tracker</h1>
                                <p style="color: #666; margin: 5px 0;">Student OJT Performance Monitoring System</p>
                            </div>
                            
                            <h2 style="color: #333;">Registration Update</h2>
                            <p style="color: #555; line-height: 1.6;">
                                Dear ' . htmlspecialchars($account['name']) . ',
                            </p>
                            
                            <div style="background-color: #fee2e2; padding: 15px; border-radius: 5px; border-left: 4px solid #ef4444; margin: 20px 0;">
                                <p style="margin: 0; color: #991b1b;">
                                    We regret to inform you that your Academic Adviser registration has not been approved at this time.
                                </p>
                            </div>
                            
                            <p style="color: #555; line-height: 1.6;">
                                If you believe this is an error or would like more information, please contact the OJT coordinator directly.
                            </p>
                            
                            <div style="text-align: center; margin-top: 30px; padding-top: 20px; border-top: 1px solid #eee;">
                                <p style="color: #666; margin: 0;">
                                    <strong>OnTheJob Tracker Team</strong><br>
                                    <small>AI-Powered OJT Performance Monitoring</small>
                                </p>
                            </div>
                        </div>';
                        
                        $mail->send();
                        $message = "Account rejected and notification email sent to " . htmlspecialchars($account['name']);
                        $message_type = "success";
                    } catch (Exception $e) {
                        $message = "Account rejected but email could not be sent.";
                        $message_type = "warning";
                    }
                } else {
                    $message = "Failed to reject account.";
                    $message_type = "error";
                }
                mysqli_stmt_close($update_stmt);
                
            } elseif ($action === 'block') {
    $update_query = "UPDATE academic_adviser SET status = 'inactive' WHERE id = ?";
                $update_stmt = mysqli_prepare($conn, $update_query);
                mysqli_stmt_bind_param($update_stmt, "i", $account_id);
                
                if (mysqli_stmt_execute($update_stmt)) {
                    $message = "Account blocked successfully for " . htmlspecialchars($account['name']);
                    $message_type = "success";
                } else {
                    $message = "Failed to block account.";
                    $message_type = "error";
                }
                mysqli_stmt_close($update_stmt);
                
            } elseif ($action === 'unblock') {
                // Unblock account
                $update_query = "UPDATE academic_adviser SET status = 'active' WHERE id = ?";
                $update_stmt = mysqli_prepare($conn, $update_query);
                mysqli_stmt_bind_param($update_stmt, "i", $account_id);
                
                if (mysqli_stmt_execute($update_stmt)) {
                    $message = "Account unblocked successfully for " . htmlspecialchars($account['name']);
                    $message_type = "success";
                } else {
                    $message = "Failed to unblock account.";
                    $message_type = "error";
                }
                mysqli_stmt_close($update_stmt);
            }
        }
    }
}


// Fetch all academic adviser accounts EXCEPT the current coordinator
$accounts_query = "SELECT * FROM academic_adviser 
                   WHERE id != ? 
                   ORDER BY 
                       CASE 
                           WHEN approval_status = 'pending' THEN 1
                           WHEN approval_status = 'approved' THEN 2
                           WHEN approval_status = 'rejected' THEN 3
                       END,
                       created_at DESC";
$accounts_stmt = mysqli_prepare($conn, $accounts_query);
mysqli_stmt_bind_param($accounts_stmt, "i", $adviser_id);
mysqli_stmt_execute($accounts_stmt);
$accounts_result = mysqli_stmt_get_result($accounts_stmt);
mysqli_stmt_close($accounts_stmt);

try {
    $profile_query = "SELECT profile_picture FROM academic_adviser WHERE id = ?";
    $profile_stmt = mysqli_prepare($conn, $profile_query);
    mysqli_stmt_bind_param($profile_stmt, "i", $adviser_id);
    mysqli_stmt_execute($profile_stmt);
    $profile_result = mysqli_stmt_get_result($profile_stmt);
    
    if ($profile_result && mysqli_num_rows($profile_result) > 0) {
        $profile_data = mysqli_fetch_assoc($profile_result);
        $profile_picture = $profile_data['profile_picture'] ?? '';
    } else {
        $profile_picture = '';
    }
    mysqli_stmt_close($profile_stmt);
} catch (Exception $e) {
    $profile_picture = '';
}

$adviser_initials = strtoupper(substr($adviser_name, 0, 2));
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Academic Accounts Management - OnTheJob Tracker</title>
    <link rel="icon" type="image/png" href="reqsample/bulsu12.png">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
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
    <style>
        .status-badge {
            display: inline-flex;
            align-items: center;
            padding: 4px 12px;
            border-radius: 9999px;
            font-size: 0.75rem;
            font-weight: 600;
        }
    </style>
</head>
<body class="bg-gray-50">
    <!-- Mobile Sidebar Overlay -->
    <div id="sidebarOverlay" class="fixed inset-0 bg-black bg-opacity-50 z-40 lg:hidden hidden"></div>

    <!-- Sidebar -->
    <div id="sidebar" class="fixed left-0 top-0 h-full w-64 bg-gradient-to-b from-bulsu-maroon to-bulsu-dark-maroon shadow-lg z-50 transform -translate-x-full lg:translate-x-0 transition-transform duration-300">
        <div class="flex justify-end p-4 lg:hidden">
            <button id="closeSidebar" class="text-bulsu-light-gold hover:text-bulsu-gold">
                <i class="fas fa-times text-xl"></i>
            </button>
        </div>

        <div class="px-6 py-4 border-b border-bulsu-gold border-opacity-30">
            <div class="flex items-center">
                <img src="reqsample/bulsu12.png" alt="BULSU Logo" class="w-14 h-14 mr-2">
                <div class="flex items-center font-bold text-lg text-white">
                    <span>OnTheJob</span>
                    <span class="ml-1">Tracker</span>
                </div>
            </div>
        </div>
        
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
                </a>
                <a href="academicAdviserEdit.php" class="nav-item flex items-center px-3 py-2 text-sm font-medium text-bulsu-light-gold hover:text-white hover:bg-bulsu-gold hover:bg-opacity-20 rounded-md transition-all duration-200">
                    <i class="fas fa-edit mr-3"></i>
                    Edit Document
                </a>
                <a href="AcademicStudentEvaluation.php" class="nav-item flex items-center px-3 py-2 text-sm font-medium text-bulsu-light-gold hover:text-white hover:bg-bulsu-gold hover:bg-opacity-20 rounded-md transition-all duration-200">
                    <i class="fas fa-star mr-3"></i>
                    Student Evaluation
                </a>
                <a href="AcademicAccounts.php" class="nav-item flex items-center px-3 py-2 text-sm font-medium text-white bg-bulsu-gold bg-opacity-20 border border-bulsu-gold border-opacity-30 rounded-md">
                    <i class="fas fa-user-tie mr-3 text-bulsu-gold"></i>
                    Academic Accounts
                </a>
            </nav>
        </div>
        
        <div class="absolute bottom-0 left-0 right-0 p-4 border-t border-bulsu-gold border-opacity-30 bg-gradient-to-t from-black to-transparent">
            <div class="flex items-center space-x-3">
                <div class="flex-shrink-0 w-10 h-10 bg-gradient-to-r from-bulsu-gold to-yellow-400 rounded-full flex items-center justify-center text-bulsu-maroon font-semibold text-sm">
                    <?php echo $adviser_initials; ?>
                </div>
                <div class="flex-1 min-w-0">
                    <p class="text-sm font-medium text-white truncate"><?php echo htmlspecialchars($adviser_name); ?></p>
                    <p class="text-xs text-bulsu-light-gold">Coordinator</p>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Main Content -->
    <div class="lg:ml-64 min-h-screen">
        <!-- Header -->
        <div class="bg-white shadow-sm border-b border-gray-200">
            <div class="flex items-center justify-between px-4 sm:px-6 py-4">
                <button id="mobileMenuBtn" class="lg:hidden p-2 rounded-md text-gray-500 hover:text-gray-900 hover:bg-gray-100">
                    <i class="fas fa-bars text-xl"></i>
                </button>

                <div class="flex-1 lg:ml-0 ml-4">
                    <h1 class="text-xl sm:text-2xl font-bold text-gray-900">Academic Accounts Management</h1>
                    <p class="text-sm sm:text-base text-gray-500">Manage academic adviser registrations and accounts</p>
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
            <!-- Success/Error Message -->
            <?php if (!empty($message)): ?>
                <div class="mb-6 p-4 rounded-lg <?php echo $message_type === 'success' ? 'bg-green-50 border border-green-200' : ($message_type === 'warning' ? 'bg-yellow-50 border border-yellow-200' : 'bg-red-50 border border-red-200'); ?>">
                    <div class="flex items-start">
                        <i class="fas <?php echo $message_type === 'success' ? 'fa-check-circle text-green-600' : ($message_type === 'warning' ? 'fa-exclamation-triangle text-yellow-600' : 'fa-exclamation-circle text-red-600'); ?> mt-1 mr-3"></i>
                        <p class="<?php echo $message_type === 'success' ? 'text-green-700' : ($message_type === 'warning' ? 'text-yellow-700' : 'text-red-700'); ?>"><?php echo htmlspecialchars($message); ?></p>
                    </div>
                </div>
            <?php endif; ?>

<div class="grid grid-cols-1 md:grid-cols-4 gap-4 mb-8">
    <?php
   $pending_count = mysqli_num_rows(mysqli_query($conn, "SELECT * FROM academic_adviser WHERE approval_status = 'pending' AND id != $adviser_id"));
$approved_count = mysqli_num_rows(mysqli_query($conn, "SELECT * FROM academic_adviser WHERE approval_status = 'approved' AND status = 'active' AND id != $adviser_id"));
$rejected_count = mysqli_num_rows(mysqli_query($conn, "SELECT * FROM academic_adviser WHERE approval_status = 'rejected' AND id != $adviser_id"));
$blocked_count = mysqli_num_rows(mysqli_query($conn, "SELECT * FROM academic_adviser WHERE approval_status = 'approved' AND status = 'inactive' AND id != $adviser_id"));
    ?>
                <div class="bg-white p-6 rounded-lg shadow-sm border border-gray-200 border-l-4 border-l-yellow-500">
                    <div class="flex items-center justify-between">
                        <div>
                            <div class="text-3xl font-bold text-yellow-600"><?php echo $pending_count; ?></div>
                            <div class="text-sm text-gray-600">Pending Approval</div>
                        </div>
                        <i class="fas fa-clock text-yellow-500 text-2xl"></i>
                    </div>
                </div>

                <div class="bg-white p-6 rounded-lg shadow-sm border border-gray-200 border-l-4 border-l-green-500">
                    <div class="flex items-center justify-between">
                        <div>
                            <div class="text-3xl font-bold text-green-600"><?php echo $approved_count; ?></div>
                            <div class="text-sm text-gray-600">Approved</div>
                        </div>
                        <i class="fas fa-check-circle text-green-500 text-2xl"></i>
                    </div>
                </div>

                <div class="bg-white p-6 rounded-lg shadow-sm border border-gray-200 border-l-4 border-l-red-500">
                    <div class="flex items-center justify-between">
                        <div>
                            <div class="text-3xl font-bold text-red-600"><?php echo $rejected_count; ?></div>
                            <div class="text-sm text-gray-600">Rejected</div>
                        </div>
                        <i class="fas fa-times-circle text-red-500 text-2xl"></i>
                    </div>
                </div>

                <div class="bg-white p-6 rounded-lg shadow-sm border border-gray-200 border-l-4 border-l-gray-500">
                    <div class="flex items-center justify-between">
                        <div>
                            <div class="text-3xl font-bold text-gray-600"><?php echo $blocked_count; ?></div>
                            <div class="text-sm text-gray-600">Blocked</div>
                        </div>
                        <i class="fas fa-ban text-gray-500 text-2xl"></i>
                    </div>
                </div>
            </div>

            <!-- Accounts Table -->
            <div class="bg-white rounded-lg shadow-sm border border-gray-200">
                <div class="p-6 border-b border-gray-200">
                    <h2 class="text-xl font-semibold text-gray-900">Academic Adviser Accounts</h2>
                    <p class="text-sm text-gray-500 mt-1">Review and manage academic adviser registrations</p>
                </div>
                <div class="overflow-x-auto">
                    <table class="w-full">
                        <thead class="bg-gray-50 border-b border-gray-200">
                            <tr>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Name</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Email</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Department</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Year/Section</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Status</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Registered</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-200">
                            <?php while ($account = mysqli_fetch_assoc($accounts_result)): ?>
                                <tr class="hover:bg-gray-50">
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <div class="flex items-center">
                                            <div class="w-10 h-10 bg-gradient-to-r from-blue-500 to-purple-600 rounded-full flex items-center justify-center text-white font-semibold text-sm flex-shrink-0">
                                                <?php echo strtoupper(substr($account['name'], 0, 2)); ?>
                                            </div>
                                            <div class="ml-4">
                                                <div class="text-sm font-medium text-gray-900"><?php echo htmlspecialchars($account['name']); ?></div>
                                                <?php if ($account['role'] === 'coordinator'): ?>
                                                    <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-purple-100 text-purple-800">
                                                        <i class="fas fa-star mr-1"></i>Coordinator
                                                    </span>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <div class="text-sm text-gray-900"><?php echo htmlspecialchars($account['email']); ?></div>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <div class="text-sm text-gray-900"><?php echo htmlspecialchars($account['department']); ?></div>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <div class="text-sm text-gray-900"><?php echo htmlspecialchars($account['year_level']) . ' - ' . htmlspecialchars($account['section']); ?></div>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap">
    <div class="flex flex-col space-y-1">
        <?php if ($account['approval_status'] === 'pending'): ?>
            <span class="status-badge bg-yellow-100 text-yellow-800">
                <i class="fas fa-clock mr-1"></i>Pending
            </span>
        <?php elseif ($account['approval_status'] === 'approved'): ?>
            <span class="status-badge bg-green-100 text-green-800">
                <i class="fas fa-check-circle mr-1"></i>Approved
            </span>
        <?php elseif ($account['approval_status'] === 'rejected'): ?>
            <span class="status-badge bg-red-100 text-red-800">
                <i class="fas fa-times-circle mr-1"></i>Rejected
            </span>
        <?php endif; ?>
        
        <?php if ($account['approval_status'] === 'approved' && $account['status'] === 'inactive'): ?>
            <span class="status-badge bg-gray-100 text-gray-800">
                <i class="fas fa-ban mr-1"></i>Blocked
            </span>
        <?php elseif ($account['status'] === 'active'): ?>
            <span class="status-badge bg-blue-100 text-blue-800">
                <i class="fas fa-circle mr-1 text-xs"></i>Active
            </span>
        <?php endif; ?>
    </div>
</td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <div class="text-sm text-gray-900"><?php echo date('M d, Y', strtotime($account['created_at'])); ?></div>
                                        <div class="text-xs text-gray-500"><?php echo date('h:i A', strtotime($account['created_at'])); ?></div>
                                    </td>
<td class="px-6 py-4 whitespace-nowrap text-sm">
    <div class="flex items-center gap-2">
        <?php if ($account['approval_status'] === 'pending'): ?>
            <button onclick="showApproveModal(<?php echo $account['id']; ?>, '<?php echo htmlspecialchars($account['name'], ENT_QUOTES); ?>')" class="inline-flex items-center px-3 py-1.5 bg-green-600 text-white rounded-md hover:bg-green-700 transition-colors text-xs font-medium shadow-sm" title="Approve Account">
                <i class="fas fa-check mr-1.5"></i>
                Approve
            </button>
            <button onclick="showRejectModal(<?php echo $account['id']; ?>, '<?php echo htmlspecialchars($account['name'], ENT_QUOTES); ?>')" class="inline-flex items-center px-3 py-1.5 bg-red-600 text-white rounded-md hover:bg-red-700 transition-colors text-xs font-medium shadow-sm" title="Reject Account">
                <i class="fas fa-times mr-1.5"></i>
                Reject
            </button>
        <?php endif; ?>

        <?php if ($account['approval_status'] === 'approved' && $account['status'] === 'inactive'): ?>
            <button onclick="showUnblockModal(<?php echo $account['id']; ?>, '<?php echo htmlspecialchars($account['name'], ENT_QUOTES); ?>')" class="inline-flex items-center px-3 py-1.5 bg-blue-600 text-white rounded-md hover:bg-blue-700 transition-colors text-xs font-medium shadow-sm" title="Unblock Account">
                <i class="fas fa-unlock mr-1.5"></i>
                Unblock
            </button>
        <?php elseif ($account['approval_status'] === 'approved' && $account['status'] === 'active'): ?>
            <button onclick="showBlockModal(<?php echo $account['id']; ?>, '<?php echo htmlspecialchars($account['name'], ENT_QUOTES); ?>')" class="inline-flex items-center px-3 py-1.5 bg-gray-600 text-white rounded-md hover:bg-gray-700 transition-colors text-xs font-medium shadow-sm" title="Block Account">
                <i class="fas fa-ban mr-1.5"></i>
                Block
            </button>
        <?php endif; ?>

        <button onclick="viewDetails(<?php echo htmlspecialchars(json_encode($account)); ?>)" class="inline-flex items-center px-3 py-1.5 bg-blue-600 text-white rounded-md hover:bg-blue-700 transition-colors text-xs font-medium shadow-sm" title="View Details">
            <i class="fas fa-eye mr-1.5"></i>
            View
        </button>
    </div>
</td>
                                </tr>
                            <?php endwhile; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <!-- Account Details Modal -->
    <div id="detailsModal" class="hidden fixed inset-0 bg-black bg-opacity-50 z-50 flex items-center justify-center p-4">
        <div class="bg-white rounded-lg shadow-xl max-w-2xl w-full max-h-[90vh] overflow-y-auto">
            <div class="p-6 border-b border-gray-200">
                <div class="flex items-center justify-between">
                    <h3 class="text-xl font-semibold text-gray-900">Account Details</h3>
                    <button onclick="closeModal()" class="text-gray-500 hover:text-gray-700">
                        <i class="fas fa-times text-xl"></i>
                    </button>
                </div>
            </div>
            <div id="modalContent" class="p-6">
                <!-- Content will be inserted here -->
            </div>
        </div>
    </div>
    <div id="approveModal" class="hidden fixed inset-0 bg-black bg-opacity-50 z-50 flex items-center justify-center p-4">
    <div class="bg-white rounded-lg shadow-xl max-w-md w-full">
        <div class="p-6">
            <div class="flex items-center justify-center w-12 h-12 mx-auto bg-green-100 rounded-full mb-4">
                <i class="fas fa-check-circle text-green-600 text-2xl"></i>
            </div>
            <h3 class="text-lg font-semibold text-gray-900 text-center mb-2">Approve Account</h3>
            <p class="text-sm text-gray-600 text-center mb-6">
                Are you sure you want to approve the account of <strong id="approveName"></strong>?
            </p>
            <form method="POST" id="approveForm">
                <input type="hidden" name="account_id" id="approveAccountId">
                <input type="hidden" name="action" value="approve">
                <div class="flex gap-3">
                    <button type="button" onclick="closeApproveModal()" class="flex-1 px-4 py-2 bg-gray-200 text-gray-800 rounded-md hover:bg-gray-300 transition-colors font-medium">
                        Cancel
                    </button>
                    <button type="submit" class="flex-1 px-4 py-2 bg-green-600 text-white rounded-md hover:bg-green-700 transition-colors font-medium">
                        Approve
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Reject Confirmation Modal -->
<div id="rejectModal" class="hidden fixed inset-0 bg-black bg-opacity-50 z-50 flex items-center justify-center p-4">
    <div class="bg-white rounded-lg shadow-xl max-w-md w-full">
        <div class="p-6">
            <div class="flex items-center justify-center w-12 h-12 mx-auto bg-red-100 rounded-full mb-4">
                <i class="fas fa-times-circle text-red-600 text-2xl"></i>
            </div>
            <h3 class="text-lg font-semibold text-gray-900 text-center mb-2">Reject Account</h3>
            <p class="text-sm text-gray-600 text-center mb-6">
                Are you sure you want to reject the account of <strong id="rejectName"></strong>?
            </p>
            <form method="POST" id="rejectForm">
                <input type="hidden" name="account_id" id="rejectAccountId">
                <input type="hidden" name="action" value="reject">
                <div class="flex gap-3">
                    <button type="button" onclick="closeRejectModal()" class="flex-1 px-4 py-2 bg-gray-200 text-gray-800 rounded-md hover:bg-gray-300 transition-colors font-medium">
                        Cancel
                    </button>
                    <button type="submit" class="flex-1 px-4 py-2 bg-red-600 text-white rounded-md hover:bg-red-700 transition-colors font-medium">
                        Reject
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Block Confirmation Modal -->
<div id="blockModal" class="hidden fixed inset-0 bg-black bg-opacity-50 z-50 flex items-center justify-center p-4">
    <div class="bg-white rounded-lg shadow-xl max-w-md w-full">
        <div class="p-6">
            <div class="flex items-center justify-center w-12 h-12 mx-auto bg-gray-100 rounded-full mb-4">
                <i class="fas fa-ban text-gray-600 text-2xl"></i>
            </div>
            <h3 class="text-lg font-semibold text-gray-900 text-center mb-2">Block Account</h3>
            <p class="text-sm text-gray-600 text-center mb-6">
                Are you sure you want to block the account of <strong id="blockName"></strong>?
            </p>
            <form method="POST" id="blockForm">
                <input type="hidden" name="account_id" id="blockAccountId">
                <input type="hidden" name="action" value="block">
                <div class="flex gap-3">
                    <button type="button" onclick="closeBlockModal()" class="flex-1 px-4 py-2 bg-gray-200 text-gray-800 rounded-md hover:bg-gray-300 transition-colors font-medium">
                        Cancel
                    </button>
                    <button type="submit" class="flex-1 px-4 py-2 bg-gray-600 text-white rounded-md hover:bg-gray-700 transition-colors font-medium">
                        Block
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Unblock Confirmation Modal -->
<div id="unblockModal" class="hidden fixed inset-0 bg-black bg-opacity-50 z-50 flex items-center justify-center p-4">
    <div class="bg-white rounded-lg shadow-xl max-w-md w-full">
        <div class="p-6">
            <div class="flex items-center justify-center w-12 h-12 mx-auto bg-blue-100 rounded-full mb-4">
                <i class="fas fa-unlock text-blue-600 text-2xl"></i>
            </div>
            <h3 class="text-lg font-semibold text-gray-900 text-center mb-2">Unblock Account</h3>
            <p class="text-sm text-gray-600 text-center mb-6">
                Are you sure you want to unblock the account of <strong id="unblockName"></strong>?
            </p>
            <form method="POST" id="unblockForm">
                <input type="hidden" name="account_id" id="unblockAccountId">
                <input type="hidden" name="action" value="unblock">
                <div class="flex gap-3">
                    <button type="button" onclick="closeUnblockModal()" class="flex-1 px-4 py-2 bg-gray-200 text-gray-800 rounded-md hover:bg-gray-300 transition-colors font-medium">
                        Cancel
                    </button>
                    <button type="submit" class="flex-1 px-4 py-2 bg-blue-600 text-white rounded-md hover:bg-blue-700 transition-colors font-medium">
                        Unblock
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

    <script>
        // Mobile Sidebar Toggle
        const mobileMenuBtn = document.getElementById('mobileMenuBtn');
        const sidebar = document.getElementById('sidebar');
        const sidebarOverlay = document.getElementById('sidebarOverlay');
        const closeSidebar = document.getElementById('closeSidebar');

        mobileMenuBtn.addEventListener('click', () => {
            sidebar.classList.remove('-translate-x-full');
            sidebarOverlay.classList.remove('hidden');
        });

        closeSidebar.addEventListener('click', () => {
            sidebar.classList.add('-translate-x-full');
            sidebarOverlay.classList.add('hidden');
        });

        sidebarOverlay.addEventListener('click', () => {
            sidebar.classList.add('-translate-x-full');
            sidebarOverlay.classList.add('hidden');
        });

        // Profile Dropdown
        const profileBtn = document.getElementById('profileBtn');
        const profileDropdown = document.getElementById('profileDropdown');

        profileBtn.addEventListener('click', (e) => {
            e.stopPropagation();
            profileDropdown.classList.toggle('hidden');
        });

        document.addEventListener('click', (e) => {
            if (!profileBtn.contains(e.target) && !profileDropdown.contains(e.target)) {
                profileDropdown.classList.add('hidden');
            }
        });

        // View Details Function
        function viewDetails(account) {
            const modal = document.getElementById('detailsModal');
            const content = document.getElementById('modalContent');
            
            const statusBadges = [];
            if (account.approval_status === 'pending') {
                statusBadges.push('<span class="status-badge bg-yellow-100 text-yellow-800"><i class="fas fa-clock mr-1"></i>Pending</span>');
            } else if (account.approval_status === 'approved') {
                statusBadges.push('<span class="status-badge bg-green-100 text-green-800"><i class="fas fa-check-circle mr-1"></i>Approved</span>');
            } else if (account.approval_status === 'rejected') {
                statusBadges.push('<span class="status-badge bg-red-100 text-red-800"><i class="fas fa-times-circle mr-1"></i>Rejected</span>');
            }
            
            if (account.status === 'blocked') {
                statusBadges.push('<span class="status-badge bg-gray-100 text-gray-800"><i class="fas fa-ban mr-1"></i>Blocked</span>');
            } else if (account.status === 'active') {
                statusBadges.push('<span class="status-badge bg-blue-100 text-blue-800"><i class="fas fa-circle mr-1 text-xs"></i>Active</span>');
            }
            
            content.innerHTML = `
                <div class="space-y-6">
                    <div class="flex items-center space-x-4">
                        <div class="w-16 h-16 bg-gradient-to-r from-blue-500 to-purple-600 rounded-full flex items-center justify-center text-white font-semibold text-xl">
                            ${account.name.substring(0, 2).toUpperCase()}
                        </div>
                        <div>
                            <h4 class="text-xl font-semibold text-gray-900">${account.name}</h4>
                            <div class="flex space-x-2 mt-1">${statusBadges.join('')}</div>
                        </div>
                    </div>
                    
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                            <label class="text-sm font-medium text-gray-500">Email</label>
                            <p class="mt-1 text-sm text-gray-900">${account.email}</p>
                        </div>
                        <div>
                            <label class="text-sm font-medium text-gray-500">Department</label>
                            <p class="mt-1 text-sm text-gray-900">${account.department}</p>
                        </div>
                        <div>
                            <label class="text-sm font-medium text-gray-500">Year Level</label>
                            <p class="mt-1 text-sm text-gray-900">${account.year_level}</p>
                        </div>
                        <div>
                            <label class="text-sm font-medium text-gray-500">Section</label>
                            <p class="mt-1 text-sm text-gray-900">${account.section}</p>
                        </div>
                        <div>
                            <label class="text-sm font-medium text-gray-500">Role</label>
                            <p class="mt-1 text-sm text-gray-900">${account.role === 'coordinator' ? 'Coordinator' : 'Adviser'}</p>
                        </div>
                        <div>
                            <label class="text-sm font-medium text-gray-500">Registration Date</label>
                            <p class="mt-1 text-sm text-gray-900">${new Date(account.created_at).toLocaleString()}</p>
                        </div>
                        ${account.approved_at ? `
                        <div>
                            <label class="text-sm font-medium text-gray-500">Approved Date</label>
                            <p class="mt-1 text-sm text-gray-900">${new Date(account.approved_at).toLocaleString()}</p>
                        </div>
                        ` : ''}
                    </div>
                </div>
            `;
            
            modal.classList.remove('hidden');
        }

        function closeModal() {
            document.getElementById('detailsModal').classList.add('hidden');
        }

        // Close modal on outside click
        document.getElementById('detailsModal').addEventListener('click', (e) => {
            if (e.target.id === 'detailsModal') {
                closeModal();
            }
        });
         function showApproveModal(accountId, accountName) {
        document.getElementById('approveAccountId').value = accountId;
        document.getElementById('approveName').textContent = accountName;
        document.getElementById('approveModal').classList.remove('hidden');
    }

    function closeApproveModal() {
        document.getElementById('approveModal').classList.add('hidden');
    }

    // Reject Modal Functions
    function showRejectModal(accountId, accountName) {
        document.getElementById('rejectAccountId').value = accountId;
        document.getElementById('rejectName').textContent = accountName;
        document.getElementById('rejectModal').classList.remove('hidden');
    }

    function closeRejectModal() {
        document.getElementById('rejectModal').classList.add('hidden');
    }

    // Block Modal Functions
    function showBlockModal(accountId, accountName) {
        document.getElementById('blockAccountId').value = accountId;
        document.getElementById('blockName').textContent = accountName;
        document.getElementById('blockModal').classList.remove('hidden');
    }

    function closeBlockModal() {
        document.getElementById('blockModal').classList.add('hidden');
    }

    // Unblock Modal Functions
    function showUnblockModal(accountId, accountName) {
        document.getElementById('unblockAccountId').value = accountId;
        document.getElementById('unblockName').textContent = accountName;
        document.getElementById('unblockModal').classList.remove('hidden');
    }

    function closeUnblockModal() {
        document.getElementById('unblockModal').classList.add('hidden');
    }

    // Close modals on outside click
    document.getElementById('approveModal').addEventListener('click', (e) => {
        if (e.target.id === 'approveModal') closeApproveModal();
    });

    document.getElementById('rejectModal').addEventListener('click', (e) => {
        if (e.target.id === 'rejectModal') closeRejectModal();
    });

    document.getElementById('blockModal').addEventListener('click', (e) => {
        if (e.target.id === 'blockModal') closeBlockModal();
    });

    document.getElementById('unblockModal').addEventListener('click', (e) => {
        if (e.target.id === 'unblockModal') closeUnblockModal();
    });

    // Close modals on Escape key
    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape') {
            closeApproveModal();
            closeRejectModal();
            closeBlockModal();
            closeUnblockModal();
        }
    });
    </script>
</body>
</html>