<div class="page-head">
  <h2>🎯 Nhiệm vụ kiếm tiền</h2>
  <a href="/tasks/history">Lịch sử nhiệm vụ</a>
</div>
<?php if ($res['ok'] && !empty($res['tasks'])): ?>
<p class="hint muted" data-task-refresh="<?= max(5, (int)($res['meta']['refresh_after'] ?? 45)) ?>">Trạng thái nhiệm vụ tự cập nhật. Khi hết thời gian chờ, hệ thống kiểm tra lại với nhà cung cấp.</p>
<?php endif; ?>
  <p class="hint">Không cộng tiền chỉ vì quay lại. Phần thưởng chờ xác nhận API hoặc postback có chữ ký. Không dùng nhiều tài khoản, chia sẻ link hay bỏ qua các bước.</p>
  <div class="task-grid">
  <?php $enabledProviders = 0; ?>
  <?php foreach ($providers as $providerId => $definition):
    if (\App\Settings::getInt('provider_' . $providerId . '_enabled') !== 1) continue;
    $enabledProviders++;
    $apiProof = $providerId === 'traffic24h';
    $count = (int)($providerCounts[$providerId] ?? 0);
    $limit = \App\Providers\Tasks::setting($providerId, 'daily_limit');
    $wait = max(0, (int)$clickCooldown - (time() - strtotime($providerLastStart ?? '1970-01-01')));
    $ready = $count < $limit && $wait === 0;
    ?>
    <?php foreach ($definition['services'] as $service): if ($service['id'] === 'no_ads') continue;
      $reward = \App\Settings::getInt('provider_' . $providerId . '_service_' . $service['id'] . '_reward_vnd', \App\Providers\Tasks::setting($providerId, 'reward_vnd')); ?>
    <article class="card task-card state-<?= $ready ? 'ok' : 'off' ?>">
      <div class="task-top">
        <span class="task-name"><?= e($definition['label']) ?><br><span class="muted hint"><?= e($service['label']) ?></span></span>
        <span class="reward"><?= $reward > 0 ? vnd($reward) : e(\App\Providers\Tasks::setting($providerId, 'share_percent') . '% job') ?></span>
      </div>
      <div class="task-meta">
        <span class="pill <?= $ready ? 'ok' : 'warn' ?>"><?= $ready ? '✅ Khả dụng' : ($wait > 0 ? '⏳ Chờ ' . $wait . 's' : '🔒 Hết lượt 24h') ?></span>
        <span class="muted">Còn tối đa <?= max(0, $limit - $count) ?>/<?= $limit ?> lượt / 24h · tối thiểu <?= \App\Providers\Tasks::setting($providerId, 'min_seconds') ?>s</span>
        <span class="muted"><?= $providerId === 'yeujob' ? 'YeuJob V2: chờ xác minh kết quả' : ($providerId === 'xtask' ? 'XTask: chờ xác minh kết quả' : ($apiProof ? 'Xác minh qua API đối tác' : 'Chờ xác nhận hoặc callback có xác thực')) ?> · còn áp dụng giới hạn IP</span>
      </div>
      <form method="post" action="/tasks/provider/start" target="_blank" rel="noopener noreferrer" class="provider-start">
        <?= \App\Csrf::field() ?>
        <input type="hidden" name="provider" value="<?= e($providerId) ?>">
        <input type="hidden" name="service" value="<?= e($service['id']) ?>">
        <button class="btn" type="submit" <?= $ready ? '' : 'disabled' ?>><?= $ready ? '🚀 Bắt đầu' : 'Vui lòng thử lại sau' ?></button>
      </form>
    </article>
    <?php endforeach; ?>
  <?php endforeach; ?>
<?php if (!$res['ok']): ?>
  <div class="card"><div class="flash error"><?= e($res['error']) ?></div></div>
<?php else: ?>
  <?php foreach ($res['tasks'] as $t):
    // Members only see their VND amount, never the internal USD rate.
    $memberVnd = (int)floor($t["member_reward"]);
    $state = $t['cooldown_remaining'] > 0 ? 'cool' : ($t['available'] ? 'ok' : 'off');
    $remaining = max(0, $t['remaining_in_cycle']);
  ?>
    <div class="card task-card state-<?= $state ?>">
      <div class="task-top">
        <span class="task-name"><?= e($t['task_name']) ?></span>
        <span class="reward"><?= vnd($memberVnd) ?></span>
      </div>
      <div class="task-meta">
        <?php if ($state === 'cool'): ?>
          <span class="pill warn" data-task-cooldown="<?= (int)$t['cooldown_remaining'] ?>">⏳ Chờ <?= (int)$t['cooldown_remaining'] ?>s</span>
        <?php elseif ($state === 'ok'): ?>
          <span class="pill ok">✅ Khả dụng</span>
        <?php else: ?>
          <span class="pill"><?= ($t['pending_in_cycle'] ?? 0) > 0 ? '⏳ Chờ xác nhận' : '⏳ Chờ nhà cung cấp mở lượt' ?></span>
        <?php endif; ?>
        <span class="muted">Còn <?= $remaining ?>/<?= (int)$t['total_max'] ?> lượt · thời gian nghỉ theo chu kỳ: <?= (int)$t['cooldown_seconds'] ?>s</span>
      </div>
      <?php if ($state === 'ok'): ?>
        <button class="btn do-task" data-task="<?= (int)$t['id'] ?>" data-url="/tasks/start">🚀 Bắt đầu</button>
      <?php else: ?>
        <button class="btn" disabled><?= $state === 'cool' ? 'Đang nghỉ...' : 'Chờ cập nhật lượt' ?></button>
      <?php endif; ?>
    </div>
  <?php endforeach; ?>
<?php endif; ?>
</div>
<?php if ($enabledProviders === 0 && (!$res['ok'] || empty($res['tasks']))): ?><div class="card"><p class="muted">Chưa có nhiệm vụ khả dụng. Vui lòng quay lại sau.</p></div><?php endif; ?>
<p class="muted hint">Bấm “Bắt đầu” để mở nhiệm vụ. Xem tiến độ tại lịch sử nhiệm vụ; quay lại hoặc đủ thời gian không phải bằng chứng hoàn thành.</p>
