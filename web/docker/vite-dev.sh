#!/bin/sh
set -e

# Clean stale hot file from previous run (prevents broken URLs on restart)
rm -f public/hot

# Install dependencies (safe to re-run; uses cache)
npm install --no-audit --no-fund

# Start Vite dev server in background.
# Must bind to 0.0.0.0 so Docker port mapping exposes it to the host.
# laravel-vite-plugin writes 0.0.0.0 into public/hot which browsers cannot
# connect to — we fix that below.
npx vite --host 0.0.0.0 --port 5173 &
VITE_PID=$!

# Wait for the laravel-vite-plugin to write public/hot (max 15 seconds)
for i in $(seq 1 15); do
    if [ -f public/hot ]; then
        break
    fi
    sleep 1
done

# Replace 0.0.0.0 with localhost: browsers need a real hostname,
# and Docker publishes 5173 on the host loopback.
if [ -f public/hot ]; then
    echo 'http://localhost:5173' > public/hot
    echo "[visionSY] Fixed public/hot → http://localhost:5173"
fi

# Wait for Vite to exit (keeps container alive)
wait $VITE_PID
