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
		Plugin::instance()->get( 'runner' )->set_item_generator( Plugin::instance()->get( 'builder' ) );
		Plugin::instance()->get( 'runner' )->clear();
		delete_option( WPASL\Settings::OPTION );
		Plugin::instance()->get( 'settings' )->flush_cache();
		parent::tear_down();
	}

	public function test_cli_generate_errors_for_disabled_post_type() {
		self::factory()->post->create( array( 'post_type' => 'page' ) );
		update_option( WPASL\Settings::OPTION, array( 'post_types' => array( 'post' ) ) );
		Plugin::instance()->get( 'settings' )->flush_cache();

		try {
			$this->commands->generate( array(), array( 'post-type' => 'page' ) );
			$this->fail( 'Expected WP_CLI::error().' );
		} catch ( RuntimeException $e ) {
			$this->assertStringContainsString( 'Post type "page" is not enabled', $e->getMessage() );
		}
		$this->assertStringStartsWith( 'Error:', end( WP_CLI::$log ) );
		$this->assertSame( array(), Plugin::instance()->get( 'storage' )->list_files( 'md' ), 'Nothing was generated.' );
		$this->assertStringNotContainsString( 'Success', implode( ' ', WP_CLI::$log ) );
	}

	public function test_cli_generate_errors_when_the_converter_is_missing() {
		self::factory()->post->create();
		Plugin::instance()->get( 'runner' )->set_item_generator( null );
		try {
			$this->commands->generate( array(), array( 'all' => true ) );
			$this->fail( 'Expected WP_CLI::error().' );
		} catch ( RuntimeException $e ) {
			$this->assertStringContainsString( 'HTML-to-Markdown library is missing', $e->getMessage() );
		}
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

	public function test_generate_post_type_with_batch_only_processes_that_type() {
		self::factory()->post->create();
		$page = self::factory()->post->create( array( 'post_type' => 'page' ) );
		$this->commands->generate( array(), array( 'post-type' => 'page', 'batch' => true ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		$storage = Plugin::instance()->get( 'storage' );
		$this->assertTrue( $storage->exists( Runner::document_path( 'page', $page ) ) );
		$this->assertCount( 1, $storage->list_files( 'md' ), 'The post was not processed.' );
		$this->assertStringStartsWith( 'Success: Processed 1 item(s); 0 remaining', end( WP_CLI::$log ) );
	}

	public function test_generate_post_type_without_eligible_items_processes_nothing() {
		self::factory()->post->create_many( 3 );
		$storage = Plugin::instance()->get( 'storage' );

		$this->commands->generate( array(), array( 'post-type' => 'page' ) );
		$this->assertSame( array(), $storage->list_files( 'md' ) );
		$this->assertStringStartsWith( 'Success: Processed 0 item(s)', end( WP_CLI::$log ) );

		$this->commands->generate( array(), array( 'post-type' => 'page', 'batch' => true ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		$this->assertSame( array(), $storage->list_files( 'md' ) );
		$this->assertStringStartsWith( 'Success: Processed 0 item(s); 0 remaining', end( WP_CLI::$log ) );
	}

	public function test_generate_all_with_post_type_keeps_other_types_generated() {
		$post = self::factory()->post->create();
		$page = self::factory()->post->create( array( 'post_type' => 'page' ) );
		$this->commands->generate( array(), array( 'all' => true ) );
		$before = get_option( WPASL\Generation\State::OPTION );
		$this->assertArrayHasKey( $post, $before['generated'] );
		$this->assertArrayHasKey( $page, $before['generated'] );

		$this->commands->generate( array(), array( 'all' => true, 'post-type' => 'page' ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		$this->assertStringStartsWith( 'Success: Processed 1 item(s)', end( WP_CLI::$log ) );
		$after = get_option( WPASL\Generation\State::OPTION );
		$this->assertArrayHasKey( $post, $after['generated'], 'Posts keep their generation mark.' );
		$this->assertSame( $before['generated'][ $post ], $after['generated'][ $post ] );
		$this->assertGreaterThanOrEqual( $before['generated'][ $page ], $after['generated'][ $page ] );
		$this->assertSame( 0, Plugin::instance()->get( 'runner' )->status()['pending'] );
	}

	public function test_status_command_lists_failed_items() {
		$state          = new WPASL\Generation\State();
		$data           = $state->load();
		$data['failed'] = array( 11 => 3, 12 => 1 );
		$state->save( $data, false );

		$this->commands->status( array(), array() );
		$this->assertContains( 'failed=2', WP_CLI::$log );
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
