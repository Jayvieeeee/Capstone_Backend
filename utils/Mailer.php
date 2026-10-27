<?php

use PHPMailer\PHPMailer\Exception;
use PHPMailer\PHPMailer\PHPMailer;

require_once __DIR__ . '/../libs/src/Exception.php';
require_once __DIR__ . '/../libs/src/PHPMailer.php';
require_once __DIR__ . '/../libs/src/SMTP.php';

class Mailer {
    // Returns true if the email was handed to the SMTP server, false otherwise.
    public static function sendResetCode($toEmail, $toName, $code, $ttlMinutes) {
        $config = require __DIR__ . '/../config/mail.php';

        $mail = new PHPMailer(true);

        try {
            $mail->isSMTP();
            $mail->Host = $config['host'];
            $mail->SMTPAuth = true;
            $mail->Username = $config['username'];
            $mail->Password = $config['password'];
            $mail->SMTPSecure = $config['encryption'] === 'ssl'
                ? PHPMailer::ENCRYPTION_SMTPS
                : PHPMailer::ENCRYPTION_STARTTLS;
            $mail->Port = (int) $config['port'];
            $mail->Timeout = 10;
            $mail->CharSet = 'UTF-8';

            $mail->setFrom($config['from_email'], $config['from_name']);
            $mail->addAddress($toEmail, $toName);

            $safeName = htmlspecialchars($toName, ENT_QUOTES, 'UTF-8');

            $mail->isHTML(true);
            $mail->Subject = 'Your password reset code';
            $mail->Body = "<p>Hi {$safeName},</p>"
                . "<p>Your password reset code is:</p>"
                . "<p style=\"font-size:28px;font-weight:bold;letter-spacing:6px;\">{$code}</p>"
                . "<p>This code expires in {$ttlMinutes} minutes. "
                . "If you didn't request it, you can ignore this email.</p>";
            $mail->AltBody = "Hi {$toName},\n\nYour password reset code is: {$code}\n\n"
                . "This code expires in {$ttlMinutes} minutes. "
                . "If you didn't request it, you can ignore this email.";

            $mail->send();
            return true;
        } catch (Exception $e) {
            // ErrorInfo explains the failure (bad login, blocked port, etc.). Never log the password.
            error_log('Reset email failed: ' . $mail->ErrorInfo);
            return false;
        }
    }
}