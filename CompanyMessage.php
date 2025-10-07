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
    $supervisor_name = $_SESSION['full_name'];
    $supervisor_email = $_SESSION['email'];
    $company_name = $_SESSION['company_name'];
    $profile_picture = '';
    $initials = strtoupper(substr($supervisor_name, 0, 2));
}

// Get unread messages count
$unread_messages_query = "SELECT COUNT(*) as count FROM messages 
    WHERE recipient_type = 'supervisor' 
    AND recipient_id = $supervisor_id
    AND (sender_type = 'student' OR sender_type = 'adviser') 
    AND is_read = 0 
    AND is_deleted_by_recipient = 0";
$unread_messages_result = mysqli_query($conn, $unread_messages_query);
$unread_messages_count = mysqli_fetch_assoc($unread_messages_result)['count'];

// Get students under this supervisor's supervision
$student_contacts = [];
try {
    $students_stmt = $conn->prepare("
        SELECT DISTINCT 
            s.id, s.first_name, s.middle_name, s.last_name, s.email, 
            s.student_id, s.department, s.program, s.year_level, s.profile_picture,
            (SELECT MAX(m.sent_at) 
             FROM messages m 
             WHERE (m.sender_id = s.id AND m.sender_type = 'student' AND m.recipient_id = ? AND m.recipient_type = 'supervisor')
                OR (m.sender_id = ? AND m.sender_type = 'supervisor' AND m.recipient_id = s.id AND m.recipient_type = 'student')
            ) as last_message_time,
            (SELECT COUNT(*) 
             FROM messages m 
             WHERE m.is_read = 0 AND m.recipient_id = ? AND m.recipient_type = 'supervisor' 
               AND m.sender_id = s.id AND m.sender_type = 'student'
            ) as unread_count
        FROM students s
        INNER JOIN student_deployments sd ON s.id = sd.student_id
        WHERE sd.supervisor_id = ? AND sd.status = 'Active' AND s.verified = 1
        ORDER BY 
            CASE WHEN last_message_time IS NULL THEN 1 ELSE 0 END,
            last_message_time DESC,
            s.first_name, s.last_name
    ");
    $students_stmt->bind_param("iiii", $supervisor_id, $supervisor_id, $supervisor_id, $supervisor_id);
    $students_stmt->execute();
    $students_result = $students_stmt->get_result();
    
    while ($row = $students_result->fetch_assoc()) {
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

// Get adviser contacts
$adviser_contacts = [];
try {
    $adviser_stmt = $conn->prepare("
        SELECT DISTINCT 
            aa.id, aa.name, aa.email, aa.profile_picture,
            (SELECT MAX(m.sent_at) 
             FROM messages m 
             WHERE (m.sender_id = aa.id AND m.sender_type = 'adviser' AND m.recipient_id = ? AND m.recipient_type = 'supervisor')
                OR (m.sender_id = ? AND m.sender_type = 'supervisor' AND m.recipient_id = aa.id AND m.recipient_type = 'adviser')
            ) as last_message_time,
            (SELECT COUNT(*) 
             FROM messages m 
             WHERE m.is_read = 0 AND m.recipient_id = ? AND m.recipient_type = 'supervisor' 
               AND m.sender_id = aa.id AND m.sender_type = 'adviser'
            ) as unread_count
        FROM academic_adviser aa
        WHERE aa.status = 'active'
        ORDER BY 
            CASE WHEN last_message_time IS NULL THEN 1 ELSE 0 END,
            last_message_time DESC,
            aa.name
    ");
    $adviser_stmt->bind_param("iii", $supervisor_id, $supervisor_id, $supervisor_id);
    $adviser_stmt->execute();
    $adviser_result = $adviser_stmt->get_result();
    
    while ($row = $adviser_result->fetch_assoc()) {
        $adviser_contacts[] = [
            'id' => 'adviser_' . $row['id'],
            'adviser_id' => $row['id'],
            'name' => $row['name'],
            'role' => 'Academic Adviser',
            'email' => $row['email'],
            'profile_picture' => $row['profile_picture'],
            'type' => 'adviser',
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
            
            if (!in_array($recipient_type, ['student', 'adviser'])) {
                echo json_encode(['success' => false, 'error' => 'Invalid recipient type']);
                exit();
            }
            
            try {
                $insert_stmt = $conn->prepare("
                    INSERT INTO messages (sender_id, sender_type, recipient_id, recipient_type, message, sent_at) 
                    VALUES (?, 'supervisor', ?, ?, ?, NOW())
                ");
                $insert_stmt->bind_param("isss", $supervisor_id, $recipient_id, $recipient_type, $message);
                
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
                // Clean the recipient_id
                if (strpos($recipient_id, 'student_') === 0) {
                    $recipient_id_clean = str_replace('student_', '', $recipient_id);
                } elseif (strpos($recipient_id, 'adviser_') === 0) {
                    $recipient_id_clean = str_replace('adviser_', '', $recipient_id);
                } else {
                    $recipient_id_clean = $recipient_id;
                }
                
                if ($recipient_type === 'student') {
                    $messages_stmt = $conn->prepare("
                        SELECT m.*,
                               CASE 
                                   WHEN m.sender_type = 'student' THEN CONCAT(s.first_name, ' ', s.last_name)
                                   WHEN m.sender_type = 'supervisor' THEN cs.full_name
                               END as sender_name,
                               CASE 
                                   WHEN m.sender_type = 'student' THEN s.profile_picture
                                   WHEN m.sender_type = 'supervisor' THEN NULL
                               END as sender_avatar,
                               CASE 
                                   WHEN m.sender_type = 'supervisor' AND m.sender_id = ? THEN 1
                                   ELSE 0
                               END as is_own_message
                        FROM messages m
                        LEFT JOIN students s ON m.sender_id = s.id AND m.sender_type = 'student'
                        LEFT JOIN company_supervisors cs ON m.sender_id = cs.supervisor_id AND m.sender_type = 'supervisor'
                        WHERE (
                            (m.sender_id = ? AND m.sender_type = 'supervisor' AND m.recipient_id = ? AND m.recipient_type = 'student')
                            OR 
                            (m.sender_id = ? AND m.sender_type = 'student' AND m.recipient_id = ? AND m.recipient_type = 'supervisor')
                        )
                        ORDER BY m.sent_at ASC
                    ");
                    
                    $messages_stmt->bind_param("iiiii", $supervisor_id, $supervisor_id, $recipient_id_clean, $recipient_id_clean, $supervisor_id);
                    
                    // Mark student messages as read
                    $mark_read_stmt = $conn->prepare("
                        UPDATE messages SET is_read = 1 
                        WHERE recipient_id = ? AND recipient_type = 'supervisor' AND sender_id = ? AND sender_type = 'student'
                    ");
                    $mark_read_stmt->bind_param("ii", $supervisor_id, $recipient_id_clean);
                    $mark_read_stmt->execute();
                    
                } else if ($recipient_type === 'adviser') {
                    $messages_stmt = $conn->prepare("
                        SELECT m.*,
                               CASE 
                                   WHEN m.sender_type = 'adviser' THEN aa.name
                                   WHEN m.sender_type = 'supervisor' THEN cs.full_name
                               END as sender_name,
                               CASE 
                                   WHEN m.sender_type = 'adviser' THEN aa.profile_picture
                                   WHEN m.sender_type = 'supervisor' THEN NULL
                               END as sender_avatar,
                               CASE 
                                   WHEN m.sender_type = 'supervisor' AND m.sender_id = ? THEN 1
                                   ELSE 0
                               END as is_own_message
                        FROM messages m
                        LEFT JOIN academic_adviser aa ON m.sender_id = aa.id AND m.sender_type = 'adviser'
                        LEFT JOIN company_supervisors cs ON m.sender_id = cs.supervisor_id AND m.sender_type = 'supervisor'
                        WHERE (
                            (m.sender_id = ? AND m.sender_type = 'supervisor' AND m.recipient_id = ? AND m.recipient_type = 'adviser')
                            OR 
                            (m.sender_id = ? AND m.sender_type = 'adviser' AND m.recipient_id = ? AND m.recipient_type = 'supervisor')
                        )
                        ORDER BY m.sent_at ASC
                    ");
                    
                    $messages_stmt->bind_param("iiiii", $supervisor_id, $supervisor_id, $recipient_id_clean, $recipient_id_clean, $supervisor_id);
                    
                    // Mark adviser messages as read
                    $mark_read_stmt = $conn->prepare("
                        UPDATE messages SET is_read = 1 
                        WHERE recipient_id = ? AND recipient_type = 'supervisor' AND sender_id = ? AND sender_type = 'adviser'
                    ");
                    $mark_read_stmt->bind_param("ii", $supervisor_id, $recipient_id_clean);
                    $mark_read_stmt->execute();
                }
                
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
                        'is_own' => ($row['is_own_message'] == 1),
                        'is_read' => $row['is_read'],
                        'sender_type' => $row['sender_type']
                    ];
                }
                
                echo json_encode(['success' => true, 'messages' => $messages]);
            } catch (Exception $e) {
                echo json_encode(['success' => false, 'error' => 'Failed to load messages: ' . $e->getMessage()]);
            }
            exit();
            
        case 'get_student_contacts':
            try {
                $contacts_stmt = $conn->prepare("
                    SELECT DISTINCT 
                        s.id, s.first_name, s.middle_name, s.last_name, s.email, 
                        s.student_id, s.department, s.program, s.year_level, s.profile_picture,
                        (SELECT MAX(m.sent_at) 
                         FROM messages m 
                         WHERE (m.sender_id = s.id AND m.sender_type = 'student' AND m.recipient_id = ? AND m.recipient_type = 'supervisor')
                            OR (m.sender_id = ? AND m.sender_type = 'supervisor' AND m.recipient_id = s.id AND m.recipient_type = 'student')
                        ) as last_message_time,
                        (SELECT COUNT(*) 
                         FROM messages m 
                         WHERE m.is_read = 0 AND m.recipient_id = ? AND m.recipient_type = 'supervisor' 
                           AND m.sender_id = s.id AND m.sender_type = 'student'
                        ) as unread_count
                    FROM students s
                    INNER JOIN student_deployments sd ON s.id = sd.student_id
                    WHERE sd.supervisor_id = ? AND sd.status = 'Active' AND s.verified = 1
                    ORDER BY 
                        CASE WHEN last_message_time IS NULL THEN 1 ELSE 0 END,
                        last_message_time DESC,
                        s.first_name, s.last_name
                ");
                $contacts_stmt->bind_param("iiii", $supervisor_id, $supervisor_id, $supervisor_id, $supervisor_id);
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
            
        case 'get_adviser_contacts':
            try {
                $contacts_stmt = $conn->prepare("
                    SELECT DISTINCT 
                        aa.id, aa.name, aa.email, aa.profile_picture,
                        (SELECT MAX(m.sent_at) 
                         FROM messages m 
                         WHERE (m.sender_id = aa.id AND m.sender_type = 'adviser' AND m.recipient_id = ? AND m.recipient_type = 'supervisor')
                            OR (m.sender_id = ? AND m.sender_type = 'supervisor' AND m.recipient_id = aa.id AND m.recipient_type = 'adviser')
                        ) as last_message_time,
                        (SELECT COUNT(*) 
                         FROM messages m 
                         WHERE m.is_read = 0 AND m.recipient_id = ? AND m.recipient_type = 'supervisor' 
                           AND m.sender_id = aa.id AND m.sender_type = 'adviser'
                        ) as unread_count
                    FROM academic_adviser aa
                    WHERE aa.status = 'active'
                    ORDER BY 
                        CASE WHEN last_message_time IS NULL THEN 1 ELSE 0 END,
                        last_message_time DESC,
                        aa.name
                ");
                $contacts_stmt->bind_param("iii", $supervisor_id, $supervisor_id, $supervisor_id);
                $contacts_stmt->execute();
                $contacts_result = $contacts_stmt->get_result();
                
                $contacts = [];
                while ($row = $contacts_result->fetch_assoc()) {
                    $contacts[] = [
                        'id' => 'adviser_' . $row['id'],
                        'adviser_id' => $row['id'],
                        'name' => $row['name'],
                        'role' => 'Academic Adviser',
                        'unread_count' => $row['unread_count'],
                        'last_message_time' => $row['last_message_time']
                    ];
                }
                
                echo json_encode(['success' => true, 'contacts' => $contacts]);
            } catch (Exception $e) {
                echo json_encode(['success' => false, 'error' => 'Failed to get contacts']);
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
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>OnTheJob Tracker - Company Messages</title>
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
        @media (max-width: 1023px) {
    body {
        position: fixed;
        width: 100%;
        height: 100vh;
        overflow: hidden;
    }
    
    .main-content-wrapper {
        height: 100vh;
        overflow: hidden;
        display: flex;
        flex-direction: column;
    }
    
    /* Landscape mode optimizations */
    @media (orientation: landscape) {
        .chat-header {
            padding: 0.75rem 1rem !important;
        }
        
        .message-input-area {
            padding: 0.75rem 1rem !important;
        }
        
        #messageInput {
            max-height: 60px !important;
        }
        
        #messagesArea {
            max-height: calc(100vh - 200px) !important;
        }
    }
}

/* Improved message input on mobile */
.message-input-area {
    position: relative;
    z-index: 10;
}

@media (max-width: 640px) {
    #messageInput {
        font-size: 16px; /* Prevents zoom on iOS */
        max-height: 120px;
    }
}
    </style>
</head>
<body class="bg-gray-50">
    <!-- Mobile Sidebar Overlay -->
    <div id="sidebarOverlay" class="fixed inset-0 bg-black bg-opacity-50 z-40 lg:hidden hidden sidebar-overlay"></div>

    <!-- Sidebar -->
    <div id="sidebar" class="fixed left-0 top-0 h-full w-64 bg-gradient-to-b from-bulsu-maroon to-bulsu-dark-maroon shadow-lg z-50 transform -translate-x-full lg:translate-x-0 transition-transform duration-300 ease-in-out sidebar">
        <div class="flex justify-end p-4 lg:hidden">
            <button id="closeSidebar" class="text-bulsu-light-gold hover:text-bulsu-gold">
                <i class="fas fa-times text-xl"></i>
            </button>
        </div>

        <div class="px-6 py-4 border-b border-bulsu-gold border-opacity-30">
            <div class="flex items-center">
                <img src="reqsample/bulsu12.png" alt="BULSU Logo" class="w-14 h-14 mr-2">
                <div class="flex items-center font-bold text-lg text-white">
                    <span>OnTheJob</span>
                    <span class="ml-1">Tracker</span>
                </div>
            </div>
        </div>
        
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
                <a href="CompanyStudentAccounts.php" class="nav-item flex items-center px-3 py-2 text-sm font-medium text-bulsu-light-gold hover:text-white hover:bg-bulsu-gold hover:bg-opacity-20 rounded-md transition-all duration-200">
                    <i class="fas fa-users mr-3 "></i>Student Accounts
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
                    <?php if ($unread_messages_count > 0): ?>
                        <span class="notification-badge ml-auto bg-red-500 text-white text-xs px-2 py-1 rounded-full">
                            <?php echo $unread_messages_count; ?>
                        </span>
                    <?php endif; ?>
                </a>
            </nav>
        </div>
        
        <div class="absolute bottom-0 left-0 right-0 p-4 border-t border-bulsu-gold border-opacity-30 bg-gradient-to-t from-black to-transparent">
            <div class="flex items-center space-x-3">
                <div class="flex-shrink-0 w-10 h-10 bg-gradient-to-r from-bulsu-gold to-yellow-400 rounded-full flex items-center justify-center text-bulsu-maroon font-semibold text-sm overflow-hidden">
                    <?php if (!empty($profile_picture) && file_exists($profile_picture)): ?>
                        <img src="<?php echo htmlspecialchars($profile_picture); ?>" alt="Profile Picture" class="w-full h-full object-cover">
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
    <!-- Main Content -->
<div class="lg:ml-64 min-h-screen main-content-wrapper">
        <!-- Header -->
        <div class="bg-white shadow-sm border-b border-gray-200">
            <div class="flex items-center justify-between px-4 sm:px-6 py-4">
                <button id="mobileMenuBtn" class="lg:hidden p-2 rounded-md text-gray-500 hover:text-gray-900 hover:bg-gray-100">
                    <i class="fas fa-bars text-xl"></i>
                </button>

                <div class="flex-1 lg:ml-0 ml-4">
                    <h1 class="text-xl sm:text-2xl font-bold text-gray-900">Messages</h1>
                    <p class="text-sm sm:text-base text-gray-500 hidden sm:block">Communicate with students and academic advisers</p>
                </div>
                
                <div class="relative">
                    <button id="profileBtn" class="flex items-center p-1 rounded-full hover:bg-gray-100">
                        <div class="w-8 h-8 sm:w-10 sm:h-10 bg-gradient-to-r from-blue-500 to-purple-600 rounded-full flex items-center justify-center text-white font-semibold text-xs sm:text-sm overflow-hidden">
                            <?php if (!empty($profile_picture) && file_exists($profile_picture)): ?>
                                <img src="<?php echo htmlspecialchars($profile_picture); ?>" alt="Profile Picture" class="w-full h-full object-cover">
                            <?php else: ?>
                                <?php echo $initials; ?>
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
                                        <?php echo $initials; ?>
                                    <?php endif; ?>
                                </div>
                                <div>
                                    <p class="font-medium text-gray-900"><?php echo htmlspecialchars($supervisor_name); ?></p>
                                    <p class="text-sm text-gray-500">Company Supervisor</p>
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
            <?php if (isset($error_message)): ?>
                <div class="mb-6 p-4 bg-red-50 border border-red-200 rounded-lg">
                    <div class="flex items-start">
                        <i class="fas fa-exclamation-triangle text-red-600 mt-1 mr-3"></i>
                        <p class="text-red-700"><?php echo htmlspecialchars($error_message); ?></p>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Messages Container -->
            <!-- Messages Container -->
<div class="bg-white rounded-lg shadow-sm border border-gray-200 h-[calc(100vh-12rem)] lg:h-[calc(100vh-12rem)] overflow-hidden">
    <div class="grid grid-cols-1 lg:grid-cols-5 h-full relative">
                    <!-- Contacts Sidebar -->
<div id="contactsSidebar" class="lg:col-span-2 border-r border-gray-200 flex flex-col bg-gray-50 h-full overflow-hidden">
                        <!-- Contacts Header -->
                        <div class="p-4 sm:p-6 border-b border-gray-200 bg-white">
                            <button class="lg:hidden mb-4 p-2 text-gray-500 hover:text-gray-700" onclick="hideMobileContacts()" id="mobileBackBtn">
                                <i class="fas fa-arrow-left text-lg"></i>
                            </button>
                            
                            <!-- Tab Buttons -->
                            <div class="flex space-x-2 mb-4">
                                <button onclick="switchToStudents()" id="studentsTab" class="flex-1 px-4 py-2 text-sm font-medium rounded-lg bg-bulsu-maroon text-white">
                                    <i class="fas fa-user-graduate mr-2"></i>Students
                                    <?php if (count(array_filter($student_contacts, function($c) { return $c['unread_count'] > 0; })) > 0): ?>
                                        <span class="ml-2 bg-red-500 text-white text-xs px-2 py-1 rounded-full">
                                            <?php echo array_sum(array_column($student_contacts, 'unread_count')); ?>
                                        </span>
                                    <?php endif; ?>
                                </button>
                                <button onclick="switchToAdvisers()" id="advisersTab" class="flex-1 px-4 py-2 text-sm font-medium rounded-lg bg-gray-200 text-gray-700 hover:bg-gray-300">
                                    <i class="fas fa-chalkboard-teacher mr-2"></i>Advisers
                                    <?php if (count(array_filter($adviser_contacts, function($c) { return $c['unread_count'] > 0; })) > 0): ?>
                                        <span class="ml-2 bg-red-500 text-white text-xs px-2 py-1 rounded-full">
                                            <?php echo array_sum(array_column($adviser_contacts, 'unread_count')); ?>
                                        </span>
                                    <?php endif; ?>
                                </button>
                            </div>
                            
                            <div class="flex items-center justify-between">
                                <div class="flex items-center">
                                    <i class="fas fa-users text-blue-600 mr-3"></i>
                                    <div>
                                        <h3 class="text-lg font-medium text-gray-900" id="contactsTitle">Student Conversations</h3>
                                        <p class="text-sm text-gray-500" id="contactsCount"><?php echo count($student_contacts); ?> conversations</p>
                                    </div>
                                </div>
                                <button class="p-2 text-gray-500 hover:text-gray-700 hover:bg-gray-100 rounded-md transition-colors" onclick="refreshContacts()" title="Refresh contacts">
                                    <i class="fas fa-sync-alt"></i>
                                </button>
                            </div>
                        </div>
                        
                        <!-- Contacts List -->
                        <div class="overflow-y-auto custom-scrollbar" id="contactsList" style="max-height: 640px;">
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
<div id="chatArea" class="lg:col-span-3 flex flex-col h-full hidden lg:flex">
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
<div class="flex-1 overflow-y-auto custom-scrollbar p-4 sm:p-6 bg-gray-50 touch-pan-y" id="messagesArea" style="overscroll-behavior: contain;">
                            <div class="flex flex-col items-center justify-center h-full text-center">
                                <i class="fas fa-comments text-gray-300 text-6xl mb-4"></i>
                                <h3 class="text-xl font-medium text-gray-900 mb-2">Select a Contact</h3>
                                <p class="text-gray-500">Choose a student or adviser from the sidebar to view your conversation</p>
                            </div>
                        </div>
                        
                        <!-- Message Input Area -->
                        <div class="message-input-area p-4 sm:p-6 border-t border-gray-200 bg-white hidden flex-shrink-0" id="messageInputArea">
    <div class="flex items-end space-x-3">
        <textarea class="flex-1 resize-none border border-gray-300 rounded-lg px-4 py-3 focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition-colors" 
                  id="messageInput" 
                  placeholder="Type your reply..." 
                  rows="1" 
                  onkeypress="handleKeyPress(event)"
                  oninput="adjustTextareaHeight(this)"
                  autocomplete="off"
                  autocorrect="off"
                  autocapitalize="sentences"></textarea>
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
        let currentTab = 'students';

        // Initialize the messaging system
        document.addEventListener('DOMContentLoaded', function() {
            initializeMessaging();
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

        document.addEventListener('click', function(e) {
            const profileDropdown = document.getElementById('profileDropdown');
            if (!e.target.closest('#profileBtn') && !profileDropdown.classList.contains('hidden')) {
                profileDropdown.classList.add('hidden');
            }
        });

        function initializeMessaging() {
            const messageInput = document.getElementById('messageInput');
            const sendButton = document.getElementById('sendButton');
            
            messageInput.addEventListener('input', function() {
                const hasText = this.value.trim().length > 0;
                sendButton.disabled = !hasText;
            });

            messageInput.addEventListener('input', function() {
                adjustTextareaHeight(this);
            });
        }

function selectContact(contactElement) {
    if (contactElement.classList.contains('disabled')) {
        showToast('Cannot select this contact', 'warning');
        return;
    }

    document.querySelectorAll('.contact-item').forEach(item => {
        item.classList.remove('bg-blue-50', 'border-l-4', 'border-l-blue-500');
    });

    contactElement.classList.add('bg-blue-50', 'border-l-4', 'border-l-blue-500');

    const contactType = contactElement.dataset.contactType;
    
    currentContact = {
        id: contactElement.dataset.contactId,
        name: contactElement.dataset.contactName,
        role: contactElement.dataset.contactRole,
        type: contactType
    };

    if (contactType === 'student') {
        currentContact.student_id = contactElement.dataset.studentId;
    } else if (contactType === 'adviser') {
        currentContact.adviser_id = contactElement.dataset.adviserId;
    }

    updateChatHeader(currentContact);
    loadMessages();

    document.getElementById('chatHeader').classList.remove('hidden');
    document.getElementById('messageInputArea').classList.remove('hidden');

    const unreadBadge = contactElement.querySelector('.absolute');
    if (unreadBadge && unreadBadge.classList.contains('bg-red-500')) {
        unreadBadge.classList.add('hidden');
    }

    // Switch to chat view on mobile
    if (window.innerWidth < 1024) {
        hideMobileContacts();
        setTimeout(() => {
            const messagesArea = document.getElementById('messagesArea');
            messagesArea.scrollTop = 0;
        }, 100);
    }
}

        function hideMobileContacts() {
            if (window.innerWidth < 1024) {
                const contactsSidebar = document.getElementById('contactsSidebar');
                const chatArea = document.getElementById('chatArea');
                contactsSidebar.classList.add('hidden');
                chatArea.classList.remove('hidden');
                chatArea.classList.add('flex');
            }
        }

        function updateChatHeader(contact) {
            const chatAvatar = document.getElementById('chatAvatar');
            const chatName = document.getElementById('chatName');
            const chatRole = document.getElementById('chatRole');

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

            let recipientId;
            if (currentContact.type === 'student') {
                recipientId = currentContact.student_id || currentContact.id.replace('student_', '');
            } else if (currentContact.type === 'adviser') {
                recipientId = currentContact.adviser_id || currentContact.id.replace('adviser_', '');
            }

            const formData = new FormData();
            formData.append('action', 'get_messages');
            formData.append('recipient_id', recipientId);
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
                                <p class="text-sm mt-2">${data.error || 'Unknown error'}</p>
                            </div>
                        </div>
                    `;
                }
            })
            .catch(error => {
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
                        <p class="text-gray-500">This is the beginning of your conversation</p>
                    </div>
                `;
                return;
            }

            let messagesHTML = '';
            let currentDate = '';

            messages.forEach(message => {
                const messageDate = new Date(message.sent_at).toDateString();
                
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

            messageInput.disabled = true;
            sendButton.disabled = true;
            sendButton.innerHTML = '<i class="fas fa-spinner animate-spin"></i>';

            let recipientId;
            if (currentContact.type === 'student') {
                recipientId = currentContact.student_id || currentContact.id.replace('student_', '');
            } else if (currentContact.type === 'adviser') {
                recipientId = currentContact.adviser_id || currentContact.id.replace('adviser_', '');
            }

            const formData = new FormData();
            formData.append('action', 'send_message');
            formData.append('recipient_id', recipientId);
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
                    loadMessages();
                    showToast('Message sent successfully', 'success');
                    
                    setTimeout(refreshContacts, 1000);
                } else {
                    showToast(data.error || 'Failed to send message', 'error');
                }
            })
            .catch(error => {
                showToast('Error sending message', 'error');
            })
            .finally(() => {
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
                    // Update UI if needed
                }
            })
            .catch(error => {
                console.error('Error updating unread counts:', error);
            });
        }

        function switchToStudents() {
            currentTab = 'students';
            
            document.getElementById('studentsTab').classList.remove('bg-gray-200', 'text-gray-700');
            document.getElementById('studentsTab').classList.add('bg-bulsu-maroon', 'text-white');
            document.getElementById('advisersTab').classList.remove('bg-bulsu-maroon', 'text-white');
            document.getElementById('advisersTab').classList.add('bg-gray-200', 'text-gray-700');
            
            document.getElementById('contactsTitle').textContent = 'Student Conversations';
            
            refreshStudentContacts();
        }

        function switchToAdvisers() {
            currentTab = 'advisers';
            
            document.getElementById('advisersTab').classList.remove('bg-gray-200', 'text-gray-700');
            document.getElementById('advisersTab').classList.add('bg-bulsu-maroon', 'text-white');
            document.getElementById('studentsTab').classList.remove('bg-bulsu-maroon', 'text-white');
            document.getElementById('studentsTab').classList.add('bg-gray-200', 'text-gray-700');
            
            document.getElementById('contactsTitle').textContent = 'Adviser Conversations';
            
            refreshAdviserContacts();
        }

        function refreshContacts() {
            if (currentTab === 'students') {
                refreshStudentContacts();
            } else {
                refreshAdviserContacts();
            }
        }

        function refreshStudentContacts() {
            const formData = new FormData();
            formData.append('action', 'get_student_contacts');

            fetch('', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    updateContactsList(data.contacts, 'student');
                }
            })
            .catch(error => {
                console.error('Error refreshing student contacts:', error);
            });
        }

        function refreshAdviserContacts() {
            const formData = new FormData();
            formData.append('action', 'get_adviser_contacts');

            fetch('', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    updateContactsList(data.contacts, 'adviser');
                }
            })
            .catch(error => {
                console.error('Error refreshing adviser contacts:', error);
            });
        }

        function updateContactsList(contacts, type) {
            const contactsList = document.getElementById('contactsList');
            const contactsCount = document.getElementById('contactsCount');
            
            if (contacts.length === 0) {
                const emptyMessage = type === 'student' ? 'Students' : 'Advisers';
                contactsList.innerHTML = `
                    <div class="flex flex-col items-center justify-center h-64 text-center p-6">
                        <i class="fas fa-comments text-gray-300 text-5xl mb-4"></i>
                        <h4 class="text-lg font-medium text-gray-900 mb-2">No Messages Yet</h4>
                        <p class="text-gray-500">${emptyMessage} will appear here when they send you a message</p>
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

                const gradientClass = type === 'student' ? 'from-green-500 to-teal-600' : 'from-purple-500 to-indigo-600';
                const contactType = type === 'student' ? 'student' : 'adviser';
                const idField = type === 'student' ? `data-student-id="${contact.student_id}"` : `data-adviser-id="${contact.adviser_id}"`;

                contactsHTML += `
                    <div class="contact-item p-4 border-b border-gray-200 cursor-pointer hover:bg-gray-100 transition-colors relative" 
                         data-contact-id="${contact.id}"
                         data-contact-name="${escapeHtml(contact.name)}"
                         data-contact-role="${escapeHtml(contact.role)}"
                         data-contact-type="${contactType}"
                         ${idField}
                         onclick="selectContact(this)">
                        <div class="flex items-center space-x-3">
                            <div class="flex-shrink-0 w-12 h-12 bg-gradient-to-r ${gradientClass} rounded-full flex items-center justify-center text-white font-semibold text-sm">
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

        function showMobileContacts() {
            const contactsSidebar = document.getElementById('contactsSidebar');
            contactsSidebar.classList.remove('lg:col-span-2');
            contactsSidebar.classList.add('col-span-1', 'absolute', 'inset-0', 'z-10', 'bg-white');
        }

       function showMobileContacts() {
    if (window.innerWidth < 1024) {
        const contactsSidebar = document.getElementById('contactsSidebar');
        const chatArea = document.getElementById('chatArea');
        contactsSidebar.classList.remove('hidden');
        chatArea.classList.add('hidden');
        chatArea.classList.remove('flex');
    }
}

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

        function confirmLogout() {
            return confirm('Are you sure you want to logout?');
        }

        window.addEventListener('online', function() {
            showToast('Connection restored', 'success');
        });

        window.addEventListener('offline', function() {
            showToast('Connection lost. Messages will be sent when connection is restored.', 'warning');
        });

        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                if (window.innerWidth < 1024) {
                    closeSidebar();
                }
                
                const profileDropdown = document.getElementById('profileDropdown');
                if (!profileDropdown.classList.contains('hidden')) {
                    profileDropdown.classList.add('hidden');
                }
            }
        });
// Prevent zoom on input focus (iOS fix)
function preventZoom(e) {
    if (e.target.tagName === 'TEXTAREA' || e.target.tagName === 'INPUT') {
        e.target.style.fontSize = '16px';
    }
}

// Handle window resize for responsive behavior
window.addEventListener('resize', function() {
    const contactsSidebar = document.getElementById('contactsSidebar');
    const chatArea = document.getElementById('chatArea');
    
    if (window.innerWidth >= 1024) {
        // Desktop view - show both
        contactsSidebar.classList.remove('hidden');
        chatArea.classList.remove('hidden');
        chatArea.classList.add('flex');
    } else {
        // Mobile view - show appropriate panel
        if (currentContact) {
            contactsSidebar.classList.add('hidden');
            chatArea.classList.remove('hidden');
            chatArea.classList.add('flex');
        } else {
            contactsSidebar.classList.remove('hidden');
            chatArea.classList.add('hidden');
        }
    }
});

// Add focus event listener for inputs
document.addEventListener('focus', preventZoom, true);
        console.log('Company Supervisor Message System initialized');
    </script>
</body>
</html>