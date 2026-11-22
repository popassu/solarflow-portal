<?php
/* EVC Plus / Zaad / Sahal payments (Somalia) through the WaafiPay API (API_PURCHASE, mobile wallet).
 * The customer gets a prompt on their phone and approves with their PIN; WaafiPay answers on the same call.
 * A payment counts only when WaafiPay replies responseCode 2001 with state APPROVED for at least the amount asked. */

function waafi_ready(): bool
{
    return setting('waafi_merchant_uid') !== '' && setting('waafi_api_user_id') !== '' && setting('waafi_api_key') !== '';
}

function waafi_url(): string
{
    $t = (string)cfg('waafi_test_url', '');       // local mock for tests only
    if ($t !== '' && preg_match('#^http://(127\.0\.0\.1|localhost)(:\d+)?/#', $t)) return $t;
    return setting('waafi_env') === 'live' ? 'https://api.waafipay.net/asm' : 'https://sandbox.waafipay.com/asm';
}

/** Somali mobile number in international form: 2526XXXXXXXX. */
function waafi_msisdn(string $phone): string
{
    $d = preg_replace('/\D/', '', $phone);
    if (str_starts_with($d, '00')) $d = substr($d, 2);
    if (preg_match('/^0?(6\d{8})$/', $d, $m)) $d = '252' . $m[1];
    if (!preg_match('/^2526\d{8}$/', $d)) throw new RuntimeException('Enter a Somali mobile number, for example 061 XXX XXXX or +252 61 XXX XXXX.');
    return $d;
}

/** Amount WaafiPay will charge, and in which currency. */
function waafi_quote(float $amountHome, ?string $home = null): array
{
    $home = $home ?: setting('currency', 'KES');
    $cur = setting('waafi_currency', 'USD');
    if ($cur === $home || $cur === 'KES') return [$home, round($amountHome, 2)];
    if ($home === 'USD') return ['USD', round($amountHome, 2)];
    $fx = (float)setting('waafi_fx');
    if ($fx <= 0) throw new RuntimeException('EVC Plus is not ready yet (no exchange rate). Please pay by M-Pesa or bank.');
    return [$cur, ceil($amountHome / $fx * 100) / 100];
}

/**
 * Asks the customer's phone to approve the payment. Waits for the answer (up to about 2 minutes).
 * Returns ['ok' => bool, 'tx' => transactionId, 'charged' => [currency, amount], 'message' => text].
 */
function waafi_purchase(string $phone, float $amountHome, string $reference, string $desc, ?string $homeCur = null): array
{
    if (!waafi_ready()) throw new RuntimeException('EVC Plus payments are not set up yet.');
    $msisdn = waafi_msisdn($phone);
    [$cur, $amt] = waafi_quote($amountHome, $homeCur);
    $body = json_encode([
        'schemaVersion' => '1.0', 'requestId' => bin2hex(random_bytes(16)), 'timestamp' => gmdate('Y-m-d H:i:s'), 'channelName' => 'WEB',
        'serviceName' => 'API_PURCHASE',
        'serviceParams' => [
            'merchantUid' => setting('waafi_merchant_uid'), 'apiUserId' => setting('waafi_api_user_id'), 'apiKey' => setting('waafi_api_key'),
            'paymentMethod' => 'MWALLET_ACCOUNT', 'payerInfo' => ['accountNo' => $msisdn],
            'transactionInfo' => ['referenceId' => $reference . '-' . substr(bin2hex(random_bytes(3)), 0, 6), 'invoiceId' => $reference,
                                  'amount' => number_format($amt, 2, '.', ''), 'currency' => $cur, 'description' => mb_substr($desc, 0, 100)],
        ],
    ]);
    @set_time_limit(180);
    $ch = curl_init(waafi_url());
    curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 150,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json']]);
    $raw = curl_exec($ch);
    $err = curl_error($ch);
    curl_close($ch);
    if ($raw === false) throw new RuntimeException('EVC Plus could not be reached. Please try again. (' . $err . ')');
    $j = json_decode((string)$raw, true) ?: [];
    $p = $j['params'] ?? [];
    $ok = (string)($j['responseCode'] ?? '') === '2001' && strtoupper((string)($p['state'] ?? '')) === 'APPROVED'
        && (float)($p['txAmount'] ?? $amt) + 0.001 >= $amt;
    return ['ok' => $ok, 'tx' => (string)($p['transactionId'] ?? $p['issuerTransactionId'] ?? ''), 'charged' => [$cur, $amt], 'msisdn' => $msisdn,
            'message' => $ok ? 'Payment approved.' : ('Not completed: ' . mb_substr((string)($j['responseMsg'] ?? 'declined'), 0, 160))];
}
