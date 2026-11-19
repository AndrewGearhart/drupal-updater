<?php

namespace DrupalUpdater\Tests\Functional;

use DrupalUpdater\Tests\Simulator\Package;
use DrupalUpdater\Tests\Simulator\Scenario;

/**
 * Covers check-only CLI isolation, selection and qualified findings.
 */
final class CheckRunTest extends UpdaterTestCase {

  /**
   * Tests a check never invokes normal consolidation, Drupal or Git mutations.
   */
  public function testCleanCheckLeavesDirtySourceAndInstalledFilesUntouched(): void {
    $this->scenario()->package(Package::named('drupal/example', '1.0.0')->withLatest('1.1.0')->asDrupalModule());
    $project = $this->project();
    file_put_contents($project->path('uncommitted.txt'), 'keep me');
    mkdir($project->path('vendor'));
    file_put_contents($project->path('vendor/sentinel'), 'installed dependency');
    $manifest = file_get_contents($project->path('composer.json'));
    $lock = file_get_contents($project->path('composer.lock'));
    $status = $project->uncommittedChanges();

    $run = $this->runUpdater(['consolidateConfiguration' => TRUE], ['--check-run'])->assertSuccessful();

    self::assertSame([], $run->calls(Scenario::DRUSH));
    self::assertSame([], $project->commitSubjects());
    self::assertSame($status, $project->uncommittedChanges());
    self::assertSame($manifest, file_get_contents($project->path('composer.json')));
    self::assertSame($lock, file_get_contents($project->path('composer.lock')));
    self::assertSame('installed dependency', file_get_contents($project->path('vendor/sentinel')));
    self::assertFileDoesNotExist($project->path('composer.drupalupdater.lock'));
    $run->assertNotCalled(Scenario::COMPOSER, 'install');
    $run->assertOutputContains('No potentially unsafe version backtracking');
    $run->assertOutputContains('Pending database updates were not inspected');
    foreach ($run->calls() as $arguments) {
      self::assertContains('--no-plugins', $arguments);
      self::assertContains('--no-scripts', $arguments);
      if ($arguments[0] === 'update') {
        self::assertContains('--no-install', $arguments);
      }
    }
    $this->assertWorkspacesRemoved($run);
  }

  /**
   * Tests intermediate core is exposed by an unrelated module's updb call.
   */
  public function testCikardTrajectoryReturnsFindingsWithoutRunningDrush(): void {
    $this->scenario()
      ->package(Package::named('drupal/core', '11.4.12')->withLatest('11.4.13')->ofType('drupal-core')->asTransitive())
      ->package(Package::named('drupal/core-recommended', '11.4.12')->withLatest('11.4.13')->ofType('metapackage')->requires('drupal/core', '^11'))
      ->package(Package::named('drupal/google_tag', '2.0.9')->withLatest('2.1.0')->asDrupalModule());
    $this->scenario()->composer()->on('update')->once()
      ->bumps('drupal/core', 'drupal/core-recommended', 'drupal/google_tag');
    $this->scenario()->composer()->on('update', 'drupal/core-recommended')
      ->bumps('drupal/core-recommended')->locks('drupal/core', '11.x-dev');

    $run = $this->runUpdater([], ['--check-run'])->assertFailed();

    self::assertSame(2, $run->exitCode(), $run->output());
    $run->assertOutputContains('[potentially unsafe] drupal/core: 11.x-dev -> 11.4.13');
    $run->assertOutputContains('updb would run at step 2 (drupal/google_tag)');
    self::assertSame([], $run->calls(Scenario::DRUSH));
    $this->assertLockedVersion('drupal/core', '11.4.12');
    $this->assertCommitSubjects([]);
    $this->assertWorkspacesRemoved($run);
  }

  /**
   * Tests limits count changed steps; the unrestricted target stays separate.
   */
  public function testLimitAndNoDevSelectionAreRespected(): void {
    $this->scenario()
      ->package(Package::named('example/a', '1.0.0')->withLatest('1.1.0'))
      ->package(Package::named('example/b', '1.0.0')->withLatest('1.1.0'))
      ->package(Package::named('example/dev', '1.0.0')->withLatest('1.1.0')->asDev());

    $run = $this->runUpdater(['limit' => 1], ['--check-run', '--no-dev'])->assertSuccessful();

    $run->assertCalled(Scenario::COMPOSER, 'update', 'example/a');
    $run->assertNotCalled(Scenario::COMPOSER, 'update', 'example/b');
    $run->assertNotCalled(Scenario::COMPOSER, 'update', 'example/dev');
    $run->assertOutputContains('Reached limit of 1 changed-lock steps');
    self::assertCount(2, $this->updateCalls($run));
  }

  /**
   * Tests explicit selection ignores the automatic limit, as in normal runs.
   */
  public function testExplicitPackagesRetainOrderAndIgnoreLimit(): void {
    $this->scenario()
      ->package(Package::named('example/a', '1.0.0')->withLatest('1.1.0'))
      ->package(Package::named('example/b', '1.0.0')->withLatest('1.1.0'));
    $run = $this->runUpdater(['limit' => 1], ['--check-run', '--packages=example/b,example/a'])->assertSuccessful();
    $calls = $this->updateCalls($run);
    self::assertCount(3, $calls);
    self::assertContains('example/b', $calls[1]);
    self::assertContains('example/a', $calls[2]);
  }

