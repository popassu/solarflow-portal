<?php
/*
 * Minimal authenticated SMTP client (no external libraries needed).
 * Default settings are for Hostinger mailboxes: smtp.hostinger.com, port 465, SSL.
 */

/**
 * Send a plain-text email. Returns true on success; on failure returns false and sets $error.
 */
/**
 * $attachments: list of [file name, MIME type, content]. HTML/script attachments are refused (Gmail blocks them).
 * $html: optional HTML version of the body (sent as multipart/alternative with the plain text).
 */
function pp_mail(string $to, string $subject, string $body, ?string $replyTo = null, ?string &$error = null, array $attachments = [], ?string $html = null): bool
{
    $attachments = array_values(array_filter($attachments, fn($a) => !preg_match('#(html|javascript|x-msdownload)#i', (string)$a[1]) && !preg_match('/\.(html?|js|exe|bat|cmd|scr)$/i', (string)$a[0])));
    $s = cfg('smtp', []);
    $from = cfg('mail_from');
    $fromName = cfg('mail_from_name', 'Sunbeam Innovation Limited');
    $error = null;

    $to = trim($to);
    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
        $error = 'Recipient address is not valid.';
        return false;
    }
    $clean = fn(string $v) => str_replace(["\r", "\n"], ' ', $v);
    $encName = '=?UTF-8?B?' . base64_encode($fromName) . '?=';
    $headers = [
        'Date: ' . date('r'),
        'From: ' . $encName . ' <' . $from . '>',
        'To: <' . $to . '>',
        'Subject: =?UTF-8?B?' . base64_encode($clean($subject)) . '?=',
        'Message-ID: <' . bin2hex(random_bytes(12)) . '@' . substr(strrchr($from, '@'), 1) . '>',
        'MIME-Version: 1.0',
    ];
    $b64 = fn(string $v) => rtrim(chunk_split(base64_encode($v), 76, "\r\n"));
    if ($html !== null && !$attachments) {
        $bd = 'sba-' . bin2hex(random_bytes(10));
        $headers[] = 'Content-Type: multipart/alternative; boundary="' . $bd . '"';
        $payload = "--$bd\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n" . $b64($body) . "\r\n"
                 . "--$bd\r\nContent-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n" . $b64($html) . "\r\n--$bd--";
    } elseif ($attachments) {
        $bd = 'sb-' . bin2hex(random_bytes(10));
        $headers[] = 'Content-Type: multipart/mixed; boundary="' . $bd . '"';
        if ($html !== null) {
            $alt = 'sba-' . bin2hex(random_bytes(10));
            $payload = "--$bd\r\nContent-Type: multipart/alternative; boundary=\"$alt\"\r\n\r\n"
                     . "--$alt\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n" . $b64($body) . "\r\n"
                     . "--$alt\r\nContent-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n" . $b64($html) . "\r\n--$alt--\r\n";
        } else {
            $payload = "--$bd\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n" . $b64($body) . "\r\n";
        }
        foreach ($attachments as [$fname, $mime, $content]) {
            $fname = preg_replace('/[^A-Za-z0-9._-]/', '_', (string)$fname);
            $payload .= "--$bd\r\nContent-Type: $mime; name=\"$fname\"\r\nContent-Disposition: attachment; filename=\"$fname\"\r\nContent-Transfer-Encoding: base64\r\n\r\n" . $b64((string)$content) . "\r\n";
        }
        $payload .= "--$bd--";
    } else {
        $headers[] = 'Content-Type: text/plain; charset=UTF-8';
        $headers[] = 'Content-Transfer-Encoding: base64';
        $payload = $b64($body);
    }
    if ($replyTo && filter_var($replyTo, FILTER_VALIDATE_EMAIL)) {
        $headers[] = 'Reply-To: <' . $clean($replyTo) . '>';
    }
    $message = implode("\r\n", $headers) . "\r\n\r\n" . $payload;

    if (empty($s['enabled'])) {
        // Fallback: PHP mail() via the hosting server
        $hdr = array_values(array_filter($headers, fn($h) => !preg_match('/^(To|Subject|Date):/i', $h)));
        $ok = @mail($to, '=?UTF-8?B?' . base64_encode($clean($subject)) . '?=', $payload, implode("\r\n", $hdr), '-f' . $from);
        if (!$ok) $error = 'PHP mail() failed. Turn on SMTP in includes/config.php.';
        return $ok;
    }

    $secure = $s['secure'] ?? 'ssl';                 // ssl | tls | none
    $host = ($secure === 'ssl' ? 'ssl://' : 'tcp://') . $s['host'] . ':' . (int)$s['port'];
    $ctx = stream_context_create(['ssl' => ['verify_peer' => true, 'verify_peer_name' => true, 'peer_name' => $s['host']]]);
    $fp = @stream_socket_client($host, $errno, $errstr, 15, STREAM_CLIENT_CONNECT, $ctx);
    if (!$fp) {
        $error = "Could not connect to {$s['host']}:{$s['port']} ($errstr).";
        return false;
    }
    stream_set_timeout($fp, 20);

    $read = function () use ($fp): string {
        $data = '';
        while (($line = fgets($fp, 1024)) !== false) {
            $data .= $line;
            if (strlen($line) < 4 || $line[3] === ' ') break;
        }
        return $data;
    };
    $cmd = function (?string $c, array $expect, ?string $label = null) use ($fp, $read, &$error): bool {
        if ($c !== null) fwrite($fp, $c . "\r\n");
        $resp = $read();
        $code = (int)substr($resp, 0, 3);
        if (!in_array($code, $expect, true)) {
            $error = 'SMTP error at ' . ($label ?? ($c === null ? 'connect' : strtok($c, ' '))) . ': ' . trim($resp);
            return false;
        }
        return true;
    };

    $ehloHost = parse_url((string)cfg('site_url'), PHP_URL_HOST) ?: 'localhost';
    $ok = $cmd(null, [220]) && $cmd('EHLO ' . $ehloHost, [250]);
    if ($ok && $secure === 'tls') {
        $ok = $cmd('STARTTLS', [220])
            && stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT)
            && $cmd('EHLO ' . $ehloHost, [250]);
    }
    $ok = $ok
        && $cmd('AUTH LOGIN', [334])
        && $cmd(base64_encode($s['user']), [334], 'username')
        && $cmd(base64_encode($s['pass']), [235], 'password (check the mailbox password)')
        && $cmd('MAIL FROM:<' . $from . '>', [250])
        && $cmd('RCPT TO:<' . $to . '>', [250, 251])
        && $cmd('DATA', [354]);
    if ($ok) {
        // dot-stuffing
        $data = preg_replace('/^\./m', '..', $message);
        $ok = $cmd($data . "\r\n.", [250], 'message body');
    }
    @fwrite($fp, "QUIT\r\n");
    fclose($fp);
    return $ok;
}
