<?php
// Ensure we always return clean JSON (no PHP warnings/notices in output)
ini_set('display_errors', '0');
ini_set('log_errors', '1');

// Start output buffering so we can recover from fatal errors and return JSON
ob_start();
$rootPath = dirname(__DIR__);

// Fatal error handler to convert white-screen/HTML 500 into JSON
register_shutdown_function(function () {
    $error = error_get_last();
    if ($error && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        while (ob_get_level()) { ob_end_clean(); }
        @header('Content-Type: application/json');
        @header('X-Content-Type-Options: nosniff');
        http_response_code(200);
        $message = 'Server error: ' . $error['message'];
        // Best-effort file log inside uploads/
        $uploadBase = getenv('VERCEL') ? sys_get_temp_dir() . DIRECTORY_SEPARATOR : __DIR__ . DIRECTORY_SEPARATOR;
        $logDir = $uploadBase . 'uploads';
        if (!is_dir($logDir)) { @mkdir($logDir, 0755, true); }
        @file_put_contents($logDir . DIRECTORY_SEPARATOR . 'error.log', date('c') . ' ' . $message . "\n", FILE_APPEND);
        echo json_encode(['success' => false, 'message' => $message]);
    } else {
        // Flush any buffered output if no fatal error
        if (ob_get_length() !== false) { @ob_end_flush(); }
    }
});

// Try to load PHPMailer if present (Composer or manual include)
$composerAutoload = __DIR__ . '/vendor/autoload.php';
if (file_exists($composerAutoload)) {
    require_once $composerAutoload;
} else {
    // Common manual locations: phpmailer/src or any PHPMailer*/src in current directory
    $candidateDirs = [];
    $candidateDirs[] = dirname(__DIR__) . '/phpmailer/src/';
    $candidateDirs[] = dirname(__DIR__) . '/PHPMailer/src/';
    foreach (glob(dirname(__DIR__) . '/PHPMailer*/src/', GLOB_NOSORT) as $g) { $candidateDirs[] = $g; }
    foreach (glob(dirname(__DIR__) . '/phpmailer*/src/', GLOB_NOSORT) as $g) { $candidateDirs[] = $g; }

    foreach ($candidateDirs as $phpMailerBase) {
        if (is_file($phpMailerBase . 'PHPMailer.php')) {
            require_once $phpMailerBase . 'PHPMailer.php';
            require_once $phpMailerBase . 'SMTP.php';
            require_once $phpMailerBase . 'Exception.php';
            break;
        }
    }
}

header('Content-Type: application/json');
header('X-Content-Type-Options: nosniff');

// Convert PHP warnings/notices into exceptions so we can send JSON
set_error_handler(function ($severity, $message, $file, $line) {
    if (!(error_reporting() & $severity)) {
        return false;
    }
    throw new ErrorException($message, 0, $severity, $file, $line);
});

// Allow only the production domain(s)
$allowed_origins = [
    'https://mefainstitute.co.ke',
    'https://www.mefainstitute.co.ke',
    'http://mefainstitute.co.ke',
    'http://www.mefainstitute.co.ke',
    'https://mefa-application.mefainstitute.co.ke',
    'http://mefa-application.mefainstitute.co.ke'
];
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if ($origin && in_array($origin, $allowed_origins, true)) {
    header('Access-Control-Allow-Origin: ' . $origin);
} else if (!empty($_SERVER['HTTP_HOST'])) {
    // Same-origin page load (no CORS) – allow current host
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https://' : 'http://';
    header('Access-Control-Allow-Origin: ' . $scheme . $_SERVER['HTTP_HOST']);
}
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

