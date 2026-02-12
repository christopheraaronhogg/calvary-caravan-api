# Calvary Caravan — App Store Submission Readiness Kit

This folder is the one-stop prep pack to get **Calvary Caravan** ready for App Store review.

## Goal
Get to “ready to submit” state before paying Apple membership, so once membership/payment is active, submission is mostly click-through.

## What this kit contains
- `metadata.en-US.md` — paste-ready App Store metadata draft
- `review-info.en-US.md` — App Review contact + testing notes template
- `screenshot-shotlist.md` — screenshot requirements + exact shot plan
- `submission-checklist.csv` — execution checklist with status tracking

## Scripts
- `scripts/nativephp-fix-appicon.sh`
  - Ensures iOS AppIcon is 1024x1024 without alpha (App Store requirement)
- `scripts/appstore-readiness.sh`
  - Verifies key blockers (icon format, URLs, screenshots, signing env vars, cert/API key files)
- `scripts/capture-appstore-screenshots.sh`
  - Assisted screenshot capture from a booted iOS simulator

## Quick run
```bash
cd /Users/chrishogg/Documents/GitHub/calvary-caravan-api
./scripts/nativephp-fix-appicon.sh
./scripts/appstore-readiness.sh
./scripts/nativephp-preflight.sh
```

If checks report `.env` unreadable / `Resource deadlock avoided`, your repo is likely in an iCloud-managed path with dataless files. Move/clone to a non-iCloud path and rerun.

## Manual account-holder-only actions (cannot be automated here)
1. Pay Apple Developer Program annual fee / confirm active membership.
2. Accept any pending legal agreements in App Store Connect.
3. Final click-through in App Store Connect (“Submit for Review”).

## Current known technical blockers (outside metadata/docs)
- Apple code-signing identity not present in keychain.
- iOS signing env vars/cert/profile/API key values not fully populated.

Until those are fixed, packaging/upload remains blocked even if metadata is complete.
