<?php
/*
Plugin Name: TYPO3 Importer
Plugin URI: http://wordpress.org/extend/plugins/typo3-importer/
Description: Import tt_news and tx_comments from TYPO3 into WordPress.
Version: 1.0.3
Author: Michael Cannon
Author URI: http://typo3vagabond.com/contact-typo3vagabond/
License: GPL2

Copyright 2011  Michael Cannon  (email : michael@typo3vagabond.com)

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


// Load dependencies
// TYPO3 includes for helping parse typolink tags
include_once( 'lib/class.t3lib_div.php' );
include_once( 'lib/class.t3lib_parsehtml.php' );
include_once( 'lib/class.t3lib_softrefproc.php' );
require_once( 'class.options.php' );


/**
 * TYPO3 Importer
 *
 * @package typo3-importer
 */
class TYPO3_Importer {
	// var $comments_order	= ' ORDER BY c.uid ASC ';
	// var $comments_where	= ' AND c.external_prefix LIKE "tx_ttnews" AND c.deleted = 0 AND c.hidden = 0';
	var $errors					= array();
	var $menu_id;
	var $newline_typo3			= "\r\n";
	var $newline_wp				= "\n\n";
	var $post_status_options	= array( 'draft', 'publish', 'pending', 'future', 'private' );
	var $t3db					= null;
	var $t3db_host				= null;
	var $t3db_name				= null;
	var $t3db_password			= null;
	var $t3db_username			= null;
	var $typo3_url				= null;
	var $wpdb					= null;

	// Plugin initialization
	function TYPO3_Importer() {

		// Capability check
		if ( !current_user_can( 'manage_options' ) )
			wp_die( __( 'Cheatin&#8217; uh?' , 'typo3-importer') );

		if ( ! function_exists( 'admin_url' ) )
			return false;

		// Load up the localization file if we're using WordPress in a different language
		// Place it in this plugin's "localization" folder and name it "typo3-importer-[value in wp-config].mo"
		load_plugin_textdomain( 'typo3-importer', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );

		add_action( 'admin_menu', array( &$this, 'add_admin_menu' ) );
		add_action( 'admin_enqueue_scripts', array( &$this, 'admin_enqueues' ) );
		add_action( 'wp_ajax_importtypo3news', array( &$this, 'ajax_process_news' ) );
		add_filter( 'plugin_action_links', array( &$this, 'add_plugin_action_links' ), 10, 2 );
		
		$this->options_link		= '<a href="'.get_admin_url().'options-general.php?page=t3i-options">'.__('TYPO3 Import Options', 'typo3-importer').'</a>';
        
		// TODO Make the Settings page link to the link list, and vice versa
		// from broken link
		if ( false ) {
        add_screen_meta_link(
        	'fsi-settings-link',
			__('Go to Settings', 'typo3-importer'),
			admin_url('options-general.php?page=t3i-options'),
			$links_page_hook,
			array('style' => 'font-weight: bold;')
		);
		add_screen_meta_link(
        	'fsi-links-page-link',
			__('Go to Import', 'typo3-importer'),
			admin_url('tools.php?page=typo3-importer'),
			$options_page_hook,
			array('style' => 'font-weight: bold;')
		);
		}
		
		$this->_create_db_client();
		$this->_get_custom_sql();
		$this->no_media_import	= get_t3i_options( 'no_media_import' );
	}


	// Display a Options link on the main Plugins page
	function add_plugin_action_links( $links, $file ) {
		if ( $file == plugin_basename( __FILE__ ) ) {
			array_unshift( $links, $this->options_link );

			$link				= '<a href="'.get_admin_url().'tools.php?page=typo3-importer">'.__('Import', 'typo3-importer').'</a>';
			array_unshift( $links, $link );
		}

		return $links;
	}


