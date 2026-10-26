<?php
// backend.php - Student & Staff API (Bank Transfer Only)

error_reporting(0);
ini_set('display_errors', 0);

define('DB_HOST', 'localhost');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_NAME', 'flux_academic');

define('ADMIN_EMAIL', 'admin@fluxacademic.lk');
define('FROM_EMAIL', 'noreply@fluxacademic.lk');
define('SITE_URL', 'http://localhost/flux');

ini_set('session.cookie_httponly', 1);
ini_set('session.use_only_cookies', 1);
@session_start();

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }

try {
    $pdo = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME, DB_USER, DB_PASS);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    sendResponse(false, 'Database connection failed');
    exit;
}

$action = $_POST['action'] ?? $_GET['action'] ?? '';

switch ($action) {
    case 'register': registerStudent($pdo); break;
    case 'login': loginStudent($pdo); break;
    case 'staff_login': staffLogin($pdo); break;
    case 'logout': logout(); break;
    case 'get_all_classes': getAllClasses($pdo); break;
    case 'drop': dropClass($pdo); break;
    case 'get_enrollments': getEnrollments($pdo); break;
    case 'get_payment_history': getPaymentHistory($pdo); break;
    case 'get_student_dashboard': getStudentDashboard($pdo); break;
    case 'get_student_materials': getStudentMaterials($pdo); break;
    case 'get_student_meetings': getStudentMeetings($pdo); break;
    case 'update_profile': updateProfile($pdo); break;
    case 'change_password': changePassword($pdo); break;
    case 'get_bank_accounts': getBankAccounts($pdo); break;
    case 'submit_bank_transfer': submitBankTransfer($pdo); break;
    case 'get_my_pending_payments': getMyPendingPayments($pdo); break;
    default: sendResponse(false, 'Invalid action: ' . $action);
}

// ============================================
// BANK TRANSFER
// ============================================

function getBankAccounts($pdo) {
    try {
        $stmt = $pdo->query("SELECT * FROM bank_accounts WHERE is_active = 1 ORDER BY id");
        sendResponse(true, 'Bank accounts retrieved', $stmt->fetchAll());
    } catch (PDOException $e) {
        sendResponse(false, 'Failed to load bank accounts');
    }
}

