<?php

namespace App\Controllers;

use App\DTOs\AuthContext;
use App\Models\Server;
use App\Services\Fail2banService;
use DomainException;

/**
 * Ban or unban a client address with fail2ban on a server, from the access log (right-click on a client
 * address in the Apache and Vhost reports). JSON in and out; admins only. See Fail2banService.
 */
class Fail2banController extends Controller
{
    /**
     * GET /admin/servers/{id}/fail2ban/jails
     */
    public function jails($id)
    {
        $server = Server::query()->find((int) $id);

        if (!$server instanceof Server) {
            $this->response->json(['error' => 'That server no longer exists.'], 404);

            return;
        }

        try {
            $this->response->json(['jails' => (new Fail2banService())->jails($this->authContext()->user, $server)]);
        } catch (DomainException $e) {
            $this->response->json(['error' => $e->getMessage()], 400);
        }
    }

    /**
     * POST /admin/servers/{id}/fail2ban {action: ban|unban|protect|unprotect, ip, jail (ban/unban)}
     */
    public function change($id)
    {
        $server = Server::query()->find((int) $id);

        if (!$server instanceof Server) {
            $this->response->json(['error' => 'That server no longer exists.'], 404);

            return;
        }

        $action = (string) $this->request->get('action', false);
        $ip = (string) $this->request->get('ip', false);
        $jail = (string) $this->request->get('jail', false);

        try {
            $output = (new Fail2banService())->change($this->authContext()->user, $server, $action, $ip, $jail, $this->clientIp());

            if ($action === 'protect' || $action === 'unprotect') {
                $this->response->json(['message' => $output]);

                return;
            }

            $done = $action === 'ban' ? "Banned $ip in $jail on {$server->name}." : "Unbanned $ip in $jail on {$server->name}.";
            $this->response->json(['message' => $done . ($output !== '' && !ctype_digit($output) ? " fail2ban: $output" : '')]);
        } catch (DomainException $e) {
            $this->response->json(['error' => $e->getMessage()], 400);
        }
    }

    private function authContext(): AuthContext
    {
        return $this->request->next('auth');
    }
}
