<?php
include('connect.php');
session_start();

// Prevent caching
header("Cache-Control: no-cache, no-store, must-revalidate");
header("Pragma: no-cache");
header("Expires: 0");

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

// Set timezone
date_default_timezone_set('Asia/Manila');
$conn->query("SET time_zone = '+08:00'");

// Handle AJAX requests
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    
    $action = $_POST['action'] ?? '';
    
    if ($action === 'get_student_details') {
        $student_id = intval($_POST['student_id']);
        
        try {
            // Get student details with deployment info
            $stmt = $conn->prepare("
                SELECT s.*, sd.deployment_id, sd.company_name, sd.position, 
                       sd.start_date, sd.end_date, sd.required_hours, sd.completed_hours,
                       sd.supervisor_name, sd.status as deployment_status,
                       sd.company_address, sd.supervisor_position, sd.supervisor_email, sd.supervisor_phone
                FROM students s
                JOIN student_deployments sd ON s.id = sd.student_id
                WHERE s.id = ? AND sd.supervisor_id = ? AND sd.status = 'Active'
            ");
            $stmt->bind_param("ii", $student_id, $supervisor_id);
            $stmt->execute();
            $student = $stmt->get_result()->fetch_assoc();
            
            if (!$student) {
                throw new Exception('Student not found or not under your supervision');
            }
            
            // Get attendance summary for this student
            $summary_stmt = $conn->prepare("
                SELECT 
                    COUNT(*) as total_days,
                    COUNT(CASE WHEN status = 'Present' OR status = 'Late' THEN 1 END) as present_days,
                    COUNT(CASE WHEN status = 'Absent' THEN 1 END) as absent_days,
                    COUNT(CASE WHEN status = 'Late' THEN 1 END) as late_days,
                    COUNT(CASE WHEN status = 'Half Day' THEN 1 END) as half_days,
                    COALESCE(SUM(total_hours), 0) as total_hours_worked,
                    COALESCE(AVG(total_hours), 0) as avg_hours_per_day,
                    COUNT(CASE WHEN time_in > '08:30:00' THEN 1 END) as late_arrivals,
                    AVG(CASE WHEN facial_confidence IS NOT NULL THEN facial_confidence END) as avg_confidence,
                    COUNT(CASE WHEN attendance_method = 'facial' THEN 1 END) as ai_detections,
                    COUNT(CASE WHEN attendance_method = 'manual' THEN 1 END) as manual_entries
                FROM student_attendance 
                WHERE student_id = ? AND deployment_id = ?
            ");
            $summary_stmt->bind_param("ii", $student_id, $student['deployment_id']);
            $summary_stmt->execute();
            $summary = $summary_stmt->get_result()->fetch_assoc();
            
            // Get recent attendance records
            $recent_stmt = $conn->prepare("
                SELECT date, time_in, time_out, total_hours, status, 
                       attendance_method, facial_confidence, notes,
                       CASE 
                           WHEN time_in > '08:30:00' THEN 'Late'
                           WHEN time_in IS NULL THEN 'Absent'
                           ELSE 'On Time'
                       END as punctuality_status
                FROM student_attendance 
                WHERE student_id = ? AND deployment_id = ?
                ORDER BY date DESC LIMIT 15
            ");
            $recent_stmt->bind_param("ii", $student_id, $student['deployment_id']);
            $recent_stmt->execute();
            $recent_attendance = $recent_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            
            // Get monthly attendance breakdown
            $monthly_stmt = $conn->prepare("
                SELECT 
                    MONTH(date) as month,
                    YEAR(date) as year,
                    COUNT(*) as total_days,
                    COUNT(CASE WHEN status = 'Present' OR status = 'Late' THEN 1 END) as present_days,
                    SUM(total_hours) as month_hours
                FROM student_attendance 
                WHERE student_id = ? AND deployment_id = ?
                GROUP BY YEAR(date), MONTH(date)
                ORDER BY year DESC, month DESC
                LIMIT 6
            ");
            $monthly_stmt->bind_param("ii", $student_id, $student['deployment_id']);
            $monthly_stmt->execute();
            $monthly_data = $monthly_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            
            echo json_encode([
                'success' => true,
                'student' => $student,
                'summary' => $summary,
                'recent_attendance' => $recent_attendance,
                'monthly_data' => $monthly_data
            ]);
            
        } catch (Exception $e) {
            echo json_encode([
                'success' => false,
                'message' => $e->getMessage()
            ]);
        }
        exit;
    }
    
    // NEW HANDLER FOR PRINT ALL RECORDS
    if ($action === 'get_all_records_for_print') {
        // Get filter parameters from POST
        $date_from = $_POST['date_from'] ?? date('Y-m-01');
        $date_to = $_POST['date_to'] ?? date('Y-m-d');
        $student_filter = $_POST['student'] ?? '';
        $status_filter = $_POST['status'] ?? '';
        $search = $_POST['search'] ?? '';
        
        try {
            // Build WHERE conditions for filtering (same logic as main query)
            $where_conditions = ["sd.supervisor_id = ?"];
            $params = [$supervisor_id];
            $param_types = "i";
            
            if (!empty($student_filter)) {
                $where_conditions[] = "s.id = ?";
                $params[] = $student_filter;
                $param_types .= "i";
            }
            
            if (!empty($search)) {
                $where_conditions[] = "(s.first_name LIKE ? OR s.last_name LIKE ? OR s.student_id LIKE ?)";
                $search_param = "%$search%";
                $params[] = $search_param;
                $params[] = $search_param;
                $params[] = $search_param;
                $param_types .= "sss";
            }
            
            $attendance_where = [];
            if (!empty($status_filter)) {
                $attendance_where[] = "sa.status = ?";
                $params[] = $status_filter;
                $param_types .= "s";
            }
            
            // Date filter for attendance
            $attendance_where[] = "sa.date BETWEEN ? AND ?";
            $params[] = $date_from;
            $params[] = $date_to;
            $param_types .= "ss";
            
            $where_clause = "WHERE " . implode(" AND ", $where_conditions);
            $attendance_where_clause = !empty($attendance_where) ? "AND " . implode(" AND ", $attendance_where) : "";
            
            // Get ALL records (no pagination limit)
            $query = "
                SELECT sa.*, s.first_name, s.last_name, s.middle_name, s.student_id, 
                       s.profile_picture, s.program, s.year_level,
                       sd.position, sd.company_name, sd.deployment_id,
                       CASE 
                           WHEN sa.time_in > '08:30:00' THEN 'Late'
                           WHEN sa.time_in IS NULL THEN 'Absent'
                           ELSE 'On Time'
                       END as punctuality_status,
                       CONCAT(s.first_name, ' ', COALESCE(s.middle_name, ''), ' ', s.last_name) as full_name
                FROM student_attendance sa
                JOIN students s ON sa.student_id = s.id
                JOIN student_deployments sd ON sa.deployment_id = sd.deployment_id
                $where_clause
                $attendance_where_clause
                ORDER BY sa.date DESC, sa.created_at DESC
            ";
            
            $stmt = $conn->prepare($query);
            if (!empty($params)) {
                $stmt->bind_param($param_types, ...$params);
            }
            $stmt->execute();
            $all_records = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            
            // Get statistics for ALL records
            $stats_query = "
                SELECT 
                    COUNT(*) as total_records,
                    COUNT(CASE WHEN sa.status = 'Present' OR sa.status = 'Late' THEN 1 END) as total_present_days,
                    COUNT(CASE WHEN sa.status = 'Absent' THEN 1 END) as total_absent_days,
                    COUNT(CASE WHEN sa.status = 'Late' THEN 1 END) as total_late_days,
                    COUNT(CASE WHEN sa.time_in > '08:30:00' THEN 1 END) as total_late_arrivals,
                    COALESCE(SUM(sa.total_hours), 0) as total_hours_sum
                FROM student_attendance sa
                JOIN students s ON sa.student_id = s.id
                JOIN student_deployments sd ON sa.deployment_id = sd.deployment_id
                $where_clause
                $attendance_where_clause
            ";
            
            $stats_stmt = $conn->prepare($stats_query);
            if (!empty($params)) {
                $stats_stmt->bind_param($param_types, ...$params);
            }
            $stats_stmt->execute();
            $stats = $stats_stmt->get_result()->fetch_assoc();
            
            // Get selected student name for filter info
            $selected_student_name = '';
            if (!empty($student_filter)) {
                $student_name_stmt = $conn->prepare("SELECT CONCAT(first_name, ' ', last_name, ' (', student_id, ')') as full_name FROM students WHERE id = ?");
                $student_name_stmt->bind_param("i", $student_filter);
                $student_name_stmt->execute();
                $student_name_result = $student_name_stmt->get_result()->fetch_assoc();
                $selected_student_name = $student_name_result['full_name'] ?? '';
            }
            
            // Prepare filter info for the print document
            $filter_info = [
                'date_from' => !empty($date_from) ? $date_from : null,
                'date_to' => !empty($date_to) ? $date_to : null,
                'student_name' => $selected_student_name,
                'status' => $status_filter,
                'search' => $search
            ];
            
            echo json_encode([
                'success' => true,
                'records' => $all_records,
                'stats' => $stats,
                'filter_info' => $filter_info,
                'total_count' => count($all_records)
            ]);
            
        } catch (Exception $e) {
            echo json_encode([
                'success' => false,
                'message' => 'Error fetching records: ' . $e->getMessage()
            ]);
        }
        exit;
    }
}

// Get filter parameters
$date_from = $_GET['date_from'] ?? date('Y-m-01');
$date_to = $_GET['date_to'] ?? date('Y-m-d');
$student_filter = $_GET['student'] ?? '';
$status_filter = $_GET['status'] ?? '';
$search = $_GET['search'] ?? '';

// Pagination
$page = max(1, intval($_GET['page'] ?? 1));
$per_page = 10;
$offset = ($page - 1) * $per_page;

try {
    // Build base WHERE conditions for filtering
    $where_conditions = ["sd.supervisor_id = ?"];
    $params = [$supervisor_id];
    $param_types = "i";
    
    if (!empty($student_filter)) {
        $where_conditions[] = "s.id = ?";
        $params[] = $student_filter;
        $param_types .= "i";
    }
    
    if (!empty($search)) {
        $where_conditions[] = "(s.first_name LIKE ? OR s.last_name LIKE ? OR s.student_id LIKE ?)";
        $search_param = "%$search%";
        $params[] = $search_param;
        $params[] = $search_param;
        $params[] = $search_param;
        $param_types .= "sss";
    }
    
    $attendance_where = [];
    if (!empty($status_filter)) {
        $attendance_where[] = "sa.status = ?";
        $params[] = $status_filter;
        $param_types .= "s";
    }
    
    // Date filter for attendance
    $attendance_where[] = "sa.date BETWEEN ? AND ?";
    $params[] = $date_from;
    $params[] = $date_to;
    $param_types .= "ss";
    
    $where_clause = "WHERE " . implode(" AND ", $where_conditions);
    $attendance_where_clause = !empty($attendance_where) ? "AND " . implode(" AND ", $attendance_where) : "";
    
    // First, get TOTAL statistics across ALL records (not just current page)
    $stats_query = "
        SELECT 
            COUNT(*) as total_records,
            COUNT(CASE WHEN sa.status = 'Present' OR sa.status = 'Late' THEN 1 END) as total_present_days,
            COUNT(CASE WHEN sa.status = 'Absent' THEN 1 END) as total_absent_days,
            COUNT(CASE WHEN sa.status = 'Late' THEN 1 END) as total_late_days,
            COUNT(CASE WHEN sa.time_in > '08:30:00' THEN 1 END) as total_late_arrivals,
            COALESCE(SUM(sa.total_hours), 0) as total_hours_sum
        FROM student_attendance sa
        JOIN students s ON sa.student_id = s.id
        JOIN student_deployments sd ON sa.deployment_id = sd.deployment_id
        $where_clause
        $attendance_where_clause
    ";
    
    $stats_stmt = $conn->prepare($stats_query);
    if (!empty($params)) {
        $stats_stmt->bind_param($param_types, ...$params);
    }
    $stats_stmt->execute();
    $total_stats = $stats_stmt->get_result()->fetch_assoc();
    
    // Main query for attendance records (paginated)
    $query = "
        SELECT sa.*, s.first_name, s.last_name, s.middle_name, s.student_id, 
               s.profile_picture, s.program, s.year_level,
               sd.position, sd.company_name, sd.deployment_id,
               CASE 
                   WHEN sa.time_in > '08:30:00' THEN 'Late'
                   WHEN sa.time_in IS NULL THEN 'Absent'
                   ELSE 'On Time'
               END as punctuality_status,
               CONCAT(s.first_name, ' ', COALESCE(s.middle_name, ''), ' ', s.last_name) as full_name
        FROM student_attendance sa
        JOIN students s ON sa.student_id = s.id
        JOIN student_deployments sd ON sa.deployment_id = sd.deployment_id
        $where_clause
        $attendance_where_clause
        ORDER BY sa.date DESC, sa.created_at DESC
        LIMIT $per_page OFFSET $offset
    ";
    
    $stmt = $conn->prepare($query);
    if (!empty($params)) {
        $stmt->bind_param($param_types, ...$params);
    }
    $stmt->execute();
    $attendance_records = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    
    // Get total count for pagination
    $total_records = $total_stats['total_records'];
    $total_pages = ceil($total_records / $per_page);
    
    // Get students list for filter dropdown
    $students_query = "
        SELECT s.id, s.first_name, s.last_name, s.student_id
        FROM students s
        JOIN student_deployments sd ON s.id = sd.student_id
        WHERE sd.supervisor_id = ? AND sd.status = 'Active'
        ORDER BY s.first_name, s.last_name
    ";
    $students_stmt = $conn->prepare($students_query);
    $students_stmt->bind_param("i", $supervisor_id);
    $students_stmt->execute();
    $students_list = $students_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    
} catch (Exception $e) {
    $error_message = "Error fetching time records: " . $e->getMessage();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>OnTheJob Tracker - Student Time Records</title>
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

        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }

        /* Print styles */
        @media print {
            body * {
                visibility: hidden;
            }
            
            .print-area, .print-area * {
                visibility: visible;
            }
            
            .print-area {
                position: absolute;
                left: 0;
                top: 0;
                width: 100%;
            }
            
            .no-print {
                display: none !important;
            }
            
            .print-header {
                display: block !important;
                text-align: center;
                margin-bottom: 20px;
                border-bottom: 2px solid #000;
                padding-bottom: 10px;
            }
            
            table {
                border-collapse: collapse !important;
                width: 100% !important;
            }
            
            th, td {
                border: 1px solid #000 !important;
                padding: 8px !important;
                font-size: 12px !important;
            }
            
            th {
                background-color: #f0f0f0 !important;
            }
        }
    </style>
</head>
<body class="bg-gray-50">
    <!-- Mobile Sidebar Overlay -->
    <div id="sidebarOverlay" class="fixed inset-0 bg-black bg-opacity-50 z-40 lg:hidden hidden sidebar-overlay"></div>

    <!-- Sidebar -->
   <div id="sidebar" class="fixed left-0 top-0 h-full w-64 bg-gradient-to-b from-bulsu-maroon to-bulsu-dark-maroon shadow-lg z-50 transform -translate-x-full lg:translate-x-0 transition-transform duration-300 ease-in-out sidebar no-print">
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
            <a href="CompanyTasks.php" class="nnav-item flex items-center px-3 py-2 text-sm font-medium text-bulsu-light-gold hover:text-white hover:bg-bulsu-gold hover:bg-opacity-20 rounded-md transition-all duration-200">
                <i class="fas fa-tasks mr-3"></i>
                Tasks
            </a>
            <a href="CompanyTimeRecord.php" class="nav-item flex items-center px-3 py-2 text-sm font-medium text-white bg-bulsu-gold bg-opacity-20 border border-bulsu-gold border-opacity-30 rounded-md">
                <i class="fas fa-clock mr-3 text-bulsu-gold"></i>
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
        <div class="bg-white shadow-sm border-b border-gray-200 no-print">
            <div class="flex items-center justify-between px-4 sm:px-6 py-4">
                <!-- Mobile Menu Button -->
                <button id="mobileMenuBtn" class="lg:hidden p-2 rounded-md text-gray-500 hover:text-gray-900 hover:bg-gray-100">
                    <i class="fas fa-bars text-xl"></i>
                </button>

                <!-- Header Title -->
                <div class="flex-1 lg:ml-0 ml-4">
                    <h1 class="text-xl sm:text-2xl font-bold text-gray-900">Student Time Records</h1>
                    <p class="text-sm sm:text-base text-gray-500 hidden sm:block">Monitor and manage attendance records of your assigned students</p>
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
        <div class="p-4 sm:p-6 lg:p-8 print-area">
            <!-- Print Header (Hidden on screen, visible on print) -->
            <div class="print-header" style="display: none;">
                <h1 class="text-2xl font-bold">OnTheJob Tracker - Student Attendance Records</h1>
                <p class="text-lg"><?php echo htmlspecialchars($company_name); ?></p>
                <p class="text-md">Supervisor: <?php echo htmlspecialchars($supervisor_name); ?></p>
                <p class="text-sm">Generated on: <?php echo date('F d, Y g:i A'); ?></p>
                <?php if (!empty($date_from) || !empty($date_to)): ?>
                    <p class="text-sm">Period: <?php echo date('F d, Y', strtotime($date_from)); ?> to <?php echo date('F d, Y', strtotime($date_to)); ?></p>
                <?php endif; ?>
            </div>

            <!-- Error Message Display -->
            <?php if (isset($error_message)): ?>
                <div class="mb-6 p-4 bg-red-50 border border-red-200 rounded-lg no-print">
                    <div class="flex items-start">
                        <i class="fas fa-exclamation-triangle text-red-600 mt-1 mr-3"></i>
                        <p class="text-red-700"><?php echo htmlspecialchars($error_message); ?></p>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Filter Section -->
            <div class="bg-white rounded-lg shadow-sm border border-gray-200 mb-6 no-print">
                <div class="p-6">
                    <form method="GET" id="filterForm" class="space-y-6">
                        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-5 gap-4">
                            <div>
                                <label for="date_from" class="block text-sm font-medium text-gray-700 mb-2">Date From</label>
                                <input type="date" id="date_from" name="date_from" 
                                       class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500" 
                                       value="<?php echo htmlspecialchars($date_from); ?>">
                            </div>
                            
                            <div>
                                <label for="date_to" class="block text-sm font-medium text-gray-700 mb-2">Date To</label>
                                <input type="date" id="date_to" name="date_to" 
                                       class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500" 
                                       value="<?php echo htmlspecialchars($date_to); ?>">
                            </div>
                            
                            <div>
                                <label for="student" class="block text-sm font-medium text-gray-700 mb-2">Student</label>
                                <select id="student" name="student" 
                                        class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                                    <option value="">All Students</option>
                                    <?php foreach ($students_list as $student): ?>
                                        <option value="<?php echo $student['id']; ?>" <?php echo ($student_filter == $student['id']) ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($student['first_name'] . ' ' . $student['last_name'] . ' (' . $student['student_id'] . ')'); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                           <div>
    <label for="status" class="block text-sm font-medium text-gray-700 mb-2">Status</label>
    <select id="status" name="status" 
            class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
        <option value="">All Status</option>
        <option value="Present" <?php echo ($status_filter == 'Present') ? 'selected' : ''; ?>>Present</option>
        <option value="Absent" <?php echo ($status_filter == 'Absent') ? 'selected' : ''; ?>>Absent</option>
        <option value="Late" <?php echo ($status_filter == 'Late') ? 'selected' : ''; ?>>Late</option>
        <option value="Half Day" <?php echo ($status_filter == 'Half Day') ? 'selected' : ''; ?>>Half Day</option>
        <option value="Late Arrival" <?php echo ($status_filter == 'Late Arrival') ? 'selected' : ''; ?>>Late Arrivals Only</option>
        <option value="No Show" <?php echo ($status_filter == 'No Show') ? 'selected' : ''; ?>>No Time In/Out</option>
    </select>
</div>
                            
                            <div>
                                <label for="search" class="block text-sm font-medium text-gray-700 mb-2">Search</label>
                                <input type="text" id="search" name="search" 
                                       class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500" 
                                       placeholder="Name or Student ID..." value="<?php echo htmlspecialchars($search); ?>">
                            </div>
                        </div>
                        
                      <div class="flex flex-col sm:flex-row gap-3">
    <button type="submit" class="px-4 py-2 bg-blue-600 text-white rounded-md hover:bg-blue-700 transition-colors duration-200 flex items-center justify-center">
        <i class="fas fa-filter mr-2"></i>
        Apply Filters
    </button>
    
    <button type="button" onclick="clearFilters()" class="px-4 py-2 bg-gray-500 text-white rounded-md hover:bg-gray-600 transition-colors duration-200 flex items-center justify-center">
        <i class="fas fa-times mr-2"></i>
        Clear
    </button>
    
    <button type="button" onclick="printRecords()" class="px-4 py-2 bg-purple-600 text-white rounded-md hover:bg-purple-700 transition-colors duration-200 flex items-center justify-center">
        <i class="fas fa-print mr-2"></i>
        Print
    </button>
</div>
                    </form>
                </div>
            </div>

            <!-- Statistics Cards -->
            <!-- Statistics Cards -->
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-5 gap-6 mb-6 no-print">
                <div class="bg-white rounded-lg shadow-sm border border-gray-200 p-6">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-sm font-medium text-gray-500">Total Records</p>
                            <p class="text-2xl font-bold text-gray-900"><?php echo number_format($total_stats['total_records']); ?></p>
                        </div>
                        <div class="w-12 h-12 bg-blue-100 rounded-lg flex items-center justify-center">
                            <i class="fas fa-clipboard-list text-blue-600 text-xl"></i>
                        </div>
                    </div>
                </div>

                <div class="bg-white rounded-lg shadow-sm border border-gray-200 p-6">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-sm font-medium text-gray-500">Present Days</p>
                            <p class="text-2xl font-bold text-green-600"><?php echo number_format($total_stats['total_present_days']); ?></p>
                        </div>
                        <div class="w-12 h-12 bg-green-100 rounded-lg flex items-center justify-center">
                            <i class="fas fa-check-circle text-green-600 text-xl"></i>
                        </div>
                    </div>
                </div>

                <div class="bg-white rounded-lg shadow-sm border border-gray-200 p-6">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-sm font-medium text-gray-500">Absent Days</p>
                            <p class="text-2xl font-bold text-red-600"><?php echo number_format($total_stats['total_absent_days']); ?></p>
                        </div>
                        <div class="w-12 h-12 bg-red-100 rounded-lg flex items-center justify-center">
                            <i class="fas fa-times-circle text-red-600 text-xl"></i>
                        </div>
                    </div>
                </div>

                <div class="bg-white rounded-lg shadow-sm border border-gray-200 p-6">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-sm font-medium text-gray-500">Late Arrivals</p>
                            <p class="text-2xl font-bold text-yellow-600"><?php echo number_format($total_stats['total_late_arrivals']); ?></p>
                        </div>
                        <div class="w-12 h-12 bg-yellow-100 rounded-lg flex items-center justify-center">
                            <i class="fas fa-clock text-yellow-600 text-xl"></i>
                        </div>
                    </div>
                </div>

                <div class="bg-white rounded-lg shadow-sm border border-gray-200 p-6">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-sm font-medium text-gray-500">Total Hours</p>
                            <p class="text-2xl font-bold text-purple-600"><?php echo number_format($total_stats['total_hours_sum'], 1); ?></p>
                        </div>
                        <div class="w-12 h-12 bg-purple-100 rounded-lg flex items-center justify-center">
                            <i class="fas fa-business-time text-purple-600 text-xl"></i>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Records Table -->
            <div class="bg-white rounded-lg shadow-sm border border-gray-200">
                <div class="p-6 border-b border-gray-200">
                    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
                        <h3 class="text-lg font-semibold text-gray-900">Attendance Records</h3>
                        <div class="flex items-center space-x-2 text-sm text-gray-500">
                            <span>Showing <?php echo number_format(min($per_page, count($attendance_records))); ?> of <?php echo number_format($total_records); ?> records</span>
                        </div>
                    </div>
                </div>

                <div class="overflow-x-auto">
                    <table class="w-full">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Student</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Date</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Time In</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Time Out</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Hours</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Method</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider no-print">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="bg-white divide-y divide-gray-200">
                            <?php if (empty($attendance_records)): ?>
                                <tr>
                                    <td colspan="8" class="px-6 py-12 text-center">
                                        <div class="flex flex-col items-center">
                                            <i class="fas fa-calendar-times text-gray-400 text-4xl mb-4"></i>
                                            <p class="text-gray-500 text-lg">No attendance records found</p>
                                            <p class="text-gray-400 text-sm">Try adjusting your filters or date range</p>
                                        </div>
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($attendance_records as $record): ?>
                                    <tr class="hover:bg-gray-50">
                                        <td class="px-6 py-4 whitespace-nowrap">
                                            <div class="flex items-center">
                                                <div class="flex-shrink-0 h-10 w-10">
                                                    <?php if (!empty($record['profile_picture']) && file_exists($record['profile_picture'])): ?>
                                                        <img class="h-10 w-10 rounded-full object-cover" src="<?php echo htmlspecialchars($record['profile_picture']); ?>" alt="">
                                                    <?php else: ?>
                                                        <div class="h-10 w-10 rounded-full bg-gradient-to-r from-blue-500 to-purple-600 flex items-center justify-center text-white font-semibold text-sm">
                                                            <?php echo strtoupper(substr($record['first_name'], 0, 1) . substr($record['last_name'], 0, 1)); ?>
                                                        </div>
                                                    <?php endif; ?>
                                                </div>
                                                <div class="ml-4">
                                                    <div class="text-sm font-medium text-gray-900">
                                                        <?php echo htmlspecialchars($record['full_name']); ?>
                                                    </div>
                                                    <div class="text-sm text-gray-500">
                                                        <?php echo htmlspecialchars($record['student_id']); ?>
                                                        <?php if (!empty($record['program'])): ?>
                                                            • <?php echo htmlspecialchars($record['program']); ?>
                                                        <?php endif; ?>
                                                    </div>
                                                </div>
                                            </div>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                            <?php echo date('M d, Y', strtotime($record['date'])); ?>
                                            <div class="text-xs text-gray-500">
                                                <?php echo date('l', strtotime($record['date'])); ?>
                                            </div>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap">
                                            <?php if (!empty($record['time_in'])): ?>
                                                <div class="text-sm text-gray-900">
                                                    <?php echo date('g:i A', strtotime($record['time_in'])); ?>
                                                </div>
                                                <?php if ($record['punctuality_status'] == 'Late'): ?>
                                                    <div class="text-xs text-red-500">
                                                        <i class="fas fa-clock mr-1"></i>Late
                                                    </div>
                                                <?php endif; ?>
                                            <?php else: ?>
                                                <span class="text-gray-400">-</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                            <?php echo !empty($record['time_out']) ? date('g:i A', strtotime($record['time_out'])) : '-'; ?>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                            <?php echo $record['total_hours'] ? number_format($record['total_hours'], 2) . ' hrs' : '-'; ?>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap">
                                            <?php
                                            $status_classes = [
                                                'Present' => 'bg-green-100 text-green-800',
                                                'Absent' => 'bg-red-100 text-red-800',
                                                'Late' => 'bg-yellow-100 text-yellow-800',
                                                'Half Day' => 'bg-blue-100 text-blue-800'
                                            ];
                                            $status_class = $status_classes[$record['status']] ?? 'bg-gray-100 text-gray-800';
                                            ?>
                                            <span class="inline-flex px-2 py-1 text-xs font-semibold rounded-full <?php echo $status_class; ?>">
                                                <?php echo htmlspecialchars($record['status']); ?>
                                            </span>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap">
                                            <?php if ($record['attendance_method'] == 'facial'): ?>
                                                <div class="flex items-center">
                                                    <i class="fas fa-robot text-blue-600 mr-1"></i>
                                                    <span class="text-xs text-blue-600">AI Detection</span>
                                                </div>
                                                <?php if ($record['facial_confidence']): ?>
                                                    <div class="text-xs text-gray-500">
                                                        <?php echo number_format($record['facial_confidence'] * 100, 1); ?>% confidence
                                                    </div>
                                                <?php endif; ?>
                                            <?php else: ?>
                                                <div class="flex items-center">
                                                    <i class="fas fa-user text-gray-600 mr-1"></i>
                                                    <span class="text-xs text-gray-600">Manual</span>
                                                </div>
                                            <?php endif; ?>
                                        </td>

                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <!-- Enhanced Pagination -->
                <?php if ($total_pages > 1): ?>
                    <div class="bg-gray-50 px-6 py-4 border-t border-gray-200 no-print">
                        <div class="flex flex-col sm:flex-row items-center justify-between space-y-4 sm:space-y-0">
                            <div class="text-sm text-gray-700">
                                Showing page <?php echo $page; ?> of <?php echo $total_pages; ?> 
                                (<?php echo number_format(($page - 1) * $per_page + 1); ?>-<?php echo number_format(min($page * $per_page, $total_records)); ?> of <?php echo number_format($total_records); ?> records)
                            </div>
                            
                            <div class="flex items-center space-x-1">
                                <!-- First Page -->
                                <?php if ($page > 1): ?>
                                    <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => 1])); ?>" 
                                       class="px-3 py-2 text-sm bg-white border border-gray-300 rounded-md hover:bg-gray-50 transition-colors">
                                        <i class="fas fa-angle-double-left"></i>
                                    </a>
                                <?php endif; ?>
                                
                                <!-- Previous Page -->
                                <?php if ($page > 1): ?>
                                    <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page - 1])); ?>" 
                                       class="px-3 py-2 text-sm bg-white border border-gray-300 rounded-md hover:bg-gray-50 transition-colors">
                                        <i class="fas fa-angle-left"></i>
                                    </a>
                                <?php endif; ?>
                                
                                <!-- Page Numbers -->
                                <?php
                                $start_page = max(1, $page - 2);
                                $end_page = min($total_pages, $page + 2);
                                
                                if ($start_page > 1) {
                                    echo '<a href="?' . http_build_query(array_merge($_GET, ['page' => 1])) . '" class="px-3 py-2 text-sm bg-white text-gray-700 hover:bg-gray-50 border border-gray-300 rounded-md transition-colors">1</a>';
                                    if ($start_page > 2) {
                                        echo '<span class="px-2 py-2 text-sm text-gray-500">...</span>';
                                    }
                                }
                                
                                for ($i = $start_page; $i <= $end_page; $i++):
                                ?>
                                    <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $i])); ?>" 
                                       class="px-3 py-2 text-sm <?php echo ($i == $page) ? 'bg-blue-600 text-white border-blue-600' : 'bg-white text-gray-700 hover:bg-gray-50 border-gray-300'; ?> border rounded-md transition-colors">
                                        <?php echo $i; ?>
                                    </a>
                                <?php 
                                endfor;
                                
                                if ($end_page < $total_pages) {
                                    if ($end_page < $total_pages - 1) {
                                        echo '<span class="px-2 py-2 text-sm text-gray-500">...</span>';
                                    }
                                    echo '<a href="?' . http_build_query(array_merge($_GET, ['page' => $total_pages])) . '" class="px-3 py-2 text-sm bg-white text-gray-700 hover:bg-gray-50 border border-gray-300 rounded-md transition-colors">' . $total_pages . '</a>';
                                }
                                ?>
                                
                                <!-- Next Page -->
                                <?php if ($page < $total_pages): ?>
                                    <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page + 1])); ?>" 
                                       class="px-3 py-2 text-sm bg-white border border-gray-300 rounded-md hover:bg-gray-50 transition-colors">
                                        <i class="fas fa-angle-right"></i>
                                    </a>
                                <?php endif; ?>
                                
                                <!-- Last Page -->
                                <?php if ($page < $total_pages): ?>
                                    <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $total_pages])); ?>" 
                                       class="px-3 py-2 text-sm bg-white border border-gray-300 rounded-md hover:bg-gray-50 transition-colors">
                                        <i class="fas fa-angle-double-right"></i>
                                    </a>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Student Details Modal -->
    <div id="studentModal" class="fixed inset-0 z-50 hidden">
        <div class="fixed inset-0 bg-black bg-opacity-50" onclick="closeStudentModal()"></div>
        <div class="fixed inset-0 flex items-center justify-center p-4">
            <div class="bg-white rounded-lg shadow-xl max-w-4xl w-full max-h-[90vh] overflow-y-auto">
                <div class="flex items-center justify-between p-6 border-b border-gray-200">
                    <h3 class="text-lg font-semibold text-gray-900">Student Details</h3>
                    <button onclick="closeStudentModal()" class="text-gray-400 hover:text-gray-600">
                        <i class="fas fa-times text-xl"></i>
                    </button>
                </div>
                <div id="studentModalContent" class="p-6">
                    <!-- Content will be loaded here -->
                </div>
            </div>
        </div>
    </div>

    <!-- Notes Modal -->
    <div id="notesModal" class="fixed inset-0 z-50 hidden">
        <div class="fixed inset-0 bg-black bg-opacity-50" onclick="closeNotesModal()"></div>
        <div class="fixed inset-0 flex items-center justify-center p-4">
            <div class="bg-white rounded-lg shadow-xl max-w-md w-full">
                <div class="flex items-center justify-between p-6 border-b border-gray-200">
                    <h3 class="text-lg font-semibold text-gray-900">Attendance Notes</h3>
                    <button onclick="closeNotesModal()" class="text-gray-400 hover:text-gray-600">
                        <i class="fas fa-times text-xl"></i>
                    </button>
                </div>
                <div class="p-6">
                    <p id="notesContent" class="text-gray-700"></p>
                </div>
            </div>
        </div>
    </div>

    <!-- JavaScript -->
    <script>
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

        // Profile dropdown
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

        // Filter functions
        function clearFilters() {
            document.getElementById('date_from').value = '';
            document.getElementById('date_to').value = '';
            document.getElementById('student').value = '';
            document.getElementById('status').value = '';
            document.getElementById('search').value = '';
            document.getElementById('filterForm').submit();
        }


        // Student details modal
        function viewStudentDetails(studentId) {
            document.getElementById('studentModalContent').innerHTML = '<div class="text-center py-8"><i class="fas fa-spinner fa-spin text-2xl text-gray-400"></i><p class="mt-2 text-gray-500">Loading...</p></div>';
            document.getElementById('studentModal').classList.remove('hidden');

            const formData = new FormData();
            formData.append('action', 'get_student_details');
            formData.append('student_id', studentId);

            fetch('CompanyTimeRecord.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    displayStudentDetails(data);
                } else {
                    document.getElementById('studentModalContent').innerHTML = 
                        '<div class="text-center py-8"><i class="fas fa-exclamation-triangle text-2xl text-red-500"></i><p class="mt-2 text-red-600">' + data.message + '</p></div>';
                }
            })
            .catch(error => {
                document.getElementById('studentModalContent').innerHTML = 
                    '<div class="text-center py-8"><i class="fas fa-exclamation-triangle text-2xl text-red-500"></i><p class="mt-2 text-red-600">Error loading student details</p></div>';
            });
        }

        function displayStudentDetails(data) {
            const student = data.student;
            const summary = data.summary;
            const recentAttendance = data.recent_attendance;

            const content = `
                <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
                    <!-- Student Info -->
                    <div class="lg:col-span-1">
                        <div class="bg-gray-50 rounded-lg p-6">
                            <div class="text-center mb-4">
                                ${student.profile_picture ? 
                                    `<img src="${student.profile_picture}" alt="Profile" class="w-20 h-20 rounded-full mx-auto object-cover">` :
                                    `<div class="w-20 h-20 rounded-full mx-auto bg-gradient-to-r from-blue-500 to-purple-600 flex items-center justify-center text-white text-2xl font-bold">
                                        ${student.first_name.charAt(0)}${student.last_name.charAt(0)}
                                    </div>`
                                }
                                <h4 class="mt-3 text-lg font-semibold text-gray-900">${student.first_name} ${student.last_name}</h4>
                                <p class="text-sm text-gray-500">${student.student_id}</p>
                                <p class="text-sm text-gray-500">${student.program || ''} ${student.year_level ? '- Year ' + student.year_level : ''}</p>
                            </div>
                            
                            <div class="space-y-3">
                                <div>
                                    <label class="text-sm font-medium text-gray-500">Position</label>
                                    <p class="text-sm text-gray-900">${student.position || 'N/A'}</p>
                                </div>
                                <div>
                                    <label class="text-sm font-medium text-gray-500">Deployment Period</label>
                                    <p class="text-sm text-gray-900">${student.start_date ? new Date(student.start_date).toLocaleDateString() : 'N/A'} - ${student.end_date ? new Date(student.end_date).toLocaleDateString() : 'N/A'}</p>
                                </div>
                                <div>
                                    <label class="text-sm font-medium text-gray-500">Required Hours</label>
                                    <p class="text-sm text-gray-900">${student.required_hours || 0} hrs</p>
                                </div>
                                <div>
                                    <label class="text-sm font-medium text-gray-500">Progress</label>
                                    <div class="mt-1 bg-gray-200 rounded-full h-2">
                                        <div class="bg-blue-600 h-2 rounded-full" style="width: ${Math.min(100, (summary.total_hours_worked / student.required_hours) * 100)}%"></div>
                                    </div>
                                    <p class="text-xs text-gray-500 mt-1">${summary.total_hours_worked || 0} / ${student.required_hours || 0} hrs (${Math.round((summary.total_hours_worked / student.required_hours) * 100) || 0}%)</p>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Statistics -->
                    <div class="lg:col-span-2">
                        <div class="grid grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
                            <div class="bg-green-50 p-4 rounded-lg">
                                <div class="text-2xl font-bold text-green-600">${summary.present_days || 0}</div>
                                <div class="text-sm text-green-600">Present Days</div>
                            </div>
                            <div class="bg-red-50 p-4 rounded-lg">
                                <div class="text-2xl font-bold text-red-600">${summary.absent_days || 0}</div>
                                <div class="text-sm text-red-600">Absent Days</div>
                            </div>
                            <div class="bg-yellow-50 p-4 rounded-lg">
                                <div class="text-2xl font-bold text-yellow-600">${summary.late_days || 0}</div>
                                <div class="text-sm text-yellow-600">Late Days</div>
                            </div>
                            <div class="bg-blue-50 p-4 rounded-lg">
                                <div class="text-2xl font-bold text-blue-600">${parseFloat(summary.total_hours_worked || 0).toFixed(1)}</div>
                                <div class="text-sm text-blue-600">Total Hours</div>
                            </div>
                        </div>

                        <!-- Recent Attendance -->
                        <div class="bg-white border border-gray-200 rounded-lg">
                            <div class="p-4 border-b border-gray-200">
                                <h5 class="text-lg font-semibold text-gray-900">Recent Attendance</h5>
                            </div>
                            <div class="max-h-96 overflow-y-auto">
                                <table class="w-full">
                                    <thead class="bg-gray-50">
                                        <tr>
                                            <th class="px-4 py-2 text-left text-xs font-medium text-gray-500">Date</th>
                                            <th class="px-4 py-2 text-left text-xs font-medium text-gray-500">Time In</th>
                                            <th class="px-4 py-2 text-left text-xs font-medium text-gray-500">Time Out</th>
                                            <th class="px-4 py-2 text-left text-xs font-medium text-gray-500">Hours</th>
                                            <th class="px-4 py-2 text-left text-xs font-medium text-gray-500">Status</th>
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y divide-gray-200">
${recentAttendance.map(record => `
    <tr>
        <td class="px-4 py-2 text-sm text-gray-900">${new Date(record.date).toLocaleDateString()}</td>
        <td class="px-4 py-2 text-sm text-gray-900">${record.time_in ? new Date('1970-01-01T' + record.time_in).toLocaleTimeString([], {hour: '2-digit', minute: '2-digit'}) : '-'}</td>
        <td class="px-4 py-2 text-sm text-gray-900">${record.time_out ? new Date('1970-01-01T' + record.time_out).toLocaleTimeString([], {hour: '2-digit', minute: '2-digit'}) : '-'}</td>
        <td class="px-4 py-2 text-sm text-gray-900">${record.total_hours ? parseFloat(record.total_hours).toFixed(2) + ' hrs' : '-'}</td>
        <td class="px-4 py-2 text-sm">
            <span class="inline-flex px-2 py-1 text-xs font-semibold rounded-full ${getStatusBadgeClass(record.status)}">
                ${record.status}
            </span>
            ${record.punctuality_status === 'Late' ? '<div class="text-xs text-red-500 mt-1"><i class="fas fa-clock mr-1"></i>Late</div>' : ''}
        </td>
    </tr>
`).join('')}
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
            `;

            document.getElementById('studentModalContent').innerHTML = content;
        }

        function getStatusBadgeClass(status) {
            const classes = {
                'Present': 'bg-green-100 text-green-800',
                'Absent': 'bg-red-100 text-red-800',
                'Late': 'bg-yellow-100 text-yellow-800',
                'Half Day': 'bg-blue-100 text-blue-800'
            };
            return classes[status] || 'bg-gray-100 text-gray-800';
        }

        function closeStudentModal() {
            document.getElementById('studentModal').classList.add('hidden');
        }

        // Notes modal functions
        function showNotes(notes) {
            document.getElementById('notesContent').textContent = notes;
            document.getElementById('notesModal').classList.remove('hidden');
        }

        function closeNotesModal() {
            document.getElementById('notesModal').classList.add('hidden');
        }

        // Logout confirmation
        function confirmLogout() {
            return confirm('Are you sure you want to logout?');
        }

        // Auto-refresh functionality (optional)
        let refreshInterval;
        function startAutoRefresh() {
            refreshInterval = setInterval(() => {
                // Only refresh if no modals are open
                if (document.getElementById('studentModal').classList.contains('hidden') && 
                    document.getElementById('notesModal').classList.contains('hidden')) {
                    location.reload();
                }
            }, 300000); // Refresh every 5 minutes
        }

        function stopAutoRefresh() {
            if (refreshInterval) {
                clearInterval(refreshInterval);
            }
        }

        // Initialize auto-refresh on page load
        document.addEventListener('DOMContentLoaded', function() {
            startAutoRefresh();
        });

        // Stop auto-refresh when page is hidden
        document.addEventListener('visibilitychange', function() {
            if (document.visibilityState === 'hidden') {
                stopAutoRefresh();
            } else {
                startAutoRefresh();
            }
        });
