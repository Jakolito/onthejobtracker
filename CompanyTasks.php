<?php
include('connect.php');
session_start();

// Improved notification function with better error handling
function createTaskNotification($conn, $student_id, $task_id, $task_title, $supervisor_name) {
    $title = "New Task Assigned";
    $message = "You have been assigned a new task: '{$task_title}' by {$supervisor_name}";
    $type = "task_assigned";
    
    // First check if the student exists
    $check_student = "SELECT id FROM students WHERE id = ?";
    $check_stmt = mysqli_prepare($conn, $check_student);
    if ($check_stmt) {
        mysqli_stmt_bind_param($check_stmt, "i", $student_id);
        mysqli_stmt_execute($check_stmt);
        $result = mysqli_stmt_get_result($check_stmt);
        if (mysqli_num_rows($result) == 0) {
            error_log("Student with ID $student_id not found");
            mysqli_stmt_close($check_stmt);
            return false;
        }
        mysqli_stmt_close($check_stmt);
    }
    
    // Insert notification
    $notification_query = "INSERT INTO student_notifications (student_id, title, message, type, task_id, is_read, created_at) 
                          VALUES (?, ?, ?, ?, ?, 0, NOW())";
    
    $stmt = mysqli_prepare($conn, $notification_query);
    if ($stmt) {
        mysqli_stmt_bind_param($stmt, "isssi", $student_id, $title, $message, $type, $task_id);
        $result = mysqli_stmt_execute($stmt);
        
        if ($result) {
            error_log("Notification created successfully for student ID: $student_id, task ID: $task_id");
        } else {
            error_log("Failed to create notification: " . mysqli_error($conn));
        }
        
        mysqli_stmt_close($stmt);
        return $result;
    } else {
        error_log("Failed to prepare notification statement: " . mysqli_error($conn));
        return false;
    }
}

// Prevent caching
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

// Initialize variables
$students_result = null;
$task_stats = array('total_tasks' => 0, 'pending_tasks' => 0, 'in_progress_tasks' => 0, 'completed_tasks' => 0, 'overdue_tasks' => 0);
$error_message = '';
$success_message = '';

// Handle task creation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_task'])) {
    $deployment_id = mysqli_real_escape_string($conn, $_POST['deployment_id']);
    $student_id = mysqli_real_escape_string($conn, $_POST['student_id']);
    $task_title = mysqli_real_escape_string($conn, $_POST['task_title']);
    $task_description = mysqli_real_escape_string($conn, $_POST['task_description']);
    $due_date = mysqli_real_escape_string($conn, $_POST['due_date']);
    $priority = mysqli_real_escape_string($conn, $_POST['priority']);
    $task_category = mysqli_real_escape_string($conn, $_POST['task_category']);
    $instructions = mysqli_real_escape_string($conn, $_POST['instructions']);
    $remarks = mysqli_real_escape_string($conn, $_POST['remarks']);

    // Validate required fields
    if (empty($task_title) || empty($task_description) || empty($due_date) || empty($priority) || empty($task_category)) {
        $error_message = "Please fill in all required fields.";
    } else {
        // Check if student's OJT status allows task creation
        $status_check_query = "SELECT ojt_status FROM student_deployments WHERE deployment_id = ? AND student_id = ?";
        $status_stmt = mysqli_prepare($conn, $status_check_query);
        
        if ($status_stmt) {
            mysqli_stmt_bind_param($status_stmt, "ii", $deployment_id, $student_id);
            mysqli_stmt_execute($status_stmt);
            $status_result = mysqli_stmt_get_result($status_stmt);
            
            if ($status_row = mysqli_fetch_assoc($status_result)) {
                $ojt_status = $status_row['ojt_status'];
                
                // Check if OJT is completed
                if (strtolower($ojt_status) === 'completed') {
                    $error_message = "Cannot create task. This student's OJT has already been completed.";
                } else {
                    // Proceed with task creation
                    $create_task_query = "INSERT INTO tasks (deployment_id, student_id, supervisor_id, task_title, task_description, due_date, priority, task_category, instructions, remarks, status, created_at) 
                                         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'Pending', NOW())";
                    
                    $stmt = mysqli_prepare($conn, $create_task_query);
                    if ($stmt) {
                        mysqli_stmt_bind_param($stmt, "iiisssssss", $deployment_id, $student_id, $supervisor_id, $task_title, $task_description, $due_date, $priority, $task_category, $instructions, $remarks);
                        
                        if (mysqli_stmt_execute($stmt)) {
                            $task_id = mysqli_insert_id($conn);
                            error_log("Task created with ID: $task_id for student ID: $student_id");
                            
                            // Create notification for the student
                            $notification_result = createTaskNotification($conn, $student_id, $task_id, $task_title, $supervisor_name);
                            
                            if ($notification_result) {
                                $success_message = "Task created successfully and notification sent to student!";
                                error_log("Notification sent successfully");
                            } else {
                                $success_message = "Task created successfully, but failed to send notification.";
                                error_log("Failed to send notification");
                            }
                        } else {
                            $error_message = "Error creating task: " . mysqli_error($conn);
                            error_log("Error creating task: " . mysqli_error($conn));
                        }
                        mysqli_stmt_close($stmt);
                    } else {
                        $error_message = "Error preparing task creation statement: " . mysqli_error($conn);
                    }
                }
            } else {
                $error_message = "Student deployment not found.";
            }
            mysqli_stmt_close($status_stmt);
        } else {
            $error_message = "Error checking student status: " . mysqli_error($conn);
        }
    }
}

