<?php
declare(strict_types=1);

/**
 * Pure PHP OIDC Authentication for SPIRAL ver.1
 *
 * SPIRAL ver.1 の制約に対応した実装:
 * - header() 使用不可 → JavaScript/Meta タグでリダイレクト
 * - file_get_contents 使用不可 → cURL を使用
 * - openssl_pkey_get_public 使用不可の可能性 → PEM を直接構築
 * - Composer 使用不可 → 外部ライブラリなし
 */

session_start();

// ============================================================================
// Configuration
// ============================================================================

/**
 * 環境変数から設定を取得（SPIRAL では $SPIRAL->getEnvValue() 等に置き換え）
 */
function getConfig(): array {
    // ローカル環境用: 環境変数から取得
    $issuer = getenv('OKTA_ISSUER');
    $clientId = getenv('OKTA_CLIENT_ID');
    $clientSecret = getenv('OKTA_CLIENT_SECRET');
    $appBaseUrl = getenv('APP_BASE_URL');

    // SPIRAL 環境では以下のように直接記述または SPIRAL の設定機能を使用
    // $issuer = 'https://your-domain.okta.com/oauth2/default';
    // $clientId = 'your-client-id';
    // $clientSecret = 'your-client-secret';
    // $appBaseUrl = 'https://your-spiral-site.com';

    if (!$issuer || !$clientId || !$clientSecret || !$appBaseUrl) {
        throw new RuntimeException('Missing required configuration');
    }

    return [
        'issuer' => rtrim($issuer, '/'),
        'client_id' => $clientId,
        'client_secret' => $clientSecret,
        'app_base_url' => rtrim($appBaseUrl, '/'),
        'redirect_uri' => rtrim($appBaseUrl, '/') . '/authorization-code/callback',
        'scopes' => ['openid', 'profile', 'email'],
    ];
}

// ============================================================================
// HTTP Client (cURL)
// ============================================================================

/**
 * cURL で GET リクエストを実行
 */
function httpGet(string $url): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_HTTPHEADER => ['Accept: application/json'],
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    if ($response === false) {
        throw new RuntimeException("HTTP request failed: {$error}");
    }

    return ['body' => $response, 'status' => $httpCode];
}

/**
 * cURL で POST リクエストを実行
 */
function httpPost(string $url, array $data, array $headers = []): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query($data),
        CURLOPT_HTTPHEADER => array_merge(
            ['Content-Type: application/x-www-form-urlencoded', 'Accept: application/json'],
            $headers
        ),
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    if ($response === false) {
        throw new RuntimeException("HTTP request failed: {$error}");
    }

    return ['body' => $response, 'status' => $httpCode];
}

// ============================================================================
// OIDC Discovery
// ============================================================================

/**
 * OIDC Discovery ドキュメントを取得
 */
function getOidcConfig(string $issuer): array {
    $wellKnownUrl = $issuer . '/.well-known/openid-configuration';
    $result = httpGet($wellKnownUrl);

    if ($result['status'] !== 200) {
        throw new RuntimeException("Failed to fetch OIDC config: HTTP {$result['status']}");
    }

    $config = json_decode($result['body'], true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        throw new RuntimeException('Invalid JSON in OIDC configuration');
    }

    return $config;
}

/**
 * JWKS を取得
 */
function getJwks(string $jwksUri): array {
    $result = httpGet($jwksUri);

    if ($result['status'] !== 200) {
        throw new RuntimeException("Failed to fetch JWKS: HTTP {$result['status']}");
    }

    $jwks = json_decode($result['body'], true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        throw new RuntimeException('Invalid JSON in JWKS');
    }

    return $jwks;
}

// ============================================================================
// JWT Handling
// ============================================================================

/**
 * Base64URL デコード
 */
function base64UrlDecode(string $data): string {
    $remainder = strlen($data) % 4;
    if ($remainder) {
        $data .= str_repeat('=', 4 - $remainder);
    }
    return base64_decode(strtr($data, '-_', '+/'));
}

/**
 * ASN.1 長さエンコーディング
 */
function asn1Length(int $length): string {
    if ($length < 128) {
        return chr($length);
    }
    $temp = ltrim(pack('N', $length), "\x00");
    return chr(0x80 | strlen($temp)) . $temp;
}