function submitBankTransfer($pdo) {
    $studentId = (int)($_POST['student_id'] ?? $_SESSION['student_id'] ?? 0);
    $classId = (int)($_POST['class_id'] ?? 0);
    $bankReference = trim($_POST['bank_reference'] ?? '');
    $transferDate = trim($_POST['transfer_date'] ?? '');
    $amount = (float)($_POST['amount'] ?? 0);

    if (!$studentId || !$classId) { sendResponse(false, 'Student ID and Class ID required'); return; }
    if (!$bankReference) { sendResponse(false, 'Bank reference number is required'); return; }

    try {
        $stmt = $pdo->prepare("SELECT * FROM students WHERE id = ? AND status = 'active'");
        $stmt->execute([$studentId]);
        $student = $stmt->fetch();
        if (!$student) { sendResponse(false, 'Student not found'); return; }

        $stmt = $pdo->prepare("SELECT * FROM classes WHERE id = ? AND status = 'active'");
        $stmt->execute([$classId]);
        $class = $stmt->fetch();
        if (!$class) { sendResponse(false, 'Class not found'); return; }

        $stmt = $pdo->prepare("SELECT id FROM enrollments WHERE student_id = ? AND class_id = ? AND status = 'active'");
        $stmt->execute([$studentId, $classId]);
        if ($stmt->fetch()) { sendResponse(false, 'Already enrolled in this class'); return; }

        $stmt = $pdo->prepare("SELECT id FROM payments WHERE student_id = ? AND class_id = ? AND payment_status = 'pending'");
        $stmt->execute([$studentId, $classId]);
        if ($stmt->fetch()) { sendResponse(false, 'You already have a pending slip for this class'); return; }

        if ($amount <= 0) $amount = (float)$class['price'];

        $slipPath = null;
        if (!empty($_FILES['slip']['name'])) {
            $uploadDir = __DIR__ . '/uploads/slips/';
            if (!is_dir($uploadDir)) @mkdir($uploadDir, 0755, true);

            $allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'application/pdf' => 'pdf'];
            $mime = mime_content_type($_FILES['slip']['tmp_name']);
            if (!isset($allowed[$mime])) { sendResponse(false, 'Slip must be JPG, PNG, WEBP, or PDF'); return; }
            if ($_FILES['slip']['size'] > 5 * 1024 * 1024) { sendResponse(false, 'Slip file must be under 5MB'); return; }

            $ext = $allowed[$mime];
            $filename = 'slip_' . $studentId . '_' . $classId . '_' . time() . '.' . $ext;
            $target = $uploadDir . $filename;

            if (!move_uploaded_file($_FILES['slip']['tmp_name'], $target)) {
                sendResponse(false, 'Failed to save slip file');
                return;
            }
            $slipPath = 'uploads/slips/' . $filename;
        } else {
            sendResponse(false, 'Please upload your payment slip');
            return;
        }

        $transactionId = 'BT-' . date('Ymd') . '-' . strtoupper(substr(uniqid(), -6));
        $receiptNumber = 'RCP-' . date('Ymd') . '-' . str_pad(rand(1, 9999), 4, '0', STR_PAD_LEFT);

        $stmt = $pdo->prepare("
            INSERT INTO payments 
            (student_id, class_id, amount, currency, payment_method, transaction_id, receipt_number, 
             payment_status, slip_file, bank_reference, transfer_date) 
            VALUES (?, ?, ?, 'LKR', 'bank_transfer', ?, ?, 'pending', ?, ?, ?)
        ");
        $stmt->execute([
            $studentId, $classId, $amount, $transactionId, $receiptNumber,
            $slipPath, $bankReference, $transferDate ?: null
        ]);

        $paymentId = $pdo->lastInsertId();
        sendAdminNewSlipEmail($student, $class, $amount, $bankReference, $receiptNumber);

        sendResponse(true, 'Bank transfer slip submitted! Waiting for admin approval.', [
            'payment_id' => $paymentId,
            'receipt_number' => $receiptNumber,
            'status' => 'pending'
        ]);

    } catch (PDOException $e) {
        sendResponse(false, 'Failed: ' . $e->getMessage());
    }
}