	// Register the management page
	function add_admin_menu() {
		$this->menu_id = add_management_page( __( 'TYPO3 Importer', 'typo3-importer' ), __( 'TYPO3 Importer', 'typo3-importer' ), 'manage_options', 'typo3-importer', array(&$this, 'user_interface') );
	}


	// Enqueue the needed Javascript and CSS
	function admin_enqueues( $hook_suffix ) {
		if ( $hook_suffix != $this->menu_id )
			return;

		// WordPress 3.1 vs older version compatibility
		if ( wp_script_is( 'jquery-ui-widget', 'registered' ) )
			wp_enqueue_script( 'jquery-ui-progressbar', plugins_url( 'jquery-ui/jquery.ui.progressbar.min.js', __FILE__ ), array( 'jquery-ui-core', 'jquery-ui-widget' ), '1.8.6' );
		else
			wp_enqueue_script( 'jquery-ui-progressbar', plugins_url( 'jquery-ui/jquery.ui.progressbar.min.1.7.2.js', __FILE__ ), array( 'jquery-ui-core' ), '1.7.2' );

		wp_enqueue_style( 'jquery-ui-t3iposts', plugins_url( 'jquery-ui/redmond/jquery-ui-1.7.2.custom.css', __FILE__ ), array(), '1.7.2' );
	}


	// The user interface plus thumbnail regenerator
	function user_interface() {

		echo <<<EOD
<div id="message" class="updated fade" style="display:none"></div>

<div class="wrap t3iposts">
	<div class="icon32" id="icon-tools"></div>
	<h2>
EOD;
	_e('TYPO3 Importer', 'typo3-importer');
	echo '</h2>';

		// testing helper
		if ( isset( $_REQUEST['importtypo3news'] ) && $_REQUEST['importtypo3news'] ) {
			$this->ajax_process_news();
		}

		// If the button was clicked
		if ( ! empty( $_POST['typo3-importer'] ) || ! empty( $_REQUEST['posts'] ) ) {

			// Form nonce check
			check_admin_referer( 'typo3-importer' );

			// check that TYPO3 login information is valid
			if ( $this->check_typo3_access() ) {
				// Create the list of image IDs
				if ( ! empty( $_REQUEST['posts'] ) ) {
					$posts			= array_map( 'intval', explode( ',', trim( $_REQUEST['posts'], ',' ) ) );
					$count			= count( $posts );
					$posts			= implode( ',', $posts );
				} else {
					$query			= "
						SELECT uid
						FROM tt_news
						WHERE 1 = 1
							{$this->news_custom_where}
						{$this->news_custom_order}
					";

					$limit			= (int) get_t3i_options( 'import_limit' );
					if ( $limit )
						$query		.= ' LIMIT ' . $limit;

					$results		= $this->t3db->get_results( $query );
					$count			= 0;

					// Generate the list of IDs
					$posts			= array();
					foreach ( $results as $post ) {
						$posts[]	= $post->uid;
						$count++;
					}

					if ( ! $count ) {
						echo '	<p>' . _e( 'All done. No further news or comment records to import.', 'typo3-importer' ) . "</p></div>";
						return;
					}

					$posts			= implode( ',', $posts );
				}

				$this->show_status( $count, $posts );
			} else {
				$this->show_errors();
			}
		} else {
			// No button click? Display the form.
			$this->show_greeting();
		}
		
		echo '</div>';
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

		if ( is_null( $this->t3db_host ) ) {
			$this->typo3_url	 	= get_t3i_options( 'typo3_url' );
			$this->t3db_host		= get_t3i_options( 't3db_host' );
			$this->t3db_name		= get_t3i_options( 't3db_name' );
			$this->t3db_username	= get_t3i_options( 't3db_username' );
			$this->t3db_password	= get_t3i_options( 't3db_password' );
		}

		$this->t3db				= new wpdb($this->t3db_username, $this->t3db_password, $this->t3db_name, $this->t3db_host);
	}

