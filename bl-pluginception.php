<?php
/*
Plugin Name: BL Pluginception
Description: Tool to quickly create new plugins based on a pre-defined plugin template
Version: 0.5
Author: Ben Lobaugh
Author URI: http://ben.lobaugh.net
Text Domain: bl-pluginception
*/
define( 'BLP_TEXTDOMAIN', 'bl-pluginception' );
define( 'BLP_PLUGIN_DIR', trailingslashit( dirname( __FILE__) ) );
define( 'BLP_PLUGIN_URL', trailingslashit ( WP_PLUGIN_URL . "/" . basename( __DIR__  ) ) );
define( 'BLP_PLUGIN_FILE', BLP_PLUGIN_DIR . basename( __DIR__  ) . ".php" );
define( 'BLP_BOOTSTRAP_FILE', 'bl-bootstrap.php' );

require_once( BLP_PLUGIN_DIR . 'BLPDebugBar.class.php' );

/*
 * Default set of plugins to list in the preset plugins select menu
 * 
 * Format:
 *      Plugin Display => URI of zip archive
 */
$plugin_templates = array(
    "Otto's (otto42) Minimal Starter" => 'https://github.com/blobaugh/otto-minimal-plugin-template/zipball/master',
    "Ben Lobaugh's (blobaugh) Starter" => 'https://github.com/blobaugh/bl-plugin-template/zipball/master'
);
$plugin_templates = apply_filters( 'blp_plugin_templates', $plugin_templates );


add_action('admin_menu', 'blp_admin_add_page');
function blp_admin_add_page() {
	add_plugins_page(__('Create a New BL Plugin', BLP_TEXTDOMAIN), __('Create a New BL Plugin', BLP_TEXTDOMAIN), 'edit_plugins', BLP_TEXTDOMAIN, 'blp_options_page');
}

