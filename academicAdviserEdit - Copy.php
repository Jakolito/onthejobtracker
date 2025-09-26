<?php
include('connect.php');
session_start();

// Check if user is logged in and is an adviser
if (!isset($_SESSION['adviser_id']) || $_SESSION['user_type'] !== 'adviser') {
    header("Location: login.php");
    exit;
}

// Get adviser information
$adviser_id = $_SESSION['adviser_id'];
$adviser_name = $_SESSION['name'];
$adviser_email = $_SESSION['email'];

// Handle form submissions
$message = '';
$message_type = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        switch ($_POST['action']) {
            case 'add_document':
                $document_name = trim($_POST['document_name']);
                $document_description = trim($_POST['document_description']);
                $is_required = isset($_POST['is_required']) ? 1 : 0;
                
                if (!empty($document_name)) {
                    try {
                        $stmt = $conn->prepare("INSERT INTO document_requirements (name, description, is_required, created_by, created_at) VALUES (?, ?, ?, ?, NOW())");
                        if (!$stmt) {
                            throw new Exception("Prepare failed: " . $conn->error);
                        }
                        
                        $stmt->bind_param("ssis", $document_name, $document_description, $is_required, $adviser_id);
                        
                        if ($stmt->execute()) {
                            $message = "Document requirement '{$document_name}' has been added successfully.";
                            $message_type = 'success';
                        } else {
                            throw new Exception("Execute failed: " . $stmt->error);
                        }
                        $stmt->close();
                    } catch (Exception $e) {
                        $message = "Database error: " . $e->getMessage();
                        $message_type = 'error';
                    }
                } else {
                    $message = "Document name is required.";
                    $message_type = 'error';
                }
                break;
                
            case 'edit_document':
                $doc_id = intval($_POST['doc_id']);
                $document_name = trim($_POST['document_name']);
                $document_description = trim($_POST['document_description']);
                $is_required = isset($_POST['is_required']) ? 1 : 0;
                
                if (!empty($document_name) && $doc_id > 0) {
                    try {
                        // First check if document exists
                        $check_stmt = $conn->prepare("SELECT id FROM document_requirements WHERE id = ?");
                        if (!$check_stmt) {
                            throw new Exception("Prepare check failed: " . $conn->error);
                        }
                        
                        $check_stmt->bind_param("i", $doc_id);
                        $check_stmt->execute();
                        $check_result = $check_stmt->get_result();
                        
                        if ($check_result->num_rows === 0) {
                            throw new Exception("Document not found with ID: " . $doc_id);
                        }
                        $check_stmt->close();
                        
                        // Now update the document
                        $stmt = $conn->prepare("UPDATE document_requirements SET name = ?, description = ?, is_required = ? WHERE id = ?");
                        if (!$stmt) {
                            throw new Exception("Prepare update failed: " . $conn->error);
                        }
                        
                        $stmt->bind_param("ssii", $document_name, $document_description, $is_required, $doc_id);
                        
                        if ($stmt->execute()) {
                            if ($stmt->affected_rows > 0) {
                                $message = "Document requirement has been updated successfully.";
                                $message_type = 'success';
                            } else {
                                $message = "No changes were made to the document requirement.";
                                $message_type = 'info';
                            }
                        } else {
                            throw new Exception("Execute update failed: " . $stmt->error);
                        }
                        $stmt->close();
                    } catch (Exception $e) {
                        $message = "Database error: " . $e->getMessage();
                        $message_type = 'error';
                    }
                } else {
                    $message = "Document name is required and valid ID must be provided.";
                    $message_type = 'error';
                }
                break;
                
            case 'toggle_requirement':
                $doc_id = intval($_POST['doc_id']);
                
                if ($doc_id > 0) {
                    try {
                        // Start transaction
                        $conn->autocommit(FALSE);
                        
                        // First get current status
                        $stmt = $conn->prepare("SELECT is_required FROM document_requirements WHERE id = ?");
                        if (!$stmt) {
                            throw new Exception("Prepare select failed: " . $conn->error);
                        }
                        
                        $stmt->bind_param("i", $doc_id);
                        if (!$stmt->execute()) {
                            throw new Exception("Execute select failed: " . $stmt->error);
                        }
                        
                        $result = $stmt->get_result();
                        
                        if ($row = $result->fetch_assoc()) {
                            $new_status = $row['is_required'] ? 0 : 1;
                            $stmt->close();
                            
                            // Update the status
                            $update_stmt = $conn->prepare("UPDATE document_requirements SET is_required = ? WHERE id = ?");
                            if (!$update_stmt) {
                                throw new Exception("Prepare update failed: " . $conn->error);
                            }
                            
                            $update_stmt->bind_param("ii", $new_status, $doc_id);
                            
                            if ($update_stmt->execute()) {
                                if ($update_stmt->affected_rows > 0) {
                                    $status_text = $new_status ? 'required' : 'optional';
                                    $message = "Document requirement status has been updated to {$status_text}.";
                                    $message_type = 'success';
                                    $conn->commit();
                                } else {
                                    throw new Exception("No rows were affected during update.");
                                }
                            } else {
                                throw new Exception("Execute update failed: " . $update_stmt->error);
                            }
                            $update_stmt->close();
                        } else {
                            throw new Exception("Document not found with ID: " . $doc_id);
                        }
                        
                        $conn->autocommit(TRUE);
                    } catch (Exception $e) {
                        $conn->rollback();
                        $conn->autocommit(TRUE);
                        $message = "Database error: " . $e->getMessage();
                        $message_type = 'error';
                    }
                } else {
                    $message = "Invalid document ID provided.";
                    $message_type = 'error';
                }
                break;
                
            case 'delete_document':
                $doc_id = intval($_POST['doc_id']);
                
                if ($doc_id > 0) {
                    try {
                        // Start transaction
                        $conn->autocommit(FALSE);
                        
                        // Check if document exists first
                        $check_stmt = $conn->prepare("SELECT name FROM document_requirements WHERE id = ?");
                        if (!$check_stmt) {
                            throw new Exception("Prepare check failed: " . $conn->error);
                        }
                        
                        $check_stmt->bind_param("i", $doc_id);
                        $check_stmt->execute();
                        $check_result = $check_stmt->get_result();
                        
                        if ($check_result->num_rows === 0) {
                            throw new Exception("Document not found with ID: " . $doc_id);
                        }
                        
                        $doc_data = $check_result->fetch_assoc();
                        $doc_name = $doc_data['name'];
                        $check_stmt->close();
                        
                        // Check for dependencies (if there are any related tables)
                        // Add checks here if you have foreign key relationships
                        
                        // Delete the document
                        $stmt = $conn->prepare("DELETE FROM document_requirements WHERE id = ?");
                        if (!$stmt) {
                            throw new Exception("Prepare delete failed: " . $conn->error);
                        }
                        
                        $stmt->bind_param("i", $doc_id);
                        
                        if ($stmt->execute()) {
                            if ($stmt->affected_rows > 0) {
                                $message = "Document requirement '{$doc_name}' has been deleted successfully.";
                                $message_type = 'success';
                                $conn->commit();
                            } else {
                                throw new Exception("No rows were affected during deletion.");
                            }
                        } else {
                            throw new Exception("Execute delete failed: " . $stmt->error);
                        }
                        $stmt->close();
                        
                        $conn->autocommit(TRUE);
                    } catch (Exception $e) {
                        $conn->rollback();
                        $conn->autocommit(TRUE);
                        $message = "Database error: " . $e->getMessage();
                        $message_type = 'error';
                    }
                } else {
                    $message = "Invalid document ID provided.";
                    $message_type = 'error';
                }
                break;
        }
    }
}

