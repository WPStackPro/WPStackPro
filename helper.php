<?php

class WPStackPro_Helper {

	/**
	 * Function to parse out domain name from a string
	 * Taken from https://stackoverflow.com/a/1974047/551713
	 *
	 * @param $url - Domain name entered by user
	 *
	 * @return string Domain name
	 */
	public static function get_host( $url ) {
		$parseUrl = parse_url( trim( $url ) );

		return trim( $parseUrl[ 'host' ] ? $parseUrl[ 'host' ] : array_shift( explode( '/', $parseUrl[ 'path' ], 2 ) ) );
	}

	/**
	 * Function to generate a friction less login link for a user
	 * The user clicks on this url, and they are auto-logged in
	 *
	 * @param $user_id
	 *
	 * @return string|void
	 */
	public static function generate_friction_less_login_url( $user_id ) {
		$token = wp_generate_password( 50 );

		update_user_meta( $user_id, 'friction_less_login_token', $token );
		// set expiry of this login token / link after 7 days
		update_user_meta( $user_id, 'friction_less_login_token_expiry', time() + ( 7 * DAY_IN_SECONDS ) ); // @TODO Cron worker that cleans this shit up on a routine

		return admin_url( 'admin-ajax.php?action=friction_less_login&token=' . urlencode( $token ) );
	}

	public static function send_generated_credentials_to_user_by_email( $user_id, $email, $generated_password ) {
		// email user their account credentials
		$friction_less_login_url = WPStackPro_Helper::generate_friction_less_login_url( $user_id );
		$email_body              = "Greetings!
		
Your account is ready!
Email: $email
Password: $generated_password

Click this link to auto-login into your account - $friction_less_login_url

If you have any questions, just reply to this email. My email is mail@ashfame.com

Thank you!
Ashfame";

		$headers = array( 'Reply-To: Ashfame <mail@ashfame.com>' );
		wp_mail( $email, get_bloginfo( 'name' ) . ' Account 💫', $email_body, $headers );
	}
}

new WPStackPro_Helper();