	function _get_custom_sql() {
		$this->news_custom_where	= get_t3i_options( 'news_custom_where' );
		$this->news_custom_order	= get_t3i_options( 'news_custom_order' );

		$this->news_to_import		= get_t3i_options( 'news_to_import' );
		if ( '' == $this->news_to_import ) {
			// poll already imported and skip those
			$done_uids			= $this->wpdb->get_col( "SELECT meta_value FROM {$this->wpdb->postmeta} WHERE meta_key = 't3:tt_news.uid'" );

			if ( count( $done_uids ) ) {
				$done_uids		= array_unique( $done_uids );
				$this->news_custom_where	.= " AND tt_news.uid NOT IN ( " . implode( ',', $done_uids ) . " ) ";
			}
		} else {
			$this->news_custom_where	= " AND tt_news.uid IN ( " . $this->news_to_import . " ) ";
		}

		$this->news_to_skip			= get_t3i_options( 'news_to_skip' );
		if ( '' != $this->news_to_skip ) {
			$this->news_custom_where	.= " AND tt_news.uid NOT IN ( " . $this->news_to_skip . " ) ";
		}
	}

	function check_typo3_access() {
		// TODO set checked okay bit via options setter so we don't have to 
		// repeat checks here

		if ( ! $this->typo3_url ) {
			$this->errors[] 			= __( "TYPO3 website URL is missing", 'typo3-importer' );
		}

		if ( ! $this->t3db_host ) {
			$this->errors[] 			= __( "TYPO3 database host is missing", 'typo3-importer' );
		}

		if ( ! $this->t3db_name ) {
			$this->errors[] 			= __( "TYPO3 database name is missing", 'typo3-importer' );
		}

		if ( ! $this->t3db_username ) {
			$this->errors[] 			= __( "TYPO3 database username is missing", 'typo3-importer' );
		}

		if ( ! $this->t3db_password ) {
			$this->errors[] 			= __( "TYPO3 database password is missing", 'typo3-importer' );
		}
	
		// TODO check for DB access

		if ( ! count( $this->errors ) ) {
			return true;
		}

		return false;
	}

	function show_errors() {
		echo '<h3>';
		_e( 'Errors found, see below' );
		echo '</h3>';
		echo '<ul class="error">';
		foreach ( $this->errors as $key => $error ) {
			echo '<li>' . $error . '</li>';
		}
		echo '</ul>';
		echo '<p>' . sprintf( __( 'Please review your %s before proceeding.', 'typo3-importer' ), $this->options_link ) . '</p>';
	}


