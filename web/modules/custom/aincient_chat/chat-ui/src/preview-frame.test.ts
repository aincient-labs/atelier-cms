// @vitest-environment jsdom
import { afterEach, describe, expect, it, vi } from "vitest";
import { makeHttpAdapter } from "./adapter";
import { getBrandOverrides, resetBrandOverrides } from "./brand-state";

/**
 * A live preview repaint must move the preview WITHOUT adding a transcript card.
 *
 * The brand studio streams two kinds of `brand_preview` payload per turn: each
 * specialist's slice as it completes (so the preview animates instead of sitting
 * frozen for two minutes), and the merge node's authoritative envelope at
 * end-of-turn. Both used to arrive as `tool_call`, and the adapter mints a fresh
 * card per `tool_call` — so a one-specialist turn rendered TWO identical
 * "Applied to preview · 6 changes" cards while exactly ONE was persisted. Live
 * and reloaded views disagreed about the same turn.
 *
 * The fix is channel separation (DECISIONS 0381): the transient repaint is its
 * own event type. This pins both halves of that contract — the `preview` frame
 * applies and renders nothing, the `tool_call` frame renders — because either
 * half alone is a bug. A `preview` that didn't apply would freeze the preview;
 * a `preview` that rendered would put the duplicate straight back.
 */

afterEach(() => {
  resetBrandOverrides();
  vi.unstubAllGlobals();
});

/** Feed the adapter a scripted SSE stream. */
function stubStream(frames: { type: string; data: Record<string, unknown> }[]): void {
  const body = frames
    .map((f) => `event: ${f.type}\ndata: ${JSON.stringify(f.data)}\n\n`)
    .join("");
  vi.stubGlobal(
    "fetch",
    vi.fn(async () => new Response(new TextEncoder().encode(body), { status: 200 })),
  );
}

/** Run one turn and return the last content snapshot the adapter yielded. */
async function runTurn(): Promise<readonly { type: string }[]> {
  const adapter = makeHttpAdapter(async () => "thr_test");
  let last: readonly { type: string }[] = [];
  for await (const chunk of adapter.run({
    messages: [{ role: "user", content: [{ type: "text", text: "warmer" }] }],
    abortSignal: new AbortController().signal,
  } as never) as AsyncGenerator<{ content: readonly { type: string }[] }>) {
    last = chunk.content ?? last;
  }
  return last;
}

describe("live preview frames", () => {
  it("applies a preview frame to the draft and renders no card", async () => {
    stubStream([
      { type: "preview", data: { name: "brand_preview", arguments: { tokens: { "brand-primary": "oklch(0.58 0.18 40)" } } } },
      { type: "result", data: { text: "Warmed the palette up." } },
      { type: "done", data: {} },
    ]);

    const content = await runTurn();

    expect(getBrandOverrides()["brand-primary"]).toBe("oklch(0.58 0.18 40)");
    expect(content.filter((p) => p.type === "tool-call")).toHaveLength(0);
  });

  it("still renders a card for the authoritative tool_call frame", async () => {
    stubStream([
      { type: "preview", data: { name: "brand_preview", arguments: { tokens: { "brand-primary": "oklch(0.58 0.18 40)" } } } },
      { type: "tool_call", data: { name: "brand_preview", arguments: { tokens: { "brand-primary": "oklch(0.58 0.18 40)" } } } },
      { type: "result", data: { text: "Warmed the palette up." } },
      { type: "done", data: {} },
    ]);

    const content = await runTurn();

    // One card for the turn — the persisted one — not one per producer.
    expect(content.filter((p) => p.type === "tool-call")).toHaveLength(1);
  });
});
