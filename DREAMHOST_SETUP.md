# DreamHost VPS Setup Instructions

## Background Job Processing Setup

The application uses background job processing for AI image generation. To enable this on DreamHost VPS, you need to set up a cron job via SSH.

### Step 1: Set up Cron Job via SSH

1. **SSH into your server** using your Shell user
2. **Edit your crontab file**:
   ```bash
   crontab -e
   ```
3. **Set up email delivery** when prompted:
   - Enter your email address for cron job output
   - Type `y` to confirm
4. **Choose editor** (recommend #6 for nano - easiest option)
5. **Add this cron job** to the file:
   ```bash
   # PicFit.ai background job processor - runs every minute
   MAILTO="your-email@example.com"
   * * * * * /usr/local/php82/bin/php /home/natemcguirellc/Projects/picfitai/process_jobs.php
   ```
6. **Save the file** (in nano: Ctrl+X, then Y, then Enter)

**Important Notes:**
- Replace `your-email@example.com` with your actual email
- Replace `/home/natemcguirellc/Projects/picfitai/` with your actual project path
- Make sure to press Enter at the end of the cron job line (newline character required)
- Check PHP version: you might need `/usr/local/php83/bin/php` instead of php82

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