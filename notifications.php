<?php
// notifications.php - Enhanced notifications page with Tailwind CSS design
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
include_once('notification_functions.php');
$user_id = $_SESSION['user_id'];

// Get student information for header
$stmt = $conn->prepare("SELECT first_name, middle_name, last_name, student_id, department, program, year_level, profile_picture FROM students WHERE id = ?");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$result = $stmt->get_result();
$student = $result->fetch_assoc();

// Build full name and initials
if ($student) {
    $full_name = $student['first_name'];
    if (!empty($student['middle_name'])) {
        $full_name .= ' ' . $student['middle_name'];
    }
    $full_name .= ' ' . $student['last_name'];
    
    // Create initials for avatar (first letter of first name + first letter of last name)
    $initials = strtoupper(substr($student['first_name'], 0, 1) . substr($student['last_name'], 0, 1));
    
    // Get profile picture
    $profile_picture = $student['profile_picture'];
    
    // Get other student info
    $first_name = $student['first_name'];
    $student_id = $student['student_id'];
    $program = $student['program'];
} else {
    // If student not found, redirect to login
    header("Location: login.php");
    exit();
}

// Handle AJAX requests
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    
    if (isset($_POST['action'])) {
        switch ($_POST['action']) {
            case 'mark_as_read':
                if (isset($_POST['notification_id'])) {
                    $notification_id = (int)$_POST['notification_id'];
                    $update_stmt = $conn->prepare("UPDATE student_notifications SET is_read = 1, read_at = NOW() WHERE id = ? AND student_id = ?");
                    $update_stmt->bind_param("ii", $notification_id, $user_id);
                    $success = $update_stmt->execute();
                    echo json_encode(['success' => $success]);
                    exit();
                }
                break;
                
            case 'mark_all_as_read':
                $update_stmt = $conn->prepare("UPDATE student_notifications SET is_read = 1, read_at = NOW() WHERE student_id = ? AND is_read = 0");
                $update_stmt->bind_param("i", $user_id);
                $success = $update_stmt->execute();
                echo json_encode(['success' => $success]);
                exit();
                break;
                
            case 'get_notification_details':
                if (isset($_POST['notification_id'])) {
                    $notification_id = (int)$_POST['notification_id'];
                    $detail_stmt = $conn->prepare("SELECT * FROM student_notifications WHERE id = ? AND student_id = ?");
                    $detail_stmt->bind_param("ii", $notification_id, $user_id);
                    $detail_stmt->execute();
                    $result = $detail_stmt->get_result();
                    $notification = $result->fetch_assoc();
                    
                    if ($notification) {
                        echo json_encode(['success' => true, 'notification' => $notification]);
                    } else {
                        echo json_encode(['success' => false, 'message' => 'Notification not found']);
                    }
                    exit();
                }
                break;
        }
    }
    
    echo json_encode(['success' => false, 'message' => 'Invalid request']);
    exit();
}

// Pagination - 10 notifications per page
$records_per_page = 10;
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$offset = ($page - 1) * $records_per_page;

// Get total notifications count
$count_stmt = $conn->prepare("SELECT COUNT(*) as total FROM student_notifications WHERE student_id = ?");
$count_stmt->bind_param("i", $user_id);
$count_stmt->execute();
$count_result = $count_stmt->get_result();
$total_records = $count_result->fetch_assoc()['total'];
$total_pages = ceil($total_records / $records_per_page);

// Get notifications with proper ordering
$notifications = getStudentNotifications($conn, $user_id, $records_per_page, $offset, false);

// Get unread count
$unread_count = getUnreadNotificationCount($conn, $user_id);

