<?php
/**
 * Minimal WordPress class stubs for unit testing.
 *
 * Provides bare-minimum implementations of WP_Error, WP_REST_Request,
 * WP_REST_Response, and WP_REST_Server so that tests can instantiate
 * and inspect these objects without loading the full WordPress core.
 *
 * @package WpDsgvoForm\Tests\Stubs
 */

declare(strict_types=1);

if ( ! class_exists( 'WP_Error' ) ) {
	/**
	 * Minimal WP_Error stub.
	 */
	class WP_Error {
		private string $code;
		private string $message;
		private mixed $data;

		public function __construct( string $code = '', string $message = '', mixed $data = '' ) {
			$this->code    = $code;
			$this->message = $message;
			$this->data    = $data;
		}

		public function get_error_code(): string {
			return $this->code;
		}

		public function get_error_message(): string {
			return $this->message;
		}

		public function get_error_data(): mixed {
			return $this->data;
		}
	}
}

if ( ! class_exists( 'WP_REST_Request' ) ) {
	/**
	 * Minimal WP_REST_Request stub.
	 */
	class WP_REST_Request {
		private array $params = [];

		public function __construct( string $method = 'GET', string $route = '' ) {}

		public function set_param( string $key, $value ): void {
			$this->params[ $key ] = $value;
		}

		public function get_param( string $key ) {
			return $this->params[ $key ] ?? null;
		}

		public function get_json_params(): array {
			return $this->params;
		}

		public function get_body_params(): array {
			return $this->params;
		}

		public function set_body( string $body ): void {
			$this->params = json_decode( $body, true ) ?: [];
		}
	}
}

if ( ! class_exists( 'WP_REST_Response' ) ) {
	/**
	 * Minimal WP_REST_Response stub.
	 */
	class WP_REST_Response {
		public array $data;
		public int $status;

		public function __construct( array $data = [], int $status = 200 ) {
			$this->data   = $data;
			$this->status = $status;
		}

		public function get_data(): array {
			return $this->data;
		}

		public function get_status(): int {
			return $this->status;
		}
	}
}

if ( ! class_exists( 'WP_REST_Server' ) ) {
	/**
	 * Minimal WP_REST_Server stub with HTTP method constants.
	 */
	class WP_REST_Server {
		const READABLE   = 'GET';
		const CREATABLE  = 'POST';
		const EDITABLE   = 'POST, PUT, PATCH';
		const DELETABLE  = 'DELETE';
		const ALLMETHODS  = 'GET, POST, PUT, PATCH, DELETE';
	}
}

// File upload constant stubs.
if ( ! defined( 'UPLOAD_ERR_OK' ) ) {
	define( 'UPLOAD_ERR_OK', 0 );
}
if ( ! defined( 'UPLOAD_ERR_NO_FILE' ) ) {
	define( 'UPLOAD_ERR_NO_FILE', 4 );
}

if ( ! class_exists( 'WP_Screen' ) ) {
	/**
	 * Minimal WP_Screen stub.
	 */
	class WP_Screen {
		public string $id = '';

		public static function get( string $id = '' ): self {
			$screen     = new self();
			$screen->id = $id;
			return $screen;
		}
	}
}

if ( ! class_exists( 'WP_User' ) ) {
	/**
	 * Minimal WP_User stub.
	 */
	class WP_User {
		public int $ID     = 0;
		public array $roles = [];

		public function __construct( int $id = 0 ) {
			$this->ID = $id;
		}
	}
}

if ( ! class_exists( 'WP_Admin_Bar' ) ) {
	/**
	 * Minimal WP_Admin_Bar stub.
	 */
	class WP_Admin_Bar {
		private array $nodes = [];

		public function add_node( array $args ): void {
			$this->nodes[ $args['id'] ?? '' ] = $args;
		}

		public function remove_node( string $id ): void {
			unset( $this->nodes[ $id ] );
		}

		public function get_node( string $id ) {
			return $this->nodes[ $id ] ?? null;
		}
	}
}

if ( ! class_exists( 'WP_List_Table' ) ) {
	/**
	 * Minimal WP_List_Table stub for unit testing SubmissionListTable.
	 */
	class WP_List_Table {

		protected $_column_headers;
		protected array $_pagination_args = [];
		public array $items               = [];

		public function __construct( $args = [] ) {}

		public function current_action() {
			if ( isset( $_REQUEST['filter_action'] ) && ! empty( $_REQUEST['filter_action'] ) ) {
				return false;
			}
			if ( isset( $_REQUEST['action'] ) && -1 != $_REQUEST['action'] ) { // phpcs:ignore
				return $_REQUEST['action'];
			}
			if ( isset( $_REQUEST['action2'] ) && -1 != $_REQUEST['action2'] ) { // phpcs:ignore
				return $_REQUEST['action2'];
			}
			return false;
		}

		public function get_pagenum() {
			return 1;
		}

		protected function set_pagination_args( $args ) {
			$this->_pagination_args = $args;
		}

		protected function row_actions( $actions, $always_visible = false ) {
			return '';
		}

		public function display() {}

		public function prepare_items() {}

		public function search_box( $text, $input_id ) {}

		protected function get_columns() {
			return [];
		}

		protected function get_sortable_columns() {
			return [];
		}

		protected function get_bulk_actions() {
			return [];
		}
	}
}
