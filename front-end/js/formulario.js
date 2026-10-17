console.log("Olá Agadê!");

// pegar o id da base selecionada, cria variavel para receber as fontes e troca o botão
const parametros = new URLSearchParams(window.location.search);
const id_base = parametros.get("id_base");
if (id_base) {
    $("#submit").text("Atualizar base e fontes");
}
let fontes_base = [];

// apenas caso estivermos editando uma base
if (id_base) {
    // envia requisição para php
    $.post(
        "../../back-end/php/script.php",
        // dados a serem enviados
        {
            acao: "buscarDados",
            id_base: id_base
        },
        // ao receber a resposta o js preenche os campos do formulário
        function(resposta) {
            $("#nome").val(resposta.base.nome);
            $("#descricao").val(resposta.base.descricao);
            $("#tabela_destino").val(resposta.base.tabela_destino);
            $("#fonte").val(resposta.base.fonte);
            $("#fonte_link").val(resposta.base.fonte_link);
            $("#fonte_api").val(resposta.base.fonte_api);
            // guarda os packages e os resources de cada fonte para usar depois
            fontes_base = resposta.fontes;
        },
        "json"
    );
}

// guarda partes do html úteis para o programa em variáveis
const btn_listar = $("#btnListarPackages");
const input_url = $("#fonte_api");
const lista_package_resource = $("#lista-package-resource");
const busca_package = $(".busca-package");
const input_busca_package = $("#input-busca-package");

// array para salvar os packages carregados, os packages que abrimos ao editar e variável para url
let packages_carregados = [];
let packages_abertos = [];
let url = "";

// chama a função que carrega os packages
btn_listar.on("click", carregarPackages);

// função que carrega os packages
function carregarPackages() {
    // guarda a url digitada pelo usuário
    url = input_url.val();

    if (!url) {
        alert("Por favor insira uma URL.");
        return;
    }

    // mensagem temporária enquanto carrega os packages
    lista_package_resource.html("<p>Carregando...</p>");

    // envia requisição para o php
    $.post(
        // envia a ação a ser feita e a url digitada
        "../../back-end/php/script.php",
        {
            acao: "listarPackages",
            url: url
        },

        function(resposta) {
            // guarda o resultado da requisição ckan
            packages_carregados = resposta.result;
            // monta os accordion com o resultado e mostra a caixa de busca
            montarAccordion(resposta.result, url);
            busca_package.show();
        },

        // informa o js para tratar a resposta como json
        "json"

    // exibe mensagem de erro caso não consiga contatar o php
    ).fail(function(xhr, status, error) {
        console.log("Erro ao carregar packages");
        console.log(error);
        lista_package_resource.html(
            "<p>Erro ao carregar os packages.</p>"
        );
    });

}

function montarAccordion(package_ids, url) {
    // limpa o local que os accordions aparecem caso haja uma outra requisição
    lista_package_resource.empty();

    // cria e guarda a div dos accordions
    const accordion = $('<div id="accordion"></div>');

    // adiciona os accordions na div onde eles devem ficar
    lista_package_resource.append(accordion);

    // para cada package carregado, pega o nome e o id
    package_ids.forEach(function(package_id) {
        const titulo = $(`
            <h3 data-id="${package_id}">
                <span>${package_id}</span>
            </h3>
        `);

        // conteudo dos accordions enquanto o php carrega os resources
        const conteudo = $(`
            <div>
                <p>Carregando resources...</p>
            </div>
        `);

        // adiciona o nome e o conteudo na div
        accordion.append(titulo);
        accordion.append(conteudo);
    });

    // configura como os accordions vão aparecer
    $("#accordion").accordion({
        collapsible: true,
        active: false,
        heightStyle: "content",

        // quando um accordion for aberto:
        activate: function(event, ui) {
            // salva seu id
            if (ui.newHeader.length) {
                const package_id =
                    ui.newHeader.data("id");
                console.log("Package aberto:", package_id);
                // chama a função que carrega os resources
                carregarResources(
                    package_id,
                    ui.newPanel,
                    url
                );
            }
        }
    });
}

