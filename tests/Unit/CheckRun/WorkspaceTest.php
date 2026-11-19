<?php

namespace DrupalUpdater\Tests\Unit\CheckRun;

use DrupalUpdater\CheckRun\Lockfile;
use DrupalUpdater\CheckRun\Workspace;
use DrupalUpdater\Tests\Simulator\Filesystem;
use PHPUnit\Framework\TestCase;

/**
 * Tests workspace isolation and lock identity parsing.
 */
final class WorkspaceTest extends TestCase {

  /**
   * Fixture directory.
   */
  private string $directory;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    $this->directory = sys_get_temp_dir() . '/check-workspace-test-' . bin2hex(random_bytes(6));
    mkdir($this->directory);
  }

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    Filesystem::remove($this->directory);
  }

  /**
   * Tests relative repositories, credentials, and independent lock copies.
   */
  public function testInputsArePrivateRebasedAndIndependentlyDisposable(): void {
    Filesystem::writeJson($this->directory . '/composer.json', [
      'config' => ['cache-repo-dir' => '/must-not-write/repo', 'cache-vcs-dir' => '/must-not-write/vcs'],
      'repositories' => [
        ['type' => 'path', 'url' => '../packages/*'],
        ['type' => 'composer', 'url' => 'https://example.invalid'],
        ['type' => 'git', 'url' => 'git@example.invalid:private/project.git'],
        ['packagist.org' => FALSE],
      ],
    ]);
    $lock = '{"packages":[],"packages-dev":[]}';
    file_put_contents($this->directory . '/composer.lock', $lock);
    file_put_contents($this->directory . '/auth.json', '{"example":"private"}');
    $before = file_get_contents($this->directory . '/composer.json');
    $workspace = new Workspace($this->directory, 'dev-main');
    try {
      $manifest = json_decode(Workspace::read($workspace->path('full') . '/composer.json'), TRUE);
      self::assertSame($this->directory . '/../packages/*', $manifest['repositories'][0]['url']);
      self::assertSame('https://example.invalid', $manifest['repositories'][1]['url']);
      self::assertSame('git@example.invalid:private/project.git', $manifest['repositories'][2]['url']);
      self::assertSame($workspace->path('cache') . '/repo', $manifest['config']['cache-repo-dir']);
      self::assertSame($workspace->path('cache') . '/vcs', $manifest['config']['cache-vcs-dir']);
      self::assertSame(0600, fileperms($workspace->path('full') . '/auth.json') & 0777);
      Workspace::write($workspace->path('full') . '/composer.lock', '{}');
      self::assertSame($lock, Workspace::read($workspace->path('stepped') . '/composer.lock'));
      self::assertSame($lock, Workspace::read($this->directory . '/composer.lock'));
      self::assertSame($before, file_get_contents($this->directory . '/composer.json'));
      self::assertSame('dev-main', $workspace->environment()['COMPOSER_ROOT_VERSION']);
    }
    finally {
      $workspace->cleanup();
    }
    self::assertDirectoryDoesNotExist(dirname($workspace->path('full')));
  }

  /**
   * Tests failed construction does not leave an owned workspace behind.
   */
  public function testMissingLockDoesNotLeakWorkspace(): void {
    file_put_contents($this->directory . '/composer.json', '{}');
    $before = glob(sys_get_temp_dir() . '/drupal-updater-check-*');
    try {
      new Workspace($this->directory, '1.0.0');
      self::fail('Expected missing lock failure.');
    }
    catch (\RuntimeException $exception) {
      self::assertStringContainsString('Cannot read', $exception->getMessage());
    }
    self::assertSame($before, glob(sys_get_temp_dir() . '/drupal-updater-check-*'));
  }

  /**
   * Tests development packages and both source references are retained.
   */
  public function testLockParsingRetainsReferencesAndDevPackages(): void {
    Filesystem::writeJson($this->directory . '/composer.lock', [
      'packages' => [],
      'packages-dev' => [
        [
          'name' => 'example/tool',
          'version' => 'dev-main',
          'source' => ['reference' => 'source-ref'],
          'dist' => ['reference' => 'dist-ref'],
        ],
      ],
    ]);
    $packages = Lockfile::packages($this->directory);
    self::assertSame('source-ref', $packages['example/tool']['source']);
    self::assertSame('dist-ref', $packages['example/tool']['dist']);
    self::assertStringContainsString('dist-ref', Lockfile::describe($packages['example/tool']));
    self::assertFalse(Lockfile::changed('{"content-hash":"old","packages":[]}', '{"content-hash":"new","packages":[]}'));
  }

  /**
   * Tests custom manifest selection is snapshotted before overriding COMPOSER.
   */
  public function testCustomManifestAndLockAreReadWithoutChangingThem(): void {
    file_put_contents($this->directory . '/alternate.json', '{"name":"example/custom"}');
    file_put_contents($this->directory . '/alternate.lock', '{"packages":[]}');
    $original = getenv('COMPOSER');
    putenv('COMPOSER=alternate.json');
    $workspace = NULL;
    try {
      $workspace = new Workspace($this->directory, '1.0.0');
      $manifest = json_decode(Workspace::read($workspace->path('full') . '/composer.json'), TRUE);
      self::assertSame('example/custom', $manifest['name']);
      self::assertSame('composer.json', $workspace->environment()['COMPOSER']);
      self::assertFileDoesNotExist($this->directory . '/composer.json');
    }
    finally {
      putenv($original === FALSE ? 'COMPOSER' : 'COMPOSER=' . $original);
      if ($workspace !== NULL) {
        $workspace->cleanup();
      }
    }
  }

}
