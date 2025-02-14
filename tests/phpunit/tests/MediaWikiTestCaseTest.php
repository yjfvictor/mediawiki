<?php

use MediaWiki\Logger\LoggerFactory;
use MediaWiki\MediaWikiServices;
use Psr\Log\LoggerInterface;
use Wikimedia\Rdbms\LoadBalancer;

/**
 * @covers MediaWikiTestCase
 * @group MediaWikiTestCaseTest
 * @group Database
 *
 * @author Addshore
 */
class MediaWikiTestCaseTest extends MediaWikiTestCase {

	private static $startGlobals = [
		'MediaWikiTestCaseTestGLOBAL-ExistingString' => 'foo',
		'MediaWikiTestCaseTestGLOBAL-ExistingStringEmpty' => '',
		'MediaWikiTestCaseTestGLOBAL-ExistingArray' => [ 1, 'foo' => 'bar' ],
		'MediaWikiTestCaseTestGLOBAL-ExistingArrayEmpty' => [],
	];

	public static function setUpBeforeClass() {
		parent::setUpBeforeClass();
		foreach ( self::$startGlobals as $key => $value ) {
			$GLOBALS[$key] = $value;
		}
	}

	public static function tearDownAfterClass() {
		parent::tearDownAfterClass();
		foreach ( self::$startGlobals as $key => $value ) {
			unset( $GLOBALS[$key] );
		}
	}

	public function provideExistingKeysAndNewValues() {
		$providedArray = [];
		foreach ( array_keys( self::$startGlobals ) as $key ) {
			$providedArray[] = [ $key, 'newValue' ];
			$providedArray[] = [ $key, [ 'newValue' ] ];
		}
		return $providedArray;
	}

	/**
	 * @dataProvider provideExistingKeysAndNewValues
	 *
	 * @covers MediaWikiTestCase::setMwGlobals
	 * @covers MediaWikiTestCase::tearDown
	 */
	public function testSetGlobalsAreRestoredOnTearDown( $globalKey, $newValue ) {
		$this->setMwGlobals( $globalKey, $newValue );
		$this->assertEquals(
			$newValue,
			$GLOBALS[$globalKey],
			'Global failed to correctly set'
		);

		$this->tearDown();

		$this->assertEquals(
			self::$startGlobals[$globalKey],
			$GLOBALS[$globalKey],
			'Global failed to be restored on tearDown'
		);
	}

	/**
	 * @covers MediaWikiTestCase::setMwGlobals
	 * @covers MediaWikiTestCase::tearDown
	 */
	public function testSetNonExistentGlobalsAreUnsetOnTearDown() {
		$globalKey = 'abcdefg1234567';
		$this->setMwGlobals( $globalKey, true );
		$this->assertTrue(
			$GLOBALS[$globalKey],
			'Global failed to correctly set'
		);

		$this->tearDown();

		$this->assertFalse(
			isset( $GLOBALS[$globalKey] ),
			'Global failed to be correctly unset'
		);
	}

	public function testOverrideMwServices() {
		$initialServices = MediaWikiServices::getInstance();

		$this->overrideMwServices();
		$this->assertNotSame( $initialServices, MediaWikiServices::getInstance() );
	}

	public function testSetService() {
		$initialServices = MediaWikiServices::getInstance();
		$initialService = $initialServices->getDBLoadBalancer();
		$mockService = $this->getMockBuilder( LoadBalancer::class )
			->disableOriginalConstructor()->getMock();

		$this->setService( 'DBLoadBalancer', $mockService );
		$this->assertNotSame(
			$initialService,
			MediaWikiServices::getInstance()->getDBLoadBalancer()
		);
		$this->assertSame( $mockService, MediaWikiServices::getInstance()->getDBLoadBalancer() );
	}

	/**
	 * @covers MediaWikiTestCase::setLogger
	 * @covers MediaWikiTestCase::restoreLoggers
	 */
	public function testLoggersAreRestoredOnTearDown_replacingExistingLogger() {
		$logger1 = LoggerFactory::getInstance( 'foo' );
		$this->setLogger( 'foo', $this->createMock( LoggerInterface::class ) );
		$logger2 = LoggerFactory::getInstance( 'foo' );
		$this->tearDown();
		$logger3 = LoggerFactory::getInstance( 'foo' );

		$this->assertSame( $logger1, $logger3 );
		$this->assertNotSame( $logger1, $logger2 );
	}

