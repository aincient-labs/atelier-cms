import { useEffect, useMemo, useRef, useState } from "react";
import { createPortal } from "react-dom";
import { settings } from "./adapter";
import type { AincientSettings } from "./adapter";
import { CheckIcon, ChevronDownIcon, SpinnerIcon, XIcon } from "./icons";
import { apiUrl } from "./console-config";

/**
 * The in-console self-service "My Account" pane (DECISIONS 0157, Tier 2).
 *
 * A modal over the console shell — opened from the account flyout's identity
 * card — that lets the operator edit their own email, password, timezone and
 * avatar without ever landing on Drupal's /user/N/edit form. It is a THIN
 * client: every security decision (current-password re-auth, email/password
 * validation, upload limits) is enforced server-side by AccountController; this
 * only renders the fields and surfaces the per-field errors the API returns.
 *
 * The text fields (email/password/timezone) save together under one button.
 * Avatar upload/remove are immediate, dedicated calls (they move bytes, so they
 * can't ride a JSON body) with their own affordances. On any successful write
 * the server hands back a refreshed `viewer` card, which we fold into
 * `window.aincientChat.viewer` + notify the caller so the flyout updates live.
 */

type Grouped = Record<string, string | Record<string, string>>;

type AccountData = {
  /** The earned display name ('' until the owner offers one). */
  name: string;
  mail: string;
  timezone: string;
  avatarUrl: string | null;
  timezones: Grouped;
  avatarExtensions: string;
  requiresCurrentPassword: boolean;
  viewer: NonNullable<AincientSettings["viewer"]>;
};

type Errors = Record<string, string>;

/** Push a refreshed identity card into the shell config so the flyout re-reads it. */
function applyViewer(viewer: AccountData["viewer"]) {
  const w = window as unknown as { aincientChat?: AincientSettings };
  if (w.aincientChat) w.aincientChat.viewer = viewer;
}

/** A flat option carrying its group heading (for grouped rendering + filtering). */
type TzOption = { value: string; label: string; group: string | null };

/** Flatten the server's grouped timezone map into ordered options. */
function flattenTimezones(groups: Grouped): TzOption[] {
  const out: TzOption[] = [];
  for (const [key, value] of Object.entries(groups)) {
    if (typeof value === "string") out.push({ value: key, label: value, group: null });
    else for (const [zone, label] of Object.entries(value)) out.push({ value: zone, label, group: key });
  }
  return out;
}

/**
 * Timezone picker rendered with the console's own flyout (the `.ain-menu`
 * anatomy shared by the account + breadcrumb menus) rather than a native
 * `<select>` whose OS-drawn popup clashes with the pixel-pastel chrome. The
 * list is long and region-grouped, so it carries a type-to-filter box and
 * sticky group headings. The menu portals into the console root with fixed
 * positioning (computed from the trigger rect) so it layers above the modal
 * overlay and never clips inside the modal's own scroll container.
 */
