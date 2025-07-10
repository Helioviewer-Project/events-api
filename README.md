# events-api
A unified API and event database that collects, normalizes, and serves solar event data from multiple sources (HEK, CCMC, WSA, RHESSI) for use in Helioviewer.org and related tools.

## Development Setup

This project uses Docker Compose for local development with PHP 8.4 and Nginx.

### Prerequisites
- Docker
- Docker Compose
- Make (optional, for convenience commands)

### Getting Started

1. **Start the development environment:**
   ```bash
   make up
   ```
   Or manually:
   ```bash
   docker compose -f docker/docker-compose.yml up -d
   ```

2. **Install PHP dependencies:**
   ```bash
   make composer-install
   ```

3. **Access the application:**
   - Open http://localhost:8082 in your browser
   - You should see the PHP info page

### Available Make Commands

- `make up` - Start the Docker containers
- `make down` - Stop the Docker containers
- `make build` - Build the Docker images
- `make composer-install` - Install PHP dependencies via Composer
- `make shell` - Open a bash shell in the PHP container

### Architecture

- **Nginx** (`eventsapi-nginx`) - Web server listening on port 8082
- **PHP-FPM** (`eventsapi-phpfpm`) - PHP 8.4 with Composer
- **Network** - All services communicate via `eventsapi` network
- **Document Root** - `/u/apps/site/public` (maps to `./public/`)
