<?php
/**
 * Unit tests for FileHandler (file upload, encryption, deletion).
 *
 * Covers: upload error validation, file size limits (SEC-FILE-03),
 * filename sanitization + double-extension rejection (SEC-FILE-04),
 * MIME type verification incl. finfo cross-check (SEC-FILE-05),
 * directory traversal prevention (SEC-FILE-11), file deletion (SEC-FILE-09),
 * upload directory security files (SEC-FILE-06, SEC-FILE-10),
 * encrypted file decryption (SEC-FILE-08), and full happy-path upload
 * with encryption (SEC-FILE-01, SEC-FILE-07).
 *
 * @package WpDsgvoForm\Tests\Unit\Upload
 */

declare(strict_types=1);

namespace WpDsgvoForm\Tests\Unit\Upload;

use WpDsgvoForm\Upload\FileHandler;
use WpDsgvoForm\Encryption\EncryptionService;
use WpDsgvoForm\Tests\TestCase;
use Brain\Monkey\Functions;
use Mockery;

/**
 * Tests for FileHandler.
 */
class FileHandlerTest extends TestCase {

	private EncryptionService $encryption;
	private FileHandler $handler;
	private string $tmp_dir;

	protected function setUp(): void {
		parent::setUp();

		$this->encryption = Mockery::mock( EncryptionService::class );
		$this->handler    = new FileHandler( $this->encryption );

		$this->tmp_dir = sys_get_temp_dir() . '/dsgvo-fh-test-' . uniqid();
		mkdir( $this->tmp_dir, 0755, true );
	}

	protected function tearDown(): void {
		if ( is_dir( $this->tmp_dir ) ) {
			$this->recursive_rmdir( $this->tmp_dir );
		}
		parent::tearDown();
	}

	/**
	 * Recursively remove a directory.
	 */
	private function recursive_rmdir( string $dir ): void {
		if ( ! is_dir( $dir ) ) {
			return;
		}
		$items = scandir( $dir );
		foreach ( $items as $item ) {
			if ( '.' === $item || '..' === $item ) {
				continue;
			}
			$path = $dir . '/' . $item;
			is_dir( $path ) ? $this->recursive_rmdir( $path ) : unlink( $path );
		}
		rmdir( $dir );
	}

	/**
	 * Mocks wp_upload_dir() to return the test temp directory.
	 */
	private function mock_upload_dir(): void {
		Functions\when( 'wp_upload_dir' )->justReturn(
			array(
				'basedir' => $this->tmp_dir,
				'baseurl' => 'http://example.com/wp-content/uploads',
			)
		);
	}

	// ──────────────────────────────────────────────────
	// Upload error validation
	// ──────────────────────────────────────────────────

	/**
	 * @test
	 */
	public function test_handle_upload_throws_on_missing_error_key(): void {
		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'Invalid file upload data.' );

