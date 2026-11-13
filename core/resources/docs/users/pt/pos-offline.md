---
title: Vender quando a internet cai (POS sem conexão)
audience: owner
group: Taking orders
updated: 2026-10-12
---

# Vender quando a internet cai (POS sem conexão)

Seu caixa continua vendendo quando a internet ou o servidor estão inacessíveis, por um tempo limitado, e envia tudo para o seu painel quando a conexão volta. Esta página explica o que ainda funciona, o que não funciona e quais são os limites.

## O que funciona sem conexão

- **Registrar vendas** no menu que seu caixa baixou por último: itens, opções, variantes, impostos, taxa de serviço e os
  descontos que sua equipe tem permissão para dar.
- **Pagamentos em dinheiro e manuais** (por exemplo, uma transferência bancária que você mesmo confirma), com o troco calculado no caixa.
- **Recibos** impressos no caixa, incluindo o código QR onde o seu país exige.
- **Cancelar** uma venda feita neste caixa, neste turno, antes de ter sido sincronizada (com um PIN de gerente se a sua loja exigir).
- **Abrir e fechar a gaveta**, entradas e retiradas de dinheiro, e o resumo do turno deste caixa.
- **Pagamentos com cartão** guardados no caixa para depois: somente em um caixa com o aplicativo do leitor de cartão, somente dentro
  dos limites de cartão abaixo, e somente depois que o seu provedor de pagamentos confirmar este recurso para a sua loja. Até
  então, os pagamentos com cartão sem conexão ficam desativados e o caixa pede outra forma de pagamento.

Quando a conexão volta, o caixa envia cada venda, pagamento e cancelamento para o seu painel em ordem, uma única vez cada. Uma venda
nunca é criada duas vezes, mesmo que o caixa reinicie ou a conexão caia no meio.

## Limites de tempo

| Limite | Padrão | Máximo |
|---|---|---|
| Quanto tempo um caixa pode vender sem contatar o servidor | 12 horas | 24 horas |
| Quanto tempo uma venda que não pode ser enviada é guardada no caixa | 7 dias | 7 dias |

- Depois que o tempo sem conexão acaba, o caixa recusa vendas **novas** e mostra "conecte-se para continuar". Ele ainda envia o que
  tem e ainda reimprime recibos. As horas contam a partir da última vez que o caixa alcançou o servidor.
- Se uma venda ainda estiver esperando depois de 7 dias, o caixa para as novas vendas e mostra um alerta para o gerente. Nada é apagado
  antes de ter sido enviado.
- Os donos de loja podem escolher um tempo sem conexão mais curto nas configurações do POS; o máximo da plataforma é 24 horas.

## Limites do cartão

Os pagamentos com cartão sem conexão ficam retidos no caixa pelo aplicativo do leitor de cartão até que o provedor consiga processá-los. O caixa verifica
estes limites **antes** de o cartão ser lido:

| Limite | Valor |
|---|---|
| Um pagamento com cartão | até SAR 250 |
| Todos os pagamentos com cartão ainda não enviados, por caixa | até SAR 2,500 |

Você pode definir limites menores para a sua loja, não maiores. Acima de um limite, o caixa diz "precisa de conexão" e você escolhe outra
forma de pagamento.

**A loja assume o risco.** Um pagamento com cartão sem conexão não pode ser verificado no momento da venda. Se o cartão for
recusado depois, a venda permanece nos seus registros e aparece como **cartão recusado após venda sem conexão** nesse pedido, no relatório
do turno e na lista de **Exceções sem conexão**, com o valor, a hora, o membro da equipe e o caixa. A Juwri não cobra o
cliente novamente por você. Se você receber o dinheiro de outra forma (dinheiro, transferência), um gerente marca como "recebido de outra forma",
o que adiciona um pagamento de correção e nunca apaga o recusado. O caixa e a Juwri nunca armazenam números de cartão.

## Caixas no navegador são somente em dinheiro

