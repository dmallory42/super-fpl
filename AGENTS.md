# Super FPL - Agent Notes

## Debug Logs

- API errors/exceptions are written to `api/cache/logs/api-error.log`.
- For live container output, use `npm run api:logs` or `docker compose logs -f nginx php cron`.
- If an API 500 response includes `request_id`, search that ID in `api/cache/logs/api-error.log` first.

