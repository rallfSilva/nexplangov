#!/usr/bin/env bash
# Gera o pacote do código-fonte para o pedido de registro de programa de computador no INPI
# (Lei nº 9.609/1998). O INPI não recebe o código: o titular guarda o pacote e declara o resumo
# digital (hash SHA-512) no formulário e-Software. Guarde o .tar sem alterar nenhum byte:
# qualquer mudança gera outro hash e o pacote deixa de provar a autoria.
#
# Uso: scripts/inpi/gerar-pacote.sh [commit-ou-tag]   (padrão: HEAD)
set -euo pipefail

ref="${1:-HEAD}"
cd "$(git rev-parse --show-toplevel)"
commit="$(git rev-parse "$ref")"
curto="$(git rev-parse --short=12 "$commit")"
data="$(git show -s --format=%cs "$commit")"
saida="inpi"
mkdir -p "$saida"

pacote="$saida/nexplangov-fonte-${data}-${curto}.tar"
# git archive é determinístico para o mesmo commit: o mesmo pacote sempre dá o mesmo hash.
git archive --format=tar --prefix="nexplangov/" -o "$pacote" "$commit"

sha512="$(sha512sum "$pacote" | cut -d' ' -f1)"
sha256="$(sha256sum "$pacote" | cut -d' ' -f1)"
arquivos="$(git ls-tree -r --name-only "$commit" | wc -l | tr -d ' ')"
linhas="$(git archive --format=tar "$commit" | tar -xO 2>/dev/null | wc -l | tr -d ' ')"
primeiro="$(git log --reverse --format=%cs "$commit" | head -1)"

cat > "$saida/nexplangov-declaracao-${data}-${curto}.txt" <<TXT
NexPlanGov: resumo digital do código-fonte para o INPI
Commit ............: ${commit}
Data do commit ....: ${data}
Primeiro registro .: ${primeiro} (data de criação a declarar)
Arquivo ...........: $(basename "$pacote")
Tamanho ...........: $(stat -c %s "$pacote") bytes
Arquivos ..........: ${arquivos}
Linhas ............: ${linhas}
SHA-512 ...........: ${sha512}
SHA-256 ...........: ${sha256}
Gerado em .........: $(date -u +%Y-%m-%dT%H:%M:%SZ)
Para conferir: sha512sum $(basename "$pacote")
TXT

cat "$saida/nexplangov-declaracao-${data}-${curto}.txt"
