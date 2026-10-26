<?php
// backend_teacher.php - Teacher API (Attendance removed, filters added)

error_reporting(0);
ini_set('display_errors', 0);

define('DB_HOST', 'localhost');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_NAME', 'flux_academic');

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }

try {
    $pdo = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME, DB_USER, DB_PASS);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
} catch (PDOException $e) { sendResponse(false, 'DB connection failed'); }

$action = $_POST['action'] ?? '';

switch ($action) {
    case 'teacher_overview': teacherOverview($pdo); break;
    case 'get_teacher_profile': getTeacherProfile($pdo); break;
    case 'get_teacher_classes': getTeacherClasses($pdo); break;
    case 'add_class': addClass($pdo); break;
    case 'update_class': updateClass($pdo); break;
    case 'delete_class': deleteClass($pdo); break;
    case 'get_class_students': getClassStudents($pdo); break;
    case 'get_teacher_students': getTeacherStudents($pdo); break;
    case 'get_materials': getMaterials($pdo); break;
    case 'add_material': addMaterial($pdo); break;
    case 'delete_material': deleteMaterial($pdo); break;
    case 'get_meetings': getMeetings($pdo); break;
    case 'add_meeting': addMeeting($pdo); break;
    case 'update_meeting': updateMeeting($pdo); break;
    case 'delete_meeting': deleteMeeting($pdo); break;
    default: sendResponse(false, 'Invalid action: ' . $action);
}

// ============================================
// TEACHER PROFILE
// ============================================

