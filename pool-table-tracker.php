<?php
/**
 * Plugin Name: Pool Table Tracker
 * Description: Pool hall table timer, guest billing, checkout, and searchable history dashboard.
 * Version: 1.0.0
 * Author: Pool Tracker
 * Text Domain: pool-table-tracker
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'PTT_VERSION', '1.1.7' );
define( 'PTT_PLUGIN_FILE', __FILE__ );
define( 'PTT_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'PTT_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

final class Pool_Table_Tracker {
	const RATE_OPTION = 'ptt_hourly_rate';
	const PAGE_OPTION = 'ptt_tracker_page_id';
	const DARTS_PAGE_OPTION = 'ptt_darts_page_id';
	const TRACKER_SLUG = 'pool-table-tracker';
	const DARTS_SLUG = 'darts-board-manager';
	const HOURLY_RATE = 5;
	const DARTS_HOURLY_RATE = 2;
	const ACTIVE_STATUS = 'active';
	const STOPPED_STATUS = 'stopped';
	const ENDED_STATUS = 'ended';
	const COMPLETED_STATUS = 'completed';
	const BOARD_AVAILABLE = 'available';
	const BOARD_RUNNING = 'running';
	const BOARD_EXCLUDED = 'excluded';
	const TABLE_EXCLUDED_ACTION = 'table_excluded';
	const PLAYER_TRANSFERRED_ACTION = 'player_transferred';
	const TABLE_TRANSFERRED_ACTION = 'table_transferred';
	const DART_BOARD_EXCLUDED_ACTION = 'dart_board_excluded';
	const DART_BOARD_TRANSFERRED_ACTION = 'dart_board_transferred';
	const DART_PLAYER_TRANSFERRED_ACTION = 'dart_player_transferred';

	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	private function __construct() {
		add_action( 'admin_menu', array( $this, 'register_admin_menu' ) );
		add_action( 'init', array( __CLASS__, 'ensure_schema' ) );
		add_action( 'admin_init', array( __CLASS__, 'ensure_tracker_page' ) );
		add_action( 'admin_init', array( __CLASS__, 'ensure_darts_page' ) );
		add_action( 'admin_init', array( $this, 'redirect_admin_tracker_pages' ), 20 );
		add_action( 'template_redirect', array( $this, 'protect_tracker_page' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'register_assets' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'register_assets' ) );
		add_action( 'login_enqueue_scripts', array( $this, 'enqueue_login_assets' ) );
		add_filter( 'body_class', array( $this, 'add_body_class' ) );
		add_filter( 'login_redirect', array( $this, 'redirect_after_login' ), 10, 3 );
		add_shortcode( 'pool_table_tracker', array( $this, 'render_app' ) );
		add_shortcode( 'darts_board_manager', array( $this, 'render_darts_app' ) );

		$actions = array(
			'ptt_get_state'      => 'ajax_get_state',
			'ptt_start_table'    => 'ajax_start_table',
			'ptt_add_guest'      => 'ajax_add_guest',
			'ptt_end_guest'      => 'ajax_end_guest',
			'ptt_toggle_guest_exclusion' => 'ajax_toggle_guest_exclusion',
			'ptt_checkout_table' => 'ajax_checkout_table',
			'ptt_swap_table'     => 'ajax_swap_table',
			'ptt_transfer_player' => 'ajax_transfer_player',
			'ptt_transfer_table' => 'ajax_transfer_table',
			'ptt_search_history' => 'ajax_search_history',
			'ptt_clear_history'  => 'ajax_clear_history',
			'ptt_clear_history_item' => 'ajax_clear_history_item',
			'ptt_add_table'      => 'ajax_add_table',
			'ptt_remove_table'   => 'ajax_remove_table',
			'ptt_exclude_table'  => 'ajax_exclude_table',
			'ptt_restore_table'  => 'ajax_restore_table',
			'ptt_update_rate'    => 'ajax_update_rate',
			'dbm_get_state'      => 'ajax_darts_get_state',
			'dbm_start_session'  => 'ajax_darts_start_session',
			'dbm_end_session'    => 'ajax_darts_end_session',
			'dbm_update_status'  => 'ajax_darts_update_status',
			'dbm_exclude_board'  => 'ajax_darts_exclude_board',
			'dbm_restore_board'  => 'ajax_darts_restore_board',
			'dbm_transfer_board' => 'ajax_darts_transfer_board',
			'dbm_add_guest'      => 'ajax_darts_add_guest',
			'dbm_transfer_guest' => 'ajax_darts_transfer_guest',
			'dbm_search_history' => 'ajax_darts_search_history',
		);

		foreach ( $actions as $action => $method ) {
			add_action( 'wp_ajax_' . $action, array( $this, $method ) );
		}
	}

	public static function activate() {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset_collate = $wpdb->get_charset_collate();
		$tables_table    = self::tables_table();
		$guests_table    = self::guests_table();
		$activity_table  = self::activity_table();
		$dart_boards_table = self::dart_boards_table();
		$dart_sessions_table = self::dart_sessions_table();
		$dart_players_table = self::dart_players_table();

		dbDelta(
			"CREATE TABLE {$tables_table} (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				table_number INT UNSIGNED NOT NULL,
				label VARCHAR(100) NOT NULL,
				is_active TINYINT(1) NOT NULL DEFAULT 1,
				is_open TINYINT(1) NOT NULL DEFAULT 0,
				is_excluded TINYINT(1) NOT NULL DEFAULT 0,
				created_at DATETIME NOT NULL,
				PRIMARY KEY (id),
				UNIQUE KEY table_number (table_number)
			) {$charset_collate};"
		);

		dbDelta(
			"CREATE TABLE {$guests_table} (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				table_id BIGINT UNSIGNED NOT NULL,
				table_number INT UNSIGNED NOT NULL,
				guest_name VARCHAR(190) NOT NULL,
				started_at DATETIME NOT NULL,
				ended_at DATETIME NULL,
				status VARCHAR(20) NOT NULL DEFAULT 'active',
				is_excluded TINYINT(1) NOT NULL DEFAULT 0,
				charged_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
				created_at DATETIME NOT NULL,
				updated_at DATETIME NOT NULL,
				PRIMARY KEY (id),
				KEY table_id (table_id),
				KEY table_number (table_number),
				KEY guest_name (guest_name),
				KEY started_at (started_at),
				KEY status (status)
			) {$charset_collate};"
		);

		dbDelta(
			"CREATE TABLE {$activity_table} (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				action VARCHAR(50) NOT NULL,
				table_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
				table_number INT UNSIGNED NOT NULL DEFAULT 0,
				table_label VARCHAR(100) NOT NULL DEFAULT '',
				source_table_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
				source_table_number INT UNSIGNED NOT NULL DEFAULT 0,
				source_table_label VARCHAR(100) NOT NULL DEFAULT '',
				destination_table_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
				destination_table_number INT UNSIGNED NOT NULL DEFAULT 0,
				destination_table_label VARCHAR(100) NOT NULL DEFAULT '',
				guest_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
				guest_name VARCHAR(190) NOT NULL DEFAULT '',
				user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
				user_name VARCHAR(190) NOT NULL DEFAULT '',
				reason TEXT NULL,
				created_at DATETIME NOT NULL,
				PRIMARY KEY (id),
				KEY action (action),
				KEY table_number (table_number),
				KEY source_table_number (source_table_number),
				KEY destination_table_number (destination_table_number),
				KEY guest_id (guest_id),
				KEY user_id (user_id),
				KEY created_at (created_at)
			) {$charset_collate};"
		);

		dbDelta(
			"CREATE TABLE {$dart_boards_table} (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				board_number INT UNSIGNED NOT NULL,
				label VARCHAR(100) NOT NULL,
				status VARCHAR(30) NOT NULL DEFAULT 'available',
				is_active TINYINT(1) NOT NULL DEFAULT 1,
				created_at DATETIME NOT NULL,
				updated_at DATETIME NOT NULL,
				PRIMARY KEY (id),
				UNIQUE KEY board_number (board_number),
				KEY status (status)
			) {$charset_collate};"
		);

		dbDelta(
			"CREATE TABLE {$dart_sessions_table} (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				board_id BIGINT UNSIGNED NOT NULL,
				board_number INT UNSIGNED NOT NULL,
				board_label VARCHAR(100) NOT NULL,
				player_count INT UNSIGNED NOT NULL,
				started_at DATETIME NOT NULL,
				ended_at DATETIME NULL,
				status VARCHAR(20) NOT NULL DEFAULT 'active',
				charged_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
				payment_collected TINYINT(1) NOT NULL DEFAULT 0,
				created_at DATETIME NOT NULL,
				updated_at DATETIME NOT NULL,
				PRIMARY KEY (id),
				KEY board_id (board_id),
				KEY board_number (board_number),
				KEY started_at (started_at),
				KEY status (status)
			) {$charset_collate};"
		);

		dbDelta(
			"CREATE TABLE {$dart_players_table} (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				session_id BIGINT UNSIGNED NOT NULL,
				board_id BIGINT UNSIGNED NOT NULL,
				board_number INT UNSIGNED NOT NULL,
				board_label VARCHAR(100) NOT NULL,
				guest_name VARCHAR(190) NOT NULL,
				started_at DATETIME NOT NULL,
				ended_at DATETIME NULL,
				status VARCHAR(20) NOT NULL DEFAULT 'active',
				charged_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
				created_at DATETIME NOT NULL,
				updated_at DATETIME NOT NULL,
				PRIMARY KEY (id),
				KEY session_id (session_id),
				KEY board_id (board_id),
				KEY guest_name (guest_name),
				KEY status (status)
			) {$charset_collate};"
		);

		$count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$tables_table}" );
		if ( 0 === $count ) {
			for ( $i = 1; $i <= 16; $i++ ) {
				$wpdb->insert(
					$tables_table,
					array(
						'table_number' => $i,
						'label'        => 'Table ' . $i,
						'is_active'    => 1,
						'is_open'      => 0,
						'is_excluded'  => 0,
						'created_at'   => current_time( 'mysql' ),
					),
					array( '%d', '%s', '%d', '%d', '%d', '%s' )
				);
			}
		}

		if ( false === get_option( self::RATE_OPTION, false ) ) {
			add_option( self::RATE_OPTION, self::HOURLY_RATE );
		}

		self::seed_dart_boards();
		self::ensure_tracker_page();
		self::ensure_darts_page();
	}

	public static function ensure_schema() {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset_collate = $wpdb->get_charset_collate();
		$tables_table = self::tables_table();
		$table_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $tables_table ) );

		if ( ! $table_exists ) {
			return;
		}

		$column = $wpdb->get_var( "SHOW COLUMNS FROM {$tables_table} LIKE 'is_open'" );

		if ( ! $column ) {
			$wpdb->query( "ALTER TABLE {$tables_table} ADD is_open TINYINT(1) NOT NULL DEFAULT 0 AFTER is_active" );
		}

		$table_excluded_column = $wpdb->get_var( "SHOW COLUMNS FROM {$tables_table} LIKE 'is_excluded'" );
		if ( ! $table_excluded_column ) {
			$wpdb->query( "ALTER TABLE {$tables_table} ADD is_excluded TINYINT(1) NOT NULL DEFAULT 0 AFTER is_open" );
		}

		dbDelta(
			"CREATE TABLE " . self::activity_table() . " (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				action VARCHAR(50) NOT NULL,
				table_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
				table_number INT UNSIGNED NOT NULL DEFAULT 0,
				table_label VARCHAR(100) NOT NULL DEFAULT '',
				source_table_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
				source_table_number INT UNSIGNED NOT NULL DEFAULT 0,
				source_table_label VARCHAR(100) NOT NULL DEFAULT '',
				destination_table_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
				destination_table_number INT UNSIGNED NOT NULL DEFAULT 0,
				destination_table_label VARCHAR(100) NOT NULL DEFAULT '',
				guest_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
				guest_name VARCHAR(190) NOT NULL DEFAULT '',
				user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
				user_name VARCHAR(190) NOT NULL DEFAULT '',
				reason TEXT NULL,
				created_at DATETIME NOT NULL,
				PRIMARY KEY (id),
				KEY action (action),
				KEY table_number (table_number),
				KEY source_table_number (source_table_number),
				KEY destination_table_number (destination_table_number),
				KEY guest_id (guest_id),
				KEY user_id (user_id),
				KEY created_at (created_at)
			) {$charset_collate};"
		);

		$guests_table  = self::guests_table();
		$guests_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $guests_table ) );
		if ( $guests_exists ) {
			$guest_column = $wpdb->get_var( "SHOW COLUMNS FROM {$guests_table} LIKE 'is_excluded'" );
			if ( ! $guest_column ) {
				$wpdb->query( "ALTER TABLE {$guests_table} ADD is_excluded TINYINT(1) NOT NULL DEFAULT 0 AFTER status" );
			}

			$wpdb->query(
				$wpdb->prepare(
					'UPDATE ' . self::tables_table() . ' SET is_open = 1 WHERE id IN (SELECT DISTINCT table_id FROM ' . self::guests_table() . ' WHERE status IN (%s, %s))',
					self::ACTIVE_STATUS,
					self::STOPPED_STATUS
				)
			);
		}

		dbDelta(
			"CREATE TABLE " . self::dart_boards_table() . " (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				board_number INT UNSIGNED NOT NULL,
				label VARCHAR(100) NOT NULL,
				status VARCHAR(30) NOT NULL DEFAULT 'available',
				is_active TINYINT(1) NOT NULL DEFAULT 1,
				created_at DATETIME NOT NULL,
				updated_at DATETIME NOT NULL,
				PRIMARY KEY (id),
				UNIQUE KEY board_number (board_number),
				KEY status (status)
			) {$charset_collate};"
		);

		dbDelta(
			"CREATE TABLE " . self::dart_sessions_table() . " (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				board_id BIGINT UNSIGNED NOT NULL,
				board_number INT UNSIGNED NOT NULL,
				board_label VARCHAR(100) NOT NULL,
				player_count INT UNSIGNED NOT NULL,
				started_at DATETIME NOT NULL,
				ended_at DATETIME NULL,
				status VARCHAR(20) NOT NULL DEFAULT 'active',
				charged_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
				payment_collected TINYINT(1) NOT NULL DEFAULT 0,
				created_at DATETIME NOT NULL,
				updated_at DATETIME NOT NULL,
				PRIMARY KEY (id),
				KEY board_id (board_id),
				KEY board_number (board_number),
				KEY started_at (started_at),
				KEY status (status)
			) {$charset_collate};"
		);

		dbDelta(
			"CREATE TABLE " . self::dart_players_table() . " (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				session_id BIGINT UNSIGNED NOT NULL,
				board_id BIGINT UNSIGNED NOT NULL,
				board_number INT UNSIGNED NOT NULL,
				board_label VARCHAR(100) NOT NULL,
				guest_name VARCHAR(190) NOT NULL,
				started_at DATETIME NOT NULL,
				ended_at DATETIME NULL,
				status VARCHAR(20) NOT NULL DEFAULT 'active',
				charged_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
				created_at DATETIME NOT NULL,
				updated_at DATETIME NOT NULL,
				PRIMARY KEY (id),
				KEY session_id (session_id),
				KEY board_id (board_id),
				KEY guest_name (guest_name),
				KEY status (status)
			) {$charset_collate};"
		);

		self::seed_dart_boards();
	}

	public static function ensure_tracker_page() {
		$page_id = (int) get_option( self::PAGE_OPTION, 0 );
		$content = '[pool_table_tracker]';

		if ( $page_id && 'page' === get_post_type( $page_id ) ) {
			if ( 'trash' === get_post_status( $page_id ) ) {
				wp_untrash_post( $page_id );
			}

			$current_content = get_post_field( 'post_content', $page_id );
			if ( false === strpos( $current_content, '[pool_table_tracker' ) ) {
				wp_update_post(
					array(
						'ID'           => $page_id,
						'post_content' => $content,
					)
				);
			}

			return $page_id;
		}

		$existing = get_page_by_path( 'pool-table-tracker' );
		if ( $existing && 'page' === $existing->post_type ) {
			if ( false === strpos( $existing->post_content, '[pool_table_tracker' ) ) {
				wp_update_post(
					array(
						'ID'           => $existing->ID,
						'post_content' => $content,
					)
				);
			}

			update_option( self::PAGE_OPTION, $existing->ID );
			return (int) $existing->ID;
		}

		$page_id = wp_insert_post(
			array(
				'post_title'   => 'Pool Table Tracker',
				'post_name'    => 'pool-table-tracker',
				'post_content' => $content,
				'post_status'  => 'publish',
				'post_type'    => 'page',
			)
		);

		if ( ! is_wp_error( $page_id ) && $page_id ) {
			update_option( self::PAGE_OPTION, $page_id );
			flush_rewrite_rules();
			return (int) $page_id;
		}

		return 0;
	}

	public static function ensure_darts_page() {
		$page_id = (int) get_option( self::DARTS_PAGE_OPTION, 0 );
		$content = '[darts_board_manager]';

		if ( $page_id && 'page' === get_post_type( $page_id ) ) {
			if ( 'trash' === get_post_status( $page_id ) ) {
				wp_untrash_post( $page_id );
			}

			$current_content = get_post_field( 'post_content', $page_id );
			if ( false === strpos( $current_content, '[darts_board_manager' ) ) {
				wp_update_post(
					array(
						'ID'           => $page_id,
						'post_content' => $content,
					)
				);
			}

			return $page_id;
		}

		$existing = get_page_by_path( 'darts-board-manager' );
		if ( $existing && 'page' === $existing->post_type ) {
			if ( false === strpos( $existing->post_content, '[darts_board_manager' ) ) {
				wp_update_post(
					array(
						'ID'           => $existing->ID,
						'post_content' => $content,
					)
				);
			}

			update_option( self::DARTS_PAGE_OPTION, $existing->ID );
			return (int) $existing->ID;
		}

		$page_id = wp_insert_post(
			array(
				'post_title'   => 'Darts Board Manager',
				'post_name'    => 'darts-board-manager',
				'post_content' => $content,
				'post_status'  => 'publish',
				'post_type'    => 'page',
			)
		);

		if ( ! is_wp_error( $page_id ) && $page_id ) {
			update_option( self::DARTS_PAGE_OPTION, $page_id );
			flush_rewrite_rules();
			return (int) $page_id;
		}

		return 0;
	}

	public static function cleanup_shortcode_pages() {
		$pages = array(
			array(
				'option'    => self::PAGE_OPTION,
				'slug'      => self::TRACKER_SLUG,
				'shortcode' => 'pool_table_tracker',
			),
			array(
				'option'    => self::DARTS_PAGE_OPTION,
				'slug'      => self::DARTS_SLUG,
				'shortcode' => 'darts_board_manager',
			),
		);

		foreach ( $pages as $page ) {
			$ids = array();
			$option_id = (int) get_option( $page['option'], 0 );
			if ( $option_id ) {
				$ids[] = $option_id;
			}

			$existing = get_page_by_path( $page['slug'] );
			if ( $existing && 'page' === $existing->post_type ) {
				$ids[] = (int) $existing->ID;
			}

			foreach ( array_unique( $ids ) as $page_id ) {
				if ( 'page' !== get_post_type( $page_id ) || 'trash' === get_post_status( $page_id ) ) {
					continue;
				}

				$content = get_post_field( 'post_content', $page_id );
				if ( false !== strpos( $content, '[' . $page['shortcode'] ) ) {
					wp_trash_post( $page_id );
				}
			}

			delete_option( $page['option'] );
		}
	}

	private static function tracker_url() {
		return home_url( '/' . self::TRACKER_SLUG . '/' );
	}

	private static function darts_url() {
		return home_url( '/' . self::DARTS_SLUG . '/' );
	}

	public function register_admin_menu() {
		add_menu_page(
			__( 'Pool Table Tracker', 'pool-table-tracker' ),
			__( 'Pool Tracker', 'pool-table-tracker' ),
			'manage_options',
			'pool-table-tracker',
			array( $this, 'render_admin_page' ),
			'dashicons-clock',
			26
		);

		add_submenu_page(
			'pool-table-tracker',
			__( 'Darts Board Manager', 'pool-table-tracker' ),
			__( 'Darts Boards', 'pool-table-tracker' ),
			'manage_options',
			'darts-board-manager',
			array( $this, 'render_darts_admin_page' )
		);
	}

	public function register_assets() {
		wp_register_style(
			'font-awesome-5',
			'https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css',
			array(),
			'5.15.4'
		);

		wp_register_style(
			'pool-table-tracker',
			PTT_PLUGIN_URL . 'assets/pool-table-tracker.css',
			array( 'font-awesome-5' ),
			PTT_VERSION
		);

		wp_register_script(
			'pool-table-tracker',
			PTT_PLUGIN_URL . 'assets/pool-table-tracker.js',
			array(),
			PTT_VERSION,
			true
		);

		wp_register_script(
			'darts-board-manager',
			PTT_PLUGIN_URL . 'assets/darts-board-manager.js',
			array(),
			PTT_VERSION,
			true
		);
	}

	public function enqueue_login_assets() {
		wp_enqueue_style(
			'font-awesome-5',
			'https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css',
			array(),
			'5.15.4'
		);

		$tracker_url = esc_url( get_permalink( self::ensure_tracker_page() ) );
		?>
		<style>
			body.login {
				align-items: center;
				background: #f4f6f5;
				display: flex;
				font-family: Inter, ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
				justify-content: center;
				min-height: 100vh;
			}

			body.login div#login {
				background: rgba(255, 255, 255, 0.9);
				border: 1px solid #d9e1dd;
				border-radius: 8px;
				box-shadow: 0 20px 60px rgba(19, 33, 29, 0.12);
				margin: 20px;
				padding: 30px;
				width: min(420px, calc(100vw - 28px));
			}

			body.login h1 a {
				background-image: none;
				color: #13211d;
				font-size: 0;
				height: auto;
				margin: 0 auto 18px;
				pointer-events: none;
				text-indent: 0;
				width: auto;
			}

			body.login h1 a::before {
				content: "Pool Table Tracker";
				display: block;
				font-family: Inter, ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
				font-size: 28px;
				font-weight: 800;
				line-height: 1.15;
			}

			body.login form {
				background: transparent;
				border: 0;
				box-shadow: none;
				margin: 0;
				padding: 0;
			}

			body.login label {
				color: #66736e;
				font-weight: 800;
			}

			body.login input[type="text"],
			body.login input[type="password"] {
				background: #fff;
				border: 1px solid #d9e1dd;
				border-radius: 8px;
				box-shadow: none;
				color: #13211d;
				font-size: 18px;
				min-height: 48px;
				padding: 10px 12px;
			}

			body.login input:focus {
				border-color: #073d2e;
				box-shadow: 0 0 0 3px rgba(7, 61, 46, 0.14);
				outline: none;
			}

			body.login .button-primary {
				align-items: center;
				background: #073d2e !important;
				border: 0 !important;
				border-radius: 8px;
				box-shadow: none !important;
				display: inline-flex;
				font-weight: 800;
				justify-content: center;
				min-height: 46px;
				padding: 0 18px;
				text-shadow: none !important;
			}

			body.login .button-primary:hover,
			body.login .button-primary:focus,
			body.login .button-primary:active {
				background: #0b4b39 !important;
				color: #fff !important;
			}

			body.login #nav,
			body.login #backtoblog,
			body.login .language-switcher {
				display: none;
			}

			body.login .message,
			body.login .notice,
			body.login #login_error {
				border-left-color: #073d2e;
				border-radius: 8px;
				box-shadow: none;
			}
		</style>
		<script>
			window.PoolTableTrackerLoginUrl = "<?php echo $tracker_url; ?>";
		</script>
		<?php
	}

	public function render_admin_page() {
		wp_safe_redirect( get_permalink( self::ensure_tracker_page() ) );
		exit;
	}

	public function render_darts_admin_page() {
		wp_safe_redirect( get_permalink( self::ensure_darts_page() ) );
		exit;
	}

	public function redirect_admin_tracker_pages() {
		if ( wp_doing_ajax() || ! is_admin() || ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
		if ( 'pool-table-tracker' === $page ) {
			wp_safe_redirect( get_permalink( self::ensure_tracker_page() ) );
			exit;
		}

		if ( 'darts-board-manager' === $page ) {
			wp_safe_redirect( get_permalink( self::ensure_darts_page() ) );
			exit;
		}
	}

	public function add_body_class( $classes ) {
		if ( is_singular() ) {
			$post = get_post();
			if ( $post && has_shortcode( $post->post_content, 'pool_table_tracker' ) ) {
				$classes[] = 'ptt-tracker-page';
			}
			if ( $post && has_shortcode( $post->post_content, 'darts_board_manager' ) ) {
				$classes[] = 'ptt-tracker-page';
			}
		}

		return $classes;
	}

	public function protect_tracker_page() {
		if ( ! $this->is_tracker_page() ) {
			return;
		}

		if ( ! is_user_logged_in() || ! current_user_can( 'manage_options' ) ) {
			if ( is_user_logged_in() ) {
				wp_logout();
			}

			wp_safe_redirect( wp_login_url( get_permalink() ) );
			exit;
		}
	}

	public function redirect_after_login( $redirect_to, $requested_redirect_to, $user ) {
		if ( is_wp_error( $user ) || ! $user instanceof WP_User ) {
			return $redirect_to;
		}

		if ( ! user_can( $user, 'manage_options' ) ) {
			wp_logout();
			return wp_login_url();
		}

		if ( $requested_redirect_to ) {
			return $requested_redirect_to;
		}

		$page_id = self::ensure_tracker_page();
		return $page_id ? get_permalink( $page_id ) : $redirect_to;
	}

	private function is_tracker_page() {
		if ( ! is_singular() ) {
			return false;
		}

		$post = get_post();
		return $post && ( has_shortcode( $post->post_content, 'pool_table_tracker' ) || has_shortcode( $post->post_content, 'darts_board_manager' ) );
	}

	private function get_virtual_app() {
		$request_path = isset( $_SERVER['REQUEST_URI'] ) ? wp_parse_url( esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ), PHP_URL_PATH ) : '';
		$request_path = trim( (string) $request_path, '/' );

		$pool_path  = trim( (string) wp_parse_url( self::tracker_url(), PHP_URL_PATH ), '/' );
		$darts_path = trim( (string) wp_parse_url( self::darts_url(), PHP_URL_PATH ), '/' );

		if ( $request_path === $pool_path ) {
			return 'pool';
		}

		if ( $request_path === $darts_path ) {
			return 'darts';
		}

		return '';
	}

	private function render_virtual_app( $app ) {
		status_header( 200 );
		nocache_headers();

		$this->register_assets();
		$title = 'darts' === $app ? 'Darts Board Manager' : 'Pool Table Tracker';
		$html  = 'darts' === $app ? $this->render_darts_app() : $this->render_app();
		?>
		<!doctype html>
		<html <?php language_attributes(); ?>>
		<head>
			<meta charset="<?php bloginfo( 'charset' ); ?>">
			<meta name="viewport" content="width=device-width, initial-scale=1">
			<title><?php echo esc_html( $title ); ?></title>
			<?php wp_head(); ?>
		</head>
		<body <?php body_class( 'ptt-tracker-page' ); ?>>
			<?php echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			<?php wp_footer(); ?>
		</body>
		</html>
		<?php
	}

	public function render_app() {
		if ( ! is_user_logged_in() ) {
			return '<p>Please log in to use Pool Table Tracker.</p>';
		}

		wp_enqueue_style( 'pool-table-tracker' );
		wp_enqueue_script( 'pool-table-tracker' );
		wp_localize_script(
			'pool-table-tracker',
			'PoolTableTracker',
			array(
				'ajaxUrl'    => admin_url( 'admin-ajax.php' ),
				'nonce'      => wp_create_nonce( 'ptt_nonce' ),
				'canManage'  => current_user_can( 'manage_options' ),
				'hourlyRate' => self::HOURLY_RATE,
				'autoFullscreen' => true,
			)
		);

		ob_start();
		?>
		<div class="ptt-app" data-ptt-app>
			<header class="ptt-header">
				<nav class="ptt-tabs" aria-label="Pool tracker views">
					<button class="is-active" type="button" data-ptt-tab="dashboard"><i class="fas fa-tachometer-alt" aria-hidden="true"></i> Dashboard</button>
					<button type="button" data-ptt-tab="history"><i class="fas fa-history" aria-hidden="true"></i> History</button>
					<button type="button" data-ptt-tab="settings"><i class="fas fa-cog" aria-hidden="true"></i> Settings</button>
				</nav>
				<div class="ptt-header-actions">
					<div class="ptt-stats" aria-live="polite">
						<div><i class="fas fa-clock" aria-hidden="true"></i> <span data-ptt-active-count>0</span> active tables</div>
						<div><i class="fas fa-dollar-sign" aria-hidden="true"></i> <span data-ptt-live-total>0.00</span> live</div>
					</div>
					<button class="ptt-fullscreen-toggle" type="button" data-ptt-fullscreen aria-label="Toggle fullscreen" title="Fullscreen">
						<i class="fas fa-expand" aria-hidden="true"></i>
					</button>
				</div>
			</header>

			<main>
				<section class="ptt-view is-active" data-ptt-view="dashboard">
					<div class="ptt-grid" data-ptt-table-grid></div>
				</section>

				<section class="ptt-view" data-ptt-view="history">
					<div class="ptt-history-tools">
						<input type="search" data-ptt-history-query placeholder="Search guest, table, or date">
						<button type="button" data-ptt-history-search><i class="fas fa-search" aria-hidden="true"></i> Search</button>
					</div>
					<div class="ptt-history-list" data-ptt-history-list></div>
				</section>

				<section class="ptt-view" data-ptt-view="settings">
					<div class="ptt-settings">
						<div class="ptt-rate-pill"><i class="fas fa-dollar-sign" aria-hidden="true"></i> $5 per person / hour</div>
						<?php if ( current_user_can( 'manage_options' ) ) : ?>
							<button type="button" data-ptt-add-table><i class="fas fa-plus" aria-hidden="true"></i> Add Table</button>
						<?php endif; ?>
					</div>
					<div class="ptt-settings-table-list" data-ptt-settings-tables></div>
				</section>
			</main>

			<div class="ptt-dialog" data-ptt-dialog aria-hidden="true">
				<form class="ptt-dialog-card" data-ptt-guest-form>
					<h2 data-ptt-dialog-title>Guest</h2>
					<input type="hidden" data-ptt-dialog-table-id>
					<label>
						Guest name
						<input type="text" data-ptt-guest-name required maxlength="190" autocomplete="off">
					</label>
					<div class="ptt-dialog-actions">
						<button type="button" data-ptt-cancel><i class="fas fa-times" aria-hidden="true"></i> Cancel</button>
						<button type="submit"><i class="fas fa-play" aria-hidden="true"></i> Start Timer</button>
					</div>
				</form>
			</div>

			<div class="ptt-dialog" data-ptt-swap-dialog aria-hidden="true">
				<form class="ptt-dialog-card" data-ptt-swap-form>
					<h2>Table Transfer</h2>
					<input type="hidden" data-ptt-swap-source>
					<label>
						Move entire table session to
						<select data-ptt-swap-target required></select>
					</label>
					<div class="ptt-dialog-actions">
						<button type="button" data-ptt-swap-cancel><i class="fas fa-times" aria-hidden="true"></i> Cancel</button>
						<button type="submit"><i class="fas fa-exchange-alt" aria-hidden="true"></i> Transfer</button>
					</div>
				</form>
			</div>

			<div class="ptt-dialog" data-ptt-player-transfer-dialog aria-hidden="true">
				<form class="ptt-dialog-card" data-ptt-player-transfer-form>
					<h2>Player Transfer</h2>
					<input type="hidden" data-ptt-player-transfer-guest>
					<label>
						Move player to
						<select data-ptt-player-transfer-target required></select>
					</label>
					<div class="ptt-dialog-actions">
						<button type="button" data-ptt-player-transfer-cancel><i class="fas fa-times" aria-hidden="true"></i> Cancel</button>
						<button type="submit"><i class="fas fa-exchange-alt" aria-hidden="true"></i> Transfer</button>
					</div>
				</form>
			</div>
		</div>
		<?php
		return ob_get_clean();
	}

	public function render_darts_app() {
		if ( ! is_user_logged_in() ) {
			return '<p>Please log in to use Darts Board Manager.</p>';
		}

		wp_enqueue_style( 'pool-table-tracker' );
		wp_enqueue_script( 'darts-board-manager' );
		wp_localize_script(
			'darts-board-manager',
			'DartsBoardManager',
			array(
				'ajaxUrl'    => admin_url( 'admin-ajax.php' ),
				'nonce'      => wp_create_nonce( 'ptt_nonce' ),
				'canManage'  => current_user_can( 'manage_options' ),
				'hourlyRate' => self::DARTS_HOURLY_RATE,
				'autoFullscreen' => true,
			)
		);

		ob_start();
		?>
		<div class="ptt-app dbm-app" data-darts-app>
			<header class="ptt-header">
				<nav class="ptt-tabs" aria-label="Darts manager views">
					<button class="is-active" type="button" data-dbm-tab="dashboard"><i class="fas fa-bullseye" aria-hidden="true"></i> Dashboard</button>
					<button type="button" data-dbm-tab="history"><i class="fas fa-chart-line" aria-hidden="true"></i> History / Report</button>
				</nav>
				<div class="ptt-header-actions">
					<div class="ptt-stats" aria-live="polite">
						<div><i class="fas fa-play-circle" aria-hidden="true"></i> <span data-dbm-running-count>0</span> running</div>
						<div><i class="fas fa-dollar-sign" aria-hidden="true"></i> <span data-dbm-live-total>0.00</span> live</div>
					</div>
					<button class="ptt-fullscreen-toggle" type="button" data-dbm-fullscreen aria-label="Toggle fullscreen" title="Fullscreen">
						<i class="fas fa-expand" aria-hidden="true"></i>
					</button>
				</div>
			</header>

			<main>
				<section class="ptt-view is-active" data-dbm-view="dashboard">
					<div class="dbm-board-grid" data-dbm-board-grid></div>
				</section>

				<section class="ptt-view" data-dbm-view="history">
					<div class="dbm-report" data-dbm-report></div>
					<div class="ptt-history-tools">
						<input type="search" data-dbm-history-query placeholder="Search board or date">
						<button type="button" data-dbm-history-search><i class="fas fa-search" aria-hidden="true"></i> Search</button>
					</div>
					<div class="ptt-history-list" data-dbm-history-list></div>
				</section>
			</main>

			<div class="ptt-dialog" data-dbm-start-dialog aria-hidden="true">
				<form class="ptt-dialog-card" data-dbm-start-form>
					<h2>Start Darts Session</h2>
					<input type="hidden" data-dbm-board-id>
					<label>
						Player name
						<input type="text" data-dbm-player-name maxlength="190" autocomplete="off" required>
					</label>
					<div class="ptt-dialog-actions">
						<button type="button" data-dbm-cancel><i class="fas fa-times" aria-hidden="true"></i> Cancel</button>
						<button type="submit"><i class="fas fa-play" aria-hidden="true"></i> Start</button>
					</div>
				</form>
			</div>

			<div class="ptt-dialog" data-dbm-transfer-dialog aria-hidden="true">
				<form class="ptt-dialog-card" data-dbm-transfer-form>
					<h2>Transfer Darts Board</h2>
					<input type="hidden" data-dbm-transfer-source>
					<label>
						Move running session to
						<select data-dbm-transfer-target required></select>
					</label>
					<div class="ptt-dialog-actions">
						<button type="button" data-dbm-transfer-cancel><i class="fas fa-times" aria-hidden="true"></i> Cancel</button>
						<button type="submit"><i class="fas fa-exchange-alt" aria-hidden="true"></i> Transfer</button>
					</div>
				</form>
			</div>

			<div class="ptt-dialog" data-dbm-guest-dialog aria-hidden="true">
				<form class="ptt-dialog-card" data-dbm-guest-form>
					<h2>Add Guest</h2>
					<input type="hidden" data-dbm-guest-board>
					<label>
						Guest name
						<input type="text" data-dbm-guest-name maxlength="190" autocomplete="off" required>
					</label>
					<div class="ptt-dialog-actions">
						<button type="button" data-dbm-guest-cancel><i class="fas fa-times" aria-hidden="true"></i> Cancel</button>
						<button type="submit"><i class="fas fa-user-plus" aria-hidden="true"></i> Add Guest</button>
					</div>
				</form>
			</div>

			<div class="ptt-dialog" data-dbm-guest-transfer-dialog aria-hidden="true">
				<form class="ptt-dialog-card" data-dbm-guest-transfer-form>
					<h2>Transfer Guest</h2>
					<input type="hidden" data-dbm-guest-transfer-player>
					<label>
						Move guest to running board
						<select data-dbm-guest-transfer-target required></select>
					</label>
					<div class="ptt-dialog-actions">
						<button type="button" data-dbm-guest-transfer-cancel><i class="fas fa-times" aria-hidden="true"></i> Cancel</button>
						<button type="submit"><i class="fas fa-user-friends" aria-hidden="true"></i> Transfer Guest</button>
					</div>
				</form>
			</div>
		</div>
		<?php
		return ob_get_clean();
	}

	public function ajax_get_state() {
		$this->verify_request();
		wp_send_json_success( $this->get_state() );
	}

	public function ajax_darts_get_state() {
		$this->verify_request();
		wp_send_json_success( $this->get_darts_state() );
	}

	public function ajax_darts_start_session() {
		$this->verify_request( 'manage_options' );

		$board_id   = isset( $_POST['board_id'] ) ? absint( $_POST['board_id'] ) : 0;
		$player_name = isset( $_POST['player_name'] ) ? sanitize_text_field( wp_unslash( $_POST['player_name'] ) ) : '';

		if ( ! $board_id || '' === $player_name ) {
			wp_send_json_error( array( 'message' => 'Choose a board and enter the player name.' ), 400 );
		}

		global $wpdb;
		$board = $this->get_dart_board( $board_id );
		if ( ! $board || self::BOARD_AVAILABLE !== $board->status ) {
			wp_send_json_error( array( 'message' => 'This board is not available.' ), 400 );
		}

		$now = current_time( 'mysql' );
		$wpdb->insert(
			self::dart_sessions_table(),
			array(
				'board_id'          => (int) $board->id,
				'board_number'      => (int) $board->board_number,
				'board_label'       => $board->label,
				'player_count'      => 1,
				'started_at'        => $now,
				'status'            => self::ACTIVE_STATUS,
				'charged_amount'    => 0,
				'payment_collected' => 0,
				'created_at'        => $now,
				'updated_at'        => $now,
			),
			array( '%d', '%d', '%s', '%d', '%s', '%s', '%f', '%d', '%s', '%s' )
		);

		$session_id = (int) $wpdb->insert_id;
		$this->insert_darts_player( $session_id, $board, $player_name, $now );

		$wpdb->update(
			self::dart_boards_table(),
			array(
				'status'     => self::BOARD_RUNNING,
				'updated_at' => $now,
			),
			array( 'id' => $board_id ),
			array( '%s', '%s' ),
			array( '%d' )
		);

		wp_send_json_success( $this->get_darts_state() );
	}

	public function ajax_darts_end_session() {
		$this->verify_request( 'manage_options' );

		$board_id = isset( $_POST['board_id'] ) ? absint( $_POST['board_id'] ) : 0;
		if ( ! $board_id ) {
			wp_send_json_error( array( 'message' => 'Board was not found.' ), 400 );
		}

		global $wpdb;
		$session = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM ' . self::dart_sessions_table() . ' WHERE board_id = %d AND status = %s ORDER BY started_at DESC LIMIT 1',
				$board_id,
				self::ACTIVE_STATUS
			)
		);

		if ( ! $session ) {
			wp_send_json_error( array( 'message' => 'No running session found for this board.' ), 404 );
		}

		$now = current_time( 'mysql' );
		$this->ensure_darts_session_players( $session );
		$players = $this->get_darts_players( (int) $session->id );
		$amount = 0;
		foreach ( $players as $player ) {
			$player_amount = $this->calculate_darts_charge( $player->started_at, $now, 1 );
			$amount += $player_amount;
			$wpdb->update(
				self::dart_players_table(),
				array(
					'ended_at'       => $now,
					'status'         => self::COMPLETED_STATUS,
					'charged_amount' => $player_amount,
					'updated_at'     => $now,
				),
				array( 'id' => (int) $player->id ),
				array( '%s', '%s', '%f', '%s' ),
				array( '%d' )
			);
		}
		$amount = round( $amount, 2 );

		$wpdb->update(
			self::dart_sessions_table(),
			array(
				'ended_at'          => $now,
				'status'            => self::COMPLETED_STATUS,
				'charged_amount'    => $amount,
				'payment_collected' => 1,
				'updated_at'        => $now,
			),
			array( 'id' => (int) $session->id ),
			array( '%s', '%s', '%f', '%d', '%s' ),
			array( '%d' )
		);

		$wpdb->update(
			self::dart_boards_table(),
			array(
				'status'     => self::BOARD_AVAILABLE,
				'updated_at' => $now,
			),
			array( 'id' => $board_id ),
			array( '%s', '%s' ),
			array( '%d' )
		);

		wp_send_json_success( $this->get_darts_state() );
	}

	public function ajax_darts_update_status() {
		$this->verify_request( 'manage_options' );

		$board_id = isset( $_POST['board_id'] ) ? absint( $_POST['board_id'] ) : 0;
		$status   = isset( $_POST['status'] ) ? sanitize_key( wp_unslash( $_POST['status'] ) ) : '';
		$allowed  = array( self::BOARD_AVAILABLE, self::BOARD_EXCLUDED );

		if ( ! $board_id || ! in_array( $status, $allowed, true ) ) {
			wp_send_json_error( array( 'message' => 'Choose a valid board status.' ), 400 );
		}

		$board = $this->get_dart_board( $board_id );
		if ( ! $board ) {
			wp_send_json_error( array( 'message' => 'Board was not found.' ), 404 );
		}

		if ( self::BOARD_RUNNING === $board->status ) {
			wp_send_json_error( array( 'message' => 'End and collect the running session before changing status.' ), 400 );
		}

		global $wpdb;
		$wpdb->update(
			self::dart_boards_table(),
			array(
				'status'     => $status,
				'updated_at' => current_time( 'mysql' ),
			),
			array( 'id' => $board_id ),
			array( '%s', '%s' ),
			array( '%d' )
		);

		if ( self::BOARD_EXCLUDED === $status ) {
			$this->record_darts_board_exclusion( $board, '' );
		}

		wp_send_json_success( $this->get_darts_state() );
	}

	public function ajax_darts_exclude_board() {
		$this->verify_request( 'manage_options' );

		$board_id = isset( $_POST['board_id'] ) ? absint( $_POST['board_id'] ) : 0;
		$reason   = isset( $_POST['reason'] ) ? sanitize_text_field( wp_unslash( $_POST['reason'] ) ) : '';

		if ( ! $board_id ) {
			wp_send_json_error( array( 'message' => 'Board was not found.' ), 400 );
		}

		$board = $this->get_dart_board( $board_id );
		if ( ! $board ) {
			wp_send_json_error( array( 'message' => 'Board was not found.' ), 404 );
		}

		if ( self::BOARD_RUNNING === $board->status ) {
			wp_send_json_error( array( 'message' => 'End or transfer the running session before excluding this board.' ), 400 );
		}

		global $wpdb;
		$wpdb->update(
			self::dart_boards_table(),
			array(
				'status'     => self::BOARD_EXCLUDED,
				'updated_at' => current_time( 'mysql' ),
			),
			array( 'id' => $board_id ),
			array( '%s', '%s' ),
			array( '%d' )
		);

		$board->status = self::BOARD_EXCLUDED;
		$this->record_darts_board_exclusion( $board, $reason );

		wp_send_json_success( $this->get_darts_state() );
	}

	public function ajax_darts_restore_board() {
		$this->verify_request( 'manage_options' );

		$board_id = isset( $_POST['board_id'] ) ? absint( $_POST['board_id'] ) : 0;
		if ( ! $board_id ) {
			wp_send_json_error( array( 'message' => 'Board was not found.' ), 400 );
		}

		$board = $this->get_dart_board( $board_id );
		if ( ! $board ) {
			wp_send_json_error( array( 'message' => 'Board was not found.' ), 404 );
		}

		if ( self::BOARD_RUNNING === $board->status ) {
			wp_send_json_error( array( 'message' => 'Running boards cannot be restored.' ), 400 );
		}

		global $wpdb;
		$wpdb->update(
			self::dart_boards_table(),
			array(
				'status'     => self::BOARD_AVAILABLE,
				'updated_at' => current_time( 'mysql' ),
			),
			array( 'id' => $board_id ),
			array( '%s', '%s' ),
			array( '%d' )
		);

		wp_send_json_success( $this->get_darts_state() );
	}

	public function ajax_darts_transfer_board() {
		$this->verify_request( 'manage_options' );

		$source_id = isset( $_POST['source_board_id'] ) ? absint( $_POST['source_board_id'] ) : 0;
		$target_id = isset( $_POST['target_board_id'] ) ? absint( $_POST['target_board_id'] ) : 0;

		if ( ! $source_id || ! $target_id || $source_id === $target_id ) {
			wp_send_json_error( array( 'message' => 'Choose two different boards.' ), 400 );
		}

		global $wpdb;
		$source = $this->get_dart_board( $source_id );
		$target = $this->get_dart_board( $target_id );

		if ( ! $source || ! $target ) {
			wp_send_json_error( array( 'message' => 'Source or destination board was not found.' ), 404 );
		}

		if ( self::BOARD_RUNNING !== $source->status ) {
			wp_send_json_error( array( 'message' => 'Source board is not running.' ), 400 );
		}

		if ( self::BOARD_AVAILABLE !== $target->status ) {
			wp_send_json_error( array( 'message' => 'Destination board must be available.' ), 400 );
		}

		$session = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM ' . self::dart_sessions_table() . ' WHERE board_id = %d AND status = %s ORDER BY started_at DESC LIMIT 1',
				$source_id,
				self::ACTIVE_STATUS
			)
		);

		if ( ! $session ) {
			wp_send_json_error( array( 'message' => 'No running session found for this board.' ), 404 );
		}

		$now = current_time( 'mysql' );
		$wpdb->update(
			self::dart_sessions_table(),
			array(
				'board_id'     => (int) $target->id,
				'board_number' => (int) $target->board_number,
				'board_label'  => $target->label,
				'updated_at'   => $now,
			),
			array( 'id' => (int) $session->id ),
			array( '%d', '%d', '%s', '%s' ),
			array( '%d' )
		);

		$wpdb->update(
			self::dart_players_table(),
			array(
				'board_id'     => (int) $target->id,
				'board_number' => (int) $target->board_number,
				'board_label'  => $target->label,
				'updated_at'   => $now,
			),
			array( 'session_id' => (int) $session->id ),
			array( '%d', '%d', '%s', '%s' ),
			array( '%d' )
		);

		$wpdb->update(
			self::dart_boards_table(),
			array(
				'status'     => self::BOARD_AVAILABLE,
				'updated_at' => $now,
			),
			array( 'id' => $source_id ),
			array( '%s', '%s' ),
			array( '%d' )
		);

		$wpdb->update(
			self::dart_boards_table(),
			array(
				'status'     => self::BOARD_RUNNING,
				'updated_at' => $now,
			),
			array( 'id' => $target_id ),
			array( '%s', '%s' ),
			array( '%d' )
		);

		$this->record_darts_board_transfer( $source, $target );

		wp_send_json_success( $this->get_darts_state() );
	}

	public function ajax_darts_add_guest() {
		$this->verify_request( 'manage_options' );

		$board_id = isset( $_POST['board_id'] ) ? absint( $_POST['board_id'] ) : 0;
		$name     = isset( $_POST['guest_name'] ) ? sanitize_text_field( wp_unslash( $_POST['guest_name'] ) ) : '';
		if ( ! $board_id || '' === $name ) {
			wp_send_json_error( array( 'message' => 'Choose a running board and enter the guest name.' ), 400 );
		}

		global $wpdb;
		$board   = $this->get_dart_board( $board_id );
		$session = $this->get_active_darts_session( $board_id );
		if ( ! $board || self::BOARD_RUNNING !== $board->status || ! $session ) {
			wp_send_json_error( array( 'message' => 'Guests can only be added to a running board.' ), 400 );
		}

		$this->ensure_darts_session_players( $session );
		$this->insert_darts_player( (int) $session->id, $board, $name, current_time( 'mysql' ) );
		$wpdb->update(
			self::dart_sessions_table(),
			array(
				'player_count' => count( $this->get_darts_players( (int) $session->id ) ),
				'updated_at'   => current_time( 'mysql' ),
			),
			array( 'id' => (int) $session->id ),
			array( '%d', '%s' ),
			array( '%d' )
		);

		wp_send_json_success( $this->get_darts_state() );
	}

	public function ajax_darts_transfer_guest() {
		$this->verify_request( 'manage_options' );

		$player_id = isset( $_POST['player_id'] ) ? absint( $_POST['player_id'] ) : 0;
		$target_id = isset( $_POST['target_board_id'] ) ? absint( $_POST['target_board_id'] ) : 0;
		if ( ! $player_id || ! $target_id ) {
			wp_send_json_error( array( 'message' => 'Choose a guest and destination board.' ), 400 );
		}

		global $wpdb;
		$player = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM ' . self::dart_players_table() . ' WHERE id = %d AND status = %s',
				$player_id,
				self::ACTIVE_STATUS
			)
		);
		if ( ! $player || (int) $player->board_id === $target_id ) {
			wp_send_json_error( array( 'message' => 'Choose a valid guest and a different board.' ), 400 );
		}

		$source         = $this->get_dart_board( (int) $player->board_id );
		$target         = $this->get_dart_board( $target_id );
		$source_session = $this->get_active_darts_session( (int) $player->board_id );
		$target_session = $this->get_active_darts_session( $target_id );
		if ( ! $source || ! $target || ! $source_session || ! $target_session || self::BOARD_RUNNING !== $target->status ) {
			wp_send_json_error( array( 'message' => 'The destination must be another running board.' ), 400 );
		}

		$source_players = $this->get_darts_players( (int) $source_session->id );
		if ( count( $source_players ) <= 1 ) {
			wp_send_json_error( array( 'message' => 'Use Board Transfer when moving the last guest.' ), 400 );
		}

		$now = current_time( 'mysql' );
		$wpdb->update(
			self::dart_players_table(),
			array(
				'session_id'   => (int) $target_session->id,
				'board_id'     => (int) $target->id,
				'board_number' => (int) $target->board_number,
				'board_label'  => $target->label,
				'updated_at'   => $now,
			),
			array( 'id' => $player_id ),
			array( '%d', '%d', '%d', '%s', '%s' ),
			array( '%d' )
		);

		foreach ( array( $source_session, $target_session ) as $changed_session ) {
			$wpdb->update(
				self::dart_sessions_table(),
				array(
					'player_count' => count( $this->get_darts_players( (int) $changed_session->id ) ),
					'updated_at'   => $now,
				),
				array( 'id' => (int) $changed_session->id ),
				array( '%d', '%s' ),
				array( '%d' )
			);
		}

		$this->record_activity(
			array(
				'action'      => self::DART_PLAYER_TRANSFERRED_ACTION,
				'table'       => $this->dart_board_as_activity_table( $source ),
				'source'      => $this->dart_board_as_activity_table( $source ),
				'destination' => $this->dart_board_as_activity_table( $target ),
				'guest'       => (object) array(
					'id'         => $player_id,
					'guest_name' => $player->guest_name,
				),
			)
		);

		wp_send_json_success( $this->get_darts_state() );
	}

	public function ajax_darts_search_history() {
		$this->verify_request();
		$query = isset( $_POST['query'] ) ? sanitize_text_field( wp_unslash( $_POST['query'] ) ) : '';
		wp_send_json_success(
			array(
				'history' => $this->get_darts_history( $query ),
				'report'  => $this->get_darts_report( $query ),
			)
		);
	}

	public function ajax_start_table() {
		$this->verify_request();
		$table_id = isset( $_POST['table_id'] ) ? absint( $_POST['table_id'] ) : 0;
		$name     = isset( $_POST['guest_name'] ) ? sanitize_text_field( wp_unslash( $_POST['guest_name'] ) ) : '';

		$this->add_guest( $table_id, $name );
		wp_send_json_success( $this->get_state() );
	}

	public function ajax_add_guest() {
		$this->ajax_start_table();
	}

	public function ajax_end_guest() {
		$this->verify_request();
		$guest_id = isset( $_POST['guest_id'] ) ? absint( $_POST['guest_id'] ) : 0;
		$this->end_guest( $guest_id );
		wp_send_json_success( $this->get_state() );
	}

	public function ajax_toggle_guest_exclusion() {
		$this->verify_request();
		$guest_id = isset( $_POST['guest_id'] ) ? absint( $_POST['guest_id'] ) : 0;

		if ( ! $guest_id ) {
			wp_send_json_error( array( 'message' => 'Guest was not found.' ), 400 );
		}

		global $wpdb;
		$guest = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM ' . self::guests_table() . ' WHERE id = %d AND status IN (%s, %s)',
				$guest_id,
				self::ACTIVE_STATUS,
				self::STOPPED_STATUS
			)
		);

		if ( ! $guest ) {
			wp_send_json_error( array( 'message' => 'Guest was not found on an open table.' ), 404 );
		}

		$is_excluded = (int) $guest->is_excluded ? 0 : 1;
		$amount      = (float) $guest->charged_amount;
		if ( self::STOPPED_STATUS === $guest->status ) {
			$amount = $is_excluded ? 0 : $this->calculate_charge( $guest->started_at, $guest->ended_at );
		}

		$wpdb->update(
			self::guests_table(),
			array(
				'is_excluded'    => $is_excluded,
				'charged_amount' => $amount,
				'updated_at'     => current_time( 'mysql' ),
			),
			array( 'id' => $guest_id ),
			array( '%d', '%f', '%s' ),
			array( '%d' )
		);

		wp_send_json_success( $this->get_state() );
	}

	public function ajax_checkout_table() {
		$this->verify_request();
		$table_id = isset( $_POST['table_id'] ) ? absint( $_POST['table_id'] ) : 0;

		global $wpdb;
		$active_guests = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT id FROM " . self::guests_table() . ' WHERE table_id = %d AND status = %s',
				$table_id,
				self::ACTIVE_STATUS
			)
		);

		foreach ( $active_guests as $guest_id ) {
			$this->end_guest( (int) $guest_id, true );
		}

		$wpdb->update(
			self::guests_table(),
			array(
				'status'     => self::ENDED_STATUS,
				'updated_at' => current_time( 'mysql' ),
			),
			array(
				'table_id' => $table_id,
				'status'   => self::STOPPED_STATUS,
			),
			array( '%s', '%s' ),
			array( '%d', '%s' )
		);

		$wpdb->update(
			self::tables_table(),
			array( 'is_open' => 0 ),
			array( 'id' => $table_id ),
			array( '%d' ),
			array( '%d' )
		);

		wp_send_json_success( $this->get_state() );
	}

	public function ajax_swap_table() {
		$this->verify_request( 'manage_options' );

		$source_id = isset( $_POST['source_table_id'] ) ? absint( $_POST['source_table_id'] ) : 0;
		$target_id = isset( $_POST['target_table_id'] ) ? absint( $_POST['target_table_id'] ) : 0;

		if ( ! $source_id || ! $target_id || $source_id === $target_id ) {
			wp_send_json_error( array( 'message' => 'Choose two different tables.' ), 400 );
		}

		global $wpdb;
		$source = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM ' . self::tables_table() . ' WHERE id = %d AND is_active = 1',
				$source_id
			)
		);
		$target = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM ' . self::tables_table() . ' WHERE id = %d AND is_active = 1',
				$target_id
			)
		);

		if ( ! $source || ! $target ) {
			wp_send_json_error( array( 'message' => 'Table was not found.' ), 404 );
		}

		if ( (int) $source->is_excluded || (int) $target->is_excluded ) {
			wp_send_json_error( array( 'message' => 'Excluded tables cannot be swapped.' ), 400 );
		}

		$source_guest_ids = $this->get_open_guest_ids( $source_id );
		$target_guest_ids = $this->get_open_guest_ids( $target_id );

		if ( ! (int) $source->is_open && empty( $source_guest_ids ) ) {
			wp_send_json_error( array( 'message' => 'Source table is not open.' ), 400 );
		}

		$source_open = (int) $source->is_open;
		$target_open = (int) $target->is_open;

		foreach ( $source_guest_ids as $guest_id ) {
			$this->move_guest_to_table( (int) $guest_id, $target );
		}

		foreach ( $target_guest_ids as $guest_id ) {
			$this->move_guest_to_table( (int) $guest_id, $source );
		}

		$wpdb->update(
			self::tables_table(),
			array( 'is_open' => $target_open ),
			array( 'id' => $source_id ),
			array( '%d' ),
			array( '%d' )
		);
		$wpdb->update(
			self::tables_table(),
			array( 'is_open' => $source_open ),
			array( 'id' => $target_id ),
			array( '%d' ),
			array( '%d' )
		);

		wp_send_json_success( $this->get_state() );
	}

	public function ajax_transfer_player() {
		$this->verify_request( 'manage_options' );

		$guest_id  = isset( $_POST['guest_id'] ) ? absint( $_POST['guest_id'] ) : 0;
		$target_id = isset( $_POST['target_table_id'] ) ? absint( $_POST['target_table_id'] ) : 0;

		if ( ! $guest_id || ! $target_id ) {
			wp_send_json_error( array( 'message' => 'Choose a player and destination table.' ), 400 );
		}

		global $wpdb;
		$guest = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM ' . self::guests_table() . ' WHERE id = %d AND status IN (%s, %s)',
				$guest_id,
				self::ACTIVE_STATUS,
				self::STOPPED_STATUS
			)
		);

		if ( ! $guest ) {
			wp_send_json_error( array( 'message' => 'Player was not found on an open table.' ), 404 );
		}

		if ( (int) $guest->table_id === $target_id ) {
			wp_send_json_error( array( 'message' => 'Choose a different destination table.' ), 400 );
		}

		$source = $this->get_active_table( (int) $guest->table_id );
		$target = $this->get_active_table( $target_id );

		if ( ! $source || ! $target || (int) $source->is_excluded || (int) $target->is_excluded ) {
			wp_send_json_error( array( 'message' => 'Source or destination table is unavailable.' ), 400 );
		}

		$this->move_guest_to_table( $guest_id, $target );
		$this->sync_table_open_state( (int) $source->id );
		$this->sync_table_open_state( (int) $target->id, true );
		$this->record_transfer_activity( self::PLAYER_TRANSFERRED_ACTION, $source, $target, $guest );

		wp_send_json_success( $this->get_state() );
	}

	public function ajax_transfer_table() {
		$this->verify_request( 'manage_options' );

		$source_id = isset( $_POST['source_table_id'] ) ? absint( $_POST['source_table_id'] ) : 0;
		$target_id = isset( $_POST['target_table_id'] ) ? absint( $_POST['target_table_id'] ) : 0;

		if ( ! $source_id || ! $target_id || $source_id === $target_id ) {
			wp_send_json_error( array( 'message' => 'Choose two different tables.' ), 400 );
		}

		$source = $this->get_active_table( $source_id );
		$target = $this->get_active_table( $target_id );

		if ( ! $source || ! $target || (int) $source->is_excluded || (int) $target->is_excluded ) {
			wp_send_json_error( array( 'message' => 'Source or destination table is unavailable.' ), 400 );
		}

		$source_guest_ids = $this->get_open_guest_ids( $source_id );
		if ( ! (int) $source->is_open && empty( $source_guest_ids ) ) {
			wp_send_json_error( array( 'message' => 'Source table is not open.' ), 400 );
		}

		foreach ( $source_guest_ids as $guest_id ) {
			$this->move_guest_to_table( (int) $guest_id, $target );
		}

		$this->sync_table_open_state( $source_id );
		$this->sync_table_open_state( $target_id, true );
		$this->record_transfer_activity( self::TABLE_TRANSFERRED_ACTION, $source, $target );

		wp_send_json_success( $this->get_state() );
	}

	public function ajax_search_history() {
		$this->verify_request();
		$query = isset( $_POST['query'] ) ? sanitize_text_field( wp_unslash( $_POST['query'] ) ) : '';
		wp_send_json_success( array( 'history' => $this->get_history( $query ) ) );
	}

	public function ajax_clear_history() {
		$this->verify_request();

		wp_send_json_error( array( 'message' => 'History records are permanent and cannot be deleted.' ), 403 );
	}

	public function ajax_clear_history_item() {
		$this->verify_request();

		wp_send_json_error( array( 'message' => 'History records are permanent and cannot be deleted.' ), 403 );
	}

	public function ajax_add_table() {
		$this->verify_request( 'manage_options' );

		global $wpdb;
		$used_numbers = $wpdb->get_col( 'SELECT table_number FROM ' . self::tables_table() . ' WHERE is_active = 1 ORDER BY table_number ASC' );
		$used_numbers = array_map( 'intval', $used_numbers );
		$next_number  = 1;

		while ( in_array( $next_number, $used_numbers, true ) ) {
			$next_number++;
		}

		$existing_id = (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT id FROM ' . self::tables_table() . ' WHERE table_number = %d',
				$next_number
			)
		);

		if ( $existing_id ) {
			$wpdb->update(
				self::tables_table(),
				array(
					'label'       => 'Table ' . $next_number,
					'is_active'   => 1,
					'is_open'     => 0,
					'is_excluded' => 0,
				),
				array( 'id' => $existing_id ),
				array( '%s', '%d', '%d', '%d' ),
				array( '%d' )
			);
		} else {
			$wpdb->insert(
				self::tables_table(),
				array(
					'table_number' => $next_number,
					'label'        => 'Table ' . $next_number,
					'is_active'    => 1,
					'is_open'      => 0,
					'is_excluded'  => 0,
					'created_at'   => current_time( 'mysql' ),
				),
				array( '%d', '%s', '%d', '%d', '%d', '%s' )
			);
		}

		wp_send_json_success( $this->get_state() );
	}

	public function ajax_remove_table() {
		$this->verify_request( 'manage_options' );

		$table_id = isset( $_POST['table_id'] ) ? absint( $_POST['table_id'] ) : 0;
		if ( ! $table_id ) {
			wp_send_json_error( array( 'message' => 'Table was not found.' ), 400 );
		}

		global $wpdb;
		$open_guests = (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM ' . self::guests_table() . ' WHERE table_id = %d AND status IN (%s, %s)',
				$table_id,
				self::ACTIVE_STATUS,
				self::STOPPED_STATUS
			)
		);

		if ( $open_guests > 0 ) {
			wp_send_json_error( array( 'message' => 'Close this table before removing it.' ), 400 );
		}

		$is_open = (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT is_open FROM ' . self::tables_table() . ' WHERE id = %d',
				$table_id
			)
		);

		if ( $is_open ) {
			wp_send_json_error( array( 'message' => 'Close this table before removing it.' ), 400 );
		}

		$wpdb->update(
			self::tables_table(),
			array(
				'is_active' => 0,
				'is_open'   => 0,
				'is_excluded' => 0,
			),
			array( 'id' => $table_id ),
			array( '%d', '%d', '%d' ),
			array( '%d' )
		);

		wp_send_json_success( $this->get_state() );
	}

	public function ajax_exclude_table() {
		$this->verify_request( 'manage_options' );

		$table_id = isset( $_POST['table_id'] ) ? absint( $_POST['table_id'] ) : 0;
		$reason   = isset( $_POST['reason'] ) ? sanitize_text_field( wp_unslash( $_POST['reason'] ) ) : '';

		if ( ! $table_id ) {
			wp_send_json_error( array( 'message' => 'Table was not found.' ), 400 );
		}

		global $wpdb;
		$table = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM ' . self::tables_table() . ' WHERE id = %d AND is_active = 1',
				$table_id
			)
		);

		if ( ! $table ) {
			wp_send_json_error( array( 'message' => 'Table was not found.' ), 404 );
		}

		if ( (int) $table->is_open || $this->table_has_open_guests( $table_id ) ) {
			wp_send_json_error( array( 'message' => 'Close this table before excluding it.' ), 400 );
		}

		$wpdb->update(
			self::tables_table(),
			array( 'is_excluded' => 1 ),
			array( 'id' => $table_id ),
			array( '%d' ),
			array( '%d' )
		);

		$this->record_table_exclusion( $table, $reason );
		wp_send_json_success( $this->get_state() );
	}

	public function ajax_restore_table() {
		$this->verify_request( 'manage_options' );

		$table_id = isset( $_POST['table_id'] ) ? absint( $_POST['table_id'] ) : 0;
		if ( ! $table_id ) {
			wp_send_json_error( array( 'message' => 'Table was not found.' ), 400 );
		}

		global $wpdb;
		$wpdb->update(
			self::tables_table(),
			array( 'is_excluded' => 0 ),
			array(
				'id'        => $table_id,
				'is_active' => 1,
			),
			array( '%d' ),
			array( '%d', '%d' )
		);

		wp_send_json_success( $this->get_state() );
	}

	public function ajax_update_rate() {
		$this->verify_request( 'manage_options' );
		update_option( self::RATE_OPTION, self::HOURLY_RATE );
		wp_send_json_success( $this->get_state() );
	}

	private function verify_request( $capability = 'manage_options' ) {
		check_ajax_referer( 'ptt_nonce', 'nonce' );
		if ( ! current_user_can( $capability ) ) {
			wp_send_json_error( array( 'message' => 'Permission denied.' ), 403 );
		}
	}

	private function table_has_open_guests( $table_id ) {
		global $wpdb;

		return (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM ' . self::guests_table() . ' WHERE table_id = %d AND status IN (%s, %s)',
				$table_id,
				self::ACTIVE_STATUS,
				self::STOPPED_STATUS
			)
		) > 0;
	}

	private function get_active_table( $table_id ) {
		global $wpdb;

		return $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM ' . self::tables_table() . ' WHERE id = %d AND is_active = 1',
				$table_id
			)
		);
	}

	private function sync_table_open_state( $table_id, $force_open = false ) {
		global $wpdb;

		$is_open = $force_open || $this->table_has_open_guests( $table_id ) ? 1 : 0;
		$wpdb->update(
			self::tables_table(),
			array( 'is_open' => $is_open ),
			array( 'id' => $table_id ),
			array( '%d' ),
			array( '%d' )
		);
	}

	private function current_audit_user() {
		$user      = wp_get_current_user();
		$user_name = $user && $user->exists() ? ( $user->display_name ? $user->display_name : $user->user_login ) : 'Unknown user';

		return array(
			'id'   => $user && $user->exists() ? (int) $user->ID : 0,
			'name' => $user_name,
		);
	}

	private function record_table_exclusion( $table, $reason ) {
		$this->record_activity(
			array(
				'action' => self::TABLE_EXCLUDED_ACTION,
				'table'  => $table,
				'reason' => $reason,
			)
		);
	}

	private function record_transfer_activity( $action, $source, $destination, $guest = null ) {
		$this->record_activity(
			array(
				'action'      => $action,
				'table'       => $source,
				'source'      => $source,
				'destination' => $destination,
				'guest'       => $guest,
			)
		);
	}

	private function dart_board_as_activity_table( $board ) {
		return (object) array(
			'id'           => (int) $board->id,
			'table_number' => (int) $board->board_number,
			'label'        => $board->label,
		);
	}

	private function record_darts_board_exclusion( $board, $reason ) {
		$this->record_activity(
			array(
				'action' => self::DART_BOARD_EXCLUDED_ACTION,
				'table'  => $this->dart_board_as_activity_table( $board ),
				'reason' => $reason,
			)
		);
	}

	private function record_darts_board_transfer( $source, $destination ) {
		$this->record_activity(
			array(
				'action'      => self::DART_BOARD_TRANSFERRED_ACTION,
				'table'       => $this->dart_board_as_activity_table( $source ),
				'source'      => $this->dart_board_as_activity_table( $source ),
				'destination' => $this->dart_board_as_activity_table( $destination ),
			)
		);
	}

	private function record_activity( $data ) {
		global $wpdb;

		$user        = $this->current_audit_user();
		$table       = isset( $data['table'] ) && $data['table'] ? $data['table'] : null;
		$source      = isset( $data['source'] ) && $data['source'] ? $data['source'] : null;
		$destination = isset( $data['destination'] ) && $data['destination'] ? $data['destination'] : null;
		$guest       = isset( $data['guest'] ) && $data['guest'] ? $data['guest'] : null;

		$wpdb->insert(
			self::activity_table(),
			array(
				'action'                   => isset( $data['action'] ) ? $data['action'] : '',
				'table_id'                 => $table ? (int) $table->id : 0,
				'table_number'             => $table ? (int) $table->table_number : 0,
				'table_label'              => $table ? $table->label : '',
				'source_table_id'          => $source ? (int) $source->id : 0,
				'source_table_number'      => $source ? (int) $source->table_number : 0,
				'source_table_label'       => $source ? $source->label : '',
				'destination_table_id'     => $destination ? (int) $destination->id : 0,
				'destination_table_number' => $destination ? (int) $destination->table_number : 0,
				'destination_table_label'  => $destination ? $destination->label : '',
				'guest_id'                 => $guest ? (int) $guest->id : 0,
				'guest_name'               => $guest ? $guest->guest_name : '',
				'user_id'                  => $user['id'],
				'user_name'                => $user['name'],
				'reason'                   => isset( $data['reason'] ) ? $data['reason'] : '',
				'created_at'               => current_time( 'mysql' ),
			),
			array( '%s', '%d', '%d', '%s', '%d', '%d', '%s', '%d', '%d', '%s', '%d', '%s', '%d', '%s', '%s', '%s' )
		);
	}

	private function add_guest( $table_id, $name ) {
		if ( ! $table_id || '' === $name ) {
			wp_send_json_error( array( 'message' => 'Choose a table and enter a guest name.' ), 400 );
		}

		global $wpdb;
		$table = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM ' . self::tables_table() . ' WHERE id = %d AND is_active = 1 AND is_excluded = 0',
				$table_id
			)
		);

		if ( ! $table ) {
			wp_send_json_error( array( 'message' => 'Table was not found or is excluded.' ), 404 );
		}

		$now = current_time( 'mysql' );
		$wpdb->insert(
			self::guests_table(),
			array(
				'table_id'       => $table->id,
				'table_number'   => $table->table_number,
				'guest_name'     => $name,
				'started_at'     => $now,
				'status'         => self::ACTIVE_STATUS,
				'is_excluded'    => 0,
				'charged_amount' => 0,
				'created_at'     => $now,
				'updated_at'     => $now,
			),
			array( '%d', '%d', '%s', '%s', '%s', '%d', '%f', '%s', '%s' )
		);

		$wpdb->update(
			self::tables_table(),
			array( 'is_open' => 1 ),
			array( 'id' => $table_id ),
			array( '%d' ),
			array( '%d' )
		);
	}

	private function end_guest( $guest_id, $final_checkout = false ) {
		if ( ! $guest_id ) {
			wp_send_json_error( array( 'message' => 'Guest was not found.' ), 400 );
		}

		global $wpdb;
		$guest = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM ' . self::guests_table() . ' WHERE id = %d',
				$guest_id
			)
		);

		if ( ! $guest || self::ACTIVE_STATUS !== $guest->status ) {
			return;
		}

		$now    = current_time( 'mysql' );
		$amount = (int) $guest->is_excluded ? 0 : $this->calculate_charge( $guest->started_at, $now );
		$status = $final_checkout ? self::ENDED_STATUS : self::STOPPED_STATUS;

		$wpdb->update(
			self::guests_table(),
			array(
				'ended_at'       => $now,
				'status'         => $status,
				'charged_amount' => $amount,
				'updated_at'     => $now,
			),
			array( 'id' => $guest_id ),
			array( '%s', '%s', '%f', '%s' ),
			array( '%d' )
		);
	}

	private function get_open_guest_ids( $table_id ) {
		global $wpdb;

		return $wpdb->get_col(
			$wpdb->prepare(
				'SELECT id FROM ' . self::guests_table() . ' WHERE table_id = %d AND status IN (%s, %s)',
				$table_id,
				self::ACTIVE_STATUS,
				self::STOPPED_STATUS
			)
		);
	}

	private function move_guest_to_table( $guest_id, $table ) {
		global $wpdb;

		$wpdb->update(
			self::guests_table(),
			array(
				'table_id'     => (int) $table->id,
				'table_number' => (int) $table->table_number,
				'updated_at'   => current_time( 'mysql' ),
			),
			array( 'id' => $guest_id ),
			array( '%d', '%d', '%s' ),
			array( '%d' )
		);
	}

	private static function seed_dart_boards() {
		global $wpdb;

		$table = self::dart_boards_table();
		$count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
		if ( $count > 0 ) {
			return;
		}

		$now = current_time( 'mysql' );
		for ( $i = 1; $i <= 5; $i++ ) {
			$wpdb->insert(
				$table,
				array(
					'board_number' => $i,
					'label'        => 'Dart Board ' . $i,
					'status'       => self::BOARD_AVAILABLE,
					'is_active'    => 1,
					'created_at'   => $now,
					'updated_at'   => $now,
				),
				array( '%d', '%s', '%s', '%d', '%s', '%s' )
			);
		}
	}

	private function get_dart_board( $board_id ) {
		global $wpdb;

		return $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM ' . self::dart_boards_table() . ' WHERE id = %d AND is_active = 1',
				$board_id
			)
		);
	}

	private function get_darts_state() {
		global $wpdb;

		$wpdb->query(
			$wpdb->prepare(
				'UPDATE ' . self::dart_boards_table() . ' SET status = %s WHERE status NOT IN (%s, %s, %s)',
				self::BOARD_AVAILABLE,
				self::BOARD_AVAILABLE,
				self::BOARD_RUNNING,
				self::BOARD_EXCLUDED
			)
		);

		$boards = $wpdb->get_results( 'SELECT * FROM ' . self::dart_boards_table() . ' WHERE is_active = 1 ORDER BY board_number ASC' );
		$sessions = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM ' . self::dart_sessions_table() . ' WHERE status = %s ORDER BY started_at ASC',
				self::ACTIVE_STATUS
			)
		);

		$active_sessions = array();
		foreach ( $sessions as $session ) {
			$this->ensure_darts_session_players( $session );
			$active_sessions[ $session->board_id ] = $this->format_darts_session( $session );
		}

		$formatted_boards = array();
		foreach ( $boards as $board ) {
			$formatted_boards[] = array(
				'id'            => (int) $board->id,
				'boardNumber'   => (int) $board->board_number,
				'label'         => $board->label,
				'status'        => $board->status,
				'activeSession' => isset( $active_sessions[ $board->id ] ) ? $active_sessions[ $board->id ] : null,
			);
		}

		return array(
			'boards'      => $formatted_boards,
			'history'     => $this->get_darts_history( '' ),
			'report'      => $this->get_darts_report( '' ),
			'hourlyRate'  => self::DARTS_HOURLY_RATE,
			'serverNow'   => current_time( 'mysql' ),
			'serverNowTs' => current_time( 'timestamp' ),
			'canManage'   => current_user_can( 'manage_options' ),
		);
	}

	private function get_darts_history( $query ) {
		$history = array_merge( $this->get_darts_session_history( $query ), $this->get_darts_activity_history( $query ) );

		usort(
			$history,
			function ( $a, $b ) {
				return (int) $b['sortTs'] <=> (int) $a['sortTs'];
			}
		);

		return array_slice( $history, 0, 150 );
	}

	private function get_darts_session_history( $query ) {
		global $wpdb;

		$sql    = 'SELECT * FROM ' . self::dart_sessions_table() . ' WHERE status = %s';
		$params = array( self::COMPLETED_STATUS );

		if ( '' !== $query ) {
			$like = '%' . $wpdb->esc_like( $query ) . '%';
			$sql .= ' AND (board_label LIKE %s OR CAST(board_number AS CHAR) LIKE %s OR CAST(player_count AS CHAR) LIKE %s OR DATE(started_at) LIKE %s OR DATE(ended_at) LIKE %s)';
			$params = array_merge( $params, array( $like, $like, $like, $like, $like ) );
		}

		$sql .= ' ORDER BY ended_at DESC LIMIT 150';
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $params ) );

		return array_map( array( $this, 'format_darts_session' ), $rows );
	}

	private function get_darts_activity_history( $query ) {
		global $wpdb;

		$sql    = 'SELECT * FROM ' . self::activity_table() . ' WHERE action IN (%s, %s, %s)';
		$params = array( self::DART_BOARD_EXCLUDED_ACTION, self::DART_BOARD_TRANSFERRED_ACTION, self::DART_PLAYER_TRANSFERRED_ACTION );

		if ( '' !== $query ) {
			$like   = '%' . $wpdb->esc_like( $query ) . '%';
			$sql   .= ' AND (table_label LIKE %s OR CAST(table_number AS CHAR) LIKE %s OR source_table_label LIKE %s OR CAST(source_table_number AS CHAR) LIKE %s OR destination_table_label LIKE %s OR CAST(destination_table_number AS CHAR) LIKE %s OR guest_name LIKE %s OR user_name LIKE %s OR reason LIKE %s OR DATE(created_at) LIKE %s)';
			$params = array_merge( $params, array( $like, $like, $like, $like, $like, $like, $like, $like, $like, $like ) );
		}

		$sql .= ' ORDER BY created_at DESC LIMIT 150';
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $params ) );

		return array_map( array( $this, 'format_activity' ), $rows );
	}

	private function get_darts_report( $query ) {
		$history = $this->get_darts_session_history( $query );
		$report = array(
			'sessions' => count( $history ),
			'players'  => 0,
			'revenue'  => 0,
			'seconds'  => 0,
		);

		foreach ( $history as $session ) {
			$report['players'] += (int) $session['playerCount'];
			$report['revenue'] += (float) $session['chargedAmount'];
			$report['seconds'] += (int) $session['elapsed'];
		}

		return $report;
	}

	private function format_darts_session( $session ) {
		$end = $session->ended_at ? $session->ended_at : current_time( 'mysql' );
		$players = $this->get_darts_players( (int) $session->id );
		$formatted_players = array_map( array( $this, 'format_darts_player' ), $players );
		$live_amount = 0;
		foreach ( $formatted_players as $player ) {
			$live_amount += (float) $player['liveAmount'];
		}
		if ( empty( $players ) ) {
			$live_amount = self::ACTIVE_STATUS === $session->status
				? $this->calculate_darts_charge( $session->started_at, $end, (int) $session->player_count )
				: (float) $session->charged_amount;
		}

		return array(
			'type'             => 'session',
			'id'               => (int) $session->id,
			'boardId'          => (int) $session->board_id,
			'boardNumber'      => (int) $session->board_number,
			'boardLabel'       => $session->board_label,
			'playerCount'      => (int) $session->player_count,
			'startedAt'        => $session->started_at,
			'endedAt'          => $session->ended_at,
			'startedAtTs'      => $this->mysql_timestamp( $session->started_at ),
			'endedAtTs'        => $session->ended_at ? $this->mysql_timestamp( $session->ended_at ) : null,
			'status'           => $session->status,
			'chargedAmount'    => (float) $session->charged_amount,
			'liveAmount'       => round( $live_amount, 2 ),
			'players'          => $formatted_players,
			'paymentCollected' => (bool) $session->payment_collected,
			'elapsed'          => $this->elapsed_seconds( $session->started_at, $end ),
			'sortTs'           => $this->mysql_timestamp( $end ),
		);
	}

	private function format_darts_player( $player ) {
		$end = $player->ended_at ? $player->ended_at : current_time( 'mysql' );

		return array(
			'id'          => (int) $player->id,
			'guestName'   => $player->guest_name,
			'boardId'     => (int) $player->board_id,
			'sessionId'   => (int) $player->session_id,
			'startedAt'   => $player->started_at,
			'startedAtTs' => $this->mysql_timestamp( $player->started_at ),
			'endedAtTs'   => $player->ended_at ? $this->mysql_timestamp( $player->ended_at ) : null,
			'status'      => $player->status,
			'elapsed'     => $this->elapsed_seconds( $player->started_at, $end ),
			'liveAmount'  => self::ACTIVE_STATUS === $player->status
				? $this->calculate_darts_charge( $player->started_at, $end, 1 )
				: (float) $player->charged_amount,
		);
	}

	private function get_active_darts_session( $board_id ) {
		global $wpdb;

		return $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM ' . self::dart_sessions_table() . ' WHERE board_id = %d AND status = %s ORDER BY started_at DESC LIMIT 1',
				$board_id,
				self::ACTIVE_STATUS
			)
		);
	}

	private function get_darts_players( $session_id ) {
		global $wpdb;

		return $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM ' . self::dart_players_table() . ' WHERE session_id = %d ORDER BY started_at ASC, id ASC',
				$session_id
			)
		);
	}

	private function insert_darts_player( $session_id, $board, $name, $started_at ) {
		global $wpdb;

		$wpdb->insert(
			self::dart_players_table(),
			array(
				'session_id'    => $session_id,
				'board_id'      => (int) $board->id,
				'board_number'  => (int) $board->board_number,
				'board_label'   => $board->label,
				'guest_name'    => $name,
				'started_at'    => $started_at,
				'status'        => self::ACTIVE_STATUS,
				'charged_amount' => 0,
				'created_at'    => current_time( 'mysql' ),
				'updated_at'    => current_time( 'mysql' ),
			),
			array( '%d', '%d', '%d', '%s', '%s', '%s', '%s', '%f', '%s', '%s' )
		);
	}

	private function ensure_darts_session_players( $session ) {
		if ( count( $this->get_darts_players( (int) $session->id ) ) > 0 ) {
			return;
		}

		$board = $this->get_dart_board( (int) $session->board_id );
		if ( ! $board ) {
			return;
		}

		for ( $i = 1; $i <= max( 1, (int) $session->player_count ); $i++ ) {
			$this->insert_darts_player( (int) $session->id, $board, 'Player ' . $i, $session->started_at );
		}
	}

	private function calculate_darts_charge( $started_at, $ended_at, $players ) {
		$seconds = $this->elapsed_seconds( $started_at, $ended_at );
		return round( ( $seconds / HOUR_IN_SECONDS ) * self::DARTS_HOURLY_RATE * max( 1, (int) $players ), 2 );
	}

	private function get_state() {
		global $wpdb;

		$tables = $wpdb->get_results( 'SELECT * FROM ' . self::tables_table() . ' WHERE is_active = 1 ORDER BY table_number ASC' );
		$guests = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM ' . self::guests_table() . ' WHERE status IN (%s, %s) ORDER BY started_at ASC',
				self::ACTIVE_STATUS,
				self::STOPPED_STATUS
			)
		);

		$grouped = array();
		foreach ( $guests as $guest ) {
			$grouped[ $guest->table_id ][] = $this->format_guest( $guest );
		}

		$formatted_tables = array();
		foreach ( $tables as $table ) {
			$table_guests = isset( $grouped[ $table->id ] ) ? $grouped[ $table->id ] : array();
			$formatted_tables[] = array(
				'id'           => (int) $table->id,
				'tableNumber'  => (int) $table->table_number,
				'label'        => $table->label,
				'isOpen'       => (bool) $table->is_open,
				'isExcluded'   => (bool) $table->is_excluded,
				'activeGuests' => $table_guests,
			);
		}

		return array(
			'tables'     => $formatted_tables,
			'history'    => $this->get_history( '' ),
			'hourlyRate' => self::HOURLY_RATE,
			'serverNow'  => current_time( 'mysql' ),
			'serverNowTs' => current_time( 'timestamp' ),
			'canManage'  => current_user_can( 'manage_options' ),
		);
	}

	private function get_history( $query ) {
		global $wpdb;

		$sql    = 'SELECT * FROM ' . self::guests_table() . ' WHERE status = %s';
		$params = array( self::ENDED_STATUS );

		if ( '' !== $query ) {
			$like   = '%' . $wpdb->esc_like( $query ) . '%';
			$sql   .= ' AND (guest_name LIKE %s OR CAST(table_number AS CHAR) LIKE %s OR DATE(started_at) LIKE %s OR DATE(ended_at) LIKE %s)';
			$params = array_merge( $params, array( $like, $like, $like, $like ) );
		}

		$sql .= ' ORDER BY ended_at DESC LIMIT 100';
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $params ) );

		$history = array_map( array( $this, 'format_guest' ), $rows );
		$history = array_merge( $history, $this->get_activity_history( $query ) );

		usort(
			$history,
			function ( $a, $b ) {
				return (int) $b['sortTs'] <=> (int) $a['sortTs'];
			}
		);

		return array_slice( $history, 0, 100 );
	}

	private function format_guest( $guest ) {
		$end = $guest->ended_at ? $guest->ended_at : current_time( 'mysql' );

		return array(
			'type'          => 'session',
			'id'            => (int) $guest->id,
			'tableId'       => (int) $guest->table_id,
			'tableNumber'   => (int) $guest->table_number,
			'guestName'     => $guest->guest_name,
			'startedAt'     => $guest->started_at,
			'endedAt'       => $guest->ended_at,
			'startedAtTs'   => $this->mysql_timestamp( $guest->started_at ),
			'endedAtTs'     => $guest->ended_at ? $this->mysql_timestamp( $guest->ended_at ) : null,
			'status'        => $guest->status,
			'isExcluded'    => (bool) $guest->is_excluded,
			'chargedAmount' => (float) $guest->charged_amount,
			'liveAmount'    => (int) $guest->is_excluded ? 0 : ( self::ACTIVE_STATUS === $guest->status ? $this->calculate_charge( $guest->started_at, $end ) : (float) $guest->charged_amount ),
			'elapsed'       => $this->elapsed_seconds( $guest->started_at, $end ),
			'sortTs'        => $this->mysql_timestamp( $end ),
		);
	}

	private function get_activity_history( $query ) {
		global $wpdb;

		$sql    = 'SELECT * FROM ' . self::activity_table() . ' WHERE action IN (%s, %s, %s)';
		$params = array( self::TABLE_EXCLUDED_ACTION, self::PLAYER_TRANSFERRED_ACTION, self::TABLE_TRANSFERRED_ACTION );

		if ( '' !== $query ) {
			$like   = '%' . $wpdb->esc_like( $query ) . '%';
			$sql   .= ' AND (table_label LIKE %s OR CAST(table_number AS CHAR) LIKE %s OR source_table_label LIKE %s OR CAST(source_table_number AS CHAR) LIKE %s OR destination_table_label LIKE %s OR CAST(destination_table_number AS CHAR) LIKE %s OR guest_name LIKE %s OR user_name LIKE %s OR reason LIKE %s OR DATE(created_at) LIKE %s)';
			$params = array_merge( $params, array( $like, $like, $like, $like, $like, $like, $like, $like, $like, $like ) );
		}

		$sql .= ' ORDER BY created_at DESC LIMIT 100';
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $params ) );

		return array_map( array( $this, 'format_activity' ), $rows );
	}

	private function format_activity( $activity ) {
		return array(
			'type'        => 'audit',
			'id'          => 'audit-' . (int) $activity->id,
			'action'      => $activity->action,
			'tableId'     => (int) $activity->table_id,
			'tableNumber' => (int) $activity->table_number,
			'tableLabel'  => $activity->table_label,
			'sourceTableId' => (int) $activity->source_table_id,
			'sourceTableNumber' => (int) $activity->source_table_number,
			'sourceTableLabel' => $activity->source_table_label,
			'destinationTableId' => (int) $activity->destination_table_id,
			'destinationTableNumber' => (int) $activity->destination_table_number,
			'destinationTableLabel' => $activity->destination_table_label,
			'guestId'     => (int) $activity->guest_id,
			'guestName'   => $activity->guest_name,
			'userId'      => (int) $activity->user_id,
			'userName'    => $activity->user_name,
			'reason'      => $activity->reason,
			'createdAt'   => $activity->created_at,
			'createdAtTs' => $this->mysql_timestamp( $activity->created_at ),
			'sortTs'      => $this->mysql_timestamp( $activity->created_at ),
		);
	}

	private function calculate_charge( $started_at, $ended_at ) {
		$seconds = $this->elapsed_seconds( $started_at, $ended_at );
		$rate    = self::HOURLY_RATE;

		return round( ( $seconds / HOUR_IN_SECONDS ) * $rate, 2 );
	}

	private function mysql_timestamp( $date ) {
		return (int) get_date_from_gmt( get_gmt_from_date( $date ), 'U' );
	}

	private function elapsed_seconds( $started_at, $ended_at ) {
		$start = $this->mysql_timestamp( $started_at );
		$end   = $this->mysql_timestamp( $ended_at );

		return max( 0, $end - $start );
	}

	private static function tables_table() {
		global $wpdb;
		return $wpdb->prefix . 'ptt_tables';
	}

	private static function guests_table() {
		global $wpdb;
		return $wpdb->prefix . 'ptt_guests';
	}

	private static function activity_table() {
		global $wpdb;
		return $wpdb->prefix . 'ptt_activity_log';
	}

	private static function dart_boards_table() {
		global $wpdb;
		return $wpdb->prefix . 'ptt_dart_boards';
	}

	private static function dart_sessions_table() {
		global $wpdb;
		return $wpdb->prefix . 'ptt_dart_sessions';
	}

	private static function dart_players_table() {
		global $wpdb;
		return $wpdb->prefix . 'ptt_dart_players';
	}
}

register_activation_hook( PTT_PLUGIN_FILE, array( 'Pool_Table_Tracker', 'activate' ) );
Pool_Table_Tracker::instance();
