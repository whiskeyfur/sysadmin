<?php

namespace App\Controllers;

use App\DTOs\AuthContext;
use App\Exceptions\AuthorizationException;
use App\Models\Server;
use App\Services\DatabaseBrowserService;
use DomainException;

/**
 * The MariaDB browser (/mariadb/browse, everyone signed in): servers, then a server's databases, a
 * database's tables, and a table's definition and rows (paged in the browser from /mariadb/browse/rows).
 * Every level lists who has privileges there. Where you are is in the query string (server, as, schema,
 * table). See DatabaseBrowserService.
 */
class DatabaseBrowserController extends Controller
{
    /**
     * GET /mariadb/browse?server=&as=&schema=&table=
     */
    public function index()
    {
        $user = $this->authContext()->user;
        $service = new DatabaseBrowserService();
        $servers = $service->servers();
        $server = $this->server($servers);
        $data = [
            'auth' => $this->authContext(), 'servers' => $servers, 'server' => $server, 'logins' => [], 'as' => null,
            'schema' => null, 'table' => null, 'error' => null, 'schemas' => null, 'tables' => null, 'info' => null,
            'columns' => [], 'privileges' => null, 'loginsFor' => [],
        ];

        if (!$server instanceof Server) {
            foreach ($servers as $each) {
                $data['loginsFor'][$each->id] = $service->logins($user, $each) !== [];
            }

            $this->response->view('mariadb.browse', $data);

            return;
        }

        $data['logins'] = $service->logins($user, $server);
        $as = (string) $this->request->get('as', false);
        $data['as'] = in_array($as, array_column($data['logins'], 'value'), true) ? $as : $service->defaultLogin($user, $server);
        $schema = $this->name('schema');
        $table = $schema === null ? null : $this->name('table');
        $data['schema'] = $schema;
        $data['table'] = $table;

        if ($data['as'] === null) {
            $data['error'] = "None of your MariaDB accounts is for {$server->name}: add one in MariaDB › Query.";
            $this->response->view('mariadb.browse', $data);

            return;
        }

        try {
            $pdo = $service->connect($user, $server, $data['as']);

            if ($schema === null) {
                $data['schemas'] = $service->schemas($pdo);
            } elseif ($table === null) {
                $data['tables'] = $service->tables($pdo, $schema);
            } else {
                $data['info'] = $service->table($pdo, $schema, $table);
                $data['columns'] = $service->columns($pdo, $schema, $table);
            }

            $data['privileges'] = $service->privileges($pdo, $schema, $table);
        } catch (DomainException|AuthorizationException $e) {
            $data['error'] = $e->getMessage();
        }

        $this->response->view('mariadb.browse', $data);
    }

    /**
     * GET /mariadb/browse/rows?server=&as=&schema=&table=&page=&q=&sort=&dir= (JSON, for paged-table.js)
     */
    public function rows()
    {
        $user = $this->authContext()->user;
        $service = new DatabaseBrowserService();
        $server = $this->server($service->servers());
        $schema = $this->name('schema');
        $table = $this->name('table');

        if (!$server instanceof Server || $schema === null || $table === null) {
            $this->response->json(['error' => 'Choose a server, database and table.'], 404);

            return;
        }

        try {
            $pdo = $service->connect($user, $server, (string) $this->request->get('as', false));
            $page = $service->rows(
                $pdo,
                $schema,
                $table,
                max(1, (int) $this->request->get('page')),
                mb_substr((string) $this->request->get('q', false), 0, 200),
                (string) $this->request->get('sort', false),
                (string) $this->request->get('dir', false) === 'desc' ? 'desc' : 'asc',
            );
        } catch (DomainException|AuthorizationException $e) {
            $this->response->json(['error' => $e->getMessage()], 400);

            return;
        }

        $this->response->json([
            'html' => $this->view('mariadb.browse-rows', ['rows' => $page['rows'], 'columns' => $page['columns']]),
            'total' => $page['total'], 'page' => $page['page'], 'pages' => $page['pages'],
        ]);
    }

    /**
     * @param list<Server> $servers
     */
    private function server(array $servers): ?Server
    {
        $id = (int) $this->request->get('server');

        foreach ($servers as $server) {
            if ($server->id === $id) {
                return $server;
            }
        }

        return null;
    }

    /**
     * A database or table name from the query string (raw: names may hold any character), or null.
     */
    private function name(string $key): ?string
    {
        $name = (string) $this->request->get($key, false);

        return $name === '' || mb_strlen($name) > 64 ? null : $name;
    }

    private function authContext(): AuthContext
    {
        return $this->request->next('auth');
    }
}
