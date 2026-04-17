<?php
/**
 * File upload handler.
 *
 * Validates, encrypts, and stores uploaded files in a protected directory.
 *
 * @package WpDsgvoForm
 */

declare(strict_types=1);

namespace WpDsgvoForm\Upload;

defined('ABSPATH') || exit;

use WpDsgvoForm\Encryption\EncryptionService;

/**
 * Handles file uploads for form submissions.
 *
 * Validates, encrypts, and stores uploaded files in a protected directory.
 * Downloads are served through an authenticated PHP endpoint — no direct
 * file access is possible.
 *
 * Security requirements: SEC-FILE-01 through SEC-FILE-10.
 */
class FileHandler
{

	/**
	 * Default allowed MIME types (SEC-FILE-02).
	 */
	private const DEFAULT_ALLOWED_MIMES = [
		'pdf'  => 'application/pdf',
		'jpg'  => 'image/jpeg',
		'jpeg' => 'image/jpeg',
		'png'  => 'image/png',
	];

	/**
	 * Default max file size in bytes: 5 MB (SEC-FILE-03).
	 */
	private const DEFAULT_MAX_SIZE = 5 * 1024 * 1024;

	/**
	 * Hard limit max file size in bytes: 20 MB (SEC-FILE-03).
	 */
	private const HARD_MAX_SIZE = 20 * 1024 * 1024;

	/**
	 * Upload base directory name inside wp-content/uploads/.
	 */
	private const UPLOAD_DIR_NAME = 'dsgvo-form-files';

	private EncryptionService $encryption;

	/**
	 * @param EncryptionService $encryption Encryption service for file encryption.
	 */
	public function __construct(EncryptionService $encryption)
	{
		$this->encryption = $encryption;
	}

	/**
	 * Processes a single uploaded file for a form submission.
	 *
	 * Validates the file (MIME, size, name), encrypts it with a per-file DEK,
	 * and stores it in the protected upload directory.
	 *
	 * SEC-FILE-01: Uses wp_handle_upload() for the initial upload.
	 * SEC-FILE-07: Encrypts file before final storage.
	 *
	 * @param array  $file                      $_FILES entry for this upload.
	 * @param int    $form_id                    The form ID (for directory structure).
	 * @param string $encrypted_form_dek_base64  Base64-encoded encrypted form DEK.
	 * @param string $form_dek_iv_base64         Base64-encoded form DEK IV.
	 * @param array  $allowed_mimes              Allowed MIME types (default: pdf, jpg, jpeg, png).
	 * @param int    $max_size                   Max file size in bytes (default: 5 MB).
	 * @return array{
	 *     file_path: string,
	 *     original_name: string,
	 *     mime_type: string,
	 *     file_size: int,
	 *     encrypted_key: string
	 * } File metadata for DB storage.
	 * @throws \RuntimeException If validation or encryption fails.
	 */
	public function handle_upload(
		array $file,
		int $form_id,
		string $encrypted_form_dek_base64,
		string $form_dek_iv_base64,
		array $allowed_mimes = [],
		int $max_size = 0
	): array {
		if (empty($allowed_mimes)) {
			$allowed_mimes = self::DEFAULT_ALLOWED_MIMES;
		}

		if ($max_size <= 0) {
			$max_size = self::DEFAULT_MAX_SIZE;
		}

		// SEC-FILE-03: Enforce hard limit.
		$max_size = min($max_size, self::HARD_MAX_SIZE);

		// Validate upload errors.
		$this->validate_upload_error($file);

		// SEC-FILE-03: Check file size.
		$this->validate_file_size($file, $max_size);

		// SEC-FILE-04: Sanitize and validate filename.
		$original_name = $this->sanitize_filename($file['name']);

		// SEC-FILE-05: Verify MIME type server-side.
		$mime_type = $this->verify_mime_type($file['tmp_name'], $original_name, $allowed_mimes);

		// SEC-FILE-01: Use wp_handle_upload() for initial processing.
		$uploaded = $this->wp_upload($file);

		try {
			// Read the uploaded file content.
			$file_contents = file_get_contents($uploaded['file']);

			if ($file_contents === false) {
				throw new \RuntimeException('Failed to read uploaded file.');
			}

			$file_size = strlen($file_contents);

			// SEC-FILE-07: Encrypt the file content.
			$encrypted = $this->encryption->encrypt_file(
				$file_contents,
				$encrypted_form_dek_base64,
				$form_dek_iv_base64
			);

			// Pack encrypted components for disk storage.
			$encrypted_blob = $this->pack_encrypted_blob($encrypted);

			// Store encrypted file in protected directory.
			$storage_path = $this->store_encrypted_file(
				$encrypted_blob,
				$form_id
			);
		} finally {
			// Always delete the temporary plaintext file, even on exception.
			wp_delete_file($uploaded['file']);
		}

		return [
			'file_path'     => $storage_path,
			'original_name' => $original_name,
			'mime_type'     => $mime_type,
			'file_size'     => $file_size,
			'encrypted_key' => $encrypted['encrypted_key'],
		];
	}

