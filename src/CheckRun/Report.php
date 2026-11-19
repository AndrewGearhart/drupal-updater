<?php

namespace DrupalUpdater\CheckRun;

use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Records version trajectories and possible database-update exposure.
 *
 * @phpstan-import-type LockedPackage from Lockfile
 */
final class Report {

  /**
   * Last step at which each package identity changed.
   *
   * @var array<string, int>
   */
  private array $changedAt = [];

  /**
   * Previous package identities, including absence.
   *
   * @var array<string, list<LockedPackage|null>>
   */
  private array $history = [];

  /**
   * Steps after which the normal updater would invoke updb.
   *
   * @var array<int, string>
   */
  private array $boundaries = [];

  /**
   * Display rows for changed packages and unchanged steps.
   *
   * @var list<list<string>>
   */
  private array $rows = [];

  /**
   * Potentially unsafe transitions, not predictions of actual update hooks.
   *
   * @var list<string>
   */
  private array $findings = [];

  /**
   * Number of changed packages that differed from the full target at a step.
   */
  private int $deviations = 0;

  /**
   * Starts a report from the original and unrestricted full target locks.
   *
   * @param array<string, LockedPackage> $initial
   *   Original packages.
   * @param array<string, LockedPackage> $target
   *   Unrestricted full-update target packages.
   */
  public function __construct(private array $initial, private array $target) {}

  /**
   * Records a completed step and its predicted database-update invocation.
   *
   * @param int $index
   *   One-based step number.
   * @param string $requested
   *   Package argument of this step.
   * @param array<string, LockedPackage> $before
   *   Packages before the step.
   * @param array<string, LockedPackage> $after
   *   Packages after the step.
   * @param bool $updb
   *   Whether the normal updater would invoke updb after this step.
   */
  public function step(int $index, string $requested, array $before, array $after, bool $updb): void {
    $changed = FALSE;
    foreach (array_unique(array_merge(array_keys($before), array_keys($after))) as $name) {
      $old = $before[$name] ?? NULL;
      $new = $after[$name] ?? NULL;
      if ($old === $new) {
        continue;
      }
      $changed = TRUE;
      $target = $this->target[$name] ?? NULL;
      $this->rows[] = [
        (string) $index . ': ' . $requested,
        $name,
        Lockfile::describe($old) . ' -> ' . Lockfile::describe($new),
        Lockfile::describe($target),
        $updb ? 'yes' : 'no',
      ];
      if ($new !== $target) {
        $this->deviations++;
      }
      $history = $this->history[$name] ?? [$this->initial[$name] ?? NULL];
      if ($this->isBacktrack($old, $new, $history)) {
        foreach ($this->boundaries as $boundary => $package) {
          if ($boundary >= ($this->changedAt[$name] ?? 0)) {
            $this->findings[] = sprintf(
              '%s: %s -> %s at step %d (%s); updb would run at step %d (%s) while %s was selected.',
              $name, Lockfile::describe($old), Lockfile::describe($new), $index, $requested,
              $boundary, $package, Lockfile::describe($old),
            );
            break;
          }
        }
      }
      $this->history[$name] = [...$history, $new];
      $this->changedAt[$name] = $index;
    }
    if (!$changed) {
      $this->rows[] = [
        (string) $index . ': ' . $requested,
        '(no package identity changes)',
        '',
        '',
        $updb ? 'yes' : 'no',
      ];
    }
    // An invocation after this step sees its new state, not its old state.
    if ($updb) {
      $this->boundaries[$index] = $requested;
    }
  }

  /**
   * Renders full targets, the stepped timeline and qualified findings.
   */
  public function render(OutputInterface $output): void {
    $output->writeln('// FULL UPDATE TARGET //');
    $rows = [];
    foreach (array_unique(array_merge(array_keys($this->initial), array_keys($this->target))) as $name) {
      $old = $this->initial[$name] ?? NULL;
      $new = $this->target[$name] ?? NULL;
      if ($old !== $new) {
        $rows[] = [$name, Lockfile::describe($old), Lockfile::describe($new)];
      }
    }
    (new Table($output))->setHeaders(['Package', 'Original', 'Full target'])->setRows($rows)->render();
    $output->writeln('// STEPPED RESOLUTION //');
    (new Table($output))->setHeaders(['Step', 'Changed package', 'Change', 'Full target', 'Would run updb'])->setRows($this->rows)->render();
    $output->writeln(sprintf('%d changed package states differ from the full target; untouched packages are not counted.', $this->deviations));
    $output->writeln('Target differences are informational: the unrestricted target may exceed a selected-package, security-only or limited run.');
    $output->writeln('// CHECK RUN FINDINGS //');
    if ($this->findings === []) {
      $output->writeln('No potentially unsafe version backtracking across a predicted updb invocation was detected.');
    }
    foreach ($this->findings as $finding) {
      $output->writeln('[potentially unsafe] ' . $finding);
    }
    $output->writeln('Pending database updates were not inspected. Findings indicate risk, not proven breakage.');
    $output->writeln('Project plugins, scripts and post-update processors were not simulated. Metadata can change between resolutions; this is not an execution guarantee.');
  }

  /**
   * Whether a potentially unsafe transition was found.
   */
  public function hasFindings(): bool {
    return $this->findings !== [];
  }

  /**
   * Identifies reversals, removals, dev-to-release moves and ref replacements.
   *
   * @param LockedPackage|null $old
   *   Old package identity.
   * @param LockedPackage|null $new
   *   New package identity.
   * @param list<LockedPackage|null> $history
   *   Previously selected identities.
   */
  private function isBacktrack(?array $old, ?array $new, array $history): bool {
    if (in_array($new, $history, TRUE) || $new === NULL) {
      return TRUE;
    }
    if ($old === NULL) {
      return count(array_filter($history)) > 0;
    }
    if ($old['version'] === $new['version']) {
      return $old['source'] !== $new['source'] || $old['dist'] !== $new['dist'];
    }
    $old_dev = str_contains(strtolower($old['version']), 'dev');
    $new_dev = str_contains(strtolower($new['version']), 'dev');
    if ($old_dev || $new_dev) {
      return $old_dev && !$new_dev;
    }
    return version_compare(ltrim($old['version'], 'vV'), ltrim($new['version'], 'vV'), '>');
  }

}
