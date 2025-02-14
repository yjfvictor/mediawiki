<?php
/**
 * Copyright © 2003-2004 Brion Vibber <brion@pobox.com>
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
 * @ingroup Cache
 */

/**
 * @defgroup Cache Cache
 */

use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Wikimedia\ScopedCallback;

/**
 * Class representing a cache/ephemeral data store
 *
 * This interface is intended to be more or less compatible with the PHP memcached client.
 *
 * Instances of this class should be created with an intended access scope, such as:
 *   - a) A single PHP thread on a server (e.g. stored in a PHP variable)
 *   - b) A single application server (e.g. stored in APC or sqlite)
 *   - c) All application servers in datacenter (e.g. stored in memcached or mysql)
 *   - d) All application servers in all datacenters (e.g. stored via mcrouter or dynomite)
 *
 * Callers should use the proper factory methods that yield BagOStuff instances. Site admins
 * should make sure the configuration for those factory methods matches their access scope.
 * BagOStuff subclasses have widely varying levels of support for replication features.
 *
 * For any given instance, methods like lock(), unlock(), merge(), and set() with WRITE_SYNC
 * should semantically operate over its entire access scope; any nodes/threads in that scope
 * should serialize appropriately when using them. Likewise, a call to get() with READ_LATEST
 * from one node in its access scope should reflect the prior changes of any other node its
 * access scope. Any get() should reflect the changes of any prior set() with WRITE_SYNC.
 *
 * Subclasses should override the default "segmentationSize" field with an appropriate value.
 * The value should not be larger than what the storage backend (by default) supports. It also
 * should be roughly informed by common performance bottlenecks (e.g. values over a certain size
 * having poor scalability). The same goes for the "segmentedValueMaxSize" member, which limits
 * the maximum size and chunk count (indirectly) of values.
 *
 * @ingroup Cache
 */
abstract class BagOStuff implements IExpiringStore, IStoreKeyEncoder, LoggerAwareInterface {
	/** @var LoggerInterface */
	protected $logger;

	/** @var callable|null */
	protected $asyncHandler;
	/** @var int[] Map of (ATTR_* class constant => QOS_* class constant) */
	protected $attrMap = [];

	/** @var bool */
	protected $debugMode = false;

	/** @var float|null */
	private $wallClockOverride;

	/** Bitfield constants for get()/getMulti(); these are only advisory */
	const READ_LATEST = 1; // if supported, avoid reading stale data due to replication
	const READ_VERIFIED = 2; // promise that the caller handles detection of staleness
	/** Bitfield constants for set()/merge(); these are only advisory */
	const WRITE_SYNC = 4; // if supported, block until the write is fully replicated
	const WRITE_CACHE_ONLY = 8; // only change state of the in-memory cache
	const WRITE_ALLOW_SEGMENTS = 16; // allow partitioning of the value if it is large
	const WRITE_PRUNE_SEGMENTS = 32; // delete all the segments if the value is partitioned
	const WRITE_BACKGROUND = 64; // if supported, do not block on completion until the next read

	/**
	 * Parameters include:
	 *   - logger: Psr\Log\LoggerInterface instance
	 *   - asyncHandler: Callable to use for scheduling tasks after the web request ends.
	 *      In CLI mode, it should run the task immediately.
	 * @param array $params
	 */
	public function __construct( array $params = [] ) {
		$this->setLogger( $params['logger'] ?? new NullLogger() );
		$this->asyncHandler = $params['asyncHandler'] ?? null;
	}

	/**
	 * @param LoggerInterface $logger
	 * @return void
	 */
	public function setLogger( LoggerInterface $logger ) {
		$this->logger = $logger;
	}

	/**
	 * @param bool $enabled
	 */
	public function setDebug( $enabled ) {
		$this->debugMode = $enabled;
	}

	/**
	 * Get an item with the given key, regenerating and setting it if not found
	 *
	 * The callback can take $ttl as argument by reference and modify it.
	 * Nothing is stored nor deleted if the callback returns false.
	 *
	 * @param string $key
	 * @param int $ttl Time-to-live (seconds)
	 * @param callable $callback Callback that derives the new value
	 * @param int $flags Bitfield of BagOStuff::READ_* or BagOStuff::WRITE_* constants [optional]
	 * @return mixed The cached value if found or the result of $callback otherwise
	 * @since 1.27
	 */
	final public function getWithSetCallback( $key, $ttl, $callback, $flags = 0 ) {
		$value = $this->get( $key, $flags );

		if ( $value === false ) {
			$value = $callback( $ttl );
			if ( $value !== false ) {
				$this->set( $key, $value, $ttl, $flags );
			}
		}

		return $value;
	}

