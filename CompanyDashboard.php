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

// Get dashboard statistics for this specific supervisor
try {
    // Number of OJT Students assigned to this supervisor
$students_query = "SELECT COUNT(*) as total FROM student_deployments WHERE supervisor_id = ?";
    $stmt = $conn->prepare($students_query);
    $stmt->bind_param("i", $supervisor_id);
    $stmt->execute();
    $total_students = $stmt->get_result()->fetch_assoc()['total'];

    // Total Hours Completed vs Required Hours
    $hours_query = "SELECT 
        SUM(required_hours) as total_required,
        SUM(completed_hours) as total_completed
        FROM student_deployments 
        WHERE supervisor_id = ? AND ojt_status = 'Active'";
    $stmt = $conn->prepare($hours_query);
    $stmt->bind_param("i", $supervisor_id);
    $stmt->execute();
    $hours_result = $stmt->get_result()->fetch_assoc();
    $total_required_hours = $hours_result['total_required'] ?? 0;
    $total_completed_hours = $hours_result['total_completed'] ?? 0;
    $hours_percentage = $total_required_hours > 0 ? round(($total_completed_hours / $total_required_hours) * 100, 1) : 0;

    // Pending Evaluations (students without recent evaluations)
    $pending_evaluations_query = "SELECT COUNT(DISTINCT sd.student_id) as total
        FROM student_deployments sd
        LEFT JOIN student_evaluations se ON sd.student_id = se.student_id AND se.supervisor_id = sd.supervisor_id
        WHERE sd.supervisor_id = ? AND sd.ojt_status = 'Active'
        AND (se.evaluation_id IS NULL OR se.created_at < DATE_SUB(NOW(), INTERVAL 1 MONTH))";
    $stmt = $conn->prepare($pending_evaluations_query);
    $stmt->bind_param("i", $supervisor_id);
    $stmt->execute();
    $pending_evaluations = $stmt->get_result()->fetch_assoc()['total'];

    // Metrics data for chart
    $metrics_students = $total_students;
    $metrics_pending_evaluations = $pending_evaluations;

    // Get student overview (Top 10 students)
    $students_overview_query = "SELECT 
        s.id, s.first_name, s.last_name, s.program, s.profile_picture,
        sd.deployment_id, sd.required_hours, sd.completed_hours, sd.ojt_status,
        sd.start_date, sd.end_date, sd.position
        FROM student_deployments sd
        JOIN students s ON sd.student_id = s.id
        WHERE sd.supervisor_id = ?
        ORDER BY sd.created_at DESC
        LIMIT 10";
    $stmt = $conn->prepare($students_overview_query);
    $stmt->bind_param("i", $supervisor_id);
    $stmt->execute();
    $students_overview = $stmt->get_result();

    // Get recent activity/notifications - FIXED QUERY
    $recent_activity_query = "SELECT 
        'task_submission' as activity_type,
        CONCAT(s.first_name, ' ', s.last_name) as student_name,
        t.task_title as title,
        ts.submitted_at as activity_date,
        'submitted a task' as activity_description
        FROM task_submissions ts
        JOIN tasks t ON ts.task_id = t.task_id
        JOIN students s ON ts.student_id = s.id
        WHERE t.supervisor_id = ?
        
        UNION ALL
        
        SELECT 
        'evaluation' as activity_type,
        CONCAT(s.first_name, ' ', s.last_name) as student_name,
        'Student Evaluation' as title,
        se.created_at as activity_date,
        CONCAT('received evaluation with rating ', se.equivalent_rating, '/5.0 (', se.verbal_interpretation, ')') as activity_description
        FROM student_evaluations se
        JOIN students s ON se.student_id = s.id
        WHERE se.supervisor_id = ?
        
        UNION ALL
        
        SELECT 
        'attendance' as activity_type,
        CONCAT(s.first_name, ' ', s.last_name) as student_name,
        'Daily Attendance' as title,
        sa.created_at as activity_date,
        CASE 
            WHEN sa.status = 'Absent' THEN 'was absent'
            WHEN sa.status = 'Late' THEN 'arrived late'
            ELSE 'marked attendance'
        END as activity_description
        FROM student_attendance sa
        JOIN student_deployments sd ON sa.student_id = sd.student_id
        JOIN students s ON sa.student_id = s.id
        WHERE sd.supervisor_id = ?
        
        ORDER BY activity_date DESC
        LIMIT 10";
    $stmt = $conn->prepare($recent_activity_query);
    $stmt->bind_param("iii", $supervisor_id, $supervisor_id, $supervisor_id);
    $stmt->execute();
    $recent_activities = $stmt->get_result();

    // Get pending tasks count
    $pending_tasks_query = "SELECT COUNT(*) as total FROM tasks WHERE supervisor_id = ? AND status IN ('Pending', 'In Progress')";
    $stmt = $conn->prepare($pending_tasks_query);
    $stmt->bind_param("i", $supervisor_id);
    $stmt->execute();
    $pending_tasks = $stmt->get_result()->fetch_assoc()['total'];

} catch (Exception $e) {
    $error_message = "Error fetching dashboard data: " . $e->getMessage();
    // Set default values
    $total_students = 0;
    $total_required_hours = 0;
    $total_completed_hours = 0;
    $hours_percentage = 0;
    $pending_evaluations = 0;
    $pending_tasks = 0;
    $metrics_students = 0;
    $metrics_supervisors = 1;
}

