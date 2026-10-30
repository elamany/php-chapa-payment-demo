<?php
/**
 * config.php — shared bootstrap.
 * Loads .env, defines helpers, opens the PDO connection.
 * MUST NOT produce any output.
 */

declare(strict_types=1);

/* ---------- Errors ---------- */
ini_set('display_errors', '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);

/* ---------- Load .env ---------- */
(function () {
    $path = __DIR__ . '/.env';
    if (!is_file($path) || !is_readable($path)) return;

    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if (!$lines) return;

    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#' || strpos($line, '=') === false) continue;

        [$k, $v] = explode('=', $line, 2);
        $k = trim($k);
        $v = trim($v);

        if ($k === '') continue;

        $len = strlen($v);
        if ($len >= 2) {
            $first = $v[0];
            $last  = $v[$len - 1];
            if (($first === '"' && $last === '"') || ($first === "'" && $last === "'")) {
                $v = substr($v, 1, -1);
            }
        }

        if (getenv($k) === false) putenv("$k=$v");
        $_ENV[$k] = $v;
    }
})();

/* ---------- Helpers ---------- */

function env(string $key, ?string $default = null): ?string
{
    $v = getenv($key);
    return ($v === false || $v === '') ? $default : $v;
}

function db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) return $pdo;

    $host    = env('DB_HOST', '127.0.0.1');
    $port    = env('DB_PORT', '3306');
    $name    = env('DB_NAME', 'donate');
    $user    = env('DB_USER', '');
    $pass    = env('DB_PASS', '');
    $charset = env('DB_CHARSET', 'utf8mb4');

    $dsn = "mysql:host={$host};port={$port};dbname={$name};charset={$charset}";

    $pdo = new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
        PDO::ATTR_STRINGIFY_FETCHES  => false,
    ]);

    return $pdo;
}

