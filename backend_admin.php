<?php
// backend_admin.php - Admin API

error_reporting(0);
ini_set('display_errors', 0);

define('DB_HOST', 'localhost');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_NAME', 'flux_academic');

define('FROM_EMAIL', 'noreply@fluxacademic.lk');
define('SITE_URL', 'http://localhost/flux');

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }

try {
    $pdo = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME, DB_USER, DB_PASS);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
} catch (PDOException $e) { sendResponse(false, 'DB failed'); }

$action = $_POST['action'] ?? '';

switch ($action) {
    case 'admin_overview': adminOverview($pdo); break;
    case 'get_all_teachers': getAllTeachers($pdo); break;
    case 'add_teacher': addTeacher($pdo); break;
    case 'delete_teacher': deleteTeacher($pdo); break;
    case 'reset_teacher_password': resetTeacherPassword($pdo); break;
    case 'add_teacher_with_class': addTeacherWithClass($pdo); break;
    case 'get_all_students': getAllStudents($pdo); break;
    case 'delete_student': deleteStudent($pdo); break;
    case 'reset_student_password': resetStudentPassword($pdo); break;
    case 'get_all_classes_admin': getAllClassesAdmin($pdo); break;
    case 'add_class': addClass($pdo); break;
    case 'delete_class': deleteClass($pdo); break;
    case 'get_all_payments': getAllPayments($pdo); break;
    case 'get_pending_slips': getPendingSlips($pdo); break;
    case 'approve_slip': approveSlip($pdo); break;
    case 'reject_slip': rejectSlip($pdo); break;
    case 'get_pending_slips_count': getPendingSlipsCount($pdo); break;
    case 'get_bank_accounts_admin': getBankAccountsAdmin($pdo); break;
    case 'save_bank_account': saveBankAccount($pdo); break;
    case 'delete_bank_account': deleteBankAccount($pdo); break;
    default: sendResponse(false, 'Invalid action');
}

// ============================================
// OVERVIEW
// ============================================

