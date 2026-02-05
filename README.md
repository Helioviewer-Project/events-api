# events-api
A unified API and event database that collects, normalizes, and serves solar event data from multiple sources (HEK, CCMC, WSA, RHESSI) for use in Helioviewer.org and related tools.

## Features

- **Multi-source Event Collection**: Collects and normalizes solar event data from CCMC DONKI, WSA, and other sources
- **Coordinate Transformation**: High-performance batch coordinate transformation from HGS to HPC with caching
- **RESTful API**: Modern REST API with v1 and v2 endpoints for event querying
- **Real-time Processing**: Event collection with configurable chunk intervals for efficient data processing
- **Redis Caching**: Comprehensive caching layer for HTTP requests and coordinate transformations
- **PostgreSQL Storage**: Robust data storage with Eloquent ORM and database migrations

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
- `make build` - Build the Docker images (with --no-cache)
- `make shell` - Open a bash shell in the PHP container (user 1000:1000)
- `make shell-root` - Open a root bash shell in the PHP container

#### Composer Commands
- `make composer-install` - Install PHP dependencies via Composer
- `make composer-require PACKAGE=package-name` - Add a new package
- `make composer-dump` - Regenerate Composer autoloader

#### Nginx Commands
- `make nginx-reload` - Reload nginx configuration

#### Database Migration Commands
- `make migrate-status` - Check migration status
- `make migrate-create NAME=MigrationName` - Create a new migration
- `make migrate-run` - Run pending migrations
- `make migrate-rollback` - Rollback the last migration
- `make seed-run` - Run database seeders

#### Event Collection & Monitoring Commands
- `make collect` - Collect events for today (daily chunks)
- `make collect 2024-01-01` - Collect events for single day
- `make collect 2024-01-01 2024-01-31` - Collect events for date range (daily chunks)
- `make collect 2024-01-01 2024-01-31 5` - Collect date range in 5-day chunks
- `make recents` - Show the most recent events from the database
- `make recents 10` - Show last 10 events
- `make stats` - Show database statistics grouped by source and path
- `make logs` - Follow application logs (tail -f storage/logs/*.log)
- `make reset` - Reset database (rollback all migrations, migrate, and seed)

## API Endpoints

The API provides several endpoints for accessing solar event data:

### V1 API (Legacy)
- `GET /api/v1/events/{source}/observation/{timestamp}` - Get events active at specific timestamp with coordinate rotation

### V2 API (Enhanced)
- `GET /api/v2/events/recents` - Get last 100 updated events with enhanced data
- `GET /api/v2/events/{uuid}` - Get single event by UUID with full details
- `GET /api/v2/events/{uuid}/source` - Get raw source data for an event
- `GET /api/v2/events/{source}/observation/{timestamp}` - Enhanced observation endpoint

**Supported Sources**: CCMC, HEK, WSA, RHESSI

**Timestamp Formats**: ISO 8601, Y-m-d, Y-m-d H:i:s, Unix timestamps

## Architecture

### Services
- **Nginx** (`eventsapi-nginx`) - Web server listening on port 8082
- **PHP-FPM** (`eventsapi-phpfpm`) - PHP 8.4 application server
- **PostgreSQL** (`eventsapi-postgres`) - Event database with Eloquent ORM
- **Redis** (`eventsapi-redis`) - Caching layer for HTTP and coordinate transformations

### Coordinate Transformation
The system includes dual HTTP-based coordinate transformation services with automatic failover:
- **Primary** - Remote coordinator service (`HV_COORDINATOR_URL`)
- **Backup** - Local Docker coordinator container (`http://coordinator`)

Both coordinators support batch processing and Redis caching for optimal performance.

### Database Configuration

PostgreSQL database configuration:
- Database: `eventsapi`
- User: `eventsapi`
- Password: `eventsapi_pass`
- Host: `postgres` (container name)
- Port: `5432`

Redis configuration:
- Host: `redis` (container name)
- Port: `6379`
- Database: `10`

Database migrations are managed using Phinx with environment variable configuration.