	/**
	 * Decrypts and returns file content for authenticated download.
	 *
	 * SEC-FILE-08: This method is called by the download endpoint after
	 * verifying user permissions and nonce.
	 *
	 * @param string $file_path                  Relative path in the upload directory.
	 * @param string $encrypted_key_base64       Base64-encoded encrypted file-DEK.
	 * @param string $encrypted_form_dek_base64  Base64-encoded encrypted form DEK.
	 * @param string $form_dek_iv_base64         Base64-encoded form DEK IV.
	 * @return string Raw decrypted file contents.
	 * @throws \RuntimeException If file not found or decryption fails.
	 */
	public function get_decrypted_file(
		string $file_path,
		string $encrypted_key_base64,
		string $encrypted_form_dek_base64,
		string $form_dek_iv_base64
	): string {
		// SEC-FILE-11: Validate path to prevent directory traversal.
		$full_path = $this->validate_file_path($file_path);

		if (!file_exists($full_path) || !is_readable($full_path)) {
			throw new \RuntimeException('Encrypted file not found or not readable.');
		}

		$encrypted_blob = file_get_contents($full_path);

		if ($encrypted_blob === false) {
			throw new \RuntimeException('Failed to read encrypted file.');
		}

		// Unpack: ciphertext + iv (12 bytes) + tag (16 bytes).
		$unpacked          = $this->unpack_encrypted_blob($encrypted_blob);
		$encrypted_content = $unpacked['encrypted_content'];
		$iv                = $unpacked['iv'];
		$tag               = $unpacked['tag'];

		return $this->encryption->decrypt_file(
			$encrypted_content,
			$iv,
			$tag,
			$encrypted_key_base64,
			$encrypted_form_dek_base64,
			$form_dek_iv_base64
		);
	}

	/**
	 * Deletes an encrypted file from the filesystem.
	 *
	 * SEC-FILE-09: Files must be deleted when submissions are deleted.
	 *
	 * @param string $file_path Relative path in the upload directory.
	 * @return bool True if deleted, false if file did not exist.
	 */
	public function delete_file(string $file_path): bool
	{
		// SEC-FILE-11: Validate path to prevent directory traversal.
		$full_path = $this->validate_file_path($file_path);

		if (!file_exists($full_path)) {
			return false;
		}

		wp_delete_file($full_path);

		// Clean up empty parent directories up to the base dir.
		$parent = dirname($full_path);
		$base   = $this->get_upload_base_dir();

		while ($parent !== $base && is_dir($parent) && $this->is_dir_empty($parent)) {
			rmdir($parent);
			$parent = dirname($parent);
		}

		return true;
	}