// Handle preflight OPTIONS request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// Configuration
$config = [
    'admin_email' => 'applications@mefainstitute.co.ke', // Where applications will be sent
    'from_email' => 'noreply@mefainstitute.co.ke', // Sender must be on your domain
    'upload_dir' => (getenv('VERCEL') ? sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'uploads' : 'uploads') . DIRECTORY_SEPARATOR,
    'max_file_size' => 10 * 1024 * 1024, // 10MB default; smaller enforced per-file where needed
    'allowed_extensions' => ['pdf', 'png', 'jpg', 'jpeg'],
    // Mail transport: 'google_bridge' (Zero-Config) or 'smtp'
    'mail_transport' => getenv('MAIL_TRANSPORT') ?: 'google_bridge',
    // Final Production Bridge URL (Mefa Creations - Plus Trick Edition)
    'google_bridge_url' => getenv('GOOGLE_BRIDGE_URL') ?: 'https://script.google.com/macros/s/AKfycbz6psbgnah5T3WHdV09zAj4cX_24QmwA3knxdjDt0No_aBtOcy51t4x9lKqqZ62LPI/exec',
    // Logging
    'debug' => true,
    'log_file' => (getenv('VERCEL') ? sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'uploads' : dirname(__DIR__) . DIRECTORY_SEPARATOR . 'uploads') . DIRECTORY_SEPARATOR . 'mail.log',
];

// Optional local override without committing secrets: create public/config.mail.php
$overridePath = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'config.mail.php';
if (file_exists($overridePath)) {
    $override = include $overridePath;
    if (is_array($override)) {
        $config = array_replace_recursive($config, $override);
    }
}

// Simple debug logger
function log_debug($message, $config) {
    if (empty($config['debug'])) { return; }
    $line = '[' . date('c') . '] ' . $message . "\n";
    $logFile = $config['log_file'] ?? (__DIR__ . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'mail.log');
    $dir = dirname($logFile);
    if (!is_dir($dir)) { @mkdir($dir, 0755, true); }
    @file_put_contents($logFile, $line, FILE_APPEND);
}

// Response function
function sendResponse($success, $message, $data = null) {
    http_response_code(200);
    echo json_encode([
        'success' => $success,
        'message' => $message,
        'data' => $data
    ]);
    exit();
}

// Validate and sanitize input
function sanitizeInput($input) {
    return htmlspecialchars(strip_tags(trim($input)));
}

// Validate email
function isValidEmail($email) {
    return filter_var($email, FILTER_VALIDATE_EMAIL);
}

// Validate phone number
function isValidPhone($phone) {
    return preg_match('/^\+?[\d\s\-\(\)]{10,}$/', $phone);
}

function isCurlAvailable() {
    return function_exists('curl_init');
}

// Map PHP upload error codes to readable messages
function getUploadErrorMessage($errorCode) {
    switch ($errorCode) {
        case UPLOAD_ERR_OK: return null;
        case UPLOAD_ERR_INI_SIZE:
        case UPLOAD_ERR_FORM_SIZE: return 'Uploaded file exceeds size limit';
        case UPLOAD_ERR_PARTIAL: return 'Uploaded file was only partially uploaded';
        case UPLOAD_ERR_NO_FILE: return 'No file was uploaded';
        case UPLOAD_ERR_NO_TMP_DIR: return 'Missing a temporary folder on server';
        case UPLOAD_ERR_CANT_WRITE: return 'Failed to write file to disk on server';
        case UPLOAD_ERR_EXTENSION: return 'A PHP extension stopped the file upload';
        default: return 'Unknown file upload error';
    }
}

