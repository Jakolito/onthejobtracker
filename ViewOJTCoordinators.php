<?php
include('connect.php');
session_start();

require './PHPMailer/PHPMailer/src/Exception.php';
require './PHPMailer/PHPMailer/src/PHPMailer.php';
require './PHPMailer/PHPMailer/src/SMTP.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

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

$adviser_query = "SELECT role, department, year_level, section, assigned_groups FROM academic_adviser WHERE id = ?";
$adviser_stmt = mysqli_prepare($conn, $adviser_query);
mysqli_stmt_bind_param($adviser_stmt, "i", $adviser_id);
mysqli_stmt_execute($adviser_stmt);
$adviser_result = mysqli_stmt_get_result($adviser_stmt);
$adviser_data = mysqli_fetch_assoc($adviser_result);
$adviser_role = $adviser_data['role'] ?? 'adviser';
mysqli_stmt_close($adviser_stmt);
$success_message = '';
$error_message = '';

// Handle approval/rejection actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && isset($_POST['supervisor_id'])) {
    $supervisor_id = intval($_POST['supervisor_id']);
    $action = $_POST['action'];
    
    if ($action === 'approve' || $action === 'reject') {
        // Get supervisor details for email
        $supervisor_query = "SELECT * FROM company_supervisors WHERE supervisor_id = ?";
        $supervisor_stmt = mysqli_prepare($conn, $supervisor_query);
        mysqli_stmt_bind_param($supervisor_stmt, "i", $supervisor_id);
        mysqli_stmt_execute($supervisor_stmt);
        $supervisor_result = mysqli_stmt_get_result($supervisor_stmt);
        $supervisor = mysqli_fetch_assoc($supervisor_result);
        
        if ($supervisor) {
            if ($action === 'approve') {
                // Update supervisor status to Active
                $update_query = "UPDATE company_supervisors SET account_status = 'Active', approved_at = NOW(), approved_by = ? WHERE supervisor_id = ?";
                $update_stmt = mysqli_prepare($conn, $update_query);
                mysqli_stmt_bind_param($update_stmt, "ii", $adviser_id, $supervisor_id);
                
               if (mysqli_stmt_execute($update_stmt)) {
    $success_message = "Supervisor account approved successfully!";
    
    // Send approval email with consistent design
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
        $mail->addAddress($supervisor['email']);
        
        $mail->isHTML(true);
        $mail->Subject = 'Account Approved - Welcome to OnTheJob Tracker';
        $mail->Body = '
        <div style="font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto; padding: 20px; border: 1px solid #ddd; border-radius: 10px;">
            <div style="text-align: center; margin-bottom: 30px;">
                <h1 style="color: #800000; margin: 0;">OnTheJob Tracker</h1>
                <p style="color: #666; margin: 5px 0;">Company Supervisor Portal</p>
            </div>
            
            <h2 style="color: #333;">Welcome, ' . htmlspecialchars($supervisor['full_name']) . '!</h2>
            <p style="color: #555; line-height: 1.6;">
                Congratulations! Your company supervisor account for <strong>' . htmlspecialchars($supervisor['company_name']) . '</strong> has been approved by our academic adviser team.
            </p>
            
            <div style="background-color: #f8f9fa; padding: 20px; border-radius: 8px; margin: 20px 0; text-align: center;">
                <h3 style="color: #800000; margin: 0 0 10px 0;">🎉 Account Activated!</h3>
                <p style="color: #555; margin: 10px 0;">You can now access all supervisor features</p>
            </div>
            
            <div style="background-color: #d4edda; padding: 15px; border-radius: 5px; border-left: 4px solid #28a745; margin: 20px 0;">
                <p style="margin: 0; color: #155724;">
                    <strong>What you can do now:</strong><br>
                    ✓ Monitor your OJT students progress<br>
                    ✓ Submit evaluations and feedback<br>
                    ✓ Communicate with academic advisers
                </p>
            </div>
            
            <div style="text-align: center; margin: 30px 0;">
                <a href="https://onthejobtracker.site/supervisor-login.php" 
                   style="display: inline-block; background-color: #800000; color: white; 
                          padding: 12px 30px; text-decoration: none; border-radius: 5px; 
                          font-weight: bold;">
                    Login to Your Account
                </a>
            </div>
            
            <p style="color: #555; line-height: 1.6;">
                If you experience any issues accessing your account, please contact our support team immediately.
            </p>
            
            <div style="text-align: center; margin-top: 30px; padding-top: 20px; border-top: 1px solid #eee;">
                <p style="color: #666; margin: 0;">
                    <strong>OnTheJob Tracker Team</strong><br>
                    <small>AI-Powered OJT Performance Monitoring</small>
                </p>
            </div>
        </div>';
        
        $mail->send();
        
    } catch (Exception $e) {
        error_log("Approval email failed: " . $e->getMessage());
    }
} else {
    $error_message = "Failed to approve supervisor account.";
}
mysqli_stmt_close($update_stmt);

} elseif ($action === 'reject') {
    // Update supervisor status to Inactive
    $update_query = "UPDATE company_supervisors SET account_status = 'Inactive' WHERE supervisor_id = ?";
    $update_stmt = mysqli_prepare($conn, $update_query);
    mysqli_stmt_bind_param($update_stmt, "i", $supervisor_id);
    
    if (mysqli_stmt_execute($update_stmt)) {
        $success_message = "Supervisor account rejected successfully!";
        
        // Send rejection email with consistent design
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
            $mail->addAddress($supervisor['email']);
            
            $mail->isHTML(true);
            $mail->Subject = 'Account Registration Update - OnTheJob Tracker';
            $mail->Body = '
            <div style="font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto; padding: 20px; border: 1px solid #ddd; border-radius: 10px;">
                <div style="text-align: center; margin-bottom: 30px;">
                    <h1 style="color: #800000; margin: 0;">OnTheJob Tracker</h1>
                    <p style="color: #666; margin: 5px 0;">Company Supervisor Portal</p>
                </div>
                
                <h2 style="color: #333;">Account Registration Update</h2>
                <p style="color: #555; line-height: 1.6;">
                    Dear ' . htmlspecialchars($supervisor['full_name']) . ',
                </p>
                
                <p style="color: #555; line-height: 1.6;">
                    We regret to inform you that your supervisor account registration for <strong>' . htmlspecialchars($supervisor['company_name']) . '</strong> could not be approved at this time.
                </p>
                
                <div style="background-color: #f8d7da; padding: 15px; border-radius: 5px; border-left: 4px solid #dc3545; margin: 20px 0;">
                    <p style="margin: 0; color: #721c24;">
                        <strong>Registration Status:</strong> Not Approved
                    </p>
                </div>
                
                <p style="color: #555; line-height: 1.6;">
                    If you believe this is an error or would like more information, please contact our academic adviser team for assistance.
                </p>
                
                <p style="color: #555; line-height: 1.6;">
                    Thank you for your interest in OnTheJob Tracker.
                </p>
                
                <div style="text-align: center; margin-top: 30px; padding-top: 20px; border-top: 1px solid #eee;">
                    <p style="color: #666; margin: 0;">
                        <strong>OnTheJob Tracker Team</strong><br>
                        <small>AI-Powered OJT Performance Monitoring</small>
                    </p>
                </div>
            </div>';
            
            $mail->send();
            
        } catch (Exception $e) {
            error_log("Rejection email failed: " . $e->getMessage());
        }
    } else {
        $error_message = "Failed to reject supervisor account.";
    }
    mysqli_stmt_close($update_stmt);
}
        }
        mysqli_stmt_close($supervisor_stmt);
    }
}

