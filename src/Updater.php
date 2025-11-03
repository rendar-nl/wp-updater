<?php
declare( strict_types=1 );

namespace Rendar\Updater;
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class Updater {
    private string $slug;
    private string $pluginBasename;
    private string $version;
    private string $endpoint;
    private int $cacheTtl;
    private array $args;
    private string $transientKey;
    private const DEFAULT_BASE = 'https://wp-plugins.rendar.nl';


    public function __construct( string $slug, string $plugin_basename, string $version, string $endpoint = '', int $cache_ttl = 300, array $args = [] ) {
        $this->slug           = $slug;
        $this->pluginBasename = $plugin_basename;
        $this->version        = $version;
        $this->endpoint       = $endpoint;
        $this->cacheTtl       = $cache_ttl;
        $this->args           = $args;
        $this->transientKey   = 'rendar_updater_' . sanitize_key( $slug );

        if ($endpoint === '' || $endpoint === null) {
            $base = self::DEFAULT_BASE;
            $base = rtrim((string) apply_filters('rendar_updater_base', rtrim($base, '/'), $slug, $plugin_basename), '/');
            $this->endpoint = $base . '/' . $slug . '/update.json';
        } else {
            $this->endpoint = $endpoint;
        }

        add_filter( 'pre_set_site_transient_update_plugins', [ $this, 'filterUpdateTransient' ] );
        add_filter( 'plugins_api', [ $this, 'filterPluginsApi' ], 10, 3 );
        add_filter( 'upgrader_pre_download', [ $this, 'filterUpgraderPreDownload' ], 10, 4 );
    }

    public function filterUpdateTransient( $t ) {
        if ( ! is_object( $t ) || empty( $t->checked ) || ! isset( $t->checked[ $this->pluginBasename ] ) ) {
            return $t;
        }
        $m = $this->getMetadata();
        if ( ! $m ) {
            return $t;
        }
        $rv = (string) ( $m['version'] ?? '' );
        if ( $rv === '' || version_compare( $rv, $this->version, '<=' ) ) {
            return $t;
        }
        $u = (object) [ 'id'           => $this->slug,
                        'slug'         => $this->slug,
                        'plugin'       => $this->pluginBasename,
                        'new_version'  => $rv,
                        'url'          => (string) ( $m['homepage'] ?? '' ),
                        'package'      => (string) ( $m['download_url'] ?? '' ),
                        'tested'       => (string) ( $m['tested'] ?? '' ),
                        'requires_php' => (string) ( $m['requires_php'] ?? '' )
        ];
        if ( ! empty( $m['banners']['low'] ) ) {
            $u->banners = [ 'low' => $m['banners']['low'], 'high' => $m['banners']['high'] ?? $m['banners']['low'] ];
        }
        $t->response[ $this->pluginBasename ] = $u;

        return $t;
    }

    public function filterPluginsApi( $r, string $a, $args ) {
        if ( $a !== 'plugin_information' || ! isset( $args->slug ) || $args->slug !== $this->slug ) {
            return $r;
        }
        $m = $this->getMetadata();
        if ( ! $m ) {
            return $r;
        }
        $i                = is_object( $r ) ? $r = new \stdClass();
        $i->name          = (string) ( $m['name'] ?? $this->slug );
        $i->slug          = $this->slug;
        $i->version       = (string) ( $m['version'] ?? '' );
        $i->author        = (string) ( $m['author'] ?? '' );
        $i->homepage      = (string) ( $m['homepage'] ?? '' );
        $i->requires      = (string) ( $m['requires'] ?? '' );
        $i->tested        = (string) ( $m['tested'] ?? '' );
        $i->requires_php  = (string) ( $m['requires_php'] ?? '' );
        $i->sections      = (array) ( $m['sections'] ?? [] );
        $i->download_link = (string) ( $m['download_url'] ?? '' );
        $i->banners       = ! empty( $m['banners'] ) ? (array) $m['banners'] : [];

        return $i;
    }

    public function filterUpgraderPreDownload( $reply, $package, $upgrader, $extra ) {
        $m = $this->getMetadata();
        if ( ! $m ) {
            return $reply;
        }
        $url = (string) ( $m['download_url'] ?? '' );
        $sha = (string) ( $m['package_sha256'] ?? '' );
        if ( ! $url || (string) $package !== $url ) {
            return $reply;
        }
        $tmp = wp_tempnam( $url );
        if ( ! $tmp ) {
            return new \WP_Error( 'rendar_updater_tmp', 'Could not create a temporary file for download.' );
        }
        $h = [ 'Accept' => 'application/zip,*/*;q=0.1' ];
        if ( ! empty( $this->args['headers'] ) && is_array( $this->args['headers'] ) ) {
            $h = array_merge( $h, $this->args['headers'] );
        }
        if ( ! empty( $this->args['user_agent'] ) ) {
            $h['User-Agent'] = (string) $this->args['user_agent'];
        }
        $resp = wp_remote_get( $url, [ 'timeout' => 60, 'stream' => true, 'filename' => $tmp, 'headers' => $h ] );
        if ( is_wp_error( $resp ) ) {
            @unlink( $tmp );

            return $resp;
        }
        $code = (int) wp_remote_retrieve_response_code( $resp );
        if ( $code < 200 || $code >= 300 ) {
            @unlink( $tmp );

            return new \WP_Error( 'rendar_updater_http', 'Download failed with HTTP ' . $code );
        }
        if ( ! defined( 'RENDAR_UPDATER_SKIP_HASH' ) || ! RENDAR_UPDATER_SKIP_HASH ) {
            if ( $sha !== '' ) {
                $hash = hash_file( 'sha256', $tmp );
                if ( ! hash_equals( $sha, $hash ) ) {
                    @unlink( $tmp );

                    return new \WP_Error( 'rendar_updater_hash', 'Package integrity check failed (sha256 mismatch).' );
                }
            }
        }

        return $tmp;
    }

    private function getMetadata(): ?array {
        $c = get_site_transient( $this->transientKey );
        if ( is_array( $c ) && ! empty( $c['_ts'] ) && ( time() - (int) $c['_ts'] < $this->cacheTtl ) ) {
            return $c['data'] ?? null;
        }
        $ep = apply_filters( 'rendar_updater_endpoint', $this->endpoint, $this->slug, $this->pluginBasename );
        $h  = [ 'Accept' => 'application/json' ];
        if ( ! empty( $this->args['headers'] ) && is_array( $this->args['headers'] ) ) {
            $h = array_merge( $h, $this->args['headers'] );
        }
        if ( ! empty( $this->args['user_agent'] ) ) {
            $h['User-Agent'] = (string) $this->args['user_agent'];
        }
        $r = wp_remote_get( $ep, [ 'timeout' => 10, 'headers' => $h ] );
        if ( is_wp_error( $r ) ) {
            return null;
        }
        $code = (int) wp_remote_retrieve_response_code( $r );
        if ( $code < 200 || $code >= 300 ) {
            return null;
        }
        $body = wp_remote_retrieve_body( $r );
        $j    = json_decode( (string) $body, true );
        if ( ! is_array( $j ) ) {
            return null;
        }
        $j = apply_filters( 'rendar_updater_metadata', $j, $this->slug, $this->pluginBasename );
        set_site_transient( $this->transientKey, [ '_ts' => time(), 'data' => $j ], $this->cacheTtl );

        return $j;
    }
}
