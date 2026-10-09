<?php
/** Small WordPress adapters used by the bonus-import application services. */
defined( 'ABSPATH' ) || exit;

final class MRSA_Pending_Review_Store {
	public function get( $key ) {
		return get_transient( $key );
	}

	public function put( $key, $value, $expires ) {
		return set_transient( $key, $value, max( 1, (int) $expires - time() ) );
	}

	public function remove( $key ) {
		return delete_transient( $key );
	}
}

final class MRSA_Import_Scheduler {
	public function schedule( $timestamp, $hook, $arguments ) {
		return wp_schedule_single_event( $timestamp, $hook, $arguments, true );
	}
}

final class MRSA_Gallery_Filesystem {
	public function is_file( $path ) { return is_file( $path ); }
	public function is_dir( $path ) { return is_dir( $path ); }
	public function is_link( $path ) { return is_link( $path ); }
	public function exists( $path ) { return file_exists( $path ); }
	public function size( $path ) { return filesize( $path ); }
	public function copy( $source, $destination ) { return copy( $source, $destination ); }
	public function move( $source, $destination ) { return rename( $source, $destination ); }
	public function remove_file( $path ) { return unlink( $path ); }
	public function make_directory( $path, $mode = 0700 ) { return mkdir( $path, $mode ); }
	public function create_tree( $path ) { return wp_mkdir_p( $path ); }
	public function write( $path, $contents ) { return file_put_contents( $path, $contents ); }
	public function files( $pattern ) { return glob( $pattern ); }
	public function remove_empty_directory( $path ) { return rmdir( $path ); }
	public function modified_time( $path ) { return filemtime( $path ); }
	public function hash( $algorithm, $path ) { return hash_file( $algorithm, $path ); }

	public function remove_directory( $directory ) {
		if ( $this->is_link( $directory ) ) {
			throw new RuntimeException( 'Gallery/import cleanup refused a symbolic-link directory.' );
		}
		if ( ! $this->is_dir( $directory ) ) {
			return;
		}
		$iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $directory, FilesystemIterator::SKIP_DOTS ),
			RecursiveIteratorIterator::CHILD_FIRST
		);
		foreach ( $iterator as $entry ) {
			$path = $entry->getPathname();
			$removed = $entry->isDir() && ! $entry->isLink() ? $this->remove_empty_directory( $path ) : $this->remove_file( $path );
			if ( ! $removed ) {
				throw new RuntimeException( 'An event gallery file could not be removed: ' . basename( $path ) );
			}
		}
		if ( ! $this->remove_empty_directory( $directory ) ) {
			throw new RuntimeException( 'The event gallery directory could not be removed.' );
		}
	}
}
