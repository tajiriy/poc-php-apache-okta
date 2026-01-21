以下は、いただいた **JS版の文章構成をできるだけ保ったまま**、中身を **BFF（Bridge）版**に書き換えた案です。
ポイントは、

* **⑩〜⑬（token交換〜検証〜userinfo）はBridgeが担当**（SPIRALは担当できない）
* ブラウザ側は **トークンを保持しない**（sessionStorageにokta-token-storage等は基本出ない）
* 最後は **ブラウザがSPIRALへPOST**してSPIRAL側のCookieを確立する（ドメインの都合で必須）
* 社内利用（A想定）なら Bridge は社内IIS上でOK

を反映しています。

---

# OIDC 認証シーケンス図 (BFF/Bridge + Authorization Code + PKCE)

`Bridge`（社内IIS上のWebアプリ）における Okta の Authorization Code + PKCE フローのシーケンス図です。
**特徴:** ブラウザにトークンを保持せず、Bridge（サーバ）側で `code → token` 交換・検証を行い、SPIRALには「識別キー（SMPID）」でログインさせます。

> **重要:** OIDCの **⑩〜⑬相当（/token, トークン受領, 検証, /userinfo）はSPIRALでは実装できない**ため、Bridgeが担います。

---

## 認証済みの場合のフロー (Already Authenticated)

```mermaid
sequenceDiagram
    autonumber
    participant Browser as Browser
    participant Bridge as Bridge<br/>(BFF on IIS)
    participant BridgeSession as Bridge Session<br/>(Server-side)
    participant SPIRAL as SPIRAL v1<br/>(MyArea)

    Note over Browser: ユーザーが Bridge 入口URL にアクセス<br/>例: https://intra.example.co.jp/spiral

    Browser->>Bridge: GET /spiral
    Note over Bridge: Bridge がセッション確認

    Bridge->>BridgeSession: セッション確認 (ログイン済み?)
    BridgeSession-->>Bridge: user/spiral_id (認証済み)

    Note over Bridge: Bridge が spiral_id を取得済み<br/>（Okta由来の一意ID：AD objectGUID / Okta user id / LAN ID など）

    Note over Bridge: Bridge が SPIRAL への auto-submit HTML を生成<br/>POST /area/Login (SMPID=spiral_id, SMPAREA=...)

    Bridge-->>Browser: 200 OK (auto-submit HTML)
    Browser->>SPIRAL: POST /area/Login<br/>(SMPID, SMPAREA)
    SPIRAL-->>Browser: 302 Redirect to MyArea Top
    Browser->>SPIRAL: GET (一覧表・単票など)
    SPIRAL-->>Browser: 200 OK (ログイン済みページ表示)
```

---

## 未認証からの認証フロー (Authentication Flow with PKCE)

