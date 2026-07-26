import { useState, useSyncExternalStore } from "react";
import { settings } from "./adapter";
import { apiUrl } from "./console-config";
import { settleNameInvite, shouldInviteName, subscribeNameInvite } from "./name-invite-state";
import { CheckIcon, XIcon } from "./icons";

/**
 * "What should we call you?" — asked once, after the studio has made something.
 *
 * The timing is the whole design; see name-invite.ts for why this is not a step
 * in onboarding. The FORM of it matters just as much:
 *
 *   - It is a strip above the composer, not a modal. The owner is looking at the
 *     page they just made; covering that to ask a favour would squander exactly
 *     the goodwill the moment created.
 *   - Dismissing is a peer of answering, not a grey afterthought — the question
 *     is genuinely optional and the copy says so without apologising for itself.
 *   - It never returns. Answered or waved off, it settles permanently, because a
 *     question this small has no business being asked twice.
 *   - A failed save is silent and settles anyway. The name is a courtesy; making
 *     someone confront an error dialog over it would invert its whole worth.
 */
export function NameInvite() {
  const invited = useSyncExternalStore(
    subscribeNameInvite,
    () => shouldInviteName(!!settings().viewer?.name),
    // The wizard path server-renders without a viewer; treat that as "no invite"
    // rather than throwing during hydration.
    () => false,
  );
  const [name, setName] = useState("");
  const [saving, setSaving] = useState(false);

  if (!invited) return null;

  const save = () => {
    const offered = name.trim();
    if (!offered) {
      settleNameInvite();
      return;
    }
    setSaving(true);
    // Best-effort and non-blocking. The server sanitises and the account pane can
    // always correct it later, so there is nothing here worth blocking on — and
    // nothing worth an error state either (see the docblock).
    void fetch(apiUrl("/account"), {
      method: "POST",
      credentials: "same-origin",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ name: offered }),
    })
      .catch(() => {})
      .finally(() => {
        setSaving(false);
        settleNameInvite();
      });
  };

  return (
    <div className="ain-nameinvite" role="group" aria-label="What should we call you?">
      <div className="ain-nameinvite__say">
        <span className="ain-nameinvite__lead">That’s yours.</span>{" "}
        <span className="ain-nameinvite__ask">What should we call you?</span>
      </div>
      <div className="ain-nameinvite__row">
        <input
          className="ain-nameinvite__input"
          type="text"
          value={name}
          onChange={(e) => setName(e.target.value)}
          onKeyDown={(e) => {
            if (e.key === "Enter") save();
            if (e.key === "Escape") settleNameInvite();
          }}
          placeholder="Your name"
          aria-label="Your name"
          autoComplete="name"
          maxLength={60}
          disabled={saving}
        />
        <button
          type="button"
          className="ain-btn ain-topbtn ain-topbtn--sm"
          onClick={save}
          disabled={saving || name.trim() === ""}
          title="Save your name"
        >
          <CheckIcon className="ain-nameinvite__icon" /> Save
        </button>
        <button
          type="button"
          className="ain-btn ain-nameinvite__dismiss"
          onClick={settleNameInvite}
          aria-label="No thanks — don’t ask again"
          title="No thanks — the studio won’t pretend to know you"
        >
          <XIcon className="ain-nameinvite__icon" />
        </button>
      </div>
    </div>
  );
}
