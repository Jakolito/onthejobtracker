<?php
// process_signature_upload.php - Create this as a separate file
session_start();
include('connect.php');

header('Content-Type: application/json');

// Security checks
if (!isset($_SESSION['supervisor_id']) || $_SESSION['user_type'] !== 'supervisor') {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
    exit();
}

// CSRF protection
if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Invalid security token']);
    exit();
}

$supervisor_id = $_SESSION['supervisor_id'];
$action = $_POST['action'] ?? '';

try {
    if ($action === 'upload_signature') {
        // Handle signature upload
        if (!isset($_FILES['signature_file']) || $_FILES['signature_file']['error'] !== UPLOAD_ERR_OK) {
            throw new Exception('No file uploaded or upload error occurred.');
        }
        
        $file = $_FILES['signature_file'];
        
        // Validate file size (2MB max)
        if ($file['size'] > 2 * 1024 * 1024) {
            throw new Exception('File size too large. Maximum size is 2MB.');
        }
        
        // Validate file type
        $allowed_types = ['image/png', 'image/jpeg', 'image/jpg'];
        if (!in_array($file['type'], $allowed_types)) {
            throw new Exception('Invalid file type. Only PNG, JPG, and JPEG are allowed.');
        }
        
        // Create upload directory if it doesn't exist
        $upload_dir = 'uploads/signatures/';
        if (!is_dir($upload_dir)) {
            mkdir($upload_dir, 0755, true);
        }
        
        // Generate unique filename
        $extension = pathinfo($file['name'], PATHINFO_EXTENSION);
        $filename = 'signature_' . $supervisor_id . '_' . time() . '.' . $extension;
        $file_path = $upload_dir . $filename;
        
        // Move uploaded file
        if (!move_uploaded_file($file['tmp_name'], $file_path)) {
            throw new Exception('Failed to save uploaded file.');
        }
        
        // Get current signature to delete old file
        $current_stmt = $conn->prepare("SELECT signature_image FROM company_supervisors WHERE supervisor_id = ?");
        $current_stmt->bind_param("i", $supervisor_id);
        $current_stmt->execute();
        $current_result = $current_stmt->get_result();
        $current_data = $current_result->fetch_assoc();
        $current_stmt->close();
        
        // Delete old signature file if it exists
        if (!empty($current_data['signature_image']) && file_exists($current_data['signature_image'])) {
            unlink($current_data['signature_image']);
        }
        
        // Update database
        $update_stmt = $conn->prepare("UPDATE company_supervisors SET signature_image = ? WHERE supervisor_id = ?");
        $update_stmt->bind_param("si", $file_path, $supervisor_id);
        $update_stmt->execute();
        $update_stmt->close();
        
        echo json_encode(['success' => true, 'message' => 'Signature uploaded successfully']);
        
    } elseif ($action === 'delete_signature') {
        // Handle signature deletion
        
        // Get current signature path
        $stmt = $conn->prepare("SELECT signature_image FROM company_supervisors WHERE supervisor_id = ?");
        $stmt->bind_param("i", $supervisor_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $data = $result->fetch_assoc();
        $stmt->close();
        
        if (!empty($data['signature_image']) && file_exists($data['signature_image'])) {
            unlink($data['signature_image']);
        }
        
        // Update database to remove signature
        $update_stmt = $conn->prepare("UPDATE company_supervisors SET signature_image = NULL WHERE supervisor_id = ?");
        $update_stmt->bind_param("i", $supervisor_id);
        $update_stmt->execute();
        $update_stmt->close();
        
        echo json_encode(['success' => true, 'message' => 'Signature deleted successfully']);
        
    } else {
        throw new Exception('Invalid action specified.');
    }
    
} catch (Exception $e) {
    error_log("Signature processing error: " . $e->getMessage());
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>