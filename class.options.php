<?php

/**
 * TYPO3 Importer settings class
 *
 * @ref http://alisothegeek.com/2011/01/wordpress-settings-api-tutorial-1/
 */
class T3I_Settings {
	
	private $sections;
	private $checkboxes;
	private $settings;
	
	/**
	 * Construct
	 */
	public function __construct() {
		global $wpdb;
		
		// This will keep track of the checkbox options for the validate_settings function.
		$this->checkboxes		= array();
		$this->settings			= array();
		$this->get_settings();
		
		$this->sections['typo3']	= __( 'TYPO3 Access', 'typo3-importer');
		$this->sections['selection']	= __( 'News Selection', 'typo3-importer');
		$this->sections['general']	= __( 'Import Options', 'typo3-importer');
		$this->sections['testing']	= __( 'Testing Options', 'typo3-importer');
		$this->sections['oops']		= __( 'Oops...', 'typo3-importer');
		$this->sections['reset']	= __( 'Reset/Restore', 'typo3-importer');
		$this->sections['TBI']		= __( 'Not Implemented', 'typo3-importer');
		$this->sections['about']	= __( 'About TYPO3 Importer', 'typo3-importer');
		
		add_action( 'admin_menu', array( &$this, 'add_pages' ) );
		add_action( 'admin_init', array( &$this, 'register_settings' ) );

		load_plugin_textdomain( 'typo3-importer', false, '/typo3-importer/languages/' );
		
		if ( ! get_option( 't3i_options' ) )
			$this->initialize_settings();

		$this->wpdb				= $wpdb;
	}
	
	/**
	 * Add options page
	 */
	public function add_pages() {
		
		$admin_page = add_options_page( __( 'TYPO3 Importer Options', 'typo3-importer'), __( 'TYPO3 Import Options', 'typo3-importer'), 'manage_options', 't3i-options', array( &$this, 'display_page' ) );
		
		add_action( 'admin_print_scripts-' . $admin_page, array( &$this, 'scripts' ) );
		add_action( 'admin_print_styles-' . $admin_page, array( &$this, 'styles' ) );
		
	}
	
	/**
	 * Create settings field
	 */
	public function create_setting( $args = array() ) {
		
		$defaults = array(
			'id'      => 'default_field',
			'title'   => __( 'Default Field', 'typo3-importer'),
			'desc'    => __( '', 'typo3-importer'),
			'std'     => '',
			'type'    => 'text',
			'section' => 'general',
			'choices' => array(),
			'class'   => ''
		);
			
		extract( wp_parse_args( $args, $defaults ) );
		
		$field_args = array(
			'type'      => $type,
			'id'        => $id,
			'desc'      => $desc,
			'std'       => $std,
			'choices'   => $choices,
			'label_for' => $id,
			'class'     => $class
		);
		
		if ( $type == 'checkbox' )
			$this->checkboxes[] = $id;
		
		add_settings_field( $id, $title, array( $this, 'display_setting' ), 't3i-options', $section, $field_args );
	}
	
