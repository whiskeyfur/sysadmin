<?php

namespace App\Controllers;

use App\DTOs\AuthContext;
use App\Services\KeyFileService;
use Leaf\Http\Headers;

/**
 * Admins download the key file to share with co-workers outside the
 * website. Generated in memory and never cached (CLAUDE.md).
 */
class KeyFileController extends Controller
{
    public function download()
    {
        /** @var AuthContext $auth */
        $auth = $this->request->next('auth');
        $keyFiles = new KeyFileService();

        // Sent directly rather than with withHeader(): Leaf's Response treats
        // an "attachment" Content-Disposition as a file path to stream from
        // disk, which would fail and put the key in the error page.
        Headers::set('Content-Disposition', 'attachment; filename="' . $keyFiles->filename() . '"');

        $this->response
            ->withHeader('Content-Type', 'application/json')
            ->withHeader('Cache-Control', 'no-store')
            ->custom($keyFiles->export((string) $auth->masterKey));
    }
}
