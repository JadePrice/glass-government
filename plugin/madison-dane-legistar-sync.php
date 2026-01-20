<?php
/**
 * Plugin Name: Madison & Dane County Legistar → The Events Calendar
 * Description: Syncs City of Madison and Dane County events from the Legistar JSON Worker into The Events Calendar.
 * Version: 2.0.0
 * Author: Glass Government
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * Text Domain: gg-legistar-sync
 * 
 * @package GlassGovernment\LegistarSync
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/* ---------------------------------------------------------
 * Constants & Configuration
 * --------------------------------------------------------- */

define( 'GG_LEGISTAR_VERSION', '2.0.0' );
define( 'GG_LEGISTAR_WORKER_BASE', 'https://legistar.glassgovernment.org/events' );
define( 'GG_LEGISTAR_MAX_DAYS_PAST', 30 );
define( 'GG_LEGISTAR_PLUGIN_FILE', __FILE__ );
define( 'GG_LEGISTAR_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );

/* ---------------------------------------------------------
 * Activation & Deactivation
 * --------------------------------------------------------- */

register_activation_hook( __FILE__, 'gg_legistar_activate' );
register_deactivation_hook( __FILE__, 'gg_legistar_deactivate' );

function gg_legistar_activate() {
    // Schedule daily sync
    if ( ! wp_next_scheduled( 'gg_legistar_daily_sync' ) ) {
        wp_schedule_event( time(), 'daily', 'gg_legistar_daily_sync' );
    }
    
    // Set default options
    add_option( 'gg_legistar_debug', false );
    add_option( 'gg_legistar_last_sync', 0 );
}

function gg_legistar_deactivate() {
    wp_clear_scheduled_hook( 'gg_legistar_daily_sync' );
}

/* ---------------------------------------------------------
 * Worker API Functions
 * --------------------------------------------------------- */

/**
 * Fetch events from worker endpoint
 * 
 * @param string $client Client identifier (madison or dane)
 * @return array Worker response data
 */
function gg_get_worker_events( $client ) {
    $debug = get_option( 'gg_legistar_debug', false );
    $url   = esc_url_raw( GG_LEGISTAR_WORKER_BASE . '/' . sanitize_key( $client ) );

    if ( $debug ) {
        $url = add_query_arg( 'debug', '1', $url );
    }

    $response = wp_remote_get( $url, [
        'timeout'     => 30,
        'redirection' => 2,
        'headers'     => [
            'Accept'     => 'application/json',
            'User-Agent' => 'GlassGovernment/WorkerSync/' . GG_LEGISTAR_VERSION
        ],
        'sslverify'   => true,
    ]);

    if ( is_wp_error( $response ) ) {
        gg_log_message( 'Worker fetch error for ' . $client . ': ' . $response->get_error_message() );
        return [];
    }

    $code = wp_remote_retrieve_response_code( $response );
    if ( $code !== 200 ) {
        gg_log_message( 'Worker returned HTTP ' . $code . ' for ' . $client );
        return [];
    }

    $body = wp_remote_retrieve_body( $response );
    $data = json_decode( $body, true );

    if ( json_last_error() !== JSON_ERROR_NONE ) {
        gg_log_message( 'JSON decode error: ' . json_last_error_msg() );
        return [];
    }

    if ( ! is_array( $data ) || empty( $data['events'] ) ) {
        gg_log_message( 'Worker returned no events for ' . $client );
        return [];
    }

    return $data;
}

/* ---------------------------------------------------------
 * Venue Management
 * --------------------------------------------------------- */

/**
 * Normalize venue name for consistency
 * 
 * @param string $name Raw venue name
 * @return string Normalized venue name
 */
function gg_normalize_venue_name( $name ) {
    if ( empty( $name ) ) {
        return '';
    }

    $name = strtolower( trim( $name ) );
    $name = preg_replace( '/\s+/', ' ', $name );
    
    // Normalize MLK variations
    $mlk_variations = [
        'martin luther king, jr.',
        'martin luther king jr.',
        'martin luther king, jr',
        'martin luther king jr',
        'mlk jr.',
        'mlk jr',
        'mlk',
    ];
    
    $name = str_replace( $mlk_variations, 'martin luther king', $name );
    
    return $name;
}

/**
 * Get or create venue by name
 * 
 * @param string $name Venue name
 * @return int Venue post ID or 0 on failure
 */
function gg_get_or_create_venue( $name ) {
    if ( empty( $name ) ) {
        return 0;
    }

    $normalized = gg_normalize_venue_name( $name );
    
    // Check for existing venue
    $existing = get_posts([
        'post_type'      => 'tribe_venue',
        'post_status'    => 'publish',
        'posts_per_page' => 1,
        'meta_query'     => [
            [
                'key'     => '_gg_normalized_name',
                'value'   => $normalized,
                'compare' => '=',
            ],
        ],
    ]);

    if ( ! empty( $existing ) ) {
        return $existing[0]->ID;
    }

    // Create new venue
    $venue_id = wp_insert_post([
        'post_title'   => sanitize_text_field( $name ),
        'post_type'    => 'tribe_venue',
        'post_status'  => 'publish',
        'post_content' => '',
    ], true );

    if ( is_wp_error( $venue_id ) ) {
        gg_log_message( 'Failed to create venue: ' . $venue_id->get_error_message() );
        return 0;
    }

    // Store normalized name for future lookups
    update_post_meta( $venue_id, '_gg_normalized_name', $normalized );

    return $venue_id;
}

/**
 * Deduplicate venues
 * 
 * @return array Results with counts
 */
function gg_deduplicate_venues() {
    $venues = get_posts([
        'post_type'      => 'tribe_venue',
        'post_status'    => 'publish',
        'posts_per_page' => -1,
    ]);

    $normalized_map = [];
    $merged_count   = 0;
    $updated_events = 0;

    foreach ( $venues as $venue ) {
        $normalized = gg_normalize_venue_name( $venue->post_title );
        
        if ( ! isset( $normalized_map[ $normalized ] ) ) {
            $normalized_map[ $normalized ] = $venue->ID;
            update_post_meta( $venue->ID, '_gg_normalized_name', $normalized );
        } else {
            // This is a duplicate - reassign events and delete
            $primary_id = $normalized_map[ $normalized ];
            
            $events = get_posts([
                'post_type'      => 'tribe_events',
                'posts_per_page' => -1,
                'meta_query'     => [
                    [
                        'key'   => '_EventVenueID',
                        'value' => $venue->ID,
                    ],
                ],
            ]);

            foreach ( $events as $event ) {
                update_post_meta( $event->ID, '_EventVenueID', $primary_id );
                $updated_events++;
            }

            wp_delete_post( $venue->ID, true );
            $merged_count++;
        }
    }

    return [
        'merged'  => $merged_count,
        'updated' => $updated_events,
    ];
}

/* ---------------------------------------------------------
 * DateTime Handling
 * --------------------------------------------------------- */

/**
 * Normalize datetime string to WordPress timezone
 * 
 * @param string $raw Raw datetime string
 * @return string|false Formatted datetime or false on failure
 */
function gg_normalize_datetime( $raw ) {
    if ( empty( $raw ) ) {
        return false;
    }

    try {
        $dt = new DateTime( $raw, new DateTimeZone( 'UTC' ) );
        $dt->setTimezone( wp_timezone() );
        return $dt->format( 'Y-m-d H:i:s' );
    } catch ( Exception $e ) {
        gg_log_message( 'DateTime parse failed for: ' . $raw . ' - ' . $e->getMessage() );
        return false;
    }
}

/**
 * Check if event is within sync window
 * 
 * @param string $datetime Event datetime
 * @return bool True if within window
 */
function gg_is_within_sync_window( $datetime ) {
    try {
        $event_date = new DateTime( $datetime, wp_timezone() );
        $cutoff     = new DateTime( 'now', wp_timezone() );
        $cutoff->modify( '-' . GG_LEGISTAR_MAX_DAYS_PAST . ' days' );
        
        return $event_date >= $cutoff;
    } catch ( Exception $e ) {
        return false;
    }
}

/* ---------------------------------------------------------
 * Event Management
 * --------------------------------------------------------- */

/**
 * Set TEC event metadata
 * 
 * @param int    $event_id Event post ID
 * @param string $datetime Event datetime
 * @param int    $venue_id Venue post ID
 */
function gg_set_tec_event_meta( $event_id, $datetime, $venue_id = 0 ) {
    update_post_meta( $event_id, '_EventStartDate', $datetime );
    update_post_meta( $event_id, '_EventEndDate', $datetime );
    update_post_meta( $event_id, '_EventAllDay', 'no' );
    update_post_meta( $event_id, '_EventShowMap', '1' );
    update_post_meta( $event_id, '_EventShowMapLink', '1' );
    
    if ( $venue_id > 0 ) {
        update_post_meta( $event_id, '_EventVenueID', $venue_id );
    }
    
    delete_post_meta( $event_id, '_EventTimezone' );
}

/**
 * Sync events from worker to TEC
 * 
 * @param array  $events        Event data from worker
 * @param string $category_name Category for events
 * @return array Sync results
 */
function gg_sync_events_to_tec( $events, $category_name ) {
    $results = [
        'fetched'  => 0,
        'inserted' => 0,
        'updated'  => 0,
        'skipped'  => 0,
        'preview'  => [],
    ];

    if ( empty( $events ) || ! is_array( $events ) ) {
        return $results;
    }

    // Get or create category
    $category_id = 0;
    if ( ! empty( $category_name ) ) {
        $term = get_term_by( 'name', $category_name, 'tribe_events_cat' );
        if ( ! $term ) {
            $term = wp_insert_term( $category_name, 'tribe_events_cat' );
            if ( ! is_wp_error( $term ) ) {
                $category_id = $term['term_id'];
            }
        } else {
            $category_id = $term->term_id;
        }
    }

    foreach ( $events as $event ) {
        $results['fetched']++;

        // Validate required fields
        if ( empty( $event['EventId'] ) || empty( $event['EventDate'] ) ) {
            $results['skipped']++;
            continue;
        }

        // Normalize datetime
        $datetime = gg_normalize_datetime( $event['EventDate'] );
        if ( ! $datetime || ! gg_is_within_sync_window( $datetime ) ) {
            $results['skipped']++;
            continue;
        }

        // Handle venue
        $venue_id = 0;
        if ( ! empty( $event['EventLocation'] ) ) {
            $venue_id = gg_get_or_create_venue( $event['EventLocation'] );
        }

        // Check for existing event
        $existing = get_posts([
            'post_type'      => 'tribe_events',
            'posts_per_page' => 1,
            'meta_query'     => [
                [
                    'key'   => '_gg_legistar_event_id',
                    'value' => sanitize_text_field( $event['EventId'] ),
                ],
            ],
        ]);

        $event_title = ! empty( $event['EventBodyName'] ) 
            ? sanitize_text_field( $event['EventBodyName'] )
            : __( 'Legistar Event', 'gg-legistar-sync' );

        $post_data = [
            'post_title'   => $event_title,
            'post_type'    => 'tribe_events',
            'post_status'  => 'publish',
            'post_content' => '',
        ];

        if ( ! empty( $existing ) ) {
            // Update existing
            $post_data['ID'] = $existing[0]->ID;
            wp_update_post( $post_data );
            $event_id = $existing[0]->ID;
            $results['updated']++;
        } else {
            // Insert new
            $event_id = wp_insert_post( $post_data, true );
            if ( is_wp_error( $event_id ) ) {
                $results['skipped']++;
                continue;
            }
            $results['inserted']++;
        }

        // Set metadata
        gg_set_tec_event_meta( $event_id, $datetime, $venue_id );
        update_post_meta( $event_id, '_gg_legistar_event_id', sanitize_text_field( $event['EventId'] ) );
        
        if ( ! empty( $event['EventInSiteURL'] ) ) {
            update_post_meta( $event_id, '_gg_legistar_url', esc_url_raw( $event['EventInSiteURL'] ) );
        }

        // Set category
        if ( $category_id > 0 ) {
            wp_set_post_terms( $event_id, [ $category_id ], 'tribe_events_cat' );
        }

        // Add to preview (first 10 only)
        if ( count( $results['preview'] ) < 10 ) {
            $results['preview'][] = [
                'title'    => $event_title,
                'datetime' => $datetime,
                'venue'    => ! empty( $event['EventLocation'] ) ? $event['EventLocation'] : '',
                'url'      => ! empty( $event['EventInSiteURL'] ) ? $event['EventInSiteURL'] : '',
            ];
        }
    }

    return $results;
}

/**
 * Purge old events
 * 
 * @return int Number of events deleted
 */
function gg_purge_old_events() {
    $cutoff = new DateTime( 'now', wp_timezone() );
    $cutoff->modify( '-' . GG_LEGISTAR_MAX_DAYS_PAST . ' days' );
    $cutoff_str = $cutoff->format( 'Y-m-d H:i:s' );

    $old_events = get_posts([
        'post_type'      => 'tribe_events',
        'posts_per_page' => -1,
        'meta_query'     => [
            [
                'key'     => '_gg_legistar_event_id',
                'compare' => 'EXISTS',
            ],
            [
                'key'     => '_EventStartDate',
                'value'   => $cutoff_str,
                'compare' => '<',
                'type'    => 'DATETIME',
            ],
        ],
    ]);

    $deleted = 0;
    foreach ( $old_events as $event ) {
        if ( wp_delete_post( $event->ID, true ) ) {
            $deleted++;
        }
    }

    return $deleted;
}

/**
 * Main sync function
 * 
 * @return array Combined results from both clients
 */
function gg_perform_sync() {
    $results = [
        'madison' => [],
        'dane'    => [],
        'success' => false,
    ];

    // Sync Madison
    $madison_data = gg_get_worker_events( 'madison' );
    if ( ! empty( $madison_data['events'] ) ) {
        $results['madison'] = gg_sync_events_to_tec( 
            $madison_data['events'], 
            'City of Madison'
        );
    }

    // Sync Dane County
    $dane_data = gg_get_worker_events( 'dane' );
    if ( ! empty( $dane_data['events'] ) ) {
        $results['dane'] = gg_sync_events_to_tec( 
            $dane_data['events'], 
            'Dane County'
        );
    }

    $results['success'] = true;
    update_option( 'gg_legistar_last_sync', time() );
    update_option( 'gg_legistar_last_results', $results );

    return $results;
}

/* ---------------------------------------------------------
 * Logging
 * --------------------------------------------------------- */

/**
 * Log debug message
 * 
 * @param string $message Message to log
 */
function gg_log_message( $message ) {
    if ( ! get_option( 'gg_legistar_debug', false ) ) {
        return;
    }

    $log = get_option( 'gg_legistar_debug_log', [] );
    
    $log[] = [
        'time'    => current_time( 'mysql' ),
        'message' => $message,
    ];

    // Keep only last 100 entries
    if ( count( $log ) > 100 ) {
        $log = array_slice( $log, -100 );
    }

    update_option( 'gg_legistar_debug_log', $log );
}

/* ---------------------------------------------------------
 * Admin Interface
 * --------------------------------------------------------- */

add_action( 'admin_menu', 'gg_legistar_admin_menu' );
function gg_legistar_admin_menu() {
    add_menu_page(
        __( 'Legistar Sync', 'gg-legistar-sync' ),
        __( 'Legistar Sync', 'gg-legistar-sync' ),
        'manage_options',
        'gg-legistar-sync',
        'gg_legistar_admin_page',
        'dashicons-calendar-alt',
        56
    );
}


function gg_legistar_admin_page() {
    // Handle form submissions
    if ( isset( $_POST['gg_legistar_action'] ) && check_admin_referer( 'gg_legistar_admin' ) ) {
        $action = sanitize_key( $_POST['gg_legistar_action'] );
        
        switch ( $action ) {
            case 'sync':
                $results = gg_perform_sync();
                add_settings_error( 
                    'gg_legistar', 
                    'sync_complete', 
                    __( 'Sync completed successfully!', 'gg-legistar-sync' ), 
                    'success' 
                );
                break;
                
            case 'purge':
                $deleted = gg_purge_old_events();
                add_settings_error( 
                    'gg_legistar', 
                    'purge_complete', 
                    sprintf( __( 'Purged %d old events.', 'gg-legistar-sync' ), $deleted ), 
                    'success' 
                );
                break;
                
            case 'dedupe':
                $dedupe_results = gg_deduplicate_venues();
                add_settings_error( 
                    'gg_legistar', 
                    'dedupe_complete', 
                    sprintf( 
                        __( 'Merged %d duplicate venues, updated %d events.', 'gg-legistar-sync' ), 
                        $dedupe_results['merged'], 
                        $dedupe_results['updated'] 
                    ), 
                    'success' 
                );
                break;
                
            case 'toggle_debug':
                $current = get_option( 'gg_legistar_debug', false );
                update_option( 'gg_legistar_debug', ! $current );
                add_settings_error( 
                    'gg_legistar', 
                    'debug_toggled', 
                    __( 'Debug mode toggled.', 'gg-legistar-sync' ), 
                    'success' 
                );
                break;
                
            case 'clear_log':
                delete_option( 'gg_legistar_debug_log' );
                add_settings_error( 
                    'gg_legistar', 
                    'log_cleared', 
                    __( 'Debug log cleared.', 'gg-legistar-sync' ), 
                    'success' 
                );
                break;
        }
    }

    // Get current status
    $last_sync    = get_option( 'gg_legistar_last_sync', 0 );
    $last_results = get_option( 'gg_legistar_last_results', [] );
    $debug_mode   = get_option( 'gg_legistar_debug', false );
    $debug_log    = get_option( 'gg_legistar_debug_log', [] );

    ?>
    <div class="wrap gg-legistar-admin">
        <h1><?php esc_html_e( 'Legistar Event Sync', 'gg-legistar-sync' ); ?></h1>
        
        <?php settings_errors( 'gg_legistar' ); ?>

        <div class="gg-admin-grid">
            <!-- Status Card -->
            <div class="gg-card">
                <h2><?php esc_html_e( 'Sync Status', 'gg-legistar-sync' ); ?></h2>
                
                <div class="gg-status-item">
                    <strong><?php esc_html_e( 'Last Sync:', 'gg-legistar-sync' ); ?></strong>
                    <span>
                        <?php 
                        if ( $last_sync > 0 ) {
                            echo esc_html( 
                                sprintf( 
                                    __( '%s ago', 'gg-legistar-sync' ),
                                    human_time_diff( $last_sync, current_time( 'timestamp' ) )
                                )
                            );
                        } else {
                            esc_html_e( 'Never', 'gg-legistar-sync' );
                        }
                        ?>
                    </span>
                </div>

                <div class="gg-status-item">
                    <strong><?php esc_html_e( 'Debug Mode:', 'gg-legistar-sync' ); ?></strong>
                    <span class="gg-badge <?php echo $debug_mode ? 'gg-badge-warning' : 'gg-badge-success'; ?>">
                        <?php echo $debug_mode ? esc_html__( 'Enabled', 'gg-legistar-sync' ) : esc_html__( 'Disabled', 'gg-legistar-sync' ); ?>
                    </span>
                </div>

                <div class="gg-status-item">
                    <strong><?php esc_html_e( 'Sync Window:', 'gg-legistar-sync' ); ?></strong>
                    <span><?php echo esc_html( GG_LEGISTAR_MAX_DAYS_PAST ); ?> <?php esc_html_e( 'days', 'gg-legistar-sync' ); ?></span>
                </div>
            </div>

            <!-- Actions Card -->
            <div class="gg-card">
                <h2><?php esc_html_e( 'Actions', 'gg-legistar-sync' ); ?></h2>
                
                <form method="post" class="gg-action-form">
                    <?php wp_nonce_field( 'gg_legistar_admin' ); ?>
                    
                    <button type="submit" name="gg_legistar_action" value="sync" class="button button-primary button-large">
                        <span class="dashicons dashicons-update"></span>
                        <?php esc_html_e( 'Sync Now', 'gg-legistar-sync' ); ?>
                    </button>

                    <button type="submit" name="gg_legistar_action" value="purge" class="button button-secondary">
                        <span class="dashicons dashicons-trash"></span>
                        <?php esc_html_e( 'Purge Old Events', 'gg-legistar-sync' ); ?>
                    </button>

                    <button type="submit" name="gg_legistar_action" value="dedupe" class="button button-secondary">
                        <span class="dashicons dashicons-admin-site-alt3"></span>
                        <?php esc_html_e( 'Deduplicate Venues', 'gg-legistar-sync' ); ?>
                    </button>

                    <button type="submit" name="gg_legistar_action" value="toggle_debug" class="button button-secondary">
                        <span class="dashicons dashicons-admin-tools"></span>
                        <?php echo $debug_mode ? esc_html__( 'Disable Debug', 'gg-legistar-sync' ) : esc_html__( 'Enable Debug', 'gg-legistar-sync' ); ?>
                    </button>

                    <?php if ( $debug_mode && ! empty( $debug_log ) ) : ?>
                    <button type="submit" name="gg_legistar_action" value="clear_log" class="button button-secondary">
                        <span class="dashicons dashicons-dismiss"></span>
                        <?php esc_html_e( 'Clear Debug Log', 'gg-legistar-sync' ); ?>
                    </button>
                    <?php endif; ?>
                </form>
            </div>
        </div>

        <?php if ( ! empty( $last_results ) && isset( $last_results['success'] ) ) : ?>
        <!-- Results Grid -->
        <div class="gg-results-grid">
            <?php foreach ( ['madison', 'dane'] as $client ) : ?>
                <?php if ( ! empty( $last_results[ $client ] ) ) : ?>
                <div class="gg-card">
                    <h2>
                        <?php echo esc_html( ucfirst( $client ) ); ?>
                        <?php esc_html_e( 'Results', 'gg-legistar-sync' ); ?>
                    </h2>
                    
                    <div class="gg-stats">
                        <div class="gg-stat">
                            <span class="gg-stat-label"><?php esc_html_e( 'Fetched', 'gg-legistar-sync' ); ?></span>
                            <span class="gg-stat-value"><?php echo absint( $last_results[ $client ]['fetched'] ?? 0 ); ?></span>
                        </div>
                        <div class="gg-stat">
                            <span class="gg-stat-label"><?php esc_html_e( 'Inserted', 'gg-legistar-sync' ); ?></span>
                            <span class="gg-stat-value gg-stat-success"><?php echo absint( $last_results[ $client ]['inserted'] ?? 0 ); ?></span>
                        </div>
                        <div class="gg-stat">
                            <span class="gg-stat-label"><?php esc_html_e( 'Updated', 'gg-legistar-sync' ); ?></span>
                            <span class="gg-stat-value gg-stat-info"><?php echo absint( $last_results[ $client ]['updated'] ?? 0 ); ?></span>
                        </div>
                        <div class="gg-stat">
                            <span class="gg-stat-label"><?php esc_html_e( 'Skipped', 'gg-legistar-sync' ); ?></span>
                            <span class="gg-stat-value gg-stat-warning"><?php echo absint( $last_results[ $client ]['skipped'] ?? 0 ); ?></span>
                        </div>
                    </div>

                    <?php if ( ! empty( $last_results[ $client ]['preview'] ) ) : ?>
                    <h3><?php esc_html_e( 'Preview', 'gg-legistar-sync' ); ?></h3>
                    <div class="gg-preview-table-wrapper">
                        <table class="gg-preview-table">
                            <thead>
                                <tr>
                                    <th><?php esc_html_e( 'Event', 'gg-legistar-sync' ); ?></th>
                                    <th><?php esc_html_e( 'Date/Time', 'gg-legistar-sync' ); ?></th>
                                    <th><?php esc_html_e( 'Venue', 'gg-legistar-sync' ); ?></th>
                                    <th><?php esc_html_e( 'Source', 'gg-legistar-sync' ); ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ( $last_results[ $client ]['preview'] as $event ) : ?>
                                <tr>
                                    <td><?php echo esc_html( $event['title'] ); ?></td>
                                    <td><?php echo esc_html( $event['datetime'] ); ?></td>
                                    <td><?php echo esc_html( $event['venue'] ); ?></td>
                                    <td>
                                        <?php if ( ! empty( $event['url'] ) ) : ?>
                                        <a href="<?php echo esc_url( $event['url'] ); ?>" target="_blank" rel="noopener noreferrer">
                                            <?php esc_html_e( 'View', 'gg-legistar-sync' ); ?>
                                            <span class="screen-reader-text"><?php esc_html_e( '(opens in new tab)', 'gg-legistar-sync' ); ?></span>
                                        </a>
                                        <?php else : ?>
                                        <span aria-hidden="true">—</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <?php if ( $debug_mode && ! empty( $debug_log ) ) : ?>
        <!-- Debug Log -->
        <div class="gg-card">
            <h2><?php esc_html_e( 'Debug Log', 'gg-legistar-sync' ); ?></h2>
            <div class="gg-debug-log">
                <?php foreach ( array_reverse( array_slice( $debug_log, -50 ) ) as $entry ) : ?>
                <div class="gg-log-entry">
                    <time datetime="<?php echo esc_attr( $entry['time'] ); ?>">
                        <?php echo esc_html( $entry['time'] ); ?>
                    </time>
                    <span class="gg-log-message"><?php echo esc_html( $entry['message'] ); ?></span>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <style>
        .gg-legistar-admin {
            max-width: 1400px;
        }

        .gg-admin-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 20px;
            margin: 20px 0;
        }

        .gg-results-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(500px, 1fr));
            gap: 20px;
            margin: 20px 0;
        }

        .gg-card {
            background: #fff;
            border: 1px solid #ccd0d4;
            border-radius: 4px;
            padding: 20px;
            box-shadow: 0 1px 1px rgba(0,0,0,.04);
        }

        .gg-card h2 {
            margin-top: 0;
            padding-bottom: 10px;
            border-bottom: 1px solid #e5e5e5;
            font-size: 18px;
        }

        .gg-card h3 {
            margin: 20px 0 10px;
            font-size: 14px;
            font-weight: 600;
            text-transform: uppercase;
            color: #646970;
        }

        .gg-status-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 10px 0;
            border-bottom: 1px solid #f0f0f1;
        }

        .gg-status-item:last-child {
            border-bottom: none;
        }

        .gg-badge {
            display: inline-block;
            padding: 2px 8px;
            border-radius: 3px;
            font-size: 12px;
            font-weight: 600;
        }

        .gg-badge-success {
            background: #d4edda;
            color: #155724;
        }

        .gg-badge-warning {
            background: #fff3cd;
            color: #856404;
        }

        .gg-action-form {
            display: flex;
            flex-direction: column;
            gap: 10px;
        }

        .gg-action-form button {
            display: flex;
            align-items: center;
            gap: 8px;
            justify-content: center;
            width: 100%;
        }

        .gg-stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(100px, 1fr));
            gap: 15px;
            margin: 20px 0;
        }

        .gg-stat {
            text-align: center;
            padding: 15px;
            background: #f6f7f7;
            border-radius: 4px;
        }

        .gg-stat-label {
            display: block;
            font-size: 12px;
            color: #646970;
            margin-bottom: 5px;
            font-weight: 600;
        }

        .gg-stat-value {
            display: block;
            font-size: 24px;
            font-weight: 700;
            color: #1d2327;
        }

        .gg-stat-success {
            color: #00a32a;
        }

        .gg-stat-info {
            color: #2271b1;
        }

        .gg-stat-warning {
            color: #996800;
        }

        .gg-preview-table-wrapper {
            overflow-x: auto;
        }

        .gg-preview-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 13px;
        }

        .gg-preview-table th {
            background: #f6f7f7;
            padding: 10px;
            text-align: left;
            font-weight: 600;
            border-bottom: 2px solid #c3c4c7;
        }

        .gg-preview-table td {
            padding: 10px;
            border-bottom: 1px solid #e5e5e5;
        }

        .gg-preview-table tr:hover {
            background: #f9f9f9;
        }

        .gg-preview-table a {
            text-decoration: none;
        }

        .gg-debug-log {
            max-height: 400px;
            overflow-y: auto;
            background: #f6f7f7;
            padding: 15px;
            border-radius: 4px;
            font-family: monospace;
            font-size: 12px;
        }

        .gg-log-entry {
            padding: 8px 0;
            border-bottom: 1px solid #e5e5e5;
        }

        .gg-log-entry:last-child {
            border-bottom: none;
        }

        .gg-log-entry time {
            color: #2271b1;
            font-weight: 600;
            margin-right: 10px;
        }

        .gg-log-message {
            color: #1d2327;
        }

        @media (max-width: 782px) {
            .gg-admin-grid,
            .gg-results-grid {
                grid-template-columns: 1fr;
            }

            .gg-stats {
                grid-template-columns: repeat(2, 1fr);
            }
        }

        /* High contrast mode support */
        @media (prefers-contrast: high) {
            .gg-card {
                border: 2px solid #000;
            }

            .gg-badge-success {
                border: 1px solid #155724;
            }

            .gg-badge-warning {
                border: 1px solid #856404;
            }
        }

        /* Reduced motion support */
        @media (prefers-reduced-motion: reduce) {
            * {
                animation: none !important;
                transition: none !important;
            }
        }
    </style>
    <?php
}