		$this->handler->handle_upload(
			array( 'name' => 'test.pdf', 'size' => 100, 'tmp_name' => '/tmp/x' ),
			1,
			'enc-dek',
			'dek-iv'
		);
	}

	/**
	 * @test
	 */
	public function test_handle_upload_throws_on_upload_error_ini_size(): void {
		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'File exceeds server upload limit.' );

		$this->handler->handle_upload(
			array( 'name' => 'test.pdf', 'size' => 100, 'tmp_name' => '/tmp/x', 'error' => UPLOAD_ERR_INI_SIZE ),
			1,
			'enc-dek',
			'dek-iv'
		);
	}

	/**
	 * @test
	 */
	public function test_handle_upload_throws_on_upload_error_no_file(): void {
		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'No file was uploaded.' );

		$this->handler->handle_upload(
			array( 'name' => 'test.pdf', 'size' => 0, 'tmp_name' => '', 'error' => UPLOAD_ERR_NO_FILE ),
			1,
			'enc-dek',
			'dek-iv'
		);
	}

	/**
	 * @test
	 */
	public function test_handle_upload_throws_on_partial_upload(): void {
		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'File was only partially uploaded.' );

		$this->handler->handle_upload(
			array( 'name' => 'test.pdf', 'size' => 100, 'tmp_name' => '/tmp/x', 'error' => UPLOAD_ERR_PARTIAL ),
			1,
			'enc-dek',
			'dek-iv'
		);
	}

	// ──────────────────────────────────────────────────
	// File size validation (SEC-FILE-03)
	// ──────────────────────────────────────────────────

	/**
	 * @test
	 * @security-relevant SEC-FILE-03 — File size limit enforced
	 */
	public function test_handle_upload_throws_when_file_exceeds_max_size(): void {
		Functions\when( 'size_format' )->justReturn( '5 MB' );

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'File size exceeds' );

		$this->handler->handle_upload(
			array( 'name' => 'big.pdf', 'size' => 10 * 1024 * 1024, 'tmp_name' => '/tmp/x', 'error' => UPLOAD_ERR_OK ),
			1,
			'enc-dek',
			'dek-iv'
		);
	}

	/**
	 * @test
	 * @security-relevant SEC-FILE-03 — Missing size treated as oversized
	 */
	public function test_handle_upload_throws_when_size_key_missing(): void {
		Functions\when( 'size_format' )->justReturn( '5 MB' );

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'File size exceeds' );

		$this->handler->handle_upload(
			array( 'name' => 'test.pdf', 'tmp_name' => '/tmp/x', 'error' => UPLOAD_ERR_OK ),
			1,
			'enc-dek',
			'dek-iv'
		);
	}

	/**
	 * @test
	 * @security-relevant SEC-FILE-03 — Hard limit 20 MB enforced even with larger custom max
	 */
	public function test_handle_upload_enforces_hard_max_size_limit(): void {
		Functions\when( 'size_format' )->justReturn( '20 MB' );

		// Custom max_size 50MB → capped to 20MB hard limit.
		// File is 25MB → exceeds 20MB.
		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'File size exceeds' );

		$this->handler->handle_upload(
			array( 'name' => 'huge.pdf', 'size' => 25 * 1024 * 1024, 'tmp_name' => '/tmp/x', 'error' => UPLOAD_ERR_OK ),
			1,
			'enc-dek',
			'dek-iv',
			array(),
			50 * 1024 * 1024
		);
	}

	// ──────────────────────────────────────────────────
	// Filename sanitization (SEC-FILE-04)
	// ──────────────────────────────────────────────────

	/**
	 * @test
	 * @security-relevant SEC-FILE-04 — Empty filename rejected
	 */
	public function test_handle_upload_throws_on_empty_sanitized_filename(): void {
		Functions\when( 'size_format' )->justReturn( '5 MB' );
		Functions\when( 'sanitize_file_name' )->justReturn( '' );

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'Invalid filename.' );

		$this->handler->handle_upload(
			array( 'name' => '...', 'size' => 100, 'tmp_name' => '/tmp/x', 'error' => UPLOAD_ERR_OK ),
			1,
			'enc-dek',
			'dek-iv'
		);
	}

	/**
	 * @test
	 * @security-relevant SEC-FILE-04 — Dangerous double extension (PHP) rejected
	 */
	public function test_handle_upload_throws_on_dangerous_php_double_extension(): void {
		Functions\when( 'size_format' )->justReturn( '5 MB' );
		Functions\when( 'sanitize_file_name' )->justReturn( 'malware.php.jpg' );

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'disallowed double extension' );

		$this->handler->handle_upload(
			array( 'name' => 'malware.php.jpg', 'size' => 100, 'tmp_name' => '/tmp/x', 'error' => UPLOAD_ERR_OK ),
			1,
			'enc-dek',
			'dek-iv'
		);
	}

	/**
	 * @test
	 * @security-relevant SEC-FILE-04 — Dangerous double extension (PHAR) rejected
	 */
	public function test_handle_upload_throws_on_dangerous_phar_double_extension(): void {
		Functions\when( 'size_format' )->justReturn( '5 MB' );
		Functions\when( 'sanitize_file_name' )->justReturn( 'payload.phar.pdf' );

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'disallowed double extension' );

		$this->handler->handle_upload(
			array( 'name' => 'payload.phar.pdf', 'size' => 100, 'tmp_name' => '/tmp/x', 'error' => UPLOAD_ERR_OK ),
			1,
			'enc-dek',
			'dek-iv'
		);
	}

	/**
	 * @test
	 * @security-relevant SEC-FILE-04 — Safe multi-dot filenames pass validation
	 */
	public function test_handle_upload_allows_safe_multi_dot_filename(): void {
		// Create a real PDF temp file to avoid finfo warnings.
		$tmp_file = $this->tmp_dir . '/safe-multidot.pdf';
		file_put_contents( $tmp_file, '%PDF-1.4 test' );

		Functions\when( 'size_format' )->justReturn( '5 MB' );
		Functions\when( 'sanitize_file_name' )->justReturn( 'file.backup.pdf' );
		Functions\expect( 'wp_check_filetype_and_ext' )
			->once()
			->andReturn( array( 'type' => 'application/pdf', 'ext' => 'pdf' ) );
		// We let it pass MIME check, then stop at wp_handle_upload.
		Functions\expect( 'wp_handle_upload' )
			->once()
			->andReturn( array( 'error' => 'test stop' ) );

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'Upload failed: test stop' );

		$this->handler->handle_upload(
			array( 'name' => 'file.backup.pdf', 'size' => 13, 'tmp_name' => $tmp_file, 'error' => UPLOAD_ERR_OK ),
			1,
			'enc-dek',
			'dek-iv'
		);
	}

	// ──────────────────────────────────────────────────
	// MIME type verification (SEC-FILE-05)
	// ──────────────────────────────────────────────────

	/**
	 * @test
	 * @security-relevant SEC-FILE-05 — WP file type check rejects disallowed type
	 */
	public function test_handle_upload_throws_when_mime_type_not_allowed(): void {
		Functions\when( 'size_format' )->justReturn( '5 MB' );
		Functions\when( 'sanitize_file_name' )->justReturn( 'virus.exe' );
		Functions\expect( 'wp_check_filetype_and_ext' )
			->once()
			->andReturn( array( 'type' => '', 'ext' => '' ) );

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'File type is not allowed' );

		$this->handler->handle_upload(
			array( 'name' => 'virus.exe', 'size' => 100, 'tmp_name' => '/tmp/x', 'error' => UPLOAD_ERR_OK ),
			1,
			'enc-dek',
			'dek-iv'
		);
	}

	/**
	 * @test
	 * @security-relevant SEC-FILE-05 — finfo detects MIME mismatch (JPEG content in PDF file)
	 */
	public function test_handle_upload_throws_when_finfo_detects_mime_mismatch(): void {
		// Create file with JPEG magic bytes but claiming to be a PDF.
		$tmp_file = $this->tmp_dir . '/fake.pdf';
		file_put_contents( $tmp_file, "\xFF\xD8\xFF\xE0\x00\x10JFIF\x00\x01\x01\x00" );

		Functions\when( 'size_format' )->justReturn( '5 MB' );
		Functions\when( 'sanitize_file_name' )->justReturn( 'fake.pdf' );
		// WP check says it's fine (spoofed).
		Functions\expect( 'wp_check_filetype_and_ext' )
			->once()
			->andReturn( array( 'type' => 'application/pdf', 'ext' => 'pdf' ) );

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'does not match its extension' );

		// Only allow PDF — finfo will detect JPEG.
		$this->handler->handle_upload(
			array( 'name' => 'fake.pdf', 'size' => 14, 'tmp_name' => $tmp_file, 'error' => UPLOAD_ERR_OK ),
			1,
			'enc-dek',
			'dek-iv',
			array( 'pdf' => 'application/pdf' ),
			5 * 1024 * 1024
		);
	}

	// ──────────────────────────────────────────────────
	// wp_handle_upload failure (SEC-FILE-01)
	// ──────────────────────────────────────────────────

	/**
	 * @test
	 * @security-relevant SEC-FILE-01 — wp_handle_upload error propagated
	 */
	public function test_handle_upload_throws_on_wp_upload_failure(): void {
		// Create a real PDF temp file to avoid finfo warnings.
		$tmp_file = $this->tmp_dir . '/upload-fail.pdf';
		file_put_contents( $tmp_file, '%PDF-1.4 test' );

		Functions\when( 'size_format' )->justReturn( '5 MB' );
		Functions\when( 'sanitize_file_name' )->justReturn( 'document.pdf' );
		Functions\expect( 'wp_check_filetype_and_ext' )
			->once()
			->andReturn( array( 'type' => 'application/pdf', 'ext' => 'pdf' ) );
		Functions\expect( 'wp_handle_upload' )
			->once()
			->andReturn( array( 'error' => 'Upload directory is not writable.' ) );

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'Upload failed: Upload directory is not writable.' );

		$this->handler->handle_upload(
			array( 'name' => 'document.pdf', 'size' => 13, 'tmp_name' => $tmp_file, 'error' => UPLOAD_ERR_OK ),
			1,
			'enc-dek',
			'dek-iv'
		);
	}

	// ──────────────────────────────────────────────────
	// Directory traversal prevention (SEC-FILE-11)
	// ──────────────────────────────────────────────────

	/**
	 * @test
	 * @security-relevant SEC-FILE-11 — Forward-slash directory traversal rejected
	 */
	public function test_delete_file_throws_on_directory_traversal(): void {
		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'directory traversal not allowed' );

		$this->handler->delete_file( '../../etc/passwd' );
	}

	/**
	 * @test
	 * @security-relevant SEC-FILE-11 — Backslash directory traversal rejected
	 */
	public function test_delete_file_throws_on_backslash_traversal(): void {
		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'directory traversal not allowed' );

		$this->handler->delete_file( '..\\..\\windows\\system32' );
	}

	/**
	 * @test
	 * @security-relevant SEC-FILE-11 — Traversal rejected in get_decrypted_file
	 */
	public function test_get_decrypted_file_throws_on_directory_traversal(): void {
		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'directory traversal not allowed' );

		$this->handler->get_decrypted_file(
			'../../../etc/shadow',
			'key',
			'dek',
			'iv'
		);
	}

	// ──────────────────────────────────────────────────
	// delete_file (SEC-FILE-09)
	// ──────────────────────────────────────────────────

	/**
	 * @test
	 * @security-relevant SEC-FILE-09 — Returns false for non-existent file
	 */
	public function test_delete_file_returns_false_for_nonexistent_file(): void {
		$this->mock_upload_dir();

		$result = $this->handler->delete_file( 'nonexistent/file.enc' );

		$this->assertFalse( $result );
	}

	/**
	 * @test
	 * @security-relevant SEC-FILE-09 — Encrypted file deleted and empty dirs cleaned up
	 */
	public function test_delete_file_removes_existing_file_and_cleans_empty_dirs(): void {
		$this->mock_upload_dir();

		// Create file structure: basedir/dsgvo-form-files/1/abc123/encrypted.enc.
		$base    = $this->tmp_dir . '/dsgvo-form-files';
		$sub_dir = $base . '/1/abc123';
		mkdir( $sub_dir, 0755, true );
		file_put_contents( $sub_dir . '/encrypted.enc', 'encrypted-data' );

		Functions\when( 'wp_delete_file' )->alias(
			function ( string $path ): void {
				if ( file_exists( $path ) ) {
					unlink( $path );
				}
			}
		);

		$result = $this->handler->delete_file( '1/abc123/encrypted.enc' );

		$this->assertTrue( $result );
		$this->assertFileDoesNotExist( $sub_dir . '/encrypted.enc' );
		// Empty parent directories should be cleaned up.
		$this->assertDirectoryDoesNotExist( $sub_dir );
		$this->assertDirectoryDoesNotExist( $base . '/1' );
		// Base dir itself should remain.
		$this->assertDirectoryExists( $base );
	}

	// ──────────────────────────────────────────────────
	// ensure_upload_directory (SEC-FILE-06, SEC-FILE-10)
	// ──────────────────────────────────────────────────

	/**
	 * @test
	 * @security-relevant SEC-FILE-06 + SEC-FILE-10 — .htaccess and index.php created
	 */
	public function test_ensure_upload_directory_creates_security_files(): void {
		$this->mock_upload_dir();

		Functions\when( 'wp_mkdir_p' )->alias(
			function ( string $dir ): bool {
				return mkdir( $dir, 0755, true );
			}
		);

		$base   = $this->tmp_dir . '/dsgvo-form-files';
		$result = $this->handler->ensure_upload_directory();

		$this->assertSame( $base, $result );
		$this->assertDirectoryExists( $base );

		// SEC-FILE-06: .htaccess denies all direct access.
		$this->assertFileExists( $base . '/.htaccess' );
		$htaccess = file_get_contents( $base . '/.htaccess' );
		$this->assertStringContainsString( 'Deny from all', $htaccess );

		// SEC-FILE-10: PHP execution disabled.
		$this->assertStringContainsString( 'php_flag engine off', $htaccess );

		// SEC-FILE-06: index.php prevents directory listing.
		$this->assertFileExists( $base . '/index.php' );
		$index = file_get_contents( $base . '/index.php' );
		$this->assertStringContainsString( 'Silence is golden', $index );
	}

	/**
	 * @test
	 */
	public function test_ensure_upload_directory_throws_on_mkdir_failure(): void {
		$this->mock_upload_dir();

		Functions\when( 'wp_mkdir_p' )->justReturn( false );

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'Failed to create upload directory' );

		$this->handler->ensure_upload_directory();
	}

	// ──────────────────────────────────────────────────
	// get_decrypted_file (SEC-FILE-08)
	// ──────────────────────────────────────────────────

	/**
	 * @test
	 * @security-relevant SEC-FILE-08 — File not found returns error
	 */
	public function test_get_decrypted_file_throws_on_missing_file(): void {
		$this->mock_upload_dir();

		$base = $this->tmp_dir . '/dsgvo-form-files';
		mkdir( $base, 0755, true );

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'not found or not readable' );

		$this->handler->get_decrypted_file(
			'1/nonexistent/file.enc',
			'key',
			'dek',
			'iv'
		);
	}

	/**
	 * @test
	 * @security-relevant SEC-FILE-08 — Corrupted (too short) encrypted blob rejected
	 */
	public function test_get_decrypted_file_throws_on_corrupted_blob(): void {
		$this->mock_upload_dir();

		$base    = $this->tmp_dir . '/dsgvo-form-files';
		$sub_dir = $base . '/1/hash';
		mkdir( $sub_dir, 0755, true );

		// Blob too short: needs > 28 bytes (12 IV + 16 tag + 1 ciphertext).
		file_put_contents( $sub_dir . '/corrupted.enc', str_repeat( "\x00", 10 ) );

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'too short' );

		$this->handler->get_decrypted_file(
			'1/hash/corrupted.enc',
			'key',
			'dek',
			'iv'
		);
	}

	/**
	 * @test
	 * @security-relevant SEC-FILE-08 — Happy path: encrypted file decrypted correctly
	 */
	public function test_get_decrypted_file_happy_path(): void {
		$this->mock_upload_dir();

		$base    = $this->tmp_dir . '/dsgvo-form-files';
		$sub_dir = $base . '/1/hash123';
		mkdir( $sub_dir, 0755, true );

		// Pack: ciphertext + IV (12 bytes) + tag (16 bytes).
		$ciphertext = 'encrypted-content-bytes-here';
		$iv         = str_repeat( "\x01", 12 );
		$tag        = str_repeat( "\x02", 16 );
		$blob       = $ciphertext . $iv . $tag;

		file_put_contents( $sub_dir . '/file.enc', $blob );

		$this->encryption->shouldReceive( 'decrypt_file' )
			->once()
			->with(
				base64_encode( $ciphertext ),
				base64_encode( $iv ),
				base64_encode( $tag ),
				'file-key-b64',
				'form-dek-b64',
				'dek-iv-b64'
			)
			->andReturn( 'Decrypted document content' );

		$result = $this->handler->get_decrypted_file(
			'1/hash123/file.enc',
			'file-key-b64',
			'form-dek-b64',
			'dek-iv-b64'
		);

		$this->assertSame( 'Decrypted document content', $result );
	}

	// ──────────────────────────────────────────────────
	// Full upload happy path (SEC-FILE-01, SEC-FILE-07)
	// ──────────────────────────────────────────────────

	/**
	 * @test
	 * @security-relevant SEC-FILE-01 + SEC-FILE-07 — Full upload with encryption
	 */
	public function test_handle_upload_happy_path_returns_file_metadata(): void {
		$this->mock_upload_dir();

		// Create a real temp file with JPEG magic bytes (finfo detects image/jpeg).
		$tmp_uploaded = $this->tmp_dir . '/wp-upload-tmp.jpg';
		$jpeg_content = "\xFF\xD8\xFF\xE0\x00\x10JFIF\x00\x01\x01\x00\x00\x01\x00\x01\x00\x00";
		file_put_contents( $tmp_uploaded, $jpeg_content );

		Functions\when( 'size_format' )->justReturn( '5 MB' );
		Functions\when( 'sanitize_file_name' )->justReturn( 'photo.jpg' );

		Functions\expect( 'wp_check_filetype_and_ext' )
			->once()
			->andReturn( array( 'type' => 'image/jpeg', 'ext' => 'jpg' ) );

		Functions\expect( 'wp_handle_upload' )
			->once()
			->andReturn(
				array(
					'file' => $tmp_uploaded,
					'url'  => 'http://example.com/wp-content/uploads/photo.jpg',
					'type' => 'image/jpeg',
				)
			);

		$deleted_files = array();
		Functions\when( 'wp_delete_file' )->alias(
			function ( string $path ) use ( &$deleted_files ): void {
				$deleted_files[] = $path;
				if ( file_exists( $path ) ) {
					unlink( $path );
				}
			}
		);

		Functions\when( 'wp_mkdir_p' )->alias(
			function ( string $dir ): bool {
				if ( ! is_dir( $dir ) ) {
					mkdir( $dir, 0755, true );
				}
				return true;
			}
		);

		Functions\when( 'wp_generate_password' )->justReturn( 'abcdef1234567890' );

		$this->encryption->shouldReceive( 'encrypt_file' )
			->once()
			->with( $jpeg_content, 'enc-dek-b64', 'dek-iv-b64' )
			->andReturn(
				array(
					'encrypted_content' => base64_encode( 'ciphertext-data' ),
					'iv'                => base64_encode( str_repeat( "\x01", 12 ) ),
					'tag'               => base64_encode( str_repeat( "\x02", 16 ) ),
					'encrypted_key'     => 'file-encrypted-key-b64',
				)
			);

		$result = $this->handler->handle_upload(
			array(
				'name'     => 'photo.jpg',
				'type'     => 'image/jpeg',
				'tmp_name' => $tmp_uploaded,
				'error'    => UPLOAD_ERR_OK,
				'size'     => strlen( $jpeg_content ),
			),
			1,
			'enc-dek-b64',
			'dek-iv-b64'
		);

		// Verify return structure.
		$this->assertArrayHasKey( 'file_path', $result );
		$this->assertArrayHasKey( 'original_name', $result );
		$this->assertArrayHasKey( 'mime_type', $result );
		$this->assertArrayHasKey( 'file_size', $result );
		$this->assertArrayHasKey( 'encrypted_key', $result );

		$this->assertSame( 'photo.jpg', $result['original_name'] );
		$this->assertSame( 'image/jpeg', $result['mime_type'] );
		$this->assertSame( strlen( $jpeg_content ), $result['file_size'] );
		$this->assertSame( 'file-encrypted-key-b64', $result['encrypted_key'] );

		// Verify temp plaintext file was cleaned up (SEC-FILE-07).
		$this->assertContains( $tmp_uploaded, $deleted_files );

		// Verify encrypted file was written to disk.
		$base = $this->tmp_dir . '/dsgvo-form-files';
		$this->assertDirectoryExists( $base );
		$encrypted_path = $base . '/' . $result['file_path'];
		$this->assertFileExists( $encrypted_path );
	}

	/**
	 * @test
	 * @security-relevant SEC-FILE-07 — Temp plaintext file deleted even on encryption failure
	 */
	public function test_handle_upload_deletes_temp_file_on_encryption_failure(): void {
		// Create real temp file with JPEG content.
		$tmp_uploaded = $this->tmp_dir . '/wp-upload-fail.jpg';
		$jpeg_content = "\xFF\xD8\xFF\xE0\x00\x10JFIF\x00\x01\x01\x00\x00\x01\x00\x01\x00\x00";
		file_put_contents( $tmp_uploaded, $jpeg_content );

		Functions\when( 'size_format' )->justReturn( '5 MB' );
		Functions\when( 'sanitize_file_name' )->justReturn( 'photo.jpg' );

		Functions\expect( 'wp_check_filetype_and_ext' )
			->andReturn( array( 'type' => 'image/jpeg', 'ext' => 'jpg' ) );

		Functions\expect( 'wp_handle_upload' )
			->andReturn(
				array(
					'file' => $tmp_uploaded,
					'url'  => 'http://example.com/uploads/photo.jpg',
					'type' => 'image/jpeg',
				)
			);

		$deleted_files = array();
		Functions\when( 'wp_delete_file' )->alias(
			function ( string $path ) use ( &$deleted_files ): void {
				$deleted_files[] = $path;
				if ( file_exists( $path ) ) {
					unlink( $path );
				}
			}
		);

		$this->encryption->shouldReceive( 'encrypt_file' )
			->once()
			->andThrow( new \RuntimeException( 'Encryption failed' ) );

		try {
			$this->handler->handle_upload(
				array(
					'name'     => 'photo.jpg',
					'type'     => 'image/jpeg',
					'tmp_name' => $tmp_uploaded,
					'error'    => UPLOAD_ERR_OK,
					'size'     => strlen( $jpeg_content ),
				),
				1,
				'dek',
				'iv'
			);
			$this->fail( 'Expected RuntimeException' );
		} catch ( \RuntimeException $e ) {
			$this->assertSame( 'Encryption failed', $e->getMessage() );
		}

		// Temp file MUST be deleted even on failure (finally block).
		$this->assertContains(
			$tmp_uploaded,
			$deleted_files,
			'Temp plaintext file must be deleted even when encryption fails.'
		);
	}
}
