<?php

declare( strict_types=1 );

namespace Fossibot\ValueObjects;

/**
 * Request for fetching device list from Fossibot API.
 */
final class DeviceListRequest {

	private const DEFAULT_PAGE_SIZE = 100;

	public function __construct(
		public readonly string $locale = 'en',
		public readonly int $pageIndex = 1,
		public readonly int $pageSize = self::DEFAULT_PAGE_SIZE
	) {}

	/**
	 * Generate function arguments for API request.
	 */
	public function toFunctionArgs( DeviceInfo $clientInfo, string $accessToken ): array {
		return [
			'$url' => 'client/device/kh/getList',
			'data' => [
				'locale' => $this->locale,
				'pageIndex' => $this->pageIndex,
				'pageSize' => $this->pageSize,
			],
			'clientInfo' => $clientInfo->toArray(),
			'uniIdToken' => $accessToken,
		];
	}
}