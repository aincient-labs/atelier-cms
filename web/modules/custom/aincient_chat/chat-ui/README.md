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
`drupalSettings.aincientChat`) and POSTs to the `/aincient/chat` SSE endpoint. Both
are runtime conventions, not build-time coupling.

## License

Licensed under the **GNU Affero General Public License v3.0 only
(AGPL-3.0-only)** — see [`LICENSE`](./LICENSE). You may use, modify, and self-host
under the AGPL, including its §13 network-use obligation: if you run a modified
version as a network service, you must offer its complete source to your users.

> **Alternative licensing.** The maintainer (AIncient Labs) retains full copyright.
> A commercial license exempting you from the AGPL's terms may be offered in the
> future for those who need it (e.g. embedding in a proprietary product). It is not
> being sold yet; a contact will be published if and when it is.

### Third-party dependencies

chat-ui bundles third-party packages under permissive licenses (MIT / ISC / BSD /
Apache-2.0 / 0BSD). Their notices are reproduced in **`THIRD-PARTY-NOTICES.txt`**,
regenerated on every build (`npm run notices`) and shipped alongside the bundle.
No copyleft dependency is permitted — the notices generator fails the build if one
appears, since it would break the dual-license model.

### Contributing

This project uses a Contributor License Agreement so the maintainer can offer the
commercial license above. See [`CLA.md`](./CLA.md). By opening a pull request you
agree to its terms.
