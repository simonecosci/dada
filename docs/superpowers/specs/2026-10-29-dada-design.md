# dada — Design

Data: 2026-10-29
Stato: bozza da approvare

## 1. Cos'è e perché

**dada** è un'applicazione web open source per la Continuous Delivery di stack Docker su server propri. Si definiscono **progetti** (es. "wordpress") con **versioni**. Una versione si installa su un host di un **deployment**, e da lì l'app genera i file di deploy (`docker-compose.yml`, `.env`, `.env.<progetto>`, script di restart). L'app li carica via SSH/SFTP ed esegue i comandi remoti. Tutto avviene con job asincroni e log in tempo reale.

Il concetto viene da uno strumento interno proprietario (admink-deploy) che l'utente conosce bene. dada è una **riscrittura da zero, indipendente**:

- nessun legame con admink-deploy o admink-release;
- nessuna sincronizzazione con sistemi esterni;
- nessun codice riusato.

I progetti nascono dentro dada.

Del vecchio strumento si tengono i concetti buoni:

- gerarchia deployment → host → installazioni;
- copia modificabile per host;
- variabili obbligatorie;
- script di restart per servizio;
- compose/swarm.

Si evitano questi suoi difetti:

- yaml costruito a pezzi di stringa con segnaposto e regex;
- fallback silenziosi su errori di parsing;
- nomi cablati (`kplatform`, servizio `queue` speciale, chiavi `VERSION_*`);
- password della webssh passata in query string;
- servizi monolitici da 1000 righe.

### Successo

Il primo traguardo è il **ciclo completo**:

1. creo un progetto e una versione;
2. registro credenziale e host (anche dietro bastion);
3. creo un deployment e installo la versione su un host;
4. modifico variabili e yaml;
5. genero i file;
6. deploy con log live;
7. restart dei servizi.

Il resto (webssh, monitoring, manutenzioni, MCP) segue in fasi successive.

### Non-obiettivi

- **Gestione VPN.** Il container di dada deve già stare in una rete che raggiunge gli host o i loro bastion.
- **Multi-tenant (più organizzazioni isolate).** È un'installazione per un team.
- **Build delle immagini dei progetti gestiti.** dada deploya immagini esistenti e non fa CI dei siti dei clienti.
- **Kubernetes.** Solo Docker Compose e Docker Swarm.
- **Canali di alert diversi dalla mail**, in questa versione.

### Anti-obiettivi (fallimento anche se "funziona")

- Generare yaml non valido senza dirlo. Ogni errore di parsing o validazione blocca la generazione con un messaggio chiaro.
- Mostrare segreti a chi non ha il permesso, o scriverli nei log dei job.
- Sovrascrivere le modifiche fatte su un'installazione in dada durante un aggiornamento di versione senza che l'utente le abbia viste e accettate. Il rilevamento di modifiche fatte a mano sui file del server, fuori da dada, è fuori dal primo traguardo: dada è la fonte di verità e il backup pre-deploy le conserva. _[scelta del designer]_
- Due deploy concorrenti sullo stesso deployment.

## 2. Utenti e permessi

È un team con ruoli diversi. I permessi sono **per deployment**.

- **Admin** (globale): gestisce utenti, credenziali e host, crea deployment ed esegue ogni azione su ogni deployment.
- **Ruoli per deployment**, assegnati utente × deployment. Ogni ruolo include quelli sotto.
    - **Operator**:
        - installa, aggiorna versioni, modifica le installazioni;
        - genera e deploya, fa restart, lancia comandi del progetto;
        - usa la webssh sugli host del deployment;
        - **aggiunge e rimuove host** del deployment, scegliendoli tra gli host registrati;
        - **gestisce i membri** del deployment, assegnando al massimo il ruolo Operator.
    - **Viewer**: vede tutto (stato, file generati, log, registrazioni) tranne i valori dei segreti, che vede mascherati.
- **Maintainer** (globale, permesso aggiuntivo): crea e modifica progetti e versioni del catalogo, inclusi i default dei segreti.

Creare un nuovo deployment è da Admin. _[scelta del designer: chi lo crea diventa Operator; se lo si vuole aperto a tutti è un flag di configurazione]_

**Segreti in chiaro**: Operator, Maintainer (sul catalogo) e Admin possono rivelarli con un pulsante "mostra". Ogni rivelazione viene registrata con utente, segreto e ora. Le API restituiscono i segreti mascherati; la rivelazione è un endpoint dedicato. L'MCP non li restituisce mai.

