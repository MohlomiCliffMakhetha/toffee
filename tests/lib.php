<?php
// Shared by the tests: a tiny HTTP client (one cookie jar per person) and the checks.
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

// ---- a tiny client: one cookie jar per person -------------------------------------------
final class Client {
    private array $cookies = [];
    private string $csrf = '';
    public array $headers = [];   // extra request headers (e.g. a pretend proxy's X-Forwarded-For)
    public function __construct(private string $base) {}
    public function get(string $action, array $q = []): array { return $this->call('GET', $action, $q); }
    public function useCsrf(string $token): void { $this->csrf = $token; }   // (a token got on another copy)
    public function post(string $action, array $data = [], ?string $file = null, array $form = []): array {
        if ($this->csrf === '') $this->csrf = $this->get('me')['csrf'];
        return $this->call('POST', $action, [], $data, $file, $form);
    }
    public function raw(string $action, array $q = []): array {   // [status, body] for ?a=file
        $ch = $this->handle($this->base . '?' . http_build_query(['a' => $action] + $q));
        $body = curl_exec($ch);
        return [curl_getinfo($ch, CURLINFO_RESPONSE_CODE), $body];
    }
    public function head(string $action, array $q = []): string {   // response headers (for ?a=file)
        $ch = $this->handle($this->base . '?' . http_build_query(['a' => $action] + $q));
        $headers = '';
        curl_setopt($ch, CURLOPT_HEADERFUNCTION, function ($ch, $h) use (&$headers) { $headers .= $h; return strlen($h); });
        curl_exec($ch);
        return $headers;
    }
    public int $status = 0;
    public function cookies(?array $set = null): array { if ($set !== null) $this->cookies = $set; return $this->cookies; }
    private function handle(string $url) {
        $ch = curl_init($url);
        $cookie = implode('; ', array_map(fn($k, $v) => "$k=$v", array_keys($this->cookies), $this->cookies));
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => false,
            CURLOPT_HTTPHEADER => array_merge($this->headers, $cookie ? ["Cookie: $cookie"] : []),
            CURLOPT_HEADERFUNCTION => function ($ch, $h) {
                if (preg_match('/^Set-Cookie:\s*([^=]+)=([^;]*)/i', $h, $m)) $this->cookies[$m[1]] = $m[2];
                return strlen($h);
            }]);
        return $ch;
    }
    private function call(string $method, string $action, array $q, array $data = [], ?string $file = null, array $form = []): array {
        $ch = $this->handle($this->base . '?' . http_build_query(['a' => $action] + $q));
        if ($method === 'POST') {
            $hdr = array_merge($this->headers, ["X-CSRF-Token: {$this->csrf}"]);
            if ($file) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, ['file' => new CURLFile($file)] + $form);
            } else {
                $hdr[] = 'Content-Type: application/json';
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
            }
            $cookie = implode('; ', array_map(fn($k, $v) => "$k=$v", array_keys($this->cookies), $this->cookies));
            if ($cookie) $hdr[] = "Cookie: $cookie";
            curl_setopt($ch, CURLOPT_HTTPHEADER, $hdr);
        }
        $body = (string)curl_exec($ch);
        $this->status = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $json = json_decode($body, true);
        if (!is_array($json)) throw new RuntimeException("$action: not JSON: " . substr($body, 0, 300));
        if (!empty($json['csrf'])) $this->csrf = $json['csrf'];   // signed in or out: a new token comes with the reply (as in app.js)
        return $json;
    }
}

// ---- assertions -------------------------------------------------------------------------
$passed = 0; $failed = 0;
function check(bool $ok, string $what, $detail = null): void {
    global $passed, $failed;
    if ($ok) { $passed++; echo "  ok    $what\n"; return; }
    $failed++;
    echo "  FAIL  $what" . ($detail !== null ? '  -> ' . json_encode($detail, JSON_UNESCAPED_UNICODE) : '') . "\n";
}
function section(string $name): void { echo "\n$name\n"; }
function people(string $base, string ...$names): array {
    $out = [];
    foreach ($names as $i => $n) {
        $c = new Client($base);
        $c->headers = ['X-Forwarded-For: 198.51.100.' . (10 + $i + crc32($n) % 200)];   // each from their own address
        $r = $c->post('register', ['username' => $n, 'password' => 'password1', 'display_name' => ucfirst($n)]);
        if (!$r['ok']) throw new RuntimeException("register $n: " . $r['error']);
        $out[] = $c;
    }
    return $out;
}
// A free port on this computer, and a `php -S` serving toffee from it with these settings.
function free_port(): int {
    $sock = stream_socket_server('tcp://127.0.0.1:0');
    $port = (int)substr(strrchr(stream_socket_get_name($sock, false), ':'), 1);
    fclose($sock);
    return $port;
}
function start_toffee(string $root, string $data, array $env, string $log) {
    $port = free_port();
    $proc = proc_open([PHP_BINARY, '-S', "127.0.0.1:$port", '-t', $root, "$root/router.php"], [1 => ['file', '/dev/null', 'w'], 2 => ['file', $log, 'w']],
        $pipes, $root, ['TOFFEE_DATA' => $data] + $env + getenv());
    for ($i = 0; $i < 50 && @file_get_contents("http://127.0.0.1:$port/healthz") !== "ok\n"; $i++) usleep(100000);
    return [$proc, "http://127.0.0.1:$port/api.php"];
}
