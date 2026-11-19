<?php
/**
 * Plugin Name:       WP Settings Exporter
 * Plugin URI:        https://github.com/Azanumer/wp-settings-exporter
 * Description:       Export and import WordPress site settings as JSON — move configuration between staging and live, or keep a backup, without touching the database directly.
 * Version:           1.0.0
 * Requires at least: 5.8
 * Requires PHP:      7.4
 * Author:            Azan Umer
 * Author URI:        https://azanumer.cv
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       wp-settings-exporter
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'WSE_VERSION', '1.0.0' );

/**
 * Option groups available for export.
 *
 * Each group maps to a list of option names. Other code can add groups
 * or options through the wse_exportable_options filter.
 *
 * @return array<string, array{label: string, options: string[]}>
 */
function wse_exportable_options() {
	$groups = array(
		'general'    => array(
			'label'   => __( 'General', 'wp-settings-exporter' ),
			'options' => array(
				'blogname',
				'blogdescription',
				'admin_email',
				'users_can_register',
				'default_role',
				'timezone_string',
				'gmt_offset',
				'date_format',
				'time_format',
				'start_of_week',
			),
		),
		'reading'    => array(
			'label'   => __( 'Reading', 'wp-settings-exporter' ),
			'options' => array(
				'show_on_front',
				'page_on_front',
				'page_for_posts',
				'posts_per_page',
				'posts_per_rss',
				'rss_use_excerpt',
				'blog_public',
			),
		),
		'discussion' => array(
			'label'   => __( 'Discussion', 'wp-settings-exporter' ),
			'options' => array(
				'default_pingback_flag',
				'default_ping_status',
				'default_comment_status',
				'require_name_email',
				'comment_registration',
				'close_comments_for_old_posts',
				'close_comments_days_old',
				'thread_comments',
				'thread_comments_depth',
				'comments_per_page',
				'comment_order',
				'moderation_keys',
				'comment_moderation',
				'comment_whitelist',
			),
		),
		'media'      => array(
			'label'   => __( 'Media', 'wp-settings-exporter' ),
			'options' => array(
				'thumbnail_size_w',
				'thumbnail_size_h',
				'thumbnail_crop',
				'medium_size_w',
				'medium_size_h',
				'large_size_w',
				'large_size_h',
			),
		),
		'permalinks' => array(
			'label'   => __( 'Permalinks', 'wp-settings-exporter' ),
			'options' => array(
				'permalink_structure',
				'category_base',
				'tag_base',
			),
		),
	);

	return (array) apply_filters( 'wse_exportable_options', $groups );
}

/**
 * Option names that are never exported or imported.
 *
 * Site URLs, credentials and anything that looks like a secret are
 * blocked — importing them onto another site would break it or leak keys.
 *
 * @param string $name Option name.
 * @return bool True when the option must be skipped.
 */
function wse_is_blocked_option( $name ) {
	$blocked = array(
		'siteurl',
		'home',
		'active_plugins',
		'template',
		'stylesheet',
		'current_theme',
		'cron',
	);

	if ( in_array( $name, $blocked, true ) ) {
		return true;
	}

	$lower = strtolower( $name );
	foreach ( array( 'password', 'passwd', 'secret', 'salt', 'api_key', 'apikey', 'token', 'auth_key', 'private' ) as $needle ) {
		if ( false !== strpos( $lower, $needle ) ) {
			return true;
		}
	}

	return (bool) apply_filters( 'wse_is_blocked_option', false, $name );
}

/**
 * Build the export payload for the selected groups.
 *
 * @param string[] $group_keys Group keys to include.
 * @return array Export payload ready for wp_json_encode().
 */
function wse_build_export( $group_keys ) {
	$groups  = wse_exportable_options();
	$payload = array(
		'plugin'     => 'wp-settings-exporter',
		'version'    => WSE_VERSION,
		'site'       => get_bloginfo( 'name' ),
		'exported'   => current_time( 'mysql' ),
		'wp_version' => get_bloginfo( 'version' ),
		'options'    => array(),
	);

	foreach ( $group_keys as $key ) {
		if ( ! isset( $groups[ $key ] ) ) {
			continue;
		}
		foreach ( $groups[ $key ]['options'] as $name ) {
			if ( wse_is_blocked_option( $name ) ) {
				continue;
			}
			$payload['options'][ $name ] = get_option( $name );
		}
	}

	return $payload;
}

/**
 * Compare an import payload against current values.
 *
 * @param array $options Option name => value from the import file.
 * @return array{added: string[], changed: string[], unchanged: string[], blocked: string[]}
 */
