<?php

declare(strict_types=1);

namespace App\Engine;

use App\Admin\Repository\Flow;
use App\Admin\Repository\FlowRepository;
use App\Shared\TestLink;
use Closure;

final class FlowMatcher
{
    /** @var array<string, array{ts:int, flows: list<Flow>}> */
    private array $cache = [];
    private const CACHE_TTL = 60; // seconds — cheap invalidation

    /** @param null|Closure(int, int): int $draw */
    public function __construct(
        private readonly FlowRepository $repo,
        private readonly FilterCompiler $compiler,
        private readonly ?Closure $draw = null,
    ) {}

    public function match(string $campaignId, Context $ctx, string $rotation = 'position'): ?Flow
    {
        $ctx->matchedFlowId = null;
        $regular = [];
        $default = null;
        $total = 0;
        foreach ($this->cachedFlows($campaignId) as $flow) {
            if (!$flow->isActive) continue;
            $predicate = $this->compiler->compile($flow->filters);
            if (!$predicate($ctx)) continue;
            if ($flow->flowType === 'forced') {
                $ctx->matchedFlowId = $flow->id;
                return $flow;
            }
            if ($flow->flowType === 'default') {
                $default ??= $flow;
            } elseif ($rotation !== 'weight' || $flow->weight > 0) {
                $regular[] = $flow;
                $total += $flow->weight;
            }
        }

        $selected = $regular[0] ?? $default;
        if ($rotation === 'weight' && $regular !== []) {
            $draw = $this->draw === null ? random_int(1, $total) : ($this->draw)(1, $total);
            foreach ($regular as $flow) {
                $draw -= $flow->weight;
                if ($draw <= 0) {
                    $selected = $flow;
                    break;
                }
            }
        }
        $ctx->matchedFlowId = $selected?->id;
        return $selected;
    }


    /**
     * The flow a test link points at, active or not, filters ignored. A
     * campaign holds a handful of flows, so recomputing each key is cheaper
     * than any index — and it only runs when a request carries `_t` at all.
     */
    public function forTestLink(string $campaignId, TestLink $link): ?Flow
    {
        foreach ($this->cachedFlows($campaignId) as $flow) {
            if ($link->isFlow($flow->id)) {
                return $flow;
            }
        }
        return null;
    }

    /** @return list<Flow> */
    private function cachedFlows(string $campaignId): array
    {
        $now = time();
        $entry = $this->cache[$campaignId] ?? null;
        if ($entry !== null && $now - $entry['ts'] < self::CACHE_TTL) {
            return $entry['flows'];
        }
        $flows = $this->repo->forCampaign($campaignId);
        $this->cache[$campaignId] = ['ts' => $now, 'flows' => $flows];
        return $flows;
    }

    public function invalidate(string $campaignId): void
    {
        unset($this->cache[$campaignId]);
    }
}
