import { useMemo, useState } from "react";
import { Button } from "./kit/button";
import { Dialog } from "./kit/dialog";
import { ChevronDownIcon } from "./kit/icons";
import { Menu, MenuItem, MenuLabel, MenuSeparator } from "./kit/menu";
import { carriesEdits, planLifecycle } from "./page-lifecycle";
import type { Moderation, Transition } from "./page-state";

/**
 * The page's lifecycle in the top bar (DECISIONS 0454): the state chip, Save
 * draft, and ONE primary transition with a menu for the rest. Every studio that
 * edits a page renders this same bar through `StudioActionsPortal`, so the
 * buttons that move the page sit in the same place and read the same way in
 * Content and in Checks. A studio's own verbs (Checks' Re-run) live in its rail,
 * never here.
 *
 * Presentational: the studio owns the writes (its notices, wrap-up, re-audit)
 * and passes them in. The ordering is {@link planLifecycle}'s, so a site-built
 * workflow with a dozen transitions takes the same space as the shipped one.
 */
export type PageLifecycleBarProps = {
  moderation: Moderation;
  docNoun?: "page" | "block";
  /** A never-saved draft: its one action is the first Publish. */
  isNew?: boolean;
  dirty: boolean;
  /** The chip's qualifier while dirty ("unsaved changes", "fix staged"). */
  dirtyLabel?: string;
  /** A write is in flight — every control waits. */
  busy: boolean;
  /** The user may write the draft (holds the lock, not sealed, has access). */
  canWrite: boolean;
  /** There is something to save / publish (a bare empty draft isn't). */
  hasContent?: boolean;
  /** Why the page is in this state / what to do — the chip's tooltip. */
  note?: string | null;
  /** Omit to offer no Discard. */
  onDiscard?: () => void;
  onSaveDraft: () => void;
  /** Save the draft and go live (`/publish`). */
  onPublish: () => void;
  /** Run a pure transition; `saveFirst` = carry the unsaved edits with it. */
  onTransition: (t: Transition, opts: { saveFirst: boolean }) => void;
};

export function PageLifecycleBar({
  moderation,
  docNoun = "page",
  isNew = false,
  dirty,
  dirtyLabel = "unsaved changes",
  busy,
  canWrite,
  hasContent = true,
  note,
  onDiscard,
  onSaveDraft,
  onPublish,
  onTransition,
}: PageLifecycleBarProps) {
  const plan = useMemo(() => planLifecycle(moderation, { isNew, dirty }), [moderation, isNew, dirty]);
  const [confirming, setConfirming] = useState<Transition | null>(null);

  // "Live" is the owner's word for published (study 02, Plate 5; 0451).
  const pending = moderation.hasPendingDraft;
  const stateText = isNew ? "New" : moderation.statePublished || pending ? "Live" : moderation.stateLabel;
  const qualifier = dirty && canWrite ? dirtyLabel : pending ? "draft pending" : null;

  const run = (t: Transition) => onTransition(t, { saveFirst: dirty && canWrite && carriesEdits(plan, t, moderation) });
  const groups = [
    { key: "forward", label: "Move forward", items: plan.forward, danger: false },
    { key: "back", label: "Send back", items: plan.back, danger: false },
    { key: "offline", label: "Take offline", items: plan.offline, danger: true },
  ].filter((g) => g.items.length > 0);
  // A single group needs no heading — "Move forward" over one item is noise.
  const labelled = groups.length > 1;

  const menuItems = groups.map((g, i) => (
    <div key={g.key} role="group" aria-label={g.label}>
      {i > 0 && <MenuSeparator />}
      {labelled && <MenuLabel>{g.label}</MenuLabel>}
      {g.items.map((t) => (
        <MenuItem key={t.id} danger={g.danger} disabled={busy} onSelect={() => (g.danger ? setConfirming(t) : run(t))}>
          <span>{t.label}</span>
          <span className="ain-lifecycle__to">→ {t.to_label}</span>
        </MenuItem>
      ))}
    </div>
  ));

  const primary = plan.primary;
  const primaryButton = primary ? (
    primary.kind === "publish" ? (
      <Button
        variant="primary"
        onClick={onPublish}
        disabled={busy || !hasContent || (!canWrite && !isNew)}
        title={docNoun === "block" ? "Publish the block — goes live everywhere it’s used" : "Publish — make this the live page"}
      >
        {busy ? "Publishing…" : "Publish"}
      </Button>
    ) : (
      <Button
        variant="primary"
        onClick={() => run(primary.transition)}
        disabled={busy}
        title={`${primary.transition.label} → ${primary.transition.to_label}`}
      >
        {/* The shipped Approve lands live: say so, as the bar always has. */}
        {primary.transition.id === "approve" ? "Approve & publish" : primary.transition.label}
      </Button>
    )
  ) : null;

  return (
    <span className="ain-lifecycle" data-testid="page-lifecycle">
      <span
        className="ain-studio__statebadge ain-lifecycle__state"
        data-state={pending ? "published" : moderation.state}
        data-pending={pending || undefined}
        title={note ?? undefined}
      >
        {stateText}
        {qualifier && <span className="ain-studio__statebadge-sub"> · {qualifier}</span>}
      </span>
      {onDiscard && dirty && canWrite && (
        <Button variant="quiet" onClick={onDiscard} disabled={busy} title="Discard draft — revert to the last saved version">
          Discard
        </Button>
      )}
      {canWrite && (
        <Button onClick={onSaveDraft} disabled={!dirty || busy || !hasContent} title="Save your changes as a draft — not live yet">
          {busy ? "Working…" : "Save draft"}
        </Button>
      )}
      {primaryButton && groups.length > 0 ? (
        <span className="ain-lifecycle__split">
          {primaryButton}
          <Menu
            label="More workflow actions"
            trigger={
              <Button variant="primary" aria-label="More workflow actions" title="More workflow actions" disabled={busy}>
                <ChevronDownIcon />
              </Button>
            }
          >
            {menuItems}
          </Menu>
        </span>
      ) : primaryButton ? (
        primaryButton
      ) : groups.length > 0 ? (
        <Menu
          label="Workflow actions"
          trigger={
            <Button disabled={busy}>
              Move to… <ChevronDownIcon />
            </Button>
          }
        >
          {menuItems}
        </Menu>
      ) : null}
      <Dialog
        open={confirming !== null}
        onOpenChange={(open) => !open && setConfirming(null)}
        title={confirming ? `${confirming.label} this ${docNoun}?` : undefined}
        description={
          confirming
            ? `It moves to ${confirming.to_label} and comes off the live site.`
            : undefined
        }
        actions={
          <>
            <Button onClick={() => setConfirming(null)}>Cancel</Button>
            <Button
              variant="danger"
              onClick={() => {
                const t = confirming;
                setConfirming(null);
                if (t) run(t);
              }}
            >
              {confirming?.label ?? "Confirm"}
            </Button>
          </>
        }
      />
    </span>
  );
}
