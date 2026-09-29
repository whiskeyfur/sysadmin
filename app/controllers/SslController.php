<?php

namespace App\Controllers;

use App\DTOs\AuthContext;
use App\Models\Server;
use App\Models\SslBinding;
use App\Models\SslCertificate;
use App\Services\ServerService;
use App\Services\SettingsService;
use App\Services\SslMonitorService;
use DomainException;

/**
 * SSL monitoring: certificates (a name and the hostnames they cover) and
 * where each is served (server + port, or directly via DNS). Everyone can
 * view; admins manage and run checks.
 */
class SslController extends Controller
{
    private readonly SslMonitorService $ssl;

    public function __construct()
    {
        parent::__construct();

        $this->ssl = new SslMonitorService();
    }

    public function index()
    {
        $this->ssl->convertLegacy();
        $auth = $this->authContext();

        $this->response->view('ssl.index', [
            'auth' => $auth,
            'certificates' => $this->ssl->certificates(),
            'warningDays' => (new SettingsService())->sslWarningDays(),
            'servers' => $auth->isAdmin() ? (new ServerService())->all() : [],
            'notice' => $this->request->flash('notice'),
            'error' => $this->request->flash('error'),
        ]);
    }

    public function show($id)
    {
        $certificate = $this->findOrRedirect($id);

        if ($certificate !== null) {
            $this->renderShow($certificate);
        }
    }

    public function store()
    {
        try {
            $certificate = $this->ssl->createCertificate($this->authContext()->user, $this->input() + [
                'server_id' => $this->request->get('server_id'),
                'port' => $this->request->get('port'),
            ]);
            $checked = $this->ssl->checkCertificate($this->authContext()->user, $certificate);
        } catch (DomainException $e) {
            $this->response->withFlash('error', $e->getMessage())->redirect('/ssl');

            return;
        }

        $this->response->withFlash('notice', "Added {$certificate->name} and checked it ($checked place(s)).")->redirect("/ssl/{$certificate->id}");
    }

    public function update($id)
    {
        $certificate = $this->findOrRedirect($id);

        if ($certificate === null) {
            return;
        }

        try {
            $this->ssl->updateCertificate($this->authContext()->user, $certificate, $this->input());
        } catch (DomainException $e) {
            $this->response->withFlash('error', $e->getMessage())->redirect("/ssl/{$certificate->id}");

            return;
        }

        $this->response->withFlash('notice', 'Saved.')->redirect("/ssl/{$certificate->id}");
    }

    public function delete($id)
    {
        $certificate = $this->findOrRedirect($id);

        if ($certificate !== null) {
            $this->ssl->deleteCertificate($this->authContext()->user, $certificate);
            $this->response->withFlash('notice', "Deleted {$certificate->name}.")->redirect('/ssl');
        }
    }

    public function addBinding($id)
    {
        $certificate = $this->findOrRedirect($id);

        if ($certificate === null) {
            return;
        }

        $serverId = (int) $this->request->get('server_id');
        $server = $serverId > 0 ? Server::query()->find($serverId) : null;

        try {
            $binding = $this->ssl->addBinding($this->authContext()->user, $certificate, $server instanceof Server ? $server : null, $this->request->get('port'));
            $this->ssl->checkCertificate($this->authContext()->user, $certificate);
        } catch (DomainException $e) {
            $this->response->withFlash('error', $e->getMessage())->redirect("/ssl/{$certificate->id}");

            return;
        }

        $this->response->withFlash('notice', 'Added ' . $binding->fresh(['server'])->label() . ' and checked.')->redirect("/ssl/{$certificate->id}");
    }

    public function removeBinding($id, $bindingId)
    {
        $certificate = $this->findOrRedirect($id);
        $binding = SslBinding::query()->with(['certificate', 'server'])->where('certificate_id', (int) $id)->find((int) $bindingId);

        if ($certificate === null) {
            return;
        }

        if ($binding instanceof SslBinding) {
            $this->ssl->removeBinding($this->authContext()->user, $binding);
        }

        $this->response->withFlash('notice', 'Removed.')->redirect("/ssl/{$certificate->id}");
    }

    public function check($id)
    {
        $certificate = $this->findOrRedirect($id);

        if ($certificate !== null) {
            $count = $this->ssl->checkCertificate($this->authContext()->user, $certificate);
            $this->response->withFlash('notice', "Checked $count place(s).")->redirect("/ssl/{$certificate->id}");
        }
    }

    public function checkAll()
    {
        $count = $this->ssl->checkAll($this->authContext()->user);

        $this->response->withFlash('notice', $count === 0 ? 'No certificates are attached to a server yet.' : "Checked $count certificate/server pair(s).")->redirect('/ssl');
    }

    private function renderShow(SslCertificate $certificate): void
    {
        $auth = $this->authContext();
        $certificate->load('bindings.server');

        $this->response->view('ssl.show', [
            'auth' => $auth,
            'certificate' => $certificate,
            'ssl' => $this->ssl,
            'servers' => $auth->isAdmin() ? (new ServerService())->all() : [],
            'notice' => $this->request->flash('notice'),
            'error' => $this->request->flash('error'),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function input(): array
    {
        return [
            'name' => $this->request->get('name', false),
            'hostnames' => $this->request->get('hostnames', false),
            'notes' => $this->request->get('notes', false),
        ];
    }

    private function findOrRedirect($id): ?SslCertificate
    {
        $certificate = SslCertificate::query()->find((int) $id);

        if (!$certificate instanceof SslCertificate) {
            $this->response->withFlash('error', 'That certificate no longer exists.')->redirect('/ssl');

            return null;
        }

        return $certificate;
    }

    private function authContext(): AuthContext
    {
        return $this->request->next('auth');
    }
}
