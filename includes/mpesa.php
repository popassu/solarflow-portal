<?php
/*
 * Safaricom Daraja — M-Pesa Express (STK push).
 * Credentials are entered in Admin → Settings → M-Pesa. Until they are filled in,
 * customers see the till number and the "Pay with M-Pesa" button is hidden.
 */

function mpesa_ready(): bool
{
    return setting('mpesa_key') !== '' && setting('mpesa_secret') !== '' && setting('mpesa_shortcode') !== '' && setting('mpesa_passkey') !== '';
}

function mpesa_host(): string
{
    return setting('mpesa_env') === 'production' ? 'https://api.safaricom.co.ke' : 'https://sandbox.safaricom.co.ke';
}

function mpesa_callback_url(): string
{
    return site_url('mpesa-callback.php?k=' . setting('callback_key'));
}

/** Normalise 07xx / 01xx / +254 numbers to 2547XXXXXXXX. Returns '' if not a valid Kenyan mobile. */
function mpesa_msisdn(string $phone): string
{
    $d = preg_replace('/\D/', '', $phone);
    if (str_starts_with($d, '0')) $d = '254' . substr($d, 1);
    if (strlen($d) === 9 && ($d[0] === '7' || $d[0] === '1')) $d = '254' . $d;
    return preg_match('/^254[17]\d{8}$/', $d) ? $d : '';
}

function mpesa_http(string $method, string $url, array $headers, ?string $body = null): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    $raw = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    if ($raw === false) throw new RuntimeException('Could not reach M-Pesa: ' . $err);
    return [$code, json_decode((string)$raw, true) ?: []];
}

function mpesa_token(): string
{
    [$code, $j] = mpesa_http('GET', mpesa_host() . '/oauth/v1/generate?grant_type=client_credentials',
        ['Authorization: Basic ' . base64_encode(setting('mpesa_key') . ':' . setting('mpesa_secret'))]);
    if ($code !== 200 || empty($j['access_token'])) throw new RuntimeException('M-Pesa sign-in failed. Check the Daraja consumer key and secret in Settings.');
    return $j['access_token'];
}

/**
 * Send an STK push. Records the request and returns its poll token.
 * $target: ['document_id' => id] or ['order_id' => id]
 */
function mpesa_stk(PDO $pdo, string $phone, float $amount, string $reference, string $desc, array $target): array
{
    if (!mpesa_ready()) throw new RuntimeException('Online M-Pesa payment is not switched on yet. Please pay to the till number shown.');
    $msisdn = mpesa_msisdn($phone);
    if ($msisdn === '') throw new RuntimeException('Enter a Safaricom number like 0712 345 678.');
    $amt = (int)ceil($amount);
    if ($amt < 1) throw new RuntimeException('There is nothing to pay.');

    $ts = date('YmdHis'); // Africa/Nairobi, set in bootstrap
    $short = setting('mpesa_shortcode');
    $isTill = setting('mpesa_type', 'till') === 'till';
    $payload = [
        'BusinessShortCode' => $short,
        'Password'          => base64_encode($short . setting('mpesa_passkey') . $ts),
        'Timestamp'         => $ts,
        'TransactionType'   => $isTill ? 'CustomerBuyGoodsOnline' : 'CustomerPayBillOnline',
        'Amount'            => $amt,
        'PartyA'            => $msisdn,
        'PartyB'            => $isTill ? setting('mpesa_till', $short) : $short,
        'PhoneNumber'       => $msisdn,
        'CallBackURL'       => mpesa_callback_url(),
        'AccountReference'  => mb_substr($reference, 0, 12),
        'TransactionDesc'   => mb_substr($desc, 0, 13),
    ];
    [$code, $j] = mpesa_http('POST', mpesa_host() . '/mpesa/stkpush/v1/processrequest',
        ['Authorization: Bearer ' . mpesa_token(), 'Content-Type: application/json'], json_encode($payload));
    if ($code !== 200 || ($j['ResponseCode'] ?? '') !== '0') {
        throw new RuntimeException($j['errorMessage'] ?? $j['ResponseDescription'] ?? 'M-Pesa did not accept the request. Try again shortly.');
    }
    $poll = bin2hex(random_bytes(16));
    $pdo->prepare('INSERT INTO mpesa_requests (document_id, order_id, phone, amount, checkout_id, merchant_id, status, poll, created_at, updated_at) VALUES (?,?,?,?,?,?,?,?,?,?)')
        ->execute([$target['document_id'] ?? null, $target['order_id'] ?? null, $msisdn, $amt, $j['CheckoutRequestID'] ?? null, $j['MerchantRequestID'] ?? null, 'pending', $poll, now(), now()]);
    return ['poll' => $poll, 'message' => $j['CustomerMessage'] ?? 'Check your phone and enter your M-Pesa PIN.'];
}

/** Handle Safaricom's callback. Only requests we started (matching CheckoutRequestID) are updated. */
function mpesa_handle_callback(PDO $pdo, array $payload): void
{
    $cb = $payload['Body']['stkCallback'] ?? null;
    if (!$cb || empty($cb['CheckoutRequestID'])) return;
    $st = $pdo->prepare("SELECT * FROM mpesa_requests WHERE checkout_id = ? AND status = 'pending'");
    $st->execute([$cb['CheckoutRequestID']]);
    $req = $st->fetch();
    if (!$req) return;

    if ((int)($cb['ResultCode'] ?? 1) !== 0) {
        $pdo->prepare("UPDATE mpesa_requests SET status='failed', result_code=?, result_desc=?, updated_at=? WHERE id=?")
            ->execute([(string)($cb['ResultCode'] ?? ''), mb_substr((string)($cb['ResultDesc'] ?? ''), 0, 255), now(), $req['id']]);
        return;
    }
    $meta = [];
    foreach (($cb['CallbackMetadata']['Item'] ?? []) as $i) $meta[$i['Name'] ?? ''] = $i['Value'] ?? null;
    $ref = mb_substr((string)($meta['MpesaReceiptNumber'] ?? ''), 0, 40);
    $amount = (float)($meta['Amount'] ?? $req['amount']);
    $pdo->prepare("UPDATE mpesa_requests SET status='paid', result_code='0', result_desc=?, mpesa_ref=?, amount=?, updated_at=? WHERE id=?")
        ->execute([mb_substr((string)($cb['ResultDesc'] ?? ''), 0, 255), $ref, $amount, now(), $req['id']]);

    if ($req['document_id']) {
        record_payment($pdo, (int)$req['document_id'], $amount, 'mpesa', $ref, date('Y-m-d'), 'Paid online via M-Pesa (' . $req['phone'] . ')', true);
    } elseif ($req['order_id']) {
        order_mark_paid($pdo, (int)$req['order_id'], $amount, $ref);
    }
}
