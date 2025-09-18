# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Development Commands

**⚠️ PRODUCTION ENVIRONMENT WARNING ⚠️**
This codebase is deployed on a DreamHost VPS at https://picfit.ai
Always be cautious when making changes - this affects real users!

### Local Development Server (for testing only)
```bash
./start-local.sh
```
Starts PHP development server on http://localhost:8000 with proper configuration. Creates necessary directories (data, generated, temp_jobs, api) and sets permissions.

### Configuration Setup
```bash
cp env.example .env
# Edit .env with your API keys
```

### Debugging & Monitoring
```bash
php debug.php          # System health check and configuration validation
./watch-logs.php      # Real-time log monitoring
```

### Manual Credit Management (Development)
```bash
php give-credits.php  # Give all users 100 credits for testing
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

#### Core User Tables
- **users**: OAuth and WhatsApp authentication, credit management
  - `id`, `oauth_provider`, `oauth_id`, `email`, `phone_number`
  - `name`, `avatar_url`, `credits_remaining`, `free_credits_used`
  - `stripe_customer_id`, `subscription_status`, `subscription_plan`
  - Unique constraints on email, phone, and OAuth provider combination

- **whatsapp_otps**: WhatsApp OTP authentication
  - `phone_number`, `otp_code`, `expires_at`, `verified`, `attempts`

- **user_sessions**: Database-backed secure sessions
  - `id`, `user_id`, `expires_at`
  - Used for CSRF protection and session management

#### Credit & Payment Tables
- **credit_transactions**: All credit movements
  - `user_id`, `type` (purchase/debit/refund/bonus), `credits`
  - `stripe_session_id`, `stripe_payment_intent_id`
  - Complete audit trail of credit usage

- **webhook_events**: Stripe webhook deduplication
  - `id` (Stripe event ID), `type`, `processed_at`
  - Prevents duplicate webhook processing

#### PicFit Outfit Generation Tables
- **generations**: Virtual try-on generation records
  - `user_id`, `status` (pending/processing/completed/failed)
  - `input_data` (JSON), `input_hash` (for caching)
  - `result_url`, `error_message`, `processing_time`
  - `is_public` (0.5 credits) vs private (1 credit)
  - `share_token` for public photo sharing

- **user_photos**: Stored user photos for outfit generation
  - `user_id`, `filename`, `original_name`, `file_path`
  - `file_size`, `mime_type`, `is_primary`

- **photo_ratings**: Public photo rating system
  - `generation_id`, `rating` (+1/-1), `ip_address`
  - One rating per IP per photo

- **background_jobs**: Async job processing (deprecated)
  - `job_id`, `user_id`, `job_type`, `job_data` (JSON)
  - `status`, `progress`, `progress_stage`
  - `input_hash` for idempotency

#### Ad Generator Tables
- **ad_campaigns**: Marketing campaign metadata
  - `user_id`, `campaign_name`, `style_guide` (JSON)
  - `status` (processing/completed/failed)
  - Contains brand info, colors, messaging

- **ad_generations**: Individual ad records
  - `campaign_id`, `user_id`, `ad_type` (facebook_feed, instagram_story, etc)
  - `width`, `height`, `image_url`, `with_text_url`
  - `prompt_used`, `status`, `processing_time`
  - `is_private` for access control

- **ad_concepts**: Brand concept storage
  - `user_id`, `concept_name`, `concept_data` (JSON)
  - `image_url`, `prompt_used`, `uploaded_assets` (JSON)
  - `is_archived` for organization

- **user_ad_folders**: User-specific ad storage tracking
  - `user_id`, `folder_path`, `last_cleanup`
  - `total_files`, `total_size_bytes`
  - Ensures secure file isolation

#### Security & Performance Tables
- **rate_limits**: Request throttling
  - `id` (IP or user_id hash), `requests`, `window_start`
  - Enforces: 100/hr per IP, 20/hr per user, 5 generations/5min

#### Key Indexes for Performance
- User lookups: `idx_users_email`, `idx_users_oauth`, `idx_users_phone`
- Generation queries: `idx_generations_user`, `idx_generations_public`, `idx_generations_share_token`
- Ad queries: `idx_ad_campaigns_user`, `idx_ad_generations_campaign`
- Performance: `idx_generations_cache_lookup`, `idx_generations_gallery`
- Cleanup: `idx_background_jobs_cleanup`, `idx_user_ad_folders_cleanup`

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
- **DreamHost VPS**: Production environment using environment variables set in control panel

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

## Product Components

### 1. PicFit Outfit Tool (Virtual Try-On)

**Core Files:**
- `generate.php` - Main interface and workflow orchestration
- `includes/AIService.php` - AI generation service using Gemini API

**Key Features:**
- **Outfit Selection System**:
  - Pre-loaded outfit collections (e.g., Emmy's red carpet looks)
  - Custom outfit upload
  - Cached outfit catalog for performance
- **Person Photo Management**:
  - Stored photo system via `UserPhotoService.php`
  - Upload new photos with optional saving
  - Primary photo selection
- **Credit System**:
  - 0.5 credits for public generations
  - 1.0 credit for private generations
  - Tier-based rate limiting (free: 3/hr, paid: 10/hr, premium: 20/hr)
- **AI Processing Pipeline**:
  - Image optimization (resize to 1024px max dimension, JPEG conversion)
  - Single Gemini API call with combined prompt
  - Direct processing (no background jobs)
  - CDN integration for result delivery

**Technical Details:**
- Uses Gemini 2.5 Flash Image Preview model
- Implements retry logic (3 attempts) for API resilience
- Base64 image encoding for API transmission
- Square format (1:1) output for consistency
- Preserves facial features and body proportions from source photo

### 2. PicFit Ad Generator (Marketing Material Creation)

**Core Files:**
- `figma-ad-generator.php` - Multi-step wizard interface with retro styling
- `ad-randomizer.php` - Alternative quick-generation interface
- `includes/AdGeneratorService.php` - Core ad generation logic
- `view-campaign.php` - Campaign management and viewing
- `serve-ad.php` - Secure ad image delivery

**Key Features:**
- **Multi-Platform Ad Sizes**:
  - 25+ predefined sizes for all major platforms
  - Facebook, Instagram, Twitter/X, LinkedIn, TikTok, YouTube, Pinterest
  - Google Ads display formats
  - Universal formats (landscape, portrait, square)
- **Brand Asset Integration**:
  - Figma design extraction via `FigmaService.php`
  - Document parsing (PDF, DOC) via `DocumentParserService.php`
  - Style guide persistence across campaigns
- **Campaign Management**:
  - Batch generation for multiple sizes
  - Campaign organization and history
  - Private user folders for security
- **AI Generation**:
  - Uses Gemini or OpenAI APIs
  - Context-aware prompt generation based on platform
  - Automatic text overlay capabilities
  - Brand consistency across all sizes

**Database Schema Extensions:**
- `ad_campaigns` - Campaign metadata and style guides
- `ad_generations` - Individual ad records with dimensions
- `ad_templates` - Reusable templates (if enabled)

### Shared Services
- **Database.php**: SQLite database management with singleton pattern
- **Session.php**: Secure authentication handling
- **Config.php**: Environment configuration loader
- **Logger.php**: Centralized logging system
- **CDNService.php**: CDN integration for images
- **StripeService.php**: Payment processing and webhooks
- **UserPhotoService.php**: User photo management
- **BackgroundJobService.php**: Asynchronous job processing

## Important Directories

- `generated/`: AI-generated images (web-accessible)
- `user_photos/`: User uploaded photos (protected)
- `temp_jobs/`: Background job temporary files (required for async processing)
- `ad-generator/`: Ad generation assets and templates
- `data/`: SQLite database files
- `logs/`: Application logs
- `cache/`: Cached data files

## Deployment Notes

### Production Environment - DreamHost VPS
**IMPORTANT: This is a PRODUCTION environment on DreamHost VPS**
- Live site at https://picfit.ai
- Real users and real payments processing
- Environment variables set via DreamHost control panel
- SQLite database in `data/` directory
- Background jobs processed via cron (if configured)
- Uses `.htaccess` for URL rewriting and security
- PHP-FPM configuration (not mod_php)

### Required PHP Extensions
- SQLite3
- cURL
- JSON
- FileInfo
- GD or ImageMagick (for image processing)

### Production Considerations
- **NEVER** enable debug output in production
- **ALWAYS** test changes locally first
- **DO NOT** commit API keys or sensitive data
- **VERIFY** file permissions (755 for directories, 644 for files)
- **CHECK** error logs at `logs/app_YYYY-MM-DD.log` before deploying changes
- Generated content in `generated/` must have proper web-accessible permissions

## Common Development Tasks

### Adding New Outfit Collections
1. Create subdirectory in `images/outfits/` with collection name
2. Add collection metadata to `$featuredCollections` array in `generate.php:42`
3. Clear cache file at `cache/outfit_collections_v2.json`

### Testing AI Generation
```bash
# Check API configuration
php debug.php

# Monitor generation logs
./watch-logs.php

# Test with minimal credits
php give-credits.php  # Gives 100 credits to all users
```

### Database Operations
- Database auto-creates schema on first connection
- Located at `data/app.sqlite`
- Backup before schema changes: `cp data/app.sqlite data/app.sqlite.backup`

### Debugging Failed Generations
1. Check `logs/app_YYYY-MM-DD.log` for error details
2. Verify API keys in `.env` or environment variables
3. Check rate limits in database: `SELECT * FROM rate_limits`
4. Test Gemini API directly: Use `debug.php` for validation

### Performance Optimization
- Outfit collections are cached for 1 hour
- CDN handles image delivery (Cloudflare/BunnyCDN)
- Direct processing eliminates job queue overhead
- Image optimization reduces API payload size