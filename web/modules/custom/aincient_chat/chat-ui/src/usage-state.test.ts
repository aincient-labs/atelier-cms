import { describe, expect, it } from "vitest";
import { addUsage, EMPTY_USAGE } from "./usage-state";

/**
 * The $ figure is shown only when every call in the tally was priced. A model
 * with no rate arrives as `cost: null`; summing it as 0 would print a partial
 * figure that reads as the whole price.
 */
describe("addUsage", () => {
  const call = (cost: number | null) => ({ input: 100, output: 10, cached: 0, cost });

  it("shows cost when every call was priced", () => {
    const total = addUsage(addUsage(EMPTY_USAGE, call(0.01)), call(0.02));
    expect(total.hasCost).toBe(true);
    expect(total.cost).toBeCloseTo(0.03);
    expect(total.unpriced).toBe(0);
  });

  it("hides cost when any call was unpriced", () => {
    const total = addUsage(addUsage(EMPTY_USAGE, call(0.01)), call(null));
    expect(total.hasCost).toBe(false);
    expect(total.unpriced).toBe(1);
    expect(total.calls).toBe(2);
    expect(total.input).toBe(200);
  });

  it("stays hidden once an unpriced call is in the session", () => {
    const total = addUsage(addUsage(EMPTY_USAGE, call(null)), call(0.05));
    expect(total.hasCost).toBe(false);
  });

  it("shows no $0 for free (local) calls", () => {
    const total = addUsage(EMPTY_USAGE, call(0));
    expect(total.hasCost).toBe(false);
    expect(total.unpriced).toBe(0);
  });
});
