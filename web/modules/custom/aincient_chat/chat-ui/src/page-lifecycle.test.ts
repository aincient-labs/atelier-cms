import { describe, expect, it } from "vitest";
import { carriesEdits, planLifecycle } from "./page-lifecycle";
import type { Moderation, Transition } from "./page-state";

// The shipped workflow (config/sync workflows.workflow.aincient_editorial.yml):
// Draft 0 · Needs review 1 · Published 2 (published, default) · Archived 3 (default).
const STATES: Record<string, { label: string; weight: number; published: boolean; def: boolean }> = {
  draft: { label: "Draft", weight: 0, published: false, def: false },
  needs_review: { label: "Needs review", weight: 1, published: false, def: false },
  published: { label: "Published", weight: 2, published: true, def: true },
  archived: { label: "Archived", weight: 3, published: false, def: true },
  // A site-built workflow's extras (an illustrative legal-review flow).
  legal: { label: "Legal review", weight: 2, published: false, def: false },
  translation: { label: "Translation", weight: 3, published: false, def: false },
  copy_edit: { label: "Copy edit", weight: 1, published: false, def: false },
  unpublished: { label: "Unpublished", weight: 6, published: false, def: true },
};

const t = (id: string, to: string, weight: number, label = id): Transition => ({
  id,
  label,
  to,
  to_label: STATES[to].label,
  weight,
  to_weight: STATES[to].weight,
  to_published: STATES[to].published,
  to_default_revision: STATES[to].def,
});

const mod = (state: string, transitions: Transition[], over: Partial<Moderation> = {}): Moderation => ({
  state,
  stateLabel: STATES[state].label,
  stateWeight: STATES[state].weight,
  statePublished: STATES[state].published,
  hasPendingDraft: false,
  canEdit: true,
  transitions,
  baseVid: 1,
  ...over,
});

const ids = (ts: Transition[]) => ts.map((x) => x.id);

describe("planLifecycle — the shipped editorial workflow", () => {
  it("offers a new page only the first Publish", () => {
    const plan = planLifecycle(mod("draft", []), { isNew: true });
    expect(plan.primary).toEqual({ kind: "publish", transition: null });
    expect([...plan.forward, ...plan.back, ...plan.offline]).toEqual([]);
  });

  it("makes Publish primary on a draft, Submit for review the only menu entry, and folds create_new_draft", () => {
    const plan = planLifecycle(
      mod("draft", [t("create_new_draft", "draft", 0), t("submit_for_review", "needs_review", 1), t("publish", "published", 4)]),
    );
    expect(plan.primary?.kind).toBe("publish");
    expect(plan.primary?.transition?.id).toBe("publish");
    expect(ids(plan.forward)).toEqual(["submit_for_review"]);
    expect(plan.back).toEqual([]);
  });

  it("makes Submit for review primary for an editor who can't publish", () => {
    const plan = planLifecycle(mod("draft", [t("create_new_draft", "draft", 0), t("submit_for_review", "needs_review", 1)]));
    expect(plan.primary).toMatchObject({ kind: "transition", transition: { id: "submit_for_review" } });
    expect([...plan.forward, ...plan.back]).toEqual([]);
  });

  it("gives a reviewer Approve as primary and Reject as send-back; the re-submit self-loop is folded", () => {
    const plan = planLifecycle(
      mod("needs_review", [t("submit_for_review", "needs_review", 1), t("approve", "published", 2), t("reject", "draft", 3)]),
    );
    expect(plan.primary).toMatchObject({ kind: "transition", transition: { id: "approve" } });
    expect(ids(plan.back)).toEqual(["reject"]);
    expect(plan.forward).toEqual([]);
  });

  it("puts Archive under take-offline, never primary, and re-publishes a dirty live page", () => {
    const transitions = [t("create_new_draft", "draft", 0), t("archive", "archived", 5)];
    const clean = planLifecycle(mod("published", transitions));
    expect(clean.primary).toBeNull();
    expect(ids(clean.offline)).toEqual(["archive"]);
    const dirty = planLifecycle(mod("published", transitions), { dirty: true });
    expect(dirty.primary).toEqual({ kind: "publish", transition: null });
  });

  it("doesn't offer a re-publish to someone who can't edit", () => {
    const plan = planLifecycle(mod("published", [t("archive", "archived", 5)], { canEdit: false }), { dirty: true });
    expect(plan.primary).toBeNull();
  });

  it("restores an archived page as a send-back, with no primary", () => {
    const plan = planLifecycle(mod("archived", [t("restore", "draft", 6)]));
    expect(plan.primary).toBeNull();
    expect(ids(plan.back)).toEqual(["restore"]);
  });
});

describe("planLifecycle — a site-built workflow", () => {
  // From Legal review: two ways forward, one fast-track live, two ways back, one archive.
  const legal = mod("legal", [
    t("save", "legal", 0),
    t("clear_legal", "translation", 3, "Clear legal"),
    t("fast_track", "published", 7, "Fast-track publish"),
    t("changes", "copy_edit", 8, "Request changes"),
    t("reject", "draft", 9),
    t("archive", "archived", 12),
  ]);

  it("picks the go-live transition as primary even when it isn't called publish", () => {
    const plan = planLifecycle(legal);
    expect(plan.primary).toMatchObject({ kind: "transition", transition: { id: "fast_track" } });
    expect(ids(plan.forward)).toEqual(["clear_legal"]);
    expect(ids(plan.back)).toEqual(["changes", "reject"]);
    expect(ids(plan.offline)).toEqual(["archive"]);
  });

  it("falls back to the lightest forward step when the user can't go live", () => {
    const plan = planLifecycle(mod("legal", legal.transitions.filter((x) => x.id !== "fast_track")));
    expect(plan.primary).toMatchObject({ kind: "transition", transition: { id: "clear_legal" } });
    expect(plan.forward).toEqual([]);
  });

  it("treats Unpublish as take-offline even though its state is heavier", () => {
    const plan = planLifecycle(mod("published", [t("unpublish", "unpublished", 10)]));
    expect(plan.primary).toBeNull();
    expect(ids(plan.offline)).toEqual(["unpublish"]);
    expect(plan.forward).toEqual([]);
  });

  it("carries the edits only forward from Draft — a save would move any other state back to Draft", () => {
    const draft = mod("draft", [
      t("create_new_draft", "draft", 0),
      t("to_legal", "legal", 2, "Send to legal"),
      t("to_copy", "copy_edit", 1, "Send to copy edit"),
    ]);
    const draftPlan = planLifecycle(draft);
    const inDraft = (id: string) => draft.transitions.find((x) => x.id === id)!;
    expect(carriesEdits(draftPlan, inDraft("to_copy"), draft)).toBe(true);
    expect(carriesEdits(draftPlan, inDraft("to_legal"), draft)).toBe(true);

    const plan = planLifecycle(legal);
    const by = (id: string) => legal.transitions.find((x) => x.id === id)!;
    expect(carriesEdits(plan, by("fast_track"), legal)).toBe(false);
    expect(carriesEdits(plan, by("clear_legal"), legal)).toBe(false);
    expect(carriesEdits(plan, by("reject"), legal)).toBe(false);
    expect(carriesEdits(plan, by("archive"), legal)).toBe(false);
  });
});
