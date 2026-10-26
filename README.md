# omnilab/ensembl

Ensembl for [omnisense/omnilab](https://github.com/glitchr-omnisense/omnilab): a gene, a transcript, a
protein (Ensembl's translation) or an exon by its Ensembl id, or a gene by its symbol in a species;
its symbol, name, biotype, species, assembly and place on the genome; a gene's NCBI Gene record and
its reviewed UniProt proteins, a protein's UniProt entries, as cross-references for the Collector to
follow; its species, its gene, its transcripts and its protein as related things; the genes a
symbol names in a species. Through the Ensembl REST API, no key.

```php
$ensembl = (new EnsemblSourceFactory($http))->create(['mailto' => 'support@glitchr.io']);
$hbb = $ensembl->entity('ENSG00000244734');      // or 'HBB', 'homo_sapiens/HBB', 'ENSG00000244734.6', its page
$hbb->label;                                     // HBB
$hbb->value('gene_name');                        // hemoglobin subunit beta
$hbb->value('genomic_location');                 // 11:5225464-5229395 (minus strand), GRCh38
$hbb->identifiers()->keys();                     // ensembl:ENSG00000244734, ncbi.gene:3043, uniprot:P68871
$hbb->related('transcript')->keys();             // ensembl:ENST00000335295, its canonical transcript
$hbb->related('organism')->keys();               // ncbi.taxon:9606
$ensembl->entity('ENSP00000333994')->identifiers()->keys();   // ..., uniprot:P68871, uniprot:D9YZU5: Swiss-Prot first
$ensembl->search(new Query('mus_musculus/Hbb-bs'));           // Hbb-bs and Hbb-bt, the mouse genes the symbol names
```

```yaml
omnilab:
    sources:
        ensembl:
            factory: ensembl
            options:
                mailto: 'support@glitchr.io'   # in the User-Agent: whom Ensembl writes to before it blocks a caller
                species: homo_sapiens          # the species a symbol alone is looked up in
                xrefs: true                    # a gene's and a protein's cross-references: one or two calls more
```

With NCBI, UniProt and Wikidata configured too, the `Collector` goes both ways:
`$collector->collect('ncbi', '3043')` or `collect('uniprot', 'P68871')` reaches Ensembl by the
Ensembl gene id they keep; `collect('ensembl', 'ENSG00000244734')` reaches NCBI Gene (3043),
UniProt (P68871) and Wikidata's gene item (Q14905542, by P594).

## Terms of use

Ensembl's data is free to use without restriction (EMBL-EBI's terms of use); cite Ensembl where you
show it. No key, no account. The REST API takes 15 requests a second and 55,000 an hour from one
caller, answers 429 with a `Retry-After` beyond, and asks every call to say
`Content-Type: application/json` (the source does, GETs included); the source leaves 0.07 s between
two calls. Ensembl's own ids (`ENS...`: the vertebrates) are the core's `ensembl` scheme; the
genomes it serves under their communities' ids - the fly (`FBgn0000008`), the worm, the yeasts
(`YAL001C`, `SPAC1002.01`), Arabidopsis (`AT5G10140`), rice, maize, wheat, Plasmodium, Anopheles -
its `ensembl.genomes` scheme, read the same way, each asked with the genome its id's shape tells
(a lookup without it took 7 to 23 s; another genome's id goes with its scheme,
`ensembl.genomes:Solyc01g005000.3`, and is asked without).

See [docs/](docs/index.md) for what is read, how it maps, and what Ensembl does not give here.

## Responsibility

This package is an independent, unofficial client: it is not affiliated with, endorsed by or
sponsored by EMBL-EBI (Ensembl). It reaches their service on behalf of whoever runs it, and it does not
redistribute their data beyond the short excerpts recorded as test fixtures. Its MIT licence
covers this package's code only and grants no right to the service or to its data.

Whoever installs and runs this package is responsible for complying with the terms above, for
obtaining whatever they require (an account, a key, a licence or subscription, an attribution or
a citation, a rate limit to respect), and for any use made of what it returns. The authors
publish it free of charge, make no commercial use of it, and accept no liability for how it is
used (see [LICENSE](LICENSE)).

License: MIT.
