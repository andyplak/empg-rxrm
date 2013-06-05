<?php
/*
Plugin Name: Events Manager Pro - RealEx Remote Gateway
Plugin URI: http://wp-events-plugin.com
Description: RealEx Remote payment gateway pluging for Events Manager Pro
Version: 1.3
Depends: Events Manager Pro
Author: Andy Place
Author URI: http://www.andyplace.co.uk

Change Log:

1.3 	Bugfix - mail notifications not sent when RealMPI enabled

1.2     Tooltip added for CVV number - 24/03/2013

1.1 	Updated to work with EM PRO 2.3 with multiple bookings - 13/03/2013
		http://wp-events-plugin.com/documentation/multiple-booking-mode/

1.0 	May 2012

*/

class EM_Pro_RealEx_Remote {

	function EM_Pro_RealEx_Remote() {
		global $wpdb;
		//Set when to run the plugin : after EM is loaded.
		add_action( 'plugins_loaded', array(&$this,'init'), 101 );
	}

	function init() {
		//add-ons
		include('add-ons/gateways/gateway.realex.remote.php');
	}

}

// Start plugin
global $EM_Pro_RealEx_Remote;
$EM_Pro_RealEx_Remote = new EM_Pro_RealEx_Remote();