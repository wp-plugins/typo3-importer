<?php
/*
Plugin Name: TYPO3 Importer
Plugin URI: http://peimic.com/t/typo3+importer
Description: Import tt_news and tx_comments from TYPO3 into WordPress.
Version: 1.0.2
Author: Michael Cannon
Author URI: http://peimic.com/contact-peimic/
License: GPL2
*/
/*  Copyright 2011  Michael Cannon  (email : michael@peimic.com)
 
	This program is free software; you can redistribute it and/or modify
	it under the terms of the GNU General Public License, version 2, as 
	published by the Free Software Foundation.

	This program is distributed in the hope that it will be useful,
	but WITHOUT ANY WARRANTY; without even the implied warranty of
	MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
	GNU General Public License for more details.

	You should have received a copy of the GNU General Public License
	along with this program; if not, write to the Free Software
	Foundation, Inc., 51 Franklin St, Fifth Floor, Boston, MA  02110-1301  USA
*/

// TYPO3 includes for helping parse typolink tags
include('lib/class.t3lib_div.php');
include('lib/class.t3lib_parsehtml.php');
include('lib/class.t3lib_softrefproc.php');

add_action( 'wp_ajax_typo3_importer', 'typo3_import_ajax_handler' );

function typo3_import_ajax_handler() {
	global $typo3_import;

	check_ajax_referer( 'typo3-import' );
	if ( !current_user_can( 'publish_posts' ) )
		die( __LINE__ );
	if ( empty( $_POST['step'] ) )
		die( __LINE__ );
	define('WP_IMPORTING', true);
	$result = $typo3_import->{ 'step' . ( (int) $_POST['step'] ) }();
	if ( is_wp_error( $result ) )
		echo $result->get_error_message();
	die;
}

if ( !defined('WP_LOAD_IMPORTERS') && !defined( 'DOING_AJAX' ) )
	return;

// Load Importer API
require_once ABSPATH . 'wp-admin/includes/import.php';

if ( !class_exists( 'WP_Importer' ) ) {
	$class_wp_importer = ABSPATH . 'wp-admin/includes/class-wp-importer.php';
	if ( file_exists( $class_wp_importer ) )
		require_once $class_wp_importer;
}


/**
 * TYPO3 Importer
 *
 * @package typo3-importer
 * @subpackage Importer
 */