	function show_status( $count, $posts ) {
		echo '<p>' . __( "Please be patient while news and comment records are processed. This can take a while, up to 2 minutes per individual news record to include comments and related media. Do not navigate away from this page until this script is done or the import will not be completed. You will be notified via this page when the import is completed.", 'typo3-importer' ) . '</p>';

		echo '<p>' . sprintf( __( 'Estimated time required to import is %1$s minutes.', 'typo3-importer' ), ( $count * 2 ) ) . '</p>';

		// TODO add estimated time remaining 

		$text_goback = ( ! empty( $_GET['goback'] ) ) ? sprintf( __( 'To go back to the previous page, <a href="%s">click here</a>.', 'typo3-importer' ), 'javascript:history.go(-1)' ) : '';

		$text_failures = sprintf( __( 'All done! %1$s tt_news records were successfully processed in %2$s seconds and there were %3$s failure(s). To try importing the failed records again, <a href="%4$s">click here</a>. %5$s', 'typo3-importer' ), "' + rt_successes + '", "' + rt_totaltime + '", "' + rt_errors + '", esc_url( wp_nonce_url( admin_url( 'tools.php?page=typo3-importer&goback=1' ), 'typo3-importer' ) . '&posts=' ) . "' + rt_failedlist + '", $text_goback );

		$text_nofailures = sprintf( __( 'All done! %1$s tt_news records were successfully processed in %2$s seconds and there were no failures. %3$s', 'typo3-importer' ), "' + rt_successes + '", "' + rt_totaltime + '", $text_goback );
?>

	<noscript><p><em><?php _e( 'You must enable Javascript in order to proceed!', 'typo3-importer' ) ?></em></p></noscript>

	<div id="t3iposts-bar" style="position:relative;height:25px;">
		<div id="t3iposts-bar-percent" style="position:absolute;left:50%;top:50%;width:300px;margin-left:-150px;height:25px;margin-top:-9px;font-weight:bold;text-align:center;"></div>
	</div>

	<p><input type="button" class="button hide-if-no-js" name="t3iposts-stop" id="t3iposts-stop" value="<?php _e( 'Abort Importing TYPO3 News and Comments', 'typo3-importer' ) ?>" /></p>

	<h3 class="title"><?php _e( 'Debugging Information', 'typo3-importer' ) ?></h3>

	<p>
		<?php printf( __( 'Total news records: %s', 'typo3-importer' ), $count ); ?><br />
		<?php printf( __( 'News Records Imported: %s', 'typo3-importer' ), '<span id="t3iposts-debug-successcount">0</span>' ); ?><br />
		<?php printf( __( 'Import Failures: %s', 'typo3-importer' ), '<span id="t3iposts-debug-failurecount">0</span>' ); ?>
	</p>

	<ol id="t3iposts-debuglist">
		<li style="display:none"></li>
	</ol>

	<script type="text/javascript">
	// <![CDATA[
		jQuery(document).ready(function($){
			var i;
			var rt_posts = [<?php echo $posts; ?>];
			var rt_total = rt_posts.length;
			var rt_count = 1;
			var rt_percent = 0;
			var rt_successes = 0;
			var rt_errors = 0;
			var rt_failedlist = '';
			var rt_resulttext = '';
			var rt_timestart = new Date().getTime();
			var rt_timeend = 0;
			var rt_totaltime = 0;
			var rt_continue = true;

			// Create the progress bar
			$("#t3iposts-bar").progressbar();
			$("#t3iposts-bar-percent").html( "0%" );

			// Stop button
			$("#t3iposts-stop").click(function() {
				rt_continue = false;
				$('#t3iposts-stop').val("<?php echo $this->esc_quotes( __( 'Stopping, please wait a moment.', 'typo3-importer' ) ); ?>");
			});

			// Clear out the empty list element that's there for HTML validation purposes
			$("#t3iposts-debuglist li").remove();

			// Called after each import. Updates debug information and the progress bar.
			function T3IPostsUpdateStatus( id, success, response ) {
				$("#t3iposts-bar").progressbar( "value", ( rt_count / rt_total ) * 100 );
				$("#t3iposts-bar-percent").html( Math.round( ( rt_count / rt_total ) * 1000 ) / 10 + "%" );
				rt_count = rt_count + 1;

				if ( success ) {
					rt_successes = rt_successes + 1;
					$("#t3iposts-debug-successcount").html(rt_successes);
					$("#t3iposts-debuglist").append("<li>" + response.success + "</li>");
				}
				else {
					rt_errors = rt_errors + 1;
					rt_failedlist = rt_failedlist + ',' + id;
					$("#t3iposts-debug-failurecount").html(rt_errors);
					$("#t3iposts-debuglist").append("<li>" + response.error + "</li>");
				}
			}

			// Called when all posts have been processed. Shows the results and cleans up.
			function T3IPostsFinishUp() {
				rt_timeend = new Date().getTime();
				rt_totaltime = Math.round( ( rt_timeend - rt_timestart ) / 1000 );

				$('#t3iposts-stop').hide();

				if ( rt_errors > 0 ) {
					rt_resulttext = '<?php echo $text_failures; ?>';
				} else {
					rt_resulttext = '<?php echo $text_nofailures; ?>';
				}

				$("#message").html("<p><strong>" + rt_resulttext + "</strong></p>");
				$("#message").show();
			}

			// Regenerate a specified image via AJAX
			function T3IPosts( id ) {
				$.ajax({
					type: 'POST',
					url: ajaxurl,
					data: { action: "importtypo3news", id: id },
					success: function( response ) {
						if ( response.success ) {
							T3IPostsUpdateStatus( id, true, response );
						}
						else {
							T3IPostsUpdateStatus( id, false, response );
						}

						if ( rt_posts.length && rt_continue ) {
							T3IPosts( rt_posts.shift() );
						}
						else {
							T3IPostsFinishUp();
						}
					},
					error: function( response ) {
						T3IPostsUpdateStatus( id, false, response );

						if ( rt_posts.length && rt_continue ) {
							T3IPosts( rt_posts.shift() );
						} 
						else {
							T3IPostsFinishUp();
						}
					}
				});
			}

			T3IPosts( rt_posts.shift() );
		});
	// ]]>
	</script>
<?php
	}


