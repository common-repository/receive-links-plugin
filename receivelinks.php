<?php
/*
Plugin Name: Receive Links Plugin
Plugin URI: http://wordpress.org/extend/plugins/receive-links-plugin/
Description: A plugin to include Receive Links Client on a Wordpress widget
Author: Receive Links Support Staff 
Version: 2.4.4
*/

/*  
    Copyright 2010  Receive Links Support Staff  (email : support@receivelinks.com)

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

// TODO: Add Debug to script to find problems with installs

if (!defined('RLPLUGINDIR')) {
	define('RLPLUGINDIR',dirname(__FILE__));
}

if (!function_exists('receivelinks_randomid')){
	function receivelinks_randomid(){
		return substr(md5(time()),0,14).rand(10,99);
	}
}

if (!function_exists('receivelinks_plugin_activate')){
	function receivelinks_plugin_activate(){
		/* 
		This will be ran when the plugin is activated in the admin panel.
		This should create and chmod the txt data file
		We also need to generate a random ID for the datafile
		So no one will be able to see whats in the data is unless they know the filename
		*/
		$siteurl =  get_option('siteurl');
		$purl = parse_url($siteurl);
		if ($purl['path'] != '/'){
		  $plugindir = $purl['path'].'/'.RLPLUGINDIR;
		  $siteurl = "http://".$purl['host'];
		} else {
			$plugindir = RLPLUGINDIR;	
		}
		$id = get_option("receivelinks_id");
		if (strlen($id) != 16){
			$id = receivelinks_randomid();
			update_option("receivelinks_id", $id);
		}
		
		if (!is_file(trailingslashit(RLPLUGINDIR).'rl'.$id.'data.txt')) {
			$handle = fopen(trailingslashit(RLPLUGINDIR).'rl'.$id.'data.txt', 'x+');
			chmod(trailingslashit(RLPLUGINDIR).'rl'.$id.'data.txt', 0666);
		}
		
		// copying template client code to new file name and replaceing key items
		if (!is_file(trailingslashit(RLPLUGINDIR).'rl'.$id.'client.php')) {
			$handle = fopen(trailingslashit(RLPLUGINDIR).'rlwordpressclient.php', 'r');
			$client_data = "";
			while(!feof($handle)){
				$client_data .= fread($handle,1024);
			}
			fclose($handle);
			
			$client_data = str_replace(array('uniquereid', 'client_folder'),array($id, trailingslashit(RLPLUGINDIR)),$client_data);
			
			$handle = fopen(trailingslashit(RLPLUGINDIR).'rl'.$id.'client.php', 'x+');
			fwrite($handle,$client_data);
			fclose($handle);
			chmod(trailingslashit(RLPLUGINDIR).'rl'.$id.'client.php', 0644);
		} else {
			chmod(trailingslashit(RLPLUGINDIR).'rl'.$id.'client.php', 0644);
		}

		$pos = strpos($plugindir,PLUGINDIR);
		if ($pos){
			$folder = substr($plugindir,$pos);
		}

		// sending an update to the Receive Links server to update the clients random id so it will know where to look for it.
		$fp = fsockopen("www.receivelinks.com", 80, $errno, $errstr, 30);
		if ($fp){
		    $out = "GET /wordpress_plugin_comm.php?url=".$siteurl."&id=".$id."&folder=".$folder." HTTP/1.1\r\n";
		    $out .= "Host: www.receivelinks.com\r\n";
		    $out .= "Connection: Close\r\n\r\n";
		    fwrite($fp, $out);
		    fclose($fp);
		}
	}
}

if (!function_exists('receivelinks_plugin_getlinks')){
	function receivelinks_plugin_getlinks(){
		$id = get_option("receivelinks_id");
		include (trailingslashit(RLPLUGINDIR).'rl'.$id.'client.php');
		echo RECEIVE_LINKS_GetAds();
	}
}

if (!function_exists('receivelinks_plugin_getlinks_footer')){
	function receivelinks_plugin_getlinks_footer(){
		if(!is_active_widget('receivelinks_widget')) { 
			receivelinks_plugin_getlinks();
		}
	}
}

if (!function_exists('receivelinks_widget')){
	function receivelinks_widget($args){
		extract($args);
		$options = get_option("receivelinks_options");
		if (!is_array( $options )){
			$options = array('title' => 'Links');
  		}
  		
		// translate if exists using function from WPML
		if (function_exists('icl_t')){
			$options['title'] = icl_t('receivelinks_options', 'receivelinks_widgetTitle', ($options['title']));
		}
		// end wpml
		$before_widget = str_replace(array("receivelinks","receive-links"),array("",""),$before_widget);
		echo $before_widget;
		
		// header yes no
		if(!isset($options['h_yesno'])) {
			echo $before_title.($options['title']).$after_title;
		}
		receivelinks_plugin_getlinks();
		echo $after_widget;
	}
}

