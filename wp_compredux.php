<?php
/*
Plugin Name: wp_compredux
Plugin Script: wp_compredux.php
Plugin URI: http://www.collegedegrees.com/affiliate-program/ 
Description: (College Degrees remote client for wordpress)
Version: 0.1
Author: Jesse Greathouse
Author URI: http://www.collegedegrees.com

=== RELEASE NOTES ===
2011-04-07 - v1.0 - plugin for wordpress
*/

register_activation_hook(__FILE__, 'wp_compredux_add_defaults');
register_uninstall_hook(__FILE__, 'wp_compredux_delete_plugin_options');

if ( is_admin() ){ // admin actions
  add_action( 'admin_menu', 'wp_compredux_plugin_menu' );
  add_action( 'admin_init', 'wp_compredux_register_settings' );
} else {
  // non-admin enqueues, actions, and filters
}

$uri = $_SERVER['REQUEST_URI'];
$pieces = explode('/', $uri);
if (count($pieces)>0) {
    include_once(dirname(__FILE__).'/library.php');
    $GLOBALS['wp_compredux'] = wp_get_compredux();
    if ("/".$pieces[1] == $GLOBALS['wp_compredux']->getController()) {
        
        $GLOBALS['wp_compredux']->request();
        if (!$GLOBALS['wp_compredux']->isType('html')) {
            $GLOBALS['wp_compredux']->initHeaders();
            echo $GLOBALS['wp_compredux']->getContent();
            exit();
        }

        add_action('send_headers', 'wp_compredux_sendheaders');
        add_filter('wp_title', 'wp_compredux_title');
        add_action('wp_head', 'wp_compredux_addhead');
        add_filter('the_content', 'wp_compredux_filter');
        add_action('template_redirect', 'wp_compredux_custom' );
    } else {
        unset($GLOBALS['wp_compredux']);
    }
}

function wp_compredux_plugin_menu() 
{
    add_options_page('compredux Plugin Options', 'compredux Plugin', 'manage_options', 'wp-compredux-settings-group', 'wp_compredux_options');
}

function wp_compredux_options() 
{
?>
<div class="wrap">
<h2>compredux Plugin Settings</h2>

<form method="post" action="options.php">
    <?php settings_fields( 'wp-compredux-settings-group' ); ?>
    <table class="form-table">
        <tr valign="top">
        <th scope="row">Remote Server</th>
        <td><input type="text" name="wp_compredux_server" value="<?php echo get_option('wp_compredux_server'); ?>" /></td>
        </tr>

        <tr valign="top">
        <th scope="row">Referer </th>
        <td><input type="text" name="wp_compredux_referer" value="<?php echo get_option('wp_compredux_referer'); ?>" /></td>
        </tr>
         
        <tr valign="top">
        <th scope="row">Controller &#40;start with &#47;&#41;</th>
        <td><input type="text" name="wp_compredux_controller" value="<?php echo get_option('wp_compredux_controller'); ?>" /></td>
        </tr>

        <tr valign="top">
        <th scope="row">Cache dir</th>
        <td><input type="text" name="wp_compredux_home_dir" value="<?php echo get_option('wp_compredux_home_dir'); ?>" /></td>
        </tr>

        <tr valign="top">
        <th scope="row">Custom CSS</th>
        <td>
        <textarea name="wp_compredux_css" rows="20" cols="50"><?php echo get_option('wp_compredux_css'); ?></textarea>
        </td>
        </tr>
    </table>
    
    <p class="submit">
    <input type="submit" class="button-primary" value="<?php _e('Save Changes') ?>" />
    </p>

</form>
</div>
<?php }

function wp_compredux_register_settings() 
{
    $option_group = 'wp-compredux-settings-group';
    register_setting( $option_group, 'wp_compredux_server');
    register_setting( $option_group, 'wp_compredux_referer');
    register_setting( $option_group, 'wp_compredux_controller');
    register_setting( $option_group, 'wp_compredux_home_dir');
    register_setting( $option_group, 'wp_compredux_css');
}

function wp_compredux_delete_plugin_options() 
{
	delete_option('wp_compredux_server');
	delete_option('wp_compredux_referer');
	delete_option('wp_compredux_controller');
	delete_option('wp_compredux_home_dir');
	delete_option('wp_compredux_css');
}

function wp_compredux_add_defaults() 
{
    add_option('wp_compredux_server', 'https://schools.collegedegrees.com');
    add_option('wp_compredux_referer', 'http://'.$_SERVER['SERVER_NAME'].'/');
    add_option('wp_compredux_controller', '/compredux');
    add_option('wp_compredux_home_dir', realpath(dirname(__FILE__)));
    add_option('wp_compredux_css', '');
}

function wp_get_compredux($url = null) 
{
    global $wpdb;
    // get your parameter values from the database
    if ($url != "" || $url != null) {
        $options['curl_options']['CURLOPT_URL'] = $url;
    }
    $wp_compredux_nb_widgets = get_option('wp_compredux_nb_widgets');
    $options = array();
    $options['client_hostname'] = $_SERVER['SERVER_NAME'];
    if (($server = get_option('wp_compredux_server')) != "") {
        $options['server'] = $server;
    }
    if (($referer = get_option('wp_compredux_referer')) != "") {
        $options['curl_options']['CURLOPT_REFERER'] = $referer;
    }
    if (($controller = get_option('wp_compredux_controller')) != "") {
        $options['controller'] = $controller;
    }
    if (($home_dir = get_option('wp_compredux_home_dir')) != "") {
        $options['home_dir'] = $home_dir;
    }
    $wp_compredux = new compredux($options);
    return $wp_compredux;
}

function wp_compredux_addhead() 
{
    echo $GLOBALS['wp_compredux']->getContent(array(
        'head base', 
        'head link', 
        'head script'
    ));
}

function wp_compredux_css() 
{
    $output = '<style type="text/css">'."\n";
    $output .= get_option('wp_compredux_css');
    $output .= '</style >'."\n";
    return $output;
}

function wp_compredux_title($title = null) 
{
    return strip_tags($GLOBALS['wp_compredux']->getContent('title'));
}

function wp_compredux_fetch($selector = null, $exclude = null) 
{
    //script here
    $output = '';
    if (isset($GLOBALS['wp_compredux'])) {
        if (($selector === null) || (is_array($selector) && empty($selector))) {
            $selector = array('#content','body script');
        }

        if (($exclude === null) || (is_array($exclude) && empty($exclude))) {
            $exclude = array('.clearfix');
        }
        
        $output .= $GLOBALS['wp_compredux']->getContent($selector, $exclude);
    }
    return $output;
}

function wp_compredux_filter($text = null) 
{
    return wp_compredux_fetch();
}

function wp_compredux_sendheaders()
{
    $GLOBALS['wp_compredux']->initHeaders();
}

function wp_compredux_custom() 
{
	status_header( 200 );
	apply_filters('handle_404',false);
    remove_filter('template_redirect', 'redirect_canonical');
    get_header();
    echo wp_compredux_css();
    echo wp_compredux_filter();
    get_sidebar();
    get_footer();
    exit();
    
}