function ensureSession(): void
{
    if (session_status() === PHP_SESSION_NONE) {
        $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
               || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');

        session_set_cookie_params([
            'lifetime' => 0,
            'path'     => '/',
            'secure'   => $secure,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        session_start();
    }
}

function csrfToken(): string
{
    ensureSession();
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrfValidate(?string $token): bool
{
    ensureSession();
    $expected = (string)($_SESSION['csrf_token'] ?? '');
    if ($expected === '' || $token === null || $token === '') return false;
    return hash_equals($expected, $token);
}

function throttle(string $key, int $seconds): bool
{
    ensureSession();
    $now  = time();
    $last = (int)($_SESSION['_throttle'][$key] ?? 0);
    if ($last && ($now - $last) < $seconds) return false;
    $_SESSION['_throttle'][$key] = $now;
    return true;
}

function clientIp(): string
{
    $ip = (string)($_SERVER['REMOTE_ADDR'] ?? '');
    return filter_var($ip, FILTER_VALIDATE_IP) ? $ip : '';
}

function baseUrl(): string
{
    $configured = env('BASE_URL');
    if ($configured) return rtrim($configured, '/');

    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $dir    = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'])), '/');
    return $scheme . '://' . $host . $dir;
}

function jsonOut(array $data, int $code = 200): void
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function chapaRequest(string $path, ?array $body = null): ?array
{
    $key = env('CHAPA_SECRET_KEY');
    if (!$key) {
        error_log('[chapa] CHAPA_SECRET_KEY not configured');
        return null;
    }

    $ch = curl_init('https://api.chapa.co/v1/' . ltrim($path, '/'));

    $headers = [
        'Authorization: Bearer ' . $key,
        'Accept: application/json',
    ];

    if ($body !== null) {
        $headers[] = 'Content-Type: application/json';
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
    }

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
    ]);

    $response = curl_exec($ch);
    $err      = curl_error($ch);
    $code     = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($err) {
        error_log('[chapa] curl error: ' . $err);
        return null;
    }

    $json = json_decode((string)$response, true);
    if (!is_array($json)) {
        error_log('[chapa] non-JSON response HTTP ' . $code);
        return null;
    }

    return ['code' => $code, 'body' => $json];
}

function validTxRef(string $txRef): bool
{
    return (bool)preg_match('/^DON-\d{12}-[a-f0-9]{8}$/', $txRef);
}

/**
 * Extract the Chapa reference from an /initialize response.
 *
 * Chapa's init payload doesn't always include a dedicated `reference`
 * field — when it's absent we take the last path segment of
 * `checkout_url`, which is the Chapa checkout-session token. The
 * canonical reference (`data.reference`) arrives later in the
 * /verify response and overwrites this value.
 */
function extractChapaRefFromInit(array $initBody): ?string
{
    $ref = $initBody['data']['reference'] ?? null;
    if (is_string($ref) && $ref !== '') return $ref;

    $url = $initBody['data']['checkout_url'] ?? null;
    if (!is_string($url) || $url === '') return null;

    $path = parse_url($url, PHP_URL_PATH);
    if (!$path) return null;

    $segments = array_values(array_filter(explode('/', trim($path, '/'))));
    if (!$segments) return null;

    return (string)end($segments);
}


/**
 * Map Chapa's verify status → our DB status.
 * Case-insensitive and tolerant of the variant strings Chapa uses
 * in different environments ("success" / "successful" / "completed").
 */
function mapChapaStatus(string $chapaStatus): string
{
    $s = strtolower(trim($chapaStatus));

    return match (true) {
        in_array($s, ['success', 'successful', 'completed', 'paid', 'settled'], true) => 'paid',
        in_array($s, ['failed', 'cancelled', 'canceled', 'reversed', 'error'], true) => 'failed',
        default => 'pending',
    };
}

/**
 * Persist a /verify result. Idempotent — never demotes a paid row,
 * never clobbers an existing chapa_ref with an empty value.
 *
 * Returns the *actual* status read back from the DB after the update,
 * so callers always render the true state even if the UPDATE failed
 * silently on some column.
 */
function applyVerifyResult(string $txRef, array $verifyBody, ?string $fallbackStatus = null): string
{
    $chapaStatus = (string)($verifyBody['data']['status'] ?? 'unknown');
    $newStatus   = mapChapaStatus($chapaStatus);
    $chapaRef    = trim((string)($verifyBody['data']['reference'] ?? ''));
    $rawJson     = json_encode($verifyBody, JSON_UNESCAPED_SLASHES);

    error_log(sprintf(
        '[donate/verify] tx_ref=%s chapa_status="%s" → "%s" chapa_ref="%s"',
        $txRef, $chapaStatus, $newStatus, $chapaRef !== '' ? $chapaRef : '(empty)'
    ));

    /* ---------- Attempt the rich UPDATE (status + ref + raw + paid_at) ---------- */
    try {
        $sets   = ['status = ?'];
        $params = [$newStatus];

        if ($chapaRef !== '') {
            $sets[]   = 'chapa_ref = ?';
            $params[] = $chapaRef;
        }

        $sets[]   = 'raw_verify = ?';
        $params[] = $rawJson;

        if ($newStatus === 'paid') {
            $sets[] = 'paid_at = COALESCE(paid_at, NOW())';
        }

        $params[] = $txRef;

        $sql = 'UPDATE donations SET ' . implode(', ', $sets)
             . " WHERE tx_ref = ? AND status <> 'paid'";

        $stmt = db()->prepare($sql);
        $stmt->execute($params);
        error_log("[donate/verify] rich UPDATE affected={$stmt->rowCount()}");
    } catch (Throwable $e) {
        error_log('[donate/verify] rich UPDATE FAILED: ' . $e->getMessage());

        /* Fallback: at minimum flip the status (covers missing chapa_ref / raw_verify columns) */
        try {
            $stmt = db()->prepare(
                "UPDATE donations SET status = ? WHERE tx_ref = ? AND status <> 'paid'"
            );
            $stmt->execute([$newStatus, $txRef]);
            error_log("[donate/verify] fallback UPDATE affected={$stmt->rowCount()}");
        } catch (Throwable $e2) {
            error_log('[donate/verify] fallback UPDATE also FAILED: ' . $e2->getMessage());
        }
    }

    /* ---------- Read back the real DB state ---------- */
    try {
        $check = db()->prepare("SELECT status FROM donations WHERE tx_ref = ? LIMIT 1");
        $check->execute([$txRef]);
        $actual = $check->fetchColumn();

        if ($actual !== false && $actual !== null) {
            error_log("[donate/verify] post-update DB status='{$actual}' (intended '{$newStatus}')");
            return (string)$actual;
        }
    } catch (Throwable $e) {
        error_log('[donate/verify] read-back failed: ' . $e->getMessage());
    }

    return $fallbackStatus ?? $newStatus;
}