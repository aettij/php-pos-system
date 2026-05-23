<?php

declare(strict_types=1);

class StripeService
{
    private static function secretKey(): string
    {
        return getenv('STRIPE_SECRET_KEY') ?: '';
    }

    public static function publishableKey(): string
    {
        return getenv('STRIPE_PUBLISHABLE_KEY') ?: '';
    }

    public static function isConfigured(): bool
    {
        return self::secretKey() !== '' && self::publishableKey() !== '';
    }

    /**
     * Create a Stripe PaymentIntent via the REST API.
     * Falls back to a simulated intent if Stripe is not configured.
     */
    public static function createPaymentIntent(float $amount, string $currency = 'mad', string $description = ''): array
    {
        if (!self::isConfigured()) {
            return self::simulateIntent($amount, $currency, $description);
        }

        $data = [
            'amount'      => (int)round($amount * 100), // cents
            'currency'    => strtolower($currency),
            'description' => $description,
            'capture_method' => 'manual',
            'automatic_payment_methods' => ['enabled' => true],
        ];

        $response = self::apiRequest('payment_intents', $data);
        return [
            'id'            => $response['id'],
            'client_secret' => $response['client_secret'],
            'amount'        => $response['amount'] / 100,
            'status'        => $response['status'],
            'simulated'     => false,
        ];
    }

    /**
     * Capture (confirm) a PaymentIntent.
     */
    public static function capturePaymentIntent(string $intentId): array
    {
        if (!self::isConfigured()) {
            return ['id' => $intentId, 'status' => 'succeeded', 'simulated' => true];
        }

        $response = self::apiRequest("payment_intents/{$intentId}/capture", [], 'POST');
        return [
            'id'     => $response['id'],
            'status' => $response['status'],
            'simulated' => false,
        ];
    }

    /**
     * Generate a mobile payment link (simulated).
     */
    public static function createMobilePaymentLink(float $amount, string $description = ''): array
    {
        return [
            'url'         => '#',
            'reference'   => 'MOB-' . strtoupper(bin2hex(random_bytes(4))),
            'amount'      => $amount,
            'description' => $description,
            'simulated'   => !self::isConfigured(),
        ];
    }

    // ---- Simulated mode (for testing without Stripe keys) ----

    private static function simulateIntent(float $amount, string $currency, string $description): array
    {
        return [
            'id'            => 'pi_sim_' . bin2hex(random_bytes(12)),
            'client_secret' => 'pi_sim_' . bin2hex(random_bytes(16)) . '_secret_' . bin2hex(random_bytes(16)),
            'amount'        => $amount,
            'currency'      => $currency,
            'status'        => 'requires_capture',
            'description'   => $description,
            'simulated'     => true,
        ];
    }

    // ---- Raw Stripe REST API call ----

    private static function apiRequest(string $path, array $data = [], string $method = 'POST'): array
    {
        $url = "https://api.stripe.com/v1/{$path}";
        $body = http_build_query($data);

        $context = stream_context_create([
            'http' => [
                'method'  => $method,
                'header'  => implode("\r\n", [
                    'Authorization: Bearer ' . self::secretKey(),
                    'Content-Type: application/x-www-form-urlencoded',
                    'Content-Length: ' . strlen($body),
                ]),
                'content' => $body,
                'timeout' => 30,
                'ignore_errors' => true,
            ],
        ]);

        $result = @file_get_contents($url, false, $context);
        if ($result === false) {
            throw new \RuntimeException('Failed to connect to Stripe API');
        }

        $response = json_decode($result, true);
        if (isset($response['error'])) {
            throw new \RuntimeException('Stripe error: ' . ($response['error']['message'] ?? 'Unknown error'));
        }

        return $response;
    }
}
