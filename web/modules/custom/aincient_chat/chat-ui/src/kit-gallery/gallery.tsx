import { useEffect, useState, type ReactNode } from "react";
import {
  Button,
  Checkbox,
  Chip,
  COMPONENT_ICON_NAMES,
  ComponentIcon,
  Dialog,
  DocumentIcon,
  EmptyState,
  LoadingState,
  ComposingState,
  DialogClose,
  Field,
  FieldRevert,
  FilterSelect,
  IconButton,
  Menu,
  MenuItem,
  MenuRadioGroup,
  MenuRadioItem,
  MoonIcon,
  MoreHorizontalIcon,
  Notice,
  PanelBar,
  PenIcon,
  PlusIcon,
  Popover,
  RadioCard,
  SegmentedControl,
  Select,
  Skeleton,
  StudioGroup,
  StudioStatus,
  SunIcon,
  Tabs,
  TextInput,
  Textarea,
  TrashIcon,
} from "../kit";
import "./gallery.css";

/**
 * The kit gallery — every primitive, every variant, in the theme you pick
 * (plans/studio-modules.md: "`/atelier/dev/kit` gallery behind the existing
 * `atelier_dev` floor … No Storybook"). Served by ConsoleController::kitGallery
 * only in dev mode, loaded as its own chunk (`kit-gallery.js`, vite.chunks.ts).
 *
 * It is the review surface for moving styles: when a primitive's CSS changes,
 * this page shows the change in both modes before any screen does. Imports only
 * `../kit` — what a studio can reach is exactly what is on show.
 */
const THEME_KEY = "aincient-theme";

function readTheme(): "light" | "dark" {
  try {
    return localStorage.getItem(THEME_KEY) === "dark" ? "dark" : "light";
  } catch {
    return "light";
  }
}

function Specimen({ name, note, children }: { name: string; note?: ReactNode; children: ReactNode }) {
  return (
    <section className="ain-kitgallery__specimen" aria-labelledby={`kit-${name}`}>
      <header className="ain-kitgallery__head">
        <h2 id={`kit-${name}`} className="ain-kitgallery__name">
          {name}
        </h2>
        {note != null && <p className="ain-kitgallery__note">{note}</p>}
      </header>
      <div className="ain-kitgallery__stage">{children}</div>
    </section>
  );
}

function Row({ label, children }: { label: string; children: ReactNode }) {
  return (
    <div className="ain-kitgallery__row">
      <span className="ain-kitgallery__rowlabel">{label}</span>
      <div className="ain-kitgallery__rowbody">{children}</div>
    </div>
  );
}