// função que carrega o resources
function carregarResources(package_id, painel, url) {
    // evita fazer a mesma requisição novamente
    if (painel.data("carregado")) {
        return;
    }

    // envia a requisição para o php
    $.post(
        // envia a ação, a url e o id do package selecionado
        "../../back-end/php/script.php",
        {
            acao: "packageShow",
            url: url,
            id: package_id
        },

        // recebe a resposta e verifica se deu certo (ver se da pra tirar isso dps)
        function(resposta) {
            if (!resposta.success) {
                painel.html(
                    "<p>Erro ao carregar os resources.</p>"
                );
                console.log(resposta.error);
                return;
            }

            // guarda a resposta da requisição
            const pkg = resposta.result;

            // adiciona o id do package que o usuário abriu (serve para verificar depois quando deletar)
            packages_abertos.push(pkg.id);

            // remove a mensagem de carregando os resources
            painel.empty();

            // cria o botão selecionar todos os packages
            const botao = $(`
                <button type="button" class="btn-selecionar-todos">
                    Selecionar todos os resources
                </button>
            `);

            const botao_dicionario = $(`
                <button type="button" class="btn-criar-dicionario">
                    Criar dicionário
                </button>
            `);

            // cria a lista em que os resources serão adiconados
            const lista = $("<ul></ul>");

            // para cada resource:
            pkg.resources.forEach(function(resource) {

                const formato = (resource.format || "").toLowerCase();
                const isJson = formato === "json";

                // verifica se os resources carregados já estão na base para poder marcar
                const ja_selecionado = fontes_base.some(function(fonte) {
                    return fonte.package_id === resource.package_id &&
                        fonte.resource_id === resource.id;
                });

                // cria um item de lista para cada metadado carregado e mostra apenas a caixa seletora e o titulo do resource
                lista.append(`
                    <li>
                        <input
                            type="checkbox"
                            name="resources[]"
                            value="${resource.id}"
                            data-package-id="${resource.package_id}"
                            data-url="${resource.url}"
                            data-nome="${resource.name}"
                            data-ultima-atualizacao="${resource.last_modified}"
                            data-delimitador=";"
                            ${ja_selecionado ? "checked" : ""}>

                        <span>
                            ${resource.name} (${resource.format})
                        </span>

                        ${isJson ? `
                            <label>
                                <input
                                    type="radio"
                                    name="resource_dicionario"
                                    value="${resource.id}"
                                    data-package-id="${resource.package_id}"
                                    data-url="${resource.url}"
                                    data-nome="${resource.name}">
                                Usar como dicionário
                            </label>
                        ` : ""}
                    </li>
                `);
            });

            // adiciona o botão e os resources na página
            painel.append(botao);
            painel.append(botao_dicionario);
            painel.append(lista);

            atualizarBotaoDicionario();

            // atualiza o botão dependendo da quantidade de resources selecionados
            function atualizarBotao() {
                const checkboxes =
                    painel.find("input[type=checkbox]");
                const todosMarcados =
                    checkboxes.length > 0 &&
                    checkboxes.length ===
                    checkboxes.filter(":checked").length;
                // variações do botão
                botao.text(
                    todosMarcados
                        ? "Desmarcar todos os resources"
                        : "Selecionar todos os resources"
                );
            }

            function atualizarBotaoDicionario() {
                const algumSelecionado =
                    painel.find("input[name='resource_dicionario']:checked").length > 0;
                botao_dicionario.prop("disabled", !algumSelecionado);
            }

            // função que faz o botão fazer o que faz e mudar a cada clique
            botao.on("click", function() {
                const checkboxes =
                    painel.find("input[type=checkbox]");

                const todosMarcados =
                    checkboxes.length ===
                    checkboxes.filter(":checked").length;

                checkboxes.prop(
                    "checked",
                    !todosMarcados
                );

                atualizarBotao();
            });

            // caso o usuário desmarque um botão individualmente, muda o botão
            painel.find("input[type=checkbox]").on(
                "change",
                function() {
                    atualizarBotao();
                }
            );

            painel.find("input[name='resource_dicionario']").on(
                "change",
                function() {
                    atualizarBotaoDicionario();
                }
            );

            
            botao_dicionario.on("click", function() {
                const selecionado =
                    painel.find("input[name='resource_dicionario']:checked");

                if (selecionado.length !== 1) {
                    alert("Selecione um JSON para criar o dicionário.");
                    return;
                }

                const resource = selecionado;

                $.post(
                    "../../back-end/php/dicionario.php",
                    {
                        id_base: id_base,
                        url: resource.data("url"),
                    },
                    function(resposta) {
                        if (resposta.sucesso) {
                            alert(resposta.mensagem);
                        } else {
                            alert("Erro: " + resposta.mensagem);
                        }

                        console.log(resposta);
                    },
                    "json"
                ).fail(function(xhr) {
                    let mensagem = "Não foi possível criar o dicionário.";

                    if (xhr.responseJSON && xhr.responseJSON.mensagem) {
                        mensagem = xhr.responseJSON.mensagem;
                    }

                    alert("Erro: " + mensagem);
                    console.error(xhr.responseText);
                });
            });

            // marca o conteúdo do accordion escolhido para que não seja necessário carregar novamente
            painel.data("carregado", true);

            // atualiza o accordion após as alterações
            $("#accordion").accordion("refresh");
        },

        // informa o js para tratar a resposta como json
        "json"

    // exibe mensagem de erro caso não consiga contatar o php
    ).fail(function(xhr, status, error) {
        console.log("Erro ao carregar resources");
        console.log(error);
        painel.html(
            "<p>Erro ao carregar resources.</p>"
        );
    });
}

