<?php

class EM_Gateway_Realex_Remote extends EM_Gateway {

	var $gateway = 'realex_remote';
	var $title = 'RealEx Remote';
	var $supports_multiple_bookings = true;
	var $status = 4;
	var $status_txt = 'Processing (RealEx Remote)';
	var $button_enabled = false; //we can's use a button here

	// RealEx specific

	/**
	 * Sets up gateaway and adds relevant actions/filters
	 */
	function __construct() {
		parent::__construct();
		if($this->is_active()) {
			//Force SSL for booking submissions, since we have card info
			if(get_option('em_'.$this->gateway.'_mode') == 'live'){ //no need if in test mode
				add_filter('em_wp_localize_script',array(&$this,'em_wp_localize_script'),10,1); //modify booking script, force SSL for all
				add_filter('em_booking_form_action_url',array(&$this,'force_ssl'),10,1); //modify booking script, force SSL for all
			}
			add_action('em_gateway_js', array(&$this,'em_gateway_js')); // Java script for 3D Secure redirect
			add_action('em_handle_payment_return_' . $this->gateway, array(&$this, 'handle_payment_return')); // Handle 3D Secure return
			add_action('em_template_my_bookings_header',array(&$this,'say_thanks')); //say thanks on my_bookings page
			add_action('em_template_my_bookings_header',array(&$this,'pay_fail_message')); //display error message back to customer
		}
	}

	/*
	 * --------------------------------------------------
	 * Booking Interception - functions that modify booking object behaviour
	 * --------------------------------------------------
	 */
	/**
	 * This function intercepts the previous booking form url from the javascript localized array of EM variables and forces it to be an HTTPS url.
	 * @param array $localized_array
	 * @return array
	 */
	function em_wp_localize_script($localized_array){
		$localized_array['bookingajaxurl'] = $this->force_ssl($localized_array['bookingajaxurl']);
		return $localized_array;
	}

	/**
	 * Turns any url into an HTTPS url.
	 * @param string $url
	 * @return string
	 */
	function force_ssl($url){
		return str_replace('http://','https://', $url);
	}

	/**
	 * Triggered by the em_booking_add_yourgateway action, modifies the booking status if the event isn't free and also adds a filter to modify user feedback returned.
	 * @param EM_Event $EM_Event
	 * @param EM_Booking $EM_Booking
	 * @param boolean $post_validation
	 */
	function booking_add($EM_Event,$EM_Booking, $post_validation = false){
		global $wpdb, $wp_rewrite, $EM_Notices;

		parent::booking_add($EM_Event, $EM_Booking, $post_validation);

		if( $post_validation && empty($EM_Booking->booking_id) ){
			//add_filter('em_booking_save', array(&$this, 'em_booking_save'),1,2);
// TODO: will this satisfy both?
			if( get_option('dbem_multiple_bookings') ){
			    add_filter('em_multiple_booking_save', array(&$this, 'em_booking_save'),1,2);
			}else{
			    add_filter('em_booking_save', array(&$this, 'em_booking_save'),1,2);
			}
		}
	}

	/**
	 * Added to filters once a booking is added. Once booking is saved,
	 * we capture payment, and approve the booking (saving a second time).
	 * If payment isn't approved, just delete the booking and return false for save.
	 *
	 * @param bool $result
	 * @param EM_Booking $EM_Booking
	 */
	function em_booking_save( $result, $EM_Booking ){
		global $wpdb, $wp_rewrite, $EM_Notices;

		//make sure booking save was successful before we try anything
		if( $result ){
			if( $EM_Booking->get_price() > 0 ){

				$transaction = $this->process_transaction($EM_Booking);

				// RealMPI
				if($transaction['mpi']) {
					// Don't actually need to do anything here, as RealMPI form generated in booking_form_feedback
					//error_log("meta... ".print_r( $EM_Booking->booking_meta['realmpi'], true));
				}else{
					if($transaction['status']){
						//Set booking status, but no emails sent
						if( !get_option('em_'.$this->gateway.'_manual_approval', false) || !get_option('dbem_bookings_approval') ){
							$EM_Booking->set_status(1, false); //Approve
						}else{
							$EM_Booking->set_status(0, false); //Set back to normal "pending"
						}
					}else{
						//not good.... error inserted into booking in process transaction function. Delete this booking from db
						if( !is_user_logged_in() && get_option('dbem_bookings_anonymous') && !get_option('dbem_bookings_registration_disable') && !empty($EM_Booking->person_id) ){
							//delete the user we just created, only if in last 2 minutes
							$EM_Person = $EM_Booking->get_person();
							if( strtotime($EM_Person->user_registered) >= (current_time('timestamp')-120) ){
								include_once(ABSPATH.'/wp-admin/includes/user.php');
								wp_delete_user($EM_Person->ID);
							}
						}
						$EM_Booking->delete();
						return false;
					}
				}
			}
		}
		return $result;
	}