/**
 * JWK (RSA) を PEM 形式の公開鍵に変換
 * openssl_pkey_get_public() を使わずに PEM を直接構築
 */
function jwkToPem(array $jwk): string {
    if (($jwk['kty'] ?? '') !== 'RSA') {
        throw new RuntimeException('Unsupported key type: ' . ($jwk['kty'] ?? 'unknown'));
    }

    // モジュラス (n) と公開指数 (e) をデコード
    $n = base64UrlDecode($jwk['n']);
    $e = base64UrlDecode($jwk['e']);

    // 先頭の 0x00 を除去（ただし最上位ビットが 1 なら 0x00 を追加）
    $modulus = ltrim($n, "\x00");
    if (ord($modulus[0]) > 0x7f) {
        $modulus = "\x00" . $modulus;
    }

    $exponent = ltrim($e, "\x00");
    if (ord($exponent[0]) > 0x7f) {
        $exponent = "\x00" . $exponent;
    }

    // RSA 公開鍵を ASN.1 DER 形式で構築
    $modulusLen = strlen($modulus);
    $exponentLen = strlen($exponent);

    // SEQUENCE { INTEGER modulus, INTEGER exponent }
    $rsaPublicKey =
        "\x30" . asn1Length($modulusLen + $exponentLen + 4) .
        "\x02" . asn1Length($modulusLen) . $modulus .
        "\x02" . asn1Length($exponentLen) . $exponent;

    // RSA OID: 1.2.840.113549.1.1.1 + NULL
    $rsaOid = "\x30\x0d\x06\x09\x2a\x86\x48\x86\xf7\x0d\x01\x01\x01\x05\x00";

    // BIT STRING
    $bitString = "\x00" . $rsaPublicKey;
    $bitStringLen = strlen($bitString);

    // SubjectPublicKeyInfo
    $publicKeyInfo =
        "\x30" . asn1Length(strlen($rsaOid) + $bitStringLen + 2) .
        $rsaOid .
        "\x03" . asn1Length($bitStringLen) . $bitString;

    return "-----BEGIN PUBLIC KEY-----\n" .
           chunk_split(base64_encode($publicKeyInfo), 64, "\n") .
           "-----END PUBLIC KEY-----";
}

/**
 * JWT をデコード（署名検証なし）
 */
function decodeJwt(string $jwt): array {
    $parts = explode('.', $jwt);
    if (count($parts) !== 3) {
        throw new RuntimeException('Invalid JWT format');
    }

    $header = json_decode(base64UrlDecode($parts[0]), true);
    $payload = json_decode(base64UrlDecode($parts[1]), true);

    if (!$header || !$payload) {
        throw new RuntimeException('Invalid JWT encoding');
    }

    return [
        'header' => $header,
        'payload' => $payload,
        'signature' => $parts[2],
        'signed_data' => $parts[0] . '.' . $parts[1],
    ];
}

/**
 * JWT の署名を検証
 */
function verifyJwtSignature(string $jwt, array $jwks): array {
    $decoded = decodeJwt($jwt);
    $header = $decoded['header'];

    // kid でマッチする鍵を探す
    $key = null;
    foreach ($jwks['keys'] as $jwk) {
        if (isset($header['kid']) && isset($jwk['kid']) && $header['kid'] === $jwk['kid']) {
            $key = $jwk;
            break;
        }
    }

    // kid が見つからない場合、最初の RSA 署名鍵を使用
    if (!$key) {
        foreach ($jwks['keys'] as $jwk) {
            if (($jwk['use'] ?? 'sig') === 'sig' && ($jwk['kty'] ?? '') === 'RSA') {
                $key = $jwk;
                break;
            }
        }
    }

    if (!$key) {
        throw new RuntimeException('No matching key found in JWKS');
    }

    // PEM 形式の公開鍵を構築
    $pem = jwkToPem($key);

    // アルゴリズムを確認
    $alg = $header['alg'] ?? 'RS256';
    switch ($alg) {
        case 'RS256':
            $algorithm = OPENSSL_ALGO_SHA256;
            break;
        case 'RS384':
            $algorithm = OPENSSL_ALGO_SHA384;
            break;
        case 'RS512':
            $algorithm = OPENSSL_ALGO_SHA512;
            break;
        default:
            throw new RuntimeException("Unsupported algorithm: {$alg}");
    }

    // 署名を検証
    $signature = base64UrlDecode($decoded['signature']);
    $verified = openssl_verify($decoded['signed_data'], $signature, $pem, $algorithm);

    if ($verified !== 1) {
        throw new RuntimeException('JWT signature verification failed');
    }

    return $decoded['payload'];
}

