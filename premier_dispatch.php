<?php
/**
 * Plugin Name:  FEZ International
 * Description:  Version 64 — Two-tier architecture. Sync manages structure only. Prices managed entirely at checkout.
 * Version:      65.0.0
 * Author:       Author: Comfort Inyang
 * Update URI:   https://github.com/lenoireee/fez-international
 */

use RYSE\GitHubUpdaterDemo\GitHubUpdater;
if ( ! defined( 'ABSPATH' ) ) exit;

// ---------------------------------------------------------------------------
// CONSTANTS
// ---------------------------------------------------------------------------
// Shared Fez auth, logging and HTTP layer — shared with the domestic plugin.
// All functions are guarded with function_exists() so whichever plugin loads
// first defines them. The second plugin safely skips redefinition.

require_once plugin_dir_path( __FILE__ ) . 'pd-shared.php';
require_once plugin_dir_path( __FILE__ ) . 'github-updater.php';

$updater = new GitHubUpdater(__FILE__);
$updater->setBranch('wordpress');
$updater->add();

define( 'PD_VERSION',         '65.0.0' );
define( 'PD_RATE_TABLE_KEY',  'pd_rate_table' );     // transient — country structure, no prices
define( 'PD_RATE_BACKUP_KEY', 'pd_rate_table_bk' );  // wp_option  — last known good, no expiry
define( 'PD_SYNC_LOCK',       'pd_sync_lock' );       // transient  — prevents duplicate syncs


// ---------------------------------------------------------------------------
// MULTISITE-SAFE KEY HELPERS
// Every transient and option is scoped to the current blog ID so each site
// in a multisite network has a fully isolated dataset.
// ---------------------------------------------------------------------------
function pd_key( $key ) {
    return $key . '_' . get_current_blog_id();
}

function pd_price_key( $cc, $weight_id ) {
    return 'pd_price_' . strtoupper( $cc ) . '_' . (int) $weight_id . '_' . get_current_blog_id();
}

function pd_fetch_lock_key( $cc ) {
    return 'pd_flk_' . strtoupper( $cc ) . '_' . get_current_blog_id();
}


// ---------------------------------------------------------------------------
// HTTP HELPERS
// pd_log(), pd_is_auth_failure(), pd_api_request(), pd_fez_auth()
// are all defined in pd-shared.php (loaded above).
// ---------------------------------------------------------------------------

// pd_is_auth_failure(), pd_api_request(), pd_fez_auth() — defined in pd-shared.php


// ---------------------------------------------------------------------------
// PRICE CACHE WRITE
// Always overwrites via set_transient → update_option → ON DUPLICATE KEY UPDATE.
// TTL is 7 days so the row always exists for stale fallback reads.
// Freshness is controlled by cached_at vs cache_hours at read time, not TTL.
// ---------------------------------------------------------------------------
function pd_write_price( $cc, $weight_id, $price, $weight_val ) {
    set_transient(
        pd_price_key( $cc, $weight_id ),
        [
            'price'     => (float) $price,
            'cached_at' => time(),
            'cc'        => strtoupper( $cc ),
            'weight'    => (float) $weight_val,
            'weight_id' => (int) $weight_id,
        ],
        7 * DAY_IN_SECONDS
    );
}


// ---------------------------------------------------------------------------
// PRICE CACHE FLUSH
// Removes all pd_price_* transients for this blog.
// Called when a new rate table is built (weight IDs may have changed).
// Also called when cache_hours setting changes.
// ---------------------------------------------------------------------------
function pd_flush_prices() {
    global $wpdb;
    $blog_id = get_current_blog_id();
    $like_v  = $wpdb->esc_like( '_transient_pd_price_' ) . '%_' . $blog_id;
    $like_t  = $wpdb->esc_like( '_transient_timeout_pd_price_' ) . '%_' . $blog_id;
    $wpdb->query(
        $wpdb->prepare(
            "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
            $like_v, $like_t
        )
    );
    pd_log( 'SYNC', 'INFO', 'Price cache flushed' );
}


// ---------------------------------------------------------------------------
// WC CLASS LOADER
// In a WP-Cron context, woocommerce_shipping_init may not have fired yet.
// This ensures the shipping class is available before sync or checkout logic runs.
// ---------------------------------------------------------------------------
function pd_ensure_wc_loaded() {
    if ( ! class_exists( 'WC_Shipping_Premier_Dispatch' ) ) {
        if ( function_exists( 'WC' ) && WC()->shipping() ) {
            do_action( 'woocommerce_shipping_init' );
        }
    }
}


// ---------------------------------------------------------------------------
// GET PLUGIN INSTANCE
// Walks WooCommerce shipping zones to find a real configured instance
// so get_option() calls load the correct settings row from the DB.
// Falls back to a fresh instance if none found (settings still load from DB).
// ---------------------------------------------------------------------------
function pd_get_instance() {
    if ( ! function_exists( 'WC' ) || ! class_exists( 'WC_Shipping_Premier_Dispatch' ) ) {
        return null;
    }

    $all_zones = array_merge(
        WC_Shipping_Zones::get_zones(),
        [ [ 'zone_id' => 0 ] ] // zone 0 = Rest of World
    );

    foreach ( $all_zones as $zone_data ) {
        $zone = new WC_Shipping_Zone( $zone_data['zone_id'] );
        foreach ( $zone->get_shipping_methods( false ) as $method ) {
            if ( $method instanceof WC_Shipping_Premier_Dispatch ) {
                return $method;
            }
        }
    }

    return new WC_Shipping_Premier_Dispatch();
}