export function KitGallery() {
  const [theme, setTheme] = useState<"light" | "dark">(readTheme);
  useEffect(() => {
    document.getElementById("aincient-chat-root")?.setAttribute("data-ain-theme", theme);
    try {
      localStorage.setItem(THEME_KEY, theme);
    } catch {
      /* private window: the choice just isn't remembered */
    }
  }, [theme]);

  const [compare, setCompare] = useState(false);
  const [chip, setChip] = useState("soft");
  const [view, setView] = useState<"grid" | "list" | "table">("grid");
  const [title, setTitle] = useState("Spring opening hours");
  const [dirtyTitle, setDirtyTitle] = useState("Spring hours (edited)");
  const [delivery, setDelivery] = useState("self");
  const [dialog, setDialog] = useState<"none" | "plain" | "danger" | "form">("none");
  const [crumb, setCrumb] = useState("pages");
  const [zone, setZone] = useState("Europe/Berlin");
  const [last, setLast] = useState("—");

  return (
    <div className="ain-kitgallery">
      <PanelBar
        className="ain-kitgallery__bar"
        title="Kit gallery"
        actions={
          <SegmentedControl
            label="Theme"
            value={theme}
            onChange={setTheme}
            options={[
              { value: "light", label: <><SunIcon /> Light</> },
              { value: "dark", label: <><MoonIcon /> Dark</> },
            ]}
          />
        }
      />
      <main className="ain-kitgallery__body">
        <p className="ain-kitgallery__lede">
          Every primitive a studio imports from <code>@console/kit</code>, live. Dev mode only. The
          last action fired: <output className="ain-kitgallery__out">{last}</output>
        </p>

        <Specimen name="Button" note="A button is a promise: one primary per view, the only filled one; danger is brick outline at the confirm step.">
          <Row label="voices">
            <Button>Secondary</Button>
            <Button variant="primary">Publish</Button>
            <Button variant="quiet">Quiet</Button>
            <Button variant="danger">Delete for good</Button>
          </Row>
          <Row label="sm">
            <Button size="sm">Edit</Button>
            <Button size="sm" variant="quiet">Change</Button>
            <Button size="sm" variant="primary">Use</Button>
          </Row>
          <Row label="disabled">
            <Button disabled>Secondary</Button>
            <Button variant="primary" disabled>Publish</Button>
            <Button variant="quiet" disabled>Quiet</Button>
          </Row>
          <Row label="toggle">
            <Button pressed={compare} onClick={() => setCompare((v) => !v)}>
              Compare
            </Button>
          </Row>
          <Row label="with icon">
            <Button variant="primary"><PlusIcon /> New page</Button>
            <Button><PenIcon /> Rename</Button>
          </Row>
        </Specimen>

        <Specimen name="IconButton" note="The label is the accessible name and the tooltip.">
          <Row label="ghost">
            <IconButton label="Add"><PlusIcon /></IconButton>
            <IconButton label="Rename"><PenIcon /></IconButton>
            <IconButton label="Delete"><TrashIcon /></IconButton>
            <IconButton label="Disabled" disabled><TrashIcon /></IconButton>
          </Row>
          <Row label="pressed">
            <IconButton label="Pinned" pressed><PenIcon /></IconButton>
          </Row>
        </Specimen>

        <Specimen name="Field" note="The field wires label, hint, error and aria-invalid to the control inside it.">
          <div className="ain-kitgallery__fields">
            <Field label="Title" hint="Shown in search results and the browser tab.">
              <TextInput value={title} onChange={(e) => setTitle(e.target.value)} />
            </Field>
            <Field
              label="Title (changed)"
              dirty
              revert={<FieldRevert label="Title" onRevert={() => setDirtyTitle("Spring opening hours")} />}
            >
              <TextInput value={dirtyTitle} onChange={(e) => setDirtyTitle(e.target.value)} />
            </Field>
            <Field label="Slug" error="Already used by “Opening hours”.">
              <TextInput defaultValue="opening-hours" />
            </Field>
            <Field label="Type">
              <Select defaultValue="landing">
                <option value="landing">Landing page</option>
                <option value="blog">Blog post</option>
              </Select>
            </Field>
            <Field label="Summary">
              <Textarea rows={3} defaultValue="Open from nine, every day but Monday." />
            </Field>
            <Field label="Markdown source">
              <Textarea mono rows={5} defaultValue={"## Hours\n\n- Tue–Sun 09:00–18:00"} />
            </Field>
            <Field label="Disabled">
              <TextInput disabled defaultValue="Locked while another editor holds the page" />
            </Field>
          </div>
        </Specimen>

        <Specimen name="Checkbox · RadioCard">
          <Checkbox label="Show in the main menu" defaultChecked />
          <Checkbox label="Hide from search engines" />
          <fieldset className="ain-kitgallery__fieldset">
            <legend className="ain-field__label">Font delivery</legend>
            <RadioCard
              name="kit-delivery"
              label="Self-hosted"
              hint="Fonts are served from this site. No visitor data leaves it."
              checked={delivery === "self"}
              onChange={() => setDelivery("self")}
            />
            <RadioCard
              name="kit-delivery"
              label="Google Fonts"
              hint="Faster first load; visitors' IPs reach Google."
              checked={delivery === "google"}
              onChange={() => setDelivery("google")}
            />
          </fieldset>
        </Specimen>

        <Specimen name="Chip">
          <Row label="presets">
            {["soft", "sharp", "round"].map((c) => (
              <Chip key={c} pressed={chip === c} onClick={() => setChip(c)}>
                {c}
              </Chip>
            ))}
          </Row>
        </Specimen>

        <Specimen name="ComponentIcon" note="One line icon per built-in page component, a generic mark for anything else (packs). 16px, ink stroke; always beside the component's name.">
          <div className="ain-kitgallery__icons">
            {[...COMPONENT_ICON_NAMES, "acme_pack_ticker"].map((n) => (
              <span key={n} className="ain-kitgallery__icon">
                <ComponentIcon name={n} />
                {n}
              </span>
            ))}
          </div>
        </Specimen>

        <Specimen name="SegmentedControl" note="A value that changes what one panel shows. The active option is raised paper, never the accent.">
          <SegmentedControl
            label="View"
            value={view}
            onChange={setView}
            options={[
              { value: "grid", label: "Grid" },
              { value: "list", label: "List" },
              { value: "table", label: "Table", disabled: true, title: "Not available here" },
            ]}
          />
        </Specimen>

        <Specimen name="Tabs" note="Radix: arrow keys move between tabs, Home and End jump.">
          <Tabs
            label="Page facets"
            items={[
              { value: "body", label: "Body", content: <p className="ain-kitgallery__p">The sections of the page.</p> },
              { value: "presence", label: "Presence", content: <p className="ain-kitgallery__p">How the page appears in search and when shared.</p> },
              { value: "history", label: "History", content: <p className="ain-kitgallery__p">Every published revision.</p> },
            ]}
          />
        </Specimen>

        <Specimen name="Popover" note="Radix: focus moves in, Escape or an outside click closes, focus returns to the trigger.">
          <Row label="anchored">
            <Popover title="Link target" trigger={<Button>Open popover</Button>}>
              <Field label="URL">
                <TextInput placeholder="https://" />
              </Field>
              <DialogFooterRow>
                <Button size="sm" variant="primary" onClick={() => setLast("popover: apply")}>Apply</Button>
              </DialogFooterRow>
            </Popover>
          </Row>
        </Specimen>

        <Specimen name="Menu" note="Radix: arrows and typeahead move, Enter chooses, focus returns to the trigger. A link entry is MenuItem asChild around an <a>.">
          <Row label="actions">
            <Menu label="Page actions" trigger={<IconButton label="More actions"><MoreHorizontalIcon /></IconButton>}>
              <MenuItem icon={<PenIcon />} onSelect={() => setLast("menu: rename")}>Rename</MenuItem>
              <MenuItem icon={<PlusIcon />} onSelect={() => setLast("menu: duplicate")}>Duplicate</MenuItem>
              <MenuItem disabled onSelect={() => setLast("menu: move up")}>Move up</MenuItem>
              <MenuItem danger icon={<TrashIcon />} onSelect={() => setDialog("danger")}>Delete…</MenuItem>
            </Menu>
          </Row>
          <Row label="links">
            <Menu label="Account" trigger={<Button>Account</Button>}>
              <MenuItem onSelect={() => setLast("menu: manage account")}>Manage account</MenuItem>
              <MenuItem asChild>
                <a href="#kit-menu-link" onClick={() => setLast("menu: link followed")}>A link item (asChild)</a>
              </MenuItem>
            </Menu>
          </Row>
          <Row label="pick one">
            <Menu label="Section" align="start" trigger={<Button>Section: {crumb}</Button>}>
              <MenuRadioGroup value={crumb} onValueChange={(v) => { setCrumb(v); setLast(`menu: ${v}`); }}>
                <MenuRadioItem value="pages">Pages</MenuRadioItem>
                <MenuRadioItem value="media">Media</MenuRadioItem>
                <MenuRadioItem value="checks">Checks</MenuRadioItem>
              </MenuRadioGroup>
            </Menu>
          </Row>
        </Specimen>

        <Specimen name="FilterSelect" note="A long list: type to narrow, arrows move, Enter chooses. Short lists use the native Select.">
          <Field label="Timezone">
            <FilterSelect label="Timezone" placeholder="Filter timezones…" value={zone} onChange={(v) => { setZone(v); setLast(`select: ${v}`); }} options={ZONES} />
          </Field>
        </Specimen>

        <Specimen name="Dialog" note="Radix: focus is trapped and returns to the opener; the page behind is inert.">
          <Row label="modal">
            <Button onClick={() => setDialog("plain")}>Open dialog</Button>
            <Button variant="quiet" onClick={() => setDialog("danger")}>Open the confirm step</Button>
            <Button variant="quiet" onClick={() => setDialog("form")}>Open a form dialog</Button>
          </Row>
          <Dialog
            open={dialog === "form"}
            onOpenChange={(o) => setDialog(o ? "form" : "none")}
            title="New page"
            closeButton
            onSubmit={(e) => { e.preventDefault(); setLast("dialog: created"); setDialog("none"); }}
            actions={
              <>
                <DialogClose><Button>Cancel</Button></DialogClose>
                <Button type="submit" variant="primary">Create page</Button>
              </>
            }
          >
            <Field label="Title">
              <TextInput placeholder="e.g. Homepage" />
            </Field>
          </Dialog>
          <Dialog
            open={dialog === "plain"}
            onOpenChange={(o) => setDialog(o ? "plain" : "none")}
            title="Unsaved changes"
            description="Switching conversations discards them. Save or publish first to keep them."
            actions={
              <>
                <DialogClose><Button>Cancel</Button></DialogClose>
                <Button variant="primary" onClick={() => { setLast("dialog: discard & switch"); setDialog("none"); }}>
                  Discard &amp; switch
                </Button>
              </>
            }
          />
          <Dialog
            open={dialog === "danger"}
            onOpenChange={(o) => setDialog(o ? "danger" : "none")}
            title="Delete “Spring opening hours”?"
            description="The page and its 4 revisions are removed. This cannot be undone."
            dismissible={false}
            actions={
              <>
                <DialogClose><Button>Keep it</Button></DialogClose>
                <Button variant="danger" onClick={() => { setLast("dialog: deleted"); setDialog("none"); }}>
                  Delete for good
                </Button>
              </>
            }
          />
        </Specimen>

        <Specimen name="Skeleton" note="Real geometry, dim bars; the loading region carries aria-busy.">
          <div className="ain-kitgallery__skeleton" aria-busy="true">
            <Skeleton variant="avatar" />
            <div className="ain-kitgallery__skeletoncol">
              <Skeleton variant="short" className="ain-kitgallery__line" />
              <Skeleton variant="input" />
              <Skeleton variant="btn" />
            </div>
          </div>
        </Specimen>

        <Specimen name="Notice" note="A one-line outcome under the control that caused it. Success is announced politely, an error at once.">
          <div className="ain-kitgallery__col">
            <Notice tone="success">Snapshot frozen — 12 pages.</Notice>
            <Notice tone="error">The snapshot could not be written.</Notice>
          </div>
        </Specimen>

        <Specimen name="EmptyState" note="Say what will appear here and how to get it there. Not a loading state.">
          <div className="ain-kitgallery__stage">
            <EmptyState icon={<DocumentIcon />}>Nothing here yet — describe a page in the chat and it lands here.</EmptyState>
          </div>
          <div className="ain-kitgallery__stage">
            <EmptyState variant="stage" hint="Ask the agent to build a page, or add a section in the studio.">
              Your page preview appears here.
            </EmptyState>
          </div>
        </Specimen>

        <Specimen name="LoadingState" note="A browse list waiting for its first rows: the list at its real size, bars where the values land, nothing for the first 150ms.">
          <div className="ain-kitgallery__stage">
            <LoadingState label="Loading pages" rows={4} trailing />
          </div>
          <div className="ain-kitgallery__stage">
            <LoadingState label="Loading the shelf" rows={3} thumb />
          </div>
        </Specimen>

        <Specimen name="ComposingState" note="A preview canvas waiting for its render: the shape of a page (or one section), its bars pulsing like every skeleton; nothing for the first 150ms.">
          <div className="ain-kitgallery__stage">
            <ComposingState label="Rendering the page" />
          </div>
          <div className="ain-kitgallery__stage">
            <ComposingState label="Rendering the component" shape="section" />
          </div>
        </Specimen>

        <Specimen name="StudioStatus" note="The rail's draft-state line: unsaved changes, then the last Publish, then the resting line.">
          <div className="ain-kitgallery__col">
            <StudioStatus dirty={<>2 unsaved changes · preview only</>}>Matches the saved chrome</StudioStatus>
            <StudioStatus saved="Published — live on the site.">Matches the saved chrome</StudioStatus>
            <StudioStatus>Matches the saved chrome</StudioStatus>
          </div>
        </Specimen>

        <Specimen name="StudioGroup" note="One titled group in an editor rail: title, what it governs, then the fields.">
          <div className="ain-kitgallery__col">
            <StudioGroup title="Teaser card" note="How this page appears when it is referenced elsewhere as a card.">
              <Field label="Title">
                <TextInput placeholder="Falls back to the page title" />
              </Field>
            </StudioGroup>
            <StudioGroup title="Sections" actions={<Button size="sm">Expand all</Button>} />
          </div>
        </Specimen>
      </main>
    </div>
  );
}

const ZONES = [
  { value: "UTC", label: "UTC", group: null },
  ...["Amsterdam", "Berlin", "Lisbon", "London", "Madrid", "Paris", "Rome", "Vienna", "Warsaw", "Zurich"].map((c) => ({ value: `Europe/${c}`, label: c, group: "Europe" })),
  ...["Chicago", "Denver", "Los Angeles", "New York", "Toronto", "Vancouver"].map((c) => ({ value: `America/${c.replace(/ /g, "_")}`, label: c, group: "America" })),
  ...["Kolkata", "Singapore", "Tokyo"].map((c) => ({ value: `Asia/${c}`, label: c, group: "Asia" })),
];

/** The right-aligned button row at the foot of a popover card. */
function DialogFooterRow({ children }: { children: ReactNode }) {
  return <div className="ain-confirm__actions">{children}</div>;
}
