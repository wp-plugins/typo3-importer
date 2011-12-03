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
	var $menu_id;

	// TODO verify vars below are needed
	// batch limit to help prevent expiring connection
	var $batch_limit_comments	= 50;
	var $batch_limit_news		= 5;
	var $commentmap;
	var $featured_image_id		= false;
	var $import_limit			= 0;
	var $import_types			= array( 'news', 'comments' );
	var $inital_lastsync		= '1900-01-01 00:00:00';
	var $newline_typo3			= "\r\n";
	var $newline_wp				= "\n\n";
	var $post_status_options	= array( 'draft', 'publish', 'pending', 'future', 'private' );
	var $postmap;
	var $t3db					= null;
	var $t3db_host				= null;
	var $t3db_name				= null;
	var $t3db_password			= null;
	var $t3db_username			= null;
	var $typo3_comments_order	= ' ORDER BY c.uid ASC ';
	var $typo3_comments_where	= ' AND c.external_prefix LIKE "tx_ttnews" AND c.deleted = 0 AND c.hidden = 0';
	var $typo3_news_order		= ' ORDER BY n.uid ASC ';
	// pid > 0 need for excluding versioned tt_news entries
	var $typo3_news_where		= ' AND n.deleted = 0 AND n.pid > 0';
	var $typo3_url				= null;
	var $wpdb					= null;

	// Plugin initialization
	function TYPO3_Importer() {
		if ( ! function_exists( 'admin_url' ) )
			return false;

		// Load up the localization file if we're using WordPress in a different language
		// Place it in this plugin's "localization" folder and name it "typo3-importer-[value in wp-config].mo"
		load_plugin_textdomain( 'typo3-importer', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );

		add_action( 'admin_menu', array( &$this, 'add_admin_menu' ) );
		add_action( 'admin_enqueue_scripts', array( &$this, 'admin_enqueues' ) );
		add_action( 'wp_ajax_importtypo3news', array( &$this, 'ajax_process_shortcode' ) );
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
		global $wpdb;
?>

<div id="message" class="updated fade" style="display:none"></div>

<div class="wrap t3iposts">
	<div class="icon32" id="icon-tools"></div>
	<h2><?php _e('TYPO3 Importer', 'typo3-importer'); ?></h2>

<?php
		// testing helper
		if ( isset( $_REQUEST['importtypo3news'] ) && $_REQUEST['importtypo3news'] ) {
			$this->ajax_process_shortcode();
			exit( __LINE__ . ':' . basename( __FILE__ ) . " ERROR<br />\n" );	
		}

		// If the button was clicked
		if ( ! empty( $_POST['typo3-importer'] ) || ! empty( $_REQUEST['posts'] ) ) {
			// Capability check
			if ( !current_user_can( 'manage_options' ) )
				wp_die( __( 'Cheatin&#8217; uh?' , 'typo3-importer') );

			// Form nonce check
			check_admin_referer( 'typo3-importer' );

			// check that TYPO3 login information is valid
			$this->check_typo3_access();

			// Create the list of image IDs
			if ( ! empty( $_REQUEST['posts'] ) ) {
				$posts			= array_map( 'intval', explode( ',', trim( $_REQUEST['posts'], ',' ) ) );
				$count			= count( $posts );
				$posts			= implode( ',', $posts );
			} else {
				// TODO adopt for remotely grabbing unimported tt_news records
				//
				// TODO figure out way to skip aggressive grabbing
				// look for posts containing A/IMG tags referencing Flickr
				$flickr_source_where = "";
				if ( t3i_options( 'import_flickr_sourced_tags' ) ) {
					$flickr_source_where = <<<EOD
						OR (
							post_content LIKE '%<a%href=%http://www.flickr.com/%><img%src=%http://farm%.static.flickr.com/%></a>%'
							OR post_content LIKE '%<img%src=%http://farm%.static.flickr.com/%>%'
						)
EOD;
				}

				// Directly querying the database is normally frowned upon, but all of the API functions will return the full post objects which will suck up lots of memory. This is best, just not as future proof.
				$query			= "
					SELECT ID
					FROM $wpdb->posts
					WHERE 1 = 1
					AND post_type = 'post'
					AND post_parent = 0
					AND (
						post_content LIKE '%[flickr %'
						OR post_content LIKE '%[flickrset %'
						$flickr_source_where
					)
					";

				$include_ids		= t3i_options( 'posts_to_import' );
				if ( $include_ids )
					$query		.= ' AND ID IN ( ' . $include_ids . ' )';

				$skip_ids		= t3i_options( 'skip_importing_post_ids' );
				if ( $skip_ids )
					$query		.= ' AND ID NOT IN ( ' . $skip_ids . ' )';

				$limit			= (int) t3i_options( 'limit' );
				if ( $limit )
					$query		.= ' LIMIT ' . $limit;

				$results		= $wpdb->get_results( $query );
				$count			= 0;

				// Generate the list of IDs
				$posts			= array();
				foreach ( $results as $post ) {
					$posts[]	= $post->ID;
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
			// No button click? Display the form.
			$this->show_greeting();
		}
?>
	</div>
<?php
	}

	function check_typo3_access() {
		// TODO make array of errors
		// TODO return error screen with link to options
		// Log in to confirm the details are correct
		if ( ! t3i_options( 't3db_host' ) )
			die( __( "TYPO3 database host is missing", 'typo3-importer' ) );

		if ( ! t3i_options( 't3db_url' ) )
			die( __( "TYPO3 website URL is missing", 'typo3-importer' ) );

		if ( ! t3i_options( 't3db_name' ) )
			die( __( "TYPO3 database name is missing", 'typo3-importer' ) );

		if ( ! t3i_options( 't3db_username' ) )
			die( __( "TYPO3 database username is missing", 'typo3-importer' ) );

		if ( ! t3i_options( 't3db_password' ) )
			die( __( "TYPO3 database password is missing", 'typo3-importer' ) );
	
		// TODO actually check for DB access

		// check for typo3_url validity & reachability
		if ( ! $this->_is_typo3_website( $this->typo3_url ) )
			die( __( "TYPO3 website URL isn't valid", 'typo3-importer' ) );
	}

	function _is_typo3_website( $url = null ) {
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

	function convert_flickr_sourced_tags( $post ) {
		global $wpdb;

		$post_content			= $post->post_content;

		// looking for
		// <a class="tt-flickr tt-flickr-Medium" title="Khan Sao Road, Bangkok, Thailand" href="http://www.flickr.com/photos/comprock/4334303694/" target="_blank"><img class="alignnone" src="http://farm3.static.flickr.com/2768/4334303694_37785d0f0d.jpg" alt="Khan Sao Road, Bangkok, Thailand" width="500" height="375" /></a>
		// cycle through a/img
		$find_flickr_a_tag		= '#<a.*href=.*http://www.flickr.com/.*><img.*src=.*http://farm\d+.static.flickr.com/.*></a>#i';
		$a_tag_open				= '<a ';

		$post_content			= $this->convert_tag_to_flickr( $post_content, $a_tag_open, $find_flickr_a_tag );

		// cycle through standalone img
		$find_flickr_img_tag		= '#<img.*src=.*http://farm\d+.static.flickr.com/.*>#i';
		$img_tag_open			= '<img ';
		$post_content			= $this->convert_tag_to_flickr( $post_content, $img_tag_open, $find_flickr_img_tag, true );

		$update					= array(
			'ID'				=> $post->ID,
			'post_content'		=> $post_content,
		);

		wp_update_post( $update );
	}


	function convert_tag_to_flickr( $post_content, $tag_open, $find_tag, $img_only = false ) {
		$default_alignment		= t3i_options( 'default_image_alignment' );
		$doc					= new DOMDocument();
		$flickr_shortcode		= '[flickr id="%1$s" thumbnail="%2$s" align="%3$s"]' . "\n";
		$matches				= explode( $tag_open, $post_content );
		$size					= '';

		// for each A/IMG tag set
		foreach ( $matches as $html ) {
			$html				= $tag_open . $html;

			if ( ! preg_match( $find_tag, $html, $match ) ) {
				continue;
			}

			// deal only with the A/IMG tag
			$tag_html				= $match[0];

			// safer than home grown regex
			if ( ! $doc->loadHTML( $tag_html ) ) {
				continue;
			}

			if ( ! $img_only ) {
				// parse out parts id, thumbnail, align
				$a_tags				= $doc->getElementsByTagName( 'a' );
				$a_tag				= $a_tags->item( 0 );

				// gives size tt-flickr tt-flickr-Medium
				$size				= $a_tag->getAttribute( 'class' );
			}

			$size				= $this->get_shortcode_size( $size );

			$image_tags			= $doc->getElementsByTagName( 'img' );
			$image_tag			= $image_tags->item( 0 );

			// give photo id http://farm3.static.flickr.com/2768/4334303694_37785d0f0d.jpg
			$src				= $image_tag->getAttribute( 'src' );
			$filename			= basename( $src );
			$id					= preg_replace( '#^(\d+)_.*#', '\1', $filename );

			// gives alginment alignnone
			$align_primary		= $image_tag->getAttribute( 'class' );
			$align_secondary	= $image_tag->getAttribute( 'align' );
			$align_combined		= $align_secondary . ' ' . $align_primary;

			$find_align			= '#(none|left|center|right)#i';
			preg_match_all( $find_align, $align_combined, $align_matches );
			// get the last align mentioned since that has precedence
			$align				= ( count( $align_matches[0] ) ) ? array_pop( $align_matches[0] ) : $default_alignment;

			// ceate simple [flickr] like
			// [flickr id="5348222727" thumbnail="small" align="none"]
			$replacement		= sprintf( $flickr_shortcode, $id, $size, $align );
			// replace A/IMG with new [flickr]
			$post_content		= str_replace( $tag_html, $replacement, $post_content );
		}

		return $post_content;
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
	function ajax_process_shortcode() {
		@error_reporting( 0 ); // Don't break the JSON result

		header( 'Content-type: application/json' );

		$this->post_id			= (int) $_REQUEST['id'];
		// TODO grab the record from TYPO3
		$post					= get_post( $this->post_id );

		if ( t3i_options( 'import_flickr_sourced_tags' ) ) {
			$this->convert_flickr_sourced_tags( $post );
			$post				= get_post( $this->post_id );
		}

		if ( ! $post || 'post' != $post->post_type || ! stristr( $post->post_content, '[flickr' ) )
			die( json_encode( array( 'error' => sprintf( __( "Failed import: %s isn't a TYPO3 news recrd.", 'typo3-importer' ), esc_html( $_REQUEST['id'] ) ) ) ) );

		if ( ! current_user_can( 'manage_options' ) )
			$this->die_json_error_msg( $this->post_id, __( "Your user account doesn't have permission to import images", 'typo3-importer' ) );

		// default is Flickr Shortcode Import API key
		$api_key				= t3i_options( 'flickr_api_key' );
		$secret					= t3i_options( 'flickr_api_secret' );
		$this->flickr			= new phpFlickr( $api_key, $secret );

		// only use our shortcode handlers to prevent messing up post content 
		remove_all_shortcodes();
		add_shortcode( 'flickr', array( &$this, 'shortcode_flickr' ) );
		add_shortcode( 'flickrset', array( &$this, 'shortcode_flickrset' ) );

		// Don't overwrite Featured Images
		$this->featured_id		= false;
		$this->first_image		= t3i_options( 'remove_first_flickr_shortcode' ) ? true : false;
		$this->menu_order		= 1;

		// TODO progress by post

		// TODO process post

		// process [flickr] codes in posts
		$post_content			= do_shortcode( $post->post_content );

		// allow overriding Featured Image
		if ( $this->featured_id
			&& t3i_options( 'set_featured_image' )
			&& ( ! has_post_thumbnail( $this->post_id ) || t3i_options( 'force_set_featured_image' ) ) ) {
			$updated			= update_post_meta( $this->post_id, "_thumbnail_id", $this->featured_id );
		}

		$post					= array(
			'ID'			=> $this->post_id,
			'post_content'	=> $post_content,
		);

		wp_update_post( $post );

		die( json_encode( array( 'success' => sprintf( __( '&quot;<a href="%1$s" target="_blank">%2$s</a>&quot; Post ID %3$s was successfully processed in %4$s seconds.', 'typo3-importer' ), get_permalink( $this->post_id ), esc_html( get_the_title( $this->post_id ) ), $this->post_id, timer_stop() ) ) ) );
	}

	
	// process each [flickr] entry
	function shortcode_flickr( $args ) {
		set_time_limit( 120 );

		$photo					= $this->flickr->photos_getInfo( $args['id'] );
		$photo					= $photo['photo'];

		if ( isset( $args['set_title'] ) ) {
			$photo['set_title']	= $args['set_title'];
		} else {
			$contexts			= $this->flickr->photos_getAllContexts( $args['id'] );
			$photo['set_title']	= isset( $contexts['set'][0]['title'] ) ? $contexts['set'][0]['title'] : '';
		}
		
		$markup					= $this->process_flickr_media( $photo, $args );
		
		return $markup;
	}

	
	// process each [flickrset] entry
	function shortcode_flickrset( $args ) {
		$this->in_flickset		= true;
		$import_limit			= ( $args['photos'] ) ? $args['photos'] : -1;

		$info					= $this->flickr->photosets_getInfo( $args['id'] );
		$args['set_title']		= $info['title'];

		$photos					= $this->flickr->photosets_getPhotos( $args['id'] );
		$photos					= $photos['photoset']['photo'];

		// increased because [flickrset] might have lots of photos
		set_time_limit( 120 * count( $photos ) );
		
		foreach( $photos as $entry ) {
			$args['id']			= $entry['id'];
			$this->shortcode_flickr( $args );

			if ( 0 == --$import_limit )
				break;
		}
		
		$markup					= '[gallery]';
		$this->in_flickset		= false;

		return $markup;
	}


	function process_flickr_media( $photo, $args = false ) {
		$markup					= '';

		if ( 'photo' == $photo['media'] ) {
			// pull original Flickr image
			$src				= $this->flickr->buildPhotoURL( $photo, 'original' );
			// add image to media library
			$image_id			= $this->import_flickr_media( $src, $photo );

			// if first image, set as featured 
			if ( ! $this->featured_id ) {
				$this->featured_id	= $image_id;
			}

			// no args, means nothing further to do
			if ( false === $args )
				return $markup;

			// wrap in link to attachment itself
			$size				= isset( $args['thumbnail'] ) ? $args['thumbnail'] : '';
			$size				= $this->get_shortcode_size( $size );
			$image_link			= wp_get_attachment_link( $image_id, $size, true 	);

			// correct class per args
			$align				= isset( $args['align'] ) ? $args['align'] : t3i_options( 'default_image_alignment' );
			$align				= ' align' . $align;
			$wp_image			= ' wp-image-' . $image_id;
			$image_link			= preg_replace( '#(class="[^"]+)"#', '\1'
				. $align
				. $wp_image
				. '"', $image_link );

			$class				= t3i_options( 'default_a_tag_class' );
			if ( $class ) {
				$image_link		= preg_replace(
					'#(<a )#',
					'\1class="' . $class . '" ',
					$image_link );
			}

			if ( ! $this->first_image ) {
				// remaining [flickr] converted to locally reference image
				$markup			= $image_link;
			} else {
				// remove [flickr] from post
				$this->first_image	= false;
			}
		}

		return $markup;
	}
	

	// correct none thumbnail, medium, large or full size values
	function get_shortcode_size( $size_name = '' ) {
		$find_size				= '#(square|thumbnail|small|medium|large|original|full)#i';
		preg_match_all( $find_size, $size_name, $size_matches );
		// get the last size mentioned since that has precedence
		$size_name				= ( isset( $size_matches[0] ) ) ? array_pop( $size_matches[0] ) : '';

		switch ( strtolower( $size_name ) ) {
			case 'square':
			case 'thumbnail':
			case 'small':
				$size			= 'thumbnail';
				break;

			case 'medium':
			case 'medium_640':
				$size			= 'medium';
				break;

			case 'large':
				$size			= 'large';
				break;

			case 'original':
			case 'full':
				$size			= 'full';
				break;

			default:
				$size			= t3i_options( 'default_image_size' );
				break;
		}

		return $size;
	}
	

	function import_flickr_media( $src, $photo ) {
		global $wpdb;

		$set_title			= isset( $photo['set_title'] ) ? $photo['set_title'] : '';
		$title				= $photo['title'];

		if ( t3i_options( 'make_nice_image_title' ) ) {
			// if title is a filename, use set_title - menu order instead
			if ( ( preg_match( '#\.[a-zA-Z]{3}$#', $title ) 
			   	|| preg_match( '#^DSCF\d+#', $title ) )
				&& ! empty( $set_title ) ) {
				$title			= $set_title . ' - ' . $this->menu_order;
			} elseif ( ! preg_match( '#\s#', $title ) ) {
				$title		= $this->cbMkReadableStr( $title );
			}
		}

		$alt				= $title;
		$caption			= t3i_options( 'set_caption' ) ? $title : '';
		$desc				= html_entity_decode( $photo['description'] );
		$date				= $photo['dates']['taken'];
		$file				= basename( $src );

		// see if src is duplicate, if so return image_id
		$query				= "
			SELECT m.post_id
			FROM $wpdb->postmeta m
			WHERE 1 = 1
				AND m.meta_key LIKE '_flickr_src'
				AND m.meta_value LIKE '$src'
		";
		$dup				= $wpdb->get_var( $query );

		if ( $dup ) {
			// ignore dup if importing [flickrset]
			if( ! $this->in_flickset ) {
				return $dup;
			} else {
				// use local source to speed up transfer
				$src		= wp_get_attachment_url( $dup );
			}
		}

		$file_move			= wp_upload_bits( $file, null, file_get_contents( $src ) );
		$filename			= $file_move['file'];

		$wp_filetype		= wp_check_filetype( $file, null );
		$attachment			= array(
			'menu_order'		=> $this->menu_order++,
			'post_content'		=> $desc,
			'post_date'			=> $date,
			'post_excerpt'		=> $caption,
			'post_mime_type'	=> $wp_filetype['type'],
			'post_status'		=> 'inherit',
			'post_title'		=> $title,
		);

		// relate image to post
		$image_id			= wp_insert_attachment( $attachment, $filename, $this->post_id );

		if ( ! $image_id )
			$this->die_json_error_msg( $this->post_id, sprintf( __( 'The originally uploaded image file cannot be found at %s', 'typo3-importer' ), '<code>' . esc_html( $filename ) . '</code>' ) );

		$metadata				= wp_generate_attachment_metadata( $image_id, $filename );

		if ( is_wp_error( $metadata ) )
			$this->die_json_error_msg( $this->post_id, $metadata->get_error_message() );

		if ( empty( $metadata ) )
			$this->die_json_error_msg( $this->post_id, __( 'Unknown failure reason.', 'typo3-importer' ) );

		// If this fails, then it just means that nothing was changed (old value == new value)
		wp_update_attachment_metadata( $image_id, $metadata );
		update_post_meta( $image_id, '_wp_attachment_image_alt', $alt );
		// help keep track of what's been imported already
		update_post_meta( $image_id, '_flickr_src', $src );

		return $image_id;
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