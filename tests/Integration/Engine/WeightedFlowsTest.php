<?php

declare(strict_types=1);

use App\Admin\Repository\CampaignRepository;
use App\Admin\Repository\FlowRepository;
use App\Admin\Repository\OfferRepository;
use App\Engine\BotDetector;
use App\Engine\ClickHandler;
use App\Engine\DeviceDetector;
use App\Engine\FilterCompiler;
use App\Engine\FlowMatcher;
use App\Engine\GeoLookup;
use App\Engine\MacroExpander;
use App\Engine\OfferPicker;
use App\Engine\Schema\SchemaRegistry;
use App\Engine\VisitorResolver;
use App\Shared\CampaignIdGenerator;
use App\Admin\Repository\SettingsRepository;
use App\Shared\Db\Connection;
use App\Shared\Notification\NotificationRegistry;
use App\Shared\Telegram\TelegramNotifier;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

use App\Engine\Context;
beforeEach(function (): void {
    $pdo = pdo();
    $pdo->exec('DELETE FROM stats.clicks');
    $pdo->exec('DELETE FROM stats.visitors_fingerprints');
    $pdo->exec('DELETE FROM core.flows');
    $pdo->exec('DELETE FROM core.offers');
    $pdo->exec('DELETE FROM core.campaigns');
    $this->db = new Connection($pdo);
    $this->camps = new CampaignRepository($this->db, new CampaignIdGenerator());
    $this->flows = new FlowRepository($this->db);
    $this->matcher = new FlowMatcher($this->flows, new FilterCompiler());
    $this->camp = $this->camps->create(['name' => 'C', 'slug' => 'fmatch', 'is_active' => '1']);
});

test('weighted regular zero weight is never selected', function (): void {
    $this->flows->create($this->camp->id, ['name' => 'Zero', 'weight' => 0, 'is_active' => '1']);
    $positive = $this->flows->create($this->camp->id, ['name' => 'Positive', 'weight' => 3, 'is_active' => '1']);
    $ctx = new Context('192.0.2.1', 'curl', 'fmatch', time());
    expect($this->matcher->match($this->camp->id, $ctx, 'weight')?->id)->toBe($positive->id);
    expect($ctx->matchedFlowId)->toBe($positive->id);
});

test('weighted integer intervals map every draw of one to three', function (int $draw, int $winner): void {
    $a = $this->flows->create($this->camp->id, ['name' => 'One', 'weight' => 1, 'is_active' => '1']);
    $b = $this->flows->create($this->camp->id, ['name' => 'Three', 'weight' => 3, 'is_active' => '1']);
    $bounds = [];
    $matcher = new FlowMatcher($this->flows, new FilterCompiler(), function (int $min, int $max) use ($draw, &$bounds): int {
        $bounds[] = [$min, $max];
        return $draw;
    });
    $ctx = new Context('192.0.2.1', 'curl', 'fmatch', time());
    $id = [$a->id, $b->id][$winner];
    expect($matcher->match($this->camp->id, $ctx, 'weight')?->id)->toBe($id);
    expect($ctx->matchedFlowId)->toBe($id)->and($bounds)->toBe([[1, 4]]);
})->with([[1, 0], [2, 1], [3, 1], [4, 1]]);

test('forced regular default priority honors eligibility and ignores special weights', function (string $mode): void {
    $default = $this->flows->create($this->camp->id, ['name' => 'Fallback', 'flow_type' => 'default', 'weight' => -5, 'is_active' => '1']);
    $regular = $this->flows->create($this->camp->id, ['name' => 'Regular', 'weight' => 1, 'is_active' => '1']);
    $this->flows->create($this->camp->id, ['name' => 'Inactive forced', 'flow_type' => 'forced', 'weight' => 1000]);
    $this->flows->create($this->camp->id, ['name' => 'Filtered forced', 'flow_type' => 'forced', 'is_active' => '1', 'filters' => [[['field' => 'country', 'op' => 'eq', 'value' => 'zz']]]]);
    $forced = $this->flows->create($this->camp->id, ['name' => 'Forced', 'flow_type' => 'forced', 'weight' => 0, 'is_active' => '1']);
    $ctx = new Context('192.0.2.1', 'curl', 'fmatch', time());
    expect($this->matcher->match($this->camp->id, $ctx, $mode)?->id)->toBe($forced->id);
    $this->flows->delete($forced->id);
    $this->matcher->invalidate($this->camp->id);
    expect($this->matcher->match($this->camp->id, $ctx, $mode)?->id)->toBe($regular->id);
    $this->flows->delete($regular->id);
    $this->matcher->invalidate($this->camp->id);
    expect($this->matcher->match($this->camp->id, $ctx, $mode)?->id)->toBe($default->id);
    expect($ctx->matchedFlowId)->toBe($default->id);
})->with(['position', 'weight']);

