# QR REST

PHP-приложение (меню, заказы, гость, кабинет ресторана). Деплой и прод — см. короткие операционные документы:

| Документ | Назначение |
|----------|------------|
| [docs/PROJECT_STATE.md](docs/PROJECT_STATE.md) | Карта модулей, entrypoints, риски |
| [docs/DEPLOY_SOURCE_OF_TRUTH.md](docs/DEPLOY_SOURCE_OF_TRUTH.md) | Как устроен прод в репо vs VPS |
| [docs/PROD_RUNBOOK.md](docs/PROD_RUNBOOK.md) | Аварийное восстановление, команды |
| [docs/RELEASE_SMOKE_CHECKLIST.md](docs/RELEASE_SMOKE_CHECKLIST.md) | Смоук после деплоя |
| [docs/BRAND_ASSETS.md](docs/BRAND_ASSETS.md) | Favicon, PNG, JSON-LD logo, генератор |

TLS: [docs/DEPLOY_HTTPS.md](docs/DEPLOY_HTTPS.md) (wildcard SAN). Локальная разработка: `docker-compose.yml`; прод: `docker-compose.prod.yml`. Рестарт app после деплоя: `deploy.sh` использует **`docker compose`** (v2 plugin).

- Owner panel smoke/checklist: `docs/OWNER_STABILITY_CHECKLIST.md`
