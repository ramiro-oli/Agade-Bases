// função que lista as bases
function carregarBases() {
    // envia requisição para o php
    $.post(
        "../../back-end/php/script.php",
        {
            acao: "listarBases"
        },

        // executa quando o php responder
        function(resposta) {
            // salva o local onde as bases devem ser adicionadas
            const lista_bases = $("#lista-bases");
            // para cada base executa isso
            resposta.bases.forEach(function(base) {
                // salva o item e a estrutura a ser adicionada
                const linha = $(`
                    <tr>
                        <td>
                            <a href="formulario.html?id_base=${base.id_base}">${base.nome}<a>
                        </td>
                    </tr>
                `);
                // adiciona na lista
                lista_bases.append(linha);
            });

        },
        "json"
    );
}

// executa a função ao carregar a página
$(document).ready(function() {
    carregarBases();
});