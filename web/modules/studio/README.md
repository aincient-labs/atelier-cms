# `web/modules/studio/` — Atelier's studio modules

The second of our two code tiers (plans/studio-modules.md, DECISIONS 0430):

    web/modules/custom/   OUR CORE — what the product needs with every studio switched off
    web/modules/studio/   OUR STUDIOS — surfaces on top of core (this directory)

A studio is a Drupal module (`aincient_studio_<x>` for a new one; a module that
already owns an entity type keeps its name, DECISIONS 0433) that owns everything it ships: a
`<module>.studios.yml` manifest, its PHP (entities, services, its own capabilities),
its React UI source under `ui/` (compiled by the ONE console build in
`aincient_chat/chat-ui`), its FlowDrop agents as `config/install`, and tagged demo
content. It depends on core and never on another studio. It is switched on/off by
config (`aincient_chat.settings:disabled_studios`), never uninstalled.

Both tiers are ours, in our tree and under our gate. This is not a pack
(`web/modules/packs/`) and not a public contract.

The appliance image `COPY`s the tier as its own layer and the test gate scans it.

First resident: `aincient_studio_components/` — the Components studio (Phase B,
DECISIONS 0431). Use it as the worked example of a studio module: manifest with a
`ui:` entry, PHP routes gated by the derived permission alone, UI that imports only
`@console/kit` / `@console/sdk`, its own `ui/styles.css` prefixed `.ain-components-`,
kernel tests under `tests/`.

Second resident: `aincient_studio_media/` — the Library family (Phase E): two studio
ids in one manifest (`library` permission-only, `media` with the UI), an agent shipped
as `config/install` and mirrored from `config/sync` under a drift test, owned
capabilities, routes re-gated to the studio's permission, and the first `ToolUIs`
export (a studio's chat widgets, mounted by the console).

Third resident: `aincient_audit/` — the Checks studio (Phase E, DECISIONS 0433): a
whole existing module moved tiers under its historical machine name (it owns the
`aincient_policy` config entity type and the `aincient_policy_revision` table —
renaming an installed entity provider is a data migration, not a folder move). It
brought the `policy_check` FlowDrop node processor with it from `aincient_flows` (the
core→studio dependency the tier guard forbids), ships three flows as `config/install`
under one drift test, and its rail reaches the shared page preview and page-state
draft through `@console/sdk`.

Fourth resident: `aincient_studio_site/` — the Site studios (Phase E.3, DECISIONS 0434):
two frozen ids with their OWN UI entry files in one module (`globals` → `ui/index.tsx`,
`settings` → `ui/settings.tsx`; the manifest points each id at its file and the
generated registry imports both), one stylesheet, the chrome agent as `config/install`
under a drift test, one owned verb (`preview_chrome`, with the applier it validates
with), and four action routes taken over from a core module (`aincient_export`) under
their original paths — the pattern for "a core module holds a studio's write routes".

Fifth resident: `aincient_studio_content/` — the Content studio (Phase E.4, DECISIONS 0435):
the largest built-in so far. Its two controllers (pages, global blocks — 22 routes under
their original `/atelier/page/*` + `/atelier/block/*` paths, one stamped by hand because it
carries its own governance permission) moved out of `aincient_pages` while the stores they
call stayed core; its agent ships as `config/install` under a drift test; its ONE owned verb
is `preview_page`, the two shared verbs it also calls (`list_pages`, `find_reference`) stay
core because other agents call them. Its `Preview` is NOT a file of the module: the page
preview is shared with Checks, so it stays core and both studios reach it through
`@console/sdk` — the pattern for any canvas two studios render.

Sixth and last resident: `aincient_brand/` — the Identity studio (Phase E.5, DECISIONS 0436):
a core module moved tiers whole under its historical name (the 0433 pattern — its six
`aincient_brand:*` capability ids are in every flow and test, and nothing is gained by renaming
them). The three `/atelier/brand/*` write routes and `BrandController` came over from
`aincient_pages` (the no-AI admin form and the revision history stay core); the brand agent plus
its three specialist flows and their executor node types ship as `config/install` under a 7-file
drift test; the rail, the token showcase and five chat cards live in `ui/`, prefixed
`.ain-design-system-` (the id's kebab form, which the CSS rule accepts). With it the last
`#[Studio]` attribute plugin went: the manager discovers manifests only, and General — the one
studio with no module to live in — is the manager's own definition.

With every former built-in a module, `web/modules/custom/aincient_chat` ships no studio of its
own except General, and the frozen ids (0425) are asserted by each module's kernel test.
