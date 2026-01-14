# エンドポイントドキュメント

このアプリケーションは、単一の PHP ファイル（`public/index.php`）で複数のエンドポイントを処理します。

## エンドポイント一覧

| エンドポイント | メソッド | 認証 | 説明 |
|--------------|---------|------|------|
| `/` | GET | 必須 | トップページ（メインアプリケーション） |
| `/authorization-code/callback` | GET | 不要 | OAuth 2.0 認可コードコールバック |
| `/logout` | GET | 不要 | ログアウト処理 |

---

## 1. トップページ

### エンドポイント
```
GET /
```

### 説明
アプリケーションのメインページ。認証済みユーザーの情報を表示します。

### 認証
必須。未認証ユーザーは自動的に Okta ログインページにリダイレクトされます。

### リクエスト
```http
GET / HTTP/1.1
Host: localhost:8080
Cookie: PHPSESSID=abc123...
```

### レスポンス

#### 成功時（認証済み）
**ステータスコード**: `200 OK`

**Content-Type**: `text/html; charset=UTF-8`

**ボディ**:
```html
<!doctype html>
<html lang="ja">
<head>
  <meta charset="utf-8">
  <title>Hello</title>
</head>
<body>
  <p>Hello, user@example.com さん！</p>
  <p><a href="/logout">Logout</a></p>
</body>
</html>
```

#### 未認証時
**ステータスコード**: `302 Found`

**Location**: Okta 認可エンドポイント（例）
```
https://dev-12345.okta.com/oauth2/default/v1/authorize?
  response_type=code&
  client_id=0oa1a2b3c4d5e6f7g8h9&
  redirect_uri=http://localhost:8080/authorization-code/callback&
  scope=openid+profile+email&
  state=random_state_value&
  nonce=random_nonce_value
```

### 処理フロー

```
1. セッションから user 情報を取得
   ↓
2. user が存在するか確認
   ↓
3a. 存在する場合
    → HTML ページを表示
   ↓
3b. 存在しない場合
    → Okta 認可エンドポイントへリダイレクト
```

### 実装（`public/index.php:84-106`）

```php
$user = $_SESSION['user'] ?? null;

if ($user === null) {
    $oidc->authenticate();
    exit;
}

$email = htmlspecialchars((string)$user['email'], ENT_QUOTES, 'UTF-8');
?>
<!doctype html>
<html lang="ja">
...
```

---

## 2. OAuth コールバック

### エンドポイント
```
GET /authorization-code/callback
```

### 説明
Okta からの OAuth 2.0 認可コードフローのコールバックを処理します。認可コードをアクセストークンと ID トークンに交換し、ユーザー情報をセッションに保存します。

### 認証
不要。Okta からのリダイレクトで自動的に呼び出されます。

### リクエスト

#### クエリパラメータ

| パラメータ | 必須 | 説明 |
|-----------|------|------|
| `code` | ✓ | Okta が発行した認可コード |
| `state` | ✓ | CSRF 対策用の state パラメータ |

#### 例
```http
GET /authorization-code/callback?code=abc123def456&state=xyz789 HTTP/1.1
Host: localhost:8080
```

### レスポンス

#### 成功時
**ステータスコード**: `302 Found`

**Location**: `/`（トップページへリダイレクト）

**Set-Cookie**: PHP セッション ID

```http
HTTP/1.1 302 Found
Location: /
Set-Cookie: PHPSESSID=new_session_id; path=/; HttpOnly
```

#### エラー時
**ステータスコード**: `500 Internal Server Error`

認可コードの検証やトークン交換に失敗した場合、エラーが発生します。

### 処理フロー

```
1. Okta からのリダイレクトを受信
   ↓
2. 認可コードを検証（ライブラリが自動処理）
   ↓
3. トークンエンドポイントにリクエスト
   - code をアクセストークンと ID トークンに交換
   ↓
4. ID トークンの検証
   - 署名の検証
   - Issuer の検証
   - Audience の検証
   - 有効期限の検証
   ↓
5. クレーム（ユーザー情報）の取得
   ↓
6. セッションに保存
   - $_SESSION['user']
   - $_SESSION['id_token']
   ↓
7. トップページへリダイレクト
```

### 実装（`public/index.php:64-82`）

```php
if ($path === '/authorization-code/callback') {
    // トークン交換と ID トークン検証
    $oidc->authenticate();

    // ID トークンを保存（ログアウト用）
    $_SESSION['id_token'] = $oidc->getIdToken();

    // クレームを取得
    $claims = $oidc->getVerifiedClaims();

    // ユーザー情報をセッションに保存
    $_SESSION['user'] = [
        'email' => $claims->email ?? '(no email claim)',
        'sub'   => $claims->sub ?? null,
        'name'  => $claims->name ?? null
    ];

    header('Location: /');
    exit;
}
```

### 取得されるクレーム

| クレーム | 説明 | スコープ |
|----------|------|---------|
| `sub` | ユーザーの一意識別子 | `openid` |
| `email` | メールアドレス | `email` |
| `name` | ユーザーの表示名 | `profile` |
| `preferred_username` | ユーザー名 | `profile` |
| `given_name` | 名 | `profile` |
| `family_name` | 姓 | `profile` |

---

## 3. ログアウト

### エンドポイント
```
GET /logout
```

### 説明
アプリケーションからログアウトし、Okta セッションも終了します（RP-Initiated Logout）。

### 認証
不要。誰でもアクセス可能ですが、認証済みセッションがない場合は効果がありません。

### リクエスト
```http
GET /logout HTTP/1.1
Host: localhost:8080
Cookie: PHPSESSID=abc123...
```

### レスポンス

