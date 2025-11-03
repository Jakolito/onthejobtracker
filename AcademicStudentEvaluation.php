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

// Get adviser role and assignment details from database
$adviser_query = "SELECT role, department, year_level, section, assigned_groups FROM academic_adviser WHERE id = ?";
$adviser_stmt = mysqli_prepare($conn, $adviser_query);
mysqli_stmt_bind_param($adviser_stmt, "i", $adviser_id);
mysqli_stmt_execute($adviser_stmt);
$adviser_result = mysqli_stmt_get_result($adviser_stmt);
$adviser_data = mysqli_fetch_assoc($adviser_result);
$adviser_role = $adviser_data['role'] ?? 'adviser';
$adviser_assigned_groups = $adviser_data['assigned_groups'];
mysqli_stmt_close($adviser_stmt);

// Get profile picture
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

// Build WHERE clause based on adviser's assigned groups
$student_where_clause = "s.status != 'Blocked'";

if ($adviser_role === 'coordinator') {
    if (!empty($adviser_assigned_groups) && $adviser_assigned_groups !== NULL) {
        $groups = array_map('trim', explode(',', $adviser_assigned_groups));
        $group_conditions = [];
        
        foreach ($groups as $group) {
            if (!empty($group)) {
                $group_escaped = mysqli_real_escape_string($conn, $group);
                $group_with_hyphen = str_replace(' G', '-G', $group_escaped);
                $group_with_space = str_replace('-G', ' G', $group_escaped);
                
                $group_conditions[] = "(
                    TRIM(s.section) = TRIM('$group_escaped')
                    OR TRIM(s.section) = TRIM('$group_with_hyphen')
                    OR TRIM(s.section) = TRIM('$group_with_space')
                )";
            }
        }
        
        if (!empty($group_conditions)) {
            $student_where_clause .= " AND (" . implode(" OR ", $group_conditions) . ")";
        }
    }
} elseif ($adviser_role === 'adviser') {
    if (!empty($adviser_assigned_groups) && $adviser_assigned_groups !== NULL) {
        $groups = array_map('trim', explode(',', $adviser_assigned_groups));
        $group_conditions = [];
        
        foreach ($groups as $group) {
            if (!empty($group)) {
                $group_escaped = mysqli_real_escape_string($conn, $group);
                $group_with_hyphen = str_replace(' G', '-G', $group_escaped);
                $group_with_space = str_replace('-G', ' G', $group_escaped);
                
                $group_conditions[] = "(
                    TRIM(s.section) = TRIM('$group_escaped')
                    OR TRIM(s.section) = TRIM('$group_with_hyphen')
                    OR TRIM(s.section) = TRIM('$group_with_space')
                )";
            }
        }
        
        if (!empty($group_conditions)) {
            $student_where_clause .= " AND (" . implode(" OR ", $group_conditions) . ")";
        } else {
            $student_where_clause .= " AND 1=0";
        }
    } else {
        $student_where_clause .= " AND 1=0";
    }
}

