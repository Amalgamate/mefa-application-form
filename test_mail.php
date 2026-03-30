<?php
// Standalone script to test SMTP connection without the full form
require_once __DIR__ . '/phpmailer/src/PHPMailer.php';
require_once __DIR__ . '/phpmailer/src/SMTP.php';
require_once __DIR__ . '/phpmailer/src/Exception.php';

$config = include __DIR__ . '/config.mail.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

$mail = new PHPMailer(true);

try {
    // Server settings
    $mail->SMTPDebug = 2; // Enable verbose debug output
    $mail->isSMTP();
    $mail->Host       = $config['smtp']['host'];
    $mail->SMTPAuth   = true;
    $mail->Username   = $config['smtp']['username'];
    $mail->Password   = $config['smtp']['password'];
    $mail->SMTPSecure = ($config['smtp']['secure'] === 'ssl') ? PHPMailer::ENCRYPTION_SMTPS : PHPMailer::ENCRYPTION_STARTTLS;
    $mail->Port       = $config['smtp']['port'];

    // Recipients
    $mail->setFrom($config['from_email'], 'Mefa Test');
    $mail->addAddress($config['admin_email']);

    // Content
    $mail->isHTML(true);
    $mail->Subject = 'Local SMTP Connectivity Test';
    $mail->Body    = 'This is a minimal test email sent from the local machine to verify SMTP connectivity and exclude attachment-related spam triggers.';
    $mail->AltBody = 'Minimal test email.';

    echo "Attempting to send minimal test email...\n";
    $mail->send();
    echo "SUCCESS: Message has been sent!\n";
} catch (Exception $e) {
    echo "ERROR: Message could not be sent. Mailer Error: {$mail->ErrorInfo}\n";
}
