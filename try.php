<?php
include('connect.php');
date_default_timezone_set('Asia/Manila');
session_start();

header("Cache-Control: no-cache, no-store, must-revalidate");
header("Pragma: no-cache");
header("Expires: 0");
$conn->query("SET time_zone = '+08:00'");

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

$user_id = $_SESSION['user_id'];
include_once('notification_functions.php');
$unread_count = getUnreadNotificationCount($conn, $user_id);

// Fetch student data
try {
    $stmt = $conn->prepare("SELECT first_name, middle_name, last_name, email, student_id, department, program, year_level, profile_picture, verified, face_encoding FROM students WHERE id = ?");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows > 0) {
        $student = $result->fetch_assoc();
        $full_name = trim($student['first_name'] . ' ' . ($student['middle_name'] ? $student['middle_name'] . ' ' : '') . $student['last_name']);
        $first_name = $student['first_name'];
        $student_id = $student['student_id'];
        $program = $student['program'];
        $profile_picture = $student['profile_picture'];
        $face_encoding = $student['face_encoding'];
        $initials = strtoupper(substr($student['first_name'], 0, 1) . substr($student['last_name'], 0, 1));
        
        // Check if user has face data enrolled
        $has_face_enrolled = !empty($face_encoding);
    } else {
        header("Location: login.php");
        exit();
    }
} catch (Exception $e) {
    echo "Error: " . $e->getMessage();
    exit();
}

function checkDeploymentStatus($conn, $user_id) {
    try {
        $stmt = $conn->prepare("SELECT sd.*, cs.company_name, cs.company_address, cs.work_schedule_start, cs.work_schedule_end, cs.work_days, cs.internship_start_date, cs.internship_end_date FROM student_deployments sd JOIN company_supervisors cs ON sd.supervisor_id = cs.supervisor_id WHERE sd.student_id = ? AND sd.status = 'Active'");
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows > 0) {
            $deployment = $result->fetch_assoc();
            
            // Check if current date is within the internship period
            $today = date('Y-m-d');
            $start_date = $deployment['start_date'];
            $end_date = $deployment['end_date'];
            
            // Add date validation flags
            $deployment['can_record_today'] = ($today >= $start_date && $today <= $end_date);
            $deployment['is_before_start'] = ($today < $start_date);
            $deployment['is_after_end'] = ($today > $end_date);
            $deployment['days_until_start'] = $today < $start_date ? (strtotime($start_date) - strtotime($today)) / (60 * 60 * 24) : 0;
            
            return $deployment;
        }
        
        return false;
    } catch (Exception $e) {
        error_log("Deployment check error: " . $e->getMessage());
        return false;
    }
}

$is_deployed = checkDeploymentStatus($conn, $user_id);
$can_record_attendance = $is_deployed !== false && ($is_deployed['can_record_today'] ?? false);

// Get today's attendance
$today = date('Y-m-d');
$today_attendance = null;
try {
    $today_stmt = $conn->prepare("SELECT * FROM student_attendance WHERE student_id = ? AND date = ?");
    $today_stmt->bind_param("is", $user_id, $today);
    $today_stmt->execute();
    $today_result = $today_stmt->get_result();
    
    if ($today_result->num_rows > 0) {
        $today_attendance = $today_result->fetch_assoc();
    }
    $today_stmt->close();
} catch (Exception $e) {
    // Handle error
}

// Get recent attendance history
$recent_attendance = [];
try {
    $history_stmt = $conn->prepare("SELECT * FROM student_attendance WHERE student_id = ? ORDER BY date DESC LIMIT 10");
    $history_stmt->bind_param("i", $user_id);
    $history_stmt->execute();
    $history_result = $history_stmt->get_result();
    
    while ($row = $history_result->fetch_assoc()) {
        $recent_attendance[] = $row;
    }
    $history_stmt->close();
} catch (Exception $e) {
    // Handle error
}

// Process facial recognition requests
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    
    $action = $_POST['action'] ?? '';
    
    // Photo-based face enrollment
    if ($action === 'enroll_face_photo') {
        try {
            if (!isset($_FILES['photo_upload'])) {
                throw new Exception('No photo provided');
            }
            
            $file = $_FILES['photo_upload'];
            
            // Validate file type
            $allowedTypes = ['image/jpeg', 'image/jpg', 'image/png'];
            if (!in_array($file['type'], $allowedTypes)) {
                throw new Exception('Invalid file type. Please upload JPG or PNG only.');
            }
            
            // Validate file size (max 5MB)
            if ($file['size'] > 5 * 1024 * 1024) {
                throw new Exception('File too large. Please upload photo under 5MB.');
            }
            
            $uploadDir = 'uploads/faces/';
            if (!is_dir($uploadDir)) {
                mkdir($uploadDir, 0755, true);
            }
            
            $fileName = 'face_' . $user_id . '_' . time() . '.jpg';
            $uploadPath = $uploadDir . $fileName;
            
            if (move_uploaded_file($file['tmp_name'], $uploadPath)) {
                $faceEncoding = $_POST['face_descriptor'] ?? null;
                
                if (empty($faceEncoding)) {
                    unlink($uploadPath);
                    throw new Exception('No face detected in photo. Please upload a clear front-facing photo.');
                }
                
                // Validate descriptor format
                $decodedDescriptor = json_decode($faceEncoding, true);
                if (!is_array($decodedDescriptor) || count($decodedDescriptor) !== 128) {
                    unlink($uploadPath);
                    throw new Exception('Invalid face data. Please try with a different photo.');
                }
                
                // Delete old profile picture if exists
                if (!empty($profile_picture) && file_exists($profile_picture)) {
                    unlink($profile_picture);
                }
                
                $updateStmt = $conn->prepare("UPDATE students SET face_encoding = ?, profile_picture = ?, face_enrolled_at = CURRENT_TIMESTAMP WHERE id = ?");
                $updateStmt->bind_param("ssi", $faceEncoding, $uploadPath, $user_id);
                $updateStmt->execute();
                $updateStmt->close();
                
                echo json_encode([
                    'success' => true,
                    'message' => $has_face_enrolled ? 'Face updated successfully!' : 'Face enrolled successfully! You can now use facial recognition for attendance.',
                    'is_reenrollment' => $has_face_enrolled,
                    'photo_path' => $uploadPath
                ]);
            } else {
                throw new Exception('Failed to save photo');
            }
        } catch (Exception $e) {
            echo json_encode([
                'success' => false,
                'message' => 'Face enrollment failed: ' . $e->getMessage()
            ]);
        }
        exit();
    }

    // Export attendance data