function wse_preview_import( $options ) {
	$groups    = wse_exportable_options();
	$allowed   = array();
	foreach ( $groups as $group ) {
		foreach ( $group['options'] as $name ) {
			$allowed[] = $name;
		}
	}

	$preview = array(
		'added'     => array(),
		'changed'   => array(),
		'unchanged' => array(),
		'blocked'   => array(),
	);

	foreach ( $options as $name => $value ) {
		if ( ! in_array( $name, $allowed, true ) || wse_is_blocked_option( $name ) ) {
			$preview['blocked'][] = $name;
			continue;
		}
		$current = get_option( $name, null );
		if ( null === $current ) {
			$preview['added'][] = $name;
		} elseif ( $current === $value ) {
			$preview['unchanged'][] = $name;
		} else {
			$preview['changed'][] = $name;
		}
	}

	return $preview;
}

/**
 * Register the Tools page.
 */
function wse_register_page() {
	add_management_page(
		__( 'Settings Export', 'wp-settings-exporter' ),
		__( 'Settings Export', 'wp-settings-exporter' ),
		'manage_options',
		'wp-settings-exporter',
		'wse_render_page'
	);
}
add_action( 'admin_menu', 'wse_register_page' );

/**
 * Handle the export download (admin-post, nonce-checked).
 */
function wse_handle_export() {
	if ( ! isset( $_POST['wse_export'] ) ) {
		return;
	}
	check_admin_referer( 'wse_export' );
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'You do not have permission to export settings.', 'wp-settings-exporter' ) );
	}

	$groups = isset( $_POST['wse_groups'] ) ? array_map( 'sanitize_key', (array) $_POST['wse_groups'] ) : array();
	if ( empty( $groups ) ) {
		wp_safe_redirect( add_query_arg( 'wse_error', 'no_groups', admin_url( 'tools.php?page=wp-settings-exporter' ) ) );
		exit;
	}

	$payload  = wse_build_export( $groups );
	$filename = 'wp-settings-' . sanitize_file_name( wp_parse_url( home_url(), PHP_URL_HOST ) ) . '-' . gmdate( 'Y-m-d' ) . '.json';

	nocache_headers();
	header( 'Content-Type: application/json; charset=' . get_option( 'blog_charset' ) );
	header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
	echo wp_json_encode( $payload, JSON_PRETTY_PRINT );
	exit;
}
add_action( 'admin_post_wse_export', 'wse_handle_export' );

/**
 * Render the Tools -> Settings Export page.
 */
