<?php

namespace App\Controllers;

use App\DTOs\AuthContext;
use App\Exceptions\AuthorizationException;
use App\Models\CsrRequest;
use App\Models\SslCertificate;
use App\Services\CsrService;
use DomainException;
use Leaf\Http\Headers;

/**
 * The CSR tool (SSL › CSR, /admin/csr, admins): request a replacement for a certificate. See CsrService.
 */
class CsrController extends Controller
{
    use ConfirmsIdentity;

    /**
     * GET /admin/csr[?certificate=ID]: the form (prefilled from a monitored certificate) and past requests.
     */
    public function index()
    {
        $id = (int) $this->request->get('certificate');

        if ($id <= 0) {
            $this->show();

            return;
        }

        $certificate = SslCertificate::query()->find($id);

        try {
            if (!$certificate instanceof SslCertificate) {
                throw new DomainException('No such certificate.');
            }

            $this->show(from: (new CsrService())->fromMonitored($this->authContext()->user, $certificate), certificate: $certificate);
        } catch (DomainException $e) {
            $this->show(error: $e->getMessage(), certificate: $certificate instanceof SslCertificate ? $certificate : null);
        }
    }

    /**
     * POST /admin/csr/read {certificate_pem}: prefill the form from a pasted certificate.
     */
    public function read()
    {
        try {
            $this->show(from: (new CsrService())->describe((string) $this->request->get('certificate_pem', false)));
        } catch (DomainException $e) {
            $this->show(error: $e->getMessage());
        }
    }

    /**
     * POST /admin/csr {common_name, names, subject[], key, private_key, certificate_pem, certificate_id}
     */
    public function create()
    {
        $input = [
            'common_name' => (string) $this->request->get('common_name', false),
            'names' => (string) $this->request->get('names', false),
            'subject' => (array) ($this->request->get('subject', false) ?? []),
            'key' => (string) $this->request->get('key', false),
            'private_key' => (string) $this->request->get('private_key', false),
            'certificate_pem' => (string) $this->request->get('certificate_pem', false),
            'certificate_id' => $this->request->get('certificate_id', false),
        ];

        try {
            $request = (new CsrService())->create($this->authContext()->user, $input);
        } catch (DomainException|AuthorizationException $e) {
            // The form again as it was, but never the pasted private key.
            $this->show(error: $e->getMessage(), input: array_diff_key($input, ['private_key' => true]));

            return;
        }

        $this->response->withFlash('notice', $request->key_source === 'new'
            ? 'Request made, with a new private key: download both. The key stays here, encrypted, until you delete the request.'
            : 'Request made with your existing private key (not kept here).')->redirect("/admin/csr/{$request->id}");
    }

    /**
     * GET /admin/csr/{id}
     */
    public function details($id)
    {
        $request = CsrRequest::query()->with(['user', 'certificate'])->find((int) $id);

        if (!$request instanceof CsrRequest) {
            $this->response->withFlash('error', 'No such request.')->redirect('/admin/csr');

            return;
        }

        $this->response->view('admin.csr.show', [
            'auth' => $this->authContext(),
            'request' => $request,
            'notice' => $this->request->flash('notice'),
            'error' => $this->request->flash('error'),
        ] + $this->confirmFields());
    }

    /**
     * GET /admin/csr/{id}/csr: the request as a file.
     */
    public function downloadCsr($id)
    {
        $request = CsrRequest::query()->find((int) $id);

        if (!$request instanceof CsrRequest) {
            $this->response->redirect('/admin/csr');

            return;
        }

        $this->download($request->csr, self::fileName($request) . '.csr', 'application/pkcs10');
    }

    /**
     * POST /admin/csr/{id}/key {acct_password or passkey_confirmed}: the new private key as a file, after a
     * fresh check of the admin's password or passkey.
     */
    public function downloadKey($id)
    {
        $request = CsrRequest::query()->find((int) $id);

        if (!$request instanceof CsrRequest) {
            $this->response->redirect('/admin/csr');

            return;
        }

        if (($refused = $this->confirmIdentity(codes: false, passwordField: 'acct_password')) !== null) {
            $this->response->withFlash('error', $refused)->redirect("/admin/csr/{$request->id}");

            return;
        }

        try {
            $key = (new CsrService())->privateKey($this->authContext()->user, $request);
        } catch (DomainException|AuthorizationException $e) {
            $this->response->withFlash('error', $e->getMessage())->redirect("/admin/csr/{$request->id}");

            return;
        }

        $this->download($key, self::fileName($request) . '.key', 'application/x-pem-file');
    }

    /**
     * POST /admin/csr/{id}/delete
     */
    public function delete($id)
    {
        $request = CsrRequest::query()->find((int) $id);

        if ($request instanceof CsrRequest) {
            (new CsrService())->delete($this->authContext()->user, $request);
        }

        $this->response->withFlash('notice', 'Request deleted' . ($request?->key_source === 'new' ? ', with its private key.' : '.'))->redirect('/admin/csr');
    }

    /**
     * A file name from the common name: www.example.com-2026-09-30 (a wildcard's * as "wildcard").
     */
    private static function fileName(CsrRequest $request): string
    {
        return preg_replace('/[^a-z0-9.-]+/', '_', str_replace('*', 'wildcard', $request->common_name)) . '-' . $request->created_at->format('Y-m-d');
    }

    /**
     * An in-memory download: Leaf's Response would take a Content-Disposition header as a file path.
     */
    private function download(string $body, string $name, string $type): void
    {
        Headers::set([
            'Content-Type' => $type,
            'Content-Disposition' => 'attachment; filename="' . $name . '"',
            'Cache-Control' => 'no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
        echo $body;
    }

    /**
     * @param array<string, mixed>|null $from a certificate's details (CsrService::describe()) to prefill from
     * @param array<string, mixed> $input the form as sent, after a refusal
     */
    private function show(?array $from = null, ?SslCertificate $certificate = null, ?string $error = null, array $input = []): void
    {
        $this->response->view('admin.csr.index', [
            'auth' => $this->authContext(),
            'from' => $from,
            'certificate' => $certificate,
            'certificates' => SslCertificate::query()->orderBy('name')->get()->all(),
            'input' => $input,
            'requests' => CsrRequest::query()->with(['user', 'certificate'])->orderByDesc('id')->limit(50)->get()->all(),
            'error' => $error ?? $this->request->flash('error'),
            'notice' => $this->request->flash('notice'),
        ]);
    }

    private function authContext(): AuthContext
    {
        return $this->request->next('auth');
    }
}
