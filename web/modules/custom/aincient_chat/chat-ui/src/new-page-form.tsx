import { useEffect, useRef, useState } from "react";
import { createPortal } from "react-dom";
import { createPage } from "./page-state";
import { XIcon } from "./icons";

/**
 * The `+` birth form (studio-navigation.md §3.2 — Phase C).
 *
 * A DELIBERATE create: language + type + title, all resolved before the node is
 * minted. Requiring a title + type is what buys "no stubs, no garbage collection"
 * (§3.2) — every node that exists is one the user meant to create. On submit it
 * mints a real draft node and hands the caller its nid; the caller navigates to
 * the new Node room, where the load path takes the editor lock and a fresh thread
 * homes to the node on its first turn.
 *
 * The `type` field is the composition-contract seam (composition-contracts.md
 * §4.11 — "template selection = by page type"). v1 exposes only `landing` while
 * the template/ruleset layer is parked; a type resolves to its design template
 * server-side once that lands, so this form stays forward-compatible — grow
 * {@link PAGE_TYPES} as the type set is ratified.
 */

/** One site language (mirrors the page-studio manifest shape). */
type Lang = { id: string; label: string; default: boolean };
type Manifest = { translation?: { languages?: Lang[]; multilingual?: boolean } };

/** The page types the birth form offers (the contract selector). */
const PAGE_TYPES: { id: string; label: string; hint: string }[] = [
  { id: "landing", label: "Landing page", hint: "A composed page — hero, sections, and calls to action." },
];

export function NewPageForm({
  onClose,
  onCreated,
}: {
  onClose: () => void;
  onCreated: (nid: string, langcode: string | null, title: string) => void;
}) {
  const [title, setTitle] = useState("");
  const [type, setType] = useState(PAGE_TYPES[0].id);
  const [langs, setLangs] = useState<Lang[]>([]);
  const [multilingual, setMultilingual] = useState(false);
  // null = the site's source/default language (a monolingual site never sends one).
  const [lang, setLang] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const titleRef = useRef<HTMLInputElement>(null);

  // Site languages drive the language field, shown only on a multilingual site —
  // elsewhere the one language IS the source, so the field is noise. Same manifest
  // the page studio's language switcher reads.
  useEffect(() => {
    let live = true;
    fetch("/aincient/page/manifest", { credentials: "same-origin" })
      .then((r) => (r.ok ? r.json() : null))
      .then((m: Manifest | null) => {
        if (!live || !m) return;
        setLangs(m.translation?.languages ?? []);
        setMultilingual(!!m.translation?.multilingual);
      })
      .catch(() => {});
    return () => {
      live = false;
    };
  }, []);

  useEffect(() => {
    titleRef.current?.focus();
  }, []);

  useEffect(() => {
    const onKey = (e: KeyboardEvent) => {
      if (e.key === "Escape") onClose();
    };
    document.addEventListener("keydown", onKey);
    return () => document.removeEventListener("keydown", onKey);
  }, [onClose]);

  const sourceLang = langs.find((l) => l.default);
  const canSubmit = title.trim().length > 0 && !busy;

  const submit = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!canSubmit) return;
    setBusy(true);
    setError(null);
    try {
      // Monolingual → always the default (source): send null. Multilingual → the
      // picked langcode, unless it's the default (which is the source anyway).
      const langcode = multilingual && lang && lang !== sourceLang?.id ? lang : null;
      const nid = await createPage(title.trim(), type, langcode);
      onCreated(nid, langcode, title.trim());
    } catch (err) {
      setError(err instanceof Error ? err.message : String(err));
      setBusy(false);
    }
  };

  const activeType = PAGE_TYPES.find((t) => t.id === type) ?? PAGE_TYPES[0];

  return createPortal(
    <div
      className="ain-confirm__overlay"
      role="presentation"
      onClick={(e) => {
        if (e.target === e.currentTarget) onClose();
      }}
    >
      <form
        className="ain-confirm ain-newpage"
        role="dialog"
        aria-modal="true"
        aria-label="New page"
        onSubmit={submit}
      >
        <div className="ain-newpage__head">
          <h2 className="ain-newpage__title">New page</h2>
          <button
            type="button"
            className="ain-btn ain-pop__close"
            onClick={onClose}
            aria-label="Close"
            title="Close (Esc)"
          >
            <XIcon />
          </button>
        </div>

        <label className="ain-field">
          <span className="ain-field__label">
            <span className="ain-field__labeltext">Title</span>
          </span>
          <input
            ref={titleRef}
            className="ain-field__input"
            type="text"
            value={title}
            onChange={(e) => setTitle(e.target.value)}
            placeholder="e.g. Homepage"
            maxLength={255}
            required
          />
        </label>

        <div className="ain-field">
          <span className="ain-field__label">
            <span className="ain-field__labeltext">Type</span>
          </span>
          {PAGE_TYPES.length > 1 ? (
            <select
              className="ain-field__input"
              value={type}
              onChange={(e) => setType(e.target.value)}
            >
              {PAGE_TYPES.map((t) => (
                <option key={t.id} value={t.id}>
                  {t.label}
                </option>
              ))}
            </select>
          ) : (
            <div className="ain-newpage__type">
              <span className="ain-newpage__typename">{activeType.label}</span>
              <span className="ain-newpage__typehint">{activeType.hint}</span>
            </div>
          )}
          <p className="ain-newpage__note">More page types arrive with design templates.</p>
        </div>

        {multilingual && langs.length > 0 && (
          <label className="ain-field">
            <span className="ain-field__label">
              <span className="ain-field__labeltext">Language</span>
            </span>
            <select
              className="ain-field__input"
              value={lang ?? sourceLang?.id ?? ""}
              onChange={(e) => {
                const v = e.target.value;
                setLang(sourceLang && v === sourceLang.id ? null : v);
              }}
            >
              {langs.map((l) => (
                <option key={l.id} value={l.id}>
                  {l.label}
                  {l.default ? " (default)" : ""}
                </option>
              ))}
            </select>
          </label>
        )}

        {error && (
          <p className="ain-newpage__error" role="alert">
            {error}
          </p>
        )}

        <div className="ain-confirm__actions">
          <button type="button" className="ain-btn ain-topbtn" onClick={onClose} disabled={busy}>
            Cancel
          </button>
          <button type="submit" className="ain-btn ain-topbtn ain-topbtn--primary" disabled={!canSubmit}>
            {busy ? "Creating…" : "Create page"}
          </button>
        </div>
      </form>
    </div>,
    // Portal within the console root, NOT document.body: the --ain-* tokens are
    // scoped to #aincient-chat-root, so a body portal renders the card
    // transparent/unstyled (same gotcha as the account pane, DECISIONS 0158).
    document.getElementById("aincient-chat-root") ?? document.body,
  );
}
