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
	 * Install Travis CI configs.
	 */
	public function install_files() {
		$source = $this->composer->getConfig()->get( 'vendor-dir' ) . '/altis/dev-tools';
		$dest   = dirname( $this->composer->getConfig()->get( 'vendor-dir' ) );

		// Copy default tests file.
		if ( ! file_exists( $dest . '/.config/travis.yml' ) ) {
			@mkdir( $dest . '/.config', 0755, true );
			copy( $source . '/travis/tests.yml', $dest . '/.config/travis.yml' );
		}

		// Create .travis.yml if one doesn't exist yet.
		if ( ! file_exists( $dest . '/.travis.yml' ) ) {
			copy( $source . '/travis/project.yml', $dest . '/.travis.yml' );
		}

		// Reset ref.
		$root_config = file_get_contents( $dest . '/.travis.yml' );
		$root_config = preg_replace( '#altis\.yml@.*#', 'altis.yml@__ref_replace_me__', $root_config );

		// Check files match.
		$source_hash = md5( file_get_contents( $source . '/travis/project.yml' ) );
		$dest_hash = md5( $root_config );

		// If files match then update the ref.
		if ( $source_hash === $dest_hash ) {
			// Get dev tools package.
			$package = $this->composer->getRepositoryManager()->getLocalRepository()->findPackage( 'altis/dev-tools', '*' );

			// Get branch name or tag.
			$ref = str_replace( 'dev-', '', $package->getPrettyVersion() );

			// Write travis config with new ref.
			$root_config = str_replace( 'altis.yml@__ref_replace_me__', "altis.yml@{$ref}", $root_config );
			file_put_contents( $dest . '/.travis.yml', $root_config );
			return;
		}

		// Files are mismatched, show a warning.
		echo(
			"\n" .
			'The file .travis.yml does not match that required by Altis.' . "\n" .
			'See the file at: ' . esc_textarea( $source ) . '/travis/project.yml' . "\n" .
			'For more information follow this guide:' . "\n" .
			'https://www.altis-dxp.com/resources/docs/dev-tools/continuous-integration/ ' . "\n"
		);
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
