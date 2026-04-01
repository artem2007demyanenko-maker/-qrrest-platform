SSL / HTTPS для production

Полная инструкция: docs/DEPLOY_HTTPS.md

Кратко:
  • Стек: docker-compose.prod.yml — Nginx терминирует TLS, проксирует в контейнер app.
  • Файлы для Nginx: deploy/nginx/certs/fullchain.pem и privkey.pem
  • HTTP-01 (webroot) — для qrrest-menu.ru и www (без wildcard).
  • Wildcard *.qrrest-menu.ru — только DNS-01; после выпуска объедините server-блоки в default.conf (см. документ).

Поведение без wildcard в fullchain.pem:
  • Первый HTTPS server: qrrest-menu.ru, www.qrrest-menu.ru
  • Второй HTTPS server: *.qrrest-menu.ru с ssl_reject_handshake on — не выдаётся сертификат apex (нет подмены имён).
  • После wildcard: удалите второй блок, добавьте *.qrrest-menu.ru в первый server_name.

Устаревшие команды certbot с HTTP challenge для выдачи *.domain у Let's Encrypt для wildcard не работают.
