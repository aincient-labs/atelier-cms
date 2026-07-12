import { settings } from "./adapter";
import type { WorkflowRef } from "./flow";

/**
 * The studio data layer (no React) — reads the server's studio → agents catalog
 * from `window.aincientChat.studios` and answers the questions the console asks:
 * which studios are enabled, which agents each runs, which studio owns an agent,
 * and which studio is the default.
 *
 * The console is a workspace switcher: it is always in exactly one studio, and
 * everything (history, the new-chat agent, the editor pane) is scoped to it. The
 * SET of studios is code-owned — the front-end component registry lives in
 * `studio-registry.tsx`, the backend mirror is the `Studio` PHP enum. This module
 * is the bridge: the server tells us which studios are enabled and their agents,
 * keyed by the same studio key the registry and the enum share.
 */

export type StudioKey = string;

/** One studio's server catalog: its agents + the default a new chat pins. */
export type StudioCatalog = {
  /** The agent id a new conversation in this studio runs. */
  default: string;
  /** The agents (FlowDrop workflows) this studio can run, with welcome metadata. */
  agents: WorkflowRef[];
};

/** The studio → catalog map the shell injected (empty when FlowDrop is absent). */
function catalog(): Record<StudioKey, StudioCatalog> {
  return settings().studios ?? {};
}

/** The studio keys the server enabled (those with valid agents), unordered. */
export function enabledStudioKeys(): StudioKey[] {
  return Object.keys(catalog());
}

/** Whether a studio is enabled (present in the server catalog). */
export function isStudioEnabled(key: StudioKey | undefined): boolean {
  return key !== undefined && key in catalog();
}

/**
 * The set of studio keys this user may enter (the server's `studioAccess`), or
 * NULL when the shell sent no list (older shell ⇒ no per-studio gate). The
 * authoritative access gate, separate from the agent catalog: it also covers
 * editor-only studios (Globals) that aren't in `studios`.
 */
function studioAccess(): Set<StudioKey> | null {
  const a = settings().studioAccess;
  return Array.isArray(a) ? new Set(a) : null;
}

/** Whether the user is permitted to enter a studio (no list ⇒ permitted). */
export function isStudioAccessible(key: StudioKey | undefined): boolean {
  if (key === undefined) return false;
  const access = studioAccess();
  return access === null || access.has(key);
}

/** The agents a studio can run (empty for an unknown/disabled studio). */
export function agentsForStudio(key: StudioKey | undefined): WorkflowRef[] {
  return key ? catalog()[key]?.agents ?? [] : [];
}

/** The default agent id a new chat in this studio pins (undefined if none). */
export function studioDefaultAgent(key: StudioKey | undefined): string | undefined {
  if (!key) return undefined;
  const entry = catalog()[key];
  return entry?.default || entry?.agents[0]?.id;
}

/**
 * Which studio owns an agent id, or undefined if none does.
 *
 * Deterministic — an agent belongs to at most one studio (enforced server-side),
 * so the first match is the only match.
 */
export function studioOfAgent(agentId: string | undefined): StudioKey | undefined {
  if (!agentId) return undefined;
  const all = catalog();
  for (const key of Object.keys(all)) {
    if (all[key].agents.some((a) => a.id === agentId)) return key;
  }
  return undefined;
}

/** The studio a fresh console session opens in. */
export function serverDefaultStudio(): StudioKey {
  const def = settings().defaultStudio;
  if (isStudioEnabled(def)) return def as StudioKey;
  return enabledStudioKeys()[0] ?? "general";
}

/** Every agent across all studios (the flat lookup the send adapter needs). */
export function allAgents(): WorkflowRef[] {
  return Object.values(catalog()).flatMap((s) => s.agents);
}

/* ----------------------------------------------------------- URL flow slugs */

/** Lowercase a string into a url-safe slug (alnum runs joined by hyphens). */
function slugify(s: string): string {
  return s.toLowerCase().replace(/[^a-z0-9]+/g, "-").replace(/^-+|-+$/g, "");
}

/**
 * The URL slug for a studio key — the studio's segment in the room-primary path
 * (`design_system` → `design-system`; single-word keys pass through). The inverse
 * of {@link studioFromSlug}. Underscores become hyphens so the path reads cleanly.
 */
export function studioSlug(key: StudioKey): string {
  return key.replace(/_/g, "-");
}

/**
 * Resolve a `<studio>` path segment back to a studio key. Forgiving (a shared
 * link should land even if the slug drifts): matches the raw key or the
 * hyphenated {@link studioSlug}, and only among studios the user may enter.
 * Undefined when nothing matches — callers fall back to {@link serverDefaultStudio}
 * rather than 404, since this is a client-routed SPA.
 */
export function studioFromSlug(slug: string): StudioKey | undefined {
  const key = slug.replace(/-/g, "_");
  return isStudioAccessible(key) ? key : undefined;
}

/**
 * The canonical URL slug for an agent id, used in /aincient/<category>/<flow>.
 * Strips the `aincient_` namespace and any trailing `_agent`/`_loop` role
 * suffixes so the path reads cleanly (aincient_brand_agent → "brand",
 * aincient_pages_agent → "pages", aincient_operator_agent_loop → "operator").
 */
export function flowSlug(agentId: string): string {
  return slugify(agentId.replace(/^aincient_/, "").replace(/(_(agent|loop))+$/, ""));
}

/**
 * Resolve a `<flow>` path segment to an agent id within a studio. Forgiving by
 * design — shared links should land in the right place even if the slug drifts:
 * matches the raw id, the canonical {@link flowSlug}, or a slug of the label.
 * Returns undefined when nothing matches (callers fall back to the studio
 * default rather than 404, since this is a client-routed SPA).
 */
export function agentInStudioBySlug(
  key: StudioKey | undefined,
  flow: string,
): string | undefined {
  const want = slugify(flow);
  const hit = agentsForStudio(key).find(
    (a) => a.id === flow || flowSlug(a.id) === want || slugify(a.label) === want,
  );
  return hit?.id;
}

/** The catalog entry for an agent id, across all studios. */
export function findAgent(agentId: string | undefined): WorkflowRef | undefined {
  return agentId ? allAgents().find((a) => a.id === agentId) : undefined;
}

/**
 * Where "open this page" lands, resolved from the workspace it's invoked in.
 * `list_pages` emits a studio-agnostic open-page intent (just a node id); the
 * console calls this to turn it into the right deep link for the ACTIVE studio,
 * so the same page table does the right thing in every studio:
 *
 *   checks  → the Checks audit room (/aincient/checks/node/<nid>) — opens that
 *             page's read-only health report.
 *   else    → the Content page node room (/aincient/content/node/<nid>) — the
 *             "edit this page" entry point the page agent / operator share.
 *
 * Room-primary paths (D3): the open document IS the room, so there is no ?page=/
 * ?audit= query — the node id rides in the path. Pure (no flow-store read) so it
 * stays a leaf util: the caller passes the active studio and the route base. New
 * studios that list pages extend this one switch rather than re-deriving URLs.
 */
export function pageDeepLink(studio: StudioKey | undefined, node: string, base: string): string {
  const id = encodeURIComponent(node);
  if (studio === "checks") return `${base}/checks/node/${id}`;
  return `${base}/content/node/${id}`;
}
