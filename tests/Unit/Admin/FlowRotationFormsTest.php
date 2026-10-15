<?php

declare(strict_types=1);

use App\Admin\Form\CampaignForm;
use App\Admin\Form\FlowForm;
use App\Admin\Repository\CampaignRepository;
use App\Shared\CampaignIdGenerator;
use App\Shared\Db\Connection;

test('weight decimals are rejected before casting', function (): void {
    expect((new FlowForm())->validate(['name' => 'Flow', 'schema_id' => 2, 'weight' => '1.5']))->toHaveKey('weight');
});

test('flow weight accepts only integer bounds', function (): void {
    $form = new FlowForm();
    foreach (['1.5', 1.5, 'junk', [], -1, '1001', '', null, true, '1e2'] as $value) {
        expect($form->validate(['name' => 'Flow', 'schema_id' => 2, 'weight' => $value]))->toHaveKey('weight');
    }
    foreach ([0, '0', 1000, '1000', 42, '42'] as $value) {
        expect($form->validate(['name' => 'Flow', 'schema_id' => 2, 'weight' => $value]))->toBe([]);
    }
    expect($form->validate(['name' => 'Flow', 'schema_id' => 2]))->toBe([]);
});

test('rotation and type reject explicit invalid enums and accept omissions', function (): void {
    $campaign = new CampaignForm(new CampaignRepository(new Connection(new PDO('sqlite::memory:')), new CampaignIdGenerator()), new CampaignIdGenerator());
    $flow = new FlowForm();
    foreach ([null, [], 0, true, 'unknown', ''] as $value) {
        expect($campaign->validate(['name' => 'Campaign', 'flow_rotation' => $value]))->toHaveKey('flow_rotation');
        expect($flow->validate(['name' => 'Flow', 'schema_id' => 2, 'flow_type' => $value]))->toHaveKey('flow_type');
    }
    foreach (['position', 'weight'] as $value) {
        expect($campaign->validate(['name' => 'Campaign', 'flow_rotation' => $value]))->toBe([]);
    }
    foreach (['forced', 'regular', 'default'] as $value) {
        expect($flow->validate(['name' => 'Flow', 'schema_id' => 2, 'flow_type' => $value]))->toBe([]);
    }
    expect($campaign->validate(['name' => 'Campaign']))->toBe([]);
    expect($flow->validate(['name' => 'Flow', 'schema_id' => 2]))->toBe([]);
});