	/**
	 * Display options page
	 */
	public function display_page() {
		
		echo '<div class="wrap">
	<div class="icon32" id="icon-options-general"></div>
	<h2>' . __( 'TYPO3 Importer Options', 'typo3-importer') . '</h2>';
	
		echo '<form action="options.php" method="post">';
	
		settings_fields( 't3i_options' );
		echo '<div class="ui-tabs">
			<ul class="ui-tabs-nav">';
		
		foreach ( $this->sections as $section_slug => $section )
			echo '<li><a href="#' . $section_slug . '">' . $section . '</a></li>';
		
		echo '</ul>';
		do_settings_sections( $_GET['page'] );
		
		echo '</div>
		<p class="submit"><input name="Submit" type="submit" class="button-primary" value="' . __( 'Save Changes', 'typo3-importer') . '" /></p>

		<div class="ready">When ready, <a href="'.get_admin_url().'tools.php?page=typo3-importer">'.__('begin importing', 'typo3-importer').'</a>.</div>
		
	</form>';

		$copyright				= '<div class="copyright">Copyright %s <a href="http://typo3vagabond.com">TYPO3Vagabond.com.</a></div>';
		$copyright				= sprintf( $copyright, date( 'Y' ) );
		echo					<<<EOD
				$copyright
EOD;
	
	echo '<script type="text/javascript">
		jQuery(document).ready(function($) {
			var sections = [];';
			
			foreach ( $this->sections as $section_slug => $section )
				echo "sections['$section'] = '$section_slug';";
			
			echo 'var wrapped = $(".wrap h3").wrap("<div class=\"ui-tabs-panel\">");
			wrapped.each(function() {
				$(this).parent().append($(this).parent().nextUntil("div.ui-tabs-panel"));
			});
			$(".ui-tabs-panel").each(function(index) {
				$(this).attr("id", sections[$(this).children("h3").text()]);
				if (index > 0)
					$(this).addClass("ui-tabs-hide");
			});
			$(".ui-tabs").tabs({
				fx: { opacity: "toggle", duration: "fast" }
			});
			
			$("input[type=text], textarea").each(function() {
				if ($(this).val() == $(this).attr("placeholder") || $(this).val() == "")
					$(this).css("color", "#999");
			});
			
			$("input[type=text], textarea").focus(function() {
				if ($(this).val() == $(this).attr("placeholder") || $(this).val() == "") {
					$(this).val("");
					$(this).css("color", "#000");
				}
			}).blur(function() {
				if ($(this).val() == "" || $(this).val() == $(this).attr("placeholder")) {
					$(this).val($(this).attr("placeholder"));
					$(this).css("color", "#999");
				}
			});
			
			$(".wrap h3, .wrap table").show();
			
			// This will make the "warning" checkbox class really stand out when checked.
			// I use it here for the Reset checkbox.
			$(".warning").change(function() {
				if ($(this).is(":checked"))
					$(this).parent().css("background", "#c00").css("color", "#fff").css("fontWeight", "bold");
				else
					$(this).parent().css("background", "none").css("color", "inherit").css("fontWeight", "normal");
			});
			
			// Browser compatibility
			if ($.browser.mozilla) 
			         $("form").attr("autocomplete", "off");
		});
	</script>
</div>';
		
	}
	
	/**
	 * Description for section
	 */
	public function display_section() {
		// code
	}
	
	/**
	 * Description for About section
	 */
	public function display_about_section() {
		
		// TODO update verbiage from about page with links to Peimic 
		echo					<<<EOD
			<div style="width: 50%;">
				<p><img class="alignright size-medium" title="Michael in Red Square, Moscow, Russia" src="/wp-content/plugins/typo3-importer/media/michael-cannon-red-square-300x2251.jpg" alt="Michael in Red Square, Moscow, Russia" width="300" height="225" /><a href="http://wordpress.org/extend/plugins/typo3-importer/">TYPO3 Importer</a> is by <a href="mailto:michael@typo3vagabond.com">Michael Cannon</a>.</p>
				<p>He's Peichi’s man, an adventurous water rat & a TYPO3 support guru who’s living simply, roaming about & smiling more.</p>
				<p>If you like this plugin, <a href="http://typo3vagabond.com/about-typo3-vagabond/donate/">please donate</a>.</p>
			</div>
EOD;
		
	}
	
