<?php
# class.release.php
#
# The main release class used in the release script.
#

class release {
	private $platform_null;
	private $platform;
	private $path;
	private $tag;
	private $svn_username;
	private $placeholders;
	private $config_settings;
	private $plugin_slug;
	private $sys_temp_dir = false;
	private $home_dir = false;
	private $temp_dir = false;
	private $temp_file = false;
	private $svn_modified;
	private $latest_wp_version = '4.5';

	public function __construct() {
		$this->home_dir = getcwd();

		// We need to set some platform specific settings.
		if( strtoupper( substr( PHP_OS, 0, 3 ) ) === 'WIN' ) {
			$this->platform_null = ' > nul 2>&1';
			$this->platform = 'win';
		} else {
			$this->platform_null = ' > /dev/null 2>&1';
			$this->platform = 'nix';
		}
	}

	/*
	 *
	 * Public functions
	 *
	 */
	public function process_args() {
		GLOBAL $argc, $argv;

		// If we have less than two parameters ( [0] is always the script name itself ), bail.
		if( $argc < 3 ) {
			$this->error_and_exit( "Error, you must provide at least a path and tag!" );
		}

		// First param is the path/slug to use, second is the tag.
		$this->path = $argv[1];
		$this->tag = $argv[2];

		// Third (optional) is the svn user to use.
		if( $argc > 3 ) {
			$this->svn_username = '--username="' . $argv[3] . '" ';
		}

		$path_first_char = substr( $this->path, 0, 1 );
		$path_second_char = substr( $this->path, 1, 1 );

		// The path can either be an absolute path, a relative path or just a tag.  If it's just a tag, then we assume it's in the directory above us.
		if( $path_first_char != '.' && $path_first_char != '/' && $path_first_char != '\\' && $path_second_char != ':' ) {
			$this->path = '../' . $this->path;
		}

		// Let's get the realpath.
		$this->path = realpath( $this->path );

		if( $this->path == false ) {
			$this->error_and_exit( "Error, path to GIT repo not found!" );
		}
	}

	public function get_config() {
		/* Check to see if we have a settings file, the order is:
		 *
		 * 1. Root of the GIT Repo.
		 * 2. /release directory in the GIT Repo.
		 * 3. /bin directory in the GIT Repo.
		 * 4. Current directory.
		 * 5. Release.ini in the current directory.
		 */
		$plugin_release_ini = false;
		if( file_exists( $this->path . '/release.ini' ) ) {
			$plugin_release_ini = $this->path . '/release.ini';
		} else if( file_exists( $this->path . '/release/release.ini' ) ) {
			$plugin_release_ini = $this->path . '/release/release.ini';
		} else if( file_exists( $this->path . '/bin/release.ini' ) ) {
			$plugin_release_ini = $this->path . '/bin/release.ini';
		}

		$default_ini_settings = parse_ini_file( './release.ini' );
		$local_ini_settings = $plugin_ini_settings = array();

		if( file_exists( '../release.ini' ) ) {
			echo 'Local release.ini to use: ../release.ini' . PHP_EOL;

			$local_ini_settings = parse_ini_file( '../release.ini' );
		}

		if( $plugin_release_ini != false ) {
			echo "Plugin release.ini to use: {$plugin_release_ini}" . PHP_EOL;

			$plugin_ini_settings = parse_ini_file( $plugin_release_ini );
		}

		// Merge the three settings arrays in to a single one.  We can't use array_merge() as
		// we don't want a blank entry to override a setting in another file that has a value.
		// For example svn-username may not be set in the default or plugin ini files but in
		// the local file, but the "key" exists in all three.  The "blank" key in the plugin
		// file would wipe out the value in the local file.
		$ini_settings = $default_ini_settings;

		foreach( $local_ini_settings as $key => $value ) {
			if( trim( $value ) != '' ) {
				$ini_settings[$key] = $value;
			}
		}

		foreach( $plugin_ini_settings as $key => $value ) {
			if( trim( $value ) != '' ) {
				$ini_settings[$key] = $value;
			}
		}

		// The plugin slug is over ridable in the ini file, so if it exists in the ini file use it, otherwise
		// assume the current basename of the path is the slug (after converting it to lower case and
		// replacing spaces with dashes.
		if( $ini_settings['plugin-slug'] ) {
			$this->plugin_slug = $ini_settings['plugin-slug'];
		} else {
			$this->plugin_slug = basename( $this->path );
			$this->plugin_slug = strtolower( $this->plugin_slug );
			$this->plugin_slug = str_replace( ' ', '-', $this->plugin_slug );
		}

		// Retrieve the current WP version from the wordpress.org API.
		$this->set_current_wp_version();

		// Now that we have our config variables we can define the placeholders.
		$this->placeholders = array( 'tag' => $this->tag, 'plugin-slug' => $this->plugin_slug, 'wp-version' => $this->latest_wp_version );

		// Now create our configuration settings by taking the ini settings and replacing any placeholders they may contain.
		$this->config_settings = array();
		foreach( $ini_settings as $setting => $value ) {
			$this->config_settings[$setting] = $this->release_replace_placeholders( $value, $this->placeholders );
		}

		if( ! empty( $this->config_settings['temp-dir'] ) && is_dir( $this->config_settings['temp-dir'] ) ) {
			$this->sys_temp_dir = $this->config_settings['temp-dir'];
		} else {
			$this->sys_temp_dir = sys_get_temp_dir();
		}
	}

