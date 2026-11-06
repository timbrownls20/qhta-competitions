<?php
/**
 * Program presets (JSON in /presets/) loadable when creating a round (spec §3.4, §4).
 *
 * Preset file shape: {title, slug, description, config: {…schema names…}}.
 * Dates are given as site-local `opens_at_local` / `closes_at_local`
 * ("Y-m-dTH:i") and converted to UTC on load; they are editable defaults.
 *
 * @package Qhta_Competitions
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Preset loader.
 */
final class Qhta_Competitions_Presets {

	/**
	 * Available presets keyed by file basename.
	 *
	 * @return array<string, array{title:string, description:string, file:string}>
	 */
	public static function all() {
		$out = array();
		foreach ( (array) glob( QC_DIR . 'presets/*.json' ) as $file ) {
			$data = self::read( $file );
			if ( ! $data ) {
				continue;
			}
			$out[ basename( $file, '.json' ) ] = array(
				'title'       => isset( $data['title'] ) ? (string) $data['title'] : basename( $file, '.json' ),
				'description' => isset( $data['description'] ) ? (string) $data['description'] : '',
				'file'        => $file,
			);
		}
		return $out;
	}

	/**
	 * Decode a preset file.
	 *
	 * @param string $file Path.
	 * @return array|null
	 */
	private static function read( $file ) {
		$raw = file_get_contents( $file );
		if ( false === $raw ) {
			return null;
		}
		$data = json_decode( $raw, true );
		return ( is_array( $data ) && isset( $data['config'] ) && is_array( $data['config'] ) ) ? $data : null;
	}

	/**
	 * A preset by id.
	 *
	 * @param string $id Basename.
	 * @return array|null
	 */
	public static function get( $id ) {
		$all = self::all();
		return isset( $all[ $id ] ) ? self::read( $all[ $id ]['file'] ) : null;
	}

	/**
	 * Preset config translated to schema names (local dates → UTC).
	 *
	 * @param array $preset Decoded preset.
	 * @return array
	 */
	public static function to_config( array $preset ) {
		$config = $preset['config'];
		foreach ( array( 'opens_at', 'closes_at' ) as $name ) {
			$local = $name . '_local';
			if ( isset( $config[ $local ] ) ) {
				$utc = Qhta_Competitions_Util::local_input_to_utc( $config[ $local ] );
				if ( null !== $utc ) {
					$config[ $name ] = $utc;
				}
				unset( $config[ $local ] );
			}
		}
		return $config;
	}

	/**
	 * Create a new draft round from a preset.
	 *
	 * @param string $id Preset id.
	 * @return array{0:int|WP_Error, 1:string[]} [post ID or error, config validation errors]
	 */
	public static function create_round( $id ) {
		$preset = self::get( $id );
		if ( ! $preset ) {
			return array( new WP_Error( 'qc_no_preset', __( 'Preset not found.', 'qhta-competitions' ) ), array() );
		}

		$post_id = wp_insert_post(
			array(
				'post_type'   => Qhta_Competitions_Program_CPT::POST_TYPE,
				'post_status' => 'draft',
				'post_title'  => isset( $preset['title'] ) ? (string) $preset['title'] : $id,
				'post_name'   => isset( $preset['slug'] ) ? sanitize_title( $preset['slug'] ) : '',
			),
			true
		);
		if ( is_wp_error( $post_id ) ) {
			return array( $post_id, array() );
		}

		$errors = Qhta_Competitions_Program_CPT::save_config( $post_id, self::to_config( $preset ) );
		update_post_meta( $post_id, '_qc_preset', sanitize_key( $id ) );
		return array( $post_id, $errors );
	}
}
