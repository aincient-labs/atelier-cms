import { Button } from "./button";
import { ChevronDownIcon } from "./icons";
import { Menu, MenuItem } from "./menu";
import { useMediaQuery } from "./studio-ui";

/** The width at and below which Discard folds into the Publish menu (audit N3). */
export const PHONE_QUERY = "(max-width: 767px)";

/** True on a phone-width top bar, where Discard lives in the Publish menu. */
export function usePhoneTopBar(): boolean {
  return useMediaQuery(PHONE_QUERY);
}

/**
 * A studio's draft verbs in the top bar: the unsaved-change count, Discard and
 * Publish. On a phone-width bar Discard folds into a menu on Publish's caret, so
 * the studio switcher keeps room for its name (audit N3).
 *
 * `canPublish` blocks Publish alone (a pending dry-run, say); Discard follows `dirty`.
 */
export function DraftActions({
  dirty,
  publishing,
  canPublish = true,
  onDiscard,
  onPublish,
  discardTitle = "Discard draft — revert to the saved version",
  publishTitle = "Publish the draft to the live site",
}: {
  dirty: number;
  publishing: boolean;
  canPublish?: boolean;
  onDiscard: () => void;
  onPublish: () => void;
  discardTitle?: string;
  publishTitle?: string;
}) {
  const phone = usePhoneTopBar();
  const idle = dirty === 0 || publishing;
  const publish = (
    <Button variant="primary" onClick={onPublish} disabled={idle || !canPublish} title={publishTitle}>
      {publishing ? "Publishing…" : "Publish"}
    </Button>
  );
  return (
    <>
      {dirty > 0 && (
        <span className="ain-studio-actions__dirty" title={`${dirty} unsaved change${dirty === 1 ? "" : "s"}`}>
          {dirty}
        </span>
      )}
      {phone ? (
        <span className="ain-split">
          {publish}
          <Menu
            label="More draft actions"
            trigger={
              <Button variant="primary" aria-label="More draft actions" title="More draft actions" disabled={idle}>
                <ChevronDownIcon />
              </Button>
            }
          >
            <MenuItem danger onSelect={onDiscard} disabled={idle}>
              Discard changes
            </MenuItem>
          </Menu>
        </span>
      ) : (
        <>
          <Button onClick={onDiscard} disabled={idle} title={discardTitle}>
            Discard
          </Button>
          {publish}
        </>
      )}
    </>
  );
}
