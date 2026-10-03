<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- Custom tables; caching would hide real-time delivery changes; schema changes are intentional in install().

/**
 * Database layer for delivery methods and the delivery-order view.
 *
 * New table: me_p_delivery_methods
 * Adds columns delivery_method_id, delivery_method_name, delivery_status to
 * the existing me_p_orders table (idempotent ALTER, version-gated in install()).
 */
class PCIO_VIS_Products_Delivery_DB {

	const TABLE = 'me_p_delivery_methods';

	const DELIVERY_STATUSES = [ 'pending', 'delivered' ];

	public static function t(): string {
		global $wpdb;
		return $wpdb->prefix . self::TABLE;
	}

	// ── Schema ────────────────────────────────────────────────────

	/**
	 * Creates the delivery-methods table and migrates me_p_orders.
	 * Called from PCIO_VIS_Products_DB::install() after the version gate.
	 */
	public static function install(): void {
		global $wpdb;

		$wpdb->query(
			$wpdb->prepare(
				"CREATE TABLE IF NOT EXISTS %i (
					id          INT NOT NULL AUTO_INCREMENT,
					name        VARCHAR(200) NOT NULL DEFAULT '',
					description VARCHAR(500) NOT NULL DEFAULT '',
					price_cents INT NOT NULL DEFAULT 0,
					sort        INT NOT NULL DEFAULT 0,
					active      TINYINT(1) NOT NULL DEFAULT 1,
					created     DATETIME DEFAULT CURRENT_TIMESTAMP,
					PRIMARY KEY  (id),
					KEY idx_dm_sort (sort)
				) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci",
				self::t()
			)
		);

		$orders = PCIO_VIS_Products_DB::t_orders();