function blp_create_plugin() {
    // Ensure we are recieving data via post
    if ( 'POST' != $_SERVER['REQUEST_METHOD'] )
		return false;
    
    // Check nonce for security
    check_admin_referer('pluginception_nonce');
    blp_log( 'Valid Nonce' );
		
    // Remove the magic quotes
    $_POST = stripslashes_deep( $_POST );
    
    $error = false;
        
    /*
     * Verify required fields exist
     */ 
    // Ensure there is a plugin name
    if (empty($_POST['pluginception_name'])) {
        add_settings_error( 'pluginception', 'required_name',__('Plugin Name is required', BLP_TEXTDOMAIN), 'error' );
        blp_log( 'No plugin name', 'A plugin name is required. Please provide one to continue', 'warning' );
        $error = true; // Return post to re-insert values to form
    }
    
    // Ensure a sane plugin slug exists
    if ( empty($_POST['pluginception_slug'] ) ) {
        $_POST['pluginception_slug'] = sanitize_title($_POST['pluginception_name']);
    } else {
        $_POST['pluginception_slug'] = sanitize_title($_POST['pluginception_slug']);
    }
        
    // Ensure plugin folder does not already exist
    if ( file_exists(trailingslashit(WP_PLUGIN_DIR).$_POST['pluginception_slug'] ) ) {
        add_settings_error( 'pluginception', 'existing_plugin', __('That plugin appears to already exist. Use a different slug or name.', BLP_TEXTDOMAIN ), 'error' );
        blp_log( 'Plugin Exists!', 'The plugin you are trying to create already exists! Please try another slug or name', 'error' );
        $error = true;
    }
    
    // Ensure a plugin template has been chosen
    if (empty($_POST['pluginception_template'])) {
        add_settings_error( 'pluginception', 'required_name',__('Plugin Template is required. Please provide a URL to a plugin template zip archive', BLP_TEXTDOMAIN), 'error' );
        blp_log( 'No plugin template!', 'Please provide a URL to a plugin template zip archive', 'error' );
        $error = true; // Return post to re-insert values to form
    }
    
    if( $error )
        return $_POST;
       
    /*
     * If we have reached this point all the required form data has been 
     * verified as existing. 
     * 
     * Lets create a new plugin!
     */
    
    
    // Get credentials on file system
    $creds_url = wp_nonce_url('themes.php?page=bl-plugin','bl-plugin-options');
    $creds = request_filesystem_credentials( $creds_url );
    
    // Unable to get filesystem credentials!
    if (false === ( $creds ) ) {
        // if we get here, then we don't have credentials yet,
        // but have just produced a form for the user to fill in,
        // so stop processing for now
        return false;
    }
    
    // Now we have some credentials, try to get the wp_filesystem running
    if ( ! WP_Filesystem($creds) ) {
        // Unable to start wp_filesystem, our credentials were no good, ask the user for them again
        request_filesystem_credentials( $url, $method, true );
        return false;
    }
    global $wp_filesystem;
    
   
    // Fetch the plugin template archive
    $file = download_url( $_POST['pluginception_template'] );
    blp_log( 'Plugin template archive', "File downloaded to $file" );

    $res = unzip_file( $file, WP_CONTENT_DIR );
    if( !$res ) {
       add_settings_error( 'pluginception', 'unable_to_unzip',__('Unable to extract plugin template archive. Please provide the URL for a valid zip archive', BLP_TEXTDOMAIN), 'error' );
       blp_log( "Unable to extract plugin template archive", "Please provide the URL for a valid zip archive", 'error' );
    }
    

    // Remove the zip archive to reduce clutter
    $res = $wp_filesystem->delete( $file );
    if( !$res ) {
        // Did not remove zip archive
        blp_log( "Unable to remove archive", "Unable to remove zip archive at $file", 'warning' );

    }
    
    // Crude method to determine which folder what just created
    $files = glob( WP_CONTENT_DIR . "/*");
    $files = array_combine($files, array_map('filectime', $files));
    arsort($files);
    $temp_plugin_folder = key( $files );
    blp_log("Temp plugin folder: $temp_plugin_folder" ); 
    
    $new_plugin_folder = trailingslashit( WP_PLUGIN_DIR ) . $_POST['pluginception_slug'];
     blp_log("New plugin folder: $new_plugin_folder" );  

    // Attempt to move new plugin folder into place
    $res = $wp_filesystem->move( $temp_plugin_folder, $new_plugin_folder );
    if( !$res ) {
        add_settings_error( 'pluginception', 'unable_to_create',__('Unable to create new plugin folder', BLP_TEXTDOMAIN), 'error' );
        blp_log( "Unable to create plugin folder", "Failed while attempting to move extracted files from zip archive to new plugin directory", 'error' );
        return $_POST;
    }


    // Check to see if a bootstrap file exists. If it does we need to run it
    // to execute whatever needs to be done to finalize setup of the plugin
    $bootstrap_file = trailingslashit( $new_plugin_folder ) . BLP_BOOTSTRAP_FILE;
    if( file_exists( $bootstrap_file ) ) {
       require_once( $bootstrap_file );
       blp_log( 'Executing bootstrap', "Using file $bootstrap_file" );
    } else {
       blp_log( "No bootstrap file", "No new plugin bootstrap file was found. 
                 This does not necessarily indicate an error. 
                 If the template requires setup supply a bootstrap file in the archive", 'warning' ); 
    }
    
//    $plugin_template_base_file = trailingslashit( $new_plugin_folder ) . '/' . basename( $temp_plugin_folder ) . '.php';
//    $new_plugin_template_base_file = trailingslashit( $new_plugin_folder ) . '/' . $_POST['pluginception_slug'] . '.php';
//    $res = $wp_filesystem->move( $plugin_template_base_file,
//                                 $new_plugin_template_base_file );
//    if( !$res ) {
//        blp_log( "Unable to create new base file", "Could not move $plugin_template_base_file to $new_plugin_template_base_file ", 'error' );
//        $error = true;
//    }
    
    $res = $wp_filesystem->delete( $bootstrap_file );
    if( !$res ) {
        blp_log( "Unable to remove bootstrap file", trailingslashit( $new_plugin_folder ) . BLP_BOOTSTRAP_FILE, 'warning' );
        $error = true;
    }
    blp_log( 'New plugin created', 'A new plugin was successfully created', 'notice' );
    
    if( $error ) 
        return $_POST;
    
    return true;
}

function blp_options_page() {
    // If the form has been submitted 
    if( isset( $_POST['create_plugin_button'] ) ) {
        // Create the new plugin
        $results = blp_create_plugin();
    
        // Maybe activate the new plugin
        if( !is_array( $results) && $results ) {
            // If activated let the user know
            $plugslug = $_POST['pluginception_slug'].'/'.$_POST['pluginception_slug'].'.php';
            $plugeditor = admin_url('plugin-editor.php?file='.$_POST['pluginception_slug'].'%2F'.$_POST['pluginception_slug'].'.php');

            if ( null !== activate_plugin( $plugslug, '', false, true ) ) {
                    add_settings_error( 'pluginception', 'activate_plugin', __('Unable to activate the new plugin.', 'pluginception'), 'error' );
            } else {
                // Redirect to editor for new plugin after activation
                ?>
                <script type="text/javascript">
                <!--
                window.location = "<?php echo $plugeditor; ?>"
                //-->
                </script>
                <?php
                
            }
        } else {
            // Unable to get file system creds. We should not continue to show the plugin form
            // The ftp login form will show instead
            
            return;
        } // if( $results )
    } // if( isset( $_POST['create_plugin_button'] ) )
	?>
	<div class="wrap">
		<?php screen_icon(); ?>
		<h2><?php _e('Create a New Plugin', BLP_TEXTDOMAIN ); ?></h2>
		<?php settings_errors(); ?>
		<form method="post" action="">
		<?php wp_nonce_field('pluginception_nonce'); ?>
		<table class="form-table">
		<?php 
		$opts = array(
			'name' => __('Plugin Name', BLP_TEXTDOMAIN ),
			'slug' => __('Plugin Slug (optional)', BLP_TEXTDOMAIN ),
			'uri' => __('Plugin URI (optional)', BLP_TEXTDOMAIN ),
			'description' => __('Description (optional)', BLP_TEXTDOMAIN ),
			'version' => __('Version (optional)', BLP_TEXTDOMAIN ),
			'author' => __('Author (optional)', BLP_TEXTDOMAIN ),
			'author_uri' => __('Author URI (optional)', BLP_TEXTDOMAIN ),
			'license' => __('License (optional)', BLP_TEXTDOMAIN ),
			'license_uri' => __('License URI (optional)', BLP_TEXTDOMAIN ),		
		);

		foreach ($opts as $slug=>$title) {
			$value = '';
			if (!empty($results['pluginception_'.$slug])) $value = esc_attr($results['pluginception_'.$slug]);
			echo "<tr valign='top'><th scope='row'>{$title}</th><td><input class='regular-text' type='text' name='pluginception_{$slug}' id='pluginception_{$slug}' value='{$value}'></td></tr>\n";
		}
		?>
                    
                   <tr valign='top'><th scope='row'>Plugin Template</th>
                   <td><!--<input class="regular-text" type="text" name="pluginception_template" value="http://localhost/bl-plugin-template.zip" />-->
                           <select name="pluginception_template">
                   <?php
                        global $plugin_templates; echo '<br><br>'; 
                        foreach( $plugin_templates AS $k => $v ) {
                           echo '<option value="' . $v . '">' . $k . '</option>'; 
                        }
                   ?>
                           </select>
                   </td></tr>
		</table>
		<?php submit_button( __('Create a blank plugin and activate it!', BLP_TEXTDOMAIN ), 'primary', 'create_plugin_button' ); ?>
		</form>
	</div>


<?php
}

//
//if( !class_exists( 'BLPDebugBar' ) ) {
//    // Disable logging functions
//    function blp_log(){};
//    function blp_dump(){};
//}