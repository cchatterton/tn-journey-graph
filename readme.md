# Techn Journey Graph

Author: Techn  
Version: 0.1.9  
Status: MVP

## Purpose

Techn Journey Graph is a WordPress extension for Independent Analytics Pro. It lets authorised logged-in users explore preprocessed visitor journeys from the public front end without adding new tracking, cookies, or a separate visitor identity system.

## Key Features

- Bottom-left front-end Journey Explorer button for authorised users.
- Full-width bottom drawer with current page context and data freshness.
- Dynamic landing, prior, current, next, and last hop tabs.
- Ten reusable histogram panels for referrer, UTM, content type, and landing page distributions.
- Background processing of completed Independent Analytics sessions into aggregate journey graph data.
- Protected REST endpoint for precomputed journey data.
- Settings page for permissions, processing frequency, hop limits, histogram limits, object types, retention, status, and manual processing.
- GitHub release updater for `cchatterton/tn-journey-graph`.

## Folder Structure

```text
tn-journey-graph/
├── tn-journey-graph.php
├── functions/
│   ├── admin.php
│   ├── assets.php
│   ├── helpers.php
│   ├── processor.php
│   ├── rest.php
│   ├── setup.php
│   └── updater.php
├── scripts/
│   └── tn-journey-graph.js
├── styles/
│   └── tn-journey-graph.css
└── templates/
    └── .gitkeep
```

## Important Notes

- Requires Independent Analytics tables to exist before processing can build journey aggregates.
- The extension reads existing Independent Analytics session, view, resource, referrer, and campaign data.
- The front-end REST route requires the configured capability and does not expose data publicly.
- Processing is batch-based and uses completed sessions only.

## Future Considerations

- Add a dedicated rebuild workflow that clears and regenerates all aggregate rows.
- Add retention pruning for old aggregate rows after production traffic patterns are known.
- Add richer object resolution for third-party campaign, form, and goal plugins.
- Add WP-CLI commands for processing and diagnostics.
