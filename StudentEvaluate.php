<?php
include('connect.php');
session_start();
include_once('notification_functions.php');

// Prevent caching
header("Cache-Control: no-cache, no-store, must-revalidate");
header("Pragma: no-cache");
header("Expires: 0");
header("X-Frame-Options: DENY");
header("X-XSS-Protection: 1; mode=block");
header("X-Content-Type-Options: nosniff");
header("Referrer-Policy: strict-origin-when-cross-origin");

// Check if user is logged in and is a company supervisor
if (!isset($_SESSION['supervisor_id']) || $_SESSION['user_type'] !== 'supervisor') {
    header("Location: login.php");
    exit();
}

// Get supervisor information
$supervisor_id = $_SESSION['supervisor_id'];

// Initialize variables
$supervisor = null;
$supervisor_name = '';
$supervisor_email = '';
$company_name = '';
$profile_picture = '';
$initials = '';
$deployed_students = [];
$selected_student = null;
$can_submit_evaluation = false;
$existing_evaluation = null;
$is_viewing_evaluation = false;

// Check for submission messages
$submit_success = isset($_SESSION['evaluation_success']) ? $_SESSION['evaluation_success'] : null;
$submit_error = isset($_SESSION['evaluation_error']) ? $_SESSION['evaluation_error'] : null;
unset($_SESSION['evaluation_success'], $_SESSION['evaluation_error']);

/**
 * Calculate total score and get verbal interpretation
 */
function calculateEvaluationScore($competency_scores) {
    $total_score = array_sum($competency_scores);
    
    $scoring_ranges = [
        ['min' => 181, 'max' => 200, 'rating_min' => 97, 'rating_max' => 100, 'interpretation' => 'Outstanding'],
        ['min' => 161, 'max' => 180, 'rating_min' => 94, 'rating_max' => 96, 'interpretation' => 'Excellent'],
        ['min' => 141, 'max' => 160, 'rating_min' => 91, 'rating_max' => 93, 'interpretation' => 'Excellent'],
        ['min' => 121, 'max' => 140, 'rating_min' => 88, 'rating_max' => 90, 'interpretation' => 'Very Good'],
        ['min' => 101, 'max' => 120, 'rating_min' => 85, 'rating_max' => 87, 'interpretation' => 'Good'],
        ['min' => 81, 'max' => 100, 'rating_min' => 82, 'rating_max' => 84, 'interpretation' => 'Fair'],
        ['min' => 61, 'max' => 80, 'rating_min' => 79, 'rating_max' => 81, 'interpretation' => 'Fair'],
        ['min' => 41, 'max' => 60, 'rating_min' => 76, 'rating_max' => 78, 'interpretation' => 'Passed'],
        ['min' => 21, 'max' => 40, 'rating_min' => 75, 'rating_max' => 75, 'interpretation' => 'Passed'],
        ['min' => 0, 'max' => 20, 'rating_min' => 74, 'rating_max' => 74, 'interpretation' => 'Conditional Passed']
    ];
    
    foreach ($scoring_ranges as $range) {
        if ($total_score >= $range['min'] && $total_score <= $range['max']) {
            $equivalent_rating = ($range['rating_min'] + $range['rating_max']) / 2;
            return [
                'total_score' => $total_score,
                'equivalent_rating' => $equivalent_rating,
                'verbal_interpretation' => $range['interpretation']
            ];
        }
    }
    
    return [
        'total_score' => $total_score,
        'equivalent_rating' => 74.0,
        'verbal_interpretation' => 'Conditional Passed'
    ];
}

// Fetch supervisor data
try {
    if (!$conn) {
        throw new Exception("Database connection failed");
    }

    // Get supervisor information
    $stmt = $conn->prepare("SELECT * FROM company_supervisors WHERE supervisor_id = ?");
    if (!$stmt) {
        throw new Exception("Prepare statement failed: " . $conn->error);
    }
    
    $stmt->bind_param("i", $supervisor_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows > 0) {
        $supervisor = $result->fetch_assoc();
        $supervisor_name = htmlspecialchars($supervisor['full_name']);
        $supervisor_email = htmlspecialchars($supervisor['email']);
        $company_name = htmlspecialchars($supervisor['company_name']);
        $profile_picture = htmlspecialchars($supervisor['profile_picture'] ?? '');
        
        // Create initials for avatar
        $name_parts = explode(' ', trim($supervisor['full_name']));
        if (count($name_parts) >= 2) {
            $initials = strtoupper(substr($name_parts[0], 0, 1) . substr(end($name_parts), 0, 1));
        } else {
            $initials = strtoupper(substr($supervisor['full_name'], 0, 2));
        }
    } else {
        error_log("Supervisor not found for supervisor_id: " . $supervisor_id);
        session_destroy();
        header("Location: login.php?error=invalid_session");
        exit();
    }
    $stmt->close();

    // Get students deployed to this supervisor with evaluation status
    $students_stmt = $conn->prepare("
        SELECT 
            sd.deployment_id,
            sd.student_id,
            sd.position,
            sd.start_date,
            sd.end_date,
            sd.required_hours,
            sd.status as deployment_status,
            sd.ojt_status,
            s.first_name,
            s.middle_name,
            s.last_name,
            s.student_id as student_number,
            s.program,
            s.year_level,
            s.profile_picture,
            se.evaluation_id,
            se.total_score,
            se.equivalent_rating,
            se.verbal_interpretation,
            se.created_at as evaluation_date
        FROM student_deployments sd
        JOIN students s ON sd.student_id = s.id
        LEFT JOIN student_evaluations se ON sd.student_id = se.student_id AND sd.supervisor_id = se.supervisor_id
        WHERE sd.supervisor_id = ? AND sd.status = 'Active'
        ORDER BY 
            CASE 
                WHEN se.evaluation_id IS NULL THEN 0 
                ELSE 1 
            END,
            s.last_name, s.first_name
    ");

    if ($students_stmt) {
        $students_stmt->bind_param("i", $supervisor_id);
        $students_stmt->execute();
        $students_result = $students_stmt->get_result();
        $deployed_students = $students_result->fetch_all(MYSQLI_ASSOC);
        $students_stmt->close();
    }

} catch (Exception $e) {
    error_log("Error fetching supervisor data: " . $e->getMessage());
    header("Location: CompanyDashboard.php?error=system_error");
    exit();
}

// Handle student selection for evaluation/viewing
$selected_student_id = isset($_GET['student_id']) ? (int)$_GET['student_id'] : null;
$action = isset($_GET['action']) ? $_GET['action'] : 'evaluate';

if ($selected_student_id) {
    // Find the selected student in deployed students
    foreach ($deployed_students as $student) {
        if ($student['student_id'] == $selected_student_id) {
            $selected_student = $student;
            break;
        }
    }
    
    if ($selected_student) {
        // Check if evaluation already exists
        if ($selected_student['evaluation_id']) {
            // Evaluation exists, we're viewing it
            $is_viewing_evaluation = true;
            $existing_evaluation = $selected_student;
            $can_submit_evaluation = false;
            
            // Get full evaluation details if viewing
            if ($action === 'view') {
                $eval_stmt = $conn->prepare("SELECT * FROM student_evaluations WHERE evaluation_id = ?");
                $eval_stmt->bind_param("i", $selected_student['evaluation_id']);
                $eval_stmt->execute();
                $eval_result = $eval_stmt->get_result();
                $existing_evaluation = $eval_result->fetch_assoc();
                $eval_stmt->close();
            }
        } else {
            // No evaluation exists, can create new one
            $can_submit_evaluation = true;
            $is_viewing_evaluation = false;
        }
    }
}
$total_students = count($deployed_students);
$evaluated_students = 0;
$pending_evaluation = 0;
$overdue_evaluation = 0;

foreach ($deployed_students as $student) {
    if (!empty($student['evaluation_id'])) {
        $evaluated_students++;
    } else {
        $pending_evaluation++;
        
        // Check if evaluation is overdue (e.g., if OJT ended more than 7 days ago)
        if ($student['end_date'] && strtotime($student['end_date']) < strtotime('-7 days')) {
            $overdue_evaluation++;
        }
    }
}
// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_evaluation'])) {
    try {
        $student_id = (int)$_POST['student_id'];
        
        // Verify supervisor has access to this student
        $access_check = $conn->prepare("
            SELECT deployment_id, ojt_status FROM student_deployments 
            WHERE student_id = ? AND supervisor_id = ? AND status = 'Active'
        ");
        $access_check->bind_param("ii", $student_id, $supervisor_id);
        $access_check->execute();
        $access_result = $access_check->get_result();
        
        if ($access_result->num_rows === 0) {
            throw new Exception("You don't have permission to evaluate this student.");
        }
        
        $deployment_info = $access_result->fetch_assoc();
        $deployment_id = $deployment_info['deployment_id'];
        $access_check->close();
        
        // Check if evaluation already exists
        $existing_check = $conn->prepare("SELECT evaluation_id FROM student_evaluations WHERE student_id = ? AND supervisor_id = ?");
        $existing_check->bind_param("ii", $student_id, $supervisor_id);
        $existing_check->execute();
        $existing_result = $existing_check->get_result();
        
        if ($existing_result->num_rows > 0) {
            throw new Exception("Evaluation for this student already exists.");
        }
        $existing_check->close();
        
        // CSRF protection
        if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'])) {
            throw new Exception("Invalid security token. Please refresh the page and try again.");
        }
        
        // Get student and supervisor information for the form
        $student_info_stmt = $conn->prepare("SELECT * FROM students WHERE id = ?");
        $student_info_stmt->bind_param("i", $student_id);
        $student_info_stmt->execute();
        $student_info_result = $student_info_stmt->get_result();
        $student_info = $student_info_result->fetch_assoc();
        $student_info_stmt->close();
        
        // Validate and sanitize all competency inputs
        $competency_fields = [
            // Team Work (5 items)
            'teamwork_1', 'teamwork_2', 'teamwork_3', 'teamwork_4', 'teamwork_5',
            // Communication (4 items)
            'communication_6', 'communication_7', 'communication_8', 'communication_9',
            // Attendance & Punctuality (3 items)
            'attendance_10', 'attendance_11', 'attendance_12',
            // Productivity/Resilience (5 items)
            'productivity_13', 'productivity_14', 'productivity_15', 'productivity_16', 'productivity_17',
            // Initiative/Proactivity (6 items)
            'initiative_18', 'initiative_19', 'initiative_20', 'initiative_21', 'initiative_22', 'initiative_23',
            // Judgement/Decision Making (3 items)
            'judgement_24', 'judgement_25', 'judgement_26',
            // Dependability/Reliability (5 items)
            'dependability_27', 'dependability_28', 'dependability_29', 'dependability_30', 'dependability_31',
            // Attitude (5 items)
            'attitude_32', 'attitude_33', 'attitude_34', 'attitude_35', 'attitude_36',
            // Professionalism (4 items)
            'professionalism_37', 'professionalism_38', 'professionalism_39', 'professionalism_40'
        ];
        
        $competency_scores = [];
        foreach ($competency_fields as $field) {
            if (!isset($_POST[$field]) || !is_numeric($_POST[$field])) {
                throw new Exception("Missing or invalid value for {$field}");
            }
            
            $value = (int)$_POST[$field];
            if ($value < 0 || $value > 5) {
                throw new Exception("Invalid rating value for {$field}. Must be between 0 and 5.");
            }
            $competency_scores[$field] = $value;
        }
        
        // Calculate total score and interpretation
        $score_result = calculateEvaluationScore($competency_scores);
        
        // Sanitize text inputs
        $remarks_comments_suggestions = isset($_POST['remarks_comments_suggestions']) ? 
            substr(trim($_POST['remarks_comments_suggestions']), 0, 2000) : '';
        
        // Get form data for student information
        $student_name = $student_info['first_name'] . ' ' . 
                       ($student_info['middle_name'] ? $student_info['middle_name'] . ' ' : '') . 
                       $student_info['last_name'];
        $course_major = $student_info['program'];
        $cooperating_agency = $supervisor['company_name'];
        $agency_address = $supervisor['company_address'];
        $contact_number = $supervisor['phone_number'];
        $evaluator_name = $supervisor['full_name'];
        $evaluator_position = $supervisor['position'];
        
        // Get deployment dates and required hours for training period
        $deployment_stmt = $conn->prepare("SELECT start_date, end_date, required_hours FROM student_deployments WHERE deployment_id = ?");
        $deployment_stmt->bind_param("i", $deployment_id);
        $deployment_stmt->execute();
        $deployment_result = $deployment_stmt->get_result();
        $deployment_data = $deployment_result->fetch_assoc();
        $deployment_stmt->close();
        
        $training_period_start = $deployment_data['start_date'];
        $training_period_end = $deployment_data['end_date'] ?? date('Y-m-d');
        $total_hours_rendered = $deployment_data['required_hours'] ?? 0;
        
        // Use current date as evaluation period
        $period_start = date('Y-m-d');
        $period_end = date('Y-m-d');
        
        // Begin transaction
        $conn->autocommit(false);
        
        // Prepare the comprehensive insert statement
        $insert_stmt = $conn->prepare("
            INSERT INTO student_evaluations (
                student_id, supervisor_id, deployment_id,
                student_name, course_major, cooperating_agency, agency_address, contact_number,
                evaluator_name, evaluator_position, training_period_start, training_period_end,
                total_hours_rendered,
                teamwork_1, teamwork_2, teamwork_3, teamwork_4, teamwork_5,
                communication_6, communication_7, communication_8, communication_9,
                attendance_10, attendance_11, attendance_12,
                productivity_13, productivity_14, productivity_15, productivity_16, productivity_17,
                initiative_18, initiative_19, initiative_20, initiative_21, initiative_22, initiative_23,
                judgement_24, judgement_25, judgement_26,
                dependability_27, dependability_28, dependability_29, dependability_30, dependability_31,
                attitude_32, attitude_33, attitude_34, attitude_35, attitude_36,
                professionalism_37, professionalism_38, professionalism_39, professionalism_40,
                total_score, equivalent_rating, verbal_interpretation,
                remarks_comments_suggestions,
                evaluation_period_start, evaluation_period_end
            ) VALUES (
                ?, ?, ?, 
                ?, ?, ?, ?, ?, 
                ?, ?, ?, ?, 
                ?,
                ?, ?, ?, ?, ?, 
                ?, ?, ?, ?, 
                ?, ?, ?, 
                ?, ?, ?, ?, ?, 
                ?, ?, ?, ?, ?, ?, 
                ?, ?, ?, 
                ?, ?, ?, ?, ?, 
                ?, ?, ?, ?, ?, 
                ?, ?, ?, ?,
                ?, ?, ?, 
                ?,
                ?, ?
            )
        ");

        if (!$insert_stmt) {
            throw new Exception("Database error: " . $conn->error);
        }
        
        $insert_stmt->bind_param("iiisssssssssiiiiiiiiiiiiiiiiiiiiiiiiiiiiiiiiiiiiiiiiiiidsss",
            // Basic info (3 integers)
            $student_id, $supervisor_id, $deployment_id,
            
            // Text fields (9 strings) 
            $student_name, $course_major, $cooperating_agency, $agency_address, $contact_number,
            $evaluator_name, $evaluator_position, $training_period_start, $training_period_end,
            
            // Hours (1 integer)
            $total_hours_rendered,
            
            // All competency scores (39 integers)
            $competency_scores['teamwork_1'], $competency_scores['teamwork_2'], $competency_scores['teamwork_3'], $competency_scores['teamwork_4'], $competency_scores['teamwork_5'],
            $competency_scores['communication_6'], $competency_scores['communication_7'], $competency_scores['communication_8'], $competency_scores['communication_9'],
            $competency_scores['attendance_10'], $competency_scores['attendance_11'], $competency_scores['attendance_12'],
            $competency_scores['productivity_13'], $competency_scores['productivity_14'], $competency_scores['productivity_15'], $competency_scores['productivity_16'], $competency_scores['productivity_17'],
            $competency_scores['initiative_18'], $competency_scores['initiative_19'], $competency_scores['initiative_20'], $competency_scores['initiative_21'], $competency_scores['initiative_22'], $competency_scores['initiative_23'],
            $competency_scores['judgement_24'], $competency_scores['judgement_25'], $competency_scores['judgement_26'],
            $competency_scores['dependability_27'], $competency_scores['dependability_28'], $competency_scores['dependability_29'], $competency_scores['dependability_30'], $competency_scores['dependability_31'],
            $competency_scores['attitude_32'], $competency_scores['attitude_33'], $competency_scores['attitude_34'], $competency_scores['attitude_35'], $competency_scores['attitude_36'],
            $competency_scores['professionalism_37'], $competency_scores['professionalism_38'], $competency_scores['professionalism_39'], $competency_scores['professionalism_40'],
            
            // Calculated scores (1 integer, 1 double, 1 string)
            $score_result['total_score'], $score_result['equivalent_rating'], $score_result['verbal_interpretation'],
            
            // Remarks and dates (3 strings)
            $remarks_comments_suggestions,
            $period_start, $period_end
        );
        
        if ($insert_stmt->execute()) {
            $evaluation_id = $conn->insert_id;
            
            // Create notification for the student
            if (function_exists('createEvaluationNotification')) {
                createEvaluationNotification($conn, $student_id, $supervisor['full_name'], 
                    $score_result['total_score'], $score_result['equivalent_rating']);
            }
            
            // Commit transaction
            $conn->commit();
            $insert_stmt->close();
            
            $_SESSION['evaluation_success'] = "Student evaluation submitted successfully! " .
                "Total Score: " . $score_result['total_score'] . " (" . $score_result['verbal_interpretation'] . ")";
        } else {
            throw new Exception("Error saving evaluation: " . $insert_stmt->error);
        }
        
        header("Location: StudentEvaluate.php");
        exit();
        
    } catch (Exception $e) {
        // Rollback transaction
        if ($conn) {
            $conn->rollback();
        }
        
        error_log("Evaluation submission error: " . $e->getMessage());
        $_SESSION['evaluation_error'] = $e->getMessage();
        header("Location: StudentEvaluate.php");
        exit();
    } finally {
        if ($conn) {
            $conn->autocommit(true);
        }
    }
}

// Generate CSRF token
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$supervisor_signature = '';
if (!empty($supervisor['signature_image']) && file_exists($supervisor['signature_image'])) {
    $supervisor_signature = $supervisor['signature_image'];
}


function handleSignatureUpload($conn, $supervisor_id, $uploaded_file) {
    $upload_dir = 'uploads/signatures/';
    $allowed_types = ['image/jpeg', 'image/jpg', 'image/png', 'image/gif'];
    $max_file_size = 2 * 1024 * 1024; // 2MB

    // Create directory if it doesn't exist
    if (!file_exists($upload_dir)) {
        mkdir($upload_dir, 0755, true);
    }

    // Validate file
    if ($uploaded_file['error'] !== UPLOAD_ERR_OK) {
        throw new Exception('File upload error: ' . $uploaded_file['error']);
    }

    if ($uploaded_file['size'] > $max_file_size) {
        throw new Exception('File size too large. Maximum 2MB allowed.');
    }

    $file_type = mime_content_type($uploaded_file['tmp_name']);
    if (!in_array($file_type, $allowed_types)) {
        throw new Exception('Invalid file type. Only JPEG, PNG, and GIF allowed.');
    }

    // Generate unique filename
    $file_extension = pathinfo($uploaded_file['name'], PATHINFO_EXTENSION);
    $filename = 'signature_' . $supervisor_id . '_' . time() . '.' . $file_extension;
    $file_path = $upload_dir . $filename;

    // Move uploaded file
    if (!move_uploaded_file($uploaded_file['tmp_name'], $file_path)) {
        throw new Exception('Failed to save uploaded file.');
    }

    // Update database
    $stmt = $conn->prepare("UPDATE company_supervisors SET signature_image = ? WHERE supervisor_id = ?");
    $stmt->bind_param("si", $file_path, $supervisor_id);
    
    if (!$stmt->execute()) {
        // Clean up uploaded file if database update fails
        unlink($file_path);
        throw new Exception('Failed to update signature in database.');
    }

    $stmt->close();
    return $file_path;
}

// Add this to your main PHP file after the session check
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['upload_signature'])) {
    try {
        if (!isset($_FILES['signature_file']) || $_FILES['signature_file']['error'] === UPLOAD_ERR_NO_FILE) {
            throw new Exception('No signature file selected.');
        }

        $signature_path = handleSignatureUpload($conn, $supervisor_id, $_FILES['signature_file']);
        
        $_SESSION['signature_success'] = 'Signature uploaded successfully!';
        header("Location: " . $_SERVER['PHP_SELF']);
        exit();
        
    } catch (Exception $e) {
        $_SESSION['signature_error'] = $e->getMessage();
        header("Location: " . $_SERVER['PHP_SELF']);
        exit();
    }
}

