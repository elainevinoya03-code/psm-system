<?php
require_once __DIR__ . '/vendor/autoload.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception;

$mail = new PHPMailer(true);

try {
    // Enable verbose debug output
    $mail->SMTPDebug   = SMTP::DEBUG_SERVER;
    $mail->Debugoutput = 'echo';

    // SMTP Settings
    $mail->isSMTP();
    $mail->Host       = 'smtp.gmail.com';
    $mail->SMTPAuth   = true;
    $mail->Username   = 'noreply.microfinancial@gmail.com';
    $mail->Password   = 'dpjdwwlopkzdyfnk';
    $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
    $mail->Port       = 587;

    // Recipients
    $mail->setFrom('noreply.microfinancial@gmail.com', 'MicroFinancial');
    $mail->addAddress('kimt06065@gmail.com');

    // Content
    $mail->isHTML(true);
    $mail->Subject = 'PHPMailer Test — MicroFinancial';
    $mail->Body    = '<h2>✅ Test email working!</h2><p>PHPMailer is configured correctly.</p>';
    $mail->AltBody = 'PHPMailer is configured correctly.';

    $mail->send();
    echo '<br><strong style="color:green">✅ Email sent successfully!</strong>';

} catch (Exception $e) {
    echo '<br><strong style="color:red">❌ Email failed: ' . $mail->ErrorInfo . '</strong>';
}