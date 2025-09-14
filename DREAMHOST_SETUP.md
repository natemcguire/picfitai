# DreamHost VPS Setup Instructions

## Background Job Processing Setup

The application uses background job processing for AI image generation. To enable this on DreamHost VPS, you need to set up a cron job.

### Step 1: Set up Cron Job

1. Log into your DreamHost panel
2. Go to "Cron Jobs" section
3. Add a new cron job with these settings:

```bash
# Run every minute to process background jobs
* * * * * /usr/local/php82/bin/php /home/natemcguirellc/Projects/picfitai/process_jobs.php

# Alternative: Run every 2 minutes (less frequent but still responsive)
*/2 * * * * /usr/local/php82/bin/php /home/natemcguirellc/Projects/picfitai/process_jobs.php
```

**Important**: Replace `/home/natemcguirellc/Projects/picfitai/` with your actual path to the project.

### Step 2: Ensure Directory Permissions

Make sure these directories exist and are writable:
- `temp_jobs/` - For temporary job files
- `generated/` - For generated images
- `user_photos/` - For user uploaded photos
- `data/` - For SQLite database

```bash
chmod 755 temp_jobs generated user_photos data
chmod 644 data/app.sqlite  # If it exists
```

### Step 3: Test the Background Processor

You can manually test the background job processor:

```bash
cd /home/natemcguirellc/Projects/picfitai
/usr/local/php82/bin/php process_jobs.php
```

### Step 4: Monitor Logs

Check the application logs to ensure jobs are being processed:
- Look for "BackgroundJobProcessor - Processed jobs" messages
- Check for any error messages

### Troubleshooting

1. **No jobs being processed**: Check if cron job is running and paths are correct
2. **Permission errors**: Ensure web server can write to temp_jobs, generated, and user_photos directories
3. **PHP path issues**: Verify PHP path with `which php` on your server

### DreamHost Specific Notes

- DreamHost VPS typically uses `/usr/local/php82/bin/php` for PHP 8.2
- Make sure your cron job uses the full path to PHP
- The process_jobs.php script prevents web access for security