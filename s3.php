<?php
// Files in S3-compatible storage (Garage on Torii), when TORII_S3_ENDPOINT is set. Without it,
// uploads stay in /data/uploads as before (see api.php). See DATA.md.
//
// Path-style addresses (<endpoint>/<bucket>/<key>), signed with AWS Signature V4. Uploads are
// streamed from PHP's temporary file, never read into memory; their contents aren't hashed
// (UNSIGNED-PAYLOAD), which spares the Pi hashing a 50 MB video before sending it.

declare(strict_types=1);

function s3_on(): bool { return (string)getenv('TORII_S3_ENDPOINT') !== ''; }

// $body: null, or [file handle, size] to upload. $onHead($status, $headers) runs once the reply's
// headers are in, and $onData($chunk) for each piece of the body; without them the body is returned.
function s3_call(string $method, string $key, array $headers = [], ?array $body = null, ?callable $onHead = null, ?callable $onData = null): array {
    $endpoint = rtrim((string)getenv('TORII_S3_ENDPOINT'), '/');
    $bucket = (string)getenv('TORII_S3_BUCKET');
    $region = (string)(getenv('TORII_S3_REGION') ?: 'garage');
    $path = '/' . rawurlencode($bucket) . '/' . implode('/', array_map('rawurlencode', explode('/', $key)));
    $u = parse_url($endpoint);
    $host = $u['host'] . (isset($u['port']) ? ':' . $u['port'] : '');
    $now = time();
    $date = gmdate('Ymd', $now);
    $stamp = gmdate('Ymd\THis\Z', $now);
    $payload = $body ? 'UNSIGNED-PAYLOAD' : hash('sha256', '');
    $signed = "host:$host\nx-amz-content-sha256:$payload\nx-amz-date:$stamp\n";
    $scope = "$date/$region/s3/aws4_request";
    $request = "$method\n$path\n\n$signed\nhost;x-amz-content-sha256;x-amz-date\n$payload";
    $k = hash_hmac('sha256', 'aws4_request', hash_hmac('sha256', 's3', hash_hmac('sha256', $region,
        hash_hmac('sha256', $date, 'AWS4' . getenv('TORII_S3_SECRET_ACCESS_KEY'), true), true), true), true);
    $sig = hash_hmac('sha256', "AWS4-HMAC-SHA256\n$stamp\n$scope\n" . hash('sha256', $request), $k);
    $headers[] = "x-amz-content-sha256: $payload";
    $headers[] = "x-amz-date: $stamp";
    $headers[] = 'Authorization: AWS4-HMAC-SHA256 Credential=' . getenv('TORII_S3_ACCESS_KEY_ID')
        . "/$scope, SignedHeaders=host;x-amz-content-sha256;x-amz-date, Signature=$sig";
    $headers[] = 'Expect:';   // no 100-continue round trip

    $ch = curl_init($endpoint . $path);
    $status = 0;
    $got = [];
    $text = '';
    $started = false;
    curl_setopt_array($ch, [CURLOPT_CUSTOMREQUEST => $method, CURLOPT_HTTPHEADER => $headers, CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 300, CURLOPT_NOBODY => $method === 'HEAD',
        CURLOPT_HEADERFUNCTION => function ($ch, $line) use (&$status, &$got) {
            if (preg_match('#^HTTP/\S+ (\d+)#', $line, $m)) { $status = (int)$m[1]; $got = []; }
            elseif (str_contains($line, ':')) { [$n, $v] = explode(':', $line, 2); $got[strtolower(trim($n))] = trim($v); }
            return strlen($line);
        },
        CURLOPT_WRITEFUNCTION => function ($ch, $chunk) use (&$status, &$got, &$text, &$started, $onHead, $onData) {
            if ($onData && $status < 300) {
                if (!$started) { $started = true; if ($onHead) $onHead($status, $got); }
                $onData($chunk);
            } else {
                $text .= $chunk;
            }
            return strlen($chunk);
        }]);
    if ($body) curl_setopt_array($ch, [CURLOPT_UPLOAD => true, CURLOPT_INFILE => $body[0], CURLOPT_INFILESIZE => $body[1]]);
    $ok = curl_exec($ch);
    if ($ok === false) $status = 0;
    if ($onHead && !$started && $status >= 200 && $status < 300) $onHead($status, $got);   // an empty body
    return [$status, $got, $text];
}

// Upload a file (streamed) under $key. Documents are stored as downloads, never as pages
// (their files are served from toffee's own address).
function s3_put_file(string $key, string $file, string $mime, string $kind, string $original): bool {
    $fh = fopen($file, 'rb');
    if (!$fh) return false;
    $type = $kind === 'file' ? 'application/octet-stream' : $mime;
    $disp = ($kind === 'file' ? 'attachment' : 'inline') . '; filename="' . preg_replace('/[^\x20-\x7e]|"/', '_', $original ?: 'file') . '"';
    [$status] = s3_call('PUT', $key, ["Content-Type: $type", "Content-Disposition: $disp",
        'Cache-Control: public, max-age=31536000, immutable'], [$fh, (int)filesize($file)]);
    fclose($fh);
    return $status === 200;
}

function s3_delete(string $key): void { s3_call('DELETE', $key); }