	/**
	 * HTML output for text field
	 */
	public function display_setting( $args = array() ) {
		
		extract( $args );
		
		$options = get_option( 't3i_options' );
		
		if ( ! isset( $options[$id] ) && $type != 'checkbox' )
			$options[$id] = $std;
		elseif ( ! isset( $options[$id] ) )
			$options[$id] = 0;
		
		$field_class = '';
		if ( $class != '' )
			$field_class = ' ' . $class;
		
		switch ( $type ) {
			
			case 'heading':
				echo '</td></tr><tr valign="top"><td colspan="2"><h4>' . $desc . '</h4>';
				break;
			
			case 'checkbox':
				
				echo '<input class="checkbox' . $field_class . '" type="checkbox" id="' . $id . '" name="t3i_options[' . $id . ']" value="1" ' . checked( $options[$id], 1, false ) . ' /> <label for="' . $id . '">' . $desc . '</label>';
				
				break;
			
			case 'select':
				echo '<select class="select' . $field_class . '" name="t3i_options[' . $id . ']">';
				
				foreach ( $choices as $value => $label )
					echo '<option value="' . esc_attr( $value ) . '"' . selected( $options[$id], $value, false ) . '>' . $label . '</option>';
				
				echo '</select>';
				
				if ( $desc != '' )
					echo '<br /><span class="description">' . $desc . '</span>';
				
				break;
			
			case 'radio':
				$i = 0;
				foreach ( $choices as $value => $label ) {
					echo '<input class="radio' . $field_class . '" type="radio" name="t3i_options[' . $id . ']" id="' . $id . $i . '" value="' . esc_attr( $value ) . '" ' . checked( $options[$id], $value, false ) . '> <label for="' . $id . $i . '">' . $label . '</label>';
					if ( $i < count( $options ) - 1 )
						echo '<br />';
					$i++;
				}
				
				if ( $desc != '' )
					echo '<br /><span class="description">' . $desc . '</span>';
				
				break;
			
			case 'textarea':
				echo '<textarea class="' . $field_class . '" id="' . $id . '" name="t3i_options[' . $id . ']" placeholder="' . $std . '" rows="5" cols="30">' . wp_htmledit_pre( $options[$id] ) . '</textarea>';
				
				if ( $desc != '' )
					echo '<br /><span class="description">' . $desc . '</span>';
				
				break;
			
			case 'password':
				echo '<input class="regular-text' . $field_class . '" type="password" id="' . $id . '" name="t3i_options[' . $id . ']" value="' . esc_attr( $options[$id] ) . '" />';
				
				if ( $desc != '' )
					echo '<br /><span class="description">' . $desc . '</span>';
				
				break;
			
			case 'text':
			default:
		 		echo '<input class="regular-text' . $field_class . '" type="text" id="' . $id . '" name="t3i_options[' . $id . ']" placeholder="' . $std . '" value="' . esc_attr( $options[$id] ) . '" />';
		 		
		 		if ( $desc != '' )
		 			echo '<br /><span class="description">' . $desc . '</span>';
		 		
		 		break;
		 	
		}
		
	}
	