	/**
	 * Intercepts return data after a booking has been made, adds RealEx Redirect vars and modifies feedback message.
	 * @param array $return
	 * @param EM_Booking $EM_Booking
	 * @return array
	 */
	function booking_form_feedback( $return, $EM_Booking = false ){
		//Double check $EM_Booking is an EM_Booking object and that we have a booking awaiting payment.

		if( !empty($return['result']) ){
			if( !empty($EM_Booking->booking_meta['gateway']) && $EM_Booking->booking_meta['gateway'] == $this->gateway && $EM_Booking->get_price() > 0 ){

				if( isset( $EM_Booking->booking_meta['realmpi'] ) ) {
					$return['message'] = get_option('em_'. $this->gateway . "_booking_feedback_realmpi_redirect", "Redirecting to 3D Secure");

					$return = array_merge($return, $EM_Booking->booking_meta['realmpi'] );
				}else{
					$return['message'] = get_option('em_realex_remote_booking_feedback');
				}
			}else{
				//returning a free message
				$return['message'] = get_option('em_realex_remote_booking_feedback_free');
			}
		}elseif( !empty($EM_Booking->booking_meta['gateway']) && $EM_Booking->booking_meta['gateway'] == $this->gateway && $EM_Booking->get_price() > 0 ){
			//void this last authroization
			$this->void($EM_Booking);
		}
		return $return;
	}


