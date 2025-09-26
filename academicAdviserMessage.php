<?php
include('connect.php');
session_start();

// Cache control headers
header("Cache-Control: no-cache, no-store, must-revalidate");
header("Pragma: no-cache");
header("Expires: 0");

// Check if adviser is logged in
if (!isset($_SESSION['adviser_id'])) {
    header("Location: adviserlogin.php");
    exit();
}

$adviser_id = $_SESSION['adviser_id'];

// Fetch adviser data
try {
    $stmt = $conn->prepare("SELECT id, name, email FROM academic_adviser WHERE id = ?");
    $stmt->bind_param("i", $adviser_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows > 0) {
        $adviser = $result->fetch_assoc();
        $adviser_name = $adviser['name'];
        $adviser_initials = strtoupper(substr($adviser['name'], 0, 1) . substr(substr($adviser['name'], strpos($adviser['name'], ' ') + 1), 0, 1));
    } else {
        header("Location: adviserlogin.php");
        exit();
    }
} catch (Exception $e) {
    echo "Error: " . $e->getMessage();
    exit();
}
 $unread_messages_query = "SELECT COUNT(*) as count FROM messages WHERE recipient_type = 'adviser' AND sender_type = 'student' AND is_read = 0 AND is_deleted_by_recipient = 0";
    $unread_messages_result = mysqli_query($conn, $unread_messages_query);
    $unread_messages_count = mysqli_fetch_assoc($unread_messages_result)['count'];
