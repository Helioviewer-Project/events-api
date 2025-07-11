# events-api
A unified API and event database that collects, normalizes, and serves solar event data from multiple sources (HEK, CCMC, WSA, RHESSI) for use in Helioviewer.org and related tools.

## Development Setup

This project uses Docker Compose for local development with PHP 8.4, Nginx, and PostgreSQL.

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
   docker compose -f docker/docker-compose.yml up
   ```

2. **Install PHP dependencies:**
   ```bash
   make composer-install
   ```

3. **Run database migrations:**
   ```bash
   make migrate-run
   ```

4. **Seed the database with sample data:**
   ```bash
   make seed-run
   ```

5. **Access the application:**
   - Open http://localhost:8082 in your browser
   - You should see the PHP info page

### Available Make Commands

For a complete list of available commands, run:
```bash
make
```

#### Docker Commands
- `make up` - Start the Docker containers
- `make down` - Stop the Docker containers
- `make build` - Build the Docker images
- `make shell` - Open a bash shell in the PHP container

#### Composer Commands
- `make composer-install` - Install PHP dependencies via Composer
- `make composer-require PACKAGE=package-name` - Add a new package

#### Database Migration Commands
- `make migrate-status` - Check migration status
- `make migrate-create NAME=MigrationName` - Create a new migration
- `make migrate-run` - Run pending migrations
- `make migrate-rollback` - Rollback the last migration
- `make seed-run` - Run database seeders

### Architecture

- **Nginx** (`eventsapi-nginx`) - Web server listening on port 8082
- **PHP-FPM** (`eventsapi-phpfpm`) - PHP 8.4 with Composer and PostgreSQL support
- **PostgreSQL** (`eventsapi-postgres`) - Database server (internal network only)
- **Network** - All services communicate via `eventsapi` network
- **Document Root** - `/u/apps/site/public` (maps to `./public/`)

### Database Configuration

The PostgreSQL database is configured with:
- Database: `eventsapi`
- User: `eventsapi`
- Password: `eventsapi_pass`
- Host: `postgres` (container name)
- Port: `5432`

Database migrations are managed using Phinx and configured to read connection details from environment variables.
