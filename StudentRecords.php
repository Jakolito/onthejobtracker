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

// Create adviser initials
$name_parts = explode(' ', trim($adviser_name));
if (count($name_parts) >= 2) {
    $adviser_initials = strtoupper(substr($name_parts[0], 0, 1) . substr($name_parts[count($name_parts)-1], 0, 1));
} else {
    $adviser_initials = strtoupper(substr($adviser_name, 0, 2));
}

// Get unread messages count
$unread_messages_query = "SELECT COUNT(*) as count FROM messages WHERE recipient_type = 'adviser' AND sender_type = 'student' AND is_read = 0 AND is_deleted_by_recipient = 0";
$unread_messages_result = mysqli_query($conn, $unread_messages_query);
$unread_messages_count = mysqli_fetch_assoc($unread_messages_result)['count'];

// Initialize variables
$students = [];
$departments = [];
$sections = [];
$error_message = '';

// Enhanced filter parameters - same as first code
$search = isset($_GET['search']) ? mysqli_real_escape_string($conn, $_GET['search']) : '';
$department_filter = isset($_GET['department']) ? mysqli_real_escape_string($conn, $_GET['department']) : '';
$section_filter = isset($_GET['section']) ? mysqli_real_escape_string($conn, $_GET['section']) : '';

// Pagination settings
$records_per_page = 10; // You can adjust this as needed
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$offset = ($page - 1) * $records_per_page;

try {
    // Get unique departments for filter dropdown
    $departments_query = "SELECT DISTINCT department FROM students WHERE department IS NOT NULL AND department != '' AND verified = 1 ORDER BY department";
    $departments_result = mysqli_query($conn, $departments_query);
    if ($departments_result) {
        $departments = mysqli_fetch_all($departments_result, MYSQLI_ASSOC);
    }

    // Get unique sections for filter dropdown  
    $sections_query = "SELECT DISTINCT section FROM students WHERE section IS NOT NULL AND section != '' AND verified = 1 ORDER BY section";
    $sections_result = mysqli_query($conn, $sections_query);
    if ($sections_result) {
        $sections = mysqli_fetch_all($sections_result, MYSQLI_ASSOC);
    }

    // Build WHERE conditions - same logic as first code
    $where_conditions = array();

    if (!empty($search)) {
        $where_conditions[] = "(s.first_name LIKE '%$search%' OR s.last_name LIKE '%$search%' OR s.email LIKE '%$search%' OR s.student_id LIKE '%$search%')";
    }

    if (!empty($department_filter)) {
        $where_conditions[] = "s.department = '$department_filter'";
    }

    if (!empty($section_filter)) {
        $where_conditions[] = "s.section = '$section_filter'";
    }



    // Base condition: show verified students
    $base_condition = "s.verified = 1";

    // Combine base condition with filter conditions
    if (count($where_conditions) > 0) {
        $where_clause = $base_condition . " AND (" . implode(' AND ', $where_conditions) . ")";
    } else {
        $where_clause = $base_condition;
    }

    // Count query for pagination
    $count_query = "SELECT COUNT(*) as total FROM students s 
    LEFT JOIN student_deployments sd ON s.id = sd.student_id AND sd.status = 'Active'
    WHERE $where_clause";

    $count_result = mysqli_query($conn, $count_query);
    $total_records = mysqli_fetch_assoc($count_result)['total'];
    $total_pages = ceil($total_records / $records_per_page);

    // Enhanced main query with all the filtering
    $query = "SELECT 
        s.id,
        s.student_id,
        s.first_name,
        s.middle_name,
        s.last_name,
        s.email,
        s.contact_number,
        s.department,
        s.program,
        s.year_level,
        s.section,
        s.status,
        s.verified,
        s.ready_for_deployment,
        s.login_attempts,
        s.created_at,
        s.last_login,
        sd.deployment_id,
        COALESCE(sd.company_name, 'Not Deployed') as company_name,
        COALESCE(sd.status, 'No Deployment') as deployment_status,
        COALESCE(sd.position, '') as deployment_position,
        sd.supervisor_name as deployed_supervisor,
        sd.start_date,
        sd.end_date,
        sd.company_address,
        sd.company_contact,
        sd.supervisor_position as deployed_supervisor_position,
        sd.supervisor_email,
        sd.supervisor_phone,
        sd.required_hours,
        sd.work_days,
        COALESCE(sd.completed_hours, 0) as completed_hours,
        sd.created_at as deployment_date
    FROM students s
    LEFT JOIN student_deployments sd ON s.id = sd.student_id 
        AND sd.status = 'Active'
    WHERE $where_clause
    ORDER BY 
        CASE WHEN s.status = 'Deployed' THEN 0 ELSE 1 END,
        sd.created_at DESC,
        s.last_name, 
        s.first_name
    LIMIT $records_per_page OFFSET $offset";
    
    $result = mysqli_query($conn, $query);
    if ($result) {
        $students = mysqli_fetch_all($result, MYSQLI_ASSOC);
    } else {
        throw new Exception("Error executing query: " . mysqli_error($conn));
    }
    
} catch (Exception $e) {
    $error_message = "Database error: " . $e->getMessage();
    error_log($error_message); // Log the error for debugging
}

// Helper function to get student status - same as first code
function getStudentStatus($student) {
    // Check if student is deployed first
    if ($student['deployment_id'] !== null) {
        return ['status' => 'deployed', 'text' => 'Deployed'];
    } elseif ($student['ready_for_deployment'] == 1) {
        return ['status' => 'ready', 'text' => 'Ready for Deployment'];
    } elseif ($student['verified'] == 0) {
        return ['status' => 'unverified', 'text' => 'Unverified'];
    } elseif ($student['login_attempts'] >= 3 || $student['status'] == 'Blocked') {
        return ['status' => 'blocked', 'text' => 'Blocked'];
    } elseif ($student['status'] == 'Active') {
        return ['status' => 'active', 'text' => 'Active'];
    } else {
        return ['status' => 'inactive', 'text' => 'Inactive'];
    }
}

