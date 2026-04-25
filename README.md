এখন তোমার existing project-এ update নিতে:

composer require mdj/db-optimizer-agent:^1.4 --dev
php artisan optimize:clear
php artisan config:clear
/_db-optimizer এ গিয়ে snapshot details refresh করে দেখো।


<p align="center"><a href="https://laravel.com" target="_blank"><img src="https://raw.githubusercontent.com/laravel/art/master/logo-lockup/5%20SVG/2%20CMYK/1%20Full%20Color/laravel-logolockup-cmyk-red.svg" width="400" alt="Laravel Logo"></a></p>

<p align="center">
<a href="https://github.com/laravel/framework/actions"><img src="https://github.com/laravel/framework/workflows/tests/badge.svg" alt="Build Status"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/dt/laravel/framework" alt="Total Downloads"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/v/laravel/framework" alt="Latest Stable Version"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/l/laravel/framework" alt="License"></a>
</p>

## About Laravel

Laravel is a web application framework with expressive, elegant syntax. We believe development must be an enjoyable and creative experience to be truly fulfilling. Laravel takes the pain out of development by easing common tasks used in many web projects, such as:

- [Simple, fast routing engine](https://laravel.com/docs/routing).
- [Powerful dependency injection container](https://laravel.com/docs/container).
- Multiple back-ends for [session](https://laravel.com/docs/session) and [cache](https://laravel.com/docs/cache) storage.
- Expressive, intuitive [database ORM](https://laravel.com/docs/eloquent).
- Database agnostic [schema migrations](https://laravel.com/docs/migrations).
- [Robust background job processing](https://laravel.com/docs/queues).
- [Real-time event broadcasting](https://laravel.com/docs/broadcasting).

Laravel is accessible, powerful, and provides tools required for large, robust applications.

## Learning Laravel

Laravel has the most extensive and thorough [documentation](https://laravel.com/docs) and video tutorial library of all modern web application frameworks, making it a breeze to get started with the framework.

You may also try the [Laravel Bootcamp](https://bootcamp.laravel.com), where you will be guided through building a modern Laravel application from scratch.

If you don't feel like reading, [Laracasts](https://laracasts.com) can help. Laracasts contains thousands of video tutorials on a range of topics including Laravel, modern PHP, unit testing, and JavaScript. Boost your skills by digging into our comprehensive video library.

## Laravel Sponsors

We would like to extend our thanks to the following sponsors for funding Laravel development. If you are interested in becoming a sponsor, please visit the [Laravel Partners program](https://partners.laravel.com).

### Premium Partners

- **[Vehikl](https://vehikl.com/)**
- **[Tighten Co.](https://tighten.co)**
- **[WebReinvent](https://webreinvent.com/)**
- **[Kirschbaum Development Group](https://kirschbaumdevelopment.com)**
- **[64 Robots](https://64robots.com)**
- **[Curotec](https://www.curotec.com/services/technologies/laravel/)**
- **[Cyber-Duck](https://cyber-duck.co.uk)**
- **[DevSquad](https://devsquad.com/hire-laravel-developers)**
- **[Jump24](https://jump24.co.uk)**
- **[Redberry](https://redberry.international/laravel/)**
- **[Active Logic](https://activelogic.com)**
- **[byte5](https://byte5.de)**
- **[OP.GG](https://op.gg)**

## Contributing

Thank you for considering contributing to the Laravel framework! The contribution guide can be found in the [Laravel documentation](https://laravel.com/docs/contributions).

## Code of Conduct

In order to ensure that the Laravel community is welcoming to all, please review and abide by the [Code of Conduct](https://laravel.com/docs/contributions#code-of-conduct).

## Security Vulnerabilities

If you discover a security vulnerability within Laravel, please send an e-mail to Taylor Otwell via [taylor@laravel.com](mailto:taylor@laravel.com). All security vulnerabilities will be promptly addressed.

## License

The Laravel framework is open-sourced software licensed under the [MIT license](https://opensource.org/licenses/MIT).

---

## DB Optimizer Tool (Local/Real Project)

This repository includes a complete DB optimizer tool with:

- Query interceptor (`DB::listen`)
- N+1 detection signals
- Missing index suggestions
- Slow query `EXPLAIN` summary
- Cache candidate hints
- Advanced optimization recommendations with code hints
- Optional safe auto-apply eligibility markers
- Dashboard: `/_db-optimizer`
- Remote scanner: `/_db-optimizer/scanner`
- Agent API: `/_db-optimizer/agent/*`

### Local run

1. Set `.env` values:

```dotenv
DB_OPTIMIZER_ENABLED=true
DB_OPTIMIZER_AGENT_TOKEN=local-dev-token
DB_OPTIMIZER_ROUTE_PREFIX=_db-optimizer
```

2. Run server:

```bash
php artisan serve
```

3. Open:

- Dashboard: `http://127.0.0.1:8000/_db-optimizer`
- Scanner: `http://127.0.0.1:8000/_db-optimizer/scanner`

### Use on a real project

Package source exists at:

- `packages/db-optimizer-agent`

Package guide:

- `packages/db-optimizer-agent/README.md`
- `PACKAGIST_PUBLISH.md`
- `DB_OPTIMIZER_STEP_BY_STEP_BN.md`


1) Project requirements check
PHP: 8.1+
Laravel: 8/9/10/11
2) Package install
Project root-এ চালাও:

composer require mdj/db-optimizer-agent:^1.2 --dev
যদি dependency conflict আসে:

composer require mdj/db-optimizer-agent:^1.2 --dev -W
3) Config publish
php artisan vendor:publish --tag=db-optimizer-config
4) .env configure
নিচেরগুলো add/update করো:

DB_OPTIMIZER_ENABLED=true
DB_OPTIMIZER_AGENT_TOKEN=your-strong-token
DB_OPTIMIZER_ROUTE_PREFIX=_db-optimizer
DB_OPTIMIZER_SAMPLE_RATE=1
DB_OPTIMIZER_SLOW_MS=50
DB_OPTIMIZER_ADVANCED_SUGGESTIONS=true
DB_OPTIMIZER_AUTO_APPLY_SAFE=false
DB_OPTIMIZER_RECOMMENDATION_LIMIT=8
5) Clear cache
php artisan optimize:clear
6) Route verify
php artisan route:list --path=_db-optimizer
তুমি 7টা route দেখতে পাবে (dashboard, scanner, agent endpoints)।

7) App চালাও
php artisan serve
অথবা Herd domain ব্যবহার করো।
তারপর browser:

/_db-optimizer
/_db-optimizer/scanner
8) Quick test (agent)
curl -H "Authorization: Bearer your-strong-token" http://your-domain.test/_db-optimizer/agent/ping
ok: true এলে setup ঠিক।

Existing project-এ conflict avoid tips
Route conflict হলে:
DB_OPTIMIZER_ROUTE_PREFIX=_internal-db-optimizer
Production এ default off রাখো:
DB_OPTIMIZER_ENABLED=false
Test environment side-effect না চাইলে:
DB_OPTIMIZER_CAPTURE_TESTING=false


-----

এখন তোমার existing project-এ update নিতে:

composer require mdj/db-optimizer-agent:^1.4 --dev
php artisan optimize:clear
php artisan config:clear
/_db-optimizer এ গিয়ে snapshot details refresh করে দেখো।


-----


দারুণ — old version install করা থাকলে update খুব সহজ।

Existing project-এ v1.3.0 নেওয়ার step
Project root-এ গিয়ে package upgrade:
composer require mdj/db-optimizer-agent:^1.3 --dev
config publish/update (নতুন keys আনতে):
php artisan vendor:publish --tag=db-optimizer-config --force
.env এ নতুন/important keys নিশ্চিত করো:
DB_OPTIMIZER_ENABLED=true
DB_OPTIMIZER_AGENT_TOKEN=your-token
DB_OPTIMIZER_ROUTE_PREFIX=_db-optimizer
DB_OPTIMIZER_ADVANCED_SUGGESTIONS=true
DB_OPTIMIZER_AUTO_APPLY_SAFE=false
DB_OPTIMIZER_RECOMMENDATION_LIMIT=8
cache clear:
php artisan optimize:clear
route verify:
php artisan route:list --path=_db-optimizer
app চালিয়ে snapshot detail page open করো:
/_db-optimizer/snapshots/{id}
এখন query block-এর নিচে Suggested Rewrites / New Query দেখাবে।
যদি update না নেয়
lock conflict হলে:
composer require mdj/db-optimizer-agent:^1.3 --dev -W
still old দেখালে:
composer show mdj/db-optimizer-agent
এখানে version v1.3.0 হওয়া লাগবে।