// ---------------------------------------------------------------------------
// WEIGHT CONVERSION
// WooCommerce stores weights in the unit configured under Settings > Units.
// Fez API expects kg. Convert any supported unit to kg.
// ---------------------------------------------------------------------------
function pd_to_kg( $weight, $unit ) {
    switch ( strtolower( $unit ) ) {
        case 'kg':  return (float) $weight;
        case 'g':   return (float) $weight / 1000;
        case 'lbs': return (float) $weight * 0.453592;
        case 'oz':  return (float) $weight * 0.0283495;
        default:    return (float) $weight; // unknown unit — pass through
    }
}


// ---------------------------------------------------------------------------
// SYNC — STRUCTURE ONLY
// One API call. Diffs against existing table:
//   - New countries added (no prices — fetched on first checkout)
//   - Removed countries deleted (their price caches also cleared)
//   - Existing countries untouched (prices managed by checkout flow)
// Fast, predictable, no price API calls.
// ---------------------------------------------------------------------------
function pd_run_sync() {
    pd_ensure_wc_loaded();

    $lock_key = pd_key( PD_SYNC_LOCK );

    if ( get_transient( $lock_key ) ) {
        pd_log( 'SYNC', 'WARN', 'Sync already running — skipped duplicate' );
        return false;
    }
    set_transient( $lock_key, 1, 3 * MINUTE_IN_SECONDS );

    // Use existing shared token if valid — don't force re-auth and risk
    // invalidating the token the other Fez plugin is currently using.
    // Only re-authenticate if no valid token exists.
    $auth = pd_fez_auth( false );
    if ( ! $auth ) {
        pd_log( 'SYNC', 'ERROR', 'Sync auth failed' );
        delete_transient( $lock_key );
        return false;
    }

    // pd_api_request handles 401 detection, token refresh, and retry automatically
    $res = pd_api_request( 'GET', FEZ_API_BASE . '/orders/export-locations', [
        'timeout' => 20,
    ] );

    if ( is_wp_error( $res ) ) {
        pd_log( 'SYNC', 'ERROR', 'export-locations request failed', [ 'error' => $res->get_error_message() ] );
        delete_transient( $lock_key );
        return false;
    }

    $body      = json_decode( wp_remote_retrieve_body( $res ), true );
    $locations = $body['data']['exportLocations'] ?? [];

    if ( empty( $locations ) ) {
        pd_log( 'SYNC', 'ERROR', 'export-locations returned no data' );
        delete_transient( $lock_key );
        return false;
    }

    // Build fresh structure from API response
    $fresh      = [];
    $built_at   = time();

    foreach ( $locations as $loc ) {
        $cc  = strtoupper( $loc['country_code'] ?? '' );
        $lid = $loc['id'] ?? null;
        if ( ! $cc || ! $lid || $cc === 'NG' ) continue;

        $weight_map = $loc['int_price'] ?? [];
        if ( empty( $weight_map ) ) continue;

        usort( $weight_map, fn( $a, $b ) =>
            (float) $a['weight']['name'] <=> (float) $b['weight']['name']
        );

        $tiers = [];
        foreach ( $weight_map as $tier ) {
            $wid = (int)   ( $tier['weight']['id']   ?? 0 );
            $wt  = (float) ( $tier['weight']['name'] ?? 0 );
            if ( ! $wid ) continue;
            $tiers[] = [ 'weight_id' => $wid, 'weight' => $wt ];
        }
        if ( empty( $tiers ) ) continue;

        $fresh[ $cc ] = [
            'location_id' => $lid,
            'tiers'       => $tiers,
            'built_at'    => $built_at,
        ];
    }

    if ( empty( $fresh ) ) {
        pd_log( 'SYNC', 'WARN', 'Fresh table built empty — preserving existing table' );
        delete_transient( $lock_key );
        return false;
    }

    // Diff against existing table
    $existing = get_transient( pd_key( PD_RATE_TABLE_KEY ) )
             ?: get_option( pd_key( PD_RATE_BACKUP_KEY ), [] );

    $added   = array_diff_key( $fresh, $existing );
    $removed = array_diff_key( $existing, $fresh );

    // For removed countries, delete their cached prices
    if ( ! empty( $removed ) ) {
        global $wpdb;
        foreach ( array_keys( $removed ) as $cc ) {
            $blog_id = get_current_blog_id();
            $like_v  = $wpdb->esc_like( '_transient_pd_price_' . $cc . '_' ) . '%_' . $blog_id;
            $like_t  = $wpdb->esc_like( '_transient_timeout_pd_price_' . $cc . '_' ) . '%_' . $blog_id;
            $wpdb->query(
                $wpdb->prepare(
                    "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
                    $like_v, $like_t
                )
            );
        }
        pd_log( 'SYNC', 'INFO', 'Countries removed from table', [ 'removed' => array_keys( $removed ) ] );
    }

    // Merge: keep existing entries (with their price-linked data intact),
    // add new ones, discard removed ones.
    // Existing country structures are updated if weight tiers changed.
    $merged = [];
    foreach ( $fresh as $cc => $entry ) {
        if ( isset( $existing[ $cc ] ) ) {
            // Keep built_at from existing so we know original cache date
            // but update tiers in case Fez changed weight structure
            $existing_tiers  = array_column( $existing[ $cc ]['tiers'], 'weight_id' );
            $fresh_tiers     = array_column( $entry['tiers'], 'weight_id' );
            $tiers_changed   = $existing_tiers !== $fresh_tiers;

            if ( $tiers_changed ) {
                // Weight IDs changed — flush prices for this country only
                global $wpdb;
                $blog_id = get_current_blog_id();
                $like_v  = $wpdb->esc_like( '_transient_pd_price_' . $cc . '_' ) . '%_' . $blog_id;
                $like_t  = $wpdb->esc_like( '_transient_timeout_pd_price_' . $cc . '_' ) . '%_' . $blog_id;
                $wpdb->query(
                    $wpdb->prepare(
                        "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
                        $like_v, $like_t
                    )
                );
                pd_log( 'SYNC', 'INFO', 'Weight tiers changed — country prices flushed', [ 'cc' => $cc ] );
            }

            $merged[ $cc ] = array_merge( $existing[ $cc ], [
                'location_id' => $entry['location_id'],
                'tiers'       => $entry['tiers'],
            ] );
        } else {
            // New country
            $merged[ $cc ] = $entry;
        }
    }

    $inst        = pd_get_instance();
    $cache_hours = $inst ? ( (int) $inst->get_option( 'cache_hours' ) ?: 24 ) : 24;

    // Write before releasing lock — no gap window
    set_transient( pd_key( PD_RATE_TABLE_KEY ), $merged, $cache_hours * HOUR_IN_SECONDS );
    update_option( pd_key( PD_RATE_BACKUP_KEY ), $merged, false );

    delete_transient( $lock_key );

    pd_log( 'SYNC', 'INFO', 'Sync complete', [ 'total' => count( $merged ), 'added' => count( $added ), 'removed' => count( $removed ) ] );

    return $merged;
}


