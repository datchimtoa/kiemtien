<div class="page-head">
  <h2>🎯 Nhiệm vụ kiếm tiền</h2>
</div>
<div class="card">
  <h3>Nhiệm vụ từ đối tác</h3>
  <p class="hint">Không cộng tiền chỉ vì quay lại. Phần thưởng chờ xác nhận API / postback có chữ ký hoặc admin kiểm tra. Không dùng nhiều tài khoản, chia sẻ link hay bỏ qua các bước.</p>
  <?php foreach ($providers as $providerId => $definition):
    if (\App\Settings::getInt('provider_' . $providerId . '_enabled') !== 1) continue; ?>
    <h4><?= e($definition['label']) ?></h4>
    <?php foreach ($definition['services'] as $service): if ($service['id'] === 'no_ads') continue;
      $reward = \App\Settings::getInt('provider_' . $providerId . '_service_' . $service['id'] . '_reward_vnd', \App\Providers\Tasks::setting($providerId, 'reward_vnd')); ?>
      <form method="post" action="/tasks/provider/start" class="stack">
        <?= \App\Csrf::field() ?>
        <input type="hidden" name="provider" value="<?= e($providerId) ?>">
        <input type="hidden" name="service" value="<?= e($service['id']) ?>">
        <button class="btn" type="submit"><?= e($service['label']) ?> · <?= $reward > 0 ? vnd($reward) : e(\App\Providers\Tasks::setting($providerId, 'share_percent') . '% thưởng job') ?></button>
      </form>
    <?php endforeach; ?>
  <?php endforeach; ?>
</div>
<?php if ($providerAttempts): ?>
<div class="card">
  <h3>Lượt nhiệm vụ đối tác gần đây</h3>
  <?php foreach ($providerAttempts as $attempt): ?>
    <p>#<?= (int)$attempt['id'] ?> · <?= e($attempt['provider']) ?> / <?= e($attempt['service']) ?> · <?= vnd($attempt['reward_vnd']) ?> · <?= e($attempt['status']) ?></p>
    <?php if ($attempt['status'] === 'pending'): ?>
      <form method="post" action="/tasks/provider/verify">
        <?= \App\Csrf::field() ?>
        <input type="hidden" name="token" value="<?= e($attempt['token']) ?>">
        <button class="btn" type="submit">Kiểm tra xác nhận</button>
        <?php if ($attempt['short_url']): ?><a href="<?= e($attempt['short_url']) ?>" rel="noreferrer">Tiếp tục nhiệm vụ</a><?php endif; ?>
      </form>
    <?php endif; ?>
  <?php endforeach; ?>
</div>
<?php endif; ?>
<?php if (!$res['ok']): ?>
  <div class="card"><div class="flash error"><?= e($res['error']) ?></div></div>
<?php else: ?>
  <div class="task-grid">
  <?php foreach ($res['tasks'] as $t):
    // Member chỉ thấy số VND họ nhận — KHÔNG hiển thị rate admin (USD) anywhere.
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
          <span class="pill warn">⏳ Chờ <?= $t['cooldown_remaining'] ?>s</span>
        <?php elseif ($state === 'ok'): ?>
          <span class="pill ok">✅ Khả dụng</span>
        <?php else: ?>
          <span class="pill">🔒 Hết lượt</span>
        <?php endif; ?>
        <span class="muted">Còn <?= $remaining ?>/<?= (int)$t['total_max'] ?> lượt · làm lại sau <?= (int)$t['cooldown_seconds'] ?>s</span>
      </div>
      <?php if ($state === 'ok'): ?>
        <button class="btn do-task" data-task="<?= (int)$t['id'] ?>" data-url="/tasks/start">🚀 Bắt đầu</button>
      <?php else: ?>
        <button class="btn" disabled><?= $state === 'cool' ? 'Đang nghỉ...' : 'Hết lượt chu kỳ' ?></button>
      <?php endif; ?>
    </div>
  <?php endforeach; ?>
  </div>
  <p class="muted hint">⚡ Tiền tự cộng qua hệ thống postback bảo mật — không cần báo admin.</p>
  <p class="muted hint">💡 Bấm “Bắt đầu” → nhiệm vụ mở ở tab mới → hoàn thành theo hướng dẫn → quay lại đây → tiền tự cộng trong ít phút.</p>
<?php endif; ?>
