# Backup & Recovery

## Как сделать backup

1. Убедитесь, что заданы переменные окружения (или используются значения по умолчанию):
   - `DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER`, `DB_PASS`

2. Запуск (из корня проекта или с указанием пути):
   ```bash
   ./scripts/db_backup.sh
   ```
   Или с env:
   ```bash
   DB_HOST=127.0.0.1 DB_NAME=qr_rest DB_USER=qr DB_PASS=secret ./scripts/db_backup.sh
   ```

3. Файл сохраняется в каталог `backups/` с именем вида `qr_rest_20250302_143022.sql`.

4. Код выхода: 0 — успех, 1 — ошибка. Пароль в лог не выводится.

## Как восстановить

1. Подготовьте дамп (файл `.sql`).

2. Запуск с подтверждением:
   ```bash
   ./scripts/db_restore.sh /path/to/backups/qr_rest_20250302_143022.sql
   ```
   Скрипт спросит подтверждение (y/N).

3. Без подтверждения (для скриптов):
   ```bash
   ./scripts/db_restore.sh /path/to/dump.sql --yes
   ```

4. Используются те же env: `DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER`, `DB_PASS`.

## Как проверить restore

1. После restore проверьте приложение:
   - Откройте `/health.php` и `/ready.php` — оба должны вернуть `status: ok` (или degraded, если чего-то не хватает).
   - Выполните smoke: `bash scripts/smoke_http.sh`.
   - Проверьте логин и основные сценарии (signup, dashboard, restaurant panel).

2. Критичные таблицы для работы:
   - `users`, `restaurants`, `orders` — минимальная готовность (ready.php).
   - Для полного функционала: `plans`, `subscriptions`, `invoices`, `payments`, `restaurants`, `menu_*`, `orders`, `crm_*`, `security_rate_limits` и др. по миграциям.

## Offsite / внешний backup

Локальные backup‑файлы на том же сервере полезны, но недостаточны для production.

Рекомендуется:

1. Настроить синхронизацию каталога `backups/` во внешнее хранилище:
   - объектное хранилище (например S3/MinIO/Wasabi) через `aws s3 sync` или аналог;
   - отдельный backup‑сервер через `rsync` по SSH;
   - snapshot‑механизмы облака (в сочетании с логическими дампами).
2. Установить политику хранения (retention), например:
   - ежедневные backup‑ы: хранить 7–14 дней;
   - еженедельные snapshot‑ы: хранить 4–12 недель.
3. Регулярно (например раз в месяц) проверять восстановление:
   - поднять временную/staging‑БД;
   - выполнить `db_restore.sh` с одного из offsite‑дампов;
   - пройти `/health.php`, `/ready.php` и smoke‑тест (`scripts/smoke_http.sh`).

Пример вспомогательного скрипта смотрите в `scripts/db_backup_offsite_example.sh` (шаблон, не запускается автоматически).

## Критичные таблицы

- **Обязательные для ready:** `users`, `restaurants`, `orders`.
- **Для billing/trial:** `plans`, `subscriptions`, `invoices`, `payments`.
- **Для CRM:** `crm_guests`, `crm_outbox`, `crm_visits`.
- **Для безопасности:** `security_rate_limits`.
- Полный список — по миграциям в проекте.