test('weighted excludes inactive filtered and nonpositive regular and default filters still apply', function (): void {
    foreach ([['weight' => 0], ['weight' => -1], ['weight' => 100, 'is_active' => '0'], ['weight' => 100, 'filters' => [[['field' => 'country', 'op' => 'eq', 'value' => 'zz']]]]] as $data) {
        $this->flows->create($this->camp->id, $data + ['name' => 'Excluded', 'is_active' => '1']);
    }
    $this->flows->create($this->camp->id, ['name' => 'Filtered fallback', 'flow_type' => 'default', 'is_active' => '1', 'filters' => [[['field' => 'country', 'op' => 'eq', 'value' => 'zz']]]]);
    $fallback = $this->flows->create($this->camp->id, ['name' => 'Fallback', 'flow_type' => 'default', 'weight' => 0, 'is_active' => '1']);
    $ctx = new Context('192.0.2.1', 'curl', 'fmatch', time());
    expect($this->matcher->match($this->camp->id, $ctx, 'weight')?->id)->toBe($fallback->id);
    $this->flows->delete($fallback->id);
    $this->matcher->invalidate($this->camp->id);
    expect($this->matcher->match($this->camp->id, $ctx, 'weight'))->toBeNull();
    expect($ctx->matchedFlowId)->toBeNull();
});

test('two argument positional mode retains zero weight and weighted empty returns null', function (): void {
    $ctx = new Context('192.0.2.1', 'curl', 'fmatch', time());
    expect($this->matcher->match($this->camp->id, $ctx, 'weight'))->toBeNull();
    $zero = $this->flows->create($this->camp->id, ['name' => 'Zero', 'weight' => 0, 'is_active' => '1']);
    $this->matcher->invalidate($this->camp->id);
    expect($this->matcher->match($this->camp->id, $ctx)?->id)->toBe($zero->id);
    expect($this->matcher->match($this->camp->id, $ctx, 'weight'))->toBeNull();
});

test('stored weighted campaign redirects exactly logs selected flow and preserves trash and pinned links', function (): void {
    $this->camps->update($this->camp->id, ['name' => 'Weighted', 'slug' => 'fmatch', 'is_active' => '1', 'flow_rotation' => 'weight', 'trash_mode' => 3]);
    $zero = $this->flows->create($this->camp->id, ['name' => 'Zero', 'weight' => 0, 'is_active' => '1', 'target_type' => 'none', 'schema_id' => 2, 'schema_config' => ['url' => 'https://destination.example/zero']]);
    $positive = $this->flows->create($this->camp->id, ['name' => 'Positive', 'weight' => 3, 'is_active' => '1', 'target_type' => 'none', 'schema_id' => 2, 'schema_config' => ['url' => 'https://destination.example/positive']]);
    $handler = new ClickHandler(
        $this->camps, new OfferRepository($this->db), new VisitorResolver($this->db),
        new DeviceDetector(), new GeoLookup(), new BotDetector($this->db), $this->matcher,
        new OfferPicker(), new MacroExpander(), new SchemaRegistry(), $this->db,
        new TelegramNotifier(null, null), new SettingsRepository($this->db), new NotificationRegistry(),
    );
    // A query parameter cannot override the stored rotation policy.
    $request = (new ServerRequestFactory())->createServerRequest('GET', '/fmatch')->withQueryParams(['flow_rotation' => 'position']);
    $response = $handler->handle($request, new Response(), 'fmatch');
    expect($response->getStatusCode())->toBe(302)->and($response->getHeaderLine('Location'))->toBe('https://destination.example/positive');
    expect($this->db->fetchScalar('SELECT flow_id FROM stats.clicks ORDER BY created_at DESC LIMIT 1'))->toBe($positive->id);
    $this->db->execute('UPDATE core.flows SET is_active = false WHERE campaign_id = :id', ['id' => $this->camp->id]);
    $this->matcher->invalidate($this->camp->id);
    $response = $handler->handle($request, new Response(), 'fmatch');
    expect($response->getStatusCode())->toBe(404)->and($response->getHeaderLine('Location'))->toBe('');
    expect($this->db->fetchScalar('SELECT flow_id FROM stats.clicks ORDER BY created_at DESC LIMIT 1'))->toBeNull();
    $request = $request->withQueryParams(['_t' => \App\Shared\TestLink::flowKey($zero->id)]);
    $response = $handler->handle($request, new Response(), 'fmatch');
    expect($response->getStatusCode())->toBe(302)->and($response->getHeaderLine('Location'))->toBe('https://destination.example/zero');
    expect($this->db->fetchScalar('SELECT flow_id FROM stats.clicks ORDER BY created_at DESC LIMIT 1'))->toBe($zero->id);
});
