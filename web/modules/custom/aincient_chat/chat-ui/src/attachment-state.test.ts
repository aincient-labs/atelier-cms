import { afterEach, describe, expect, it, vi } from "vitest";
import {
  addAttachment,
  clearAttachments,
  getAttachments,
  removeAttachment,
  subscribeAttachments,
  type PendingAttachment,
} from "./attachment-state";

const make = (ref: string): PendingAttachment => ({
  ref,
  kind: "image",
  thumb: `/thumb/${ref}.png`,
  filename: `${ref}.png`,
});

describe("attachment store", () => {
  afterEach(() => clearAttachments());

  it("appends added attachments in order", () => {
    addAttachment(make("context:1"));
    addAttachment(make("context:2"));
    const items = getAttachments();
    expect(items.map((a) => a.ref)).toEqual(["context:1", "context:2"]);
    expect(items[0].filename).toBe("context:1.png");
  });

  it("removes by ref, leaving the rest", () => {
    addAttachment(make("context:1"));
    addAttachment(make("context:2"));
    removeAttachment("context:1");
    expect(getAttachments().map((a) => a.ref)).toEqual(["context:2"]);
  });

  it("clear empties the store", () => {
    addAttachment(make("context:1"));
    clearAttachments();
    expect(getAttachments()).toEqual([]);
  });

  it("notifies subscribers on each mutation and stops after unsubscribe", () => {
    const cb = vi.fn();
    const unsub = subscribeAttachments(cb);

    addAttachment(make("context:1"));
    removeAttachment("context:1");
    addAttachment(make("context:2"));
    clearAttachments();
    expect(cb).toHaveBeenCalledTimes(4);

    unsub();
    addAttachment(make("context:3"));
    expect(cb).toHaveBeenCalledTimes(4);
  });

  it("clear on an already-empty store does not notify", () => {
    const cb = vi.fn();
    const unsub = subscribeAttachments(cb);
    clearAttachments();
    expect(cb).not.toHaveBeenCalled();
    unsub();
  });
});
