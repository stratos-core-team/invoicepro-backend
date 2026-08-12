<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/env.php';

final class TwoFactorService
{
    private int $digits = 6;
    private int $period = 30;

    /*
    |--------------------------------------------------------------------------
    | Generate TOTP Secret
    |--------------------------------------------------------------------------
    */

    public function generateSecret(
        int $length = 20
    ): string {

        if ($length < 10) {
            throw new InvalidArgumentException(
                'TOTP secret length is too short.'
            );
        }

        return $this->base32Encode(
            random_bytes($length)
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Generate OTP Auth URI
    |--------------------------------------------------------------------------
    |
    | Hii ndiyo URI ambayo QR code itawakilisha.
    |--------------------------------------------------------------------------
    */

    public function getOtpAuthUri(
        string $secret,
        string $email,
        string $issuer = 'InvoicePro NG'
    ): string {

        $secret = strtoupper(
            trim($secret)
        );

        if ($secret === '') {
            throw new InvalidArgumentException(
                'TOTP secret is required.'
            );
        }

        if (!filter_var(
            $email,
            FILTER_VALIDATE_EMAIL
        )) {
            throw new InvalidArgumentException(
                'Valid account email is required.'
            );
        }

        $label =
            rawurlencode($issuer)
            . ':'
            . rawurlencode($email);

        return sprintf(
            'otpauth://totp/%s?secret=%s&issuer=%s&algorithm=SHA1&digits=%d&period=%d',
            $label,
            rawurlencode($secret),
            rawurlencode($issuer),
            $this->digits,
            $this->period
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Verify TOTP Code
    |--------------------------------------------------------------------------
    |
    | window=1 means:
    | previous 30 sec
    | current 30 sec
    | next 30 sec
    |--------------------------------------------------------------------------
    */

    public function verifyCode(
        string $secret,
        string $code,
        int $window = 1
    ): bool {

        $code = preg_replace(
            '/\D/',
            '',
            $code
        ) ?? '';

        if (strlen($code) !== $this->digits) {
            return false;
        }

        $secret = strtoupper(
            trim($secret)
        );

        if ($secret === '') {
            return false;
        }

        $counter = (int) floor(
            time() / $this->period
        );

        for (
            $offset = -$window;
            $offset <= $window;
            $offset++
        ) {

            $expected = $this->generateCode(
                $secret,
                $counter + $offset
            );

            if (
                hash_equals(
                    $expected,
                    $code
                )
            ) {
                return true;
            }
        }

        return false;
    }

    /*
    |--------------------------------------------------------------------------
    | Generate Recovery Codes
    |--------------------------------------------------------------------------
    */

    public function generateRecoveryCodes(
        int $count = 8
    ): array {

        if ($count < 4 || $count > 20) {
            throw new InvalidArgumentException(
                'Recovery code count must be between 4 and 20.'
            );
        }

        $codes = [];

        for ($i = 0; $i < $count; $i++) {

            $code =
                strtoupper(
                    bin2hex(
                        random_bytes(4)
                    )
                );

            $codes[] =
                substr($code, 0, 4)
                . '-'
                . substr($code, 4, 4);
        }

        return $codes;
    }

    /*
    |--------------------------------------------------------------------------
    | Hash Recovery Code
    |--------------------------------------------------------------------------
    */

    public function hashRecoveryCode(
        string $code
    ): string {

        $normalized =
            $this->normalizeRecoveryCode(
                $code
            );

        return hash(
            'sha256',
            $normalized
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Verify Recovery Code
    |--------------------------------------------------------------------------
    */

    public function verifyRecoveryCode(
        string $plainCode,
        string $storedHash
    ): bool {

        $plainHash =
            $this->hashRecoveryCode(
                $plainCode
            );

        return hash_equals(
            $storedHash,
            $plainHash
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Encrypt Secret
    |--------------------------------------------------------------------------
    |
    | two_factor_secret should not be stored as plain text.
    |
    | Requires:
    |
    | TWO_FACTOR_ENCRYPTION_KEY
    |--------------------------------------------------------------------------
    */

    public function encryptSecret(
        string $secret
    ): string {

        $key =
            $this->encryptionKey();

        $cipher =
            'aes-256-gcm';

        $ivLength =
            openssl_cipher_iv_length(
                $cipher
            );

        $iv =
            random_bytes(
                $ivLength
            );

        $tag = '';

        $encrypted =
            openssl_encrypt(
                $secret,
                $cipher,
                $key,
                OPENSSL_RAW_DATA,
                $iv,
                $tag
            );

        if ($encrypted === false) {
            throw new RuntimeException(
                'Could not encrypt TOTP secret.'
            );
        }

        return base64_encode(
            $iv
            . $tag
            . $encrypted
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Decrypt Secret
    |--------------------------------------------------------------------------
    */

    public function decryptSecret(
        string $encryptedValue
    ): string {

        $key =
            $this->encryptionKey();

        $decoded =
            base64_decode(
                $encryptedValue,
                true
            );

        if ($decoded === false) {
            throw new RuntimeException(
                'Invalid encrypted TOTP secret.'
            );
        }

        $cipher =
            'aes-256-gcm';

        $ivLength =
            openssl_cipher_iv_length(
                $cipher
            );

        $tagLength = 16;

        if (
            strlen($decoded)
            <= ($ivLength + $tagLength)
        ) {
            throw new RuntimeException(
                'Invalid encrypted TOTP secret.'
            );
        }

        $iv =
            substr(
                $decoded,
                0,
                $ivLength
            );

        $tag =
            substr(
                $decoded,
                $ivLength,
                $tagLength
            );

        $cipherText =
            substr(
                $decoded,
                $ivLength + $tagLength
            );

        $decrypted =
            openssl_decrypt(
                $cipherText,
                $cipher,
                $key,
                OPENSSL_RAW_DATA,
                $iv,
                $tag
            );

        if ($decrypted === false) {
            throw new RuntimeException(
                'Could not decrypt TOTP secret.'
            );
        }

        return $decrypted;
    }

    /*
    |--------------------------------------------------------------------------
    | Generate TOTP
    |--------------------------------------------------------------------------
    */

    private function generateCode(
        string $base32Secret,
        int $counter
    ): string {

        $secret =
            $this->base32Decode(
                $base32Secret
            );

        /*
        |--------------------------------------------------------------------------
        | Convert counter to 8-byte big endian
        |--------------------------------------------------------------------------
        */

        $binaryCounter =
            pack(
                'N2',
                ($counter >> 32) & 0xFFFFFFFF,
                $counter & 0xFFFFFFFF
            );

        $hash =
            hash_hmac(
                'sha1',
                $binaryCounter,
                $secret,
                true
            );

        $offset =
            ord(
                $hash[
                    strlen($hash) - 1
                ]
            ) & 0x0F;

        $binary =
            (
                (ord($hash[$offset]) & 0x7F)
                << 24
            )
            |
            (
                (ord($hash[$offset + 1]) & 0xFF)
                << 16
            )
            |
            (
                (ord($hash[$offset + 2]) & 0xFF)
                << 8
            )
            |
            (
                ord(
                    $hash[$offset + 3]
                ) & 0xFF
            );

        $otp =
            $binary
            % (10 ** $this->digits);

        return str_pad(
            (string)$otp,
            $this->digits,
            '0',
            STR_PAD_LEFT
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Base32 Encode
    |--------------------------------------------------------------------------
    */

    private function base32Encode(
        string $data
    ): string {

        $alphabet =
            'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

        $binary = '';

        foreach (
            str_split($data)
            as $char
        ) {

            $binary .=
                str_pad(
                    decbin(ord($char)),
                    8,
                    '0',
                    STR_PAD_LEFT
                );
        }

        $encoded = '';

        foreach (
            str_split($binary, 5)
            as $chunk
        ) {

            if (strlen($chunk) < 5) {
                $chunk =
                    str_pad(
                        $chunk,
                        5,
                        '0',
                        STR_PAD_RIGHT
                    );
            }

            $encoded .=
                $alphabet[
                    bindec($chunk)
                ];
        }

        return $encoded;
    }

    /*
    |--------------------------------------------------------------------------
    | Base32 Decode
    |--------------------------------------------------------------------------
    */

    private function base32Decode(
        string $encoded
    ): string {

        $alphabet =
            'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

        $encoded =
            strtoupper(
                preg_replace(
                    '/[^A-Z2-7]/',
                    '',
                    $encoded
                ) ?? ''
            );

        if ($encoded === '') {
            throw new InvalidArgumentException(
                'Invalid Base32 secret.'
            );
        }

        $binary = '';

        foreach (
            str_split($encoded)
            as $char
        ) {

            $position =
                strpos(
                    $alphabet,
                    $char
                );

            if ($position === false) {
                throw new InvalidArgumentException(
                    'Invalid Base32 secret.'
                );
            }

            $binary .=
                str_pad(
                    decbin($position),
                    5,
                    '0',
                    STR_PAD_LEFT
                );
        }

        $decoded = '';

        foreach (
            str_split($binary, 8)
            as $byte
        ) {

            if (strlen($byte) < 8) {
                continue;
            }

            $decoded .=
                chr(
                    bindec($byte)
                );
        }

        return $decoded;
    }

    /*
    |--------------------------------------------------------------------------
    | Normalize Recovery Code
    |--------------------------------------------------------------------------
    */

    private function normalizeRecoveryCode(
        string $code
    ): string {

        return strtoupper(
            preg_replace(
                '/[^A-Z0-9]/i',
                '',
                $code
            ) ?? ''
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Encryption Key
    |--------------------------------------------------------------------------
    */

    private function encryptionKey(): string
    {
        $key =
            (string) env(
                'TWO_FACTOR_ENCRYPTION_KEY',
                ''
            );

        if ($key === '') {
            throw new RuntimeException(
                'TWO_FACTOR_ENCRYPTION_KEY is not configured.'
            );
        }

        /*
        |--------------------------------------------------------------------------
        | Convert configured key to fixed 32-byte key.
        |--------------------------------------------------------------------------
        */

        return hash(
            'sha256',
            $key,
            true
        );
    }
}
