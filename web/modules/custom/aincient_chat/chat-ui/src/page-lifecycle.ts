import type { Moderation, Transition } from "./page-state";

/**
 * How the lifecycle bar orders a page's transitions (DECISIONS 0454).
 *
 * A site can reshape content_moderation, so the console never keys the layout
 * off a transition id beyond the two that WRITE the page (`publish` is the
 * save+go-live path, `create_new_draft` is Save draft). Everything else is
 * sorted by what the workflow config already declares — each transition's
 * weight and its target state's weight / published / default-revision flags —
 * into one primary and three menu groups:
 *
 *   primary   the go-live transition (target published), else the next step
 *             forward (target heavier than the current state), else none.
 *             A live page with unsaved edits re-publishes them (no state change).
 *   forward   other transitions to a heavier state, or to a published one.
 *   back      transitions to a lighter state (Reject, Restore, Request changes).
 *   offline   a target that is the default revision but NOT published (Archive,
 *             Unpublish): it changes what visitors see, so it goes last and
 *             confirms first.
 *
 * Self-loops (a re-submit) and `create_new_draft` are folded into Save draft and
 * never listed. Pure — the bar renders from it; the tests pin the rules.
 */

export type LifecyclePrimary =
  /** Save the schema and go live (`/publish`): a new page, the `publish`
   *  transition, or a live page re-publishing unsaved edits (`transition` null). */
  | { kind: "publish"; transition: Transition | null }
  /** Run a pure transition (Approve, Submit for review, a site's own). */
  | { kind: "transition"; transition: Transition };

export type LifecyclePlan = {
  primary: LifecyclePrimary | null;
  forward: Transition[];
  back: Transition[];
  offline: Transition[];
};

/** The transitions that write the page instead of only moving its state. */
const WRITE_TRANSITIONS = new Set(["publish", "create_new_draft"]);

const byWeight = (a: Transition, b: Transition) => (a.weight ?? 0) - (b.weight ?? 0);

const takesOffline = (t: Transition) => t.to_default_revision === true && t.to_published !== true;

export function planLifecycle(m: Moderation, { isNew = false, dirty = false }: { isNew?: boolean; dirty?: boolean } = {}): LifecyclePlan {
  // A never-saved draft has no node and so no transitions: its one action is
  // the first Publish (which creates the node).
  if (isNew) return { primary: { kind: "publish", transition: null }, forward: [], back: [], offline: [] };

  const listed = m.transitions.filter((t) => t.to !== m.state && t.id !== "create_new_draft");
  const offline = listed.filter(takesOffline).sort(byWeight);
  const moving = listed.filter((t) => !takesOffline(t));
  const heavier = (t: Transition) => (t.to_weight ?? 0) > m.stateWeight;

  const goLive = moving.filter((t) => t.to_published === true).sort(byWeight)[0];
  const nextStep = moving.filter(heavier).sort(byWeight)[0];

  let primary: LifecyclePrimary | null = null;
  if (goLive) {
    primary = goLive.id === "publish" ? { kind: "publish", transition: goLive } : { kind: "transition", transition: goLive };
  } else if (m.statePublished && dirty && m.canEdit) {
    primary = { kind: "publish", transition: null };
  } else if (nextStep) {
    primary = { kind: "transition", transition: nextStep };
  }

  const rest = moving.filter((t) => t !== primary?.transition && !WRITE_TRANSITIONS.has(t.id));
  const forward = rest.filter((t) => heavier(t) || t.to_published === true).sort(byWeight);
  const back = rest.filter((t) => !forward.includes(t)).sort(byWeight);
  return { primary, forward, back, offline };
}

/** Whether a transition should carry the unsaved edits with it: one that moves
 *  the page FORWARD (to review, or live) saves first, so the next person sees
 *  the edits. Sending back or taking offline never writes the draft.
 *
 *  Only FROM Draft: Save draft always writes a `draft` revision, so saving on a
 *  page in review would first move it back to Draft and the transition would
 *  then be refused from the wrong state. Elsewhere the edits stay unsaved. */
export function carriesEdits(plan: LifecyclePlan, t: Transition, m: Moderation): boolean {
  return m.state === "draft" && (plan.primary?.transition === t || plan.forward.includes(t));
}

/** The shipped transitions' success lines; a site's own say where the page went. */
const TRANSITION_NOTICE: Record<string, string> = {
  submit_for_review: "Sent for review",
  reject: "Sent back to draft",
  archive: "Archived — taken off the live site",
  restore: "Restored to draft",
};

/** The transient line a studio shows after a pure transition that didn't go live. */
export function transitionNotice(t: Transition): string {
  return TRANSITION_NOTICE[t.id] ?? `Moved to ${t.to_label}`;
}
