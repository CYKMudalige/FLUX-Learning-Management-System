<?php
// backend_reception.php - Full Reception + POS + QR Attendance

error_reporting(0);
ini_set('display_errors', 0);

define('DB_HOST', 'localhost');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_NAME', 'flux_academic');

// SMS (Notify.lk) — set true when configured
define('SMS_ENABLED', false);
define('SMS_USER_ID', 'YOUR_NOTIFY_USER_ID');
define('SMS_API_KEY', 'YOUR_NOTIFY_API_KEY');
define('SMS_SENDER_ID', 'FluxAcad');

// Email
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
    case 'reception_overview': receptionOverview($pdo); break;
    case 'search_students': searchStudents($pdo); break;
    case 'register_student': registerStudent($pdo); break;
    case 'get_student_details': getStudentDetails($pdo); break;
    case 'get_student_enrollments': getStudentEnrollments($pdo); break;
    case 'get_student_qr': getStudentQR($pdo); break;
    case 'get_recent_students': getRecentStudents($pdo); break;
    case 'get_all_classes': getAllClasses($pdo); break;
    case 'pos_search_student': posSearchStudent($pdo); break;
    case 'pos_create_sale': posCreateSale($pdo); break;
    case 'pos_get_sale': posGetSale($pdo); break;
    case 'pos_recent_sales': posRecentSales($pdo); break;
    case 'pos_daily_summary': posDailySummary($pdo); break;
    case 'pos_void_sale': posVoidSale($pdo); break;
    case 'scan_qr': scanQR($pdo); break;
    case 'get_today_attendance': getTodayAttendance($pdo); break;
    case 'get_attendance_for_class': getAttendanceForClass($pdo); break;
    case 'list_inquiries': listInquiries($pdo); break;
    case 'add_inquiry': addInquiry($pdo); break;
    case 'update_inquiry': updateInquiry($pdo); break;
    case 'delete_inquiry': deleteInquiry($pdo); break;
    default: sendResponse(false, 'Invalid action: ' . $action);
}

// ============================================
// OVERVIEW
// ============================================