function getActionUrl($notification) {
    switch($notification['type']) {
        case 'document_approved':
        case 'document_rejected':
        case 'document_updated':
        case 'document_pending':
            return "studentReport.php";
            
        case 'task_assigned':
        case 'task_updated':
        case 'task_approved':
        case 'task_rejected':
        case 'task_completed':
        case 'task_overdue':
        case 'task_reminder':
            if (!empty($notification['task_id'])) {
                return "studentTask.php?task_id=" . $notification['task_id'];
            }
            return "studentTask.php";
            
        case 'evaluation_received':
        case 'evaluation_updated':
        case 'evaluation_reminder':
            return "studentEvaluation.php";
            
        case 'attendance_reminder':
        case 'attendance_approved':
        case 'attendance_rejected':
        case 'attendance_updated':
            return "studentAttendance.php";
            
        case 'message_received':
        case 'message_reply':
        case 'new_message':
            return "studentMessage.php";
            
        case 'self_assessment_reminder':
        case 'self_assessment_due':
            return "studentSelf-Assessment.php";
            
        case 'deployment_confirmed':
        case 'deployment_completed':
        case 'deployment_terminated':
        case 'deployment_updated':
            return "studentdashboard.php?tab=deployment";
            
        case 'report_feedback':
        case 'report_approved':
        case 'report_rejected':
            return "studentReport.php";
            
        default:
            return "studentdashboard.php";
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>OnTheJob Tracker - Notifications</title>
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
        }

        .sidebar-overlay {
            transition: opacity 0.3s ease-in-out;
        }

        .notification-item.unread {
            position: relative;
        }
        
        .notification-item.unread::before {
            content: '';
            position: absolute;
            left: 20px;
            top: 20px;
            width: 8px;
            height: 8px;
            background: linear-gradient(135deg, #2196f3, #1976d2);
            border-radius: 50%;
            box-shadow: 0 2px 8px rgba(33, 150, 243, 0.4);
            animation: pulse 2s infinite;
        }
        
        @keyframes pulse {
            0% { transform: scale(1); opacity: 1; }
            50% { transform: scale(1.2); opacity: 0.7; }
            100% { transform: scale(1); opacity: 1; }
        }

        .loading i {
            animation: spin 1s linear infinite;
        }

        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }

        /* Fixed Modal Styling */
        .modal-backdrop {
            background-color: rgba(0, 0, 0, 0.5);
            backdrop-filter: blur(4px);
        }

        .modal-content {
            background: white;
            border-radius: 12px;
            box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.25);
            max-height: 90vh;
            overflow-y: auto;
        }

        .modal-enter {
            animation: modalEnter 0.2s ease-out forwards;
        }
        
        @keyframes modalEnter {
            from {
                opacity: 0;
                transform: scale(0.9) translateY(-20px);
            }
            to {
                opacity: 1;
                transform: scale(1) translateY(0);
            }
        }

        /* Better scrollbar for modal */
        .modal-content::-webkit-scrollbar {
            width: 6px;
        }

        .modal-content::-webkit-scrollbar-track {
            background: #f1f5f9;
            border-radius: 3px;
        }

        .modal-content::-webkit-scrollbar-thumb {
            background: #cbd5e1;
            border-radius: 3px;
        }

        .modal-content::-webkit-scrollbar-thumb:hover {
            background: #94a3b8;
        }
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
            <a href="studentdashboard.php" class="nav-item flex items-center px-3 py-2 text-sm font-medium text-bulsu-light-gold hover:text-white hover:bg-bulsu-gold hover:bg-opacity-20 rounded-md transition-all duration-200">
                <i class="fas fa-th-large mr-3 "></i>
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
            <a href="notifications.php" class="nav-item flex items-center px-3 py-2 text-sm font-medium text-white bg-bulsu-gold bg-opacity-20 border border-bulsu-gold border-opacity-30 rounded-md">
                <i class="fas fa-bell mr-3 text-bulsu-gold"></i>
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

                <!-- Header Title -->
                <div class="flex-1 lg:ml-0 ml-4">
                    <h1 class="text-xl sm:text-2xl font-bold text-gray-900">Student Notifications</h1>
                    <p class="text-sm sm:text-base text-gray-500 hidden sm:block">Stay updated with your latest notifications</p>
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
                                    <p class="text-sm text-gray-500"><?php echo htmlspecialchars($student_id); ?> • <?php echo htmlspecialchars($program); ?></p>
                                </div>
                            </div>
                        </div>
                        <a href="studentAccount-settings.php" class="flex items-center px-4 py-2 text-sm text-gray-700 hover:bg-gray-50">
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
            <!-- Notifications Header Card -->
