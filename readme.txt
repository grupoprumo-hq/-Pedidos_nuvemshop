=== Pedidos Nuvemshop ===
Conecta o WordPress à API da Nuvemshop (somente leitura), detecta o que o token pode ler e mostra os dados em abas.

== Instalação ==
1. Plugins > Adicionar novo > Enviar plugin > escolha o .zip > Instalar. Se já existir uma versão, escolha "Substituir o atual pelo enviado".
2. Clique em "Configurar" (na lista de plugins) ou abra o menu "Pedidos Nuvemshop".
3. Informe o ID da loja e o token do app sob medida da Nuvemshop.
   Mais seguro: no wp-config.php, antes da linha "That's all, stop editing!", adicione:
     define( 'NUVEMSHOP_STORE_ID', '8063546' );
     define( 'NUVEMSHOP_TOKEN', 'COLE_O_TOKEN_AQUI' );
4. Clique em "Salvar e testar conexão". O plugin mostra o que o token PODE ler.
5. Clique em "Sincronizar agora". Depois disso a sincronização roda sozinha a cada 30 minutos (WP-Cron).

== Permissões do token (mudou no app? o plugin descobre) ==
Na Nuvemshop: Aplicativos > Aplicativos sob medida > seu app > Perfis de acesso.
Marque só o que precisa e volte ao plugin: "Testar permissões agora" (ou "Sincronizar agora") reconfere tudo.
As permissões também são reconferidas sozinhas a cada 6 horas.

Dado           | Permissão a marcar
Pedidos        | Pedidos > Ler pedidos
Produtos       | Produtos > Ler produtos
Categorias     | Produtos > Ler produtos
Clientes       | Clientes > Ler clientes
Cupons         | Cupons > Ler cupons
Outros dados   | Pedidos manuais, Pedidos (carrinhos abandonados), Conteúdo (páginas), Envio (transportadoras): consulta ao vivo

== Abas ==
Visão geral (configuração, permissões, sincronização), Pedidos, Produtos, Categorias, Clientes, Cupons, Outros dados.

== CSVs ==
* Resumo semanal: colunas do 05_PAINEL_METRICAS.csv, só com o que vem da loja (pedidos pagos e não cancelados).
  Kg e pacotes saem do peso escrito no nome do produto (ex.: "250 g", "500 g").
* Catálogo de produtos: uma linha por variação (nome, SKU, preço, estoque).

== Privacidade (LGPD) ==
O plugin nunca guarda e-mail, telefone, CPF/CNPJ nem endereço completo. Pedidos guardam só o ID do cliente e cidade/UF.
Clientes aparecem com nome mascarado (ex.: M*** S***). O token nunca é exibido de volta.

== Requisitos ==
WordPress 5.8+, PHP 7.4+. Limite da API Nuvemshop: 2 requisições por segundo (o plugin respeita).
Se o WP-Cron do servidor estiver desativado, agende uma chamada real de cron a cada 30 minutos.
Gancho para integrações: do_action( 'pedidos_nuvemshop_synced', $estado_por_recurso ).

== Versão 3.1.0: Brasil Gigante e atualização automática ==
Aba "Brasil Gigante" no painel do plugin:
- Aviso de pedido pago (e-mail e/ou webhook https) só para pedidos com o produto Brasil Gigante. Dados pessoais não são gravados.
- eGestor: começa DESLIGADO. Modos: simulado, criar venda, criar venda e pedir NF-e. Credencial só no wp-config.php:
    define( 'PN_EGESTOR_PERSONAL_TOKEN', 'COLE_AQUI' );
  Webhook de NF-e do eGestor (opcional): endereço mostrado na aba; defina no wp-config.php:
    define( 'PN_BG_EGESTOR_WH_TOKEN', 'TOKEN_DO_WEBHOOK' );
- Atualização automática: informe "dono/repositorio" do GitHub na aba (ou defina PN_UPDATE_REPO no wp-config.php).
  Para publicar uma versão nova: aumente "Version" no cabeçalho do plugin, gere o .zip (pasta pedidos-nuvemshop), crie uma Release no GitHub com tag igual à versão (ex.: v3.2.0) e anexe o .zip.
  O WordPress passa a mostrar "Atualizar agora" na lista de plugins. Repositório privado: defina PN_UPDATE_TOKEN no wp-config.php.
