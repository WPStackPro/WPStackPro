<?php

// Note: PDT handler needs to be rewritten, consider it untested piece of code still tied up to its origin

class WPStackPro_Paypal {

	public function __construct() {
		// Paypal PDT handler
		add_action( 'wp_ajax_nopriv_pdt_handler', array( $this, 'pdt_handler' ) );

		// Add dashboard widgets
		add_action( 'wp_dashboard_setup', array( $this, 'add_dashboard_widgets' ) );
	}

	public function log( $message ) {
		error_log( PHP_EOL . $message, 3, trailingslashit( dirname( ABSPATH ) ) . 'logs/paypal.log' );
	}

	public function pdt_handler() {
		global $wpdb;
		/**
		 * Verify PDT
		 */
		$is_paypal_testing_mode = get_option( 'paypal_testing_mode', 0 );

		if ( $is_paypal_testing_mode ) {
			$pdt_auth_token = get_option( 'pdt_sandbox_auth_token' );
			$pp_hostname    = "www.sandbox.paypal.com";
		} else {
			$pdt_auth_token = get_option( 'pdt_live_auth_token' );
			$pp_hostname    = "www.paypal.com";
		}

		$req = 'cmd=_notify-synch';

		if ( isset( $_GET[ 'tx' ] ) ) {
			$tx_token = $_GET[ 'tx' ];
		} else {
			die( 'No Transaction ID provided' ); // Test Transaction ID - 3K205710TA1752747
		}

		$req .= "&tx=$tx_token&at=$pdt_auth_token";

		$ch = curl_init();
		curl_setopt( $ch, CURLOPT_URL, "https://$pp_hostname/cgi-bin/webscr" );
		curl_setopt( $ch, CURLOPT_POST, 1 );
		curl_setopt( $ch, CURLOPT_RETURNTRANSFER, 1 );
		curl_setopt( $ch, CURLOPT_POSTFIELDS, $req );
		curl_setopt( $ch, CURLOPT_SSL_VERIFYPEER, 1 );
		//set cacert.pem verisign certificate path in curl using 'CURLOPT_CAINFO' field here,
		//if your server does not bundled with default verisign certificates.
		curl_setopt( $ch, CURLOPT_SSL_VERIFYHOST, 2 );
		curl_setopt( $ch, CURLOPT_HTTPHEADER, array( "Host: $pp_hostname" ) );
		$res = curl_exec( $ch );
		curl_close( $ch );

		$message = array();

		if ( $res ) {
			// parse the data
			$lines   = explode( "\n", trim( $res ) );
			$payload = array();

			if ( strcmp( $lines[ 0 ], "SUCCESS" ) == 0 ) {

				for ( $i = 1; $i < count( $lines ); $i++ ) {
					$temp                               = explode( "=", $lines[ $i ], 2 );
					$payload[ urldecode( $temp[ 0 ] ) ] = urldecode( $temp[ 1 ] );
				}

				$this->log( "Transaction[$tx_token] SUCCESS" );

				// check the payment_status is Completed
				if ( $payload[ 'payment_status' ] != 'Completed' ) {
					$this->log( "Transaction[$tx_token] Status != Completed" );
					$message[] = 'Seems like your transaction ' . $tx_token . ' has not go through successfully.';
					$message[] = 'Feel free to reach out to support at <a href="mailto:' . $this->support_email . '?subject=AlertYo-Transaction[' . $tx_token . ']">' . $this->support_email . '</a>';
					die( implode( '<br />', $message ) );
				}

				// check that txn_id has not been previously processed
				$already_exists = $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->usermeta} WHERE meta_key = 'payment_transaction_id' AND meta_value = '$tx_token';" );
				if ( $already_exists ) {
					$this->log( "Transaction[$tx_token] Already redeemed" );

					$message[] = 'Seems like you have already redeemed your transaction ' . $tx_token . '.';
					$message[] = 'Feel free to reach out to support at <a href="mailto:' . $this->support_email . '?subject=AlertYo-Transaction[' . $tx_token . ']">' . $this->support_email . '</a>';
					die( implode( '<br />', $message ) );
				}

				// check the transaction was indeed of $5
				if ( $payload[ 'payment_gross' ] < 5.00 ) {
					$this->log( "Transaction[$tx_token] Amount < $5 (USD)" );

					$message[] = 'Seems like your transaction ' . $tx_token . ' was of less than $5 (USD).';
					$message[] = 'This transaction has been flagged for manual inspection.';
					$message[] = 'Feel free to reach out to support at <a href="mailto:' . $this->support_email . '?subject=AlertYo-Transaction[' . $tx_token . ']">' . $this->support_email . '</a>';
					die( implode( '<br />', $message ) );
				}

				$generated_password = wp_generate_password( 10, true, true );

				if ( is_email( $payload[ 'payer_email' ] ) ) {
					$customer_email_address_for_account = $payload[ 'payer_email' ];
				} else {
					$customer_email_address_for_account = 'customer-' . md5( $tx_token ) . '@alertyo.com'; // customer-XXXXXX@alertyo.com email
				}

				$user_id = wp_create_user( $customer_email_address_for_account, $generated_password, $customer_email_address_for_account );

				// update in records
				update_user_meta( $user_id, 'payment_transaction_id', $tx_token );
				update_user_meta( $user_id, 'payment_transaction_gross', $payload[ 'payment_gross' ] );
				update_user_meta( $user_id, 'payment_transaction_fee', $payload[ 'payment_fee' ] );

				$this->set_customer_auth_cookies( $user_id );
				$this->redirect_to_profile_page();

			} else if ( strcmp( $lines[ 0 ], "FAIL" ) == 0 ) {

				$this->log( "Transaction[$tx_token] FAIL" );

				$message[] = 'Seems like your transaction ' . $tx_token . ' did not go through successfully.';
				$message[] = 'Feel free to reach out to support at <a href="mailto:' . $this->support_email . '?subject=AlertYo-Transaction[' . $tx_token . ']">' . $this->support_email . '</a>';
				die( implode( '<br />', $message ) );
			}

		} else {

			$this->log( "Transaction[$tx_token] Couldn't check status with Paypal" );

			// notify me
			$this->notify_me( 'PDT verification issue with AlertYo, check logs' );

			$message[] = 'There was an error verifying your Transaction ' . $tx_token . '.';
			$message[] = 'This transaction has been flagged for manual inspection.';
			$message[] = 'You can also reach out to support at <a href="mailto:' . $this->support_email . '?subject=AlertYo-Transaction[' . $tx_token . ']">' . $this->support_email . '</a>';
			die( implode( '<br />', $message ) );
		}
	}

	public function add_dashboard_widgets() {
		$file_contents = trim( file_get_contents( dirname( ABSPATH ) . '/logs/paypal.log' ) );
		if ( current_user_can( 'manage_options' ) && ! empty( $file_contents ) ) {
			wp_add_dashboard_widget(
				'paypal_log',
				'Paypal Log',
				function( $file_contents ) {
					echo implode( '<br/>', explode( PHP_EOL, $file_contents ) );
				}
			);
		}
	}

	public function set_customer_auth_cookies( $user_id ) {
		wp_set_auth_cookie( $user_id, true );
	}

	public function redirect_to_profile_page() {
		wp_redirect( '/wp-admin/profile.php' );
		die();
	}
}

new WPStackPro_Paypal();