import { describe, expect, it, vi } from "vitest";
import { commandFailure, createCommandSurface, defineCommand } from "./commands";
import type { AnyCommandSurface, CommandOf, DescriptorFor, StudioCommandSurface } from "./commands";

/**
 * The studio-commands executor (plans/studio-commands.md P0): a dummy studio
 * over a tiny draft round-trips a batch + undo; a failing batch leaves the
 * draft unchanged; one batch = one undo; read-only commands push no history.
 */

type Draft = { tokens: Record<string, string>; items: string[] };

type DummyCommand = CommandOf<{
  set_token: { name: string; value: string };
  add_item: { label: string };
  explode: Record<string, never>;
  get_draft: Record<string, never>;
}>;

const descriptors: DescriptorFor<DummyCommand>[] = [
  defineCommand<Extract<DummyCommand, { verb: "set_token" }>>({
    verb: "set_token",
    description: "Set one token.",
    inputSchema: {
      type: "object",
      required: ["name", "value"],
      properties: { name: { type: "string", pattern: "^[a-z-]+$" }, value: { type: "string", minLength: 1 } },
      additionalProperties: false,
    },
    readOnly: false,
    toCommand: (input) => ({ verb: "set_token", args: input as { name: string; value: string } }),
    summarize: (c) => `${c.args.name} → ${c.args.value}`,
  }),
  defineCommand<Extract<DummyCommand, { verb: "add_item" }>>({
    verb: "add_item",
    description: "Append an item.",
    inputSchema: { type: "object", required: ["label"], properties: { label: { type: "string" } } },
    readOnly: false,
    toCommand: (input) => ({ verb: "add_item", args: input as { label: string } }),
    summarize: (c) => `+ ${c.args.label}`,
  }),
  defineCommand<Extract<DummyCommand, { verb: "explode" }>>({
    verb: "explode",
    description: "Always throws.",
    inputSchema: { type: "object" },
    readOnly: false,
    toCommand: () => ({ verb: "explode", args: {} }),
    summarize: () => "boom",
  }),
  defineCommand<Extract<DummyCommand, { verb: "get_draft" }>>({
    verb: "get_draft",
    description: "Read the draft.",
    inputSchema: { type: "object", additionalProperties: false },
    readOnly: true,
    toCommand: () => ({ verb: "get_draft", args: {} }),
    summarize: () => "read draft",
  }),
];

function dummy(initial: Draft = { tokens: { primary: "#000" }, items: [] }) {
  let draft = initial;
  const setDraft = vi.fn((next: Draft) => {
    draft = next;
  });
  const surface = createCommandSurface<Draft, DummyCommand>({
    studio: "dummy",
    descriptors,
    getDraft: () => draft,
    setDraft,
    apply(d, cmd) {
      switch (cmd.verb) {
        case "set_token":
          // Mutates in place on purpose: the executor hands apply a COPY.
          d.tokens[cmd.args.name] = cmd.args.value;
          return d;
        case "add_item":
          if (d.items.includes(cmd.args.label)) return commandFailure(`duplicate item "${cmd.args.label}"`);
          return { ...d, items: [...d.items, cmd.args.label] };
        case "explode":
          throw new Error("kaboom");
        default:
          return d;
      }
    },
    read: (d) => d.items.length,
  });
  return { surface, setDraft, current: () => draft };
}

