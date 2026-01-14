# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

A PHP/Apache proof-of-concept demonstrating Okta OpenID Connect (OIDC) authentication. The entire application logic is contained in a single file (`public/index.php`).

**Stack:** PHP 8.3, Apache 2.4, jumbojett/openid-connect-php, Docker

## Commands

### Start the application
```bash
docker-compose up -d
```

### Install dependencies (first time or after composer.json changes)
```bash
docker-compose exec php composer install
```

### View logs
```bash
docker-compose logs -f php                              # Container logs
docker-compose exec php tail -f /var/log/apache2/error.log   # Apache errors
```

### Rebuild container (after Dockerfile changes)
```bash
docker-compose up -d --build
```

## Architecture

### Authentication Flow

1. Unauthenticated user accesses `/` → redirected to Okta login via `$oidc->authenticate()`
2. After Okta login → callback to `/authorization-code/callback`
3. Token exchange and validation → user info stored in `$_SESSION['user']` and ID token in `$_SESSION['id_token']`
4. User redirected to `/` → sees authenticated content

### Logout Flow (RP-Initiated)

1. User accesses `/logout` → app session destroyed
2. Redirect to Okta's `/v1/logout` endpoint with `id_token_hint` and `post_logout_redirect_uri`
3. Okta clears its session → redirects back to app

### Key Files

- `public/index.php` - Single-file application: routing, OIDC handling, session management, HTML rendering
- `public/.htaccess` - Apache URL rewriting (all requests → index.php)
- `.env` - Environment variables (OKTA_ISSUER, OKTA_CLIENT_ID, OKTA_CLIENT_SECRET)

### Environment Variables

| Variable | Description |
|----------|-------------|
| `OKTA_ISSUER` | Okta authorization server URL (e.g., `https://dev-xxx.okta.com/oauth2/default`) |
| `OKTA_CLIENT_ID` | Okta application client ID |
| `OKTA_CLIENT_SECRET` | Okta application client secret |
| `APP_BASE_URL` | Application base URL (set in docker-compose.yml as `http://localhost:8080`) |

## Development with VS Code

Open project in VS Code and use "Dev Containers: Reopen in Container". Dependencies install automatically via `postCreateCommand`.

## Documentation

Detailed documentation in Japanese available in `/doc/`:
- `ARCHITECTURE.md` - Technical architecture and OIDC flow details
- `SETUP.md` - Okta configuration and environment setup
- `ENDPOINTS.md` - Endpoint specifications and session management
