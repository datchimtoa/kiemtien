<div class="page-head">
  <h2>🎯 Nhiệm vụ kiếm tiền</h2>
</div>
<section aria-labelledby="provider-heading">
  <h3 id="provider-heading">Nhiệm vụ từ đối tác</h3>
  <p class="hint">Không cộng tiền chỉ vì quay lại. Phần thưởng chờ xác nhận API / postback có chữ ký hoặc admin kiểm tra. Không dùng nhiều tài khoản, chia sẻ link hay bỏ qua các bước.</p>
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
        <span class="muted"><?= $providerId === 'yeujob' ? 'YeuJob V2: admin duyệt thưởng thủ công' : ($apiProof ? 'Xác minh qua API đối tác' : 'Chờ duyệt hoặc callback có xác thực') ?> · còn áp dụng giới hạn IP</span>
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
  </div>
  <?php if ($enabledProviders === 0): ?><div class="card"><p class="muted">Chưa có nguồn đối tác được bật. Vui lòng quay lại sau.</p></div><?php endif; ?>
  <p class="hint muted">Bấm “Bắt đầu” để mở tab mới, hoàn thành hướng dẫn rồi tải lại trang này để xem lượt nhiệm vụ. Thời gian tối thiểu không phải bằng chứng hoàn thành.</p>
</section>
<?php if ($providerAttempts): ?>
<div class="card">
  <h3>Lượt nhiệm vụ đối tác gần đây</h3>
  <div class="table-wrap"><table>
    <thead><tr><th>Nhiệm vụ</th><th>Thưởng</th><th>Trạng thái</th><th>Thao tác</th></tr></thead>
    <tbody>
  <?php foreach ($providerAttempts as $attempt):
    $expired = $attempt['status'] === 'pending' && $attempt['expires_at'] < now();
    $status = $expired ? 'expired' : $attempt['status'];
    $manualV2 = $attempt['provider'] === 'yeujob' && str_starts_with($attempt['remote_id'], 'v2:');
    $labels = ['creating' => 'Đang tạo', 'pending' => $manualV2 ? 'Chờ admin duyệt' : 'Chờ xác nhận', 'credited' => 'Đã cộng thưởng', 'rejected' => 'Đã từ chối', 'failed' => 'Tạo thất bại', 'creation_failed' => 'Chưa tạo được', 'expired' => 'Hết hạn']; ?>
    <tr><td>#<?= (int)$attempt['id'] ?> · <?= e($providers[$attempt['provider']]['label'] ?? $attempt['provider']) ?><br><span class="muted"><?= e($attempt['service']) ?> · <?= e($attempt['created_at']) ?></span></td>
    <td><?= vnd($attempt['reward_vnd']) ?></td>
    <td><span class="pill <?= $status === 'credited' ? 'ok' : ($status === 'pending' ? 'pending' : 'warn') ?>"><?= e($labels[$status] ?? $status) ?></span></td><td>
    <?php if ($status === 'pending'): ?>
      <form method="post" action="/tasks/provider/verify">
        <?php if (!$manualV2): ?>
          <?= \App\Csrf::field() ?>
          <input type="hidden" name="token" value="<?= e($attempt['token']) ?>">
          <button class="btn" type="submit">Kiểm tra xác nhận</button>
        <?php else: ?><span class="hint">Hoàn thành job rồi chờ admin đối chiếu; quay lại không tự cộng tiền.</span><?php endif; ?>
        <?php if ($attempt['short_url']): ?><a href="<?= e($attempt['short_url']) ?>" target="_blank" rel="noopener noreferrer">Tiếp tục nhiệm vụ</a><?php endif; ?>
      </form>
    <?php endif; ?>
    </td></tr>
  <?php endforeach; ?>
    </tbody></table></div>
</div>
<?php endif; ?>
<?php if (!$res['ok']): ?>
  <div class="card"><div class="flash error"><?= e($res['error']) ?></div></div>
<?php else: ?>
  <h3>Nhiệm vụ PubCrypto</h3>
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
