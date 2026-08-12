<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/env.php';

final class MailService
{
    private string $fromEmail;
    private string $fromName;

    public function __construct()
    {
        $this->fromEmail = (string) env(
            'MAIL_FROM_ADDRESS',
            ''
        );

        $this->fromName = (string) env(
            'MAIL_FROM_NAME',
            'InvoicePro NG'
        );

        if ($this->fromEmail === '') {
            throw new RuntimeException(
                'MAIL_FROM_ADDRESS is not configured.'
            );
        }
    }

    /**
     * Send a basic HTML email.
     *
     * NOTE:
     * This currently uses PHP mail() as a fallback.
     * For production, switch this method to PHPMailer SMTP
     * or another transactional provider.
     */
    public function send(
        string $to,
        string $subject,
        string $html,
        ?string $text = null
    ): bool {

        $to = trim($to);

        if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
            throw new InvalidArgumentException(
                'Invalid recipient email.'
            );
        }

        $subject = trim($subject);

        if ($subject === '') {
            throw new InvalidArgumentException(
                'Email subject is required.'
            );
        }

        $headers = [];

        $headers[] =
            'MIME-Version: 1.0';

        $headers[] =
            'Content-Type: text/html; charset=UTF-8';

        $headers[] =
            'From: '
            . $this->formatFromHeader();

        $headers[] =
            'Reply-To: '
            . $this->fromEmail;

        return mail(
            $to,
            $subject,
            $html,
            implode("\r\n", $headers)
        );
    }

    /**
     * Email verification email
     */
    public function sendVerificationEmail(
        string $email,
        string $name,
        string $token
    ): bool {

        $frontendUrl = rtrim(
            (string) env(
                'FRONTEND_URL',
                ''
            ),
            '/'
        );

        if ($frontendUrl === '') {
            throw new RuntimeException(
                'FRONTEND_URL is not configured.'
            );
        }

        $verificationUrl =
            $frontendUrl
            . '/verify-email?token='
            . rawurlencode($token);

        $safeName =
            htmlspecialchars(
                $name,
                ENT_QUOTES,
                'UTF-8'
            );

        $safeUrl =
            htmlspecialchars(
                $verificationUrl,
                ENT_QUOTES,
                'UTF-8'
            );

        $subject =
            'Verify your InvoicePro NG email';

        $html = "
        <div style=\"font-family:Arial,sans-serif;max-width:600px;margin:auto\">
            <h2>Welcome to InvoicePro NG</h2>

            <p>Hello {$safeName},</p>

            <p>
                Please verify your email address to complete
                your InvoicePro NG account setup.
            </p>

            <p style=\"margin:24px 0\">
                <a
                    href=\"{$safeUrl}\"
                    style=\"
                        display:inline-block;
                        padding:12px 20px;
                        background:#111827;
                        color:#ffffff;
                        text-decoration:none;
                        border-radius:6px;
                    \"
                >
                    Verify Email
                </a>
            </p>

            <p>
                This verification link expires in 24 hours.
            </p>

            <p>
                If you did not create this account,
                you can ignore this email.
            </p>

            <hr>

            <p style=\"font-size:12px;color:#6b7280\">
                InvoicePro NG
            </p>
        </div>
        ";

        return $this->send(
            $email,
            $subject,
            $html
        );
    }

    /**
     * Password reset email
     */
    public function sendPasswordResetEmail(
        string $email,
        string $name,
        string $token
    ): bool {

        $frontendUrl = rtrim(
            (string) env(
                'FRONTEND_URL',
                ''
            ),
            '/'
        );

        if ($frontendUrl === '') {
            throw new RuntimeException(
                'FRONTEND_URL is not configured.'
            );
        }

        $resetUrl =
            $frontendUrl
            . '/reset-password?token='
            . rawurlencode($token);

        $safeName =
            htmlspecialchars(
                $name,
                ENT_QUOTES,
                'UTF-8'
            );

        $safeUrl =
            htmlspecialchars(
                $resetUrl,
                ENT_QUOTES,
                'UTF-8'
            );

        $subject =
            'Reset your InvoicePro NG password';

        $html = "
        <div style=\"font-family:Arial,sans-serif;max-width:600px;margin:auto\">
            <h2>Password Reset</h2>

            <p>Hello {$safeName},</p>

            <p>
                We received a request to reset your password.
            </p>

            <p style=\"margin:24px 0\">
                <a
                    href=\"{$safeUrl}\"
                    style=\"
                        display:inline-block;
                        padding:12px 20px;
                        background:#111827;
                        color:#ffffff;
                        text-decoration:none;
                        border-radius:6px;
                    \"
                >
                    Reset Password
                </a>
            </p>

            <p>
                This reset link should expire after a short period.
            </p>

            <p>
                If you did not request a password reset,
                you can ignore this email.
            </p>
        </div>
        ";

        return $this->send(
            $email,
            $subject,
            $html
        );
    }

    /**
     * Invoice email
     */
    public function sendInvoiceEmail(
        string $email,
        string $customerName,
        string $invoiceNumber,
        string $invoiceUrl
    ): bool {

        $safeName =
            htmlspecialchars(
                $customerName,
                ENT_QUOTES,
                'UTF-8'
            );

        $safeInvoice =
            htmlspecialchars(
                $invoiceNumber,
                ENT_QUOTES,
                'UTF-8'
            );

        $safeUrl =
            htmlspecialchars(
                $invoiceUrl,
                ENT_QUOTES,
                'UTF-8'
            );

        $subject =
            "Invoice {$invoiceNumber}";

        $html = "
        <div style=\"font-family:Arial,sans-serif;max-width:600px;margin:auto\">
            <h2>Invoice {$safeInvoice}</h2>

            <p>Hello {$safeName},</p>

            <p>
                A new invoice has been issued to you.
            </p>

            <p style=\"margin:24px 0\">
                <a
                    href=\"{$safeUrl}\"
                    style=\"
                        display:inline-block;
                        padding:12px 20px;
                        background:#111827;
                        color:#ffffff;
                        text-decoration:none;
                        border-radius:6px;
                    \"
                >
                    View Invoice
                </a>
            </p>

            <p>
                Thank you.
            </p>
        </div>
        ";

        return $this->send(
            $email,
            $subject,
            $html
        );
    }

    private function formatFromHeader(): string
    {
        $safeName = str_replace(
            ['"', "\r", "\n"],
            '',
            $this->fromName
        );

        return sprintf(
            '"%s" <%s>',
            $safeName,
            $this->fromEmail
        );
    }
}
