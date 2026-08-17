<?php
defined( 'ABSPATH' ) || exit;

class Shield_Monitor_DB_Cleanup {
	const BATCH_SIZE = 500;

	private $settings;
	private $logger;

	public function __construct( $settings, $logger ) {
		$this->settings = $settings;
		$this->logger   = $logger;
	}

	public function register_hooks() {
		add_action( 'wp_ajax_shield_monitor_db_cleanup', array( $this, 'ajax_cleanup' ) );
		add_action( 'wp_ajax_shield_monitor_db_stats', array( $this, 'ajax_stats' ) );
	}

	public function stats() {
		global $wpdb;

		return array(
			'spam_comments'        => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->comments} WHERE comment_approved = 'spam'" ),
			'unapproved_comments'  => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->comments} WHERE comment_approved = '0'" ),
			'trashed_comments'     => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->comments} WHERE comment_approved = 'trash'" ),
			'trashed_posts'        => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_status = 'trash'" ),
			'post_revisions'       => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'revision'" ),
			'auto_drafts'          => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_status = 'auto-draft'" ),
			'expired_transients'   => (int) $wpdb->get_var( $wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE %s AND option_value < %d",
				$wpdb->esc_like( '_transient_timeout_' ) . '%',
				time()
			) ),
			'orphaned_postmeta'    => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->postmeta} pm LEFT JOIN {$wpdb->posts} p ON pm.post_id = p.ID WHERE p.ID IS NULL" ),
			'orphaned_commentmeta' => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->commentmeta} cm LEFT JOIN {$wpdb->comments} c ON cm.comment_id = c.comment_ID WHERE c.comment_ID IS NULL" ),
			'spam_users'           => $this->count_spam_users(),
		);
	}

	/**
	 * Run one batch of cleanup for a task.
	 * Returns array: [ 'deleted' => int, 'remaining' => int, 'done' => bool ]
	 */
	public function cleanup_batch( $task ) {
		global $wpdb;
		$task    = sanitize_key( $task );
		$batch   = self::BATCH_SIZE;
		$deleted = 0;

		switch ( $task ) {
			case 'spam_comments':
				$ids = $wpdb->get_col( $wpdb->prepare( "SELECT comment_ID FROM {$wpdb->comments} WHERE comment_approved = 'spam' LIMIT %d", $batch ) );
				$deleted = $this->delete_comments_by_ids( $ids );
				break;

			case 'unapproved_comments':
				$ids = $wpdb->get_col( $wpdb->prepare( "SELECT comment_ID FROM {$wpdb->comments} WHERE comment_approved = '0' LIMIT %d", $batch ) );
				$deleted = $this->delete_comments_by_ids( $ids );
				break;

			case 'trashed_comments':
				$ids = $wpdb->get_col( $wpdb->prepare( "SELECT comment_ID FROM {$wpdb->comments} WHERE comment_approved = 'trash' LIMIT %d", $batch ) );
				$deleted = $this->delete_comments_by_ids( $ids );
				break;

			case 'trashed_posts':
				$ids = $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_status = 'trash' LIMIT %d", $batch ) );
				$deleted = $this->delete_posts_by_ids( $ids );
				break;

			case 'post_revisions':
				$ids = $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'revision' LIMIT %d", $batch ) );
				$deleted = $this->delete_posts_by_ids( $ids );
				break;

			case 'auto_drafts':
				$ids = $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_status = 'auto-draft' LIMIT %d", $batch ) );
				$deleted = $this->delete_posts_by_ids( $ids );
				break;

			case 'expired_transients':
				$deleted = $this->delete_expired_transients_batch( $batch );
				break;

			case 'orphaned_postmeta':
				$deleted = (int) $wpdb->query( $wpdb->prepare(
					"DELETE pm FROM {$wpdb->postmeta} pm LEFT JOIN {$wpdb->posts} p ON pm.post_id = p.ID WHERE p.ID IS NULL LIMIT %d",
					$batch
				) );
				break;

			case 'orphaned_commentmeta':
				$deleted = (int) $wpdb->query( $wpdb->prepare(
					"DELETE cm FROM {$wpdb->commentmeta} cm LEFT JOIN {$wpdb->comments} c ON cm.comment_id = c.comment_ID WHERE c.comment_ID IS NULL LIMIT %d",
					$batch
				) );
				break;

			case 'spam_users':
				$deleted = $this->delete_spam_users_batch( $batch );
				break;

			default:
				return new WP_Error( 'invalid_task', __( 'Unknown cleanup task.', 'codewp-shield-monitor' ) );
		}

		// Get remaining count for this task.
		$fresh_stats = $this->stats();
		$remaining   = $fresh_stats[ $task ] ?? 0;
		$done        = $remaining === 0 || $deleted === 0;

		if ( $done && $deleted > 0 ) {
			$this->logger->record( 'db_cleanup', array(
				'task'    => $task,
				'deleted' => $deleted,
				'batch'   => true,
			) );
		}

		return array(
			'deleted'   => $deleted,
			'remaining' => $remaining,
			'done'      => $done,
		);
	}

	// ── Batch helpers ────────────────────────────────────────────────────

	private function delete_comments_by_ids( $ids ) {
		global $wpdb;
		if ( empty( $ids ) ) {
			return 0;
		}
		$ids = array_map( 'absint', $ids );
		$in  = implode( ',', $ids );
		$wpdb->query( "DELETE FROM {$wpdb->commentmeta} WHERE comment_id IN ({$in})" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		return (int) $wpdb->query( "DELETE FROM {$wpdb->comments} WHERE comment_ID IN ({$in})" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	private function delete_posts_by_ids( $ids ) {
		global $wpdb;
		if ( empty( $ids ) ) {
			return 0;
		}
		$ids = array_map( 'absint', $ids );
		$in  = implode( ',', $ids );
		$wpdb->query( "DELETE FROM {$wpdb->postmeta} WHERE post_id IN ({$in})" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		return (int) $wpdb->query( "DELETE FROM {$wpdb->posts} WHERE ID IN ({$in})" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	private function delete_expired_transients_batch( $batch ) {
		global $wpdb;
		$expired = $wpdb->get_col( $wpdb->prepare(
			"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s AND option_value < %d LIMIT %d",
			$wpdb->esc_like( '_transient_timeout_' ) . '%',
			time(),
			$batch
		) );

		if ( empty( $expired ) ) {
			return 0;
		}

		$deleted = 0;
		foreach ( $expired as $timeout_key ) {
			$transient_key = str_replace( '_transient_timeout_', '_transient_', $timeout_key );
			delete_option( $timeout_key );
			delete_option( $transient_key );
			$deleted++;
		}
		return $deleted;
	}

	private function count_spam_users() {
		$subscriber_ids = get_users( array(
			'role'       => 'subscriber',
			'fields'     => 'ID',
			'date_query' => array( array( 'before' => '7 days ago' ) ),
		) );

		if ( empty( $subscriber_ids ) ) {
			return 0;
		}

		$count = 0;
		foreach ( $subscriber_ids as $uid ) {
			if ( count_user_posts( (int) $uid ) === 0 ) {
				$count++;
			}
		}
		return $count;
	}

	private function delete_spam_users_batch( $batch ) {
		if ( ! function_exists( 'wp_delete_user' ) ) {
			require_once ABSPATH . 'wp-admin/includes/user.php';
		}

		$subscriber_ids = get_users( array(
			'role'       => 'subscriber',
			'fields'     => 'ID',
			'number'     => $batch,
			'date_query' => array( array( 'before' => '7 days ago' ) ),
		) );

		$deleted = 0;
		foreach ( $subscriber_ids as $uid ) {
			$uid = (int) $uid;
			if ( count_user_posts( $uid ) === 0 ) {
				wp_delete_user( $uid );
				$deleted++;
			}
		}
		return $deleted;
	}

	// ── AJAX ─────────────────────────────────────────────────────────────

	public function ajax_stats() {
		if ( ! current_user_can( 'manage_options' ) || ! check_ajax_referer( 'shield_monitor_db_cleanup', '_nonce', false ) ) {
			wp_send_json_error( array( 'message' => __( 'Access denied.', 'codewp-shield-monitor' ) ) );
		}
		wp_send_json_success( $this->stats() );
	}

	public function ajax_cleanup() {
		if ( ! current_user_can( 'manage_options' ) || ! check_ajax_referer( 'shield_monitor_db_cleanup', '_nonce', false ) ) {
			wp_send_json_error( array( 'message' => __( 'Access denied.', 'codewp-shield-monitor' ) ) );
		}

		$task   = isset( $_POST['task'] ) ? sanitize_key( wp_unslash( $_POST['task'] ) ) : '';
		$result = $this->cleanup_batch( $task );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		wp_send_json_success( array(
			'deleted'   => $result['deleted'],
			'remaining' => $result['remaining'],
			'done'      => $result['done'],
			'stats'     => $this->stats(),
		) );
	}
}
