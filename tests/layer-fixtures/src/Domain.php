<?php
/**
 * Domain layer fixtures.
 */

namespace Acme\Shop\Domain\Enums {
	enum Status: string {
		case Open   = 'open';
		case Closed = 'closed';
	}
}

namespace Acme\Shop\Domain {
	// GOOD: framework domain base class and a PHP class only.
	final class Order extends \TangibleDDD\Domain\Shared\Aggregate {
		public function __construct( private \DateTimeImmutable $placed_at, public Enums\Status $status ) {
		}
	}
}

namespace Acme\Shop\Domain\Repositories {
	use Acme\Shop\Domain\Order;

	interface IOrderRepository {
		// GOOD: domain types.
		public function get( int $id ): ?Order;

		/**
		 * BAD (DDD-L1): a persistence record named only in a PHPDoc generic.
		 *
		 * @return list<\Acme\Shop\Infra\OrderRecord>
		 */
		public function records(): array;
	}
}

namespace Acme\Shop\Domain\Services {
	final class Pricing {
		// BAD (DDD-L1): a WordPress class in the domain.
		public function for_post( \WP_Post $post ): int {
			return 0;
		}
	}
}