function getMyPendingPayments($pdo) {
    $studentId = (int)($_POST['student_id'] ?? $_SESSION['student_id'] ?? 0);
    if (!$studentId) { sendResponse(false, 'Student ID required'); return; }
    try {
        $stmt = $pdo->prepare("
            SELECT p.*, c.name as class_name, c.subject
            FROM payments p
            JOIN classes c ON p.class_id = c.id
            WHERE p.student_id = ? AND p.payment_status = 'pending'
            ORDER BY p.payment_date DESC
        ");
        $stmt->execute([$studentId]);
        sendResponse(true, 'Pending payments', $stmt->fetchAll());
    } catch (PDOException $e) { sendResponse(false, 'Failed'); }
}

// ============================================
// EMAIL HELPERS
// ============================================

function sendAdminNewSlipEmail($student, $class, $amount, $reference, $receipt) {
    $to = ADMIN_EMAIL;
    $subject = "New Bank Transfer Slip - {$student['first_name']} {$student['last_name']}";

    $body = "
    <html><body style='font-family: Arial, sans-serif; background:#f5f5f5; padding:20px;'>
    <div style='max-width:600px;margin:auto;background:#fff;border-radius:12px;padding:30px;'>
        <h2 style='color:#0d7c6e;margin-top:0;'>New Bank Transfer Slip Submitted</h2>
        <p>A student has submitted a bank transfer slip that requires your approval.</p>
        <table style='width:100%;border-collapse:collapse;margin:20px 0;'>
            <tr><td style='padding:8px 0;color:#666;'><strong>Student:</strong></td><td>{$student['first_name']} {$student['last_name']}</td></tr>
            <tr><td style='padding:8px 0;color:#666;'><strong>Email:</strong></td><td>{$student['email']}</td></tr>
            <tr><td style='padding:8px 0;color:#666;'><strong>Class:</strong></td><td>{$class['name']}</td></tr>
            <tr><td style='padding:8px 0;color:#666;'><strong>Amount:</strong></td><td>LKR " . number_format($amount, 2) . "</td></tr>
            <tr><td style='padding:8px 0;color:#666;'><strong>Reference:</strong></td><td>{$reference}</td></tr>
            <tr><td style='padding:8px 0;color:#666;'><strong>Receipt #:</strong></td><td>{$receipt}</td></tr>
        </table>
        <a href='" . SITE_URL . "/admin-dashboard.html' style='display:inline-block;padding:12px 24px;background:#0d7c6e;color:#fff;text-decoration:none;border-radius:8px;font-weight:bold;'>Review in Admin Dashboard</a>
    </div>
    </body></html>
    ";
    $headers = "MIME-Version: 1.0\r\nContent-type: text/html; charset=UTF-8\r\nFrom: " . FROM_EMAIL . "\r\n";
    @mail($to, $subject, $body, $headers);
}

// ============================================
// AUTH
// ============================================

function registerStudent($pdo) {
    $fname = trim($_POST['first_name'] ?? '');
    $lname = trim($_POST['last_name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    $program = trim($_POST['program'] ?? 'A/L 2026 Batch');

    if (empty($fname) || empty($lname) || empty($email) || empty($phone) || empty($username) || empty($password)) {
        sendResponse(false, 'All fields are required'); return;
    }
    if (strlen($password) < 6) { sendResponse(false, 'Password min 6 characters'); return; }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) { sendResponse(false, 'Invalid email'); return; }

    try {
        $stmt = $pdo->prepare("SELECT id FROM students WHERE username = ? OR email = ?");
        $stmt->execute([$username, $email]);
        if ($stmt->fetch()) { sendResponse(false, 'Username or email already exists'); return; }

        $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
        $stmt = $pdo->prepare("INSERT INTO students (first_name, last_name, email, phone, username, password, program) VALUES (?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([$fname, $lname, $email, $phone, $username, $hashedPassword, $program]);
        sendResponse(true, 'Registration successful! Please login.');
    } catch (PDOException $e) { sendResponse(false, 'Registration failed'); }
}

function loginStudent($pdo) {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    if (empty($username) || empty($password)) { sendResponse(false, 'Username and password required'); return; }

    try {
        $stmt = $pdo->prepare("SELECT * FROM students WHERE (username = ? OR email = ?) AND status = 'active'");
        $stmt->execute([$username, $username]);
        $student = $stmt->fetch();
        if ($student && password_verify($password, $student['password'])) {
            $_SESSION['student_id'] = $student['id'];
            sendResponse(true, 'Login successful', [
                'id' => (int)$student['id'],
                'first_name' => $student['first_name'],
                'last_name' => $student['last_name'],
                'email' => $student['email'],
                'phone' => $student['phone'],
                'username' => $student['username'],
                'program' => $student['program'],
                'role' => 'student'
            ]);
        } else {
            sendResponse(false, 'Invalid username or password');
        }
    } catch (PDOException $e) { sendResponse(false, 'Login failed'); }
}

function staffLogin($pdo) {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    $requestedRole = trim($_POST['role'] ?? '');
    if (empty($username) || empty($password)) { sendResponse(false, 'Username and password required'); return; }

    try {
        $stmt = $pdo->prepare("SELECT * FROM admin_users WHERE (username = ? OR email = ?) AND status = 'active'");
        $stmt->execute([$username, $username]);
        $admin = $stmt->fetch();
        if ($admin && password_verify($password, $admin['password'])) {
            if ($requestedRole === 'teacher') { sendResponse(false, 'This account is not a teacher account'); return; }
            sendResponse(true, 'Login successful', [
                'id' => (int)$admin['id'],
                'first_name' => $admin['first_name'],
                'last_name' => $admin['last_name'],
                'email' => $admin['email'],
                'username' => $admin['username'],
                'role' => 'admin'
            ]);
            return;
        }
    } catch (PDOException $e) {}

    try {
        $stmt = $pdo->prepare("SELECT * FROM teachers WHERE (username = ? OR email = ?) AND status = 'active'");
        $stmt->execute([$username, $username]);
        $teacher = $stmt->fetch();
        if ($teacher && password_verify($password, $teacher['password'])) {
            if ($requestedRole === 'admin') { sendResponse(false, 'This account is not an admin account'); return; }
            sendResponse(true, 'Login successful', [
                'id' => (int)$teacher['id'],
                'first_name' => $teacher['first_name'],
                'last_name' => $teacher['last_name'],
                'email' => $teacher['email'],
                'username' => $teacher['username'],
                'subject' => $teacher['subject'],
                'role' => 'teacher'
            ]);
            return;
        }
    } catch (PDOException $e) {}

        // ---- Reception Staff ----
    try {
        $stmt = $pdo->prepare("SELECT * FROM reception_staff WHERE (username = ? OR email = ?) AND status = 'active'");
        $stmt->execute([$username, $username]);
        $reception = $stmt->fetch();
        if ($reception && password_verify($password, $reception['password'])) {
            if ($requestedRole === 'admin' || $requestedRole === 'teacher') {
                sendResponse(false, 'This account is not a ' . $requestedRole . ' account');
                return;
            }
            sendResponse(true, 'Login successful', [
                'id' => (int)$reception['id'],
                'first_name' => $reception['first_name'],
                'last_name' => $reception['last_name'],
                'email' => $reception['email'],
                'username' => $reception['username'],
                'role' => 'reception'
            ]);
            return;
        }
    } catch (PDOException $e) {}

    
    sendResponse(false, 'Invalid username or password');
}

function logout() { @session_destroy(); sendResponse(true, 'Logged out'); }

// ============================================
// CLASSES
// ============================================

function getAllClasses($pdo) {
    try {
        $stmt = $pdo->query("
            SELECT c.*, CONCAT(t.first_name, ' ', t.last_name) as teacher_name
            FROM classes c LEFT JOIN teachers t ON c.teacher_id = t.id
            WHERE c.status = 'active' ORDER BY c.year DESC, c.subject
        ");
        sendResponse(true, 'Classes retrieved', $stmt->fetchAll());
    } catch (PDOException $e) { sendResponse(false, 'Failed'); }
}

function dropClass($pdo) {
    $studentId = (int)($_POST['student_id'] ?? $_SESSION['student_id'] ?? 0);
    $classId = (int)($_POST['class_id'] ?? 0);
    if (!$studentId || !$classId) { sendResponse(false, 'Required fields missing'); return; }

    try {
        $pdo->beginTransaction();
        $stmt = $pdo->prepare("UPDATE enrollments SET status = 'dropped' WHERE student_id = ? AND class_id = ? AND status = 'active'");
        $stmt->execute([$studentId, $classId]);
        if ($stmt->rowCount() > 0) {
            $stmt = $pdo->prepare("UPDATE classes SET enrolled = GREATEST(enrolled - 1, 0) WHERE id = ?");
            $stmt->execute([$classId]);
        }
        $pdo->commit();
        sendResponse(true, 'Class dropped successfully');
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        sendResponse(false, 'Failed');
    }
}

function getEnrollments($pdo) {
    $studentId = (int)($_POST['student_id'] ?? $_SESSION['student_id'] ?? 0);
    if (!$studentId) { sendResponse(false, 'Student ID required'); return; }
    try {
        $stmt = $pdo->prepare("
            SELECT e.id as enrollment_id, e.class_id, e.status as enrollment_status, e.enrollment_date,
                   c.name as class_name, c.subject, c.year, c.schedule, c.teacher, c.price, c.zoom_link,
                   CONCAT(t.first_name, ' ', t.last_name) as teacher_name
            FROM enrollments e
            JOIN classes c ON e.class_id = c.id
            LEFT JOIN teachers t ON c.teacher_id = t.id
            WHERE e.student_id = ? AND e.status = 'active'
            ORDER BY e.enrollment_date DESC
        ");
        $stmt->execute([$studentId]);
        sendResponse(true, 'Enrollments retrieved', $stmt->fetchAll());
    } catch (PDOException $e) { sendResponse(false, 'Failed'); }
}

function getPaymentHistory($pdo) {
    $studentId = (int)($_POST['student_id'] ?? $_SESSION['student_id'] ?? 0);
    if (!$studentId) { sendResponse(false, 'Student ID required'); return; }
    try {
        $stmt = $pdo->prepare("
            SELECT p.*, c.name as class_name FROM payments p
            JOIN classes c ON p.class_id = c.id
            WHERE p.student_id = ?
            ORDER BY p.payment_date DESC
        ");
        $stmt->execute([$studentId]);
        sendResponse(true, 'Payment history', $stmt->fetchAll());
    } catch (PDOException $e) { sendResponse(false, 'Failed'); }
}

// ============================================
// DASHBOARD
// ============================================

function getStudentDashboard($pdo) {
    $studentId = (int)($_POST['student_id'] ?? $_SESSION['student_id'] ?? 0);
    if (!$studentId) { 
        sendResponse(false, 'Student ID required'); 
        return; 
    }
    
    try {
        // --- 1. Student info ---
        $stmt = $pdo->prepare("SELECT * FROM students WHERE id = ?");
        $stmt->execute([$studentId]);
        $student = $stmt->fetch();
        
        if (!$student) {
            sendResponse(false, 'Student not found');
            return;
        }

        // --- 2. Enrolled classes ---
        $classes = [];
        try {
            $stmt = $pdo->prepare("
                SELECT c.*, e.enrollment_date,
                       CONCAT(t.first_name, ' ', t.last_name) as teacher_name
                FROM enrollments e
                JOIN classes c ON e.class_id = c.id
                LEFT JOIN teachers t ON c.teacher_id = t.id
                WHERE e.student_id = ? AND e.status = 'active'
                ORDER BY e.enrollment_date DESC
            ");
            $stmt->execute([$studentId]);
            $classes = $stmt->fetchAll();
        } catch (PDOException $e) {
            error_log('Dashboard classes query failed: ' . $e->getMessage());
        }

        // --- 3. Payments ---
        $payments = [];
        try {
            $stmt = $pdo->prepare("
                SELECT p.*, c.name as class_name FROM payments p
                JOIN classes c ON p.class_id = c.id
                WHERE p.student_id = ?
                ORDER BY p.payment_date DESC LIMIT 10
            ");
            $stmt->execute([$studentId]);
            $payments = $stmt->fetchAll();
        } catch (PDOException $e) {
            error_log('Dashboard payments query failed: ' . $e->getMessage());
        }

        // --- 4. Upcoming meetings ---
        $meetings = [];
        try {
            $stmt = $pdo->prepare("
                SELECT m.*, c.name as class_name FROM zoom_meetings m
                JOIN classes c ON m.class_id = c.id
                JOIN enrollments e ON e.class_id = c.id
                WHERE e.student_id = ? AND e.status = 'active' 
                AND m.start_time > NOW() AND m.status = 'scheduled'
                ORDER BY m.start_time ASC LIMIT 5
            ");
            $stmt->execute([$studentId]);
            $meetings = $stmt->fetchAll();
        } catch (PDOException $e) {
            error_log('Dashboard meetings query failed: ' . $e->getMessage());
        }

        // --- 5. Return (attendance ඉවත් කර ඇත) ---
        sendResponse(true, 'Dashboard data', [
            'student' => $student,
            'classes' => $classes,
            'payments' => $payments,
            'upcoming_meetings' => $meetings
        ]);

    } catch (PDOException $e) {
        sendResponse(false, 'Failed: ' . $e->getMessage());
    }
}

function getStudentMaterials($pdo) {
    $studentId = (int)($_POST['student_id'] ?? $_SESSION['student_id'] ?? 0);
    if (!$studentId) { sendResponse(false, 'Student ID required'); return; }
    try {
        $stmt = $pdo->prepare("
            SELECT m.*, c.name as class_name FROM class_materials m
            JOIN classes c ON m.class_id = c.id
            JOIN enrollments e ON e.class_id = c.id
            WHERE e.student_id = ? AND e.status = 'active'
            ORDER BY m.created_at DESC
        ");
        $stmt->execute([$studentId]);
        sendResponse(true, 'Materials retrieved', $stmt->fetchAll());
    } catch (PDOException $e) { sendResponse(false, 'Failed'); }
}

function getStudentMeetings($pdo) {
    $studentId = (int)($_POST['student_id'] ?? $_SESSION['student_id'] ?? 0);
    if (!$studentId) { sendResponse(false, 'Student ID required'); return; }
    try {
        $stmt = $pdo->prepare("
            SELECT m.*, c.name as class_name FROM zoom_meetings m
            JOIN classes c ON m.class_id = c.id
            JOIN enrollments e ON e.class_id = c.id
            WHERE e.student_id = ? AND e.status = 'active'
            ORDER BY m.start_time ASC
        ");
        $stmt->execute([$studentId]);
        sendResponse(true, 'Meetings retrieved', $stmt->fetchAll());
    } catch (PDOException $e) { sendResponse(false, 'Failed'); }
}

// ============================================
// PROFILE
// ============================================

function updateProfile($pdo) {
    $studentId = (int)($_POST['student_id'] ?? $_SESSION['student_id'] ?? 0);
    if (!$studentId) { sendResponse(false, 'Student ID required'); return; }

    $fname = trim($_POST['first_name'] ?? '');
    $lname = trim($_POST['last_name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $dob = !empty($_POST['date_of_birth']) ? $_POST['date_of_birth'] : null;
    $gender = !empty($_POST['gender']) ? $_POST['gender'] : null;
    $address = trim($_POST['address'] ?? '');
    $city = trim($_POST['city'] ?? '');
    $postalCode = trim($_POST['postal_code'] ?? '');

    if (empty($fname) || empty($lname) || empty($email)) {
        sendResponse(false, 'Name and email are required'); return;
    }

    try {
        $stmt = $pdo->prepare("SELECT id FROM students WHERE email = ? AND id != ?");
        $stmt->execute([$email, $studentId]);
        if ($stmt->fetch()) { sendResponse(false, 'Email already in use'); return; }

        $stmt = $pdo->prepare("
            UPDATE students SET first_name = ?, last_name = ?, email = ?, phone = ?, 
                date_of_birth = ?, gender = ?, address = ?, city = ?, postal_code = ?
            WHERE id = ?
        ");
        $stmt->execute([$fname, $lname, $email, $phone, $dob, $gender, $address, $city, $postalCode, $studentId]);
        sendResponse(true, 'Profile updated successfully');
    } catch (PDOException $e) { sendResponse(false, 'Failed: ' . $e->getMessage()); }
}

function changePassword($pdo) {
    $studentId = (int)($_POST['student_id'] ?? $_SESSION['student_id'] ?? 0);
    $currentPassword = $_POST['current_password'] ?? '';
    $newPassword = $_POST['new_password'] ?? '';
    if (!$studentId || empty($currentPassword) || empty($newPassword)) {
        sendResponse(false, 'All fields required'); return;
    }
    if (strlen($newPassword) < 6) { sendResponse(false, 'Password min 6 characters'); return; }

    try {
        $stmt = $pdo->prepare("SELECT password FROM students WHERE id = ?");
        $stmt->execute([$studentId]);
        $student = $stmt->fetch();
        if (!password_verify($currentPassword, $student['password'])) {
            sendResponse(false, 'Current password incorrect'); return;
        }
        $hashed = password_hash($newPassword, PASSWORD_DEFAULT);
        $stmt = $pdo->prepare("UPDATE students SET password = ? WHERE id = ?");
        $stmt->execute([$hashed, $studentId]);
        sendResponse(true, 'Password changed');
    } catch (PDOException $e) { sendResponse(false, 'Failed'); }
}

function sendResponse($success, $message, $data = null) {
    if (ob_get_length()) ob_clean();
    $response = ['success' => $success, 'message' => $message];
    if ($data !== null) $response['data'] = $data;
    echo json_encode($response);
    exit;
}
?>