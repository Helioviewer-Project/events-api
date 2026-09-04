# events-api
A unified API and event database that collects, normalizes, and serves solar event data from multiple sources (HEK, CCMC, RHESSI) for use in Helioviewer.org and related tools.

## Features

- **Multi-source Event Collection**: Collects and normalizes solar event data from CCMC DONKI and other sources
- **Coordinate Transformation**: High-performance batch coordinate transformation from HGS to HPC with caching
- **RESTful API**: Modern REST API with v1 endpoints for event querying
- **Real-time Processing**: Event collection with configurable chunk intervals for efficient data processing
- **Event Distribution Aggregation**: Pre-aggregated event counts by time buckets (30m, hourly, daily, weekly, monthly, yearly) for fast distribution queries
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
- `make collect SOURCES="NAME1,NAME2" 2024-01-01` - Collect only from the named sources
- `make sources` - List every registered source and the path it writes to
- `make recents` - Show the most recent events from the database
- `make recents 10` - Show last 10 events
- `make stats` - Show database statistics grouped by source and path
- `make logs` - Follow application logs (tail -f storage/logs/*.log)
- `make reset` - Reset database (rollback all migrations, migrate, and seed)
- `make distribution-build` - Build distribution aggregations from all events

## API Endpoints

**Base URL:** `https://events.helioviewer.org`

### Supported Sources

| Source | Description |
|--------|-------------|
| CCMC | Community Coordinated Modeling Center (DONKI, FlareScoreboard) |
| HEK | Heliophysics Event Knowledgebase |
| RHESSI | Reuven Ramaty High Energy Solar Spectroscopic Imager |

Source names are case-insensitive in all endpoints.

### Accepted Timestamp Formats

| Format | Example |
|--------|---------|
| Unix timestamp | `1705314645` |
| ISO 8601 with microseconds & timezone | `2024-01-15T10:30:45.123456+00:00` |
| ISO 8601 with milliseconds & Z | `2024-01-15T10:30:45.123Z` |
| ISO 8601 with timezone | `2024-01-15T10:30:45+00:00` |
| ISO 8601 with Z | `2024-01-15T10:30:45Z` |
| ISO 8601 without timezone | `2024-01-15T10:30:45` |
| Space-separated | `2024-01-15 10:30:45` |
| PHP strtotime fallback | `2024-01-15`, `now`, etc. |

---

### Helioviewer.org Integration

These endpoints return data in a format tailored for the Helioviewer.org client.

#### GET `/helioviewer/events/{source}/observation/{timestamp}`

Get events active at a specific observation time, in Helioviewer legacy format. Returns events grouped by event type with nested groups by detection method.

| Parameter | Type | Description |
|-----------|------|-------------|
| `source` | path | Event source (CCMC, HEK, RHESSI) |
| `timestamp` | path | Observation time (any supported timestamp format) |

```python
import requests

response = requests.get(
    "https://events.helioviewer.org/helioviewer/events/HEK/observation/2025-03-15T12:00:00Z"
)
data = response.json()
```

Example response:
```json
[
  {
    "name": "Active Region",
    "pin": "AR",
    "groups": [
      {
        "name": "HMI SHARP",
        "contact": "turmon@jpl.nasa.gov",
        "url": "http://sun.stanford.edu/~mbobra/spaceweather/sharps.htm",
        "data": [
          {
            "id": "019c3d8f-0932-715c-b107-7599a5d9a5a9",
            "path": "HEK>>Active Region>>HMI SHARP",
            "start": "2025-03-15T08:00:00",
            "end": "2025-03-15T12:00:00",
            "hv_hpc_x": -806.97,
            "hv_hpc_y": 440.01,
            "visible": true,
            "label": "HMI SHARP 12923",
            "..."
          },
          "..."
        ]
      }
    ]
  },
  "... (31 event type groups)"
]
```

#### POST `/helioviewer/events/from/{from}/to/{to}`

Get events matching path prefixes within a time range. Returns a flat list with Helioviewer-specific fields (`x`, `x2` as millisecond timestamps, `event_type`, `frm_name`).

| Parameter | Type | Description |
|-----------|------|-------------|
| `from` | path | Start time (Unix timestamp) |
| `to` | path | End time (Unix timestamp) |
| `paths` | body (JSON) | Array of event path prefixes; an entry ending in `>><uuid>` selects that single event by id |

```python
import requests

response = requests.post(
    "https://events.helioviewer.org/helioviewer/events/from/1741996800/to/1742083200",
    json={"paths": ["HEK>>Flare"]}
)
data = response.json()
```

Example response:
```json
{
  "paths": ["HEK>>Flare"],
  "from": 1741996800,
  "to": 1742083200,
  "count": 33,
  "events": [
    {
      "x": 1741999352000,
      "x2": 1741999592000,
      "y": 1,
      "event_starttime": "2025-03-15 00:42:32",
      "event_endtime": "2025-03-15 00:46:32",
      "event_peaktime": "2025-03-15 00:44:20",
      "hv_hpc_x": 345.6,
      "hv_hpc_y": 192,
      "url": "https://events.helioviewer.org/api/v1/events/019c3d8f-07d6-...",
      "event_type": "FL",
      "frm_name": "Flare Detective - Trigger Module",
      "concept": "Flare",
      "hv_labels_formatted": {"Peak Flux": "37.4 DN/sec/pixel"}
    },
    "..."
  ]
}
```

#### POST `/helioviewer/distributions/size/{size}/from/{from}/to/{to}`

Get event count distributions aggregated into time buckets.

| Parameter | Type | Description |
|-----------|------|-------------|
| `size` | path | Bucket size: `30m`, `h`, `D`, `W`, `M`, `Y` |
| `from` | path | Start time (Unix timestamp) |
| `to` | path | End time (Unix timestamp) |
| `paths` | body (JSON) | Array of event path prefixes |

```python
import requests

response = requests.post(
    "https://events.helioviewer.org/helioviewer/distributions/size/D/from/1741996800/to/1742256000",
    json={"paths": ["HEK>>Flare", "CCMC>>DONKI>>CME"]}
)
data = response.json()
```

Example response:
```json
{
  "paths": ["HEK>>Flare", "CCMC>>DONKI>>CME"],
  "size": "D",
  "from": 1741996800,
  "to": 1742256000,
  "event_types": ["CE", "FL"],
  "buckets": [
    {"start": 1741996800, "counts": {"CE": 9, "FL": 33}},
    {"start": 1742083200, "counts": {"CE": 13, "FL": 73}},
    {"start": 1742169600, "counts": {"CE": 16, "FL": 79}},
    {"start": 1742256000, "counts": {"CE": 16, "FL": 49}}
  ]
}
```

#### POST `/helioviewer/events/frames_with_selections`

Get deduplicated events + rotated coordinates for multiple timestamps, filtered by a flexible `selections` array of path prefixes — designed for movie rendering (static event data sent once, per-timestamp rotated coordinates sent separately). A selection matches every event whose path equals the prefix or starts with that prefix.

| Parameter | Type | Description |
|-----------|------|-------------|
| `timestamps` | body (JSON) | Array of datetime strings (max 150 per request) |
| `selections` | body (JSON) | Array of path-prefix strings (max 200) |

```python
import requests

response = requests.post(
    "https://events.helioviewer.org/helioviewer/events/frames_with_selections",
    json={
        "timestamps": ["2025-03-15 11:00:00", "2025-03-15 12:00:00"],
        "selections": ["HEK>>Flare", "CCMC>>DONKI>>CME"]
    }
)
data = response.json()
```

Example response:
```json
{
  "events": {
    "019c3d8f-...": {
      "path": "HEK>>Flare>>SSW Latest Events",
      "label": "...", "start": "2025-03-15T11:50:00", "end": "2025-03-15T12:10:00",
      "hv_hpc_x": -123.4, "hv_hpc_y": 567.8, "footprint": [[...]],
      "type": "FL", "pin": "FL"
    }
  },
  "timestamps": {
    "2025-03-15 11:00:00": {"019c3d8f-...": {"dx": 4.4, "dy": 2.4, "visible": true}},
    "2025-03-15 12:00:00": {"019c3d8f-...": {"dx": 9.7, "dy": 5.1, "visible": true}}
  }
}
```

`timestamps[ts]` is `{}` (object) if no event in the selection is active at that moment.

For movies > 150 frames, split timestamps into chunks and merge `timestamps` across responses.

`hv_hpc_x/y` and `footprint` in `events` are the arcsec base at the event's own coordinate time; each timestamp carries `{"dx", "dy"}`, that frame's arcsec offset from the base. Render pin = `center + (dx, dy)`, and shift every polygon point by the same delta. `footprint` is a **list of polygons** (`[[{x,y},…],…]` — multi-contour events like WSA coronal-hole maps carry several).

`visible` is per frame: whether the event's center faces the observer at that timestamp, so a movie can fade a region as it rotates over the limb without re-fetching its footprint. Far-side vertices in the `events` footprint base carry their own `"visible": false`.

---

### Event Data (API v1)

#### GET `/api/v1/events/recents`

Get the last 100 updated events with enhanced data.

```python
import requests

response = requests.get(
    "https://events.helioviewer.org/api/v1/events/recents"
)
events = response.json()
```

Example response:
```json
[
  {
    "url": "https://events.helioviewer.org/api/v1/events/019d0c00-1985-...",
    "path": "CCMC>>Solar Flare Predictions>>ASSA",
    "start": "2026-03-20 16:00:00",
    "end": "2026-03-21 04:00:00",
    "hv_hpc_x": -1,
    "hv_hpc_y": -1,
    "label": "ASSA \nC: 25%\nM: 4%\nX: 0%",
    "coordinate_system": "stonyhurst",
    "regions": [{"organization": "MODEL", "external_id": "4", "..."}],
    "source_url": "https://events.helioviewer.org/api/v1/events/019d0c00-.../source",
    "views": [{"name": "Flare Prediction", "content": {"C": 0.25, "M": 0.04, "..."}}],
    "link": {"url": "...", "text": "Helioviewer Events API JSON"}
  },
  "... (100 events)"
]
```

#### GET `/api/v1/events/{source}/observation/{timestamp}`

Get events from a specific source active at a given observation time.

| Parameter | Type | Description |
|-----------|------|-------------|
| `source` | path | Event source (CCMC, HEK, RHESSI) |
| `timestamp` | path | Observation time (any supported timestamp format) |

```python
import requests

response = requests.get(
    "https://events.helioviewer.org/api/v1/events/HEK/observation/2025-03-15T12:00:00Z"
)
events = response.json()
```

Example response:
```json
[
  {
    "url": "https://events.helioviewer.org/api/v1/events/019c3d8f-0932-...",
    "path": "HEK>>Active Region>>HMI SHARP",
    "start": "2025-03-15 08:00:00",
    "end": "2025-03-15 12:00:00",
    "hv_hpc_x": -806.97,
    "hv_hpc_y": 440.01,
    "visible": true,
    "label": "HMI SHARP 12923",
    "coordinate_system": "helioprojective",
    "regions": [{"organization": "NOAA", "external_id": "14033", "..."}],
    "..."
  },
  "... (49 events)"
]
```

`visible` says whether the event's center faces the observer at the requested time — an event that has rotated behind the limb is still returned, with `visible: false`, and the client decides how to draw it. Far-side footprint vertices carry their own `"visible": false` next to `x`/`y`; front-side vertices have no such key. Both fields are additive, and a missing key always means visible.

#### GET `/api/v1/events/{uuid}`

Get a single event by its UUID with full details.

| Parameter | Type | Description |
|-----------|------|-------------|
| `uuid` | path | Event UUID |

```python
import requests

uuid = "019d0c00-1985-7131-84eb-24bc34a750ad"
response = requests.get(
    f"https://events.helioviewer.org/api/v1/events/{uuid}"
)
event = response.json()
```

Example response:
```json
{
  "url": "https://events.helioviewer.org/api/v1/events/019d0c00-1985-...",
  "path": "CCMC>>Solar Flare Predictions>>ASSA",
  "start": "2026-03-20 16:00:00",
  "peak": "2026-03-21 04:00:00",
  "end": "2026-03-21 04:00:00",
  "coordinate_time": "2026-03-20 17:24:36",
  "hv_hpc_x": -1,
  "hv_hpc_y": -1,
  "label": "ASSA \nC: 25%\nM: 4%\nX: 0%",
  "coordinate_system": "stonyhurst",
  "regions": [{"organization": "MODEL", "external_id": "4", "..."}],
  "source_url": "https://events.helioviewer.org/api/v1/events/019d0c00-.../source",
  "views": [{"name": "Flare Prediction", "content": {"C": 0.25, "M": 0.04, "X": 0, "..."}}],
  "link": {"url": "...", "text": "Helioviewer Events API JSON"}
}
```

#### GET `/api/v1/events/{uuid}/source`

Get the raw source data for an event (original data from the provider before normalization).

| Parameter | Type | Description |
|-----------|------|-------------|
| `uuid` | path | Event UUID |

```python
import requests

uuid = "019d0c00-1985-7131-84eb-24bc34a750ad"
response = requests.get(
    f"https://events.helioviewer.org/api/v1/events/{uuid}/source"
)
source_data = response.json()
```

Example response (CCMC FlareScoreboard source):
```json
{
  "start_window": "2026-03-20T16:00:00.0Z",
  "end_window": "2026-03-21T04:00:00.0Z",
  "issue_time": "2026-03-20T16:00:00.0Z",
  "C": 0.25,
  "M": 0.04,
  "X": 0,
  "NOAALocationTime": "-1",
  "NOAALatitude": "-1",
  "NOAALongitude": "-1",
  "ModelRegionId": 4,
  "ModelLocationTime": "2026-03-20T16:00:00.0Z",
  "ModelLatitude": -69,
  "ModelLongitude": 25
}
```

---

### Active Regions (API v1)

#### GET `/api/v1/regions`

Get all active regions across all organizations.

```python
import requests

response = requests.get(
    "https://events.helioviewer.org/api/v1/regions"
)
data = response.json()
regions = data["regions"]
```

Example response:
```json
{
  "regions": [
    {
      "id": 1032,
      "organization": "CATANIA",
      "external_id": "1",
      "event_count": 147,
      "first_seen": "2025-09-24 23:38:31",
      "last_updated": "2025-09-24 23:38:31",
      "latest_event_start": "2026-03-19 12:30:00"
    },
    "... (11310 total regions)"
  ]
}
```

#### GET `/api/v1/regions/{organization}/{external_id}`

Get events associated with a specific active region.

| Parameter | Type | Description |
|-----------|------|-------------|
| `organization` | path | Region organization (e.g., NOAA, CATANIA, HARP) |
| `external_id` | path | Region identifier (e.g., 14188) |

```python
import requests

response = requests.get(
    "https://events.helioviewer.org/api/v1/regions/NOAA/14188"
)
data = response.json()
region = data["region"]
events = data["events"]
```

Example response:
```json
{
  "region": {
    "organization": "NOAA",
    "external_id": "14188",
    "event_count": 100
  },
  "events": [
    {
      "url": "https://events.helioviewer.org/api/v1/events/019981d6-c0db-...",
      "path": "CCMC>>Solar Flare Predictions>>DAFFS",
      "start": "2025-08-31 23:54:00",
      "end": "2025-09-01 23:54:00",
      "hv_hpc_x": -10.97,
      "hv_hpc_y": 60.73,
      "label": "DAFFS \nC+: 0.3%\nM+: 0.03%\nX: 0.06%",
      "coordinate_system": "stonyhurst",
      "..."
    },
    "... (100 events total)"
  ]
}
```

---

### HTML Pages

| Path | Description |
|------|-------------|
| `GET /` | Home page with API documentation |
| `GET /stats` | Statistics dashboard |
| `GET /active-regions` | Active regions search tool |

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
