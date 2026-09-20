<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Session;
use App\Settings;
use App\View;

final class HomeController
{
    public static function landing(): void
    {
        if (Session::userId() !== null) {
            redirect('/dashboard');
        }
        View::show('home/landing', [
            'siteName'    => (string)Settings::get('site_name', 'HTXG.PRO'),
            'minWithdraw' => (int)Settings::get('min_withdraw_vnd', '50000'),
        ], 'base');
    }
}
