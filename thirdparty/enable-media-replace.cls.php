<?php
/**
 * The Third Party integration with the Enable Media Replace plugin.
 *
 * @since 7.9
 * @package LiteSpeed
 * @subpackage LiteSpeed_Cache\Thirdparty
 */

namespace LiteSpeed\Thirdparty;

use LiteSpeed\Img_Optm;

defined( 'WPINC' ) || exit();

/**
 * Provides compatibility for the Enable Media Replace plugin.
 *
 * When an attachment's file is replaced in-place (same post ID), EMR
 * regenerates the metadata and LiteSpeed correctly tears down the old
 * optimization (files + postmeta). However, the image is not re-queued
 * for optimization within that same request, so it stays unoptimized.
 *
 * This ties into EMR's "upload done" action and re-gathers the replaced
 * attachment on a fresh Img_Optm instance, queueing its sizes as RAW so
 * the normal request/cron flow picks them up. The scan cursor
 * (next_post_id) is left untouched, so no unrelated images are rescanned.
 */
class Enable_Media_Replace {

	/**
	 * Mime types LiteSpeed image optimization supports.
	 *
	 * @since 7.9
	 * @var array
	 */
	private static $supported_mimes = array( 'image/jpeg', 'image/png', 'image/gif' );

	/**
	 * Preload hooks for Enable Media Replace integration.
	 *
	 * @since 7.9
	 * @access public
	 * @return void
	 */
	public static function preload() {
		if ( ! defined( 'EMR_VERSION' ) ) {
			return;
		}

		// Fires last in EMR's ReplaceController::run(), after files/postmeta
		// teardown and after EMR's own thumbnail and metadata regeneration.
		add_action( 'enable-media-replace-upload-done', __CLASS__ . '::requeue_optimization', 20, 3 );
	}

	/**
	 * Re-queue the replaced attachment for image optimization.
	 *
	 * @since 7.9
	 * @access public
	 * @param string $target_url The new file URL.
	 * @param string $source_url The old file URL.
	 * @param int    $post_id    The attachment post ID.
	 * @return void
	 */
	public static function requeue_optimization( $target_url, $source_url, $post_id ) {
		$post_id = (int) $post_id;
		if ( ! $post_id ) {
			return;
		}

		if ( ! in_array( get_post_mime_type( $post_id ), self::$supported_mimes, true ) ) {
			return;
		}

		$meta = wp_get_attachment_metadata( $post_id );
		if ( empty( $meta['file'] ) ) {
			return;
		}

		// Use a fresh Img_Optm instance rather than the shared singleton.
		// LiteSpeed's own gather runs earlier in this same request (via the
		// wp_update_attachment_metadata hook) and populates the singleton's
		// in-memory "already handled" list. The subsequent teardown deletes
		// the backing records/files but cannot clear that in-memory list, so
		// re-gathering on the singleton would skip every size. A fresh
		// instance re-reads the (now empty) tables and queues correctly.
		$img_optm = new Img_Optm();
		$img_optm->wp_update_attachment_metadata( $meta, $post_id );
	}
}