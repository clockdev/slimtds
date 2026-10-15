<?php

declare(strict_types=1);

use App\Admin\Controller\CampaignController;
use App\Admin\Controller\FlowController;
use App\Admin\Form\CampaignForm;
use App\Admin\Form\FlowForm;
use App\Admin\Repository\CampaignRepository;
use App\Admin\Repository\FlowRepository;
use App\Admin\Repository\OfferRepository;
use App\Shared\Asset\Manifest;
use App\Shared\CampaignIdGenerator;
use App\Shared\Db\Connection;
use App\Shared\I18n\I18n;
use App\Shared\I18n\TranslatorFactory;
use App\Shared\View\View;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

beforeEach(function (): void {
    $_SESSION = [];
    $GLOBALS['__old_bag__'] = [];
    pdo()->exec('DELETE FROM core.campaigns');
    $db = new Connection(pdo());
    $this->campaigns = new CampaignRepository($db, new CampaignIdGenerator());
    $this->flows = new FlowRepository($db);
    $offers = new OfferRepository($db);
    $this->campaignController = new CampaignController($this->campaigns, new CampaignForm($this->campaigns, new CampaignIdGenerator()), $offers, $this->flows);
    $this->flowController = new FlowController($this->flows, $this->campaigns, $offers, new FlowForm());
    $root = dirname(__DIR__, 3);
    $this->view = new View($root . '/resources/views', new Manifest($root . '/public/assets/manifest.json'), new I18n((new TranslatorFactory($root . '/resources/translations'))->create()));
    $this->request = (new ServerRequestFactory())->createServerRequest('POST', '/admin/campaigns');
});

afterEach(function (): void {
    $GLOBALS['__old_bag__'] = [];
});

test('campaign controller creates updates omits and rejects rotation without overwrite', function (): void {
    $this->campaignController->create($this->request->withParsedBody(['name' => 'Campaign', 'slug' => 'rotctl', 'flow_rotation' => 'weight']), new Response());
    $c = $this->campaigns->findBySlug('rotctl');
    expect($c->flowRotation)->toBe('weight');
    $this->campaignController->update($this->request->withParsedBody(['name' => 'Renamed']), new Response(), $c->id);
    expect($this->campaigns->findById($c->id)->flowRotation)->toBe('weight');
    foreach ([[], null, 1, 'bad'] as $value) {
        $data = ['name' => 'Rejected', 'flow_rotation' => $value];
        $r = $this->campaignController->update($this->request->withParsedBody($data), new Response(), $c->id);
        expect($r->getHeaderLine('Location'))->toBe('/admin/campaigns/' . $c->id . '/edit');
        expect($_SESSION['_old'])->toBe($data);
        expect($_SESSION['_errors'])->toHaveKey('flow_rotation');
        expect($this->campaigns->findById($c->id)->name)->toBe('Renamed');
        expect($this->campaigns->findById($c->id)->flowRotation)->toBe('weight');
    }
    $this->campaignController->update($this->request->withParsedBody(['name' => 'Position', 'flow_rotation' => 'position']), new Response(), $c->id);
    expect($this->campaigns->findById($c->id)->flowRotation)->toBe('position');
    $count = $this->campaigns->count();
    $this->campaignController->create($this->request->withParsedBody(['name' => 'Bad', 'flow_rotation' => []]), new Response());
    expect($this->campaigns->count())->toBe($count);
    $this->campaignController->create($this->request->withParsedBody(['name' => 'Legacy', 'slug' => 'legacyrot']), new Response());
    expect($this->campaigns->findBySlug('legacyrot')->flowRotation)->toBe('position');
});

