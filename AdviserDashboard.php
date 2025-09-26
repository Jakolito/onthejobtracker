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
$adviser_name = $_SESSION['name'] ?? 'Academic Adviser';
$adviser_email = $_SESSION['email'] ?? '';

// Simple database queries with error handling
$total_students = 0;
$total_supervisors = 0;
$error_message = '';

try {
    // Count students
    $students_query = "SELECT COUNT(*) as total FROM students";
    $students_result = mysqli_query($conn, $students_query);
    if ($students_result) {
        $row = mysqli_fetch_assoc($students_result);
        $total_students = (int)$row['total'];
    }
    
    // Count supervisors
    $supervisors_query = "SELECT COUNT(*) as total FROM company_supervisors";
    $supervisors_result = mysqli_query($conn, $supervisors_query);
    if ($supervisors_result) {
        $row = mysqli_fetch_assoc($supervisors_result);
        $total_supervisors = (int)$row['total'];
    }
} catch (Exception $e) {
    $error_message = "Database error: " . $e->getMessage();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Academic Adviser Dashboard</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
    tailwind.config = {
        theme: {
            extend: {
                colors: {
                    'bulsu-maroon': '#800000',
                    'bulsu-dark-maroon': '#6B1028',
                    'bulsu-gold': '#DAA520',
                    'bulsu-light-gold': '#F4E4BC',
                    'bulsu-white': '#FFFFFF'
                }
            }
        }
    }
    </script>
</head>
<body class="bg-gray-100">
    <!-- Simple Header -->
    <div class="bg-gradient-to-r from-bulsu-maroon to-bulsu-dark-maroon text-white p-4">
        <div class="max-w-6xl mx-auto flex justify-between items-center">
            <h1 class="text-2xl font-bold">OnTheJob Tracker - Academic Adviser Dashboard</h1>
            <div class="flex items-center space-x-4">
                <span>Welcome, <?php echo htmlspecialchars($adviser_name); ?>!</span>
                <a href="logout.php" class="bg-red-500 px-3 py-1 rounded hover:bg-red-600" onclick="return confirm('Are you sure you want to logout?')">Logout</a>
            </div>
        </div>
    </div>

    <!-- Main Content -->
    <div class="max-w-6xl mx-auto p-6">
        <?php if (!empty($error_message)): ?>
            <div class="bg-red-50 border border-red-200 text-red-700 px-4 py-3 rounded mb-6">
                <?php echo htmlspecialchars($error_message); ?>
            </div>
        <?php endif; ?>

        <!-- Success Message -->
        <div class="bg-green-50 border border-green-200 text-green-700 px-4 py-3 rounded mb-6">
            <h2 class="text-lg font-semibold">Login Successful!</h2>
            <p>You have successfully logged in to the Academic Adviser Dashboard.</p>
        </div>

        <!-- Session Information -->
        <div class="bg-white rounded-lg shadow p-6 mb-6">
            <h3 class="text-lg font-semibold mb-4 text-bulsu-maroon">Session Information</h3>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                    <p><strong>Adviser ID:</strong> <?php echo htmlspecialchars($adviser_id); ?></p>
                    <p><strong>Name:</strong> <?php echo htmlspecialchars($adviser_name); ?></p>
                    <p><strong>Email:</strong> <?php echo htmlspecialchars($adviser_email); ?></p>
                </div>
                <div>
                    <p><strong>User Type:</strong> <?php echo htmlspecialchars($_SESSION['user_type']); ?></p>
                    <p><strong>Login Status:</strong> <?php echo $_SESSION['logged_in'] ? 'Active' : 'Inactive'; ?></p>
                    <p><strong>Session Started:</strong> <?php echo date('Y-m-d H:i:s'); ?></p>
                </div>
            </div>
        </div>

        <!-- Statistics -->
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 mb-6">
            <div class="bg-white p-6 rounded-lg shadow border-l-4 border-l-green-500">
                <div class="flex items-center justify-between">
                    <div>
                        <div class="text-3xl font-bold text-green-600"><?php echo $total_students; ?></div>
                        <div class="text-sm text-gray-600">Total Students</div>
                    </div>
                    <div class="text-green-500 text-2xl">
                        👨‍🎓
                    </div>
                </div>
            </div>

            <div class="bg-white p-6 rounded-lg shadow border-l-4 border-l-blue-500">
                <div class="flex items-center justify-between">
                    <div>
                        <div class="text-3xl font-bold text-blue-600"><?php echo $total_supervisors; ?></div>
                        <div class="text-sm text-gray-600">Company Supervisors</div>
                    </div>
                    <div class="text-blue-500 text-2xl">
                        👔
                    </div>
                </div>
            </div>

            <div class="bg-white p-6 rounded-lg shadow border-l-4 border-l-purple-500">
                <div class="flex items-center justify-between">
                    <div>
                        <div class="text-3xl font-bold text-purple-600">5</div>
                        <div class="text-sm text-gray-600">Active Deployments</div>
                    </div>
                    <div class="text-purple-500 text-2xl">
                        🚀
                    </div>
                </div>
            </div>

            <div class="bg-white p-6 rounded-lg shadow border-l-4 border-l-orange-500">
                <div class="flex items-center justify-between">
                    <div>
                        <div class="text-3xl font-bold text-orange-600">12</div>
                        <div class="text-sm text-gray-600">Completed OJT</div>
                    </div>
                    <div class="text-orange-500 text-2xl">
                        ✅
                    </div>
                </div>
            </div>
        </div>

        <!-- Quick Actions -->
        <div class="bg-white rounded-lg shadow p-6 mb-6">
            <h3 class="text-lg font-semibold mb-4 text-bulsu-maroon">Quick Actions</h3>
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                <a href="StudentAccounts.php" class="flex items-center p-4 bg-blue-50 rounded-lg border border-blue-200 hover:border-blue-300 transition-all">
                    <div class="w-10 h-10 bg-blue-500 rounded-lg flex items-center justify-center text-white mr-3">
                        👥
                    </div>
                    <div>
                        <p class="font-medium text-gray-900">Manage Students</p>
                        <p class="text-sm text-gray-500">View and manage student accounts</p>
                    </div>
                </a>

                <a href="StudentDeployment.php" class="flex items-center p-4 bg-green-50 rounded-lg border border-green-200 hover:border-green-300 transition-all">
                    <div class="w-10 h-10 bg-green-500 rounded-lg flex items-center justify-center text-white mr-3">
                        📤
                    </div>
                    <div>
                        <p class="font-medium text-gray-900">Deploy Students</p>
                        <p class="text-sm text-gray-500">Assign students to companies</p>
                    </div>
                </a>

                <a href="GenerateReports.php" class="flex items-center p-4 bg-purple-50 rounded-lg border border-purple-200 hover:border-purple-300 transition-all">
                    <div class="w-10 h-10 bg-purple-500 rounded-lg flex items-center justify-center text-white mr-3">
                        📊
                    </div>
                    <div>
                        <p class="font-medium text-gray-900">Generate Reports</p>
                        <p class="text-sm text-gray-500">Create performance reports</p>
                    </div>
                </a>
            </div>
        </div>

        <!-- Database Status -->
        <div class="bg-white rounded-lg shadow p-6">
            <h3 class="text-lg font-semibold mb-4 text-bulsu-maroon">Database Connection Status</h3>
            <div class="space-y-2">
                <?php
                $tables = ['academic_adviser', 'students', 'company_supervisors', 'student_deployments', 'messages', 'student_documents'];
                foreach ($tables as $table) {
                    $check_query = "SHOW TABLES LIKE '$table'";
                    $check_result = mysqli_query($conn, $check_query);
                    $exists = $check_result && mysqli_num_rows($check_result) > 0;
                    
                    echo "<div class='flex items-center'>";
                    echo "<span class='w-4 h-4 rounded-full mr-3 " . ($exists ? "bg-green-500" : "bg-red-500") . "'></span>";
                    echo "<span class='" . ($exists ? "text-green-700" : "text-red-700") . "'>";
                    echo htmlspecialchars($table) . " table: " . ($exists ? "Connected" : "Not Found");
                    echo "</span>";
                    echo "</div>";
                }
                ?>
            </div>
        </div>
    </div>
</body>
</html>