// Enhanced print function with detailed student and supervisor information
// Enhanced print function with detailed student and supervisor information - ALL RECORDS
function printRecords() {
    // Show loading state
    const printButton = document.querySelector('[onclick="printRecords()"]');
    if (printButton) {
        setButtonLoading(printButton, true);
    }
    
    // Get current filter values
    const dateFrom = document.getElementById('date_from').value;
    const dateTo = document.getElementById('date_to').value;
    const studentFilter = document.getElementById('student').value;
    const statusFilter = document.getElementById('status').value;
    const searchFilter = document.getElementById('search').value;
    
    // Prepare form data for fetching ALL records
    const formData = new FormData();
    formData.append('action', 'get_all_records_for_print');
    formData.append('date_from', dateFrom);
    formData.append('date_to', dateTo);
    formData.append('student', studentFilter);
    formData.append('status', statusFilter);
    formData.append('search', searchFilter);
    
    // Fetch ALL records for printing
    fetch('CompanyTimeRecord.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            generatePrintDocument(data);
        } else {
            alert('Error loading records for printing: ' + data.message);
        }
    })
    .catch(error => {
        console.error('Error:', error);
        alert('Error loading records for printing');
    })
    .finally(() => {
        // Remove loading state
        if (printButton) {
            setButtonLoading(printButton, false);
        }
    });
}

