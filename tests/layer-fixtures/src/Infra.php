<?php
/**
 * Infrastructure fixtures: may use every inner layer.
 */

namespace Acme\Shop\Infra {
	use Acme\Shop\Domain\Order;
	use Acme\Shop\Domain\Repositories\IOrderRepository;

	final class OrderRecord {
	}

	final class DbException extends \RuntimeException {
	}

	// GOOD: infrastructure implementing a domain contract.
	final class OrderRepository implements IOrderRepository {
		public function get( int $id ): ?Order {
			return null;
		}

		/** @return list<OrderRecord> */
		public function records(): array {
			return array();
		}
	}
}