// Add this to handle signature deletion
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_signature'])) {
    try {
        // Get current signature path
        $stmt = $conn->prepare("SELECT signature_image FROM company_supervisors WHERE supervisor_id = ?");
        $stmt->bind_param("i", $supervisor_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $current_signature = $result->fetch_assoc()['signature_image'];
        $stmt->close();

        // Delete file if exists
        if ($current_signature && file_exists($current_signature)) {
            unlink($current_signature);
        }

        // Update database
        $stmt = $conn->prepare("UPDATE company_supervisors SET signature_image = NULL WHERE supervisor_id = ?");
        $stmt->bind_param("i", $supervisor_id);
        $stmt->execute();
        $stmt->close();

        $_SESSION['signature_success'] = 'Signature deleted successfully!';
        header("Location: " . $_SERVER['PHP_SELF']);
        exit();
        
    } catch (Exception $e) {
        $_SESSION['signature_error'] = $e->getMessage();
        header("Location: " . $_SERVER['PHP_SELF']);
        exit();
    }
}

// Check for signature messages
$signature_success = isset($_SESSION['signature_success']) ? $_SESSION['signature_success'] : null;
$signature_error = isset($_SESSION['signature_error']) ? $_SESSION['signature_error'] : null;
unset($_SESSION['signature_success'], $_SESSION['signature_error']);

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>OnTheJob Tracker - Student Performance Appraisal</title>
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

        /* Optimized form styling for better printing */
        /* Improved form styling for better readability and printing */
.evaluation-form {
    font-family: 'Times New Roman', Times, serif;
    background: white;
    max-width: 90%; /* Much larger form width */
    margin: 0 auto;
    box-shadow: 0 0 10px rgba(0,0,0,0.1);
    font-size: 16px; /* Larger base font size */
    line-height: 1.4;
}

.form-header {
    text-align: center;
    padding: 20px;
    border-bottom: 2px solid #000;
}

/* Enhanced Form Header Styles */
.form-header {
    text-align: center;
    padding: 25px 20px;
    border-bottom: 2px solid #000;
    background: white;
    page-break-inside: avoid;
}

.form-logo {
    width: 80px;
    height: 80px;
    margin: 0 auto 20px;
    background: #f0f0f0;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 32px;
    color: #666;
}

.form-title {
    font-size: 26px;
    font-weight: bold;
    margin-bottom: 25px;
    text-transform: uppercase;
    letter-spacing: 2px;
    line-height: 1.2;
    color: #000;
}

.student-info {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 40px;
    padding: 20px 0;
    border-bottom: 1px solid #ccc;
    text-align: left;
    max-width: 100%;
    margin: 20px 0;
}

.left-column, .right-column {
    display: flex;
    flex-direction: column;
}

.info-item {
    font-size: 16px;
    margin-bottom: 10px;
    line-height: 1.6;
    word-wrap: break-word;
    overflow-wrap: break-word;
}

.info-label {
    font-weight: bold;
    display: inline-block;
    min-width: 160px;
    color: #000;
}

.directions-text {
    margin: 20px 0;
    padding: 15px;
    background-color: #f9f9f9;
    text-align: left;
    font-size: 15px;
    border: 1px solid #ddd;
    border-radius: 4px;
}

.rating-scale-table {
    width: 100%;
    border-collapse: collapse;
    margin: 15px 0;
    font-size: 14px;
}

.rating-scale-table th,
.rating-scale-table td {
    border: 1px solid #000;
    padding: 8px 10px;
    line-height: 1.4;
    vertical-align: top;
}

.rating-scale-table th {
    background-color: #f0f0f0;
    font-weight: bold;
    text-align: center;
}

.rating-scale-table td {
    text-align: left;
}