function TimezoneSelect({
  options,
  value,
  onChange,
}: {
  options: TzOption[];
  value: string;
  onChange: (tz: string) => void;
}) {
  const [open, setOpen] = useState(false);
  const [query, setQuery] = useState("");
  const [pos, setPos] = useState({ top: 0, left: 0, width: 0 });
  const rootRef = useRef<HTMLDivElement>(null);
  const btnRef = useRef<HTMLButtonElement>(null);
  const menuRef = useRef<HTMLDivElement>(null);
  const filterRef = useRef<HTMLInputElement>(null);

  const current = options.find((o) => o.value === value);
  const filtered = useMemo(() => {
    const q = query.trim().toLowerCase();
    if (!q) return options;
    return options.filter(
      (o) => o.label.toLowerCase().includes(q) || o.value.toLowerCase().includes(q) || (o.group ?? "").toLowerCase().includes(q),
    );
  }, [options, query]);

  const optEls = () => Array.from(menuRef.current?.querySelectorAll<HTMLElement>('[role="option"]') ?? []);

  const place = () => {
    const r = btnRef.current?.getBoundingClientRect();
    if (r) setPos({ top: r.bottom + 4, left: r.left, width: r.width });
  };

  const openMenu = () => {
    place();
    setQuery("");
    setOpen(true);
  };

  // On open: focus the filter so you can type-to-narrow immediately.
  useEffect(() => {
    if (open) filterRef.current?.focus();
  }, [open]);

  // Dismiss on outside pointer / Escape / scroll of anything but the menu.
  useEffect(() => {
    if (!open) return;
    const onPointer = (e: PointerEvent) => {
      const t = e.target as Node;
      if (rootRef.current?.contains(t) || menuRef.current?.contains(t)) return;
      setOpen(false);
    };
    const onScroll = (e: Event) => {
      if (menuRef.current?.contains(e.target as Node)) return;
      setOpen(false);
    };
    document.addEventListener("pointerdown", onPointer, true);
    document.addEventListener("scroll", onScroll, true);
    return () => {
      document.removeEventListener("pointerdown", onPointer, true);
      document.removeEventListener("scroll", onScroll, true);
    };
  }, [open]);

  const choose = (tz: string) => {
    onChange(tz);
    setOpen(false);
    btnRef.current?.focus();
  };

  const onFilterKeyDown = (e: React.KeyboardEvent) => {
    if (e.key === "Escape") {
      e.preventDefault();
      setOpen(false);
      btnRef.current?.focus();
    } else if (e.key === "ArrowDown") {
      e.preventDefault();
      optEls()[0]?.focus();
    } else if (e.key === "Enter") {
      e.preventDefault();
      if (filtered.length) choose(filtered[0].value);
    }
  };

  const onOptionKeyDown = (e: React.KeyboardEvent) => {
    const list = optEls();
    const idx = list.indexOf(document.activeElement as HTMLElement);
    switch (e.key) {
      case "Escape":
        e.preventDefault();
        setOpen(false);
        btnRef.current?.focus();
        break;
      case "ArrowDown":
        e.preventDefault();
        (list[idx + 1] ?? list[0])?.focus();
        break;
      case "ArrowUp":
        e.preventDefault();
        if (idx <= 0) filterRef.current?.focus();
        else list[idx - 1]?.focus();
        break;
      case "Home":
        e.preventDefault();
        list[0]?.focus();
        break;
      case "End":
        e.preventDefault();
        list[list.length - 1]?.focus();
        break;
    }
  };

  return (
    <div className="ain-selfield" ref={rootRef}>
      <button
        ref={btnRef}
        type="button"
        className="ain-btn ain-selfield__trigger"
        data-open={open || undefined}
        aria-haspopup="listbox"
        aria-expanded={open}
        onClick={() => (open ? setOpen(false) : openMenu())}
        onKeyDown={(e) => {
          if (!open && (e.key === "ArrowDown" || e.key === "Enter" || e.key === " ")) {
            e.preventDefault();
            openMenu();
          }
        }}
      >
        <span className="ain-selfield__label">{current?.label ?? value}</span>
        <ChevronDownIcon className="ain-selfield__caret" aria-hidden />
      </button>
      {open &&
        createPortal(
          <div
            ref={menuRef}
            className="ain-menu ain-selfield__menu"
            role="listbox"
            aria-label="Timezone"
            style={{ top: pos.top, left: pos.left, minWidth: pos.width }}
          >
            <div className="ain-selfield__filter">
              <input
                ref={filterRef}
                className="ain-field__input"
                type="text"
                value={query}
                placeholder="Filter timezones…"
                onChange={(e) => setQuery(e.target.value)}
                onKeyDown={onFilterKeyDown}
                aria-label="Filter timezones"
              />
            </div>
            {filtered.length === 0 && <p className="ain-selfield__empty">No match.</p>}
            {filtered.map((o, i) => {
              const showGroup = o.group && (i === 0 || filtered[i - 1].group !== o.group);
              return (
                <div key={o.value}>
                  {showGroup && <div className="ain-selfield__group">{o.group}</div>}
                  <button
                    type="button"
                    className="ain-menu__item ain-crumb__option"
                    role="option"
                    aria-selected={o.value === value}
                    onClick={() => choose(o.value)}
                    onKeyDown={onOptionKeyDown}
                  >
                    <span className="ain-crumb__check" aria-hidden>{o.value === value && <CheckIcon />}</span>
                    {o.label}
                  </button>
                </div>
              );
            })}
          </div>,
          document.getElementById("aincient-chat-root") ?? document.body,
        )}
    </div>
  );
}

