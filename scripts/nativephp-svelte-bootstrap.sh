#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
FRONTEND_DIR="$ROOT_DIR/frontend"

cd "$ROOT_DIR"

echo "==> NativePHP + SvelteKit bootstrap"

if ! command -v npm >/dev/null 2>&1; then
  echo "npm is required but not installed."
  exit 1
fi

if [[ ! -d "$FRONTEND_DIR" ]]; then
  echo "Scaffolding SvelteKit app in ./frontend ..."
  npx --yes sv@latest create frontend \
    --template minimal \
    --types ts \
    --no-add-ons \
    --install npm \
    --no-download-check
else
  echo "frontend/ already exists. Skipping scaffold step."
fi

cd "$FRONTEND_DIR"

if ! grep -q '@sveltejs/adapter-static' package.json 2>/dev/null; then
  echo "Installing @sveltejs/adapter-static ..."
  npm install --save-dev @sveltejs/adapter-static
fi

if ! grep -q '"runed"' package.json 2>/dev/null; then
  echo "Installing runed utilities ..."
  npm install runed
fi

echo "Writing SvelteKit config for NativePHP static bundle output ..."
cat > svelte.config.js <<'EOF'
import adapter from '@sveltejs/adapter-static';
import { vitePreprocess } from '@sveltejs/vite-plugin-svelte';

const config = {
  preprocess: vitePreprocess(),
  kit: {
    adapter: adapter({
      pages: '../public/mobile',
      assets: '../public/mobile',
      fallback: 'index.html',
      precompress: false,
      strict: false,
    }),
    paths: {
      base: '/mobile',
    },
  },
};

export default config;
EOF

mkdir -p src/lib src/routes

if [[ ! -f src/lib/api.ts ]]; then
  echo "Creating src/lib/api.ts helper ..."
  cat > src/lib/api.ts <<'EOF'
export const API_BASE = '/api/v1/retreat';

export async function api<T>(
  path: string,
  init: RequestInit = {},
  deviceToken?: string
): Promise<T> {
  const headers = new Headers(init.headers ?? {});
  headers.set('Accept', 'application/json');

  if (init.body && !headers.has('Content-Type')) {
    headers.set('Content-Type', 'application/json');
  }

  if (deviceToken) {
    headers.set('X-Device-Token', deviceToken);
  }

  const response = await fetch(`${API_BASE}${path}`, {
    ...init,
    headers,
  });

  if (!response.ok) {
    const text = await response.text();
    throw new Error(text || `Request failed: ${response.status}`);
  }

  return (await response.json()) as T;
}
EOF
fi

if [[ ! -f src/routes/+page.svelte ]]; then
  echo "Creating starter join screen at src/routes/+page.svelte ..."
  cat > src/routes/+page.svelte <<'EOF'
<script lang="ts">
  import { api } from '$lib/api';

  let code = '';
  let name = '';
  let deviceToken = '';
  let loading = false;
  let error = '';

  async function joinRetreat() {
    loading = true;
    error = '';

    try {
      const payload = await api<{ data: { device_token: string } }>('/join', {
        method: 'POST',
        body: JSON.stringify({
          code: code.trim().toUpperCase(),
          name: name.trim(),
        }),
      });

      deviceToken = payload.data.device_token;
      localStorage.setItem('caravan_device_token', deviceToken);
    } catch (err) {
      error = err instanceof Error ? err.message : 'Unable to join retreat';
    } finally {
      loading = false;
    }
  }
</script>

<main class="shell">
  <h1>Calvary Caravan</h1>
  <p>NativePHP + SvelteKit starter screen</p>

  <form on:submit|preventDefault={joinRetreat}>
    <label>
      Retreat code
      <input bind:value={code} placeholder="TEST26" maxlength="12" required />
    </label>

    <label>
      Name
      <input bind:value={name} placeholder="Chris" maxlength="50" required />
    </label>

    <button type="submit" disabled={loading}>{loading ? 'Joining...' : 'Join retreat'}</button>
  </form>

  {#if deviceToken}
    <p class="ok">Joined. Device token saved locally.</p>
  {/if}

  {#if error}
    <pre class="error">{error}</pre>
  {/if}
</main>

<style>
  :global(body) {
    margin: 0;
    font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
    background: #0b1020;
    color: #f8fafc;
  }

  .shell {
    max-width: 420px;
    margin: 0 auto;
    padding: 2rem 1rem 3rem;
  }

  h1 {
    margin: 0 0 0.25rem;
  }

  p {
    color: #cbd5e1;
  }

  form {
    display: grid;
    gap: 0.85rem;
    margin-top: 1.25rem;
  }

  label {
    display: grid;
    gap: 0.35rem;
    font-size: 0.9rem;
  }

  input,
  button {
    border-radius: 10px;
    border: 1px solid #334155;
    padding: 0.7rem 0.75rem;
    font: inherit;
  }

  input {
    background: #0f172a;
    color: #f8fafc;
  }

  button {
    background: #2563eb;
    border-color: #2563eb;
    color: #fff;
    font-weight: 600;
  }

  .ok {
    margin-top: 1rem;
    color: #4ade80;
  }

  .error {
    margin-top: 1rem;
    background: #450a0a;
    border: 1px solid #7f1d1d;
    border-radius: 10px;
    padding: 0.75rem;
    white-space: pre-wrap;
    word-break: break-word;
  }
</style>
EOF
fi

echo "Building SvelteKit app to public/mobile ..."
npm run build

cd "$ROOT_DIR"

echo
echo "✅ SvelteKit bootstrap complete"
echo "Next steps:"
echo "  1) Set NATIVEPHP_START_URL=/mobile/index.html in .env"
echo "  2) npm run svelte:dev    (or cd frontend && npm run dev)"
echo "  3) php artisan native:run ios <SIMULATOR_UDID> --build=debug --start-url=/mobile/index.html --no-tty"
