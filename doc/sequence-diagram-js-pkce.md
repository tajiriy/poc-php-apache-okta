# OIDC 認証シーケンス図 (JavaScript + PKCE)

`public/index4.html` における Okta Auth JS SDK を使用した PKCE (Proof Key for Code Exchange) フローのシーケンス図です。

## 認証フロー (Authentication Flow with PKCE)

```mermaid
sequenceDiagram
    autonumber
    participant Browser as Browser
    participant SPA as SPA<br/>(index4.html)
    participant Storage as sessionStorage
    participant Okta as Okta<br/>(IdP)

    Note over Browser: ユーザーが<br/>http://localhost:8080/<br/>にアクセス

    Browser->>SPA: GET http://localhost:8080/

    SPA->>Storage: トークン確認<br/>authClient.tokenManager.get('idToken')
    Storage-->>SPA: null (未認証)

    Note over SPA: SPAが PKCE パラメータを生成:<br/>・code_verifier (ランダム文字列)<br/>・code_challenge = BASE64URL(SHA256(code_verifier))<br/>・state (CSRF対策用ランダム文字列)<br/>・nonce (リプレイ攻撃対策)

    SPA->>Storage: PKCE パラメータ保存<br/>code_verifier, state, nonce を<br/>sessionStorage に保存

    Note over SPA: SPAが Okta 認可エンドポイントの<br/>URL を構築

    SPA-->>Browser: window.location.replace() で<br/>Okta へリダイレクト

    Note over Browser: ブラウザが<br/>Okta認可エンドポイントに<br/>リダイレクト

    Browser->>Okta: GET https://trial-8821075.okta.com/oauth2/default/v1/authorize<br/>?client_id=0oazbggp14P6Nr1G3697<br/>&redirect_uri=http://localhost:8080/login/callback<br/>&response_type=code<br/>&scope=openid%20profile%20email<br/>&state=abc123xyz<br/>&nonce=nonce456<br/>&code_challenge=E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-cM<br/>&code_challenge_method=S256

    Note over Okta: Oktaが<br/>ログイン画面を表示

    Okta-->>Browser: 200 OK<br/>ログインフォーム HTML

    Note over Browser: ユーザーが<br/>認証情報を入力

    Browser->>Okta: POST https://trial-8821075.okta.com/oauth2/default/v1/authorize<br/>(username, password)

    Note over Okta: Oktaが認証を検証し、<br/>認可コードを発行

    Okta-->>Browser: 302 Found<br/>Location: http://localhost:8080/login/callback<br/>?code=authorization_code_xxx<br/>&state=abc123xyz

    Note over Browser: ブラウザが<br/>コールバックURLに<br/>リダイレクト

    Browser->>SPA: GET http://localhost:8080/login/callback<br/>?code=authorization_code_xxx<br/>&state=abc123xyz

    Note over SPA: SPAが authClient.handleLoginRedirect() を実行

    SPA->>Storage: 保存済みパラメータ取得<br/>(code_verifier, state, nonce)
    Storage-->>SPA: code_verifier, state, nonce

    Note over SPA: SPAが state を検証<br/>(リクエスト時と一致するか確認)

    Note over SPA: SPAがトークンエンドポイントに<br/>リクエストを送信

    SPA->>Okta: POST https://trial-8821075.okta.com/oauth2/default/v1/token<br/>Content-Type: application/x-www-form-urlencoded<br/><br/>grant_type=authorization_code<br/>&code=authorization_code_xxx<br/>&redirect_uri=http://localhost:8080/login/callback<br/>&client_id=0oazbggp14P6Nr1G3697<br/>&code_verifier=dBjftJeZ4CVP-mB92K27uhbUJU1p1r_wW1gFWFOEjXk

    Note over Okta: Oktaが code_verifier を検証:<br/>code_challenge == BASE64URL(SHA256(code_verifier))<br/>検証成功後、トークンを発行

    Okta-->>SPA: 200 OK<br/>{<br/>  "access_token": "eyJraWQ...",<br/>  "id_token": "eyJraWQ...",<br/>  "token_type": "Bearer",<br/>  "expires_in": 3600,<br/>  "scope": "openid profile email"<br/>}

    Note over SPA: SPAが ID トークンを検証:<br/>・署名検証 (JWK)<br/>・issuer 確認<br/>・audience (client_id) 確認<br/>・nonce 確認<br/>・有効期限 (exp) 確認

    SPA->>Storage: トークン保存<br/>authClient.tokenManager に<br/>access_token, id_token を保存

    SPA->>Storage: PKCE パラメータ削除<br/>transactionManager.clear()

    Note over SPA: SPAが元のURIを取得し<br/>リダイレクト

    SPA-->>Browser: window.location.replace('/')

    Note over Browser: ブラウザがトップページに<br/>リダイレクト

    Browser->>SPA: GET http://localhost:8080/

    SPA->>Storage: トークン確認<br/>authClient.tokenManager.get('idToken')
    Storage-->>SPA: idToken (認証済み)

    Note over SPA: SPAが ID トークンから<br/>claims を取得し画面表示

    SPA-->>Browser: 200 OK<br/>認証済みページ表示<br/>"Hello, user@example.com さん!"
```

## ログアウトフロー (RP-Initiated Logout)

