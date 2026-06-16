# Changelog

All notable changes to Techn Journey Graph are recorded here.

## 0.1.10 - 2026-06-16

- Removed the panel header "Top x" labels.
- Right-aligned the close button in the controls row.
- Added an exit page distribution panel for each selected hop slice.
- Changed landing page distribution to show the selected hop pages instead of the original session landing page.
- Kept source panels hop-aware so attribution follows the selected hop slice.
- Bumped the graph schema reset so stale source aggregates cannot survive the update.

## 0.1.9 - 2026-06-16

- Removed the drawer title, page name, and freshness text from the front-end panel.
- Moved the close button into the controls row.
- Changed referrer and UTM attribution so "To Here" counts direct landing on the selected page and "From Here" counts prior landing pages in the journey.
- Grouped content type distribution by content type label instead of individual URLs.
- Added URL query fallback parsing for UTM labels when IA campaign lookup values are unavailable.
- Reset existing graph aggregates for reprocessing under the updated model.

## 0.1.8 - 2026-06-16

- Rebuilt the release artifact with the current plugin version and updater behavior.
- Loaded WordPress update functions explicitly before running the manual Plugins-screen update check.

## 0.1.7 - 2026-06-16

- Changed the plugin-row update check link to run a plugin-page GitHub update check and return to the Plugins screen.

## 0.1.6 - 2026-06-16

- Moved the object type filter into the hop controls row.
- Changed the default selected hop to Landing.
- Reordered panels to show Landing Page Distribution first.
- Removed per-row count text below histogram bars.
- Compacted histogram row spacing and changed bars to taller square-corner bars.

## 0.1.5 - 2026-06-16

- Added a plugin-row "Check for updates" link for active admin contexts.

## 0.1.4 - 2026-06-16

- Changed the Journey Explorer drawer content to span the full viewport width.
- Compacted the panel typography, spacing, bars, and controls.
- Changed analytics panels to render in one horizontal row.

## 0.1.3 - 2026-06-16

- Changed the plugin header URI to the latest GitHub release page for inactive-plugin self-serve updates.
- Added the WordPress `Update URI` header for GitHub update identity.

## 0.1.2 - 2026-06-16

- Fixed journey processing against current Independent Analytics referrer columns.
- Added a skipped queue state so unprocessable sessions cannot block later ready sessions.
- Added skipped queue counts to processing status.

## 0.1.1 - 2026-06-16

- Added historical session queueing for existing Independent Analytics sites.
- Added configurable processing batch size with a default of 100 sessions per run.
- Changed processing to queue completed historical sessions before each manual or scheduled run.
- Added clearer queue counts to processing status messages.

## 0.1.0 - 2026-06-16

- Added the initial Independent Analytics journey graph processor.
- Added protected front-end Journey Explorer REST data endpoint.
- Added authorised front-end launcher, bottom drawer, hop tabs, object filter, and reusable histogram panels.
- Added settings page, processing status, and manual processing action.
- Added GitHub release updater metadata for `cchatterton/tn-journey-graph`.
- Added release ZIP build script.
