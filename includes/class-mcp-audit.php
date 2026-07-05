<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Logs every tool call (especially writes) with before/after state.
 * This is the trust feature that differentiates this from a bare API wrapper.
 */
class WC_Ops_MCP_Audit {

	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function table_name() {
		global $wpdb;
		return $wpdb->prefix . 'wc_ops_mcp_log';
	}

	public function maybe_create_table() {
		global $wpdb;
		$table_name      = $this->table_name();
		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table_name} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			tool_name VARCHAR(100) NOT NULL,
			is_dry_run TINYINT(1) NOT NULL DEFAULT 0,
			input_json LONGTEXT NULL,
			before_json LONGTEXT NULL,
			after_json LONGTEXT NULL,
			status VARCHAR(20) NOT NULL DEFAULT 'success',
			error_message TEXT NULL,
			created_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY tool_name (tool_name),
			KEY created_at (created_at)
		) {$charset_collate};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}

	/**
	 * Record a tool call.
	 */
	public function log( $tool_name, $input, $before, $after, $is_dry_run = false, $status = 'success', $error_message = '' ) {
		global $wpdb;

		$wpdb->insert(
			$this->table_name(),
			array(
				'tool_name'     => sanitize_text_field( $tool_name ),
				'is_dry_run'    => $is_dry_run ? 1 : 0,
				'input_json'    => wp_json_encode( $input ),
				'before_json'   => wp_json_encode( $before ),
				'after_json'    => wp_json_encode( $after ),
				'status'        => sanitize_text_field( $status ),
				'error_message' => sanitize_textarea_field( $error_message ),
				'created_at'    => current_time( 'mysql' ),
			),
			array( '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s' )
		);
	}

	/**
	 * Fetch recent log entries for the admin dashboard.
	 */
	public function get_recent( $limit = 50 ) {
		global $wpdb;
		$table_name = $this->table_name();
		$limit      = absint( $limit );

		return $wpdb->get_results(
			"SELECT * FROM {$table_name} ORDER BY created_at DESC LIMIT {$limit}"
		);
	}
}