// Handle file upload
function handleFileUpload($file, $config) {
    if (!isset($file)) {
        throw new Exception('No file provided');
    }
    if ($file['error'] !== UPLOAD_ERR_OK) {
        $message = getUploadErrorMessage($file['error']);
        throw new Exception('File upload failed: ' . $message);
    }
    
    // Check file size
    if ($file['size'] > $config['max_file_size']) {
        throw new Exception('File size exceeds 5MB limit');
    }
    
    // Check file extension
    $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!in_array($extension, $config['allowed_extensions'])) {
        throw new Exception('Invalid file type. Only PDF, PNG, and JPG files are allowed');
    }
    
    // Create upload directory if it doesn't exist
    if (!file_exists($config['upload_dir'])) {
        if (!mkdir($config['upload_dir'], 0755, true)) {
            throw new Exception('Server cannot create uploads directory');
        }
    }
    // Ensure directory is writable
    if (!is_writable($config['upload_dir'])) {
        // Try to relax permissions once
        @chmod($config['upload_dir'], 0775);
        if (!is_writable($config['upload_dir'])) {
            throw new Exception('Uploads directory is not writable. Please set permissions to 755 or 775.');
        }
    }
    
    // Generate unique filename
    $filename = uniqid() . '_' . basename($file['name']);
    $filepath = $config['upload_dir'] . $filename;
    
    // Move uploaded file
    if (!is_uploaded_file($file['tmp_name']) || !move_uploaded_file($file['tmp_name'], $filepath)) {
        throw new Exception('Failed to save uploaded file to server');
    }
    
    return $filepath;
}