// Get deployed students for this supervisor (exclude completed OJT students)
try {
    $students_query = "SELECT d.deployment_id, d.student_id, 
                              CONCAT(s.first_name, ' ', IFNULL(s.middle_name, ''), ' ', s.last_name) as student_name,
                              s.student_id as student_id_number, 
                              d.position, d.start_date, d.end_date, d.status, d.ojt_status
                       FROM student_deployments d 
                       JOIN students s ON d.student_id = s.id 
                       WHERE d.supervisor_id = ? AND d.status = 'Active'
                       ORDER BY s.first_name ASC, s.last_name ASC";
    
    $stmt = mysqli_prepare($conn, $students_query);
    if ($stmt) {
        mysqli_stmt_bind_param($stmt, "i", $supervisor_id);
        mysqli_stmt_execute($stmt);
        $students_result = mysqli_stmt_get_result($stmt);
        mysqli_stmt_close($stmt);
    } else {
        throw new Exception("Error preparing students query: " . mysqli_error($conn));
    }

    // Get task statistics
    $task_stats_query = "SELECT 
                        COUNT(*) as total_tasks,
                        SUM(CASE WHEN status = 'Pending' THEN 1 ELSE 0 END) as pending_tasks,
                        SUM(CASE WHEN status = 'In Progress' THEN 1 ELSE 0 END) as in_progress_tasks,
                        SUM(CASE WHEN status = 'Completed' THEN 1 ELSE 0 END) as completed_tasks,
                        SUM(CASE WHEN status = 'Overdue' THEN 1 ELSE 0 END) as overdue_tasks
                        FROM tasks WHERE supervisor_id = ?";
    
    $stmt2 = mysqli_prepare($conn, $task_stats_query);
    if ($stmt2) {
        mysqli_stmt_bind_param($stmt2, "i", $supervisor_id);
        mysqli_stmt_execute($stmt2);
        $task_stats_result = mysqli_stmt_get_result($stmt2);
        $task_stats = mysqli_fetch_assoc($task_stats_result);
        mysqli_stmt_close($stmt2);
        
        foreach ($task_stats as $key => $value) {
            if ($value === null) {
                $task_stats[$key] = 0;
            }
        }
    } else {
        throw new Exception("Error preparing task statistics query: " . mysqli_error($conn));
    }

} catch (Exception $e) {
    $error_message = "Error fetching data: " . $e->getMessage();
    $students_result = mysqli_query($conn, "SELECT * FROM student_deployments WHERE 1=0");
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>OnTheJob Tracker - Company Tasks</title>
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

        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
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
            <a href="CompanyTasks.php" class="nav-item flex items-center px-3 py-2 text-sm font-medium text-white bg-bulsu-gold bg-opacity-20 border border-bulsu-gold border-opacity-30 rounded-md">
                <i class="fas fa-tasks mr-3 text-bulsu-gold"></i>
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
                    <h1 class="text-xl sm:text-2xl font-bold text-gray-900">Task Management</h1>
                    <p class="text-sm sm:text-base text-gray-500 hidden sm:block">Manage tasks for deployed students</p>
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
            <?php if (isset($error_message) && !empty($error_message)): ?>
                <div class="mb-6 p-4 bg-red-50 border border-red-200 rounded-lg">
                    <div class="flex items-start">
                        <i class="fas fa-exclamation-triangle text-red-600 mt-1 mr-3"></i>
                        <p class="text-red-700"><?php echo htmlspecialchars($error_message); ?></p>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Success Message Display -->
            <?php if (isset($success_message) && !empty($success_message)): ?>
                <div class="mb-6 p-4 bg-green-50 border border-green-200 rounded-lg">
                    <div class="flex items-start">
                        <i class="fas fa-check-circle text-green-600 mt-1 mr-3"></i>
                        <p class="text-green-700"><?php echo htmlspecialchars($success_message); ?></p>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Task Statistics Grid -->
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-5 gap-4 sm:gap-6 mb-6 sm:mb-8">
                <div class="bg-white p-4 sm:p-6 rounded-lg shadow-sm border border-gray-200 border-l-4 border-l-blue-500">
                    <div class="flex items-center justify-between">
                        <div>
                            <div class="text-2xl sm:text-3xl font-bold text-blue-600 mb-1"><?php echo $task_stats['total_tasks']; ?></div>
                            <div class="text-sm text-gray-600">Total Tasks</div>
                        </div>
                        <div class="text-blue-500">
                            <i class="fas fa-tasks text-2xl"></i>
                        </div>
                    </div>
                </div>

                <div class="bg-white p-4 sm:p-6 rounded-lg shadow-sm border border-gray-200 border-l-4 border-l-yellow-500">
                    <div class="flex items-center justify-between">
                        <div>
                            <div class="text-2xl sm:text-3xl font-bold text-yellow-600 mb-1"><?php echo $task_stats['pending_tasks']; ?></div>
                            <div class="text-sm text-gray-600">Pending</div>
                        </div>
                        <div class="text-yellow-500">
                            <i class="fas fa-clock text-2xl"></i>
                        </div>
                    </div>
                </div>

                <div class="bg-white p-4 sm:p-6 rounded-lg shadow-sm border border-gray-200 border-l-4 border-l-blue-400">
                    <div class="flex items-center justify-between">
                        <div>
                            <div class="text-2xl sm:text-3xl font-bold text-blue-400 mb-1"><?php echo $task_stats['in_progress_tasks']; ?></div>
                            <div class="text-sm text-gray-600">In Progress</div>
                        </div>
                        <div class="text-blue-400">
                            <i class="fas fa-spinner text-2xl"></i>
                        </div>
                    </div>
                </div>

                <div class="bg-white p-4 sm:p-6 rounded-lg shadow-sm border border-gray-200 border-l-4 border-l-green-500">
                    <div class="flex items-center justify-between">
                        <div>
                            <div class="text-2xl sm:text-3xl font-bold text-green-600 mb-1"><?php echo $task_stats['completed_tasks']; ?></div>
                            <div class="text-sm text-gray-600">Completed</div>
                        </div>
                        <div class="text-green-500">
                            <i class="fas fa-check-circle text-2xl"></i>
                        </div>
                    </div>
                </div>

                <div class="bg-white p-4 sm:p-6 rounded-lg shadow-sm border border-gray-200 border-l-4 border-l-red-500">
                    <div class="flex items-center justify-between">
                        <div>
                            <div class="text-2xl sm:text-3xl font-bold text-red-600 mb-1"><?php echo $task_stats['overdue_tasks']; ?></div>
                            <div class="text-sm text-gray-600">Overdue</div>
                        </div>
                        <div class="text-red-500">
                            <i class="fas fa-exclamation-triangle text-2xl"></i>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Students Table -->
            <div class="bg-white rounded-lg shadow-sm border border-bulsu-maroon overflow-hidden">
    <!-- Header -->
    <div class="bg-gradient-to-r from-bulsu-maroon to-bulsu-dark-maroon px-6 py-4">
        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between space-y-4 sm:space-y-0">
            <!-- Title + Icon -->
            <div class="flex items-center">
                <i class="fas fa-users text-bulsu-gold mr-3"></i>
                <h3 class="text-lg font-medium text-white">Deployed Students</h3>
            </div>
            <!-- Actions -->
            <div class="flex flex-col sm:flex-row space-y-2 sm:space-y-0 sm:space-x-3">
                <!-- Refresh Button -->
                <button onclick="refreshStudents()" 
                        class="flex items-center justify-center px-4 py-2 text-sm font-medium text-bulsu-dark-maroon bg-bulsu-light-gold hover:bg-bulsu-gold rounded-md transition-colors">
                    <i class="fas fa-sync-alt mr-2"></i>
                    <span class="hidden sm:inline">Refresh</span>
                </button>
                <!-- Search Box -->
                <div class="relative">
                    <input type="text" id="searchStudents" placeholder="Search students..." 
                           class="pl-10 pr-4 py-2 border border-bulsu-gold rounded-md text-sm focus:ring-2 focus:ring-bulsu-gold focus:border-bulsu-maroon">
                    <i class="fas fa-search absolute left-3 top-2.5 text-gray-400"></i>
                </div>
            </div>
        </div>
    </div>


               <div class="overflow-x-auto">
        <table class="w-full">
            <thead class="bg-gray-50">
                <tr>
                    <th class="px-4 sm:px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Student Name</th>
                    <th class="px-4 sm:px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Student ID</th>
                    <th class="px-4 sm:px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Position</th>
                    <th class="px-4 sm:px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Start Date</th>
                    <th class="px-4 sm:px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">End Date</th>
                    <th class="px-4 sm:px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Status</th>
                    <th class="px-4 sm:px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Action</th>
                </tr>
            </thead>
            <tbody class="bg-white divide-y divide-gray-200">
                <?php if ($students_result && mysqli_num_rows($students_result) > 0): ?>
                    <?php while ($student = mysqli_fetch_assoc($students_result)): ?>
                        <tr class="hover:bg-gray-50 student-row" 
                            data-student-name="<?php echo strtolower($student['student_name']); ?>"
                            data-student-id="<?php echo strtolower($student['student_id_number']); ?>"
                            data-position="<?php echo strtolower($student['position']); ?>">
                            <td class="px-4 sm:px-6 py-4 whitespace-nowrap">
                                <div class="flex items-center">
                                    <div class="flex-shrink-0 w-8 h-8 bg-gradient-to-r from-purple-500 to-pink-600 rounded-full flex items-center justify-center text-white font-semibold text-sm">
                                        <?php echo strtoupper(substr($student['student_name'], 0, 1) . substr(strstr($student['student_name'], ' '), 1, 1)); ?>
                                    </div>
                                    <div class="ml-3">
                                        <p class="text-sm font-medium text-gray-900"><?php echo htmlspecialchars($student['student_name']); ?></p>
                                    </div>
                                </div>
                            </td>
                            <td class="px-4 sm:px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                <?php echo htmlspecialchars($student['student_id_number']); ?>
                            </td>
                            <td class="px-4 sm:px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                <?php echo htmlspecialchars($student['position']); ?>
                            </td>
                            <td class="px-4 sm:px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                <?php echo date('M d, Y', strtotime($student['start_date'])); ?>
                            </td>
                            <td class="px-4 sm:px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                <?php echo date('M d, Y', strtotime($student['end_date'])); ?>
                            </td>
                            <td class="px-4 sm:px-6 py-4 whitespace-nowrap">
                                <?php 
                                $ojt_status = strtolower($student['ojt_status']);
                                $status_class = '';
                                $status_icon = '';
                                
                                switch($ojt_status) {
                                    case 'completed':
                                        $status_class = 'bg-green-100 text-green-800';
                                        $status_icon = 'fas fa-check-circle';
                                        break;
                                    case 'ongoing':
                                        $status_class = 'bg-blue-100 text-blue-800';
                                        $status_icon = 'fas fa-clock';
                                        break;
                                    case 'pending':
                                        $status_class = 'bg-yellow-100 text-yellow-800';
                                        $status_icon = 'fas fa-hourglass-half';
                                        break;
                                    default:
                                        $status_class = 'bg-gray-100 text-gray-800';
                                        $status_icon = 'fas fa-question-circle';
                                }
                                ?>
                                <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium <?php echo $status_class; ?>">
                                    <i class="<?php echo $status_icon; ?> mr-1"></i>
                                    <?php echo ucfirst($student['ojt_status']); ?>
                                </span>
                            </td>
                            <td class="px-4 sm:px-6 py-4 whitespace-nowrap text-sm font-medium">
                                <?php if (strtolower($student['ojt_status']) === 'completed'): ?>
                                    <span class="text-gray-400">
                                        <i class="fas fa-ban mr-1"></i>
                                        OJT Completed
                                    </span>
                                <?php else: ?>
                                    <button onclick="openCreateTaskModal(<?php echo $student['deployment_id']; ?>, <?php echo $student['student_id']; ?>, '<?php echo addslashes($student['student_name']); ?>')" 
                                            class="inline-flex items-center px-3 py-1.5 border border-transparent text-xs font-medium rounded-md text-white bg-blue-600 hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500 transition-colors">
                                        <i class="fas fa-plus mr-1"></i>
                                        Create Task
                                    </button>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endwhile; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="7" class="px-4 sm:px-6 py-12 text-center">
                            <div class="flex flex-col items-center justify-center">
                                <i class="fas fa-users text-gray-300 text-4xl mb-4"></i>
                                <h3 class="text-lg font-medium text-gray-900 mb-2">No Students Found</h3>
                                <p class="text-gray-500 max-w-md">You don't have any deployed students at the moment. Students will appear here once they are assigned to you.</p>
                            </div>
                        </td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

    <!-- Create Task Modal -->
    <div id="createTaskModal" class="fixed inset-0 bg-black bg-opacity-50 z-50 hidden">
        <div class="flex items-center justify-center min-h-screen p-4">
            <div class="bg-white rounded-lg shadow-xl w-full max-w-2xl max-h-screen overflow-y-auto">
                <div class="p-6 border-b border-gray-200">
                    <div class="flex items-center justify-between">
                        <h3 class="text-lg font-medium text-gray-900">Create New Task</h3>
                        <button onclick="closeCreateTaskModal()" class="text-gray-400 hover:text-gray-600">
                            <i class="fas fa-times text-xl"></i>
                        </button>
                    </div>
                </div>

                <form method="POST" class="p-6">
                    <input type="hidden" name="create_task" value="1">
                    <input type="hidden" id="modal_deployment_id" name="deployment_id" value="">
                    <input type="hidden" id="modal_student_id" name="student_id" value="">

                    <div class="mb-4">
                        <label class="block text-sm font-medium text-gray-700 mb-2">Student</label>
                        <div class="p-3 bg-gray-50 rounded-md">
                            <span id="modal_student_name" class="text-sm font-medium text-gray-900"></span>
                        </div>
                    </div>

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
                        <div>
                            <label for="task_title" class="block text-sm font-medium text-gray-700 mb-2">Task Title *</label>
                            <input type="text" id="task_title" name="task_title" required 
                                   class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                                   placeholder="Enter task title">
                        </div>

                        <div>
                            <label for="task_category" class="block text-sm font-medium text-gray-700 mb-2">Category *</label>
                            <select id="task_category" name="task_category" required 
                                    class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                                <option value="">Select Category</option>
                                <option value="Development">Development</option>
                                <option value="Documentation">Documentation</option>
                                <option value="Testing">Testing</option>
                                <option value="Research">Research</option>
                                <option value="Meeting">Meeting</option>
                                <option value="Training">Training</option>
                                <option value="Other">Other</option>
                            </select>
                        </div>
                    </div>

                    <div class="mb-4">
                        <label for="task_description" class="block text-sm font-medium text-gray-700 mb-2">Description *</label>
                        <textarea id="task_description" name="task_description" rows="4" required 
                                  class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                                  placeholder="Enter task description"></textarea>
                    </div>

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
                        <div>
                            <label for="due_date" class="block text-sm font-medium text-gray-700 mb-2">Due Date *</label>
                            <input type="date" id="due_date" name="due_date" required 
                                   class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                        </div>

                        <div>
                            <label for="priority" class="block text-sm font-medium text-gray-700 mb-2">Priority *</label>
                            <select id="priority" name="priority" required 
                                    class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                                <option value="">Select Priority</option>
                                <option value="Low">Low</option>
                                <option value="Medium">Medium</option>
                                <option value="High">High</option>
                                <option value="Urgent">Urgent</option>
                            </select>
                        </div>
                    </div>

                    <div class="mb-4">
                        <label for="instructions" class="block text-sm font-medium text-gray-700 mb-2">Instructions</label>
                        <textarea id="instructions" name="instructions" rows="3" 
                                  class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                                  placeholder="Enter specific instructions for the task"></textarea>
                    </div>

                    <div class="mb-6">
                        <label for="remarks" class="block text-sm font-medium text-gray-700 mb-2">Remarks</label>
                        <textarea id="remarks" name="remarks" rows="2" 
                                  class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                                  placeholder="Enter any additional remarks or notes"></textarea>
                    </div>

                    <div class="flex items-center justify-end space-x-3 pt-4 border-t border-gray-200">
                        <button type="button" onclick="closeCreateTaskModal()" 
                                class="px-4 py-2 text-sm font-medium text-gray-700 bg-gray-100 hover:bg-gray-200 rounded-md transition-colors">
                            Cancel
                        </button>
                        <button type="submit" 
                                class="px-4 py-2 text-sm font-medium text-white bg-blue-600 hover:bg-blue-700 rounded-md transition-colors">
                            <i class="fas fa-plus mr-2"></i>
                            Create Task
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
            document.body.style.overflow = 'hidden';
        });

        closeSidebar.addEventListener('click', () => {
            sidebar.classList.add('-translate-x-full');
            sidebarOverlay.classList.add('hidden');
            document.body.style.overflow = '';
        });

        sidebarOverlay.addEventListener('click', () => {
            sidebar.classList.add('-translate-x-full');
            sidebarOverlay.classList.add('hidden');
            document.body.style.overflow = '';
        });

        // Profile Dropdown
        const profileBtn = document.getElementById('profileBtn');
        const profileDropdown = document.getElementById('profileDropdown');

        profileBtn.addEventListener('click', (e) => {
            e.stopPropagation();
            profileDropdown.classList.toggle('hidden');
        });

        document.addEventListener('click', () => {
            profileDropdown.classList.add('hidden');
        });

        profileDropdown.addEventListener('click', (e) => {
            e.stopPropagation();
        });

        // Task Modal Functions
        function openCreateTaskModal(deploymentId, studentId, studentName) {
            document.getElementById('modal_deployment_id').value = deploymentId;
            document.getElementById('modal_student_id').value = studentId;
            document.getElementById('modal_student_name').textContent = studentName;
            document.getElementById('createTaskModal').classList.remove('hidden');
            document.body.style.overflow = 'hidden';
            
            // Set minimum due date to today
            const today = new Date().toISOString().split('T')[0];
            document.getElementById('due_date').setAttribute('min', today);
        }

        function closeCreateTaskModal() {
            document.getElementById('createTaskModal').classList.add('hidden');
            document.body.style.overflow = '';
            
            // Reset form
            const form = document.querySelector('#createTaskModal form');
            form.reset();
        }

        // Close modal with Escape key
        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape') {
                closeCreateTaskModal();
            }
        });

        // Refresh Students Function
        function refreshStudents() {
            window.location.reload();
        }

        // Logout Confirmation
        function confirmLogout() {
            return confirm('Are you sure you want to logout?');
        }
        // Search functionality