/* Responsive adjustments for form header */
@media (max-width: 768px) {
    .student-info {
        grid-template-columns: 1fr;
        gap: 20px;
    }
    
    .form-title {
        font-size: 20px;
        letter-spacing: 1px;
    }
    
    .info-label {
        min-width: 140px;
    }
    
    .info-item {
        font-size: 14px;
    }
}

.competency-table {
    width: 100%;
    border-collapse: collapse;
    margin-top: 20px;
}

.competency-table th,
.competency-table td {
    border: 1px solid #000;
    padding: 8px 6px; /* Larger padding */
    text-align: center;
    font-size: 15px; /* Much larger table font */
    line-height: 1.3;
}

.competency-table th {
    background-color: #f0f0f0;
    font-weight: bold;
    font-size: 16px;
}

.competency-header {
    background-color: #e0e0e0 !important;
    font-weight: bold;
    text-align: left;
    padding: 12px 15px !important; /* Much larger header padding */
    font-size: 17px !important; /* Larger header font */
}

.competency-item {
    text-align: left !important;  /* Force left alignment */
    padding-left: 15px !important; /* Maintain left padding for indentation */
    font-size: 22px !important; /* Increased font size from 15px */
    line-height: 1.4;
    vertical-align: middle; /* Center vertically in the cell */
}
.rating-cell {
    width: 45px; /* Larger rating cells */
    position: relative;
}

.rating-circle {
    width: 28px; /* Much larger circles */
    height: 28px;
    border: 2px solid #000;
    border-radius: 50%;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    cursor: pointer;
    font-weight: bold;
    background: white;
    transition: all 0.2s ease;
    font-size: 14px; /* Larger rating font */
}

.rating-circle:hover {
    background-color: #f0f0f0;
}

.rating-circle.selected {
    background-color: #000;
    color: white;
}

.scoring-reference {
    margin: 20px;
    padding: 15px;
    border: 1px solid #ccc;
}

.remarks-section {
    margin: 20px;
    padding: 15px;
    border: 1px solid #ccc;
}

.remarks-section h4 {
    font-size: 16px;
    margin-bottom: 10px;
}

.signature-section {
    margin: 20px;
    padding: 15px;
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 50px;
}

.print-button {
    position: fixed;
    top: 20px;
    right: 20px;
    z-index: 1000;
}

/* Directions and rating scale styling */
.directions-text {
    margin: 15px 0;
    padding: 12px;
    background-color: #f9f9f9;
    text-align: left;
    font-size: 15px;
    border: 1px solid #ddd;
}

.rating-scale-table {
    width: 100%;
    border-collapse: collapse;
    margin: 10px 0;
    font-size: 14px;
}

.rating-scale-table th,
.rating-scale-table td {
    border: 1px solid #000;
    padding: 6px 8px;
    line-height: 1.3;
}

.rating-scale-table th {
    background-color: #f0f0f0;
    font-weight: bold;
}

/* Scoring reference grid styling */
.scoring-grid {
    display: grid;
    grid-template-columns: 1fr 1fr 1fr;
    gap: 25px;
    margin-top: 15px;
}

.scoring-grid h4 {
    font-weight: bold;
    margin-bottom: 10px;
    text-align: center;
    font-size: 16px;
    text-decoration: underline;
}

.scoring-grid div:not(h4) {
    font-size: 14px;
    line-height: 1.5;
    text-align: center;
}

/* Form ID styling */
.form-id {
    margin: 20px;
    font-size: 12px;
    color: #666;
    text-align: center;
    border-top: 1px solid #ddd;
    padding-top: 15px;
}

/* Signature section improvements */
.signature-section div {
    text-align: center;
}

.signature-line {
    border-bottom: 2px solid #000;
    margin-bottom: 8px;
    height: 40px;
    display: flex;
    align-items: end;
    justify-content: center;
    padding-bottom: 8px;
    font-size: 14px;
}

.signature-label {
    font-size: 14px;
    font-weight: bold;
    margin-top: 5px;
}

/* Improved scoring reference grid styling */
.scoring-grid {
    display: grid;
    grid-template-columns: 1fr 1fr 1fr;
    gap: 25px;
    margin-top: 15px;
    align-items: start; /* Align items to the top */
}

.scoring-grid > div {
    display: flex;
    flex-direction: column;
    height: 100%; /* Ensure consistent height */
}

.scoring-grid h4 {
    font-weight: bold;
    margin-bottom: 10px;
    text-align: center;
    font-size: 16px;
    text-decoration: underline;
    min-height: 24px; /* Consistent header height */
    display: flex;
    align-items: center;
    justify-content: center;
}

.scoring-grid .score-content {
    font-size: 14px;
    line-height: 1.5;
    text-align: center;
    flex-grow: 1;
    display: flex;
    flex-direction: column;
    justify-content: flex-start;
}

/* Alternative table-based layout for better alignment */
.scoring-reference-table {
    width: 100%;
    border-collapse: collapse;
    margin-top: 15px;
    border: 1px solid #000;
}

.scoring-reference-table th,
.scoring-reference-table td {
    border: 1px solid #000;
    padding: 8px 6px;
    text-align: center;
    font-size: 14px;
    line-height: 1.3;
    vertical-align: top;
}

.scoring-reference-table th {
    background-color: #f0f0f0;
    font-weight: bold;
    font-size: 15px;
    text-decoration: underline;
}

.scoring-reference-table td {
    font-size: 13px;
}

/* Better responsive design */
@media (max-width: 1200px) {
    .evaluation-form {
        max-width: 95%;
        font-size: 15px;
    }
    
    .competency-table th,
    .competency-table td {
        font-size: 14px;
        padding: 6px 4px;
    }
}

@media (max-width: 768px) {
    .evaluation-form {
        font-size: 14px;
        max-width: 98%;
    }
    
    .student-info {
        grid-template-columns: 1fr;
        gap: 15px;
    }
    
    .competency-table th,
    .competency-table td {
        padding: 5px 3px;
        font-size: 13px;
    }
    
    .rating-circle {
        width: 24px;
        height: 24px;
        font-size: 12px;
    }
    
    .scoring-grid {
        grid-template-columns: 1fr;
        gap: 15px;
    }
}

/* BALANCED PRINT OPTIMIZATION - READABLE AND SPACE EFFICIENT */
@media print {
    /* Page setup for better space usage */
    @page {
        size: A4;
        margin: 0.4in 0.5in; /* Reasonable margins */
    }

    body {
        font-size: 10px !important; /* Readable base font */
        line-height: 1.1 !important;
        margin: 0;
        padding: 0;
    }

    .print-button, .sidebar, .no-print {
        display: none !important;
    }
    
    .evaluation-form {
        box-shadow: none;
        max-width: none !important;
        transform: scale(0.92) !important; /* Moderate scaling */
        transform-origin: top left;
        font-size: 11px !important;
        margin: 0;
        padding: 0;
    }
    
    /* Compact but readable header */
    .form-header {
        padding: 10px 8px !important;
        page-break-after: avoid;
    }
    
    .form-title {
        font-size: 16px !important;
        margin-bottom: 10px !important;
        letter-spacing: 1px !important;
    }
    
    /* Readable student info */
    .student-info {
        padding: 8px !important;
        gap: 15px !important;
        page-break-after: avoid;
        font-size: 10px !important;
    }
    
    .info-item {
        font-size: 10px !important;
        margin-bottom: 4px !important;
        line-height: 1.2 !important;
    }
    
    .info-label {
        min-width: 100px !important;
        font-size: 10px !important;
    }
    
    /* Readable directions */
    .directions-text {
        font-size: 9px !important;
        padding: 6px !important;
        margin: 6px 8px !important;
        background-color: #f9f9f9 !important;
        -webkit-print-color-adjust: exact !important;
        print-color-adjust: exact !important;
    }
    
    /* Readable rating scale table */
    .rating-scale-table {
        font-size: 8px !important;
        margin: 5px 0 !important;
    }
    
    .rating-scale-table th,
    .rating-scale-table td {
        padding: 2px 3px !important;
        line-height: 1.1 !important;
        border: 0.5px solid #000 !important;
    }
    
    .rating-scale-table th {
        background-color: #f0f0f0 !important;
        -webkit-print-color-adjust: exact !important;
        print-color-adjust: exact !important;
        font-size: 8px !important;
    }
    
    /* Compact but readable competency table */
    .competency-table {
        margin-top: 8px !important;
        font-size: 9px !important;
        border-collapse: collapse !important;
    }
    
    .competency-table th,
    .competency-table td {
        padding: 2px 3px !important;
        font-size: 9px !important;
        line-height: 1.1 !important;
        border: 0.5px solid #000 !important;
    }
    
    .competency-table th {
        font-size: 9px !important;
        background-color: #f0f0f0 !important;
        -webkit-print-color-adjust: exact !important;
        print-color-adjust: exact !important;
    }
    
    .competency-header {
        padding: 3px 5px !important;
        font-size: 9px !important;
        page-break-after: avoid;
        background-color: #e0e0e0 !important;
        -webkit-print-color-adjust: exact !important;
        print-color-adjust: exact !important;
    }
    
    .competency-item {
        padding: 2px 4px !important;
        font-size: 11px !important; /* Increased from 8px for better print readability */
        line-height: 1.2 !important;
        text-align: left !important; /* Ensure left alignment in print too */
    }
    
    /* Readable rating circles */
    .rating-cell {
        width: 16px !important;
        padding: 0 !important;
    }
    
    .rating-circle {
        width: 14px !important;
        height: 14px !important;
        font-size: 7px !important;
        border: 0.5px solid #000 !important;
        margin: 0 auto !important;
    }
    
    /* CRITICAL: Ensure selected ratings show in print */
    .rating-circle.selected {
        background-color: #000 !important;
        color: white !important;
        -webkit-print-color-adjust: exact !important;
        print-color-adjust: exact !important;
    }
    
    /* Readable scoring reference */
    .scoring-reference {
        margin: 6px 8px !important;
        padding: 4px !important;
        page-break-inside: avoid;
    }
    
    .scoring-grid {
        gap: 10px !important;
        page-break-inside: avoid;
    }
    
    .scoring-grid h4 {
        font-size: 9px !important;
        min-height: 12px !important;
        margin-bottom: 5px !important;
    }
    
    .scoring-grid .score-content {
        font-size: 8px !important;
    }
    
    .scoring-reference-table {
        font-size: 8px !important;
        page-break-inside: avoid;
    }
    
    .scoring-reference-table th,
    .scoring-reference-table td {
        padding: 2px 3px !important;
        border: 0.5px solid #000 !important;
        font-size: 8px !important;
    }
    
    .scoring-reference-table th {
        background-color: #f0f0f0 !important;
        -webkit-print-color-adjust: exact !important;
        print-color-adjust: exact !important;
    }
    
    /* Readable remarks section */
    .remarks-section {
        margin: 6px 8px !important;
        padding: 4px !important;
        page-break-inside: avoid;
    }
    
    .remarks-section h4 {
        font-size: 9px !important;
        margin-bottom: 3px !important;
    }
    
    .remarks-section textarea,
    .remarks-section div[style*="border"] {
        min-height: 25px !important;
        font-size: 8px !important;
        padding: 3px !important;
        border: 0.5px solid #000 !important;
        line-height: 1.2 !important;
    }
    
    /* Readable signature section */
    .signature-section {
        margin: 6px 8px !important;
        padding: 4px !important;
        gap: 15px !important;
        page-break-inside: avoid;
    }
    
    .signature-line {
        height: 20px !important;
        font-size: 8px !important;
        border-bottom: 0.5px solid #000 !important;
        margin-bottom: 3px !important;
        padding-bottom: 3px !important;
    }
    
    .signature-label {
        font-size: 8px !important;
        margin-top: 2px !important;
    }
    
    /* Readable form ID */
    .form-id {
        font-size: 7px !important;
        margin: 6px 8px !important;
        padding-top: 3px !important;
        border-top: 0.5px solid #ddd !important;
    }

    /* Prevent problematic page breaks */
    .competency-header {
        break-after: avoid;
        page-break-after: avoid;
    }
    
    .competency-table tr {
        page-break-inside: avoid;
        orphans: 2;
        widows: 2;
    }

    /* Ensure table borders are visible */
    table, th, td {
        border-collapse: collapse !important;
    }

    /* Maximum width utilization */
    .student-info,
    .competency-table,
    .rating-scale-table,
    .scoring-reference-table {
        width: 100% !important;
    }
}

/* Additional utility classes */
.text-center {
    text-align: center;
}

