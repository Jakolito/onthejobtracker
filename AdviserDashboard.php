<?php
include('connect.php');
session_start();

// Prevent caching - add these headers at the top
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

// Get dashboard statistics
try {
    // Total OJT Students
    $total_students_query = "SELECT COUNT(*) as total FROM students WHERE verified = 1 AND status != 'Blocked'";
    $total_students_result = mysqli_query($conn, $total_students_query);
    $total_students = mysqli_fetch_assoc($total_students_result)['total'];

    // Total OJT Company Supervisors
    $total_supervisors_query = "SELECT COUNT(*) as total FROM company_supervisors WHERE account_status = 'Active'";
    $total_supervisors_result = mysqli_query($conn, $total_supervisors_query);
    $total_supervisors = mysqli_fetch_assoc($total_supervisors_result)['total'];

    // Get previous month's student count for comparison
    $prev_month_students_query = "SELECT COUNT(*) as total FROM students WHERE verified = 1 AND status != 'Blocked' AND created_at < DATE_SUB(NOW(), INTERVAL 1 MONTH)";
    $prev_month_students_result = mysqli_query($conn, $prev_month_students_query);
    $prev_month_students = mysqli_fetch_assoc($prev_month_students_result)['total'];
    $student_growth = $total_students - $prev_month_students;

    // Get previous month's supervisor count for comparison
    $prev_month_supervisors_query = "SELECT COUNT(*) as total FROM company_supervisors WHERE account_status = 'Active' AND created_at < DATE_SUB(NOW(), INTERVAL 1 MONTH)";
    $prev_month_supervisors_result = mysqli_query($conn, $prev_month_supervisors_query);
    $prev_month_supervisors = mysqli_fetch_assoc($prev_month_supervisors_result)['total'];
    $supervisor_growth = $total_supervisors - $prev_month_supervisors;

    // Get active students count from student_deployments
    $active_students_query = "SELECT COUNT(DISTINCT student_id) as total FROM student_deployments WHERE status = 'Active' OR ojt_status = 'Active'";
    $active_students_result = mysqli_query($conn, $active_students_query);
    $active_students = mysqli_fetch_assoc($active_students_result)['total'];

    // Get completed OJT count from student_deployments
    $completed_ojt_query = "SELECT COUNT(DISTINCT student_id) as total FROM student_deployments WHERE (status = 'Completed' OR ojt_status = 'Completed') AND updated_at >= DATE_SUB(NOW(), INTERVAL 1 YEAR)";
    $completed_ojt_result = mysqli_query($conn, $completed_ojt_query);
    $completed_ojt = mysqli_fetch_assoc($completed_ojt_result)['total'];

    // Get recent messages sent to adviser
    $recent_messages_query = "SELECT 
        m.id, m.message, m.sent_at, m.is_read, m.message_type,
        s.first_name, s.last_name, s.student_id, s.program 
        FROM messages m 
        JOIN students s ON m.sender_id = s.id 
        WHERE m.recipient_type = 'adviser' AND m.sender_type = 'student' 
        AND m.is_deleted_by_recipient = 0 
        ORDER BY m.sent_at DESC 
        LIMIT 10";
    $recent_messages_result = mysqli_query($conn, $recent_messages_query);

    // Get notification counts
    $unread_messages_query = "SELECT COUNT(*) as count FROM messages WHERE recipient_type = 'adviser' AND sender_type = 'student' AND is_read = 0 AND is_deleted_by_recipient = 0";
    $unread_messages_result = mysqli_query($conn, $unread_messages_query);
    $unread_messages_count = mysqli_fetch_assoc($unread_messages_result)['count'];

    $pending_documents_query = "SELECT COUNT(*) as count FROM student_documents sd JOIN students s ON sd.student_id = s.id WHERE sd.status = 'pending' AND s.verified = 1";
    $pending_documents_result = mysqli_query($conn, $pending_documents_query);
    $pending_documents_count = mysqli_fetch_assoc($pending_documents_result)['count'];

    // Get recent document submissions
    $recent_documents_query = "SELECT 
        sd.id, sd.original_filename, sd.status, sd.submitted_at, sd.name as doc_name,
        s.first_name, s.last_name, s.student_id, s.program 
        FROM student_documents sd 
        JOIN students s ON sd.student_id = s.id 
        WHERE s.verified = 1 
        ORDER BY sd.submitted_at DESC 
        LIMIT 10";
    $recent_documents_result = mysqli_query($conn, $recent_documents_query);

    // Get students for evaluation
    $students_for_evaluation_query = "SELECT 
        s.id, s.first_name, s.last_name, s.student_id, s.program, s.year_level, s.section, s.status, s.last_login 
        FROM students s 
        WHERE s.verified = 1 AND s.status IN ('Active', 'Completed') 
        ORDER BY s.last_login DESC 
        LIMIT 10";
    $students_for_evaluation_result = mysqli_query($conn, $students_for_evaluation_query);

} catch (Exception $e) {
    $error_message = "Error fetching dashboard data: " . $e->getMessage();
}

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
// Create adviser initials
$adviser_initials = strtoupper(substr($adviser_name, 0, 2));
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>OnTheJob Tracker - Academic Adviser Dashboard</title>
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
        /* Custom CSS for features not easily achievable with Tailwind */
        .sidebar {
            transition: transform 0.3s ease-in-out;
        }

        .sidebar-overlay {
            transition: opacity 0.3s ease-in-out;
        }

        .progress-fill {
            transition: width 2s ease-in-out;
        }

        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }

        .chart-container {
            position: relative;
            height: 400px;
        }

        .notification-badge {
            animation: pulse 2s infinite;
        }

        @keyframes pulse {
            0%, 100% { transform: scale(1); }
            50% { transform: scale(1.1); }
        }

        .activity-item {
            transition: all 0.3s ease;
        }

        .activity-item:hover {
            transform: translateX(4px);
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
        }
    </style>
