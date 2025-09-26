<?php
include('connect.php');
session_start();

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

$user_id = $_SESSION['user_id'];

// Fetch student data (same as in main attendance page)
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
        $student_id = $student['student_id'];
        $program = $student['program'];
        $profile_picture = $student['profile_picture'];
        
        // Create initials for avatar
        $initials = strtoupper(substr($student['first_name'], 0, 1) . substr($student['last_name'], 0, 1));
    } else {
        header("Location: login.php");
        exit();
    }
} catch (Exception $e) {
    echo "Error: " . $e->getMessage();
    exit();
}

// Pagination setup
$records_per_page = 20;
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$offset = ($page - 1) * $records_per_page;

// Get total records count
$count_stmt = $conn->prepare("SELECT COUNT(*) as total FROM student_attendance WHERE student_id = ?");
$count_stmt->bind_param("i", $user_id);
$count_stmt->execute();
$count_result = $count_stmt->get_result();
$total_records = $count_result->fetch_assoc()['total'];
$total_pages = ceil($total_records / $records_per_page);

// Get attendance history with pagination
$attendance_history = [];
try {
    $history_stmt = $conn->prepare("
        SELECT date, time_in, time_out, total_hours, status, notes 
        FROM student_attendance 
        WHERE student_id = ? 
        ORDER BY date DESC 
        LIMIT ? OFFSET ?
    ");
    $history_stmt->bind_param("iii", $user_id, $records_per_page, $offset);
    $history_stmt->execute();
    $history_result = $history_stmt->get_result();
    
    while ($row = $history_result->fetch_assoc()) {
        $attendance_history[] = $row;
    }
    $history_stmt->close();
} catch (Exception $e) {
    // Handle error
}

// Calculate summary statistics
$summary_stats = [
    'total_hours' => 0,
    'total_days' => 0,
    'present_days' => 0,
    'absent_days' => 0,
    'average_hours' => 0
];

try {
    $summary_stmt = $conn->prepare("
        SELECT 
            COUNT(*) as total_days,
            SUM(CASE WHEN time_in IS NOT NULL THEN 1 ELSE 0 END) as present_days,
            SUM(CASE WHEN time_in IS NULL THEN 1 ELSE 0 END) as absent_days,
            SUM(total_hours) as total_hours,
            AVG(total_hours) as average_hours
        FROM student_attendance 
        WHERE student_id = ?
    ");
    $summary_stmt->bind_param("i", $user_id);
    $summary_stmt->execute();
    $summary_result = $summary_stmt->get_result();
    
    if ($summary_result->num_rows > 0) {
        $summary = $summary_result->fetch_assoc();
        $summary_stats = [
            'total_hours' => $summary['total_hours'] ?: 0,
            'total_days' => $summary['total_days'] ?: 0,
            'present_days' => $summary['present_days'] ?: 0,
            'absent_days' => $summary['absent_days'] ?: 0,
            'average_hours' => $summary['average_hours'] ?: 0
        ];
    }
    $summary_stmt->close();
} catch (Exception $e) {
    // Handle error
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>OnTheJob Tracker - Attendance History</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link rel="stylesheet" href="css/studentDashboard.css" />
    <link rel="stylesheet" href="css/studentAttendance.css" />
    <style>
        .pagination {
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 10px;
            margin-top: 20px;
            padding: 20px 0;
        }
        
        .pagination a, .pagination span {
            padding: 8px 12px;
            text-decoration: none;
            border: 1px solid #ddd;
            border-radius: 5px;
            color: #333;
            transition: all 0.3s ease;
        }
        
        .pagination a:hover {
            background-color: #007bff;
            color: white;
            border-color: #007bff;
        }
        
        .pagination .current {
            background-color: #007bff;
            color: white;
            border-color: #007bff;
        }
        
        .summary-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }
        
        .summary-card {
            background: white;
            padding: 20px;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            text-align: center;
        }
        
        .summary-number {
            font-size: 2rem;
            font-weight: bold;
            color: #007bff;
            margin-bottom: 5px;
        }
        
        .summary-label {
            color: #666;
            font-size: 0.9rem;
        }
    </style>
</head>
<body>
    <div class="sidebar">
        <div class="logo">
            <h1>
                <span class="logo-text">OnTheJob</span>
                <span class="logo-highlight">Tracker</span>
                <span class="logo-icon">|||</span>
            </h1>
        </div>
        
        <div class="navigation">
            <h2>Navigation</h2>
            <a href="studentdashboard.php" class="nav-item">
                <i class="fas fa-th-large"></i>
                Dashboard
            </a>
            <a href="studentAttendance.php" class="nav-item active">
                <i class="fas fa-calendar-check"></i>
                Attendance
            </a>
            <a href="studentTask.php" class="nav-item">
                <i class="fas fa-tasks"></i>
                Tasks
            </a>
            <a href="studentReport.php" class="nav-item">
                <i class="fas fa-book"></i>
                Report
            </a>
            <a href="studentEvaluation.php" class="nav-item">
                <i class="fas fa-star"></i>
                Evaluation
            </a>
            <a href="studentSelf-Assessment.php" class="nav-item">
                <i class="fas fa-star"></i>
                Self-Assessment
            </a>
            <a href="studentMessage.php" class="nav-item">
                <i class="fas fa-envelope"></i>
                Message
            </a>
        </div>
        
        <div class="user-profile">
            <div class="avatar">
                <?php if (!empty($profile_picture) && file_exists($profile_picture)): ?>
                    <img src="<?php echo htmlspecialchars($profile_picture); ?>" alt="Profile Picture">
                <?php else: ?>
                    <?php echo $initials; ?>
                <?php endif; ?>
            </div>
            <div class="user-info">
                <div class="user-name"><?php echo htmlspecialchars($full_name); ?></div>
                <div class="user-role">Trainee</div>
            </div>
        </div>
    </div>
    
    <div class="main-content">
        <div class="header">
            <div class="header-title">
                <h1>Attendance History</h1>
                <p>Complete record of your OJT attendance</p>
            </div>
            
            <div class="top-bar">
                <a href="studentAttendance.php" class="btn btn-secondary">
                    <i class="fas fa-arrow-left"></i>
                    Back to Attendance
                </a>
            </div>
        </div>

        <div class="attendance-container">
            <!-- Summary Statistics -->
            <div class="summary-grid">
                <div class="summary-card">
                    <div class="summary-number"><?php echo number_format($summary_stats['total_hours'], 1); ?></div>
                    <div class="summary-label">Total Hours</div>
                </div>
                <div class="summary-card">
                    <div class="summary-number"><?php echo $summary_stats['present_days']; ?></div>
                    <div class="summary-label">Days Present</div>
                </div>
                <div class="summary-card">
                    <div class="summary-number"><?php echo $summary_stats['total_days']; ?></div>
                    <div class="summary-label">Total Days</div>
                </div>
                <div class="summary-card">
                    <div class="summary-number"><?php echo number_format($summary_stats['average_hours'], 1); ?></div>
                    <div class="summary-label">Avg Hours/Day</div>
                </div>
            </div>

            <!-- Full Attendance History -->
            <div class="history-section">
                <div class="history-header">
                    <div class="history-title">
                        <i class="fas fa-calendar-alt"></i>
                        Complete Attendance History
                    </div>
                    <div class="history-info">
                        Showing <?php echo min($records_per_page, $total_records - $offset); ?> of <?php echo $total_records; ?> records
                    </div>
                </div>

                <?php if (!empty($attendance_history)): ?>
                    <table class="history-table">
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Day</th>
                                <th>Time In</th>
                                <th>Time Out</th>
                                <th>Hours</th>
                                <th>Status</th>
                                <th>Notes</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($attendance_history as $record): ?>
                                <tr>
                                    <td><?php echo date('M j, Y', strtotime($record['date'])); ?></td>
                                    <td><?php echo date('l', strtotime($record['date'])); ?></td>
                                    <td><?php echo $record['time_in'] ? date('g:i A', strtotime($record['time_in'])) : '--'; ?></td>
                                    <td><?php echo $record['time_out'] ? date('g:i A', strtotime($record['time_out'])) : '--'; ?></td>
                                    <td><?php echo $record['total_hours'] ? number_format($record['total_hours'], 1) . ' hrs' : '--'; ?></td>
                                    <td>
                                        <span class="status-badge <?php 
                                            if ($record['time_in'] && $record['time_out']) {
                                                echo 'completed';
                                            } elseif ($record['time_in'] && !$record['time_out']) {
                                                echo 'ongoing';
                                            } else {
                                                echo 'absent';
                                            }
                                        ?>">
                                            <?php echo htmlspecialchars($record['status']); ?>
                                        </span>
                                    </td>
                                    <td><?php echo $record['notes'] ? htmlspecialchars($record['notes']) : '--'; ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>

                    <!-- Pagination -->
                    <?php if ($total_pages > 1): ?>
                        <div class="pagination">
                            <?php if ($page > 1): ?>
                                <a href="?page=1"><i class="fas fa-angle-double-left"></i></a>
                                <a href="?page=<?php echo $page - 1; ?>"><i class="fas fa-angle-left"></i></a>
                            <?php endif; ?>
                            
                            <?php
                            $start_page = max(1, $page - 2);
                            $end_page = min($total_pages, $page + 2);
                            
                            for ($i = $start_page; $i <= $end_page; $i++):
                            ?>
                                <?php if ($i == $page): ?>
                                    <span class="current"><?php echo $i; ?></span>
                                <?php else: ?>
                                    <a href="?page=<?php echo $i; ?>"><?php echo $i; ?></a>
                                <?php endif; ?>
                            <?php endfor; ?>
                            
                            <?php if ($page < $total_pages): ?>
                                <a href="?page=<?php echo $page + 1; ?>"><i class="fas fa-angle-right"></i></a>
                                <a href="?page=<?php echo $total_pages; ?>"><i class="fas fa-angle-double-right"></i></a>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>

                <?php else: ?>
                    <div class="empty-state">
                        <i class="fas fa-calendar-times"></i>
                        <h3>No Attendance Records</h3>
                        <p>You haven't recorded any attendance yet. Start by timing in on your OJT dashboard.</p>
                        <a href="studentAttendance.php" class="btn btn-primary">
                            <i class="fas fa-clock"></i>
                            Record Attendance
                        </a>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</body>
</html>

<?php
// facial_attendance.php - Fixed UI logic for first-time vs re-enrollment
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
        
        // CRITICAL: Check if user actually has face data enrolled
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
        $stmt = $conn->prepare("SELECT * FROM student_deployments WHERE student_id = ? AND status = 'Active'");
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows > 0) {
            $deployment = $result->fetch_assoc();
            return $deployment;
        }
        
        return false;
    } catch (Exception $e) {
        error_log("Deployment check error: " . $e->getMessage());
        return false;
    }
}

