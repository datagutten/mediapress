<?php
add_action( 'admin_menu', 'my_plugin_menu' );

function my_plugin_menu() {
	add_options_page( __('Django options'), 'Django', 'manage_options', 'wikiembedder', 'django_options' );
}

/** Step 3. */
function django_options() {
	echo '<h2>Django</h2>';
	if ( !current_user_can( 'manage_options' ) )  {
		wp_die( __( 'You do not have sufficient permissions to access this page.' ) );
	}
	if( !empty( $_POST['django_url'] ) ) {
		update_option( 'django_url', $_POST['django_url'] );
		if(!empty(get_post($_POST['django_page_id'])))
		{
			update_option( 'django_page_id', $_POST['django_page_id'] );
			echo sprintf( '<div class="updated"><p><strong>%s</strong></p></div>', __('Django url saved'));
		}

		else {
			echo sprintf( '<div class="error"><p><strong>%s</strong></p></div>', __( 'Invalid page id' ) );
		}

	}

	echo '<form method="post">';
	echo '<div class="wrap">';
	echo __("Django url (without trailing /):", 'Wikiembedder' );
	echo sprintf( '<input type="text" name="django_url" size="50" value="%s">', get_option('django_url'));
	echo '</div>';
	echo '<div class="wrap">';
	echo __("Page id:", 'django' );
	echo sprintf( '<input type="text" name="django_page_id" size="50" value="%s">', get_option('django_page_id'));
	echo sprintf('<p><input type="submit" value="%s"></p>', __('Save'));
	echo '</div>';
	echo '</form>';
}