// ---------------------------------------------------------------------------
// CRON SPAWN
// Nudges WP-Cron via a non-blocking GET to wp-cron.php.
// More reliable than admin-post.php loopback on most hosts.
// If loopback is blocked, scheduled events fire on the next real page visit.
// ---------------------------------------------------------------------------
function pd_spawn_cron() {
    wp_remote_get(
        add_query_arg( 'doing_wp_cron', '', site_url( 'wp-cron.php' ) ),
        [
            'blocking'  => false,
            'sslverify' => apply_filters( 'https_local_ssl_verify', false ),
            'timeout'   => 1,
            'headers'   => [ 'Cache-Control' => 'no-cache' ],
        ]
    );
}


// ---------------------------------------------------------------------------
// SHIPPING METHOD CLASS
// ---------------------------------------------------------------------------
function premier_dispatch_init_v64() {
    if ( ! class_exists( 'WC_Shipping_Method' ) ) return;

    class WC_Shipping_Premier_Dispatch extends WC_Shipping_Method {

        public function __construct( $instance_id = 0 ) {
            $this->id                 = 'fez_shipping';
            $this->instance_id        = absint( $instance_id );
            $this->method_title       = 'Premium Dispatch';
            $this->method_description = 'Global shipping via Fez Delivery.';
            $this->supports           = [ 'shipping-zones', 'instance-settings', 'settings' ];
            $this->init();
        }

        public function init() {
            $this->init_form_fields();
            $this->init_settings();
            $this->title = 'Premium Dispatch';

            add_action( 'woocommerce_update_options_shipping_' . $this->id, [ $this, 'process_admin_options' ] );
            add_action( 'woocommerce_update_options_shipping_' . $this->id, [ $this, 'on_settings_saved' ] );

            if ( is_admin() && isset( $_POST['pd_manual_sync'] ) ) {
                if ( check_admin_referer( 'pd_sync_action', 'pd_sync_nonce' ) ) {
                    $this->trigger_manual_sync();
                }
            }
        }

        // -----------------------------------------------------------------------
        // SETTINGS SAVED
        // -----------------------------------------------------------------------
        public function on_settings_saved() {
            $new_hours = (int) $this->get_option( 'cache_hours' );
            $prev_key  = pd_key( 'pd_prev_cache_hours' );
            $old_hours = (int) get_option( $prev_key );

            if ( $new_hours !== $old_hours ) {
                pd_flush_prices();
                update_option( $prev_key, $new_hours, false );
            }

            $this->reschedule_sync();
        }

        // -----------------------------------------------------------------------
        // FORM FIELDS
        // -----------------------------------------------------------------------
        public function init_form_fields() {
            $this->form_fields = [
                'enabled' => [
                    'title'   => 'Enable Premium Dispatch',
                    'type'    => 'checkbox',
                    'default' => 'yes',
                ],
                'shipping_exchange_rate' => [
                    'title'             => 'Exchange Rate (NGN per 1 store unit)',
                    'type'              => 'number',
                    'default'           => '1300',
                    'description'       => 'Applied live at checkout. Changes take effect immediately without a sync.',
                    'custom_attributes' => [ 'step' => '1', 'min' => '1' ],
                ],
                'extra_expense' => [
                    'title'             => 'Handling Fee (NGN)',
                    'type'              => 'number',
                    'default'           => '4500',
                    'description'       => 'Added to every shipment at checkout.',
                    'custom_attributes' => [ 'step' => '1', 'min' => '0' ],
                ],
                'cache_hours' => [
                    'title'             => 'Price Cache Duration (Hours)',
                    'type'              => 'number',
                    'default'           => '24',
                    'description'       => 'How long a fetched price is considered fresh before a live refresh is attempted. Changing this flushes all cached prices.',
                    'custom_attributes' => [ 'step' => '1', 'min' => '1' ],
                ],
                'stale_multiplier' => [
                    'title'             => 'Stale Price Multiplier',
                    'type'              => 'number',
                    'default'           => '1.15',
                    'description'       => 'Applied only when a cached price is stale AND all live fetch attempts fail. E.g. 1.15 = +15% buffer to avoid undercharging.',
                    'custom_attributes' => [ 'step' => '0.01', 'min' => '1.00' ],
                ],
                'default_product_weight' => [
                    'title'             => 'Default Product Weight (kg)',
                    'type'              => 'number',
                    'default'           => '0.5',
                    'description'       => 'Used for products with no weight set. Virtual/downloadable products are excluded from weight calculation.',
                    'custom_attributes' => [ 'step' => '0.1', 'min' => '0.1' ],
                ],
                'sync_frequency' => [
                    'title'       => 'Sync Frequency',
                    'type'        => 'select',
                    'default'     => 'twicedaily',
                    'description' => 'How often the background sync checks for new or removed countries.',
                    'options'     => [
                        'hourly'     => 'Hourly',
                        'twicedaily' => 'Twice Daily (12 hrs)',
                        'daily'      => 'Daily (24 hrs)',
                    ],
                ],
                'sync_button' => [
                    'title' => 'Status',
                    'type'  => 'pd_sync_button',
                ],
            ];
        }

        // -----------------------------------------------------------------------
        // ADMIN STATUS PANEL
        // -----------------------------------------------------------------------
        public function generate_pd_sync_button_html() {
            $table      = get_transient( pd_key( PD_RATE_TABLE_KEY ) );
            $is_syncing = (bool) get_transient( pd_key( PD_SYNC_LOCK ) );
            $count      = $table ? count( $table ) : 0;
            $next_sync  = wp_next_scheduled( 'pd_sync_event' );
            $age_str = '';
            if ( $table ) {
                $first   = reset( $table );
                $age_str = isset( $first['built_at'] )
                    ? ', built ' . human_time_diff( $first['built_at'] ) . ' ago'
                    : '';
            }

            $cache_h  = (int) $this->get_option( 'cache_hours' ) ?: 24;
            $freq     = $this->get_option( 'sync_frequency' ) ?: 'twicedaily';
            $freq_h   = [ 'hourly' => 1, 'twicedaily' => 12, 'daily' => 24 ];
            $gap_warn = $cache_h > ( $freq_h[ $freq ] ?? 24 );

            ob_start(); ?>
            <tr valign="top">
                <th scope="row" class="titledesc">Status</th>
                <td class="forminp">
                    <?php wp_nonce_field( 'pd_sync_action', 'pd_sync_nonce' ); ?>
                    <button name="pd_manual_sync" type="submit" class="button-secondary"
                            value="1" id="pd-sync-btn"
                            <?php disabled( $is_syncing, true ); ?>>
                        <?php echo $is_syncing ? 'Syncing...' : 'Force Refresh Now'; ?>
                    </button>

                    <p class="description" id="pd-status-line">
                        <?php if ( $is_syncing ): ?>
                            &#9203; <strong>Sync running</strong>
                        <?php elseif ( $count ): ?>
                            &#9989; <strong><?php echo esc_html( $count ); ?> countries cached</strong><?php echo esc_html( $age_str ); ?>
                        <?php else: ?>
                            &#9888; <strong>Rate table not built yet.</strong> Click Force Refresh Now.
                        <?php endif; ?>
                        &nbsp;|&nbsp; Next auto-refresh:
                        <strong>
                            <?php echo $next_sync
                                ? esc_html( human_time_diff( time(), $next_sync ) . ' from now' )
                                : '&#10060; not scheduled &mdash; re-save settings'; ?>
                        </strong>
                    </p>

                    <?php if ( $gap_warn ): ?>
                    <p class="description" style="color:#b32d2e;">
                        &#9888; Cache Duration (<?php echo esc_html( $cache_h ); ?>h) exceeds sync frequency
                        (<?php echo esc_html( $freq_h[ $freq ] ?? '?' ); ?>h).
                        Prices may expire before the next sync fires.
                    </p>
                    <?php endif; ?>

                    <?php
                    $stale_count = (int) get_option( pd_key( 'pd_stale_count' ), 0 );
                    $stale_last  = get_option( pd_key( 'pd_stale_last' ), null );
                    if ( $stale_count > 0 ): ?>
                    <p class="description" style="color:#b32d2e;">
                        &#9888; <strong>Stale multiplier applied <?php echo esc_html( $stale_count ); ?> time(s)</strong>
                        since last reset.
                        <?php if ( $stale_last ): ?>
                            Last: <strong><?php echo esc_html( $stale_last['cc'] ); ?></strong>,
                            price was <?php echo esc_html( human_time_diff( $stale_last['at'] ) ); ?> ago
                            (cache age <?php echo esc_html( round( $stale_last['age'] / 3600, 1 ) ); ?>h).
                        <?php endif; ?>
                        This means the Fez API failed to return a fresh price — check your error log.
                        <a href="<?php echo esc_url( add_query_arg( 'pd_reset_stale', '1' ) ); ?>">Reset counter</a>
                    </p>
                    <?php endif; ?>

                    <p class="description" style="color:#555;margin-top:8px;">
                        Add a real server cron for reliable scheduling on low-traffic sites:<br>
                        <code>*/<?php echo esc_html( ( $freq_h[ $freq ] ?? 12 ) * 60 ); ?> * * * * wget -q -O /dev/null "<?php echo esc_url( site_url( '/wp-cron.php?doing_wp_cron' ) ); ?>"</code>
                    </p>
                    <p class="description" style="color:#555;">
                        Exclude the checkout page from any full-page cache (Cloudflare, Nginx, WP Rocket)
                        to prevent stale shipping rates being served to customers.
                    </p>
                </td>
            </tr>
            <script>
            (function() {
                var ajaxUrl = <?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>;
                var nonce   = <?php echo wp_json_encode( wp_create_nonce( 'pd_status_nonce' ) ); ?>;
                var syncing = <?php echo $is_syncing ? 'true' : 'false'; ?>;
                var status  = document.getElementById( 'pd-status-line' );
                var timer   = null;

                function poll() {
                    fetch( ajaxUrl + '?action=pd_sync_status&_wpnonce=' + encodeURIComponent( nonce ) )
                        .then( function(r) { return r.json(); } )
                        .then( function(d) {
                            if ( ! d.syncing ) { clearInterval( timer ); window.location.reload(); }
                        } )
                        .catch( function() {} );
                }

                if ( syncing ) { timer = setInterval( poll, 4000 ); }
            })();
            </script>
            <?php
            return ob_get_clean();
        }

        // -----------------------------------------------------------------------
        // MANUAL SYNC TRIGGER
        // Tier 1 = one API call, runs inline (fast, no 504 risk).
        // -----------------------------------------------------------------------
        private function trigger_manual_sync() {
            if ( get_transient( pd_key( PD_SYNC_LOCK ) ) ) {
                add_action( 'admin_notices', function() {
                    echo '<div class="notice notice-warning is-dismissible"><p>'
                        . '<strong>Premium Dispatch:</strong> A sync is already running.</p></div>';
                } );
                return;
            }

            $result = pd_run_sync();

            if ( $result !== false ) {
                $count = count( $result );
                add_action( 'admin_notices', function() use ( $count ) {
                    echo '<div class="notice notice-success is-dismissible"><p>'
                        . '<strong>Premium Dispatch:</strong> Sync complete — '
                        . esc_html( $count ) . ' countries cached.</p></div>';
                } );
            } else {
                add_action( 'admin_notices', function() {
                    echo '<div class="notice notice-error is-dismissible"><p>'
                        . '<strong>Premium Dispatch:</strong> Sync failed. '
                        . 'Check your error log. Verify FEZ_USER_ID and FEZ_PASSWORD in wp-config.php.</p></div>';
                } );
            }
        }

        // -----------------------------------------------------------------------
        // CALCULATE SHIPPING
        //
        // Checkout price flow:
        //
        //   Fresh cache (age < cache_hours)
        //     → return price immediately, zero API calls
        //
        //   Stale cache (age >= cache_hours)
        //     → live fetch
        //         success → overwrite cache, return new price
        //         fail    → return stale_price * stale_multiplier
        //
        //   Cache miss
        //     → live fetch
        //         success → write cache, return price
        //         fail    → retry once after short pause
        //             success → write cache, return price
        //             fail    → show checkout notice, return (no rate)
        //
        // Weight handling:
        //   - Converts store weight unit to kg before matching tiers
        //   - Virtual/downloadable products excluded
        //   - Products with no weight use configurable default
        //   - Cart weight floored at 2kg (Fez minimum)
        //   - Carts heavier than all tiers use the highest tier
        // -----------------------------------------------------------------------
        public function calculate_shipping( $package = [] ) {
            if ( $this->get_option( 'enabled' ) !== 'yes' ) return;

            $country = $package['destination']['country'] ?? '';
            if ( ! $country || $country === 'NG' ) return;

            // Rate table — transient first, backup option as last resort
            $table = get_transient( pd_key( PD_RATE_TABLE_KEY ) )
                  ?: get_option( pd_key( PD_RATE_BACKUP_KEY ), null );

            if ( ! $table || ! isset( $table[ $country ] ) ) {
                $this->schedule_emergency_sync();
                return;
            }

            // Cart weight in kg
            $wc_unit      = strtolower( get_option( 'woocommerce_weight_unit', 'kg' ) );
            $default_wt   = (float) $this->get_option( 'default_product_weight' ) ?: 0.5;
            $total_weight = 0;

            foreach ( $package['contents'] as $item ) {
                $product = $item['data'];

                // Skip virtual and downloadable products — they have no physical weight
                if ( $product->is_virtual() || $product->is_downloadable() ) continue;

                $raw = (float) $product->get_weight();
                $kg  = pd_to_kg( $raw ?: $default_wt, $wc_unit );
                $total_weight += $kg * $item['quantity'];
            }

            // Fez minimum shipment weight
            $search_weight = max( 2.0, $total_weight );

            // Match weight tier (pre-sorted ascending)
            $matched = null;
            $highest = null;
            foreach ( $table[ $country ]['tiers'] as $tier ) {
                $highest = $tier;
                if ( $tier['weight'] >= $search_weight ) { $matched = $tier; break; }
            }
            $tier = $matched ?? $highest; // use highest tier for over-max carts
            if ( ! $tier ) return;

            // Price resolution
            $naira_price = $this->resolve_price(
                $country,
                $tier['weight_id'],
                $table[ $country ]['location_id'],
                $tier['weight']
            );

            if ( $naira_price === null ) {
                // All fetches failed — show checkout notice, return no rate
                $this->add_checkout_notice(
                    __( 'We\'re unable to load shipping options for your location at the moment. Please try again in a few minutes.', 'premium-dispatch' )
                );
                return;
            }

            $naira_total  = $naira_price + (float) $this->get_option( 'extra_expense' );
            $exchange     = (float) $this->get_option( 'shipping_exchange_rate' ) ?: 1300;
            $display_cost = $naira_total / $exchange;

            $this->add_rate( [
                'id'        => $this->get_rate_id(),
                'label'     => $this->title,
                'cost'      => $display_cost,
                'meta_data' => [
                    'pd_raw_naira' => $naira_total,
                    'pd_cached_at' => time(),
                    'pd_country'   => $country,
                ],
            ] );
        }

        // -----------------------------------------------------------------------
        // PRICE RESOLUTION
        // Returns NGN price or null if all attempts failed.
        // -----------------------------------------------------------------------
        private function resolve_price( $cc, $weight_id, $location_id, $weight_val ) {
            $cache_hours = (int) $this->get_option( 'cache_hours' ) ?: 24;
            $price_key   = pd_price_key( $cc, $weight_id );
            $cached      = get_transient( $price_key );
            $now         = time();

            // --- Fresh cache hit ---
            if ( $cached && isset( $cached['price'], $cached['cached_at'] ) ) {
                $age     = $now - $cached['cached_at'];
                $is_fresh = $age < ( $cache_hours * HOUR_IN_SECONDS );

                if ( $is_fresh ) {
                    return $cached['price'];
                }

                // --- Stale cache — attempt live refresh ---
                $fresh = $this->fetch_price( $cc, $weight_id, $location_id, $weight_val );
                if ( $fresh !== false ) {
                    return $fresh; // cache already written by fetch_price
                }

                // Live fetch failed — return stale price with multiplier
                $multiplier = (float) $this->get_option( 'stale_multiplier' ) ?: 1.15;
                pd_log( 'FETCH', 'WARN', 'Stale price served with multiplier', [ 'cc' => $cc, 'wid' => $weight_id, 'age_hours' => round( $age / 3600, 2 ), 'multiplier' => $multiplier ] );
                // Track stale multiplier usage so admin can see it in the status panel
                $stale_key = pd_key( 'pd_stale_count' );
                update_option( $stale_key, ( (int) get_option( $stale_key, 0 ) ) + 1, false );
                update_option( pd_key( 'pd_stale_last' ), [
                    'cc'  => $cc,
                    'age' => $age,
                    'at'  => time(),
                ], false );
                return $cached['price'] * $multiplier;
            }

            // --- Cache miss — live fetch ---
            $fresh = $this->fetch_price( $cc, $weight_id, $location_id, $weight_val );
            if ( $fresh !== false ) {
                return $fresh;
            }

            // First attempt failed — retry once after a short pause
            usleep( 300000 ); // 0.3s — enough to avoid hammering, not enough to noticeably stall checkout
            $retry = $this->fetch_price( $cc, $weight_id, $location_id, $weight_val );
            if ( $retry !== false ) {
                return $retry;
            }

            // Both attempts failed, no cache — return null (caller shows error)
            pd_log( 'FETCH', 'ERROR', 'All price fetch attempts failed — no rate shown', [ 'cc' => $cc, 'wid' => $weight_id ] );
            return null;
        }

        // -----------------------------------------------------------------------
        // LIVE PRICE FETCH
        // 5s timeout — checkout must not stall.
        // Per-country lock prevents duplicate concurrent fetches.
        // Writes cache on success.
        // Returns price (float) or false on failure.
        // -----------------------------------------------------------------------
        private function fetch_price( $cc, $weight_id, $location_id, $weight_val ) {
            $lock_key = pd_fetch_lock_key( $cc );

            // Another request is already fetching this country.
            // Wait briefly and read from cache — if the other request succeeded
            // the price will be there. If cache is still empty after waiting,
            // fall through and make our own fetch rather than returning false.
            if ( get_transient( $lock_key ) ) {
                usleep( 700000 ); // 0.7s
                $cached = get_transient( pd_price_key( $cc, $weight_id ) );
                if ( $cached && isset( $cached['price'] ) ) return $cached['price'];
                // Other request may have failed — fall through to our own fetch
                pd_log( 'FETCH', 'INFO', 'Lock wait resolved to empty cache — attempting own fetch', [ 'cc' => $cc, 'wid' => $weight_id ] );
            }

            set_transient( $lock_key, 1, 10 );

            // pd_api_request injects auth headers and retries on 401 automatically
            $res = pd_api_request( 'POST', FEZ_API_BASE . '/orders/export-price', [
                'headers' => [ 'Content-Type' => 'application/json' ],
                'body'    => wp_json_encode( [
                    'exportLocationId' => (int) $location_id,
                    'weightId'         => (int) $weight_id,
                ] ),
                'timeout' => 5,
            ] );

            delete_transient( $lock_key );

            if ( is_wp_error( $res ) ) {
                pd_log( 'FETCH', 'ERROR', 'Price fetch request failed', [ 'cc' => $cc, 'wid' => $weight_id, 'error' => $res->get_error_message() ] );
                // Clear token on network failure — may be a dead token causing connection rejection
                delete_transient( PD_SHARED_AUTH_KEY );
                return false;
            }

            $http_code = (int) wp_remote_retrieve_response_code( $res );
            $body_raw  = wp_remote_retrieve_body( $res );
            $data      = json_decode( $body_raw, true );
            $price     = $data['data']['price'] ?? null;

            if ( ! $price ) {
                pd_log( 'FETCH', 'ERROR', 'Price fetch returned empty price', [
                    'cc'        => $cc,
                    'wid'       => $weight_id,
                    'http_code' => $http_code,
                    'body'      => $data,
                ] );
                // If auth failure slipped past pd_api_request detection, clear token
                if ( $http_code === 401 || $http_code === 403 ) {
                    delete_transient( PD_SHARED_AUTH_KEY );
                    pd_log( 'AUTH', 'WARN', 'Token cleared after unexpected auth failure in price response', [ 'http_code' => $http_code ] );
                }
                return false;
            }

            pd_write_price( $cc, $weight_id, (float) $price, $weight_val );
            return (float) $price;
        }

        // -----------------------------------------------------------------------
        // CHECKOUT NOTICE — checkout page only, not cart
        // -----------------------------------------------------------------------
        private function add_checkout_notice( $message ) {
            // Only add on checkout page — not cart, not admin
            if ( ! function_exists( 'is_checkout' ) || ! is_checkout() ) return;
            if ( ! function_exists( 'wc_add_notice' ) ) return;

            // Avoid duplicate notices if calculate_shipping is called multiple times
            $existing = wc_get_notices( 'error' );
            foreach ( $existing as $notice ) {
                $text = is_array( $notice ) ? ( $notice['notice'] ?? '' ) : $notice;
                if ( strpos( $text, 'unable to load shipping options' ) !== false ) return;
            }

            wc_add_notice( $message, 'error' );
        }

        private function schedule_emergency_sync() {
            if ( get_transient( pd_key( PD_SYNC_LOCK ) ) ) return;
            if ( wp_next_scheduled( 'pd_emergency_sync_event' ) ) return;
            wp_schedule_single_event( time(), 'pd_emergency_sync_event' );
            pd_spawn_cron();
        }

        public function reschedule_sync() {
            $freq = $this->get_option( 'sync_frequency' ) ?: 'twicedaily';
            wp_clear_scheduled_hook( 'pd_sync_event' );
            wp_schedule_event( time(), $freq, 'pd_sync_event' );
        }
    }
}

