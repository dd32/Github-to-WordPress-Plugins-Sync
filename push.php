<?php
/**
 * Sync a Github Repository to WordPress.org Plugins SVN
 * Relies upon the Github 'push' webhook variables.
 */
class Github_to_Worg_SVN {
	private $config = array(
		'svns_location' => '',
		'repos' => array(),
	);
	private $repo   = array(
		'secret' => '',
		'svn_url' => '',
		'username' => '',
		'password'  => '',
		'github_svn' => '',
		'checkout_dir' => '',
	);

	// Used for signature validation
	private $post_data = '';

	function __construct() {
		$this->populate_config();
		$this->populate_post_vars();

		$this->log_request();
		$this->handle_request();
	}

	function populate_config() {
		if ( file_exists( __DIR__ . '/config.php' ) ) {
			include __DIR__ . '/config.php';
		}
		$this->config = get_defined_vars();

		// Defaults
		if ( empty( $this->config['svns_location'] ) ) {
			$this->config['svns_location'] = __DIR__ . '/svns/';
		}
	}

	function populate_post_vars() {
		if ( 'application/json' == $_SERVER['HTTP_CONTENT_TYPE'] ) {
			$_POST = @json_decode( file_get_contents('php://input'), true );
		} else {
			// Assuming Magic Quotes disabled like a good host.
			$_POST = @json_decode( $_POST['payload'], true );
		}
	}

	function setup_repo_config( $github_repo ) {
		if ( ! $github_repo || ! isset( $this->config['repos'][ $github_repo ] ) ) {
			return false;
		}
		$this->repo = &$this->config['repos'][ $github_repo ];

		$this->repo['github_svn'] = $_POST['repository']['svn_url'];
		$this->repo['checkout_dir'] = $this->config['svns_location'] . basename( $this->repo['svn_url'] ) . '-' . sha1( $this->repo['svn_url'] );

		return true;
	}

	function handle_request() {
		$github_repo = $_POST['repository']['full_name'];

		if ( ! $this->setup_repo_config( $github_repo ) ) {
			header( 'HTTP/1.1 400 Repo Not Configured.', true, 400 );
			die( 'Repo Not Configured.' );
		}

		if ( ! $this->verify_github_signature() ) {
			header( 'HTTP/1.1 400 Not Github.', true, 404 );
			die( 'Not Github.' );
		}

		$this->initialize_svn_repo();
		$this->overlay_github();
			// \-> calls $this->perform_text_replacements();
		$this->check_in_changes( $this->generate_commit_message() );	
	}

	function verify_github_signature() {
		if ( empty( $this->repo['secret'] ) ) {
			return true;
		}
		if ( empty( $_SERVER['HTTP_X_HUB_SIGNATURE'] ) ) {
			return false;
		}

		list( $algo, $hash ) = explode( '=', $_SERVER['HTTP_X_HUB_SIGNATURE'], 2 );

		$hmac = hash_hmac( $algo, file_get_contents('php://input' ), $this->repo['secret'] );

		return $hash === $hmac;
	}

	function initialize_svn_repo( $rev = 'HEAD' ) {
		if ( ! is_dir( $this->repo['checkout_dir'] ) ) {
			$this->exec( "svn co --non-interactive --force -r {rev} {svn_url} {checkout_dir}", compact( 'rev' ) );
		}
	
		$this->exec( "svn up --non-interactive --force -r {rev} {checkout_dir}", compact( 'rev' ) );
	}

	function overlay_github( $rev = 'HEAD' ) {
	
		$export_output = $this->exec( "svn export --non-interactive --force -r {rev} {github_svn} {checkout_dir}", compact( 'rev' ) );
		$github_svn_rev_exported = $rev;
		if ( preg_match( '!Exported revision (\d+)\.!i', $export_output, $svn_rev_match ) ) {
			$github_svn_rev_exported = $svn_rev_match[1];
		}

		// Move branches/assets to /assets
		$this->exec( "rm -rf {checkout_dir:/assets}" );
		$this->exec( "mv {checkout_dir:/trunk/assets} {checkout_dir}" );

		// And remove them from tag/branch builds (Github will have it, but that's okay)
		$this->exec( "rm -rf {checkout_dir:/}{branches,tags}/*/assets" );

		// Add all files
		$this->exec( "svn add --non-interactive --force {checkout_dir:/}*" );

		// Handle all replacements
	
		$this->perform_text_replacements( $github_svn_rev_exported );
	}
	
