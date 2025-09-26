<?php
include('connect.php');
session_start();

// Check if user is logged in and is an adviser
if (!isset($_SESSION['adviser_id']) || $_SESSION['user_type'] !== 'adviser') {
    header("Location: login.php");
    exit;
}

// Get adviser information
$adviser_id = $_SESSION['adviser_id'];
$adviser_name = $_SESSION['name'];
$adviser_email = $_SESSION['email'];

// Handle form submissions
$message = '';
$message_type = '';

 $unread_messages_query = "SELECT COUNT(*) as count FROM messages WHERE recipient_type = 'adviser' AND sender_type = 'student' AND is_read = 0 AND is_deleted_by_recipient = 0";
    $unread_messages_result = mysqli_query($conn, $unread_messages_query);
    $unread_messages_count = mysqli_fetch_assoc($unread_messages_result)['count'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        switch ($_POST['action']) {
            case 'add_document':
                $document_name = trim($_POST['document_name']);
                $document_description = trim($_POST['document_description']);
                $is_required = isset($_POST['is_required']) ? 1 : 0;
                
                if (!empty($document_name)) {
                    try {
                        $stmt = $conn->prepare("INSERT INTO document_requirements (name, description, is_required, created_by, created_at) VALUES (?, ?, ?, ?, NOW())");
                        if (!$stmt) {
                            throw new Exception("Prepare failed: " . $conn->error);
                        }
                        
                        $stmt->bind_param("ssis", $document_name, $document_description, $is_required, $adviser_id);
                        
                        if ($stmt->execute()) {
                            $message = "Document requirement '{$document_name}' has been added successfully.";
                            $message_type = 'success';
                        } else {
                            throw new Exception("Execute failed: " . $stmt->error);
                        }
                        $stmt->close();
                    } catch (Exception $e) {
                        $message = "Database error: " . $e->getMessage();
                        $message_type = 'error';
                    }
                } else {
                    $message = "Document name is required.";
                    $message_type = 'error';
                }
                break;
                
            case 'edit_document':
                $doc_id = intval($_POST['doc_id']);
                $document_name = trim($_POST['document_name']);
                $document_description = trim($_POST['document_description']);
                $is_required = isset($_POST['is_required']) ? 1 : 0;
                
                if (!empty($document_name) && $doc_id > 0) {
                    try {
                        $stmt = $conn->prepare("UPDATE document_requirements SET name = ?, description = ?, is_required = ? WHERE id = ?");
                        if (!$stmt) {
                            throw new Exception("Prepare update failed: " . $conn->error);
                        }
                        
                        $stmt->bind_param("ssii", $document_name, $document_description, $is_required, $doc_id);
                        
                        if ($stmt->execute()) {
                            if ($stmt->affected_rows > 0) {
                                $message = "Document requirement has been updated successfully.";
                                $message_type = 'success';
                            } else {
                                $message = "No changes were made to the document requirement.";
                                $message_type = 'info';
                            }
                        } else {
                            throw new Exception("Execute update failed: " . $stmt->error);
                        }
                        $stmt->close();
                    } catch (Exception $e) {
                        $message = "Database error: " . $e->getMessage();
                        $message_type = 'error';
                    }
                } else {
                    $message = "Document name is required and valid ID must be provided.";
                    $message_type = 'error';
                }
                break;
                
            case 'toggle_requirement':
                $doc_id = intval($_POST['doc_id']);
                
                if ($doc_id > 0) {
                    try {
                        $conn->autocommit(FALSE);
                        
                        $stmt = $conn->prepare("SELECT is_required FROM document_requirements WHERE id = ?");
                        if (!$stmt) {
                            throw new Exception("Prepare select failed: " . $conn->error);
                        }
                        
                        $stmt->bind_param("i", $doc_id);
                        if (!$stmt->execute()) {
                            throw new Exception("Execute select failed: " . $stmt->error);
                        }
                        
                        $result = $stmt->get_result();
                        
                        if ($row = $result->fetch_assoc()) {
                            $new_status = $row['is_required'] ? 0 : 1;
                            $stmt->close();
                            
                            $update_stmt = $conn->prepare("UPDATE document_requirements SET is_required = ? WHERE id = ?");
                            if (!$update_stmt) {
                                throw new Exception("Prepare update failed: " . $conn->error);
                            }
                            
                            $update_stmt->bind_param("ii", $new_status, $doc_id);
                            
                            if ($update_stmt->execute()) {
                                if ($update_stmt->affected_rows > 0) {
                                    $status_text = $new_status ? 'required' : 'optional';
                                    $message = "Document requirement status has been updated to {$status_text}.";
                                    $message_type = 'success';
                                    $conn->commit();
                                } else {
                                    throw new Exception("No rows were affected during update.");
                                }
                            } else {
                                throw new Exception("Execute update failed: " . $update_stmt->error);
                            }
                            $update_stmt->close();
                        } else {
                            throw new Exception("Document not found with ID: " . $doc_id);
                        }
                        
                        $conn->autocommit(TRUE);
                    } catch (Exception $e) {
                        $conn->rollback();
                        $conn->autocommit(TRUE);
                        $message = "Database error: " . $e->getMessage();
                        $message_type = 'error';
                    }
                } else {
                    $message = "Invalid document ID provided.";
                    $message_type = 'error';
                }
                break;
                
            case 'delete_document':
                $doc_id = intval($_POST['doc_id']);
                
                if ($doc_id > 0) {
                    try {
                        $conn->autocommit(FALSE);
                        
                        $check_stmt = $conn->prepare("SELECT name FROM document_requirements WHERE id = ?");
                        if (!$check_stmt) {
                            throw new Exception("Prepare check failed: " . $conn->error);
                        }
                        
                        $check_stmt->bind_param("i", $doc_id);
                        $check_stmt->execute();
                        $check_result = $check_stmt->get_result();
                        
                        if ($check_result->num_rows === 0) {
                            throw new Exception("Document not found with ID: " . $doc_id);
                        }
                        
                        $doc_data = $check_result->fetch_assoc();
                        $doc_name = $doc_data['name'];
                        $check_stmt->close();
                        
                        $stmt = $conn->prepare("DELETE FROM document_requirements WHERE id = ?");
                        if (!$stmt) {
                            throw new Exception("Prepare delete failed: " . $conn->error);
                        }
                        
                        $stmt->bind_param("i", $doc_id);
                        
                        if ($stmt->execute()) {
                            if ($stmt->affected_rows > 0) {
                                $message = "Document requirement '{$doc_name}' has been deleted successfully.";
                                $message_type = 'success';
                                $conn->commit();
                            } else {
                                throw new Exception("No rows were affected during deletion.");
                            }
                        } else {
                            throw new Exception("Execute delete failed: " . $stmt->error);
                        }
                        $stmt->close();
                        
                        $conn->autocommit(TRUE);
                    } catch (Exception $e) {
                        $conn->rollback();
                        $conn->autocommit(TRUE);
                        $message = "Database error: " . $e->getMessage();
                        $message_type = 'error';
                    }
                } else {
                    $message = "Invalid document ID provided.";
                    $message_type = 'error';
                }
                break;
        }
    }
}

