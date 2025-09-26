<?php
require_once('connect.php');
session_start();

// Check if user is logged in and is a company supervisor
if (!isset($_SESSION['supervisor_id']) || $_SESSION['user_type'] !== 'supervisor') {
    header("Location: login.php");
    exit;
}

// Get supervisor information
$supervisor_id = $_SESSION['supervisor_id'];

// Validate student_id parameter
if (!isset($_GET['student_id']) || !is_numeric($_GET['student_id'])) {
    header("Location: CompanyProgressReport.php");
    exit;
}

$student_id = intval($_GET['student_id']);

// Fetch student data with deployment information
$query = "
    SELECT s.*, sd.deployment_id, sd.position, sd.start_date, sd.end_date, 
           sd.required_hours, sd.completed_hours, sd.status as deployment_status,
           sd.company_name, sd.supervisor_name, cs.full_name as supervisor_full_name,
           cs.position as supervisor_position, cs.company_address
    FROM students s
    JOIN student_deployments sd ON s.id = sd.student_id
    JOIN company_supervisors cs ON sd.supervisor_id = cs.supervisor_id
    WHERE s.id = ? AND sd.supervisor_id = ? AND sd.completed_hours >= sd.required_hours
";

$stmt = $conn->prepare($query);
$stmt->bind_param("ii", $student_id, $supervisor_id);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    $_SESSION['error_message'] = "Student not found or has not completed required hours for certification.";
    header("Location: CompanyProgressReport.php");
    exit;
}

$student = $result->fetch_assoc();

// Get additional statistics for the certificate
$stats_query = "
    SELECT 
        COUNT(DISTINCT DATE(date)) as total_work_days,
        SUM(CASE WHEN status = 'Present' THEN 1 ELSE 0 END) as present_days,
        AVG(total_hours) as avg_daily_hours
    FROM student_attendance 
    WHERE student_id = ? AND deployment_id = ?
";

$stmt = $conn->prepare($stats_query);
$stmt->bind_param("ii", $student_id, $student['deployment_id']);
$stmt->execute();
$stats = $stmt->get_result()->fetch_assoc();

// Get latest evaluation for performance rating
$eval_query = "
    SELECT total_score, equivalent_rating, verbal_interpretation, 
           remarks_comments_suggestions as remarks, created_at
    FROM student_evaluations 
    WHERE student_id = ? AND supervisor_id = ?
    ORDER BY created_at DESC 
    LIMIT 1
";

$stmt = $conn->prepare($eval_query);
$stmt->bind_param("ii", $student_id, $supervisor_id);
$stmt->execute();
$eval_result = $stmt->get_result();
$evaluation = $eval_result->num_rows > 0 ? $eval_result->fetch_assoc() : null;

// Calculate completion percentage and other metrics
$completion_percentage = ($student['completed_hours'] / $student['required_hours']) * 100;
$attendance_rate = $stats['total_work_days'] > 0 ? ($stats['present_days'] / $stats['total_work_days']) * 100 : 0;

// Generate certificate ID
$certificate_id = 'BULSU-OJT-' . date('Y') . '-' . str_pad($student_id, 4, '0', STR_PAD_LEFT) . '-' . strtoupper(substr(md5($student['student_id'] . $student['deployment_id']), 0, 6));

// Student full name
$student_full_name = trim($student['first_name'] . ' ' . ($student['middle_name'] ? $student['middle_name'] . ' ' : '') . $student['last_name']);

// Format dates
$start_date = date('F j, Y', strtotime($student['start_date']));
$end_date = date('F j, Y', strtotime($student['end_date']));
$completion_date = date('F j, Y');

