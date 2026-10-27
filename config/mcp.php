<?php

/*
 * Serverul MCP (laravel/mcp). Conectorii OAuth se pot înregistra singuri (dynamic client registration), dar se întorc doar la adresele de aici:
 * aplicațiile Claude și ChatGPT, plus localhost (dezvoltare); orice altă adresă e refuzată, ca un link străin să nu poată cere un token în numele tău.
 */
return [
    'redirect_domains' => array_values(array_filter(array_map('trim', explode(',', (string) env('MCP_REDIRECT_DOMAINS',
        'https://claude.ai,https://claude.com,https://chatgpt.com,https://chat.openai.com,http://localhost,http://127.0.0.1'))))),

    // aplicațiile de pe calculator (Claude Desktop, Cursor, VS Code) se întorc la o schemă proprie, nu la https
    'custom_schemes' => ['claude', 'cursor', 'vscode'],

    'authorization_server' => null,

    'tool_search' => [
        'max_tool_calls' => 10,
        'max_output_bytes' => 65_536,
    ],
];
