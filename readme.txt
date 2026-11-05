=== Pedidos Nuvemshop (Brasil Gigante) ===
Painel exclusivo do Café Brasil Gigante: lê somente os pedidos do produto, mostra vendas e envios, avisa pedido pago e prepara venda e NF-e no eGestor. Acesso por senha.

== Instalação ==
1. Plugins > Adicionar novo > Enviar plugin > escolha o .zip > Instalar e Ativar.
2. No primeiro acesso ao painel Brasil Gigante, o responsável cria a senha na própria tela. Opcional (mais seguro): definir define( 'PN_GATE_HASH', '...' ); no wp-config.php, que passa a mandar e não pode ser alterada pelo painel.
   Depois abra o menu "Brasil Gigante" e informe a senha.
3. Em "Conexão com a Nuvemshop", informe o ID da loja e o token (app com apenas "Pedidos > Ler pedidos") e clique em "Salvar e testar conexão".
   Opcional, mais seguro: no wp-config.php, antes de "That's all, stop editing!":
     define( 'NUVEMSHOP_STORE_ID', '8063546' );
     define( 'NUVEMSHOP_TOKEN', 'COLE_O_TOKEN_AQUI' );
4. Clique em "Sincronizar agora". Depois disso a sincronização roda sozinha a cada 30 minutos.

== O que o plugin acessa ==
Somente pedidos com o produto Brasil Gigante. Produtos, clientes, cupons e demais dados da loja são bloqueados no código.
Não grava CPF, e-mail, telefone nem endereço completo (nome mascarado).

== eGestor ==
Vem desligado/simulado. Credencial só no wp-config.php: define( 'PN_EGESTOR_PERSONAL_TOKEN', '...' );
Webhook de NF-e (opcional): define( 'PN_BG_EGESTOR_WH_TOKEN', '...' );

== Atualização automática ==
Informe "dono/repositorio" do GitHub no painel. Nova versão = nova Release com tag igual à versão (ex.: v3.2.1).