	/**
	 * Handles the silent post URL
	 * This will only be called if RealMPI is in use.
	 * This will be fired once the user returns from their banks 3D Secure page
	 */
	function handle_payment_return(){
		global $wpdb;

		$merchantid = get_option('em_'. $this->gateway . "_merchant_id" );
		$secret     = get_option('em_'. $this->gateway . "_shared_secret" );
		$account    = get_option('em_'. $this->gateway . "_account" );

		$pasres = $_REQUEST['PaRes'];
		$md = $_REQUEST['MD'];

		// Decrypt Merchant Data :
		$md = rtrim( mcrypt_decrypt( MCRYPT_RIJNDAEL_256, md5( $secret ), base64_decode( $md ), MCRYPT_MODE_CBC, md5( md5( $secret ))), "\0");

		$valuearray = split("&",$md);

		foreach ($valuearray as $postvalue) {
			list($field,$content) = split("=",$postvalue);
			$formatarray[$field] = $content;
		}

		$currency 	= $formatarray['currency'];
		$amount 	= $formatarray['amount'];
		$cardnumber = $formatarray['cardnumber'];
		$cardname 	= $formatarray['cardname'];
		$cardtype 	= $formatarray['cardtype'];
		$expdate 	= $formatarray['expdate'];
		$orderid 	= $formatarray['orderid'];


		$timestamp = strftime("%Y%m%d%H%M%S");
		mt_srand((double)microtime()*1000000);

		// creating the hash.
		$tmp = "$timestamp.$merchantid.$orderid.$amount.$currency.$cardnumber";
		$md5hash = md5($tmp);
		$tmp = "$md5hash.$secret";
		$md5hash = md5($tmp);

		// Create 3ds-verifySig xml request
		$xml = "<request type='3ds-verifysig' timestamp='$timestamp'>
	<merchantid>$merchantid</merchantid>
	<account>$account</account>
	<orderid>$orderid</orderid>
	<amount currency='$currency'>$amount</amount>
	<card>
		<number>$cardnumber</number>
		<expdate>$expdate</expdate>
		<type>$cardtype</type>
		<chname>$cardname</chname>
	</card>
	<autosettle flag='1'/>
	<md5hash>$md5hash</md5hash>
    <pares>$pasres</pares>
</request>";


		// Send 3ds-verifySig request
		$response = $this->sendXmlRequest( $xml, "https://epage.payandshop.com/epage-3dsecure.cgi" );

		// Load Booking
		$orderid = substr( $orderid, 0, strpos($orderid, '-') );
		$EM_Booking = em_get_booking( $orderid );


		// Handle Verify Sig request response
		switch( $response['response']['result'] ) {
			case "00" : // the message has not been tampered with

				switch( $response['response']['threedsecure']['status'] ) {
					case "Y": // 3D Secure authorised
					case "A": // issuing bank acknowledges attempt
					case "U": // Card issuer having problems with 3Ds system

						// Use cavv, eci & xid in realAuth request below

						break;

					case "N": // Cardholder entered the wrong passphrase
						$this->record_transaction($EM_Booking, $amount / 100, $currency, $timestamp, null, "3D Secure Check Failed", "User entered incorrect password on 3D Secure site.");

						$EM_Booking->cancel();

						// Redirect to my bookings with error
						$redirect = get_permalink(get_option("dbem_my_bookings_page")).'?fail=3ds-pass';
						header('Location: '.$redirect);
						return;
				}

				break;
			case "110" : // the digital signatures do not match the message and most likely the message has been tampered with. Do not proceed to authorisation.

				$this->record_transaction($EM_Booking, $amount / 100, $currency, $timestamp, null, "3D Secure Sig Mismatch", "The digital signatures do not match. 3Ds Message tampered with?");

				$EM_Booking->cancel();

				// Redirect to my bookings with error
				$redirect = get_permalink(get_option("dbem_my_bookings_page")).'?fail=3ds-sig-mismatch';
				header('Location: '.$redirect);
				return;
		}


		// Send auth request with additional MPI feilds

		//You can use any alphanumeric combination for the orderid. Although each transaction must have a unique orderid.
		$orderid = $EM_Booking->booking_id."-".$timestamp."-".mt_rand(1, 999);

		$comment1 = "Events Manager Booking from ".get_site_url();
		$comment2 = "Booking #".$EM_Booking->booking_id;

		// New timestamp for next request
		$timestamp = strftime("%Y%m%d%H%M%S");
		mt_srand((double)microtime()*1000000);

		// Rebuild the MD5 hash with updated timestamp
		$tmp = "$timestamp.$merchantid.$orderid.$amount.$currency.$cardnumber";
		$md5hash = md5($tmp);
		$tmp = "$md5hash.$secret";
		$md5hash = md5($tmp);

		//A number of variables are needed to generate the request xml that is send to Realex Payments.
		$xml = "<request type='auth' timestamp='$timestamp'>
	<merchantid>$merchantid</merchantid>
	<account>$account</account>
	<orderid>$orderid</orderid>
	<amount currency='$currency'>$amount</amount>
	<card>
		<number>$cardnumber</number>
		<expdate>$expdate</expdate>
		<type>$cardtype</type>
		<chname>$cardname</chname>
	</card>
	<autosettle flag='1'/>
	<mpi>
		<cavv>".$response['response']['threedsecure']['cavv']."</cavv>
		<xid>".$response['response']['threedsecure']['xid']."</xid>
		<eci>".$response['response']['threedsecure']['eci']."</eci>
	</mpi>
	<md5hash>$md5hash</md5hash>
	<comments>
		<comment id='1'>$comment1</comment>
		<comment id='2'>$comment2</comment>
	</comments>
</request>";

		$response = $this->sendXmlRequest( $xml, "https://epage.payandshop.com/epage-remote.cgi" );

		if( !empty($EM_Booking->booking_id) ){

	        //Handle result
	        if( $response['response']['result'] == '00' ){

				$EM_Booking->booking_meta[$this->gateway] = array('txn_id'=>$response['response']['authcode'], 'amount' => $EM_Booking->get_price(false, false, true));
		        $this->record_transaction($EM_Booking, $amount / 100, $currency, $timestamp, $response['response']['authcode'], 'Completed', $response['response']['message']);

	        	//Set booking status, but no emails sent
				if( !get_option('em_'.$this->gateway.'_manual_approval', false) || !get_option('dbem_bookings_approval') ){
					$EM_Booking->approve(); //Approve
				}else{
					$EM_Booking->set_status(0, false); //Set back to normal "pending"
				}

				do_action('em_payment_processed', $EM_Booking, $this);

				// Redirect to default thanks message
				$redirect = get_permalink(get_option("dbem_my_bookings_page")).'?thanks=1';
				header('Location: '.$redirect);
				return;

	        }else{

		        //$EM_Booking->add_error($response['response']['message']. '<br />Please Check your card details and try again.<br />');
		        $this->record_transaction($EM_Booking, $amount / 100, $currency, $timestamp, null, $response['response']['result'], $response['response']['message']);

		        $EM_Booking->cancel();
		        do_action( 'em_payment_3Dsecure_fail', $EM_Booking, $this);

		       	$redirect = get_permalink(get_option("dbem_my_bookings_page")).'?fail='.urlencode( $response['response']['message'] );
		        header('Location: '.$redirect);

	        }
		}else{
			if( $response['response']['result'] == '00' ){

				$message = apply_filters('em_gateway_realex_redirect_bad_booking_email',"
A Payment has been received by RealEx for a non-existent booking.

Event Details : %event%

It may be that this user's booking has timed out yet they proceeded with payment at a later stage.

To refund this transaction, you must go to your SagePay account and search for this transaction:

Transaction ID : %transaction_id%

When viewing the transaction details, you should see an option to issue a refund.

If there is still space available, the user must book again.

Sincerely,
Events Manager
					", $booking_id, $event_id);

				if( !empty($event_id) ){
					$EM_Event = new EM_Event($event_id);
					$event_details = $EM_Event->name . " - " . date_i18n(get_option('date_format'), $EM_Event->start);
				}else{
					$event_details = __('Unknown','em-pro');
				}
				$message  = str_replace(array('%transaction_id%', '%event%'), array($strVPSTxId, $event_details), $message);
				wp_mail(get_option('em_'. $this->gateway . "_email" ), __('Unprocessed payment needs refund'), $message);
			}else{
				echo 'Error: Bad RealEx request, custom ID does not correspond with any pending booking.';
				exit;
			}
		}
	}

	/*
	 * --------------------------------------------------
	 * Booking UI - modifications to booking pages and tables containing authorize_aim bookings
	 * --------------------------------------------------
	 */

	/**
	 * Outputs custom content and credit card information.
	 */
	function booking_form(){
		echo get_option('em_'.$this->gateway.'_form');

		// TODO: Allow card choices to be configured by admin

		?>
        <p>
          <label><?php  _e('Card Type','em-pro'); ?></label>
          <select name="x_card_type" >
          	<option value=""><?php  _e('Select card type','em-pro'); ?></option>
          	<option value="AMEX">Amex</option>
          	<option value="DINERS">Diners</option>
          	<?php echo ( get_option('dbem_bookings_currency', 'GBP') == "EUR"? '<option value="LASER">Laser</option>' : '') ?>
          	<option value="MC">Mastercard</option>
          	<option value="SWITCH">Switch</option>
          	<option value="VISA">Visa</option>
          </select>
		<p>
          <label><?php  _e('Card Holder Name','em-pro'); ?></label>
          <input type="text" size="15" name="x_card_holder" value="" />
        </p>
        <p>
          <label><?php  _e('Card Number','em-pro'); ?></label>
          <input type="text" size="15" name="x_card_num" value="" />
        </p>
        <p>
          <label><?php  _e('Expiry Date','em-pro'); ?></label>
          <select name="x_exp_date_month" >
          	<?php
          		for($i = 1; $i <= 12; $i++){
          			$m = $i > 9 ? $i:"0$i";
          			echo "<option>$m</option>";
          		}
          	?>
          </select> /
          <select name="x_exp_date_year" >
          	<?php
          		$year = date('y',current_time('timestamp'));
          		for($i = $year; $i <= $year+10; $i++){
		 	      	echo "<option>$i</option>";
          		}
          	?>
          </select>
        </p>
        <p>
          <label><?php  _e('Issue Number','em-pro'); ?></label>
          <input type="text" size="2" name="x_card_issue_num" value="" />
        </p>
        <p>
          <label>
		  	<span class="form-tip" title="The CVV Number (Card Verification Value) is a 3 digit
		  	number on MasterCard and VISA cards located on the back of the card, on the right side of the signature strip.
		  	On American Express cards it is a 4 digit numeric code on the front, above the 16 digit card number.">
          		<?php  _e('CVN','em-pro'); ?>
          	</span>
          </label>
          <input type="text" size="4" name="x_card_code" value="" />
        </p>
		<?php
	}


	/**
	 * Outputs some JavaScript during the em_gateway_js action, which is run inside a script html tag, located in gateways/gateway.realmpi.js
	 */
	function em_gateway_js(){
		include(dirname(__FILE__).'/gateway.realmpi.js');
	}

	function say_thanks(){
		if( $_REQUEST['thanks'] == 1 ){
			echo "<div class='em-booking-message em-booking-message-success'>".get_option('em_'.$this->gateway.'_booking_feedback').'</div>';
		}
	}

	function pay_fail_message() {
		if( isset( $_REQUEST['fail'] ) ) {
			echo "<div class='em-booking-message em-booking-message-error'><p>Payment Unsuccessful</p><p>";
			switch ($_REQUEST['fail']) {
				default:
					echo "Payment unsucessful: ".urldecode( $_REQUEST['fail'] );
				case "3ds-pass":
					echo "You did not pass the 3D Secure authentification.";
					break;
				case "3ds-sig-mismatch":
					echo "We were unable to verify the the 3D Secure transaction due to a signature mismatch. You have not been charged.";
					break;
			}
			echo "</p></div>";
		}
	}


	function process_transaction($EM_Booking) {
		global $EM_Notices;

		$debug_out = '';

		// Prepare and send data over to RealEx

        // Pre submission data validation
		$result = array("status" => true, "mpi" => false);

		// Check card type
		if( empty( $_REQUEST['x_card_type'] ) ) {
			$EM_Booking->add_error('Please specify card type');
        	$result['status'] = false;
		}

		// Check card holder
		if( strlen( $_REQUEST['x_card_holder'] ) < 1 || preg_match('/[^-_@. 0-9A-Za-z]/', $_REQUEST['x_card_holder']) ) {
			$EM_Booking->add_error('Please enter card holder name (alpha numerics only)');
        	$result['status'] = false;
		}

		// Check card length and Luhn test
		$card_num_len = strlen( $_REQUEST['x_card_num'] );

		if( $card_num_len < 12 || $card_num_len > 19 || !$this->luhnCheck( $_REQUEST['x_card_num'] ) ) {
        	$EM_Booking->add_error('Invalid card number');
        	$result['status'] = false;
		}

		// Card expiry date should be checked. The date itself should be valid and formatted correctly.
		if( $_REQUEST['x_exp_date_month'] <= date('m') && $_REQUEST['x_exp_date_year'] <= date('y') ) {
        	$EM_Booking->add_error('Card expiry date has passed');
        	$result['status'] = false;
		}

		// Check CVN
		$cvn_len = strlen( $_REQUEST['x_card_code'] );

		if( $cvn_len < 3 || $cvn_len > 4 || !is_numeric( $_REQUEST['x_card_code'] ) ) {
        	$EM_Booking->add_error('Invalid card verification number (CVN)');
        	$result['status'] = false;
		}

		// Check issue number if this is a switch card
		if( $_REQUEST['x_card_type'] == 'SWITCH' ) {
			$issue_len = strlen( $_REQUEST['x_card_issue_num'] );

			if( $issue_len < 1 || $issue_len > 3 || !is_numeric( $_REQUEST['x_card_issue_num'] ) ) {
        		$EM_Booking->add_error('Please enter a valid issue number for Switch cards');
        		$result['status'] = false;
			}
		}

		if( !$result['status'] ) {
			return apply_filters('em_gateway_realex_process_transaction', $result, $EM_Booking, $this);
		}


		// The amount should be in the smallest unit of the required currency (i.e. 2000 = £20, $20 or €20)
        $amount     = $EM_Booking->get_price(false, false, true) * 100;
        $currency   = get_option('dbem_bookings_currency', 'GBP');
        $cardnumber = $_REQUEST['x_card_num'];
        $cardname   = $_REQUEST['x_card_holder'];
        $cardtype   = $_REQUEST['x_card_type'];
        $expdate    = $_REQUEST['x_exp_date_month'].$_REQUEST['x_exp_date_year'];
        $cardissue  = $_REQUEST['x_card_issue_num'];
        $cardcode   = $_REQUEST['x_card_code'];

		$merchantid = get_option('em_'. $this->gateway . "_merchant_id" );
		$secret     = get_option('em_'. $this->gateway . "_shared_secret" );
		$account    = get_option('em_'. $this->gateway . "_account" );

		//Creates timestamp that is needed to make up orderid
		$timestamp = strftime("%Y%m%d%H%M%S");
		mt_srand((double)microtime()*1000000);

		//You can use any alphanumeric combination for the orderid. Although each transaction must have a unique orderid.
		$orderid = $EM_Booking->booking_id."-".$timestamp."-".mt_rand(1, 999);

		$comment1 = "Events Manager Booking from ".get_site_url();
		$comment2 = "Booking #".$EM_Booking->booking_id;


// TODO: SHA1 is preferred to MD5 according to the docs
		// This section of code creates the md5hash that is needed
		$tmp = "$timestamp.$merchantid.$orderid.$amount.$currency.$cardnumber";
		$md5hash = md5($tmp);
		$tmp = "$md5hash.$secret";
		$md5hash = md5($tmp);

        // RealMPI Integration (3D Secure)
        if( get_option('em_'. $this->gateway . "_realmpi" ) ) {

        	// Only in place for certain card types
        	if( $cardtype == 'VISA' || $cardtype == 'MASTERCARD' || $cardtype == 'SWITCH' ) {

        		// Send 3ds-verifyenrolled request

        		// generate the request xml.
        		$xml = "<request type='3ds-verifyenrolled' timestamp='$timestamp'>
	<merchantid>$merchantid</merchantid>
	<account>$account</account>
	<orderid>$orderid</orderid>
	<amount currency='$currency'>$amount</amount>
	<card>
		<number>$cardnumber</number>
		<expdate>$expdate</expdate>
		<type>$cardtype</type>
		<chname>$cardname</chname>
	</card>
	<autosettle flag='1'/>
	<md5hash>$md5hash</md5hash>
</request>";

        		$response = $this->sendXmlRequest( $xml, "https://epage.payandshop.com/epage-3dsecure.cgi" );

				if( WP_DEBUG ) {
					$debug_out.= "<p>3ds-verifyenrolled request</p>";
					$debug_out = "<pre>".$xml."</pre><hr />";
					$debug_out.= "<pre>".print_r($response, true)."</pre>";
				}

        		switch( $response['response']['result'] ) {
        			case "00" : // Cardholder enrolled - Redirect user to 3D Secure via hidden form

						// Merchant Data
        				$md ="orderid=$orderid&cardnumber=$cardnumber&cardname=$cardname&cardtype=$cardtype&currency=$currency&amount=$amount&expdate=$expdate";
					    // encrypt
						$md = base64_encode( mcrypt_encrypt( MCRYPT_RIJNDAEL_256, md5( $secret ), $md, MCRYPT_MODE_CBC, md5( md5( $secret ) ) ) );

        				// Redirect to 3D secure via hidden form
        				// Store RealMPI form data against booking for use in booking_form_feedback
        				$EM_Booking->booking_meta['realmpi'] = array(
        					"realmpi_url"  => $response['response']['url'],
        					"realmpi_form" => array(
        						"PaReq" => $response['response']['pareq'],
        						"TermUrl" => $this->get_payment_return_url(),
        						"MD" => $md
        					)
        				);

        				$result['mpi'] = true;
        				return $result;


        			case "110" :

        				if( $response['response']['enrolled'] == 'N' ) { // Cardholder not enrolled (8a - Liability shift)
	        				if( $cardtype == 'VISA' ) {
	        					$eci = 6;
	        				}else{
	        					$eci = 1;
	        				}
        				}elseif( $response['response']['enrolled'] == 'U' ) { // Enrolled status could not be verified (8c - No liability shift)
            				if( $cardtype == 'VISA' ) {
	        					$eci = 7;
	        				}else{
	        					$eci = 0;
	        				}
        				}
        				break;

        			case "220" : // Card scheme directory service unavailable (8c - No liability shift)
        				if( $cardtype == 'VISA' ) {
        					$eci = 7;
        				}else{
        					$eci = 0;
        				}
        				break;

        			case "521" : // Enrolment request sent for a Solo card.
        				$EM_Booking->add_error('Card number is not a Switch Card. 3DSecure Transactions are not supported for Solo Cards. Please check your card type and try again.');
        				$result['status'] = false;
        				return apply_filters('em_gateway_realex_process_transaction', $result, $EM_Booking, $this);

        			default : // Need to catch 500's
        				$EM_Booking->add_error('Error '.$response['response']['result'].' with 3D Secure Verify Request<br />'.$response['response']['message']);
        				$result['status'] = false;
        				return apply_filters('em_gateway_realex_process_transaction', $result, $EM_Booking, $this);
        		}

				if( WP_DEBUG ) {
					$debug_out.='<br/>eci: '.$eci;
				}

        		$EM_Booking->add_error('3D Secure Response - '.$response['response']['message'].'<br />'.$debug_out );
        		$result['status'] = false;
        		return apply_filters('em_gateway_realex_process_transaction', $result, $EM_Booking, $this);

        	}
        }


		//A number of variables are needed to generate the request xml that is send to Realex Payments.
		$xml = "<request type='auth' timestamp='$timestamp'>
	<merchantid>$merchantid</merchantid>
	<account>$account</account>
	<orderid>$orderid</orderid>
	<amount currency='$currency'>$amount</amount>
	<card>
		<number>$cardnumber</number>
		<expdate>$expdate</expdate>
		<type>$cardtype</type>
		<chname>$cardname</chname>";

	    if( !empty( $cardcode ) ) {
        	$xml.= "
        <cvn>
        	<number>$cardcode</number>
        	<presind>1</presind>
        </cvn>
        	";
        }

		if( !empty( $cardissue ) ) {
        	$xml.= "
        <issueno>$cardissue</issueno>
        	";
        }

		$xml.= "
	</card>
	<autosettle flag='1'/>";

		if( isset( $eci ) ) {
			$xml.= "
	<mpi>
		<eci>".$eci."</eci>
	</mpi>";
		}

	$xml.= "
	<md5hash>$md5hash</md5hash>
	<comments>
		<comment id='1'>$comment1</comment>
		<comment id='2'>$comment2</comment>
	</comments>
	<custnum>$EM_Booking->person_id</custnum>
</request>";

		$response = $this->sendXmlRequest( $xml, "https://epage.payandshop.com/epage-remote.cgi" );

		if( WP_DEBUG ) {
			$debug_out.= "<p>Payment Request</p>";
			$debug_out.= "<pre>".$xml."</pre><hr />";
			$debug_out.= "<pre>".print_r($response, true)."</pre>";
		}

        //Handle result
        if( $response['response']['result'] == '00' ){
			$EM_Booking->booking_meta[$this->gateway] = array('txn_id'=>$response['response']['authcode'], 'amount' => $EM_Booking->get_price(false, false, true));
	        $this->record_transaction($EM_Booking, $amount / 100, $currency, date('Y-m-d H:i:s', current_time('timestamp')), $response['response']['authcode'], 'Completed', '');
	        $result['status'] = true;
        }else{
	        $EM_Booking->add_error($response['response']['message']. '<br />Please check your card details and try again.<br />'.$debug_out);
	        $result['status'] = false;
        }
        //Return transaction_id or false
		return apply_filters('em_gateway_realex_process_transaction', $result, $EM_Booking, $this);
	}


	private function sendXmlRequest( $xml, $url ) {
        // send it to payandshop.com
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_USERAGENT, "payandshop.com php version 0.9");
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $xml);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, FALSE); // this line makes it work under https
        $response = curl_exec ($ch);
        curl_close ($ch);

        // parse the response xml
        $response = eregi_replace ( "[\n\r]", "", $response );
        $response = eregi_replace ( "[[:space:]]+", " ", $response );

		$response = $this->xml2array( $response );
		return $response;
	}



	/**
	 * xml2array() will convert the given XML text to an array in the XML structure.
	 * Link: http://www.bin-co.com/php/scripts/xml2array/
	 * Arguments : $contents - The XML text
	 *                $get_attributes - 1 or 0. If this is 1 the function will get the attributes as well as the tag values - this results in a different array structure in the return value.
	 *                $priority - Can be 'tag' or 'attribute'. This will change the way the resulting array sturcture. For 'tag', the tags are given more importance.
	 * Return: The parsed XML in an array form. Use print_r() to see the resulting array structure.
	 * Examples: $array =  xml2array(file_get_contents('feed.xml'));
	 *              $array =  xml2array(file_get_contents('feed.xml', 1, 'attribute'));
	 */
	function xml2array($contents, $get_attributes=1, $priority = 'tag') {
		if(!$contents) return array();

		if(!function_exists('xml_parser_create')) {
			//print "'xml_parser_create()' function not found!";
			return array();
		}

		//Get the XML parser of PHP - PHP must have this module for the parser to work
		$parser = xml_parser_create('');
		xml_parser_set_option($parser, XML_OPTION_TARGET_ENCODING, "UTF-8"); # http://minutillo.com/steve/weblog/2004/6/17/php-xml-and-character-encodings-a-tale-of-sadness-rage-and-data-loss
		xml_parser_set_option($parser, XML_OPTION_CASE_FOLDING, 0);
		xml_parser_set_option($parser, XML_OPTION_SKIP_WHITE, 1);
		xml_parse_into_struct($parser, trim($contents), $xml_values);
		xml_parser_free($parser);

		if(!$xml_values) return;//Hmm...

		//Initializations
		$xml_array = array();
		$parents = array();
		$opened_tags = array();
		$arr = array();

		$current = &$xml_array; //Refference

		//Go through the tags.
		$repeated_tag_index = array();//Multiple tags with same name will be turned into an array
		foreach($xml_values as $data) {
			unset($attributes,$value);//Remove existing values, or there will be trouble

			//This command will extract these variables into the foreach scope
			// tag(string), type(string), level(int), attributes(array).
			extract($data);//We could use the array by itself, but this cooler.

			$result = array();
			$attributes_data = array();

			if(isset($value)) {
				if($priority == 'tag') $result = $value;
				else $result['value'] = $value; //Put the value in a assoc array if we are in the 'Attribute' mode
			}

			//Set the attributes too.
			if(isset($attributes) and $get_attributes) {
				foreach($attributes as $attr => $val) {
					if($priority == 'tag') $attributes_data[$attr] = $val;
					else $result['attr'][$attr] = $val; //Set all the attributes in a array called 'attr'
				}
			}

			//See tag status and do the needed.
			if($type == "open") {//The starting of the tag '<tag>'
				$parent[$level-1] = &$current;
				if(!is_array($current) or (!in_array($tag, array_keys($current)))) { //Insert New tag
					$current[$tag] = $result;
					if($attributes_data) $current[$tag. '_attr'] = $attributes_data;
					$repeated_tag_index[$tag.'_'.$level] = 1;

					$current = &$current[$tag];

				} else { //There was another element with the same tag name

					if(isset($current[$tag][0])) {//If there is a 0th element it is already an array
						$current[$tag][$repeated_tag_index[$tag.'_'.$level]] = $result;
						$repeated_tag_index[$tag.'_'.$level]++;
					} else {//This section will make the value an array if multiple tags with the same name appear together
						$current[$tag] = array($current[$tag],$result);//This will combine the existing item and the new item together to make an array
						$repeated_tag_index[$tag.'_'.$level] = 2;

						if(isset($current[$tag.'_attr'])) { //The attribute of the last(0th) tag must be moved as well
							$current[$tag]['0_attr'] = $current[$tag.'_attr'];
							unset($current[$tag.'_attr']);
						}

					}
					$last_item_index = $repeated_tag_index[$tag.'_'.$level]-1;
					$current = &$current[$tag][$last_item_index];
				}

			} elseif($type == "complete") { //Tags that ends in 1 line '<tag />'
				//See if the key is already taken.
				if(!isset($current[$tag])) { //New Key
					$current[$tag] = $result;
					$repeated_tag_index[$tag.'_'.$level] = 1;
					if($priority == 'tag' and $attributes_data) $current[$tag. '_attr'] = $attributes_data;

				} else { //If taken, put all things inside a list(array)
					if(isset($current[$tag][0]) and is_array($current[$tag])) {//If it is already an array...

						// ...push the new element into that array.
						$current[$tag][$repeated_tag_index[$tag.'_'.$level]] = $result;

						if($priority == 'tag' and $get_attributes and $attributes_data) {
							$current[$tag][$repeated_tag_index[$tag.'_'.$level] . '_attr'] = $attributes_data;
						}
						$repeated_tag_index[$tag.'_'.$level]++;

					} else { //If it is not an array...
						$current[$tag] = array($current[$tag],$result); //...Make it an array using using the existing value and the new value
						$repeated_tag_index[$tag.'_'.$level] = 1;
						if($priority == 'tag' and $get_attributes) {
							if(isset($current[$tag.'_attr'])) { //The attribute of the last(0th) tag must be moved as well

							$current[$tag]['0_attr'] = $current[$tag.'_attr'];
							unset($current[$tag.'_attr']);
							}

							if($attributes_data) {
								$current[$tag][$repeated_tag_index[$tag.'_'.$level] . '_attr'] = $attributes_data;
							}
						}
						$repeated_tag_index[$tag.'_'.$level]++; //0 and 1 index is already taken
					}
				}

			} elseif($type == 'close') { //End of tag '</tag>'
				$current = &$parent[$level-1];
			}
		}

		return($xml_array);
	}


	/**
	 *
	 * Credit Card number Luhn Check
	 * http://www.icurtain.co.uk/luhn-check.php
	 * @param $number
	 * @return boolean
	 */
	private function luhnCheck($number) {

    	$sum = 0;
    	$alt = false;
    	for($i = strlen($number) - 1; $i >= 0; $i--){
    		$n = substr($number, $i, 1);
    		if($alt){
    			//square n
    			$n *= 2;
    			if($n > 9) {
    				//calculate remainder
    				$n = ($n % 10) +1;
    			}
    		}
    		$sum += $n;
    		$alt = !$alt;
    	}

    	//if $sum divides by 10 with no remainder then it's valid
    	return ($sum % 10 == 0);
	}


	function void($EM_Booking){
		if( !empty($EM_Booking->booking_meta[$this->gateway]) ){
	        $capture = $this->get_api();
	        $capture->amount = $EM_Booking->booking_meta[$this->gateway]['amount'];
	        $capture->void();
		}
	}

	/*
	 * --------------------------------------------------
	 * Gateway Settings Functions
	 * --------------------------------------------------
	 */

	/**
	 * Outputs custom PayPal setting fields in the settings page
	 */
	function mysettings() {
		global $EM_options;
		?>
		<table class="form-table">
		<tbody>
		  <tr valign="top">
			  <th scope="row"><?php _e('Success Message', 'em-pro') ?></th>
			  <td>
			  	<input type="text" name="booking_feedback" value="<?php esc_attr_e(get_option('em_'. $this->gateway . "_booking_feedback" )); ?>" style='width: 40em;' /><br />
			  	<em><?php _e('The message that is shown to a user when a booking is successful and payment has been taken.','em-pro'); ?></em>
			  </td>
		  </tr>
		  <tr valign="top">
			  <th scope="row"><?php _e('Success Free Message', 'em-pro') ?></th>
			  <td>
			  	<input type="text" name="booking_feedback_free" value="<?php esc_attr_e(get_option('em_'. $this->gateway . "_booking_feedback_free" )); ?>" style='width: 40em;' /><br />
			  	<em><?php _e('If some cases if you allow a free ticket (e.g. pay at gate) as well as paid tickets, this message will be shown and the user will not be charged.','em-pro'); ?></em>
			  </td>
		  </tr>
		  <tr valign="top">
			  <th scope="row"><?php _e('Real MPI Redirect Message', 'em-pro') ?></th>
			  <td>
			  	<input type="text" name="booking_feedback_realmpi_redirect" value="<?php esc_attr_e(get_option('em_'. $this->gateway . "_booking_feedback_realmpi_redirect" )); ?>" style='width: 40em;' /><br />
			  	<em><?php _e('If you are using RealMPI for 3D Secure, you can customise the redirect message here.','em-pro'); ?></em>
			  </td>
		  </tr>
		</tbody>
		</table>
		<h3><?php echo sprintf(__('%s Options','dbem'),'RealEx Remote')?></h3>
		<table class="form-table">
		<tbody>
			 <tr valign="top">
				  <th scope="row"><?php _e('Mode', 'em-pro'); ?></th>
				  <td>
					  <select name="mode">
					  	<?php $selected = get_option('em_'.$this->gateway.'_mode'); ?>
						<option value="test" <?php echo ($selected == 'test') ? 'selected="selected"':''; ?>><?php _e('Test','emp-pro'); ?></option>
						<option value="live" <?php echo ($selected == 'live') ? 'selected="selected"':''; ?>><?php _e('Live','emp-pro'); ?></option>
					  </select>
				  </td>
			</tr>
			<tr valign="top">
				  <th scope="row"><?php _e('Merchant ID', 'emp-pro') ?></th>
				  <td><input type="text" name="merchant_id" value="<?php esc_attr_e(get_option( 'em_'. $this->gateway . "_merchant_id", "" )); ?>" /></td>
			</tr>
			<tr valign="top">
			 	<th scope="row"><?php _e('Shared Secret', 'emp-pro') ?></th>
			    <td><input type="password" name="shared_secret" value="<?php esc_attr_e(get_option( 'em_'. $this->gateway . "_shared_secret", "" )); ?>" /></td>
			</tr>
			<tr valign="top">
			 	<th scope="row"><?php _e('Domain / Account', 'emp-pro') ?></th>
			    <td><input type="text" name="account" value="<?php esc_attr_e(get_option( 'em_'. $this->gateway . "_account", "" )); ?>" /></td>
			</tr>
			<tr valign="top">
			 	<th scope="row"><?php _e('Credit Card Surcharge', 'emp-pro') ?></th>
			  <td>
			  	<input type="number" name="cc_surcharge" value="<?php esc_attr_e(get_option( 'em_'. $this->gateway . "_cc_surcharge", "" )); ?>" />%<br />
			  	<em><?php _e('If you wish to charge a surcharge for users paying by credit card, enter the percentage here.','em-pro'); ?></em><br />
			  </td>
			</tr>
		  	<tr valign="top">
			  <th scope="row"><?php _e('Use RealMPI 3D Secure', 'em-pro') ?></th>
			  <td>
			  	<input type="checkbox" name="realmpi" value="1" <?php echo (get_option('em_'. $this->gateway . "_realmpi" )) ? 'checked="checked"':''; ?> /><br />
			  </td>
		  </tr>
		  <tr valign="top">
			  <th scope="row"><?php _e('Manually approve completed transactions?', 'em-pro') ?></th>
			  <td>
			  	<input type="checkbox" name="manual_approval" value="1" <?php echo (get_option('em_'. $this->gateway . "_manual_approval" )) ? 'checked="checked"':''; ?> /><br />
			  	<em><?php _e('By default, when someone pays for a booking, it gets automatically approved once the payment is confirmed. If you would like to manually verify and approve bookings, tick this box.','em-pro'); ?></em><br />
			  	<em><?php echo sprintf(__('Approvals must also be required for all bookings in your <a href="%s">settings</a> for this to work properly.','em-pro'),EM_ADMIN_URL.'&amp;page=events-manager-options'); ?></em>
			  </td>
		  </tr>
		</tbody>
		</table>
		<?php
	}

	/*
	 * Run when saving settings, saves the settings available in EM_Gateway_Authorize_AIM::mysettings()
	 */
	function update() {
		parent::update();
		$gateway_options = array(
			$this->gateway . "_mode" => $_REQUEST[ 'mode' ],
			$this->gateway . "_merchant_id" => $_REQUEST[ 'merchant_id' ],
			$this->gateway . "_shared_secret" => $_REQUEST[ 'shared_secret' ],
			$this->gateway . "_account" => $_REQUEST[ 'account' ],
			$this->gateway . "_realmpi" => $_REQUEST[ 'realmpi' ],
			$this->gateway . "_cc_surcharge" => $_REQUEST[ 'cc_surcharge' ],
			$this->gateway . "_manual_approval" => $_REQUEST[ 'manual_approval' ],
			$this->gateway . "_booking_feedback" => wp_kses_data($_REQUEST[ 'booking_feedback' ]),
			$this->gateway . "_booking_feedback_free" => wp_kses_data($_REQUEST[ 'booking_feedback_free' ]),
			$this->gateway . "_booking_feedback_realmpi_redirect" => wp_kses_data($_REQUEST[ 'booking_feedback_realmpi_redirect' ])
		);
		foreach($gateway_options as $key=>$option){
			update_option('em_'.$key, stripslashes($option));
		}
		//default action is to return true
		return true;
	}

}
EM_Gateways::register_gateway('realex_remote', 'EM_Gateway_Realex_Remote');
?>