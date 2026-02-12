# NativePHP Mobile Bootstrap (Ship-Today Path)

This repo now includes NativePHP Mobile scaffolding so you can package iOS + Android directly from Laravel.

## What was bootstrapped

- `nativephp/mobile` added to Composer dependencies
- `config/nativephp.php` generated
- `native` CLI wrapper added (`./native`)
- Bootstrap scripts added:
  - `./scripts/nativephp-svelte-bootstrap.sh` (Svelte shell bootstrap)
  - `./scripts/nativephp-preflight.sh`
  - `./scripts/nativephp-bootstrap.sh`

## Fast start

```bash
cd /Users/chrishogg/Documents/GitHub/calvary-caravan-api
./scripts/nativephp-svelte-bootstrap.sh
./scripts/nativephp-bootstrap.sh
./scripts/nativephp-preflight.sh
./scripts/web-health-check.sh
```

If preflight fails, install missing dependencies first (CocoaPods, Java 17, adb, Android SDK, code-signing credentials).

---

## Required env keys

Set these in `.env` before packaging:

```dotenv
APP_KEY=
NATIVEPHP_APP_ID=com.calvarybaptist.calvarycaravan
NATIVEPHP_APP_VERSION=1.0.0
NATIVEPHP_APP_VERSION_CODE=1
NATIVEPHP_START_URL=/mobile/index.html
IOS_TEAM_ID=

ANDROID_KEYSTORE_FILE=
ANDROID_KEYSTORE_PASSWORD=
ANDROID_KEY_ALIAS=
ANDROID_KEY_PASSWORD=

# For direct store uploads
APP_STORE_API_KEY_PATH=
APP_STORE_API_KEY_ID=
APP_STORE_API_ISSUER_ID=
GOOGLE_SERVICE_KEY=
```

---

## Build / run (development)

List simulators:

```bash
xcrun simctl list devices
```

Run iOS debug build on simulator:

```bash
php artisan native:run ios <SIMULATOR_UDID> --build=debug --start-url=/mobile/index.html --no-tty
```

Recommended on this Mac mini (auto locale + stable non-iCloud build path + simulator auto-pick):

```bash
./scripts/nativephp-run-ios-sim.sh
```

If your repo lives in iCloud-managed folders (`Documents`, `Desktop`) and you hit `Resource deadlock avoided` or `dataless` file errors, clone/move to a non-iCloud path (for example `~/GitHub/...`) before packaging.

Run Android debug build:

```bash
php artisan native:run android --build=debug --start-url=/mobile/index.html --no-tty
```

---

## Package for store tracks

### iOS (TestFlight)

```bash
php artisan native:package ios \
  --export-method=app-store \
  --team-id="$IOS_TEAM_ID" \
  --certificate-path="$IOS_DISTRIBUTION_CERTIFICATE_PATH" \
  --certificate-password="$IOS_DISTRIBUTION_CERTIFICATE_PASSWORD" \
  --provisioning-profile-path="$IOS_DISTRIBUTION_PROVISIONING_PROFILE_PATH" \
  --api-key-path="$APP_STORE_API_KEY_PATH" \
  --api-key-id="$APP_STORE_API_KEY_ID" \
  --api-issuer-id="$APP_STORE_API_ISSUER_ID" \
  --upload-to-app-store
```

### Android (Play Internal testing)

```bash
php artisan native:package android \
  --build-type=bundle \
  --keystore="$ANDROID_KEYSTORE_FILE" \
  --keystore-password="$ANDROID_KEYSTORE_PASSWORD" \
  --key-alias="$ANDROID_KEY_ALIAS" \
  --key-password="$ANDROID_KEY_PASSWORD" \
  --upload-to-play-store \
  --play-store-track=internal \
  --google-service-key="$GOOGLE_SERVICE_KEY"
```

---

## Known blockers on a fresh machine

- `pod` missing → iOS native build fails
- `java`, `keytool`, `adb` missing → Android build/credentials fail
- no Apple signing identities in keychain → iOS packaging/upload fails

Use `./scripts/nativephp-preflight.sh` to verify all blockers are cleared before release packaging.
