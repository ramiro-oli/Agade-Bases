<?php

require_once __DIR__ . '/config.php';

header('Content-Type: application/json; charset=utf-8');

// Schemas utilizados
$schema_bases_fontes = 'agade_software';
$schema_dicionario = 'ramon';
$schema_dados = 'public';


/**
 * Baixa o conteúdo de uma URL usando cURL.
 */
function baixarArquivo(string $url): string
{
    if (!filter_var($url, FILTER_VALIDATE_URL)) {
        throw new Exception('A URL informada é inválida.');
    }

    $protocolo = parse_url($url, PHP_URL_SCHEME);

    if (strtolower((string) $protocolo) !== 'https') {
        throw new Exception('A URL precisa utilizar HTTPS.');
    }

    $curl = curl_init($url);

    curl_setopt_array($curl, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 5,
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_TIMEOUT => 60,
        CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
        CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,

        // Temporário para testes.
        // Reative a validação SSL antes de colocar em produção.
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => 0
    ]);

    $conteudo = curl_exec($curl);

    $erro = curl_error($curl);
    $status_http = curl_getinfo($curl, CURLINFO_HTTP_CODE);

    curl_close($curl);

    if ($conteudo === false) {
        throw new Exception('Erro ao baixar o JSON: ' . $erro);
    }

    if ($status_http < 200 || $status_http >= 300) {
        throw new Exception(
            'A URL retornou o status HTTP ' . $status_http . '.'
        );
    }

    return $conteudo;
}


/**
 * Cria o dicionário e registra suas colunas.
 */
