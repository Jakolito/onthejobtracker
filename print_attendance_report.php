<?php
include('connect.php');
session_start();

// Prevent caching
header("Cache-Control: no-cache, no-store, must-revalidate");
header("Pragma: no-cache");
header("Expires: 0");

if (!isset($_SESSION['supervisor_id']) || $_SESSION['user_type'] !== 'supervisor') {
    header("Location: login.php");
    exit;
}

// Get supervisor information
$supervisor_id = $_SESSION['supervisor_id'];

// Fetch supervisor data
try {
    $stmt = $conn->prepare("SELECT * FROM company_supervisors WHERE supervisor_id = ?");
    $stmt->bind_param("i", $supervisor_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows > 0) {
        $supervisor = $result->fetch_assoc();
        $supervisor_name = $supervisor['full_name'];
        $supervisor_email = $supervisor['email'];
        $company_name = $supervisor['company_name'];
    } else {
        header("Location: login.php");
        exit();
    }
} catch (Exception $e) {
    $supervisor_name = $_SESSION['full_name'];
    $supervisor_email = $_SESSION['email'];
    $company_name = $_SESSION['company_name'];
}

// Set timezone
date_default_timezone_set('Asia/Manila');
$conn->query("SET time_zone = '+08:00'");

// Get parameters from URL
$student_id = $_GET['student_id'] ?? '';
$date_from = $_GET['date_from'] ?? date('Y-m-01');
$date_to = $_GET['date_to'] ?? date('Y-m-d');