// Print attendance data
if ($action === 'print_attendance') {
    try {
        // Get all attendance records for the student
        $print_stmt = $conn->prepare("
            SELECT sa.*, cs.company_name, cs.company_address
            FROM student_attendance sa 
            LEFT JOIN student_deployments sd ON sa.deployment_id = sd.deployment_id 
            LEFT JOIN company_supervisors cs ON sd.supervisor_id = cs.supervisor_id 
            WHERE sa.student_id = ? 
            ORDER BY sa.date DESC
        ");
        $print_stmt->bind_param("i", $user_id);
        $print_stmt->execute();
        $print_result = $print_stmt->get_result();
        
        $attendance_records = [];
        while ($row = $print_result->fetch_assoc()) {
            $attendance_records[] = $row;
        }
        $print_stmt->close();
        
        // Return data as JSON for client-side processing
        echo json_encode([
            'success' => true,
            'data' => $attendance_records,
            'student_info' => [
                'name' => $full_name,
                'student_id' => $student_id,
                'program' => $program
            ]
        ]);
        exit();
        
    } catch (Exception $e) {
        echo json_encode([
            'success' => false,
            'message' => 'Print preparation failed: ' . $e->getMessage()
        ]);
        exit();
    }
}
    
    // Delete face enrollment
    if ($action === 'delete_face') {
        try {
            if (!empty($profile_picture) && file_exists($profile_picture)) {
                unlink($profile_picture);
            }
            
            $updateStmt = $conn->prepare("UPDATE students SET face_encoding = NULL, profile_picture = NULL, face_enrolled_at = NULL WHERE id = ?");
            $updateStmt->bind_param("i", $user_id);
            $updateStmt->execute();
            $updateStmt->close();
            
            echo json_encode([
                'success' => true,
                'message' => 'Face enrollment deleted successfully!'
            ]);
        } catch (Exception $e) {
            echo json_encode([
                'success' => false,
                'message' => 'Failed to delete face enrollment: ' . $e->getMessage()
            ]);
        }
        exit();
    }
    
    // Attendance recording with date validation
    if ($action === 'time_in' || $action === 'time_out') {
        // First check if deployment is active
        if (!$is_deployed) {
            echo json_encode([
                'success' => false,
                'message' => 'No active deployment found. Please contact your coordinator.'
            ]);
            exit(); 
        }
        
        // Check if current date is within internship period
        if (!$is_deployed['can_record_today']) {
            if ($is_deployed['is_before_start']) {
                $days_left = ceil($is_deployed['days_until_start']);
                $start_date_formatted = date('F j, Y', strtotime($is_deployed['start_date']));
                echo json_encode([
                    'success' => false,
                    'message' => "Your internship starts on {$start_date_formatted}. You can record attendance starting from that date. ({$days_left} days remaining)"
                ]);
            } elseif ($is_deployed['is_after_end']) {
                $end_date_formatted = date('F j, Y', strtotime($is_deployed['end_date']));
                echo json_encode([
                    'success' => false,
                    'message' => "Your internship period ended on {$end_date_formatted}. You can no longer record attendance."
                ]);
            }
            exit();
        }
        
        if (empty($face_encoding)) {
            echo json_encode([
                'success' => false,
                'message' => 'Please enroll your face first before using facial recognition attendance.'
            ]);
            exit();
        }
        
        try {
            $currentFaceDescriptor = $_POST['current_face_descriptor'] ?? null;
            
            if (empty($currentFaceDescriptor)) {
                echo json_encode([
                    'success' => false,
                    'message' => 'No face detected. Please position your face properly in the camera.'
                ]);
                exit();
            }
            
            // Parse both descriptors
            $enrolledDescriptor = json_decode($face_encoding, true);
            $currentDescriptor = json_decode($currentFaceDescriptor, true);
            
            if (!is_array($enrolledDescriptor) || !is_array($currentDescriptor)) {
                throw new Exception('Invalid face descriptor format');
            }
            
            // Calculate Euclidean distance
            $distance = calculateEuclideanDistance($enrolledDescriptor, $currentDescriptor);
            $confidence = max(0, 1 - ($distance / 1.2));
            
            // Improved thresholds
            $hour = (int)date('H');
            
            if ($hour < 8 || $hour > 18) {
                $threshold = 0.8;
                $minConfidence = 0.65;
            } else {
                $threshold = 0.75;
                $minConfidence = 0.70;
            }
            
            $verificationPassed = false;
            $verificationLevel = '';
            
            if ($distance <= 0.6 && $confidence >= 0.75) {
                $verificationPassed = true;
                $verificationLevel = 'High';
            }
            elseif ($distance <= $threshold && $confidence >= $minConfidence) {
                $verificationPassed = true;
                $verificationLevel = 'Standard';
            }
            elseif ($distance <= 0.9 && $confidence >= 0.60) {
                $verificationPassed = true;
                $verificationLevel = 'Low (Challenging Conditions)';
            }
            
            if (!$verificationPassed) {
                $errorMessage = 'Face verification failed. ';
                if ($distance <= $threshold && $confidence < $minConfidence) {
                    $errorMessage .= 'Try better lighting or move closer to camera.';
                } elseif ($distance > $threshold && $confidence >= $minConfidence) {
                    $errorMessage .= 'Photo quality issue. Consider re-enrolling with a clearer photo.';
                } else {
                    $errorMessage .= 'Please try again with better face positioning.';
                }
                
                $errorMessage .= ' (Distance: ' . number_format($distance, 3) . ', Confidence: ' . number_format($confidence * 100, 1) . '%)';
                
                echo json_encode([
                    'success' => false,
                    'message' => $errorMessage,
                    'distance' => $distance,
                    'confidence' => $confidence
                ]);
                exit();
            }
            
            if ($action === 'time_in') {
                if ($today_attendance && $today_attendance['time_in']) {
                    echo json_encode([
                        'success' => false,
                        'message' => 'You have already timed in today at ' . date('g:i A', strtotime($today_attendance['time_in']))
                    ]);
                    exit();
                }
                
                $current_time = date('H:i:s');
                
                if ($today_attendance) {
                    $update_stmt = $conn->prepare("UPDATE student_attendance SET time_in = ?, attendance_method = 'facial', facial_confidence = ?, verification_level = ?, updated_at = CURRENT_TIMESTAMP WHERE attendance_id = ?");
                    $update_stmt->bind_param("sdsi", $current_time, $confidence, $verificationLevel, $today_attendance['attendance_id']);
                } else {
                    $insert_stmt = $conn->prepare("INSERT INTO student_attendance (student_id, deployment_id, date, time_in, attendance_method, facial_confidence, verification_level, status, created_at) VALUES (?, ?, ?, ?, 'facial', ?, ?, 'Present', CURRENT_TIMESTAMP)");
                    $insert_stmt->bind_param("iissds", $user_id, $is_deployed['deployment_id'], $today, $current_time, $confidence, $verificationLevel);
                }
                
                if (isset($update_stmt)) {
                    $update_stmt->execute();
                    $update_stmt->close();
                } else {
                    $insert_stmt->execute();
                    $insert_stmt->close();
                }
                
                echo json_encode([
                    'success' => true,
                    'message' => 'Time In recorded successfully!',
                    'time' => date('g:i A', strtotime($current_time)),
                    'confidence' => $confidence,
                    'distance' => $distance,
                    'verification_level' => $verificationLevel
                ]);
                
            } elseif ($action === 'time_out') {
                if (!$today_attendance || !$today_attendance['time_in']) {
                    echo json_encode([
                        'success' => false,
                        'message' => 'You must time in first before timing out.'
                    ]);
                    exit();
                }
                
                if ($today_attendance['time_out']) {
                    echo json_encode([
                        'success' => false,
                        'message' => 'You have already timed out today at ' . date('g:i A', strtotime($today_attendance['time_out']))
                    ]);
                    exit();
                }
                
                $current_time = date('H:i:s');
                $time_in = $today_attendance['time_in'];
                
                $time_in_timestamp = strtotime($today . ' ' . $time_in);
                $time_out_timestamp = strtotime($today . ' ' . $current_time);
                $total_hours = round(($time_out_timestamp - $time_in_timestamp) / 3600, 2);
                
                $update_stmt = $conn->prepare("UPDATE student_attendance SET time_out = ?, total_hours = ?, facial_confidence = ?, verification_level = ?, updated_at = CURRENT_TIMESTAMP WHERE attendance_id = ?");
                $update_stmt->bind_param("sddsi", $current_time, $total_hours, $confidence, $verificationLevel, $today_attendance['attendance_id']);
                $update_stmt->execute();
                $update_stmt->close();
                
                echo json_encode([
                    'success' => true,
                    'message' => 'Time Out recorded successfully!',
                    'time' => date('g:i A', strtotime($current_time)),
                    'hours' => $total_hours,
                    'confidence' => $confidence,
                    'distance' => $distance,
                    'verification_level' => $verificationLevel
                ]);
            }
            
        } catch (Exception $e) {
            echo json_encode([
                'success' => false,
                'message' => 'Face verification error: ' . $e->getMessage()
            ]);
        }
    }
    exit();
}

// Function to calculate Euclidean distance between two face descriptors
function calculateEuclideanDistance($desc1, $desc2) {
    if (count($desc1) !== count($desc2)) {
        throw new Exception('Face descriptors have different dimensions');
    }
    
    $sum = 0;
    for ($i = 0; $i < count($desc1); $i++) {
        $sum += pow($desc1[$i] - $desc2[$i], 2);
    }
    
    return sqrt($sum);
}

