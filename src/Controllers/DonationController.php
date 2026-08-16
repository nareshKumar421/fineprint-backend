<?php
/**
 * DonationController — create, status, webhook.
 *
 * TWO RULES THAT ARE NOT OPTIONAL (docs/00 §4):
 *
 *  1. Validate the amount ON THE SERVER, not only in the app. A modified
 *     client can send any value it likes. The database CHECK constraint is
 *     the third line of defence, not the first.
 *
 *  2. ONLY THE WEBHOOK PROVES PAYMENT. What the app says and what the
 *     browser redirect says are both claims from an untrusted source. The
 *     webhook comes from Instamojo's server and carries a signature.
 *
 * PAYMENTS ARE CURRENTLY OFF
 * --------------------------
 * PAYMENTS_ENABLED=false in .env. While it is false, create() and the
 * sandbox refuse, and info() tells the app to display a UPI id instead.
 *
 * The whole Instamojo path is left intact rather than deleted: turning it
 * back on is one env value, and code that is deleted and re-added later
 * is code that gets re-tested from scratch.
 *
 * webhook() is deliberately NOT gated. A payment made minutes before the
 * switch was flipped still deserves to be recorded, and refusing it would
 * make Instamojo retry a request that can never succeed.
 */

declare(strict_types=1);

namespace App\Controllers;

use App\ApiException;
use App\Db;
use App\Env;
use App\Request;
use App\Response;
use App\Services\InstamojoService;
use PDO;
use PDOException;

final class DonationController
{
    private const MIN_AMOUNT = 100.0;
    private const MAX_AMOUNT = 5000.0;

    /** POST /api/donation/create */
    public function create(Request $request): void
    {
        // Checked BEFORE auth-independent work and before any provider call,
        // so a disabled provider can never be reached by any route through
        // this method.
        if (!self::paymentsEnabled()) {
            throw new ApiException(
                'PAYMENTS_DISABLED',
                'Card payments are turned off at the moment. You can still support '
                . 'the app by paying to the UPI id shown in the app.',
                503
            );
        }

        $userId = $request->requireUserId();
        $amount = $this->amount($request);

        $instamojo = new InstamojoService();
        $base      = Env::get('APP_URL', 'http://localhost:8000');

        // Call the provider BEFORE inserting, so a failed call cannot leave
        // an orphan pending row that monitoring would flag as a stuck
        // payment forever.
        $payment = $instamojo->createPaymentRequest(
            $amount,
            // Shown to the customer on Instamojo's own payment page, so it
            // has to be the app's real name.
            'Support FinePrint',
            $base . '/api/donation/webhook',
            $base . '/api/donation/thanks',
        );

        try {
            $id = (int) Db::scalar(
                'INSERT INTO donations (user_id, amount, status, instamojo_request_id, payment_url)
                 VALUES (?, ?, ?, ?, ?)
                 RETURNING id',
                [$userId, number_format($amount, 2, '.', ''), 'pending',
                 $payment['request_id'], $payment['payment_url']]
            );
        } catch (PDOException $e) {
            // The CHECK constraint is the last line of defence. If it fires
            // here, the validation above has a hole — log loudly.
            error_log('Donation insert rejected by the database: ' . $e->getMessage());
            throw new ApiException('VALIDATION_ERROR',
                'That amount is not allowed.', 400, $e);
        }

        Response::json([
            'donation_id' => $id,
            'payment_url' => $payment['payment_url'],
            'amount'      => number_format($amount, 2, '.', ''),
            'status'      => 'pending',
            'sandbox'     => $instamojo->isSandbox(),
        ], 201);
    }