```mermaid
sequenceDiagram
    autonumber
    participant Browser as Browser
    participant SPA as SPA<br/>(index4.html)
    participant Storage as sessionStorage
    participant Okta as Okta<br/>(IdP)

    Note over Browser: ユーザーが<br/>ログアウトリンクをクリック

    Browser->>SPA: GET http://localhost:8080/logout

    Note over SPA: SPAが authClient.signOut() を実行

    SPA->>Storage: トークン取得<br/>authClient.tokenManager.get('idToken')
    Storage-->>SPA: id_token

    SPA->>Storage: トークン削除<br/>authClient.tokenManager.clear()

    SPA->>Storage: トランザクション情報削除<br/>transactionManager.clear()

    Note over SPA: SPAが Okta ログアウト URL を構築

    SPA-->>Browser: window.location.replace() で<br/>Okta へリダイレクト

    Note over Browser: ブラウザが<br/>Okta ログアウトエンドポイントに<br/>リダイレクト

    Browser->>Okta: GET https://trial-8821075.okta.com/oauth2/default/v1/logout<br/>?id_token_hint=eyJraWQ...<br/>&post_logout_redirect_uri=http://localhost:8080/

    Note over Okta: Oktaが id_token_hint を検証し、<br/>Okta セッションを破棄

    Okta-->>Browser: 302 Found<br/>Location: http://localhost:8080/

    Note over Browser: ブラウザが<br/>トップページに<br/>リダイレクト

    Browser->>SPA: GET http://localhost:8080/

    SPA->>Storage: トークン確認<br/>authClient.tokenManager.get('idToken')
    Storage-->>SPA: null (未認証)

    Note over SPA: 未認証のため<br/>再度ログインフローを開始

    SPA-->>Browser: Okta へリダイレクト<br/>(認証フロー開始)
```

## PKCE パラメータ詳細

| パラメータ | 説明 | 例 |
|-----------|------|-----|
| `code_verifier` | クライアントが生成する暗号学的にランダムな文字列 (43-128文字) | `dBjftJeZ4CVP-mB92K27uhbUJU1p1r_wW1gFWFOEjXk` |
| `code_challenge` | `code_verifier` の SHA-256 ハッシュを Base64URL エンコードした値 | `E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-cM` |
| `code_challenge_method` | チャレンジ生成方法 (S256 = SHA-256) | `S256` |
| `state` | CSRF 対策用のランダム文字列 | `abc123xyz` |
| `nonce` | ID トークンのリプレイ攻撃対策用ランダム文字列 | `nonce456` |

## PHP版 (index.php) との違い

| 項目 | JavaScript版 (index4.html) | PHP版 (index.php) |
|------|---------------------------|-------------------|
| **実行環境** | ブラウザ (クライアントサイド) | サーバーサイド |
| **認証フロー** | Authorization Code + PKCE | Authorization Code (client_secret) |
| **クライアント認証** | code_verifier で検証 | client_secret で認証 |
| **トークン保存先** | sessionStorage | サーバーセッション ($_SESSION) |
| **コールバックパス** | `/login/callback` | `/authorization-code/callback` |
| **トークン取得** | ブラウザから直接 Okta に POST | PHP から Okta に POST |
| **セキュリティ** | PKCE で認可コード横取り攻撃を防止 | client_secret をサーバーで安全に保持 |

## 全体フロー概要

```mermaid
flowchart TD
    subgraph Browser["Browser"]
        A[ユーザーアクセス<br/>http://localhost:8080/]
    end

    subgraph SPA["SPA (index4.html)"]
        B{トークン<br/>存在?}
        C[PKCE パラメータ生成<br/>code_verifier, code_challenge,<br/>state, nonce]
        D[sessionStorage に保存]
        E[Okta URL 構築]
        K[handleLoginRedirect 実行]
        L[state/nonce 検証]
        M[トークン取得リクエスト]
        N[ID トークン検証]
        O[トークン保存]
        P[認証済み画面表示]
        Q[signOut 実行]
        R[トークン削除]
    end

    subgraph Storage["sessionStorage"]
        S[(code_verifier<br/>state<br/>nonce)]
        T[(access_token<br/>id_token)]
    end

    subgraph Okta["Okta (IdP)"]
        F[/authorize エンドポイント]
        G[ログイン画面]
        H[認可コード発行]
        I[/token エンドポイント]
        J[トークン発行]
        U[/logout エンドポイント]
        V[セッション破棄]
    end

    A --> B
    B -->|No| C
    C --> D
    D --> S
    D --> E
    E --> F
    F --> G
    G --> H
    H -->|code, state| K
    K --> L
    L --> M
    S --> M
    M --> I
    I --> J
    J --> N
    N --> O
    O --> T
    O --> P
    B -->|Yes| P
    T --> B

    P -->|ログアウト| Q
    Q --> R
    R --> U
    U --> V
    V --> A
```

## URL 一覧

| エンドポイント | URL | 用途 |
|---------------|-----|------|
| アプリケーション | `http://localhost:8080/` | トップページ |
| コールバック | `http://localhost:8080/login/callback` | 認可コード受信 |
| ログアウト | `http://localhost:8080/logout` | ログアウト処理開始 |
| Okta 認可 | `https://trial-8821075.okta.com/oauth2/default/v1/authorize` | 認可リクエスト |
| Okta トークン | `https://trial-8821075.okta.com/oauth2/default/v1/token` | トークン取得 |
| Okta ログアウト | `https://trial-8821075.okta.com/oauth2/default/v1/logout` | Okta セッション終了 |
