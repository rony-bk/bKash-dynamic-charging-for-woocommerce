<?php

namespace bKash\PGW\DC;

use WC_Logger;

class Log {
	/**
	 * @var WC_Logger|null
	 */
	private static $logger;

	public static function debug( $message ) {
		if ( self::isDebug() !== 'yes' ) {
			return;
		}

		self::writeLog( 'debug', $message );
	}

	public static function info( $message ) {
		if ( self::isDebug() !== 'yes' ) {
			return;
		}

		self::writeLog( 'info', $message );
	}

	public static function error( $message ) {
		self::writeLog( 'error', $message );
	}

	private static function writeLog( string $level, $message ): void {
		$logger = self::getLogger();
		if ( ! $logger ) {
			return;
		}

		$message = is_string( $message ) ? $message : wp_json_encode( $message );
		$logger->log( $level, $message, array( 'source' => 'bKash_DC_API_LOG' ) );
	}

	private static function getLogger(): ?WC_Logger {
		if ( self::$logger !== null ) {
			return self::$logger;
		}

		if ( class_exists( WC_Logger::class ) ) {
			self::$logger = function_exists( 'wc_get_logger' ) ? wc_get_logger() : new WC_Logger();
		} else {
			self::$logger = null;
		}

		return self::$logger;
	}

	public static function isDebug(): string {
		$settings  = get_option( 'woocommerce_' . BKASH_DC_PLUGIN_SLUG . '_settings' );
		if ( ! is_array( $settings ) ) {
			return 'no';
		}

		return $settings['debug'] ?? 'no';
	}
}
