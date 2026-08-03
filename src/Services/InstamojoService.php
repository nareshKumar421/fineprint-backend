<?php
/**
 * InstamojoService — create payment requests and verify webhooks.
 *
 * No SDK. Two methods and cURL.
 *
 * SANDBOX MODE
 * ------------
 * When no API key is configured AND APP_ENV is not production, this falls
 * back to a LOCAL sandbox: it mints a payment request handled by our own
 * /api/donation/sandbox endpoint, signed with the same HMAC the real
 * webhook uses.
 *
 * That exists so the whole flow — validation, the pending row, the payment
 * page, the webhook, the signature check, the status poll — can be built
 * and tested before the client has Instamojo credentials. Every line of
 * production code is exercised; only the remote HTTP call is replaced.
 *
 * It CANNOT run in production: isSandbox() requires APP_ENV !== 'production'
 * as well as a missing key, so setting APP_ENV=production disables it even
 * if someone forgets to fill in the credentials. That combination is
 * asserted by verify-phase7.sh.
 */

declare(strict_types=1);

namespace App\Services;

use App\ApiException;
use App\Env;

final class InstamojoService
{
    private const TIMEOUT = 15;

    public function isSandbox(): bool
    {
        return Env::get('INSTAMOJO_API_KEY') === '' && !Env::isProduction();
    }

    /**
     * Ask Instamojo for a payment page.
     *
     * @return array{request_id: string, payment_url: string}
     */
    public function createPaymentRequest(
        float $amount,
        string $purpose,
        string $webhookUrl,
        string $redirectUrl,
    ): array {
        if ($this->isSandbox()) {
            return $this->sandboxRequest($amount);
        }

        $endpoint = rtrim(Env::get('INSTAMOJO_BASE_URL'), '/') . '/payment-requests/';

        $ch = curl_init($endpoint);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_TIMEOUT        => self::TIMEOUT,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_HTTPHEADER     => [
                'X-Api-Key: '     . Env::get('INSTAMOJO_API_KEY'),
                'X-Auth-Token: '  . Env::get('INSTAMOJO_AUTH_TOKEN'),
            ],
            CURLOPT_POSTFIELDS => http_build_query([
                'purpose'                 => $purpose,
                // Instamojo wants a string with 2 decimals, not a float.
                'amount'                  => number_format($amount, 2, '.', ''),
                'send_email'              => 'False',
                'allow_repeated_payments' => 'False',
                'webhook'                 => $webhookUrl,
                'redirect_url'            => $redirectUrl,
            ]),
        ]);

        $body   = curl_exec($ch);
        $err    = curl_error($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);

        if ($body === false || $err !== '') {
            // Instamojo unreachable is a 502, not a 500 — it is their fault,
            // and the app tells the user to try again shortly rather than
            // showing a generic error.
            throw new ApiException('PAYMENT_PROVIDER_ERROR',
                'Payments are temporarily unavailable. Please try again shortly.', 502);
        }

        $data = json_decode((string) $body, true);

        if ($status !== 201 || !is_array($data) || ($data['success'] ?? false) !== true) {
            error_log('Instamojo create failed: HTTP ' . $status . ' ' . substr((string) $body, 0, 500));
            throw new ApiException('PAYMENT_PROVIDER_ERROR',
                'Payments are temporarily unavailable. Please try again shortly.', 502);
        }

        $req = $data['payment_request'] ?? [];
        if (empty($req['id']) || empty($req['longurl'])) {
            throw new ApiException('PAYMENT_PROVIDER_ERROR',
                'Payments are temporarily unavailable. Please try again shortly.', 502);
        }

        return [
            'request_id'  => (string) $req['id'],
            'payment_url' => (string) $req['longurl'],
        ];
    }

    /**
     * Verify a webhook came from Instamojo.
     *
     * HMAC-SHA1 over the field VALUES, sorted by KEY, joined with "|",
     * keyed by the salt.
     *
     * Three details, each a real bug if missed:
     *   1. sort by key, join the values
     *   2. remove `mac` before computing, or it can never match
     *   3. hash_equals(), never === — string comparison short-circuits and
     *      leaks how many leading characters were correct
     */
    public function verifyWebhook(array $post, ?string $salt = null): bool
    {
        $salt ??= Env::get('INSTAMOJO_SALT');
        if ($salt === '') {
            return false;    // unconfigured must fail closed, never open
        }

        $mac = (string) ($post['mac'] ?? '');
        if ($mac === '') {
            return false;
        }

        unset($post['mac']);
        ksort($post, SORT_STRING | SORT_FLAG_CASE);

        $expected = hash_hmac('sha1', implode('|', array_map('strval', $post)), $salt);

        return hash_equals($expected, $mac);
    }

    /** Sign a payload the way Instamojo would — used only by the sandbox. */
    public function sign(array $fields, ?string $salt = null): string
    {
        $salt ??= Env::get('INSTAMOJO_SALT');
        unset($fields['mac']);
        ksort($fields, SORT_STRING | SORT_FLAG_CASE);
        return hash_hmac('sha1', implode('|', array_map('strval', $fields)), $salt);
    }

    /* ------------------------------------------------------------------ */
    /*  Sandbox                                                            */
    /* ------------------------------------------------------------------ */

    private function sandboxRequest(float $amount): array
    {
        $id = 'sbx_' . bin2hex(random_bytes(8));

        error_log('InstamojoService: SANDBOX payment request ' . $id
                  . ' — no INSTAMOJO_API_KEY configured');

        return [
            'request_id'  => $id,
            // Served by our own DonationController::sandbox().
            'payment_url' => Env::get('APP_URL', 'http://localhost:8000')
                             . '/api/donation/sandbox?request_id=' . urlencode($id)
                             . '&amount=' . urlencode(number_format($amount, 2, '.', '')),
        ];
    }
}
