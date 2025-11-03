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
    exit;
}

// Get supervisor information
$supervisor_id = $_SESSION['supervisor_id'];

// Fetch complete supervisor data including profile picture
try {
    $stmt = $conn->prepare("SELECT * FROM company_supervisors WHERE supervisor_id = ?");
    $stmt->bind_param("i", $supervisor_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows > 0) {
        $supervisor = $result->fetch_assoc();
        $supervisor_name = $supervisor['full_name'];
        $supervisor_email = $supervisor['email'];
        $company_name = $supervisor['company_name'];
        $profile_picture = $supervisor['profile_picture'] ?? '';
        
        // Create initials for avatar fallback
        $name_parts = explode(' ', trim($supervisor['full_name']));
        if (count($name_parts) >= 2) {
            $initials = strtoupper(substr($name_parts[0], 0, 1) . substr(end($name_parts), 0, 1));
        } else {
            $initials = strtoupper(substr($supervisor['full_name'], 0, 2));
        }
    } else {
        header("Location: login.php");
        exit();
    }
} catch (Exception $e) {
    $error_message = "Error fetching user data: " . $e->getMessage();
    // Fallback to session data
    $supervisor_name = $_SESSION['full_name'];
    $supervisor_email = $_SESSION['email'];
    $company_name = $_SESSION['company_name'];
    $profile_picture = '';
    $initials = strtoupper(substr($supervisor_name, 0, 2));
}

// Pagination settings
$items_per_page = 10;
$current_page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$offset = ($current_page - 1) * $items_per_page;

// Handle filter parameter
$filter_status = isset($_GET['filter']) ? $_GET['filter'] : 'all';
$filter_condition = '';
$filter_params = [$supervisor_id];

if ($filter_status !== 'all') {
    $status_map = [
        'submitted' => 'Submitted',
        'approved' => 'Approved', 
        'rejected' => 'Rejected'
    ];
    
    if (isset($status_map[$filter_status])) {
        $filter_condition = ' AND ts.status = ?';
        $filter_params[] = $status_map[$filter_status];
    }
}