$is_deployed = checkDeploymentStatus($conn, $user_id);
$can_record_attendance = $is_deployed !== false;

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
                // Face descriptor will be processed on frontend
                $faceEncoding = $_POST['face_descriptor'] ?? null;
                
                if (empty($faceEncoding)) {
                    // Delete uploaded file if no face detected
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
    
    // Delete face enrollment
    if ($action === 'delete_face') {
        try {
            // Delete face image file if exists
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
    
    // Attendance recording with REAL face verification (camera-based)
    if ($action === 'time_in' || $action === 'time_out') {
        if (!$can_record_attendance) {
            echo json_encode([
                'success' => false,
                'message' => 'No active deployment found. Please contact your coordinator.'
            ]);
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
            // Get current face descriptor from frontend
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
            
            // Calculate Euclidean distance between face descriptors
            $distance = calculateEuclideanDistance($enrolledDescriptor, $currentDescriptor);
            $confidence = max(0, 1 - ($distance / 1.2)); // Normalize distance to confidence
            
            // CRITICAL: Only allow if faces match with high confidence
            $threshold = 0.6; // Maximum allowed distance
            $minConfidence = 0.75; // Minimum confidence required
            
            if ($distance > $threshold || $confidence < $minConfidence) {
                echo json_encode([
                    'success' => false,
                    'message' => 'Face verification failed. Distance: ' . number_format($distance, 3) . 
                               ', Confidence: ' . number_format($confidence * 100, 1) . '%',
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
                    $update_stmt = $conn->prepare("UPDATE student_attendance SET time_in = ?, attendance_method = 'facial', facial_confidence = ?, updated_at = CURRENT_TIMESTAMP WHERE attendance_id = ?");
                    $update_stmt->bind_param("sdi", $current_time, $confidence, $today_attendance['attendance_id']);
                } else {
                    $insert_stmt = $conn->prepare("INSERT INTO student_attendance (student_id, deployment_id, date, time_in, attendance_method, facial_confidence, status, created_at) VALUES (?, ?, ?, ?, 'facial', ?, 'Present', CURRENT_TIMESTAMP)");
                    $insert_stmt->bind_param("iissd", $user_id, $is_deployed['deployment_id'], $today, $current_time, $confidence);
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
                    'distance' => $distance
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
                
                $update_stmt = $conn->prepare("UPDATE student_attendance SET time_out = ?, total_hours = ?, facial_confidence = ?, updated_at = CURRENT_TIMESTAMP WHERE attendance_id = ?");
                $update_stmt->bind_param("sddi", $current_time, $total_hours, $confidence, $today_attendance['attendance_id']);
                $update_stmt->execute();
                $update_stmt->close();
                
                echo json_encode([
                    'success' => true,
                    'message' => 'Time Out recorded successfully!',
                    'time' => date('g:i A', strtotime($current_time)),
                    'hours' => $total_hours,
                    'confidence' => $confidence,
                    'distance' => $distance
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

// Add face encoding column if not exists
try {
    $conn->query("ALTER TABLE students ADD COLUMN IF NOT EXISTS face_encoding TEXT DEFAULT NULL");
    $conn->query("ALTER TABLE students ADD COLUMN IF NOT EXISTS face_enrolled_at TIMESTAMP NULL DEFAULT NULL");
    $conn->query("ALTER TABLE student_attendance ADD COLUMN IF NOT EXISTS attendance_method ENUM('manual', 'facial') DEFAULT 'facial'");
    $conn->query("ALTER TABLE student_attendance ADD COLUMN IF NOT EXISTS facial_confidence DECIMAL(5,2) DEFAULT NULL");
    $conn->query("ALTER TABLE student_attendance ADD COLUMN IF NOT EXISTS facial_image_path VARCHAR(255) DEFAULT NULL");
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
    <link rel="stylesheet" href="css/studentDashboard.css" />
    <link rel="stylesheet" href="css/studentAttendance.css" />
    <script src="https://cdn.jsdelivr.net/npm/@vladmandic/face-api@1.7.13/dist/face-api.min.js"></script>
    <style>
        .face-enrollment-section {
            background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);
            color: white;
            border-radius: 20px;
            padding: 30px;
            margin-bottom: 30px;
            text-align: center;
        }
        .face-management-section {
            background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%);
            color: white;
            border-radius: 20px;
            padding: 30px;
            margin-bottom: 30px;
            text-align: center;
        }
        .attendance-section {
            background: white;
            border-radius: 20px;
            padding: 30px;
            margin-bottom: 30px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.1);
        }
        
        /* First-time enrollment styling */
        .first-time-enrollment {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        }
        
        /* Re-enrollment styling */
        .re-enrollment-section {
            background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);
        }
        
        .photo-upload-area {
            border: 3px dashed rgba(255,255,255,0.3);
            border-radius: 15px;
            padding: 40px;
            margin: 30px 0;
            text-align: center;
            transition: all 0.3s ease;
            cursor: pointer;
        }
        .photo-upload-area:hover {
            border-color: rgba(255,255,255,0.6);
            background: rgba(255,255,255,0.05);
        }
        .photo-upload-area.drag-over {
            border-color: #00ff88;
            background: rgba(0,255,136,0.1);
        }
        .upload-icon {
            font-size: 3rem;
            margin-bottom: 20px;
            opacity: 0.7;
        }
        .photo-preview {
            max-width: 300px;
            max-height: 300px;
            border-radius: 15px;
            margin: 20px auto;
            box-shadow: 0 8px 25px rgba(0,0,0,0.2);
        }
        .camera-container {
            position: relative;
            border-radius: 15px;
            overflow: hidden;
            background: #000;
            margin-bottom: 25px;
            box-shadow: 0 8px 25px rgba(0,0,0,0.2);
        }
        #attendanceVideo {
            width: 100%;
            height: 400px;
            object-fit: cover;
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
            border: 3px solid #00ff88;
            border-radius: 8px;
            box-shadow: 0 0 20px rgba(0, 255, 136, 0.5);
        }
        .face-status-overlay {
            position: absolute;
            bottom: 20px;
            left: 50%;
            transform: translateX(-50%);
            background: rgba(0,0,0,0.9);
            color: white;
            padding: 12px 24px;
            border-radius: 25px;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .status-icon {
            width: 12px;
            height: 12px;
            border-radius: 50%;
            background: #ff4444;
            animation: pulse-red 1.5s infinite;
        }
        .status-icon.detected { background: #00ff88; }
        .status-icon.verified { background: #4CAF50; animation: none; }
        .facial-btn {
            background: linear-gradient(135deg, #667eea, #764ba2);
            color: white;
            border: none;
            padding: 15px 30px;
            border-radius: 30px;
            font-size: 1.1rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            gap: 10px;
            min-width: 150px;
            justify-content: center;
            margin: 0 10px;
        }
        .facial-btn:disabled { opacity: 0.6; cursor: not-allowed; }
        .facial-btn.danger {
            background: linear-gradient(135deg, #ff6b6b, #ee5a24);
        }
        .facial-btn.success {
            background: linear-gradient(135deg, #00d4aa, #01a3a4);
        }
        .verification-status {
            background: #f8f9fa;
            border-radius: 12px;
            padding: 20px;
            margin: 20px 0;
            text-align: center;
            display: none;
        }
        .verification-status.success { background: #d4edda; color: #155724; }
        .verification-status.error { background: #f8d7da; color: #721c24; }
        .confidence-meter {
            width: 100%;
            height: 8px;
            background: #e9ecef;
            border-radius: 4px;
            margin: 10px 0;
        }
        .confidence-fill {
            height: 100%;
            background: linear-gradient(90deg, #ff4444, #ffaa00, #00ff88);
            width: 0%;
            transition: width 0.5s ease;
        }
        .control-buttons {
            display: flex;
            justify-content: center;
            gap: 20px;
            flex-wrap: wrap;
        }
        .face-status-card {
            background: rgba(255,255,255,0.15);
            border-radius: 15px;
            padding: 20px;
            margin-bottom: 20px;
            text-align: left;
        }
        .face-status-card h4 {
            margin: 0 0 10px 0;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .loading-spinner {
            border: 3px solid rgba(255,255,255,0.3);
            border-radius: 50%;
            border-top: 3px solid white;
            width: 30px;
            height: 30px;
            animation: spin 1s linear infinite;
            display: none;
            margin: 0 auto;
        }
        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
        @keyframes pulse-red {
            0%, 100% { opacity: 1; }
            50% { opacity: 0.3; }
        }
        .photo-requirements {
            background: rgba(255,255,255,0.1);
            border-radius: 10px;
            padding: 20px;
            margin: 20px 0;
            text-align: left;
        }
        .photo-requirements h4 {
            color: white;
            margin-bottom: 15px;
        }
        .photo-requirements ul {
            color: rgba(255,255,255,0.9);
            margin: 0;
            padding-left: 20px;
        }
        .photo-requirements li {
            margin-bottom: 8px;
        }
        
        /* Welcome message for new users */
        .welcome-message {
            background: rgba(255,255,255,0.1);
            border-left: 4px solid #00ff88;
            padding: 20px;
            margin-bottom: 30px;
            border-radius: 0 10px 10px 0;
        }
        
        .welcome-message h3 {
            margin: 0 0 10px 0;
            color: #00ff88;
        }
    </style>
</head>
<body>
    <div class="sidebar">
        <div class="logo">
            <h1>
                <span class="logo-text">OnTheJob</span>
                <span class="logo-highlight">Tracker</span>
                <span class="logo-icon">|||</span>
            </h1>
        </div>
        
        <div class="navigation">
            <h2>Navigation</h2>
            <a href="studentdashboard.php" class="nav-item">
                <i class="fas fa-th-large"></i>
                Dashboard
            </a>
            <a href="studentAttendance.php" class="nav-item active">
                <i class="fas fa-camera"></i>
                Facial Attendance
            </a>
            <a href="studentTask.php" class="nav-item">
                <i class="fas fa-tasks"></i>
                Tasks
            </a>
            <a href="studentReport.php" class="nav-item">
                <i class="fas fa-book"></i>
                Report
            </a>
            <a href="studentEvaluation.php" class="nav-item">
                <i class="fas fa-star"></i>
                Evaluation
            </a>
            <a href="studentSelf-Assessment.php" class="nav-item">
                <i class="fas fa-star"></i>
                Self-Assessment
            </a>
            <a href="studentMessage.php" class="nav-item">
                <i class="fas fa-envelope"></i>
                Message
            </a>
        </div>
        
        <div class="user-profile">
            <div class="avatar">
                <?php if (!empty($profile_picture) && file_exists($profile_picture)): ?>
                    <img src="<?php echo htmlspecialchars($profile_picture); ?>" alt="Profile Picture">
                <?php else: ?>
                    <?php echo $initials; ?>
                <?php endif; ?>
            </div>
            <div class="user-info">
                <div class="user-name"><?php echo htmlspecialchars($full_name); ?></div>
                <div class="user-role">Trainee</div>
            </div>
        </div>
    </div>
    
    <div class="main-content">
        <div class="header">
            <div class="header-title">
                <h1><i class="fas fa-shield-alt"></i> Facial Recognition Attendance</h1>
                <p><?php echo $has_face_enrolled ? 'Manage your facial recognition settings' : 'Set up secure attendance verification'; ?></p>
            </div>
            <div class="top-bar">
                 <div class="profile-dropdown">
                    <div class="profile-pic" onclick="toggleDropdown()">
                        <?php if (!empty($profile_picture) && file_exists($profile_picture)): ?>
                            <img src="<?php echo htmlspecialchars($profile_picture); ?>" alt="Profile Picture">
                        <?php else: ?>
                            <span><?php echo $initials; ?></span>
                        <?php endif; ?>
                    </div>
                    <div class="dropdown-content" id="profileDropdown">
                        <div class="dropdown-header">
                            <div style="display: flex; align-items: center; gap: 12px; margin-bottom: 10px;">
                                <div style="width: 50px; height: 50px; border-radius: 50%; overflow: hidden; background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); display: flex; align-items: center; justify-content: center;">
                                    <?php if (!empty($profile_picture) && file_exists($profile_picture)): ?>
                                        <img src="<?php echo htmlspecialchars($profile_picture); ?>" alt="Profile Picture" style="width: 100%; height: 100%; object-fit: cover;">
                                    <?php else: ?>
                                        <span style="color: white; font-weight: 600; font-size: 1.2rem;"><?php echo $initials; ?></span>
                                    <?php endif; ?>
                                </div>
                                <div>
                                    <div class="dropdown-header-name"><?php echo htmlspecialchars($full_name); ?></div>
                                    <div class="dropdown-header-info"><?php echo htmlspecialchars($student_id); ?> • <?php echo htmlspecialchars($program); ?></div>
                                </div>
                            </div>
                        </div>
                        <a href="studentAccount-settings.php" class="dropdown-item">
                            <i class="fas fa-cog"></i>
                            Account Settings
                        </a>
                        <div class="dropdown-divider"></div>
<a href="logout.php" class="dropdown-item">
                            <i class="fas fa-sign-out-alt"></i>
                            Logout
                        </a>
                    </div>
                </div>
            </div>
        </div>

        <div class="attendance-container">
            
            <!-- CASE 1: First-time users (no face enrolled) - UPLOAD ONLY -->
            <?php if (!$has_face_enrolled): ?>
                <div class="welcome-message">
                    <h3><i class="fas fa-hand-wave"></i> Welcome to Facial Recognition!</h3>
                    <p>To get started with secure attendance tracking, you'll need to upload a clear photo of your face. This is a one-time setup process.</p>
                </div>
                
                <div class="face-enrollment-section first-time-enrollment">
                    <h2><i class="fas fa-user-plus"></i> Upload Your Photo</h2>
                    <p>Upload a clear front-facing photo for facial recognition attendance</p>
                    
                    <div class="photo-requirements">
                        <h4><i class="fas fa-info-circle"></i> Photo Requirements:</h4>
                        <ul>
                            <li>Latest clear selfie of yourself</li>
                            <li>Clear, front-facing photo with good lighting</li>
                            <li>Single person only (no group photos)</li>
                            <li>No sunglasses, hats, or face coverings</li>
                            <li>JPG or PNG format, under 5MB</li>
                            <li>High resolution for best results</li>
                        </ul>
                    </div>
                    
                    <div class="photo-upload-area" onclick="document.getElementById('photoInput').click()">
                        <div class="upload-icon">
                            <i class="fas fa-cloud-upload-alt"></i>
                        </div>
                        <h3>Click to Upload Photo</h3>
                        <p>Or drag and drop your photo here</p>
                        <input type="file" id="photoInput" accept="image/*" style="display: none;" onchange="handlePhotoSelect(this)">
                    </div>
                    
                    <div id="photoPreview" style="display: none;">
                        <img id="selectedPhoto" class="photo-preview" alt="Selected Photo">
                        <div class="loading-spinner" id="processingSpinner"></div>
                        <div id="faceDetectionResult" style="margin: 20px 0;"></div>
                    </div>
                    
                    <div class="control-buttons">
                        <button class="facial-btn success" id="enrollPhotoBtn" onclick="enrollFacePhoto()" disabled style="display: none;">
                            <i class="fas fa-user-check"></i> Enroll Face
                        </button>
                        <button class="facial-btn" id="cancelPhotoBtn" onclick="cancelPhotoSelection()" style="display: none;">
                            <i class="fas fa-times"></i> Cancel
                        </button>
                    </div>
                </div>
                
            <?php else: ?>
            <!-- CASE 2: Users with existing face enrollment - MANAGE FACE & ATTENDANCE -->
                <div class="face-management-section">
                    <h2><i class="fas fa-user-check"></i> Face Enrollment Status</h2>
                    
                    <div class="face-status-card">
                        <h4><i class="fas fa-check-circle"></i> Face Enrolled Successfully</h4>
                        <p>Your face is enrolled and ready for attendance verification.</p>
                        <p><strong>Enrolled:</strong> <?php echo file_exists($profile_picture) ? date('M d, Y g:i A', filemtime($profile_picture)) : 'Unknown'; ?></p>
                        </div>
                    
                    <div class="control-buttons">
                        <button class="facial-btn" onclick="showReEnrollmentSection()">
                            <i class="fas fa-sync-alt"></i> Update Face Photo
                        </button>
                        <button class="facial-btn danger" onclick="deleteFaceEnrollment()">
                            <i class="fas fa-trash-alt"></i> Delete Face Data
                        </button>
                    </div>
                </div>
                
                <!-- Re-enrollment Section (Initially Hidden) -->
                <div class="face-enrollment-section re-enrollment-section" id="reEnrollmentSection" style="display: none;">
                    <h2><i class="fas fa-sync-alt"></i> Update Face Photo</h2>
                    <p>Upload a new photo to update your facial recognition data</p>
                    
                    <div class="photo-requirements">
                        <h4><i class="fas fa-info-circle"></i> Photo Requirements:</h4>
                        <ul>
                            <li>Clear, front-facing photo with good lighting</li>
                            <li>Single person only (no group photos)</li>
                            <li>No sunglasses, hats, or face coverings</li>
                            <li>JPG or PNG format, under 5MB</li>
                            <li>High resolution for best results</li>
                        </ul>
                    </div>
                    
                    <div class="photo-upload-area" onclick="document.getElementById('reEnrollPhotoInput').click()">
                        <div class="upload-icon">
                            <i class="fas fa-cloud-upload-alt"></i>
                        </div>
                        <h3>Click to Upload New Photo</h3>
                        <p>Or drag and drop your photo here</p>
                        <input type="file" id="reEnrollPhotoInput" accept="image/*" style="display: none;" onchange="handleReEnrollPhotoSelect(this)">
                    </div>
                    
                    <div id="reEnrollPhotoPreview" style="display: none;">
                        <img id="reEnrollSelectedPhoto" class="photo-preview" alt="Selected Photo">
                        <div class="loading-spinner" id="reEnrollProcessingSpinner"></div>
                        <div id="reEnrollFaceDetectionResult" style="margin: 20px 0;"></div>
                    </div>
                    
                    <div class="control-buttons">
                        <button class="facial-btn success" id="updateFaceBtn" onclick="updateFacePhoto()" disabled style="display: none;">
                            <i class="fas fa-user-check"></i> Update Face
                        </button>
                        <button class="facial-btn" onclick="hideReEnrollmentSection()">
                            <i class="fas fa-times"></i> Cancel
                        </button>
                    </div>
                </div>
                
                <!-- Attendance Section -->
                <?php if ($can_record_attendance): ?>
                <div class="attendance-section">
                    <h2><i class="fas fa-clock"></i> Record Attendance</h2>
                    
                    <?php if ($today_attendance): ?>
                        <div class="today-status">
                            <h3>Today's Status</h3>
                            <div style="display: flex; gap: 20px; justify-content: center; margin: 20px 0;">
                                <?php if ($today_attendance['time_in']): ?>
                                    <div class="time-display time-in">
                                        <i class="fas fa-sign-in-alt"></i>
                                        <strong>Time In:</strong> <?php echo date('g:i A', strtotime($today_attendance['time_in'])); ?>
                                    </div>
                                <?php endif; ?>
                                
                                <?php if ($today_attendance['time_out']): ?>
                                    <div class="time-display time-out">
                                        <i class="fas fa-sign-out-alt"></i>
                                        <strong>Time Out:</strong> <?php echo date('g:i A', strtotime($today_attendance['time_out'])); ?>
                                        <small>(<?php echo $today_attendance['total_hours']; ?>h total)</small>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endif; ?>
                    
                    <div class="camera-container">
                        <video id="attendanceVideo" autoplay muted></video>
                        <div class="face-detection-overlay" id="faceOverlay"></div>
                        <div class="face-status-overlay" id="faceStatusOverlay">
                            <div class="status-icon" id="statusIcon"></div>
                            <span id="statusText">Initializing camera...</span>
                        </div>
                    </div>
                    
                    <div class="verification-status" id="verificationStatus"></div>
                    
                    <div class="control-buttons">
                        <?php if (!$today_attendance || !$today_attendance['time_in']): ?>
                            <button class="facial-btn success" id="timeInBtn" onclick="recordAttendance('time_in')" disabled>
                                <i class="fas fa-sign-in-alt"></i> Time In
                            </button>
                        <?php endif; ?>
                        
                        <?php if ($today_attendance && $today_attendance['time_in'] && !$today_attendance['time_out']): ?>
                            <button class="facial-btn danger" id="timeOutBtn" onclick="recordAttendance('time_out')" disabled>
                                <i class="fas fa-sign-out-alt"></i> Time Out
                            </button>
                        <?php endif; ?>
                        
                        <button class="facial-btn" id="refreshCameraBtn" onclick="initializeAttendanceCamera()">
                            <i class="fas fa-sync-alt"></i> Refresh Camera
                        </button>
                    </div>
                </div>
                <?php else: ?>
                <div class="attendance-section">
                    <div class="no-deployment-message">
                        <i class="fas fa-exclamation-triangle" style="font-size: 3rem; color: #ff6b6b; margin-bottom: 20px;"></i>
                        <h3>No Active Deployment</h3>
                        <p>You don't have an active deployment assignment. Please contact your coordinator to get deployed before you can record attendance.</p>
                    </div>
                </div>
                <?php endif; ?>
                
            <?php endif; ?>
        </div>
    </div>

    <!-- Face API -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/face-api.js/0.22.2/face-api.min.js"></script>
    
    <script>
        let attendanceVideo = null;
        let faceDetectionInterval = null;
        let currentFaceDescriptor = null;
        let faceApiLoaded = false;

        async function loadFaceApiModels() {
    try {
        updateStatus('Loading face recognition models...', false, true);
        
        // Use a more reliable CDN
        const MODEL_URL = 'https://cdn.jsdelivr.net/npm/@vladmandic/face-api@1.7.13/model';
        
        console.log('Loading models from:', MODEL_URL);
        
        // Load with explicit timeout and error handling
        const loadPromise = Promise.all([
            faceapi.nets.tinyFaceDetector.loadFromUri(MODEL_URL),
            faceapi.nets.faceLandmark68Net.loadFromUri(MODEL_URL),
            faceapi.nets.faceRecognitionNet.loadFromUri(MODEL_URL)
        ]);
        
        // 30-second timeout
        const timeoutPromise = new Promise((_, reject) => {
            setTimeout(() => reject(new Error('Models loading timeout after 30 seconds')), 30000);
        });
        
        await Promise.race([loadPromise, timeoutPromise]);
        
        faceApiLoaded = true;
        console.log('Face API models loaded successfully!');
        updateStatus('Face recognition ready!', true);
        
        // Initialize camera if user has face enrolled
        const hasEnrollment = <?php echo json_encode($has_face_enrolled); ?>;
        const canRecord = <?php echo json_encode($can_record_attendance); ?>;
        
        if (hasEnrollment && canRecord) {
            setTimeout(() => initializeAttendanceCamera(), 1000);
        }
        
    } catch (error) {
        console.error('Face API loading error:', error);
        faceApiLoaded = false;
        updateStatus('Face recognition failed to load', false);
        
        // Show retry option
        showRetryOption(error.message);
    }
}
function showRetryOption(errorMsg) {
    const container = document.querySelector('.attendance-section') || document.querySelector('.face-enrollment-section');
    if (!container) return;
    
    // Remove existing retry button
    const existingRetry = container.querySelector('.model-retry-section');
    if (existingRetry) existingRetry.remove();
    
    const retrySection = document.createElement('div');
    retrySection.className = 'model-retry-section';
    retrySection.style.cssText = `
        background: #fff3cd;
        border: 1px solid #ffeaa7;
        border-radius: 10px;
        padding: 20px;
        margin: 20px 0;
        text-align: center;
    `;
    
    retrySection.innerHTML = `
        <h4 style="color: #856404; margin: 0 0 15px 0;">
            <i class="fas fa-exclamation-triangle"></i> Face Recognition Loading Failed
        </h4>
        <p style="color: #856404; margin: 0 0 15px 0;">
            Error: ${errorMsg}
        </p>
        <div style="display: flex; gap: 10px; justify-content: center; flex-wrap: wrap;">
            <button onclick="retryModelLoading()" class="facial-btn">
                <i class="fas fa-sync-alt"></i> Retry Loading
            </button>
            <button onclick="window.location.reload()" class="facial-btn">
                <i class="fas fa-refresh"></i> Refresh Page
            </button>
        </div>
    `;
    
    container.appendChild(retrySection);
}

function retryModelLoading() {
    const retrySection = document.querySelector('.model-retry-section');
    if (retrySection) retrySection.remove();
    
    loadFaceApiModels();
}


        // Initialize attendance camera for enrolled users
        async function initializeAttendanceCamera() {
            const video = document.getElementById('attendanceVideo');
            if (!video) return;

            try {
                // Stop existing stream
                if (attendanceVideo && attendanceVideo.srcObject) {
                    attendanceVideo.srcObject.getTracks().forEach(track => track.stop());
                }

                const stream = await navigator.mediaDevices.getUserMedia({ 
                    video: { 
                        width: { ideal: 640 }, 
                        height: { ideal: 480 },
                        facingMode: 'user' 
                    } 
                });
                
                video.srcObject = stream;
                attendanceVideo = video;
                
                updateStatus('Camera ready - Position your face in view', true);
                
                // Start face detection
                video.addEventListener('loadedmetadata', () => {
                    startFaceDetection();
                });
                
            } catch (error) {
                console.error('Camera error:', error);
                updateStatus('Camera access denied or unavailable', false);
            }
        }
        function updateStatus(message, isGood = false, showSpinner = false) {
    const statusText = document.getElementById('statusText');
    const statusIcon = document.getElementById('statusIcon');
    
    if (statusText) {
        statusText.innerHTML = showSpinner ? 
            `<i class="fas fa-spinner fa-spin"></i> ${message}` : 
            message;
    }
    
    if (statusIcon) {
        statusIcon.className = 'status-icon ' + (isGood ? 'detected' : '');
    }
    
    console.log(`Status Update: ${message}`);
}

        // Start continuous face detection for attendance
        function startFaceDetection() {
            if (!faceApiLoaded || !attendanceVideo) return;
            
            faceDetectionInterval = setInterval(async () => {
                try {
                    const detections = await faceapi
                        .detectAllFaces(attendanceVideo, new faceapi.TinyFaceDetectorOptions())
                        .withFaceLandmarks()
                        .withFaceDescriptors();
                    
                    const overlay = document.getElementById('faceOverlay');
                    overlay.innerHTML = '';
                    
                    if (detections.length > 0) {
                        const detection = detections[0];
                        currentFaceDescriptor = Array.from(detection.descriptor);
                        
                        // Draw face rectangle
                        const box = detection.detection.box;
                        const rect = document.createElement('div');
                        rect.className = 'face-rectangle';
                        rect.style.left = box.x + 'px';
                        rect.style.top = box.y + 'px';
                        rect.style.width = box.width + 'px';
                        rect.style.height = box.height + 'px';
                        overlay.appendChild(rect);
                        
                        updateStatus('Face detected - Ready for verification', true);
                        enableAttendanceButtons(true);
                    } else {
                        currentFaceDescriptor = null;
                        updateStatus('No face detected - Position your face properly', false);
                        enableAttendanceButtons(false);
                    }
                } catch (error) {
                    console.error('Face detection error:', error);
                }
            }, 500);
        }

        // Update status display
        function updateStatus(message, isGood = false) {
            const statusText = document.getElementById('statusText');
            const statusIcon = document.getElementById('statusIcon');
            
            if (statusText) statusText.textContent = message;
            if (statusIcon) {
                statusIcon.className = 'status-icon ' + (isGood ? 'detected' : '');
            }
        }

        // Enable/disable attendance buttons
        function enableAttendanceButtons(enable) {
            const timeInBtn = document.getElementById('timeInBtn');
            const timeOutBtn = document.getElementById('timeOutBtn');
            
            if (timeInBtn) timeInBtn.disabled = !enable;
            if (timeOutBtn) timeOutBtn.disabled = !enable;
        }

        // Handle photo selection for first-time enrollment
        function handlePhotoSelect(input) {
            const file = input.files[0];
            if (!file) return;

            const preview = document.getElementById('photoPreview');
            const img = document.getElementById('selectedPhoto');
            const spinner = document.getElementById('processingSpinner');
            const enrollBtn = document.getElementById('enrollPhotoBtn');
            const cancelBtn = document.getElementById('cancelPhotoBtn');
            const resultDiv = document.getElementById('faceDetectionResult');

            // Show preview
            preview.style.display = 'block';
            img.src = URL.createObjectURL(file);
            enrollBtn.style.display = 'none';
            cancelBtn.style.display = 'inline-flex';
            enrollBtn.disabled = true;

            // Process face detection
            img.onload = async function() {
                if (!faceApiLoaded) {
                    resultDiv.innerHTML = '<div style="color: #ff6b6b;"><i class="fas fa-exclamation-circle"></i> Face recognition not loaded yet. Please wait...</div>';
                    return;
                }

                spinner.style.display = 'block';
                resultDiv.innerHTML = '';

                try {
                    const detections = await faceapi
                        .detectAllFaces(img, new faceapi.TinyFaceDetectorOptions())
                        .withFaceLandmarks()
                        .withFaceDescriptors();

                    spinner.style.display = 'none';

                    if (detections.length === 0) {
                        resultDiv.innerHTML = '<div style="color: #ff6b6b;"><i class="fas fa-exclamation-circle"></i> No face detected. Please upload a clear front-facing photo.</div>';
                        return;
                    }

                    if (detections.length > 1) {
                        resultDiv.innerHTML = '<div style="color: #ff6b6b;"><i class="fas fa-exclamation-circle"></i> Multiple faces detected. Please upload a photo with only one person.</div>';
                        return;
                    }

                    // Store face descriptor
                    currentFaceDescriptor = Array.from(detections[0].descriptor);
                    
                    resultDiv.innerHTML = '<div style="color: #00ff88;"><i class="fas fa-check-circle"></i> Face detected successfully! Ready to enroll.</div>';
                    enrollBtn.style.display = 'inline-flex';
                    enrollBtn.disabled = false;

                } catch (error) {
                    spinner.style.display = 'none';
                    console.error('Face detection error:', error);
                    resultDiv.innerHTML = '<div style="color: #ff6b6b;"><i class="fas fa-exclamation-circle"></i> Face detection failed. Please try another photo.</div>';
                }
            };
        }

        // Handle photo selection for re-enrollment
        function handleReEnrollPhotoSelect(input) {
            const file = input.files[0];
            if (!file) return;

            const preview = document.getElementById('reEnrollPhotoPreview');
            const img = document.getElementById('reEnrollSelectedPhoto');
            const spinner = document.getElementById('reEnrollProcessingSpinner');
            const updateBtn = document.getElementById('updateFaceBtn');
            const resultDiv = document.getElementById('reEnrollFaceDetectionResult');

            // Show preview
            preview.style.display = 'block';
            img.src = URL.createObjectURL(file);
            updateBtn.style.display = 'none';
            updateBtn.disabled = true;

            // Process face detection (same logic as first-time)
            img.onload = async function() {
                if (!faceApiLoaded) {
                    resultDiv.innerHTML = '<div style="color: #ff6b6b;"><i class="fas fa-exclamation-circle"></i> Face recognition not loaded yet. Please wait...</div>';
                    return;
                }

                spinner.style.display = 'block';
                resultDiv.innerHTML = '';

                try {
                    const detections = await faceapi
                        .detectAllFaces(img, new faceapi.TinyFaceDetectorOptions())
                        .withFaceLandmarks()
                        .withFaceDescriptors();

                    spinner.style.display = 'none';

                    if (detections.length === 0) {
                        resultDiv.innerHTML = '<div style="color: #ff6b6b;"><i class="fas fa-exclamation-circle"></i> No face detected. Please upload a clear front-facing photo.</div>';
                        return;
                    }

                    if (detections.length > 1) {
                        resultDiv.innerHTML = '<div style="color: #ff6b6b;"><i class="fas fa-exclamation-circle"></i> Multiple faces detected. Please upload a photo with only one person.</div>';
                        return;
                    }

                    // Store face descriptor
                    currentFaceDescriptor = Array.from(detections[0].descriptor);
                    
                    resultDiv.innerHTML = '<div style="color: #00ff88;"><i class="fas fa-check-circle"></i> Face detected successfully! Ready to update.</div>';
                    updateBtn.style.display = 'inline-flex';
                    updateBtn.disabled = false;

                } catch (error) {
                    spinner.style.display = 'none';
                    console.error('Face detection error:', error);
                    resultDiv.innerHTML = '<div style="color: #ff6b6b;"><i class="fas fa-exclamation-circle"></i> Face detection failed. Please try another photo.</div>';
                }
            };
        }

        // Enroll face with photo
        async function enrollFacePhoto() {
            if (!currentFaceDescriptor) {
                showAlert('No face descriptor available. Please select a photo first.', 'error');
                return;
            }

            const formData = new FormData();
            const photoInput = document.getElementById('photoInput');
            
            formData.append('action', 'enroll_face_photo');
            formData.append('photo_upload', photoInput.files[0]);
            formData.append('face_descriptor', JSON.stringify(currentFaceDescriptor));

            try {
                const response = await fetch('', {
                    method: 'POST',
                    body: formData
                });

                const result = await response.json();
                
                if (result.success) {
                    showAlert(result.message, 'success');
                    setTimeout(() => {
                        window.location.reload();
                    }, 2000);
                } else {
                    showAlert(result.message, 'error');
                }
            } catch (error) {
                console.error('Enrollment error:', error);
                showAlert('Network error occurred. Please try again.', 'error');
            }
        }

        // Update face photo (re-enrollment)
        async function updateFacePhoto() {
            if (!currentFaceDescriptor) {
                showAlert('No face descriptor available. Please select a photo first.', 'error');
                return;
            }

            const formData = new FormData();
            const photoInput = document.getElementById('reEnrollPhotoInput');
            
            formData.append('action', 'enroll_face_photo');
            formData.append('photo_upload', photoInput.files[0]);
            formData.append('face_descriptor', JSON.stringify(currentFaceDescriptor));

            try {
                const response = await fetch('', {
                    method: 'POST',
                    body: formData
                });

                const result = await response.json();
                
                if (result.success) {
                    showAlert(result.message, 'success');
                    setTimeout(() => {
                        window.location.reload();
                    }, 2000);
                } else {
                    showAlert(result.message, 'error');
                }
            } catch (error) {
                console.error('Update error:', error);
                showAlert('Network error occurred. Please try again.', 'error');
            }
        }

        // Cancel photo selection
        function cancelPhotoSelection() {
            document.getElementById('photoInput').value = '';
            document.getElementById('photoPreview').style.display = 'none';
            currentFaceDescriptor = null;
        }

        // Show/hide re-enrollment section
        function showReEnrollmentSection() {
            document.getElementById('reEnrollmentSection').style.display = 'block';
        }

        function hideReEnrollmentSection() {
            document.getElementById('reEnrollmentSection').style.display = 'none';
            document.getElementById('reEnrollPhotoInput').value = '';
            document.getElementById('reEnrollPhotoPreview').style.display = 'none';
            currentFaceDescriptor = null;
        }

        // Delete face enrollment
        async function deleteFaceEnrollment() {
            if (!confirm('Are you sure you want to delete your face enrollment? You will need to re-enroll to use facial recognition attendance.')) {
                return;
            }

            try {
                const formData = new FormData();
                formData.append('action', 'delete_face');

                const response = await fetch('', {
                    method: 'POST',
                    body: formData
                });

                const result = await response.json();
                
                if (result.success) {
                    showAlert(result.message, 'success');
                    setTimeout(() => {
                        window.location.reload();
                    }, 1500);
                } else {
                    showAlert(result.message, 'error');
                }
            } catch (error) {
                console.error('Delete error:', error);
                showAlert('Network error occurred. Please try again.', 'error');
            }
        }

        // Record attendance
        async function recordAttendance(action) {
            if (!currentFaceDescriptor) {
                showAlert('No face detected. Please position your face properly in the camera.', 'error');
                return;
            }

            const verificationDiv = document.getElementById('verificationStatus');
            verificationDiv.style.display = 'block';
            verificationDiv.className = 'verification-status';
            verificationDiv.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Verifying face...';

            try {
                const formData = new FormData();
                formData.append('action', action);
                formData.append('current_face_descriptor', JSON.stringify(currentFaceDescriptor));

                const response = await fetch('', {
                    method: 'POST',
                    body: formData
                });

                const result = await response.json();
                
                if (result.success) {
                    verificationDiv.className = 'verification-status success';
                    verificationDiv.innerHTML = `
                        <i class="fas fa-check-circle"></i> ${result.message}
                        ${result.confidence ? `<br><small>Confidence: ${(result.confidence * 100).toFixed(1)}%</small>` : ''}
                    `;
                    
                    // Refresh page after successful attendance
                    setTimeout(() => {
                        window.location.reload();
                    }, 2000);
                } else {
                    verificationDiv.className = 'verification-status error';
                    verificationDiv.innerHTML = `<i class="fas fa-exclamation-circle"></i> ${result.message}`;
                }
            } catch (error) {
                console.error('Attendance error:', error);
                verificationDiv.className = 'verification-status error';
                verificationDiv.innerHTML = '<i class="fas fa-exclamation-circle"></i> Network error occurred. Please try again.';
            }
        }

        // Utility function to show alerts
        function showAlert(message, type = 'info') {
            const alertDiv = document.createElement('div');
            alertDiv.className = `alert alert-${type}`;
            alertDiv.style.cssText = `
                position: fixed;
                top: 20px;
                right: 20px;
                padding: 15px 20px;
                border-radius: 8px;
                color: white;
                font-weight: 600;
                z-index: 9999;
                max-width: 400px;
                box-shadow: 0 4px 12px rgba(0,0,0,0.3);
            `;
            
            if (type === 'success') {
                alertDiv.style.background = 'linear-gradient(135deg, #00d4aa, #01a3a4)';
            } else if (type === 'error') {
                alertDiv.style.background = 'linear-gradient(135deg, #ff6b6b, #ee5a24)';
            } else {
                alertDiv.style.background = 'linear-gradient(135deg, #667eea, #764ba2)';
            }
            
            alertDiv.innerHTML = `<i class="fas fa-${type === 'success' ? 'check-circle' : type === 'error' ? 'exclamation-circle' : 'info-circle'}"></i> ${message}`;
            
            document.body.appendChild(alertDiv);
            
            setTimeout(() => {
                alertDiv.remove();
            }, 5000);
        }

        // Profile dropdown functionality
        function toggleDropdown() {
            const dropdown = document.getElementById('profileDropdown');
            dropdown.classList.toggle('show');
        }

        // Close dropdown when clicking outside
        window.onclick = function(event) {
            if (!event.target.matches('.profile-pic') && !event.target.closest('.profile-pic')) {
                const dropdown = document.getElementById('profileDropdown');
                if (dropdown && dropdown.classList.contains('show')) {
                    dropdown.classList.remove('show');
                }
            }
        }

        // Drag and drop functionality
        function setupDragAndDrop() {
            const uploadAreas = document.querySelectorAll('.photo-upload-area');
            
            uploadAreas.forEach(area => {
                area.addEventListener('dragover', (e) => {
                    e.preventDefault();
                    area.classList.add('drag-over');
                });

                area.addEventListener('dragleave', () => {
                    area.classList.remove('drag-over');
                });

                area.addEventListener('drop', (e) => {
                    e.preventDefault();
                    area.classList.remove('drag-over');
                    
                    const files = e.dataTransfer.files;
                    if (files.length > 0) {
                        const input = area.querySelector('input[type="file"]');
                        input.files = files;
                        
                        // Trigger change event
                        if (input.id === 'photoInput') {
                            handlePhotoSelect(input);
                        } else if (input.id === 'reEnrollPhotoInput') {
                            handleReEnrollPhotoSelect(input);
                        }
                    }
                });
            });
        }

        // Initialize everything when page loads
        document.addEventListener('DOMContentLoaded', function() {
            loadFaceApiModels();
            setupDragAndDrop();
        });

        // Cleanup when page unloads
        window.addEventListener('beforeunload', function() {
            if (faceDetectionInterval) {
                clearInterval(faceDetectionInterval);
            }
            
            if (attendanceVideo && attendanceVideo.srcObject) {
                attendanceVideo.srcObject.getTracks().forEach(track => track.stop());
            }
        });
    </script>

    <!-- Additional CSS for alerts and time displays -->
    <style>
        .time-display {
            background: rgba(255,255,255,0.1);
            padding: 15px;
            border-radius: 12px;
            text-align: center;
            flex: 1;
        }
        
        .time-display i {
            display: block;
            font-size: 1.5rem;
            margin-bottom: 8px;
        }
        
        .time-display.time-in i { color: #00ff88; }
        .time-display.time-out i { color: #ff6b6b; }
        
        .no-deployment-message {
            text-align: center;
            padding: 40px;
            color: #666;
        }
        
        .no-deployment-message h3 {
            color: #ff6b6b;
            margin: 20px 0;
        }
        
        .alert {
            animation: slideInRight 0.3s ease;
        }
        
        @keyframes slideInRight {
            from {
                transform: translateX(100%);
                opacity: 0;
            }
            to {
                transform: translateX(0);
                opacity: 1;
            }
        }
        
        .today-status {
            background: rgba(255,255,255,0.05);
            border-radius: 15px;
            padding: 25px;
            margin-bottom: 30px;
        }
        
        .today-status h3 {
            text-align: center;
            color: #333;
            margin-bottom: 20px;
        }
    </style>
</body>
</html>