```mermaid
sequenceDiagram
    autonumber
    participant Browser as Browser
    participant Bridge as Bridge<br/>(BFF on IIS)
    participant BridgeSession as Bridge Session<br/>(Server-side)
    participant Okta as Okta<br/>(IdP)
    participant SPIRAL as SPIRAL v1<br/>(MyArea)

    Note over Browser: ユーザーが Bridge 入口URL にアクセス<br/>例: https://intra.example.co.jp/spiral

    Browser->>Bridge: GET /spiral
    Note over Bridge: Bridge がセッション確認

    Bridge->>BridgeSession: セッション確認 (ログイン済み?)
    BridgeSession-->>Bridge: null (未認証)

    Note over Bridge: Bridge が PKCE パラメータを生成:<br/>・code_verifier (ランダム文字列)<br/>・code_challenge = BASE64URL(SHA256(code_verifier))<br/>・state (CSRF対策)<br/>・nonce (リプレイ攻撃対策)

    Note over Bridge: Bridge が PKCE パラメータを<br/>サーバ側セッションに保存

    Bridge->>BridgeSession: PKCE/transaction 保存<br/>(code_verifier, state, nonce)

    Note over Bridge: Bridge が Okta /authorize URL を構築して<br/>ブラウザをリダイレクト

    Bridge-->>Browser: 302 Redirect to Okta /authorize<br/>(client_id, redirect_uri, scope, state, nonce,<br/>code_challenge, code_challenge_method=S256)
    Browser->>Okta: GET /authorize ...

    Note over Okta: Okta がログイン画面を表示<br/>（ただし既にOktaログイン済みならSSOにより省略される）

    Okta-->>Browser: 302 Redirect to Bridge callback<br/>?code=authorization_code_xxx&state=abc123xyz
    Browser->>Bridge: GET /spiral/callback?code=authorization_code_xxx&state=abc123xyz

    Note over Bridge: Bridge が state を検証<br/>（セッション保存値と一致するか）

    Bridge->>BridgeSession: 事前保存の state/nonce/code_verifier 取得
    BridgeSession-->>Bridge: code_verifier, state, nonce

    Note over Bridge: ★ここ(⑩〜⑬相当)をSPIRALは持てないためBridgeが担当

    Bridge->>Okta: POST /token<br/>grant_type=authorization_code<br/>&code=authorization_code_xxx<br/>&redirect_uri=...<br/>&client_id=...<br/>&code_verifier=...
    Note over Okta: Okta が code_verifier を検証しトークン発行

    Okta-->>Bridge: 200 OK<br/>{access_token, id_token, ...}

    Note over Bridge: Bridge が ID トークンを検証:<br/>・署名検証 (JWK)<br/>・issuer 確認<br/>・audience 確認<br/>・nonce 確認<br/>・有効期限 (exp) 確認

    opt 必要に応じて（実装方針次第）
        Bridge->>Okta: GET /userinfo (Bearer access_token)
        Okta-->>Bridge: ユーザ情報 (email, sub, etc.)
    end

    Note over Bridge: Bridge が spiral_id を決定<br/>（例: claims.spiral_id / AD objectGUID / Okta user id / LAN ID）<br/>必要ならDBでSPIRAL会員と突合

    Bridge->>BridgeSession: ユーザ情報保存<br/>(user, spiral_id, id_tokenメタ情報等)
    Note over Bridge: Bridge はブラウザにトークンを保存しない<br/>（sessionStorageにOktaトークンが残らない）

    Note over Bridge: Bridge が SPIRAL への auto-submit HTML を生成<br/>POST /area/Login (SMPID=spiral_id, SMPAREA=...)

    Bridge-->>Browser: 200 OK (auto-submit HTML)
    Browser->>SPIRAL: POST /area/Login<br/>(SMPID, SMPAREA)
    SPIRAL-->>Browser: 302 Redirect to MyArea Top
    Browser->>SPIRAL: GET (一覧表・単票など)
    SPIRAL-->>Browser: 200 OK (ログイン済みページ表示)
```

---

## ログアウトフロー (Bridge主導 + Oktaログアウト)

BFF方式では「ブラウザのsessionStorageにトークンがない」ため、ログアウトは主に以下の2段階です。

* Bridgeのセッション破棄（Bridge側ログアウト）
* Oktaセッション破棄（必要なら）

```mermaid
sequenceDiagram
    autonumber
    participant Browser as Browser
    participant Bridge as Bridge<br/>(BFF on IIS)
    participant BridgeSession as Bridge Session<br/>(Server-side)
    participant Okta as Okta<br/>(IdP)

    Note over Browser: ユーザーがログアウトURLをクリック<br/>例: https://intra.example.co.jp/spiral/logout

    Browser->>Bridge: GET /spiral/logout
    Note over Bridge: Bridge がサーバセッションを破棄

    Bridge->>BridgeSession: セッション削除 (user, spiral_id, transaction)
    BridgeSession-->>Bridge: OK

    Note over Bridge: 必要に応じて Okta のRP-Initiated Logoutへ誘導<br/>（id_token_hint を使うかは設計次第）

    Bridge-->>Browser: 302 Redirect to Okta /logout<br/>(post_logout_redirect_uri=...)
    Browser->>Okta: GET /logout ...
    Okta-->>Browser: 302 Redirect to Bridge post-logout
    Browser->>Bridge: GET /spiral (または /post-logout)
    Bridge-->>Browser: 200 OK (未認証状態のページ)
```

