<?php
/** Explicit, bounded import of factual catalog labels only. */
defined( 'ABSPATH' ) || exit;

final class MRSA_Importer {
	const SOURCE = 'https://www.totalmotorcycle.com/motorcyclemodelguides-index';

	public static function import() {
		if ( ! class_exists( 'DOMDocument' ) ) {
			throw new RuntimeException( 'The PHP DOM extension is unavailable. Use manual entry or factual CSV rows instead.' );
		}
		$queue = array( self::SOURCE );
		$seen = array();
		$rows = array();
		// No redirects, alternate hosts, images, assets, articles or unbounded crawling.
		while ( $queue && count( $seen ) < 6 && count( $rows ) < 500 ) {
			$url = array_shift( $queue );
			if ( isset( $seen[ $url ] ) ) {
				continue;
			}
			$seen[ $url ] = true;
			$response = wp_safe_remote_get( $url, array( 'timeout' => 8, 'redirection' => 0, 'limit_response_size' => 1048576, 'headers' => array( 'Accept' => 'text/html' ) ) );
			if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
				continue;
			}
			$html = wp_remote_retrieve_body( $response );
			$doc = new DOMDocument();
			$previous = libxml_use_internal_errors( true );
			$loaded = $doc->loadHTML( $html, LIBXML_NONET );
			libxml_clear_errors();
			libxml_use_internal_errors( $previous );
			if ( ! $loaded ) {
				continue;
			}
			foreach ( $doc->getElementsByTagName( 'a' ) as $a ) {
				$text = trim( preg_replace( '/\s+/u', ' ', $a->textContent ) );
				if ( preg_match( '/^((?:19|20)\d{2})\s+(Aprilia|BMW|Buell|CFMoto|Ducati|Harley-Davidson|Honda|Husqvarna|Indian|Kawasaki|KTM|Moto Guzzi|MV Agusta|Royal Enfield|Suzuki|Triumph|Ural|Vespa|Yamaha|Zero)\s+(.+)$/iu', $text, $m ) ) {
					$model = trim( $m[3] );
					if ( strlen( $model ) <= 120 && ! preg_match( '/\b(guide|review|news|price|specification|motorcycles|lineup|models|overview|photos)\b/i', $model ) ) {
						$key = strtolower( $m[2] . '/' . $model . '/' . $m[1] );
						$rows[ $key ] = array( 'make' => sanitize_text_field( $m[2] ), 'model' => sanitize_text_field( $model ), 'year' => (int) $m[1], 'source' => self::SOURCE );
					}
				}
				$href = self::guide_url( $a->getAttribute( 'href' ), $url );
				if ( $href && ! isset( $seen[ $href ] ) && count( $queue ) < 30 ) {
					$queue[] = $href;
				}
				if ( count( $rows ) >= 500 ) {
					break;
				}
			}
		}
		if ( ! $rows ) {
			throw new RuntimeException( 'No unambiguous make/model/year rows could be extracted, or the source was unavailable. No records were invented. Use manual entry or the factual CSV fallback.' );
		}
		return self::store( array_values( $rows ) );
	}

	public static function guide_url( $href, $base ) {
		if ( 0 === strpos( $href, '/' ) && 0 !== strpos( $href, '//' ) ) {
			$href = 'https://www.totalmotorcycle.com' . $href;
		} elseif ( ! preg_match( '~^[a-z]+:~i', $href ) && preg_match( '~^[a-zA-Z0-9/_-]+(?:\.htm[l]?)?$~', $href ) ) {
			$href = substr( $base, 0, strrpos( $base, '/' ) + 1 ) . $href;
		}
		$p = wp_parse_url( $href );
		if ( ! $p || ( $p['scheme'] ?? '' ) !== 'https' || ( $p['host'] ?? '' ) !== 'www.totalmotorcycle.com' || isset( $p['port'] ) || isset( $p['user'] ) || isset( $p['pass'] ) || isset( $p['query'] ) || isset( $p['fragment'] ) ) {
			return false;
		}
		$path = $p['path'] ?? '';
		return preg_match( '~^/[a-zA-Z0-9/_-]*(?:19|20)\d{2}[a-zA-Z0-9/_-]*(?:guide|model)[a-zA-Z0-9/_-]*(?:\.htm[l]?)?/?$~i', $path ) && false === strpos( $path, '..' ) ? $href : false;
	}

	public static function csv( $text ) {
		if ( strlen( $text ) > 100000 ) {
			throw new RuntimeException( 'CSV exceeds 100 KB.' );
		}
		$lines = preg_split( '/\r\n|\r|\n/', trim( $text ) );
		if ( count( $lines ) > 501 ) {
			throw new RuntimeException( 'Import at most 500 rows plus a header.' );
		}
		$rows = array();
		foreach ( $lines as $i => $line ) {
			if ( '' === trim( $line ) ) {
				continue;
			}
			$cols = str_getcsv( $line, ',', '"', '' );
			if ( 0 === $i && array_map( 'strtolower', array_map( 'trim', $cols ) ) === array( 'make', 'model', 'year' ) ) {
				continue;
			}
			if ( count( $cols ) !== 3 ) {
				throw new RuntimeException( 'Each CSV row must contain make,model,year. Row ' . ( $i + 1 ) . ' is invalid.' );
			}
			$make = MRSA_Admin::text( $cols[0], 80, true );
			$model = MRSA_Admin::text( $cols[1], 120, true );
			$year = MRSA_Admin::integer( trim( $cols[2] ), 1900, 2100 );
			$rows[] = array( 'make' => $make, 'model' => $model, 'year' => $year, 'source' => 'Admin-provided factual CSV' );
		}
		if ( ! $rows ) {
			throw new RuntimeException( 'No factual CSV rows supplied.' );
		}
		return self::store( $rows );
	}

	private static function store( $rows ) {
		global $wpdb;
		$added = 0;
		foreach ( $rows as $row ) {
			if ( $row['year'] < 1900 || $row['year'] > 2100 ) {
				continue;
			}
			$found = $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . MRSA::table( 'bikes' ) . ' WHERE make=%s AND model=%s AND year=%d', $row['make'], $row['model'], $row['year'] ) );
			if ( ! $found ) {
				if ( false === $wpdb->insert( MRSA::table( 'bikes' ), $row ) ) {
					throw new RuntimeException( 'Catalog storage failed. Some preceding rows may have been imported; retrying will skip duplicates.' );
				}
				++$added;
			}
		}
		return $added . ' catalog rows added; duplicate rows skipped.';
	}
}
