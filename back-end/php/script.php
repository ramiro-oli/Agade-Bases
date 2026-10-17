<?php

// faz o php mostrar erros nas respostas caso haja algum erro
ini_set('display_errors', 1);
error_reporting(E_ALL);

// chama o arquivo que faz a conexão com o banco de dados
require_once 'config.php';

// variável do schema a ser utilizado (trocar aqui quando for para agade_bases)
$schema = "agade_software";

// informa que a resposta será enviada em json
header('Content-Type: application/json');

// coleta e guarda os valores enviados pelo js
extract($_POST);

//chama a função que o js mandar
switch ($acao) {
    
    case 'listarBases':
        listarBases($conn, $schema);
        break;

    case 'buscarDados':
        buscarDados($conn, $schema);
        break;

    case 'listarPackages':
        listarPackages();
        break;

    case 'packageShow':
        packageShow();
        break;

    case 'criarBasesFontes':
        criarBasesFontes($conn, $schema);
        break;

    case 'atualizarBaseFontes':
        atualizarBaseFontes($conn, $schema);
        break;

    // caso a ação seja inválida
    default:
        echo json_encode([
            "erro" => "Ação inválida."
        ]);
        break;
}

// função que vai listar as bases
function listarBases($conn, $schema) {

    // salva a consulta para encontrar as bases
    $sql = "
        SELECT
            id_base,
            nome
        FROM $schema.bases
        ORDER BY nome
    ";

    // executa a consulta e guarda os resultados
    $resultado = pg_query($conn, $sql);

    // verifica se a consulta deu certo
    if(!$resultado){
        echo json_encode([
            "erro" => "Erro ao buscar as bases.",
            "detalhes" => pg_last_error($conn)
        ]);
        return;
    }

    // transforma os registros em uma array
    $bases = pg_fetch_all($resultado);

    // devolve para o js
    echo json_encode([
        "success" => true,
        "bases" => $bases
    ]);
}

// função que vai buscar os dados da base para preencher o formulário
function buscarDados($conn, $schema) {
    // guarda as variáveis do post dentro da função
    extract($_POST);

    // guarda a consulta a ser realizada para buscar os dados da base
    $sql = "
        SELECT
            id_base,
            nome,
            descricao,
            tabela_destino,
            fonte,
            fonte_link,
            fonte_api
        FROM $schema.bases
        WHERE id_base = $1
    ";

    // executa a consulta e salva o resultado
    $resultado = pg_query_params($conn, $sql, [$id_base]);

    // verifica se a consulta deu certo
    if(!$resultado){
        echo json_encode([
            "erro" => "Erro ao buscar a base.",
            "detalhes" => pg_last_error($conn)
        ]);

        return;
    }

    // transforma em array associativo
    $base = pg_fetch_assoc($resultado);


    // salva a consulta das fontes
    $sql = "
        SELECT
            package_id,
            resource_id
        FROM $schema.fontes
        WHERE id_base = $1
    ";

    // executa e salva o resultado
    $resultado_fontes = pg_query_params($conn, $sql, [$id_base]);

    // verifica se deu certo
    if(!$resultado_fontes){
        echo json_encode([
            "erro" => "Erro ao buscar as fontes.",
            "detalhes" => pg_last_error($conn)
        ]);

        return;
    }

    // transforma em array tbm mas pega todas as linhas
    $fontes = pg_fetch_all($resultado_fontes);


    // devolve pro js
    echo json_encode([
        "success" => true,
        "base" => $base,
        "fontes" => $fontes
    ]);
}

// função que vai listar os packages
function listarPackages(){
    // guarda as variáveis do post dentro da função
    extract($_POST);

    // monta a url da api do ckan que lista os packages
    $api = $url . '/api/3/action/package_list';

    // chama a função que faz a requisição e guarda o resultado
    $resultado = requisicaoCKAN($api);

    // verifica o resultado da requisição
    if(!$resultado){
        return;
    }

    // devolve para o js
    echo json_encode($resultado);
}

function packageShow(){
    // guarda as variáveis do post dentro da função
    extract($_POST);

    // monta a url da api do ckan que busca os dados do package
    $api = $url . '/api/3/action/package_show?id=' . urlencode($id);

    // chama a função que faz a requisição e guarda o resultado
    $resultado = requisicaoCKAN($api);

    // verifica o resultado da requisição
    if(!$resultado){
        return;
    }

    // devolve para o js
    echo json_encode($resultado);
}

