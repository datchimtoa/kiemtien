<?php
$activity = array_reverse($tx);
$balances = array_map(static fn(array $row): int => (int)$row['balance_after'], $activity);
$chartPoints = [];
if (count($balances) > 1) {
    $lowest = min($balances);
    $range = max(1, max($balances) - $lowest);
    foreach ($balances as $index => $balance) {
        $x = 12 + ($index * 696 / (count($balances) - 1));
        $y = 136 - (($balance - $lowest) * 110 / $range);
        $chartPoints[] = round($x, 2) . ',' . round($y, 2);
    }
}
?>
<section class="card">
  <div class="section-head"><div><h3>Biến động số dư</h3><p>Tối đa 12 giao dịch gần nhất · không phải tổng hợp theo ngày.</p></div><span class="mono muted">VND</span></div>
  <?php if ($chartPoints): ?>
    <figure class="activity-chart">
      <svg viewBox="0 0 720 160" role="img" aria-labelledby="activity-title" preserveAspectRatio="none">
        <title id="activity-title">Số dư sau <?= count($activity) ?> giao dịch gần nhất, theo thứ tự thời gian</title>
        <path class="chart-grid" d="M12 26H708 M12 81H708 M12 136H708"></path>
        <polyline class="chart-line" points="<?= e(implode(' ', $chartPoints)) ?>"></polyline>
      </svg>
      <figcaption><time><?= e($activity[0]['created_at']) ?></time><time><?= e($activity[count($activity) - 1]['created_at']) ?></time></figcaption>
    </figure>
  <?php else: ?>
    <div class="empty-state">Cần ít nhất hai giao dịch để hiển thị biến động số dư.</div>
  <?php endif; ?>
</section>
