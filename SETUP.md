# PicFit.ai - Quick Setup Guide

## ✅ What's Been Built

I've completely transformed your PicFit.ai repo into a production-ready application:

### 🔧 Core Features
- ✅ **Google OAuth Authentication** - Secure user login
- ✅ **Stripe Payment Integration** - Credit-based pricing system  
- ✅ **AI Generation Pipeline** - Gemini API integration for outfit try-ons
- ✅ **Modern UI** - Clean, responsive interface with Tailwind CSS
- ✅ **User Dashboard** - Credit tracking, generation history
- ✅ **Security Hardening** - CSRF protection, rate limiting, input validation
- ✅ **DreamHost Ready** - Optimized for shared hosting deployment

### 📁 New File Structure
```
picfitai/
├── auth/                   # OAuth authentication
├── includes/               # Core PHP classes  
├── webhooks/              # Stripe webhook handler
├── generated/             # AI generated images
├── data/                  # SQLite database
├── config.php             # Centralized configuration
├── bootstrap.php          # App initialization
├── index.php              # Landing page
├── dashboard.php          # User dashboard
├── generate.php           # AI generation interface
├── pricing.php            # Pricing & checkout
├── profile.php            # User profile
├── success.php            # Payment success
├── .htaccess             # Apache configuration
├── README.md             # Detailed documentation
└── start-local.sh        # Local dev server script
```

## 🚀 Quick Start (2 minutes)

### 1. Copy Configuration
```bash
cp env.example .env
```

### 2. Add Your API Keys to `.env`
```bash
# Google OAuth (Google Cloud Console)
GOOGLE_CLIENT_ID=your_client_id
GOOGLE_CLIENT_SECRET=your_client_secret

# Stripe (Stripe Dashboard)  
STRIPE_SECRET_KEY=sk_test_...
STRIPE_PUBLISHABLE_KEY=pk_test_...
STRIPE_WEBHOOK_SECRET=whsec_...

# AI (Google AI Studio)
GEMINI_API_KEY=your_gemini_key
```

### 3. Start Local Server
```bash
./start-local.sh
```

Visit: **http://localhost:8000**

## 🔑 API Key Setup (5 minutes)

### Google OAuth
1. Go to [Google Cloud Console](https://console.cloud.google.com/)
2. Create project → Enable Google+ API → Create OAuth credentials
3. Set redirect URI: `http://localhost:8000/auth/callback.php`
4. Copy Client ID & Secret to `.env`

### Stripe
1. Create account at [Stripe.com](https://stripe.com)
2. Get API keys from Dashboard
3. Create webhook: `http://localhost:8000/webhooks/stripe.php`
4. Enable events: `checkout.session.completed`, `payment_intent.succeeded`
5. Copy keys & webhook secret to `.env`

### Gemini AI
1. Get key from [Google AI Studio](https://makersuite.google.com/app/apikey)
2. Add to `.env`

## 🌐 Production Deployment

### DreamHost Deployment
1. Upload all files to your domain directory
2. Set environment variables in DreamHost panel (Websites → Environment Variables)
3. Update webhook URL to your domain
4. Done! 

## 🎯 What You Get

### For Users:
- **Free Trial** - 1 generation per email
- **Credit System** - Buy credits for more generations  
- **Modern UI** - Clean, mobile-friendly interface
- **Secure Login** - Google OAuth integration
- **Generation History** - View and download past results

### For You:
- **Complete Analytics** - User activity, credits, generations
- **Secure Architecture** - Rate limiting, CSRF protection, input validation
- **Scalable Database** - SQLite with proper schema and indexes
- **Error Handling** - Comprehensive logging and user-friendly error pages
- **Payment Processing** - Secure Stripe integration with webhook verification
- **AI Integration** - Gemini API for realistic outfit generation

## 💰 Revenue Model
- **Free tier**: 1 generation per user
- **Paid tiers**: 
  - 10 credits for $9 ($0.90/generation)
  - 50 credits for $29 ($0.58/generation) 
  - 250 credits for $99 ($0.40/generation)

## 🛡️ Security Features
- HTTPS enforcement
- CSRF token validation
- Rate limiting (per IP and user)
- SQL injection prevention
- XSS protection
- Secure session management
- Stripe webhook signature verification
- Input validation and sanitization

## 📊 Database Schema
- **users** - OAuth accounts with credit balances
- **credit_transactions** - Purchase and usage history
- **generations** - AI generation requests and results
- **user_sessions** - Secure session management
- **rate_limits** - Rate limiting data
- **webhook_events** - Stripe event deduplication

## 🔧 Technical Stack
- **Backend**: PHP 8+ with SQLite
- **Frontend**: Vanilla JS + Tailwind CSS
- **Authentication**: Google OAuth 2.0
- **Payments**: Stripe Checkout + Webhooks
- **AI**: Google Gemini API
- **Hosting**: DreamHost shared hosting ready
- **Security**: Comprehensive hardening

## ✨ Ready to Launch!

Your app is now production-ready. Just add your API keys and you're good to go!

Need help? Check the detailed README.md or contact support.