	/**
	 * Settings and defaults
	 */
	public function get_settings() {
		// TYPO3 Website Access
		$this->settings['typo3_url'] = array(
			'title'   => __( 'Website URL', 'typo3-importer'),
			'desc'    => __( 'e.g. http://example.com', 'typo3-importer'),
			'std'     => '',
			'type'    => 'text',
			'section' => 'typo3'
		);
		
		$this->settings['t3db_host'] = array(
			'title'   => __( 'Database Host', 'typo3-importer'),
			'std'     => '',
			'type'    => 'text',
			'section' => 'typo3'
		);
		
		$this->settings['t3db_name'] = array(
			'title'   => __( 'Database Name', 'typo3-importer'),
			'std'     => '',
			'type'    => 'text',
			'section' => 'typo3'
		);
		
		$this->settings['t3db_username'] = array(
			'title'   => __( 'Database Username', 'typo3-importer'),
			'std'     => '',
			'type'    => 'text',
			'section' => 'typo3'
		);
		
		$this->settings['t3db_password'] = array(
			'title'   => __( 'Database Password', 'typo3-importer'),
			'type'    => 'password',
			'std'     => '',
			'section' => 'typo3'
		);
		
		
		// Import Options
		$this->settings['protected_password'] = array(
			'title'   => __( 'Protected Post Password', 'typo3-importer'),
			'desc'    => __( 'If set, posts will require this password to be viewed.', 'typo3-importer'),
			'std'     => '',
			'type'    => 'text',
			'section' => 'general'
		);
		
		$this->settings['force_post_status'] = array(
			'section' => 'general',
			'title'   => __( 'Set Post Status as...?', 'typo3-importer'),
			'desc'    => __( 'Overrides incoming news record status. However, hidden news records will remain as Draft.', 'typo3-importer'),
			'type'    => 'radio',
			'std'     => 'default',
			'choices' => array(
				'default'	=> 'Default',
				'draft'		=> 'Draft',
				'publish'	=> 'Publish',
				'pending'	=> 'Pending',
				'future'	=> 'Future',
				'private'	=> 'Private'
			)
		);

		$this->settings['insert_more_link'] = array(
			'section' => 'general',
			'title'   => __( 'Insert More Link?', 'typo3-importer'),
			'desc'    => __( 'Denote where the &lt;--more--&gt; link is be inserted into post content.', 'typo3-importer'),
			'type'    => 'select',
			'std'     => '0',
			'choices' => array(
				'0'	=> 'No',
				'1'	=> 'After 1st paragraph',
				'2'	=> 'After 2nd paragraph',
				'3'	=> 'After 3rd paragraph',
				'4'	=> 'After 4th paragraph',
				'5'	=> 'After 5th paragraph',
				'6'	=> 'After 6th paragraph',
				'7'	=> 'After 7th paragraph',
				'8'	=> 'After 8th paragraph',
				'9'	=> 'After 9th paragraph',
				'10'	=> 'After 10th paragraph'
			)
		);

		$this->settings['set_featured_image'] = array(
			'section' => 'general',
			'title'   => __( 'Set Featured Image?', 'typo3-importer'),
			'desc'    => __( 'Set first image found in content or related as the Featured Image.', 'typo3-importer'),
			'type'    => 'checkbox',
			'std'     => 1
		);
		
		$this->settings['insert_gallery_shortcut'] = array(
			'section' => 'general',
			'title'   => __( 'Insert Gallery Shortcode?', 'typo3-importer'),
			'desc'    => __( 'Inserts [gallery] into post content if news record has related images. Follows more link if insert points match.', 'typo3-importer'),
			'type'    => 'select',
			'std'     => '-1',
			'choices' => array(
				'0'	=> 'No',
				'1'	=> 'After 1st paragraph',
				'2'	=> 'After 2nd paragraph',
				'3'	=> 'After 3rd paragraph',
				'4'	=> 'After 4th paragraph',
				'5'	=> 'After 5th paragraph',
				'6'	=> 'After 6th paragraph',
				'7'	=> 'After 7th paragraph',
				'8'	=> 'After 8th paragraph',
				'9'	=> 'After 9th paragraph',
				'10'	=> 'After 10th paragraph',
				'-1'	=> 'After content'
			)
		);

		$this->settings['related_files_header'] = array(
			'title'   => __( 'Related Files Header' , 'typo3-importer'),
			'std'     => __( 'Related Files', 'typo3-importer' ),
			'type'	=> 'text',
			'section' => 'general'
		);

		$this->settings['related_files_header_tag'] = array(
			'section' => 'general',
			'title'   => __( 'Related Files Header Tag', 'typo3-importer'),
			'type'    => 'select',
			'std'     => '3',
			'choices' => array(
				'0'	=> 'None',
				'1'	=> 'H1',
				'2'	=> 'H2',
				'3'	=> 'H3',
				'4'	=> 'H4',
				'5'	=> 'H5',
				'6'	=> 'H6'
			)
		);
		
		$this->settings['related_files_wrap'] = array(
			'title'   => __( 'Related Files Wrap' , 'typo3-importer'),
			'desc'   => __( 'Useful for adding membership oriented shortcodes around premium content. e.g. [paid]|[/paid]' , 'typo3-importer'),
			'type'	=> 'text',
			'section' => 'general'
		);

		$this->settings['related_links_header'] = array(
			'title'   => __( 'Related Links Header' , 'typo3-importer'),
			'std'     => __( 'Related Links', 'typo3-importer' ),
			'type'	=> 'text',
			'section' => 'general'
		);

		$this->settings['related_links_header_tag'] = array(
			'section' => 'general',
			'title'   => __( 'Related Links Header Tag', 'typo3-importer'),
			'type'    => 'select',
			'std'     => '3',
			'choices' => array(
				'0'	=> 'None',
				'1'	=> 'H1',
				'2'	=> 'H2',
				'3'	=> 'H3',
				'4'	=> 'H4',
				'5'	=> 'H5',
				'6'	=> 'H6'
			)
		);
		
		$this->settings['related_links_wrap'] = array(
			'title'   => __( 'Related Links Wrap' , 'typo3-importer'),
			'desc'   => __( 'Useful for adding membership oriented shortcodes around premium content. e.g. [paid]|[/paid]' , 'typo3-importer'),
			'section' => 'general'
		);
		$this->settings['approve_comments'] = array(
			'section' => 'general',
			'title'   => __( 'Approve Non-spam Comments?', 'typo3-importer'),
			'desc'    => __( 'Not fool proof, but beats mass approving comments after import.', 'typo3-importer'),
			'type'    => 'checkbox',
			'std'     => 1
		);
		
		// Testing
		$this->settings['no_comments_import'] = array(
			'section' => 'testing',
			'title'   => __( "Don't Import Comments" , 'typo3-importer'),
			'type'    => 'checkbox',
			'std'     => 0
		);
		
		$this->settings['no_media_import'] = array(
			'section' => 'testing',
			'title'   => __( "Don't Import Media" , 'typo3-importer'),
			'desc'    => __( 'Skips importing any related images and other media files of news records.', 'typo3-importer'),
			'type'    => 'checkbox',
			'std'     => 0
		);
		
		$this->settings['import_limit'] = array(
			'section' => 'testing',
			'title'   => __( 'Import Limit', 'typo3-importer'),
			'desc'    => __( 'Number of news records allowed to import at a time. 0 for all..', 'typo3-importer'),
			'std'     => '',
			'type'    => 'text'
		);
		
		// Oops...
		$this->settings['force_private_posts'] = array(
			'section' => 'oops',
			'title'   => __( 'Convert Imported Posts to Private, NOW!', 'typo3-importer'),
			'desc'    => __( 'A quick way to hide imported live posts.', 'typo3-importer'),
			'type'    => 'checkbox',
			'std'     => 0
		);
		
		$desc_imports		= __( "This will remove ALL post imports with the 't3:tt_news.uid' meta key. Related post media and comments will also be deleted.", 'typo3-importer');
		$desc_comments		= __( "This will remove ALL comments imports with the 't3:tx_comments' comment_agent key." , 'typo3-importer');
		$desc_attachments	= __( "This will remove ALL media without a related post. It's possible for non-imported media to be deleted.", 'typo3-importer');

		// Reset/restore
		$this->settings['delete'] = array(
			'section' => 'reset',
			'title'   => __( 'Delete...', 'typo3-importer'),
			'type'    => 'radio',
			'std'     => '',
			'choices' => array(
				'imports'		=> __( 'Prior imports', 'typo3-importer') . ': ' . $desc_imports,
				'comments'		=> __( 'Imported comments', 'typo3-importer') . ': ' . $desc_comments,
				'attachments'	=> __( 'Unattached media', 'typo3-importer') . ': ' . $desc_attachments
			)
		);
		
		$this->settings['reset_plugin'] = array(
			'section' => 'reset',
			'title'   => __( 'Reset plugin', 'typo3-importer'),
			'type'    => 'checkbox',
			'std'     => 0,
			'class'   => 'warning', // Custom class for CSS
			'desc'    => __( 'Check this box and click "Save Changes" below to reset plugin options to their defaults.', 'typo3-importer')
		);


		// selection
		$this->settings['news_custom_where'] = array(
			'title'   => __( 'News WHERE Clause' , 'typo3-importer'),
			'desc'    => __( "WHERE clause used to select news records from TYPO3. e.g.: AND tt_news.deleted = 0 AND tt_news.pid > 0" , 'typo3-importer'),
			'std'     => 'AND tt_news.deleted = 0 AND tt_news.pid > 0',
			'type'	=> 'text',
			'section' => 'selection'
		);
		
		$this->settings['news_custom_order'] = array(
			'title'   => __( 'News ORDER Clause' , 'typo3-importer'),
			'desc'    => __( "ORDER clause used to select news records from TYPO3. e.g.: ORDER BY tt_news.uid ASC" , 'typo3-importer'),
			'std'     => 'ORDER BY tt_news.uid ASC',
			'type'	=> 'text',
			'section' => 'selection'
		);

		$this->settings['news_to_import'] = array(
			'title'   => __( 'News to Import' , 'typo3-importer'),
			'desc'    => __( "A CSV list of news uids to import, like '1,2,3'. Overrides 'News Selection Criteria'." , 'typo3-importer'),
			'type'	=> 'text',
			'section' => 'selection'
		);
		
		$this->settings['news_to_skip'] = array(
			'title'   => __( 'Skip Importing News' , 'typo3-importer'),
			'desc'    => __( "A CSV list of news uids not to import, like '1,2,3'." , 'typo3-importer'),
			'type'	=> 'text',
			'section' => 'selection'
		);
		

		// Pending
		$this->settings['make_nice_image_title'] = array(
			'section' => 'TBI',
			'title'   => __( 'Make Nice Image Title?' , 'typo3-importer'),
			'desc'    => __( 'Tries to make a nice title out of filenames if no title exists.' , 'typo3-importer'),
			'type'    => 'checkbox',
			'std'     => 1
		);
		
		
		// Here for reference
		if ( false ) {
		$this->settings['example_text'] = array(
			'title'   => __( 'Example Text Input', 'typo3-importer'),
			'desc'    => __( 'This is a description for the text input.', 'typo3-importer'),
			'std'     => 'Default value',
			'type'    => 'text',
			'section' => 'general'
		);
		
		$this->settings['example_textarea'] = array(
			'title'   => __( 'Example Textarea Input', 'typo3-importer'),
			'desc'    => __( 'This is a description for the textarea input.', 'typo3-importer'),
			'std'     => 'Default value',
			'type'    => 'textarea',
			'section' => 'general'
		);
		
		$this->settings['example_checkbox'] = array(
			'section' => 'general',
			'title'   => __( 'Example Checkbox', 'typo3-importer'),
			'desc'    => __( 'This is a description for the checkbox.', 'typo3-importer'),
			'type'    => 'checkbox',
			'std'     => 1 // Set to 1 to be checked by default, 0 to be unchecked by default.
		);
		
		$this->settings['example_heading'] = array(
			'section' => 'general',
			'title'   => '', // Not used for headings.
			'desc'    => 'Example Heading',
			'type'    => 'heading'
		);
		
		$this->settings['example_radio'] = array(
			'section' => 'general',
			'title'   => __( 'Example Radio', 'typo3-importer'),
			'desc'    => __( 'This is a description for the radio buttons.', 'typo3-importer'),
			'type'    => 'radio',
			'std'     => '',
			'choices' => array(
				'choice1' => 'Choice 1',
				'choice2' => 'Choice 2',
				'choice3' => 'Choice 3'
			)
		);
		
		$this->settings['example_select'] = array(
			'section' => 'general',
			'title'   => __( 'Example Select', 'typo3-importer'),
			'desc'    => __( 'This is a description for the drop-down.', 'typo3-importer'),
			'type'    => 'select',
			'std'     => '',
			'choices' => array(
				'choice1' => 'Other Choice 1',
				'choice2' => 'Other Choice 2',
				'choice3' => 'Other Choice 3'
			)
		);
		}
	}
	
