<?php
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
$user_id = $_SESSION['user_id'];
include_once('notification_functions.php');
$unread_count = getUnreadNotificationCount($conn, $user_id);

// Check for upload messages
$upload_success = isset($_SESSION['upload_success']) ? $_SESSION['upload_success'] : null;
$upload_error = isset($_SESSION['upload_error']) ? $_SESSION['upload_error'] : null;
unset($_SESSION['upload_success'], $_SESSION['upload_error']);

// Fetch student data from database
try {
    $stmt = $conn->prepare("SELECT first_name, middle_name, last_name, email, student_id, department, program, year_level, profile_picture, verified FROM students WHERE id = ?");
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
        $is_verified = $student['verified']; // Get verification status
        
        // Create initials for avatar (first letter of first name + first letter of last name)
        $initials = strtoupper(substr($student['first_name'], 0, 1) . substr($student['last_name'], 0, 1));
    } else {
        // If student not found, redirect to login
        header("Location: login.php");
        exit();
    }
} catch (Exception $e) {
    // Handle database error
    echo "Error: " . $e->getMessage();
    exit();
}

// Fetch document requirements
$document_requirements = [];
$submitted_documents = [];

try {
    // Get all document requirements
    $doc_stmt = $conn->prepare("SELECT id, name, description, is_required, created_at FROM document_requirements ORDER BY is_required DESC, name ASC");
    $doc_stmt->execute();
    $doc_result = $doc_stmt->get_result();
    
    while ($row = $doc_result->fetch_assoc()) {
        $document_requirements[] = $row;
    }
    $doc_stmt->close();
    
    // Get submitted documents for this student
    $sub_stmt = $conn->prepare("
        SELECT dr.id as doc_id, sd.id as submission_id, sd.file_path, sd.submitted_at, sd.status, sd.feedback, sd.original_filename, sd.file_type
        FROM document_requirements dr 
        LEFT JOIN student_documents sd ON dr.id = sd.document_id AND sd.student_id = ?
    ");
    $sub_stmt->bind_param("i", $user_id);
    $sub_stmt->execute();
    $sub_result = $sub_stmt->get_result();
    
    while ($row = $sub_result->fetch_assoc()) {
        $submitted_documents[$row['doc_id']] = [
            'submission_id' => $row['submission_id'],
            'file_path' => $row['file_path'],
            'submitted_at' => $row['submitted_at'],
            'status' => $row['status'],
            'feedback' => $row['feedback'],
            'original_filename' => $row['original_filename'],
            'file_type' => $row['file_type']
        ];
    }
    $sub_stmt->close();
    
} catch (Exception $e) {
    echo "Error fetching documents: " . $e->getMessage();
}

// Calculate statistics
$total_documents = count($document_requirements);
$required_documents = array_filter($document_requirements, function($doc) { return $doc['is_required']; });
$total_required = count($required_documents);
$submitted_count = count(array_filter($submitted_documents, function($sub) { return !empty($sub['submission_id']); }));
$approved_count = count(array_filter($submitted_documents, function($sub) { return $sub['status'] === 'approved'; }));
$pending_count = count(array_filter($submitted_documents, function($sub) { return $sub['status'] === 'pending'; }));
$rejected_count = count(array_filter($submitted_documents, function($sub) { return $sub['status'] === 'rejected'; }));

$completion_percentage = $total_required > 0 ? round(($approved_count / $total_required) * 100, 1) : 0;
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>OnTheJob Tracker - Document Requirements</title>
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
            width: 280px;
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
            border-color: #DAA520 !important;
            background-color: #F4E4BC !important;
            background-opacity: 0.2;
        }

        /* Document viewer modal styles */
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
<body class="bg-gray-100 text-gray-800">
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
                <a href="studentdashboard.php" class="nav-item flex items-center px-3 py-2 text-sm font-medium text-white bg-bulsu-gold bg-opacity-20 border border-bulsu-gold border-opacity-30 rounded-md">
                    <i class="fas fa-th-large mr-3 text-bulsu-gold"></i>
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

                 <div class="flex-1 lg:ml-0 ml-4">
                    <h1 class="text-xl sm:text-2xl font-bold text-gray-900">Student Dashboard</h1>
                    <p class="text-sm sm:text-base text-gray-500 hidden sm:block">Here's what happening with your Job Today!</p>
                </div>
                
                <!-- Profile Dropdown -->
                <div class="relative">
                    <button id="profileBtn" class="flex items-center p-1 rounded-full hover:bg-bulsu-gold hover:bg-opacity-20">
                        <div class="w-8 h-8 sm:w-10 sm:h-10 bg-gradient-to-r from-bulsu-gold to-yellow-400 rounded-full flex items-center justify-center text-bulsu-maroon font-semibold text-xs sm:text-sm">
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
                                <div class="w-12 h-12 bg-gradient-to-r from-bulsu-gold to-yellow-400 rounded-full flex items-center justify-center text-bulsu-maroon font-semibold">
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
            <!-- Welcome Section -->
            <div class="mb-6 sm:mb-8">
                <h1 class="text-2xl sm:text-3xl font-bold text-bulsu-maroon mb-2">Welcome, <?php echo htmlspecialchars($first_name); ?>!</h1>
                <div class="w-24 h-1 bg-bulsu-gold rounded mb-4"></div>
            </div>
            

            <!-- Verification Status Alert -->
            <?php if (!$is_verified): ?>
                <div class="mb-6 p-4 bg-yellow-50 border border-yellow-200 rounded-lg">
                    <div class="flex items-start">
                        <i class="fas fa-exclamation-triangle text-yellow-600 mt-1 mr-3"></i>
                        <div>
                            <h3 class="font-medium text-yellow-800">Account Not Verified</h3>
                            <p class="text-sm text-yellow-700 mt-1">
                                You need to verify your account before you can upload documents. 
                                <a href="verification.php" class="font-medium underline hover:no-underline text-bulsu-maroon">Click here to verify your account</a>
                            </p>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Alert Messages -->
            <?php if ($upload_success): ?>
                <div id="successAlert" class="mb-6 p-4 bg-green-50 border border-green-200 rounded-lg">
                    <div class="flex items-start justify-between">
                        <div class="flex items-start">
                            <i class="fas fa-check-circle text-green-600 mt-1 mr-3"></i>
                            <p class="text-green-700"><?php echo htmlspecialchars($upload_success); ?></p>
                        </div>
                        <button onclick="closeAlert('successAlert')" class="text-green-400 hover:text-green-600">
                            <i class="fas fa-times"></i>
                        </button>
                    </div>
                </div>
            <?php endif; ?>

            <?php if ($upload_error): ?>
                <div id="errorAlert" class="mb-6 p-4 bg-red-50 border border-red-200 rounded-lg">
                    <div class="flex items-start justify-between">
                        <div class="flex items-start">
                            <i class="fas fa-exclamation-circle text-red-600 mt-1 mr-3"></i>
                            <p class="text-red-700"><?php echo htmlspecialchars($upload_error); ?></p>
                        </div>
                        <button onclick="closeAlert('errorAlert')" class="text-red-400 hover:text-red-600">
                            <i class="fas fa-times"></i>
                        </button>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Statistics Grid -->
            <div class="grid grid-cols-2 lg:grid-cols-4 gap-4 sm:gap-6 mb-6 sm:mb-8">
                <div class="bg-white p-4 sm:p-6 rounded-lg shadow-sm border border-gray-200">
                    <div class="text-2xl sm:text-3xl font-bold text-bulsu-maroon mb-1"><?php echo $total_documents; ?></div>
                    <div class="text-sm text-gray-600">Total Documents</div>
                </div>
                <div class="bg-white p-4 sm:p-6 rounded-lg shadow-sm border border-gray-200 border-l-4 border-l-green-500">
                    <div class="text-2xl sm:text-3xl font-bold text-green-600 mb-1"><?php echo $approved_count; ?></div>
                    <div class="text-sm text-gray-600">Approved</div>
                </div>
                <div class="bg-white p-4 sm:p-6 rounded-lg shadow-sm border border-gray-200 border-l-4 border-l-bulsu-gold">
                    <div class="text-2xl sm:text-3xl font-bold text-bulsu-gold mb-1"><?php echo $pending_count; ?></div>
                    <div class="text-sm text-gray-600">Pending Review</div>
                </div>
                <div class="bg-white p-4 sm:p-6 rounded-lg shadow-sm border border-gray-200 border-l-4 border-l-red-500">
                    <div class="text-2xl sm:text-3xl font-bold text-red-600 mb-1"><?php echo $rejected_count; ?></div>
                    <div class="text-sm text-gray-600">Rejected</div>
                </div>
            </div>

            <!-- Progress Section -->
            <div class="bg-white p-4 sm:p-6 rounded-lg shadow-sm border border-gray-200 mb-6 sm:mb-8">
                <div class="flex items-center justify-between mb-4">
                    <h3 class="text-lg font-medium text-bulsu-maroon">Document Completion Progress</h3>
                    <span class="text-2xl font-bold text-bulsu-gold"><?php echo $completion_percentage; ?>%</span>
                </div>
                <div class="w-full bg-gray-200 rounded-full h-3 mb-2">
                    <div class="bg-gradient-to-r from-bulsu-maroon to-bulsu-gold h-3 rounded-full progress-fill" style="width: <?php echo $completion_percentage; ?>%;"></div>
                </div>
                <p class="text-sm text-gray-600">
                    <?php echo $approved_count; ?> of <?php echo $total_required; ?> required documents approved
                </p>
            </div>

            <!-- Documents Section -->
            <div class="bg-white rounded-lg shadow-sm border border-gray-200">
                <div class="p-4 sm:p-6 border-b border-gray-200">
                    <div class="flex items-center justify-between">
                        <div class="flex items-center">
                            <i class="fas fa-file-alt text-bulsu-maroon mr-3"></i>
                            <h3 class="text-lg font-medium text-bulsu-maroon">Document Requirements</h3>
                        </div>
                        <button id="refreshBtn" onclick="refreshPage()" class="flex items-center px-3 py-2 text-sm font-medium text-bulsu-maroon bg-bulsu-light-gold hover:bg-bulsu-gold hover:text-white rounded-md transition-colors">
                            <i class="fas fa-sync-alt mr-2"></i>
                            <span class="hidden sm:inline">Refresh</span>
                        </button>
                    </div>
                </div>

                <div class="p-4 sm:p-6">
                    <?php if (empty($document_requirements)): ?>
                        <div class="text-center py-8">
                            <i class="fas fa-folder-open text-gray-400 text-4xl mb-4"></i>
                            <h3 class="text-lg font-medium text-bulsu-maroon mb-2">No Document Requirements</h3>
                            <p class="text-gray-600">There are currently no document requirements set up for your program.</p>
                        </div>
                    <?php else: ?>
                        <div class="space-y-4 sm:space-y-6">
                            <?php foreach ($document_requirements as $doc): ?>
                                <?php 
                                $submission = isset($submitted_documents[$doc['id']]) ? $submitted_documents[$doc['id']] : null;
                                $is_submitted = $submission && !empty($submission['submission_id']);
                                $status = $is_submitted ? $submission['status'] : 'not_submitted';
                                ?>
                                <div class="border border-gray-200 rounded-lg p-4 sm:p-6 <?php echo !$is_verified ? 'opacity-60' : ''; ?>">
                                    <!-- Document Header -->
                                    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between mb-4">
                                        <div class="mb-3 sm:mb-0">
                                            <h4 class="text-lg font-medium text-bulsu-maroon mb-2">
                                                <?php echo htmlspecialchars($doc['name']); ?>
                                            </h4>
                                            <div class="flex flex-wrap gap-2">
                                                <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium <?php echo $doc['is_required'] ? 'bg-red-100 text-red-800' : 'bg-gray-100 text-gray-800'; ?>">
                                                    <?php echo $doc['is_required'] ? 'Required' : 'Optional'; ?>
                                                </span>
                                                <?php if ($is_submitted): ?>
                                                    <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium 
                                                        <?php 
                                                        switch($status) {
                                                            case 'approved': echo 'bg-green-100 text-green-800'; break;
                                                            case 'pending': echo 'bg-yellow-100 text-yellow-800'; break;
                                                            case 'rejected': echo 'bg-red-100 text-red-800'; break;
                                                        }
                                                        ?>">
                                                        <?php echo ucfirst($status); ?>
                                                    </span>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </div>

                                    <!-- Document Description -->
                                    <p class="text-gray-600 mb-4"><?php echo htmlspecialchars($doc['description']); ?></p>

                                    <!-- Document Info and Actions -->
                                    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between space-y-3 sm:space-y-0">
                                        <div class="text-sm text-gray-600">
                                            <?php if ($is_submitted): ?>
                                                <div class="flex items-center mb-1">
                                                    <?php 
                                                    $file_extension = strtolower(pathinfo($submission['original_filename'], PATHINFO_EXTENSION));
                                                    $icon_class = 'fa-file';
                                                    $icon_color = 'text-gray-500';
                                                    if (in_array($file_extension, ['pdf'])) {
                                                        $icon_class = 'fa-file-pdf';
                                                        $icon_color = 'text-red-500';
                                                    } elseif (in_array($file_extension, ['doc', 'docx'])) {
                                                        $icon_class = 'fa-file-word';
                                                        $icon_color = 'text-blue-500';
                                                    } elseif (in_array($file_extension, ['jpg', 'jpeg', 'png'])) {
                                                        $icon_class = 'fa-file-image';
                                                        $icon_color = 'text-green-500';
                                                    }
                                                    ?>
                                                    <i class="fas <?php echo $icon_class; ?> <?php echo $icon_color; ?> mr-2"></i>
                                                    <span class="font-medium"><?php echo htmlspecialchars($submission['original_filename']); ?></span>
                                                </div>
                                                <p class="text-xs text-gray-500">
                                                    Submitted: <?php echo date('M j, Y g:i A', strtotime($submission['submitted_at'])); ?>
                                                </p>
                                            <?php else: ?>
                                                <span class="text-gray-500">Not submitted</span>
                                            <?php endif; ?>
                                        </div>

                                        <div class="flex flex-col sm:flex-row gap-2">
                                            <?php if ($is_submitted): ?>
                                                <button onclick="viewDocument('<?php echo htmlspecialchars($submission['file_path']); ?>', '<?php echo htmlspecialchars($submission['original_filename']); ?>', '<?php echo htmlspecialchars($submission['file_type']); ?>')"
                                                   class="inline-flex items-center justify-center px-3 py-2 border border-bulsu-gold text-sm font-medium rounded-md text-bulsu-maroon bg-white hover:bg-bulsu-light-gold hover:bg-opacity-30 transition-colors">
                                                    <i class="fas fa-eye mr-1"></i>
                                                    View
                                                </button>
                                                <?php if ($is_verified && ($status === 'rejected' || $status === 'pending')): ?>
                                                    <button onclick="openUploadModal(<?php echo $doc['id']; ?>, '<?php echo htmlspecialchars($doc['name'], ENT_QUOTES); ?>')"
                                                            class="inline-flex items-center justify-center px-3 py-2 border border-transparent text-sm font-medium rounded-md text-white bg-bulsu-maroon hover:bg-bulsu-dark-maroon transition-colors">
                                                        <i class="fas fa-upload mr-1"></i>
                                                        Re-upload
                                                    </button>
                                                <?php elseif (!$is_verified && ($status === 'rejected' || $status === 'pending')): ?>
                                                    <button onclick="showVerificationAlert()"
                                                            class="inline-flex items-center justify-center px-3 py-2 border border-transparent text-sm font-medium rounded-md text-white bg-gray-400 cursor-not-allowed opacity-60">
                                                        <i class="fas fa-lock mr-1"></i>
                                                        Re-upload
                                                    </button>
                                                <?php endif; ?>
                                            <?php else: ?>
                                                <?php if ($is_verified): ?>
                                                    <button onclick="openUploadModal(<?php echo $doc['id']; ?>, '<?php echo htmlspecialchars($doc['name'], ENT_QUOTES); ?>')"
                                                            class="inline-flex items-center justify-center px-3 py-2 border border-transparent text-sm font-medium rounded-md text-white bg-bulsu-maroon hover:bg-bulsu-dark-maroon transition-colors">
                                                        <i class="fas fa-upload mr-1"></i>
                                                        Upload
                                                    </button>
                                                <?php else: ?>
                                                    <button onclick="showVerificationAlert()"
                                                            class="inline-flex items-center justify-center px-3 py-2 border border-transparent text-sm font-medium rounded-md text-white bg-gray-400 cursor-not-allowed opacity-60">
                                                        <i class="fas fa-lock mr-1"></i>
                                                        Upload
                                                    </button>
                                                <?php endif; ?>
                                            <?php endif; ?>
                                        </div>
                                    </div>

                                    <!-- Feedback Section -->
                                    <?php if ($is_submitted && !empty($submission['feedback']) && $status === 'rejected'): ?>
                                        <div class="mt-4 p-3 bg-red-50 border border-red-200 rounded-md">
                                            <div class="flex items-start">
                                                <i class="fas fa-comment text-red-600 mt-0.5 mr-2"></i>
                                                <div>
                                                    <h5 class="font-medium text-red-800 text-sm mb-1">Feedback:</h5>
                                                    <p class="text-red-700 text-sm"><?php echo htmlspecialchars($submission['feedback']); ?></p>
                                                </div>
                                            </div>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>

                <div class="p-4 sm:p-6 border-t border-gray-200 bg-bulsu-light-gold bg-opacity-20 text-center text-sm text-bulsu-maroon">
                    Last updated: <?php echo date('F j, Y g:i A'); ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Upload Modal -->
    <div id="uploadModal" class="fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full z-50 hidden">
        <div class="relative top-20 mx-auto p-4 w-full max-w-lg">
            <div class="relative bg-white rounded-lg shadow-lg">
                <!-- Modal Header -->
                <div class="flex items-center justify-between p-4 sm:p-6 border-b border-gray-200 bg-gradient-to-r from-bulsu-maroon to-bulsu-dark-maroon text-white rounded-t-lg">
                    <h3 id="modalTitle" class="text-lg font-medium">Upload Document</h3>
                    <button onclick="closeUploadModal()" class="text-bulsu-light-gold hover:text-white">
                        <i class="fas fa-times text-xl"></i>
                    </button>
                </div>
                
                <!-- Modal Body -->
                <div class="p-4 sm:p-6">
                    <form id="uploadForm" action="upload_document.php" method="POST" enctype="multipart/form-data">
                        <input type="hidden" id="documentId" name="document_id" value="">
                        
                        <!-- File Upload Area -->
                        <div onclick="document.getElementById('fileInput').click()" 
                             class="file-upload-area border-2 border-dashed border-bulsu-gold border-opacity-50 rounded-lg p-6 text-center cursor-pointer hover:border-bulsu-gold transition-colors">
                            <div class="text-bulsu-gold mb-3">
                                <i class="fas fa-cloud-upload-alt text-4xl"></i>
                            </div>
                            <p class="text-bulsu-maroon font-medium mb-1">Click to select file or drag and drop</p>
                            <p class="text-sm text-gray-500">Supported formats: PDF, DOC, DOCX, JPG, PNG (Max: 5MB)</p>
                        </div>
                        
                        <input type="file" id="fileInput" name="document_file" class="hidden" 
                               accept=".pdf,.doc,.docx,.jpg,.jpeg,.png" onchange="handleFileSelect(this)">
                        
                        <!-- Selected File Display -->
                        <div id="selectedFile" class="hidden mt-4 p-3 bg-bulsu-light-gold bg-opacity-20 border border-bulsu-gold border-opacity-30 rounded-md">
                            <div class="flex items-center">
                                <i class="fas fa-file text-bulsu-maroon mr-2"></i>
                                <div class="flex-1">
                                    <div id="fileName" class="font-medium text-bulsu-maroon"></div>
                                    <div id="fileSize" class="text-sm text-gray-600"></div>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Submit Button -->
                        <button type="submit" id="submitBtn" disabled
                                class="w-full mt-6 flex items-center justify-center px-4 py-2 border border-transparent text-sm font-medium rounded-md text-white bg-bulsu-maroon hover:bg-bulsu-dark-maroon disabled:bg-gray-300 disabled:cursor-not-allowed transition-colors">
                            <i class="fas fa-upload mr-2"></i>
                            Upload Document
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <!-- Document Viewer Modal -->
    <div id="documentViewerModal" class="fixed inset-0 document-modal z-50 hidden">
        <div class="flex items-center justify-center min-h-screen p-4">
            <div class="bg-white rounded-lg shadow-2xl document-viewer w-full h-full max-w-4xl max-h-screen flex flex-col">
                <!-- Modal Header -->
                <div class="flex items-center justify-between p-4 border-b border-gray-200 bg-gradient-to-r from-bulsu-maroon to-bulsu-dark-maroon text-white rounded-t-lg">
                    <div class="flex items-center">
                        <i class="fas fa-file-alt mr-3 text-bulsu-gold"></i>
                        <h3 id="documentTitle" class="text-lg font-medium">Document Viewer</h3>
                    </div>
                    <div class="flex items-center space-x-2">
                        <button id="downloadBtn" class="flex items-center px-3 py-1 bg-bulsu-gold bg-opacity-20 border border-bulsu-gold border-opacity-50 text-bulsu-light-gold hover:bg-opacity-30 rounded text-sm transition-colors">
                            <i class="fas fa-download mr-1"></i>
                            Download
                        </button>
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

    <script>
        // Global variables
        let uploadModal = document.getElementById('uploadModal');
        let documentViewerModal = document.getElementById('documentViewerModal');
        let sidebar = document.getElementById('sidebar');
        let sidebarOverlay = document.getElementById('sidebarOverlay');
        let currentDocumentId = null;
        const isVerified = <?php echo $is_verified ? 'true' : 'false'; ?>;

        // Mobile menu functionality
        document.getElementById('mobileMenuBtn').addEventListener('click', function() {
            sidebar.classList.remove('-translate-x-full');
            sidebarOverlay.classList.remove('hidden');
            document.body.style.overflow = 'hidden';
        });

        document.getElementById('closeSidebar').addEventListener('click', closeSidebar);
        sidebarOverlay.addEventListener('click', closeSidebar);

        function closeSidebar() {
            sidebar.classList.add('-translate-x-full');
            sidebarOverlay.classList.add('hidden');
            document.body.style.overflow = 'auto';
        }

        // Profile dropdown functionality
        document.getElementById('profileBtn').addEventListener('click', function(e) {
            e.stopPropagation();
            const dropdown = document.getElementById('profileDropdown');
            dropdown.classList.toggle('hidden');
        });

        // Close dropdown and modals when clicking outside
        document.addEventListener('click', function(e) {
            const profileDropdown = document.getElementById('profileDropdown');
            if (!e.target.closest('#profileBtn') && !profileDropdown.classList.contains('hidden')) {
                profileDropdown.classList.add('hidden');
            }
        });

        // Document viewer functions
        function viewDocument(filePath, fileName, fileType) {
            const modal = document.getElementById('documentViewerModal');
            const content = document.getElementById('documentContent');
            const title = document.getElementById('documentTitle');
            const downloadBtn = document.getElementById('downloadBtn');
            
            title.textContent = fileName;
            
            // Set up download functionality
            downloadBtn.onclick = function() {
                const link = document.createElement('a');
                link.href = filePath;
                link.download = fileName;
                document.body.appendChild(link);
                link.click();
                document.body.removeChild(link);
            };
            
            // Clear previous content
            content.innerHTML = '<div class="flex items-center justify-center h-full"><i class="fas fa-spinner fa-spin text-bulsu-gold text-3xl"></i></div>';
            
            // Show modal
            modal.classList.remove('hidden');
            document.body.style.overflow = 'hidden';
            
            // Load content based on file type
            if (fileType && (fileType.includes('pdf') || fileName.toLowerCase().endsWith('.pdf'))) {
                // PDF viewer
                content.innerHTML = `
                    <iframe src="${filePath}" 
                            class="document-iframe w-full h-full" 
                            onload="this.style.display='block'" 
                            style="display:none;">
                        <div class="text-center p-8">
                            <i class="fas fa-file-pdf text-red-500 text-6xl mb-4"></i>
                            <p class="text-gray-600 mb-4">Cannot display PDF in browser</p>
                            <button onclick="window.open('${filePath}', '_blank')" class="bg-bulsu-maroon text-white px-4 py-2 rounded hover:bg-bulsu-dark-maroon">
                                Open in new tab
                            </button>
                        </div>
                    </iframe>
                `;
            } else if (fileType && fileType.includes('image') || /\.(jpg|jpeg|png|gif)$/i.test(fileName)) {
                // Image viewer
                content.innerHTML = `
                    <div class="w-full h-full flex items-center justify-center p-4">
                        <img src="${filePath}" 
                             alt="${fileName}"
                             class="max-w-full max-h-full object-contain rounded shadow-lg"
                             onload="this.style.opacity='1'"
                             style="opacity: 0; transition: opacity 0.3s;"
                             onerror="this.parentElement.innerHTML='<div class=\\"text-center\\"><i class=\\"fas fa-exclamation-triangle text-red-500 text-4xl mb-4\\"></i><p class=\\"text-gray-600\\">Error loading image</p></div>'">
                    </div>
                `;
            } else if (fileType && (fileType.includes('word') || /\.(doc|docx)$/i.test(fileName))) {
                // Word document - show download option
                content.innerHTML = `
                    <div class="text-center p-8">
                        <i class="fas fa-file-word text-blue-500 text-6xl mb-4"></i>
                        <h3 class="text-lg font-medium text-bulsu-maroon mb-2">${fileName}</h3>
                        <p class="text-gray-600 mb-6">Word documents cannot be previewed in the browser</p>
                        <button onclick="downloadBtn.click()" class="bg-bulsu-maroon text-white px-6 py-2 rounded hover:bg-bulsu-dark-maroon transition-colors">
                            <i class="fas fa-download mr-2"></i>
                            Download Document
                        </button>
                    </div>
                `;
            } else {
                // Generic file viewer
                content.innerHTML = `
                    <div class="text-center p-8">
                        <i class="fas fa-file text-gray-500 text-6xl mb-4"></i>
                        <h3 class="text-lg font-medium text-bulsu-maroon mb-2">${fileName}</h3>
                        <p class="text-gray-600 mb-6">This file type cannot be previewed</p>
                        <button onclick="downloadBtn.click()" class="bg-bulsu-maroon text-white px-6 py-2 rounded hover:bg-bulsu-dark-maroon transition-colors">
                            <i class="fas fa-download mr-2"></i>
                            Download File
                        </button>
                    </div>
                `;
            }
        }

        function closeDocumentViewer() {
            const modal = document.getElementById('documentViewerModal');
            modal.classList.add('hidden');
            document.body.style.overflow = 'auto';
        }

        // Verification alert
        function showVerificationAlert() {
            const modalHtml = `
                <div class="fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full z-50" id="verificationModal">
                    <div class="relative top-20 mx-auto p-4 w-full max-w-md">
                        <div class="relative bg-white rounded-lg shadow-lg">
                            <div class="p-6 text-center">
                                <div class="text-yellow-600 text-5xl mb-4">
                                    <i class="fas fa-exclamation-triangle"></i>
                                </div>
                                <h3 class="text-lg font-medium text-bulsu-maroon mb-3">Account Not Verified</h3>
                                <p class="text-gray-600 mb-6">You need to verify your account before you can upload documents.</p>
                                <div class="flex flex-col sm:flex-row gap-3 justify-center">
                                    <a href="verification.php" class="inline-flex items-center justify-center px-4 py-2 border border-transparent text-sm font-medium rounded-md text-white bg-bulsu-maroon hover:bg-bulsu-dark-maroon">
                                        <i class="fas fa-check-circle mr-2"></i>
                                        Verify Now
                                    </a>
                                    <button onclick="closeVerificationModal()" class="inline-flex items-center justify-center px-4 py-2 border border-bulsu-gold text-sm font-medium rounded-md text-bulsu-maroon bg-white hover:bg-bulsu-light-gold hover:bg-opacity-30">
                                        <i class="fas fa-times mr-2"></i>
                                        Cancel
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            `;
            
            document.body.insertAdjacentHTML('beforeend', modalHtml);
        }

        function closeVerificationModal() {
            const modal = document.getElementById('verificationModal');
            if (modal) {
                modal.remove();
            }
        }

        // Alert functions
        function closeAlert(alertId) {
            const alert = document.getElementById(alertId);
            if (alert) {
                alert.style.display = 'none';
            }
        }

        // Auto close alerts after 5 seconds
        setTimeout(function() {
            const alerts = document.querySelectorAll('[id$="Alert"]');
            alerts.forEach(alert => {
                if (alert.id !== 'verificationAlert') {
                    alert.style.display = 'none';
                }
            });
        }, 5000);

        // Refresh page functionality
        function refreshPage() {
            const refreshBtn = document.getElementById('refreshBtn');
            const icon = refreshBtn.querySelector('i');
            icon.classList.add('animate-spin');
            refreshBtn.disabled = true;
            
            setTimeout(() => {
                location.reload();
            }, 1000);
        }

        // Modal functions
        function openUploadModal(documentId, documentName) {
            if (!isVerified) {
                showVerificationAlert();
                return;
            }
            
            currentDocumentId = documentId;
            document.getElementById('documentId').value = documentId;
            document.getElementById('modalTitle').textContent = 'Upload: ' + documentName;
            uploadModal.classList.remove('hidden');
            document.body.style.overflow = 'hidden';
            resetUploadForm();
        }

        function closeUploadModal() {
            uploadModal.classList.add('hidden');
            document.body.style.overflow = 'auto';
            currentDocumentId = null;
            resetUploadForm();
        }

        function resetUploadForm() {
            document.getElementById('uploadForm').reset();
            document.getElementById('selectedFile').classList.add('hidden');
            document.getElementById('submitBtn').disabled = true;
            
            // Reset file upload area
            const uploadArea = document.querySelector('.file-upload-area');
            uploadArea.classList.remove('border-green-500', 'border-red-500', 'bg-green-50', 'bg-red-50');
            uploadArea.classList.add('border-bulsu-gold', 'border-opacity-50');
            
            // Remove any error messages
            const existingError = document.querySelector('.file-error');
            if (existingError) {
                existingError.remove();
            }
        }

        // File handling
        function handleFileSelect(input) {
            const file = input.files[0];
            if (file) {
                displaySelectedFile(file);
                validateFile(file);
            }
        }

        function displaySelectedFile(file) {
            const selectedFileDiv = document.getElementById('selectedFile');
            const fileName = document.getElementById('fileName');
            const fileSize = document.getElementById('fileSize');
            
            fileName.textContent = file.name;
            fileSize.textContent = formatFileSize(file.size);
            selectedFileDiv.classList.remove('hidden');
        }

        function validateFile(file) {
            const submitBtn = document.getElementById('submitBtn');
            const maxSize = 5 * 1024 * 1024; // 5MB
            const allowedTypes = ['application/pdf', 'application/msword', 
                                'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                                'image/jpeg', 'image/jpg', 'image/png'];
            
            let isValid = true;
            let errorMessage = '';
            
            // Check file size
            if (file.size > maxSize) {
                isValid = false;
                errorMessage = 'File size must be less than 5MB';
            }
            
            // Check file type
            if (!allowedTypes.includes(file.type)) {
                isValid = false;
                errorMessage = 'Invalid file type. Please upload PDF, DOC, DOCX, JPG, or PNG files only';
            }
            
            const uploadArea = document.querySelector('.file-upload-area');
            
            if (isValid) {
                submitBtn.disabled = false;
                uploadArea.classList.remove('border-bulsu-gold', 'border-opacity-50', 'border-red-500', 'bg-red-50');
                uploadArea.classList.add('border-green-500', 'bg-green-50');
                
                // Remove any existing error messages
                const existingError = document.querySelector('.file-error');
                if (existingError) {
                    existingError.remove();
                }
            } else {
                submitBtn.disabled = true;
                uploadArea.classList.remove('border-bulsu-gold', 'border-opacity-50', 'border-green-500', 'bg-green-50');
                uploadArea.classList.add('border-red-500', 'bg-red-50');
                
                // Show error message
                showFileError(errorMessage);
            }
        }

        function showFileError(message) {
            // Remove existing error message
            const existingError = document.querySelector('.file-error');
            if (existingError) {
                existingError.remove();
            }
            
            // Create new error message
            const errorDiv = document.createElement('div');
            errorDiv.className = 'file-error mt-3 p-3 bg-red-50 border border-red-200 rounded-md';
            errorDiv.innerHTML = `
                <div class="flex items-center">
                    <i class="fas fa-exclamation-triangle text-red-600 mr-2"></i>
                    <span class="text-red-700 text-sm">${message}</span>
                </div>
            `;
            
            // Insert after the file upload area
            const uploadArea = document.querySelector('.file-upload-area');
            uploadArea.parentNode.insertBefore(errorDiv, uploadArea.nextSibling);
        }

        function formatFileSize(bytes) {
            if (bytes === 0) return '0 Bytes';
            const k = 1024;
            const sizes = ['Bytes', 'KB', 'MB', 'GB'];
            const i = Math.floor(Math.log(bytes) / Math.log(k));
            return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
        }

        // Drag and drop functionality
        const fileUploadArea = document.querySelector('.file-upload-area');
        
        if (fileUploadArea) {
            fileUploadArea.addEventListener('dragover', function(e) {
                e.preventDefault();
                e.stopPropagation();
                this.classList.add('drag-over');
            });
            
            fileUploadArea.addEventListener('dragleave', function(e) {
                e.preventDefault();
                e.stopPropagation();
                this.classList.remove('drag-over');
            });
            
            fileUploadArea.addEventListener('drop', function(e) {
                e.preventDefault();
                e.stopPropagation();
                this.classList.remove('drag-over');
                
                const files = e.dataTransfer.files;
                if (files.length > 0) {
                    const fileInput = document.getElementById('fileInput');
                    fileInput.files = files;
                    handleFileSelect(fileInput);
                }
            });
        }

        // Form submission with loading state
        document.getElementById('uploadForm').addEventListener('submit', function(e) {
            const submitBtn = document.getElementById('submitBtn');
            const originalText = submitBtn.innerHTML;
            
            // Show loading state
            submitBtn.disabled = true;
            submitBtn.innerHTML = '<i class="fas fa-spinner animate-spin mr-2"></i> Uploading...';
            submitBtn.classList.add('bg-gray-400');
            
            // Reset after timeout if form doesn't redirect
            setTimeout(() => {
                submitBtn.disabled = false;
                submitBtn.innerHTML = originalText;
                submitBtn.classList.remove('bg-gray-400');
            }, 5000);
        });

        // Keyboard shortcuts
        document.addEventListener('keydown', function(e) {
            // Close modal with Escape key
            if (e.key === 'Escape') {
                if (!uploadModal.classList.contains('hidden')) {
                    closeUploadModal();
                }
                if (!documentViewerModal.classList.contains('hidden')) {
                    closeDocumentViewer();
                }
                const verificationModal = document.getElementById('verificationModal');
                if (verificationModal) {
                    closeVerificationModal();
                }
                if (!sidebar.classList.contains('-translate-x-full')) {
                    closeSidebar();
                }
            }
            
            // Refresh with F5 or Ctrl+R
            if (e.key === 'F5' || (e.ctrlKey && e.key === 'r')) {
                e.preventDefault();
                refreshPage();
            }
        });

        // Initialize progress bar animation
        document.addEventListener('DOMContentLoaded', function() {
            const progressFill = document.querySelector('.progress-fill');
            if (progressFill) {
                const targetWidth = progressFill.style.width;
                progressFill.style.width = '0%';
                setTimeout(() => {
                    progressFill.style.width = targetWidth;
                }, 100);
            }
        });

        // Close sidebar on larger screens
        window.addEventListener('resize', function() {
            if (window.innerWidth >= 1024) {
                closeSidebar();
            }
        });
    </script>
</body>
</html>