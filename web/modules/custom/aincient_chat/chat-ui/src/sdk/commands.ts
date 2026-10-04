import { validateArgs } from "./validate";
import type { JsonSchema, ValidationIssue } from "./validate";

/**
 * Studio commands — the contract (plans/studio-commands.md P0, DECISIONS 0447).
 *
 * Unlike the rest of the sdk (re-exports of console files), this is sdk-native:
 * the contract exists FOR studios, so it lives where they import it from.
 *
 * A studio declares a typed command set over its own DRAFT and runs it through
 * ONE executor ({@link createCommandSurface}). Every front end — the server
 * agent's preview frames, WebMCP, a dev text console — is an adapter onto the
 * resulting {@link StudioCommandSurface}. Transport-free on purpose: nothing
 * here touches the DOM, `fetch` or `modelContext`.
 *
 * The rules this file keeps (the plan's 1–4):
 *   1. Draft-only — a surface has no publish/save; nothing here persists.
 *   2. One batch = one undo; a batch that fails anywhere leaves the draft
 *      unchanged and names the failing command.
 *   3. The server stays the authority on values — arg validation here is UX.
 *   4. Commands carry intent; the studio's `apply` resolves the rest.
 *
 * A studio's `ui/` entry exports its surface as `Commands` (beside `ToolUIs`);
 * the studio loader collects it by studio id.
 */

/** One command: a verb plus its arguments. A studio's set is a union discriminated by `verb`. */
export type Command<V extends string = string, A = unknown> = { verb: V; args: A };

/**
 * A command union from a verb → args map:
 * `CommandOf<{ set_tokens: { tokens: Record<string, string> }; reset: {} }>`.
 */
export type CommandOf<M extends Record<string, unknown>> = {
  [V in keyof M & string]: Command<V, M[V]>;
}[keyof M & string];

/** How one verb is described to every adapter. Same shape as FlowDrop's `webmcp/descriptors.ts`. */
export type CommandDescriptor<C extends Command = Command> = {
  verb: C["verb"];
  /** One sentence for a tool catalogue — what the command does to the draft. */
  description: string;
  /** Schema for `args` (the keyword subset in `./validate`). */
  inputSchema: JsonSchema;
  /** Reads the draft, never changes it: no undo entry, never refused by a lock. */
  readOnly: boolean;
  /** Validated tool input → the command (an adapter's way in). Its `args` must satisfy `inputSchema`. */
  toCommand(input: Record<string, unknown>): C;
  /** One human line for the batch summary ("primary → #b33"). */
  summarize(command: C): string;
};

/** A surface's descriptor list: one descriptor per verb of `Cmd`, each typed to its own command. */
export type DescriptorFor<Cmd extends Command> = {
  [V in Cmd["verb"]]: CommandDescriptor<Extract<Cmd, { verb: V }>>;
}[Cmd["verb"]];

export type CommandErrorCode = "INVALID_BATCH" | "UNKNOWN_VERB" | "INVALID_ARGS" | "APPLY_FAILED";

export type ExecuteResult<Draft> =
  | {
      ok: true;
      /** The committed draft (unchanged by a read-only batch). */
      draft: Draft;
      /** The batch's summary lines, one per command, joined by newlines. */
      summary: string;
      /** Per command: what `read` returned for a read-only one, undefined otherwise. */
      results: unknown[];
    }
  | {
      ok: false;
      /** Index of the command that failed; -1 when the batch itself is malformed. */
      failedIndex: number;
      verb: string;
      code: CommandErrorCode;
      error: string;
      /** The arg-validation issues, for `INVALID_ARGS`. */
      issues?: ValidationIssue[];
    };

export type UndoResult<Draft> = { ok: true; draft: Draft } | { ok: false; error: "NOTHING_TO_UNDO" };

/** What a studio exposes: its descriptors and the one executor over its draft. */
export type StudioCommandSurface<Draft = unknown, Cmd extends Command = Command> = {
  /** The studio id (`design_system`, `content`, …) — also the adapters' tool prefix. */
  studio: string;
  descriptors: readonly DescriptorFor<Cmd>[];
  /** Validates and applies a batch atomically: all of it, as one undo entry, or none of it. */
  execute(batch: readonly Cmd[]): ExecuteResult<Draft>;
  /** Restores the draft from before the last mutating batch. */
  undo(): UndoResult<Draft>;
  /** The current draft (a copy — mutating it changes nothing). */
  read(): Draft;
};

/** Any studio's surface, as the loader holds it. */
export type AnyCommandSurface = StudioCommandSurface<unknown, Command>;

const FAILURE = Symbol("aincient.commandFailure");

/** A structured refusal an `apply` may return instead of throwing. */
export type CommandFailure = { readonly [FAILURE]: true; message: string };

/** Builds a {@link CommandFailure}: `return commandFailure("no such section")`. */
export function commandFailure(message: string): CommandFailure {
  return { [FAILURE]: true, message };
}

function isFailure(value: unknown): value is CommandFailure {
  return typeof value === "object" && value !== null && (value as Record<symbol, unknown>)[FAILURE] === true;
}

