<?php
add_action( 'admin_menu', 'my_plugin_menu' );

function my_plugin_menu() {
	add_options_page( __('Mediapress options'), 'Mediapress', 'manage_options', 'mediapress', 'mediapress_options' );
}

/** Step 3. */
function mediapress_options() {
	echo '<h2>Mediapress</h2>';
	if ( !current_user_can( 'manage_options' ) )  {
		wp_die( __( 'You do not have sufficient permissions to access this page.' ) );
	}
	if( !empty( $_POST['wikiurl'] ) ) {
		update_option( 'wikiurl', $_POST['wikiurl'] );
		// Put a "settings saved" message on the screen
		echo sprintf( '<div class="updated"><p><strong>%s</strong></p></div>', __('Wiki url saved'));

	}

	echo '<form method="post">';
	echo '<div class="wrap">';
	echo __("Wiki url (without trailing /):", 'Mediapress' );
	echo sprintf( '<input type="text" name="wikiurl" size="50" value="%s">', get_option('wikiurl'));
	echo sprintf('<p><input type="submit" value="%s"></p>', __('Save'));
	echo '</div>';
	echo '</form>';
}
?>
