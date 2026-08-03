<?php

declare(strict_types=1);

namespace Drupal\aincient_core\Form;

use Drupal\aincient_core\ModelRoleResolver;
use Drupal\aincient_core\ModelRoles;
use Drupal\aincient_core\Usage\ModelPricing;
use Drupal\aincient_core\Usage\RateFreshness;
use Drupal\aincient_core\Usage\UsageQuery;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Component\Utility\Html;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\TypedConfigManagerInterface;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Render\Markup;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * What this site pays per model — the operator's number, with ours suggested.
 *
 * ## Why this became editable, having deliberately not been
 *
 * The page this replaces argued at length that rates must NOT be editable, and
 * the argument was good: the contrib table Atelier replaced let a hand-entered
 * rate outrank a synced one BY DESIGN and forever, which is how
 * `claude-haiku-4-5-20251001` sat pinned at Haiku 3.5's rate, 4x low, and how a
 * sonnet-5 turn that really billed $0.0475 was recorded as $0.00.
 *
 * That argument rested on a premise that turned out to be false: that we can
 * always know the price. Reached through a LiteLLM-style proxy we provably
 * cannot — the model id is an alias from the operator's own `config.yaml`, so it
 * may carry no vendor, a vendor by convention, or a namespace that is wrong, and
 * nothing stops one named `openai/gpt-4.1` routing to Haiku (DECISIONS 0304). A
 * rate we cannot know is not a rate we can ship. So the operator has to be able
 * to say, and this form is where.
 *
 * ## How this avoids rebuilding the trap
 *
 * The trap's active ingredient was SILENCE, not the override. An operator rate
 * here therefore:
 *
 *   - is attributed and dated — `source` says who set it, `checked` says when,
 *     the same two fields our own entries carry;
 *   - ages by the same rules — {@see RateFreshness} does not care who wrote a
 *     rate, so a hand-entered one goes stale on screen exactly as ours does;
 *   - SHOWS disagreement — when our suggestion differs from the stored value the
 *     row says so and offers the new figure. The contrib table could not say
 *     this, which is precisely why nobody noticed for a year.
 *
 * An override still wins. That is correct — it is their deployment and their
 * bill. What it no longer does is win quietly.
 *
 * ## Why grouped by model and not by role
 *
 * The read-only page was organised by ROLE, and for a page that was right: an
 * operator picks roles, not models. An editable screen cannot be, and the reason
 * is correctness rather than taste. Two roles routinely share one model — the
 * Forge demo binds `openai/gpt-4.1` to both reasoning and task — and a role-keyed
 * form would render that model's rates TWICE, as two independently editable
 * copies of one underlying value. Whichever the operator saved last would win,
 * silently discarding the other. Rates belong to models; roles only point at
 * them. So models are the rows, and the roles using each are shown on it.
 *
 * ## Why a ledger that opens, and not a screen of forms
 *
 * The first build put every row permanently in EDITING posture: four labelled
 * number inputs, a checkbox with two sentences of help, a status paragraph and a
 * provenance line, per model. The two facts an operator comes for — which model,
 * what it costs — carried exactly the same weight as an explanation of what a
 * cache write is, and the price sat at the END of a variable-length title, so it
 * landed at a different position on every row and there was no column to scan.
 *
 * So the collapsed row is for READING — id, then input and output in a fixed
 * right-hand column, tabular, with an em dash where a rate is missing, because a
 * gap in a numeric column is legible at a glance in a way the word "unpriced"
 * buried in a sentence is not. Everything else — the decision, the rate fields,
 * the provenance, the explanation of what an unpriced model costs — lives in the
 * open row, where it can be the only prose on screen. The rows share an
 * accordion `name`, so the browser keeps one open at a time with no JavaScript.
 *
 * Two consequences worth naming. Cache read and write are NOT peers of input and
 * output — two providers publish them and they are noise in a scan — so they sit
 * behind their own disclosure. And a model no role uses is moved to a section at
 * the bottom rather than hidden: it may have billed real calls at $0.00, which is
 * the exact thing this page exists to surface, but nothing further will spend on
 * it, so it must not compete with a live binding for attention.
 */
final class PricingForm extends ConfigFormBase {

