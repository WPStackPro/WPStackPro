<?php

class WPStackPro_Gumroad {

	private $auth_secret;

	public function __construct() {
		$this->auth_secret = get_option( 'wpstackpro_gumroad_auth_secret' );

		add_action( 'wp_ajax_nopriv_gumroad_post_request', array( $this, 'handle_gumroad_post_request' ) );

		// Seems like Gumroad removed the webhooks functionality & this crappy simple redirection doesn't work for subscription products
		add_action( 'wp_ajax_nopriv_gumroad_custom_delivery_test', array(
			$this,
			'handle_gumroad_custom_delivery_test'
		) );
	}

	public function handle_gumroad_post_request() {
		if ( $_REQUEST[ 'secret' ] != $this->auth_secret ) {
			wp_send_json_error( array( 'reason' => 'Invalid authentication, who are you?' ) );
		}

		$email = $_REQUEST[ 'email' ];
		// If a customer exists by this email, find it
		// Else create a new customer
		$user = get_user_by( 'email', $email );
		if ( $user === false ) {
			$user_id = WPStackPro_Helper::create_customer_account( $email );
			// Pretty sure it won't fail though, cuz username is already trimmed to be of length 60 chars max
			// and we are sure email doesn't exist as a user already
			if ( is_wp_error( $user_id ) ) {
				WPStackPro_Pager::notify_me( 'Could not handle a Gumroad PING, check email' );
				wp_mail( get_option( 'admin_email' ), 'AlertYo', 'Could not handle this Gumroad PING' . PHP_EOL . print_r( $_REQUEST, true ) );
				return;
			}
		} else {
			$user_id = $user->ID;
		}

		// @TODO put some security checks in here?
		// @TODO like verify this sale with Gumroad? Don't need it right away, but would be good to verify accounts

		// upgrade the customer designation
		update_user_meta( $user_id, 'customer_designation', 'sponsor' );

		// save all info
		update_user_meta( $user_id, 'gumroad_post_details', $_REQUEST );
	}

	public function handle_gumroad_custom_delivery_test() {
		wp_send_json_success( $_REQUEST );
	}
}

new WPStackPro_Gumroad();