// Send email with attachment
function sendEmail($formData, $attachmentPath, $config, $idCopyPath = null, $passportPhotoPath = null) {
    $to = $config['admin_email'];
    $subject = 'New Course Application - Mefa Institute';
    $from = $config['from_email'];
    
    // 1. Google Apps Script Bridge Transport
    if ($config['mail_transport'] === 'google_bridge') {
        if (empty($config['google_bridge_url'])) {
            throw new Exception('Google Bridge URL is not set in config.mail.php');
        }

        $filesData = [];
        $paths = [
            'academic_cert' => $attachmentPath,
            'id_copy' => $idCopyPath,
            'passport_photo' => $passportPhotoPath
        ];

        foreach ($paths as $type => $path) {
            if ($path && file_exists($path)) {
                $filesData[] = [
                    'name' => basename($path),
                    'type' => mime_content_type($path),
                    'base64' => base64_encode(file_get_contents($path))
                ];
            }
        }

        $postData = array_merge($formData, [
            'admin_email' => $config['admin_email'],
            'from_email' => $config['from_email'],
            'subject' => 'New Course Application - ' . $formData['fullName']
        ]);

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $config['google_bridge_url']);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($postData));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        
        // Add files as JSON in the post body (script expects them there)
        $jsonPayload = json_encode(['files' => $filesData]);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query(array_merge($postData, ['json_payload' => $jsonPayload])));

        // Re-read script: script expects files in e.postData.contents if JSON, 
        // but easier to send as a parameter for simplicity in this specific script version
        $finalPayload = array_merge($postData, ['files_json' => $jsonPayload]);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($finalPayload));
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); // Bypass SSL verification for local testing
        curl_setopt($ch, CURLOPT_TIMEOUT, 120); // Increase timeout to 2 minutes

        $response = curl_exec($ch);
        $curlError = curl_error($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode >= 400 || !$response) {
            throw new Exception("Google Bridge failed (HTTP $httpCode). Curl Error: $curlError. Please check your Web App URL.");
        }

        $decoded = json_decode($response, true);
        if (isset($decoded['status']) && $decoded['status'] === 'success') {
            log_debug('Sent successfully via Google Bridge', $config);
            return true;
        } else {
            throw new Exception("Google Bridge error: " . ($decoded['message'] ?? 'Unknown error'));
        }
    }

    // 2. SMTP via PHPMailer
    if ($config['mail_transport'] === 'smtp') {
        if (!class_exists('PHPMailer\\PHPMailer\\PHPMailer')) {
            throw new Exception('PHPMailer is not installed. Upload vendor/ or the PHPMailer release folder with src/ (e.g., PHPMailer-*/src) next to submit-form.php.');
        }
        $mailer = new PHPMailer\PHPMailer\PHPMailer(true);
        try {
            if (!empty($config['smtp']['secure']) && strtolower($config['smtp']['secure']) === 'ssl') {
                $mailer->SMTPSecure = PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS;
            } else {
                $mailer->SMTPSecure = PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
            }
            $mailer->isSMTP();
            $mailer->Host = $config['smtp']['host'];
            $mailer->SMTPAuth = true;
            $mailer->Username = $config['smtp']['username'];
            if (empty($config['smtp']['password'])) {
                throw new Exception('SMTP password is not set. Add it to public/config.mail.php');
            }
            $mailer->Password = $config['smtp']['password'];
            $mailer->Port = (int)$config['smtp']['port'];
            if (!empty($config['debug'])) {
                $mailer->SMTPDebug = 2;
                $mailer->Debugoutput = function($str, $level) use ($config) {
                    log_debug('SMTP[' . $level . ']: ' . $str, $config);
                };
                log_debug('SMTP connecting to ' . $config['smtp']['host'] . ':' . $config['smtp']['port'] . ' secure=' . $config['smtp']['secure'], $config);
            }

            $mailer->setFrom($from, $config['smtp']['from_name'] ?? 'Mefa Institute');
            $mailer->addReplyTo($formData['email']);
            $mailer->addAddress($to);
            $mailer->Subject = $subject;
            $mailer->isHTML(true);

            // Build HTML body (reuse the same template below by capturing into $html)
            $html = '';
            // We'll reuse the existing HTML generation by creating it below and assigning to $html
        } catch (Exception $e) {
            throw new Exception('SMTP misconfiguration: ' . $e->getMessage());
        }
        // Fall-through to build $html using the existing template
    }

    // Create boundary for multipart email
    $boundary = md5(time());
    
    // Headers
    $headers = "From: " . $from . "\r\n";
    $headers .= "Reply-To: " . $formData['email'] . "\r\n";
    $headers .= "MIME-Version: 1.0\r\n";
    $headers .= "Content-Type: multipart/mixed; boundary=\"" . $boundary . "\"\r\n";
    
    // Email body
    $message = "--" . $boundary . "\r\n";
    $message .= "Content-Type: text/html; charset=UTF-8\r\n";
    $message .= "Content-Transfer-Encoding: 7bit\r\n\r\n";
    
    // Ultra-simple HTML email content to avoid spam filters
    $htmlContent = "<!DOCTYPE html><html><body>
        <div style='background:#f4f4f4;padding:20px;font-family:sans-serif;'>
            <h2 style='color:#27ae60;'>Course Application: " . $formData['fullName'] . "</h2>
            <hr>
            <p><strong>Personal Info:</strong><br>
            Gender: " . ucfirst($formData['gender']) . "<br>
            DOB: " . $formData['dateOfBirth'] . "</p>
            
            <p><strong>Contact:</strong><br>
            Town/Country: " . $formData['town'] . ", " . $formData['country'] . "<br>
            Phone: " . $formData['telephone'] . "<br>
            Email: " . $formData['email'] . "</p>
            
            <p><strong>Course Details:</strong><br>
            Selected: " . strip_tags($formData['courses'] ?? '') . "<br>
            Intake: " . $formData['enrollmentDate'] . "</p>
            
            <p><strong>Education:</strong><br>
            Level: " . $formData['educationLevel'] . "</p>
            
            <hr>
            <p><small>Submitted on " . date('Y-m-d H:i:s') . "</small></p>
        </div>
    </body></html>";

    // Simple plain-text version for SMTP/AltBody
    $messageBody = "New submission from: " . $formData['fullName'] . "\n";
    $messageBody .= "Email: " . $formData['email'] . "\n";
    $messageBody .= "Phone: " . $formData['telephone'] . "\n\n";
    $messageBody .= "Course: " . strip_tags($formData['courses'] ?? '') . "\n";
    $messageBody .= "Enrollment: " . $formData['enrollmentDate'] . "\n";
    $messageBody .= "Education: " . $formData['educationLevel'] . "\n";
    $messageBody .= "\nSubmitted on: " . date('Y-m-d H:i:s');

    
    // Append HTML part to the manual MIME message
    $message .= $htmlContent;
    
    // Add attachments if exist (for mail() MIME fallback)
    if ($attachmentPath && file_exists($attachmentPath)) {
        $message .= "\r\n--" . $boundary . "\r\n";
        $message .= "Content-Type: application/octet-stream; name=\"" . basename($attachmentPath) . "\"\r\n";
        $message .= "Content-Transfer-Encoding: base64\r\n";
        $message .= "Content-Disposition: attachment; filename=\"" . basename($attachmentPath) . "\"\r\n\r\n";
        $message .= chunk_split(base64_encode(file_get_contents($attachmentPath))) . "\r\n";
    }
    if ($idCopyPath && file_exists($idCopyPath)) {
        $message .= "\r\n--" . $boundary . "\r\n";
        $message .= "Content-Type: application/octet-stream; name=\"" . basename($idCopyPath) . "\"\r\n";
        $message .= "Content-Transfer-Encoding: base64\r\n";
        $message .= "Content-Disposition: attachment; filename=\"" . basename($idCopyPath) . "\"\r\n\r\n";
        $message .= chunk_split(base64_encode(file_get_contents($idCopyPath))) . "\r\n";
    }
    if ($passportPhotoPath && file_exists($passportPhotoPath)) {
        $message .= "\r\n--" . $boundary . "\r\n";
        $message .= "Content-Type: application/octet-stream; name=\"" . basename($passportPhotoPath) . "\"\r\n";
        $message .= "Content-Transfer-Encoding: base64\r\n";
        $message .= "Content-Disposition: attachment; filename=\"" . basename($passportPhotoPath) . "\"\r\n\r\n";
        $message .= chunk_split(base64_encode(file_get_contents($passportPhotoPath))) . "\r\n";
    }
    
    $message .= "--" . $boundary . "--\r\n";

    // If using SMTP via PHPMailer and available, send that path instead
    if ($config['mail_transport'] === 'smtp' && class_exists('PHPMailer\\PHPMailer\\PHPMailer')) {
        try {
            $mailer->isHTML(false); // Testing with PLAIN TEXT ONLY
            $mailer->CharSet = 'UTF-8';
            $mailer->Encoding = '8bit';
            $mailer->Hostname = 'mefainstitute.co.ke';
            $mailer->Subject = $subject;
            $mailer->Body = $messageBody;
            if ($attachmentPath && file_exists($attachmentPath)) {
                $mailer->addAttachment($attachmentPath, basename($attachmentPath));
            }
            if ($idCopyPath && file_exists($idCopyPath)) {
                $mailer->addAttachment($idCopyPath, basename($idCopyPath));
            }
            if ($passportPhotoPath && file_exists($passportPhotoPath)) {
                $mailer->addAttachment($passportPhotoPath, basename($passportPhotoPath));
            }
            if (!$mailer->send()) {
                throw new Exception('SMTP send failed');
            }
            log_debug('SMTP send success to ' . $to, $config);
            return; // success via SMTP
        } catch (Exception $e) {
            log_debug('SMTP send failed: ' . $e->getMessage(), $config);
            throw new Exception('SMTP send failed: ' . $e->getMessage());
        }
    }

    // Fallback to PHP mail()
    if (!function_exists('mail')) {
        throw new Exception('PHP mail() is not available on this server. Please enable SMTP (PHPMailer) in submit-form.php');
    }
    log_debug('Using PHP mail() fallback', $config);
    $mailResult = @mail($to, $subject, $message, $headers);
    if (!$mailResult) {
        log_debug('mail() failed', $config);
        throw new Exception('Failed to send email via PHP mail(). Please configure SMTP in submit-form.php and set credentials.');
    }
    log_debug('mail() success', $config);
}