// MODIFIED QUERY: Get only company supervisors that match students handled by this adviser
try {
    $supervisors_query = "SELECT DISTINCT
        cs.supervisor_id, cs.full_name, cs.email, cs.phone_number, cs.position, cs.company_name, 
        cs.company_address, cs.industry_field, cs.company_contact_number, cs.students_needed, 
        cs.role_position, cs.required_skills, cs.internship_duration, cs.work_mode, 
        cs.work_schedule_start, cs.work_schedule_end, cs.work_days, 
        cs.internship_start_date, cs.internship_end_date,
        cs.account_status, cs.created_at, cs.approved_at, cs.approved_by,
        COUNT(DISTINCT s.id) as student_count
        FROM company_supervisors cs
        INNER JOIN students s ON s.company_name = cs.company_name 
            AND s.academic_adviser_id = ?
        WHERE s.company_name IS NOT NULL 
            AND s.company_name != ''
        GROUP BY cs.supervisor_id
        ORDER BY cs.created_at DESC";
    
    $supervisors_stmt = mysqli_prepare($conn, $supervisors_query);
    mysqli_stmt_bind_param($supervisors_stmt, "i", $adviser_id);
    mysqli_stmt_execute($supervisors_stmt);
    $supervisors_result = mysqli_stmt_get_result($supervisors_stmt);
    
} catch (Exception $e) {
    $error_message = "Error fetching supervisors: " . $e->getMessage();
}

