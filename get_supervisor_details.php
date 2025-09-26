<?php
include('connect.php');
session_start();

// Check if user is logged in and is admin
if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'admin') {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
    exit;
}

// Set content type to JSON
header('Content-Type: application/json');

if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    echo json_encode(['success' => false, 'message' => 'Invalid supervisor ID']);
    exit;
}

$supervisor_id = (int)$_GET['id'];

try {
    // Get supervisor details
    $query = "SELECT * FROM company_supervisors WHERE supervisor_id = ?";
    $stmt = mysqli_prepare($conn, $query);
    
    if (!$stmt) {
        throw new Exception('Database preparation failed: ' . mysqli_error($conn));
    }
    
    mysqli_stmt_bind_param($stmt, "i", $supervisor_id);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    
    if ($supervisor = mysqli_fetch_assoc($result)) {
        echo json_encode([
            'success' => true,
            'supervisor' => $supervisor
        ]);
    } else {
        echo json_encode([
            'success' => false,
            'message' => 'Supervisor not found'
        ]);
    }
    
    mysqli_stmt_close($stmt);
    
} catch (Exception $e) {
    error_log("Error fetching supervisor details: " . $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => 'Database error occurred'
    ]);
}

mysqli_close($conn);
?>