	public function set_temp_dir_and_file() {
		// Get a temporary working directory to checkout the SVN repo to.
		$this->temp_dir = tempnam( $this->sys_temp_dir, "GWP" );
		unlink( $this->temp_dir );
		mkdir( $this->temp_dir );
		echo "Temporary dir: {$this->temp_dir}" . PHP_EOL;

		// Get a temporary filename for the GIT tar file we're going to checkout later.
		$this->temp_file = tempnam( $this->sys_temp_dir, "GWP" );
	}

	public function validate_git_repo() {
		// Ok, time to get serious, change to the GIT repo directory.
		chdir( $this->path );

		// Let's make sure the local repo is up to date, do a pull.
		echo 'Pulling the current repo...';
		exec( '"' . $this->config_settings['git-path'] . 'git" pull ' .  $this->platform_null, $output, $result );
		echo ' done.'  . PHP_EOL;

		// Let's make sure the tag exists.
		echo 'Checking if the tag exists in git...';
		exec( '"' . $this->config_settings['git-path'] . 'git" rev-parse "' . $this->tag . '"' .  $this->platform_null, $output, $result );

		if( $result ) {
			echo ' no.' . PHP_EOL;

			if( ! $this->config_settings['git-do-not-tag'] ) {
				$this->error_and_exit( "Aborting, tag not found in GIT and we're not tagging one!" );
			} else {
				echo "Tagging {$this->tag} in the GIT repo...";

				exec( '"' . $this->config_settings['git-path'] . 'git" tag "' . $this->tag . '" -m "' . $this->config_settings['git-tag-message'] . '' .  $this->platform_null, $output, $result );

				if( $result ) {
					$this->error_and_exit( " error creating tag!" );
				} else {
					echo ' done.' . PHP_EOL;
				}
			}
		} else {
			echo ' yes!' . PHP_EOL;
		}
	}

	public function validate_svn_repo() {
		// Let's check to see if the tag already exists in SVN, if we're using a tag that is.
		if( ! $this->config_settings['svn-do-not-tag'] ) {
			exec( '"' . $this->config_settings['svn-path'] . 'svn" info ' . $this->svn_username . '"' . $this->config_settings['svn-url'] . '/tags/' . $this->tag . '"' .  $this->platform_null, $output, $result );

			if( ! $result ) {
				$this->error_and_exit( "Error, tag already exists in SVN." );
			}
		}
	}