// Get statistics for dashboard
$stats_query = "SELECT 
    (SELECT COUNT(*) FROM students WHERE verified = 1) as total_verified,
    (SELECT COUNT(*) FROM students WHERE ready_for_deployment = 1 AND verified = 1 AND status != 'Deployed' AND id NOT IN (SELECT student_id FROM student_deployments WHERE status = 'Active')) as ready_count,
    (SELECT COUNT(*) FROM student_deployments WHERE status = 'Active') as deployed_count,
    (SELECT COUNT(*) FROM students WHERE verified = 0) as unverified_count,
    (SELECT COUNT(*) FROM students WHERE status = 'Blocked' OR login_attempts >= 3) as blocked_count";
$stats_result = mysqli_query($conn, $stats_query);
$stats = mysqli_fetch_assoc($stats_result);

// Handle AJAX requests for student details
if (isset($_GET['action']) && $_GET['action'] === 'get_student_details' && isset($_GET['student_id'])) {
    $student_id = intval($_GET['student_id']);
    
    if ($student_id > 0) {
        try {
            // Get complete student information
            $student_query = "SELECT * FROM students WHERE id = ? AND verified = 1";
            $student_stmt = mysqli_prepare($conn, $student_query);
            mysqli_stmt_bind_param($student_stmt, "i", $student_id);
            mysqli_stmt_execute($student_stmt);
            $student_result = mysqli_stmt_get_result($student_stmt);
            $student_data = mysqli_fetch_assoc($student_result);
            mysqli_stmt_close($student_stmt);
            
            if (!$student_data) {
                throw new Exception("Student not found");
            }
            
            // Get deployment information with company supervisor details
            $deployment_query = "SELECT 
                sd.*,
                cs.full_name as supervisor_full_name,
                cs.email as supervisor_email,
                cs.phone_number as supervisor_phone,
                cs.position as supervisor_position,
                cs.company_address as company_address,
                cs.company_contact_number as company_contact_number,
                cs.industry_field,
                cs.work_mode,
                cs.work_schedule_start,
                cs.work_schedule_end,
                cs.work_days as company_work_days
            FROM student_deployments sd
            LEFT JOIN company_supervisors cs ON sd.supervisor_id = cs.supervisor_id
            WHERE sd.student_id = ?
            ORDER BY sd.created_at DESC";
            $deployment_stmt = mysqli_prepare($conn, $deployment_query);
            mysqli_stmt_bind_param($deployment_stmt, "i", $student_id);
            mysqli_stmt_execute($deployment_stmt);
            $deployment_result = mysqli_stmt_get_result($deployment_stmt);
            $deployments = mysqli_fetch_all($deployment_result, MYSQLI_ASSOC);
            mysqli_stmt_close($deployment_stmt);
            
            // Get attendance records
            $attendance_query = "SELECT 
                sa.*,
                sd.company_name,
                COALESCE(sa.total_hours, 0) as hours_worked
            FROM student_attendance sa
            LEFT JOIN student_deployments sd ON sa.deployment_id = sd.deployment_id
            WHERE sa.student_id = ?
            ORDER BY sa.date DESC
            LIMIT 20";
            $attendance_stmt = mysqli_prepare($conn, $attendance_query);
            mysqli_stmt_bind_param($attendance_stmt, "i", $student_id);
            mysqli_stmt_execute($attendance_stmt);
            $attendance_result = mysqli_stmt_get_result($attendance_stmt);
            $attendance_records = mysqli_fetch_all($attendance_result, MYSQLI_ASSOC);
            mysqli_stmt_close($attendance_stmt);
            
            // Get document requirements
            $documents = [];
            $doc_query = "SELECT 
                dr.name as requirement_name,
                dr.description,
                dr.is_required,
                COALESCE(sd.status, 'Pending') as status,
                sd.submitted_at,
                sd.reviewed_at,
                sd.feedback
            FROM document_requirements dr
            LEFT JOIN student_documents sd ON dr.id = sd.document_id 
                AND sd.student_id = ?
            ORDER BY dr.is_required DESC, dr.name ASC";

            if ($doc_stmt = mysqli_prepare($conn, $doc_query)) {
                mysqli_stmt_bind_param($doc_stmt, "i", $student_id);
                mysqli_stmt_execute($doc_stmt);
                $doc_result = mysqli_stmt_get_result($doc_stmt);
                $documents = mysqli_fetch_all($doc_result, MYSQLI_ASSOC);
                mysqli_stmt_close($doc_stmt);
            }
            
            // Return JSON response
            header('Content-Type: application/json');
            echo json_encode([
                'success' => true,
                'student' => $student_data,
                'deployments' => $deployments,
                'attendance' => $attendance_records,
                'documents' => $documents
            ]);
            
        } catch (Exception $e) {
            header('Content-Type: application/json');
            echo json_encode([
                'success' => false,
                'error' => 'Error fetching student details: ' . $e->getMessage()
            ]);
        }
    } else {
        header('Content-Type: application/json');
        echo json_encode([
            'success' => false,
            'error' => 'Invalid student ID'
        ]);
    }
    exit;
}