// Duration calculation
$start_timestamp = strtotime($student['start_date']);
$end_timestamp = strtotime($student['end_date']);
$duration_weeks = ceil(($end_timestamp - $start_timestamp) / (7 * 24 * 60 * 60));

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>BulSU OJT Completion Certificate</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js"></script>
    <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@400;700;900&family=Inter:wght@300;400;500;600&display=swap" rel="stylesheet">
    
    <style>
        :root {
            --bulsu-maroon: #862D48;
            --bulsu-gold: #DAA520;
            --bulsu-dark-maroon: #6B1028;
            --bulsu-light-gold: #F4E4BC;
        }
        
        @page {
            size: A4;
            margin: 0;
        }
        
        .certificate-bg {
            background: linear-gradient(135deg, #fefefe 0%, var(--bulsu-light-gold) 100%);
            position: relative;
            overflow: hidden;
        }
        
        .certificate-bg::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: 
                radial-gradient(circle at 20% 80%, rgba(134, 45, 72, 0.08) 0%, transparent 50%),
                radial-gradient(circle at 80% 20%, rgba(218, 165, 32, 0.06) 0%, transparent 50%),
                linear-gradient(45deg, transparent 40%, rgba(134, 45, 72, 0.02) 50%, transparent 60%);
            pointer-events: none;
        }
        
        .geometric-accent {
            position: absolute;
            width: 200px;
            height: 200px;
            background: linear-gradient(135deg, rgba(134, 45, 72, 0.12), rgba(218, 165, 32, 0.1));
            clip-path: polygon(0 0, 100% 0, 85% 100%, 0% 85%);
        }
        
        .geometric-accent.top-left {
            top: -50px;
            left: -50px;
        }
        
        .geometric-accent.bottom-right {
            bottom: -50px;
            right: -50px;
            transform: rotate(180deg);
        }
        
        .certificate-border {
            background: linear-gradient(90deg, var(--bulsu-maroon), var(--bulsu-gold), var(--bulsu-maroon));
            padding: 4px;
            border-radius: 12px;
            box-shadow: 0 0 20px rgba(134, 45, 72, 0.2);
        }
        
        .certificate-inner {
            background: white;
            border-radius: 8px;
            border: 2px solid var(--bulsu-light-gold);
        }
        
        .title-font {
            font-family: 'Playfair Display', serif;
        }
        
        .body-font {
            font-family: 'Inter', sans-serif;
        }
        
        .signature-line {
            position: relative;
            border-bottom: 2px solid var(--bulsu-dark-maroon);
            width: 200px;
            margin: 0 auto;
        }
        
        .bulsu-seal {
            width: 100px;
            height: 100px;
            border: 4px solid var(--bulsu-maroon);
            border-radius: 50%;
            background: linear-gradient(135deg, var(--bulsu-light-gold), #ffffff);
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            font-size: 12px;
            color: var(--bulsu-dark-maroon);
            position: relative;
            margin: 0 auto;
            text-align: center;
            font-weight: bold;
            line-height: 1.1;
            box-shadow: 0 0 15px rgba(134, 45, 72, 0.3);
        }
        
        .bulsu-seal::before {
            content: '';
            position: absolute;
            width: 80px;
            height: 80px;
            border: 2px dashed var(--bulsu-gold);
            border-radius: 50%;
        }
        
        .bulsu-seal .university-text {
            font-size: 8px;
            font-weight: 600;
            letter-spacing: 0.5px;
        }
        
        .bulsu-seal .year-text {
            font-size: 10px;
            font-weight: 700;
            margin-top: 2px;
        }
        
        .bulsu-colors-primary {
            color: var(--bulsu-maroon);
        }
        
        .bulsu-colors-secondary {
            color: var(--bulsu-gold);
        }
        
        .bulsu-bg-primary {
            background-color: var(--bulsu-maroon);
        }
        
        .bulsu-bg-secondary {
            background-color: var(--bulsu-gold);
        }
        
        .bulsu-gradient {
            background: linear-gradient(135deg, var(--bulsu-maroon), var(--bulsu-gold));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }
        
        .decorative-flourish {
            width: 60px;
            height: 20px;
            background: linear-gradient(90deg, transparent, var(--bulsu-gold), transparent);
            margin: 0 auto;
            position: relative;
        }
        
        .decorative-flourish::before,
        .decorative-flourish::after {
            content: '❦';
            position: absolute;
            color: var(--bulsu-maroon);
            font-size: 14px;
            top: -7px;
        }
        
        .decorative-flourish::before {
            left: -20px;
        }
        
        .decorative-flourish::after {
            right: -20px;
        }
        
        @media print {
            * {
                -webkit-print-color-adjust: exact !important;
                color-adjust: exact !important;
            }
            
            .no-print {
                display: none !important;
            }
            
            body {
                margin: 0;
                padding: 0;
            }
            
            .certificate-container {
                width: 210mm;
                height: 297mm;
                margin: 0;
                padding: 15mm;
                box-sizing: border-box;
            }
        }
    </style>
</head>
<body style="background: linear-gradient(135deg, #f8f9fa 0%, var(--bulsu-light-gold) 100%);">
    <!-- Print Controls -->
    <div class="no-print fixed top-4 right-4 z-50 flex gap-3">
        <button onclick="downloadPDF()" style="background-color: var(--bulsu-maroon);" class="flex items-center gap-2 px-4 py-2 text-white rounded-lg hover:opacity-90 transition-opacity duration-200 shadow-lg">
            <i class="fas fa-download text-sm"></i>
            <span class="hidden sm:inline">Download PDF</span>
        </button>
        <button onclick="window.print()" style="background-color: var(--bulsu-gold); color: var(--bulsu-dark-maroon);" class="flex items-center gap-2 px-4 py-2 rounded-lg hover:opacity-90 transition-opacity duration-200 shadow-lg font-semibold">
            <i class="fas fa-print text-sm"></i>
            <span class="hidden sm:inline">Print</span>
        </button>
        <a href="CompanyProgressReport.php" class="flex items-center gap-2 px-4 py-2 bg-gray-600 text-white rounded-lg hover:bg-gray-700 transition-colors duration-200 shadow-lg">
            <i class="fas fa-arrow-left text-sm"></i>
            <span class="hidden sm:inline">Back</span>
        </a>
    </div>
    
    <!-- Certificate Container -->
    <div class="certificate-container max-w-4xl mx-auto p-6">
        <div id="certificate" class="certificate-bg min-h-screen relative">
            <!-- Geometric Accents -->
            <div class="geometric-accent top-left"></div>
            <div class="geometric-accent bottom-right"></div>
            
            <div class="certificate-border h-full">
                <div class="certificate-inner p-12 h-full flex flex-col justify-between">
                    
                    <!-- Header -->
                    <div class="text-center mb-8">
                        <!-- University Header -->
                        <div class="mb-8">
                            <h1 class="title-font text-2xl font-bold bulsu-colors-primary mb-1">
                                BULACAN STATE UNIVERSITY
                            </h1>
                            <p class="body-font text-sm bulsu-colors-secondary font-semibold mb-1">
                                City of Malolos, Bulacan
                            </p>
                            <p class="body-font text-xs text-gray-600 mb-4">
                                College of Information and Communications Technology
                            </p>
                            <div class="decorative-flourish mb-6"></div>
                        </div>
                        
                        <div class="mb-6">
                            <h2 class="title-font text-4xl lg:text-5xl font-bold bulsu-gradient mb-2">
                                Certificate
                            </h2>
                            <div class="w-32 h-1 mx-auto mb-4" style="background: linear-gradient(90deg, var(--bulsu-maroon), var(--bulsu-gold), var(--bulsu-maroon));"></div>
                            <h3 class="title-font text-2xl font-bold bulsu-colors-primary uppercase tracking-wider">
                                of Completion
                            </h3>
                            <p class="body-font text-sm text-gray-600 mt-2 italic">
                                On-the-Job Training Program
                            </p>
                        </div>
                        
                        <p class="body-font text-gray-600 text-lg mb-2">This is to certify that</p>
                        
                        <!-- Student Name -->
                        <div class="my-8 relative">
                            <div class="absolute inset-0 transform -skew-x-12" style="background: linear-gradient(90deg, rgba(134, 45, 72, 0.08), rgba(218, 165, 32, 0.1), rgba(134, 45, 72, 0.08));"></div>
                            <h4 class="title-font text-3xl lg:text-4xl font-bold bulsu-colors-primary py-6 relative z-10">
                                <?php echo htmlspecialchars($student_full_name); ?>
                            </h4>
                        </div>
                        
                        <p class="body-font text-gray-700 text-lg mb-6 leading-relaxed">
                            has successfully completed <span class="font-bold bulsu-colors-secondary"><?php echo number_format($student['completed_hours']); ?> hours</span> of<br>
                            On-the-Job Training under <span class="font-semibold bulsu-colors-primary"><?php echo htmlspecialchars($student['company_name']); ?></span><br>
                            as part of the academic requirements for graduation
                        </p>
                        
                        <p class="body-font text-gray-600">
                            Given this <span class="font-semibold bulsu-colors-secondary"><?php echo date('jS'); ?></span> day of <span class="font-semibold bulsu-colors-secondary"><?php echo date('F Y'); ?></span><br>
                            at <?php echo htmlspecialchars($student['company_name']); ?>, 
                            <?php echo htmlspecialchars($student['company_address']); ?>
                        </p>
                    </div>
                    
                    <!-- Training Details -->
                    <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-12 text-center">
                        <div class="p-6 rounded-lg border-2 border-opacity-20" style="background: linear-gradient(135deg, rgba(134, 45, 72, 0.05), rgba(134, 45, 72, 0.1)); border-color: var(--bulsu-maroon);">
                            <div class="bulsu-colors-primary text-2xl mb-2">
                                <i class="fas fa-calendar-alt"></i>
                            </div>
                            <h5 class="body-font font-semibold text-gray-700 mb-1">Training Period</h5>
                            <p class="body-font text-sm text-gray-600"><?php echo $start_date; ?><br>to <?php echo $end_date; ?></p>
                        </div>
                        
                        <div class="p-6 rounded-lg border-2 border-opacity-20" style="background: linear-gradient(135deg, rgba(218, 165, 32, 0.05), rgba(218, 165, 32, 0.1)); border-color: var(--bulsu-gold);">
                            <div class="bulsu-colors-secondary text-2xl mb-2">
                                <i class="fas fa-clock"></i>
                            </div>
                            <h5 class="body-font font-semibold text-gray-700 mb-1">Total Hours</h5>
                            <p class="body-font text-sm text-gray-600"><?php echo number_format($student['completed_hours']); ?> Hours<br>Completed</p>
                        </div>
                        
                        <div class="p-6 rounded-lg border-2 border-opacity-20" style="background: linear-gradient(135deg, rgba(107, 16, 40, 0.05), rgba(107, 16, 40, 0.1)); border-color: var(--bulsu-dark-maroon);">
                            <div style="color: var(--bulsu-dark-maroon);" class="text-2xl mb-2">
                                <i class="fas fa-briefcase"></i>
                            </div>
                            <h5 class="body-font font-semibold text-gray-700 mb-1">Position</h5>
                            <p class="body-font text-sm text-gray-600"><?php echo htmlspecialchars($student['position']); ?></p>
                        </div>
                    </div>
                    
                    <!-- Certificate Seal and Signatures -->
                    <div class="grid grid-cols-1 md:grid-cols-3 gap-8 items-end">
                        <!-- Technical Representative -->
                        <div class="text-center">
                            <div class="signature-line mb-3 mt-12"></div>
                            <p class="body-font font-semibold bulsu-colors-primary text-sm mb-1">
                                <?php echo htmlspecialchars($student['supervisor_full_name']); ?>
                            </p>
                            <p class="body-font text-xs bulsu-colors-secondary uppercase tracking-wide font-semibold">
                                Industry Supervisor
                            </p>
                            <p class="body-font text-xs text-gray-500">
                                <?php echo htmlspecialchars($student['supervisor_position'] ?? 'Company Supervisor'); ?>
                            </p>
                        </div>
                        
                        <!-- BulSU Seal -->
                        <div class="text-center">
                            <div class="bulsu-seal mb-4">
                                <div class="university-text">BULACAN STATE</div>
                                <div class="university-text">UNIVERSITY</div>
                                <div class="year-text"><?php echo date('Y'); ?></div>
                            </div>
                            <p class="body-font text-xs text-gray-500 font-medium"><?php echo $certificate_id; ?></p>
                        </div>
                        
                        <!-- Academic Coordinator -->
                        <div class="text-center">
                            <div class="signature-line mb-3 mt-12"></div>
                            <p class="body-font font-semibold bulsu-colors-primary text-sm mb-1">Academic Coordinator</p>
                            <p class="body-font text-xs bulsu-colors-secondary uppercase tracking-wide font-semibold">
                                OJT Program Coordinator
                            </p>
                            <p class="body-font text-xs text-gray-500">College of BSIT</p>
                        </div>
                    </div>
                    
                    <!-- Footer Information -->
                    <div class="mt-12 pt-6 border-t-2" style="border-color: var(--bulsu-light-gold);">
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4 text-xs text-gray-600 body-font">
                            <div>
                                <p><strong>Student ID:</strong> <?php echo htmlspecialchars($student['student_id']); ?></p>
                                <p><strong>Program:</strong> <?php echo htmlspecialchars($student['program'] . ' - ' . $student['year_level']); ?></p>
                                <?php if ($evaluation): ?>
                                <p><strong>Performance Rating:</strong> <?php echo number_format($evaluation['equivalent_rating'], 2); ?> (<?php echo htmlspecialchars($evaluation['verbal_interpretation']); ?>)</p>
                                <?php endif; ?>
                            </div>
                            <div class="md:text-right">
                                <p><strong>Certificate ID:</strong> <?php echo $certificate_id; ?></p>
                                <p><strong>Date Issued:</strong> <?php echo $completion_date; ?></p>
                                <p><strong>Department:</strong> <?php echo htmlspecialchars($student['department']); ?></p>
                            </div>
                        </div>
                        
                        <!-- University Footer -->
                        <div class="text-center mt-6 pt-4 border-t border-gray-300">
                            <p class="body-font text-xs text-gray-500">
                                <em>"Excellence, Service, and Social Responsibility"</em><br>
                                Bulacan State University - Committed to Quality Education
                            </p>
                        </div>
                    </div>
                    
                </div>
            </div>
        </div>
        
        <!-- Success Message Display Area -->
        <div id="messageArea" class="no-print mt-4"></div>
    </div>
    
    <script>
        function downloadPDF() {
            const element = document.getElementById('certificate');
            const opt = {
                margin: 0.2,
                filename: 'BulSU_OJT_Certificate_<?php echo str_replace(' ', '_', $student_full_name); ?>.pdf',
                image: { type: 'jpeg', quality: 0.98 },
                html2canvas: { 
                    scale: 2,
                    useCORS: true,
                    letterRendering: true,
                    allowTaint: false,
                    width: 794,
                    height: 1123
                },
                jsPDF: { 
                    unit: 'mm', 
                    format: 'a4', 
                    orientation: 'portrait' 
                }
            };
            
            // Show loading indicator
            const downloadBtn = document.querySelector('button[onclick="downloadPDF()"]');
            const originalHTML = downloadBtn.innerHTML;
            downloadBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> <span class="hidden sm:inline">Generating PDF...</span>';
            downloadBtn.disabled = true;
            
            html2pdf().set(opt).from(element).save().then(() => {
                // Reset button
                downloadBtn.innerHTML = originalHTML;
                downloadBtn.disabled = false;
                
                // Show success message
                showMessage('BulSU Certificate downloaded successfully!', 'success');
            }).catch((error) => {
                console.error('Error generating PDF:', error);
                downloadBtn.innerHTML = originalHTML;
                downloadBtn.disabled = false;
                showMessage('Error generating PDF. Please try again.', 'error');
            });
        }
        
        function showMessage(message, type) {
            const messageArea = document.getElementById('messageArea');
            const messageDiv = document.createElement('div');
            
            const bgColor = type === 'success' ? 'border-green-400 text-green-700' : 'border-red-400 text-red-700';
            const bgColorClass = type === 'success' ? 'bg-green-50' : 'bg-red-50';
            const icon = type === 'success' ? 'fa-check-circle' : 'fa-exclamation-circle';
            
            messageDiv.className = `border-l-4 p-4 rounded ${bgColor} ${bgColorClass}`;
            messageDiv.innerHTML = `
                <div class="flex">
                    <div class="flex-shrink-0">
                        <i class="fas ${icon}"></i>
                    </div>
                    <div class="ml-3">
                        <p class="text-sm font-medium">${message}</p>
                    </div>
                </div>
            `;
            
            messageArea.innerHTML = '';
            messageArea.appendChild(messageDiv);
            
            setTimeout(() => {
                messageDiv.remove();
            }, 5000);
        }
        
        // Add responsive font sizing
        function adjustFontSize() {
            const certificate = document.getElementById('certificate');
            if (window.innerWidth < 640) {
                certificate.classList.add('text-sm');
            } else {
                certificate.classList.remove('text-sm');
            }
        }
        
        window.addEventListener('resize', adjustFontSize);
        document.addEventListener('DOMContentLoaded', adjustFontSize);
        
        // Add subtle animation on load
        document.addEventListener('DOMContentLoaded', function() {
            const certificate = document.getElementById('certificate');
            certificate.style.opacity = '0';
            certificate.style.transform = 'translateY(20px)';
            
            setTimeout(() => {
                certificate.style.transition = 'opacity 0.8s ease-out, transform 0.8s ease-out';
                certificate.style.opacity = '1';
                certificate.style.transform = 'translateY(0)';
            }, 100);
        });
    </script>
</body>
</html>