if ( class_exists( 'WP_Importer' ) ) {
class TYPO3_API_Import extends WP_Importer {

	var $wpdb					= null;
	var $typo3_url				= null;
	var $t3db					= null;
	var $t3db_host				= null;
	var $t3db_name				= null;
	var $t3db_username			= null;
	var $t3db_password			= null;
	var $postmap;
	var $commentmap;
	var $newline_typo3			= "\r\n";
	var $newline_wp				= "\n\n";
	var $inital_lastsync			= '1900-01-01 00:00:00';
	var $featured_image_id		= false;

	// pid > 0 need for excluding versioned tt_news entries
	var $typo3_news_where		= ' AND n.deleted = 0 AND n.pid > 0';
	var $typo3_news_order		= ' ORDER BY n.uid ASC ';
	var $typo3_comments_where	= ' AND c.external_prefix LIKE "tx_ttnews" AND c.deleted = 0 AND c.hidden = 0 AND c.approved = 1';
	var $typo3_comments_order	= ' ORDER BY c.uid ASC ';
	// batch limit to help prevent expiring connection
	var $batch_limit_news		= 5;
	var $batch_limit_comments	= 50;
	var $import_limit			= 0;
	var $import_types			= array( 'news', 'comments' );
	var $post_status_options	= array( 'draft', 'publish', 'pending', 'future', 'private' );

	function header() {
		echo '<div class="wrap">';
		screen_icon();
		echo '<h2>' . __( 'TYPO3 Importer', 'typo3-importer') . '</h2>';
	}

	function footer() {
		echo '</div>';
	}

	function greet() {
		?>
		<div class="narrow">
		<form action="admin.php?import=typo3" method="post">
		<?php wp_nonce_field( 'typo3-import' ) ?>
		<?php if ( get_option( 't3db_host' )
			&& get_option( 'typo3_url' )
			&& get_option( 't3db_name' )
			&& get_option( 't3db_username' )
			&& get_option( 't3db_password' )
			&& get_option( 't3api_step' )
		) : ?>
			<input type="hidden" name="step" value="<?php echo get_option( 't3api_step' ); ?>" />
			<p><?php _e( 'It looks like you attempted to import your TYPO3 posts previously and got interrupted.', 'typo3-importer') ?></p>
			<p class="submit">
				<input type="submit" class="button" value="<?php esc_attr_e( 'Continue previous import', 'typo3-importer') ?>" />
			</p>
			<p class="submitbox"><a href="<?php echo esc_url($_SERVER['PHP_SELF'] . '?import=typo3&amp;step=-1&amp;_wpnonce=' . wp_create_nonce( 'typo3-import' ) . '&amp;_wp_http_referer=' . esc_attr( $_SERVER['REQUEST_URI'] )) ?>" class="deletion submitdelete"><?php _e( 'Cancel &amp; start a new import', 'typo3-importer') ?></a></p>
			<p>
		<?php else : ?>
			<input type="hidden" name="step" value="1" />
			<input type="hidden" name="login" value="true" />
			<p><?php _e( 'Howdy! This importer allows you to connect directly to a TYPO3 website and download all of your tt_news and tx_comments records.', 'typo3-importer') ?></p>

			<h3><?php _e( 'TYPO3 Website Access', 'typo3-importer'); ?></h3>
			<table class="form-table">
				<tr>
					<th scope="row"><label for="typo3_url"><?php _e( 'Website URL', 'typo3-importer') ?></label></th>
					<td><input type="text" name="typo3_url" id="typo3_url" class="regular-text" value="<?php echo get_option( 'typo3_url' ); ?>" /></td>
				</tr>
				<tr>
					<th scope="row"><label for="t3db_host"><?php _e( 'Database Host', 'typo3-importer') ?></label></th>
					<td><input type="text" name="t3db_host" id="t3db_host" class="regular-text" value="<?php echo get_option( 't3db_host' ); ?>" /></td>
				</tr>
				<tr>
					<th scope="row"><label for="t3db_name"><?php _e( 'Database Name', 'typo3-importer') ?></label></th>
					<td><input type="text" name="t3db_name" id="t3db_name" class="regular-text" value="<?php echo get_option( 't3db_name' ); ?>" /></td>
				</tr>
				<tr>
					<th scope="row"><label for="t3db_username"><?php _e( 'Database Username', 'typo3-importer') ?></label></th>
					<td><input type="text" name="t3db_username" id="t3db_username" class="regular-text" value="<?php echo get_option( 't3db_username' ); ?>" /></td>
				</tr>
				<tr>
					<th scope="row"><label for="t3db_password"><?php _e( 'Database Password', 'typo3-importer') ?></label></th>
					<td><input type="password" name="t3db_password" id="t3db_password" class="regular-text" value="<?php echo get_option( 't3db_password' ); ?>" /></td>
				</tr>
			</table>

			<h3><?php _e( 'Import Options', 'typo3-importer'); ?></h3>
			<table class="form-table">
				<tr>
					<th scope="row"><label for="protected_password"><?php _e( 'Protected Post Password', 'typo3-importer') ?></label></th>
					<td><input type="text" name="protected_password" id="protected_password" class="regular-text" value="<?php echo get_option( 't3api_protected_password' ); ?>" /></td>
				</tr>
				<tr>
					<th scope="row"><?php _e( 'Set Post Status as...?', 'typo3-importer') ?></th>
					<td>
					<?php
						$checked_force_post_status	= get_option( 't3api_force_post_status', 'normal' );

						$status_options		=  $this->post_status_options;
						array_unshift( $status_options, 'default' );

						foreach ( $status_options as $status ) {
							$checked	= ( $status == $checked_force_post_status ) ? 'checked="checked"' : '';
					?>
							<input type="radio" name="force_post_status" value="<?php echo $status; ?>" id="force_post_status_<?php echo $status; ?>" class="regular-text" <?php echo $checked; ?> />
							<label for="force_post_status_<?php echo $status; ?>"><?php printf( __( '%s', 'typo3-importer'), ucfirst( $status ) ); ?></label>
					<?php } ?>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="set_featured_image"><?php _e( 'Set Featured Image?', 'typo3-importer') ?></label></th>
					<?php
						$checked_set_featured_image	= get_option( 't3api_set_featured_image', 1 ) ? 'checked="checked"' : '';
					?>
					<td><input type="checkbox" name="set_featured_image" value="1" id="set_featured_image" class="regular-text" <?php echo $checked_set_featured_image; ?> /></td>
				</tr>
				<tr>
					<th scope="row"><label for="approve_comments"><?php _e( 'Approve Non-spam Comments?', 'typo3-importer') ?></label></th>
					<?php
						$checked_approve_comments	= get_option( 't3api_approve_comments', 1 ) ? 'checked="checked"' : '';
					?>
					<td><input type="checkbox" name="approve_comments" value="1" id="approve_comments" class="regular-text" <?php echo $checked_approve_comments; ?> /></td>
				</tr>
			</table>

			<h3><?php _e( 'Testing Options', 'typo3-importer'); ?></h3>
			<table class="form-table">
				<tr>
					<th scope="row"><label for="no_comments_import"><?php _e( "Don't Import Comments", 'typo3-importer') ?></label></th>
					<?php
						$checked_no_comments	= get_option( 't3api_no_comments_import', 0 ) ? 'checked="checked"' : '';
					?>
					<td><input type="checkbox" name="no_comments_import" value="1" id="no_comments_import" class="regular-text" <?php echo $checked_no_comments; ?> /></td>
				</tr>
				<tr>
					<th scope="row"><label for="no_media_import"><?php _e( "Don't Import Media", 'typo3-importer') ?></label></th>
					<?php
						$checked_no_media	= get_option( 't3api_no_media_import', 0 ) ? 'checked="checked"' : '';
					?>
					<td><input type="checkbox" name="no_media_import" value="1" id="no_media_import" class="regular-text" <?php echo $checked_no_media; ?> /></td>
				</tr>
			</table>
			<table class="form-table">
				<tr>
					<th scope="row"><label for="import_limit"><?php _e( 'Import Limit (0 for all)', 'typo3-importer') ?></label></th>
					<td><input type="text" name="import_limit" id="import_limit" class="regular-text" value="<?php echo get_option( 't3api_import_limit', 0 ); ?>" /></td>
				</tr>
			</table>

			<h3><?php _e( 'Oops...', 'typo3-importer'); ?></h3>
			<table class="form-table">
				<tr>
					<th scope="row"><label for="force_private_posts"><?php _e( 'Imported Posts to Private, NOW!', 'typo3-importer') ?></label></th>
					<td><input type="checkbox" name="step" value="-5" id="force_private_posts" class="regular-text" /></td>
				</tr>
			</table>

			<h3><?php _e( 'Delete Prior Imports', 'typo3-importer'); ?></h3>
			<p><?php _e( "This will remove ALL post imports with the 't3:tt_news.uid' meta key. Post media and comments will also be deleted." , 'typo3-importer') ?></p>
			<table class="form-table">
				<tr>
					<th scope="row"><label for="delete_import"><?php _e( 'Delete Imported Posts', 'typo3-importer') ?></label></th>
					<td><input type="checkbox" name="step" value="-2" id="delete_import" class="regular-text" /></td>
				</tr>
			</table>
			<p><?php _e( "This will remove ALL comments imports with the 't3:tx_comments' comment_agent key." , 'typo3-importer') ?></p>
			<table class="form-table">
				<tr>
					<th scope="row"><label for="delete_import"><?php _e( 'Delete Imported Comments', 'typo3-importer') ?></label></th>
					<td><input type="checkbox" name="step" value="-3" id="delete_comments" class="regular-text" /></td>
				</tr>
			</table>
			<p><?php _e( "This will remove ALL media without a related post. It's possible for non-imported media to be deleted." , 'typo3-importer') ?></p>
			<table class="form-table">
				<tr>
					<th scope="row"><label for="delete_import"><?php _e( 'Delete Unattached Media', 'typo3-importer') ?></label></th>
					<td><input type="checkbox" name="step" value="-4" id="delete_attachment" class="regular-text" /></td>
				</tr>
			</table>

			<h3><?php _e( 'Check Access and Start Import', 'typo3-importer'); ?></h3>
			<p class="submit">
				<input type="submit" class="button" value="<?php esc_attr_e( 'Connect to TYPO3 and Import', 'typo3-importer') ?>" />
			</p>
			<p><?php _e( "This can take a really long time if you have a lot of news in your TYPO3 system or a lot of comments. Ideally, you should only start this process if you can leave your computer alone while it finishes the import." , 'typo3-importer') ?></p>
			<p><?php _e( '<strong>NOTE:</strong> If the import process is interrupted for <em>any</em> reason, come back to this page and it will continue from where it stopped automatically.', 'typo3-importer') ?></p>
			<noscript>
				<p><?php _e( '<strong>NOTE:</strong> You appear to have JavaScript disabled, so you will need to manually click through each step of this importer. If you enable JavaScript, it will step through automatically.', 'typo3-importer') ?></p>
			</noscript>
			<hr />
			<p>TYPO3 Importer by <a href="mailto:michael@peimic.com">Michael Cannon</a> of <a href="http://peimic.com">Peimic.com</a>.</p>
		<?php endif; ?>
		</form>
		</div>
		<?php
	}

	function get_meta( $type ) {
		$metalist				= $this->typo3_api( 'meta_' . $type );

		if ( is_wp_error( $metalist ) )
			return $metalist;

		$total					= $metalist['total'];

		// all post meta is cached locally
		unset( $metalist );
		update_option( 't3api_total_' . $type, $total );

		echo '<p>' . sprintf( __( 'Meta data for %s has been downloaded, continuing with import.', 'typo3-importer'), $type ) . '</p>';
	}

	function get_data( $type ) {
		$sync_count				= (int) get_option( 't3api_sync_count_' . $type );
		if ( ! $sync_count )
			update_option( 't3api_sync_count_' . $type, 0 );

		echo '<ol style="counter-reset: item ' . $sync_count . '">';

		// Get the batch of items that match up with the meta_$type list
		$itemlist				= $this->typo3_api( 'data_' . $type );

		if ( is_wp_error( $itemlist ) )
			return $itemlist;

		foreach( $itemlist['entries'] as $entry ) {
			switch ( $type ) {
				case 'news':
					$inserted 	= $this->import_post( $entry );
					wp_cache_flush();
					break;
				case 'comments':
					$inserted	= $this->import_comment( $entry );
					clean_comment_cache( $inserted );
					break;
			}

			if ( is_wp_error( $inserted ) )
				return $inserted;

			$sync_count++;
		}

		// how far along in the import
		update_option( 't3api_sync_count_' . $type, $sync_count );

		echo '</ol>';
	}

	function import_comment( $comment_arr ) {
		// Parse this comment into an array and insert
		$comment				= $this->parse_comment( $comment_arr );
		$comment				= wp_filter_comment( $comment );

		// redo comment approval 
		if ( check_comment($comment['comment_author'], $comment['comment_author_email'], $comment['comment_author_url'], $comment['comment_content'], $comment['comment_author_IP'], $comment['comment_agent'], $comment['comment_type']) )
			$approved			= 1;
		else
			$approved			= 0;

		if ( wp_blacklist_check($comment['comment_author'], $comment['comment_author_email'], $comment['comment_author_url'], $comment['comment_content'], $comment['comment_author_IP'], $comment['comment_agent']) )
			$approved			= 'spam';

		// auto approve imported comments
		if ( $this->approve_comments && $approved !== 'spam' ) {
			$approved			= 1;
		}

		$comment['comment_approved']	= $approved;

		// Simple duplicate check
		$dupe					= "
			SELECT comment_ID
			FROM {$this->wpdb->comments}
			WHERE comment_post_ID = '{$comment['comment_post_ID']}'
				AND comment_approved != 'trash'
				AND comment_author = '{$comment['comment_author']}'
				AND comment_author_email = '{$comment['comment_author_email']}'
				AND comment_content = '{$comment['comment_content']}'
			LIMIT 1
		";
		$comment_ID				= $this->wpdb->get_var($dupe);

		echo '<li>';

		if ( ! $comment_ID ) {
			printf( __( 'Imported comment from <strong>%s</strong>', 'typo3-importer'), stripslashes( $comment['comment_author'] ) );
			$inserted			= wp_insert_comment( $comment );
		} else {
			printf( __( 'Comment from <strong>%s</strong> already exists.', 'typo3-importer'), stripslashes( $comment['comment_author'] ) );
			$inserted			= false;
		}

		echo '</li>';
		ob_flush(); flush();

		return $inserted;
	}

	function _normalize_tag( $matches ) {
		return '<' . strtolower( $matches[1] );
	}

	function lookup_author( $author_email, $author ) {
		$post_author      		= email_exists( $author_email );

		if ( $post_author )
			return $post_author;

		$url					= preg_replace( '#^[^@]+@#', 'http://', $author_email );
		$author_arr				= explode( ' ', $author );

		$username_arr			= array();
		foreach ( $author_arr as $key => $value ) {
			// remove all non word characters
			$value				= preg_replace( '#\W#', '', $value );
			$value				= strtolower( $value );
			$username_arr[]		= $value;
		}
		$username				= implode( '.', $username_arr );

		$username_orig			= $username;
		$offset					= 1;
		while ( username_exists( $username ) ) {
			$username			= $username_orig . '.' . $offset;
			$offset++;
		}

		$password				= wp_generate_password();
		$first					= array_shift( $author_arr );
		$last					= implode( ' ', $author_arr );

		$user					= array(
			'user_login'		=> $username,
			'user_email'		=> $author_email,
			'user_url'			=> $url,
			'display_name'		=> $author,
			'user_pass'			=> $password,
			'first_name'		=> $first,
			'last_name'			=> $last,
			'role'				=> 'author',
		);

		$post_author      		= wp_insert_user( $user );

		if ( $post_author ) {
			echo '<p>' . sprintf( __( "%s added as an author." , 'typo3-importer'), $author ) . '</p>';

			return $post_author;
		} else {
			false;
		}
	}

	function import_post( $post ) {
		// lookup or create author for posts, but not comments
		$post_author      		= $this->lookup_author( $post['props']['author_email'], $post['props']['author'] );

		if ( in_array( $post['status'], $this->post_status_options ) ) {
			$post_status		= $post['status'];
		} else {
			$post_status		= 'draft';
		}

		// leave draft's alone to prevent publishing something that had been hidden on TYPO3
		if ( 'default' != $this->force_post_status && 'draft' != $post_status ) {
			$post_status      	= $this->force_post_status;
		}

		$post_password    		= $this->protected_password ? $this->protected_password : '';
		$post_category			= $post['category'];
		$post_date				= $post['datetime'];

		// Cleaning up and linking the title
		$post_title				= isset( $post['title'] ) ? trim( $post['title'] ) : '';
		$post_title				= strip_tags( $post_title ); // Can't have tags in the title in WP
		$post_title				= trim( $post_title );

		// Clean up content
		// TYPO3 stores bodytext usually in psuedo HTML
		$post_content			= $this->_prepare_content( $post['bodytext'] );

		// Handle any tags associated with the post
		$tags_input				= !empty( $post['props']['keywords'] ) ? $post['props']['keywords'] : '';

		// Check if comments are closed on this post
		$comment_status			= $post['props']['comments'];

		// Add excerpt
		$post_excerpt			= !empty( $post['props']['excerpt'] ) ? $post['props']['excerpt'] : '';

		// add slug AKA url
		$post_name				= $post['props']['slug'];

		echo '<li>';
		if ( $post_id = post_exists( $post_title, $post_content, $post_date ) ) {
			printf( __( 'Post <strong>%s</strong> already exists.', 'typo3-importer'), stripslashes( $post_title ) );
		} else {
			printf( __( 'Imported post <strong>%s</strong>', 'typo3-importer'), stripslashes( $post_title ) );
			$postdata			= compact( 'post_author', 'post_date', 'post_content', 'post_title', 'post_status', 'post_password', 'tags_input', 'comment_status', 'post_excerpt', 'post_category', 'post_name' );
			// @ref http://codex.wordpress.org/Function_Reference/wp_insert_post
			$post_id			= wp_insert_post( $postdata, true );

			if ( is_wp_error( $post_id ) ) {
				if ( 'empty_content' == $post_id->getErrorCode() )
					return; // Silent skip on "empty" posts
				return $post_id;
			}
			if ( !$post_id ) {
				_e( 'Couldn&#8217;t get post ID (creating post failed!)', 'typo3-importer');
				echo '</li>';
				return new WP_Error( 'insert_post_failed', __( 'Failed to create post.', 'typo3-importer') );
			}

			$this->featured_image_id	= false;

			// replace original external images with internal
			$this->_typo3_replace_images( $post_id );

			// Handle all the metadata for this post
			$this->insert_postmeta( $post_id, $post );

			if ( $this->set_featured_image && $this->featured_image_id ) {
				update_post_meta( $post_id, "_thumbnail_id", $this->featured_image_id );
			}
		}
		echo '</li>';
		ob_flush(); flush();
	}

	function insert_postmeta( $post_id, $post ) {
		// Need the original TYPO3 id for comments
		add_post_meta( $post_id, 't3:tt_news.uid', $post['itemid'] );

		foreach ( $post['props'] as $prop => $value ) {
			if ( ! empty( $post['props'][$prop] ) ) {
				$add_post_meta	= false;

				switch ( $prop ) {
					case 'slug':
						// save the permalink on TYPO3 in case we want to link back or something
						add_post_meta( $post_id, 't3:tt_news.url', $value );
						break;

					case 'excerpt':
						add_post_meta( $post_id, 'thesis_description', $value );
						break;

					case 'keywords':
						add_post_meta( $post_id, 'thesis_keywords', $value );
						break;

					case 'image':
						$this->insert_postimages( $post_id, $value, $post['props']['imagecaption'] );
						break;

					case 'imagecaption':
						break;

					case 'news_files':
						$this->_typo3_append_files( $post_id, $value );
						break;

					case 'links':
						// not possible to do blogroll link inserts, so format and append
						$this->_typo3_append_links( $post_id, $value );
						break;

					default:
						$add_post_meta	= true;
						break;
				}

				if ( $add_post_meta ) {
					add_post_meta( $post_id, 't3:tt_news.' . $prop, $value );
				}
			}
		}
	}

	function _typo3_append_files($post_id, $files) {
		if ( $this->no_media_import )
			return;

		$post					= get_post( $post_id );
		$post_content			= $post->post_content;

		// create header
		$new_content			= __( '<h3>Related Files</h3>' , 'typo3-importer');
		// then create ul/li list of links
		$new_content			.= '<ul>';

		$files_arr				= explode( ",", $files );
		$uploads_dir_typo3		= $this->typo3_url . 'uploads/';

		foreach ( $files_arr as $key => $file ) {
			$original_file_uri	= $uploads_dir_typo3 . 'media/' . $file;
			$file_move			= wp_upload_bits($file, null, file_get_contents($original_file_uri));
			$filename			= $file_move['file'];
			$title				= sanitize_title_with_dashes($file);

			$wp_filetype		= wp_check_filetype($file, null);
			$attachment			= array(
				'post_content'		=> '',
				'post_mime_type'	=> $wp_filetype['type'],
				'post_status'		=> 'inherit',
				'post_title'		=> $title,
			);
			$attach_id			= wp_insert_attachment( $attachment, $filename, $post_id );
			$attach_data		= wp_generate_attachment_metadata( $attach_id, $filename );
			wp_update_attachment_metadata( $attach_id, $attach_data );
			$attach_src			= wp_get_attachment_url( $attach_id );

			// link title is either link base or some title text
			$new_content		.= '<li>';
			$new_content		.= '<a href="' . $attach_src . '" title="' . $title . '">' . $title . '</a>';
			$new_content		.= '</li>';
		}

		$new_content			.= '</ul>';
		$post_content			.= $new_content;

		$post					= array(
			'ID'			=> $post_id,
			'post_content'	=> $post_content
		);
	 
		wp_update_post( $post );
	}

	function _typo3_append_links( $post_id, $links ) {
		$post					= get_post( $post_id );
		$post_content			= $post->post_content;

		// create header
		$new_content			= __( '<h3>Related Links</h3>' , 'typo3-importer');
		$new_content			.= '<ul>';

		// then create ul/li list of links
		// link title is either link base or some title text
		$links					= $this->_typo3_api_parse_typolinks( $links );
		$links_arr				= explode( $this->newline_typo3, $links );

		foreach ( $links_arr as $link ) {
			$new_content		.= '<li>';

			// normally links processed by _typo3_api_parse_typolinks
			if ( ! preg_match( '#^http#i', $link ) ) {
				$new_content	.= $link;
			} else {
				$new_content	.= '<a href="' . $link . '">' . $link . '</a>';
			}
			$new_content		.= '</li>';
		}

		$new_content			.= '</ul>';
		$post_content			.= $new_content;

		$post					= array(
			'ID'			=> $post_id,
			'post_content'	=> $post_content
		);
	 
		wp_update_post( $post );
	}

	// check for images in post_content
	function _typo3_replace_images( $post_id ) {
		if ( $this->no_media_import )
			return;

		$post					= get_post( $post_id );
		$post_content			= $post->post_content;

		if ( ! stristr( $post_content, '<img' ) ) {
			return;
		}

		// pull images out of post_content
		$doc					= new DOMDocument();
		if ( ! $doc->loadHTML( $post_content ) ) {
			return;
		}

		// grab img tag, src, title
		$image_tags				= $doc->getElementsByTagName('img');

		foreach ( $image_tags as $image ) {
			$src				= $image->getAttribute('src');
			$title				= $image->getAttribute('title');
			$alt				= $image->getAttribute('alt');

			// check that src is locally referenced to post 
			if ( preg_match( '#^(https?://[^/]+/)#i', $src, $matches ) ) {
				if ( $matches[0] != $this->typo3_url ) {
					// external src, ignore importing
					continue;
				} else {
					// TODO handle http/https alternatives
					// internal src, prep for importing
					$src		= str_ireplace( $this->typo3_url, '', $src );
				}
			}

			// try to figure out longest amount of the caption to keep
			// push src, title to like images, captions arrays
			if ( $title == $alt 
				|| strlen( $title ) > strlen( $alt ) ) {
				$caption	= $title;
			} elseif ( $alt ) {
				$caption	= $alt;
			} else {
				$caption	= '';
			}

			$file				= basename( $src );
			$original_file_uri	= $this->typo3_url . $src;
			$file_move			= wp_upload_bits($file, null, file_get_contents($original_file_uri));
			$filename			= $file_move['file'];

			$title				= $caption ? $caption : sanitize_title_with_dashes($file);

			$wp_filetype		= wp_check_filetype($file, null);
			$attachment			= array(
				'post_content'		=> '',
				'post_excerpt'		=> $caption,
				'post_mime_type'	=> $wp_filetype['type'],
				'post_status'		=> 'inherit',
				'post_title'		=> $title,
			);
			$attach_id			= wp_insert_attachment( $attachment, $filename, $post_id );

			if ( ! $this->featured_image_id ) {
				$this->featured_image_id	= $attach_id;
			}

			$attach_data		= wp_generate_attachment_metadata( $attach_id, $filename );
			wp_update_attachment_metadata( $attach_id, $attach_data );
			$attach_src			= wp_get_attachment_url( $attach_id );

			// then replace old image src with new in post_content
			$post_content		= str_ireplace( $src, $attach_src, $post_content );
		}

		$post					= array(
			'ID'			=> $post_id,
			'post_content'	=> $post_content
		);
	 
		wp_update_post( $post );
	}

	function insert_postimages($post_id, $images, $captions) {
		if ( $this->no_media_import )
			return;

		$post					= get_post( $post_id );
		$post_content			= $post->post_content;

		// images is a CSV string, convert images to array
		$images					= explode( ",", $images );
		$captions				= explode( "\n", $captions );
		$uploads_dir_typo3		= $this->typo3_url . 'uploads/';

		// cycle through to create new post attachments
		foreach ( $images as $key => $file ) {
			// cp image from A to B
			// @ref http://codex.wordpress.org/Function_Reference/wp_upload_bits
			// $upload = wp_upload_bits($_FILES["field1"]["name"], null, file_get_contents($_FILES["field1"]["tmp_name"]));
			$original_file_uri	= $uploads_dir_typo3 . 'pics/' . $file;
			$file_move			= wp_upload_bits($file, null, file_get_contents($original_file_uri));
			$filename			= $file_move['file'];

			// @ref http://codex.wordpress.org/Function_Reference/wp_insert_attachment
			$caption			= isset($captions[$key]) ? $captions[$key] : '';
			$title				= $caption ? $caption : sanitize_title_with_dashes($file);

			$wp_filetype		= wp_check_filetype($file, null);
			$attachment			= array(
				'post_content'		=> '',
				'post_excerpt'		=> $caption,
				'post_mime_type'	=> $wp_filetype['type'],
				'post_status'		=> 'inherit',
				'post_title'		=> $title,
			);
			$attach_id			= wp_insert_attachment( $attachment, $filename, $post_id );

			if ( ! $this->featured_image_id ) {
				$this->featured_image_id	= $attach_id;
			}

			$attach_data		= wp_generate_attachment_metadata( $attach_id, $filename );
			wp_update_attachment_metadata( $attach_id, $attach_data );
		}

		// insert [gallery] into content after the second paragraph
		// TODO prevent inserting gallery into pre/code sections
		$post_content_array		= explode( $this->newline_wp, $post_content );
		$post_content_arr_size	= sizeof( $post_content_array );
		$new_post_content		= '';
		$gallery_code			= '[gallery]';
		$gallery_inserted		= false;

		for ( $i = 0; $i < $post_content_arr_size; $i++ ) {
			if ( 2 != $i ) {
				$new_post_content	.= $post_content_array[$i] . "{$this->newline_wp}";
			} else {
				$new_post_content	.= "{$gallery_code}{$this->newline_wp}";
				$new_post_content	.= $post_content_array[$i] . "{$this->newline_wp}";
				$gallery_inserted	= true;
			}
		}

		if ( ! $gallery_inserted ) {
			$new_post_content	.= $gallery_code;
		}
		
		$post					= array(
			'ID'			=> $post_id,
			'post_content'	=> $new_post_content
		);
	 
		wp_update_post( $post );
	}

	function parse_comment( $comment ) {
		// Clean up content
		$content				= $this->_prepare_content( $comment['comment_content'] );
		// double-spacing issues
		$content				= str_replace( $this->newline_wp, "\n", $content );

		// Send back the array of details
		return array(
			'comment_post_ID'		=> $this->get_wp_post_ID( $comment['typo3_comment_post_ID'] ),
			'comment_author'		=> $comment['comment_author'],
			'comment_author_url'	=> $comment['comment_author_url'],
			'comment_author_email'	=> $comment['comment_author_email'],
			'comment_content'		=> $content,
			'comment_date'			=> $comment['comment_date'],
			'comment_author_IP'		=> $comment['comment_author_IP'],
			'comment_approved'		=> $comment['comment_approved'],
			'comment_karma'			=> $comment['itemid'], // Need this and next value until rethreading is done
			'comment_agent'			=> 't3:tx_comments',  // Custom type, so we can find it later for processing
			'comment_type'			=> '',	// blank for comment
			'user_ID'				=> email_exists( $comment['comment_author_email'] ),
		);
	}

	// Clean up content
	function _prepare_content( $content ) {
		// convert LINK tags to A
		$content				= $this->_typo3_api_parse_typolinks($content);
		// remove broken link spans
		$content				= $this->_typo3_api_parse_link_spans($content);
		// clean up code samples
		$content				= $this->_typo3_api_parse_pre_code($content);
		// return carriage and newline used as line breaks, consolidate
		$content				= str_replace($this->newline_typo3, $this->newline_wp, $content);
		// lowercase closing tags
		$content				= preg_replace_callback( '|<(/?[A-Z]+)|', array( &$this, '_normalize_tag' ), $content );
		// XHTMLize some tags
		$content				= str_replace( '<br>', '<br />', $content );
		$content				= str_replace( '<hr>', '<hr />', $content );

		// process read more linking
		// t3-cut ==>  <!--more-->
		$content				= preg_replace( '|<t3-cut text="([^"]*)">|is', '<!--more $1-->', $content );
		$content				= str_replace( array( '<t3-cut>', '</t3-cut>' ), array( '<!--more-->', '' ), $content );
		$first					= strpos( $content, '<!--more' );
		$content				= substr( $content, 0, $first + 1 ) . preg_replace( '|<!--more(.*)?-->|sUi', '', substr( $content, $first + 1 ) );

		// database prepping
		$content				= trim( $content );
	
		return $content;
	}

	// Gets the post_ID that a TYPO3 post has been saved as within WP
	function get_wp_post_ID( $post ) {
		if ( empty( $this->postmap[$post] ) )
		 	$this->postmap[$post] = (int) $this->wpdb->get_var( $this->wpdb->prepare( "SELECT post_id FROM {$this->wpdb->postmeta} WHERE meta_key = 't3:tt_news.uid' AND meta_value = %d", $post ) );

		return $this->postmap[$post];
	}

	// Gets the comment_ID that a TYPO3 comment has been saved as within WP
	function get_wp_comment_ID( $comment ) {
		if ( empty( $this->commentmap[$comment] ) )
		 	$this->commentmap[$comment] = $this->wpdb->get_var( $this->wpdb->prepare( "SELECT comment_ID FROM {$this->wpdb->comments} WHERE comment_karma = %d", $comment ) );
		return $this->commentmap[$comment];
	}

	// recreate typo3_api as a meta function to handle tasks
	function typo3_api( $command = null, $args = null ) {
		if ( ! $command ) {
			return false;
		}

		switch ( $command ) {
			case 'meta_news':
				return $this->_typo3_api_meta_news( $args );
				break;

			case 'data_news':
				return $this->_typo3_api_data_news( $args );
				break;

			case 'meta_comments':
				return $this->_typo3_api_meta_comments( $args );
				break;

			case 'data_comments':
				return $this->_typo3_api_data_comments( $args );
				break;

			default:
				return false;
				break;
		}
	}

	// return array of comment entries
	// attempts to grab comments within same time frame as the imported news
	function _typo3_api_data_comments( $args ) {
		$results['entries']		= array();

		$query_items				= "
			SELECT
				REPLACE(c.external_ref,'tt_news_','') typo3_comment_post_ID,
				c.uid itemid,
				FROM_UNIXTIME(c.tstamp) comment_date,
				c.approved comment_approved,
				TRIM(CONCAT(c.firstname, ' ', c.lastname)) comment_author,
				c.email comment_author_email,
				c.homepage comment_author_url,
				c.content comment_content,
				c.remote_addr comment_author_IP
			FROM tx_comments_comments c
			WHERE 1 = 1
		";

		$query_items				.= $this->typo3_comments_where;
		$query_items				.= $this->typo3_comments_order;
		$count					= (int) get_option( 't3api_sync_count_comments' );
		$query_items				.= ' LIMIT ' . $count . ', ' . $this->batch_limit_comments;
		$res_items				= $this->t3db->get_results($query_items, ARRAY_A);
		$results['entries']		= $res_items;

		return $results;
	}

	// news_items returns array of entries
	function _typo3_api_data_news( $args ) {
		$results['entries']		= array();

		$query_items				= "
			SELECT n.uid itemid,
				CASE
					WHEN n.hidden = 1 THEN 'draft'
					WHEN n.datetime > UNIX_TIMESTAMP() THEN 'future'
					ELSE 'publish'
					END status,
				FROM_UNIXTIME(n.datetime) datetime,
				n.title,
				n.bodytext,
				n.keywords,
				n.short excerpt,
				'open' comments,
				n.author,
				n.author_email,
				n.category,
				n.image,
				n.imagecaption,
				n.news_files,
				n.links
			FROM tt_news n
			WHERE 1 = 1
		";
		$query_items				.= $this->typo3_news_where;
		$query_items				.= $this->typo3_news_order;
		$count					= (int) get_option( 't3api_sync_count_news' );
		$query_items				.= ' LIMIT ' . $count . ', ' . $this->batch_limit_news;
		$res_items				= $this->t3db->get_results($query_items, ARRAY_A);

		foreach ( $res_items as $row ) {
			$row['props']['datetime']		= $row['datetime'];

			$row['props']['keywords']		= $row['keywords'];
			unset($row['keywords']);

			$row['props']['comments']		= $row['comments'];
			unset($row['comments']);

			$row['props']['excerpt']		= $row['excerpt'];
			unset($row['excerpt']);

			$row['props']['author']			= $row['author'];
			unset($row['author']);

			$row['props']['author_email']	= $row['author_email'];
			unset($row['author_email']);

			$row['props']['image']			= $row['image'];
			unset($row['image']);

			$row['props']['imagecaption']	= $row['imagecaption'];
			unset($row['imagecaption']);

			$row['props']['news_files']		= $row['news_files'];
			unset($row['news_files']);

			$row['props']['links']			= $row['links'];
			unset($row['links']);

			$row['props']['slug']			= $this->_typo3_api_news_slug($row['itemid']);

			// Link each category to this post
			$catids				= $this->_typo3_api_news_categories($row['itemid']);
			$category_arr		= array();
			foreach ($catids as $cat_name) {
				// typo3 tt_news_cat => wp category taxomony
				$category_arr[]	= wp_create_category($cat_name);
			}

			$row['category']		= $category_arr;
			$results['entries'][]	= $row;
		}

		return $results;
	}

	function _typo3_api_news_slug( $uid ) {
		$url					= '';

		$query_items				= "
			SELECT a.value_alias slug
			FROM tx_realurl_uniqalias a
			WHERE a.tablename = 'tt_news'
				AND a.value_id = {$uid}
			ORDER BY a.tstamp DESC
			LIMIT 1
		";

		$url					= $this->t3db->get_var( $query_items );

		return $url;
	}

	/*
	 * @param	integer	tt_news id to lookup
	 * @return	array	names of tt_news categories
	 */
	function _typo3_api_news_categories($uid) {
		$sql					= sprintf("
			SELECT c.title
			FROM tt_news_cat c
				LEFT JOIN tt_news_cat_mm m ON c.uid = m.uid_foreign
			WHERE m.uid_local = %s
		", $uid);

		$results				= $this->t3db->get_results($sql);

		$cats					= array();

		foreach( $results as $cat ) {
			$cats[]				= $cat->title;
		}

		return $cats;
	}

	// returns array of
	// total - overall total of entries
	// count - number of entries since last sync based upon time
	function _typo3_api_meta_comments( $args ) {
		$results				= array(
			'total'		=> 0,
		);

		$query_total				= "SELECT COUNT(c.uid) FROM tx_comments_comments c WHERE 1 = 1";
		$query_total				.= $this->typo3_comments_where;
		$results['total']		= $this->t3db->get_var( $query_total );

		if ( $this->import_limit && $this->import_limit < $results['total'] ) {
			$results['total']	= $this->import_limit;
		}

		return $results;
	}

	// returns array of
	// total - overall total of entries
	// count - number of entries since last sync based upon time
	function _typo3_api_meta_news( $args ) {
		$results				= array(
			'total'		=> 0,
		);

		$query_total				= "SELECT COUNT(n.uid) FROM tt_news n WHERE 1 = 1";
		$query_total				.= $this->typo3_news_where;
		$results['total']		= $this->t3db->get_var( $query_total );

		if ( $this->import_limit && $this->import_limit < $results['total'] ) {
			$results['total']	= $this->import_limit;
		}

		return $results;
	}

	function dispatch() {
		global $wpdb;

		if ( empty( $_REQUEST['step'] ) )
			$step				= 0;
		else
			$step				= (int) $_REQUEST['step'];

		$this->wpdb				= $wpdb;
		$this->init();
		$this->header();

		switch ( $step ) {
			case -5 :
				$this->force_private_posts();
				$this->greet();
				break;
			case -4 :
				$this->delete_attachments();
				$this->cleanup();
				$this->greet();
				break;
			case -2 :
				$this->delete_imports();
			case -3 :
				$this->delete_comments();
				$this->cleanup();
				$this->greet();
				break;
			case -1 :
				$this->cleanup();
				// Intentional no break
			case 0 :
				$this->greet();
				break;
			case 1 :
			case 2 :
			case 3 :
			case 4 :
				check_admin_referer( 'typo3-import' );
				$result			= $this->{ 'step' . $step }();
				if ( is_wp_error( $result ) ) {
					$this->throw_error( $result, $step );
				}
				break;
		}

		$this->footer();
	}

	function delete_comments() {
		$comment_count			= 0;

		$query					= "SELECT comment_ID FROM {$this->wpdb->comments} WHERE comment_agent = 't3:tx_comments'";
		$comments				= $this->wpdb->get_results( $query );

		foreach( $comments as $comment ) {
			// returns array of obj->ID
			$comment_id			= $comment->comment_ID;

			wp_delete_comment( $comment_id, true );

			$comment_count++;
		}

		echo '<h3>' . __( 'Comments Deleted', 'typo3-importer') . '</h3>';
		echo '<p>' . sprintf( __( "Successfully removed %s comments." , 'typo3-importer'), number_format( $comment_count ) ) . '</p>';
		echo '<hr />';

		delete_option( 't3api_sync_count_comments' );

		return true;
	}

	function force_private_posts() {
		$post_count				= 0;

		// during botched imports not all postmeta is read successfully
		// pull post ids with typo3_uid as post_meta key
		$posts					= $this->wpdb->get_results( "SELECT post_id FROM {$this->wpdb->postmeta} WHERE meta_key = 't3:tt_news.uid'" );

		foreach( $posts as $post ) {
			// returns array of obj->ID
			$post_id			= $post->post_id;

			// dels post, meta & comments
			// true is force delete

			$post_arr				= array(
				'ID'			=> $post_id,
				'post_status'	=> 'private',
			);
		 
			wp_update_post( $post_arr );

			$post_count++;
		}

		echo '<h3>' . __( 'Prior Imports Forced to Private Status', 'typo3-importer') . '</h3>';
		echo '<p>' . sprintf( __( "Successfully updated %s TYPO3 news imports to 'Private'." , 'typo3-importer'), number_format( $post_count ) ) . '</p>';
		echo '<hr />';

		return true;
	}

	function delete_imports() {
		$post_count				= 0;

		// during botched imports not all postmeta is read successfully
		// pull post ids with typo3_uid as post_meta key
		$posts					= $this->wpdb->get_results( "SELECT post_id FROM {$this->wpdb->postmeta} WHERE meta_key = 't3:tt_news.uid'" );

		foreach( $posts as $post ) {
			// returns array of obj->ID
			$post_id			= $post->post_id;

			// remove media relationships
			$this->delete_attachments( $post_id );

			// dels post, meta & comments
			// true is force delete
			wp_delete_post( $post_id, true );

			$post_count++;
		}

		echo '<h3>' . __( 'Prior Imports Deleted', 'typo3-importer') . '</h3>';
		echo '<p>' . sprintf( __( "Successfully removed %s TYPO3 news and their related media and comments." , 'typo3-importer'), number_format( $post_count ) ) . '</p>';
		echo '<hr />';

		return true;
	}

	function delete_attachments( $post_id = false ) {
		$post_id				= $post_id ? $post_id : 0;
		$query					= "SELECT ID FROM {$this->wpdb->posts} WHERE post_type = 'attachment' AND post_parent = {$post_id}";
		$attachments			= $this->wpdb->get_results( $query );

		$attachment_count		= 0;
		foreach( $attachments as $attachment ) {
			// true is force delete
			wp_delete_attachment( $attachment->ID, true );
			$attachment_count++;
		}

		if ( ! $post_id ) {
			echo '<h3>' . __( 'Attachments Deleted', 'typo3-importer') . '</h3>';
			echo '<p>' . sprintf( __( "Successfully removed %s no-post attachments." , 'typo3-importer'), number_format( $attachment_count ) ) . '</p>';
			echo '<hr />';
		}
	}

	// Technically the first half of step 1, this is separated to allow for AJAX
	// calls. Sets up some variables and options and confirms authentication.
	function check_typo3_access() {
		// Log in to confirm the details are correct
		if ( empty( $this->t3db_host )
			|| empty( $this->typo3_url )
			|| empty( $this->t3db_name )
			|| empty( $this->t3db_username )
			|| empty( $this->t3db_password )
		) {
			?>
			<p><?php _e( 'Please provide your TYPO3 website information.', 'typo3-importer') ?></p>
			<p><a href="<?php echo esc_url($_SERVER['PHP_SELF'] . '?import=typo3&amp;step=0&amp;_wpnonce=' . wp_create_nonce( 'typo3-import' ) . '&amp;_wp_http_referer=' . esc_attr( str_replace( '&step=-1', '', $_SERVER['REQUEST_URI'] ) ) ) ?>"><?php _e( 'Start again', 'typo3-importer') ?></a></p>
			<?php
			return false;
		}
	
		// check for typo3_url validity & reachability
		$verified = $this-> _is_typo3_website( $this->typo3_url );
		if ( ! $verified ) {
			?>
			<p><?php _e( 'TYPO3 website not found. Check your TYPO3 URL and try again.', 'typo3-importer') ?></p>
			<p><a href="<?php echo esc_url($_SERVER['PHP_SELF'] . '?import=typo3&amp;step=-1&amp;_wpnonce=' . wp_create_nonce( 'typo3-import' ) . '&amp;_wp_http_referer=' . esc_attr( str_replace( '&step=1', '', $_SERVER['REQUEST_URI'] ) ) ) ?>"><?php _e( 'Start again', 'typo3-importer') ?></a></p>
			<?php
			return $verified;
		} else {
			update_option( 'typo3_url_verified', 'yes' );
		}

		return true;
	}

	function _is_typo3_website( $url = null ) {
		if ( ! $url ) {
			// no url
			return false;
		}

		// regex url
		if ( filter_var( $url, FILTER_VALIDATE_URL ) ) {
			// pull site's TYPO3 admin url, http://example.com/typo3
			$typo3_url			= preg_replace( '#$#', 'typo3/index.php', $url );

			// check for TYPO3 header code
			$html				= file_get_contents( $typo3_url );

			// look for `<meta name="generator" content="TYPO3`
			// looking for meta doesn't work as TYPO3 throws browser error
			// if exists, return true, else false
			if ( preg_match( '#typo3logo#', $html ) ) {
				return true;
			} else {
				// not typo3 site
				return false;
			}
		} else {
			// bad url
			return false;
		}
	}

	function init() {
		// Get details from form or from DB
		// cycle through what's available to set or get
		$t3db_fields			= array('t3db_host',
									'typo3_url',
									't3db_name',
									't3db_username',
									't3db_password'
								);

		foreach ( $t3db_fields as $key ) {
			if ( isset( $_POST[ $key ] ) && ! empty( $_POST[ $key ] ) ) {
				// clean up URL once
				if ( 'typo3_url' == $key ) {
					// fix / appended to URLs with / already
					$_POST[$key]	= preg_replace('#^https?://[^/]+$#', '$0/',  $_POST[$key]);
				}

				// Store details for later
				$this->$key		= $_POST[$key];
				update_option( $key, $this->$key );
			} else {
				$this->$key		= get_option( $key );
			}
		}

		// _POST['login'] denotes first run

		if ( isset( $_POST['force_post_status'] ) ) {
			$this->force_post_status	= $_POST['force_post_status'];
		} elseif ( $_POST['login'] ) {
			$this->force_post_status	= 'default';
		} else {
			$this->force_post_status	= get_option( 't3api_force_post_status', 'default' );
		}
		update_option( 't3api_force_post_status', $this->force_post_status );

		if ( isset( $_POST['set_featured_image'] ) ) {
			$this->set_featured_image	= $_POST['set_featured_image'];
		} elseif ( $_POST['login'] ) {
			$this->set_featured_image	= 0;
		} else {
			$this->set_featured_image	= get_option( 't3api_set_featured_image', 0 );
		}
		update_option( 't3api_set_featured_image', $this->set_featured_image );

		if ( isset( $_POST['approve_comments'] ) ) {
			$this->approve_comments	= $_POST['approve_comments'];
		} elseif ( $_POST['login'] ) {
			$this->approve_comments	= 0;
		} else {
			$this->approve_comments	= get_option( 't3api_approve_comments', 0 );
		}
		update_option( 't3api_approve_comments', $this->approve_comments );

		if ( isset( $_POST['no_comments_import'] ) ) {
			$this->no_comments_import	= $_POST['no_comments_import'];
		} elseif ( $_POST['login'] ) {
			$this->no_comments_import	= 0;
		} else {
			$this->no_comments_import	= get_option( 't3api_no_comments_import', 0 );
		}
		update_option( 't3api_no_comments_import', $this->no_comments_import );

		if ( isset( $_POST['no_media_import'] ) ) {
			$this->no_media_import	= $_POST['no_media_import'];
		} elseif ( $_POST['login'] ) {
			$this->no_media_import	= 0;
		} else {
			$this->no_media_import	= get_option( 't3api_no_media_import', 0 );
		}
		update_option( 't3api_no_media_import', $this->no_media_import );

		if ( isset( $_POST['protected_password'] ) ) {
			$this->protected_password	= $_POST['protected_password'];
		} elseif ( $_POST['login'] ) {
			$this->protected_password	= null;
		} else {
			$this->protected_password	= get_option( 't3api_protected_password', null );
		}
		update_option( 't3api_protected_password', $this->protected_password );

		if ( isset( $_POST['import_limit'] ) ) {
			$this->import_limit	= (int) $_POST['import_limit'];
		} elseif ( $_POST['login'] ) {
			$this->import_limit	= 0;
		} else {
			$this->import_limit	= get_option( 't3api_import_limit', 0 );
		}
		update_option( 't3api_import_limit', $this->import_limit );

		if ( $this->import_limit && $this->batch_limit_news > $this->import_limit ) {
			$this->batch_limit_news	= $this->import_limit;
		}

		if ( $this->import_limit && $this->batch_limit_comments > $this->import_limit ) {
			$this->batch_limit_comments	= $this->import_limit;
		}
	}

	// Check form inputs and start importing posts
	function step1() {
		$type					= 'news';
		do_action( 'import_start' );
		$this->init();

		set_time_limit( 0 );
		update_option( 't3api_step', 1 );
		
		if ( $_POST['login'] ) {
			// First run (non-AJAX)
			$setup				= $this->check_typo3_access();
			if ( !$setup ) {
				return false;
			} else if ( is_wp_error( $setup ) ) {
				$this->throw_error( $setup, get_option( 't3api_step' ) );
				return false;
			}
		}

		$this->_create_db_client();

		echo '<div id="t3api-status">';
		echo '<h3>' . __( 'Importing News', 'typo3-importer') . '</h3>';
		echo '<p>' . __( 'We&#8217;re downloading and importing your TYPO3 tt_news.', 'typo3-importer') . '</p>';

		$count					= (int) get_option( 't3api_sync_count_' . $type );
		if ( ! $count ) {
			// We haven't downloaded meta yet, so do that first
			$result				= $this->get_meta( $type );
			if ( is_wp_error( $result ) ) {
				$this->throw_error( $result, get_option( 't3api_step' ) );
				return false;
			}
		}

		// Download a batch of $type
		$result					= $this->get_data( $type );
		if ( is_wp_error( $result ) ) {
			if ( 406 == $result->getErrorCode() ) {
				?>
				<p><strong><?php _e( 'Uh oh &ndash; TYPO3 has disconnected us because we made too many requests to their servers too quickly.', 'typo3-importer') ?></strong></p>
				<p><strong><?php _e( 'We&#8217;ve saved where you were up to though, so if you come back to this importer in about 30 minutes, you should be able to continue from where you were.', 'typo3-importer') ?></strong></p>
				<?php
				echo $this->next_step( get_option( 't3api_step' ), __( 'Try Again', 'typo3-importer') );
				return false;
			} else {
				$this->throw_error( $result, get_option( 't3api_step' ) );
				return false;
			}
		}

		$count					= (int) get_option( 't3api_sync_count_' . $type );
		$total					= (int) get_option( 't3api_total_' . $type );

		if ( $count < $total ) {
			$limit				= ( 'news' == $type ) ? $this->batch_limit_news : $this->batch_limit_comments;
			$batch				= ceil( $count / $limit );
			$batches			= ( $total > $limit ) ? ceil( $total / $limit ) : 1;
			echo '<p><strong>' . sprintf( __( 'Imported %s batch %d of %d', 'typo3-importer'), $type, $batch, $batches ) . '</strong></p>';
		?>
			<form action="admin.php?import=typo3" method="post" id="t3api-auto-repost">
			<?php wp_nonce_field( 'typo3-import' ) ?>
			<input type="hidden" name="step" id="step" value="<?php echo get_option( 't3api_step' ); ?>" />
			<p><input type="submit" class="button" value="<?php esc_attr_e( 'Import the next batch', 'typo3-importer') ?>" /> <span id="auto-message"></span></p>
			</form>
			<?php $this->auto_ajax( 't3api-auto-repost', 'auto-message' ); ?>
		<?php
		} elseif ( $this->no_comments_import ) {
			echo $this->next_step( 4, __( 'Finish Import', 'typo3-importer') );
			$this->auto_submit();
		} else {
			echo '<p>' . __( 'Your posts have all been imported, but wait &#8211; there&#8217;s more! Now we need to download &amp; import your comments.', 'typo3-importer') . '</p>';
			echo $this->next_step( 2, __( 'Download my comments &raquo;', 'typo3-importer') );
			$this->auto_submit();
		}
		echo '</div>';
	}

	function step4() {
		$this->finish();
	}

	// Download comments to local XML
	function step2() {
		$type					= 'comments';
		do_action( 'import_start' );
		$this->init();

		set_time_limit( 0 );
		update_option( 't3api_step', 2 );

		$this->_create_db_client();

		echo '<div id="t3api-status">';
		echo '<h3>' . __( 'Importing Comments', 'typo3-importer') . '</h3>';
		echo '<p>' . __( 'Now we will download your comments so we can import them (this could take a <strong>long</strong> time if you have lots of comments).', 'typo3-importer') . '</p>';
		ob_flush(); flush();

		$count					= (int) get_option( 't3api_sync_count_' . $type );
		if ( ! $count ) {
			// We haven't downloaded meta yet, so do that first
			$result				= $this->get_meta( $type );
			if ( is_wp_error( $result ) ) {
				$this->throw_error( $result, get_option( 't3api_step' ) );
				return false;
			}
		}

		// Download a batch of actual comments
		$result					= $this->get_data( $type );
		if ( is_wp_error( $result ) ) {
			$this->throw_error( $result, get_option( 't3api_step' ) );
			return false;
		}

		$count					= (int) get_option( 't3api_sync_count_' . $type );
		$total					= (int) get_option( 't3api_total_' . $type );

		if ( $count < $total ) {
			$limit				= ( 'news' == $type ) ? $this->batch_limit_news : $this->batch_limit_comments;
			$batch				= ceil( $count / $limit );
			$batches			= ( $total > $limit ) ? ceil( $total / $limit ) : 1;
			echo '<p><strong>' . sprintf( __( 'Imported %s batch %d of %d', 'typo3-importer'), $type, $batch, $batches ) . '</strong></p>';
		?>
			<form action="admin.php?import=typo3" method="post" id="t3api-auto-repost">
			<?php wp_nonce_field( 'typo3-import' ) ?>
			<input type="hidden" name="step" id="step" value="<?php echo get_option( 't3api_step' ); ?>" />
			<p><input type="submit" class="button" value="<?php esc_attr_e( 'Import the next batch', 'typo3-importer') ?>" /> <span id="auto-message"></span></p>
			</form>
			<?php $this->auto_ajax( 't3api-auto-repost', 'auto-message' ); ?>
		<?php
		} else {
			echo '<p>' . __( 'Your comments have all been imported now, but we still need to rebuild your conversation threads.', 'typo3-importer') . '</p>';
			echo $this->next_step( 3, __( 'Rebuild my comment threads &raquo;', 'typo3-importer') );
			$this->auto_submit();
		}
		echo '</div>';
	}

	// Re-thread comments already in the DB
	function step3() {
		do_action( 'import_start' );
		$this->init();

		set_time_limit( 0 );
		update_option( 't3api_step', 3 );

		// TODO use comment meta to save/pull comment parent
		if ( false ) {
		echo '<div id="t3api-status">';
		echo '<h3>' . __( 'Threading Comments', 'typo3-importer') . '</h3>';
		echo '<p>' . __( 'We are now re-building the threading of your comments (this can also take a while if you have lots of comments).', 'typo3-importer') . '</p>';
		ob_flush(); flush();

		// Only bother adding indexes if they have over $this->batch_limit_comments (arbitrary number)
		$imported_comments = $this->wpdb->get_var( "SELECT COUNT(*) FROM {$this->wpdb->comments} WHERE comment_agent = 't3:tx_comments'" );
		$added_indices = false;
		if ( $this->batch_limit_comments < $imported_comments ) {
			include_once(ABSPATH . 'wp-admin/includes/upgrade.php');
			$added_indices = true;
			add_clean_index( $this->wpdb->comments, 'comment_agent' );
		}

		// Get TYPO3 comments, which haven't been threaded yet, $this->batch_limit_comments at a time and thread them
		while ( $comments = $this->wpdb->get_results( "SELECT comment_ID, comment_agent FROM {$this->wpdb->comments} WHERE comment_agent = 't3:tx_comments' AND comment_agent != '0' LIMIT {$this->batch_limit_comments}", OBJECT ) ) {
			foreach ( $comments as $comment ) {
				$this->wpdb->update( $this->wpdb->comments,
								array( 'comment_parent' => $this->get_wp_comment_ID( $comment->comment_agent ), 'comment_agent' => 't3:tx_comments-done' ),
								array( 'comment_ID' => $comment->comment_ID ) );
			}
			wp_cache_flush();
			$this->wpdb->flush();
		}

		// Revert the comments table back to normal and optimize it to reclaim space
		if ( $added_indices ) {
			drop_index( $this->wpdb->comments, 'comment_agent' );
			$this->wpdb->query( "OPTIMIZE TABLE {$this->wpdb->comments}" );
		}

		if ( $imported_comments > 1 )
			echo '<p>' . sprintf( __( "Successfully re-threaded %s comments." , 'typo3-importer'), number_format( $imported_comments ) ) . '</p>';

		} // end if false

		$this->finish();
	}

	function finish() {
		// Clean up database and we're out
		$this->cleanup();
		do_action( 'import_done', 'typo3' );
		echo '<h3>';
		printf( __( 'All done. <a href="%s">Have fun!</a>', 'typo3-importer'), get_option( 'home' ) );
		echo '</h3>';
		echo '</div>';
	}

	// Output an error message with a button to try again.
	function throw_error( $error, $step ) {
		echo '<p><strong>' . $error->get_error_message() . '</strong></p>';
		echo $this->next_step( $step, __( 'Try Again', 'typo3-importer') );
	}

	// Returns the HTML for a link to the next page
	function next_step( $next_step, $label, $id = 't3api-next-form' ) {
		$str  = '<form action="admin.php?import=typo3" method="post" id="' . $id . '">';
		$str .= wp_nonce_field( 'typo3-import', '_wpnonce', true, false );
		$str .= wp_referer_field( false );
		$str .= '<input type="hidden" name="step" id="step" value="' . esc_attr($next_step) . '" />';
		$str .= '<p><input type="submit" class="button" value="' . esc_attr( $label ) . '" /> <span id="auto-message"></span></p>';
		$str .= '</form>';

		return $str;
	}

	// Automatically submit the specified form after $seconds
	// Include a friendly countdown in the element with id=$msg
	function auto_submit( $id = 't3api-next-form', $msg = 'auto-message', $seconds = 5 ) {
		?><script type="text/javascript">
			next_counter = <?php echo $seconds ?>;
			jQuery(document).ready(function(){
				t3api_msg();
			});

			function t3api_msg() {
				str = '<?php echo esc_js( _e( "Continuing in %d" , 'typo3-importer') ); ?>';
				jQuery( '#<?php echo $msg ?>' ).html( str.replace( /%d/, next_counter ) );
				if ( next_counter <= 0 ) {
					if ( jQuery( '#<?php echo $id ?>' ).length ) {
						jQuery( "#<?php echo $id ?> input[type='submit']" ).hide();
						str = '<?php echo esc_js( __( "Continuing" , 'typo3-importer') ); ?> <img src="<?php echo esc_url( admin_url( 'images/wpspin_light.gif' ) ); ?>" alt="" id="processing" align="top" />';
						jQuery( '#<?php echo $msg ?>' ).html( str );
						jQuery( '#<?php echo $id ?>' ).submit();
						return;
					}
				}
				next_counter = next_counter - 1;
				setTimeout('t3api_msg()', 1000);
			}
		</script><?php
	}

	// Automatically submit the form with #id to continue the process
	// Hide any submit buttons to avoid people clicking them
	// Display a countdown in the element indicated by $msg for "Continuing in x"
	function auto_ajax( $id = 't3api-next-form', $msg = 'auto-message', $seconds = 5 ) {
		?><script type="text/javascript">
			next_counter = <?php echo $seconds ?>;
			jQuery(document).ready(function(){
				t3api_msg();
			});

			function t3api_msg() {
				str = '<?php echo esc_js( __( "Continuing in %d" , 'typo3-importer') ); ?>';
				jQuery( '#<?php echo $msg ?>' ).html( str.replace( /%d/, next_counter ) );
				if ( next_counter <= 0 ) {
					if ( jQuery( '#<?php echo $id ?>' ).length ) {
						jQuery( "#<?php echo $id ?> input[type='submit']" ).hide();
						jQuery.ajaxSetup({'timeout':3600000});
						str = '<?php echo esc_js( __( "Processing next batch." , 'typo3-importer') ); ?> <img src="<?php echo esc_url( admin_url( 'images/wpspin_light.gif' ) ); ?>" alt="" id="processing" align="top" />';
						jQuery( '#<?php echo $msg ?>' ).html( str );
						jQuery('#t3api-status').load(ajaxurl, {'action':'typo3_importer',
																'import':'typo3',
																'step':jQuery('#step').val(),
																'_wpnonce':'<?php echo wp_create_nonce( 'typo3-import' ) ?>',
																'_wp_http_referer':'<?php echo $_SERVER['REQUEST_URI'] ?>'});
						return;
					}
				}
				next_counter = next_counter - 1;
				setTimeout('t3api_msg()', 1000);
			}
		</script><?php
	}

	// Remove all options used during import process and
	// set wp_comments entries back to "normal" values
	function cleanup() {
		delete_option( 't3api_step' );
		delete_option( 'typo3_url_verified' );

		foreach ( $this->import_types as $type ) {
			delete_option( 't3api_sync_count_' . $type );
			delete_option( 't3api_total_' . $type );
		}

		do_action( 'import_end' );
	}

	// t3db is the database connection
	// for TYPO3 there's no API
	// only database and url requests
	// should be used for db connection and establishing valid website url
	function _create_db_client() {
		if ( null === $this->wpdb ) {
			global $wpdb;
			$this->wpdb			= $wpdb;
		}

		if ( $this->t3db ) return;

		$this->t3db_host		= get_option( 't3db_host' );
		$this->t3db_name		= get_option( 't3db_name' );
		$this->t3db_username	= get_option( 't3db_username' );
		$this->t3db_password	= get_option( 't3db_password' );

		$this->t3db				= new wpdb($this->t3db_username, $this->t3db_password, $this->t3db_name, $this->t3db_host);
	}

	function filter_plugin_actions($links, $file) {
	   $importer_link = '<a href="admin.php?import=typo3">' . __('Import', 'typo3-importer') . '</a>';
	   array_unshift( $links, $importer_link ); // before other links

	   return $links;
	}

	// remove TYPO3's broken link span code
	function _typo3_api_parse_link_spans($bodytext) {
		$parsehtml = t3lib_div::makeInstance('t3lib_parsehtml');
		$span_tags = $parsehtml->splitTags('span', $bodytext);

		foreach($span_tags as $k => $found_value)	{
			if ($k%2) {
				$span_value = preg_replace('/<span[[:space:]]+/i','',substr($found_value,0,-1));

				// remove the red border, yellow backgroun broken link code
				if ( preg_match( '#border: 2px solid red#i', $span_value ) ) {
					$span_tags[$k] = '';
					$span_value = str_ireplace('</span>', '', $span_tags[$k+1]);
					$span_tags[$k+1] = $span_value;
				}
			}
		}

		return implode( '', $span_tags );
	}

	// include t3lib_div, t3lib_softrefproc
	// look for getTypoLinkParts to parse out LINK tags into array
	function _typo3_api_parse_typolinks( $bodytext ) {
		$softrefproc = t3lib_div::makeInstance('t3lib_softrefproc');
		$parsehtml = t3lib_div::makeInstance('t3lib_parsehtml');

		$link_tags = $parsehtml->splitTags('link', $bodytext);

		foreach($link_tags as $k => $found_value)	{
			if ($k%2) {
				$typolink_value = preg_replace('/<LINK[[:space:]]+/i','',substr($found_value,0,-1));
				$t_lP = $softrefproc->getTypoLinkParts($typolink_value);

				switch ( $t_lP['LINK_TYPE'] ) {
					case 'mailto':
						// internal page link, drop link
						$link_tags[$k] = '<a href="mailto:' . $t_lP['url'] . '" target="_blank">';
						$typolink_value = str_ireplace('</link>', '</a>', $link_tags[$k+1]);
						$link_tags[$k+1] = $typolink_value;
						break;

					case 'url':
						// internal page link, drop link
						$link_tags[$k] = '<a href="' . $t_lP['url'] . '">';
						$typolink_value = str_ireplace('</link>', '</a>', $link_tags[$k+1]);
						$link_tags[$k+1] = $typolink_value;
						break;

					// TODO? pull file into post
					case 'file':
					case 'page':
					default:
						// internal page link, drop link
						$link_tags[$k] = '';
						$typolink_value = str_ireplace('</link>', '', $link_tags[$k+1]);
						$link_tags[$k+1] = $typolink_value;
						break;
				}
			}
		}

		$return_links			= implode( '', $link_tags );
		return $return_links;
	}

	// remove <br /> from pre code and replace withnew lines
	function _typo3_api_parse_pre_code($bodytext) {
		$parsehtml				= t3lib_div::makeInstance('t3lib_parsehtml');
		$pre_tags				= $parsehtml->splitTags('pre', $bodytext);

		// silly fix for editor color code parsing
		$match					= '#<br\s?/?'.'>#i';
		foreach($pre_tags as $k => $found_value)	{
			if ( 0 == $k%2 ) {
				$pre_value = preg_replace($match, "\n", $found_value);
				$pre_tags[$k]	= $pre_value;
			}
		}

		return implode( '', $pre_tags );
	}
}

$typo3_import = new TYPO3_API_Import();

register_importer( 'typo3', __( 'TYPO3 Importer', 'typo3-importer'), __( 'Import tt_news and tx_comments from TYPO3.', 'typo3-importer'), array( $typo3_import, 'dispatch' ) );

// TODO fix
// add_filter( 'plugin_action_links_' . plugin_basename(__FILE__), array(&$typo3_import, 'filter_plugin_actions'), 10, 2 );

} // class_exists( 'WP_Importer' )

function typo3_importer_init() {
    load_plugin_textdomain( 'typo3-importer', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
}

add_action( 'init', 'typo3_importer_init' );
