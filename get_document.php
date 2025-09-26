<?php
include('connect.php');
session_start();

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit();
}

$user_id = $_SESSION['user_id'];

try {
    // Fetch document requirements
    $document_requirements = [];
    $submitted_documents = [];

    // Get all document requirements
    $doc_stmt = $conn->prepare("SELECT id, name, description, is_required, created_at FROM document_requirements ORDER BY is_required DESC, name ASC");
    $doc_stmt->execute();
    $doc_result = $doc_stmt->get_result();
    
    while ($row = $doc_result->fetch_assoc()) {
        $document_requirements[] = $row;
    }
    $doc_stmt->close();
    
    // Get submitted documents for this student
    $sub_stmt = $conn->prepare("
        SELECT dr.id as doc_id, sd.id as submission_id, sd.file_path, sd.submitted_at, sd.status, sd.feedback, sd.original_filename
        FROM document_requirements dr 
        LEFT JOIN student_documents sd ON dr.id = sd.document_id AND sd.student_id = ?
    ");
    $sub_stmt->bind_param("i", $user_id);
    $sub_stmt->execute();
    $sub_result = $sub_stmt->get_result();
    
    while ($row = $sub_result->fetch_assoc()) {
        $submitted_documents[$row['doc_id']] = [
            'submission_id' => $row['submission_id'],
            'file_path' => $row['file_path'],
            'submitted_at' => $row['submitted_at'],
            'status' => $row['status'],
            'feedback' => $row['feedback'],
            'original_filename' => $row['original_filename']
        ];
    }
    $sub_stmt->close();

    // Calculate statistics
    $total_documents = count($document_requirements);
    $required_documents = array_filter($document_requirements, function($doc) { return $doc['is_required']; });
    $total_required = count($required_documents);
    $submitted_count = count(array_filter($submitted_documents, function($sub) { return !empty($sub['submission_id']); }));
    $approved_count = count(array_filter($submitted_documents, function($sub) { return $sub['status'] === 'approved'; }));
    $pending_count = count(array_filter($submitted_documents, function($sub) { return $sub['status'] === 'pending'; }));
    $rejected_count = count(array_filter($submitted_documents, function($sub) { return $sub['status'] === 'rejected'; }));

    $completion_percentage = $total_required > 0 ? round(($approved_count / $total_required) * 100, 1) : 0;

    // Prepare response
    $response = [
        'success' => true,
        'documents' => $document_requirements,
        'submissions' => $submitted_documents,
        'statistics' => [
            'total_documents' => $total_documents,
            'total_required' => $total_required,
            'submitted_count' => $submitted_count,
            'approved_count' => $approved_count,
            'pending_count' => $pending_count,
            'rejected_count' => $rejected_count,
            'completion_percentage' => $completion_percentage
        ],
        'last_updated' => date('c') // ISO 8601 format
    ];

    header('Content-Type: application/json');
    echo json_encode($response);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
?>