	function show_greeting() {
?>
	<form method="post" action="">
<?php wp_nonce_field('typo3-importer') ?>

	<p><?php _e( "Use this tool to import tt_news and tx_comments records from TYPO3 into WordPress.", 'typo3-importer' ); ?></p>

	<p><?php _e( "Requires remote web and database access to the source TYPO3 instance. Images, files and comments related to TYPO3 tt_news entries will be pulled into WordPress as new posts.", 'typo3-importer' ); ?></p>

	<p><?php _e( "Comments will be automatically tested for spam via Askimet if you have it configured.", 'typo3-importer' ); ?></p>

	<p><?php _e( "Inline and related images will be added to the Media Library. The first image found will be set as the Featured Image for the post. Inline images will have their source URLs updated. Related images will be converted to a [gallery] and appended or inserted into the post per your settings.", 'typo3-importer' ); ?></p>

	<p><?php _e( "Files will be appended to post content as 'Related Files'.", 'typo3-importer' ); ?></p>

	<p><?php _e( "It's possible to change post statuses on import. However, draft posts, will remain as drafts.", 'typo3-importer' ); ?></p>

	<p><?php _e( "If you import TYPO3 news and they've gone live when you didn't want them to, visit the Options screen and look for `Oops`. That's the option to convert imported posts to `Private` thereby removing them from public view.", 'typo3-importer' ); ?></p>

	<p><?php _e( "Finally, it's possible to delete prior imports and lost comments and attachments.", 'typo3-importer' ); ?></p>

	<p><?php printf( __( 'Please review your %s before proceeding.', 'typo3-importer' ), $this->options_link ); ?></p>

	<p><?php _e( 'To begin, click the button below.', 'typo3-importer ', 'typo3-importer'); ?></p>

	<p><input type="submit" class="button hide-if-no-js" name="typo3-importer" id="typo3-importer" value="<?php _e( 'Import TYPO3 News & Comments', 'typo3-importer' ) ?>" /></p>

	<noscript><p><em><?php _e( 'You must enable Javascript in order to proceed!', 'typo3-importer' ) ?></em></p></noscript>

	</form>
<?php
	}


	// Process a single image ID (this is an AJAX handler)
	function ajax_process_news() {
		error_reporting( 0 ); // Don't break the JSON result
		header( 'Content-type: application/json' );

		$this->news_uid			= (int) $_REQUEST['id'];

		// grab the record from TYPO3
		$news					= $this->get_news( $this->news_uid );

		if ( ! is_array( $news ) || 1 > count( $news ) || $news['itemid'] != $this->news_uid )
			die( json_encode( array( 'error' => sprintf( __( "Failed import: %s isn't a TYPO3 news record.", 'typo3-importer' ), esc_html( $_REQUEST['id'] ) ) ) ) );

		// TODO progress by post

		// process and import news post
		$post_id 				= $this->import_news_as_post( $news );

		$this->featured_image_id	= false;

		// replace original external images with internal
		$this->_typo3_replace_images( $post_id );

		// Handle all the metadata for this post
		$this->insert_postmeta( $post_id, $news );

		if ( get_t3i_options( 'set_featured_image' ) && $this->featured_image_id ) {
			update_post_meta( $post_id, "_thumbnail_id", $this->featured_image_id );
		}

		if ( ! get_t3i_options( 'no_comments_import' ) ) {
			// TODO import comments
		}

		die( json_encode( array( 'success' => sprintf( __( '&quot;<a href="%1$s" target="_blank">%2$s</a>&quot; Post ID %3$s was successfully processed in %4$s seconds.', 'typo3-importer' ), get_permalink( $post_id ), esc_html( get_the_title( $post_id ) ), $post_id, timer_stop() ) ) ) );
	}