	/**
	 * @covers MediaWikiTestCase::setLogger
	 * @covers MediaWikiTestCase::restoreLoggers
	 */
	public function testLoggersAreRestoredOnTearDown_replacingNonExistingLogger() {
		$this->setLogger( 'foo', $this->createMock( LoggerInterface::class ) );
		$logger1 = LoggerFactory::getInstance( 'foo' );
		$this->tearDown();
		$logger2 = LoggerFactory::getInstance( 'foo' );

		$this->assertNotSame( $logger1, $logger2 );
		$this->assertInstanceOf( \Psr\Log\LoggerInterface::class, $logger2 );
	}

	/**
	 * @covers MediaWikiTestCase::setLogger
	 * @covers MediaWikiTestCase::restoreLoggers
	 */
	public function testLoggersAreRestoredOnTearDown_replacingSameLoggerTwice() {
		$logger1 = LoggerFactory::getInstance( 'baz' );
		$this->setLogger( 'foo', $this->createMock( LoggerInterface::class ) );
		$this->setLogger( 'foo', $this->createMock( LoggerInterface::class ) );
		$this->tearDown();
		$logger2 = LoggerFactory::getInstance( 'baz' );

		$this->assertSame( $logger1, $logger2 );
	}

	/**
	 * @covers MediaWikiTestCase::setupDatabaseWithTestPrefix
	 * @covers MediaWikiTestCase::copyTestData
	 */
	public function testCopyTestData() {
		$this->markTestSkippedIfDbType( 'sqlite' );

		$this->tablesUsed[] = 'objectcache';
		$this->db->insert(
			'objectcache',
			[ 'keyname' => __METHOD__, 'value' => 'TEST', 'exptime' => $this->db->timestamp( 11 ) ],
			__METHOD__
		);

		$lbFactory = MediaWikiServices::getInstance()->getDBLoadBalancerFactory();
		$lb = $lbFactory->newMainLB();
		$db = $lb->getConnection( DB_REPLICA );

		// sanity
		$this->assertNotSame( $this->db, $db );

		// Make sure the DB connection has the fake table clones and the fake table prefix
		MediaWikiTestCase::setupDatabaseWithTestPrefix( $db, $this->dbPrefix(), false );

		$this->assertSame( $this->db->tablePrefix(), $db->tablePrefix(), 'tablePrefix' );

		// Make sure the DB connection has all the test data
		$this->copyTestData( $this->db, $db );

		$value = $db->selectField( 'objectcache', 'value', [ 'keyname' => __METHOD__ ], __METHOD__ );
		$this->assertSame( 'TEST', $value, 'Copied Data' );
	}

	public function testResetServices() {
		$services = MediaWikiServices::getInstance();

		// override a service instance
		$myReadOnlyMode = $this->getMockBuilder( ReadOnlyMode::class )
			->disableOriginalConstructor()
			->getMock();
		$this->setService( 'ReadOnlyMode', $myReadOnlyMode );

		// sanity check
		$this->assertSame( $myReadOnlyMode, $services->getService( 'ReadOnlyMode' ) );

		// define a custom service
		$services->defineService(
			'_TEST_ResetService_Dummy',
			function ( MediaWikiServices $services ) {
				$conf = $services->getMainConfig();
				return (object)[ 'lang' => $conf->get( 'LanguageCode' ) ];
			}
		);

		// sanity check
		$lang = $services->getMainConfig()->get( 'LanguageCode' );
		$dummy = $services->getService( '_TEST_ResetService_Dummy' );
		$this->assertSame( $lang, $dummy->lang );

		// the actual test: change config, reset services.
		$this->setMwGlobals( 'wgLanguageCode', 'qqx' );
		$this->resetServices();

		// the overridden service instance should still be there
		$this->assertSame( $myReadOnlyMode, $services->getService( 'ReadOnlyMode' ) );

		// our custom service should have been re-created with the new language code
		$dummy2 = $services->getService( '_TEST_ResetService_Dummy' );
		$this->assertNotSame( $dummy2, $dummy );
		$this->assertSame( 'qqx', $dummy2->lang );
	}

}
