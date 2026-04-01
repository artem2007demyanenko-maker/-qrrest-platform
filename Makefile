.PHONY: schema smoke ok

schema:
	docker compose exec -T web php scripts/check_schema.php --json

smoke:
	docker compose exec -T web bash scripts/smoke_http.sh

ok: schema smoke
	@echo "✅ ALL GOOD"
