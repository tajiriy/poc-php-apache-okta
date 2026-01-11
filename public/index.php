<?php
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Jumbojett\OpenIDConnectClient;

session_start();

function envOrFail(string $key): string {
    $v = getenv($key);
    if ($v === false || $v === '') {
        http_response_code(500);
        echo "Missing env: {$key}";
        exit;
    }
    return $v;
}

$issuer       = rtrim(envOrFail('OKTA_ISSUER'), '/');          // e.g. https://xxxx.okta.com/oauth2/default
$clientId     = envOrFail('OKTA_CLIENT_ID');
$clientSecret = envOrFail('OKTA_CLIENT_SECRET');
$appBaseUrl   = rtrim(envOrFail('APP_BASE_URL'), '/');         // http://localhost:8080

$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?? '/';

$oidc = new OpenIDConnectClient($issuer, $clientId, $clientSecret);
$oidc->setRedirectURL($appBaseUrl . '/authorization-code/callback');
$oidc->addScope(['openid', 'profile', 'email']);

if ($path === '/logout') {
    // 退避（Oktaへ渡す）
    $idToken = $_SESSION['id_token'] ?? null;

    // 1) アプリ側セッションを終了
    $_SESSION = [];
    if (ini_get("session.use_cookies")) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000,
            $params["path"], $params["domain"],
            $params["secure"], $params["httponly"]
        );
    }
    session_destroy();

    // 2) Oktaセッションも終了（RP-Initiated Logout）
    // Oktaの end session endpoint に飛ばす
    // issuer が .../oauth2/default なら、logout は .../oauth2/default/v1/logout が基本形
    $logoutEndpoint = rtrim($issuer, '/') . '/v1/logout';

    // Oktaは id_token_hint と post_logout_redirect_uri を推奨/要求
    // post_logout_redirect_uri は Oktaアプリ設定に登録済みであること
    $params = [
        'post_logout_redirect_uri' => $appBaseUrl . '/'
    ];
    if ($idToken) {
        $params['id_token_hint'] = $idToken;
    }

    header('Location: ' . $logoutEndpoint . '?' . http_build_query($params));
    exit;
}

if ($path === '/authorization-code/callback') {
    // This will exchange the "code" for tokens and validate ID token
    $oidc->authenticate();

    // ★追加：Oktaログアウトに必要
    $_SESSION['id_token'] = $oidc->getIdToken();

    // claims (contains email if scope includes "email")
    $claims = $oidc->getVerifiedClaims();

    $_SESSION['user'] = [
        'email' => $claims->email ?? '(no email claim)',
        'sub'   => $claims->sub ?? null,
        'name'  => $claims->name ?? null
    ];

    header('Location: /');
    exit;
}

// Top page: require login
$user = $_SESSION['user'] ?? null;

if ($user === null) {
    // redirect to Okta login
    $oidc->authenticate();
    // authenticate() normally redirects, but just in case:
    exit;
}

$email = htmlspecialchars((string)$user['email'], ENT_QUOTES, 'UTF-8');
?>
<!doctype html>
<html lang="ja">
<head>
  <meta charset="utf-8">
  <title>Hello</title>
</head>
<body>
  <p>Hello, <?= $email ?> さん！</p>
  <p><a href="/logout">Logout</a></p>
</body>
</html>