add_action( 'woocommerce_shipping_init', 'premier_dispatch_init_v64' );

add_filter( 'woocommerce_shipping_methods', function( $methods ) {
    $methods['fez_shipping'] = 'WC_Shipping_Premier_Dispatch';
    return $methods;
} );


// ---------------------------------------------------------------------------
// CRON HOOKS
// ---------------------------------------------------------------------------
add_action( 'pd_sync_event',          'pd_run_sync' );
add_action( 'pd_emergency_sync_event','pd_run_sync' );


// ---------------------------------------------------------------------------
// ACTIVATION / DEACTIVATION
// ---------------------------------------------------------------------------
register_activation_hook( __FILE__, function() {
    if ( ! wp_next_scheduled( 'pd_sync_event' ) ) {
        wp_schedule_event( time(), 'twicedaily', 'pd_sync_event' );
    }
    // First sync fires on next page load when WC is fully initialised
    wp_schedule_single_event( time(), 'pd_emergency_sync_event' );
    pd_spawn_cron();
} );

register_deactivation_hook( __FILE__, function() {
    wp_clear_scheduled_hook( 'pd_sync_event' );
    wp_clear_scheduled_hook( 'pd_emergency_sync_event' );
    // Rate backup, hit data intentionally kept — instant recovery on re-activation
} );


