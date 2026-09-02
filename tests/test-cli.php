<?php
/**
 * WP-CLI command tests.
 *
 * @package WPASL
 */

require_once __DIR__ . '/includes/class-wp-cli-stub.php';
require_once __DIR__ . '/includes/wp-cli-format-items-stub.php';

use WPASL\CLI\Commands;
use WPASL\Generation\Runner;
use WPASL\Plugin;

/**
 * Covers WPASL\CLI\Commands through the WP_CLI stub.
 */
class Test_CLI extends WP_UnitTestCase {

	/**
	 * @var Commands
	 */
	private $commands;

	public function set_up() {
		parent::set_up();
		WP_CLI::$log    = array();
		$this->commands = new Commands( Plugin::instance()->get( 'runner' ), Plugin::instance()->get( 'scheduler' ) );
		Plugin::instance()->get( 'runner' )->clear();
	}

	public function tear_down() {
		Plugin::instance()->get( 'runner' )->clear();
		parent::tear_down();
	}

	public function test_generate_all_processes_every_item_and_reports_the_total() {
		$ids = self::factory()->post->create_many( 3 );
		$this->commands->generate( array(), array( 'all' => true ) );
		$this->assertSame( 'Success: Processed 3 item(s) and regenerated the discovery files.', end( WP_CLI::$log ) );
		$storage = Plugin::instance()->get( 'storage' );
		foreach ( $ids as $id ) {
			$this->assertTrue( $storage->exists( Runner::document_path( 'post', $id ) ) );
		}
		$this->assertTrue( $storage->exists( 'llms.txt' ) );
	}

	public function test_generate_post_type_and_batch() {
		self::factory()->post->create();
		$page = self::factory()->post->create( array( 'post_type' => 'page' ) );
		$this->commands->generate( array(), array( 'post-type' => 'page' ) );
		$storage = Plugin::instance()->get( 'storage' );
		$this->assertTrue( $storage->exists( Runner::document_path( 'page', $page ) ) );
		$this->assertCount( 1, $storage->list_files( 'md' ) );

		$this->commands->generate( array(), array( 'batch' => true ) );
		$this->assertStringStartsWith( 'Success: Processed 2 item(s); 0 remaining', end( WP_CLI::$log ), 'A new cycle covers the pending post and refreshes the page.' );
		$this->assertCount( 2, $storage->list_files( 'md' ) );
	}

	public function test_status_and_clear() {
		self::factory()->post->create_many( 2 );
		$this->commands->status( array(), array( 'format' => 'table' ) );
		$this->assertContains( 'eligible=2', WP_CLI::$log );
		$this->assertContains( 'pending=2', WP_CLI::$log );

		$this->commands->generate( array(), array() );
		WP_CLI::$log = array();
		$this->commands->status( array(), array() );
		$this->assertContains( 'generated=2', WP_CLI::$log );
		$this->assertContains( 'pending=0', WP_CLI::$log );

		$this->commands->clear();
		$this->assertSame( 'Success: Storage cleared. All items are pending again.', end( WP_CLI::$log ) );
		$this->assertSame( array(), Plugin::instance()->get( 'storage' )->list_files( '' ) );
		WP_CLI::$log = array();
		$this->commands->status( array(), array() );
		$this->assertContains( 'pending=2', WP_CLI::$log );
	}
}
