<?php

/**
 * Flickr Shortcode Importer settings class
 *
 * @ref http://alisothegeek.com/2011/01/wordpress-settings-api-tutorial-1/
 * @since 1.0
 */
class FSI_Settings {
	
	private $sections;
	private $checkboxes;
	private $settings;
	
	/**
	 * Construct
	 *
	 * @since 1.0
	 */
	public function __construct() {
		
		// This will keep track of the checkbox options for the validate_settings function.
		$this->checkboxes				= array();
		$this->settings					= array();
		$this->get_settings();
		
		$this->sections['general']      = __( 'General Settings' , 'flickr-shortcode-importer');
		$this->sections['api']   		= __( 'Flickr API' , 'flickr-shortcode-importer');
		$this->sections['reset']        = __( 'Reset to Defaults' , 'flickr-shortcode-importer');
		$this->sections['about']        = __( 'About Flickr Shortcode Importer' , 'flickr-shortcode-importer');
		
		add_action( 'admin_menu', array( &$this, 'add_pages' ) );
		add_action( 'admin_init', array( &$this, 'register_settings' ) );

		load_plugin_textdomain( 'flickr-shortcode-importer', false, '/flickr-shortcode-importer/languages/' );
		
		if ( ! get_option( 'fsi_options' ) )
			$this->initialize_settings();
		
	}
	
	/**
	 * Add options page
	 *
	 * @since 1.0
	 */
	public function add_pages() {
		
		$admin_page = add_options_page( __( 'Flickr Shortcode Importer Options' , 'flickr-shortcode-importer'), __( '[flickr] Options' , 'flickr-shortcode-importer'), 'manage_options', 'fsi-options', array( &$this, 'display_page' ) );
		
		add_action( 'admin_print_scripts-' . $admin_page, array( &$this, 'scripts' ) );
		add_action( 'admin_print_styles-' . $admin_page, array( &$this, 'styles' ) );
		
	}
	
