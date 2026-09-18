<div class="card">
  <h3>Postback URL (dùng cho PubCrypto App)</h3>
  <p><code class="copybox"><?= e($postbackUrl) ?></code></p>
  <table>
  <thead><tr><th>ID</th><th>transId</th><th>subId</th><th>status</th><th>reward_pb</th><th>payout_pb</th><th>Chữ ký</th><th>Kết quả</th><th>IP</th><th>Thời gian</th></tr></thead>
  <tbody>
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
  </table>
</div>
