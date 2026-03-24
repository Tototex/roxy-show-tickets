<?php
namespace RoxyST;

if (!defined('ABSPATH')) exit;

class Updater {
  public static function init($config) {
    add_filter('site_transient_update_plugins', function($transient) use ($config) {
      if (empty($transient->checked)) return $transient;

      $response = wp_remote_get(
        "https://api.github.com/repos/{$config['repo']}/releases/latest",
        ['headers' => ['User-Agent' => 'WordPress']]
      );

      if (is_wp_error($response)) return $transient;

      $release = json_decode(wp_remote_retrieve_body($response));
      if (!$release || empty($release->tag_name)) return $transient;

      $version = ltrim($release->tag_name, 'v');

      if (version_compare($config['version'], $version, '<')) {
        $transient->response[$config['plugin']] = (object)[
          'slug' => $config['slug'],
          'plugin' => $config['plugin'],
          'new_version' => $version,
          'package' => $release->zipball_url,
          'url' => $release->html_url
        ];
      }

      return $transient;
    });
  }
}