const searchInput = document.getElementById('searchStudents');
if (searchInput) {
    searchInput.addEventListener('input', function() {
        const searchTerm = this.value.toLowerCase();
        const studentRows = document.querySelectorAll('.student-row');
        
        studentRows.forEach(function(row) {
            const studentName = row.getAttribute('data-student-name');
            const studentId = row.getAttribute('data-student-id');
            const position = row.getAttribute('data-position');
            
            const matches = studentName.includes(searchTerm) || 
                           studentId.includes(searchTerm) || 
                           position.includes(searchTerm);
            
            row.style.display = matches ? '' : 'none';
        });
    });
}

        // Auto-hide success/error messages after 5 seconds
        document.addEventListener('DOMContentLoaded', function() {
            const messages = document.querySelectorAll('.bg-red-50, .bg-green-50');
            messages.forEach(function(message) {
                setTimeout(function() {
                    message.style.transition = 'opacity 0.5s ease-out';
                    message.style.opacity = '0';
                    setTimeout(function() {
                        message.remove();
                    }, 500);
                }, 5000);
            });
        });

        // Form Validation
        document.querySelector('#createTaskModal form').addEventListener('submit', function(e) {
            const title = document.getElementById('task_title').value.trim();
            const description = document.getElementById('task_description').value.trim();
            const dueDate = document.getElementById('due_date').value;
            const priority = document.getElementById('priority').value;
            const category = document.getElementById('task_category').value;
            
            if (!title || !description || !dueDate || !priority || !category) {
                e.preventDefault();
                alert('Please fill in all required fields.');
                return;
            }
            
            // Check if due date is not in the past
            const selectedDate = new Date(dueDate);
            const today = new Date();
            today.setHours(0, 0, 0, 0);
            
            if (selectedDate < today) {
                e.preventDefault();
                alert('Due date cannot be in the past.');
                return;
            }
        });
    </script>
</body>
</html>