function getTeacherProfile($pdo) {
    $teacherId = (int)($_POST['teacher_id'] ?? 0);
    if (!$teacherId) { sendResponse(false, 'Teacher ID required'); return; }

    try {
        $stmt = $pdo->prepare("
            SELECT id, first_name, last_name, email, phone, username, subject, qualification, status, created_at
            FROM teachers WHERE id = ? AND status = 'active'
        ");
        $stmt->execute([$teacherId]);
        $teacher = $stmt->fetch();

        if (!$teacher) {
            sendResponse(false, 'Teacher not found');
            return;
        }

        sendResponse(true, 'Teacher profile', $teacher);
    } catch (PDOException $e) {
        sendResponse(false, 'Failed: ' . $e->getMessage());
    }
}

// ============================================
// OVERVIEW
// ============================================

function teacherOverview($pdo) {
    $teacherId = (int)($_POST['teacher_id'] ?? 0);
    if (!$teacherId) { sendResponse(false, 'Teacher ID required'); return; }

    try {
        $stats = [];

        // --- Teacher info ---
        $stmt = $pdo->prepare("SELECT first_name, last_name, subject FROM teachers WHERE id = ?");
        $stmt->execute([$teacherId]);
        $teacher = $stmt->fetch();
        if (!$teacher) {
            sendResponse(false, 'Teacher not found');
            return;
        }
        $stats['teacher'] = $teacher;

        // --- Classes ---
        $stmt = $pdo->prepare("
            SELECT c.*,
                   (SELECT COUNT(*) FROM enrollments WHERE class_id = c.id AND status = 'active') as student_count
            FROM classes c
            WHERE c.teacher_id = ? AND c.status = 'active'
            ORDER BY c.year DESC, c.subject
        ");
        $stmt->execute([$teacherId]);
        $stats['classes'] = $stmt->fetchAll();
        $stats['total_classes'] = count($stats['classes']);

        // --- Total unique students ---
        $stmt = $pdo->prepare("
            SELECT COUNT(DISTINCT e.student_id) as total
            FROM enrollments e
            JOIN classes c ON e.class_id = c.id
            WHERE c.teacher_id = ? AND e.status = 'active'
        ");
        $stmt->execute([$teacherId]);
        $stats['total_students'] = (int)($stmt->fetch()['total'] ?? 0);

        // --- Materials ---
        $stmt = $pdo->prepare("SELECT COUNT(*) as total FROM class_materials WHERE teacher_id = ?");
        $stmt->execute([$teacherId]);
        $stats['total_materials'] = (int)($stmt->fetch()['total'] ?? 0);

        // --- Upcoming meetings ---
        $stmt = $pdo->prepare("
            SELECT m.*, c.name as class_name
            FROM zoom_meetings m
            JOIN classes c ON m.class_id = c.id
            WHERE m.teacher_id = ? AND m.start_time > NOW() AND m.status = 'scheduled'
            ORDER BY m.start_time ASC
            LIMIT 5
        ");
        $stmt->execute([$teacherId]);
        $stats['upcoming_meetings'] = $stmt->fetchAll();

        sendResponse(true, 'Overview data', $stats);

    } catch (PDOException $e) {
        sendResponse(false, 'Failed: ' . $e->getMessage());
    }
}

// ============================================
// CLASSES
// ============================================

function getTeacherClasses($pdo) {
    $teacherId = (int)($_POST['teacher_id'] ?? 0);
    if (!$teacherId) { sendResponse(false, 'Teacher ID required'); return; }

    try {
        $stmt = $pdo->prepare("
            SELECT c.*,
                   (SELECT COUNT(*) FROM enrollments WHERE class_id = c.id AND status = 'active') as student_count
            FROM classes c
            WHERE c.teacher_id = ? AND c.status = 'active'
            ORDER BY c.year DESC, c.subject
        ");
        $stmt->execute([$teacherId]);
        sendResponse(true, 'Classes retrieved', $stmt->fetchAll());
    } catch (PDOException $e) {
        sendResponse(false, 'Failed: ' . $e->getMessage());
    }
}

function addClass($pdo) {
    $teacherId = (int)($_POST['teacher_id'] ?? 0);
    $name = trim($_POST['name'] ?? '');
    $subject = trim($_POST['subject'] ?? '');
    $year = trim($_POST['year'] ?? '');
    $schedule = trim($_POST['schedule'] ?? '');
    $price = (float)($_POST['price'] ?? 25000);
    $capacity = (int)($_POST['capacity'] ?? 50);

    if (!$teacherId) { sendResponse(false, 'Teacher ID required'); return; }
    if (empty($name) || empty($subject) || empty($year)) {
        sendResponse(false, 'Name, subject, and year are required');
        return;
    }

    try {
        // Get teacher name
        $stmt = $pdo->prepare("SELECT first_name, last_name FROM teachers WHERE id = ?");
        $stmt->execute([$teacherId]);
        $t = $stmt->fetch();
        if (!$t) { sendResponse(false, 'Teacher not found'); return; }
        $teacherName = $t['first_name'] . ' ' . $t['last_name'];

        // Check duplicate class name for this teacher
        $stmt = $pdo->prepare("
            SELECT id FROM classes 
            WHERE teacher_id = ? AND name = ? AND status = 'active'
        ");
        $stmt->execute([$teacherId, $name]);
        if ($stmt->fetch()) {
            sendResponse(false, 'You already have a class with this name');
            return;
        }

        $stmt = $pdo->prepare("
            INSERT INTO classes (name, subject, year, schedule, teacher_id, teacher, price, capacity)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([$name, $subject, $year, $schedule, $teacherId, $teacherName, $price, $capacity]);

        sendResponse(true, 'Class added', ['id' => $pdo->lastInsertId()]);
    } catch (PDOException $e) {
        sendResponse(false, 'Failed: ' . $e->getMessage());
    }
}

function updateClass($pdo) {
    $teacherId = (int)($_POST['teacher_id'] ?? 0);
    $classId = (int)($_POST['class_id'] ?? 0);
    $name = trim($_POST['name'] ?? '');
    $schedule = trim($_POST['schedule'] ?? '');
    $price = (float)($_POST['price'] ?? 0);
    $capacity = (int)($_POST['capacity'] ?? 50);

    if (!$teacherId || !$classId) { sendResponse(false, 'Teacher ID and Class ID required'); return; }
    if (empty($name)) { sendResponse(false, 'Class name required'); return; }

    try {
        // Verify ownership
        $stmt = $pdo->prepare("SELECT id FROM classes WHERE id = ? AND teacher_id = ? AND status = 'active'");
        $stmt->execute([$classId, $teacherId]);
        if (!$stmt->fetch()) { sendResponse(false, 'Class not found or not yours'); return; }

        $stmt = $pdo->prepare("
            UPDATE classes SET name = ?, schedule = ?, price = ?, capacity = ?
            WHERE id = ? AND teacher_id = ?
        ");
        $stmt->execute([$name, $schedule, $price, $capacity, $classId, $teacherId]);
        sendResponse(true, 'Class updated');
    } catch (PDOException $e) {
        sendResponse(false, 'Failed: ' . $e->getMessage());
    }
}

function deleteClass($pdo) {
    $teacherId = (int)($_POST['teacher_id'] ?? 0);
    $classId = (int)($_POST['class_id'] ?? 0);
    if (!$teacherId || !$classId) { sendResponse(false, 'Teacher ID and Class ID required'); return; }

    try {
        // Check if class has active students
        $stmt = $pdo->prepare("SELECT COUNT(*) as total FROM enrollments WHERE class_id = ? AND status = 'active'");
        $stmt->execute([$classId]);
        if ($stmt->fetch()['total'] > 0) {
            sendResponse(false, 'Cannot delete class with enrolled students');
            return;
        }

        // Verify ownership + soft delete
        $stmt = $pdo->prepare("UPDATE classes SET status = 'inactive' WHERE id = ? AND teacher_id = ?");
        $stmt->execute([$classId, $teacherId]);

        if ($stmt->rowCount() === 0) {
            sendResponse(false, 'Class not found or not yours');
            return;
        }
        sendResponse(true, 'Class deleted');
    } catch (PDOException $e) {
        sendResponse(false, 'Failed: ' . $e->getMessage());
    }
}

// ============================================
// STUDENTS
// ============================================

function getClassStudents($pdo) {
    $classId = (int)($_POST['class_id'] ?? 0);
    if (!$classId) { sendResponse(false, 'Class ID required'); return; }

    try {
        $stmt = $pdo->prepare("
            SELECT s.id, s.first_name, s.last_name, s.email, s.phone, s.program, s.status,
                   e.enrollment_date
            FROM students s
            JOIN enrollments e ON s.id = e.student_id
            WHERE e.class_id = ? AND e.status = 'active' AND s.status = 'active'
            ORDER BY s.first_name, s.last_name
        ");
        $stmt->execute([$classId]);
        sendResponse(true, 'Students retrieved', $stmt->fetchAll());
    } catch (PDOException $e) {
        sendResponse(false, 'Failed: ' . $e->getMessage());
    }
}

function getTeacherStudents($pdo) {
    $teacherId = (int)($_POST['teacher_id'] ?? 0);
    $classId = (int)($_POST['class_id'] ?? 0);
    $search = trim($_POST['search'] ?? '');

    if (!$teacherId) { sendResponse(false, 'Teacher ID required'); return; }

    try {
        $query = "
            SELECT DISTINCT s.id, s.first_name, s.last_name, s.email, s.phone, s.program, s.status,
                   c.name as class_name, c.id as class_id
            FROM students s
            JOIN enrollments e ON s.id = e.student_id
            JOIN classes c ON e.class_id = c.id
            WHERE c.teacher_id = ? AND e.status = 'active' AND s.status = 'active'
        ";
        $params = [$teacherId];

        if ($classId) {
            $query .= " AND c.id = ?";
            $params[] = $classId;
        }
        if ($search !== '') {
            $query .= " AND (s.first_name LIKE ? OR s.last_name LIKE ? OR s.email LIKE ? OR s.phone LIKE ?)";
            $like = '%' . $search . '%';
            array_push($params, $like, $like, $like, $like);
        }

        $query .= " ORDER BY c.name, s.first_name, s.last_name";
        $stmt = $pdo->prepare($query);
        $stmt->execute($params);
        sendResponse(true, 'Students retrieved', $stmt->fetchAll());
    } catch (PDOException $e) {
        sendResponse(false, 'Failed: ' . $e->getMessage());
    }
}

// ============================================
// MATERIALS
// ============================================

function getMaterials($pdo) {
    $teacherId = (int)($_POST['teacher_id'] ?? 0);
    if (!$teacherId) { sendResponse(false, 'Teacher ID required'); return; }

    try {
        $stmt = $pdo->prepare("
            SELECT m.*, c.name as class_name, c.subject as class_subject
            FROM class_materials m
            LEFT JOIN classes c ON m.class_id = c.id
            WHERE m.teacher_id = ?
            ORDER BY m.created_at DESC
        ");
        $stmt->execute([$teacherId]);
        sendResponse(true, 'Materials retrieved', $stmt->fetchAll());
    } catch (PDOException $e) {
        sendResponse(false, 'Failed: ' . $e->getMessage());
    }
}

function addMaterial($pdo) {
    $teacherId = (int)($_POST['teacher_id'] ?? 0);
    $classId = (int)($_POST['class_id'] ?? 0);
    $title = trim($_POST['title'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $fileName = trim($_POST['file_name'] ?? '');
    $fileType = trim($_POST['file_type'] ?? '');

    if (!$teacherId) { sendResponse(false, 'Teacher ID required'); return; }
    if (!$classId) { sendResponse(false, 'Please select a class'); return; }
    if (empty($title)) { sendResponse(false, 'Please enter a title'); return; }

    try {
        // Verify the class belongs to this teacher
        $stmt = $pdo->prepare("SELECT id FROM classes WHERE id = ? AND teacher_id = ? AND status = 'active'");
        $stmt->execute([$classId, $teacherId]);
        if (!$stmt->fetch()) {
            sendResponse(false, 'Class not found or not yours');
            return;
        }

        $stmt = $pdo->prepare("
            INSERT INTO class_materials (class_id, teacher_id, title, description, file_path, file_type)
            VALUES (?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([$classId, $teacherId, $title, $description, $fileName, $fileType]);

        sendResponse(true, 'Material added successfully', ['id' => $pdo->lastInsertId()]);
    } catch (PDOException $e) {
        sendResponse(false, 'Failed: ' . $e->getMessage());
    }
}

function deleteMaterial($pdo) {
    $teacherId = (int)($_POST['teacher_id'] ?? 0);
    $materialId = (int)($_POST['material_id'] ?? 0);

    if (!$teacherId || !$materialId) {
        sendResponse(false, 'Teacher ID and Material ID required');
        return;
    }

    try {
        $stmt = $pdo->prepare("DELETE FROM class_materials WHERE id = ? AND teacher_id = ?");
        $stmt->execute([$materialId, $teacherId]);

        if ($stmt->rowCount() === 0) {
            sendResponse(false, 'Material not found or not yours');
            return;
        }
        sendResponse(true, 'Material deleted');
    } catch (PDOException $e) {
        sendResponse(false, 'Failed: ' . $e->getMessage());
    }
}

// ============================================
// MEETINGS
// ============================================

function getMeetings($pdo) {
    $teacherId = (int)($_POST['teacher_id'] ?? 0);
    if (!$teacherId) { sendResponse(false, 'Teacher ID required'); return; }

    try {
        $stmt = $pdo->prepare("
            SELECT m.*, c.name as class_name, c.subject as class_subject
            FROM zoom_meetings m
            LEFT JOIN classes c ON m.class_id = c.id
            WHERE m.teacher_id = ?
            ORDER BY m.start_time DESC
        ");
        $stmt->execute([$teacherId]);
        sendResponse(true, 'Meetings retrieved', $stmt->fetchAll());
    } catch (PDOException $e) {
        sendResponse(false, 'Failed: ' . $e->getMessage());
    }
}

function addMeeting($pdo) {
    $teacherId = (int)($_POST['teacher_id'] ?? 0);
    $classId = (int)($_POST['class_id'] ?? 0);
    $title = trim($_POST['title'] ?? '');
    $meetingUrl = trim($_POST['meeting_url'] ?? '');
    $password = trim($_POST['password'] ?? '');
    $startTime = trim($_POST['start_time'] ?? '');
    $duration = (int)($_POST['duration'] ?? 60);

    if (!$teacherId) { sendResponse(false, 'Teacher ID required'); return; }
    if (!$classId) { sendResponse(false, 'Please select a class'); return; }
    if (empty($title)) { sendResponse(false, 'Please enter a meeting title'); return; }
    if (empty($meetingUrl)) { sendResponse(false, 'Please enter the Zoom URL'); return; }
    if (empty($startTime)) { sendResponse(false, 'Please select date and time'); return; }

    try {
        // Verify class ownership
        $stmt = $pdo->prepare("SELECT id FROM classes WHERE id = ? AND teacher_id = ? AND status = 'active'");
        $stmt->execute([$classId, $teacherId]);
        if (!$stmt->fetch()) {
            sendResponse(false, 'Class not found or not yours');
            return;
        }

        // Validate start_time is in the future
        if (strtotime($startTime) < time() - 60) {
            sendResponse(false, 'Meeting time must be in the future');
            return;
        }

        $stmt = $pdo->prepare("
            INSERT INTO zoom_meetings 
            (class_id, teacher_id, title, meeting_url, password, start_time, duration, status)
            VALUES (?, ?, ?, ?, ?, ?, ?, 'scheduled')
        ");
        $stmt->execute([$classId, $teacherId, $title, $meetingUrl, $password, $startTime, $duration]);

        sendResponse(true, 'Meeting scheduled', ['id' => $pdo->lastInsertId()]);
    } catch (PDOException $e) {
        sendResponse(false, 'Failed: ' . $e->getMessage());
    }
}

function updateMeeting($pdo) {
    $teacherId = (int)($_POST['teacher_id'] ?? 0);
    $meetingId = (int)($_POST['meeting_id'] ?? 0);
    $title = trim($_POST['title'] ?? '');
    $meetingUrl = trim($_POST['meeting_url'] ?? '');
    $password = trim($_POST['password'] ?? '');
    $startTime = trim($_POST['start_time'] ?? '');
    $duration = (int)($_POST['duration'] ?? 60);
    $status = trim($_POST['status'] ?? 'scheduled');

    if (!$teacherId || !$meetingId) {
        sendResponse(false, 'Teacher ID and Meeting ID required');
        return;
    }

    try {
        $stmt = $pdo->prepare("
            UPDATE zoom_meetings
            SET title = ?, meeting_url = ?, password = ?, start_time = ?, duration = ?, status = ?
            WHERE id = ? AND teacher_id = ?
        ");
        $stmt->execute([$title, $meetingUrl, $password, $startTime, $duration, $status, $meetingId, $teacherId]);

        if ($stmt->rowCount() === 0) {
            sendResponse(false, 'Meeting not found or not yours');
            return;
        }
        sendResponse(true, 'Meeting updated');
    } catch (PDOException $e) {
        sendResponse(false, 'Failed: ' . $e->getMessage());
    }
}

function deleteMeeting($pdo) {
    $teacherId = (int)($_POST['teacher_id'] ?? 0);
    $meetingId = (int)($_POST['meeting_id'] ?? 0);

    if (!$teacherId || !$meetingId) {
        sendResponse(false, 'Teacher ID and Meeting ID required');
        return;
    }

    try {
        $stmt = $pdo->prepare("DELETE FROM zoom_meetings WHERE id = ? AND teacher_id = ?");
        $stmt->execute([$meetingId, $teacherId]);

        if ($stmt->rowCount() === 0) {
            sendResponse(false, 'Meeting not found or not yours');
            return;
        }
        sendResponse(true, 'Meeting deleted');
    } catch (PDOException $e) {
        sendResponse(false, 'Failed: ' . $e->getMessage());
    }
}

// ============================================
// RESPONSE HELPER
// ============================================

function sendResponse($success, $message, $data = null) {
    if (ob_get_length()) ob_clean();
    $response = ['success' => $success, 'message' => $message];
    if ($data !== null) $response['data'] = $data;
    echo json_encode($response);
    exit;
}
?>