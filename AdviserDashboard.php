<?php
include('connect.php');
session_start();

// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Check if user is logged in and is an adviser
if (!isset($_SESSION['adviser_id']) || $_SESSION['user_type'] !== 'adviser') {
    header("Location: login.php");
    exit;
}

// Get adviser information with default values
$adviser_id = $_SESSION['adviser_id'];
$adviser_name = $_SESSION['name'] ?? 'Academic Adviser';
$adviser_email = $_SESSION['email'] ?? '';

// Initialize all variables with safe defaults
$total_students = 0;
$total_supervisors = 0;
$error_messages = [];

// Test database connection first
if (!$conn) {
    die("Database connection failed: " . mysqli_connect_error());
}

// Simple queries with proper error handling
try {
    // Test if Academic_Adviser table exists
    $test_query = "SHOW TABLES LIKE 'Academic_Adviser'";
    $test_result = mysqli_query($conn, $test_query);
    if (!$test_result || mysqli_num_rows($test_result) == 0) {
        $error_messages[] = "Academic_Adviser table does not exist";
    }
    
    // Test if students table exists
    $test_query = "SHOW TABLES LIKE 'students'";
    $test_result = mysqli_query($conn, $test_query);
    if ($test_result && mysqli_num_rows($test_result) > 0) {
        // Count students
        $students_query = "SELECT COUNT(*) as total FROM students";
        $students_result = mysqli_query($conn, $students_query);
        if ($students_result) {
            $row = mysqli_fetch_assoc($students_result);
            $total_students = (int)$row['total'];
        } else {
            $error_messages[] = "Failed to query students table: " . mysqli_error($conn);
        }
    } else {
        $error_messages[] = "Students table does not exist";
    }
    
    // Test if company_supervisors table exists
    $test_query = "SHOW TABLES LIKE 'company_supervisors'";
    $test_result = mysqli_query($conn, $test_query);
    if ($test_result && mysqli_num_rows($test_result) > 0) {
        // Count supervisors
        $supervisors_query = "SELECT COUNT(*) as total FROM company_supervisors";
        $supervisors_result = mysqli_query($conn, $supervisors_query);
        if ($supervisors_result) {
            $row = mysqli_fetch_assoc($supervisors_result);
            $total_supervisors = (int)$row['total'];
        } else {
            $error_messages[] = "Failed to query company_supervisors table: " . mysqli_error($conn);
        }
    } else {
        $error_messages[] = "Company_supervisors table does not exist";
    }

} catch (Exception $e) {
    $error_messages[] = "Database error: " . $e->getMessage();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Academic Adviser Dashboard - Debug Mode</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gray-100 p-8">
    <div class="max-w-4xl mx-auto">
        <div class="bg-white rounded-lg shadow p-6 mb-6">
            <h1 class="text-2xl font-bold mb-4">Academic Adviser Dashboard - Debug Mode</h1>
            <p class="text-gray-600 mb-4">Welcome, <?php echo htmlspecialchars($adviser_name); ?>!</p>
            
            <!-- Session Info -->
            <div class="bg-blue-50 p-4 rounded mb-4">
                <h3 class="font-semibold text-blue-800">Session Information:</h3>
                <p>Adviser ID: <?php echo $adviser_id; ?></p>
                <p>Email: <?php echo htmlspecialchars($adviser_email); ?></p>
                <p>User Type: <?php echo $_SESSION['user_type'] ?? 'Not set'; ?></p>
            </div>
            
            <!-- Error Messages -->
            <?php if (!empty($error_messages)): ?>
                <div class="bg-red-50 border border-red-200 p-4 rounded mb-4">
                    <h3 class="font-semibold text-red-800 mb-2">Debug Messages:</h3>
                    <ul class="text-red-700 text-sm">
                        <?php foreach ($error_messages as $error): ?>
                            <li>• <?php echo htmlspecialchars($error); ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>
            
            <!-- Basic Stats -->
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-6">
                <div class="bg-green-50 p-4 rounded">
                    <h3 class="font-semibold text-green-800">Total Students</h3>
                    <p class="text-2xl font-bold text-green-600"><?php echo $total_students; ?></p>
                </div>
                
                <div class="bg-blue-50 p-4 rounded">
                    <h3 class="font-semibold text-blue-800">Company Supervisors</h3>
                    <p class="text-2xl font-bold text-blue-600"><?php echo $total_supervisors; ?></p>
                </div>
            </div>
            
            <!-- Database Tables Check -->
            <div class="bg-gray-50 p-4 rounded">
                <h3 class="font-semibold text-gray-800 mb-2">Database Tables Status:</h3>
                <?php
                $required_tables = [
                    'Academic_Adviser',
                    'students', 
                    'company_supervisors',
                    'student_deployments',
                    'messages',
                    'student_documents'
                ];
                
                foreach ($required_tables as $table) {
                    $check_query = "SHOW TABLES LIKE '$table'";
                    $check_result = mysqli_query($conn, $check_query);
                    $exists = $check_result && mysqli_num_rows($check_result) > 0;
                    
                    echo "<p class='text-sm'>";
                    echo "<span class='" . ($exists ? "text-green-600" : "text-red-600") . "'>";
                    echo $exists ? "✓" : "✗";
                    echo "</span> ";
                    echo htmlspecialchars($table);
                    echo "</p>";
                }
                ?>
            </div>
            
            <!-- Navigation Links -->
            <div class="mt-6 space-x-4">
                <a href="logout.php" class="bg-red-500 text-white px-4 py-2 rounded hover:bg-red-600">Logout</a>
                <a href="login.php" class="bg-gray-500 text-white px-4 py-2 rounded hover:bg-gray-600">Back to Login</a>
            </div>
        </div>
    </div>
</body>
</html>