#!/bin/sh
set -e

# Clean stale hot file from previous run (prevents broken URLs on restart)
rm -f public/hot

# Install dependencies (safe to re-run; uses cache)
npm install --no-audit --no-fund

# Start Vite dev server in background.
# Must bind to 0.0.0.0 so Docker port mapping exposes it to the host.
# laravel-vite-plugin writes 0.0.0.0 into public/hot which browsers cannot
# connect to; we fix that below.
npx vite --host 0.0.0.0 --port 5173 &
VITE_PID=$!

# Wait for the laravel-vite-plugin to write public/hot (max 15 seconds)
for i in $(seq 1 15); do
    if [ -f public/hot ]; then
        break
    fi
    sleep 1
done

# Advertise the browser-accessible URL; localhost remains the default.
if [ -f public/hot ]; then
    DEV_SERVER_URL="${VITE_DEV_SERVER_URL:-http://localhost:5173}"
    printf '%s\n' "$DEV_SERVER_URL" > public/hot
    printf '[visionSY] Fixed public/hot -> %s\n' "$DEV_SERVER_URL"
fi

# Wait for Vite to exit (keeps container alive)
wait $VITE_PID
