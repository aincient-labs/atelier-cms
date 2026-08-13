// @vitest-environment jsdom
import { afterEach, describe, expect, it, vi } from "vitest";
import { interceptPreviewLinks, subscribeBlockedLink } from "./preview-nav";

/**
 * Link handling inside the preview frames.
 *
 * The previews are `srcdoc` documents: they have no URL of their own, so the
 * browser resolves EVERY relative href — including a bare `#` — against the
 * parent console URL. A `#` CTA (what the SDCs emit until a URL is set) used to
 * pass straight through and navigate the frame to `/atelier/content/node/N`,
 * loading the whole console inside the preview. So fragments must be cancelled
 * like any other href, and the in-page jump done by hand.
 */
function fire(el: Element): boolean {
  const ev = new MouseEvent("click", { bubbles: true, cancelable: true });
  el.dispatchEvent(ev);
  return ev.defaultPrevented;
}

const cleanups: Array<() => void> = [];
afterEach(() => {
  while (cleanups.length) cleanups.pop()!();
  document.body.innerHTML = "";
});

function attach(): void {
  cleanups.push(interceptPreviewLinks(document));
}

describe("interceptPreviewLinks", () => {
  it("cancels a bare '#' CTA without reporting it as a blocked link", () => {
    const seen = vi.fn();
    cleanups.push(subscribeBlockedLink(seen));
    document.body.innerHTML = `<a href="#"><span>Get started</span></a>`;
    attach();
    expect(fire(document.querySelector("span")!)).toBe(true);
    expect(seen).not.toHaveBeenCalled();
  });

  it("scrolls to an in-page target instead of navigating", () => {
    document.body.innerHTML = `<a href="#pricing">Pricing</a><section id="pricing"></section>`;
    const scroll = vi.fn();
    (document.getElementById("pricing") as HTMLElement).scrollIntoView = scroll;
    attach();
    expect(fire(document.querySelector("a")!)).toBe(true);
    expect(scroll).toHaveBeenCalled();
  });

  it("cancels a real link and reports it to the shell", () => {
    const seen = vi.fn();
    cleanups.push(subscribeBlockedLink(seen));
    document.body.innerHTML = `<a href="https://example.com/pricing">Pricing</a>`;
    attach();
    expect(fire(document.querySelector("a")!)).toBe(true);
    expect(seen).toHaveBeenCalledWith("https://example.com/pricing");
  });

  it("leaves javascript: hrefs alone", () => {
    document.body.innerHTML = `<a href="javascript:void 0">Toggle</a>`;
    attach();
    expect(fire(document.querySelector("a")!)).toBe(false);
  });
});
