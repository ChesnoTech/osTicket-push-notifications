[English](../README.md) | [Русский](README.ru.md) | [العربية](README.ar.md) | [Español](README.es.md) | [Français](README.fr.md) | [Deutsch](README.de.md) | [中文](README.zh.md) | 🌐 **Português** | [Türkçe](README.tr.md)

# Plugin de Notificações Push para osTicket

Notificações Web Push (PWA) para o painel de atendentes do osTicket. Entrega notificações push em tempo real no navegador para eventos de tickets, completamente independente dos alertas por e-mail.

## Funcionalidades

- **Notificações push em tempo real** para: novos tickets, novas mensagens/respostas, atribuições, transferências, tickets vencidos
- **Independente dos alertas por e-mail** — funciona mesmo quando todos os alertas por e-mail estão desativados
- **Preferências do atendente** com controles por tipo de evento, filtro por departamento e horário silencioso
- **Controles administrativos** com chave geral, controles por evento, ícone de notificação personalizado e gerenciamento de chaves VAPID
- **Suporte a múltiplos idiomas** usando o sistema de tradução nativo do osTicket
- **Responsivo para dispositivos móveis** com ícones de sino e engrenagem na barra de navegação mobile
- **Compatível com modo escuro** (tema osTicketAwesome)
- **Baseado em Service Worker** — funciona mesmo quando a aba do navegador está fechada
- **Zero dependências** — implementação PHP pura do Web Push, sem necessidade do Composer

## Requisitos

- osTicket **1.18+**
- PHP **8.0+** com a extensão `openssl`
- HTTPS (exigido pela API Web Push)

## Instalação

1. Copie a pasta `push-notifications/` para `include/plugins/`
2. No Painel Administrativo, acesse **Gerenciar > Plugins > Adicionar Novo Plugin**
3. Clique em **Instalar** ao lado de "Push Notifications"
4. Defina o Status como **Ativo** e salve
5. Vá para a aba **Instâncias**, clique em **Adicionar Nova Instância**
6. Defina o nome da instância e o status como **Habilitado**
7. Na aba **Config**:
   - Informe um VAPID Subject (ex.: `mailto:admin@example.com`)
   - Marque **Habilitar Notificações Push**
   - Ative os tipos de alerta desejados
   - Opcionalmente, defina uma URL de ícone personalizado para notificações
   - Salve — as chaves VAPID são geradas automaticamente

## Como Funciona

### Configuração Administrativa (Painel Admin > Plugins > Push Notifications)

| Configuração | Descrição |
|---|---|
| Habilitar Notificações Push | Chave geral liga/desliga |
| VAPID Subject | E-mail de contato para identificação no serviço push |
| Chaves VAPID | Geradas automaticamente no primeiro salvamento |
| Alertas de Novo Ticket / Mensagem / Atribuição / Transferência / Vencimento | Controles globais por tipo de evento |
| URL do Ícone de Notificação | Ícone/logotipo personalizado para notificações push (deixe vazio para o padrão) |

### Preferências do Atendente (Ícone de engrenagem ao lado do sino na barra de navegação)

Cada atendente pode personalizar suas próprias preferências de notificação:

| Configuração | Descrição |
|---|---|
| Controles de evento | Escolha quais tipos de evento acionam notificações push |
| Filtro de departamento | Receba notificações apenas dos departamentos selecionados |
| Horário silencioso | Suprime notificações durante um intervalo de tempo (suporta períodos que cruzam a meia-noite) |

### Fluxo de Notificação

```
Chave geral do plugin ATIVADA?
  └─ Controle de evento do plugin ATIVADO?
      └─ Atendente possui assinatura push?
          └─ Preferência de evento do atendente ATIVADA?
              └─ Departamento do ticket no filtro de departamentos do atendente? (vazio = todos)
                  └─ Fora do horário silencioso do atendente?
                      └─ ENVIAR PUSH ✓
```

> **Observação:** As notificações push são completamente independentes das configurações de alerta por e-mail do osTicket.

## Arquitetura

| Arquivo | Finalidade |
|---|---|
| `plugin.php` | Manifesto do plugin (id, versão, nome) |
| `config.php` | Campos de configuração admin + geração de chaves VAPID + criação de tabelas no banco |
| `class.PushNotificationsPlugin.php` | Bootstrap, hooks de sinais, rotas AJAX, injeção de assets |
| `class.PushNotificationsAjax.php` | Controlador AJAX (inscrever, cancelar inscrição, preferências, teste) |
| `class.PushDispatcher.php` | Despacho de notificações com lógica de destinatários + filtragem de preferências |
| `class.WebPush.php` | Remetente PHP puro de Web Push (VAPID + ECDH + AES-128-GCM, sem Composer) |
| `assets/push-notifications.js` | Interface cliente do sino/engrenagem, modal de preferências, registro do service worker |
| `assets/push-notifications.css` | Estilos para ícones de navegação, modal, botões de alternância, modo escuro |
| `assets/sw.js` | Service Worker para recebimento e exibição de notificações push |

## Tabelas do Banco de Dados

- `ost_push_subscription` — armazena os endpoints de assinatura push do navegador por atendente
- `ost_push_preferences` — armazena as preferências de notificação por atendente

## Autor

ChesnoTech

## Licença

GPL-2.0 (mesma do osTicket)