	/**
	 * Ensures the protected upload directory exists with security files.
	 *
	 * SEC-FILE-06: .htaccess with Deny from all.
	 * SEC-FILE-10: php_flag engine off.
	 *
	 * @return string Absolute path to the upload base directory.
	 * @throws \RuntimeException If directory cannot be created.
	 */
	public function ensure_upload_directory(): string
	{
		$base_dir = $this->get_upload_base_dir();

		if (!is_dir($base_dir)) {
			if (!wp_mkdir_p($base_dir)) {
				throw new \RuntimeException(
					'Failed to create upload directory: ' . $base_dir
				);
			}
		}

		// SEC-FILE-06 + SEC-FILE-10: .htaccess protection.
		$htaccess_path = $base_dir . '/.htaccess';
		if (!file_exists($htaccess_path)) {
			$htaccess_content = "# SEC-FILE-06: Deny all direct access\n"
				. "Order deny,allow\n"
				. "Deny from all\n"
				. "\n"
				. "# SEC-FILE-10: Disable PHP execution\n"
				. "<IfModule mod_php.c>\n"
				. "  php_flag engine off\n"
				. "</IfModule>\n";

			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			file_put_contents($htaccess_path, $htaccess_content);
		}

		// SEC-FILE-06: index.php to prevent directory listing.
		$index_path = $base_dir . '/index.php';
		if (!file_exists($index_path)) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			file_put_contents($index_path, "<?php\n// Silence is golden.\n");
		}

