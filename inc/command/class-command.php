<?php

namespace Altis\Dev_Tools\Command;

use Composer\Command\BaseCommand;
use DOMDocument;
use Symfony\Component\Console\Exception\CommandNotFoundException;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Chassis command for Composer.
 */
class Command extends BaseCommand {
	/**
	 * Configure the command.
	 */
	protected function configure() {
		$this->setName( 'dev-tools' );
		$this->setDescription( 'Developer tools' );
		$this->setDefinition( [
			new InputArgument( 'subcommand', InputArgument::REQUIRED, 'phpunit' ),
			new InputOption( 'chassis', null, null, 'Run commands in the Local Chassis environment' ),
			new InputArgument( 'options', InputArgument::IS_ARRAY ),
		] );
		$this->setHelp(
			<<<EOT
Run a dev tools feature.

To run PHPUnit integration tests:
    phpunit [--chassis] [--] [options]
                                use `--` to separate arguments you want to
                                pass to phpunit. Use the --chassis option
                                if you are running Local Chassis.
EOT
		);
	}

	/**
	 * Wrapper command to dispatch subcommands
	 *
	 * @param InputInterface $input
	 * @param OutputInterface $output
	 * @return int Status code to return
	 */
	protected function execute( InputInterface $input, OutputInterface $output ) {
		$subcommand = $input->getArgument( 'subcommand' );
		switch ( $subcommand ) {
			case 'phpunit':
				return $this->phpunit( $input, $output );

			default:
				throw new CommandNotFoundException( sprintf( 'Subcommand "%s" is not defined.', $subcommand ) );
		}
	}

	/**
	 * Runs PHPUnit with zero config by default.
	 *
	 * @param InputInterface $input
	 * @param OutputInterface $output
	 * @return int
	 */
	protected function phpunit( InputInterface $input, OutputInterface $output ) {
		$options = [];

		// Get dev-tools config.
		$config = $this->get_config()['phpunit'] ?? [];

		// Set default directories and files.
		$test_paths = [ 'tests' ];

		// Get directories and files from config.
		if ( isset( $config['directories'] ) ) {
			$test_paths = array_merge( (array) $config['directories'], $test_paths );
		}

		$test_paths = array_map( function ( $path ) {
			return trim( $path, DIRECTORY_SEPARATOR );
		}, $test_paths );
		$test_paths = array_filter( $test_paths, [ $this, 'is_valid_test_path' ] );
		$test_paths = array_unique( $test_paths );

		// Check last option for a specific file path and override config if so.
		$options = $input->getArgument( 'options' );
		$maybe_test_path = $options[ count( $options ) - 1 ] ?? false;
		if ( $this->is_valid_test_path( $maybe_test_path ) ) {
			array_pop( $options );
			$test_paths = [ $maybe_test_path ];
		}

		// Get excludes from config.
		$excludes = (array) ( $config['excludes'] ?? [] );
		$excludes = array_map( function ( $path ) {
			return trim( $path, DIRECTORY_SEPARATOR );
		}, $excludes );
		$excludes = array_filter( $excludes, [ $this, 'is_valid_test_path' ] );
		$excludes = array_unique( $excludes );

		// Write XML config.
		$doc = new DOMDocument( '1.0', 'utf-8' );

		// Create PHPUnit Element.
		$phpunit = $doc->createElement( 'phpunit' );
		$phpunit->setAttribute( 'bootstrap', 'altis/dev-tools/inc/phpunit/bootstrap.php' );
		$phpunit->setAttribute( 'backupGlobals', 'false' );
		$phpunit->setAttribute( 'colors', 'true' );
		$phpunit->setAttribute( 'convertErrorsToExceptions', 'true' );
		$phpunit->setAttribute( 'convertNoticesToExceptions', 'true' );
		$phpunit->setAttribute( 'convertWarningsToExceptions', 'true' );

		// Allow overrides and additional attributes.
		if ( isset( $config['attributes'] ) ) {
			foreach ( $config['attributes'] as $name => $value ) {
				$phpunit->setAttribute( $name, $value );
			}
		}

		// Create testsuites.
		$testsuites = $doc->createElement( 'testsuites' );

		// Create testsuite.
		$testsuite = $doc->createElement( 'testsuite' );
		$testsuite->setAttribute( 'name', 'project' );

		foreach ( $test_paths as $test_path ) {
			if ( is_file( $this->get_root_dir() . DIRECTORY_SEPARATOR . $test_path ) ) {
				$tag = $doc->createElement( 'file', "../{$test_path}/" );
				$testsuite->appendChild( $tag );
			} else {
				$tag = $doc->createElement( 'directory', "../{$test_path}/" );
				// class-test-*.php
				$variant = $tag->cloneNode( true );
				$variant->setAttribute( 'prefix', 'class-test-' );
				$variant->setAttribute( 'suffix', '.php' );
				$testsuite->appendChild( $variant );
				// test-*.php
				$variant = $tag->cloneNode( true );
				$variant->setAttribute( 'prefix', 'test-' );
				$variant->setAttribute( 'suffix', '.php' );
				$testsuite->appendChild( $variant );
				// *-test.php
				$variant = $tag->cloneNode( true );
				$variant->setAttribute( 'suffix', '-test.php' );
				$testsuite->appendChild( $variant );
			}
		}

		foreach ( $excludes as $exclude ) {
			$tag = $doc->createElement( 'exclude', "../{$exclude}/" );
			$testsuite->appendChild( $tag );
		}

		// Build the doc.
		$doc->appendChild( $phpunit );
		$phpunit->appendChild( $testsuites );
		$testsuites->appendChild( $testsuite );

		// Add extensions if set.
		if ( isset( $config['extensions'] ) ) {
			$extensions = $doc->createElement( 'extensions' );
			foreach ( (array) $config['extensions'] as $class ) {
				$extension = $doc->createElement( 'extension' );
				$extension->setAttribute( 'class', $class );
				$extensions->appendChild( $extension );
			}
			$phpunit->appendChild( $extensions );
		}

		// Write the file.
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_file_put_contents
		file_put_contents(
			$this->get_root_dir() . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'phpunit.xml',
			$doc->saveXML()
		);

		// Check for passed config option.
		if ( ! preg_match( '/(-c|--configuration)\s+/', implode( ' ', $options ) ) ) {
			$options = array_merge(
				[ '-c', 'vendor/phpunit.xml' ],
				$options
			);
		}

		return $this->run_command( $input, $output, 'vendor/bin/phpunit', $options );
	}

