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
     * Show home page
     */
    public function home(Request $request, Response $response): Response
    {
        $html = <<<HTML
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
        ul {
            line-height: 1.8;
        }
        a {
            color: #d35400;
            text-decoration: none;
        }
        a:hover {
            text-decoration: underline;
        }
        .endpoint {
            background: #f8f9fa;
            padding: 8px 12px;
            border-radius: 6px;
            font-family: 'Courier New', monospace;
            margin: 5px 0;
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

        <h2>API Endpoints</h2>

        <h3>Event Data</h3>
        <ul>
            <li><div class="endpoint">GET /api/v2/events/recents</div> Get recent events</li>
            <li><div class="endpoint">GET /api/v2/events/{uuid}</div> Get event by UUID</li>
            <li><div class="endpoint">GET /api/v2/events/{uuid}/source</div> Get event source data</li>
            <li><div class="endpoint">GET /api/v2/events/{source}/observation/{timestamp}</div> Get events by observation time</li>
            <li><div class="endpoint">GET /api/v1/events/{source}/observation/{timestamp}</div> Legacy V1 format</li>
        </ul>

        <h3>Active Region Data</h3>
        <ul>
            <li><div class="endpoint">GET /api/v2/regions</div> Get all regions</li>
            <li><div class="endpoint">GET /api/v2/regions/{organization}/{external_id}</div> Get events for specific region</li>
        </ul>

        <h3>System Information</h3>
        <ul>
            <li><div class="endpoint">GET /api/v2/stats</div> Get API statistics (JSON)</li>
            <li><div class="endpoint">GET /stats</div> View statistics dashboard (HTML)</li>
        </ul>

        <h3>Interactive Tools</h3>
        <ul>
            <li><div class="endpoint">GET /active-regions</div> Search active regions (HTML)</li>
        </ul>

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
                    apiUrl = `/api/v2/regions/\${organization}/\${encodeURIComponent(regionId)}`;
                } else {
                    // Try each organization if none specified
                    const orgs = ['NOAA', 'CATANIA', 'HARP'];
                    let results = [];

                    for (const org of orgs) {
                        try {
                            const response = await fetch(`/api/v2/regions/\${org}/\${encodeURIComponent(regionId)}`);
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

                    html += `
                        <div class="result-item">
                            <div class="result-header">\${probabilityText}</div>
                            <div><strong>Path:</strong> \${event.path || 'Unknown'}</div>
                            <div><strong>Region:</strong> \${result.organization} \${regionInfo.external_id || 'Unknown'}</div>
                            <div><strong>Event Period:</strong> \${startDate} - \${endDate}</div>
                            <div><strong>Stonyhurst Coordinates:</strong> (\${event.hv_hpc_x || 'N/A'}, \${event.hv_hpc_y || 'N/A'})</div>
                            <div class="result-meta">Event ID: \${event.url ? `<a href="\${event.url}" target="_blank">\${eventId}</a>` : eventId}</div>
                        </div>
                    `;
                });
            });

            resultsDiv.innerHTML = html;
        }
    </script>
</body>
</html>
HTML;

        $response->getBody()->write($html);
        return $response->withHeader('Content-Type', 'text/html');
    }
}