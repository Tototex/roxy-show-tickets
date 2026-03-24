<?php
namespace RoxyST;

if (!defined('ABSPATH')) exit;

class Updater {
  private static array $config = [];
  private static ?array $release = null;

  public static function init(array $config): void {
    self::$config = wp_parse_args($config, [
      'plugin_file' => '',
      'version'     => '',
      'github_repo' => '',
      'slug'        => '',
      'name'        => 'Plugin',
    ]);

    if (
      self::$config['plugin_file'] === '' ||
      self::$config['version'] === '' ||
      self::$config['github_repo'] === '' ||
      self::$config['slug'] === ''
    ) {
      return;
    }

    add_filter('site_transient_update_plugins', [__CLASS__, 'filter_update_plugins']);
    add_filter('plugins_api', [__CLASS__, 'filter_plugins_api'], 20, 3);
    add_filter('upgrader_post_install', [__CLASS__, 'filter_upgrader_post_install'], 20, 3);
  }

  public static function filter_update_plugins($transient) {
    if (!is_object($transient)) {
      $transient = new \stdClass();
    }

    if (empty($transient->checked) || !is_array($transient->checked)) {
      return $transient;
    }

    $plugin_file = (string) self::$config['plugin_file'];
    if (!isset($transient->checked[$plugin_file])) {
      return $transient;
    }

    $release = self::get_latest_release();
    if (!$release || empty($release['version']) || empty($release['download_url'])) {
      return $transient;
    }

    $current_version = (string) self::$config['version'];
    $new_version = (string) $release['version'];

    if (version_compare($new_version, $current_version, '>')) {
      $transient->response[$plugin_file] = (object) [
        'slug'        => (string) self::$config['slug'],
        'plugin'      => $plugin_file,
        'new_version' => $new_version,
        'package'     => (string) $release['download_url'],
        'url'         => (string) $release['html_url'],
        'tested'      => '',
        'requires'    => '',
        'icons'       => [],
        'banners'     => [],
        'banners_rtl' => [],
      ];
    }

    return $transient;
  }

  public static function filter_plugins_api($result, string $action, $args) {
    if ($action !== 'plugin_information' || empty($args->slug) || $args->slug !== self::$config['slug']) {
      return $result;
    }

    $release = self::get_latest_release();
    if (!$release) {
      return $result;
    }

    return (object) [
      'name'          => (string) self::$config['name'],
      'slug'          => (string) self::$config['slug'],
      'version'       => (string) ($release['version'] ?? self::$config['version']),
      'author'        => '<a href="https://github.com/' . esc_attr((string) self::$config['github_repo']) . '">Roxy AI Team</a>',
      'homepage'      => (string) ($release['html_url'] ?? ''),
      'download_link' => (string) ($release['download_url'] ?? ''),
      'requires'      => '',
      'tested'        => '',
      'sections'      => [
        'description' => 'Auto-updates from GitHub Releases.',
        'changelog'   => self::format_release_notes((string) ($release['body'] ?? '')),
      ],
    ];
  }

  public static function filter_upgrader_post_install($response, array $hook_extra, array $result) {
    if (empty($hook_extra['plugin']) || $hook_extra['plugin'] !== self::$config['plugin_file']) {
      return $response;
    }

    $plugin_dir = trailingslashit(WP_PLUGIN_DIR . '/' . dirname((string) self::$config['plugin_file']));
    if (!empty($result['destination']) && $result['destination'] !== $plugin_dir) {
      global $wp_filesystem;
      if ($wp_filesystem) {
        $wp_filesystem->move($result['destination'], $plugin_dir, true);
        $result['destination'] = $plugin_dir;
      }
    }

    if (is_plugin_active((string) self::$config['plugin_file'])) {
      activate_plugin((string) self::$config['plugin_file']);
    }

    return $response;
  }

  private static function get_latest_release(): ?array {
    if (self::$release !== null) {
      return self::$release;
    }

    $cache_key = 'roxy_st_github_release_' . md5((string) self::$config['github_repo']);
    $cached = get_transient($cache_key);
    if (is_array($cached)) {
      self::$release = $cached;
      return self::$release;
    }

    $url = 'https://api.github.com/repos/' . (string) self::$config['github_repo'] . '/releases/latest';
    $response = wp_remote_get($url, [
      'timeout' => 15,
      'headers' => [
        'Accept'     => 'application/vnd.github+json',
        'User-Agent' => 'WordPress/' . get_bloginfo('version') . '; ' . home_url('/'),
      ],
    ]);

    if (is_wp_error($response)) {
      return null;
    }

    $code = (int) wp_remote_retrieve_response_code($response);
    if ($code !== 200) {
      return null;
    }

    $data = json_decode((string) wp_remote_retrieve_body($response), true);
    if (!is_array($data)) {
      return null;
    }

    $download_url = self::find_release_zip_asset($data);
    if ($download_url === '') {
      return null;
    }

    $version = ltrim((string) ($data['tag_name'] ?? ''), 'v');
    if ($version === '') {
      return null;
    }

    self::$release = [
      'version'      => $version,
      'download_url' => $download_url,
      'html_url'     => (string) ($data['html_url'] ?? ''),
      'body'         => (string) ($data['body'] ?? ''),
    ];

    set_transient($cache_key, self::$release, 6 * HOUR_IN_SECONDS);
    return self::$release;
  }

  private static function find_release_zip_asset(array $release): string {
    $slug = (string) self::$config['slug'];
    $prefix = $slug . '-';

    if (empty($release['assets']) || !is_array($release['assets'])) {
      return '';
    }

    foreach ($release['assets'] as $asset) {
      if (!is_array($asset)) continue;

      $name = (string) ($asset['name'] ?? '');
      $url  = (string) ($asset['browser_download_url'] ?? '');

      if ($url === '') continue;

      if ($name === $slug . '.zip') {
        return $url;
      }

      if (substr($name, -4) === '.zip' && strpos($name, $prefix) === 0) {
        return $url;
      }
    }

    return '';
  }

  private static function format_release_notes(string $body): string {
    $body = trim($body);
    if ($body === '') {
      return '<p>No release notes provided.</p>';
    }

    $lines = preg_split('/\r\n|\r|\n/', $body);
    $html = '';
    $in_list = false;

    foreach ((array) $lines as $line) {
      $line = trim($line);
      if ($line === '') {
        if ($in_list) {
          $html .= '</ul>';
          $in_list = false;
        }
        continue;
      }

      if (preg_match('/^[-*]\s+(.+)$/', $line, $m)) {
        if (!$in_list) {
          $html .= '<ul>';
          $in_list = true;
        }
        $html .= '<li>' . esc_html($m[1]) . '</li>';
      } else {
        if ($in_list) {
          $html .= '</ul>';
          $in_list = false;
        }
        $html .= '<p>' . esc_html($line) . '</p>';
      }
    }

    if ($in_list) {
      $html .= '</ul>';
    }

    return $html;
  }
}
