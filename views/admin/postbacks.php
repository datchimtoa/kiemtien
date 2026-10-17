<div class="page-head"><div><span class="eyebrow">TASK1 / PROVIDER EVENTS</span><h2>Postback & xác nhận</h2><p>Theo dõi chữ ký, trạng thái và kết quả xử lý từ PubCrypto.</p></div></div>
<div class="card">
  <h3>Postback URL (dùng cho PubCrypto App)</h3>
  <p><code class="copybox"><?= e($postbackUrl) ?></code></p>
  <div class="table-wrap"><table>
  <thead><tr><th>ID</th><th>transId</th><th>subId</th><th>status</th><th>reward_pb</th><th>payout_pb</th><th>Chữ ký</th><th>Kết quả</th><th>IP</th><th>Thời gian</th></tr></thead>
  <tbody>
<?php if (!$rows): ?><tr><td colspan="10"><div class="empty-state">Chưa ghi nhận postback nào.</div></td></tr><?php endif; ?>
  <?php foreach ($rows as $p): ?>
    <tr>
      <td><?= (int)$p['id'] ?></td>
      <td><code><?= e($p['trans_id']) ?></code></td>
      <td><?= e($p['sub_id']) ?></td>
      <td><?= (int)$p['status_raw'] ?></td>
      <td><?= (float)$p['reward_pb'] ?></td>
      <td><?= (float)$p['payout_pb'] ?></td>
      <td><?= (int)$p['signature_valid'] ? '<span class="pill ok">valid</span>' : '<span class="pill danger">INVALID</span>' ?></td>
      <td><?= e($p['result']) ?></td>
      <td><?= e($p['ip']) ?></td>
      <td><?= e($p['created_at']) ?></td>
    </tr>
  <?php endforeach; ?>
  </tbody>
  </table></div>
</div>