	function perform_text_replacements( $rev ) {
		$replacements = array(
			'%GITHUB_MERGE_DATE%' => gmdate( 'Y-m-d' ),
			'%GITHUB_MERGE_DATETIME%' => gmdate( 'Y-m-d h:i:s' ),
			'%GITHUB_MERGE_SVN_REV%' => $rev
		);
	
		foreach ( $replacements as $token => $value ) {
			$this->exec(
				"grep -rli '%GITHUB_' --exclude-dir='.svn' {checkout_dir} | xargs -i@ sed -i 's/{token}/{value}/g' @",
				compact( 'token', 'value' ),
				true,
				array( 'token' => true, 'value' => true )
			);
		}
	}

	function check_in_changes( $message ) {
		// Display a diff
		$this->exec( "svn diff --non-interactive --no-diff-deleted {checkout_dir}" );
		$this->exec( "svn ci --non-interactive --username {username} --password {password} -m {message} {checkout_dir}", compact( 'message' ) );
	}

	function generate_commit_message() {
		$commit_message = '';
		foreach ( (array)$_POST['commits'] as $i => $commit ) {
			if ( $i > 0 ) {
				$commit_message .= "\n-----\n";
			}
		
			$commit_message .= "{$commit['message']}\n";
			if ( $commit['author']['username'] == $commit['committer']['username'] ) {
				$commit_message .= "Author: {$commit['author']['username']} @ {$commit['timestamp']}\n";
			} else {
				$commit_message .= "Author: {$commit['author']['username']} ";
				$commit_message .= "Commited by: {$commit['committer']['username']} @ {$commit['timestamp']}\n";
			}
			$commit_message .= "{$commit['url']}";
		}
		$commit_message .= "\n\nMerged from Github: {$_POST['compare']}";

		return $commit_message;
	}

	/* Log the incoming request if enabled */
	function log_request() {
		if ( ! $this->config['save_log'] ) {
			return;
		}
		file_put_contents(
			$this->config['save_log'],
			json_encode($_POST) . "\n\n",
			FILE_APPEND
		);
	}

	/**
	 * Execute a Shell command, with helpful placeholders and suffixing.
	 * The placeholder can exist in $args, $repo_config, or $config itself.
	 * The placeholders will be escaped, unless they appear in $pre_escaped.
	 * Placeholders can suffix: {variable:/trunk/} will expand to '{variable}/trunk/' when escaped
	 *
	 * Example: exec( "svn up --username {username} {checkout_dir:/trunk}", array( 'checkout_dir' => __DIR__ ) );
	 * resulting in: "svn up --username 'alfred' '/home/....../trunk'"
	 */
	function exec( $command, $args = array(), $echo = true, $pre_escaped = array() ) {
		$config = $this->config;
		$repo   = $this->repo;

		$command = preg_replace_callback( '!{([^}: ]+)(:([^} ]+))?}!i', function( $match ) use ( $args, $config, $repo, $pre_escaped ) {
			$placeholder = $match[1];
			$suffix = ! empty( $match[3] ) ? $match[3] : '';

			foreach ( array( 'args', 'repo' ) as $blob ) {
				if ( isset( ${$blob}[ $placeholder ] ) ) {
					$replacement = ${$blob}[ $placeholder ];

					// Special case to keep the password out of the CLI arg
					if ( 'password' == $placeholder ) {
						putenv( "PHP_SVN_PASSWORD={$replacement}{$suffix}" );
						return '$PHP_SVN_PASSWORD';
					}

					if ( !empty( $pre_escaped[ $placeholder ] ) ) {
						return $replacement . $suffix;
					} else {
						return escapeshellarg( $replacement . $suffix );
					}
				}
			}

			// Return the input
			return $match[0];
		}, $command );

		$command .= ' 2>&1';

		$output = shell_exec( $command );

		// Cleanup
		putenv( "PHP_SVN_PASSWORD=" );

		if ( $echo ) {
			echo "Command: {$command}\n{$output}\n";
		}

		return $output;
	}
}
new Github_to_Worg_SVN();