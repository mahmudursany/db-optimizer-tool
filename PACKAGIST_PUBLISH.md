# Packagist Publish Guide (mdj/db-optimizer-agent)

## 1) Create package repository on GitHub
- Create: `https://github.com/<your-user>/db-optimizer-agent`
- Do not add README/license from GitHub UI (keep empty repo).

## 2) Push package code from monorepo (one command flow)

From this repository root run:

```bash
bash scripts/split-package.sh https://github.com/<your-user>/db-optimizer-agent.git
```

This will:
- split `packages/db-optimizer-agent` subtree
- push it as `main` branch to new package repo

Helper script location:
- [scripts/split-package.sh](scripts/split-package.sh)

## 3) Create release tag

```bash
bash scripts/tag-package-release.sh v1.0.0
```

Helper script location:
- [scripts/tag-package-release.sh](scripts/tag-package-release.sh)

## 4) Publish on Packagist
- Sign in at Packagist.
- Submit GitHub repo URL.
- Ensure webhook is enabled for auto-update.

## 5) Install in any Laravel 11 project
- `composer require mdj/db-optimizer-agent --dev`
- `php artisan vendor:publish --tag=db-optimizer-config`
- Add `.env` values:
  - `DB_OPTIMIZER_ENABLED=true`
  - `DB_OPTIMIZER_AGENT_TOKEN=<secret-token>`
  - `DB_OPTIMIZER_ROUTE_PREFIX=_db-optimizer`
  - `DB_OPTIMIZER_CAPTURE_TESTING=false`

## 6) Zero-issue setup for existing projects
- If route conflict exists, change prefix:
  - `DB_OPTIMIZER_ROUTE_PREFIX=_internal-db-optimizer`
- If only API agent needed:
  - `DB_OPTIMIZER_REGISTER_DASHBOARD_ROUTES=false`
- If only dashboard needed:
  - `DB_OPTIMIZER_REGISTER_AGENT_ROUTES=false`
- Keep production disabled by default:
  - `DB_OPTIMIZER_ENABLED=false`

## 7) Verify
- Open `/_db-optimizer`
- Test agent ping:
  - `curl -H "Authorization: Bearer <secret-token>" http://your-app.test/_db-optimizer/agent/ping`

## 8) Production policy
- Default: `DB_OPTIMIZER_ENABLED=false`
- Use sampling if enabled:
  - `DB_OPTIMIZER_SAMPLE_RATE=0.1`
- Restrict dashboard and agent access with network/auth policy.

## 9) Optional preflight check on target project

```bash
bash scripts/preflight-target-project.sh /absolute/path/to/target-project
```

Helper script location:
- [scripts/preflight-target-project.sh](scripts/preflight-target-project.sh)
