/**
 * Owner words for engine steps — the presentation layer over `node` frames.
 *
 * The backend relays one `node` frame per executed FlowDrop job (see
 * NodeProgressSubscriber), which is the ENGINE's own job list: it includes the
 * wiring (chat_input, prompt_template, boolean_gateway, conversation_buffer) and
 * carries machine ids. Shown raw that reads as "Chat input, chat_input" — the
 * machinery narrating itself, which is exactly what the craftsman's voice
 * forbids (brand.md §7: name outcomes, not machinery).
 *
 * So every step is resolved here into:
 *  - `kind`  — "work" (something the user asked for) or "plumbing" (wiring that
 *              only matters when debugging), and
 *  - `present`/`past` — what to say while it runs and after it finished.
 *
 * Machine ids never reach the default UI; they surface only under the
 * console-wide technical-detail flag (`aincient_chat.settings:features
 * .technical_detail`), where the plumbing rows come back too.
 *
 * This is a LEAF module: it takes a plain step shape and imports nothing
 * app-side, so the adapter, the trail widget and its tests can all use it.
 * Adding a node type = one line in a map below; an unknown type still resolves
 * (to its authored label), it just isn't phrased as nicely.
 */

export type StepKind = "work" | "plumbing";

/**
 * The fields of a `node` frame this module reasons about.
 *
 * `label` is optional and defensively read: the adapter always sets it, but a
 * step also arrives as tool-part `args` — data this module must not crash on.
 */
export type StepInput = {
  label?: string;
  status?: string;
  nodeTypeId?: string;
  tool?: boolean;
};

export type StepPhrase = {
  /** While it is running — "Generating an image". */
  present: string;
  /** Once it finished — "Generated an image". */
  past: string;
  kind: StepKind;
};

/**
 * Wiring: real nodes, but not answers to anything the user asked for — routing,
 * prompt assembly, buffer reads/writes, the tool dispatcher. Dropped from a
 * finished trail, yet PHRASED anyway, because while the turn runs these are the
 * ticks that show the machine moving (`visibleSteps`). "Read the conversation"
 * is worth a second of someone's attention; "Chat Input, chat_input" never was.
 */
const PLUMBING_PHRASES: Record<string, [present: string, past: string]> = {
  chat_input: ["Reading your message", "Read your message"],
  chat_output: ["Wrapping up", "Wrapped up"],
  prompt_template: ["Preparing the prompt", "Prepared the prompt"],
  data_mapper: ["Sorting the details", "Sorted the details"],
  data_extractor: ["Picking out the details", "Picked out the details"],
  boolean_gateway: ["Deciding what is next", "Decided what is next"],
  switch_gateway: ["Deciding what is next", "Decided what is next"],
  if_else: ["Deciding what is next", "Decided what is next"],
  confirmation: ["Waiting for you", "Waited for you"],
  flowdrop_node_processor_toolbox: ["Gathering its tools", "Gathered its tools"],
  conversation_buffer: ["Saving the conversation", "Saved the conversation"],
  conversation_normalize: ["Tidying the conversation", "Tidied the conversation"],
  message_assemble: ["Preparing the messages", "Prepared the messages"],
  aincient_flows_aincient_invoke: ["Using its tools", "Used its tools"],
  aincient_flows_brand_state: ["Reading the brand", "Read the brand"],
};

/** Wiring with no phrase worth writing — its authored label will do. */
const PLUMBING_UNPHRASED = new Set(["note"]);

/** Node types whose work is worth naming, keyed by node_type_id. */
const NODE_PHRASES: Record<string, [present: string, past: string]> = {
  aincient_reason: ["Thinking", "Thought it through"],
  flowdrop_ai_provider_simple_chat: ["Thinking", "Thought it through"],
  http_request: ["Fetching from the web", "Fetched from the web"],
  aincient_flows_policy_check: ["Checking site policy", "Checked site policy"],
  aincient_flows_brand_validate_slice: ["Checking the brand", "Checked the brand"],
  aincient_flows_brand_apply_slices: ["Applying the brand", "Applied the brand"],
};

/**
 * Capabilities, keyed by the bare capability name (the part after
 * `aincient_flows_aincient_capability_`). These are the tools the agent reaches
 * for, so their phrasing is what the user actually reads in a normal turn.
 */
const CAPABILITY_PHRASES: Record<string, [present: string, past: string]> = {
  list_pages: ["Looking through your pages", "Looked through your pages"],
  find_reference: ["Looking something up", "Looked something up"],
  preview_page: ["Previewing the page", "Previewed the page"],
  reset_preview: ["Clearing the preview", "Cleared the preview"],
  run_page_audit: ["Checking the page", "Checked the page"],
  read_meta_tags: ["Reading the page's meta tags", "Read the page's meta tags"],
  generate_image: ["Generating an image", "Generated an image"],
  generate_alt_text: ["Writing alt text", "Wrote alt text"],
  propose_media_name: ["Naming the image", "Named the image"],
  preview_brand: ["Previewing the brand", "Previewed the brand"],
  propose_brand_status: ["Reviewing the brand", "Reviewed the brand"],
  brand_picker: ["Opening the brand picker", "Opened the brand picker"],
  preview_chrome: ["Previewing the header and footer", "Previewed the header and footer"],
  studio_tour: ["Showing you around", "Showed you around"],
  onboarding_panel: ["Opening setup", "Opened setup"],
};

/**
 * Sub-agents (a workflow run as a node), keyed by the workflow id. A named
 * specialist is the one place the engine's own structure IS user-meaningful —
 * the brand specialists are how the Brand studio explains itself.
 */
