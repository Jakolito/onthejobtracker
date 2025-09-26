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

// Handle certificate generation request
if (isset($_GET['generate_certificate'])) {
    $student_id = intval($_GET['generate_certificate']);
    
    // Verify student has completed required hours
    $cert_check_query = "
        SELECT s.*, sd.deployment_id, sd.position, sd.start_date, sd.end_date, 
               sd.required_hours, sd.completed_hours, sd.status as deployment_status,
               sd.company_name, sd.supervisor_name
        FROM students s
        JOIN student_deployments sd ON s.id = sd.student_id
        WHERE s.id = ? AND sd.supervisor_id = ? AND sd.completed_hours >= sd.required_hours
    ";
    
    $stmt = $conn->prepare($cert_check_query);
    $stmt->bind_param("ii", $student_id, $supervisor_id);
    $stmt->execute();
    $cert_student = $stmt->get_result()->fetch_assoc();
    
    if ($cert_student) {
        // Student is eligible for certificate - redirect to certificate generation
        header("Location: generate_certificate.php?student_id=" . $student_id);
        exit;
    } else {
        $cert_error = "This student has not completed the required hours for certification.";
    }
}

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

// Check if viewing specific student
$view_student = isset($_GET['view_student']) ? $_GET['view_student'] : null;

if ($view_student) {
    // Get detailed student information
    try {
        $student_detail_query = "
            SELECT s.*, sd.deployment_id, sd.position, sd.start_date, sd.end_date, 
                   sd.required_hours, sd.completed_hours, sd.status as deployment_status,
                   sd.company_name, sd.supervisor_name
            FROM students s
            JOIN student_deployments sd ON s.id = sd.student_id
            WHERE s.id = ? AND sd.supervisor_id = ?
        ";
        $stmt = $conn->prepare($student_detail_query);
        $stmt->bind_param("ii", $view_student, $supervisor_id);
        $stmt->execute();
        $student_details = $stmt->get_result()->fetch_assoc();

        if ($student_details) {
            // Calculate progress percentage with null safety
            $completed_hours = (float)($student_details['completed_hours'] ?? 0);
            $required_hours = (float)($student_details['required_hours'] ?? 1);
            $progress_percentage = $required_hours > 0 ? ($completed_hours / $required_hours) * 100 : 0;
            $progress_percentage = min(100, round($progress_percentage, 1));
            
            // Check if student is eligible for certificate
            $is_eligible_for_certificate = $completed_hours >= $required_hours;

            // Get attendance data for the last 30 days
            $attendance_query = "
                SELECT date, time_in, time_out, total_hours, status, notes
                FROM student_attendance 
                WHERE student_id = ? AND deployment_id = ?
                ORDER BY date DESC 
                LIMIT 30
            ";
            $stmt = $conn->prepare($attendance_query);
            $stmt->bind_param("ii", $view_student, $student_details['deployment_id']);
            $stmt->execute();
            $attendance_result = $stmt->get_result();
            $attendance_data = [];
            while ($row = $attendance_result->fetch_assoc()) {
                $attendance_data[] = $row;
            }

            // Get task data for this student
            $task_query = "
                SELECT t.*, ts.submission_id, ts.status as submission_status, 
                       ts.submitted_at, ts.reviewed_at, ts.feedback
                FROM tasks t
                LEFT JOIN task_submissions ts ON t.task_id = ts.task_id
                WHERE t.student_id = ? AND t.supervisor_id = ?
                ORDER BY t.created_at DESC
            ";
            $stmt = $conn->prepare($task_query);
            $stmt->bind_param("ii", $view_student, $supervisor_id);
            $stmt->execute();
            $task_result = $stmt->get_result();
            $task_data = [];
            while ($row = $task_result->fetch_assoc()) {
                $task_data[] = $row;
            }

            // Get task statistics with null safety
            $task_stats_query = "
                SELECT 
                    COUNT(*) as total_tasks,
                    SUM(CASE WHEN t.status = 'Completed' THEN 1 ELSE 0 END) as completed_tasks,
                    SUM(CASE WHEN t.status = 'In Progress' THEN 1 ELSE 0 END) as in_progress_tasks,
                    SUM(CASE WHEN t.status = 'Pending' THEN 1 ELSE 0 END) as pending_tasks,
                    SUM(CASE WHEN t.status = 'Overdue' THEN 1 ELSE 0 END) as overdue_tasks
                FROM tasks t
                WHERE t.student_id = ? AND t.supervisor_id = ?
            ";
            $stmt = $conn->prepare($task_stats_query);
            $stmt->bind_param("ii", $view_student, $supervisor_id);
            $stmt->execute();
            $task_stats = $stmt->get_result()->fetch_assoc();

            $evaluation_query = "
    SELECT *, 
           equivalent_rating,
           verbal_interpretation,
           remarks_comments_suggestions,
           -- Calculate category averages from actual fields
           ROUND((teamwork_1 + teamwork_2 + teamwork_3 + teamwork_4 + teamwork_5) / 5.0, 1) as teamwork_avg,
           ROUND((communication_6 + communication_7 + communication_8 + communication_9) / 4.0, 1) as communication_avg,
           ROUND((attendance_10 + attendance_11 + attendance_12) / 3.0, 1) as attendance_avg,
           ROUND((productivity_13 + productivity_14 + productivity_15 + productivity_16 + productivity_17) / 5.0, 1) as productivity_avg,
           ROUND((initiative_18 + initiative_19 + initiative_20 + initiative_21 + initiative_22 + initiative_23) / 6.0, 1) as initiative_avg,
           ROUND((judgement_24 + judgement_25 + judgement_26) / 3.0, 1) as judgement_avg,
           ROUND((dependability_27 + dependability_28 + dependability_29 + dependability_30 + dependability_31) / 5.0, 1) as dependability_avg,
           ROUND((attitude_32 + attitude_33 + attitude_34 + attitude_35 + attitude_36) / 5.0, 1) as attitude_avg,
           ROUND((professionalism_37 + professionalism_38 + professionalism_39 + professionalism_40) / 4.0, 1) as professionalism_avg
    FROM student_evaluations 
    WHERE student_id = ? AND supervisor_id = ?
    ORDER BY created_at DESC 
    LIMIT 1
";
$stmt = $conn->prepare($evaluation_query);
$stmt->bind_param("ii", $view_student, $supervisor_id);
$stmt->execute();
$evaluation_result = $stmt->get_result();
$evaluation_data = null;
if ($evaluation_result->num_rows > 0) {
    $evaluation_data = $evaluation_result->fetch_assoc();
    // Use equivalent_rating as the overall performance rating
    $evaluation_data['overall_performance'] = $evaluation_data['equivalent_rating'];
}


            // Get attendance statistics with null safety
            $attendance_stats_query = "
                SELECT 
                    COUNT(*) as total_days,
                    SUM(CASE WHEN status = 'Present' THEN 1 ELSE 0 END) as present_days,
                    SUM(CASE WHEN status = 'Absent' THEN 1 ELSE 0 END) as absent_days,
                    SUM(CASE WHEN status = 'Late' THEN 1 ELSE 0 END) as late_days
                FROM student_attendance 
                WHERE student_id = ? AND deployment_id = ?
            ";
            $stmt = $conn->prepare($attendance_stats_query);
            $stmt->bind_param("ii", $view_student, $student_details['deployment_id']);
            $stmt->execute();
            $attendance_stats = $stmt->get_result()->fetch_assoc();
            
            // Ensure attendance stats have default values
            $attendance_stats = array_map(function($val) { return $val ?? 0; }, $attendance_stats);
        }
    } catch (Exception $e) {
        $error_message = "Error fetching student details: " . $e->getMessage();
    }
} else {
    // Get all students under this supervisor with summary data - FIXED QUERY
    try {
        $students_query = "
            SELECT DISTINCT s.id, s.student_id, s.first_name, s.middle_name, s.last_name, 
                   s.program, s.year_level, s.profile_picture,
                   sd.deployment_id, sd.position, sd.start_date, sd.end_date, 
                   COALESCE(sd.required_hours, 0) as required_hours, 
                   COALESCE(sd.completed_hours, 0) as completed_hours, 
                   sd.status as deployment_status,
                   
                   -- Check if eligible for certificate
                   CASE WHEN sd.completed_hours >= sd.required_hours 
                        THEN 1 ELSE 0 END as eligible_for_certificate,
                   
                   -- Get attendance stats
                   COALESCE(att_stats.total_days, 0) as total_attendance_days,
                   COALESCE(att_stats.present_days, 0) as present_days,
                   COALESCE(att_stats.absent_days, 0) as absent_days,
                   
                   -- Get task stats
                   COALESCE(task_stats.total_tasks, 0) as total_tasks,
                   COALESCE(task_stats.completed_tasks, 0) as completed_tasks,
                   
                   -- Get latest evaluation - FIXED
                   COALESCE(eval_data.equivalent_rating, eval_data.calculated_rating) as latest_evaluation
                   
            FROM students s
            JOIN student_deployments sd ON s.id = sd.student_id
            
            LEFT JOIN (
                SELECT student_id, deployment_id,
                       COUNT(*) as total_days,
                       SUM(CASE WHEN status = 'Present' THEN 1 ELSE 0 END) as present_days,
                       SUM(CASE WHEN status = 'Absent' THEN 1 ELSE 0 END) as absent_days
                FROM student_attendance 
                GROUP BY student_id, deployment_id
            ) att_stats ON s.id = att_stats.student_id AND sd.deployment_id = att_stats.deployment_id
            
            LEFT JOIN (
                SELECT student_id,
                       COUNT(*) as total_tasks,
                       SUM(CASE WHEN status = 'Completed' THEN 1 ELSE 0 END) as completed_tasks
                FROM tasks 
                GROUP BY student_id
            ) task_stats ON s.id = task_stats.student_id
            
            LEFT JOIN (
                SELECT student_id, 
                       equivalent_rating,
                       (total_score / 40.0) as calculated_rating,
                       ROW_NUMBER() OVER (PARTITION BY student_id ORDER BY created_at DESC) as rn
                FROM student_evaluations
            ) eval_data ON s.id = eval_data.student_id AND eval_data.rn = 1
            
            WHERE sd.supervisor_id = ?
            ORDER BY 
                CASE WHEN sd.status = 'Active' THEN 1 
                     WHEN sd.status = 'Completed' THEN 2 
                     ELSE 3 END,
                s.last_name, s.first_name
        ";
        $stmt = $conn->prepare($students_query);
        $stmt->bind_param("i", $supervisor_id);
        $stmt->execute();
        $students_result = $stmt->get_result();
        $students_data = [];
        while ($row = $students_result->fetch_assoc()) {
            $students_data[] = $row;
        }
    } catch (Exception $e) {
        $error_message = "Error fetching students data: " . $e->getMessage();
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>OnTheJob Tracker - Student Progress Report</title>
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
        .progress-bar {
            transition: width 1.5s cubic-bezier(0.4, 0, 0.2, 1);
            transform-origin: left center;
        }
        .progress-bar.animate {
            animation: progressFill 2s ease-out forwards;
        }
        @keyframes progressFill {
            0% { 
                width: 0%; 
                opacity: 0.7;
            }
            50% {
                opacity: 0.9;
            }
            100% { 
                opacity: 1;
            }
        }
        .student-avatar img, .avatar img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }
        .card-hover {
            transition: all 0.3s ease;
        }
        .card-hover:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 25px -5px rgba(0, 0, 0, 0.1), 0 10px 10px -5px rgba(0, 0, 0, 0.04);
        }
        .certificate-glow {
            box-shadow: 0 0 20px rgba(255, 215, 0, 0.5);
            animation: certificateGlow 2s ease-in-out infinite alternate;
        }
        @keyframes certificateGlow {
            0% { box-shadow: 0 0 20px rgba(255, 215, 0, 0.3); }
            100% { box-shadow: 0 0 30px rgba(255, 215, 0, 0.7); }
        }
        .completed-row {
            background-color: rgba(34, 197, 94, 0.05);
            border-left: 4px solid #22c55e;
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
            <a href="CompanyTimeRecord.php" class="nav-item flex items-center px-3 py-2 text-sm font-medium text-bulsu-light-gold hover:text-white hover:bg-bulsu-gold hover:bg-opacity-20 rounded-md transition-all duration-200">
                <i class="fas fa-clock mr-3"></i>
                Student Time Record
            </a>
            <a href="ApproveTasks.php" class="nav-item flex items-center px-3 py-2 text-sm font-medium text-bulsu-light-gold hover:text-white hover:bg-bulsu-gold hover:bg-opacity-20 rounded-md transition-all duration-200">
                <i class="fas fa-comment-dots mr-3"></i>
                Task Approval Management
            </a>
            <a href="CompanyProgressReport.php" class="nav-item flex items-center px-3 py-2 text-sm font-medium text-white bg-bulsu-gold bg-opacity-20 border border-bulsu-gold border-opacity-30 rounded-md">
                <i class="fas fa-chart-line mr-3 text-bulsu-gold"></i>
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
                    <h1 class="text-xl sm:text-2xl font-bold text-gray-900">
                        <?php if ($view_student): ?>
                            Student Progress Details
                        <?php else: ?>
                            Student Progress Report
                        <?php endif; ?>
                    </h1>
                    <p class="text-sm sm:text-base text-gray-500 hidden sm:block">
                        <?php if ($view_student): ?>
                            Detailed progress report for selected student
                        <?php else: ?>
                            Track OJT progress, attendance, performance evaluation, and task completion
                        <?php endif; ?>
                    </p>
                </div>
                
                <!-- Profile Dropdown -->
                <div class="relative">
                    <button id="profileBtn" class="flex items-center p-1 rounded-full hover:bg-gray-100">
                        <div class="w-8 h-8 sm:w-10 sm:h-10 bg-gradient-to-r from-blue-500 to-purple-600 rounded-full flex items-center justify-center text-white font-semibold text-xs sm:text-sm avatar">
                            <?php if (!empty($profile_picture) && file_exists($profile_picture)): ?>
                                <img src="<?php echo htmlspecialchars($profile_picture); ?>" alt="Profile Picture" class="rounded-full">
                            <?php else: ?>
                                <?php echo $initials; ?>
                            <?php endif; ?>
                        </div>
                    </button>
                    <div id="profileDropdown" class="hidden absolute right-0 mt-2 w-48 sm:w-64 bg-white rounded-md shadow-lg border border-gray-200 z-50">
                        <div class="p-4 border-b border-gray-200">
                            <div class="flex items-center space-x-3">
                                <div class="w-12 h-12 bg-gradient-to-r from-blue-500 to-purple-600 rounded-full flex items-center justify-center text-white font-semibold avatar">
                                    <?php if (!empty($profile_picture) && file_exists($profile_picture)): ?>
                                        <img src="<?php echo htmlspecialchars($profile_picture); ?>" alt="Profile Picture" class="rounded-full">
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

            <!-- Certificate Error Display -->
            <?php if (isset($cert_error)): ?>
                <div class="mb-6 p-4 bg-yellow-50 border border-yellow-200 rounded-lg">
                    <div class="flex items-start">
                        <i class="fas fa-exclamation-triangle text-yellow-600 mt-1 mr-3"></i>
                        <p class="text-yellow-700"><?php echo htmlspecialchars($cert_error); ?></p>
                    </div>
                </div>
            <?php endif; ?>

            <?php if ($view_student && isset($student_details) && $student_details): ?>
                <!-- Back button -->
                <div class="mb-6">
                    <a href="CompanyProgressReport.php" class="inline-flex items-center px-4 py-2 text-sm font-medium text-gray-600 bg-white border border-gray-300 rounded-md shadow-sm hover:bg-gray-50 transition-colors">
                        <i class="fas fa-arrow-left mr-2"></i>
                        Back to Students List
                    </a>
                </div>

                <!-- Student Information Section -->
                <div class="bg-white rounded-lg shadow-sm border border-gray-200 mb-6 card-hover <?php echo $is_eligible_for_certificate ? 'completed-row' : ''; ?>">
                    <div class="p-6">
                        <div class="flex flex-col sm:flex-row sm:items-center space-y-4 sm:space-y-0 sm:space-x-6 mb-6 pb-6 border-b border-gray-200">
                            <div class="flex-shrink-0">
                                <div class="w-20 h-20 bg-gradient-to-r from-blue-500 to-purple-600 rounded-full flex items-center justify-center text-white font-bold text-2xl student-avatar">
                                    <?php if (!empty($student_details['profile_picture']) && file_exists($student_details['profile_picture'])): ?>
                                        <img src="<?php echo htmlspecialchars($student_details['profile_picture']); ?>" alt="Profile Picture" class="rounded-full">
                                    <?php else: ?>
                                        <?php echo strtoupper(substr($student_details['first_name'], 0, 1) . substr($student_details['last_name'], 0, 1)); ?>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div class="flex-1">
                                <div class="flex items-center justify-between">
                                    <div>
                                        <h2 class="text-2xl font-bold text-gray-900 mb-2">
                                            <?php echo htmlspecialchars($student_details['first_name'] . ' ' . ($student_details['middle_name'] ? $student_details['middle_name'] . ' ' : '') . $student_details['last_name']); ?>
                                        </h2>
                                        <div class="space-y-1 text-sm text-gray-600">
                                            <p><span class="font-medium">Student ID:</span> <?php echo htmlspecialchars($student_details['student_id']); ?></p>
                                            <p><span class="font-medium">Position:</span> <?php echo htmlspecialchars($student_details['position']); ?></p>
                                            <p><span class="font-medium">Status:</span> 
                                                <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium <?php 
                                                    echo $student_details['deployment_status'] === 'Active' ? 'bg-green-100 text-green-800' : 
                                                        ($student_details['deployment_status'] === 'Completed' ? 'bg-blue-100 text-blue-800' :
                                                        ($student_details['deployment_status'] === 'Inactive' ? 'bg-red-100 text-red-800' : 'bg-gray-100 text-gray-800')); 
                                                ?>">
                                                    <?php 
                                                    if ($student_details['deployment_status'] === 'Active' && $is_eligible_for_certificate) {
                                                        echo 'Completed';
                                                    } else {
                                                        echo $student_details['deployment_status'];
                                                    }
                                                    ?>
                                                </span>
                                            </p>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6">
                            <div>
                                <div class="text-sm text-gray-500 mb-1">Course & Year</div>
                                <div class="font-medium text-gray-900"><?php echo htmlspecialchars($student_details['program'] . ' - ' . $student_details['year_level']); ?></div>
                            </div>
                            <div>
                                <div class="text-sm text-gray-500 mb-1">Department</div>
                                <div class="font-medium text-gray-900"><?php echo htmlspecialchars($student_details['department'] ?? 'N/A'); ?></div>
                            </div>
                            <div>
                                <div class="text-sm text-gray-500 mb-1">Section</div>
                                <div class="font-medium text-gray-900"><?php echo htmlspecialchars($student_details['section'] ?? 'N/A'); ?></div>
                            </div>
                            <div>
                                <div class="text-sm text-gray-500 mb-1">Contact Number</div>
                                <div class="font-medium text-gray-900"><?php echo htmlspecialchars($student_details['contact_number'] ?? 'N/A'); ?></div>
                            </div>
                            <div>
                                <div class="text-sm text-gray-500 mb-1">Email Address</div>
                                <div class="font-medium text-gray-900 truncate"><?php echo htmlspecialchars($student_details['email'] ?? 'N/A'); ?></div>
                            </div>
                            <div>
                                <div class="text-sm text-gray-500 mb-1">Start Date</div>
                                <div class="font-medium text-gray-900"><?php echo date('M d, Y', strtotime($student_details['start_date'])); ?></div>
                            </div>
                            <div>
                                <div class="text-sm text-gray-500 mb-1">End Date</div>
                                <div class="font-medium text-gray-900"><?php echo date('M d, Y', strtotime($student_details['end_date'])); ?></div>
                            </div>
                            <div>
                                <div class="text-sm text-gray-500 mb-1">Company</div>
                                <div class="font-medium text-gray-900"><?php echo htmlspecialchars($student_details['company_name']); ?></div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Progress Overview Cards -->
                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 mb-6">
                    <!-- Hours Progress -->
                    <div class="bg-white rounded-lg shadow-sm border border-gray-200 border-l-4 border-l-blue-500 p-6 card-hover">
                        <div class="flex items-center justify-between">
                            <div class="flex-1">
                                <div class="text-2xl font-bold text-blue-600 mb-1"><?php echo number_format($completed_hours); ?></div>
                                <div class="text-sm text-gray-600 mb-2">Hours Completed</div>
                                <div class="w-full bg-gray-200 rounded-full h-2">
                                    <div class="bg-gradient-to-r from-blue-500 to-blue-600 h-2 rounded-full progress-bar" 
                                         data-progress="<?php echo $progress_percentage; ?>"></div>
                                </div>
                                <div class="text-xs text-gray-500 mt-1"><?php echo $progress_percentage; ?>% of <?php echo $required_hours; ?> hours</div>
                                <?php if ($is_eligible_for_certificate): ?>
                                <div class="mt-2">
                                    <span class="inline-flex items-center px-2 py-1 rounded-full text-xs font-medium bg-green-100 text-green-800">
                                        <i class="fas fa-check-circle mr-1"></i>
                                        Completed!
                                    </span>
                                </div>
                                <?php endif; ?>
                            </div>
                            <div class="ml-4">
                                <div class="w-12 h-12 bg-blue-100 rounded-full flex items-center justify-center">
                                    <i class="fas fa-clock text-blue-600 text-lg"></i>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Attendance -->
                    <div class="bg-white rounded-lg shadow-sm border border-gray-200 border-l-4 border-l-green-500 p-6 card-hover">
                        <div class="flex items-center justify-between">
                            <div>
                                <div class="text-2xl font-bold text-green-600 mb-1"><?php echo $attendance_stats['present_days']; ?></div>
                                <div class="text-sm text-gray-600">Days Present</div>
                                <div class="text-xs text-gray-500 mt-1">Out of <?php echo $attendance_stats['total_days']; ?> total days</div>
                            </div>
                            <div class="ml-4">
                                <div class="w-12 h-12 bg-green-100 rounded-full flex items-center justify-center">
                                    <i class="fas fa-calendar-check text-green-600 text-lg"></i>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Tasks -->
                    <div class="bg-white rounded-lg shadow-sm border border-gray-200 border-l-4 border-l-purple-500 p-6 card-hover">
                        <div class="flex items-center justify-between">
                            <div>
                                <div class="text-2xl font-bold text-purple-600 mb-1"><?php echo $task_stats['completed_tasks']; ?></div>
                                <div class="text-sm text-gray-600">Tasks Completed</div>
                                <div class="text-xs text-gray-500 mt-1">Out of <?php echo $task_stats['total_tasks']; ?> total tasks</div>
                            </div>
                            <div class="ml-4">
                                <div class="w-12 h-12 bg-purple-100 rounded-full flex items-center justify-center">
                                    <i class="fas fa-tasks text-purple-600 text-lg"></i>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Evaluation -->
                    <?php if (isset($evaluation_data) && $evaluation_data): ?>
                    <div class="bg-white rounded-lg shadow-sm border border-gray-200 border-l-4 border-l-yellow-500 p-6 card-hover">
                        <div class="flex items-center justify-between">
                            <div>
                                <div class="text-2xl font-bold text-yellow-600 mb-1"><?php echo number_format($evaluation_data['overall_performance'], 1); ?></div>
                                <div class="text-sm text-gray-600 mb-2">Overall Rating</div>
                                <div class="flex text-yellow-400">
                                    <?php
                                    $rating = round($evaluation_data['overall_performance']);
                                    for ($i = 1; $i <= 5; $i++):
                                    ?>
                                        <i class="fas fa-star<?php echo $i <= $rating ? '' : ' opacity-30'; ?> text-sm"></i>
                                    <?php endfor; ?>
                                </div>
                            </div>
                            <div class="ml-4">
                                <div class="w-12 h-12 bg-yellow-100 rounded-full flex items-center justify-center">
                                    <i class="fas fa-star text-yellow-600 text-lg"></i>
                                </div>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>

                <!-- Certificate Generation Section (if eligible) -->
                <?php if ($is_eligible_for_certificate): ?>
                <div class="bg-gradient-to-r from-yellow-50 to-amber-50 rounded-lg shadow-sm border border-yellow-200 mb-6 certificate-glow">
                    <div class="p-6">
                        <div class="flex items-center justify-between">
                            <div class="flex items-center">
                                <div class="w-16 h-16 bg-gradient-to-r from-yellow-500 to-amber-600 rounded-full flex items-center justify-center mr-4">
                                    <i class="fas fa-trophy text-white text-2xl"></i>
                                </div>
                                <div>
                                    <h3 class="text-xl font-bold text-gray-900 mb-2">Congratulations! OJT Completed</h3>
                                    <p class="text-gray-600 mb-2">
                                        This student has successfully completed their required OJT hours 
                                        (<?php echo number_format($completed_hours); ?>/<?php echo number_format($required_hours); ?> hours).
                                    </p>
                                    <p class="text-sm text-gray-500">
                                        You can now generate an official completion certificate for download.
                                    </p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Recent Attendance -->
                <div class="bg-white rounded-lg shadow-sm border border-gray-200 mb-6 card-hover">
                    <div class="p-6 border-b border-gray-200">
                        <div class="flex items-center justify-between">
                            <div class="flex items-center">
                                <i class="fas fa-calendar-alt text-blue-600 mr-3"></i>
                                <h3 class="text-lg font-semibold text-gray-900">Recent Attendance (Last 30 Days)</h3>
                            </div>
                            <button onclick="location.reload()" class="flex items-center px-3 py-2 text-sm font-medium text-gray-600 bg-gray-100 hover:bg-gray-200 rounded-md transition-colors">
                                <i class="fas fa-sync-alt mr-2"></i>
                                <span class="hidden sm:inline">Refresh</span>
                            </button>
                        </div>
                    </div>
                    <div class="p-6">
                        <?php if (!empty($attendance_data)): ?>
                        <div class="overflow-x-auto">
                            <table class="min-w-full divide-y divide-gray-200">
                                <thead class="bg-gray-50">
                                    <tr>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Date</th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Time In</th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Time Out</th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Total Hours</th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Status</th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Notes</th>
                                    </tr>
                                </thead>
                                <tbody class="bg-white divide-y divide-gray-200">
                                    <?php foreach ($attendance_data as $attendance): ?>
                                    <tr class="hover:bg-gray-50">
                                        <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900">
                                            <?php echo date('M d, Y', strtotime($attendance['date'])); ?>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                            <?php echo $attendance['time_in'] ? date('h:i A', strtotime($attendance['time_in'])) : '--'; ?>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                            <?php echo $attendance['time_out'] ? date('h:i A', strtotime($attendance['time_out'])) : '--'; ?>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                            <?php echo number_format($attendance['total_hours'], 2); ?>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap">
                                            <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium <?php 
                                                echo $attendance['status'] === 'Present' ? 'bg-green-100 text-green-800' : 
                                                    ($attendance['status'] === 'Absent' ? 'bg-red-100 text-red-800' : 'bg-yellow-100 text-yellow-800'); 
                                            ?>">
                                                <?php echo $attendance['status']; ?>
                                            </span>
                                        </td>
                                        <td class="px-6 py-4 text-sm text-gray-500">
                                            <?php echo htmlspecialchars($attendance['notes'] ?? ''); ?>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        <?php else: ?>
                        <div class="text-center py-12">
                            <i class="fas fa-calendar-times text-gray-400 text-5xl mb-4"></i>
                            <h3 class="text-lg font-medium text-gray-900 mb-2">No Attendance Records</h3>
                            <p class="text-gray-600">No attendance records found for this student.</p>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Tasks Overview -->
                <div class="bg-white rounded-lg shadow-sm border border-gray-200 mb-6 card-hover">
                    <div class="p-6 border-b border-gray-200">
                        <div class="flex items-center">
                            <i class="fas fa-tasks text-purple-600 mr-3"></i>
                            <h3 class="text-lg font-semibold text-gray-900">Tasks Overview</h3>
                        </div>
                    </div>
                    <div class="p-6">
                        <?php if (!empty($task_data)): ?>
                        <div class="overflow-x-auto">
                            <table class="min-w-full divide-y divide-gray-200">
                                <thead class="bg-gray-50">
                                    <tr>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Task Title</th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Category</th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Priority</th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Due Date</th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Status</th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Submission</th>
                                    </tr>
                                </thead>
                                <tbody class="bg-white divide-y divide-gray-200">
                                    <?php foreach ($task_data as $task): ?>
                                    <tr class="hover:bg-gray-50">
                                        <td class="px-6 py-4">
                                            <div>
                                                <div class="text-sm font-medium text-gray-900"><?php echo htmlspecialchars($task['task_title']); ?></div>
                                                <div class="text-sm text-gray-500 truncate max-w-xs"><?php echo htmlspecialchars(substr($task['task_description'] ?? '', 0, 100)) . (strlen($task['task_description'] ?? '') > 100 ? '...' : ''); ?></div>
                                            </div>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                            <?php echo htmlspecialchars($task['task_category'] ?? 'N/A'); ?>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap">
                                            <span class="inline-flex items-center text-xs font-medium <?php 
                                                echo strtolower($task['priority'] ?? '') === 'high' ? 'text-red-600' : 
                                                    (strtolower($task['priority'] ?? '') === 'medium' ? 'text-yellow-600' : 'text-green-600'); 
                                            ?>">
                                                <i class="fas fa-flag mr-1"></i>
                                                <?php echo $task['priority'] ?? 'Low'; ?>
                                            </span>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                            <?php echo date('M d, Y', strtotime($task['due_date'])); ?>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap">
                                            <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium <?php 
                                                echo strtolower($task['status']) === 'completed' ? 'bg-green-100 text-green-800' : 
                                                    (strtolower($task['status']) === 'in progress' ? 'bg-blue-100 text-blue-800' : 
                                                    (strtolower($task['status']) === 'overdue' ? 'bg-red-100 text-red-800' : 'bg-yellow-100 text-yellow-800')); 
                                            ?>">
                                                <?php echo $task['status']; ?>
                                            </span>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap">
                                            <?php if ($task['submission_status']): ?>
                                                <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium <?php 
                                                    echo strtolower($task['submission_status']) === 'approved' ? 'bg-green-100 text-green-800' : 
                                                        (strtolower($task['submission_status']) === 'pending' ? 'bg-yellow-100 text-yellow-800' : 'bg-gray-100 text-gray-800'); 
                                                ?>">
                                                    <?php echo $task['submission_status']; ?>
                                                </span>
                                            <?php else: ?>
                                                <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-gray-100 text-gray-800">
                                                    Not Submitted
                                                </span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        <?php else: ?>
                        <div class="text-center py-12">
                            <i class="fas fa-tasks text-gray-400 text-5xl mb-4"></i>
                            <h3 class="text-lg font-medium text-gray-900 mb-2">No Tasks Found</h3>
                            <p class="text-gray-600">No tasks have been assigned to this student yet.</p>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Latest Performance Evaluation -->
                <div class="bg-white rounded-lg shadow-sm border border-gray-200 mb-6 card-hover">
    <div class="p-6 border-b border-gray-200">
        <div class="flex items-center">
            <i class="fas fa-star text-yellow-600 mr-3"></i>
            <h3 class="text-lg font-semibold text-gray-900">Latest Performance Evaluation</h3>
        </div>
    </div>
    <div class="p-6">
        <?php if (isset($evaluation_data) && $evaluation_data): ?>
        
        <!-- Overall Rating Display -->
        <div class="mb-6 p-4 bg-gradient-to-r from-blue-50 to-purple-50 rounded-lg">
            <div class="flex items-center justify-between">
                <div>
                    <h4 class="text-lg font-semibold text-gray-900 mb-2">Overall Performance Rating</h4>
                    <div class="flex items-center space-x-3">
                        <div class="text-3xl font-bold text-blue-600">
                            <?php echo number_format($evaluation_data['equivalent_rating'], 2); ?>
                        </div>
                        <div class="flex text-yellow-400 text-xl">
                            <?php
                            $rating = round($evaluation_data['equivalent_rating']);
                            for ($i = 1; $i <= 5; $i++):
                            ?>
                                <i class="fas fa-star<?php echo $i <= $rating ? '' : ' opacity-30'; ?>"></i>
                            <?php endfor; ?>
                        </div>
                        <div class="text-sm font-medium text-gray-600 bg-white px-3 py-1 rounded-full">
                            <?php echo htmlspecialchars($evaluation_data['verbal_interpretation']); ?>
                        </div>
                    </div>
                </div>
                <div class="text-sm text-gray-500">
                    Total Score: <?php echo $evaluation_data['total_score']; ?>/40
                </div>
            </div>
        </div>

        <!-- Category Breakdown -->
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4 mb-6">
            <?php 
            $evaluation_categories = [
                'teamwork_avg' => ['name' => 'Teamwork & Collaboration', 'icon' => 'fa-users', 'color' => 'blue'],
                'communication_avg' => ['name' => 'Communication Skills', 'icon' => 'fa-comments', 'color' => 'green'],
                'attendance_avg' => ['name' => 'Attendance & Punctuality', 'icon' => 'fa-clock', 'color' => 'purple'],
                'productivity_avg' => ['name' => 'Work Productivity', 'icon' => 'fa-chart-line', 'color' => 'indigo'],
                'initiative_avg' => ['name' => 'Initiative & Creativity', 'icon' => 'fa-lightbulb', 'color' => 'yellow'],
                'judgement_avg' => ['name' => 'Decision Making', 'icon' => 'fa-balance-scale', 'color' => 'red'],
                'dependability_avg' => ['name' => 'Dependability', 'icon' => 'fa-shield-alt', 'color' => 'teal'],
                'attitude_avg' => ['name' => 'Work Attitude', 'icon' => 'fa-smile', 'color' => 'pink'],
                'professionalism_avg' => ['name' => 'Professionalism', 'icon' => 'fa-briefcase', 'color' => 'gray']
            ];
            
            foreach ($evaluation_categories as $key => $category): 
                if (isset($evaluation_data[$key]) && $evaluation_data[$key] > 0):
            ?>
            <div class="flex items-center justify-between p-4 bg-gray-50 rounded-lg border-l-4 border-<?php echo $category['color']; ?>-400">
                <div class="flex items-center">
                    <div class="w-10 h-10 bg-<?php echo $category['color']; ?>-100 rounded-full flex items-center justify-center mr-3">
                        <i class="fas <?php echo $category['icon']; ?> text-<?php echo $category['color']; ?>-600"></i>
                    </div>
                    <div>
                        <div class="font-medium text-gray-900 text-sm"><?php echo $category['name']; ?></div>
                        <div class="flex text-yellow-400 text-xs">
                            <?php
                            $rating = round($evaluation_data[$key]);
                            for ($i = 1; $i <= 5; $i++):
                            ?>
                                <i class="fas fa-star<?php echo $i <= $rating ? '' : ' opacity-30'; ?>"></i>
                            <?php endfor; ?>
                        </div>
                    </div>
                </div>
                <div class="text-lg font-bold text-<?php echo $category['color']; ?>-600">
                    <?php echo number_format($evaluation_data[$key], 1); ?>
                </div>
            </div>
            <?php 
                endif;
            endforeach; 
            ?>
        </div>
        
        <!-- Comments and Remarks -->
        <?php if (!empty($evaluation_data['remarks_comments_suggestions'])): ?>
        <div class="mb-4 p-4 bg-blue-50 border-l-4 border-blue-400 rounded-r-lg">
            <h4 class="font-semibold text-blue-800 mb-2">Supervisor's Comments & Suggestions:</h4>
            <p class="text-blue-700"><?php echo nl2br(htmlspecialchars($evaluation_data['remarks_comments_suggestions'])); ?></p>
        </div>
        <?php endif; ?>
        
        <!-- Evaluation Details -->
        <div class="grid grid-cols-1 md:grid-cols-2 gap-4 text-sm text-gray-600 mt-6 pt-4 border-t border-gray-200">
            <div>
                <span class="font-medium">Evaluator:</span> 
                <?php echo htmlspecialchars($evaluation_data['evaluator_name']); ?>
                <?php if (!empty($evaluation_data['evaluator_position'])): ?>
                    (<?php echo htmlspecialchars($evaluation_data['evaluator_position']); ?>)
                <?php endif; ?>
            </div>
            <div>
                <span class="font-medium">Training Period:</span> 
                <?php echo date('M d', strtotime($evaluation_data['training_period_start'])); ?> - 
                <?php echo date('M d, Y', strtotime($evaluation_data['training_period_end'])); ?>
            </div>
            <div>
                <span class="font-medium">Total Hours Rendered:</span> 
                <?php echo number_format($evaluation_data['total_hours_rendered']); ?> hours
            </div>
            <div>
                <span class="font-medium">Evaluation Date:</span> 
                <?php echo date('M d, Y', strtotime($evaluation_data['created_at'])); ?>
            </div>
        </div>
        
        <?php else: ?>
        <div class="text-center py-12">
            <i class="fas fa-star-half-alt text-gray-400 text-5xl mb-4"></i>
            <h3 class="text-lg font-medium text-gray-900 mb-2">No Evaluation Available</h3>
            <p class="text-gray-600 mb-4">This student has not been evaluated yet.</p>
            <a href="StudentEvaluate.php?student_id=<?php echo $view_student; ?>" class="inline-flex items-center px-4 py-2 bg-gradient-to-r from-blue-500 to-purple-600 text-white rounded-md hover:from-blue-600 hover:to-purple-700 transition-colors">
                <i class="fas fa-plus mr-2"></i>
                Create Evaluation
            </a>
        </div>
        <?php endif; ?>
    </div>
</div>

            <?php else: ?>
                <!-- Students List View -->
                
                <!-- Summary Statistics - MOVED TO TOP -->
                <?php if (!empty($students_data)): ?>
                <div class="mb-6 grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
                    <?php 
                    $total_students = count($students_data);
                    $active_students = count(array_filter($students_data, function($s) { return $s['deployment_status'] === 'Active'; }));
                    $completed_students = count(array_filter($students_data, function($s) { return $s['deployment_status'] === 'Completed' || ($s['deployment_status'] === 'Active' && $s['eligible_for_certificate'] == 1); }));
                    $eligible_for_cert = count(array_filter($students_data, function($s) { return $s['eligible_for_certificate'] == 1; }));
                    ?>
                    <div class="bg-gradient-to-r from-blue-500 to-blue-600 rounded-lg p-4 text-white">
                        <div class="flex items-center">
                            <i class="fas fa-users text-2xl mr-3"></i>
                            <div>
                                <div class="text-2xl font-bold"><?php echo $total_students; ?></div>
                                <div class="text-sm opacity-90">Total Students</div>
                            </div>
                        </div>
                    </div>
                    <div class="bg-gradient-to-r from-green-500 to-green-600 rounded-lg p-4 text-white">
                        <div class="flex items-center">
                            <i class="fas fa-play-circle text-2xl mr-3"></i>
                            <div>
                                <div class="text-2xl font-bold"><?php echo $active_students; ?></div>
                                <div class="text-sm opacity-90">Active Students</div>
                            </div>
                        </div>
                    </div>
                    <div class="bg-gradient-to-r from-purple-500 to-purple-600 rounded-lg p-4 text-white">
                        <div class="flex items-center">
                            <i class="fas fa-check-circle text-2xl mr-3"></i>
                            <div>
                                <div class="text-2xl font-bold"><?php echo $completed_students; ?></div>
                                <div class="text-sm opacity-90">Completed</div>
                            </div>
                        </div>
                    </div>
                </div>
<?php endif; ?>

                <!-- Students Table -->
                <div class="bg-white rounded-lg shadow-sm border border-bulsu-maroon card-hover overflow-hidden">
    <!-- Header -->
    <div class="bg-gradient-to-r from-bulsu-maroon to-bulsu-dark-maroon px-6 py-4">
        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between space-y-4 sm:space-y-0">
            <!-- Title + Icon -->
            <div class="flex items-center">
                <i class="fas fa-users text-bulsu-gold mr-3"></i>
                <h3 class="text-lg font-semibold text-white">Students Under Your Supervision</h3>
            </div>
            <!-- Actions -->
            <div class="flex flex-col sm:flex-row space-y-2 sm:space-y-0 sm:space-x-3">
                <!-- Refresh Button -->
                <button onclick="location.reload()" 
                        class="flex items-center justify-center px-4 py-2 text-sm font-medium text-bulsu-dark-maroon bg-bulsu-light-gold hover:bg-bulsu-gold rounded-md transition-colors">
                    <i class="fas fa-sync-alt mr-2"></i>
                    Refresh
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
</div>

                    <div class="overflow-x-auto">
                        <?php if (!empty($students_data)): ?>
                        <table class="min-w-full divide-y divide-gray-200">
                            <thead class="bg-gray-50">
                                <tr>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Student</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Position</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Hours Progress</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Attendance</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Tasks</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Evaluation</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Status</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Actions</th>
                                </tr>
                            </thead>
                            <tbody class="bg-white divide-y divide-gray-200">
                                <?php foreach ($students_data as $student): ?>
                                <?php 
                                $progress_percentage = $student['required_hours'] > 0 ? ($student['completed_hours'] / $student['required_hours']) * 100 : 0;
                                $progress_percentage = min(100, round($progress_percentage, 1));
                                $is_completed = $student['eligible_for_certificate'] == 1;
                                ?>
                                <tr class="hover:bg-gray-50 student-row <?php echo $is_completed ? 'completed-row' : ''; ?>" 
                                    data-student-name="<?php echo strtolower($student['first_name'] . ' ' . $student['last_name']); ?>"
                                    data-student-id="<?php echo strtolower($student['student_id']); ?>"
                                    data-position="<?php echo strtolower($student['position']); ?>">
                                    <td class="px-6 py-4">
                                        <div class="flex items-center">
                                            <div class="flex-shrink-0 w-12 h-12 bg-gradient-to-r from-blue-500 to-purple-600 rounded-full flex items-center justify-center text-white font-semibold text-sm student-avatar">
                                                <?php if (!empty($student['profile_picture']) && file_exists($student['profile_picture'])): ?>
                                                    <img src="<?php echo htmlspecialchars($student['profile_picture']); ?>" alt="Profile Picture" class="rounded-full">
                                                <?php else: ?>
                                                    <?php echo strtoupper(substr($student['first_name'], 0, 1) . substr($student['last_name'], 0, 1)); ?>
                                                <?php endif; ?>
                                            </div>
                                            <div class="ml-4">
                                                <div class="text-sm font-medium text-gray-900">
                                                    <?php echo htmlspecialchars($student['first_name'] . ' ' . ($student['middle_name'] ? $student['middle_name'] . ' ' : '') . $student['last_name']); ?>
                                                </div>
                                                <div class="text-sm text-gray-500">
                                                    ID: <?php echo htmlspecialchars($student['student_id']); ?>
                                                </div>
                                                <div class="text-xs text-gray-500">
                                                    <?php echo htmlspecialchars($student['program'] . ' - ' . $student['year_level']); ?>
                                                </div>
                                            </div>
                                        </div>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                        <?php echo htmlspecialchars($student['position']); ?>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <div class="text-sm text-gray-900 mb-1">
                                            <?php echo number_format($student['completed_hours']); ?>/<?php echo number_format($student['required_hours']); ?> hrs
                                        </div>
                                        <div class="w-full bg-gray-200 rounded-full h-2">
                                            <div class="bg-gradient-to-r <?php echo $is_completed ? 'from-green-500 to-green-600' : 'from-blue-500 to-blue-600'; ?> h-2 rounded-full progress-bar" 
                                                 style="width: <?php echo $progress_percentage; ?>%"></div>
                                        </div>
                                        <div class="text-xs text-gray-500 mt-1"><?php echo $progress_percentage; ?>%</div>
                                        <?php if ($is_completed): ?>
                                        <div class="mt-1">
                                            <span class="inline-flex items-center px-2 py-1 rounded-full text-xs font-medium bg-green-100 text-green-800">
                                                <i class="fas fa-check-circle mr-1"></i>
                                                Completed!
                                            </span>
                                        </div>
                                        <?php endif; ?>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <div class="text-sm text-gray-900">
                                            <?php echo $student['present_days']; ?> Present
                                        </div>
                                        <div class="text-xs text-gray-500">
                                            <?php echo $student['absent_days']; ?> Absent / <?php echo $student['total_attendance_days']; ?> Total
                                        </div>
                                        <?php if ($student['total_attendance_days'] > 0): ?>
                                        <div class="text-xs text-gray-500">
                                            <?php echo round(($student['present_days'] / $student['total_attendance_days']) * 100, 1); ?>% Rate
                                        </div>
                                        <?php endif; ?>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <div class="text-sm text-gray-900">
                                            <?php echo $student['completed_tasks']; ?>/<?php echo $student['total_tasks']; ?> Tasks
                                        </div>
                                        <?php if ($student['total_tasks'] > 0): ?>
                                        <div class="text-xs text-gray-500">
                                            <?php echo round(($student['completed_tasks'] / $student['total_tasks']) * 100, 1); ?>% Complete
                                        </div>
                                        <?php else: ?>
                                        <div class="text-xs text-gray-500">No tasks assigned</div>
                                        <?php endif; ?>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <?php if ($student['latest_evaluation']): ?>
                                        <div class="flex items-center">
                                            <div class="flex text-yellow-400 mr-2">
                                                <?php
                                                $rating = round($student['latest_evaluation']);
                                                for ($i = 1; $i <= 5; $i++):
                                                ?>
                                                    <i class="fas fa-star<?php echo $i <= $rating ? '' : ' opacity-30'; ?> text-xs"></i>
                                                <?php endfor; ?>
                                            </div>
                                            <span class="text-sm text-gray-600"><?php echo number_format($student['latest_evaluation'], 1); ?></span>
                                        </div>
                                        <?php else: ?>
                                        <span class="text-xs text-gray-500">Not evaluated</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium <?php 
                                            if ($is_completed) {
                                                echo 'bg-green-100 text-green-800';
                                            } else {
                                                echo $student['deployment_status'] === 'Active' ? 'bg-blue-100 text-blue-800' : 
                                                    ($student['deployment_status'] === 'Completed' ? 'bg-green-100 text-green-800' : 
                                                    ($student['deployment_status'] === 'Inactive' ? 'bg-red-100 text-red-800' : 'bg-gray-100 text-gray-800')); 
                                            }
                                        ?>">
                                            <?php 
                                            if ($is_completed) {
                                                echo 'Completed';
                                            } else {
                                                echo $student['deployment_status'];
                                            }
                                            ?>
                                        </span>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm font-medium">
                                        <div class="flex items-center space-x-3">
                                            <a href="?view_student=<?php echo $student['id']; ?>" 
                                               class="inline-flex items-center px-3 py-2 text-sm font-medium text-blue-600 bg-blue-50 border border-blue-200 rounded-md hover:bg-blue-100 hover:text-blue-700 transition-colors">
                                                <i class="fas fa-eye mr-2"></i>
                                                View
                                            </a>
                                        </div>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                        <?php else: ?>
                        <div class="text-center py-12">
                            <i class="fas fa-users text-gray-400 text-5xl mb-4"></i>
                            <h3 class="text-lg font-medium text-gray-900 mb-2">No Students Found</h3>
                            <p class="text-gray-600">You don't have any students assigned to you yet.</p>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- JavaScript -->
    <script>
        // Mobile sidebar toggle
        const mobileMenuBtn = document.getElementById('mobileMenuBtn');
        const sidebar = document.getElementById('sidebar');
        const sidebarOverlay = document.getElementById('sidebarOverlay');
        const closeSidebar = document.getElementById('closeSidebar');

        function toggleSidebar() {
            sidebar.classList.toggle('-translate-x-full');
            sidebarOverlay.classList.toggle('hidden');
        }

        mobileMenuBtn?.addEventListener('click', toggleSidebar);
        closeSidebar?.addEventListener('click', toggleSidebar);
        sidebarOverlay?.addEventListener('click', toggleSidebar);

        // Profile dropdown
        const profileBtn = document.getElementById('profileBtn');
        const profileDropdown = document.getElementById('profileDropdown');

        profileBtn?.addEventListener('click', function(e) {
            e.stopPropagation();
            profileDropdown.classList.toggle('hidden');
        });

        document.addEventListener('click', function() {
            profileDropdown?.classList.add('hidden');
        });

        // Animate progress bars
        window.addEventListener('load', function() {
            const progressBars = document.querySelectorAll('.progress-bar');
            progressBars.forEach(function(bar) {
                const progress = bar.getAttribute('data-progress');
                if (progress) {
                    setTimeout(function() {
                        bar.style.width = progress + '%';
                        bar.classList.add('animate');
                    }, 300);
                }
            });
        });

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

        // Certificate generation function
        function generateCertificate(studentId) {
            if (confirm('Generate completion certificate for this student?')) {
                window.location.href = '?generate_certificate=' + studentId;
            }
        }

        // Logout confirmation
        function confirmLogout() {
            return confirm('Are you sure you want to logout?');
        }

        // Auto-refresh for real-time updates (optional)
        // Uncomment the following lines if you want auto-refresh every 5 minutes
        /*
        setInterval(function() {
            if (!document.hidden) {
                location.reload();
            }
        }, 300000); // 5 minutes
        */
    </script>
</body>
</html>