	/**
	 * Initialize settings to their default values
	 */
	public function initialize_settings() {
		
		$default_settings = array();
		foreach ( $this->settings as $id => $setting ) {
			if ( $setting['type'] != 'heading' )
				$default_settings[$id] = $setting['std'];
		}
		
		update_option( 't3i_options', $default_settings );
		
	}
	
	/**
	* Register settings
	*/
	public function register_settings() {
		
		register_setting( 't3i_options', 't3i_options', array ( &$this, 'validate_settings' ) );
		
		foreach ( $this->sections as $slug => $title ) {
			if ( $slug == 'about' )
				add_settings_section( $slug, $title, array( &$this, 'display_about_section' ), 't3i-options' );
			else
				add_settings_section( $slug, $title, array( &$this, 'display_section' ), 't3i-options' );
		}
		
		$this->get_settings();
		
		foreach ( $this->settings as $id => $setting ) {
			$setting['id'] = $id;
			$this->create_setting( $setting );
		}
		
	}
	
	/**
	* jQuery Tabs
	*/
	public function scripts() {
		
		wp_print_scripts( 'jquery-ui-tabs' );
		
	}
	
	/**
	* Styling for the plugin options page
	*/
	public function styles() {
		
		wp_register_style( 'fsi-admin', plugins_url( 'settings.css', __FILE__ ) );
		wp_enqueue_style( 'fsi-admin' );
		
	}
	