    /**
     * GET /api/donation/info
     *
     * What the Donate screen renders. No auth: it carries nothing private —
     * a UPI id is published precisely so strangers can pay into it — and
     * requiring a token would mean a logged-out user is told "session
     * expired" when all they wanted was the way to donate.
     *
     * The app asks for this rather than hard-coding the UPI id, so the id
     * can be corrected in .env without shipping a new build to the store.
     * A wrong UPI id baked into an APK sends money to a stranger until
     * every user updates.
     */
    public function info(Request $request): void
    {
        if (self::paymentsEnabled()) {
            Response::json([
                'method'      => 'gateway',
                'provider'    => 'instamojo',
                'min_amount'  => number_format(self::MIN_AMOUNT, 2, '.', ''),
                'max_amount'  => number_format(self::MAX_AMOUNT, 2, '.', ''),
            ]);
            return;
        }

        $upiId = Env::get('DONATION_UPI_ID');

        // Misconfiguration, not a user error: payments are off and no UPI id
        // was set, so there is no way at all to donate. Say so plainly
        // instead of rendering a screen with a blank id on it.
        if ($upiId === '') {
            error_log('PAYMENTS_ENABLED is false but DONATION_UPI_ID is empty — '
                . 'the Donate screen has nothing to show.');
            throw new ApiException('PAYMENTS_DISABLED',
                'Donations are unavailable at the moment.', 503);
        }

        $payee = Env::get('DONATION_UPI_NAME', 'FinePrint');

        Response::json([
            'method'     => 'upi',
            'upi_id'     => $upiId,
            'payee_name' => $payee,

            // A upi:// link opens the phone's UPI app with the payee already
            // filled in. No amount is included: this is a donation, the
            // payer chooses. Every value is urlencoded — an unencoded & or
            // space in the payee name would truncate the link silently.
            'upi_link'   => 'upi://pay?pa=' . rawurlencode($upiId)
                          . '&pn=' . rawurlencode($payee)
                          . '&cu=INR',

            'note'       => 'Card payments are being set up. Until then you can '
                          . 'send any amount to this UPI id — thank you.',
        ]);
    }

    /** GET /api/donation/status?donation_id=N */
    public function status(Request $request): void
    {
        $userId = $request->requireUserId();
        $raw    = $request->query('donation_id');

        if ($raw === null || !ctype_digit((string) $raw)) {
            throw new ApiException('VALIDATION_ERROR', 'donation_id is required.', 400);
        }

        $row = Db::one(
            'SELECT id, amount, status, completed_at
               FROM donations
              WHERE id = ? AND user_id = ?',
            [(int) $raw, $userId]
        );

        // 404 rather than 403 for someone else's donation, so ids cannot be
        // enumerated by watching which ones return "forbidden".
        if ($row === null) {
            throw new ApiException('NOT_FOUND', 'No such donation.', 404);
        }

        Response::json([
            'donation_id'  => (int) $row['id'],
            'amount'       => $row['amount'],
            'status'       => $row['status'],
            'completed_at' => $row['completed_at'],
        ]);
    }

    /**
     * POST /api/donation/webhook
     *
     * Instamojo's server calls this. No user auth — the signature IS the
     * authentication.
     */
    public function webhook(Request $request): void
    {
        // Instamojo posts form-encoded, not JSON.
        $post = $_POST;
        if ($post === []) {
            parse_str(file_get_contents('php://input') ?: '', $post);
        }

        $instamojo = new InstamojoService();

        // ORDER MATTERS. Verify first, touch nothing until it passes.
        if (!$instamojo->verifyWebhook($post)) {
            error_log('Donation webhook REJECTED: bad signature from ' . $request->ip());
            // No detail in the body — an attacker learns nothing about why.
            Response::json(['error' => ['code' => 'FORBIDDEN', 'message' => 'Invalid signature.']], 403);
            return;
        }

        $requestId = (string) ($post['payment_request_id'] ?? '');
        $paymentId = (string) ($post['payment_id'] ?? '');
        $rawStatus = strtolower((string) ($post['status'] ?? ''));

        if ($requestId === '' || $paymentId === '') {
            Response::json(['success' => true]);   // nothing to act on
            return;
        }

        $this->applyPaymentResult($requestId, $paymentId, $rawStatus);

        // 200 quickly. Instamojo retries anything else.
        Response::json(['success' => true]);
    }

