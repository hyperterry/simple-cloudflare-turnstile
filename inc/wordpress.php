<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Get turnstile field: WP
function cfturnstile_field_login() {
	if(isset($_SESSION['cfturnstile_login_checked'])) { unset($_SESSION['cfturnstile_login_checked']); }
	cfturnstile_field_show('#wp-submit', 'turnstileWPCallback', 'wordpress-login', '-' . mt_rand());
}
function cfturnstile_field_register() { cfturnstile_field_show('#wp-submit', 'turnstileWPCallback', 'wordpress-register', '-' . mt_rand()); }
function cfturnstile_field_reset() { cfturnstile_field_show('#wp-submit', 'turnstileWPCallback', 'wordpress-reset', '-' . mt_rand()); }

// WP Login Check
if(get_option('cfturnstile_login')) {
    if(empty(get_option('cfturnstile_tested')) || get_option('cfturnstile_tested') == 'yes') {
        add_action('login_form','cfturnstile_field_login');
        add_action('authenticate', 'cfturnstile_wp_login_check', 21, 1);
        function cfturnstile_wp_login_check($user) {
            
            // Start session
            if (!session_id()) { session_start(); }

            // Only run if $user exists
            if(!isset($user->ID)) { return $user; }

            // Check skip
            if(defined( 'XMLRPC_REQUEST' ) && XMLRPC_REQUEST) { return $user; } // Skip XMLRPC
            if(isset($_POST['woocommerce-login-nonce']) && wp_verify_nonce(sanitize_text_field($_POST['woocommerce-login-nonce']), 'woocommerce-login')) { return $user; } // Skip Woo
            if(isset($_POST['edd_login_nonce']) && wp_verify_nonce( sanitize_text_field($_POST['edd_login_nonce']), 'edd-login-nonce') ) { return $user; } // Skip EDD
            if(is_wp_error($user) && isset($user->errors['empty_username']) && isset($user->errors['empty_password']) ) {return $user; } // Skip Errors

            // Check if already validated
            if(isset($_SESSION['cfturnstile_login_checked']) && wp_verify_nonce( sanitize_text_field($_SESSION['cfturnstile_login_checked']), 'cfturnstile_login_check' )) {
                return $user;
            }

            // Check Turnstile
            $check = cfturnstile_check();
            $success = $check['success'];
            if($success != true) {
                $user = new WP_Error( 'cfturnstile_error', cfturnstile_failed_message() );
            } else {
                $nonce = wp_create_nonce( 'cfturnstile_login_check' );
                $_SESSION['cfturnstile_login_checked'] = $nonce;
            }
            
            return $user;
            
        }
    }
}
// Clear session on login
add_action('wp_login', 'cfturnstile_wp_login_clear', 10, 2);
function cfturnstile_wp_login_clear($user_login, $user) {
	if(isset($_SESSION['cfturnstile_login_checked'])) { unset($_SESSION['cfturnstile_login_checked']); }
}

// WP Register Check
if(get_option('cfturnstile_register')) {
	add_action('register_form','cfturnstile_field_register');
	add_action('registration_errors', 'cfturnstile_wp_register_check', 10, 3);
	function cfturnstile_wp_register_check($errors, $sanitized_user_login, $user_email) {
		if ( defined( 'XMLRPC_REQUEST' ) && XMLRPC_REQUEST ) { return $errors; } // Skip XMLRPC
		if(isset($_POST['woocommerce-register-nonce'])) { return $errors; } // Skip Woo
		$check = cfturnstile_check();
		$success = $check['success'];
		if($success != true) {
			$errors->add( 'cfturnstile_error', sprintf('<strong>%s</strong>: %s',__( 'ERROR', 'simple-cloudflare-turnstile' ), cfturnstile_failed_message() ) );
		}
		return $errors;
	}
}

