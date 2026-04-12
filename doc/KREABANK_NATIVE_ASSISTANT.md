# KreaBank como auxiliar do módulo nativo `/compta/bank`

## Objetivo

O KreaBank funciona como assistente operacional do banco nativo do Dolibarr, sem manter um motor paralelo de extratos/reconciliação.

## Princípios de arquitetura

- Fonte oficial dos lançamentos e reconciliações: tabelas nativas de banco do Dolibarr.
- KreaBank persiste apenas metadados de suporte (perfil de importação, hash de idempotência, auditoria técnica).
- As telas do KreaBank encaminham o utilizador para as páginas nativas quando aplicável.

## Fluxo de importação

1. O ficheiro é analisado (CSV/OFX/CAMT/XLS/XLSX) e o utilizador confirma o mapeamento de colunas.
2. O KreaBank converte o conteúdo para o formato esperado pelo domínio nativo.
3. As linhas são gravadas como movimentos bancários nativos.
4. Metadados de integração (hash, origem, referência técnica) são gravados em tabela auxiliar.

## Fluxo de reconciliação

1. O KreaBank propõe documentos elegíveis (faturas/pagamentos).
2. Ao reconciliar, a persistência é feita via links nativos de banco/pagamento e estado nativo de conciliação.
3. O estado reconciliado fica visível nas páginas nativas (`/compta/bank/...`).

## Idempotência

- Cada linha importada é protegida com hash de idempotência por contexto de conta.
- Reimportar o mesmo extrato não deve criar lançamentos duplicados.

## Permissões e dependências

- O módulo exige `modBanque` ativo.
- Operações KreaBank respeitam permissões nativas:
  - leitura: `banque->lire`
  - escrita/import/reconciliação: `banque->modifier`

## Multi-entidade

- As consultas e gravações respeitam o `entity` ativo.
- Não há partilha de lançamentos/reconciliações entre entidades.

## Validação recomendada

- Executar `test/run_regression_native.sh`.
- Confirmar manualmente:
  - importação no KreaBank gera linhas nativas;
  - reconciliação no KreaBank marca estado nativo;
  - reimportação não duplica;
  - permissões e multi-entidade se mantêm corretas.
