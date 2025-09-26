<?php
// get_face_descriptor.php - Get enrolled face descriptor for verification
include('connect.php');
session_start();

header('Content-Type: application/json');
header("Cache-Control: no-cache, no-store, must-revalidate");
header("Pragma: no-cache");
header("Expires: 0");

if (!isset($_SESSION['user_id'])) {
    echo json_encode([
        'success' => false,
        'message' => 'Not authenticated'
    ]);
    exit();
}

$user_id = $_SESSION['user_id'];

try {
    $stmt = $conn->prepare("SELECT face_encoding FROM students WHERE id = ?");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows > 0) {
        $student = $result->fetch_assoc();
        
        if (!empty($student['face_encoding'])) {
            echo json_encode([
                'success' => true,
                'face_encoding' => $student['face_encoding'],
                'message' => 'Face descriptor loaded'
            ]);
        } else {
            echo json_encode([
                'success' => false,
                'message' => 'No face encoding found'
            ]);
        }
    } else {
        echo json_encode([
            'success' => false,
            'message' => 'Student not found'
        ]);
    }
    
    $stmt->close();
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => 'Database error: ' . $e->getMessage()
    ]);
}
?>