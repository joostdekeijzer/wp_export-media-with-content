<?php
/*
 * Plugin Name: Export media with selected content
 * Plugin URI: https://dekeijzer.org/
 * Description: Make sure all relevant media are exported with the selected content.
 * Author: Joost de Keijzer
 * Version: 1.0
 * Author URI: https://dekeijzer.org/
 * Text Domain: export-media-with-content
 */

class dkzrExportMediaWithContent {
	public function __construct() {
		// handle WordPress imports
		// wp-admin/export.php line 237
		add_action('export_filters', array($this, 'wp_export_filter'), 10, 0);

		// wp-admin/export.php line 112
		add_filter('export_args', array($this, 'export_args'), 10, 1);

		// wp-admin/includes/export.php line 40
		add_action('export_wp', array($this, 'export_wp'), 10, 1);
	}

	public function wp_export_filter() { ?>
<p><input type="hidden" name="export-media-with-content" value="0" /><label><input type="checkbox" name="export-media-with-content" value="1" /> <?php esc_html_e( 'Export media with selected content', 'export-media-with-content' ); ?></label></p>
<?php }

	public function export_args( $args ) {
		if ( $_GET['export-media-with-content'] ) {
			$args['export-media-with-content'] = $_GET['export-media-with-content'];
		}
		return $args;
	}

	public function export_wp( $args ) {
		if ( isset( $args['content'], $args['export-media-with-content'] ) && 'all' !== $args['content'] && 'attachment' !== $args['content'] && $args['export-media-with-content'] ) {
			add_filter('query', array($this, 'export_query'), 10, 1);
		}
	}

	public function export_query( $query ) {
		global $wpdb;

		if (
			is_string($query)
			&& 0 === strpos($query, "SELECT ID FROM {$wpdb->posts} ")
		) {
			remove_filter('query', array($this, 'export_query'), 10);

			$attachments = $wpdb->get_results( "SELECT ID, guid FROM {$wpdb->posts} WHERE post_type = 'attachment'", OBJECT_K );
			if ( empty($attachments) ) {
				return $query;
			}

			$ids = array();
			$cache = array();

			$posts = $wpdb->get_col( $query );
			if ( $posts ) {
				$ids = $wpdb->get_col( sprintf( "SELECT meta_value FROM {$wpdb->postmeta} WHERE meta_key = '_thumbnail_id' AND post_id IN(%s)", implode(',', $posts) ) );
			}

			foreach( $wpdb->get_results( sprintf( "SELECT post_id, meta_key, meta_value FROM {$wpdb->postmeta} WHERE meta_key IN('_wp_attached_file', '_wp_attachment_metadata') AND post_id IN(%s)", implode( ',', array_keys( $attachments ) ) ), ARRAY_A ) as $meta ) {
				if ( isset( $attachments[ $meta['post_id'] ] ) ) {
					$attachments[ $meta['post_id'] ]->{$meta['meta_key']} = maybe_unserialize( $meta['meta_value'] );
				}
			}

			foreach ( $wpdb->get_col( str_replace( 'SELECT ID FROM ', 'SELECT post_content FROM ', $query ) . ' AND post_content REGEXP "((wp-image-|wp-att-)[0-9][0-9]*)|\\\[gallery |href=|src="' ) as $text ) {
				// wp-x-ID tags content
				preg_match_all('#(wp-image-|wp-att-)(\d+)#', $text, $matches, PREG_SET_ORDER);
				foreach ($matches as $match) {
					$ids[] = $match[2];
				}

				// [gallery] shortcode
				preg_match_all('#\[gallery\s+.+ids=["\']([\d\s,]*)["\'].*\]#', $text, $matches, PREG_SET_ORDER);
				foreach ($matches as $match) {
					foreach( explode( ',', $match[1] ) as $id ) {
						$ids[] = (int) $id;
					}
				}

				
				preg_match_all('#(href|src)\s*=\s*["\']([^"\']+)["\']#', $text, $matches, PREG_SET_ORDER);
				foreach ($matches as $match) {
					if ( isset( $cache[ $match[2] ] ) ) {
						continue;
					}

					$needle = trim($match[2]);
					if ( 0 === strpos( $needle, '#' ) || 0 === strpos( $needle, 'mailto:') ) {
						continue;
					}

					if ( ! preg_match( '|^([a-zA-Z]+:)?//|', $needle ) ) {
						// relative url
						$needle = $this->fullUrl( $needle );
					}

					foreach ( $attachments as $id => $att ) {
						if ( isset( $att->_wp_attached_file ) && ($hay = $this->fullUrl( $att->_wp_attached_file )) && $hay == $needle ) {
							$cache[ $match[2] ] = $ids[] = $id;
							break;
						}
						if ( isset( $att->_wp_attachment_metadata['file'] ) && ($hay = $this->fullUrl( $att->_wp_attachment_metadata['file'] )) && $hay == $needle ) {
							$cache[ $match[2] ] = $ids[] = $id;
							break;
						}
						if ( isset( $att->_wp_attachment_metadata['file'], $att->_wp_attachment_metadata['sizes'] ) ) {
							$base = trailingslashit( dirname( $att->_wp_attachment_metadata['file'] ) );
							foreach( $att->_wp_attachment_metadata['sizes'] as $size ) {
								if ( ($hay = $this->fullUrl( $base . $size['file'] )) && $hay == $needle ) {
									$cache[ $match[2] ] = $ids[] = $id;
									break 2;
								}
							}
						}
						if ( isset( $att->guid ) && $att->guid == $needle ) {
							$cache[ $match[2] ] = $ids[] = $id;
							break;
						}
					}
				}
			}

			$ids = array_unique( $ids );

			if ( count($ids) > 0 ) {
				$query .= sprintf( " OR {$wpdb->posts}.ID IN (%s) ", implode(',', $ids) );
			}
		}
		return $query;
	}

	protected function fullUrl( $file ) {
		if ( ( $uploads = wp_get_upload_dir() ) && false === $uploads['error'] ) {
			// Check that the upload base exists in the file location.
			if ( 0 === strpos( $file, $uploads['basedir'] ) ) {
				// Replace file location with url location.
				$url = str_replace($uploads['basedir'], $uploads['baseurl'], $file);
			} elseif ( false !== strpos($file, 'wp-content/uploads') ) {
				// Get the directory name relative to the basedir (back compat for pre-2.7 uploads)
				$url = trailingslashit( $uploads['baseurl'] . '/' . _wp_get_attachment_relative_path( $file ) ) . basename( $file );
			} else {
				// It's a newly-uploaded file, therefore $file is relative to the basedir.
				$url = $uploads['baseurl'] . "/$file";
			}
			return $url;
		}
		return false;
	}
}
$dkzrExportMediaWithContent = new dkzrExportMediaWithContent();
