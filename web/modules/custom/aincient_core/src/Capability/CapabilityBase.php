<?php

declare(strict_types=1);

namespace Drupal\aincient_core\Capability;

use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Plugin\ContextAwarePluginTrait;
use Drupal\Core\Plugin\PluginBase;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * The base every Atelier capability extends.
 *
 * WHY THIS EXISTS. It replaces the AI module's function-call base — 275 lines of
 * which our 15 capabilities used four things: the core context-aware plumbing,
 * a `create()` they call `parent::create()` on, the readable-output pair, and —
 * the one piece with real behaviour in it — argument TYPE COERCION. Everything
 * else on that base (`normalize()`, `populateValues()`, `populateChildValue()`,
 * tools ids, structured output, per-instance context overrides) existed to serve
 * the vendor `Tools*` DTO stack that we never called. Reproducing the four we
 * use, here, is what lets the other ~1,670 lines stop being ours to carry.
 *
 * WHERE PARAMETERS COME FROM. Context definitions are read straight off the
 * plugin definition's `context_definitions` key by core's
 * {@see ContextAwarePluginTrait} — the same sourcing the vendor base used
 * (it only wrapped the trait to support per-instance overrides, which no Atelier
 * capability sets, so the wrapper is gone and the trait's implementations stand
 * unmodified). Those definitions are core `ContextDefinition` objects, which is
 * why the FlowDrop processor can keep reading their labels, data types,
 * required-ness and `SimpleToolItems` constraints without touching vendor code.
 *
 * NO SERVICES INJECTED HERE ON PURPOSE. The vendor base took two vendor
 * services in its constructor, which is why every subclass's `create()` had to
 * route through `parent::create()`. This base needs none: coercion is a pure
 * function of the declared data type. Subclasses still call `parent::create()`
 * and then set their own dependencies, so their code is unchanged — but the base
 * no longer drags a service graph behind it.
 */
abstract class CapabilityBase extends PluginBase implements ExecutableCapabilityInterface, ContainerFactoryPluginInterface {

  use ContextAwarePluginTrait {
    setContextValue as protected traitSetContextValue;
  }
  use StringTranslationTrait;

  /**
   * The capability's readable result.
   */
  protected string $stringOutput = '';

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    return new static($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * {@inheritdoc}
   */
  public function getReadableOutput(): string {
    return $this->stringOutput;
  }

  /**
   * {@inheritdoc}
   */
  public function setOutput(string $output): void {
    $this->stringOutput = $output;
  }

  /**
   * Coerces a model-supplied argument to the type the capability declared.
   *
   * THE ONE PIECE OF INHERITED BEHAVIOUR THAT IS LOAD-BEARING. Arguments reach a
   * capability from a language model, over a transport with no shared type
   * system: a provider that respects the tool schema sends a real boolean or a
   * real array, a looser one sends `"true"` or a JSON string, and a FlowDrop
   * string port sends text either way. The vendor base absorbed that spread
   * through the AI module's data-type converter plugins; without an equivalent
   * here, capabilities would start seeing `"true"` where they expect TRUE and a
   * JSON string where they expect a list, and would fail on input that works in
   * production today.
   *
   * It covers exactly the four data types our capabilities declare. A fifth type
   * needs a case added here AND a matching case in CapabilityTool::toJsonType(),
   * or the model will be shown a type this method cannot accept.
   *
   * Coercion is deliberately CONSERVATIVE: a value it cannot confidently convert
   * is passed through untouched rather than mangled, exactly as the converter
   * plugins did (each one refused to apply to a value it did not recognise).
   * Capabilities defend their own inputs anyway — that is where the useful,
   * model-readable error message lives.
   */
  public function setContextValue($name, $value) {
    $data_type = (string) $this->getContextDefinition($name)->getDataType();

    switch ($data_type) {
      case 'string':
        // Cast scalars and Stringables. An array is NOT stringified: a model
        // that sent structured JSON for a string param made a mistake worth
        // surfacing, and `(string) []` would turn it into the literal "Array".
        if ($value === NULL || is_scalar($value) || $value instanceof \Stringable) {
          $value = (string) $value;
        }
        break;

      case 'boolean':
        // Models write booleans as words. `"true"` and `"1"` are TRUE; every
        // other string (`"false"`, `"0"`, `""`) is FALSE. Real booleans pass
        // through the same cast unchanged. Non-string, non-bool values are left
        // alone — there is no honest reading of them.
        if (is_string($value)) {
          $value = strtolower($value) === 'true' || $value === '1';
        }
        elseif (is_bool($value)) {
          $value = (bool) $value;
        }
        break;

      case 'integer':
        // `"3"` arrives as a string from any text-shaped transport. Only cast
        // what is actually numeric, so `"soon"` does not silently become 0.
        if (is_numeric($value)) {
          $value = (int) $value;
        }
        break;

      case 'list':
        // A list param can arrive as a native array (schema-respecting
        // provider) or as a JSON string (looser provider, or a string port).
        // Decode the string case so the capability always sees an array —
        // PreviewPage's `ops` depends on this, and PreviewPageTest passes its
        // ops as a JSON string precisely to hold that path down.
        //
        // A list MUST end up an array, not merely usually: core's typed-data
        // ItemList throws `Cannot set a list with a non-array value` on the way
        // in, so passing an unparseable value through would turn a model's
        // malformed argument into an exception instead of the readable "Error:
        // provide a non-empty array…" the capability is written to return. So
        // undecodable input is wrapped rather than rejected here — empty becomes
        // an empty list, anything else becomes a one-item list — and the
        // capability gets to do the complaining, in words the model can act on.
        if (!is_array($value)) {
          $decoded = is_string($value) ? json_decode($value, TRUE) : NULL;
          if (is_array($decoded)) {
            $value = $decoded;
          }
          elseif ($value === NULL || $value === '') {
            $value = [];
          }
          else {
            $value = [$value];
          }
        }
        break;
    }

    return $this->traitSetContextValue($name, $value);
  }

}