	/**
	 * Create settings field
	 *
	 * @since 1.0
	 */
	public function create_setting( $args = array() ) {
		
		$defaults = array(
			'id'      => 'default_field',
			'title'   => __( 'Default Field' , 'flickr-shortcode-importer'),
			'desc'    => __( 'This is a default description.' , 'flickr-shortcode-importer'),
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
		
		add_settings_field( $id, $title, array( $this, 'display_setting' ), 'fsi-options', $section, $field_args );
	}
	
	/**
	 * Display options page
	 *
	 * @since 1.0
	 */
	public function display_page() {
		
		echo '<div class="wrap">
	<div class="icon32" id="icon-options-general"></div>
	<h2>' . __( 'Flickr Shortcode Importer Options' , 'flickr-shortcode-importer') . '</h2>';
	
		echo '<form action="options.php" method="post">';
	
		settings_fields( 'fsi_options' );
		echo '<div class="ui-tabs">
			<ul class="ui-tabs-nav">';
		
		foreach ( $this->sections as $section_slug => $section )
			echo '<li><a href="#' . $section_slug . '">' . $section . '</a></li>';
		
		echo '</ul>';
		do_settings_sections( $_GET['page'] );
		
		echo '</div>
		<p class="submit"><input name="Submit" type="submit" class="button-primary" value="' . __( 'Save Changes' , 'flickr-shortcode-importer') . '" /></p>

		<p>When ready, <a href="'.get_admin_url().'tools.php?page=flickr-shortcode-importer">'.__('begin [flickr] importing', 'flickr-shortcode-importer').'</a>
		
	</form>';
	
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
	 *
	 * @since 1.0
	 */
	public function display_section() {
		// code
	}
	
	/**
	 * Description for About section
	 *
	 * @since 1.0
	 */
	public function display_about_section() {
		
		echo					<<<EOD
			<div style="width: 50%;">
				<p><img class="alignright size-medium" title="Michael in Red Square, Moscow, Russia" src="http://peimic.com/wp-content/uploads/2009/05/michael-cannon-red-square-300x2251.jpg" alt="Michael in Red Square, Moscow, Russia" width="300" height="225" /><a href="http://wordpress.org/extend/plugins/flickr-shortcode-importer/">Flickr Shortcode Importer</a> is by <a href="mailto:michael@peimic.com">Michael Cannon</a>.</p>
				<p>He's Peichi’s man, an adventurous water rat & a TYPO3 support guru who’s living simply, roaming about & smiling more.</p>
				<p>If you like this plugin, <a href="http://peimic.com/about-peimic/donate/">please donate</a>.</p>
EOD;
		$copyright				= '<p>Copyright %s <a href="http://peimic.com">Peimic.com.</a></p>';
		$copyright				= sprintf( $copyright, date( 'Y' ) );
		echo					<<<EOD
				$copyright
			</div>
EOD;
		
	}
	
	/**
	 * HTML output for text field
	 *
	 * @since 1.0
	 */
	public function display_setting( $args = array() ) {
		
		extract( $args );
		
		$options = get_option( 'fsi_options' );
		
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
				
				echo '<input class="checkbox' . $field_class . '" type="checkbox" id="' . $id . '" name="fsi_options[' . $id . ']" value="1" ' . checked( $options[$id], 1, false ) . ' /> <label for="' . $id . '">' . $desc . '</label>';
				
				break;
			
			case 'select':
				echo '<select class="select' . $field_class . '" name="fsi_options[' . $id . ']">';
				
				foreach ( $choices as $value => $label )
					echo '<option value="' . esc_attr( $value ) . '"' . selected( $options[$id], $value, false ) . '>' . $label . '</option>';
				
				echo '</select>';
				
				if ( $desc != '' )
					echo '<br /><span class="description">' . $desc . '</span>';
				
				break;
			
			case 'radio':
				$i = 0;
				foreach ( $choices as $value => $label ) {
					echo '<input class="radio' . $field_class . '" type="radio" name="fsi_options[' . $id . ']" id="' . $id . $i . '" value="' . esc_attr( $value ) . '" ' . checked( $options[$id], $value, false ) . '> <label for="' . $id . $i . '">' . $label . '</label>';
					if ( $i < count( $options ) - 1 )
						echo '<br />';
					$i++;
				}
				
				if ( $desc != '' )
					echo '<br /><span class="description">' . $desc . '</span>';
				
				break;
			
			case 'textarea':
				echo '<textarea class="' . $field_class . '" id="' . $id . '" name="fsi_options[' . $id . ']" placeholder="' . $std . '" rows="5" cols="30">' . wp_htmledit_pre( $options[$id] ) . '</textarea>';
				
				if ( $desc != '' )
					echo '<br /><span class="description">' . $desc . '</span>';
				
				break;
			
			case 'password':
				echo '<input class="regular-text' . $field_class . '" type="password" id="' . $id . '" name="fsi_options[' . $id . ']" value="' . esc_attr( $options[$id] ) . '" />';
				
				if ( $desc != '' )
					echo '<br /><span class="description">' . $desc . '</span>';
				
				break;
			
			case 'text':
			default:
		 		echo '<input class="regular-text' . $field_class . '" type="text" id="' . $id . '" name="fsi_options[' . $id . ']" placeholder="' . $std . '" value="' . esc_attr( $options[$id] ) . '" />';
		 		
		 		if ( $desc != '' )
		 			echo '<br /><span class="description">' . $desc . '</span>';
		 		
		 		break;
		 	
		}
		
	}
	
	/**
	 * Settings and defaults
	 * 
	 * @since 1.0
	 */
	public function get_settings() {
		
		/* General Settings
		===========================================*/
		
		$this->settings['set_caption'] = array(
			'section' => 'general',
			'title'   => __( 'Set Captions' , 'flickr-shortcode-importer'),
			'desc'    => __( 'Uses media title as the caption.' , 'flickr-shortcode-importer'),
			'type'    => 'checkbox',
			'std'     => 0 // Set to 1 to be checked by default, 0 to be unchecked by default.
		);
		
		$this->settings['import_flickr_sourced_tags'] = array(
			'section' => 'general',
			'title'   => __( 'Import Flickr-sourced A/IMG tags' , 'flickr-shortcode-importer'),
			'desc'    => __( 'Converts Flickr-sourced A/IMG tags to [flickr] and then proceeds with import.' , 'flickr-shortcode-importer'),
			'type'    => 'checkbox',
			'std'     => 1 // Set to 1 to be checked by default, 0 to be unchecked by default.
		);
		
		$this->settings['set_featured_image'] = array(
			'section' => 'general',
			'title'   => __( 'Set Featured Image' , 'flickr-shortcode-importer'),
			'desc'    => __( 'Set the first [flickr] or [flickrset] image found as the Featured Image. Will not replace the current Featured Image of a post.' , 'flickr-shortcode-importer'),
			'type'    => 'checkbox',
			'std'     => 1 // Set to 1 to be checked by default, 0 to be unchecked by default.
		);
		
		$this->settings['force_set_featured_image'] = array(
			'section' => 'general',
			'title'   => __( 'Force Set Featured Image' , 'flickr-shortcode-importer'),
			'desc'    => __( 'Set the Featured Image even if one already exists for a post.', 'flickr-shortcode-importer'),
			'type'    => 'checkbox',
			'std'     => 0 // Set to 1 to be checked by default, 0 to be unchecked by default.
		);
		
		$this->settings['remove_first_flickr_shortcode'] = array(
			'section' => 'general',
			'title'   => __( 'Remove First Flickr Shortcode' , 'flickr-shortcode-importer'),
			'desc'    => __( 'Remove the first [flickr] from post content? If you use Featured Images, this will help prevent duplicate images in your post.' , 'flickr-shortcode-importer'),
			'type'    => 'checkbox',
			'std'     => 1 // Set to 1 to be checked by default, 0 to be unchecked by default.
		);
		
		$this->settings['make_nice_image_title'] = array(
			'section' => 'general',
			'title'   => __( 'Make Nice Image Title?' , 'flickr-shortcode-importer'),
			'desc'    => __( "If the image title is a filename and the image is part of a Flickr set, the Flickr set title plus a numeric suffix will be used instead." , 'flickr-shortcode-importer'),
			'type'    => 'checkbox',
			'std'     => 1 // Set to 1 to be checked by default, 0 to be unchecked by default.
		);
		
		$this->settings['default_image_alignment'] = array(
			'section' => 'general',
			'title'   => __( 'Default Image Alignment' , 'flickr-shortcode-importer'),
			'desc'    => __( 'Default alignment of image displayed in post when no alignment is found.' , 'flickr-shortcode-importer'),
			'type'    => 'select',
			'std'     => 'left',
			'choices' => array(
				'none'		=> 'None',
				'left'		=> 'Left',
				'center'	=> 'Center',
				'right'		=> 'Right',
			)
		);
		
		$this->settings['default_image_size'] = array(
			'section' => 'general',
			'title'   => __( 'Default Image Size' , 'flickr-shortcode-importer'),
			'desc'    => __( 'Default size of image displayed in post when no size is found.' , 'flickr-shortcode-importer'),
			'type'    => 'select',
			'std'     => 'medium',
			'choices' => array(
				'thumbnail'	=> 'Small',
				'medium'	=> 'Medium',
				'large'		=> 'Large',
				'full'		=> 'Original',
			)
		);
		
		$this->settings['default_a_tag_class'] = array(
			'title'   => __( 'Default A Tag Class' , 'flickr-shortcode-importer'),
			'desc'    => __( "Useful for lightbox'ing." , 'flickr-shortcode-importer'),
			'std'     => '',
			'type'    => 'text',
			'section' => 'general'
		);
		
		$this->settings['posts_to_import'] = array(
			'title'   => __( 'Posts to Import' , 'flickr-shortcode-importer'),
			'desc'    => __( "A CSV list of post ids to import, like '1,2,3'." , 'flickr-shortcode-importer'),
			'std'     => '',
			'type'    => 'text',
			'section' => 'general'
		);
		
		$this->settings['skip_importing_post_ids'] = array(
			'title'   => __( 'Skip Importing Posts' , 'flickr-shortcode-importer'),
			'desc'    => __( "A CSV list of post ids to not import, like '1,2,3'." , 'flickr-shortcode-importer'),
			'std'     => '',
			'type'    => 'text',
			'section' => 'general'
		);
		
		$this->settings['limit'] = array(
			'title'   => __( 'Import Limit' , 'flickr-shortcode-importer'),
			'desc'    => __( 'Useful for testing import on a limited amount of posts. 0 or blank means unlimited.' , 'flickr-shortcode-importer'),
			'std'     => '',
			'type'    => 'text',
			'section' => 'general'
		);
		
		$this->settings['flickr_api_key'] = array(
			'title'   => __( 'Flickr API Key' , 'flickr-shortcode-importer'),
			'desc'    => __( '<a href="http://www.flickr.com/services/api/">Flickr API Documentation</a>' , 'flickr-shortcode-importer'),
			'std'     => '9f9508c77dc554c1ee7fdc006aa1879e',
			'type'    => 'text',
			'section' => 'api'
		);
		
		$this->settings['flickr_api_secret'] = array(
			'title'   => __( 'Flickr API Secret' , 'flickr-shortcode-importer'),
			'desc'    => __( '' , 'flickr-shortcode-importer'),
			'std'     => 'e63952df7d02cc03',
			'type'    => 'text',
			'section' => 'api'
		);
		
		// Here for reference
		if ( false ) {
		$this->settings['example_text'] = array(
			'title'   => __( 'Example Text Input' , 'flickr-shortcode-importer'),
			'desc'    => __( 'This is a description for the text input.' , 'flickr-shortcode-importer'),
			'std'     => 'Default value',
			'type'    => 'text',
			'section' => 'general'
		);
		
		$this->settings['example_textarea'] = array(
			'title'   => __( 'Example Textarea Input' , 'flickr-shortcode-importer'),
			'desc'    => __( 'This is a description for the textarea input.' , 'flickr-shortcode-importer'),
			'std'     => 'Default value',
			'type'    => 'textarea',
			'section' => 'general'
		);
		
		$this->settings['example_checkbox'] = array(
			'section' => 'general',
			'title'   => __( 'Example Checkbox' , 'flickr-shortcode-importer'),
			'desc'    => __( 'This is a description for the checkbox.' , 'flickr-shortcode-importer'),
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
			'title'   => __( 'Example Radio' , 'flickr-shortcode-importer'),
			'desc'    => __( 'This is a description for the radio buttons.' , 'flickr-shortcode-importer'),
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
			'title'   => __( 'Example Select' , 'flickr-shortcode-importer'),
			'desc'    => __( 'This is a description for the drop-down.' , 'flickr-shortcode-importer'),
			'type'    => 'select',
			'std'     => '',
			'choices' => array(
				'choice1' => 'Other Choice 1',
				'choice2' => 'Other Choice 2',
				'choice3' => 'Other Choice 3'
			)
		);
		}
		
		/* Appearance
		===========================================*/
		
		$this->settings['header_logo'] = array(
			'section' => 'appearance',
			'title'   => __( 'Header Logo' , 'flickr-shortcode-importer'),
			'desc'    => __( 'Enter the URL to your logo for the plugin header.' , 'flickr-shortcode-importer'),
			'type'    => 'text',
			'std'     => ''
		);
		
		$this->settings['favicon'] = array(
			'section' => 'appearance',
			'title'   => __( 'Favicon' , 'flickr-shortcode-importer'),
			'desc'    => __( 'Enter the URL to your custom favicon. It should be 16x16 pixels in size.' , 'flickr-shortcode-importer'),
			'type'    => 'text',
			'std'     => ''
		);
		
		$this->settings['custom_css'] = array(
			'title'   => __( 'Custom Styles' , 'flickr-shortcode-importer'),
			'desc'    => __( 'Enter any custom CSS here to apply it to your plugin.' , 'flickr-shortcode-importer'),
			'std'     => '',
			'type'    => 'textarea',
			'section' => 'appearance',
			'class'   => 'code'
		);
				
		/* Reset
		===========================================*/
		
		$this->settings['reset_plugin'] = array(
			'section' => 'reset',
			'title'   => __( 'Reset plugin' , 'flickr-shortcode-importer'),
			'type'    => 'checkbox',
			'std'     => 0,
			'class'   => 'warning', // Custom class for CSS
			'desc'    => __( 'Check this box and click "Save Changes" below to reset plugin options to their defaults.' , 'flickr-shortcode-importer')
		);
		
	}
	
	/**
	 * Initialize settings to their default values
	 * 
	 * @since 1.0
	 */
	public function initialize_settings() {
		
		$default_settings = array();
		foreach ( $this->settings as $id => $setting ) {
			if ( $setting['type'] != 'heading' )
				$default_settings[$id] = $setting['std'];
		}
		
		update_option( 'fsi_options', $default_settings );
		
	}
	
	/**
	* Register settings
	*
	* @since 1.0
	*/
	public function register_settings() {
		
		register_setting( 'fsi_options', 'fsi_options', array ( &$this, 'validate_settings' ) );
		
		foreach ( $this->sections as $slug => $title ) {
			if ( $slug == 'about' )
				add_settings_section( $slug, $title, array( &$this, 'display_about_section' ), 'fsi-options' );
			else
				add_settings_section( $slug, $title, array( &$this, 'display_section' ), 'fsi-options' );
		}
		
		$this->get_settings();
		
		foreach ( $this->settings as $id => $setting ) {
			$setting['id'] = $id;
			$this->create_setting( $setting );
		}
		
	}
	
	/**
	* jQuery Tabs
	*
	* @since 1.0
	*/
	public function scripts() {
		
		wp_print_scripts( 'jquery-ui-tabs' );
		
	}
	
	/**
	* Styling for the plugin options page
	*
	* @since 1.0
	*/
	public function styles() {
		
		wp_register_style( 'fsi-admin', plugins_url( 'settings.css', __FILE__ ) );
		wp_enqueue_style( 'fsi-admin' );
		
	}
	
	/**
	* Validate settings
	*
	* @since 1.0
	*/
	public function validate_settings( $input ) {
		
		// TODO validate for integer CSV
		// posts_to_import
		// skip_importing_post_ids

		if ( ! isset( $input['reset_plugin'] ) ) {
			$options = get_option( 'fsi_options' );
			
			foreach ( $this->checkboxes as $id ) {
				if ( isset( $options[$id] ) && ! isset( $input[$id] ) )
					unset( $options[$id] );
			}
			
			return $input;
		}

		return false;
		
	}
	
}

$FSI_Settings					= new FSI_Settings();

function fsi_options( $option ) {
	$options					= get_option( 'fsi_options' );
	if ( isset( $options[$option] ) )
		return $options[$option];
	else
		return false;
}
?>