import { describe, expect, it } from "vitest";
import { validateArgs } from "./validate";
import type { JsonSchema } from "./validate";

/** The command-arg validator: one case per supported keyword, plus nested paths. */

const ok = (schema: JsonSchema, value: unknown) => expect(validateArgs(schema, value)).toEqual([]);
const issues = (schema: JsonSchema, value: unknown) => validateArgs(schema, value);

describe("validateArgs", () => {
  it("type: each JSON type, integer vs number, and a type list", () => {
    ok({ type: "string" }, "x");
    ok({ type: "number" }, 1.5);
    ok({ type: "number" }, 2);
    ok({ type: "integer" }, 2);
    ok({ type: "boolean" }, false);
    ok({ type: "object" }, {});
    ok({ type: "array" }, []);
    ok({ type: "null" }, null);
    ok({ type: ["string", "null"] }, null);
    expect(issues({ type: "integer" }, 1.5)).toEqual([{ path: "$", message: "must be integer, got number" }]);
    expect(issues({ type: "object" }, [])).toEqual([{ path: "$", message: "must be object, got array" }]);
    expect(issues({ type: "object" }, null)).toEqual([{ path: "$", message: "must be object, got null" }]);
    expect(issues({ type: "number" }, Number.NaN)).toEqual([{ path: "$", message: "must be number, got number" }]);
    expect(issues({ type: ["string", "null"] }, 3)).toEqual([{ path: "$", message: "must be string or null, got integer" }]);
    expect(issues({ type: "string" }, undefined)).toEqual([{ path: "$", message: "must be string, got undefined" }]);
  });

  it("a wrong type reports only the type", () => {
    expect(issues({ type: "string", minLength: 3, enum: ["abc"] }, 1)).toEqual([
      { path: "$", message: "must be string, got integer" },
    ]);
  });

  it("const and enum (deep equality)", () => {
    ok({ const: { a: [1] } }, { a: [1] });
    expect(issues({ const: "x" }, "y")).toEqual([{ path: "$", message: 'must equal "x"' }]);
    ok({ enum: ["a", 1, null] }, null);
    expect(issues({ enum: ["a", "b"] }, "c")).toEqual([{ path: "$", message: 'must be one of "a", "b"' }]);
  });

  it("minLength / maxLength count code points", () => {
    ok({ minLength: 2, maxLength: 2 }, "😀😀");
    expect(issues({ minLength: 2 }, "a")).toEqual([{ path: "$", message: "must be at least 2 characters, got 1" }]);
    expect(issues({ maxLength: 1 }, "ab")).toEqual([{ path: "$", message: "must be at most 1 characters, got 2" }]);
  });

  it("pattern is unanchored; an invalid pattern is reported, not thrown", () => {
    ok({ pattern: "b" }, "abc");
    expect(issues({ pattern: "^#[0-9a-f]{3}$" }, "#zzz")).toEqual([
      { path: "$", message: 'must match pattern "^#[0-9a-f]{3}$"' },
    ]);
    expect(issues({ pattern: "(" }, "x")).toEqual([
      { path: "$", message: 'schema pattern "(" is not a valid regular expression' },
    ]);
  });

  it("minimum / maximum", () => {
    ok({ minimum: 0, maximum: 10 }, 10);
    expect(issues({ minimum: 0 }, -1)).toEqual([{ path: "$", message: "must be >= 0, got -1" }]);
    expect(issues({ maximum: 1 }, 1.5)).toEqual([{ path: "$", message: "must be <= 1, got 1.5" }]);
  });

  it("items / minItems / maxItems with indexed paths", () => {
    ok({ type: "array", items: { type: "string" }, minItems: 1, maxItems: 2 }, ["a"]);
    expect(issues({ items: { type: "string" } }, ["a", 2, "c", true])).toEqual([
      { path: "$[1]", message: "must be string, got integer" },
      { path: "$[3]", message: "must be string, got boolean" },
    ]);
    expect(issues({ minItems: 2 }, [1])).toEqual([{ path: "$", message: "must have at least 2 items, got 1" }]);
    expect(issues({ maxItems: 0 }, [1])).toEqual([{ path: "$", message: "must have at most 0 items, got 1" }]);
  });

  it("required, properties, additionalProperties: false (sorted extras)", () => {
    const schema: JsonSchema = {
      type: "object",
      required: ["name", "size"],
      properties: { name: { type: "string" }, size: { type: "integer" } },
      additionalProperties: false,
    };
    ok(schema, { name: "a", size: 1 });
    expect(issues(schema, { size: "big", zeta: 1, alpha: 2 })).toEqual([
      { path: "$.name", message: "is required" },
      { path: "$.size", message: "must be integer, got string" },
      { path: "$.alpha", message: "is not an allowed property" },
      { path: "$.zeta", message: "is not an allowed property" },
    ]);
    // An explicit undefined counts as missing.
    expect(issues({ required: ["a"] }, { a: undefined })).toEqual([{ path: "$.a", message: "is required" }]);
  });

  it("additionalProperties as a schema validates every extra key", () => {
    const schema: JsonSchema = { type: "object", additionalProperties: { type: "string", pattern: "^#" } };
    ok(schema, { primary: "#b33" });
    expect(issues(schema, { primary: "red", "on-primary": 1 })).toEqual([
      { path: '$["on-primary"]', message: "must be string, got integer" },
      { path: "$.primary", message: 'must match pattern "^#"' },
    ]);
  });

  it("anyOf / oneOf (pass or fail, no branch detail)", () => {
    const anyOf: JsonSchema = { anyOf: [{ type: "string" }, { type: "integer" }] };
    ok(anyOf, 3);
    expect(issues(anyOf, true)).toEqual([{ path: "$", message: "must match at least one schema in anyOf" }]);
    const oneOf: JsonSchema = { oneOf: [{ type: "number" }, { type: "integer" }] };
    ok(oneOf, 1.5);
    expect(issues(oneOf, 2)).toEqual([{ path: "$", message: "must match exactly one schema in oneOf, matched 2" }]);
    expect(issues(oneOf, "x")).toEqual([{ path: "$", message: "must match exactly one schema in oneOf, matched 0" }]);
  });

  it("reports deep paths through objects and arrays", () => {
    const schema: JsonSchema = {
      type: "object",
      required: ["sections"],
      properties: {
        sections: {
          type: "array",
          items: {
            type: "object",
            required: ["type"],
            properties: {
              type: { enum: ["hero", "text"] },
              props: { type: "object", properties: { level: { type: "integer", minimum: 1, maximum: 6 } } },
            },
          },
        },
      },
    };
    expect(issues(schema, { sections: [{ type: "hero" }, { props: { level: 9 } }, { type: "card" }] })).toEqual([
      { path: "$.sections[1].type", message: "is required" },
      { path: "$.sections[1].props.level", message: "must be <= 6, got 9" },
      { path: "$.sections[2].type", message: 'must be one of "hero", "text"' },
    ]);
  });
});
