# POC: PHP Apache + Okta 認証

## 概要

このプロジェクトは、PHP + Apache 環境で Okta を使用した OpenID Connect (OIDC) 認証を実装する概念実証（Proof of Concept）アプリケーションです。

### 主な機能

- **Okta 認証**: OpenID Connect プロトコルによるユーザー認証
- **セッション管理**: PHP セッションを使用したユーザー状態の管理
- **完全なログアウト**: アプリケーションと Okta の両方からのログアウト（RP-Initiated Logout）
- **Docker 対応**: コンテナ化されたアプリケーション環境

## 技術スタック

- **言語**: PHP 8.3
- **Web サーバー**: Apache 2.4
- **認証ライブラリ**: jumbojett/openid-connect-php ^1.0
- **コンテナ**: Docker + Docker Compose
- **認証プロバイダー**: Okta

## プロジェクト構造

```
.
├── .devcontainer/          # VS Code Dev Container 設定
├── public/                 # Web ルートディレクトリ
│   ├── .htaccess          # Apache リライトルール
│   └── index.php          # メインアプリケーションファイル
├── vendor/                 # Composer 依存関係
├── composer.json           # PHP 依存関係定義
├── composer.lock           # 依存関係ロックファイル
├── Dockerfile              # Docker イメージ定義
├── docker-compose.yml      # Docker Compose 設定
└── .env                    # 環境変数（Git 管理外）
```

## クイックスタート

### 前提条件

- Docker および Docker Compose がインストールされていること
- Okta アカウントと設定済みの OIDC アプリケーション

### セットアップ手順

1. **環境変数の設定**

`.env` ファイルを作成し、以下の値を設定します：

```bash
OKTA_ISSUER=https://your-domain.okta.com/oauth2/default
OKTA_CLIENT_ID=your_client_id
OKTA_CLIENT_SECRET=your_client_secret
```

2. **アプリケーションの起動**

```bash
docker-compose up -d
```

3. **アクセス**

ブラウザで `http://localhost:8080` にアクセスします。

## 使用方法

### 初回アクセス
- アプリケーションにアクセスすると、自動的に Okta ログインページにリダイレクトされます
- Okta 認証情報でログインすると、アプリケーションのトップページに戻ります

### ログアウト
- トップページの「Logout」リンクをクリックすると、アプリケーションと Okta の両方からログアウトされます

## セキュリティ考慮事項

- `.env` ファイルは Git 管理から除外されています
- クライアントシークレットは環境変数として管理されます
- セッションクッキーは適切に破棄されます
- ID トークンヒントを使用した安全なログアウトを実装しています

## 開発環境

このプロジェクトは VS Code の Dev Container に対応しています。`.devcontainer` ディレクトリ内の設定を使用して、一貫した開発環境を構築できます。

## ライセンス

このプロジェクトは概念実証用です。

## 関連ドキュメント

- [アーキテクチャ](./ARCHITECTURE.md) - 技術的な詳細とアーキテクチャ設計
- [セットアップガイド](./SETUP.md) - 詳細なセットアップ手順
- [エンドポイント](./ENDPOINTS.md) - API エンドポイントの詳細
