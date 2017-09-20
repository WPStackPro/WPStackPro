<?php

class WPStackPro_Pager {

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
}

new WPStackPro_Pager();