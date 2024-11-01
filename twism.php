<?php

/**
 * Plugin Name: Twism
 * Plugin URI: https://twism.com/
 * Description: A loyalty platform for local and online businesses.
 * Version: 1.4.0
 * Author: Twism
 * Author URI: https://twism.com
 * Requires at least: 4.7
 * Requires PHP: 7.0
 *
 * WC requires at least: 5.5.2
 * WC tested up to: 6.1.0
 */
require 'vendor/autoload.php';

use GuzzleHttp\Client;

define( 'TWISM_BASE_URL', 'https://api.twism.com' );
define( 'TWISM_ASSETS_URL', 'https://assets.twism.com/widget/build' );


// If this file is called directly, abort.
if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

if ( ! isset( $_SERVER['SERVER_NAME'] ) || ! isset( $_SERVER['REQUEST_URI'] ) ) {
	exit; // Not accessed from browser
}

define( 'SERVER_NAME', explode( '//', get_site_url())[1] );
define( 'SERVER_REQUEST_URI', $_SERVER['REQUEST_URI'] );
$plugin_path = trailingslashit( WP_PLUGIN_DIR ) . 'woocommerce/woocommerce.php';

if (
	(function_exists( 'wp_get_active_and_valid_plugins' ) && in_array( $plugin_path, wp_get_active_and_valid_plugins() ))
	|| (function_exists( 'wp_get_active_network_plugins' ) && in_array( $plugin_path, wp_get_active_network_plugins() ))
	|| (in_array($plugin_path, apply_filters( 'active_plugins',get_option( 'active_plugins' ))))
) {

	if ( ! function_exists( 'twism_add_extension_register_script' ) ) {
		function twism_add_extension_register_script() {
			$script_path       = '/build/index.js';
			$script_asset_path = dirname( __FILE__ ) . '/build/index.asset.php';
			$script_asset      = file_exists( $script_asset_path )
				? require $script_asset_path
				: array(
					'dependencies' => array(),
					'version'      => filemtime( $script_path ),
				);
			$script_url        = plugins_url( $script_path, __FILE__ );
			$widget_url        = TWISM_ASSETS_URL . '/bundle.js';

			wp_register_script(
				'twism',
				$script_url,
				$script_asset['dependencies'],
				$script_asset['version'],
				true
			);

			wp_enqueue_script( 'twism' );

			$current_user = wp_get_current_user();

			if ( ! function_exists( 'str_contains' ) ) {
				function str_contains( string $haystack, string $needle ): bool {
					return '' === $needle || false !== strpos( $haystack, $needle );
				}
			}

			if ( str_contains( SERVER_REQUEST_URI, '/checkout/order-received' ) ) {
				$splitted_request_uri = explode('order-received/', SERVER_REQUEST_URI);
				$order_id = explode('/', $splitted_request_uri[1])[0];
				$order                = wc_get_order( $order_id );
				if ($order !== false) {
					$order_data           = $order->get_data();

					wp_localize_script(
						'twism',
						'twism_data',
						array(
							'user_id'   => $current_user->ID,
							'user'      => $current_user,
							'orderUser' => $order->get_user()->data,
							'orderData' => $order_data,
						)
					);	
				}
			} else {
				wp_localize_script(
					'twism',
					'twism_data',
					array(
						'user_id' => $current_user->ID,
						'user'    => $current_user,
					)
				);
			}

			wp_enqueue_script(
				'twism-widget',
				$widget_url . '?provider=woocommerce&account=' . SERVER_NAME,
				null,
				null,
				true
			);
		}
	}

	if ( ! function_exists( 'twism_extension_activate' ) ) {
		function twism_extension_activate($plugin) {
			if ($plugin === 'twism/twism.php') {
				$twism_api_url = TWISM_BASE_URL . '/merchant-connect/woocommerce/oauth/signup';

				header(
					'Location: ' . $twism_api_url .
						'?shop=' . urlencode( SERVER_NAME )
				);
				exit();
			}
		}
	}

	if ( ! function_exists( 'twism_extension_deactivate' ) ) {
		function twism_extension_deactivate($plugin) {
			if ($plugin === 'twism/twism.php') {
				try {
					$client = new Client(
						array(
							'base_uri'    => TWISM_BASE_URL,
							'timeout'     => 2.0,
							'exceptions'  => false,
							'http_errors' => false,
						)
					);

					$response = $client->request(
						'GET',
						'merchant-connect/woocommerce/uninstall',
						array(
							'query' => array(
								'signed_payload' => hash_hmac( 'sha256', SERVER_NAME, get_option( 'hmac_secret' ) ),
								'shop'           => urlencode( SERVER_NAME ),
							),
						)
					);
				} catch ( Exception $e ) {
					echo 'Something went wrong try again later.';
				}
			}
		}
	}

	if ( ! function_exists( 'twism_setup_menu' ) ) {
		function twism_setup_menu() {
			add_menu_page( 'Twism Plugin Page', 'Twism Loyalty', 'manage_options', 'twism-plugin', 'twism_init_page', 'data:image/svg+xml;base64,PHN2ZyB3aWR0aD0iMjUiIGhlaWdodD0iMjQiIHZpZXdCb3g9IjAgMCAyNSAyNCIgZmlsbD0ibm9uZSIgeG1sbnM9Imh0dHA6Ly93d3cudzMub3JnLzIwMDAvc3ZnIj4KPHBhdGggb3BhY2l0eT0iMC41IiBmaWxsLXJ1bGU9ImV2ZW5vZGQiIGNsaXAtcnVsZT0iZXZlbm9kZCIgZD0iTTMuMTc4NjQgMjMuOTk5OUMxLjQyMzA4IDIzLjk5OTkgMCAyMi41ODYgMCAyMC44NDJDMCAxOS4wOTc5IDEuNDIzMDggMTcuNjg0MSAzLjE3ODY0IDE3LjY4NDFDNC45MzQyIDE3LjY4NDEgNi4zNTc0NSAxOS4wOTc5IDYuMzU3NDUgMjAuODQyQzYuMzU3NDUgMjIuNTg2IDQuOTM0MiAyMy45OTk5IDMuMTc4NjQgMjMuOTk5OVpNMjAuOTQ0NCAyNEMxOS4xODg5IDI0IDE3Ljc2NTggMjIuNTg2MSAxNy43NjU4IDIwLjg0MkMxNy43NjU4IDE5LjA5OCAxOS4xODg5IDE3LjY4NDIgMjAuOTQ0NCAxNy42ODQyQzIyLjcgMTcuNjg0MiAyNC4xMjMzIDE5LjA5OCAyNC4xMjMzIDIwLjg0MkMyNC4xMjMzIDIyLjU4NjEgMjIuNyAyNCAyMC45NDQ0IDI0WiIgZmlsbD0iIzMzQ0RGMCIvPgo8cGF0aCBvcGFjaXR5PSIwLjUiIGZpbGwtcnVsZT0iZXZlbm9kZCIgY2xpcC1ydWxlPSJldmVub2RkIiBkPSJNMjAuOTQ0NCAxNS4xNTc4QzE5LjE4ODkgMTUuMTU3OCAxNy43NjU4IDEzLjc0MzggMTcuNzY1OCAxMS45OTk4QzE3Ljc2NTggMTAuMjU1NyAxOS4xODg5IDguODQxOTggMjAuOTQ0NCA4Ljg0MTk4QzIyLjcgOC44NDE5OCAyNC4xMjMyIDEwLjI1NTcgMjQuMTIzMiAxMS45OTk4QzI0LjEyMzIgMTMuNzQzOCAyMi43IDE1LjE1NzggMjAuOTQ0NCAxNS4xNTc4Wk0zLjE3ODY0IDE1LjE1OEMxLjQyMzA4IDE1LjE1OCAwIDEzLjc0NDEgMCAxMi4wMDAxQzAgMTAuMjU2IDEuNDIzMDggOC44NDIyNSAzLjE3ODY0IDguODQyMjVDNC45MzQyIDguODQyMjUgNi4zNTc0NSAxMC4yNTYgNi4zNTc0NSAxMi4wMDAxQzYuMzU3NDUgMTMuNzQ0MSA0LjkzNDIgMTUuMTU4IDMuMTc4NjQgMTUuMTU4WiIgZmlsbD0iIzJBQjZGNiIvPgo8cGF0aCBmaWxsLXJ1bGU9ImV2ZW5vZGQiIGNsaXAtcnVsZT0iZXZlbm9kZCIgZD0iTTcuNjI4ODUgMS44MDE0QzYuNzc4MzIgMS44MDE0IDYuMDAxIDEuNDY5NTcgNS40MzA1NyAwLjkyOTA1OEM0Ljg1NDk4IDAuMzU1MTYxIDQuMDU4NTUgMCAzLjE3ODY0IDBDMS40MjMwOCAwIDAgMS40MTM3NiAwIDMuMTU3ODFDMCA0LjkwMTg3IDEuNDIzMDggNi4zMTU3OSAzLjE3ODY0IDYuMzE1NzlDMy45OTE0MSA2LjMxNTc5IDQuNzM1NjEgNi4wMzI3NSA1LjI5NTAxIDUuNTE0MTVDNS44NTQ0MSA0Ljk5NTU1IDYuNjk1MjMgNC40ODU4MiA3LjYxNzI2IDQuNDg1ODJDOC40NzY1MyA0LjQ4NTgyIDkuMjU0NzcgNC44MzA2MiA5LjgxOTM3IDUuMzg4NTZIOS44MjkxNEw5Ljg1MTQ3IDUuNDEwNThMOS44NTE0NCA1LjQyMDcxQzEwLjQwMTEgNS45Nzk4MyAxMC43Mzk4IDYuNzQ0NDYgMTAuNzM5OCA3LjU4NzdDMTAuNzM5OCA4LjQ4OTU3IDEwLjMyMDggOS4yNjk0IDkuNzMzNzcgOS44NjgzOEM5LjIwMDc2IDEwLjQxMjIgOC45MDA0MyAxMS4xNzg0IDguOTAwNDMgMTEuOTk5OUM4LjkwMDQzIDEzLjc0NCAxMC4zMjM1IDE1LjE1NzkgMTIuMDc5MSAxNS4xNTc5QzEzLjgzNDYgMTUuMTU3OSAxNS4yNTc5IDEzLjc0NCAxNS4yNTc5IDExLjk5OTlDMTUuMjU3OSAxMS4xODI0IDE0Ljk1MzcgMTAuNDQgMTQuNDMyMSA5Ljg3NjczQzEzLjg1OTYgOS4yNTg0NiAxMy40MTcxIDguNDk0IDEzLjQxNzEgNy41ODc3QzEzLjQxNzEgNi43MzExNiAxMy43NzE1IDUuOTI3MjkgMTQuMzMxNSA1LjM4NjE2QzE0LjkxMzEgNC44MjQxNyAxNS4yNTc5IDQuMDI3NDcgMTUuMjU3OSAzLjE1NzgxQzE1LjI1NzkgMS40MTM3NiAxMy44MzQ2IDAgMTIuMDc5MSAwQzExLjIyMjMgMCAxMC40NDQ3IDAuMzM2NzI4IDkuODczMDggMC44ODQyNDJDOS4yOTg0NiAxLjQ1MTExIDguNTAyNiAxLjgwMTQgNy42Mjg4NSAxLjgwMTRaTTE3Ljc2NTggMy4xNTc4MUMxNy43NjU4IDQuOTAxODcgMTkuMTg4OSA2LjMxNTc5IDIwLjk0NDQgNi4zMTU3OUMyMi43IDYuMzE1NzkgMjQuMTIzMiA0LjkwMTg3IDI0LjEyMzIgMy4xNTc4MUMyNC4xMjMyIDEuNDEzNzYgMjIuNyAwIDIwLjk0NDQgMEMxOS4xODg5IDAgMTcuNzY1OCAxLjQxMzc2IDE3Ljc2NTggMy4xNTc4MVpNOC45MDAzOSAyMC44NDJDOC45MDAzOSAyMi41ODYgMTAuMzIzNSAyMy45OTk5IDEyLjA3OSAyMy45OTk5QzEzLjgzNDYgMjMuOTk5OSAxNS4yNTc4IDIyLjU4NiAxNS4yNTc4IDIwLjg0MkMxNS4yNTc4IDE5LjA5NzkgMTMuODM0NiAxNy42ODQxIDEyLjA3OSAxNy42ODQxQzEwLjMyMzUgMTcuNjg0MSA4LjkwMDM5IDE5LjA5NzkgOC45MDAzOSAyMC44NDJaIiBmaWxsPSJ1cmwoI3BhaW50MF9saW5lYXIpIi8+CjxkZWZzPgo8bGluZWFyR3JhZGllbnQgaWQ9InBhaW50MF9saW5lYXIiIHgxPSIxMi4wNjAxIiB5MT0iMy4yMTUzNGUtMDciIHgyPSIxMi4wNjAxIiB5Mj0iMjMuOTk5OSIgZ3JhZGllbnRVbml0cz0idXNlclNwYWNlT25Vc2UiPgo8c3RvcCBzdG9wLWNvbG9yPSIjMUU5NUZGIi8+CjxzdG9wIG9mZnNldD0iMSIgc3RvcC1jb2xvcj0iIzM2RDdFRCIvPgo8L2xpbmVhckdyYWRpZW50Pgo8L2RlZnM+Cjwvc3ZnPgo=' );
		}
	}

	if ( ! function_exists( 'twism_safe_css_attributes' ) ) {
		function twism_safe_css_attributes( $array ) {
			$array[] = 'position';
			$array[] = 'top';
			$array[] = 'bottom';
			$array[] = 'left';
			$array[] = 'right';
			$array[] = 'height';
			return $array;
		};
	}

	if ( ! function_exists( 'twism_init_page' ) ) {
		function twism_init_page() {
			try {
				$client = new Client(
					array(
						'base_uri'    => TWISM_BASE_URL,
						'timeout'     => 2.0,
						'exceptions'  => false,
						'http_errors' => false,
					)
				);

				$response             = $client->request(
					'GET',
					'merchant-connect/woocommerce/oauth/signup',
					array(
						'query' => array(
							'signed_payload' => hash_hmac( 'sha256', SERVER_NAME, get_option( 'hmac_secret' ) ),
							'shop'           => urlencode( SERVER_NAME ),
							'json'           => 'true',
						),
					)
				);
				$response_status_code = $response->getStatusCode();
				switch ( $response_status_code ) {
					case 200:
						$response_data = json_decode( $response->getBody()->getContents() );
						$iframe_url    = esc_url( $response_data->url );
						add_filter( 'safe_style_css', 'twism_safe_css_attributes' );
						$iframe_div = "<div style='height: calc(100vh - 72px); position: absolute; top: 0;left: -20px;bottom: 0;right: 0;'><iframe src=\"" . $iframe_url . '&hideSideBar=true\" width=\"100%\" height=\"100%\"></iframe></div>';
						echo wp_kses(
							$iframe_div,
							array(
								'div'    => array(
									'style'    => array(),
									'position' => array(),
									'top'      => array(),
									'left'     => array(),
									'right'    => array(),
									'height'   => array(),
									'bottom'   => array(),
								),
								'iframe' => array(
									'src'    => array(),
									'style'  => array(),
									'width'  => array(),
									'height' => array(),
								),
							)
						);
						break;
					case 401:
						echo 'Wrong signature.';
						break;
					case 404:
						$iframe_url = esc_url( TWISM_BASE_URL . '/merchant-connect/woocommerce/oauth/signup' );
						add_filter( 'safe_style_css', 'twism_safe_css_attributes' );
						$iframe_div = "<div style='height: calc(100vh - 72px);position: absolute;top: 0;left: -20px;bottom: 0;right: 0;'><iframe src=\"" . $iframe_url . '?shop=' . urlencode( SERVER_NAME ) . '&hideSideBar=true\" width=\"100%\" height=\"100%\"></iframe></div>';
						echo wp_kses(
							$iframe_div,
							array(
								'div'    => array(
									'style'    => array(),
									'position' => array(),
									'top'      => array(),
									'left'     => array(),
									'right'    => array(),
									'height'   => array(),
									'bottom'   => array(),
								),
								'iframe' => array(
									'src'    => array(),
									'style'  => array(),
									'width'  => array(),
									'height' => array(),
								),
							)
						);
						break;
					default:
						throw new Exception( 'Error when trying to login' );
				}
			} catch ( Exception $e ) {
				echo 'Something went wrong try again later.';
			}
		}
	}

	if ( ! function_exists( 'twism_save_hmac_secret' ) ) {
		function twism_save_hmac_secret( WP_REST_Request $request ) {
			$body = $request->get_json_params();
			update_option( 'hmac_secret', $body['hmacSecret'] );
		}
	}

	if ( ! function_exists( 'twism_get_base_location' ) ) {
		function twism_get_base_location( WP_REST_Request $request ) {
			return wc_get_base_location();
		}
	}

	if ( ! function_exists( 'twism_add_rest_api' ) ) {
		function twism_add_rest_api() {
			register_rest_route(
				'twism/v1',
				'/secret',
				array(
					'methods'  => 'POST',
					'callback' => 'twism_save_hmac_secret',
				)
			);

			register_rest_route(
				'twism/v1',
				'/location',
				array(
					'methods'  => 'GET',
					'callback' => 'twism_get_base_location',
				)
			);
		}
	}

	add_action( 'wp_enqueue_scripts', 'twism_add_extension_register_script' );
	add_action( 'activated_plugin', 'twism_extension_activate', 10, 2);
	add_action( 'deactivated_plugin', 'twism_extension_deactivate', 10, 2);
	add_action( 'admin_menu', 'twism_setup_menu' );
	add_action( 'rest_api_init', 'twism_add_rest_api' );
}
