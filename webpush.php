<?php
// Web Push for toffee: phone/desktop notifications even when the app is closed.
// No libraries: OpenSSL does the P-256 keys, ECDH, AES-128-GCM and ES256.
//   - Payload encryption: RFC 8291 (aes128gcm content coding, RFC 8188).
//   - Sender identification: VAPID, RFC 8292 (a signed JWT per push service).
// The VAPID key pair is made on first use and kept on the data volume.

declare(strict_types=1);

function b64url(string $s): string { return rtrim(strtr(base64_encode($s), '+/', '-_'), '='); }
function b64url_decode(string $s): string { return base64_decode(strtr($s, '-_', '+/') . str_repeat('=', (4 - strlen($s) % 4) % 4)); }

// Raw uncompressed P-256 public key (0x04 || X || Y) of an OpenSSL key.
function p256_raw_public($key): string {
    $ec = openssl_pkey_get_details($key)['ec'];
    return "\x04" . str_pad($ec['x'], 32, "\0", STR_PAD_LEFT) . str_pad($ec['y'], 32, "\0", STR_PAD_LEFT);
}
// OpenSSL public key from a raw uncompressed P-256 point (DER SubjectPublicKeyInfo).
function p256_public_from_raw(string $raw) {
    $der = hex2bin('3059301306072a8648ce3d020106082a8648ce3d030107034200') . $raw;
    $pem = "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($der), 64, "\n") . "-----END PUBLIC KEY-----\n";
    return openssl_pkey_get_public($pem);
}
function p256_new() {
    return openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
}

// This server's VAPID keys: ['private' => PEM, 'public' => base64url raw point].
function vapid_new(): array {   // a new key pair (kept in the database: see api.php, version 5)
    $key = p256_new();
    openssl_pkey_export($key, $pem);
    return ['private' => $pem, 'public' => b64url(p256_raw_public($key))];
}

// ES256 JWT for the push service at $endpoint (DER ECDSA signature -> raw r || s).
function vapid_header(array $vapid, string $endpoint, string $subject): string {
    $u = parse_url($endpoint);
    $aud = $u['scheme'] . '://' . $u['host'] . (isset($u['port']) ? ':' . $u['port'] : '');
    $input = b64url(json_encode(['typ' => 'JWT', 'alg' => 'ES256'])) . '.'
           . b64url(json_encode(['aud' => $aud, 'exp' => time() + 12 * 3600, 'sub' => $subject]));
    openssl_sign($input, $der, openssl_pkey_get_private($vapid['private']), OPENSSL_ALGO_SHA256);
    $off = 3;                                   // 0x30 len 0x02 ...
    $rlen = ord($der[$off]);
    $r = substr($der, $off + 1, $rlen);
    $off += 1 + $rlen + 1;
    $slen = ord($der[$off]);
    $s = substr($der, $off + 1, $slen);
    $fix = fn($n) => str_pad(ltrim($n, "\0"), 32, "\0", STR_PAD_LEFT);
    return 'vapid t=' . $input . '.' . b64url($fix($r) . $fix($s)) . ', k=' . $vapid['public'];
}

// The encrypted body (RFC 8291) for one subscription.
function webpush_encrypt(string $payload, string $uaPublic, string $authSecret): string {
    $as = p256_new();
    $asPublic = p256_raw_public($as);
    $ecdh = openssl_pkey_derive(p256_public_from_raw($uaPublic), $as);
    $prkKey = hash_hmac('sha256', $ecdh, $authSecret, true);
    $ikm = hash_hmac('sha256', "WebPush: info\0" . $uaPublic . $asPublic . "\x01", $prkKey, true);
    $salt = random_bytes(16);
    $prk = hash_hmac('sha256', $ikm, $salt, true);
    $cek = substr(hash_hmac('sha256', "Content-Encoding: aes128gcm\0\x01", $prk, true), 0, 16);
    $nonce = substr(hash_hmac('sha256', "Content-Encoding: nonce\0\x01", $prk, true), 0, 12);
    $cipher = openssl_encrypt($payload . "\x02", 'aes-128-gcm', $cek, OPENSSL_RAW_DATA, $nonce, $tag, '', 16);
    return $salt . pack('N', 4096) . chr(65) . $asPublic . $cipher . $tag;
}

// How the push service treats a message (RFC 8030): ttl = seconds it may wait for a phone that's
// off or asleep (then it's dropped); urgency 'high' wakes a sleeping phone (Android's Doze), for
// calls and private messages; topic = a newer message with the same topic replaces one not yet
// delivered (a ring by the next ring, or by "Missed call").
function webpush_headers(array $o): array {
    $h = ['TTL: ' . max(0, (int)($o['ttl'] ?? 86400)), 'Urgency: ' . (in_array($o['urgency'] ?? '', ['very-low', 'low', 'normal', 'high'], true) ? $o['urgency'] : 'normal')];
    if (!empty($o['topic'])) $h[] = 'Topic: ' . substr(preg_replace('/[^A-Za-z0-9_-]/', '', (string)$o['topic']), 0, 32);
    return $h;
}

// Send pushes, all at the same time: $sends is a list of [subscription, message, options]
// (options: see webpush_headers; none = kept a day, normal urgency).
// Returns endpoint => HTTP status (0 if that push service couldn't be reached).
function webpush_send_all(array $vapid, array $sends, string $subject): array {
    $mh = curl_multi_init();
    $handles = [];
    $broken = [];
    foreach ($sends as $send) {
        [$sub, $message] = $send;
        $opts = $send[2] ?? [];
        // One phone whose keys don't work is skipped (marked 410: forgotten), never the whole batch.
        try { $body = webpush_encrypt(json_encode($message), b64url_decode($sub['p256dh']), b64url_decode($sub['auth'])); }
        catch (Throwable $e) { $broken[$sub['endpoint']] = 410; continue; }
        $ch = curl_init($sub['endpoint']);
        curl_setopt_array($ch, [
            CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body, CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 3, CURLOPT_TIMEOUT => 5,
            CURLOPT_HTTPHEADER => array_merge(['Content-Type: application/octet-stream', 'Content-Encoding: aes128gcm'],
                webpush_headers($opts), ['Authorization: ' . vapid_header($vapid, $sub['endpoint'], $subject)]),
        ]);
        curl_multi_add_handle($mh, $ch);
        $handles[$sub['endpoint']] = $ch;
    }
    do {
        $status = curl_multi_exec($mh, $running);
        if ($running) curl_multi_select($mh, 1.0);
    } while ($running && $status === CURLM_OK);
    $codes = [];
    foreach ($handles as $endpoint => $ch) {
        $codes[$endpoint] = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_multi_remove_handle($mh, $ch);
    }
    curl_multi_close($mh);
    return $codes + $broken;
}
