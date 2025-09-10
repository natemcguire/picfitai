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
mkdir -p data generated

# Set permissions
chmod 755 data generated

echo "✅ Starting PHP development server on http://localhost:8000"
echo "📖 See README.md for configuration instructions"
echo "🛑 Press Ctrl+C to stop the server"
echo ""

# Start the server with custom php.ini
php -c php.ini -S localhost:8000 -t /Users/nate/Projects/picfitai
