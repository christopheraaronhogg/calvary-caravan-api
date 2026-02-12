export const API_BASE = '/api/v1/retreat';

function parseErrorText(text: string, status: number): string {
  if (!text) return `Request failed (${status})`;

  try {
    const parsed = JSON.parse(text) as {
      error?: string;
      message?: string;
      errors?: Record<string, string[] | string>;
    };

    if (parsed.error) return parsed.error;
    if (parsed.message) return parsed.message;

    if (parsed.errors && typeof parsed.errors === 'object') {
      const first = Object.values(parsed.errors)[0];
      if (Array.isArray(first) && first.length > 0) return String(first[0]);
      if (typeof first === 'string') return first;
    }
  } catch {
    // non-json response; return raw text below
  }

  return text;
}

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
    headers
  });

  if (!response.ok) {
    const text = await response.text();
    throw new Error(parseErrorText(text, response.status));
  }

  return (await response.json()) as T;
}
