# アーキテクチャドキュメント

## システムアーキテクチャ

### 全体構成

```
┌─────────────┐         ┌──────────────┐         ┌─────────────┐
│             │         │              │         │             │
│   Browser   │◄───────►│  PHP Apache  │◄───────►│    Okta     │
│             │         │  Container   │         │   (IdP)     │
└─────────────┘         └──────────────┘         └─────────────┘
                              │
                              │
                        ┌─────▼──────┐
                        │   Session  │
                        │   Storage  │
                        └────────────┘
```

### コンポーネント

#### 1. Web サーバー層 (Apache)

- **ベースイメージ**: `php:8.3-apache`
- **モジュール**:
  - `mod_rewrite`: URL リライト処理
- **ドキュメントルート**: `/var/www/html/public`

**設定のポイント**:
- `.htaccess` ファイルによるフロントコントローラーパターン
- すべてのリクエストが `index.php` にルーティングされます
- 静的ファイルは直接提供されます

#### 2. アプリケーション層 (PHP)

**主要ファイル**: `public/index.php`

**処理フロー**:

```php
1. セッション開始
2. 環境変数の読み込み
3. OpenIDConnectClient の初期化
4. リクエストパスに基づくルーティング:
   - /                          → トップページ（認証必須）
   - /authorization-code/callback → OAuth コールバック処理
   - /logout                    → ログアウト処理
```

#### 3. 認証層 (OpenID Connect)

**ライブラリ**: `jumbojett/openid-connect-php`

**認証フロー**:

```
1. ユーザーアクセス
   ↓
2. 未認証チェック
   ↓
3. Okta 認証ページへリダイレクト
   ↓
4. ユーザーログイン (Okta)
   ↓
5. コールバック URL へリダイレクト
   (authorization code 付き)
   ↓
6. トークン交換
   (code → access_token, id_token)
   ↓
7. ID トークン検証
   ↓
8. クレーム取得とセッション保存
   ↓
9. トップページへリダイレクト
```

#### 4. セッション管理

**ストレージ**: PHP デフォルトセッション (ファイルベース)

**保存データ**:
```php
$_SESSION = [
    'user' => [
        'email' => string,
        'sub'   => string,
        'name'  => string
    ],
    'id_token' => string  // ログアウト用
];
```

## 認証実装の詳細

### OpenID Connect 設定

```php
$oidc = new OpenIDConnectClient(
    $issuer,        // e.g., https://xxxx.okta.com/oauth2/default
    $clientId,      // Okta アプリケーションのクライアント ID
    $clientSecret   // Okta アプリケーションのクライアントシークレット
);

$oidc->setRedirectURL($appBaseUrl . '/authorization-code/callback');
$oidc->addScope(['openid', 'profile', 'email']);
```

### スコープ

- `openid`: 必須の基本スコープ
- `profile`: ユーザープロフィール情報（name など）
- `email`: メールアドレス

### トークン検証

ライブラリが自動的に以下を検証します:
- ID トークンの署名 (JWS)
- Issuer の検証
- Audience の検証 (Client ID)
- トークンの有効期限

## ログアウト実装

### RP-Initiated Logout

Okta の OIDC 仕様に従った完全なログアウトを実装しています。

**処理手順** (`public/index.php:31-62`):

1. **ID トークンの退避**
   ```php
   $idToken = $_SESSION['id_token'] ?? null;
   ```

2. **アプリケーションセッションの破棄**
   ```php
   $_SESSION = [];
   session_destroy();
   ```

3. **セッションクッキーの削除**
   ```php
   setcookie(session_name(), '', time() - 42000, ...);
   ```

4. **Okta へのログアウトリクエスト**
   ```php
   $logoutEndpoint = rtrim($issuer, '/') . '/v1/logout';
   $params = [
       'post_logout_redirect_uri' => $appBaseUrl . '/',
       'id_token_hint' => $idToken  // オプションだが推奨
   ];
   header('Location: ' . $logoutEndpoint . '?' . http_build_query($params));
   ```