function criarDicionario(
    $conn,
    $id_base,
    $url,
    $schema_bases_fontes,
    $schema_dicionario,
    $schema_dados
): void {

    $transacao_iniciada = false;

    try {

        // 1. Validar os dados recebidos
        if (
            filter_var($id_base, FILTER_VALIDATE_INT) === false ||
            (int) $id_base <= 0
        ) {
            throw new Exception('O ID da base é inválido.');
        }

        $id_base = (int) $id_base;

        // A URL do JSON é opcional.
        // Quando não houver JSON selecionado, a descrição padrão será utilizada.
        if ($url !== null && !is_string($url)) {
            throw new Exception('A URL do JSON é inválida.');
        }

        $url = is_string($url) ? trim($url) : '';


        // 2. Buscar os dados da base
        $sql = "
            SELECT tabela_destino, fonte_api
            FROM {$schema_bases_fontes}.bases
            WHERE id_base = $1
        ";

        $resultado = pg_query_params(
            $conn,
            $sql,
            [$id_base]
        );

        if ($resultado === false) {
            throw new Exception(
                'Erro ao consultar a base: ' . pg_last_error($conn)
            );
        }

        $base = pg_fetch_assoc($resultado);

        if (!$base) {
            throw new Exception('A base informada não foi encontrada.');
        }

        $tabela_destino = trim($base['tabela_destino'] ?? '');
        $fonte_api = trim($base['fonte_api'] ?? '');

        if ($tabela_destino === '') {
            throw new Exception(
                'A base não possui uma tabela de destino configurada.'
            );
        }

        if ($fonte_api === '') {
            throw new Exception(
                'A base não possui uma URL de API do CKAN configurada.'
            );
        }

        $fonte_api = rtrim($fonte_api, '/');


        // 3. Verificar se já existe um dicionário para essa base
        $sql = "
            SELECT id_tabela
            FROM {$schema_dicionario}.tabelas
            WHERE id_base = $1
            LIMIT 1
        ";

        $resultado = pg_query_params(
            $conn,
            $sql,
            [$id_base]
        );

        if ($resultado === false) {
            throw new Exception(
                'Erro ao verificar o dicionário existente: ' .
                pg_last_error($conn)
            );
        }

        if (pg_fetch_assoc($resultado)) {
            throw new Exception(
                'Já existe um dicionário cadastrado para essa base.'
            );
        }


        // 4. Obter a descrição da tabela.
        // Se um JSON foi selecionado, tentar obter a descrição dele.
        // Sem JSON, a criação continua usando a descrição padrão.
        $descricao_tabela = '...';

        if ($url !== '') {
            $conteudo_json = baixarArquivo($url);
            $dados_json = json_decode($conteudo_json, true);

            if (!is_array($dados_json)) {
                throw new Exception(
                    'Não foi possível interpretar o JSON selecionado.'
                );
            }

            $descricao_json =
                $dados_json['metadados']['cabecalho']['descricao'] ?? '...';

            if (
                is_string($descricao_json) &&
                trim($descricao_json) !== ''
            ) {
                $descricao_tabela = trim($descricao_json);
            }
        }


        // 5. Buscar os resources cadastrados para essa base
        $sql = "
            SELECT nome, resource_id, url, delimitador
            FROM {$schema_bases_fontes}.fontes
            WHERE id_base = $1
              AND resource_id IS NOT NULL
              AND TRIM(resource_id) <> ''
        ";

        $resultado = pg_query_params(
            $conn,
            $sql,
            [$id_base]
        );

        if ($resultado === false) {
            throw new Exception(
                'Erro ao consultar os resources da base: ' .
                pg_last_error($conn)
            );
        }

        $resources = [];
        $maior_ano = -1;

        while ($resource = pg_fetch_assoc($resultado)) {

            $nome_resource = trim($resource['nome'] ?? '');
            $url_resource = trim($resource['url'] ?? '');
            $delimitador = trim($resource['delimitador'] ?? '');

            if (
                $nome_resource === '' ||
                $url_resource === '' ||
                trim($resource['resource_id']) === ''
            ) {
                continue;
            }

            /*
             * Considerar resources CSV.
             *
             * Um resource é considerado CSV quando:
             * - a URL contém uma extensão .csv; ou
             * - existe um delimitador cadastrado para ele.
             */
            $url_sem_query = preg_split(
                '/[?#]/',
                $url_resource,
                2
            )[0];

            $eh_csv =
                preg_match('/\.csv$/i', $url_sem_query) === 1
                || $delimitador !== '';

            if (!$eh_csv) {
                continue;
            }

            // Extrair o ano do nome, por exemplo: "IPTU - 2026".
            if (
                !preg_match(
                    '/\b((?:19|20)\d{2})\b/',
                    $nome_resource,
                    $correspondencia
                )
            ) {
                continue;
            }

            $ano = (int) $correspondencia[1];

            if ($ano > $maior_ano) {
                $maior_ano = $ano;
                $resources = [$resource];
            } elseif ($ano === $maior_ano) {
                $resources[] = $resource;
            }
        }

        if (count($resources) === 0) {
            throw new Exception(
                'Nenhum resource CSV com ano identificado no nome ' .
                'foi encontrado para essa base.'
            );
        }

        if (count($resources) > 1) {
            throw new Exception(
                'Existe mais de um resource CSV com o ano mais recente ' .
                'para essa base. Verifique os nomes dos resources.'
            );
        }

        $resource_mais_recente = $resources[0];

        $resource_id = trim(
            $resource_mais_recente['resource_id']
        );


        // 6. Consultar os campos do resource mais recente na API do CKAN
        $url_api = $fonte_api
            . '/api/3/action/datastore_search?resource_id='
            . rawurlencode($resource_id)
            . '&limit=0';

        $conteudo_api = baixarArquivo($url_api);

        $dados_api = json_decode($conteudo_api, true);

        if (!is_array($dados_api)) {
            throw new Exception(
                'A API do CKAN retornou uma resposta JSON inválida.'
            );
        }

        if (($dados_api['success'] ?? false) !== true) {
            $mensagem_api =
                $dados_api['error']['message']
                ?? 'Erro não especificado pela API do CKAN.';

            throw new Exception(
                'A consulta à API do CKAN falhou: ' . $mensagem_api
            );
        }

        $campos_api = $dados_api['result']['fields'] ?? null;

        if (!is_array($campos_api)) {
            throw new Exception(
                'A resposta da API do CKAN não contém os campos esperados.'
            );
        }

        $nomes_campos_api = [];

        foreach ($campos_api as $campo) {

            if (
                !isset($campo['id']) ||
                !is_string($campo['id']) ||
                trim($campo['id']) === ''
            ) {
                throw new Exception(
                    'A API do CKAN retornou um campo sem nome válido.'
                );
            }

            // Preservar o nome original retornado pela API.
            $nomes_campos_api[] = $campo['id'];
        }

        if (count($nomes_campos_api) === 0) {
            throw new Exception(
                'Nenhum campo foi retornado pela API do CKAN.'
            );
        }


        // 7. Buscar as colunas reais da tabela de destino em public
        $sql = "
            SELECT column_name
            FROM information_schema.columns
            WHERE table_schema = $1
              AND table_name = $2
            ORDER BY ordinal_position
        ";

        $resultado = pg_query_params(
            $conn,
            $sql,
            [$schema_dados, $tabela_destino]
        );

        if ($resultado === false) {
            throw new Exception(
                'Erro ao consultar as colunas da tabela: ' .
                pg_last_error($conn)
            );
        }

        $nomes_colunas_sql = [];

        while ($coluna = pg_fetch_assoc($resultado)) {
            $nomes_colunas_sql[] = $coluna['column_name'];
        }

        if (count($nomes_colunas_sql) === 0) {
            throw new Exception(
                "Nenhuma coluna foi encontrada na tabela " .
                "{$schema_dados}.{$tabela_destino}."
            );
        }


        // 8. Verificar se os campos correspondem em quantidade
        if (count($nomes_campos_api) !== count($nomes_colunas_sql)) {
            throw new Exception(
                'A quantidade de campos retornados pela API do CKAN (' .
                count($nomes_campos_api) .
                ') é diferente da quantidade de colunas da tabela em ' .
                $schema_dados . ' (' .
                count($nomes_colunas_sql) .
                '). O dicionário não foi criado para evitar associações incorretas.'
            );
        }


        // 9. Associar os nomes da API aos nomes SQL pela posição
        $colunas_dicionario = [];

        foreach ($nomes_campos_api as $indice => $nome_api) {

            $colunas_dicionario[] = [
                'nome' => $nome_api,
                'descricao' => '...',
                'nome_sql' => $nomes_colunas_sql[$indice]
            ];
        }


        // 10. Iniciar a transação
        $resultado = pg_query($conn, 'BEGIN');

        if ($resultado === false) {
            throw new Exception(
                'Não foi possível iniciar a transação: ' .
                pg_last_error($conn)
            );
        }

        $transacao_iniciada = true;


        // 11. Inserir o dicionário da tabela
        $sql = "
            INSERT INTO {$schema_dicionario}.tabelas
                (nome, descricao, id_base)
            VALUES
                ($1, $2, $3)
            RETURNING id_tabela
        ";

        $resultado = pg_query_params(
            $conn,
            $sql,
            [
                $tabela_destino,
                $descricao_tabela,
                $id_base
            ]
        );

        if ($resultado === false) {
            throw new Exception(
                'Erro ao inserir o dicionário: ' .
                pg_last_error($conn)
            );
        }

        $tabela = pg_fetch_assoc($resultado);

        if (!$tabela || !isset($tabela['id_tabela'])) {
            throw new Exception(
                'Não foi possível obter o ID do dicionário criado.'
            );
        }

        $id_tabela = $tabela['id_tabela'];


        // 12. Inserir as colunas do dicionário
        $sql = "
            INSERT INTO {$schema_dicionario}.colunas
                (nome, descricao, nome_sql, id_tabela)
            VALUES
                ($1, $2, $3, $4)
        ";

        foreach ($colunas_dicionario as $coluna) {

            $resultado = pg_query_params(
                $conn,
                $sql,
                [
                    $coluna['nome'],
                    $coluna['descricao'],
                    $coluna['nome_sql'],
                    $id_tabela
                ]
            );

            if ($resultado === false) {
                throw new Exception(
                    'Erro ao inserir a coluna "' .
                    $coluna['nome'] . '": ' .
                    pg_last_error($conn)
                );
            }
        }


        // 13. Confirmar a transação
        $resultado = pg_query($conn, 'COMMIT');

        if ($resultado === false) {
            throw new Exception(
                'Erro ao confirmar a transação: ' .
                pg_last_error($conn)
            );
        }

        $transacao_iniciada = false;


        // 14. Retornar sucesso
        echo json_encode([
            'sucesso' => true,
            'mensagem' => 'Dicionário criado com sucesso.',
            'id_tabela' => $id_tabela,
            'tabela_destino' => $tabela_destino,
            'resource_utilizado' => $resource_mais_recente['nome'],
            'resource_id' => $resource_id,
            'ano_resource' => $maior_ano,
            'quantidade_colunas' => count($colunas_dicionario)
        ], JSON_UNESCAPED_UNICODE);

    } catch (Throwable $e) {

        if ($transacao_iniciada) {
            pg_query($conn, 'ROLLBACK');
        }

        http_response_code(400);

        echo json_encode([
            'sucesso' => false,
            'mensagem' => $e->getMessage()
        ], JSON_UNESCAPED_UNICODE);
    }
}


// Executar a criação do dicionário
criarDicionario(
    $conn,
    $_POST['id_base'] ?? null,
    $_POST['url'] ?? null,
    $schema_bases_fontes,
    $schema_dicionario,
    $schema_dados
);

?>