/**
 * JWT の claims を検証
 */
function validateClaims(array $claims, string $issuer, string $clientId, string $nonce): void {
    $now = time();

    // Issuer
    if (($claims['iss'] ?? '') !== $issuer) {
        throw new RuntimeException('Invalid issuer');
    }

    // Audience
    $aud = $claims['aud'] ?? '';
    if (is_array($aud)) {
        if (!in_array($clientId, $aud, true)) {
            throw new RuntimeException('Invalid audience');
        }
    } else {
        if ($aud !== $clientId) {
            throw new RuntimeException('Invalid audience');
        }
    }

    // Expiration (exp)
    if (($claims['exp'] ?? 0) < $now) {
        throw new RuntimeException('Token has expired');
    }

    // Issued At (iat) - 5分の余裕を持たせる
    if (isset($claims['iat']) && $claims['iat'] > ($now + 300)) {
        throw new RuntimeException('Token issued in the future');
    }

    // Nonce
    if (($claims['nonce'] ?? '') !== $nonce) {
        throw new RuntimeException('Invalid nonce');
    }
}

// ============================================================================
// Token Exchange
// ============================================================================

/**
 * Authorization Code をトークンに交換
 */
function exchangeCodeForTokens(array $config, array $oidcConfig, string $code): array {
    $result = httpPost($oidcConfig['token_endpoint'], [
        'grant_type' => 'authorization_code',
        'code' => $code,
        'redirect_uri' => $config['redirect_uri'],
        'client_id' => $config['client_id'],
        'client_secret' => $config['client_secret'],
    ]);

    if ($result['status'] !== 200) {
        throw new RuntimeException("Token exchange failed: HTTP {$result['status']} - {$result['body']}");
    }

    $tokens = json_decode($result['body'], true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        throw new RuntimeException('Invalid JSON in token response');
    }

    if (isset($tokens['error'])) {
        throw new RuntimeException("Token error: {$tokens['error']} - " . ($tokens['error_description'] ?? ''));
    }

    return $tokens;
}

// ============================================================================
// Redirect Helper (SPIRAL 対応: header() の代わりに JavaScript/Meta を使用)
// ============================================================================

/**
 * JavaScript でリダイレクト（header() が使えない環境用）
 * セッションを明示的に保存してからリダイレクト
 */
function renderRedirect(string $url): void {
    // セッションデータを確実に保存
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }

    $escapedUrl = htmlspecialchars($url, ENT_QUOTES, 'UTF-8');
    echo <<<HTML
<!doctype html>
<html lang="ja">
<head>
  <meta charset="utf-8">
  <meta http-equiv="refresh" content="0;url={$escapedUrl}">
  <title>Redirecting...</title>
</head>
<body>
  <p>Redirecting...</p>
  <script>window.location.href = "{$escapedUrl}";</script>
</body>
</html>
HTML;
    exit;
}

/**
 * エラーページを表示
 */
function renderError(string $message): void {
    $escapedMessage = htmlspecialchars($message, ENT_QUOTES, 'UTF-8');
    echo <<<HTML
<!doctype html>
<html lang="ja">
<head>
  <meta charset="utf-8">
  <title>Error</title>
</head>
<body>
  <h1>Error</h1>
  <p>{$escapedMessage}</p>
  <p><a href="/">Back to Home</a></p>
</body>
</html>
HTML;
    exit;
}

// ============================================================================
// Random String Generator
// ============================================================================

/**
 * 暗号学的に安全なランダム文字列を生成
 */
function generateRandomString(int $length = 32): string {
    return bin2hex(random_bytes($length));
}

// ============================================================================
// Main Application
// ============================================================================

