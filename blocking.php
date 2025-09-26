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

// Get filter parameters
$search = isset($_GET['search']) ? mysqli_real_escape_string($conn, $_GET['search']) : '';
$department_filter = isset($_GET['department']) ? mysqli_real_escape_string($conn, $_GET['department']) : '';
$status_filter = isset($_GET['status']) ? mysqli_real_escape_string($conn, $_GET['status']) : '';

// Pagination settings
$records_per_page = 10;
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$offset = ($page - 1) * $records_per_page;

try {
    // Get total counts for summary cards
    $active_students_query = "SELECT COUNT(*) as total FROM students WHERE status = 'Active' AND verified = 1";
    $active_students_result = mysqli_query($conn, $active_students_query);
    $active_students = mysqli_fetch_assoc($active_students_result)['total'];

    // Get blocked students count (including those with 3+ failed login attempts)
    $blocked_students_query = "SELECT COUNT(*) as total FROM students WHERE status = 'Blocked' OR login_attempts >= 3";
    $blocked_students_result = mysqli_query($conn, $blocked_students_query);
    $blocked_students = mysqli_fetch_assoc($blocked_students_result)['total'];

    // Get unverified students count
    $unverified_students_query = "SELECT COUNT(*) as total FROM students WHERE verified = 0";
    $unverified_students_result = mysqli_query($conn, $unverified_students_query);
    $unverified_students = mysqli_fetch_assoc($unverified_students_result)['total'];

    // Get unique departments for filter dropdown
    $departments_query = "SELECT DISTINCT department FROM students WHERE department IS NOT NULL AND department != '' ORDER BY department";
    $departments_result = mysqli_query($conn, $departments_query);

    // Build the main query with filters
    $where_conditions = array();
    $where_conditions[] = "1=1"; // Base condition

    if (!empty($search)) {
        $where_conditions[] = "(first_name LIKE '%$search%' OR last_name LIKE '%$search%' OR email LIKE '%$search%' OR student_id LIKE '%$search%')";
    }

    if (!empty($department_filter)) {
        $where_conditions[] = "department = '$department_filter'";
    }

    if (!empty($status_filter)) {
        if ($status_filter === 'verified') {
            $where_conditions[] = "verified = 1";
        } elseif ($status_filter === 'unverified') {
            $where_conditions[] = "verified = 0";
        } elseif ($status_filter === 'blocked') {
            $where_conditions[] = "(status = 'Blocked' OR login_attempts >= 3)";
        } else {
            $where_conditions[] = "status = '$status_filter'";
        }
    }

    $where_clause = implode(' AND ', $where_conditions);

    // Count total records for pagination
    $count_query = "SELECT COUNT(*) as total FROM students WHERE $where_clause";
    $count_result = mysqli_query($conn, $count_query);
    $total_records = mysqli_fetch_assoc($count_result)['total'];
    $total_pages = ceil($total_records / $records_per_page);

    // Get students with pagination
    $students_query = "
        SELECT 
            id,
            student_id,
            first_name,
            middle_name,
            last_name,
            email,
            department,
            program,
            year_level,
            section,
            status,
            verified,
            login_attempts,
            created_at,
            last_login
        FROM students 
        WHERE $where_clause
        ORDER BY created_at DESC
        LIMIT $records_per_page OFFSET $offset
    ";
    $students_result = mysqli_query($conn, $students_query);

} catch (Exception $e) {
    $error_message = "Error fetching student data: " . $e->getMessage();
}

