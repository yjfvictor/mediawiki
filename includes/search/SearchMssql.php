<?php
/**
 * Mssql search engine
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
use Wikimedia\Rdbms\IResultWrapper;

/**
 * Search engine hook base class for Mssql (ConText).
 * @ingroup Search
 */
class SearchMssql extends SearchDatabase {
	/**
	 * Perform a full text search query and return a result set.
	 *
	 * @param string $term Raw search term
	 * @return SqlSearchResultSet|null
	 */
	protected function doSearchTextInDB( $term ) {
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
		$dbr = $this->lb->getConnectionRef( DB_REPLICA );
		$resultSet = $dbr->query( $this->getQuery( $this->filter( $term ), false ) );

		return new SqlSearchResultSet( $resultSet, $this->searchTerms );
	}

	/**
	 * Return a partial WHERE clause to limit the search to the given namespaces
	 *
	 * @return string
	 */
	private function queryNamespaces() {
		$namespaces = implode( ',', $this->namespaces );
		if ( $namespaces == '' ) {
			$namespaces = '0';
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
		return ' ORDER BY ftindex.[RANK] DESC'; // return ' ORDER BY score(1)';
	}

	/**
	 * Construct the full SQL query to do the search.
	 * The guts shoulds be constructed in queryMain()
	 *
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
	 *
	 * @param bool $fulltext
	 * @return string
	 */
	function getIndexField( $fulltext ) {
		return $fulltext ? 'si_text' : 'si_title';
	}

	/**
	 * Get the base part of the search query.
	 *
	 * @param string $filteredTerm
	 * @param bool $fulltext
	 * @return string
	 */
	private function queryMain( $filteredTerm, $fulltext ) {
		$match = $this->parseQuery( $filteredTerm, $fulltext );
		$dbr = $this->lb->getMaintenanceConnectionRef( DB_REPLICA );
		$page = $dbr->tableName( 'page' );
		$searchindex = $dbr->tableName( 'searchindex' );

		return 'SELECT page_id, page_namespace, page_title, ftindex.[RANK]' .
			"FROM $page,FREETEXTTABLE($searchindex , $match, LANGUAGE 'English') as ftindex " .
			'WHERE page_id=ftindex.[KEY] ';
	}

	/** @todo document
	 * @param string $filteredText
	 * @param bool $fulltext
	 * @return string
	 */
	private function parseQuery( $filteredText, $fulltext ) {
		$lc = $this->legalSearchChars( self::CHARS_NO_SYNTAX );
		$this->searchTerms = [];

		# @todo FIXME: This doesn't handle parenthetical expressions.
		$m = [];
		$q = [];

		if ( preg_match_all( '/([-+<>~]?)(([' . $lc . ']+)(\*?)|"[^"]*")/',
			$filteredText, $m, PREG_SET_ORDER ) ) {
			foreach ( $m as $terms ) {
				$q[] = $terms[1] . MediaWikiServices::getInstance()->getContentLanguage()->
					normalizeForSearch( $terms[2] );

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
		$searchon = $dbr->addQuotes( implode( ',', $q ) );
		$field = $this->getIndexField( $fulltext );

		return "$field, $searchon";
	}

	/**
	 * Create or update the search index record for the given page.
	 * Title and text should be pre-processed.
	 *
	 * @param int $id
	 * @param string $title
	 * @param string $text
	 * @return bool|IResultWrapper
	 */
	function update( $id, $title, $text ) {
		// We store the column data as UTF-8 byte order marked binary stream
		// because we are invoking the plain text IFilter on it so that, and we want it
		// to properly decode the stream as UTF-8.  SQL doesn't support UTF8 as a data type
		// but the indexer will correctly handle it by this method.  Since all we are doing
		// is passing this data to the indexer and never retrieving it via PHP, this will save space
		$dbr = $this->lb->getMaintenanceConnectionRef( DB_MASTER );
		$table = $dbr->tableName( 'searchindex' );
		$utf8bom = '0xEFBBBF';
		$si_title = $utf8bom . bin2hex( $title );
		$si_text = $utf8bom . bin2hex( $text );
		$sql = "DELETE FROM $table WHERE si_page = $id;";
		$sql .= "INSERT INTO $table (si_page, si_title, si_text) VALUES ($id, $si_title, $si_text)";
		return $dbr->query( $sql, 'SearchMssql::update' );
	}

	/**
	 * Update a search index record's title only.
	 * Title should be pre-processed.
	 *
	 * @param int $id
	 * @param string $title
	 * @return bool|IResultWrapper
	 */
	function updateTitle( $id, $title ) {
		$dbr = $this->lb->getMaintenanceConnectionRef( DB_MASTER );
		$table = $dbr->tableName( 'searchindex' );

		// see update for why we are using the utf8bom
		$utf8bom = '0xEFBBBF';
		$si_title = $utf8bom . bin2hex( $title );
		$sql = "DELETE FROM $table WHERE si_page = $id;";
		$sql .= "INSERT INTO $table (si_page, si_title, si_text) VALUES ($id, $si_title, 0x00)";
		return $dbr->query( $sql, 'SearchMssql::updateTitle' );
	}
}