try {
    $config = getConfig();
    $path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?? '/';

    // OIDC Discovery を取得
    $oidcConfig = getOidcConfig($config['issuer']);

    // ========================================================================
    // Route: /logout
    // ========================================================================
    if ($path === '/logout') {
        $idToken = $_SESSION['id_token'] ?? null;

        // セッションをクリア
        $_SESSION = [];
        session_destroy();

        // Okta のログアウトエンドポイントにリダイレクト
        $logoutEndpoint = $oidcConfig['end_session_endpoint']
            ?? $config['issuer'] . '/v1/logout';

        $logoutParams = [
            'post_logout_redirect_uri' => $config['app_base_url'] . '/',
        ];
        if ($idToken) {
            $logoutParams['id_token_hint'] = $idToken;
        }

        renderRedirect($logoutEndpoint . '?' . http_build_query($logoutParams));
    }

    // ========================================================================
    // Route: /authorization-code/callback
    // ========================================================================
    if ($path === '/authorization-code/callback') {
        // エラーチェック
        if (isset($_GET['error'])) {
            throw new RuntimeException(
                "Authorization error: {$_GET['error']} - " . ($_GET['error_description'] ?? '')
            );
        }

        // State 検証 (CSRF 対策)
        $state = $_GET['state'] ?? '';
        $expectedState = $_SESSION['oidc_state'] ?? '';
        if (empty($state) || $state !== $expectedState) {
            throw new RuntimeException('Invalid state parameter');
        }

        // Authorization Code を取得
        $code = $_GET['code'] ?? '';
        if (empty($code)) {
            throw new RuntimeException('Missing authorization code');
        }

        // トークン交換
        $tokens = exchangeCodeForTokens($config, $oidcConfig, $code);

        $idToken = $tokens['id_token'] ?? null;
        if (!$idToken) {
            throw new RuntimeException('No ID token in response');
        }

        // JWKS を取得して署名検証
        $jwks = getJwks($oidcConfig['jwks_uri']);
        $claims = verifyJwtSignature($idToken, $jwks);

        // Claims 検証
        $expectedNonce = $_SESSION['oidc_nonce'] ?? '';
        validateClaims($claims, $config['issuer'], $config['client_id'], $expectedNonce);

        // セッションから一時データを削除
        unset($_SESSION['oidc_state'], $_SESSION['oidc_nonce']);

        // ユーザー情報をセッションに保存
        $_SESSION['id_token'] = $idToken;
        $_SESSION['user'] = [
            'email' => $claims['email'] ?? '(no email claim)',
            'sub' => $claims['sub'] ?? null,
            'name' => $claims['name'] ?? null,
        ];

        renderRedirect('/');
    }

    // ========================================================================
    // Route: / (Home - 認証必須)
    // ========================================================================
    $user = $_SESSION['user'] ?? null;

    if ($user === null) {
        // State と Nonce を生成してセッションに保存
        $state = generateRandomString(16);
        $nonce = generateRandomString(16);
        $_SESSION['oidc_state'] = $state;
        $_SESSION['oidc_nonce'] = $nonce;

        // 認証 URL を構築
        $authUrl = $oidcConfig['authorization_endpoint'] . '?' . http_build_query([
            'response_type' => 'code',
            'client_id' => $config['client_id'],
            'redirect_uri' => $config['redirect_uri'],
            'scope' => implode(' ', $config['scopes']),
            'state' => $state,
            'nonce' => $nonce,
        ]);

        renderRedirect($authUrl);
    }

    // 認証済みユーザーの表示
    $email = htmlspecialchars((string)$user['email'], ENT_QUOTES, 'UTF-8');
    $name = htmlspecialchars((string)($user['name'] ?? ''), ENT_QUOTES, 'UTF-8');

} catch (Throwable $e) {
    renderError($e->getMessage());
}
?>
<!doctype html>
<html lang="ja">
<head>
  <meta charset="utf-8">
  <title>Hello (Pure PHP OIDC for SPIRAL)</title>
</head>
<body>
  <h1>Hello, <?= $email ?> さん！</h1>
  <?php if ($name): ?>
  <p>Name: <?= $name ?></p>
  <?php endif; ?>
  <p><small>Pure PHP implementation (SPIRAL ver.1 compatible)</small></p>
  <p><a href="/logout">Logout</a></p>
</body>
</html>
