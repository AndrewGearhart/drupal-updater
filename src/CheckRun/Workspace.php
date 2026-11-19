<?php

namespace DrupalUpdater\CheckRun;

/**
 * Owns disposable Composer inputs and a cache shared by the two resolutions.
 */
final class Workspace {

  /**
   * Private temporary directory owned by this check.
   */
  private string $directory;

  /**
   * Creates independent full and stepped inputs without copying vendor or Git.
   */
  public function __construct(string $source, private string $rootVersion) {
    $this->directory = sys_get_temp_dir() . '/drupal-updater-check-' . bin2hex(random_bytes(12));
    if (!mkdir($this->directory, 0700)) {
      throw new \RuntimeException('Cannot create check workspace.');
    }
    try {
      $manifest = getenv('COMPOSER') ?: 'composer.json';
      if (!self::isAbsolute($manifest)) {
        $manifest = $source . '/' . $manifest;
      }
      $lock = str_ends_with($manifest, '.json') ? substr($manifest, 0, -5) . '.lock' : $manifest . '.lock';
      $json = json_decode(self::read($manifest), TRUE, 512, JSON_THROW_ON_ERROR);
      if (!is_array($json)) {
        throw new \RuntimeException('Composer manifest must be an object.');
      }
      // Explicit child cache paths otherwise override COMPOSER_CACHE_DIR.
      $cache_paths = [
        'cache-dir' => '',
        'cache-files-dir' => '/files',
        'cache-repo-dir' => '/repo',
        'cache-vcs-dir' => '/vcs',
      ];
      foreach ($cache_paths as $key => $suffix) {
        $json['config'][$key] = $this->path('cache') . $suffix;
      }
      // Relative repositories must still refer to the original project.
      foreach ($json['repositories'] ?? [] as $key => $repository) {
        if (is_array($repository) && isset($repository['url']) && is_string($repository['url']) && !self::isAbsolute($repository['url'])) {
          $json['repositories'][$key]['url'] = $source . '/' . $repository['url'];
        }
      }
      $contents = json_encode($json, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
      $original_lock = self::read($lock);
      foreach (['full', 'stepped'] as $name) {
        if (!mkdir($this->path($name), 0700)) {
          throw new \RuntimeException('Cannot create resolution directory.');
        }
        self::write($this->path($name) . '/composer.json', $contents);
        self::write($this->path($name) . '/composer.lock', $original_lock);
        if (is_file($source . '/auth.json')) {
          self::write($this->path($name) . '/auth.json', self::read($source . '/auth.json'));
        }
      }
    }
    catch (\Exception $exception) {
      $this->cleanup();
      throw $exception;
    }
  }

  /**
   * Gets a path within the check workspace.
   */
  public function path(string $name): string {
    return $this->directory . '/' . $name;
  }

  /**
   * Isolates cache writes and custom manifest/vendor environment overrides.
   *
   * COMPOSER_HOME and COMPOSER_AUTH remain inherited for repository access.
   *
   * @return array<string, string>
   *   Environment variables for all check commands.
   */
  public function environment(): array {
    return [
      'COMPOSER' => 'composer.json',
      'COMPOSER_CACHE_DIR' => $this->path('cache'),
      'COMPOSER_ROOT_VERSION' => $this->rootVersion,
    ];
  }

  /**
   * Reads a required input file.
   */
  public static function read(string $path): string {
    if (!is_file($path) || !is_readable($path)) {
      throw new \RuntimeException('Cannot read ' . $path);
    }
    $contents = file_get_contents($path);
    if ($contents === FALSE) {
      throw new \RuntimeException('Cannot read ' . $path);
    }
    return $contents;
  }

  /**
   * Writes a private file in the owned workspace.
   */
  public static function write(string $path, string $contents): void {
    if (file_put_contents($path, $contents) !== strlen($contents) || !chmod($path, 0600)) {
      throw new \RuntimeException('Cannot write ' . $path);
    }
  }

  /**
   * Removes the workspace, including authentication copies and cache clones.
   */
  public function cleanup(): void {
    self::remove($this->directory);
  }

  /**
   * Determines whether a repository URL/path is already absolute.
   */
  private static function isAbsolute(string $path): bool {
    return preg_match('{^(?:[a-z][a-z0-9+.-]*:|[^/\\\\\s]+@[^/\\\\\s]+:|[/\\\\])}i', $path) === 1;
  }

  /**
   * Deletes only owned temporary paths, without following symlinks.
   */
  private static function remove(string $path): void {
    if (is_link($path) || is_file($path)) {
      if (!unlink($path)) {
        throw new \RuntimeException('Cannot remove check file ' . $path);
      }
    }
    elseif (is_dir($path)) {
      foreach (array_diff(scandir($path) ?: [], ['.', '..']) as $name) {
        self::remove($path . '/' . $name);
      }
      if (!rmdir($path)) {
        throw new \RuntimeException('Cannot remove check directory ' . $path);
      }
    }
  }

}
