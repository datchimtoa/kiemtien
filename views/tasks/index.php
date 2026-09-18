<div class="page-head">
  <h2>🎯 Nhiệm vụ kiếm tiền</h2>
</div>
<?php if (!$res['ok']): ?>
  <div class="card"><div class="flash error"><?= e($res['error']) ?></div></div>
<?php else: ?>
  <div class="task-grid">
  <?php foreach ($res['tasks'] as $t):
    $rewardVnd = (int)floor($t["member_reward"]);
    $state = $t['cooldown_remaining'] > 0 ? 'cool' : ($t['available'] ? 'ok' : 'off');
    $remaining = max(0, $t['remaining_in_cycle']);
  ?>
    <div class="card task-card state-<?= $state ?>">
      <div class="task-top">
        <span class="task-name"><?= e($t['task_name']) ?></span>
        <span class="reward"><?= vnd($rewardVnd) ?></span>
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
  <p class="muted hint">⚡ Tiền tự cộng qua hệ thống postback bảo mật — không cần báo admin. Nhiệm vụ có mã sẽ yêu cầu nhập mã tìm được.</p>
<?php endif; ?>