  /**
   * Tests security discovery only steps through the vulnerable package.
   */
  public function testSecuritySelectionIsReused(): void {
    $this->scenario()
      ->package(Package::named('example/a', '1.0.0')->withLatest('1.1.0')->withAdvisory('Example advisory'))
      ->package(Package::named('example/b', '1.0.0')->withLatest('1.1.0'));
    $run = $this->runUpdater([], ['--check-run', '--security'])->assertSuccessful();
    $run->assertCalled(Scenario::COMPOSER, 'update', 'example/a');
    $run->assertNotCalled(Scenario::COMPOSER, 'update', 'example/b');
    self::assertCount(2, $this->updateCalls($run));
  }

  /**
   * Tests a failed full resolution is incomplete and the workspace is removed.
   */
  public function testFailedFullTargetReturnsIncompleteAndCleansUp(): void {
    $this->scenario()->package(Package::named('example/a', '1.0.0')->withLatest('1.1.0'));
    $this->scenario()->composer()->on('update')->fails('Unable to resolve full target');
    $run = $this->runUpdater([], ['--check-run'])->assertFailed();
    self::assertSame(1, $run->exitCode());
    $run->assertOutputContains('Check run incomplete');
    self::assertCount(1, $this->updateCalls($run));
    $this->assertWorkspacesRemoved($run);
    $this->assertLockedVersion('example/a', '1.0.0');
  }

  /**
   * Tests failed stepped mutations are restored before proceeding.
   */
  public function testFailedStepRestoresInputsAndContinuesWithIncompleteStatus(): void {
    $this->scenario()
      ->package(Package::named('example/a', '1.0.0')->withLatest('1.1.0'))
      ->package(Package::named('example/b', '1.0.0')->withLatest('1.1.0'));
    $this->scenario()->composer()->on('update', 'example/a')
      ->writes('composer.json', '{}')->writes('composer.lock', '{}')->fails('Deliberate stepped failure');
    $run = $this->runUpdater([], ['--check-run'])->assertFailed();
    self::assertSame(1, $run->exitCode());
    $run->assertCalled(Scenario::COMPOSER, 'update', 'example/b');
    $run->assertOutputContains('one or more stepped resolutions could not be checked');
    $this->assertWorkspacesRemoved($run);
    $this->assertLockedVersion('example/a', '1.0.0');
  }

  /**
   * Tests real Composer resolves relative local repositories with no installs.
   */
  public function testRealComposerChecksRelativeRepositoriesWithoutChangingVendor(): void {
    $this->scenario()
      ->package(Package::named('example/library', '1.0.0')->withLatest('1.1.0')->requires('example/dependency'))
      ->package(Package::named('example/dependency', '1.0.0')->withLatest('1.2.0')->asTransitive()->withBinary('dependency-tool'));
    $this->useRealComposer();
    $project = $this->project();
    $installed = file_get_contents($project->path('vendor/composer/installed.json'));
    $lock = file_get_contents($project->path('composer.lock'));

    $run = $this->runUpdater([], ['--check-run'])->assertSuccessful();

    $run->assertOutputContains('1.1.0');
    $run->assertOutputContains('1.2.0');
    self::assertSame($installed, file_get_contents($project->path('vendor/composer/installed.json')));
    self::assertSame($lock, file_get_contents($project->path('composer.lock')));
    self::assertFileExists($project->path('vendor/bin/dependency-tool'));
    $this->assertCommitSubjects([]);
    self::assertSame('', $project->uncommittedChanges());
    $this->assertWorkspacesRemoved($run);
  }

  /**
   * Tests package-type edge cases use the same decision as a normal run.
   */
  public function testDrupalLibraryPredictionMatchesNormalUpdater(): void {
    $this->scenario()->package(Package::named('drupal/library', '1.0.0')->withLatest('1.1.0')->ofType('drupal-library'));
    $check = $this->runUpdater([], ['--check-run'])->assertSuccessful();
    $predicted = preg_match('/\|\s+1: drupal\/library.*\|\s+yes\s+\|/', $check->output()) === 1;
    $check->assertCalled(Scenario::COMPOSER, 'show', '--locked', 'drupal/library');
    $normal = $this->runUpdater()->assertSuccessful();
    self::assertSame($normal->countCalls(Scenario::DRUSH, 'updb') > 0, $predicted);
  }

  /**
   * Gets only update calls, retaining their recorded order.
   *
   * @return list<list<string>>
   *   Composer arguments.
   */
  private function updateCalls(UpdaterRun $run): array {
    return array_values(array_filter($run->calls(), fn (array $arguments) => $arguments[0] === 'update'));
  }

  /**
   * Asserts the private cache/workspace from every check call is gone.
   */
  private function assertWorkspacesRemoved(UpdaterRun $run): void {
    foreach ($run->callEnvironments() as $environment) {
      $cache = $environment['COMPOSER_CACHE_DIR'] ?? '';
      if (str_contains($cache, '/drupal-updater-check-')) {
        self::assertDirectoryDoesNotExist(dirname($cache));
      }
    }
  }

}