  /**
   * How a row got here, worst-first — which is also the display order.
   *
   * `spending` leads because it is the only one costing money right now: a model
   * the usage log has seen, with no rate, is under-reporting on every call. A
   * bound-but-unpriced model is next (it will spend as soon as it is used), and
   * a priced one is last because it needs nothing.
   */
  private const ORIGIN_SPENDING = 'spending';
  private const ORIGIN_BOUND = 'bound';
  private const ORIGIN_PRICED = 'priced';

  /**
   * The accordion group: one open row at a time, no JavaScript.
   *
   * A shared `name` on sibling `<details>` is the platform's own exclusive
   * accordion. It matters more here than it would elsewhere — an open row is
   * five times the height of a closed one, so three open rows destroy the scan
   * this layout exists to provide.
   */
  private const ACCORDION = 'ain-pricing-row';

  public function __construct(
    ConfigFactoryInterface $configFactory,
    TypedConfigManagerInterface $typedConfigManager,
    private readonly ModelRoleResolver $roleResolver,
    private readonly ModelPricing $pricing,
    private readonly RateFreshness $freshness,
    private readonly UsageQuery $usage,
    private readonly TimeInterface $time,
  ) {
    // Both go to the parent rather than being set afterwards: ConfigFormBase
    // promotes `$typedConfigManager` as a typed property, so a constructor that
    // skips it leaves the property uninitialised and the page 500s the moment
    // anything touches config schema — which a `setConfigFactory()`-only wiring
    // does not reveal until a real request.
    parent::__construct($configFactory, $typedConfigManager);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('config.factory'),
      $container->get('config.typed'),
      $container->get('aincient_core.model_role_resolver'),
      $container->get('aincient_core.model_pricing'),
      $container->get('aincient_core.rate_freshness'),
      $container->get('aincient_core.usage_query'),
      $container->get('datetime.time'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'aincient_core_pricing';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames(): array {
    return [ModelPricing::CONFIG];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $form['#attached']['library'][] = 'aincient_core/pricing';
    $form['#attributes']['class'][] = 'ain-pricing';

    $subjects = $this->subjects();

    $form['intro'] = [
      '#type' => 'html_tag',
      '#tag' => 'p',
      '#attributes' => ['class' => ['ain-pricing__intro']],
      '#value' => (string) $this->t('What Atelier records a call as costing. We suggest a rate where we can recognise the model; through a proxy we often cannot, because the model name is yours to choose — so the number below is yours to set.'),
    ];

    if ($subjects === []) {
      $form['empty'] = [
        '#type' => 'html_tag',
        '#tag' => 'p',
        '#attributes' => ['class' => ['ain-pricing__empty']],
        '#value' => (string) $this->t('No model is bound to a role yet, and nothing has been billed. Bind a model on <a href="@url">Atelier models</a> and it will appear here.', [
          '@url' => Url::fromRoute('aincient_core.model_roles')->toString(),
        ]),
      ];
      return parent::buildForm($form, $form_state);
    }

    $form['models'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['ain-pricing__models']],
      '#tree' => TRUE,
    ];

    // The column heading the numbers are read against. A single one for the
    // whole ledger rather than a label per field: repeating "Input" twelve times
    // is what made the first build unscannable.
    $form['models']['_head'] = [
      '#type' => 'inline_template',
      '#template' => '<div class="ain-pricing__head"><span>{{ model }}</span><span class="ain-pricing__nums"><span>{{ in }}</span><span>{{ out }}</span></span></div>',
      '#context' => [
        'model' => $this->t('Model'),
        'in' => $this->t('Input'),
        'out' => $this->t('Output'),
      ],
    ];

    $inUse = array_filter($subjects, static fn (array $s): bool => $s['roles'] !== []);
    $retired = array_filter($subjects, static fn (array $s): bool => $s['roles'] === []);

    // Exactly one row starts open: the worst one. A screen of collapsed rows
    // hides the one losing money; opening all of them hides it just as well.
    $openKey = NULL;
    foreach ($inUse as $key => $subject) {
      if ($this->needsDecision($subject)) {
        $openKey = $key;
        break;
      }
    }

    foreach ($inUse as $key => $subject) {
      $form['models'][$key] = $this->row($key, $subject, $key === $openKey);
    }

    if ($retired !== []) {
      $form['models']['_retired'] = [
        '#type' => 'details',
        '#title' => $this->t('Models no role uses (@count)', ['@count' => count($retired)]),
        '#description' => $this->t('Nothing on this site will call these again. They are listed because past calls may have been recorded at $0.00, and because a rate set here can still be corrected or cleared.'),
        '#attributes' => ['class' => ['ain-pricing__retired']],
      ];
      foreach ($retired as $key => $subject) {
        $form['models']['_retired'][$key] = $this->row($key, $subject, FALSE);
      }
    }

    $form['models_link'] = [
      '#type' => 'html_tag',
      '#tag' => 'p',
      '#attributes' => ['class' => ['ain-pricing__link']],
      '#value' => (string) $this->t('Change which model a role uses on <a href="@url">Atelier models</a>.', [
        '@url' => Url::fromRoute('aincient_core.model_roles')->toString(),
      ]),
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * One model: a ledger line that opens into the decision.
   *
   * @param string $key
   *   The form-safe key for this model.
   * @param array<string, mixed> $subject
   *   A row from {@see self::subjects()}.
   * @param bool $open
   *   Whether this row starts expanded.
   *
   * @return array<string, mixed>
   *   A render array.
   */
  private function row(string $key, array $subject, bool $open): array {
    $provider = (string) $subject['provider'];
    $model = (string) $subject['model'];
    $stored = $this->storedEntry($provider, $model);
    $candidates = $this->pricing->candidates($provider, $model);
    $exact = NULL;
    foreach ($candidates as $candidate) {
      if ($candidate['exact']) {
        $exact = $candidate['entry'];
        break;
      }
    }
    $effective = $this->pricing->published($provider, $model);

    $row = [
      '#type' => 'details',
      '#open' => $open,
      '#title' => $this->summary($subject, $effective, $stored, $exact),
      '#attributes' => [
        'class' => ['ain-pricing__model', 'ain-pricing__model--' . $subject['origin']],
        // Platform-native exclusive accordion; see self::ACCORDION.
        'name' => self::ACCORDION,
      ],
    ];

    $row['status'] = $this->statusNote($subject, $stored, $exact, $effective);
    $row['choice'] = $this->choice($key, $subject, $stored, $candidates, $exact);

    // The rate fields belong INSIDE the radio group, under the option that
    // reveals them. Rendered as a sibling — which is where a plain
    // `$row['rates']` lands — they appear below the fieldset's own border, and
    // the connection between "Enter the rates myself" and the boxes that follow
    // has to be inferred from proximity alone.
    //
    // `#parents` is what makes that safe: the element moves in the RENDER tree
    // while its submitted value stays at `models[<key>][rates]`, so nothing in
    // the submit handler or the tests has to know it was moved. Without it the
    // values would nest under `choice`, whose own value is a scalar.
    $row['choice']['rates'] = $this->rateFields($key, $stored) + [
      '#parents' => ['models', $key, 'rates'],
      '#weight' => 100,
    ];

    $row['identity'] = [
      '#type' => 'value',
      '#value' => ['provider' => $provider, 'model' => $model],
    ];

    return $row;
  }

  /**
   * The collapsed line: model, prices in their column, then meta and state.
   *
   * Built as markup rather than a string because the three facts are not one
   * sentence — the id is the subject, the prices are a column to scan DOWN, and
   * the roles and state are annotations. Joined by em dashes, as the first build
   * did, they read as equals and none of them can be found.
   */
  private function summary(array $subject, ?array $effective, ?array $stored, ?array $exact): Markup {
    $id = Html::escape($subject['provider'] . ':' . $subject['model']);

    if ($effective !== NULL && !empty($effective['free'])) {
      $numbers = '<span class="ain-pricing__free">' . Html::escape((string) $this->t('Free')) . '</span>';
    }
    else {
      $numbers = $this->rateCell((string) $this->t('Input'), $effective['input'] ?? NULL)
        . $this->rateCell((string) $this->t('Output'), $effective['output'] ?? NULL);
    }

    // A full stop after the roles, and the sentence form, so speech does not run
    // "Fast" into the badge that follows it as one word ("Fastunpriced").
    $meta = $subject['roles'] !== []
      ? Html::escape((string) $this->t('Used by @roles.', ['@roles' => implode(', ', $subject['roles'])]))
      : Html::escape($this->presence($subject) . '.');

    $badge = '';
    if ($effective === NULL) {
      $badge = $this->badge('unpriced', (string) $this->t('unpriced'), (string) $this->t('Unpriced: calls to this model are recorded at $0.00.'));
    }
    elseif ($this->disagrees($stored, $exact)) {
      $badge = $this->badge('disagrees', (string) $this->t('differs'), (string) $this->t('This rate differs from the one we now publish.'));
    }
    else {
      $flag = $this->freshness->state($subject['provider'], $subject['model']);
      if ($flag === RateFreshness::LAPSED) {
        $badge = $this->badge('lapsed', (string) $this->t('lapsed'), (string) $this->t('Lapsed: this rate has a known end date that has passed.'));
      }
      elseif ($flag === RateFreshness::STALE) {
        $badge = $this->badge('stale', (string) $this->t('stale'), (string) $this->t('Stale: this rate has not been checked for a while.'));
      }
    }

    return Markup::create(
      '<span class="ain-pricing__line">'
      . '<span class="ain-pricing__id">' . $id . '</span>'
      . '<span class="ain-pricing__nums">' . $numbers . '</span>'
      . '<span class="ain-pricing__meta">' . $meta . '</span>'
      . '<span class="ain-pricing__badges">' . $badge . '</span>'
      . '</span>',
    );
  }

  /**
   * One figure in the price column, labelled for anyone not seeing the column.
   *
   * The column heading is a VISUAL device: it sits once at the top and the eye
   * carries it down the page. Nothing carries it down for a screen reader, which
   * reaches the row and is read "0.30 30.00" — two numbers in no stated unit and
   * no stated order. So each figure names itself, and the heading above is
   * decoration that costs nothing to ignore.
   *
   * A missing rate is the sharper case. Visually it is an em dash, which speech
   * either announces as punctuation or skips entirely — so the one state this
   * whole page exists to make loud would be the quietest thing in the row. The
   * dash is hidden from speech and replaced with the words.
   */
  private function rateCell(string $label, float|int|string|null $value): string {
    $missing = $value === NULL || $value === '';
    $spoken = $missing
      ? (string) $this->t('@label: no rate set.', ['@label' => $label])
      : (string) $this->t('@label: $@value per million tokens.', [
        '@label' => $label,
        '@value' => $this->money($value),
      ]);

    return '<span class="ain-pricing__num' . ($missing ? ' ain-pricing__num--none' : '') . '">'
      . '<span class="visually-hidden">' . Html::escape($spoken) . '</span>'
      . '<span aria-hidden="true">' . Html::escape($this->money($value)) . '</span>'
      . '</span>';
  }

  /**
   * A state badge.
   *
   * The visible text is lower case, because a shouting word in every row is a
   * word nobody reads; the spoken text is a sentence, because "unpriced" arriving
   * bare after a model name is not one.
   */
  private function badge(string $kind, string $label, string $spoken): string {
    return '<span class="ain-pricing__badge ain-pricing__badge--' . $kind . '">'
      . '<span class="visually-hidden">' . Html::escape($spoken) . '</span>'
      . '<span aria-hidden="true">' . Html::escape($label) . '</span>'
      . '</span>';
  }

  /**
   * Why a row nobody's roles point at is on the page at all.
   *
   * "Not bound to a role" — what the first build said — describes what the row
   * is NOT, which is never the sentence that answers the question.
   */
  private function presence(array $subject): string {
    if ($subject['calls'] > 0) {
      return (string) $this->t('billed @count calls at $0.00', ['@count' => number_format($subject['calls'])]);
    }
    return $subject['origin'] === self::ORIGIN_PRICED
      ? (string) $this->t('priced on this site, unused')
      : (string) $this->t('seen in the usage log');
  }

  /**
   * The one question: what does a call to this model cost?
   *
   * A radio and not the checkboxes this replaces, because the answers are
   * mutually exclusive and the first build could not say so. It let an operator
   * tick "free", type $2.00 into input AND tick "use our suggestion" — three
   * simultaneous answers, silently resolved by a precedence order in the submit
   * handler that appeared nowhere on screen. That is the same failure mode as
   * the table this page replaced: what got stored stopped matching what the
   * operator believed they had said.
   *
   * @return array<string, mixed>
   */
  private function choice(string $key, array $subject, ?array $stored, array $candidates, ?array $exact): array {
    $options = [
      'none' => $this->t('Not set — record $0.00 and keep reporting the gap'),
      'free' => $this->t('Free — self-hosted or local, so $0.00 is the true price'),
    ];
    $descriptions = [
      'free' => $this->t('For local inference such as Ollama. Records a deliberate zero instead of an unknown one.'),
    ];

    foreach ($candidates as $index => $candidate) {
      $summary = $this->suggestionSummary($candidate['entry']);
      if ($candidate['exact']) {
        $options['inherit'] = $this->t('Our published rate — @rates', ['@rates' => $summary]);
        $descriptions['inherit'] = $this->t('Nothing is stored on this site, so the rate stays current if we revise it.');
        continue;
      }
      $options['match_' . $index] = $this->t('Priced as @from — @rates', [
        '@from' => $candidate['from'],
        '@rates' => $summary,
      ]);
      // The honesty this option turns on. We matched a NAME; through a proxy the
      // name is an alias the operator wrote, and nothing stops one reading
      // `openai/gpt-4.1` from routing somewhere else entirely. Copied in once and
      // stamped as a match — never quietly re-synced later, because we guessed.
      $descriptions['match_' . $index] = $this->t('Matched by name. We cannot see where this actually routes, so check it against your provider.');
    }

    $options['custom'] = $this->t('Enter the rates myself');

    $element = [
      '#type' => 'radios',
      '#title' => $this->t('What does a call to this model cost?'),
      '#options' => $options,
      '#default_value' => $this->defaultChoice($stored, $exact),
      '#attributes' => ['class' => ['ain-pricing__choice']],
    ];
    foreach ($descriptions as $option => $description) {
      if (isset($options[$option])) {
        $element[$option]['#description'] = $description;
      }
    }

    return $element;
  }

  /**
   * Which answer the stored state already amounts to.
   */
  private function defaultChoice(?array $stored, ?array $exact): string {
    if ($stored !== NULL) {
      if (!empty($stored['free'])) {
        return 'free';
      }
      foreach (array_keys(self::classLabels()) as $class) {
        if (($stored[$class . '_per_mtok'] ?? NULL) !== NULL) {
          return 'custom';
        }
      }
    }
    // Nothing stored, but we price this model exactly: the site IS using our
    // rate, so the radio must show that rather than claiming nothing is set.
    return $exact !== NULL ? 'inherit' : 'none';
  }

  /**
   * The rate fields, shown only when the operator says they will type them.
   *
   * @return array<string, mixed>
   */
  private function rateFields(string $key, ?array $stored): array {
    $visible = [
      'visible' => [
        ':input[name="models[' . $key . '][choice]"]' => ['value' => 'custom'],
      ],
    ];

    $rates = [
      '#type' => 'container',
      '#states' => $visible,
      '#attributes' => ['class' => ['ain-pricing__rates-grid']],
    ];

    // All four on one grid, input and output leading and the cache pair on the
    // line below. They are NOT peers — two providers publish cache rates and
    // most calls carry none — but that ranking is now carried by the order and
    // by the fact that these fields only exist inside an already-open row.
    // Folding them into a second disclosure inside the first was one nesting
    // level charged for information the layout already gives away.
    foreach (array_keys(self::classLabels()) as $class) {
      $rates[$class] = $this->rateField(self::classLabels()[$class], $stored[$class . '_per_mtok'] ?? NULL);
    }

    $rates['note'] = [
      '#type' => 'html_tag',
      '#tag' => 'p',
      '#attributes' => ['class' => ['ain-pricing__rates-note']],
      '#value' => (string) $this->t('Only some providers bill cached prompt tokens separately. Left empty, those tokens are reported as unpriced rather than as free.'),
    ];

    return $rates;
  }

  /**
   * One rate input.
   *
   * @return array<string, mixed>
   */
  private function rateField(string $label, float|int|string|null $default): array {
    return [
      '#type' => 'number',
      '#title' => $label,
      '#default_value' => $default,
      // Rates run from $0.02 (cached input on a nano model) to $60 and are
      // quoted to the cent or finer, so the step has to be finer than the
      // cheapest figure anyone will type — not the 0.01 a currency field would
      // default to, which would refuse a real published rate.
      '#step' => 0.000001,
      '#min' => 0,
      '#field_prefix' => '$',
      '#field_suffix' => $this->t('/ Mtok'),
      '#attributes' => ['class' => ['ain-pricing__rate-input']],
    ];
  }

  /**
   * The one sentence this row most needs to say, shown when it is open.
   *
   * Ordered by consequence, not by tidiness: money already lost first, then a
   * disagreement with our suggestion, then staleness. Only one is shown — a row
   * carrying three warnings communicates none of them.
   *
   * @return array<string, mixed>
   */
  private function statusNote(array $subject, ?array $stored, ?array $exact, ?array $effective): array {
    $class = 'ain-pricing__status';
    $message = NULL;

    if ($effective === NULL && $subject['origin'] === self::ORIGIN_SPENDING) {
      $class .= ' ain-pricing__status--unpriced';
      $message = $this->t('This model has been called and has no rate, so those calls were recorded at $0.00. Every total on the usage dashboard is under-reported until you set one.');
    }
    elseif ($effective === NULL) {
      $class .= ' ain-pricing__status--unpriced';
      $message = $this->t('No rate. Calls to this model will record $0.00 and the dashboard will under-report until you set one.');
    }
    elseif ($this->disagrees($stored, $exact)) {
      // The line the contrib table could never print, and the reason a stale
      // hand-entered rate survived a year there.
      $class .= ' ain-pricing__status--disagrees';
      $message = $this->t('Your rate differs from the one we now publish (@suggestion). Yours is being used.', [
        '@suggestion' => $this->suggestionSummary($exact),
      ]);
    }
    else {
      $flag = $this->freshness->state($subject['provider'], $subject['model']);
      if ($flag === RateFreshness::LAPSED) {
        $class .= ' ain-pricing__status--lapsed';
        $message = $this->t('This rate has a known end date that has passed, so it is now wrong rather than merely old.');
      }
      elseif ($flag === RateFreshness::STALE) {
        $class .= ' ain-pricing__status--stale';
        $message = $this->t('This rate has not been checked against its source for a while.');
      }
    }

    $provenance = $effective === NULL ? '' : trim(sprintf(
      '%s%s',
      $effective['source'] !== '' ? (string) $this->t('Source: @s.', ['@s' => $effective['source']]) : '',
      $effective['checked'] !== '' ? ' ' . (string) $this->t('Checked @d.', ['@d' => $effective['checked']]) : '',
    ));

    return [
      '#type' => 'inline_template',
      '#template' => '{% if message %}<p class="{{ class }}">{{ message }}</p>{% endif %}{% if provenance %}<p class="ain-pricing__provenance">{{ provenance }}</p>{% endif %}',
      '#context' => ['class' => $class, 'message' => $message, 'provenance' => $provenance],
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $now = date('Y-m-d', $this->time->getRequestTime());
    $who = (string) $this->t('Set on this site by @user.', ['@user' => $this->currentUser()->getAccountName()]);

    // Everything the operator has NOT touched is left exactly where it was —
    // including entries for models no longer on screen, which a rebuild-from-
    // form-state would silently delete. Only the rows submitted are rewritten.
    $existing = (array) ($this->config(ModelPricing::CONFIG)->get('models') ?? []);
    $byKey = [];
    foreach ($existing as $entry) {
      if (is_array($entry)) {
        $byKey[$this->key((string) ($entry['provider'] ?? ''), (string) ($entry['model'] ?? ''))] = $entry;
      }
    }

    foreach ($this->submittedRows((array) $form_state->getValue('models')) as $key => $values) {
      $provider = (string) $values['identity']['provider'];
      $model = (string) $values['identity']['model'];
      $choice = (string) ($values['choice'] ?? 'none');

      // `none` and `inherit` both store nothing, and mean different things only
      // in what they fall through TO: no rate at all, or ours. Storing a copy of
      // our rate for `inherit` would freeze it at today's figure and silence the
      // disagreement notice forever — the contrib trap, rebuilt.
      if ($choice === 'none' || $choice === 'inherit') {
        unset($byKey[$key]);
        continue;
      }

      if (str_starts_with($choice, 'match_')) {
        $candidates = $this->pricing->candidates($provider, $model);
        $index = (int) substr($choice, 6);
        if (isset($candidates[$index]) && !$candidates[$index]['exact']) {
          $entry = $candidates[$index]['entry'];
          // Rekeyed onto THIS model, and stamped as a match rather than as our
          // published rate: it is now this site's number, arrived at by a guess
          // a human accepted, and it must read that way in an audit.
          $entry['provider'] = $provider;
          $entry['model'] = $model;
          $entry['source'] = (string) $this->t('Matched from @from and accepted on this site by @user.', [
            '@from' => $candidates[$index]['from'],
            '@user' => $this->currentUser()->getAccountName(),
          ]);
          $entry['checked'] = $now;
          $byKey[$key] = $entry;
        }
        continue;
      }

      $entry = ['provider' => $provider, 'model' => $model];

      if ($choice === 'free') {
        $entry['free'] = TRUE;
      }
      else {
        // Nested under `rates`, because that container is inside the `#tree` and
        // therefore part of the value hierarchy, not just a styling wrapper.
        // Read one level too shallow — as the first version did — and every rate
        // the operator types is silently discarded on save, which is the single
        // worst bug this screen could ship.
        $rates = is_array($values['rates'] ?? NULL) ? $values['rates'] : [];
        $any = FALSE;
        foreach (array_keys(self::classLabels()) as $class) {
          $value = $rates[$class] ?? '';
          if ($value === '' || $value === NULL) {
            // Absent stays ABSENT, never 0.0. An unfilled cache-read field means
            // "not published", which reports those tokens as unpriced; writing a
            // zero would bill them at nothing and say nothing.
            continue;
          }
          $entry[$class . '_per_mtok'] = (float) $value;
          $any = TRUE;
        }
        if (!$any) {
          // "I'll enter the rates", with none entered, is not a rate of zero.
          unset($byKey[$key]);
          continue;
        }
      }

      $entry['source'] = $who;
      $entry['checked'] = $now;
      $byKey[$key] = $entry;
    }

    $this->config(ModelPricing::CONFIG)
      ->set('models', array_values($byKey))
      ->save();

    parent::submitForm($form, $form_state);
  }

  /**
   * The rows out of the submitted tree, whichever section they were rendered in.
   *
   * Retired models live inside a `details` wrapper, so their values arrive one
   * level deeper. Recognising a row by its `identity` rather than by its
   * position keeps the two sections from needing two code paths — and a section
   * added later cannot silently stop saving.
   *
   * @param array<mixed> $values
   *   The `models` subtree of the submitted values.
   *
   * @return array<string, array<string, mixed>>
   *   Form key => row values.
   */
  private function submittedRows(array $values): array {
    $rows = [];
    foreach ($values as $key => $value) {
      if (!is_array($value)) {
        continue;
      }
      if (isset($value['identity']) && is_array($value['identity'])) {
        $rows[(string) $key] = $value;
        continue;
      }
      foreach ($this->submittedRows($value) as $nestedKey => $nested) {
        $rows[$nestedKey] = $nested;
      }
    }
    return $rows;
  }

  /**
   * Every model this form should show, worst state first.
   *
   * Three sources, deliberately: what is BOUND (about to spend), what has SPENT
   * (already under-reporting, from the usage log), and what already carries an
   * operator rate (so it can be corrected or cleared). Not the suggestion list —
   * offering a form row for all thirteen models we happen to price would bury
   * the two that matter on this site.
   *
   * @return array<string, array{provider: string, model: string, roles: list<string>, origin: string, calls: int}>
   */
  private function subjects(): array {
    $subjects = [];

    $add = function (string $provider, string $model, string $origin, ?string $role = NULL) use (&$subjects): void {
      if ($provider === '' || $model === '') {
        return;
      }
      $key = $this->key($provider, $model);
      $subjects[$key] ??= [
        'provider' => $provider,
        'model' => $model,
        'roles' => [],
        'origin' => $origin,
        'calls' => 0,
      ];
      if ($role !== NULL && !in_array($role, $subjects[$key]['roles'], TRUE)) {
        $subjects[$key]['roles'][] = $role;
      }
    };

    foreach (ModelRoles::pickerDefinitions() as $role => $definition) {
      $binding = $this->roleResolver->binding($role);
      if (is_array($binding)) {
        $add(
          (string) ($binding['provider_id'] ?? ''),
          (string) ($binding['model_id'] ?? ''),
          self::ORIGIN_BOUND,
          (string) ($definition['label'] ?? $role),
        );
      }
    }

    // What has actually been billed and could not be priced. This is the only
    // source that reports a loss rather than a risk, so it overrides whatever
    // origin a row already had.
    if ($this->usage->available()) {
      foreach ($this->usage->unpricedModels(NULL) as $seen) {
        $provider = (string) ($seen['provider'] ?? $seen['provider_id'] ?? '');
        $model = (string) ($seen['model'] ?? $seen['model_id'] ?? '');
        $add($provider, $model, self::ORIGIN_SPENDING);
        $key = $this->key($provider, $model);
        if (isset($subjects[$key])) {
          $subjects[$key]['origin'] = self::ORIGIN_SPENDING;
          $subjects[$key]['calls'] = (int) ($seen['calls'] ?? 0);
        }
      }
    }

    foreach ((array) ($this->config(ModelPricing::CONFIG)->get('models') ?? []) as $entry) {
      if (is_array($entry)) {
        $add((string) ($entry['provider'] ?? ''), (string) ($entry['model'] ?? ''), self::ORIGIN_PRICED);
      }
    }

    $order = [self::ORIGIN_SPENDING => 0, self::ORIGIN_BOUND => 1, self::ORIGIN_PRICED => 2];
    uasort($subjects, static fn (array $a, array $b): int => [$order[$a['origin']], $a['provider'], $a['model']]
      <=> [$order[$b['origin']], $b['provider'], $b['model']]);

    return $subjects;
  }

  /**
   * Whether this row is one an operator still has to answer.
   */
  private function needsDecision(array $subject): bool {
    $effective = $this->pricing->published($subject['provider'], $subject['model']);
    if ($effective === NULL) {
      return TRUE;
    }
    return $this->disagrees(
      $this->storedEntry($subject['provider'], $subject['model']),
      $this->pricing->suggestion($subject['provider'], $subject['model']),
    );
  }

  /**
   * The operator's own entry for a model, ignoring our suggestion.
   *
   * @return array<string, mixed>|null
   */
  private function storedEntry(string $provider, string $model): ?array {
    foreach ((array) ($this->config(ModelPricing::CONFIG)->get('models') ?? []) as $entry) {
      if (is_array($entry)
        && (string) ($entry['provider'] ?? '') === $provider
        && (string) ($entry['model'] ?? '') === $model) {
        return $entry;
      }
    }
    return NULL;
  }

  /**
   * Whether the operator's stored rate and our suggestion differ on a number.
   *
   * Compares only the rate classes — a difference in note or checked date is not
   * a disagreement about price, and flagging one would train the operator to
   * ignore the notice that matters.
   */
  private function disagrees(?array $stored, ?array $suggested): bool {
    if ($stored === NULL || $suggested === NULL) {
      return FALSE;
    }
    foreach (array_keys(self::classLabels()) as $class) {
      $mine = $stored[$class . '_per_mtok'] ?? NULL;
      $ours = $suggested[$class . '_per_mtok'] ?? NULL;
      if ($mine === NULL && $ours === NULL) {
        continue;
      }
      if ($mine === NULL || $ours === NULL || abs((float) $mine - (float) $ours) > 0.0000001) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * A rate entry as one readable phrase.
   */
  private function suggestionSummary(?array $suggested): string {
    if ($suggested === NULL) {
      return '';
    }
    if (!empty($suggested['free'])) {
      return (string) $this->t('free');
    }
    return (string) $this->t('$@in in / $@out out per Mtok', [
      '@in' => $this->money($suggested['input_per_mtok'] ?? NULL),
      '@out' => $this->money($suggested['output_per_mtok'] ?? NULL),
    ]);
  }

  /**
   * A rate as a scannable figure, or an em dash where there is none.
   *
   * Two decimals, because the column exists to be compared down — except where
   * that would render a real rate as `0.00`, which is the one case where a
   * missing digit changes the meaning rather than the precision.
   */
  private function money(float|int|string|null $value): string {
    if ($value === NULL || $value === '') {
      return '—';
    }
    $value = (float) $value;
    return $value > 0 && $value < 0.005
      ? rtrim(number_format($value, 4, '.', ''), '0')
      : number_format($value, 2, '.', '');
  }

  /**
   * A form-safe key for a `provider:model` pair.
   *
   * Model ids carry dots, slashes and colons; form array keys tolerate none of
   * them reliably. The identity is carried in a `#value` element rather than
   * parsed back out of this, so the encoding only has to be unique.
   */
  private function key(string $provider, string $model): string {
    return preg_replace('/[^a-z0-9_]+/i', '_', $provider . '__' . $model) ?? $provider;
  }

  /**
   * The four token classes, with the labels an operator reads them under.
   *
   * @return array<string, string>
   */
  private static function classLabels(): array {
    return [
      'input' => 'Input',
      'output' => 'Output',
      'cache_read' => 'Cache read',
      'cache_write' => 'Cache write',
    ];
  }

}
