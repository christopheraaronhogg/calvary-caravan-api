# NativePHP + SvelteKit Bootstrap (Ship-Today)

This is the **Svelte-first** mobile path for Calvary Caravan.

It also standardizes on **Runed** (`runed.dev`) as the Svelte utility layer.

## Architecture (today)

- Laravel (`/`) remains the API/backend and NativePHP host.
- SvelteKit lives in `frontend/`.
- **Runed** is the default utility layer for runes/state helpers.
- **shadcn-svelte** is the default component primitive layer.
- SvelteKit static build outputs to `public/mobile`.
- NativePHP loads `NATIVEPHP_START_URL=/mobile/index.html`.
- API stays at `/api/v1/retreat/*` with `X-Device-Token` auth.

## Bootstrap command

From repo root:

```bash
./scripts/nativephp-svelte-bootstrap.sh
```

This command will:

1. Scaffold `frontend/` using SvelteKit (if missing)
2. Install `@sveltejs/adapter-static`
3. Install `runed` (Runed utilities)
4. Configure build output to `public/mobile`
5. Add a starter API helper + join screen
6. Build the Svelte app once

Optional UI kit init (recommended):

```bash
npx shadcn-svelte@latest init
```

## Daily dev loop

```bash
# terminal 1: Laravel API
php artisan serve

# terminal 2: Svelte UI
npm run svelte:dev

# terminal 3: Native shell (iOS simulator)
php artisan native:run ios <SIMULATOR_UDID> --build=debug --start-url=/mobile/index.html --no-tty
```

Android local run:

```bash
php artisan native:run android --build=debug --start-url=/mobile/index.html --no-tty
```

## Required environment setting

```dotenv
NATIVEPHP_START_URL=/mobile/index.html
```

## Release build reminders

- Run preflight first:

```bash
./scripts/nativephp-preflight.sh
```

- iOS package/upload:

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

- Android package/upload (internal track):

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
