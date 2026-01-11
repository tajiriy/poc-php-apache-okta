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
    session_destroy();
    header('Location: /');
    exit;
}

if ($path === '/authorization-code/callback') {
    // This will exchange the "code" for tokens and validate ID token
    $oidc->authenticate();

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