// Handle AJAX requests for student actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');
    
    $action = $_POST['action'];
    
    try {
        switch ($action) {
            case 'verify':
                $student_id = (int)$_POST['student_id'];
                $update_query = "UPDATE students SET verified = 1 WHERE id = ?";
                $stmt = mysqli_prepare($conn, $update_query);
                mysqli_stmt_bind_param($stmt, "i", $student_id);
                $success = mysqli_stmt_execute($stmt);
                echo json_encode(['success' => $success, 'message' => $success ? 'Student verified successfully' : 'Failed to verify student']);
                break;
                
            case 'block':
                $student_id = (int)$_POST['student_id'];
                $update_query = "UPDATE students SET status = 'Blocked', login_attempts = 3 WHERE id = ?";
                $stmt = mysqli_prepare($conn, $update_query);
                mysqli_stmt_bind_param($stmt, "i", $student_id);
                $success = mysqli_stmt_execute($stmt);
                echo json_encode(['success' => $success, 'message' => $success ? 'Student blocked successfully' : 'Failed to block student']);
                break;
                
            case 'unblock':
                $student_id = (int)$_POST['student_id'];
                $update_query = "UPDATE students SET status = 'Active', login_attempts = 0 WHERE id = ?";
                $stmt = mysqli_prepare($conn, $update_query);
                mysqli_stmt_bind_param($stmt, "i", $student_id);
                $success = mysqli_stmt_execute($stmt);
                echo json_encode(['success' => $success, 'message' => $success ? 'Student unblocked successfully' : 'Failed to unblock student']);
                break;
                
            case 'activate':
                $student_id = (int)$_POST['student_id'];
                $update_query = "UPDATE students SET status = 'Active', login_attempts = 0 WHERE id = ?";
                $stmt = mysqli_prepare($conn, $update_query);
                mysqli_stmt_bind_param($stmt, "i", $student_id);
                $success = mysqli_stmt_execute($stmt);
                echo json_encode(['success' => $success, 'message' => $success ? 'Student activated successfully' : 'Failed to activate student']);
                break;

            case 'get_details':
                $student_id = (int)$_POST['student_id'];
                $details_query = "SELECT * FROM students WHERE id = ?";
                $stmt = mysqli_prepare($conn, $details_query);
                mysqli_stmt_bind_param($stmt, "i", $student_id);
                mysqli_stmt_execute($stmt);
                $result = mysqli_stmt_get_result($stmt);
                $student = mysqli_fetch_assoc($result);
                
                if ($student) {
                    echo json_encode(['success' => true, 'student' => $student]);
                } else {
                    echo json_encode(['success' => false, 'message' => 'Student not found']);
                }
                break;
                
            default:
                echo json_encode(['success' => false, 'message' => 'Invalid action']);
        }
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
    }
    exit;
}

