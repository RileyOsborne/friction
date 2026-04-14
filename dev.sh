#!/bin/bash

# Ensure database exists
if [ ! -f database/database.sqlite ]; then
    echo "Creating database/database.sqlite..."
    touch database/database.sqlite
    php artisan migrate --seed
fi

# Run any pending migrations
php artisan migrate --force

# Detect Local IP
if [[ "$OSTYPE" == "darwin"* ]]; then
    LOCAL_IP=$(ipconfig getifaddr en0 || ipconfig getifaddr en1)
else
    LOCAL_IP=$(hostname -I | awk '{print $1}')
fi

echo "Detected Local IP: $LOCAL_IP"
echo "Starting Friction at http://$LOCAL_IP:8888"

# Start services concurrently with the detected IP
# We override the environment variables for this process only
export APP_URL="http://$LOCAL_IP:8888"
export REVERB_HOST="$LOCAL_IP"
export VITE_REVERB_HOST="$LOCAL_IP"
export VITE_HMR_HOST="$LOCAL_IP"

npx concurrently -c "#93c5fd,#c4b5fd,#fb7185,#fdba74,#86efac" \
    "php artisan serve --host=0.0.0.0 --port=8888" \
    "php artisan queue:listen --tries=1" \
    "php artisan reverb:start --host=0.0.0.0 --port=8989" \
    "php artisan pail --timeout=0" \
    "npm run dev" \
    --names=server,queue,reverb,logs,vite --kill-others
