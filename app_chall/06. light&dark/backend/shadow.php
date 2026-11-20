<?php
declare(strict_types=1);

/*
 * Desafio 06 — Caminho das Trevas
 *
 * O leitor resolve um caminho relativo à sala /luz/registro/arquivo/ordem/selos,
 * mas não confere
 * se o resultado continua naquela sala. A árvore abaixo é virtual e limitada
 * ao desafio: ela nunca lê arquivos do sistema operacional do contêiner.
 */

require __DIR__ . '/challenge-auth.php';
require_once dirname(__DIR__) . '/private/light-dark.php';

function dark_resolve_path(string $base, string $requested): string {
    $parts = explode('/', $base . '/' . str_replace('\\', '/', $requested));
    $resolved = [];
    foreach ($parts as $part) {
        if ($part === '' || $part === '.') continue;
        if ($part === '..') {
            array_pop($resolved);
            continue;
        }
        $resolved[] = $part;
    }
    return '/' . implode('/', $resolved);
}

function dark_shadow_fragment(int $accountId): string {
    return oracle_eclipse_fragment($accountId, 'shadow', challenge_account_secret());
}

try {
    $identity = challenge_identity();
    if (!$identity) challenge_json(['error' => 'Entre no portal e abra o desafio novamente.'], 401);

    $requested = (string)($_GET['path'] ?? 'leitura-inicial.txt');
    if (strlen($requested) > 240 || str_contains($requested, "\0")) {
        challenge_json(['error' => 'Caminho inválido.'], 400);
    }

    // A falha intencional: falta validar que $resolved ainda começa com
    // /luz/registro/arquivo/ordem/selos. Assim, ../ permite sair do diretório
    // inicial e cinco subidas alcançam a raiz virtual.
    $resolved = dark_resolve_path('/luz/registro/arquivo/ordem/selos', $requested);
    $documents = [
        '/luz/registro/arquivo/ordem/selos/leitura-inicial.txt' => "ARQUIVO LUMINAR — LEITURA INICIAL\n\nSetor: selos/\nEstado: catalogado\n\nA luz guarda o que pode ser dito. As trevas guardam o que foi apagado.\nEste leitor resolve caminhos relativos ao setor aberto.\n",
        '/luz/registro/arquivo/ordem/selos/revogacoes.log' => "2026-09-28T21:04:11Z selo de vigília revogado\n2026-09-29T04:18:03Z acesso da arquivista confirmado\n2026-09-30T00:00:00Z retenção automática concluída\n",
        '/luz/registro/arquivo/ordem/selos/selo-da-lua.pem' => "-----BEGIN ORACLE SEAL-----\nQ0VSVC1MVU5BLTA2\n-----END ORACLE SEAL-----\n",
        '/luz/registro/arquivo/ordem/selos/inventario-selos.json' => "{\n  \"setor\": \"selos\",\n  \"ativos\": 7,\n  \"ultimo_inventario\": \"2026-09-30\"\n}\n",
        '/luz/registro/arquivo/ordem/selos/vigias.txt' => "selene — vigília da aurora\ncaelum — vigília do meio-dia\numbra — vigília da penumbra\n",
        '/luz/registro/arquivo/ordem/selos/trilha-auditoria.log' => "2026-10-01T09:20:07Z inventário selos status=ok\n2026-10-02T23:48:19Z troca de vigia setor=selos\n",
        '/luz/registro/arquivo/ordem/selos/chaveiro.ini' => "[vigilia]\nalgoritmo=oraculo-v2\nrotacao=mensal\nstatus=ativo\n",
        '/luz/registro/arquivo/ordem/selos/manifesto.txt' => "Manifesto do Arquivo\n\nSete selos preservam o limite entre o que deve ser visto e o que deve permanecer sob sombra.\n",
        '/luz/registro/arquivo/ordem/selos/caderno-vigilia.md' => "# Caderno de Vigília\n\nA aurora encerrou seu turno sem ruptura.\nA penumbra recebeu os lacres e confirmou a contagem ritual.\n",
        '/luz/registro/arquivo/ordem/selos/registro-rupturas.csv' => "data,selo,estado,observacao\n2026-07-14,terceiro,resolvido,oscilação breve\n2026-08-09,quinto,resolvido,bruma no corredor oeste\n",
        '/luz/registro/arquivo/ordem/selos/selo-cinza.asc' => "-----BEGIN ORACLE ATTESTATION-----\nU0VMTy1DSU5aQS1BUlFVSVZP\n-----END ORACLE ATTESTATION-----\n",
        '/luz/registro/arquivo/ordem/protocolo-selo.md' => "# Protocolo de Selagem 7\n\n- Mantenha os registros sob selo.\n- Não exponha o diretório pai ao leitor público.\n- Revise caminhos relativos antes de liberar uma consulta.\n",
        '/luz/registro/arquivo/ordem/guardioes.csv' => "vigia,turno,setor\nselene,01:00-07:00,selo\ncaelum,07:00-13:00,arquivo\numbra,13:00-19:00,registro\n",
        '/luz/registro/arquivo/ordem/juramento-vigias.md' => "# Juramento dos Vigias\n\nGuardaremos a passagem entre o brilho e a sombra.\nNenhum registro será entregue antes do tempo marcado.\n",
        '/luz/registro/arquivo/ordem/turnos.yaml' => "aurora:\n  vigia: selene\n  setor: arquivo\nzenite:\n  vigia: caelum\n  setor: relicario\npenumbra:\n  vigia: umbra\n  setor: selos\n",
        '/luz/registro/arquivo/ordem/procedimento-limiar.txt' => "Procedimento do Limiar\n\n1. Conferir a marca da vigília.\n2. Registrar a passagem no livro de turno.\n3. Recolher as lâmpadas após o último sino.\n",
        '/luz/registro/arquivo/catalogo-luminar.txt' => "CATÁLOGO LUMINAR\n\nL-17  Carta de vigília\nL-18  Inventário das lâmpadas rituais\nL-19  Registro da última penumbra\n",
        '/luz/registro/arquivo/retencao.conf' => "archive_days=90\nsealed_days=365\nreviewer=arquivista\n",
        '/luz/registro/arquivo/inventario-eclipse.tsv' => "codigo\tdescricao\tguarda\nE-041\tLâmpada de quartzo\tarquivista\nE-042\tAmpulheta de neblina\tvigia\nE-043\tCírculo de prata\trelicário\n",
        '/luz/registro/arquivo/carta-selada-17.txt' => "À Arquivista,\n\nA torre permanece quieta. Que a luz não confunda o caminho da sombra.\n\n— Conselho do Eclipse\n",
        '/luz/registro/arquivo/mapa-camaras.txt' => "CÂMARAS DO ARQUIVO\n\nI   Recepção\nII  Registro\nIII Relicário\nIV  Ordem dos Vigias\nV   Selos\n",
        '/luz/registro/nota-do-registro.txt' => "Registro de manutenção\n\nO Arquivo foi migrado para a nova sala de selos.\nOs documentos anteriores permanecem sob custódia.\n",
        '/luz/registro/agenda-ritual.ics' => "BEGIN:VCALENDAR\nVERSION:2.0\nBEGIN:VEVENT\nSUMMARY:Renovação do Véu\nDTSTART:20261031T000000Z\nEND:VEVENT\nEND:VCALENDAR\n",
        '/luz/registro/ocorrencias-penumbra.log' => "2026-10-01 18:00 sino oeste confirmado\n2026-10-01 21:00 lâmpadas baixadas\n2026-10-02 00:00 vigília transferida\n",
        '/luz/registro/livro-de-turnos.txt' => "Livro de Turnos\n\nAurora: Selene\nZênite: Caelum\nPenumbra: Umbra\n\nAs assinaturas foram conferidas pela Arquivista.\n",
        '/luz/guia-da-luz.txt' => "GUIA DA LUZ\n\nA torre mantém registros de leitura, arquivo e selagem.\nNenhum leitor deve atravessar as fronteiras entre esses setores.\n",
        '/luz/lampadas.ini' => "[lampada-principal]\nintensidade=72\ncor=violeta\nstatus=acesa\n",
        '/luz/relatorio-crepusculo.md' => "# Relatório do Crepúsculo\n\nAs correntes de luz mantiveram-se estáveis durante a última vigília.\nNenhum espelho registrou alteração fora do previsto.\n",
        '/luz/calendario-lunar.txt' => "CALENDÁRIO LUNAR\n\n03/10 — Lua de quartzo\n10/10 — Vigília do norte\n17/10 — Renovação das lâmpadas\n",
        '/etc/issue' => "Oracle Archive OS 6.0 \\n \\l\n",
        '/etc/hostname' => "arquivo-eclipse\n",
        '/etc/hosts' => "127.0.0.1\tlocalhost\n127.0.1.1\tarquivo-eclipse\n::1\tlocalhost ip6-localhost ip6-loopback\n",
        '/etc/os-release' => "PRETTY_NAME=\"Oracle Archive OS 6.0\"\nNAME=\"Oracle Archive OS\"\nVERSION_ID=\"6.0\"\nID=oracle\n",
        '/etc/group' => "root:x:0:\ndaemon:x:1:\nadm:x:4:vigia\nwww-data:x:33:\narchivistas:x:1001:arquivista,selene,caelum,umbra\n",
        '/etc/fstab' => "# /etc/fstab: static file system information.\nUUID=1d0d-eclipse / ext4 defaults 0 1\ntmpfs /tmp tmpfs defaults,nosuid,nodev 0 0\n",
        '/etc/ssh/sshd_config' => "Port 22\nPermitRootLogin no\nPasswordAuthentication no\nAllowUsers arquivista vigia\n",
        '/etc/resolv.conf' => "search ordem.local\nnameserver 127.0.0.53\noptions edns0 trust-ad\n",
        '/etc/cron.d/oracle-indexer' => "17 * * * * oracle /usr/local/bin/oracle-indexar --quiet\n",
        '/etc/systemd/system/oracle-indexer.service' => "[Unit]\nDescription=Oracle Archive indexer\nAfter=network-online.target\n\n[Service]\nUser=oracle\nExecStart=/usr/local/bin/oracle-indexar\nRestart=on-failure\n",
        '/etc/passwd' => "root:x:0:0:root:/root:/usr/sbin/nologin\narchivista:x:1001:1001:Arquivista:/var/arquivo:/usr/sbin/nologin\nvigia:x:1002:1002:Vigia do Selo:/var/vigia:/usr/sbin/nologin\n",
        '/etc/shadow' => "# Arquivo reservado da Ordem do Eclipse\nroot:*:20105:0:99999:7:::\ndaemon:*:19852:0:99999:7:::\nbin:*:19852:0:99999:7:::\nsys:*:19852:0:99999:7:::\nsync:*:19852:0:99999:7:::\ngames:*:19852:0:99999:7:::\nman:*:19852:0:99999:7:::\nlp:*:19852:0:99999:7:::\nmail:*:19852:0:99999:7:::\nnews:*:19852:0:99999:7:::\nuucp:*:19852:0:99999:7:::\nproxy:*:20143:0:99999:7:::\nwww-data:*:20143:0:99999:7:::\nbackup:*:20011:0:99999:7:::\nlist:*:20143:0:99999:7:::\nirc:*:20143:0:99999:7:::\nnobody:*:19852:0:99999:7:::\nsystemd-network:!*:20076:0:99999:7:::\nsystemd-timesync:!*:20076:0:99999:7:::\nmessagebus:!*:20076:0:99999:7:::\npolkitd:!*:20076:0:99999:7:::\nredis:!*:20432:0:99999:7:::\narchivista:!*:20705:1:180:14:30::\nvigia:!*:20672:1:180:14:30::\nselene:!*:20672:1:180:14:30::\ncaelum:!*:20641:1:180:14:30::\numbra:!*:20641:1:180:14:30::\n\nfragmento_trevas=" . dark_shadow_fragment((int)$identity['uid']) . "\n",
        '/var/log/arquivo/access.log' => "2026-10-03T00:14:02Z leitor=publico arquivo=leitura-inicial.txt status=200\n2026-10-03T00:17:45Z leitor=arquivista arquivo=protocolo-selo.md status=200\n",
        '/var/log/auth.log' => "Oct 03 00:14:02 arquivo-eclipse sshd[421]: Accepted publickey for arquivista from 10.8.0.14 port 53312\nOct 03 00:17:45 arquivo-eclipse sudo: vigia : TTY=unknown ; PWD=/var/arquivo ; COMMAND=/usr/bin/find .\n",
        '/var/log/syslog' => "Oct 03 00:00:01 arquivo-eclipse CRON[188]: (root) CMD (/usr/local/sbin/rotacionar-selos)\nOct 03 00:15:01 arquivo-eclipse systemd[1]: Started Oracle Archive indexer.\n",
        '/var/log/apt/history.log' => "Start-Date: 2026-09-28  09:14:41\nCommandline: apt install oracle-indexer\nInstall: oracle-indexer:amd64 (6.0.2)\nEnd-Date: 2026-09-28  09:14:48\n",
        '/var/backups/arquivo-index.bak' => "ORACLE-ARCHIVE BACKUP\nversion=6\ncreated=2026-10-02T23:00:00Z\nentries=184\n",
        '/home/arquivista/.profile' => "# ~/.profile\nexport EDITOR=vi\nexport ARCHIVE_HOME=/var/arquivo\n",
        '/home/arquivista/notas.txt' => "Lembrar de revisar a rotação dos selos antes da próxima vigília.\n",
        '/home/vigia/.bash_history' => "ls\ncd /var/arquivo\nfind . -type f\nexit\n",
        '/home/selene/.profile' => "export ORDEM_TURNO=aurora\nexport LANG=pt_BR.UTF-8\n",
        '/home/caelum/.profile' => "export ORDEM_TURNO=zenite\nexport LANG=pt_BR.UTF-8\n",
        '/home/umbra/.profile' => "export ORDEM_TURNO=penumbra\nexport LANG=pt_BR.UTF-8\n",
        '/opt/oracle/README' => "Oracle Archive Runtime\n\nComponentes locais para indexação e leitura dos registros luminares.\n",
        '/opt/oracle/ORDEM.md' => "# Ordem do Eclipse\n\nEste ambiente sustenta o catálogo ritual, o índice de vigílias e os registros da torre.\n",
        '/opt/oracle/config/torre.ini' => "[torre]\nnome=Eclipse\nsetor=ocidente\nmodo=vigilia\n",
        '/srv/relicario/status.json' => "{\"service\":\"relicario\",\"status\":\"online\",\"sealed\":true}\n",
        '/srv/relicario/inventario.md' => "# Inventário do Relicário\n\n- 12 lâmpadas de quartzo\n- 7 selos ativos\n- 1 espelho de maré\n",
        '/srv/relicario/visitas.log' => "2026-10-02T19:20:00Z visitante=arquivista motivo=inventário\n2026-10-02T23:45:00Z visitante=vigia motivo=troca de turno\n",
        '/usr/local/bin/rotacionar-selos' => "#!/bin/sh\n# rotina interna da Ordem\nlogger -t oracle-selos 'rotação concluída'\n",
        '/usr/local/bin/oracle-indexar' => "#!/bin/sh\n# atualiza o índice do Arquivo Luminar\nexec /usr/bin/oracle-indexer --source /var/arquivo\n",
        '/usr/share/doc/oracle-indexer/changelog.Debian.gz' => "oracle-indexer (6.0.2) stable; urgency=medium\n\n  * Atualiza o catálogo de registros luminares.\n\n -- Ordem do Eclipse <infra@ordem.local>\n",
        '/boot/grub/grub.cfg' => "set default=0\nset timeout=3\nmenuentry 'Oracle Archive OS' {\n  linux /vmlinuz root=UUID=1d0d-eclipse ro quiet\n}\n",
        '/root/.bashrc' => "# ~/.bashrc\nexport PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin\n",
        '/run/arquivo.pid' => "421\n",
        '/tmp/.oracle-indexer.lock' => "pid=421\ncreated=2026-10-03T00:15:01Z\n",
        '/proc/version' => "Linux version 6.1.0-oracle (builder@eclipse) #1 SMP PREEMPT_DYNAMIC Oracle Archive OS\n",
        '/proc/uptime' => "184292.34 125470.19\n",
        '/proc/cpuinfo' => "processor\t: 0\nmodel name\t: Oracle Virtual Core\ncpu MHz\t\t: 2400.000\n\nprocessor\t: 1\nmodel name\t: Oracle Virtual Core\ncpu MHz\t\t: 2400.000\n",
        '/proc/meminfo' => "MemTotal:        8192000 kB\nMemFree:         2148024 kB\nMemAvailable:    5601120 kB\nBuffers:          248112 kB\nCached:          2034188 kB\n",
        '/sys/kernel/hostname' => "arquivo-eclipse\n",
        '/sys/class/net/eth0/address' => "02:42:ac:11:00:06\n",
        '/mnt/.keep' => "Ponto de montagem reservado pela Ordem.\n",
        '/media/README' => "Mídias removíveis são inventariadas pela Arquivista.\n",
        '/var/cache/apt/pkgcache.bin' => "APT-PACKAGE-CACHE\nentries=412\ngenerated=2026-10-02T23:00:00Z\n",
        '/var/lib/dpkg/status' => "Package: oracle-indexer\nStatus: install ok installed\nVersion: 6.0.2\nArchitecture: amd64\n\nPackage: oracle-seals\nStatus: install ok installed\nVersion: 2.4.1\nArchitecture: all\n",
        '/var/lib/oracle/index.state' => "generation=184\nlast_index=2026-10-03T00:15:01Z\nrecords=2317\nsealed_records=7\n",
        '/var/arquivo/indice-geral.json' => "{\n  \"colecao\": \"Arquivo Luminar\",\n  \"geracao\": 184,\n  \"registros\": 2317,\n  \"ultima_vigilia\": \"2026-10-03T00:00:00Z\"\n}\n",
        '/var/arquivo/recados-da-torre.txt' => "Recados da Torre\n\nA Arquivista pede silêncio no corredor dos espelhos.\nOs vigias devem registrar a troca de turno antes da aurora.\n",
        '/var/arquivo/colecoes/mares.txt' => "Coleção: Mares\n\nCânticos, cartas e reflexos recolhidos antes da última maré.\n",
        '/var/arquivo/colecoes/constelacoes.txt' => "Coleção: Constelações\n\nMapas incompletos da passagem entre as seis torres.\n",
        '/var/spool/cron/crontabs/oracle' => "# m h  dom mon dow   command\n17 * * * * /usr/local/bin/oracle-indexar --quiet\n",
        '/var/www/html/index.html' => "<!doctype html><title>Oracle Archive</title><h1>Arquivo Luminar</h1>\n",
    ];

    $directories = [
        '/' => ['boot/', 'dev/', 'etc/', 'home/', 'lib/', 'lib64/', 'lost+found/', 'luz/', 'media/', 'mnt/', 'opt/', 'proc/', 'root/', 'run/', 'srv/', 'sys/', 'tmp/', 'usr/', 'var/'],
        '/boot' => ['grub/'],
        '/boot/grub' => ['grub.cfg'],
        '/dev' => ['null', 'random', 'urandom'],
        '/luz' => ['calendario-lunar.txt', 'guia-da-luz.txt', 'lampadas.ini', 'registro/', 'relatorio-crepusculo.md'],
        '/luz/registro' => ['agenda-ritual.ics', 'arquivo/', 'livro-de-turnos.txt', 'nota-do-registro.txt', 'ocorrencias-penumbra.log'],
        '/luz/registro/arquivo' => ['carta-selada-17.txt', 'catalogo-luminar.txt', 'inventario-eclipse.tsv', 'mapa-camaras.txt', 'ordem/', 'retencao.conf'],
        '/luz/registro/arquivo/ordem' => ['guardioes.csv', 'juramento-vigias.md', 'procedimento-limiar.txt', 'protocolo-selo.md', 'selos/', 'turnos.yaml'],
        '/luz/registro/arquivo/ordem/selos' => ['caderno-vigilia.md', 'chaveiro.ini', 'inventario-selos.json', 'leitura-inicial.txt', 'manifesto.txt', 'registro-rupturas.csv', 'revogacoes.log', 'selo-cinza.asc', 'selo-da-lua.pem', 'trilha-auditoria.log', 'vigias.txt'],
        '/etc' => ['cron.d/', 'fstab', 'group', 'hostname', 'hosts', 'issue', 'os-release', 'passwd', 'resolv.conf', 'shadow', 'ssh/', 'systemd/'],
        '/etc/cron.d' => ['oracle-indexer'],
        '/etc/ssh' => ['sshd_config'],
        '/etc/systemd' => ['system/'],
        '/etc/systemd/system' => ['oracle-indexer.service'],
        '/home' => ['arquivista/', 'caelum/', 'selene/', 'umbra/', 'vigia/'],
        '/home/arquivista' => ['.profile', 'notas.txt'],
        '/home/caelum' => ['.profile'],
        '/home/selene' => ['.profile'],
        '/home/umbra' => ['.profile'],
        '/home/vigia' => ['.bash_history'],
        '/media' => ['README'],
        '/mnt' => ['.keep'],
        '/opt' => ['oracle/'],
        '/opt/oracle' => ['ORDEM.md', 'README', 'config/'],
        '/opt/oracle/config' => ['torre.ini'],
        '/root' => ['.bashrc'],
        '/run' => ['arquivo.pid', 'lock/', 'user/'],
        '/srv' => ['relicario/'],
        '/srv/relicario' => ['inventario.md', 'status.json', 'visitas.log'],
        '/usr' => ['local/', 'share/'],
        '/usr/local' => ['bin/'],
        '/usr/local/bin' => ['oracle-indexar', 'rotacionar-selos'],
        '/usr/share' => ['doc/'],
        '/usr/share/doc' => ['oracle-indexer/'],
        '/usr/share/doc/oracle-indexer' => ['changelog.Debian.gz'],
        '/proc' => ['cpuinfo', 'meminfo', 'self/', 'uptime', 'version'],
        '/sys' => ['class/', 'kernel/'],
        '/sys/class' => ['net/'],
        '/sys/class/net' => ['eth0/'],
        '/sys/class/net/eth0' => ['address'],
        '/sys/kernel' => ['hostname'],
        '/var' => ['arquivo/', 'backups/', 'cache/', 'lib/', 'log/', 'spool/', 'tmp/', 'www/'],
        '/var/arquivo' => ['colecoes/', 'indice-geral.json', 'recados-da-torre.txt'],
        '/var/arquivo/colecoes' => ['constelacoes.txt', 'mares.txt'],
        '/var/backups' => ['arquivo-index.bak'],
        '/var/cache' => ['apt/'],
        '/var/cache/apt' => ['pkgcache.bin'],
        '/var/lib' => ['dpkg/', 'oracle/'],
        '/var/lib/dpkg' => ['status'],
        '/var/lib/oracle' => ['index.state'],
        '/var/log' => ['apt/', 'arquivo/', 'auth.log', 'syslog'],
        '/var/log/apt' => ['history.log'],
        '/var/spool' => ['cron/'],
        '/var/spool/cron' => ['crontabs/'],
        '/var/spool/cron/crontabs' => ['oracle'],
        '/var/www' => ['html/'],
        '/var/www/html' => ['index.html'],
        '/tmp' => ['.oracle-indexer.lock'],
        '/var/log/arquivo' => ['access.log'],
    ];

    if (array_key_exists($resolved, $directories)) {
        challenge_json([
            'content' => "Diretório aberto\n\n" . implode("\n", $directories[$resolved]) . "\n",
        ]);
    }
    if (!array_key_exists($resolved, $documents)) {
        challenge_json(['error' => 'Arquivo não encontrado.'], 404);
    }
    challenge_json(['content' => $documents[$resolved]]);
} catch (Throwable $error) {
    error_log((string)$error);
    challenge_json(['error' => 'O Arquivo das Sombras não respondeu.'], 500);
}
