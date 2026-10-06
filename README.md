# PicFit.ai

Upload a photo of yourself, see yourself in a different outfit. PHP + SQLite, Gemini for the images, Stripe for credits.

```bash
cp env.example .env                              # add your keys
php -c php.ini -S localhost:8000 router.php      # http://localhost:8000
```

Production is a DreamHost VPS at https://picfit.ai with real users. Be careful. See [CLAUDE.md](CLAUDE.md).

## Get started

1. `cp env.example .env` and fill in Google OAuth, Stripe and `GEMINI_API_KEY`.
2. `mkdir -p data generated` (SQLite and generated images live there; the schema is created on first request).
3. `php -c php.ini -S localhost:8000 router.php` from the repo root.
4. Sign in with Google. Run `php give-credits.php` to give every local user 100 credits.

## What it does

- Sign in with Google (Instagram and WhatsApp login are also wired up).
- Upload photos, pick an outfit, and `generate.php` sends them to Gemini (OpenAI as fallback). Results land in `generated/`.
- Each generation costs credits. Credits are bought on `pricing.php` through Stripe Checkout and granted by `webhooks/stripe.php`.
- Public results get a share link at `/share/<token>` and can be rated.
- A separate ad generator (`ad-generator/`, `ad-dashboard.php`) makes social ad images for a brand.

## Usage

| | |
|---|---|
| `php debug.php` | check config and system health |
| `./watch-logs.php` | tail the app logs |
| `php give-credits.php` | give all users 100 credits (testing only) |
| `npm run dev` | serve the static `dist/` copy of the marketing pages |

## Config

Set in `.env` locally, or as environment variables in the DreamHost panel:

- `GOOGLE_CLIENT_ID`, `GOOGLE_CLIENT_SECRET` (redirect URI: `/auth/callback.php`)
- `STRIPE_SECRET_KEY`, `STRIPE_PUBLISHABLE_KEY`, `STRIPE_WEBHOOK_SECRET` (webhook: `/webhooks/stripe.php`)
- `GEMINI_API_KEY`, `OPENAI_API_KEY`
- Optional: `INSTAGRAM_CLIENT_ID`, `INSTAGRAM_CLIENT_SECRET`, `INSTAGRAM_REDIRECT_URI`, `TWILIO_*`, `FIGMA_API_KEY`

## Deploy

Upload the files to the domain directory, set the env vars in the DreamHost panel, and make sure `data/` is writable. More in [DREAMHOST_SETUP.md](DREAMHOST_SETUP.md) and [SETUP.md](SETUP.md).
