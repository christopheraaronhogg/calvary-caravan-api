# Calvary Caravan — Google Play Submission Readiness Kit

This folder is the Google Play counterpart to `docs/app-store/`.

## Goal
Ship Android first with a policy-aligned, evidence-backed Play Console package while keeping iOS parity notes intact.

## Contents
- `metadata.en-US.md` — paste-ready Play Store listing copy
- `review-notes.en-US.md` — app access + reviewer notes draft
- `data-safety-and-permissions.md` — policy-facing rationale for data/permissions
- `submission-checklist.csv` — execution tracker with done/blocked state

## Scripts
- `scripts/nativephp-preflight.sh` — machine + env readiness baseline
- `scripts/playstore-readiness.sh` — Google Play specific checks (docs, URLs, identity/deletion wiring, Android env)

## Quick run
```bash
cd /Users/chrishogg/Documents/GitHub/calvary-caravan-api
./scripts/nativephp-preflight.sh
./scripts/playstore-readiness.sh
```

## Owner-only actions (cannot be automated here)
1. Keep Google Play Console account active and in good standing.
2. Complete Play policy declarations (Data safety, app access, content rating, ads status).
3. Create production release and click final submit in Play Console.