	/**
	 * Run the passed command on either the local-server or local-chassis environment.
	 *
	 * @param InputInterface $input
	 * @param OutputInterface $output
	 * @param string $command The command to run.
	 * @param array $options Any required options to pass to the command.
	 * @return void
	 */
	protected function run_command( InputInterface $input, OutputInterface $output, string $command, array $options = [] ) {
		$use_chassis = $input->getOption( 'chassis' );
		$cli = $this->getApplication()->find( $use_chassis ? 'chassis' : 'local-server' );

		// Add the command, default options and input options together.
		$options = array_merge(
			[ $command ],
			$options
		);

		$return_val = $cli->run( new ArrayInput( [
			'subcommand' => 'exec',
			'options' => $options,
		] ), $output );

		return $return_val;
	}

	/**
	 * Get the root directory path for the project.
	 *
	 * @return string
	 */
	protected function get_root_dir() : string {
		return dirname( $this->getComposer()->getConfig()->getConfigSource()->getName() );
	}

	/**
	 * Get a module config from composer.json.
	 *
	 * @param string $module The module to get the config for.
	 * @return array
	 */
	protected function get_config( $module = 'dev-tools' ) : array {
		// @codingStandardsIgnoreLine
		$json = file_get_contents( $this->get_root_dir() . DIRECTORY_SEPARATOR . 'composer.json' );
		$composer_json = json_decode( $json, true );

		return (array) ( $composer_json['extra']['altis']['modules'][ $module ] ?? [] );
	}

	/**
	 * Check if a given path is valid for PHPUnit.
	 *
	 * @param string $path The filepath to check.
	 * @return boolean
	 */
	protected function is_valid_test_path( string $path ) : bool {
		if ( empty( $path ) ) {
			return false;
		}
		$full_path = $this->get_root_dir() . DIRECTORY_SEPARATOR . $path;
		if ( strpos( $path, '*' ) !== false ) {
			return ! empty( glob( $full_path ) );
		}
		if ( is_file( $full_path ) ) {
			return in_array( pathinfo( $full_path, PATHINFO_EXTENSION ), [ 'php', 'inc' ], true );
		}
		return is_dir( $full_path );
	}

}
