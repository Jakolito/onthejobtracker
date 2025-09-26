<?php
include('connect.php');
session_start();

// ADD CACHE CONTROL HEADERS
header("Cache-Control: no-cache, no-store, must-revalidate");
header("Pragma: no-cache");
header("Expires: 0");

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

$user_id = $_SESSION['user_id'];
include_once('notification_functions.php');
$unread_count = getUnreadNotificationCount($conn, $user_id);

// Check for submission messages
$submission_success = isset($_SESSION['submission_success']) ? $_SESSION['submission_success'] : null;
$submission_error = isset($_SESSION['submission_error']) ? $_SESSION['submission_error'] : null;
unset($_SESSION['submission_success'], $_SESSION['submission_error']);

// Fetch student data
try {
    $stmt = $conn->prepare("SELECT first_name, middle_name, last_name, email, student_id, department, program, year_level, profile_picture FROM students WHERE id = ?");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows > 0) {
        $student = $result->fetch_assoc();
        
        // Build full name
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
        
        // Create initials for avatar
        $initials = strtoupper(substr($student['first_name'], 0, 1) . substr($student['last_name'], 0, 1));
    } else {
        header("Location: login.php");
        exit();
    }
} catch (Exception $e) {
    echo "Error: " . $e->getMessage();
    exit();
}

// Fetch tasks assigned to this student
$tasks = [];
$task_submissions = [];

