<?php

return [

    /*
     * Variabilele din .env se cheamă CEMAINOU_*; cele vechi, WHATSUPAI_*, se citesc mai departe pentru instalările de dinainte de 0.1.257.
     *
     * Versiunea aplicației (pagina Setări, serverul MCP).
     */
    'version' => '0.1.402',

    /*
     * În spatele unui proxy (imaginea Docker, Cloudflare): TRUSTED_PROXIES=* sau o listă de adrese, ca IP-ul și https să fie cele reale.
     * Citit aici (nu în bootstrap), ca să meargă și cu configurația pusă în cache.
     */
    'trusted_proxies' => env('TRUSTED_PROXIES'),

    /*
     * Versiunile noi: lista publică de pe rt.md (versiunea, data, arhiva, noutățile), citită o dată pe zi dacă adminul n-a oprit verificarea din Setări.
     * O arhivă încărcată se instalează doar dacă lista ei de fișiere (release.json) poartă semnătura RT, verificată cu cheia publică de mai jos
     * (fixă în cod, nu în .env).
     */
    'release_feed' => env('CEMAINOU_RELEASE_FEED', env('WHATSUPAI_RELEASE_FEED', 'https://rt.md/cemainou/releases.json')),
    'release_key' => 'MC/wjvXfCal7h8n8EaScCBtKJ2poKaR/S53x52XaorI=',

    /*
     * Licența (LICENSE.md): o instalare e gratuită cu cel mult 5 oameni; peste, cu o cheie de licență pentru acea instalare, semnată de RT (Setări → Licență),
     * verificată cu cheia publică de mai jos (fixă în cod). Fără cheie validă, aplicația merge mai departe, doar versiunile noi nu se mai instalează.
     */
    'license_public_key' => 'xQGKZWbYWjXf/Kk/EO/XWiP/BHboRHvF8PP6krQy4gE=',
    'free_people' => 5,

    /*
     * Instanța demo publică (CEMAINOU_DEMO=true): date inventate, intrare dintr-un clic ca unul dintre oamenii demo (niciunul admin), fără fișiere
     * și fără schimbarea parolei; totul se reface în fiecare noapte (cemainou:demo-reset, programat).
     */
    'demo' => (bool) env('CEMAINOU_DEMO', false),
    'demo_locale' => env('CEMAINOU_DEMO_LOCALE', 'ro'),              // limba datelor inventate și a echipei demo (ro sau en)

    /*
     * Cererile de acces de pe pagina publică (formularul „Cere acces" de pe rt.md/cemainou): fiecare devine o sarcină pe proiectul cu slug-ul de
     * mai jos, dată liderului lui. Doar pe instanța RT; gol = adresa nu există.
     */
    'access_requests' => env('CEMAINOU_ACCESS_REQUESTS'),

    /*
     * Feedback-ul centralizat (opțional, din Setări): instalările care aleg își trimit feedback-ul despre cemainou la instanța RT, la adresa de mai jos.
     * feedback_inbox = instanța aceasta primește (doar la RT, în .env: CEMAINOU_FEEDBACK_INBOX=true).
     */
    'feedback_upstream' => env('CEMAINOU_FEEDBACK_UPSTREAM', 'https://whatsupai.rt.md/api/feedback/upstream'),
    'install_logs' => env('CEMAINOU_INSTALL_LOGS'),   // doar la RT: jurnalele rt.md, pentru câte instalări verifică versiunile (cemainou:install-estimate)
    'installations_upstream' => env('CEMAINOU_INSTALLATIONS_UPSTREAM', 'https://whatsupai.rt.md/api/installations'),   // „Ține-mă la curent” (App\Support\KeepInTouch), doar la cererea adminului
    'feedback_inbox' => (bool) env('CEMAINOU_FEEDBACK_INBOX', false),
    // statistici de vizite fără cookie-uri (Umami), doar pe instanțele care le pornesc; goale = nimic încărcat (implicit, și în arhiva publică)
    'umami_src' => env('CEMAINOU_UMAMI_SRC'),
    'umami_id' => env('CEMAINOU_UMAMI_ID'),
    // doar la RT: rezumatul săptămânal din Umami, ca notiță pe un proiect (cemainou:umami-weekly); cheia SSH poate rula doar scriptul de rezumat
    'umami_summary_ssh' => env('CEMAINOU_UMAMI_SUMMARY_SSH'),
    'umami_summary_key' => env('CEMAINOU_UMAMI_SUMMARY_KEY', getenv('HOME').'/.ssh/umami_summary'),
    'umami_summary_project' => env('CEMAINOU_UMAMI_SUMMARY_PROJECT'),

    /*
     * Unde se păstrează backup-urile (implicit data/backups, lângă baza de date și fișiere).
     */
    'backup_path' => env('CEMAINOU_BACKUP_PATH', env('WHATSUPAI_BACKUP_PATH')),

    /*
     * Câte cereri MCP poate face un om pe minut (toți agenții lui la un loc). Un agent care o ia razna nu blochează serverul.
     */
    'mcp_per_minute' => (int) env('CEMAINOU_MCP_PER_MINUTE', env('WHATSUPAI_MCP_PER_MINUTE', 120)),

    /*
     * Fusul orar implicit al oamenilor noi (fiecare și-l poate schimba din profil). La instalare devine o alegere.
     */
    'timezone' => env('CEMAINOU_TIMEZONE', env('WHATSUPAI_TIMEZONE', 'Europe/Chisinau')),

    /*
     * Câte cereri pe minut poate face o adresă IP la MCP și la bătaia de inimă, înainte de autentificare (zgomot fără cheie, agent defect).
     */
    'mcp_ip_per_minute' => (int) env('CEMAINOU_MCP_IP_PER_MINUTE', env('WHATSUPAI_MCP_IP_PER_MINUTE', 300)),

    /*
     * După câte zile fără folosire se revocă singură cheia unui agent (laptopuri vechi, dispozitive uitate). Cheile folosite nu expiră.
     */
    'token_idle_days' => (int) env('CEMAINOU_TOKEN_IDLE_DAYS', env('WHATSUPAI_TOKEN_IDLE_DAYS', 90)),

    /*
     * Câte zile se păstrează semnalele brute de activitate și jurnalul de apeluri MCP. Zilele de lucru rămân ca rezumat (work_days).
     */
    'keep_raw_days' => (int) env('CEMAINOU_KEEP_RAW_DAYS', env('WHATSUPAI_KEEP_RAW_DAYS', 90)),

];
