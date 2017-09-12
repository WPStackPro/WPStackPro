<?php

class WPStackPro_Gumroad {

	private $auth_secret = 'dbz';

	public function __construct() {
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

		$email              = $_REQUEST[ 'email' ];
		$generated_password = wp_generate_password( 10, true, true );
		$user_id            = wp_create_user( $email, $generated_password, $email );

		// save all info
		// @TODO put some security checks in here?
		// @TODO like verify this sale with Gumroad? Don't need it right away, but would be good to verify accounts
		update_user_meta( $user_id, 'gumroad_post_details', $_REQUEST );

		WPStackPro_Helper::send_generated_credentials_to_user_by_email( $user_id, $email, $generated_password );
	}

	public function handle_gumroad_custom_delivery_test() {
		wp_send_json_success( $_REQUEST );
	}
}

new WPStackPro_Gumroad();