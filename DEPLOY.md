# Self-Hosting Friction

Friction is designed to be easily self-hosted using Docker. The image is automatically built and pushed to the GitHub Container Registry (GHCR).

## Quick Start

The simplest way to run Friction is using `docker-compose`.

```yaml
services:
  app:
    image: ghcr.io/rileyosborne/friction:latest
    container_name: friction
    restart: unless-stopped
    ports:
      - "8888:80"
      - "8989:8989"
    volumes:
      - friction-data:/var/www/html/database
    environment:
      - APP_NAME=Friction
      - APP_URL=http://your-ip-or-domain:8888
      - REVERB_HOST=your-ip-or-domain
      - VITE_REVERB_HOST=your-ip-or-domain

volumes:
  friction-data:
```

## TrueNAS / Custom App Setup

When setting up a Custom App in TrueNAS (or any other container manager), use these settings:

### Container Settings
- **Image:** `ghcr.io/rileyosborne/friction:latest`
- **Container Port 80:** Maps to your preferred web port (e.g., `8888`)
- **Container Port 8989:** Maps to `8989` (Required for WebSockets/Reverb)

### Environment Variables
| Variable | Value | Description |
|----------|-------|-------------|
| `APP_URL` | `http://your-server-ip:8888` | The base URL used for links and assets |
| `REVERB_HOST` | `your-server-ip` | IP/Domain for WebSocket connections |
| `VITE_REVERB_HOST` | `your-server-ip` | Same as REVERB_HOST (used by frontend) |
| `APP_KEY` | (Optional) | If omitted, one will be generated on first boot |

### Storage (Volumes)
Map a persistent volume to:
- **Mount Path:** `/var/www/html/database`
- This ensures your games, categories, and players are saved when the container restarts.

## First Run
On the first run, the container will automatically:
1. Generate an `APP_KEY` (if not provided)
2. Create the SQLite database file
3. Run all migrations
4. Start Nginx, PHP-FPM, the Queue worker, and Reverb
