<?php
include('connect.php');
session_start();

// Prevent caching
header("Cache-Control: no-cache, no-store, must-revalidate");
header("Pragma: no-cache");
header("Expires: 0");
header('Content-Type: application/json');

// Check if user is logged in and is an adviser
if (!isset($_SESSION['adviser_id']) || $_SESSION['user_type'] !== 'adviser') {
    echo json_encode(['success' => false, 'error' => 'Unauthorized access']);
    exit;
}

// Get JSON input
$json = file_get_contents('php://input');
$data = json_decode($json, true);

if (!$data) {
    echo json_encode(['success' => false, 'error' => 'Invalid request data']);
    exit;
}

// Validate required fields
// Validate required fields
$student_id = intval($data['student_id'] ?? 0);
$new_student_id = trim($data['new_student_id'] ?? ''); // New field
$first_name = trim($data['first_name'] ?? '');
$last_name = trim($data['last_name'] ?? '');
$email = trim($data['email'] ?? '');

if ($student_id <= 0 || empty($new_student_id) || empty($first_name) || empty($last_name) || empty($email)) {
    echo json_encode(['success' => false, 'error' => 'Missing required fields']);
    exit;
}

try {
    // Prepare update query
    $update_query = "UPDATE students SET 
    student_id = ?,
    first_name = ?,
    middle_name = ?,
    last_name = ?,
        email = ?,
        contact_number = ?,
        address = ?,
        department = ?,
        program = ?,
        year_level = ?,
        section = ?,
        gender = ?";
    
    // Only add date_of_birth if it's provided and not empty
    $params = [
            $new_student_id,

        $first_name,
        $data['middle_name'] ?? '',
        $last_name,
        $email,
        $data['contact_number'] ?? '',
        $data['address'] ?? '',
        $data['department'] ?? '',
        $data['program'] ?? '',
        $data['year_level'] ?? '',
        $data['section'] ?? '',
        $data['gender'] ?? ''
    ];
$types = "ssssssssssss"; // One more 's' for student_id
    
    if (!empty($data['date_of_birth'])) {
        $update_query .= ", date_of_birth = ?";
        $params[] = $data['date_of_birth'];
        $types .= "s";
    }
    
    $update_query .= " WHERE id = ? AND verified = 1";
    $params[] = $student_id;
    $types .= "i";
    
    $stmt = mysqli_prepare($conn, $update_query);
    
    if (!$stmt) {
        throw new Exception('Failed to prepare statement: ' . mysqli_error($conn));
    }
    
    // Bind parameters dynamically
    mysqli_stmt_bind_param($stmt, $types, ...$params);
    
    // Execute update
    if (mysqli_stmt_execute($stmt)) {
        $affected_rows = mysqli_stmt_affected_rows($stmt);
        mysqli_stmt_close($stmt);
        
        if ($affected_rows >= 0) { // Changed from > 0 to >= 0 to handle cases where no changes were made
            echo json_encode([
                'success' => true,
                'message' => 'Student information updated successfully'
            ]);
        } else {
            echo json_encode([
                'success' => false,
                'error' => 'Student not found'
            ]);
        }
    } else {
        throw new Exception('Failed to execute update: ' . mysqli_stmt_error($stmt));
    }
    
} catch (Exception $e) {
    error_log('Update student info error: ' . $e->getMessage());
    echo json_encode([
        'success' => false,
        'error' => 'Database error: ' . $e->getMessage()
    ]);
}
?>