// Add/update database columns
try {
    $conn->query("ALTER TABLE students ADD COLUMN IF NOT EXISTS face_encoding TEXT DEFAULT NULL");
    $conn->query("ALTER TABLE students ADD COLUMN IF NOT EXISTS face_enrolled_at TIMESTAMP NULL DEFAULT NULL");
    $conn->query("ALTER TABLE student_attendance ADD COLUMN IF NOT EXISTS attendance_method ENUM('manual', 'facial') DEFAULT 'facial'");
    $conn->query("ALTER TABLE student_attendance ADD COLUMN IF NOT EXISTS facial_confidence DECIMAL(5,2) DEFAULT NULL");
    $conn->query("ALTER TABLE student_attendance ADD COLUMN IF NOT EXISTS verification_level VARCHAR(50) DEFAULT NULL");
} catch (Exception $e) {
    // Columns might already exist
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>OnTheJob Tracker - Facial Recognition Attendance</title>
    <meta http-equiv="Cache-Control" content="no-cache, no-store, must-revalidate">
    <meta http-equiv="Pragma" content="no-cache">
    <meta http-equiv="Expires" content="0">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdn.jsdelivr.net/npm/@vladmandic/face-api@1.7.13/dist/face-api.min.js"></script>
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

        /* Camera specific styles */
        .camera-container {
            position: relative;
            width: 100%;
            max-width: 640px;
            margin: 0 auto;
            border-radius: 12px;
            overflow: hidden;
            background: #000;
        }

        .camera-container video {
            width: 100%;
            height: auto;
            display: block;
        }

        .face-detection-overlay {
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            pointer-events: none;
        }

        .face-rectangle {
            position: absolute;
            border: 3px solid #10b981;
            border-radius: 8px;
            box-shadow: 0 0 10px rgba(16, 185, 129, 0.5);
        }

        .face-rectangle.face-scanning {
            animation: face-scan 2s infinite;
        }

        @keyframes face-scan {
            0%, 100% { border-color: #10b981; box-shadow: 0 0 10px rgba(16, 185, 129, 0.5); }
            50% { border-color: #3b82f6; box-shadow: 0 0 15px rgba(59, 130, 246, 0.7); }
        }

        .face-status-overlay {
            position: absolute;
            top: 12px;
            left: 12px;
            right: 12px;
            background: rgba(0, 0, 0, 0.8);
            color: white;
            padding: 8px 12px;
            border-radius: 6px;
            font-size: 0.875rem;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .status-icon {
            width: 12px;
            height: 12px;
            border-radius: 50%;
            background: #ef4444;
        }

        .status-icon.detected {
            background: #10b981;
            animation: pulse 2s infinite;
        }

        @keyframes pulse {
            0%, 100% { opacity: 1; }
            50% { opacity: 0.5; }
        }

        .confidence-display {
            position: absolute;
            top: 60px;
            left: 12px;
            background: rgba(16, 185, 129, 0.9);
            color: white;
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 0.75rem;
            font-weight: 600;
        }

        .camera-prompt {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            padding: 3rem 2rem;
            text-align: center;
            background: #f9fafb;
            border: 2px dashed #d1d5db;
            border-radius: 12px;
        }

        .camera-prompt i {
            font-size: 3rem;
            color: #9ca3af;
            margin-bottom: 1rem;
        }

        /* Modal styles */
        .modal-overlay {
            backdrop-filter: blur(4px);
        }

        .photo-upload-area {
            border: 2px dashed #d1d5db;
            border-radius: 12px;
            padding: 2rem;
            text-align: center;
            cursor: pointer;
            transition: all 0.3s ease;
        }

        .photo-upload-area:hover {
            border-color: #3b82f6;
            background-color: #f0f9ff;
        }

        .photo-upload-area.drag-over {
            border-color: #3b82f6;
            background-color: #f0f9ff;
            transform: scale(1.02);
        }

        .photo-preview {
            max-width: 200px;
            max-height: 200px;
            border-radius: 8px;
            margin: 1rem auto;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
        }

        .btn-loading {
            position: relative;
        }

        .btn-loading::after {
            content: '';
            position: absolute;
            top: 50%;
            left: 50%;
            width: 16px;
            height: 16px;
            border: 2px solid transparent;
            border-top: 2px solid currentColor;
            border-radius: 50%;
            animation: spin 1s linear infinite;
            transform: translate(-50%, -50%);
        }

        @keyframes spin {
            0% { transform: translate(-50%, -50%) rotate(0deg); }
            100% { transform: translate(-50%, -50%) rotate(360deg); }
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
            <img src="reqsample/bulsu12.png" alt="BULSU Logo 2" class="w-8 h-8 mr-2">
            <img src="reqsample/bulsu1.png" alt="BULSU Logo 1" class="w-8 h-8 mr-2">
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
                <i class="fas fa-th-large mr-3"></i>
                Dashboard
            </a>
            <a href="studentAttendance.php" class="nav-item flex items-center px-3 py-2 text-sm font-medium text-white bg-bulsu-gold bg-opacity-20 border border-bulsu-gold border-opacity-30 rounded-md">
                <i class="fas fa-calendar-check mr-3 text-bulsu-gold"></i>
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

                <!-- Header Title -->
                <div class="flex-1 lg:ml-0 ml-4">
                    <h1 class="text-xl sm:text-2xl font-bold text-gray-900">Facial Recognition Attendance</h1>
                    <p class="text-sm sm:text-base text-gray-500 hidden sm:block">Secure attendance tracking with face verification</p>
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
            <div class="mb-6">
                <p class="text-gray-600">Use facial recognition for secure attendance tracking</p>
            </div>

            <!-- Work Schedule Card -->
            <?php if ($is_deployed): ?>
            <div class="bg-white rounded-lg shadow-sm border border-gray-200 mb-6">
                <div class="p-4 sm:p-6 border-b border-gray-200">
                    <div class="flex items-center">
                        <i class="fas fa-building text-blue-600 mr-3"></i>
                        <h3 class="text-lg font-medium text-gray-900"><?php echo htmlspecialchars($is_deployed['company_name']); ?></h3>
                    </div>
                </div>
                <div class="p-4 sm:p-6">
                    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
                        <div class="border-l-4 border-blue-500 pl-4">
                            <h4 class="text-sm font-medium text-gray-500 mb-1">
                                <i class="fas fa-clock mr-2"></i>Work Hours
                            </h4>
                            <p class="text-sm text-gray-900"><?php echo date('g:i A', strtotime($is_deployed['work_schedule_start'])); ?> - <?php echo date('g:i A', strtotime($is_deployed['work_schedule_end'])); ?></p>
                        </div>
                        <div class="border-l-4 border-green-500 pl-4">
                            <h4 class="text-sm font-medium text-gray-500 mb-1">
                                <i class="fas fa-calendar mr-2"></i>Work Days
                            </h4>
                            <p class="text-sm text-gray-900"><?php echo htmlspecialchars($is_deployed['work_days']); ?></p>
                        </div>
                        <div class="border-l-4 border-purple-500 pl-4">
                            <h4 class="text-sm font-medium text-gray-500 mb-1">
                                <i class="fas fa-map-marker-alt mr-2"></i>Location
                            </h4>
                            <p class="text-sm text-gray-900"><?php echo htmlspecialchars($is_deployed['company_address']); ?></p>
                        </div>
                        <div class="border-l-4 border-orange-500 pl-4">
                            <h4 class="text-sm font-medium text-gray-500 mb-1">
                                <i class="fas fa-calendar-check mr-2"></i>Duration
                            </h4>
                            <p class="text-sm text-gray-900"><?php echo date('M j, Y', strtotime($is_deployed['internship_start_date'])); ?> - <?php echo date('M j, Y', strtotime($is_deployed['internship_end_date'])); ?></p>
                        </div>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <!-- Status Cards Grid -->
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-6">
                <!-- Face Enrollment Status -->
                <div class="bg-white rounded-lg shadow-sm border border-gray-200">
                    <div class="p-4 sm:p-6 border-b border-gray-200">
                        <div class="flex items-center">
                            <i class="fas fa-user-shield text-blue-600 mr-3"></i>
                            <h3 class="text-lg font-medium text-gray-900">Face Recognition Setup</h3>
                        </div>
                    </div>
                    <div class="p-4 sm:p-6">
                        <?php if ($has_face_enrolled): ?>
                            <div class="flex items-center text-green-600 mb-3">
                                <i class="fas fa-check-circle mr-2"></i>
                                <span class="font-medium">Face recognition is enabled</span>
                            </div>
                            <p class="text-sm text-gray-600 mb-4">You can now use facial recognition for attendance</p>
                        <?php else: ?>
                            <div class="flex items-center text-yellow-600 mb-3">
                                <i class="fas fa-exclamation-circle mr-2"></i>
                                <span class="font-medium">Face recognition not set up</span>
                            </div>
                            <p class="text-sm text-gray-600 mb-4">Please enroll your face to use facial recognition attendance</p>
                        <?php endif; ?>
                        
                        <div class="flex flex-col sm:flex-row gap-2">
                            <button id="enrollFaceBtn" class="flex items-center justify-center px-4 py-2 border border-transparent text-sm font-medium rounded-md text-white bg-blue-600 hover:bg-blue-700 transition-colors">
                                <i class="fas fa-camera mr-2"></i>
                                <?php echo $has_face_enrolled ? 'Update Face' : 'Enroll Face'; ?>
                            </button>
                            <?php if ($has_face_enrolled): ?>
                            <button id="deleteFaceBtn" class="flex items-center justify-center px-4 py-2 border border-red-300 text-sm font-medium rounded-md text-red-700 bg-red-50 hover:bg-red-100 transition-colors">
                                <i class="fas fa-trash mr-2"></i>
                                Delete Face Data
                            </button>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- Today's Status -->
                <div class="bg-white rounded-lg shadow-sm border border-gray-200">
                    <div class="p-4 sm:p-6 border-b border-gray-200">
                        <div class="flex items-center">
                            <i class="fas fa-calendar-day text-blue-600 mr-3"></i>
                            <h3 class="text-lg font-medium text-gray-900">Today's Status</h3>
                        </div>
                    </div>
                    <div class="p-4 sm:p-6">
                        <div class="text-center mb-4">
                            <div class="text-3xl font-bold text-gray-900 mb-1" id="currentTime"></div>
                            <div class="text-sm text-gray-500" id="currentDate"></div>
                        </div>
                        
                        <?php if ($is_deployed): ?>
                            <?php if ($is_deployed['is_before_start']): ?>
                                <div class="flex items-center text-yellow-600 mb-2">
                                    <i class="fas fa-clock mr-2"></i>
                                    <span class="font-medium">Internship Not Started</span>
                                </div>
                                <p class="text-sm text-gray-600">
                                    Your internship starts on <?php echo date('F j, Y', strtotime($is_deployed['start_date'])); ?>
                                </p>
                                <div id="countdown-timer" class="text-xs text-blue-600 font-medium mt-2"></div>
                            <?php elseif ($is_deployed['is_after_end']): ?>
                                <div class="flex items-center text-gray-600 mb-2">
                                    <i class="fas fa-check-circle mr-2"></i>
                                    <span class="font-medium">Internship Completed</span>
                                </div>
                                <p class="text-sm text-gray-600">
                                    Your internship ended on <?php echo date('F j, Y', strtotime($is_deployed['end_date'])); ?>
                                </p>
                            <?php elseif ($today_attendance): ?>
                                <?php if ($today_attendance['time_in'] && $today_attendance['time_out']): ?>
                                    <div class="flex items-center text-green-600 mb-2">
                                        <i class="fas fa-check-circle mr-2"></i>
                                        <span class="font-medium">Day Complete</span>
                                    </div>
                                    <p class="text-sm text-gray-600">
                                        In: <?php echo date('g:i A', strtotime($today_attendance['time_in'])); ?> | 
                                        Out: <?php echo date('g:i A', strtotime($today_attendance['time_out'])); ?> | 
                                        Hours: <?php echo $today_attendance['total_hours']; ?>
                                    </p>
                                <?php elseif ($today_attendance['time_in']): ?>
                                    <div class="flex items-center text-blue-600 mb-2">
                                        <i class="fas fa-clock mr-2"></i>
                                        <span class="font-medium">Timed In</span>
                                    </div>
                                    <p class="text-sm text-gray-600">
                                        Time In: <?php echo date('g:i A', strtotime($today_attendance['time_in'])); ?>
                                    </p>
                                <?php endif; ?>
                            <?php else: ?>
                                <div class="flex items-center text-blue-600 mb-2">
                                    <i class="fas fa-clock mr-2"></i>
                                    <span class="font-medium">Ready for Time In</span>
                                </div>
                                <p class="text-sm text-gray-600">Click Time In when you arrive at work</p>
                            <?php endif; ?>
                        <?php else: ?>
                            <div class="flex items-center text-yellow-600 mb-2">
                                <i class="fas fa-exclamation-triangle mr-2"></i>
                                <span class="font-medium">No Active Deployment</span>
                            </div>
                            <p class="text-sm text-gray-600">Please contact your coordinator to set up your internship deployment</p>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Facial Recognition Attendance Card -->
            <?php if ($has_face_enrolled && $can_record_attendance): ?>
            <div class="bg-white rounded-lg shadow-sm border border-gray-200 mb-6">
                <div class="p-4 sm:p-6 border-b border-gray-200">
                    <div class="flex items-center">
                        <i class="fas fa-camera text-blue-600 mr-3"></i>
                        <h3 class="text-lg font-medium text-gray-900">Facial Recognition Attendance</h3>
                    </div>
                </div>
                <div class="p-4 sm:p-6">
                    <div class="camera-container mb-4">
                        <video id="attendanceVideo" autoplay muted class="rounded-lg"></video>
                        <canvas id="attendanceCanvas" style="display: none;"></canvas>
                        <div class="face-detection-overlay" id="faceOverlay"></div>
                        <div class="face-status-overlay" id="faceStatus">
                            <div class="status-icon" id="statusIcon"></div>
                            <span id="statusText">Initializing camera...</span>
                        </div>
                        <div class="confidence-display" id="confidenceDisplay" style="display: none;"></div>
                    </div>

                    <div id="verificationStatus" class="hidden mb-4 p-3 rounded-lg"></div>

                    <div class="flex flex-col sm:flex-row gap-3">
                        <?php if (!$today_attendance || !$today_attendance['time_in']): ?>
                        <button id="timeInBtn" class="flex items-center justify-center px-6 py-3 border border-transparent text-sm font-medium rounded-md text-white bg-green-600 hover:bg-green-700 disabled:bg-gray-300 disabled:cursor-not-allowed transition-colors" disabled>
                            <i class="fas fa-sign-in-alt mr-2"></i>
                            Time In
                        </button>
                        <?php endif; ?>
                        
                        <?php if ($today_attendance && $today_attendance['time_in'] && !$today_attendance['time_out']): ?>
                        <button id="timeOutBtn" class="flex items-center justify-center px-6 py-3 border border-transparent text-sm font-medium rounded-md text-white bg-red-600 hover:bg-red-700 disabled:bg-gray-300 disabled:cursor-not-allowed transition-colors" disabled>
                            <i class="fas fa-sign-out-alt mr-2"></i>
                            Time Out
                        </button>
                        <?php endif; ?>
                        
                        <button id="refreshCameraBtn" class="flex items-center justify-center px-6 py-3 border border-gray-300 text-sm font-medium rounded-md text-gray-700 bg-white hover:bg-gray-50 transition-colors">
                            <i class="fas fa-sync-alt mr-2"></i>
                            Refresh Camera
                        </button>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <!-- Attendance History Card -->
            <div class="bg-white rounded-lg shadow-sm border border-gray-200">
    <div class="p-4 sm:p-6 border-b border-gray-200">
        <div class="flex items-center justify-between">
            <div class="flex items-center">
                <i class="fas fa-history text-blue-600 mr-3"></i>
                <h3 class="text-lg font-medium text-gray-900">Recent Attendance History</h3>
            </div>
            <div class="flex gap-2">
               <button id="printAttendanceBtn" class="flex items-center px-4 py-2 border border-blue-300 text-sm font-medium rounded-md text-blue-700 bg-blue-50 hover:bg-blue-100 transition-colors">
    <i class="fas fa-print mr-2"></i>
    Print Report
</button>
            </div>
        </div>
    </div>

    <div class="p-4 sm:p-6">
        <?php if (empty($recent_attendance)): ?>
            <div class="text-center py-8">
                <i class="fas fa-clipboard-list text-gray-400 text-4xl mb-4"></i>
                <h4 class="text-lg font-medium text-gray-900 mb-2">No attendance records yet</h4>
                <p class="text-gray-600">Your attendance history will appear here once you start recording</p>
            </div>
        <?php else: ?>
            <div class="space-y-4">
                <?php foreach ($recent_attendance as $record): ?>
                <div class="border border-gray-200 rounded-lg p-4">
                    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between mb-3">
                        <div class="flex items-center mb-2 sm:mb-0">
                            <div class="w-3 h-3 rounded-full mr-3 <?php echo ($record['time_in'] && $record['time_out']) ? 'bg-green-500' : ($record['time_in'] ? 'bg-yellow-500' : 'bg-red-500'); ?>"></div>
                            <div>
                                <div class="font-medium text-gray-900"><?php echo date('M j, Y', strtotime($record['date'])); ?></div>
                                <div class="text-sm text-gray-500"><?php echo date('l', strtotime($record['date'])); ?></div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                        <div class="flex items-center">
                            <i class="fas fa-sign-in-alt text-green-600 mr-2"></i>
                            <div>
                                <div class="text-xs text-gray-500">Time In</div>
                                <div class="text-sm font-medium text-gray-900">
                                    <?php echo $record['time_in'] ? date('g:i A', strtotime($record['time_in'])) : '--'; ?>
                                </div>
                            </div>
                        </div>
                        
                        <div class="flex items-center">
                            <i class="fas fa-sign-out-alt text-red-600 mr-2"></i>
                            <div>
                                <div class="text-xs text-gray-500">Time Out</div>
                                <div class="text-sm font-medium text-gray-900">
                                    <?php echo $record['time_out'] ? date('g:i A', strtotime($record['time_out'])) : '--'; ?>
                                </div>
                            </div>
                        </div>
                        
                        <div class="flex items-center">
                            <i class="fas fa-clock text-blue-600 mr-2"></i>
                            <div>
                                <div class="text-xs text-gray-500">Hours</div>
                                <div class="text-sm font-medium text-gray-900">
                                    <?php echo $record['total_hours'] ? number_format($record['total_hours'], 1) . 'h' : '--'; ?>
                                </div>
                            </div>
                        </div>
                    </div>

                    <?php if ($record['attendance_method'] === 'facial' && $record['facial_confidence']): ?>
                    <div class="mt-3 flex items-center text-xs text-gray-500">
                        <i class="fas fa-user-check mr-2"></i>
                        Facial Recognition (<?php echo round($record['facial_confidence'] * 100); ?>% confidence)
                    </div>
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

    <!-- Face Enrollment Modal -->
    <div id="faceEnrollmentModal" class="fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full z-50 modal-overlay hidden">
        <div class="relative top-20 mx-auto p-4 w-full max-w-lg">
            <div class="relative bg-white rounded-lg shadow-lg">
                <div class="flex items-center justify-between p-4 sm:p-6 border-b border-gray-200">
                    <h3 class="text-lg font-medium text-gray-900">
                        <i class="fas fa-user-plus mr-2"></i>Face Enrollment
                    </h3>
                    <button id="closeFaceEnrollmentModal" class="text-gray-400 hover:text-gray-600">
                        <i class="fas fa-times text-xl"></i>
                    </button>
                </div>
                
                <div class="p-4 sm:p-6">
                    <div class="mb-4">
                        <p class="text-gray-600">Upload a clear front-facing photo to set up facial recognition for attendance</p>
                    </div>

                    <div class="mb-4 p-4 bg-blue-50 border border-blue-200 rounded-lg">
                        <h4 class="text-sm font-medium text-blue-800 mb-2">
                            <i class="fas fa-info-circle mr-2"></i>Photo Requirements:
                        </h4>
                        <ul class="text-sm text-blue-700 space-y-1">
                            <li>• Clear, well-lit front-facing photo</li>
                            <li>• Face should be clearly visible and centered</li>
                            <li>• No sunglasses or face coverings</li>
                            <li>• Neutral expression recommended</li>
                            <li>• File size under 5MB (JPG/PNG only)</li>
                        </ul>
                    </div>

                    <div class="photo-upload-area mb-4" id="photoUploadArea">
                        <i class="fas fa-cloud-upload-alt text-4xl text-gray-400 mb-3"></i>
                        <h4 class="font-medium text-gray-700 mb-1">Click to upload or drag & drop your photo</h4>
                        <p class="text-sm text-gray-500">JPG or PNG files only</p>
                        <input type="file" id="photoUpload" accept="image/*" style="display: none;">
                    </div>

                    <img id="photoPreview" class="photo-preview mx-auto" style="display: none;">
                    
                    <div id="enrollmentMessage" class="hidden mt-4 p-3 rounded-lg"></div>
                    <div id="enrollmentSpinner" class="hidden mt-4 text-center">
                        <i class="fas fa-spinner fa-spin text-blue-600 text-2xl"></i>
                        <p class="text-sm text-gray-600 mt-2">Processing photo...</p>
                    </div>
                </div>

                <div class="px-4 sm:px-6 py-4 bg-gray-50 rounded-b-lg flex flex-col sm:flex-row gap-3 sm:justify-end">
                    <button id="processPhotoBtn" class="flex items-center justify-center px-4 py-2 border border-transparent text-sm font-medium rounded-md text-white bg-green-600 hover:bg-green-700 transition-colors" style="display: none;">
                        <i class="fas fa-check mr-2"></i>
                        Process Photo
                    </button>
                    <button id="cancelEnrollmentBtn" class="flex items-center justify-center px-4 py-2 border border-gray-300 text-sm font-medium rounded-md text-gray-700 bg-white hover:bg-gray-50 transition-colors">
                        <i class="fas fa-times mr-2"></i>
                        Cancel
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Delete Confirmation Modal -->
    <div id="deleteModal" class="fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full z-50 modal-overlay hidden">
        <div class="relative top-20 mx-auto p-4 w-full max-w-md">
            <div class="relative bg-white rounded-lg shadow-lg">
                <div class="p-6 text-center">
                    <i class="fas fa-exclamation-triangle text-yellow-500 text-4xl mb-4"></i>
                    <h3 class="text-lg font-medium text-gray-900 mb-3">Confirm Deletion</h3>
                    <p class="text-gray-600 mb-6">Are you sure you want to delete your face enrollment? You will need to re-enroll to use facial recognition attendance.</p>
                    <div class="flex flex-col sm:flex-row gap-3 justify-center">
                        <button id="confirmDeleteBtn" class="flex items-center justify-center px-4 py-2 border border-transparent text-sm font-medium rounded-md text-white bg-red-600 hover:bg-red-700 transition-colors">
                            <i class="fas fa-trash mr-2"></i>
                            Delete
                        </button>
                        <button id="cancelDeleteBtn" class="flex items-center justify-center px-4 py-2 border border-gray-300 text-sm font-medium rounded-md text-gray-700 bg-white hover:bg-gray-50 transition-colors">
                            <i class="fas fa-times mr-2"></i>
                            Cancel
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
        // Global variables
        let video, canvas, faceDetectionInterval;
        let isFaceApiLoaded = false;
        let currentFaceDescriptor = null;
        let isProcessingAttendance = false;
        let sidebar = document.getElementById('sidebar');
        let sidebarOverlay = document.getElementById('sidebarOverlay');

        // Initialize when page loads
        document.addEventListener('DOMContentLoaded', function() {
            updateCurrentTime();
            setInterval(updateCurrentTime, 1000);
            
            // Load Face API models
            loadFaceApiModels();
            
            // Setup event listeners
            setupEventListeners();
            setupModalEventListeners();
        });

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

        // Close dropdown when clicking outside
        document.addEventListener('click', function(e) {
            const profileDropdown = document.getElementById('profileDropdown');
            if (!e.target.closest('#profileBtn') && !profileDropdown.classList.contains('hidden')) {
                profileDropdown.classList.add('hidden');
            }
        });
        // Update current time display
function updateCurrentTime() {
    const now = new Date();
    const timeString = now.toLocaleTimeString('en-US', { 
        hour: 'numeric', 
        minute: '2-digit',
        hour12: true 
    });
    const dateString = now.toLocaleDateString('en-US', { 
        weekday: 'long',
        year: 'numeric',
        month: 'long',
        day: 'numeric'
    });
    
    const timeElement = document.getElementById('currentTime');
    const dateElement = document.getElementById('currentDate');
    
    if (timeElement) timeElement.textContent = timeString;
    if (dateElement) dateElement.textContent = dateString;
    
    // Update countdown timer if exists
    updateCountdownTimer();
}

// Update countdown timer for internship start
function updateCountdownTimer() {
    const countdownElement = document.getElementById('countdown-timer');
    if (!countdownElement) return;
    
    const startDate = new Date(countdownElement.dataset.startDate);
    const now = new Date();
    const diff = startDate - now;
    
    if (diff > 0) {
        const days = Math.floor(diff / (1000 * 60 * 60 * 24));
        const hours = Math.floor((diff % (1000 * 60 * 60 * 24)) / (1000 * 60 * 60));
        const minutes = Math.floor((diff % (1000 * 60 * 60)) / (1000 * 60));
        
        countdownElement.textContent = `Starts in: ${days}d ${hours}h ${minutes}m`;
    } else {
        countdownElement.textContent = 'Internship has started!';
    }
}

// Load Face API models
async function loadFaceApiModels() {
    try {
        const faceStatus = document.getElementById('faceStatus');
        const statusText = document.getElementById('statusText');
        
        if (statusText) statusText.textContent = 'Loading face recognition models...';
        
        // Use CDN URLs for the models
        await Promise.all([
            faceapi.nets.tinyFaceDetector.loadFromUri('https://cdn.jsdelivr.net/npm/@vladmandic/face-api@1.7.13/model'),
            faceapi.nets.faceLandmark68Net.loadFromUri('https://cdn.jsdelivr.net/npm/@vladmandic/face-api@1.7.13/model'),
            faceapi.nets.faceRecognitionNet.loadFromUri('https://cdn.jsdelivr.net/npm/@vladmandic/face-api@1.7.13/model')
        ]);
        
        isFaceApiLoaded = true;
        console.log('Face API models loaded successfully');
        
        if (statusText) statusText.textContent = 'Face recognition ready';
        
        // Initialize camera after models are loaded
        initializeCamera();
        
    } catch (error) {
        console.error('Error loading Face API models:', error);
        const statusText = document.getElementById('statusText');
        if (statusText) statusText.textContent = 'Failed to load face recognition';
    }
}

// Initialize camera
async function initializeCamera() {
    try {
        video = document.getElementById('attendanceVideo');
        canvas = document.getElementById('attendanceCanvas');
        
        if (!video || !canvas) return;
        
        const stream = await navigator.mediaDevices.getUserMedia({
            video: { 
                width: 640, 
                height: 480,
                facingMode: 'user'
            }
        });
        
        video.srcObject = stream;
        
        video.addEventListener('loadedmetadata', () => {
            canvas.width = video.videoWidth;
            canvas.height = video.videoHeight;
            startFaceDetection();
        });
        
    } catch (error) {
        console.error('Camera initialization error:', error);
        showCameraError(error.message);
    }
}

// Start face detection
function startFaceDetection() {
    if (!isFaceApiLoaded || !video) return;
    
    const statusText = document.getElementById('statusText');
    const statusIcon = document.getElementById('statusIcon');
    
    faceDetectionInterval = setInterval(async () => {
        try {
            const detections = await faceapi
                .detectAllFaces(video, new faceapi.TinyFaceDetectorOptions())
                .withFaceLandmarks()
                .withFaceDescriptors();
            
            const faceOverlay = document.getElementById('faceOverlay');
            if (faceOverlay) {
                faceOverlay.innerHTML = '';
            }
            
            if (detections.length > 0) {
                const detection = detections[0];
                currentFaceDescriptor = Array.from(detection.descriptor);
                
                // Draw face rectangle
                if (faceOverlay) {
                    const box = detection.detection.box;
                    const rect = document.createElement('div');
                    rect.className = 'face-rectangle face-scanning';
                    rect.style.left = `${box.x}px`;
                    rect.style.top = `${box.y}px`;
                    rect.style.width = `${box.width}px`;
                    rect.style.height = `${box.height}px`;
                    faceOverlay.appendChild(rect);
                }
                
                // Update status
                if (statusText) statusText.textContent = 'Face detected - Ready for attendance';
                if (statusIcon) {
                    statusIcon.classList.add('detected');
                }
                
                // Enable attendance buttons
                enableAttendanceButtons();
                
            } else {
                currentFaceDescriptor = null;
                
                if (statusText) statusText.textContent = 'Please position your face in the camera';
                if (statusIcon) {
                    statusIcon.classList.remove('detected');
                }
                
                // Disable attendance buttons
                disableAttendanceButtons();
            }
            
        } catch (error) {
            console.error('Face detection error:', error);
        }
    }, 100);
}

// Enable attendance buttons
function enableAttendanceButtons() {
    const timeInBtn = document.getElementById('timeInBtn');
    const timeOutBtn = document.getElementById('timeOutBtn');
    
    if (timeInBtn && !isProcessingAttendance) {
        timeInBtn.disabled = false;
    }
    if (timeOutBtn && !isProcessingAttendance) {
        timeOutBtn.disabled = false;
    }
}

// Disable attendance buttons
function disableAttendanceButtons() {
    const timeInBtn = document.getElementById('timeInBtn');
    const timeOutBtn = document.getElementById('timeOutBtn');
    
    if (timeInBtn) timeInBtn.disabled = true;
    if (timeOutBtn) timeOutBtn.disabled = true;
}

// Show camera error
function showCameraError(message) {
    const video = document.getElementById('attendanceVideo');
    const statusText = document.getElementById('statusText');
    
    if (video) {
        video.style.display = 'none';
    }
    
    if (statusText) {
        statusText.textContent = `Camera error: ${message}`;
    }
    
    // Show camera permission prompt
    const cameraContainer = video?.parentElement;
    if (cameraContainer) {
        const errorDiv = document.createElement('div');
        errorDiv.className = 'camera-prompt';
        errorDiv.innerHTML = `
            <i class="fas fa-video-slash"></i>
            <h3 class="text-lg font-medium text-gray-900 mb-2">Camera Access Required</h3>
            <p class="text-gray-600 mb-4">Please allow camera access to use facial recognition attendance</p>
            <button onclick="initializeCamera()" class="px-4 py-2 bg-blue-600 text-white rounded-md hover:bg-blue-700">
                <i class="fas fa-refresh mr-2"></i>Retry
            </button>
        `;
        cameraContainer.appendChild(errorDiv);
    }
}

// Setup event listeners
// Setup event listeners
function setupEventListeners() {
    // Time In button
    const timeInBtn = document.getElementById('timeInBtn');
    if (timeInBtn) {
        timeInBtn.addEventListener('click', () => processAttendance('time_in'));
    }
    
    // Time Out button
    const timeOutBtn = document.getElementById('timeOutBtn');
    if (timeOutBtn) {
        timeOutBtn.addEventListener('click', () => processAttendance('time_out'));
    }
    
    // Refresh Camera button
    const refreshCameraBtn = document.getElementById('refreshCameraBtn');
    if (refreshCameraBtn) {
        refreshCameraBtn.addEventListener('click', refreshCamera);
    }
    
    // Face enrollment button
    const enrollFaceBtn = document.getElementById('enrollFaceBtn');
    if (enrollFaceBtn) {
        enrollFaceBtn.addEventListener('click', () => {
            document.getElementById('faceEnrollmentModal').classList.remove('hidden');
        });
    }
    
    // Delete face button
    const deleteFaceBtn = document.getElementById('deleteFaceBtn');
    if (deleteFaceBtn) {
        deleteFaceBtn.addEventListener('click', () => {
            document.getElementById('deleteModal').classList.remove('hidden');
        });
    }
    
    // Export attendance button
    const exportAttendanceBtn = document.getElementById('exportAttendanceBtn');
    if (exportAttendanceBtn) {
        exportAttendanceBtn.addEventListener('click', exportAttendanceData);
    }
}

// Export attendance data
function exportAttendanceData() {
    try {
        // Create a form to submit export request
        const form = document.createElement('form');
        form.method = 'POST';
        form.action = window.location.href;
        form.style.display = 'none';
        
        const actionInput = document.createElement('input');
        actionInput.type = 'hidden';
        actionInput.name = 'action';
        actionInput.value = 'export_attendance';
        
        form.appendChild(actionInput);
        document.body.appendChild(form);
        
        // Submit form to trigger download
        form.submit();
        
        // Clean up
        document.body.removeChild(form);
        
    } catch (error) {
        console.error('Export error:', error);
        alert('Failed to export data. Please try again.');
    }
}

// Process attendance (Time In/Out)
async function processAttendance(action) {
    if (isProcessingAttendance || !currentFaceDescriptor) return;
    
    isProcessingAttendance = true;
    const button = document.getElementById(action === 'time_in' ? 'timeInBtn' : 'timeOutBtn');
    const verificationStatus = document.getElementById('verificationStatus');
    
    try {
        // Show loading state
        if (button) {
            button.disabled = true;
            button.classList.add('btn-loading');
            const originalText = button.innerHTML;
            button.innerHTML = '<i class="fas fa-spinner fa-spin mr-2"></i>Processing...';
        }
        
        // Show verification status
        if (verificationStatus) {
            verificationStatus.className = 'mb-4 p-3 rounded-lg bg-blue-50 border border-blue-200';
            verificationStatus.innerHTML = `
                <div class="flex items-center">
                    <i class="fas fa-spinner fa-spin text-blue-600 mr-2"></i>
                    <span class="text-blue-700">Verifying face...</span>
                </div>
            `;
            verificationStatus.classList.remove('hidden');
        }
        
        const formData = new FormData();
        formData.append('action', action);
        formData.append('current_face_descriptor', JSON.stringify(currentFaceDescriptor));
        
        const response = await fetch(window.location.href, {
            method: 'POST',
            body: formData
        });
        
        const result = await response.json();
        
        if (result.success) {
            showVerificationSuccess(result, action);
            setTimeout(() => {
                window.location.reload();
            }, 2000);
        } else {
            showVerificationError(result.message);
        }
        
    } catch (error) {
        console.error('Attendance processing error:', error);
        showVerificationError('Network error. Please try again.');
    } finally {
        isProcessingAttendance = false;
        
        // Restore button state
        if (button) {
            button.classList.remove('btn-loading');
            const actionText = action === 'time_in' ? 'Time In' : 'Time Out';
            const icon = action === 'time_in' ? 'fa-sign-in-alt' : 'fa-sign-out-alt';
            button.innerHTML = `<i class="fas ${icon} mr-2"></i>${actionText}`;
        }
    }
}

// Show verification success
function showVerificationSuccess(result, action) {
    const verificationStatus = document.getElementById('verificationStatus');
    if (!verificationStatus) return;
    
    const actionText = action === 'time_in' ? 'Time In' : 'Time Out';
    const icon = action === 'time_in' ? 'fa-sign-in-alt' : 'fa-sign-out-alt';
    const color = action === 'time_in' ? 'green' : 'red';
    
    verificationStatus.className = `mb-4 p-3 rounded-lg bg-${color}-50 border border-${color}-200`;
    verificationStatus.innerHTML = `
        <div class="flex items-center mb-2">
            <i class="fas fa-check-circle text-${color}-600 mr-2"></i>
            <span class="font-medium text-${color}-700">${result.message}</span>
        </div>
        <div class="text-sm text-${color}-600">
            <div class="flex items-center mb-1">
                <i class="fas ${icon} mr-2"></i>
                <span>Time: ${result.time}</span>
            </div>
            ${result.hours ? `<div class="flex items-center mb-1"><i class="fas fa-clock mr-2"></i><span>Total Hours: ${result.hours}</span></div>` : ''}
            <div class="flex items-center">
                <i class="fas fa-shield-alt mr-2"></i>
                <span>Confidence: ${Math.round(result.confidence * 100)}% (${result.verification_level})</span>
            </div>
        </div>
    `;
    verificationStatus.classList.remove('hidden');
}

// Show verification error
function showVerificationError(message) {
    const verificationStatus = document.getElementById('verificationStatus');
    if (!verificationStatus) return;
    
    verificationStatus.className = 'mb-4 p-3 rounded-lg bg-red-50 border border-red-200';
    verificationStatus.innerHTML = `
        <div class="flex items-center">
            <i class="fas fa-exclamation-circle text-red-600 mr-2"></i>
            <span class="text-red-700">${message}</span>
        </div>
    `;
    verificationStatus.classList.remove('hidden');
}

// Refresh camera
async function refreshCamera() {
    try {
        // Stop current face detection
        if (faceDetectionInterval) {
            clearInterval(faceDetectionInterval);
        }
        
        // Stop video stream
        if (video && video.srcObject) {
            const tracks = video.srcObject.getTracks();
            tracks.forEach(track => track.stop());
            video.srcObject = null;
        }
        
        // Clear face overlay
        const faceOverlay = document.getElementById('faceOverlay');
        if (faceOverlay) {
            faceOverlay.innerHTML = '';
        }
        
        // Reset status
        const statusText = document.getElementById('statusText');
        const statusIcon = document.getElementById('statusIcon');
        
        if (statusText) statusText.textContent = 'Restarting camera...';
        if (statusIcon) statusIcon.classList.remove('detected');
        
        // Reinitialize camera
        await initializeCamera();
        
    } catch (error) {
        console.error('Camera refresh error:', error);
        showCameraError(error.message);
    }
}

// Setup modal event listeners
function setupModalEventListeners() {
    // Face enrollment modal
    const faceEnrollmentModal = document.getElementById('faceEnrollmentModal');
    const closeFaceEnrollmentModal = document.getElementById('closeFaceEnrollmentModal');
    const cancelEnrollmentBtn = document.getElementById('cancelEnrollmentBtn');
    const photoUploadArea = document.getElementById('photoUploadArea');
    const photoUpload = document.getElementById('photoUpload');
    const processPhotoBtn = document.getElementById('processPhotoBtn');
    
    // Close modal handlers
    if (closeFaceEnrollmentModal) {
        closeFaceEnrollmentModal.addEventListener('click', () => {
            faceEnrollmentModal.classList.add('hidden');
            resetEnrollmentModal();
        });
    }
    
    if (cancelEnrollmentBtn) {
        cancelEnrollmentBtn.addEventListener('click', () => {
            faceEnrollmentModal.classList.add('hidden');
            resetEnrollmentModal();
        });
    }
    
    // Photo upload handlers
    if (photoUploadArea) {
        photoUploadArea.addEventListener('click', () => {
            photoUpload.click();
        });
        
        photoUploadArea.addEventListener('dragover', (e) => {
            e.preventDefault();
            photoUploadArea.classList.add('drag-over');
        });
        
        photoUploadArea.addEventListener('dragleave', () => {
            photoUploadArea.classList.remove('drag-over');
        });
        
        photoUploadArea.addEventListener('drop', (e) => {
            e.preventDefault();
            photoUploadArea.classList.remove('drag-over');
            
            const files = e.dataTransfer.files;
            if (files.length > 0) {
                handlePhotoUpload(files[0]);
            }
        });
    }
    
    if (photoUpload) {
        photoUpload.addEventListener('change', (e) => {
            if (e.target.files.length > 0) {
                handlePhotoUpload(e.target.files[0]);
            }
        });
    }
    
    if (processPhotoBtn) {
        processPhotoBtn.addEventListener('click', processPhotoEnrollment);
    }
    
    // Delete confirmation modal
    const deleteModal = document.getElementById('deleteModal');
    const confirmDeleteBtn = document.getElementById('confirmDeleteBtn');
    const cancelDeleteBtn = document.getElementById('cancelDeleteBtn');
    
    if (confirmDeleteBtn) {
        confirmDeleteBtn.addEventListener('click', processFaceDeletion);
    }
    
    if (cancelDeleteBtn) {
        cancelDeleteBtn.addEventListener('click', () => {
            deleteModal.classList.add('hidden');
        });
    }
}

// Handle photo upload
function handlePhotoUpload(file) {
    // Validate file type
    if (!file.type.match(/image\/(jpeg|jpg|png)/)) {
        showEnrollmentMessage('Invalid file type. Please upload JPG or PNG only.', 'error');
        return;
    }
    
    // Validate file size (5MB)
    if (file.size > 5 * 1024 * 1024) {
        showEnrollmentMessage('File too large. Please upload photo under 5MB.', 'error');
        return;
    }
    
    const reader = new FileReader();
    reader.onload = (e) => {
        const photoPreview = document.getElementById('photoPreview');
        const processPhotoBtn = document.getElementById('processPhotoBtn');
        
        if (photoPreview) {
            photoPreview.src = e.target.result;
            photoPreview.style.display = 'block';
        }
        
        if (processPhotoBtn) {
            processPhotoBtn.style.display = 'block';
        }
        
        // Store file for processing
        photoPreview.dataset.file = JSON.stringify({
            name: file.name,
            type: file.type,
            data: e.target.result
        });
    };
    
    reader.readAsDataURL(file);
}

// Process photo enrollment
async function processPhotoEnrollment() {
    const photoPreview = document.getElementById('photoPreview');
    const enrollmentSpinner = document.getElementById('enrollmentSpinner');
    const processPhotoBtn = document.getElementById('processPhotoBtn');
    
    if (!photoPreview.dataset.file) {
        showEnrollmentMessage('No photo selected', 'error');
        return;
    }
    
    try {
        // Show loading
        if (enrollmentSpinner) enrollmentSpinner.classList.remove('hidden');
        if (processPhotoBtn) processPhotoBtn.disabled = true;
        
        // Create image element for face detection
        const img = new Image();
        img.src = photoPreview.src;
        
        img.onload = async () => {
            try {
                // Detect face in uploaded photo
                const detections = await faceapi
                    .detectAllFaces(img, new faceapi.TinyFaceDetectorOptions())
                    .withFaceLandmarks()
                    .withFaceDescriptors();
                
                if (detections.length === 0) {
                    throw new Error('No face detected in photo. Please upload a clear front-facing photo.');
                }
                
                if (detections.length > 1) {
                    throw new Error('Multiple faces detected. Please upload a photo with only one face.');
                }
                
                const faceDescriptor = Array.from(detections[0].descriptor);
                
                // Submit enrollment
                const fileData = JSON.parse(photoPreview.dataset.file);
                
                // Convert base64 to blob
                const response = await fetch(fileData.data);
                const blob = await response.blob();
                
                const formData = new FormData();
                formData.append('action', 'enroll_face_photo');
                formData.append('photo_upload', blob, fileData.name);
                formData.append('face_descriptor', JSON.stringify(faceDescriptor));
                
                const enrollmentResponse = await fetch(window.location.href, {
                    method: 'POST',
                    body: formData
                });
                
                const result = await enrollmentResponse.json();
                
                if (result.success) {
                    showEnrollmentMessage(result.message, 'success');
                    setTimeout(() => {
                        window.location.reload();
                    }, 2000);
                } else {
                    throw new Error(result.message);
                }
                
            } catch (error) {
                console.error('Face enrollment error:', error);
                showEnrollmentMessage(error.message, 'error');
            } finally {
                if (enrollmentSpinner) enrollmentSpinner.classList.add('hidden');
                if (processPhotoBtn) processPhotoBtn.disabled = false;
            }
        };
        
    } catch (error) {
        console.error('Photo processing error:', error);
        showEnrollmentMessage('Failed to process photo: ' + error.message, 'error');
        if (enrollmentSpinner) enrollmentSpinner.classList.add('hidden');
        if (processPhotoBtn) processPhotoBtn.disabled = false;
    }
}

// Show enrollment message
function showEnrollmentMessage(message, type) {
    const enrollmentMessage = document.getElementById('enrollmentMessage');
    if (!enrollmentMessage) return;
    
    const bgColor = type === 'success' ? 'bg-green-50 border-green-200' : 'bg-red-50 border-red-200';
    const textColor = type === 'success' ? 'text-green-700' : 'text-red-700';
    const icon = type === 'success' ? 'fa-check-circle' : 'fa-exclamation-circle';
    
    enrollmentMessage.className = `mt-4 p-3 rounded-lg ${bgColor}`;
    enrollmentMessage.innerHTML = `
        <div class="flex items-center">
            <i class="fas ${icon} ${textColor} mr-2"></i>
            <span class="${textColor}">${message}</span>
        </div>
    `;
    enrollmentMessage.classList.remove('hidden');
}

// Reset enrollment modal
function resetEnrollmentModal() {
    const photoPreview = document.getElementById('photoPreview');
    const processPhotoBtn = document.getElementById('processPhotoBtn');
    const enrollmentMessage = document.getElementById('enrollmentMessage');
    const enrollmentSpinner = document.getElementById('enrollmentSpinner');
    
    if (photoPreview) {
        photoPreview.style.display = 'none';
        photoPreview.src = '';
        photoPreview.dataset.file = '';
    }
    
    if (processPhotoBtn) {
        processPhotoBtn.style.display = 'none';
        processPhotoBtn.disabled = false;
    }
    
    if (enrollmentMessage) {
        enrollmentMessage.classList.add('hidden');
    }
    
    if (enrollmentSpinner) {
        enrollmentSpinner.classList.add('hidden');
    }
}

// Process face deletion
async function processFaceDeletion() {
    const confirmDeleteBtn = document.getElementById('confirmDeleteBtn');
    const deleteModal = document.getElementById('deleteModal');
    
    try {
        // Show loading state
        if (confirmDeleteBtn) {
            confirmDeleteBtn.disabled = true;
            confirmDeleteBtn.innerHTML = '<i class="fas fa-spinner fa-spin mr-2"></i>Deleting...';
        }
        
        const formData = new FormData();
        formData.append('action', 'delete_face');
        
        const response = await fetch(window.location.href, {
            method: 'POST',
            body: formData
        });
        
        const result = await response.json();
        
        if (result.success) {
            window.location.reload();
        } else {
            alert('Error: ' + result.message);
        }
        
    } catch (error) {
        console.error('Face deletion error:', error);
        alert('Network error. Please try again.');
    } finally {
        if (confirmDeleteBtn) {
            confirmDeleteBtn.disabled = false;
            confirmDeleteBtn.innerHTML = '<i class="fas fa-trash mr-2"></i>Delete';
        }
        
        if (deleteModal) {
            deleteModal.classList.add('hidden');
        }
    }
}

// Close modals when clicking outside
window.addEventListener('click', (e) => {
    const faceEnrollmentModal = document.getElementById('faceEnrollmentModal');
    const deleteModal = document.getElementById('deleteModal');
    
    if (e.target === faceEnrollmentModal) {
        faceEnrollmentModal.classList.add('hidden');
        resetEnrollmentModal();
    }
    
    if (e.target === deleteModal) {
        deleteModal.classList.add('hidden');
    }
});

// Cleanup on page unload
window.addEventListener('beforeunload', () => {
    if (faceDetectionInterval) {
        clearInterval(faceDetectionInterval);
    }
    
    if (video && video.srcObject) {
        const tracks = video.srcObject.getTracks();
        tracks.forEach(track => track.stop());
    }
});
</script>
</body>
</html>

