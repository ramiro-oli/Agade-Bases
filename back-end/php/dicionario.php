<?php

require_once __DIR__ . '/config.php';

header('Content-Type: application/json; charset=utf-8');

// Schemas utilizados
$schema_bases_fontes = 'agade_software';
$schema_dicionario = 'ramon';
$schema_dados = 'public';


/**
 * Baixa o conteúdo de uma URL usando cURL.
 * Utilizado para buscar o JSON de metadados.
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

        if (!is_string($url) || trim($url) === '') {
            throw new Exception('A URL do JSON não foi informada.');
        }

        $url = trim($url);


        // 2. Buscar a tabela de destino da base
        $sql = "
            SELECT tabela_destino
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

        if ($tabela_destino === '') {
            throw new Exception(
                'A base não possui uma tabela de destino configurada.'
            );
        }


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


        // 4. Baixar o JSON e obter a descrição da tabela
        $conteudo_json = baixarArquivo($url);

        $dados_json = json_decode($conteudo_json, true);

        if (!is_array($dados_json)) {
            throw new Exception(
                'Não foi possível interpretar o JSON selecionado.'
            );
        }

        // A descrição da tabela vem do cabeçalho do JSON.
        $descricao_tabela =
            $dados_json['metadados']['cabecalho']['descricao'] ?? '...';

        if (
            !is_string($descricao_tabela) ||
            trim($descricao_tabela) === ''
        ) {
            $descricao_tabela = '...';
        }


        // 5. Buscar as colunas da tabela de destino em public
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

        $colunas_dicionario = [];

        while ($coluna = pg_fetch_assoc($resultado)) {

            $nome_sql = $coluna['column_name'];

            $colunas_dicionario[] = [
                'nome' => $nome_sql,
                'descricao' => '...',
                'nome_sql' => $nome_sql
            ];
        }

        if (count($colunas_dicionario) === 0) {
            throw new Exception(
                "Nenhuma coluna foi encontrada na tabela " .
                "{$schema_dados}.{$tabela_destino}."
            );
        }


        // 6. Iniciar a transação
        $resultado = pg_query($conn, 'BEGIN');

        if ($resultado === false) {
            throw new Exception(
                'Não foi possível iniciar a transação: ' .
                pg_last_error($conn)
            );
        }

        $transacao_iniciada = true;


        // 7. Inserir o dicionário da tabela
        // A descrição utilizada é a obtida do JSON.
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


        // 8. Inserir as colunas do dicionário
        // nome e nome_sql recebem o nome real da coluna.
        // A descrição das colunas recebe sempre '...'.
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


        // 9. Confirmar a transação
        $resultado = pg_query($conn, 'COMMIT');

        if ($resultado === false) {
            throw new Exception(
                'Erro ao confirmar a transação: ' .
                pg_last_error($conn)
            );
        }

        $transacao_iniciada = false;


        // 10. Retornar sucesso
        echo json_encode([
            'sucesso' => true,
            'mensagem' => 'Dicionário criado com sucesso.',
            'id_tabela' => $id_tabela,
            'tabela_destino' => $tabela_destino,
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