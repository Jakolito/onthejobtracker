<?php
// Modified upload_document.php - Removed notification creation for academic advisers
include('connect.php');
session_start();

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

$user_id = $_SESSION['user_id'];
$response = ['success' => false, 'message' => ''];

try {
    // Check if form was submitted
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception('Invalid request method');
    }

    // Validate required fields
    if (!isset($_POST['document_id']) || !isset($_FILES['document_file'])) {
        throw new Exception('Missing required fields');
    }

    $document_id = intval($_POST['document_id']);
    $file = $_FILES['document_file'];

    // Validate file upload
    if ($file['error'] !== UPLOAD_ERR_OK) {
        throw new Exception('File upload failed');
    }

    // Validate file size (10MB max)
    $max_size = 10 * 1024 * 1024; // 10MB
    if ($file['size'] > $max_size) {
        throw new Exception('File size exceeds 10MB limit');
    }

    // Validate file type
    $allowed_types = [
        'application/pdf',
        'application/msword',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'image/jpeg',
        'image/jpg',
        'image/png'
    ];

    $file_type = $file['type'];
    if (!in_array($file_type, $allowed_types)) {
        throw new Exception('Invalid file type. Only PDF, DOC, DOCX, JPG, PNG files are allowed');
    }

    // Get file extension
    $file_extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    
    // Validate extension matches MIME type
    $valid_extensions = [
        'application/pdf' => 'pdf',
        'application/msword' => 'doc',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
        'image/jpeg' => ['jpg', 'jpeg'],
        'image/jpg' => ['jpg', 'jpeg'],
        'image/png' => 'png'
    ];

    $expected_ext = $valid_extensions[$file_type];
    if (is_array($expected_ext)) {
        if (!in_array($file_extension, $expected_ext)) {
            throw new Exception('File extension does not match file type');
        }
    } else {
        if ($file_extension !== $expected_ext) {
            throw new Exception('File extension does not match file type');
        }
    }

    // Get student information
    $student_stmt = $conn->prepare("SELECT first_name, middle_name, last_name, student_id FROM students WHERE id = ?");
    $student_stmt->bind_param("i", $user_id);
    $student_stmt->execute();
    $student_result = $student_stmt->get_result();
    
    if ($student_result->num_rows === 0) {
        throw new Exception('Student not found');
    }
    
    $student_info = $student_result->fetch_assoc();
    $student_name = trim($student_info['first_name'] . ' ' . 
                        ($student_info['middle_name'] ? $student_info['middle_name'] . ' ' : '') . 
                        $student_info['last_name']);
    $student_number = $student_info['student_id'];
    $student_stmt->close();

    // Verify document exists and get document info including name and description
    $doc_stmt = $conn->prepare("SELECT name, description FROM document_requirements WHERE id = ?");
    $doc_stmt->bind_param("i", $document_id);
    $doc_stmt->execute();
    $doc_result = $doc_stmt->get_result();
    
    if ($doc_result->num_rows === 0) {
        throw new Exception('Document requirement not found');
    }
    
    $document_info = $doc_result->fetch_assoc();
    $doc_name = $document_info['name'];
    $doc_description = $document_info['description'];
    $doc_stmt->close();

    // Create uploads directory if it doesn't exist
    $upload_dir = 'uploads/documents/' . $user_id . '/';
    if (!is_dir($upload_dir)) {
        if (!mkdir($upload_dir, 0755, true)) {
            throw new Exception('Failed to create upload directory');
        }
    }

    // Generate unique filename
    $original_filename = $file['name'];
    $unique_filename = $document_id . '_' . time() . '_' . uniqid() . '.' . $file_extension;
    $file_path = $upload_dir . $unique_filename;

    // Move uploaded file
    if (!move_uploaded_file($file['tmp_name'], $file_path)) {
        throw new Exception('Failed to save uploaded file');
    }

    // Check if student already has a submission for this document
    $check_stmt = $conn->prepare("SELECT id FROM student_documents WHERE student_id = ? AND document_id = ?");
    $check_stmt->bind_param("ii", $user_id, $document_id);
    $check_stmt->execute();
    $check_result = $check_stmt->get_result();

    $is_resubmission = false;
    
    if ($check_result->num_rows > 0) {
        // Update existing submission
        $existing = $check_result->fetch_assoc();
        $check_stmt->close();

        // Get old file path to delete it
        $old_file_stmt = $conn->prepare("SELECT file_path FROM student_documents WHERE id = ?");
        $old_file_stmt->bind_param("i", $existing['id']);
        $old_file_stmt->execute();
        $old_file_result = $old_file_stmt->get_result();
        
        if ($old_file_result->num_rows > 0) {
            $old_file = $old_file_result->fetch_assoc();
            if (file_exists($old_file['file_path'])) {
                unlink($old_file['file_path']); // Delete old file
            }
        }
        $old_file_stmt->close();

        // Update the record with name and description
        $update_stmt = $conn->prepare("
            UPDATE student_documents 
            SET file_path = ?, original_filename = ?, file_size = ?, file_type = ?, 
                status = 'pending', feedback = NULL, submitted_at = CURRENT_TIMESTAMP, 
                reviewed_at = NULL, reviewed_by = NULL, name = ?, description = ?
            WHERE id = ?
        ");
        $update_stmt->bind_param("ssisssi", $file_path, $original_filename, $file['size'], $file_type, $doc_name, $doc_description, $existing['id']);
        
        if (!$update_stmt->execute()) {
            throw new Exception('Failed to update document submission');
        }
        $update_stmt->close();
        
        $is_resubmission = true;
        $response['message'] = 'Document resubmitted successfully';
    } else {
        // Insert new submission with name and description
        $check_stmt->close();
        
        $insert_stmt = $conn->prepare("
            INSERT INTO student_documents (student_id, document_id, file_path, original_filename, file_size, file_type, status, submitted_at, name, description) 
            VALUES (?, ?, ?, ?, ?, ?, 'pending', CURRENT_TIMESTAMP, ?, ?)
        ");
        $insert_stmt->bind_param("iississs", $user_id, $document_id, $file_path, $original_filename, $file['size'], $file_type, $doc_name, $doc_description);
        
        if (!$insert_stmt->execute()) {
            throw new Exception('Failed to save document submission');
        }
        $insert_stmt->close();
        
        $response['message'] = 'Document uploaded successfully';
    }
    
    // REMOVED: All notification creation code for academic advisers has been removed
    // The document upload process now completes without creating notifications
    
    $response['success'] = true;

} catch (Exception $e) {
    $response['message'] = $e->getMessage();
    
    // Clean up uploaded file if it exists
    if (isset($file_path) && file_exists($file_path)) {
        unlink($file_path);
    }
}

// Helper function to format file size
function formatFileSize($size) {
    $units = ['B', 'KB', 'MB', 'GB'];
    $power = $size > 0 ? floor(log($size, 1024)) : 0;
    return number_format($size / pow(1024, $power), 2, '.', ',') . ' ' . $units[$power];
}

// Return JSON response for AJAX requests
if (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
    header('Content-Type: application/json');
    echo json_encode($response);
    exit();
}

// For regular form submission, redirect back to dashboard
if ($response['success']) {
    $_SESSION['upload_success'] = $response['message'];
    header("Location: studentdashboard.php");
} else {
    $_SESSION['upload_error'] = $response['message'];
    header("Location: studentdashboard.php");
}
exit();
?>