// Sanitize values for JavaScript (ensure they're numeric and safe)
$metrics_students_safe = intval($metrics_students);
$metrics_pending_evaluations_safe = intval($pending_evaluations);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>OnTheJob Tracker - Company Supervisor Dashboard</title>
    <link rel="icon" type="image/png" href="reqsample/bulsu12.png">
    <link rel="shortcut icon" type="image/png" href="reqsample/bulsu12.png">
    <meta http-equiv="Cache-Control" content="no-cache, no-store, must-revalidate">
    <meta http-equiv="Pragma" content="no-cache">
    <meta http-equiv="Expires" content="0">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/3.9.1/chart.min.js"></script>
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
        .progress-fill {
            transition: width 2s ease-in-out;
        }
        .chart-container {
            position: relative;
            height: 300px;
        }
        @keyframes pulse {
            0%, 100% { opacity: 1; }
            50% { opacity: 0.5; }
        }
        .pulse { animation: pulse 2s infinite; }
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
            <a href="CompanyDashboard.php" class="nav-item flex items-center px-3 py-2 text-sm font-medium text-white bg-bulsu-gold bg-opacity-20 border border-bulsu-gold border-opacity-30 rounded-md">
                <i class="fas fa-th-large mr-3 text-bulsu-gold"></i>
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
                    <h1 class="text-xl sm:text-2xl font-bold text-gray-900">Company Supervisor Dashboard</h1>
                    <p class="text-sm sm:text-base text-gray-500 hidden sm:block">Monitor and manage your OJT students</p>
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
            <?php if (isset($error_message)): ?>
                <div class="mb-6 p-4 bg-red-50 border border-red-200 rounded-lg">
                    <div class="flex items-start">
                        <i class="fas fa-exclamation-triangle text-red-600 mt-1 mr-3"></i>
                        <p class="text-red-700"><?php echo htmlspecialchars($error_message); ?></p>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Welcome Section -->
            <div class="mb-6 sm:mb-8">
                <h1 class="text-2xl sm:text-3xl font-bold text-gray-900 mb-2">
                    Welcome, <?php echo htmlspecialchars($supervisor_name); ?>!
                </h1>
                <p class="text-gray-600"><?php echo htmlspecialchars($company_name); ?></p>
                <p class="text-gray-500 text-sm">Here's your OJT supervision overview for today.</p>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-4 sm:gap-6 mb-6 sm:mb-8">
    <!-- Total Students -->
    <div class="bg-gradient-to-r from-blue-500 to-blue-600 p-6 rounded-lg text-white">
        <div class="flex items-center justify-between">
            <div>
                <div class="text-3xl font-bold mb-1"><?php echo $total_students; ?></div>
                <div class="text-blue-100">OJT Students</div>
            </div>
            <div class="text-blue-200">
                <i class="fas fa-user-graduate text-2xl"></i>
            </div>
        </div>
    </div>

    <!-- Pending Evaluations -->
    <div class="bg-gradient-to-r from-yellow-500 to-yellow-600 p-6 rounded-lg text-white">
        <div class="flex items-center justify-between">
            <div>
                <div class="text-3xl font-bold mb-1"><?php echo $pending_evaluations; ?></div>
                <div class="text-yellow-100">Pending Evaluations</div>
            </div>
            <div class="text-yellow-200">
                <i class="fas fa-clipboard-list text-2xl"></i>
            </div>
        </div>
    </div>