// Handle evaluation submission
$success_message = '';
$error_message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_evaluation'])) {
    $student_id = intval($_POST['student_id']);
    $quality_of_work = floatval($_POST['quality_of_work']);
    $completeness_of_work = floatval($_POST['completeness_of_work']);
    $urgency_of_output = floatval($_POST['urgency_of_output']);
    $attendance_promptness = floatval($_POST['attendance_promptness']);
    
    // Validate inputs with proper error messages
    if ($quality_of_work < 0 || $quality_of_work > 100) {
        $error_message = "Quality of Work must be between 0 and 100. You entered: " . number_format($quality_of_work, 2);
    } elseif ($completeness_of_work < 0 || $completeness_of_work > 100) {
        $error_message = "Completeness of Work must be between 0 and 100. You entered: " . number_format($completeness_of_work, 2);
    } elseif ($urgency_of_output < 0 || $urgency_of_output > 10) {
        $error_message = "Urgency of Output must be between 0 and 10. You entered: " . number_format($urgency_of_output, 2);
    } elseif ($attendance_promptness < 0 || $attendance_promptness > 10) {
        $error_message = "Attendance/Promptness must be between 0 and 10. You entered: " . number_format($attendance_promptness, 2);
    } else {
        // Calculate percentages
        $quality_percentage = ($quality_of_work / 100) * 40;
        $completeness_percentage = ($completeness_of_work / 100) * 40;
        $urgency_percentage = $urgency_of_output;
        $attendance_percentage = $attendance_promptness;
        
        // Calculate total coordinator grade
        $total_points = $quality_percentage + $completeness_percentage + $urgency_percentage + $attendance_percentage;
        $coordinator_grade = ($total_points / 100) * 30;
        
        // Check if evaluation already exists
        $check_query = "SELECT id FROM academicstudentevaluation WHERE student_id = ?";
        $check_stmt = mysqli_prepare($conn, $check_query);
        mysqli_stmt_bind_param($check_stmt, "i", $student_id);
        mysqli_stmt_execute($check_stmt);
        $check_result = mysqli_stmt_get_result($check_stmt);
        
        if (mysqli_num_rows($check_result) > 0) {
            // Update existing evaluation
            $update_query = "UPDATE academicstudentevaluation SET 
                quality_of_work = ?, 
                completeness_of_work = ?, 
                urgency_of_output = ?, 
                attendance_promptness = ?,
                quality_percentage = ?,
                completeness_percentage = ?,
                urgency_percentage = ?,
                attendance_percentage = ?,
                coordinator_grade = ?,
                evaluated_by = ?,
                evaluated_at = NOW()
                WHERE student_id = ?";
            
            $update_stmt = mysqli_prepare($conn, $update_query);
            mysqli_stmt_bind_param($update_stmt, "dddddddddii", 
                $quality_of_work, $completeness_of_work, $urgency_of_output, $attendance_promptness,
                $quality_percentage, $completeness_percentage, $urgency_percentage, $attendance_percentage,
                $coordinator_grade, $adviser_id, $student_id
            );
            
            if (mysqli_stmt_execute($update_stmt)) {
                $success_message = "Evaluation updated successfully!";
            } else {
                $error_message = "Error updating evaluation: " . mysqli_error($conn);
            }
            mysqli_stmt_close($update_stmt);
        } else {
            // Insert new evaluation
            $insert_query = "INSERT INTO academicstudentevaluation 
                (student_id, quality_of_work, completeness_of_work, urgency_of_output, attendance_promptness,
                quality_percentage, completeness_percentage, urgency_percentage, attendance_percentage,
                coordinator_grade, evaluated_by, evaluated_at) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())";
            
            $insert_stmt = mysqli_prepare($conn, $insert_query);
            mysqli_stmt_bind_param($insert_stmt, "idddddddddi", 
                $student_id, $quality_of_work, $completeness_of_work, $urgency_of_output, $attendance_promptness,
                $quality_percentage, $completeness_percentage, $urgency_percentage, $attendance_percentage,
                $coordinator_grade, $adviser_id
            );
            
            if (mysqli_stmt_execute($insert_stmt)) {
                $success_message = "Evaluation submitted successfully!";
            } else {
                $error_message = "Error submitting evaluation: " . mysqli_error($conn);
            }
            mysqli_stmt_close($insert_stmt);
        }
        mysqli_stmt_close($check_stmt);
    }
}

// Get deployed students with their evaluations
$students_query = "SELECT 
    s.id, s.first_name, s.last_name, s.student_id, s.program, s.year_level, s.section,
    sd.company_name, sd.position, sd.start_date, sd.end_date, sd.status as deployment_status,
    ae.quality_of_work, ae.completeness_of_work, ae.urgency_of_output, ae.attendance_promptness,
    ae.coordinator_grade, ae.evaluated_at
    FROM students s
    INNER JOIN student_deployments sd ON s.id = sd.student_id
    LEFT JOIN academicstudentevaluation ae ON s.id = ae.student_id
    WHERE $student_where_clause AND (sd.status = 'Active' OR sd.ojt_status = 'Active')
    ORDER BY s.last_name, s.first_name";

