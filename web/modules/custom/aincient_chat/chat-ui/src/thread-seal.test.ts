import { beforeEach, describe, expect, it } from "vitest";
import {
  isThreadSealed,
  rememberThreadSeal,
  resetSealsForTests,
  sealVersion,
  subscribeSeals,
} from "./thread-seal";

/**
 * "Is the conversation I'm looking at wrapped up?" has TWO inputs: which thread
 * is active, and which threads are sealed. The seal store owns only the second —
 * it emits on seal *writes* and has no idea a thread switch happened.
 *
 * That asymmetry produced issue #10: Publish → "Start a new thread" seals the
 * old thread (emit → sealed = true) and *then* switches to a fresh one. The
 * switch emits nothing, so a consumer that CACHED the verdict in local state fed
 * by a `subscribeSeals` effect kept `true` under a brand-new, unsealed thread —
 * and the page went permanently unsaveable (Discard / Save draft / Publish all
 * dead, only Archive live).
 *
 * These tests pin both halves: the store's per-thread truth, and the derivation
 * shape (`useMemo` over [threadId, sealVersion]) that consumers must use so a
 * thread switch re-derives by construction. The node test env has no DOM, so the
 * hook's dependency semantics are modelled by {@link memoized} rather than
 * rendered — what's under test is the DEP LIST, which is where the bug lived.
 */

/**
 * A stand-in for `useMemo`: recomputes only when one of its deps changes, the
 * same contract React gives `useThreadSealed`. Reports how often it recomputed
 * so "the switch re-derived" is an assertion, not an assumption.
 */
function memoized<T>(compute: (deps: readonly unknown[]) => T) {
  let last: readonly unknown[] | null = null;
  let value: T;
  let computations = 0;
  return {
    read(deps: readonly unknown[]): T {
      if (last === null || deps.length !== last.length || deps.some((d, i) => d !== last![i])) {
        last = deps;
        value = compute(deps);
        computations++;
      }
      return value;
    },
    get computations() {
      return computations;
    },
  };
}

describe("thread seal store", () => {
  beforeEach(() => {
    resetSealsForTests();
  });

  it("seals one thread without sealing any other", () => {
    rememberThreadSeal("thread-a", true, { url: "/about", node: "12" });

    expect(isThreadSealed("thread-a")).toBe(true);
    // Never recorded — an unknown thread is live, not sealed.
    expect(isThreadSealed("thread-b")).toBe(false);
    // A fresh, unsent thread has no backend id at all.
    expect(isThreadSealed(undefined)).toBe(false);
    expect(isThreadSealed("")).toBe(false);
  });

  it("bumps the version on real changes only", () => {
    let emits = 0;
    const stop = subscribeSeals(() => emits++);

    rememberThreadSeal("thread-a", true);
    expect(emits).toBe(1);
    expect(sealVersion()).toBe(1);

    // Re-recording the same verdict is not a change — no emit, no version bump,
    // so memoized consumers don't churn.
    rememberThreadSeal("thread-a", true);
    expect(emits).toBe(1);
    expect(sealVersion()).toBe(1);

    rememberThreadSeal("thread-b", true);
    expect(emits).toBe(2);
    expect(sealVersion()).toBe(2);

    stop();
  });
});

describe("active-thread sealed derivation (issue #10)", () => {
  beforeEach(() => {
    resetSealsForTests();
  });

  it("reads false on a fresh thread opened right after sealing the old one", () => {
    // The wrap-up commit, in order: seal A locally, then switch to B.
    let activeThread = "thread-a";
    rememberThreadSeal("thread-a", true, { url: "/about" });

    const sealed = memoized((deps) => isThreadSealed(deps[0] as string));
    // While still on A the studio is correctly frozen.
    expect(sealed.read([activeThread, sealVersion()])).toBe(true);

    // consoleNav.seal() switches to a brand-new thread. The seal store emits
    // NOTHING for this — sealVersion() is unchanged — so the thread id alone has
    // to invalidate the verdict.
    const versionAtSwitch = sealVersion();
    activeThread = "thread-b";
    expect(sealed.read([activeThread, versionAtSwitch])).toBe(false);
    expect(sealed.computations).toBe(2);

    // …and the thread we wrapped up is still history, not resurrected.
    expect(isThreadSealed("thread-a")).toBe(true);
  });

  it("stays stale when the verdict is cached against seal emits alone", () => {
    // The regression's shape, kept as a negative control: an effect subscribed
    // only to `subscribeSeals` never runs on a thread switch, so its cached
    // `true` survives onto the new thread. Any consumer that reintroduces this
    // subscribe-to-one-of-two-inputs pattern reproduces issue #10.
    let activeThread = "thread-a";
    let cached = false;
    const stop = subscribeSeals(() => {
      cached = isThreadSealed(activeThread);
    });

    rememberThreadSeal("thread-a", true);
    expect(cached).toBe(true);

    activeThread = "thread-b";
    // No emit fired: the cache is now wrong about an unsealed thread. The derived
    // shape above gets this right; this one is why we don't cache.
    expect(cached).toBe(true);
    expect(isThreadSealed(activeThread)).toBe(false);

    stop();
  });

  it("re-derives when a seal lands on the thread already being viewed", () => {
    // The other direction: the id is stable, the store moves. Publishing from
    // the thread you're in must freeze the studio without a remount.
    const activeThread = "thread-a";
    const sealed = memoized((deps) => isThreadSealed(deps[0] as string));

    expect(sealed.read([activeThread, sealVersion()])).toBe(false);
    rememberThreadSeal("thread-a", true);
    expect(sealed.read([activeThread, sealVersion()])).toBe(true);
    expect(sealed.computations).toBe(2);
  });
});
