<?php

namespace Helioviewer\EventsApi\Api\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class PageController extends Controller
{
    /**
     * Show stats HTML page
     */
    public function statsPage(Request $request, Response $response): Response
    {
        // Read and serve the HTML file
        $htmlPath = __DIR__ . '/../../../public/stats.html';

        if (!file_exists($htmlPath)) {
            return $this->error($response, 'Stats page not found', 404);
        }

        $html = file_get_contents($htmlPath);
        $response->getBody()->write($html);

        return $response->withHeader('Content-Type', 'text/html');
    }

    /**
     * Show implementation plan page
     */
    public function planPage(Request $request, Response $response): Response
    {
        $htmlPath = __DIR__ . '/../../../public/plan.html';

        if (!file_exists($htmlPath)) {
            return $this->error($response, 'Plan page not found', 404);
        }

        $html = file_get_contents($htmlPath);
        $response->getBody()->write($html);

        return $response->withHeader('Content-Type', 'text/html');
    }

    /**
     * Show home page
     */
    public function home(Request $request, Response $response): Response
    {
        $html = <<<'HTML'
<!DOCTYPE html>
<html>
<head>
    <title>Helioviewer Events API</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: linear-gradient(135deg, #2c3e50 0%, #34495e 100%);
            min-height: 100vh;
            padding: 20px;
        }
        .main-container {
            max-width: 800px;
            margin: 0 auto;
        }
        header {
            text-align: center;
            color: white;
            margin-bottom: 30px;
            padding: 20px;
        }
        .header-content {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 20px;
            margin-bottom: 10px;
        }
        .logo {
            width: 60px;
            height: 60px;
        }
        header h1 {
            font-size: 2.5rem;
            margin: 0;
            text-shadow: 2px 2px 4px rgba(0,0,0,0.2);
            color: white;
        }
        .subtitle {
            font-size: 1.1rem;
            opacity: 0.9;
            color: white;
        }
        .nav-buttons {
            display: flex;
            justify-content: center;
            gap: 15px;
            margin-top: 20px;
        }
        .nav-button {
            display: inline-block;
            background: rgba(255, 255, 255, 0.2);
            color: white;
            padding: 10px 20px;
            border-radius: 6px;
            text-decoration: none;
            transition: background 0.3s;
        }
        .nav-button:hover {
            background: rgba(255, 255, 255, 0.3);
            text-decoration: none;
        }
        .nav-button.active {
            background: rgba(255, 255, 255, 0.35);
            font-weight: bold;
        }
        .container {
            background: white;
            border-radius: 12px;
            padding: 40px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.1);
        }
        .version {
            color: #666;
            font-size: 14px;
            margin-bottom: 30px;
        }
        h2 {
            color: #d35400;
            margin-top: 30px;
            border-bottom: 2px solid #f0f0f0;
            padding-bottom: 10px;
        }
        h3 {
            color: #333;
            margin-top: 20px;
            margin-bottom: 10px;
        }
        a {
            color: #d35400;
            text-decoration: none;
        }
        a:hover {
            text-decoration: underline;
        }
        /* Details / Summary collapsible sections */
        details {
            background: #f8f9fa;
            border-radius: 8px;
            margin: 8px 0;
            border: 1px solid #e9ecef;
        }
        details[open] {
            border-color: #d35400;
        }
        summary {
            padding: 12px 16px;
            cursor: pointer;
            font-family: 'Courier New', monospace;
            font-size: 14px;
            font-weight: 600;
            color: #333;
            list-style: none;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        summary::-webkit-details-marker {
            display: none;
        }
        summary::before {
            content: "\25b6";
            font-size: 10px;
            color: #d35400;
            transition: transform 0.2s;
            flex-shrink: 0;
        }
        details[open] > summary::before {
            transform: rotate(90deg);
        }
        summary:hover {
            background: #e9ecef;
            border-radius: 8px;
        }
        .method-badge {
            display: inline-block;
            padding: 2px 8px;
            border-radius: 4px;
            font-size: 12px;
            font-weight: 700;
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            color: white;
            flex-shrink: 0;
        }
        .method-get {
            background: #888;
        }
        .method-post {
            background: #666;
        }
        .endpoint-path {
            flex-grow: 1;
        }
        .endpoint-desc {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            font-weight: 400;
            color: #666;
            font-size: 13px;
        }
        .endpoint-detail {
            padding: 16px;
            border-top: 1px solid #e9ecef;
        }
        .endpoint-detail p {
            margin: 8px 0;
            color: #555;
            font-size: 14px;
            line-height: 1.6;
        }
        .param-table {
            width: 100%;
            border-collapse: collapse;
            margin: 10px 0;
            font-size: 13px;
        }
        .param-table th {
            background: #e9ecef;
            padding: 8px 12px;
            text-align: left;
            font-weight: 600;
            color: #333;
        }
        .param-table td {
            padding: 8px 12px;
            border-bottom: 1px solid #e9ecef;
        }
        .param-table code {
            background: #fff3e0;
            padding: 1px 5px;
            border-radius: 3px;
            font-size: 12px;
        }
        /* Code block styling */
        pre {
            background: #1e1e1e;
            color: #d4d4d4;
            padding: 16px;
            border-radius: 6px;
            overflow-x: auto;
            margin: 10px 0;
            font-size: 13px;
            line-height: 1.5;
        }
        pre code {
            background: none;
            padding: 0;
            color: inherit;
            font-size: inherit;
        }
        /* Syntax highlighting classes */
        .kw { color: #c586c0; }
        .fn { color: #dcdcaa; }
        .str { color: #ce9178; }
        .cmt { color: #6a9955; }
        /* Timestamp format table */
        .info-table {
            width: 100%;
            border-collapse: collapse;
            margin: 10px 0;
            font-size: 13px;
        }
        .info-table th {
            background: #e9ecef;
            padding: 8px 12px;
            text-align: left;
        }
        .info-table td {
            padding: 8px 12px;
            border-bottom: 1px solid #e9ecef;
        }
        .info-table code {
            background: #fff3e0;
            padding: 1px 5px;
            border-radius: 3px;
            font-size: 12px;
        }
    </style>
</head>
<body>
    <div class="main-container">
        <header>
            <div class="header-content">
                <img src="https://helioviewer-project.github.io/event-tree/helioviewer-logo.png" alt="Helioviewer Logo" class="logo">
                <h1>Helioviewer Events API</h1>
            </div>
            <div class="subtitle">Solar Event Data Platform</div>
            <div class="nav-buttons">
                <a href="/" class="nav-button active">Home</a>
                <a href="/stats" class="nav-button">Statistics Dashboard</a>
                <a href="/active-regions" class="nav-button">Active Regions</a>
            </div>
        </header>

        <div class="container">
            <p class="version">Version 2.0</p>

            <p>Welcome to the Helioviewer Events API. This API provides access to solar event data from multiple sources.</p>

            <details style="background: white; border: 1px solid #e9ecef; margin-top: 20px;">
                <summary style="font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; font-weight: 600; color: #333;">Supported Sources &amp; Timestamp Formats</summary>
                <div class="endpoint-detail">
                    <h3>Supported Sources</h3>
                    <table class="info-table">
                        <tr><th>Source</th><th>Description</th></tr>
                        <tr><td><code>CCMC</code></td><td>Community Coordinated Modeling Center (DONKI, FlareScoreboard)</td></tr>
                        <tr><td><code>HEK</code></td><td>Heliophysics Event Knowledgebase</td></tr>
                        <tr><td><code>RHESSI</code></td><td>Reuven Ramaty High Energy Solar Spectroscopic Imager</td></tr>
                    </table>
                    <p>Source names are case-insensitive in all endpoints.</p>

                    <h3>Accepted Timestamp Formats</h3>
                    <table class="info-table">
                        <tr><th>Format</th><th>Example</th></tr>
                        <tr><td>Unix timestamp</td><td><code>1705314645</code></td></tr>
                        <tr><td>ISO 8601 with microseconds &amp; timezone</td><td><code>2024-01-15T10:30:45.123456+00:00</code></td></tr>
                        <tr><td>ISO 8601 with milliseconds &amp; Z</td><td><code>2024-01-15T10:30:45.123Z</code></td></tr>
                        <tr><td>ISO 8601 with timezone</td><td><code>2024-01-15T10:30:45+00:00</code></td></tr>
                        <tr><td>ISO 8601 with Z</td><td><code>2024-01-15T10:30:45Z</code></td></tr>
                        <tr><td>ISO 8601 without timezone</td><td><code>2024-01-15T10:30:45</code></td></tr>
                        <tr><td>Space-separated</td><td><code>2024-01-15 10:30:45</code></td></tr>
                        <tr><td>PHP strtotime fallback</td><td><code>2024-01-15</code>, <code>now</code>, etc.</td></tr>
                    </table>
                </div>
            </details>

            <h2>Helioviewer.org Integration</h2>

            <details>
                <summary>
                    <span class="method-badge method-get">GET</span>
                    <span class="endpoint-path">/helioviewer/events/{source}/observation/{timestamp}</span>
                    <span class="endpoint-desc">Legacy observation format</span>
                </summary>
                <div class="endpoint-detail">
                    <p>Get events active at a specific observation time, grouped by event type with nested detection method groups.</p>
                    <table class="param-table">
                        <tr><th>Parameter</th><th>Type</th><th>Description</th></tr>
                        <tr><td><code>source</code></td><td>path</td><td>Event source (CCMC, HEK, RHESSI)</td></tr>
                        <tr><td><code>timestamp</code></td><td>path</td><td>Observation time (any supported format)</td></tr>
                    </table>
                    <pre><code><span class="kw">import</span> requests

response = requests.<span class="fn">get</span>(
    <span class="str">"https://events.helioviewer.org/helioviewer/events/HEK/observation/2025-03-15T12:00:00Z"</span>
)
data = response.<span class="fn">json</span>()</code></pre>
                    <p><strong>Example response:</strong></p>
                    <pre><code>[
  {
    "name": "Active Region",
    "pin": "AR",
    "groups": [{
      "name": "HMI SHARP",
      "contact": "turmon@jpl.nasa.gov",
      "data": [{
        "id": "019c3d8f-0932-...",
        "path": "HEK&gt;&gt;Active Region&gt;&gt;HMI SHARP",
        "start": "2025-03-15T08:00:00",
        "end": "2025-03-15T12:00:00",
        "hv_hpc_x": -806.97,
        "hv_hpc_y": 440.01,
        "label": "HMI SHARP 12923",
        ...
      }, ...]
    }]
  },
  ... <span class="cmt">// 31 event type groups</span>
]</code></pre>
                </div>
            </details>

            <details>
                <summary>
                    <span class="method-badge method-post">POST</span>
                    <span class="endpoint-path">/helioviewer/events/from/{from}/to/{to}</span>
                    <span class="endpoint-desc">Events by path prefixes</span>
                </summary>
                <div class="endpoint-detail">
                    <p>Get events matching path prefixes within a time range. Returns flat list with Helioviewer-specific fields.</p>
                    <table class="param-table">
                        <tr><th>Parameter</th><th>Type</th><th>Description</th></tr>
                        <tr><td><code>from</code></td><td>path</td><td>Start time (Unix timestamp)</td></tr>
                        <tr><td><code>to</code></td><td>path</td><td>End time (Unix timestamp)</td></tr>
                        <tr><td><code>paths</code></td><td>body (JSON)</td><td>Array of event path prefixes</td></tr>
                    </table>
                    <pre><code><span class="kw">import</span> requests

response = requests.<span class="fn">post</span>(
    <span class="str">"https://events.helioviewer.org/helioviewer/events/from/1741996800/to/1742083200"</span>,
    json={<span class="str">"paths"</span>: [<span class="str">"HEK&gt;&gt;Flare"</span>]}
)
data = response.<span class="fn">json</span>()</code></pre>
                    <p><strong>Example response:</strong></p>
                    <pre><code>{
  "paths": ["HEK&gt;&gt;Flare"],
  "from": 1741996800,
  "to": 1742083200,
  "count": 33,
  "events": [{
    "x": 1741999352000,
    "x2": 1741999592000,
    "y": 1,
    "event_starttime": "2025-03-15 00:42:32",
    "event_endtime": "2025-03-15 00:46:32",
    "event_peaktime": "2025-03-15 00:44:20",
    "hv_hpc_x": 345.6,
    "hv_hpc_y": 192,
    "event_type": "FL",
    "frm_name": "Flare Detective - Trigger Module",
    "concept": "Flare",
    "hv_labels_formatted": {"Peak Flux": "37.4 DN/sec/pixel"},
    ...
  }, ...]
}</code></pre>
                </div>
            </details>

            <details>
                <summary>
                    <span class="method-badge method-post">POST</span>
                    <span class="endpoint-path">/helioviewer/distributions/size/{size}/from/{from}/to/{to}</span>
                    <span class="endpoint-desc">Event count distributions</span>
                </summary>
                <div class="endpoint-detail">
                    <p>Get event count distributions aggregated into time buckets.</p>
                    <table class="param-table">
                        <tr><th>Parameter</th><th>Type</th><th>Description</th></tr>
                        <tr><td><code>size</code></td><td>path</td><td>Bucket size: <code>30m</code>, <code>h</code>, <code>D</code>, <code>W</code>, <code>M</code>, <code>Y</code></td></tr>
                        <tr><td><code>from</code></td><td>path</td><td>Start time (Unix timestamp)</td></tr>
                        <tr><td><code>to</code></td><td>path</td><td>End time (Unix timestamp)</td></tr>
                        <tr><td><code>paths</code></td><td>body (JSON)</td><td>Array of event path prefixes</td></tr>
                    </table>
                    <pre><code><span class="kw">import</span> requests

response = requests.<span class="fn">post</span>(
    <span class="str">"https://events.helioviewer.org/helioviewer/distributions/size/D/from/1741996800/to/1742256000"</span>,
    json={<span class="str">"paths"</span>: [<span class="str">"HEK&gt;&gt;Flare"</span>, <span class="str">"CCMC&gt;&gt;DONKI&gt;&gt;CME"</span>]}
)
data = response.<span class="fn">json</span>()</code></pre>
                    <p><strong>Example response:</strong></p>
                    <pre><code>{
  "paths": ["HEK&gt;&gt;Flare", "CCMC&gt;&gt;DONKI&gt;&gt;CME"],
  "size": "D",
  "from": 1741996800,
  "to": 1742256000,
  "event_types": ["C3", "FL"],
  "buckets": [
    {"start": 1741996800, "counts": {"C3": 9, "FL": 33}},
    {"start": 1742083200, "counts": {"C3": 13, "FL": 73}},
    {"start": 1742169600, "counts": {"C3": 16, "FL": 79}},
    {"start": 1742256000, "counts": {"C3": 16, "FL": 49}}
  ]
}</code></pre>
                </div>
            </details>

            <h2>Event Data</h2>

            <details>
                <summary>
                    <span class="method-badge method-get">GET</span>
                    <span class="endpoint-path">/api/v1/events/recents</span>
                    <span class="endpoint-desc">Recent events</span>
                </summary>
                <div class="endpoint-detail">
                    <p>Get the last 100 updated events with enhanced data.</p>
                    <pre><code><span class="kw">import</span> requests

response = requests.<span class="fn">get</span>(
    <span class="str">"https://events.helioviewer.org/api/v1/events/recents"</span>
)
events = response.<span class="fn">json</span>()</code></pre>
                    <p><strong>Example response:</strong></p>
                    <pre><code>[{
  "url": "https://events.helioviewer.org/api/v1/events/019d0c00-1985-...",
  "path": "CCMC&gt;&gt;Solar Flare Predictions&gt;&gt;ASSA",
  "start": "2026-03-20 16:00:00",
  "end": "2026-03-21 04:00:00",
  "hv_hpc_x": -1,
  "hv_hpc_y": -1,
  "label": "ASSA \nC: 25%\nM: 4%\nX: 0%",
  "coordinate_system": "stonyhurst",
  "regions": [{"organization": "MODEL", "external_id": "4", ...}],
  "source_url": "https://events.helioviewer.org/api/v1/events/019d0c00-.../source",
  "views": [{"name": "Flare Prediction", "content": {"C": 0.25, "M": 0.04, ...}}],
  "link": {"url": "...", "text": "Helioviewer Events API JSON"}
}, ... <span class="cmt">// 100 events</span>]</code></pre>
                </div>
            </details>

            <details>
                <summary>
                    <span class="method-badge method-get">GET</span>
                    <span class="endpoint-path">/api/v1/events/{source}/observation/{timestamp}</span>
                    <span class="endpoint-desc">Events by observation time</span>
                </summary>
                <div class="endpoint-detail">
                    <p>Get events from a specific source active at a given observation time.</p>
                    <table class="param-table">
                        <tr><th>Parameter</th><th>Type</th><th>Description</th></tr>
                        <tr><td><code>source</code></td><td>path</td><td>Event source (CCMC, HEK, RHESSI)</td></tr>
                        <tr><td><code>timestamp</code></td><td>path</td><td>Observation time (any supported format)</td></tr>
                    </table>
                    <pre><code><span class="kw">import</span> requests

response = requests.<span class="fn">get</span>(
    <span class="str">"https://events.helioviewer.org/api/v1/events/HEK/observation/2025-03-15T12:00:00Z"</span>
)
events = response.<span class="fn">json</span>()</code></pre>
                    <p><strong>Example response:</strong></p>
                    <pre><code>[{
  "url": "https://events.helioviewer.org/api/v1/events/019c3d8f-0932-...",
  "path": "HEK&gt;&gt;Active Region&gt;&gt;HMI SHARP",
  "start": "2025-03-15 08:00:00",
  "end": "2025-03-15 12:00:00",
  "hv_hpc_x": -806.97,
  "hv_hpc_y": 440.01,
  "label": "HMI SHARP 12923",
  "coordinate_system": "helioprojective",
  "regions": [{"organization": "NOAA", "external_id": "14033", ...}],
  ...
}, ... <span class="cmt">// 49 events</span>]</code></pre>
                </div>
            </details>

            <details>
                <summary>
                    <span class="method-badge method-get">GET</span>
                    <span class="endpoint-path">/api/v1/events/{uuid}</span>
                    <span class="endpoint-desc">Single event by UUID</span>
                </summary>
                <div class="endpoint-detail">
                    <p>Get a single event by its UUID with full details.</p>
                    <table class="param-table">
                        <tr><th>Parameter</th><th>Type</th><th>Description</th></tr>
                        <tr><td><code>uuid</code></td><td>path</td><td>Event UUID</td></tr>
                    </table>
                    <pre><code><span class="kw">import</span> requests

uuid = <span class="str">"019d0c00-1985-7131-84eb-24bc34a750ad"</span>
response = requests.<span class="fn">get</span>(
    <span class="str">f"https://events.helioviewer.org/api/v1/events/</span>{uuid}<span class="str">"</span>
)
event = response.<span class="fn">json</span>()</code></pre>
                    <p><strong>Example response:</strong></p>
                    <pre><code>{
  "url": "https://events.helioviewer.org/api/v1/events/019d0c00-1985-...",
  "path": "CCMC&gt;&gt;Solar Flare Predictions&gt;&gt;ASSA",
  "start": "2026-03-20 16:00:00",
  "peak": "2026-03-21 04:00:00",
  "end": "2026-03-21 04:00:00",
  "coordinate_time": "2026-03-20 17:24:36",
  "hv_hpc_x": -1,
  "hv_hpc_y": -1,
  "label": "ASSA \nC: 25%\nM: 4%\nX: 0%",
  "coordinate_system": "stonyhurst",
  "regions": [{"organization": "MODEL", "external_id": "4", ...}],
  "source_url": ".../source",
  "views": [{"name": "Flare Prediction", "content": {"C": 0.25, "M": 0.04, "X": 0, ...}}],
  "link": {"url": "...", "text": "Helioviewer Events API JSON"}
}</code></pre>
                </div>
            </details>

            <details>
                <summary>
                    <span class="method-badge method-get">GET</span>
                    <span class="endpoint-path">/api/v1/events/{uuid}/source</span>
                    <span class="endpoint-desc">Raw source data</span>
                </summary>
                <div class="endpoint-detail">
                    <p>Get the raw source data for an event (original data from the provider before normalization).</p>
                    <table class="param-table">
                        <tr><th>Parameter</th><th>Type</th><th>Description</th></tr>
                        <tr><td><code>uuid</code></td><td>path</td><td>Event UUID</td></tr>
                    </table>
                    <pre><code><span class="kw">import</span> requests

uuid = <span class="str">"019d0c00-1985-7131-84eb-24bc34a750ad"</span>
response = requests.<span class="fn">get</span>(
    <span class="str">f"https://events.helioviewer.org/api/v1/events/</span>{uuid}<span class="str">/source"</span>
)
source_data = response.<span class="fn">json</span>()</code></pre>
                    <p><strong>Example response</strong> (CCMC FlareScoreboard):</p>
                    <pre><code>{
  "start_window": "2026-03-20T16:00:00.0Z",
  "end_window": "2026-03-21T04:00:00.0Z",
  "issue_time": "2026-03-20T16:00:00.0Z",
  "C": 0.25,
  "M": 0.04,
  "X": 0,
  "NOAALocationTime": "-1",
  "ModelRegionId": 4,
  "ModelLatitude": -69,
  "ModelLongitude": 25
}</code></pre>
                </div>
            </details>

            <h2>Active Regions</h2>

            <details>
                <summary>
                    <span class="method-badge method-get">GET</span>
                    <span class="endpoint-path">/api/v1/regions</span>
                    <span class="endpoint-desc">All regions</span>
                </summary>
                <div class="endpoint-detail">
                    <p>Get all active regions across all organizations.</p>
                    <pre><code><span class="kw">import</span> requests

response = requests.<span class="fn">get</span>(
    <span class="str">"https://events.helioviewer.org/api/v1/regions"</span>
)
data = response.<span class="fn">json</span>()
regions = data[<span class="str">"regions"</span>]</code></pre>
                    <p><strong>Example response:</strong></p>
                    <pre><code>{
  "regions": [{
    "id": 1032,
    "organization": "CATANIA",
    "external_id": "1",
    "event_count": 147,
    "first_seen": "2025-09-24 23:38:31",
    "last_updated": "2025-09-24 23:38:31",
    "latest_event_start": "2026-03-19 12:30:00"
  }, ... <span class="cmt">// 11310 total regions</span>]
}</code></pre>
                </div>
            </details>

            <details>
                <summary>
                    <span class="method-badge method-get">GET</span>
                    <span class="endpoint-path">/api/v1/regions/{organization}/{external_id}</span>
                    <span class="endpoint-desc">Events for a region</span>
                </summary>
                <div class="endpoint-detail">
                    <p>Get events associated with a specific active region.</p>
                    <table class="param-table">
                        <tr><th>Parameter</th><th>Type</th><th>Description</th></tr>
                        <tr><td><code>organization</code></td><td>path</td><td>Region organization (e.g., NOAA, CATANIA, HARP)</td></tr>
                        <tr><td><code>external_id</code></td><td>path</td><td>Region identifier (e.g., 14188)</td></tr>
                    </table>
                    <pre><code><span class="kw">import</span> requests

response = requests.<span class="fn">get</span>(
    <span class="str">"https://events.helioviewer.org/api/v1/regions/NOAA/14188"</span>
)
data = response.<span class="fn">json</span>()
region = data[<span class="str">"region"</span>]
events = data[<span class="str">"events"</span>]</code></pre>
                    <p><strong>Example response:</strong></p>
                    <pre><code>{
  "region": {
    "organization": "NOAA",
    "external_id": "14188",
    "event_count": 100
  },
  "events": [{
    "url": "https://events.helioviewer.org/api/v1/events/019981d6-c0db-...",
    "path": "CCMC&gt;&gt;Solar Flare Predictions&gt;&gt;DAFFS",
    "start": "2025-08-31 23:54:00",
    "end": "2025-09-01 23:54:00",
    "hv_hpc_x": -10.97,
    "hv_hpc_y": 60.73,
    "label": "DAFFS \nC+: 0.3%\nM+: 0.03%\nX: 0.06%",
    "coordinate_system": "stonyhurst",
    ...
  }, ... <span class="cmt">// 100 events total</span>]
}</code></pre>
                </div>
            </details>

            <h3 style="margin-top: 20px;">Interactive Tools</h3>
            <table class="info-table">
                <tr><th>Path</th><th>Description</th></tr>
                <tr><td><a href="/stats"><code>/stats</code></a></td><td>Statistics dashboard</td></tr>
                <tr><td><a href="/active-regions"><code>/active-regions</code></a></td><td>Active regions search tool</td></tr>
            </table>

            <h2>Documentation</h2>
            <p>For more information, visit the <a href="https://helioviewer.org" target="_blank">Helioviewer website</a>.</p>
        </div>
    </div>
</body>
</html>
HTML;

        $response->getBody()->write($html);
        return $response->withHeader('Content-Type', 'text/html');
    }

    /**
     * Show predictions search page
     */
    public function predictionsPage(Request $request, Response $response): Response
    {
        $html = <<<HTML
<!DOCTYPE html>
<html>
<head>
    <title>Active Regions Search - Helioviewer Events API</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: linear-gradient(135deg, #2c3e50 0%, #34495e 100%);
            min-height: 100vh;
            padding: 20px;
        }
        .main-container {
            max-width: 1200px;
            margin: 0 auto;
        }
        header {
            text-align: center;
            color: white;
            margin-bottom: 30px;
            padding: 20px;
        }
        .header-content {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 20px;
            margin-bottom: 10px;
        }
        .logo {
            width: 60px;
            height: 60px;
        }
        header h1 {
            font-size: 2.5rem;
            margin: 0;
            text-shadow: 2px 2px 4px rgba(0,0,0,0.2);
            color: white;
        }
        .subtitle {
            font-size: 1.1rem;
            opacity: 0.9;
            color: white;
        }
        .nav-buttons {
            display: flex;
            justify-content: center;
            gap: 15px;
            margin-top: 20px;
        }
        .nav-button {
            display: inline-block;
            background: rgba(255, 255, 255, 0.2);
            color: white;
            padding: 10px 20px;
            border-radius: 6px;
            text-decoration: none;
            transition: background 0.3s;
        }
        .nav-button:hover {
            background: rgba(255, 255, 255, 0.3);
            text-decoration: none;
            color: white;
        }
        .nav-button.active {
            background: rgba(255, 255, 255, 0.35);
            font-weight: bold;
        }
        .container {
            background: white;
            border-radius: 12px;
            padding: 40px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.1);
        }
        .page-title {
            color: #333;
            margin-bottom: 30px;
        }
        .search-form {
            background: #f8f9fa;
            border-radius: 8px;
            margin-bottom: 30px;
        }
        .form-group {
            margin-bottom: 20px;
        }
        label {
            display: block;
            margin-bottom: 8px;
            font-weight: 500;
            color: #333;
        }
        input[type="text"] {
            width: 100%;
            padding: 10px;
            border: 1px solid #ddd;
            border-radius: 4px;
            font-size: 14px;
        }
        .radio-group {
            display: flex;
            gap: 20px;
            margin-top: 8px;
        }
        .radio-item {
            display: flex;
            align-items: center;
            background: white;
            padding: 8px 15px;
            border: 2px solid #e0e0e0;
            border-radius: 6px;
            cursor: pointer;
            transition: all 0.2s;
        }
        .radio-item:hover {
            border-color: #e67e22;
            background: #fdebd0;
        }
        .radio-item input[type="radio"] {
            margin-right: 8px;
            cursor: pointer;
        }
        .radio-item.selected {
            border-color: #e67e22;
            background: #fad7a0;
        }
        .radio-item label {
            margin: 0;
            cursor: pointer;
            font-weight: normal;
        }
        button {
            background: #e67e22;
            color: white;
            padding: 12px 24px;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            font-size: 14px;
        }
        button:hover {
            background: #c0392b;
        }
        .results {
            margin-top: 30px;
        }
        .result-item {
            background: #f8f9fa;
            padding: 15px;
            margin-bottom: 10px;
            border-radius: 6px;
            border-left: 4px solid #e67e22;
        }
        .result-header {
            font-weight: bold;
            color: #333;
            margin-bottom: 5px;
        }
        .result-meta {
            color: #666;
            font-size: 12px;
        }
        .loading {
            text-align: center;
            color: #666;
            padding: 20px;
        }
        .error {
            background: #fee;
            color: #c33;
            padding: 15px;
            border-radius: 6px;
            border-left: 4px solid #c33;
        }
        .section-title {
            font-size: 1.5rem;
            margin: 30px 0 20px 0;
            color: #333;
            border-bottom: 2px solid #e67e22;
            padding-bottom: 10px;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 30px;
        }
        th {
            background: #e9ecef;
            padding: 12px;
            text-align: left;
            font-weight: 600;
            color: #333;
            border-bottom: 2px solid #dee2e6;
        }
        td {
            padding: 10px 12px;
            border-bottom: 1px solid #dee2e6;
        }
        tr:hover {
            background: #fff;
        }
        .number {
            font-weight: 600;
            color: #e67e22;
        }
        .api-link {
            color: #3498db;
            text-decoration: none;
            font-size: 12px;
        }
        .api-link:hover {
            text-decoration: underline;
        }
        .spinner {
            border: 3px solid #f3f3f3;
            border-top: 3px solid #e67e22;
            border-radius: 50%;
            width: 30px;
            height: 30px;
            animation: spin 1s linear infinite;
            margin: 20px auto;
        }
        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
    </style>
</head>
<body>
    <div class="main-container">
        <header>
            <div class="header-content">
                <img src="https://helioviewer-project.github.io/event-tree/helioviewer-logo.png" alt="Helioviewer Logo" class="logo">
                <h1>Helioviewer Events API</h1>
            </div>
            <div class="subtitle">Active Regions Search</div>
            <div class="nav-buttons">
                <a href="/" class="nav-button">Home</a>
                <a href="/stats" class="nav-button">Statistics Dashboard</a>
                <a href="/active-regions" class="nav-button active">Active Regions</a>
            </div>
        </header>

        <div class="container">
            <h2 class="page-title">Search Solar Active Regions</h2>

        <div class="search-form">
            <form id="searchForm">
                <div class="form-group">
                    <label>Select Organization:</label>
                    <div class="radio-group">
                        <div class="radio-item">
                            <input type="radio" id="org_all" name="organization" value="" checked>
                            <label for="org_all">All</label>
                        </div>
                        <div class="radio-item">
                            <input type="radio" id="org_noaa" name="organization" value="NOAA">
                            <label for="org_noaa">NOAA</label>
                        </div>
                        <div class="radio-item">
                            <input type="radio" id="org_catania" name="organization" value="CATANIA">
                            <label for="org_catania">Catania</label>
                        </div>
                        <div class="radio-item">
                            <input type="radio" id="org_harp" name="organization" value="HARP">
                            <label for="org_harp">HARP</label>
                        </div>
                    </div>
                </div>

                <div class="form-group">
                    <label for="region_id">Region ID:</label>
                    <input type="text" id="region_id" name="region_id" placeholder="Enter region number (e.g., 14188)">
                </div>

                <button type="submit">Search Solar Events</button>
            </form>
        </div>

            <div id="results" class="results"></div>

            <h2 class="section-title">Latest Regions</h2>
            <div id="tablesLoading" class="loading">
                <div class="spinner"></div>
                <p>Loading regions...</p>
            </div>
            <div id="tablesContent" style="display: none;">
                <table>
                    <thead>
                        <tr>
                            <th>Organization</th>
                            <th>Region ID</th>
                            <th>Latest Event</th>
                            <th>Events</th>
                            <th>API</th>
                        </tr>
                    </thead>
                    <tbody id="latestRegionsBody"></tbody>
                </table>

                <h2 class="section-title">Regions by Most Events</h2>
                <table>
                    <thead>
                        <tr>
                            <th>Organization</th>
                            <th>Region ID</th>
                            <th>Latest Event</th>
                            <th>Events</th>
                            <th>API</th>
                        </tr>
                    </thead>
                    <tbody id="topRegionsBody"></tbody>
                </table>
            </div>
        </div>
    </div>

    <script>
        // Add visual feedback for radio button selection
        document.querySelectorAll('.radio-item').forEach(item => {
            item.addEventListener('click', function() {
                // Remove selected class from all items
                document.querySelectorAll('.radio-item').forEach(i => i.classList.remove('selected'));
                // Add selected class to clicked item
                this.classList.add('selected');
                // Check the radio button
                this.querySelector('input[type="radio"]').checked = true;
            });
        });

        // Set initial selected state
        document.querySelector('.radio-item input:checked').closest('.radio-item').classList.add('selected');

        document.getElementById('searchForm').addEventListener('submit', async function(e) {
            e.preventDefault();

            const organization = document.querySelector('input[name="organization"]:checked').value;
            const regionId = document.getElementById('region_id').value;
            const resultsDiv = document.getElementById('results');

            if (!regionId.trim()) {
                resultsDiv.innerHTML = '<div class="error">Please enter a region ID</div>';
                return;
            }

            resultsDiv.innerHTML = '<div class="loading">Searching...</div>';

            try {
                // Build API URL
                let apiUrl;
                if (organization) {
                    apiUrl = `/api/v1/regions/\${organization}/\${encodeURIComponent(regionId)}`;
                } else {
                    // Try each organization if none specified
                    const orgs = ['NOAA', 'CATANIA', 'HARP'];
                    let results = [];

                    for (const org of orgs) {
                        try {
                            const response = await fetch(`/api/v1/regions/\${org}/\${encodeURIComponent(regionId)}`);
                            if (response.ok) {
                                const data = await response.json();
                                if (data.events && data.events.length > 0) {
                                    results.push({ organization: org, data: data });
                                }
                            }
                        } catch (err) {
                            console.log(`No results for \${org}`);
                        }
                    }

                    if (results.length === 0) {
                        resultsDiv.innerHTML = '<div class="error">No entries found for this region ID in any organization</div>';
                        return;
                    }

                    displayResults(results);
                    return;
                }

                const response = await fetch(apiUrl);

                if (!response.ok) {
                    if (response.status === 404) {
                        resultsDiv.innerHTML = `<div class="error">No entries found for \${organization} region \${regionId}</div>`;
                        return;
                    }
                    throw new Error(`HTTP \${response.status}: \${response.statusText}`);
                }

                const data = await response.json();
                displayResults([{ organization: organization, data: data }]);

            } catch (error) {
                resultsDiv.innerHTML = `<div class="error">Error: \${error.message}</div>`;
            }
        });

        function displayResults(results) {
            const resultsDiv = document.getElementById('results');
            let html = '';

            results.forEach(result => {
                const events = result.data.events || [];
                const regionInfo = result.data.region || {};

                html += `<h3>\${result.organization} - \${events.length} entries found</h3>`;

                if (events.length === 0) {
                    html += '<div class="result-item">No entries found for this region</div>';
                    return;
                }

                events.forEach(event => {
                    // Parse dates - handle both timestamp and string formats
                    let startDate = 'N/A';
                    let endDate = 'N/A';

                    if (event.start) {
                        // Check if it's a timestamp (number) or string
                        if (typeof event.start === 'number') {
                            startDate = new Date(event.start * 1000).toLocaleString();
                        } else {
                            // Parse string date format "YYYY-MM-DD HH:MM:SS"
                            startDate = new Date(event.start).toLocaleString();
                        }
                    }
                    if (event.end) {
                        if (typeof event.end === 'number') {
                            endDate = new Date(event.end * 1000).toLocaleString();
                        } else {
                            endDate = new Date(event.end).toLocaleString();
                        }
                    }

                    // Extract event ID from URL if present
                    let eventId = 'N/A';
                    if (event.url) {
                        const matches = event.url.match(/events\/([a-f0-9-]+)/);
                        if (matches) {
                            eventId = matches[1];
                        }
                    }

                    // Clean up the label to show just the probabilities
                    let probabilityText = event.short_label || event.label || 'No probabilities';
                    // Remove leading newline if present
                    probabilityText = probabilityText.trim();

                    // Determine coordinate system label
                    let coordLabel = 'Coordinates';
                    if (event.coordinate_system === 'stonyhurst') {
                        coordLabel = 'Stonyhurst Coordinates';
                    } else if (event.coordinate_system === 'helioprojective') {
                        coordLabel = 'Helioprojective Coordinates';
                    }

                    html += `
                        <div class="result-item">
                            <div class="result-header">\${probabilityText}</div>
                            <div><strong>Path:</strong> \${event.path || 'Unknown'}</div>
                            <div><strong>Region:</strong> \${result.organization} \${regionInfo.external_id || 'Unknown'}</div>
                            <div><strong>Event Period:</strong> \${startDate} - \${endDate}</div>
                            <div><strong>\${coordLabel}:</strong> (\${event.hv_hpc_x ?? 'N/A'}, \${event.hv_hpc_y ?? 'N/A'})</div>
                            <div class="result-meta">Event ID: \${event.url ? `<a href="\${event.url}" target="_blank">\${eventId}</a>` : eventId}</div>
                        </div>
                    `;
                });
            });

            resultsDiv.innerHTML = html;
        }

        // Load region tables on page load
        async function loadRegionTables() {
            const loading = document.getElementById('tablesLoading');
            const content = document.getElementById('tablesContent');

            try {
                const response = await fetch('/api/v1/regions');
                if (!response.ok) throw new Error('Failed to fetch regions');

                const data = await response.json();
                const allRegions = data.regions || [];

                // Filter out MODEL organization and NOAA UNK
                const regions = allRegions.filter(r => {
                    if (r.organization === 'MODEL') return false;
                    if (r.organization === 'NOAA' && r.external_id === 'UNK') return false;
                    return true;
                });

                // Sort by last_updated descending for latest regions
                const latestRegions = [...regions]
                    .sort((a, b) => new Date(b.last_updated) - new Date(a.last_updated))
                    .slice(0, 10);

                // Sort by event_count descending for top regions
                const topRegions = [...regions]
                    .sort((a, b) => b.event_count - a.event_count)
                    .slice(0, 10);

                // Helper function to format date
                function formatEventDate(dateStr) {
                    if (!dateStr) return 'N/A';
                    const date = new Date(dateStr);
                    return date.toLocaleDateString() + ' ' + date.toLocaleTimeString([], {hour: '2-digit', minute:'2-digit'});
                }

                // Populate latest regions table (ordered by last_updated)
                const latestBody = document.getElementById('latestRegionsBody');
                latestBody.innerHTML = latestRegions.map(r => `
                    <tr>
                        <td>\${r.organization}</td>
                        <td>\${r.external_id}</td>
                        <td>\${formatEventDate(r.latest_event_start)}</td>
                        <td class="number">\${r.event_count}</td>
                        <td><a href="/api/v1/regions/\${r.organization}/\${r.external_id}" target="_blank" class="api-link">View JSON</a></td>
                    </tr>
                `).join('');

                // Populate top regions table
                const topBody = document.getElementById('topRegionsBody');
                topBody.innerHTML = topRegions.map(r => `
                    <tr>
                        <td>\${r.organization}</td>
                        <td>\${r.external_id}</td>
                        <td>\${formatEventDate(r.latest_event_start)}</td>
                        <td class="number">\${r.event_count}</td>
                        <td><a href="/api/v1/regions/\${r.organization}/\${r.external_id}" target="_blank" class="api-link">View JSON</a></td>
                    </tr>
                `).join('');

                loading.style.display = 'none';
                content.style.display = 'block';

            } catch (error) {
                loading.innerHTML = '<div class="error">Error loading regions: ' + error.message + '</div>';
            }
        }

        // Load tables on page load
        loadRegionTables();
    </script>
</body>
</html>
HTML;

        $response->getBody()->write($html);
        return $response->withHeader('Content-Type', 'text/html');
    }
}
