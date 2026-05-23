<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/stripe.php';

$user = Auth::requireAuth();
$method = $_SERVER['REQUEST_METHOD'];

switch ($method) {
    case 'GET':
        // Return Stripe config status + publishable key
        jsonSuccess([
            'configured'       => StripeService::isConfigured(),
            'publishable_key'  => StripeService::publishableKey(),
            'mode'             => StripeService::isConfigured() ? 'live' : 'simulation',
        ]);
        break;

    case 'POST':
        $input = getJsonInput();
        $action = $input['action'] ?? '';
        $amount = (float)($input['amount'] ?? 0);
        $currency = $input['currency'] ?? 'mad';
        $description = sanitizeString($input['description'] ?? 'SuperMa POS');

        if ($amount <= 0) {
            jsonError('Invalid amount', 400);
        }

        switch ($action) {
            case 'create_intent':
                $intent = StripeService::createPaymentIntent($amount, $currency, $description);
                jsonSuccess($intent, 'Payment intent created');
                break;

            case 'capture':
                $intentId = sanitizeString($input['intent_id'] ?? '');
                if (!$intentId) {
                    jsonError('Intent ID required', 400);
                }
                $result = StripeService::capturePaymentIntent($intentId);
                jsonSuccess($result, 'Payment captured');
                break;

            case 'mobile_link':
                $link = StripeService::createMobilePaymentLink($amount, $description);
                jsonSuccess($link, 'Mobile payment link created');
                break;

            default:
                jsonError('Unknown action', 400);
        }
        break;

    default:
        jsonError('Method not allowed', 405);
}
