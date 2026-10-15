<?php

declare(strict_types=1);

if (getenv('BROWSER_TESTS') !== '1') {
    test('weighted flow browser checks require an explicit disposable browser environment', fn () => null)->skip('set BROWSER_TESTS=1');
    return;
}

beforeAll(function (): void {
    browserAdmin('rotation-browser-pass');
    browserPdo()->exec("DELETE FROM core.campaigns WHERE slug IN ('rotationru', 'rotationen')");
});

test('rotation and every flow type persist with errors retained in both locales', function (string $locale, string $rotationLabel, string $typeLabel): void {
    $page = visit(browserUrl('/admin/login'));
    $page->fill('login', 'admin')->fill('password', 'rotation-browser-pass')->press('button[type=submit]');
    $page->assertPathIs('/admin');
    $page->navigate(browserUrl('/admin/lang/' . $locale));
    $page->navigate(browserUrl('/admin/campaigns/new'));
    $page->assertSee($rotationLabel);
    $page->assertScript('document.querySelector("[name=flow_rotation]").value', 'position');
    $page->assertScript('document.querySelector("button[type=submit]").disabled', false);
    $page->fill('name', 'Rotation ' . $locale)->fill('slug', 'rotation' . $locale)->select('flow_rotation', 'weight');
    $page->script('document.querySelector("#is_active").checked = false');
    $page->press('button[type=submit]')->assertPathIs('/admin/campaigns');
    $pdo = browserPdo();
    $stmt = $pdo->prepare('SELECT id, flow_rotation, is_active FROM core.campaigns WHERE slug = :slug');
    $stmt->execute(['slug' => 'rotation' . $locale]);
    $c = $stmt->fetch(PDO::FETCH_ASSOC);
    expect($c['flow_rotation'])->toBe('weight');
    expect($c['is_active'])->toBeFalse();
    $edit = '/admin/campaigns/' . $c['id'] . '/edit';
    $page->navigate(browserUrl($edit));
    $campaignSave = 'form[action="/admin/campaigns/' . $c['id'] . '"] button[type=submit]';
    // Select the existing settings tab explicitly after reopening.
    $page->click('[role=tab] >> nth=0');
    $page->assertScript('document.querySelector("[name=flow_rotation]").value', 'weight');
    $page->assertScript('document.querySelector("#is_active").checked', false);
    foreach (['position', 'weight'] as $rotation) {
        $page->select('flow_rotation', $rotation)->press($campaignSave);
        $page->assertPathIs('/admin/campaigns');
        $page->navigate(browserUrl($edit));
        $page->click('[role=tab] >> nth=0');
        $page->assertScript('document.querySelector("[name=flow_rotation]").value', $rotation);
        expect($pdo->query("SELECT flow_rotation FROM core.campaigns WHERE id = '{$c['id']}'")->fetchColumn())->toBe($rotation);
    }
    $page->script('const s = document.querySelector("[name=flow_rotation]"); s.add(new Option("forged", "forged", true, true));');
    $page->press($campaignSave);
    $page->assertPathIs($edit);
    $page->click('[role=tab] >> nth=0');
    $page->assertScript('document.querySelector("[name=flow_rotation]").value', 'forged');
    $page->assertScript('document.querySelectorAll(".form-error").length > 0', true);
    $page->assertScript('document.querySelector("#is_active").checked', false);
    expect($pdo->query("SELECT flow_rotation FROM core.campaigns WHERE id = '{$c['id']}'")->fetchColumn())->toBe('weight');

    foreach (['forced', 'regular', 'default'] as $type) {
        $base = '/admin/campaigns/' . $c['id'] . '/flows';
        $page->navigate(browserUrl($base . '/new'));
        $page->assertSee($typeLabel);
        $page->assertScript('document.querySelector("[name=flow_type]").value', 'regular');
        $page->fill('name', $type)->select('flow_type', $type)->fill('weight', '0');
        $page->script('document.querySelector("#is_active").checked = false');
        $page->press('button[type=submit]')->assertPathIs($edit);
        $stmt = $pdo->prepare('SELECT id, flow_type, weight, is_active FROM core.flows WHERE campaign_id = :cid AND name = :name');
        $stmt->execute(['cid' => $c['id'], 'name' => $type]);
        $f = $stmt->fetch(PDO::FETCH_ASSOC);
        expect([$f['flow_type'], (int)$f['weight'], $f['is_active']])->toBe([$type, 0, false]);
        $flowEdit = $base . '/' . $f['id'] . '/edit';
        $page->navigate(browserUrl($flowEdit));
        $page->assertScript('document.querySelector("[name=flow_type]").value', $type);
        $page->assertScript('document.querySelector("[name=weight]").value', '0');
        $page->assertScript('document.querySelector("#is_active").checked', false);
        $page->fill('weight', '1000')->press('button[type=submit]')->assertPathIs($edit);
        $page->navigate(browserUrl($flowEdit));
        $page->assertScript('document.querySelector("[name=weight]").value', '1000');
        foreach (['1.5', '12junk'] as $invalid) {
            $page->fill('weight', $invalid)->press('button[type=submit]')->assertPathIs($flowEdit);
            $page->assertScript('document.querySelector("[name=weight]").value', $invalid);
            $page->assertScript('document.querySelector("[name=flow_type]").value', $type);
            $page->assertScript('document.querySelector("#is_active").checked', false);
            $page->assertScript('document.querySelectorAll(".form-error").length > 0', true);
            $page->assertScript('document.querySelector("button[type=submit]").disabled', false);
            expect((int)$pdo->query("SELECT weight FROM core.flows WHERE id = '{$f['id']}'")->fetchColumn())->toBe(1000);
        }
        $next = $type === 'forced' ? 'regular' : ($type === 'regular' ? 'default' : 'forced');
        $page->select('flow_type', $next)->fill('weight', '7')->press('button[type=submit]')->assertPathIs($edit);
        $page->navigate(browserUrl($flowEdit));
        $page->assertScript('document.querySelector("[name=flow_type]").value', $next);
        $page->assertScript('document.querySelector("[name=weight]").value', '7');
        expect($pdo->query("SELECT flow_type FROM core.flows WHERE id = '{$f['id']}'")->fetchColumn())->toBe($next);
    }
    $page->navigate(browserUrl($edit));
    $page->click('[role=tab] >> nth=1');
    $page->assertSee($locale === 'en' ? 'By weight' : 'По весу');
    $page->assertSee($locale === 'en' ? 'Forced' : 'Принудительный');
    $page->click('[role=tab] >> nth=4');
    $page->assertSee($locale === 'en' ? 'By weight' : 'По весу');
    $page->assertSee($locale === 'en' ? 'Default fallback' : 'Резервный');
})->with([
    ['ru', 'Распределение потоков', 'Тип потока'],
    ['en', 'Flow rotation', 'Flow type'],
]);