function generatePrintDocument(data) {
    // Create a new window for printing
    const printWindow = window.open('', '_blank');
    
    const records = data.records || [];
    const stats = data.stats || {};
    const filterInfo = data.filter_info || {};
    
    // Get supervisor info from PHP variables
    const supervisorName = '<?php echo addslashes($supervisor_name); ?>';
    const supervisorEmail = '<?php echo addslashes($supervisor_email); ?>';
    const companyName = '<?php echo addslashes($company_name); ?>';
    
    // Build filter summary
    let filterSummary = [];
    if (filterInfo.date_from) filterSummary.push(`From: ${new Date(filterInfo.date_from).toLocaleDateString()}`);
    if (filterInfo.date_to) filterSummary.push(`To: ${new Date(filterInfo.date_to).toLocaleDateString()}`);
    if (filterInfo.student_name) filterSummary.push(`Student: ${filterInfo.student_name}`);
    if (filterInfo.status) filterSummary.push(`Status: ${filterInfo.status}`);
    if (filterInfo.search) filterSummary.push(`Search: ${filterInfo.search}`);
    
    // Generate table rows from ALL records (removed notes column)
    const tableRows = records.map(record => {
        const statusBadgeClass = getStatusBadgeClass(record.status);
        const fullName = `${record.first_name} ${record.middle_name ? record.middle_name + ' ' : ''}${record.last_name}`;
        
        return `
            <tr>
                <td>
                    <div class="student-info">
                        <div class="student-name">${fullName}</div>
                        <div class="student-id">${record.student_id}</div>
                        ${record.program ? `<div class="student-program">${record.program}${record.year_level ? ' - Year ' + record.year_level : ''}</div>` : ''}
                    </div>
                </td>
                <td class="time-info">
                    ${new Date(record.date).toLocaleDateString('en-US', { month: 'short', day: '2-digit', year: 'numeric' })}
                    <div class="day-name">${new Date(record.date).toLocaleDateString('en-US', { weekday: 'short' })}</div>
                </td>
                <td class="time-info">
                    ${record.time_in ? new Date('1970-01-01T' + record.time_in).toLocaleTimeString([], {hour: '2-digit', minute: '2-digit'}) : '-'}
                    ${record.punctuality_status === 'Late' ? '<div class="late-indicator">Late</div>' : ''}
                </td>
                <td class="time-info">
                    ${record.time_out ? new Date('1970-01-01T' + record.time_out).toLocaleTimeString([], {hour: '2-digit', minute: '2-digit'}) : '-'}
                </td>
                <td class="time-info">
                    ${record.total_hours ? parseFloat(record.total_hours).toFixed(2) + ' hrs' : '-'}
                </td>
                <td>
                    <div class="status-badge ${statusBadgeClass}">${record.status}</div>
                </td>
                <td>
                    ${record.attendance_method === 'facial' ? 
                        `<div class="method-ai">AI Detection</div>${record.facial_confidence ? `<div class="confidence">${Math.round(record.facial_confidence * 100)}%</div>` : ''}` :
                        '<div class="method-manual">Manual Entry</div>'
                    }
                </td>
            </tr>
        `;
    }).join('');
    
    // Generate print content
    const printContent = `
        <!DOCTYPE html>
        <html>
        <head>
            <title>Student Attendance Records - ${companyName}</title>
            <style>
                @page {
                    margin: 0.5in;
                    size: A4 landscape;
                }
                
                body {
                    font-family: Arial, sans-serif;
                    font-size: 11px;
                    line-height: 1.3;
                    color: #000;
                    margin: 0;
                    padding: 0;
                }
                
                .header {
                    text-align: center;
                    margin-bottom: 25px;
                    border-bottom: 3px solid #800000;
                    padding-bottom: 15px;
                }
                
                .header h1 {
                    margin: 0;
                    font-size: 22px;
                    color: #800000;
                    font-weight: bold;
                }
                
                .header h2 {
                    margin: 5px 0;
                    font-size: 16px;
                    color: #333;
                }
                
                .header p {
                    margin: 3px 0;
                    font-size: 11px;
                    color: #666;
                }
                
                .info-section {
                    display: flex;
                    justify-content: space-between;
                    margin-bottom: 20px;
                    padding: 12px;
                    background-color: #f8f9fa;
                    border: 1px solid #dee2e6;
                    border-radius: 4px;
                }
                
                .info-box {
                    flex: 1;
                    margin: 0 8px;
                }
                
                .info-box h3 {
                    margin: 0 0 8px 0;
                    font-size: 12px;
                    color: #800000;
                    border-bottom: 1px solid #800000;
                    padding-bottom: 3px;
                }
                
                .info-box p {
                    margin: 3px 0;
                    font-size: 10px;
                }
                
                .info-box strong {
                    color: #333;
                }
                
                .statistics {
                    display: flex;
                    justify-content: space-around;
                    margin-bottom: 20px;
                    padding: 12px;
                    background-color: #e3f2fd;
                    border-radius: 4px;
                    border: 1px solid #90caf9;
                }
                
                .stat-item {
                    text-align: center;
                    flex: 1;
                }
                
                .stat-number {
                    font-size: 16px;
                    font-weight: bold;
                    color: #1565c0;
                    display: block;
                }
                
                .stat-label {
                    font-size: 9px;
                    color: #666;
                    margin-top: 2px;
                }
                
                .filter-info {
                    margin-bottom: 15px;
                    padding: 8px;
                    background-color: #fff3cd;
                    border: 1px solid #ffeaa7;
                    border-radius: 3px;
                }
                
                .filter-info h4 {
                    margin: 0 0 6px 0;
                    font-size: 11px;
                    color: #856404;
                }
                
                .filter-info p {
                    margin: 1px 0;
                    font-size: 9px;
                    color: #856404;
                }
                
                table {
                    width: 100%;
                    border-collapse: collapse;
                    margin-top: 15px;
                    font-size: 9px;
                }
                
                th, td {
                    border: 1px solid #333;
                    padding: 4px 3px;
                    text-align: left;
                    vertical-align: top;
                }
                
                th {
                    background-color: #800000;
                    color: white;
                    font-weight: bold;
                    font-size: 8px;
                    text-transform: uppercase;
                    text-align: center;
                }
                
                tbody tr:nth-child(even) {
                    background-color: #f8f9fa;
                }
                
                .student-info {
                    line-height: 1.2;
                }
                
                .student-name {
                    font-weight: bold;
                    color: #333;
                    font-size: 9px;
                }
                
                .student-id {
                    color: #666;
                    font-size: 8px;
                }
                
                .student-program {
                    color: #888;
                    font-size: 7px;
                    font-style: italic;
                }
                
                .time-info {
                    text-align: center;
                }
                
                .day-name {
                    color: #666;
                    font-size: 7px;
                    font-style: italic;
                }
                
                .late-indicator {
                    color: #dc3545;
                    font-size: 7px;
                    font-weight: bold;
                    font-style: italic;
                }
                
                .status-badge {
                    padding: 2px 5px;
                    border-radius: 8px;
                    font-size: 7px;
                    font-weight: bold;
                    text-align: center;
                    display: inline-block;
                    min-width: 40px;
                }
                
                .status-present { background-color: #d4edda; color: #155724; }
                .status-absent { background-color: #f8d7da; color: #721c24; }
                .status-late { background-color: #fff3cd; color: #856404; }
                .status-half-day { background-color: #cce5ff; color: #004085; }
                
                .method-ai {
                    color: #0066cc;
                    font-size: 7px;
                    font-weight: bold;
                }
                
                .method-manual {
                    color: #666;
                    font-size: 7px;
                }
                
                .confidence {
                    color: #0066cc;
                    font-size: 6px;
                    font-style: italic;
                }
                
                .footer {
                    margin-top: 25px;
                    padding-top: 15px;
                    border-top: 2px solid #800000;
                    text-align: center;
                    font-size: 9px;
                    color: #666;
                }
                
                .signature-section {
                    margin-top: 30px;
                    display: flex;
                    justify-content: space-between;
                }
                
                .signature-box {
                    text-align: center;
                    width: 180px;
                }
                
                .signature-line {
                    border-top: 1px solid #333;
                    margin-top: 40px;
                    padding-top: 5px;
                    font-size: 9px;
                }
                
                .page-break {
                    page-break-after: always;
                }
                
                .record-count {
                    text-align: right;
                    margin-bottom: 10px;
                    font-size: 10px;
                    color: #666;
                    font-style: italic;
                }
            </style>
        </head>
        <body>
            <div class="header">
                <h1>OnTheJob Tracker</h1>
                <h2>Complete Student Attendance Records Report</h2>
                <p><strong>Generated on:</strong> ${new Date().toLocaleDateString('en-US', { 
                    weekday: 'long', 
                    year: 'numeric', 
                    month: 'long', 
                    day: 'numeric',
                    hour: '2-digit',
                    minute: '2-digit'
                })}</p>
            </div>
            
            <div class="info-section">
                <div class="info-box">
                    <h3>Company Information</h3>
                    <p><strong>Company:</strong> ${companyName}</p>
                    <p><strong>Supervisor:</strong> ${supervisorName}</p>
                    <p><strong>Email:</strong> ${supervisorEmail}</p>
                    <p><strong>Report Type:</strong> Complete Attendance Records</p>
                </div>
                
                <div class="info-box">
                    <h3>Report Summary</h3>
                    <p><strong>Total Records:</strong> ${stats.total_records || 0}</p>
                    <p><strong>Date Range:</strong> ${filterInfo.date_from ? new Date(filterInfo.date_from).toLocaleDateString() : 'All'} - ${filterInfo.date_to ? new Date(filterInfo.date_to).toLocaleDateString() : 'All'}</p>
                    <p><strong>Student Filter:</strong> ${filterInfo.student_name || 'All Students'}</p>
                    <p><strong>Status Filter:</strong> ${filterInfo.status || 'All Status'}</p>
                </div>
                
                <div class="info-box">
                    <h3>System Information</h3>
                    <p><strong>System:</strong> OnTheJob Tracker</p>
                    <p><strong>Version:</strong> 2.0</p>
                    <p><strong>Timezone:</strong> Asia/Manila (UTC+8)</p>
                    <p><strong>Generated From:</strong> All Available Records</p>
                </div>
            </div>
            
            <div class="statistics">
                <div class="stat-item">
                    <span class="stat-number">${stats.total_records || 0}</span>
                    <div class="stat-label">Total Records</div>
                </div>
                <div class="stat-item">
                    <span class="stat-number">${stats.total_present_days || 0}</span>
                    <div class="stat-label">Present Days</div>
                </div>
                <div class="stat-item">
                    <span class="stat-number">${stats.total_late_arrivals || 0}</span>
                    <div class="stat-label">Late Arrivals</div>
                </div>
                <div class="stat-item">
                    <span class="stat-number">${parseFloat(stats.total_hours_sum || 0).toFixed(1)}</span>
                    <div class="stat-label">Total Hours</div>
                </div>
                <div class="stat-item">
                    <span class="stat-number">${stats.total_absent_days || 0}</span>
                    <div class="stat-label">Absent Days</div>
                </div>
            </div>
            
            ${filterSummary.length > 0 ? `
                <div class="filter-info">
                    <h4>Applied Filters:</h4>
                    ${filterSummary.map(filter => `<p>• ${filter}</p>`).join('')}
                </div>
            ` : ''}
            
            <div class="record-count">
                <strong>Showing all ${records.length} records</strong>
            </div>
            
            <table>
                <thead>
                    <tr>
                        <th style="width: 25%;">Student Information</th>
                        <th style="width: 15%;">Date</th>
                        <th style="width: 15%;">Time In</th>
                        <th style="width: 15%;">Time Out</th>
                        <th style="width: 12%;">Hours</th>
                        <th style="width: 13%;">Status</th>
                        <th style="width: 15%;">Method</th>
                    </tr>
                </thead>
                <tbody>
                    ${records.length > 0 ? tableRows : `
                        <tr>
                            <td colspan="7" style="text-align: center; padding: 20px; color: #666; font-style: italic;">
                                No attendance records found for the selected criteria.
                            </td>
                        </tr>
                    `}
                </tbody>
            </table>
            
            <div class="signature-section">
                <div class="signature-box">
                    <div class="signature-line">
                        Prepared By<br>
                        <strong>${supervisorName}</strong><br>
                        Company Supervisor
                    </div>
                </div>
                <div class="signature-box">
                    <div class="signature-line">
                        Date<br>
                        <strong>${new Date().toLocaleDateString()}</strong>
                    </div>
                </div>
                <div class="signature-box">
                    <div class="signature-line">
                        Verified By<br>
                        ________________________<br>
                        HR Manager
                    </div>
                </div>
            </div>
            
            <div class="footer">
                <p>This is a computer-generated document from OnTheJob Tracker System containing <strong>${records.length} total records</strong>.</p>
                <p>For questions or concerns, please contact ${supervisorEmail}</p>
                <p><strong>Confidential:</strong> This document contains sensitive student information and should be handled accordingly.</p>
            </div>
        </body>
        </html>
    `;
    
    // Write content to print window
    printWindow.document.write(printContent);
    printWindow.document.close();
    
    // Wait for content to load, then print
    printWindow.onload = function() {
        setTimeout(() => {
            printWindow.print();
            printWindow.close();
        }, 1000);
    };
}
// Helper function for status badge classes (same as before)
function getStatusBadgeClass(status) {
    const classes = {
        'Present': 'status-present',
        'Absent': 'status-absent',
        'Late': 'status-late',
        'Half Day': 'status-half-day'
    };
    return classes[status] || 'status-present';
}

