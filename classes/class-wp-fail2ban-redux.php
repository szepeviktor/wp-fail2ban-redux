<?php
/**
 * The main WP Fail2Ban Redux class.
 *
 * @since 0.1.0
 *
 * @package WP_Fail2Ban_Redux
 * @subpackage WP_Fail2Ban_Redux
 */

// Bail if accessed directly.
defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'WP_Fail2Ban_Redux' ) ) {

	/**
	 * The main WP Fail2Ban Redux Class.
	 *
	 * Adds log messages to your system log in the below format:
	 * [TIMESTAMP] [SERVER HOSTNAME] [DAEMON/SERVICE][PID] [MESSAGE]
	 * Apr 1 14:12:34 hostname wp(example.com)[2003]: Accepted password for username from 192.168.1.1
	 *
	 * @since 0.1.0
	 */
	class WP_Fail2Ban_Redux {

		/**
		 * The WP Fail2Ban Redux instance.
		 *
		 * @since 0.1.1
		 *
		 * @var WP_Fail2Ban_Redux
		 */
		private static $instance;

		/**
		 * Provides access to a single instance of `WP_Fail2Ban_Redux` using the
		 * singleton pattern.
		 *
		 * @since 0.1.1
		 *
		 * @return WP_Fail2Ban_Redux
		 */
		public static function get_instance() {
			if ( null === self::$instance ) {
				self::$instance = new self;
			}

			return self::$instance;
		}

		/**
		 * Constructor method.
		 *
		 * @since 0.1.1
		 */
		protected function __construct() {
			$this->setup_actions();
		}

		/**
		 * Set admin-related actions and filters.
		 *
		 * @since 0.1.1
		 */
		private function setup_actions() {

			/* Filters ********************************************************/

			// Username and email blocking.
			add_filter( 'authenticate', array( $this, 'authenticate' ), 1, 2 );

			// Failed XML-RPC login attempts.
			add_filter( 'xmlrpc_login_error', array( $this, 'xmlrpc_login_error' ), 1 );

			// XML-RPC Pingback errors.
			add_filter( 'xmlrpc_pingback_error', array( $this, 'xmlrpc_pingback_error' ), 1 );

			/* Actions ********************************************************/

			// Comment spam.
			add_action( 'comment_post', array( $this, 'comment_spam' ), 10, 2 );

			// User enumeration. Hooked later for a cheap rest request check.
			add_action( 'parse_request', array( $this, 'user_enumeration' ), 12 );

			// Login logging.
			add_action( 'wp_login', array( $this, 'wp_login' ) );

			// Failed logins.
			add_action( 'wp_login_failed', array( $this, 'wp_login_failed' ) );

			// Comment spam.
			add_action( 'wp_set_comment_status', array( $this, 'comment_spam' ), 10, 2 );

			// XML-RPC logging.
			add_action( 'xmlrpc_call', array( $this, 'xmlrpc_call' ), 1 );
		}

		/* Filters ************************************************************/

		/**
		 * Checks for and logs attempts to authenticate as a blocked user.
		 *
		 * @since 0.1.0
		 *
		 * @param WP_User|WP_Error $user     The WP_User or WP_Error object.
		 * @param string           $username The username or email address.
		 *
		 * @return WP_User|WP_Error|void
		 */
		public function authenticate( $user, $username ) {

			/**
			 * Filters the array of blocked users.
			 *
			 * @since 0.1.0
			 *
			 * @param array $users The array of usernames or email addresses.
			 */
			$users = (array) apply_filters( 'wp_fail2ban_redux_blocked_users', array() );

			// Log attempts to authenticate as a blocked user.
			if ( ! empty( $users ) ) {

				/**
				 * Filters the boolean of blocked users not in.
				 *
				 * The default is to block authetication attempts for any
				 * username in the blocked users array. If you'd rather block
				 * authentication attempts for users not in the blocked users
				 * array, return true on this filter.
				 *
				 * @since 0.1.0
				 *
				 * @param bool $not_in Defaults to false.
				 */
				$not_in = (bool) apply_filters( 'wp_fail2ban_redux_blocked_users_not_in', false );

				// Run the requested check.
				if ( $not_in ) {
					$blocked = ! in_array( $username, $users, true );
				} else {
					$blocked = in_array( $username, $users, true );
				}

				// If the username is blocked, log, and return a 403.
				if ( $blocked ) {
					WP_Fail2Ban_Redux_Log::openlog( 'authenticate' );
					WP_Fail2Ban_Redux_Log::syslog( "Blocked authentication attempt for {$username}" );
					WP_Fail2Ban_Redux_Log::_exit( 'authenticate' );
				}
			}

			return $user;
		}

		/**
		 * Checks for, and logs, user enumeration attempts.
		 *
		 * Only enable this feature if you are using pretty permalinks,
		 * otherwise bad things will happen.
		 *
		 * @since 0.1.0
		 * @deprecated 0.2.0
		 *
		 * @param string $redirect_url  The redirect URL.
		 *
		 * @return $redirect_url
		 */
		public function redirect_canonical( $redirect_url ) {
			_deprecated_function( 'WP_Fail2Ban_Redux::redirect_canonical', '0.2.0', 'WP_Fail2Ban_Redux::user_enumeration' );
			return $redirect_url;
		}

		/**
		 * Logs XML-RPC authentication failures.
		 *
		 * @since 0.1.0
		 *
		 * @param IXR_Error $error The IXR_Error object.
		 *
		 * @return IXR_Error
		 */
		public function xmlrpc_login_error( $error ) {
			static $failure_count = 0;

			// Log XML-RPC authentication failures.
			WP_Fail2Ban_Redux_Log::openlog( 'xmlrpc_login_error' );
			WP_Fail2Ban_Redux_Log::syslog( 'XML-RPC authentication failure' );

			// Bump the XML-RPC failure count.
			$failure_count++;

			/*
			 * If the failure count is greater than 1, log the failure. Since
			 * the count is reset for each request, it can be reasonably assumed
			 * that it's the result of a multicall.
			 */
			if ( 1 < $failure_count ) {
				WP_Fail2Ban_Redux_Log::syslog( 'XML-RPC multicall authentication failure' );
			}

			return $error;
		}

		/**
		 * Logs XML-RPC pingback errors.
		 *
		 * @since 0.1.0
		 *
		 * @param IXR_Error $error The IXR error object.
		 *
		 * @return IXR_Error
		 */
		public function xmlrpc_pingback_error( $error ) {

			// Don't log a pingback error if a pingback was already registered.
			if ( 48 !== $error->code ) {
				WP_Fail2Ban_Redux_Log::openlog( 'xmlrpc_pingback_error' );
				WP_Fail2Ban_Redux_Log::syslog( "Pingback error {$error->code} generated" );
			}

			return $error;
		}

		/* Actions ************************************************************/

		/**
		 * Log spammed comments.
		 *
		 * @since 0.1.0
		 *
		 * @param int    $id     The comment id.
		 * @param string $status The comment status.
		 *
		 * @return void
		 */
		public function comment_spam( $id, $status ) {

			/**
			 * Filters the log spam comments boolean.
			 *
			 * @since 0.1.0
			 *
			 * @param bool $comments Defaults to false.
			 */
			$comments = (bool) apply_filters( 'wp_fail2ban_redux_log_spam_comments', false );

			// Bail if we're not logging spam comments.
			if ( ! $comments ) {
				return;
			}

			// Bail if the comment isn't spam.
			if ( 'spam' !== $status ) {
				return;
			}

			// Get the comment.
			$comment = get_comment( $id );
			if ( ! $comment ) {
				return;
			}

			WP_Fail2Ban_Redux_Log::openlog( 'comment_spam' );
			WP_Fail2Ban_Redux_Log::syslog( 'Spammed comment', LOG_NOTICE, $comment->comment_author_IP );
		}

		/**
		 * Checks for, and logs, user enumeration attempts.
		 *
		 * If we're in the admin, pretty permalinks are disabled, or were not
		 * processing a GET request, we do not attempt to block enumeration. In
		 * these scenarios, attempting to block user enumeration can cause
		 * terrible, horrible, no good, very bad things to happen.
		 *
		 * @since 0.2.0
		 *
		 * @return void
		 */
		public function user_enumeration() {

			// Bail if we don't have an `author` or `author_name` request var.
			if ( ! isset( $_GET['author'] ) && ! isset( $_GET['author_name'] ) ) {
				return;
			}

			// Bail if we're in the admin.
			if ( is_admin() ) {
				return;
			}

			// Bail if pretty permalinks are disabled.
			if ( ! get_option( 'permalink_structure' ) ) {
				return;
			}

			/**
			 * Filters the user enumeration boolean.
			 *
			 * @since 0.1.0
			 *
			 * @param bool $enum Defaults to false.
			 */
			$enum = (bool) apply_filters( 'wp_fail2ban_redux_block_user_enumeration', false );

			// Maybe block and log user enumeration attempts.
			if ( $enum ) {
				WP_Fail2Ban_Redux_Log::openlog( 'user_enumeration' );
				WP_Fail2Ban_Redux_Log::syslog( 'Blocked user enumeration attempt' );
				WP_Fail2Ban_Redux_Log::_exit( 'user_enumeration' );
			}
		}

		/**
		 * Log successful authentication attempts.
		 *
		 * @since 0.1.0
		 *
		 * @param string $username The username.
		 */
		public function wp_login( $username ) {
			WP_Fail2Ban_Redux_Log::openlog( 'wp_login' );
			WP_Fail2Ban_Redux_Log::syslog( "Accepted password for {$username}", LOG_INFO );
		}

		/**
		 * Log failed authentication attempts.
		 *
		 * @since 0.1.0
		 *
		 * @param string $username Username or email address.
		 */
		public function wp_login_failed( $username ) {

			// Use the cache to check that the user actually exists.
			$existing = '';
			if ( wp_cache_get( $username, 'userlogins' ) ) {
				$existing = $username;
			} elseif ( wp_cache_get( $username, 'useremail' ) ) {
				$existing = wp_cache_get( wp_cache_get( $username, 'useremail' ), 'users' )->user_login;
			}

			// Set our message variable based on the user's existence.
			$message = empty( $existing )
					 ? "Authentication attempt for unknown user {$username}"
					 : "Authentication failure for {$existing}";

			WP_Fail2Ban_Redux_Log::openlog( 'wp_login_failed' );
			WP_Fail2Ban_Redux_Log::syslog( $message );
		}

		/**
		 * Maybe log pingback requests.
		 *
		 * @since 0.1.0
		 *
		 * @todo Maybe add more information to the log message like website, etc.
		 *
		 * @param string $name The method name.
		 *
		 * @return void
		 */
		public function xmlrpc_call( $name ) {

			// Bail if we're not processing a pingback.
			if ( 'pingback.ping' !== $name ) {
				return;
			}

			/**
			 * Filters the log pingbacks boolean.
			 *
			 * @since 0.1.0
			 *
			 * @param bool $pingbacks Defaults to false.
			 */
			$pingbacks = (bool) apply_filters( 'wp_fail2ban_redux_log_pingbacks', false );

			// Maybe log pingback requests.
			if ( $pingbacks ) {

				global $wp_xmlrpc_server;

				$args = array();
				if ( is_object( $wp_xmlrpc_server ) ) {
					$args = $wp_xmlrpc_server->message->params;
				}

				$to = 'unknown';
				if ( ! empty( $args[1] ) ) {
					$to = esc_url_raw( $args[1] );
				}

				WP_Fail2Ban_Redux_Log::openlog( 'xmlrpc_call_pingback', LOG_USER );
				WP_Fail2Ban_Redux_Log::syslog( "Pingback requested for '{$to}'", LOG_INFO );
			}
		}
	}
} // End if().
