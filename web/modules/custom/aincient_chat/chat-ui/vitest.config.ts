import { defineConfig } from "vitest/config";

// Unit tests for the pure logic layer — the console statechart and its room
// primitives. Node environment (no DOM): the machine and `rooms-core` carry no
// React / assistant-ui dependency, so tests run fast and deterministic.
export default defineConfig({
  test: {
    environment: "node",
    include: ["src/**/*.test.ts"],
  },
});
