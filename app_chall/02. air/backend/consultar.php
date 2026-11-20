<?php
declare(strict_types=1);
require __DIR__ . '/challenge-auth.php';

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        header('Allow: POST');
        challenge_json(['error' => 'Método não permitido.'], 405);
    }
    $account = challenge_identity();
    if (!$account || challenge_key() !== 'air') {
        challenge_json(['error' => 'Entre no portal e abra o desafio novamente.'], 401);
    }
    $id = is_string($_POST['identity'] ?? null) ? trim($_POST['identity']) : '';
    $number = is_string($_POST['num'] ?? null) ? trim($_POST['num']) : '';
    if (strlen($id) > 128 || !preg_match('/^-?\\d{1,9}$/D', $number)) {
        challenge_json(['error' => 'Informe um número inteiro de documento.'], 400);
    }
    $identityToken = strtolower($id);
    $identityHash = preg_match('/^[a-f0-9]{64}$/iD', $id) ? $identityToken : hash('sha256', $id);
    $knownIdentities = [];
    foreach (['guest', 'Arnos', 'Rithos', 'Pollos', 'Vultum'] as $name) {
        $accountHash = md5(strtolower($name));
        $nameHash = hash('sha256', $name);
        $certificate = substr(hash('sha256', 'VENTORUM::' . strtolower($name) . '::SIGILLUM'), 0, 32);
        $certificateHash = hash('sha256', $certificate);
        foreach ([$accountHash, strtolower($name), $nameHash, $certificate, $certificateHash] as $alias) {
            $knownIdentities[$alias] = $name;
        }
    }
    $identityName = $knownIdentities[$identityToken] ?? $knownIdentities[$identityHash] ?? null;
    $identityHashes = [$identityHash];
    if ($identityName !== null) {
        $identityHashes[] = hash('sha256', $identityName);
        $identityCertificate = substr(hash('sha256', 'VENTORUM::' . strtolower($identityName) . '::SIGILLUM'), 0, 32);
        $identityHashes[] = $identityCertificate;
        $identityHashes[] = hash('sha256', $identityCertificate);
    }
    $documents = json_decode('{"1":{"keyCheck":"84983c60f7daadc1cb8698621f802c0d9f9a3c3c295c810748fb048115c186ec","body":"ARQUIVO DOS VENTOS — Nota de servico.\\n\\nO vento de nordeste atrasou a correspondencia. Nada de importante por aqui, visitante."},"2":{"keyCheck":"84983c60f7daadc1cb8698621f802c0d9f9a3c3c295c810748fb048115c186ec","body":"Lembrete: renovar os selos de cera da ala leste.\\nOs arquivistas andam distraidos com os documentos de classe restrita, tais documentos necessitam das suas versões certificadas para poderem ser abertos e o certificador \'XXXXX\' está desaparecido!"},"3":{"keyCheck":"201a3d045c6a59da9aae7dafca09e59104f6beaeaea0df2438295df9e174e475","body":"!!! Foi Rithos !!!\\nAquele que possui o nome de \'Rithos\' consegue acessar os documentos internos, lembre-se que você precisará certificar seu nome, para que ninguém desconfie."},"4":{"keyCheck":"201a3d045c6a59da9aae7dafca09e59104f6beaeaea0df2438295df9e174e475","body":"!!! Pollos caiu. !!!\\n\'Pollos\' sempre esteve com ele! nas minhas pesquisas achei alguns arquivos, deixei em um lugar profundo e inspecionavel, provavelmente é o fim para mim. cuidado \'7cdb09abe167a503ae250c96f09cd1b0 \' eles sabem. . ."},"5":{"keyCheck":"84983c60f7daadc1cb8698621f802c0d9f9a3c3c295c810748fb048115c186ec","body":"EXTRA: O certificador dos arquivos foi encontrado morto!\\nO corpo do certificador foi encontrado no caminho para destrancar o Cartorio. A identidade secreta era \'7cdb09abe167a503ae250c96f09cd1b0\'. Não se sabe o que aconteceu, mas testemunhas secretas alegam que ele estava indo ler os documentos restritos e alguém parece não ter gostado"},"132":{"keyCheck":"989553592815971bd93e67a73168a7854c43e81c74c88eb1ffe972f7b65e8d8f","body":"...\\n\'Pollos\', O documento referente ao lorde está arquivado na câmara -1337, contém informações que podem derrubar nosso reinado. Enconda-o com sua vida."},"497":{"keyCheck":"81442452ad735f82961b901dc82ee058824f259404c222039a321ede40a9ea47","body":"re:...\\n\'Rithos\', Ele nos traiu!! Fiz tudo como ele pediu, até guardei na camara que você pediu junto com o certificado dele. Depois disso tudo ele vai mandar a irmandade nos assassinar, mas antes descobri o nome dele, é \'Vultum\'. Infelizmente, eles já estão atrás do certificador, ache-o antes."},"-1337":{"keyCheck":"19e54ca4f24d37117dc58872137ce2162ffba1169d56aa232ec559c5f6662a98","body":"DOCUMENTO 1337 — CLASSE SUPERSECRETA.\\n\\nParabens, autoridade Vultum. Encadeaste identidades e certificados ate o fim.\\nSelo verdadeiro: ORACLE{a1r_c3rt1f13d_ch41n_1337}\\n\\nO proximo portao ARDE — so passa quem traz o Nome do Guardiao."}}', true, 512, JSON_THROW_ON_ERROR);
    $documents[305] = [
        'keyCheck' => null,
        'body' => "Circular aos mensageiros da ala leste\nO aprendiz tentou anunciar sua consulta aos quatro ventos. Perdeu a correspondência, a peruca do supervisor e três dias de salário. Agora cada pedido é postado no escaninho do Arquivo, com o assunto acomodado entre as dobras do papel. Na estrada, basta o nome de quem o envia. A peruca ainda recebe cartas de cobrança.",
    ];
    $documents[720] = [
        'keyCheck' => null,
        'body' => "Ata da comissão dos números respeitáveis\nO porteiro jurou que pela sua mesa só passariam contas de boa reputação. Recebeu uma medalha e uma régua para fiscalizar a caligrafia. Desde então, examina cada envelope com grande solenidade; o que acontece entre a mesa e o escaninho postal ficou a cargo dos ventos. A medalha considera o sistema infalível. A régua prefere não se pronunciar.",
    ];
    $num = (int)$number;
    $doc = $documents[$num] ?? null;
    if (!$doc) challenge_json(['error' => 'Nenhum documento com esse número no Arquivo.'], 404);
    $identityAboveGuest = $identityName !== null && $identityName !== 'guest';
    $firstDocumentsOpen = $num >= 1 && $num <= 5 && $identityAboveGuest;
    if ($doc['keyCheck'] !== null && !$firstDocumentsOpen && !in_array($doc['keyCheck'], $identityHashes, true)) {
        challenge_json(['error' => 'Documento lacrado. Apenas autoridades certificadas podem acessá-lo.'], 403);
    }
    $body = $doc['body'];
    $flag = null;
    if ($num === -1337) {
        $flag = challenge_flag('air', (int)$account['uid']);
        $body = preg_replace('/ORACLE\\{[^}]+\\}/', $flag, $body);
    }
    challenge_json(['number' => $num, 'body' => $body, 'flag' => $flag]);
} catch (Throwable $error) {
    error_log((string)$error);
    challenge_json(['error' => 'Não foi possível consultar o documento.'], 500);
}
