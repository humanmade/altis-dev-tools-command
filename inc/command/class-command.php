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
use Symfony\Component\Yaml\Yaml;

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
			new InputArgument( 'subcommand', InputArgument::REQUIRED, 'phpunit | codecept' ),
			new InputOption( 'chassis', null, null, 'Run commands in the Local Chassis environment' ),
			new InputOption( 'module', 'm', InputArgument::OPTIONAL, 'Run commands for a specific module', 'project' ),
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

To run Codeception integration tests:
    codecept [--chassis] -m <module> [--] [options]
                                use `--` to separate arguments you want to
                                pass to Codeception. Use the --chassis option
                                if you are running Local Chassis. Use -m module
                                to test an Altis module.
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
			case 'codecept':
				return $this->codecept( $input, $output );

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
	 * Runs Codecept with zero config by default.
	 *
	 * @param InputInterface $input
	 * @param OutputInterface $output
	 * @return int
	 */
	protected function codecept( InputInterface $input, OutputInterface $output ) {
		$options = $input->getArgument( 'options' );
		$module = $input->getOption( 'module' );
		$tests_folder = $module !== 'project' ? "altis/$module/tests" : 'tests';
		$use_chassis = $input->getOption( 'chassis' );

		// Write the default config.
		$config = [
			'paths' => [
				'tests' => $tests_folder,
				'output' => 'altis/dev-tools/tests/_output',
				'data' => 'altis/dev-tools/tests/_data',
				'support' => 'altis/dev-tools/tests/_support',
			],
			'actor_suffix' => 'Tester',
			'extensions' => [
				'enabled' => [
					'Codeception\Extension\RunFailed',
				],
				'commands' => [
					'Codeception\Command\GenerateWPUnit',
					'Codeception\Command\GenerateWPRestApi',
					'Codeception\Command\GenerateWPRestController',
					'Codeception\Command\GenerateWPRestPostTypeController',
					'Codeception\Command\GenerateWPAjax',
					'Codeception\Command\GenerateWPCanonical',
					'Codeception\Command\GenerateWPXMLRPC',
				],
			],
			'modules' => [
				'config' => [
					'WPDb' => [
						'dsn' => '%TEST_SITE_DB_DSN%',
						'user' => '%TEST_SITE_DB_USER%',
						'password' => '%TEST_SITE_DB_PASSWORD%',
						'dump' => '%TEST_SITE_DB_DUMP%',
						'populate' => true,
						'cleanup' => true,
						'waitlock' => 10,
						'url' => '%TEST_SITE_WP_URL%',
						'urlReplacement' => true,
						'tablePrefix' => '%TEST_SITE_TABLE_PREFIX%',
					],
					'WPBrowser' => [
						'url' => '%TEST_SITE_WP_URL%',
						'adminUsername' => '%TEST_SITE_ADMIN_USERNAME%',
						'adminPassword' => '%TEST_SITE_ADMIN_PASSWORD%',
						'adminPath' => '%TEST_SITE_WP_ADMIN_PATH%',
						'headers' => [
							'X_TEST_REQUEST' => 1,
							'X_WPBROWSER_REQUEST' => 1,
						],
					],
					'WPFilesystem' => [
						'wpRootFolder' => '%WP_ROOT_FOLDER%',
						'plugins' => '.%WP_CONTENT_FOLDER%/plugins',
						'mu-plugins' => '%WP_CONTENT_FOLDER%/mu-plugins',
						'themes' => '%WP_CONTENT_FOLDER%/themes',
						'uploads' => '%WP_CONTENT_FOLDER%/uploads',
					],
					'WPLoader' => [
						'wpRootFolder' => '%WP_ROOT_FOLDER%',
						'dbName' => '%TEST_DB_NAME%',
						'dbHost' => '%TEST_DB_HOST%',
						'dbUser' => '%TEST_DB_USER%',
						'dbPassword' => '%TEST_DB_PASSWORD%',
						'tablePrefix' => '%TEST_TABLE_PREFIX%',
						'domain' => '%TEST_SITE_WP_DOMAIN%',
						'adminEmail' => '%TEST_SITE_ADMIN_EMAIL%',
						'title' => 'Test',
						'theme' => 'default',
						'plugins' => [],
						'activatePlugins' => [],
						'multisite' => true,
						'configFile' => 'altis/dev-tools/inc/codeception/config.php',
						'contentFolder' => 'content',
						'bootstrapActions' => [
							'bootstrapCCWP',
						],
					],
				],
			],
			'params' => [
				'codeception.env',
			],
		];

		// Merge config from composer.json.
		$overrides = $this->get_config()['codeception'] ?? [];
		$config = $this->merge_config( $config, $overrides );

		// Convert to YAML.
		$yaml = Yaml::dump( $config, 2, 4, Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK );

		// Write the file.
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_file_put_contents
		file_put_contents(
			$this->get_root_dir() . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'codeception.yml',
			$yaml
		);

		$db_host = $use_chassis ? 'localhost' : 'db';
		$test_env = <<<EOL
TEST_SITE_DB_DSN=mysql:host=$db_host;dbname=test
TEST_SITE_DB_HOST=$db_host
TEST_SITE_DB_NAME=test
TEST_SITE_DB_USER=wordpress
TEST_SITE_DB_PASSWORD=wordpress
TEST_SITE_DB_DUMP=altis/dev-tools/tests/_data/dump.sql
TEST_SITE_TABLE_PREFIX=wp_
TEST_SITE_ADMIN_USERNAME=admin
TEST_SITE_ADMIN_PASSWORD=password
TEST_SITE_WP_ADMIN_PATH=/wp-admin
TEST_SITE_WP_URL=dev.altis.dev
TEST_SITE_WP_DOMAIN=dev.altis.dev
TEST_SITE_ADMIN_EMAIL=admin@example.org
WP_ROOT_FOLDER=wordpress
WP_CONTENT_FOLDER=../content
TEST_DB_NAME=test2
TEST_DB_HOST=$db_host
TEST_DB_USER=wordpress
TEST_DB_PASSWORD=wordpress
TEST_TABLE_PREFIX=wp_
EOL;

		// Write the env file.
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_file_put_contents
		file_put_contents(
			$this->get_root_dir() . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'codeception.env',
			$test_env
		);

		// // Check for passed config option.
		if ( ! preg_match( '/(-c|--configuration)\s+/', implode( ' ', $options ) ) ) {
			$options = array_merge(
				[ '-c', 'vendor/codeception.yml' ],
				$options
			);
		}

		$this->create_test_db( $input, $output );
		$return = $this->run_command( $input, $output, 'vendor/bin/codecept', $options );
		$this->delete_test_db( $input, $output );

		return $return;
	}

	/**
	 * Run the passed command on either the local-server or local-chassis environment.
	 *
	 * @param InputInterface $input
	 * @param OutputInterface $output
	 * @param string $command The command to run.
	 * @param array $options Any required options to pass to the command.
	 * @return int
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
	 * Create test databases.
	 *
	 * @param InputInterface $input
	 * @param OutputInterface $output
	 *
	 * @return int
	 */
	protected function create_test_db( InputInterface $input, OutputInterface $output ) {
		$use_chassis = $input->getOption( 'chassis' );

		if ( $use_chassis ) {
			$cli = $this->getApplication()->find( 'chassis' );

			$return_val = $cli->run( new ArrayInput( [
				'subcommand' => 'exec',
				'options' => [
					'mysql',
					'-uroot',
					'-ppassword',
					'-e',
					'"CREATE DATABASE IF NOT EXISTS test; CREATE DATABASE IF NOT EXISTS test2; GRANT ALL PRIVILEGES ON test.* TO wordpress@localhost IDENTIFIED BY \"wordpress\"; GRANT ALL PRIVILEGES ON test2.* TO wordpress@localhost IDENTIFIED BY \"wordpress\";"',
				],
			] ), $output );
		} else {
			$cli = $this->getApplication()->find( 'local-server' );

			$return_val = $cli->run( new ArrayInput( [
				'subcommand' => 'db',
				'options' => [
					'exec',
					'CREATE DATABASE IF NOT EXISTS test; CREATE DATABASE IF NOT EXISTS test2; GRANT ALL PRIVILEGES ON test.* TO wordpress IDENTIFIED BY \"wordpress\"; GRANT ALL PRIVILEGES ON test2.* TO wordpress IDENTIFIED BY \"wordpress\";',
				],
			] ), $output );
		}

		return $return_val;
	}

	/**
	 * Create test databases.
	 *
	 * @param InputInterface $input
	 * @param OutputInterface $output
	 *
	 * @return int
	 */
	protected function delete_test_db( InputInterface $input, OutputInterface $output ) {
		$use_chassis = $input->getOption( 'chassis' );

		if ( $use_chassis ) {
			$cli = $this->getApplication()->find( 'chassis' );

			$return_val = $cli->run( new ArrayInput( [
				'subcommand' => 'exec',
				'options' => [
					'mysql',
					'-uroot',
					'-ppassword',
					'-e',
					'"DROP DATABASE test; DROP DATABASE test2; REVOKE ALL PRIVILEGES on test.* FROM wordpress; REVOKE ALL PRIVILEGES on test2.* FROM wordpress;"',
				],
			] ), $output );
		} else {
			$cli = $this->getApplication()->find( 'local-server' );

			$return_val = $cli->run( new ArrayInput( [
				'subcommand' => 'db',
				'options' => [
					'exec',
					'DROP DATABASE test; DROP DATABASE test2; REVOKE ALL PRIVILEGES on test.* FROM wordpress; REVOKE ALL PRIVILEGES on test2.* FROM wordpress;',
				],
			] ), $output );
		}

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

	/**
	 * Merges two configuration arrays together, overriding the first or adding
	 * to it with items from the second.
	 *
	 * @param array $config The default config array.
	 * @param array $overrides The config to merge in.
	 * @return array
	 */
	protected function merge_config( array $config, array $overrides ) : array {
		$merged = $config;
		foreach ( $overrides as $key => $value ) {
			if ( is_string( $key ) ) {
				if ( is_array( $value ) ) {
					// Recursively merge arrays.
					$merged[ $key ] = $this->merge_config( $merged[ $key ] ?? [], $value );
				} else {
					// Overwrite scalar values directly.
					$merged[ $key ] = $value;
				}
			} else {
				// Merge numerically keyed arrays directly and remove empty/duplicate items.
				$merged = array_merge( $merged, (array) $overrides );
				$merged = array_filter( $merged );
				$merged = array_unique( $merged );
				break;
			}
		}
		return $merged;
	}

}