	function import_news_as_post( $news ) {
		// lookup or create author for posts, but not comments
		$post_author      		= $this->lookup_author( $news['props']['author_email'], $news['props']['author'] );

		if ( in_array( $news['status'], $this->post_status_options ) ) {
			$post_status		= $news['status'];
		} else {
			$post_status		= 'draft';
		}

		// leave draft's alone to prevent publishing something that had been hidden on TYPO3
		$force_post_status		= get_t3i_options( 'force_post_status' );
		if ( 'default' != $force_post_status && 'draft' != $post_status ) {
			$post_status      	= $force_post_status;
		}

		$post_password    		= get_t3i_options( 'protected_password' );
		$post_category			= $news['category'];
		$post_date				= $news['datetime'];

		// Cleaning up and linking the title
		$post_title				= isset( $news['title'] ) ? $news['title'] : '';
		$post_title				= strip_tags( $post_title ); // Can't have tags in the title in WP
		$post_title				= trim( $post_title );

		// Clean up content
		// TYPO3 stores bodytext usually in psuedo HTML
		$post_content			= $this->_prepare_content( $news['bodytext'] );

		// Handle any tags associated with the post
		$tags_input				= ! empty( $news['props']['keywords'] ) ? $news['props']['keywords'] : '';

		// Check if comments are closed on this post
		$comment_status			= $news['props']['comments'];

		// Add excerpt
		$post_excerpt			= ! empty( $news['props']['excerpt'] ) ? $news['props']['excerpt'] : '';

		// add slug AKA url
		$post_name				= $news['props']['slug'];

		$post_id				= post_exists( $post_title, $post_content, $post_date );
		if ( ! $post_id ) {
			$postdata			= compact( 'post_author', 'post_date', 'post_content', 'post_title', 'post_status', 'post_password', 'tags_input', 'comment_status', 'post_excerpt', 'post_category', 'post_name' );
			// @ref http://codex.wordpress.org/Function_Reference/wp_insert_post
			$post_id			= wp_insert_post( $postdata, true );

			if ( is_wp_error( $post_id ) ) {
				if ( 'empty_content' == $post_id->getErrorCode() )
					return; // Silent skip on "empty" posts
			}
		}

		return $post_id;
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
						// TODO figure out other SEO packages
						add_post_meta( $post_id, 'thesis_description', $value );
						break;

					case 'keywords':
						// TODO figure out other SEO packages
						add_post_meta( $post_id, 'thesis_keywords', $value );
						break;

					case 'image':
						$this->insert_postimages( $post_id, $value, $post['props']['imagecaption'] );
						break;

					case 'imagecaption':
						// TODO apply imagecaption to related image
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

	function _typo3_append_links( $post_id, $links ) {
		$post					= get_post( $post_id );
		$post_content			= $post->post_content;

		// create header
		$tag					= get_t3i_options( 'related_links_header_tag' );
		$new_content			= ( $tag ) ? '<h' . $tag . '>' : '';
		$new_content			.= __(  get_t3i_options( 'related_links_header' ), 'typo3-importer');
		$new_content			.= ( $tag ) ? '</h' . $tag . '>' : '';

		$wrap					= get_t3i_options( 'related_links_wrap' );
		$wrap					= explode( '|', $wrap );
		$new_content			.= ( isset( $wrap[0] ) ) ? $wrap[0] : '';
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
		$new_content			.= ( isset( $wrap[1] ) ) ? $wrap[1] : '';
		$post_content			.= $new_content;

		$post					= array(
			'ID'			=> $post_id,
			'post_content'	=> $post_content
		);
	 
		wp_update_post( $post );
	}

	function _typo3_append_files($post_id, $files) {
		if ( $this->no_media_import )
			return;

		$post					= get_post( $post_id );
		$post_content			= $post->post_content;

		// create header
		$tag					= get_t3i_options( 'related_files_header_tag' );
		$new_content			= ( $tag ) ? '<h' . $tag . '>' : '';
		$new_content			.= __(  get_t3i_options( 'related_files_header' ), 'typo3-importer');
		$new_content			.= ( $tag ) ? '</h' . $tag . '>' : '';

		$wrap					= get_t3i_options( 'related_files_wrap' );
		$wrap					= explode( '|', $wrap );
		$new_content			.= ( isset( $wrap[0] ) ) ? $wrap[0] : '';

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
		$new_content			.= ( isset( $wrap[1] ) ) ? $wrap[1] : '';
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

			// TODO config caption for own, title, alt or none
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
		$image_count			= count( $images );
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
			// TODO nice title from file name if possible
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
		$post_content_array		= explode( $this->newline_wp, $post_content );
		$post_content_arr_size	= sizeof( $post_content_array );
		$new_post_content		= '';
		$gallery_code			= '[gallery]';
		$gallery_inserted		= false;

		// don't give single image galleries
		if ( 1 == $image_count && get_t3i_options( 'set_featured_image' ) ) {
			$gallery_code			= '';
		}

		$insert_gallery_shortcut	= get_t3i_options( 'insert_gallery_shortcut' );

		// TODO prevent inserting gallery into pre/code sections
		for ( $i = 0; $i < $post_content_arr_size; $i++ ) {
			if ( $insert_gallery_shortcut != $i ) {
				$new_post_content	.= $post_content_array[$i] . "{$this->newline_wp}";
			} else {
				if ( $insert_gallery_shortcut != 0 && $insert_gallery_shortcut == $i ) {
					$new_post_content	.= "{$gallery_code}{$this->newline_wp}";
					$gallery_inserted	= true;
				}

				$new_post_content	.= $post_content_array[$i] . "{$this->newline_wp}";
			}
		}

		if ( ! $gallery_inserted && 0 != $insert_gallery_shortcut ) {
			$new_post_content	.= $gallery_code;
		}
		
		$post					= array(
			'ID'			=> $post_id,
			'post_content'	=> $new_post_content
		);
	 
		wp_update_post( $post );
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
		$morelink_code			= '<!--more-->';
		
		// t3-cut ==>  <!--more-->
		$content				= preg_replace( '#<t3-cut text="([^"]*)">#is', $morelink_code, $content );
		$content				= str_replace( array( '<t3-cut>', '</t3-cut>' ), array( $morelink_code, '' ), $content );

		$post_content_array		= explode( $this->newline_wp, $content );
		$post_content_arr_size	= sizeof( $post_content_array );
		$new_post_content		= '';

		$insert_more_link			= get_t3i_options( 'insert_more_link' );

		for ( $i = 0; $i < $post_content_arr_size; $i++ ) {
			if ( $insert_more_link != $i ) {
				$new_post_content	.= $post_content_array[$i] . "{$this->newline_wp}";
			} else {
				if ( $insert_more_link != 0 && $insert_more_link == $i ) {
					$new_post_content	.= "{$morelink_code}{$this->newline_wp}";
				}

				$new_post_content	.= $post_content_array[$i] . "{$this->newline_wp}";
			}
		}

		// database prepping
		$content				= trim( $new_post_content );
	
		return $content;
	}

	function lookup_author( $author_email, $author ) {
		// TODO have default author, author_email for empty
		// TODO verify ideas for unique emails work
		$username				= $this->_create_username( $author );

		// create unique emails for no email authors
		if ( '' == $author_email ) {
			$author_email		= $username . '@' . preg_replace( '#https?://#', $this->typo3_url, '' );
		}

		$post_author      		= email_exists( $author_email );

		if ( $post_author )
			return $post_author;

		$url					= preg_replace( '#^[^@]+@#', 'http://', $author_email );

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
			return $post_author;
		} else {
			false;
		}
	}

