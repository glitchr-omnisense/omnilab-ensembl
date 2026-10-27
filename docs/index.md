# omnilab/ensembl

Ensembl's REST API (EMBL-EBI: `https://rest.ensembl.org/`) for
[omnisense/omnilab](https://github.com/glitchr-omnisense/omnilab): genes, transcripts, proteins and
exons by Ensembl id (and the genomes Ensembl Genomes serves under their communities' ids), genes by
symbol in a species - biotype, species, assembly, place on the genome - with their NCBI Gene and
UniProt cross-references, as entities; a search of symbols. No key.

## Installation

```sh
composer require omnilab/ensembl
```

```php
use Omnilab\Ensembl\EnsemblSourceFactory;

$ensembl = (new EnsemblSourceFactory($httpClient))->create([
    'mailto' => 'support@glitchr.io',  // optional, in the User-Agent
    'species' => 'homo_sapiens',       // optional: the species of a symbol alone
    'xrefs' => true,                   // optional
]);
```

With the Symfony bundle, the factory is registered as `ensembl` (see the core's docs).

## Credentials

None: Ensembl's REST API takes no key and no account, within 15 requests a second and 55,000 an
hour from one caller.

## Options

The factory's options, with the defaults it applies (`EnsemblSourceFactory`):

| Option | Type | Default | Required | Effect |
|---|---|---|---|---|
| `species` | `string` | `homo_sapiens` | no | the species a symbol is looked up in when it names none (`mus_musculus/Hbb-bs` names its own) |
| `xrefs` | `bool` | `true` | no | `entity()` reads a gene's or a protein's cross-references (NCBI Gene, UniProt, HGNC's synonyms): one or two calls more |
| `mailto` | `?string` | null | no | a contact address, in the User-Agent: whom Ensembl writes to before it blocks a caller |
| `user_agent` | `?string` | null | no | the whole User-Agent, in place of the one made from `mailto` |
| `throttle` | `float` | `0.07` | no | seconds left between two calls: Ensembl takes 15 a second at most (55,000 an hour) |
| `base_uri` | `string` | `https://rest.ensembl.org/` | no | the API the source calls |

## Capabilities

`capabilities()` answers `entity`, `search`, `cross_references`; an operation it does not answer throws `NotSupportedException` before any call.

| Capability | Supported | |
|---|---|---|
| `entity` | yes | an Ensembl id, versioned or not (a gene, transcript, protein, exon, regulatory feature), an `ensembl.genomes` id (the fly, the worm, the yeasts, the plants...), or a symbol in the configured species or one named before it; another scheme refused before any call; up to four calls a gene |
| `search` | yes | a symbol or `species/symbol`: the genes it names, its own or a synonym's, in Ensembl's order, 1,000 a page at most, paged by offset; a domain other than biology answers an empty page |
| `series` | no |  |
| `products` | no |  |
| `occurrences` | no |  |
| `datasets` | no |  |
| `jobs` | no |  |
| `open` | no |  |
| `structure` | no |  |
| `cross_references` | yes | a gene's NCBI GeneID and Swiss-Prot entries; its transcripts, proteins and gene are `related` |
| `forecast` | no |  |
| `historical` | no |  |
| `area` | no |  |
| `station` | no |  |
| `cloud_cover` | no |  |

## Which API

The Ensembl REST API, `https://rest.ensembl.org/` (release 116 on 2026-10-15), JSON when a call
says `Content-Type: application/json` - Ensembl reads the format from it, a GET's too (without it,
an HTML page; with `Accept: application/json` alone, JSON as well, but Ensembl's rules name
`Content-Type`, which the source always sends). An unknown id or symbol is a 400 (`{"error":"ID
'ENSG00000000000' not found"}`, `"No valid lookup found for symbol NOTAGENE"`): the source reads
those two as null, any other 400 (an unknown species) as a `ProviderException`.

The calls:

| What | Ensembl |
|---|---|
| a record by id | `lookup/id/{id}` (`?expand=1` for a transcript: its exons and its translation) |
| a gene by symbol | `lookup/symbol/{species}/{symbol}` |
| a gene's cross-references | `xrefs/id/{gene}`: NCBI Gene (`EntrezGene`), the nomenclature committee's symbol, id and synonyms (`HGNC`, `MGI`, `ZFIN_ID`, `RGD`...) |
| a gene's reviewed proteins | `xrefs/id/{gene}?all_levels=1&external_db=Uniprot/SWISSPROT`: the gene's own list (`Uniprot_gn`) mixes the reviewed and unreviewed entries with nothing to tell them apart |
| a protein's UniProt entries | `xrefs/id/{protein}?external_db=Uniprot%` (Swiss-Prot and TrEMBL: the reviewed put first; without the filter, 575 cross-references for HBB's protein, 323 of them PDB chains) |
| a species | `info/genomes/{species}`: its taxon, scientific and common names; once a species, kept |
| the genes of a symbol | `xrefs/symbol/{species}/{symbol}?object_type=gene`, then one `POST lookup/id` with the page's ids |

A gene costs four calls the first time (record, species, two lists of cross-references), three
after; a protein four (record, its transcript's, species, UniProt entries); a transcript or an exon
one or two. `xrefs: false` leaves out the cross-references.

## Calls

| Method | Reads |
|---|---|
| `entity()` | an Ensembl id, its version dropped (`ENSG00000244734`, `ENSG00000244734.6`, `ensembl:...`, `https://www.ensembl.org/Homo_sapiens/Gene/Summary?g=ENSG00000244734`): a gene (`G`), a transcript (`T`), a protein (`P`), an exon (`E`), a regulatory feature (`R`) |
| | a symbol in the configured species (`HBB`), or in one named before it, Ensembl's name or an alias (`mus_musculus/Hbb-bs`, `mouse/Hbb-bs`) |
| `search()` | `Query::$text`: a symbol, or `species/symbol`; the genes it names, its own or a synonym's (`p53` gives TP53), in Ensembl's order, page by offset; a domain other than biology answers nothing |

An identifier of another scheme (a UniProt accession, a GeneID, a Wikidata item) is refused before
any call: the `Collector` offers Ensembl only its own ids.

## What an entity holds

`id` (its Ensembl id), `label` (a gene's symbol, a transcript's name `HBB-201`, a protein's id: Ensembl
names it no other way), `domain` biology, `kind` (`gene`, `transcript`, `protein`, `exon`), `url`
(`https://www.ensembl.org/id/...`), `synonyms` (a gene's name and its nomenclature committee's
synonyms), `description` (a gene's name), and:

| Key | Ensembl | Value |
|---|---|---|
| `gene` | a gene's display name | text (the key NCBI and UniProt give the symbol under) |
| `gene_name`, `name_source` | its description, apart from its source (`[Source:HGNC Symbol;Acc:HGNC:4827]`) | text |
| `biotype` | `protein_coding`, `lncRNA`, `processed_pseudogene`... | text |
| `organism`, `organism_common_name` | the species' scientific and common names | text |
| `assembly`, `chromosome` | `assembly_name`, `seq_region_name` | text |
| `genomic_location` | `11:5225464-5229395 (minus strand), GRCh38` - NCBI's key, so that the two show side by side | text |
| `sequence_length` | a gene's span on the genome, a transcript's spliced length (bp); a protein's length (aa) | Quantity |
| `version`, `annotation_source` | the id's version, `ensembl_havana`... | number, text |
| `canonical_transcript`, `nomenclature_id` | a gene's canonical transcript, its HGNC (MGI, ZFIN...) id | text |
| `canonical`, `gencode_primary`, `exon_count`, `protein_length` | a transcript's | boolean, number, Quantity |
| `transcript_name` | a protein's transcript's name | text |

`identifiers`: a gene's NCBI Gene record and its reviewed UniProt entries (Swiss-Prot); a protein's
UniProt entries, Swiss-Prot first. A transcript has none in the core's schemes (its RefSeq and CCDS
ids are not). `related`: `organism` (the species' NCBI taxon), a gene's `transcript` (its
canonical one), a transcript's `gene` and `protein`, a protein's `transcript` and `gene`. A search
result holds what the gene's record says, no cross-references: `entity()` reads them.

A gene and its protein are one thing for the `Merger` (the UniProt entry is among the gene's
identifiers), as NCBI Gene and UniProt already make them; a transcript and a protein are other
things, related.

## What Ensembl does not give here

- No Wikidata item: Wikidata finds the gene by its Ensembl id (P594), so the `Collector` reaches it.
- Not read: the sequences (`sequence/id`), the variants, the homologues and the comparative
  genomics, the regulatory build, the transcripts' RefSeq and CCDS ids, the GO terms, Reactome,
  the protein's PDB chains (UniProt's structures are related things there).
- The genomes whose ids are their communities' (the fly, the worm, the yeasts, the plants of
  Ensembl Genomes: `FBgn0003996`, `YAL001C`, `AT5G10140`) are the core's `ensembl.genomes` scheme
  (since 2026-10-15): read as Ensembl's own, a gene by its id or its symbol
  (`drosophila_melanogaster/w`), each lookup and cross-reference call with the genome its shape
  tells (`species=drosophila_melanogaster`): rest.ensembl.org serves Ensembl Genomes too (the old
  rest.ensemblgenomes.org answers with another host's certificate), but without the species it took
  7 to 23 s, or answered 500. An id of a genome the core does not know by its shape goes with its
  scheme and is asked without the species. Their related transcripts, genes and proteins are kept
  when the core knows their genome's shape (an LRG, a locus reference, never).

## The Collector

Answered on 2026-10-15 (the core's `docs/merging.md` gives them whole):

- `collect('ncbi', '3043', 1, ['ensembl'])`: NCBI Gene's HBB gives its Ensembl id, Ensembl answers
  with the gene (one NCBI call, four Ensembl); `genomic_location` holds NCBI's two assemblies and
  Ensembl's line, `gene_name` both.
- `collect('uniprot', 'P68871', 1, ['ensembl'])`: the protein's Ensembl gene (its cross-reference)
  in the same four Ensembl calls.
- and back, `collect('ensembl', 'ENSG00000244734', 1, ['ncbi', 'uniprot', 'wikidata'])`: NCBI Gene by
  GeneID 3043, UniProt by P68871, Wikidata by the Ensembl id (P594: Q14905542, the HBB gene, a
  "protein-coding gene in the species Homo sapiens"); sources ensembl, ncbi, uniprot, wikidata.

## Example

```php
<?php

require __DIR__.'/vendor/autoload.php';

use Omnilab\Ensembl\EnsemblSourceFactory;
use Omnilab\Model\Scheme;
use Omnilab\Source\Query;
use Symfony\Component\HttpClient\HttpClient;

$ensembl = (new EnsemblSourceFactory(HttpClient::create(['timeout' => 60])))->create(['mailto' => 'you@example.org']);

$hbb = $ensembl->entity('HBB');   // a symbol in the configured species, homo_sapiens
echo $hbb->label, ' (', $hbb->id, '): ', $hbb->value('biotype'), ', ', $hbb->value('genomic_location'), "\n";
echo '  NCBI Gene ', $hbb->identifier(Scheme::NCBI_GENE), ', UniProt ', $hbb->identifier(Scheme::UNIPROT), ', canonical transcript ', $hbb->value('canonical_transcript'), "\n";

$fly = $ensembl->entity('FBgn0000008');   // the fly: the ensembl.genomes scheme
echo $fly->label, ' (', $fly->id, '): ', $fly->value('organism'), ', NCBI Gene ', $fly->identifier(Scheme::NCBI_GENE), "\n";

$page = $ensembl->search(new Query('mouse/Hbb-bs', limit: 5));
printf("mouse/Hbb-bs: %s\n", implode(', ', array_map(static fn ($g) => $g->label.' '.$g->id->value, $page->items)));
```

```
HBB (ensembl:ENSG00000244734): protein_coding, 11:5225464-5229395 (minus strand), GRCh38
  NCBI Gene 3043, UniProt P68871, canonical transcript ENST00000335295.4
a (ensembl.genomes:FBgn0000008): Drosophila melanogaster, NCBI Gene 43852
mouse/Hbb-bs: Hbb-bt ENSMUSG00000073940, Hbb-bs ENSMUSG00000052305
```

(as answered on 2026-10-27: a dozen calls, 0.07 s apart; the HTTP client's timeout raised: Ensembl can be slow)

```yaml
# config/packages/omnilab.yaml
omnilab:
    sources:
        ensembl: { factory: ensembl, options: { mailto: '%env(OMNILAB_MAILTO)%', species: homo_sapiens, xrefs: true } }
```

## Errors

Every call goes through `HttpSource`: a 404 or a 410 (and a 204) is null - an empty page for a list -; a 429 is a `RateLimitedException` (`retryAfter` from `Retry-After`); a 5xx, a time-out or a host that does not answer an `UnavailableException`; any other 4xx a `ProviderException` with its `status` and what the service said; an answer that is not JSON a `ProviderException`, a web page answered for JSON an `UnavailableException`.

Ensembl's own: an unknown id or symbol is its 400 saying "not found" or "No valid lookup found",
read as null (any other 400 stays a `ProviderException`); a 429
past its rate carries a `Retry-After` (`RateLimitedException::$retryAfter`); its 500s and 503s
when it is slow are `UnavailableException`s. Before any call: an identifier of another scheme (a
UniProt accession, a GeneID, a Wikidata item) (`NotSupportedException`).

## Verified, and not

Against the real service on 2026-10-15 (release 116), without a key: HBB by its id, versioned id,
page and symbol, its cross-references (GeneID 3043, HGNC:4827 and its synonyms BETA-GLOBIN and
CD113T-C, Swiss-Prot P68871 alone among five UniProt entries), its canonical transcript (three
exons, 628 bp, 147 aa) and its protein (P68871 then D9YZU5); TP53 (GeneID 7157, P04637, synonyms LFS1
and P53); an exon; the mouse genes of `Hbb-bs` (Hbb-bs, Hbb-bt) by `mouse/` and `mus_musculus/`;
`p53` giving TP53; the zebrafish's `hbba1` (ENSDARG00000089087); that evening, Ensembl slow
(a lookup of HBB took 20 s), the community ids: the fly's `a` (FBgn0000008: GeneID 43852, its
canonical transcript FBtr0071764, its protein FBpp0071678) and `w` by its symbol (FBgn0003996, GeneID
31271, P10090), the budding yeast's YAL001C (TFC3, GeneID 851262, P34111) and YIL177W-A, the fission
yeast's SPAC1002.01 and SPBC1105.02c, rice's Os01g0100100, maize's Zm00001eb000010, wheat's
TraesCS1A02G000100, Plasmodium's PF3D7_0100100, Anopheles' AGAP000002, the worm's WBGene00000001,
Arabidopsis' AT5G10140 (FLC) and its transcript AT5G10140.1 - Arabidopsis' lookups answered 500 a
few hours later, an `UnavailableException`; the `Collector` from FBgn0000008 into NCBI Gene (two
sources, `arc` from each, 21.5 s); an unknown id, an unknown symbol, an unknown species; Ensembl's rate headers
(`x-ratelimit-limit: 55000` an hour). The fixtures in `Tests/Fixtures/` are its answers.

Again on 2026-10-16, 08:29 to 08:41 JST (the workspace's `.samples/live.php`, not published, section 18, run four times, the
last two step by step): Ensembl answered, slowly and not always - `info/ping` took 9.9 s, a lookup 6 to
30 s, and calls answered 500 or 503 now and then (an `UnavailableException`), so no run passed
whole. Every check passed in one run or another: HBB by its symbol and its versioned id, its GeneID
and Swiss-Prot entry, its species and canonical transcript (HBB-201, three exons) and its protein
(P68871 before D9YZU5); the mouse genes of `Hbb-bs` (failed twice in the section, answered alone in
9.6 s); an unknown id and symbol, the fly's `w` by its symbol; the `Collector` from NCBI Gene and
from UniProt into Ensembl and from Ensembl into NCBI Gene, UniProt and Wikidata (the HBB gene's
item, Q14905542, by P594); the fly's `a` (FBgn0000008, GeneID 43852) and the budding yeast's
YAL001C (TFC3, P34111). The service, not the package: nothing changed here.

Again on 2026-10-27: HBB by its symbol (GeneID 3043, P68871, its canonical transcript), the fly's
`FBgn0000008`, the mouse genes of `Hbb-bs` (the example above).

Not verified: Ensembl's 429 (none met: the handling is the core's); the regulatory features (`R`) and
protein families (`F`), read as generic features.

## Responsibility

This package is an independent, unofficial client: it is not affiliated with, endorsed by or
sponsored by Ensembl or the EMBL-EBI. Ensembl's data are free to use without restriction (the
EMBL-EBI's terms of use); cite Ensembl where they are shown, and keep to its rate, as the README's
[Terms of use](https://github.com/glitchr-omnisense/omnilab-ensembl#terms-of-use) says. Whoever
runs the package is responsible for any use made of what it returns: see the README's
[Responsibility](https://github.com/glitchr-omnisense/omnilab-ensembl#responsibility) section.
