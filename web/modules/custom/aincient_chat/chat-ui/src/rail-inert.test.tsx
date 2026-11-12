// @vitest-environment jsdom
/**
 * Hidden panels stay out of the tab order (console UI audit N1).
 *
 * Below 1100px the editor rail is a sheet parked off-screen until summoned, and
 * the closed conversations sidebar is zero-wide or off-canvas in every layout.
 * Both used to keep their controls tabbable, so keyboard focus vanished into
 * them. They are `inert` while hidden now:
 *
 *  - `useRailInert()` decides it for the rail (sheet width AND not summoned),
 *    rendered here against a mocked `matchMedia`;
 *  - every element that renders `.ain-studio__rail` (each built-in studio, the
 *    pack-studio host, the loading/failed placeholders) and the sidebar must
 *    wire the attribute — a source guard, so a new studio cannot forget it.
 */

import { afterEach, describe, expect, it } from "vitest";
import { act, cleanup, render, screen } from "@testing-library/react";
import { readFileSync, readdirSync, statSync } from "node:fs";
import { join } from "node:path";
import { StudioUIContext, useConvoInert, useRailInert, type StudioUI } from "./kit/studio-ui";

type Listener = () => void;

/** A controllable `matchMedia` whose (max-width: Npx) queries track `width`. */
function mockViewport(initial: number) {
  let width = initial;
  const lists: { query: string; listeners: Set<Listener>; mql: { matches: boolean } }[] = [];
  const evaluate = (q: string) => {
    const m = /max-width:\s*(\d+)px/.exec(q);
    return m ? width <= Number(m[1]) : false;
  };
  window.matchMedia = ((query: string) => {
    const entry = { query, listeners: new Set<Listener>(), mql: {} as { matches: boolean } };
    const mql = {
      get matches() {
        return evaluate(query);
      },
      media: query,
      addEventListener: (_: string, l: Listener) => entry.listeners.add(l),
      removeEventListener: (_: string, l: Listener) => entry.listeners.delete(l),
    };
    entry.mql = mql;
    lists.push(entry);
    return mql;
  }) as unknown as typeof window.matchMedia;
  return {
    resize(next: number) {
      width = next;
      for (const e of lists) e.listeners.forEach((l) => l());
    },
  };
}

const ui = (editOpen: boolean): StudioUI => ({
  editOpen,
  convoOpen: false,
  toggleEdit: () => {},
  toggleConvo: () => {},
  closeSheets: () => {},
});

function Rail() {
  const railInert = useRailInert();
  return (
    <div className="ain-studio__rail" data-testid="rail" inert={railInert}>
      <button type="button">Save</button>
    </div>
  );
}

afterEach(cleanup);

describe("useRailInert", () => {
  it("leaves the desktop rail column reachable", () => {
    mockViewport(1440);
    render(
      <StudioUIContext.Provider value={ui(false)}>
        <Rail />
      </StudioUIContext.Provider>,
    );
    expect(screen.getByTestId("rail").hasAttribute("inert")).toBe(false);
  });

  it("makes the parked sheet inert at tablet and phone widths", () => {
    for (const w of [1024, 768, 390]) {
      mockViewport(w);
      render(
        <StudioUIContext.Provider value={ui(false)}>
          <Rail />
        </StudioUIContext.Provider>,
      );
      expect(screen.getByTestId("rail").hasAttribute("inert"), `width ${w}`).toBe(true);
      cleanup();
    }
  });

  it("releases the sheet once it is summoned", () => {
    mockViewport(768);
    render(
      <StudioUIContext.Provider value={ui(true)}>
        <Rail />
      </StudioUIContext.Provider>,
    );
    expect(screen.getByTestId("rail").hasAttribute("inert")).toBe(false);
  });

  it("follows a resize across the sheet breakpoint", () => {
    const vp = mockViewport(1440);
    render(
      <StudioUIContext.Provider value={ui(false)}>
        <Rail />
      </StudioUIContext.Provider>,
    );
    expect(screen.getByTestId("rail").hasAttribute("inert")).toBe(false);
    act(() => vp.resize(900));
    expect(screen.getByTestId("rail").hasAttribute("inert")).toBe(true);
    act(() => vp.resize(1200));
    expect(screen.getByTestId("rail").hasAttribute("inert")).toBe(false);
  });
});

describe("useConvoInert", () => {
  function Convo({ docked, open }: { docked: boolean; open: boolean }) {
    return (
      <StudioUIContext.Provider value={{ ...ui(false), convoOpen: open }}>
        <Inner docked={docked} />
      </StudioUIContext.Provider>
    );
  }
  function Inner({ docked }: { docked: boolean }) {
    return <div data-testid="convo" inert={useConvoInert(docked)} />;
  }
  const inert = () => screen.getByTestId("convo").hasAttribute("inert");

  it("is inert only on phones, in a studio, while dropped", () => {
    mockViewport(390);
    render(<Convo docked open={false} />);
    expect(inert()).toBe(true);
    cleanup();
    render(<Convo docked open />);
    expect(inert()).toBe(false);
    cleanup();
    render(<Convo docked={false} open={false} />);
    expect(inert(), "chat-only studio: the conversation is the page").toBe(false);
    cleanup();
    mockViewport(768);
    render(<Convo docked open={false} />);
    expect(inert(), "tablet keeps the chat column").toBe(false);
  });
});

// --- source guard ---------------------------------------------------------

const SRC = __dirname;
const STUDIOS = join(__dirname, "../../../../studio");

function tsxFiles(dir: string): string[] {
  const out: string[] = [];
  for (const name of readdirSync(dir)) {
    if (name === "node_modules" || name === "dist") continue;
    const p = join(dir, name);
    if (statSync(p).isDirectory()) out.push(...tsxFiles(p));
    else if (name.endsWith(".tsx") && !name.endsWith(".test.tsx")) out.push(p);
  }
  return out;
}

/** Every JSX opening tag whose className includes `ain-studio__rail`. */
function railTags(source: string): string[] {
  const tags: string[] = [];
  const re = /<(div|aside|section)\b[^>]*?className="[^"]*\bain-studio__rail\b[^"]*"[^>]*>/gs;
  for (const m of source.matchAll(re)) tags.push(m[0]);
  return tags;
}

describe("every rail and the sidebar wire inert", () => {
  const files = [...tsxFiles(SRC), ...tsxFiles(STUDIOS)];

  it("finds the rails it guards (not vacuous)", () => {
    const count = files.reduce((n, f) => n + railTags(readFileSync(f, "utf8")).length, 0);
    // 7 built-in studios + the pack-studio host + loading + failed.
    expect(count).toBeGreaterThanOrEqual(10);
  });

  it("gives each rail an inert binding", () => {
    const missing: string[] = [];
    for (const f of files) {
      for (const tag of railTags(readFileSync(f, "utf8"))) {
        if (!/\binert=\{/.test(tag)) missing.push(`${f.replace(STUDIOS, "studio").replace(SRC, "src")}: ${tag.slice(0, 80)}`);
      }
    }
    expect(missing).toEqual([]);
  });

  it("makes the closed sidebar inert", () => {
    const app = readFileSync(join(SRC, "App.tsx"), "utf8");
    const aside = /<aside\s+className=\{`ain-sidebar[^>]*>/s.exec(app)?.[0] ?? "";
    expect(aside).toMatch(/inert=\{!open\}/);
  });

  it("makes the dropped phone conversation inert", () => {
    const app = readFileSync(join(SRC, "App.tsx"), "utf8");
    expect(app).toMatch(/<ThreadPrimitive\.Viewport className="ain-viewport"[^>]*inert=\{convoInert\}/);
  });
});
