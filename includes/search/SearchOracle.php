<?php
/**
 * Oracle search engine
 *
 * Copyright © 2004 Brion Vibber <brion@pobox.com>
 * https://www.mediawiki.org/
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License along
 * with this program; if not, write to the Free Software Foundation, Inc.,
 * 51 Franklin Street, Fifth Floor, Boston, MA 02110-1301, USA.
 * http://www.gnu.org/copyleft/gpl.html
 *
 * @file
 * @ingroup Search
 */

use MediaWiki\MediaWikiServices;

/**
 * Search engine hook base class for Oracle (ConText).
 * @ingroup Search
 */
class SearchOracle extends SearchDatabase {
	private $reservedWords = [
		'ABOUT' => 1,
		'ACCUM' => 1,
		'AND' => 1,
		'BT' => 1,
		'BTG' => 1,
		'BTI' => 1,
		'BTP' => 1,
		'FUZZY' => 1,
		'HASPATH' => 1,
		'INPATH' => 1,
		'MINUS' => 1,
		'NEAR' => 1,
		'NOT' => 1,
		'NT' => 1,
		'NTG' => 1,
		'NTI' => 1,
		'NTP' => 1,
		'OR' => 1,
		'PT' => 1,
		'RT' => 1,
		'SQE' => 1,
		'SYN' => 1,
		'TR' => 1,
		'TRSYN' => 1,
		'TT' => 1,
		'WITHIN' => 1,
	];

	/**
	 * Perform a full text search query and return a result set.
	 *
	 * @param string $term Raw search term
	 * @return SqlSearchResultSet|null
	 */
	protected function doSearchTextInDB( $term ) {
		if ( $term == '' ) {
			return null;
		}

		$dbr = $this->lb->getConnectionRef( DB_REPLICA );
		$resultSet = $dbr->query( $this->getQuery( $this->filter( $term ), true ) );
		return new SqlSearchResultSet( $resultSet, $this->searchTerms );
	}

	/**
	 * Perform a title-only search query and return a result set.
	 *
	 * @param string $term Raw search term
	 * @return SqlSearchResultSet|null
	 */
	protected function doSearchTitleInDB( $term ) {
		if ( $term == '' ) {
			return null;
		}

		$dbr = $this->lb->getConnectionRef( DB_REPLICA );
		$resultSet = $dbr->query( $this->getQuery( $this->filter( $term ), false ) );
		return new SqlSearchResultSet( $resultSet, $this->searchTerms );
	}

	/**
	 * Return a partial WHERE clause to limit the search to the given namespaces
	 * @return string
	 */
	private function queryNamespaces() {
		if ( is_null( $this->namespaces ) ) {
			return '';
		}
		if ( $this->namespaces === [] ) {
			$namespaces = '0';
		} else {
			$dbr = $this->lb->getConnectionRef( DB_REPLICA );
			$namespaces = $dbr->makeList( $this->namespaces );
		}
		return 'AND page_namespace IN (' . $namespaces . ')';
	}

	/**
	 * Return a LIMIT clause to limit results on the query.
	 *
	 * @param string $sql
	 *
	 * @return string
	 */
	private function queryLimit( $sql ) {
		$dbr = $this->lb->getConnectionRef( DB_REPLICA );

		return $dbr->limitResult( $sql, $this->limit, $this->offset );
	}

	/**
	 * Does not do anything for generic search engine
	 * subclasses may define this though
	 *
	 * @param string $filteredTerm
	 * @param bool $fulltext
	 * @return string
	 */
	function queryRanking( $filteredTerm, $fulltext ) {
		return ' ORDER BY score(1)';
	}

	/**
	 * Construct the full SQL query to do the search.
	 * The guts shoulds be constructed in queryMain()
	 * @param string $filteredTerm
	 * @param bool $fulltext
	 * @return string
	 */
	private function getQuery( $filteredTerm, $fulltext ) {
		return $this->queryLimit( $this->queryMain( $filteredTerm, $fulltext ) . ' ' .
			$this->queryNamespaces() . ' ' .
			$this->queryRanking( $filteredTerm, $fulltext ) . ' ' );
	}

	/**
	 * Picks which field to index on, depending on what type of query.
	 * @param bool $fulltext
	 * @return string
	 */
	private function getIndexField( $fulltext ) {
		return $fulltext ? 'si_text' : 'si_title';
	}

