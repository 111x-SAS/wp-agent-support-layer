<?php
/**
 * Admin tab contract.
 *
 * @package WPASL
 */

namespace WPASL\Admin;

/**
 * A tab of the settings page.
 */
interface Tab {

	/**
	 * Tab slug, matching a key of Settings::TAB_KEYS (or a non-settings tab such as "diagnostics").
	 *
	 * @return string
	 */
	public function slug();

	/**
	 * Translated tab label.
	 *
	 * @return string
	 */
	public function label();

	/**
	 * Whether the tab renders a settings form (true) or its own content (false).
	 *
	 * @return bool
	 */
	public function has_form();

	/**
	 * Prints the tab body. For form tabs, only the fields; the page prints the form wrapper.
	 *
	 * @return void
	 */
	public function render();
}
