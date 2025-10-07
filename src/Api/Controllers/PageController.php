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
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            max-width: 800px;
            margin: 50px auto;
            padding: 20px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
        }
        .container {
            background: white;
            border-radius: 12px;
            padding: 40px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.1);
        }
        h1 {
            color: #333;
            margin-bottom: 10px;
        }
        .version {
            color: #666;
            font-size: 14px;
            margin-bottom: 30px;
        }
        h2 {
            color: #667eea;
            margin-top: 30px;
            border-bottom: 2px solid #f0f0f0;
            padding-bottom: 10px;
        }
        ul {
            line-height: 1.8;
        }
        a {
            color: #667eea;
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
        .stats-link {
            display: inline-block;
            background: #667eea;
            color: white;
            padding: 10px 20px;
            border-radius: 6px;
            margin-top: 20px;
        }
        .stats-link:hover {
            background: #5a67d8;
            text-decoration: none;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>Helioviewer Events API</h1>
        <p class="version">Version 2.0</p>

        <p>Welcome to the Helioviewer Events API. This API provides access to solar event data from multiple sources.</p>

        <h2>Available Endpoints</h2>

        <h3>Events</h3>
        <ul>
            <li><div class="endpoint">GET /api/v2/events/recents</div> Get recent events</li>
            <li><div class="endpoint">GET /api/v2/events/{uuid}</div> Get event by UUID</li>
            <li><div class="endpoint">GET /api/v2/events/{uuid}/source</div> Get event source data</li>
            <li><div class="endpoint">GET /api/v2/events/{source}/observation/{timestamp}</div> Get events by observation time</li>
            <li><div class="endpoint">GET /api/v1/events/{source}/observation/{timestamp}</div> Legacy V1 format</li>
        </ul>

        <h3>Regions</h3>
        <ul>
            <li><div class="endpoint">GET /api/v2/regions</div> Get all regions</li>
            <li><div class="endpoint">GET /api/v2/regions/{organization}/{external_id}</div> Get events for specific region</li>
        </ul>

        <h3>Statistics</h3>
        <ul>
            <li><div class="endpoint">GET /api/v2/stats</div> Get API statistics (JSON)</li>
            <li><div class="endpoint">GET /stats</div> View statistics dashboard (HTML)</li>
        </ul>

        <a href="/stats" class="stats-link">View Statistics Dashboard</a>

        <h2>Documentation</h2>
        <p>For more information, visit the <a href="https://helioviewer.org" target="_blank">Helioviewer website</a>.</p>
    </div>
</body>
</html>
HTML;

        $response->getBody()->write($html);
        return $response->withHeader('Content-Type', 'text/html');
    }
}