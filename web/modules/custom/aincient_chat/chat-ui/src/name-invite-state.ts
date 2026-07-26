/**
 * When to ask the owner what to call them — a tiny pub/sub, deliberately timed.
 *
 * Onboarding used to open with "What should we call you?" as its first field.
 * That makes the very first thing Atelier ever shows a form, and it asks for
 * something personal before the product has done anything to deserve it. Study
 * 02 is explicit that names are EARNED, never scraped; being asked at the door
 * is a milder version of the same mistake.
 *
 * So the ask moved to the moment it's earned: the first time the studio actually
 * builds a page for you. The page agent's live preview is the signal — that is
 * the instant the owner has MADE something, which is where onboarding was always
 * meant to end.
 *
 * Three conditions gate it, all of which must hold:
 *   1. The owner has no earned name yet (`viewer.name` is '').
 *   2. A LIVE page preview has applied this session — never a historical card
 *      replayed from a reloaded thread, which would fire on every revisit of an
 *      old conversation and turn the invite into a haunting.
 *   3. It hasn't been dismissed or answered before, on any earlier visit.
 *
 * Condition 3 persists in localStorage rather than on the server: getting it
 * wrong costs one redundant question, and that isn't worth a schema, a write
 * endpoint, or a round trip on a screen whose whole point is to stay out of the
 * way. Cleared storage means it may ask once more — an acceptable trade for a
 * question that is optional anyway.
 */

/** Set once the invite has been answered or waved off — never ask again. */
const SETTLED_KEY = "ain-name-invite-settled";

let made = false;
const subscribers = new Set<() => void>();

function settled(): boolean {
  try {
    return localStorage.getItem(SETTLED_KEY) === "1";
  } catch {
    // Private mode: treat as unsettled. The invite is dismissible in-session, so
    // the worst case is being asked again next visit — never a stuck banner.
    return false;
  }
}

/**
 * The studio just built something live — the earliest honest moment to ask.
 *
 * Called from the page preview tool on a real (non-historical) apply. Idempotent
 * and one-way: the first build of a session flips it, later ones are no-ops.
 */
export function markSomethingMade(): void {
  if (made) return;
  made = true;
  for (const fn of subscribers) fn();
}

/** Stop asking, permanently — whether they answered or waved it off. */
export function settleNameInvite(): void {
  try {
    localStorage.setItem(SETTLED_KEY, "1");
  } catch {
    /* private mode — in-session dismissal still hides it */
  }
  made = false;
  for (const fn of subscribers) fn();
}

/** True when all three gates are open and the invite should be on screen. */
export function shouldInviteName(hasName: boolean): boolean {
  return made && !hasName && !settled();
}

/** Subscribe to invite-worthiness changes; returns an unsubscribe. */
export function subscribeNameInvite(fn: () => void): () => void {
  subscribers.add(fn);
  return () => subscribers.delete(fn);
}

/** Test seam — forget this session's "something was made" signal. */
export function resetNameInviteForTests(): void {
  made = false;
  try {
    localStorage.removeItem(SETTLED_KEY);
  } catch {
    /* nothing to clear */
  }
  subscribers.clear();
}