	/**
	 * Get the base part of the search query.
	 *
	 * @param string $filteredTerm
	 * @param bool $fulltext
	 * @return string
	 */
	function queryMain( $filteredTerm, $fulltext ) {
		$match = $this->parseQuery( $filteredTerm, $fulltext );

		$dbr = $this->lb->getMaintenanceConnectionRef( DB_REPLICA );
		$page = $dbr->tableName( 'page' );
		$searchindex = $dbr->tableName( 'searchindex' );

		return 'SELECT page_id, page_namespace, page_title ' .
			"FROM $page,$searchindex " .
			'WHERE page_id=si_page AND ' . $match;
	}

	/**
	 * Parse a user input search string, and return an SQL fragment to be used
	 * as part of a WHERE clause
	 * @param string $filteredText
	 * @param bool $fulltext
	 * @return string
	 */
	private function parseQuery( $filteredText, $fulltext ) {
		$lc = $this->legalSearchChars( self::CHARS_NO_SYNTAX );
		$this->searchTerms = [];

		# @todo FIXME: This doesn't handle parenthetical expressions.
		$m = [];
		$searchon = '';
		if ( preg_match_all( '/([-+<>~]?)(([' . $lc . ']+)(\*?)|"[^"]*")/',
				$filteredText, $m, PREG_SET_ORDER ) ) {
			foreach ( $m as $terms ) {
				// Search terms in all variant forms, only
				// apply on wiki with LanguageConverter
				$temp_terms = MediaWikiServices::getInstance()->getContentLanguage()->
					autoConvertToAllVariants( $terms[2] );
				if ( is_array( $temp_terms ) ) {
					$temp_terms = array_unique( array_values( $temp_terms ) );
					foreach ( $temp_terms as $t ) {
						$searchon .= ( $terms[1] == '-' ? ' ~' : ' & ' ) . $this->escapeTerm( $t );
					}
				} else {
					$searchon .= ( $terms[1] == '-' ? ' ~' : ' & ' ) . $this->escapeTerm( $terms[2] );
				}
				if ( !empty( $terms[3] ) ) {
					$regexp = preg_quote( $terms[3], '/' );
					if ( $terms[4] ) {
						$regexp .= "[0-9A-Za-z_]+";
					}
				} else {
					$regexp = preg_quote( str_replace( '"', '', $terms[2] ), '/' );
				}
				$this->searchTerms[] = $regexp;
			}
		}

		$dbr = $this->lb->getConnectionRef( DB_REPLICA );
		$searchon = $dbr->addQuotes( ltrim( $searchon, ' &' ) );
		$field = $this->getIndexField( $fulltext );

		return " CONTAINS($field, $searchon, 1) > 0 ";
	}

	private function escapeTerm( $t ) {
		$t = MediaWikiServices::getInstance()->getContentLanguage()->normalizeForSearch( $t );
		$t = isset( $this->reservedWords[strtoupper( $t )] ) ? '{' . $t . '}' : $t;
		$t = preg_replace( '/^"(.*)"$/', '($1)', $t );
		$t = preg_replace( '/([-&|])/', '\\\\$1', $t );
		return $t;
	}

	/**
	 * Create or update the search index record for the given page.
	 * Title and text should be pre-processed.
	 *
	 * @param int $id
	 * @param string $title
	 * @param string $text
	 */
	function update( $id, $title, $text ) {
		$dbw = $this->lb->getMaintenanceConnectionRef( DB_MASTER );
		$dbw->replace( 'searchindex',
			[ 'si_page' ],
			[
				'si_page' => $id,
				'si_title' => $title,
				'si_text' => $text
			], 'SearchOracle::update' );

		// Sync the index
		// We need to specify the DB name (i.e. user/schema) here so that
		// it can work from the installer, where
		//     ALTER SESSION SET CURRENT_SCHEMA = ...
		// was used.
		$dbw->query( "CALL ctx_ddl.sync_index(" .
			$dbw->addQuotes( $dbw->getDBname() . '.' . $dbw->tableName( 'si_text_idx', 'raw' ) ) . ")" );
		$dbw->query( "CALL ctx_ddl.sync_index(" .
			$dbw->addQuotes( $dbw->getDBname() . '.' . $dbw->tableName( 'si_title_idx', 'raw' ) ) . ")" );
	}

	/**
	 * Update a search index record's title only.
	 * Title should be pre-processed.
	 *
	 * @param int $id
	 * @param string $title
	 */
	function updateTitle( $id, $title ) {
		$dbw = $this->lb->getConnectionRef( DB_MASTER );
		$dbw->update( 'searchindex',
			[ 'si_title' => $title ],
			[ 'si_page' => $id ],
			'SearchOracle::updateTitle',
			[] );
	}

	public function legalSearchChars( $type = self::CHARS_ALL ) {
		$searchChars = parent::legalSearchChars( $type );
		if ( $type === self::CHARS_ALL ) {
			$searchChars = "\"" . $searchChars;
		}
		return $searchChars;
	}
}
