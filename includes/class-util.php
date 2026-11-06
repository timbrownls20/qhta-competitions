<?php
/**
 * Small shared helpers: time zone conversion, JSON meta, options.
 *
 * @package Qhta_Competitions
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Static helpers.
 */
final class Qhta_Competitions_Util {

	/** Storage format for UTC datetimes in meta. */
	const UTC_FORMAT = 'Y-m-d H:i:s';

	/** Format used by <input type="datetime-local">. */
	const LOCAL_INPUT_FORMAT = 'Y-m-d\TH:i';

	/**
	 * Convert a site-local datetime-local value ("2027-01-29T00:00") to a UTC storage string.
	 *
	 * @param string $local Local value.
	 * @return string|null UTC string, '' for empty input, null when invalid.
	 */
	public static function local_input_to_utc( $local ) {
		$local = trim( (string) $local );
		if ( '' === $local ) {
			return '';
		}
		// Browsers may submit seconds ("…T00:00:00"); accept both.
		$date = DateTime::createFromFormat( '!Y-m-d\TH:i', substr( $local, 0, 16 ), wp_timezone() );
		if ( ! $date || $date->format( self::LOCAL_INPUT_FORMAT ) !== substr( $local, 0, 16 ) ) {
			return null;
		}
		$date->setTimezone( new DateTimeZone( 'UTC' ) );
		return $date->format( self::UTC_FORMAT );
	}

	/**
	 * Convert a UTC storage string to a datetime-local value in the site time zone.
	 *
	 * @param string $utc UTC string.
	 * @return string '' when empty or invalid.
	 */
	public static function utc_to_local_input( $utc ) {
		$ts = self::utc_to_timestamp( $utc );
		return null === $ts ? '' : wp_date( self::LOCAL_INPUT_FORMAT, $ts );
	}

	/**
	 * UTC storage string to Unix timestamp.
	 *
	 * @param string $utc UTC string.
	 * @return int|null
	 */
	public static function utc_to_timestamp( $utc ) {
		if ( ! is_string( $utc ) || '' === $utc ) {
			return null;
		}
		$date = DateTime::createFromFormat( '!' . self::UTC_FORMAT, $utc, new DateTimeZone( 'UTC' ) );
		return $date ? $date->getTimestamp() : null;
	}

	/**
	 * Human display of a UTC string in the site time zone, e.g. "29 January 2027, 12:00 am AEST".
	 *
	 * @param string $utc UTC string.
	 * @return string
	 */
	public static function display_datetime( $utc ) {
		$ts = self::utc_to_timestamp( $utc );
		return null === $ts ? '' : wp_date( 'j F Y, g:i a T', $ts );
	}

	/**
	 * Encode for meta storage: stable, readable, unicode kept as-is.
	 *
	 * @param mixed $value Value.
	 * @return string
	 */
	public static function json_encode( $value ) {
		return (string) wp_json_encode( $value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
	}

	/**
	 * Pretty JSON for admin textareas.
	 *
	 * @param mixed $value Value.
	 * @return string
	 */
	public static function json_pretty( $value ) {
		return (string) wp_json_encode( $value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT );
	}

	/**
	 * Read a JSON meta value.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $key     Meta key.
	 * @param mixed  $default Returned when missing or undecodable.
	 * @return mixed
	 */
	public static function get_json_meta( $post_id, $key, $default = null ) {
		$raw = get_post_meta( $post_id, $key, true );
		if ( ! is_string( $raw ) || '' === $raw ) {
			return $default;
		}
		$value = json_decode( $raw, true );
		return JSON_ERROR_NONE === json_last_error() ? $value : $default;
	}

	/**
	 * Write a JSON meta value. wp_slash() because update_post_meta() unslashes,
	 * which would otherwise eat backslashes inside the JSON.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $key     Meta key.
	 * @param mixed  $value   Value.
	 */
	public static function set_json_meta( $post_id, $key, $value ) {
		update_post_meta( $post_id, $key, wp_slash( self::json_encode( $value ) ) );
	}

	/**
	 * Write a non-autoloaded option (all plugin options are autoload=no).
	 *
	 * @param string $name  Option name.
	 * @param mixed  $value Value.
	 */
	public static function set_option( $name, $value ) {
		if ( false === get_option( $name, false ) ) {
			add_option( $name, $value, '', 'no' );
		} else {
			update_option( $name, $value, false );
		}
	}

	/**
	 * Template path. A copy in uploads/qhta-competitions/templates/ overrides the
	 * plugin's own (survives plugin updates; that dir is not web-served).
	 *
	 * @param string $name File name, e.g. "email.php".
	 * @return string
	 */
	public static function template( $name ) {
		$name     = basename( $name );
		$override = Qhta_Competitions_Plugin::upload_base_dir() . 'templates/' . $name;
		return file_exists( $override ) ? $override : QC_DIR . 'templates/' . $name;
	}

	/**
	 * Client IP for audit records (REMOTE_ADDR only; proxies headers are spoofable).
	 *
	 * @return string
	 */
	public static function client_ip() {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		return (string) filter_var( $ip, FILTER_VALIDATE_IP );
	}

	/**
	 * Client user agent for audit records.
	 *
	 * @return string
	 */
	public static function user_agent() {
		return isset( $_SERVER['HTTP_USER_AGENT'] ) ? substr( sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ), 0, 255 ) : '';
	}
}