// Fetch adviser profile picture
try {
    $adviser_query = "SELECT profile_picture FROM academic_adviser WHERE id = ?";
    $adviser_stmt = mysqli_prepare($conn, $adviser_query);
    mysqli_stmt_bind_param($adviser_stmt, "i", $adviser_id);
    mysqli_stmt_execute($adviser_stmt);
    $adviser_result = mysqli_stmt_get_result($adviser_stmt);
    
    if ($adviser_result && mysqli_num_rows($adviser_result) > 0) {
        $adviser_data = mysqli_fetch_assoc($adviser_result);
        $profile_picture = $adviser_data['profile_picture'] ?? '';
    } else {
        $profile_picture = '';
    }
} catch (Exception $e) {
    $profile_picture = '';
}

$adviser_initials = strtoupper(substr($adviser_name, 0, 2));

$unread_messages_query = "SELECT COUNT(*) as count FROM messages WHERE recipient_type = 'adviser' AND sender_type = 'student' AND is_read = 0 AND is_deleted_by_recipient = 0";
$unread_messages_result = mysqli_query($conn, $unread_messages_query);
$unread_messages_count = mysqli_fetch_assoc($unread_messages_result)['count'];
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>OnTheJob Tracker - View OJT Company Supervisors</title>
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
        .notification-badge {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-width: 20px;
            height: 20px;
            padding: 0 6px;
            margin-left: 8px;
            background: #EF4444;
            color: white;
            font-size: 11px;
            font-weight: 600;
            border-radius: 10px;
            animation: pulse 2s infinite;
        }
        
        .table-row-hover:hover {
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
        }
        
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
        }
        
        .fade-in {
            animation: fadeIn 0.5s ease-out;
        }
        
        ::-webkit-scrollbar {
            width: 8px;
        }
        
        ::-webkit-scrollbar-track {
            background: #f1f5f9;
        }
        
        ::-webkit-scrollbar-thumb {
            background: #cbd5e1;
            border-radius: 4px;
        }
    </style>
</head>
<body class="bg-gray-50">
    <!-- Mobile Sidebar Overlay -->
    <div id="sidebarOverlay" class="fixed inset-0 bg-black bg-opacity-50 z-40 lg:hidden hidden sidebar-overlay"></div>

    <!-- Sidebar -->
   <div id="sidebar" class="fixed left-0 top-0 h-full w-64 bg-gradient-to-b from-bulsu-maroon to-bulsu-dark-maroon shadow-lg z-50 transform -translate-x-full lg:translate-x-0 transition-transform duration-300 ease-in-out sidebar">
    <div class="flex justify-end p-4 lg:hidden">
        <button id="closeSidebar" class="text-bulsu-light-gold hover:text-bulsu-gold">
            <i class="fas fa-times text-xl"></i>
        </button>
    </div>

    <div class="px-6 py-4 border-b border-bulsu-gold border-opacity-30">
        <div class="flex items-center">
          <img src="reqsample/bulsu12.png" alt="BULSU Logo 2" class="w-14 h-14 mr-2">   
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
            <a href="ViewOJTCoordinators.php" class="nav-item flex items-center px-3 py-2 text-sm font-medium text-white bg-bulsu-gold bg-opacity-20 border border-bulsu-gold border-opacity-30 rounded-md">
                <i class="fas fa-users-cog mr-3 text-bulsu-gold"></i>
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
                    <span class="notification-badge">
                        <?php echo $unread_messages_count; ?>
                    </span>
                <?php endif; ?>
            </a>
            <a href="academicAdviserEdit.php" class="nav-item flex items-center px-3 py-2 text-sm font-medium text-bulsu-light-gold hover:text-white hover:bg-bulsu-gold hover:bg-opacity-20 rounded-md transition-all duration-200">
                <i class="fas fa-edit mr-3"></i>
                Edit Document
            </a>
             <?php if ($adviser_role === 'coordinator'): ?>
<a href="AcademicAccounts.php" class="nav-item flex items-center px-3 py-2 text-sm font-medium text-bulsu-light-gold hover:text-white hover:bg-bulsu-gold hover:bg-opacity-20 rounded-md transition-all duration-200">
    <i class="fas fa-user-tie mr-3"></i>
    Academic Accounts