#### 成功時
**ステータスコード**: `302 Found`

**Location**: Okta ログアウトエンドポイント

```http
HTTP/1.1 302 Found
Location: https://dev-12345.okta.com/oauth2/default/v1/logout?
  post_logout_redirect_uri=http://localhost:8080/&
  id_token_hint=eyJhbGciOiJSUzI1NiIs...
Set-Cookie: PHPSESSID=deleted; expires=Thu, 01-Jan-1970 00:00:01 GMT
```

### 処理フロー

```
1. セッションから ID トークンを取得
   ↓
2. セッション配列をクリア
   ↓
3. セッションクッキーを削除
   ↓
4. セッションを破棄
   ↓
5. Okta ログアウトエンドポイントへリダイレクト
   - post_logout_redirect_uri: ログアウト後のリダイレクト先
   - id_token_hint: どのセッションを終了するかを特定
   ↓
6. Okta がセッションを終了
   ↓
7. post_logout_redirect_uri へリダイレクト（トップページ）
```

### 実装（`public/index.php:31-62`）

```php
if ($path === '/logout') {
    // ID トークンを退避
    $idToken = $_SESSION['id_token'] ?? null;

    // セッションをクリア
    $_SESSION = [];

    // クッキーを削除
    if (ini_get("session.use_cookies")) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000,
            $params["path"], $params["domain"],
            $params["secure"], $params["httponly"]
        );
    }

    // セッションを破棄
    session_destroy();

    // Okta ログアウトエンドポイント
    $logoutEndpoint = rtrim($issuer, '/') . '/v1/logout';

    // パラメータ
    $params = [
        'post_logout_redirect_uri' => $appBaseUrl . '/'
    ];
    if ($idToken) {
        $params['id_token_hint'] = $idToken;
    }

    // リダイレクト
    header('Location: ' . $logoutEndpoint . '?' . http_build_query($params));
    exit;
}
```

### Okta ログアウトエンドポイント

#### URL 形式
```
{issuer}/v1/logout
```

例:
```
https://dev-12345.okta.com/oauth2/default/v1/logout
```

#### クエリパラメータ

| パラメータ | 必須 | 説明 |
|-----------|------|------|
| `id_token_hint` | 推奨 | ログアウトするセッションの ID トークン |
| `post_logout_redirect_uri` | 推奨 | ログアウト後のリダイレクト先 URL |
| `state` | 任意 | クライアントに返される状態値 |

#### 設定要件

`post_logout_redirect_uri` は Okta アプリケーション設定の **Sign-out redirect URIs** に事前登録が必要です。

---

## エラーレスポンス

### 環境変数エラー

必須の環境変数が設定されていない場合:

**ステータスコード**: `500 Internal Server Error`

**Content-Type**: `text/plain`

**ボディ**:
```
Missing env: OKTA_ISSUER
```

### 実装
```php
function envOrFail(string $key): string {
    $v = getenv($key);
    if ($v === false || $v === '') {
        http_response_code(500);
        echo "Missing env: {$key}";
        exit;
    }
    return $v;
}
```

---

## セッション管理

### セッションデータ構造

```php
$_SESSION = [
    // ユーザー情報
    'user' => [
        'email' => 'user@example.com',
        'sub'   => '00u1a2b3c4d5e6f7g8h9',
        'name'  => 'John Doe'
    ],

    // ID トークン（ログアウト用）
    'id_token' => 'eyJhbGciOiJSUzI1NiIsInR5cCI6IkpXVCJ9...'
];
```

### セッションライフサイクル

1. **開始**: トップページまたはコールバックで `session_start()`
2. **保存**: コールバックでユーザー情報と ID トークンを保存
3. **検証**: 各リクエストで `$_SESSION['user']` の存在を確認
4. **破棄**: ログアウト時に `session_destroy()`

---

## セキュリティ

### CSRF 対策

OAuth フローには `state` パラメータが含まれ、`jumbojett/openid-connect-php` ライブラリが自動的に検証します。

### XSS 対策

出力時に HTML エスケープを実施:
```php
$email = htmlspecialchars((string)$user['email'], ENT_QUOTES, 'UTF-8');
```

### セッション固定攻撃対策

認証成功後、新しいセッション ID が発行されます（PHP のデフォルト動作）。

---

## テスト方法

### 1. トップページのテスト

```bash
curl -i http://localhost:8080/
```

未認証の場合、302 リダイレクトが返されます。

### 2. ログアウトのテスト

ブラウザでログイン後:
```bash
curl -i -b cookies.txt http://localhost:8080/logout
```

### 3. セッション確認

```bash
# PHP コンテナに入る
docker-compose exec php bash

# セッションファイルを確認
ls -la /tmp/
cat /tmp/sess_*
```

---

## API 仕様まとめ

### 認証フロー全体

```
1. ユーザーが / にアクセス
   ↓
2. 未認証のため、Okta 認可エンドポイントへリダイレクト
   ↓
3. ユーザーが Okta でログイン
   ↓
4. Okta が /authorization-code/callback へリダイレクト（code 付き）
   ↓
5. アプリケーションがトークンを取得・検証
   ↓
6. セッションにユーザー情報を保存
   ↓
7. / へリダイレクト
   ↓
8. 認証済みユーザーとしてページを表示
```

### ログアウトフロー

```
1. ユーザーが /logout にアクセス
   ↓
2. アプリケーションセッションを破棄
   ↓
3. Okta ログアウトエンドポイントへリダイレクト
   ↓
4. Okta がセッションを終了
   ↓
5. / へリダイレクト
   ↓
6. 未認証状態でトップページにアクセス
   ↓
7. Okta ログインページへリダイレクト
```
