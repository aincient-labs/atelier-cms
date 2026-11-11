import { csrfTokenUrl } from "./console-config";

/**
 * Every console write carries Drupal's `X-CSRF-Token` (DECISIONS 0511).
 *
 * The server requires the header on every Atelier route that accepts an unsafe
 * method (aincient_core's CsrfHeaderRouteSubscriber). Rather than threading a
 * token through each of the console's fetch call sites, `installCsrfFetch()`
 * wraps `window.fetch` once at boot: a same-origin request with an unsafe
 * method gets the session token unless it already set one. Cross-origin and
 * safe requests pass through untouched, so the token never leaves the origin.
 *
 * The token is fetched once per page from `/session/token`. Should a write come
 * back 403, the token is fetched again: if it changed (the session moved on
 * under the page), the request is retried once with the new one. A refusal
 * happens in the access check, before any controller runs, so the retry cannot
 * double a write; a plain permission 403 keeps the same token and is not
 * retried. (Drupal's 403 page doesn't say which check failed, hence the
 * comparison rather than reading the body.)
 */

const SAFE = new Set(["GET", "HEAD", "OPTIONS", "TRACE"]);
const HEADER = "X-CSRF-Token";

let token: Promise<string> | null = null;
let installed = false;

/** Drupal's session CSRF token ("" when there is no token URL or the fetch fails). */
function sessionToken(doFetch: typeof fetch): Promise<string> {
  const url = csrfTokenUrl();
  if (!url) return Promise.resolve("");
  token ??= doFetch(url, { credentials: "same-origin" }).then(
    (res) => (res.ok ? res.text() : ""),
    () => "",
  );
  return token;
}

/** Test seam: forget the cached token and allow a fresh install. */
export function resetCsrf(): void {
  token = null;
  installed = false;
}

function requestMethod(input: RequestInfo | URL, init?: RequestInit): string {
  if (init?.method) return init.method.toUpperCase();
  if (input instanceof Request) return input.method.toUpperCase();
  return "GET";
}

function requestUrl(input: RequestInfo | URL): string {
  if (typeof input === "string") return input;
  if (input instanceof URL) return input.href;
  return input.url;
}

function sameOrigin(url: string, origin: string, base: string): boolean {
  try {
    return new URL(url, base).origin === origin;
  } catch {
    return false;
  }
}

/**
 * Wraps `target.fetch` so same-origin writes carry the session CSRF token.
 * Idempotent: a second call is a no-op.
 */
export function installCsrfFetch(target: Window & typeof globalThis = window): void {
  if (installed) return;
  installed = true;
  const original = target.fetch.bind(target);

  target.fetch = async (input: RequestInfo | URL, init?: RequestInit): Promise<Response> => {
    const method = requestMethod(input, init);
    if (SAFE.has(method) || !sameOrigin(requestUrl(input), target.location.origin, target.location.href)) {
      return original(input, init);
    }
    const given = new Headers(init?.headers ?? (input instanceof Request ? input.headers : undefined));
    if (given.has(HEADER)) return original(input, init);

    const send = async (value: string): Promise<Response> => {
      const headers = new Headers(given);
      if (value) headers.set(HEADER, value);
      return original(input, { ...init, headers });
    };

    const sent = await sessionToken(original);
    const res = await send(sent);
    // A Request's body is single-use, so only a (url, init) call is retried.
    if (res.status !== 403 || !sent || input instanceof Request) return res;
    token = null;
    const fresh = await sessionToken(original);
    return fresh && fresh !== sent ? send(fresh) : res;
  };
}
