<?php

class WPStackPro_Pager {

	public function __construct() {
		add_action( 'user_register', array( $this, 'notify_me_of_new_user' ) ); // @TODO build it as an option
	}

	public static function notify_me( $message ) {
		$pager_id = get_option( 'wpstackpro_pager' ); // either email or webhook url or empty

		if ( empty( $pager_id ) ) {
			return;
		}

		if ( is_email( $pager_id ) ) {
			wp_mail( $pager_id, 'AlertYo', $message );
		} else {
			wp_remote_post( $pager_id, array(
				'headers' => array( 'Content-Type' => 'application/json; charset=utf-8' ),
				'body'    => json_encode( array( 'message' => $message ) )
			) );
		}
	}

	public function notify_me_of_new_user( $user_id ) {
		$user = new WP_User( $user_id );

		self::notify_me( '😇 ' . get_option( 'blogname' ) . ' New User: ' . $user->user_email );
	}
}

new WPStackPro_Pager();