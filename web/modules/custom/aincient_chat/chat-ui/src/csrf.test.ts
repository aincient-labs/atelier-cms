// @vitest-environment jsdom
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { installCsrfFetch, resetCsrf } from "./csrf";

vi.mock("./console-config", () => ({ csrfTokenUrl: () => "/session/token" }));

type Call = { url: string; method: string; token: string | null };

describe("installCsrfFetch (0511)", () => {
  let calls: Call[];
  let tokens: string[];
  let refuseOnce: boolean;
  const realFetch = window.fetch;

  beforeEach(() => {
    calls = [];
    tokens = ["tok-1", "tok-2"];
    refuseOnce = false;
    window.fetch = vi.fn(async (input: RequestInfo | URL, init?: RequestInit) => {
      const url = typeof input === "string" ? input : input instanceof URL ? input.href : input.url;
      const method = (init?.method ?? "GET").toUpperCase();
      if (url.endsWith("/session/token")) return new Response(tokens.shift() ?? "tok-x");
      const token = new Headers(init?.headers).get("X-CSRF-Token");
      calls.push({ url, method, token });
      if (refuseOnce && token === "tok-1") {
        refuseOnce = false;
        return new Response("<!DOCTYPE html><title>Access denied</title>", { status: 403 });
      }
      return new Response("{}", { status: 200 });
    }) as typeof fetch;
    resetCsrf();
    installCsrfFetch();
  });

  afterEach(() => {
    window.fetch = realFetch;
    resetCsrf();
  });

  it("adds the token to a same-origin write", async () => {
    await fetch("/atelier/chat", { method: "POST", body: "{}" });
    expect(calls).toEqual([{ url: "/atelier/chat", method: "POST", token: "tok-1" }]);
  });

  it("leaves safe methods alone", async () => {
    await fetch("/atelier/chat/threads");
    expect(calls[0].token).toBeNull();
  });

  it("never sends the token to another origin", async () => {
    await fetch("https://elsewhere.example/hook", { method: "POST" });
    expect(calls[0].token).toBeNull();
  });

  it("keeps a token the caller set", async () => {
    await fetch("/atelier/x", { method: "DELETE", headers: { "X-CSRF-Token": "mine" } });
    expect(calls[0].token).toBe("mine");
  });

  it("fetches the token once per page", async () => {
    await fetch("/atelier/a", { method: "POST" });
    await fetch("/atelier/b", { method: "POST" });
    expect(calls.map((c) => c.token)).toEqual(["tok-1", "tok-1"]);
  });

  it("retries once with a fresh token when the session moved on", async () => {
    refuseOnce = true;
    const res = await fetch("/atelier/chat", { method: "POST", body: "{}" });
    expect(res.status).toBe(200);
    expect(calls.map((c) => c.token)).toEqual(["tok-1", "tok-2"]);
  });

  it("does not retry a 403 when the token is unchanged (a permission refusal)", async () => {
    tokens = ["tok-1", "tok-1"];
    refuseOnce = true;
    const res = await fetch("/atelier/chat", { method: "POST", body: "{}" });
    expect(res.status).toBe(403);
    expect(calls).toHaveLength(1);
  });
});