/* ---------------------------------------------------------
 * Cron Hook
 * --------------------------------------------------------- */

add_action( 'gg_legistar_daily_sync', 'gg_perform_sync' );

/* ---------------------------------------------------------
 * AJAX Handlers (for future async operations)
 * --------------------------------------------------------- */

add_action( 'wp_ajax_gg_legistar_sync', 'gg_ajax_sync' );

function gg_ajax_sync() {
    check_ajax_referer( 'gg_legistar_admin', 'nonce' );
    
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( __( 'Permission denied', 'gg-legistar-sync' ) );
    }

    $results = gg_perform_sync();
    wp_send_json_success( $results );
}

/* ---------------------------------------------------------
 * Security: Disable directory browsing for uploads
 * --------------------------------------------------------- */

add_action( 'init', function() {
    if ( ! is_admin() ) {
        return;
    }
    
    $upload_dir = wp_upload_dir();
    $htaccess   = $upload_dir['basedir'] . '/.htaccess';
    
    if ( ! file_exists( $htaccess ) ) {
        $content = "Options -Indexes\n";
        @file_put_contents( $htaccess, $content );
    }
});

/* ---------------------------------------------------------
 * Uninstall Hook
 * --------------------------------------------------------- */

register_uninstall_hook( __FILE__, 'gg_legistar_uninstall' );

function gg_legistar_uninstall() {
    // Remove options
    delete_option( 'gg_legistar_debug' );
    delete_option( 'gg_legistar_last_sync' );
    delete_option( 'gg_legistar_last_results' );
    delete_option( 'gg_legistar_debug_log' );
    
    // Clear scheduled events
    wp_clear_scheduled_hook( 'gg_legistar_daily_sync' );
}