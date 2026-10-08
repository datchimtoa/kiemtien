<?php
declare(strict_types=1);

namespace App\Controllers;

use App\AdminAuth;
use App\Audit;
use App\Csrf;
use App\Database;
use App\Providers\Callback;
use App\Providers\Catalog;
use App\Providers\Tasks;
use App\RateLimiter;
use App\Session;
use App\View;

final class ProviderController
{
    public static function start(): void
    {
        $user = require_user();
        Csrf::check();
        try {
            $result = Tasks::start((int)$user['id'], (string)input('provider', ''), (string)input('service', ''));
            redirect($result['redirect']);
        } catch (\Throwable $e) {
            $cause = $e;
            while ($cause->getPrevious() !== null) {
                $cause = $cause->getPrevious();
            }
            if ($cause instanceof \PDOException) {
                $ref = strtoupper(bin2hex(random_bytes(4)));
                app_log('provider', 'ref=' . $ref . ' start database failure: ' . $cause->getMessage()
                    . ' @ ' . $cause->getFile() . ':' . $cause->getLine() . "\n" . $cause->getTraceAsString());
                Session::flash('error', 'Không tạo được nhiệm vụ do lỗi hệ thống. Mã lỗi: ' . $ref . '. Vui lòng gửi mã này cho hỗ trợ.');
            } else {
                Session::flash('error', $e->getMessage());
            }
            redirect('/tasks');
        }
    }

    public static function returned(string $token): void
    {
        $user = require_user();
        header('Cache-Control: no-store');
        header('Referrer-Policy: no-referrer');
        try {
            Tasks::returned((int)$user['id'], $token);
            Session::flash('info', 'Đã ghi nhận quay lại. Nhiệm vụ đang chờ xác nhận hoặc admin đối chiếu và duyệt thưởng; chưa cộng tiền.');
        } catch (\Throwable $e) {
            \App\Risk::event((int)$user['id'], 'provider_return_invalid', 'medium', substr($e->getMessage(), 0, 200));
            Session::flash('error', $e->getMessage());
        }
        redirect('/tasks');
    }

    public static function verify(): void
    {
        $user = require_user();
        Csrf::check();
        if (!RateLimiter::attempt('provider_poll', (string)$user['id'], 10, 60)) {
            Session::flash('error', 'Vui lòng chờ trước khi kiểm tra lại.');
            redirect('/tasks');
        }
        try {
            $ok = Tasks::poll((int)$user['id'], (string)input('token', ''));
            Session::flash($ok ? 'success' : 'info', $ok ? 'Đã xác nhận và cộng phần thưởng.' : 'Chưa có xác nhận trả thưởng; tiếp tục chờ hoặc liên hệ hỗ trợ.');
        } catch (\Throwable $e) {
            Session::flash('error', 'Không kiểm tra được nhà cung cấp. Vui lòng thử lại sau.');
        }
        redirect('/tasks');
    }

    public static function callback(string $provider): void
    {
        try {
            $body = (string)file_get_contents('php://input', false, null, 0, 8193);
            $ok = Callback::process($provider, (string)($_SERVER['HTTP_X_POSTBACK_TIMESTAMP'] ?? ''), (string)($_SERVER['HTTP_X_POSTBACK_SIGNATURE'] ?? ''), $body);
            json_response(['ok' => $ok], $ok ? 200 : 409);
        } catch (\InvalidArgumentException $e) {
            json_response(['ok' => false], 403);
        } catch (\Throwable $e) {
            error_log('[provider callback] processing failed');
            json_response(['ok' => false], 503);
        }
    }

    public static function review(): void
    {
        AdminAuth::require();
        View::show('admin/providers', ['attempts' => Database::all('SELECT p.*, u.phone AS user_phone, u.full_name AS user_full_name FROM provider_attempts p LEFT JOIN users u ON u.id = p.user_id ORDER BY p.id DESC LIMIT 100'), 'providers' => Catalog::definitions()], 'admin');
    }

    public static function decide(): void
    {
        AdminAuth::require();
        Csrf::check();
        $token = (string)input('token', '');
        $note = trim((string)input('note', ''));
        $action = (string)input('action', '');
        if (strlen($note) < 10 || strlen($note) > 300 || !in_array($action, ['approve', 'reject'], true)) {
            Session::flash('error', 'Cần ghi chú bằng chứng (10–300 ký tự).');
            redirect('/admin/providers');
        }
        Database::begin();
        try {
            $attemptId = Database::value('SELECT id FROM provider_attempts WHERE token = ?', [$token]);
            // Credit explicitly joins this transaction so the audit is atomic too.
            if ($action === 'approve') {
                $ok = Tasks::credit($token, 'admin_review');
            } else {
                $ok = Database::run("UPDATE provider_attempts SET status = 'rejected' WHERE token = ? AND status = 'pending'", [$token])->rowCount() > 0;
            }
            Audit::log('admin', AdminAuth::id() ?? 0, 'provider_' . $action, 'pv:' . (int)$attemptId, ['note' => $note, 'changed' => $ok]);
            Database::commit();
            Session::flash('success', $ok ? 'Đã xử lý nhiệm vụ.' : 'Nhiệm vụ không còn khả dụng để xử lý.');
        } catch (\Throwable $e) {
            Database::rollback();
            Session::flash('error', 'Xử lý thất bại; không thay đổi số dư.');
        }
        redirect('/admin/providers');
    }
}