> 補足：Oktaログアウトは運用要件次第です。
> 「Okta SSOは維持しつつ、SPIRALだけ抜けたい」なら Bridgeセッション破棄だけで十分なことも多いです。

---

## PKCE パラメータ詳細（BFF版）

| パラメータ                   | 説明                                    | 保存先（BFF）       |
| ----------------------- | ------------------------------------- | -------------- |
| `code_verifier`         | 暗号学的にランダムな文字列 (43-128文字)              | Bridgeサーバセッション |
| `code_challenge`        | `code_verifier` の SHA-256 を Base64URL | Oktaへ送信のみ      |
| `code_challenge_method` | `S256`                                | Oktaへ送信のみ      |
| `state`                 | CSRF 対策                               | Bridgeサーバセッション |
| `nonce`                 | IDトークンのリプレイ攻撃対策                       | Bridgeサーバセッション |

---

## JS版との違い（あなたの表の「BFF版」への置換）

| 項目                | JavaScript版 (index5.html) | **BFF/Bridge版（推奨）**                    |
| ----------------- | ------------------------- | -------------------------------------- |
| 実行環境              | ブラウザ                      | **サーバ（IIS上）**                          |
| 認証フロー             | Authorization Code + PKCE | **Authorization Code + PKCE**（サーバ実施）   |
| クライアント認証          | code_verifier（ブラウザ）       | **code_verifier（サーバ）**                 |
| トークン保存先           | sessionStorage            | **サーバセッション（ブラウザに残さない）**                |
| callback URL      | `/index5.html` 自身         | **`/spiral/callback`**（Bridgeのエンドポイント） |
| トークン取得            | ブラウザからOktaへPOST           | **BridgeがOktaへPOST**                   |
| SPIRALログイン        | ブラウザが直接POST               | **ブラウザがSPIRALへPOST（auto-submit HTML）** |
| SPIRAL側でOIDC(⑩〜⑬) | 不要                        | **不要（Bridgeで完結）**                      |
| セキュリティ説明          | トークンが端末に残る                | **端末にトークンを残さないので説明が通しやすい**             |

---

## URL 一覧（BFF/Bridge版の例）

| エンドポイント         | URL例                                               | 用途                             |
| --------------- | -------------------------------------------------- | ------------------------------ |
| Bridge入口        | `https://intra.example.co.jp/spiral`               | SPIRALへ入るための入口                 |
| Bridge callback | `https://intra.example.co.jp/spiral/callback`      | Oktaからの認可コード受信                 |
| Bridge logout   | `https://intra.example.co.jp/spiral/logout`        | Bridgeセッション破棄 + (任意でOktaログアウト) |
| Okta 認可         | `https://{oktaDomain}/oauth2/default/v1/authorize` | 認可リクエスト                        |
| Okta トークン       | `https://{oktaDomain}/oauth2/default/v1/token`     | トークン取得                         |
| Okta ログアウト      | `https://{oktaDomain}/oauth2/default/v1/logout`    | Oktaセッション終了                    |

---

## Okta アプリケーション設定（BFF/Bridge版）

Bridgeを使うには、Okta管理画面で以下のURIを登録します：

| 設定項目                   | 値（例）                                                     |
| ---------------------- | -------------------------------------------------------- |
| Sign-in redirect URIs  | `https://intra.example.co.jp/spiral/callback`            |
| Sign-out redirect URIs | `https://intra.example.co.jp/spiral`（または `/post-logout`） |

---

## 追加（SPIRAL連携での要点：SMPID）

BridgeがSPIRALへ送る `SMPID` は、**推測不能ID**（例：AD objectGUID / Okta user id）を推奨します。
メールアドレスをSMPIDにすると、ログインURL直アクセスの攻撃面が増えるため、避けた方が安全です。

---

必要なら、このドキュメントに合わせて **「全体フロー概要（flowchart TD）」もBFF版に置き換え**ます（JS版の図と同じ粒度で）。
また、実装がASP.NET Core前提なら、`/spiral` と `/spiral/callback` の疑似コード（コントローラ単位）まで続けて書けます。