// Helper function to format table for printing
function formatTableForPrint(tableHTML) {
    // Remove action columns and clean up the table for printing
    const tempDiv = document.createElement('div');
    tempDiv.innerHTML = tableHTML;
    
    const table = tempDiv.querySelector('table');
    if (!table) return '<p>No attendance records found.</p>';
    
    // Remove action column header
    const headerRow = table.querySelector('thead tr');
    if (headerRow) {
        const actionHeader = headerRow.querySelector('th:last-child');
        if (actionHeader && actionHeader.textContent.trim() === 'Actions') {
            actionHeader.remove();
        }
    }
    
    // Remove action column cells and format content
    const dataRows = table.querySelectorAll('tbody tr');
    dataRows.forEach(row => {
        // Remove action cell
        const actionCell = row.querySelector('td:last-child');
        if (actionCell) {
            actionCell.remove();
        }
        
        // Format student info cell
        const studentCell = row.querySelector('td:first-child');
        if (studentCell) {
            const studentInfo = studentCell.querySelector('.ml-4');
            if (studentInfo) {
                const nameEl = studentInfo.querySelector('.text-sm.font-medium');
                const idEl = studentInfo.querySelector('.text-sm.text-gray-500');
                
                if (nameEl && idEl) {
                    studentCell.innerHTML = `
                        <div class="student-info">
                            <div class="student-name">${nameEl.textContent.trim()}</div>
                            <div class="student-id">${idEl.textContent.trim()}</div>
                        </div>
                    `;
                }
            }
        }
        
        // Format status badges
        const statusCell = row.querySelector('td:nth-child(6)');
        if (statusCell) {
            const badge = statusCell.querySelector('span');
            if (badge) {
                const status = badge.textContent.trim();
                let badgeClass = 'status-badge ';
                
                switch (status) {
                    case 'Present': badgeClass += 'status-present'; break;
                    case 'Absent': badgeClass += 'status-absent'; break;
                    case 'Late': badgeClass += 'status-late'; break;
                    case 'Half Day': badgeClass += 'status-half-day'; break;
                    default: badgeClass += 'status-present';
                }
                
                statusCell.innerHTML = `<div class="${badgeClass}">${status}</div>`;
            }
        }
        
        // Format time cells
        const timeInCell = row.querySelector('td:nth-child(3)');
        const timeOutCell = row.querySelector('td:nth-child(4)');
        
        [timeInCell, timeOutCell].forEach(cell => {
            if (cell) {
                cell.classList.add('time-info');
                
                // Check for late indicator
                const lateIndicator = cell.querySelector('.text-xs.text-red-500');
                if (lateIndicator) {
                    const timeText = cell.querySelector('.text-sm.text-gray-900');
                    if (timeText) {
                        cell.innerHTML = `
                            ${timeText.textContent}
                            <div class="late-indicator">Late</div>
                        `;
                    }
                }
            }
        });
        
        // Format method column
        const methodCell = row.querySelector('td:nth-child(7)');
        if (methodCell) {
            const method = methodCell.textContent.toLowerCase();
            if (method.includes('ai') || method.includes('facial')) {
                methodCell.innerHTML = '<div class="method-ai">AI Detection</div>';
            } else {
                methodCell.innerHTML = '<div class="method-manual">Manual Entry</div>';
            }
        }
    });
    
    return table.outerHTML;
}

