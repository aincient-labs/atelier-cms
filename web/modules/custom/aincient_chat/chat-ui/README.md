# AIncient chat-ui

The AIncient operator console — an [assistant-ui](https://www.assistant-ui.com/)
chat island (React 19 + Vite). It builds to a single self-mounting IIFE bundle at
`../js/dist/`, which the `aincient_chat` Drupal module serves as a library. There is
no build step at install time; the built artifact is committed.

## Develop

```bash
npm install
npm run dev        # Vite dev server
npm run build      # → ../js/dist/aincient-chat.{js,css} + THIRD-PARTY-NOTICES.txt
npm run test       # vitest
npm run typecheck  # tsc --noEmit
```

The console reads its config from `window.aincientChat` (falling back to Drupal's
`drupalSettings.aincientChat`) and POSTs to the `/atelier/chat` SSE endpoint. Both
are runtime conventions, not build-time coupling.

## License

Licensed under the **MIT License** — see [`LICENSE`](./LICENSE). You may use,
modify, distribute, and embed it, including in proprietary products, provided the
copyright notice and permission notice are preserved.

### Third-party dependencies

chat-ui bundles third-party packages under permissive licenses (MIT / ISC / BSD /
Apache-2.0 / 0BSD). Their notices are reproduced in **`THIRD-PARTY-NOTICES.txt`**,
regenerated on every build (`npm run notices`) and shipped alongside the bundle.
No copyleft dependency is permitted — the notices generator fails the build if one
appears, since bundling copyleft code would relicense the whole distributable.