	/**
	 * Get an item with the given key
	 *
	 * If the key includes a deterministic input hash (e.g. the key can only have
	 * the correct value) or complete staleness checks are handled by the caller
	 * (e.g. nothing relies on the TTL), then the READ_VERIFIED flag should be set.
	 * This lets tiered backends know they can safely upgrade a cached value to
	 * higher tiers using standard TTLs.
	 *
	 * @param string $key
	 * @param int $flags Bitfield of BagOStuff::READ_* constants [optional]
	 * @return mixed Returns false on failure or if the item does not exist
	 */
	abstract public function get( $key, $flags = 0 );

	/**
	 * Set an item
	 *
	 * @param string $key
	 * @param mixed $value
	 * @param int $exptime Either an interval in seconds or a unix timestamp for expiry
	 * @param int $flags Bitfield of BagOStuff::WRITE_* constants
	 * @return bool Success
	 */
	abstract public function set( $key, $value, $exptime = 0, $flags = 0 );

	/**
	 * Delete an item
	 *
	 * For large values written using WRITE_ALLOW_SEGMENTS, this only deletes the main
	 * segment list key unless WRITE_PRUNE_SEGMENTS is in the flags. While deleting the segment
	 * list key has the effect of functionally deleting the key, it leaves unused blobs in cache.
	 *
	 * @param string $key
	 * @return bool True if the item was deleted or not found, false on failure
	 * @param int $flags Bitfield of BagOStuff::WRITE_* constants
	 */
	abstract public function delete( $key, $flags = 0 );

	/**
	 * Insert an item if it does not already exist
	 *
	 * @param string $key
	 * @param mixed $value
	 * @param int $exptime
	 * @param int $flags Bitfield of BagOStuff::WRITE_* constants (since 1.33)
	 * @return bool Success
	 */
	abstract public function add( $key, $value, $exptime = 0, $flags = 0 );

	/**
	 * Merge changes into the existing cache value (possibly creating a new one)
	 *
	 * The callback function returns the new value given the current value
	 * (which will be false if not present), and takes the arguments:
	 * (this BagOStuff, cache key, current value, TTL).
	 * The TTL parameter is reference set to $exptime. It can be overriden in the callback.
	 * Nothing is stored nor deleted if the callback returns false.
	 *
	 * @param string $key
	 * @param callable $callback Callback method to be executed
	 * @param int $exptime Either an interval in seconds or a unix timestamp for expiry
	 * @param int $attempts The amount of times to attempt a merge in case of failure
	 * @param int $flags Bitfield of BagOStuff::WRITE_* constants
	 * @return bool Success
	 * @throws InvalidArgumentException
	 */
	abstract public function merge(
		$key,
		callable $callback,
		$exptime = 0,
		$attempts = 10,
		$flags = 0
	);

	/**
	 * Change the expiration on a key if it exists
	 *
	 * If an expiry in the past is given then the key will immediately be expired
	 *
	 * For large values written using WRITE_ALLOW_SEGMENTS, this only changes the TTL of the
	 * main segment list key. While lowering the TTL of the segment list key has the effect of
	 * functionally lowering the TTL of the key, it might leave unused blobs in cache for longer.
	 * Raising the TTL of such keys is not effective, since the expiration of a single segment
	 * key effectively expires the entire value.
	 *
	 * @param string $key
	 * @param int $exptime TTL or UNIX timestamp
	 * @param int $flags Bitfield of BagOStuff::WRITE_* constants (since 1.33)
	 * @return bool Success Returns false on failure or if the item does not exist
	 * @since 1.28
	 */
	abstract public function changeTTL( $key, $exptime = 0, $flags = 0 );

	/**
	 * Acquire an advisory lock on a key string
	 *
	 * Note that if reentry is enabled, duplicate calls ignore $expiry
	 *
	 * @param string $key
	 * @param int $timeout Lock wait timeout; 0 for non-blocking [optional]
	 * @param int $expiry Lock expiry [optional]; 1 day maximum
	 * @param string $rclass Allow reentry if set and the current lock used this value
	 * @return bool Success
	 */
	abstract public function lock( $key, $timeout = 6, $expiry = 6, $rclass = '' );