// Fetch all document requirements from database
$documents = [];
try {
    $stmt = $conn->prepare("SELECT id, name, description, is_required, created_at, created_by FROM document_requirements ORDER BY created_at DESC");
    if (!$stmt) {
        throw new Exception("Prepare select failed: " . $conn->error);
    }
    
    $stmt->execute();
    $result = $stmt->get_result();
    
    while ($row = $result->fetch_assoc()) {
        $documents[] = $row;
    }
    $stmt->close();
} catch (Exception $e) {
    $message = "Error fetching documents: " . $e->getMessage();
    $message_type = 'error';
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>OnTheJob Tracker - Edit Document Requirements</title>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
  <link rel="stylesheet" href="css/AdviserDashboard.css" />
  <style>
    .documents-container {
      background-color: #f5f5f5;
      border-radius: 10px;
      padding: 20px;
      margin-bottom: 30px;
      box-shadow: 0 2px 4px rgba(0,0,0,0.05);
    }

    .documents-header {
      display: flex;
      justify-content: space-between;
      align-items: center;
      margin-bottom: 20px;
    }

    .documents-title {
      font-size: 24px;
      font-weight: bold;
      color: #333;
    }

    .add-document-btn {
      background-color: #28a745;
      color: white;
      border: none;
      padding: 10px 20px;
      border-radius: 5px;
      cursor: pointer;
      font-size: 14px;
      display: flex;
      align-items: center;
      gap: 8px;
    }

    .add-document-btn:hover {
      background-color: #218838;
    }

    .documents-table {
      width: 100%;
      border-collapse: collapse;
      background-color: white;
      border-radius: 8px;
      overflow: hidden;
      box-shadow: 0 1px 3px rgba(0,0,0,0.1);
    }

    .documents-table th {
      background-color: #f8f9fa;
      padding: 15px;
      text-align: left;
      font-weight: 600;
      color: #495057;
      border-bottom: 2px solid #dee2e6;
    }

    .documents-table td {
      padding: 15px;
      border-bottom: 1px solid #dee2e6;
      vertical-align: middle;
    }

    .documents-table tr:hover {
      background-color: #f8f9fa;
    }

    .status-toggle {
      display: flex;
      align-items: center;
      gap: 10px;
    }

    .toggle-switch {
      position: relative;
      width: 50px;
      height: 24px;
      background-color: #ccc;
      border-radius: 12px;
      cursor: pointer;
      transition: background-color 0.3s;
    }

    .toggle-switch.active {
      background-color: #28a745;
    }

    .toggle-slider {
      position: absolute;
      top: 2px;
      left: 2px;
      width: 20px;
      height: 20px;
      background-color: white;
      border-radius: 50%;
      transition: transform 0.3s;
    }

    .toggle-switch.active .toggle-slider {
      transform: translateX(26px);
    }

    .action-buttons {
      display: flex;
      gap: 8px;
    }

    .btn {
      padding: 6px 12px;
      border: none;
      border-radius: 4px;
      cursor: pointer;
      font-size: 12px;
      display: flex;
      align-items: center;
      gap: 4px;
    }

    .btn-edit {
      background-color: #007bff;
      color: white;
    }

    .btn-edit:hover {
      background-color: #0056b3;
    }

    .btn-delete {
      background-color: #dc3545;
      color: white;
    }

    .btn-delete:hover {
      background-color: #c82333;
    }

    .modal {
      display: none;
      position: fixed;
      z-index: 1000;
      left: 0;
      top: 0;
      width: 100%;
      height: 100%;
      background-color: rgba(0,0,0,0.5);
    }

    .modal-content {
      background-color: white;
      margin: 5% auto;
      padding: 20px;
      border-radius: 8px;
      width: 90%;
      max-width: 500px;
      position: relative;
    }

    .modal-header {
      display: flex;
      justify-content: space-between;
      align-items: center;
      margin-bottom: 20px;
    }

    .modal-title {
      font-size: 18px;
      font-weight: bold;
      color: #333;
    }

    .close {
      color: #aaa;
      font-size: 28px;
      font-weight: bold;
      cursor: pointer;
      line-height: 1;
    }

    .close:hover {
      color: #333;
    }

    .form-group {
      margin-bottom: 15px;
    }

    .form-label {
      display: block;
      margin-bottom: 5px;
      font-weight: 600;
      color: #333;
    }

    .form-input {
      width: 100%;
      padding: 10px;
      border: 1px solid #ddd;
      border-radius: 4px;
      font-size: 14px;
      box-sizing: border-box;
    }

    .form-textarea {
      width: 100%;
      padding: 10px;
      border: 1px solid #ddd;
      border-radius: 4px;
      font-size: 14px;
      min-height: 80px;
      resize: vertical;
      box-sizing: border-box;
    }

    .form-checkbox {
      display: flex;
      align-items: center;
      gap: 8px;
    }

    .form-checkbox input {
      width: auto;
    }

    .form-actions {
      display: flex;
      justify-content: flex-end;
      gap: 10px;
      margin-top: 20px;
    }

    .btn-primary {
      background-color: #28a745;
      color: white;
      padding: 10px 20px;
      border: none;
      border-radius: 4px;
      cursor: pointer;
    }

    .btn-primary:hover {
      background-color: #218838;
    }

    .btn-secondary {
      background-color: #6c757d;
      color: white;
      padding: 10px 20px;
      border: none;
      border-radius: 4px;
      cursor: pointer;
    }

    .btn-secondary:hover {
      background-color: #545b62;
    }

    .alert {
      padding: 15px;
      margin-bottom: 20px;
      border-radius: 4px;
      border-left: 4px solid;
    }

    .alert-success {
      background-color: #d4edda;
      color: #155724;
      border-left-color: #28a745;
    }

    .alert-error {
      background-color: #f8d7da;
      color: #721c24;
      border-left-color: #dc3545;
    }

    .alert-info {
      background-color: #d1ecf1;
      color: #0c5460;
      border-left-color: #17a2b8;
    }

    .required-badge {
      background-color: #dc3545;
      color: white;
      padding: 2px 6px;
      border-radius: 3px;
      font-size: 10px;
      font-weight: bold;
    }

    .optional-badge {
      background-color: #6c757d;
      color: white;
      padding: 2px 6px;
      border-radius: 3px;
      font-size: 10px;
    }

    .document-description {
      color: #666;
      font-size: 13px;
      margin-top: 2px;
    }

    .empty-state {
      text-align: center;
      padding: 40px;
      color: #666;
    }

    .empty-state i {
      font-size: 48px;
      color: #ccc;
      margin-bottom: 20px;
    }

    .empty-state h3 {
      margin-bottom: 10px;
      color: #333;
    }

    .date-created {
      color: #999;
      font-size: 12px;
    }

    /* Loading states */
    .btn:disabled {
      opacity: 0.6;
      cursor: not-allowed;
    }

    .loading {
      pointer-events: none;
      opacity: 0.7;
    }

    /* Debug info (remove in production) */
    .debug-info {
      background-color: #f8f9fa;
      border: 1px solid #dee2e6;
      border-radius: 4px;
      padding: 10px;
      margin-bottom: 20px;
      font-size: 12px;
      color: #666;
      display: none; /* Hide by default */
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
    
    <div class="nav-header">Navigation</div>
    <div class="navigation">
      <a href="AdviserDashboard.php" class="nav-item">
        <i class="fas fa-th-large"></i>
        Dashboard
      </a>
      <a href="ViewOJTCoordinators.php" class="nav-item">
        <i class="fas fa-users-cog"></i>
        View OJT Company Supervisor
      </a>
      <a href="StudentAccounts.php" class="nav-item">
        <i class="fas fa-user-graduate"></i>
        Student Accounts
      </a>
       <a href="StudentDeployment.php" class="nav-item">
                <i class="fas fa-paper-plane"></i>
                Student Deployment
            </a>
      <a href="StudentPerformance.php" class="nav-item">
        <i class="fas fa-chart-line"></i>
        Student Performance
      </a>
      <a href="StudentRecords.php" class="nav-item">
        <i class="fas fa-folder-open"></i>
        Student Records
      </a>
      <a href="GenerateReports.php" class="nav-item">
        <i class="fas fa-file-alt"></i>
        Generate Reports
      </a>
      <a href="AdminAlerts.php" class="nav-item">
        <i class="fas fa-bell"></i>
        Administrative Alerts
      </a>
      <a href="academicAdviserMessage.php" class="nav-item">
        <i class="fas fa-envelope"></i>
        Messages
      </a>
       <a href="academicAdviserEdit.php" class="nav-item active">
        <i class="fas fa-edit"></i>
        Edit Document
      </a>
    </div>
    
    <div class="user-profile">
      <div class="avatar"><?php echo strtoupper(substr($adviser_name, 0, 2)); ?></div>
      <div class="user-info">
        <div class="user-name"><?php echo htmlspecialchars($adviser_name); ?></div>
        <div class="user-role">Academic Adviser</div>
      </div>
    </div>
  </div>
  
  <div class="main-content">
    <div class="topbar">
      <div class="search-box">
        <i class="fas fa-search"></i>
        <input type="text" placeholder="Search documents...">
      </div>
      
      <div class="topbar-right">
        <div class="icon-button">
          <i class="fas fa-bell"></i>
          <div class="notification-badge">3</div>
        </div>
        
        <div class="profile-dropdown">
          <div class="profile-pic" onclick="toggleDropdown()">
            <span><?php echo strtoupper(substr($adviser_name, 0, 2)); ?></span>
          </div>
          <div class="dropdown-content" id="profileDropdownTop">
            <div class="dropdown-header">
              <div class="dropdown-header-name"><?php echo htmlspecialchars($adviser_name); ?></div>
            </div>
            <a href="AdviserAccountSettings.php" class="dropdown-item">
              <i class="fas fa-cog"></i>
              Account Settings
            </a>
            <div class="dropdown-divider"></div>
            <a href="index.php" class="dropdown-item">
              <i class="fas fa-sign-out-alt"></i>
              Logout
            </a>
          </div>
        </div>
      </div>
    </div>
    
    <div class="dashboard-header">
      <div>
        <div class="dashboard-title">Document Requirements Management</div>
        <div class="dashboard-subtitle">Configure required documents for OJT students</div>
      </div>
    </div>

    <?php if (!empty($message)): ?>
      <div class="alert <?php echo ($message_type === 'success') ? 'alert-success' : (($message_type === 'info') ? 'alert-info' : 'alert-error'); ?>">
        <?php echo htmlspecialchars($message); ?>
      </div>
    <?php endif; ?>

    <!-- Debug info (uncomment to debug database issues) -->
    <!-- <div class="debug-info" style="display: block;">
      <strong>Debug Info:</strong><br>
      Total Documents: <?php echo count($documents); ?><br>
      Database Connection: <?php echo $conn ? 'Connected' : 'Not Connected'; ?><br>
      Last MySQL Error: <?php echo $conn->error; ?>
    </div> -->
    
    <div class="documents-container">
      <div class="documents-header">
        <h2 class="documents-title">Document Requirements (<?php echo count($documents); ?>)</h2>
        <button class="add-document-btn" onclick="openAddModal()">
          <i class="fas fa-plus"></i>
          Add Document Requirement
        </button>
      </div>
      
      <?php if (empty($documents)): ?>
        <div class="empty-state">
          <i class="fas fa-file-alt"></i>
          <h3>No Document Requirements Found</h3>
          <p>Start by adding your first document requirement for OJT students.</p>
        </div>
      <?php else: ?>
        <table class="documents-table">
          <thead>
            <tr>
              <th>Document</th>
              <th>Status</th>
              <th>Created</th>
              <th>Actions</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($documents as $doc): ?>
              <tr data-doc-id="<?php echo $doc['id']; ?>">
                <td>
                  <div>
                    <strong><?php echo htmlspecialchars($doc['name']); ?></strong>
                    <?php if ($doc['is_required']): ?>
                      <span class="required-badge">Required</span>
                    <?php else: ?>
                      <span class="optional-badge">Optional</span>
                    <?php endif; ?>
                    <?php if (!empty($doc['description'])): ?>
                      <div class="document-description">
                        <?php echo htmlspecialchars($doc['description']); ?>
                      </div>
                    <?php endif; ?>
                  </div>
                </td>
                <td>
                  <div class="status-toggle">
                    <div class="toggle-switch <?php echo $doc['is_required'] ? 'active' : ''; ?>" 
                         onclick="toggleRequirement(<?php echo $doc['id']; ?>)">
                      <div class="toggle-slider"></div>
                    </div>
                    <span class="status-text"><?php echo $doc['is_required'] ? 'Required' : 'Optional'; ?></span>
                  </div>
                </td>
                <td>
                  <div class="date-created">
                    <?php echo date('M j, Y', strtotime($doc['created_at'])); ?>
                  </div>
                </td>
                <td>
                  <div class="action-buttons">
                    <button class="btn btn-edit" onclick="editDocument(<?php echo $doc['id']; ?>, '<?php echo htmlspecialchars($doc['name'], ENT_QUOTES); ?>', '<?php echo htmlspecialchars($doc['description'], ENT_QUOTES); ?>', <?php echo $doc['is_required'] ? 'true' : 'false'; ?>)">
                      <i class="fas fa-edit"></i>
                      Edit
                    </button>
                    <button class="btn btn-delete" onclick="deleteDocument(<?php echo $doc['id']; ?>, '<?php echo htmlspecialchars($doc['name'], ENT_QUOTES); ?>')">
                      <i class="fas fa-trash"></i>
                      Delete
                    </button>
                  </div>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      <?php endif; ?>
    </div>
  </div>

  <!-- Add Document Modal -->
  <div id="addDocumentModal" class="modal">
    <div class="modal-content">
      <div class="modal-header">
        <h3 class="modal-title">Add Document Requirement</h3>
        <span class="close" onclick="closeAddModal()">&times;</span>
      </div>
      <form id="addDocumentForm" method="POST">
        <input type="hidden" name="action" value="add_document">
        
        <div class="form-group">
          <label class="form-label">Document Name *</label>
          <input type="text" name="document_name" class="form-input" required>
        </div>
        
        <div class="form-group">
          <label class="form-label">Description</label>
          <textarea name="document_description" class="form-textarea" placeholder="Brief description of the document requirement"></textarea>
        </div>
        
        <div class="form-group">
          <div class="form-checkbox">
            <input type="checkbox" name="is_required" id="is_required" checked>
            <label for="is_required">This document is required</label>
          </div>
        </div>
        
        <div class="form-actions">
          <button type="button" class="btn-secondary" onclick="closeAddModal()">Cancel</button>
          <button type="submit" class="btn-primary">Add Document</button>
        </div>
      </form>
    </div>
  </div>

  <!-- Edit Document Modal -->
  <div id="editDocumentModal" class="modal">
    <div class="modal-content">
      <div class="modal-header">
        <h3 class="modal-title">Edit Document Requirement</h3>
        <span class="close" onclick="closeEditModal()">&times;</span>
      </div>
      <form id="editDocumentForm" method="POST">
        <input type="hidden" name="action" value="edit_document">
        <input type="hidden" name="doc_id" id="edit_doc_id">
        
        <div class="form-group">
          <label class="form-label">Document Name *</label>
          <input type="text" name="document_name" id="edit_document_name" class="form-input" required>
        </div>
        
        <div class="form-group">
          <label class="form-label">Description</label>
          <textarea name="document_description" id="edit_document_description" class="form-textarea"></textarea>
        </div>
        
        <div class="form-group">
          <div class="form-checkbox">
            <input type="checkbox" name="is_required" id="edit_is_required">
            <label for="edit_is_required">This document is required</label>
          </div>
        </div>
        
        <div class="form-actions">
          <button type="button" class="btn-secondary" onclick="closeEditModal()">Cancel</button>
          <button type="submit" class="btn-primary">Update Document</button>
        </div>
      </form>
    </div>
  </div>

  <!-- Delete Confirmation Modal -->
  <div id="deleteConfirmModal" class="modal">
    <div class="modal-content">
      <div class="modal-header">
        <h3 class="modal-title">Confirm Delete</h3>
        <span class="close" onclick="closeDeleteModal()">&times;</span>
      </div>
      <p>Are you sure you want to delete the document requirement "<span id="delete_doc_name"></span>"?</p>
      <p style="color: #dc3545; font-size: 14px;">This action cannot be undone.</p>
      
      <form id="deleteDocumentForm" method="POST">
        <input type="hidden" name="action" value="delete_document">
        <input type="hidden" name="doc_id" id="delete_doc_id">
        
        <div class="form-actions">
          <button type="button" class="btn-secondary" onclick="closeDeleteModal()">Cancel</button>
          <button type="submit" class="btn btn-delete">Delete Document</button>
        </div>
      </form>
    </div>
  </div>

  <!-- Toggle Requirement Form (Hidden) -->
  <form id="toggleRequirementForm" method="POST" style="display: none;">
    <input type="hidden" name="action" value="toggle_requirement">
    <input type="hidden" name="doc_id" id="toggle_doc_id">
  </form>

  <script>
    // Modal functions
    function openAddModal() {
      document.getElementById('addDocumentModal').style.display = 'block';
    }

    function closeAddModal() {
      document.getElementById('addDocumentModal').style.display = 'none';
    }

    function editDocument(id, name, description, isRequired) {
      document.getElementById('edit_doc_id').value = id;
      document.getElementById('edit_document_name').value = name;
      document.getElementById('edit_document_description').value = description;
      document.getElementById('edit_is_required').checked = isRequired;
      document.getElementById('editDocumentModal').style.display = 'block';
    }

    function closeEditModal() {
      document.getElementById('editDocumentModal').style.display = 'none';
    }

    function deleteDocument(id, name) {
      document.getElementById('delete_doc_id').value = id;
      document.getElementById('delete_doc_name').textContent = name;
      document.getElementById('deleteConfirmModal').style.display = 'block';
    }

    function closeDeleteModal() {
      document.getElementById('deleteConfirmModal').style.display = 'none';
    }

    function toggleRequirement(docId) {
      // Show loading state
      const toggleSwitch = document.querySelector(`tr[data-doc-id="${docId}"] .toggle-switch`);
      const statusText = document.querySelector(`tr[data-doc-id="${docId}"] .status-text`);
      
      if (toggleSwitch && statusText) {
        toggleSwitch.style.opacity = '0.6';
        statusText.textContent = 'Updating...';
        
        // Set the doc ID and submit the form
        document.getElementById('toggle_doc_id').value = docId;
        document.getElementById('toggleRequirementForm').submit();
      }
    }

    // Profile dropdown functionality
    function toggleDropdown() {
      const dropdown = document.getElementById('profileDropdownTop');
      dropdown.classList.toggle('show');
    }

    // Close dropdown when clicking outside
    window.onclick = function(event) {
      // Close modals when clicking outside
      const modals = document.querySelectorAll('.modal');
      modals.forEach(modal => {
        if (event.target === modal) {
          modal.style.display = 'none';
        }
      });

      // Close dropdown when clicking outside
      if (!event.target.matches('.profile-pic') && !event.target.matches('.profile-pic span')) {
        const dropdown = document.getElementById('profileDropdownTop');
        if (dropdown && dropdown.classList.contains('show')) {
          dropdown.classList.remove('show');
        }
      }
    }

    // Form validation and enhancement
    document.addEventListener('DOMContentLoaded', function() {
      // Add form submission handlers
      const forms = document.querySelectorAll('form');
      forms.forEach(form => {
        form.addEventListener('submit', function(e) {
          const submitBtn = form.querySelector('button[type="submit"]');
          if (submitBtn) {
            submitBtn.disabled = true;
            submitBtn.textContent = 'Processing...';
            
            // Re-enable after 3 seconds to prevent permanent disable on validation errors
            setTimeout(() => {
              submitBtn.disabled = false;
              submitBtn.textContent = submitBtn.textContent.replace('Processing...', submitBtn.getAttribute('data-original-text') || 'Submit');
            }, 3000);
          }
        });
      });

      // Store original button text
      document.querySelectorAll('button[type="submit"]').forEach(btn => {
        btn.setAttribute('data-original-text', btn.textContent);
      });

      // Search functionality
      const searchInput = document.querySelector('.search-box input');
      if (searchInput) {
        searchInput.addEventListener('input', function(e) {
          const searchTerm = e.target.value.toLowerCase();
          const tableRows = document.querySelectorAll('.documents-table tbody tr');
          
          tableRows.forEach(row => {
            const documentName = row.querySelector('td:first-child strong').textContent.toLowerCase();
            const documentDesc = row.querySelector('.document-description');
            const description = documentDesc ? documentDesc.textContent.toLowerCase() : '';
            
            if (documentName.includes(searchTerm) || description.includes(searchTerm)) {
              row.style.display = '';
            } else {
              row.style.display = 'none';
            }
          });
        });
      }

      // Auto-hide alerts after 5 seconds
      const alerts = document.querySelectorAll('.alert');
      alerts.forEach(alert => {
        setTimeout(() => {
          alert.style.opacity = '0';
          setTimeout(() => {
            alert.remove();
          }, 300);
        }, 5000);
      });
    });

    // Keyboard shortcuts
    document.addEventListener('keydown', function(e) {
      // Escape key to close modals
      if (e.key === 'Escape') {
        const modals = document.querySelectorAll('.modal');
        modals.forEach(modal => {
          if (modal.style.display === 'block') {
            modal.style.display = 'none';
          }
        });
      }
      
      // Ctrl/Cmd + N to add new document
      if ((e.ctrlKey || e.metaKey) && e.key === 'n') {
        e.preventDefault();
        openAddModal();
      }
    });

    // Add confirmation for delete operations
    document.getElementById('deleteDocumentForm').addEventListener('submit', function(e) {
      const confirmation = confirm('Are you absolutely sure you want to delete this document requirement? This action cannot be undone.');
      if (!confirmation) {
        e.preventDefault();
      }
    });

    // Enhanced toggle animation
    document.querySelectorAll('.toggle-switch').forEach(toggle => {
      toggle.addEventListener('click', function() {
        // Add click animation
        this.style.transform = 'scale(0.95)';
        setTimeout(() => {
          this.style.transform = 'scale(1)';
        }, 150);
      });
    });

    // Tooltips for action buttons
    document.querySelectorAll('.btn').forEach(btn => {
      btn.addEventListener('mouseenter', function() {
        const text = this.textContent.trim();
        this.setAttribute('title', text);
      });
    });

    // Auto-focus on modal inputs
    document.getElementById('addDocumentModal').addEventListener('transitionend', function() {
      if (this.style.display === 'block') {
        const nameInput = this.querySelector('input[name="document_name"]');
        if (nameInput) nameInput.focus();
      }
    });

    document.getElementById('editDocumentModal').addEventListener('transitionend', function() {
      if (this.style.display === 'block') {
        const nameInput = this.querySelector('input[name="document_name"]');
        if (nameInput) nameInput.focus();
      }
    });

    // Character count for description textarea
    document.querySelectorAll('.form-textarea').forEach(textarea => {
      const maxLength = 500; // Set your desired max length
      
      // Create character counter
      const counter = document.createElement('div');
      counter.style.cssText = 'font-size: 12px; color: #666; text-align: right; margin-top: 5px;';
      textarea.parentNode.appendChild(counter);
      
      function updateCounter() {
        const remaining = maxLength - textarea.value.length;
        counter.textContent = `${textarea.value.length}/${maxLength} characters`;
        counter.style.color = remaining < 50 ? '#dc3545' : '#666';
      }
      
      textarea.addEventListener('input', updateCounter);
      updateCounter(); // Initial count
    });

    // Smooth scrolling for long tables
    const table = document.querySelector('.documents-table');
    if (table) {
      table.style.scrollBehavior = 'smooth';
    }

    // Enhanced feedback for successful operations
    <?php if ($message_type === 'success'): ?>
      // Add success animation or sound here if needed
      console.log('Operation completed successfully');
    <?php endif; ?>

    // Debug logging (remove in production)
    console.log('Document Management System loaded');
    console.log('Total documents:', <?php echo count($documents); ?>);
  </script>
</body>
</html>
