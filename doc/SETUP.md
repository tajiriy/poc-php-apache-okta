# セットアップガイド

## 1. 前提条件

### システム要件

- **Docker**: 20.10 以降
- **Docker Compose**: 2.0 以降
- **Git**: 2.0 以降

### Okta アカウント

- Okta 開発者アカウント（無料で作成可能）
- 組織の Okta ドメイン（例: `https://dev-12345.okta.com`）

## 2. Okta アプリケーションの設定

### 2.1 Okta 開発者コンソールにログイン

1. https://developer.okta.com/ にアクセス
2. アカウントでログイン

### 2.2 OIDC アプリケーションの作成

1. **Applications** > **Create App Integration** をクリック
2. **Sign-in method** で **OIDC - OpenID Connect** を選択
3. **Application type** で **Web Application** を選択
4. **Next** をクリック

### 2.3 アプリケーション設定

以下の設定を入力します：

**一般設定**:
- **App integration name**: `PHP Apache POC`（任意の名前）

**Sign-in redirect URIs**:
```
http://localhost:8080/authorization-code/callback
```

**Sign-out redirect URIs**:
```
http://localhost:8080/
```

**Assignments**:
- **Controlled access**:
  - **Allow everyone in your organization to access**: チェック
  - または特定のグループを指定

### 2.4 認証情報の取得

アプリケーション作成後、以下の情報を控えます：

1. **Client ID**: アプリケーション詳細ページに表示されます
2. **Client Secret**: 「Client Credentials」セクションで確認
3. **Okta Domain**: 組織の Okta ドメイン（例: `dev-12345.okta.com`）

### 2.5 Authorization Server の確認

1. **Security** > **API** に移動
2. **Authorization Servers** タブを選択
3. **default** サーバーを使用する場合、Issuer URI は以下の形式:
   ```
   https://{yourOktaDomain}/oauth2/default
   ```

## 3. ローカル環境のセットアップ

### 3.1 リポジトリのクローン

```bash
git clone <repository-url>
cd poc-php-apache-okta
```

### 3.2 環境変数ファイルの作成

プロジェクトルートに `.env` ファイルを作成します：

```bash
touch .env
```

以下の内容を `.env` ファイルに記述します：

```bash
# Okta Configuration
OKTA_ISSUER=https://{yourOktaDomain}/oauth2/default
OKTA_CLIENT_ID={yourClientId}
OKTA_CLIENT_SECRET={yourClientSecret}

# Application Configuration
APP_BASE_URL=http://localhost:8080
```

**置き換える値**:
- `{yourOktaDomain}`: あなたの Okta ドメイン（例: `dev-12345.okta.com`）
- `{yourClientId}`: Okta アプリケーションのクライアント ID
- `{yourClientSecret}`: Okta アプリケーションのクライアントシークレット

### 3.3 依存関係のインストール

Docker コンテナ内で Composer 依存関係をインストールします：

```bash
docker-compose run --rm php composer install
```

または、コンテナ起動後に手動でインストール：

```bash
docker-compose up -d
docker-compose exec php composer install
```

### 3.4 アプリケーションの起動

```bash
docker-compose up -d
```

コンテナの状態を確認：

```bash
docker-compose ps
```

出力例：
```
NAME                COMMAND                  SERVICE             STATUS              PORTS
poc-php-apache-okta-php-1   "docker-php-entrypoint apache2-foreground"   php                 running             0.0.0.0:8080->80/tcp
```

### 3.5 動作確認

1. ブラウザで http://localhost:8080 にアクセス
2. Okta ログインページにリダイレクトされます
3. Okta アカウントでログイン
4. ログイン成功後、メールアドレスが表示されます

## 4. 開発環境の設定（VS Code）

### 4.1 Dev Container の使用

このプロジェクトは VS Code の Dev Container に対応しています。

1. VS Code で拡張機能「Dev Containers」をインストール
2. プロジェクトフォルダを開く
3. コマンドパレット（Ctrl+Shift+P / Cmd+Shift+P）を開く
4. 「Dev Containers: Reopen in Container」を選択

