<?php
/**
 * Altis Dev Tools Command Composer Plugin.
 *
 * @package altis/dev-tools-command
 */

namespace Altis\Dev_Tools\Command;

use Composer\Composer;
use Composer\EventDispatcher\EventSubscriberInterface;
use Composer\IO\IOInterface;
use Composer\Plugin\Capable;
use Composer\Plugin\PluginInterface;

/**
 * Altis Dev Tools Composer plugin class.
 */
class Plugin implements PluginInterface, Capable, EventSubscriberInterface {

	/**
	 * Composer instance.
	 *
	 * @var Composer
	 */
	protected Composer $composer;

	/**
	 *  Activate the plugin.
	 *
	 * @param Composer $composer Composer instance.
	 * @param IOInterface $io IO instance.
	 */
	public function activate( Composer $composer, IOInterface $io ) {
		$this->composer = $composer;
	}

	/**
	 * Get the capabilities of this plugin.
	 *
	 * @return array
	 */
	public function getCapabilities() {
		return [
			'Composer\\Plugin\\Capability\\CommandProvider' => __NAMESPACE__ . '\\Command_Provider',
		];
	}

	/**
	 * Register the composer events we want to run on.
	 *
	 * @return array
	 */
	public static function getSubscribedEvents() : array {
		return [
			'post-autoload-dump' => [ 'install_files' ],
		];
	}

	/**
	 * Install / update the project's GitHub Actions CI workflow.
	 *
	 * Copies templates/project-ci.yml from altis/dev-tools to
	 * .github/workflows/ci.yml in the project root, and re-pins the
	 * reusable-workflow reference (`altis-ci.yml@<ref>`) to the installed
	 * dev-tools version. Once the user customises the file (or its hash
	 * otherwise diverges from the template) the auto re-pinning stops.
	 *
	 * Existing .travis.yml files are intentionally left untouched. Travis CI
	 * remains a supported choice for users who prefer it; from this version
	 * onwards `composer install` no longer manages the Travis config.
	 */
	public function install_files() {
		$source = $this->composer->getConfig()->get( 'vendor-dir' ) . '/altis/dev-tools';
		$dest   = dirname( $this->composer->getConfig()->get( 'vendor-dir' ) );

		$template = $source . '/templates/project-ci.yml';
		$workflow = $dest . '/.github/workflows/ci.yml';

		// Older altis/dev-tools versions don't ship the template; bail silently.
		if ( ! file_exists( $template ) ) {
			return;
		}

		// Create the workflow file if it doesn't exist yet.
		if ( ! file_exists( $workflow ) ) {
			@mkdir( dirname( $workflow ), 0755, true );
			copy( $template, $workflow );
		}

		// Normalise the ref in the dest file so we can compare against the
		// canonical template (which still contains the `__ref_replace_me__`
		// placeholder).
		$current = file_get_contents( $workflow );
		$reset   = preg_replace( '#altis-ci\.yml@\S+#', 'altis-ci.yml@__ref_replace_me__', $current );

		// Only re-pin when the file matches the template; if the user has
		// customised it, leave it alone.
		if ( md5( $reset ) !== md5( file_get_contents( $template ) ) ) {
			return;
		}

		$package = $this->composer->getRepositoryManager()->getLocalRepository()->findPackage( 'altis/dev-tools', '*' );
		if ( ! $package ) {
			return;
		}

		// Get branch name or tag.
		$ref = str_replace( 'dev-', '', $package->getPrettyVersion() );

		// Write workflow with new ref.
		$updated = str_replace( 'altis-ci.yml@__ref_replace_me__', "altis-ci.yml@{$ref}", $reset );
		file_put_contents( $workflow, $updated );
	}

	/**
	 *  Deactivate the plugin.
	 *
	 * @param Composer $composer Composer instance.
	 * @param IOInterface $io IO instance.
	 */
	public function deactivate( Composer $composer, IOInterface $io ) {
	}

	/**
	 *  Uninstall the plugin.
	 *
	 * @param Composer $composer Composer instance.
	 * @param IOInterface $io IO instance.
	 */
	public function uninstall( Composer $composer, IOInterface $io ) {
	}
}
