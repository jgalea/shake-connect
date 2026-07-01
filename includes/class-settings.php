<?php
/**
 * Admin settings page: generates the connection token on activation,
 * displays it once on the settings page so the customer can paste it
 * into the WPShake dashboard, and lets them regenerate it.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ShakeConnect_Settings {

	const TRANSIENT_PLAIN = 'shake_connect_plain_token_view';

	public static function on_activate() {
		if ( ! ShakeConnect_Auth::is_configured() ) {
			$plain = ShakeConnect_Auth::generate_token();
			ShakeConnect_Auth::store_hashed( $plain );
			set_transient( self::TRANSIENT_PLAIN, $plain, HOUR_IN_SECONDS );
		}
	}

	public function register_menu() {
		add_options_page(
			'Shake Connect',
			'Shake Connect',
			'manage_options',
			'shake-connect',
			array( $this, 'render_page' )
		);
	}

	public function register_settings() {
		register_setting(
			'shake_connect',
			'shake_connect_action',
			array(
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
				'default'           => '',
				'show_in_rest'      => false,
			)
		);
	}

	public function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		// Handle token regeneration.
		if ( isset( $_POST['shake_regenerate'] ) && check_admin_referer( 'shake_connect_regen' ) ) {
			$plain = ShakeConnect_Auth::generate_token();
			ShakeConnect_Auth::store_hashed( $plain );
			set_transient( self::TRANSIENT_PLAIN, $plain, HOUR_IN_SECONDS );
			echo '<div class="notice notice-success"><p>New token generated. Copy it below — it will not be shown again after 1 hour.</p></div>';
		}

		$plain      = get_transient( self::TRANSIENT_PLAIN );
		$configured = ShakeConnect_Auth::is_configured();
		$last_seen  = ShakeConnect_Auth::get_last_seen();
		$multisite  = is_multisite();
		?>
		<div class="wrap">
			<h1>Shake Connect</h1>
			<p>Connects this site to your WPShake dashboard.</p>

			<?php if ( $plain ) : ?>
				<div class="notice notice-info" style="padding: 14px;">
					<p><strong>Your connection token (copy this now — it won't show again):</strong></p>
					<p>
						<input
							type="text"
							readonly
							style="font-family: monospace; width: 100%; padding: 8px; font-size: 13px;"
							value="<?php echo esc_attr( $plain ); ?>"
							onclick="this.select();"
						/>
					</p>
					<p>Paste this into the WPShake dashboard when adding this site.</p>
				</div>
			<?php endif; ?>

			<table class="form-table">
				<tr>
					<th>Status</th>
					<td><?php echo $configured ? '<span style="color: #16a34a;">✓ Configured</span>' : '<span style="color: #dc2626;">✗ No token set</span>'; ?></td>
				</tr>
				<tr>
					<th>Site URL</th>
					<td><code><?php echo esc_html( get_site_url() ); ?></code></td>
				</tr>
				<tr>
					<th>REST endpoint</th>
					<td><code><?php echo esc_html( get_site_url() . '/wp-json/' . SHAKE_CONNECT_NAMESPACE . '/' ); ?></code></td>
				</tr>
				<tr>
					<th>Last agent contact</th>
					<td>
						<?php
						if ( $last_seen > 0 ) {
							echo esc_html( human_time_diff( $last_seen, time() ) . ' ago' );
						} else {
							echo '<em>Never</em>';
						}
						?>
					</td>
				</tr>
			</table>

			<form method="post">
				<?php wp_nonce_field( 'shake_connect_regen' ); ?>
				<p>
					<button type="submit" name="shake_regenerate" class="button button-secondary" onclick="return confirm('Regenerate the token? You will need to update it in your WPShake dashboard.');">
						Regenerate token
					</button>
				</p>
			</form>

			<h2>How it works</h2>
			<p>The WPShake agent authenticates to this site by sending the token in the <code>Authorization: Bearer &lt;token&gt;</code> header on every request. The plugin stores only the SHA-256 hash. No outbound calls are made from this plugin — the agent always initiates.</p>

			<p>Available endpoints: <code>/ping</code>, <code>/site/info</code>, <code>/plugins</code>, <code>/themes</code>, <code>/core/info</code>, <code>/checksums</code>, <code>/db/health</code>, <code>/orders/recent</code>, <code>/php-errors/recent</code>, <code>/php-errors/stats</code>, <code>/backups/state</code>, <code>/activity-log/source</code>, <code>/activity-log/recent</code>, <code>/security/malware-scan</code>, <code>/links/broken</code>, <code>/network/sites</code>, <code>/cache/flush</code>, <code>/cron/run-due</code>, <code>/transients/delete-expired</code>, <code>/permalinks/flush</code>.</p>

			<?php if ( $multisite ) : ?>
				<p><strong>Multisite:</strong> this is a multisite network. Use <code>/network/sites</code> to enumerate subsites and pass <code>?blog_id=N</code> to per-site read endpoints (e.g. <code>/site/info?blog_id=3</code>) to inspect each subsite. Without the parameter, requests run against the main site.</p>
			<?php else : ?>
				<p><em>Single-site install. The <code>/network/sites</code> endpoint returns an empty array; the <code>?blog_id=N</code> parameter is rejected.</em></p>
			<?php endif; ?>

			<h2>External services disclosure</h2>
			<p>This plugin responds to requests from the WPShake dashboard at <a href="https://wpshake.com">wpshake.com</a> when the dashboard authenticates with the bearer token shown above. The plugin does not initiate outbound requests on a schedule. See the plugin readme for full disclosure.</p>
		</div>
		<?php
	}
}