const SUBAGENT_PHRASES: Record<string, [present: string, past: string]> = {
  aincient_brand_specialist_colour: ["Working on colour", "Worked on colour"],
  aincient_brand_specialist_typography: ["Working on typography", "Worked on typography"],
  aincient_brand_specialist_shape: ["Working on shape", "Worked on shape"],
};

const CAPABILITY_PREFIX = "aincient_flows_aincient_capability_";
const SUBWORKFLOW_PREFIX = "flowdrop_workflow_executor_flowdrop_workflow_";

/**
 * Owner words for a machine id: drop our module/plugin prefixes, swap the
 * underscores, sentence-case. Best effort, and only ever a FALLBACK — a phrase
 * from the maps above always wins.
 */
function humanize(id: string): string {
  const bare = id
    .replace(/^aincient_flows_aincient_/, "")
    .replace(/^aincient_flows_/, "")
    .replace(/^aincient_/, "")
    .replace(/_/g, " ")
    .trim();
  return bare ? bare.charAt(0).toUpperCase() + bare.slice(1) : id;
}

/** The step's authored label, tolerating a missing or non-string one. */
function authoredLabel(step: StepInput): string {
  return typeof step.label === "string" ? step.label.trim() : "";
}

/** A step with no known phrasing: use its authored label, never its machine id. */
function fallback(step: StepInput): StepPhrase {
  const label = authoredLabel(step);
  const text = label && label !== step.nodeTypeId ? label : humanize(step.nodeTypeId ?? label);
  return { present: text, past: text, kind: "work" };
}

/**
 * What to say about one step.
 *
 * A failed step is always "work": a failure the user can see beats a tidy trail
 * (the empty-bubble lesson in adapter.ts — silent machinery is how three
 * backend failures got reported as "it did nothing").
 */
export function describeStep(step: StepInput): StepPhrase {
  const typeId = step.nodeTypeId ?? "";
  const failed = step.status === "failed";

  const named = NODE_PHRASES[typeId];
  if (named) return { present: named[0], past: named[1], kind: "work" };

  if (typeId.startsWith(CAPABILITY_PREFIX)) {
    const name = typeId.slice(CAPABILITY_PREFIX.length);
    const phrase = CAPABILITY_PHRASES[name];
    if (phrase) return { present: phrase[0], past: phrase[1], kind: "work" };
    const text = humanize(name);
    return { present: text, past: text, kind: "work" };
  }

  if (typeId.startsWith(SUBWORKFLOW_PREFIX)) {
    const id = typeId.slice(SUBWORKFLOW_PREFIX.length);
    const phrase = SUBAGENT_PHRASES[id];
    if (phrase) return { present: phrase[0], past: phrase[1], kind: "work" };
    const text = humanize(id);
    return { present: text, past: text, kind: "work" };
  }

  const wiring = PLUMBING_PHRASES[typeId];
  if (wiring || PLUMBING_UNPHRASED.has(typeId)) {
    // A FAILED wiring step is promoted to work: a failure the user can see beats
    // a tidy trail, and this is the one row that must survive the turn ending.
    const kind: StepKind = failed ? "work" : "plumbing";
    if (!wiring) {
      const text = authoredLabel(step) || humanize(typeId);
      return { present: text, past: text, kind };
    }
    return { present: wiring[0], past: wiring[1], kind };
  }

  return fallback(step);
}

/** Whether this step is worth showing to someone who isn't debugging. */
export function isWork(step: StepInput): boolean {
  return describeStep(step).kind === "work";
}

/** A step paired with its resolved words, the shape the trail renders. */
export type DescribedStep<T extends StepInput = StepInput> = {
  step: T;
  phrase: StepPhrase;
};

/** Resolve a whole trail at once, keeping arrival order. */
export function describeSteps<T extends StepInput>(steps: readonly T[]): DescribedStep<T>[] {
  return steps.map((step) => ({ step, phrase: describeStep(step) }));
}

/**
 * Which steps the trail shows — and the answer differs WHILE THE TURN RUNS.
 *
 * A page turn spends most of its minute inside one node (a reasoning step of
 * ~50s is normal), and no frame arrives until it finishes. Waiting is the state
 * that needs the most feedback and has the least to report, so while running the
 * trail is EPHEMERAL: the wiring shows too, because "Read the conversation" ·
 * "Built the prompt" ticking by is honest evidence that the machine is moving.
 * When the turn finishes those rows drop out and only the work remains, so the
 * conversation's record stays clean — motion while you wait, no residue after.
 *
 * Technical detail keeps everything, running or not: it is the engine view.
 */
export function visibleSteps<T extends StepInput>(
  described: readonly DescribedStep<T>[],
  options: { running: boolean; technical: boolean },
): DescribedStep<T>[] {
  if (options.technical || options.running) return [...described];
  return described.filter((d) => d.phrase.kind === "work");
}

/**
 * The one-line "what just happened" for a finished turn.
 *
 * Names the work instead of counting nodes — a count of internal jobs is noise
 * that also jitters as flows are edited. Long runs keep the first two names and
 * count the rest, so the header stays one line.
 */
export function summarizeSteps(steps: StepInput[]): string {
  const seen: string[] = [];
  for (const step of steps) {
    const { past, kind } = describeStep(step);
    if (kind !== "work") continue;
    if (!seen.includes(past)) seen.push(past);
  }
  if (seen.length === 0) return "";
  if (seen.length <= 2) return seen.join(" · ");
  return `${seen[0]} · ${seen[1]} · ${seen.length - 2} more`;
}
