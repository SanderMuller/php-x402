<?php

declare(strict_types=1);

namespace X402\Facilitator;

/**
 * Query parameters for `GET /discovery/resources`.
 */
final readonly class DiscoveryQuery
{
    public function __construct(
        public ?string $type = null,
        public int $limit = 100,
        public int $offset = 0,
    ) {}

    /**
     * @return array<string, string>
     */
    public function toQueryParams(): array
    {
        $params = [
            'limit' => (string) $this->limit,
            'offset' => (string) $this->offset,
        ];

        if ($this->type !== null && $this->type !== '') {
            $params['type'] = $this->type;
        }

        return $params;
    }
}