$students_result = mysqli_query($conn, $students_query);
if (!$students_result) {
    $error_message = "Error fetching students: " . mysqli_error($conn);
    $students_result = mysqli_query($conn, "SELECT 1 WHERE 1=0");
}

// Get unread messages count
$unread_messages_query = "SELECT COUNT(*) as count 
    FROM messages m 
    JOIN students s ON m.sender_id = s.id 
    WHERE m.recipient_type = 'adviser' AND m.sender_type = 'student' 
    AND m.is_read = 0 AND m.is_deleted_by_recipient = 0 AND $student_where_clause";
$unread_messages_result = mysqli_query($conn, $unread_messages_query);
$unread_messages_count = 0;
if ($unread_messages_result) {
    $unread_messages_count = mysqli_fetch_assoc($unread_messages_result)['count'];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Student Evaluation - Academic Adviser</title>
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

        @keyframes pulse {
            0%, 100% { transform: scale(1); }
            50% { transform: scale(1.1); }
        }

        .modal {
            display: none;
            position: fixed;
            z-index: 1000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            overflow: auto;
            background-color: rgba(0,0,0,0.5);
        }

        .modal.active {
            display: block;
        }

        .modal-content {
            background-color: #fefefe;
            margin: 5% auto;
            padding: 0;
            border-radius: 8px;
            width: 90%;
            max-width: 600px;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
        }

        input[type="number"]::-webkit-inner-spin-button,
        input[type="number"]::-webkit-outer-spin-button {
            -webkit-appearance: none;
            margin: 0;
        }

        input[type="number"] {
            -moz-appearance: textfield;
        }
    </style>
</head>
<body class="bg-gray-50">
    <!-- Mobile Sidebar Overlay -->
    <div id="sidebarOverlay" class="fixed inset-0 bg-black bg-opacity-50 z-40 lg:hidden hidden"></div>

    <!-- Sidebar -->
    <div id="sidebar" class="fixed left-0 top-0 h-full w-64 bg-gradient-to-b from-bulsu-maroon to-bulsu-dark-maroon shadow-lg z-50 transform -translate-x-full lg:translate-x-0 transition-transform duration-300 ease-in-out">
        <!-- Close button for mobile -->
        <div class="flex justify-end p-4 lg:hidden">
            <button id="closeSidebar" class="text-bulsu-light-gold hover:text-bulsu-gold">
                <i class="fas fa-times text-xl"></i>
            </button>
        </div>

        <!-- Logo Section -->
        <div class="px-6 py-4 border-b border-bulsu-gold border-opacity-30">
            <div class="flex items-center">
                <img src="reqsample/bulsu12.png" alt="BULSU Logo" class="w-14 h-14 mr-2">
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
                        <span class="notification-badge"><?php echo $unread_messages_count; ?></span>
                    <?php endif; ?>
                </a>
                <a href="academicAdviserEdit.php" class="nav-item flex items-center px-3 py-2 text-sm font-medium text-bulsu-light-gold hover:text-white hover:bg-bulsu-gold hover:bg-opacity-20 rounded-md transition-all duration-200">
                    <i class="fas fa-edit mr-3"></i>
                    Edit Document
                </a>
                <a href="AcademicStudentEvaluation.php" class="nav-item flex items-center px-3 py-2 text-sm font-medium text-white bg-bulsu-gold bg-opacity-20 border border-bulsu-gold border-opacity-30 rounded-md">
                    <i class="fas fa-star mr-3 text-bulsu-gold"></i>
                    Student Evaluation
                </a>
                <?php if ($adviser_role === 'coordinator'): ?>
                <a href="AcademicAccounts.php" class="nav-item flex items-center px-3 py-2 text-sm font-medium text-bulsu-light-gold hover:text-white hover:bg-bulsu-gold hover:bg-opacity-20 rounded-md transition-all duration-200">
                    <i class="fas fa-user-tie mr-3"></i>
                    Academic Accounts
                </a>
                <?php endif; ?>
            </nav>
        </div>

        <!-- User Profile -->
        <div class="absolute bottom-0 left-0 right-0 p-4 border-t border-bulsu-gold border-opacity-30 bg-gradient-to-t from-black to-transparent">
            <div class="flex items-center space-x-3">
                <div class="flex-shrink-0 w-10 h-10 bg-gradient-to-r from-bulsu-gold to-yellow-400 rounded-full flex items-center justify-center text-bulsu-maroon font-semibold text-sm overflow-hidden">
                    <?php if (!empty($profile_picture) && file_exists($profile_picture)): ?>
                        <img src="<?php echo htmlspecialchars($profile_picture); ?>" alt="Profile" class="w-full h-full object-cover">
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
                    <h1 class="text-xl sm:text-2xl font-bold text-gray-900">Student Evaluation</h1>
                    <p class="text-sm sm:text-base text-gray-500">Evaluate deployed students (Coordinator's Grade - 30%)</p>
                </div>

                <!-- Profile Dropdown -->
                <div class="relative">
                    <button id="profileBtn" class="flex items-center p-1 rounded-full hover:bg-gray-100">
                        <div class="w-8 h-8 sm:w-10 sm:h-10 bg-gradient-to-r from-blue-500 to-purple-600 rounded-full flex items-center justify-center text-white font-semibold text-xs sm:text-sm overflow-hidden">
                            <?php if (!empty($profile_picture) && file_exists($profile_picture)): ?>
                                <img src="<?php echo htmlspecialchars($profile_picture); ?>" alt="Profile" class="w-full h-full object-cover">
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
                                        <img src="<?php echo htmlspecialchars($profile_picture); ?>" alt="Profile" class="w-full h-full object-cover">
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
            <!-- Success/Error Messages -->
            <?php if (!empty($success_message)): ?>
                <div class="mb-6 bg-green-50 border border-green-200 text-green-800 px-4 py-3 rounded-lg flex items-center">
                    <i class="fas fa-check-circle mr-3"></i>
                    <span><?php echo htmlspecialchars($success_message); ?></span>
                </div>
            <?php endif; ?>

            <?php if (!empty($error_message)): ?>
                <div class="mb-6 bg-red-50 border border-red-200 text-red-800 px-4 py-3 rounded-lg flex items-center">
                    <i class="fas fa-exclamation-circle mr-3"></i>
                    <span><?php echo htmlspecialchars($error_message); ?></span>
                </div>
            <?php endif; ?>

            <!-- Grading System Info -->
            <div class="mb-6 bg-blue-50 border border-blue-200 rounded-lg p-4">
                <h3 class="text-lg font-semibold text-blue-900 mb-3">
                    <i class="fas fa-info-circle mr-2"></i>
                    Coordinator's Grade - 30% (Covers all submitted Final Output)
                </h3>
                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4 text-sm">
                    <div class="bg-white p-3 rounded-md">
                        <p class="font-medium text-gray-700">Quality of Work</p>
                        <p class="text-blue-600 font-bold">40% (max: 100 points)</p>
                    </div>
                    <div class="bg-white p-3 rounded-md">
                        <p class="font-medium text-gray-700">Completeness of Work</p>
                        <p class="text-blue-600 font-bold">40% (max: 100 points)</p>
                    </div>
                    <div class="bg-white p-3 rounded-md">
                        <p class="font-medium text-gray-700">Urgency of Output</p>
                        <p class="text-blue-600 font-bold">10% (max: 10 points)</p>
                    </div>
                    <div class="bg-white p-3 rounded-md">
                        <p class="font-medium text-gray-700">Attendance/Promptness</p>
                        <p class="text-blue-600 font-bold">10% (max: 10 points)</p>
                    </div>
                </div>
                <p class="mt-3 text-sm text-blue-800">
                    <i class="fas fa-calculator mr-2"></i>
                    <strong>Total Maximum:</strong> 30% of final grade
                </p>
            </div>

            <!-- Students Table -->
            <div class="bg-white rounded-lg shadow-sm border border-gray-200">
                <div class="p-4 sm:p-6 border-b border-gray-200">
                    <h3 class="text-lg font-medium text-gray-900">Deployed Students for Evaluation</h3>
                    <p class="text-sm text-gray-500 mt-1">Click "Evaluate" to assess student performance</p>
                </div>
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200">
                        <thead class="bg-gray-50">
    <tr>
        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Student</th>
        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Company</th>
        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Deployment Period</th>
        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Status</th>
        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Current Grade</th>
        <th class="px-6 py-3 text-center text-xs font-medium text-gray-500 uppercase tracking-wider">Action</th>
    </tr>
</thead>
                        <tbody class="bg-white divide-y divide-gray-200">
                            <?php if (mysqli_num_rows($students_result) > 0): ?>
                                <?php while ($student = mysqli_fetch_assoc($students_result)): ?>
                                    <?php
                                    $student_initials = strtoupper(substr($student['first_name'], 0, 1) . substr($student['last_name'], 0, 1));
                                    $has_evaluation = !empty($student['coordinator_grade']);
                                    $grade_color = '';
                                    if ($has_evaluation) {
                                        $grade = floatval($student['coordinator_grade']);
                                        if ($grade >= 27) $grade_color = 'text-green-600';
                                        elseif ($grade >= 21) $grade_color = 'text-blue-600';
                                        elseif ($grade >= 15) $grade_color = 'text-yellow-600';
                                        else $grade_color = 'text-red-600';
                                    }
                                    ?>
                                    <tr class="hover:bg-gray-50">
                                        <td class="px-6 py-4 whitespace-nowrap">
                                            <div class="flex items-center">
                                                <div class="flex-shrink-0 h-10 w-10 bg-gradient-to-r from-blue-500 to-purple-600 rounded-full flex items-center justify-center text-white font-semibold">
                                                    <?php echo $student_initials; ?>
                                                </div>
                                                <div class="ml-4">
                                                    <div class="text-sm font-medium text-gray-900">
                                                        <?php echo htmlspecialchars($student['first_name'] . ' ' . $student['last_name']); ?>
                                                    </div>
                                                    <div class="text-sm text-gray-500">
                                                        <?php echo htmlspecialchars($student['student_id']); ?>
                                                    </div>
                                                    <div class="text-xs text-gray-400">
                                                        <?php echo htmlspecialchars($student['program'] . ' - ' . $student['section']); ?>
                                                    </div>
                                                </div>
                                            </div>
                                        </td>
                                        <td class="px-6 py-4">
                                            <div class="text-sm text-gray-900"><?php echo htmlspecialchars($student['company_name']); ?></div>
                                            <div class="text-xs text-gray-500"><?php echo htmlspecialchars($student['position']); ?></div>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap">
                                            <div class="text-sm text-gray-900"><?php echo date('M j, Y', strtotime($student['start_date'])); ?></div>
                                            <div class="text-xs text-gray-500">to <?php echo date('M j, Y', strtotime($student['end_date'])); ?></div>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap">
                                            <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-green-100 text-green-800">
                                                <?php echo htmlspecialchars($student['deployment_status']); ?>
                                            </span>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap">
                                            <?php if ($has_evaluation): ?>
                                                <div class="text-sm">
                                                    <span class="font-bold <?php echo $grade_color; ?>">
                                                        <?php echo number_format($student['coordinator_grade'], 2); ?>%
                                                    </span>
                                                    <span class="text-gray-500">/ 30%</span>
                                                </div>
                                                <div class="text-xs text-gray-400">
                                                    Evaluated: <?php echo date('M j, Y', strtotime($student['evaluated_at'])); ?>
                                                </div>
                                            <?php else: ?>
                                                <span class="text-sm text-gray-400 italic">Not yet evaluated</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-center">
                                            <button onclick="openEvaluationModal(<?php echo htmlspecialchars(json_encode($student)); ?>)" 
                                                class="inline-flex items-center px-3 py-2 border border-transparent text-sm leading-4 font-medium rounded-md text-white bg-bulsu-maroon hover:bg-bulsu-dark-maroon focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-bulsu-gold">
                                                <i class="fas fa-star mr-2"></i>
                                                <?php echo $has_evaluation ? 'Re-evaluate' : 'Evaluate'; ?>
                                            </button>
                                        </td>
                                    </tr>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="6" class="px-6 py-8 text-center text-gray-500">
                                        <i class="fas fa-users text-4xl mb-3 text-gray-300"></i>
                                        <p class="text-lg">No deployed students found for evaluation</p>
                                        <p class="text-sm mt-1">Students must be actively deployed to be evaluated</p>
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <!-- Evaluation Modal -->
    <div id="evaluationModal" class="modal">
        <div class="modal-content">
            <div class="bg-gradient-to-r from-bulsu-maroon to-bulsu-dark-maroon text-white px-6 py-4 rounded-t-lg">
                <div class="flex items-center justify-between">
                    <h3 class="text-lg font-semibold">
                        <i class="fas fa-star mr-2"></i>
                        Student Evaluation
                    </h3>
                    <button onclick="closeEvaluationModal()" class="text-white hover:text-bulsu-light-gold">
                        <i class="fas fa-times text-xl"></i>
                    </button>
                </div>
            </div>

            <form method="POST" action="" id="evaluationForm">
                <div class="p-6">
                    <!-- Student Information -->
                    <div class="mb-6 bg-gray-50 rounded-lg p-4">
                        <h4 class="text-sm font-semibold text-gray-700 mb-2">Student Information</h4>
                        <div class="grid grid-cols-2 gap-4 text-sm">
                            <div>
                                <p class="text-gray-600">Name:</p>
                                <p class="font-medium" id="modal_student_name"></p>
                            </div>
                            <div>
                                <p class="text-gray-600">Student ID:</p>
                                <p class="font-medium" id="modal_student_id"></p>
                            </div>
                            <div>
                                <p class="text-gray-600">Program:</p>
                                <p class="font-medium" id="modal_program"></p>
                            </div>
                            <div>
                                <p class="text-gray-600">Company:</p>
                                <p class="font-medium" id="modal_company"></p>
                            </div>
                        </div>
                    </div>

                    <input type="hidden" name="student_id" id="student_id_input">
                    <input type="hidden" name="submit_evaluation" value="1">

                    <!-- Evaluation Criteria -->
                    <div class="space-y-4">
                        <!-- Quality of Work -->
                        <div class="border border-gray-200 rounded-lg p-4">
                            <div class="flex items-center justify-between mb-2">
                                <label class="block text-sm font-medium text-gray-700">
                                    <i class="fas fa-check-circle text-blue-600 mr-2"></i>
                                    Quality of Work (40%)
                                </label>
                                <span class="text-xs text-gray-500">Max: 100 points</span>
                            </div>
                            <input type="number" 
       name="quality_of_work" 
       id="quality_of_work" 
       min="0" 
       max="100" 
       step="0.01"
       class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-bulsu-gold focus:ring-bulsu-gold sm:text-sm px-3 py-2 border"
       required
       oninput="calculateTotal()"
       onblur="validateInput('quality_of_work', 100)">
                            <div class="mt-2 text-sm text-gray-600">
                                Percentage: <span id="quality_percentage" class="font-semibold text-blue-600">0.00%</span>
                            </div>
                        </div>

                        <!-- Completeness of Work -->
                        <div class="border border-gray-200 rounded-lg p-4">
                            <div class="flex items-center justify-between mb-2">
                                <label class="block text-sm font-medium text-gray-700">
                                    <i class="fas fa-tasks text-green-600 mr-2"></i>
                                    Completeness of Work (40%)
                                </label>
                                <span class="text-xs text-gray-500">Max: 100 points</span>
                            </div>
                            <input type="number" 
       name="completeness_of_work" 
       id="completeness_of_work" 
       min="0" 
       max="100" 
       step="0.01"
       class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-bulsu-gold focus:ring-bulsu-gold sm:text-sm px-3 py-2 border"
       required
       oninput="calculateTotal()"
       onblur="validateInput('completeness_of_work', 100)">
                            <div class="mt-2 text-sm text-gray-600">
                                Percentage: <span id="completeness_percentage" class="font-semibold text-green-600">0.00%</span>
                            </div>
                        </div>

                        <!-- Urgency of Output -->
                        <div class="border border-gray-200 rounded-lg p-4">
                            <div class="flex items-center justify-between mb-2">
                                <label class="block text-sm font-medium text-gray-700">
                                    <i class="fas fa-clock text-orange-600 mr-2"></i>
                                    Urgency of Output (10%)
                                </label>
                                <span class="text-xs text-gray-500">Max: 10 points</span>
                            </div>
                            <input type="number" 
       name="urgency_of_output" 
       id="urgency_of_output" 
       min="0" 
       max="10" 
       step="0.01"
       class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-bulsu-gold focus:ring-bulsu-gold sm:text-sm px-3 py-2 border"
       required
       oninput="calculateTotal()"
       onblur="validateInput('urgency_of_output', 10)">
                            <div class="mt-2 text-sm text-gray-600">
                                Percentage: <span id="urgency_percentage" class="font-semibold text-orange-600">0.00%</span>
                            </div>
                        </div>

                        <!-- Attendance/Promptness -->
                        <div class="border border-gray-200 rounded-lg p-4">
                            <div class="flex items-center justify-between mb-2">
                                <label class="block text-sm font-medium text-gray-700">
                                    <i class="fas fa-user-check text-purple-600 mr-2"></i>
                                    Attendance/Promptness (10%)
                                </label>
                                <span class="text-xs text-gray-500">Max: 10 points</span>
                            </div>
                            <input type="number" 
       name="attendance_promptness" 
       id="attendance_promptness" 
       min="0" 
       max="10" 
       step="0.01"
       class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-bulsu-gold focus:ring-bulsu-gold sm:text-sm px-3 py-2 border"
       required
       oninput="calculateTotal()"
       onblur="validateInput('attendance_promptness', 10)">
                            <div class="mt-2 text-sm text-gray-600">
                                Percentage: <span id="attendance_percentage" class="font-semibold text-purple-600">0.00%</span>
                            </div>
                        </div>
                    </div>

                    <!-- Total Grade Display -->
                 <div class="mt-6 bg-gradient-to-r from-bulsu-maroon to-bulsu-dark-maroon rounded-lg p-4">
    <div class="flex items-center justify-between text-white">
        <div class="flex-shrink-0">
            <p class="text-sm font-medium whitespace-nowrap">Total Coordinator's Grade</p>
            <p class="text-xs text-bulsu-light-gold">Maximum: 30%</p>
        </div>
        <div class="text-right ml-4">
            <p class="text-3xl font-bold whitespace-nowrap">
                <span id="total_grade">0%</span><span class="text-bulsu-light-gold"> / 30%</span>
            </p>
        </div>
    </div>
    <div class="mt-3 bg-white bg-opacity-20 rounded-full h-2 overflow-hidden">
        <div id="grade_progress" class="bg-bulsu-gold h-2 rounded-full transition-all duration-300" style="width: 0%; max-width: 100%;"></div>
    </div>
</div>
                </div>

                <!-- Modal Footer -->
                <div class="bg-gray-50 px-6 py-4 rounded-b-lg flex justify-end space-x-3">
                    <button type="button" 
                            onclick="closeEvaluationModal()" 
                            class="px-4 py-2 border border-gray-300 rounded-md text-sm font-medium text-gray-700 bg-white hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-bulsu-gold">
                        Cancel
                    </button>
                    <button type="submit" 
                            class="px-4 py-2 border border-transparent rounded-md text-sm font-medium text-white bg-bulsu-maroon hover:bg-bulsu-dark-maroon focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-bulsu-gold">
                        <i class="fas fa-save mr-2"></i>
                        Submit Evaluation
                    </button>
                </div>
            </form>
        </div>
    </div>

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

        mobileMenuBtn.addEventListener('click', toggleSidebar);
        closeSidebar.addEventListener('click', toggleSidebar);
        sidebarOverlay.addEventListener('click', toggleSidebar);

        // Profile dropdown toggle
        const profileBtn = document.getElementById('profileBtn');
        const profileDropdown = document.getElementById('profileDropdown');

        profileBtn.addEventListener('click', (e) => {
            e.stopPropagation();
            profileDropdown.classList.toggle('hidden');
        });

        document.addEventListener('click', () => {
            profileDropdown.classList.add('hidden');
        });

        // Logout confirmation
        function confirmLogout() {
            return confirm('Are you sure you want to logout?');
        }

        // Modal functions
        function openEvaluationModal(student) {
            const modal = document.getElementById('evaluationModal');
            
            // Populate student information
            document.getElementById('modal_student_name').textContent = student.first_name + ' ' + student.last_name;
            document.getElementById('modal_student_id').textContent = student.student_id;
            document.getElementById('modal_program').textContent = student.program + ' - ' + student.section;
            document.getElementById('modal_company').textContent = student.company_name;
            document.getElementById('student_id_input').value = student.id;

            // Populate existing evaluation data if available
            if (student.quality_of_work !== null) {
                document.getElementById('quality_of_work').value = student.quality_of_work;
                document.getElementById('completeness_of_work').value = student.completeness_of_work;
                document.getElementById('urgency_of_output').value = student.urgency_of_output;
                document.getElementById('attendance_promptness').value = student.attendance_promptness;
                calculateTotal();
            } else {
                // Reset form if no existing evaluation
                document.getElementById('evaluationForm').reset();
                document.getElementById('student_id_input').value = student.id;
                calculateTotal();
            }

            modal.classList.add('active');
        }

        function closeEvaluationModal() {
            const modal = document.getElementById('evaluationModal');
            modal.classList.remove('active');
            document.getElementById('evaluationForm').reset();
        }
        // Validate input values
function validateInput(inputId, maxValue) {
    const input = document.getElementById(inputId);
    const value = parseFloat(input.value) || 0;
    
    if (value > maxValue) {
        input.value = maxValue;
        alert(`Maximum value for this field is ${maxValue}. Value has been adjusted.`);
    } else if (value < 0) {
        input.value = 0;
        alert('Value cannot be negative. Value has been set to 0.');
    }
    
    calculateTotal();
}

        // Calculate total grade
      function calculateTotal() {
    // Get input values
    const qualityOfWork = parseFloat(document.getElementById('quality_of_work').value) || 0;
    const completenessOfWork = parseFloat(document.getElementById('completeness_of_work').value) || 0;
    const urgencyOfOutput = parseFloat(document.getElementById('urgency_of_output').value) || 0;
    const attendancePromptness = parseFloat(document.getElementById('attendance_promptness').value) || 0;

    // Calculate points (not percentages!)
    // Quality of Work: 100 points input = 40 points max
    const qualityPoints = (qualityOfWork / 100) * 40;
    
    // Completeness of Work: 100 points input = 40 points max
    const completenessPoints = (completenessOfWork / 100) * 40;
    
    // Urgency of Output: 10 points input = 10 points max
    const urgencyPoints = urgencyOfOutput;
    
    // Attendance/Promptness: 10 points input = 10 points max
    const attendancePoints = attendancePromptness;

    // Calculate total points (max 100 points)
    const totalPoints = qualityPoints + completenessPoints + urgencyPoints + attendancePoints;
    
    // Convert to percentage (100 points = 30%)
    const totalPercentage = (totalPoints / 100) * 30;

    // Update individual point displays (not percentages)
    document.getElementById('quality_percentage').textContent = qualityPoints.toFixed(2) + ' pts';
    document.getElementById('completeness_percentage').textContent = completenessPoints.toFixed(2) + ' pts';
    document.getElementById('urgency_percentage').textContent = urgencyPoints.toFixed(2) + ' pts';
    document.getElementById('attendance_percentage').textContent = attendancePoints.toFixed(2) + ' pts';
    
    // Update total grade display
    document.getElementById('total_grade').textContent = totalPercentage.toFixed(2) + '%';

    // Calculate progress bar percentage
    const progressPercentage = (totalPercentage / 30) * 100;
    document.getElementById('grade_progress').style.width = Math.min(progressPercentage, 100).toFixed(2) + '%';
}

        // Close modal when clicking outside
        window.onclick = function(event) {
            const modal = document.getElementById('evaluationModal');
            if (event.target == modal) {
                closeEvaluationModal();
            }
        }

        // Initialize calculation on page load
        document.addEventListener('DOMContentLoaded', function() {
            calculateTotal();
        });
    </script>
</body>
</html>