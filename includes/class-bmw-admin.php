<?php
/**
 * Administrace: Bombora → Mergado feedy. Cron URL a URL feedu pro každý jazyk WPML.
 *
 * @package Bombora\MergadoWpml
 */

namespace Bombora\MergadoWpml;

defined( 'ABSPATH' ) || exit;

final class Admin {

	public const CAP    = 'manage_options';
	public const PARENT = 'bombora';
	public const PAGE   = 'bombora-mergado-wpml';

	public static function init(): void {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 20 );
		add_action( 'admin_post_bmw_save', array( __CLASS__, 'save' ) );
		add_action( 'admin_post_bmw_unlock', array( __CLASS__, 'unlock' ) );
		add_filter( 'plugin_action_links_' . BMW_BASENAME, array( __CLASS__, 'action_links' ) );
	}

	public static function menu(): void {
		global $admin_page_hooks;
		// Menu „Bombora“ vytváří Bombora Manager; bez něj stránka spadne pod WooCommerce / Nástroje.
		$parent = ! empty( $admin_page_hooks[ self::PARENT ] ) ? self::PARENT : ( ! empty( $admin_page_hooks['woocommerce'] ) ? 'woocommerce' : 'tools.php' );

		add_submenu_page(
			$parent,
			__( 'Mergado feeds by language', 'bombora-mergado-wpml' ),
			__( 'Mergado feeds', 'bombora-mergado-wpml' ),
			self::CAP,
			self::PAGE,
			array( __CLASS__, 'render' )
		);
	}

	/**
	 * @param string[] $links Odkazy.
	 * @return string[]
	 */
	public static function action_links( $links ): array {
		array_unshift( $links, '<a href="' . esc_url( self::url() ) . '">' . esc_html__( 'Settings', 'bombora-mergado-wpml' ) . '</a>' );
		return $links;
	}

	private static function url( array $args = array() ): string {
		return add_query_arg( array_merge( array( 'page' => self::PAGE ), $args ), admin_url( 'admin.php' ) );
	}

	public static function save(): void {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'bombora-mergado-wpml' ) );
		}
		check_admin_referer( 'bmw_save' );

		$in  = array(
			'cron_url'     => isset( $_POST['cron_url'] ) ? (string) wp_unslash( $_POST['cron_url'] ) : '', // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- sanitizace v Settings::sanitize()
			'lock_minutes' => isset( $_POST['lock_minutes'] ) ? (int) $_POST['lock_minutes'] : 20,
		);
		$res = Settings::sanitize( $in );

		if ( is_wp_error( $res ) ) {
			set_transient( 'bmw_error_' . get_current_user_id(), $res->get_error_message(), 60 );
			wp_safe_redirect( self::url( array( 'bmw-msg' => 'error' ) ) );
			exit;
		}
		update_option( Settings::OPTION, $res, false );
		wp_safe_redirect( self::url( array( 'bmw-msg' => 'saved' ) ) );
		exit;
	}

	public static function unlock(): void {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'bombora-mergado-wpml' ) );
		}
		check_admin_referer( 'bmw_unlock' );
		delete_option( Settings::LOCK );
		wp_safe_redirect( self::url( array( 'bmw-msg' => 'unlocked' ) ) );
		exit;
	}

	private static function notice(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- jen zobrazení hlášky.
		$msg = isset( $_GET['bmw-msg'] ) ? sanitize_key( wp_unslash( $_GET['bmw-msg'] ) ) : '';
		if ( 'saved' === $msg ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Saved.', 'bombora-mergado-wpml' ) . '</p></div>';
		} elseif ( 'unlocked' === $msg ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Lock released.', 'bombora-mergado-wpml' ) . '</p></div>';
		} elseif ( 'error' === $msg ) {
			$err = (string) get_transient( 'bmw_error_' . get_current_user_id() );
			echo '<div class="notice notice-error is-dismissible"><p>' . esc_html( $err ?: __( 'Not saved.', 'bombora-mergado-wpml' ) ) . '</p></div>';
		}
	}

	public static function render(): void {
		if ( ! current_user_can( self::CAP ) ) {
			return;
		}

		$settings = Settings::get();
		$status   = Settings::status();
		$langs    = Settings::languages();
		$default  = Settings::default_language();
		$roots    = Settings::feed_roots();
		$lock     = get_option( Settings::LOCK, array() );
		$fmt      = get_option( 'date_format' ) . ' ' . get_option( 'time_format' );
		$hour     = 2;
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Mergado feeds by language', 'bombora-mergado-wpml' ); ?></h1>
			<?php self::notice(); ?>

			<?php if ( ! $langs ) : ?>
				<div class="notice notice-info"><p><?php esc_html_e( 'WPML is not active – the website has one language. Use the standard cron URL from Mergado Pack; this plugin does not change anything.', 'bombora-mergado-wpml' ); ?></p></div>
			<?php endif; ?>

			<?php if ( ! $roots ) : ?>
				<div class="notice notice-warning"><p><?php esc_html_e( 'No Mergado Pack folder found (uploads/*mergado* or wp-content/*mergado*). It will appear after the first feed generation.', 'bombora-mergado-wpml' ); ?></p></div>
			<?php endif; ?>

			<?php if ( is_array( $lock ) && ! empty( $lock['lang'] ) ) : ?>
				<div class="notice notice-warning">
					<p>
						<?php
						printf(
							/* translators: 1: language code, 2: time of the last batch */
							esc_html__( 'Feed „%1$s“ is being generated (last batch: %2$s). Cron requests for other languages are refused until it finishes.', 'bombora-mergado-wpml' ),
							esc_html( (string) $lock['lang'] ),
							esc_html( wp_date( $fmt, (int) $lock['hit'] ) )
						);
						?>
					</p>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<input type="hidden" name="action" value="bmw_unlock">
						<?php wp_nonce_field( 'bmw_unlock' ); ?>
						<p><?php submit_button( __( 'Release lock', 'bombora-mergado-wpml' ), 'secondary small', 'submit', false ); ?></p>
					</form>
				</div>
			<?php endif; ?>

			<h2><?php esc_html_e( '1. Cron URL from Mergado Pack', 'bombora-mergado-wpml' ); ?></h2>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="bmw_save">
				<?php wp_nonce_field( 'bmw_save' ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="bmw-cron"><?php esc_html_e( 'Product cron URL', 'bombora-mergado-wpml' ); ?></label></th>
						<td>
							<input type="url" id="bmw-cron" name="cron_url" class="large-text code"
								placeholder="<?php echo esc_attr( home_url( '/mergado/?action=productCron&token=…' ) ); ?>"
								value="<?php echo esc_attr( $settings['cron_url'] ); ?>">
							<p class="description"><?php esc_html_e( 'Copy it from Mergado Pack → Product feed → Cron. The URLs for each language are derived from it.', 'bombora-mergado-wpml' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="bmw-lock"><?php esc_html_e( 'Lock timeout (minutes)', 'bombora-mergado-wpml' ); ?></label></th>
						<td>
							<input type="number" id="bmw-lock" name="lock_minutes" min="5" max="120" class="small-text" value="<?php echo (int) $settings['lock_minutes']; ?>">
							<p class="description"><?php esc_html_e( 'While a language is being generated, other languages wait. The lock expires this many minutes after the last batch (e.g. after an interrupted run).', 'bombora-mergado-wpml' ); ?></p>
						</td>
					</tr>
				</table>
				<?php submit_button( __( 'Save', 'bombora-mergado-wpml' ) ); ?>
			</form>

			<?php if ( $langs ) : ?>
				<h2><?php esc_html_e( '2. URLs for each language', 'bombora-mergado-wpml' ); ?></h2>
				<table class="widefat striped">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Language', 'bombora-mergado-wpml' ); ?></th>
							<th><?php esc_html_e( 'Products', 'bombora-mergado-wpml' ); ?></th>
							<th><?php esc_html_e( 'Cron URL (cron-job.org)', 'bombora-mergado-wpml' ); ?></th>
							<th><?php esc_html_e( 'Feed URL (Mergado / Merchant Center)', 'bombora-mergado-wpml' ); ?></th>
							<th><?php esc_html_e( 'Last run', 'bombora-mergado-wpml' ); ?></th>
						</tr>
					</thead>
					<tbody>
					<?php foreach ( $langs as $code => $name ) : ?>
						<?php
						$cron = Settings::cron_url( $code );
						$st   = $status[ $code ] ?? null;
						?>
						<tr>
							<td>
								<strong><?php echo esc_html( $name ); ?></strong> <code><?php echo esc_html( $code ); ?></code>
								<?php if ( $code === $default ) : ?>
									<br><small><?php esc_html_e( 'default language', 'bombora-mergado-wpml' ); ?></small>
								<?php endif; ?>
							</td>
							<td><?php echo (int) Settings::product_count( $code ); ?></td>
							<td>
								<?php if ( $cron ) : ?>
									<input type="text" readonly class="large-text code" onclick="this.select()" value="<?php echo esc_attr( $cron ); ?>">
									<small>
										<?php
										printf(
											/* translators: 1: start time, 2: end time */
											esc_html__( 'Suggested: every 5 min from %1$s to %2$s', 'bombora-mergado-wpml' ),
											esc_html( sprintf( '%02d:00', $hour % 24 ) ),
											esc_html( sprintf( '%02d:30', $hour % 24 ) )
										);
										?>
									</small>
									<?php $hour++; ?>
								<?php else : ?>
									<em><?php esc_html_e( 'Save the cron URL first.', 'bombora-mergado-wpml' ); ?></em>
								<?php endif; ?>
							</td>
							<td>
								<?php if ( $st && ! empty( $st['files'] ) ) : ?>
									<?php foreach ( $st['files'] as $f ) : ?>
										<input type="text" readonly class="large-text code" onclick="this.select()" value="<?php echo esc_attr( (string) $f['url'] ); ?>">
										<small>
											<?php
											printf(
												/* translators: 1: file size, 2: number of products */
												esc_html__( '%1$s, %2$d items', 'bombora-mergado-wpml' ),
												esc_html( (string) size_format( (int) $f['bytes'] ) ),
												(int) ( $f['items'] ?? 0 )
											);
											?>
										</small><br>
									<?php endforeach; ?>
								<?php else : ?>
									<em><?php esc_html_e( 'Not generated yet. The URL appears after the first finished run.', 'bombora-mergado-wpml' ); ?></em>
								<?php endif; ?>
							</td>
							<td>
								<?php echo $st ? esc_html( wp_date( $fmt, (int) $st['finished'] ) ) : '–'; ?>
								<?php if ( $cron ) : ?>
									<br><a href="<?php echo esc_url( $cron ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Run one batch now', 'bombora-mergado-wpml' ); ?></a>
								<?php endif; ?>
							</td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>

				<h2><?php esc_html_e( 'Rules', 'bombora-mergado-wpml' ); ?></h2>
				<ul style="list-style:disc;padding-left:2em">
					<li><?php esc_html_e( 'Turn off the internal WP-Cron in Mergado Pack and run all languages only through these URLs.', 'bombora-mergado-wpml' ); ?></li>
					<li><?php esc_html_e( 'Give each language its own time window in cron-job.org. Mergado keeps only one feed in progress; the plugin refuses a different language until the current one finishes (HTTP 423).', 'bombora-mergado-wpml' ); ?></li>
					<li><?php esc_html_e( 'Connect Mergado / Merchant Center to the feed URLs above, not to the original Mergado feed – that one always contains the language of the last run.', 'bombora-mergado-wpml' ); ?></li>
					<li><?php esc_html_e( 'Only products translated into a language get into its feed (see the Products column).', 'bombora-mergado-wpml' ); ?></li>
					<li><?php esc_html_e( 'Only the language is switched, not the WCML currency.', 'bombora-mergado-wpml' ); ?></li>
				</ul>
			<?php endif; ?>
		</div>
		<?php
	}
}
