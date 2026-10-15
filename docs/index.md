# omnilab/ensembl

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
- The genomes of Ensembl Genomes (plants, yeasts, the fly, the worm), whose ids are their
  communities' (`AT5G10140`, `YAL001C`, `FBgn0003996`): not the core's `ensembl` scheme, so a symbol
  that names one is null.

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

## Verified, and not

Against the real service on 2026-10-15 (release 116), without a key: HBB by its id, versioned id,
page and symbol, its cross-references (GeneID 3043, HGNC:4827 and its synonyms BETA-GLOBIN and
CD113T-C, Swiss-Prot P68871 alone among five UniProt entries), its canonical transcript (three
exons, 628 bp, 147 aa) and its protein (P68871 then D9YZU5); TP53 (GeneID 7157, P04637, synonyms LFS1
and P53); an exon; the mouse genes of `Hbb-bs` (Hbb-bs, Hbb-bt) by `mouse/` and `mus_musculus/`;
`p53` giving TP53; the zebrafish's `hbba1` (ENSDARG00000089087) and the fly's `w` (FBgn0003996,
not an ENS id); an unknown id, an unknown symbol, an unknown species; Ensembl's rate headers
(`x-ratelimit-limit: 55000` an hour). The fixtures in `Tests/Fixtures/` are its answers.

Not verified: Ensembl's 429 (none met: the handling is the core's); the regulatory features (`R`) and
protein families (`F`), read as generic features.
