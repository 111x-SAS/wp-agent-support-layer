<?php
/**
 * AI crawler robots.txt tests.
 *
 * @package WPASL
 */

use WPASL\Plugin;
use WPASL\Robots\Catalog;
use WPASL\Robots\Policy;
use WPASL\Robots\RobotsTxt;
use WPASL\Settings;

/**
 * Covers WPASL\Robots\Catalog, Policy and RobotsTxt.
 */
class Test_Crawler_Robots extends WP_UnitTestCase {

	/**
	 * @var RobotsTxt
	 */
	private $robots;

	public function set_up() {
		parent::set_up();
		$this->robots = Plugin::instance()->get( 'robots' );
	}

	public function tear_down() {
		delete_option( Settings::OPTION );
		Plugin::instance()->get( 'settings' )->flush_cache();
		remove_all_filters( 'wpasl_crawler_catalog' );
		remove_all_filters( 'wpasl_physical_robots_path' );
		parent::tear_down();
	}

	private function settings( array $values ) {
		update_option( Settings::OPTION, $values );
		Plugin::instance()->get( 'settings' )->flush_cache();
	}

	public function test_catalog_contains_the_required_agents_with_groups() {
		$all = Catalog::all();
		foreach ( array( 'GPTBot', 'ChatGPT-User', 'OAI-SearchBot', 'ClaudeBot', 'Claude-User', 'Claude-SearchBot', 'anthropic-ai', 'PerplexityBot', 'Perplexity-User', 'Google-Extended', 'Applebot-Extended', 'CCBot', 'Bytespider', 'meta-externalagent', 'meta-externalfetcher', 'Amazonbot', 'cohere-ai', 'Diffbot', 'DuckAssistBot', 'YouBot', 'MistralAI-User' ) as $agent ) {
			$this->assertArrayHasKey( $agent, $all, $agent );
		}
		$this->assertSame( Catalog::GROUP_TRAINING, $all['GPTBot']['group'] );
		$this->assertSame( Catalog::GROUP_SEARCH, $all['PerplexityBot']['group'] );
		$this->assertSame( Catalog::GROUP_AGENT, $all['ChatGPT-User']['group'] );
		$this->assertSame( 'OpenAI', $all['GPTBot']['vendor'] );
		$this->assertStringStartsWith( 'https://', $all['GPTBot']['docs'] );
	}

	public function test_catalog_filter_adds_an_agent() {
		add_filter(
			'wpasl_crawler_catalog',
			static function ( $crawlers ) {
				$crawlers[] = array(
					'agent'  => 'ExampleBot',
					'vendor' => 'Example',
					'group'  => 'search',
					'docs'   => '',
				);
				$crawlers[] = array( 'agent' => 'bad agent' ); // Invalid: whitespace.
				return $crawlers;
			}
		);
		$all = Catalog::all();
		$this->assertArrayHasKey( 'ExampleBot', $all );
		$this->assertSame( 'search', $all['ExampleBot']['group'] );
		$this->assertArrayNotHasKey( 'bad agent', $all );
		$this->assertStringContainsString( "User-agent: ExampleBot\nAllow: /", $this->robots->rules_block() );
	}

	public function test_catalog_matches_user_agent_strings() {
		$this->assertSame( 'GPTBot', Catalog::match( 'Mozilla/5.0 AppleWebKit/537.36 (KHTML, like Gecko; compatible; GPTBot/1.2; +https://openai.com/gptbot)' ) );
		$this->assertSame( 'ClaudeBot', Catalog::match( 'Mozilla/5.0 (compatible; claudebot/1.0; +claudebot@anthropic.com)' ) );
		$this->assertNull( Catalog::match( 'Mozilla/5.0 (Macintosh) Safari' ) );
	}

	public function test_default_policies_follow_the_signals() {
		$policy = $this->robots->policy();
		$this->assertSame( Policy::BLOCK, $policy->for_agent( 'GPTBot' ) );
		$this->assertSame( Policy::BLOCK, $policy->for_agent( 'CCBot' ) );
		$this->assertSame( Policy::BLOCK, $policy->for_agent( 'ClaudeBot' ) );
		$this->assertSame( Policy::ALLOW, $policy->for_agent( 'OAI-SearchBot' ) );
		$this->assertSame( Policy::ALLOW, $policy->for_agent( 'PerplexityBot' ) );
		$this->assertSame( Policy::ALLOW, $policy->for_agent( 'ChatGPT-User' ) );
	}