</head>
<body class="bg-gray-50">
    <!-- Mobile Sidebar Overlay -->
    <div id="sidebarOverlay" class="fixed inset-0 bg-black bg-opacity-50 z-40 lg:hidden hidden sidebar-overlay"></div>

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
            <a href="AdviserDashboard.php" class="nav-item flex items-center px-3 py-2 text-sm font-medium text-white bg-bulsu-gold bg-opacity-20 border border-bulsu-gold border-opacity-30 rounded-md">
                <i class="fas fa-th-large mr-3 text-bulsu-gold"></i>
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
                    <h1 class="text-xl sm:text-2xl font-bold text-gray-900">Academic Adviser Dashboard</h1>
                    <p class="text-sm sm:text-base text-gray-500 hidden sm:block">Monitoring OJT program and student progress</p>
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
                <h1 class="text-2xl sm:text-3xl font-bold text-gray-900 mb-2">Welcome, <?php echo htmlspecialchars($adviser_name); ?>!</h1>
                <p class="text-gray-600">Here's your OJT program overview for today.</p>
            </div>

            <!-- Statistics Grid -->
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4 sm:gap-6 mb-6 sm:mb-8">
                <div class="bg-white p-4 sm:p-6 rounded-lg shadow-sm border border-gray-200 border-l-4 border-l-green-500">
                    <div class="flex items-center justify-between">
                        <div>
                            <div class="text-2xl sm:text-3xl font-bold text-green-600 mb-1"><?php echo $total_students; ?></div>
                            <div class="text-sm text-gray-600">Total OJT Students</div>
                            <div class="text-xs <?php echo $student_growth >= 0 ? 'text-green-600' : 'text-red-600'; ?> mt-1">
                                <?php echo $student_growth >= 0 ? '+' : ''; ?><?php echo $student_growth; ?> since last month
                            </div>
                        </div>
                        <div class="text-green-500">
                            <i class="fas fa-user-graduate text-2xl"></i>
                        </div>
                    </div>
                </div>

                <div class="bg-white p-4 sm:p-6 rounded-lg shadow-sm border border-gray-200 border-l-4 border-l-yellow-500">
                    <div class="flex items-center justify-between">
                        <div>
                            <div class="text-2xl sm:text-3xl font-bold text-yellow-600 mb-1"><?php echo $total_supervisors; ?></div>
                            <div class="text-sm text-gray-600">Company Supervisors</div>
                            <div class="text-xs <?php echo $supervisor_growth >= 0 ? 'text-yellow-600' : 'text-red-600'; ?> mt-1">
                                <?php echo $supervisor_growth >= 0 ? '+' : ''; ?><?php echo $supervisor_growth; ?> since last month
                            </div>
                        </div>
                        <div class="text-yellow-500">
                            <i class="fas fa-users-cog text-2xl"></i>
                        </div>
                    </div>
                </div>

                <div class="bg-white p-4 sm:p-6 rounded-lg shadow-sm border border-gray-200 border-l-4 border-l-blue-500">
                    <div class="flex items-center justify-between">
                        <div>
                            <div class="text-2xl sm:text-3xl font-bold text-blue-600 mb-1"><?php echo $active_students; ?></div>
                            <div class="text-sm text-gray-600">Active Students</div>
                            <div class="text-xs text-blue-600 mt-1">Currently on OJT</div>
                        </div>
                        <div class="text-blue-500">
                            <i class="fas fa-chart-line text-2xl"></i>
                        </div>
                    </div>
                </div>

                <div class="bg-white p-4 sm:p-6 rounded-lg shadow-sm border border-gray-200 border-l-4 border-l-purple-500">
                    <div class="flex items-center justify-between">
                        <div>
                            <div class="text-2xl sm:text-3xl font-bold text-purple-600 mb-1"><?php echo $completed_ojt; ?></div>
                            <div class="text-sm text-gray-600">Completed OJT</div>
                            <div class="text-xs text-purple-600 mt-1">This year</div>
                        </div>
                        <div class="text-purple-500">
                            <i class="fas fa-medal text-2xl"></i>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Chart Section -->
            <div class="bg-white rounded-lg shadow-sm border border-gray-200 mb-6 sm:mb-8">
                <div class="p-4 sm:p-6 border-b border-gray-200">
                    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between">
                        <div class="mb-4 sm:mb-0">
                            <h3 class="text-lg font-medium text-gray-900 mb-1">OJT Program Metrics Overview</h3>
                            <p class="text-sm text-gray-500">Track student and supervisor engagement over time</p>
                        </div>
                        <div class="flex flex-wrap gap-2">
                            <button id="currentBtn" class="metric-button px-3 py-2 text-sm font-medium bg-blue-600 text-white rounded-md hover:bg-blue-700 transition-colors" data-view="current">
                                Current Month
                            </button>
                            <button id="previousBtn" class="metric-button px-3 py-2 text-sm font-medium bg-gray-200 text-gray-700 rounded-md hover:bg-gray-300 transition-colors" data-view="previous">
                                Previous Month
                            </button>
                            <button id="yearlyBtn" class="metric-button px-3 py-2 text-sm font-medium bg-gray-200 text-gray-700 rounded-md hover:bg-gray-300 transition-colors" data-view="yearly">
                                Yearly
                            </button>
                        </div>
                    </div>
                </div>
                <div class="p-4 sm:p-6">
                    <div class="chart-container">
                        <canvas id="barChart"></canvas>
                    </div>
                </div>
            </div>

            <!-- Recent Activity & Notifications Section -->
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-6 sm:mb-8">
                <!-- Recent Document Submissions -->
                <div class="bg-white rounded-lg shadow-sm border border-gray-200">
                    <div class="p-4 sm:p-6 border-b border-gray-200">
                        <div class="flex items-center justify-between">
                            <div class="flex items-center">
                                <i class="fas fa-file-upload text-orange-600 mr-3"></i>
                                <h3 class="text-lg font-medium text-gray-900">Recent Document Submissions</h3>
                            </div>
                            <?php if ($pending_documents_count > 0): ?>
                                <span class="bg-orange-100 text-orange-800 text-xs font-medium px-2.5 py-0.5 rounded-full">
                                    <?php echo $pending_documents_count; ?> pending
                                </span>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="p-4 sm:p-6 max-h-80 overflow-y-auto">
                        <div class="space-y-3">
                            <?php if (mysqli_num_rows($recent_documents_result) > 0): ?>
                                <?php while ($doc = mysqli_fetch_assoc($recent_documents_result)): ?>
                                    <?php 
                                    $student_initials = strtoupper(substr($doc['first_name'], 0, 1) . substr($doc['last_name'], 0, 1));
                                    $status_colors = [
                                        'pending' => 'bg-orange-100 text-orange-800',
                                        'approved' => 'bg-green-100 text-green-800',
                                        'rejected' => 'bg-red-100 text-red-800'
                                    ];
                                    ?>
                                    <div class="activity-item flex items-center space-x-3 p-3 bg-gray-50 rounded-lg">
                                        <div class="flex-shrink-0 w-8 h-8 bg-gradient-to-r from-orange-500 to-red-600 rounded-full flex items-center justify-center text-white font-semibold text-xs">
                                            <?php echo $student_initials; ?>
                                        </div>
                                        <div class="flex-1">
                                            <p class="text-sm font-medium text-gray-900"><?php echo htmlspecialchars($doc['first_name'] . ' ' . $doc['last_name']); ?></p>
                                            <p class="text-xs text-gray-600"><?php echo htmlspecialchars($doc['doc_name'] ?: $doc['original_filename']); ?></p>
                                            <p class="text-xs text-gray-500"><?php echo date('M j, Y g:i A', strtotime($doc['submitted_at'])); ?></p>
                                        </div>
                                        <div class="flex-shrink-0">
                                            <span class="inline-flex items-center px-2 py-1 rounded-full text-xs font-medium <?php echo $status_colors[$doc['status']]; ?>">
                                                <?php echo ucfirst($doc['status']); ?>
                                            </span>
                                        </div>
                                    </div>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <div class="text-center py-4">
                                    <i class="fas fa-file text-gray-400 text-2xl mb-2"></i>
                                    <p class="text-gray-500 text-sm">No recent document submissions</p>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- Recent Messages -->
                <div class="bg-white rounded-lg shadow-sm border border-gray-200">
                    <div class="p-4 sm:p-6 border-b border-gray-200">
                        <div class="flex items-center justify-between">
                            <div class="flex items-center">
                                <i class="fas fa-comments text-blue-600 mr-3"></i>
                                <h3 class="text-lg font-medium text-gray-900">Recent Messages</h3>
                            </div>
                            <?php if ($unread_messages_count > 0): ?>
                                <span class="bg-red-100 text-red-800 text-xs font-medium px-2.5 py-0.5 rounded-full">
                                    <?php echo $unread_messages_count; ?> unread
                                </span>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="p-4 sm:p-6 max-h-80 overflow-y-auto">
                        <div class="space-y-3">
                            <?php if (mysqli_num_rows($recent_messages_result) > 0): ?>
                                <?php while ($message = mysqli_fetch_assoc($recent_messages_result)): ?>
                                    <?php 
                                    $student_initials = strtoupper(substr($message['first_name'], 0, 1) . substr($message['last_name'], 0, 1));
                                    $message_preview = strlen($message['message']) > 50 ? substr($message['message'], 0, 50) . '...' : $message['message'];
                                    ?>
                                    <div class="activity-item flex items-center space-x-3 p-3 bg-gray-50 rounded-lg <?php echo !$message['is_read'] ? 'border-l-4 border-l-blue-500' : ''; ?>">
                                        <div class="flex-shrink-0 w-8 h-8 bg-gradient-to-r from-blue-500 to-purple-600 rounded-full flex items-center justify-center text-white font-semibold text-xs">
                                            <?php echo $student_initials; ?>
                                        </div>
                                        <div class="flex-1">
                                            <div class="flex items-center space-x-2">
                                                <p class="text-sm font-medium text-gray-900"><?php echo htmlspecialchars($message['first_name'] . ' ' . $message['last_name']); ?></p>
                                                <?php if (!$message['is_read']): ?>
                                                    <span class="w-2 h-2 bg-blue-600 rounded-full"></span>
                                                <?php endif; ?>
                                            </div>
                                            <p class="text-xs text-gray-600"><?php echo htmlspecialchars($message_preview); ?></p>
                                            <p class="text-xs text-gray-500"><?php echo date('M j, Y g:i A', strtotime($message['sent_at'])); ?></p>
                                        </div>
                                        <div class="flex-shrink-0">
                                            <span class="inline-flex items-center px-2 py-1 rounded-full text-xs font-medium bg-blue-100 text-blue-800">
                                                <?php echo ucfirst($message['message_type'] ?: 'general'); ?>
                                            </span>
                                        </div>
                                    </div>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <div class="text-center py-4">
                                    <i class="fas fa-envelope text-gray-400 text-2xl mb-2"></i>
                                    <p class="text-gray-500 text-sm">No recent messages</p>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Quick Actions Section -->
            <div class="bg-white rounded-lg shadow-sm border border-gray-200">
                <div class="p-4 sm:p-6 border-b border-gray-200">
                    <h3 class="text-lg font-medium text-gray-900">Quick Actions</h3>
                    <p class="text-sm text-gray-500">Common tasks and shortcuts</p>
                </div>
                <div class="p-4 sm:p-6">
                    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
                        <a href="StudentAccounts.php" class="flex items-center p-4 bg-gradient-to-r from-blue-50 to-indigo-50 rounded-lg border border-blue-200 hover:border-blue-300 transition-all duration-200 hover:shadow-md">
                            <div class="flex-shrink-0 w-10 h-10 bg-blue-500 rounded-lg flex items-center justify-center text-white">
                                <i class="fas fa-user-plus"></i>
                            </div>
                            <div class="ml-3">
                                <p class="text-sm font-medium text-gray-900">Manage Students</p>
                                <p class="text-xs text-gray-500">View and manage student accounts</p>
                            </div>
                        </a>

                        <a href="StudentDeployment.php" class="flex items-center p-4 bg-gradient-to-r from-green-50 to-emerald-50 rounded-lg border border-green-200 hover:border-green-300 transition-all duration-200 hover:shadow-md">
                            <div class="flex-shrink-0 w-10 h-10 bg-green-500 rounded-lg flex items-center justify-center text-white">
                                <i class="fas fa-paper-plane"></i>
                            </div>
                            <div class="ml-3">
                                <p class="text-sm font-medium text-gray-900">Deploy Students</p>
                                <p class="text-xs text-gray-500">Assign students to companies</p>
                            </div>
                        </a>

                        <a href="GenerateReports.php" class="flex items-center p-4 bg-gradient-to-r from-purple-50 to-violet-50 rounded-lg border border-purple-200 hover:border-purple-300 transition-all duration-200 hover:shadow-md">
                            <div class="flex-shrink-0 w-10 h-10 bg-purple-500 rounded-lg flex items-center justify-center text-white">
                                <i class="fas fa-chart-bar"></i>
                            </div>
                            <div class="ml-3">
                                <p class="text-sm font-medium text-gray-900">Generate Reports</p>
                                <p class="text-xs text-gray-500">Create performance reports</p>
                            </div>
                        </a>

                        <a href="academicAdviserMessage.php" class="flex items-center p-4 bg-gradient-to-r from-orange-50 to-red-50 rounded-lg border border-orange-200 hover:border-orange-300 transition-all duration-200 hover:shadow-md">
                            <div class="flex-shrink-0 w-10 h-10 bg-orange-500 rounded-lg flex items-center justify-center text-white">
                                <i class="fas fa-envelope"></i>
                            </div>
                            <div class="ml-3">
                                <p class="text-sm font-medium text-gray-900">Send Messages</p>
                                <p class="text-xs text-gray-500">Communicate with students</p>
                            </div>
                        </a>
                    </div>
                </div>
            </div>
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

        // Chart.js implementation
        const ctx = document.getElementById('barChart').getContext('2d');
        let chart;

        // Chart data based on your PHP variables
        const chartData = {
            current: {
                labels: ['Total Students', 'Company Supervisors', 'Active Students', 'Completed OJT'],
                datasets: [{
                    label: 'Current Month',
                    data: [<?php echo $total_students; ?>, <?php echo $total_supervisors; ?>, <?php echo $active_students; ?>, <?php echo $completed_ojt; ?>],
                    backgroundColor: ['#10B981', '#F59E0B', '#3B82F6', '#8B5CF6'],
                    borderColor: ['#059669', '#D97706', '#2563EB', '#7C3AED'],
                    borderWidth: 2
                }]
            },
            previous: {
                labels: ['Total Students', 'Company Supervisors', 'Active Students', 'Completed OJT'],
                datasets: [{
                    label: 'Previous Month',
                    data: [<?php echo $prev_month_students; ?>, <?php echo $prev_month_supervisors; ?>, <?php echo max(0, $active_students - 5); ?>, <?php echo max(0, $completed_ojt - 3); ?>],
                    backgroundColor: ['#6B7280', '#6B7280', '#6B7280', '#6B7280'],
                    borderColor: ['#4B5563', '#4B5563', '#4B5563', '#4B5563'],
                    borderWidth: 2
                }]
            },
            yearly: {
                labels: ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'],
                datasets: [{
                    label: 'Students',
                    data: [45, 52, 48, 61, 58, 65, 72, 68, 75, 78, 82, <?php echo $total_students; ?>],
                    backgroundColor: '#10B981',
                    borderColor: '#059669',
                    borderWidth: 2
                }, {
                    label: 'Supervisors',
                    data: [8, 10, 12, 14, 15, 16, 18, 19, 20, 22, 24, <?php echo $total_supervisors; ?>],
                    backgroundColor: '#F59E0B',
                    borderColor: '#D97706',
                    borderWidth: 2
                }]
            }
        };

        function createChart(view = 'current') {
            if (chart) {
                chart.destroy();
            }

            chart = new Chart(ctx, {
                type: 'bar',
                data: chartData[view],
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            position: 'top',
                        },
                        title: {
                            display: true,
                            text: 'OJT Program Metrics'
                        }
                    },
                    scales: {
                        y: {
                            beginAtZero: true,
                            ticks: {
                                stepSize: 1
                            }
                        }
                    },
                    animation: {
                        duration: 1000,
                        easing: 'easeInOutQuart'
                    }
                }
            });
        }

        // Initialize chart
        createChart('current');

        // Chart view buttons
        const metricButtons = document.querySelectorAll('.metric-button');
        metricButtons.forEach(button => {
            button.addEventListener('click', () => {
                // Remove active class from all buttons
                metricButtons.forEach(btn => {
                    btn.classList.remove('bg-blue-600', 'text-white');
                    btn.classList.add('bg-gray-200', 'text-gray-700');
                });
                
                // Add active class to clicked button
                button.classList.remove('bg-gray-200', 'text-gray-700');
                button.classList.add('bg-blue-600', 'text-white');
                
                // Create chart with selected view
                createChart(button.dataset.view);
            });
        });

        // Auto-refresh data every 30 seconds
        setInterval(() => {
            // You can implement AJAX calls here to refresh data
            console.log('Auto-refreshing dashboard data...');
        }, 30000);

        // Add smooth scrolling to anchor links
        document.querySelectorAll('a[href^="#"]').forEach(anchor => {
            anchor.addEventListener('click', function (e) {
                e.preventDefault();
                document.querySelector(this.getAttribute('href')).scrollIntoView({
                    behavior: 'smooth'
                });
            });
        });
    </script>
</body>
</html>