describe("studio command surface", () => {
  it("round-trips a batch and undoes it in one step", () => {
    const { surface, current } = dummy();
    const result = surface.execute([
      { verb: "set_token", args: { name: "primary", value: "#b33" } },
      { verb: "add_item", args: { label: "hero" } },
    ]);
    expect(result).toEqual({
      ok: true,
      draft: { tokens: { primary: "#b33" }, items: ["hero"] },
      summary: "primary → #b33\n+ hero",
      results: [undefined, undefined],
    });
    expect(surface.read()).toEqual({ tokens: { primary: "#b33" }, items: ["hero"] });
    expect(surface.undo()).toEqual({ ok: true, draft: { tokens: { primary: "#000" }, items: [] } });
    expect(current()).toEqual({ tokens: { primary: "#000" }, items: [] });
    expect(surface.undo()).toEqual({ ok: false, error: "NOTHING_TO_UNDO" });
  });

  it("refuses a batch with bad args, naming the command, and leaves the draft untouched", () => {
    const { surface, setDraft, current } = dummy();
    const before = current();
    const result = surface.execute([
      { verb: "set_token", args: { name: "primary", value: "#b33" } },
      { verb: "set_token", args: { name: "Bad Name", value: "" } },
    ]);
    expect(result).toEqual({
      ok: false,
      failedIndex: 1,
      verb: "set_token",
      code: "INVALID_ARGS",
      error: '$.name must match pattern "^[a-z-]+$"; $.value must be at least 1 characters, got 0',
      issues: [
        { path: "$.name", message: 'must match pattern "^[a-z-]+$"' },
        { path: "$.value", message: "must be at least 1 characters, got 0" },
      ],
    });
    expect(setDraft).not.toHaveBeenCalled();
    expect(current()).toBe(before);
    expect(before).toEqual({ tokens: { primary: "#000" }, items: [] });
    expect(surface.undo()).toEqual({ ok: false, error: "NOTHING_TO_UNDO" });
  });

  it("refuses a batch whose apply throws mid-way, even after an in-place mutation", () => {
    const { surface, setDraft, current } = dummy();
    const result = surface.execute([
      { verb: "set_token", args: { name: "primary", value: "#b33" } },
      { verb: "explode", args: {} },
      { verb: "add_item", args: { label: "never" } },
    ]);
    expect(result).toEqual({ ok: false, failedIndex: 1, verb: "explode", code: "APPLY_FAILED", error: "kaboom" });
    expect(setDraft).not.toHaveBeenCalled();
    expect(current()).toEqual({ tokens: { primary: "#000" }, items: [] });
  });

  it("treats a returned commandFailure like a throw", () => {
    const { surface, current } = dummy({ tokens: {}, items: ["hero"] });
    const result = surface.execute([{ verb: "add_item", args: { label: "hero" } }]);
    expect(result).toMatchObject({ ok: false, failedIndex: 0, code: "APPLY_FAILED", error: 'duplicate item "hero"' });
    expect(current()).toEqual({ tokens: {}, items: ["hero"] });
  });

  it("names an unknown verb and a malformed batch", () => {
    const { surface } = dummy();
    const unknown = surface.execute([{ verb: "publish", args: {} }] as unknown as DummyCommand[]);
    expect(unknown).toEqual({ ok: false, failedIndex: 0, verb: "publish", code: "UNKNOWN_VERB", error: 'unknown command "publish"' });
    const noVerb = surface.execute([{ args: {} }] as unknown as DummyCommand[]);
    expect(noVerb).toMatchObject({ failedIndex: 0, verb: "", code: "UNKNOWN_VERB" });
    const notArray = surface.execute("set_token" as unknown as DummyCommand[]);
    expect(notArray).toMatchObject({ ok: false, failedIndex: -1, code: "INVALID_BATCH" });
  });

  it("makes one undo entry per batch, however many commands", () => {
    const { surface, current } = dummy();
    surface.execute([{ verb: "add_item", args: { label: "a" } }]);
    surface.execute([
      { verb: "add_item", args: { label: "b" } },
      { verb: "add_item", args: { label: "c" } },
      { verb: "set_token", args: { name: "accent", value: "#0a0" } },
    ]);
    expect(current().items).toEqual(["a", "b", "c"]);
    surface.undo();
    expect(current()).toEqual({ tokens: { primary: "#000" }, items: ["a"] });
    surface.undo();
    expect(current()).toEqual({ tokens: { primary: "#000" }, items: [] });
    expect(surface.undo().ok).toBe(false);
  });

  it("answers read-only commands without committing or pushing history", () => {
    const { surface, setDraft, current } = dummy({ tokens: {}, items: ["x"] });
    const before = current();
    const result = surface.execute([{ verb: "get_draft", args: {} }]);
    expect(result).toEqual({ ok: true, draft: before, summary: "read draft", results: [1] });
    expect(setDraft).not.toHaveBeenCalled();
    expect(surface.undo()).toEqual({ ok: false, error: "NOTHING_TO_UNDO" });
    // A read inside a mutating batch sees the working draft.
    const mixed = surface.execute([
      { verb: "add_item", args: { label: "y" } },
      { verb: "get_draft", args: {} },
    ]);
    expect(mixed).toMatchObject({ ok: true, results: [undefined, 2] });
  });

  it("returns a copy from read()", () => {
    const { surface, current } = dummy();
    const copy = surface.read();
    copy.items.push("tamper");
    expect(current().items).toEqual([]);
  });

  it("uses injected history hooks", () => {
    const stack: Draft[] = [];
    let draft: Draft = { tokens: {}, items: [] };
    const surface = createCommandSurface<Draft, DummyCommand>({
      studio: "dummy",
      descriptors,
      getDraft: () => draft,
      setDraft: (d) => {
        draft = d;
      },
      apply: (d, cmd) => (cmd.verb === "add_item" ? { ...d, items: [...d.items, cmd.args.label] } : d),
      history: { push: (d) => stack.push(d), pop: () => stack.pop() },
    });
    surface.execute([{ verb: "add_item", args: { label: "a" } }]);
    expect(stack).toEqual([{ tokens: {}, items: [] }]);
    surface.undo();
    expect(stack).toEqual([]);
  });

  it("rejects duplicate verbs at construction", () => {
    expect(() =>
      createCommandSurface<Draft, DummyCommand>({
        studio: "dummy",
        descriptors: [descriptors[0], descriptors[0]],
        getDraft: () => ({ tokens: {}, items: [] }),
        setDraft: () => {},
        apply: (d) => d,
      }),
    ).toThrow('dummy: duplicate command verb "set_token"');
  });

  it("a typed surface is assignable to the loader's AnyCommandSurface", () => {
    const { surface } = dummy();
    const typed: StudioCommandSurface<Draft, DummyCommand> = surface;
    const any: AnyCommandSurface = typed;
    expect(any.descriptors.map((d) => d.verb)).toEqual(["set_token", "add_item", "explode", "get_draft"]);
  });
});
