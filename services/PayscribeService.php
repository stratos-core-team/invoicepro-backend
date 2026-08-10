<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/env.php';

final class PayscribeService
{
    private string $baseUrl;
    private string $apiKey;
    private string $webhookSecret;

    public function __construct()
    {
        $this->baseUrl = rtrim(
            (string) env('PAYSCRIBE_BASE_URL', ''),
            '/'
        );

        $this->apiKey = (string) env(
            'PAYSCRIBE_API_KEY',
            ''
        );

        $this->webhookSecret = (string) env(
            'PAYSCRIBE_WEBHOOK_SECRET',
            ''
        );

        if ($this->baseUrl === '') {
            throw new RuntimeException(
                'PAYSCRIBE_BASE_URL is not configured.'
            );
        }

        if ($this->apiKey === '') {
            throw new RuntimeException(
                'PAYSCRIBE_API_KEY is not configured.'
            );
        }
    }

    /**
     * Generic Payscribe API request.
     *
     * Endpoint paths and authentication headers must be aligned
     * with the official Payscribe API documentation before production use.
     */
    private function request(
        string $method,
        string $endpoint,
        array $payload = []
    ): array {

        $url = $this->baseUrl
            . '/'
            . ltrim($endpoint, '/');

        $ch = curl_init($url);

        if ($ch === false) {
            throw new RuntimeException(
                'Could not initialize HTTP client.'
            );
        }

        $headers = [
            'Accept: application/json',
            'Content-Type: application/json',

            /*
             * IMPORTANT:
             * Confirm the exact authentication header from
             * official Payscribe API documentation.
             */
            'Authorization: Bearer ' . $this->apiKey
        ];

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_CUSTOMREQUEST => strtoupper($method),
            CURLOPT_HTTPHEADER => $headers
        ]);

        if (
            strtoupper($method) !== 'GET'
            && $payload !== []
        ) {
            curl_setopt(
                $ch,
                CURLOPT_POSTFIELDS,
                json_encode(
                    $payload,
                    JSON_UNESCAPED_SLASHES
                )
            );
        }

        $response = curl_exec($ch);

        if ($response === false) {

            $error = curl_error($ch);

            curl_close($ch);

            throw new RuntimeException(
                'Payscribe request failed: '
                . $error
            );
        }

        $statusCode = (int) curl_getinfo(
            $ch,
            CURLINFO_HTTP_CODE
        );

        curl_close($ch);

        $data = json_decode(
            $response,
            true
        );

        if (!is_array($data)) {
            throw new RuntimeException(
                'Payscribe returned invalid JSON.'
            );
        }

        if (
            $statusCode < 200
            || $statusCode >= 300
        ) {

            $message =
                $data['message']
                ?? 'Payscribe request failed.';

            throw new RuntimeException(
                $message
            );
        }

        return $data;
    }

    /**
     * Initialize invoice payment.
     *
     * We keep this generic until the official Payscribe endpoint
     * and payload structure are confirmed.
     */
    public function initializePayment(
        array $paymentData
    ): array {

        /*
         * TODO:
         *
         * Replace this endpoint with the exact official
         * Payscribe payment initialization endpoint.
         */

        $endpoint = env(
            'PAYSCRIBE_PAYMENT_ENDPOINT',
            ''
        );

        if ($endpoint === '') {
            throw new RuntimeException(
                'PAYSCRIBE_PAYMENT_ENDPOINT is not configured.'
            );
        }

        return $this->request(
            'POST',
            $endpoint,
            $paymentData
        );
    }

    /**
     * Verify transaction/payment.
     */
    public function verifyPayment(
        string $reference
    ): array {

        if ($reference === '') {
            throw new InvalidArgumentException(
                'Payment reference is required.'
            );
        }

        /*
         * Example configuration:
         *
         * PAYSCRIBE_VERIFY_ENDPOINT=/payments/verify/{reference}
         *
         * But do not assume that URL until confirmed from
         * official Payscribe documentation.
         */

        $endpointTemplate = env(
            'PAYSCRIBE_VERIFY_ENDPOINT',
            ''
        );

        if ($endpointTemplate === '') {
            throw new RuntimeException(
                'PAYSCRIBE_VERIFY_ENDPOINT is not configured.'
            );
        }

        $endpoint = str_replace(
            '{reference}',
            rawurlencode($reference),
            $endpointTemplate
        );

        return $this->request(
            'GET',
            $endpoint
        );
    }

    /**
     * Verify webhook signature.
     *
     * This deliberately returns false until the exact signature
     * algorithm/header from Payscribe is configured.
     */
    public function verifyWebhookSignature(
        string $rawPayload,
        ?string $signature
    ): bool {

        if (
            $this->webhookSecret === ''
            || !$signature
        ) {
            return false;
        }

        /*
         * IMPORTANT:
         *
         * Do NOT assume HMAC SHA256 unless official Payscribe
         * documentation explicitly confirms it.
         *
         * Once confirmed, implement the provider's exact algorithm here.
         */

        return false;
    }
}