try {
    // Get tasks assigned to this student with submission status
    $task_stmt = $conn->prepare("
        SELECT 
            t.task_id,
            t.task_title,
            t.task_description,
            t.due_date,
            t.priority,
            t.task_category,
            t.instructions,
            t.remarks,
            t.status,
            t.created_at,
            t.updated_at,
            cs.full_name as supervisor_name, 
            cs.company_name, 
            cs.position as supervisor_position,
            ts.submission_id,
            ts.submission_description,
            ts.attachment,
            ts.status as submission_status,
            ts.feedback,
            ts.submitted_at,
            ts.reviewed_at
        FROM tasks t
        LEFT JOIN company_supervisors cs ON t.supervisor_id = cs.supervisor_id
        LEFT JOIN task_submissions ts ON t.task_id = ts.task_id AND ts.student_id = t.student_id
        WHERE t.student_id = ?
        ORDER BY t.created_at DESC, t.updated_at DESC, t.due_date ASC, t.priority DESC
    ");
    $task_stmt->bind_param("i", $user_id);
    $task_stmt->execute();
    $task_result = $task_stmt->get_result();
    
    while ($row = $task_result->fetch_assoc()) {
        $tasks[] = $row;
    }
    $task_stmt->close();
    
} catch (Exception $e) {
    echo "Error fetching tasks: " . $e->getMessage();
    error_log("Task fetch error: " . $e->getMessage());
}

// Calculate statistics
$total_tasks = count($tasks);
$completed_tasks = count(array_filter($tasks, function($task) { 
    return $task['status'] === 'Completed'; 
}));
$in_progress_tasks = count(array_filter($tasks, function($task) { 
    return $task['status'] === 'In Progress'; 
}));
$pending_tasks = count(array_filter($tasks, function($task) { 
    return $task['status'] === 'Pending'; 
}));
$rejected_tasks = count(array_filter($tasks, function($task) { 
    return $task['submission_status'] === 'Rejected'; 
}));
$overdue_tasks = count(array_filter($tasks, function($task) { 
    return $task['status'] !== 'Completed' && strtotime($task['due_date']) < time(); 
}));

$completion_percentage = $total_tasks > 0 ? round(($completed_tasks / $total_tasks) * 100, 1) : 0;
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>OnTheJob Tracker - Tasks</title>
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

        .progress-fill {
            transition: width 2s ease-in-out;
        }

        .loading i {
            animation: spin 1s linear infinite;
        }

        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }

        /* File upload drag and drop styles */
        .file-upload-area {
            transition: all 0.3s ease;
        }

        .file-upload-area:hover {
            background-color: #f8f9fa;
        }

        .file-upload-area.drag-over {
            border-color: #007bff !important;
            background-color: #f0f8ff !important;
        }

        .file-upload-area.dragover {
            border-color: #3b82f6 !important;
            background-color: #eff6ff !important;
        }

        /* Task card animations */
        .task-card {
            transition: all 0.3s ease;
        }

        .task-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.1);
        }

        .submission-info.expanded .submission-content {
            display: block;
            animation: slideDown 0.3s ease-in-out;
        }

        .submission-info .submission-content {
            display: none;
        }

        @keyframes slideDown {
            from {
                opacity: 0;
                max-height: 0;
            }
            to {
                opacity: 1;
                max-height: 200px;
            }
        }

        .submission-info.expanded .submission-toggle i {
            transform: rotate(90deg);
        }

        .submission-info .submission-toggle i {
            transition: transform 0.3s ease;
        }

        /* Modal styles */
        .modal-backdrop {
            backdrop-filter: blur(2px);
        }

        /* Start Task button styles */
        .start-task-btn {
            background: linear-gradient(135deg, #10b981, #059669);
            transition: all 0.3s ease;
        }

        .start-task-btn:hover {
            background: linear-gradient(135deg, #059669, #047857);
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(16, 185, 129, 0.4);
        }

        /* Rejected task highlight */
        .task-card.rejected {
            border-left: 4px solid #ef4444;
            background: linear-gradient(to right, #fef2f2, #ffffff);
        }

        .rejected-feedback {
            background: linear-gradient(135deg, #fef2f2, #fde8e8);
            border: 1px solid #fca5a5;
            animation: pulse-rejected 3s ease-in-out;
        }

        @keyframes pulse-rejected {
            0%, 100% { box-shadow: 0 0 0 0 rgba(239, 68, 68, 0.4); }
            50% { box-shadow: 0 0 0 8px rgba(239, 68, 68, 0); }
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
            <a href="studentTask.php" class="nav-item flex items-center px-3 py-2 text-sm font-medium text-white bg-bulsu-gold bg-opacity-20 border border-bulsu-gold border-opacity-30 rounded-md">
                <i class="fas fa-tasks mr-3 text-bulsu-gold"></i>
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

                <!-- Header Title -->
                <div class="flex-1 lg:ml-0 ml-4">
                    <h1 class="text-xl sm:text-2xl font-bold text-gray-900">My Tasks</h1>
                    <p class="text-sm sm:text-base text-gray-500 hidden sm:block">Manage your assigned tasks and submissions</p>
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
            <!-- Alert Messages -->
            <?php if ($submission_success): ?>
                <div id="successAlert" class="mb-6 p-4 bg-green-50 border border-green-200 rounded-lg">
                    <div class="flex items-start justify-between">
                        <div class="flex items-start">
                            <i class="fas fa-check-circle text-green-600 mt-1 mr-3"></i>
                            <p class="text-green-700"><?php echo htmlspecialchars($submission_success); ?></p>
                        </div>
                        <button onclick="closeAlert('successAlert')" class="text-green-400 hover:text-green-600">
                            <i class="fas fa-times"></i>
                        </button>
                    </div>
                </div>
            <?php endif; ?>

            <?php if ($submission_error): ?>
                <div id="errorAlert" class="mb-6 p-4 bg-red-50 border border-red-200 rounded-lg">
                    <div class="flex items-start justify-between">
                        <div class="flex items-start">
                            <i class="fas fa-exclamation-circle text-red-600 mt-1 mr-3"></i>
                            <p class="text-red-700"><?php echo htmlspecialchars($submission_error); ?></p>
                        </div>
                        <button onclick="closeAlert('errorAlert')" class="text-red-400 hover:text-red-600">
                            <i class="fas fa-times"></i>
                        </button>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Task Statistics -->
            <div class="grid grid-cols-2 lg:grid-cols-6 gap-4 sm:gap-6 mb-6 sm:mb-8">
                <div class="bg-white p-4 sm:p-6 rounded-lg shadow-sm border border-gray-200">
                    <div class="flex items-center">
                        <div class="p-3 bg-blue-100 rounded-lg">
                            <i class="fas fa-tasks text-blue-600 text-xl"></i>
                        </div>
                        <div class="ml-4">
                            <div class="text-2xl sm:text-3xl font-bold text-gray-900"><?php echo $total_tasks; ?></div>
                            <div class="text-sm text-gray-600">Total Tasks</div>
                        </div>
                    </div>
                </div>
                
                <div class="bg-white p-4 sm:p-6 rounded-lg shadow-sm border border-gray-200 border-l-4 border-l-green-500">
                    <div class="flex items-center">
                        <div class="p-3 bg-green-100 rounded-lg">
                            <i class="fas fa-check-circle text-green-600 text-xl"></i>
                        </div>
                        <div class="ml-4">
                            <div class="text-2xl sm:text-3xl font-bold text-green-600"><?php echo $completed_tasks; ?></div>
                            <div class="text-sm text-gray-600">Completed</div>
                        </div>
                    </div>
                </div>
                
                <div class="bg-white p-4 sm:p-6 rounded-lg shadow-sm border border-gray-200 border-l-4 border-l-yellow-500">
                    <div class="flex items-center">
                        <div class="p-3 bg-yellow-100 rounded-lg">
                            <i class="fas fa-clock text-yellow-600 text-xl"></i>
                        </div>
                        <div class="ml-4">
                            <div class="text-2xl sm:text-3xl font-bold text-yellow-600"><?php echo $in_progress_tasks; ?></div>
                            <div class="text-sm text-gray-600">In Progress</div>
                        </div>
                    </div>
                </div>
                
                <div class="bg-white p-4 sm:p-6 rounded-lg shadow-sm border border-gray-200 border-l-4 border-l-gray-500">
                    <div class="flex items-center">
                        <div class="p-3 bg-gray-100 rounded-lg">
                            <i class="fas fa-hourglass-half text-gray-600 text-xl"></i>
                        </div>
                        <div class="ml-4">
                            <div class="text-2xl sm:text-3xl font-bold text-gray-600"><?php echo $pending_tasks; ?></div>
                            <div class="text-sm text-gray-600">Pending</div>
                        </div>
                    </div>
                </div>
                
                <div class="bg-white p-4 sm:p-6 rounded-lg shadow-sm border border-gray-200 border-l-4 border-l-red-500">
                    <div class="flex items-center">
                        <div class="p-3 bg-red-100 rounded-lg">
                            <i class="fas fa-exclamation-circle text-red-600 text-xl"></i>
                        </div>
                        <div class="ml-4">
                            <div class="text-2xl sm:text-3xl font-bold text-red-600"><?php echo $overdue_tasks; ?></div>
                            <div class="text-sm text-gray-600">Overdue</div>
                        </div>
                    </div>
                </div>
                
                <div class="bg-white p-4 sm:p-6 rounded-lg shadow-sm border border-gray-200 border-l-4 border-l-orange-500">
                    <div class="flex items-center">
                        <div class="p-3 bg-orange-100 rounded-lg">
                            <i class="fas fa-times-circle text-orange-600 text-xl"></i>
                        </div>
                        <div class="ml-4">
                            <div class="text-2xl sm:text-3xl font-bold text-orange-600"><?php echo $rejected_tasks; ?></div>
                            <div class="text-sm text-gray-600">Rejected</div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Task Filters -->
            <div class="mb-6">
                <div class="flex flex-wrap gap-2 sm:gap-4">
                    <button class="filter-btn px-4 py-2 text-sm font-medium rounded-lg bg-blue-600 text-white" onclick="filterTasks('all')">All Tasks</button>
                    <button class="filter-btn px-4 py-2 text-sm font-medium rounded-lg bg-gray-200 text-gray-700 hover:bg-gray-300" onclick="filterTasks('pending')">Pending</button>
                    <button class="filter-btn px-4 py-2 text-sm font-medium rounded-lg bg-gray-200 text-gray-700 hover:bg-gray-300" onclick="filterTasks('in-progress')">In Progress</button>
                    <button class="filter-btn px-4 py-2 text-sm font-medium rounded-lg bg-gray-200 text-gray-700 hover:bg-gray-300" onclick="filterTasks('completed')">Completed</button>
                    <button class="filter-btn px-4 py-2 text-sm font-medium rounded-lg bg-gray-200 text-gray-700 hover:bg-gray-300" onclick="filterTasks('rejected')">Rejected</button>
                    <button class="filter-btn px-4 py-2 text-sm font-medium rounded-lg bg-gray-200 text-gray-700 hover:bg-gray-300" onclick="filterTasks('overdue')">Overdue</button>
                </div>
            </div>

            <!-- Tasks List -->
            <div class="space-y-4 sm:space-y-6">
                <?php if (empty($tasks)): ?>
                    <div class="text-center py-12 bg-white rounded-lg shadow-sm border border-gray-200">
                        <i class="fas fa-clipboard-list text-gray-400 text-5xl mb-4"></i>
                        <h3 class="text-lg font-medium text-gray-900 mb-2">No Tasks Assigned</h3>
                        <p class="text-gray-600">You don't have any tasks assigned yet. Check back later for updates from your supervisor.</p>
                    </div>
                <?php else: ?>
                    <?php foreach ($tasks as $task): ?>
                        <?php
                        $is_overdue = $task['status'] !== 'Completed' && strtotime($task['due_date']) < time();
                        $has_submission = !empty($task['submission_id']);
                        $is_rejected = $task['submission_status'] === 'Rejected';
                        
                        // Determine actual task status for display
                        if ($is_rejected) {
                            $status_display = 'Rejected';
                            $task_status_for_filter = 'rejected';
                        } elseif ($is_overdue) {
                            $status_display = 'Overdue';
                            $task_status_for_filter = 'overdue';
                        } else {
                            $status_display = $task['status'];
                            $task_status_for_filter = strtolower(str_replace(' ', '-', $task['status']));
                        }
                        
                        // Status classes
                        $status_classes = [
                            'Pending' => 'bg-gray-100 text-gray-800',
                            'In Progress' => 'bg-yellow-100 text-yellow-800',
                            'Completed' => 'bg-green-100 text-green-800',
                            'Rejected' => 'bg-red-100 text-red-800',
                            'Overdue' => 'bg-red-100 text-red-800'
                        ];
                        
                        // Priority classes
                        $priority_classes = [
                            'Low' => 'bg-blue-100 text-blue-800',
                            'Medium' => 'bg-yellow-100 text-yellow-800',
                            'High' => 'bg-red-100 text-red-800',
                            'Critical' => 'bg-red-200 text-red-900'
                        ];
                        ?>
                        
                        <div class="task-card <?php echo $is_rejected ? 'rejected' : ''; ?> bg-white rounded-lg shadow-sm border border-gray-200 p-4 sm:p-6" 
                             data-status="<?php echo $task_status_for_filter; ?>" 
                             data-overdue="<?php echo $is_overdue ? 'true' : 'false'; ?>"
                             data-rejected="<?php echo $is_rejected ? 'true' : 'false'; ?>">
                            
                            <!-- Rejected Task Alert Banner -->
                            <?php if ($is_rejected): ?>
                                <div class="rejected-feedback mb-4 p-3 rounded-lg">
                                    <div class="flex items-center mb-2">
                                        <i class="fas fa-exclamation-triangle text-red-600 mr-2"></i>
                                        <span class="font-semibold text-red-800">Task Submission Rejected</span>
                                    </div>
                                    <p class="text-red-700 text-sm">Your supervisor has rejected this task. Please review the feedback below and resubmit.</p>
                                </div>
                            <?php endif; ?>
                            
                            <!-- Task Header -->
                            <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between mb-4">
                                <h3 class="text-lg font-medium text-gray-900 mb-2 sm:mb-0"><?php echo htmlspecialchars($task['task_title']); ?></h3>
                                <div class="flex flex-wrap gap-2">
                                    <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium <?php echo $status_classes[$status_display]; ?>">
                                        <?php echo $status_display; ?>
                                    </span>
                                    <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium <?php echo $priority_classes[$task['priority']]; ?>">
                                        <?php echo $task['priority']; ?>
                                    </span>
                                </div>
                            </div>
                            
                            <!-- Task Meta -->
                            <div class="mb-4 space-y-2">
                                <div class="flex items-center text-sm text-gray-600">
                                    <i class="fas fa-user mr-2"></i>
                                    <span><?php echo htmlspecialchars($task['supervisor_name'] ?? 'Not Assigned'); ?></span>
                                    <?php if (!empty($task['supervisor_position'])): ?>
                                        <span class="text-gray-400 mx-2">•</span>
                                        <span><?php echo htmlspecialchars($task['supervisor_position']); ?></span>
                                    <?php endif; ?>
                                </div>
                                <div class="flex items-center text-sm text-gray-600">
                                    <i class="fas fa-tag mr-2"></i>
                                    <span><?php echo htmlspecialchars($task['task_category'] ?? 'General'); ?></span>
                                </div>
                                <div class="flex items-center text-sm <?php echo ($is_overdue || $is_rejected) ? 'text-red-600' : 'text-gray-600'; ?>">
                                    <i class="fas fa-calendar mr-2"></i>
                                    Due: <?php echo date('M j, Y', strtotime($task['due_date'])); ?>
                                    <?php if ($is_overdue): ?>
                                        <span class="ml-2 text-red-600 font-medium">(Overdue)</span>
                                    <?php endif; ?>
                                </div>
                            </div>
                            
                            <!-- Task Description Preview -->
                            <div class="mb-4">
                                <p class="text-gray-700 line-clamp-2">
                                    <?php 
                                    $description = htmlspecialchars($task['task_description']);
                                    echo strlen($description) > 150 ? substr($description, 0, 150) . '...' : $description;
                                    ?>
                                </p>
                            </div>
                            
                            <!-- Task Actions -->
                            <div class="flex flex-col sm:flex-row gap-3 mb-4">
                                <button onclick="openTaskDetailsModal(<?php echo $task['task_id']; ?>)"
                                        class="flex items-center justify-center px-4 py-2 bg-gray-600 text-white text-sm font-medium rounded-md hover:bg-gray-700 transition-colors">
                                    <i class="fas fa-eye mr-2"></i>
                                    View Details
                                </button>
                                
                                <?php if ($task['status'] === 'Pending' && !$is_rejected): ?>
                                    <!-- Start Task Button -->
                                    <button onclick="startTask(<?php echo $task['task_id']; ?>)"
                                            class="start-task-btn flex items-center justify-center px-4 py-2 text-white text-sm font-medium rounded-md transition-all duration-300">
                                        <i class="fas fa-play mr-2"></i>
                                        Start Task
                                    </button>
                                <?php elseif ($task['status'] === 'In Progress' || $is_rejected): ?>
                                    <!-- Submit/Resubmit Task Button -->
                                    <button onclick="openSubmissionModal(<?php echo $task['task_id']; ?>, '<?php echo htmlspecialchars($task['task_title']); ?>', <?php echo $is_rejected ? 'true' : 'false'; ?>)"
                                            class="flex items-center justify-center px-4 py-2 <?php echo $is_rejected ? 'bg-red-600 hover:bg-red-700' : 'bg-blue-600 hover:bg-blue-700'; ?> text-white text-sm font-medium rounded-md transition-colors">
                                        <i class="fas fa-<?php echo $is_rejected ? 'redo' : 'upload'; ?> mr-2"></i>
                                        <?php echo $is_rejected ? 'Resubmit Task' : ($has_submission ? 'Update Submission' : 'Submit Task'); ?>
                                    </button>
                                <?php endif; ?>
                            </div>
                            
                            <!-- Submission Status -->
                            <?php if ($has_submission): ?>
                                <div class="submission-info border-t border-gray-200 pt-4">
                                    <div class="flex items-center justify-between mb-2">
                                        <h4 class="text-sm font-medium text-gray-900">
                                            <?php echo $is_rejected ? 'Rejected Submission' : 'Your Submission'; ?>
                                        </h4>
                                        <button class="submission-toggle text-sm text-blue-600 hover:text-blue-800" 
                                                onclick="toggleSubmissionDetails(this)">
                                            <i class="fas fa-chevron-right mr-1"></i>
                                            View Details
                                        </button>
                                    </div>
                                    
                                    <div class="submission-content space-y-3">
                                        <div class="flex items-center justify-between">
                                            <div>
                                                <span class="text-sm font-medium text-gray-600">Status:</span>
                                                <span class="ml-2 inline-flex items-center px-2 py-1 rounded-full text-xs font-medium <?php echo $status_classes[$task['submission_status']]; ?>">
                                                    <?php echo $task['submission_status']; ?>
                                                </span>
                                            </div>
                                            <div>
                                                <span class="text-sm font-medium text-gray-600">Submitted:</span>
                                                <span class="text-sm text-gray-900 ml-2">
                                                    <?php echo date('M j, Y \a\t g:i A', strtotime($task['submitted_at'])); ?>
                                                </span>
                                            </div>
                                        </div>

                                        <?php if (!empty($task['submission_description'])): ?>
                                            <div>
                                                <span class="text-sm font-medium text-gray-600">Your Submission:</span>
                                                <div class="mt-1 p-3 bg-gray-50 rounded-lg">
                                                    <p class="text-sm text-gray-900">
                                                        <?php echo nl2br(htmlspecialchars($task['submission_description'])); ?>
                                                    </p>
                                                </div>
                                            </div>
                                        <?php endif; ?>

                                        <?php if (!empty($task['attachment'])): ?>
                                            <div>
                                                <span class="text-sm font-medium text-gray-600">Attachment:</span>
                                                <a href="<?php echo htmlspecialchars($task['attachment']); ?>" 
                                                   target="_blank"
                                                   class="inline-flex items-center ml-2 text-sm text-blue-600 hover:text-blue-800">
                                                    <i class="fas fa-file mr-1"></i>
                                                    View File
                                                </a>
                                            </div>
                                        <?php endif; ?>
                                            
                                        <?php if (!empty($task['feedback'])): ?>
                                            <div class="border-t border-gray-200 pt-3">
                                                <span class="text-sm font-medium <?php echo $is_rejected ? 'text-red-600' : 'text-gray-600'; ?>">
                                                    <i class="fas fa-comment mr-1"></i>
                                                    Supervisor Feedback:
                                                </span>
                                                <div class="mt-1 p-3 <?php echo $is_rejected ? 'bg-red-50 border border-red-200' : 'bg-blue-50 border border-blue-200'; ?> rounded-lg">
                                                    <p class="text-sm <?php echo $is_rejected ? 'text-red-800' : 'text-blue-800'; ?>">
                                                        <?php echo nl2br(htmlspecialchars($task['feedback'])); ?>
                                                    </p>
                                                </div>
                                                <?php if (!empty($task['reviewed_at'])): ?>
                                                    <div class="mt-1 text-xs text-gray-500">
                                                        Reviewed: <?php echo date('M j, Y \a\t g:i A', strtotime($task['reviewed_at'])); ?>
                                                    </div>
                                                <?php endif; ?>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Task Details Modal -->
    <div id="taskDetailsModal" class="fixed inset-0 bg-black bg-opacity-50 z-50 hidden modal-backdrop">
        <div class="flex items-center justify-center min-h-screen px-4">
            <div class="bg-white rounded-lg max-w-4xl w-full max-h-screen overflow-y-auto">
                <!-- Modal Header -->
                <div class="flex items-center justify-between p-6 border-b border-gray-200">
                    <h3 class="text-lg font-medium text-gray-900" id="taskDetailsTitle">Task Details</h3>
                    <button type="button" onclick="closeTaskDetailsModal()" class="text-gray-400 hover:text-gray-600">
                        <i class="fas fa-times text-xl"></i>
                    </button>
                </div>
                
                <!-- Modal Body -->
                <div class="p-6" id="taskDetailsContent">
                    <!-- Content will be loaded here -->
                </div>
            </div>
        </div>
    </div>

    <!-- Task Submission Modal -->
    <div id="submissionModal" class="fixed inset-0 bg-black bg-opacity-50 z-50 hidden">
        <div class="flex items-center justify-center min-h-screen px-4">
            <div class="bg-white rounded-lg max-w-2xl w-full max-h-screen overflow-y-auto">
                <form id="submissionForm" method="POST" action="submit_task.php" enctype="multipart/form-data">
                    <!-- Modal Header -->
                    <div class="flex items-center justify-between p-6 border-b border-gray-200">
                        <h3 class="text-lg font-medium text-gray-900" id="modalTitle">Submit Task</h3>
                        <button type="button" onclick="closeSubmissionModal()" class="text-gray-400 hover:text-gray-600">
                            <i class="fas fa-times text-xl"></i>
                        </button>
                    </div>
                    
                    <!-- Modal Body -->
                    <div class="p-6">
                        <input type="hidden" name="task_id" id="taskId">
                        <input type="hidden" name="is_resubmission" id="isResubmission" value="0">
                        
                        <!-- Resubmission Notice -->
                        <div id="resubmissionNotice" class="hidden mb-6 p-4 bg-yellow-50 border border-yellow-200 rounded-lg">
                            <div class="flex items-start">
                                <i class="fas fa-info-circle text-yellow-600 mr-2 mt-0.5"></i>
                                <div class="text-yellow-800">
                                    <p class="font-medium">Resubmitting Task</p>
                                    <p class="text-sm mt-1">Please address the supervisor's feedback before resubmitting this task.</p>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Submission Text -->
                        <div class="mb-6">
                            <label for="submissionText" class="block text-sm font-medium text-gray-700 mb-2">
                                Description <span class="text-red-500">*</span>
                            </label>
                            <textarea name="submission_text" id="submissionText" rows="4" required
                                    class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent"
                                    placeholder="Describe your work, findings, or any relevant information about the completed task..."></textarea>
                        </div>
                        
                        <!-- File Upload -->
                        <div class="mb-6">
                            <label class="block text-sm font-medium text-gray-700 mb-2">
                                Attach File (Optional)
                            </label>
                            <div class="file-upload-area border-2 border-dashed border-gray-300 rounded-lg p-6 text-center">
                                <input type="file" name="submission_file" id="submissionFile" class="hidden" accept=".pdf,.doc,.docx,.txt,.jpg,.jpeg,.png">
                                <div id="uploadArea" class="cursor-pointer" onclick="document.getElementById('submissionFile').click()">
                                    <i class="fas fa-cloud-upload-alt text-gray-400 text-3xl mb-2"></i>
                                    <p class="text-gray-600">Click to upload or drag and drop</p>
                                    <p class="text-sm text-gray-500 mt-1">PDF, DOC, DOCX, TXT, JPG, PNG (Max 10MB)</p>
                                </div>
                                <div id="fileName" class="hidden mt-3 p-3 bg-blue-50 rounded-md">
                                    <i class="fas fa-file text-blue-600 mr-2"></i>
                                    <span class="text-blue-800" id="fileNameText"></span>
                                    <button type="button" onclick="removeFile()" class="ml-2 text-red-600 hover:text-red-800">
                                        <i class="fas fa-times"></i>
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Modal Footer -->
                    <div class="flex items-center justify-end space-x-3 p-6 border-t border-gray-200">
                        <button type="button" onclick="closeSubmissionModal()" 
                                class="px-4 py-2 text-sm font-medium text-gray-700 bg-gray-200 rounded-md hover:bg-gray-300">
                            Cancel
                        </button>
                        <button type="submit" id="submitBtn"
                                class="px-4 py-2 text-sm font-medium text-white bg-blue-600 rounded-md hover:bg-blue-700">
                            <span class="submit-text">Submit Task</span>
                            <span class="loading hidden">
                                <i class="fas fa-spinner mr-2"></i>
                                Submitting...
                            </span>
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script>
        // Store tasks data for modal usage
        const tasksData = <?php echo json_encode($tasks); ?>;

        // Mobile menu functionality
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

        // Profile dropdown functionality
        const profileBtn = document.getElementById('profileBtn');
        const profileDropdown = document.getElementById('profileDropdown');

        profileBtn.addEventListener('click', (e) => {
            e.stopPropagation();
            profileDropdown.classList.toggle('hidden');
        });

        document.addEventListener('click', () => {
            profileDropdown.classList.add('hidden');
        });

        // Start Task functionality
        function startTask(taskId) {
            if (confirm('Are you sure you want to start this task? This will change the status to "In Progress" and notify your supervisor.')) {
                // Show loading state
                const startBtn = document.querySelector(`button[onclick="startTask(${taskId})"]`);
                const originalContent = startBtn.innerHTML;
                startBtn.innerHTML = '<i class="fas fa-spinner mr-2 animate-spin"></i>Starting...';
                startBtn.disabled = true;
                
                // Create and submit form
                const form = document.createElement('form');
                form.method = 'POST';
                form.action = 'start_task.php';
                
                const taskIdInput = document.createElement('input');
                taskIdInput.type = 'hidden';
                taskIdInput.name = 'task_id';
                taskIdInput.value = taskId;
                
                form.appendChild(taskIdInput);
                document.body.appendChild(form);
                form.submit();
            }
        }

        // Task filtering
        function filterTasks(status) {
            const tasks = document.querySelectorAll('.task-card');
            const filterBtns = document.querySelectorAll('.filter-btn');
            
            // Update active button
            filterBtns.forEach(btn => {
                btn.classList.remove('bg-blue-600', 'text-white');
                btn.classList.add('bg-gray-200', 'text-gray-700');
            });
            event.target.classList.remove('bg-gray-200', 'text-gray-700');
            event.target.classList.add('bg-blue-600', 'text-white');
            
            // Filter tasks
            tasks.forEach(task => {
                if (status === 'all') {
                    task.style.display = 'block';
                } else if (status === 'rejected') {
                    task.style.display = task.dataset.rejected === 'true' ? 'block' : 'none';
                } else if (status === 'overdue') {
                    task.style.display = task.dataset.overdue === 'true' ? 'block' : 'none';
                } else {
                    task.style.display = task.dataset.status === status ? 'block' : 'none';
                }
            });
        }

        // Task Details Modal
        function openTaskDetailsModal(taskId) {
            const task = tasksData.find(t => t.task_id == taskId);
            if (!task) return;

            const modal = document.getElementById('taskDetailsModal');
            const title = document.getElementById('taskDetailsTitle');
            const content = document.getElementById('taskDetailsContent');

            title.textContent = task.task_title;
            
            const isOverdue = task.status !== 'Completed' && new Date(task.due_date) < new Date();
            const isRejected = task.submission_status === 'Rejected';
            let statusDisplay = task.status;
            
            if (isRejected) {
                statusDisplay = 'Rejected';
            } else if (isOverdue) {
                statusDisplay = 'Overdue';
            }
            
            const statusClasses = {
                'Pending': 'bg-gray-100 text-gray-800',
                'In Progress': 'bg-yellow-100 text-yellow-800',
                'Completed': 'bg-green-100 text-green-800',
                'Rejected': 'bg-red-100 text-red-800',
                'Overdue': 'bg-red-100 text-red-800'
            };
            
            const priorityClasses = {
                'Low': 'bg-blue-100 text-blue-800',
                'Medium': 'bg-yellow-100 text-yellow-800',
                'High': 'bg-red-100 text-red-800',
                'Critical': 'bg-red-200 text-red-900'
            };

            // Create action buttons based on task status
            let actionButtons = '';
            if (task.status === 'Pending' && !isRejected) {
                actionButtons = `
                    <button onclick="closeTaskDetailsModal(); startTask(${task.task_id});"
                            class="start-task-btn flex items-center px-4 py-2 text-white text-sm font-medium rounded-md transition-all duration-300">
                        <i class="fas fa-play mr-2"></i>
                        Start Task
                    </button>
                `;
            } else if (task.status === 'In Progress' || isRejected) {
                const buttonClass = isRejected ? 'bg-red-600 hover:bg-red-700' : 'bg-blue-600 hover:bg-blue-700';
                const buttonText = isRejected ? 'Resubmit Task' : 'Submit Task';
                const buttonIcon = isRejected ? 'redo' : 'upload';
                
                actionButtons = `
                    <button onclick="closeTaskDetailsModal(); openSubmissionModal(${task.task_id}, '${task.task_title.replace(/'/g, "\\'")}', ${isRejected});"
                            class="flex items-center px-4 py-2 ${buttonClass} text-white text-sm font-medium rounded-md transition-colors">
                        <i class="fas fa-${buttonIcon} mr-2"></i>
                        ${buttonText}
                    </button>
                `;
            }

            // Add rejected task banner if applicable
            let rejectedBanner = '';
            if (isRejected) {
                rejectedBanner = `
                    <div class="mb-6 p-4 bg-red-50 border border-red-200 rounded-lg">
                        <div class="flex items-center mb-2">
                            <i class="fas fa-exclamation-triangle text-red-600 mr-2"></i>
                            <span class="font-semibold text-red-800">Task Submission Rejected</span>
                        </div>
                        <p class="text-red-700 text-sm">Your supervisor has rejected this task. Please review the feedback and resubmit with the required changes.</p>
                    </div>
                `;
            }

            content.innerHTML = `
                <div class="space-y-6">
                    ${rejectedBanner}
                    
                    <!-- Task Status and Priority -->
                    <div class="flex flex-wrap gap-3">
                        <span class="inline-flex items-center px-3 py-1 rounded-full text-sm font-medium ${statusClasses[statusDisplay]}">
                            ${statusDisplay}
                        </span>
                        <span class="inline-flex items-center px-3 py-1 rounded-full text-sm font-medium ${priorityClasses[task.priority]}">
                            ${task.priority} Priority
                        </span>
                        <span class="inline-flex items-center px-3 py-1 rounded-full text-sm font-medium bg-gray-100 text-gray-800">
                            ${task.task_category || 'General'}
                        </span>
                    </div>

                    <!-- Task Meta Information -->
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4 p-4 bg-gray-50 rounded-lg">
                        <div>
                            <span class="text-sm font-medium text-gray-600">Assigned by:</span>
                            <p class="text-sm text-gray-900">${task.supervisor_name || 'Not Assigned'}</p>
                        </div>
                        <div>
                            <span class="text-sm font-medium text-gray-600">Position:</span>
                            <p class="text-sm text-gray-900">${task.supervisor_position || 'N/A'}</p>
                        </div>
                        <div>
                            <span class="text-sm font-medium text-gray-600">Due Date:</span>
                            <p class="text-sm ${(isOverdue || isRejected) ? 'text-red-600 font-medium' : 'text-gray-900'}">
                                ${new Date(task.due_date).toLocaleDateString('en-US', { 
                                    year: 'numeric', 
                                    month: 'long', 
                                    day: 'numeric' 
                                })}
                                ${isOverdue ? ' (Overdue)' : ''}
                            </p>
                        </div>
                        <div>
                            <span class="text-sm font-medium text-gray-600">Created:</span>
                            <p class="text-sm text-gray-900">
                                ${new Date(task.created_at).toLocaleDateString('en-US', { 
                                    year: 'numeric', 
                                    month: 'long', 
                                    day: 'numeric' 
                                })}
                            </p>
                        </div>
                    </div>

                    <!-- Task Description -->
                    <div>
                        <h4 class="text-lg font-medium text-gray-900 mb-3">Description</h4>
                        <div class="prose prose-sm max-w-none">
                            <p class="text-gray-700 whitespace-pre-wrap">${task.task_description}</p>
                        </div>
                    </div>

                    <!-- Task Instructions -->
                    ${task.instructions ? `
                        <div>
                            <h4 class="text-lg font-medium text-gray-900 mb-3">Instructions</h4>
                            <div class="p-4 bg-blue-50 rounded-lg border border-blue-200">
                                <p class="text-blue-800 whitespace-pre-wrap">${task.instructions}</p>
                            </div>
                        </div>
                    ` : ''}

                    <!-- Task Remarks -->
                    ${task.remarks ? `
                        <div>
                            <h4 class="text-lg font-medium text-gray-900 mb-3">Remarks</h4>
                            <div class="p-4 bg-yellow-50 rounded-lg border border-yellow-200">
                                <p class="text-yellow-800 whitespace-pre-wrap">${task.remarks}</p>
                            </div>
                        </div>
                    ` : ''}

                    <!-- Submission Feedback (if rejected) -->
                    ${(isRejected && task.feedback) ? `
                        <div>
                            <h4 class="text-lg font-medium text-gray-900 mb-3">Supervisor Feedback</h4>
                            <div class="p-4 bg-red-50 rounded-lg border border-red-200">
                                <p class="text-red-800 whitespace-pre-wrap">${task.feedback}</p>
                            </div>
                        </div>
                    ` : ''}

                    <!-- Action Buttons -->
                    <div class="flex gap-3 pt-4 border-t border-gray-200">
                        ${actionButtons}
                        <button onclick="closeTaskDetailsModal()"
                                class="flex items-center px-4 py-2 bg-gray-200 text-gray-700 text-sm font-medium rounded-md hover:bg-gray-300 transition-colors">
                            <i class="fas fa-times mr-2"></i>
                            Close
                        </button>
                    </div>
                </div>
            `;

            modal.classList.remove('hidden');
            document.body.style.overflow = 'hidden';
        }

        function closeTaskDetailsModal() {
            document.getElementById('taskDetailsModal').classList.add('hidden');
            document.body.style.overflow = 'auto';
        }

        // Submission modal functionality
        function openSubmissionModal(taskId, taskTitle, isRejected = false) {
            const task = tasksData.find(t => t.task_id == taskId);
            
            document.getElementById('taskId').value = taskId;
            document.getElementById('isResubmission').value = isRejected ? '1' : '0';
            
            // Update modal title and button based on rejection status
            const modalTitle = document.getElementById('modalTitle');
            const submitBtn = document.getElementById('submitBtn');
            const submitText = submitBtn.querySelector('.submit-text');
            const resubmissionNotice = document.getElementById('resubmissionNotice');
            
            if (isRejected) {
                modalTitle.textContent = `Resubmit: ${taskTitle}`;
                submitText.textContent = 'Resubmit Task';
                submitBtn.classList.remove('bg-blue-600', 'hover:bg-blue-700');
                submitBtn.classList.add('bg-red-600', 'hover:bg-red-700');
                resubmissionNotice.classList.remove('hidden');
                
                // Pre-fill with existing submission if available
                if (task && task.submission_description) {
                    document.getElementById('submissionText').value = task.submission_description;
                }
            } else {
                modalTitle.textContent = `Submit: ${taskTitle}`;
                submitText.textContent = 'Submit Task';
                submitBtn.classList.remove('bg-red-600', 'hover:bg-red-700');
                submitBtn.classList.add('bg-blue-600', 'hover:bg-blue-700');
                resubmissionNotice.classList.add('hidden');
            }
            
            document.getElementById('submissionModal').classList.remove('hidden');
            document.body.style.overflow = 'hidden';
        }

        function closeSubmissionModal() {
            document.getElementById('submissionModal').classList.add('hidden');
            document.body.style.overflow = 'auto';
            document.getElementById('submissionForm').reset();
            resetFileUpload();
            
            // Reset button styling
            const submitBtn = document.getElementById('submitBtn');
            submitBtn.classList.remove('bg-red-600', 'hover:bg-red-700');
            submitBtn.classList.add('bg-blue-600', 'hover:bg-blue-700');
            document.getElementById('resubmissionNotice').classList.add('hidden');
        }

        // File upload functionality
        const fileInput = document.getElementById('submissionFile');
        const uploadArea = document.getElementById('uploadArea');
        const fileName = document.getElementById('fileName');
        const fileNameText = document.getElementById('fileNameText');

        fileInput.addEventListener('change', handleFileSelect);
        uploadArea.addEventListener('dragover', handleDragOver);
        uploadArea.addEventListener('drop', handleDrop);
        uploadArea.addEventListener('dragleave', handleDragLeave);

        function handleFileSelect(e) {
            const file = e.target.files[0];
            if (file) {
                displayFileName(file.name);
            }
        }

        function handleDragOver(e) {
            e.preventDefault();
            uploadArea.parentElement.classList.add('dragover');
        }

        function handleDrop(e) {
            e.preventDefault();
            uploadArea.parentElement.classList.remove('dragover');
            
            const files = e.dataTransfer.files;
            if (files.length > 0) {
                fileInput.files = files;
                displayFileName(files[0].name);
            }
        }

        function handleDragLeave(e) {
            e.preventDefault();
            uploadArea.parentElement.classList.remove('dragover');
        }

        function displayFileName(name) {
            fileNameText.textContent = name;
            uploadArea.classList.add('hidden');
            fileName.classList.remove('hidden');
        }

        function removeFile() {
            fileInput.value = '';
            resetFileUpload();
        }

        function resetFileUpload() {
            uploadArea.classList.remove('hidden');
            fileName.classList.add('hidden');
        }

        // Form submission
        document.getElementById('submissionForm').addEventListener('submit', function(e) {
            const submitBtn = document.getElementById('submitBtn');
            const submitText = submitBtn.querySelector('.submit-text');
            const loading = submitBtn.querySelector('.loading');
            
            submitBtn.disabled = true;
            submitText.classList.add('hidden');
            loading.classList.remove('hidden');
        });

        // Toggle submission details
        function toggleSubmissionDetails(button) {
            const submissionInfo = button.closest('.submission-info');
            const content = submissionInfo.querySelector('.submission-content');
            const icon = button.querySelector('i');
            
            if (submissionInfo.classList.contains('expanded')) {
                submissionInfo.classList.remove('expanded');
                button.innerHTML = '<i class="fas fa-chevron-right mr-1"></i>View Details';
            } else {
                submissionInfo.classList.add('expanded');
                button.innerHTML = '<i class="fas fa-chevron-down mr-1"></i>Hide Details';
            }
        }

        // Close alert messages
        function closeAlert(alertId) {
            document.getElementById(alertId).style.display = 'none';
        }

        // Auto-hide alerts after 5 seconds
        setTimeout(() => {
            const alerts = document.querySelectorAll('[id$="Alert"]');
            alerts.forEach(alert => {
                if (alert.style.display !== 'none') {
                    alert.style.opacity = '0';
                    setTimeout(() => {
                        alert.style.display = 'none';
                    }, 300);
                }
            });
        }, 5000);

        // Close modals when clicking outside
        document.getElementById('taskDetailsModal').addEventListener('click', function(e) {
            if (e.target === this) {
                closeTaskDetailsModal();
            }
        });

        document.getElementById('submissionModal').addEventListener('click', function(e) {
            if (e.target === this) {
                closeSubmissionModal();
            }
        });

        // Auto-highlight rejected tasks on page load
        document.addEventListener('DOMContentLoaded', function() {
            const rejectedTasks = document.querySelectorAll('.task-card[data-rejected="true"]');
            rejectedTasks.forEach(task => {
                // Add a subtle animation to draw attention to rejected tasks
                setTimeout(() => {
                    task.style.transform = 'scale(1.02)';
                    setTimeout(() => {
                        task.style.transform = 'scale(1)';
                    }, 200);
                }, 100);
            });
        });
    </script>
</body>
</html>