// sempre que o conteúdo do campo de busca mudar, filtra os packages
input_busca_package.on("input", function() {
    const busca = $(this).val().toLowerCase();
    // salva os packages filtrados
    const packages_filtrados = packages_carregados.filter(function(package_id) {
        return package_id.toLowerCase().includes(busca);
    });
    // montar os accordions com os packages filtrados
    montarAccordion(packages_filtrados, url);
});

// ao clicar em criar bases e fontes:
$("#formulario").on("submit", function(event) {
    // envia os dados para o php sem recarregar a página
    event.preventDefault();

    // valores digitados pelo usuário nos campos e lista vazia para guardar os dados dos resources selecionados
    const dados = {
        nome: $("#nome").val(),
        descricao: $("#descricao").val(),
        tabela_destino: $("#tabela_destino").val(),
        fonte: $("#fonte").val(),
        fonte_link: $("#fonte_link").val(),
        fonte_api: $("#fonte_api").val(),
        resources: [],
        // coloca a lista de packages que o usuário abriu no dicionário que vai para o php
        packages_abertos: packages_abertos
    };

    // para cada resource selecionado, pegar os dados necessários e adicionar na lista resources em "dados"
    $("#lista-package-resource input[name='resources[]']:checked").each(function() {
        const checkbox = $(this);
        dados.resources.push({
            resource_id: checkbox.val(),
            package_id: checkbox.data("package-id"),
            url: checkbox.data("url"),
            nome: checkbox.data("nome"),
            ultima_atualizacao: checkbox.data("ultima-atualizacao").substring(0, 10),
            delimitador: checkbox.data("delimitador")
        });
    });

    // define que a ação padrão é add nova base e troca caso seja atualizar
    let acao = "criarBasesFontes";
    if (id_base) {
        acao = "atualizarBaseFontes";
    };

    $.post(
        // envia para o php a ação que deve ser feita e os dados a serem utilizados
        "../../back-end/php/script.php",
        {
            acao: acao,
            dados: JSON.stringify(dados),
            id_base: id_base
        },

        // mostra o resultado do criar bases e fontes ou atualizar bases e fontes
        function(resposta) {
            console.log(resposta);
            if (resposta.success) {
                if (acao === "atualizarBaseFontes") {
                    alert("Base e fontes atualizadas com sucesso!");
                } else {
                    alert("Base e fontes criadas com sucesso!");
                }
            } else {
                alert("Erro ao criar base e fontes.");
                console.log(resposta.erro);
            }
        },

        // informa o js para tratar a resposta como json
        "json"

        // caso js não consiga enviar os dados
    ).fail(function(xhr, status, error) {
        console.log("Erro ao enviar os dados");
        console.log(error);
    });
});