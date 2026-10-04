/**
 * The command-arg validator (plans/studio-commands.md P0, DECISIONS 0447): the
 * JSON-Schema keyword subset a {@link CommandDescriptor}'s `inputSchema` may use.
 *
 * Written here rather than ported: FlowDrop's `webmcp/validate.ts` is the model
 * (same subset, same "list of path + message" result), but it is not in this
 * workspace. Deliberately small — this is UX, not the authority (rule 3: the
 * server still validates every value it persists).
 *
 * Supported keywords: `type` (string/number/integer/boolean/object/array/null,
 * or a list of them), `const`, `enum`, `minLength`/`maxLength` (code points),
 * `pattern` (unanchored, `u` flag), `minimum`/`maximum`, `items` (one schema),
 * `minItems`/`maxItems`, `required`, `properties`, `additionalProperties`
 * (`false` or a schema), `anyOf`/`oneOf` (pass/fail only — their branches'
 * own issues are not reported). Anything else (`title`, `description`,
 * `default`, …) is ignored.
 *
 * Deterministic: issues come in a fixed keyword order, object keys in schema
 * order (`required`, then `properties`) and extra keys sorted, and a node whose
 * `type` is wrong reports only that — its other keywords would be noise.
 */

export type JsonSchemaType = "string" | "number" | "integer" | "boolean" | "object" | "array" | "null";

export type JsonSchema = {
  type?: JsonSchemaType | readonly JsonSchemaType[];
  const?: unknown;
  enum?: readonly unknown[];
  minLength?: number;
  maxLength?: number;
  pattern?: string;
  minimum?: number;
  maximum?: number;
  items?: JsonSchema;
  minItems?: number;
  maxItems?: number;
  required?: readonly string[];
  properties?: Readonly<Record<string, JsonSchema>>;
  additionalProperties?: boolean | JsonSchema;
  anyOf?: readonly JsonSchema[];
  oneOf?: readonly JsonSchema[];
  title?: string;
  description?: string;
  default?: unknown;
};

/** One failed keyword: `path` is `$` for the root, then `.key` / `[index]`. */
export type ValidationIssue = { path: string; message: string };

/** Validates `value` against `schema`; an empty list means valid. */
export function validateArgs(schema: JsonSchema, value: unknown): ValidationIssue[] {
  const issues: ValidationIssue[] = [];
  check(schema, value, "$", issues);
  return issues;
}

function typeOf(value: unknown): JsonSchemaType | "undefined" | "other" {
  if (value === null) return "null";
  if (Array.isArray(value)) return "array";
  switch (typeof value) {
    case "string":
      return "string";
    case "boolean":
      return "boolean";
    case "number":
      return Number.isFinite(value) ? (Number.isInteger(value) ? "integer" : "number") : "other";
    case "object":
      return "object";
    case "undefined":
      return "undefined";
    default:
      return "other";
  }
}

function matchesType(value: unknown, type: JsonSchemaType): boolean {
  const actual = typeOf(value);
  if (type === "number") return actual === "number" || actual === "integer";
  return actual === type;
}

function kindOf(value: unknown): string {
  const t = typeOf(value);
  return t === "other" ? typeof value : t;
}

function deepEqual(a: unknown, b: unknown): boolean {
  if (a === b) return true;
  if (typeof a !== "object" || typeof b !== "object" || a === null || b === null) return false;
  if (Array.isArray(a) !== Array.isArray(b)) return false;
  if (Array.isArray(a) && Array.isArray(b)) {
    return a.length === b.length && a.every((item, i) => deepEqual(item, b[i]));
  }
  const ka = Object.keys(a as object);
  const kb = Object.keys(b as object);
  if (ka.length !== kb.length) return false;
  return ka.every(
    (k) => Object.prototype.hasOwnProperty.call(b, k) && deepEqual((a as Record<string, unknown>)[k], (b as Record<string, unknown>)[k]),
  );
}

const patternCache = new Map<string, RegExp | null>();