### Okta 側の設定要件

- `post_logout_redirect_uri` は Okta アプリケーション設定の「Sign-out redirect URIs」に登録する必要があります
- 通常は `http://localhost:8080/` を登録します

## Docker 環境

### イメージ構成

**Dockerfile の主要要素**:

```dockerfile
FROM php:8.3-apache

# Apache mod_rewrite 有効化
RUN a2enmod rewrite

# 必要なパッケージとPHP拡張のインストール
RUN apt-get update \
  && apt-get install -y git unzip libzip-dev \
  && docker-php-ext-install zip

# Composer のインストール
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# ドキュメントルートの設定
ENV APACHE_DOCUMENT_ROOT=/var/www/html/public
```

### ボリュームマウント

```yaml
volumes:
  - .:/var/www/html:cached
```

- ソースコードをコンテナにマウント
- `cached` フラグで macOS でのパフォーマンス最適化

### ポート設定

```yaml
ports:
  - "8080:80"
```

- ホストの 8080 ポートをコンテナの 80 ポートにマッピング

## 環境変数

### 必須変数

| 変数名 | 説明 | 例 |
|--------|------|-----|
| `OKTA_ISSUER` | Okta の Issuer URL | `https://dev-12345.okta.com/oauth2/default` |
| `OKTA_CLIENT_ID` | OIDC アプリケーションのクライアント ID | `0oa1a2b3c4d5e6f7g8h9` |
| `OKTA_CLIENT_SECRET` | OIDC アプリケーションのクライアントシークレット | `secret_value` |
| `APP_BASE_URL` | アプリケーションのベース URL | `http://localhost:8080` |

### 環境変数の読み込み

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

環境変数が未設定の場合は HTTP 500 エラーを返します。

## セキュリティ対策

### 1. XSS 対策

```php
$email = htmlspecialchars((string)$user['email'], ENT_QUOTES, 'UTF-8');
```

出力時に HTML エスケープ処理を実施しています。

### 2. 認証状態の検証

```php
$user = $_SESSION['user'] ?? null;
if ($user === null) {
    $oidc->authenticate();
    exit;
}
```

すべてのページで認証状態をチェックし、未認証の場合は Okta にリダイレクトします。

### 3. シークレット管理

- クライアントシークレットは環境変数で管理
- `.env` ファイルは `.gitignore` で除外
- コンテナ環境変数として注入

### 4. HTTPS の使用（本番環境）

本番環境では以下を推奨:
- HTTPS の使用
- Secure フラグ付きクッキー
- HSTS ヘッダーの設定

## 拡張性

### 追加可能な機能

1. **データベース統合**
   - ユーザー情報の永続化
   - セッションストアとしての Redis 使用

2. **追加の認証フロー**
   - ソーシャルログイン (Google, Facebook など)
   - 多要素認証 (MFA)

3. **API エンドポイント**
   - RESTful API の追加
   - トークンベースの認証

4. **ロギング・モニタリング**
   - アクセスログの記録
   - エラートラッキング

## パフォーマンス考慮事項

### 現在の構成

- シングルコンテナ構成
- ファイルベースセッション
- 小規模な POC に適しています

### スケーリングの推奨事項

本番環境やより大規模な展開には:

1. **セッション管理**
   - Redis/Memcached によるセッション共有

2. **ロードバランシング**
   - 複数の PHP-FPM プロセス
   - Nginx/Apache のリバースプロキシ

3. **キャッシング**
   - OPcache の有効化
   - 静的アセットのキャッシング

## トラブルシューティング

### よくある問題

1. **環境変数が読み込まれない**
   - `.env` ファイルの存在確認
   - Docker Compose の再起動

2. **リダイレクトループ**
   - `APP_BASE_URL` の設定確認
   - Okta アプリケーション設定のコールバック URL 確認

3. **ログアウト後も認証状態が残る**
   - ブラウザのキャッシュクリア
   - Okta セッションの確認
