<?php

namespace App\Controllers;

use App\DTOs\AuthContext;
use App\Services\SettingsService;
use App\Services\SslMonitorService;

/**
 * SSL monitoring: every monitored certificate across all servers.
 */
class SslController extends Controller
{
    public function index()
    {
        /** @var AuthContext $auth */
        $auth = $this->request->next('auth');

        $this->response->view('ssl.index', [
            'auth' => $auth,
            'certificates' => (new SslMonitorService())->latest(),
            'warningDays' => (new SettingsService())->sslWarningDays(),
            'notice' => $this->request->flash('notice'),
        ]);
    }

    public function checkAll()
    {
        /** @var AuthContext $auth */
        $auth = $this->request->next('auth');
        $count = (new SslMonitorService())->checkAll($auth->user);

        $this->response->withFlash('notice', $count === 0 ? 'No servers have SSL monitoring turned on.' : "Checked $count certificate(s).")->redirect('/ssl');
    }
}