// Handle approval/rejection actions
if (isset($_POST['action']) && isset($_POST['submission_id'])) {
    $submission_id = $_POST['submission_id'];
    $action = $_POST['action'];
    $feedback = isset($_POST['feedback']) ? trim($_POST['feedback']) : '';
    
    try {
        if ($action === 'approve') {
            // ✅ FIX: Update ALL collaborative submissions, not just one
            // First, get the task info to find all related tasks
            $get_task = $conn->prepare("
                SELECT t.task_title, t.supervisor_id, t.due_date, t.created_at
                FROM task_submissions ts
                JOIN tasks t ON ts.task_id = t.task_id
                WHERE ts.submission_id = ?
            ");
            $get_task->bind_param("i", $submission_id);
            $get_task->execute();
            $task_info = $get_task->get_result()->fetch_assoc();
            $get_task->close();
            
            if ($task_info) {
                // Update ALL team members' submissions to Approved
                $update_submissions = $conn->prepare("
                    UPDATE task_submissions ts
                    JOIN tasks t ON ts.task_id = t.task_id
                    SET ts.status = 'Approved', 
                        ts.feedback = ?, 
                        ts.reviewed_at = NOW()
                    WHERE t.task_title = ?
                        AND t.supervisor_id = ?
                        AND t.due_date = ?
                        AND t.created_at = ?
                ");
                $update_submissions->bind_param("ssiss", 
                    $feedback, 
                    $task_info['task_title'], 
                    $task_info['supervisor_id'], 
                    $task_info['due_date'], 
                    $task_info['created_at']
                );
                $update_submissions->execute();
                $update_submissions->close();
                
                // Update ALL team members' tasks to Completed
                $update_tasks = $conn->prepare("
                    UPDATE tasks 
                    SET status = 'Completed' 
                    WHERE task_title = ?
                        AND supervisor_id = ?
                        AND due_date = ?
                        AND created_at = ?
                ");
                $update_tasks->bind_param("siss", 
                    $task_info['task_title'], 
                    $task_info['supervisor_id'], 
                    $task_info['due_date'], 
                    $task_info['created_at']
                );
                $update_tasks->execute();
                $update_tasks->close();
                
                $_SESSION['success_message'] = "Task approved for all team members successfully!";
            } else {
                throw new Exception("Task not found.");
            }
            
        } elseif ($action === 'reject') {
            // ✅ FIX: Reject ALL collaborative submissions
            // Get task info first
            $get_task = $conn->prepare("
                SELECT t.task_title, t.supervisor_id, t.due_date, t.created_at
                FROM task_submissions ts
                JOIN tasks t ON ts.task_id = t.task_id
                WHERE ts.submission_id = ?
            ");
            $get_task->bind_param("i", $submission_id);
            $get_task->execute();
            $task_info = $get_task->get_result()->fetch_assoc();
            $get_task->close();
            
            if ($task_info) {
                // Update ALL team members' submissions to Rejected
                $update_submissions = $conn->prepare("
                    UPDATE task_submissions ts
                    JOIN tasks t ON ts.task_id = t.task_id
                    SET ts.status = 'Rejected', 
                        ts.feedback = ?, 
                        ts.reviewed_at = NOW()
                    WHERE t.task_title = ?
                        AND t.supervisor_id = ?
                        AND t.due_date = ?
                        AND t.created_at = ?
                ");
                $update_submissions->bind_param("ssiss", 
                    $feedback, 
                    $task_info['task_title'], 
                    $task_info['supervisor_id'], 
                    $task_info['due_date'], 
                    $task_info['created_at']
                );
                $update_submissions->execute();
                $update_submissions->close();
                
                // Update ALL team members' tasks back to In Progress (so they can resubmit)
                $update_tasks = $conn->prepare("
                    UPDATE tasks 
                    SET status = 'In Progress' 
                    WHERE task_title = ?
                        AND supervisor_id = ?
                        AND due_date = ?
                        AND created_at = ?
                ");
                $update_tasks->bind_param("siss", 
                    $task_info['task_title'], 
                    $task_info['supervisor_id'], 
                    $task_info['due_date'], 
                    $task_info['created_at']
                );
                $update_tasks->execute();
                $update_tasks->close();
                
                $_SESSION['success_message'] = "Task rejected for all team members. They can resubmit.";
            } else {
                throw new Exception("Task not found.");
            }
        }
    } catch (Exception $e) {
        $_SESSION['error_message'] = "Error processing action: " . $e->getMessage();
    }
    
    // Preserve current page and filter when redirecting
    $redirect_params = [];
    if ($current_page > 1) $redirect_params['page'] = $current_page;
    if ($filter_status !== 'all') $redirect_params['filter'] = $filter_status;
    
    $redirect_url = 'ApproveTasks.php';
    if (!empty($redirect_params)) {
        $redirect_url .= '?' . http_build_query($redirect_params);
    }
    
    header("Location: $redirect_url");
    exit;
}

// Get success/error messages
$success_message = isset($_SESSION['success_message']) ? $_SESSION['success_message'] : null;
$error_message = isset($_SESSION['error_message']) ? $_SESSION['error_message'] : null;
unset($_SESSION['success_message'], $_SESSION['error_message']);

// Get task submissions for this supervisor
try {
    // Get total count - GROUP BY task to avoid duplicates
    $count_query = "
        SELECT COUNT(DISTINCT CONCAT(t.task_title, '-', t.supervisor_id, '-', t.due_date, '-', t.created_at)) as total
        FROM task_submissions ts
        JOIN tasks t ON ts.task_id = t.task_id
        WHERE t.supervisor_id = ?" . $filter_condition;

    if ($filter_condition) {
        $count_stmt = $conn->prepare($count_query);
        $count_stmt->bind_param(str_repeat('s', count($filter_params)), ...$filter_params);
    } else {
        $count_stmt = $conn->prepare($count_query);
        $count_stmt->bind_param("i", $supervisor_id);
    }
    
    $count_stmt->execute();
    $count_result = $count_stmt->get_result();
    $total_submissions = $count_result->fetch_assoc()['total'];
    $total_pages = ceil($total_submissions / $items_per_page);
    
    // ✅ UPDATED QUERY - Group collaborative submissions into ONE entry
    $submissions_query = "
        SELECT 
            MIN(ts.submission_id) as submission_id,
            MIN(ts.task_id) as task_id,
            t.task_title,
            t.task_description,
            t.due_date,
            t.priority,
            t.task_category,
            MIN(ts.submission_description) as submission_description,
            MIN(ts.attachment) as attachment,
            MIN(ts.status) as status,
            MIN(ts.feedback) as feedback,
            MIN(ts.submitted_at) as submitted_at,
            MIN(ts.reviewed_at) as reviewed_at,
            GROUP_CONCAT(DISTINCT CONCAT(s.first_name, ' ', IFNULL(CONCAT(s.middle_name, ' '), ''), s.last_name) ORDER BY s.first_name SEPARATOR ', ') as collaborators,
            GROUP_CONCAT(DISTINCT s.student_id ORDER BY s.first_name SEPARATOR ', ') as student_ids,
            GROUP_CONCAT(DISTINCT s.id ORDER BY s.first_name SEPARATOR ',') as student_db_ids,
            COUNT(DISTINCT t2.student_id) as total_collaborators,
            MIN(s.first_name) as first_name,
            MIN(s.middle_name) as middle_name,
            MIN(s.last_name) as last_name,
            MIN(s.student_id) as student_id,
            MIN(s.email) as student_email,
            MIN(s.program) as program,
            MIN(s.department) as department,
            MIN(sd.position) as deployment_position
        FROM tasks t
        JOIN task_submissions ts ON t.task_id = ts.task_id
        JOIN students s ON ts.student_id = s.id
        LEFT JOIN student_deployments sd ON s.id = sd.student_id AND sd.status = 'Active'
        LEFT JOIN tasks t2 ON t.task_title = t2.task_title 
            AND t.supervisor_id = t2.supervisor_id 
            AND t.due_date = t2.due_date
            AND t.created_at = t2.created_at
        WHERE t.supervisor_id = ?" . $filter_condition . "
        GROUP BY t.task_title, t.supervisor_id, t.due_date, t.created_at, 
                 t.task_description, t.priority, t.task_category
        ORDER BY 
            CASE MIN(ts.status)
                WHEN 'Submitted' THEN 1 
                WHEN 'Reviewed' THEN 2 
                WHEN 'Approved' THEN 3 
                WHEN 'Rejected' THEN 4 
            END,
            MIN(ts.submitted_at) DESC
        LIMIT ? OFFSET ?
    ";
    
    $params = array_merge($filter_params, [$items_per_page, $offset]);
    $param_types = str_repeat('s', count($filter_params)) . 'ii';
    
    $stmt = $conn->prepare($submissions_query);
    $stmt->bind_param($param_types, ...$params);
    $stmt->execute();
    $submissions_result = $stmt->get_result();
    $submissions = $submissions_result->fetch_all(MYSQLI_ASSOC);
    
    // Get statistics for all submissions (not just current page)
    $stats_query = "
        SELECT 
            COUNT(DISTINCT CONCAT(t.task_title, '-', t.supervisor_id, '-', t.due_date, '-', t.created_at)) as total,
            COUNT(DISTINCT CASE WHEN ts.status = 'Submitted' 
                THEN CONCAT(t.task_title, '-', t.supervisor_id, '-', t.due_date, '-', t.created_at) 
                END) as pending,
            COUNT(DISTINCT CASE WHEN ts.status = 'Approved' 
                THEN CONCAT(t.task_title, '-', t.supervisor_id, '-', t.due_date, '-', t.created_at) 
                END) as approved,
            COUNT(DISTINCT CASE WHEN ts.status = 'Rejected' 
                THEN CONCAT(t.task_title, '-', t.supervisor_id, '-', t.due_date, '-', t.created_at) 
                END) as rejected
        FROM task_submissions ts
        JOIN tasks t ON ts.task_id = t.task_id
        WHERE t.supervisor_id = ?
    ";

    $stats_stmt = $conn->prepare($stats_query);
    $stats_stmt->bind_param("i", $supervisor_id);
    $stats_stmt->execute();
    $stats_result = $stats_stmt->get_result();
    $stats = $stats_result->fetch_assoc();

    $total_all_submissions = (int)$stats['total'];
    $pending_submissions = (int)$stats['pending'];
    $approved_submissions = (int)$stats['approved'];
    $rejected_submissions = (int)$stats['rejected'];
    
} catch (Exception $e) {
    $error_message = "Error fetching submissions: " . $e->getMessage();
    $submissions = [];
    $total_submissions = 0;
    $total_pages = 0;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>OnTheJob Tracker - Task Approval Management</title>
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

        /* Animation for card transitions */
        @keyframes slideIn {
            from {
                opacity: 0;
                transform: translateY(20px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .submission-card {
            animation: slideIn 0.5s ease-out;
        }

        /* Compact card styles for approved/rejected */
        .submission-card.status-approved,
        .submission-card.status-rejected {
            transform: scale(0.95);
            opacity: 0.85;
        }

        .submission-card.status-approved:hover,
        .submission-card.status-rejected:hover {
            transform: scale(0.97);
            opacity: 1;
        }

        .submission-card.status-approved.expanded,
        .submission-card.status-rejected.expanded {
            transform: scale(1);
            opacity: 1;
        }

        /* Hide detailed content for compact cards */
        .submission-card.status-approved .submission-details,
        .submission-card.status-rejected .submission-details {
            display: none;
        }

        .submission-card.status-approved.expanded .submission-details,
        .submission-card.status-rejected.expanded .submission-details {
            display: block;
        }

        .submission-card.status-approved .compact-summary,
        .submission-card.status-rejected .compact-summary {
            display: block;
        }

        .submission-card.status-approved.expanded .compact-summary,
        .submission-card.status-rejected.expanded .compact-summary {
            display: none;
        }

         .submission-card.status-submitted .compact-summary {
            display: none;
        }

        /* Document viewer modal styles - ADD THIS SECTION */
        .document-modal {
            background: rgba(0, 0, 0, 0.8);
            backdrop-filter: blur(4px);
        }

        .document-viewer {
            max-height: 90vh;
            max-width: 90vw;
        }

        .document-iframe {
            border: none;
            border-radius: 8px;
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
            <a href="CompanyStudentAccounts.php" class="nav-item flex items-center px-3 py-2 text-sm font-medium text-bulsu-light-gold hover:text-white hover:bg-bulsu-gold hover:bg-opacity-20 rounded-md transition-all duration-200">
                    <i class="fas fa-users mr-3 "></i>Student Accounts
                </a>
            <a href="CompanyTimeRecord.php" class="nav-item flex items-center px-3 py-2 text-sm font-medium text-bulsu-light-gold hover:text-white hover:bg-bulsu-gold hover:bg-opacity-20 rounded-md transition-all duration-200">
                <i class="fas fa-clock mr-3"></i>
                Student Time Record
            </a>
             <a href="CompanyScheduleManager.php" class="nav-item flex items-center px-3 py-2 text-sm font-medium text-bulsu-light-gold hover:text-white hover:bg-bulsu-gold hover:bg-opacity-20 rounded-md transition-all duration-200">
                    <i class="fas fa-calendar-alt mr-3"></i>
                    Schedule Manager
                </a>
            <a href="ApproveTasks.php" class="nav-item flex items-center px-3 py-2 text-sm font-medium text-white bg-bulsu-gold bg-opacity-20 border border-bulsu-gold border-opacity-30 rounded-md">
                <i class="fas fa-comment-dots mr-3 text-bulsu-gold"></i>
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
                <p class="text-sm font-medium text-white truncate"><?php echo htmlspecialchars($supervisor_name); ?></p>
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
                    <h1 class="text-xl sm:text-2xl font-bold text-gray-900">Task Approval Management</h1>
                    <p class="text-sm sm:text-base text-gray-500 hidden sm:block">Review and approve student task submissions</p>
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
                                    <p class="font-medium text-gray-900"><?php echo htmlspecialchars($supervisor_name); ?></p>
                                    <p class="text-sm text-gray-500">Company Supervisor</p>
                                    <p class="text-xs text-gray-400"><?php echo htmlspecialchars($company_name); ?></p>
                                </div>
                            </div>
                        </div>
                        <a href="CompanyAccountSettings.php" class="flex items-center px-4 py-2 text-sm text-gray-700 hover:bg-gray-50">
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
            <!-- Error Message Display -->
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
                        <i class="fas fa-exclamation-triangle text-red-600 mt-1 mr-3"></i>
                        <p class="text-red-700"><?php echo htmlspecialchars($error_message); ?></p>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Statistics Grid -->
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4 sm:gap-6 mb-6 sm:mb-8">
                <div class="bg-white p-4 sm:p-6 rounded-lg shadow-sm border border-gray-200 border-l-4 border-l-blue-500">
                    <div class="flex items-center justify-between">
                        <div>
                            <div class="text-2xl sm:text-3xl font-bold text-blue-600 mb-1"><?php echo $total_all_submissions; ?></div>
                            <div class="text-sm text-gray-600">Total Submissions</div>
                        </div>
                        <div class="text-blue-500">
                            <i class="fas fa-clipboard-list text-2xl"></i>
                        </div>
                    </div>
                </div>

                <div class="bg-white p-4 sm:p-6 rounded-lg shadow-sm border border-gray-200 border-l-4 border-l-yellow-500">
                    <div class="flex items-center justify-between">
                        <div>
                            <div class="text-2xl sm:text-3xl font-bold text-yellow-600 mb-1"><?php echo $pending_submissions; ?></div>
                            <div class="text-sm text-gray-600">Pending Review</div>
                        </div>
                        <div class="text-yellow-500">
                            <i class="fas fa-clock text-2xl"></i>
                        </div>
                    </div>
                </div>

                <div class="bg-white p-4 sm:p-6 rounded-lg shadow-sm border border-gray-200 border-l-4 border-l-green-500">
                    <div class="flex items-center justify-between">
                        <div>
                            <div class="text-2xl sm:text-3xl font-bold text-green-600 mb-1"><?php echo $approved_submissions; ?></div>
                            <div class="text-sm text-gray-600">Approved</div>
                        </div>
                        <div class="text-green-500">
                            <i class="fas fa-check-circle text-2xl"></i>
                        </div>
                    </div>
                </div>

                <div class="bg-white p-4 sm:p-6 rounded-lg shadow-sm border border-gray-200 border-l-4 border-l-red-500">
                    <div class="flex items-center justify-between">
                        <div>
                            <div class="text-2xl sm:text-3xl font-bold text-red-600 mb-1"><?php echo $rejected_submissions; ?></div>
                            <div class="text-sm text-gray-600">Rejected</div>
                        </div>
                        <div class="text-red-500">
                            <i class="fas fa-times-circle text-2xl"></i>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Filter Tabs -->
            <div class="flex flex-wrap gap-2 mb-6">
                <a href="?<?php echo http_build_query(array_merge($_GET, ['filter' => 'all', 'page' => 1])); ?>" 
                   class="filter-tab px-4 py-2 <?php echo ($filter_status === 'all') ? 'bg-blue-600 text-white' : 'bg-gray-200 text-gray-700 hover:bg-gray-300'; ?> rounded-lg font-medium transition-colors">
                    All Submissions
                </a>
                <a href="?<?php echo http_build_query(array_merge($_GET, ['filter' => 'submitted', 'page' => 1])); ?>" 
                   class="filter-tab px-4 py-2 <?php echo ($filter_status === 'submitted') ? 'bg-blue-600 text-white' : 'bg-gray-200 text-gray-700 hover:bg-gray-300'; ?> rounded-lg font-medium transition-colors">
                    Pending Review
                </a>
                <a href="?<?php echo http_build_query(array_merge($_GET, ['filter' => 'approved', 'page' => 1])); ?>" 
                   class="filter-tab px-4 py-2 <?php echo ($filter_status === 'approved') ? 'bg-blue-600 text-white' : 'bg-gray-200 text-gray-700 hover:bg-gray-300'; ?> rounded-lg font-medium transition-colors">
                    Approved
                </a>
                <a href="?<?php echo http_build_query(array_merge($_GET, ['filter' => 'rejected', 'page' => 1])); ?>" 
                   class="filter-tab px-4 py-2 <?php echo ($filter_status === 'rejected') ? 'bg-blue-600 text-white' : 'bg-gray-200 text-gray-700 hover:bg-gray-300'; ?> rounded-lg font-medium transition-colors">
                    Rejected
                </a>
            </div>
            
            <!-- Submissions Container -->
            <div class="space-y-6">
                <?php if (empty($submissions)): ?>
                    <div class="bg-white rounded-lg shadow-sm border border-gray-200 p-8 text-center">
                        <i class="fas fa-clipboard-list text-gray-400 text-6xl mb-4"></i>
                        <h3 class="text-xl font-medium text-gray-900 mb-2">No Task Submissions</h3>
                        <p class="text-gray-600">
                            <?php if ($filter_status !== 'all'): ?>
                                No <?php echo ucfirst($filter_status); ?> submissions found.
                            <?php else: ?>
                                There are no task submissions to review at this time.
                            <?php endif; ?>
                        </p>
                    </div>
                <?php else: ?>
                    <?php foreach ($submissions as $submission): ?>
                        <?php
                        $student_full_name = $submission['first_name'];
                        if (!empty($submission['middle_name'])) {
                            $student_full_name .= ' ' . $submission['middle_name'];
                        }
                        $student_full_name .= ' ' . $submission['last_name'];
                        $student_initials = strtoupper(substr($submission['first_name'], 0, 1) . substr($submission['last_name'], 0, 1));
                        
                        $status_class = '';
                        $status_bg = '';
                        $status_text = '';
                        switch(strtolower($submission['status'])) {
                            case 'submitted':
                                $status_class = 'bg-yellow-100 text-yellow-800';
                                $status_bg = 'border-l-yellow-500';
                                break;
                            case 'approved':
                                $status_class = 'bg-green-100 text-green-800';
                                $status_bg = 'border-l-green-500';
                                break;
                            case 'rejected':
                                $status_class = 'bg-red-100 text-red-800';
                                $status_bg = 'border-l-red-500';
                                break;
                            default:
                                $status_class = 'bg-gray-100 text-gray-800';
                                $status_bg = 'border-l-gray-500';
                        }
                        
                        $priority_class = '';
                        switch(strtolower($submission['priority'])) {
                            case 'high':
                                $priority_class = 'bg-red-100 text-red-800';
                                break;
                            case 'critical':
                                $priority_class = 'bg-purple-100 text-purple-800';
                                break;
                            case 'medium':
                                $priority_class = 'bg-orange-100 text-orange-800';
                                break;
                            case 'low':
                                $priority_class = 'bg-green-100 text-green-800';
                                break;
                            default:
                                $priority_class = 'bg-gray-100 text-gray-800';
                        }
                        ?>
                        
                        <div class="submission-card status-<?php echo strtolower($submission['status']); ?> bg-white rounded-lg shadow-sm border border-gray-200 border-l-4 <?php echo $status_bg; ?> p-6 transition-all duration-300 hover:shadow-md" data-status="<?php echo strtolower($submission['status']); ?>">
                            <!-- Card Header -->
                            <div class="flex flex-col sm:flex-row sm:items-start sm:justify-between mb-4">
                                <div class="flex-1">
                                    <h3 class="text-lg font-semibold text-gray-900 mb-2"><?php echo htmlspecialchars($submission['task_title']); ?></h3>
                                </div>
                                <div class="flex flex-wrap gap-2 mt-2 sm:mt-0">
                                    <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium <?php echo $status_class; ?>">
                                        <?php echo htmlspecialchars($submission['status']); ?>
                                    </span>
                                    <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium <?php echo $priority_class; ?>">
                                        <?php echo htmlspecialchars($submission['priority']); ?>
                                    </span>
                                </div>
                            </div>
                            
                            <!-- Student Info -->
                            <?php if ($submission['total_collaborators'] > 1): ?>
    <div class="mb-4 p-3 bg-blue-50 border border-blue-200 rounded-lg">
        <div class="flex items-center mb-2">
            <i class="fas fa-users text-blue-600 mr-2"></i>
            <span class="text-sm font-medium text-blue-800">
                Collaborative Task - <?php echo $submission['total_collaborators']; ?> Students
            </span>
        </div>
        <p class="text-xs text-blue-600">
            Team Members: <?php echo htmlspecialchars($submission['collaborators']); ?>
        </p>
        <div class="mt-2 p-2 bg-blue-100 rounded text-xs text-blue-800">
            <i class="fas fa-info-circle mr-1"></i>
            This is a shared submission. All team members submitted the same work.
        </div>
    </div>
<?php endif; ?>
                            
                            <!-- Compact summary for approved/rejected submissions -->
                            <div class="compact-summary bg-gray-50 border-l-4 border-l-blue-500 p-3 mb-4 rounded text-sm text-gray-600">
                                <i class="fas fa-calendar-alt mr-2"></i>
                                Submitted: <?php echo date('M j, Y', strtotime($submission['submitted_at'])); ?>
                                <?php if (!empty($submission['reviewed_at'])): ?>
                                    • Reviewed: <?php echo date('M j, Y', strtotime($submission['reviewed_at'])); ?>
                                <?php endif; ?>
                                <?php if (!empty($submission['attachment'])): ?>
                                    • <i class="fas fa-paperclip ml-2"></i> Has attachment
                                <?php endif; ?>
                            </div>
                            
                            <!-- Detailed content -->
                            <div class="submission-details">
                                <!-- Task Description -->
                                <div class="mb-4">
                                    <h5 class="font-medium text-gray-900 mb-2">Task Description:</h5>
                                    <div class="bg-gray-50 rounded-lg p-3 text-sm text-gray-700 leading-relaxed">
                                        <?php echo nl2br(htmlspecialchars($submission['task_description'])); ?>
                                    </div>
                                </div>
                                
                                <!-- Student Submission -->
                                <div class="mb-4">
                                    <h5 class="font-medium text-gray-900 mb-2 flex items-center">
                                        <i class="fas fa-file-alt mr-2 text-blue-500"></i>
                                        Student Submission:
                                    </h5>
                                    <div class="bg-blue-50 border-l-4 border-l-blue-500 rounded-lg p-4">
                                        <div class="text-sm text-gray-700 leading-relaxed mb-3">
                                            <?php echo nl2br(htmlspecialchars($submission['submission_description'])); ?>
                                        </div>
                                        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between text-xs text-gray-500 pt-2 border-t border-blue-200">
                                            <span>
                                                <i class="fas fa-calendar-alt mr-1"></i>
                                                Submitted: <?php echo date('M j, Y g:i A', strtotime($submission['submitted_at'])); ?>
                                            </span>
                                            <?php if (!empty($submission['attachment'])): ?>
    <button onclick="viewAttachment('<?php echo htmlspecialchars($submission['attachment']); ?>', '<?php echo htmlspecialchars(basename($submission['attachment'])); ?>')" 
            class="inline-flex items-center text-blue-600 hover:text-blue-800 font-medium mt-1 sm:mt-0">
        <i class="fas fa-eye mr-1"></i>
        View Attachment
    </button>
<?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            
                            <!-- Action Buttons -->
                            <!-- Action Buttons -->
<div class="flex flex-col sm:flex-row gap-3">
    <?php if ($submission['status'] === 'Submitted'): ?>
        <?php 
        // Use collaborators if multiple students, otherwise use single student name
        $display_names = ($submission['total_collaborators'] > 1) 
            ? htmlspecialchars($submission['collaborators']) 
            : htmlspecialchars($student_full_name);
        ?>
        <button onclick="showApproveModal(<?php echo $submission['submission_id']; ?>, '<?php echo htmlspecialchars($submission['task_title']); ?>', '<?php echo $display_names; ?>', <?php echo $submission['total_collaborators']; ?>)" 
                class="flex items-center justify-center px-4 py-2 bg-green-600 text-white rounded-lg font-medium hover:bg-green-700 transition-colors">
            <i class="fas fa-check mr-2"></i>
            Approve
        </button>
        <button onclick="showRejectModal(<?php echo $submission['submission_id']; ?>, '<?php echo htmlspecialchars($submission['task_title']); ?>', '<?php echo $display_names; ?>', <?php echo $submission['total_collaborators']; ?>)" 
                class="flex items-center justify-center px-4 py-2 bg-red-600 text-white rounded-lg font-medium hover:bg-red-700 transition-colors">
            <i class="fas fa-times mr-2"></i>
            Reject
        </button>
                                <?php else: ?>
                                    <button onclick="viewSubmissionDetails(<?php echo $submission['submission_id']; ?>)" 
                                            class="flex items-center justify-center px-4 py-2 bg-blue-600 text-white rounded-lg font-medium hover:bg-blue-700 transition-colors">
                                        <i class="fas fa-eye mr-2"></i>
                                        View Details
                                    </button>
                                    <?php if ($submission['status'] === 'Approved' || $submission['status'] === 'Rejected'): ?>
                                        <button onclick="toggleCardExpansion(this)" 
                                                class="flex items-center justify-center px-3 py-2 border border-gray-300 text-gray-700 rounded-lg font-medium hover:bg-gray-50 transition-colors">
                                            <i class="fas fa-expand-alt mr-2"></i>
                                            Show Details
                                        </button>
                                    <?php endif; ?>
                                <?php endif; ?>
                            </div>
                            
                            <!-- Feedback Section -->
                            <?php if (!empty($submission['feedback'])): ?>
                                <div class="mt-6 bg-gray-50 border-l-4 border-l-indigo-500 rounded-lg p-4">
                                    <h5 class="font-medium text-gray-900 mb-2 flex items-center">
                                        <i class="fas fa-comment mr-2 text-indigo-500"></i>
                                        Your Feedback:
                                    </h5>
                                    <div class="text-sm text-gray-700 leading-relaxed">
                                        <?php echo nl2br(htmlspecialchars($submission['feedback'])); ?>
                                    </div>
                                    <?php if (!empty($submission['reviewed_at'])): ?>
                                        <div class="mt-2 text-xs text-gray-500">
                                            Reviewed: <?php echo date('M j, Y g:i A', strtotime($submission['reviewed_at'])); ?>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>

            <!-- Pagination -->
            <?php if ($total_pages > 1): ?>
                <div class="flex flex-col sm:flex-row items-center justify-between mt-8 pt-6 border-t border-gray-200">
                    <div class="text-sm text-gray-700 mb-4 sm:mb-0">
                        Showing <?php echo (($current_page - 1) * $items_per_page) + 1; ?> to 
                        <?php echo min($current_page * $items_per_page, $total_submissions); ?> of 
                        <?php echo $total_submissions; ?> submissions
                        <?php if ($filter_status !== 'all'): ?>
                            (<?php echo ucfirst($filter_status); ?> only)
                        <?php endif; ?>
                    </div>
                    
                    <div class="flex items-center space-x-2">
                        <!-- Previous Button -->
                        <?php if ($current_page > 1): ?>
                            <?php
                            $prev_params = $_GET;
                            $prev_params['page'] = $current_page - 1;
                            ?>
                            <a href="?<?php echo http_build_query($prev_params); ?>" 
                               class="px-3 py-2 text-sm font-medium text-gray-500 bg-white border border-gray-300 rounded-md hover:bg-gray-50">
                                <i class="fas fa-chevron-left mr-1"></i>Previous
                            </a>
                        <?php endif; ?>
                        
                        <!-- Page Numbers -->
                        <?php
                        $start_page = max(1, $current_page - 2);
                        $end_page = min($total_pages, $current_page + 2);
                        
                        if ($start_page > 1): ?>
                            <?php
                            $first_params = $_GET;
                            $first_params['page'] = 1;
                            ?>
                            <a href="?<?php echo http_build_query($first_params); ?>" class="px-3 py-2 text-sm font-medium text-gray-500 bg-white border border-gray-300 rounded-md hover:bg-gray-50">1</a>
                            <?php if ($start_page > 2): ?>
                                <span class="px-2 text-gray-400">...</span>
                            <?php endif; ?>
                        <?php endif; ?>
                        
                        <?php for ($i = $start_page; $i <= $end_page; $i++): ?>
                            <?php if ($i == $current_page): ?>
                                <span class="px-3 py-2 text-sm font-medium text-white bg-blue-600 border border-blue-600 rounded-md">
                                    <?php echo $i; ?>
                                </span>
                            <?php else: ?>
                                <?php
                                $page_params = $_GET;
                                $page_params['page'] = $i;
                                ?>
                                <a href="?<?php echo http_build_query($page_params); ?>" 
                                   class="px-3 py-2 text-sm font-medium text-gray-500 bg-white border border-gray-300 rounded-md hover:bg-gray-50">
                                    <?php echo $i; ?>
                                </a>
                            <?php endif; ?>
                        <?php endfor; ?>
                        
                        <?php if ($end_page < $total_pages): ?>
                            <?php if ($end_page < $total_pages - 1): ?>
                                <span class="px-2 text-gray-400">...</span>
                            <?php endif; ?>
                            <?php
                            $last_params = $_GET;
                            $last_params['page'] = $total_pages;
                            ?>
                            <a href="?<?php echo http_build_query($last_params); ?>" 
                               class="px-3 py-2 text-sm font-medium text-gray-500 bg-white border border-gray-300 rounded-md hover:bg-gray-50"><?php echo $total_pages; ?></a>
                        <?php endif; ?>
                        
                        <!-- Next Button -->
                        <?php if ($current_page < $total_pages): ?>
                            <?php
                            $next_params = $_GET;
                            $next_params['page'] = $current_page + 1;
                            ?>
                            <a href="?<?php echo http_build_query($next_params); ?>" 
                               class="px-3 py-2 text-sm font-medium text-gray-500 bg-white border border-gray-300 rounded-md hover:bg-gray-50">
                                Next<i class="fas fa-chevron-right ml-1"></i>
                            </a>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>
    
    <!-- Approve Modal -->
    <div id="approveModal" class="fixed inset-0 bg-black bg-opacity-50 z-50 hidden flex items-center justify-center p-4">
        <div class="bg-white rounded-lg shadow-xl max-w-md w-full mx-4">
            <div class="flex items-center justify-between p-6 border-b border-gray-200">
                <h3 class="text-lg font-semibold text-gray-900 flex items-center">
                    <i class="fas fa-check-circle text-green-600 mr-2"></i>
                    Approve Task Submission
                </h3>
                <button onclick="closeApproveModal()" class="text-gray-400 hover:text-gray-600">
                    <i class="fas fa-times text-xl"></i>
                </button>
            </div>
            
            <form id="approveForm" method="POST" action="ApproveTasks.php">
                <div class="p-6">
                    <input type="hidden" name="action" value="approve">
                    <input type="hidden" id="approve_submission_id" name="submission_id" value="">
                    
                    <div class="bg-green-50 border-l-4 border-l-green-500 p-4 mb-6 rounded">
    <div class="font-medium text-green-800 mb-1">Task: <span id="approve_task_title"></span></div>
    <div class="text-green-700" id="approve_student_container">
        <span id="approve_student_label">Student:</span> <span id="approve_student_name"></span>
    </div>
</div>
                    
                    <div class="mb-6">
                        <label class="block text-sm font-medium text-gray-700 mb-2">
                            <i class="fas fa-comment mr-1"></i>
                            Feedback (Optional)
                        </label>
                        <textarea name="feedback" rows="4" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-green-500 focus:border-green-500" placeholder="Add any feedback or comments for the student..."></textarea>
                    </div>
                </div>
                
                <div class="flex justify-end gap-3 px-6 py-4 bg-gray-50 rounded-b-lg">
                    <button type="button" onclick="closeApproveModal()" class="px-4 py-2 text-gray-700 bg-gray-200 rounded-lg hover:bg-gray-300 transition-colors">
                        Cancel
                    </button>
                    <button type="submit" class="px-4 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700 transition-colors flex items-center">
                        <i class="fas fa-check mr-2"></i>
                        Approve Task
                    </button>
                </div>
            </form>
        </div>
    </div>
    
    <!-- Reject Modal -->
    <div id="rejectModal" class="fixed inset-0 bg-black bg-opacity-50 z-50 hidden flex items-center justify-center p-4">
        <div class="bg-white rounded-lg shadow-xl max-w-md w-full mx-4">
            <div class="flex items-center justify-between p-6 border-b border-gray-200">
                <h3 class="text-lg font-semibold text-gray-900 flex items-center">
                    <i class="fas fa-times-circle text-red-600 mr-2"></i>
                    Reject Task Submission
                </h3>
                <button onclick="closeRejectModal()" class="text-gray-400 hover:text-gray-600">
                    <i class="fas fa-times text-xl"></i>
                </button>
            </div>
            
            <form id="rejectForm" method="POST" action="ApproveTasks.php">
                <div class="p-6">
                    <input type="hidden" name="action" value="reject">
                    <input type="hidden" id="reject_submission_id" name="submission_id" value="">
                    
             <div class="bg-red-50 border-l-4 border-l-red-500 p-4 mb-6 rounded">
    <div class="font-medium text-red-800 mb-1">Task: <span id="reject_task_title"></span></div>
    <div class="text-red-700" id="reject_student_container">
        <span id="reject_student_label">Student:</span> <span id="reject_student_name"></span>
    </div>
</div>
                    
                    <div class="mb-4">
                        <label class="block text-sm font-medium text-gray-700 mb-2">
                            <i class="fas fa-comment mr-1"></i>
                            Reason for Rejection <span class="text-red-500">*</span>
                        </label>
                        <textarea name="feedback" rows="4" required class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-red-500 focus:border-red-500" placeholder="Please provide a clear reason for rejecting this submission..."></textarea>
                    </div>
                    
                    <div class="bg-yellow-50 border-l-4 border-l-yellow-500 p-3 mb-6 rounded">
                        <div class="text-yellow-800 text-sm flex items-start">
                            <i class="fas fa-info-circle mr-2 mt-0.5"></i>
                            The student will be notified and can resubmit the task with your feedback.
                        </div>
                    </div>
                </div>
                
                <div class="flex justify-end gap-3 px-6 py-4 bg-gray-50 rounded-b-lg">
                    <button type="button" onclick="closeRejectModal()" class="px-4 py-2 text-gray-700 bg-gray-200 rounded-lg hover:bg-gray-300 transition-colors">
                        Cancel
                    </button>
                    <button type="submit" class="px-4 py-2 bg-red-600 text-white rounded-lg hover:bg-red-700 transition-colors flex items-center">
                        <i class="fas fa-times mr-2"></i>
                        Reject Task
                    </button>
                </div>
            </form>
        </div>
    </div>

    <div id="documentViewerModal" class="fixed inset-0 document-modal z-50 hidden">
        <div class="flex items-center justify-center min-h-screen p-4">
            <div class="bg-white rounded-lg shadow-2xl document-viewer w-full h-full max-w-6xl flex flex-col" style="height: 90vh;">
                <!-- Modal Header -->
                <div class="flex items-center justify-between p-4 border-b border-gray-200 bg-gradient-to-r from-bulsu-maroon to-bulsu-dark-maroon text-white rounded-t-lg">
                    <div class="flex items-center">
                        <i class="fas fa-file-alt mr-3 text-bulsu-gold"></i>
                        <h3 id="documentTitle" class="text-lg font-medium">Document Viewer</h3>
                    </div>
                    <div class="flex items-center space-x-2">
                        <button onclick="closeDocumentViewer()" class="text-bulsu-light-gold hover:text-white p-1">
                            <i class="fas fa-times text-xl"></i>
                        </button>
                    </div>
                </div>
                
                <!-- Modal Body -->
                <div class="flex-1 p-4 overflow-hidden">
                    <div id="documentContent" class="w-full h-full flex items-center justify-center">
                        <!-- Content will be loaded here -->
                    </div>
                </div>
            </div>
        </div>
    </div>
    <!-- View Details Modal -->
    <div id="detailsModal" class="fixed inset-0 bg-black bg-opacity-50 z-50 hidden flex items-center justify-center p-4">
        <div class="bg-white rounded-lg shadow-xl max-w-2xl w-full mx-4 max-h-[90vh] overflow-y-auto">
            <div class="flex items-center justify-between p-6 border-b border-gray-200">
                <h3 class="text-lg font-semibold text-gray-900 flex items-center">
                    <i class="fas fa-eye text-blue-600 mr-2"></i>
                    Submission Details
                </h3>
                <button onclick="closeDetailsModal()" class="text-gray-400 hover:text-gray-600">
                    <i class="fas fa-times text-xl"></i>
                </button>
            </div>
            
            <div id="detailsModalContent" class="p-6">
                <!-- Details will be populated here -->
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

        // Toggle card expansion for approved/rejected submissions
        function toggleCardExpansion(button) {
            const card = button.closest('.submission-card');
            const submissionDetails = card.querySelector('.submission-details');
            const compactSummary = card.querySelector('.compact-summary');
            const icon = button.querySelector('i');
            const buttonText = button.childNodes[button.childNodes.length - 1];
            
            if (card.classList.contains('expanded')) {
                // Collapse the card
                card.classList.remove('expanded');
                submissionDetails.style.display = 'none';
                compactSummary.style.display = 'block';
                
                icon.className = 'fas fa-expand-alt mr-2';
                buttonText.textContent = ' Show Details';
            } else {
                // Expand the card
                card.classList.add('expanded');
                submissionDetails.style.display = 'block';
                compactSummary.style.display = 'none';
                
                icon.className = 'fas fa-compress-alt mr-2';
                buttonText.textContent = ' Hide Details';
            }
        }

        // Show approve modal
        // Show approve modal
function showApproveModal(submissionId, taskTitle, studentName, totalCollaborators) {
    document.getElementById('approve_submission_id').value = submissionId;
    document.getElementById('approve_task_title').textContent = taskTitle;
    document.getElementById('approve_student_name').textContent = studentName;
    
    // Update label based on number of students
    if (totalCollaborators > 1) {
        document.getElementById('approve_student_label').textContent = 'Students:';
    } else {
        document.getElementById('approve_student_label').textContent = 'Student:';
    }
    
    document.getElementById('approveModal').classList.remove('hidden');
}

        // Close approve modal
        function closeApproveModal() {
            document.getElementById('approveModal').classList.add('hidden');
            document.getElementById('approveForm').reset();
        }

        // Show reject modal
        // Show reject modal
function showRejectModal(submissionId, taskTitle, studentName, totalCollaborators) {
    document.getElementById('reject_submission_id').value = submissionId;
    document.getElementById('reject_task_title').textContent = taskTitle;
    document.getElementById('reject_student_name').textContent = studentName;
    
    // Update label based on number of students
    if (totalCollaborators > 1) {
        document.getElementById('reject_student_label').textContent = 'Students:';
    } else {
        document.getElementById('reject_student_label').textContent = 'Student:';
    }
    
    document.getElementById('rejectModal').classList.remove('hidden');
}

        // Close reject modal
        function closeRejectModal() {
            document.getElementById('rejectModal').classList.add('hidden');
            document.getElementById('rejectForm').reset();
        }

        // View submission details
        function viewSubmissionDetails(submissionId) {
            const submissions = <?php echo json_encode($submissions); ?>;
            const submission = submissions.find(s => s.submission_id == submissionId);
            
            if (submission) {
                const studentFullName = submission.first_name + 
                    (submission.middle_name ? ' ' + submission.middle_name : '') + 
                    ' ' + submission.last_name;
                
                const statusClass = submission.status.toLowerCase() === 'submitted' ? 'bg-yellow-100 text-yellow-800' :
                    submission.status.toLowerCase() === 'approved' ? 'bg-green-100 text-green-800' :
                    submission.status.toLowerCase() === 'rejected' ? 'bg-red-100 text-red-800' :
                    'bg-gray-100 text-gray-800';
                
                const priorityClass = submission.priority.toLowerCase() === 'high' ? 'bg-red-100 text-red-800' :
                    submission.priority.toLowerCase() === 'critical' ? 'bg-purple-100 text-purple-800' :
                    submission.priority.toLowerCase() === 'medium' ? 'bg-orange-100 text-orange-800' :
                    submission.priority.toLowerCase() === 'low' ? 'bg-green-100 text-green-800' :
                    'bg-gray-100 text-gray-800';
                
                const content = `
                    <div class="mb-6">
                        <div class="bg-gray-50 border-l-4 border-l-blue-500 p-4 rounded-lg">
                            <h4 class="font-semibold text-gray-900 mb-2">${submission.task_title}</h4>
                            <p class="text-gray-700">Student: ${studentFullName}</p>
                        </div>
                    </div>
                    
                    <div class="mb-6">
                        <h5 class="font-medium text-gray-900 mb-2">Task Description:</h5>
                        <div class="bg-gray-50 rounded-lg p-3 text-sm text-gray-700 leading-relaxed">
                            ${submission.task_description.replace(/\n/g, '<br>')}
                        </div>
                    </div>
                    
                    <div class="mb-6">
                        <h5 class="font-medium text-gray-900 mb-2 flex items-center">
                            <i class="fas fa-file-alt mr-2 text-blue-500"></i>
                            Student Submission:
                        </h5>
                        <div class="bg-blue-50 border-l-4 border-l-blue-500 rounded-lg p-4">
                            <div class="text-sm text-gray-700 leading-relaxed">
                                ${submission.submission_description.replace(/\n/g, '<br>')}
                            </div>
                        </div>
                    </div>
                    
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-6">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Status:</label>
                            <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium ${statusClass}">
                                ${submission.status}
                            </span>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Priority:</label>
<span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium ${priorityClass}">
                                ${submission.priority}
                            </span>
                        </div>
                    </div>
                    
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-6">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Submitted:</label>
                            <p class="text-sm text-gray-600">
                                ${new Date(submission.submitted_at).toLocaleDateString('en-US', {
                                    year: 'numeric',
                                    month: 'long',
                                    day: 'numeric',
                                    hour: '2-digit',
                                    minute: '2-digit'
                                })}
                            </p>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Due Date:</label>
                            <p class="text-sm text-gray-600">
                                ${new Date(submission.due_date).toLocaleDateString('en-US', {
                                    year: 'numeric',
                                    month: 'long',
                                    day: 'numeric'
                                })}
                            </p>
                        </div>
                    </div>
                    
                     ${submission.attachment ? `
                        <div class="mb-6">
                            <label class="block text-sm font-medium text-gray-700 mb-2">Attachment:</label>
                            <button onclick="viewAttachment('${submission.attachment}', '${submission.attachment.split('/').pop()}')" 
                                    class="inline-flex items-center px-3 py-2 bg-blue-100 text-blue-800 rounded-lg hover:bg-blue-200 transition-colors">
                                <i class="fas fa-eye mr-2"></i>
                                View Attachment
                            </button>
                        </div>
                    ` : ''}
                    
                    ${submission.feedback ? `
                        <div class="mb-6">
                            <label class="block text-sm font-medium text-gray-700 mb-2">Your Feedback:</label>
                            <div class="bg-gray-50 border-l-4 border-l-indigo-500 rounded-lg p-4">
                                <div class="text-sm text-gray-700 leading-relaxed">
                                    ${submission.feedback.replace(/\n/g, '<br>')}
                                </div>
                                ${submission.reviewed_at ? `
                                    <div class="mt-2 text-xs text-gray-500">
                                        Reviewed: ${new Date(submission.reviewed_at).toLocaleDateString('en-US', {
                                            year: 'numeric',
                                            month: 'long',
                                            day: 'numeric',
                                            hour: '2-digit',
                                            minute: '2-digit'
                                        })}
                                    </div>
                                ` : ''}
                            </div>
                        </div>
                    ` : ''}
                    
                    <div class="bg-gray-50 rounded-lg p-4">
                        <h5 class="font-medium text-gray-900 mb-2">Student Information:</h5>
                        <div class="text-sm text-gray-600 space-y-1">
                            <p><strong>Student ID:</strong> ${submission.student_id}</p>
                            <p><strong>Email:</strong> ${submission.student_email}</p>
                            <p><strong>Program:</strong> ${submission.program}</p>
                            <p><strong>Department:</strong> ${submission.department}</p>
                            ${submission.deployment_position ? `<p><strong>Position:</strong> ${submission.deployment_position}</p>` : ''}
                        </div>
                    </div>
                `;
                
                document.getElementById('detailsModalContent').innerHTML = content;
                document.getElementById('detailsModal').classList.remove('hidden');
            }
        }

        // Close details modal
        function closeDetailsModal() {
            document.getElementById('detailsModal').classList.add('hidden');
        }

        // Close modals with Escape key
        function viewAttachment(filePath, fileName) {
            const modal = document.getElementById('documentViewerModal');
            const content = document.getElementById('documentContent');
            const title = document.getElementById('documentTitle');
            
            title.textContent = fileName;
            content.innerHTML = '<div class="flex flex-col items-center justify-center h-full"><i class="fas fa-spinner fa-spin text-bulsu-gold text-3xl"></i><p class="mt-4 text-gray-600">Loading document...</p></div>';
            
            modal.classList.remove('hidden');
            document.body.style.overflow = 'hidden';
            
            const ext = fileName.toLowerCase().split('.').pop();
            
            if (ext === 'pdf') {
                content.innerHTML = `
                    <embed src="${filePath}" 
                           type="application/pdf" 
                           class="w-full h-full rounded-lg">
                `;
            } else if (['jpg', 'jpeg', 'png', 'gif'].includes(ext)) {
                content.innerHTML = `
                    <div class="w-full h-full flex items-center justify-center p-4">
                        <img src="${filePath}" 
                             alt="${fileName}"
                             class="max-w-full max-h-full object-contain rounded shadow-lg">
                    </div>
                `;
            } else if (['doc', 'docx'].includes(ext)) {
                const fullUrl = window.location.origin + '/' + filePath;
                content.innerHTML = `
                    <iframe src="https://docs.google.com/gview?url=${encodeURIComponent(fullUrl)}&embedded=true" 
                            class="w-full h-full rounded-lg"
                            frameborder="0"
                            style="background: white;">
                    </iframe>
                    <div class="absolute bottom-4 left-0 right-0 text-center">
                        <div class="inline-block bg-white px-4 py-2 rounded-lg shadow-lg">
                            <p class="text-sm text-gray-600 mb-2">Preview not loading?</p>
                            <a href="${filePath}" download="${fileName}" 
                               class="inline-flex items-center px-4 py-2 bg-bulsu-maroon text-white rounded-md hover:bg-bulsu-dark-maroon text-sm">
                                <i class="fas fa-download mr-2"></i>
                                Download Document
                            </a>
                        </div>
                    </div>
                `;
            } else {
                content.innerHTML = `
                    <div class="text-center p-8">
                        <i class="fas fa-file text-gray-400 text-6xl mb-4"></i>
                        <p class="text-gray-600 mb-4">Cannot preview this file type.</p>
                        <a href="${filePath}" download="${fileName}" 
                           class="inline-flex items-center px-4 py-2 bg-bulsu-maroon text-white rounded-md hover:bg-bulsu-dark-maroon">
                            <i class="fas fa-download mr-2"></i>
                            Download File
                        </a>
                    </div>
                `;
            }
        }

        function closeDocumentViewer() {
            const modal = document.getElementById('documentViewerModal');
            modal.classList.add('hidden');
            document.body.style.overflow = 'auto';
        }

        // UPDATED: Close modals with Escape key (ADD closeDocumentViewer)
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                closeApproveModal();
                closeRejectModal();
                closeDetailsModal();
                closeDocumentViewer(); // ADD THIS LINE
            }
        });

        // Close modals when clicking outside
        document.getElementById('approveModal').addEventListener('click', function(e) {
            if (e.target === this) {
                closeApproveModal();
            }
        });

        document.getElementById('rejectModal').addEventListener('click', function(e) {
            if (e.target === this) {
                closeRejectModal();
            }
        });

        document.getElementById('detailsModal').addEventListener('click', function(e) {
            if (e.target === this) {
                closeDetailsModal();
            }
        });

        // Logout confirmation
        function confirmLogout() {
            return confirm('Are you sure you want to logout?');
        }

        // Auto-hide success/error messages after 5 seconds
        document.addEventListener('DOMContentLoaded', function() {
            const successMessage = document.querySelector('.bg-green-50');
            const errorMessage = document.querySelector('.bg-red-50');
            
            if (successMessage) {
                setTimeout(function() {
                    successMessage.style.opacity = '0';
                    setTimeout(function() {
                        successMessage.remove();
                    }, 300);
                }, 5000);
            }
            
            if (errorMessage) {
                setTimeout(function() {
                    errorMessage.style.opacity = '0';
                    setTimeout(function() {
                        errorMessage.remove();
                    }, 300);
                }, 5000);
            }
        });

        // Form validation
        document.getElementById('rejectForm').addEventListener('submit', function(e) {
            const feedback = this.querySelector('textarea[name="feedback"]').value.trim();
            if (!feedback) {
                e.preventDefault();
                alert('Please provide a reason for rejecting this submission.');
                return false;
            }
        });

        // Auto-resize textareas
        document.addEventListener('input', function(e) {
            if (e.target.tagName === 'TEXTAREA') {
                e.target.style.height = 'auto';
                e.target.style.height = e.target.scrollHeight + 'px';
            }
        });
    </script>
</body>
</html>