// Alternative function for quick print (simpler version)
function quickPrintRecords() {
    window.print();
}

        // Keyboard shortcuts
        document.addEventListener('keydown', function(e) {
            // Escape key to close modals
            if (e.key === 'Escape') {
                if (!document.getElementById('studentModal').classList.contains('hidden')) {
                    closeStudentModal();
                }
                if (!document.getElementById('notesModal').classList.contains('hidden')) {
                    closeNotesModal();
                }
            }
            
            // Ctrl+P to print
            if (e.ctrlKey && e.key === 'p') {
                e.preventDefault();
                printRecords();
            }
        });

        // Form validation for date filters
        document.getElementById('date_from').addEventListener('change', function() {
            const dateFrom = new Date(this.value);
            const dateTo = new Date(document.getElementById('date_to').value);
            
            if (dateTo && dateFrom > dateTo) {
                document.getElementById('date_to').value = this.value;
            }
        });

        document.getElementById('date_to').addEventListener('change', function() {
            const dateFrom = new Date(document.getElementById('date_from').value);
            const dateTo = new Date(this.value);
            
            if (dateFrom && dateTo < dateFrom) {
                document.getElementById('date_from').value = this.value;
            }
        });

        // Loading states for buttons
        function setButtonLoading(button, isLoading) {
            if (isLoading) {
                button.disabled = true;
                const originalText = button.innerHTML;
                button.setAttribute('data-original-text', originalText);
                button.innerHTML = '<i class="fas fa-spinner fa-spin mr-2"></i>Loading...';
            } else {
                button.disabled = false;
                button.innerHTML = button.getAttribute('data-original-text');
            }
        }

        // Enhanced error handling
        window.addEventListener('error', function(e) {
            console.error('JavaScript error:', e.error);
            // You could show a user-friendly error message here
        });
    </script>
</body>
</html>