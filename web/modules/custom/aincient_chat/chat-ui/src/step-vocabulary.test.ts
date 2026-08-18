import { describe, expect, it } from "vitest";
import { describeStep, describeSteps, isWork, summarizeSteps, visibleSteps } from "./step-vocabulary";

/**
 * The contract the console's copy rests on: engine ids never become UI text,
 * wiring is not work, and real work has owner words in both tenses.
 */
describe("describeStep", () => {
  it("phrases a capability as an outcome, present and past", () => {
    const phrase = describeStep({
      label: "Generate image",
      status: "completed",
      nodeTypeId: "aincient_flows_aincient_capability_generate_image",
    });
    expect(phrase).toEqual({
      present: "Generating an image",
      past: "Generated an image",
      kind: "work",
    });
  });

  it("treats the wiring as plumbing", () => {
    for (const nodeTypeId of [
      "chat_input",
      "chat_output",
      "prompt_template",
      "boolean_gateway",
      "flowdrop_node_processor_toolbox",
      "conversation_buffer",
      "conversation_normalize",
      "message_assemble",
      "aincient_flows_aincient_invoke",
    ]) {
      expect(isWork({ label: "Whatever", status: "completed", nodeTypeId })).toBe(false);
    }
  });

  it("counts reasoning, tools, brand and policy steps as work", () => {
    for (const nodeTypeId of [
      "aincient_reason",
      "aincient_flows_policy_check",
      "aincient_flows_brand_validate_slice",
      "aincient_flows_aincient_capability_list_pages",
      "flowdrop_workflow_executor_flowdrop_workflow_aincient_brand_specialist_colour",
    ]) {
      expect(isWork({ label: "Whatever", status: "completed", nodeTypeId })).toBe(true);
    }
  });

  it("shows a failed wiring step rather than hiding it", () => {
    const step = { label: "Prompt Template", status: "failed", nodeTypeId: "prompt_template" };
    expect(isWork(step)).toBe(true);
    // Promoted to work, and still in owner words — a failure is the one wiring
    // row that must survive the turn ending, so it has to read as a sentence.
    expect(describeStep(step).past).toBe("Prepared the prompt");
  });

  it("phrases the wiring too, for the ephemeral running trail", () => {
    // Hidden once done, but shown while running — so "Chat Input" is not good
    // enough. Both tenses, like any other step.
    const phrase = describeStep({ label: "Chat Input", status: "completed", nodeTypeId: "chat_input" });
    expect(phrase).toEqual({
      present: "Reading your message",
      past: "Read your message",
      kind: "plumbing",
    });
  });

  it("falls back to the authored label, never to the machine id", () => {
    const phrase = describeStep({
      label: "Sort the sections",
      status: "completed",
      nodeTypeId: "some_contrib_node",
    });
    expect(phrase.past).toBe("Sort the sections");
    expect(phrase.kind).toBe("work");
  });

  it("humanizes an unknown capability instead of printing snake_case", () => {
    const phrase = describeStep({
      label: "aincient_flows_aincient_capability_publish_page",
      status: "completed",
      nodeTypeId: "aincient_flows_aincient_capability_publish_page",
    });
    expect(phrase.past).toBe("Publish page");
  });

  it("never leaves a machine id as the label when there is nothing better", () => {
    // Worst case: the label IS the id (an unlabelled instance). The words must
    // still read as words — this is the "Chat input, chat_input" regression.
    const phrase = describeStep({
      label: "aincient_flows_brand_apply_slices",
      status: "completed",
      nodeTypeId: "aincient_flows_brand_apply_slices",
    });
    expect(phrase.past).toBe("Applied the brand");
    expect(phrase.past).not.toContain("_");
  });
});

describe("summarizeSteps", () => {
  const capability = (name: string) => ({
    label: name,
    status: "completed",
    nodeTypeId: `aincient_flows_aincient_capability_${name}`,
  });

  it("is empty when a turn only ran wiring", () => {
    expect(
      summarizeSteps([
        { label: "Chat input", status: "completed", nodeTypeId: "chat_input" },
        { label: "Chat output", status: "completed", nodeTypeId: "chat_output" },
      ]),
    ).toBe("");
  });

  it("names the work instead of counting nodes", () => {
    expect(summarizeSteps([capability("generate_image"), { label: "Reason", status: "completed", nodeTypeId: "aincient_reason" }]))
      .toBe("Generated an image · Thought it through");
  });

  it("collapses repeats of the same step", () => {
    expect(summarizeSteps([capability("preview_page"), capability("preview_page")])).toBe(
      "Previewed the page",
    );
  });

  it("keeps two names and counts the rest", () => {
    expect(
      summarizeSteps([
        capability("list_pages"),
        capability("preview_page"),
        capability("generate_image"),
        capability("run_page_audit"),
      ]),
    ).toBe("Looked through your pages · Previewed the page · 2 more");
  });
});

describe("robustness", () => {
  it("survives a step with no label and no type (malformed part args)", () => {
    // The trail also reads persisted/mocked tool-part args, so a missing field
    // must degrade, not throw — the old widget rendered `{s.label}` and
    // tolerated it.
    expect(() => describeStep({} as never)).not.toThrow();
    expect(describeStep({} as never).kind).toBe("work");
    expect(() => summarizeSteps([{} as never])).not.toThrow();
  });

  it("keeps classifying by type when the label is absent", () => {
    expect(isWork({ status: "completed", nodeTypeId: "chat_input" })).toBe(false);
    expect(describeStep({ status: "completed", nodeTypeId: "chat_input" }).past).toBe("Read your message");
  });
});

describe("visibleSteps", () => {
  const wiring = { label: "Chat input", status: "completed", nodeTypeId: "chat_input" };
  const work = {
    label: "Generate image",
    status: "completed",
    nodeTypeId: "aincient_flows_aincient_capability_generate_image",
  };

  it("shows the wiring WHILE RUNNING so a long wait has visible motion", () => {
    const shown = visibleSteps(describeSteps([wiring, work]), { running: true, technical: false });
    expect(shown).toHaveLength(2);
  });

  it("drops the wiring once the turn is done — ephemeral, no residue", () => {
    const shown = visibleSteps(describeSteps([wiring, work]), { running: false, technical: false });
    expect(shown.map((d) => d.phrase.past)).toEqual(["Generated an image"]);
  });

  it("keeps everything in technical mode, running or not", () => {
    for (const running of [true, false]) {
      const shown = visibleSteps(describeSteps([wiring, work]), { running, technical: true });
      expect(shown).toHaveLength(2);
    }
  });
});
