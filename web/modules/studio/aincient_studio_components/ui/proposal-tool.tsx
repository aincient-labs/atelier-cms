import { useEffect, useState } from "react";
import { makeSafeAssistantToolUI } from "@console/kit";
import { consoleNav, ensureStudio } from "@console/sdk";
import { SITE_SCOPE } from "./constraint-model";
import {
  getComponentsState,
  loadKind,
  loadManifest,
  setDraft,
  setKindDraft,
  setScope,
} from "./components-store";
import { applyKindProposal, applySiteProposal, proposalSize, type ComponentProposal } from "./proposal";

/**
 * The Components agent's chat card (DECISIONS 0455, P2) — the chrome_preview
 * pattern. `propose_component_constraint` emits a `components_proposal`
 * envelope; this card opens the studio on the proposal's scope and MERGES the
 * operations into that scope's staged draft (once per tool call). Nothing is
 * written: the owner reviews the rail and the impact list, then publishes.
 */

/** Tool calls already merged this page-session (de-dupe across re-renders). */
const applied = new Set<string>();

/** Merge one proposal into the store, loading whatever the scope needs first. */
async function adopt(p: ComponentProposal): Promise<string[]> {
  await loadManifest();
  const scope = p.scope || SITE_SCOPE;
  if (scope !== SITE_SCOPE) await loadKind(scope);
  setScope(scope);
  const s = getComponentsState();
  const entries = s.manifest?.components ?? [];
  if (!s.draft) return ["the studio did not load"];
  if (scope === SITE_SCOPE) {
    const merged = applySiteProposal(s.draft, p, entries, s.manifest?.vocabulary.tones ?? []);
    setDraft(merged.draft);
    return merged.skipped;
  }
  const kind = s.kinds[scope];
  if (!kind) return [`page type ${scope} did not load`];
  const merged = applyKindProposal(kind.draft, p, entries, s.draft);
  setKindDraft(scope, merged.draft);
  return merged.skipped;
}

function ProposalCard({ payload, toolCallId }: { payload: ComponentProposal; toolCallId: string }) {
  const [skipped, setSkipped] = useState<string[]>([]);
  useEffect(() => {
    if (applied.has(toolCallId)) return;
    applied.add(toolCallId);
    ensureStudio("components");
    consoleNav.adoptRoom({ kind: "studio", studio: "components" });
    void adopt(payload).then(setSkipped);
  }, [payload, toolCallId]);

  const scopes = getComponentsState().scopes;
  const where = !payload.scope || payload.scope === SITE_SCOPE
    ? "everywhere"
    : scopes.find((s) => s.id === payload.scope)?.label ?? payload.scope;
  const count = proposalSize(payload);

  return (
    <div className="ain-brandprev" data-testid="components-proposal">
      <span className="ain-brandprev__dot" aria-hidden="true" />
      <div className="ain-brandprev__body">
        <span className="ain-brandprev__label">
          Staged for {where} · {count} change{count === 1 ? "" : "s"}
        </span>
        {payload.reason && <span className="ain-brandprev__hint">{payload.reason}</span>}
        <span className="ain-brandprev__hint">Not live yet — review it in the studio and Publish to apply it</span>
        {skipped.length > 0 && (
          <span className="ain-brandprev__rejected">Not applied (it would leave nothing to choose): {skipped.join(", ")}</span>
        )}
      </div>
    </div>
  );
}

/** Registers the card for the `components_proposal` widget. */
export const ComponentsProposalToolUI = makeSafeAssistantToolUI<ComponentProposal, unknown>({
  toolName: "components_proposal",
  render: ({ args, toolCallId }) => <ProposalCard payload={args ?? { scope: SITE_SCOPE }} toolCallId={toolCallId} />,
});
