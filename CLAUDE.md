# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Development Commands

### Local Development Server
```bash
./start-local.sh
```
Starts PHP development server on http://localhost:8000 with proper configuration.

### Configuration Setup
```bash
cp env.example .env
# Edit .env with your API keys
```

### Log Monitoring
```bash
./watch-logs.php
```

### Debugging
```bash
php debug.php
```

## Architecture Overview

### Core Structure
PicFit.ai is a PHP-based AI virtual try-on application designed for shared hosting environments. The architecture follows a modular pattern with centralized configuration and service-oriented design.

**Key Design Principles:**
- **Shared Hosting Optimized**: Uses SQLite, .env files, and minimal dependencies
- **Security-First**: CSRF protection, rate limiting, input validation, secure sessions
- **Service Layer Pattern**: Core functionality separated into dedicated service classes
- **Configuration Centralization**: Single Config class handles environment variables

### Core Services (`includes/`)

- **Database.php**: SQLite database management with schema auto-creation
- **Session.php**: Secure session handling with database storage
- **StripeService.php**: Payment processing and webhook handling
- **AIService.php**: AI generation using Gemini/OpenAI APIs
- **Security.php**: CSRF tokens, rate limiting, input sanitization
- **ErrorHandler.php**: Centralized error handling and logging
- **Logger.php**: Application logging system

### Authentication Flow
1. **OAuth Login** (`auth/login.php`) → Google OAuth redirect
2. **Callback Handler** (`auth/callback.php`) → User creation/login
3. **Session Management** → Database-backed sessions with CSRF protection

### Payment Flow
1. **Pricing Page** (`pricing.php`) → Plan selection
2. **Stripe Checkout** → External payment processing
3. **Webhook Handler** (`webhooks/stripe.php`) → Credit allocation
4. **Success Page** (`success.php`) → Confirmation and redirect

### AI Generation Pipeline
1. **Image Upload** (`generate.php`) → Validation and storage
2. **Credit Check** → Verify user has sufficient credits
3. **AI Processing** → Gemini API integration for outfit generation
4. **Result Storage** → Generated images saved to `generated/` directory
5. **Database Logging** → Generation history and analytics

### Database Schema (SQLite)
- **users**: OAuth user data, credit balances
- **credit_transactions**: Purchase/usage history with Stripe integration
- **generations**: AI generation requests, results, and processing times
- **user_sessions**: Secure session data with CSRF tokens
- **rate_limits**: Per-IP and per-user rate limiting data
- **webhook_events**: Stripe webhook deduplication

### Security Implementation
- **Rate Limiting**: 100 requests/hour per IP, 20/hour per user, 5 generations per 5 minutes
- **CSRF Protection**: Tokens generated per session and validated on state-changing operations
- **Input Validation**: File type/size validation, SQL injection prevention
- **Session Security**: Database-backed sessions with secure cookies
- **Webhook Security**: Stripe signature verification
- **File Protection**: `.htaccess` files protect sensitive directories

## Configuration Management

### Environment Variables
The `Config` class loads from:
1. System environment variables (production)
2. `.env` file (development)

**Required Configuration:**
- `GOOGLE_CLIENT_ID` / `GOOGLE_CLIENT_SECRET`: OAuth authentication
- `STRIPE_SECRET_KEY` / `STRIPE_PUBLISHABLE_KEY` / `STRIPE_WEBHOOK_SECRET`: Payment processing
- `GEMINI_API_KEY`: AI generation service

### Deployment Contexts
- **Local Development**: Uses `.env` file and built-in PHP server
- **DreamHost Shared Hosting**: Uses environment variables set in control panel

## Development Patterns

### Error Handling
All errors are centralized through `ErrorHandler::init()` and logged appropriately. User-facing errors are sanitized while detailed errors go to logs.

### Database Queries
Always use prepared statements. The Database class is a singleton that auto-creates the schema on first connection.

### File Uploads
- Maximum 10MB file size
- JPEG/PNG/WebP formats only
- Files are validated before processing
- Generated images stored in web-accessible `generated/` directory

### API Integration
- Gemini API is primary AI service with OpenAI as fallback
- Stripe webhooks handle payment confirmations asynchronously
- All external API calls include error handling and timeout management

## Key Files to Understand

- `config.php:16-116`: Centralized configuration management
- `bootstrap.php`: Application initialization sequence
- `includes/Database.php:50-150`: Database schema and setup
- `includes/Security.php`: Security utilities and validation
- `generate.php:16-100`: Core AI generation workflow
- `webhooks/stripe.php`: Payment webhook processing

## Testing and Debugging

The application includes a debug interface at `debug.php` that shows:
- Configuration status
- Database connectivity
- API key validation
- Recent errors and logs

Always test payment flows with Stripe test keys before production deployment.