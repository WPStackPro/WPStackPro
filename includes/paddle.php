<?php

class WPStackPro_Paddle {

	public function __construct() {
		add_action( 'wp_ajax_nopriv_paddle_webhook', array( $this, 'paddle_webhook_handler' ) );
	}

	public static function render_subscription_purchase_button( $plan_id, $user_email = '' ) {
		if ( ! $plan_id ) {
			return;
		}
		?>
		<script src="https://cdn.paddle.com/paddle/paddle.js"></script>
		<script type="text/javascript"> Paddle.Setup( { vendor: 21187 } ); </script>
		<a href="#!" class="paddle_button" data-product="<?php echo $plan_id ?>" data-email="<?php echo $user_email; ?>"
		   data-success="<?php echo admin_url( '', 'https' ) ?>">Buy Now!</a>
		<?php
	}

	public function verify_signature() {
		// Paddle 'Public Key'
		$public_key = get_option( 'paddle_public_key' );

		// Get the p_signature parameter & base64 decode it.
		$signature = base64_decode( $_POST[ 'p_signature' ] );

		// Get the fields sent in the request, and remove the p_signature parameter
		$fields = $_POST;
		unset( $fields[ 'p_signature' ] );

		// ksort() and serialize the fields
		ksort( $fields );
		foreach ( $fields as $k => $v ) {
			if ( ! in_array( gettype( $v ), array( 'object', 'array' ) ) ) {
				$fields[ $k ] = "$v";
			}
		}
		$data = serialize( $fields );

		// Verify the signature
		return openssl_verify( $data, $signature, $public_key, OPENSSL_ALGO_SHA1 );
	}

	public function paddle_webhook_handler() {
		if ( false === $this->verify_signature() ) {
			http_response_code( 401 );
			die( 'Not OK!' );
		}

		unset( $_REQUEST[ 'p_signature' ] );

		// lets figure out who the alert is meant for
		if ( $_REQUEST[ 'passthrough' ] ) {
			$user_id = $_REQUEST[ 'passthrough' ];
		} else {
			$email = $_REQUEST[ 'email' ];
			// create a new customer if one already isn't there by this email
			$user = get_user_by( 'email', $email );
			if ( $user === false ) {
				$user_id = WPStackPro_Helper::create_customer_account( $email );
				// Pretty sure it won't fail though, cuz username is already trimmed to be of length 60 chars max
				// and we are sure email doesn't exist as a user already
				if ( is_wp_error( $user_id ) ) {
					WPStackPro_Pager::notify_me( 'Could not handle a Paddle webhook call, check email' );
					wp_mail( get_option( 'admin_email' ), get_option( 'blogname' ), 'Could not handle this Paddle webhook' . PHP_EOL . print_r( $_REQUEST, true ) );

					// best to return non-200 request so that Paddle retries it later on & I can check it in meanwhile
					http_response_code( 500 );
				}
			} else {
				$user_id = $user->ID;
			}
		}

		switch ( $_REQUEST[ 'alert_name' ] ) {
			case 'subscription_created':
				update_user_meta( $user_id, 'subscription_id', $_REQUEST[ 'subscription_id' ] );
				update_user_meta( $user_id, 'checkout_id', $_REQUEST[ 'checkout_id' ] );
				update_user_meta( $user_id, 'subscription_plan_id', $_REQUEST[ 'subscription_plan_id' ] );
				update_user_meta( $user_id, 'subscription_update_url', $_REQUEST[ 'update_url' ] );
				update_user_meta( $user_id, 'subscription_cancel_url', $_REQUEST[ 'cancel_url' ] );

				update_user_meta( $user_id, 'customer_designation', 'sponsor' );
				break;
			case 'subscription_payment_succeeded':
				// make sure the user is marked as a sponsor, maybe they had a failed payment prior to this payment
				update_user_meta( $user_id, 'customer_designation', 'sponsor' );
				delete_user_meta( $user_id, 'subscription_payment_failed_count' );
				break;
			case 'subscription_payment_failed':
				$count = absint( get_user_meta( $user_id, 'subscription_payment_failed_count', true ) );
				$count++;
				update_user_meta( $user_id, 'subscription_payment_failed_count', $count );
				if ( $count >= 3 ) {
					update_user_meta( $user_id, 'customer_designation', 'defaulter' );
				}
				break;
			case 'subscription_cancelled':
				update_user_meta( $user_id, 'customer_designation', 'retired' );
				break;
			case 'subscription_updated':
				// For cases when user upgrades or downgrades
				// @TODO implement this when the need arises
				break;
			case 'subscription_payment_refunded':
				global $wpdb;
				// we only have checkout id to pull out the user, if this webhook alert is even meant for this product
				$query = "SELECT user_id FROM $wpdb->usermeta WHERE meta_key = 'checkout_id' AND meta_value = '%s';";
				$user_id = $wpdb->get_var( $wpdb->prepare( $query, $_REQUEST['checkout_id'] ) );

				if ( $user_id ) {
					update_user_meta( $user_id, 'customer_designation', 'traitor' );
				}
				break;
		}

		// save all info
		add_user_meta( $user_id, 'paddle_webhook_' . $_REQUEST[ 'alert_name' ] . '_details', $_REQUEST );
	}
}

new WPStackPro_Paddle();