<h1>Confidențialitate</h1>
<p class="lead">Ce date păstrează cemainou când ai cont, de ce, cât timp și cine le vede. Pe scurt: doar ce trebuie ca echipa să știe la ce se lucrează, fără conținutul conversațiilor cu agentul și fără codul tău.</p>
<p class="updated">cemainou la {{ $f['site'] }}</p>

<h2>Cine răspunde de date</h2>
<p><b>{{ $f['operator'] }}</b>{{ $f['address'] ? ', '.$f['address'] : '' }}. Aplicația rulează pe serverul nostru; datele nu sunt vândute și nu sunt folosite pentru reclame.</p>
@if ($f['email'])<p>Pentru orice întrebare sau cerere despre datele tale: <a href="mailto:{{ $f['email'] }}">{{ $f['email'] }}</a>.</p>@endif

<h2>Cine își poate face cont</h2>
<p>Nimeni nu își face singur cont: îl primești doar prin invitație, de la un administrator. Echipa vede proiectele echipei; un om de la client vede doar proiectele firmei lui, pornite ca vizibile pentru client, și doar ce i se arată acolo.</p>

<h2>Ce date păstrăm</h2>
<ul>
    <li><b>Contul:</b> numele, emailul, parola (doar criptată, ireversibil), fusul orar, limba și preferințele de notificare; opțional autentificarea în doi pași.</li>
    <li><b>Ce scrii:</b> update-urile, sarcinile, comentariile, notele, documentele și fișierele pe care le adaugi, cu data și autorul.</li>
    <li><b>Timpul de lucru, de la agent:</b> dacă îți conectezi agentul (Claude Code, Cursor...), el trimite doar <b>când</b> lucrezi și pe ce proiect: ora și felul semnalului (sesiune pornită, mesaj, unealtă, oprire). <b>Nu trimite textul conversațiilor, nici codul, nici fișierele tale.</b> Din semnale se calculează orele pe zi.</li>
    <li><b>Ce raportează agentul:</b> la /upd, agentul îți arată ciorna și o trimite doar după ce o confirmi; ajunge doar ce vezi în ciornă (rândurile, numărul de commit-uri și de rânduri de cod, mesajele commit-urilor care pomenesc o sarcină).</li>
    <li><b>Jurnalul apelurilor agentului:</b> ce unealtă a folosit și când, pentru siguranță și limite.</li>
    <li><b>Notificările:</b> dacă le pornești, adresa dispozitivului dată de browser (pentru notificările pe telefon sau calculator) și, dacă îți legi contul, identificatorul tău de Telegram.</li>
    <li><b>Widget-ul de pe site</b> (pe proiectele care îl au): cu o sesizare se păstrează pagina, browserul, ecranul, elementul arătat și erorile paginii; vizitatorii fără cont lasă numele și emailul doar dacă vor.</li>
</ul>

<h2>Cât timp</h2>
<ul>
    <li>Semnalele brute de la agent și jurnalul apelurilor: <b>{{ $f['rawDays'] }} de zile</b>; după, rămân doar totalurile pe zi.</li>
    <li>Notificările din clopoțel: {{ $f['noticeDays'] }} de zile.</li>
    <li>Cheile agenților nefolosite {{ $f['keyDays'] }} de zile se revocă singure.</li>
    @if ($f['backupDays'])<li>Copiile de siguranță: ultimele {{ $f['backupDays'] }}, pe același server.</li>@endif
    <li>Ce s-a scris pe proiecte rămâne cu proiectul, ca istoria lui să fie întreagă. Contul rămâne cât lucrezi cu noi; când pleci, e oprit, iar datele tale personale (emailul, preferințele, dispozitivele, Telegram) se șterg sau se anonimizează.</li>
</ul>

<h2>Cine vede datele</h2>
<p>Colegii văd ce scrii pe proiectele pe care le văd și ei; orele le vede echipa (nu și clientul); un colaborator extern își vede doar orele lui. Clientul vede doar update-urile neinterne și ce i se arată pe proiectele lui, fără ore. Nimic nu pleacă la alții, în afară de serviciile de mai jos, doar ca să-și facă treaba:</p>
<ul>
    @if ($f['mail'])<li>serverul de email al echipei, pentru invitații, rezumate și rapoarte;</li>@endif
    <li>serviciul de notificări al browserului tău (Google, Apple sau Mozilla), doar pentru notificările pe care le-ai pornit;</li>
    @if ($f['telegram'])<li>Telegram, doar dacă îți legi contul sau dacă echipa trimite update-urile într-un grup;</li>@endif
    @if ($f['slack'])<li>Slack, unde ajung update-urile publicate ale echipei;</li>@endif
    @if ($f['feedbackToRt'] ?? false)<li>RT Design Studio, cei care fac cemainou: feedback-ul pe care îl trimiți despre aplicație (textul, pagina fără adresa acestui site, browserul, versiunea și fișierele; nu numele sau emailul tău);</li>@endif
    @if ($f['updateCheck'] ?? false)<li>rt.md, o dată pe zi, ca să vadă dacă există o versiune nouă de cemainou: pleacă doar numărul versiunii, nimic despre tine sau echipă;</li>@endif
</ul>
<p>Agentul tău (Claude, Cursor...) rulează la tine, cu contul tău: ce îi spui lui rămâne între tine și furnizorul lui.</p>

<h2>Cookie-uri</h2>
<p>Doar cele strict necesare: sesiunea (te ține logat; expiră după {{ $f['sessionMinutes'] }} de minute fără activitate), protecția formularelor și, dacă bifezi „Ține-mă minte", cel care te păstrează logat. Fără analytics, fără reclame, fără urmărire de la terți, de aceea nu cerem acord pentru cookie-uri.</p>

<h2>Drepturile tale</h2>
<p>Update-urile, sarcinile, comentariile și fișierele de pe proiecte sunt munca făcută pentru companie și pentru clienții ei: aparțin companiei și, unde e cazul, clientului proiectului, nu contului care le-a scris. Pentru datele tale personale (numele, emailul, orele tale, dispozitivele) poți cere oricând să vezi ce păstrăm și să corectezi ce e greșit. Mare parte le schimbi singur, din profil (numele, notificările, Telegram, agenții conectați). Pentru rest, {!! $f['email'] ? 'scrie-ne la <a href="mailto:'.e($f['email']).'">'.e($f['email']).'</a>' : 'scrie-ne' !!}; răspundem în cel mult 30 de zile. Dacă nu ești mulțumit, te poți adresa Centrului Național pentru Protecția Datelor cu Caracter Personal (Legea nr. 133/2011) sau autorității din țara ta.</p>

<h2>Cum se folosește</h2>
<ul>
    <li>Contul e al tău: nu-l împărți și ține-ți parola și cheile agenților doar pentru tine.</li>
    <li>Nu pune în cemainou parole, chei, date de card sau date personale ale altora; agentul e instruit să le lase deoparte.</li>
    <li>Ce scrii pe un proiect aparține companiei și, unde e cazul, clientului proiectului.</li>
    <li>Un administrator poate opri un cont, de exemplu când cineva pleacă din echipă.</li>
</ul>