	/**
	 * Release an advisory lock on a key string
	 *
	 * @param string $key
	 * @return bool Success
	 */
	abstract public function unlock( $key );

	/**
	 * Get a lightweight exclusive self-unlocking lock
	 *
	 * Note that the same lock cannot be acquired twice.
	 *
	 * This is useful for task de-duplication or to avoid obtrusive
	 * (though non-corrupting) DB errors like INSERT key conflicts
	 * or deadlocks when using LOCK IN SHARE MODE.
	 *
	 * @param string $key
	 * @param int $timeout Lock wait timeout; 0 for non-blocking [optional]
	 * @param int $expiry Lock expiry [optional]; 1 day maximum
	 * @param string $rclass Allow reentry if set and the current lock used this value
	 * @return ScopedCallback|null Returns null on failure
	 * @since 1.26
	 */
	final public function getScopedLock( $key, $timeout = 6, $expiry = 30, $rclass = '' ) {
		$expiry = min( $expiry ?: INF, self::TTL_DAY );

		if ( !$this->lock( $key, $timeout, $expiry, $rclass ) ) {
			return null;
		}

		$lSince = $this->getCurrentTime(); // lock timestamp

		return new ScopedCallback( function () use ( $key, $lSince, $expiry ) {
			$latency = 0.050; // latency skew (err towards keeping lock present)
			$age = ( $this->getCurrentTime() - $lSince + $latency );
			if ( ( $age + $latency ) >= $expiry ) {
				$this->logger->warning(
					"Lock for {key} held too long ({age} sec).",
					[ 'key' => $key, 'age' => $age ]
				);
				return; // expired; it's not "safe" to delete the key
			}
			$this->unlock( $key );
		} );
	}

	/**
	 * Delete all objects expiring before a certain date.
	 * @param string|int $timestamp The reference date in MW or TS_UNIX format
	 * @param callable|null $progress Optional, a function which will be called
	 *     regularly during long-running operations with the percentage progress
	 *     as the first parameter. [optional]
	 * @param int $limit Maximum number of keys to delete [default: INF]
	 *
	 * @return bool Success; false if unimplemented
	 */
	abstract public function deleteObjectsExpiringBefore(
		$timestamp,
		callable $progress = null,
		$limit = INF
	);

	/**
	 * Get an associative array containing the item for each of the keys that have items.
	 * @param string[] $keys List of keys
	 * @param int $flags Bitfield; supports READ_LATEST [optional]
	 * @return mixed[] Map of (key => value) for existing keys
	 */
	abstract public function getMulti( array $keys, $flags = 0 );

	/**
	 * Batch insertion/replace
	 *
	 * This does not support WRITE_ALLOW_SEGMENTS to avoid excessive read I/O
	 *
	 * WRITE_BACKGROUND can be used for bulk insertion where the response is not vital
	 *
	 * @param mixed[] $data Map of (key => value)
	 * @param int $exptime Either an interval in seconds or a unix timestamp for expiry
	 * @param int $flags Bitfield of BagOStuff::WRITE_* constants (since 1.33)
	 * @return bool Success
	 * @since 1.24
	 */
	abstract public function setMulti( array $data, $exptime = 0, $flags = 0 );

	/**
	 * Batch deletion
	 *
	 * This does not support WRITE_ALLOW_SEGMENTS to avoid excessive read I/O
	 *
	 * WRITE_BACKGROUND can be used for bulk deletion where the response is not vital
	 *
	 * @param string[] $keys List of keys
	 * @param int $flags Bitfield of BagOStuff::WRITE_* constants
	 * @return bool Success
	 * @since 1.33
	 */
	abstract public function deleteMulti( array $keys, $flags = 0 );

	/**
	 * Change the expiration of multiple keys that exist
	 *
	 * @see BagOStuff::changeTTL()
	 *
	 * @param string[] $keys List of keys
	 * @param int $exptime TTL or UNIX timestamp
	 * @param int $flags Bitfield of BagOStuff::WRITE_* constants (since 1.33)
	 * @return bool Success
	 * @since 1.34
	 */
	abstract public function changeTTLMulti( array $keys, $exptime, $flags = 0 );

	/**
	 * Increase stored value of $key by $value while preserving its TTL
	 * @param string $key Key to increase
	 * @param int $value Value to add to $key (default: 1) [optional]
	 * @return int|bool New value or false on failure
	 */
	abstract public function incr( $key, $value = 1 );