// ---------------------------------------------------------------------------
// AJAX STATUS POLLER
// ---------------------------------------------------------------------------
add_action( 'wp_ajax_pd_sync_status', function() {
    if ( ! current_user_can( 'manage_options' ) ) wp_die( '', 403 );
    check_ajax_referer( 'pd_status_nonce', '_wpnonce' );

    $lock  = get_transient( pd_key( PD_SYNC_LOCK ) );
    $table = get_transient( pd_key( PD_RATE_TABLE_KEY ) );

    wp_send_json( [
        'syncing' => (bool) $lock,
        'count'   => $table ? count( $table ) : 0,
    ] );
} );


// ---------------------------------------------------------------------------
// STALE COUNTER RESET
// Admin can reset the stale multiplier counter from the status panel link.
// Counter-only reset — no destructive data affected, capability check sufficient.
// ---------------------------------------------------------------------------
add_action( 'admin_init', function() {
    if ( ! current_user_can( 'manage_options' ) ) return;
    if ( ! isset( $_GET['pd_reset_stale'] ) ) return;
    delete_option( pd_key( 'pd_stale_count' ) );
    delete_option( pd_key( 'pd_stale_last' ) );
    wp_safe_redirect( remove_query_arg( 'pd_reset_stale' ) );
    exit;
} );