function compile(pattern: string): RegExp | null {
  if (!patternCache.has(pattern)) {
    let re: RegExp | null;
    try {
      re = new RegExp(pattern, "u");
    } catch {
      re = null;
    }
    patternCache.set(pattern, re);
  }
  return patternCache.get(pattern) ?? null;
}

function childPath(path: string, key: string): string {
  return /^[A-Za-z_$][A-Za-z0-9_$]*$/.test(key) ? `${path}.${key}` : `${path}[${JSON.stringify(key)}]`;
}

function check(schema: JsonSchema, value: unknown, path: string, issues: ValidationIssue[]): void {
  const push = (message: string) => issues.push({ path, message });

  if (schema.type !== undefined) {
    const types: readonly JsonSchemaType[] = typeof schema.type === "string" ? [schema.type] : schema.type;
    if (!types.some((t) => matchesType(value, t))) {
      push(`must be ${types.join(" or ")}, got ${kindOf(value)}`);
      return;
    }
  }

  if ("const" in schema && !deepEqual(value, schema.const)) {
    push(`must equal ${JSON.stringify(schema.const)}`);
  }
  if (schema.enum !== undefined && !schema.enum.some((option) => deepEqual(value, option))) {
    push(`must be one of ${schema.enum.map((o) => JSON.stringify(o)).join(", ")}`);
  }

  if (typeof value === "string") {
    const length = [...value].length;
    if (schema.minLength !== undefined && length < schema.minLength) {
      push(`must be at least ${schema.minLength} characters, got ${length}`);
    }
    if (schema.maxLength !== undefined && length > schema.maxLength) {
      push(`must be at most ${schema.maxLength} characters, got ${length}`);
    }
    if (schema.pattern !== undefined) {
      const re = compile(schema.pattern);
      if (re === null) push(`schema pattern ${JSON.stringify(schema.pattern)} is not a valid regular expression`);
      else if (!re.test(value)) push(`must match pattern ${JSON.stringify(schema.pattern)}`);
    }
  }

  if (typeof value === "number") {
    if (schema.minimum !== undefined && value < schema.minimum) push(`must be >= ${schema.minimum}, got ${value}`);
    if (schema.maximum !== undefined && value > schema.maximum) push(`must be <= ${schema.maximum}, got ${value}`);
  }

  if (Array.isArray(value)) {
    if (schema.minItems !== undefined && value.length < schema.minItems) {
      push(`must have at least ${schema.minItems} items, got ${value.length}`);
    }
    if (schema.maxItems !== undefined && value.length > schema.maxItems) {
      push(`must have at most ${schema.maxItems} items, got ${value.length}`);
    }
    if (schema.items !== undefined) {
      const items = schema.items;
      value.forEach((item, i) => check(items, item, `${path}[${i}]`, issues));
    }
  }

  if (typeOf(value) === "object") {
    const record = value as Record<string, unknown>;
    const has = (key: string) => Object.prototype.hasOwnProperty.call(record, key) && record[key] !== undefined;
    for (const key of schema.required ?? []) {
      if (!has(key)) issues.push({ path: childPath(path, key), message: "is required" });
    }
    const properties = schema.properties ?? {};
    for (const [key, sub] of Object.entries(properties)) {
      if (has(key)) check(sub, record[key], childPath(path, key), issues);
    }
    if (schema.additionalProperties !== undefined && schema.additionalProperties !== true) {
      const extra = Object.keys(record)
        .filter((key) => !Object.prototype.hasOwnProperty.call(properties, key))
        .sort();
      for (const key of extra) {
        if (schema.additionalProperties === false) {
          issues.push({ path: childPath(path, key), message: "is not an allowed property" });
        } else {
          check(schema.additionalProperties, record[key], childPath(path, key), issues);
        }
      }
    }
  }

  if (schema.anyOf !== undefined && !schema.anyOf.some((sub) => validateArgs(sub, value).length === 0)) {
    push("must match at least one schema in anyOf");
  }
  if (schema.oneOf !== undefined) {
    const matched = schema.oneOf.filter((sub) => validateArgs(sub, value).length === 0).length;
    if (matched !== 1) push(`must match exactly one schema in oneOf, matched ${matched}`);
  }
}
