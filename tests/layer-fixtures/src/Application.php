<?php
/**
 * Application layer fixtures.
 */

namespace Acme\Shop\Application\Dto {
	final class OrderView {
		public function __construct( public int $id ) {
		}
	}
}

namespace Acme\Shop\Application {
	use Acme\Shop\Application\Dto\OrderView;
	use Acme\Shop\Domain\Repositories\IOrderRepository;

	// GOOD: domain repository in, application read model out.
	final class GetOrderHandler {
		public function __construct( private IOrderRepository $orders ) {
		}

		public function handle( int $id ): ?OrderView {
			return $this->orders->get( $id ) === null ? null : new OrderView( $id );
		}
	}

	final class PlaceOrderHandler {
		public function handle(): void {
			// BAD (DDD-L4): infrastructure, written inline without a `use`.
			$record = new \Acme\Shop\Infra\OrderRecord();
		}
	}

	final class BadQueryHandler {
		// BAD (DDD-L4): returns an adapter's response type.
		public function handle(): \Acme\Shop\Api\OrderResponse {
			return new \Acme\Shop\Api\OrderResponse();
		}
	}
}