	/**
	* Validate settings
	*/
	public function validate_settings( $input ) {
		
		// TODO validate for
		// CSV of posts_to_import, skip_importing_post_ids
		// valid TYPO3 url
		// db connectivity

		if ( isset( $input['delete'] ) && $input['delete'] ) {
			switch ( $input['delete'] ) {
				case 'imports' :
					$this->delete_import();
					break;

				case 'comments' :
					$this->delete_comments();
					break;

				case 'attachments' :
					$this->delete_attachments();
					break;
			}

			unset( $input['delete'] );
			return $input;
		}

		if ( isset( $input['force_private_posts'] ) && $input['force_private_posts'] ) {
			$this->force_private_posts();

			unset( $input['force_private_posts'] );
			return $input;
		}

		if ( ! isset( $input['reset_plugin'] ) ) {
			$options = get_option( 't3i_options' );
			
			foreach ( $this->checkboxes as $id ) {
				if ( isset( $options[$id] ) && ! isset( $input[$id] ) )
					unset( $options[$id] );
			}
			
			return $input;
		}

		return false;
		
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

		// TODO return message
		/*
		echo '<h3>' . __( 'Comments Deleted', 'typo3-importer') . '</h3>';
		echo '<p>' . sprintf( __( "Successfully removed %s comments." , 'typo3-importer'), number_format( $comment_count ) ) . '</p>';
		echo '<hr />';
		*/
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

		// TODO report message
		/*
		echo '<h3>' . __( 'Prior Imports Forced to Private Status', 'typo3-importer') . '</h3>';
		echo '<p>' . sprintf( __( "Successfully updated %s TYPO3 news imports to 'Private'." , 'typo3-importer'), number_format( $post_count ) ) . '</p>';
		echo '<hr />';
		*/
	}

	function delete_import() {
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

		// TODO pass success message
		/*
		echo '<h3>' . __( 'Prior Imports Deleted', 'typo3-importer') . '</h3>';
		echo '<p>' . sprintf( __( "Successfully removed %s TYPO3 news and their related media and comments." , 'typo3-importer'), number_format( $post_count ) ) . '</p>';
		echo '<hr />';
		*/
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

		// TODO report message
		if ( false && ! $post_id ) {
			echo '<h3>' . __( 'Attachments Deleted', 'typo3-importer') . '</h3>';
			echo '<p>' . sprintf( __( "Successfully removed %s no-post attachments." , 'typo3-importer'), number_format( $attachment_count ) ) . '</p>';
			echo '<hr />';
		}
	}
	
}

$T3I_Settings					= new T3I_Settings();

function get_t3i_options( $option ) {
	$options					= get_option( 't3i_options' );
	if ( isset( $options[$option] ) ) {
		return $options[$option];
	} else {
		return false;
	}
}

function update_t3i_options( $option, $value = null ) {
	$options					= get_option( 't3i_options' );

	if ( ! is_array( $options ) ) {
		$options				= array();
	}

	$options[$option]			= $value;
	update_option( 't3i_options', $options );
}
?>