	function _create_username( $author ) {
		$author					= strtolower( $author );
		$author_arr				= explode( ' ', $author );
		$username_arr			= array();

		foreach ( $author_arr as $key => $value ) {
			// remove all non word characters
			// TODO see if exploding array is really necessary
			$value				= preg_replace( '#\W#', '', $value );
			$username_arr[]		= $value;
		}
		$username				= implode( '.', $username_arr );

		$username_orig			= $username;
		$offset					= 1;
		while ( username_exists( $username ) ) {
			$username			= $username_orig . '.' . $offset;
			$offset++;
		}

		return $username;
	}

	function get_news( $uid = null ) {
		if ( is_null( $uid ) ) {
			$uid				= $this->news_uid;
		}

		$query				= "
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
				AND uid = {$uid}
		";

		$row					= $this->t3db->get_row($query, ARRAY_A);

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

		return $row;
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

	function _typo3_api_news_slug( $uid ) {
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

	// Helper to make a JSON error message
	function die_json_error_msg( $id, $message ) {
		die( json_encode( array( 'error' => sprintf( __( '&quot;%1$s&quot; Post ID %2$s failed to be processed. The error message was: %3$s', 'typo3-importer' ), esc_html( get_the_title( $id ) ), $id, $message ) ) ) );
	}


	// Helper function to escape quotes in strings for use in Javascript
	function esc_quotes( $string ) {
		return str_replace( '"', '\"', $string );
	}


	/**
	 * Returns string of a filename or string converted to a spaced extension
	 * less header type string.
	 *
	 * @author Michael Cannon <michael@typo3vagabond.com>
	 * @param string filename or arbitrary text
	 * @return mixed string/boolean
	 */
	function cbMkReadableStr($str) {
		if ( is_numeric( $str ) ) {
			return number_format( $str );
		}

		if ( is_string($str) )
		{
			$clean_str = htmlspecialchars($str);

			// remove file extension
			$clean_str = preg_replace('/\.[[:alnum:]]+$/i', '', $clean_str);

			// remove funky characters
			$clean_str = preg_replace('/[^[:print:]]/', '_', $clean_str);

			// Convert camelcase to underscore
			$clean_str = preg_replace('/([[:alpha:]][a-z]+)/', "$1_", $clean_str);

			// try to cactch N.N or the like
			$clean_str = preg_replace('/([[:digit:]\.\-]+)/', "$1_", $clean_str);

			// change underscore or underscore-hyphen to become space
			$clean_str = preg_replace('/(_-|_)/', ' ', $clean_str);

			// remove extra spaces
			$clean_str = preg_replace('/ +/', ' ', $clean_str);

			// convert stand alone s to 's
			$clean_str = preg_replace('/ s /', "'s ", $clean_str);

			// remove beg/end spaces
			$clean_str = trim($clean_str);

			// capitalize
			$clean_str = ucwords($clean_str);

			// restore previous entities facing &amp; issues
			$clean_str = preg_replace( '/(&amp ;)([a-z0-9]+) ;/i'
				, '&\2;'
				, $clean_str
			);

			return $clean_str;
		}

		return false;
	}
}


// Start up this plugin
function TYPO3_Importer() {
	global $TYPO3_Importer;
	$TYPO3_Importer	= new TYPO3_Importer();
}

add_action( 'init', 'TYPO3_Importer' );

?>