test('flow controller persists every type and bounds preserves omission and rejects forged input', function (): void {
    $c = $this->campaigns->create(['name' => 'Campaign']);
    $base = ['name' => 'Flow', 'schema_id' => '2', 'target_type' => 'none', 'schema_config' => '{"url":"https://destination.example/old"}'];
    foreach (['forced', 'regular', 'default'] as $type) {
        $this->flowController->create($this->request->withParsedBody($base + ['flow_type' => $type, 'weight' => '0']), new Response(), $c->id);
        $f = array_values(array_filter($this->flows->forCampaign($c->id), fn ($flow) => $flow->flowType === $type))[0];
        expect([$f->flowType, $f->weight])->toBe([$type, 0]);
        $this->flowController->update($this->request->withParsedBody($base + ['weight' => '1000']), new Response(), $c->id, $f->id);
        expect([$this->flows->findById($f->id)->flowType, $this->flows->findById($f->id)->weight])->toBe([$type, 1000]);
        $stored = $this->flows->findById($f->id);
        foreach ([['weight' => '1.5'], ['weight' => 'junk'], ['weight' => []], ['flow_type' => []], ['flow_type' => null], ['flow_type' => 'bad']] as $invalid) {
            $data = $invalid + ['name' => 'Rejected', 'schema_id' => '2', 'target_type' => 'none'];
            $r = $this->flowController->update($this->request->withParsedBody($data), new Response(), $c->id, $f->id);
            expect($r->getHeaderLine('Location'))->toBe('/admin/campaigns/' . $c->id . '/flows/' . $f->id . '/edit');
            expect($_SESSION['_old'])->toBe($data);
            expect($_SESSION['_errors'])->toHaveKey(array_key_first($invalid));
            expect($this->flows->findById($f->id))->toEqual($stored);
            expect([$this->flows->findById($f->id)->name, $this->flows->findById($f->id)->flowType, $this->flows->findById($f->id)->weight, $this->flows->findById($f->id)->schemaConfig])->toBe(['Flow', $type, 1000, ['url' => 'https://destination.example/old']]);
        }
    }
    $count = count($this->flows->forCampaign($c->id));
    foreach ([['weight' => '-1'], ['flow_type' => []], ['flow_type' => 'unknown']] as $invalid) {
        $data = $invalid + $base;
        $this->flowController->create($this->request->withParsedBody($data), new Response(), $c->id);
        expect(count($this->flows->forCampaign($c->id)))->toBe($count);
        expect($_SESSION['_old'])->toBe($data);
        expect($_SESSION['_errors'])->toHaveKey(array_key_first($invalid));
    }
    $this->flowController->create($this->request->withParsedBody(['name' => 'Legacy', 'schema_id' => '2']), new Response(), $c->id);
    $legacy = array_values(array_filter($this->flows->forCampaign($c->id), fn ($f) => $f->name === 'Legacy'))[0];
    expect([$legacy->flowType, $legacy->weight])->toBe(['regular', 100]);
    $this->flowController->update($this->request->withParsedBody($base + ['flow_type' => 'forced']), new Response(), $c->id, $legacy->id);
    expect($this->flows->findById($legacy->id)->flowType)->toBe('forced');
});

test('rejected edit renders raw weight type rotation errors and submitted flow configuration', function (): void {
    $c = $this->campaigns->create(['name' => 'Campaign', 'flow_rotation' => 'weight']);
    $f = $this->flows->create($c->id, ['name' => 'Flow', 'schema_id' => 2]);
    $data = ['name' => 'Edited', 'schema_id' => '4', 'flow_type' => 'default', 'weight' => '12junk', 'is_active' => '0', 'target_type' => 'none', 'schema_config' => '{"url":"https://destination.example/new"}', 'filters' => '[[{"field":"country","op":"eq","value":"us"}]]'];
    $this->flowController->update($this->request->withParsedBody($data), new Response(), $c->id, $f->id);
    // Same old-value snapshot provided by the existing middleware on redirect.
    $GLOBALS['__old_bag__'] = $_SESSION['_old'];
    $html = (string)$this->flowController->edit($this->request, new Response(), $this->view, $c->id, $f->id)->getBody();
    expect($html)->toContain('value="12junk"', 'value="default" selected', 'form-error', 'destination.example\\/new', 'initialSchemaId: 4', '&quot;country&quot;');
    $this->campaignController->update($this->request->withParsedBody(['name' => '', 'flow_rotation' => 'position']), new Response(), $c->id);
    $GLOBALS['__old_bag__'] = $_SESSION['_old'];
    $html = (string)$this->campaignController->edit($this->request, new Response(), $this->view, $c->id)->getBody();
    expect($html)->toContain('value="position" selected', 'form-error');
    expect($this->campaigns->findById($c->id)->flowRotation)->toBe('weight');
});
