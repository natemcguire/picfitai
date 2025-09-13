#!/bin/bash
# start-local.sh - Start local development server

echo "🚀 Starting PicFit.ai local development server..."

# Check if .env file exists
if [ ! -f .env ]; then
    echo "⚠️  .env file not found. Creating from template..."
    cp env.example .env
    echo "📝 Please edit .env file with your API keys before starting the server."
    echo "   See README.md for setup instructions."
    exit 1
fi

# Check if PHP is available
if ! command -v php &> /dev/null; then
    echo "❌ PHP is not installed or not in PATH"
    exit 1
fi

# Create necessary directories
mkdir -p data generated temp_jobs api

# Set permissions
chmod 755 data generated temp_jobs api

# Function to cleanup background processes
cleanup() {
    echo ""
    echo "🛑 Shutting down servers..."
    if [ ! -z "$JOB_PROCESSOR_PID" ]; then
        kill $JOB_PROCESSOR_PID 2>/dev/null
        echo "✅ Background job processor stopped"
    fi
    if [ ! -z "$WEB_SERVER_PID" ]; then
        kill $WEB_SERVER_PID 2>/dev/null
        echo "✅ Web server stopped"
    fi
    exit 0
}

# Set up signal handlers
trap cleanup SIGINT SIGTERM

echo "✅ Starting PHP development server on http://localhost:8000"
echo "✅ Starting background job processor"
echo "📖 See README.md for configuration instructions"
echo "🛑 Press Ctrl+C to stop both servers"
echo ""

# Start the web server in background with router for URL rewriting
php -c php.ini -S localhost:8000 -t /Users/nate/Projects/picfitai router.php &
WEB_SERVER_PID=$!

# Wait a moment for web server to start
sleep 2

# Start the background job processor
while true; do
    php process_jobs.php
    sleep 5  # Process jobs every 5 seconds
done &
JOB_PROCESSOR_PID=$!

echo "🌐 Web server running at http://localhost:8000 (PID: $WEB_SERVER_PID)"
echo "⚙️  Job processor running (PID: $JOB_PROCESSOR_PID)"
echo ""

# Wait for any background job to finish
wait
