<?php
include('connect.php');
session_start();

if (!isset($_SESSION['user_id']) || !isset($_GET['id'])) {
    echo '<div style="text-align:center; padding:50px; font-family: Arial;">Access denied</div>';
    exit();
}

$user_id = $_SESSION['user_id'];
$submission_id = (int)$_GET['id'];

// Fetch document
$stmt = $conn->prepare("SELECT file_path, original_filename, file_type FROM student_documents WHERE id = ? AND student_id = ?");
$stmt->bind_param("ii", $submission_id, $user_id);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    echo '<div style="text-align:center; padding:50px; font-family: Arial;">Document not found</div>';
    exit();
}

$doc = $result->fetch_assoc();
$file_path = $doc['file_path'];
$file_name = $doc['original_filename'];

if (!file_exists($file_path)) {
    echo '<div style="text-align:center; padding:50px; font-family: Arial;">File not found: ' . htmlspecialchars($file_path) . '</div>';
    exit();
}

$ext = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: #f8f9fa;
            padding: 20px;
        }
        .doc-container {
            max-width: 850px;
            margin: 0 auto;
            background: white;
            padding: 40px;
            box-shadow: 0 2px 15px rgba(0,0,0,0.1);
            border-radius: 8px;
        }
        .doc-header {
            text-align: center;
            margin-bottom: 30px;
            padding-bottom: 20px;
            border-bottom: 3px solid #800000;
        }
        .doc-icon {
            font-size: 64px;
            color: #0066cc;
            margin-bottom: 15px;
        }
        .doc-title {
            color: #800000;
            font-size: 22px;
            font-weight: 600;
            margin-bottom: 10px;
            word-wrap: break-word;
        }
        .doc-info {
            color: #666;
            font-size: 14px;
            margin-top: 15px;
        }
        .message-box {
            background: #e3f2fd;
            border: 1px solid #90caf9;
            border-radius: 6px;
            padding: 20px;
            margin: 20px 0;
            text-align: center;
        }
        .message-box h3 {
            color: #1976d2;
            font-size: 18px;
            margin-bottom: 10px;
        }
        .message-box p {
            color: #555;
            font-size: 14px;
            line-height: 1.6;
        }
        .download-btn {
            display: inline-block;
            margin-top: 20px;
            padding: 12px 30px;
            background: #800000;
            color: white;
            text-decoration: none;
            border-radius: 6px;
            font-weight: 500;
            transition: all 0.3s;
        }
        .download-btn:hover {
            background: #6B1028;
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(128,0,0,0.3);
        }
        .download-btn i {
            margin-right: 8px;
        }
    </style>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
</head>
<body>
    <div class="doc-container">
        <div class="doc-header">
            <div class="doc-icon">
                <i class="fas fa-file-word"></i>
            </div>
            <div class="doc-title"><?php echo htmlspecialchars($file_name); ?></div>
            <div class="doc-info">Microsoft Word Document</div>
        </div>
        
        <div class="message-box">
            <h3><i class="fas fa-info-circle"></i> Preview Not Available</h3>
            <p>
                Word documents (.doc, .docx) cannot be previewed directly in the browser on localhost.<br>
                To view the full content and formatting of this document, please download it below.
            </p>
        </div>

        <div style="text-align: center;">
            <a href="<?php echo htmlspecialchars($file_path); ?>" download="<?php echo htmlspecialchars($file_name); ?>" class="download-btn">
                <i class="fas fa-download"></i>
                Download Document
            </a>
        </div>

        <div style="margin-top: 40px; padding-top: 20px; border-top: 1px solid #e0e0e0; text-align: center; color: #999; font-size: 12px;">
            <p>File location: <?php echo htmlspecialchars($file_path); ?></p>
        </div>
    </div>
</body>
</html>