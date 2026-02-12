# Screenshot Plan (iOS App Store)

## Apple minimum rules (current)
- Upload **1 to 10** screenshots per device size/language.
- If app runs on iPhone, provide accepted iPhone screenshot sizes.
- If app runs on iPad, iPad screenshots are required too.

Reference:
- Apple screenshot specs: https://developer.apple.com/help/app-store-connect/reference/app-information/screenshot-specifications/

## Target for Calvary Caravan
Current config sets iPad support to false, so target **iPhone set first**.

### Primary iPhone set (recommended)
Use **6.9" display** accepted size, portrait:
- 1320 × 2868 (or another accepted 6.9" size listed by Apple)

Store files in:
`artifacts/app-store/ios/iphone-6.9/en-US/`

## Shot sequence (recommended 5)
1. `01-join-retreat.png`
   - Join screen with retreat code + name fields
2. `02-live-group-status.png`
   - Live participant/map/status view
3. `03-group-chat.png`
   - Group chat in active use
4. `04-location-sharing.png`
   - Location/status update context
5. `05-profile-and-settings.png`
   - Profile/vehicle + key settings

## Capture helper
```bash
cd /Users/chrishogg/Documents/GitHub/calvary-caravan-api
./scripts/capture-appstore-screenshots.sh
# optional non-interactive mode (captures every 2s):
./scripts/capture-appstore-screenshots.sh iphone-6.9 en-US --auto
```

## Quality checks before upload
- No placeholder lorem text.
- No debug overlays or local dev URLs visible.
- Consistent theme/branding across all screenshots.
- Avoid fake promises or features not available in this build.