Un utente senza ruolo su un deployment non lo vede.

I token API/MCP (Sanctum personal access token) ereditano i permessi del loro utente. Al token si possono restringere le _abilities_, per esempio sola lettura.

## 3. Modello di dominio

### Catalogo

**Project**

- nome, slug (usato nei nomi file: `.env.<slug>`, `<slug>.sh`), descrizione, icona opzionale.

**ProjectVersion**

- appartiene a un Project;
- `version` (stringa libera, es. `1.2.0`), note di rilascio;
- **services**: la definizione compose dei servizi del progetto, salvata strutturata (JSON/array, editata come YAML nell'editor ma validata e parsata al salvataggio);
- top-level aggiuntivi opzionali: volumes, networks, configs;
- **variables**: lista di variabili;
- **commands**: lista di comandi predefiniti;
- stato: `draft` / `published`. Solo le versioni pubblicate si installano. _[scelta del designer]_

Una versione pubblicata è immutabile; per cambiarla si clona. _[scelta del designer, serve per rendere affidabile il diff di aggiornamento]_

**Clona versione** crea una nuova versione `draft` che copia services, variabili e comandi della sorgente.

**Variable** (dentro una versione o un'installazione)

- `key`, `default`, `description`;
- `required` (bool): il deploy è bloccato se il valore effettivo è vuoto;
- `secret` (bool): cifrato a riposo, mascherato in UI/API/log;
- `scope`:
    - `container`: va in `.env.<slug>`, cioè dentro il container tramite `env_file`;
    - `compose`: va in `.env`, per l'interpolazione `${VAR}` nello yaml;
    - `host-global`: va in `.env` ed è condivisa tra le installazioni dell'host.

**Command** (dentro una versione o un'installazione)

- nome, descrizione;
- `target`: `service:<nome>`, eseguito con `docker compose exec` / `docker exec` sul task, oppure `host`, eseguito nella cartella di installazione;
- `command` (stringa);
- `dangerous` (bool): richiede conferma esplicita in UI e in MCP.

### Infrastruttura

**Credential**

- nome, tipo `password` | `ssh-key` (chiave privata + passphrase opzionale) | `registry` (URL del registry, username, password/token), username opzionale;
- dati cifrati con cast `encrypted` di Laravel (APP_KEY);
- mai restituiti in chiaro dalle API.

**Host**

- nome, hostname/IP, porta, username, credential;
- `jump_host_id` opzionale (FK su Host). I salti si possono concatenare; sono vietati i cicli;
- `sudo` (comando prefisso, es. `sudo -n`, vuoto = nessuno);
- fingerprint della chiave host: memorizzata al primo collegamento (TOFU), poi verificata; se cambia, la connessione fallisce con un errore esplicito;
- stato: raggiungibile/irraggiungibile, ultimo check, info raccolte (OS, versione docker, CPU, RAM, disco).

### Installazioni

**Deployment**

- nome, descrizione, ambiente (etichetta libera, es. production/staging);
- membri con ruolo.

**DeploymentHost**

- un Host dentro un Deployment;
- `path` (cartella di installazione), `orchestrator`: `compose` | `swarm`;
- `stack_name` (per swarm, default = slug del deployment);
- **credenziali registry** associate (zero o più Credential di tipo `registry`);
- variabili `host-global` dell'host (con lo stesso schema Variable);
- **template di compose** opzionale: top-level networks/volumes condivisi da tutte le installazioni dell'host;
- lo stesso Host fisico può comparire in più deployment con path diversi.

**Installation**

- una ProjectVersion installata su un DeploymentHost: è una **copia completa e modificabile** di services, variabili e comandi;
- riferimento alla ProjectVersion d'origine (per il diff di aggiornamento);
- `slug` proprio dell'installazione, con default lo slug del progetto e unico sul DeploymentHost. I nomi file `.env.<slug>` e `<slug>.sh` usano questo slug, quindi lo stesso progetto si può installare due volte sullo stesso host (es. due wordpress), purché i nomi dei servizi non collidano. _[scelta del designer]_
- `order` (ordine nel restart);
- per ogni variabile, il valore impostato sull'installazione oppure "usa il default". Serve per distinguere "lasciato al default" da "modificato".

## 4. Generazione dei file

Per ogni DeploymentHost, dada genera in memoria un insieme di file. Li mostra in anteprima (con segreti mascherati per chi non può vederli) e li carica al deploy.

### docker-compose.yml

È costruito **come struttura dati** e serializzato una volta sola con `symfony/yaml`:

1. si parte dal template dell'host (networks/volumes condivisi);
2. si uniscono i services di ogni installazione in `order`. Una collisione di nomi servizio tra installazioni è un errore bloccante;
3. a ogni servizio con variabili `container` si aggiunge `env_file: .env.<slug>`;
4. si uniscono i top-level volumes/networks delle installazioni;
5. si valida il risultato: struttura compose minima e riferimenti a network/volumi dichiarati. Opzionale: `docker compose config -q` eseguito sull'host prima dell'up.

Non c'è nessun segnaposto testuale: le variabili restano `${VAR}` nel yaml e sono risolte da Docker leggendo `.env`.

### .env

Contiene:

- tutte le variabili `compose` di tutte le installazioni dell'host;
- le `host-global`.

Un conflitto di chiave tra installazioni con valori diversi è un errore bloccante, che l'utente risolve spostando la variabile a `host-global`.

**Variabili `host-global` dichiarate da una versione** _[scelta del designer]_:

- **All'installazione**: se la chiave non esiste ancora sul DeploymentHost, viene creata lì con default e flag della versione. Se esiste già, si tiene quella dell'host e si segnala la differenza di default o di flag. `required` e `secret` si sommano in OR.
- **Dopo**: la variabile vive sull'host e non sull'installazione.
- **Rimozione o aggiornamento dell'installazione**: la variabile non viene cancellata, ma segnalata come "non più usata" se nessuna installazione la dichiara.

### .env.<slug>

Uno per installazione, con le variabili `container`.

### Segreti

Nella prima versione i segreti finiscono nei file `.env*` sull'host, con permessi `600`, owner l'utente di deploy. _[scelta del designer: è semplice e compatibile con qualsiasi immagine]_

I Docker secrets (`/run/secrets`, `*_FILE`) sono un'evoluzione futura, fuori dal primo traguardo.

### Script

Sono generati da template Blade.

- **`<slug>.sh`**: uno per installazione. Uso: `./<slug>.sh start|stop|restart|status [servizio|all]`.
    - **compose**: `docker compose up -d <servizi>` / `stop` + `rm -f` / `ps`.
    - **swarm**:
        - `start` / `restart`: `docker service update --force <stack>_<servizio>`, oppure scale a N repliche se il servizio era stato fermato;
        - `stop`: `docker service scale <stack>_<servizio>=0`;
        - `status`: `docker service ps`.
        - Il numero di repliche da ripristinare viene dalla definizione del servizio.
    - I servizi marcati come _init_ (proprietà del servizio nella versione, es. migrazioni one-shot) vengono eseguiti e attesi prima degli altri in `start`/`restart`. Un exit code diverso da 0 interrompe lo script.
        - In compose: `docker compose run --rm <init>`.
        - In swarm: `docker run --rm` sul manager, con la stessa immagine, gli stessi env file e la rete del servizio (rete overlay `attachable`).
        - Gli init non fanno parte dello stack.
- **`restart.sh`**: unico per host. Uso: `./restart.sh start|stop|restart|status`.
    - In **compose** chiama in `order` gli script delle installazioni.
    - In **swarm** carica `.env` (perché `stack deploy` non lo legge da solo), esegue gli init, poi `docker stack deploy -c docker-compose.yml --with-registry-auth --prune <stack>`.
    - Non distrugge e ricrea lo swarm. L'inizializzazione dello swarm (`docker swarm init`) è un'azione separata ed esplicita in UI.

### Swarm: topologia

Ogni DeploymentHost in modalità swarm è un **manager**. dada gli invia i file e lancia lì lo stack. Eventuali worker del cluster non sono gestiti da dada e non ricevono file. Non esiste un oggetto "cluster": un deployment swarm con più host significa più manager, ognuno con il proprio stack. _[interpretazione della risposta "ogni host è un manager"]_

### Installazione rimossa

Quando si rimuove un'installazione da un DeploymentHost, la scheda del deploy successivo mostra un avviso esplicito: "verranno fermati e rimossi i servizi X, Y". Al deploy confermato succede questo:

- **compose**: `up --remove-orphans`;
- **swarm**: `stack deploy --prune`;
- vengono cancellati `.env.<slug>` e `<slug>.sh`.

_[scelta del designer]_

## 5. Aggiornamento di versione

Un'installazione su `wordpress 1.0` viene portata a `1.1`. dada mostra:

- **Variabili**:
    - **aggiunte**: saranno aggiunte con il loro default;
    - **rimosse**: saranno rimosse;
    - **default cambiato**: se sull'installazione la variabile era "usa il default" prende il nuovo default; se era stata impostata, resta il valore impostato. In entrambi i casi la differenza viene mostrata.
    - Il valore impostato (override) non viene mai toccato.
- **Services/yaml e comandi**: vista affiancata (Monaco diff editor). A sinistra c'è l'installazione corrente, a destra la nuova versione. L'utente sceglie blocco per blocco e può ritoccare a mano il risultato.
- **Il motore di diff è a tre vie** _[scelta del designer]_: base = versione d'origine, locale = installazione, remoto = nuova versione. Questo serve a capire quali differenze sono modifiche locali e quali novità della versione. In UI lo si usa per evidenziare i blocchi; la scelta resta all'utente. L'MCP lo usa per decidere se l'aggiornamento è "senza conflitti": nessun blocco modificato sia localmente sia nella nuova versione.
- Si conferma o si annulla. Non si applica nulla in automatico.

L'aggiornamento cambia solo l'installazione nel DB. Il deploy resta un'azione separata.

## 6. Deploy

È un job asincrono (`DeployJob`) per deployment, eseguibile su tutti gli host o su uno solo.

- **Lock**: un lock atomico (Redis) per deployment impedisce due job concorrenti. Il lock ha un TTL, così un worker morto non lo lascia appeso per sempre.

Per ogni host, in sequenza:

1. connessione SSH (attraverso i jump host);
2. controllo delle precondizioni: variabili obbligatorie valorizzate, generazione senza errori, spazio disco sufficiente (soglia configurabile);
3. creazione della cartella `path`;
4. backup dei file correnti in `path/.dada/backups/<timestamp>/`, con retention configurabile;
5. upload via SFTP di tutti i file generati, poi permessi (`600` per `.env*`, `+x` per gli script);
6. `docker login` su ogni registry associato al DeploymentHost, con la password passata via stdin e mai sulla riga di comando; poi `docker compose pull` / pull delle immagini (opzionale, attivo di default);
7. `./restart.sh restart` (opzionale, attivo di default).

Questi passi valgono per tutto il job:

- **Log**: ogni passo e ogni riga di output remoto vengono trasmessi live via Reverb sul canale privato del job e salvati nello storico. I segreti vengono mascherati nell'output.
- **Esito**: successo o fallimento per host e complessivo, con storico consultabile.
- **Snapshot**: ogni deploy riuscito salva nel DB uno snapshot dello stato delle installazioni dell'host (versione d'origine, services, variabili, comandi) e dei file generati.
- **Rollback** a un deploy precedente: è un'azione esplicita, non automatica. Ripristina sia lo snapshot nel DB sia i file sull'host (upload + restart), così il deploy successivo non riapplica ciò che si è annullato. _[scelta del designer; lo snapshot serve dalla fase 1, il rollback da UI arriva in fase 2]_

Altri job: Restart (host o singolo servizio), RunCommand (comando di progetto), HostCheck (raccolta info e raggiungibilità), Prune.

Il deploy si può programmare a una data e ora (scheduler).

## 7. WebSSH (fase successiva)

- È un terminale nel browser (xterm.js) verso un host, attraverso i jump host.
- La sessione SSH è aperta lato server con phpseclib; i byte passano su un canale websocket. _[scelta del designer: il modo esatto (processo dedicato per sessione, server ws custom accanto a Reverb, o sidecar) è lasciato al builder, purché le credenziali non lascino mai il server]_
- Accesso: Operator del deployment che contiene l'host, oppure Admin.
- **Registrazione completa** di ogni sessione: output con i tempi (formato asciicast v2) più input, salvati e riproducibili da UI con un player. Si registrano anche utente, host, inizio e fine.
- Retention configurabile.

## 8. Scheduler (fase successiva)

- **Pulizia**:
    - prune delle immagini docker inutilizzate sugli host;
    - backup di deploy più vecchi della retention;
    - log dei job e registrazioni ssh più vecchi di N giorni.
- **Monitoring**: controllo periodico di:
    - host raggiungibile;
    - container/servizi fermi o in restart loop;
    - disco sopra soglia;
    - check HTTP configurabili per installazione (URL + status atteso);
    - certificati TLS in scadenza (dagli URL dei check HTTP).
- **Alerting via mail** ai membri del deployment, con ruolo configurabile. Si manda un avviso all'apertura e uno alla risoluzione, senza ripetizioni a ogni controllo. Gli alert aperti sono visibili in UI.
- **Manutenzioni**: si assegnano a un utente, su un deployment o un host, con data e descrizione. 3 giorni prima l'assegnatario riceve una mail di promemoria (il numero di giorni è configurabile). Stati: pianificata / fatta / annullata.

## 9. Server MCP (fase successiva)

Usa `laravel/mcp` ed è esposto via HTTP dall'app. Si autentica con un token personale Sanctum e applica gli stessi permessi per deployment.

- **Lettura e diagnosi**:
    - elenca deployment, host, installazioni, progetti e versioni;
    - stato di container e servizi;
    - log di un servizio (tail);
    - esito degli ultimi job;
    - alert aperti;
    - file generati, con segreti sempre mascherati.
- **Azioni**:
    - deploy;
    - aggiornamento di versione: solo se senza conflitti yaml, altrimenti restituisce il diff e rimanda alla UI;
    - restart;
    - lancio di comandi di progetto.
- Le azioni distruttive (stop, comandi `dangerous`) richiedono un parametro di conferma esplicito.

Le azioni passano dagli stessi service/policy della UI, senza logica duplicata.

## 10. Stack e confezionamento

Lo stack è deciso così.

- **Backend**:
    - Laravel 13, PHP 8.5;
    - PostgreSQL di default (MySQL/MariaDB devono funzionare: niente SQL specifico);
    - Redis per code, cache, lock;
    - Horizon per i worker;
    - Reverb per i websocket;
    - phpseclib 3 per SSH/SFTP;
    - symfony/yaml;
    - laravel/mcp;
    - Sanctum.
- **Frontend**:
    - Vue 3 + TypeScript + Inertia + Vite;
    - Tailwind + PrimeVue _[scelta del designer]_;
    - xterm.js;
    - Monaco editor (yaml e diff).
- **Autenticazione**: login a sessione, 2FA TOTP opzionale _[scelta del designer]_.
- **Test**: Pest. Unit test sulla generazione (input → file attesi, snapshot), sul diff di aggiornamento, sulle policy. Feature test sulle API. Le operazioni SSH sono dietro un'interfaccia con un fake per i test.
- **Immagine Docker dell'app**:
    - unica immagine nginx + PHP 8.5-fpm con supervisord;
    - una variabile d'ambiente sceglie il ruolo del container: `web` | `worker` | `scheduler` | `reverb` (oppure `all` per installazioni piccole);
    - include un `docker-compose.yml` di esempio con app, postgres e redis.
- **CI (GitHub Actions)**:
    - lint (Pint, ESLint) e test Pest su push e PR;
    - build multi-arch (amd64/arm64) e push su GitHub Container Registry `ghcr.io/<owner>/dada` con tag `latest` (main), `sha-<short>`, e semver sui tag git.
- **Licenza**: quella già presente nel repo.

## 11. Fasi

1. **Fondamenta e ciclo completo** (primo traguardo):
    - auth e utenti, ruoli per deployment;
    - credenziali, host con jump host e TOFU;
    - progetti, versioni (clona, pubblica), variabili, comandi;
    - deployment, host del deployment, installazioni;
    - generazione file con anteprima;
    - DeployJob con lock, backup, log live via Reverb;
    - restart;
    - immagine docker + CI;
    - test Pest.
2. **Aggiornamento di versione** con diff variabili e merge affiancato yaml; rollback da backup; comandi di progetto da UI; deploy programmati.
3. **WebSSH** con registrazione e player.
4. **Scheduler**: pulizia, monitoring, alerting mail, manutenzioni con promemoria.
5. **Server MCP**.

## 12. Lasciato al builder

- Schema DB dettagliato, nomi delle tabelle, struttura delle cartelle del codice.
- Il meccanismo tecnico della webssh (vedi §7), con i vincoli dichiarati.
- Il layout UI e la navigazione, purché le funzioni descritte ci siano.
- Il formato esatto degli script bash, purché rispetti l'interfaccia `start|stop|restart|status [servizio|all]`.
- Gli intervalli di default dello scheduler e le soglie.