/** Where a surface keeps its undo stack. The default is an in-memory stack. */
export type CommandHistory<Draft> = {
  push(draft: Draft): void;
  pop(): Draft | undefined;
};

export type CommandSurfaceOptions<Draft, Cmd extends Command> = {
  studio: string;
  descriptors: readonly DescriptorFor<Cmd>[];
  getDraft(): Draft;
  setDraft(draft: Draft): void;
  /**
   * Pure: the draft after one mutating command. Return a {@link CommandFailure}
   * or throw to refuse; either fails the whole batch.
   */
  apply(draft: Draft, command: Cmd): Draft | CommandFailure;
  /** Answers a read-only command against the (working) draft. Absent → `undefined`. */
  read?(draft: Draft, command: Cmd): unknown;
  /** Copies a draft. Default `structuredClone`. */
  clone?(draft: Draft): Draft;
  /** Undo storage. Default: an in-memory stack capped at `historyLimit`. */
  history?: CommandHistory<Draft>;
  /** Cap for the default stack (oldest dropped first). Default 50. */
  historyLimit?: number;
};

/** The default undo stack. */
export function createMemoryHistory<Draft>(limit = 50): CommandHistory<Draft> {
  const stack: Draft[] = [];
  return {
    push(draft) {
      stack.push(draft);
      if (stack.length > limit) stack.shift();
    },
    pop() {
      return stack.pop();
    },
  };
}

/** Types one descriptor against its command (inference helper; returns its argument). */
export function defineCommand<C extends Command>(descriptor: CommandDescriptor<C>): CommandDescriptor<C> {
  return descriptor;
}

function messageOf(err: unknown): string {
  if (err instanceof Error) return err.message;
  return typeof err === "string" ? err : "command failed";
}

/**
 * Builds a studio's surface from a pure `apply` and its draft store. The
 * executor validates every command's `args`, applies the batch to a copy, and
 * commits (one `setDraft`, one history entry) only if every command succeeded.
 */
export function createCommandSurface<Draft, Cmd extends Command>(
  options: CommandSurfaceOptions<Draft, Cmd>,
): StudioCommandSurface<Draft, Cmd> {
  const clone = options.clone ?? ((draft: Draft) => structuredClone(draft));
  const history = options.history ?? createMemoryHistory<Draft>(options.historyLimit);
  const byVerb = new Map<string, CommandDescriptor<Cmd>>();
  for (const descriptor of options.descriptors as readonly CommandDescriptor<Cmd>[]) {
    if (byVerb.has(descriptor.verb)) throw new Error(`${options.studio}: duplicate command verb "${descriptor.verb}"`);
    byVerb.set(descriptor.verb, descriptor);
  }

  function execute(batch: readonly Cmd[]): ExecuteResult<Draft> {
    if (!Array.isArray(batch)) {
      return { ok: false, failedIndex: -1, verb: "", code: "INVALID_BATCH", error: "batch must be an array of commands" };
    }
    const before = options.getDraft();
    let working = clone(before);
    let mutated = false;
    const lines: string[] = [];
    const results: unknown[] = [];

    for (let i = 0; i < batch.length; i++) {
      const command: unknown = batch[i];
      const verb =
        typeof command === "object" && command !== null && typeof (command as { verb?: unknown }).verb === "string"
          ? (command as { verb: string }).verb
          : "";
      const fail = (code: CommandErrorCode, error: string, issues?: ValidationIssue[]): ExecuteResult<Draft> => ({
        ok: false,
        failedIndex: i,
        verb,
        code,
        error,
        ...(issues ? { issues } : {}),
      });

      const descriptor = byVerb.get(verb);
      if (!descriptor) {
        return fail("UNKNOWN_VERB", verb === "" ? "command has no verb" : `unknown command "${verb}"`);
      }
      const cmd = command as Cmd;
      const issues = validateArgs(descriptor.inputSchema, cmd.args);
      if (issues.length > 0) {
        return fail("INVALID_ARGS", issues.map((issue) => `${issue.path} ${issue.message}`).join("; "), issues);
      }

      try {
        if (descriptor.readOnly) {
          results.push(options.read ? options.read(working, cmd) : undefined);
        } else {
          const next = options.apply(working, cmd);
          if (isFailure(next)) return fail("APPLY_FAILED", next.message);
          working = next;
          mutated = true;
          results.push(undefined);
        }
      } catch (err) {
        return fail("APPLY_FAILED", messageOf(err));
      }
      lines.push(descriptor.summarize(cmd));
    }

    if (mutated) {
      history.push(before);
      options.setDraft(working);
      return { ok: true, draft: working, summary: lines.join("\n"), results };
    }
    return { ok: true, draft: before, summary: lines.join("\n"), results };
  }

  function undo(): UndoResult<Draft> {
    const previous = history.pop();
    if (previous === undefined) return { ok: false, error: "NOTHING_TO_UNDO" };
    options.setDraft(previous);
    return { ok: true, draft: previous };
  }

  function read(): Draft {
    return clone(options.getDraft());
  }

  return { studio: options.studio, descriptors: options.descriptors, execute, undo, read };
}