	public function checkout_svn_repo() {
		// Time to checkout the SVN tree.
		echo "Checking out SVN tree from: {$this->config_settings['svn-url']}/trunk...";
		exec( '"' . $this->config_settings['svn-path'] . 'svn" co ' . $this->svn_username . '"' . $this->config_settings['svn-url'] . '/trunk" "' . $this->temp_dir . '"' .  $this->platform_null, $output, $result );

		if( $result ) {
			$this->error_and_exit( " error, SVN checkout failed." );
		} else {
			echo ' done.'  . PHP_EOL;
		}
	}

	public function extract_git_repo() {
		// Extract the GIT repo files to the SVN checkout directory via a tar file.
		echo 'Extracting GIT repo for update...';
		exec( '"' . $this->config_settings['git-path'] . 'git" archive --format="zip" "' . $this->tag . '" > "' . $this->temp_file . '"', $output, $result );

		if( $result ) {
			$this->error_and_exit( " error, GIT extract failed." );

		}

		$zip = new ZipArchive;
		if ( $zip->open( $this->temp_file, ZipArchive::CHECKCONS ) === TRUE ) {
			if( $zip->numFiles == 0 || FALSE === $zip->extractTo( $this->temp_dir ) ) {
				$this->error_and_exit( " error, extracting zip files failed." );
			}

			$zip->close();
		} else {
			$this->error_and_exit( " error, opening zip file failed." );
		}

		echo ' done!' . PHP_EOL;
	}

	public function generate_readme() {
		// Get the readme and changelog files if they exist.
		echo 'Generating readme.txt...';
		$readme = $changelog = false;

		if( $this->config_settings['readme-template'] && file_exists( $this->path . '/' . $this->config_settings['readme-template'] ) ) {
			$readme = file_get_contents( $this->path . '/' . $this->config_settings['readme-template'] );

			// Replace any placeholders that are in the template file.
			$readme = $this->release_replace_placeholders( $readme, $this->placeholders );
		}

		if( $this->config_settings['changelog'] && file_exists( $this->path . '/' . $this->config_settings['changelog'] ) ) {
			$changelog = file_get_contents( $this->path . '/' . $this->config_settings['changelog'] );
			$split_cl = explode( PHP_EOL, $changelog );

			// Since the changelog is in "standard" MarkDown format, convert it to "WordPress" MarkDown format.
			// Note: Can't use a simple regex as the EOL marker may be '\r\n' or '\n' depending on the platform and
			//       preg_match() only match '\n' for newlines, leaving an extra '\r' that messes up the formating.
			$changelog = '';
			foreach( $split_cl as $line ) {
				if( '##' == substr( $line, 0, 2 ) ) {
					$line = '= ' . trim( substr( $line, 2 ) ) . ' =';
				}

				$changelog .= $line . PHP_EOL;
			}
		}

		// If we found a readme/changelog write it out as readme.txt in the temp directory.
		if( $readme != false ) {
			$readme_file = fopen( $this->temp_dir . '/readme.txt', 'w' );
			fwrite( $readme_file, $readme );

			if( $changelog != false ) {
				fwrite( $readme_file, $changelog );
			}

			fclose( $readme_file );
		}

		echo ' done!' . PHP_EOL;
	}

	public function delete_files_and_directories() {
		echo 'Deleting files...';
		// Get a list of files to delete.
		$delete_files = explode( ',', $this->config_settings['DeleteFiles'] );
		$prefix = ' ';
		$post_msg = ' no files to delete.';

		// Delete the files.
		foreach( $delete_files as $file ) {
			$file = trim( $file );
			if( file_exists( $this->temp_dir . '/' . $file ) ) {
				unlink( $this->temp_dir . '/' . $file );
				echo $prefix . $file;
				$prefix = ', ';
				$post_msg = '.';
			}
		}

		echo $post_msg . PHP_EOL;

		echo 'Deleting directories...';

		// Get a list of directories to delete.
		$delete_dirs = explode( ',', $this->config_settings['DeleteDirs'] );
		$prefix = ' ';
		$post_msg = ' no directories to delete.';

		// Delete the directories.
		foreach( $delete_dirs as $dir ) {
			$dir = trim( $dir );
			if( is_dir( $this->temp_dir . '/' . $dir ) ) {
				$this->delete_tree( $this->temp_dir . '/' . $dir );
				echo $prefix . $dir;
				$prefix = ', ';
				$post_msg = '.';
			}
		}

		echo $post_msg . PHP_EOL;
	}