		return $base_dir;
	}

	/**
	 * Returns the absolute base path for encrypted file uploads.
	 *
	 * @return string Absolute path to wp-content/uploads/dsgvo-form-files/.
	 */
	private function get_upload_base_dir(): string
	{
		$upload_dir = wp_upload_dir();

		return $upload_dir['basedir'] . '/' . self::UPLOAD_DIR_NAME;
	}

	/**
	 * Validates a relative file path against directory traversal attacks.
	 *
	 * Defense in Depth: Even though paths are generated internally,
	 * a compromised database could inject traversal sequences.
	 *
	 * @param string $file_path Relative path within the upload directory.
	 * @return string Validated absolute path.
	 * @throws \RuntimeException If path traversal is detected.
	 */
	private function validate_file_path(string $file_path): string
	{
		// Reject suspicious patterns in the input.
		if (preg_match('/\.\.[\/\\\\]/', $file_path)) {
			throw new \RuntimeException(
				'Invalid file path: directory traversal not allowed.'
			);
		}

		$base_dir  = $this->get_upload_base_dir();
		$full_path = $base_dir . '/' . $file_path;

		// For existing files: verify resolved path is within the base directory.
		$realpath = realpath($full_path);

		if ($realpath !== false) {
			$real_base = realpath($base_dir);

			if ($real_base === false || strpos($realpath, $real_base . DIRECTORY_SEPARATOR) !== 0) {
				throw new \RuntimeException(
					'Invalid file path: path traversal detected.'
				);
			}
		}

		return $full_path;
	}

	/**
	 * Packs encrypted file components into a single binary blob for disk storage.
	 *
	 * Format: ciphertext + iv (12 bytes) + tag (16 bytes).
	 * Matches the format used by FileEncryptor::pack_for_storage().
	 *
	 * @param array{encrypted_content: string, iv: string, tag: string} $encrypted
	 *     Base64-encoded encryption result from EncryptionService::encrypt_file().
	 * @return string Raw binary blob.
	 * @throws \RuntimeException If base64 decoding fails.
	 */
	private function pack_encrypted_blob(array $encrypted): string
	{
		$content = base64_decode($encrypted['encrypted_content'], true);
		$iv      = base64_decode($encrypted['iv'], true);
		$tag     = base64_decode($encrypted['tag'], true);

		if ($content === false || $iv === false || $tag === false) {
			throw new \RuntimeException('Invalid base64 encoding in encrypted file data.');
		}

		return $content . $iv . $tag;
	}

	/**
	 * Unpacks a binary blob from disk into base64-encoded components.
	 *
	 * Reverses pack_encrypted_blob(). Format: ciphertext + iv (12) + tag (16).
	 *
	 * @param string $blob Raw binary blob from disk.
	 * @return array{encrypted_content: string, iv: string, tag: string} Base64-encoded.
	 * @throws \RuntimeException If blob is too short.
	 */
	private function unpack_encrypted_blob(string $blob): array
	{
		$iv_length  = 12;
		$tag_length = 16;
		$blob_len   = strlen($blob);
		$min_length = $iv_length + $tag_length + 1;

		if ($blob_len < $min_length) {
			throw new \RuntimeException('Encrypted file data is too short.');
		}

		$ciphertext_len = $blob_len - $iv_length - $tag_length;

		return [
			'encrypted_content' => base64_encode(substr($blob, 0, $ciphertext_len)),
			'iv'                => base64_encode(substr($blob, $ciphertext_len, $iv_length)),
			'tag'               => base64_encode(substr($blob, $ciphertext_len + $iv_length, $tag_length)),
		];
	}

	/**
	 * Validates that the upload did not encounter PHP-level errors.
	 *
	 * @param array $file $_FILES entry.
	 * @throws \RuntimeException If an upload error occurred.
	 */
	private function validate_upload_error(array $file): void
	{
		if (!isset($file['error'])) {
			throw new \RuntimeException('Invalid file upload data.');
		}

		if ($file['error'] !== UPLOAD_ERR_OK) {
			$messages = [
				UPLOAD_ERR_INI_SIZE   => 'File exceeds server upload limit.',
				UPLOAD_ERR_FORM_SIZE  => 'File exceeds form upload limit.',
				UPLOAD_ERR_PARTIAL    => 'File was only partially uploaded.',
				UPLOAD_ERR_NO_FILE    => 'No file was uploaded.',
				UPLOAD_ERR_NO_TMP_DIR => 'Server missing temporary directory.',
				UPLOAD_ERR_CANT_WRITE => 'Failed to write file to disk.',
				UPLOAD_ERR_EXTENSION  => 'Upload stopped by server extension.',
			];

			$message = $messages[$file['error']] ?? 'Unknown upload error.';
			throw new \RuntimeException('Upload error: ' . $message);
		}
	}

	/**
	 * Validates file size against the configured maximum.
	 *
	 * SEC-FILE-03: Enforce configurable max size with hard limit.
	 *
	 * @param array $file     $_FILES entry.
	 * @param int   $max_size Maximum allowed size in bytes.
	 * @throws \RuntimeException If file exceeds the limit.
	 */
	private function validate_file_size(array $file, int $max_size): void
	{
		if (!isset($file['size']) || $file['size'] > $max_size) {
			throw new \RuntimeException(
				sprintf(
					'File size exceeds the maximum allowed size of %s.',
					size_format($max_size)
				)
			);
		}
	}

	/**
	 * Sanitizes and validates the uploaded filename.
	 *
	 * SEC-FILE-04: Uses sanitize_file_name() and rejects double extensions.
	 *
	 * @param string $filename Original filename.
	 * @return string Sanitized filename.
	 * @throws \RuntimeException If filename contains double extension.
	 */
	private function sanitize_filename(string $filename): string
	{
		$sanitized = sanitize_file_name($filename);

		if (empty($sanitized)) {
			throw new \RuntimeException('Invalid filename.');
		}

		// SEC-FILE-04: Reject double extensions (e.g. file.php.jpg).
		$parts = explode('.', $sanitized);

		if (count($parts) > 2) {
			// Check if any intermediate part is an executable extension.
			$dangerous_extensions = [
				'php', 'phtml', 'php3', 'php4', 'php5', 'php7', 'phps',
				'pht', 'phar', 'cgi', 'pl', 'py', 'asp', 'aspx', 'jsp',
				'sh', 'bash', 'exe', 'bat', 'cmd', 'com', 'vbs', 'js',
				'shtml', 'shtm',
			];

			// Check all parts except the last one (the actual extension).
			$intermediate = array_slice($parts, 1, -1);

			foreach ($intermediate as $part) {
				if (in_array(strtolower($part), $dangerous_extensions, true)) {
					throw new \RuntimeException(
						'Filename contains a disallowed double extension.'
					);
				}
			}
		}

		return $sanitized;
	}

	/**
	 * Verifies the MIME type of an uploaded file server-side.
	 *
	 * SEC-FILE-05: Uses wp_check_filetype_and_ext() AND finfo_file()
	 * for real MIME type detection.
	 *
	 * @param string $tmp_path       Temporary file path.
	 * @param string $filename       Sanitized filename.
	 * @param array  $allowed_mimes  Map of extension => MIME type.
	 * @return string Verified MIME type.
	 * @throws \RuntimeException If MIME type is not allowed.
	 */
	private function verify_mime_type(
		string $tmp_path,
		string $filename,
		array $allowed_mimes
	): string {
		// WordPress-level check.
		$wp_check = wp_check_filetype_and_ext($tmp_path, $filename, $allowed_mimes);

		if (empty($wp_check['type'])) {
			throw new \RuntimeException(
				'File type is not allowed. Permitted types: '
				. implode(', ', array_keys($allowed_mimes)) . '.'
			);
		}

		// SEC-FILE-05: Additional finfo_file() check for real MIME detection.
		if (function_exists('finfo_file')) {
			$finfo     = finfo_open(FILEINFO_MIME_TYPE);
			$real_mime = finfo_file($finfo, $tmp_path);
			finfo_close($finfo);

			if ($real_mime !== false && !in_array($real_mime, $allowed_mimes, true)) {
				throw new \RuntimeException(
					'File content does not match its extension. '
					. 'Detected type: ' . $real_mime . '.'
				);
			}
		}

		return $wp_check['type'];
	}

	/**
	 * Performs the initial upload via WordPress upload handling.
	 *
	 * SEC-FILE-01: Uses wp_handle_upload(), NOT move_uploaded_file().
	 *
	 * @param array $file $_FILES entry.
	 * @return array{file: string, url: string, type: string} Upload result.
	 * @throws \RuntimeException If upload fails.
	 */
	private function wp_upload(array $file): array
	{
		// Prevent WordPress from running default upload actions.
		$overrides = [
			'test_form' => false,
			'test_type' => false,
		];

		$result = wp_handle_upload($file, $overrides);

		if (isset($result['error'])) {
			throw new \RuntimeException('Upload failed: ' . $result['error']);
		}

		return $result;
	}

	/**
	 * Stores an encrypted file blob in the protected upload directory.
	 *
	 * @param string $encrypted_blob Raw encrypted file content.
	 * @param int    $form_id        Form ID for directory structure.
	 * @return string Relative path from upload base directory.
	 * @throws \RuntimeException If file cannot be stored.
	 */
	private function store_encrypted_file(string $encrypted_blob, int $form_id): string
	{
		$base_dir = $this->ensure_upload_directory();

		// Create form-specific subdirectory with random hash.
		$hash    = wp_generate_password(16, false);
		$sub_dir = $form_id . '/' . $hash;
		$dir     = $base_dir . '/' . $sub_dir;

		if (!wp_mkdir_p($dir)) {
			throw new \RuntimeException('Failed to create file storage directory.');
		}

		// Use a generic encrypted filename (no original name leak).
		$filename = wp_generate_password(32, false) . '.enc';
		$filepath = $dir . '/' . $filename;

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		$written = file_put_contents($filepath, $encrypted_blob);

		if ($written === false) {
			throw new \RuntimeException('Failed to write encrypted file to disk.');
		}

		// Return path relative to upload base directory.
		return $sub_dir . '/' . $filename;
	}

	/**
	 * Checks whether a directory is empty.
	 *
	 * @param string $dir Directory path.
	 * @return bool True if empty.
	 */
	private function is_dir_empty(string $dir): bool
	{
		$handle = opendir($dir);

		if ($handle === false) {
			return false;
		}

		while (($entry = readdir($handle)) !== false) {
			if ($entry !== '.' && $entry !== '..') {
				closedir($handle);
				return false;
			}
		}

		closedir($handle);
		return true;
	}
}
