<?php
/**
 * Protected file storage and the download handler (spec §5.4).
 *
 * Files live in uploads/qhta-competitions/{program_id}/{random}.{ext} behind a
 * deny-all .htaccess, never in the media library, never at a public URL. They
 * are served only through admin-post.php with a nonce and capability check.
 *
 * @package Qhta_Competitions
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Protected files.
 */
final class Qhta_Competitions_Files {

	/** Submission meta: array of stored file refs. */
	const META = '_qc_files';

	/** Allowed extensions → accepted finfo MIME types. */
	const ALLOWED = array(
		'docx' => array( 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/zip', 'application/octet-stream' ),
		'pdf'  => array( 'application/pdf', 'application/x-pdf' ),
	);

	/**
	 * Hooks.
	 */
	public static function init() {
		add_action( 'admin_post_qc_file', array( __CLASS__, 'handle_download' ) );
		add_action( 'before_delete_post', array( __CLASS__, 'on_delete_submission' ), 10, 2 );
	}

	/**
	 * Validate an uploaded file from $_FILES.
	 *
	 * @param array $file   One $_FILES entry.
	 * @param int   $max_mb Size limit in MB.
	 * @return array{ext:string, mime:string}|WP_Error
	 */
	public static function validate_upload( array $file, $max_mb ) {
		$error = isset( $file['error'] ) ? (int) $file['error'] : UPLOAD_ERR_NO_FILE;
		if ( UPLOAD_ERR_INI_SIZE === $error || UPLOAD_ERR_FORM_SIZE === $error ) {
			/* translators: %d: MB */
			return new WP_Error( 'qc_too_big', sprintf( __( 'The file is larger than %d MB.', 'qhta-competitions' ), $max_mb ) );
		}
		if ( UPLOAD_ERR_OK !== $error || empty( $file['tmp_name'] ) || ! is_uploaded_file( $file['tmp_name'] ) ) {
			return new WP_Error( 'qc_upload_failed', __( 'The file did not upload. Please try again.', 'qhta-competitions' ) );
		}
		if ( (int) $file['size'] > $max_mb * MB_IN_BYTES ) {
			/* translators: %d: MB */
			return new WP_Error( 'qc_too_big', sprintf( __( 'The file is larger than %d MB.', 'qhta-competitions' ), $max_mb ) );
		}
		$ext = strtolower( pathinfo( (string) $file['name'], PATHINFO_EXTENSION ) );
		if ( ! isset( self::ALLOWED[ $ext ] ) ) {
			return new WP_Error( 'qc_bad_type', __( 'Only .docx and .pdf files are accepted.', 'qhta-competitions' ) );
		}
		$mime = self::sniff( $file['tmp_name'] );
		if ( ! in_array( $mime, self::ALLOWED[ $ext ], true ) || ! self::looks_like( $file['tmp_name'], $ext ) ) {
			return new WP_Error( 'qc_bad_type', __( 'That file is not a valid .docx or .pdf document.', 'qhta-competitions' ) );
		}
		return array(
			'ext'  => $ext,
			'mime' => $mime,
		);
	}

	/**
	 * finfo MIME type.
	 *
	 * @param string $path Path.
	 * @return string
	 */
	private static function sniff( $path ) {
		if ( ! function_exists( 'finfo_open' ) ) {
			return '';
		}
		$fi   = finfo_open( FILEINFO_MIME_TYPE );
		$mime = $fi ? (string) finfo_file( $fi, $path ) : '';
		if ( $fi ) {
			finfo_close( $fi );
		}
		return $mime;
	}

	/**
	 * Structural check beyond MIME: PDF magic bytes; .docx is a zip with word/document.xml.
	 *
	 * @param string $path Path.
	 * @param string $ext  Extension.
	 * @return bool
	 */
	private static function looks_like( $path, $ext ) {
		if ( 'pdf' === $ext ) {
			$fh   = fopen( $path, 'rb' );
			$head = $fh ? (string) fread( $fh, 5 ) : '';
			if ( $fh ) {
				fclose( $fh );
			}
			return '%PDF-' === $head;
		}
		if ( ! class_exists( 'ZipArchive' ) ) {
			return false;
		}
		$zip = new ZipArchive();
		if ( true !== $zip->open( $path ) ) {
			return false;
		}
		$ok = false !== $zip->locateName( 'word/document.xml' );
		$zip->close();
		return $ok;
	}

	/**
	 * Move a validated upload into protected storage.
	 *
	 * @param array  $file       $_FILES entry.
	 * @param string $ext        Validated extension.
	 * @param int    $program_id Program ID.
	 * @return string|WP_Error Path relative to the protected base dir.
	 */
	public static function store( array $file, $ext, $program_id ) {
		$dir = Qhta_Competitions_Plugin::upload_base_dir() . (int) $program_id . '/';
		Qhta_Competitions_Plugin::protect_upload_dir();
		if ( ! Qhta_Competitions_Plugin::protect_upload_dir( $dir ) ) {
			return new WP_Error( 'qc_store_failed', __( 'The file could not be saved. Please try again later.', 'qhta-competitions' ) );
		}
		$name = bin2hex( random_bytes( 16 ) ) . '.' . $ext;
		if ( ! move_uploaded_file( $file['tmp_name'], $dir . $name ) ) {
			return new WP_Error( 'qc_store_failed', __( 'The file could not be saved. Please try again later.', 'qhta-competitions' ) );
		}
		chmod( $dir . $name, 0640 );
		return (int) $program_id . '/' . $name;
	}

	/**
	 * Absolute path for a stored relative path, or '' if it escapes the base dir.
	 *
	 * @param string $rel Relative path.
	 * @return string
	 */
	public static function abs_path( $rel ) {
		if ( ! preg_match( '#^\d+/[a-f0-9]{32}\.(docx|pdf)$#', (string) $rel ) ) {
			return '';
		}
		return Qhta_Competitions_Plugin::upload_base_dir() . $rel;
	}

	/**
	 * Stored files for a submission.
	 *
	 * @param int $submission_id Submission ID.
	 * @return array<int, array>
	 */
	public static function get( $submission_id ) {
		$files = Qhta_Competitions_Util::get_json_meta( $submission_id, self::META, array() );
		return is_array( $files ) ? $files : array();
	}

	/**
	 * Signed download URL.
	 *
	 * @param int    $submission_id Submission ID.
	 * @param string $field         Field key.
	 * @return string
	 */
	public static function download_url( $submission_id, $field ) {
		return wp_nonce_url(
			add_query_arg(
				array(
					'action' => 'qc_file',
					'id'     => (int) $submission_id,
					'field'  => $field,
				),
				admin_url( 'admin-post.php' )
			),
			'qc_file_' . (int) $submission_id
		);
	}

	/**
	 * Whether the current user may download a submission's files: admins, or a
	 * judge assigned to its category (assignment arrives in M4 via the filter).
	 *
	 * @param int $submission_id Submission ID.
	 * @return bool
	 */
	public static function user_can_download( $submission_id ) {
		if ( current_user_can( Qhta_Competitions_Plugin::CAP_MANAGE ) ) {
			return true;
		}
		if ( ! current_user_can( Qhta_Competitions_Plugin::CAP_JUDGE ) ) {
			return false;
		}
		return (bool) apply_filters( 'qc_judge_can_access_submission', false, (int) $submission_id, get_current_user_id() );
	}

	/**
	 * admin-post handler: stream a protected file as an attachment named {ref}.{ext}.
	 */
	public static function handle_download() {
		$id    = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;
		$field = isset( $_GET['field'] ) ? sanitize_key( wp_unslash( $_GET['field'] ) ) : '';
		$nonce = isset( $_GET['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ) : '';

		if ( ! $id || ! wp_verify_nonce( $nonce, 'qc_file_' . $id ) ) {
			wp_die( esc_html__( 'This download link has expired. Go back and try again.', 'qhta-competitions' ), 403 );
		}
		if ( Qhta_Competitions_Submission_CPT::POST_TYPE !== get_post_type( $id ) || ! self::user_can_download( $id ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to download this file.', 'qhta-competitions' ), 403 );
		}
		$ref  = null;
		foreach ( self::get( $id ) as $f ) {
			if ( isset( $f['field'] ) && $f['field'] === $field ) {
				$ref = $f;
			}
		}
		$path = $ref ? self::abs_path( $ref['path'] ) : '';
		if ( '' === $path || ! is_readable( $path ) ) {
			wp_die( esc_html__( 'File not found.', 'qhta-competitions' ), 404 );
		}

		$code     = (string) get_post_meta( $id, Qhta_Competitions_Submission_CPT::REF_META, true );
		$filename = sanitize_file_name( ( '' !== $code ? $code : 'submission-' . $id ) . '.' . $ref['ext'] );
		$types    = array(
			'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
			'pdf'  => 'application/pdf',
		);

		nocache_headers();
		header( 'Content-Type: ' . $types[ $ref['ext'] ] );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
		header( 'Content-Length: ' . filesize( $path ) );
		header( 'X-Content-Type-Options: nosniff' );
		while ( ob_get_level() ) {
			ob_end_clean();
		}
		readfile( $path );
		exit;
	}

	/**
	 * Remove a submission's files when the post is permanently deleted.
	 *
	 * @param int     $post_id Post ID.
	 * @param WP_Post $post    Post.
	 */
	public static function on_delete_submission( $post_id, $post = null ) {
		$post = $post ? $post : get_post( $post_id );
		if ( ! $post || Qhta_Competitions_Submission_CPT::POST_TYPE !== $post->post_type ) {
			return;
		}
		self::delete_all( $post_id );
	}

	/**
	 * Delete every stored file of a submission (also used by retention, M5).
	 *
	 * @param int $submission_id Submission ID.
	 */
	public static function delete_all( $submission_id ) {
		foreach ( self::get( $submission_id ) as $f ) {
			$path = isset( $f['path'] ) ? self::abs_path( $f['path'] ) : '';
			if ( '' !== $path && file_exists( $path ) ) {
				wp_delete_file( $path );
			}
		}
	}
}