<div class="bg-gradient-to-r from-bulsu-maroon to-bulsu-dark-maroon rounded-lg p-6 mb-6 sm:mb-8 text-white">
                <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between">
                    <div class="mb-4 sm:mb-0">
                        <h2 class="text-2xl font-bold flex items-center">
                            <i class="fas fa-bell mr-3"></i>
                            Notifications Center
                        </h2>
                        <p class="text-blue-100 mt-1">Stay informed about your progress and updates</p>
                    </div>
                    
                    <?php if ($unread_count > 0): ?>
                    <div class="flex gap-3">
                        <button onclick="markAllAsRead()" class="flex items-center px-4 py-2 bg-white bg-opacity-20 hover:bg-opacity-30 rounded-md transition-all duration-200">
                            <i class="fas fa-check-double mr-2"></i>
                            Mark All Read
                        </button>
                    </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Notifications List -->
            <div class="bg-white rounded-lg shadow-sm border border-gray-200">
                <div class="p-4 sm:p-6 border-b border-gray-200">
                    <div class="flex items-center justify-between">
                        <div class="flex items-center">
                            <i class="fas fa-list text-blue-600 mr-3"></i>
                            <h3 class="text-lg font-medium text-gray-900">Recent Notifications</h3>
                        </div>
                        <div class="text-sm text-gray-500">
                            <?php echo $unread_count; ?> unread
                        </div>
                    </div>
                </div>

                <div class="divide-y divide-gray-200">
                    <?php if (empty($notifications)): ?>
                        <div class="p-8 sm:p-12 text-center">
                            <i class="fas fa-bell-slash text-gray-300 text-6xl mb-4"></i>
                            <h3 class="text-xl font-medium text-gray-900 mb-2">No notifications yet</h3>
                            <p class="text-gray-600">You'll see notifications here when there are updates about your tasks, documents, or other important information.</p>
                        </div>
                    <?php else: ?>
                        <?php foreach ($notifications as $notification): ?>
                            <?php
                            $isUnread = $notification['is_read'] == 0;
                            $actionUrl = getActionUrl($notification);
                            $typeClass = '';
                            $typeIcon = '';
                            $typeText = '';
                            
                            switch($notification['type']) {
                                case 'document_approved':
                                    $typeClass = 'bg-green-100 text-green-800';
                                    $typeIcon = 'fa-check-circle';
                                    $typeText = 'Approved';
                                    break;
                                case 'document_rejected':
                                    $typeClass = 'bg-red-100 text-red-800';
                                    $typeIcon = 'fa-times-circle';
                                    $typeText = 'Rejected';
                                    break;
                                case 'evaluation_received':
                                case 'evaluation_updated':
                                case 'evaluation_reminder':
                                    $typeClass = 'bg-blue-100 text-blue-800';
                                    $typeIcon = 'fa-star';
                                    $typeText = 'Evaluation';
                                    break;
                                case 'attendance_reminder':
                                case 'attendance_approved':
                                case 'attendance_rejected':
                                case 'attendance_updated':
                                    $typeClass = 'bg-purple-100 text-purple-800';
                                    $typeIcon = 'fa-calendar-check';
                                    $typeText = 'Attendance';
                                    break;
                                case 'message_received':
                                case 'message_reply':
                                case 'new_message':
                                    $typeClass = 'bg-gray-100 text-gray-800';
                                    $typeIcon = 'fa-envelope';
                                    $typeText = 'Message';
                                    break;
                                case 'self_assessment_reminder':
                                case 'self_assessment_due':
                                    $typeClass = 'bg-indigo-100 text-indigo-800';
                                    $typeIcon = 'fa-user-check';
                                    $typeText = 'Assessment';
                                    break;
                                case 'deployment_confirmed':
                                    $typeClass = 'bg-cyan-100 text-cyan-800';
                                    $typeIcon = 'fa-paper-plane';
                                    $typeText = 'Deployment';
                                    break;
                                case 'deployment_completed':
                                    $typeClass = 'bg-green-100 text-green-800';
                                    $typeIcon = 'fa-graduation-cap';
                                    $typeText = 'Completed';
                                    break;
                                case 'deployment_terminated':
                                    $typeClass = 'bg-orange-100 text-orange-800';
                                    $typeIcon = 'fa-exclamation-triangle';
                                    $typeText = 'Update';
                                    break;
                                case 'report_feedback':
                                case 'report_approved':
                                case 'report_rejected':
                                case 'document_updated':
                                case 'document_pending':
                                    $typeClass = 'bg-purple-100 text-purple-800';
                                    $typeIcon = 'fa-book';
                                    $typeText = 'Report';
                                    break;
                                default:
                                    $typeClass = 'bg-gray-100 text-gray-800';
                                    $typeIcon = 'fa-info-circle';
                                    $typeText = 'General';
                            }
                            ?>
                            <div onclick="viewNotificationDetails(<?php echo $notification['id']; ?>)" 
                                 class="notification-item <?php echo $isUnread ? 'unread bg-blue-50 border-l-4 border-blue-500' : 'hover:bg-gray-50'; ?> p-4 sm:p-6 cursor-pointer transition-all duration-200">
                                
                                <div class="ml-6">
                                    <div class="flex flex-col sm:flex-row sm:items-start sm:justify-between mb-2">
                                        <h4 class="text-base font-medium text-gray-900 mb-1 sm:mb-0">
                                            <?php echo htmlspecialchars($notification['title']); ?>
                                        </h4>
                                        <div class="text-sm text-gray-500">
                                            <?php echo date('M j, Y g:i A', strtotime($notification['created_at'])); ?>
                                        </div>
                                    </div>
                                    
                                    <p class="text-gray-600 text-sm mb-3 line-clamp-2">
                                        <?php echo htmlspecialchars($notification['message']); ?>
                                    </p>
                                    
                                    <div class="flex flex-wrap items-center gap-2">
                                        <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium <?php echo $typeClass; ?>">
                                            <i class="fas <?php echo $typeIcon; ?> mr-1"></i>
                                            <?php echo $typeText; ?>
                                        </span>
                                        
                                        <?php if ($notification['document_name']): ?>
                                            <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-gray-100 text-gray-800">
                                                <i class="fas fa-file-alt mr-1"></i>
                                                <?php echo htmlspecialchars($notification['document_name']); ?>
                                            </span>
                                        <?php endif; ?>
                                        
                                        <?php if ($isUnread): ?>
                                            <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-blue-100 text-blue-800">
                                                <i class="fas fa-circle mr-1" style="font-size: 0.4rem;"></i>
                                                New
                                            </span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>

                <!-- Pagination -->
                <?php if ($total_pages > 1): ?>
                    <div class="px-4 py-3 bg-gray-50 border-t border-gray-200 sm:px-6">
                        <div class="flex items-center justify-between">
                            <div class="text-sm text-gray-700">
                                Showing <?php echo ($offset + 1); ?> to <?php echo min($offset + $records_per_page, $total_records); ?> of <?php echo $total_records; ?> results
                            </div>
                            <div class="flex items-center space-x-2">
                                <?php if ($page > 1): ?>
                                    <a href="?page=<?php echo ($page - 1); ?>" class="inline-flex items-center px-3 py-2 text-sm font-medium text-gray-500 bg-white border border-gray-300 rounded-md hover:bg-gray-50">
                                        <i class="fas fa-chevron-left mr-1"></i>
                                        Previous
                                    </a>
                                <?php endif; ?>
                                
                                <?php
                                $start_page = max(1, $page - 2);
                                $end_page = min($total_pages, $page + 2);
                                
                                for ($i = $start_page; $i <= $end_page; $i++): ?>
                                    <a href="?page=<?php echo $i; ?>" class="inline-flex items-center px-3 py-2 text-sm font-medium <?php echo $i == $page ? 'text-blue-600 bg-blue-50 border border-blue-300' : 'text-gray-500 bg-white border border-gray-300 hover:bg-gray-50'; ?> rounded-md">
                                        <?php echo $i; ?>
                                    </a>
                                <?php endfor; ?>
                                
                                <?php if ($page < $total_pages): ?>
                                    <a href="?page=<?php echo ($page + 1); ?>" class="inline-flex items-center px-3 py-2 text-sm font-medium text-gray-500 bg-white border border-gray-300 rounded-md hover:bg-gray-50">
                                        Next
                                        <i class="fas fa-chevron-right ml-1"></i>
                                    </a>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Fixed Notification Detail Modal -->
    <div id="notificationModal" class="fixed inset-0 z-50 hidden">
        <!-- Backdrop -->
        <div class="modal-backdrop fixed inset-0 transition-opacity" onclick="closeNotificationModal()"></div>
        
        <!-- Modal Container -->
        <div class="fixed inset-0 z-10 overflow-y-auto">
            <div class="flex min-h-full items-center justify-center p-4 text-center sm:p-0">
                <!-- Modal Content -->
                <div class="modal-content modal-enter relative transform overflow-hidden bg-white text-left shadow-xl transition-all sm:my-8 sm:w-full sm:max-w-lg">
                    <!-- Modal Header -->
                    <div class="bg-white px-4 pb-4 pt-5 sm:p-6 sm:pb-4">
                        <div class="flex items-center justify-between mb-4">
                            <div class="flex items-center">
                                <div class="mx-auto flex h-12 w-12 flex-shrink-0 items-center justify-center rounded-full bg-blue-100 sm:mx-0 sm:h-10 sm:w-10">
                                    <i class="fas fa-bell text-blue-600"></i>
                                </div>
                                <div class="ml-4 text-left">
                                    <h3 class="text-lg font-medium leading-6 text-gray-900">
                                        Notification Details
                                    </h3>
                                </div>
                            </div>
                            <button onclick="closeNotificationModal()" class="rounded-md bg-white text-gray-400 hover:text-gray-600 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2">
                                <span class="sr-only">Close</span>
                                <i class="fas fa-times h-6 w-6"></i>
                            </button>
                        </div>
                        
                        <!-- Modal Body -->
                        <div id="notificationModalContent" class="mt-3 text-center sm:mt-0 sm:text-left">
                            <!-- Loading State -->
                            <div class="flex items-center justify-center py-8">
                                <div class="animate-spin rounded-full h-8 w-8 border-b-2 border-blue-600"></div>
                                <span class="ml-3 text-gray-600">Loading notification details...</span>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Modal Footer -->
                    <div class="bg-gray-50 px-4 py-3 sm:flex sm:flex-row-reverse sm:px-6">
                        <div id="notificationModalActions" class="inline-flex w-full justify-center rounded-md px-3 py-2 text-sm font-semibold sm:ml-3 sm:w-auto">
                            <!-- Action buttons will be populated by JavaScript -->
                        </div>
                        <button onclick="closeNotificationModal()" type="button" class="mt-3 inline-flex w-full justify-center rounded-md bg-white px-3 py-2 text-sm font-semibold text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 hover:bg-gray-50 sm:mt-0 sm:w-auto">
                            Close
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- JavaScript -->
    <script>
        let currentNotificationId = null;
        
        // Mobile menu toggle
        document.getElementById('mobileMenuBtn').addEventListener('click', function() {
            document.getElementById('sidebar').classList.remove('-translate-x-full');
            document.getElementById('sidebarOverlay').classList.remove('hidden');
            document.body.style.overflow = 'hidden';
        });

        document.getElementById('closeSidebar').addEventListener('click', closeMobileSidebar);
        document.getElementById('sidebarOverlay').addEventListener('click', closeMobileSidebar);

        function closeMobileSidebar() {
            document.getElementById('sidebar').classList.add('-translate-x-full');
            document.getElementById('sidebarOverlay').classList.add('hidden');
            document.body.style.overflow = 'auto';
        }

        // Profile dropdown
        document.getElementById('profileBtn').addEventListener('click', function(e) {
            e.stopPropagation();
            const dropdown = document.getElementById('profileDropdown');
            dropdown.classList.toggle('hidden');
        });

        // Close dropdown when clicking outside
        document.addEventListener('click', function(event) {
            const profileBtn = document.getElementById('profileBtn');
            const dropdown = document.getElementById('profileDropdown');
            
            if (!profileBtn.contains(event.target) && !dropdown.contains(event.target)) {
                dropdown.classList.add('hidden');
            }
        });

        // Mark all notifications as read
        function markAllAsRead() {
            if (!confirm('Mark all notifications as read?')) {
                return;
            }
            
            const formData = new FormData();
            formData.append('action', 'mark_all_as_read');
            
            fetch('notifications.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    location.reload(); // Refresh page to show updated state
                } else {
                    alert('Failed to mark all notifications as read. Please try again.');
                }
            })
            .catch(error => {
                console.error('Error:', error);
                alert('An error occurred. Please try again.');
            });
        }

        // View notification details
        function viewNotificationDetails(notificationId) {
            currentNotificationId = notificationId;
            
            // Show modal
            const modal = document.getElementById('notificationModal');
            modal.classList.remove('hidden');
            
            // Prevent body scrolling
            document.body.style.overflow = 'hidden';
            
            // Reset content to loading state
            document.getElementById('notificationModalContent').innerHTML = `
                <div class="flex items-center justify-center py-8">
                    <div class="animate-spin rounded-full h-8 w-8 border-b-2 border-blue-600"></div>
                    <span class="ml-3 text-gray-600">Loading notification details...</span>
                </div>
            `;
            
            // Clear actions
            document.getElementById('notificationModalActions').innerHTML = '';
            
            // Fetch notification details
            const formData = new FormData();
            formData.append('action', 'get_notification_details');
            formData.append('notification_id', notificationId);
            
            fetch('notifications.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    displayNotificationDetails(data.notification);
                    
                    // Mark as read if unread
                    if (data.notification.is_read == 0) {
                        markNotificationAsRead(notificationId, false);
                    }
                } else {
                    document.getElementById('notificationModalContent').innerHTML = `
                        <div class="text-center py-8">
                            <div class="mx-auto flex h-12 w-12 items-center justify-center rounded-full bg-red-100">
                                <i class="fas fa-exclamation-triangle h-6 w-6 text-red-600"></i>
                            </div>
                            <div class="mt-3 text-center sm:mt-5">
                                <h3 class="text-lg font-medium text-gray-900">Error Loading Notification</h3>
                                <div class="mt-2">
                                    <p class="text-sm text-gray-500">${data.message || 'Failed to load notification details'}</p>
                                </div>
                            </div>
                        </div>
                    `;
                }
            })
            .catch(error => {
                console.error('Error:', error);
                document.getElementById('notificationModalContent').innerHTML = `
                    <div class="text-center py-8">
                        <div class="mx-auto flex h-12 w-12 items-center justify-center rounded-full bg-red-100">
                            <i class="fas fa-exclamation-triangle h-6 w-6 text-red-600"></i>
                        </div>
                        <div class="mt-3 text-center sm:mt-5">
                            <h3 class="text-lg font-medium text-gray-900">Network Error</h3>
                            <div class="mt-2">
                                <p class="text-sm text-gray-500">An error occurred while loading the notification details. Please check your connection and try again.</p>
                            </div>
                        </div>
                    </div>
                `;
            });
        }

        // Display notification details in modal
        function displayNotificationDetails(notification) {
            const createdDate = new Date(notification.created_at).toLocaleString('en-US', {
                year: 'numeric',
                month: 'short',
                day: 'numeric',
                hour: '2-digit',
                minute: '2-digit'
            });
            
            const readDate = notification.read_at ? new Date(notification.read_at).toLocaleString('en-US', {
                year: 'numeric',
                month: 'short',
                day: 'numeric',
                hour: '2-digit',
                minute: '2-digit'
            }) : null;
            
            let typeIcon = 'fa-info-circle';
            let typeClass = 'bg-gray-100 text-gray-800';
            let iconBgClass = 'bg-gray-100';
            let iconTextClass = 'text-gray-600';
            
            switch(notification.type) {
                case 'document_approved':
                    typeIcon = 'fa-check-circle';
                    typeClass = 'bg-green-100 text-green-800';
                    iconBgClass = 'bg-green-100';
                    iconTextClass = 'text-green-600';
                    break;
                case 'document_rejected':
                    typeIcon = 'fa-times-circle';
                    typeClass = 'bg-red-100 text-red-800';
                    iconBgClass = 'bg-red-100';
                    iconTextClass = 'text-red-600';
                    break;
                case 'evaluation_received':
                case 'evaluation_updated':
                case 'evaluation_reminder':
                    typeIcon = 'fa-star';
                    typeClass = 'bg-blue-100 text-blue-800';
                    iconBgClass = 'bg-blue-100';
                    iconTextClass = 'text-blue-600';
                    break;
                case 'attendance_reminder':
                case 'attendance_approved':
                case 'attendance_rejected':
                case 'attendance_updated':
                    typeIcon = 'fa-calendar-check';
                    typeClass = 'bg-purple-100 text-purple-800';
                    iconBgClass = 'bg-purple-100';
                    iconTextClass = 'text-purple-600';
                    break;
                case 'message_received':
                case 'message_reply':
                case 'new_message':
                    typeIcon = 'fa-envelope';
                    typeClass = 'bg-gray-100 text-gray-800';
                    iconBgClass = 'bg-gray-100';
                    iconTextClass = 'text-gray-600';
                    break;
                case 'self_assessment_reminder':
                case 'self_assessment_due':
                    typeIcon = 'fa-user-check';
                    typeClass = 'bg-indigo-100 text-indigo-800';
                    iconBgClass = 'bg-indigo-100';
                    iconTextClass = 'text-indigo-600';
                    break;
                case 'task_assigned':
                case 'task_updated':
                case 'task_approved':
                case 'task_rejected':
                case 'task_completed':
                case 'task_overdue':
                case 'task_reminder':
                    typeIcon = 'fa-tasks';
                    typeClass = 'bg-yellow-100 text-yellow-800';
                    iconBgClass = 'bg-yellow-100';
                    iconTextClass = 'text-yellow-600';
                    break;
                default:
                    typeIcon = 'fa-bell';
                    typeClass = 'bg-blue-100 text-blue-800';
                    iconBgClass = 'bg-blue-100';
                    iconTextClass = 'text-blue-600';
            }
            
            const content = `
                <div class="sm:flex sm:items-start">
                    <div class="mx-auto flex h-12 w-12 flex-shrink-0 items-center justify-center rounded-full ${iconBgClass} sm:mx-0 sm:h-10 sm:w-10">
                        <i class="fas ${typeIcon} ${iconTextClass}"></i>
                    </div>
                    <div class="mt-3 text-center sm:ml-4 sm:mt-0 sm:text-left flex-1">
                        <h3 class="text-lg font-medium leading-6 text-gray-900 mb-2">
                            ${escapeHtml(notification.title)}
                        </h3>
                        <div class="mt-2">
                            <p class="text-sm text-gray-600 leading-relaxed mb-4">
                                ${escapeHtml(notification.message)}
                            </p>
                            
                            <!-- Notification Type Badge -->
                            <div class="flex flex-wrap gap-2 mb-4">
                                <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium ${typeClass}">
                                    <i class="fas ${typeIcon} mr-1"></i>
                                    ${getTypeText(notification.type)}
                                </span>
                                ${notification.is_read == 0 ? `
                                <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-blue-100 text-blue-800">
                                    <i class="fas fa-circle mr-1" style="font-size: 0.4rem;"></i>
                                    Unread
                                </span>
                                ` : `
                                <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-green-100 text-green-800">
                                    <i class="fas fa-check-circle mr-1"></i>
                                    Read
                                </span>
                                `}
                            </div>
                            
                            <!-- Additional Details -->
                            <div class="border-t pt-4 space-y-3 text-sm text-gray-500">
                                <div class="flex items-center">
                                    <i class="fas fa-clock w-4 h-4 mr-3 text-gray-400"></i>
                                    <span class="font-medium text-gray-600">Created:</span>
                                    <span class="ml-2">${createdDate}</span>
                                </div>
                                ${readDate ? `
                                <div class="flex items-center">
                                    <i class="fas fa-eye w-4 h-4 mr-3 text-gray-400"></i>
                                    <span class="font-medium text-gray-600">Read:</span>
                                    <span class="ml-2">${readDate}</span>
                                </div>
                                ` : ''}
                                ${notification.document_name ? `
                                <div class="flex items-center">
                                    <i class="fas fa-file-alt w-4 h-4 mr-3 text-gray-400"></i>
                                    <span class="font-medium text-gray-600">Document:</span>
                                    <span class="ml-2">${escapeHtml(notification.document_name)}</span>
                                </div>
                                ` : ''}
                                ${notification.task_id ? `
                                <div class="flex items-center">
                                    <i class="fas fa-tasks w-4 h-4 mr-3 text-gray-400"></i>
                                    <span class="font-medium text-gray-600">Task ID:</span>
                                    <span class="ml-2">#${notification.task_id}</span>
                                </div>
                                ` : ''}
                            </div>
                        </div>
                    </div>
                </div>
            `;
            
            document.getElementById('notificationModalContent').innerHTML = content;
            
            // Add action button
            
        }

        // Get action URL for notification type
        function getActionUrl(notification) {
            switch(notification.type) {
                case 'document_approved':
                case 'document_rejected':
                case 'document_updated':
                case 'document_pending':
                case 'report_feedback':
                case 'report_approved':
                case 'report_rejected':
                    return 'studentReport.php';
                    
                case 'task_assigned':
                case 'task_updated':
                case 'task_approved':
                case 'task_rejected':
                case 'task_completed':
                case 'task_overdue':
                case 'task_reminder':
                    return notification.task_id ? `studentTask.php?task_id=${notification.task_id}` : 'studentTask.php';
                    
                case 'evaluation_received':
                case 'evaluation_updated':
                case 'evaluation_reminder':
                    return 'studentEvaluation.php';
                    
                case 'attendance_reminder':
                case 'attendance_approved':
                case 'attendance_rejected':
                case 'attendance_updated':
                    return 'studentAttendance.php';
                    
                case 'message_received':
                case 'message_reply':
                case 'new_message':
                    return 'studentMessage.php';
                    
                case 'self_assessment_reminder':
                case 'self_assessment_due':
                    return 'studentSelf-Assessment.php';
                    
                case 'deployment_confirmed':
                case 'deployment_completed':
                case 'deployment_terminated':
                case 'deployment_updated':
                    return 'studentdashboard.php?tab=deployment';
                    
                default:
                    return 'studentdashboard.php';
            }
        }

        // Get human readable type text
        function getTypeText(type) {
            switch(type) {
                case 'document_approved': return 'Document Approved';
                case 'document_rejected': return 'Document Rejected';
                case 'document_updated': return 'Document Updated';
                case 'document_pending': return 'Document Pending';
                case 'evaluation_received': return 'Evaluation Received';
                case 'evaluation_updated': return 'Evaluation Updated';
                case 'evaluation_reminder': return 'Evaluation Reminder';
                case 'attendance_reminder': return 'Attendance Reminder';
                case 'attendance_approved': return 'Attendance Approved';
                case 'attendance_rejected': return 'Attendance Rejected';
                case 'attendance_updated': return 'Attendance Updated';
                case 'message_received': return 'Message Received';
                case 'message_reply': return 'Message Reply';
                case 'new_message': return 'New Message';
                case 'self_assessment_reminder': return 'Self-Assessment Reminder';
                case 'self_assessment_due': return 'Self-Assessment Due';
                case 'task_assigned': return 'Task Assigned';
                case 'task_updated': return 'Task Updated';
                case 'task_approved': return 'Task Approved';
                case 'task_rejected': return 'Task Rejected';
                case 'task_completed': return 'Task Completed';
                case 'task_overdue': return 'Task Overdue';
                case 'task_reminder': return 'Task Reminder';
                case 'deployment_confirmed': return 'Deployment Confirmed';
                case 'deployment_completed': return 'Deployment Completed';
                case 'deployment_terminated': return 'Deployment Terminated';
                case 'deployment_updated': return 'Deployment Updated';
                case 'report_feedback': return 'Report Feedback';
                case 'report_approved': return 'Report Approved';
                case 'report_rejected': return 'Report Rejected';
                default: return 'General';
            }
        }

        // Utility function to escape HTML
        function escapeHtml(unsafe) {
            return unsafe
                .replace(/&/g, "&amp;")
                .replace(/</g, "&lt;")
                .replace(/>/g, "&gt;")
                .replace(/"/g, "&quot;")
                .replace(/'/g, "&#039;");
        }

        // Mark notification as read
        function markNotificationAsRead(notificationId, reload = true) {
            const formData = new FormData();
            formData.append('action', 'mark_as_read');
            formData.append('notification_id', notificationId);
            
            fetch('notifications.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success && reload) {
                    // Update the notification item visually
                    const notificationItem = document.querySelector(`[onclick="viewNotificationDetails(${notificationId})"]`);
                    if (notificationItem) {
                        notificationItem.classList.remove('unread', 'bg-blue-50', 'border-l-4', 'border-blue-500');
                        notificationItem.classList.add('hover:bg-gray-50');
                        
                        // Remove "New" badge
                        const newBadge = notificationItem.querySelector('.bg-blue-100.text-blue-800');
                        if (newBadge && newBadge.textContent.includes('New')) {
                            newBadge.remove();
                        }
                    }
                    
                    // Update unread count after a short delay
                    setTimeout(() => {
                        updateUnreadCount();
                    }, 500);
                }
            })
            .catch(error => {
                console.error('Error:', error);
            });
        }

        // Update unread count in header
        function updateUnreadCount() {
            // This would typically make another AJAX call to get the updated count
            // For now, we'll just reload the page after marking as read
            location.reload();
        }

        // Close notification modal
        function closeNotificationModal() {
            document.getElementById('notificationModal').classList.add('hidden');
            document.body.style.overflow = 'auto';
            currentNotificationId = null;
        }

        // Keyboard navigation
        document.addEventListener('keydown', function(event) {
            if (event.key === 'Escape') {
                const modal = document.getElementById('notificationModal');
                if (!modal.classList.contains('hidden')) {
                    closeNotificationModal();
                }
            }
        });

        // Prevent modal from closing when clicking inside the modal content
        document.querySelector('.modal-content').addEventListener('click', function(e) {
            e.stopPropagation();
        });
    </script>
</body>
</html>