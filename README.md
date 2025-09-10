# PicFit.ai - AI Virtual Try-On

A complete AI-powered virtual try-on application built for DreamHost shared hosting with PHP.

## Features

- 🔐 Google OAuth authentication
- 💳 Stripe payment integration with credit system
- 🤖 AI-powered outfit generation (Gemini API)
- 📱 Responsive modern UI
- 🛡️ Security hardening with rate limiting
- 📊 User dashboard and analytics
- ☁️ DreamHost shared hosting ready

## Quick Start

### 1. Clone and Setup

```bash
git clone <your-repo>
cd picfitai
cp env.example .env
```

### 2. Configure Environment

Edit `.env` file with your API keys:

```bash
# Google OAuth (get from Google Cloud Console)
GOOGLE_CLIENT_ID=your_google_client_id_here
GOOGLE_CLIENT_SECRET=your_google_client_secret_here

# Stripe (get from Stripe Dashboard)
STRIPE_SECRET_KEY=sk_test_or_live_key_here
STRIPE_PUBLISHABLE_KEY=pk_test_or_live_key_here
STRIPE_WEBHOOK_SECRET=whsec_webhook_secret_here

# AI Generation (get from Google AI Studio)
GEMINI_API_KEY=your_gemini_api_key_here
```

### 3. Run Locally

```bash
php -S localhost:8000 -t /Users/nate/Projects/picfitai
```

Visit: http://localhost:8000

## Configuration Guide

### Google OAuth Setup

1. Go to [Google Cloud Console](https://console.cloud.google.com/)
2. Create a new project or select existing
3. Enable Google+ API
4. Create OAuth 2.0 credentials
5. Add authorized redirect URI: `http://localhost:8000/auth/callback.php` (or your domain)
6. Copy Client ID and Secret to `.env`

### Stripe Setup

1. Create account at [Stripe](https://stripe.com)
2. Get API keys from Dashboard
3. Set up webhook endpoint: `https://yourdomain.com/webhooks/stripe.php`
4. Enable these webhook events:
   - `checkout.session.completed`
   - `payment_intent.succeeded`
5. Copy webhook secret to `.env`

### Gemini AI Setup

1. Get API key from [Google AI Studio](https://makersuite.google.com/app/apikey)
2. Add to `.env` file

## Deployment to DreamHost

### 1. Upload Files

Upload all files to your domain's directory (usually `yourdomain.com/`)

### 2. Set Environment Variables

In DreamHost panel, go to:
- Websites → Manage → Environment Variables
- Add each variable from your `.env` file

### 3. Configure Webhooks

Update Stripe webhook URL to: `https://yourdomain.com/webhooks/stripe.php`

### 4. Set Permissions

Ensure the `data/` directory is writable:
```bash
chmod 755 data/
```

## File Structure

```
picfitai/
├── auth/                   # OAuth authentication
│   ├── login.php
│   ├── callback.php
│   └── logout.php
├── includes/               # Core classes
│   ├── Database.php        # Database management
│   ├── Session.php         # Session handling
│   ├── StripeService.php   # Stripe integration
│   ├── AIService.php       # AI generation
│   ├── Security.php        # Security utilities
│   └── ErrorHandler.php    # Error handling
├── webhooks/               # External webhooks
│   └── stripe.php          # Stripe webhook handler
├── generated/              # AI generated images
├── data/                   # SQLite database
├── images/                 # Static images
├── config.php              # Configuration
├── bootstrap.php           # App initialization
├── index.php               # Landing page
├── dashboard.php           # User dashboard
├── generate.php            # AI generation interface
├── pricing.php             # Pricing page
├── profile.php             # User profile
├── success.php             # Payment success
└── README.md               # This file
```

## Database Schema

The app uses SQLite with these tables:

- `users` - User accounts with OAuth data
- `credit_transactions` - Credit purchases and usage
- `generations` - AI generation requests and results
- `user_sessions` - Secure session management
- `rate_limits` - Rate limiting data
- `webhook_events` - Stripe webhook deduplication

## Security Features

- ✅ CSRF protection
- ✅ Rate limiting (per IP and per user)
- ✅ SQL injection prevention
- ✅ XSS protection
- ✅ Secure session management
- ✅ Stripe webhook signature verification
- ✅ Input validation and sanitization

## API Endpoints

- `POST /generate.php` - Generate AI outfit preview
- `POST /webhooks/stripe.php` - Handle Stripe webhooks
- `GET /auth/login.php` - OAuth login
- `GET /auth/callback.php` - OAuth callback
- `POST /pricing.php` - Create Stripe checkout session

## Troubleshooting

### Common Issues

1. **"Google OAuth not configured"**
   - Check your `GOOGLE_CLIENT_ID` and `GOOGLE_CLIENT_SECRET`
   - Verify redirect URI in Google Console

2. **"Stripe error"**
   - Verify Stripe API keys
   - Check webhook endpoint is accessible
   - Ensure webhook secret is correct

3. **"Database connection failed"**
   - Check file permissions on `data/` directory
   - Ensure SQLite is enabled in PHP

4. **AI generation fails**
   - Verify Gemini API key
   - Check image file sizes (max 10MB)
   - Ensure images are valid formats (JPEG, PNG, WebP)

### Logs

Check error logs in:
- DreamHost: `~/logs/yourdomain.com/http/error.log`
- Local: PHP error log or console output

### Rate Limits

- 100 requests per hour per IP
- 20 requests per hour per user
- 5 AI generations per 5 minutes per user

## Support

For issues or questions:
- Check the troubleshooting section above
- Review error logs
- Contact: support@picfit.ai

## License

Proprietary - All rights reserved