function wse_render_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'You do not have permission to view this page.', 'wp-settings-exporter' ) );
	}

	$groups     = wse_exportable_options();
	$preview    = null;
	$imported   = false;
	$import_msg = '';

	// Handle the import upload: first pass shows a dry-run preview,
	// the confirmed pass (wse_confirm=1) applies it.
	if ( isset( $_POST['wse_import'] ) ) {
		check_admin_referer( 'wse_import' );

		if ( empty( $_FILES['wse_file'] ) || UPLOAD_ERR_OK !== $_FILES['wse_file']['error'] ) {
			echo '<div class="notice notice-error is-dismissible"><p>' . esc_html__( 'Please choose a valid JSON export file.', 'wp-settings-exporter' ) . '</p></div>';
		} else {
			$raw  = file_get_contents( $_FILES['wse_file']['tmp_name'] );
			$data = json_decode( $raw, true );

			if ( ! is_array( $data ) || empty( $data['options'] ) || ! is_array( $data['options'] ) ) {
				echo '<div class="notice notice-error is-dismissible"><p>' . esc_html__( 'That file does not look like a WP Settings Exporter export.', 'wp-settings-exporter' ) . '</p></div>';
			} else {
				$preview = wse_preview_import( $data['options'] );

				// Stash the payload for the confirm step (nothing is
				// changed until the user clicks "Apply import").
				set_transient( 'wse_pending_import_' . get_current_user_id(), $data['options'], 10 * MINUTE_IN_SECONDS );
			}
		}
	}

	// Confirm step: re-read the stashed payload and apply it.
	if ( isset( $_POST['wse_import_confirm'] ) ) {
		check_admin_referer( 'wse_import_confirm' );
		$stashed = get_transient( 'wse_pending_import_' . get_current_user_id() );
		if ( is_array( $stashed ) ) {
			$preview = wse_preview_import( $stashed );
			$applied = 0;
			foreach ( $stashed as $name => $value ) {
				if ( in_array( $name, $preview['blocked'], true ) ) {
					continue;
				}
				update_option( $name, $value );
				$applied++;
			}
			if ( isset( $stashed['permalink_structure'] ) ) {
				flush_rewrite_rules();
			}
			delete_transient( 'wse_pending_import_' . get_current_user_id() );
			$imported   = true;
			$import_msg = sprintf(
				/* translators: %d: number of options imported. */
				__( '%d options imported.', 'wp-settings-exporter' ),
				$applied
			);
			$preview = null;
		}
	}

	?>
	<div class="wrap">
		<h1><?php esc_html_e( 'Settings Export', 'wp-settings-exporter' ); ?></h1>
		<p><?php esc_html_e( 'Export your site settings as JSON to move configuration between staging and live, or keep a backup. Site URLs, credentials and secrets are never exported.', 'wp-settings-exporter' ); ?></p>

		<?php if ( isset( $_GET['wse_error'] ) && 'no_groups' === $_GET['wse_error'] ) : ?>
			<div class="notice notice-error is-dismissible"><p><?php esc_html_e( 'Select at least one settings group to export.', 'wp-settings-exporter' ); ?></p></div>
		<?php endif; ?>

		<?php if ( $imported ) : ?>
			<div class="notice notice-success is-dismissible"><p><?php echo esc_html( $import_msg ); ?></p></div>
		<?php endif; ?>

		<h2><?php esc_html_e( 'Export settings', 'wp-settings-exporter' ); ?></h2>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="wse_export">
			<?php wp_nonce_field( 'wse_export' ); ?>
			<?php foreach ( $groups as $key => $group ) : ?>
				<label style="display:block;margin:4px 0;">
					<input type="checkbox" name="wse_groups[]" value="<?php echo esc_attr( $key ); ?>" checked>
					<?php echo esc_html( $group['label'] ); ?>
					<span class="description">(<?php echo (int) count( $group['options'] ); ?> <?php esc_html_e( 'options', 'wp-settings-exporter' ); ?>)</span>
				</label>
			<?php endforeach; ?>
			<p><?php submit_button( __( 'Download export (.json)', 'wp-settings-exporter' ), 'primary', 'wse_export_submit', false ); ?></p>
		</form>

		<h2><?php esc_html_e( 'Import settings', 'wp-settings-exporter' ); ?></h2>
		<form method="post" action="" enctype="multipart/form-data">
			<?php wp_nonce_field( 'wse_import' ); ?>
			<p>
				<input type="file" name="wse_file" accept=".json,application/json" required>
			</p>
			<p class="description"><?php esc_html_e( 'Uploading a file only shows a preview first — nothing is changed until you confirm.', 'wp-settings-exporter' ); ?></p>
			<?php submit_button( __( 'Preview import', 'wp-settings-exporter' ), 'secondary', 'wse_import' ); ?>
		</form>

		<?php if ( is_array( $preview ) ) : ?>
			<h2><?php esc_html_e( 'Import preview', 'wp-settings-exporter' ); ?></h2>
			<table class="widefat striped">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Change', 'wp-settings-exporter' ); ?></th>
						<th><?php esc_html_e( 'Options', 'wp-settings-exporter' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<tr>
						<td><strong><?php esc_html_e( 'Will change', 'wp-settings-exporter' ); ?></strong></td>
						<td><?php echo empty( $preview['changed'] ) ? '&mdash;' : esc_html( implode( ', ', $preview['changed'] ) ); ?></td>
					</tr>
					<tr>
						<td><strong><?php esc_html_e( 'Will be added', 'wp-settings-exporter' ); ?></strong></td>
						<td><?php echo empty( $preview['added'] ) ? '&mdash;' : esc_html( implode( ', ', $preview['added'] ) ); ?></td>
					</tr>
					<tr>
						<td><strong><?php esc_html_e( 'Unchanged', 'wp-settings-exporter' ); ?></strong></td>
						<td><?php echo empty( $preview['unchanged'] ) ? '&mdash;' : esc_html( implode( ', ', $preview['unchanged'] ) ); ?></td>
					</tr>
					<tr>
						<td><strong><?php esc_html_e( 'Skipped (blocked)', 'wp-settings-exporter' ); ?></strong></td>
						<td><?php echo empty( $preview['blocked'] ) ? '&mdash;' : esc_html( implode( ', ', $preview['blocked'] ) ); ?></td>
					</tr>
				</tbody>
			</table>
			<form method="post" action="">
				<?php wp_nonce_field( 'wse_import_confirm' ); ?>
				<p><?php submit_button( __( 'Apply import', 'wp-settings-exporter' ), 'primary', 'wse_import_confirm' ); ?></p>
			</form>
		<?php endif; ?>
	</div>
	<?php
}