    /**
     * Record the outcome of a payment.
     *
     * Shared by the HTTP webhook and the sandbox, so both go through exactly
     * the same status mapping, transaction and replay handling.
     */
    private function applyPaymentResult(string $requestId, string $paymentId, string $rawStatus): void
    {
        $status = in_array(strtolower($rawStatus), ['credit', 'completed', 'success'], true)
            ? 'success'
            : 'failed';

        try {
            Db::transaction(static function (PDO $db) use ($requestId, $paymentId, $status): void {
                $stmt = $db->prepare(
                    "UPDATE donations
                        SET status = ?,
                            instamojo_payment_id = ?,
                            completed_at = CASE WHEN ? = 'success' THEN NOW() ELSE completed_at END
                      WHERE instamojo_request_id = ?
                        AND status = 'pending'"
                );
                $stmt->execute([$status, $paymentId, $status, $requestId]);
            });
        } catch (PDOException $e) {
            // instamojo_payment_id is UNIQUE, so a REPLAYED webhook collides
            // here. That is the defence working, not a failure — Instamojo
            // retries on non-200, so the caller must still answer 200 or it
            // will retry forever.
            if ($e->getCode() !== '23505') {
                throw $e;
            }
        }
    }

    /**
     * GET|POST /api/donation/sandbox — LOCAL TESTING ONLY.
     *
     * Stands in for Instamojo's hosted payment page so the whole flow can be
     * exercised without credentials. Refuses to run whenever a real API key
     * is configured or APP_ENV is production.
     */
    public function sandbox(Request $request): void
    {
        $instamojo = new InstamojoService();
        if (!self::paymentsEnabled() || !$instamojo->isSandbox()) {
            throw new ApiException('NOT_FOUND', 'Unknown endpoint.', 404);
        }

        $requestId = (string) ($request->query('request_id') ?? '');
        $amount    = (string) ($request->query('amount') ?? '0.00');
        $decision  = (string) ($request->query('decision') ?? '');

        if ($decision === '') {
            // Render the "payment page".
            Response::html($this->sandboxPage($requestId, $amount));
            return;
        }

        /*
         * Build a correctly-signed webhook payload and process it IN-PROCESS.
         *
         * It deliberately does NOT post to /api/donation/webhook over HTTP.
         * `php -S` is single-threaded, so a request that calls back into the
         * same server deadlocks: the server cannot answer the second request
         * while it is still busy with the first. That hung the dev server
         * hard the first time this was written.
         *
         * The signature is still generated and VERIFIED through the same
         * code the real webhook uses, so the security-critical path is
         * genuinely exercised — only the HTTP hop is skipped, and that hop
         * is covered separately by verify-phase7.sh posting to the endpoint.
         */
        $fields = [
            'payment_id'         => 'sbxpay_' . bin2hex(random_bytes(6)),
            'payment_request_id' => $requestId,
            'status'             => $decision === 'pay' ? 'Credit' : 'Failed',
            'amount'             => $amount,
        ];
        $fields['mac'] = $instamojo->sign($fields);

        if (!$instamojo->verifyWebhook($fields)) {
            error_log('Sandbox produced a payload its own verifier rejected — signing is broken');
            Response::html($this->sandboxDonePage(false), 500);
            return;
        }

        $this->applyPaymentResult($requestId, $fields['payment_id'], $fields['status']);

        Response::html($this->sandboxDonePage($decision === 'pay'));
    }

    /* ------------------------------------------------------------------ */