export function AccountPane({
  onClose,
  onViewerChange,
}: {
  onClose: () => void;
  onViewerChange: () => void;
}) {
  const [data, setData] = useState<AccountData | null>(null);
  const [loadError, setLoadError] = useState<string | null>(null);

  // Editable fields.
  const [name, setName] = useState("");
  const [mail, setMail] = useState("");
  const [timezone, setTimezone] = useState("");
  const [newPass, setNewPass] = useState("");
  const [confirmPass, setConfirmPass] = useState("");
  const [currentPass, setCurrentPass] = useState("");
  const [avatarUrl, setAvatarUrl] = useState<string | null>(null);

  const [errors, setErrors] = useState<Errors>({});
  const [saving, setSaving] = useState(false);
  const [avatarBusy, setAvatarBusy] = useState(false);
  const [saved, setSaved] = useState(false);

  const fileRef = useRef<HTMLInputElement>(null);
  const closeRef = useRef<HTMLButtonElement>(null);

  // Load the current values when the pane opens.
  useEffect(() => {
    let live = true;
    fetch(apiUrl("/account"), { credentials: "same-origin" })
      .then((r) => (r.ok ? r.json() : Promise.reject(new Error(`HTTP ${r.status}`))))
      .then((d: AccountData) => {
        if (!live) return;
        setData(d);
        setName(d.name ?? "");
        setMail(d.mail ?? "");
        setTimezone(d.timezone ?? "");
        setAvatarUrl(d.avatarUrl ?? null);
      })
      .catch((e) => live && setLoadError(e instanceof Error ? e.message : String(e)));
    return () => {
      live = false;
    };
  }, []);

  useEffect(() => {
    closeRef.current?.focus();
  }, [data]);

  useEffect(() => {
    const onKey = (e: KeyboardEvent) => {
      if (e.key === "Escape") onClose();
    };
    document.addEventListener("keydown", onKey);
    return () => document.removeEventListener("keydown", onKey);
  }, [onClose]);

  const nameChanged = data != null && name.trim() !== data.name;
  const mailChanged = data != null && mail.trim() !== data.mail;
  const passChanged = newPass !== "";
  const tzChanged = data != null && timezone !== data.timezone;
  // The "Confirm it's you" group appears the moment a protected field (email /
  // password) is touched — the server demands the current password, so the
  // form asks VISIBLY instead of surprising at save (study 02, Plate 15).
  const needsCurrentPass = !!data?.requiresCurrentPassword && (mailChanged || passChanged);
  const dirty = nameChanged || mailChanged || passChanged || tzChanged;

  const save = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!data || !dirty || saving) return;
    setSaved(false);

    // The one thing the client owns: the two password boxes must agree. (The
    // server never sees a mismatch — it only takes a single new password.)
    if (passChanged && newPass !== confirmPass) {
      setErrors({ confirmPass: "The two passwords don’t match." });
      return;
    }

    setSaving(true);
    setErrors({});
    const body: Record<string, string> = {};
    if (nameChanged) body.name = name.trim();
    if (mailChanged) body.mail = mail.trim();
    if (tzChanged) body.timezone = timezone;
    if (passChanged) body.newPass = newPass;
    if (needsCurrentPass) body.currentPass = currentPass;

    try {
      const res = await fetch(apiUrl("/account"), {
        method: "POST",
        credentials: "same-origin",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify(body),
      });
      const payload = (await res.json().catch(() => null)) as
        | { ok?: boolean; mail?: string; timezone?: string; viewer?: AccountData["viewer"]; errors?: Errors }
        | null;
      if (!res.ok || !payload?.ok) {
        setErrors(payload?.errors ?? { form: `Couldn’t save (HTTP ${res.status}).` });
        return;
      }
      // Success: reset the transient password boxes, refresh the card, confirm.
      setNewPass("");
      setConfirmPass("");
      setCurrentPass("");
      // The server may have sanitized the name away (paste-accident guard) —
      // reflect what was actually stored.
      const storedName = (payload as { name?: string }).name ?? name.trim();
      setName(storedName);
      setData({ ...data, name: storedName, mail: payload.mail ?? mail, timezone: payload.timezone ?? timezone });
      if (payload.viewer) {
        applyViewer(payload.viewer);
        onViewerChange();
      }
      setSaved(true);
    } catch (err) {
      setErrors({ form: err instanceof Error ? err.message : String(err) });
    } finally {
      setSaving(false);
    }
  };

  const onPickAvatar = async (e: React.ChangeEvent<HTMLInputElement>) => {
    const file = e.target.files?.[0];
    e.target.value = ""; // allow re-picking the same file
    if (!file) return;
    setAvatarBusy(true);
    setErrors((x) => ({ ...x, avatar: "", user_picture: "" }));
    try {
      const form = new FormData();
      form.append("file", file);
      const res = await fetch(apiUrl("/account/avatar"), {
        method: "POST",
        credentials: "same-origin",
        body: form,
      });
      const payload = (await res.json().catch(() => null)) as
        | { ok?: boolean; avatarUrl?: string | null; viewer?: AccountData["viewer"]; errors?: Errors; error?: string }
        | null;
      if (!res.ok || !payload?.ok) {
        setErrors((x) => ({ ...x, ...(payload?.errors ?? { avatar: payload?.error ?? `Upload failed (HTTP ${res.status}).` }) }));
        return;
      }
      setAvatarUrl(payload.avatarUrl ?? null);
      if (payload.viewer) {
        applyViewer(payload.viewer);
        onViewerChange();
      }
    } catch (err) {
      setErrors((x) => ({ ...x, avatar: err instanceof Error ? err.message : String(err) }));
    } finally {
      setAvatarBusy(false);
    }
  };

  const removeAvatar = async () => {
    setAvatarBusy(true);
    setErrors((x) => ({ ...x, avatar: "" }));
    try {
      const res = await fetch(apiUrl("/account/avatar"), { method: "DELETE", credentials: "same-origin" });
      const payload = (await res.json().catch(() => null)) as
        | { ok?: boolean; viewer?: AccountData["viewer"] }
        | null;
      if (res.ok && payload?.ok) {
        setAvatarUrl(null);
        if (payload.viewer) {
          applyViewer(payload.viewer);
          onViewerChange();
        }
      }
    } finally {
      setAvatarBusy(false);
    }
  };

  const initial = data?.viewer.initial ?? settings().viewer?.initial ?? "?";

  return createPortal(
    <div
      className="ain-confirm__overlay"
      role="presentation"
      onClick={(e) => {
        if (e.target === e.currentTarget) onClose();
      }}
    >
      <form className="ain-confirm ain-account" role="dialog" aria-modal="true" aria-label="My account" onSubmit={save}>
        <div className="ain-account__head">
          <h2 className="ain-account__title">My account</h2>
          <button ref={closeRef} type="button" className="ain-btn ain-pop__close" onClick={onClose} aria-label="Close" title="Close (Esc)">
            <XIcon />
          </button>
        </div>

        {loadError && (
          <p className="ain-account__error" role="alert">
            Couldn’t load your account ({loadError}).
          </p>
        )}

        {/* Law 09 — Paper before ink. Before the values land the pane arrives at
            its real size with labels set and value bars as dim placeholders — never
            a header floating over nothing, no layout jump on populate. The reveal is
            delayed 150ms in CSS so a fast load never flashes the skeleton. */}
        {!data && !loadError && (
          <div className="ain-account__skeleton" aria-hidden="true">
            <div className="ain-field">
              <span className="ain-field__label">
                <span className="ain-field__labeltext">Name</span>
              </span>
              <span className="ain-skeleton ain-skeleton--input" />
            </div>
            <div className="ain-account__avatarrow">
              <span className="ain-skeleton ain-skeleton--avatar" />
              <span className="ain-skeleton ain-skeleton--btn" />
            </div>
            <div className="ain-field">
              <span className="ain-field__label">
                <span className="ain-field__labeltext">Email</span>
              </span>
              <span className="ain-skeleton ain-skeleton--input" />
            </div>
            <div className="ain-field">
              <span className="ain-field__label">
                <span className="ain-field__labeltext">Timezone</span>
              </span>
              <span className="ain-skeleton ain-skeleton--input" />
            </div>
            <div className="ain-account__pw">
              <div className="ain-account__pwlegend">Change password</div>
              <div className="ain-field">
                <span className="ain-field__label">
                  <span className="ain-field__labeltext">New password</span>
                </span>
                <span className="ain-skeleton ain-skeleton--input" />
              </div>
            </div>
          </div>
        )}

        {data && (
          <>
            {/* The Name field leads (Plate 15): this is where the EARNED name
                (the onboarding "What should we call you?") lives and is edited. */}
            <label className="ain-field">
              <span className="ain-field__label">
                <span className="ain-field__labeltext">Name · optional</span>
              </span>
              <input
                className="ain-field__input"
                type="text"
                value={name}
                onChange={(e) => setName(e.target.value)}
                autoComplete="name"
                placeholder="What should we call you?"
                maxLength={60}
              />
              {/* No hint: "· optional" + the placeholder already say it all —
                  explaining the obvious is noise (Plate 15 feedback). */}
              {errors.name && (
                <span className="ain-field__error" role="alert">
                  {errors.name}
                </span>
              )}
            </label>

            {/* Avatar — immediate upload / remove; QUIET, TEXT-ONLY actions
                (the upload/trash glyphs belonged to the plate's "as shipped"
                autopsy — the cut is words alone). Format specs appear only
                when a file is refused — the server's error names them. */}
            <div className="ain-account__avatarrow">
              {avatarUrl ? (
                <img className="ain-account__avatar ain-account__avatar--img" src={avatarUrl} alt="" />
              ) : (
                <span className="ain-account__avatar" aria-hidden>
                  {initial}
                </span>
              )}
              <div className="ain-account__avatarctl">
                <div className="ain-account__avatarbtns">
                  <button
                    type="button"
                    className="ain-btn ain-topbtn ain-topbtn--quiet"
                    onClick={() => fileRef.current?.click()}
                    disabled={avatarBusy}
                  >
                    {avatarBusy && <SpinnerIcon />}
                    <span>{avatarUrl ? "Replace photo" : "Add a photo"}</span>
                  </button>
                  {avatarUrl && (
                    <button type="button" className="ain-btn ain-topbtn ain-topbtn--quiet" onClick={removeAvatar} disabled={avatarBusy}>
                      <span>Remove</span>
                    </button>
                  )}
                </div>
                {errors.avatar && (
                  <p className="ain-field__error" role="alert">
                    {errors.avatar}
                  </p>
                )}
                {errors.user_picture && (
                  <p className="ain-field__error" role="alert">
                    {errors.user_picture}
                  </p>
                )}
              </div>
              <input ref={fileRef} type="file" accept="image/*" hidden onChange={onPickAvatar} />
            </div>

            <label className="ain-field">
              <span className="ain-field__label">
                <span className="ain-field__labeltext">Email</span>
              </span>
              <input
                className="ain-field__input"
                type="email"
                value={mail}
                onChange={(e) => setMail(e.target.value)}
                autoComplete="email"
              />
              {errors.mail && (
                <span className="ain-field__error" role="alert">
                  {errors.mail}
                </span>
              )}
            </label>

            <label className="ain-field">
              <span className="ain-field__label">
                <span className="ain-field__labeltext">Timezone</span>
              </span>
              <TimezoneSelect options={flattenTimezones(data.timezones)} value={timezone} onChange={setTimezone} />
              {errors.timezone && (
                <span className="ain-field__error" role="alert">
                  {errors.timezone}
                </span>
              )}
            </label>

            <div className="ain-account__pw">
              <div className="ain-account__pwlegend">Change password</div>
              <label className="ain-field">
                <span className="ain-field__label">
                  <span className="ain-field__labeltext">New password</span>
                </span>
                <input
                  className="ain-field__input"
                  type="password"
                  value={newPass}
                  onChange={(e) => setNewPass(e.target.value)}
                  autoComplete="new-password"
                />
                {/* Rules live in help text, permanently visible — never in a
                    placeholder that vanishes on the first keystroke (Plate 15). */}
                <span className="ain-field__hint">Leave blank to keep your current password.</span>
                {errors.pass && (
                  <span className="ain-field__error" role="alert">
                    {errors.pass}
                  </span>
                )}
              </label>
              {passChanged && (
                <label className="ain-field">
                  <span className="ain-field__label">
                    <span className="ain-field__labeltext">Confirm new password</span>
                  </span>
                  <input
                    className="ain-field__input"
                    type="password"
                    value={confirmPass}
                    onChange={(e) => setConfirmPass(e.target.value)}
                    autoComplete="new-password"
                  />
                  {errors.confirmPass && (
                    <span className="ain-field__error" role="alert">
                      {errors.confirmPass}
                    </span>
                  )}
                </label>
              )}
            </div>

            {needsCurrentPass && (
              <div className="ain-account__reauth">
                <label className="ain-field">
                  <span className="ain-field__label">
                    <span className="ain-field__labeltext">Confirm it’s you</span>
                  </span>
                  <input
                    className="ain-field__input"
                    type="password"
                    value={currentPass}
                    onChange={(e) => setCurrentPass(e.target.value)}
                    autoComplete="current-password"
                    placeholder="Current password"
                  />
                  <span className="ain-field__hint">Required when changing your email or password.</span>
                  {errors.currentPass && (
                    <span className="ain-field__error" role="alert">
                      {errors.currentPass}
                    </span>
                  )}
                </label>
              </div>
            )}

            {errors.form && (
              <p className="ain-account__error" role="alert">
                {errors.form}
              </p>
            )}

            <div className="ain-confirm__actions">
              {saved && !dirty && (
                <span className="ain-account__saved" role="status">
                  Saved.
                </span>
              )}
              {/* Close is QUIET (plate footer: quiet Close · primary Save) —
                  a raised Close reads same-weight as a resting Save. */}
              <button type="button" className="ain-btn ain-topbtn ain-topbtn--quiet" onClick={onClose} disabled={saving}>
                Close
              </button>
              <button type="submit" className="ain-btn ain-topbtn ain-topbtn--primary" disabled={!dirty || saving}>
                {saving ? "Saving…" : "Save changes"}
              </button>
            </div>
          </>
        )}
      </form>
    </div>,
    // Portal within the console root (not document.body): the design tokens
    // (--ain-surface, --ain-shadow, …) are scoped to #aincient-chat-root, so a
    // document.body portal would render unstyled/transparent. The root creates
    // no containing block, so the overlay's position:fixed stays viewport-anchored.
    document.getElementById("aincient-chat-root") ?? document.body,
  );
}
