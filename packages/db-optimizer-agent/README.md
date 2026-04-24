# mdj/db-optimizer-agent

Laravel 11 package for local/staging database query diagnostics.

## Compatibility
- PHP `8.1+`
- Laravel `8.x`, `9.x`, `10.x`, or `11.x`

Install note:
- Composer will resolve dependencies based on your project's existing Laravel major version.

## Features
- `DB::listen` query interception
- N+1 suspicion signals
- Missing index hints from `WHERE` / `JOIN`
- Auto `EXPLAIN` on slow queries
- Cache candidate hints
- Advanced optimization suggestions with code hints
- Rewritten "New Query" suggestions shown under detected queries
- Executable guarded SQL for index suggestions (safe to rerun)
- Laravel-style current and optimized query snippets for direct copy/paste
- Optional safe auto-apply eligibility flags
- Built-in dashboard + remote scanner + protected agent API

## Install in another Laravel project (local path)

1. In target project `composer.json`, add local repository:

```json
{
	"repositories": [
		{
			"type": "path",
			"url": "../db-optimizer-tool/packages/db-optimizer-agent",
			"options": {
				"symlink": true
			}
		}
	]
}
```

2. Require package:

```bash
composer require mdj/db-optimizer-agent:* --dev
```

3. Publish config:

```bash
php artisan vendor:publish --tag=db-optimizer-config
```

4. Set `.env`:

```dotenv
DB_OPTIMIZER_ENABLED=true
DB_OPTIMIZER_AGENT_TOKEN=change-me
DB_OPTIMIZER_ROUTE_PREFIX=_db-optimizer
DB_OPTIMIZER_CAPTURE_TESTING=false
DB_OPTIMIZER_REGISTER_DASHBOARD_ROUTES=true
DB_OPTIMIZER_REGISTER_AGENT_ROUTES=true
DB_OPTIMIZER_ADVANCED_SUGGESTIONS=true
DB_OPTIMIZER_AUTO_APPLY_SAFE=false
DB_OPTIMIZER_RECOMMENDATION_LIMIT=8
```

5. Visit dashboard:

`http://your-app.test/_db-optimizer`

## Install from Git/Packagist

After publishing package to GitHub + Packagist:

```bash
composer require mdj/db-optimizer-agent --dev
php artisan vendor:publish --tag=db-optimizer-config
```

## Agent API
- `GET /_db-optimizer/agent/ping`
- `GET /_db-optimizer/agent/snapshots`
- `POST /_db-optimizer/agent/reset`

Send `Authorization: Bearer <DB_OPTIMIZER_AGENT_TOKEN>`.

## Notes
- Use in local/staging first.
- Keep `DB_OPTIMIZER_ENABLED=false` in production unless you intentionally sample traffic.
- For existing projects with route conflicts, change `DB_OPTIMIZER_ROUTE_PREFIX`.
- If you only want scanner API, set `DB_OPTIMIZER_REGISTER_DASHBOARD_ROUTES=false`.
- Safe auto-apply only marks eligible suggestions; it does not rewrite your code automatically.