if (!function_exists('receivelinks_widget_init')){
	function receivelinks_widget_init(){
		if (function_exists('register_sidebar_widget')) {
			register_sidebar_widget(__('Receive Links'), 'receivelinks_widget');
			register_widget_control(   'Receive Links', 'receivelinks_widget_control', 200, 200 );    
		}
	}
}


if (!function_exists('receivelinks_widget_control')){
	function receivelinks_widget_control(){
		$options = get_option("receivelinks_options");
		if (!is_array( $options )){
			$options = array('title' => 'My Title');
  		}
		if ($_POST['receivelinks_widget-Submit']){
			$options['title'] = htmlspecialchars($_POST['receivelinks_widgetTitle']);
			// included header yes no
			$options['h_yesno'] = $_POST['receivelinks_title_yesno'];
			update_option("receivelinks_options", $options);
		}
		echo '<p>';
		echo '<label for="receivelinks_widgetTitle">Widget Title: </label>';
		echo '<input type="text" id="receivelinks_widgetTitle" name="receivelinks_widgetTitle" value="'.$options['title'].'" />';
		echo "<br><input id='receivelinks_title_yesno' name='receivelinks_title_yesno' type='checkbox' value='1'";
		checked('1' ,$options['h_yesno']);
		echo "' /> Click here to remove header";
		echo '<input type="hidden" id="receivelinks_widget-Submit" name="receivelinks_widget-Submit" value="1" />';
		echo '</p>';
	}
}

if (!function_exists('receivelinks_admin_add_page')){
	function receivelinks_admin_add_page() {
		add_options_page('Manage Receive Links Plugin', 'Receive Links', 'manage_options', 'receive-links-plugin', 'receivelinks_plugin_options_page');
	}
}

if (!function_exists('receivelinks_plugin_options_page')){
	function receivelinks_plugin_options_page() {
		echo '<div>';
		echo '<h2>Receive Links Plugin</h2>';
		echo 'Options For Receive Links Plugin.';
		echo '<form action="options.php" method="post">';
		settings_fields('receivelinks_options');
		do_settings_sections('receivelinks_plugin');
		echo '<input name="Submit" type="submit" value="'; echo esc_attr_e('Save Changes'); echo'"/>';
		echo '</form></div>';
	}
}	

if (!function_exists('receivelinks_admin_init')){
	function receivelinks_admin_init(){
		register_setting( 'receivelinks_options', 'receivelinks_options', 'receivelinks_options_validate' );
		add_settings_section('receivelinks_widget', 'Widget Settings', 'receivelinks_widget_section_text', 'receivelinks_plugin');
		add_settings_field('receivelinks_widget_text_string', 'Widget Title', 'receivelinks_widget_setting_string', 'receivelinks_plugin', 'receivelinks_widget');
	}
}

if (!function_exists('receivelinks_widget_section_text')){
	function receivelinks_widget_section_text() {
		echo '<p>Set your Widget Options:</p>';
	}
}

if (!function_exists('receivelinks_widget_setting_string')){
	function receivelinks_widget_setting_string() {
		$options = get_option('receivelinks_options');
		if (!is_array( $options )){
			$options = array('title' => 'My Title');
  		}
		echo '<a href="http://www.receivelinks.com/guidelines.php" target="_blank">Guideline</a>: 14. Ads are not to be labeled with the name of the Network or any other Exchange Network name. Example "ReceiveLinks links" is not allowed.<br>';
		echo "<input id='receivelinks_title_text_string' name='receivelinks_options[title]' size='40' type='text' value='".$options['title']."' />";
		echo "<br><input id='receivelinks_title_yesno' name='receivelinks_options[h_yesno]' type='checkbox' value='1'";
		checked('1' ,$options['h_yesno']);
		echo "' />";
		echo 'Check this box if you wish to have NO header for tight integration with above widget. Example with links from Blogrol or other widgets.<br>';
	}
}

if (!function_exists('receivelinks_options_validate')){
	function receivelinks_options_validate($input) {
		// TODO: use this to enforce Guideline 14 later if needed
		$newinput['title'] = trim($input['title']);
		$newinput['h_yesno'] = $input['h_yesno'];
		// register variable just in case to translate and display using WPML
		// if no multilanguage is used no harm done but its registered should later WPML be used 
		if (function_exists('icl_register_string')){
			icl_register_string('receivelinks_options', 'receivelinks_widgetTitle', $newinput['title']);
		}
		return $newinput;
	}
}

if (!function_exists('receivelinks_settings_link')){
	function receivelinks_settings_link($links) {  
	  $settings_link = '<a href="options-general.php?page=receive-links-plugin">Settings</a>';  
	  array_unshift($links, $settings_link);  
	  return $links;  
	}  
}

$plugin = plugin_basename(__FILE__);  
add_filter("plugin_action_links_$plugin", 'receivelinks_settings_link' );

add_action('admin_menu', 'receivelinks_admin_add_page');
add_action('admin_init', 'receivelinks_admin_init');

register_activation_hook(__FILE__, 'receivelinks_plugin_activate');

add_action("plugins_loaded", "receivelinks_widget_init");
add_action('wp_footer', 'receivelinks_plugin_getlinks_footer');
?>