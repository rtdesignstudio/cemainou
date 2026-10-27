<?php

namespace App\Http\Controllers;

use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Fișierele pentru agent (comanda /upd, comenzile vechi, scripturile de instalare și de hook), servite ca atare și fără cache: după un update
 * al aplicației, versiunea nouă imediat. Un controller, nu funcții în rute: rutele puse în cache (route:cache) nu păstrau funcțiile imbricate,
 * iar fișierele răspundeau cu 500.
 */
class AgentFileController
{
    public const FILES = [
        'upd.md' => 'text/markdown; charset=UTF-8', 'brief.md' => 'text/markdown; charset=UTF-8', 'history.md' => 'text/markdown; charset=UTF-8',
        'ctx.md' => 'text/markdown; charset=UTF-8', 'idea.md' => 'text/markdown; charset=UTF-8', 'task.md' => 'text/markdown; charset=UTF-8',
        'work.md' => 'text/markdown; charset=UTF-8', 'install.sh' => 'text/x-shellscript; charset=UTF-8', 'install-cursor.sh' => 'text/x-shellscript; charset=UTF-8', 'connect.sh' => 'text/x-shellscript; charset=UTF-8',
        'heartbeat.sh' => 'text/x-shellscript; charset=UTF-8', 'session-start.sh' => 'text/x-shellscript; charset=UTF-8', 'update.sh' => 'text/x-shellscript; charset=UTF-8',
    ];

    public function __invoke(string $file): BinaryFileResponse
    {
        abort_unless(isset(self::FILES[$file]), 404);

        return response()->file(resource_path("agent/{$file}"), ['Content-Type' => self::FILES[$file], 'Cache-Control' => 'no-cache']);
    }
}
