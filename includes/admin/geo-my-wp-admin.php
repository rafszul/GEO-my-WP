<?php

if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

/**
 * GMW_Admin class.
 */
class GMW_Admin {

	/**
	 * __construct function.
	 *
	 * @access public
	 * @return void
	 */
	public function __construct() {
		
		include_once( 'geo-my-wp-addons.php' );
		include_once( 'geo-my-wp-settings.php' );
		include_once( 'geo-my-wp-forms.php' );
		
		$this->addons         = get_option( 'gmw_addons' );
		$this->settings       = get_option( 'gmw_options' );
		$this->addons_page    = new GMW_Addons();
		$this->settings_page  = new GMW_Settings();
		$this->forms_page     = new GMW_Forms();	
		
		add_action( 'admin_menu', array( $this, 'admin_menu' ), 12 );
		add_action( 'admin_enqueue_scripts', array( $this, 'admin_enqueue_scripts' ) );
		
		add_filter( 'plugin_action_links', array( $this, 'addons_action_links' ), 10, 2 );
	}
	
	/**
	 * add gmw action links in plugins page
	 * @param $links
	 * @param $file
	 */
	public function addons_action_links( $links, $file ) {
		static $this_plugin;
		
		if ( $file == 'geo-my-wp/geo-my-wp.php' ) {
			
			$settings_link = '<a href="' . get_bloginfo('wpurl') . '/wp-admin/admin.php?page=gmw-settings">'.__( 'Settings', 'GMW' ).'</a>';
			
		}
		
		$deactivate_links = array();
		$deactivate_links = apply_filters( 'gmw_plugin_action_links', $deactivate_links );
	
		foreach ( $deactivate_links as $addon => $link ) {
		
			if ( $file == $link ) {
				
				if ( isset( $this->addons[$addon] ) && $this->addons[$addon] == 'active' )
					$links['deactivate'] = '<a href="' . get_bloginfo('wpurl') . '/wp-admin/admin.php?page=gmw-add-ons">'.__( 'Deactivate from add-ons page first', 'GMW' ).'</a>';
				else 
					array_unshift( $links,  '<a href="' . get_bloginfo('wpurl') . '/wp-admin/admin.php?page=gmw-add-ons">'.__( 'GMW Add-ons', 'GMW' ).'</a>' );
			
			}
		}
		return $links;
	}
	
	/**
	 * admin_enqueue_scripts function.
	 *
	 * @access public
	 * @return void
	 */
	public function admin_enqueue_scripts() {
		wp_register_script( 'google-maps', 'http://maps.googleapis.com/maps/api/js?key='.$this->settings['general_settings']['google_api'].'&sensor=false&region='.$this->settings['general_settings']['country_code'], array(), false ) ;
		wp_enqueue_style( 'gmw-style-admin', GMW_URL . '/assets/css/style-admin.css' );
	}

	/**
	 * admin_menu function.
	 *
	 * @access public
	 * @return void
	 */
	public function admin_menu() {
			
		add_menu_page('GEO my WP', 'GEO my WP', 'manage_options', 'gmw-add-ons', array( $this->addons_page, 'output' ), '', 66 );
		add_submenu_page( 'gmw-add-ons', __( 'Add-ons','GMW' ), __( 'Add-ons','GMW' ), 'manage_options', 'gmw-add-ons', array( $this->addons_page, 'output' ) );
		add_submenu_page( 'gmw-add-ons', __( 'GEO my WP Settings','GMW' ), __( 'Settings','GMW' ), 'manage_options', 'gmw-settings', array( $this->settings_page, 'output' ) );
		add_submenu_page( 'gmw-add-ons', __( 'Forms', 'GMW' ), __( 'Forms','GMW' ), 'manage_options', 'gmw-forms', array( $this->forms_page, 'output' ) );
		
		$menu_items = array();
		
		//hook your add-on's menu item
		$menu_items = apply_filters( 'gmw_admin_menu_items', $menu_items );
		
		foreach ( $menu_items as $item ) {
			add_submenu_page( 'gmw-add-ons', $item['page_title'], $item['menu_title'], $item['capability'], $item['menu_slug'], $item['callback_function'] );
		}
		
	}
	
	static public function gmw_credits() {
		
		$output  =	'<div class="gmw-credits">';
		$output .=          '<img src="'.GMW_IMAGES .'/gmw-logo.png" />';
		$output .=          '<div style="display: inline-block;"><a href="http://www.geomywp.com" target="_blank">'.__( 'GEO my WP developed by Eyal Fitoussi - Version' ,'GMW').' ' .GMW_VERSION.'</a></div>';
                $output .=          '<div>';
                $output .=              '<a class="gmw-credits-facebook-button" href="https://www.facebook.com/geomywp" target="_blank">Facebook</a>';
                $output .=              '<a class="gmw-credits-twitter-button" href="https://twitter.com/GEOmyWP" target="_blank">Twitter</a>';
                $output .=              '<a class="gmw-credits-email-button" href="mailto:info@geomywp.com" title="Email" target="_blank">Email</a>';
                $output .=              '<div style="float:left;margin-top: 2px;" class="fb-like" data-href="https://www.facebook.com/geomywp" data-layout="button_count" data-action="like" data-show-faces="true" data-share="true"></div>';
                $output .=              '<span style="margin: 2px 4px 5px 4px;float:left;"><a href="https://twitter.com/GEOmyWP" class="twitter-follow-button" data-show-count="false" data-show-screen-name="false" style="margin-top:2px">Follow @GEOmyWP</a></span>';
                $output .=              '<a class="gmw-credits-addons-button" href="http://geomywp.com/add-ons" target="_blank">Add-ons</a>';
                $output .=          '</div>';             
		$output .=	'</div>';
		
                ?>
                <div id="fb-root"></div>
                <script>
                    !function(d,s,id){var js,fjs=d.getElementsByTagName(s)[0],p=/^http:/.test(d.location)?'http':'https';if(!d.getElementById(id)){js=d.createElement(s);js.id=id;js.src=p+'://platform.twitter.com/widgets.js';fjs.parentNode.insertBefore(js,fjs);}}(document, 'script', 'twitter-wjs');    
                    (function(d, s, id) {
                      var js, fjs = d.getElementsByTagName(s)[0];
                      if (d.getElementById(id)) return;
                      js = d.createElement(s); js.id = id;
                      js.src = "//connect.facebook.net/en_US/all.js#xfbml=1&appId=150962325088686";
                      fjs.parentNode.insertBefore(js, fjs);
                    }(document, 'script', 'facebook-jssdk'));
                </script>
                <?php
                
		return $output;
		
	}

}
new GMW_Admin();
?>