function adminOverview($pdo) {
    try {
        $stats = [];
        $stmt = $pdo->query("SELECT COUNT(*) as total FROM students WHERE status = 'active'");
        $stats['total_students'] = $stmt->fetch()['total'] ?? 0;
        $stmt = $pdo->query("SELECT COUNT(*) as total FROM teachers WHERE status = 'active'");
        $stats['total_teachers'] = $stmt->fetch()['total'] ?? 0;
        $stmt = $pdo->query("SELECT COUNT(*) as total FROM classes WHERE status = 'active'");
        $stats['total_classes'] = $stmt->fetch()['total'] ?? 0;
        $stmt = $pdo->query("SELECT COALESCE(SUM(amount), 0) as total FROM payments WHERE payment_status = 'completed'");
        $stats['total_revenue'] = $stmt->fetch()['total'] ?? 0;
        $stmt = $pdo->query("SELECT COUNT(*) as total FROM enrollments WHERE status = 'active'");
        $stats['total_enrollments'] = $stmt->fetch()['total'] ?? 0;
        $stmt = $pdo->query("SELECT COUNT(*) as total FROM payments WHERE payment_status = 'pending'");
        $stats['pending_slips'] = $stmt->fetch()['total'] ?? 0;

        $stmt = $pdo->query("
            SELECT p.*, CONCAT(s.first_name, ' ', s.last_name) as student_name, c.name as class_name
            FROM payments p
            LEFT JOIN students s ON p.student_id = s.id
            LEFT JOIN classes c ON p.class_id = c.id
            WHERE p.payment_status = 'completed'
            ORDER BY p.payment_date DESC LIMIT 10
        ");
        $stats['recent_payments'] = $stmt->fetchAll();
        sendResponse(true, 'Admin overview', $stats);
    } catch (PDOException $e) { sendResponse(false, 'Failed'); }
}

function getPendingSlipsCount($pdo) {
    try {
        $stmt = $pdo->query("SELECT COUNT(*) as total FROM payments WHERE payment_status = 'pending'");
        sendResponse(true, 'Count', ['count' => (int)$stmt->fetch()['total']]);
    } catch (PDOException $e) { sendResponse(false, 'Failed'); }
}

// ============================================
// SLIP APPROVAL
// ============================================

function getPendingSlips($pdo) {
    try {
        $stmt = $pdo->query("
            SELECT p.*, 
                   CONCAT(s.first_name, ' ', s.last_name) as student_name,
                   s.email as student_email, s.phone as student_phone,
                   c.name as class_name, c.subject as class_subject,
                   c.price as class_price
            FROM payments p
            JOIN students s ON p.student_id = s.id
            JOIN classes c ON p.class_id = c.id
            WHERE p.payment_status = 'pending'
            ORDER BY p.payment_date ASC
        ");
        sendResponse(true, 'Pending slips retrieved', $stmt->fetchAll());
    } catch (PDOException $e) { sendResponse(false, 'Failed'); }
}

function approveSlip($pdo) {
    $paymentId = (int)($_POST['payment_id'] ?? 0);
    if (!$paymentId) { sendResponse(false, 'Payment ID required'); return; }
    try {
        $pdo->beginTransaction();
        $stmt = $pdo->prepare("
            SELECT p.*, s.first_name, s.last_name, s.email, c.name as class_name
            FROM payments p
            JOIN students s ON p.student_id = s.id
            JOIN classes c ON p.class_id = c.id
            WHERE p.id = ? AND p.payment_status = 'pending'
        ");
        $stmt->execute([$paymentId]);
        $payment = $stmt->fetch();
        if (!$payment) { $pdo->rollBack(); sendResponse(false, 'Payment not found or already processed'); return; }

        $stmt = $pdo->prepare("UPDATE payments SET payment_status = 'completed', reviewed_at = NOW() WHERE id = ?");
        $stmt->execute([$paymentId]);

        $stmt = $pdo->prepare("INSERT INTO enrollments (student_id, class_id, status) VALUES (?, ?, 'active') ON DUPLICATE KEY UPDATE status = 'active'");
        $stmt->execute([$payment['student_id'], $payment['class_id']]);

        $stmt = $pdo->prepare("UPDATE classes SET enrolled = enrolled + 1 WHERE id = ?");
        $stmt->execute([$payment['class_id']]);
        $pdo->commit();

        sendStudentApprovalEmail($payment);
        sendResponse(true, 'Payment approved! Student enrolled and notified by email.');
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        sendResponse(false, 'Failed: ' . $e->getMessage());
    }
}

function rejectSlip($pdo) {
    $paymentId = (int)($_POST['payment_id'] ?? 0);
    $reason = trim($_POST['reason'] ?? 'Payment slip could not be verified');
    if (!$paymentId) { sendResponse(false, 'Payment ID required'); return; }
    try {
        $stmt = $pdo->prepare("
            SELECT p.*, s.first_name, s.last_name, s.email, c.name as class_name
            FROM payments p
            JOIN students s ON p.student_id = s.id
            JOIN classes c ON p.class_id = c.id
            WHERE p.id = ? AND p.payment_status = 'pending'
        ");
        $stmt->execute([$paymentId]);
        $payment = $stmt->fetch();
        if (!$payment) { sendResponse(false, 'Payment not found or already processed'); return; }

        $stmt = $pdo->prepare("UPDATE payments SET payment_status = 'rejected', rejection_reason = ?, reviewed_at = NOW() WHERE id = ?");
        $stmt->execute([$reason, $paymentId]);

        sendStudentRejectionEmail($payment, $reason);
        sendResponse(true, 'Payment rejected. Student notified by email.');
    } catch (PDOException $e) { sendResponse(false, 'Failed: ' . $e->getMessage()); }
}

function sendStudentApprovalEmail($payment) {
    $to = $payment['email'];
    $subject = "Payment Approved - {$payment['class_name']}";
    $body = "
    <html><body style='font-family:Arial,sans-serif;background:#f5f5f5;padding:20px;'>
    <div style='max-width:600px;margin:auto;background:#fff;border-radius:12px;padding:30px;'>
        <h2 style='color:#2ecc71;margin-top:0;'>✅ Payment Approved!</h2>
        <p>Dear {$payment['first_name']} {$payment['last_name']},</p>
        <p>Your bank transfer payment has been <strong style='color:#2ecc71;'>approved</strong>. You are now enrolled in:</p>
        <div style='background:#f8f9fa;border-left:4px solid #2ecc71;padding:15px;margin:20px 0;'>
            <p style='margin:5px 0;'><strong>Class:</strong> {$payment['class_name']}</p>
            <p style='margin:5px 0;'><strong>Amount Paid:</strong> LKR " . number_format($payment['amount'], 2) . "</p>
            <p style='margin:5px 0;'><strong>Receipt #:</strong> {$payment['receipt_number']}</p>
            <p style='margin:5px 0;'><strong>Reference:</strong> {$payment['bank_reference']}</p>
        </div>
        <a href='" . SITE_URL . "/INDEX.html' style='display:inline-block;padding:12px 24px;background:#0d7c6e;color:#fff;text-decoration:none;border-radius:8px;font-weight:bold;'>Go to Dashboard</a>
    </div>
    </body></html>
    ";
    $headers = "MIME-Version: 1.0\r\nContent-type: text/html; charset=UTF-8\r\nFrom: " . FROM_EMAIL . "\r\n";
    @mail($to, $subject, $body, $headers);
}

function sendStudentRejectionEmail($payment, $reason) {
    $to = $payment['email'];
    $subject = "Payment Slip Rejected - {$payment['class_name']}";
    $body = "
    <html><body style='font-family:Arial,sans-serif;background:#f5f5f5;padding:20px;'>
    <div style='max-width:600px;margin:auto;background:#fff;border-radius:12px;padding:30px;'>
        <h2 style='color:#e74c3c;margin-top:0;'>❌ Payment Slip Rejected</h2>
        <p>Dear {$payment['first_name']} {$payment['last_name']},</p>
        <p>Unfortunately your slip for <strong>{$payment['class_name']}</strong> could not be approved.</p>
        <div style='background:#fff3cd;border-left:4px solid #e74c3c;padding:15px;margin:20px 0;'>
            <p style='margin:0;'><strong>Reason:</strong> {$reason}</p>
        </div>
        <p>Please re-submit with a valid slip, or contact us.</p>
    </div>
    </body></html>
    ";
    $headers = "MIME-Version: 1.0\r\nContent-type: text/html; charset=UTF-8\r\nFrom: " . FROM_EMAIL . "\r\n";
    @mail($to, $subject, $body, $headers);
}

// ============================================
// PAYMENTS
// ============================================

function getAllPayments($pdo) {
    $filter = $_POST['filter'] ?? 'all';
    $search = trim($_POST['search'] ?? '');
    try {
        $query = "
            SELECT p.*, CONCAT(s.first_name, ' ', s.last_name) as student_name,
                   s.email as student_email, c.name as class_name
            FROM payments p
            LEFT JOIN students s ON p.student_id = s.id
            LEFT JOIN classes c ON p.class_id = c.id WHERE 1=1
        ";
        $params = [];
        if ($filter !== 'all') { $query .= " AND p.payment_status = ?"; $params[] = $filter; }
        if ($search) {
            $query .= " AND (s.first_name LIKE ? OR s.last_name LIKE ? OR s.email LIKE ? OR p.transaction_id LIKE ?)";
            array_push($params, '%'.$search.'%', '%'.$search.'%', '%'.$search.'%', '%'.$search.'%');
        }
        $query .= " ORDER BY p.payment_date DESC";
        $stmt = $pdo->prepare($query);
        $stmt->execute($params);
        sendResponse(true, 'Payments retrieved', $stmt->fetchAll());
    } catch (PDOException $e) { sendResponse(false, 'Failed'); }
}

// ============================================
// TEACHERS
// ============================================

function getAllTeachers($pdo) {
    try {
        $stmt = $pdo->query("
            SELECT t.*, (SELECT COUNT(*) FROM classes WHERE teacher_id = t.id AND status = 'active') as class_count
            FROM teachers t WHERE t.status = 'active' ORDER BY t.first_name
        ");
        sendResponse(true, 'Teachers retrieved', $stmt->fetchAll());
    } catch (PDOException $e) { sendResponse(false, 'Failed'); }
}

function addTeacher($pdo) {
    $fname = trim($_POST['first_name'] ?? '');
    $lname = trim($_POST['last_name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    $subject = trim($_POST['subject'] ?? '');

    if (empty($fname) || empty($lname) || empty($email) || empty($username) || empty($password)) {
        sendResponse(false, 'All required fields must be filled'); return;
    }
    if (strlen($password) < 6) { sendResponse(false, 'Password min 6 chars'); return; }

    try {
        $stmt = $pdo->prepare("SELECT id FROM teachers WHERE username = ? OR email = ?");
        $stmt->execute([$username, $email]);
        if ($stmt->fetch()) { sendResponse(false, 'Username or email already exists'); return; }

        $hashed = password_hash($password, PASSWORD_DEFAULT);
        $stmt = $pdo->prepare("INSERT INTO teachers (first_name, last_name, email, phone, username, password, subject) VALUES (?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([$fname, $lname, $email, $phone, $username, $hashed, $subject]);
        sendResponse(true, 'Teacher added successfully', ['id' => $pdo->lastInsertId()]);
    } catch (PDOException $e) { sendResponse(false, 'Failed: ' . $e->getMessage()); }
}

function deleteTeacher($pdo) {
    $id = (int)($_POST['id'] ?? 0);
    if (!$id) { sendResponse(false, 'ID required'); return; }
    try {
        $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM classes WHERE teacher_id = ? AND status = 'active'");
        $stmt->execute([$id]);
        if ($stmt->fetch()['count'] > 0) { sendResponse(false, 'Cannot delete teacher with active classes'); return; }
        $stmt = $pdo->prepare("UPDATE teachers SET status = 'inactive' WHERE id = ?");
        $stmt->execute([$id]);
        sendResponse(true, 'Teacher deleted');
    } catch (PDOException $e) { sendResponse(false, 'Failed'); }
}

function resetTeacherPassword($pdo) {
    $id = (int)($_POST['id'] ?? 0);
    $password = $_POST['password'] ?? '';
    if (!$id || strlen($password) < 6) { sendResponse(false, 'Password min 6 chars'); return; }
    try {
        $hashed = password_hash($password, PASSWORD_DEFAULT);
        $stmt = $pdo->prepare("UPDATE teachers SET password = ? WHERE id = ?");
        $stmt->execute([$hashed, $id]);
        sendResponse(true, 'Password reset successfully');
    } catch (PDOException $e) { sendResponse(false, 'Failed'); }
}

function addTeacherWithClass($pdo) {
    $fname = trim($_POST['first_name'] ?? '');
    $lname = trim($_POST['last_name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    $subject = trim($_POST['subject'] ?? '');
    $className = trim($_POST['class_name'] ?? '');
    $classYear = trim($_POST['class_year'] ?? '');
    $classSchedule = trim($_POST['class_schedule'] ?? '');
    $classPrice = (float)($_POST['class_price'] ?? 25000);
    $classCapacity = (int)($_POST['class_capacity'] ?? 50);

    if (empty($fname) || empty($lname) || empty($email) || empty($username) || empty($password)) {
        sendResponse(false, 'Teacher details required'); return;
    }
    if (empty($className) || empty($classYear)) { sendResponse(false, 'Class details required'); return; }

    try {
        $pdo->beginTransaction();
        $stmt = $pdo->prepare("SELECT id FROM teachers WHERE username = ? OR email = ?");
        $stmt->execute([$username, $email]);
        if ($stmt->fetch()) { $pdo->rollBack(); sendResponse(false, 'Username/email exists'); return; }

        $hashed = password_hash($password, PASSWORD_DEFAULT);
        $stmt = $pdo->prepare("INSERT INTO teachers (first_name, last_name, email, phone, username, password, subject) VALUES (?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([$fname, $lname, $email, $phone, $username, $hashed, $subject]);
        $teacherId = $pdo->lastInsertId();

        $teacherName = $fname . ' ' . $lname;
        $stmt = $pdo->prepare("INSERT INTO classes (name, subject, year, schedule, teacher_id, teacher, price, capacity) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([$className, $subject, $classYear, $classSchedule, $teacherId, $teacherName, $classPrice, $classCapacity]);
        $classId = $pdo->lastInsertId();
        $pdo->commit();
        sendResponse(true, 'Teacher and class added', ['teacher_id' => $teacherId, 'class_id' => $classId]);
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        sendResponse(false, 'Failed: ' . $e->getMessage());
    }
}

// ============================================
// STUDENTS
// ============================================

function getAllStudents($pdo) {
    $search = trim($_POST['search'] ?? '');
    try {
        $query = "
            SELECT s.*, 
                   (SELECT COUNT(*) FROM enrollments WHERE student_id = s.id AND status = 'active') as enrolled_count,
                   (SELECT COALESCE(SUM(amount), 0) FROM payments WHERE student_id = s.id AND payment_status = 'completed') as total_paid
            FROM students s
        ";
        $params = [];
        if ($search) {
            $query .= " WHERE s.first_name LIKE ? OR s.last_name LIKE ? OR s.email LIKE ? OR s.username LIKE ?";
            $params = array_fill(0, 4, '%' . $search . '%');
        }
        $query .= " ORDER BY s.first_name";
        $stmt = $pdo->prepare($query);
        $stmt->execute($params);
        sendResponse(true, 'Students retrieved', $stmt->fetchAll());
    } catch (PDOException $e) { sendResponse(false, 'Failed'); }
}

function deleteStudent($pdo) {
    $id = (int)($_POST['id'] ?? 0);
    if (!$id) { sendResponse(false, 'ID required'); return; }
    try {
        $stmt = $pdo->prepare("UPDATE students SET status = 'inactive' WHERE id = ?");
        $stmt->execute([$id]);
        sendResponse(true, 'Student deleted');
    } catch (PDOException $e) { sendResponse(false, 'Failed'); }
}

function resetStudentPassword($pdo) {
    $id = (int)($_POST['id'] ?? 0);
    $password = $_POST['password'] ?? '';
    if (!$id || strlen($password) < 6) { sendResponse(false, 'Password min 6 chars'); return; }
    try {
        $hashed = password_hash($password, PASSWORD_DEFAULT);
        $stmt = $pdo->prepare("UPDATE students SET password = ? WHERE id = ?");
        $stmt->execute([$hashed, $id]);
        sendResponse(true, 'Password reset');
    } catch (PDOException $e) { sendResponse(false, 'Failed'); }
}

// ============================================
// CLASSES
// ============================================

function getAllClassesAdmin($pdo) {
    try {
        $stmt = $pdo->query("
            SELECT c.*, CONCAT(t.first_name, ' ', t.last_name) as teacher_name
            FROM classes c LEFT JOIN teachers t ON c.teacher_id = t.id
            WHERE c.status = 'active' ORDER BY c.year DESC, c.subject
        ");
        sendResponse(true, 'Classes retrieved', $stmt->fetchAll());
    } catch (PDOException $e) { sendResponse(false, 'Failed'); }
}

function addClass($pdo) {
    $name = trim($_POST['name'] ?? '');
    $subject = trim($_POST['subject'] ?? '');
    $year = trim($_POST['year'] ?? '');
    $schedule = trim($_POST['schedule'] ?? '');
    $teacherId = (int)($_POST['teacher_id'] ?? 0);
    $price = (float)($_POST['price'] ?? 25000);
    $capacity = (int)($_POST['capacity'] ?? 50);

    if (empty($name) || empty($subject) || empty($year)) { sendResponse(false, 'Required fields missing'); return; }

    try {
        $teacherName = '';
        if ($teacherId) {
            $stmt = $pdo->prepare("SELECT first_name, last_name FROM teachers WHERE id = ?");
            $stmt->execute([$teacherId]);
            $t = $stmt->fetch();
            if ($t) $teacherName = $t['first_name'] . ' ' . $t['last_name'];
        }
        $stmt = $pdo->prepare("INSERT INTO classes (name, subject, year, schedule, teacher_id, teacher, price, capacity) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([$name, $subject, $year, $schedule, $teacherId, $teacherName, $price, $capacity]);
        sendResponse(true, 'Class added', ['id' => $pdo->lastInsertId()]);
    } catch (PDOException $e) { sendResponse(false, 'Failed'); }
}

function deleteClass($pdo) {
    $id = (int)($_POST['id'] ?? 0);
    if (!$id) { sendResponse(false, 'ID required'); return; }
    try {
        $stmt = $pdo->prepare("UPDATE classes SET status = 'inactive' WHERE id = ?");
        $stmt->execute([$id]);
        sendResponse(true, 'Class deleted');
    } catch (PDOException $e) { sendResponse(false, 'Failed'); }
}

// ============================================
// BANK ACCOUNTS
// ============================================

function getBankAccountsAdmin($pdo) {
    try {
        $stmt = $pdo->query("SELECT * FROM bank_accounts ORDER BY id");
        sendResponse(true, 'Bank accounts', $stmt->fetchAll());
    } catch (PDOException $e) { sendResponse(false, 'Failed'); }
}

function saveBankAccount($pdo) {
    $id = (int)($_POST['id'] ?? 0);
    $bankName = trim($_POST['bank_name'] ?? '');
    $accountName = trim($_POST['account_name'] ?? '');
    $accountNumber = trim($_POST['account_number'] ?? '');
    $branch = trim($_POST['branch'] ?? '');
    $swift = trim($_POST['swift_code'] ?? '');
    $isActive = (int)($_POST['is_active'] ?? 1);

    if (empty($bankName) || empty($accountName) || empty($accountNumber)) {
        sendResponse(false, 'Bank name, account name, and account number required'); return;
    }
    try {
        if ($id) {
            $stmt = $pdo->prepare("UPDATE bank_accounts SET bank_name=?, account_name=?, account_number=?, branch=?, swift_code=?, is_active=? WHERE id=?");
            $stmt->execute([$bankName, $accountName, $accountNumber, $branch, $swift, $isActive, $id]);
            sendResponse(true, 'Bank account updated');
        } else {
            $stmt = $pdo->prepare("INSERT INTO bank_accounts (bank_name, account_name, account_number, branch, swift_code, is_active) VALUES (?, ?, ?, ?, ?, ?)");
            $stmt->execute([$bankName, $accountName, $accountNumber, $branch, $swift, $isActive]);
            sendResponse(true, 'Bank account added', ['id' => $pdo->lastInsertId()]);
        }
    } catch (PDOException $e) { sendResponse(false, 'Failed: ' . $e->getMessage()); }
}

function deleteBankAccount($pdo) {
    $id = (int)($_POST['id'] ?? 0);
    if (!$id) { sendResponse(false, 'ID required'); return; }
    try {
        $stmt = $pdo->prepare("DELETE FROM bank_accounts WHERE id = ?");
        $stmt->execute([$id]);
        sendResponse(true, 'Bank account deleted');
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