// WP Reset Check
if(get_option('cfturnstile_reset')) {
  if(!is_admin()) {
  	add_action('lostpassword_form','cfturnstile_field_reset');
  	add_action('lostpassword_post','cfturnstile_wp_reset_check', 10, 1);
  	function cfturnstile_wp_reset_check($validation_errors) {
		if(stripos($_SERVER["SCRIPT_NAME"], strrchr(wp_login_url(), '/')) !== false) { // Check if WP login page
  			$check = cfturnstile_check();
  			$success = $check['success'];
  			if($success != true) {
  				$validation_errors->add( 'cfturnstile_error', cfturnstile_failed_message() );
  			}
  		}
  	}
  }
}

// WP Comment
if(get_option('cfturnstile_comment') && !cft_is_plugin_active('wpdiscuz/class.WpdiscuzCore.php')) {
  if( !is_admin() || wp_doing_ajax() ) {
	add_action("comment_form_after", "cfturnstile_comment_form_after");
	function cfturnstile_comment_form_after() {
		if ( wp_doing_ajax() ) {
			wp_print_scripts('cfturnstile');
			wp_print_styles('cfturnstile-css');
		}
		cfturnstile_force_render();
	}
  	add_action('comment_form_submit_button','cfturnstile_field_comment', 100, 2);
  	// Create and display the turnstile field for comments.
  	function cfturnstile_field_comment( $submit_button, $args ) {
        do_action("cfturnstile_enqueue_scripts");
		$unique_id = mt_rand();
		$key = esc_attr( get_option('cfturnstile_key') );
		$theme = esc_attr( get_option('cfturnstile_theme') );
		$language = esc_attr(get_option('cfturnstile_language'));
		$script = '<script type="text/javascript">document.addEventListener("DOMContentLoaded", function() { document.body.addEventListener("click", function(event) { if (event.target.matches(".comment-reply-link, #cancel-comment-reply-link")) { turnstile.render("#cf-turnstile-c-' . $unique_id . '"); } }); });</script>';
		if(!$language) { $language = 'auto'; }
		$submit_before = '';
		$submit_after = '';
		$callback = '';
		if(get_option('cfturnstile_disable_button')) { $callback = 'turnstileCommentCallback'; }
		$submit_before .= '<div id="cf-turnstile-c-'.$unique_id.'" class="cf-turnstile" data-action="wordpress-comment" data-callback="'.$callback.'" data-sitekey="'.sanitize_text_field($key).'" data-theme="'.sanitize_text_field($theme).'" data-language="'.sanitize_text_field($language).'" data-retry="auto" data-retry-interval="1000"></div>';
		if(get_option('cfturnstile_disable_button')) {
			$submit_before .= '<div class="cf-turnstile-comment" style="pointer-events: none; opacity: 0.5;">';
			$submit_after .= "</div>";
		}
		$submit_after .= cfturnstile_force_render("-c-" . $unique_id);
		$submit_after .= do_action("cfturnstile_after_field", $unique_id);
		// if ajax comments are enabled, we need to re-render the turnstile after the comment is submitted
		$submit_after .= '<script type="text/javascript">jQuery(document).ajaxComplete(function() { setTimeout(function() { turnstile.render("#cf-turnstile-c-'.$unique_id.'"); }, 1000); });</script>';
		// return button
		return $submit_before . $submit_button . $submit_after . $script;
  	}
  	// Comment Validation
  	add_action('preprocess_comment','cfturnstile_wp_comment_check', 10, 1);
  	function cfturnstile_wp_comment_check($commentdata) {
		if(is_admin()) { return $commentdata; }
		if(!empty($_POST)) {
			$check = cfturnstile_check();
			$success = $check['success'];
			if($success != true) {
				wp_die( '<p><strong>' . esc_html__( 'ERROR:', 'simple-cloudflare-turnstile' ) . '</strong> ' . cfturnstile_failed_message() . '</p>', 'simple-cloudflare-turnstile', array( 'response'  => 403, 'back_link' => 1, ) );
			}
			return $commentdata;
      	}
  	}
  }
}
