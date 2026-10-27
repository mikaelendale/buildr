# Contributing

Thanks for helping with Buildr. Every change, including the maintainer's, goes in through a pull request: `main` is protected and deploys to production on every merge.

## How to send a change

1. **Fork** the repository and clone your fork.
2. **Branch** from `main`: `git checkout -b fix/short-description`.
3. **Set it up** with the steps in the [README](README.md#run-it-locally).
4. **Make the change**, with a test for anything that behaves differently.
5. **Run the checks** (CI runs the same ones):

    ```bash
    vendor/bin/pest
    vendor/bin/phpstan analyse
    vendor/bin/pint --test
    npx tsc --noEmit
    npm run check
    ```

6. **Open a pull request** against `main`. Say what changed and why, how you tested it, and add screenshots for anything visual (light and dark, desktop and phone).

A pull request can be merged once:

- the `sqlite` and `postgres` checks pass,
- a maintainer has approved it,
- every review comment is resolved.

## Guidelines

- Keep pull requests small and about one thing; open an issue first for big changes.
- Match the code around you: naming, comment style, and the design system in `resources/css/app.css` (no new ad-hoc colors).
- AI calls go through the Laravel AI SDK (`laravel/ai`), never a provider's own PHP SDK.
- Tests never call Telegram or an AI provider; fake them (see `tests/Fixtures/telegram` and `Http::fake()`).
- Never commit `.env`, keys or real people's data. Fixtures are made up.

## Security

Found a vulnerability? Please don't open an issue or a pull request for it: see [SECURITY.md](SECURITY.md).