	public function add_files_to_svn() {
		// We need to move to the SVN temp directory to do some SVN commands now.
		chdir( $this->temp_dir );

		// Do an SVN status to get any files we need to add to the wordpress.org SVN tree.
		echo 'Files to add to SVN...';
		exec( '"' . $this->config_settings['svn-path'] . 'svn" status ' . $this->svn_username . '>' .  $this->temp_file, $output, $result );

		// Since we can't redirect to null in this case (we want the output) use the temporary file to hold the output and now read it in.
		$output = file_get_contents( $this->temp_file );

		// Let's convert the end of line marks in case we're on Windows.
		$output = str_replace( "\r\n", "\n", $output );

		// Now split the output in to lines.
		$output = explode( "\n", $output );
		$prefix = ' ';
		$post_msg = ' no files to add.';

		$this->platform_null = '';

		foreach( $output as $line ) {
			$first_char = substr( $line, 0, 1 );
			$name = trim( substr( $line, 1 ) );

			if( $first_char == '?' ) {
				exec( '"' . $this->config_settings['svn-path'] . 'svn" add ' . $this->svn_username . '"' . $name . '"' . $this->platform_null, $output, $result );

				echo $prefix . $name;
				$prefix = ', ';
				$post_msg = '.';
			} else if ( $first_char == 'M' ) {
				// Keep track of the modified files for use later.
				$this->svn_modified[] = $name;
			}
		}

		echo $post_msg . PHP_EOL;
	}

	public function delete_files_from_svn() {
		// Compare the GIT and SVN directories to see if there are any files we need to delete.
		echo 'Files to delete from SVN...';
		$git_files = $this->get_file_list( $this->path );
		$git_files = $this->make_file_paths_relative( $git_files, $this->path );
		$svn_files = $this->get_file_list( $this->temp_dir );
		$svn_files = $this->make_file_paths_relative( $svn_files, $this->temp_dir );
		$prefix = ' ';
		$post_msg = ' no files to delete from SVN.';

		foreach( $svn_files as $file ) {
			if( ! in_array( $file, $git_files ) && '.svn' != $file && '.svn/' != substr( $file, 0, 5 ) && $file != 'readme.txt' ) {
				exec( '"' . $this->config_settings['svn-path'] . 'svn" delete ' . $this->svn_username . '' . $file . $this->platform_null, $output, $result );

				echo $prefix . $file;
				$prefix = ', ';
				$post_msg = '.';
			}
		}

		echo $post_msg . PHP_EOL;
	}

	public function update_files_to_svn() {
		echo 'Modified files to commit to SVN...';

		$prefix = ' ';
		$post_msg = ' no files to commit to SVN.';
		$display_count = count( $this->svn_modified );
		$remainder = $display_count;

		if( $display_count > 5 ) { $display_count = 5; }

		$remainder = $remainder - $display_count;

		if( $display_count > 0 && $remainder > 0 ) { $post_msg = " and {$remainder} more."; }

		if( $display_count > 0 && $remainder < 1 ) { $post_msg = "."; }

		for( $i = 0; $i < $display_count; $i++ ) {
			echo $prefix . $this->svn_modified[$i];
			$prefix = ', ';
		}

		echo $post_msg . PHP_EOL;
	}

	public function confirm_commit() {
		echo PHP_EOL;
		echo "About to commit {$this->tag}. Double-check {$this->temp_dir} to make sure everything looks fine." . PHP_EOL;
		echo PHP_EOL;
		echo "Type 'YES' in all capitals and then return to continue." . PHP_EOL;

		$fh = fopen( 'php://stdin', 'r' );
		$message = fgets( $fh, 1024 ); // read the special file to get the user input from keyboard
		fclose( $fh );

		if( trim( $message ) != 'YES' ) {
			$this->error_and_exit( "Commit aborted." );
		}
	}