// função que faz a requisição ckan
function requisicaoCKAN(string $url){
    // inicia a requisição
    $ch = curl_init($url);

    // configura a requisição
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_USERAGENT, 'Agade Software');
    // desativa a verificação do certificado SSL (TEMPORÁRIO)
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);

    // executa a requisição
    $json = curl_exec($ch);

    // verifica se houve erro na requisição
    if(curl_errno($ch)){
        echo json_encode([
            "erro" => curl_error($ch)
        ]);
        curl_close($ch);
        return null;
    }

    // encerra o cURL
    curl_close($ch);

    // converte a resposta em um array e devolve para quem chamou
    return json_decode($json, true);
}

function criarBasesFontes($conn, $schema) {
    // guarda as variáveis do post dentro da função
    extract($_POST);

    // verifica se php recebeu os dados (ver se realmente precisa)
    if(empty($dados)){
        echo json_encode([
            "erro" => "Dados não enviados."
        ]);
        return;
    }

    // transforma os dados em um array associativo do php
    $dados = json_decode($dados, true);

    // verifica se o php conseguiu converter os dados do js
    if(!$dados){
        echo json_encode([
            "erro" => "Erro ao interpretar os dados."
        ]);
        return;
    }

    // Inicia a transação
    pg_query($conn, "BEGIN");

    // cria o script de inserir a base e pegar o id_base gerado
    $sql_base = "
        INSERT INTO $schema.bases
        (
            nome,
            descricao,
            tabela_destino,
            fonte,
            fonte_link,
            fonte_api,
            qualidade_dados,
            numero_regras,
            numero_padroes,
            numero_registros,
            pos_processamento
        )
        VALUES
        (
            $1,
            $2,
            $3,
            $4,
            $5,
            $6,
            0,
            0,
            0,
            0,
            ''
        )
        RETURNING id_base
    ";

    // executa o script com os dados que o js mandou
    $resultado = pg_query_params($conn, $sql_base, [
        $dados['nome'],
        $dados['descricao'],
        $dados['tabela_destino'],
        $dados['fonte'],
        $dados['fonte_link'],
        $dados['fonte_api']
    ]);

    // guarda o id_base
    $id_base = pg_fetch_result($resultado, 0, 'id_base');

    // verifica se o insert funcionou
    if(!$resultado){
        pg_query($conn, "ROLLBACK");
        echo json_encode([
            "success" => "false",
            "mensagem" => "Não foi possível criar a base. Ocorreu um erro ao salvar os dados"
        ]);
        return;
    };

    // monta o script para inserir as fontes
    $sql_fonte = "
        INSERT INTO $schema.fontes
        (
            resource_id,
            url,
            nome,
            ultima_atualizacao,
            package_id,
            delimitador,
            id_base
        )
        VALUES
        (
            $1,
            $2,
            $3,
            $4,
            $5,
            $6,
            $7
        )
    ";

    // executa o insert da fonte com cada resource selecionado
    foreach($dados['resources'] as $resource){
        $resultado = pg_query_params($conn, $sql_fonte, [
            $resource['resource_id'],
            $resource['url'],
            $resource['nome'],
            $resource['ultima_atualizacao'],
            $resource['package_id'],
            $resource['delimitador'],
            $id_base
        ]);

        // verifica se algum insert de resource falhou
        if(!$resultado){
            pg_query($conn, "ROLLBACK");
            echo json_encode([
                "erro" => "Erro ao inserir um resource.",
                "resource_id" => $resource['resource_id'],
                "detalhes" => pg_last_error($conn)
            ]);
            return;
        }
    }

    // Confirma todas as inserções
    pg_query($conn, "COMMIT");

    echo json_encode([
        "success" => true,
        "mensagem" => "Base e fontes inseridas com sucesso."
    ]);
}