try {
    // Get student information
    $student_info = null;
    if (!empty($student_id)) {
        $stmt = $conn->prepare("
            SELECT s.*, sd.deployment_id, sd.company_name, sd.position, 
                   sd.start_date, sd.end_date, sd.required_hours, sd.completed_hours,
                   sd.supervisor_name, sd.status as deployment_status
            FROM students s
            JOIN student_deployments sd ON s.id = sd.student_id
            WHERE s.id = ? AND sd.supervisor_id = ? AND sd.status = 'Active'
        ");
        $stmt->bind_param("ii", $student_id, $supervisor_id);
        $stmt->execute();
        $student_info = $stmt->get_result()->fetch_assoc();
    }
    
    // Build query for attendance records
    $where_conditions = ["sd.supervisor_id = ?"];
    $params = [$supervisor_id];
    $param_types = "i";
    
    if (!empty($student_id)) {
        $where_conditions[] = "s.id = ?";
        $params[] = $student_id;
        $param_types .= "i";
    }
    
    // Date filter
    $where_conditions[] = "sa.date BETWEEN ? AND ?";
    $params[] = $date_from;
    $params[] = $date_to;
    $param_types .= "ss";
    
    $where_clause = "WHERE " . implode(" AND ", $where_conditions);
    
    // Get attendance records
    $query = "
        SELECT sa.*, s.first_name, s.last_name, s.middle_name, s.student_id, 
               s.program, s.year_level,
               sd.position, sd.company_name, sd.deployment_id,
               CASE 
                   WHEN sa.time_in > '08:30:00' THEN 'Late'
                   WHEN sa.time_in IS NULL THEN 'Absent'
                   ELSE 'On Time'
               END as punctuality_status,
               CONCAT(s.first_name, ' ', COALESCE(s.middle_name, ''), ' ', s.last_name) as full_name
        FROM student_attendance sa
        JOIN students s ON sa.student_id = s.id
        JOIN student_deployments sd ON sa.deployment_id = sd.deployment_id
        $where_clause
        ORDER BY sa.date ASC, s.first_name, s.last_name
    ";
    
    $stmt = $conn->prepare($query);
    if (!empty($params)) {
        $stmt->bind_param($param_types, ...$params);
    }
    $stmt->execute();
    $attendance_records = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    
    // Get summary statistics
    $stats_query = "
        SELECT 
            COUNT(*) as total_records,
            COUNT(CASE WHEN sa.status = 'Present' OR sa.status = 'Late' THEN 1 END) as total_present_days,
            COUNT(CASE WHEN sa.status = 'Absent' THEN 1 END) as total_absent_days,
            COUNT(CASE WHEN sa.status = 'Late' THEN 1 END) as total_late_days,
            COUNT(CASE WHEN sa.time_in > '08:30:00' THEN 1 END) as total_late_arrivals,
            COALESCE(SUM(sa.total_hours), 0) as total_hours_sum,
            COUNT(DISTINCT s.id) as total_students
        FROM student_attendance sa
        JOIN students s ON sa.student_id = s.id
        JOIN student_deployments sd ON sa.deployment_id = sd.deployment_id
        $where_clause
    ";
    
    $stats_stmt = $conn->prepare($stats_query);
    if (!empty($params)) {
        $stats_stmt->bind_param($param_types, ...$params);
    }
    $stats_stmt->execute();
    $summary_stats = $stats_stmt->get_result()->fetch_assoc();
    
} catch (Exception $e) {
    $error_message = "Error fetching attendance records: " . $e->getMessage();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Attendance Report - OnTheJob Tracker</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Arial', sans-serif;
            font-size: 11px;
            line-height: 1.3;
            color: #2c3e50;
            background: white;
            padding: 8mm;
        }

        /* Compact header with BULSU colors */
        .header {
            text-align: center;
            border-bottom: 3px solid #800000;
            padding-bottom: 8px;
            margin-bottom: 12px;
            background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
            padding: 10px;
            border-radius: 6px;
        }

        .header h1 {
            font-size: 16px;
            color: #800000;
            font-weight: bold;
            margin-bottom: 2px;
            text-shadow: 0 1px 2px rgba(0,0,0,0.1);
        }

        .header h2 {
            font-size: 13px;
            color: #2c3e50;
            margin-bottom: 4px;
        }

        .header .meta-info {
            display: flex;
            justify-content: space-between;
            font-size: 9px;
            color: #6c757d;
            margin-top: 6px;
            flex-wrap: wrap;
        }

        /* Two-column layout for company and student info */
        .info-container {
            display: flex;
            gap: 10px;
            margin-bottom: 12px;
        }

        .info-box {
            flex: 1;
            padding: 8px;
            border-radius: 4px;
            font-size: 9px;
        }

        .company-info {
            background: linear-gradient(135deg, #e8f5e8 0%, #d4edda 100%);
            border-left: 3px solid #28a745;
        }

        .student-info {
            background: linear-gradient(135deg, #fff3cd 0%, #ffeaa7 100%);
            border-left: 3px solid #DAA520;
        }

        .info-box h3 {
            font-size: 10px;
            margin-bottom: 6px;
            font-weight: 600;
            color: #2c3e50;
        }

        .info-row {
            display: flex;
            justify-content: space-between;
            margin-bottom: 2px;
            align-items: center;
        }

        .info-row .label {
            font-weight: 600;
            color: #495057;
            min-width: 60px;
        }

        .info-row .value {
            color: #6c757d;
            text-align: right;
            flex-grow: 1;
        }

        /* Compact horizontal summary stats */
        .summary-stats {
            display: flex;
            justify-content: space-between;
            margin-bottom: 12px;
            background: linear-gradient(135deg, #e3f2fd 0%, #bbdefb 100%);
            padding: 6px 12px;
            border-radius: 6px;
            border: 1px solid #2196f3;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }

        .stat-item {
            text-align: center;
            flex: 1;
        }

        .stat-number {
            font-size: 14px;
            font-weight: bold;
            color: #1976d2;
            text-shadow: 0 1px 2px rgba(0,0,0,0.1);
        }

        .stat-label {
            font-size: 8px;
            color: #6c757d;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        /* Optimized table for better paper usage */
        .attendance-table {
            width: 100%;
            border-collapse: collapse;
            border: 1px solid #dee2e6;
            font-size: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }

        .attendance-table th {
            background: linear-gradient(135deg, #800000 0%, #a71b3d 100%);
            color: white;
            padding: 4px 2px;
            text-align: center;
            font-weight: 600;
            font-size: 7px;
            text-transform: uppercase;
            border: 1px solid #6b1028;
            letter-spacing: 0.3px;
            text-shadow: 0 1px 2px rgba(0,0,0,0.3);
        }

        .attendance-table td {
            padding: 3px 2px;
            border: 1px solid #dee2e6;
            text-align: center;
            vertical-align: middle;
            font-size: 7px;
        }

        .attendance-table tbody tr:nth-child(even) {
            background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 50%, #f8f9fa 100%);
        }

        .attendance-table tbody tr:hover {
            background: linear-gradient(135deg, #e3f2fd 0%, #bbdefb 50%, #e3f2fd 100%);
        }

        .student-name {
            text-align: left !important;
            font-weight: 600;
            color: #2c3e50;
            font-size: 7px;
            max-width: 80px;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        .student-id {
            font-size: 6px;
            color: #6c757d;
            font-style: italic;
        }

        /* Colorful status badges */
        .status-badge {
            padding: 1px 4px;
            border-radius: 8px;
            font-size: 6px;
            font-weight: 700;
            text-transform: uppercase;
            text-shadow: 0 1px 1px rgba(0,0,0,0.2);
            box-shadow: 0 1px 2px rgba(0,0,0,0.1);
            letter-spacing: 0.2px;
        }

        .status-present { 
            background: linear-gradient(135deg, #28a745 0%, #20c997 100%); 
            color: white; 
        }
        .status-absent { 
            background: linear-gradient(135deg, #dc3545 0%, #fd7e14 100%); 
            color: white; 
        }
        .status-late { 
            background: linear-gradient(135deg, #ffc107 0%, #fd7e14 100%); 
            color: #212529; 
        }
        .status-halfday { 
            background: linear-gradient(135deg, #007bff 0%, #6610f2 100%); 
            color: white; 
        }

        /* Method badges */
        .method-badge {
            padding: 1px 3px;
            border-radius: 4px;
            font-size: 6px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.2px;
        }

        .method-facial { 
            background: linear-gradient(135deg, #17a2b8 0%, #6f42c1 100%); 
            color: white; 
        }
        .method-manual { 
            background: linear-gradient(135deg, #6c757d 0%, #495057 100%); 
            color: white; 
        }

        .confidence-score {
            font-size: 5px;
            color: #6c757d;
            margin-top: 1px;
            font-weight: 500;
        }

        .late-indicator {
            color: #dc3545;
            font-size: 5px;
            font-weight: 600;
            text-transform: uppercase;
        }

        .time-cell {
            font-weight: 600;
            color: #495057;
        }

        .hours-cell {
            font-weight: 600;
            color: #28a745;
        }

        /* Compact footer */
        .footer {
            margin-top: 12px;
            padding-top: 8px;
            border-top: 2px solid #800000;
            text-align: center;
            font-size: 7px;
            color: #6c757d;
            background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
            padding: 6px;
            border-radius: 4px;
        }

        .footer p {
            margin: 1px 0;
        }

        .no-records {
            text-align: center;
            padding: 20px;
            color: #6c757d;
            font-style: italic;
            font-size: 10px;
        }

        /* Print optimizations */
        @media print {
            body {
                padding: 5mm;
                font-size: 10px;
            }
            
            .header {
                padding: 6px;
                margin-bottom: 8px;
            }
            
            .header h1 {
                font-size: 14px;
            }
            
            .header h2 {
                font-size: 11px;
            }
            
            .info-container {
                margin-bottom: 8px;
                gap: 6px;
            }
            
            .info-box {
                padding: 4px;
            }
            
            .summary-stats {
                padding: 4px 8px;
                margin-bottom: 8px;
            }
            
            .stat-number {
                font-size: 12px;
            }
            
            .attendance-table {
                font-size: 7px;
            }
            
            .attendance-table th {
                font-size: 6px;
                padding: 2px 1px;
            }
            
            .attendance-table td {
                padding: 2px 1px;
            }
            
            .student-name {
                font-size: 6px;
            }
            
            .footer {
                margin-top: 8px;
                padding: 4px;
                font-size: 6px;
            }
            
            /* Ensure colors print well */
            .status-present { background: #28a745 !important; }
            .status-absent { background: #dc3545 !important; }
            .status-late { background: #ffc107 !important; color: #000 !important; }
            .status-halfday { background: #007bff !important; }
            .method-facial { background: #17a2b8 !important; }
            .method-manual { background: #6c757d !important; }
        }

        /* Page break control */
        .page-break {
            page-break-before: always;
        }

        .no-break {
            page-break-inside: avoid;
        }

        /* Hide scrollbars and optimize for printing */
        @media screen {
            html, body {
                overflow: hidden;
            }
        }
    </style>
</head>
<body>
    <!-- Compact Header -->
    <div class="header">
        <h1>🎓 OnTheJob Tracker - Attendance Report</h1>
        <h2><?php echo htmlspecialchars($company_name); ?></h2>
        <div class="meta-info">
            <span><strong>Generated:</strong> <?php echo date('M j, Y g:i A'); ?></span>
            <span><strong>Period:</strong> <?php echo date('M j', strtotime($date_from)); ?> - <?php echo date('M j, Y', strtotime($date_to)); ?></span>
            <span><strong>Supervisor:</strong> <?php echo htmlspecialchars($supervisor_name); ?></span>
        </div>
    </div>

    <!-- Two-column info layout -->
    <div class="info-container">
        <div class="info-box company-info">
            <h3>📋 Report Details</h3>
            <div class="info-row">
                <span class="label">Company:</span>
                <span class="value"><?php echo htmlspecialchars($company_name); ?></span>
            </div>
            <div class="info-row">
                <span class="label">Supervisor:</span>
                <span class="value"><?php echo htmlspecialchars($supervisor_name); ?></span>
            </div>
            <div class="info-row">
                <span class="label">Students:</span>
                <span class="value"><?php echo number_format($summary_stats['total_students']); ?></span>
            </div>
        </div>

        <?php if ($student_info): ?>
        <div class="info-box student-info">
            <h3>👤 Student Details</h3>
            <div class="info-row">
                <span class="label">Name:</span>
                <span class="value"><?php echo htmlspecialchars($student_info['first_name'] . ' ' . $student_info['last_name']); ?></span>
            </div>
            <div class="info-row">
                <span class="label">ID:</span>
                <span class="value"><?php echo htmlspecialchars($student_info['student_id']); ?></span>
            </div>
            <div class="info-row">
                <span class="label">Position:</span>
                <span class="value"><?php echo htmlspecialchars($student_info['position'] ?? 'N/A'); ?></span>
            </div>
            <div class="info-row">
                <span class="label">Required:</span>
                <span class="value"><?php echo number_format($student_info['required_hours'] ?? 0); ?>h</span>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <!-- Horizontal summary stats -->
    <div class="summary-stats">
        <div class="stat-item">
            <div class="stat-number"><?php echo number_format($summary_stats['total_records']); ?></div>
            <div class="stat-label">Records</div>
        </div>
        <div class="stat-item">
            <div class="stat-number"><?php echo number_format($summary_stats['total_present_days']); ?></div>
            <div class="stat-label">Present</div>
        </div>
        <div class="stat-item">
            <div class="stat-number"><?php echo number_format($summary_stats['total_absent_days']); ?></div>
            <div class="stat-label">Absent</div>
        </div>
        <div class="stat-item">
            <div class="stat-number"><?php echo number_format($summary_stats['total_late_arrivals']); ?></div>
            <div class="stat-label">Late</div>
        </div>
        <div class="stat-item">
            <div class="stat-number"><?php echo number_format($summary_stats['total_hours_sum'], 0); ?></div>
            <div class="stat-label">Hours</div>
        </div>
    </div>

    <!-- Optimized attendance table -->
    <table class="attendance-table">
        <thead>
            <tr>
                <th style="width: 18%;">Student</th>
                <th style="width: 9%;">Date</th>
                <th style="width: 9%;">Time In</th>
                <th style="width: 9%;">Time Out</th>
                <th style="width: 7%;">Hours</th>
                <th style="width: 12%;">Status</th>
                <th style="width: 10%;">Method</th>
                <th style="width: 26%;">Notes</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($attendance_records)): ?>
                <tr>
                    <td colspan="8" class="no-records">
                        📅 No attendance records found for the selected criteria.
                    </td>
                </tr>
            <?php else: ?>
                <?php foreach ($attendance_records as $index => $record): ?>
                    <tr class="<?php echo ($index > 0 && $index % 30 == 0) ? 'page-break' : ''; ?>">
                        <td class="student-name">
                            <div><?php echo htmlspecialchars($record['first_name'] . ' ' . $record['last_name']); ?></div>
                            <div class="student-id"><?php echo htmlspecialchars($record['student_id']); ?></div>
                        </td>
                        <td>
                            <div><?php echo date('M j', strtotime($record['date'])); ?></div>
                            <div style="font-size: 5px; color: #6c757d;"><?php echo date('D', strtotime($record['date'])); ?></div>
                        </td>
                        <td class="time-cell">
                            <?php if (!empty($record['time_in'])): ?>
                                <div><?php echo date('g:i A', strtotime($record['time_in'])); ?></div>
                                <?php if ($record['punctuality_status'] == 'Late'): ?>
                                    <div class="late-indicator">⏰ LATE</div>
                                <?php endif; ?>
                            <?php else: ?>
                                --
                            <?php endif; ?>
                        </td>
                        <td class="time-cell">
                            <?php echo !empty($record['time_out']) ? date('g:i A', strtotime($record['time_out'])) : '--'; ?>
                        </td>
                        <td class="hours-cell">
                            <?php echo $record['total_hours'] ? number_format($record['total_hours'], 1) : '--'; ?>
                        </td>
                        <td>
                            <?php
                            $status_class = '';
                            switch ($record['status']) {
                                case 'Present': $status_class = 'status-present'; break;
                                case 'Absent': $status_class = 'status-absent'; break;
                                case 'Late': $status_class = 'status-late'; break;
                                case 'Half Day': $status_class = 'status-halfday'; break;
                            }
                            ?>
                            <span class="status-badge <?php echo $status_class; ?>">
                                <?php echo htmlspecialchars($record['status']); ?>
                            </span>
                        </td>
                        <td>
                            <?php if ($record['attendance_method'] == 'facial'): ?>
                                <div class="method-badge method-facial">🤖 AI</div>
                                <?php if ($record['facial_confidence']): ?>
                                    <div class="confidence-score">
                                        <?php echo number_format($record['facial_confidence'] * 100, 0); ?>%
                                    </div>
                                <?php endif; ?>
                            <?php else: ?>
                                <div class="method-badge method-manual">👤 Manual</div>
                            <?php endif; ?>
                        </td>
                        <td style="text-align: left; font-size: 6px; color: #6c757d;">
                            <?php echo htmlspecialchars($record['notes'] ? (strlen($record['notes']) > 50 ? substr($record['notes'], 0, 47) . '...' : $record['notes']) : '--'); ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>

    <!-- Compact footer -->
    <div class="footer">
        <p><strong>🎓 OnTheJob Tracker System | Bulacan State University</strong></p>
        <p>AI-Powered Attendance Management • Generated on <?php echo date('F j, Y g:i A'); ?></p>
    </div>

    <script>
        // Immediately trigger print dialog when page loads
        window.addEventListener('load', function() {
            // Small delay to ensure content is fully rendered
            setTimeout(function() {
                window.print();
            }, 100);
        });
        
        // Handle after print actions
        window.addEventListener('afterprint', function() {
            // Close the window/tab after printing
            window.close();
        });

        // Alternative method for browsers that don't support afterprint
        window.onafterprint = function() {
            window.close();
        };

        // Backup close method - close after 3 seconds if print dialog was cancelled
        setTimeout(function() {
            if (!window.matchMedia('print').matches) {
                // User likely cancelled print or finished printing
                window.close();
            }
        }, 3000);
    </script>
</body>
</html>