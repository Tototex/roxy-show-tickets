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

    add_filter('pre_set_site_transient_update_plugins', [__CLASS__, 'filter_update_plugins']);
    add_filter('plugins_api', [__CLASS__, 'filter_plugins_api'], 20, 3);
    add_filter('upgrader_post_install', [__CLASS__, 'filter_upgrader_post_install'], 20, 3);
  }

  public static function filter_update_plugins($transient) {
    if (!is_object($transient)) {
      $transient = new \stdClass();
    }

    $release = self::get_latest_release();
    if (!$release) return $transient;

    $plugin_file = self::$config['plugin_file'];
    $current_version = self::$config['version'];
    $new_version = $release['version'];

    if (version_compare($new_version, $current_version, '>')) {
      $transient->response[$plugin_file] = (object) [
        'slug'        => self::$config['slug'],
        'plugin'      => $plugin_file,
        'new_version' => $new_version,
        'package'     => $release['download_url'],
        'url'         => $release['html_url'],
      ];
    }

    return $transient;
  }

  public static function filter_plugins_api($result, string $action, $args) {
    if ($action !== 'plugin_information' || empty($args->slug) || $args->slug !== self::$config['slug']) {
      return $result;
    }

    $release = self::get_latest_release();
    if (!$release) return $result;

    return (object) [
      'name'          => self::$config['name'],
      'slug'          => self::$config['slug'],
      'version'       => $release['version'],
      'download_link' => $release['download_url'],
      'sections'      => [
        'description' => 'Auto updates from GitHub',
      ],
    ];
  }

  private static function get_latest_release(): ?array {
    if (self::$release !== null) return self::$release;

    $url = 'https://api.github.com/repos/' . self::$config['github_repo'] . '/releases/latest';

    $response = wp_remote_get($url, [
      'headers' => [
        'Accept' => 'application/vnd.github+json',
        'User-Agent' => 'WordPress'
      ]
    ]);

    if (is_wp_error($response)) return null;

    $data = json_decode(wp_remote_retrieve_body($response), true);
    if (!$data) return null;

    foreach ($data['assets'] as $asset) {
      if (strpos($asset['name'], self::$config['slug']) === 0 && substr($asset['name'], -4) === '.zip') {
        self::$release = [
          'version' => ltrim($data['tag_name'], 'v'),
          'download_url' => $asset['browser_download_url'],
          'html_url' => $data['html_url']
        ];
        return self::$release;
      }
    }

    return null;
  }

  public static function filter_upgrader_post_install($response, $hook_extra, $result) {
    if (!empty($hook_extra['plugin']) && $hook_extra['plugin'] === self::$config['plugin_file']) {
      activate_plugin(self::$config['plugin_file']);
    }

    return $response;
  }
}