// ---------------------------------------------------------------------------
// CURRENCY FILTER
// Swap cost to raw NGN when store currency is NGN.
// Guard prevents double-conversion by other currency plugins.
// ---------------------------------------------------------------------------
add_filter( 'woocommerce_shipping_rate_cost', function( $cost, $rate ) {
    if ( get_woocommerce_currency() !== 'NGN' ) return $cost;
    if ( strpos( $rate->get_id(), 'fez_shipping' ) === false ) return $cost;

    $meta = $rate->get_meta_data();
    if ( ! isset( $meta['pd_raw_naira'] ) ) return $cost;
    if ( (float) $cost === (float) $meta['pd_raw_naira'] ) return $cost; // already swapped

    return $meta['pd_raw_naira'];
}, 20, 2 );


// ---------------------------------------------------------------------------
// ADMIN DEBUG PAGE
// Access: /wp-admin/?pd_debug=1&pd_token=YOUR_SECRET_TOKEN
// Country detail: append &pd_country=US
// Set token: update_option('pd_debug_token', 'your-secret');
// ---------------------------------------------------------------------------
add_action( 'admin_init', function() {
    if ( ! current_user_can( 'manage_options' ) || ! isset( $_GET['pd_debug'] ) ) return;

    $stored = get_option( 'pd_debug_token', '' );
    $given  = sanitize_text_field( $_GET['pd_token'] ?? '' );
    if ( ! $stored || ! hash_equals( $stored, $given ) ) {
        wp_die( 'Invalid or missing debug token.' );
    }

    $table       = get_transient( pd_key( PD_RATE_TABLE_KEY ) );
    $backup      = get_option( pd_key( PD_RATE_BACKUP_KEY ), null );
    $lock        = get_transient( pd_key( PD_SYNC_LOCK ) );
    $auth_data   = get_transient( PD_SHARED_AUTH_KEY ); // shared — not blog-scoped
    $last_error  = get_option( pd_key( 'pd_last_error' ), null );
    $stale_count = (int) get_option( pd_key( 'pd_stale_count' ), 0 );
    $stale_last  = get_option( pd_key( 'pd_stale_last' ), null );
    $next        = wp_next_scheduled( 'pd_sync_event' );
    $wc_unit     = get_option( 'woocommerce_weight_unit', 'kg' );

    $age = 'n/a';
    if ( $table ) {
        $first = reset( $table );
        $age   = isset( $first['built_at'] ) ? human_time_diff( $first['built_at'] ) . ' ago' : 'unknown';
    }

    $token_info = 'NOT CACHED';
    if ( $auth_data && isset( $auth_data['cached_at'] ) ) {
        $expires_in = round( ( $auth_data['cached_at'] + 55 * MINUTE_IN_SECONDS - time() ) / 60 );
        $token_info = 'cached ' . human_time_diff( $auth_data['cached_at'] ) . ' ago'
            . ' | expires in ~' . max( 0, $expires_in ) . 'min'
            . ( $expires_in <= 0 ? ' [EXPIRED — will refresh on next call]' : '' );
    } elseif ( $auth_data ) {
        $token_info = 'cached (no timestamp — old format)';
    }

    echo '<pre style="background:#1e1e1e;color:#d4d4d4;padding:24px;font-family:monospace;font-size:13px;line-height:1.8;">';
    echo '<strong>Premium Dispatch v64 -- Debug</strong>  blog=' . get_current_blog_id() . '  wc_weight_unit=' . esc_html( $wc_unit ) . "\n\n";

    echo "-- State --\n";
    echo 'Auth token  : ' . $token_info . "\n";
    echo 'Sync lock   : ' . ( $lock ? 'ACTIVE'         : 'clear'        ) . "\n";
    echo 'Next sync   : ' . ( $next ? human_time_diff( time(), $next ) . ' from now' : 'NOT SCHEDULED' ) . "\n";
    echo 'Table       : ' . ( $table  ? count( $table )  . ' countries, built ' . $age : 'NOT BUILT' ) . "\n";
    echo 'Backup      : ' . ( $backup ? count( $backup ) . ' countries'                : 'NOT BUILT' ) . "\n";
    echo 'Stale hits  : ' . ( $stale_count > 0
        ? $stale_count . ' time(s)' . ( $stale_last ? ' | last: ' . $stale_last['cc'] . ' ' . human_time_diff( $stale_last['at'] ) . ' ago' : '' )
        : 'none' ) . "\n";

    if ( $last_error ) {
        echo "\n-- Last Error --\n";
        echo 'Context : ' . esc_html( $last_error['context'] ) . "\n";
        echo 'Message : ' . esc_html( $last_error['message'] ) . "\n";
        echo 'When    : ' . human_time_diff( $last_error['at'] ) . ' ago' . "\n";
        if ( ! empty( $last_error['data'] ) ) {
            echo 'Data    : ' . esc_html( wp_json_encode( $last_error['data'] ) ) . "\n";
        }
    }
    echo "\n";


    if ( isset( $_GET['pd_country'] ) ) {
        $cc  = strtoupper( sanitize_text_field( $_GET['pd_country'] ) );
        $src = $table ?? $backup;
        echo "-- Country: " . esc_html( $cc ) . " --\n";
        if ( $src && isset( $src[ $cc ] ) ) {
            echo 'Location ID : ' . $src[ $cc ]['location_id'] . "\n";
            foreach ( $src[ $cc ]['tiers'] as $t ) {
                $cached = get_transient( pd_price_key( $cc, $t['weight_id'] ) );
                if ( $cached && isset( $cached['price'], $cached['cached_at'] ) ) {
                    $p = sprintf( 'N%s  (cached %s ago)', number_format( $cached['price'] ), human_time_diff( $cached['cached_at'] ) );
                } else {
                    $p = 'no cache';
                }
                printf( "  wid=%-6d  %5.1fkg  %s\n", $t['weight_id'], $t['weight'], $p );
            }
        } else {
            echo "Not in rate table.\n";
        }
    } elseif ( $table ) {
        echo 'Countries: ' . implode( ', ', array_keys( $table ) ) . "\n\n";
        echo "Tip: append &pd_country=US to inspect a country.\n";
    }

    echo '</pre>';
    exit;
} );