	/**
	 * Decrease stored value of $key by $value while preserving its TTL
	 * @param string $key
	 * @param int $value Value to subtract from $key (default: 1) [optional]
	 * @return int|bool New value or false on failure
	 */
	abstract public function decr( $key, $value = 1 );

	/**
	 * Increase stored value of $key by $value while preserving its TTL
	 *
	 * This will create the key with value $init and TTL $ttl instead if not present
	 *
	 * @param string $key
	 * @param int $ttl
	 * @param int $value
	 * @param int $init
	 * @return int|bool New value or false on failure
	 * @since 1.24
	 */
	abstract public function incrWithInit( $key, $ttl, $value = 1, $init = 1 );

	/**
	 * Get the "last error" registered; clearLastError() should be called manually
	 * @return int ERR_* constant for the "last error" registry
	 * @since 1.23
	 */
	abstract public function getLastError();

	/**
	 * Clear the "last error" registry
	 * @since 1.23
	 */
	abstract public function clearLastError();

	/**
	 * Let a callback be run to avoid wasting time on special blocking calls
	 *
	 * The callbacks may or may not be called ever, in any particular order.
	 * They are likely to be invoked when something WRITE_SYNC is used used.
	 * They should follow a caching pattern as shown below, so that any code
	 * using the work will get it's result no matter what happens.
	 * @code
	 *     $result = null;
	 *     $workCallback = function () use ( &$result ) {
	 *         if ( !$result ) {
	 *             $result = ....
	 *         }
	 *         return $result;
	 *     }
	 * @endcode
	 *
	 * @param callable $workCallback
	 * @since 1.28
	 */
	abstract public function addBusyCallback( callable $workCallback );

	/**
	 * Construct a cache key.
	 *
	 * @since 1.27
	 * @param string $keyspace
	 * @param array $args
	 * @return string Colon-delimited list of $keyspace followed by escaped components of $args
	 */
	abstract public function makeKeyInternal( $keyspace, $args );

	/**
	 * Make a global cache key.
	 *
	 * @since 1.27
	 * @param string $class Key class
	 * @param string ...$components Key components (starting with a key collection name)
	 * @return string Colon-delimited list of $keyspace followed by escaped components
	 */
	abstract public function makeGlobalKey( $class, ...$components );

	/**
	 * Make a cache key, scoped to this instance's keyspace.
	 *
	 * @since 1.27
	 * @param string $class Key class
	 * @param string ...$components Key components (starting with a key collection name)
	 * @return string Colon-delimited list of $keyspace followed by escaped components
	 */
	abstract public function makeKey( $class, ...$components );

	/**
	 * @param int $flag ATTR_* class constant
	 * @return int QOS_* class constant
	 * @since 1.28
	 */
	public function getQoS( $flag ) {
		return $this->attrMap[$flag] ?? self::QOS_UNKNOWN;
	}

	/**
	 * @return int|float The chunk size, in bytes, of segmented objects (INF for no limit)
	 * @since 1.34
	 */
	public function getSegmentationSize() {
		return INF;
	}

	/**
	 * @return int|float Maximum total segmented object size in bytes (INF for no limit)
	 * @since 1.34
	 */
	public function getSegmentedValueMaxSize() {
		return INF;
	}

	/**
	 * Merge the flag maps of one or more BagOStuff objects into a "lowest common denominator" map
	 *
	 * @param BagOStuff[] $bags
	 * @return int[] Resulting flag map (class ATTR_* constant => class QOS_* constant)
	 */
	final protected function mergeFlagMaps( array $bags ) {
		$map = [];
		foreach ( $bags as $bag ) {
			foreach ( $bag->attrMap as $attr => $rank ) {
				if ( isset( $map[$attr] ) ) {
					$map[$attr] = min( $map[$attr], $rank );
				} else {
					$map[$attr] = $rank;
				}
			}
		}

		return $map;
	}

	/**
	 * @internal For testing only
	 * @return float UNIX timestamp
	 * @codeCoverageIgnore
	 */
	public function getCurrentTime() {
		return $this->wallClockOverride ?: microtime( true );
	}

	/**
	 * @internal For testing only
	 * @param float|null &$time Mock UNIX timestamp
	 * @codeCoverageIgnore
	 */
	public function setMockTime( &$time ) {
		$this->wallClockOverride =& $time;
	}
}
