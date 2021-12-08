<?php

namespace Altis\Dev_Tools\Command;

use Composer\Command\BaseCommand;
use DOMDocument;
use Exception;
use Symfony\Component\Console\Exception\CommandNotFoundException;
use Symfony\Component\Console\Exception\InvalidArgumentException;
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
			new InputOption( 'path', 'p', InputArgument::OPTIONAL, 'Use a custom path for tests folder.', 'tests' ),
			new InputOption( 'output', 'o', InputArgument::OPTIONAL, 'Use a custom path for output folder.', '' ),
			new InputOption( 'browser', 'b', InputArgument::OPTIONAL, 'Run a headless Chrome browser for acceptance tests, use "chrome", "firefox", or "edge"', 'chrome' ),
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
    codecept [--chassis] -p <path> -b <browser> -o <output-folder> [--] [options]
                                use `--` to separate arguments you want to
                                pass to Codeception. Use the --chassis option
                                if you are running Local Chassis. Use -p path
                                to specify custom tests folder. Use --browser/-b
                                to run a headless browser container for acceptance tests,
                                choose 'chrome', or 'firefox' as needed.
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
		$tests_folder = rtrim( $input->getOption( 'path' ), '\\/' );
		$output_folder = $input->getOption( 'output' );
		$run_headless_browser = $input->getOption( 'browser' );
		$use_chassis = $input->getOption( 'chassis' );
		$project_subdomain = $this->get_project_subdomain();
		$test_suite = $this->get_test_suite_argument( $input );

		if ( $input->hasParameterOption( 'bootstrap', true ) ) {
			return $this->bootstrap_codecept( $tests_folder, $input, $output );
		}

		// Working directory for codeception is `vendor`, so need to go up once to resolve relative paths correctly.
		$tests_folder = '../' . $tests_folder;

		$folders = [
			'_data' => 'altis/dev-tools/tests/_data',
			'_support' => 'altis/dev-tools/tests/_support',
			'_envs' => 'altis/dev-tools/tests/_env',
			'_output' => "{$tests_folder}/_output",
		];

		foreach ( $folders as $folder => $_ ) {
			if ( file_exists( $tests_folder . '/' . $folder ) ) {
				$folders[ $folder ] = $tests_folder . '/' . $folder;
			}
		}

		// Allow custom output folder.
		if ( $output_folder ) {
			$folders['_output'] = $output_folder;
		}

		// Write the default config.
		$config = [
			'paths' => [
				'tests' => $tests_folder,
				'output' => $folders['_output'],
				'data' => $folders['_data'],
				'support' => $folders['_support'],
				'envs' => $folders['_envs'],
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
						'populator' => sprintf(
							'export DB_NAME=%1$s && ' .
								'wp core multisite-install --quiet --url=%2$s --base=/ --title=Testing ' .
								'--admin_user=admin --admin_password=password --admin_email=admin@%3$s ' .
								'--skip-email --skip-config && wp altis migrate --url=%2$s',
							'%TEST_SITE_DB_NAME%',
							'%TEST_SITE_WP_URL%',
							'%TEST_SITE_WP_DOMAIN%',
						),
						'populate' => true,
						'cleanup' => false,
						'waitlock' => 10,
						'url' => '%TEST_SITE_WP_URL%',
						'urlReplacement' => false,
						'tablePrefix' => '%TEST_SITE_TABLE_PREFIX%',
						'letAdminEmailVerification' => true,
						'letCron' => true,
					],
					'WPCLI' => [
						'path' => '/usr/src/app',
						'require' => '/usr/src/app/index.php',
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
					'WPWebDriver' => [
						'url' => '%TEST_SITE_WP_URL%',
						'adminUsername' => '%TEST_SITE_ADMIN_USERNAME%',
						'adminPassword' => '%TEST_SITE_ADMIN_PASSWORD%',
						'adminPath' => '%TEST_SITE_WP_ADMIN_PATH%',
						'browser' => $run_headless_browser,
						'host' => '172.17.0.1',
						'port' => '4444',
						'wait' => 20,
						'window_size' => false, // disabled for Chrome driver.
						'capabilities' => [
							'chromeOptions' => [
								'args' => [
									'--headless',
									'--disable-gpu',
									'--proxy-server=\'direct://\'',
									'--proxy-bypass-list=*',
									'--user-agent="Mozilla/5.0 (Macintosh; Intel Mac OS X 10_14_6) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/95.0.4638.69 Safari/537.36 wp-browser"',
								],
							],
							'moz:firefoxOptions' => [
								'args' => [
									'-headless',
								],
								'prefs' => [
									'general.useragent.override' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10.14; rv:70.0) Gecko/20100101 Firefox/70.0 wp-browser',
								],
							],
							'EdgeOptions' => [
								'args' => [
									'-user-agent="Mozilla/5.0 (Macintosh; Intel Mac OS X 10_14_6) AppleWebKit/605.1.15 (KHTML, like Gecko) Chrome/95.0.4638.69 Safari/604.1 Edg/95.0.100.0 wp-browser"',
								],
							],
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
							'bootstrap_codeception_wp',
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
TEST_SITE_WP_URL=https://$project_subdomain.altis.dev
TEST_SITE_WP_DOMAIN=$project_subdomain.altis.dev
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

		// Check for passed config option.
		if ( ! preg_match( '/(-c|--configuration)\s+/', implode( ' ', $options ) ) ) {
			$options = array_merge(
				[ '-c', 'vendor/codeception.yml' ],
				$options
			);
		}

		if ( $test_suite ) {
			$suites = [ $test_suite ];
		} else {
			// Codeception command runs within `vendor` directory, so paths are relative to that.
			$suites = $this->get_test_suites( 'vendor/' . $tests_folder );
			$output->write(
				sprintf( '<info>Detected %d suites, (%s)..</info>', count( $suites ), implode( ', ', $suites ) ),
				true,
				$output::VERBOSITY_NORMAL
			);
		}

		$return = '';

		// Write temp file during test run.
		$temp_run_file_path = $this->get_root_dir() . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . '.test-running';
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_file_put_contents
		file_put_contents( $temp_run_file_path, 'true' );

		// Iterate over the suites.
		foreach ( $suites as $suite ) {
			$output->write( sprintf( '<info>Running "%s" test suite..</info>', $suite ), true, $output::VERBOSITY_NORMAL );

			// Ensure cache is clean.
			$this->run_command( $input, $output, 'wp', [ 'cache', 'flush', '--quiet' ] );

			// Create database if needed.
			if ( $this->suite_has_module( 'vendor/' . $tests_folder . '/' . $suite . '.suite.yml', 'WPDb' ) ) {
				$this->create_test_db( $input, $output );

				register_shutdown_function( function() use ( $input, $output, $temp_run_file_path ) {
					$output->write( '<info>Removing test databases..</info>', true, $output::VERBOSITY_NORMAL );
					$this->delete_test_db( $input, $output );
					unlink( $temp_run_file_path );
				} );
			}

			// Run the headless browser container if needed.
			if ( $this->suite_has_module( 'vendor/' . $tests_folder . '/' . $suite . '.suite.yml', 'WPWebDriver' ) ) {
				// Start a new container.
				$this->start_browser_container( $input, $output );

				// Stop the container on shutdown.
				register_shutdown_function( function() use ( $input, $output ) {
					$output->write( '<info>Removing headless browser container..</info>', true, $output::VERBOSITY_NORMAL );
					$this->stop_browser_container( $input, $output );
				} );
			}

			// Add the suite name to options here, if not passed already, and we have more than one.
			if ( empty( $test_suite ) ) {
				$options = $this->add_suite_name_to_options( $options, $suite );
			}

			$output->write( '<info>Running CodeCeption..</info>', true, $output::VERBOSITY_NORMAL );
			$return = $this->run_command( $input, $output, 'vendor/bin/codecept', $options );
		}

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
	 * Bootstrap tests folder.
	 *
	 * @param string $tests_folder Target folder to boostrap the tests into.
	 * @param InputInterface $input
	 * @param OutputInterface $output
	 *
	 * @return void
	 */
	protected function bootstrap_codecept( string $tests_folder, InputInterface $input, OutputInterface $output ) {
		$default_suites = $this->get_test_suites( 'vendor/altis/dev-tools/tests' );

		$suites = $input->getArguments()['options'][1] ?? '';
		if ( 0 === strpos( $suites, '-' ) ) {
			$suites = $default_suites;
		} else {
			$suites = explode( ',', $suites );
			$invalid = array_diff( $suites, $default_suites );
			if ( count( $invalid ) ) {
				throw new Exception(
					sprintf(
						'Invalid suites selected: "%s", available suites are: "%s".',
						implode( ',', $invalid ),
						implode( ',', $default_suites )
					)
				);
			}
		}

		$base_path = getcwd(); // Do we have another way to detect this ?
		$tests_folder = $base_path . '/' . $tests_folder;

		if ( ! file_exists( $tests_folder ) ) {
			mkdir( $tests_folder, 0755, true );
		} else {
			foreach ( $suites as $suite ) {
				if ( file_exists( $tests_folder . '/' . $suite ) ) {
					throw new Exception( sprintf( 'An existing "%s" suite was found, halting execution.', $suite ) );
				}
			}
		}

		$template_path = 'vendor/altis/dev-tools/tests/%s.suite.yml';
		foreach ( $suites as $suite ) {
			$suite_path = sprintf( '%s/%s.suite.yml', $tests_folder, $suite );
			copy( sprintf( $template_path, $suite ), $suite_path );
			mkdir( $tests_folder . '/' . $suite, 0755, true );
		}

		$output->write( sprintf( '<info>Created test suites (%s) in %s.</info>', implode( ',', $suites ), $tests_folder ) );
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
					'DROP DATABASE IF EXISTS test; DROP DATABASE IF EXISTS test2; CREATE DATABASE test; CREATE DATABASE test2; GRANT ALL PRIVILEGES ON test.* TO wordpress IDENTIFIED BY \"wordpress\"; GRANT ALL PRIVILEGES ON test2.* TO wordpress IDENTIFIED BY \"wordpress\";',
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

		// Remove ES indexes.
		$return_val = $cli->run( new ArrayInput( [
			'subcommand' => 'exec',
			'options' => [
				'curl',
				'--silent',
				'-o',
				'/dev/null',
				'-XDELETE',
				'http://elasticsearch:9200/ep-tests-*',
			],
		] ), $output );

		return $return_val;
	}

	/**
	 * Get the name of the project for the local subdomain
	 *
	 * @return string
	 */
	protected function get_project_subdomain() : string {

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$composer_json = json_decode( file_get_contents( getcwd() . '/composer.json' ), true );

		if ( isset( $composer_json['extra']['altis']['modules']['local-server']['name'] ) ) {
			$project_name = $composer_json['extra']['altis']['modules']['local-server']['name'];
		} else {
			$project_name = basename( getcwd() );
		}

		return preg_replace( '/[^A-Za-z0-9\-\_]/', '', $project_name );
	}

	/**
	 * Start a headless browser container for WebDriver used in acceptance tests.
	 *
	 * @param InputInterface $input
	 * @param OutputInterface $output
	 *
	 * @return int
	 */
	protected function start_browser_container( InputInterface $input, OutputInterface $output ) {
		$columns = exec( 'tput cols' );
		$lines = exec( 'tput lines' );
		$browser = $input->getOption( 'browser' ) ?: 'chrome';
		$output->write( sprintf( '<info>Starting headless "%s" browser container..</info>', $browser ), true, $output::VERBOSITY_NORMAL );

		$available_browsers = [
			'chrome',
			'firefox',
			// 'edge', // TODO Buggy driver, dig deeper.
		];

		if ( ! in_array( $browser, $available_browsers, true ) ) {
			throw new InvalidArgumentException( sprintf(
				'Browser "%s" is unavailable, available browsers are: %s.',
				$browser,
				implode( ', ', $available_browsers ),
			) );
		}

		// Stop any lingering containers first.
		$this->stop_browser_container( $input, $output );

		// This exports ports 4444 for the Selenium hub web portal, and 7900 for the noVNC server.
		$base_command = sprintf(
			'docker run ' .
				'-d ' .
				'-e COLUMNS=%1%d -e LINES=%2$d ' .
				'--network=host ' .
				'--name=%3$s_selenium ' .
				'--shm-size="2g" ' .
				'selenium/standalone-%4$s:4.0.0-20211102',
			$columns,
			$lines,
			$this->get_project_subdomain(),
			$browser,
		);

		passthru( $base_command, $return_var );

		// Allow time for selenium app to boot up.
		sleep( 5 );

		return $return_var;
	}

	/**
	 * Stop the headless browser container.
	 *
	 * @param InputInterface $input
	 * @param OutputInterface $output
	 *
	 * @return int
	 */
	protected function stop_browser_container( InputInterface $input, OutputInterface $output ) {
		$base_command = sprintf( 'docker ps -q -a --filter "name=%1$s_selenium" | grep -q . && docker rm -f %1$s_selenium &> /dev/null', $this->get_project_subdomain() );

		passthru( $base_command, $return_var );

		return $return_var;
	}

	/**
	 * Return suite files within a tests folder.
	 *
	 * @param string $folder Tests folder to scan.
	 * @return array
	 */
	protected function get_test_suites( $folder ) : array {
		$suites = glob( $folder . '/*.suite.yml' );
		foreach ( $suites as $i => $suite ) {
			$suites[ $i ] = substr( $suite, strlen( $folder ) + 1, strpos( $suite, '.suite.yml' ) - strlen( $suite ) );
		}

		return $suites;
	}

	/**
	 * Returns whether a suite configuration requires a specific module.
	 *
	 * @param string $suite_file Suite YML file.
	 * @param string $module Module name.
	 *
	 * @return boolean
	 */
	protected function suite_has_module( string $suite_file, string $module ) : bool {
		$suite_config = file_get_contents( $suite_file );
		$needs_web_driver = preg_match( "#- {$module}#", $suite_config );

		return $needs_web_driver;
	}

	/**
	 * Return the test suite selected from CLI arguments.
	 *
	 * @param InputInterface $input
	 *
	 * @return string
	 */
	protected function get_test_suite_argument( InputInterface $input ) : string {
		$arguments = $input->getArguments();
		$first_argument = $arguments['options'][1] ?? '';

		// Test suite should be the first argumet so we can check for it, otherwise, assume no suite is selected.
		if ( 0 === strpos( $first_argument, '-' ) ) {
			return '';
		}

		return $first_argument;
	}

	/**
	 * Add suite name to options passed to codecept command.
	 *
	 * @param array $options Options array.
	 * @param string $suite_name Suite name.
	 *
	 * @return array
	 */
	protected function add_suite_name_to_options( array $options, string $suite_name ) : array {
		$index = array_search( 'run', $options );
		array_splice( $options, $index + 1, 0, [ $suite_name ] );
		return $options;
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