// Helper function to determine student status
function getStudentStatus($student) {
    if ($student['verified'] == 0) {
        return ['status' => 'unverified', 'text' => 'Unverified'];
    } elseif ($student['login_attempts'] >= 3 || $student['status'] == 'Blocked') {
        return ['status' => 'blocked', 'text' => 'Blocked'];
    } elseif ($student['status'] == 'Active') {
        return ['status' => 'active', 'text' => 'Active'];
    } else {
        return ['status' => 'inactive', 'text' => 'Inactive'];
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>OnTheJob Tracker - Student Accounts</title>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
  <link rel="stylesheet" href="css/AdviserDashboard.css" />
  <link rel="stylesheet" href="css/studentaccount.css" />  
   <style>
     /* Modal Styles */
    .modal {
      position: fixed;
      z-index: 10000;
      left: 0;
      top: 0;
      width: 100%;
      height: 100%;
      background-color: rgba(0,0,0,0.5);
      display: flex;
      align-items: center;
      justify-content: center;
    }

    .modal-content {
      background-color: #fefefe;
      border-radius: 12px;
      box-shadow: 0 10px 30px rgba(0,0,0,0.3);
      width: 90%;
      max-width: 800px;
      max-height: 80vh;
      overflow-y: auto;
      animation: modalSlideIn 0.3s ease-out;
    }

    @keyframes modalSlideIn {
      from {
        transform: translateY(-50px);
        opacity: 0;
      }
      to {
        transform: translateY(0);
        opacity: 1;
      }
    }

    .modal-header {
      padding: 20px 30px;
      border-bottom: 1px solid #e9ecef;
      display: flex;
      justify-content: space-between;
      align-items: center;
    }

    .modal-header h3 {
      margin: 0;
      color: #2c3e50;
      font-weight: 600;
    }

    .close {
      color: #aaa;
      font-size: 28px;
      font-weight: bold;
      cursor: pointer;
      transition: color 0.3s ease;
    }

    .close:hover {
      color: #000;
    }

    .modal-body {
      padding: 30px;
    }

    .loading-content {
      text-align: center;
      padding: 40px 20px;
    }

    .spinner {
      width: 40px;
      height: 40px;
      border: 4px solid #f3f3f3;
      border-top: 4px solid #007bff;
      border-radius: 50%;
      animation: spin 1s linear infinite;
      margin: 0 auto 20px;
    }

    @keyframes spin {
      0% { transform: rotate(0deg); }
      100% { transform: rotate(360deg); }
    }

    .student-details-grid {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
      gap: 20px;
      margin-top: 20px;
    }

    .detail-item {
      background: #f8f9fa;
      padding: 15px;
      border-radius: 8px;
      border-left: 4px solid #007bff;
    }

    .detail-label {
      font-weight: 600;
      color: #495057;
      font-size: 0.9em;
      margin-bottom: 5px;
    }

    .detail-value {
      color: #2c3e50;
      font-size: 1em;
    }

    .student-row {
      cursor: pointer;
      transition: all 0.2s ease;
    }

    .student-row:hover {
      background-color: #f8f9fa !important;
      transform: translateX(2px);
    }

    .status-blocked {
      background: #dc3545;
      color: white;
    }

    /* Enhanced button styles */
    .btn {
      padding: 8px 12px;
      border: none;
      border-radius: 6px;
      cursor: pointer;
      font-size: 14px;
      transition: all 0.2s ease;
      margin: 0 2px;
    }

    .btn:hover {
      transform: translateY(-2px);
      box-shadow: 0 4px 8px rgba(0,0,0,0.15);
    }

    .btn-primary {
      background: #007bff;
      color: white;
    }

    .btn-primary:hover {
      background: #0056b3;
    }

    .btn-success {
      background: #28a745;
      color: white;
    }

    .btn-success:hover {
      background: #1e7e34;
    }

    .btn-warning {
      background: #ffc107;
      color: #212529;
    }

    .btn-warning:hover {
      background: #e0a800;
    }

    .btn-danger {
      background: #dc3545;
      color: white;
    }

    .btn-danger:hover {
      background: #c82333;
    }

    .btn-info {
      background: #17a2b8;
      color: white;
    }

    .btn-info:hover {
      background: #138496;
    }
    /* Notification styles */
    .notification {
      position: fixed;
      top: 20px;
      right: 20px;
      background: white;
      border-radius: 8px;
      padding: 15px 20px;
      box-shadow: 0 4px 12px rgba(0,0,0,0.15);
      z-index: 10001;
      transform: translateX(400px);
      opacity: 0;
      transition: all 0.3s ease;
      display: flex;
      align-items: center;
      gap: 10px;
      min-width: 300px;
    }

    .notification.show {
      transform: translateX(0);
      opacity: 1;
    }

    .notification.success {
      border-left: 4px solid #28a745;
      color: #155724;
    }

    .notification.success i {
      color: #28a745;
    }

    .notification.error {
      border-left: 4px solid #dc3545;
      color: #721c24;
    }

    .notification.error i {
      color: #dc3545;
    }

    /* No data styles */
    .no-data {
      text-align: center;
      padding: 60px 20px;
      color: #6c757d;
    }

    .no-data i {
      font-size: 48px;
      margin-bottom: 20px;
      opacity: 0.5;
    }

    .no-data h3 {
      margin: 0 0 10px 0;
      color: #495057;
    }

    .no-data p {
      margin: 0;
      font-size: 16px;
    }

    /* Dropdown styles */
    .dropdown-content.show {
      display: block;
    }

    /* Pagination styles */
    .pagination-container {
      display: flex;
      justify-content: center;
      margin-top: 30px;
    }

    .pagination {
      display: flex;
      gap: 5px;
    }

    .pagination-btn {
      padding: 10px 15px;
      border: 1px solid #dee2e6;
      background: white;
      color: #007bff;
      text-decoration: none;
      border-radius: 6px;
      transition: all 0.2s ease;
    }

    .pagination-btn:hover {
      background: #e9ecef;
      transform: translateY(-1px);
    }

    .pagination-btn.active {
      background: #007bff;
      color: white;
      border-color: #007bff;
    }

    .pagination-btn i {
      margin: 0 5px;
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
       <a href="blocking.php" class="nav-item active">
        <i class="fas fa-user-graduate"></i>
        Blocking Student
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
      <a href="academicAdviserEdit.php" class="nav-item">
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
        <input type="text" placeholder="Search...">
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
            <a href="home.php" class="dropdown-item">
              <i class="fas fa-sign-out-alt"></i>
              Logout
            </a>
          </div>
        </div>
      </div>
    </div>
    
    <div class="student-accounts-container">
      <div class="dashboard-header">
        <div>
          <div class="dashboard-title">Student Accounts</div>
          <div class="dashboard-subtitle">Monitor and manage student accounts in the OJT program</div>
        </div>
      </div>
      
      <?php if (isset($error_message)): ?>
        <div class="error-message"><?php echo $error_message; ?></div>
      <?php endif; ?>
      
      <!-- Summary Cards -->
      <div class="student-summary">
        <div class="summary-card active">
          <div class="summary-title">Active Students</div>
          <div class="summary-number"><?php echo $active_students; ?></div>
        </div>
        <div class="summary-card blocked">
          <div class="summary-title">Blocked Students</div>
          <div class="summary-number"><?php echo $blocked_students; ?></div>
        </div>
        <div class="summary-card unverified">
          <div class="summary-title">Unverified Students</div>
          <div class="summary-number"><?php echo $unverified_students; ?></div>
        </div>
      </div>
      
      <!-- Filters Section -->
      <div class="filters-section">
        <input type="text" class="search-input" id="searchInput" placeholder="Search students..." value="<?php echo htmlspecialchars($search); ?>">
        
        <select class="filter-select" id="departmentFilter">
          <option value="">All Departments</option>
          <?php while ($dept = mysqli_fetch_assoc($departments_result)): ?>
            <option value="<?php echo htmlspecialchars($dept['department']); ?>" 
                    <?php echo $department_filter === $dept['department'] ? 'selected' : ''; ?>>
              <?php echo htmlspecialchars($dept['department']); ?>
            </option>
          <?php endwhile; ?>
        </select>
        
        <select class="filter-select" id="statusFilter">
          <option value="">All Status</option>
          <option value="Active" <?php echo $status_filter === 'Active' ? 'selected' : ''; ?>>Active</option>
          <option value="blocked" <?php echo $status_filter === 'blocked' ? 'selected' : ''; ?>>Blocked</option>
          <option value="verified" <?php echo $status_filter === 'verified' ? 'selected' : ''; ?>>Verified</option>
          <option value="unverified" <?php echo $status_filter === 'unverified' ? 'selected' : ''; ?>>Unverified</option>
        </select>
      </div>
      
      <!-- Students Table -->
      <div class="students-table-container">
        <div class="table-header">
          <div class="table-title">Student List</div>
          <div class="table-info">
            Showing <?php echo ($offset + 1); ?>-<?php echo min($offset + $records_per_page, $total_records); ?> of <?php echo $total_records; ?> students
          </div>
        </div>
        
        <?php if (mysqli_num_rows($students_result) > 0): ?>
          <table class="students-table">
            <thead>
              <tr>
                <th>Student</th>
                <th>Student ID</th>
                <th>Department</th>
                <th>Program</th>
                <th>Year & Section</th>
                <th>Status</th>
                <th>Actions</th>
              </tr>
            </thead>
            <tbody>
              <?php while ($student = mysqli_fetch_assoc($students_result)): ?>
                <?php $studentStatus = getStudentStatus($student); ?>
                <tr class="student-row" data-student-id="<?php echo $student['id']; ?>" onclick="viewStudentDetails(<?php echo $student['id']; ?>)">
                  <td>
                    <div class="student-info">
                      <div class="student-avatar">
                        <?php echo strtoupper(substr($student['first_name'], 0, 1)); ?>
                      </div>
                      <div class="student-details">
                        <h4><?php echo htmlspecialchars($student['first_name'] . ' ' . $student['last_name']); ?></h4>
                        <p><?php echo htmlspecialchars($student['email']); ?></p>
                        <?php if ($student['login_attempts'] >= 3): ?>
                          <small style="color: #dc3545;">Login attempts: <?php echo $student['login_attempts']; ?></small>
                        <?php endif; ?>
                      </div>
                    </div>
                  </td>
                  <td><?php echo htmlspecialchars($student['student_id']); ?></td>
                  <td><?php echo htmlspecialchars($student['department'] ?: 'Not Assigned'); ?></td>
                  <td><?php echo htmlspecialchars($student['program'] ?: 'Not Assigned'); ?></td>
                  <td><?php echo htmlspecialchars(($student['year_level'] ?: '') . ($student['section'] ? ' - ' . $student['section'] : '') ?: 'Not Assigned'); ?></td>
                  <td>
                    <span class="status-badge status-<?php echo $studentStatus['status']; ?>">
                      <?php echo $studentStatus['text']; ?>
                    </span>
                  </td>
                  <td onclick="event.stopPropagation()">
                    <div class="action-buttons">
                      <?php if ($student['verified'] == 0): ?>
                        <button class="btn btn-success" onclick="performAction(<?php echo $student['id']; ?>, 'verify')" title="Verify Student">
                          <i class="fas fa-check"></i>
                        </button>
                      <?php endif; ?>
                      
                      <?php if ($student['login_attempts'] >= 3 || $student['status'] == 'Blocked'): ?>
                        <button class="btn btn-warning" onclick="performAction(<?php echo $student['id']; ?>, 'unblock')" title="Unblock Student">
                          <i class="fas fa-unlock"></i>
                        </button>
                      <?php else: ?>
                        <button class="btn btn-danger" onclick="performAction(<?php echo $student['id']; ?>, 'block')" title="Block Student">
                          <i class="fas fa-ban"></i>
                        </button>
                      <?php endif; ?>
                      
                      <?php if ($student['status'] != 'Active'): ?>
                        <button class="btn btn-primary" onclick="performAction(<?php echo $student['id']; ?>, 'activate')" title="Activate Student">
                          <i class="fas fa-play"></i>
                        </button>
                      <?php endif; ?>
                    </div>
                  </td>
                </tr>
              <?php endwhile; ?>
            </tbody>
          </table>
        <?php else: ?>
          <div class="no-data">
            <i class="fas fa-users"></i>
            <h3>No students found</h3>
            <p>No students match your current search criteria.</p>
          </div>
        <?php endif; ?>
      </div>
      
      <!-- Pagination -->
      <?php if ($total_pages > 1): ?>
        <div class="pagination-container">
          <div class="pagination">
            <?php if ($page > 1): ?>
              <a href="?page=<?php echo ($page - 1); ?>&search=<?php echo urlencode($search); ?>&department=<?php echo urlencode($department_filter); ?>&status=<?php echo urlencode($status_filter); ?>" class="pagination-btn">
                <i class="fas fa-chevron-left"></i> Previous
              </a>
            <?php endif; ?>
            
            <?php
            $start_page = max(1, $page - 2);
            $end_page = min($total_pages, $page + 2);
            
            for ($i = $start_page; $i <= $end_page; $i++): ?>
              <a href="?page=<?php echo $i; ?>&search=<?php echo urlencode($search); ?>&department=<?php echo urlencode($department_filter); ?>&status=<?php echo urlencode($status_filter); ?>" 
                 class="pagination-btn <?php echo $i == $page ? 'active' : ''; ?>">
                <?php echo $i; ?>
              </a>
            <?php endfor; ?>
            
            <?php if ($page < $total_pages): ?>
              <a href="?page=<?php echo ($page + 1); ?>&search=<?php echo urlencode($search); ?>&department=<?php echo urlencode($department_filter); ?>&status=<?php echo urlencode($status_filter); ?>" class="pagination-btn">
                Next <i class="fas fa-chevron-right"></i>
              </a>
            <?php endif; ?>
          </div>
        </div>
      <?php endif; ?>
    </div>
  </div>

  <!-- Student Details Modal -->
  <div id="studentModal" class="modal" style="display: none;">
    <div class="modal-content">
      <div class="modal-header">
        <h3>Student Details</h3>
        <span class="close" onclick="closeModal('studentModal')">&times;</span>
      </div>
      <div class="modal-body">
        <div class="loading-content" id="studentModalLoading">
          <div class="spinner"></div>
          <p>Loading student information...</p>
        </div>
        <div id="studentModalContent" style="display: none;">
          <div id="studentDetailsContent"></div>
        </div>
      </div>
    </div>
  </div>

  <script>
    let currentStudentId = null;

    // Search functionality
    document.getElementById('searchInput').addEventListener('input', function() {
      clearTimeout(this.searchTimeout);
      this.searchTimeout = setTimeout(() => {
        applyFilters();
      }, 500);
    });

    document.getElementById('departmentFilter').addEventListener('change', applyFilters);
    document.getElementById('statusFilter').addEventListener('change', applyFilters);

    function applyFilters() {
      const search = document.getElementById('searchInput').value;
      const department = document.getElementById('departmentFilter').value;
      const status = document.getElementById('statusFilter').value;
      
      const params = new URLSearchParams();
      if (search) params.append('search', search);
      if (department) params.append('department', department);
      if (status) params.append('status', status);
      
      window.location.href = 'StudentAccounts.php?' + params.toString();
    }

    // Modal functions
    function openModal(modalId) {
      document.getElementById(modalId).style.display = 'flex';
      document.body.style.overflow = 'hidden';
    }

    function closeModal(modalId) {
      document.getElementById(modalId).style.display = 'none';
      document.body.style.overflow = 'auto';
    }

    // Student actions
    function performAction(studentId, action) {
      if (confirm(`Are you sure you want to ${action} this student?`)) {
        const formData = new FormData();
        formData.append('action', action);
        formData.append('student_id', studentId);
        
        fetch('StudentAccounts.php', {
          method: 'POST',
          body: formData
        })
        .then(response => response.json())
        .then(data => {
          if (data.success) {
            showNotification(data.message, 'success');
            setTimeout(() => {
              window.location.reload();
            }, 1500);
          } else {
            showNotification(data.message, 'error');
          }
        })
        .catch(error => {
          console.error('Error:', error);
          showNotification('An error occurred while processing the request.', 'error');
        });
      }
    }

    // View student details
    function viewStudentDetails(studentId) {
      currentStudentId = studentId;
      openModal('studentModal');
      
      // Show loading state
      document.getElementById('studentModalLoading').style.display = 'block';
      document.getElementById('studentModalContent').style.display = 'none';
      
      const formData = new FormData();
      formData.append('action', 'get_details');
      formData.append('student_id', studentId);
      
      fetch('StudentAccounts.php', {
        method: 'POST',
        body: formData
      })
      .then(response => response.json())
      .then(data => {
        document.getElementById('studentModalLoading').style.display = 'none';
        document.getElementById('studentModalContent').style.display = 'block';
        
        if (data.success) {
          displayStudentDetails(data.student);
        } else {
          showNotification(data.message, 'error');
        }
      })
      .catch(error => {
        console.error('Error:', error);
        document.getElementById('studentModalLoading').style.display = 'none';
        showNotification('An error occurred while loading student details.', 'error');
      });
    }

    function displayStudentDetails(student) {
      const statusInfo = getStudentStatusInfo(student);
      
      const detailsHTML = `
        <div class="student-details-grid">
          <div class="detail-item">
            <div class="detail-label">Full Name</div>
            <div class="detail-value">${student.first_name} ${student.middle_name || ''} ${student.last_name}</div>
          </div>
          <div class="detail-item">
            <div class="detail-label">Student ID</div>
            <div class="detail-value">${student.student_id}</div>
          </div>
          <div class="detail-item">
            <div class="detail-label">Email</div>
            <div class="detail-value">${student.email}</div>
          </div>
          <div class="detail-item">
            <div class="detail-label">Department</div>
            <div class="detail-value">${student.department || 'Not Assigned'}</div>
          </div>
          <div class="detail-item">
            <div class="detail-label">Program</div>
            <div class="detail-value">${student.program || 'Not Assigned'}</div>
          </div>
          <div class="detail-item">
            <div class="detail-label">Year Level</div>
            <div class="detail-value">${student.year_level || 'Not Assigned'}</div>
          </div>
          <div class="detail-item">
            <div class="detail-label">Section</div>
            <div class="detail-value">${student.section || 'Not Assigned'}</div>
          </div>
          <div class="detail-item">
            <div class="detail-label">Status</div>
            <div class="detail-value">
              <span class="status-badge status-${statusInfo.status}">${statusInfo.text}</span>
            </div>
          </div>
          <div class="detail-item">
            <div class="detail-label">Verification Status</div>
            <div class="detail-value">${student.verified == 1 ? 'Verified' : 'Unverified'}</div>
          </div>
          <div class="detail-item">
            <div class="detail-label">Login Attempts</div>
            <div class="detail-value">${student.login_attempts}</div>
          </div>
          <div class="detail-item">
            <div class="detail-label">Registration Date</div>
            <div class="detail-value">${formatDate(student.created_at)}</div>
          </div>
          <div class="detail-item">
            <div class="detail-label">Last Login</div>
            <div class="detail-value">${student.last_login ? formatDate(student.last_login) : 'Never'}</div>
          </div>
        </div>
      `;
      
      document.getElementById('studentDetailsContent').innerHTML = detailsHTML;
    }

    function getStudentStatusInfo(student) {
      if (student.verified == 0) {
        return { status: 'unverified', text: 'Unverified' };
      } else if (student.login_attempts >= 3 || student.status == 'Blocked') {
        return { status: 'blocked', text: 'Blocked' };
      } else if (student.status == 'Active') {
        return { status: 'active', text: 'Active' };
      } else {
        return { status: 'inactive', text: 'Inactive' };
      }
    }

    // Utility functions
    function formatDate(dateString) {
      const date = new Date(dateString);
      return date.toLocaleDateString() + ' ' + date.toLocaleTimeString();
    }

    function showNotification(message, type) {
      // Create notification element
      const notification = document.createElement('div');
      notification.className = `notification ${type}`;
      notification.innerHTML = `
        <i class="fas ${type === 'success' ? 'fa-check-circle' : 'fa-exclamation-circle'}"></i>
        <span>${message}</span>
      `;
      
      // Add to page
      document.body.appendChild(notification);
      
      // Show notification
      setTimeout(() => notification.classList.add('show'), 10);
      
      // Remove notification after 5 seconds
      setTimeout(() => {
        notification.classList.remove('show');
        setTimeout(() => document.body.removeChild(notification), 300);
      }, 5000);
    }

    // Profile dropdown functionality
    function toggleDropdown() {
      const dropdown = document.getElementById('profileDropdownTop');
      dropdown.classList.toggle('show');
    }

// Close dropdown when clicking outside
    window.addEventListener('click', function(event) {
      if (!event.target.matches('.profile-pic') && !event.target.closest('.profile-pic')) {
        const dropdown = document.getElementById('profileDropdownTop');
        if (dropdown && dropdown.classList.contains('show')) {
          dropdown.classList.remove('show');
        }
      }
    });

    // Close modal when clicking outside
    window.addEventListener('click', function(event) {
      const modal = document.getElementById('studentModal');
      if (event.target === modal) {
        closeModal('studentModal');
      }
    });

    // Handle keyboard events
    document.addEventListener('keydown', function(event) {
      // Close modal on Escape key
      if (event.key === 'Escape') {
        const modal = document.getElementById('studentModal');
        if (modal.style.display === 'flex') {
          closeModal('studentModal');
        }
      }
      
      // Search shortcut (Ctrl/Cmd + K)
      if ((event.ctrlKey || event.metaKey) && event.key === 'k') {
        event.preventDefault();
        document.getElementById('searchInput').focus();
      }
    });

    // Initialize page
    document.addEventListener('DOMContentLoaded', function() {
      // Focus search input if there's a search parameter
      const urlParams = new URLSearchParams(window.location.search);
      if (urlParams.get('search')) {
        document.getElementById('searchInput').focus();
      }
      
      // Add loading states to action buttons
      const actionButtons = document.querySelectorAll('.action-buttons .btn');
      actionButtons.forEach(button => {
        button.addEventListener('click', function() {
          if (!this.classList.contains('loading')) {
            this.classList.add('loading');
            const originalText = this.innerHTML;
            this.innerHTML = '<i class="fas fa-spinner fa-spin"></i>';
            
            // Remove loading state after 3 seconds (failsafe)
            setTimeout(() => {
              this.classList.remove('loading');
              this.innerHTML = originalText;
            }, 3000);
          }
        });
      });
      
      // Add hover effects for table rows
      const tableRows = document.querySelectorAll('.student-row');
      tableRows.forEach(row => {
        row.addEventListener('mouseenter', function() {
          this.style.transform = 'translateY(-1px)';
        });
        
        row.addEventListener('mouseleave', function() {
          this.style.transform = 'translateY(0)';
        });
      });
      
      // Auto-refresh page every 5 minutes to keep data current
      setInterval(() => {
        // Only refresh if no modal is open
        const modal = document.getElementById('studentModal');
        if (!modal || modal.style.display === 'none') {
          window.location.reload();
        }
      }, 300000); // 5 minutes
    });

    // Export functions for global access if needed
    window.performAction = performAction;
    window.viewStudentDetails = viewStudentDetails;
    window.toggleDropdown = toggleDropdown;
    window.openModal = openModal;
    window.closeModal = closeModal;
</script>
    
    </body>
</html>