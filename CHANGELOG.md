# Changelog

All notable changes to Techn Journey Graph are recorded here.

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