	public function commit_svn_changes() {
		echo 'Committing to SVN...';
		exec( '"' . $this->config_settings['svn-path'] . 'svn" commit ' . $this->svn_username . '-m "' . $this->config_settings['svn-commit-message'] . '"', $output, $result );

		if( $result ) {
			$this->error_and_exit( " error, commit failed." );
		}

		echo ' done!' . PHP_EOL;

		if( ! $this->config_settings['svn-do-not-tag'] ) {
			echo 'Tagging SVN...';

			exec( '"' . $this->config_settings['svn-path'] . 'svn" copy ' . $this->svn_username . '"' . $this->config_settings['svn-url'] . '/trunk" "' . $this->config_settings['svn-url'] . '/tags/' . $this->tag . '" -m "' . $this->config_settings['svn-tag-message'] . '"', $output, $result );

			if( $result ) {
				$this->error_and_exit( " error, tag failed." . PHP_EOL );
			}

			echo ' done!' . PHP_EOL;
		}

		$this->clean_up();
	}

	/*
	 *
	 * Private functions
	 *
	 */

	private function clean_up() {
		// We have to fudge the delete of the hidden SVN directory as unlink() will throw an error otherwise.
		if( $this->platform == 'win' && false !== $this->temp_dir ) {
			rename( $this->temp_dir . '/.svn/', $this->temp_dir . 'svn.tmp.delete' );
		}

		if( false !== $this->temp_dir ) {
			// Clean up the temporary dirs/files.
			$this->delete_tree( $this->temp_dir );
		}

		if( false !== $this->temp_file ) {
			unlink( $this->temp_file );
		}

		chdir( $this->home_dir );
	}

	private function delete_tree( $dir ) {
		if( ! is_dir( $dir ) ) {
			return true;
		}

		$files = array_diff( scandir( $dir ), array( '.', '..' ) );

		foreach ( $files as $file ) {
			if( is_dir( "$dir/$file" ) ) {
				$this->delete_tree("$dir/$file");
			} else {
				unlink("$dir/$file");
			}
		}

		return rmdir( $dir );
	}

	private function get_file_list( $dir ) {
		$files = array_diff( scandir( $dir ), array( '.', '..' ) );
		array_walk( $files, array( $this, 'add_dir_to_item' ), $dir );

		foreach ( $files as $file ) {
			if( is_dir( $file ) ) {
				$files = array_merge( $files, $this->get_file_list( $file ) );
			}
		}

		return $files;
	}
	
	private function add_dir_to_item( &$item, $key, $append ) {
		$item = $append . '/' . $item;
	}

	private function make_file_paths_relative( $files, $dir ) {
		if( '/' != substr( $dir, -1, 1 ) ) {
			$dir = $dir . '/';
		}
		
		array_walk( $files, array( $this, 'strip_dir_from_item' ), $dir );
		
		return $files;
	}
	
	private function strip_dir_from_item( &$item, $key, $strip ) {
		if ( 0 === strpos( $item, $strip ) ) {
			$item = substr( $item, strlen( $strip ) );
		}
	}
	
	private function release_replace_placeholders( $string, $placeholders ) {
		if( ! is_array( $placeholders ) ) {
			return $string;
		}

		foreach( $placeholders as $tag => $value ) {
			$string = preg_replace( '/{{' . $tag . '}}/i', $value, $string );
		}

		return $string;
	}

	private function error_and_exit( $message ) {
		echo $message . PHP_EOL;

		$this->clean_up();

		exit;
	}

	private function set_current_wp_version() {
		$response = file_get_contents( 'https://api.wordpress.org/core/version-check/1.6/' );

		$version_info = unserialize( $response );

		if( is_array( $version_info ) && array_key_exists( 'offers', $version_info ) && is_array( $version_info['offers'] ) ) {
			foreach( $version_info['offers'] as $offer ) {
				if( is_array( $offer ) ) {
					if( version_compare( $this->latest_wp_version, $offer['current'], '<' ) ) {
						$this->latest_wp_version = $offer['current'];
					}
				}
			}
		}
	}

}