// Fetch all document requirements from database
$documents = [];
try {
    $stmt = $conn->prepare("SELECT id, name, description, is_required, created_at, created_by FROM document_requirements ORDER BY created_at DESC");
    if (!$stmt) {
        throw new Exception("Prepare select failed: " . $conn->error);
    }
    
    $stmt->execute();
    $result = $stmt->get_result();
    
    while ($row = $result->fetch_assoc()) {
        $documents[] = $row;
    }
    $stmt->close();
} catch (Exception $e) {
    $message = "Error fetching documents: " . $e->getMessage();
    $message_type = 'error';
}

// Create adviser initials
$adviser_initials = strtoupper(substr($adviser_name, 0, 2));

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
    <title>OnTheJob Tracker - Edit Document Requirements</title>
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

        .toggle-switch {
            position: relative;
            width: 50px;
            height: 24px;
            background-color: #ccc;
            border-radius: 12px;
            cursor: pointer;
            transition: background-color 0.3s;
        }

        .toggle-switch.active {
            background-color: #10b981;
        }

        .toggle-slider {
            position: absolute;
            top: 2px;
            left: 2px;
            width: 20px;
            height: 20px;
            background-color: white;
            border-radius: 50%;
            transition: transform 0.3s;
        }

        .toggle-switch.active .toggle-slider {
            transform: translateX(26px);
        }

        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(10px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .fade-in {
            animation: fadeIn 0.3s ease-out;
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
            <a href="academicAdviserMessage.php" class="nav-item flex items-center px-3 py-2 text-sm font-medium text-bulsu-light-gold hover:text-white hover:bg-bulsu-gold hover:bg-opacity-20 rounded-md transition-all duration-200">
                <i class="fas fa-envelope mr-3"></i>
                Messages
                <?php if ($unread_messages_count > 0): ?>
                    <span class="notification-badge" id="sidebar-notification-badge">
                        <?php echo $unread_messages_count; ?>
                    </span>
                <?php endif; ?>
            </a>
            <a href="academicAdviserEdit.php" class="nav-item flex items-center px-3 py-2 text-sm font-medium text-white bg-bulsu-gold bg-opacity-20 border border-bulsu-gold border-opacity-30 rounded-md">
                <i class="fas fa-edit mr-3 text-bulsu-gold"></i>
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
                    <h1 class="text-xl sm:text-2xl font-bold text-gray-900">Document Requirements Management</h1>
                    <p class="text-sm sm:text-base text-gray-500 hidden sm:block">Configure required documents for OJT students</p>
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
            <!-- Alert Messages -->
            <?php if (!empty($message)): ?>
                <div class="mb-6 fade-in">
                    <div class="p-4 rounded-lg border-l-4 <?php echo ($message_type === 'success') ? 'bg-green-50 border-green-500 text-green-700' : (($message_type === 'info') ? 'bg-blue-50 border-blue-500 text-blue-700' : 'bg-red-50 border-red-500 text-red-700'); ?>">
                        <div class="flex items-start">
                            <i class="fas <?php echo ($message_type === 'success') ? 'fa-check-circle' : (($message_type === 'info') ? 'fa-info-circle' : 'fa-exclamation-triangle'); ?> mt-1 mr-3"></i>
                            <p><?php echo htmlspecialchars($message); ?></p>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Header Section -->
            <div class="mb-6 sm:mb-8">
                <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between">
                    <div class="mb-4 sm:mb-0">
                        <h2 class="text-2xl sm:text-3xl font-bold text-gray-900 mb-2">Document Requirements</h2>
                        <p class="text-gray-600">Manage required documents for OJT students (<?php echo count($documents); ?> total)</p>
                    </div>
                    <button onclick="openAddModal()" class="inline-flex items-center px-4 py-2 bg-green-600 text-white font-medium rounded-lg hover:bg-green-700 transition-colors">
                        <i class="fas fa-plus mr-2"></i>
                        Add Document Requirement
                    </button>
                </div>
            </div>

            <!-- Documents Table -->
            <div class="bg-white rounded-lg shadow-sm border border-gray-200">
                <?php if (empty($documents)): ?>
                    <div class="text-center py-16">
                        <i class="fas fa-file-alt text-gray-400 text-6xl mb-4"></i>
                        <h3 class="text-xl font-medium text-gray-900 mb-2">No Document Requirements Found</h3>
                        <p class="text-gray-600 mb-6">Start by adding your first document requirement for OJT students.</p>
                        <button onclick="openAddModal()" class="inline-flex items-center px-4 py-2 bg-green-600 text-white font-medium rounded-lg hover:bg-green-700 transition-colors">
                            <i class="fas fa-plus mr-2"></i>
                            Add Your First Document
                        </button>
                    </div>
                <?php else: ?>
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-200">
                            <thead class="bg-gray-50">
                                <tr>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Document Name</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Status</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Created</th>
                                    <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Actions</th>
                                </tr>
                            </thead>
                            <tbody class="bg-white divide-y divide-gray-200">
                                <?php foreach ($documents as $doc): ?>
                                    <tr data-doc-id="<?php echo $doc['id']; ?>" class="hover:bg-gray-50">
                                        <td class="px-6 py-4">
                                            <div>
                                                <div class="flex items-center">
                                                    <div class="text-sm font-medium text-gray-900"><?php echo htmlspecialchars($doc['name']); ?></div>
                                                    <span class="ml-2 inline-flex items-center px-2 py-0.5 rounded text-xs font-medium <?php echo $doc['is_required'] ? 'bg-red-100 text-red-800' : 'bg-gray-100 text-gray-800'; ?>">
                                                        <?php echo $doc['is_required'] ? 'Required' : 'Optional'; ?>
                                                    </span>
                                                </div>
                                                <?php if (!empty($doc['description'])): ?>
                                                    <div class="text-sm text-gray-500 mt-1"><?php echo htmlspecialchars($doc['description']); ?></div>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                        <td class="px-6 py-4">
                                            <div class="flex items-center space-x-3">
                                                <div class="toggle-switch <?php echo $doc['is_required'] ? 'active' : ''; ?>" 
                                                     onclick="toggleRequirement(<?php echo $doc['id']; ?>)">
                                                    <div class="toggle-slider"></div>
                                                </div>
                                                <span class="status-text text-sm text-gray-700"><?php echo $doc['is_required'] ? 'Required' : 'Optional'; ?></span>
                                            </div>
                                        </td>
                                        <td class="px-6 py-4 text-sm text-gray-500">
                                            <?php echo date('M j, Y', strtotime($doc['created_at'])); ?>
                                        </td>
                                        <td class="px-6 py-4 text-right text-sm font-medium">
                                            <div class="flex items-center justify-end space-x-2">
                                                <button onclick="editDocument(<?php echo $doc['id']; ?>, '<?php echo htmlspecialchars($doc['name'], ENT_QUOTES); ?>', '<?php echo htmlspecialchars($doc['description'], ENT_QUOTES); ?>', <?php echo $doc['is_required'] ? 'true' : 'false'; ?>)"
                                                        class="inline-flex items-center px-3 py-1 bg-blue-600 text-white text-xs font-medium rounded hover:bg-blue-700 transition-colors">
                                                    <i class="fas fa-edit mr-1"></i>
                                                    Edit
                                                </button>
                                                <button onclick="deleteDocument(<?php echo $doc['id']; ?>, '<?php echo htmlspecialchars($doc['name'], ENT_QUOTES); ?>')"
                                                        class="inline-flex items-center px-3 py-1 bg-red-600 text-white text-xs font-medium rounded hover:bg-red-700 transition-colors">
                                                    <i class="fas fa-trash mr-1"></i>
                                                    Delete
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Add Document Modal -->
    <div id="addDocumentModal" class="fixed inset-0 z-50 hidden">
        <div class="fixed inset-0 bg-black bg-opacity-50"></div>
        <div class="fixed inset-0 flex items-center justify-center p-4">
            <div class="bg-white rounded-lg shadow-xl max-w-md w-full">
                <div class="flex items-center justify-between p-6 border-b border-gray-200">
                    <h3 class="text-lg font-medium text-gray-900">Add Document Requirement</h3>
                    <button onclick="closeAddModal()" class="text-gray-400 hover:text-gray-600">
                        <i class="fas fa-times text-xl"></i>
                    </button>
                </div>
                <form id="addDocumentForm" method="POST" class="p-6">
                    <input type="hidden" name="action" value="add_document">
                    
                    <div class="mb-4">
                        <label class="block text-sm font-medium text-gray-700 mb-2">Document Name *</label>
                        <input type="text" name="document_name" required
                               class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                    </div>
                    
                    <div class="mb-4">
                        <label class="block text-sm font-medium text-gray-700 mb-2">Description</label>
                        <textarea name="document_description" rows="3"
                                  class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                                  placeholder="Brief description of the document requirement"></textarea>
                    </div>
                    
                    <div class="mb-6">
                        <label class="flex items-center">
                            <input type="checkbox" name="is_required" checked
                                   class="w-4 h-4 text-blue-600 border-gray-300 rounded focus:ring-blue-500">
                            <span class="ml-2 text-sm text-gray-700">This document is required</span>
                        </label>
                    </div>
                    
                    <div class="flex items-center justify-end space-x-3">
                        <button type="button" onclick="closeAddModal()" 
                                class="px-4 py-2 text-sm font-medium text-gray-700 bg-gray-100 rounded-md hover:bg-gray-200 transition-colors">
                            Cancel
                        </button>
                        <button type="submit"
                                class="px-4 py-2 text-sm font-medium text-white bg-green-600 rounded-md hover:bg-green-700 transition-colors">
                            Add Document
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Edit Document Modal -->
    <div id="editDocumentModal" class="fixed inset-0 z-50 hidden">
        <div class="fixed inset-0 bg-black bg-opacity-50"></div>
        <div class="fixed inset-0 flex items-center justify-center p-4">
            <div class="bg-white rounded-lg shadow-xl max-w-md w-full">
                <div class="flex items-center justify-between p-6 border-b border-gray-200">
                    <h3 class="text-lg font-medium text-gray-900">Edit Document Requirement</h3>
                    <button onclick="closeEditModal()" class="text-gray-400 hover:text-gray-600">
                        <i class="fas fa-times text-xl"></i>
                    </button>
                </div>
                <form id="editDocumentForm" method="POST" class="p-6">
                    <input type="hidden" name="action" value="edit_document">
                    <input type="hidden" name="doc_id" id="edit_doc_id">
                    
                    <div class="mb-4">
                        <label class="block text-sm font-medium text-gray-700 mb-2">Document Name *</label>
                        <input type="text" name="document_name" id="edit_document_name" required
                               class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                    </div>
                    
                    <div class="mb-4">
                        <label class="block text-sm font-medium text-gray-700 mb-2">Description</label>
                        <textarea name="document_description" id="edit_document_description" rows="3"
                                  class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500"></textarea>
                    </div>
                    
                    <div class="mb-6">
                        <label class="flex items-center">
                            <input type="checkbox" name="is_required" id="edit_is_required"
                                   class="w-4 h-4 text-blue-600 border-gray-300 rounded focus:ring-blue-500">
                            <span class="ml-2 text-sm text-gray-700">This document is required</span>
                        </label>
                    </div>
                    
                    <div class="flex items-center justify-end space-x-3">
                        <button type="button" onclick="closeEditModal()" 
                                class="px-4 py-2 text-sm font-medium text-gray-700 bg-gray-100 rounded-md hover:bg-gray-200 transition-colors">
                            Cancel
                        </button>
                        <button type="submit"
                                class="px-4 py-2 text-sm font-medium text-white bg-blue-600 rounded-md hover:bg-blue-700 transition-colors">
                            Update Document
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Delete Confirmation Modal -->
    <div id="deleteConfirmModal" class="fixed inset-0 z-50 hidden">
        <div class="fixed inset-0 bg-black bg-opacity-50"></div>
        <div class="fixed inset-0 flex items-center justify-center p-4">
            <div class="bg-white rounded-lg shadow-xl max-w-md w-full">
                <div class="flex items-center justify-between p-6 border-b border-gray-200">
                    <h3 class="text-lg font-medium text-gray-900">Confirm Delete</h3>
                    <button onclick="closeDeleteModal()" class="text-gray-400 hover:text-gray-600">
                        <i class="fas fa-times text-xl"></i>
                    </button>
                </div>
                <div class="p-6">
                    <div class="flex items-start space-x-3">
                        <div class="flex-shrink-0">
                            <i class="fas fa-exclamation-triangle text-red-500 text-xl"></i>
                        </div>
                        <div>
                            <p class="text-sm text-gray-700">
                                Are you sure you want to delete the document requirement "<span id="delete_doc_name" class="font-medium"></span>"?
                            </p>
                            <p class="text-sm text-red-600 mt-2">This action cannot be undone.</p>
                        </div>
                    </div>
                    
                    <form id="deleteDocumentForm" method="POST" class="mt-6">
                        <input type="hidden" name="action" value="delete_document">
                        <input type="hidden" name="doc_id" id="delete_doc_id">
                        
                        <div class="flex items-center justify-end space-x-3">
                            <button type="button" onclick="closeDeleteModal()" 
                                    class="px-4 py-2 text-sm font-medium text-gray-700 bg-gray-100 rounded-md hover:bg-gray-200 transition-colors">
                                Cancel
                            </button>
                            <button type="submit"
                                    class="px-4 py-2 text-sm font-medium text-white bg-red-600 rounded-md hover:bg-red-700 transition-colors">
                                Delete Document
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <!-- Toggle Requirement Form (Hidden) -->
    <form id="toggleRequirementForm" method="POST" style="display: none;">
        <input type="hidden" name="action" value="toggle_requirement">
        <input type="hidden" name="doc_id" id="toggle_doc_id">
    </form>

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

        document.addEventListener('click', function(e) {
            const profileDropdown = document.getElementById('profileDropdown');
            if (!e.target.closest('#profileBtn') && !profileDropdown.classList.contains('hidden')) {
                profileDropdown.classList.add('hidden');
            }
        });

        // Modal functions
        function openAddModal() {
            document.getElementById('addDocumentModal').classList.remove('hidden');
            setTimeout(() => {
                document.querySelector('#addDocumentModal input[name="document_name"]').focus();
            }, 100);
        }

        function closeAddModal() {
            document.getElementById('addDocumentModal').classList.add('hidden');
            document.getElementById('addDocumentForm').reset();
        }

        function editDocument(id, name, description, isRequired) {
            document.getElementById('edit_doc_id').value = id;
            document.getElementById('edit_document_name').value = name;
            document.getElementById('edit_document_description').value = description;
            document.getElementById('edit_is_required').checked = isRequired;
            document.getElementById('editDocumentModal').classList.remove('hidden');
            setTimeout(() => {
                document.getElementById('edit_document_name').focus();
            }, 100);
        }

        function closeEditModal() {
            document.getElementById('editDocumentModal').classList.add('hidden');
        }

        function deleteDocument(id, name) {
            document.getElementById('delete_doc_id').value = id;
            document.getElementById('delete_doc_name').textContent = name;
            document.getElementById('deleteConfirmModal').classList.remove('hidden');
        }

        function closeDeleteModal() {
            document.getElementById('deleteConfirmModal').classList.add('hidden');
        }

        function toggleRequirement(docId) {
            const toggleSwitch = document.querySelector(`tr[data-doc-id="${docId}"] .toggle-switch`);
            const statusText = document.querySelector(`tr[data-doc-id="${docId}"] .status-text`);
            
            if (toggleSwitch && statusText) {
                toggleSwitch.style.opacity = '0.6';
                statusText.textContent = 'Updating...';
                
                document.getElementById('toggle_doc_id').value = docId;
                document.getElementById('toggleRequirementForm').submit();
            }
        }

        // Logout confirmation
        function confirmLogout() {
            return confirm('Are you sure you want to logout?');
        }

        // Close modals when clicking outside or pressing escape
        document.addEventListener('click', function(e) {
            const modals = document.querySelectorAll('.fixed.inset-0.z-50');
            modals.forEach(modal => {
                if (e.target === modal) {
                    modal.classList.add('hidden');
                }
            });
        });

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

                // Close any open modals
                const modals = document.querySelectorAll('.fixed.inset-0.z-50');
                modals.forEach(modal => {
                    if (!modal.classList.contains('hidden')) {
                        modal.classList.add('hidden');
                    }
                });
            }

            // Ctrl/Cmd + N to add new document
            if ((e.ctrlKey || e.metaKey) && e.key === 'n') {
                e.preventDefault();
                openAddModal();
            }
        });

        // Form submission handling
        document.addEventListener('DOMContentLoaded', function() {
            const forms = document.querySelectorAll('form');
            forms.forEach(form => {
                form.addEventListener('submit', function(e) {
                    const submitBtn = form.querySelector('button[type="submit"]');
                    if (submitBtn) {
                        const originalText = submitBtn.textContent;
                        submitBtn.disabled = true;
                        submitBtn.textContent = 'Processing...';
                        
                        // Re-enable after 5 seconds to prevent permanent disable
                        setTimeout(() => {
                            submitBtn.disabled = false;
                            submitBtn.textContent = originalText;
                        }, 5000);
                    }
                });
            });

            // Auto-hide alerts after 5 seconds
            const alerts = document.querySelectorAll('.fade-in');
            alerts.forEach(alert => {
                setTimeout(() => {
                    alert.style.opacity = '0';
                    setTimeout(() => {
                        alert.remove();
                    }, 300);
                }, 5000);
            });

            // Add character counter to textareas
            document.querySelectorAll('textarea').forEach(textarea => {
                const maxLength = 500;
                
                const counter = document.createElement('div');
                counter.className = 'text-xs text-gray-500 text-right mt-1';
                textarea.parentNode.appendChild(counter);
                
                function updateCounter() {
                    const remaining = maxLength - textarea.value.length;
                    counter.textContent = `${textarea.value.length}/${maxLength} characters`;
                    counter.className = remaining < 50 ? 'text-xs text-red-500 text-right mt-1' : 'text-xs text-gray-500 text-right mt-1';
                }
                
                textarea.addEventListener('input', updateCounter);
                updateCounter();
            });
        });

        // Enhanced toggle animation
        document.querySelectorAll('.toggle-switch').forEach(toggle => {
            toggle.addEventListener('click', function() {
                this.style.transform = 'scale(0.95)';
                setTimeout(() => {
                    this.style.transform = 'scale(1)';
                }, 150);
            });
        });

        // Add loading state for toggle switches
        function addLoadingState(docId) {
            const row = document.querySelector(`tr[data-doc-id="${docId}"]`);
            if (row) {
                row.style.opacity = '0.7';
                row.style.pointerEvents = 'none';
            }
        }

        // Console logging for debugging (remove in production)
        console.log('Document Management System loaded');
        console.log('Total documents:', <?php echo count($documents); ?>);
        <?php if ($message_type === 'success'): ?>
        console.log('Operation completed successfully');
        <?php endif; ?>
    </script>
</body>
</html>