function receptionOverview($pdo) {
    try {
        $stats = [];
        $stmt = $pdo->query("SELECT COUNT(*) as total FROM students WHERE DATE(created_at) = CURDATE()");
        $stats['today_students'] = (int)$stmt->fetch()['total'];

        $stmt = $pdo->query("
            SELECT COUNT(*) as count, COALESCE(SUM(total), 0) as total 
            FROM pos_sales WHERE DATE(created_at) = CURDATE() AND status = 'completed'
        ");
        $row = $stmt->fetch();
        $stats['today_sales_count'] = (int)$row['count'];
        $stats['today_sales_amount'] = (float)$row['total'];

        $stmt = $pdo->query("SELECT COUNT(*) as total FROM payments WHERE payment_status = 'pending'");
        $stats['pending_slips'] = (int)$stmt->fetch()['total'];

        $stmt = $pdo->query("SELECT COUNT(*) as total FROM inquiries WHERE status = 'new'");
        $stats['new_inquiries'] = (int)$stmt->fetch()['total'];

        $stmt = $pdo->query("SELECT COUNT(*) as total FROM students WHERE status = 'active'");
        $stats['total_students'] = (int)$stmt->fetch()['total'];

        $stmt = $pdo->query("SELECT COUNT(*) as total FROM attendance_qr WHERE DATE(scan_time) = CURDATE()");
        $stats['today_attendance'] = (int)$stmt->fetch()['total'];

        sendResponse(true, 'Overview', $stats);
    } catch (PDOException $e) { sendResponse(false, 'Failed: ' . $e->getMessage()); }
}

// ============================================
// STUDENTS
// ============================================

function searchStudents($pdo) {
    $search = trim($_POST['search'] ?? '');
    try {
        if ($search === '') {
            $stmt = $pdo->query("
                SELECT s.*, 
                    (SELECT COUNT(*) FROM enrollments WHERE student_id = s.id AND status = 'active') as enrolled_count
                FROM students s WHERE s.status = 'active'
                ORDER BY s.created_at DESC LIMIT 50
            ");
        } else {
            $like = '%' . $search . '%';
            $stmt = $pdo->prepare("
                SELECT s.*, 
                    (SELECT COUNT(*) FROM enrollments WHERE student_id = s.id AND status = 'active') as enrolled_count
                FROM students s 
                WHERE s.status = 'active'
                AND (s.first_name LIKE ? OR s.last_name LIKE ? OR s.email LIKE ? 
                     OR s.phone LIKE ? OR s.card_number LIKE ?)
                ORDER BY s.created_at DESC LIMIT 50
            ");
            $stmt->execute([$like, $like, $like, $like, $like]);
        }
        sendResponse(true, 'Students', $stmt->fetchAll());
    } catch (PDOException $e) { sendResponse(false, 'Failed'); }
}

function getStudentQR($pdo) {
    $studentId = (int)($_POST['student_id'] ?? 0);
    if (!$studentId) { sendResponse(false, 'Student ID required'); return; }
    try {
        $stmt = $pdo->prepare("SELECT id, first_name, last_name, email, phone, card_number, qr_token FROM students WHERE id = ?");
        $stmt->execute([$studentId]);
        $student = $stmt->fetch();
        if (!$student) { sendResponse(false, 'Student not found'); return; }

        if (!$student['qr_token']) {
            $qrToken = 'QR' . strtoupper(md5($studentId . $student['email'] . time()));
            $stmt = $pdo->prepare("UPDATE students SET qr_token = ? WHERE id = ?");
            $stmt->execute([$qrToken, $studentId]);
            $student['qr_token'] = $qrToken;
        }
        sendResponse(true, 'QR', $student);
    } catch (PDOException $e) { sendResponse(false, 'Failed'); }
}

function registerStudent($pdo) {
    $fname = trim($_POST['first_name'] ?? '');
    $lname = trim($_POST['last_name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    $program = trim($_POST['program'] ?? 'A/L 2026 Batch');
    $createdBy = (int)($_POST['created_by'] ?? 0);

    if (empty($fname) || empty($lname) || empty($email) || empty($phone) || empty($username) || empty($password)) {
        sendResponse(false, 'All fields required'); return;
    }
    if (strlen($password) < 6) { sendResponse(false, 'Password min 6 chars'); return; }

    try {
        $stmt = $pdo->prepare("SELECT id FROM students WHERE username = ? OR email = ?");
        $stmt->execute([$username, $email]);
        if ($stmt->fetch()) { sendResponse(false, 'Username or email exists'); return; }

        $hashed = password_hash($password, PASSWORD_DEFAULT);
        $stmt = $pdo->prepare("
            INSERT INTO students 
            (first_name, last_name, email, phone, username, password, program, created_by_role, created_by_id) 
            VALUES (?, ?, ?, ?, ?, ?, ?, 'reception', ?)
        ");
        $stmt->execute([$fname, $lname, $email, $phone, $username, $hashed, $program, $createdBy]);
        $studentId = $pdo->lastInsertId();

        $cardNumber = 'FLX' . str_pad($studentId, 6, '0', STR_PAD_LEFT);
        $qrToken = 'QR' . strtoupper(md5($studentId . $email . time()));

        $stmt = $pdo->prepare("UPDATE students SET card_number = ?, qr_token = ? WHERE id = ?");
        $stmt->execute([$cardNumber, $qrToken, $studentId]);

        sendResponse(true, 'Student registered', [
            'id' => $studentId,
            'username' => $username,
            'password' => $password,
            'card_number' => $cardNumber,
            'qr_token' => $qrToken
        ]);
    } catch (PDOException $e) { sendResponse(false, 'Failed: ' . $e->getMessage()); }
}

function getStudentDetails($pdo) {
    $studentId = (int)($_POST['student_id'] ?? 0);
    if (!$studentId) { sendResponse(false, 'Student ID required'); return; }
    try {
        $stmt = $pdo->prepare("SELECT * FROM students WHERE id = ?");
        $stmt->execute([$studentId]);
        $student = $stmt->fetch();
        if (!$student) { sendResponse(false, 'Student not found'); return; }

        $stmt = $pdo->prepare("
            SELECT e.*, c.name as class_name, c.subject, c.year, c.price
            FROM enrollments e JOIN classes c ON e.class_id = c.id
            WHERE e.student_id = ? ORDER BY e.enrollment_date DESC
        ");
        $stmt->execute([$studentId]);
        $student['enrollments'] = $stmt->fetchAll();

        $stmt = $pdo->prepare("
            SELECT p.*, c.name as class_name FROM payments p
            JOIN classes c ON p.class_id = c.id
            WHERE p.student_id = ? ORDER BY p.payment_date DESC LIMIT 10
        ");
        $stmt->execute([$studentId]);
        $student['payments'] = $stmt->fetchAll();

        sendResponse(true, 'Details', $student);
    } catch (PDOException $e) { sendResponse(false, 'Failed'); }
}

function getStudentEnrollments($pdo) {
    $studentId = (int)($_POST['student_id'] ?? 0);
    if (!$studentId) { sendResponse(false, 'Student ID required'); return; }
    try {
        $stmt = $pdo->prepare("
            SELECT e.id as enrollment_id, e.class_id, e.status, e.enrollment_date,
                   c.name as class_name, c.subject, c.year, c.price
            FROM enrollments e JOIN classes c ON e.class_id = c.id
            WHERE e.student_id = ? ORDER BY e.enrollment_date DESC
        ");
        $stmt->execute([$studentId]);
        sendResponse(true, 'Enrollments', $stmt->fetchAll());
    } catch (PDOException $e) { sendResponse(false, 'Failed'); }
}

function getRecentStudents($pdo) {
    try {
        $stmt = $pdo->query("
            SELECT id, first_name, last_name, email, phone, username, card_number, qr_token, program, created_at
            FROM students WHERE status = 'active'
            ORDER BY created_at DESC LIMIT 20
        ");
        sendResponse(true, 'Recent', $stmt->fetchAll());
    } catch (PDOException $e) { sendResponse(false, 'Failed'); }
}

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
        sendResponse(true, 'Classes', $stmt->fetchAll());
    } catch (PDOException $e) { sendResponse(false, 'Failed'); }
}

// ============================================
// POS
// ============================================

function posSearchStudent($pdo) {
    $query = trim($_POST['query'] ?? '');
    if (strlen($query) < 2) { sendResponse(false, 'Query too short'); return; }
    try {
        $like = '%' . $query . '%';
        $stmt = $pdo->prepare("
            SELECT id, first_name, last_name, email, phone, card_number,
                   (SELECT COUNT(*) FROM enrollments WHERE student_id = students.id AND status = 'active') as enrolled_count
            FROM students
            WHERE status = 'active'
            AND (card_number = ? OR first_name LIKE ? OR last_name LIKE ? OR email LIKE ? 
                 OR phone LIKE ? OR CONCAT(first_name, ' ', last_name) LIKE ?)
            ORDER BY CASE WHEN card_number = ? THEN 1 ELSE 2 END, first_name
            LIMIT 20
        ");
        $stmt->execute([$query, $like, $like, $like, $like, $like, $query]);
        sendResponse(true, 'Found', $stmt->fetchAll());
    } catch (PDOException $e) { sendResponse(false, 'Failed'); }
}

function posCreateSale($pdo) {
    $studentId = (int)($_POST['student_id'] ?? 0);
    $studentName = trim($_POST['student_name'] ?? '');
    $studentPhone = trim($_POST['student_phone'] ?? '');
    $studentEmail = trim($_POST['student_email'] ?? '');
    $itemsJson = $_POST['items'] ?? '[]';
    $paymentMethod = trim($_POST['payment_method'] ?? 'cash');
    $amountPaid = (float)($_POST['amount_paid'] ?? 0);
    $discount = (float)($_POST['discount'] ?? 0);
    $notes = trim($_POST['notes'] ?? '');
    $cashierId = (int)($_POST['cashier_id'] ?? 0);

    if (!$cashierId) { sendResponse(false, 'Cashier required'); return; }
    if (!$studentName) { sendResponse(false, 'Student name required'); return; }

    $items = json_decode($itemsJson, true);
    if (!is_array($items) || count($items) === 0) { sendResponse(false, 'Cart empty'); return; }

    try {
        $pdo->beginTransaction();
        $subtotal = 0;
        $validatedItems = [];

        foreach ($items as $item) {
            $classId = (int)($item['class_id'] ?? 0);
            if (!$classId) continue;

            $stmt = $pdo->prepare("SELECT * FROM classes WHERE id = ? AND status = 'active'");
            $stmt->execute([$classId]);
            $class = $stmt->fetch();
            if (!$class) { $pdo->rollBack(); sendResponse(false, "Class not found"); return; }

            if ($studentId) {
                $stmt = $pdo->prepare("SELECT id FROM enrollments WHERE student_id = ? AND class_id = ? AND status = 'active'");
                $stmt->execute([$studentId, $classId]);
                if ($stmt->fetch()) {
                    $pdo->rollBack();
                    sendResponse(false, "Already enrolled in: {$class['name']}");
                    return;
                }
            }

            $unitPrice = (float)$class['price'];
            $subtotal += $unitPrice;
            $validatedItems[] = [
                'class_id' => $classId,
                'class_name' => $class['name'],
                'subject' => $class['subject'],
                'year' => $class['year'],
                'unit_price' => $unitPrice,
                'line_total' => $unitPrice,
            ];
        }

        if (count($validatedItems) === 0) { $pdo->rollBack(); sendResponse(false, 'No items'); return; }

        $total = max(0, $subtotal - $discount);
        $change = max(0, $amountPaid - $total);
        $receiptNumber = 'POS-' . date('Ymd') . '-' . str_pad(rand(1, 99999), 5, '0', STR_PAD_LEFT);

        $stmt = $pdo->prepare("
            INSERT INTO pos_sales 
            (receipt_number, student_id, student_name, student_phone, student_email,
             subtotal, discount, total, payment_method, amount_paid, change_given,
             cashier_id, notes, status)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'completed')
        ");
        $stmt->execute([
            $receiptNumber, $studentId ?: null, $studentName, $studentPhone, $studentEmail,
            $subtotal, $discount, $total, $paymentMethod, $amountPaid, $change,
            $cashierId, $notes
        ]);
        $saleId = $pdo->lastInsertId();

        foreach ($validatedItems as $vi) {
            $stmt = $pdo->prepare("
                INSERT INTO pos_sale_items 
                (sale_id, class_id, class_name, subject, year, unit_price, line_total)
                VALUES (?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $saleId, $vi['class_id'], $vi['class_name'],
                $vi['subject'], $vi['year'], $vi['unit_price'], $vi['line_total']
            ]);

            if ($studentId) {
                $stmt = $pdo->prepare("
                    INSERT INTO enrollments (student_id, class_id, status) 
                    VALUES (?, ?, 'active') ON DUPLICATE KEY UPDATE status = 'active'
                ");
                $stmt->execute([$studentId, $vi['class_id']]);

                $stmt = $pdo->prepare("UPDATE classes SET enrolled = enrolled + 1 WHERE id = ?");
                $stmt->execute([$vi['class_id']]);

                $txnId = 'POS-' . strtoupper(substr(uniqid(), -8));
                $stmt = $pdo->prepare("
                    INSERT INTO payments 
                    (student_id, class_id, amount, currency, payment_method, transaction_id, receipt_number,
                     payment_status, payment_date, received_by_role, received_by_id, payment_channel)
                    VALUES (?, ?, ?, 'LKR', ?, ?, ?, 'completed', NOW(), 'reception', ?, 'pos')
                ");
                $stmt->execute([
                    $studentId, $vi['class_id'], $vi['unit_price'],
                    $paymentMethod, $txnId, $receiptNumber . '-' . $vi['class_id'], $cashierId
                ]);
            }
        }

        $pdo->commit();
        sendResponse(true, 'Sale completed', [
            'sale_id' => $saleId,
            'receipt_number' => $receiptNumber,
            'subtotal' => $subtotal,
            'discount' => $discount,
            'total' => $total,
            'amount_paid' => $amountPaid,
            'change' => $change,
            'items' => $validatedItems,
            'student_name' => $studentName,
            'payment_method' => $paymentMethod,
            'date' => date('Y-m-d H:i:s')
        ]);
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        sendResponse(false, 'Failed: ' . $e->getMessage());
    }
}

function posGetSale($pdo) {
    $saleId = (int)($_POST['sale_id'] ?? 0);
    if (!$saleId) { sendResponse(false, 'Sale ID required'); return; }
    try {
        $stmt = $pdo->prepare("
            SELECT s.*, CONCAT(r.first_name, ' ', r.last_name) as cashier_name
            FROM pos_sales s LEFT JOIN reception_staff r ON s.cashier_id = r.id
            WHERE s.id = ?
        ");
        $stmt->execute([$saleId]);
        $sale = $stmt->fetch();
        if (!$sale) { sendResponse(false, 'Not found'); return; }
        $stmt = $pdo->prepare("SELECT * FROM pos_sale_items WHERE sale_id = ?");
        $stmt->execute([$saleId]);
        $sale['items'] = $stmt->fetchAll();
        sendResponse(true, 'Details', $sale);
    } catch (PDOException $e) { sendResponse(false, 'Failed'); }
}

function posRecentSales($pdo) {
    try {
        $stmt = $pdo->query("
            SELECT s.*, CONCAT(r.first_name, ' ', r.last_name) as cashier_name,
                   (SELECT COUNT(*) FROM pos_sale_items WHERE sale_id = s.id) as item_count
            FROM pos_sales s LEFT JOIN reception_staff r ON s.cashier_id = r.id
            WHERE s.status = 'completed'
            ORDER BY s.created_at DESC LIMIT 50
        ");
        sendResponse(true, 'Sales', $stmt->fetchAll());
    } catch (PDOException $e) { sendResponse(false, 'Failed'); }
}

function posDailySummary($pdo) {
    $date = $_POST['date'] ?? date('Y-m-d');
    try {
        $stmt = $pdo->prepare("
            SELECT 
                COUNT(*) as total_sales,
                COALESCE(SUM(total), 0) as gross_total,
                COALESCE(SUM(discount), 0) as total_discount,
                COALESCE(SUM(CASE WHEN payment_method = 'cash' THEN total ELSE 0 END), 0) as cash_total,
                COALESCE(SUM(CASE WHEN payment_method = 'card' THEN total ELSE 0 END), 0) as card_total,
                COALESCE(SUM(CASE WHEN payment_method = 'bank_transfer' THEN total ELSE 0 END), 0) as bank_total
            FROM pos_sales WHERE DATE(created_at) = ? AND status = 'completed'
        ");
        $stmt->execute([$date]);
        sendResponse(true, 'Summary', $stmt->fetch());
    } catch (PDOException $e) { sendResponse(false, 'Failed'); }
}

function posVoidSale($pdo) {
    $saleId = (int)($_POST['sale_id'] ?? 0);
    if (!$saleId) { sendResponse(false, 'Sale ID required'); return; }
    try {
        $pdo->beginTransaction();
        $stmt = $pdo->prepare("SELECT * FROM pos_sales WHERE id = ? AND status = 'completed'");
        $stmt->execute([$saleId]);
        $sale = $stmt->fetch();
        if (!$sale) { $pdo->rollBack(); sendResponse(false, 'Not found'); return; }

        $stmt = $pdo->prepare("SELECT * FROM pos_sale_items WHERE sale_id = ?");
        $stmt->execute([$saleId]);
        $items = $stmt->fetchAll();

        if ($sale['student_id']) {
            foreach ($items as $item) {
                $stmt = $pdo->prepare("
                    UPDATE enrollments SET status = 'dropped' 
                    WHERE student_id = ? AND class_id = ? AND status = 'active'
                ");
                $stmt->execute([$sale['student_id'], $item['class_id']]);

                $stmt = $pdo->prepare("UPDATE classes SET enrolled = GREATEST(enrolled - 1, 0) WHERE id = ?");
                $stmt->execute([$item['class_id']]);
            }
        }

        $stmt = $pdo->prepare("UPDATE pos_sales SET status = 'void' WHERE id = ?");
        $stmt->execute([$saleId]);
        $pdo->commit();
        sendResponse(true, 'Sale voided');
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        sendResponse(false, 'Failed');
    }
}

// ============================================
// QR ATTENDANCE
// ============================================

function scanQR($pdo) {
    $qrToken = trim($_POST['qr_token'] ?? '');
    $classId = (int)($_POST['class_id'] ?? 0);
    $markedBy = (int)($_POST['marked_by'] ?? 0);

    if (!$qrToken) { sendResponse(false, 'QR token required'); return; }
    if (!$classId) { sendResponse(false, 'Please select a class'); return; }

    try {
        // Find student by QR token OR card number
        $stmt = $pdo->prepare("
            SELECT * FROM students 
            WHERE (qr_token = ? OR card_number = ?) AND status = 'active'
        ");
        $stmt->execute([$qrToken, $qrToken]);
        $student = $stmt->fetch();
        if (!$student) { sendResponse(false, 'Student not found for this QR'); return; }

        // Check enrollment
        $stmt = $pdo->prepare("
            SELECT e.id FROM enrollments e
            WHERE e.student_id = ? AND e.class_id = ? AND e.status = 'active'
        ");
        $stmt->execute([$student['id'], $classId]);
        if (!$stmt->fetch()) {
            sendResponse(false, "{$student['first_name']} {$student['last_name']} is not enrolled in this class");
            return;
        }

        // Check today's attendance
        $today = date('Y-m-d');
        $stmt = $pdo->prepare("
            SELECT id FROM attendance_qr 
            WHERE student_id = ? AND class_id = ? AND DATE(scan_time) = ?
        ");
        $stmt->execute([$student['id'], $classId, $today]);
        if ($stmt->fetch()) {
            sendResponse(false, "{$student['first_name']} already marked present today", [
                'already_marked' => true,
                'student_name' => $student['first_name'] . ' ' . $student['last_name']
            ]);
            return;
        }

        // Determine status (late if >30 min after class start — simplified: always present for now)
        $status = 'present';

        // Insert attendance
        $stmt = $pdo->prepare("
            INSERT INTO attendance_qr (student_id, class_id, status, marked_by, marked_by_role)
            VALUES (?, ?, ?, ?, 'reception')
        ");
        $stmt->execute([$student['id'], $classId, $status, $markedBy]);
        $attendanceId = $pdo->lastInsertId();

        // Also log to old `attendance` table for compatibility
        try {
            $stmt = $pdo->prepare("
                INSERT INTO attendance (student_id, class_id, date, status, marked_by, marked_by_role)
                VALUES (?, ?, ?, ?, ?, 'reception')
                ON DUPLICATE KEY UPDATE status = ?, marked_by_role = 'reception'
            ");
            $stmt->execute([$student['id'], $classId, $today, $status, $markedBy, $status]);
        } catch (PDOException $e) { /* ignore if table missing */ }

        // Get class name for notification
        $stmt = $pdo->prepare("SELECT name FROM classes WHERE id = ?");
        $stmt->execute([$classId]);
        $class = $stmt->fetch();

        // Send SMS/Email
        $notifSent = sendAttendanceNotification($pdo, $student, $class, $status);

        sendResponse(true, 'Attendance marked!', [
            'student_name' => $student['first_name'] . ' ' . $student['last_name'],
            'card_number' => $student['card_number'],
            'class_name' => $class['name'],
            'status' => $status,
            'time' => date('H:i:s'),
            'notification_sent' => $notifSent,
            'attendance_id' => $attendanceId
        ]);

    } catch (PDOException $e) {
        sendResponse(false, 'Failed: ' . $e->getMessage());
    }
}

function getTodayAttendance($pdo) {
    try {
        $stmt = $pdo->query("
            SELECT a.*, 
                   CONCAT(s.first_name, ' ', s.last_name) as student_name,
                   s.card_number, s.phone as student_phone,
                   c.name as class_name
            FROM attendance_qr a
            JOIN students s ON a.student_id = s.id
            JOIN classes c ON a.class_id = c.id
            WHERE DATE(a.scan_time) = CURDATE()
            ORDER BY a.scan_time DESC
        ");
        sendResponse(true, 'Today attendance', $stmt->fetchAll());
    } catch (PDOException $e) { sendResponse(false, 'Failed'); }
}

function getAttendanceForClass($pdo) {
    $classId = (int)($_POST['class_id'] ?? 0);
    $date = $_POST['date'] ?? date('Y-m-d');
    if (!$classId) { sendResponse(false, 'Class ID required'); return; }
    try {
        $stmt = $pdo->prepare("
            SELECT a.*, 
                   CONCAT(s.first_name, ' ', s.last_name) as student_name,
                   s.card_number
            FROM attendance_qr a
            JOIN students s ON a.student_id = s.id
            WHERE a.class_id = ? AND DATE(a.scan_time) = ?
            ORDER BY a.scan_time DESC
        ");
        $stmt->execute([$classId, $date]);
        sendResponse(true, 'Class attendance', $stmt->fetchAll());
    } catch (PDOException $e) { sendResponse(false, 'Failed'); }
}

// ============================================
// NOTIFICATIONS
// ============================================

function sendAttendanceNotification($pdo, $student, $class, $status) {
    $sent = false;

    // SMS
    if (SMS_ENABLED && !empty($student['phone'])) {
        $message = "Hi {$student['first_name']}, you were marked {$status} for {$class['name']} at " . date('H:i') . ". — Flux Academic";
        $sent = sendSMS($pdo, $student['phone'], $message, $student['id']);
    }

    // Email
    if (!empty($student['email'])) {
        sendAttendanceEmail($student, $class, $status);
        $sent = true;
    }

    // Update notification flag
    if ($sent) {
        $stmt = $pdo->prepare("UPDATE attendance_qr SET notification_sent = 1 WHERE student_id = ? AND class_id = ? AND DATE(scan_time) = CURDATE()");
        $stmt->execute([$student['id'], $class['id']]);
    }

    return $sent;
}

function sendSMS($pdo, $phone, $message, $studentId) {
    $userId = SMS_USER_ID;
    $apiKey = SMS_API_KEY;
    $senderId = SMS_SENDER_ID;

    $url = 'https://app.notify.lk/api/v1/send?' . http_build_query([
        'user_id' => $userId,
        'api_key' => $apiKey,
        'sender_id' => $senderId,
        'to' => $phone,
        'message' => $message
    ]);

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $status = ($httpCode === 200) ? 'sent' : 'failed';
    $stmt = $pdo->prepare("INSERT INTO sms_log (phone, message, type, student_id, status) VALUES (?, ?, 'attendance', ?, ?)");
    $stmt->execute([$phone, $message, $studentId, $status]);

    return $status === 'sent';
}

function sendAttendanceEmail($student, $class, $status) {
    $to = $student['email'];
    $subject = "Attendance Confirmed - {$class['name']}";
    $body = "
    <html><body style='font-family:Arial,sans-serif;background:#f5f5f5;padding:20px;'>
    <div style='max-width:600px;margin:auto;background:#fff;border-radius:12px;padding:30px;'>
        <h2 style='color:#0d7c6e;margin-top:0;'>✅ Attendance Marked</h2>
        <p>Dear {$student['first_name']} {$student['last_name']},</p>
        <p>You have been marked <strong style='color:#2ecc71;text-transform:uppercase;'>{$status}</strong> for:</p>
        <div style='background:#f8f9fa;border-left:4px solid #0d7c6e;padding:15px;margin:20px 0;'>
            <p style='margin:5px 0;'><strong>Class:</strong> {$class['name']}</p>
            <p style='margin:5px 0;'><strong>Time:</strong> " . date('Y-m-d H:i:s') . "</p>
            <p style='margin:5px 0;'><strong>Status:</strong> " . ucfirst($status) . "</p>
        </div>
        <p style='color:#888;font-size:12px;margin-top:30px;'>Flux Academic</p>
    </div>
    </body></html>
    ";
    $headers = "MIME-Version: 1.0\r\nContent-type: text/html; charset=UTF-8\r\nFrom: " . FROM_EMAIL . "\r\n";
    @mail($to, $subject, $body, $headers);
}

// ============================================
// INQUIRIES
// ============================================

function listInquiries($pdo) {
    $status = trim($_POST['status'] ?? '');
    try {
        if ($status) {
            $stmt = $pdo->prepare("
                SELECT i.*, CONCAT(r.first_name, ' ', r.last_name) as handled_by_name
                FROM inquiries i LEFT JOIN reception_staff r ON i.handled_by = r.id
                WHERE i.status = ? ORDER BY i.created_at DESC
            ");
            $stmt->execute([$status]);
        } else {
            $stmt = $pdo->query("
                SELECT i.*, CONCAT(r.first_name, ' ', r.last_name) as handled_by_name
                FROM inquiries i LEFT JOIN reception_staff r ON i.handled_by = r.id
                ORDER BY i.created_at DESC LIMIT 100
            ");
        }
        sendResponse(true, 'Inquiries', $stmt->fetchAll());
    } catch (PDOException $e) { sendResponse(false, 'Failed'); }
}

function addInquiry($pdo) {
    $fullName = trim($_POST['full_name'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $subjectInterest = trim($_POST['subject_interest'] ?? '');
    $message = trim($_POST['message'] ?? '');
    $handledBy = (int)($_POST['handled_by'] ?? 0);

    if (empty($fullName) || empty($phone)) { sendResponse(false, 'Name and phone required'); return; }

    try {
        $stmt = $pdo->prepare("
            INSERT INTO inquiries (full_name, phone, email, subject_interest, message, handled_by)
            VALUES (?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([$fullName, $phone, $email, $subjectInterest, $message, $handledBy ?: null]);
        sendResponse(true, 'Inquiry added', ['id' => $pdo->lastInsertId()]);
    } catch (PDOException $e) { sendResponse(false, 'Failed'); }
}

function updateInquiry($pdo) {
    $id = (int)($_POST['id'] ?? 0);
    $status = trim($_POST['status'] ?? '');
    $notes = trim($_POST['notes'] ?? '');
    if (!$id) { sendResponse(false, 'ID required'); return; }
    try {
        $stmt = $pdo->prepare("UPDATE inquiries SET status = ?, notes = ? WHERE id = ?");
        $stmt->execute([$status, $notes, $id]);
        sendResponse(true, 'Updated');
    } catch (PDOException $e) { sendResponse(false, 'Failed'); }
}

function deleteInquiry($pdo) {
    $id = (int)($_POST['id'] ?? 0);
    if (!$id) { sendResponse(false, 'ID required'); return; }
    try {
        $stmt = $pdo->prepare("DELETE FROM inquiries WHERE id = ?");
        $stmt->execute([$id]);
        sendResponse(true, 'Deleted');
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