</a>
<?php endif; ?>
        </nav>
    </div>
    
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
    <p class="text-xs text-bulsu-light-gold"><?php echo ucfirst($adviser_role); ?></p>
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
                    <h1 class="text-xl sm:text-2xl font-bold text-gray-900">Your Students' OJT Companies</h1>
                    <p class="text-sm sm:text-base text-gray-500 hidden sm:block">Companies where your students are assigned for OJT</p>
                </div>
                
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
        <div class="p-4 sm:p-6 lg:p-8 fade-in">
            <!-- Alert Messages -->
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

            <!-- Info Box -->
            <div class="mb-6 p-4 bg-blue-50 border border-blue-200 rounded-lg">
                <div class="flex items-start">
                    <i class="fas fa-info-circle text-blue-600 mt-1 mr-3"></i>
                    <p class="text-blue-700">
                        You are viewing companies where <strong>your assigned students</strong> are registered for OJT. 
                        Only supervisors from companies matched with your students will appear here.
                    </p>
                </div>
            </div>

            <!-- Supervisors Table -->
<!-- Supervisors Table -->
<div class="bg-white rounded-lg shadow-sm border border-bulsu-maroon">
    <div class="bg-gradient-to-r from-bulsu-maroon to-bulsu-dark-maroon px-6 py-4 rounded-t-lg">
        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between">
            <div class="mb-2 sm:mb-0">
                <h3 class="text-lg font-semibold text-white flex items-center">
                    <i class="fas fa-users-cog mr-3 text-bulsu-gold"></i>
                    Company Supervisors (Your Students' Companies)
                </h3>
            </div>
            <div class="text-bulsu-light-gold text-sm">
                Total: <?php echo mysqli_num_rows($supervisors_result); ?> companies
            </div>
        </div>
    </div>

    <div class="overflow-x-auto">
        <?php if (mysqli_num_rows($supervisors_result) > 0): ?>
            <table class="w-full">
                <thead>
                    <tr class="bg-gray-50 border-b border-gray-200">
                        <th class="px-6 py-4 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Supervisor Info</th>
                        <th class="px-6 py-4 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Company</th>
                        <th class="px-6 py-4 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Contact</th>
                        <th class="px-6 py-4 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Your Students</th>
                        <th class="px-6 py-4 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Slots Available</th>
                        <th class="px-6 py-4 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Status</th>
                        <th class="px-6 py-4 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Actions</th>
                    </tr>
                </thead>
                <tbody class="bg-white divide-y divide-gray-200">
                    <?php while ($supervisor = mysqli_fetch_assoc($supervisors_result)): ?>
                        <tr class="table-row-hover transition-all duration-200">
                            <td class="px-6 py-4 whitespace-nowrap">
                                <div class="font-semibold text-gray-900"><?php echo htmlspecialchars($supervisor['full_name']); ?></div>
                                <div class="text-sm text-gray-600"><?php echo htmlspecialchars($supervisor['position']); ?></div>
                                <div class="text-xs text-gray-500"><?php echo htmlspecialchars($supervisor['email']); ?></div>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap">
                                <div class="font-semibold text-gray-900"><?php echo htmlspecialchars($supervisor['company_name']); ?></div>
                                <div class="text-sm text-gray-600"><?php echo htmlspecialchars($supervisor['industry_field']); ?></div>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap">
                                <div class="text-sm text-gray-900"><?php echo htmlspecialchars($supervisor['phone_number']); ?></div>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap">
                                <div class="text-lg font-bold text-blue-600"><?php echo htmlspecialchars($supervisor['student_count']); ?></div>
                                <div class="text-xs text-gray-500">student(s) assigned</div>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap">
                                <div class="text-lg font-bold text-green-600"><?php echo htmlspecialchars($supervisor['students_needed']); ?></div>
                                <div class="text-xs text-gray-500">slot(s) left</div>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap">
                                <?php 
                                $status_classes = [
                                    'pending' => 'bg-yellow-100 text-yellow-800',
                                    'active' => 'bg-green-100 text-green-800',
                                    'inactive' => 'bg-red-100 text-red-800'
                                ];
                                $status_class = $status_classes[strtolower($supervisor['account_status'])] ?? 'bg-gray-100 text-gray-800';
                                ?>
                                <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium uppercase <?php echo $status_class; ?>">
                                    <?php echo htmlspecialchars($supervisor['account_status']); ?>
                                </span>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap">
                                <button 
                                    onclick="viewSupervisor(<?php echo $supervisor['supervisor_id']; ?>)"
                                    class="inline-flex items-center px-3 py-2 border border-transparent text-sm leading-4 font-medium rounded-md text-white bg-blue-600 hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500 transition-colors duration-200">
                                    <i class="fas fa-eye mr-2"></i>
                                    View Details
                                </button>
                            </td>
                        </tr>
                    <?php endwhile; ?>
                </tbody>
            </table>
        <?php else: ?>
            <div class="text-center py-12">
                <i class="fas fa-briefcase text-gray-400 text-6xl mb-4"></i>
                <h3 class="text-lg font-medium text-gray-900 mb-2">No Company Supervisors Found</h3>
                <p class="text-gray-600">None of your students have been assigned to companies yet.</p>
                <p class="text-sm text-gray-500 mt-2">Once students register with company names, their supervisors will appear here.</p>
            </div>
        <?php endif; ?>
    </div>
</div>
<div id="confirmationModal" class="fixed inset-0 bg-black bg-opacity-50 backdrop-blur-sm z-[60] hidden">
        <div class="flex items-center justify-center min-h-screen p-4">
            <div class="bg-white rounded-lg max-w-md w-full transform transition-all">
                <div id="confirmModalHeader" class="px-6 py-4 rounded-t-lg">
                    <h3 id="confirmModalTitle" class="text-lg font-semibold text-white flex items-center">
                        <i id="confirmModalIcon" class="mr-3 text-2xl"></i>
                        <span id="confirmModalTitleText"></span>
                    </h3>
                </div>
                
                <div class="p-6">
                    <div id="confirmModalBody" class="text-gray-700 space-y-3"></div>
                </div>
                
                <div class="bg-gray-50 px-6 py-4 rounded-b-lg flex flex-col sm:flex-row gap-3 justify-end">
                    <button 
                        onclick="closeConfirmationModal()"
                        class="px-4 py-2 border border-gray-300 text-sm font-medium rounded-md text-gray-700 bg-white hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-gray-500 transition-colors">
                        <i class="fas fa-times mr-2"></i>
                        Cancel
                    </button>
                    <button 
                        id="confirmActionBtn"
                        class="px-4 py-2 border border-transparent text-sm font-medium rounded-md text-white focus:outline-none focus:ring-2 focus:ring-offset-2 transition-colors">
                        <i id="confirmActionIcon" class="mr-2"></i>
                        <span id="confirmActionText"></span>
                    </button>
                </div>
            </div>
        </div>
    </div>
    <!-- Supervisor Details Modal -->
    <div id="supervisorModal" class="fixed inset-0 bg-black bg-opacity-50 backdrop-blur-sm z-50 hidden modal">
        <div class="flex items-center justify-center min-h-screen p-4">
            <div class="bg-white rounded-lg max-w-4xl w-full max-h-screen overflow-y-auto modal-content">
                <div class="bg-gradient-to-r from-bulsu-maroon to-bulsu-dark-maroon px-6 py-4 rounded-t-lg">
                    <div class="flex items-center justify-between">
                        <h2 class="text-xl font-semibold text-white flex items-center">
                            <i class="fas fa-user-tie mr-3 text-bulsu-gold"></i>
                            Supervisor Details
                        </h2>
                        <button onclick="closeModal()" class="text-white hover:text-bulsu-light-gold transition-colors">
                            <i class="fas fa-times text-xl"></i>
                        </button>
                    </div>
                </div>

                <div id="modalBody" class="p-6"></div>

                <div class="bg-gray-50 px-6 py-4 border-t border-gray-200 rounded-b-lg">
                    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between">
                        <div class="flex items-center text-sm text-gray-600 mb-4 sm:mb-0">
                            <i class="fas fa-info-circle mr-2"></i>
                            Review all details before making a decision
                        </div>
                        <div id="modalActions" class="flex flex-col sm:flex-row gap-3"></div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <form id="approveForm" method="POST" style="display: none;">
        <input type="hidden" name="action" value="approve">
        <input type="hidden" name="supervisor_id" id="approveSupervisorId">
    </form>

    <form id="rejectForm" method="POST" style="display: none;">
        <input type="hidden" name="action" value="reject">
        <input type="hidden" name="supervisor_id" id="rejectSupervisorId">
    </form>

    <script>
        const supervisorData = {
            <?php 
            mysqli_data_seek($supervisors_result, 0);
            $supervisor_array = [];
            while ($supervisor = mysqli_fetch_assoc($supervisors_result)) {
                $supervisor_array[] = $supervisor['supervisor_id'] . ': ' . json_encode($supervisor);
            }
            echo implode(',', $supervisor_array);
            ?>
        };

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

        document.getElementById('profileBtn').addEventListener('click', function(e) {
            e.stopPropagation();
            document.getElementById('profileDropdown').classList.toggle('hidden');
        });

        document.addEventListener('click', function(e) {
            const profileDropdown = document.getElementById('profileDropdown');
            if (!e.target.closest('#profileBtn') && !profileDropdown.classList.contains('hidden')) {
                profileDropdown.classList.add('hidden');
            }
        });

        function confirmLogout() {
            return confirm('Are you sure you want to logout?');
        }

        function formatTime(timeString) {
            if (!timeString) return '<span class="text-gray-400 italic">Not specified</span>';
            try {
                let hours, minutes;
                if (timeString.includes(':')) {
                    [hours, minutes] = timeString.split(':').map(num => parseInt(num));
                } else {
                    return timeString;
                }
                let period = 'AM';
                let displayHours = hours;
                if (hours === 0) {
                    displayHours = 12;
                } else if (hours === 12) {
                    period = 'PM';
                } else if (hours > 12) {
                    displayHours = hours - 12;
                    period = 'PM';
                }
                const displayMinutes = minutes.toString().padStart(2, '0');
                return `${displayHours}:${displayMinutes} ${period}`;
            } catch (e) {
                return timeString;
            }
        }

        function formatDate(dateString) {
            if (!dateString) return '<span class="text-gray-400 italic">Not specified</span>';
            try {
                const date = new Date(dateString);
                return date.toLocaleDateString('en-US', { year: 'numeric', month: 'long', day: 'numeric' });
            } catch (e) {
                return dateString;
            }
        }

        function viewSupervisor(supervisorId) {
            const supervisor = supervisorData[supervisorId];
            if (!supervisor) return;

            const modalBody = document.getElementById('modalBody');
            const modalActions = document.getElementById('modalActions');

            let skillsHtml = '';
            if (supervisor.required_skills && supervisor.required_skills.trim()) {
                const skills = supervisor.required_skills.split(',');
                skillsHtml = skills.map(skill => 
                    `<span class="inline-block bg-gray-100 text-gray-800 px-2 py-1 rounded-full text-xs mr-1 mb-1">${skill.trim()}</span>`
                ).join('');
            } else {
                skillsHtml = '<span class="text-gray-400 italic">No specific skills required</span>';
            }

            let workDaysHtml = supervisor.work_days && supervisor.work_days.trim() ? 
                supervisor.work_days : '<span class="text-gray-400 italic">Not specified</span>';

            modalBody.innerHTML = `
                <div class="bg-gray-50 rounded-lg p-6 mb-6 border-l-4 border-green-500">
                    <h4 class="text-lg font-semibold text-green-600 mb-4 flex items-center">
                        <i class="fas fa-building mr-2"></i>
                        Company Information
                    </h4>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Company Name</label>
                            <p class="text-green-600 font-semibold">${supervisor.company_name}</p>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Industry / Field</label>
                            <p class="text-gray-900">${supervisor.industry_field}</p>
                        </div>
                        <div class="md:col-span-2">
                            <label class="block text-sm font-medium text-gray-700 mb-1">Company Address</label>
                            <p class="text-gray-900">${supervisor.company_address}</p>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Company Contact</label>
                            <p class="bg-gray-100 px-2 py-1 rounded font-mono text-sm">${supervisor.company_contact_number}</p>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Your Students at This Company</label>
                            <p class="text-2xl font-bold text-blue-600">${supervisor.student_count}</p>
                        </div>
                    </div>
                </div>

                <div class="bg-blue-50 rounded-lg p-6 mb-6 border-l-4 border-blue-500">
                    <h4 class="text-lg font-semibold text-blue-600 mb-4 flex items-center">
                        <i class="fas fa-user-tie mr-2"></i>
                        Supervisor Information
                    </h4>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Full Name</label>
                            <p class="text-gray-900 font-semibold">${supervisor.full_name}</p>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Position</label>
                            <p class="text-gray-900">${supervisor.position}</p>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Email Address</label>
                            <p class="bg-gray-100 px-2 py-1 rounded font-mono text-sm">${supervisor.email}</p>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Phone Number</label>
                            <p class="bg-gray-100 px-2 py-1 rounded font-mono text-sm">${supervisor.phone_number}</p>
                        </div>
                    </div>
                </div>

                <div class="bg-purple-50 rounded-lg p-6 mb-6 border-l-4 border-purple-500">
                    <h4 class="text-lg font-semibold text-purple-600 mb-4 flex items-center">
                        <i class="fas fa-clipboard-list mr-2"></i>
                        OJT Requirements
                    </h4>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Students Needed</label>
                            <p class="text-2xl font-bold text-green-600">${supervisor.students_needed}</p>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Role / Position</label>
                            <p class="text-gray-900">${supervisor.role_position}</p>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Internship Duration</label>
                            <p class="text-gray-900">${supervisor.internship_duration}</p>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Work Mode</label>
                            <p class="text-gray-900">${supervisor.work_mode}</p>
                        </div>
                        <div class="md:col-span-2">
                            <label class="block text-sm font-medium text-gray-700 mb-2">Required Skills</label>
                            <div>${skillsHtml}</div>
                        </div>
                    </div>
                </div>

                <div class="bg-yellow-50 rounded-lg p-6 mb-6 border-l-4 border-yellow-500">
                    <h4 class="text-lg font-semibold text-yellow-600 mb-4 flex items-center">
                        <i class="fas fa-clock mr-2"></i>
                        Work Schedule & Internship Period
                    </h4>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div class="md:col-span-2">
                            <label class="block text-sm font-medium text-gray-700 mb-2">Work Schedule</label>
                            <div class="flex items-center space-x-2">
                                <span class="bg-blue-100 text-blue-800 px-3 py-1 rounded-full text-sm font-medium">${formatTime(supervisor.work_schedule_start)}</span>
                                <span class="text-gray-500">to</span>
                                <span class="bg-blue-100 text-blue-800 px-3 py-1 rounded-full text-sm font-medium">${formatTime(supervisor.work_schedule_end)}</span>
                            </div>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Work Days</label>
                            <p class="text-gray-900">${workDaysHtml}</p>
                        </div>
                        <div></div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Internship Start Date</label>
                            <p class="text-gray-900">${formatDate(supervisor.internship_start_date)}</p>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Internship End Date</label>
                            <p class="text-gray-900">${formatDate(supervisor.internship_end_date)}</p>
                        </div>
                    </div>
                </div>

                <div class="bg-gray-50 rounded-lg p-6 border-l-4 border-gray-500">
                    <h4 class="text-lg font-semibold text-gray-600 mb-4 flex items-center">
                        <i class="fas fa-info-circle mr-2"></i>
                        Account Status
                    </h4>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Current Status</label>
                            <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium uppercase ${
                                supervisor.account_status.toLowerCase() === 'pending' ? 'bg-yellow-100 text-yellow-800' :
                                supervisor.account_status.toLowerCase() === 'active' ? 'bg-green-100 text-green-800' :
                                'bg-red-100 text-red-800'
                            }">
                                ${supervisor.account_status}
                            </span>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Registration Date</label>
                            <p class="text-sm text-gray-900">${new Date(supervisor.created_at).toLocaleDateString('en-US', { year: 'numeric', month: 'long', day: 'numeric' })}</p>
                        </div>
                    </div>
                </div>
            `;

            if (supervisor.account_status === 'Pending') {
                modalActions.innerHTML = `
                    <button 
                        onclick="approveSupervisor(${supervisorId})"
                        class="inline-flex items-center px-4 py-2 border border-transparent text-sm font-medium rounded-md text-white bg-green-600 hover:bg-green-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-green-500 transition-colors">
                        <i class="fas fa-check mr-2"></i>
                        Approve Account
                    </button>
                    <button 
                        onclick="rejectSupervisor(${supervisorId})"
                        class="inline-flex items-center px-4 py-2 border border-transparent text-sm font-medium rounded-md text-white bg-red-600 hover:bg-red-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-red-500 transition-colors">
                        <i class="fas fa-times mr-2"></i>
                        Reject Account
                    </button>
                `;
            } else {
                modalActions.innerHTML = `
                    <button class="inline-flex items-center px-4 py-2 border border-gray-300 text-sm font-medium rounded-md text-gray-700 bg-gray-100 cursor-not-allowed">
                        <i class="fas fa-info-circle mr-2"></i>
                        Status: ${supervisor.account_status}
                    </button>
                `;
            }

            document.getElementById('supervisorModal').classList.remove('hidden');
        }

        function closeModal() {
            document.getElementById('supervisorModal').classList.add('hidden');
        }

        window.addEventListener('click', function(event) {
            if (event.target === document.getElementById('supervisorModal')) {
                closeModal();
            }
        });

       function closeConfirmationModal() {
            document.getElementById('confirmationModal').classList.add('hidden');
        }

        function approveSupervisor(supervisorId) {
            const supervisor = supervisorData[supervisorId];
            
            // Set modal header (green theme for approval)
            const header = document.getElementById('confirmModalHeader');
            header.className = 'px-6 py-4 rounded-t-lg bg-gradient-to-r from-green-600 to-green-700';
            
            // Set icon
            document.getElementById('confirmModalIcon').className = 'fas fa-check-circle mr-3 text-2xl';
            document.getElementById('confirmModalTitleText').textContent = 'Approve Supervisor Account';
            
            // Set body content
            document.getElementById('confirmModalBody').innerHTML = `
                <div class="bg-green-50 border-l-4 border-green-500 p-4 rounded">
                    <p class="font-semibold text-green-800 mb-2">You are about to approve:</p>
                    <p class="text-gray-700"><strong>Name:</strong> ${supervisor.full_name}</p>
                    <p class="text-gray-700"><strong>Company:</strong> ${supervisor.company_name}</p>
                    <p class="text-gray-700"><strong>Email:</strong> ${supervisor.email}</p>
                </div>
                <div class="mt-4 flex items-start">
                    <i class="fas fa-envelope text-blue-500 mt-1 mr-3"></i>
                    <p class="text-sm text-gray-600">An approval email will be automatically sent to the supervisor's email address.</p>
                </div>
                <div class="mt-3 flex items-start">
                    <i class="fas fa-info-circle text-yellow-500 mt-1 mr-3"></i>
                    <p class="text-sm text-gray-600">Once approved, the supervisor will be able to access their account immediately.</p>
                </div>
            `;
            
            // Set action button
            const actionBtn = document.getElementById('confirmActionBtn');
            actionBtn.className = 'px-4 py-2 border border-transparent text-sm font-medium rounded-md text-white bg-green-600 hover:bg-green-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-green-500 transition-colors';
            document.getElementById('confirmActionIcon').className = 'fas fa-check mr-2';
            document.getElementById('confirmActionText').textContent = 'Approve Account';
            
            actionBtn.onclick = function() {
                document.getElementById('approveSupervisorId').value = supervisorId;
                document.getElementById('approveForm').submit();
            };
            
            // Show modal
            document.getElementById('confirmationModal').classList.remove('hidden');
        }

        function rejectSupervisor(supervisorId) {
            const supervisor = supervisorData[supervisorId];
            
            // Set modal header (red theme for rejection)
            const header = document.getElementById('confirmModalHeader');
            header.className = 'px-6 py-4 rounded-t-lg bg-gradient-to-r from-red-600 to-red-700';
            
            // Set icon
            document.getElementById('confirmModalIcon').className = 'fas fa-times-circle mr-3 text-2xl';
            document.getElementById('confirmModalTitleText').textContent = 'Reject Supervisor Account';
            
            // Set body content
            document.getElementById('confirmModalBody').innerHTML = `
                <div class="bg-red-50 border-l-4 border-red-500 p-4 rounded">
                    <p class="font-semibold text-red-800 mb-2">You are about to reject:</p>
                    <p class="text-gray-700"><strong>Name:</strong> ${supervisor.full_name}</p>
                    <p class="text-gray-700"><strong>Company:</strong> ${supervisor.company_name}</p>
                    <p class="text-gray-700"><strong>Email:</strong> ${supervisor.email}</p>
                </div>
                <div class="mt-4 flex items-start">
                    <i class="fas fa-envelope text-blue-500 mt-1 mr-3"></i>
                    <p class="text-sm text-gray-600">A rejection notification email will be sent to the supervisor's email address.</p>
                </div>
                <div class="mt-3 flex items-start">
                    <i class="fas fa-exclamation-triangle text-yellow-500 mt-1 mr-3"></i>
                    <p class="text-sm text-gray-600"><strong>Warning:</strong> This action will set the account status to inactive. The supervisor will not be able to access the system.</p>
                </div>
            `;
            
            // Set action button
            const actionBtn = document.getElementById('confirmActionBtn');
            actionBtn.className = 'px-4 py-2 border border-transparent text-sm font-medium rounded-md text-white bg-red-600 hover:bg-red-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-red-500 transition-colors';
            document.getElementById('confirmActionIcon').className = 'fas fa-times mr-2';
            document.getElementById('confirmActionText').textContent = 'Reject Account';
            
            actionBtn.onclick = function() {
                document.getElementById('rejectSupervisorId').value = supervisorId;
                document.getElementById('rejectForm').submit();
            };
            
            // Show modal
            document.getElementById('confirmationModal').classList.remove('hidden');
        }

        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                closeModal();
                document.getElementById('profileDropdown').classList.add('hidden');
            }
        });
    </script>
</body>
</html>