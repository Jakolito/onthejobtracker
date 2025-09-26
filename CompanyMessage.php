<?php
include('connect.php');
session_start();

// Cache control headers
header("Cache-Control: no-cache, no-store, must-revalidate");
header("Pragma: no-cache");
header("Expires: 0");

// Check if user is logged in and is a company supervisor
if (!isset($_SESSION['supervisor_id']) || $_SESSION['user_type'] !== 'supervisor') {
    header("Location: login.php");
    exit();
}

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

// Get students under this supervisor's supervision
$supervised_students = [];
try {
    $students_stmt = $conn->prepare("
        SELECT s.id, s.first_name, s.middle_name, s.last_name, s.email, s.student_id, 
               s.department, s.program, s.year_level, s.profile_picture, s.verified,
               sd.deployment_id, sd.position as deployment_position, sd.start_date, sd.end_date
        FROM students s
        INNER JOIN student_deployments sd ON s.id = sd.student_id
        WHERE sd.supervisor_id = ? AND sd.status = 'Active'
        ORDER BY s.first_name, s.last_name
    ");
    $students_stmt->bind_param("i", $supervisor_id);
    $students_stmt->execute();
    $students_result = $students_stmt->get_result();
    
    while ($row = $students_result->fetch_assoc()) {
        $full_name = trim($row['first_name'] . ' ' . $row['middle_name'] . ' ' . $row['last_name']);
        $student_initials = strtoupper(substr($row['first_name'], 0, 1) . substr($row['last_name'], 0, 1));
        
        $supervised_students[] = [
            'id' => 'student_' . $row['id'],
            'student_id' => $row['id'],
            'name' => $full_name,
            'role' => $row['program'] . ' - ' . $row['year_level'] . ' (' . $row['student_id'] . ')',
            'email' => $row['email'],
            'type' => 'student',
            'profile_picture' => $row['profile_picture'],
            'initials' => $student_initials,
            'deployment_position' => $row['deployment_position'],
            'start_date' => $row['start_date'],
            'end_date' => $row['end_date'],
            'available' => true
        ];
    }
} catch (Exception $e) {
    // Handle error silently
}

// Handle AJAX requests
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');
    
    switch ($_POST['action']) {
        case 'send_message':
            $recipient_id_full = $_POST['recipient_id'];
            $message = trim($_POST['message']);
            $recipient_type = $_POST['recipient_type'];
            
            // Extract the actual student ID (remove prefix)
            $student_id = str_replace('student_', '', $recipient_id_full);
            
            if (empty($message)) {
                echo json_encode(['success' => false, 'error' => 'Message cannot be empty']);
                exit();
            }
            
            // Validate that this supervisor can message this student
            $can_send = false;
            if ($recipient_type === 'student') {
                $verify_stmt = $conn->prepare("
                    SELECT COUNT(*) as count 
                    FROM student_deployments 
                    WHERE supervisor_id = ? AND student_id = ? AND status = 'Active'
                ");
                $verify_stmt->bind_param("ii", $supervisor_id, $student_id);
                $verify_stmt->execute();
                $verify_result = $verify_stmt->get_result();
                $verify_data = $verify_result->fetch_assoc();
                $can_send = $verify_data['count'] > 0;
            }
            
            if (!$can_send) {
                echo json_encode(['success' => false, 'error' => 'You cannot send messages to this student']);
                exit();
            }
            
            try {
                $insert_stmt = $conn->prepare("
                    INSERT INTO messages (sender_id, sender_type, recipient_id, recipient_type, message, sent_at) 
                    VALUES (?, 'supervisor', ?, ?, ?, NOW())
                ");
                $insert_stmt->bind_param("isss", $supervisor_id, $student_id, $recipient_type, $message);
                
                if ($insert_stmt->execute()) {
                    echo json_encode(['success' => true, 'message' => 'Message sent successfully']);
                } else {
                    echo json_encode(['success' => false, 'error' => 'Failed to send message']);
                }
            } catch (Exception $e) {
                echo json_encode(['success' => false, 'error' => 'Database error: ' . $e->getMessage()]);
            }
            exit();
            
        case 'get_messages':
            $recipient_id_full = $_POST['recipient_id'];
            $recipient_type = $_POST['recipient_type'];
            
            // Extract the actual student ID (remove prefix)
            $student_id = str_replace('student_', '', $recipient_id_full);
            
            // Verify supervisor can access this student's messages
            $verify_stmt = $conn->prepare("
                SELECT COUNT(*) as count 
                FROM student_deployments 
                WHERE supervisor_id = ? AND student_id = ? AND status = 'Active'
            ");
            $verify_stmt->bind_param("ii", $supervisor_id, $student_id);
            $verify_stmt->execute();
            $verify_result = $verify_stmt->get_result();
            $verify_data = $verify_result->fetch_assoc();
            
            if ($verify_data['count'] == 0) {
                echo json_encode(['success' => false, 'error' => 'Unauthorized access to student messages']);
                exit();
            }
            
            try {
                // Get messages between supervisor and student
                $messages_stmt = $conn->prepare("
                    SELECT m.*, 
                           CASE 
                               WHEN m.sender_type = 'supervisor' AND m.sender_id = ? THEN ?
                               WHEN m.sender_type = 'student' THEN CONCAT(s.first_name, ' ', COALESCE(s.middle_name, ''), ' ', s.last_name)
                           END as sender_name,
                           CASE 
                               WHEN m.sender_type = 'supervisor' THEN NULL
                               WHEN m.sender_type = 'student' THEN s.profile_picture
                           END as sender_avatar
                    FROM messages m
                    LEFT JOIN students s ON m.sender_id = s.id AND m.sender_type = 'student'
                    WHERE (
                        -- Messages from supervisor to student
                        (m.sender_id = ? AND m.sender_type = 'supervisor' AND m.recipient_id = ? AND m.recipient_type = 'student')
                        OR 
                        -- Messages from student to supervisor
                        (m.sender_id = ? AND m.sender_type = 'student' AND m.recipient_id = ? AND m.recipient_type = 'supervisor')
                    )
                    ORDER BY m.sent_at ASC
                ");
                
                $messages_stmt->bind_param("isiiii", 
                    $supervisor_id, $supervisor_name,  // For displaying supervisor name
                    $supervisor_id, $student_id,  // Supervisor to student messages
                    $student_id, $supervisor_id   // Student to supervisor messages
                );
                
                $messages_stmt->execute();
                $messages_result = $messages_stmt->get_result();
                
                $messages = [];
                while ($row = $messages_result->fetch_assoc()) {
                    $is_own_message = ($row['sender_type'] === 'supervisor' && $row['sender_id'] == $supervisor_id);
                    
                    $messages[] = [
                        'id' => $row['id'],
                        'message' => $row['message'],
                        'sent_at' => $row['sent_at'],
                        'sender_name' => $row['sender_name'],
                        'sender_avatar' => $row['sender_avatar'],
                        'is_own' => $is_own_message,
                        'is_read' => $row['is_read'],
                        'sender_type' => $row['sender_type']
                    ];
                }
                
                // Mark incoming messages as read
                $mark_read_stmt = $conn->prepare("
                    UPDATE messages SET is_read = 1 
                    WHERE recipient_id = ? AND recipient_type = 'supervisor' AND sender_id = ? AND sender_type = 'student' AND is_read = 0
                ");
                $mark_read_stmt->bind_param("ii", $supervisor_id, $student_id);
                $mark_read_stmt->execute();
                
                echo json_encode(['success' => true, 'messages' => $messages]);
            } catch (Exception $e) {
                echo json_encode(['success' => false, 'error' => 'Failed to load messages: ' . $e->getMessage()]);
            }
            exit();
            
        case 'get_unread_count':
            try {
                $unread_stmt = $conn->prepare("
                    SELECT COUNT(*) as unread_count 
                    FROM messages 
                    WHERE recipient_id = ? AND recipient_type = 'supervisor' AND is_read = 0
                ");
                $unread_stmt->bind_param("i", $supervisor_id);
                $unread_stmt->execute();
                $unread_result = $unread_stmt->get_result();
                $unread_data = $unread_result->fetch_assoc();
                
                echo json_encode(['success' => true, 'count' => $unread_data['unread_count']]);
            } catch (Exception $e) {
                echo json_encode(['success' => false, 'error' => 'Failed to get unread count']);
            }
            exit();
            
        case 'get_individual_unread_count':
            $recipient_id_full = $_POST['recipient_id'];
            $recipient_type = $_POST['recipient_type'];
            $student_id = str_replace('student_', '', $recipient_id_full);
            
            try {
                $individual_unread_stmt = $conn->prepare("
                    SELECT COUNT(*) as unread_count 
                    FROM messages 
                    WHERE recipient_id = ? AND recipient_type = 'supervisor' 
                    AND sender_id = ? AND sender_type = 'student' AND is_read = 0
                ");
                $individual_unread_stmt->bind_param("ii", $supervisor_id, $student_id);
                $individual_unread_stmt->execute();
                $individual_unread_result = $individual_unread_stmt->get_result();
                $individual_unread_data = $individual_unread_result->fetch_assoc();
                
                echo json_encode([
                    'success' => true, 
                    'count' => $individual_unread_data['unread_count'],
                    'contact_id' => $recipient_id_full
                ]);
            } catch (Exception $e) {
                echo json_encode(['success' => false, 'error' => 'Failed to get individual unread count']);
            }
            exit();
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>OnTheJob Tracker - Messages</title>
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

        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }

        /* Custom scrollbar */
        .messages-area::-webkit-scrollbar,
        .students-list::-webkit-scrollbar {
            width: 6px;
        }

        .messages-area::-webkit-scrollbar-track,
        .students-list::-webkit-scrollbar-track {
            background: #f1f1f1;
        }

        .messages-area::-webkit-scrollbar-thumb,
        .students-list::-webkit-scrollbar-thumb {
            background: #c1c1c1;
            border-radius: 3px;
        }

        .messages-area::-webkit-scrollbar-thumb:hover,
        .students-list::-webkit-scrollbar-thumb:hover {
            background: #a8a8a8;
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
            <a href="CompanyProgressReport.php" class="nav-item flex items-center px-3 py-2 text-sm font-medium text-bulsu-light-gold hover:text-white hover:bg-bulsu-gold hover:bg-opacity-20 rounded-md transition-all duration-200">
                <i class="fas fa-chart-line mr-3"></i>
                Student Progress Report
            </a>
            <a href="StudentEvaluate.php" class="nav-item flex items-center px-3 py-2 text-sm font-medium text-bulsu-light-gold hover:text-white hover:bg-bulsu-gold hover:bg-opacity-20 rounded-md transition-all duration-200">
                <i class="fas fa-star mr-3"></i>
                Student Evaluation
            </a>
            <a href="CompanyMessage.php" class="nav-item flex items-center px-3 py-2 text-sm font-medium text-white bg-bulsu-gold bg-opacity-20 border border-bulsu-gold border-opacity-30 rounded-md">
                <i class="fas fa-envelope mr-3 text-bulsu-gold"></i>
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
                    <h1 class="text-xl sm:text-2xl font-bold text-gray-900">Messages</h1>
                    <p class="text-sm sm:text-base text-gray-500 hidden sm:block">Communicate with your supervised students</p>
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

            <?php if (!empty($supervised_students)): ?>
    <div class="mb-6 bg-white border border-bulsu-maroon rounded-lg shadow-sm overflow-hidden">
        <!-- Header -->
        <div class="bg-gradient-to-r from-bulsu-maroon to-bulsu-dark-maroon px-4 py-3">
            <h4 class="text-white font-medium flex items-center">
                <i class="fas fa-users text-bulsu-gold mr-2"></i>
                Supervised Students
            </h4>
        </div>
        <!-- Body -->
        <div class="p-4">
            <p class="text-gray-700 text-sm">
                You are currently supervising 
                <span class="font-semibold text-bulsu-maroon">
                    <?php echo count($supervised_students); ?> student(s)
                </span> 
                at 
                <strong class="text-bulsu-dark-maroon">
                    <?php echo htmlspecialchars($company_name); ?>
                </strong>
            </p>
        </div>
    </div>
<?php endif; ?>


            <!-- Messages Container -->
            <div class="bg-white rounded-lg shadow-sm border border-gray-200 overflow-hidden" style="height: calc(100vh - 280px);">
                <div class="grid grid-cols-1 lg:grid-cols-3 h-full">
                    <!-- Students Sidebar -->
                    <div id="studentsSidebar" class="bg-gray-50 border-r border-gray-200 flex flex-col lg:block hidden lg:flex">
                        <!-- Students Header -->
                        <div class="p-4 sm:p-6 border-b border-gray-200 bg-white">
                            <button class="lg:hidden mb-4 text-blue-600 hover:text-blue-800" onclick="hideMobileStudents()" id="mobileBackBtn">
                                <i class="fas fa-arrow-left mr-2"></i>
                                Back to Chat
                            </button>
                            <h3 class="text-lg font-medium text-gray-900 flex items-center">
                                <i class="fas fa-graduation-cap mr-3 text-blue-600"></i>
                                My Students
                            </h3>
                        </div>
                        
                        <!-- Students List -->
                        <div class="flex-1 overflow-y-auto students-list">
                            <?php if (empty($supervised_students)): ?>
                                <div class="p-6 text-center">
                                    <i class="fas fa-user-graduate text-gray-400 text-4xl mb-4"></i>
                                    <h3 class="text-lg font-medium text-gray-900 mb-2">No students assigned yet</h3>
                                    <p class="text-gray-600 text-sm">Students will appear here once they are deployed under your supervision</p>
                                </div>
                            <?php else: ?>
                                <?php foreach ($supervised_students as $student): ?>
                                    <div class="p-4 cursor-pointer hover:bg-white border-b border-gray-100 transition-colors duration-200 student-item relative" 
                                         data-contact-id="<?php echo $student['id']; ?>"
                                         data-contact-name="<?php echo htmlspecialchars($student['name']); ?>"
                                         data-contact-role="<?php echo htmlspecialchars($student['role']); ?>"
                                         data-contact-type="<?php echo $student['type']; ?>"
                                         data-student-id="<?php echo $student['student_id']; ?>"
                                         data-profile-picture="<?php echo htmlspecialchars($student['profile_picture'] ?? ''); ?>"
                                         data-initials="<?php echo $student['initials']; ?>"
                                         onclick="selectStudent(this)">
                                        <div class="flex items-center space-x-3">
                                            <div class="flex-shrink-0 w-12 h-12 bg-gradient-to-r from-purple-500 to-pink-600 rounded-full flex items-center justify-center text-white font-semibold">
                                                <?php if (!empty($student['profile_picture']) && file_exists($student['profile_picture'])): ?>
                                                    <img src="<?php echo htmlspecialchars($student['profile_picture']); ?>" alt="Profile Picture" class="w-full h-full rounded-full object-cover">
                                                <?php else: ?>
                                                    <?php echo $student['initials']; ?>
                                                <?php endif; ?>
                                            </div>
                                            <div class="flex-1 min-w-0">
                                                <p class="text-sm font-medium text-gray-900 truncate"><?php echo htmlspecialchars($student['name']); ?></p>
                                                <p class="text-xs text-gray-500 truncate"><?php echo htmlspecialchars($student['role']); ?></p>
                                                <div class="flex items-center mt-1">
                                                    <div class="w-2 h-2 bg-green-500 rounded-full mr-2"></div>
                                                    <p class="text-xs text-green-600"><?php echo htmlspecialchars($student['deployment_position']); ?></p>
                                                </div>
                                            </div>
                                            <div class="unread-badge absolute top-4 right-4 bg-red-500 text-white rounded-full w-5 h-5 flex items-center justify-center text-xs font-bold hidden">0</div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Chat Area -->
                    <div class="col-span-2 flex flex-col h-full">
                        <!-- Chat Header -->
                        <div id="chatHeader" class="p-4 sm:p-6 border-b border-gray-200 bg-white hidden">
                            <div class="flex items-center justify-between">
                                <div class="flex items-center space-x-4">
                                    <button class="lg:hidden text-blue-600 hover:text-blue-800" onclick="showMobileStudents()" id="mobileStudentsBtn">
                                        <i class="fas fa-arrow-left mr-2"></i>
                                    </button>
                                    <div id="chatAvatar" class="flex-shrink-0 w-12 h-12 bg-gradient-to-r from-purple-500 to-pink-600 rounded-full flex items-center justify-center text-white font-semibold">
                                        <!-- Avatar will be populated by JavaScript -->
                                    </div>
                                    <div>
                                        <h3 id="chatName" class="text-lg font-medium text-gray-900">Select a student</h3>
                                        <p id="chatRole" class="text-sm text-gray-500">Choose a student to start messaging</p>
                                    </div>
                                </div>
                                <div class="flex items-center space-x-2">
                                    <div class="flex items-center text-green-600">
                                        <div class="w-2 h-2 bg-green-500 rounded-full mr-2"></div>
                                        <span class="text-xs font-medium">Active</span>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Messages Area -->
                        <div id="messagesArea" class="flex-1 overflow-y-auto p-4 sm:p-6 messages-area bg-gray-50">
                            <!-- Welcome State -->
                            <div id="welcomeState" class="flex flex-col items-center justify-center h-full text-center">
                                <div class="bg-white rounded-lg p-8 max-w-md mx-auto shadow-sm border border-gray-200">
                                    <i class="fas fa-comments text-gray-400 text-6xl mb-6"></i>
                                    <h3 class="text-xl font-semibold text-gray-900 mb-4">Welcome to Messages</h3>
                                    <p class="text-gray-600 mb-6">Select a student from the sidebar to start a conversation. You can communicate with all students under your supervision.</p>
                                    <button class="lg:hidden bg-blue-600 text-white px-6 py-2 rounded-lg hover:bg-blue-700 transition-colors" onclick="showMobileStudents()">
                                        <i class="fas fa-user-graduate mr-2"></i>
                                        View Students
                                    </button>
                                </div>
                            </div>

                            <!-- Messages Container -->
                            <div id="messagesContainer" class="space-y-4 hidden">
                                <!-- Messages will be loaded here via JavaScript -->
                            </div>

                            <!-- Loading State -->
                            <div id="loadingMessages" class="flex justify-center items-center py-8 hidden">
                                <div class="text-center">
                                    <div class="inline-block animate-spin rounded-full h-8 w-8 border-b-2 border-blue-600 mb-4"></div>
                                    <p class="text-gray-600">Loading messages...</p>
                                </div>
                            </div>
                        </div>

                        <!-- Message Input -->
                        <div id="messageInput" class="p-4 sm:p-6 bg-white border-t border-gray-200 hidden">
                            <form id="messageForm" class="flex space-x-4">
                                <div class="flex-1">
                                    <textarea id="messageText" 
                                              placeholder="Type your message here..." 
                                              class="w-full px-4 py-3 border border-gray-300 rounded-lg resize-none focus:ring-2 focus:ring-blue-500 focus:border-transparent"
                                              rows="1"
                                              style="min-height: 44px; max-height: 120px;"></textarea>
                                </div>
                                <button type="submit" 
                                        id="sendButton"
                                        class="bg-blue-600 text-white px-6 py-3 rounded-lg hover:bg-blue-700 focus:ring-2 focus:ring-blue-500 focus:ring-offset-2 transition-colors flex items-center justify-center min-w-[100px]"
                                        disabled>
                                    <span class="send-text">
                                        <i class="fas fa-paper-plane mr-2"></i>
                                        Send
                                    </span>
                                    <span class="sending-text hidden">
                                        <div class="animate-spin rounded-full h-4 w-4 border-2 border-white border-t-transparent mr-2"></div>
                                        Sending...
                                    </span>
                                </button>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Success/Error Toast -->
    <div id="toast" class="fixed top-4 right-4 z-50 hidden">
        <div class="bg-white border border-gray-200 rounded-lg shadow-lg p-4 max-w-sm">
            <div class="flex items-center">
                <div id="toastIcon" class="flex-shrink-0 w-6 h-6 mr-3">
                    <!-- Icon will be set by JavaScript -->
                </div>
                <div class="flex-1">
                    <p id="toastMessage" class="text-sm font-medium text-gray-900"></p>
                </div>
                <button onclick="hideToast()" class="ml-4 text-gray-400 hover:text-gray-600">
                    <i class="fas fa-times"></i>
                </button>
            </div>
        </div>
    </div>

    <script>
        // Global variables
        let currentContactId = null;
        let currentContactType = null;
        let messagesPollingInterval = null;
        let unreadCountInterval = null;

        // DOM elements
        const sidebar = document.getElementById('sidebar');
        const sidebarOverlay = document.getElementById('sidebarOverlay');
        const mobileMenuBtn = document.getElementById('mobileMenuBtn');
        const closeSidebar = document.getElementById('closeSidebar');
        const profileBtn = document.getElementById('profileBtn');
        const profileDropdown = document.getElementById('profileDropdown');
        const messageForm = document.getElementById('messageForm');
        const messageText = document.getElementById('messageText');
        const sendButton = document.getElementById('sendButton');
        const messagesContainer = document.getElementById('messagesContainer');
        const loadingMessages = document.getElementById('loadingMessages');
        const welcomeState = document.getElementById('welcomeState');
        const chatHeader = document.getElementById('chatHeader');
        const messageInput = document.getElementById('messageInput');
        const studentsSidebar = document.getElementById('studentsSidebar');

        // Initialize page
        document.addEventListener('DOMContentLoaded', function() {
            setupEventListeners();
            startUnreadCountPolling();
            autoResizeTextarea();
        });

        // Event listeners setup
        function setupEventListeners() {
            // Mobile menu toggle
            mobileMenuBtn?.addEventListener('click', () => {
                sidebar.classList.remove('-translate-x-full');
                sidebarOverlay.classList.remove('hidden');
                sidebarOverlay.classList.add('opacity-50');
            });

            // Close sidebar
            closeSidebar?.addEventListener('click', closeMobileSidebar);
            sidebarOverlay?.addEventListener('click', closeMobileSidebar);

            // Profile dropdown
            profileBtn?.addEventListener('click', (e) => {
                e.stopPropagation();
                profileDropdown.classList.toggle('hidden');
            });

            // Close dropdown when clicking outside
            document.addEventListener('click', (e) => {
                if (!profileBtn?.contains(e.target)) {
                    profileDropdown?.classList.add('hidden');
                }
            });

            // Message form
            messageForm?.addEventListener('submit', sendMessage);
            
            // Auto-resize textarea
            messageText?.addEventListener('input', autoResizeTextarea);
            messageText?.addEventListener('keydown', function(e) {
                if (e.key === 'Enter' && !e.shiftKey) {
                    e.preventDefault();
                    sendMessage(e);
                }
            });
        }

        // Close mobile sidebar
        function closeMobileSidebar() {
            sidebar.classList.add('-translate-x-full');
            sidebarOverlay.classList.add('hidden');
            sidebarOverlay.classList.remove('opacity-50');
        }

        // Auto-resize textarea
        function autoResizeTextarea() {
            if (messageText) {
                messageText.style.height = 'auto';
                messageText.style.height = Math.min(messageText.scrollHeight, 120) + 'px';
                
                // Enable/disable send button
                sendButton.disabled = messageText.value.trim() === '';
            }
        }

        // Select student
        function selectStudent(element) {
            // Remove active class from all students
            document.querySelectorAll('.student-item').forEach(item => {
                item.classList.remove('bg-blue-50', 'border-l-4', 'border-blue-600');
            });
            
            // Add active class to selected student
            element.classList.add('bg-blue-50', 'border-l-4', 'border-blue-600');
            
            // Get student data
            const contactId = element.dataset.contactId;
            const contactName = element.dataset.contactName;
            const contactRole = element.dataset.contactRole;
            const contactType = element.dataset.contactType;
            const profilePicture = element.dataset.profilePicture;
            const initials = element.dataset.initials;
            
            // Update global variables
            currentContactId = contactId;
            currentContactType = contactType;
            
            // Update chat header
            updateChatHeader(contactName, contactRole, profilePicture, initials);
            
            // Show chat interface
            showChatInterface();
            
            // Load messages
            loadMessages();
            
            // Start polling for new messages
            startMessagesPolling();
            
            // Hide mobile students sidebar on mobile
            if (window.innerWidth < 1024) {
                hideMobileStudents();
            }
        }

        // Update chat header
        function updateChatHeader(name, role, profilePicture, initials) {
            const chatName = document.getElementById('chatName');
            const chatRole = document.getElementById('chatRole');
            const chatAvatar = document.getElementById('chatAvatar');
            
            if (chatName) chatName.textContent = name;
            if (chatRole) chatRole.textContent = role;
            
            if (chatAvatar) {
                if (profilePicture && profilePicture.trim() !== '') {
                    chatAvatar.innerHTML = `<img src="${profilePicture}" alt="Profile Picture" class="w-full h-full rounded-full object-cover">`;
                } else {
                    chatAvatar.innerHTML = initials;
                }
            }
        }

        // Show chat interface
        function showChatInterface() {
            welcomeState?.classList.add('hidden');
            chatHeader?.classList.remove('hidden');
            messageInput?.classList.remove('hidden');
            messagesContainer?.classList.remove('hidden');
        }

        // Mobile functions
        function showMobileStudents() {
            studentsSidebar.classList.remove('hidden');
            studentsSidebar.classList.add('block');
        }

        function hideMobileStudents() {
            if (window.innerWidth < 1024) {
                studentsSidebar.classList.add('hidden');
                studentsSidebar.classList.remove('block');
            }
        }

        // Load messages
        async function loadMessages() {
            if (!currentContactId || !currentContactType) return;
            
            try {
                showLoadingMessages();
                
                const formData = new FormData();
                formData.append('action', 'get_messages');
                formData.append('recipient_id', currentContactId);
                formData.append('recipient_type', currentContactType);
                
                const response = await fetch(window.location.href, {
                    method: 'POST',
                    body: formData
                });
                
                const data = await response.json();
                
                if (data.success) {
                    displayMessages(data.messages);
                } else {
                    showToast('Error loading messages: ' + (data.error || 'Unknown error'), 'error');
                }
            } catch (error) {
                console.error('Error loading messages:', error);
                showToast('Failed to load messages', 'error');
            } finally {
                hideLoadingMessages();
            }
        }

        // Display messages
        function displayMessages(messages) {
            if (!messagesContainer) return;
            
            messagesContainer.innerHTML = '';
            
            if (messages.length === 0) {
                messagesContainer.innerHTML = `
                    <div class="text-center py-8">
                        <i class="fas fa-comments text-gray-400 text-4xl mb-4"></i>
                        <p class="text-gray-600">No messages yet. Start the conversation!</p>
                    </div>
                `;
                return;
            }
            
            messages.forEach(message => {
                const messageElement = createMessageElement(message);
                messagesContainer.appendChild(messageElement);
            });
            
            // Scroll to bottom
            messagesContainer.scrollTop = messagesContainer.scrollHeight;
        }

        // Create message element
        function createMessageElement(message) {
            const messageDiv = document.createElement('div');
            const isOwnMessage = message.is_own;
            const timestamp = new Date(message.sent_at).toLocaleString('en-US', {
                month: 'short',
                day: 'numeric',
                hour: 'numeric',
                minute: '2-digit',
                hour12: true
            });
            
            messageDiv.className = `flex ${isOwnMessage ? 'justify-end' : 'justify-start'}`;
            
            messageDiv.innerHTML = `
                <div class="max-w-xs lg:max-w-md">
                    ${!isOwnMessage ? `
                        <div class="flex items-center mb-1">
                            <div class="w-6 h-6 bg-gradient-to-r from-purple-500 to-pink-600 rounded-full flex items-center justify-center text-white text-xs font-semibold mr-2">
                                ${message.sender_avatar && message.sender_avatar.trim() !== '' ? 
                                    `<img src="${message.sender_avatar}" alt="Avatar" class="w-full h-full rounded-full object-cover">` : 
                                    message.sender_name ? message.sender_name.split(' ').map(n => n[0]).join('').substring(0, 2).toUpperCase() : 'ST'
                                }
                            </div>
                            <span class="text-xs text-gray-600 font-medium">${message.sender_name || 'Student'}</span>
                        </div>
                    ` : ''}
                    <div class="relative group">
                        <div class="${isOwnMessage ? 
                            'bg-blue-600 text-white rounded-l-lg rounded-br-lg' : 
                            'bg-white text-gray-900 border border-gray-200 rounded-r-lg rounded-bl-lg'
                        } px-4 py-3 shadow-sm">
                            <p class="text-sm whitespace-pre-wrap break-words">${escapeHtml(message.message)}</p>
                        </div>
                        <div class="text-xs text-gray-500 mt-1 ${isOwnMessage ? 'text-right' : 'text-left'}">
                            ${timestamp}
                            ${isOwnMessage ? '<i class="fas fa-check ml-1 text-gray-400"></i>' : ''}
                        </div>
                    </div>
                </div>
            `;
            
            return messageDiv;
        }

        // Send message
        async function sendMessage(e) {
            e.preventDefault();
            
            if (!currentContactId || !currentContactType) {
                showToast('Please select a student first', 'error');
                return;
            }
            
            const message = messageText.value.trim();
            if (!message) return;
            
            try {
                // Update UI
                setSendingState(true);
                
                const formData = new FormData();
                formData.append('action', 'send_message');
                formData.append('recipient_id', currentContactId);
                formData.append('recipient_type', currentContactType);
                formData.append('message', message);
                
                const response = await fetch(window.location.href, {
                    method: 'POST',
                    body: formData
                });
                
                const data = await response.json();
                
                if (data.success) {
                    messageText.value = '';
                    autoResizeTextarea();
                    loadMessages(); // Reload messages to show the new one
                    showToast('Message sent successfully', 'success');
                } else {
                    showToast('Error sending message: ' + (data.error || 'Unknown error'), 'error');
                }
            } catch (error) {
                console.error('Error sending message:', error);
                showToast('Failed to send message', 'error');
            } finally {
                setSendingState(false);
            }
        }

        // Set sending state
        function setSendingState(sending) {
            const sendText = sendButton?.querySelector('.send-text');
            const sendingText = sendButton?.querySelector('.sending-text');
            
            if (sending) {
                sendText?.classList.add('hidden');
                sendingText?.classList.remove('hidden');
                sendButton.disabled = true;
            } else {
                sendText?.classList.remove('hidden');
                sendingText?.classList.add('hidden');
                sendButton.disabled = messageText.value.trim() === '';
            }
        }

        // Show/hide loading messages
        function showLoadingMessages() {
            loadingMessages?.classList.remove('hidden');
            messagesContainer?.classList.add('hidden');
        }

        function hideLoadingMessages() {
            loadingMessages?.classList.add('hidden');
            messagesContainer?.classList.remove('hidden');
        }

        // Start messages polling
        function startMessagesPolling() {
            // Clear existing interval
            if (messagesPollingInterval) {
                clearInterval(messagesPollingInterval);
            }
            
            // Poll every 3 seconds
            messagesPollingInterval = setInterval(() => {
                if (currentContactId && currentContactType) {
                    loadMessages();
                }
            }, 3000);
        }

        // Start unread count polling
        function startUnreadCountPolling() {
            updateUnreadCounts();
            
            unreadCountInterval = setInterval(updateUnreadCounts, 5000);
        }

        // Update unread counts
        async function updateUnreadCounts() {
            try {
                // Update total unread count
                const totalResponse = await fetch(window.location.href, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: 'action=get_unread_count'
                });
                
                const totalData = await totalResponse.json();
                if (totalData.success) {
                    updateTotalUnreadDisplay(totalData.count);
                }
                
                // Update individual unread counts
                const studentItems = document.querySelectorAll('.student-item');
                for (const item of studentItems) {
                    const contactId = item.dataset.contactId;
                    const contactType = item.dataset.contactType;
                    
                    const individualResponse = await fetch(window.location.href, {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                        body: `action=get_individual_unread_count&recipient_id=${contactId}&recipient_type=${contactType}`
                    });
                    
                    const individualData = await individualResponse.json();
                    if (individualData.success) {
                        updateIndividualUnreadDisplay(contactId, individualData.count);
                    }
                }
            } catch (error) {
                console.error('Error updating unread counts:', error);
            }
        }

        // Update total unread display
        function updateTotalUnreadDisplay(count) {
            const totalUnreadElement = document.getElementById('totalUnreadCount');
            if (totalUnreadElement) {
                if (count > 0) {
                    totalUnreadElement.textContent = count;
                    totalUnreadElement.classList.remove('hidden');
                } else {
                    totalUnreadElement.classList.add('hidden');
                }
            }
        }

        // Update individual unread display
        function updateIndividualUnreadDisplay(contactId, count) {
            const studentItem = document.querySelector(`[data-contact-id="${contactId}"]`);
            if (studentItem) {
                const badge = studentItem.querySelector('.unread-badge');
                if (badge) {
                    if (count > 0) {
                        badge.textContent = count;
                        badge.classList.remove('hidden');
                    } else {
                        badge.classList.add('hidden');
                    }
                }
            }
        }

        // Show toast notification
        function showToast(message, type = 'info') {
            const toast = document.getElementById('toast');
            const toastMessage = document.getElementById('toastMessage');
            const toastIcon = document.getElementById('toastIcon');
            
            if (!toast || !toastMessage || !toastIcon) return;
            
            // Set message
            toastMessage.textContent = message;
            
            // Set icon based on type
            let iconClass = '';
            let iconColor = '';
            
            switch (type) {
                case 'success':
                    iconClass = 'fas fa-check-circle';
                    iconColor = 'text-green-600';
                    break;
                case 'error':
                    iconClass = 'fas fa-exclamation-circle';
                    iconColor = 'text-red-600';
                    break;
                case 'warning':
                    iconClass = 'fas fa-exclamation-triangle';
                    iconColor = 'text-yellow-600';
                    break;
                default:
                    iconClass = 'fas fa-info-circle';
                    iconColor = 'text-blue-600';
            }
            
            toastIcon.innerHTML = `<i class="${iconClass} ${iconColor}"></i>`;
            
            // Show toast
            toast.classList.remove('hidden');
            
            // Auto hide after 5 seconds
            setTimeout(() => {
                hideToast();
            }, 5000);
        }

        // Hide toast
        function hideToast() {
            const toast = document.getElementById('toast');
            toast?.classList.add('hidden');
        }

        // Utility function to escape HTML
        function escapeHtml(unsafe) {
            return unsafe
                .replace(/&/g, "&amp;")
                .replace(/</g, "&lt;")
                .replace(/>/g, "&gt;")
                .replace(/"/g, "&quot;")
                .replace(/'/g, "&#039;");
        }

        // Confirm logout
        function confirmLogout() {
            return confirm('Are you sure you want to logout?');
        }

        // Handle window resize
        window.addEventListener('resize', function() {
            if (window.innerWidth >= 1024) {
                // Desktop view - show students sidebar
                studentsSidebar.classList.remove('hidden');
                studentsSidebar.classList.add('lg:flex');
            }
        });

        // Cleanup intervals when page unloads
        window.addEventListener('beforeunload', function() {
            if (messagesPollingInterval) {
                clearInterval(messagesPollingInterval);
            }
            if (unreadCountInterval) {
                clearInterval(unreadCountInterval);
            }
        });
    </script>
</body>
</html>