.font-bold {
    font-weight: bold;
}

.border-solid {
    border: 1px solid #000;
}

/* Textarea styling for remarks */
textarea {
    font-family: 'Times New Roman', Times, serif;
    font-size: 14px;
    line-height: 1.4;
    border: 2px solid #ccc;
    padding: 10px;
    border-radius: 4px;
    resize: vertical;
}

textarea:focus {
    outline: none;
    border-color: #007cba;
    box-shadow: 0 0 5px rgba(0, 124, 186, 0.3);
}

.signature-upload-section {
    margin-bottom: 20px;
    padding: 15px;
    border: 2px dashed #ccc;
    border-radius: 8px;
    text-align: center;
    background-color: #f9f9f9;
}

.signature-preview {
    max-width: 200px;
    max-height: 80px;
    border: 1px solid #ddd;
    margin: 10px auto;
    display: block;
}

.signature-image-display {
    max-width: 150px;
    max-height: 60px;
    object-fit: contain;
    margin: 0 auto;
}

/* Hide upload section when printing */
@media print {
    .signature-upload-section,
    .no-print {
        display: none !important;
    }
    
    .signature-image-display {
        max-width: 120px;
        max-height: 50px;
    }
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
            <a href="CompanyDashboard.php" class="nnav-item flex items-center px-3 py-2 text-sm font-medium text-bulsu-light-gold hover:text-white hover:bg-bulsu-gold hover:bg-opacity-20 rounded-md transition-all duration-200">
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
            <a href="StudentEvaluate.php" class="nav-item flex items-center px-3 py-2 text-sm font-medium text-white bg-bulsu-gold bg-opacity-20 border border-bulsu-gold border-opacity-30 rounded-md">
                <i class="fas fa-star mr-3  text-bulsu-gold"></i>
                Student Evaluation
            </a>
            <a href="CompanyMessage.php" class="nav-item flex items-center px-3 py-2 text-sm font-medium text-bulsu-light-gold hover:text-white hover:bg-bulsu-gold hover:bg-opacity-20 rounded-md transition-all duration-200">
                <i class="fas fa-envelope mr-3"></i>
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
        <!-- Print Button -->
        <?php if ($selected_student && ($is_viewing_evaluation || $can_submit_evaluation)): ?>
        <button onclick="window.print()" class="print-button bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg shadow-lg no-print">
            <i class="fas fa-print mr-2"></i>
            Print Form
        </button>
        <?php endif; ?>

        <!-- Header -->
        <div class="bg-white shadow-sm border-b border-gray-200 no-print">
            <div class="flex items-center justify-between px-4 sm:px-6 py-4">
                <!-- Mobile Menu Button -->
                <button id="mobileMenuBtn" class="lg:hidden p-2 rounded-md text-gray-500 hover:text-gray-900 hover:bg-gray-100">
                    <i class="fas fa-bars text-xl"></i>
                </button>

                <!-- Header Title -->
                <div class="flex-1 lg:ml-0 ml-4">
                    <h1 class="text-xl sm:text-2xl font-bold text-gray-900">Student Performance Appraisal</h1>
                    <p class="text-sm sm:text-base text-gray-500 hidden sm:block">One-time comprehensive evaluation for OJT students</p>
                </div>
                
                <!-- Back Button (when viewing specific student) -->
                <?php if ($selected_student): ?>
                <div class="mr-4">
                    <a href="StudentEvaluate.php" class="inline-flex items-center px-3 py-2 border border-gray-300 shadow-sm text-sm leading-4 font-medium rounded-md text-gray-700 bg-white hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500">
                        <i class="fas fa-arrow-left mr-2"></i>
                        Back to List
                    </a>
                </div>
                <?php endif; ?>
                
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

       <!-- Dashboard Cards -->
        <div class="px-6 py-4">
    <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-8 no-print">
        <!-- Total Students Card -->
        <div class="bg-white rounded-lg shadow-sm border-l-4 border-bulsu-maroon p-6 hover:shadow-md transition-all duration-200">
            <div class="flex items-center justify-between">
                <div class="flex-1">
                    <div class="flex items-center mb-3">
                        <div class="w-8 h-8 bg-blue-100 rounded-full flex items-center justify-center mr-3">
                            <i class="fas fa-users text-blue-600"></i>
                        </div>
                        <h3 class="text-sm font-semibold text-gray-700 uppercase tracking-wider">Total Students</h3>
                    </div>
                    <div class="flex items-baseline space-x-2">
                        <p class="text-3xl font-bold text-gray-900"><?php echo $total_students; ?></p>
                        <span class="text-sm text-gray-500">students</span>
                    </div>
                    <p class="text-xs text-gray-500 mt-2">Currently deployed under supervision</p>
                </div>
                <div class="w-16 h-16 bg-gradient-to-r from-blue-50 to-blue-100 rounded-full flex items-center justify-center">
                    <i class="fas fa-users text-blue-600 text-xl"></i>
                </div>
            </div>
        </div>

        <!-- Pending Evaluations Card -->
        <div class="bg-white rounded-lg shadow-sm border-l-4 border-yellow-500 p-6 hover:shadow-md transition-all duration-200">
            <div class="flex items-center justify-between">
                <div class="flex-1">
                    <div class="flex items-center mb-3">
                        <div class="w-8 h-8 bg-yellow-100 rounded-full flex items-center justify-center mr-3">
                            <i class="fas fa-clock text-yellow-600"></i>
                        </div>
                        <h3 class="text-sm font-semibold text-gray-700 uppercase tracking-wider">Pending Evaluation</h3>
                    </div>
                    <div class="flex items-baseline space-x-2 mb-2">
                        <p class="text-3xl font-bold text-gray-900"><?php echo $pending_evaluation; ?></p>
                        <span class="text-sm text-gray-500">
                            (<?php echo $total_students > 0 ? round(($pending_evaluation / $total_students) * 100) : 0; ?>%)
                        </span>
                    </div>
                    <?php if ($pending_evaluation > 0): ?>
                    <button onclick="filterStudents('pending')" 
                           class="inline-flex items-center text-xs text-yellow-600 hover:text-yellow-800 font-medium group">
                        <span>View pending students</span>
                        <i class="fas fa-arrow-right ml-1 group-hover:translate-x-1 transition-transform"></i>
                    </button>
                    <?php else: ?>
                    <p class="text-xs text-gray-500">All students evaluated</p>
                    <?php endif; ?>
                </div>
                <div class="w-16 h-16 bg-gradient-to-r from-yellow-50 to-yellow-100 rounded-full flex items-center justify-center">
                    <i class="fas fa-clock text-yellow-600 text-xl"></i>
                </div>
            </div>
        </div>

        <!-- Evaluated Students Card -->
        <div class="bg-white rounded-lg shadow-sm border-l-4 border-green-500 p-6 hover:shadow-md transition-all duration-200">
            <div class="flex items-center justify-between">
                <div class="flex-1">
                    <div class="flex items-center mb-3">
                        <div class="w-8 h-8 bg-green-100 rounded-full flex items-center justify-center mr-3">
                            <i class="fas fa-check-circle text-green-600"></i>
                        </div>
                        <h3 class="text-sm font-semibold text-gray-700 uppercase tracking-wider">Completed</h3>
                    </div>
                    <div class="flex items-baseline space-x-2 mb-2">
                        <p class="text-3xl font-bold text-gray-900"><?php echo $evaluated_students; ?></p>
                        <span class="text-sm text-gray-500">
                            (<?php echo $total_students > 0 ? round(($evaluated_students / $total_students) * 100) : 0; ?>%)
                        </span>
                    </div>
                    <?php if ($evaluated_students > 0): ?>
                    <button onclick="filterStudents('evaluated')" 
                           class="inline-flex items-center text-xs text-green-600 hover:text-green-800 font-medium group">
                        <span>View completed evaluations</span>
                        <i class="fas fa-arrow-right ml-1 group-hover:translate-x-1 transition-transform"></i>
                    </button>
                    <?php else: ?>
                    <p class="text-xs text-gray-500">No evaluations completed yet</p>
                    <?php endif; ?>
                </div>
                <div class="w-16 h-16 bg-gradient-to-r from-green-50 to-green-100 rounded-full flex items-center justify-center">
                    <i class="fas fa-check-circle text-green-600 text-xl"></i>
                </div>
            </div>
        </div>
    </div>
</div>


        <!-- Main Container -->
        <div class="p-4 sm:p-6 lg:p-8">
            <!-- Alert Messages -->
            <?php if ($submit_success): ?>
                <div class="mb-6 p-4 bg-green-50 border border-green-200 rounded-lg no-print">
                    <div class="flex items-start">
                        <i class="fas fa-check-circle text-green-600 mt-1 mr-3"></i>
                        <div>
                            <p class="text-green-700"><?php echo htmlspecialchars($submit_success); ?></p>
                            <button onclick="this.closest('.bg-green-50').style.display='none'" class="text-green-500 hover:text-green-700 text-sm mt-1">Dismiss</button>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

            <?php if ($submit_error): ?>
                <div class="mb-6 p-4 bg-red-50 border border-red-200 rounded-lg no-print">
                    <div class="flex items-start">
                        <i class="fas fa-exclamation-triangle text-red-600 mt-1 mr-3"></i>
                        <div>
                            <p class="text-red-700"><?php echo htmlspecialchars($submit_error); ?></p>
                            <button onclick="this.closest('.bg-red-50').style.display='none'" class="text-red-500 hover:text-red-700 text-sm mt-1">Dismiss</button>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

            <?php if (!$selected_student): ?>
            <!-- Students Table -->
           <!-- Students Table -->
<div class="bg-white rounded-lg shadow-sm border border-bulsu-maroon mb-6 sm:mb-8 no-print overflow-hidden">
    <!-- Header -->
    <div class="bg-gradient-to-r from-bulsu-maroon to-bulsu-dark-maroon px-6 py-4">
        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between space-y-4 sm:space-y-0">
            <!-- Title + Icon -->
            <div class="flex items-center">
                <i class="fas fa-users text-bulsu-gold mr-3"></i>
                <h3 class="text-lg font-medium text-white">Deployed Students</h3>
                <span class="ml-2 inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-bulsu-light-gold text-bulsu-dark-maroon">
                    <?php echo count($deployed_students); ?> Students
                </span>
            </div>
            <!-- Actions -->
            <div class="flex flex-col sm:flex-row space-y-2 sm:space-y-0 sm:space-x-3">
                <!-- Refresh Button -->
                <button onclick="refreshStudents()" 
                        class="flex items-center justify-center px-4 py-2 text-sm font-medium text-bulsu-dark-maroon bg-bulsu-light-gold hover:bg-bulsu-gold rounded-md transition-colors">
                    <i class="fas fa-sync-alt mr-2"></i>
                    <span class="hidden sm:inline">Refresh</span>
                </button>
                <!-- Search Box -->
                <div class="relative">
                    <input type="text" id="searchStudents" placeholder="Search students..." 
                           class="pl-10 pr-4 py-2 border border-bulsu-gold rounded-md text-sm focus:ring-2 focus:ring-bulsu-gold focus:border-bulsu-maroon">
                    <i class="fas fa-search absolute left-3 top-2.5 text-gray-400"></i>
                </div>
            </div>
        </div>
    </div>

                
                <div class="overflow-x-auto">
                    <?php if (empty($deployed_students)): ?>
                        <div class="text-center py-12">
                            <i class="fas fa-user-slash text-gray-400 text-4xl mb-4"></i>
                            <h4 class="text-lg font-medium text-gray-900 mb-2">No Students Found</h4>
                            <p class="text-gray-600">You currently have no students deployed under your supervision.</p>
                        </div>
                    <?php else: ?>
                        <table class="min-w-full divide-y divide-gray-200">
                            <thead class="bg-gray-50">
                                <tr>
                                    <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                        Student Name
                                    </th>
                                    <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                        Student ID
                                    </th>
                                    <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                        Position
                                    </th>
                                    <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                        Start Date
                                    </th>
                                    <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                        End Date
                                    </th>
                                    <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                        Status
                                    </th>
                                    <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                        Action
                                    </th>
                                </tr>
                            </thead>
                            <tbody class="bg-white divide-y divide-gray-200">
                                <?php foreach ($deployed_students as $student): ?>
    <?php
    $student_full_name = trim($student['first_name'] . ' ' . ($student['middle_name'] ? $student['middle_name'] . ' ' : '') . $student['last_name']);
    $has_evaluation = !empty($student['evaluation_id']);
    ?>
    <tr class="hover:bg-gray-50 student-row" 
        data-student-name="<?php echo strtolower($student_full_name); ?>"
        data-student-id="<?php echo strtolower($student['student_number']); ?>"
        data-program="<?php echo strtolower($student['program']); ?>"
        data-position="<?php echo strtolower($student['position']); ?>">
        <td class="px-6 py-4 whitespace-nowrap">
            <div class="flex items-center">
                <div class="flex-shrink-0 h-10 w-10">
                    <div class="h-10 w-10 rounded-full bg-gradient-to-r from-blue-400 to-purple-500 flex items-center justify-center text-white font-semibold text-sm">
                        <?php if (!empty($student['profile_picture']) && file_exists($student['profile_picture'])): ?>
                            <img class="h-10 w-10 rounded-full object-cover" src="<?php echo htmlspecialchars($student['profile_picture']); ?>" alt="Profile">
                        <?php else: ?>
                            <?php echo strtoupper(substr($student['first_name'], 0, 1) . substr($student['last_name'], 0, 1)); ?>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="ml-4">
                    <div class="text-sm font-medium text-gray-900"><?php echo htmlspecialchars($student_full_name); ?></div>
                    <div class="text-sm text-gray-500"><?php echo htmlspecialchars($student['program']); ?></div>
                </div>
            </div>
        </td>
        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
            <?php echo htmlspecialchars($student['student_number']); ?>
        </td>
        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
            <?php echo htmlspecialchars($student['position']); ?>
        </td>
        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
            <?php echo date('M j, Y', strtotime($student['start_date'])); ?>
        </td>
        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
            <?php echo $student['end_date'] ? date('M j, Y', strtotime($student['end_date'])) : 'Ongoing'; ?>
        </td>
        <td class="px-6 py-4 whitespace-nowrap">
            <?php if ($has_evaluation): ?>
                <div class="flex flex-col">
                    <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-green-100 text-green-800 mb-1">
                        <i class="fas fa-check-circle mr-1"></i>
                        Evaluated
                    </span>
                    <div class="text-xs text-gray-600">
                        Score: <?php echo $student['total_score']; ?> 
                        (<?php echo number_format($student['equivalent_rating'], 1); ?>%)
                    </div>
                </div>
            <?php else: ?>
                <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-yellow-100 text-yellow-800">
                    <i class="fas fa-clock mr-1"></i>
                    Pending
                </span>
            <?php endif; ?>
        </td>
        <td class="px-6 py-4 whitespace-nowrap text-sm font-medium">
            <?php if ($has_evaluation): ?>
                <a href="StudentEvaluate.php?student_id=<?php echo $student['student_id']; ?>&action=view" 
                   class="inline-flex items-center px-3 py-2 border border-transparent text-sm leading-4 font-medium rounded-md text-white bg-green-600 hover:bg-green-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-green-500">
                    <i class="fas fa-eye mr-2"></i>
                    View
                </a>
            <?php else: ?>
                <a href="StudentEvaluate.php?student_id=<?php echo $student['student_id']; ?>&action=evaluate" 
                   class="inline-flex items-center px-3 py-2 border border-transparent text-sm leading-4 font-medium rounded-md text-white bg-blue-600 hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500">
                    <i class="fas fa-star mr-2"></i>
                    Evaluate
                </a>
            <?php endif; ?>
        </td>
    </tr>
<?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                </div>
            </div>
            
            <?php else: ?>
            <!-- Evaluation Form Section -->
            <div class="evaluation-form">
                <!-- Form Header -->
                <div class="form-header">
    <div class="form-logo">
        <i class="fas fa-graduation-cap"></i>
    </div>
    <div class="form-title">INTERN'S PERFORMANCE APPRAISAL FORM</div>
    
    <!-- Student Information Grid - FIXED -->
    <div class="student-info">
        <div class="left-column">
            <div class="info-item">
                <span class="info-label">STUDENT NAME:</span> 
                <?php 
                echo htmlspecialchars(trim($selected_student['first_name'] . ' ' . 
                     ($selected_student['middle_name'] ? $selected_student['middle_name'] . ' ' : '') . 
                     $selected_student['last_name'])); 
                ?>
            </div>
            <div class="info-item">
                <span class="info-label">Cooperating Agency:</span> <?php echo htmlspecialchars($company_name); ?>
            </div>
            <div class="info-item">
                <span class="info-label">Address:</span> <?php echo htmlspecialchars($supervisor['company_address'] ?? 'N/A'); ?>
            </div>
            <div class="info-item">
                <span class="info-label">Evaluator:</span> <?php echo htmlspecialchars($supervisor_name); ?>
            </div>
            <div class="info-item">
                <span class="info-label">Training Period:</span> 
                <?php 
                echo date('F j, Y', strtotime($selected_student['start_date'])) . ' to ' . 
                     date('F j, Y', strtotime($selected_student['end_date'] ?? date('Y-m-d'))); 
                ?>
            </div>
        </div>
        <div class="right-column">
            <div class="info-item">
                <span class="info-label">COURSE/MAJOR:</span> <?php echo htmlspecialchars($selected_student['program']); ?>
            </div>
            <div class="info-item">
                <span class="info-label">Contact No.:</span> <?php echo htmlspecialchars($supervisor['phone_number'] ?? 'N/A'); ?>
            </div>
            <div class="info-item">
                <span class="info-label">Position:</span> <?php echo htmlspecialchars($selected_student['position']); ?>
            </div>
            <div class="info-item">
                <span class="info-label">Total Hours Rendered:</span> 
                <?php echo $selected_student['required_hours'] . ' HOURS'; ?>
            </div>
            <div class="info-item">
                <!-- Empty space for balance -->
                &nbsp;
            </div>
        </div>
    </div>
    
    <!-- Directions -->
    <div class="directions-text">
        <strong>Directions:</strong> Using the scale below, please encircle the rating that best describes the competencies of the intern.
    </div>
    
    <!-- Rating Scale Table -->
    <table class="rating-scale-table">
        <tr style="background-color: #f0f0f0;">
            <th style="width: 8%;">5</th>
            <th style="width: 20%;">Outstanding (O)</th>
            <th>Performance exceeds the required standard.</th>
        </tr>
        <tr>
            <td style="text-align: center; font-weight: bold;">4</td>
            <td style="text-align: center; font-weight: bold;">Very Satisfactory (VS)</td>
            <td>Performance fully met the training requirements. The intern performed what was expected of him/her.</td>
        </tr>
        <tr style="background-color: #f9f9f9;">
            <td style="text-align: center; font-weight: bold;">3</td>
            <td style="text-align: center; font-weight: bold;">Satisfactory (S)</td>
            <td>Performance met the required standards, the intern performed duties with minimal supervision.</td>
        </tr>
        <tr>
            <td style="text-align: center; font-weight: bold;">2</td>
            <td style="text-align: center; font-weight: bold;">Fair (F)</td>
            <td>Performance partially meets the required standard, observed to be less than satisfactory, a lot could be done better.</td>
        </tr>
        <tr style="background-color: #f9f9f9;">
            <td style="text-align: center; font-weight: bold;">1</td>
            <td style="text-align: center; font-weight: bold;">Needs Improvement (NI)</td>
            <td>Performance does not meet the required standard. Major improvement may be needed.</td>
        </tr>
        <tr>
            <td style="text-align: center; font-weight: bold;">0</td>
            <td style="text-align: center; font-weight: bold;">Not applicable N/A</td>
            <td>Performance indicator is not relevant to the training.</td>
        </tr>
    </table>
</div>

                <?php if ($is_viewing_evaluation): ?>
                    <!-- View Mode: Show existing evaluation -->
                    <!-- Competencies Table (View Mode) -->
                    <table class="competency-table">
                        <thead>
                            <tr>
                                <th style="width: 40%; text-align: left; padding-left: 10px;">COMPETENCIES</th>
                                <th style="width: 10%;">O<br>5</th>
                                <th style="width: 10%;">VS<br>4</th>
                                <th style="width: 10%;">S<br>3</th>
                                <th style="width: 10%;">F<br>2</th>
                                <th style="width: 10%;">NI<br>1</th>
                                <th style="width: 10%;">N/A<br>0</th>
                            </tr>
                        </thead>
                        <tbody>
                            <!-- Team Work -->
                            <tr>
                                <td colspan="7" class="competency-header">TEAM WORK</td>
                            </tr>
                            <?php 
                            $teamwork_items = [
                                'teamwork_1' => '1. Consistently works with others to accomplish goals and tasks.',
                                'teamwork_2' => '2. Treats all team members in respectful and courteous manner.',
                                'teamwork_3' => '3. Actively participates in discussions and assigned tasks.',
                                'teamwork_4' => '4. Willingly works with team members to continuously improve team collaboration.',
                                'teamwork_5' => '5. Considers feedbacks and views of team members when completing assigned tasks.'
                            ];
                            
                            foreach ($teamwork_items as $field => $description): ?>
                                <tr>
                                    <td class="competency-item"><?php echo $description; ?></td>
                                    <?php for($i = 5; $i >= 0; $i--): ?>
                                        <td class="rating-cell">
                                            <div class="rating-circle <?php echo ($existing_evaluation[$field] == $i) ? 'selected' : ''; ?>">
                                                <?php echo $i; ?>
                                            </div>
                                        </td>
                                    <?php endfor; ?>
                                </tr>
                            <?php endforeach; ?>

                            <!-- Communication -->
                            <tr>
                                <td colspan="7" class="competency-header">COMMUNICATION</td>
                            </tr>
                            <?php 
                            $communication_items = [
                                'communication_6' => '6. Listens conscientiously to supervisor and co-workers.',
                                'communication_7' => '7. Comprehends written and oral information.',
                                'communication_8' => '8. Consistently delivers accurate information.',
                                'communication_9' => '9. Reliably provides feedback as required, both internally and externally.'
                            ];
                            
                            foreach ($communication_items as $field => $description): ?>
                                <tr>
                                    <td class="competency-item"><?php echo $description; ?></td>
                                    <?php for($i = 5; $i >= 0; $i--): ?>
                                        <td class="rating-cell">
                                            <div class="rating-circle <?php echo ($existing_evaluation[$field] == $i) ? 'selected' : ''; ?>">
                                                <?php echo $i; ?>
                                            </div>
                                        </td>
                                    <?php endfor; ?>
                                </tr>
                            <?php endforeach; ?>

                            <!-- Attendance & Punctuality -->
                            <tr>
                                <td colspan="7" class="competency-header">ATTENDANCE & PUNCTUALITY</td>
                            </tr>
                            <?php 
                            $attendance_items = [
                                'attendance_10' => '10. Is consistently punctual.',
                                'attendance_11' => '11. Maintains good attendance and participation.',
                                'attendance_12' => '12. Informs supervisor promptly if absent or late.'
                            ];
                            
                            foreach ($attendance_items as $field => $description): ?>
                                <tr>
                                    <td class="competency-item"><?php echo $description; ?></td>
                                    <?php for($i = 5; $i >= 0; $i--): ?>
                                        <td class="rating-cell">
                                            <div class="rating-circle <?php echo ($existing_evaluation[$field] == $i) ? 'selected' : ''; ?>">
                                                <?php echo $i; ?>
                                            </div>
                                        </td>
                                    <?php endfor; ?>
                                </tr>
                            <?php endforeach; ?>

                            <!-- Productivity/Resilience -->
                            <tr>
                                <td colspan="7" class="competency-header">PRODUCTIVITY/RESILIENCE</td>
                            </tr>
                            <?php 
                            $productivity_items = [
                                'productivity_13' => '13. Consistently delivers quality results.',
                                'productivity_14' => '14. Meets deadlines and manages time well.',
                                'productivity_15' => '15. Works around problems and obstacles in a stressful situation in order to achieve required tasks.',
                                'productivity_16' => '16. Time management is effective and efficient.',
                                'productivity_17' => '17. Informs supervisor of any challenges or barriers that transpire in tasks.'
                            ];
                            
                            foreach ($productivity_items as $field => $description): ?>
                                <tr>
                                    <td class="competency-item"><?php echo $description; ?></td>
                                    <?php for($i = 5; $i >= 0; $i--): ?>
                                        <td class="rating-cell">
                                            <div class="rating-circle <?php echo ($existing_evaluation[$field] == $i) ? 'selected' : ''; ?>">
                                                <?php echo $i; ?>
                                            </div>
                                        </td>
                                    <?php endfor; ?>
                                </tr>
                            <?php endforeach; ?>

                            <!-- Initiative/Proactivity -->
                            <tr>
                                <td colspan="7" class="competency-header">INITIATIVE/PROACTIVITY</td>
                            </tr>
                            <?php 
                            $initiative_items = [
                                'initiative_18' => '18. Completes assignments with minimal supervision.',
                                'initiative_19' => '19. Successfully completes tasks independently and accurately.',
                                'initiative_20' => '20. Seeks additional support when necessary.',
                                'initiative_21' => '21. Recognizes and takes appropriate action to effectively address problems.',
                                'initiative_22' => '22. Engages in continuous learning.',
                                'initiative_23' => '23. Contributes new ideas and seek ways to improve the organization or work place.'
                            ];
                            
                            foreach ($initiative_items as $field => $description): ?>
                                <tr>
                                    <td class="competency-item"><?php echo $description; ?></td>
                                    <?php for($i = 5; $i >= 0; $i--): ?>
                                        <td class="rating-cell">
                                            <div class="rating-circle <?php echo ($existing_evaluation[$field] == $i) ? 'selected' : ''; ?>">
                                                <?php echo $i; ?>
                                            </div>
                                        </td>
                                    <?php endfor; ?>
                                </tr>
                            <?php endforeach; ?>

                            <!-- Judgement/Decision Making -->
                            <tr>
                                <td colspan="7" class="competency-header">JUDGEMENT/DECISION-MAKING</td>
                            </tr>
                            <?php 
                            $judgement_items = [
                                'judgement_24' => '24. Analyzes problems effectively.',
                                'judgement_25' => '25. Demonstrates the ability to make creative and effective solutions to problems.',
                                'judgement_26' => '26. Demonstrates good judgement in handling routine problems.'
                            ];
                            
                            foreach ($judgement_items as $field => $description): ?>
                                <tr>
                                    <td class="competency-item"><?php echo $description; ?></td>
                                    <?php for($i = 5; $i >= 0; $i--): ?>
                                        <td class="rating-cell">
                                            <div class="rating-circle <?php echo ($existing_evaluation[$field] == $i) ? 'selected' : ''; ?>">
                                                <?php echo $i; ?>
                                            </div>
                                        </td>
                                    <?php endfor; ?>
                                </tr>
                            <?php endforeach; ?>

                            <!-- Dependability/Reliability -->
                            <tr>
                                <td colspan="7" class="competency-header">DEPENDABILITY/RELIABILITY</td>
                            </tr>
                            <?php 
                            $dependability_items = [
                                'dependability_27' => '27. Aptly follows instructions and meet expectations.',
                                'dependability_28' => '28. Adapts effectively to changes in the work environment.',
                                'dependability_29' => '29. Is personally accountable for his/her actions.',
                                'dependability_30' => '30. Adapts effectively to changes in the work environment.',
                                'dependability_31' => '31. Willingly offers assistance when needed.'
                            ];
                            
                            foreach ($dependability_items as $field => $description): ?>
                                <tr>
                                    <td class="competency-item"><?php echo $description; ?></td>
                                    <?php for($i = 5; $i >= 0; $i--): ?>
                                        <td class="rating-cell">
                                            <div class="rating-circle <?php echo ($existing_evaluation[$field] == $i) ? 'selected' : ''; ?>">
                                                <?php echo $i; ?>
                                            </div>
                                        </td>
                                    <?php endfor; ?>
                                </tr>
                            <?php endforeach; ?>

                            <!-- Attitude -->
                            <tr>
                                <td colspan="7" class="competency-header">ATTITUDE</td>
                            </tr>
                            <?php 
                            $attitude_items = [
                                'attitude_32' => '32. Makes positive contribution to the organization\'s morale.',
                                'attitude_33' => '33. Shows sensitivity to and consideration for other\'s feelings.',
                                'attitude_34' => '34. Accepts constructive criticism positively.',
                                'attitude_35' => '35. Shows pride in performing tasks.',
                                'attitude_36' => '36. Respects those in authority.'
                            ];
                            
                            foreach ($attitude_items as $field => $description): ?>
                                <tr>
                                    <td class="competency-item"><?php echo $description; ?></td>
                                    <?php for($i = 5; $i >= 0; $i--): ?>
                                        <td class="rating-cell">
                                            <div class="rating-circle <?php echo ($existing_evaluation[$field] == $i) ? 'selected' : ''; ?>">
                                                <?php echo $i; ?>
                                            </div>
                                        </td>
                                    <?php endfor; ?>
                                </tr>
                            <?php endforeach; ?>

                            <!-- Professionalism -->
                            <tr>
                                <td colspan="7" class="competency-header">PROFESSIONALISM</td>
                            </tr>
                            <?php 
                            $professionalism_items = [
                                'professionalism_37' => '37. Responsibly uses tools, equipment and machines.',
                                'professionalism_38' => '38. Follows all policies and procedures when issues and conflicts arise.',
                                'professionalism_39' => '39. Sticks with policies and procedures when issues and conflicts arise.',
                                'professionalism_40' => '40. Physical appearance is appropriate with the work environment and placement rules.',
                            ];
                            
                            foreach ($professionalism_items as $field => $description): ?>
                                <tr>
                                    <td class="competency-item"><?php echo $description; ?></td>
                                    <?php for($i = 5; $i >= 0; $i--): ?>
                                        <td class="rating-cell">
                                            <div class="rating-circle <?php echo ($existing_evaluation[$field] == $i) ? 'selected' : ''; ?>">
                                                <?php echo $i; ?>
                                            </div>
                                        </td>
                                    <?php endfor; ?>
                                </tr>
                            <?php endforeach; ?>

                            <!-- Total Score Row -->
                            <tr style="background-color: #f0f0f0; font-weight: bold;">
                                <td class="competency-item">Total Score /Equivalent rating</td>
                                <td colspan="6" style="text-align: center; font-size: 12px;">
                                    <?php echo $existing_evaluation['total_score']; ?> / <?php echo number_format($existing_evaluation['equivalent_rating'], 0); ?>%
                                </td>
                            </tr>
                        </tbody>
                    </table>

<!-- Scoring Reference -->
<div class="scoring-reference">
    <table class="scoring-reference-table">
        <thead>
            <tr>
                <th>Raw score</th>
                <th>Equivalent rating</th>
                <th>Verbal interpretation</th>
            </tr>
        </thead>
        <tbody>
            <tr><td>181 - 200</td><td>97 - 100</td><td>Outstanding</td></tr>
            <tr><td>161 - 180</td><td>94 - 96</td><td>Excellent</td></tr>
            <tr><td>141 - 160</td><td>91 - 93</td><td>Excellent</td></tr>
            <tr><td>121 - 140</td><td>88 - 90</td><td>Very Good</td></tr>
            <tr><td>101 - 120</td><td>85 - 87</td><td>Good</td></tr>
            <tr><td>81 - 100</td><td>82 - 84</td><td>Fair</td></tr>
            <tr><td>61 - 80</td><td>79 - 81</td><td>Fair</td></tr>
            <tr><td>41 - 60</td><td>76 - 78</td><td>Passed</td></tr>
            <tr><td>21 - 40</td><td>75</td><td>Passed</td></tr>
            <tr><td>0 - 20</td><td>74</td><td>Conditional Passed</td></tr>
        </tbody>
    </table>
</div>

                    <!-- Remarks Section -->
                    <div class="remarks-section">
                        <h4 style="font-weight: bold; margin-bottom: 8px;">Remarks/Comments/Suggestions:</h4>
                        <div style="border: 1px solid #ccc; padding: 8px; min-height: 60px; background: #f9f9f9; font-size: 10px;">
                            <?php echo $existing_evaluation['remarks_comments_suggestions'] ? htmlspecialchars($existing_evaluation['remarks_comments_suggestions']) : 'No remarks provided.'; ?>
                        </div>
                    </div>

                    <!-- Signature Section -->
                    <div class="signature-section">
                        <div>
                            <div style="border-bottom: 1px solid #000; margin-bottom: 5px; height: 30px; display: flex; align-items: end; justify-content: center; padding-bottom: 5px;">
                                <span style="font-size: 11px; font-weight: bold;"><?php echo htmlspecialchars($existing_evaluation['evaluator_name']); ?></span>
                            </div>
                            <div style="text-align: center; font-size: 10px;">
                                <strong>Evaluator's Signature</strong>
                            </div>
                        </div>
                        <div>
                            <div style="border-bottom: 1px solid #000; margin-bottom: 5px; height: 30px; display: flex; align-items: end; justify-content: center; padding-bottom: 5px;">
                                <span style="font-size: 11px;"><?php echo date('n/j/Y', strtotime($existing_evaluation['created_at'])); ?></span>
                            </div>
                            <div style="text-align: center; font-size: 10px;">
                                <strong>Date</strong>
                            </div>
                        </div>
                    </div>

                    <!-- Form ID -->
                    <div style="margin: 15px; font-size: 9px; color: #666; text-align: center;">
                        BulSU-OP-OSI-16F7 | Revision 0<br>
                        Evaluation Date: <?php echo date('F j, Y \a\t g:i A', strtotime($existing_evaluation['created_at'])); ?>
                    </div>

                <?php else: ?>
                    <!-- Edit Mode: Interactive evaluation form -->
                    <form method="POST" action="StudentEvaluate.php" id="evaluationForm">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                        <input type="hidden" name="student_id" value="<?php echo $selected_student['student_id']; ?>">

                        <!-- Competencies Table (Interactive) -->
                        <table class="competency-table">
                            <thead>
                                <tr>
                                    <th style="width: 40%; text-align: left; padding-left: 10px;">COMPETENCIES</th>
                                    <th style="width: 10%;">O<br>5</th>
                                    <th style="width: 10%;">VS<br>4</th>
                                    <th style="width: 10%;">S<br>3</th>
                                    <th style="width: 10%;">F<br>2</th>
                                    <th style="width: 10%;">NI<br>1</th>
                                    <th style="width: 10%;">N/A<br>0</th>
                                </tr>
                            </thead>
                            <tbody>
                                <!-- Team Work -->
                                <tr>
                                    <td colspan="7" class="competency-header">TEAM WORK</td>
                                </tr>
                                <?php 
                                $teamwork_items = [
                                    'teamwork_1' => '1. Consistently works with others to accomplish goals and tasks.',
                                    'teamwork_2' => '2. Treats all team members in respectful and courteous manner.',
                                    'teamwork_3' => '3. Actively participates in discussions and assigned tasks.',
                                    'teamwork_4' => '4. Willingly works with team members to continuously improve team collaboration.',
                                    'teamwork_5' => '5. Considers feedbacks and views of team members when completing assigned tasks.'
                                ];
                                
                                foreach ($teamwork_items as $field => $description): ?>
                                    <tr>
                                        <td class="competency-item"><?php echo $description; ?></td>
                                        <?php for($i = 5; $i >= 0; $i--): ?>
                                            <td class="rating-cell">
                                                <div class="rating-circle" data-field="<?php echo $field; ?>" data-value="<?php echo $i; ?>">
                                                    <?php echo $i; ?>
                                                </div>
                                            </td>
                                        <?php endfor; ?>
                                        <input type="hidden" name="<?php echo $field; ?>" id="<?php echo $field; ?>" required>
                                    </tr>
                                <?php endforeach; ?>

                                <!-- Continue with all other competency sections... -->
                                <!-- Communication -->
                                <tr>
                                    <td colspan="7" class="competency-header">COMMUNICATION</td>
                                </tr>
                                <?php 
                                $communication_items = [
                                    'communication_6' => '6. Listens conscientiously to supervisor and co-workers.',
                                    'communication_7' => '7. Comprehends written and oral information.',
                                    'communication_8' => '8. Consistently delivers accurate information.',
                                    'communication_9' => '9. Reliably provides feedback as required, both internally and externally.'
                                ];
                                
                                foreach ($communication_items as $field => $description): ?>
                                    <tr>
                                        <td class="competency-item"><?php echo $description; ?></td>
                                        <?php for($i = 5; $i >= 0; $i--): ?>
                                            <td class="rating-cell">
                                                <div class="rating-circle" data-field="<?php echo $field; ?>" data-value="<?php echo $i; ?>">
                                                    <?php echo $i; ?>
                                                </div>
                                            </td>
                                        <?php endfor; ?>
                                        <input type="hidden" name="<?php echo $field; ?>" id="<?php echo $field; ?>" required>
                                    </tr>
                                <?php endforeach; ?>

                                <!-- Attendance & Punctuality -->
                                <tr>
                                    <td colspan="7" class="competency-header">ATTENDANCE & PUNCTUALITY</td>
                                </tr>
                                <?php 
                                $attendance_items = [
                                    'attendance_10' => '10. Is consistently punctual.',
                                    'attendance_11' => '11. Maintains good attendance and participation.',
                                    'attendance_12' => '12. Informs supervisor promptly if absent or late.'
                                ];
                                
                                foreach ($attendance_items as $field => $description): ?>
                                    <tr>
                                        <td class="competency-item"><?php echo $description; ?></td>
                                        <?php for($i = 5; $i >= 0; $i--): ?>
                                            <td class="rating-cell">
                                                <div class="rating-circle" data-field="<?php echo $field; ?>" data-value="<?php echo $i; ?>">
                                                    <?php echo $i; ?>
                                                </div>
                                            </td>
                                        <?php endfor; ?>
                                        <input type="hidden" name="<?php echo $field; ?>" id="<?php echo $field; ?>" required>
                                    </tr>
                                <?php endforeach; ?>

                                <!-- Productivity/Resilience -->
                                <tr>
                                    <td colspan="7" class="competency-header">PRODUCTIVITY/RESILIENCE</td>
                                </tr>
                                <?php 
                                $productivity_items = [
                                    'productivity_13' => '13. Consistently delivers quality results.',
                                    'productivity_14' => '14. Meets deadlines and manages time well.',
                                    'productivity_15' => '15. Works around problems and obstacles in a stressful situation in order to achieve required tasks.',
                                    'productivity_16' => '16. Time management is effective and efficient.',
                                    'productivity_17' => '17. Informs supervisor of any challenges or barriers that transpire in tasks.'
                                ];
                                
                                foreach ($productivity_items as $field => $description): ?>
                                    <tr>
                                        <td class="competency-item"><?php echo $description; ?></td>
                                        <?php for($i = 5; $i >= 0; $i--): ?>
                                            <td class="rating-cell">
                                                <div class="rating-circle" data-field="<?php echo $field; ?>" data-value="<?php echo $i; ?>">
                                                    <?php echo $i; ?>
                                                </div>
                                            </td>
                                        <?php endfor; ?>
                                        <input type="hidden" name="<?php echo $field; ?>" id="<?php echo $field; ?>" required>
                                    </tr>
                                <?php endforeach; ?>

                                <!-- Initiative/Proactivity -->
                                <tr>
                                    <td colspan="7" class="competency-header">INITIATIVE/PROACTIVITY</td>
                                </tr>
                                <?php 
                                $initiative_items = [
                                    'initiative_18' => '18. Completes assignments with minimal supervision.',
                                    'initiative_19' => '19. Successfully completes tasks independently and accurately.',
                                    'initiative_20' => '20. Seeks additional support when necessary.',
                                    'initiative_21' => '21. Recognizes and takes appropriate action to effectively address problems.',
                                    'initiative_22' => '22. Engages in continuous learning.',
                                    'initiative_23' => '23. Contributes new ideas and seek ways to improve the organization or work place.'
                                ];
                                
                                foreach ($initiative_items as $field => $description): ?>
                                    <tr>
                                        <td class="competency-item"><?php echo $description; ?></td>
                                        <?php for($i = 5; $i >= 0; $i--): ?>
                                            <td class="rating-cell">
                                                <div class="rating-circle" data-field="<?php echo $field; ?>" data-value="<?php echo $i; ?>">
                                                    <?php echo $i; ?>
                                                </div>
                                            </td>
                                        <?php endfor; ?>
                                        <input type="hidden" name="<?php echo $field; ?>" id="<?php echo $field; ?>" required>
                                    </tr>
                                <?php endforeach; ?>

                                <!-- Judgement/Decision Making -->
                                <tr>
                                    <td colspan="7" class="competency-header">JUDGEMENT/DECISION-MAKING</td>
                                </tr>
                                <?php 
                                $judgement_items = [
                                    'judgement_24' => '24. Analyzes problems effectively.',
                                    'judgement_25' => '25. Demonstrates the ability to make creative and effective solutions to problems.',
                                    'judgement_26' => '26. Demonstrates good judgement in handling routine problems.'
                                ];
                                
                                foreach ($judgement_items as $field => $description): ?>
                                    <tr>
                                        <td class="competency-item"><?php echo $description; ?></td>
                                        <?php for($i = 5; $i >= 0; $i--): ?>
                                            <td class="rating-cell">
                                                <div class="rating-circle" data-field="<?php echo $field; ?>" data-value="<?php echo $i; ?>">
                                                    <?php echo $i; ?>
                                                </div>
                                            </td>
                                        <?php endfor; ?>
                                        <input type="hidden" name="<?php echo $field; ?>" id="<?php echo $field; ?>" required>
                                    </tr>
                                <?php endforeach; ?>

                                <!-- Dependability/Reliability -->
                                <tr>
                                    <td colspan="7" class="competency-header">DEPENDABILITY/RELIABILITY</td>
                                </tr>
                                <?php 
                                $dependability_items = [
                                    'dependability_27' => '27. Aptly follows instructions and meet expectations.',
                                    'dependability_28' => '28. Adapts effectively to changes in the work environment.',
                                    'dependability_29' => '29. Is personally accountable for his/her actions.',
                                    'dependability_30' => '30. Adapts effectively to changes in the work environment.',
                                    'dependability_31' => '31. Willingly offers assistance when needed.'
                                ];
                                
                                foreach ($dependability_items as $field => $description): ?>
                                    <tr>
                                        <td class="competency-item"><?php echo $description; ?></td>
                                        <?php for($i = 5; $i >= 0; $i--): ?>
                                            <td class="rating-cell">
                                                <div class="rating-circle" data-field="<?php echo $field; ?>" data-value="<?php echo $i; ?>">
                                                    <?php echo $i; ?>
                                                </div>
                                            </td>
                                        <?php endfor; ?>
                                        <input type="hidden" name="<?php echo $field; ?>" id="<?php echo $field; ?>" required>
                                    </tr>
                                <?php endforeach; ?>

                                <!-- Attitude -->
                                <tr>
                                    <td colspan="7" class="competency-header">ATTITUDE</td>
                                </tr>
                                <?php 
                                $attitude_items = [
                                    'attitude_32' => '32. Makes positive contribution to the organization\'s morale.',
                                    'attitude_33' => '33. Shows sensitivity to and consideration for other\'s feelings.',
                                    'attitude_34' => '34. Accepts constructive criticism positively.',
                                    'attitude_35' => '35. Shows pride in performing tasks.',
                                    'attitude_36' => '36. Respects those in authority.'
                                ];
                                
                                foreach ($attitude_items as $field => $description): ?>
                                    <tr>
                                        <td class="competency-item"><?php echo $description; ?></td>
                                        <?php for($i = 5; $i >= 0; $i--): ?>
                                            <td class="rating-cell">
                                                <div class="rating-circle" data-field="<?php echo $field; ?>" data-value="<?php echo $i; ?>">
                                                    <?php echo $i; ?>
                                                </div>
                                            </td>
                                        <?php endfor; ?>
                                        <input type="hidden" name="<?php echo $field; ?>" id="<?php echo $field; ?>" required>
                                    </tr>
                                <?php endforeach; ?>

                                <!-- Professionalism -->
                                <tr>
                                    <td colspan="7" class="competency-header">PROFESSIONALISM</td>
                                </tr>
                                <?php 
                                $professionalism_items = [
                                    'professionalism_37' => '37. Responsibly uses tools, equipment and machines.',
                                    'professionalism_38' => '38. Follows all policies and procedures when issues and conflicts arise.',
                                    'professionalism_39' => '39. Sticks with policies and procedures when issues and conflicts arise.',
                                    'professionalism_40' => '40. Physical appearance is appropriate with the work environment and placement rules.',
                                ];
                                
                                foreach ($professionalism_items as $field => $description): ?>
                                    <tr>
                                        <td class="competency-item"><?php echo $description; ?></td>
                                        <?php for($i = 5; $i >= 0; $i--): ?>
                                            <td class="rating-cell">
                                                <div class="rating-circle" data-field="<?php echo $field; ?>" data-value="<?php echo $i; ?>">
                                                    <?php echo $i; ?>
                                                </div>
                                            </td>
                                        <?php endfor; ?>
                                        <input type="hidden" name="<?php echo $field; ?>" id="<?php echo $field; ?>" required>
                                    </tr>
                                <?php endforeach; ?>

                                <!-- Total Score Row -->
                                <tr style="background-color: #f0f0f0; font-weight: bold;">
                                    <td class="competency-item">Total Score /Equivalent rating</td>
                                    <td colspan="6" style="text-align: center; font-size: 12px;" id="totalScoreDisplay">-- / --%</td>
                                </tr>
                            </tbody>
                        </table>

                        <!-- Scoring Reference -->
                       <!-- Scoring Reference -->
<div class="scoring-reference">
    <table class="scoring-reference-table">
        <thead>
            <tr>
                <th>Raw score</th>
                <th>Equivalent rating</th>
                <th>Verbal interpretation</th>
            </tr>
        </thead>
        <tbody>
            <tr><td>181 - 200</td><td>97 - 100</td><td>Outstanding</td></tr>
            <tr><td>161 - 180</td><td>94 - 96</td><td>Excellent</td></tr>
            <tr><td>141 - 160</td><td>91 - 93</td><td>Excellent</td></tr>
            <tr><td>121 - 140</td><td>88 - 90</td><td>Very Good</td></tr>
            <tr><td>101 - 120</td><td>85 - 87</td><td>Good</td></tr>
            <tr><td>81 - 100</td><td>82 - 84</td><td>Fair</td></tr>
            <tr><td>61 - 80</td><td>79 - 81</td><td>Fair</td></tr>
            <tr><td>41 - 60</td><td>76 - 78</td><td>Passed</td></tr>
            <tr><td>21 - 40</td><td>75</td><td>Passed</td></tr>
            <tr><td>0 - 20</td><td>74</td><td>Conditional Passed</td></tr>
        </tbody>
    </table>
</div>

                        <!-- Remarks Section -->
                        <div class="remarks-section">
                            <h4 style="font-weight: bold; margin-bottom: 8px;">Remarks/Comments/Suggestions:</h4>
                            <textarea 
                                name="remarks_comments_suggestions" 
                                id="remarks_comments_suggestions" 
                                rows="4" 
                                style="width: 100%; border: 1px solid #ccc; padding: 6px; font-size: 10px; font-family: 'Times New Roman', Times, serif;"
                                placeholder="Enter your remarks, comments, and suggestions here... (Optional)"
                                maxlength="2000"
                            ></textarea>
                        </div>

                        <!-- Signature Section -->
                        <div class="signature-section">
                            <div>
                                <div style="border-bottom: 1px solid #000; margin-bottom: 5px; height: 30px;"></div>
                                <div style="text-align: center; font-size: 10px;">
                                    <strong>Evaluator's Signature:</strong><br>
                                    <?php echo htmlspecialchars($supervisor_name); ?>
                                </div>
                            </div>
                            <div>
                                <div style="border-bottom: 1px solid #000; margin-bottom: 5px; height: 30px; display: flex; align-items: end; justify-content: center; padding-bottom: 5px;">
                                    <span style="font-size: 10px;" id="currentDate"><?php echo date('n/j/Y'); ?></span>
                                </div>
                                <div style="text-align: center; font-size: 10px;">
                                    <strong>Date:</strong>
                                </div>
                            </div>
                        </div>

                        <!-- Form ID -->
                        <div style="margin: 15px; font-size: 9px; color: #666; text-align: center;">
                            BulSU-OP-OSI-16F7<br>
                            Revision 0
                        </div>

                        <!-- Submit Button -->
                        <div class="text-center py-6 no-print">
                            <button 
                                type="submit" 
                                name="submit_evaluation"
                                class="bg-gradient-to-r from-blue-600 to-purple-600 hover:from-blue-700 hover:to-purple-700 text-white font-semibold py-3 px-8 rounded-lg transition-all duration-200 transform hover:scale-105 shadow-lg"
                                onclick="return confirmSubmission()"
                            >
                                <i class="fas fa-paper-plane mr-2"></i>
                                Submit Final Evaluation
                            </button>
                            <p class="text-xs text-gray-500 mt-2">This evaluation cannot be edited once submitted</p>
                        </div>
                    </form>
                <?php endif; ?>
            </div>
            <?php endif; ?>
        </div>
    </div>
    <script>
        // Mobile menu functionality
        const mobileMenuBtn = document.getElementById('mobileMenuBtn');
        const sidebar = document.getElementById('sidebar');
        const sidebarOverlay = document.getElementById('sidebarOverlay');
        const closeSidebar = document.getElementById('closeSidebar');

        function openSidebar() {
            sidebar.classList.remove('-translate-x-full');
            sidebarOverlay.classList.remove('hidden');
        }

        function closeSidebarFunc() {
            sidebar.classList.add('-translate-x-full');
            sidebarOverlay.classList.add('hidden');
        }

        mobileMenuBtn.addEventListener('click', openSidebar);
        closeSidebar.addEventListener('click', closeSidebarFunc);
        sidebarOverlay.addEventListener('click', closeSidebarFunc);

        // Profile dropdown functionality
        const profileBtn = document.getElementById('profileBtn');
        const profileDropdown = document.getElementById('profileDropdown');

        profileBtn.addEventListener('click', function(e) {
            e.stopPropagation();
            profileDropdown.classList.toggle('hidden');
        });

        document.addEventListener('click', function() {
            profileDropdown.classList.add('hidden');
        });

        // Rating system functionality (only for evaluation mode)
        <?php if ($can_submit_evaluation): ?>
        document.addEventListener('DOMContentLoaded', function() {
            const ratingCircles = document.querySelectorAll('.rating-circle');
            
            ratingCircles.forEach(function(circle) {
                circle.addEventListener('click', function() {
                    const field = this.getAttribute('data-field');
                    const value = this.getAttribute('data-value');
                    
                    // Remove selected class from all circles in this rating scale
                    const fieldCircles = document.querySelectorAll(`[data-field="${field}"]`);
                    fieldCircles.forEach(function(fc) {
                        fc.classList.remove('selected');
                    });
                    
                    // Add selected class to clicked circle
                    this.classList.add('selected');
                    
                    // Update hidden input
                    document.getElementById(field).value = value;
                    
                    // Update total score
                    calculateTotalScore();
                });
            });
        });

        // Calculate total score and update display
        function calculateTotalScore() {
            const competencyFields = [
                'teamwork_1', 'teamwork_2', 'teamwork_3', 'teamwork_4', 'teamwork_5',
                'communication_6', 'communication_7', 'communication_8', 'communication_9',
                'attendance_10', 'attendance_11', 'attendance_12',
                'productivity_13', 'productivity_14', 'productivity_15', 'productivity_16', 'productivity_17',
                'initiative_18', 'initiative_19', 'initiative_20', 'initiative_21', 'initiative_22', 'initiative_23',
                'judgement_24', 'judgement_25', 'judgement_26',
                'dependability_27', 'dependability_28', 'dependability_29', 'dependability_30', 'dependability_31',
                'attitude_32', 'attitude_33', 'attitude_34', 'attitude_35', 'attitude_36',
                'professionalism_37', 'professionalism_38', 'professionalism_39', 'professionalism_40'
            ];

            let totalScore = 0;
            let filledFields = 0;

            competencyFields.forEach(function(field) {
                const input = document.getElementById(field);
                if (input && input.value !== '') {
                    totalScore += parseInt(input.value);
                    filledFields++;
                }
            });

            if (filledFields === competencyFields.length) {
                // Calculate equivalent rating based on scoring ranges
                let equivalentRating = 74; // Default
                let interpretation = 'Conditional Passed';

                const scoringRanges = [
                    {min: 181, max: 200, rating_min: 97, rating_max: 100, interpretation: 'Outstanding'},
                    {min: 161, max: 180, rating_min: 94, rating_max: 96, interpretation: 'Excellent'},
                    {min: 141, max: 160, rating_min: 91, rating_max: 93, interpretation: 'Excellent'},
                    {min: 121, max: 140, rating_min: 88, rating_max: 90, interpretation: 'Very Good'},
                    {min: 101, max: 120, rating_min: 85, rating_max: 87, interpretation: 'Good'},
                    {min: 81, max: 100, rating_min: 82, rating_max: 84, interpretation: 'Fair'},
                    {min: 61, max: 80, rating_min: 79, rating_max: 81, interpretation: 'Fair'},
                    {min: 41, max: 60, rating_min: 76, rating_max: 78, interpretation: 'Passed'},
                    {min: 21, max: 40, rating_min: 75, rating_max: 75, interpretation: 'Passed'},
                    {min: 0, max: 20, rating_min: 74, rating_max: 74, interpretation: 'Conditional Passed'}
                ];

                for (let range of scoringRanges) {
                    if (totalScore >= range.min && totalScore <= range.max) {
                        equivalentRating = (range.rating_min + range.rating_max) / 2;
                        interpretation = range.interpretation;
                        break;
                    }
                }

                document.getElementById('totalScoreDisplay').textContent = 
                    totalScore + ' / ' + equivalentRating.toFixed(0) + '% (' + interpretation + ')';
            } else {
                document.getElementById('totalScoreDisplay').textContent = '-- / --%';
            }
        }

        // Form validation
        function confirmSubmission() {
            // Check if all required fields are filled
            const requiredFields = document.querySelectorAll('input[type="hidden"][required]');
            const emptyFields = [];
            
            requiredFields.forEach(function(field) {
                if (!field.value) {
                    emptyFields.push(field.name);
                }
            });
            
            if (emptyFields.length > 0) {
                alert('Please complete all rating fields before submitting the evaluation.\n\nMissing ratings: ' + emptyFields.length + ' fields');
                
                // Scroll to first empty field
                const firstEmptyField = document.getElementById(emptyFields[0]);
                if (firstEmptyField) {
                    firstEmptyField.closest('tr').scrollIntoView({ behavior: 'smooth', block: 'center' });
                }
                return false;
            }
            
            return confirm('Are you sure you want to submit this final evaluation?\n\nThis action cannot be undone and the evaluation cannot be edited once submitted.');
        }
        <?php endif; ?>

        // Logout confirmation
        function confirmLogout() {
            return confirm('Are you sure you want to logout?');
        }

        // Auto-hide success/error messages after 10 seconds
        setTimeout(function() {
            const alerts = document.querySelectorAll('.bg-green-50, .bg-red-50');
            alerts.forEach(function(alert) {
                if (alert.style.display !== 'none') {
                    alert.style.transition = 'opacity 0.5s ease-out';
                    alert.style.opacity = '0';
                    setTimeout(() => alert.style.display = 'none', 500);
                }
            });
        }, 10000);

        // Enhanced table responsiveness
        document.addEventListener('DOMContentLoaded', function() {
            // Add loading states for better UX
            const actionButtons = document.querySelectorAll('a[href*="student_id"]');
            actionButtons.forEach(button => {
                button.addEventListener('click', function() {
                    this.innerHTML = '<i class="fas fa-spinner fa-spin mr-2"></i>Loading...';
                    this.classList.add('opacity-75', 'cursor-not-allowed');
                });
            });
        });

        // Print optimization
        window.addEventListener('beforeprint', function() {
            // Hide any tooltips or dropdowns before printing
            document.getElementById('profileDropdown').classList.add('hidden');
        });

        // Page visibility change handler
        document.addEventListener('visibilitychange', function() {
            if (document.hidden) {
                // Page is hidden, save any draft data if needed
                console.log('Page hidden');
            } else {
                // Page is visible, refresh data if needed
                console.log('Page visible');
            }
        });

        function filterStudents(type) {
    const studentRows = document.querySelectorAll('.student-row');
    const searchInput = document.getElementById('searchStudents');
    
    // Clear search input
    if (searchInput) {
        searchInput.value = '';
    }
    
    studentRows.forEach(function(row) {
        let shouldShow = false;
        const hasEvaluation = row.querySelector('.bg-green-100'); // Has "Evaluated" badge
        const isPending = row.querySelector('.bg-yellow-100'); // Has "Pending" badge
        
        // Get end date to check for overdue
        const endDateCell = row.children[4]; // Assuming end date is in 5th column
        const endDateText = endDateCell.textContent.trim();
        const isOverdue = endDateText !== 'Ongoing' && isPending && 
                         new Date(endDateText) < new Date(Date.now() - 7 * 24 * 60 * 60 * 1000);
        
        switch(type) {
            case 'pending':
                shouldShow = isPending && !isOverdue;
                break;
            case 'evaluated':
                shouldShow = hasEvaluation;
                break;
            case 'overdue':
                shouldShow = isOverdue;
                break;
            default:
                shouldShow = true;
        }
        
        row.style.display = shouldShow ? '' : 'none';
    });
    
    // Update page title or add filter indicator
    const filterIndicator = document.getElementById('filterIndicator');
    if (!filterIndicator) {
        const header = document.querySelector('h1');
        const indicator = document.createElement('span');
        indicator.id = 'filterIndicator';
        indicator.className = 'ml-2 text-sm font-normal text-gray-500';
        header.appendChild(indicator);
    }
    
    const indicator = document.getElementById('filterIndicator');
    const filterText = {
        'pending': '(Showing Pending Evaluations)',
        'evaluated': '(Showing Evaluated Students)', 
        'overdue': '(Showing Overdue Evaluations)'
    };
    
    indicator.textContent = filterText[type] || '';
    
    // Add clear filter option
    if (type && !document.getElementById('clearFilter')) {
        const clearBtn = document.createElement('button');
        clearBtn.id = 'clearFilter';
        clearBtn.className = 'ml-2 text-xs text-blue-600 hover:text-blue-800';
        clearBtn.textContent = 'Clear Filter';
        clearBtn.onclick = function() {
            filterStudents(null);
            this.remove();
            indicator.textContent = '';
        };
        indicator.appendChild(clearBtn);
    }
}

        // Search functionality - Add this to your existing JavaScript section
const searchInput = document.getElementById('searchStudents');
if (searchInput) {
    searchInput.addEventListener('input', function() {
        const searchTerm = this.value.toLowerCase();
        const studentRows = document.querySelectorAll('.student-row');
        
        studentRows.forEach(function(row) {
            const studentName = row.getAttribute('data-student-name');
            const studentId = row.getAttribute('data-student-id');
            const program = row.getAttribute('data-program');
            const position = row.getAttribute('data-position');
            
            const matches = studentName.includes(searchTerm) || 
                           studentId.includes(searchTerm) || 
                           program.includes(searchTerm) ||
                           position.includes(searchTerm);
            
            row.style.display = matches ? '' : 'none';
        });
        
        // Show/hide "no results" message if needed
        const visibleRows = document.querySelectorAll('.student-row:not([style*="display: none"])');
        const noResultsMessage = document.getElementById('noResultsMessage');
        
        if (visibleRows.length === 0 && searchTerm.trim() !== '') {
            if (!noResultsMessage) {
                const tbody = document.querySelector('tbody');
                const noResultsRow = document.createElement('tr');
                noResultsRow.id = 'noResultsMessage';
                noResultsRow.innerHTML = `
                    <td colspan="7" class="px-6 py-12 text-center">
                        <div class="flex flex-col items-center justify-center">
                            <i class="fas fa-search text-gray-300 text-4xl mb-4"></i>
                            <h3 class="text-lg font-medium text-gray-900 mb-2">No Results Found</h3>
                            <p class="text-gray-500 max-w-md">No students match your search criteria. Try adjusting your search terms.</p>
                        </div>
                    </td>
                `;
                tbody.appendChild(noResultsRow);
            }
        } else if (noResultsMessage) {
            noResultsMessage.remove();
        }
    });
}

// Refresh Students Function
function refreshStudents() {
    // Add loading state to refresh button
    const refreshBtn = document.querySelector('button[onclick="refreshStudents()"]');
    const originalContent = refreshBtn.innerHTML;
    
    refreshBtn.innerHTML = '<i class="fas fa-spin fa-spinner mr-2"></i><span class="hidden sm:inline">Refreshing...</span>';
    refreshBtn.disabled = true;
    
    // Reload the page
    window.location.reload();
}
    </script>
</body>
</html>
               