// Fetch adviser profile picture
try {
    $adviser_query = "SELECT profile_picture FROM Academic_Adviser WHERE id = ?";
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
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Student Records - OnTheJob Tracker</title>
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

        /* Modal animations */
        .modal {
            animation: fadeIn 0.3s ease-out;
        }

        @keyframes fadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
        }

        .modal-content {
            animation: slideIn 0.3s ease-out;
        }

        @keyframes slideIn {
            from { 
                transform: translateY(-50px); 
                opacity: 0; 
            }
            to { 
                transform: translateY(0); 
                opacity: 1; 
            }
        }

        /* Table scroll styling */
        .table-container {
            scrollbar-width: thin;
            scrollbar-color: #CBD5E0 #EDF2F7;
        }

        .table-container::-webkit-scrollbar {
            height: 6px;
        }

        .table-container::-webkit-scrollbar-track {
            background: #EDF2F7;
            border-radius: 3px;
        }

        .table-container::-webkit-scrollbar-thumb {
            background: #CBD5E0;
            border-radius: 3px;
        }

        .table-container::-webkit-scrollbar-thumb:hover {
            background: #A0AEC0;
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
            <a href="StudentRecords.php" class="nav-item flex items-center px-3 py-2 text-sm font-medium text-white bg-bulsu-gold bg-opacity-20 border border-bulsu-gold border-opacity-30 rounded-md">
                <i class="fas fa-folder-open mr-3 text-bulsu-gold"></i>
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
                    <span class="notification-badge" id="sidebar-notification-badge">
                        <?php echo $unread_messages_count; ?>
                    </span>
                <?php endif; ?>
            </a>
            <a href="academicAdviserEdit.php" class="nav-item flex items-center px-3 py-2 text-sm font-medium text-bulsu-light-gold hover:text-white hover:bg-bulsu-gold hover:bg-opacity-20 rounded-md transition-all duration-200">
                <i class="fas fa-edit mr-3"></i>
                Edit Document
            </a>
        </nav>
    </div>
    
    <!-- User Profile -->
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
                <p class="text-xs text-bulsu-light-gold">Academic Adviser</p>
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
                    <h1 class="text-xl sm:text-2xl font-bold text-gray-900">Student Records</h1>
                    <p class="text-sm sm:text-base text-gray-500 hidden sm:block">Comprehensive student information and tracking</p>
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
            <!-- Error Message Display -->
            <?php if (!empty($error_message)): ?>
                <div class="mb-6 p-4 bg-red-50 border border-red-200 rounded-lg">
                    <div class="flex items-start">
                        <i class="fas fa-exclamation-triangle text-red-600 mt-1 mr-3"></i>
                        <p class="text-red-700"><?php echo htmlspecialchars($error_message); ?></p>
                    </div>
                </div>
            <?php endif; ?>
             <!-- Filters Section -->
            <div class="bg-white rounded-lg shadow-sm border border-gray-200 mb-6">
                <div class="p-4 sm:p-6">
                    <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">Search Students</label>
                            <div class="relative">
                                <input type="text" id="searchInput" 
                                       class="w-full pl-10 pr-4 py-2 border border-gray-300 rounded-md focus:ring-blue-500 focus:border-blue-500" 
                                       placeholder="Search by name, email, or ID..." 
                                       value="<?php echo htmlspecialchars($search); ?>">
                                <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                                    <i class="fas fa-search text-gray-400"></i>
                                </div>
                            </div>
                        </div>

                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">Filter by Department</label>
                            <select id="departmentFilter" class="w-full px-3 py-2 border border-gray-300 rounded-md focus:ring-blue-500 focus:border-blue-500">
                                <option value="">All Departments</option>
                                <?php 
                                mysqli_data_seek($departments_result, 0); // Reset result pointer
                                while ($dept = mysqli_fetch_assoc($departments_result)): ?>
                                    <option value="<?php echo htmlspecialchars($dept['department']); ?>" 
                                            <?php echo $department_filter === $dept['department'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($dept['department']); ?>
                                    </option>
                                <?php endwhile; ?>
                            </select>
                        </div>
                        <div>
    <label class="block text-sm font-medium text-gray-700 mb-2">Filter by Section</label>
    <select id="sectionFilter" class="w-full px-3 py-2 border border-gray-300 rounded-md focus:ring-blue-500 focus:border-blue-500">
        <option value="">All Sections</option>
        <?php 
        mysqli_data_seek($sections_result, 0);
        while ($sect = mysqli_fetch_assoc($sections_result)): ?>
            <option value="<?php echo htmlspecialchars($sect['section']); ?>" 
                    <?php echo $section_filter === $sect['section'] ? 'selected' : ''; ?>>
                <?php echo htmlspecialchars($sect['section']); ?>
            </option>
        <?php endwhile; ?>
    </select>
</div>


                        
                    </div>
                </div>
        
            
</div>

            <!-- Records Container -->
           <div class="bg-white rounded-lg shadow-sm border border-gray-200">
    <!-- Header Section -->
    <div class="p-4 sm:p-6 border-b border-gray-200 bg-gradient-to-r from-bulsu-maroon to-bulsu-dark-maroon rounded-t-lg">
        <div class="flex flex-col lg:flex-row lg:items-center lg:justify-between gap-4">
            <div>
                <h3 class="text-lg font-medium text-white mb-1 flex items-center">
                    <i class="fas fa-folder-open text-bulsu-gold mr-2"></i>
                    Student Records Management
                </h3>
                <p class="text-sm text-bulsu-light-gold">
                    <?php echo count($students); ?> students found
                </p>
            </div>
            
        </div>
    </div>
</div>


                
                <!-- Table Section -->
                <div class="overflow-x-auto table-container">
                    <table class="w-full">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Student ID</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Name</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Department</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Program</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Year Level</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Deployment</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="bg-white divide-y divide-gray-200">
                            <?php if (!empty($students)): ?>
                                <?php foreach ($students as $student): ?>
                                    <tr class="hover:bg-gray-50">
                                        <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900">
                                            <?php echo htmlspecialchars($student['student_id']); ?>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap">
                                            <div class="text-sm font-medium text-gray-900">
                                                <?php echo htmlspecialchars($student['first_name'] . ' ' . $student['last_name']); ?>
                                            </div>
                                            <div class="text-sm text-gray-500"><?php echo htmlspecialchars($student['email']); ?></div>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                            <?php echo htmlspecialchars($student['department']); ?>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                            <?php echo htmlspecialchars($student['program']); ?>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                            <?php echo htmlspecialchars($student['year_level']); ?>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap">
                                            <div class="flex flex-col space-y-1">
                                                <?php
                                                $deployment_status = strtolower(str_replace(' ', '-', $student['deployment_status']));
                                                $badge_classes = [
                                                    'active' => 'bg-blue-100 text-blue-800',
                                                    'completed' => 'bg-green-100 text-green-800',
                                                    'no-deployment' => 'bg-gray-100 text-gray-800',
                                                    'on-hold' => 'bg-yellow-100 text-yellow-800'
                                                ];
                                                $badge_class = $badge_classes[$deployment_status] ?? 'bg-gray-100 text-gray-800';
                                                ?>
                                                <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium <?php echo $badge_class; ?>">
                                                    <?php echo htmlspecialchars($student['deployment_status']); ?>
                                                </span>
                                                <?php if ($student['company_name'] !== 'Not Deployed'): ?>
                                                    <span class="text-xs text-gray-500"><?php echo htmlspecialchars($student['company_name']); ?></span>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm font-medium">
                                            <button 
                                                onclick="viewStudentDetails(<?php echo $student['id']; ?>)"
                                                class="inline-flex items-center px-3 py-1.5 border border-transparent text-xs font-medium rounded text-white bg-blue-600 hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500 transition-colors">
                                                <i class="fas fa-eye mr-1"></i>
                                                View Details
                                            </button>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="7" class="px-6 py-12 text-center">
                                        <div class="text-gray-400">
                                            <i class="fas fa-users text-3xl mb-3"></i>
                                            <div class="text-sm font-medium text-gray-900 mb-1">No student records found</div>
                                            <div class="text-sm text-gray-500">No students match your current search criteria</div>
                                        </div>
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

       <!-- Pagination -->
           <?php if ($total_pages > 1): ?>
    <div class="mt-6 flex items-center justify-center">
        <nav class="relative z-0 inline-flex rounded-md shadow-sm -space-x-px">
            <?php if ($page > 1): ?>
                <a href="?page=<?php echo ($page - 1); ?>&search=<?php echo urlencode($search); ?>&department=<?php echo urlencode($department_filter); ?>&section=<?php echo urlencode($section_filter); ?>" 
                   class="relative inline-flex items-center px-3 py-2 rounded-l-md border border-gray-300 bg-white text-sm font-medium text-gray-500 hover:bg-gray-50">
                    <i class="fas fa-chevron-left mr-1"></i>
                    Previous
                </a>
            <?php endif; ?>
            
            <?php
            $start_page = max(1, $page - 2);
            $end_page = min($total_pages, $page + 2);
            
            for ($i = $start_page; $i <= $end_page; $i++): ?>
                <a href="?page=<?php echo $i; ?>&search=<?php echo urlencode($search); ?>&department=<?php echo urlencode($department_filter); ?>&section=<?php echo urlencode($section_filter); ?>" 
                   class="relative inline-flex items-center px-4 py-2 border border-gray-300 bg-white text-sm font-medium <?php echo $i == $page ? 'text-blue-600 bg-blue-50 border-blue-500' : 'text-gray-700 hover:bg-gray-50'; ?>">
                    <?php echo $i; ?>
                </a>
            <?php endfor; ?>
            
            <?php if ($page < $total_pages): ?>
                <a href="?page=<?php echo ($page + 1); ?>&search=<?php echo urlencode($search); ?>&department=<?php echo urlencode($department_filter); ?>&section=<?php echo urlencode($section_filter); ?>" 
                   class="relative inline-flex items-center px-3 py-2 rounded-r-md border border-gray-300 bg-white text-sm font-medium text-gray-500 hover:bg-gray-50">
                    Next
                    <i class="fas fa-chevron-right ml-1"></i>
                </a>
            <?php endif; ?>
        </nav>
    </div>
<?php endif; ?>
        </div>
    </div>


    <!-- Student Details Modal -->
    <div id="studentModal" class="fixed inset-0 bg-black bg-opacity-50 z-50 hidden modal">
        <div class="min-h-screen px-4 text-center">
            <div class="fixed inset-0" onclick="closeModal()"></div>
            
            <!-- This element is to trick the browser into centering the modal contents. -->
            <span class="hidden sm:inline-block sm:align-middle sm:h-screen">&#8203;</span>
            
            <div class="inline-block align-bottom bg-white rounded-lg text-left overflow-hidden shadow-xl transform transition-all sm:my-8 sm:align-middle sm:max-w-6xl sm:w-full modal-content">
                <!-- Modal Header -->
                <div class="bg-gray-50 px-4 sm:px-6 py-4 border-b border-gray-200">
                    <div class="flex items-center justify-between">
                        <h3 class="text-lg font-medium text-gray-900">Student Details</h3>
                        <button onclick="closeModal()" class="text-gray-400 hover:text-gray-600">
                            <i class="fas fa-times text-xl"></i>
                        </button>
                    </div>
                </div>
                
                <!-- Modal Body -->
                <div class="max-h-96 sm:max-h-screen-75 overflow-y-auto">
                    <!-- Loading State -->
                    <div id="modalLoading" class="p-8 text-center">
                        <i class="fas fa-spinner fa-spin text-2xl text-blue-600 mb-3"></i>
                        <p class="text-gray-600">Loading student details...</p>
                    </div>
                    
                    <!-- Modal Content -->
                    <div id="modalContent" style="display: none;">
                        <!-- Tab Navigation -->
                        <div class="border-b border-gray-200">
    <nav class="flex space-x-8 px-4 sm:px-6" aria-label="Tabs">
        <button type="button" class="tab-button py-4 px-1 border-b-2 font-medium text-sm border-blue-500 text-blue-600">
            Personal Info
        </button>
        <button type="button" class="tab-button py-4 px-1 border-b-2 font-medium text-sm border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300">
            Deployment
        </button>
        <button type="button" class="tab-button py-4 px-1 border-b-2 font-medium text-sm border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300">
            Attendance
        </button>
        <button type="button" class="tab-button py-4 px-1 border-b-2 font-medium text-sm border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300">
            Documents
        </button>
    </nav>
</div>
                        
                        <div class="p-4 sm:p-6">
                            <!-- Personal Information Tab -->
                            <div id="personalTab" class="tab-content">
                                <div class="space-y-6">
                                    <div>
                                        <h4 class="text-lg font-medium text-gray-900 mb-4">Basic Information</h4>
                                        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                                            <div class="bg-gray-50 rounded-lg p-4">
                                                <div class="text-xs font-medium text-gray-500 uppercase tracking-wide mb-1">Student ID</div>
                                                <div class="text-sm font-medium text-gray-900" id="studentId">-</div>
                                            </div>
                                            <div class="bg-gray-50 rounded-lg p-4">
                                                <div class="text-xs font-medium text-gray-500 uppercase tracking-wide mb-1">Full Name</div>
                                                <div class="text-sm font-medium text-gray-900" id="fullName">-</div>
                                            </div>
                                            <div class="bg-gray-50 rounded-lg p-4">
                                                <div class="text-xs font-medium text-gray-500 uppercase tracking-wide mb-1">Email</div>
                                                <div class="text-sm font-medium text-gray-900" id="email">-</div>
                                            </div>
                                            <div class="bg-gray-50 rounded-lg p-4">
                                                <div class="text-xs font-medium text-gray-500 uppercase tracking-wide mb-1">Contact Number</div>
                                                <div class="text-sm font-medium text-gray-900" id="contactNumber">-</div>
                                            </div>
                                            <div class="bg-gray-50 rounded-lg p-4">
                                                <div class="text-xs font-medium text-gray-500 uppercase tracking-wide mb-1">Address</div>
                                                <div class="text-sm font-medium text-gray-900" id="address">-</div>
                                            </div>
                                            <div class="bg-gray-50 rounded-lg p-4">
                                                <div class="text-xs font-medium text-gray-500 uppercase tracking-wide mb-1">Department</div>
                                                <div class="text-sm font-medium text-gray-900" id="department">-</div>
                                            </div>
                                            <div class="bg-gray-50 rounded-lg p-4">
                                                <div class="text-xs font-medium text-gray-500 uppercase tracking-wide mb-1">Program</div>
                                                <div class="text-sm font-medium text-gray-900" id="program">-</div>
                                            </div>
                                            <div class="bg-gray-50 rounded-lg p-4">
                                                <div class="text-xs font-medium text-gray-500 uppercase tracking-wide mb-1">Year Level</div>
                                                <div class="text-sm font-medium text-gray-900" id="yearLevel">-</div>
                                            </div>
                                            <div class="bg-gray-50 rounded-lg p-4">
                                                <div class="text-xs font-medium text-gray-500 uppercase tracking-wide mb-1">Section</div>
                                                <div class="text-sm font-medium text-gray-900" id="section">-</div>
                                            </div>
                                            <div class="bg-gray-50 rounded-lg p-4">
                                                <div class="text-xs font-medium text-gray-500 uppercase tracking-wide mb-1">Ready for Deployment</div>
                                                <div class="text-sm font-medium text-gray-900" id="readyForDeployment">-</div>
                                            </div>
                                            <div class="bg-gray-50 rounded-lg p-4">
                                                <div class="text-xs font-medium text-gray-500 uppercase tracking-wide mb-1">Date Registered</div>
                                                <div class="text-sm font-medium text-gray-900" id="dateRegistered">-</div>
                                            </div>
                                            <div class="bg-gray-50 rounded-lg p-4">
                                                <div class="text-xs font-medium text-gray-500 uppercase tracking-wide mb-1">Last Login</div>
                                                <div class="text-sm font-medium text-gray-900" id="lastLogin">-</div>
                                            </div>
                                            <div class="bg-gray-50 rounded-lg p-4">
                                                <div class="text-xs font-medium text-gray-500 uppercase tracking-wide mb-1">Gender</div>
                                                <div class="text-sm font-medium text-gray-900" id="gender">-</div>
                                            </div>
                                            <div class="bg-gray-50 rounded-lg p-4">
                                                <div class="text-xs font-medium text-gray-500 uppercase tracking-wide mb-1">Date of Birth</div>
                                                <div class="text-sm font-medium text-gray-900" id="dateOfBirth">-</div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            
                            <!-- Deployment Tab -->
                            <div id="deploymentTab" class="tab-content" style="display: none;">
                                <div>
                                    <h4 class="text-lg font-medium text-gray-900 mb-4">Deployment History</h4>
                                    <div id="deploymentHistory">
                                        <div class="text-center py-8 text-gray-500">
                                            <i class="fas fa-briefcase text-2xl mb-2"></i>
                                            <p>No deployment records found</p>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            
                            <!-- Attendance Tab -->
                            <div id="attendanceTab" class="tab-content" style="display: none;">
                                <div>
                                    <h4 class="text-lg font-medium text-gray-900 mb-4">Recent Attendance Records</h4>
                                    <div class="overflow-x-auto">
                                        <table class="min-w-full divide-y divide-gray-200">
                                            <thead class="bg-gray-50">
                                                <tr>
                                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Date</th>
                                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Time In</th>
                                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Time Out</th>
                                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Hours</th>
                                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Company</th>
                                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Status</th>
                                                </tr>
                                            </thead>
                                            <tbody id="attendanceTableBody" class="bg-white divide-y divide-gray-200">
                                                <tr>
                                                    <td colspan="6" class="px-6 py-8 text-center text-gray-500">
                                                        <i class="fas fa-clock text-2xl mb-2"></i>
                                                        <p>No attendance records found</p>
                                                    </td>
                                                </tr>
                                            </tbody>
                                        </table>
                                    </div>
                                </div>  
                            </div>
                            
                            <!-- Documents Tab -->
                            <div id="documentsTab" class="tab-content" style="display: none;">
                                <div>
                                    <h4 class="text-lg font-medium text-gray-900 mb-4">Document Requirements</h4>
                                    <div class="overflow-x-auto">
                                        <table class="min-w-full divide-y divide-gray-200">
                                            <thead class="bg-gray-50">
                                                <tr>
                                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Document</th>
                                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Description</th>
                                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Required</th>
                                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Status</th>
                                                </tr>
                                            </thead>
                                            <tbody id="documentsTableBody" class="bg-white divide-y divide-gray-200">
                                                <tr>
                                                    <td colspan="4" class="px-6 py-8 text-center text-gray-500">
                                                        <i class="fas fa-file-alt text-2xl mb-2"></i>
                                                        <p>No document requirements found</p>
                                                    </td>
                                                </tr>
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Error State -->
                    <div id="modalError" style="display: none;" class="p-6">
                        <div class="bg-red-50 border border-red-200 rounded-lg p-4">
                            <div class="flex items-start">
                                <i class="fas fa-exclamation-triangle text-red-600 mt-1 mr-3"></i>
                                <p class="text-red-700" id="errorMessage">Error loading student details</p>
                            </div>
                        </div>
                    </div>
                </div>
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

       // Replace the existing filter JavaScript code with this corrected version:

document.getElementById('searchInput').addEventListener('keyup', function(e) {
    if (e.key === 'Enter') {
        applyFilters();
    }
});

document.getElementById('departmentFilter').addEventListener('change', applyFilters);
document.getElementById('sectionFilter').addEventListener('change', applyFilters); // This was missing!

// Updated filter functionality
function applyFilters() {
    const search = document.getElementById('searchInput').value;
    const department = document.getElementById('departmentFilter').value;
    const section = document.getElementById('sectionFilter').value;
    
    const params = new URLSearchParams();
    if (search) params.append('search', search);
    if (department) params.append('department', department);
    if (section) params.append('section', section);
    
    window.location.href = `${window.location.pathname}?${params.toString()}`;
}

// Enhanced event listeners setup
document.addEventListener('DOMContentLoaded', function() {
    // Search input with Enter key support
    const searchInput = document.getElementById('searchInput');
    if (searchInput) {
        searchInput.addEventListener('keyup', function(e) {
            if (e.key === 'Enter') {
                applyFilters();
            }
        });
    }

    // All dropdown filters with immediate change detection
    const filters = ['departmentFilter', 'sectionFilter'];
    filters.forEach(filterId => {
        const filterElement = document.getElementById(filterId);
        if (filterElement) {
            filterElement.addEventListener('change', applyFilters);
        }
    });

    // Tab functionality
    const tabButtons = document.querySelectorAll('.tab-button');
    tabButtons.forEach((button, index) => {
        button.addEventListener('click', function(e) {
            e.preventDefault();
            const tabNames = ['personal', 'deployment', 'attendance', 'documents'];
            const tabName = tabNames[index];
            if (tabName) {
                showTab(tabName);
            }
        });
    });
    
    // Initialize first tab as active
    resetToFirstTab();
});

       function viewStudentDetails(studentId) {
    const modal = document.getElementById('studentModal');
    const modalLoading = document.getElementById('modalLoading');
    const modalContent = document.getElementById('modalContent');
    const modalError = document.getElementById('modalError');
    
    // Validate student ID
    if (!studentId || isNaN(studentId)) {
        console.error('Invalid student ID:', studentId);
        return;
    }
    
    // Show modal and loading state
    modal.classList.remove('hidden');
    modalLoading.style.display = 'block';
    modalContent.style.display = 'none';
    modalError.style.display = 'none';
    document.body.style.overflow = 'hidden';
    
    // Reset to first tab
    resetToFirstTab();
    
    // Fetch student details with improved error handling
    fetch(`?action=get_student_details&student_id=${encodeURIComponent(studentId)}`)
        .then(response => {
            if (!response.ok) {
                throw new Error(`HTTP error! status: ${response.status}`);
            }
            return response.json();
        })
        .then(data => {
            modalLoading.style.display = 'none';
            
            if (data.success) {
                populateStudentDetails(data);
                modalContent.style.display = 'block';
            } else {
                showModalError(data.error || 'Unknown error occurred');
            }
        })
        .catch(error => {
            console.error('Fetch error:', error);
            modalLoading.style.display = 'none';
            showModalError('Network error: Unable to load student details. Please try again.');
        });
}
        
        function populateStudentDetails(data) {
    const student = data.student;
    
    // Helper function to safely set text content
    const safeSetText = (id, value, fallback = '-') => {
        const element = document.getElementById(id);
        if (element) {
            element.textContent = value || fallback;
        }
    };
    
    // Populate personal information with null checks
    safeSetText('studentId', student.student_id);
    safeSetText('fullName', 
        `${student.first_name || ''} ${student.middle_name || ''} ${student.last_name || ''}`.trim());
    safeSetText('email', student.email);
    safeSetText('contactNumber', student.contact_number);
    safeSetText('address', student.address);
    safeSetText('department', student.department);
    safeSetText('program', student.program);
    safeSetText('yearLevel', student.year_level);
    safeSetText('section', student.section);
    safeSetText('status', student.status);
    safeSetText('readyForDeployment', student.ready_for_deployment ? 'Yes' : 'No');
    
    // Format and set dates safely
    safeSetText('dateRegistered', 
        student.created_at ? formatDate(student.created_at) : '-');
    safeSetText('lastLogin', 
        student.last_login ? formatDateTime(student.last_login) : 'Never');
    safeSetText('dateOfBirth', 
        student.date_of_birth ? formatDate(student.date_of_birth) : '-');
    safeSetText('gender', 
        student.gender ? capitalizeFirst(student.gender) : '-');
    
    // Populate other sections
    populateDeploymentHistory(data.deployments || []);
    populateAttendanceRecords(data.attendance || []);
    populateDocumentRequirements(data.documents || []);
}
function formatDate(dateString) {
    try {
        return new Date(dateString).toLocaleDateString();
    } catch (e) {
        return dateString;
    }
}

function formatDateTime(dateString) {
    try {
        return new Date(dateString).toLocaleString();
    } catch (e) {
        return dateString;
    }
}

function capitalizeFirst(str) {
    return str.charAt(0).toUpperCase() + str.slice(1).toLowerCase();
}

        
        function resetToFirstTab() {
    // Hide all tabs first
    const tabs = document.querySelectorAll('.tab-content');
    tabs.forEach(tab => tab.style.display = 'none');
    
    // Remove active class from all buttons
    const tabButtons = document.querySelectorAll('.tab-button');
    tabButtons.forEach(button => {
        button.classList.remove('border-blue-500', 'text-blue-600');
        button.classList.add('border-transparent', 'text-gray-500');
    });
    
    // Show first tab (personal)
    const firstTab = document.getElementById('personalTab');
    if (firstTab) {
        firstTab.style.display = 'block';
    }
    
    // Activate first button
    const firstButton = document.querySelector('.tab-button');
    if (firstButton) {
        firstButton.classList.remove('border-transparent', 'text-gray-500');
        firstButton.classList.add('border-blue-500', 'text-blue-600');
    }
}
        function populateDeploymentHistory(deployments) {
    const container = document.getElementById('deploymentHistory');
    
    if (!deployments || deployments.length === 0) {
        container.innerHTML = `
            <div class="text-center py-8 text-gray-500">
                <i class="fas fa-briefcase text-2xl mb-2"></i>
                <p>No deployment records found</p>
            </div>
        `;
        return;
    }
    
    let html = '<div class="space-y-6">';
    deployments.forEach(deployment => {
        const startDate = deployment.start_date ? formatDate(deployment.start_date) : 'Not set';
        const endDate = deployment.end_date ? formatDate(deployment.end_date) : 'Not set';
        
        const deploymentStatus = (deployment.status || 'unknown').toLowerCase().replace(' ', '-');
        const statusClasses = {
            'active': 'bg-blue-100 text-blue-800',
            'completed': 'bg-green-100 text-green-800',
            'on-hold': 'bg-yellow-100 text-yellow-800',
            'unknown': 'bg-gray-100 text-gray-800'
        };
        const statusClass = statusClasses[deploymentStatus] || 'bg-gray-100 text-gray-800';
        
        html += `
            <div class="bg-white border border-gray-200 rounded-lg p-6 shadow-sm">
                <div class="flex items-center justify-between mb-4">
                    <h5 class="text-lg font-medium text-gray-900 flex items-center">
                        <i class="fas fa-building text-blue-600 mr-2"></i>
                        ${escapeHtml(deployment.company_name || 'Unknown Company')}
                    </h5>
                    <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium ${statusClass}">
                        ${escapeHtml(deployment.status || 'Unknown')}
                    </span>
                </div>
                
                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4 mb-4">
                    ${createInfoCard('Position', deployment.position)}
                    ${createInfoCard('Start Date', startDate)}
                    ${createInfoCard('End Date', endDate)}
                    ${createInfoCard('Required Hours', deployment.required_hours)}
                    ${createInfoCard('Completed Hours', deployment.completed_hours || '0')}
                    ${createInfoCard('Work Days', deployment.work_days)}
                </div>
        `;
        
        // Add company and supervisor info if available
        if (deployment.supervisor_full_name || deployment.company_address || deployment.industry_field) {
            html += `
                <div class="border-t border-gray-200 pt-4">
                    <h6 class="text-sm font-medium text-gray-900 mb-3">Company & Supervisor Information</h6>
                    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
            `;
            
            const companyFields = [
                { label: 'Supervisor Name', value: deployment.supervisor_full_name },
                { label: 'Supervisor Email', value: deployment.supervisor_email },
                { label: 'Supervisor Phone', value: deployment.supervisor_phone },
                { label: 'Supervisor Position', value: deployment.supervisor_position },
                { label: 'Company Address', value: deployment.company_address },
                { label: 'Company Contact', value: deployment.company_contact_number },
                { label: 'Industry Field', value: deployment.industry_field },
                { label: 'Work Mode', value: deployment.work_mode }
            ];
            
            companyFields.forEach(field => {
                if (field.value) {
                    html += createInfoCard(field.label, field.value);
                }
            });
            
            if (deployment.work_schedule_start && deployment.work_schedule_end) {
                html += createInfoCard('Work Schedule', 
                    `${deployment.work_schedule_start} - ${deployment.work_schedule_end}`);
            }
            
            if (deployment.company_work_days) {
                html += createInfoCard('Company Work Days', deployment.company_work_days);
            }
            
            html += '</div></div>';
        }
        
        html += '</div>';
    });
    html += '</div>';
    
    container.innerHTML = html;
}
function createInfoCard(label, value) {
    if (!value || value === '-') return '';
    return `
        <div class="bg-gray-50 rounded-lg p-3">
            <div class="text-xs font-medium text-gray-500 uppercase tracking-wide mb-1">${escapeHtml(label)}</div>
            <div class="text-sm font-medium text-gray-900">${escapeHtml(value)}</div>
        </div>
    `;
}

// HTML escape function for security
function escapeHtml(text) {
    if (!text) return '';
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}
        
function populateAttendanceRecords(attendance) {
    const tbody = document.getElementById('attendanceTableBody');
    
    if (!attendance || attendance.length === 0) {
        tbody.innerHTML = `
            <tr>
                <td colspan="6" class="px-6 py-8 text-center text-gray-500">
                    <i class="fas fa-clock text-2xl mb-2"></i>
                    <p>No attendance records found</p>
                </td>
            </tr>
        `;
        return;
    }
    
    let html = '';
    attendance.forEach(record => {
        const date = record.date ? formatDate(record.date) : '-';
        const timeIn = record.time_in || '-';
        const timeOut = record.time_out || '-';
        const hours = record.hours_worked || '-';
        const company = record.company_name || '-';
        const status = record.status || 'Present';
        
        const statusClasses = {
            'present': 'bg-green-100 text-green-800',
            'absent': 'bg-red-100 text-red-800',
            'late': 'bg-yellow-100 text-yellow-800'
        };
        const statusClass = statusClasses[status.toLowerCase()] || 'bg-gray-100 text-gray-800';
        
        html += `
            <tr>
                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">${escapeHtml(date)}</td>
                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">${escapeHtml(timeIn)}</td>
                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">${escapeHtml(timeOut)}</td>
                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">${escapeHtml(hours)}</td>
                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">${escapeHtml(company)}</td>
                <td class="px-6 py-4 whitespace-nowrap">
                    <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium ${statusClass}">
                        ${escapeHtml(status)}
                    </span>
                </td>
            </tr>
        `;
    });
    
    tbody.innerHTML = html;
}
        
        function populateDocumentRequirements(documents) {
    const tbody = document.getElementById('documentsTableBody');
    
    if (!documents || documents.length === 0) {
        tbody.innerHTML = `
            <tr>
                <td colspan="4" class="px-6 py-8 text-center text-gray-500">
                    <i class="fas fa-file-alt text-2xl mb-2"></i>
                    <p>No document requirements found</p>
                </td>
            </tr>
        `;
        return;
    }
    
    let html = '';
    documents.forEach(doc => {
        const required = doc.is_required ? 'Yes' : 'No';
        const status = doc.status || 'Pending';
        
        const statusClasses = {
            'submitted': 'bg-green-100 text-green-800',
            'pending': 'bg-yellow-100 text-yellow-800',
            'rejected': 'bg-red-100 text-red-800',
            'approved': 'bg-blue-100 text-blue-800'
        };
        const statusClass = statusClasses[status.toLowerCase()] || 'bg-gray-100 text-gray-800';
        
        html += `
            <tr>
                <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900">${escapeHtml(doc.requirement_name || '-')}</td>
                <td class="px-6 py-4 text-sm text-gray-900">${escapeHtml(doc.description || '-')}</td>
                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">${required}</td>
                <td class="px-6 py-4 whitespace-nowrap">
                    <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium ${statusClass}">
                        ${escapeHtml(status)}
                    </span>
                </td>
            </tr>
        `;
    });
    
    tbody.innerHTML = html;
}
       function showModalError(message) {
    const errorElement = document.getElementById('errorMessage');
    if (errorElement) {
        errorElement.textContent = message;
    }
    document.getElementById('modalError').style.display = 'block';
}


        function showTab(tabName) {
    // Hide all tabs
    const tabs = document.querySelectorAll('.tab-content');
    tabs.forEach(tab => tab.style.display = 'none');
    
    // Remove active class from all tab buttons
    const tabButtons = document.querySelectorAll('.tab-button');
    tabButtons.forEach(button => {
        button.classList.remove('border-blue-500', 'text-blue-600');
        button.classList.add('border-transparent', 'text-gray-500');
    });
    
    // Show selected tab
    const selectedTab = document.getElementById(tabName + 'Tab');
    if (selectedTab) {
        selectedTab.style.display = 'block';
    }
    
    // Find and activate the corresponding button
    const activeButton = Array.from(tabButtons).find(button => 
        button.textContent.trim().toLowerCase().includes(tabName.toLowerCase())
    );
    
    if (activeButton) {
        activeButton.classList.remove('border-transparent', 'text-gray-500');
        activeButton.classList.add('border-blue-500', 'text-blue-600');
    }
}
        
        function closeModal() {
            document.getElementById('studentModal').classList.add('hidden');
            document.body.style.overflow = 'auto';
        }
        
        // Confirm logout
        function confirmLogout() {
            return confirm('Are you sure you want to logout?');
        }
        
        // Auto-submit search form on select change
        document.addEventListener('DOMContentLoaded', function() {
            const selectElements = document.querySelectorAll('select[name="department"], select[name="status"]');
            selectElements.forEach(select => {
                select.addEventListener('change', function() {
                    this.form.submit();
                });
            });
        });

        // Handle escape key to close modals/dropdowns
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                // Close modal
                const modal = document.getElementById('studentModal');
                if (!modal.classList.contains('hidden')) {
                    closeModal();
                }
                
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
        document.addEventListener('DOMContentLoaded', function() {
    // Add click event listeners to tab buttons
    const tabButtons = document.querySelectorAll('.tab-button');
    tabButtons.forEach((button, index) => {
        button.addEventListener('click', function(e) {
            e.preventDefault();
            const tabNames = ['personal', 'deployment', 'attendance', 'documents'];
            const tabName = tabNames[index];
            if (tabName) {
                showTab(tabName);
            }
        });
    });
    
    // Initialize first tab as active
    resetToFirstTab();
});

        // Initialize default tab state
        document.addEventListener('DOMContentLoaded', function() {
            // Ensure first tab is active by default
            const firstTabButton = document.querySelector('.tab-button');
            if (firstTabButton) {
                firstTabButton.classList.add('border-blue-500', 'text-blue-600');
                firstTabButton.classList.remove('border-transparent', 'text-gray-500');
            }
        });
    </script>
</body>
</html>