		$wpdb->query(
			$wpdb->prepare(
				'ALTER TABLE %i ADD COLUMN IF NOT EXISTS delivery_method_id INT NOT NULL DEFAULT 0',
				$orders
			)
		);
		$wpdb->query(
			$wpdb->prepare(
				"ALTER TABLE %i ADD COLUMN IF NOT EXISTS delivery_method_name VARCHAR(200) NOT NULL DEFAULT ''",
				$orders
			)
		);
		$wpdb->query(
			$wpdb->prepare(
				"ALTER TABLE %i ADD COLUMN IF NOT EXISTS delivery_status VARCHAR(20) NOT NULL DEFAULT 'pending'",
				$orders
			)
		);
	}

	// ── Delivery methods CRUD ─────────────────────────────────────

	/** @return array<int,array<string,mixed>> */
	public static function get_all(): array {
		global $wpdb;
		return $wpdb->get_results(
			$wpdb->prepare( 'SELECT * FROM %i ORDER BY sort ASC, id ASC', self::t() ),
			ARRAY_A
		) ?: [];
	}

	/** @return array<int,array<string,mixed>> */
	public static function get_active(): array {
		global $wpdb;
		return $wpdb->get_results(
			$wpdb->prepare( 'SELECT * FROM %i WHERE active = 1 ORDER BY sort ASC, id ASC', self::t() ),
			ARRAY_A
		) ?: [];
	}

	public static function get( int $id ): ?array {
		global $wpdb;
		return $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', self::t(), $id ),
			ARRAY_A
		) ?: null;
	}

	public static function create( array $data ): int {
		global $wpdb;
		$clean    = self::sanitize( $data );
		$max_sort = (int) $wpdb->get_var(
			$wpdb->prepare( 'SELECT COALESCE(MAX(sort),0)+1 FROM %i', self::t() )
		);
		$clean['sort'] = $max_sort;
		$wpdb->insert( self::t(), $clean, self::formats( $clean ) );
		return (int) $wpdb->insert_id;
	}

	public static function update( int $id, array $data ): bool {
		global $wpdb;
		$clean = self::sanitize( $data );
		if ( ! $clean ) {
			return false;
		}
		return false !== $wpdb->update( self::t(), $clean, [ 'id' => $id ], self::formats( $clean ), [ '%d' ] );
	}

	public static function delete( int $id ): bool {
		global $wpdb;
		return false !== $wpdb->delete( self::t(), [ 'id' => $id ], [ '%d' ] );
	}

	// ── Delivery orders ───────────────────────────────────────────

	/**
	 * Orders that have a delivery method assigned, joined with customer names and
	 * member contact details (address, phone) from the core members table.
	 * Accepts optional filters: status (delivery_status), method_id.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public static function get_delivery_orders( array $args = [] ): array {
		global $wpdb;

		$status    = isset( $args['status'] )    ? sanitize_text_field( $args['status'] )  : '';
		$method_id = isset( $args['method_id'] ) ? absint( $args['method_id'] )            : 0;
		$members   = $wpdb->prefix . 'me_members';

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT o.id, o.order_no, o.email, o.created, o.amount_cents, o.currency,
				        o.delivery_method_id, o.delivery_method_name, o.delivery_status,
				        o.summary, o.status AS payment_status,
				        c.name AS customer_name,
				        m.address AS member_address,
				        m.phone   AS member_phone
				 FROM %i o
				 LEFT JOIN %i c ON c.id = o.customer_id
				 LEFT JOIN %i m ON m.email = o.email
				 WHERE o.delivery_method_id > 0
				   AND ( %s = \'\' OR o.delivery_status = %s )
				   AND ( %d = 0 OR o.delivery_method_id = %d )
				 ORDER BY o.id DESC',
				PCIO_VIS_Products_DB::t_orders(),
				PCIO_VIS_Products_DB::t_customers(),
				$members,
				$status,
				$status,
				$method_id,
				$method_id
			),
			ARRAY_A
		) ?: [];

		return array_map( [ self::class, 'shape_delivery_order' ], $rows );
	}

	/**
	 * Look up address and phone for a buyer email from the core members table.
	 *
	 * @return array{address:string,phone:string}|null
	 */
	public static function get_member_contact_by_email( string $email ): ?array {
		if ( ! class_exists( 'PCIO_VIS_DB' ) ) {
			return null;
		}
		global $wpdb;
		$row = $wpdb->get_row(
			$wpdb->prepare( 'SELECT address, phone FROM %i WHERE email = %s LIMIT 1', $wpdb->prefix . 'me_members', $email ),
			ARRAY_A
		);
		return $row ? [
			'address' => (string) ( $row['address'] ?? '' ),
			'phone'   => (string) ( $row['phone'] ?? '' ),
		] : null;
	}

	public static function update_delivery_status( int $order_id, string $status ): bool {
		global $wpdb;
		if ( ! in_array( $status, self::DELIVERY_STATUSES, true ) ) {
			return false;
		}
		return false !== $wpdb->update(
			PCIO_VIS_Products_DB::t_orders(),
			[ 'delivery_status' => $status ],
			[ 'id' => $order_id ],
			[ '%s' ],
			[ '%d' ]
		);
	}

	// ── Internal ──────────────────────────────────────────────────

	private static function shape_delivery_order( array $row ): array {
		return [
			'id'                   => (int) $row['id'],
			'order_no'             => (string) $row['order_no'],
			'email'                => (string) $row['email'],
			'customer_name'        => (string) ( $row['customer_name'] ?? '' ),
			'member_address'       => (string) ( $row['member_address'] ?? '' ),
			'member_phone'         => (string) ( $row['member_phone'] ?? '' ),
			'created'              => (string) $row['created'],
			'amount_cents'         => (int) $row['amount_cents'],
			'currency'             => (string) $row['currency'],
			'delivery_method_id'   => (int) $row['delivery_method_id'],
			'delivery_method_name' => (string) $row['delivery_method_name'],
			'delivery_status'      => (string) $row['delivery_status'],
			'summary'              => (string) $row['summary'],
			'payment_status'       => (string) $row['payment_status'],
		];
	}

	private static function sanitize( array $data ): array {
		$out = [];
		if ( array_key_exists( 'name', $data ) ) {
			$out['name'] = substr( sanitize_text_field( $data['name'] ), 0, 200 );
		}
		if ( array_key_exists( 'description', $data ) ) {
			$out['description'] = substr( sanitize_text_field( $data['description'] ), 0, 500 );
		}
		if ( array_key_exists( 'price_cents', $data ) ) {
			$out['price_cents'] = max( 0, (int) $data['price_cents'] );
		}
		if ( array_key_exists( 'sort', $data ) ) {
			$out['sort'] = max( 0, (int) $data['sort'] );
		}
		if ( array_key_exists( 'active', $data ) ) {
			$out['active'] = (int) (bool) $data['active'];
		}
		return $out;
	}

	private static function formats( array $clean ): array {
		$formats = [];
		foreach ( array_keys( $clean ) as $field ) {
			$formats[] = in_array( $field, [ 'name', 'description' ], true ) ? '%s' : '%d';
		}
		return $formats;
	}
}