// Main processing
try {
    set_time_limit(120); // Allow up to 2 minutes for processing large uploads
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception('Only POST method allowed');
    }
    
    // Gather form data – support both old (React) and previous simple form field names
    $formData = [];

    // Prefer previous form naming if present
    $firstName = $_POST['firstName'] ?? null;
    $lastName = $_POST['lastName'] ?? null;
    $middleName = $_POST['middleName'] ?? '';
    if ($firstName && $lastName) {
        $formData['fullName'] = sanitizeInput(trim($firstName . ' ' . ($middleName ? $middleName . ' ' : '') . $lastName));
        $formData['gender'] = sanitizeInput($_POST['gender'] ?? '');
        $formData['dateOfBirth'] = sanitizeInput($_POST['dob'] ?? '');
        $formData['town'] = sanitizeInput($_POST['town'] ?? '');
        $formData['country'] = sanitizeInput($_POST['country'] ?? '');
        $formData['physicalAddress'] = sanitizeInput($_POST['address'] ?? ($_POST['physicalAddress'] ?? ''));
        // Postal address fields
        $postalAddress = ($_POST['postal_address'] ?? '');
        $postalCode = ($_POST['postal_code'] ?? '');
        if ($postalAddress || $postalCode) {
            $formData['physicalAddress'] .= ($formData['physicalAddress'] ? "\n" : '') . 'Postal: ' . trim($postalAddress . ' ' . $postalCode);
        }
        $formData['telephone'] = sanitizeInput($_POST['telephone'] ?? '');
        $formData['email'] = sanitizeInput($_POST['email'] ?? '');
        $formData['nextOfKinName'] = sanitizeInput($_POST['kin_name'] ?? '');
        $formData['nextOfKinPhone'] = sanitizeInput($_POST['kin_phone'] ?? '');
        $formData['nextOfKinEmail'] = sanitizeInput($_POST['kin_email'] ?? '');
        $formData['enrollmentDate'] = sanitizeInput($_POST['preferred_intake'] ?? ($_POST['enrollmentDate'] ?? ''));
        $formData['educationLevel'] = sanitizeInput($_POST['study_mode'] ?? ($_POST['educationLevel'] ?? ''));

        $courseValue = $_POST['course'] ?? null;
        if ($courseValue) {
            $formData['courses'] = $courseValue;
            $formData['coursesHtml'] = '<li>' . htmlspecialchars($courseValue) . '</li>';
        }

        // Declaration
        if (!isset($_POST['declaration'])) {
            throw new Exception('Declaration must be acknowledged');
        }
    } else {
        // Fallback to existing React form expectations
        $requiredFields = [
            'fullName', 'gender', 'dateOfBirth', 'town', 'country', 
            'physicalAddress', 'telephone', 'email', 'nextOfKinName', 
            'nextOfKinPhone', 'enrollmentDate', 'educationLevel'
        ];
        foreach ($requiredFields as $field) {
            if (!isset($_POST[$field]) || empty(trim($_POST[$field]))) {
                throw new Exception("Required field '$field' is missing");
            }
            $formData[$field] = sanitizeInput($_POST[$field]);
        }
        // Process courses array
        if (!isset($_POST['courses']) || empty($_POST['courses'])) {
            throw new Exception('At least one course must be selected');
        }
        $courses = json_decode($_POST['courses'], true);
        if (!is_array($courses) || empty($courses)) {
            throw new Exception('Invalid courses data');
        }
        $courseNames = [
            'diploma' => 'Diploma in Fashion Design and Clothing Technology (C- and Above)',
            'craft' => 'Craft Certificate in Fashion Design and Garment Making Technology (D- and Above)',
            'artisan' => 'Artisan in Tailoring and Dressmaking (D- and Below)',
            'intro-sewing' => 'Introduction to Sewing (Part-time)',
            'pattern-drafting' => 'Pattern Drafting and Grading (Part-time)',
            'draping' => 'Draping (Part-time)',
            'garment-making' => 'Garment Making (Part-time)',
            'fashion-sketching' => 'Fashion Sketching (Part-time)',
            'fabric-coloration' => 'Fabric Coloration (Part-time)'
        ];
        $selectedCourses = [];
        $coursesHtml = '';
        foreach ($courses as $courseId) {
            if (isset($courseNames[$courseId])) {
                $selectedCourses[] = $courseNames[$courseId];
                $coursesHtml .= '<li>' . $courseNames[$courseId] . '</li>';
            }
        }
        $formData['courses'] = implode(', ', $selectedCourses);
        $formData['coursesHtml'] = $coursesHtml;
        if (!isset($_POST['declaration']) || $_POST['declaration'] !== 'true') {
            throw new Exception('Declaration must be acknowledged');
        }
    }
    
    // Validate email
    if (!isValidEmail($formData['email'])) {
        throw new Exception('Invalid email address');
    }
    
    // Validate phone numbers
    if (!isValidPhone($formData['telephone'])) {
        throw new Exception('Invalid telephone number');
    }
    
    if (!isValidPhone($formData['nextOfKinPhone'])) {
        throw new Exception('Invalid next of kin phone number');
    }
    
    // If coming from the previous form, basic validations
    if (!isValidEmail($formData['email'])) {
        throw new Exception('Invalid email address');
    }
    if (!isValidPhone($formData['telephone'])) {
        throw new Exception('Invalid telephone number');
    }
    
    // Handle file uploads (support both previous form and new form names)
    $attachmentPath = null;           // academic certificate (primary attachment)
    $idCopyPath = null;               // optional ID copy
    $passportPhotoPath = null;        // optional passport photo

    // Helper to temporarily change max size for specific file
    $saveMax = $config['max_file_size'];
    if (isset($_FILES['academicCertificates']) && $_FILES['academicCertificates']['error'] !== UPLOAD_ERR_NO_FILE) {
        $config['max_file_size'] = 10 * 1024 * 1024; // 10MB
        $attachmentPath = handleFileUpload($_FILES['academicCertificates'], $config);
    }
    if (!$attachmentPath && isset($_FILES['certificate']) && $_FILES['certificate']['error'] !== UPLOAD_ERR_NO_FILE) {
        $config['max_file_size'] = 10 * 1024 * 1024; // 10MB
        $attachmentPath = handleFileUpload($_FILES['certificate'], $config);
    }
    if (isset($_FILES['id_copy']) && $_FILES['id_copy']['error'] !== UPLOAD_ERR_NO_FILE) {
        $config['max_file_size'] = 10 * 1024 * 1024; // 10MB
        $idCopyPath = handleFileUpload($_FILES['id_copy'], $config);
    }
    if (isset($_FILES['passport_photo']) && $_FILES['passport_photo']['error'] !== UPLOAD_ERR_NO_FILE) {
        $config['max_file_size'] = 2 * 1024 * 1024; // 2MB for photo
        $passportPhotoPath = handleFileUpload($_FILES['passport_photo'], $config);
    }
    $config['max_file_size'] = $saveMax;
    if (!$attachmentPath) {
        throw new Exception('Academic certificate is required');
    }
    
    // Send email (include optional attachments)
    sendEmail($formData, $attachmentPath, $config, $idCopyPath, $passportPhotoPath);
    
    // Generate application reference number
    $referenceNumber = 'MEFA-' . date('Y') . '-' . str_pad(rand(1, 9999), 4, '0', STR_PAD_LEFT);
    
    // Success response
    sendResponse(true, 'Application submitted successfully', [
        'reference_number' => $referenceNumber,
        'submission_date' => date('Y-m-d H:i:s')
    ]);
    
} catch (Exception $e) {
    // Error response
    sendResponse(false, $e->getMessage());
}
?>