<?php

namespace Omnilab\Ensembl;

use Omnilab\Exception\NotSupportedException;
use Omnilab\Exception\ProviderException;
use Omnilab\Model\Domain;
use Omnilab\Model\Entity;
use Omnilab\Model\Identifier;
use Omnilab\Model\Identifiers;
use Omnilab\Model\Property;
use Omnilab\Model\Quantity;
use Omnilab\Model\Scheme;
use Omnilab\Source\Capability;
use Omnilab\Source\HttpSource;
use Omnilab\Source\Page;
use Omnilab\Source\Query;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Ensembl (EMBL-EBI), the genome annotation of the vertebrates and the
 * model organisms, through its REST API (rest.ensembl.org): a gene, a
 * transcript, a protein (Ensembl's translation) or an exon by its Ensembl id
 * (ENSG00000244734, its version dropped), or a gene by its symbol in a
 * species ("HBB", "mus_musculus/Hbb-bs"); its symbol, name, biotype,
 * species, assembly and place on the genome; a gene's NCBI Gene record and
 * its Swiss-Prot proteins, a protein's UniProt entries, as cross-references
 * for the Collector to follow; its species, gene, transcripts and protein
 * as related things; and the genes a symbol names in a species.
 *
 * The genomes whose ids are Ensembl's own (ENS..., the vertebrates) are of
 * the core's ensembl scheme; those Ensembl and Ensembl Genomes annotate
 * under their community's ids (FBgn0000008, AT5G10140, YAL001C) of its
 * ensembl.genomes scheme, read the same way, with the genome their shape
 * tells (a lookup without it took 7 to 23 s on 2026-10-15).
 *
 * No key. Ensembl takes 15 calls a second (55,000 an hour), and asks every
 * call to say "Content-Type: application/json". A gene costs three calls
 * (its record, its cross-references, its Swiss-Prot proteins), a protein
 * three (its record, its transcript's, its UniProt entries), a transcript
 * or an exon one; each species' record once more (its taxon, its common
 * name), kept for the source's life.
 */
final class EnsemblSource extends HttpSource
{
    public const BASE_URI = 'https://rest.ensembl.org/';

    /** The species a symbol is looked up in when it names none. */
    public const SPECIES = 'homo_sapiens';

    /** Seconds between two calls: Ensembl's 15 a second, with a margin. */
    public const THROTTLE = 0.07;

    /** The most ids one batch lookup takes (Ensembl stops at 1000). */
    public const MAX_PER_PAGE = 1000;

    /** What an id's letter before its number says it is. */
    private const KINDS = ['G' => 'gene', 'T' => 'transcript', 'P' => 'protein', 'E' => 'exon', 'R' => 'regulatory feature', 'F' => 'protein family'];

    /** A species as Ensembl names it in a path: its production name ("homo_sapiens") or an alias ("human"). */
    private const SPECIES_NAME = '~^[a-z][a-z0-9_]*$~i';

    /** @var array<string, array<string, mixed>|null> the species' records already read */
    private array $genomes = [];

    /**
     * @param string $species the species a symbol is looked up in when it names none
     * @param bool   $xrefs   whether a gene's and a protein's cross-references are read (one or two calls more)
     */
    public function __construct(
        HttpClientInterface $http,
        private readonly string $species = self::SPECIES,
        private readonly bool $xrefs = true,
        string $baseUri = self::BASE_URI,
        array $headers = [],
        float $throttle = self::THROTTLE,
    ) {
        // Ensembl tells its formats by the Content-Type a call says, a GET's too.
        parent::__construct($http, $baseUri, $headers + ['Content-Type' => 'application/json'], $throttle);
    }

    public function getName(): string
    {
        return 'ensembl';
    }

    public function capabilities(): array
    {
        return [Capability::ENTITY, Capability::SEARCH, Capability::CROSS_REFERENCES];
    }

    /**
     * A gene, transcript, protein or exon by its Ensembl id (ENSG00000244734,
     * ENSG00000244734.6, ensembl:..., its page), or a gene by its symbol in
     * the configured species ("HBB") or in a species named before it
     * ("mus_musculus/Hbb-bs", "mouse/Hbb-bs"). An identifier of another
     * scheme is refused before any call.
     */
    public function entity(Identifier|string $id): ?Entity
    {
        if ($id instanceof Identifier) {
            return \in_array($id->scheme, [Scheme::ENSEMBL, Scheme::ENSEMBL_GENOMES], true) ? $this->byId($id->value) : throw NotSupportedException::identifier('ensembl', (string) $id);
        }
        $text = trim($id);
        $identifier = Identifier::tryOf(Scheme::ENSEMBL, $text) ?? Identifier::parse($text);
        if (null !== $identifier) {
            return \in_array($identifier->scheme, [Scheme::ENSEMBL, Scheme::ENSEMBL_GENOMES], true) ? $this->byId($identifier->value) : throw NotSupportedException::identifier('ensembl', $id);
        }
        if ('' === $text) {
            return null;
        }
        [$species, $symbol] = $this->symbol($text);
        $gene = $this->ask('lookup/symbol/'.rawurlencode($species).'/'.rawurlencode($symbol));

        return null === self::own($gene['id'] ?? null) ? null : $this->gene($gene);
    }

    /**
     * The genes a symbol names in a species - its own, or a synonym's
     * ("p53" gives TP53) -, as Ensembl's cross-references know them: "HBB"
     * in the configured species, "mus_musculus/Hbb-bs" in another. Each
     * holds what the gene's record says (no cross-references: entity()
     * reads them); a page by offset. A domain other than biology answers
     * nothing.
     */
    public function search(Query $query): Page
    {
        $text = trim($query->text);
        if ((null !== $query->domain && Domain::BIOLOGY !== $query->domain) || '' === $text) {
            return new Page();
        }
        [$species, $symbol] = $this->symbol($text);
        $ids = [];
        foreach ($this->ask('xrefs/symbol/'.rawurlencode($species).'/'.rawurlencode($symbol), ['object_type' => 'gene']) ?? [] as $match) {
            // Ensembl's genes only: an LRG (a locus reference) is no Ensembl id.
            if (null !== ($gene = self::own($match['id'] ?? null))) {
                $ids[$gene->value] = $gene->value;
            }
        }
        $ids = array_values($ids);
        $offset = max(0, (int) $query->cursor);
        $slice = \array_slice($ids, $offset, max(1, min(self::MAX_PER_PAGE, $query->limit)));
        if (!$slice) {
            return new Page([], null, \count($ids));
        }
        $records = $this->postForJson('lookup/id', ['ids' => $slice], null === Identifier::ensemblSpecies($slice[0]) ? [] : ['species' => $species]) ?? [];
        $entities = [];
        foreach ($slice as $gene) {
            if (\is_array($records[$gene] ?? null)) {
                $entities[] = $this->gene($records[$gene], false);
            }
        }
        $next = $offset + \count($slice);

        return new Page($entities, $next < \count($ids) ? (string) $next : null, \count($ids));
    }

    /** [the species, the symbol] of "species/symbol", the configured species for a symbol alone. */
    private function symbol(string $text): array
    {
        if (preg_match('~^([^/\s]+)/(.+)$~', $text, $m) && preg_match(self::SPECIES_NAME, $m[1])) {
            return [strtolower($m[1]), trim($m[2])];
        }

        return [$this->species, $text];
    }

    private function byId(string $id): ?Entity
    {
        $kind = self::KINDS[preg_match('~^ENS[A-Z]*([EFGPRT])\d{11}$~', $id, $m) ? $m[1] : ''] ?? (preg_match('~^FBtr|^AT[1-5CM]G\d{5}\.\d+$~i', $id) ? 'transcript' : null);
        $record = $this->ask('lookup/id/'.rawurlencode($id), ('transcript' === $kind ? ['expand' => 1] : []) + self::genomeOf($id));
        if (null === $record || !isset($record['id'])) {
            return null;
        }

        return match ($record['object_type'] ?? null) {
            'Gene' => $this->gene($record),
            'Transcript' => $this->transcript($record),
            'Translation' => $this->translation($record),
            default => $this->feature($record),
        };
    }

    /**
     * A gene's record, and its cross-references unless asked not to: its
     * NCBI Gene record, its HGNC symbol and synonyms, its Swiss-Prot proteins.
     *
     * @param array<string, mixed> $r
     */
    private function gene(array $r, bool $xrefs = true): Entity
    {
        $id = (string) $r['id'];
        $symbol = self::text($r['display_name'] ?? null) ?? $id;
        [$name, $nameSource] = self::named($r['description'] ?? null);
        $genome = $this->genome((string) ($r['species'] ?? $this->species));
        $properties = $this->located($r, $genome);
        $add = static function (string $key, string $label, Quantity|string|int|bool|null $value) use (&$properties): void {
            if (null !== $value && '' !== $value) {
                $properties[] = new Property($key, $label, $value, 'ensembl');
            }
        };
        array_unshift($properties, ...array_filter([
            new Property('gene', 'Gene symbol', $symbol, 'ensembl'),
            null === $name ? null : new Property('gene_name', 'Gene name', $name, 'ensembl'),
        ]));
        $add('name_source', 'Name given by', $nameSource);
        $add('canonical_transcript', 'Canonical transcript', self::text($r['canonical_transcript'] ?? null));

        $identifiers = [];
        $synonyms = [];
        if ($xrefs && $this->xrefs) {
            // The gene's own cross-references: its NCBI Gene record, the nomenclature committee's symbol and synonyms.
            foreach ($this->ask('xrefs/id/'.rawurlencode($id), self::genomeOf($id)) ?? [] as $xref) {
                $database = $xref['dbname'] ?? null;
                if ('EntrezGene' === $database) {
                    $identifiers[] = Identifier::tryOf(Scheme::NCBI_GENE, $xref['primary_id'] ?? null);
                } elseif (\in_array($database, ['HGNC', 'MGI', 'ZFIN_ID', 'RGD', 'FlyBase_gene_id', 'WormBase_Gene', 'SGD_GENE'], true)) {
                    $add('nomenclature_id', 'Nomenclature id ('.($xref['db_display_name'] ?? $database).')', self::text($xref['primary_id'] ?? null));
                    foreach ($xref['synonyms'] ?? [] as $synonym) {
                        if (null !== ($synonym = self::text($synonym)) && mb_strtolower($synonym) !== mb_strtolower($symbol)) {
                            $synonyms[mb_strtolower($synonym)] ??= $synonym;
                        }
                    }
                }
            }
            // Its proteins' reviewed UniProt entries: the gene's own list mixes them with the unreviewed ones, unordered.
            foreach ($this->ask('xrefs/id/'.rawurlencode($id), ['all_levels' => 1, 'external_db' => 'Uniprot/SWISSPROT'] + self::genomeOf($id)) ?? [] as $xref) {
                $identifiers[] = Identifier::tryOf(Scheme::UNIPROT, $xref['primary_id'] ?? null);
            }
        }
        if (null !== $name && mb_strtolower($name) !== mb_strtolower($symbol)) {
            $synonyms = [mb_strtolower($name) => $name] + $synonyms;
        }
        $transcript = self::own(rtrim((string) ($r['canonical_transcript'] ?? ''), '.'));

        return $this->toEntity($r, $symbol, 'gene', $name, $properties, $identifiers, $genome, array_values($synonyms), ['transcript' => $transcript]);
    }

    /** @param array<string, mixed> $r a transcript's record, expanded: its exons and its translation */
    private function transcript(array $r): Entity
    {
        $id = (string) $r['id'];
        $genome = $this->genome((string) ($r['species'] ?? $this->species));
        $properties = $this->located($r, $genome);
        $add = static function (string $key, string $label, Quantity|string|int|bool|null $value) use (&$properties): void {
            if (null !== $value && '' !== $value) {
                $properties[] = new Property($key, $label, $value, 'ensembl');
            }
        };
        $add('canonical', 'Canonical transcript of its gene', isset($r['is_canonical']) ? 1 === (int) $r['is_canonical'] : null);
        $add('gencode_primary', 'GENCODE primary', isset($r['gencode_primary']) ? 1 === (int) $r['gencode_primary'] : null);
        $add('exon_count', 'Exons', isset($r['Exon']) && \is_array($r['Exon']) ? \count($r['Exon']) : null);
        $add('protein_length', 'Protein length', is_numeric($r['Translation']['length'] ?? null) ? new Quantity((int) $r['Translation']['length'], 'aa') : null);

        return $this->toEntity($r, self::text($r['display_name'] ?? null) ?? $id, 'transcript', null, $properties, [], $genome, [], [
            'gene' => self::own($r['Parent'] ?? null),
            'protein' => self::own($r['Translation']['id'] ?? null),
        ]);
    }

    /**
     * A protein (Ensembl's translation of a transcript): its record, its
     * transcript's (where it sits, its gene), and its UniProt entries, the
     * reviewed first.
     *
     * @param array<string, mixed> $r
     */
    private function translation(array $r): Entity
    {
        $id = (string) $r['id'];
        $transcript = isset($r['Parent']) ? $this->ask('lookup/id/'.rawurlencode((string) $r['Parent']), self::genomeOf((string) $r['Parent'])) : null;
        $genome = $this->genome((string) ($r['species'] ?? $this->species));
        // Its place: the coding part of its transcript, on its transcript's sequence.
        $properties = $this->located(['seq_region_name' => $transcript['seq_region_name'] ?? null, 'strand' => $transcript['strand'] ?? null, 'assembly_name' => $transcript['assembly_name'] ?? null] + $r, $genome, false);
        if (is_numeric($r['length'] ?? null)) {
            $properties[] = new Property('sequence_length', 'Sequence length', new Quantity((int) $r['length'], 'aa'), 'ensembl');
        }
        $identifiers = [];
        if ($this->xrefs) {
            $reviewed = $unreviewed = [];
            foreach ($this->ask('xrefs/id/'.rawurlencode($id), ['external_db' => 'Uniprot%'] + self::genomeOf($id)) ?? [] as $xref) {
                $accession = Identifier::tryOf(Scheme::UNIPROT, $xref['primary_id'] ?? null);
                if ('Uniprot/SWISSPROT' === ($xref['dbname'] ?? null)) {
                    $reviewed[] = $accession;
                } else {
                    $unreviewed[] = $accession;
                }
            }
            $identifiers = [...$reviewed, ...$unreviewed];
        }
        if (null !== ($name = self::text($transcript['display_name'] ?? null))) {
            $properties[] = new Property('transcript_name', 'Transcript', $name, 'ensembl');
        }

        // Ensembl names a protein by its id alone (its transcript's name is the transcript's).
        return $this->toEntity($r, $id, 'protein', null, $properties, $identifiers, $genome, [], [
            'transcript' => self::own($r['Parent'] ?? null),
            'gene' => self::own($transcript['Parent'] ?? null),
        ]);
    }

    /** @param array<string, mixed> $r an exon's record, or another feature's */
    private function feature(array $r): Entity
    {
        $id = (string) $r['id'];
        $genome = $this->genome((string) ($r['species'] ?? $this->species));
        $kind = strtolower((string) ($r['object_type'] ?? 'feature'));

        return $this->toEntity($r, self::text($r['display_name'] ?? null) ?? $id, $kind, null, $this->located($r, $genome), [], $genome, [], []);
    }

    /**
     * What every record says: its biotype, species, assembly, place and
     * length on the genome, its version.
     *
     * @param array<string, mixed>      $r
     * @param array<string, mixed>|null $genome
     *
     * @return list<Property>
     */
    private function located(array $r, ?array $genome, bool $length = true): array
    {
        $properties = [];
        $add = static function (string $key, string $label, Quantity|string|int|null $value) use (&$properties): void {
            if (null !== $value && '' !== $value) {
                $properties[] = new Property($key, $label, $value, 'ensembl');
            }
        };
        $add('biotype', 'Biotype', self::text($r['biotype'] ?? null));
        $add('organism', 'Organism', self::text($genome['scientific_name'] ?? null) ?? (isset($r['species']) ? ucfirst(str_replace('_', ' ', (string) $r['species'])) : null));
        $add('organism_common_name', 'Organism (common name)', self::text($genome['display_name'] ?? null));
        $add('assembly', 'Assembly', self::text($r['assembly_name'] ?? null));
        $chromosome = self::text(isset($r['seq_region_name']) ? (string) $r['seq_region_name'] : null);
        $add('chromosome', 'Chromosome (or sequence region)', $chromosome);
        if (null !== $chromosome && is_numeric($r['start'] ?? null) && is_numeric($r['end'] ?? null)) {
            $strand = match ((int) ($r['strand'] ?? 0)) {
                1 => 'plus',
                -1 => 'minus',
                default => '?',
            };
            $add('genomic_location', 'Genomic location', \sprintf('%s:%d-%d (%s strand), %s', $chromosome, $r['start'], $r['end'], $strand, self::text($r['assembly_name'] ?? null) ?? '?'));
            if ($length) {
                $add('sequence_length', 'Length', new Quantity(isset($r['length']) && is_numeric($r['length']) ? (int) $r['length'] : (int) $r['end'] - (int) $r['start'] + 1, 'bp'));
            }
        }
        $add('version', 'Version (of the Ensembl id)', is_numeric($r['version'] ?? null) ? (int) $r['version'] : null);
        $add('annotation_source', 'Annotated by', self::text($r['source'] ?? null));

        return $properties;
    }

    /**
     * @param list<Property>                 $properties
     * @param list<Identifier|null>          $identifiers
     * @param array<string, mixed>|null      $genome
     * @param list<string>                   $synonyms
     * @param array<string, Identifier|null> $related
     */
    private function toEntity(array $r, string $label, string $kind, ?string $description, array $properties, array $identifiers, ?array $genome, array $synonyms, array $related): Entity
    {
        $id = (string) $r['id'];
        $related['organism'] = Identifier::tryOf(Scheme::NCBI_TAXON, $genome['taxonomy_id'] ?? null);

        return new Entity(
            id: self::own($id) ?? Identifier::of(Scheme::ENSEMBL_GENOMES, $id),
            label: $label,
            domain: Domain::BIOLOGY,
            kind: $kind,
            synonyms: $synonyms,
            description: $description,
            properties: $properties,
            identifiers: new Identifiers($identifiers),
            url: 'https://www.ensembl.org/id/'.rawurlencode($id),
            source: 'ensembl',
            related: array_map(static fn (Identifier $i) => Identifiers::of($i), array_filter($related)),
        );
    }

    /** An id Ensembl gives: its own (ENS...), else a community's of a known genome (ensembl.genomes); null when neither. */
    private static function own(mixed $id): ?Identifier
    {
        if (!\is_string($id) || '' === $id) {
            return null;
        }

        // A community's id of a genome the core knows by its shape (an LRG, a locus reference, is none).
        return Identifier::tryOf(Scheme::ENSEMBL, $id) ?? (null === Identifier::ensemblSpecies($id) ? null : Identifier::tryOf(Scheme::ENSEMBL_GENOMES, $id));
    }

    /**
     * The species a lookup of a community's id names, when its shape tells
     * it: rest.ensembl.org answers it in seconds, without it in 7 to 23 s
     * or not at all (2026-10-15).
     *
     * @return array<string, string>
     */
    private static function genomeOf(string $id): array
    {
        return null === ($species = Identifier::ensemblSpecies($id)) ? [] : ['species' => $species];
    }

    /**
     * A species' record, read once: its taxon, its scientific and common
     * names. Null when Ensembl does not answer it.
     *
     * @return array<string, mixed>|null
     */
    private function genome(string $species): ?array
    {
        $species = strtolower($species);
        if (!\array_key_exists($species, $this->genomes)) {
            $this->genomes[$species] = preg_match(self::SPECIES_NAME, $species) ? $this->ask('info/genomes/'.$species) : null;
        }

        return $this->genomes[$species];
    }

    /**
     * A name as Ensembl writes it, its source apart: "hemoglobin subunit beta
     * [Source:HGNC Symbol;Acc:HGNC:4827]".
     *
     * @return array{0: string|null, 1: string|null}
     */
    private static function named(mixed $description): array
    {
        $text = \is_string($description) ? $description : '';
        $source = preg_match('~\[Source:([^;\]]+)~', $text, $m) ? self::text($m[1]) : null;

        return [self::text(preg_replace('~\s*\[Source:[^\]]*\]\s*$~', '', $text)), $source];
    }

    /**
     * A GET; null when Ensembl says it knows no such thing - it answers 400
     * for that ("ID '...' not found", "No valid lookup found for symbol").
     *
     * @param array<string, mixed> $query
     */
    private function ask(string $path, array $query = []): ?array
    {
        try {
            return $this->getJson($path, $query);
        } catch (ProviderException $e) {
            if (400 === $e->status && preg_match('~not found|No valid lookup found~i', $e->getMessage())) {
                return null;
            }
            throw $e;
        }
    }
}