Um caixa rodando em um navegador web (não o aplicativo do leitor de cartão) pode vender sem conexão apenas com **dinheiro e pagamentos manuais**. Ele não tem o
aplicativo do leitor de cartão, então os pagamentos com cartão sem conexão não estão disponíveis ali.

Um navegador também pode apagar os próprios dados guardados quando o dispositivo está com pouco espaço ou o navegador é redefinido. Para proteger as suas
vendas, o caixa pede ao navegador que mantenha os dados e mostra um aviso sobre isso. Se um caixa no navegador puder ficar sem conexão por muito tempo,
envie as vendas assim que a conexão voltar e prefira o aplicativo para tablet nos pontos de venda movimentados.

## O que a sua equipe vai ver

- Um aviso "Sem conexão" com o número de vendas em espera e quanto tempo sem conexão ainda resta.
- Preços, impostos, arredondamento e recibos funcionam da mesma forma que quando há conexão. Se um preço for alterado no painel enquanto o caixa estava
  sem conexão, a venda mantém o preço que o cliente viu e pagou.
- Depois de sincronizar, uma breve lista de **Exceções sem conexão** do turno mostra tudo o que um gerente deve verificar.

## Coisas que precisam de conexão (exceções)

Estas não funcionam sem conexão. O caixa avisa quando você tenta:

- Pagamentos com cartão pela internet e links de pagamento, pagamentos com carteira digital ou crédito armazenado, vales-presente (vender ou resgatar), resgate de pontos de fidelidade
  (os pontos ainda são ganhos quando a venda é sincronizada), cupons que precisam do servidor (códigos de uso único ou específicos do cliente).
- Reembolsos de vendas não feitas neste caixa neste turno, qualquer reembolso em cartão, e cancelar um pagamento com cartão depois que ele foi enviado.
- Consultar clientes (digitar um nome e um telefone em uma venda não tem problema) e criar contas de clientes.
- Pedidos pela internet e de aplicativos de entrega recebidos, despacho de entregadores, a tela da cozinha em outros dispositivos, mover ou juntar uma mesa
  com outro caixa, reservas e a lista de espera.
- Alterar preços, itens, impostos, equipe ou configurações, abrir um caixa que nunca foi conectado, e fazer login de um membro da equipe
  que não estava na última lista baixada.
- Relatórios além do resumo do turno deste caixa (o relatório de fim de dia só é definitivo depois que as vendas forem sincronizadas).
- Notas fiscais que precisam de aprovação do governo (por exemplo, notas de empresa para empresa na Arábia Saudita). Os recibos simplificados
  para clientes são assinados no próprio caixa.
- Vender depois que o tempo sem conexão ou o limite de 7 dias acabar.

## Quando duas coisas são diferentes

- **Estoque:** o caixa pode vender um item abaixo do estoque zero enquanto está sem conexão. A venda é aceita quando sincroniza e o item entra
  em um relatório de **venda acima do estoque** para você. Itens marcados como "nunca vender acima do estoque" são bloqueados no zero no caixa.
- **A mesma mesa em dois caixas:** as duas vendas são mantidas como pedidos separados e marcadas.
- **Um turno fechado de outro dispositivo enquanto este caixa estava sem conexão:** as vendas atrasadas são adicionadas ao turno
  correto e o relatório dele é reemitido com uma seção de "vendas atrasadas".

## Um caixa é perdido ou roubado

Peça a quem administra a sua conta da Juwri que **revogue** o caixa. Na próxima vez que ele alcançar a internet, ele apaga tudo
o que guarda, depois de enviar as vendas que conseguir. Se ele nunca alcançar a internet, ele apaga os próprios dados sozinho na próxima inicialização
depois de estar revogado por 3 dias. Tudo o que é armazenado no caixa é criptografado e a chave nunca sai do dispositivo.

## Páginas relacionadas

- [Ponto de venda (POS)](../pos.md) — o caixa registradora, os pagamentos e a gaveta.
- [Contas da equipe](../staff.md) — funções, PINs e permissões por pessoa.
- [Gerenciamento de pedidos](../orders.md) — onde as vendas sem conexão sincronizadas aparecem.