</div>

            <!-- OJT Program Metrics Overview Chart -->
            <div class="bg-white rounded-lg shadow-sm border border-gray-200 mb-6 sm:mb-8">
                <div class="p-4 sm:p-6 border-b border-gray-200">
                    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between">
                        <div class="mb-4 sm:mb-0">
                            <h3 class="text-lg font-medium text-gray-900 mb-1">OJT Program Metrics Overview</h3>
                            <p class="text-sm text-gray-500">Track student and supervisor engagement over time</p>
                        </div>
                        <div class="flex space-x-2">
                            <button onclick="switchTimeFrame('current')" id="currentMonthBtn" class="px-4 py-2 bg-blue-600 text-white rounded-md hover:bg-blue-700 transition-colors text-sm">
                                Current Month
                            </button>
                            <button onclick="switchTimeFrame('previous')" id="previousMonthBtn" class="px-4 py-2 bg-gray-200 text-gray-700 rounded-md hover:bg-gray-300 transition-colors text-sm">
                                Previous Month
                            </button>
                            <button onclick="switchTimeFrame('yearly')" id="yearlyBtn" class="px-4 py-2 bg-gray-200 text-gray-700 rounded-md hover:bg-gray-300 transition-colors text-sm">
                                Yearly
                            </button>
                        </div>
                    </div>
                </div>
                <div class="p-4 sm:p-6">
                    <div class="chart-container">
                        <canvas id="metricsChart"></canvas>
                    </div>
                </div>
            </div>

            <!-- Student Overview Table -->
            <div class="bg-white rounded-lg shadow-sm border border-gray-200 mb-6 sm:mb-8">
                <div class="p-4 sm:p-6 border-b border-gray-200">
                    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between">
                        <div class="mb-4 sm:mb-0">
                            <h3 class="text-lg font-medium text-gray-900 mb-1">Student Overview</h3>
                            <p class="text-sm text-gray-500">Monitor your OJT students' progress and status</p>
                        </div>
                        <div class="flex space-x-2">
                            <a href="StudentEvaluate.php" class="px-4 py-2 bg-blue-600 text-white rounded-md hover:bg-blue-700 transition-colors text-sm">
                                <i class="fas fa-star mr-2"></i>
                                Evaluate Students
                            </a>
                        </div>
                    </div>
                </div>
                <div class="overflow-x-auto">
                    <table class="w-full">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Student</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Program</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Hours Progress</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Status</th>
                            </tr>
                        </thead>
                        <tbody class="bg-white divide-y divide-gray-200">
                            <?php if (isset($students_overview) && mysqli_num_rows($students_overview) > 0): ?>
                                <?php while ($student = mysqli_fetch_assoc($students_overview)): ?>
                                    <?php
                                    $student_initials = strtoupper(substr($student['first_name'], 0, 1) . substr($student['last_name'], 0, 1));
                                    $progress_percentage = $student['required_hours'] > 0 ? round(($student['completed_hours'] / $student['required_hours']) * 100, 1) : 0;
                                    $status_color = $student['ojt_status'] == 'Active' ? 'bg-green-100 text-green-800' : 'bg-gray-100 text-gray-800';
                                    ?>
                                    <tr class="hover:bg-gray-50">
                                        <td class="px-6 py-4 whitespace-nowrap">
                                            <div class="flex items-center">
                                                <div class="flex-shrink-0 h-10 w-10">
                                                    <?php if (!empty($student['profile_picture']) && file_exists($student['profile_picture'])): ?>
                                                        <img class="h-10 w-10 rounded-full object-cover" src="<?php echo htmlspecialchars($student['profile_picture']); ?>" alt="">
                                                    <?php else: ?>
                                                        <div class="h-10 w-10 rounded-full bg-gray-300 flex items-center justify-center text-sm font-medium text-gray-700">
                                                            <?php echo $student_initials; ?>
                                                        </div>
                                                    <?php endif; ?>
                                                </div>
                                                <div class="ml-4">
                                                    <div class="text-sm font-medium text-gray-900">
                                                        <?php echo htmlspecialchars($student['first_name'] . ' ' . $student['last_name']); ?>
                                                    </div>
                                                    <div class="text-sm text-gray-500">
                                                        <?php echo htmlspecialchars($student['position']); ?>
                                                    </div>
                                                </div>
                                            </div>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap">
                                            <div class="text-sm text-gray-900"><?php echo htmlspecialchars($student['program']); ?></div>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap">
                                            <div class="text-sm text-gray-900"><?php echo $progress_percentage; ?>%</div>
                                            <div class="w-full bg-gray-200 rounded-full h-2 mt-1">
                                                <div class="bg-blue-600 h-2 rounded-full progress-fill" style="width: <?php echo $progress_percentage; ?>%"></div>
                                            </div>
                                            <div class="text-xs text-gray-500 mt-1">
                                                <?php echo number_format($student['completed_hours']); ?> / <?php echo number_format($student['required_hours']); ?> hrs
                                            </div>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap">
                                            <span class="inline-flex px-2 py-1 text-xs font-semibold rounded-full <?php echo $status_color; ?>">
                                                <?php echo htmlspecialchars($student['ojt_status']); ?>
                                            </span>
                                        </td>
                                    </tr>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="5" class="px-6 py-12 text-center">
                                        <div class="text-center">
                                            <i class="fas fa-user-graduate text-gray-400 text-4xl mb-4"></i>
                                            <h3 class="text-lg font-medium text-gray-900 mb-2">No Students Assigned</h3>
                                            <p class="text-gray-600">You don't have any OJT students assigned yet.</p>
                                        </div>
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Recent Activity Section -->
            <div class="bg-white rounded-lg shadow-sm border border-gray-200 mb-6">
                <div class="p-4 sm:p-6 border-b border-gray-200">
                    <div class="flex items-center justify-between">
                        <div class="flex items-center">
                            <i class="fas fa-bell text-blue-600 mr-3"></i>
                            <h3 class="text-lg font-medium text-gray-900">Recent Activity & Notifications</h3>
                        </div>
                        <button onclick="refreshActivity()" class="flex items-center px-3 py-2 text-sm font-medium text-gray-600 bg-gray-100 hover:bg-gray-200 rounded-md transition-colors">
                            <i class="fas fa-sync-alt mr-2"></i>
                            <span class="hidden sm:inline">Refresh</span>
                        </button>
                    </div>
                </div>

                <div class="p-4 sm:p-6">
                    <div class="space-y-4">
                        <?php if (isset($recent_activities) && mysqli_num_rows($recent_activities) > 0): ?>
                            <?php while ($activity = mysqli_fetch_assoc($recent_activities)): ?>
                                <?php
                                $activity_icon = '';
                                $activity_color = '';
                                switch($activity['activity_type']) {
                                    case 'task_submission':
                                        $activity_icon = 'fas fa-tasks';
                                        $activity_color = 'text-blue-600';
                                        break;
                                    case 'evaluation':
                                        $activity_icon = 'fas fa-star';
                                        $activity_color = 'text-green-600';
                                        break;
                                    case 'attendance':
                                        $activity_icon = 'fas fa-clock';
                                        $activity_color = 'text-purple-600';
                                        break;
                                    default:
                                        $activity_icon = 'fas fa-bell';
                                        $activity_color = 'text-gray-600';
                                }
                                ?>
                                <div class="flex items-start space-x-4 p-4 bg-gray-50 rounded-lg hover:bg-gray-100 transition-colors">
                                    <div class="flex-shrink-0">
                                        <div class="w-10 h-10 bg-white rounded-full flex items-center justify-center shadow-sm">
                                            <i class="<?php echo $activity_icon; ?> <?php echo $activity_color; ?>"></i>
                                        </div>
                                    </div>
                                    <div class="flex-1 min-w-0">
                                        <div class="text-sm font-medium text-gray-900">
                                            <?php echo htmlspecialchars($activity['student_name']); ?>
                                            <span class="font-normal text-gray-600"><?php echo htmlspecialchars($activity['activity_description']); ?></span>
                                        </div>
                                        <div class="text-sm text-gray-500"><?php echo htmlspecialchars($activity['title']); ?></div>
                                        <div class="text-xs text-gray-400 mt-1">
                                            <?php echo date('M j, Y g:i A', strtotime($activity['activity_date'])); ?>
                                        </div>
                                    </div>
                                </div>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <div class="text-center py-8">
                                <i class="fas fa-bell-slash text-gray-400 text-4xl mb-4"></i>
                                <h3 class="text-lg font-medium text-gray-900 mb-2">No Recent Activity</h3>
                                <p class="text-gray-600">No recent student activities to display.</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Quick Links Section -->
            <div class="bg-white rounded-lg shadow-sm border border-gray-200">
                <div class="p-4 sm:p-6 border-b border-gray-200">
                    <div class="flex items-center">
                        <i class="fas fa-external-link-alt text-blue-600 mr-3"></i>
                        <h3 class="text-lg font-medium text-gray-900">Quick Links & Shortcuts</h3>
                    </div>
                </div>
                <div class="p-4 sm:p-6">
                    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4">
                        <!-- View All Students -->
                        <a href="CompanyProgressReport.php" class="group flex flex-col items-center p-6 bg-gradient-to-br from-blue-50 to-blue-100 hover:from-blue-100 hover:to-blue-200 rounded-lg transition-all duration-200 transform hover:scale-105">
                            <div class="w-12 h-12 bg-blue-600 rounded-lg flex items-center justify-center mb-4 group-hover:bg-blue-700 transition-colors">
                                <i class="fas fa-users text-white text-xl"></i>
                            </div>
                            <h4 class="text-sm font-medium text-gray-900 mb-2 text-center">View All Students</h4>
                            <p class="text-xs text-gray-600 text-center">Monitor all your OJT students</p>
                        </a>

                        <!-- Evaluate Students -->
                        <a href="StudentEvaluate.php" class="group flex flex-col items-center p-6 bg-gradient-to-br from-green-50 to-green-100 hover:from-green-100 hover:to-green-200 rounded-lg transition-all duration-200 transform hover:scale-105">
                            <div class="w-12 h-12 bg-green-600 rounded-lg flex items-center justify-center mb-4 group-hover:bg-green-700 transition-colors">
                                <i class="fas fa-star text-white text-xl"></i>
                            </div>
                            <h4 class="text-sm font-medium text-gray-900 mb-2 text-center">Evaluate Students</h4>
                            <p class="text-xs text-gray-600 text-center">Submit student evaluations</p>
                        </a>

                        <!-- Attendance Logs -->
                        <a href="CompanyTimeRecord.php" class="group flex flex-col items-center p-6 bg-gradient-to-br from-purple-50 to-purple-100 hover:from-purple-100 hover:to-purple-200 rounded-lg transition-all duration-200 transform hover:scale-105">
                            <div class="w-12 h-12 bg-purple-600 rounded-lg flex items-center justify-center mb-4 group-hover:bg-purple-700 transition-colors">
                                <i class="fas fa-clock text-white text-xl"></i>
                            </div>
                            <h4 class="text-sm font-medium text-gray-900 mb-2 text-center">Attendance Logs</h4>
                            <p class="text-xs text-gray-600 text-center">View time records</p>
                        </a>

                        <!-- Reports & Documents -->
                        <a href="CompanyTasks.php" class="group flex flex-col items-center p-6 bg-gradient-to-br from-orange-50 to-orange-100 hover:from-orange-100 hover:to-orange-200 rounded-lg transition-all duration-200 transform hover:scale-105">
                            <div class="w-12 h-12 bg-orange-600 rounded-lg flex items-center justify-center mb-4 group-hover:bg-orange-700 transition-colors">
                                <i class="fas fa-folder text-white text-xl"></i>
                            </div>
                            <h4 class="text-sm font-medium text-gray-900 mb-2 text-center">Tasks & Documents</h4>
                            <p class="text-xs text-gray-600 text-center">Manage assignments</p>
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
        // Chart instance variable
        let metricsChart;

        // Initialize the chart on page load
        document.addEventListener('DOMContentLoaded', function() {
            initializeMetricsChart();
            
            // Animate progress fills
            setTimeout(() => {
                document.querySelectorAll('.progress-fill').forEach(fill => {
                    fill.style.transition = 'width 2s ease-in-out';
                });
            }, 500);
        });

        // Initialize metrics chart
        function initializeMetricsChart() {
            const ctx = document.getElementById('metricsChart').getContext('2d');
            
            const chartData = {
                labels: ['OJT Students', 'Pending Evaluations'],
                datasets: [{
                    label: 'Count',
                    data: [<?php echo $metrics_students_safe; ?>, <?php echo $metrics_pending_evaluations_safe; ?>],
                    backgroundColor: [
                        'rgba(59, 130, 246, 0.8)',   // Blue for OJT Students
                        'rgba(251, 191, 36, 0.8)'    // Yellow for Pending Evaluations
                    ],
                    borderColor: [
                        'rgba(59, 130, 246, 1)',
                        'rgba(251, 191, 36, 1)'
                    ],
                    borderWidth: 2
                }]
            };

            const chartOptions = {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    title: {
                        display: false
                    },
                    legend: {
                        display: false
                    },
                    tooltip: {
                        backgroundColor: 'rgba(0, 0, 0, 0.8)',
                        titleColor: 'white',
                        bodyColor: 'white',
                        borderColor: 'rgba(255, 255, 255, 0.2)',
                        borderWidth: 1
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: {
                            stepSize: 1,
                            color: '#6B7280'
                        },
                        grid: {
                            color: 'rgba(0, 0, 0, 0.1)'
                        }
                    },
                    x: {
                        ticks: {
                            color: '#6B7280'
                        },
                        grid: {
                            display: false
                        }
                    }
                },
                animation: {
                    duration: 1500,
                    easing: 'easeInOutCubic'
                }
            };

            metricsChart = new Chart(ctx, {
                type: 'bar',
                data: chartData,
                options: chartOptions
            });
        }

        function switchTimeFrame(timeFrame) {
            // Update button states
            document.getElementById('currentMonthBtn').className = timeFrame === 'current' 
                ? 'px-4 py-2 bg-blue-600 text-white rounded-md hover:bg-blue-700 transition-colors text-sm'
                : 'px-4 py-2 bg-gray-200 text-gray-700 rounded-md hover:bg-gray-300 transition-colors text-sm';
            
            document.getElementById('previousMonthBtn').className = timeFrame === 'previous' 
                ? 'px-4 py-2 bg-blue-600 text-white rounded-md hover:bg-blue-700 transition-colors text-sm'
                : 'px-4 py-2 bg-gray-200 text-gray-700 rounded-md hover:bg-gray-300 transition-colors text-sm';
            
            document.getElementById('yearlyBtn').className = timeFrame === 'yearly' 
                ? 'px-4 py-2 bg-blue-600 text-white rounded-md hover:bg-blue-700 transition-colors text-sm'
                : 'px-4 py-2 bg-gray-200 text-gray-700 rounded-md hover:bg-gray-300 transition-colors text-sm';

            // Update chart data based on selected time frame
            let newStudentsData, newEvaluationsData;
            switch(timeFrame) {
                case 'current':
                    newStudentsData = <?php echo $metrics_students_safe; ?>;
                    newEvaluationsData = <?php echo $metrics_pending_evaluations_safe; ?>;
                    break;
                case 'previous':
                    newStudentsData = <?php echo max(0, $metrics_students_safe - 1); ?>; // Mock previous month data
                    newEvaluationsData = <?php echo max(0, $metrics_pending_evaluations_safe + 2); ?>; // Mock data
                    break;
                case 'yearly':
                    newStudentsData = <?php echo $metrics_students_safe * 10; ?>; // Mock yearly data
                    newEvaluationsData = <?php echo $metrics_pending_evaluations_safe * 8; ?>; // Mock yearly data
                    break;
                default:
                    newStudentsData = <?php echo $metrics_students_safe; ?>;
                    newEvaluationsData = <?php echo $metrics_pending_evaluations_safe; ?>;
            }

            // Update chart
            metricsChart.data.datasets[0].data = [newStudentsData, newEvaluationsData];
            metricsChart.update('active');
        }

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

        // Refresh activity functionality
        function refreshActivity() {
            const refreshBtn = document.querySelector('button[onclick="refreshActivity()"]');
            const icon = refreshBtn.querySelector('i');
            
            // Add spinning animation
            icon.classList.add('animate-spin');
            refreshBtn.disabled = true;
            
            // Simulate refresh delay
            setTimeout(() => {
                location.reload();
            }, 1000);
        }

        // Logout confirmation
        function confirmLogout() {
            return confirm('Are you sure you want to logout?');
        }

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

        // Auto-refresh dashboard data every 5 minutes
        setInterval(() => {
            // Only refresh if user is active (not idle)
            if (document.hasFocus()) {
                location.reload();
            }
        }, 300000); // 5 minutes

        // Back button prevention
        if (performance.navigation.type === 2) {
            location.replace('login.php');
        }

        // Disable back button functionality
        history.pushState(null, null, location.href);
        window.onpopstate = function () {
            history.go(1);
        };

        // Add hover effects and animations
        document.querySelectorAll('.group').forEach(element => {
            element.addEventListener('mouseenter', function() {
                this.style.transform = 'scale(1.05)';
            });
            
            element.addEventListener('mouseleave', function() {
                this.style.transform = 'scale(1)';
            });
        });
    </script>

</body>
</html>