### 4.2 推奨される VS Code 拡張機能

- PHP Intelephense
- Docker
- EditorConfig for VS Code

## 5. トラブルシューティング

### 問題: 「Missing env: OKTA_ISSUER」エラー

**原因**: 環境変数が正しく読み込まれていません。

**解決方法**:
1. `.env` ファイルが存在することを確認
2. `.env` ファイルの内容が正しいことを確認
3. Docker Compose を再起動:
   ```bash
   docker-compose down
   docker-compose up -d
   ```

### 問題: ログインリダイレクトが機能しない

**原因**: Okta アプリケーション設定のリダイレクト URI が一致していません。

**解決方法**:
1. Okta アプリケーション設定で「Sign-in redirect URIs」を確認
2. `http://localhost:8080/authorization-code/callback` が登録されていることを確認
3. 保存してアプリケーションを再起動

### 問題: ログアウト後にエラーが発生

**原因**: Okta アプリケーション設定にサインアウトリダイレクト URI が登録されていません。

**解決方法**:
1. Okta アプリケーション設定で「Sign-out redirect URIs」を確認
2. `http://localhost:8080/` を追加
3. 保存

### 問題: コンテナが起動しない

**原因**: ポート 8080 が既に使用されています。

**解決方法**:
1. 使用中のプロセスを確認:
   ```bash
   lsof -i :8080
   ```
2. 別のポートを使用する場合、`docker-compose.yml` を編集:
   ```yaml
   ports:
     - "8081:80"  # 8081 に変更
   ```
3. `.env` の `APP_BASE_URL` も更新:
   ```bash
   APP_BASE_URL=http://localhost:8081
   ```
4. Okta アプリケーション設定のリダイレクト URI も更新

### 問題: Composer の依存関係がインストールできない

**原因**: ネットワークの問題または権限の問題。

**解決方法**:
1. コンテナ内で直接実行:
   ```bash
   docker-compose exec php bash
   composer install
   ```
2. キャッシュをクリア:
   ```bash
   docker-compose exec php composer clear-cache
   docker-compose exec php composer install
   ```

## 6. 本番環境へのデプロイ

### 6.1 環境変数の設定

本番環境では以下を考慮してください：

- **HTTPS の使用**: `APP_BASE_URL` を `https://` に変更
- **セキュアな環境変数管理**: AWS Secrets Manager、Azure Key Vault などを使用
- **Okta Production Org**: 開発用ではなく本番用の Okta 組織を使用

### 6.2 Okta アプリケーション設定の更新

本番環境のドメインを Okta アプリケーション設定に追加します：

**Sign-in redirect URIs**:
```
https://your-production-domain.com/authorization-code/callback
```

**Sign-out redirect URIs**:
```
https://your-production-domain.com/
```

### 6.3 セキュリティ強化

1. **HTTPS の強制**:
   - リバースプロキシ（Nginx など）で HTTPS を設定
   - HSTS ヘッダーの追加

2. **セッションのセキュリティ**:
   ```php
   session_set_cookie_params([
       'secure' => true,      // HTTPS のみ
       'httponly' => true,    // JavaScript からアクセス不可
       'samesite' => 'Lax'    // CSRF 対策
   ]);
   ```

3. **環境変数の保護**:
   - `.env` ファイルを使用しない
   - 環境変数をコンテナランタイムで注入

## 7. その他の設定

### 7.1 ログの確認

```bash
# アプリケーションログ
docker-compose logs -f php

# Apache アクセスログ
docker-compose exec php tail -f /var/log/apache2/access.log

# Apache エラーログ
docker-compose exec php tail -f /var/log/apache2/error.log
```

### 7.2 コンテナの停止と削除

```bash
# 停止
docker-compose stop

# 停止と削除
docker-compose down

# ボリュームも含めて削除
docker-compose down -v
```

### 7.3 依存関係の更新

```bash
docker-compose exec php composer update
```
