<?php
/**
 * Word counting (spec §5.6). Footnotes count; bibliography doesn't.
 *
 * A "word" is a run of non-space characters containing at least one letter or
 * digit — close to Word's own count, which treats stray punctuation (a lone
 * dash) as non-words in most cases.
 *
 * @package Qhta_Competitions
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Inline and .docx word counts.
 */
final class Qhta_Competitions_Wordcount {

	const NS_W  = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main';
	const NS_MC = 'http://schemas.openxmlformats.org/markup-compatibility/2006';

	/**
	 * Count words in plain or lightly marked-up text.
	 *
	 * @param string $text Text.
	 * @return int
	 */
	public static function count_text( $text ) {
		$text = html_entity_decode( wp_strip_all_tags( (string) $text ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$parts = preg_split( '/[\s\x{00A0}\x{2000}-\x{200B}\x{3000}]+/u', $text, -1, PREG_SPLIT_NO_EMPTY );
		if ( ! is_array( $parts ) ) {
			return 0;
		}
		$n = 0;
		foreach ( $parts as $p ) {
			if ( preg_match( '/[\p{L}\p{N}]/u', $p ) ) {
				++$n;
			}
		}
		return $n;
	}

	/**
	 * Count a .docx.
	 *
	 * @param string $path  File path.
	 * @param array  $rules Round word_count_rules {include_footnotes, exclude_after_headings[]}.
	 * @return array{total:int, body:int, notes:int, excluded:int, stopped_at:string}|WP_Error
	 */
	public static function count_docx( $path, array $rules ) {
		if ( ! class_exists( 'ZipArchive' ) ) {
			return new WP_Error( 'qc_no_zip', 'ZipArchive is not available.' );
		}
		$zip = new ZipArchive();
		if ( true !== $zip->open( $path ) ) {
			return new WP_Error( 'qc_bad_docx', 'Not a readable .docx file.' );
		}
		$document = $zip->getFromName( 'word/document.xml' );
		if ( false === $document ) {
			$zip->close();
			return new WP_Error( 'qc_bad_docx', 'The .docx has no document body.' );
		}
		$styles    = $zip->getFromName( 'word/styles.xml' );
		$footnotes = $zip->getFromName( 'word/footnotes.xml' );
		$endnotes  = $zip->getFromName( 'word/endnotes.xml' );
		$zip->close();

		$headings = array();
		foreach ( isset( $rules['exclude_after_headings'] ) ? (array) $rules['exclude_after_headings'] : array() as $h ) {
			$headings[] = self::norm_heading( $h );
		}
		$heading_styles = self::heading_style_ids( false === $styles ? '' : $styles );

		$body     = 0;
		$excluded = 0;
		$stopped  = '';
		$dom      = self::load_xml( $document );
		if ( ! $dom ) {
			return new WP_Error( 'qc_bad_docx', 'The .docx body could not be read.' );
		}
		$bodies = $dom->getElementsByTagNameNS( self::NS_W, 'body' );
		if ( $bodies->length ) {
			foreach ( self::paragraphs( $bodies->item( 0 ) ) as $p ) {
				$text  = self::paragraph_text( $p );
				$words = self::count_text( $text );
				if ( '' === $stopped && $headings && self::is_heading_like( $p, $heading_styles ) && in_array( self::norm_heading( $text ), $headings, true ) ) {
					$stopped = trim( $text );
				}
				if ( '' === $stopped ) {
					$body += $words;
				} else {
					$excluded += $words;
				}
			}
		}

		$notes = 0;
		foreach ( array( array( $footnotes, 'footnote' ), array( $endnotes, 'endnote' ) ) as $pair ) {
			if ( false !== $pair[0] && '' !== $pair[0] ) {
				$notes += self::count_notes( $pair[0], $pair[1] );
			}
		}

		$include_notes = ! isset( $rules['include_footnotes'] ) || $rules['include_footnotes'];
		return array(
			'total'      => $body + ( $include_notes ? $notes : 0 ),
			'body'       => $body,
			'notes'      => $notes,
			'excluded'   => $excluded,
			'stopped_at' => $stopped,
		);
	}

	/**
	 * Parse XML safely (no network, no entity expansion).
	 *
	 * @param string $xml XML.
	 * @return DOMDocument|null
	 */
	private static function load_xml( $xml ) {
		$dom  = new DOMDocument();
		$prev = libxml_use_internal_errors( true );
		$ok   = $dom->loadXML( $xml, LIBXML_NONET | LIBXML_COMPACT | LIBXML_PARSEHUGE );
		libxml_clear_errors();
		libxml_use_internal_errors( $prev );
		return $ok ? $dom : null;
	}

	/**
	 * Normalise heading text for comparison: case-insensitive, trailing colon/space ignored.
	 *
	 * @param string $s Text.
	 * @return string
	 */
	private static function norm_heading( $s ) {
		$s = preg_replace( '/[\s\x{00A0}]+/u', ' ', (string) $s );
		return mb_strtolower( rtrim( trim( $s ), ': ' ), 'UTF-8' );
	}

	/**
	 * Style IDs whose name is a heading or title ("heading 1", "Title"…), plus their IDs.
	 *
	 * @param string $styles styles.xml.
	 * @return array<string,true>
	 */
	private static function heading_style_ids( $styles ) {
		$ids = array();
		if ( '' === $styles ) {
			return $ids;
		}
		$dom = self::load_xml( $styles );
		if ( ! $dom ) {
			return $ids;
		}
		foreach ( $dom->getElementsByTagNameNS( self::NS_W, 'style' ) as $style ) {
			$id   = $style->getAttributeNS( self::NS_W, 'styleId' );
			$name = '';
			foreach ( $style->childNodes as $c ) {
				if ( $c instanceof DOMElement && 'name' === $c->localName ) {
					$name = $c->getAttributeNS( self::NS_W, 'val' );
				}
			}
			if ( preg_match( '/^(heading\s*\d|title|subtitle)$/i', $name ) || preg_match( '/^(heading\d|title|subtitle)$/i', $id ) ) {
				$ids[ $id ] = true;
			}
		}
		return $ids;
	}

	/**
	 * Body paragraphs in document order, including those inside tables and
	 * content controls; skips mc:Fallback (duplicate text-box content).
	 *
	 * @param DOMNode $node Container.
	 * @return DOMElement[]
	 */
	private static function paragraphs( DOMNode $node ) {
		$out = array();
		foreach ( $node->childNodes as $c ) {
			if ( ! $c instanceof DOMElement ) {
				continue;
			}
			if ( self::NS_MC === $c->namespaceURI && 'Fallback' === $c->localName ) {
				continue;
			}
			if ( self::NS_W === $c->namespaceURI && 'p' === $c->localName ) {
				$out[] = $c;
				// Text boxes nested inside a paragraph's runs.
				foreach ( self::nested_text_boxes( $c ) as $box ) {
					$out = array_merge( $out, self::paragraphs( $box ) );
				}
				continue;
			}
			$out = array_merge( $out, self::paragraphs( $c ) );
		}
		return $out;
	}

	/**
	 * w:txbxContent elements inside a paragraph (not under mc:Fallback).
	 *
	 * @param DOMElement $p Paragraph.
	 * @return DOMElement[]
	 */
	private static function nested_text_boxes( DOMElement $p ) {
		$out = array();
		foreach ( $p->getElementsByTagNameNS( self::NS_W, 'txbxContent' ) as $box ) {
			$skip = false;
			for ( $a = $box->parentNode; $a && $a !== $p; $a = $a->parentNode ) {
				if ( self::NS_MC === $a->namespaceURI && 'Fallback' === $a->localName ) {
					$skip = true;
					break;
				}
			}
			if ( ! $skip ) {
				$out[] = $box;
			}
		}
		return $out;
	}

	/**
	 * Visible text of one paragraph (its own runs, not nested text boxes).
	 * Deleted text and field codes are ignored; tabs and breaks separate words.
	 *
	 * @param DOMElement $p Paragraph.
	 * @return string
	 */
	private static function paragraph_text( DOMElement $p ) {
		$text = '';
		self::collect_text( $p, $text, true );
		return $text;
	}

	/**
	 * Recursive text collector.
	 *
	 * @param DOMNode $node Node.
	 * @param string  $text Accumulator.
	 * @param bool    $root Whether $node is the paragraph itself.
	 */
	private static function collect_text( DOMNode $node, &$text, $root = false ) {
		foreach ( $node->childNodes as $c ) {
			if ( ! $c instanceof DOMElement ) {
				continue;
			}
			if ( self::NS_MC === $c->namespaceURI && 'Fallback' === $c->localName ) {
				continue;
			}
			if ( self::NS_W === $c->namespaceURI ) {
				switch ( $c->localName ) {
					case 't':
						$text .= $c->textContent;
						continue 2;
					case 'tab':
					case 'br':
					case 'cr':
						$text .= ' ';
						continue 2;
					case 'noBreakHyphen':
						$text .= '-';
						continue 2;
					case 'delText':
					case 'instrText':
					case 'del':
					case 'txbxContent':
					case 'pPr':
					case 'rPr':
						continue 2;
					case 'p':
						if ( ! $root ) {
							continue 2;
						}
				}
			}
			self::collect_text( $c, $text );
		}
	}

	/**
	 * Heading-styled, or every text run bold.
	 *
	 * @param DOMElement          $p      Paragraph.
	 * @param array<string,true>  $styles Heading style IDs.
	 * @return bool
	 */
	private static function is_heading_like( DOMElement $p, array $styles ) {
		foreach ( $p->childNodes as $c ) {
			if ( $c instanceof DOMElement && 'pPr' === $c->localName ) {
				foreach ( $c->childNodes as $pc ) {
					if ( $pc instanceof DOMElement && 'pStyle' === $pc->localName ) {
						$id = $pc->getAttributeNS( self::NS_W, 'val' );
						if ( isset( $styles[ $id ] ) || preg_match( '/^(heading\d|title)$/i', $id ) ) {
							return true;
						}
					}
					if ( $pc instanceof DOMElement && 'outlineLvl' === $pc->localName ) {
						return true;
					}
				}
			}
		}

		// Whole-paragraph bold: every run that carries text is bold.
		$runs_with_text = 0;
		foreach ( $p->getElementsByTagNameNS( self::NS_W, 'r' ) as $r ) {
			$t = '';
			foreach ( $r->getElementsByTagNameNS( self::NS_W, 't' ) as $tn ) {
				$t .= $tn->textContent;
			}
			if ( '' === trim( $t ) ) {
				continue;
			}
			++$runs_with_text;
			$bold = false;
			foreach ( $r->childNodes as $rc ) {
				if ( $rc instanceof DOMElement && 'rPr' === $rc->localName ) {
					foreach ( $rc->childNodes as $b ) {
						if ( $b instanceof DOMElement && 'b' === $b->localName ) {
							$val  = $b->getAttributeNS( self::NS_W, 'val' );
							$bold = ! in_array( $val, array( '0', 'false', 'off' ), true );
						}
					}
				}
			}
			if ( ! $bold ) {
				return false;
			}
		}
		return $runs_with_text > 0;
	}

	/**
	 * Words in footnotes.xml / endnotes.xml, skipping separator notes (ids -1/0 or typed).
	 *
	 * @param string $xml  XML.
	 * @param string $kind footnote|endnote.
	 * @return int
	 */
	private static function count_notes( $xml, $kind ) {
		$dom = self::load_xml( $xml );
		if ( ! $dom ) {
			return 0;
		}
		$n = 0;
		foreach ( $dom->getElementsByTagNameNS( self::NS_W, $kind ) as $note ) {
			$id   = $note->getAttributeNS( self::NS_W, 'id' );
			$type = $note->getAttributeNS( self::NS_W, 'type' );
			if ( in_array( $id, array( '-1', '0' ), true ) || ( '' !== $type && 'normal' !== $type ) ) {
				continue;
			}
			foreach ( self::paragraphs( $note ) as $p ) {
				$n += self::count_text( self::paragraph_text( $p ) );
			}
		}
		return $n;
	}
}