    /**
     * Is the payment gateway switched on?
     *
     * Anything other than an explicit true is false. A typo'd value must
     * fail CLOSED — the failure mode of wrongly-on is taking money through
     * a half-configured gateway, and of wrongly-off is a visible screen
     * saying to use UPI.
     */
    private static function paymentsEnabled(): bool
    {
        return strtolower(trim(Env::get('PAYMENTS_ENABLED', 'false'))) === 'true';
    }

    private function amount(Request $request): float
    {
        $raw = $request->input('amount');

        if (!is_int($raw) && !is_float($raw)
            && !(is_string($raw) && is_numeric($raw))) {
            throw new ApiException('VALIDATION_ERROR', 'Enter a valid amount.', 400);
        }

        $amount = round((float) $raw, 2);

        if ($amount < self::MIN_AMOUNT || $amount > self::MAX_AMOUNT) {
            throw new ApiException('VALIDATION_ERROR',
                'Amount must be between ₹' . (int) self::MIN_AMOUNT
                . ' and ₹' . number_format(self::MAX_AMOUNT), 400);
        }

        return $amount;
    }

    private function sandboxPage(string $requestId, string $amount): string
    {
        $r = htmlspecialchars($requestId, ENT_QUOTES);
        $a = htmlspecialchars($amount, ENT_QUOTES);
        return <<<HTML
<!doctype html><html><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Sandbox payment</title>
<style>
 body{font:16px -apple-system,Roboto,sans-serif;margin:0;background:#F5F3F1;color:#0E0F12;
      display:flex;align-items:center;justify-content:center;min-height:100vh;padding:24px}
 .card{background:#fff;border-radius:16px;padding:28px;max-width:380px;width:100%;
       box-shadow:0 2px 24px rgba(0,0,0,.08)}
 .tag{display:inline-block;background:#FBF0DA;color:#B4790E;font-size:11px;font-weight:700;
      letter-spacing:.5px;padding:5px 10px;border-radius:999px;margin-bottom:16px}
 h1{font-size:22px;margin:0 0 6px} p{color:#565B63;margin:0 0 22px;line-height:1.5}
 .amt{font-size:34px;font-weight:700;margin:0 0 22px}
 a{display:block;text-align:center;padding:15px;border-radius:12px;text-decoration:none;
   font-weight:600;margin-bottom:10px}
 .pay{background:#2563EB;color:#fff} .fail{background:#fff;color:#565B63;border:1px solid #E2E5E9}
</style></head><body><div class="card">
 <span class="tag">SANDBOX — NO REAL MONEY</span>
 <h1>Support FinePrint</h1>
 <p>Instamojo is not configured, so this stands in for their payment page. Both buttons
    send a correctly signed webhook, exactly as Instamojo would.</p>
 <div class="amt">₹{$a}</div>
 <a class="pay"  href="?request_id={$r}&amount={$a}&decision=pay">Pay ₹{$a}</a>
 <a class="fail" href="?request_id={$r}&amount={$a}&decision=fail">Simulate a failed payment</a>
</div></body></html>
HTML;
    }

    private function sandboxDonePage(bool $paid): string
    {
        $title = $paid ? 'Payment successful' : 'Payment failed';
        $msg   = $paid
            ? 'The webhook has been sent. Return to the app — it will confirm shortly.'
            : 'The webhook has been sent. The donation is marked failed; you can try again.';
        $col   = $paid ? '#0E7C6B' : '#A32D2D';
        return <<<HTML
<!doctype html><html><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1"><title>{$title}</title>
<style>body{font:16px -apple-system,Roboto,sans-serif;margin:0;background:#F5F3F1;
 display:flex;align-items:center;justify-content:center;min-height:100vh;padding:24px;text-align:center}
 .card{background:#fff;border-radius:16px;padding:32px;max-width:360px}
 h1{font-size:20px;margin:0 0 8px;color:{$col}} p{color:#565B63;line-height:1.5;margin:0}</style>
</head><body><div class="card"><h1>{$title}</h1><p>{$msg}</p></div></body></html>
HTML;
    }
}
