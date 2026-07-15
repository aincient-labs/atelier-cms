<?php

declare(strict_types=1);

namespace Drupal\aincient_pages\Plugin\AiFunctionCall;

use Drupal\aincient_pages\PageStore;
use Drupal\ai\Attribute\FunctionCall;
use Drupal\ai\Base\FunctionCallBase;
use Drupal\ai\Service\FunctionCalling\ExecutableFunctionCallInterface;
use Drupal\Core\Session\AccountInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * AIncient capability: show the user a table of their existing pages.
 *
 * Emits a generic `data_table` generative-UI envelope (the same `__widget__`
 * contract as `preview_page`/`brand_picker`): the dispatcher harvests it from
 * the tool result and the console renders a `DataTable` widget inline. Each row
 * carries a studio-agnostic `open_page` action — just the node id — and the
 * console deep-links it to the room that fits the CURRENT workspace: the Content
 * page node (/atelier/content/node/<nid>) when editing, the Checks audit room
 * (/atelier/checks/node/<nid>) when auditing. One capability, shared by the
 * page, operator and audit agents, lands correctly in each — the user picks a
 * page in plain language instead of hunting for a node id, without losing the
 * current conversation (it opens in a new tab).
 *
 * The `data_table` widget is deliberately generic (columns + rows + per-row
 * action); pages are simply its first producer. Read-only: it lists, it never
 * writes — editing still happens through the studio's Publish.
 */
#[FunctionCall(
  id: 'aincient_pages:list_pages',
  function_name: 'aincient_list_pages',
  name: 'List pages',
  description: 'Show the user a table of the pages that already exist on the site, so they can pick one. Each row opens that page in the right studio for the current task when clicked. Call this when the user asks to edit / open / change / check / audit an existing page, asks "what pages do I have", or wants to continue working on a page they made earlier. Takes no arguments — it renders the table.',
)]
final class ListPages extends FunctionCallBase implements ExecutableFunctionCallInterface {

  /**
   * The page store.
   */
  protected PageStore $store;

  /**
   * The current user.
   */
  protected AccountInterface $currentUser;

  /**
   * The readable output (the widget envelope).
   */
  protected string $result = '';

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    $instance = parent::create($container, $configuration, $plugin_id, $plugin_definition);
    $instance->store = $container->get('aincient_pages.store');
    $instance->currentUser = $container->get('current_user');
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public function execute(): void {
    if (!$this->currentUser->hasPermission('administer aincient pages')) {
      $this->result = 'Error: you do not have permission to view the site pages.';
      return;
    }

    // Load up to 50 most recently edited pages; the widget pages them 10 at a
    // time client-side (no round-trip). This is a pick-one-to-edit affordance,
    // not a content browser — beyond 50 the operator's find tool handles
    // searching older pages, and the caption below flags that overflow.
    $limit = 50;
    $page_size = 10;
    $pages = $this->store->list($limit);
    $total = $this->store->count();
    $rows = [];
    foreach ($pages as $page) {
      $rows[] = [
        'id' => $page['id'],
        'cells' => [
          'title' => $page['title'] !== '' ? $page['title'] : 'Untitled page',
          'changed' => $page['changed'],
        ],
        // Studio-agnostic "open this page" intent: just the node id. The console
        // resolves where it lands from the ACTIVE studio (data-table.tsx →
        // pageDeepLink) — the Content editor when editing, the Checks audit when
        // auditing — so the same table is correct in whichever studio's agent
        // rendered it. Subdir/base-path safety lives on the JS side (consoleBase).
        'action' => ['kind' => 'open_page', 'node' => (string) $page['id']],
        // A secondary link to view the live page in a new tab.
        'href' => $page['url'],
      ];
    }

    $shown = count($rows);
    // There are pages beyond the 50 we loaded — the pager can't reach them, so
    // say so and point at title search. Rendered as the widget's own caption
    // (deterministic), not left to the model to relay.
    $overflow = $total > $shown;

    $payload = [
      'columns' => [
        ['key' => 'title', 'label' => 'Page'],
        ['key' => 'changed', 'label' => 'Updated', 'format' => 'datetime'],
      ],
      'rows' => $rows,
      'pageSize' => $page_size,
      'empty' => 'No pages yet — tell me what page to build and I’ll compose it.',
    ];
    if ($overflow) {
      $payload['summary'] = sprintf(
        'Showing your %d most recently edited pages of %d — tell me a page title to find an older one.',
        $shown,
        $total,
      );
    }

    if ($total === 0) {
      $summary = 'You don’t have any pages yet. Describe one and I’ll build it.';
    }
    elseif ($overflow) {
      $summary = sprintf('Showing your %d most recently edited pages of %d — pick one to open it, or tell me a page title to find an older one.', $shown, $total);
    }
    else {
      $summary = sprintf('You have %d page%s — pick one to open it in the studio.', $total, $total === 1 ? '' : 's');
    }

    $this->result = (string) json_encode([
      '__widget__' => 'data_table',
      'payload' => $payload,
      'summary' => $summary,
    ]);
  }

  /**
   * {@inheritdoc}
   */
  public function getReadableOutput(): string {
    return $this->result;
  }

}