	public function test_signals_change_the_defaults() {
		$this->settings(
			array(
				'signal_search'   => 'no',
				'signal_ai_input' => 'no',
				'signal_ai_train' => 'yes',
			)
		);
		$policy = $this->robots->policy();
		$this->assertSame( Policy::ALLOW, $policy->for_agent( 'GPTBot' ) );
		$this->assertSame( Policy::BLOCK, $policy->for_agent( 'PerplexityBot' ) );
		$this->assertSame( Policy::BLOCK, $policy->for_agent( 'ChatGPT-User' ) );
	}

	public function test_individual_override_is_respected() {
		$this->settings( array( 'crawler_overrides' => array( 'GPTBot' => 'allow' ) ) );
		$policy = $this->robots->policy();
		$this->assertSame( Policy::ALLOW, $policy->for_agent( 'GPTBot' ) );
		$this->assertSame( Policy::BLOCK, $policy->for_agent( 'ClaudeBot' ), 'Rest of the training group stays blocked.' );

		$block = $this->robots->rules_block();
		$this->assertStringContainsString( "User-agent: GPTBot\nAllow: /", $block );
		$this->assertStringContainsString( "User-agent: ClaudeBot\nDisallow: /", $block );
	}

	public function test_sanitizer_drops_default_and_keeps_valid_overrides() {
		$settings = Plugin::instance()->get( 'settings' );
		$clean    = $settings->sanitize(
			array(
				'_tab'              => 'crawlers',
				'crawler_overrides' => array(
					'GPTBot'        => 'default',
					'PerplexityBot' => 'block',
					'Evil'          => 'nuke',
				),
			)
		);
		$this->assertSame( array( 'PerplexityBot' => 'block' ), $clean['crawler_overrides'] );
	}

	public function test_robots_txt_output_keeps_core_lines_and_adds_groups() {
		$core   = "User-agent: *\nDisallow: /wp-admin/\nAllow: /wp-admin/admin-ajax.php\n";
		$output = apply_filters( 'robots_txt', $core, true );

		$this->assertStringContainsString( "Disallow: /wp-admin/\nAllow: /wp-admin/admin-ajax.php", $output );
		$this->assertStringContainsString( 'Content-Signal: search=yes, ai-input=yes, ai-train=no', $output );
		$this->assertStringContainsString( "\n\nUser-agent: GPTBot\nDisallow: /\n", $output );
		$this->assertStringContainsString( "\n\nUser-agent: PerplexityBot\nAllow: /\n", $output );
		$this->assertStringEndsWith( '# llms.txt: ' . home_url( '/llms.txt' ) . "\n", $output );
		$this->assertLessThan( strpos( $output, 'User-agent: GPTBot' ), strpos( $output, 'Content-Signal' ), 'Signals stay in the wildcard group before the crawler groups.' );
	}

	public function test_generated_output_matches_the_virtual_file() {
		$output = $this->robots->generated_output();
		$this->assertStringStartsWith( "User-agent: *\n", $output );
		$this->assertStringContainsString( 'User-agent: GPTBot', $output );
	}

	public function test_physical_file_detection() {
		$this->assertFalse( RobotsTxt::physical_file_exists() );
		$file = wp_tempnam( 'robots' );
		file_put_contents( $file, 'User-agent: *' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		add_filter(
			'wpasl_physical_robots_path',
			static function () use ( $file ) {
				return $file;
			}
		);
		$this->assertTrue( RobotsTxt::physical_file_exists() );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$tabs = Plugin::instance()->get( 'page' )->tabs();
		ob_start();
		$tabs['crawlers']->render();
		$html = ob_get_clean();
		$this->assertStringContainsString( 'A physical robots.txt file exists', $html );
		$this->assertStringContainsString( '<textarea readonly', $html );
		$this->assertStringContainsString( 'User-agent: GPTBot', $html );
		unlink( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
	}

	public function test_crawlers_tab_renders_grouped_radios() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$tabs = Plugin::instance()->get( 'page' )->tabs();
		$this->assertArrayHasKey( 'crawlers', $tabs );
		ob_start();
		$tabs['crawlers']->render();
		$html = ob_get_clean();
		$this->assertStringContainsString( 'Training crawlers', $html );
		$this->assertStringContainsString( 'AI search crawlers', $html );
		$this->assertStringContainsString( 'On-demand agents', $html );
		$this->assertMatchesRegularExpression( '/name="wpasl_settings\[crawler_overrides\]\[GPTBot\]" value="default"\s+checked/', $html );
		$this->assertStringNotContainsString( 'A physical robots.txt file exists', $html );
	}
}