// função que atualiza a base e as fontes
function atualizarBaseFontes($conn, $schema) {
    //inicia transação
    pg_query($conn, "BEGIN");

    // extrai as variaveis do post
    extract($_POST);

    // verifica se tem os dados
    if(empty($dados)){
        echo json_encode([
            "erro" => "Dados não enviados."
        ]);

        return;
    }

    // transforma json do js em array associativo
    $dados = json_decode($dados, true);

    // salva a consulta a ser feita
    $sql = "
        SELECT
            package_id,
            resource_id
        FROM $schema.fontes
        WHERE id_base = $1
    ";

    // executa e salva o resultado
    $resultado_fontes = pg_query_params($conn, $sql, [$id_base]);

    // verifica se deu certo
    if(!$resultado_fontes){
        pg_query($conn, "ROLLBACK");
        echo json_encode([
            "erro" => "Erro ao buscar as fontes atuais.",
            "detalhes" => pg_last_error($conn)
        ]);

        return;
    }

    // transforma em array
    $fontes_atuais = pg_fetch_all($resultado_fontes);

    // salva o insert
    $sql_fonte = "
        INSERT INTO $schema.fontes
        (resource_id, url, nome, ultima_atualizacao, package_id, delimitador, id_base)
        VALUES ($1, $2, $3, $4, $5, $6, $7)
    ";

    // executa o insert se algum resource marcado não estiver na base
    foreach($dados['resources'] as $resource){
        $existe = false;

        foreach($fontes_atuais as $fonte){
            if(
                $fonte['package_id'] === $resource['package_id'] &&
                $fonte['resource_id'] === $resource['resource_id']
            ){
                $existe = true;
                break;
            }
        }

        if(!$existe){
            $resultado = pg_query_params($conn, $sql_fonte, [
                $resource['resource_id'],
                $resource['url'],
                $resource['nome'],
                $resource['ultima_atualizacao'],
                $resource['package_id'],
                $resource['delimitador'],
                $id_base
            ]);

            if(!$resultado){
                pg_query($conn, "ROLLBACK");

                echo json_encode([
                    "erro" => "Erro ao inserir a fonte.",
                    "detalhes" => pg_last_error($conn)
                ]);

                return;
            }
        }
    }

    // salva o delete
    $sql_delete = "
        DELETE FROM $schema.fontes
        WHERE id_base = $1
            AND package_id = $2
            AND resource_id = $3
    ";

    // executa o delete se algum resource da base estiver desmarcado
    foreach($fontes_atuais as $fonte){

        $continua_selecionada = false;

        foreach($dados['resources'] as $resource){
            if(
                $fonte['package_id'] == $resource['package_id'] &&
                $fonte['resource_id'] == $resource['resource_id']
            ){
                $continua_selecionada = true;
                break;
            }
        }

        if(
            !$continua_selecionada &&
            in_array($fonte['package_id'], $dados['packages_abertos'])
        ){
            $resultado = pg_query_params($conn, $sql_delete, [
                $id_base,
                $fonte['package_id'],
                $fonte['resource_id']
            ]);

            if(!$resultado){
                pg_query($conn, "ROLLBACK");

                echo json_encode([
                    "erro" => "Erro ao excluir a fonte.",
                    "detalhes" => pg_last_error($conn)
                ]);

                return;
            }
        }
    }

    // verifica se deu certo
    if(!$dados){
        echo json_encode([
            "erro" => "Erro ao interpretar os dados."
        ]);

        return;
    }

    // salva o update da base
    $sql = "
        UPDATE $schema.bases
        SET
            nome = $1,
            descricao = $2,
            tabela_destino = $3,
            fonte = $4,
            fonte_link = $5,
            fonte_api = $6
        WHERE id_base = $7
    ";

    // executa e salva o update da base
    $resultado = pg_query_params($conn, $sql, [
        $dados['nome'],
        $dados['descricao'],
        $dados['tabela_destino'],
        $dados['fonte'],
        $dados['fonte_link'],
        $dados['fonte_api'],
        $id_base
    ]);

    // verifica se deu certo
    if(!$resultado){
        pg_query($conn, "ROLLBACK");

        echo json_encode([
            "erro" => "Erro ao atualizar a base.",
            "detalhes" => pg_last_error($conn)
        ]);

        return;
    }

    // commit
    pg_query($conn, "COMMIT");

    // feedback
    echo json_encode([
        "success" => true,
        "mensagem" => "Base atualizada com sucesso."
    ]);
}

?>