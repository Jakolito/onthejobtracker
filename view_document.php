<?php
include('connect.php');
session_start();

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

$user_id = $_SESSION['user_id'];

// Get submission ID from URL
if (!isset($_GET['id']) || empty($_GET['id'])) {
    header("Location: studentdashboard.php");
    exit();
}

$submission_id = (int)$_GET['id'];

// Fetch document details and verify ownership
try {
    $stmt = $conn->prepare("
        SELECT sd.*, dr.name as document_name, dr.description, s.first_name, s.last_name
        FROM student_documents sd 
        JOIN document_requirements dr ON sd.document_id = dr.id 
        JOIN students s ON sd.student_id = s.id
        WHERE sd.id = ? AND sd.student_id = ?
    ");
    $stmt->bind_param("ii", $submission_id, $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows === 0) {
        $_SESSION['upload_error'] = "Document not found or access denied.";
        header("Location: studentdashboard.php");
        exit();
    }
    
    $document = $result->fetch_assoc();
    $stmt->close();
    
    // Build full name
    $full_name = $document['first_name'] . ' ' . $document['last_name'];
    
    // Check if file exists
    $file_path = $document['file_path'];
    if (!file_exists($file_path)) {
        $_SESSION['upload_error'] = "Document file not found on server.";
        header("Location: studentdashboard.php");
        exit();
    }
    
    // Get file info
    $file_info = pathinfo($file_path);
    $file_extension = strtolower($file_info['extension']);
    $file_size = filesize($file_path);
    $mime_type = mime_content_type($file_path);
    
    // Determine if file can be displayed inline
    $viewable_types = ['pdf', 'jpg', 'jpeg', 'png', 'gif', 'txt'];
    $is_viewable = in_array($file_extension, $viewable_types);
    
} catch (Exception $e) {
    $_SESSION['upload_error'] = "Error accessing document: " . $e->getMessage();
    header("Location: studentdashboard.php");
    exit();
}

// Handle download request
if (isset($_GET['download']) && $_GET['download'] === '1') {
    // Set headers for file download
    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="' . $document['original_filename'] . '"');
    header('Content-Length: ' . $file_size);
    header('Cache-Control: must-revalidate');
    header('Pragma: public');
    
    // Output file
    readfile($file_path);
    exit();
}

// Function to format file size
function formatFileSize($bytes) {
    if ($bytes >= 1073741824) {
        return number_format($bytes / 1073741824, 2) . ' GB';
    } elseif ($bytes >= 1048576) {
        return number_format($bytes / 1048576, 2) . ' MB';
    } elseif ($bytes >= 1024) {
        return number_format($bytes / 1024, 2) . ' KB';
    } else {
        return $bytes . ' bytes';
    }
}

// Function to get status badge color
function getStatusColor($status) {
    switch ($status) {
        case 'approved': return '#28a745';
        case 'rejected': return '#dc3545';
        case 'pending': return '#ffc107';
        default: return '#6c757d';
    }
}

// Function to get file icon
function getFileIcon($extension) {
    switch (strtolower($extension)) {
        case 'pdf': return 'fas fa-file-pdf';
        case 'doc':
        case 'docx': return 'fas fa-file-word';
        case 'jpg':
        case 'jpeg':
        case 'png':
        case 'gif': return 'fas fa-file-image';
        case 'txt': return 'fas fa-file-alt';
        default: return 'fas fa-file';
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>View Document - <?php echo htmlspecialchars($document['document_name']); ?></title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            padding: 20px;
        }

        .container {
            max-width: 1200px;
            margin: 0 auto;
            background: white;
            border-radius: 15px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.2);
            overflow: hidden;
        }

        .header {
            background: linear-gradient(135deg, #2c3e50 0%, #3498db 100%);
            color: white;
            padding: 25px 30px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 15px;
        }

        .header-info h1 {
            font-size: 1.5rem;
            margin-bottom: 5px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .header-info p {
            opacity: 0.9;
            font-size: 0.9rem;
        }

        .header-actions {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
        }

        .btn {
            padding: 10px 20px;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            font-size: 0.9rem;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            transition: all 0.3s ease;
            font-weight: 500;
        }

        .btn-primary {
            background: #007bff;
            color: white;
        }

        .btn-primary:hover {
            background: #0056b3;
            transform: translateY(-2px);
        }

        .btn-success {
            background: #28a745;
            color: white;
        }

        .btn-success:hover {
            background: #218838;
            transform: translateY(-2px);
        }

        .btn-secondary {
            background: rgba(255,255,255,0.2);
            color: white;
            border: 1px solid rgba(255,255,255,0.3);
        }

        .btn-secondary:hover {
            background: rgba(255,255,255,0.3);
            transform: translateY(-2px);
        }

        .document-info {
            padding: 30px;
            border-bottom: 1px solid #e9ecef;
        }

        .info-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
            margin-bottom: 20px;
        }

        .info-item {
            background: #f8f9fa;
            padding: 15px;
            border-radius: 8px;
            border-left: 4px solid #007bff;
        }

        .info-label {
            font-weight: 600;
            color: #495057;
            font-size: 0.85rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 5px;
        }

        .info-value {
            font-size: 1rem;
            color: #212529;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .status-badge {
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
            text-transform: uppercase;
            color: white;
        }

        .feedback-section {
            background: #e3f2fd;
            border: 1px solid #bbdefb;
            border-radius: 8px;
            padding: 15px;
            margin-top: 20px;
        }

        .feedback-title {
            font-weight: 600;
            color: #1976d2;
            margin-bottom: 8px;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .feedback-text {
            color: #424242;
            line-height: 1.5;
        }

        .document-viewer {
            padding: 30px;
            min-height: 500px;
        }

        .viewer-container {
            background: #f8f9fa;
            border-radius: 12px;
            padding: 20px;
            text-align: center;
            min-height: 400px;
            display: flex;
            flex-direction: column;
            justify-content: center;
            align-items: center;
        }

        .document-preview {
            width: 100%;
            height: 600px;
            border: none;
            border-radius: 8px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.1);
        }

        .image-preview {
            max-width: 100%;
            max-height: 600px;
            border-radius: 8px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.1);
        }

        .file-icon-large {
            font-size: 4rem;
            color: #6c757d;
            margin-bottom: 20px;
        }

        .file-icon-large.pdf { color: #dc3545; }
        .file-icon-large.doc,
        .file-icon-large.docx { color: #0066cc; }
        .file-icon-large.image { color: #28a745; }

        .not-viewable {
            text-align: center;
            padding: 40px;
        }

        .not-viewable h3 {
            color: #495057;
            margin-bottom: 15px;
        }

        .not-viewable p {
            color: #6c757d;
            margin-bottom: 25px;
        }

        .error-message {
            background: #f8d7da;
            color: #721c24;
            padding: 15px;
            border-radius: 8px;
            margin: 20px;
            border: 1px solid #f5c6cb;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .loading {
            display: flex;
            justify-content: center;
            align-items: center;
            height: 400px;
            flex-direction: column;
            gap: 15px;
        }

        .spinner {
            width: 40px;
            height: 40px;
            border: 4px solid #f3f3f3;
            border-top: 4px solid #007bff;
            border-radius: 50%;
            animation: spin 1s linear infinite;
        }

        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }

        .zoom-controls {
            margin-bottom: 15px;
            display: flex;
            justify-content: center;
            gap: 10px;
        }

        .zoom-btn {
            background: #495057;
            color: white;
            border: none;
            padding: 8px 12px;
            border-radius: 6px;
            cursor: pointer;
            font-size: 0.9rem;
        }

        .zoom-btn:hover {
            background: #343a40;
        }

        /* Responsive design */
        @media (max-width: 768px) {
            body {
                padding: 10px;
            }

            .header {
                padding: 20px;
                flex-direction: column;
                text-align: center;
            }

            .header-actions {
                justify-content: center;
            }

            .info-grid {
                grid-template-columns: 1fr;
                gap: 15px;
            }

            .document-info,
            .document-viewer {
                padding: 20px;
            }

            .btn {
                padding: 8px 16px;
                font-size: 0.85rem;
            }
        }

        @media (max-width: 480px) {
            .header-actions {
                flex-direction: column;
                width: 100%;
            }

            .btn {
                justify-content: center;
            }
        }

        /* Print styles */
        @media print {
            .header-actions,
            .zoom-controls {
                display: none;
            }

            .container {
                box-shadow: none;
                border-radius: 0;
            }

            body {
                background: white;
                padding: 0;
            }
        }
    </style>
</head>
<body>
    
    <div class="container">
        <!-- Header -->
        <div class="header">
            <div class="header-info">
                <h1>
                    <i class="<?php echo getFileIcon($file_extension); ?>"></i>
                    <?php echo htmlspecialchars($document['document_name']); ?>
                </h1>
                <p>Submitted by <?php echo htmlspecialchars($full_name); ?></p>
            </div>
            <div class="header-actions">
                <a href="?id=<?php echo $submission_id; ?>&download=1" class="btn btn-success">
                    <i class="fas fa-download"></i> Download
                </a>
                <button onclick="window.print()" class="btn btn-secondary">
                    <i class="fas fa-print"></i> Print
                </button>
                <a href="studentdashboard.php" class="btn btn-primary">
                    <i class="fas fa-arrow-left"></i> Back to Dashboard
                </a>
            </div>
        </div>

        <!-- Document Information -->
        <div class="document-info">
            <div class="info-grid">
                <div class="info-item">
                    <div class="info-label">File Name</div>
                    <div class="info-value">
                        <i class="<?php echo getFileIcon($file_extension); ?>"></i>
                        <?php echo htmlspecialchars($document['original_filename']); ?>
                    </div>
                </div>
                <div class="info-item">
                    <div class="info-label">File Size</div>
                    <div class="info-value">
                        <i class="fas fa-hdd"></i>
                        <?php echo formatFileSize($file_size); ?>
                    </div>
                </div>
                <div class="info-item">
                    <div class="info-label">Submitted Date</div>
                    <div class="info-value">
                        <i class="fas fa-calendar"></i>
                        <?php echo date('F j, Y g:i A', strtotime($document['submitted_at'])); ?>
                    </div>
                </div>
                <div class="info-item">
                    <div class="info-label">Status</div>
                    <div class="info-value">
                        <span class="status-badge" style="background-color: <?php echo getStatusColor($document['status']); ?>">
                            <?php echo ucfirst($document['status']); ?>
                        </span>
                    </div>
                </div>
            </div>

            <?php if (!empty($document['description'])): ?>
                <div class="info-item">
                    <div class="info-label">Document Description</div>
                    <div class="info-value">
                        <?php echo htmlspecialchars($document['description']); ?>
                    </div>
                </div>
            <?php endif; ?>

            <?php if (!empty($document['feedback'])): ?>
                <div class="feedback-section">
                    <div class="feedback-title">
                        <i class="fas fa-comment-alt"></i>
                        Feedback
                    </div>
                    <div class="feedback-text">
                        <?php echo nl2br(htmlspecialchars($document['feedback'])); ?>
                    </div>
                </div>
            <?php endif; ?>
        </div>

        <!-- Document Viewer -->
        <div class="document-viewer">
            <?php if ($is_viewable): ?>
                <?php if ($file_extension === 'pdf'): ?>
                    <div class="zoom-controls">
                        <button class="zoom-btn" onclick="zoomOut()">
                            <i class="fas fa-search-minus"></i> Zoom Out
                        </button>
                        <button class="zoom-btn" onclick="resetZoom()">
                            <i class="fas fa-expand-arrows-alt"></i> Fit Page
                        </button>
                        <button class="zoom-btn" onclick="zoomIn()">
                            <i class="fas fa-search-plus"></i> Zoom In
                        </button>
                    </div>
                    <iframe id="pdfViewer" 
                            src="<?php echo htmlspecialchars($file_path); ?>#toolbar=1&navpanes=1&scrollbar=1" 
                            class="document-preview"
                            onload="hideLoading()">
                        <p>Your browser does not support PDFs. 
                           <a href="?id=<?php echo $submission_id; ?>&download=1">Download the PDF</a> instead.
                        </p>
                    </iframe>
                <?php elseif (in_array($file_extension, ['jpg', 'jpeg', 'png', 'gif'])): ?>
                    <div class="zoom-controls">
                        <button class="zoom-btn" onclick="zoomImageOut()">
                            <i class="fas fa-search-minus"></i> Zoom Out
                        </button>
                        <button class="zoom-btn" onclick="resetImageZoom()">
                            <i class="fas fa-expand-arrows-alt"></i> Fit Screen
                        </button>
                        <button class="zoom-btn" onclick="zoomImageIn()">
                            <i class="fas fa-search-plus"></i> Zoom In
                        </button>
                    </div>
                    <div style="text-align: center; overflow: auto;">
                        <img id="imageViewer" 
                             src="<?php echo htmlspecialchars($file_path); ?>" 
                             alt="<?php echo htmlspecialchars($document['original_filename']); ?>"
                             class="image-preview"
                             onload="hideLoading()"
                             style="cursor: zoom-in;"
                             onclick="toggleFullscreen()">
                    </div>
                <?php elseif ($file_extension === 'txt'): ?>
                    <div style="text-align: left; background: white; padding: 20px; border-radius: 8px; max-height: 600px; overflow-y: auto;">
                        <pre style="white-space: pre-wrap; font-family: 'Courier New', monospace; line-height: 1.5;">
                            <?php echo htmlspecialchars(file_get_contents($file_path)); ?>
                        </pre>
                    </div>
                <?php endif; ?>
            <?php else: ?>
                <div class="not-viewable">
                    <div class="file-icon-large <?php echo $file_extension; ?>">
                        <i class="<?php echo getFileIcon($file_extension); ?>"></i>
                    </div>
                    <h3><?php echo htmlspecialchars($document['original_filename']); ?></h3>
                    <p>This file type cannot be previewed in the browser.</p>
                    <a href="?id=<?php echo $submission_id; ?>&download=1" class="btn btn-success">
                        <i class="fas fa-download"></i> Download to View
                    </a>
                </div>
            <?php endif; ?>

            <div id="loadingIndicator" class="loading">
                <div class="spinner"></div>
                <p>Loading document...</p>
            </div>
        </div>
    </div>

    <script>
        let currentZoom = 1;
        let imageZoom = 1;

        // Hide loading indicator
        function hideLoading() {
            document.getElementById('loadingIndicator').style.display = 'none';
        }

        // PDF zoom functions
        function zoomIn() {
            currentZoom += 0.25;
            updatePdfZoom();
        }

        function zoomOut() {
            if (currentZoom > 0.5) {
                currentZoom -= 0.25;
                updatePdfZoom();
            }
        }

        function resetZoom() {
            currentZoom = 1;
            updatePdfZoom();
        }

        function updatePdfZoom() {
            const iframe = document.getElementById('pdfViewer');
            if (iframe) {
                const src = iframe.src.split('#')[0];
                iframe.src = src + '#zoom=' + (currentZoom * 100);
            }
        }

        // Image zoom functions
        function zoomImageIn() {
            imageZoom += 0.25;
            updateImageZoom();
        }

        function zoomImageOut() {
            if (imageZoom > 0.25) {
                imageZoom -= 0.25;
                updateImageZoom();
            }
        }

        function resetImageZoom() {
            imageZoom = 1;
            updateImageZoom();
        }

        function updateImageZoom() {
            const img = document.getElementById('imageViewer');
            if (img) {
                img.style.transform = `scale(${imageZoom})`;
                img.style.transformOrigin = 'center';
                img.style.transition = 'transform 0.3s ease';
            }
        }

        // Toggle fullscreen for images
        function toggleFullscreen() {
            const img = document.getElementById('imageViewer');
            if (img) {
                if (document.fullscreenElement) {
                    document.exitFullscreen();
                } else {
                    img.requestFullscreen().catch(err => {
                        console.log('Error attempting to enable fullscreen:', err);
                    });
                }
            }
        }

        // Keyboard shortcuts
        document.addEventListener('keydown', function(e) {
            if (e.target.tagName === 'INPUT' || e.target.tagName === 'TEXTAREA') return;

            switch(e.key) {
                case 'Escape':
                    if (document.fullscreenElement) {
                        document.exitFullscreen();
                    }
                    break;
                case '+':
                case '=':
                    if (document.getElementById('pdfViewer')) {
                        zoomIn();
                    } else if (document.getElementById('imageViewer')) {
                        zoomImageIn();
                    }
                    e.preventDefault();
                    break;
                case '-':
                    if (document.getElementById('pdfViewer')) {
                        zoomOut();
                    } else if (document.getElementById('imageViewer')) {
                        zoomImageOut();
                    }
                    e.preventDefault();
                    break;
                case '0':
                    if (document.getElementById('pdfViewer')) {
                        resetZoom();
                    } else if (document.getElementById('imageViewer')) {
                        resetImageZoom();
                    }
                    e.preventDefault();
                    break;
            }
        });

        // Handle fullscreen changes
        document.addEventListener('fullscreenchange', function() {
            const img = document.getElementById('imageViewer');
            if (img) {
                if (document.fullscreenElement) {
                    img.style.cursor = 'zoom-out';
                    img.style.maxWidth = '100vw';
                    img.style.maxHeight = '100vh';
                } else {
                    img.style.cursor = 'zoom-in';
                    img.style.maxWidth = '100%';
                    img.style.maxHeight = '600px';
                }
            }
        });

        // Show loading initially for viewable documents
        <?php if ($is_viewable): ?>
        document.addEventListener('DOMContentLoaded', function() {
            setTimeout(hideLoading, 3000); // Fallback to hide loading after 3 seconds
        });
        <?php else: ?>
        document.addEventListener('DOMContentLoaded', function() {
            document.getElementById('loadingIndicator').style.display = 'none';
        });
        <?php endif; ?>

        // Handle iframe load errors
        document.addEventListener('DOMContentLoaded', function() {
            const iframe = document.getElementById('pdfViewer');
            if (iframe) {
                iframe.onerror = function() {
                    document.querySelector('.viewer-container').innerHTML = `
                        <div class="error-message">
                            <i class="fas fa-exclamation-triangle"></i>
                            Unable to display PDF. Please download the file to view it.
                        </div>
                        <div class="not-viewable">
                            <a href="?id=<?php echo $submission_id; ?>&download=1" class="btn btn-success">
                                <i class="fas fa-download"></i> Download PDF
                            </a>
                        </div>
                    `;
                };
            }
        });
    </script>
</body>
</html>