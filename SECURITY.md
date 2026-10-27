# Security

## Reporting a vulnerability

Please don't open a public issue for security problems. Send a private message on Telegram to [@mike_endale](https://t.me/mike_endale), or use GitHub's "Report a vulnerability" button on this repository (Security tab), with:

- what you found and where (URL or file and line),
- how to reproduce it,
- what an attacker could do with it.

You'll get a reply within a few days. Please give us a reasonable time to fix it before you talk about it publicly. Testing against the live site is fine as long as you only use your own accounts and don't touch other people's data, spam the bot, or degrade the service.

## Running your own copy

- Never run production with `APP_ENV=local`: the local "dev login" button on `/login` signs anyone in without a password when the environment is `local`.
- Keep every key in environment variables (`.env` is git-ignored): the Telegram bot token, `TELEGRAM_WEBHOOK_SECRET`, AI provider keys, database and storage credentials.
- Set a long random `TELEGRAM_WEBHOOK_SECRET`; webhook calls without it are refused.
- Admins sign in at `/admin/login` with a password and a required authenticator app (2FA). Create them with `php artisan buildr:make-admin`.
