<?php
include('connect.php');
session_start();

// Cache control headers
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
// Fetch student data
try {
    $stmt = $conn->prepare("SELECT first_name, middle_name, last_name, email, student_id, department, program, year_level, profile_picture, verified FROM students WHERE id = ?");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows > 0) {
        $student = $result->fetch_assoc();
        $full_name = trim($student['first_name'] . ' ' . $student['middle_name'] . ' ' . $student['last_name']);
        $is_verified = $student['verified'];
        $initials = strtoupper(substr($student['first_name'], 0, 1) . substr($student['last_name'], 0, 1));
    } else {
        header("Location: login.php");
        exit();
    }
} catch (Exception $e) {
    echo "Error: " . $e->getMessage();
    exit();
}

// Check deployment status
$is_deployed = false;
$deployment_info = null;
try {
    $deploy_stmt = $conn->prepare("
        SELECT sd.*, cs.full_name as supervisor_name, cs.email as supervisor_email 
        FROM student_deployments sd 
        LEFT JOIN company_supervisors cs ON sd.supervisor_id = cs.supervisor_id 
        WHERE sd.student_id = ? AND sd.status = 'Active'
    ");
    $deploy_stmt->bind_param("i", $user_id);
    $deploy_stmt->execute();
    $deploy_result = $deploy_stmt->get_result();
    
    if ($deploy_result->num_rows > 0) {
        $is_deployed = true;
        $deployment_info = $deploy_result->fetch_assoc();
    }
} catch (Exception $e) {
    // Handle error silently
}

// Get available contacts
$contacts = [];

// Always add academic adviser
try {
    $adviser_stmt = $conn->prepare("SELECT id, name, email FROM academic_adviser ORDER BY name ASC");
    $adviser_stmt->execute();
    $adviser_result = $adviser_stmt->get_result();
    
    while ($row = $adviser_result->fetch_assoc()) {
        $contacts[] = [
            'id' => 'adviser_' . $row['id'],
            'name' => $row['name'],
            'role' => 'Academic Adviser',
            'email' => $row['email'],
            'type' => 'adviser',
            'available' => true
        ];
    }
} catch (Exception $e) {
    // Handle error
}

// Add company supervisor if deployed
if ($is_deployed && $deployment_info && $deployment_info['supervisor_id']) {
    try {
        $supervisor_stmt = $conn->prepare("SELECT supervisor_id, full_name, email, position, company_name FROM company_supervisors WHERE supervisor_id = ?");
        $supervisor_stmt->bind_param("i", $deployment_info['supervisor_id']);
        $supervisor_stmt->execute();
        $supervisor_result = $supervisor_stmt->get_result();
        
        if ($supervisor_result->num_rows > 0) {
            $supervisor = $supervisor_result->fetch_assoc();
            $contacts[] = [
                'id' => 'supervisor_' . $supervisor['supervisor_id'],
                'name' => $supervisor['full_name'],
                'role' => $supervisor['position'] . ' at ' . $supervisor['company_name'],
                'email' => $supervisor['email'],
                'type' => 'supervisor',
                'available' => true
            ];
        }
    } catch (Exception $e) {
        // Handle error
    }
}

// Handle AJAX requests
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');
    
    switch ($_POST['action']) {
        case 'send_message':
            $recipient_id_full = $_POST['recipient_id'];
            $message = trim($_POST['message']);
            $recipient_type = $_POST['recipient_type'];
            
            // Extract the actual ID (remove prefix)
            $recipient_id = str_replace(['adviser_', 'supervisor_'], '', $recipient_id_full);
            
            if (empty($message)) {
                echo json_encode(['success' => false, 'error' => 'Message cannot be empty']);
                exit();
            }
            
            // Validate recipient access
            $can_send = false;
            if ($recipient_type === 'adviser') {
                $can_send = true; // All students can message adviser
            } elseif ($recipient_type === 'supervisor' && $is_deployed) {
                $can_send = true; // Only deployed students can message supervisor
            }
            
            if (!$can_send) {
                echo json_encode(['success' => false, 'error' => 'You cannot send messages to this recipient']);
                exit();
            }
            
            try {
                $insert_stmt = $conn->prepare("
                    INSERT INTO messages (sender_id, sender_type, recipient_id, recipient_type, message, sent_at) 
                    VALUES (?, 'student', ?, ?, ?, NOW())
                ");
                $insert_stmt->bind_param("isss", $user_id, $recipient_id, $recipient_type, $message);
                
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
            
            // Extract the actual ID (remove prefix)
            $recipient_id = str_replace(['adviser_', 'supervisor_'], '', $recipient_id_full);
            
            try {
                // Fixed query to properly fetch all messages between student and contact
                $messages_stmt = $conn->prepare("
                    SELECT m.*, 
                           CASE 
                               WHEN m.sender_type = 'student' AND m.sender_id = ? THEN ?
                               WHEN m.sender_type = 'adviser' THEN aa.name
                               WHEN m.sender_type = 'supervisor' THEN cs.full_name
                           END as sender_name,
                           CASE 
                               WHEN m.sender_type = 'student' THEN s.profile_picture
                               ELSE NULL
                           END as sender_avatar
                    FROM messages m
                    LEFT JOIN students s ON m.sender_id = s.id AND m.sender_type = 'student'
                    LEFT JOIN academic_adviser aa ON m.sender_id = aa.id AND m.sender_type = 'adviser'
                    LEFT JOIN company_supervisors cs ON m.sender_id = cs.supervisor_id AND m.sender_type = 'supervisor'
                    WHERE (
                        -- Messages from student to contact
                        (m.sender_id = ? AND m.sender_type = 'student' AND m.recipient_id = ? AND m.recipient_type = ?)
                        OR 
                        -- Messages from contact to student
                        (m.sender_id = ? AND m.sender_type = ? AND m.recipient_id = ? AND m.recipient_type = 'student')
                    )
                    ORDER BY m.sent_at ASC
                ");
                
                $messages_stmt->bind_param("isiisisi", 
                    $user_id, $full_name,  // For displaying student name
                    $user_id, $recipient_id, $recipient_type,  // Student to contact messages
                    $recipient_id, $recipient_type, $user_id  // Contact to student messages
                );
                
                $messages_stmt->execute();
                $messages_result = $messages_stmt->get_result();
                
                $messages = [];
                while ($row = $messages_result->fetch_assoc()) {
                    $is_own_message = ($row['sender_type'] === 'student' && $row['sender_id'] == $user_id);
                    
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
                    WHERE recipient_id = ? AND recipient_type = 'student' AND sender_id = ? AND sender_type = ? AND is_read = 0
                ");
                $mark_read_stmt->bind_param("iis", $user_id, $recipient_id, $recipient_type);
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
                    WHERE recipient_id = ? AND recipient_type = 'student' AND is_read = 0
                ");
                $unread_stmt->bind_param("i", $user_id);
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
            $recipient_id = str_replace(['adviser_', 'supervisor_'], '', $recipient_id_full);
            
            try {
                $individual_unread_stmt = $conn->prepare("
                    SELECT COUNT(*) as unread_count 
                    FROM messages 
                    WHERE recipient_id = ? AND recipient_type = 'student' 
                    AND sender_id = ? AND sender_type = ? AND is_read = 0
                ");
                $individual_unread_stmt->bind_param("iis", $user_id, $recipient_id, $recipient_type);
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

        .loading i {
            animation: spin 1s linear infinite;
        }

        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }

        /* Custom scrollbar */
        .custom-scrollbar::-webkit-scrollbar {
            width: 6px;
        }

        .custom-scrollbar::-webkit-scrollbar-track {
            background: #f1f1f1;
        }

        .custom-scrollbar::-webkit-scrollbar-thumb {
            background: #c1c1c1;
            border-radius: 3px;
        }

        .custom-scrollbar::-webkit-scrollbar-thumb:hover {
            background: #a8a8a8;
        }

        .message-unread-badge {
            position: absolute;
            right: 15px;
            top: 50%;
            transform: translateY(-50%);
            background: #dc3545;
            color: white;
            border-radius: 50%;
            width: 20px;
            height: 20px;
            font-size: 0.75rem;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: bold;
        }

        .typing-indicator {
            display: flex;
            align-items: center;
            gap: 5px;
            padding: 10px 16px;
            background: white;
            border-radius: 18px;
            margin-bottom: 10px;
        }

        .typing-dot {
            width: 6px;
            height: 6px;
            border-radius: 50%;
            background: #6c757d;
            animation: typing 1.4s infinite ease-in-out;
        }

        .typing-dot:nth-child(2) { animation-delay: 0.2s; }
        .typing-dot:nth-child(3) { animation-delay: 0.4s; }

        @keyframes typing {
            0%, 60%, 100% { transform: translateY(0); opacity: 0.5; }
            30% { transform: translateY(-10px); opacity: 1; }
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
            <a href="studentMessage.php" class="nav-item flex items-center px-3 py-2 text-sm font-medium text-white bg-bulsu-gold bg-opacity-20 border border-bulsu-gold border-opacity-30 rounded-md">
                <i class="fas fa-envelope mr-3  text-bulsu-gold"></i>
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
                    <h1 class="text-xl sm:text-2xl font-bold text-gray-900">Messages</h1>
                    <p class="text-sm sm:text-base text-gray-500 hidden sm:block">Communicate with your academic adviser and company supervisor</p>
                </div>
                
                <!-- Profile Dropdown -->
                <div class="relative">
                    <button id="profileBtn" class="flex items-center p-1 rounded-full hover:bg-gray-100">
                        <div class="w-8 h-8 sm:w-10 sm:h-10 bg-gradient-to-r from-blue-500 to-purple-600 rounded-full flex items-center justify-center text-white font-semibold text-xs sm:text-sm">
                            <?php if (!empty($student['profile_picture']) && file_exists($student['profile_picture'])): ?>
                                <img src="<?php echo htmlspecialchars($student['profile_picture']); ?>" alt="Profile Picture" class="w-full h-full rounded-full object-cover">
                            <?php else: ?>
                                <?php echo $initials; ?>
                            <?php endif; ?>
                        </div>
                    </button>
                    <div id="profileDropdown" class="hidden absolute right-0 mt-2 w-48 sm:w-64 bg-white rounded-md shadow-lg border border-gray-200 z-50">
                        <div class="p-4 border-b border-gray-200">
                            <div class="flex items-center space-x-3">
                                <div class="w-12 h-12 bg-gradient-to-r from-blue-500 to-purple-600 rounded-full flex items-center justify-center text-white font-semibold">
                                    <?php if (!empty($student['profile_picture']) && file_exists($student['profile_picture'])): ?>
                                        <img src="<?php echo htmlspecialchars($student['profile_picture']); ?>" alt="Profile Picture" class="w-full h-full rounded-full object-cover">
                                    <?php else: ?>
                                        <?php echo $initials; ?>
                                    <?php endif; ?>
                                </div>
                                <div>
                                    <p class="font-medium text-gray-900"><?php echo htmlspecialchars($full_name); ?></p>
                                    <p class="text-sm text-gray-500"><?php echo htmlspecialchars($student['student_id']); ?> • <?php echo htmlspecialchars($student['program']); ?></p>
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
            <!-- Deployment Info -->
            <?php if ($is_deployed && $deployment_info): ?>
                <div class="mb-6 p-4 bg-blue-50 border border-blue-200 rounded-lg">
                    <div class="flex items-start">
                        <i class="fas fa-building text-blue-600 mt-1 mr-3"></i>
                        <div>
                            <h3 class="font-medium text-blue-800">Current Deployment</h3>
                            <p class="text-sm text-blue-700 mt-1">
                                <strong><?php echo htmlspecialchars($deployment_info['company_name']); ?></strong> under <?php echo htmlspecialchars($deployment_info['supervisor_name']); ?>
                            </p>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Messages Container -->
            <div class="bg-white rounded-lg shadow-sm border border-gray-200 h-[calc(100vh-250px)] flex">
                <!-- Contacts Sidebar -->
                <div class="w-80 border-r border-gray-200 flex flex-col" id="contactsSidebar">
                    <div class="p-4 border-b border-gray-200">
                        <button class="lg:hidden mb-3 p-2 text-gray-500 hover:text-gray-700" onclick="hideMobileContacts()" id="mobileBackBtn">
                            <i class="fas fa-arrow-left"></i>
                        </button>
                        <h3 class="text-lg font-medium text-gray-900 flex items-center">
                            <i class="fas fa-address-book text-blue-600 mr-3"></i>
                            Contacts
                        </h3>
                    </div>
                    
                    <div class="flex-1 overflow-y-auto custom-scrollbar">
                        <?php if (empty($contacts)): ?>
                            <div class="p-8 text-center text-gray-500">
                                <i class="fas fa-users text-4xl mb-4 opacity-30"></i>
                                <p>No contacts available</p>
                            </div>
                        <?php else: ?>
                            <?php foreach ($contacts as $contact): ?>
                                <div class="p-4 border-b border-gray-100 cursor-pointer hover:bg-gray-50 transition-colors relative contact-item <?php echo $contact['available'] ? '' : 'opacity-50 cursor-not-allowed'; ?>" 
                                     data-contact-id="<?php echo $contact['id']; ?>"
                                     data-contact-name="<?php echo htmlspecialchars($contact['name']); ?>"
                                     data-contact-role="<?php echo htmlspecialchars($contact['role']); ?>"
                                     data-contact-type="<?php echo $contact['type']; ?>"
                                     onclick="selectContact(this)">
                                    <div class="flex items-center space-x-3">
                                        <div class="w-10 h-10 bg-gradient-to-r from-blue-500 to-purple-600 rounded-full flex items-center justify-center text-white font-semibold text-sm">
                                            <?php 
                                            $contactInitials = implode('', array_map(function($word) {
                                                return strtoupper(substr($word, 0, 1));
                                            }, array_slice(explode(' ', $contact['name']), 0, 2)));
                                            echo $contactInitials;
                                            ?>
                                        </div>
                                        <div class="flex-1">
                                            <div class="font-medium text-gray-900"><?php echo htmlspecialchars($contact['name']); ?></div>
                                            <div class="text-sm text-gray-500"><?php echo htmlspecialchars($contact['role']); ?></div>
                                            <div class="text-xs text-green-600 flex items-center mt-1">
                                                <span class="w-2 h-2 bg-green-500 rounded-full mr-2"></span>
                                                Available
                                            </div>
                                        </div>
                                    </div>
                                    <div class="message-unread-badge" style="display: none;">0</div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
                <!-- Chat Area -->
                <div class="flex-1 flex flex-col" id="chatArea">
                    <!-- Default State - No Contact Selected -->
                    <div class="flex-1 flex items-center justify-center" id="defaultState">
                        <div class="text-center text-gray-500">
                            <i class="fas fa-comments text-6xl mb-4 opacity-30"></i>
                            <h3 class="text-xl font-medium mb-2">Select a Contact</h3>
                            <p>Choose someone from your contacts to start messaging</p>
                        </div>
                    </div>

                    <!-- Active Chat -->
                    <div class="hidden flex-1 flex flex-col" id="activeChat">
                        <!-- Chat Header -->
                        <div class="p-4 border-b border-gray-200 bg-gray-50">
                            <div class="flex items-center justify-between">
                                <div class="flex items-center space-x-3">
                                    <button class="lg:hidden p-2 text-gray-500 hover:text-gray-700" onclick="showMobileContacts()">
                                        <i class="fas fa-arrow-left"></i>
                                    </button>
                                    <div class="w-10 h-10 bg-gradient-to-r from-blue-500 to-purple-600 rounded-full flex items-center justify-center text-white font-semibold text-sm" id="chatAvatar">
                                        <!-- Avatar will be populated by JS -->
                                    </div>
                                    <div>
                                        <div class="font-medium text-gray-900" id="chatContactName"><!-- Name --></div>
                                        <div class="text-sm text-gray-500" id="chatContactRole"><!-- Role --></div>
                                    </div>
                                </div>
                                <div class="flex items-center space-x-2">
                                    <div class="text-xs text-green-600 flex items-center">
                                        <span class="w-2 h-2 bg-green-500 rounded-full mr-2"></span>
                                        Online
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Messages Area -->
                        <div class="flex-1 overflow-y-auto p-4 space-y-4 bg-gray-50 custom-scrollbar" id="messagesContainer">
                            <!-- Messages will be loaded here -->
                            <div class="text-center text-gray-500" id="loadingMessages">
                                <i class="fas fa-spinner fa-spin text-2xl mb-2"></i>
                                <p>Loading messages...</p>
                            </div>
                        </div>

                        <!-- Message Input -->
                        <div class="p-4 border-t border-gray-200 bg-white">
                            <form id="messageForm" class="flex space-x-3">
                                <div class="flex-1 relative">
                                    <textarea 
                                        id="messageInput" 
                                        placeholder="Type your message..." 
                                        class="w-full px-4 py-3 border border-gray-300 rounded-lg resize-none focus:ring-2 focus:ring-blue-500 focus:border-transparent"
                                        rows="1"
                                        style="min-height: 44px; max-height: 120px;"
                                    ></textarea>
                                </div>
                                <button 
                                    type="submit" 
                                    class="px-6 py-3 bg-blue-600 text-white font-medium rounded-lg hover:bg-blue-700 transition-colors disabled:opacity-50 disabled:cursor-not-allowed"
                                    id="sendButton"
                                >
                                    <i class="fas fa-paper-plane"></i>
                                </button>
                            </form>
                            <div class="text-xs text-gray-500 mt-2 flex items-center">
                                <i class="fas fa-info-circle mr-1"></i>
                                Press Enter to send, Shift+Enter for new line
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- JavaScript -->
    <script>
        let currentContact = null;
        let messagePollingInterval = null;
        let unreadPollingInterval = null;

        document.addEventListener('DOMContentLoaded', function() {
            // Initialize
            initializeSidebar();
            initializeProfileDropdown();
            initializeMessageSystem();
            startUnreadCountPolling();
            
            // Auto-resize textarea
            const messageInput = document.getElementById('messageInput');
            if (messageInput) {
                messageInput.addEventListener('input', function() {
                    this.style.height = 'auto';
                    this.style.height = Math.min(this.scrollHeight, 120) + 'px';
                });

                // Handle Enter key
                messageInput.addEventListener('keydown', function(e) {
                    if (e.key === 'Enter' && !e.shiftKey) {
                        e.preventDefault();
                        document.getElementById('messageForm').dispatchEvent(new Event('submit'));
                    }
                });
            }
        });

        function initializeSidebar() {
            const mobileMenuBtn = document.getElementById('mobileMenuBtn');
            const closeSidebar = document.getElementById('closeSidebar');
            const sidebarOverlay = document.getElementById('sidebarOverlay');
            const sidebar = document.getElementById('sidebar');

            if (mobileMenuBtn) {
                mobileMenuBtn.addEventListener('click', function() {
                    sidebar.classList.remove('-translate-x-full');
                    sidebarOverlay.classList.remove('hidden');
                });
            }

            if (closeSidebar) {
                closeSidebar.addEventListener('click', function() {
                    sidebar.classList.add('-translate-x-full');
                    sidebarOverlay.classList.add('hidden');
                });
            }

            if (sidebarOverlay) {
                sidebarOverlay.addEventListener('click', function() {
                    sidebar.classList.add('-translate-x-full');
                    sidebarOverlay.classList.add('hidden');
                });
            }
        }

        function initializeProfileDropdown() {
            const profileBtn = document.getElementById('profileBtn');
            const profileDropdown = document.getElementById('profileDropdown');

            if (profileBtn && profileDropdown) {
                profileBtn.addEventListener('click', function(e) {
                    e.stopPropagation();
                    profileDropdown.classList.toggle('hidden');
                });

                document.addEventListener('click', function() {
                    profileDropdown.classList.add('hidden');
                });
            }
        }

        function initializeMessageSystem() {
            const messageForm = document.getElementById('messageForm');
            if (messageForm) {
                messageForm.addEventListener('submit', function(e) {
                    e.preventDefault();
                    sendMessage();
                });
            }

            // Load individual unread counts for contacts
            loadUnreadCounts();
        }

        function selectContact(contactElement) {
            // Remove active state from all contacts
            document.querySelectorAll('.contact-item').forEach(item => {
                item.classList.remove('bg-blue-50', 'border-l-4', 'border-blue-500');
            });

            // Add active state to selected contact
            contactElement.classList.add('bg-blue-50', 'border-l-4', 'border-blue-500');

            // Get contact data
            const contactId = contactElement.dataset.contactId;
            const contactName = contactElement.dataset.contactName;
            const contactRole = contactElement.dataset.contactRole;
            const contactType = contactElement.dataset.contactType;

            // Set current contact
            currentContact = {
                id: contactId,
                name: contactName,
                role: contactRole,
                type: contactType
            };

            // Update UI
            showActiveChat();
            updateChatHeader(contactName, contactRole);
            loadMessages(contactId, contactType);

            // Start polling for new messages
            if (messagePollingInterval) {
                clearInterval(messagePollingInterval);
            }
            messagePollingInterval = setInterval(() => {
                loadMessages(contactId, contactType, false);
            }, 3000);

            // Hide contacts on mobile
            if (window.innerWidth < 1024) {
                document.getElementById('contactsSidebar').classList.add('hidden');
            }
        }

        function showActiveChat() {
            document.getElementById('defaultState').classList.add('hidden');
            document.getElementById('activeChat').classList.remove('hidden');
        }

        function updateChatHeader(name, role) {
            document.getElementById('chatContactName').textContent = name;
            document.getElementById('chatContactRole').textContent = role;
            
            // Update avatar with initials
            const initials = name.split(' ').slice(0, 2).map(word => word.charAt(0).toUpperCase()).join('');
            document.getElementById('chatAvatar').textContent = initials;
        }

        function loadMessages(contactId, contactType, showLoading = true) {
            const messagesContainer = document.getElementById('messagesContainer');
            
            if (showLoading) {
                messagesContainer.innerHTML = `
                    <div class="text-center text-gray-500">
                        <i class="fas fa-spinner fa-spin text-2xl mb-2"></i>
                        <p>Loading messages...</p>
                    </div>
                `;
            }

            const formData = new FormData();
            formData.append('action', 'get_messages');
            formData.append('recipient_id', contactId);
            formData.append('recipient_type', contactType);

            fetch('', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    displayMessages(data.messages, showLoading);
                    // Update unread count for this contact
                    updateContactUnreadBadge(contactId, 0);
                } else {
                    console.error('Failed to load messages:', data.error);
                    if (showLoading) {
                        messagesContainer.innerHTML = `
                            <div class="text-center text-red-500">
                                <i class="fas fa-exclamation-triangle text-2xl mb-2"></i>
                                <p>Failed to load messages</p>
                            </div>
                        `;
                    }
                }
            })
            .catch(error => {
                console.error('Error loading messages:', error);
                if (showLoading) {
                    messagesContainer.innerHTML = `
                        <div class="text-center text-red-500">
                            <i class="fas fa-exclamation-triangle text-2xl mb-2"></i>
                            <p>Error loading messages</p>
                        </div>
                    `;
                }
            });
        }

        function displayMessages(messages, scrollToBottom = true) {
            const messagesContainer = document.getElementById('messagesContainer');
            const currentScrollTop = messagesContainer.scrollTop;
            const currentScrollHeight = messagesContainer.scrollHeight;
            
            if (messages.length === 0) {
                messagesContainer.innerHTML = `
                    <div class="text-center text-gray-500">
                        <i class="fas fa-comments text-4xl mb-4 opacity-30"></i>
                        <p>No messages yet</p>
                        <p class="text-sm">Start the conversation by sending a message below</p>
                    </div>
                `;
                return;
            }

            let messagesHTML = '';
            messages.forEach(message => {
                const messageTime = new Date(message.sent_at).toLocaleTimeString([], {hour: '2-digit', minute:'2-digit'});
                const messageDate = new Date(message.sent_at).toLocaleDateString();
                
                if (message.is_own) {
                    messagesHTML += `
                        <div class="flex justify-end mb-4">
                            <div class="max-w-xs lg:max-w-md">
                                <div class="bg-blue-600 text-white rounded-lg px-4 py-2 break-words">
                                    <p>${escapeHtml(message.message)}</p>
                                </div>
                                <div class="text-xs text-gray-500 mt-1 text-right">
                                    ${messageTime} • ${messageDate}
                                    ${message.is_read ? '<i class="fas fa-check-double text-blue-500 ml-1"></i>' : '<i class="fas fa-check text-gray-400 ml-1"></i>'}
                                </div>
                            </div>
                        </div>
                    `;
                } else {
                    const senderInitials = message.sender_name ? message.sender_name.split(' ').slice(0, 2).map(word => word.charAt(0).toUpperCase()).join('') : '?';
                    messagesHTML += `
                        <div class="flex justify-start mb-4">
                            <div class="flex items-start space-x-2 max-w-xs lg:max-w-md">
                                <div class="w-8 h-8 bg-gradient-to-r from-gray-400 to-gray-600 rounded-full flex items-center justify-center text-white font-semibold text-xs flex-shrink-0">
                                    ${message.sender_avatar ? `<img src="${message.sender_avatar}" alt="Avatar" class="w-full h-full rounded-full object-cover">` : senderInitials}
                                </div>
                                <div>
                                    <div class="bg-white border border-gray-200 rounded-lg px-4 py-2 break-words">
                                        <p>${escapeHtml(message.message)}</p>
                                    </div>
                                    <div class="text-xs text-gray-500 mt-1">
                                        ${message.sender_name} • ${messageTime} • ${messageDate}
                                    </div>
                                </div>
                            </div>
                        </div>
                    `;
                }
            });

            messagesContainer.innerHTML = messagesHTML;

            // Handle scrolling - only auto-scroll if user was at bottom or this is initial load
            if (scrollToBottom || (currentScrollTop + messagesContainer.clientHeight >= currentScrollHeight - 50)) {
                messagesContainer.scrollTop = messagesContainer.scrollHeight;
            }
        }

        function sendMessage() {
            if (!currentContact) {
                return;
            }

            const messageInput = document.getElementById('messageInput');
            const sendButton = document.getElementById('sendButton');
            const message = messageInput.value.trim();

            if (!message) {
                return;
            }

            // Disable input and button
            messageInput.disabled = true;
            sendButton.disabled = true;
            sendButton.innerHTML = '<i class="fas fa-spinner fa-spin"></i>';

            const formData = new FormData();
            formData.append('action', 'send_message');
            formData.append('recipient_id', currentContact.id);
            formData.append('recipient_type', currentContact.type);
            formData.append('message', message);

            fetch('', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    messageInput.value = '';
                    messageInput.style.height = 'auto';
                    // Reload messages to show the new one
                    loadMessages(currentContact.id, currentContact.type, false);
                } else {
                    alert('Failed to send message: ' + (data.error || 'Unknown error'));
                }
            })
            .catch(error => {
                console.error('Error sending message:', error);
                alert('Error sending message. Please try again.');
            })
            .finally(() => {
                // Re-enable input and button
                messageInput.disabled = false;
                sendButton.disabled = false;
                sendButton.innerHTML = '<i class="fas fa-paper-plane"></i>';
                messageInput.focus();
            });
        }

        function loadUnreadCounts() {
            document.querySelectorAll('.contact-item').forEach(contactElement => {
                const contactId = contactElement.dataset.contactId;
                const contactType = contactElement.dataset.contactType;

                const formData = new FormData();
                formData.append('action', 'get_individual_unread_count');
                formData.append('recipient_id', contactId);
                formData.append('recipient_type', contactType);

                fetch('', {
                    method: 'POST',
                    body: formData
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        updateContactUnreadBadge(contactId, data.count);
                    }
                })
                .catch(error => {
                    console.error('Error loading unread count for contact:', error);
                });
            });
        }

        function updateContactUnreadBadge(contactId, count) {
            const contactElement = document.querySelector(`[data-contact-id="${contactId}"]`);
            if (contactElement) {
                const badge = contactElement.querySelector('.message-unread-badge');
                if (badge) {
                    if (count > 0) {
                        badge.textContent = count;
                        badge.style.display = 'flex';
                    } else {
                        badge.style.display = 'none';
                    }
                }
            }
        }

        function startUnreadCountPolling() {
            // Update total unread count
            function updateTotalUnreadCount() {
                const formData = new FormData();
                formData.append('action', 'get_unread_count');

                fetch('', {
                    method: 'POST',
                    body: formData
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        const badge = document.getElementById('totalUnreadCount');
                        if (badge) {
                            if (data.count > 0) {
                                badge.textContent = data.count;
                                badge.style.display = 'flex';
                            } else {
                                badge.style.display = 'none';
                            }
                        }
                    }
                })
                .catch(error => {
                    console.error('Error updating unread count:', error);
                });
            }

            // Update immediately
            updateTotalUnreadCount();
            
            // Then poll every 5 seconds
            unreadPollingInterval = setInterval(() => {
                updateTotalUnreadCount();
                loadUnreadCounts();
            }, 5000);
        }

        function showMobileContacts() {
            document.getElementById('contactsSidebar').classList.remove('hidden');
        }

        function hideMobileContacts() {
            document.getElementById('contactsSidebar').classList.add('hidden');
        }

        function escapeHtml(text) {
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }

        // Handle window resize
        window.addEventListener('resize', function() {
            if (window.innerWidth >= 1024) {
                document.getElementById('contactsSidebar').classList.remove('hidden');
            }
        });

        // Cleanup intervals when page unloads
        window.addEventListener('beforeunload', function() {
            if (messagePollingInterval) {
                clearInterval(messagePollingInterval);
            }
            if (unreadPollingInterval) {
                clearInterval(unreadPollingInterval);
            }
        });

        // Mobile responsiveness for contacts sidebar
        if (window.innerWidth < 1024) {
            const contactsSidebar = document.getElementById('contactsSidebar');
            contactsSidebar.classList.add('absolute', 'inset-0', 'z-10', 'lg:relative', 'lg:inset-auto');
        }
    </script>

    <!-- Mobile responsive styles -->
    <style>
        @media (max-width: 1024px) {
            #contactsSidebar {
                position: absolute;
                inset: 0;
                z-index: 10;
                width: 100% !important;
            }
            
            #contactsSidebar.hidden {
                display: none;
            }
            
            .message-input-mobile {
                padding: 12px;
            }
        }
        
        @media (max-width: 640px) {
            .w-80 {
                width: 100% !important;
            }
            
            #messagesContainer {
                padding: 12px;
            }
            
            .max-w-xs {
                max-width: 280px;
            }
        }
    </style>
</body>
</html>