// Get students who have messaged this adviser
$student_contacts = [];
try {
    $contacts_stmt = $conn->prepare("
        SELECT DISTINCT 
            s.id, s.first_name, s.middle_name, s.last_name, s.email, 
            s.student_id, s.department, s.program, s.year_level, s.profile_picture,
            MAX(m.sent_at) as last_message_time,
            COUNT(CASE WHEN m.is_read = 0 AND m.recipient_id = ? AND m.recipient_type = 'adviser' THEN 1 END) as unread_count
        FROM students s
        INNER JOIN messages m ON (
            (m.sender_id = s.id AND m.sender_type = 'student' AND m.recipient_id = ? AND m.recipient_type = 'adviser') OR
            (m.sender_id = ? AND m.sender_type = 'adviser' AND m.recipient_id = s.id AND m.recipient_type = 'student')
        )
        GROUP BY s.id, s.first_name, s.middle_name, s.last_name, s.email, s.student_id, s.department, s.program, s.year_level, s.profile_picture
        ORDER BY last_message_time DESC
    ");
    $contacts_stmt->bind_param("iii", $adviser_id, $adviser_id, $adviser_id);
    $contacts_stmt->execute();
    $contacts_result = $contacts_stmt->get_result();
    
    while ($row = $contacts_result->fetch_assoc()) {
        $full_name = trim($row['first_name'] . ' ' . $row['middle_name'] . ' ' . $row['last_name']);
        $student_contacts[] = [
            'id' => 'student_' . $row['id'],
            'student_id' => $row['id'],
            'name' => $full_name,
            'role' => $row['program'] . ' - ' . $row['year_level'],
            'email' => $row['email'],
            'student_number' => $row['student_id'],
            'department' => $row['department'],
            'profile_picture' => $row['profile_picture'],
            'type' => 'student',
            'last_message_time' => $row['last_message_time'],
            'unread_count' => $row['unread_count'],
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
            $recipient_id = $_POST['recipient_id'];
            $message = trim($_POST['message']);
            $recipient_type = $_POST['recipient_type'];
            
            if (empty($message)) {
                echo json_encode(['success' => false, 'error' => 'Message cannot be empty']);
                exit();
            }
            
            // Validate recipient type (adviser can only send to students)
            if ($recipient_type !== 'student') {
                echo json_encode(['success' => false, 'error' => 'Invalid recipient type']);
                exit();
            }
            
            try {
                $insert_stmt = $conn->prepare("
                    INSERT INTO messages (sender_id, sender_type, recipient_id, recipient_type, message, sent_at) 
                    VALUES (?, 'adviser', ?, ?, ?, NOW())
                ");
                $insert_stmt->bind_param("isss", $adviser_id, $recipient_id, $recipient_type, $message);
                
                if ($insert_stmt->execute()) {
                    echo json_encode(['success' => true, 'message' => 'Message sent successfully']);
                } else {
                    echo json_encode(['success' => false, 'error' => 'Failed to send message']);
                }
            } catch (Exception $e) {
                echo json_encode(['success' => false, 'error' => 'Database error']);
            }
            exit();
            
        case 'get_messages':
            $recipient_id = $_POST['recipient_id'];
            $recipient_type = $_POST['recipient_type'];
            
            try {
                // Fixed query to properly show both adviser and student messages
                $messages_stmt = $conn->prepare("
                    SELECT m.*,
                           CASE 
                               WHEN m.sender_type = 'student' THEN CONCAT(s.first_name, ' ', s.last_name)
                               WHEN m.sender_type = 'adviser' THEN aa.name
                           END as sender_name,
                           CASE 
                               WHEN m.sender_type = 'student' THEN s.profile_picture
                               WHEN m.sender_type = 'adviser' THEN NULL
                           END as sender_avatar,
                           CASE 
                               WHEN m.sender_type = 'adviser' AND m.sender_id = ? THEN 1
                               ELSE 0
                           END as is_own_message
                    FROM messages m
                    LEFT JOIN students s ON m.sender_id = s.id AND m.sender_type = 'student'
                    LEFT JOIN academic_adviser aa ON m.sender_id = aa.id AND m.sender_type = 'adviser'
                    WHERE (
                        (m.sender_id = ? AND m.sender_type = 'adviser' AND m.recipient_id = ? AND m.recipient_type = 'student')
                        OR 
                        (m.sender_id = ? AND m.sender_type = 'student' AND m.recipient_id = ? AND m.recipient_type = 'adviser')
                    )
                    ORDER BY m.sent_at ASC
                ");
                
                $recipient_id_clean = str_replace('student_', '', $recipient_id);
                $messages_stmt->bind_param("iiiii", 
                    $adviser_id,                    // For checking is_own_message
                    $adviser_id,                    // adviser sending to student
                    $recipient_id_clean,            // to specific student
                    $recipient_id_clean,            // student sending to adviser
                    $adviser_id                     // to this adviser
                );
                
                $messages_stmt->execute();
                $messages_result = $messages_stmt->get_result();
                
                $messages = [];
                while ($row = $messages_result->fetch_assoc()) {
                    $messages[] = [
                        'id' => $row['id'],
                        'message' => $row['message'],
                        'sent_at' => $row['sent_at'],
                        'sender_name' => $row['sender_name'],
                        'sender_avatar' => $row['sender_avatar'],
                        'is_own' => ($row['is_own_message'] == 1), // Messages sent by this adviser
                        'is_read' => $row['is_read'],
                        'sender_type' => $row['sender_type']
                    ];
                }
                
                // Mark messages from student as read (messages TO this adviser)
                $mark_read_stmt = $conn->prepare("
                    UPDATE messages SET is_read = 1 
                    WHERE recipient_id = ? AND recipient_type = 'adviser' AND sender_id = ? AND sender_type = 'student'
                ");
                $mark_read_stmt->bind_param("ii", $adviser_id, $recipient_id_clean);
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
                    WHERE recipient_id = ? AND recipient_type = 'adviser' AND is_read = 0
                ");
                $unread_stmt->bind_param("i", $adviser_id);
                $unread_stmt->execute();
                $unread_result = $unread_stmt->get_result();
                $unread_data = $unread_result->fetch_assoc();
                
                echo json_encode(['success' => true, 'count' => $unread_data['unread_count']]);
            } catch (Exception $e) {
                echo json_encode(['success' => false, 'error' => 'Failed to get unread count']);
            }
            exit();
            
        case 'get_student_contacts':
            // Return updated contact list with unread counts
            try {
                $contacts_stmt = $conn->prepare("
                    SELECT DISTINCT 
                        s.id, s.first_name, s.middle_name, s.last_name, s.email, 
                        s.student_id, s.department, s.program, s.year_level, s.profile_picture,
                        MAX(m.sent_at) as last_message_time,
                        COUNT(CASE WHEN m.is_read = 0 AND m.recipient_id = ? AND m.recipient_type = 'adviser' THEN 1 END) as unread_count
                    FROM students s
                    INNER JOIN messages m ON (
                        (m.sender_id = s.id AND m.sender_type = 'student' AND m.recipient_id = ? AND m.recipient_type = 'adviser') OR
                        (m.sender_id = ? AND m.sender_type = 'adviser' AND m.recipient_id = s.id AND m.recipient_type = 'student')
                    )
                    GROUP BY s.id
                    ORDER BY last_message_time DESC
                ");
                $contacts_stmt->bind_param("iii", $adviser_id, $adviser_id, $adviser_id);
                $contacts_stmt->execute();
                $contacts_result = $contacts_stmt->get_result();
                
                $contacts = [];
                while ($row = $contacts_result->fetch_assoc()) {
                    $full_name = trim($row['first_name'] . ' ' . $row['middle_name'] . ' ' . $row['last_name']);
                    $contacts[] = [
                        'id' => 'student_' . $row['id'],
                        'student_id' => $row['id'],
                        'name' => $full_name,
                        'role' => $row['program'] . ' - ' . $row['year_level'],
                        'unread_count' => $row['unread_count'],
                        'last_message_time' => $row['last_message_time']
                    ];
                }
                
                echo json_encode(['success' => true, 'contacts' => $contacts]);
            } catch (Exception $e) {
                echo json_encode(['success' => false, 'error' => 'Failed to get contacts']);
            }
            exit();
    }
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
    <title>OnTheJob Tracker - Academic Adviser Messages</title>
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

        .message-bubble {
            word-wrap: break-word;
            overflow-wrap: break-word;
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
            <a href="academicAdviserMessage.php" class="nav-item flex items-center px-3 py-2 text-sm font-medium text-white bg-bulsu-gold bg-opacity-20 border border-bulsu-gold border-opacity-30 rounded-md">
                <i class="fas fa-envelope mr-3 text-bulsu-gold"></i>
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
                    <h1 class="text-xl sm:text-2xl font-bold text-gray-900">Messages</h1>
                    <p class="text-sm sm:text-base text-gray-500 hidden sm:block">Communicate with students and manage conversations</p>
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

            <!-- Messages Container -->
            <div class="bg-white rounded-lg shadow-sm border border-gray-200 h-[calc(100vh-12rem)]">
                <div class="grid grid-cols-1 lg:grid-cols-5 h-full">
                    <!-- Contacts Sidebar -->
                    <div id="contactsSidebar" class="lg:col-span-2 border-r border-gray-200 flex flex-col bg-gray-50">
                        <!-- Contacts Header -->
                        <div class="p-4 sm:p-6 border-b border-gray-200 bg-white">
                            <button class="lg:hidden mb-4 p-2 text-gray-500 hover:text-gray-700" onclick="hideMobileContacts()" id="mobileBackBtn">
                                <i class="fas fa-arrow-left text-lg"></i>
                            </button>
                            <div class="flex items-center justify-between">
                                <div class="flex items-center">
                                    <i class="fas fa-users text-blue-600 mr-3"></i>
                                    <div>
                                        <h3 class="text-lg font-medium text-gray-900">Student Conversations</h3>
                                        <p class="text-sm text-gray-500" id="contactsCount"><?php echo count($student_contacts); ?> conversations</p>
                                    </div>
                                </div>
                                <button class="p-2 text-gray-500 hover:text-gray-700 hover:bg-gray-100 rounded-md transition-colors" onclick="refreshContacts()" title="Refresh contacts">
                                    <i class="fas fa-sync-alt"></i>
                                </button>
                            </div>
                        </div>
                        
                        <!-- Contacts List -->
                        <div class="flex-1 overflow-y-auto custom-scrollbar" id="contactsList">
                            <?php if (empty($student_contacts)): ?>
                                <div class="flex flex-col items-center justify-center h-64 text-center p-6">
                                    <i class="fas fa-comments text-gray-300 text-5xl mb-4"></i>
                                    <h4 class="text-lg font-medium text-gray-900 mb-2">No Messages Yet</h4>
                                    <p class="text-gray-500">Students will appear here when they send you a message</p>
                                </div>
                            <?php else: ?>
                                <?php foreach ($student_contacts as $contact): ?>
                                    <div class="contact-item p-4 border-b border-gray-200 cursor-pointer hover:bg-gray-100 transition-colors relative" 
                                         data-contact-id="<?php echo $contact['id']; ?>"
                                         data-contact-name="<?php echo htmlspecialchars($contact['name']); ?>"
                                         data-contact-role="<?php echo htmlspecialchars($contact['role']); ?>"
                                         data-contact-type="<?php echo $contact['type']; ?>"
                                         data-student-id="<?php echo $contact['student_id']; ?>"
                                         onclick="selectContact(this)">
                                        <div class="flex items-center space-x-3">
                                            <div class="flex-shrink-0 w-12 h-12 bg-gradient-to-r from-green-500 to-teal-600 rounded-full flex items-center justify-center text-white font-semibold text-sm">
                                                <?php 
                                                $name_parts = explode(' ', $contact['name']);
                                                echo strtoupper(substr($name_parts[0], 0, 1) . (isset($name_parts[1]) ? substr($name_parts[1], 0, 1) : ''));
                                                ?>
                                            </div>
                                            <div class="flex-1 min-w-0">
                                                <p class="text-sm font-medium text-gray-900 truncate"><?php echo htmlspecialchars($contact['name']); ?></p>
                                                <p class="text-sm text-gray-600 truncate"><?php echo htmlspecialchars($contact['role']); ?></p>
                                                <p class="text-xs text-gray-500">ID: <?php echo htmlspecialchars($contact['student_number']); ?></p>
                                                <p class="text-xs text-gray-500">
                                                    <?php 
                                                    if ($contact['last_message_time']) {
                                                        $time_diff = time() - strtotime($contact['last_message_time']);
                                                        if ($time_diff < 60) {
                                                            echo "Just now";
                                                        } elseif ($time_diff < 3600) {
                                                            echo floor($time_diff / 60) . " minutes ago";
                                                        } elseif ($time_diff < 86400) {
                                                            echo floor($time_diff / 3600) . " hours ago";
                                                        } else {
                                                            echo date('M j', strtotime($contact['last_message_time']));
                                                        }
                                                    }
                                                    ?>
                                                </p>
                                            </div>
                                        </div>
                                        <?php if ($contact['unread_count'] > 0): ?>
                                            <div class="absolute top-2 right-2 w-5 h-5 bg-red-500 text-white text-xs font-bold rounded-full flex items-center justify-center">
                                                <?php echo $contact['unread_count']; ?>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Chat Area -->
                    <div class="lg:col-span-3 flex flex-col">
                        <!-- Chat Header -->
                        <div class="chat-header p-4 sm:p-6 border-b border-gray-200 bg-white hidden" id="chatHeader">
                            <button class="lg:hidden mr-4 p-2 text-gray-500 hover:text-gray-700" onclick="showMobileContacts()" id="mobileContactsBtn">
                                <i class="fas fa-arrow-left text-lg"></i>
                            </button>
                            <div class="flex items-center space-x-4">
                                <div class="chat-header-avatar w-12 h-12 bg-gradient-to-r from-blue-500 to-purple-600 rounded-full flex items-center justify-center text-white font-semibold" id="chatAvatar"></div>
                                <div>
                                    <h3 class="text-lg font-medium text-gray-900" id="chatName"></h3>
                                    <p class="text-sm text-gray-500" id="chatRole"></p>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Messages Area -->
                        <div class="flex-1 overflow-y-auto custom-scrollbar p-4 sm:p-6 bg-gray-50" id="messagesArea">
                            <div class="flex flex-col items-center justify-center h-full text-center">
                                <i class="fas fa-comments text-gray-300 text-6xl mb-4"></i>
                                <h3 class="text-xl font-medium text-gray-900 mb-2">Select a Student</h3>
                                <p class="text-gray-500">Choose a student from the sidebar to view your conversation</p>
                            </div>
                        </div>
                        
                        <!-- Message Input Area -->
                        <div class="message-input-area p-4 sm:p-6 border-t border-gray-200 bg-white hidden" id="messageInputArea">
                            <div class="flex items-end space-x-3">
                                <textarea class="flex-1 resize-none border border-gray-300 rounded-lg px-4 py-3 focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition-colors" 
                                          id="messageInput" 
                                          placeholder="Type your reply..." 
                                          rows="1" 
                                          onkeypress="handleKeyPress(event)"
                                          oninput="adjustTextareaHeight(this)"></textarea>
                                <button class="w-12 h-12 bg-blue-600 hover:bg-blue-700 disabled:bg-gray-400 disabled:cursor-not-allowed text-white rounded-lg flex items-center justify-center transition-colors" 
                                        id="sendButton" 
                                        onclick="sendMessage()" 
                                        disabled>
                                    <i class="fas fa-paper-plane"></i>
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
        // Global variables
        let currentContact = null;
        let messagesInterval = null;
        let unreadInterval = null;
        let contactsInterval = null;

        // Initialize the messaging system
        document.addEventListener('DOMContentLoaded', function() {
            initializeMessaging();
            startPeriodicUpdates();
            updateUnreadCounts();
        });

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

        function initializeMessaging() {
            const messageInput = document.getElementById('messageInput');
            const sendButton = document.getElementById('sendButton');
            
            // Enable/disable send button based on input
            messageInput.addEventListener('input', function() {
                const hasText = this.value.trim().length > 0;
                sendButton.disabled = !hasText;
            });

            // Auto-resize textarea
            messageInput.addEventListener('input', function() {
                adjustTextareaHeight(this);
            });
        }

        function selectContact(contactElement) {
            if (contactElement.classList.contains('disabled')) {
                showToast('Cannot select this contact', 'warning');
                return;
            }

            // Remove active class from all contacts
            document.querySelectorAll('.contact-item').forEach(item => {
                item.classList.remove('bg-blue-50', 'border-l-4', 'border-l-blue-500');
            });

            // Add active class to selected contact
            contactElement.classList.add('bg-blue-50', 'border-l-4', 'border-l-blue-500');

            // Get contact data
            currentContact = {
                id: contactElement.dataset.contactId,
                name: contactElement.dataset.contactName,
                role: contactElement.dataset.contactRole,
                type: contactElement.dataset.contactType,
                student_id: contactElement.dataset.studentId
            };

            // Update chat header
            updateChatHeader(currentContact);

            // Load messages
            loadMessages();

            // Show chat elements
            document.getElementById('chatHeader').classList.remove('hidden');
            document.getElementById('messageInputArea').classList.remove('hidden');

            // Hide unread badge for this contact
            const unreadBadge = contactElement.querySelector('.absolute');
            if (unreadBadge && unreadBadge.classList.contains('bg-red-500')) {
                unreadBadge.classList.add('hidden');
            }

            // Hide mobile contacts on mobile
            hideMobileContacts();
        }

        function updateChatHeader(contact) {
            const chatAvatar = document.getElementById('chatAvatar');
            const chatName = document.getElementById('chatName');
            const chatRole = document.getElementById('chatRole');

            // Create initials from name
            const initials = contact.name.split(' ')
                .map(word => word.charAt(0))
                .join('')
                .toUpperCase()
                .substring(0, 2);

            chatAvatar.textContent = initials;
            chatName.textContent = contact.name;
            chatRole.textContent = contact.role;
        }

        function loadMessages() {
            if (!currentContact) return;

            const messagesArea = document.getElementById('messagesArea');
            messagesArea.innerHTML = `
                <div class="flex items-center justify-center h-full">
                    <div class="flex items-center space-x-3 text-gray-500">
                        <i class="fas fa-spinner animate-spin"></i>
                        <span>Loading messages...</span>
                    </div>
                </div>
            `;

            const formData = new FormData();
            formData.append('action', 'get_messages');
            formData.append('recipient_id', currentContact.id);
            formData.append('recipient_type', currentContact.type);

            fetch('', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    displayMessages(data.messages);
                    scrollToBottom();
                } else {
                    messagesArea.innerHTML = `
                        <div class="flex items-center justify-center h-full">
                            <div class="text-center text-red-500">
                                <i class="fas fa-exclamation-triangle text-4xl mb-3"></i>
                                <p>Failed to load messages</p>
                            </div>
                        </div>
                    `;
                }
            })
            .catch(error => {
                console.error('Error loading messages:', error);
                messagesArea.innerHTML = `
                    <div class="flex items-center justify-center h-full">
                        <div class="text-center text-red-500">
                            <i class="fas fa-exclamation-triangle text-4xl mb-3"></i>
                            <p>Error loading messages</p>
                        </div>
                    </div>
                `;
            });
        }

        function displayMessages(messages) {
            const messagesArea = document.getElementById('messagesArea');
            
            if (messages.length === 0) {
                messagesArea.innerHTML = `
                    <div class="flex flex-col items-center justify-center h-full text-center">
                        <i class="fas fa-comment-slash text-gray-300 text-6xl mb-4"></i>
                        <h3 class="text-xl font-medium text-gray-900 mb-2">No messages yet</h3>
                        <p class="text-gray-500">This is the beginning of your conversation with this student</p>
                    </div>
                `;
                return;
            }

            let messagesHTML = '';
            let currentDate = '';

            messages.forEach(message => {
                const messageDate = new Date(message.sent_at).toDateString();
                
                // Add date separator if date changed
                if (messageDate !== currentDate) {
                    messagesHTML += createDateSeparator(messageDate);
                    currentDate = messageDate;
                }

                messagesHTML += createMessageHTML(message);
            });

            messagesArea.innerHTML = messagesHTML;
        }

        function createDateSeparator(dateString) {
            const date = new Date(dateString);
            const today = new Date().toDateString();
            const yesterday = new Date(Date.now() - 86400000).toDateString();
            
            let displayDate;
            if (dateString === today) {
                displayDate = 'Today';
            } else if (dateString === yesterday) {
                displayDate = 'Yesterday';
            } else {
                displayDate = date.toLocaleDateString('en-US', { 
                    weekday: 'long', 
                    year: 'numeric', 
                    month: 'long', 
                    day: 'numeric' 
                });
            }

            return `
                <div class="flex items-center justify-center my-6">
                    <div class="flex-1 border-t border-gray-300"></div>
                    <div class="px-4 py-2 bg-white border border-gray-300 rounded-full text-sm text-gray-500">
                        ${displayDate}
                    </div>
                    <div class="flex-1 border-t border-gray-300"></div>
                </div>
            `;
        }

        function createMessageHTML(message) {
            const time = new Date(message.sent_at).toLocaleTimeString('en-US', { 
                hour: 'numeric', 
                minute: '2-digit',
                hour12: true 
            });

            const avatarInitials = message.sender_name.split(' ').map(word => word.charAt(0)).join('').toUpperCase().substring(0, 2);
            
            const avatarContent = message.sender_avatar && message.sender_avatar !== 'null' 
                ? `<img src="${message.sender_avatar}" alt="Avatar" class="w-full h-full object-cover">` 
                : avatarInitials;

            if (message.is_own) {
                // Message sent by adviser (right side)
                return `
                    <div class="flex items-end justify-end space-x-2 mb-4">
                        <div class="flex flex-col items-end max-w-xs lg:max-w-md">
                            <div class="bg-blue-600 text-white px-4 py-2 rounded-lg message-bubble">
                                ${escapeHtml(message.message)}
                            </div>
                            <div class="text-xs text-gray-500 mt-1">${time}</div>
                        </div>
                        <div class="w-8 h-8 bg-gradient-to-r from-blue-500 to-purple-600 rounded-full flex items-center justify-center text-white font-semibold text-sm flex-shrink-0">
                            ${avatarInitials}
                        </div>
                    </div>
                `;
            } else {
                // Message received from student (left side)
                return `
                    <div class="flex items-end space-x-2 mb-4">
                        <div class="w-8 h-8 bg-gradient-to-r from-green-500 to-teal-600 rounded-full flex items-center justify-center text-white font-semibold text-sm flex-shrink-0 overflow-hidden">
                            ${avatarContent}
                        </div>
                        <div class="flex flex-col max-w-xs lg:max-w-md">
                            <div class="bg-white border border-gray-200 px-4 py-2 rounded-lg message-bubble shadow-sm">
                                ${escapeHtml(message.message)}
                            </div>
                            <div class="text-xs text-gray-500 mt-1">${time}</div>
                        </div>
                    </div>
                `;
            }
        }

        function sendMessage() {
            const messageInput = document.getElementById('messageInput');
            const sendButton = document.getElementById('sendButton');
            const message = messageInput.value.trim();

            if (!message || !currentContact) return;

            // Disable input while sending
            messageInput.disabled = true;
            sendButton.disabled = true;
            sendButton.innerHTML = '<i class="fas fa-spinner animate-spin"></i>';

            const formData = new FormData();
            formData.append('action', 'send_message');
            formData.append('recipient_id', currentContact.id.replace('student_', ''));
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
                    adjustTextareaHeight(messageInput);
                    loadMessages(); // Reload messages to show the new message
                    showToast('Message sent successfully', 'success');
                    
                    // Update contact list to reflect latest message
                    setTimeout(refreshContacts, 1000);
                } else {
                    showToast(data.error || 'Failed to send message', 'error');
                }
            })
            .catch(error => {
                console.error('Error sending message:', error);
                showToast('Error sending message', 'error');
            })
            .finally(() => {
                // Re-enable input
                messageInput.disabled = false;
                sendButton.disabled = message.trim().length === 0;
                sendButton.innerHTML = '<i class="fas fa-paper-plane"></i>';
                messageInput.focus();
            });
        }

        function handleKeyPress(event) {
            if (event.key === 'Enter' && !event.shiftKey) {
                event.preventDefault();
                if (!event.target.disabled && event.target.value.trim()) {
                    sendMessage();
                }
            }
        }

        function adjustTextareaHeight(textarea) {
            textarea.style.height = 'auto';
            textarea.style.height = Math.min(textarea.scrollHeight, 100) + 'px';
        }

        function scrollToBottom() {
            const messagesArea = document.getElementById('messagesArea');
            messagesArea.scrollTop = messagesArea.scrollHeight;
        }

        function startPeriodicUpdates() {
            // Update messages every 3 seconds when a contact is selected
            messagesInterval = setInterval(() => {
                if (currentContact) {
                    loadMessages();
                }
            }, 3000);

            // Update unread counts every 30 seconds
            unreadInterval = setInterval(() => {
                updateUnreadCounts();
            }, 30000);

            // Update contacts list every 60 seconds
            contactsInterval = setInterval(() => {
                refreshContacts();
            }, 60000);
        }

        function updateUnreadCounts() {
            const formData = new FormData();
            formData.append('action', 'get_unread_count');

            fetch('', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    const totalCount = data.count;
                    // You can add total unread count display here if needed
                }
            })
            .catch(error => {
                console.error('Error updating unread counts:', error);
            });
        }

        function refreshContacts() {
            const formData = new FormData();
            formData.append('action', 'get_student_contacts');

            fetch('', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    updateContactsList(data.contacts);
                }
            })
            .catch(error => {
                console.error('Error refreshing contacts:', error);
            });
        }

        function updateContactsList(contacts) {
            const contactsList = document.getElementById('contactsList');
            const contactsCount = document.getElementById('contactsCount');
            
            if (contacts.length === 0) {
                contactsList.innerHTML = `
                    <div class="flex flex-col items-center justify-center h-64 text-center p-6">
                        <i class="fas fa-comments text-gray-300 text-5xl mb-4"></i>
                        <h4 class="text-lg font-medium text-gray-900 mb-2">No Messages Yet</h4>
                        <p class="text-gray-500">Students will appear here when they send you a message</p>
                    </div>
                `;
                contactsCount.textContent = '0 conversations';
                return;
            }

            let contactsHTML = '';
            contacts.forEach(contact => {
                const timeText = getTimeText(contact.last_message_time);
                const unreadBadge = contact.unread_count > 0 ? 
                    `<div class="absolute top-2 right-2 w-5 h-5 bg-red-500 text-white text-xs font-bold rounded-full flex items-center justify-center">${contact.unread_count}</div>` : '';
                
                const nameInitials = contact.name.split(' ')
                    .map(word => word.charAt(0))
                    .join('')
                    .toUpperCase()
                    .substring(0, 2);

                contactsHTML += `
                    <div class="contact-item p-4 border-b border-gray-200 cursor-pointer hover:bg-gray-100 transition-colors relative" 
                         data-contact-id="${contact.id}"
                         data-contact-name="${escapeHtml(contact.name)}"
                         data-contact-role="${escapeHtml(contact.role)}"
                         data-contact-type="student"
                         data-student-id="${contact.student_id}"
                         onclick="selectContact(this)">
                        <div class="flex items-center space-x-3">
                            <div class="flex-shrink-0 w-12 h-12 bg-gradient-to-r from-green-500 to-teal-600 rounded-full flex items-center justify-center text-white font-semibold text-sm">
                                ${nameInitials}
                            </div>
                            <div class="flex-1 min-w-0">
                                <p class="text-sm font-medium text-gray-900 truncate">${escapeHtml(contact.name)}</p>
                                <p class="text-sm text-gray-600 truncate">${escapeHtml(contact.role)}</p>
                                <p class="text-xs text-gray-500">${timeText}</p>
                            </div>
                        </div>
                        ${unreadBadge}
                    </div>
                `;
            });

            contactsList.innerHTML = contactsHTML;
            contactsCount.textContent = `${contacts.length} conversations`;

            // Reselect current contact if it exists
            if (currentContact) {
                const currentContactElement = contactsList.querySelector(`[data-contact-id="${currentContact.id}"]`);
                if (currentContactElement) {
                    currentContactElement.classList.add('bg-blue-50', 'border-l-4', 'border-l-blue-500');
                }
            }
        }

        function getTimeText(timestamp) {
            if (!timestamp) return '';
            
            const time_diff = Math.floor(Date.now() / 1000) - Math.floor(new Date(timestamp).getTime() / 1000);
            
            if (time_diff < 60) {
                return "Just now";
            } else if (time_diff < 3600) {
                return Math.floor(time_diff / 60) + " minutes ago";
            } else if (time_diff < 86400) {
                return Math.floor(time_diff / 3600) + " hours ago";
            } else {
                const date = new Date(timestamp);
                return date.toLocaleDateString('en-US', { month: 'short', day: 'numeric' });
            }
        }

        // Mobile responsive functions
        function showMobileContacts() {
            const contactsSidebar = document.getElementById('contactsSidebar');
            contactsSidebar.classList.remove('lg:col-span-2');
            contactsSidebar.classList.add('col-span-1', 'absolute', 'inset-0', 'z-10', 'bg-white');
        }

        function hideMobileContacts() {
            const contactsSidebar = document.getElementById('contactsSidebar');
            contactsSidebar.classList.add('lg:col-span-2');
            contactsSidebar.classList.remove('col-span-1', 'absolute', 'inset-0', 'z-10', 'bg-white');
        }

        // Utility functions
        function escapeHtml(text) {
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }

        function showToast(message, type = 'info') {
            const toast = document.createElement('div');
            toast.className = `fixed top-4 right-4 px-6 py-4 rounded-lg text-white font-medium z-50 transform transition-all duration-300 ease-in-out translate-x-full opacity-0`;
            
            switch (type) {
                case 'success':
                    toast.classList.add('bg-green-500');
                    break;
                case 'error':
                    toast.classList.add('bg-red-500');
                    break;
                case 'warning':
                    toast.classList.add('bg-yellow-500');
                    break;
                default:
                    toast.classList.add('bg-blue-500');
            }
            
            toast.innerHTML = `
                <div class="flex items-center space-x-2">
                    <i class="fas fa-${type === 'success' ? 'check-circle' : type === 'error' ? 'exclamation-circle' : type === 'warning' ? 'exclamation-triangle' : 'info-circle'}"></i>
                    <span>${message}</span>
                </div>
            `;
            
            document.body.appendChild(toast);
            
            setTimeout(() => {
                toast.classList.remove('translate-x-full', 'opacity-0');
            }, 100);
            
            setTimeout(() => {
                toast.classList.add('translate-x-full', 'opacity-0');
                setTimeout(() => {
                    if (toast.parentNode) {
                        toast.parentNode.removeChild(toast);
                    }
                }, 300);
            }, 4000);
        }

        // Logout confirmation
        function confirmLogout() {
            return confirm('Are you sure you want to logout?');
        }

        // Cleanup on page unload
        window.addEventListener('beforeunload', function() {
            if (messagesInterval) clearInterval(messagesInterval);
            if (unreadInterval) clearInterval(unreadInterval);
            if (contactsInterval) clearInterval(contactsInterval);
        });

        // Handle online/offline status
        window.addEventListener('online', function() {
            showToast('Connection restored', 'success');
            if (currentContact) {
                loadMessages();
            }
            updateUnreadCounts();
            refreshContacts();
        });

        window.addEventListener('offline', function() {
            showToast('Connection lost. Messages will be sent when connection is restored.', 'warning');
        });

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

        // Initialize page
        console.log('Academic Adviser Message System initialized with Tailwind CSS');
    </script>
</body>
</html>