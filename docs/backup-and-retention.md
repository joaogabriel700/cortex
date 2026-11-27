# Retenção de backups e logs

Política definida pelo DevOps. **Ainda não implementada:** não há rotina de backup, registro de exclusões nem job de exclusão de conta. Vale para qualquer hospedagem e precisa estar funcionando antes do primeiro dado real.

Base: o usuário pede a exclusão, há 30 dias de carência e depois a exclusão é definitiva.

## Linha do tempo

| Momento | O que acontece com os dados |
|---|---|
| Dia 0 | Pedido de exclusão. A conta continua utilizável e pode ser cancelada |
| Dia 30 (job diário: até o dia 31) | Exclusão definitiva no banco principal |
| Até 31 dias depois da exclusão definitiva (cerca de 62 dias após o pedido) | Última cópia de backup com os dados é apagada |

O usuário deve ser informado desse prazo na tela de exclusão. Se o job de exclusão atrasar, o prazo atrasa junto: o atraso precisa gerar alerta.

## Backups

- Um backup diário do banco (`pg_dump -Fc`) e do volume de arquivos, criptografado, com cópia fora do servidor.
- **Retenção máxima de 30 dias**, em janela móvel, com apagamento automático. Não há cópias semanais, mensais ou anuais guardadas por mais tempo.
- A regra vale para **todas** as cópias: dump antes de deploy ou migration, dump manual, cópia no computador do usuário, snapshot de disco ou volume do provedor, versões antigas em armazenamento de objetos e histórico de restauração de banco gerenciado.
- Armazenamento com versionamento ou lixeira precisa apagar também as versões antigas dentro do mesmo prazo.
- Em banco gerenciado (ex.: Neon), conferir o prazo de histórico e de snapshots do plano. Se não for possível garantir no máximo 30 dias, não usar esse recurso.
- Falha no apagamento das cópias antigas gera alerta, assim como falha no backup.
- Testar a restauração pelo menos uma vez por mês, em ambiente isolado, apagado ao final do teste.
- A chave que descriptografa os backups e a `APP_KEY` ficam guardadas separadas das cópias.
- A exportação feita pelo próprio usuário é um arquivo dele e não entra nesta regra.

## Restauração sem trazer contas excluídas de volta

- O job de exclusão grava, **fora do banco**, um registro com apenas o UUID do usuário e a data da exclusão. Sem nome, e-mail ou outro dado pessoal.
- Cada registro é guardado por 45 dias (mais que a retenção dos backups) e depois apagado.
- Ordem da restauração: restaurar com API, worker e scheduler parados; reaplicar as exclusões do registro; apagar também contas cujo prazo de 30 dias já venceu; apagar os arquivos dessas contas; só então religar.
- O registro precisa ter cópia fora do servidor: se ele se perder junto com o banco, as exclusões recentes não podem ser reaplicadas.

## Logs e dados temporários

| Item | Retenção máxima |
|---|---|
| Logs da aplicação, do proxy e do banco | 30 dias |
| `failed_jobs` | 7 dias (`queue:prune-failed --hours=168`, já agendado) |
| Arquivos temporários de importação | Apagados após o processamento, inclusive em falha |
| Conteúdo bruto de linhas importadas | 90 dias (regra do modelo de dados) |

- A rotação `json-file` do Compose limita **tamanho**, não **tempo**. Um servidor com pouco tráfego pode guardar logs por mais de 30 dias. No deploy, usar um destino com expiração por tempo (por exemplo, o driver `journald` com `MaxRetentionSec=30day`) e conferir. Em plataforma gerenciada, conferir a retenção do plano.
- Cópias de logs (anexos, downloads, serviços externos) seguem o mesmo limite.
- Logs não devem conter senha, token, e-mail ou valores financeiros. Mensagens de erro de SQL precisam ser higienizadas, inclusive em `failed_jobs`.
- Com `MAIL_MAILER=log`, o conteúdo dos e-mails (inclusive links de redefinição de senha) vai para o log. Enquanto não houver SMTP, restringir o acesso aos logs.
